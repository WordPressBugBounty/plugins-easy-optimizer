<?php
/**
 * EasyOpt\Backend\Backend — Backend Analyzer orchestrator (v1, 2.4.0).
 *
 * On-demand backend profiling: the admin enters a URL, the plugin issues
 * token-authenticated loopback requests against it (page cache bypassed),
 * and the armed analyzers record what made the backend slow:
 *
 *   • Slow Callback Analyzer (Profiler)   — gap-based hook timing via the
 *     'all' action: which hooks/components consumed the TTFB, plus a
 *     lifecycle-phase breakdown (plugins_loaded → init → template).
 *   • Slow Query Analyzer (Query_Log)     — SAVEQUERIES armed for the
 *     profiled request only; queries over a threshold are fingerprinted
 *     (literals redacted — no PII ever persists), attributed to a
 *     component, and rule-checked for well-known anti-patterns.
 *
 * Design constraints (v1):
 *   • OFF by default; when the master toggle is off this file isn't even
 *     loaded (conditional module loading in easy-optimizer.php).
 *   • NOTHING passive: analyzers arm only on a request carrying a valid
 *     short-lived profile token. Organic traffic is never measured.
 *   • Storage: two small custom tables (queue-table pattern), 14-day
 *     retention + row caps enforced by the existing daily GC.
 *
 * First fully namespaced module — resolved by EasyOpt_Autoloader.
 *
 * @package EasyOptimizer
 * @since   2.4.0
 */

namespace EasyOpt\Backend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Backend {

    const SCHEMA_VERSION = 2;

    /** Profile token option (non-autoloaded). */
    const TOKEN_OPTION = 'easyopt_profile_token';

    /** Token lifetime — long enough for 3 sequential passes on a slow site. */
    const TOKEN_TTL = 300;

    /** Retention + caps enforced by gc(). */
    const RETENTION_DAYS = 14;
    const MAX_ROWS       = 5000;

    /** @var bool Whether THIS request is an armed profile run. */
    private static $armed = false;

    /* ───────────────────────────────────────────────
     *  Bootstrap
     * ─────────────────────────────────────────────── */

    public static function init() {

        // Table creation — version-gated, dbDelta-idempotent (queue pattern).
        add_action( 'plugins_loaded', array( __CLASS__, 'maybe_create_tables' ), 5 );

        // REST endpoints.
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

        // ── Arm the analyzers for THIS request? ──
        // Runs at plugin-load time (we're included during plugin loading, so
        // the 'all' hook still catches plugins_loaded → shutdown). Only a
        // request carrying a valid, unexpired profile token arms anything —
        // organic traffic always takes this branch's early return.
        if ( empty( $_GET['easyopt_profile'] ) ) {
            return;
        }
        if ( ! self::validate_token( isset( $_GET['eoptoken'] ) ? (string) $_GET['eoptoken'] : '' ) ) {
            return;
        }

        // Pass 1 is a WARM-UP: the request still executes fully (priming
        // opcache, object cache, generated assets like used.css / minified
        // files), but nothing is recorded. Only passes 2+ persist, so
        // cold-generation artifacts (e.g. a 2s used-CSS build) never land
        // in the results as if they were steady-state backend cost.
        $pass = isset( $_GET['eopass'] ) ? (int) $_GET['eopass'] : 0;
        if ( $pass <= 1 ) {
            return;
        }

        self::$armed = true;

        if ( (int) \EasyOpt_Config::get( 'backend_callbacks', 1 ) ) {
            Profiler::arm();
        }
        if ( (int) \EasyOpt_Config::get( 'backend_queries', 1 ) ) {
            Query_Log::arm();
        }
    }

    /** Whether the current request is an armed profile run. */
    public static function is_armed() {
        return self::$armed;
    }

    /* ───────────────────────────────────────────────
     *  Tables
     * ─────────────────────────────────────────────── */

    public static function callbacks_table() {
        global $wpdb;
        return $wpdb->prefix . 'easyopt_callbacks';
    }

    public static function queries_table() {
        global $wpdb;
        return $wpdb->prefix . 'easyopt_queries';
    }

