<?php
/**
 * Server-level cache detection and purging.
 *
 * Detects active caching layers across hosting providers and purges
 * them when EasyOptimizer clears its own page cache.
 *
 * Design principles:
 *   1. CONDITIONAL   — only purges layers that are actually detected.
 *   2. RATE-LIMITED  — once per PHP request, no matter how many hooks fire.
 *   3. NON-BLOCKING  — external HTTP purge requests use fire-and-forget.
 *   4. SAFE          — every handler wrapped in try/catch; failures logged, never fatal.
 *   5. LIGHTWEIGHT   — per-URL purges (save_post path) are fast O(1) operations.
 *                      Full purge only runs on explicit admin clear or global site events.
 *   6. CPU-SAFE      — save_post does NOT trigger full server purge.
 *
 * Hook architecture:
 *   easyopt_cache_cleared_all  →  purge_all()   full server cache purge
 *   easyopt_cache_cleared_url  →  purge_url()   lightweight per-URL purge only
 *
 *   save_post fires clear_url() → easyopt_cache_cleared_url → lightweight purge.
 *   Explicit "Clear All Cache" fires clear_all() → easyopt_cache_cleared_all → full purge.
 *
 * Supported hosts:
 *   Kinsta · WP Engine · SiteGround · Cloudways · GridPane · RunCloud
 *   SpinupWP · Rocket.net · WordPress.com (Atomic) · Pantheon · Flywheel
 *   Pressable · Closte · Convesio
 *
 * Supported generic cache layers:
 *   Varnish (HTTP PURGE) · NGINX FastCGI (Nginx Helper)
 *   LiteSpeed Cache (per-URL only, via plugin API)
 *
 * @package EasyOptimizer
 * @since   2.0.0
 * @since   2.2.0  Rewritten: auto-detection engine, Varnish/NGINX direct purge,
 *                  direct purge, Pantheon/Flywheel/Pressable support, rate limiting,
 *                  non-blocking HTTP, per-request idempotency, full safety layer.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Hosting {

    /* ================================================================
     *  STATE
     * ================================================================ */

    /** @var bool  Full purge already executed this request. */
    private static $purged_all = false;

    /** @var array<string,bool>  Per-URL purge guard keyed by URL. */
    private static $purged_urls = array();

    /** @var array<string,array{label:string,detected:bool}>|null  Cached detection. */
    private static $layers = null;

    /** Max timeout for any single external purge HTTP call (seconds). */
    const PURGE_TIMEOUT = 2;

    /** Max per-URL purges per request (prevents runaway loops). */
    const MAX_URL_PURGES = 20;

    /* ================================================================
     *  INIT
     * ================================================================ */

    public static function init() {

        // Full server cache purge — fires on explicit admin clear,
        // theme switch, customizer save, and similar INFREQUENT events.
        // Does NOT fire on save_post (which only fires easyopt_cache_cleared_url).
        add_action( 'easyopt_cache_cleared_all', array( __CLASS__, 'purge_all' ) );

        // Lightweight per-URL purge — fires on save_post via clear_url().
        // Only clears the specific URL from server caches that support it.
        // No full flushes, no object cache flush, no OPcache reset.
        add_action( 'easyopt_cache_cleared_url', array( __CLASS__, 'purge_url' ), 10, 1 );

        // (2.6.0) Upstream-only invalidation: our cache entry is fine, but a
        // host/CDN layer above us is holding a stale copy of this URL. Same
        // handler, different trigger — this one carries no cache-state or
        // counter side-effects.
        add_action( 'easyopt_purge_upstream_url', array( __CLASS__, 'purge_url' ), 10, 1 );

        // REST endpoint for dashboard.
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
    }

    /**
     * Register REST route for dashboard status card.
     */
    public static function register_rest_routes() {
        register_rest_route( 'easyopt/v1', '/server-cache-status', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_status' ),
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
        ) );
    }

    /**
     * REST: return detected server cache layers for the dashboard.
     */
    public static function rest_status() {
        return rest_ensure_response( self::get_status() );
    }

    /* ================================================================
     *  DETECTION ENGINE
     * ================================================================ */

    /**
     * Detect all server-side caching layers.
     *
     * Results are cached for the lifetime of the request. Each layer
     * entry includes a human label and whether it was detected.
     *
     * @return array<string,array{label:string,detected:bool}>
     */
    public static function detect() {

        if ( null !== self::$layers ) {
            return self::$layers;
        }

        $checks = array(
            // ── Managed hosting ──
            'kinsta'       => array( 'label' => 'Kinsta (Nginx + CDN)',      'test' => 'is_kinsta' ),
            'wpengine'     => array( 'label' => 'WP Engine (Varnish)',       'test' => 'is_wpengine' ),
            'siteground'   => array( 'label' => 'SiteGround (SuperCacher)', 'test' => 'is_siteground' ),
            'cloudways'    => array( 'label' => 'Cloudways (Varnish)',       'test' => 'is_cloudways' ),
            'gridpane'     => array( 'label' => 'GridPane (Nginx)',          'test' => 'is_gridpane' ),
            'runcloud'     => array( 'label' => 'RunCloud (Nginx + Redis)', 'test' => 'is_runcloud' ),
            'spinupwp'     => array( 'label' => 'SpinupWP (Nginx)',          'test' => 'is_spinupwp' ),
            'rocketnet'    => array( 'label' => 'Rocket.net (CDN)',          'test' => 'is_rocketnet' ),
            'wpcloud'      => array( 'label' => 'WordPress.com (Edge)',      'test' => 'is_wpcloud' ),
            'pantheon'     => array( 'label' => 'Pantheon (Edge + Varnish)', 'test' => 'is_pantheon' ),
            'flywheel'     => array( 'label' => 'Flywheel',                  'test' => 'is_flywheel' ),
            'pressable'    => array( 'label' => 'Pressable (Batcache)',      'test' => 'is_pressable' ),
            'closte'       => array( 'label' => 'Closte',                    'test' => 'is_closte' ),
            'convesio'     => array( 'label' => 'Convesio',                  'test' => 'is_convesio' ),
            'godaddy'      => array( 'label' => 'GoDaddy (Varnish + CDN)',   'test' => 'is_godaddy' ),

            // ── Generic cache layers ──
            'varnish'      => array( 'label' => 'Varnish HTTP Cache',        'test' => 'has_varnish' ),
            'nginx_helper' => array( 'label' => 'Nginx Helper (FastCGI)',    'test' => 'has_nginx_helper' ),
        );

        self::$layers = array();

        foreach ( $checks as $key => $info ) {
            $detected = false;
            try {
                $method   = $info['test'];
                $detected = self::$method();
            } catch ( \Throwable $e ) {
                // Detection should never fatal.
            }
            self::$layers[ $key ] = array(
                'label'    => $info['label'],
                'detected' => (bool) $detected,
            );
        }

        /**
         * Filter the detected cache layers. Third-party code can add/remove
         * layers or override detection for custom hosting environments.
         *
         * @param array<string,array{label:string,detected:bool}> $layers
         */
        self::$layers = (array) apply_filters( 'easyopt_detected_cache_layers', self::$layers );

        return self::$layers;
    }

    /**
     * Return only the keys of layers detected as active.
     *
     * @return string[]
     */
    public static function active_layers() {
        $all = self::detect();
        return array_keys( array_filter( $all, function ( $l ) {
            return ! empty( $l['detected'] );
        } ) );
    }

    /**
     * True when the site runs on the LiteSpeed web server (LSWS / OpenLiteSpeed).
     *
     * This is the SERVER, not the LiteSpeed Cache plugin — the two are
     * independent. LiteSpeed advertises itself in SERVER_SOFTWARE, and exposes
     * an X-LSCACHE capability header on LSWS builds with the cache module.
     *
     * @since 2.6.5
     * @return bool
     */
    public static function is_litespeed_server() {
        if ( isset( $_SERVER['SERVER_SOFTWARE'] )
            && false !== stripos( (string) wp_unslash( $_SERVER['SERVER_SOFTWARE'] ), 'litespeed' ) ) {
            return true;
        }
        // LSWS with the cache module sets this request header.
        return isset( $_SERVER['HTTP_X_LSCACHE'] ) && '' !== (string) $_SERVER['HTTP_X_LSCACHE'];
    }

    /**
     * A friendly name for a server/stack Easy Optimizer is known to run cleanly
     * alongside — used for the wizard's "Fully compatible with X" reassurance.
     *
     * Returns '' when the site is on none of them, so the caller can decide
     * whether to show the line at all.
     *
     * @since 2.6.5
     * @return string 'LiteSpeed' | 'Cloudways' | 'SiteGround' | ''
     */
    public static function compatible_server_label() {
        if ( self::is_litespeed_server() ) {
            return 'LiteSpeed';
        }
        if ( self::is_cloudways() ) {
            return 'Cloudways';
        }
        if ( self::is_siteground() ) {
            return 'SiteGround';
        }
        return '';
    }

    /**
     * Admin-friendly status array for dashboard display.
     *
     * @return array{layers:array,count:int}
     */
    public static function get_status() {
        $cached = get_transient( 'easyopt_hosting_status' );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $all    = self::detect();
        $active = array();

        foreach ( $all as $key => $info ) {
            if ( $info['detected'] ) {
                $active[] = array(
                    'key'   => $key,
                    'label' => $info['label'],
                );
            }
        }

        $status = array(
            'layers' => $active,
            'count'  => count( $active ),
        );

        // The host environment is static between requests, but detect() runs
        // filesystem/constant probes every call. Cache it so /dashboard-stats
        // doesn't re-probe on every poll — important on cheap shared hosting
        // where overlapping polls already strain the worker pool.
        set_transient( 'easyopt_hosting_status', $status, HOUR_IN_SECONDS );

        return $status;
    }

    /* ----------------------------------------------------------------
     *  Detection helpers — one per layer
     * --------------------------------------------------------------- */

    // ── Managed Hosting ──────────────────────────────────────────

    private static function is_kinsta() {
        return ! empty( $GLOBALS['kinsta_cache'] )
            || defined( 'KINSTA_CACHE_ZONE' )
            || ( isset( $_SERVER['KINSTA_CACHE_ZONE'] ) && '' !== $_SERVER['KINSTA_CACHE_ZONE'] );
    }

    private static function is_wpengine() {
        return class_exists( 'WpeCommon' )
            || defined( 'WPE_APIKEY' )
            || ( function_exists( 'is_wpe' ) && is_wpe() );
    }

    private static function is_siteground() {
        return function_exists( 'sg_cachepress_purge_everything' )
            || class_exists( 'SiteGround_Optimizer\\Supercacher\\Supercacher' );
    }

    /**
     * True if $path is safe to probe with file_exists()/is_dir() without
     * tripping an open_basedir warning (skips disallowed paths on restricted
     * hosts so PHP/Query Monitor stay quiet).
     */
    private static function fs_probe_ok( $path ) {
        $obd = ini_get( 'open_basedir' );
        if ( empty( $obd ) ) {
            return true;
        }
        foreach ( explode( PATH_SEPARATOR, $obd ) as $base ) {
            $base = rtrim( trim( $base ), '/' );
            if ( '' !== $base && 0 === strpos( $path, $base ) ) {
                return true;
            }
        }
        return false;
    }

    private static function is_cloudways() {
        // cw_allowed_ip is set by the Cloudways platform.
        if ( ! empty( $_SERVER['cw_allowed_ip'] ) ) {
            return true;
        }
        // Breeze is Cloudways' default cache plugin.
        if ( class_exists( 'Breeze_PurgeVarnish' ) || class_exists( 'Breeze_PurgeCache' ) ) {
            return true;
        }
        // Cloudways uses /home/master/applications on their stack.
        if ( self::fs_probe_ok( '/home/master/applications' ) && @is_dir( '/home/master/applications' ) ) {
            return true;
        }
        return false;
    }

    private static function is_gridpane() {
        return class_exists( 'Jeep_Developers_Developer_Jeep' )
            || class_exists( 'Jeep_Developer' )
            || class_exists( 'Nginx_Cache_Purger_Admin' );
    }

    private static function is_runcloud() {
        return class_exists( 'RunCloud_Hub' );
    }

    private static function is_spinupwp() {
        return function_exists( 'spinupwp_purge_site' );
    }

    private static function is_rocketnet() {
        return class_exists( 'CDN_Clear_Cache_Hooks' );
    }

    private static function is_wpcloud() {
        return class_exists( 'Atomic_Persistent_Data' );
    }

    private static function is_pantheon() {
        return defined( 'PANTHEON_ENVIRONMENT' )
            || function_exists( 'pantheon_wp_clear_edge_all' );
    }

    private static function is_flywheel() {
        return defined( 'FLYWHEEL_CONFIG_DIR' )
            || ( isset( $_SERVER['SERVER_SOFTWARE'] ) && false !== stripos( (string) wp_unslash( $_SERVER['SERVER_SOFTWARE'] ), 'flywheel' ) );
    }

    private static function is_pressable() {
        return defined( 'IS_PRESSABLE' ) && IS_PRESSABLE;
    }

    private static function is_closte() {
        return function_exists( 'closte_purge_cache' );
    }

    /**
     * GoDaddy Managed WordPress (WPaaS).
     *
     * (2.6.0) The platform's mu-plugin exposes its cache controller as
     * $GLOBALS['wpaas_cache_class'] (WPaaS\Cache_V2). Detect on the object
     * itself rather than the WPAAS_* env vars — the object is what we
     * actually need to call, so its presence is the capability that matters,
     * and it survives the env differences between WPaaS v1 and v2.
     *
     * NOTE: the WPaaS\Cache wrapper class is deliberately NOT used. It is
     * marked @deprecated, emits E_USER_DEPRECATED on every call, and its
     * purge() drops the $urls argument entirely — calling it would flush more
     * than we asked for while filling the site's error log.
     */
    private static function is_godaddy() {
        if ( isset( $GLOBALS['wpaas_cache_class'] )
            && is_object( $GLOBALS['wpaas_cache_class'] )
            && method_exists( $GLOBALS['wpaas_cache_class'], 'purge' ) ) {
            return true;
        }
        // Platform markers, for the case where the cache class is absent but
        // we still want the host identified in diagnostics.
        return ( ! empty( $_SERVER['WPAAS_SITE_ID'] ) || false !== getenv( 'WPAAS_SITE_ID' ) || false !== getenv( 'WPAAS_V2_SITE_ID' ) );
    }

    private static function is_convesio() {
        return defined( 'STARTER_STARTER_CONVESIO' )
            || ( defined( 'STARTER_STARTER' ) && class_exists( 'Starter_Starter' ) && self::fs_probe_ok( '/convesio' ) && @is_dir( '/convesio' ) );
    }

    // ── Generic Cache Layers ─────────────────────────────────────

    /**
     * Detect standalone Varnish not already covered by a managed host.
     *
     * Conservative: only returns true when we have strong evidence via
     * a Varnish-aware plugin class. We do NOT blindly guess Varnish
     * from headers or process lists, because a false positive would
     * send PURGE requests to servers that don't understand them.
     *
     * Managed hosts that include Varnish (Cloudways, WP Engine, Pantheon)
     * handle it in their own handler — this is for VPS / custom setups.
     */
    private static function has_varnish() {
        // Skip if a managed host already handles Varnish.
        if ( self::is_cloudways() || self::is_wpengine() || self::is_pantheon() ) {
            return false;
        }
        // Varnish-aware plugins.
        if ( class_exists( 'Jeep_VarnishPurger' ) ) {
            return true;
        }
        // Proxy-Cache-Purge (the "Varnish HTTP Purge" plugin by DreamHost).
        if ( class_exists( 'VarnishPurger' ) || class_exists( 'ProxyCachePurge\\VarnishStatus' ) ) {
            return true;
        }
        // Filter for custom Varnish setups (user opts in).
        return (bool) apply_filters( 'easyopt_has_varnish', false );
    }

    private static function has_nginx_helper() {
        return function_exists( 'rt_nginx_helper_purge_all' )
            || class_exists( 'Jeep_Developers_Developer_Jeep' )
            || class_exists( 'Jeep_Developer' );
    }


    /* ================================================================
     *  PURGE ALL — full server cache purge
     * ================================================================
     *
     * Called on easyopt_cache_cleared_all which fires during:
     *   - Explicit "Clear All Cache" (admin bar, AJAX, REST)
     *   - Theme switch, customizer save (infrequent global events)
     *
     * NOT called on save_post (only easyopt_cache_cleared_url fires).
     * Rate-limited to one execution per PHP request.
     */

    public static function purge_all() {

        // ── Rate limit: once per request ──────────────────────────
        if ( self::$purged_all ) {
            return;
        }
        self::$purged_all = true;

        $layers = self::active_layers();
        if ( empty( $layers ) ) {
            self::log( 'debug', 'purge_all: no server cache layers detected.' );
            return;
        }

        self::log( 'info', 'purge_all: detected layers: ' . implode( ', ', $layers ) );

        $results = array();

        foreach ( $layers as $key ) {
            $method = 'do_purge_all_' . $key;
            if ( ! method_exists( __CLASS__, $method ) ) {
                continue;
            }

            $ok = false;
            try {
                $ok = self::$method();
                self::log(
                    $ok ? 'info' : 'warn',
                    sprintf( 'purge_all [%s]: %s', $key, $ok ? 'OK' : 'failed or skipped' )
                );
            } catch ( \Throwable $e ) {
                self::log( 'error', sprintf( 'purge_all [%s]: %s', $key, $e->getMessage() ) );
            }
            $results[ $key ] = $ok;
        }

        /**
         * Fires after all server cache layers have been purged.
         *
         * @param array<string,bool> $results  Key → success for each layer.
         * @param string[]           $layers   Active layer keys.
         */
        do_action( 'easyopt_server_cache_purged', $results, $layers );
    }


    /* ================================================================
     *  PURGE URL — lightweight per-URL purge
     * ================================================================
     *
     * Called on easyopt_cache_cleared_url (save_post, comment, LCP).
     * Only sends targeted URL invalidation. No full flushes, no object
     * cache flush, no OPcache reset. Each call is O(1), ~10ms max.
     */

    public static function purge_url( $url ) {

        if ( ! is_string( $url ) || '' === $url ) {
            return;
        }

        // ── Per-URL rate limit ────────────────────────────────────
        if ( isset( self::$purged_urls[ $url ] ) ) {
            return;
        }
        self::$purged_urls[ $url ] = true;

        // Cap total per-URL purges per request.
        if ( count( self::$purged_urls ) > self::MAX_URL_PURGES ) {
            return;
        }

        // ── Kinsta per-URL ────────────────────────────────────────
        if ( ! empty( $GLOBALS['kinsta_cache'] ) ) {
            $kc = $GLOBALS['kinsta_cache'];
            if ( isset( $kc->kinsta_cache_purge ) && method_exists( $kc->kinsta_cache_purge, 'purge_url' ) ) {
                try {
                    $kc->kinsta_cache_purge->purge_url( $url );
                } catch ( \Throwable $e ) { /* swallow */ }
            }
        }

        // ── Nginx Helper per-URL ──────────────────────────────────
        if ( function_exists( 'rt_nginx_helper_purge_url' ) ) {
            try {
                rt_nginx_helper_purge_url( $url );
            } catch ( \Throwable $e ) { /* swallow */ }
        }

        // ── LiteSpeed Cache per-URL ───────────────────────────────
        if ( class_exists( 'LiteSpeed\\Purge' ) && method_exists( 'LiteSpeed\\Purge', 'purge_url' ) ) {
            try {
                \LiteSpeed\Purge::purge_url( $url );
            } catch ( \Throwable $e ) { /* swallow */ }
        }

        // ── Pantheon per-URL ──────────────────────────────────────
        if ( function_exists( 'pantheon_wp_clear_edge_paths' ) ) {
            $path = wp_parse_url( $url, PHP_URL_PATH );
            if ( $path ) {
                try {
                    pantheon_wp_clear_edge_paths( array( $path ) );
                } catch ( \Throwable $e ) { /* swallow */ }
            }
        }

        // ── SpinupWP per-URL ──────────────────────────────────────
        if ( function_exists( 'spinupwp_purge_url' ) ) {
            try {
                spinupwp_purge_url( $url );
            } catch ( \Throwable $e ) { /* swallow */ }
        }

        // ── RunCloud per-URL ──────────────────────────────────────
        if ( class_exists( 'RunCloud_Hub' ) && method_exists( 'RunCloud_Hub', 'purge_cache_url' ) ) {
            try {
                \RunCloud_Hub::purge_cache_url( $url );
            } catch ( \Throwable $e ) { /* swallow */ }
        }

        // ── GoDaddy Managed WordPress (WPaaS) per-URL ─────────────
        // (2.6.0) Accumulates URLs and flushes them in ONE shutdown call.
        // Cache_V2::purge() refuses to run outside the shutdown action, and
        // caps at MAX_PURGE_URLS (20) — so batching is both required and the
        // only way to stay under the cap when several URLs change at once.
        if ( isset( $GLOBALS['wpaas_cache_class'] )
            && is_object( $GLOBALS['wpaas_cache_class'] )
            && method_exists( $GLOBALS['wpaas_cache_class'], 'purge' ) ) {
            self::queue_godaddy_url( $url );
        }

        // ── Varnish HTTP PURGE for specific URL (Cloudways or opted-in) ──
        if ( self::should_purge_varnish_url() ) {
            self::http_purge( $url, false );
        }
    }

    /** @var string[] URLs pending a GoDaddy per-URL purge at shutdown. */
    private static $gd_purge_urls = array();

    /**
     * Stage a URL for GoDaddy's per-URL purge and register the shutdown flush
     * once. Their cap is 20 URLs per call, so we bound the batch at that.
     *
     * @since 2.6.0
     * @param string $url
     */
    private static function queue_godaddy_url( $url ) {
        if ( in_array( $url, self::$gd_purge_urls, true ) ) {
            return;
        }
        if ( count( self::$gd_purge_urls ) >= 20 ) {
            return; // WPaaS\Cache_V2::MAX_PURGE_URLS
        }
        if ( empty( self::$gd_purge_urls ) ) {
            add_action( 'shutdown', array( __CLASS__, 'flush_godaddy_urls' ), 5 );
        }
        self::$gd_purge_urls[] = $url;
    }

    /**
     * Shutdown handler: hand the staged URLs to GoDaddy's cache controller.
     *
     * Public because it is an action callback. Runs at priority 5 so it lands
     * before the platform's own shutdown work.
     *
     * (2.6.0) On WPaaS v2, a per-URL purge does not work and never did.
     * WPaaS\Cache\V2_Manager::is_full_page_cache_enabled() is a hard-coded
     * `return true` (includes/cache/class-v2-manager.php), and Cache_V2's own
     * do_purge() consults that via should_switch_to_ban() BEFORE deciding what
     * to schedule — so on v2 it always escalates to a site-wide ban and its
     * purge() method is effectively dead code. Calling purge() directly, as we
     * did, bypassed that decision: the call returned true, the Varnish path
     * request went out, and the CDN carried on serving the stale page. Nothing
     * surfaced as an error, which is why this looked like it was working.
     *
     * So mirror the platform: escalate where the platform escalates, and keep
     * the targeted purge only where it genuinely applies (v1 without the CDN
     * full-page cache).
     *
     * @since 2.6.0
     */
    public static function flush_godaddy_urls() {
        if ( empty( self::$gd_purge_urls ) ) {
            return;
        }
        $urls                = self::$gd_purge_urls;
        self::$gd_purge_urls = array();

        if ( ! isset( $GLOBALS['wpaas_cache_class'] ) || ! is_object( $GLOBALS['wpaas_cache_class'] ) ) {
            return;
        }
        $gd = $GLOBALS['wpaas_cache_class'];

        // Something already scheduled a full ban this request (the platform's
        // own publish hook usually has). Anything further is redundant work
        // against the same cache — and, more importantly, would burn a second
        // token from the ban rate limit for one logical purge.
        if ( self::godaddy_ban_scheduled( $gd ) ) {
            return;
        }

        if ( self::godaddy_requires_ban( $gd ) ) {
            // ban_no_flush(), NOT ban(). ban() defaults to
            // $flush_object_cache = true and calls flush_object_cache(), which
            // would wipe Redis on every post save — far worse than the bug
            // this fixes. GoDaddy added ban_no_flush() for exactly this case:
            // invalidate Varnish + CDN, leave the object cache alone.
            //
            // do_ban_no_flush() is idempotent and registers on shutdown at
            // PHP_INT_MAX, so this runs once per request no matter how many
            // URLs were staged — which keeps us inside their limit of
            // MAX_BAN_LIMIT * 2 (16) bans per 300s on v2. Escalating per URL
            // would exhaust that quota in a single post save and leave the
            // next few edits silently unpurged.
            if ( method_exists( $gd, 'do_ban_no_flush' ) ) {
                $gd->do_ban_no_flush();
                // Deliberately not logged: on v2 this is the normal, expected
                // path on every content change, and self::log( 'info', … )
                // writes to the warning channel — it would fill the debug log
                // with a warning for routine behaviour.
                return;
            }
            // Older gd-system-plugin without ban_no_flush(): fall through to
            // the targeted purge rather than reach for ban() and flush the
            // object cache on every edit. Incomplete beats destructive.
            self::log( 'warn', 'GoDaddy: full-page cache is active but ban_no_flush() is unavailable; falling back to a per-URL purge, which the CDN may ignore.' );
        }

        if ( ! method_exists( $gd, 'purge' ) ) {
            return;
        }
        try {
            $gd->purge( $urls );
        } catch ( \Throwable $e ) {
            self::log( 'warn', 'GoDaddy per-URL purge failed: ' . $e->getMessage() );
        }
    }

    /**
     * (2.6.0) Is a GoDaddy cache ban already scheduled for this request?
     *
     * Covers both variants — Cache_V2 tracks them as separate shutdown
     * callbacks, so checking only has_ban() would miss a pending
     * ban_no_flush() and schedule a duplicate.
     *
     * @param object $gd WPaaS cache controller.
     * @return bool
     */
    private static function godaddy_ban_scheduled( $gd ) {
        if ( method_exists( $gd, 'has_ban' ) && $gd->has_ban() ) {
            return true;
        }
        if ( method_exists( $gd, 'has_ban_no_flush' ) && $gd->has_ban_no_flush() ) {
            return true;
        }
        return false;
    }

    /**
     * (2.6.0) Would GoDaddy itself escalate a per-URL purge to a full ban?
     *
     * Reproduces Cache_V2::should_switch_to_ban()'s full-page-cache test using
     * only public signals — the cache manager it consults is a private
     * property, so we cannot ask it directly.
     *
     *   v2 infrastructure  → V2_Manager::is_full_page_cache_enabled() is
     *                        `return true`, unconditionally.
     *   v1 infrastructure  → V1_Manager checks the GD_CDN_FULLPAGE constant,
     *                        so per-URL purges remain valid without it.
     *
     * @param object $gd WPaaS cache controller.
     * @return bool
     */
    private static function godaddy_requires_ban( $gd ) {
        // Their own helper is public static and filterable (`is_wpaas_v2`),
        // so prefer it over sniffing the environment ourselves.
        if ( is_callable( array( $gd, 'is_wpaas_v2' ) ) ) {
            try {
                if ( $gd::is_wpaas_v2() ) {
                    return true;
                }
            } catch ( \Throwable $e ) { // phpcs:ignore
                // Fall through to the environment checks below.
            }
        }
        if ( '' !== (string) getenv( 'WPAAS_V2_SITE_ID' ) ) {
            return true;
        }
        if ( defined( 'GD_CDN_FULLPAGE' ) && GD_CDN_FULLPAGE ) {
            return true;
        }
        return false;
    }

    /**
     * Whether to send Varnish HTTP PURGE on per-URL invalidation.
     *
     * Conservative: only when we have strong evidence Varnish is active
     * and NOT already handled by a plugin-level method above.
     */
    private static function should_purge_varnish_url() {
        // Cloudways always has Varnish. When Breeze IS installed,
        // Breeze_PurgeVarnish handles per-URL purge — but only if
        // their Varnish option is ON. Since we can't reliably check
        // Breeze's settings, send the HTTP PURGE as safety net.
        // It's idempotent and costs ~2ms with blocking=false.
        if ( self::is_cloudways() ) {
            return true;
        }
        // Standalone Varnish detected by plugin class.
        if ( self::has_varnish() ) {
            return true;
        }
        // User opt-in for custom setups.
        return (bool) apply_filters( 'easyopt_varnish_purge_urls', false );
    }


    /* ================================================================
     *  FULL PURGE HANDLERS
     * ================================================================
     *
     *  One `do_purge_all_{key}` method per detected layer.
     *  Each returns bool (true = success or sent, false = skipped/failed).
     *  All are called from purge_all() inside try/catch.
     */

    // ── Managed Hosting ──────────────────────────────────────────

    /**
     * Kinsta — purges Nginx edge cache + CDN via their MU-plugin.
     */
    private static function do_purge_all_kinsta() {
        global $kinsta_cache;
        if ( empty( $kinsta_cache ) ) {
            return false;
        }
        if ( ! isset( $kinsta_cache->kinsta_cache_purge )
             || ! method_exists( $kinsta_cache->kinsta_cache_purge, 'purge_complete_caches' ) ) {
            return false;
        }
        $kinsta_cache->kinsta_cache_purge->purge_complete_caches();
        return true;
    }

    /**
     * WP Engine — purges Varnish + Memcached + CDN.
     */
    private static function do_purge_all_wpengine() {
        if ( ! class_exists( 'WpeCommon' ) ) {
            return false;
        }
        if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
            \WpeCommon::purge_memcached();
        }
        if ( method_exists( 'WpeCommon', 'clear_maxcdn_cache' ) ) {
            \WpeCommon::clear_maxcdn_cache();
        }
        if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
            \WpeCommon::purge_varnish_cache();
        }
        return true;
    }

    /**
     * SiteGround — purges SG Optimizer / SuperCacher.
     */
    private static function do_purge_all_siteground() {
        if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
            sg_cachepress_purge_everything();
            return true;
        }
        return false;
    }

    /**
     * Cloudways — purges Varnish via Breeze plugin API, Breeze local cache,
     * Breeze Cloudflare integration, AND direct HTTP PURGE as fallback
     * for setups where Breeze is absent or Varnish purge class is missing.
     */
    private static function do_purge_all_cloudways() {
        $did = false;

        // 1. Breeze Varnish purge (talks to Varnish daemon directly).
        if ( class_exists( 'Breeze_PurgeVarnish' ) && method_exists( 'Breeze_PurgeVarnish', 'breeze_cache_flush' ) ) {
            \Breeze_PurgeVarnish::breeze_cache_flush();
            $did = true;
        }

        // 2. Breeze local file cache.
        if ( class_exists( 'Breeze_PurgeCache' ) && method_exists( 'Breeze_PurgeCache', 'breeze_cache_flush' ) ) {
            \Breeze_PurgeCache::breeze_cache_flush();
            $did = true;
        }

        // 3. Breeze Cloudflare integration.
        if ( class_exists( 'Breeze_CloudFlare_Helper' )
             && method_exists( 'Breeze_CloudFlare_Helper', 'is_cloudflare_enabled' )
             && \Breeze_CloudFlare_Helper::is_cloudflare_enabled() ) {
            \Breeze_CloudFlare_Helper::reset_all_cache();
            $did = true;
        }

        // 4. Direct Varnish HTTP PURGE — essential fallback when Breeze
        //    is not installed or Breeze_PurgeVarnish class is absent.
        //    Cloudways Varnish accepts PURGE method on the site's own URL.
        if ( ! class_exists( 'Breeze_PurgeVarnish' ) ) {
            self::http_purge( home_url( '/' ), true );
            $did = true;
        }

        return $did;
    }

    /**
     * GridPane — purges Nginx cache via their purger plugin.
     */
    private static function do_purge_all_gridpane() {
        if ( ! class_exists( 'Nginx_Cache_Purger_Admin' ) ) {
            return false;
        }
        $purger = new \Nginx_Cache_Purger_Admin();
        if ( method_exists( $purger, 'register_purge' ) ) {
            $purger->register_purge();
            return true;
        }
        return false;
    }

    /**
     * RunCloud — purges Nginx + Redis via RunCloud Hub MU-plugin.
     */
    private static function do_purge_all_runcloud() {
        if ( ! class_exists( 'RunCloud_Hub' ) ) {
            return false;
        }
        if ( is_multisite() ) {
            \RunCloud_Hub::purge_cache_all_sites();
        } else {
            \RunCloud_Hub::purge_cache_all();
        }
        return true;
    }

    /**
     * SpinupWP — purges Nginx page cache via their MU-plugin.
     */
    private static function do_purge_all_spinupwp() {
        if ( ! function_exists( 'spinupwp_purge_site' ) ) {
            return false;
        }
        spinupwp_purge_site();
        return true;
    }

    /**
     * Rocket.net — purges their CDN edge cache.
     */
    private static function do_purge_all_rocketnet() {
        if ( ! class_exists( 'CDN_Clear_Cache_Hooks' ) ) {
            return false;
        }
        if ( method_exists( 'CDN_Clear_Cache_Hooks', 'purge_cache' ) ) {
            \CDN_Clear_Cache_Hooks::purge_cache();
            return true;
        }
        return false;
    }

    /**
     * WordPress.com / WP Cloud (Atomic) — purges edge cache.
     */
    private static function do_purge_all_wpcloud() {
        if ( ! class_exists( 'Atomic_Persistent_Data' ) ) {
            return false;
        }
        if ( class_exists( 'Edge_Cache_Atomic' ) && method_exists( 'Edge_Cache_Atomic', 'purge' ) ) {
            \Edge_Cache_Atomic::purge();
            return true;
        }
        return false;
    }

    /**
     * Pantheon — purges their global edge + Varnish layer.
     */
    private static function do_purge_all_pantheon() {
        if ( function_exists( 'pantheon_wp_clear_edge_all' ) ) {
            pantheon_wp_clear_edge_all();
            return true;
        }
        if ( function_exists( 'pantheon_clear_edge_all' ) ) {
            pantheon_clear_edge_all();
            return true;
        }
        return false;
    }

    /**
     * Flywheel — purges their custom page cache.
     */
    private static function do_purge_all_flywheel() {

        // (2.6.2) flywheel_purge_site_cache() does not exist and never did —
        // Flywheel ships no page-cache purge API of any kind. Verified against
        // a live Flywheel site: their own maintenance script
        // (/www/fw-flush-cache.php) flushes the object cache and nothing else,
        // so that is the honest equivalent of a full purge here.
        //
        // FlyCache is purged by Flywheel's own platform, which watches for
        // content-change events their mu-plugin reports on save_post and
        // friends. Publishing and editing therefore already refresh the edge
        // without us. What we cannot reach is a theme or CSS change, so
        // manual_purge_note() tells the admin to clear it from their Flywheel
        // dashboard instead of failing silently.
        //
        // An earlier attempt reported our own purges through that same event
        // channel. It did not clear FlyCache — their collector evidently keys
        // on the originating hook — so it was removed rather than left in
        // place writing to the site's error log on every purge.
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
            return true;
        }

        return false;
    }

    /**
     * (2.6.2) Short note for hosts whose server cache we cannot purge.
     *
     * Appended to the cache-cleared confirmation so the admin knows what did
     * and did not happen, rather than assuming a green message covered
     * everything.
     *
     * @return string Empty when the host has no such limitation.
     */
    public static function manual_purge_note() {
        if ( self::is_flywheel() ) {
            return __( "Flywheel's server cache can't be cleared from WordPress — clear it from your Flywheel dashboard.", 'easy-optimizer' );
        }
        return '';
    }

    /**
     * Pressable — Batcache-backed; wp_cache_flush covers it.
     * Their mu-plugin may expose more specific methods in future.
     */
    private static function do_purge_all_pressable() {
        if ( ! defined( 'IS_PRESSABLE' ) || ! IS_PRESSABLE ) {
            return false;
        }
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }
        return true;
    }

    /**
     * Closte — purges their custom edge/proxy cache.
     */
    private static function do_purge_all_closte() {
        if ( function_exists( 'closte_purge_cache' ) ) {
            closte_purge_cache();
            return true;
        }
        return false;
    }

    /**
     * GoDaddy Managed WordPress — full cache ban (Varnish + CDN).
     *
     * WPaaS\Cache_V2::ban() begins with `if ( 'shutdown' !== current_action() )
     * return false;`, so calling it directly from our purge hook is a silent
     * no-op. It MUST be deferred to shutdown. Their own code does the same
     * (do_ban() schedules ban() on shutdown), and it is internally
     * rate-limited, so scheduling it more than once is harmless.
     */
    private static function do_purge_all_godaddy() {
        if ( ! isset( $GLOBALS['wpaas_cache_class'] ) || ! is_object( $GLOBALS['wpaas_cache_class'] ) ) {
            return false;
        }
        $gd = $GLOBALS['wpaas_cache_class'];

        if ( self::godaddy_ban_scheduled( $gd ) ) {
            return true; // already scheduled by the platform this request
        }

        // (2.6.0) Use their do_ban() rather than hooking ban() ourselves.
        //
        // Two reasons. First, it registers on shutdown at PHP_INT_MAX, which is
        // the priority the platform uses — and WordPress de-duplicates an
        // identical callback only within the SAME priority bucket. Registering
        // at the default 10, as we did, meant that if the platform also queued
        // its own ban, ban() ran twice for one logical purge and consumed two
        // tokens from a rate limit of 8 (16 on v2) per 300 seconds. Exhaust it
        // and every further flush that window is silently dropped, which is
        // indistinguishable from a purge that never happened.
        //
        // Second, do_ban() clears any pending purge/ban_no_flush registration
        // first, so the request ends with exactly one cache operation.
        //
        // ban() (object-cache flush included) is deliberate here: this path is
        // the explicit "clear everything" action, unlike the per-URL path,
        // which uses ban_no_flush().
        if ( method_exists( $gd, 'do_ban' ) ) {
            $gd->do_ban();
            return true;
        }
        if ( method_exists( $gd, 'ban' ) ) {
            add_action( 'shutdown', array( $gd, 'ban' ), PHP_INT_MAX );
            return true;
        }
        return false;
    }

    /**
     * Convesio — container-based hosting with their own purge hook.
     */
    private static function do_purge_all_convesio() {
        if ( class_exists( 'Starter_Starter' ) && method_exists( 'Starter_Starter', 'clear_page_cache' ) ) {
            \Starter_Starter::clear_page_cache();
            return true;
        }
        return false;
    }

    // ── Generic Cache Layers ─────────────────────────────────────

    /**
     * Varnish — direct HTTP PURGE for standalone Varnish setups.
     *
     * Skip if a managed host handler (Cloudways, WP Engine, Pantheon)
     * already covers Varnish — those handlers talk to the daemon via
     * their own plugin APIs which are more reliable than blind HTTP PURGE.
     */
    private static function do_purge_all_varnish() {
        // Managed host handlers already sent Varnish purge.
        if ( self::is_cloudways() || self::is_wpengine() || self::is_pantheon() ) {
            return true;
        }
        return self::http_purge( home_url( '/' ), true );
    }

    /**
     * Nginx Helper — purge Nginx FastCGI / Redis cache via plugin API.
     */
    private static function do_purge_all_nginx_helper() {
        if ( function_exists( 'rt_nginx_helper_purge_all' ) ) {
            rt_nginx_helper_purge_all();
            return true;
        }
        return false;
    }


    /* ================================================================
     *  HTTP PURGE HELPER
     * ================================================================ */

    /**
     * Send an HTTP PURGE request to a URL.
     *
     * Used for Varnish and similar reverse proxies that accept the PURGE
     * method. Fire-and-forget (blocking=false) so it never stalls the
     * user's request. A PURGE to a server without Varnish returns 405
     * and is harmless.
     *
     * @param string $url    Target URL.
     * @param bool   $regex  Whether to request regex-based purge (entire site vs single URL).
     * @return bool True if request was dispatched (not necessarily successful).
     */
    private static function http_purge( $url, $regex = false ) {

        $host = (string) wp_parse_url( $url, PHP_URL_HOST );
        if ( '' === $host ) {
            return false;
        }

        $headers = array(
            'Host'           => $host,
            'X-Purge-Method' => $regex ? 'regex' : 'default',
        );

        /**
         * Filter HTTP PURGE request headers.
         *
         * Useful for custom Varnish ACL headers, different purge-method
         * names (e.g. X-VC-Purge-Method), or additional auth tokens.
         *
         * @param array  $headers Request headers.
         * @param string $url     Target URL.
         * @param bool   $regex   Whether this is a regex/full purge.
         */
        $headers = (array) apply_filters( 'easyopt_http_purge_headers', $headers, $url, $regex );

        $response = wp_remote_request( $url, array(
            'method'      => 'PURGE',
            'timeout'     => self::PURGE_TIMEOUT,
            'redirection' => 0,
            'blocking'    => false,   // Fire-and-forget.
            'sslverify'   => false,
            'headers'     => $headers,
        ) );

        // With blocking=false, wp_remote_request returns immediately.
        // We can't check the response code — by design.
        return ! is_wp_error( $response );
    }


    /* ================================================================
     *  UTILITY
     * ================================================================ */

    /**
     * Log a message through the plugin's debug log if available.
     *
     * @param string $level  'info', 'warn', 'error', 'debug'.
     * @param string $msg    Message text.
     */
    private static function log( $level, $msg ) {

        if ( ! class_exists( 'EasyOpt_Debug_Log' ) ) {
            return;
        }

        switch ( $level ) {
            case 'error':
                EasyOpt_Debug_Log::error( 'hosting', $msg );
                break;
            case 'warn':
                EasyOpt_Debug_Log::warn( 'hosting', $msg );
                break;
            case 'info':
            case 'debug':
            default:
                // Debug log only has warn/error. Use warn for info-level
                // messages that are worth recording.
                if ( 'info' === $level ) {
                    EasyOpt_Debug_Log::warn( 'hosting', '[info] ' . $msg );
                }
                break;
        }
    }

    /**
     * Reset detection cache. Useful for unit tests or when a plugin
     * changes the environment mid-request.
     */
    public static function reset_detection() {
        self::$layers      = null;
        self::$purged_all  = false;
        self::$purged_urls = array();
    }
}
