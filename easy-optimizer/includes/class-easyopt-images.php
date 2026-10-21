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

    /**
     * Set when the user restores ONE image from the Media Library. Bulk runs
     * leave it alone until they optimize it again — otherwise the next
     * "Optimize all" would simply redo what they just undid. Kept apart from
     * META so restore (which deletes META) never clears it.
     */
    const META_EXCLUDE = '_easyopt_img_exclude';

    /** Queue wiring. */
    const QUEUE_GROUP    = 'images';
    const HOOK_OPTIMIZE  = 'easyopt_image_optimize';
    const HOOK_RESTORE   = 'easyopt_image_restore';
    const MAX_RETRIES    = 2;

    /** Directory (under uploads) holding backups of modified originals. */
    const BACKUP_DIR = 'easyopt-originals';

    /** Sources we will process. */
    const MIME = array( 'image/jpeg', 'image/png', 'image/gif' );

    /** True while restore regenerates core's sizes — not a new upload. */
    private static $restoring = false;

    /**
     * Hooks that must exist whether or not the feature is enabled, so that
     * disabling the feature never orphans variants or leaks a restore job.
     */
    public static function init_always() {
        add_action( self::HOOK_OPTIMIZE, array( __CLASS__, 'run_optimize' ) );
        add_action( self::HOOK_RESTORE, array( __CLASS__, 'run_restore' ) );
        add_action( 'delete_attachment', array( __CLASS__, 'on_delete_attachment' ) );
        // Every process that dispatches runners (admin click, the REST runner
        // re-dispatching itself, the cron watchdog) passes through here, so the
        // filters see the same numbers everywhere.
        add_filter( 'easyopt_queue_concurrency', array( __CLASS__, 'filter_queue_concurrency' ), 10, 3 );
        add_filter( 'easyopt_queue_task_delay', array( __CLASS__, 'filter_queue_task_delay' ), 10, 2 );
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

        // Per-image status, Restore and Optimize in the Media Library.
        if ( is_admin() ) {
            EasyOpt_Images_Media::init();
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
        // Restore regenerates sizes through this same filter with 'create'.
        // Queueing then would re-optimize (and re-shrink) what was just restored.
        if ( 'create' !== $context || self::$restoring ) {
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
            // Optimize concurrency comes from filter_queue_concurrency(), which
            // runner processes that never build this object also see.
            $queues[ $hook ] = new EasyOpt_Queue( self::QUEUE_GROUP, $hook, self::MAX_RETRIES, 1 );
        }
        return $queues[ $hook ];
    }

    /**
     * How many images to encode at once.
     *
     * Encoding is CPU-bound and single-threaded per image, so one runner uses
     * one core however large the server is: a 12,000-image library took about
     * 38 hours one at a time (11 s per image, every size, WebP + AVIF). Half
     * the CPUs this site may use, leaving the rest for visitors, at most 3 by
     * default — each runner also holds a PHP worker, and a stock PHP-FPM pool
     * has only 5. EASYOPT_IMAGES_CONCURRENCY or the easyopt_images_concurrency
     * filter set it outright (the queue caps it at EasyOpt_Queue::MAX_CONCURRENCY).
     *
     * @return int
     */
    public static function concurrency() {
        $n = defined( 'EASYOPT_IMAGES_CONCURRENCY' )
            ? (int) EASYOPT_IMAGES_CONCURRENCY
            : min( 3, intdiv( self::cpu_count(), 2 ) );
        return max( 1, (int) apply_filters( 'easyopt_images_concurrency', $n ) );
    }

    /**
     * CPUs this site may actually use, 1 when unknown. Takes the LOWEST of the
     * signals available, because each can over-report on its own: the affinity
     * list shows every host CPU under CloudLinux (whose cpuinfo is virtualised
     * to the account's limit), and both show every host CPU inside a container
     * whose limit is a cgroup quota. Unreadable files (open_basedir) count as
     * absent, which falls back to 1 — today's behaviour.
     *
     * @return int
     */
    public static function cpu_count() {
        static $n = null;
        if ( null === $n ) {
            $n = self::cpu_count_from( array(
                'status'      => (string) @file_get_contents( '/proc/self/status' ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                'cpuinfo'     => (string) @file_get_contents( '/proc/cpuinfo' ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                'cpu_max'     => (string) @file_get_contents( '/sys/fs/cgroup/cpu.max' ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                'cfs_quota'   => (string) @file_get_contents( '/sys/fs/cgroup/cpu/cpu.cfs_quota_us' ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                'cfs_period'  => (string) @file_get_contents( '/sys/fs/cgroup/cpu/cpu.cfs_period_us' ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                'windows'     => '\\' === DIRECTORY_SEPARATOR ? (string) getenv( 'NUMBER_OF_PROCESSORS' ) : '',
            ) );
        }
        return $n;
    }

    /**
     * cpu_count() over already-read file contents (testable without /proc).
     *
     * @param array $src status, cpuinfo, cpu_max, cfs_quota, cfs_period, windows.
     * @return int
     */
    public static function cpu_count_from( array $src ) {
        $seen = array();

        if ( preg_match( '/^Cpus_allowed_list:\s*(\S+)/m', (string) ( $src['status'] ?? '' ), $m ) ) {
            $count = 0;
            foreach ( explode( ',', $m[1] ) as $part ) {
                $b      = explode( '-', $part, 2 );
                $count += isset( $b[1] ) ? max( 0, (int) $b[1] - (int) $b[0] + 1 ) : 1;
            }
            $seen[] = $count;
        }
        $procs = preg_match_all( '/^processor\s*:/m', (string) ( $src['cpuinfo'] ?? '' ) );
        if ( $procs ) {
            $seen[] = $procs;
        }
        // cgroup v2 "<quota> <period>" ("max" = unlimited), then cgroup v1.
        if ( preg_match( '/^(\d+)\s+(\d+)/', (string) ( $src['cpu_max'] ?? '' ), $m ) && (int) $m[2] > 0 ) {
            $seen[] = (int) ceil( (int) $m[1] / (int) $m[2] );
        } elseif ( (int) ( $src['cfs_quota'] ?? 0 ) > 0 && (int) ( $src['cfs_period'] ?? 0 ) > 0 ) {
            $seen[] = (int) ceil( (int) $src['cfs_quota'] / (int) $src['cfs_period'] );
        }
        if ( (int) ( $src['windows'] ?? 0 ) > 0 ) {
            $seen[] = (int) $src['windows'];
        }

        $seen = array_filter( $seen );
        return $seen ? max( 1, min( $seen ) ) : 1;
    }

    /** easyopt_queue_concurrency — optimize runners only; restore stays at 1. */
    public static function filter_queue_concurrency( $c, $group, $callback = '' ) {
        if ( self::QUEUE_GROUP === $group && self::HOOK_OPTIMIZE === $callback ) {
            return self::concurrency();
        }
        return $c;
    }

    /**
     * easyopt_queue_task_delay — no pause between images. The queue's default
     * 0.5 s spaces out page renders for the origin; next to an 11 s encode it
     * protects nothing and added about 1.7 hours to a 12,000-image run.
     */
    public static function filter_queue_task_delay( $delay, $group = '' ) {
        return self::QUEUE_GROUP === $group ? 0 : $delay;
    }

    /**
     * Queue attachments for optimization.
     *
     * @param int[] $ids
     * @param bool  $start Dispatch runners now. enqueue_library() passes false
     *                     and starts once at the end — starting per page sent
     *                     a fresh batch of runners every 200 rows, before the
     *                     first ones had claimed anything to count as active.
     * @return int Number queued.
     */
    public static function enqueue( array $ids, $start = true ) {
        $q = self::queue( self::HOOK_OPTIMIZE );
        $n = 0;
        foreach ( $ids as $id ) {
            $id = (int) $id;
            if ( $id > 0 && $q->add_task( array( 'id' => $id ), 30 ) ) {
                $n++;
            }
        }
        if ( $n ) {
            // Queued images leave the cached "untouched" count (see stats()).
            delete_transient( self::STATS_CACHE );
            if ( $start ) {
                $q->start_queue();
            }
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
            delete_transient( self::STATS_CACHE );
            $q->start_queue();
        }
        return $n;
    }

    /**
     * Stop a bulk run (or a restore-all). Drops every queued task; the image
     * being encoded right now finishes, and everything already done stays
     * done. "Optimize N remaining" picks up where it stopped.
     */
    public static function stop() {
        self::queue( self::HOOK_OPTIMIZE )->clear_queue();
        self::queue( self::HOOK_RESTORE )->clear_queue();
        delete_transient( self::STATS_CACHE );
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
                $total += self::enqueue( $pending, false );
            }

            $paged++;
            if ( $limit > 0 && $total >= $limit ) {
                break;
            }
        } while ( true );

        if ( $total ) {
            self::queue( self::HOOK_OPTIMIZE )->start_queue();
        }
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
        if ( $id <= 0 || self::is_excluded( $id ) ) {
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

        // A runner the server kills mid-image (PHP time or memory limit) never
        // reaches the queue's failure path: the row is simply claimed again once
        // its lock expires — and, as the oldest row, ahead of every other image,
        // so one image could hold the whole run forever. An exception would have
        // failed the row at MAX_RETRIES (encoder errors are recorded, not
        // thrown), so a claim count past it means earlier runs died. Record it
        // as attempted (it then counts as skipped, and single Optimize in the
        // Media Library can still retry it) and move on.
        if ( (int) ( $task['_attempts'] ?? 0 ) > self::MAX_RETRIES ) {
            $meta['optimized_at'] = time();
            $meta['sig']          = self::settings_signature();
            $meta['errors']       = array( 'The server stopped the optimizer while it was encoding this image (time or memory limit), so it was skipped.' );
            update_post_meta( $id, self::META, $meta );
            return;
        }
        $task_id    = (int) ( $task['_task_id'] ?? 0 );
        $lock_token = (string) ( $task['_lock_token'] ?? '' );

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

                // Each encode gets its own time budget: a runner processes images
                // for up to 20 s and then finishes the one in hand, so one large
                // image could cross a 30 s max_execution_time and be killed.
                if ( function_exists( 'set_time_limit' ) ) {
                    @set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                }

                $res = EasyOpt_Images_Encoder::encode( $source, $dest, $format, self::quality( $format ), 0 );

                // Still alive: keep the claim. The queue hands a row whose lock
                // is older than 120 s to another runner, which then encoded the
                // same image a second time alongside this one.
                if ( $task_id > 0 ) {
                    EasyOpt_Queue::touch_lock( $task_id, $lock_token );
                }

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

        // Restored from the Media Library while this encode ran: undo the run
        // (variants and any shrink) so the user's restore holds. The meta cache
        // was filled when this run started, so drop it to see the restore.
        wp_cache_delete( $id, 'post_meta' );
        if ( self::is_excluded( $id ) ) {
            self::run_restore( array( 'id' => $id ) );
        }
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
                    self::$restoring = true;
                    $regenerated     = wp_generate_attachment_metadata( $id, $main );
                    self::$restoring = false;
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
     * Restore ONE image now (Media Library) and keep bulk runs off it.
     * Exclusion first, so an encode of this image already in flight sees it
     * when it finishes and undoes itself (see run_optimize()).
     */
    public static function restore_one( $id ) {
        $id = (int) $id;
        update_post_meta( $id, self::META_EXCLUDE, 1 );
        self::run_restore( array( 'id' => $id ) );
        delete_transient( self::STATS_CACHE );
    }

    /**
     * Optimize ONE image (Media Library): lift a restore's exclusion, queue it.
     *
     * @return bool Queued.
     */
    public static function optimize_one( $id ) {
        $id = (int) $id;
        delete_post_meta( $id, self::META_EXCLUDE );
        delete_transient( self::STATS_CACHE );
        return self::enqueue( array( $id ) ) > 0;
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

    /** Restored by hand from the Media Library (see META_EXCLUDE). */
    public static function is_excluded( $id ) {
        return (bool) get_post_meta( (int) $id, self::META_EXCLUDE, true );
    }

    /**
     * Has this attachment already been through the optimizer at all — including
     * a run that produced no usable variant (too large, unsupported source,
     * animated GIF, or simply not smaller)? Distinct from is_optimized(), which
     * means "has a variant to serve". The bulk queue skips ATTEMPTED images so
     * a file that can never shrink is processed once, not on every run.
     */
    public static function has_been_attempted( $id ) {
        if ( self::is_excluded( $id ) ) {
            return true; // Restored by hand: bulk runs skip it.
        }
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
        // Live queue depth — cheap, and must stay real-time for the poll.
        $oq        = self::queue( self::HOOK_OPTIMIZE );
        $rq        = self::queue( self::HOOK_RESTORE );
        $pending   = $oq->get_pending_count();
        $failed    = $oq->get_failed_count();
        $restoring = $rq->get_pending_count();
        $busy      = ( $pending + $restoring ) > 0;

        // The expensive half — a scan of every _easyopt_img row — is cached
        // briefly and keyed on the settings signature, so a 2s progress poll on
        // a large library does not re-scan every tick (and a settings change
        // invalidates it at once). Also re-scanned once when the queue goes
        // idle, so the resting numbers are exact rather than up to 20s old.
        $agg = get_transient( self::STATS_CACHE );
        if ( ! is_array( $agg ) || ( $agg['sig'] ?? '' ) !== self::settings_signature()
            || ( ! empty( $agg['busy'] ) && ! $busy ) ) {
            $agg         = self::compute_aggregate();
            $agg['busy'] = $busy;
            // Images neither processed nor queued. Unlike `current`, this does
            // not move while the queue works (it only moves when something is
            // queued or restored, and both clear this cache), so the live
            // `processed` below stays exact on every poll with no re-scan.
            $agg['untouched'] = max( 0, $agg['total'] - $agg['current'] - $pending - $failed );
            set_transient( self::STATS_CACHE, $agg, 20 );
        }

        return array(
            'total'       => $agg['total'],
            // Live. Every image that is neither queued nor untouched has been
            // processed: optimized, skipped, failed or restored by hand. So
            // processed + pending = total for a full run, on every poll.
            'processed'   => max( 0, min( $agg['total'], $agg['total'] - $pending - $agg['untouched'] ) ),
            'optimized'   => $agg['optimized'],
            // Reaches 100% even when some images can't be shrunk, and re-opens
            // when settings change (those images become stale = not current).
            'remaining'   => max( 0, $agg['total'] - $agg['current'] ),
            // Attempted at the current settings but produced no servable variant.
            'skipped'     => $agg['skipped'],
            // Restored one by one in the Media Library; bulk runs skip them.
            'excluded'    => $agg['excluded'],
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

        // Restored by hand: done as far as bulk runs are concerned, so they
        // count as current or "Optimize N remaining" could never reach zero.
        // Restore deleted their _easyopt_img row, so no double count.
        $excluded = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_EXCLUDE )
        );

        return array(
            'total'     => $total,
            'optimized' => $optimized,
            'current'   => min( $total, $current + $excluded ),
            'skipped'   => $skipped,
            'excluded'  => $excluded,
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
