<?php
/**
 * Schema definition for all plugin settings.
 * Defines defaults, types and sanitizers. Does not touch the database.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Settings_Registry {

    /** Cached schema after first build. */
    private static $schema = null;

    /**
     * Return the full schema. Each entry:
     *   'default'   => mixed
     *   'sanitize'  => callable($value): mixed
     *   'type'      => 'int'|'string'|'enum'
     *
     * Built lazily so EasyOpt_Cache::default_mode() (which probes server
     * software) only runs when actually needed.
     *
     * @return array<string, array{default: mixed, sanitize: callable, type: string}>
     */
    public static function schema() {
        if ( null !== self::$schema ) {
            return self::$schema;
        }

        $default_cache_mode = ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'default_mode' ) )
            ? EasyOpt_Cache::default_mode()
            : 'php';

        $int_bool = array( 'sanitize' => 'absint', 'type' => 'int' );
        $str_text = array( 'sanitize' => 'sanitize_text_field', 'type' => 'string' );
        $str_area = array( 'sanitize' => 'sanitize_textarea_field', 'type' => 'string' );

        // Build the schema. Format below mirrors the old register_setting()
        // calls one-for-one so audit is straightforward.
        $s = array();

        // ── Page Cache ───────────────────────────────────────────────────
        $s['easyopt_cache']                    = array( 'default' => 1 ) + $int_bool;
        $s['easyopt_cache_mode']               = array( 'default' => $default_cache_mode, 'sanitize' => array( __CLASS__, 'sanitize_cache_mode' ), 'type' => 'enum' );
        $s['easyopt_cache_ttl']                = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_cache_separate_mobile']    = array( 'default' => 1 ) + $int_bool;
        $s['easyopt_cache_logged_in']          = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_cache_preload']            = array( 'default' => 1 ) + $int_bool;
        $s['easyopt_cache_preload_speed']      = array( 'default' => 'gentle', 'sanitize' => array( __CLASS__, 'sanitize_preload_speed' ), 'type' => 'enum' );
        $s['easyopt_cache_browser_caching']    = array( 'default' => 1 ) + $int_bool;
        $s['easyopt_cache_gzip']               = array( 'default' => 1 ) + $int_bool;
        // (2.5.5) Automatic preconnect / dns-prefetch for cross-origin assets.
        $s['easyopt_preconnect']               = array( 'default' => 1 ) + $int_bool;
        $s['easyopt_cache_exclude_urls']       = array( 'default' => '' ) + $str_area;
        $s['easyopt_cache_exclude_cookies']    = array( 'default' => '' ) + $str_area;
        $s['easyopt_cache_strip_query_params'] = array( 'default' => '' ) + $str_area;
        // (2.6.1) Counterpart to the above: names whose VALUE gets its own
        // cache file instead of being collapsed onto one. Empty by default.
        $s['easyopt_cache_query_strings']      = array( 'default' => '' ) + $str_area;

        // ── Cloudflare ───────────────────────────────────────────────────
        $s['easyopt_cf_enabled']        = array( 'default' => 0 )      + $int_bool;
        $s['easyopt_cf_api_token']      = array( 'default' => '' )     + $str_text;
        $s['easyopt_cf_zone_id']        = array( 'default' => '' )     + $str_text;
        $s['easyopt_cf_purge_strategy'] = array( 'default' => 'host', 'sanitize' => array( __CLASS__, 'sanitize_cf_purge_strategy' ), 'type' => 'enum' );

        // ── Object Cache (2.4.7) ─────────────────────────────────────────
        // Master toggle installs/removes our Redis object-cache drop-in.
        // Connection keys are the fallback when WP_REDIS_* constants are not
        // defined (constants always win). Empty prefix = auto.
        $s['easyopt_object_cache'] = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_oc_client']    = array( 'default' => 'auto', 'sanitize' => array( __CLASS__, 'sanitize_oc_client' ), 'type' => 'enum' );
        $s['easyopt_oc_host']      = array( 'default' => '127.0.0.1' ) + $str_text;
        $s['easyopt_oc_port']      = array( 'default' => 6379 ) + $int_bool;
        $s['easyopt_oc_username']  = array( 'default' => '' ) + $str_text; // Redis 6 ACL user (optional)
        $s['easyopt_oc_password']  = array( 'default' => '' ) + $str_text;
        $s['easyopt_oc_tls']       = array( 'default' => 0 ) + $int_bool;  // TLS (rediss://) connections
        $s['easyopt_oc_database']  = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_oc_prefix']    = array( 'default' => '' ) + $str_text;

        // ── Database ─────────────────────────────────────────────────────
        $s['easyopt_db_post_revisions']         = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_db_auto_drafts']            = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_db_trashed_posts']          = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_db_spam_comments']          = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_db_trashed_comments']       = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_db_expired_transients']     = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_db_all_transients']         = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_db_optimize_tables']        = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_db_cron_enabled']           = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_db_schedule']               = array( 'default' => 'weekly', 'sanitize' => array( __CLASS__, 'sanitize_db_schedule' ), 'type' => 'enum' );
        $s['easyopt_db_snapshot_enabled']       = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_db_snapshot_retention_days']= array( 'default' => 14 ) + $int_bool;
        $s['easyopt_db_chunk_size']             = array( 'default' => 500 ) + $int_bool;
        $s['easyopt_db_optimize_max_size_mb']   = array( 'default' => 100 ) + $int_bool;

        // ── Bloat ────────────────────────────────────────────────────────
        foreach ( array(
            'easyopt_bloat_emojis', 'easyopt_bloat_embeds', 'easyopt_bloat_xmlrpc',
            'easyopt_bloat_jquery_migrate', 'easyopt_bloat_wp_version', 'easyopt_bloat_rsd_wlw',
            'easyopt_bloat_shortlinks', 'easyopt_bloat_rss_feeds', 'easyopt_bloat_self_pingbacks',
            'easyopt_bloat_rest_api_logged_out', 'easyopt_bloat_heartbeat',
            'easyopt_bloat_wc_cart_fragments', 'easyopt_bloat_app_passwords',
            'easyopt_bloat_dashicons', 'easyopt_bloat_block_css',
        ) as $k ) {
            $s[ $k ] = array( 'default' => 0 ) + $int_bool;
        }

        // Heartbeat sub-settings (visible when bloat_heartbeat is on).
        $s['easyopt_heartbeat_location']  = array( 'default' => 'allow_admin', 'sanitize' => array( __CLASS__, 'sanitize_heartbeat_location' ), 'type' => 'enum' );
        $s['easyopt_heartbeat_frequency'] = array( 'default' => 60 )            + $int_bool;

        // ── FluxPress static asset delivery ───────────────────
        // Off by default even when connected. Moving every stylesheet and
        // script to a third-party host is not something to start doing to a
        // live site without the owner asking for it.
        $s['easyopt_cloud_assets']         = array( 'default' => 0 ) + $int_bool;
        // Marker: Unused CSS was turned on via the Smart Images (cloud)
        // add-on rather than the Optimization tab. Only this flag emits
        // the delayed-stylesheet reveal script, so enabling Unused CSS the
        // ordinary way is unaffected.
        $s['easyopt_cloud_unused_css']     = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_cloud_assets_exclude'] = array( 'default' => '' ) + $str_area;

        // ── Local image optimization ──────────────────────────
        // Off by default: it writes files into uploads/, which is not a
        // thing to start doing to someone's site without them asking.
        $s['easyopt_images']                  = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_images_auto']             = array( 'default' => 1 ) + $int_bool;
        $s['easyopt_images_avif']             = array( 'default' => 1 ) + $int_bool;
        $s['easyopt_images_webp']             = array( 'default' => 1 ) + $int_bool;
        $s['easyopt_images_picture']          = array( 'default' => 1 ) + $int_bool;
        // AVIF holds quality at a lower number than WebP does; 55/78 are
        // visually comparable, not a typo.
        $s['easyopt_images_quality_avif']     = array( 'default' => 55 ) + $int_bool;
        $s['easyopt_images_quality_webp']     = array( 'default' => 78 ) + $int_bool;
        $s['easyopt_images_quality_jpeg']     = array( 'default' => 82 ) + $int_bool;
        // 0 = never resize. Drives big_image_size_threshold when set.
        $s['easyopt_images_max_dimension']    = array( 'default' => 0 )  + $int_bool;
        // Modifies the uploaded file itself, so it backs up first and stays
        // off unless the user explicitly opts in.
        $s['easyopt_images_resize_originals'] = array( 'default' => 0 ) + $int_bool;
        // Format we ask core's own encoder to emit ('' = leave core alone).
        $s['easyopt_images_core_format']      = array( 'default' => '', 'sanitize' => array( __CLASS__, 'sanitize_image_format' ), 'type' => 'enum' );

        // ── Debug logging ────────────────────────────────────────────────
        // Errors default ON (catch failures out of the box); warnings default
        // ON (they surface the cache/preload loop signals). Both toggleable.
        $s['easyopt_log_errors']   = array( 'default' => 1 ) + $int_bool;
        $s['easyopt_log_warnings'] = array( 'default' => 0 ) + $int_bool;

        // WP-Cron throttle (0 = default, 120/300/600 = seconds between spawns).
        $s['easyopt_cron_frequency']      = array( 'default' => 0 )             + $int_bool;
        $s['easyopt_cron_throttle']       = array( 'default' => 0 )             + $int_bool;

        // ── Delay JS ─────────────────────────────────────────────────────
        $s['easyopt_delay_js']                = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_delay_js_method']         = array( 'default' => 'delay', 'sanitize' => array( __CLASS__, 'sanitize_delay_js_method' ), 'type' => 'enum' );
        $s['easyopt_delay_js_exclude']        = array( 'default' => '' ) + $str_area;
        $s['easyopt_delay_js_exclude_urls']   = array( 'default' => '' ) + $str_area;
        $s['easyopt_delay_js_exclude_jquery'] = array( 'default' => 1 ) + $int_bool;
        $s['easyopt_delay_js_include_inline'] = array( 'default' => '' ) + $str_area;

        // ── Minify (2.3.0) ───────────────────────────────────────────────
        // Independent of Delay JS / Unused CSS. Each minifies LOCAL files only
        // and reuses the matching exclude field (JS → delay_js_exclude,
        // CSS → unused_css_exclude_stylesheets). Off by default.
        $s['easyopt_minify_css'] = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_minify_js']  = array( 'default' => 0 ) + $int_bool;

        // ── Unused CSS ───────────────────────────────────────────────────
        $s['easyopt_unused_css']                      = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_unused_css_method']               = array( 'default' => 'inline', 'sanitize' => array( __CLASS__, 'sanitize_unused_css_method' ), 'type' => 'enum' );
        $s['easyopt_unused_css_behavior']             = array( 'default' => 'delayed', 'sanitize' => array( __CLASS__, 'sanitize_unused_css_behavior' ), 'type' => 'enum' );
        $s['easyopt_unused_css_post_types_only']      = array( 'default' => 1 ) + $int_bool;
        $s['easyopt_unused_css_exclude_selectors']    = array( 'default' => '' ) + $str_area;
        $s['easyopt_unused_css_exclude_stylesheets']  = array( 'default' => '' ) + $str_area;
        // Page-builder stylesheets that are ALREADY page-specific. These are
        // folded into Used CSS whole, without selector filtering — see
        // EasyOpt_Unused_CSS::get_passthrough_stylesheets() for why.
        $s['easyopt_unused_css_passthrough_stylesheets'] = array( 'default' => '' ) + $str_area;
        $s['easyopt_unused_css_exclude_urls']         = array( 'default' => '' ) + $str_area;
        $s['easyopt_unused_css_include_inline']       = array( 'default' => '' ) + $str_area;

        // ── Lazy Load ────────────────────────────────────────────────────
        $s['easyopt_lazy_images']            = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_lazy_iframes']           = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_lazy_videos']            = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_add_missing_dims']       = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_dims_exclude']           = array( 'default' => '' ) + $str_area;
        $s['easyopt_lazyload_exclude']       = array( 'default' => '' ) + $str_area;
        // (2.5.5) Browser-native lazy loading. On by default: keeps the real
        // src/srcset/sizes on the element so the preload scanner can find
        // them, and drops the JS runtime on most pages. Turn off to restore
        // the placeholder-swap behaviour (needed only by themes that style
        // the .lazyload / .lazyloaded classes).
        $s['easyopt_lazy_native']           = array( 'default' => 1 ) + $int_bool;
        $s['easyopt_lazyload_exclude_first'] = array( 'default' => 1 ) + $int_bool;

        // ── Fonts ────────────────────────────────────────────────────────
        $s['easyopt_font_display_swap'] = array( 'default' => 1 ) + $int_bool;
        $s['easyopt_preload_fonts']     = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_lazyload_fonts']    = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_fonts_exclude']     = array( 'default' => '' ) + $str_area;
        $s['easyopt_fonts_exclude_urls'] = array( 'default' => '' ) + $str_area;

        // ── Image CDN ────────────────────────────────────────────────────
        $s['easyopt_img_opt']            = array( 'default' => 0 ) + $int_bool;
        // Review prompt state. dismissed_at is a unix timestamp driving the
        // 90-day snooze; done is set once the user follows through.
        $s['easyopt_review_dismissed_at'] = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_review_done']         = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_image_exclude']      = array( 'default' => '' ) + $str_area;
        $s['easyopt_elementor_bg_cdn']   = array( 'default' => 0 ) + $int_bool;

        // FluxCDN (2.5.3) — imgproxy-backed image CDN. api_key/verified are
        // written by the /fluxcdn/connect REST endpoint; verified gates rewriting.
        $s['easyopt_fluxcdn_api_key']        = array( 'default' => '' ) + $str_text;
        $s['easyopt_fluxcdn_verified']       = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_fluxcdn_endpoint']       = array( 'default' => '', 'sanitize' => array( __CLASS__, 'sanitize_fluxcdn_endpoint' ), 'type' => 'string' );
        $s['easyopt_fluxcdn_format']         = array( 'default' => 'auto', 'sanitize' => array( __CLASS__, 'sanitize_fluxcdn_format' ), 'type' => 'enum' );
        $s['easyopt_fluxcdn_quality']        = array( 'default' => 0, 'sanitize' => array( __CLASS__, 'sanitize_fluxcdn_quality' ), 'type' => 'int' );
        $s['easyopt_fluxcdn_max_width']      = array( 'default' => 2560, 'sanitize' => array( __CLASS__, 'sanitize_fluxcdn_max_width' ), 'type' => 'int' );
        $s['easyopt_fluxcdn_srcset_resize']  = array( 'default' => 1 ) + $int_bool;
        $s['easyopt_fluxcdn_error_fallback'] = array( 'default' => 1 ) + $int_bool;
        $s['easyopt_fluxcdn_license_key']    = array( 'default' => '' ) + $str_text;
        $s['easyopt_fluxcdn_activation_id']  = array( 'default' => '' ) + $str_text;
        // 2.5.3 hardening: site binding (H4), probe diagnosis (H9),
        // transform-change purge signature (M9), opt-in query-URL rewriting (M5).
        $s['easyopt_fluxcdn_fingerprint']    = array( 'default' => '' ) + $str_text;
        $s['easyopt_fluxcdn_probe_state']    = array( 'default' => '' ) + $str_text;
        $s['easyopt_fluxcdn_transform_sig']  = array( 'default' => '' ) + $str_text;
        $s['easyopt_fluxcdn_query_urls']     = array( 'default' => 0 ) + $int_bool;

        // FluxPress Adaptive Images v3 (shared endpoint). Written by the
        // /cloud/* REST routes. Additions only — no existing default moves.
        // The signed-URL map is NOT here: it is derived data with a TTL and
        // lives in the standalone option easyopt_cloud_map so it never rides
        // along in EasyOpt_Config::get_all() on every admin render.
        $s['easyopt_cloud_account']          = array( 'default' => '' ) + $str_text;
        $s['easyopt_cloud_token']            = array( 'default' => '' ) + $str_text;
        $s['easyopt_cloud_endpoint']         = array( 'default' => '', 'sanitize' => array( __CLASS__, 'sanitize_fluxcdn_endpoint' ), 'type' => 'string' );
        $s['easyopt_cloud_plan']             = array( 'default' => '' ) + $str_text;
        $s['easyopt_cloud_key']              = array( 'default' => '' ) + $str_text;
        $s['easyopt_cloud_salt']             = array( 'default' => '' ) + $str_text;
        $s['easyopt_cloud_license_key']      = array( 'default' => '' ) + $str_text;
        $s['easyopt_cloud_fingerprint']      = array( 'default' => '' ) + $str_text;
        $s['easyopt_cloud_activation_id']    = array( 'default' => '' ) + $str_text;
        $s['easyopt_cloud_active']           = array( 'default' => 0 ) + $int_bool;
        // These two omitted 'sanitize' until 2.7.1. Registry::sanitize() falls
        // through to `return $value` when the callback is missing, so a string
        // or array from the save endpoint reached the option unchanged and the
        // allowance maths then ran on it. $int_bool supplies absint like every
        // other int in this schema.
        $s['easyopt_cloud_images_used']      = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_cloud_image_cap']        = array( 'default' => 0 ) + $int_bool;

        // ── Prefetch Pages ───────────────────────────────────────────────
        // Keys keep their historical `instant_` names so an upgrade from 2.6.6
        // carries every configured value across untouched.
        //
        // `instant_throttle` and `instant_limit` were REMOVED in 2.6.7. They
        // configured the old JavaScript request queue (requests/second and a
        // per-page-view prefetch cap). The engine no longer has a queue: the
        // browser schedules speculation and enforces its own budgets, so both
        // keys described work that does not happen any more. They are deleted
        // from storage by easyopt_maybe_install() rather than left behind as
        // settings the UI cannot show and the code never reads.
        $s['easyopt_instant_preload']                   = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_instant_preload_exclude_urls']      = array( 'default' => '' ) + $str_area;
        $s['easyopt_instant_preload_exclude_selectors'] = array( 'default' => '' ) + $str_area;
        // (2.6.7) Prerender defaults OFF. A prerender is a complete page load
        // — scripts, subresources, paint — and measurement showed it never
        // completing in the window a pointerdown gives it: every navigation was
        // served by the prefetch instead. It stays available for sites that
        // want it, but it is no longer a cost every install pays by default.
        $s['easyopt_instant_prerender']                 = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_instant_eagerness']                 = array( 'default' => 'moderate', 'sanitize' => array( __CLASS__, 'sanitize_eagerness' ), 'type' => 'enum' );

        // ── LCP Preload ──────────────────────────────────────────────────
        $s['easyopt_lcp_preload']      = array( 'default' => 1 ) + $int_bool;
        $s['easyopt_lcp_exclude_urls'] = array( 'default' => '' ) + $str_area;

        // ── Accessibility ────────────────────────────────────────────────
        foreach ( array(
            'easyopt_a11y_inputs', 'easyopt_a11y_links', 'easyopt_a11y_buttons',
            'easyopt_a11y_viewport', 'easyopt_a11y_role_elements', 'easyopt_a11y_iframes',
            'easyopt_a11y_progressbar', 'easyopt_a11y_tabindex',
        ) as $k ) {
            $s[ $k ] = array( 'default' => 0 ) + $int_bool;
        }

        // ── SEO ──────────────────────────────────────────────────────────
        $s['easyopt_seo_crawlable_links'] = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_seo_image_alts']      = array( 'default' => 0 ) + $int_bool;

        // ── Backend Analyzer (2.4.0) — all OFF-by-default master switch.
        // Sub-analyzers arm only when the master is on AND a profile run is
        // explicitly triggered; zero overhead otherwise.
        $s['easyopt_backend_analyzer']           = array( 'default' => 0 ) + $int_bool;
        $s['easyopt_backend_callbacks']          = array( 'default' => 1 ) + $int_bool;
        $s['easyopt_backend_queries']            = array( 'default' => 1 ) + $int_bool;
        $s['easyopt_backend_query_threshold_ms'] = array( 'default' => 50 ) + $int_bool;

        // ── PSI benchmark (2.4.0)
        $s['easyopt_psi_api_key'] = array( 'default' => '' ) + $str_text;
        $s['easyopt_psi_auto']    = array( 'default' => 1 ) + $int_bool;

        // ── Settings ─────────────────────────────────────────────────────
        // Default OFF (preserve data on uninstall). This now matches BOTH the
        // uninstall.php fallback and every preset, so the toggle the dashboard
        // shows always reflects the real uninstall behaviour. Users who want a
        // clean removal simply turn it on.
        $s['easyopt_delete_on_uninstall'] = array( 'default' => 0 ) + $int_bool;

        self::$schema = $s;
        return $s;
    }

    /**
     * Whitelist of recognised setting keys. Anything not in this list is
     * either runtime state (separate option) or an unknown key (rejected
     * at the save endpoint).
     *
     * @return string[]
     */
    public static function keys() {
        return array_keys( self::schema() );
    }

    /**
     * Full defaults map. Used by EasyOpt_Config when a key is queried but
     * the settings array has no entry for it (e.g. legacy code asking
     * about an option that was removed in this version).
     *
     * @return array<string, mixed>
     */
    public static function defaults() {
        $out = array();
        foreach ( self::schema() as $k => $meta ) {
            $out[ $k ] = $meta['default'];
        }
        return $out;
    }

    /**
     * Sanitise a single incoming value for a known key. Unknown keys
     * return null — the save endpoint MUST drop those before persisting.
     */
    public static function sanitize( $key, $value ) {
        $schema = self::schema();
        if ( ! isset( $schema[ $key ] ) ) {
            return null;
        }
        $fn = $schema[ $key ]['sanitize'];
        return is_callable( $fn ) ? call_user_func( $fn, $value ) : $value;
    }

    /**
     * 1.6.2 — Whitelist for Speculation Rules eagerness. Any other value
     * silently snaps back to 'moderate'.
     */
    /** FluxCDN output format: auto (browser-negotiated), webp, or avif. */
    public static function sanitize_fluxcdn_format( $v ) {
        $v = is_string( $v ) ? strtolower( trim( $v ) ) : '';
        return in_array( $v, array( 'auto', 'webp', 'avif' ), true ) ? $v : 'auto';
    }

    /** FluxCDN quality: 0 = Smart (container per-format defaults), else 30–100. */
    public static function sanitize_fluxcdn_quality( $v ) {
        $v = absint( $v );
        return 0 === $v ? 0 : max( 30, min( 100, $v ) );
    }

    /** FluxCDN max width cap in px: 0 disables, otherwise 320–4096. */
    public static function sanitize_fluxcdn_max_width( $v ) {
        $v = absint( $v );
        return 0 === $v ? 0 : max( 320, min( 4096, $v ) );
    }

    /** FluxCDN endpoint: https URL or empty (empty = built-in default). */
    public static function sanitize_fluxcdn_endpoint( $v ) {
        $v = is_string( $v ) ? untrailingslashit( trim( $v ) ) : '';
        if ( '' === $v ) {
            return '';
        }
        $v = esc_url_raw( $v, array( 'https' ) );
        return is_string( $v ) ? untrailingslashit( $v ) : '';
    }

    public static function sanitize_eagerness( $v ) {
        $v = is_string( $v ) ? strtolower( trim( $v ) ) : '';
        // (2.6.7) 'immediate' is accepted on the way in — 2.6.6 could store it
        // — and folded to 'eager'. On a document-level speculation rule
        // 'immediate' means "fetch every matching link on this page now", which
        // on a 50-link archive is 50 uninvited document fetches. WordPress Core
        // forbids it for the same reason.
        if ( 'immediate' === $v ) {
            $v = 'eager';
        }
        return in_array( $v, array( 'conservative', 'moderate', 'eager' ), true )
            ? $v : 'moderate';
    }

    /**
     * Cache mode must be one of: 'htaccess', 'php'. Anything else snaps
     * to the server-appropriate default.
     */
    /**
     * Format handed to core's own encoder via image_editor_output_format.
     * '' means "leave core's default alone", which is the safe state.
     */
    public static function sanitize_image_format( $v ) {
        $v = is_string( $v ) ? strtolower( trim( $v ) ) : '';
        // AVIF is deliberately NOT accepted here. Storing the upload ITSELF as
        // AVIF leaves the browsers that can't decode it with a broken image and
        // no fallback: the stored file is the avif, and the <picture> delivery
        // path only wraps jpeg/png/gif sources. AVIF is still delivered safely
        // as a sibling variant without changing the upload. WebP is universally
        // supported, so it stays.
        return 'webp' === $v ? 'webp' : '';
    }

    public static function sanitize_cache_mode( $v ) {
        $v = is_string( $v ) ? strtolower( trim( $v ) ) : '';
        if ( in_array( $v, array( 'htaccess', 'php' ), true ) ) {
            return $v;
        }
        return ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'default_mode' ) )
            ? EasyOpt_Cache::default_mode()
            : 'php';
    }

    /**
     * Generic enum guard: lower-cases/trims the value and returns it only when
     * it is one of $allowed; otherwise returns $default. Used by the small
     * choice-list settings below so an unrecognised value (bad import, manual
     * API call) can never be persisted — it snaps to a known-good default
     * instead, exactly like cache_mode and eagerness already do.
     */
    private static function sanitize_enum( $v, array $allowed, $default ) {
        $v = is_string( $v ) ? strtolower( trim( $v ) ) : '';
        return in_array( $v, $allowed, true ) ? $v : $default;
    }

    /** Used CSS delivery: 'inline' (critical CSS in-page) or 'file' (cached stylesheet). */
    public static function sanitize_unused_css_method( $v ) {
        return self::sanitize_enum( $v, array( 'inline', 'file' ), 'inline' );
    }

    /** What to do with original stylesheets after building Used CSS. */
    public static function sanitize_unused_css_behavior( $v ) {
        return self::sanitize_enum( $v, array( 'delayed', 'async', 'remove' ), 'delayed' );
    }

    /** JavaScript optimisation method: 'delay' (until interaction) or 'defer' (native). */
    public static function sanitize_delay_js_method( $v ) {
        return self::sanitize_enum( $v, array( 'delay', 'defer' ), 'delay' );
    }

    /** Cloudflare purge strategy: 'host' (tag, falls back to everything) or 'everything'. */
    public static function sanitize_cf_purge_strategy( $v ) {
        return self::sanitize_enum( $v, array( 'host', 'everything' ), 'host' );
    }

    /** Object cache client preference. 'auto' picks Relay → PhpRedis → Predis. */
    public static function sanitize_oc_client( $v ) {
        return self::sanitize_enum( $v, array( 'auto', 'relay', 'phpredis', 'predis' ), 'auto' );
    }

    /**
     * Preload speed. The fallback MUST match the schema default ('gentle') —
     * they disagreed until 2.7.1, so any value that failed validation silently
     * moved the site onto a faster preload than the one it was set to.
     */
    public static function sanitize_preload_speed( $v ) {
        return self::sanitize_enum( $v, array( 'gentle', 'balanced', 'turbo' ), 'gentle' );
    }

    /** Heartbeat allowed location. Mirrors the dashboard's option set exactly. */
    public static function sanitize_heartbeat_location( $v ) {
        return self::sanitize_enum( $v, array( 'everywhere', 'allow_admin', 'allow_editor', 'disabled' ), 'allow_admin' );
    }

    /** Scheduled database-cleanup frequency. */
    public static function sanitize_db_schedule( $v ) {
        return self::sanitize_enum( $v, array( 'daily', 'weekly', 'monthly' ), 'weekly' );
    }

    /**
     * Settings whose change should not be autoloaded on every page request.
     * These mirror the old `ensure_no_autoload` list — for the new single-
     * row architecture this is informational only (we autoload the whole
     * array as one row regardless), but is used by callers that want to
     * know whether a setting is admin/cron-only.
     *
     * @return string[]
     */
    public static function admin_only_keys() {
        return array(
            'easyopt_cf_enabled', 'easyopt_cf_api_token', 'easyopt_cf_zone_id',
            'easyopt_cf_purge_strategy',
            'easyopt_db_post_revisions', 'easyopt_db_auto_drafts',
            'easyopt_db_trashed_posts', 'easyopt_db_spam_comments',
            'easyopt_db_trashed_comments', 'easyopt_db_expired_transients',
            'easyopt_db_all_transients', 'easyopt_db_optimize_tables',
            'easyopt_db_cron_enabled', 'easyopt_db_schedule',
            'easyopt_db_snapshot_enabled', 'easyopt_db_snapshot_retention_days',
            'easyopt_db_chunk_size', 'easyopt_db_optimize_max_size_mb',
            'easyopt_bloat_app_passwords',
            'easyopt_object_cache', 'easyopt_oc_client', 'easyopt_oc_host',
            'easyopt_oc_port', 'easyopt_oc_username', 'easyopt_oc_password',
            'easyopt_oc_database', 'easyopt_oc_prefix', 'easyopt_oc_tls',
        );
    }
}
