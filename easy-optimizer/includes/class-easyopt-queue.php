<?php
/**
 * Generic background-job queue for Easy Optimizer.
 *
 * for Easy Optimizer's preload (and, in time, image-optimization and
 * database-maintenance jobs). The design is a direct adaptation of
 * a purpose-built queue with our naming + style conventions.
 *
 *   Why we moved off Action Scheduler:
 *
 *     1. AS's async runner relies on a `wp_remote_post` loopback to
 *        admin-ajax.php?action=as_async_request_queue_runner. On
 *        Cloudways/LiteSpeed and any host with strict loopback rules,
 *        that POST gets blocked or times out — the action sits "pending"
 *        indefinitely. When that happens, AS falls back to WP-Cron,
 *        which is itself visitor-triggered and useless on low-traffic
 *        sites (or fully disabled when DISABLE_WP_CRON is true).
 *
 *     2. AS has no concept of "the work I asked you to do never ran".
 *        A dropped async dispatch + a missed cron tick = the job is
 *        gone, with no UI indication. We had stuck-at-0/1 reports
 *        traced back to exactly this.
 *
 *     3. The 700 KB AS bundle is a heavy dependency for the small slice
 *        of its features we actually use (just async enqueue + retry).
 *
 *   What this queue does instead:
 *
 *     - Tasks live in `wp_easyopt_queue`. The DB is the source of truth
 *       for both queue state and progress reporting; no AS state to
 *       reconcile.
 *
 *     - Dispatch is a non-blocking `wp_remote_post` loopback to our own
 *       REST endpoint (`/wp-json/easyopt/v1/queue/run`). The endpoint
 *       runs for up to 20 s, claiming tasks one at a time via an atomic
 *       `UPDATE ... LIMIT 1` and processing them.
 *
 *     - A per-minute watchdog cron looks for groups with pending tasks
 *       but no live runner and re-fires the loopback. This is the
 *       safety net AS doesn't provide.
 *
 *     - Tasks are locked via `lock_token + locked_at`; stuck rows
 *       (processing for > TASK_LOCK_TIMEOUT seconds) are reclaimable.
 *
 *     - Retries are per-task with exponential backoff (30s → 5 min,
 *       up to N attempts).
 *
 *     - Deduplication is atomic via `task_hash` + `ON DUPLICATE KEY
 *       UPDATE id = LAST_INSERT_ID(id)`.
 *
 *   Multi-group, multi-action: instantiate one Queue per (group,
 *   callback_action) pair. The static methods (watchdog, REST runner)
 *   service all groups from one table.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sentinel exception: thrown by task callbacks that want to be re-queued
 * after a soft pause (e.g. high CPU) without burning a retry attempt.
 *
 * a few high-CPU windows in a row would mark a URL permanently 'failed'.
 * Callbacks that throw THIS class get:
 *   1. attempts rolled back (un-counting claim_next_task's bump)
 *   2. locked_at pushed (defer seconds, default 60) so it re-enters
 *      the pending pool after the pause window
 *   3. status stays 'pending' so the watchdog reclaims it
 *
 * Pass desired defer-seconds as the exception's `code` (Exception::getCode).
 */
if ( ! class_exists( 'EasyOpt_Queue_Defer_Exception' ) ) {
    class EasyOpt_Queue_Defer_Exception extends \Exception {}
}

class EasyOpt_Queue {

    /* ───────────────────────────────────────────────
     *  Constants
     * ─────────────────────────────────────────────── */

    const REST_NAMESPACE = 'easyopt/v1';
    const REST_ROUTE     = '/queue/run';

    /** Seconds before a "processing" task is considered stuck and
     *  reclaimable. Should be larger than the longest realistic task. */
    const TASK_LOCK_TIMEOUT = 120;

    /** How long one runner loop runs before exiting voluntarily. Keeps
     *  PHP under any host max_execution_time and lets the loopback
     *  pattern self-renew. */
    const RUNNER_WINDOW_SECONDS = 20;

    /** Per-task retry attempts before status flips to 'failed'. */
    const DEFAULT_MAX_RETRIES = 3;

    /** Concurrent runners allowed per (group, callback). Default 1 for
     *  preload — sequential is safer for tiny VPS hosts. */
    const DEFAULT_CONCURRENCY = 1;

    /** Hard ceiling regardless of caller request. */
    const MAX_CONCURRENCY = 8;

    /** Exponential retry backoff. First retry waits 30 s, second 60,
     *  third 120, capped at 5 min. */
    const RETRY_BACKOFF_BASE_SECONDS = 30;
    const RETRY_BACKOFF_MAX_SECONDS  = 300;

    const WATCHDOG_HOOK     = 'easyopt_queue_watchdog';
    const WATCHDOG_SCHEDULE = 'easyopt_one_minute';
    /** Max groups the watchdog re-fires per minute. Cheap query, but
     *  this limits damage if something weird happens. */
    const WATCHDOG_BATCH = 10;

    /**
     * (2.4.5) Loopback-blocked fallback budget. On hosts that block the
     * wp_remote_post loopback to our REST runner (strict managed hosting,
     * basic-auth staging, self-signed local TLS, some WAF/Cloudflare setups)
     * no runner ever starts and warming would stall forever. As a safety net
     * the watchdog ALSO drains a tiny, time-boxed batch IN-PROCESS. It is
     * deliberately small so it never loads the origin: the atomic claim makes
     * it safe to run alongside a real runner, and on a healthy host the
     * watchdog rarely finds a stuck group at all. It runs in the cron request
     * (not a visitor request), so no page view is ever blocked by it.
     */
    const INLINE_DRAIN_MAX_TASKS      = 5;
    const INLINE_DRAIN_BUDGET_SECONDS = 12;

    /* ───────────────────────────────────────────────
     *  Garbage collection (table-bloat control)
     *
     *  Successful tasks are deleted inline (see process_task). What was
     *  NOT cleaned before v2: permanently-`failed` rows (kept forever),
     *  orphaned `processing` rows from crashed runners, and the InnoDB
     *  tablespace itself (never shrinks on DELETE). On a very large site
     *  a one-time full-site preload + accumulated failures could leave a
     *  100 MB+ table that never reclaimed. The daily GC below fixes all
     *  three. It only ever removes TERMINAL/orphaned rows — pending and
     *  live processing work is never touched.
     * ─────────────────────────────────────────────── */

    /** Daily GC cron. `daily` is a WordPress built-in schedule. */
    const GC_HOOK     = 'easyopt_queue_gc';
    const GC_SCHEDULE = 'daily';

    /** Purge `failed` rows older than this (24 h). A failed row never
     *  self-cleans: it only flips back to `pending` if the *same* task is
     *  re-added, but a deleted / redirected / auth-walled URL is never
     *  re-added — so without GC it lives forever. */
    const GC_FAILED_TTL = 86400; // 24 h (= DAY_IN_SECONDS)

    /** Purge orphaned `processing` rows whose runner died. Deliberately
     *  far larger than the watchdog's TASK_LOCK_TIMEOUT (120 s) reclaim
     *  window, so GC can only ever remove rows the watchdog could not
     *  recover — never a row about to be legitimately retried. */
    const GC_STUCK_TTL = 3600; // 1 h (= HOUR_IN_SECONDS), >> watchdog's 120 s reclaim

    /** Rows deleted per DELETE pass — keeps locks short on huge tables. */
    const GC_BATCH = 5000;

    /** Hard ceiling on retained `failed` rows. Bounds growth between TTL
     *  passes and on sites that churn failures fast. Eviction is oldest-
     *  first and FAILED-only, so live work can never be truncated. */
    const GC_FAILED_MAX_ROWS = 10000;

    /** Only OPTIMIZE TABLE (reclaim disk) when a pass frees at least this
     *  many rows. Keeps small / healthy tables from ever triggering the
     *  rebuild. (A one-time post-upgrade flag also forces it once.) */
    const GC_OPTIMIZE_MIN_ROWS = 1000;

    /** Schema bump: increment when columns OR indexes change. Stored in
     *  the `easyopt_queue_schema_version` option so dbDelta / migration
     *  only re-runs on upgrade.
     *  v2: added KEY idx_gc (status, updated_at) + daily GC + one-time
     *      OPTIMIZE TABLE to reclaim historical bloat.
     *  v3 (2.5.0): added url_hash CHAR(40) + KEY idx_url_hash so
     *      (a) count_distinct_urls() can count REAL distinct URLs instead
     *      of the ceil(rows/2) heuristic that mis-reports whenever device
     *      rows are asymmetric (companion enqueues, per-device skips), and
     *      (b) the preload results ledger can check "does this URL still
     *      have live queue work" in one indexed query. */
    const SCHEMA_VERSION = 3;