    public static function maybe_create_tables() {
        // (2.5.4 / perf #26) Shared autoloaded fast path (see EasyOpt_Config
        // schema registry); legacy option remains authoritative below.
        if ( class_exists( 'EasyOpt_Config' ) && method_exists( 'EasyOpt_Config', 'schema_version' )
            && \EasyOpt_Config::schema_version( 'backend' ) === (string) self::SCHEMA_VERSION ) {
            return;
        }
        if ( self::SCHEMA_VERSION === (int) get_option( 'easyopt_backend_schema_version', 0 ) ) {
            if ( class_exists( 'EasyOpt_Config' ) && method_exists( 'EasyOpt_Config', 'set_schema_version' ) ) {
                \EasyOpt_Config::set_schema_version( 'backend', (string) self::SCHEMA_VERSION );
            }
            return;
        }

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $cb      = self::callbacks_table();
        $q       = self::queries_table();

        $sql_cb = "CREATE TABLE $cb (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            captured_at DATETIME NOT NULL,
            context VARCHAR(16) NOT NULL DEFAULT 'frontend',
            url_hash CHAR(32) NOT NULL,
            url TEXT NOT NULL,
            hook VARCHAR(191) NOT NULL,
            component VARCHAR(64) NOT NULL DEFAULT '',
            calls INT UNSIGNED NOT NULL DEFAULT 0,
            total_ms DECIMAL(10,2) NOT NULL DEFAULT 0,
            max_ms DECIMAL(10,2) NOT NULL DEFAULT 0,
            mem_kb INT NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_url_hook_comp (url_hash, hook, component),
            KEY idx_slow (total_ms),
            KEY idx_gc (captured_at)
        ) $charset;";

        $sql_q = "CREATE TABLE $q (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            fingerprint CHAR(32) NOT NULL,
            sample_sql TEXT NOT NULL,
            tables_used VARCHAR(191) NOT NULL DEFAULT '',
            caller VARCHAR(191) NOT NULL DEFAULT '',
            component VARCHAR(64) NOT NULL DEFAULT '',
            context VARCHAR(16) NOT NULL DEFAULT 'frontend',
            hits INT UNSIGNED NOT NULL DEFAULT 0,
            total_ms DECIMAL(12,2) NOT NULL DEFAULT 0,
            max_ms DECIMAL(10,2) NOT NULL DEFAULT 0,
            findings TEXT NULL,
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_fp (fingerprint),
            KEY idx_cost (total_ms),
            KEY idx_gc (last_seen)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        // The callbacks table holds only ephemeral profiling output (regenerated
        // on every test) and its unique key changed in schema v2 — dbDelta can't
        // alter a unique key in place, so drop and recreate it. No user data is
        // lost. The queries table is left to dbDelta as usual.
        // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
        $wpdb->query( "DROP TABLE IF EXISTS $cb" );
        dbDelta( $sql_cb );
        dbDelta( $sql_q );

