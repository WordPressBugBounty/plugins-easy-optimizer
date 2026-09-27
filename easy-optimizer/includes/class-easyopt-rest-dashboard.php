<?php
/**
 * REST API endpoints for the React dashboard (replaces all wp_ajax_ handlers).
 *
 * WHY THIS EXISTS:
 * The old architecture used 16 separate wp_ajax_ handlers routed through
 * admin-ajax.php. Every single request — even a lightweight "what version
 * is the cache state?" poll — loaded the full WordPress admin bootstrap.
 * The version-watcher alone fired 60 requests/minute at this heavy path.
 *
 * REST API routes skip most of the admin bootstrap and support conditional
 * GET caching. Combined with the React dashboard's React-Query layer
 * (which deduplicates requests and uses staleTime instead of polling),
 * server load drops by 90%+ on idle dashboards.
 *
 * ENDPOINTS (all under /wp-json/easyopt/v1/):
 *
 *   GET  /settings              → full settings object
 *   POST /settings              → save changed settings
 *   GET  /dashboard-stats       → pages cached, waiting count, serving mode (ONE call replaces THREE)
 *   POST /cache/clear           → clear page cache
 *   POST /cache/preload-start   → start cache preload
 *   POST /cache/preload-stop    → stop cache preload
 *   GET  /cache/preload-status  → preload progress
 *   POST /css/clear             → clear used CSS cache
 *   POST /cloudflare/test       → test CF connection
 *   POST /cloudflare/purge      → purge CF cache
 *   POST /database/run          → run DB cleanup (chunked)
 *   GET  /database/counts       → cleanup item counts
 *   GET  /database/snapshots    → list snapshots
 *   POST /database/snapshot-restore → restore a snapshot (chunked)
 *   POST /database/snapshot-delete  → delete a snapshot
 *
 * Auth: All endpoints require manage_options + valid REST nonce.
 *
 * @package EasyOptimizer
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Rest_Dashboard {

    const NS = 'easyopt/v1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    /* ── Auth ──────────────────────────────────────────────────────── */

    public static function can_manage( $request ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'easyopt_forbidden',
                __( 'Insufficient permissions.', 'easy-optimizer' ),
                array( 'status' => 403 )
            );
        }
        return true;
    }

    /* ── Route Registration ───────────────────────────────────────── */

    public static function register_routes() {
        $admin = array( 'permission_callback' => array( __CLASS__, 'can_manage' ) );

        // ── Settings (already exists in class-easyopt-rest-settings.php,
        //    but kept here for reference — only register if not already done)
        // register_rest_route( self::NS, '/settings', ... );

        // ── Dashboard Stats (consolidates 3 old AJAX calls into 1) ──
        register_rest_route( self::NS, '/dashboard-stats', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'get_dashboard_stats' ),
        ) + $admin );

        // ── Cache Operations ─────────────────────────────────────────
        register_rest_route( self::NS, '/cache/clear', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'clear_cache' ),
        ) + $admin );

        register_rest_route( self::NS, '/cache/preload-start', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'preload_start' ),
        ) + $admin );

        register_rest_route( self::NS, '/cache/preload-stop', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'preload_stop' ),
        ) + $admin );

        register_rest_route( self::NS, '/cache/preload-status', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'preload_status' ),
        ) + $admin );

        // ── CSS ──────────────────────────────────────────────────────
        register_rest_route( self::NS, '/css/clear', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'clear_used_css' ),
        ) + $admin );

        // ── LCP ──────────────────────────────────────────────────────
        register_rest_route( self::NS, '/lcp/clear', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'clear_lcp_data' ),
        ) + $admin );

        // ── Fonts ────────────────────────────────────────────────────
        register_rest_route( self::NS, '/fonts/clear', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'clear_fonts_data' ),
        ) + $admin );

        // ── Local images ─────────────────────────────
        register_rest_route( self::NS, '/images/stats', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'images_stats' ),
        ) + $admin );

        register_rest_route( self::NS, '/images/optimize', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'images_optimize' ),
        ) + $admin );

        register_rest_route( self::NS, '/images/restore', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'images_restore' ),
        ) + $admin );

        // ── Cloudflare ───────────────────────────────────────────────
        register_rest_route( self::NS, '/cloudflare/test', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'cf_test' ),
        ) + $admin );

        register_rest_route( self::NS, '/cloudflare/purge', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'cf_purge' ),
        ) + $admin );

        // ── Database ─────────────────────────────────────────────────
        register_rest_route( self::NS, '/database/run', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'db_run' ),
        ) + $admin );

        register_rest_route( self::NS, '/database/counts', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'db_counts' ),
        ) + $admin );

        register_rest_route( self::NS, '/database/snapshots', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'db_snapshots' ),
        ) + $admin );

        register_rest_route( self::NS, '/database/snapshot-restore', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'db_snapshot_restore' ),
        ) + $admin );

        register_rest_route( self::NS, '/database/snapshot-delete', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'db_snapshot_delete' ),
        ) + $admin );

        register_rest_route( self::NS, '/database/autoload', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'db_autoload' ),
        ) + $admin );

        register_rest_route( self::NS, '/database/autoload-toggle', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'db_autoload_toggle' ),
        ) + $admin );

        // ── Elementor CDN ────────────────────────────────────────────
        // ── FluxCDN (2.5.3) ──────────────────────────────────────────
        register_rest_route( self::NS, '/fluxcdn/connect', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'fluxcdn_connect' ),
        ) + $admin );

        register_rest_route( self::NS, '/fluxcdn/disconnect', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'fluxcdn_disconnect' ),
        ) + $admin );

        register_rest_route( self::NS, '/fluxcdn/test', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'fluxcdn_test' ),
        ) + $admin );

        register_rest_route( self::NS, '/fluxcdn/usage', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'fluxcdn_usage' ),
        ) + $admin );

        register_rest_route( self::NS, '/elementor/apply-cdn', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'elementor_apply_cdn' ),
        ) + $admin );

        register_rest_route( self::NS, '/elementor/regenerate-css', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'elementor_regenerate_css' ),
        ) + $admin );

        // ── Cron ──────────────────────────────────────────────────────
        register_rest_route( self::NS, '/cron/events', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'cron_events' ),
        ) + $admin );

        register_rest_route( self::NS, '/cron/run', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'cron_run' ),
        ) + $admin );

        register_rest_route( self::NS, '/cron/delete', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'cron_delete' ),
        ) + $admin );

        // ── Settings Import (2.1.0) ─────────────────────────────────
        register_rest_route( self::NS, '/settings/import', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'settings_import' ),
        ) + $admin );

        // ── Debug Log (2.1.1) ─────────────────────────────────────────
        register_rest_route( self::NS, '/debug-log', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'debug_log_read' ),
        ) + $admin );

        register_rest_route( self::NS, '/debug-log/clear', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'debug_log_clear' ),
        ) + $admin );

        // ── Debug Issues panel (2.6.0) ────────────────────────────────
        // Cache status for ONE url. Two file_exists() calls behind an
        // admin capability check; never polled. Deliberately not folded into
        // /dashboard-stats, which must stay O(1) for the 20s poll.
        register_rest_route( self::NS, '/debug/url-status', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'debug_url_status' ),
            'args'     => array(
                'url' => array(
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'esc_url_raw',
                ),
            ),
        ) + $admin );

        // ── Object Cache (2.4.7) ─────────────────────────────────────
        register_rest_route( self::NS, '/objectcache/status', array(
            'methods'  => 'GET',
            'callback' => array( __CLASS__, 'objectcache_status' ),
        ) + $admin );

        register_rest_route( self::NS, '/objectcache/flush', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'objectcache_flush' ),
        ) + $admin );

        register_rest_route( self::NS, '/objectcache/test', array(
            'methods'  => 'POST',
            'callback' => array( __CLASS__, 'objectcache_test' ),
        ) + $admin );
    }

    /* ── Dashboard Stats ──────────────────────────────────────────── */
    /*  ONE endpoint replaces: easyopt_cache_stats + easyopt_waiting_count
     *  + easyopt_preload_status + easyopt_state_version.
     *  React Query calls this once on mount and re-fetches on user actions. */

    /**
     * @param WP_REST_Request|null $request REST request (when called as a route callback).
     * @param bool                 $fast    When true, skip the filesystem-scan fallback
     *                                       for the cached-pages count and report 0 if the
     *                                       O(1) counter isn't seeded yet. Used by the save
     *                                       endpoint so a settings save never blocks on a
     *                                       directory scan; the dedicated GET /dashboard-stats
     *                                       (called as a route, $fast defaults false) keeps the
     *                                       full self-healing behaviour.
     */
    public static function get_dashboard_stats( $request = null, $fast = false ) {
        $cache_on  = (int) EasyOpt_Config::get( 'easyopt_cache' );
        $preload   = (int) EasyOpt_Config::get( 'easyopt_cache_preload' );

        // ── Pages cached ─────────────────────────────────────────────
        // Prefer the atomic counter (O(1), single option read) over the
        // filesystem scan (RecursiveIteratorIterator, 60s stale transient).
        // The counter self-heals from get_stats() every 60s via the
        // easyopt_cache_stats_computed action.
        $pages = 0;
        if ( $cache_on ) {
            if ( class_exists( 'EasyOpt_Cache_Counter' ) && method_exists( 'EasyOpt_Cache_Counter', 'get' ) ) {
                $count = EasyOpt_Cache_Counter::get();
                // Counter returns -1 if the option doesn't exist yet.
                // Fall back to get_stats() for the initial scan.
                if ( $count >= 0 ) {
                    $pages = $count;
                } elseif ( ! $fast && class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'get_stats' ) ) {
                    $stats = EasyOpt_Cache::get_stats();
                    $pages = (int) ( isset( $stats['pages'] ) ? $stats['pages'] : 0 );
                }
            } elseif ( ! $fast && class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'get_stats' ) ) {
                $stats = EasyOpt_Cache::get_stats();
                $pages = (int) ( isset( $stats['pages'] ) ? $stats['pages'] : 0 );
            }
        }

        // ── URLs in queue ──────────────────────────────────────────
        // Fast: single COUNT(*) on queue table (~1ms).
        // Shows pending preload tasks, NOT "total uncached URLs on site"
        // (which required scanning every post + filesystem = timeout risk).
        $waiting = 0;
        if ( $preload && class_exists( 'EasyOpt_Queue' ) && method_exists( 'EasyOpt_Queue', 'count_distinct_urls' ) ) {
            $waiting = (int) EasyOpt_Queue::count_distinct_urls( 'preload', 'easyopt_preload_warm_url' );
        }

        // ── Preload status ───────────────────────────────────────────
        // Replicate ajax_status() logic exactly.
        $preload_status = 'idle';
        $preload_total  = (int) get_option( 'easyopt_cache_preload_total', 0 );
        $preload_done   = 0;
        $preload_failed = 0;
        if ( class_exists( 'EasyOpt_Cache_Preload' ) ) {
            $preload_status = (string) get_option( 'easyopt_cache_preload_status', 'idle' );

            $urls_remaining = 0;
            $build_pending  = 0;
            if ( class_exists( 'EasyOpt_Queue' ) ) {
                if ( method_exists( 'EasyOpt_Queue', 'count_distinct_urls' ) ) {
                    $urls_remaining = EasyOpt_Queue::count_distinct_urls( 'preload', 'easyopt_preload_warm_url' );
                }
            }
            $preload_done = max( 0, $preload_total - $urls_remaining );

            // (2.5.0 / M2) Display-only completion — do NOT write state from
            // a GET. The authoritative running→done flip happens on the
            // queue's group-drained action and the watchdog. Reflecting an
            // empty queue as 'done' here is purely for this response.
            if ( 'running' === $preload_status && 0 === $urls_remaining && $preload_total > 0 ) {
                $preload_status = 'done';
            } elseif ( 'running' === $preload_status && 0 === $urls_remaining && $preload_total < 1 ) {
                // (2.6.0) A companion warm (preload-on-MISS) sets the status to
                // 'running' without ever seeding a total, so the branch above
                // could never rescue it and the UI showed a preload running
                // forever. With an empty queue and nothing seeded there is no
                // run — report idle. Display-only, exactly like the branch
                // above; maybe_mark_done() is what actually writes the state.
                $preload_status = 'idle';
            }
        }

        // The dashboard surfaces exactly two live numbers — "Pages cached"
        // (disk truth, from the counter) and "URLs in waiting" (the queue).
        // The per-URL results ledger drives completion detection + retry
        // behind the scenes but is intentionally NOT shown here: mixing a
        // per-run "cached" tally with the on-disk total invited confusing,
        // apparently-inconsistent figures on the dashboard.
        $preload_data = array(
            'status'        => $preload_status,
            'done'          => $preload_done,
            'total'         => $preload_total,
            'paused_reason' => (string) get_transient( 'easyopt_cache_preload_paused_reason' ),
        );

        $version = (int) get_option( 'easyopt_state_version', 0 );

        // Server-side cache layers detected on this host.
        $server_cache = class_exists( 'EasyOpt_Hosting' ) && method_exists( 'EasyOpt_Hosting', 'get_status' )
            ? EasyOpt_Hosting::get_status()
            : array( 'layers' => array(), 'count' => 0 );

        // (2.6.0) "Pages cached" and "URLs in waiting" are both URL-scoped, but
        // they can still legitimately differ: a URL cached for desktop and
        // still queued for its mobile variant is one page AND one waiting URL
        // at the same time. Rather than hide that, the UI explains it — see
        // the KPI sub-labels in react-src/app.tsx. Shipping the flag costs one
        // already-warm config read and saves a support ticket.
        $separate_mobile = (int) EasyOpt_Config::get( 'cache_separate_mobile', 1 );

        return rest_ensure_response( array(
            'pages'           => $pages,
            'pages_label'     => number_format_i18n( $pages ),
            'waiting'         => $waiting,
            'preload_status'  => $preload_data,
            'state_version'   => $version,
            'server_cache'    => $server_cache,
            'separate_mobile' => $separate_mobile,
        ) );
    }

    /**
     * (2.6.0) Is ONE url currently in the page cache?
     *
     * Powers the Debug Issues panel, which needs to answer "the page you are
     * testing is / is not being served from cache" before the user starts
     * toggling optimizations. Without it they debug the render pipeline for a
     * page that was never cached in the first place.
     *
     * Cost is two file_exists() per device (gzip then plain), admin-only, and
     * called once when the panel opens — not on any polling path.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function debug_url_status( $request ) {
        $url = (string) $request->get_param( 'url' );
        $out = array(
            'url'             => $url,
            'cache_enabled'   => (int) EasyOpt_Config::get( 'easyopt_cache' ),
            'separate_mobile' => (int) EasyOpt_Config::get( 'cache_separate_mobile', 1 ),
            'desktop'         => false,
            'mobile'          => false,
            'excluded'        => false,
            'resolved'        => false,
        );

        if ( '' === $url || ! class_exists( 'EasyOpt_Cache' )
             || ! method_exists( 'EasyOpt_Cache', 'cache_paths_for_url' ) ) {
            return rest_ensure_response( $out );
        }

        // Is this URL excluded from caching by a built-in or user rule? That
        // is the single most common reason a page "won't cache", and it is
        // answerable without touching the filesystem.
        $path = wp_parse_url( $url, PHP_URL_PATH );
        $qs   = wp_parse_url( $url, PHP_URL_QUERY );
        $uri  = ( is_string( $path ) && '' !== $path ? $path : '/' )
              . ( is_string( $qs ) && '' !== $qs ? '?' . $qs : '' );
        if ( function_exists( 'EasyOpt\\Cache\\is_excluded_uri' )
             && method_exists( 'EasyOpt_Cache', 'get_url_exclusions' ) ) {
            $out['excluded'] = (bool) \EasyOpt\Cache\is_excluded_uri(
                $uri,
                (array) EasyOpt_Cache::get_url_exclusions()
            );
        }

        $paths = EasyOpt_Cache::cache_paths_for_url( $url );
        if ( ! empty( $paths ) ) {
            $out['resolved'] = true;
            // Either the gzip copy or the plain HTML counts — an install with
            // Gzip off, or on OpenLiteSpeed, only ever writes the plain file.
            foreach ( array( 'desktop', 'mobile' ) as $dev ) {
                $gz    = isset( $paths[ $dev ] ) ? (string) $paths[ $dev ] : '';
                $plain = isset( $paths[ $dev . '_plain' ] ) ? (string) $paths[ $dev . '_plain' ] : '';
                $out[ $dev ] = ( '' !== $gz && file_exists( $gz ) )
                    || ( '' !== $plain && file_exists( $plain ) );
            }
        }

        return rest_ensure_response( $out );
    }

    /**
     * Module status list for the dashboard overview card.
     */
    private static function get_module_statuses() {
        $items = array(
            array( 'key' => 'cache',       'title' => __( 'Page Cache', 'easy-optimizer' ),                          'opt' => 'easyopt_cache',          'tab' => 'cache',        'sub' => '' ),
            array( 'key' => 'delay_js',    'title' => __( 'Delay Javascript', 'easy-optimizer' ),                    'opt' => 'easyopt_delay_js',       'tab' => 'optimization', 'sub' => 'js' ),
            array( 'key' => 'unused_css',  'title' => __( 'Remove Unused CSS', 'easy-optimizer' ),                   'opt' => 'easyopt_unused_css',     'tab' => 'optimization', 'sub' => 'css' ),
            array( 'key' => 'lazy_images', 'title' => __( 'Lazyload Images', 'easy-optimizer' ),                     'opt' => 'easyopt_lazy_images',    'tab' => 'optimization', 'sub' => 'lazy' ),
            array( 'key' => 'lcp',         'title' => __( 'Preload Largest Contentful Paint', 'easy-optimizer' ),     'opt' => 'easyopt_lcp_preload',    'tab' => 'optimization', 'sub' => 'lcp' ),
            array( 'key' => 'img_cdn',     'title' => __( 'Image Optimizer (CDN)', 'easy-optimizer' ),                'opt' => 'easyopt_img_opt',        'tab' => 'imgopt',       'sub' => '' ),
        );

        foreach ( $items as &$m ) {
            $m['enabled'] = (bool) (int) EasyOpt_Config::get( $m['opt'] );
        }
        return $items;
    }

    /* ── Cache Operations ─────────────────────────────────────────── */

    public static function clear_cache() {
        if ( ! class_exists( 'EasyOpt_Cache' ) ) {
            return new WP_Error( 'easyopt_no_cache', 'Cache module not loaded.', array( 'status' => 500 ) );
        }
        // clear_all() internally schedules a 10s delayed preload restart
        // when preload is enabled — no need to call start() separately.
        $result = EasyOpt_Cache::clear_all();

        // Guard against race: a concurrent request could regenerate the
        // stats transient between clear_all()'s delete and our response.
        delete_transient( 'easyopt_cache_stats' );

        // Return fresh dashboard data so React can update immediately
        // without waiting for a separate poll round-trip.
        $stats_response = self::get_dashboard_stats();
        $stats_data     = $stats_response->get_data();

        // (2.6.2) On hosts with a server cache we cannot purge, say so here
        // rather than letting a bare success message imply otherwise.
        $message = __( 'Cache cleared.', 'easy-optimizer' );
        if ( class_exists( 'EasyOpt_Hosting' )
             && method_exists( 'EasyOpt_Hosting', 'manual_purge_note' ) ) {
            $note = EasyOpt_Hosting::manual_purge_note();
            if ( '' !== $note ) {
                $message .= ' ' . $note;
            }
        }

        return rest_ensure_response( array(
            'success' => true,
            'message' => $message,
            'stats'   => $stats_data,
        ) );
    }

    public static function preload_start() {
        if ( ! class_exists( 'EasyOpt_Cache_Preload' ) ) {
            return new WP_Error( 'easyopt_no_preload', 'Preload module not loaded.', array( 'status' => 500 ) );
        }
        $result = EasyOpt_Cache_Preload::start();
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( array( 'success' => true, 'message' => __( 'Preload started.', 'easy-optimizer' ) ) );
    }

    public static function preload_stop() {
        if ( ! class_exists( 'EasyOpt_Cache_Preload' ) ) {
            return new WP_Error( 'easyopt_no_preload', 'Preload module not loaded.', array( 'status' => 500 ) );
        }
        EasyOpt_Cache_Preload::stop();
        return rest_ensure_response( array( 'success' => true ) );
    }

    public static function preload_status() {
        if ( ! class_exists( 'EasyOpt_Cache_Preload' ) ) {
            return rest_ensure_response( array( 'status' => 'idle', 'done' => 0, 'total' => 0 ) );
        }

        $total          = (int) get_option( 'easyopt_cache_preload_total', 0 );
        $status         = (string) get_option( 'easyopt_cache_preload_status', 'idle' );
        $urls_remaining = 0;

        if ( class_exists( 'EasyOpt_Queue' ) && method_exists( 'EasyOpt_Queue', 'count_distinct_urls' ) ) {
            $urls_remaining = (int) EasyOpt_Queue::count_distinct_urls( 'preload', 'easyopt_preload_warm_url' );
        }

        $done = max( 0, $total - $urls_remaining );

        // (2.5.0 / M2) Display-only completion — no write from a GET.
        if ( 'running' === $status && 0 === $urls_remaining && $total > 0 ) {
            $status = 'done';
        }

        return rest_ensure_response( array(
            'status'        => $status,
            'total'         => $total,
            'done'          => $done,
            'paused_reason' => (string) get_transient( 'easyopt_cache_preload_paused_reason' ),
            'version'       => (int) get_option( 'easyopt_state_version', 0 ),
        ) );
    }

    /* ── CSS ──────────────────────────────────────────────────────── */

    public static function clear_used_css() {
        if ( ! class_exists( 'EasyOpt_Unused_CSS' ) ) {
            return new WP_Error( 'easyopt_no_css', 'Unused CSS module not loaded.', array( 'status' => 500 ) );
        }
        EasyOpt_Unused_CSS::clear_used_css_only();
        return rest_ensure_response( array( 'success' => true, 'message' => __( 'Used CSS cache cleared.', 'easy-optimizer' ) ) );
    }

    public static function clear_lcp_data() {
        if ( ! class_exists( 'EasyOpt_LCP' ) ) {
            return new WP_Error( 'easyopt_no_lcp', 'LCP module not loaded.', array( 'status' => 500 ) );
        }
        // Explicit admin action → full reset (table + the page-cache rows that
        // carry baked-in preload tags). This is the destructive path the new
        // settings-tab "Clear all LCP data" button calls; the per-beacon
        // on_lcp_saved() path is intentionally NON-destructive.
        EasyOpt_LCP::clear_all();
        return rest_ensure_response( array( 'success' => true, 'message' => __( 'All LCP data cleared.', 'easy-optimizer' ) ) );
    }

    public static function clear_fonts_data() {
        if ( ! class_exists( 'EasyOpt_Fonts' ) ) {
            return new WP_Error( 'easyopt_no_fonts', 'Fonts module not loaded.', array( 'status' => 500 ) );
        }
        $n = (int) EasyOpt_Fonts::clear_all_fonts_data();
        return rest_ensure_response( array(
            'success' => true,
            'message' => sprintf(
                /* translators: %d = number of collected-font records cleared */
                _n( 'Cleared collected fonts for %d page type.', 'Cleared collected fonts for %d page types.', $n, 'easy-optimizer' ),
                $n
            ),
        ) );
    }

    /* ── Cloudflare ───────────────────────────────────────────────── */

    public static function cf_test( $request ) {
        if ( ! class_exists( 'EasyOpt_Cloudflare' ) ) {
            return new WP_Error( 'easyopt_no_cf', 'Cloudflare module not loaded.', array( 'status' => 500 ) );
        }

        // Accept inline credentials so the user can test BEFORE saving.
        $token   = $request->get_param( 'token' );
        $zone_id = $request->get_param( 'zone_id' );

        if ( $token && $zone_id ) {
            $result = EasyOpt_Cloudflare::api_verify_with( $token, $zone_id );
        } else {
            $result = EasyOpt_Cloudflare::api_verify();
        }

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $name = isset( $result['result']['name'] ) ? (string) $result['result']['name'] : '';
        $plan = isset( $result['result']['plan']['name'] ) ? (string) $result['result']['plan']['name'] : '';

        return rest_ensure_response( array(
            'success' => true,
            'message' => sprintf(
                __( 'Connected to %1$s (%2$s plan).', 'easy-optimizer' ),
                $name ?: 'Cloudflare',
                $plan ?: '—'
            ),
            'name' => $name,
            'plan' => $plan,
        ) );
    }

    public static function cf_purge() {
        if ( ! class_exists( 'EasyOpt_Cloudflare' ) ) {
            return new WP_Error( 'easyopt_no_cf', 'Cloudflare module not loaded.', array( 'status' => 500 ) );
        }
        $result = EasyOpt_Cloudflare::purge_all();
        return rest_ensure_response( array(
            'success' => (bool) $result,
            'message' => $result
                ? __( 'Cloudflare cache purged.', 'easy-optimizer' )
                : __( 'Cloudflare purge failed. Check your API token and Zone ID.', 'easy-optimizer' ),
        ) );
    }

    /* ── Database ─────────────────────────────────────────────────── */

    public static function db_run( $request ) {
        if ( ! class_exists( 'EasyOpt_Database' ) ) {
            return new WP_Error( 'easyopt_no_db', 'Database module not loaded.', array( 'status' => 500 ) );
        }

        $task       = $request->get_param( 'task' ) ?: 'all';
        $session_id = $request->get_param( 'session_id' ) ?: (string) time();
        $session_id = preg_replace( '/[^0-9]/', '', $session_id );
        if ( '' === $session_id ) {
            $session_id = (string) time();
        }

        // Run all enabled tasks (same logic as the old ajax_run handler).
        if ( '' === $task || 'all' === $task ) {
            $report  = EasyOpt_Database::run_enabled( 20 );
            $pending = (array) get_option( 'easyopt_db_pending', array() );
            return rest_ensure_response( array(
                'report'     => $report,
                'counts'     => EasyOpt_Database::counts(),
                'done'       => empty( $pending ),
                'session_id' => $session_id,
            ) );
        }

        // Single-task run.
        $tasks = EasyOpt_Database::tasks();
        if ( ! array_key_exists( $task, $tasks ) ) {
            return new WP_Error( 'easyopt_unknown_task', __( 'Unknown task.', 'easy-optimizer' ), array( 'status' => 400 ) );
        }

        $offset = (int) $request->get_param( 'offset' );
        $r = EasyOpt_Database::run_task( $task, $offset, 20, $session_id );
        update_option( 'easyopt_db_last_run', time(), false );

        return rest_ensure_response( array(
            'task'        => $task,
            'processed'   => (int) $r['processed'],
            'done'        => (bool) $r['done'],
            'next_offset' => (int) $r['next_offset'],
            'snapshot'    => (string) $r['snapshot'],
            'session_id'  => $session_id,
            'counts'      => EasyOpt_Database::counts(),
        ) );
    }

    public static function db_counts() {
        if ( ! class_exists( 'EasyOpt_Database' ) ) {
            return rest_ensure_response( array() );
        }
        return rest_ensure_response( EasyOpt_Database::counts() );
    }

    public static function db_snapshots() {
        if ( ! class_exists( 'EasyOpt_DB_Snapshot' ) ) {
            return rest_ensure_response( array( 'snapshots' => array(), 'retention' => 14 ) );
        }
        return rest_ensure_response( array(
            'snapshots' => EasyOpt_DB_Snapshot::list_all(),
            'retention' => (int) EasyOpt_Config::get( 'db_snapshot_retention_days', EasyOpt_DB_Snapshot::DEFAULT_RETENTION ),
        ) );
    }

    public static function db_snapshot_restore( $request ) {
        if ( ! class_exists( 'EasyOpt_DB_Snapshot' ) ) {
            return new WP_Error( 'easyopt_no_snap', 'Snapshot module not loaded.', array( 'status' => 500 ) );
        }
        $basename = $request->get_param( 'basename' );
        $offset   = (int) $request->get_param( 'offset' );
        return rest_ensure_response( EasyOpt_DB_Snapshot::restore_chunk( $basename, $offset ) );
    }

    public static function db_snapshot_delete( $request ) {
        if ( ! class_exists( 'EasyOpt_DB_Snapshot' ) ) {
            return new WP_Error( 'easyopt_no_snap', 'Snapshot module not loaded.', array( 'status' => 500 ) );
        }
        $basename = $request->get_param( 'basename' );
        $result   = EasyOpt_DB_Snapshot::delete( $basename );
        return rest_ensure_response( array( 'success' => $result ) );
    }

    /* ── Autoload Health ────────────────────────────────────────── */

    /**
     * Core WP options that must stay autoloaded. Disabling autoload on
     * these will break WordPress or cause severe performance regressions
     * (they'd be fetched via individual DB queries on every page load).
     */
    private static $protected_autoload_options = array(
        'siteurl', 'home', 'blogname', 'blogdescription', 'users_can_register',
        'admin_email', 'start_of_week', 'use_balanceTags', 'use_smilies',
        'require_name_email', 'comments_notify', 'posts_per_rss',
        'rss_use_excerpt', 'mailserver_url', 'mailserver_login',
        'mailserver_pass', 'mailserver_port', 'default_category',
        'default_comment_status', 'default_ping_status', 'default_pingback_flag',
        'posts_per_page', 'date_format', 'time_format', 'links_updated_date_format',
        'comment_moderation', 'moderation_notify', 'permalink_structure',
        'rewrite_rules', 'hack_file', 'blog_charset', 'moderation_keys',
        'active_plugins', 'category_base', 'ping_sites', 'comment_max_links',
        'gmt_offset', 'default_email_category', 'template', 'stylesheet',
        'comment_registration', 'html_type', 'default_role',
        'db_version', 'uploads_use_yearmonth_folders', 'upload_path',
        'blog_public', 'default_link_category', 'show_on_front',
        'tag_base', 'show_avatars', 'avatar_rating', 'upload_url_path',
        'thumbnail_size_w', 'thumbnail_size_h', 'thumbnail_crop',
        'medium_size_w', 'medium_size_h', 'avatar_default',
        'large_size_w', 'large_size_h', 'links_recently_updated_prepend',
        'links_recently_updated_append', 'links_recently_updated_time',
        'comment_whitelist', 'moderation_keys', 'dismissed_update_core',
        'page_on_front', 'page_for_posts', 'page_for_privacy_policy',
        'timezone_string', 'WPLANG', 'wp_user_roles',
        'widget_block', 'sidebars_widgets',
        'cron', 'uninstall_plugins', 'auto_update_core_dev',
        'auto_update_core_minor', 'auto_update_core_major',
        'wp_page_for_privacy_policy', 'show_comments_cookies_opt_in',
        'site_icon', 'current_theme', 'stylesheet_root', 'template_root',
        'theme_switched', 'nonce_key', 'nonce_salt',
    );

    /**
     * Return autoload stats: total size in bytes, option count, and
     * the top 20 largest autoloaded options with name, size, and
     * a human-readable label. Each option includes a `protected` flag
     * so the UI can warn or block toggles on core WP options.
     */
    public static function db_autoload() {
        global $wpdb;

        // (2.3.3) WP 6.6+ stores autoload as 'on'/'off'/'auto-on'/'auto-off'
        // ('yes'/'no' remain as legacy values). Match every autoloaded
        // variant so the dashboard numbers stay correct on modern WP.
        $auto_in = "( 'yes', 'on', 'auto-on', 'auto' )";

        $total = (int) $wpdb->get_var(
            "SELECT SUM( LENGTH( option_value ) ) FROM {$wpdb->options} WHERE autoload IN {$auto_in}"
        );

        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload IN {$auto_in}"
        );

        $top = $wpdb->get_results(
            "SELECT option_name, LENGTH( option_value ) AS size
             FROM {$wpdb->options}
             WHERE autoload IN {$auto_in}
             ORDER BY size DESC
             LIMIT 20",
            ARRAY_A
        );

        if ( is_array( $top ) ) {
            foreach ( $top as &$row ) {
                $row['size']       = (int) $row['size'];
                $row['size_human'] = size_format( $row['size'], 1 );
                $row['protected']  = in_array( $row['option_name'], self::$protected_autoload_options, true );
                $row['autoload']   = 'yes';
            }
            unset( $row );
        } else {
            $top = array();
        }

        // Include options the user previously disabled so they stay visible.
        $disabled_names = get_option( 'easyopt_disabled_autoload', array() );
        if ( is_array( $disabled_names ) && ! empty( $disabled_names ) ) {
            // Filter out any that happen to be in the top-20 already (shouldn't be, but safe).
            $top_names  = array_column( $top, 'option_name' );
            $to_include = array_diff( $disabled_names, $top_names );

            if ( ! empty( $to_include ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $to_include ), '%s' ) );
                $disabled_rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT option_name, LENGTH( option_value ) AS size, autoload
                         FROM {$wpdb->options}
                         WHERE option_name IN ($placeholders)
                         ORDER BY size DESC",
                        ...$to_include
                    ),
                    ARRAY_A
                );
                if ( is_array( $disabled_rows ) ) {
                    foreach ( $disabled_rows as &$row ) {
                        $row['size']       = (int) $row['size'];
                        $row['size_human'] = size_format( $row['size'], 1 );
                        $row['protected']  = false;
                        // (2.3.3 fix) Normalise to the UI contract. WP 6.6+
                        // stores 'off'/'auto-off' when autoload is disabled
                        // via wp_set_option_autoload(); the dashboard checks
                        // for the literal 'no' to show the "(disabled)" tag
                        // and the Enable button. Without this, a disabled
                        // option looked stuck with no way to re-enable it.
                        $row['autoload'] = in_array( (string) $row['autoload'], array( 'no', 'off', 'auto-off' ), true ) ? 'no' : 'yes';
                    }
                    unset( $row );
                    $top = array_merge( $top, $disabled_rows );
                }
            }
        }

        return rest_ensure_response( array(
            'total_bytes' => $total,
            'total_human' => size_format( $total, 1 ),
            'count'       => $count,
            'top'         => $top,
        ) );
    }

    /**
     * Toggle autoload yes ↔ no for a single wp_options row.
     * Protected core options are rejected.
     */
    public static function db_autoload_toggle( $request ) {
        global $wpdb;

        $option_name = sanitize_text_field( $request->get_param( 'option_name' ) );
        $autoload    = $request->get_param( 'autoload' ); // 'yes' or 'no'

        if ( '' === $option_name ) {
            return new WP_Error( 'easyopt_missing_param', 'option_name is required.', array( 'status' => 400 ) );
        }
        if ( ! in_array( $autoload, array( 'yes', 'no' ), true ) ) {
            return new WP_Error( 'easyopt_invalid_param', 'autoload must be "yes" or "no".', array( 'status' => 400 ) );
        }

        // Block changes to core WP options.
        if ( in_array( $option_name, self::$protected_autoload_options, true ) ) {
            return new WP_Error(
                'easyopt_protected_option',
                __( 'This is a core WordPress option and cannot be changed.', 'easy-optimizer' ),
                array( 'status' => 403 )
            );
        }

        // Verify the option actually exists.
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
            $option_name
        ) );
        if ( ! $exists ) {
            return new WP_Error( 'easyopt_not_found', 'Option not found.', array( 'status' => 404 ) );
        }

        // (2.3.3) Prefer the core API (WP 6.4+). wp_set_option_autoload()
        // writes the values core actually uses on this WP version ('on'/'off'
        // on 6.6+, 'yes'/'no' before) AND keeps the alloptions cache coherent
        // — the old direct $wpdb->update wrote literal 'yes'/'no', which 6.6+
        // tolerates but treats as legacy, and bypassed core's cache handling.
        if ( function_exists( 'wp_set_option_autoload' ) ) {
            wp_set_option_autoload( $option_name, ( 'yes' === $autoload ) );
        } else {
            $wpdb->update(
                $wpdb->options,
                array( 'autoload' => $autoload ),
                array( 'option_name' => $option_name ),
                array( '%s' ),
                array( '%s' )
            );
        }

        // Track disabled options so they remain visible in the UI.
        $disabled = get_option( 'easyopt_disabled_autoload', array() );
        if ( ! is_array( $disabled ) ) {
            $disabled = array();
        }
        if ( 'no' === $autoload ) {
            $disabled[] = $option_name;
            $disabled   = array_unique( $disabled );
        } else {
            $disabled = array_diff( $disabled, array( $option_name ) );
        }
        update_option( 'easyopt_disabled_autoload', array_values( $disabled ), false );

        // Flush the alloptions cache so WP picks up the change immediately.
        wp_cache_delete( 'alloptions', 'options' );

        return rest_ensure_response( array(
            'success'     => true,
            'option_name' => $option_name,
            'autoload'    => $autoload,
        ) );
    }

    /* ── Cron Event Manager ─────────────────────────────────────── */

    /**
     * List all scheduled cron events.
     */
    public static function cron_events() {
        $crons  = _get_cron_array();
        $events = array();

        if ( ! is_array( $crons ) ) {
            return rest_ensure_response( array( 'events' => array() ) );
        }

        $schedules = wp_get_schedules();

        foreach ( $crons as $timestamp => $hooks ) {
            foreach ( $hooks as $hook => $details ) {
                foreach ( $details as $hash => $info ) {
                    $schedule_name = isset( $info['schedule'] ) ? $info['schedule'] : false;
                    $interval      = '';
                    if ( $schedule_name && isset( $schedules[ $schedule_name ] ) ) {
                        $interval = $schedules[ $schedule_name ]['display'];
                    } elseif ( $schedule_name ) {
                        $interval = $schedule_name;
                    } else {
                        $interval = __( 'One-time', 'easy-optimizer' );
                    }

                    $events[] = array(
                        'hook'         => $hook,
                        'hash'         => $hash,
                        'timestamp'    => (int) $timestamp,
                        // (2.5.3) wp_date converts the Unix timestamp into the
                        // SITE timezone. The previous date_i18n() call rendered
                        // Unix timestamps as UTC — "time is wrong" reports.
                        'next_run'     => wp_date( 'Y-m-d H:i:s', (int) $timestamp ),
                        'overdue'      => ( (int) $timestamp < time() - 2 * MINUTE_IN_SECONDS ),
                        'schedule'     => $schedule_name ? $schedule_name : 'once',
                        'interval'     => $interval,
                        'args'         => isset( $info['args'] ) ? $info['args'] : array(),
                    );
                }
            }
        }

        // Sort by next run time.
        usort( $events, function ( $a, $b ) {
            return $a['timestamp'] - $b['timestamp'];
        } );

        $last_seen = (int) get_option( 'easyopt_last_cron_seen', 0 );
        return rest_ensure_response( array(
            'events'         => $events,
            'total'          => count( $events ),
            'wp_cron'        => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
            'now'            => wp_date( 'Y-m-d H:i:s' ),
            'last_cron_seen' => $last_seen ? wp_date( 'Y-m-d H:i:s', $last_seen ) : '',
            'last_cron_ago'  => $last_seen ? human_time_diff( $last_seen ) : '',
        ) );
    }

    /**
     * Run a specific cron event immediately.
     */
    public static function cron_run( $request ) {
        $hook = sanitize_text_field( $request->get_param( 'hook' ) );
        $hash = sanitize_text_field( $request->get_param( 'hash' ) );

        if ( '' === $hook ) {
            return new WP_Error( 'easyopt_missing_param', 'hook is required.', array( 'status' => 400 ) );
        }

        // Find the event in the cron array.
        $crons     = _get_cron_array();
        $found     = false;
        $found_ts  = 0;
        $schedule  = '';
        $args      = array();

        if ( is_array( $crons ) ) {
            foreach ( $crons as $timestamp => $hooks ) {
                if ( isset( $hooks[ $hook ][ $hash ] ) ) {
                    $args     = $hooks[ $hook ][ $hash ]['args'];
                    $schedule = isset( $hooks[ $hook ][ $hash ]['schedule'] ) ? (string) $hooks[ $hook ][ $hash ]['schedule'] : '';
                    $found_ts = (int) $timestamp;
                    $found    = true;
                    break;
                }
            }
        }

        if ( ! $found ) {
            return new WP_Error( 'easyopt_not_found', 'Cron event not found.', array( 'status' => 404 ) );
        }

        // Audit trail: manually running a cron hook fires whatever is attached
        // to it, so record who triggered which hook before executing. This is
        // a guard rail around an admin-only, powerful action (it can only run
        // an event that is already scheduled).
        if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
            $current = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
            $who     = ( $current && ! empty( $current->user_login ) ) ? $current->user_login : ( 'user#' . get_current_user_id() );
            EasyOpt_Debug_Log::info( 'cron', sprintf( 'Manual cron run of "%s" triggered by %s.', $hook, $who ) );
        }

        // Execute the cron hook.
        do_action_ref_array( $hook, $args );

        // (2.5.3) Consume + reschedule the occurrence we just ran. Without
        // this, an overdue event kept its past Next Run after a manual run —
        // the list looked unchanged, so "Run does nothing". Recurring events
        // move to now + interval; one-time events are removed (they ran).
        wp_unschedule_event( $found_ts, $hook, $args );
        $next = '';
        if ( '' !== $schedule ) {
            $schedules = wp_get_schedules();
            $interval  = isset( $schedules[ $schedule ]['interval'] ) ? (int) $schedules[ $schedule ]['interval'] : 0;
            if ( $interval > 0 ) {
                wp_schedule_event( time() + $interval, $schedule, $hook, $args );
                $next = wp_date( 'Y-m-d H:i:s', time() + $interval );
            }
        }

        return rest_ensure_response( array(
            'success'  => true,
            'hook'     => $hook,
            'next_run' => $next,
        ) );
    }

    /**
     * Delete a specific cron event.
     */
    public static function cron_delete( $request ) {
        $hook      = sanitize_text_field( $request->get_param( 'hook' ) );
        $hash      = sanitize_text_field( $request->get_param( 'hash' ) );
        $timestamp = (int) $request->get_param( 'timestamp' );

        if ( '' === $hook || 0 === $timestamp ) {
            return new WP_Error( 'easyopt_missing_param', 'hook and timestamp are required.', array( 'status' => 400 ) );
        }

        // Find the event args.
        $crons = _get_cron_array();
        $args  = array();

        if ( is_array( $crons ) && isset( $crons[ $timestamp ][ $hook ][ $hash ] ) ) {
            $args = $crons[ $timestamp ][ $hook ][ $hash ]['args'];
        }

        $result = wp_unschedule_event( $timestamp, $hook, $args );

        return rest_ensure_response( array(
            'success' => ( false !== $result ),
            'hook'    => $hook,
        ) );
    }

    /* ── Elementor CDN ───────────────────────────────────────────── */

    /* ── FluxCDN (2.5.3) ─────────────────────────────────────────── */

    /**
     * Connect FluxCDN. Accepts EITHER a SureCart license key (production) or
     * a raw fpcdn_… credentials key (dev/testing). Both paths end with a live
     * signed-probe through the CDN before anything is unlocked.
     * Body: { api_key: string, endpoint?: string, skip_probe?: bool }
     */
    /** Re-probe the CDN with the currently stored credentials (non-destructive). */
    public static function fluxcdn_test() {
        if ( ! class_exists( 'EasyOpt_CDN' ) ) {
            return new WP_Error( 'easyopt_no_cdn', 'CDN module not loaded.', array( 'status' => 500 ) );
        }
        $key = (string) EasyOpt_Config::get( 'fluxcdn_api_key', '' );
        if ( '' === $key ) {
            return rest_ensure_response( array( 'success' => false, 'message' => __( 'Not connected yet.', 'easy-optimizer' ) ) );
        }
        $r = EasyOpt_CDN::verify_key( $key, (string) EasyOpt_Config::get( 'fluxcdn_endpoint', '' ), false );
        EasyOpt_Config::set( 'easyopt_fluxcdn_probe_state', isset( $r['state'] ) ? $r['state'] : 'warming' ); // H9
        // verify_key is now non-blocking; report the served/warming state.
        $served = empty( $r['ssl_prov'] );
        return rest_ensure_response( array(
            'success' => true,
            'served'  => $served,
            'message' => $served
                ? __( 'Working — a test image was delivered through your CDN.', 'easy-optimizer' )
                : __( 'Your CDN is still warming up (SSL/first image). Try again in a minute.', 'easy-optimizer' ),
        ) );
    }

    /** Cached bandwidth usage for the connected license (panel usage bar). */
    public static function fluxcdn_usage( $request = null ) {
        if ( class_exists( 'EasyOpt_Tracker' ) ) {
            EasyOpt_Tracker::smartimg_tab_viewed(); // daily-throttled inside
        }
        $force = $request && (bool) $request->get_param( 'force' );
        $u = class_exists( 'EasyOpt_CDN' ) ? EasyOpt_CDN::usage_status( $force ) : null;
        $u = is_array( $u ) ? $u : array( 'used_bytes' => 0, 'quota_bytes' => 0 );
        $u['probe_state'] = (string) EasyOpt_Config::get( 'fluxcdn_probe_state', '' ); // H9
        return rest_ensure_response( $u );
    }

    public static function fluxcdn_connect( $request ) {
        if ( ! class_exists( 'EasyOpt_CDN' ) ) {
            return new WP_Error( 'easyopt_no_cdn', 'CDN module not loaded.', array( 'status' => 500 ) );
        }
        $input      = sanitize_text_field( (string) $request->get_param( 'api_key' ) );
        $endpoint   = ''; // filled from provisioning (per-customer zone hostname)
        $skip_probe = (bool) $request->get_param( 'skip_probe' );

        if ( class_exists( 'EasyOpt_Tracker' ) ) {
            EasyOpt_Tracker::smartimg_connect_attempted(
                null === EasyOpt_CDN::parse_key( $input ) ? 'license' : 'key'
            );
        }

        $license_key   = '';
        $activation_id = '';
        $internal_key  = $input;

        if ( null === EasyOpt_CDN::parse_key( $input ) ) {
            // Not an fpcdn_ credentials key → treat as a SureCart license key.
            $sc = EasyOpt_CDN::sc_connect( $input );
            if ( empty( $sc['success'] ) ) {
                EasyOpt_Config::set( 'easyopt_fluxcdn_verified', 0 );
                return rest_ensure_response( array(
                    'success' => false,
                    'code'    => isset( $sc['code'] ) ? $sc['code'] : '',
                    'message' => $sc['message'],
                ) );
            }
            $license_key   = $input;
            $activation_id = $sc['activation_id'];
            $internal_key  = $sc['internal_key'];
            if ( '' === $endpoint && ! empty( $sc['endpoint'] ) ) {
                $endpoint = $sc['endpoint'];
            }
        }

        // Both modes: verify the credentials end-to-end with a signed probe.
        // (For license mode the internal key was just provisioned; store it
        // temporarily so verify_key's probe signs with it.)
        EasyOpt_Config::set( 'easyopt_fluxcdn_license_key', $license_key );
        $result = EasyOpt_CDN::verify_key( $internal_key, $endpoint, $skip_probe );
        if ( empty( $result['success'] ) ) {
            EasyOpt_Config::set( 'easyopt_fluxcdn_verified', 0 );
            EasyOpt_Config::set( 'easyopt_fluxcdn_license_key', '' );
            if ( '' !== $activation_id ) {
                EasyOpt_CDN::sc_release_activation_by_id( $activation_id );
            }
            return rest_ensure_response( array( 'success' => false, 'message' => $result['message'] ) );
        }

        EasyOpt_Config::set( 'easyopt_fluxcdn_api_key', $internal_key );
        EasyOpt_Config::set( 'easyopt_fluxcdn_endpoint', $endpoint );
        EasyOpt_Config::set( 'easyopt_fluxcdn_activation_id', $activation_id );
        EasyOpt_Config::set( 'easyopt_fluxcdn_verified', 1 );
        // H4: bind the connection to this exact site URL; H9: persist the
        // probe diagnosis so the panel can show real state after reload.
        EasyOpt_Config::set( 'easyopt_fluxcdn_fingerprint', esc_url_raw( get_site_url() ) );
        EasyOpt_Config::set( 'easyopt_fluxcdn_probe_state', isset( $result['state'] ) ? $result['state'] : 'warming' );
        EasyOpt_Config::set( 'easyopt_fluxcdn_transform_sig', EasyOpt_CDN::transform_signature() );
        if ( class_exists( 'EasyOpt_Tracker' ) ) {
            EasyOpt_Tracker::smartimg_state( isset( $result['state'] ) ? $result['state'] : 'warming' );
        }
        // (2.5.3) Gate closed? Schedule quick re-probes so delivery starts
        // automatically the moment SSL finishes (typically 1-3 min).
        if ( 'ok' !== (string) EasyOpt_Config::get( 'fluxcdn_probe_state', '' ) ) {
            wp_schedule_single_event( time() + 90, 'easyopt_fluxcdn_reprobe' );
        }
        delete_option( 'easyopt_fluxcdn_revoked' );
        delete_option( 'easyopt_fluxcdn_moved' );

        if ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'clear_all' ) ) {
            EasyOpt_Cache::clear_all();
        }
        if ( (int) EasyOpt_Config::get( 'elementor_bg_cdn', 0 ) ) {
            EasyOpt_CDN::regenerate_elementor_css(); // pick up CDN URLs via the filter
        }

        return rest_ensure_response( array(
            'success'  => true,
            'account'  => isset( $result['account'] ) ? $result['account'] : '',
            'ssl_prov' => ! empty( $result['ssl_prov'] ),
            'message' => ! empty( $result['ssl_prov'] ) ? $result['message'] : ( '' !== $license_key
                ? __( 'License activated — FluxCDN is connected and a test image was served through the CDN.', 'easy-optimizer' )
                : $result['message'] ),
        ) );
    }

    /** Remove the stored key, release the license activation, lock rewriting. */
    public static function fluxcdn_disconnect() {
        if ( class_exists( 'EasyOpt_Tracker' ) ) {
            EasyOpt_Tracker::smartimg_disconnected();
        }
        if ( class_exists( 'EasyOpt_CDN' ) ) {
            EasyOpt_CDN::deprovision();          // H3: park the zone server-side (origin → redirector + purge)
            EasyOpt_CDN::sc_release_activation(); // M2: checked, queued on failure
        }
        EasyOpt_Config::set( 'easyopt_fluxcdn_api_key', '' );
        EasyOpt_Config::set( 'easyopt_fluxcdn_license_key', '' );
        EasyOpt_Config::set( 'easyopt_fluxcdn_activation_id', '' );
        EasyOpt_Config::set( 'easyopt_fluxcdn_verified', 0 );
        EasyOpt_Config::set( 'easyopt_fluxcdn_endpoint', '' );
        EasyOpt_Config::set( 'easyopt_fluxcdn_fingerprint', '' );
        EasyOpt_Config::set( 'easyopt_fluxcdn_probe_state', '' );
        delete_option( 'easyopt_fluxcdn_revoked' );
        delete_option( 'easyopt_fluxcdn_moved' );
        delete_transient( 'easyopt_fluxcdn_usage' );
        delete_transient( 'easyopt_fluxcdn_admin_lic_check' );
        // M10: purge CDN URLs baked into generated CSS. Used CSS regenerates
        // on next visit; Elementor CSS regenerates without the CDN filter.
        if ( class_exists( 'EasyOpt_Unused_CSS' ) && method_exists( 'EasyOpt_Unused_CSS', 'clear_used_css_only' ) ) {
            EasyOpt_Unused_CSS::clear_used_css_only();
        }
        if ( class_exists( 'EasyOpt_CDN' ) && (int) EasyOpt_Config::get( 'elementor_bg_cdn', 0 ) ) {
            EasyOpt_CDN::regenerate_elementor_css();
        }
        // Stored LCP rows point at the (now dead) CDN URLs — drop them so the
        // preload re-learns origin URLs instead of preloading a parked zone.
        if ( class_exists( 'EasyOpt_LCP' ) && method_exists( 'EasyOpt_LCP', 'clear_all' ) ) {
            EasyOpt_LCP::clear_all();
        }
        if ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'clear_all' ) ) {
            EasyOpt_Cache::clear_all();
        }
        return rest_ensure_response( array(
            'success' => true,
            'message' => __( 'Disconnected. The license seat was released and images are now served from your own server. Your CDN zone is preserved and will be reused if you reconnect.', 'easy-optimizer' ),
        ) );
    }

    public static function elementor_apply_cdn() {
        if ( ! class_exists( 'EasyOpt_CDN' ) ) {
            return new WP_Error( 'easyopt_no_cdn', 'CDN module not loaded.', array( 'status' => 500 ) );
        }
        // (2.5.6) CDN URLs are injected at CSS generation time via the
        // elementor/files/css/selectors filter; "applying" = regenerating.
        $ok = EasyOpt_CDN::regenerate_elementor_css();
        return rest_ensure_response( array(
            'success' => (bool) $ok,
            'message' => $ok
                ? __( 'Elementor CSS regeneration triggered — background images will use the CDN as files rebuild.', 'easy-optimizer' )
                : __( 'Elementor is not active.', 'easy-optimizer' ),
        ) );
    }

    public static function elementor_regenerate_css() {
        if ( ! class_exists( 'EasyOpt_CDN' ) ) {
            return new WP_Error( 'easyopt_no_cdn', 'CDN module not loaded.', array( 'status' => 500 ) );
        }
        $regenerated = EasyOpt_CDN::regenerate_elementor_css();
        if ( ! $regenerated ) {
            return rest_ensure_response( array(
                'success' => false,
                'message' => __( 'Elementor not found or regeneration failed.', 'easy-optimizer' ),
            ) );
        }

        // If CDN is enabled, apply CDN to the freshly generated files.
        $cdn_result = array( 'processed' => 0 );
        if ( (int) EasyOpt_Config::get( 'elementor_bg_cdn', 0 ) && (int) EasyOpt_Config::get( 'img_opt', 0 ) ) {
            // Small delay to let Elementor finish writing files.
            usleep( 500000 );
            $cdn_result = EasyOpt_CDN::process_elementor_css();
        }

        return rest_ensure_response( array(
            'success'   => true,
            'processed' => $cdn_result['processed'],
            'message'   => __( 'Elementor CSS regenerated.', 'easy-optimizer' )
                . ( $cdn_result['processed'] > 0
                    ? ' ' . sprintf( __( '%d file(s) updated with CDN.', 'easy-optimizer' ), $cdn_result['processed'] )
                    : '' ),
        ) );
    }

    /* ── Settings Import (2.1.0) ─────────────────────────────────── */

    /**
     * Setting keys holding live third-party credentials.
     *
     * Never exported, never accepted on import. Mirrors SECRET_KEYS in
     * react-src/app.tsx — keep the two lists in step.
     *
     * @since 2.5.7
     * @return string[]
     */
    public static function secret_setting_keys() {
        return (array) apply_filters( 'easyopt_secret_setting_keys', array(
            'easyopt_cf_api_token',
            'easyopt_oc_password',
            'easyopt_oc_username',
            'easyopt_fluxcdn_api_key',
            'easyopt_fluxcdn_activation_id',
            // (2.6.1) The FluxPress cloud credentials. These moved into their
            // own non-autoloaded option, but they are still declared in the
            // registry, so get_all() returns their DEFAULT — an empty string —
            // rather than omitting them. Without them on this list an export
            // carried easyopt_cloud_key:"" and the import wrote that empty
            // value straight through to put_secrets(), which treats empty as
            // "delete": key, salt, token and the customer's LICENCE KEY were
            // silently destroyed, while cloud_active/account/endpoint survived
            // and left the panel claiming a connection that no longer existed.
            //
            // Listed here they are neither exported nor accepted on import, so
            // put_secrets() keeps its delete-on-empty behaviour — which
            // disconnect() genuinely relies on to clear credentials.
            'easyopt_cloud_key',
            'easyopt_cloud_salt',
            'easyopt_cloud_token',
            'easyopt_cloud_license_key',
        ) );
    }

    public static function settings_import( $request ) {
        $params   = $request->get_json_params();
        $imported = isset( $params['settings'] ) ? (array) $params['settings'] : array();

        if ( empty( $imported ) ) {
            return rest_ensure_response( array( 'success' => false, 'message' => 'No settings provided.' ) );
        }

        // Validate against the registry — only accept known keys.
        $registry = class_exists( 'EasyOpt_Settings_Registry' )
            ? EasyOpt_Settings_Registry::schema()
            : array();

        // (2.5.7) Credentials are never accepted from an import file. The
        // exporter omits them; enforcing it here too means a hand-edited or
        // third-party file cannot inject a Cloudflare token, a Redis password
        // or a CDN key into this site. Connections are re-entered by hand on
        // the destination, which is the only place they should be typed.
        $secret_keys = self::secret_setting_keys();

        $valid = array();
        foreach ( $imported as $key => $value ) {
            if ( in_array( $key, $secret_keys, true ) ) {
                continue;
            }
            if ( isset( $registry[ $key ] ) ) {
                // Use the schema's own sanitizer so multi-line exclude lists
                // keep their newlines and enum/int fields are validated
                // correctly. sanitize() returns null for unknown keys.
                $clean = EasyOpt_Settings_Registry::sanitize( $key, $value );
                if ( null !== $clean ) {
                    $valid[ $key ] = $clean;
                }
            }
        }

        if ( empty( $valid ) ) {
            return rest_ensure_response( array( 'success' => false, 'message' => 'No valid settings found in import.' ) );
        }

        // (2.3.3) Save through the REAL pipeline. The old direct
        // update_option('easyopt_settings', …) bypassed update_many()'s diff,
        // so `easyopt_settings_saved` never fired — no drop-in regeneration,
        // no .htaccess update, no cache clear, no preload restart. Imported
        // settings silently didn't take effect until the next manual save.
        EasyOpt_Config::update_many( $valid );
        if ( class_exists( 'EasyOpt_Save_Coordinator' ) ) {
            EasyOpt_Save_Coordinator::drain_now();
        }

        // Return the merged settings so the UI can refresh.
        return rest_ensure_response( array(
            'success'  => true,
            'settings' => EasyOpt_Config::get_all(),
            'message'  => sprintf( 'Imported %d settings.', count( $valid ) ),
        ) );
    }

    /* ── Debug Log (2.1.1) ───────────────────────────────────────── */

    public static function debug_log_read() {
        if ( ! class_exists( 'EasyOpt_Debug_Log' ) ) {
            return rest_ensure_response( array( 'entries' => array(), 'counts' => array( 'warnings' => 0, 'errors' => 0, 'total' => 0 ) ) );
        }
        // (2.7.1) Derive counts from the SAME entries the panel renders, in one
        // read, so the header badge can never disagree with the list below it
        // (the old two-read path used different window sizes and drifted).
        $entries = EasyOpt_Debug_Log::read( 200 );
        $warnings = 0;
        $errors   = 0;
        foreach ( $entries as $entry ) {
            if ( 'error' === $entry['level'] ) {
                $errors++;
            } else {
                $warnings++;
            }
        }
        return rest_ensure_response( array(
            'entries' => $entries,
            'counts'  => array( 'warnings' => $warnings, 'errors' => $errors, 'total' => $warnings + $errors ),
        ) );
    }

    public static function debug_log_clear() {
        if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
            EasyOpt_Debug_Log::clear();
        }
        return rest_ensure_response( array( 'success' => true ) );
    }

    /* ── Object Cache (2.4.7) ────────────────────────────────────── */

    public static function objectcache_status() {
        if ( ! class_exists( 'EasyOpt_Object_Cache_Manager' ) ) {
            return rest_ensure_response( array( 'success' => false, 'message' => 'Object cache module unavailable.' ) );
        }
        return rest_ensure_response( array( 'success' => true, 'status' => EasyOpt_Object_Cache_Manager::get_status() ) );
    }

    public static function objectcache_flush() {
        if ( ! class_exists( 'EasyOpt_Object_Cache_Manager' ) ) {
            return rest_ensure_response( array( 'success' => false, 'message' => 'Object cache module unavailable.' ) );
        }
        $ok = EasyOpt_Object_Cache_Manager::flush();
        return rest_ensure_response( array(
            'success' => $ok,
            'message' => $ok ? __( 'Object cache flushed.', 'easy-optimizer' ) : __( 'Nothing to flush, or flush failed.', 'easy-optimizer' ),
            'status'  => EasyOpt_Object_Cache_Manager::get_status(),
        ) );
    }

    public static function objectcache_test( $request ) {
        if ( ! class_exists( 'EasyOpt_Object_Cache_Manager' ) ) {
            return rest_ensure_response( array( 'success' => false, 'message' => 'Object cache module unavailable.' ) );
        }
        $override = array();
        foreach ( array( 'client', 'host', 'port', 'username', 'password', 'database', 'prefix', 'tls' ) as $k ) {
            $v = $request->get_param( $k );
            if ( null !== $v ) {
                $override[ $k ] = ( 'port' === $k || 'database' === $k || 'tls' === $k ) ? (int) $v : sanitize_text_field( (string) $v );
            }
        }
        return rest_ensure_response( EasyOpt_Object_Cache_Manager::test_connection( $override ) );
    }

    /* ── Local images ──────────────────────────── */

    public static function images_stats() {
        if ( ! class_exists( 'EasyOpt_Images' ) ) {
            return new WP_Error( 'easyopt_no_images', 'Image module not loaded.', array( 'status' => 500 ) );
        }
        return rest_ensure_response( array( 'success' => true ) + EasyOpt_Images::stats() );
    }

    public static function images_optimize() {
        if ( ! class_exists( 'EasyOpt_Images' ) ) {
            return new WP_Error( 'easyopt_no_images', 'Image module not loaded.', array( 'status' => 500 ) );
        }
        if ( ! EasyOpt_Images::target_formats() ) {
            return rest_ensure_response( array(
                'success' => false,
                'message' => EasyOpt_Images_Capability::summary(),
            ) );
        }
        $n = (int) EasyOpt_Images::enqueue_library();
        return rest_ensure_response( array(
            'success' => true,
            'queued'  => $n,
            'message' => $n
                ? sprintf(
                    /* translators: %d: number of images queued. */
                    _n( '%d image queued.', '%d images queued.', $n, 'easy-optimizer' ),
                    $n
                )
                : __( 'Every image is already optimized.', 'easy-optimizer' ),
        ) );
    }

    public static function images_restore() {
        if ( ! class_exists( 'EasyOpt_Images' ) ) {
            return new WP_Error( 'easyopt_no_images', 'Image module not loaded.', array( 'status' => 500 ) );
        }
        $n = (int) EasyOpt_Images::restore_library();
        return rest_ensure_response( array(
            'success' => true,
            'queued'  => $n,
            'message' => $n
                ? sprintf(
                    /* translators: %d: number of images queued for restore. */
                    _n( '%d image queued for restore.', '%d images queued for restore.', $n, 'easy-optimizer' ),
                    $n
                )
                : __( 'Nothing to restore.', 'easy-optimizer' ),
        ) );
    }
}