    /* ───────────────────────────────────────────────
     *  Per-(group,action) configuration registry
     *
     *  Instances register their max_retries/concurrency in this static
     *  map so the static REST runner can look them up by string keys
     *  (the runner doesn't hold an instance — it's called from REST).
     * ─────────────────────────────────────────────── */
    private static $queue_max_retries = array();
    private static $queue_concurrency = array();
    /** Phase 3/4 — optional batch handlers, keyed by "group|callback".
     *  A group MAY register a callable that warms several claimed tasks at
     *  once (the preload capture path uses concurrent HTTP). Empty by default,
     *  so the runner keeps its proven one-task-at-a-time loop unless a handler
     *  is registered AND an effective batch size ≥ 2 is requested. */
    private static $queue_batch_callbacks = array();

    /** Group identifier (e.g. 'preload-urls'). */
    private $group_name;
    /** Hook name fired with the task payload (e.g. 'easyopt_preload_warm_url'). */
    private $callback_action;

    /* ───────────────────────────────────────────────
     *  Lifecycle / WP integration
     * ─────────────────────────────────────────────── */

    public static function init() {
        // dbDelta is idempotent; run on every init() in case the table
        // was deleted out from under us by a database tool. Cheap when
        // the schema version matches.
        add_action( 'plugins_loaded', array( __CLASS__, 'maybe_create_table' ), 5 );

        // Watchdog: schedule on init, run on its hook.
        add_filter( 'cron_schedules',          array( __CLASS__, 'register_schedule' ) );
        add_action( 'init',                    array( __CLASS__, 'setup_watchdog'    ) );
        add_action( self::WATCHDOG_HOOK,       array( __CLASS__, 'run_watchdog'      ) );

        // Garbage collection: daily cron that purges terminal / orphaned
        // rows and reclaims tablespace. Separate from the watchdog (which
        // runs every minute) — GC is cheap and only needs to run daily.
        add_action( 'init',                    array( __CLASS__, 'setup_gc'          ) );
        add_action( self::GC_HOOK,             array( __CLASS__, 'run_gc'            ) );

        // Inline watchdog removed — it triggered 508 Loop
        // Detected on LiteSpeed/Cloudways by issuing wp_remote_post
        // loopback from within REST requests. WP-Cron watchdog (every 60s)
        // is sufficient for most hosts. Sites with
        // DISABLE_WP_CRON should set up a system cron hitting wp-cron.php.

        // REST endpoint that runners hit via loopback.
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_route' ) );

        // Plugin lifecycle: create table on activation, clear watchdog
        // on deactivation. The main plugin file's register_*_hook calls
        // happen too late for us to register here (this class is
        // included after activation runs), so the main file calls
        // `EasyOpt_Queue::maybe_create_table()` from its own activation
        // hook directly.
        add_action( 'easyopt_uninstall', array( __CLASS__, 'drop_table' ) );
    }

    public function __construct(
        $group_name,
        $callback_action,
        $max_retries = self::DEFAULT_MAX_RETRIES,
        $concurrency = self::DEFAULT_CONCURRENCY
    ) {
        $this->group_name      = $group_name;
        $this->callback_action = $callback_action;
        self::set_max_retries( $group_name, $callback_action, $max_retries );
        self::set_concurrency( $group_name, $callback_action, $concurrency );
    }

    /* ───────────────────────────────────────────────
     *  Public API (instance methods)
     * ─────────────────────────────────────────────── */