        update_option( 'easyopt_backend_schema_version', self::SCHEMA_VERSION, false );
        if ( class_exists( 'EasyOpt_Config' ) && method_exists( 'EasyOpt_Config', 'set_schema_version' ) ) {
            \EasyOpt_Config::set_schema_version( 'backend', (string) self::SCHEMA_VERSION ); // (2.5.4 / perf #26)
        }
    }

    /* ───────────────────────────────────────────────
     *  Profile token (probe-key pattern: short-lived, HMAC-grade random)
     * ─────────────────────────────────────────────── */

    private static function issue_token() {
        $token = wp_generate_password( 32, false, false );
        update_option( self::TOKEN_OPTION, array(
            'token'   => $token,
            'expires' => time() + self::TOKEN_TTL,
        ), false );
        return $token;
    }

    private static function validate_token( $token ) {
        if ( '' === $token ) {
            return false;
        }
        $stored = get_option( self::TOKEN_OPTION, array() );
        if ( ! is_array( $stored ) || empty( $stored['token'] ) || empty( $stored['expires'] ) ) {
            return false;
        }
        if ( time() > (int) $stored['expires'] ) {
            return false;
        }
        return hash_equals( (string) $stored['token'], $token );
    }

    /* ───────────────────────────────────────────────
     *  REST
     * ─────────────────────────────────────────────── */

    public static function register_routes() {
        $perm = function () {
            return current_user_can( 'manage_options' );
        };
        register_rest_route( 'easyopt/v1', '/backend/profile', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_profile' ),
            'permission_callback' => $perm,
        ) );
        register_rest_route( 'easyopt/v1', '/backend/token', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_token' ),
            'permission_callback' => $perm,
        ) );
        register_rest_route( 'easyopt/v1', '/backend/callbacks', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_callbacks' ),
            'permission_callback' => $perm,
        ) );
        register_rest_route( 'easyopt/v1', '/backend/queries', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_queries' ),
            'permission_callback' => $perm,
        ) );
        register_rest_route( 'easyopt/v1', '/backend/purge', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_purge' ),
            'permission_callback' => $perm,
        ) );
    }

    /**
     * Issue a fresh profile token for the BROWSER-driven admin profiling
     * flow (v1.1): /wp-admin/ URLs are loaded by the dashboard in a hidden
     * same-origin iframe with this token appended. The admin's browser
     * carries their session cookies natively, so authentication works on
     * every host — unlike server loopbacks, where hosts and security
     * plugins routinely strip cookies or block internal HTTP entirely.
     */
    public static function rest_token() {
        return rest_ensure_response( array(
            'token'   => self::issue_token(),
            'expires' => self::TOKEN_TTL,
        ) );
    }

    /**
     * Run ONE profiling pass against a same-host URL. The React UI calls
     * this 3× sequentially ("Pass 1/3 …") — one loopback per REST call keeps
     * each request comfortably inside PHP/REST execution limits on slow
     * sites, and the upsert aggregation (LEAST of totals across passes)
     * kills first-hit/opcache noise the way a median would.
     */
    public static function rest_profile( $request ) {
        $url  = esc_url_raw( (string) $request->get_param( 'url' ) );
        $pass = max( 1, (int) $request->get_param( 'pass' ) );

        if ( '' === $url ) {
            $url = home_url( '/' );
        }

        // Same-host only — never profile (or hammer) someone else's site.
        $req_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        if ( '' === $req_host || $req_host !== $home_host ) {
            return new \WP_Error( 'easyopt_bad_url', __( 'URL must be on this site.', 'easy-optimizer' ), array( 'status' => 400 ) );
        }

        $token = self::issue_token();

        // easyopt_profile/eoptoken are real (non-stripped) query params, so
        // BOTH cache serve paths MISS and the cache writer skips the page —
        // the profiled request runs full PHP and never pollutes the cache.
        $probe = add_query_arg(
            array(
                'easyopt_profile' => '1',
                'eoptoken'        => rawurlencode( $token ),
                'eopass'          => $pass,
            ),
            $url
        );

        // /wp-admin/ profiling is BROWSER-driven (hidden same-origin iframe
        // with a token — see rest_token()), never a server loopback: cookie
        // forwarding dies on most hosts (stripped by WAFs/security plugins),
        // so the old approach redirected to login almost everywhere.
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        if ( false !== strpos( $path, '/wp-admin' ) ) {
            return new \WP_Error( 'easyopt_admin_browser_flow', __( 'Admin URLs are profiled through your browser session — use the Profile button in the dashboard.', 'easy-optimizer' ), array( 'status' => 400 ) );
        }

        $args = array(
            'timeout'     => 45,
            'redirection' => 1,
            'sslverify'   => false,
            'blocking'    => true,
            'headers'     => array( 'Cache-Control' => 'no-cache' ),
        );

        $started = microtime( true );
        $resp    = wp_remote_get( $probe, $args );
        $elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );

        if ( is_wp_error( $resp ) ) {
            return new \WP_Error( 'easyopt_loopback_failed', sprintf(
                /* translators: %s error message */
                __( 'Loopback request failed: %s. Your host may block internal HTTP requests.', 'easy-optimizer' ),
                $resp->get_error_message()
            ), array( 'status' => 502 ) );
        }

        $status = (int) wp_remote_retrieve_response_code( $resp );

        return rest_ensure_response( array(
            'ok'         => true,
            'pass'       => $pass,
            'status'     => $status,
            'elapsed_ms' => $elapsed,
            'url'        => $url,
            'url_hash'   => md5( self::normalize_url( $url ) ),
        ) );
    }

    /** Canonical URL form shared by the writer (Profiler) and the reader. */
    public static function normalize_url( $url ) {
        $p    = wp_parse_url( $url );
        $host = isset( $p['host'] ) ? strtolower( $p['host'] ) : '';
        $path = isset( $p['path'] ) && '' !== $p['path'] ? $p['path'] : '/';
        return $host . untrailingslashit( $path ) . '/';
    }

    public static function rest_callbacks( $request ) {
        global $wpdb;
        $table = self::callbacks_table();

        $url_hash = sanitize_text_field( (string) $request->get_param( 'url_hash' ) );
        $where    = '';
        $args     = array();
        if ( '' !== $url_hash ) {
            $where  = 'WHERE url_hash = %s';
            $args[] = $url_hash;
        }

        $sql = "SELECT * FROM $table $where ORDER BY total_ms DESC LIMIT 200";
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );

        // Profiled-URL index for the UI's URL picker.
        $urls = $wpdb->get_results(
            "SELECT url_hash, MIN(url) AS url, MAX(captured_at) AS captured_at,
                    SUM(CASE WHEN hook NOT LIKE '\\_phase:%' THEN total_ms ELSE 0 END) AS sum_ms
             FROM $table GROUP BY url_hash ORDER BY captured_at DESC LIMIT 50",
            ARRAY_A
        );

        // Grouped by plugin / theme (Option A): one row per component with its
        // total time across every hook it touched, so "plugin X did 6 things"
        // collapses into a single line. Phase markers (_phase:*) are excluded.
        $grp_where = "WHERE hook NOT LIKE '\\_phase:%'";
        $grp_args  = array();
        if ( '' !== $url_hash ) {
            $grp_where .= ' AND url_hash = %s';
            $grp_args[] = $url_hash;
        }
        $grp_sql = "SELECT component,
                           SUM(total_ms) AS total_ms,
                           SUM(calls)    AS calls,
                           MAX(max_ms)   AS max_ms,
                           SUM(mem_kb)   AS mem_kb,
                           COUNT(*)      AS steps
                    FROM $table $grp_where
                    GROUP BY component
                    ORDER BY total_ms DESC
                    LIMIT 100";
        // phpcs:ignore WordPress.DB.PreparedSQL
        $by_component = $grp_args ? $wpdb->get_results( $wpdb->prepare( $grp_sql, $grp_args ), ARRAY_A ) : $wpdb->get_results( $grp_sql, ARRAY_A );

        return rest_ensure_response( array(
            'rows'         => is_array( $rows ) ? $rows : array(),
            'by_component' => is_array( $by_component ) ? $by_component : array(),
            'urls'         => is_array( $urls ) ? $urls : array(),
        ) );
    }

    public static function rest_queries() {
        global $wpdb;
        $table = self::queries_table();
        $rows  = $wpdb->get_results( "SELECT * FROM $table ORDER BY total_ms DESC LIMIT 200", ARRAY_A );
        if ( is_array( $rows ) ) {
            foreach ( $rows as &$row ) {
                $row['findings'] = ! empty( $row['findings'] ) ? json_decode( (string) $row['findings'], true ) : array();
            }
            unset( $row );
        }
        return rest_ensure_response( array( 'rows' => is_array( $rows ) ? $rows : array() ) );
    }

    public static function rest_purge( $request ) {
        global $wpdb;
        $what = sanitize_key( (string) $request->get_param( 'what' ) );
        if ( 'callbacks' === $what || 'all' === $what || '' === $what ) {
            $wpdb->query( 'TRUNCATE TABLE ' . self::callbacks_table() ); // phpcs:ignore
        }
        if ( 'queries' === $what || 'all' === $what || '' === $what ) {
            $wpdb->query( 'TRUNCATE TABLE ' . self::queries_table() ); // phpcs:ignore
        }
        return rest_ensure_response( array( 'ok' => true ) );
    }

    /* ───────────────────────────────────────────────
     *  GC — invoked from EasyOpt_Queue::run_gc (daily)
     * ─────────────────────────────────────────────── */

    public static function gc() {
        global $wpdb;
        $cb  = self::callbacks_table();
        $q   = self::queries_table();
        $cut = gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS );

        // Tables may not exist yet (feature enabled but never used) — the
        // DELETEs just no-op with a suppressed error in that case.
        $suppress = $wpdb->suppress_errors( true );
        $wpdb->query( $wpdb->prepare( "DELETE FROM $cb WHERE captured_at < %s", $cut ) ); // phpcs:ignore
        $wpdb->query( $wpdb->prepare( "DELETE FROM $q  WHERE last_seen   < %s", $cut ) ); // phpcs:ignore

        // Row caps — evict cheapest-cost rows first.
        foreach ( array( $cb => 'total_ms', $q => 'total_ms' ) as $table => $cost ) {
            $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore
            if ( $count > self::MAX_ROWS ) {
                $excess = $count - self::MAX_ROWS;
                $wpdb->query( $wpdb->prepare( "DELETE FROM $table ORDER BY $cost ASC LIMIT %d", $excess ) ); // phpcs:ignore
            }
        }
        $wpdb->suppress_errors( $suppress );
    }

    /* ───────────────────────────────────────────────
     *  Shared component attribution (file path → plugin/theme/core)
     * ─────────────────────────────────────────────── */

    /**
     * Classify an absolute file path into a component label.
     *
     * @param string $file Absolute path (may be '').
     * @return string 'plugin:{slug}' | 'theme:{slug}' | 'core' | ''
     */
    public static function component_from_file( $file ) {
        if ( ! is_string( $file ) || '' === $file ) {
            return '';
        }
        $file = str_replace( '\\', '/', $file );

        if ( false !== ( $pos = strpos( $file, '/plugins/' ) ) ) {
            $rest = substr( $file, $pos + 9 );
            $slug = strtok( $rest, '/' );
            return 'plugin:' . sanitize_key( (string) $slug );
        }
        if ( false !== ( $pos = strpos( $file, '/mu-plugins/' ) ) ) {
            $rest = substr( $file, $pos + 12 );
            $slug = strtok( $rest, '/' );
            return 'plugin:' . sanitize_key( (string) pathinfo( (string) $slug, PATHINFO_FILENAME ) );
        }
        if ( false !== ( $pos = strpos( $file, '/themes/' ) ) ) {
            $rest = substr( $file, $pos + 8 );
            $slug = strtok( $rest, '/' );
            return 'theme:' . sanitize_key( (string) $slug );
        }
        return 'core';
    }

    /**
     * Resolve a callable to its defining file via reflection (best-effort).
     *
     * @param callable|mixed $cb
     * @return string Absolute file path or ''.
     */
    public static function file_of_callable( $cb ) {
        try {
            if ( is_string( $cb ) && function_exists( $cb ) ) {
                $r = new \ReflectionFunction( $cb );
                return (string) $r->getFileName();
            }
            if ( $cb instanceof \Closure ) {
                $r = new \ReflectionFunction( $cb );
                return (string) $r->getFileName();
            }
            if ( is_array( $cb ) && isset( $cb[0], $cb[1] ) ) {
                $r = new \ReflectionMethod( is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0], (string) $cb[1] );
                return (string) $r->getFileName();
            }
            if ( is_object( $cb ) && method_exists( $cb, '__invoke' ) ) {
                $r = new \ReflectionMethod( $cb, '__invoke' );
                return (string) $r->getFileName();
            }
        } catch ( \Throwable $e ) { // phpcs:ignore
            // Unresolvable callable — fall through.
        }
        return '';
    }
}
