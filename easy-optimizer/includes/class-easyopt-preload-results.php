<?php
/**
 * Preload results ledger (2.5.0).
 *
 * A small per-URL inventory that records what actually HAPPENED to every
 * URL a preload run touches — independent of the task queue, whose rows
 * are deleted on success and therefore can only ever answer "is work
 * still pending", never "did this URL end up cached".
 *
 * WHY (C3 — ground-truth completion):
 *   Before this table, "done" on the dashboard meant "dequeued". A warm
 *   task that hit a redirect, an uncacheable page, or a render whose
 *   output buffer never wrote a file was deleted exactly like a success,
 *   so coverage losses were invisible. Completion is now confirmed by the
 *   CACHE WRITE ITSELF: EasyOpt_Cache::capture_buffer() and
 *   store_prefetched_html() call confirm() after a file lands on disk —
 *   the same architectural idea WP Rocket implements via its
 *   rocket_after_process_buffer hook.
 *
 * Row lifecycle (status column):
 *   pending      queued / warmed, awaiting a confirmed cache write
 *   cached       cache file(s) confirmed written (all required devices)
 *   redirected   warm returned a 3xx to a different page — never cached
 *                under this key by design (CB-1); note records the target
 *   uncacheable  page opted out (DONOTCACHEPAGE, password form, search,
 *                4xx, excluded URL/cookie/query) — terminal for this run
 *   failed       warm attempted, produced no cache file (network error,
 *                retries exhausted, silent no-write) — reverted to
 *                pending by the periodic revert pass (H5)
 *
 * devices_done is a bitmask: 1 = desktop confirmed, 2 = mobile confirmed.
 * A row flips to 'cached' when every REQUIRED device bit is set (mobile
 * only required while cache_separate_mobile is on).
 *
 * run_id groups rows per preload run (H4): the dashboard total is the
 * live row count for the current run, so companion warms enqueued after
 * the build no longer make progress run backwards.
 *
 * Cost profile: one UPDATE-by-unique-key per cache write while preload is
 * enabled (writes are MISSes, so this is rare in steady state), a bounded
 * reconcile pass on the existing per-minute watchdog, and a bounded
 * revert/GC pass on the existing daily GC tick. No new cron schedules.
 *
 * @package EasyOptimizer
 * @since   2.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Preload_Results {

    /** Bump when columns or indexes change. */
    const SCHEMA_VERSION = 1;

    const OPTION_SCHEMA = 'easyopt_preload_results_schema';
    const OPTION_RUN_ID = 'easyopt_preload_run_id';

    /** Device bits. */
    const DEV_DESKTOP = 1;
    const DEV_MOBILE  = 2;

    /** A 'pending' row untouched for this long with no live queue task is
     *  reconciled against the filesystem (confirm or fail). */
    const RECONCILE_GRACE_SECONDS = 600; // 10 min

    /** Rows reconciled per watchdog tick — keeps the pass O(small). */
    const RECONCILE_CAP = 20;

    /** (H5) failed → pending revert: age gate and per-pass cap. Mirrors
     *  WP Rocket's 12-hour revert loop so no URL is stranded forever. */
    const REVERT_AFTER_SECONDS = 43200; // 12 h
    const REVERT_CAP           = 200;

    /** GC: resolved rows from PREVIOUS runs older than this are purged. */
    const GC_RESOLVED_TTL = 2592000; // 30 days

    /** Hard row ceiling — oldest prior-run rows evicted above this. */
    const MAX_ROWS = 60000;

    /** @var bool|null Memoized "table exists" (schema option present). */
    private static $table_known = null;

    /* ───────────────────────────────────────────────
     *  Lifecycle
     * ─────────────────────────────────────────────── */

    public static function init() {
        // After EasyOpt_Queue::maybe_create_table (priority 5) for tidy
        // upgrade ordering; both are idempotent so order is not load-bearing.
        add_action( 'plugins_loaded', array( __CLASS__, 'maybe_create_table' ), 6 );
        add_action( 'easyopt_uninstall', array( __CLASS__, 'drop_table' ) );
    }

    public static function maybe_create_table() {
        // (2.5.4 / perf #26) Shared autoloaded fast path (see EasyOpt_Config
        // schema registry); legacy option remains authoritative below.
        if ( class_exists( 'EasyOpt_Config' ) && method_exists( 'EasyOpt_Config', 'schema_version' )
            && EasyOpt_Config::schema_version( 'preload_results' ) === (string) self::SCHEMA_VERSION ) {
            self::$table_known = true;
            return;
        }
        $current = (int) get_option( self::OPTION_SCHEMA, 0 );
        if ( $current === self::SCHEMA_VERSION ) {
            self::$table_known = true;
            if ( class_exists( 'EasyOpt_Config' ) && method_exists( 'EasyOpt_Config', 'set_schema_version' ) ) {
                EasyOpt_Config::set_schema_version( 'preload_results', (string) self::SCHEMA_VERSION );
            }
            return;
        }

        global $wpdb;
        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            url_hash CHAR(40) NOT NULL,
            url TEXT NOT NULL,
            run_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            devices_done TINYINT UNSIGNED NOT NULL DEFAULT 0,
            http_code SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            note VARCHAR(500) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_url_hash (url_hash),
            KEY idx_run_status (run_id, status, updated_at),
            KEY idx_gc (run_id, updated_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( self::OPTION_SCHEMA, self::SCHEMA_VERSION, false );
        if ( class_exists( 'EasyOpt_Config' ) && method_exists( 'EasyOpt_Config', 'set_schema_version' ) ) {
            EasyOpt_Config::set_schema_version( 'preload_results', (string) self::SCHEMA_VERSION ); // (2.5.4 / perf #26)
        }
        self::$table_known = true;
    }

    public static function drop_table() {
        global $wpdb;
        $wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        delete_option( self::OPTION_SCHEMA );
        delete_option( self::OPTION_RUN_ID );
        self::$table_known = false;
    }

    private static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'easyopt_preload_results';
    }

    /** Cheap availability gate for the hot confirm() path: a single
     *  autoload-free option read, memoized per process. */
    private static function available() {
        if ( null === self::$table_known ) {
            self::$table_known = ( (int) get_option( self::OPTION_SCHEMA, 0 ) > 0 );
        }
        return self::$table_known;
    }

    /* ───────────────────────────────────────────────
     *  URL hashing (shared cache-key identity)
     * ─────────────────────────────────────────────── */

    /**
     * Stable identity for a URL that MATCHES the page-cache keying used by
     * EasyOpt_Cache::compute_path_base(): lowercase host (no port), decoded
     * path, trailing slash and scheme and query ignored. The collected URL
     * (preloader side) and the rendered request URL (capture_buffer side)
     * therefore hash to the SAME row even when they differ in scheme or
     * trailing slash. www./non-www remain DISTINCT — they are distinct
     * cache keys too.
     *
     * @param string $url Absolute URL.
     * @return string sha1 hash, or '' when the URL cannot be parsed.
     */
    public static function hash_url( $url ) {
        $p = wp_parse_url( (string) $url );
        if ( ! is_array( $p ) || empty( $p['host'] ) ) {
            return '';
        }
        $host = strtolower( (string) $p['host'] );
        $path = isset( $p['path'] ) ? (string) $p['path'] : '/';
        $path = rawurldecode( $path );
        $path = untrailingslashit( $path );
        if ( '' === $path ) {
            $path = '/';
        }
        return sha1( $host . '|' . $path );
    }

    /** How many device bits must be set for a row to count as cached. */
    private static function required_bits() {
        $separate = class_exists( 'EasyOpt_Config' )
            ? (int) EasyOpt_Config::get( 'cache_separate_mobile', 1 )
            : 1;
        return $separate ? ( self::DEV_DESKTOP | self::DEV_MOBILE ) : self::DEV_DESKTOP;
    }

    private static function preload_enabled() {
        return class_exists( 'EasyOpt_Config' )
            && (int) EasyOpt_Config::get( 'cache_preload', 0 );
    }

    /* ───────────────────────────────────────────────
     *  Run management
     * ─────────────────────────────────────────────── */

    public static function current_run() {
        return (int) get_option( self::OPTION_RUN_ID, 0 );
    }

    /**
     * Start a new run: bump the run id and (re)seed a pending row for every
     * URL. Existing rows (any status, any run) are adopted into the new run
     * and reset to pending — a fresh run re-verifies everything.
     *
     * Called from inside the build_queue task; the caller refreshes its
     * queue lock around this (chunks are small, ~200 rows/INSERT).
     *
     * @param string[] $urls Collected URLs.
     * @return int New run id.
     */
    public static function begin_run( $urls ) {
        global $wpdb;
        if ( ! self::available() ) {
            self::maybe_create_table();
        }
        $run = self::current_run() + 1;
        update_option( self::OPTION_RUN_ID, $run, false );

        $table = self::table_name();
        $chunk = array();
        foreach ( (array) $urls as $url ) {
            $hash = self::hash_url( $url );
            if ( '' === $hash ) {
                continue;
            }
            $chunk[] = $wpdb->prepare( '(%s, %s, %d, UTC_TIMESTAMP(), UTC_TIMESTAMP())', $hash, $url, $run );
            if ( count( $chunk ) >= 200 ) {
                self::flush_seed_chunk( $chunk );
                $chunk = array();
            }
        }
        if ( ! empty( $chunk ) ) {
            self::flush_seed_chunk( $chunk );
        }
        return $run;
    }

    private static function flush_seed_chunk( $rows ) {
        global $wpdb;
        $table  = self::table_name();
        $values = implode( ',', $rows );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- rows are individually prepared above.
        $wpdb->query(
            "INSERT INTO $table (url_hash, url, run_id, created_at, updated_at)
             VALUES $values
             ON DUPLICATE KEY UPDATE
               run_id       = VALUES(run_id),
               status       = 'pending',
               devices_done = 0,
               http_code    = 0,
               note         = '',
               updated_at   = UTC_TIMESTAMP()"
        );
    }

    /**
     * Adopt a single URL into the current run WITHOUT resetting its state
     * (used by enqueue_companion for URLs cached on-visit mid-run — H4:
     * the row joins the live total instead of breaking the denominator).
     */
    public static function adopt( $url ) {
        if ( ! self::available() || ! self::preload_enabled() ) {
            return;
        }
        $hash = self::hash_url( $url );
        if ( '' === $hash ) {
            return;
        }
        global $wpdb;
        $table = self::table_name();
        // (2.5.0 / B5) A companion enqueue happens on a cache MISS — proof
        // the file is NOT on disk. A 'cached' row carried over from a
        // PREVIOUS run is therefore stale and must reset to pending so the
        // current run's cached count isn't overstated. Mid-run adopts
        // (run_id already current — the H4 case) keep their state exactly
        // as before. Assignment order matters: devices_done and status are
        // computed from the OLD run_id/status, so run_id is assigned LAST.
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO $table (url_hash, url, run_id, status, created_at, updated_at)
             VALUES (%s, %s, %d, 'pending', UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
               devices_done = IF( run_id <> VALUES(run_id) AND status = 'cached', 0, devices_done ),
               status       = IF( run_id <> VALUES(run_id) AND status = 'cached', 'pending', status ),
               run_id       = VALUES(run_id),
               updated_at   = UTC_TIMESTAMP()",
            $hash,
            (string) $url,
            self::current_run()
        ) );
    }

    /* ───────────────────────────────────────────────
     *  State transitions
     * ─────────────────────────────────────────────── */

    /**
     * (C3) A cache file for this URL/device was ACTUALLY written. Sets the
     * device bit; flips the row to 'cached' once all required bits are set.
     * A confirmed write is ground truth — it overrides failed/redirected/
     * uncacheable classifications. No-op for untracked URLs.
     *
     * Called from EasyOpt_Cache::capture_buffer() (live visitor + preload
     * loopback renders) and EasyOpt_Cache::store_prefetched_html() (the
     * preloader's capture path).
     *
     * @param string $url       Absolute URL that was cached.
     * @param bool   $is_mobile Mobile variant?
     */
    public static function confirm( $url, $is_mobile ) {
        if ( ! self::available() || ! self::preload_enabled() ) {
            return;
        }
        $hash = self::hash_url( $url );
        if ( '' === $hash ) {
            return;
        }
        global $wpdb;
        $table = self::table_name();
        $bit   = $is_mobile ? self::DEV_MOBILE : self::DEV_DESKTOP;
        $req   = self::required_bits();
        $wpdb->query( $wpdb->prepare(
            "UPDATE $table
             SET devices_done = devices_done | %d,
                 status       = IF( ((devices_done | %d) & %d) = %d, 'cached', 'pending' ),
                 http_code    = 200,
                 updated_at   = UTC_TIMESTAMP()
             WHERE url_hash = %s",
            $bit,
            $bit,
            $req,
            $req,
            $hash
        ) );
    }

    /**
     * Bulk-mark URLs cached (used by the build task for URLs whose cache
     * files already exist — they were previously invisible in progress).
     *
     * @param string[] $urls
     */
    public static function mark_prewarmed( $urls ) {
        if ( ! self::available() || empty( $urls ) ) {
            return;
        }
        global $wpdb;
        $table = self::table_name();
        $req   = self::required_bits();
        $run   = self::current_run();

        $hashes = array();
        foreach ( (array) $urls as $url ) {
            $h = self::hash_url( $url );
            if ( '' !== $h ) {
                $hashes[] = $h;
            }
        }
        foreach ( array_chunk( $hashes, 200 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
            $params       = array_merge( array( $req, $run ), $chunk );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( $wpdb->prepare(
                "UPDATE $table
                 SET status = 'cached', devices_done = %d, http_code = 200,
                     note = 'already cached', updated_at = UTC_TIMESTAMP()
                 WHERE run_id = %d AND url_hash IN ($placeholders)",
                $params
            ) );
        }
    }

    /**
     * (C1 visibility) The warm hit a redirect to a DIFFERENT page. Terminal
     * for this run; the note records the target so the admin can fix the
     * source (menu link, permalink, redirect rule).
     */
    public static function mark_redirected( $url, $target, $code ) {
        self::mark_terminal( $url, 'redirected', (int) $code, '→ ' . (string) $target, array( 'cached' ) );
    }

    /** The page itself refuses caching (DONOTCACHEPAGE, password prompt,
     *  search/feed/preview, 4xx, exclusion rules). Terminal for this run. */
    public static function mark_uncacheable( $url, $reason, $code = 200 ) {
        self::mark_terminal( $url, 'uncacheable', (int) $code, (string) $reason, array( 'cached' ) );
    }

    /** The warm was attempted but produced no cache file. Retryable: the
     *  periodic revert pass (H5) flips these back to pending. */
    public static function mark_failed( $url, $reason, $code = 0 ) {
        self::mark_terminal( $url, 'failed', (int) $code, (string) $reason, array( 'cached', 'redirected', 'uncacheable' ) );
    }

    private static function mark_terminal( $url, $status, $code, $note, $protected ) {
        if ( ! self::available() || ! self::preload_enabled() ) {
            return;
        }
        $hash = self::hash_url( $url );
        if ( '' === $hash ) {
            return;
        }
        global $wpdb;
        $table        = self::table_name();
        $placeholders = implode( ',', array_fill( 0, count( $protected ), '%s' ) );
        $params       = array_merge(
            array( $status, $code, substr( $note, 0, 500 ), $hash ),
            $protected
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( $wpdb->prepare(
            "UPDATE $table
             SET status = %s, http_code = %d, note = %s, updated_at = UTC_TIMESTAMP()
             WHERE url_hash = %s AND status NOT IN ($placeholders)",
            $params
        ) );
    }

    /* ───────────────────────────────────────────────
     *  Reads
     * ─────────────────────────────────────────────── */

    /**
     * Per-status counts for the current run. Returns zeros when no run has
     * been recorded (fresh install / pre-first-preload).
     *
     * @return array{run:int,total:int,pending:int,cached:int,redirected:int,uncacheable:int,failed:int}
     */
    public static function counts() {
        $out = array(
            'run'         => self::current_run(),
            'total'       => 0,
            'pending'     => 0,
            'cached'      => 0,
            'redirected'  => 0,
            'uncacheable' => 0,
            'failed'      => 0,
        );
        if ( ! self::available() || $out['run'] < 1 ) {
            return $out;
        }
        global $wpdb;
        $table = self::table_name();
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT status, COUNT(*) AS c FROM $table WHERE run_id = %d GROUP BY status",
            $out['run']
        ), ARRAY_A );
        foreach ( (array) $rows as $row ) {
            $s = (string) $row['status'];
            $c = (int) $row['c'];
            if ( isset( $out[ $s ] ) ) {
                $out[ $s ] = $c;
            }
            $out['total'] += $c;
        }
        return $out;
    }

    /**
     * (C3 reconcile) Verify stale 'pending' rows against reality. Runs on
     * the existing per-minute queue watchdog, bounded to RECONCILE_CAP rows.
     *
     * For each current-run pending row untouched for RECONCILE_GRACE_SECONDS:
     *   - a live queue task still exists → bump updated_at ("seen, queued")
     *     so the scan window advances past it;
     *   - cache file(s) exist on disk → confirm them (covers writes our
     *     hooks missed, e.g. plugin was upgraded mid-run);
     *   - neither → 'failed' with a diagnostic note. The fire-and-forget
     *     path is thereby fully observable: a dispatched render that never
     *     produced a file surfaces here instead of vanishing.
     */
    public static function reconcile( $limit = self::RECONCILE_CAP ) {
        if ( ! self::available() || ! self::preload_enabled() ) {
            return 0;
        }
        $run = self::current_run();
        if ( $run < 1 ) {
            return 0;
        }
        global $wpdb;
        $table = self::table_name();
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, url, url_hash, devices_done FROM $table
             WHERE run_id = %d AND status = 'pending'
               AND updated_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d SECOND )
             ORDER BY updated_at ASC
             LIMIT %d",
            $run,
            self::RECONCILE_GRACE_SECONDS,
            max( 1, (int) $limit )
        ), ARRAY_A );
        if ( empty( $rows ) ) {
            return 0;
        }

        // One batched query: which of these still have live queue work?
        $live = array();
        if ( class_exists( 'EasyOpt_Queue' ) && method_exists( 'EasyOpt_Queue', 'live_hashes' ) ) {
            $live = EasyOpt_Queue::live_hashes(
                'preload',
                'easyopt_preload_warm_url',
                wp_list_pluck( $rows, 'url_hash' )
            );
        }
        $live = array_flip( (array) $live );

        $separate = ( self::required_bits() & self::DEV_MOBILE ) === self::DEV_MOBILE;
        $touched  = array();
        $handled  = 0;

        foreach ( $rows as $row ) {
            if ( isset( $live[ $row['url_hash'] ] ) ) {
                $touched[] = (int) $row['id']; // still queued — advance the window.
                continue;
            }
            $paths = ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'cache_paths_for_url' ) )
                ? EasyOpt_Cache::cache_paths_for_url( (string) $row['url'] )
                : array();
            $has_desktop = self::variant_on_disk( isset( $paths['desktop'] ) ? (string) $paths['desktop'] : '' );
            $has_mobile  = self::variant_on_disk( isset( $paths['mobile'] ) ? (string) $paths['mobile'] : '' );

            if ( $has_desktop && ( $has_mobile || ! $separate ) ) {
                self::confirm( (string) $row['url'], false );
                if ( $separate ) {
                    self::confirm( (string) $row['url'], true );
                }
                $handled++;
                continue;
            }

            $missing = array();
            if ( ! $has_desktop ) {
                $missing[] = 'desktop';
            }
            if ( $separate && ! $has_mobile ) {
                $missing[] = 'mobile';
            }
            self::mark_failed(
                (string) $row['url'],
                'warm produced no cache file (' . implode( '+', $missing ) . ')',
                0
            );
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::warn( 'preload', sprintf(
                    'No cache file after warm: %s (missing %s) — will retry via revert pass.',
                    (string) $row['url'],
                    implode( '+', $missing )
                ) );
            }
            $handled++;
        }

        if ( ! empty( $touched ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $touched ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( $wpdb->prepare(
                "UPDATE $table SET updated_at = UTC_TIMESTAMP() WHERE id IN ($placeholders)",
                $touched
            ) );
        }
        return $handled;
    }

    private static function variant_on_disk( $gz_path ) {
        if ( '' === $gz_path ) {
            return false;
        }
        if ( file_exists( $gz_path ) ) {
            return true;
        }
        $plain = preg_replace( '/_gzip$/', '', $gz_path );
        return is_string( $plain ) && '' !== $plain && file_exists( $plain );
    }

    /**
     * (H5) Revert aged 'failed' rows to pending and re-enqueue their warm
     * tasks — WP Rocket's 12-hour self-heal, capped per pass. Runs on the
     * existing daily GC tick; safe alongside a live run (add_task dedup).
     *
     * @return int Rows reverted.
     */
    public static function revert_stale_failed() {
        if ( ! self::available() || ! self::preload_enabled() ) {
            return 0;
        }
        $run = self::current_run();
        if ( $run < 1 ) {
            return 0;
        }
        global $wpdb;
        $table = self::table_name();
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, url FROM $table
             WHERE run_id = %d AND status = 'failed'
               AND updated_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d SECOND )
             ORDER BY updated_at ASC
             LIMIT %d",
            $run,
            (int) apply_filters( 'easyopt_preload_revert_after', self::REVERT_AFTER_SECONDS ),
            (int) apply_filters( 'easyopt_preload_revert_cap', self::REVERT_CAP )
        ), ARRAY_A );
        if ( empty( $rows ) ) {
            return 0;
        }

        $ids = array();
        if ( class_exists( 'EasyOpt_Cache_Preload' ) && method_exists( 'EasyOpt_Cache_Preload', 'requeue_url' ) ) {
            foreach ( $rows as $row ) {
                EasyOpt_Cache_Preload::requeue_url( (string) $row['url'] );
                $ids[] = (int) $row['id'];
            }
        }
        if ( ! empty( $ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( $wpdb->prepare(
                "UPDATE $table
                 SET status = 'pending', note = 'reverted for retry', updated_at = UTC_TIMESTAMP()
                 WHERE id IN ($placeholders)",
                $ids
            ) );
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::info( 'preload', sprintf(
                    'Revert pass re-queued %d previously failed URL(s) for warming.',
                    count( $ids )
                ) );
            }
            // Kick the runner ONCE for the whole batch (requeue_url is
            // deliberately silent per-URL to avoid a dispatch storm).
            if ( class_exists( 'EasyOpt_Cache_Preload' )
                 && method_exists( 'EasyOpt_Cache_Preload', 'kick_warm_queue' ) ) {
                EasyOpt_Cache_Preload::kick_warm_queue();
            }
        }
        return count( $ids );
    }

    /**
     * Daily GC: purge resolved rows from PREVIOUS runs older than 30 days
     * and enforce the hard row ceiling (oldest prior-run rows first).
     * Current-run rows are never touched.
     */
    public static function gc() {
        if ( ! self::available() ) {
            return;
        }
        global $wpdb;
        $table = self::table_name();
        $run   = self::current_run();

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table
             WHERE run_id < %d
               AND updated_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d SECOND )
             LIMIT 5000",
            $run,
            self::GC_RESOLVED_TTL
        ) );

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( $total > self::MAX_ROWS ) {
            $over = $total - self::MAX_ROWS;
            while ( $over > 0 ) {
                $chunk = min( $over, 5000 );
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $affected = (int) $wpdb->query( $wpdb->prepare(
                    "DELETE FROM $table
                     WHERE run_id < %d
                     ORDER BY updated_at ASC
                     LIMIT %d",
                    $run,
                    $chunk
                ) );
                $over -= $affected;
                if ( $affected < 1 ) {
                    break;
                }
            }
        }
    }
}