    /**
     * Insert a task into the queue. Idempotent: if a task with the
     * same canonical (group, callback, task_data) hash already exists,
     * the existing row is preserved (priority is bumped down if the
     * new priority is lower; failed rows are reset to pending).
     *
     * @param array|string|int $task_data Task payload. Recommended to be
     *                                    an associative array.
     * @param int              $priority  Lower = runs sooner. Default 20.
     * @return int Inserted/existing row ID, or 0 on failure.
     */
    public function add_task( $task_data, $priority = 20 ) {
        global $wpdb;
        $table     = self::table_name();
        $task_data = $this->normalize_task_data( $task_data );
        $task_json = wp_json_encode( $task_data );
        $task_hash = $this->build_task_hash( $task_data );
        $priority  = max( 0, (int) $priority );

        // (2.5.0 / H1) URL identity for URL-bearing tasks. Lets
        // count_distinct_urls() count REAL distinct URLs and lets the
        // preload results ledger check for live work per URL — both via
        // the idx_url_hash index. Empty for non-URL tasks (build_queue).
        $url_hash = '';
        if ( is_array( $task_data ) && ! empty( $task_data['url'] )
             && class_exists( 'EasyOpt_Preload_Results' ) ) {
            $url_hash = (string) EasyOpt_Preload_Results::hash_url( (string) $task_data['url'] );
        }

        // ON DUPLICATE KEY UPDATE handles dedup atomically: if a row
        // with this hash exists, we use LAST_INSERT_ID(id) trick to
        // surface the existing ID. priority and status get massaged
        // so a re-add can reactivate a previously-failed task.
        $sql = $wpdb->prepare(
            "INSERT INTO $table
              (group_name, callback_action, task_data, task_hash, url_hash, priority, status, created_at, updated_at)
            VALUES
              (%s, %s, %s, %s, %s, %d, 'pending', UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
              id         = LAST_INSERT_ID(id),
              url_hash   = VALUES(url_hash),
              priority   = LEAST(priority, VALUES(priority)),
              status     = IF(status = 'failed', 'pending', status),
              updated_at = UTC_TIMESTAMP()",
            $this->group_name,
            $this->callback_action,
            $task_json,
            $task_hash,
            $url_hash,
            $priority
        );
        $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        // (2.5.4 / perf #21) Signal the watchdog that live work exists.
        // Once per request: get_option is alloptions/notoptions-cached, so
        // the steady-state cost of this guard is an array lookup.
        static $easyopt_flag_raised = false;
        if ( ! $easyopt_flag_raised ) {
            $easyopt_flag_raised = true;
            if ( '1' !== (string) get_option( 'easyopt_queue_active', '' ) ) {
                update_option( 'easyopt_queue_active', '1', false );
            }
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Kick the queue. Fires a non-blocking REST loopback if there are
     * pending tasks and we have an available concurrency slot. Safe to
     * call from any context — never blocks, never throws.
     */
    public function start_queue() {
        self::dispatch_runners( $this->group_name, $this->callback_action );
    }

    /**
     * Total live work for this (group, callback) — pending + processing.
     * Completed tasks are deleted; failed tasks are NOT counted (UI can
     * call get_failed_count() separately if needed).
     */
    public function get_pending_count() {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE group_name = %s
               AND callback_action = %s
               AND status IN ('pending','processing')",
            $this->group_name,
            $this->callback_action
        ) );
    }

    /**
     * Failed-task count for this (group, callback). Useful for surfacing
     * "X URLs couldn't be preloaded" in admin UI.
     */
    public function get_failed_count() {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE group_name = %s
               AND callback_action = %s
               AND status = 'failed'",
            $this->group_name,
            $this->callback_action
        ) );
    }

    /**
     * Delete every row for this (group, callback). Used by "stop
     * preload" and by clear_all() on cache clear.
     */
    public function clear_queue() {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table WHERE group_name = %s AND callback_action = %s",
            $this->group_name,
            $this->callback_action
        ) );
    }

    /* ───────────────────────────────────────────────
     *  Table management
     * ─────────────────────────────────────────────── */

    public static function maybe_create_table() {
        // (2.5.4 / perf #26) Shared autoloaded fast path first — zero extra
        // queries in steady state. The legacy per-component option below
        // stays the source of truth for create/upgrade and seeds the map.
        if ( class_exists( 'EasyOpt_Config' ) && method_exists( 'EasyOpt_Config', 'schema_version' )
            && EasyOpt_Config::schema_version( 'queue' ) === (string) self::SCHEMA_VERSION ) {
            return;
        }
        $current = (int) get_option( 'easyopt_queue_schema_version', 0 );
        if ( $current === self::SCHEMA_VERSION ) {
            if ( class_exists( 'EasyOpt_Config' ) && method_exists( 'EasyOpt_Config', 'set_schema_version' ) ) {
                EasyOpt_Config::set_schema_version( 'queue', (string) self::SCHEMA_VERSION );
            }
            return;
        }

        global $wpdb;
        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // The unique key on (group_name, task_hash) is what makes the
        // INSERT ... ON DUPLICATE KEY UPDATE dedup atomic. idx_runner
        // covers the hot path in claim_next_task: pick the
        // lowest-priority pending row in (group, callback) order.
        // idx_lock supports the watchdog's "stuck rows" detection AND the
        // GC's orphaned-`processing` purge. idx_gc supports the GC's
        // age-based `failed` purge and the FAILED-row cap eviction.
        $sql = "CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            group_name VARCHAR(32) NOT NULL,
            callback_action VARCHAR(64) NOT NULL,
            task_data LONGTEXT NOT NULL,
            task_hash CHAR(64) NOT NULL,
            url_hash CHAR(40) NOT NULL DEFAULT '',
            priority INT NOT NULL DEFAULT 20,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            lock_token VARCHAR(64) NULL,
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            locked_at DATETIME NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_group_task (group_name, task_hash),
            KEY idx_runner (group_name, callback_action, status, priority, created_at, id),
            KEY idx_lock (status, locked_at),
            KEY idx_gc (status, updated_at),
            KEY idx_url_hash (group_name, url_hash)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // dbDelta is unreliable at ADDING a KEY to a pre-existing table
        // (it compares key definitions loosely and often skips the add),
        // so guarantee idx_gc explicitly on upgrade. Idempotent.
        self::maybe_add_index( 'idx_gc', '(status, updated_at)' );
        // v3 (2.5.0): dbDelta DOES add the url_hash column reliably, but
        // the index needs the same explicit guarantee as idx_gc.
        self::maybe_add_index( 'idx_url_hash', '(group_name, url_hash)' );

        // One-time disk reclaim. An existing install ($current > 0) may
        // carry a bloated .ibd from a past full-site preload spike —
        // InnoDB never returns freed pages to the OS on DELETE. Flag the
        // next GC run to OPTIMIZE TABLE once (queue table only). Brand-new
        // installs ($current === 0) have nothing to reclaim, so we skip it.
        if ( $current > 0 ) {
            update_option( 'easyopt_queue_gc_optimize_once', 1, false );
        }

        update_option( 'easyopt_queue_schema_version', self::SCHEMA_VERSION, false );
        if ( class_exists( 'EasyOpt_Config' ) && method_exists( 'EasyOpt_Config', 'set_schema_version' ) ) {
            EasyOpt_Config::set_schema_version( 'queue', (string) self::SCHEMA_VERSION ); // (2.5.4 / perf #26)
        }
    }

    /**
     * Add an index to the queue table if it isn't already present.
     * Idempotent — safe to call on every upgrade. Uses information_schema
     * so we never issue a duplicate-key ALTER.
     */
    private static function maybe_add_index( $index_name, $columns_sql ) {
        global $wpdb;
        $table = self::table_name();

        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(1) FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name   = %s
               AND index_name   = %s",
            $table,
            $index_name
        ) );

        if ( 0 === $exists ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( "ALTER TABLE $table ADD INDEX $index_name $columns_sql" );
        }
    }

    public static function drop_table() {
        global $wpdb;
        $wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_name() );
        delete_option( 'easyopt_queue_schema_version' );
        if ( class_exists( 'EasyOpt_Config' ) && method_exists( 'EasyOpt_Config', 'clear_schema_version' ) ) {
            EasyOpt_Config::clear_schema_version( 'queue' ); // (2.5.4 / perf #26)
        }
        delete_option( 'easyopt_queue_gc_optimize_once' );
        self::clear_watchdog();
        self::clear_gc();
    }

    private static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'easyopt_queue';
    }

    /* ───────────────────────────────────────────────
     *  Watchdog
     * ─────────────────────────────────────────────── */

    /**
     * Register the 1-minute schedule we use for the watchdog. Named
     * `easyopt_one_minute` (not just `1min`) to avoid colliding with
     * other plugins.
     */
    public static function register_schedule( $schedules ) {
        if ( ! isset( $schedules[ self::WATCHDOG_SCHEDULE ] ) ) {
            $schedules[ self::WATCHDOG_SCHEDULE ] = array(
                'interval' => MINUTE_IN_SECONDS,
                'display'  => __( 'Every minute (Easy Optimizer queue watchdog)', 'easy-optimizer' ),
            );
        }
        return $schedules;
    }

    public static function setup_watchdog() {
        $next = wp_next_scheduled( self::WATCHDOG_HOOK );
        $sched = $next ? wp_get_schedule( self::WATCHDOG_HOOK ) : false;
        if ( ! $next || $sched !== self::WATCHDOG_SCHEDULE ) {
            wp_clear_scheduled_hook( self::WATCHDOG_HOOK );
            wp_schedule_event( time(), self::WATCHDOG_SCHEDULE, self::WATCHDOG_HOOK );
        }
    }

    public static function clear_watchdog() {
        wp_clear_scheduled_hook( self::WATCHDOG_HOOK );
    }

    /* ───────────────────────────────────────────────
     *  Garbage collection
     * ─────────────────────────────────────────────── */

    /**
     * Ensure the daily GC event is scheduled. `daily` is a WordPress
     * built-in, so no cron_schedules registration is needed. First run is
     * offset by an hour so it never fires during the activation rush.
     */
    public static function setup_gc() {
        if ( ! wp_next_scheduled( self::GC_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, self::GC_SCHEDULE, self::GC_HOOK );
        }
    }

    public static function clear_gc() {
        wp_clear_scheduled_hook( self::GC_HOOK );
    }

    /**
     * The daily self-clean. Removes ONLY terminal / orphaned rows, then
     * reclaims tablespace when a pass freed meaningful space.
     *
     * Order:
     *   1. Purge `failed` rows older than GC_FAILED_TTL (24 h).
     *   2. Purge orphaned `processing` rows stuck past GC_STUCK_TTL (1 h)
     *      — far beyond the watchdog's 120 s reclaim window, so we never
     *      delete a row that's about to be legitimately retried.
     *   3. Cap retained `failed` rows at GC_FAILED_MAX_ROWS, evicting the
     *      oldest first. FAILED-only — pending / live processing untouched.
     *   4. OPTIMIZE TABLE (queue table only) if this pass freed
     *      ≥ GC_OPTIMIZE_MIN_ROWS, or once after an upgrade (historical
     *      bloat reclaim). InnoDB doesn't shrink the .ibd on DELETE alone.
     *
     * All deletes are LIMIT-batched to keep locks short on huge tables.
     */
    public static function run_gc() {
        global $wpdb;
        $table = self::table_name();

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        if ( ! $exists ) {
            return;
        }

        $deleted = 0;

        // (1) Aged-out failed rows.
        $deleted += self::gc_delete_batched( $wpdb->prepare(
            "DELETE FROM $table
             WHERE status = 'failed'
               AND updated_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d SECOND )
             LIMIT %d",
            self::GC_FAILED_TTL,
            self::GC_BATCH
        ) );

        // (2) Orphaned processing rows (dead runner).
        $deleted += self::gc_delete_batched( $wpdb->prepare(
            "DELETE FROM $table
             WHERE status = 'processing'
               AND locked_at IS NOT NULL
               AND locked_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d SECOND )
             LIMIT %d",
            self::GC_STUCK_TTL,
            self::GC_BATCH
        ) );

        // (3) Hard cap on failed rows — oldest-first eviction of the
        //     overage only. Bounded loop so we delete EXACTLY the overage,
        //     never all failed rows.
        $failed_total = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(1) FROM $table WHERE status = %s", 'failed' )
        );
        if ( $failed_total > self::GC_FAILED_MAX_ROWS ) {
            $over = $failed_total - self::GC_FAILED_MAX_ROWS;
            while ( $over > 0 ) {
                $chunk = (int) min( $over, self::GC_BATCH );
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $affected = (int) $wpdb->query( $wpdb->prepare(
                    "DELETE FROM $table
                     WHERE status = 'failed'
                     ORDER BY updated_at ASC
                     LIMIT %d",
                    $chunk
                ) );
                $deleted += $affected;
                $over    -= $affected;
                if ( $affected < 1 ) {
                    break;
                }
            }
        }

        // (4) Reclaim disk — gated so healthy / small tables never rebuild.
        $optimize_once = (int) get_option( 'easyopt_queue_gc_optimize_once', 0 );
        // (2.3.3) Piggyback the asset-cache GC on the same daily tick —
        // sweeps minified/inline/used-CSS files no render has referenced in
        // the retention window. See EasyOpt_Cache::gc_asset_caches().
        if ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'gc_asset_caches' ) ) {
            EasyOpt_Cache::gc_asset_caches();
        }

        // (2.4.0) Backend Analyzer retention: 14-day eviction + row caps.
        // class_exists(…, false): no autoload — only runs when the module
        // is enabled and therefore already loaded this request.
        if ( class_exists( '\\EasyOpt\\Backend\\Backend', false ) ) {
            \EasyOpt\Backend\Backend::gc();
        }

        // (2.4.0) Daily PSI auto-scan — refreshes the dashboard's "after"
        // scores once per ~day when enabled and previously benchmarked.
        // (2.5.4 / perf #51) Detached to its own single event instead of
        // running inline: run_job() holds this cron request for up to two
        // 120 s PSI API calls, and anything ordered after it in the GC tick
        // (the OPTIMIZE TABLE below included) risked hitting the PHP time
        // limit behind it. The scan itself is unchanged — same gates, same
        // cadence — it just occupies its own request now.
        if ( class_exists( '\\EasyOpt\\Psi\\Client', false ) ) {
            if ( ! wp_next_scheduled( 'easyopt_psi_auto_scan' ) ) {
                wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, 'easyopt_psi_auto_scan' );
            }
        }

        if ( $optimize_once || $deleted >= self::GC_OPTIMIZE_MIN_ROWS ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( "OPTIMIZE TABLE $table" );
            if ( $optimize_once ) {
                delete_option( 'easyopt_queue_gc_optimize_once' );
            }
        }
    }

    /**
     * Run the same LIMIT-batched DELETE until it stops matching rows.
     * Capped at 40 passes (≤ 200k rows / GC tick at GC_BATCH=5000) so a
     * runaway condition can't monopolise the DB — any remainder is cleaned
     * on the next daily tick. The supplied SQL must be already-prepared and
     * carry its own LIMIT clause.
     *
     * @return int Total rows deleted.
     */
    private static function gc_delete_batched( $prepared_sql ) {
        global $wpdb;
        $total = 0;

        for ( $i = 0; $i < 40; $i++ ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $affected = (int) $wpdb->query( $prepared_sql );
            $total   += $affected;
            if ( $affected < self::GC_BATCH ) {
                break;
            }
        }

        return $total;
    }

    /**
     * 1.6.1 inline watchdog — REMOVED in 1.7.1.
     *
     * This fired run_watchdog() from wp_loaded on every request and
     * caused 508 Loop Detected on LiteSpeed/Cloudways servers. The
     * WP-Cron watchdog (every 60s) is the only recovery mechanism now,
     * for consistent pacing.
     *
     * Method kept as a no-op for backward compatibility.
     */
    public static function maybe_run_watchdog_inline() {
        return; // disabled — use WP-Cron watchdog only
    }

    /**
     * The 1-minute self-heal. One SQL query looks for groups that have
     * either:
     *   (a) pending tasks but NO live runner (locked_at < 120 s old), OR
     *   (b) processing rows whose lock has expired (the runner died).
     *
     * For any such group, re-fire the loopback dispatcher. The runner
     * itself handles the stuck-row reclaim during its claim cycle, we
     * just need to make sure a runner exists.
     *
     * This is the safety net AS doesn't provide. Without it, a single
     * blocked loopback turn = preload stuck forever.
     */
    public static function run_watchdog() {
        global $wpdb;
        $table = self::table_name();

        // (2.5.4 / perf #21) Idle fast path. The queue is empty for the vast
        // majority of watchdog ticks, yet every minute used to pay SHOW
        // TABLES + a full-table GROUP BY/HAVING aggregate to discover that.
        // add_task() raises this flag; the watchdog lowers it below only
        // after verifying no pending/processing rows remain. A missing flag
        // (pre-2.5.4 upgrade with rows already queued) counts as active, so
        // no in-flight work can ever be stranded.
        $easyopt_active_flag = get_option( 'easyopt_queue_active', 'unset' );
        if ( '0' === (string) $easyopt_active_flag ) {
            return; // one option read and done.
        }

        // Bail early if the table doesn't exist (fresh install before
        // plugins_loaded fired, or someone dropped the table).
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        if ( ! $exists ) {
            return;
        }

        // Find (group, callback) pairs where:
        //   - no live runner (no processing row with locked_at >= now-120s), AND
        //   - either a pending row exists, OR a stuck processing row exists.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT group_name, callback_action
             FROM $table
             GROUP BY group_name, callback_action
             HAVING
                SUM(CASE WHEN status = 'processing'
                              AND locked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)
                         THEN 1 ELSE 0 END) = 0
                AND (
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) > 0
                    OR SUM(CASE WHEN status = 'processing'
                                     AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)
                                THEN 1 ELSE 0 END) > 0
                )
             LIMIT %d",
            self::TASK_LOCK_TIMEOUT,
            self::TASK_LOCK_TIMEOUT,
            self::WATCHDOG_BATCH
        ), ARRAY_A );

        // (2.5.4 / perf #21) Nothing actionable → check whether the queue is
        // fully drained (no pending AND no live processing rows) and, if so,
        // lower the flag so subsequent ticks take the one-read fast path.
        // A live runner keeps the flag up: its rows still match the EXISTS
        // probe, so a runner that later dies is still reclaimed normally.
        if ( empty( $rows ) ) {
            $easyopt_any = $wpdb->get_var(
                "SELECT 1 FROM $table WHERE status IN ('pending','processing') LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            );
            if ( null === $easyopt_any ) {
                update_option( 'easyopt_queue_active', '0', false );
            }
            return;
        }

        $drain_deadline = microtime( true ) + self::INLINE_DRAIN_BUDGET_SECONDS;

        foreach ( (array) $rows as $row ) {
            $group    = sanitize_text_field( (string) ( $row['group_name'] ?? '' ) );
            $callback = sanitize_text_field( (string) ( $row['callback_action'] ?? '' ) );
            if ( '' === $group || '' === $callback ) {
                continue;
            }
            self::dispatch_runners( $group, $callback );
            // (2.4.5) Guarantee forward progress even when the loopback above
            // is silently blocked by the host. Bounded by a per-tick deadline
            // (shared across all groups) and a per-group task cap, so it stays
            // gentle. Safe to overlap a real runner — claims are atomic.
            if ( microtime( true ) < $drain_deadline ) {
                self::drain_inline( $group, $callback, $drain_deadline );
            }
        }
    }

    /**
     * (2.4.5) Bounded, time-boxed in-process drain — the loopback-blocked
     * fallback described on INLINE_DRAIN_MAX_TASKS. Claims and processes at
     * most INLINE_DRAIN_MAX_TASKS tasks, stopping early at $deadline, using the
     * same atomic claim the REST runner uses (so a task is never processed
     * twice even if a loopback runner is concurrently live).
     *
     * @param string $group
     * @param string $callback
     * @param float  $deadline Absolute microtime() after which to stop.
     * @return int Tasks processed.
     */
    private static function drain_inline( $group, $callback, $deadline ) {
        if ( '' === $group || '' === $callback ) {
            return 0;
        }
        if ( function_exists( 'ignore_user_abort' ) ) {
            @ignore_user_abort( true );
        }
        $done  = 0;
        $empty = false;
        while ( $done < self::INLINE_DRAIN_MAX_TASKS && microtime( true ) < $deadline ) {
            $task = self::claim_next_task( $group, $callback );
            if ( empty( $task ) ) {
                $empty = true;
                break;
            }
            self::process_task( $task );
            $done++;
        }
        // (2.5.0 / M2) Same drained signal as the REST runner, so hosts
        // that only ever progress via the cron drain still resolve state.
        if ( $empty && ! self::has_pending_tasks( $group, $callback ) ) {
            do_action( 'easyopt_queue_group_drained', $group, $callback );
        }
        return $done;
    }

    /* ───────────────────────────────────────────────
     *  REST endpoint — the actual worker loop
     * ─────────────────────────────────────────────── */

    /**
     * (2.4.5) On-demand bounded inline drain across every group that currently
     * has runnable work. Used by the manual "Preload Now" trigger on hosts
     * where WP-Cron is disabled (so the watchdog can't be relied on to fire),
     * giving the click immediate forward progress even if the loopback is also
     * blocked. Bounded by the same small per-tick budget as the watchdog, so
     * it never loads the origin. Returns the number of tasks processed.
     *
     * @return int
     */
    public static function drain_pending_inline() {
        global $wpdb;
        $table = self::table_name();

        // Distinct (group, callback) pairs with a pending row, or a processing
        // row whose lock has expired (crashed/blocked runner).
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT group_name, callback_action
                   FROM $table
                  WHERE status = 'pending'
                     OR ( status = 'processing'
                          AND locked_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d SECOND ) )
               GROUP BY group_name, callback_action
                  LIMIT %d",
                self::TASK_LOCK_TIMEOUT,
                self::WATCHDOG_BATCH
            ),
            ARRAY_A
        );
        if ( empty( $rows ) ) {
            return 0;
        }

        $deadline = microtime( true ) + self::INLINE_DRAIN_BUDGET_SECONDS;
        $done     = 0;
        foreach ( (array) $rows as $row ) {
            if ( microtime( true ) >= $deadline ) {
                break;
            }
            $group    = sanitize_text_field( (string) ( $row['group_name'] ?? '' ) );
            $callback = sanitize_text_field( (string) ( $row['callback_action'] ?? '' ) );
            if ( '' === $group || '' === $callback ) {
                continue;
            }
            $done += self::drain_inline( $group, $callback, $deadline );
        }
        return $done;
    }

    public static function register_rest_route() {
        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            array(
                'methods'             => 'POST',
                // Auth is via HMAC token in the body. `__return_true`
                // is intentional — see run_queue_from_rest() below.
                'permission_callback' => '__return_true',
                'callback'            => array( __CLASS__, 'run_queue_from_rest' ),
            )
        );
    }

    /**
     * REST handler. Verifies the HMAC token, then loops for up to
     * RUNNER_WINDOW_SECONDS claiming and processing tasks. Each
     * iteration:
     *
     *   1. Atomically claim one task via UPDATE ... LIMIT 1
     *   2. Dispatch its callback_action with the task payload
     *   3. On success: DELETE the row
     *   4. On exception: status=pending + locked_at=now+backoff (retry),
     *      or status=failed if max_retries exhausted
     *
     * Multi-tenancy: if another runner is started for the same group
     * mid-loop, both run concurrently up to the configured concurrency.
     * Task claim is race-safe via the lock_token mechanism.
     */
    public static function run_queue_from_rest( $request ) {
        $group    = sanitize_text_field( (string) $request->get_param( 'group' ) );
        $callback = sanitize_text_field( (string) $request->get_param( 'callback' ) );
        $token    = sanitize_text_field( (string) $request->get_param( 'token' ) );

        if ( '' === $group || '' === $callback
             || ! hash_equals( self::build_runner_token( $group, $callback ), $token ) ) {
            return new WP_Error(
                'easyopt-queue/invalid-runner',
                'Invalid runner request',
                array( 'status' => 403 )
            );
        }

        // Keep PHP alive even if the (rare) blocking caller hangs up.
        if ( function_exists( 'ignore_user_abort' ) ) {
            @ignore_user_abort( true );
        }

        $started_at = microtime( true );
        $processed  = 0;

        // Phase 5: announce this runner in the heartbeat registry (no-op
        // without a persistent object cache).
        $hb_id = self::heartbeat_register( $group, $callback );

        // Phase 3/4: opt-in batch mode. Only engages when a batch handler is
        // registered for this group AND an effective size ≥ 2 is requested via
        // the filter (default 1). Otherwise the proven single-task loop runs
        // exactly as before — no behaviour change on the default path.
        $batch_handler = self::get_batch_handler( $group, $callback );
        $batch_size    = (int) apply_filters( 'easyopt_queue_batch_size', 1, $group, $callback );
        $use_batch     = ( null !== $batch_handler && $batch_size >= 2 );

        while ( microtime( true ) - $started_at < self::RUNNER_WINDOW_SECONDS ) {
            // Backpressure: a group can ask the runner to stand down for a
            // while (the preload governor sets this on a 429/503 Retry-After).
            // We exit cleanly rather than spin; the watchdog re-fires us within
            // ~60s, by which time the pause has typically elapsed.
            $pause_until = (int) apply_filters( 'easyopt_queue_pause_until', 0, $group, $callback );
            if ( $pause_until > time() ) {
                break;
            }
            self::heartbeat_refresh( $group, $callback, $hb_id );

            /**
             * Admission gate. A group may declare it has no capacity right now
             * (e.g. the preload in-flight budget is full). We idle briefly
             * INSIDE the window rather than claiming a task we would have to
             * defer — deferring runs through the failure path, and three of
             * those mark a task 'failed'. No URL should ever fail merely
             * because the host was momentarily busy.
             *
             * @param bool   $can_claim
             * @param string $group
             * @param string $callback
             */
            if ( ! (bool) apply_filters( 'easyopt_queue_can_claim', true, $group, $callback ) ) {
                usleep( 200000 ); // 200 ms; the while() bound still applies.
                continue;
            }

            if ( $use_batch ) {
                $tasks = self::claim_next_batch( $group, $callback, $batch_size );
                if ( empty( $tasks ) ) {
                    break;
                }
                self::process_batch( $group, $callback, $tasks, $batch_handler );
                $processed += count( $tasks );
                continue;
            }

            $task = self::claim_next_task( $group, $callback );
            if ( empty( $task ) ) {
                break;
            }
            self::process_task( $task );
            $processed++;
        }

        // Clean exit → remove our heartbeat so the slot frees immediately.
        self::heartbeat_unregister( $group, $callback, $hb_id );

        // If pending tasks remain at exit (because we hit the window
        // or concurrency cap), the watchdog will re-fire us within
        // ~60 s. Also fire an immediate dispatch in case another slot
        // is now free.
        if ( self::has_pending_tasks( $group, $callback ) ) {
            self::dispatch_runners( $group, $callback );
        } else {
            /**
             * (2.5.0 / M2) The runner found the group EMPTY on exit. State
             * transitions that used to be side effects of GET status
             * endpoints (preload's running → done flip) now hang off this
             * authoritative signal instead, so status reads are read-only.
             */
            do_action( 'easyopt_queue_group_drained', $group, $callback );
        }

        return rest_ensure_response( array(
            'processed' => $processed,
            'group'     => $group,
            'callback'  => $callback,
        ) );
    }

    /**
     * Atomically claim the next pending task for (group, callback).
     * Returns the row (as assoc array) or null if nothing's available.
     *
     * The trick: UPDATE-then-SELECT, gated by a per-claim random
     * lock_token. The UPDATE returns rows_affected=1 only when the
     * pending row was found AND we won the race against any other
     * concurrent runner. The follow-up SELECT then retrieves the
     * row we just locked.
     *
     * We also pick up stuck processing rows whose locked_at has
     * expired — runners that died mid-task leave their row in this
     * state, and the next runner claims it (incrementing attempts).
     */
    private static function claim_next_task( $group, $callback ) {
        global $wpdb;
        $table      = self::table_name();
        $lock_token = wp_generate_password( 32, false );

        // The WHERE clause matches either:
        //   - a pending row with no lock (or expired lock)
        //   - a processing row whose lock has expired (crashed runner)
        //
        // Note the `locked_at <= UTC_TIMESTAMP()` form: rows we
        // bumped to "retry in N seconds" set locked_at = NOW + backoff,
        // so they become eligible again when locked_at hits now.
        $wpdb->query( $wpdb->prepare(
            "UPDATE $table
             SET status     = 'processing',
                 lock_token = %s,
                 locked_at  = UTC_TIMESTAMP(),
                 attempts   = attempts + 1,
                 updated_at = UTC_TIMESTAMP()
             WHERE group_name = %s
               AND callback_action = %s
               AND (
                   (status = 'pending'
                       AND (locked_at IS NULL OR locked_at <= UTC_TIMESTAMP()))
                   OR (status = 'processing'
                       AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND))
               )
             ORDER BY priority ASC, created_at ASC, id ASC
             LIMIT 1",
            $lock_token,
            $group,
            $callback,
            self::TASK_LOCK_TIMEOUT
        ) );

        if ( ! $wpdb->rows_affected ) {
            return null;
        }

        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM $table WHERE lock_token = %s LIMIT 1", $lock_token ),
            ARRAY_A
        );
    }

    /* ───────────────────────────────────────────────
     *  Batch claim / completion (Phase 3/4) — opt-in
     *
     *  Same atomic UPDATE-then-SELECT trick as claim_next_task, but claims up
     *  to N eligible rows under ONE shared lock_token so a batch handler can
     *  process them together (e.g. warm several URLs over concurrent HTTP).
     *  Successes are removed in a single DELETE; failures reuse the exact
     *  backoff/fail rules of process_task. Unused unless a batch handler is
     *  registered, so it can never change default behaviour.
     * ─────────────────────────────────────────────── */

    /** Register a batch handler for (group, callback).
     *  Signature: function( array $tasks ): array — returns
     *  [ 'done' => [id,…], 'retry' => [ id => 'error message', … ] ].
     *  Each $task is the row assoc array (with lock_token). */
    public static function register_batch_handler( $group, $callback, $handler ) {
        if ( is_callable( $handler ) ) {
            self::$queue_batch_callbacks[ self::queue_config_key( $group, $callback ) ] = $handler;
        }
    }

    private static function get_batch_handler( $group, $callback ) {
        $key = self::queue_config_key( $group, $callback );
        return isset( self::$queue_batch_callbacks[ $key ] ) ? self::$queue_batch_callbacks[ $key ] : null;
    }

    /**
     * Atomically claim up to $limit eligible rows under one shared lock_token.
     * Returns an array of row assoc arrays (possibly empty).
     */
    private static function claim_next_batch( $group, $callback, $limit ) {
        global $wpdb;
        $table      = self::table_name();
        $limit      = max( 1, (int) $limit );
        $lock_token = wp_generate_password( 32, false );

        $wpdb->query( $wpdb->prepare(
            "UPDATE $table
             SET status     = 'processing',
                 lock_token = %s,
                 locked_at  = UTC_TIMESTAMP(),
                 attempts   = attempts + 1,
                 updated_at = UTC_TIMESTAMP()
             WHERE group_name = %s
               AND callback_action = %s
               AND (
                   (status = 'pending'
                       AND (locked_at IS NULL OR locked_at <= UTC_TIMESTAMP()))
                   OR (status = 'processing'
                       AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND))
               )
             ORDER BY priority ASC, created_at ASC, id ASC
             LIMIT %d",
            $lock_token,
            $group,
            $callback,
            self::TASK_LOCK_TIMEOUT,
            $limit
        ) );

        if ( ! $wpdb->rows_affected ) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table WHERE lock_token = %s ORDER BY priority ASC, created_at ASC, id ASC",
                $lock_token
            ),
            ARRAY_A
        );
        return is_array( $rows ) ? $rows : array();
    }

    /** Delete a set of successfully-processed rows in one query. */
    private static function complete_tasks_batch( $ids, $lock_token ) {
        global $wpdb;
        $ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
        if ( empty( $ids ) ) {
            return;
        }
        $table        = self::table_name();
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $params       = $ids;
        $params[]     = (string) $lock_token;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM $table WHERE id IN ($placeholders) AND lock_token = %s",
            $params
        ) );
    }

    /**
     * Apply the same retry/backoff-or-fail rules process_task() uses, for a
     * single failed row in a batch. attempts was already bumped on claim.
     */
    private static function batch_handle_failure( $task, $errmsg ) {
        global $wpdb;
        $table       = self::table_name();
        $attempts    = (int) ( $task['attempts'] ?? 0 );
        $max_retries = self::get_max_retries(
            (string) ( $task['group_name'] ?? '' ),
            (string) ( $task['callback_action'] ?? '' )
        );
        $last_error = substr( (string) $errmsg, 0, 1000 );

        if ( $attempts < $max_retries ) {
            $backoff = self::get_retry_backoff_seconds( $attempts );
            $wpdb->query( $wpdb->prepare(
                "UPDATE $table
                 SET status     = 'pending',
                     lock_token = NULL,
                     locked_at  = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND),
                     last_error = %s,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = %d",
                $backoff,
                $last_error,
                (int) $task['id']
            ) );
        } else {
            $wpdb->query( $wpdb->prepare(
                "UPDATE $table
                 SET status     = 'failed',
                     lock_token = NULL,
                     locked_at  = NULL,
                     last_error = %s,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = %d",
                $last_error,
                (int) $task['id']
            ) );
            // (2.5.0 / C3) Same terminal-failure signal as process_task.
            $td = json_decode( isset( $task['task_data'] ) ? (string) $task['task_data'] : '', true );
            do_action(
                'easyopt_queue_task_failed',
                (string) ( $task['group_name'] ?? '' ),
                (string) ( $task['callback_action'] ?? '' ),
                is_array( $td ) ? $td : array(),
                $last_error
            );
        }
    }

    /**
     * Process one claimed batch through its registered handler, then reconcile:
     * DELETE the successes in one query and apply retry/fail to the rest. Any
     * row the handler doesn't account for is treated as a retry so nothing is
     * silently dropped. Returns the number of rows the handler marked done.
     */
    private static function process_batch( $group, $callback, $tasks, $handler ) {
        if ( empty( $tasks ) ) {
            return 0;
        }
        $lock_token = (string) ( $tasks[0]['lock_token'] ?? '' );

        // Index rows by id so we can reconcile the handler's verdict.
        $by_id = array();
        foreach ( $tasks as $t ) {
            $by_id[ (int) $t['id'] ] = $t;
        }

        try {
            $result = call_user_func( $handler, $tasks );
        } catch ( \Throwable $e ) {
            // Handler blew up wholesale → retry the entire batch.
            foreach ( $by_id as $t ) {
                self::batch_handle_failure( $t, 'batch handler error: ' . $e->getMessage() );
            }
            return 0;
        }

        $done  = array();
        $retry = array();
        if ( is_array( $result ) ) {
            $done  = isset( $result['done'] )  && is_array( $result['done'] )  ? array_map( 'intval', $result['done'] ) : array();
            $retry = isset( $result['retry'] ) && is_array( $result['retry'] ) ? $result['retry'] : array();
        }

        // Successes: single DELETE.
        if ( ! empty( $done ) ) {
            self::complete_tasks_batch( $done, $lock_token );
        }

        // Everything not marked done is retried/failed (explicit retry messages
        // preferred; unaccounted rows get a generic reason).
        $done_map = array_flip( $done );
        foreach ( $by_id as $id => $t ) {
            if ( isset( $done_map[ $id ] ) ) {
                continue;
            }
            $msg = isset( $retry[ $id ] ) ? (string) $retry[ $id ] : 'not completed in batch';
            self::batch_handle_failure( $t, $msg );
        }

        return count( $done );
    }

    /**
     * Dispatch one task to its callback_action. Success deletes the
     * row; exception increments attempts and either retries (with
     * exponential backoff) or marks failed.
     */
    private static function process_task( $task ) {
        global $wpdb;
        $table     = self::table_name();
        $task_data = json_decode( $task['task_data'], true );
        $task_data = is_array( $task_data ) ? $task_data : array();

        // call EasyOpt_Queue::touch_lock() during long work and avoid
        // the watchdog reclaiming it mid-execution. Underscore-prefixed
        // so they don't collide with userland keys, and they're added
        // here (not persisted to the DB column) so the on-disk task_data
        // stays clean for dedup hashing.
        $task_data['_task_id']    = (int)    $task['id'];
        $task_data['_lock_token'] = (string) $task['lock_token'];

        try {
            do_action_ref_array( $task['callback_action'], array( $task_data ) );

            // Tiny inter-task pause keeps a runner from monopolising the
            // database or origin server. Filterable so a host with extra
            // headroom can shorten it.
            // 0.05s was too aggressive for shared hosting (PHP 7.4, 30s
            // timeout) and caused 502/507 errors. 0.5s = ~40 URLs/min per
            // runner, which is sustainable on virtually any host.
            $delay_seconds = (float) apply_filters( 'easyopt_queue_task_delay', 0.5, ( isset( $task['group_name'] ) ? $task['group_name'] : '' ) );
            $delay_us      = (int) round( $delay_seconds * 1_000_000 );
            if ( $delay_us > 0 ) {
                usleep( $delay_us );
            }

            // Success → delete. Keep the table small.
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM $table WHERE id = %d AND lock_token = %s",
                (int) $task['id'],
                $task['lock_token']
            ) );

        } catch ( \Throwable $e ) {
            $attempts    = (int) ( $task['attempts'] ?? 0 );
            $max_retries = self::get_max_retries(
                (string) ( $task['group_name'] ?? '' ),
                (string) ( $task['callback_action'] ?? '' )
            );
            // Truncate to fit the TEXT column comfortably and avoid
            // dumping a stack trace into the DB.
            $last_error = substr( $e->getMessage(), 0, 1000 );

            // EasyOpt_Queue_Defer_Exception when they want to be
            // re-queued without consuming a retry attempt (e.g. server
            // is busy right now; come back in 60s). attempts is rolled
            // back so a long busy spell can't burn through max_retries.
            if ( $e instanceof EasyOpt_Queue_Defer_Exception ) {
                $defer_seconds = (int) $e->getCode();
                if ( $defer_seconds <= 0 ) {
                    $defer_seconds = 60;
                }
                // Roll attempts back (claim_next_task bumped it on claim).
                $new_attempts = max( 0, $attempts - 1 );
                $wpdb->query( $wpdb->prepare(
                    "UPDATE $table
                     SET status     = 'pending',
                         lock_token = NULL,
                         locked_at  = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND),
                         attempts   = %d,
                         last_error = %s,
                         updated_at = UTC_TIMESTAMP()
                     WHERE id = %d",
                    $defer_seconds,
                    $new_attempts,
                    $last_error,
                    (int) $task['id']
                ) );
                return;
            }

            if ( $attempts < $max_retries ) {
                $backoff = self::get_retry_backoff_seconds( $attempts );
                $wpdb->query( $wpdb->prepare(
                    "UPDATE $table
                     SET status     = 'pending',
                         lock_token = NULL,
                         locked_at  = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND),
                         last_error = %s,
                         updated_at = UTC_TIMESTAMP()
                     WHERE id = %d",
                    $backoff,
                    $last_error,
                    (int) $task['id']
                ) );
            } else {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE $table
                     SET status     = 'failed',
                         lock_token = NULL,
                         locked_at  = NULL,
                         last_error = %s,
                         updated_at = UTC_TIMESTAMP()
                     WHERE id = %d",
                    $last_error,
                    (int) $task['id']
                ) );
                /**
                 * (2.5.0 / C3) Retries exhausted — surface the terminal
                 * failure to interested modules (the preload results
                 * ledger records it against the URL so the dashboard can
                 * show it and the revert pass can retry it later).
                 *
                 * @param string $group      Queue group.
                 * @param string $callback   Callback action.
                 * @param array  $task_data  Decoded task payload.
                 * @param string $last_error Truncated error message.
                 */
                do_action(
                    'easyopt_queue_task_failed',
                    (string) ( $task['group_name'] ?? '' ),
                    (string) ( $task['callback_action'] ?? '' ),
                    is_array( $task_data ) ? $task_data : array(),
                    $last_error
                );
            }
        }
    }

    /**
     * Refresh a task's locked_at to NOW. Lets a long-running task
     * (e.g. preload's collect_urls + redirect-filter walk) signal that
     * it's alive so the watchdog's `locked_at + TASK_LOCK_TIMEOUT`
     * check doesn't reclaim it mid-work.
     *
     */
    public static function touch_lock( $task_id, $lock_token ) {
        global $wpdb;
        if ( (int) $task_id <= 0 || '' === (string) $lock_token ) {
            return false;
        }
        $table = self::table_name();
        $wpdb->query( $wpdb->prepare(
            "UPDATE $table
             SET locked_at = UTC_TIMESTAMP(),
                 updated_at = UTC_TIMESTAMP()
             WHERE id = %d AND lock_token = %s",
            (int) $task_id,
            (string) $lock_token
        ) );
        return (bool) $wpdb->rows_affected;
    }

    /**
     * Count remaining URLs (collapsed: desktop+mobile = 1 URL).
     *
     * MariaDB it errored, and even on 5.7+ it was a full table scan.
     * Simple ceil(rows/2) is accurate when separate_mobile is on
     * (every URL has exactly 2 tasks). When it's off, rows = URLs.
     * Either way the error margin is ±1 which is acceptable for a
     * progress indicator.
     */
    public static function count_distinct_urls( $group, $callback ) {
        global $wpdb;
        $table = self::table_name();

        // (2.5.4 / perf #33) Memoise the aggregate. Within one request the
        // dashboard-stats + preload-status handlers each used to run this
        // COUNT(DISTINCT) twice or more; across requests, dashboard polling
        // repeated it every couple of seconds. Per-request static removes
        // the intra-request duplicates everywhere; a 10 s object-cache entry
        // additionally absorbs cross-request polling on hosts with a
        // persistent cache. Add_task/claim/delete paths don't invalidate it —
        // a ≤10 s-stale PROGRESS number is invisible in the UI, and the
        // done-flip (maybe_mark_done) runs on a once-a-minute tick anyway.
        static $easyopt_cdu_memo = array();
        $easyopt_cdu_key = $group . '|' . $callback;
        if ( isset( $easyopt_cdu_memo[ $easyopt_cdu_key ] ) ) {
            return $easyopt_cdu_memo[ $easyopt_cdu_key ];
        }
        if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
            $easyopt_cdu_cached = wp_cache_get( 'easyopt_cdu_' . md5( $easyopt_cdu_key ), 'easyopt' );
            if ( false !== $easyopt_cdu_cached ) {
                $easyopt_cdu_memo[ $easyopt_cdu_key ] = (int) $easyopt_cdu_cached;
                return (int) $easyopt_cdu_cached;
            }
        }

        // (2.5.0 / H1) Count REAL distinct URLs via the url_hash column.
        // The old ceil(rows/2) heuristic assumed every URL always has
        // exactly two device rows, which breaks whenever rows are
        // asymmetric (per-device already-cached skips, companion
        // enqueues, one device retrying) — progress then oscillated and
        // could flip 'done' early/late. Rows written by pre-2.5.0 code
        // (url_hash = '') keep the old heuristic so an in-flight run
        // survives the upgrade without a wrong denominator.
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(DISTINCT NULLIF(url_hash, '')) AS hashed,
                    SUM(url_hash = '') AS legacy
             FROM $table
             WHERE group_name = %s
               AND callback_action = %s
               AND status IN ('pending','processing')",
            $group,
            $callback
        ), ARRAY_A );

        $hashed = isset( $row['hashed'] ) ? (int) $row['hashed'] : 0;
        $legacy = isset( $row['legacy'] ) ? (int) $row['legacy'] : 0;

        if ( $legacy > 0 ) {
            $separate_mobile = class_exists( 'EasyOpt_Config' )
                ? (int) EasyOpt_Config::get( 'cache_separate_mobile', 1 )
                : 1;
            $legacy = $separate_mobile ? (int) ceil( $legacy / 2 ) : $legacy;
        }
        $easyopt_cdu_total = $hashed + $legacy;
        $easyopt_cdu_memo[ $easyopt_cdu_key ] = $easyopt_cdu_total;
        if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
            wp_cache_set( 'easyopt_cdu_' . md5( $easyopt_cdu_key ), $easyopt_cdu_total, 'easyopt', 10 );
        }
        return $easyopt_cdu_total;
    }

    /**
     * (2.5.0 / C3) Of the given url_hashes, which still have LIVE queue
     * work (pending/processing) for (group, callback)? Used by the preload
     * results ledger's reconcile pass so it never marks a URL failed while
     * its warm task is still queued. One indexed query per batch.
     *
     * @param string   $group
     * @param string   $callback
     * @param string[] $hashes
     * @return string[] Subset of $hashes with live rows.
     */
    public static function live_hashes( $group, $callback, $hashes ) {
        global $wpdb;
        $hashes = array_values( array_filter( array_map( 'strval', (array) $hashes ) ) );
        if ( empty( $hashes ) ) {
            return array();
        }
        $table        = self::table_name();
        $placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
        $params       = array_merge( array( $group, $callback ), $hashes );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT url_hash FROM $table
             WHERE group_name = %s
               AND callback_action = %s
               AND status IN ('pending','processing')
               AND url_hash IN ($placeholders)",
            $params
        ) );
        return is_array( $rows ) ? $rows : array();
    }

    /* ───────────────────────────────────────────────
     *  Dispatcher (non-blocking REST loopback)
     * ─────────────────────────────────────────────── */

    /**
     * Fire up to (concurrency - active_runners) non-blocking POSTs to
     * the REST endpoint. Each POST starts an independent runner that
     * claims and processes tasks for up to RUNNER_WINDOW_SECONDS.
     *
     * `blocking => false, timeout => 0.01` means the caller doesn't
     * wait for a response. The OS sends the request and we return
     * immediately. This is what makes start_queue() / clear_all() /
     * activation NOT hang.
     */
    private static function dispatch_runners( $group, $callback ) {
        $active      = self::get_active_runner_count( $group, $callback );
        $concurrency = self::get_concurrency( $group, $callback );
        $available   = $concurrency - $active;
        if ( $available <= 0 ) {
            return;
        }

        $claimable = self::get_claimable_pending_count( $group, $callback );
        $dispatch  = min( $available, $claimable );
        for ( $i = 0; $i < $dispatch; $i++ ) {
            self::dispatch_runner( $group, $callback );
        }
    }

    private static function dispatch_runner( $group, $callback ) {
        $url = rest_url( trim( self::REST_NAMESPACE, '/' ) . self::REST_ROUTE );
        wp_remote_post( $url, array(
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => false,
            'body'      => array(
                'group'    => $group,
                'callback' => $callback,
                'token'    => self::build_runner_token( $group, $callback ),
            ),
        ) );
    }

    /* ───────────────────────────────────────────────
     *  Counting helpers
     * ─────────────────────────────────────────────── */

    private static function has_pending_tasks( $group, $callback ) {
        global $wpdb;
        $table = self::table_name();
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE group_name = %s
               AND callback_action = %s
               AND (
                   status = 'processing'
                   OR (status = 'pending'
                       AND (locked_at IS NULL OR locked_at <= UTC_TIMESTAMP()))
               )",
            $group,
            $callback
        ) );
        return $count > 0;
    }

    private static function get_active_runner_count( $group, $callback ) {
        // Phase 5: when a persistent object cache is present, prefer a
        // crash-safe heartbeat registry — it isn't fooled by the brief window
        // where a live runner holds no `processing` row (delete/claim gap →
        // over-spawn) nor by a crashed runner's row lingering for up to
        // TASK_LOCK_TIMEOUT (→ starvation). Without a shared cache, or if the
        // registry is empty, fall back to the original processing-row count so
        // behaviour is unchanged on those hosts.
        $hb = self::heartbeat_count( $group, $callback );
        if ( null !== $hb ) {
            return (int) $hb;
        }

        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE group_name = %s
               AND callback_action = %s
               AND status = 'processing'
               AND locked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)",
            $group,
            $callback,
            self::TASK_LOCK_TIMEOUT
        ) );
    }

    /* ───────────────────────────────────────────────
     *  Runner heartbeat registry (Phase 5)
     *
     *  A single object-cache key per (group, callback) holds a map of
     *  { runner_id => expiry }. Live runners refresh their entry each loop;
     *  crashed runners simply expire out (crash-safe). Only active when a
     *  PERSISTENT object cache is available — a non-persistent cache isn't
     *  shared across the loopback processes, so we return null and the caller
     *  uses the DB proxy instead. Last-write-wins races are acceptable for a
     *  dispatch heuristic (same tolerance as the preload governor).
     * ─────────────────────────────────────────────── */

    const HEARTBEAT_TTL = 45; // seconds; > RUNNER_WINDOW_SECONDS (20).

    private static function heartbeat_available() {
        return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
    }

    private static function heartbeat_key( $group, $callback ) {
        return 'rhb_' . md5( $group . '|' . $callback );
    }

    /** Read the registry, drop expired entries, persist the pruned map, and
     *  return the live count. Returns null when heartbeats are unavailable. */
    private static function heartbeat_count( $group, $callback ) {
        if ( ! self::heartbeat_available() ) {
            return null;
        }
        $key = self::heartbeat_key( $group, $callback );
        $map = wp_cache_get( $key, 'easyopt' );
        if ( ! is_array( $map ) || empty( $map ) ) {
            return 0;
        }
        $now   = time();
        $live  = array();
        foreach ( $map as $id => $exp ) {
            if ( (int) $exp > $now ) {
                $live[ $id ] = (int) $exp;
            }
        }
        if ( count( $live ) !== count( $map ) ) {
            wp_cache_set( $key, $live, 'easyopt', self::HEARTBEAT_TTL * 2 );
        }
        return count( $live );
    }

    /** Register this runner and return its id (empty string when unavailable). */
    private static function heartbeat_register( $group, $callback ) {
        if ( ! self::heartbeat_available() ) {
            return '';
        }
        $key = self::heartbeat_key( $group, $callback );
        $id  = ( function_exists( 'getmypid' ) ? (string) getmypid() : '' ) . '-' . wp_generate_password( 8, false );
        $map = wp_cache_get( $key, 'easyopt' );
        $map = is_array( $map ) ? $map : array();
        $now = time();
        // Prune expired while we're here.
        foreach ( $map as $k => $exp ) {
            if ( (int) $exp <= $now ) {
                unset( $map[ $k ] );
            }
        }
        $map[ $id ] = $now + self::HEARTBEAT_TTL;
        wp_cache_set( $key, $map, 'easyopt', self::HEARTBEAT_TTL * 2 );
        return $id;
    }

    /** Refresh this runner's expiry (called each loop iteration). */
    private static function heartbeat_refresh( $group, $callback, $id ) {
        if ( '' === $id || ! self::heartbeat_available() ) {
            return;
        }
        $key = self::heartbeat_key( $group, $callback );
        $map = wp_cache_get( $key, 'easyopt' );
        $map = is_array( $map ) ? $map : array();
        $map[ $id ] = time() + self::HEARTBEAT_TTL;
        wp_cache_set( $key, $map, 'easyopt', self::HEARTBEAT_TTL * 2 );
    }

    /** Remove this runner from the registry on clean exit. */
    private static function heartbeat_unregister( $group, $callback, $id ) {
        if ( '' === $id || ! self::heartbeat_available() ) {
            return;
        }
        $key = self::heartbeat_key( $group, $callback );
        $map = wp_cache_get( $key, 'easyopt' );
        if ( is_array( $map ) && isset( $map[ $id ] ) ) {
            unset( $map[ $id ] );
            wp_cache_set( $key, $map, 'easyopt', self::HEARTBEAT_TTL * 2 );
        }
    }

    private static function get_claimable_pending_count( $group, $callback ) {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE group_name = %s
               AND callback_action = %s
               AND status = 'pending'
               AND (locked_at IS NULL OR locked_at <= UTC_TIMESTAMP())",
            $group,
            $callback
        ) );
    }

    /* ───────────────────────────────────────────────
     *  Auth + retries + concurrency registry
     * ─────────────────────────────────────────────── */

    private static function build_runner_token( $group, $callback ) {
        // wp_salt('auth') ties the token to the site instance. Anyone
        // observing the REST endpoint can't forge a token unless they
        // already have wp-config.php access. The token is constant for
        // a given (group, callback) pair so the watchdog doesn't need
        // to coordinate with the dispatcher about ephemeral nonces.
        return hash_hmac( 'sha256', $group . '|' . $callback, wp_salt( 'auth' ) );
    }

    private static function get_max_retries( $group, $callback ) {
        $key = self::queue_config_key( $group, $callback );
        return max( 0, self::$queue_max_retries[ $key ] ?? self::DEFAULT_MAX_RETRIES );
    }

    private static function get_concurrency( $group, $callback ) {
        $key = self::queue_config_key( $group, $callback );
        $c   = self::$queue_concurrency[ $key ] ?? self::DEFAULT_CONCURRENCY;
        /**
         * Allow a queue group to drive its own concurrency dynamically (the
         * preload governor uses this for adaptive AIMD tuning). Still clamped
         * to [1, MAX_CONCURRENCY] below, so a filter can never exceed the cap.
         */
        $c = (int) apply_filters( 'easyopt_queue_concurrency', $c, $group, $callback );
        return min( self::MAX_CONCURRENCY, max( 1, (int) $c ) );
    }

    private static function get_retry_backoff_seconds( $attempts ) {
        $idx     = max( 0, (int) $attempts - 1 );
        $seconds = (int) round( self::RETRY_BACKOFF_BASE_SECONDS * pow( 2, $idx ) );
        return min( self::RETRY_BACKOFF_MAX_SECONDS, max( 1, $seconds ) );
    }

    private static function set_max_retries( $group, $callback, $max_retries ) {
        $key = self::queue_config_key( $group, $callback );
        self::$queue_max_retries[ $key ] = max( 0, (int) $max_retries );
    }

    private static function set_concurrency( $group, $callback, $concurrency ) {
        $key = self::queue_config_key( $group, $callback );
        self::$queue_concurrency[ $key ] = min( self::MAX_CONCURRENCY, max( 1, (int) $concurrency ) );
    }

    private static function queue_config_key( $group, $callback ) {
        return $group . '|' . $callback;
    }

    /* ───────────────────────────────────────────────
     *  Task hashing (for dedup)
     * ─────────────────────────────────────────────── */

    private function normalize_task_data( $task_data ) {
        if ( ! is_array( $task_data ) ) {
            return array( $task_data );
        }
        return $this->is_assoc( $task_data ) ? $task_data : array_values( $task_data );
    }

    private function build_task_hash( $task_data ) {
        // Canonicalise so {a:1,b:2} and {b:2,a:1} produce the same
        // hash — otherwise a re-add of the same logical task would
        // create a duplicate row when key order shifts.
        return hash(
            'sha256',
            $this->group_name . '|' . wp_json_encode( $this->canonicalize_for_hash( $task_data ) )
        );
    }

    private function is_assoc( $arr ) {
        return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
    }

    private function canonicalize_for_hash( $data ) {
        if ( ! is_array( $data ) ) {
            return $data;
        }
        foreach ( $data as $k => $v ) {
            // (2.5.4 / perf #13) Underscore-prefixed keys are ADVISORY
            // metadata (runner-injected _task_id/_lock_token were never
            // stored; payload hints like _post_id ride along to save work
            // downstream). They must not change a task's identity, or the
            // same URL enqueued with and without the hint would dedupe into
            // two rows and warm twice.
            if ( is_string( $k ) && '' !== $k && '_' === $k[0] ) {
                unset( $data[ $k ] );
                continue;
            }
            $data[ $k ] = $this->canonicalize_for_hash( $v );
        }
        if ( $this->is_assoc( $data ) ) {
            ksort( $data );
        }
        return $data;
    }

    /* ───────────────────────────────────────────────
     *  Upgrade-time cleanup (called from main plugin file)
     * ─────────────────────────────────────────────── */

    /**
     * Wipe pre-1.6.0 Action Scheduler rows for our former hooks.
     * Idempotent and tolerant of missing AS tables.
     *
     * We don't drop the actionscheduler_* tables outright because
     * other plugins (WooCommerce, ActionScheduler standalone) might
     * legitimately be using them.
     */
    public static function clear_legacy_action_scheduler_tasks() {
        global $wpdb;
        $actions_table = $wpdb->prefix . 'actionscheduler_actions';
        $exists        = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions_table ) ) === $actions_table;
        if ( ! $exists ) {
            return;
        }
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM $actions_table
             WHERE hook IN (%s, %s, %s, %s)",
            'easyopt/preload/build_queue',
            'easyopt/preload/warm_url',
            'easyopt/preload/finalize',
            'easyopt_cache_preload_tick' // pre-1.5.8 WP-Cron hook AS may have ingested
        ) );
    }
}