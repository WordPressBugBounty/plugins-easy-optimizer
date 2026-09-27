<?php
/**
 * EasyOpt — local image optimization.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE DESIGN DECISION THAT SHAPES EVERYTHING ELSE: WE NEVER REPLACE FILES.
 *
 * The obvious way to build this is the way most competitors do: convert
 * photo.jpg to photo.avif, update the attachment metadata, rewrite the GUID,
 * then find and replace the old URL across post_content and postmeta —
 * including inside serialised arrays, recursively.
 *
 * That design makes "restore" a database migration. It has to un-rewrite
 * content it may not have written, in rows a user may have edited since,
 * with serialised payloads from page builders that store their own copies.
 * Every one of those is a way to corrupt a customer's site, and the blast
 * radius of a bug is the entire posts table.
 *
 * So we do the other thing. The original file is never renamed, moved, or
 * modified. Variants are written as siblings:
 *
 *     2026/09/photo.jpg          <- untouched, still the canonical URL
 *     2026/09/photo.jpg.webp     <- variant
 *     2026/09/photo.jpg.avif     <- variant
 *
 * Delivery happens at render time by rewriting <img> into <picture> (see
 * EasyOpt_Images_Delivery), so no stored URL ever changes. The database is
 * never touched. Restore is `unlink()` plus `delete_post_meta()` and cannot
 * fail halfway into a corrupt state.
 *
 * The extension is appended, not replaced, so photo.jpg.avif and
 * photo.png.avif cannot collide. It also matches the try_files convention
 * that nginx and Apache use for the same job.
 *
 * The one path that DOES modify an original is the optional "shrink
 * oversized originals" setting. That one takes a real backup copy first,
 * into uploads/easyopt-originals/, mirroring the year/month layout.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Images {

    /** Single meta blob per attachment. */
    const META = '_easyopt_img';

    /** Short-lived cache of the expensive library aggregate (the stats scan). */
    const STATS_CACHE = 'easyopt_images_stats';

    /** Marker written by the client-side shim when core encoded in-browser. */
    const META_CLIENTSIDE = '_easyopt_img_clientside';

    /** Queue wiring. */
    const QUEUE_GROUP    = 'images';
    const HOOK_OPTIMIZE  = 'easyopt_image_optimize';
    const HOOK_RESTORE   = 'easyopt_image_restore';
    const MAX_RETRIES    = 2;

    /** Directory (under uploads) holding backups of modified originals. */
    const BACKUP_DIR = 'easyopt-originals';

    /** Sources we will process. */
    const MIME = array( 'image/jpeg', 'image/png', 'image/gif' );

    /**
     * Hooks that must exist whether or not the feature is enabled, so that
     * disabling the feature never orphans variants or leaks a restore job.
     */
    public static function init_always() {
        add_action( self::HOOK_OPTIMIZE, array( __CLASS__, 'run_optimize' ) );
        add_action( self::HOOK_RESTORE, array( __CLASS__, 'run_restore' ) );
        add_action( 'delete_attachment', array( __CLASS__, 'on_delete_attachment' ) );
    }

    /**
     * Hooks that only make sense when local optimization is switched on.
     */
    public static function init() {
        if ( ! self::enabled() ) {
            return;
        }

        // Steer core's client-side encoder (WP 7.1+, Chromium). These same
        // filters also drive core's server-side path, so they are correct on
        // every version — there is no branch here on purpose.
        add_filter( 'image_editor_output_format', array( __CLASS__, 'filter_output_format' ) );
        add_filter( 'wp_editor_set_quality', array( __CLASS__, 'filter_quality' ), 10, 2 );
        add_filter( 'jpeg_quality', array( __CLASS__, 'filter_quality' ), 10, 2 );
        add_filter( 'image_save_progressive', '__return_true' );

        if ( self::max_dimension() > 0 ) {
            add_filter( 'big_image_size_threshold', array( __CLASS__, 'filter_big_image_threshold' ) );
        }

        // Auto-optimize new uploads.
        if ( (int) EasyOpt_Config::get( 'easyopt_images_auto', 1 ) ) {
            add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_generate_metadata' ), 20, 3 );
        }
    }

    public static function enabled() {
        return (bool) (int) EasyOpt_Config::get( 'easyopt_images', 0 );
    }

    /* ───────────────────────────────────────────────
     *  Steering core's encoder
     * ─────────────────────────────────────────────── */

    /**
     * Ask core to write a modern format directly where the host supports it.
     *
     * On 7.1 + Chromium this is honoured in the browser by wasm-vips; on
     * everything else core applies it server-side. Either way we then only
     * have to generate the OTHER format ourselves.
     */
    public static function filter_output_format( $formats ) {
        $target = (string) EasyOpt_Config::get( 'easyopt_images_core_format', '' );
        if ( '' === $target || ! in_array( $target, array( 'webp', 'avif' ), true ) ) {
            return $formats;
        }
        // Only claim a format the host can actually produce, or core will
        // fall back to writing the source format anyway and the setting
        // becomes a lie in the UI.
        if ( ! in_array( $target, EasyOpt_Images_Capability::available_formats(), true ) ) {
            return $formats;
        }
        $mime                 = 'image/' . $target;
        $formats['image/jpeg'] = $mime;
        $formats['image/png']  = $mime;
        return $formats;
    }

    /**
     * Quality for core's own encodes. Core exposes a single integer and
     * nothing else — no effort, no subsampling, no per-image adaptation.
     * That ceiling is why the server-side pipeline still runs on the
     * "maximum compression" setting even where client-side is available.
     */
    public static function filter_quality( $quality, $mime = '' ) {
        if ( 'image/webp' === $mime ) {
            return self::quality( 'webp' );
        }
        if ( 'image/avif' === $mime ) {
            return self::quality( 'avif' );
        }
        return (int) EasyOpt_Config::get( 'easyopt_images_quality_jpeg', 82 );
    }

    public static function filter_big_image_threshold( $threshold ) {
        $max = self::max_dimension();
        return $max > 0 ? $max : $threshold;
    }

    /* ───────────────────────────────────────────────
     *  New uploads
     * ─────────────────────────────────────────────── */

    /**
     * Queue a freshly uploaded attachment.
     *
     * WordPress 7.1 fires this filter TWICE — once with context 'create' and
     * again with 'update'. We act on 'create' only. Acting on both would
     * enqueue the same attachment twice; the queue would dedupe it by task
     * hash, but relying on that to paper over a known double-fire is how you
     * end up debugging it a year later.
     *
     * We do NOT try to infer from the context whether the browser did the
     * encoding — the context describes the lifecycle stage, not the encoder,
     * and it fires identically on a Firefox upload that core processed
     * server-side. The client-side question is answered by an explicit
     * marker instead; see did_client_side().
     */
    public static function on_generate_metadata( $metadata, $attachment_id, $context = 'create' ) {
        if ( 'create' !== $context ) {
            return $metadata;
        }
        if ( ! in_array( (string) get_post_mime_type( $attachment_id ), self::MIME, true ) ) {
            return $metadata;
        }
        self::enqueue( array( (int) $attachment_id ) );
        return $metadata;
    }

    /**
     * Did core's client-side pipeline already encode this attachment?
     *
     * Answered by a marker our own JS shim stamps on completion, because
     * that is the only signal we control. Do not replace this with a guess
     * based on the metadata filter's context — see on_generate_metadata().
     */
    public static function did_client_side( $attachment_id ) {
        return (bool) get_post_meta( (int) $attachment_id, self::META_CLIENTSIDE, true );
    }

    /* ───────────────────────────────────────────────
     *  Queue
     * ─────────────────────────────────────────────── */

    private static function queue( $hook ) {
        static $queues = array();
        if ( ! isset( $queues[ $hook ] ) ) {
            $queues[ $hook ] = new EasyOpt_Queue( self::QUEUE_GROUP, $hook, self::MAX_RETRIES, 1 );
        }
        return $queues[ $hook ];
    }

    /**
     * Queue attachments for optimization.
     *
     * @param int[] $ids
     * @return int Number queued.
     */
    public static function enqueue( array $ids ) {
        $q = self::queue( self::HOOK_OPTIMIZE );
        $n = 0;
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 && $q->add_task( array( 'id' => $id ), 30 ) ) {
                $n++;
            }
        }
        if ( $n ) {
            $q->start_queue();
        }
        return $n;
    }

    /**
     * Queue attachments for restore.
     */
    public static function enqueue_restore( array $ids ) {
        $q = self::queue( self::HOOK_RESTORE );
        $n = 0;
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 && $q->add_task( array( 'id' => $id ), 10 ) ) {
                $n++;
            }
        }
        if ( $n ) {
            $q->start_queue();
        }
        return $n;
    }

    /**
     * Queue the whole library. Paged so a 50,000-image site does not build a
     * 50,000-element array in memory before inserting anything.
     *
     * @return int Number queued.
     */
    public static function enqueue_library( $limit = 0 ) {
        $paged = 1;
        $total = 0;

        do {
            $ids = get_posts( array(
                'post_type'              => 'attachment',
                'post_status'            => 'inherit',
                'post_mime_type'         => self::MIME,
                'posts_per_page'         => 200,
                'paged'                  => $paged,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ) );

            if ( empty( $ids ) ) {
                break;
            }

            $pending = array();
            foreach ( $ids as $id ) {
                // Skip anything already ATTEMPTED, not just anything with a
                // variant: an image that can never shrink (or that failed) has
                // meta with optimized_at but no variants, and must not be
                // re-encoded on every "Optimize all" — that is work that can
                // never complete and shows no progress.
                if ( ! self::has_been_attempted( $id ) ) {
                    $pending[] = $id;
                }
            }
            if ( $pending ) {
                $total += self::enqueue( $pending );
            }

            $paged++;
            if ( $limit > 0 && $total >= $limit ) {
                break;
            }
        } while ( true );

        return $total;
    }

    /* ───────────────────────────────────────────────
     *  Optimize
     * ─────────────────────────────────────────────── */

    /**
     * Queue callback. Optimizes the full-size file and every registered size.
     *
     * @param array $task { id }
     */
    public static function run_optimize( $task ) {
        $id = isset( $task['id'] ) ? (int) $task['id'] : 0;
        if ( $id <= 0 ) {
            return;
        }

        $formats = self::target_formats();
        if ( ! $formats ) {
            return; // Host cannot encode anything; nothing to do, quietly.
        }

        $main = get_attached_file( $id );
        if ( ! $main || ! is_readable( $main ) ) {
            return;
        }

        $meta    = (array) get_post_meta( $id, self::META, true );
        // Re-encode from scratch when the quality/format settings changed since
        // the last run — otherwise the mtime skip below keeps the stale variants
        // forever and a quality change never takes effect.
        $resign  = ( ( $meta['sig'] ?? '' ) !== self::settings_signature() );
        $record  = ( ! $resign && isset( $meta['variants'] ) && is_array( $meta['variants'] ) ) ? $meta['variants'] : array();
        $errors  = array();
        $base    = trailingslashit( dirname( $main ) );
        $targets = array( basename( $main ) );

        // Registered sizes share the directory with the full-size file.
        $wp_meta = wp_get_attachment_metadata( $id );
        if ( is_array( $wp_meta ) && ! empty( $wp_meta['sizes'] ) ) {
            foreach ( $wp_meta['sizes'] as $size ) {
                if ( ! empty( $size['file'] ) ) {
                    $targets[] = (string) $size['file'];
                }
            }
        }
        $targets = array_values( array_unique( $targets ) );

        // Optionally shrink the original itself. This is the ONLY path that
        // modifies a file the user uploaded, so it backs up first.
        if ( self::max_dimension() > 0 && (int) EasyOpt_Config::get( 'easyopt_images_resize_originals', 0 ) ) {
            self::maybe_shrink_original( $id, $main, $meta );
        }

        foreach ( $targets as $file ) {
            $source = $base . $file;
            if ( ! is_readable( $source ) ) {
                continue;
            }
            $original_bytes = (int) filesize( $source );

            foreach ( $formats as $format ) {
                $dest = $source . '.' . $format;

                // Already have a current variant at the current settings? Skip.
                if ( ! $resign
                    && EasyOpt_Images_Capability::valid_file( $dest, $format )
                    && filemtime( $dest ) >= filemtime( $source ) ) {
                    continue;
                }

                $res = EasyOpt_Images_Encoder::encode( $source, $dest, $format, self::quality( $format ), 0 );

                if ( is_wp_error( $res ) ) {
                    $errors[] = $file . ' → ' . $format . ': ' . $res->get_error_message();
                    continue;
                }

                // A variant that is not meaningfully smaller is a net loss:
                // it costs disk, and serving it gains nothing. Discard it.
                if ( $res['bytes'] >= $original_bytes * 0.95 ) {
                    @unlink( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                    continue;
                }

                $record[ $file ][ $format ] = array(
                    'bytes'    => $res['bytes'],
                    'original' => $original_bytes,
                    'encoder'  => $res['encoder'],
                );
            }
        }

        $meta['variants']    = $record;
        $meta['optimized_at'] = time();
        $meta['errors']      = array_slice( $errors, 0, 10 );
        $meta['saved']       = self::compute_saved( $record );
        $meta['sig']         = self::settings_signature();
        update_post_meta( $id, self::META, $meta );
    }

    /**
     * Shrink an oversized original in place, keeping a real backup.
     *
     * Backups mirror the uploads layout under uploads/easyopt-originals/ so
     * a restore is an unambiguous file-for-file copy back.
     */
    private static function maybe_shrink_original( $id, $path, &$meta ) {
        if ( ! empty( $meta['original_backed_up'] ) ) {
            return; // Already shrunk once; do not re-shrink a shrunk file.
        }

        $info = EasyOpt_Images_Encoder::inspect( $path );
        if ( is_wp_error( $info ) ) {
            return;
        }
        $max = self::max_dimension();
        if ( max( $info['width'], $info['height'] ) <= $max ) {
            return;
        }

        $backup = self::backup_path( $path );
        if ( ! $backup || ! wp_mkdir_p( dirname( $backup ) ) ) {
            return;
        }
        if ( ! file_exists( $backup ) && ! copy( $path, $backup ) ) {
            return; // No backup, no modification. Non-negotiable.
        }

        // Encode the shrunk version beside the original, then swap only
        // after it validates. A failed encode must never leave a truncated
        // file where the user's photo was.
        $format = 'image/png' === $info['mime'] ? 'png' : 'jpeg';
        $tmp    = $path . '.easyopt-tmp';
        $editor = wp_get_image_editor( $path );
        if ( is_wp_error( $editor ) ) {
            return;
        }
        $editor->set_quality( (int) EasyOpt_Config::get( 'easyopt_images_quality_jpeg', 82 ) );
        $editor->resize( $max, $max, false );
        $saved = $editor->save( $tmp );

        if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
            self::unlink_if( $tmp );
            return;
        }
        if ( 'jpeg' === $format && ! EasyOpt_Images_Capability::valid_file( $saved['path'], 'jpeg' ) ) {
            self::unlink_if( $saved['path'] );
            return;
        }

        if ( @rename( $saved['path'], $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $meta['original_backed_up'] = self::backup_relative( $path );
            $meta['original_bytes']     = (int) filesize( $backup );
        } else {
            self::unlink_if( $saved['path'] );
        }
    }

    /* ───────────────────────────────────────────────
     *  Restore
     * ─────────────────────────────────────────────── */

    /**
     * Queue callback. Undo everything we did to one attachment.
     *
     * Because variants are siblings and the database was never rewritten,
     * this is just file deletion plus a metadata delete. There is no partial
     * state it can leave behind: worst case a variant file survives, and the
     * delivery layer stops referencing it the moment the meta is gone.
     *
     * @param array $task { id }
     */
    public static function run_restore( $task ) {
        $id = isset( $task['id'] ) ? (int) $task['id'] : 0;
        if ( $id <= 0 ) {
            return;
        }

        $meta = (array) get_post_meta( $id, self::META, true );

        // 1. Delete every variant we generated.
        foreach ( self::variant_paths( $id ) as $path ) {
            self::unlink_if( $path );
        }

        // 2. Put back an original we shrank, if we shrank one.
        if ( ! empty( $meta['original_backed_up'] ) ) {
            $main   = get_attached_file( $id );
            $backup = self::backup_path( $main );
            if ( $main && $backup && is_readable( $backup ) ) {
                if ( copy( $backup, $main ) ) {
                    self::unlink_if( $backup );
                    // Dimensions changed back, so core's metadata is stale.
                    // run_restore is a queue callback (WP-Cron / REST loopback),
                    // neither of which loads wp-admin/includes/image.php where
                    // wp_generate_attachment_metadata() lives — pull it in or the
                    // restore fatals instead of regenerating the sizes.
                    if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
                        require_once ABSPATH . 'wp-admin/includes/image.php';
                    }
                    $regenerated = wp_generate_attachment_metadata( $id, $main );
                    if ( is_array( $regenerated ) ) {
                        wp_update_attachment_metadata( $id, $regenerated );
                    }
                }
            }
        }

        // 3. Forget we were ever here.
        delete_post_meta( $id, self::META );
        delete_post_meta( $id, self::META_CLIENTSIDE );
    }

    /**
     * Restore the entire library.
     */
    public static function restore_library() {
        $paged = 1;
        $total = 0;

        do {
            $ids = get_posts( array(
                'post_type'              => 'attachment',
                'post_status'            => 'inherit',
                'post_mime_type'         => self::MIME,
                'posts_per_page'         => 200,
                'paged'                  => $paged,
                'fields'                 => 'ids',
                'no_found_rows'          => true,
                'update_post_term_cache' => false,
                'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                    array( 'key' => self::META, 'compare' => 'EXISTS' ),
                ),
            ) );
            if ( empty( $ids ) ) {
                break;
            }
            $total += self::enqueue_restore( $ids );
            $paged++;
        } while ( true );

        return $total;
    }

    /**
     * An attachment is being deleted: take our variants and backup with it.
     */
    public static function on_delete_attachment( $id ) {
        foreach ( self::variant_paths( (int) $id ) as $path ) {
            self::unlink_if( $path );
        }
        $main = get_attached_file( (int) $id );
        if ( $main ) {
            self::unlink_if( self::backup_path( $main ) );
        }
    }

    /* ───────────────────────────────────────────────
     *  Paths and state
     * ─────────────────────────────────────────────── */

    /**
     * Every variant file belonging to an attachment, whether or not our meta
     * knows about it. Derived from the filesystem layout rather than trusted
     * metadata, so an interrupted run still cleans up completely.
     *
     * @return string[]
     */
    public static function variant_paths( $id ) {
        $main = get_attached_file( (int) $id );
        if ( ! $main ) {
            return array();
        }

        $base  = trailingslashit( dirname( $main ) );
        $files = array( basename( $main ) );

        $wp_meta = wp_get_attachment_metadata( (int) $id );
        if ( is_array( $wp_meta ) && ! empty( $wp_meta['sizes'] ) ) {
            foreach ( $wp_meta['sizes'] as $size ) {
                if ( ! empty( $size['file'] ) ) {
                    $files[] = (string) $size['file'];
                }
            }
        }

        $paths = array();
        foreach ( array_unique( $files ) as $file ) {
            foreach ( array( 'webp', 'avif' ) as $format ) {
                $candidate = $base . $file . '.' . $format;
                if ( file_exists( $candidate ) ) {
                    $paths[] = $candidate;
                }
            }
        }
        return $paths;
    }

    /** Absolute path of the backup copy for an original. */
    private static function backup_path( $original ) {
        $rel = self::backup_relative( $original );
        if ( '' === $rel ) {
            return '';
        }
        $up = wp_get_upload_dir();
        return trailingslashit( $up['basedir'] ) . self::BACKUP_DIR . '/' . $rel;
    }

    /** Uploads-relative path of an original, used as the backup key. */
    private static function backup_relative( $original ) {
        $up   = wp_get_upload_dir();
        $base = trailingslashit( wp_normalize_path( $up['basedir'] ) );
        $norm = wp_normalize_path( (string) $original );
        if ( 0 !== strpos( $norm, $base ) ) {
            return '';
        }
        return ltrim( substr( $norm, strlen( $base ) ), '/' );
    }

    public static function is_optimized( $id ) {
        $meta = get_post_meta( (int) $id, self::META, true );
        return is_array( $meta ) && ! empty( $meta['variants'] );
    }

    /**
     * Has this attachment already been through the optimizer at all — including
     * a run that produced no usable variant (too large, unsupported source,
     * animated GIF, or simply not smaller)? Distinct from is_optimized(), which
     * means "has a variant to serve". The bulk queue skips ATTEMPTED images so
     * a file that can never shrink is processed once, not on every run.
     */
    public static function has_been_attempted( $id ) {
        $meta = get_post_meta( (int) $id, self::META, true );
        // Sig-aware: an attempt made under DIFFERENT settings is stale, so the
        // image counts as not-yet-attempted and is re-queued at the new
        // quality/format. This is what makes a quality change take effect on a
        // library the mtime skip would otherwise leave untouched.
        return is_array( $meta ) && ! empty( $meta['optimized_at'] )
            && ( ( $meta['sig'] ?? '' ) === self::settings_signature() );
    }

    /**
     * Signature of every setting that changes the BYTES a variant would have.
     * Stored on each attachment; when it changes, existing variants are stale,
     * the image is re-queued, and the mtime skip is bypassed — so lowering
     * quality (or enabling a format) actually re-encodes the library instead of
     * reporting "all optimized" and doing nothing.
     */
    public static function settings_signature() {
        return md5( (string) wp_json_encode( array(
            (int) EasyOpt_Config::get( 'easyopt_images_avif', 1 ),
            (int) EasyOpt_Config::get( 'easyopt_images_webp', 1 ),
            self::quality( 'avif' ),
            self::quality( 'webp' ),
            (int) EasyOpt_Config::get( 'easyopt_images_quality_jpeg', 82 ),
            self::max_dimension(),
            (int) EasyOpt_Config::get( 'easyopt_images_resize_originals', 0 ),
        ) ) );
    }

    /**
     * Library-wide stats for the dashboard.
     */
    public static function stats() {
        // The expensive half — a scan of every _easyopt_img row — is cached
        // briefly and keyed on the settings signature, so a 2s progress poll on
        // a large library does not re-scan every tick (and a settings change
        // invalidates it at once). Queue depth is always live below.
        $agg = get_transient( self::STATS_CACHE );
        if ( ! is_array( $agg ) || ( $agg['sig'] ?? '' ) !== self::settings_signature() ) {
            $agg = self::compute_aggregate();
            set_transient( self::STATS_CACHE, $agg, 20 );
        }

        // Live queue depth — cheap, and must stay real-time for the poll.
        $oq        = self::queue( self::HOOK_OPTIMIZE );
        $rq        = self::queue( self::HOOK_RESTORE );
        $pending   = $oq->get_pending_count();
        $failed    = $oq->get_failed_count();
        $restoring = $rq->get_pending_count();

        return array(
            'total'       => $agg['total'],
            'optimized'   => $agg['optimized'],
            // Reaches 100% even when some images can't be shrunk, and re-opens
            // when settings change (those images become stale = not current).
            'remaining'   => max( 0, $agg['total'] - $agg['current'] ),
            // Attempted at the current settings but produced no servable variant.
            'skipped'     => $agg['skipped'],
            'saved'       => $agg['saved'],
            'saved_human' => size_format( $agg['saved'] ),
            'formats'     => EasyOpt_Images_Capability::available_formats(),
            'capability'  => EasyOpt_Images_Capability::summary(),
            // Live progress.
            'pending'     => $pending,
            'failed'      => $failed,
            'restoring'   => $restoring,
            'running'     => ( $pending + $restoring ) > 0,
        );
    }

    /**
     * The expensive library aggregate: one scan of every _easyopt_img row.
     * `current` counts images optimized AT THE CURRENT settings signature, so
     * a quality change makes stale images fall out of `current` and back into
     * `remaining`.
     */
    private static function compute_aggregate() {
        global $wpdb;
        $sig = self::settings_signature();

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png','image/gif')
                 AND post_status = %s",
                'inherit'
            )
        );
        $rows = $wpdb->get_col(
            $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META )
        );

        $optimized = 0;
        $current   = 0;
        $skipped   = 0;
        $saved     = 0;
        foreach ( $rows as $raw ) {
            $meta = maybe_unserialize( $raw );
            if ( ! is_array( $meta ) ) {
                continue;
            }
            $sig_ok = ( ( $meta['sig'] ?? '' ) === $sig );
            if ( ! empty( $meta['variants'] ) ) {
                $optimized++;
                $saved += isset( $meta['saved'] ) ? (int) $meta['saved'] : 0;
            }
            if ( ! empty( $meta['optimized_at'] ) && $sig_ok ) {
                $current++;
                if ( empty( $meta['variants'] ) ) {
                    $skipped++;
                }
            }
        }

        return array(
            'total'     => $total,
            'optimized' => $optimized,
            'current'   => $current,
            'skipped'   => $skipped,
            'saved'     => $saved,
            'sig'       => $sig,
        );
    }

    /* ───────────────────────────────────────────────
     *  Settings helpers
     * ─────────────────────────────────────────────── */

    /**
     * Formats to generate, intersected with what the host can actually do.
     * A user who ticked AVIF on a host without libavif gets WebP and an
     * honest message, not a silent no-op.
     */
    public static function target_formats() {
        $wanted = array();
        if ( (int) EasyOpt_Config::get( 'easyopt_images_avif', 1 ) ) {
            $wanted[] = 'avif';
        }
        if ( (int) EasyOpt_Config::get( 'easyopt_images_webp', 1 ) ) {
            $wanted[] = 'webp';
        }
        return array_values( array_intersect( $wanted, EasyOpt_Images_Capability::available_formats() ) );
    }

    public static function quality( $format ) {
        $key = 'avif' === $format ? 'easyopt_images_quality_avif' : 'easyopt_images_quality_webp';
        $def = 'avif' === $format ? 55 : 78;
        return max( 1, min( 100, (int) EasyOpt_Config::get( $key, $def ) ) );
    }

    public static function max_dimension() {
        return max( 0, (int) EasyOpt_Config::get( 'easyopt_images_max_dimension', 0 ) );
    }

    private static function compute_saved( $variants ) {
        $saved = 0;
        foreach ( (array) $variants as $formats ) {
            // Credit the smallest variant per file — that is the one a
            // modern browser will actually be served.
            $best     = 0;
            $original = 0;
            foreach ( (array) $formats as $data ) {
                $original = max( $original, (int) $data['original'] );
                $bytes    = (int) $data['bytes'];
                if ( 0 === $best || $bytes < $best ) {
                    $best = $bytes;
                }
            }
            if ( $original && $best ) {
                $saved += max( 0, $original - $best );
            }
        }
        return $saved;
    }

    private static function unlink_if( $path ) {
        if ( $path && file_exists( $path ) ) {
            @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
    }
}
