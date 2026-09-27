<?php
/**
 * Database cleanup module.
 *
 * Each cleanup task is keyed by an option (`easyopt_db_<task>`). Users tick
 * what they want, hit Run Now, and we execute every enabled task in a single
 * AJAX call. A daily/weekly/monthly cron picks up the same task list so the
 * cleanup keeps happening unattended.
 *
 * Why direct SQL for the post/comment cleanups: WP's API helpers
 * (wp_delete_post, wp_delete_comment) trigger meta cascades and revision
 * loops that on a busy site can take minutes. Direct DELETE statements
 * cover the same rows in one round-trip; we still call the meta-cleanup
 * helpers separately to avoid orphaned postmeta / commentmeta.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Database {

    const CRON_HOOK = 'easyopt_db_cleanup_tick';

    public static function init() {
        // Cleanup run, counts, and snapshot list/restore/delete are served by
        // the REST dashboard (/database/run, /database/counts,
        // /database/snapshots, /database/snapshot-restore,
        // /database/snapshot-delete). The old admin-ajax twins were unused by
        // the React app and have been removed.

        // Cron handler.
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled' ) );

        // Re-schedule whenever the schedule option changes.
        add_action( 'updated_option', array( __CLASS__, 'maybe_reschedule' ), 20, 1 );
        add_action( 'add_option',     array( __CLASS__, 'maybe_reschedule' ), 20, 1 );

        // First boot — make sure cron matches stored schedule.
        // (2.5.4 / perf #5) wp_next_scheduled() walks the whole cron array;
        // running the self-heal on every frontend request was waste. Admin
        // and cron requests are frequent enough to keep the schedule healthy.
        if ( is_admin() || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
            if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
                $sched = (string) EasyOpt_Config::get( 'db_schedule', '' );
                if ( '' !== $sched && in_array( $sched, array( 'daily', 'weekly', 'monthly' ), true ) ) {
                    // (2.5.4 / perf #54) +3h (queue GC seeds at +1h): both
                    // dailies landing on the same wp-cron minute made one
                    // unlucky request pay GC + OPTIMIZE + DB cleanup + PSI
                    // together. The offset keeps them ~2h apart forever.
                    wp_schedule_event( time() + 3 * HOUR_IN_SECONDS, $sched, self::CRON_HOOK );
                }
            }
        }
    }

    /** Tasks the user can enable (option-name → human label). */
    public static function tasks() {
        return array(
            'post_revisions'    => __( 'Post revisions', 'easy-optimizer' ),
            'auto_drafts'       => __( 'Auto drafts', 'easy-optimizer' ),
            'trashed_posts'     => __( 'Trashed posts', 'easy-optimizer' ),
            'spam_comments'     => __( 'Spam comments', 'easy-optimizer' ),
            'trashed_comments'  => __( 'Trashed comments', 'easy-optimizer' ),
            'expired_transients'=> __( 'Expired transients', 'easy-optimizer' ),
            'all_transients'    => __( 'All transients', 'easy-optimizer' ),
            'optimize_tables'   => __( 'Optimize database tables', 'easy-optimizer' ),
        );
    }

    /* ─────────────────────────────────────────────
     *  Counters — drive the "X items" badges on the settings page.
     * ───────────────────────────────────────────── */

    public static function counts() {
        global $wpdb;

        $out = array();

        $out['post_revisions']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
        $out['auto_drafts']      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" );
        $out['trashed_posts']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" );
        $out['spam_comments']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'" );
        $out['trashed_comments'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash' OR comment_approved = 'post-trashed'" );

        // Expired transients = where timeout < now.
        $out['expired_transients'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE %s
             AND option_value < %d",
            '\_transient\_timeout\_%',
            time()
        ) );

        $out['all_transients'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE '\\_transient\\_%'
             OR option_name LIKE '\\_site\\_transient\\_%'"
        );

        return $out;
    }

    /* ─────────────────────────────────────────────
     *  Cleanup runners
     * ───────────────────────────────────────────── */

    /**
     * Run every enabled task. 1.6.2 — loops the chunked run_task inside a
     * time budget so we don't ship the old "delete-all-in-one-pass" bomb.
     * Each call to this function processes whatever fits in the budget;
     * subsequent calls (from cron or repeat AJAX) pick up where we left off.
     *
     * Returns a map of task → rows-affected-this-call. Tasks still pending
     * leave a `easyopt_db_pending` option set so the next scheduled tick
     * resumes them.
     *
     * @param int $time_budget Hard wall-clock cap, seconds. Default 25 to
     *                         stay well below standard 30s PHP max.
     * @return array
     */
    public static function run_enabled( $time_budget = 25 ) {
        $report     = array();
        $started    = microtime( true );
        $session_id = (string) time();
        $pending    = array();

        foreach ( self::tasks() as $key => $_label ) {
            if ( ! (int) EasyOpt_Config::get( 'db_' . $key, 0 ) ) {
                continue;
            }
            $total_for_task = 0;
            $offset         = 0;
            $next           = 0;

            // Pump the chunked runner until it's done OR we burn our budget.
            do {
                $remaining_budget = $time_budget - ( microtime( true ) - $started );
                if ( $remaining_budget < 1 ) {
                    $pending[ $key ] = $next; // resume here next time
                    break;
                }
                $r = self::run_task( $key, $next, min( 15, (int) $remaining_budget ), $session_id );
                $total_for_task += (int) ( $r['processed'] ?? 0 );
                $next            = (int) ( $r['next_offset'] ?? 0 );
                if ( ! empty( $r['done'] ) ) {
                    break;
                }
            } while ( true );

            $report[ $key ] = $total_for_task;
            $offset = $next;
            unset( $offset );
        }

        update_option( 'easyopt_db_last_run',    time(),  false );
        update_option( 'easyopt_db_last_report', $report, false );
        // Pending tasks (if any) get resumed by the next cron tick.
        if ( ! empty( $pending ) ) {
            update_option( 'easyopt_db_pending', $pending, false );
        } else {
            delete_option( 'easyopt_db_pending' );
        }

        return $report;
    }

    public static function run_scheduled() {
        if ( ! (int) EasyOpt_Config::get( 'db_cron_enabled', 0 ) ) {
            return;
        }
        // re-evaluating which tasks are enabled. This keeps a long cleanup
        // (50k revisions) progressing across multiple cron ticks rather
        // than starting from zero each night.
        self::run_enabled( 25 );
    }

    /**
     * Single-task dispatcher — 1.6.2 chunked version.
     *
     * Each call processes up to `db_chunk_size` rows (default 500) and stops
     * when EITHER the chunk is full OR the wall-clock budget expires. The
     * caller (AJAX or run_enabled) loops until `$result['done'] === true`.
     *
     * Snapshot integration: before deleting, every row is appended to a
     * gzipped NDJSON file in uploads/easyopt-snapshots/. Restorable from
     * the Snapshots tab for `db_snapshot_retention_days` (default 14).
     *
     * Returns an associative array:
     *   processed     int   rows touched in THIS call
     *   next_offset   int   pass back on the next call (currently always 0
     *                       since we DELETE in place — kept for API
     *                       symmetry with potential future read-only tasks)
     *   done          bool  true when the task has nothing left to do
     *   snapshot      string path of the snapshot file, or '' if disabled
     *
     * @param string $key          Task key.
     * @param int    $offset       Resume offset (kept for API compatibility).
     * @param int    $time_budget  Wall-clock cap for this call, seconds.
     * @param string $session_id   Snapshot session id (groups multiple chunks).
     * @return array
     */
    public static function run_task( $key, $offset = 0, $time_budget = 15, $session_id = '' ) {
        global $wpdb;
        unset( $offset );  // accepted for API symmetry, not currently needed

        $start       = microtime( true );
        $session_id  = '' === $session_id ? (string) time() : (string) $session_id;
        $chunk_size  = max( 50, min( 2000, (int) EasyOpt_Config::get( 'db_chunk_size', 500 ) ) );
        $processed   = 0;
        $snapshot_on = class_exists( 'EasyOpt_DB_Snapshot' ) && EasyOpt_DB_Snapshot::enabled();
        $snap_path   = '';

        // (2.5.4 / perf #29) Suppress the per-row cache-purge fan-out during
        // bulk deletion tasks. Deleting already-trashed posts (their URLs were
        // purged when trashed) and spam/trashed comments (never rendered)
        // cannot change public HTML, yet each wp_delete_post/wp_delete_comment
        // fires the purge listeners — on a 10k-row cleanup that was thousands
        // of no-value permalink lookups and globs. try/finally guarantees the
        // flag is restored even if a delete throws.
        $bulk_purge = in_array( $key, array( 'post_revisions', 'auto_drafts', 'trashed_posts', 'spam_comments', 'trashed_comments' ), true )
            && class_exists( 'EasyOpt_Cache' )
            && method_exists( 'EasyOpt_Cache', 'set_bulk_purge_mode' );
        if ( $bulk_purge ) {
            EasyOpt_Cache::set_bulk_purge_mode( true );
        }
        try {
            return self::run_task_inner( $key, $chunk_size, $start, $time_budget, $session_id, $snapshot_on, $snap_path, $processed );
        } finally {
            if ( $bulk_purge ) {
                EasyOpt_Cache::set_bulk_purge_mode( false );
            }
        }
    }


    /**
     * (2.5.4 / perf #30) Cache-coherence sweep after direct-SQL transient
     * deletion. The chunked DELETEs above bypass delete_transient(), so on
     * hosts with a persistent object cache Redis keeps serving the deleted
     * transients' cached copies until their TTLs run out — and every option
     * row deleted may still have an individually cached 'options'-group
     * entry. One bounded group flush per completed task (NOT per chunk)
     * restores coherence; without an external cache the runtime arrays die
     * with the request and nothing is needed.
     *
     * @param bool $site_too Also flush site-transient groups.
     */
    private static function flush_transient_caches( $site_too = true ) {
        if ( ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) {
            return;
        }
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( 'transient' );
            wp_cache_flush_group( 'transient_timeout' );
            if ( $site_too ) {
                wp_cache_flush_group( 'site-transient' );
                wp_cache_flush_group( 'site-transient_timeout' );
            }
        }
        // The deleted rows may also sit as individual 'options'-group keys
        // (get_option caches transient rows it reads). notoptions/alloptions
        // stay valid (transients are non-autoloaded); dropping the two
        // sentinel keys is cheap insurance either way.
        wp_cache_delete( 'notoptions', 'options' );
        wp_cache_delete( 'alloptions', 'options' );
    }

    /**
     * (2.5.4 / perf #29) Body of run_task(), extracted unchanged so the
     * bulk-purge flag can be try/finally-guarded around every return path.
     */
    private static function run_task_inner( $key, $chunk_size, $start, $time_budget, $session_id, $snapshot_on, $snap_path, $processed ) {
        global $wpdb;

        // Helper: time / memory budget check between row batches.
        $out_of_time = function () use ( $start, $time_budget ) {
            return ( microtime( true ) - $start ) >= $time_budget;
        };

        switch ( $key ) {

            case 'post_revisions':
                while ( ! $out_of_time() ) {
                    $ids = $wpdb->get_col( $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts}
                         WHERE post_type = 'revision' LIMIT %d",
                        $chunk_size
                    ) );
                    if ( empty( $ids ) ) {
                        return self::result( $processed, true, $snap_path );
                    }
                    if ( $snapshot_on ) {
                        $snap_path = self::snapshot_rows( $session_id, 'post_revisions', $wpdb->posts, $ids ) ?: $snap_path;
                    }
                    foreach ( $ids as $id ) {
                        if ( wp_delete_post_revision( (int) $id ) ) {
                            $processed++;
                        }
                        if ( $out_of_time() ) { break; }
                    }
                }
                // Cheap "any left?" check without re-scanning the whole table.
                $remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision' LIMIT 1" );
                return self::result( $processed, $remaining === 0, $snap_path );

            case 'auto_drafts':
                // Auto-drafts are throwaway WP scaffolding (the row created
                // when you click "Add New" but never publish). Snapshot
                // before delete in case the user has unsaved valuable
                // content in one of them.
                while ( ! $out_of_time() ) {
                    $rows = $wpdb->get_results( $wpdb->prepare(
                        "SELECT * FROM {$wpdb->posts}
                         WHERE post_status = 'auto-draft' LIMIT %d",
                        $chunk_size
                    ), ARRAY_A );
                    if ( empty( $rows ) ) { break; }
                    if ( $snapshot_on ) {
                        $snap_path = EasyOpt_DB_Snapshot::append(
                            'auto_drafts', $session_id, $wpdb->posts, $rows
                        ) ?: $snap_path;
                    }
                    $ids = wp_list_pluck( $rows, 'ID' );
                    $in  = implode( ',', array_map( 'intval', $ids ) );
                    $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ($in)" );
                    $processed += count( $ids );
                }
                $remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft' LIMIT 1" );
                return self::result( $processed, $remaining === 0, $snap_path );

            case 'trashed_posts':
                while ( ! $out_of_time() ) {
                    $rows = $wpdb->get_results( $wpdb->prepare(
                        "SELECT * FROM {$wpdb->posts}
                         WHERE post_status = 'trash' LIMIT %d",
                        $chunk_size
                    ), ARRAY_A );
                    if ( empty( $rows ) ) { break; }
                    if ( $snapshot_on ) {
                        $snap_path = EasyOpt_DB_Snapshot::append(
                            'trashed_posts', $session_id, $wpdb->posts, $rows
                        ) ?: $snap_path;
                        // Also snapshot postmeta so restore brings back
                        // featured images, custom fields, ACF, etc.
                        $ids = array_map( 'intval', wp_list_pluck( $rows, 'ID' ) );
                        if ( ! empty( $ids ) ) {
                            $in = implode( ',', $ids );
                            $meta = $wpdb->get_results( "SELECT * FROM {$wpdb->postmeta} WHERE post_id IN ($in)", ARRAY_A );
                            if ( ! empty( $meta ) ) {
                                EasyOpt_DB_Snapshot::append(
                                    'trashed_posts', $session_id, $wpdb->postmeta, $meta
                                );
                            }
                        }
                    }
                    foreach ( $rows as $row ) {
                        if ( wp_delete_post( (int) $row['ID'], true ) ) {
                            $processed++;
                        }
                        if ( $out_of_time() ) { break; }
                    }
                }
                $remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash' LIMIT 1" );
                return self::result( $processed, $remaining === 0, $snap_path );

            case 'spam_comments':
                while ( ! $out_of_time() ) {
                    $rows = $wpdb->get_results( $wpdb->prepare(
                        "SELECT * FROM {$wpdb->comments}
                         WHERE comment_approved = 'spam' LIMIT %d",
                        $chunk_size
                    ), ARRAY_A );
                    if ( empty( $rows ) ) { break; }
                    if ( $snapshot_on ) {
                        $snap_path = EasyOpt_DB_Snapshot::append(
                            'spam_comments', $session_id, $wpdb->comments, $rows
                        ) ?: $snap_path;
                    }
                    foreach ( $rows as $row ) {
                        if ( wp_delete_comment( (int) $row['comment_ID'], true ) ) {
                            $processed++;
                        }
                        if ( $out_of_time() ) { break; }
                    }
                }
                $remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam' LIMIT 1" );
                return self::result( $processed, $remaining === 0, $snap_path );

            case 'trashed_comments':
                while ( ! $out_of_time() ) {
                    $rows = $wpdb->get_results( $wpdb->prepare(
                        "SELECT * FROM {$wpdb->comments}
                         WHERE comment_approved IN ('trash','post-trashed') LIMIT %d",
                        $chunk_size
                    ), ARRAY_A );
                    if ( empty( $rows ) ) { break; }
                    if ( $snapshot_on ) {
                        $snap_path = EasyOpt_DB_Snapshot::append(
                            'trashed_comments', $session_id, $wpdb->comments, $rows
                        ) ?: $snap_path;
                    }
                    foreach ( $rows as $row ) {
                        if ( wp_delete_comment( (int) $row['comment_ID'], true ) ) {
                            $processed++;
                        }
                        if ( $out_of_time() ) { break; }
                    }
                }
                $remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved IN ('trash','post-trashed') LIMIT 1" );
                return self::result( $processed, $remaining === 0, $snap_path );

            case 'expired_transients':
                // loops. On a site with 5k expired transients this drops
                // ~10k queries to ~20. We pair each timeout row with its
                // value row so we never leave orphaned halves behind.
                while ( ! $out_of_time() ) {
                    $timeouts = (array) $wpdb->get_col( $wpdb->prepare(
                        "SELECT option_name FROM {$wpdb->options}
                         WHERE option_name LIKE %s AND option_value < %d
                         LIMIT %d",
                        '\_transient\_timeout\_%',
                        time(),
                        $chunk_size
                    ) );
                    $site_timeouts = (array) $wpdb->get_col( $wpdb->prepare(
                        "SELECT option_name FROM {$wpdb->options}
                         WHERE option_name LIKE %s AND option_value < %d
                         LIMIT %d",
                        '\_site\_transient\_timeout\_%',
                        time(),
                        $chunk_size
                    ) );
                    if ( empty( $timeouts ) && empty( $site_timeouts ) ) { break; }

                    // Map timeout-name → value-name pairs for the IN().
                    $names = array();
                    foreach ( $timeouts as $t ) {
                        $names[] = $t;
                        $names[] = '_transient_' . preg_replace( '/^_transient_timeout_/', '', $t );
                    }
                    foreach ( $site_timeouts as $t ) {
                        $names[] = $t;
                        $names[] = '_site_transient_' . preg_replace( '/^_site_transient_timeout_/', '', $t );
                    }
                    if ( empty( $names ) ) { break; }

                    // Snapshot values before delete so an over-eager prune
                    // can be reversed if it nuked something important.
                    if ( $snapshot_on ) {
                        $placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
                        $rows = $wpdb->get_results(
                            $wpdb->prepare(
                                "SELECT * FROM {$wpdb->options} WHERE option_name IN ($placeholders)",
                                $names
                            ),
                            ARRAY_A
                        );
                        if ( ! empty( $rows ) ) {
                            $snap_path = EasyOpt_DB_Snapshot::append(
                                'expired_transients', $session_id, $wpdb->options, $rows
                            ) ?: $snap_path;
                        }
                    }

                    // Single batched delete for everything in this chunk.
                    $placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
                    $sql = "DELETE FROM {$wpdb->options} WHERE option_name IN ($placeholders)";
                    $r   = (int) $wpdb->query( $wpdb->prepare( $sql, $names ) );
                    $processed += $r;
                    if ( $r === 0 ) { break; } // safety against pathological loop
                }
                // Cheap remaining check.
                $remaining = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->options}
                     WHERE (option_name LIKE %s OR option_name LIKE %s)
                       AND option_value < %d LIMIT 1",
                    '\_transient\_timeout\_%',
                    '\_site\_transient\_timeout\_%',
                    time()
                ) );
                if ( $processed > 0 ) {
                    self::flush_transient_caches( true ); // (2.5.4 / perf #30)
                }
                return self::result( $processed, $remaining === 0, $snap_path );

            case 'all_transients':
                // Heavy — wipes ALL transients. We chunk because on a site
                // with WC sessions there can be 100k+ rows; one big DELETE
                // can hold a table lock for minutes.
                while ( ! $out_of_time() ) {
                    $names = (array) $wpdb->get_col( $wpdb->prepare(
                        "SELECT option_name FROM {$wpdb->options}
                         WHERE option_name LIKE %s OR option_name LIKE %s
                         LIMIT %d",
                        '\_transient\_%',
                        '\_site\_transient\_%',
                        $chunk_size
                    ) );
                    if ( empty( $names ) ) { break; }
                    if ( $snapshot_on ) {
                        $placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
                        $rows = $wpdb->get_results( $wpdb->prepare(
                            "SELECT * FROM {$wpdb->options} WHERE option_name IN ($placeholders)",
                            $names
                        ), ARRAY_A );
                        if ( ! empty( $rows ) ) {
                            $snap_path = EasyOpt_DB_Snapshot::append(
                                'all_transients', $session_id, $wpdb->options, $rows
                            ) ?: $snap_path;
                        }
                    }
                    $placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
                    $r = (int) $wpdb->query( $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name IN ($placeholders)",
                        $names
                    ) );
                    $processed += $r;
                    if ( $r === 0 ) { break; }
                }
                $remaining = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->options}
                     WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%' LIMIT 1"
                );
                if ( $processed > 0 ) {
                    self::flush_transient_caches( true ); // (2.5.4 / perf #30)
                }
                return self::result( $processed, $remaining === 0, '' /* don't surface to UI: too generic */ );

            case 'optimize_tables':
                // default. Optimizing a 5GB postmeta on InnoDB rebuilds
                // the entire tablespace and can lock the site for minutes;
                // not a thing to do unattended at 3am.
                $size_cap_mb = (int) EasyOpt_Config::get( 'db_optimize_max_size_mb', 100 );
                $size_cap_b  = $size_cap_mb * 1024 * 1024;
                $tables_info = $wpdb->get_results( $wpdb->prepare(
                    "SELECT TABLE_NAME, (DATA_LENGTH + INDEX_LENGTH) AS size_bytes
                       FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = %s
                        AND TABLE_NAME LIKE %s
                        AND TABLE_TYPE = 'BASE TABLE'",
                    DB_NAME,
                    $wpdb->prefix . '%'
                ), ARRAY_A );
                foreach ( (array) $tables_info as $info ) {
                    if ( $out_of_time() ) {
                        // Pending OPTIMIZE tables resume next call.
                        return self::result( $processed, false, '' );
                    }
                    $table = (string) $info['TABLE_NAME'];
                    $size  = (int) $info['size_bytes'];
                    if ( $size_cap_b > 0 && $size > $size_cap_b ) {
                        continue; // user said skip
                    }
                    $wpdb->query( "OPTIMIZE TABLE `" . esc_sql( $table ) . "`" );
                    $processed++;
                }
                return self::result( $processed, true, '' );
        }
        return self::result( 0, true, '' );
    }

    /**
     * Uniform result envelope so AJAX and run_enabled don't have to care
     * which case ran.
     */
    private static function result( $processed, $done, $snap_path ) {
        return array(
            'processed'   => (int) $processed,
            'next_offset' => 0,
            'done'        => (bool) $done,
            'snapshot'    => (string) $snap_path,
        );
    }

    /**
     * Helper: snapshot rows by ID for tasks where we don't already have
     * the row data in hand (post_revisions calls wp_delete_post_revision
     * which expects an ID list, so we fetch the rows separately).
     */
    private static function snapshot_rows( $session_id, $task, $table, $ids ) {
        global $wpdb;
        if ( empty( $ids ) ) { return ''; }
        $ids = array_map( 'intval', $ids );
        $in  = implode( ',', $ids );
        $rows = $wpdb->get_results( "SELECT * FROM `" . esc_sql( $table ) . "` WHERE ID IN ($in)", ARRAY_A );
        if ( empty( $rows ) ) { return ''; }
        // For posts, also include postmeta so restore brings back ACF
        // fields and featured-image attachments.
        if ( $table === $wpdb->posts ) {
            $meta = $wpdb->get_results( "SELECT * FROM {$wpdb->postmeta} WHERE post_id IN ($in)", ARRAY_A );
            if ( ! empty( $meta ) ) {
                EasyOpt_DB_Snapshot::append( $task, $session_id, $wpdb->postmeta, $meta );
            }
        }
        return EasyOpt_DB_Snapshot::append( $task, $session_id, $table, $rows ) ?: '';
    }

    /* ─────────────────────────────────────────────
     *  Cron scheduling
     * ───────────────────────────────────────────── */

    public static function maybe_reschedule( $option ) {
        if ( ! in_array( $option, array( 'easyopt_db_schedule', 'easyopt_db_cron_enabled' ), true ) ) {
            return;
        }

        // Always start clean.
        $existing = wp_next_scheduled( self::CRON_HOOK );
        if ( $existing ) {
            wp_unschedule_event( $existing, self::CRON_HOOK );
        }
        wp_clear_scheduled_hook( self::CRON_HOOK );

        if ( ! (int) EasyOpt_Config::get( 'db_cron_enabled', 0 ) ) {
            return;
        }

        $sched = (string) EasyOpt_Config::get( 'db_schedule', 'weekly' );
        if ( ! in_array( $sched, array( 'daily', 'weekly', 'monthly' ), true ) ) {
            $sched = 'weekly';
        }
        // (2.5.4 / perf #54) Offset from the queue GC — see init().
        wp_schedule_event( time() + 3 * HOUR_IN_SECONDS, $sched, self::CRON_HOOK );
    }

    public static function stop_cron() {
        $existing = wp_next_scheduled( self::CRON_HOOK );
        if ( $existing ) {
            wp_unschedule_event( $existing, self::CRON_HOOK );
        }
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /* ─────────────────────────────────────────────
     *  AJAX
     * ───────────────────────────────────────────── */

    public static function ajax_run() {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easy-optimizer' ) ), 403 );
        }
        check_ajax_referer( 'easyopt_nonce', 'nonce' );

        $task        = isset( $_POST['task'] )       ? sanitize_key( wp_unslash( $_POST['task'] ) )       : '';
        $offset      = isset( $_POST['offset'] )     ? (int) $_POST['offset']                              : 0;
        $session_id  = isset( $_POST['session_id'] ) ? preg_replace( '/[^0-9]/', '', wp_unslash( $_POST['session_id'] ) ) : '';
        if ( '' === $session_id ) {
            $session_id = (string) time();
        }

        // the same session_id until `done` comes back true. The old
        // run-everything-in-one-request path lives on for the cron only
        // (run_scheduled). This is what protects busy sites from PHP timeouts.
        if ( '' === $task || 'all' === $task ) {
            $report = self::run_enabled( 20 );
            // run_enabled's own loop checks easyopt_db_pending. If it's set,
            // we're not done; the JS should keep calling.
            $pending = (array) get_option( 'easyopt_db_pending', array() );
            wp_send_json_success( array(
                'report'     => $report,
                'counts'     => self::counts(),
                'done'       => empty( $pending ),
                'session_id' => $session_id,
            ) );
        }

        if ( ! array_key_exists( $task, self::tasks() ) ) {
            wp_send_json_error( array( 'message' => __( 'Unknown task.', 'easy-optimizer' ) ) );
        }

        $r = self::run_task( $task, $offset, 20, $session_id );
        update_option( 'easyopt_db_last_run', time(), false );

        // (2.5.4 / perf #31) counts() runs 7 aggregate queries (5 COUNTs +
        // 2 LIKE scans over wp_options). Returning it with EVERY chunk of a
        // long cleanup multiplied that by the chunk count (a 100-chunk run
        // paid ~700 aggregates). Intermediate chunks now return counts:null
        // — the React panel already shows per-chunk progress from
        // `processed` and only needs fresh totals when the task finishes,
        // which the final (done:true) response still carries.
        wp_send_json_success( array(
            'task'        => $task,
            'processed'   => (int) $r['processed'],
            'done'        => (bool) $r['done'],
            'next_offset' => (int) $r['next_offset'],
            'snapshot'    => (string) $r['snapshot'],
            'session_id'  => $session_id,
            'counts'      => ( ! empty( $r['done'] ) ) ? self::counts() : null,
        ) );
    }

    public static function ajax_counts() {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easy-optimizer' ) ), 403 );
        }
        check_ajax_referer( 'easyopt_nonce', 'nonce' );

        wp_send_json_success( array( 'counts' => self::counts() ) );
    }

    /* ─────────────────────────────────────────────
     * ───────────────────────────────────────────── */

    /**
     * List all available snapshots. Sidecar metadata only — cheap.
     */
    public static function ajax_snapshots_list() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easy-optimizer' ) ), 403 );
        }
        check_ajax_referer( 'easyopt_nonce', 'nonce' );
        if ( ! class_exists( 'EasyOpt_DB_Snapshot' ) ) {
            wp_send_json_success( array( 'snapshots' => array() ) );
        }
        wp_send_json_success( array(
            'snapshots' => EasyOpt_DB_Snapshot::list_all(),
            'retention' => (int) EasyOpt_Config::get( 'db_snapshot_retention_days', EasyOpt_DB_Snapshot::DEFAULT_RETENTION ),
        ) );
    }

    /**
     * Restore one snapshot in a single chunk. Caller loops until done.
     */
    public static function ajax_snapshot_restore() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easy-optimizer' ) ), 403 );
        }
        check_ajax_referer( 'easyopt_nonce', 'nonce' );
        if ( ! class_exists( 'EasyOpt_DB_Snapshot' ) ) {
            wp_send_json_error( array( 'message' => __( 'Snapshot module unavailable.', 'easy-optimizer' ) ) );
        }
        $basename = isset( $_POST['basename'] ) ? sanitize_text_field( wp_unslash( $_POST['basename'] ) ) : '';
        $offset   = isset( $_POST['offset'] )   ? (int) $_POST['offset'] : 0;
        if ( '' === $basename ) {
            wp_send_json_error( array( 'message' => __( 'Missing snapshot id.', 'easy-optimizer' ) ) );
        }
        $r = EasyOpt_DB_Snapshot::restore_chunk( $basename, $offset, 500 );
        if ( empty( $r['ok'] ) ) {
            wp_send_json_error( array( 'message' => (string) ( $r['error'] ?? 'Restore failed.' ) ) );
        }
        wp_send_json_success( $r );
    }

    /**
     * Delete a snapshot (data + metadata).
     */
    public static function ajax_snapshot_delete() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easy-optimizer' ) ), 403 );
        }
        check_ajax_referer( 'easyopt_nonce', 'nonce' );
        if ( ! class_exists( 'EasyOpt_DB_Snapshot' ) ) {
            wp_send_json_error( array( 'message' => __( 'Snapshot module unavailable.', 'easy-optimizer' ) ) );
        }
        $basename = isset( $_POST['basename'] ) ? sanitize_text_field( wp_unslash( $_POST['basename'] ) ) : '';
        if ( '' === $basename ) {
            wp_send_json_error( array( 'message' => __( 'Missing snapshot id.', 'easy-optimizer' ) ) );
        }
        $ok = EasyOpt_DB_Snapshot::delete( $basename );
        if ( ! $ok ) {
            wp_send_json_error( array( 'message' => __( 'Could not delete snapshot.', 'easy-optimizer' ) ) );
        }
        wp_send_json_success( array( 'deleted' => true ) );
    }
}
