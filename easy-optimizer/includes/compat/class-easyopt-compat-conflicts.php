<?php
/**
 * Detects other optimisation/caching plugins and reports overlaps — but ONLY
 * for jobs Easy Optimizer is actually doing, and (where a probe exists) only
 * when the other plugin's feature is genuinely switched ON.
 *
 * ── What changed in 2.6.5 ────────────────────────────────────────────────
 *
 * 1. PAGE CACHE IS NOW A CAPABILITY PROBE, NOT A SLUG LIST.
 *    2.6.4 matched 27 plugin slugs with is_plugin_active(). That answered
 *    "is this plugin installed?", never "is it caching?". It also caught
 *    plugins that don't compete for the same hook at all, most damagingly
 *    SiteGround Speed Optimizer — whose cache is server-level NGINX, and
 *    which Easy Optimizer PURGES THROUGH (EasyOpt_Hosting::is_siteground()
 *    and do_purge_all_siteground() both depend on sg_cachepress_purge_
 *    everything() existing). The notice offered a "Deactivate SiteGround
 *    Speed Optimizer" link: following Easy Optimizer's own advice broke
 *    Easy Optimizer's own SiteGround purge, leaving stale server-cached
 *    pages after every publish. SG Optimizer ships auto-installed on every
 *    SiteGround account, so that was a default-path bug for a whole host.
 *
 *    We now ask the only question that matters — who owns
 *    wp-content/advanced-cache.php, and is their code still on disk? — via
 *    EasyOpt_Advanced_Cache::dropin_owner_is_live(). It needs no per-plugin
 *    knowledge, catches caches we've never heard of, and cannot go stale.
 *    Server / host / edge caches (SiteGround NGINX, Cloudways Varnish,
 *    Kinsta, Cloudflare APO) are handled by EasyOpt_Hosting::active_layers()
 *    and reported as INFORMATION on the dashboard, never as a conflict.
 *
 * 2. TWO TIERS, HONESTLY LABELLED.
 *    • PROVEN  — we read the other plugin's own settings and can state that
 *                a feature is on. Earns an admin notice.
 *    • SOFT    — slug-only. The plugin is installed and does overlapping
 *                work, but we cannot prove it is switched on. Reported
 *                in-app on the dashboard's Compatibility card. Never a
 *                notice: an interruption should cost a claim we can defend.
 *
 *    2.6.4 had proven detection for exactly two plugins (Autoptimize,
 *    Jetpack Boost) and wired it only into the wizard's pre-flight, while
 *    the runtime notice stayed slug-only — so an Autoptimize installed with
 *    CSS and JS both OFF still produced "These optimizations are running
 *    twice." The correct detector existed in this file and the notice
 *    didn't call it.
 *
 * 3. THE OVERLAP MAP WAS STALE. Verified against current sources (see
 *    docs/COMPAT-PROBE-SCHEMAS.md): Jetpack Boost has grown from 2 modules
 *    to 15 and now ships its own page cache, image CDN, LCP module, cache
 *    preload and speculation rules. Perfmatters carries remove_unused_css
 *    and minify — 2.6.4 modelled it as delay-JS only. Image-CDN collisions
 *    (two plugins rewriting the same <img> URL — a visible, every-page
 *    failure) were gated on lazy-load and so were invisible whenever Smart
 *    Images was on but lazy load was off.
 *
 * 4. THE SCAN IS CACHED. It used to run on every admin_init: ~45
 *    is_plugin_active() calls on every screen in wp-admin, to detect a state
 *    that changes a few times a year. Now a 12-hour transient, invalidated
 *    on plugin activate / deactivate / update and on our own settings save,
 *    plus a manual "Re-check" on the Compatibility card.
 *
 * Notices (3, down from 4 — "Some features are off on purpose" moved to the
 * Compatibility card, where its own docblock always said it belonged):
 *  • Page-cache conflict (warning) — another plugin owns the drop-in AND our
 *                                    page cache is on.
 *  • Drop-in hijacked   (error)    — we installed it, it isn't ours any more.
 *  • Feature overlap    (warning)  — a PROVEN-on feature duplicates one of
 *                                    ours that is switched on.
 *
 * @package EasyOptimizer
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Compat_Conflicts {

    /** Cross-request scan cache. */
    const SCAN_TRANSIENT = 'easyopt_compat_scan';

    /** Scan lifetime. The answer changes when a plugin is installed, removed
     *  or updated — all of which we hook — so this is only a backstop for the
     *  one case we cannot hook: the user changing a setting inside the OTHER
     *  plugin. The Compatibility card carries a Re-check button for that. */
    const SCAN_TTL = 12 * HOUR_IN_SECONDS;

    /** Bump to invalidate every cached scan on upgrade. Raised to 3 when the
     *  dropin entry gained its `known` flag — a cached v2 scan has no such key,
     *  so owner_known would read false and every notice would fall back to the
     *  generic "Go to Plugins" button until the transient expired. */
    const SCAN_VERSION = 3;

    /** @var array|null Per-request memo. */
    private static $scan = null;

    public static function init() {

        // Invalidation is NOT admin-only: plugins are activated by WP-CLI and
        // updated by cron. Registering these outside is_admin() costs four
        // add_action() calls and prevents a stale scan surviving a headless
        // deploy.
        add_action( 'activated_plugin',          array( __CLASS__, 'flush_scan' ) );
        add_action( 'deactivated_plugin',        array( __CLASS__, 'flush_scan' ) );
        add_action( 'upgrader_process_complete', array( __CLASS__, 'flush_scan' ) );
        add_action( 'easyopt_settings_saved',    array( __CLASS__, 'flush_scan' ) );

        // (2.6.5) The case the TTL alone could not cover: the user changes a
        // setting inside the OTHER plugin. None of the hooks above fire, so a
        // 12-hour-old scan kept insisting WP Rocket was delaying JavaScript
        // after the user had switched it off, and stayed silent after they
        // enabled Combine CSS in SiteGround. Every plugin we probe stores its
        // settings in wp_options, so watch the specific rows our probes read
        // and drop the cache the moment one of them moves.
        add_action( 'updated_option', array( __CLASS__, 'maybe_flush_for_option' ) );
        add_action( 'added_option',   array( __CLASS__, 'maybe_flush_for_option' ) );
        add_action( 'deleted_option', array( __CLASS__, 'maybe_flush_for_option' ) );
        // Hummingbird and Smush store theirs as site options.
        add_action( 'update_site_option', array( __CLASS__, 'maybe_flush_for_option' ) );

        // Also before the is_admin() guard: REST requests are not admin
        // requests. This is the same trap that stopped detect() from ever
        // running on the wizard's apply path before 2.6.0.
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

        if ( ! is_admin() ) {
            return;
        }

        // (2.6.5) No admin_init/detect() pass. The scan is lazy — nothing runs
        // until a notice actually asks for it.
        add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
    }

    /* ══════════════════════════════════════════════════════════════════
     *  Capability vocabulary
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * Third-party capability => the Easy Optimizer settings it duplicates.
     *
     * A capability is only ever an overlap when OUR corresponding feature is
     * also on — running alongside a plugin that does something we don't do is
     * not a conflict, it's a working site.
     *
     * SEVERITY decides which surface a finding reaches:
     *
     *   breaking — running twice can visibly damage the page: two minifiers
     *              concatenating in different orders, two critical-CSS engines,
     *              two script deferrers, two lazy-loaders fighting over the
     *              same <img>. These earn the admin notice, whose words are
     *              "can slow pages down or break scripts and styles".
     *   minor    — running twice is wasteful, not harmful. Two cache preloaders
     *              just crawl the site twice; two font handlers cost a repaint.
     *              Reported on the Compatibility card, never as a notice.
     *
     * Without this split a duplicated preloader — which breaks nothing — sat in
     * the same red-flag list as two lazy-loaders, and the notice's own wording
     * was untrue for half its contents.
     *
     * @return array<string,array{keys:string[],label:string,reason:string}>
     */
    private static function capability_map() {
        return array(
            'page_cache' => array(
                'severity' => 'breaking',
                'keys'   => array( 'easyopt_cache' ),
                'label'  => __( 'Page Cache', 'easy-optimizer' ),
                'reason' => __( 'also caches your pages', 'easy-optimizer' ),
            ),
            'preload' => array(
                'severity' => 'minor',
                'keys'   => array( 'easyopt_cache_preload' ),
                'label'  => __( 'Cache Preload', 'easy-optimizer' ),
                'reason' => __( 'also preloads the cache', 'easy-optimizer' ),
            ),
            'minify_css' => array(
                'severity' => 'breaking',
                'keys'   => array( 'easyopt_minify_css' ),
                'label'  => __( 'Minify CSS', 'easy-optimizer' ),
                'reason' => __( 'also minifies CSS', 'easy-optimizer' ),
            ),
            'minify_js' => array(
                'severity' => 'breaking',
                'keys'   => array( 'easyopt_minify_js' ),
                'label'  => __( 'Minify JavaScript', 'easy-optimizer' ),
                'reason' => __( 'also minifies JavaScript', 'easy-optimizer' ),
            ),
            'unused_css' => array(
                'severity' => 'breaking',
                'keys'   => array( 'easyopt_unused_css' ),
                'label'  => __( 'Remove Unused CSS', 'easy-optimizer' ),
                'reason' => __( 'also strips or defers unused CSS', 'easy-optimizer' ),
            ),
            'delay_js' => array(
                'severity' => 'breaking',
                'keys'   => array( 'easyopt_delay_js' ),
                'label'  => __( 'Delay JavaScript', 'easy-optimizer' ),
                'reason' => __( 'also delays or defers JavaScript', 'easy-optimizer' ),
            ),
            'lazy' => array(
                'severity' => 'breaking',
                'keys'   => array( 'easyopt_lazy_images', 'easyopt_lazy_iframes', 'easyopt_lazy_videos' ),
                'label'  => __( 'Lazy Load', 'easy-optimizer' ),
                'reason' => __( 'also lazy-loads media', 'easy-optimizer' ),
            ),
            // (2.6.5) New. Two plugins rewriting the same <img> URL produces
            // broken or double-proxied images on every page — the most visible
            // failure in this whole list, and the one 2.6.4 could not see.
            'imgcdn' => array(
                'severity' => 'breaking',
                'keys'   => array( 'easyopt_img_opt' ),
                'label'  => __( 'Smart Images', 'easy-optimizer' ),
                'reason' => __( 'also serves your images from its own CDN', 'easy-optimizer' ),
            ),
            'fonts' => array(
                'severity' => 'minor',
                'keys'   => array( 'easyopt_font_display_swap', 'easyopt_preload_fonts', 'easyopt_lazyload_fonts' ),
                'label'  => __( 'Font Optimization', 'easy-optimizer' ),
                'reason' => __( 'also handles web font loading', 'easy-optimizer' ),
            ),
            'lcp' => array(
                'severity' => 'minor',
                'keys'   => array( 'easyopt_lcp_preload' ),
                'label'  => __( 'LCP Preload', 'easy-optimizer' ),
                'reason' => __( 'also prioritises the LCP image', 'easy-optimizer' ),
            ),
            'prefetch' => array(
                'severity' => 'minor',
                'keys'   => array( 'easyopt_instant_preload' ),
                'label'  => __( 'Prefetch Pages', 'easy-optimizer' ),
                'reason' => __( 'also prefetches links', 'easy-optimizer' ),
            ),
        );
    }

    /**
     * Every plugin we know about.
     *
     *   file   — plugin file for is_plugin_active().
     *   probe  — method on this class returning capability => bool, or null
     *            when we have no way to read their settings (soft tier).
     *   claims — capabilities the plugin is CAPABLE of. For probed plugins
     *            this is documentation; for unprobed ones it IS the finding.
     *   unprobed — capabilities in `claims` that the probe deliberately does
     *            NOT return, each for a stated reason. Once a probe returns an
     *            array, `claims` is never consulted again — so a capability
     *            claimed but not probed is silently unreportable. That hole
     *            swallowed LiteSpeed's page cache, crawler, CDN and font
     *            options entirely. tests/test-compat-registry.php now requires
     *            claims === probe keys + unprobed, so the only way to omit a
     *            capability is to say so here.
     *
     * @return array<string,array{file:string,probe:?string,claims:string[]}>
     */
    private static function registry() {

        $r = array(
            // ── Probed (proven tier) ─────────────────────────────────────
            'WP Rocket' => array(
                'file'   => 'wp-rocket/wp-rocket.php',
                'probe'  => 'probe_wp_rocket',
                'claims' => array( 'page_cache', 'preload', 'minify_css', 'minify_js', 'unused_css', 'delay_js', 'lazy', 'imgcdn', 'fonts' ),
                // WP Rocket's page cache has no off switch — if it is active
                // and owns the drop-in, the drop-in probe has already said so,
                // and the de-duplication would strip this anyway.
                'unprobed' => array( 'page_cache' ),
            ),
            'Perfmatters' => array(
                'file'   => 'perfmatters/perfmatters.php',
                'probe'  => 'probe_perfmatters',
                'claims' => array( 'minify_css', 'minify_js', 'unused_css', 'delay_js', 'lazy', 'fonts', 'prefetch' ),
            ),
            'Autoptimize' => array(
                'file'   => 'autoptimize/autoptimize.php',
                'probe'  => 'probe_autoptimize',
                'claims' => array( 'minify_css', 'minify_js', 'unused_css', 'delay_js', 'lazy', 'imgcdn' ),
            ),
            'Jetpack Boost' => array(
                'file'   => 'jetpack-boost/jetpack-boost.php',
                'probe'  => 'probe_jetpack_boost',
                'claims' => array( 'page_cache', 'preload', 'minify_css', 'minify_js', 'unused_css', 'delay_js', 'imgcdn', 'lcp', 'prefetch' ),
            ),
            'LiteSpeed Cache' => array(
                'file'   => 'litespeed-cache/litespeed-cache.php',
                'probe'  => 'probe_litespeed',
                'claims' => array( 'page_cache', 'preload', 'minify_css', 'minify_js', 'unused_css', 'delay_js', 'lazy', 'imgcdn', 'fonts' ),
            ),
            'SiteGround Speed Optimizer' => array(
                'file'   => 'sg-cachepress/sg-cachepress.php',
                'probe'  => 'probe_siteground',
                'claims' => array( 'minify_css', 'minify_js', 'delay_js', 'lazy', 'imgcdn', 'fonts' ),
            ),
            'WP Fastest Cache' => array(
                'file'   => 'wp-fastest-cache/wpFastestCache.php',
                'probe'  => 'probe_wp_fastest_cache',
                'claims' => array( 'page_cache', 'preload', 'minify_css', 'minify_js', 'delay_js', 'lazy' ),
            ),
            'Hummingbird' => array(
                'file'   => 'hummingbird-performance/wp-hummingbird.php',
                'probe'  => 'probe_hummingbird',
                'claims' => array( 'page_cache', 'minify_css', 'minify_js', 'unused_css', 'delay_js', 'fonts' ),
            ),
            'WP-Optimize' => array(
                'file'   => 'wp-optimize/wp-optimize.php',
                'probe'  => 'probe_wp_optimize',
                'claims' => array( 'page_cache', 'preload', 'minify_css', 'minify_js', 'delay_js' ),
            ),
            'Breeze' => array(
                'file'   => 'breeze/breeze.php',
                'probe'  => 'probe_breeze',
                'claims' => array( 'page_cache', 'preload', 'minify_css', 'minify_js', 'delay_js', 'lazy' ),
            ),
            'Asset CleanUp' => array(
                'file'   => 'wp-asset-clean-up/wpacu.php',
                'probe'  => 'probe_asset_cleanup',
                'claims' => array( 'minify_css', 'minify_js', 'unused_css' ),
                // Asset CleanUp unloads assets with PER-PAGE rules, not a
                // site-wide toggle. There is no boolean that means "this site
                // is removing CSS", so claiming to prove it would be a lie.
                'unprobed' => array( 'unused_css' ),
            ),
            'Optimole' => array(
                'file'   => 'optimole-wp/optimole-wp.php',
                'probe'  => 'probe_optimole',
                'claims' => array( 'lazy', 'imgcdn' ),
            ),
            'Smush' => array(
                'file'   => 'wp-smushit/wp-smush.php',
                'probe'  => 'probe_smush',
                'claims' => array( 'lazy', 'imgcdn' ),
            ),

            // ── Soft tier (no readable settings) ─────────────────────────
            'W3 Total Cache' => array(
                'file'   => 'w3-total-cache/w3-total-cache.php',
                'probe'  => 'probe_w3tc',
                'claims' => array( 'page_cache', 'minify_css', 'minify_js', 'lazy', 'imgcdn' ),
            ),
            'WP Meteor' => array(
                'file'   => 'wp-meteor/wp-meteor.php',
                'probe'  => null,
                'claims' => array( 'delay_js' ),
            ),
            'Flying Scripts' => array(
                'file'   => 'flying-scripts/flying-scripts.php',
                'probe'  => null,
                'claims' => array( 'delay_js' ),
            ),
            'Fast Velocity Minify' => array(
                'file'   => 'fast-velocity-minify/fvm.php',
                'probe'  => null,
                'claims' => array( 'minify_css', 'minify_js', 'delay_js' ),
            ),
            'Lazy Load by WP Rocket' => array(
                'file'   => 'rocket-lazy-load/rocket-lazy-load.php',
                'probe'  => null,
                'claims' => array( 'lazy' ),
            ),
            'a3 Lazy Load' => array(
                'file'   => 'a3-lazy-load/a3-lazy-load.php',
                'probe'  => null,
                'claims' => array( 'lazy' ),
            ),
            'instant.page' => array(
                'file'   => 'instant-page/instantpage.php',
                'probe'  => null,
                'claims' => array( 'prefetch' ),
            ),
            'Flying Pages' => array(
                'file'   => 'flying-pages/flying-pages.php',
                'probe'  => null,
                'claims' => array( 'prefetch' ),
            ),
            'Speculation Rules' => array(
                'file'   => 'speculation-rules/load.php',
                'probe'  => null,
                'claims' => array( 'prefetch' ),
            ),
            'Image Prioritizer' => array(
                'file'   => 'image-prioritizer/load.php',
                'probe'  => null,
                'claims' => array( 'lcp' ),
            ),
            'Optimization Detective' => array(
                'file'   => 'optimization-detective/load.php',
                'probe'  => null,
                'claims' => array( 'lcp' ),
            ),
            'OMGF' => array(
                'file'   => 'host-webfonts-local/host-webfonts-local.php',
                'probe'  => null,
                'claims' => array( 'fonts' ),
            ),
            'Debloat' => array(
                'file'   => 'debloat/debloat.php',
                'probe'  => null,
                'claims' => array( 'minify_css', 'minify_js', 'unused_css', 'delay_js' ),
            ),
            'Merge + Minify + Refresh' => array(
                'file'   => 'merge-minify-refresh/merge-minify-refresh.php',
                'probe'  => null,
                'claims' => array( 'minify_css', 'minify_js' ),
            ),
            'ShortPixel Adaptive Images' => array(
                'file'   => 'shortpixel-adaptive-images/short-pixel-ai.php',
                'probe'  => null,
                'claims' => array( 'lazy', 'imgcdn' ),
            ),
            'NitroPack' => array(
                'file'   => 'nitropack/main.php',
                'probe'  => null,
                'claims' => array( 'page_cache', 'minify_css', 'minify_js', 'unused_css', 'delay_js', 'lazy', 'imgcdn' ),
            ),
            'Swift Performance' => array(
                'file'   => 'swift-performance/performance.php',
                'probe'  => null,
                'claims' => array( 'page_cache', 'minify_css', 'minify_js', 'delay_js', 'lazy' ),
            ),
            'Swift Performance Lite' => array(
                'file'   => 'swift-performance-lite/performance.php',
                'probe'  => null,
                'claims' => array( 'page_cache', 'minify_css', 'minify_js', 'delay_js', 'lazy' ),
            ),
            'Seraphinite Accelerator' => array(
                'file'   => 'seraphinite-accelerator/plugin_root.php',
                'probe'  => null,
                'claims' => array( 'page_cache', 'minify_css', 'minify_js', 'unused_css', 'delay_js', 'lazy' ),
            ),
            '10Web Booster' => array(
                'file'   => 'tenweb-speed-optimizer/tenweb-speed-optimizer.php',
                'probe'  => null,
                'claims' => array( 'page_cache', 'minify_css', 'minify_js', 'unused_css', 'delay_js', 'lazy', 'imgcdn' ),
            ),
            'Powered Cache' => array(
                'file'   => 'powered-cache/powered-cache.php',
                'probe'  => null,
                'claims' => array( 'page_cache', 'minify_css', 'minify_js', 'delay_js', 'lazy' ),
            ),
            'SpeedyCache' => array(
                'file'   => 'speedycache/speedycache.php',
                'probe'  => null,
                'claims' => array( 'page_cache', 'minify_css', 'minify_js', 'delay_js', 'lazy' ),
            ),
        );

        /**
         * Filter the compatibility registry.
         *
         * @since 2.6.5
         * @param array $r label => {file, probe, claims}
         */
        return (array) apply_filters( 'easyopt_compat_registry', $r );
    }

    /* ══════════════════════════════════════════════════════════════════
     *  Probes
     *
     *  Every probe reads ANOTHER plugin's private settings. Contract:
     *    • Never throw — scan() wraps each call, but don't rely on it.
     *    • Fail to FALSE. Under-detecting is a missing notice; over-detecting
     *      is a false accusation that makes users switch off a working
     *      feature. Only one of those is recoverable.
     *    • Prefer the plugin's own public accessor. If they refactor,
     *      function_exists() goes false and we get a DETECTABLE miss instead
     *      of a silently wrong value.
     *
     *  Schemas verified 2026-08-26 — see docs/COMPAT-PROBE-SCHEMAS.md for the
     *  version each was read from. Re-verify before trusting a quiet result.
     * ══════════════════════════════════════════════════════════════════ */

    /** Loose truthiness across the string/int/bool soup other plugins store. */
    private static function on( $v ) {
        if ( is_bool( $v ) ) {
            return $v;
        }
        // Several of these settings are LISTS, not switches: Breeze stores
        // 'breeze-defer-js' as an array of script handles, WP Rocket stores
        // 'preload_fonts' as an array of URLs. A non-empty list means the
        // feature is configured and running, so treating every array as OFF
        // would silently lose those. Objects and null stay OFF, and nothing is
        // ever cast to string (`(string) $array` is a PHP warning in the
        // user's debug log).
        if ( is_array( $v ) ) {
            return ! empty( $v );
        }
        if ( ! is_scalar( $v ) ) {
            return false;
        }
        if ( is_numeric( $v ) ) {
            return (int) $v > 0;
        }
        $v = strtolower( trim( (string) $v ) );
        return in_array( $v, array( '1', 'on', 'yes', 'true', 'enabled' ), true );
    }

    /** Safe nested array read. */
    private static function dig( $arr, $path, $default = false ) {
        foreach ( (array) $path as $k ) {
            if ( ! is_array( $arr ) || ! array_key_exists( $k, $arr ) ) {
                return $default;
            }
            $arr = $arr[ $k ];
        }
        return $arr;
    }

    /** WP Rocket 3.21.3 — public accessor. */
    private static function probe_wp_rocket() {
        if ( ! function_exists( 'get_rocket_option' ) ) {
            return null; // Active but API missing — degrade to soft tier.
        }
        return array(
            // WP Rocket's page cache has no off switch; if it's active and
            // owns the drop-in, the drop-in probe already reported it. Leaving
            // page_cache out here avoids naming it in two notices at once.
            'preload'    => self::on( get_rocket_option( 'manual_preload' ) ) || self::on( get_rocket_option( 'sitemap_preload' ) ),
            // Combine/concatenate is the same job as minify and the more
            // dangerous half of it — concatenating in the wrong order is a
            // classic way to break a theme.
            'minify_css' => self::on( get_rocket_option( 'minify_css' ) )
                         || self::on( get_rocket_option( 'minify_concatenate_css' ) )
                         || self::on( get_rocket_option( 'minify_css_combine_all' ) ),
            'minify_js'  => self::on( get_rocket_option( 'minify_js' ) )
                         || self::on( get_rocket_option( 'minify_concatenate_js' ) )
                         || self::on( get_rocket_option( 'minify_js_combine_all' ) ),
            'unused_css' => self::on( get_rocket_option( 'remove_unused_css' ) ) || self::on( get_rocket_option( 'async_css' ) ),
            'delay_js'   => self::on( get_rocket_option( 'delay_js' ) ) || self::on( get_rocket_option( 'defer_all_js' ) ),
            'lazy'       => self::on( get_rocket_option( 'lazyload' ) )
                         || self::on( get_rocket_option( 'lazyload_iframes' ) )
                         || self::on( get_rocket_option( 'lazyload_youtube' ) ),
            'imgcdn'     => self::on( get_rocket_option( 'cdn' ) ) || self::on( get_rocket_option( 'cache_webp' ) ),
            // NOT minify_google_fonts. WP Rocket ships it as 1 by default
            // (inc/admin/upgrader.php) and exposes no checkbox for it in 3.x —
            // it is applied automatically — so reading it made 'fonts' true
            // for practically every WP Rocket install no matter what the user
            // did. That is a probe reporting a vendor default as a user
            // choice, which is exactly the false accusation the proven tier
            // exists to avoid. preload_fonts IS user-configured (a list in the
            // Preload tab), so a non-empty value is a real signal.
            'fonts'      => ! empty( get_rocket_option( 'preload_fonts', array() ) ),
        );
    }

    /** Perfmatters 3.6.0 — perfmatters_options['assets'][...]. */
    private static function probe_perfmatters() {
        $o = get_option( 'perfmatters_options', array() );
        if ( ! is_array( $o ) ) {
            return null;
        }
        return array(
            'minify_css' => self::on( self::dig( $o, array( 'assets', 'minify_css' ) ) ),
            'minify_js'  => self::on( self::dig( $o, array( 'assets', 'minify_js' ) ) ),
            'unused_css' => self::on( self::dig( $o, array( 'assets', 'remove_unused_css' ) ) ),
            'delay_js'   => self::on( self::dig( $o, array( 'assets', 'delay_js' ) ) )
                         || self::on( self::dig( $o, array( 'assets', 'defer_js' ) ) )
                         || self::on( self::dig( $o, array( 'assets', 'defer_jquery' ) ) ),
            'lazy'       => self::on( self::dig( $o, array( 'lazyload', 'lazy_loading' ) ) )
                         || self::on( self::dig( $o, array( 'lazyload', 'lazy_loading_iframes' ) ) )
                         || self::on( self::dig( $o, array( 'lazyload', 'css_background_images' ) ) )
                         || self::on( self::dig( $o, array( 'lazyload', 'youtube_preview_thumbnails' ) ) ),
            'fonts'      => self::on( self::dig( $o, array( 'fonts', 'local_google_fonts' ) ) )
                         || self::on( self::dig( $o, array( 'fonts', 'display_swap' ) ) ),
            'prefetch'   => self::on( self::dig( $o, array( 'preload', 'instant_page' ) ) ),
        );
    }

    /** Autoptimize 3.1.15.1 — individual options, '1' or ''. */
    private static function probe_autoptimize() {
        $css = '1' === (string) get_option( 'autoptimize_css', '' );
        $js  = '1' === (string) get_option( 'autoptimize_js', '' );
        $img = get_option( 'autoptimize_imgopt_settings', array() );
        return array(
            'minify_css' => $css,
            'minify_js'  => $js,
            // Autoptimize's "unused CSS" is its Critical CSS power-up, which
            // only runs with a key present.
            // Two routes to "this site is stripping or deferring CSS": the
            // Critical CSS power-up (needs a key), and "Inline and Defer CSS",
            // which holds the full sheet back behind above-the-fold CSS.
            'unused_css' => $css && (
                '' !== (string) get_option( 'autoptimize_ccss_key', '' )
                || self::on( get_option( 'autoptimize_css_defer', '' ) )
            ),
            'delay_js'   => $js,
            // Autoptimize Images: field_3 is lazy load, field_1 is the CDN.
            // Confirmed against autoptimizeImages::should_lazyload().
            'lazy'       => self::on( self::dig( $img, array( 'autoptimize_imgopt_checkbox_field_3' ) ) ),
            'imgcdn'     => self::on( self::dig( $img, array( 'autoptimize_imgopt_checkbox_field_1' ) ) ),
        );
    }

    /**
     * Jetpack Boost 4.7.0 — per-module status options.
     *
     * Option name is 'jetpack_boost_status_' . str_replace('_','-',$slug),
     * per Status::get_option_name(). The 2.6.4 comment marked these
     * UNVERIFIED; they were in fact correct. What was wrong is that Boost has
     * 15 modules and we modelled two.
     */
    private static function probe_jetpack_boost() {
        $m = function ( $slug ) {
            return self::on( get_option( 'jetpack_boost_status_' . $slug, false ) );
        };
        return array(
            'page_cache' => $m( 'page-cache' ),
            'preload'    => $m( 'cache-preload' ),
            'minify_css' => $m( 'minify-css' ),
            'minify_js'  => $m( 'minify-js' ),
            // cloud-css is the hosted variant of critical-css; Boost syncs the
            // two, but read both so either switch counts.
            'unused_css' => $m( 'critical-css' ) || $m( 'cloud-css' ),
            'delay_js'   => $m( 'render-blocking-js' ),
            'imgcdn'     => $m( 'image-cdn' ),
            'lcp'        => $m( 'lcp' ),
            'prefetch'   => $m( 'speculation-rules' ),
        );
    }

    /** LiteSpeed Cache 7.9 — public filter, falling back to raw options. */
    private static function probe_litespeed() {
        $v = function ( $id ) {
            // apply_filters('litespeed_conf', $id) is LiteSpeed's own public
            // read API (api.cls.php). It returns the id unchanged when the
            // plugin isn't listening, so fall back to the documented storage
            // name — Root::name() => 'litespeed.conf.<id>'.
            $out = apply_filters( 'litespeed_conf', $id );
            if ( $out === $id ) {
                $out = get_option( 'litespeed.conf.' . $id, false );
            }
            return self::on( $out );
        };
        return array(
            // The master cache toggle. LiteSpeed writes advanced-cache.php, so
            // on a LiteSpeed server the drop-in probe usually reports the
            // caching clash first and this is suppressed as a duplicate — but
            // it must still be probed, or a LiteSpeed running WITHOUT the
            // drop-in (server-level LSCache via .htaccess) goes unreported.
            'page_cache' => $v( 'cache' ),
            'preload'    => $v( 'crawler' ),
            'minify_css' => $v( 'optm-css_min' ) || $v( 'optm-css_comb' ),
            'minify_js'  => $v( 'optm-js_min' ) || $v( 'optm-js_comb' ),
            // UCSS, async CSS (critical inline + defer the rest) and the
            // critical-CSS generator are three routes to one collision.
            'unused_css' => $v( 'optm-ucss' ) || $v( 'optm-css_async' ) || $v( 'optm-ccss_con' ),
            'delay_js'   => $v( 'optm-js_defer' ),
            'lazy'       => $v( 'media-lazy' ) || $v( 'media-iframe_lazy' ),
            'imgcdn'     => $v( 'cdn' ) || $v( 'img_optm-webp' ),
            'fonts'      => $v( 'optm-ggfonts_async' ) || $v( 'optm-ggfonts_rm' ) || $v( 'optm-localize' ),
        );
    }

    /**
     * SiteGround Speed Optimizer 7.8.2.
     *
     * Deliberately reports NO page_cache capability. SG's dynamic cache is
     * server-level NGINX, it does not compete for advanced-cache.php, and
     * Easy Optimizer purges THROUGH this plugin. Its CSS/JS/image work is a
     * genuine overlap; its cache is infrastructure we depend on.
     */
    private static function probe_siteground() {
        return array(
            'minify_css' => self::on( get_option( 'siteground_optimizer_optimize_css', 0 ) )
                         || self::on( get_option( 'siteground_optimizer_combine_css', 0 ) ),
            'minify_js'  => self::on( get_option( 'siteground_optimizer_optimize_javascript', 0 ) )
                         || self::on( get_option( 'siteground_optimizer_combine_javascript', 0 ) ),
            'delay_js'   => self::on( get_option( 'siteground_optimizer_optimize_javascript_async', 0 ) ),
            'lazy'       => self::on( get_option( 'siteground_optimizer_lazyload_images', 0 ) )
                         || self::on( get_option( 'siteground_optimizer_lazyload_iframes', 0 ) )
                         || self::on( get_option( 'siteground_optimizer_lazyload_videos', 0 ) )
                         || self::on( get_option( 'siteground_optimizer_lazyload_woocommerce', 0 ) ),
            'imgcdn'     => self::on( get_option( 'siteground_optimizer_optimize_images', 0 ) ),
            'fonts'      => self::on( get_option( 'siteground_optimizer_optimize_web_fonts', 0 ) )
                         || self::on( get_option( 'siteground_optimizer_combine_google_fonts', 0 ) ),
        );
    }

    /** WP Fastest Cache 1.5.1 — one option holding a JSON string. */
    private static function probe_wp_fastest_cache() {
        $raw = get_option( 'WpFastestCache', '' );
        $o   = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
        if ( ! is_array( $o ) ) {
            return null;
        }
        return array(
            'page_cache' => self::on( self::dig( $o, array( 'wpFastestCacheStatus' ) ) ),
            'preload'    => self::on( self::dig( $o, array( 'wpFastestCachePreload' ) ) ),
            'minify_css' => self::on( self::dig( $o, array( 'wpFastestCacheMinifyCss' ) ) )
                         || self::on( self::dig( $o, array( 'wpFastestCacheCombineCss' ) ) )
                         || self::on( self::dig( $o, array( 'wpFastestCacheMinifyCssPowerFul' ) ) ),
            'minify_js'  => self::on( self::dig( $o, array( 'wpFastestCacheMinifyJs' ) ) )
                         || self::on( self::dig( $o, array( 'wpFastestCacheCombineJs' ) ) )
                         || self::on( self::dig( $o, array( 'wpFastestCacheCombineJsPowerFul' ) ) ),
            'delay_js'   => self::on( self::dig( $o, array( 'wpFastestCacheRenderBlocking' ) ) ),
            'lazy'       => self::on( self::dig( $o, array( 'wpFastestCacheLazyLoad' ) ) ),
        );
    }

    /** Hummingbird 3.20.0 — wphb_settings, a SITE option, nested by module. */
    private static function probe_hummingbird() {
        $o = get_site_option( 'wphb_settings', array() );
        if ( ! is_array( $o ) ) {
            return null;
        }
        $minify = self::on( self::dig( $o, array( 'minify', 'enabled' ) ) );
        return array(
            'page_cache' => self::on( self::dig( $o, array( 'page_cache', 'enabled' ) ) ),
            'minify_css' => $minify,
            'minify_js'  => $minify,
            'unused_css' => self::on( self::dig( $o, array( 'minify', 'critical_css' ) ) ),
            'delay_js'   => self::on( self::dig( $o, array( 'minify', 'delay_js' ) ) ),
            'fonts'      => self::on( self::dig( $o, array( 'minify', 'font_swap' ) ) )
                         || self::on( self::dig( $o, array( 'minify', 'preload_fonts' ) ) ),
        );
    }

    /** WP-Optimize 4.6.1 — separate cache and minify config arrays. */
    private static function probe_wp_optimize() {
        $min   = get_option( 'wpo_minify_config', array() );
        $cache = get_option( 'wpo_cache_config', array() );
        $on    = self::on( self::dig( $min, array( 'enabled' ) ) );
        return array(
            'page_cache' => self::on( self::dig( $cache, array( 'enable_page_caching' ) ) ),
            'preload'    => self::on( self::dig( $cache, array( 'enable_schedule_preload' ) ) ),
            'minify_css' => $on && ( self::on( self::dig( $min, array( 'enable_css' ) ) )
                                  || self::on( self::dig( $min, array( 'enable_merging_of_css' ) ) ) ),
            'minify_js'  => $on && ( self::on( self::dig( $min, array( 'enable_js' ) ) )
                                  || self::on( self::dig( $min, array( 'enable_merging_of_js' ) ) ) ),
            'delay_js'   => $on && self::on( self::dig( $min, array( 'enable_defer_js' ) ) ),
        );
    }

    /** Breeze 2.5.13 — settings split across basic/file groups. */
    private static function probe_breeze() {
        $basic   = get_option( 'breeze_basic_settings', array() );
        $file    = get_option( 'breeze_file_settings', array() );
        $preload = get_option( 'breeze_preload_settings', array() );
        return array(
            'page_cache' => self::on( self::dig( $basic, array( 'breeze-desktop-cache' ) ) ),
            'preload'    => self::on( self::dig( $preload, array( 'breeze-preload-links' ) ) ),
            'minify_css' => self::on( self::dig( $file, array( 'breeze-minify-css' ) ) )
                         || self::on( self::dig( $file, array( 'breeze-group-css' ) ) ),
            'minify_js'  => self::on( self::dig( $file, array( 'breeze-minify-js' ) ) )
                         || self::on( self::dig( $file, array( 'breeze-group-js' ) ) ),
            // breeze-defer-js is a LIST of handles, not a switch — see on().
            'delay_js'   => self::on( self::dig( $file, array( 'breeze-defer-js' ) ) )
                         || self::on( self::dig( $file, array( 'breeze-enable-js-delay' ) ) )
                         || self::on( self::dig( $file, array( 'breeze-delay-all-js' ) ) ),
            'lazy'       => self::on( self::dig( $basic, array( 'breeze-lazy-load' ) ) )
                         || self::on( self::dig( $basic, array( 'breeze-lazy-load-iframes' ) ) )
                         || self::on( self::dig( $basic, array( 'breeze-lazy-load-native' ) ) )
                         || self::on( self::dig( $basic, array( 'breeze-lazy-load-videos' ) ) ),
        );
    }

    /** Asset CleanUp 1.4.0.5 — WPACU_PLUGIN_ID . '_settings'. */
    private static function probe_asset_cleanup() {
        $o = get_option( 'wpassetcleanup_settings', array() );
        if ( ! is_array( $o ) ) {
            return null;
        }
        return array(
            'minify_css' => self::on( self::dig( $o, array( 'minify_loaded_css' ) ) )
                         || self::on( self::dig( $o, array( 'combine_loaded_css' ) ) ),
            'minify_js'  => self::on( self::dig( $o, array( 'minify_loaded_js' ) ) )
                         || self::on( self::dig( $o, array( 'combine_loaded_js' ) ) ),
        );
    }

    /** Optimole 4.2.11 — OPTML_NAMESPACE . '_settings'. */
    private static function probe_optimole() {
        $o = get_option( 'optml_settings', array() );
        if ( ! is_array( $o ) ) {
            return null;
        }
        return array(
            'lazy'   => self::on( self::dig( $o, array( 'lazyload' ) ) ),
            'imgcdn' => self::on( self::dig( $o, array( 'cdn' ) ) ),
        );
    }

    /** Smush 4.3.2 — a SITE option, not get_option(). */
    private static function probe_smush() {
        $o = get_site_option( 'wp-smush-settings', array() );
        if ( ! is_array( $o ) ) {
            return null;
        }
        return array(
            'lazy'   => self::on( self::dig( $o, array( 'lazy_load' ) ) ),
            'imgcdn' => self::on( self::dig( $o, array( 'cdn' ) ) )
                     || self::on( self::dig( $o, array( 'webp_mod' ) ) ),
        );
    }

    /**
     * W3 Total Cache — via its own config object when available.
     *
     * W3TC's config is not a plain option (it is a generated PHP file under
     * wp-content/w3tc-config/), so there is no raw-option fallback. When the
     * class is missing we return null and W3TC degrades to the soft tier.
     */
    private static function probe_w3tc() {
        if ( ! class_exists( '\\W3TC\\Dispatcher' ) ) {
            return null;
        }
        try {
            $c = \W3TC\Dispatcher::config();
            if ( ! is_object( $c ) || ! method_exists( $c, 'get_boolean' ) ) {
                return null;
            }
            return array(
                'page_cache' => (bool) $c->get_boolean( 'pgcache.enabled' ),
                'minify_css' => (bool) $c->get_boolean( 'minify.enabled' )
                                && ( $c->get_boolean( 'minify.css.enable' ) || $c->get_boolean( 'minify.css.combine' ) ),
                'minify_js'  => (bool) $c->get_boolean( 'minify.enabled' )
                                && ( $c->get_boolean( 'minify.js.enable' )
                                    || $c->get_boolean( 'minify.js.combine.header' )
                                    || $c->get_boolean( 'minify.js.combine.body' )
                                    || $c->get_boolean( 'minify.js.combine.footer' ) ),
                'lazy'       => (bool) $c->get_boolean( 'lazyload.enabled' ),
                'imgcdn'     => (bool) $c->get_boolean( 'cdn.enabled' ),
            );
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    /* ══════════════════════════════════════════════════════════════════
     *  Scan
     * ══════════════════════════════════════════════════════════════════ */

    /** Drop the cached scan. Hooked to every event that can change it. */
    public static function flush_scan() {
        self::$scan = null;
        delete_transient( self::SCAN_TRANSIENT );
    }

    /**
     * Option rows our probes read.
     *
     * Exact names, plus prefixes for the plugins that store one option per
     * setting (SiteGround, LiteSpeed, Jetpack Boost). Keeping this next to the
     * probes is the point: adding a probe without adding its option here gives
     * you a detector that reports yesterday's answer.
     *
     * @since 2.6.5
     * @return array{exact:string[],prefix:string[]}
     */
    private static function watched_options() {
        return array(
            'exact' => array(
                'wp_rocket_settings',
                'perfmatters_options',
                'autoptimize_css', 'autoptimize_js', 'autoptimize_html',
                'autoptimize_ccss_key', 'autoptimize_css_defer', 'autoptimize_imgopt_settings',
                'WpFastestCache',
                'wphb_settings',
                'wpo_minify_config', 'wpo_cache_config',
                'breeze_basic_settings', 'breeze_file_settings', 'breeze_preload_settings',
                'wpassetcleanup_settings',
                'optml_settings',
                'wp-smush-settings',
            ),
            'prefix' => array(
                'siteground_optimizer_',
                'litespeed.conf.',
                'jetpack_boost_status_',
            ),
        );
    }

    /**
     * Flush the scan when a watched option changes.
     *
     * Runs on every option write on the site, so it must stay cheap: one hash
     * lookup and at most three strpos calls, no DB access, no scan triggered.
     *
     * @since 2.6.5
     * @param string $option Option name (first arg of all four hooks).
     */
    public static function maybe_flush_for_option( $option ) {

        $option = (string) $option;
        if ( '' === $option ) {
            return;
        }

        static $exact = null;
        if ( null === $exact ) {
            $w     = self::watched_options();
            $exact = array_flip( $w['exact'] );
        }

        if ( isset( $exact[ $option ] ) ) {
            self::flush_scan();
            return;
        }

        $w = self::watched_options();
        foreach ( $w['prefix'] as $p ) {
            if ( 0 === strpos( $option, $p ) ) {
                self::flush_scan();
                return;
            }
        }
    }

    /**
     * The scan. Cached per-request and in a transient.
     *
     * @param bool $force Skip the caches (the Re-check button).
     * @return array{dropin:array,layers:array,objectcache:array,proven:array,soft:array,exclusions:array}
     */
    public static function scan( $force = false ) {

        if ( ! $force && null !== self::$scan ) {
            return self::$scan;
        }

        if ( ! $force ) {
            $cached = get_transient( self::SCAN_TRANSIENT );
            if ( is_array( $cached ) && isset( $cached['v'] ) && self::SCAN_VERSION === (int) $cached['v'] ) {
                self::$scan = $cached;
                return self::$scan;
            }
        }

        self::$scan = self::build_scan();
        set_transient( self::SCAN_TRANSIENT, self::$scan, self::SCAN_TTL );

        return self::$scan;
    }

    /**
     * Everything that costs anything. Every block fails open — a probe that
     * throws is skipped, never fatal, because none of this is worth taking
     * an admin screen down for.
     */
    private static function build_scan() {

        $out = array(
            'v'           => self::SCAN_VERSION,
            'dropin'      => array(),  // {owner} — foreign page-cache drop-in
            'layers'      => array(),  // host/server cache layers (info only)
            'objectcache' => array(),  // {name} — foreign object-cache drop-in
            'proven'      => array(),  // [{name, reason, feat, caps}]
            'soft'        => array(),  // [{name, reason, feat, caps}]
            'exclusions'  => array(),  // key => reason, from the last preset apply
        );

        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $cache_on = (int) EasyOpt_Config::get( 'cache', 0 );

        // ── Who owns the page-cache drop-in? ──────────────────────────────
        // The 2.6.4 slug list is gone. Only one plugin can own
        // advanced-cache.php, so this yields one true statement instead of a
        // list of everything installed — and it cannot misfire on caches that
        // never touch the drop-in (SiteGround NGINX, Cloudways Varnish,
        // Cloudflare APO), which are reported as layers below.
        try {
            if ( $cache_on && defined( 'WP_CONTENT_DIR' ) && class_exists( 'EasyOpt_Advanced_Cache' ) ) {
                $dropin = trailingslashit( WP_CONTENT_DIR ) . 'advanced-cache.php';
                if ( file_exists( $dropin )
                    && ! EasyOpt_Advanced_Cache::is_installed()
                    && EasyOpt_Advanced_Cache::dropin_owner_is_live( $dropin ) ) {
                    $owner = (string) EasyOpt_Advanced_Cache::foreign_dropin_owner( $dropin );
                    // Did we actually identify it, or is this the generic
                    // fallback? The difference is load-bearing: an unnamed
                    // owner must not be handed to plugin_deactivate_button(),
                    // which would render the button "Deactivate another
                    // caching plugin" and link to a Plugins search for that
                    // phrase — a real 2.6.4 symptom.
                    $out['dropin'] = array(
                        'owner' => $owner,
                        'known' => ( $owner !== __( 'another caching plugin', 'easy-optimizer' ) ),
                    );
                }
            }
        } catch ( \Throwable $e ) { /* fail open */ }

        // ── Host / server / edge cache layers (INFORMATION, never conflict) ─
        try {
            if ( class_exists( 'EasyOpt_Hosting' ) ) {
                $out['layers'] = array_values( (array) EasyOpt_Hosting::active_layers() );
            }
        } catch ( \Throwable $e ) { /* fail open */ }

        // ── Foreign object-cache drop-in ──────────────────────────────────
        // (2.6.5) Pre-flight has probed this since 2.6.0, but nothing showed
        // it at runtime — so installing Redis Object Cache AFTER setup was
        // silent. Card-only; it is reference information, not an alert.
        try {
            if ( class_exists( 'EasyOpt_Object_Cache_Manager' )
                && method_exists( 'EasyOpt_Object_Cache_Manager', 'foreign_dropin_present' )
                && EasyOpt_Object_Cache_Manager::foreign_dropin_present() ) {
                $out['objectcache'] = array(
                    'name' => method_exists( 'EasyOpt_Object_Cache_Manager', 'foreign_dropin_name' )
                        ? (string) EasyOpt_Object_Cache_Manager::foreign_dropin_name()
                        : __( 'another plugin', 'easy-optimizer' ),
                );
            }
        } catch ( \Throwable $e ) { /* fail open */ }

        // ── Overlaps ──────────────────────────────────────────────────────
        $caps_map = self::capability_map();

        // Which of OUR features are on? A capability only overlaps when both
        // sides are switched on.
        $ours = array();
        foreach ( $caps_map as $cap => $meta ) {
            $on = false;
            foreach ( $meta['keys'] as $k ) {
                if ( (int) EasyOpt_Config::get( $k, 0 ) ) {
                    $on = true;
                    break;
                }
            }
            $ours[ $cap ] = $on;
        }

        foreach ( self::registry() as $label => $def ) {

            try {
                if ( empty( $def['file'] ) || ! is_plugin_active( $def['file'] ) ) {
                    continue;
                }

                // Is this plugin the page-cache drop-in owner? Then the cache
                // notice already makes the caching point, and repeating it
                // here would name the same plugin twice on one screen.
                //
                // Suppress the DUPLICATED POINT, not the plugin. Skipping the
                // whole entry (as the first cut of this did) hid every OTHER
                // overlap it has — a WP Rocket that owns the drop-in stopped
                // reporting that it also minifies CSS, removes unused CSS and
                // lazy-loads. That is separate information with a separate
                // fix: "turn off page caching in WP Rocket" and "WP Rocket
                // also minifies CSS" are two different actions, and only the
                // first is already on screen.
                //
                // The bug was masked until 2.6.5 fixed drop-in owner naming:
                // the owner read "another caching plugin", which never matched
                // the label "WP Rocket", so the skip never fired.
                $suppress = array();
                if ( ! empty( $out['dropin']['owner'] )
                    && false !== stripos( $label, (string) $out['dropin']['owner'] ) ) {
                    $suppress = array( 'page_cache', 'preload' );
                }

                $probe = isset( $def['probe'] ) ? $def['probe'] : null;
                $caps  = null;

                if ( $probe && method_exists( __CLASS__, $probe ) ) {
                    $caps = call_user_func( array( __CLASS__, $probe ) );
                }

                if ( is_array( $caps ) ) {
                    // PROVEN tier — keep only capabilities that are on at
                    // BOTH ends.
                    $hits = array();
                    foreach ( $caps as $cap => $is_on ) {
                        if ( in_array( $cap, $suppress, true ) ) {
                            continue;
                        }
                        if ( $is_on && ! empty( $ours[ $cap ] ) && isset( $caps_map[ $cap ] ) ) {
                            $hits[] = $cap;
                        }
                    }

                    // Probe-rot signal: the plugin is active but every single
                    // capability reads off. Possible, but far more often it
                    // means they refactored their option names and we are
                    // now silently blind. This is the failure mode that hid
                    // for a whole release in 2.6.4 — log it so it is findable.
                    if ( empty( array_filter( $caps ) ) && class_exists( 'EasyOpt_Debug_Log' ) ) {
                        EasyOpt_Debug_Log::info(
                            'compat',
                            sprintf( '%s is active but every capability probe read OFF — verify its option schema.', $label )
                        );
                    }

                    if ( ! empty( $hits ) ) {
                        $out['proven'][] = self::finding( $label, $hits, $caps_map );
                    }
                    continue;
                }

                // SOFT tier — installed, does overlapping work, cannot prove
                // it is switched on. Report only capabilities where OUR side
                // is on, so this stays about actual decisions the user faces.
                $hits = array();
                foreach ( (array) $def['claims'] as $cap ) {
                    if ( in_array( $cap, $suppress, true ) ) {
                        continue;
                    }
                    if ( ! empty( $ours[ $cap ] ) && isset( $caps_map[ $cap ] ) ) {
                        $hits[] = $cap;
                    }
                }
                if ( ! empty( $hits ) ) {
                    $out['soft'][] = self::finding( $label, $hits, $caps_map );
                }
            } catch ( \Throwable $e ) {
                // One bad probe must never cost the whole scan.
                continue;
            }
        }

        // ── Preset exclusions (moved off admin_notices in 2.6.5) ──────────
        // Only report keys that are STILL off. Once the user switches one on
        // themselves the explanation is stale.
        try {
            $ex = get_option( 'easyopt_preset_exclusions', array() );
            if ( is_array( $ex ) && ! empty( $ex ) ) {
                $live = array();
                foreach ( $ex as $key => $reason ) {
                    if ( ! (int) EasyOpt_Config::get( $key, 0 ) ) {
                        $live[ $key ] = (string) $reason;
                    }
                }
                if ( empty( $live ) ) {
                    delete_option( 'easyopt_preset_exclusions' );
                } else {
                    $out['exclusions'] = $live;
                }
            }
        } catch ( \Throwable $e ) { /* fail open */ }

        return $out;
    }

    /**
     * Shape one overlap finding.
     *
     * Carries BOTH the full picture (for the Compatibility card) and the
     * breaking-only subset (for the notice), so the notice never pads a
     * breakage warning with "also preloads the cache".
     */
    private static function finding( $label, $hits, $caps_map ) {

        $reasons  = array();
        $feats    = array();
        $breaking = array();
        $b_reason = array();

        foreach ( $hits as $cap ) {
            $reasons[] = $caps_map[ $cap ]['reason'];
            $feats[]   = $caps_map[ $cap ]['label'];
            if ( isset( $caps_map[ $cap ]['severity'] ) && 'breaking' === $caps_map[ $cap ]['severity'] ) {
                $breaking[] = $cap;
                $b_reason[] = $caps_map[ $cap ]['reason'];
            }
        }

        return array(
            'name'            => (string) $label,
            'reason'          => implode( ', ', array_unique( $reasons ) ),
            'feat'            => implode( ', ', array_unique( $feats ) ),
            'caps'            => array_values( $hits ),
            'breaking'        => array_values( $breaking ),
            'reason_breaking' => implode( ', ', array_unique( $b_reason ) ),
        );
    }

    /* ══════════════════════════════════════════════════════════════════
     *  Public adapters
     * ══════════════════════════════════════════════════════════════════ */

    /**
     * Proven overlaps, in the shape EasyOpt_Preflight expects.
     *
     * Kept under its original name so the wizard's pre-flight is unchanged,
     * while the underlying detection is now the full registry rather than the
     * two hand-written probes of 2.6.4.
     *
     * (2.6.5) SCOPE WIDENED from four hand-listed capabilities to every
     * BREAKING one. The old list stopped at CSS/JS, so the setup wizard could
     * switch Lazy Load on beside a proven-on lazy-loader in WP Rocket,
     * LiteSpeed, Perfmatters, Autoptimize, SiteGround, Breeze, WP Fastest
     * Cache, W3 Total Cache, Optimole or Smush — none of which the wizard's
     * own legacy lazy probe knows about. Two lazy-loaders on one <img> is a
     * top cause of images that never appear, and the wizard was creating it.
     *
     * Deriving the list from severity keeps it in sync: a capability promoted
     * to breaking is excluded by setup automatically. Keys the chosen preset
     * does not govern are ignored by EasyOpt_Presets::apply(), so listing more
     * than a preset can enable is harmless.
     *
     * @since 2.6.0
     * @return array<string,string[]> plugin label => EO setting keys it duplicates
     */
    public static function proven_css_js_overlaps() {

        $found    = array();
        $caps_map = self::capability_map();

        $wanted = array();
        foreach ( $caps_map as $cap => $meta ) {
            if ( isset( $meta['severity'] ) && 'breaking' === $meta['severity'] ) {
                $wanted[] = $cap;
            }
        }

        foreach ( self::registry() as $label => $def ) {
            try {
                if ( empty( $def['probe'] ) || ! method_exists( __CLASS__, $def['probe'] ) ) {
                    continue;
                }
                if ( ! function_exists( 'is_plugin_active' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                if ( ! is_plugin_active( $def['file'] ) ) {
                    continue;
                }
                $caps = call_user_func( array( __CLASS__, $def['probe'] ) );
                if ( ! is_array( $caps ) ) {
                    continue;
                }
                $keys = array();
                foreach ( $wanted as $cap ) {
                    if ( ! empty( $caps[ $cap ] ) && isset( $caps_map[ $cap ] ) ) {
                        foreach ( $caps_map[ $cap ]['keys'] as $k ) {
                            $keys[] = $k;
                        }
                    }
                }
                if ( ! empty( $keys ) ) {
                    $found[ $label ] = array_values( array_unique( $keys ) );
                }
            } catch ( \Throwable $e ) {
                continue;
            }
        }

        /**
         * Filter the proven CSS/JS overlaps.
         *
         * @since 2.6.0
         * @param array<string,string[]> $found label => EO setting keys.
         */
        return (array) apply_filters( 'easyopt_proven_css_js_overlaps', $found );
    }

    /**
     * Conflicting plugins the setup wizard can offer to deactivate (2.6.5).
     *
     * One row per ACTIVE plugin that owns a job the chosen preset would enable,
     * shaped for the wizard's per-plugin deactivate action:
     *
     *   [ { name, file, jobs:[human labels] }, … ]
     *
     * Sources, deduped by plugin file:
     *   • the page-cache drop-in owner (Page Cache),
     *   • every proven overlap (feature read as ON in the other plugin),
     *   • every soft overlap (installed, does the job, can't prove it's on).
     *
     * NOTE: this is the ONLY place the plugin FILE is surfaced to the wizard.
     * EasyOpt_Preflight deliberately reports exclusions by EO setting key with
     * the plugin name baked into a sentence — never the file — because its
     * design contract is "recommend, never enforce, never touch another
     * plugin". Deactivation is a separate, explicitly user-triggered path, so
     * the file it needs lives here rather than leaking into the scan.
     *
     * @since 2.6.5
     * @return array<int,array{name:string,file:string,jobs:string[]}>
     */
    public static function wizard_conflicting_plugins() {

        $scan     = self::scan();
        $caps_map = self::capability_map();
        $registry = self::registry();

        // name (as it appears in findings) => plugin file.
        $file_of = array();
        foreach ( $registry as $label => $def ) {
            if ( ! empty( $def['file'] ) ) {
                $file_of[ $label ] = $def['file'];
            }
        }

        $rows = array(); // file => { name, file, jobs }

        $add = function ( $name, $jobs ) use ( &$rows, $file_of ) {
            $name = (string) $name;
            $file = '';
            // Exact, then substring (drop-in owner names may carry a suffix).
            if ( isset( $file_of[ $name ] ) ) {
                $file = $file_of[ $name ];
            } else {
                foreach ( $file_of as $label => $f ) {
                    if ( false !== stripos( $name, $label ) || false !== stripos( $label, $name ) ) {
                        $file = $f;
                        $name = $label;
                        break;
                    }
                }
            }
            if ( '' === $file ) {
                return; // Unknown plugin — nothing safe to deactivate.
            }
            if ( ! isset( $rows[ $file ] ) ) {
                $rows[ $file ] = array( 'name' => $name, 'file' => $file, 'jobs' => array() );
            }
            foreach ( (array) $jobs as $j ) {
                if ( '' !== $j && ! in_array( $j, $rows[ $file ]['jobs'], true ) ) {
                    $rows[ $file ]['jobs'][] = $j;
                }
            }
        };

        // Page-cache drop-in owner.
        if ( ! empty( $scan['dropin']['known'] ) && ! empty( $scan['dropin']['owner'] ) ) {
            $add( $scan['dropin']['owner'], array( $caps_map['page_cache']['label'] ) );
        }

        // Proven + soft overlaps → job labels from their capabilities.
        foreach ( array( 'proven', 'soft' ) as $tier ) {
            foreach ( (array) ( isset( $scan[ $tier ] ) ? $scan[ $tier ] : array() ) as $f ) {
                $jobs = array();
                foreach ( (array) ( isset( $f['caps'] ) ? $f['caps'] : array() ) as $cap ) {
                    if ( isset( $caps_map[ $cap ] ) ) {
                        $jobs[] = $caps_map[ $cap ]['label'];
                    }
                }
                $add( $f['name'], $jobs );
            }
        }

        // Never offer to deactivate a plugin we depend on (SiteGround Optimizer
        // is our SiteGround purge bridge).
        $out = array();
        foreach ( $rows as $row ) {
            if ( self::never_deactivate( $row['name'] ) ) {
                continue;
            }
            $out[] = $row;
        }

        /**
         * Filter the wizard's deactivatable-conflict list.
         *
         * @since 2.6.5
         * @param array $out [{name, file, jobs}]
         */
        return (array) apply_filters( 'easyopt_wizard_conflicting_plugins', array_values( $out ) );
    }

    /**
     * Everything the dashboard's Compatibility card renders.
     *
     * One question, answered in one place: what on this site is Easy
     * Optimizer not doing, and why?
     *
     * @since 2.6.5
     * @param bool $force Re-check button.
     */
    public static function card_data( $force = false ) {

        $scan = self::scan( $force );

        $items = array();

        // Features the setup left off because something else owns the job.
        //
        // (2.6.5) Page Cache is the PARENT toggle: Cache Preload, Gzip and
        // Browser Caching only do anything while it is on, so when a foreign
        // page cache owns caching all four are excluded together and the card
        // listed the same "X is already caching your pages" sentence four
        // times. That is noise — one line ("Page Cache") says it. Collapse the
        // children whenever the parent is present in the exclusion set.
        $labels    = self::exclusion_labels();
        $exclusions = (array) $scan['exclusions'];
        if ( array_key_exists( 'easyopt_cache', $exclusions ) ) {
            unset(
                $exclusions['easyopt_cache_preload'],
                $exclusions['easyopt_cache_gzip'],
                $exclusions['easyopt_cache_browser_caching']
            );
        }
        foreach ( $exclusions as $key => $reason ) {
            $items[] = array(
                'kind'    => 'excluded',
                'title'   => isset( $labels[ $key ] ) ? $labels[ $key ] : $key,
                'detail'  => (string) $reason,
            );
        }

        // Foreign object cache.
        if ( ! empty( $scan['objectcache']['name'] ) ) {
            $items[] = array(
                'kind'   => 'excluded',
                'title'  => __( 'Object Cache', 'easy-optimizer' ),
                'detail' => sprintf(
                    /* translators: %s: name of the other plugin. */
                    __( '%s is already handling object caching.', 'easy-optimizer' ),
                    $scan['objectcache']['name']
                ),
            );
        }

        // Host / server cache layers — information, and for SiteGround an
        // explicit "keep it": Easy Optimizer purges THROUGH SG Optimizer.
        $host_labels = self::host_labels();
        foreach ( (array) $scan['layers'] as $layer ) {
            if ( ! isset( $host_labels[ $layer ] ) ) {
                continue;
            }
            // (2.6.5) SiteGround is detected by SG Optimizer merely being
            // ACTIVE — EasyOpt_Hosting::is_siteground() tests for its purge
            // function, not for its cache being on. So with SG's Dynamic Cache
            // switched off we were still telling users "SiteGround caches your
            // pages at the server", which is simply untrue in that state, and
            // advising them to keep a plugin active for a purge that has
            // nothing to purge.
            if ( 'siteground' === $layer
                && ! self::on( get_option( 'siteground_optimizer_enable_cache', 0 ) ) ) {
                continue;
            }
            $items[] = array(
                'kind'   => 'info',
                'title'  => $host_labels[ $layer ]['label'],
                'detail' => $host_labels[ $layer ]['note'],
            );
        }

        // Proven overlaps the notice deliberately did not raise: real, read
        // from the other plugin's settings, but wasteful rather than harmful.
        foreach ( (array) $scan['proven'] as $f ) {
            if ( ! empty( $f['breaking'] ) ) {
                continue; // Already in the notice.
            }
            $items[] = array(
                'kind'   => 'check',
                'title'  => $f['name'],
                'detail' => sprintf(
                    /* translators: 1: what the other plugin does, 2: our feature name(s). */
                    __( 'Also %1$s, overlapping Easy Optimizer\'s %2$s. Harmless, but you only need one.', 'easy-optimizer' ),
                    $f['reason'],
                    $f['feat']
                ),
                'url'    => class_exists( 'EasyOpt_Notices' ) ? EasyOpt_Notices::plugin_settings_url( $f['name'] ) : '',
            );
        }

        // Soft-tier overlaps — hedged, because we cannot prove these are on.
        foreach ( (array) $scan['soft'] as $s ) {
            $items[] = array(
                'kind'   => 'check',
                'title'  => $s['name'],
                'detail' => sprintf(
                    /* translators: 1: what the other plugin can do, 2: our feature name(s). */
                    __( 'Can also %1$s. If that is switched on, it overlaps Easy Optimizer\'s %2$s — worth checking.', 'easy-optimizer' ),
                    $s['reason'],
                    $s['feat']
                ),
                'url'    => class_exists( 'EasyOpt_Notices' ) ? EasyOpt_Notices::plugin_settings_url( $s['name'] ) : '',
            );
        }

        return array(
            'items' => $items,
            'count' => count( $items ),
        );
    }

    /** EO setting key => human label, for the exclusions list. */
    private static function exclusion_labels() {
        return array(
            'easyopt_cache'                 => __( 'Page Cache', 'easy-optimizer' ),
            'easyopt_cache_preload'         => __( 'Cache Preload', 'easy-optimizer' ),
            'easyopt_cache_gzip'            => __( 'Gzip', 'easy-optimizer' ),
            'easyopt_cache_browser_caching' => __( 'Browser Caching', 'easy-optimizer' ),
            'easyopt_object_cache'          => __( 'Object Cache', 'easy-optimizer' ),
            'easyopt_lazy_images'           => __( 'Lazy Load', 'easy-optimizer' ),
            'easyopt_lazy_iframes'          => __( 'Lazy Load (iframes)', 'easy-optimizer' ),
            'easyopt_lazy_videos'           => __( 'Lazy Load (videos)', 'easy-optimizer' ),
            'easyopt_preload_fonts'         => __( 'Smart Preload Fonts', 'easy-optimizer' ),
            'easyopt_unused_css'            => __( 'Remove Unused CSS', 'easy-optimizer' ),
            'easyopt_minify_css'            => __( 'Minify CSS', 'easy-optimizer' ),
            'easyopt_minify_js'             => __( 'Minify JavaScript', 'easy-optimizer' ),
            'easyopt_delay_js'              => __( 'Delay JavaScript', 'easy-optimizer' ),
        );
    }

    /**
     * Host cache layers worth a line on the Compatibility card.
     *
     * Deliberately ONE entry. The dashboard already renders a "Detected server
     * caches" card from liveStats.server_cache.layers, which says the generic
     * thing ("these are purged when you clear all cache") for every host we
     * detect. Repeating that here would put the same fact on the same screen
     * twice.
     *
     * SiteGround is the exception because it is the one host where we have
     * something DIFFERENT and corrective to say. 2.6.4 listed sg-cachepress in
     * the page-cache conflict list and rendered a "Deactivate SiteGround Speed
     * Optimizer" link — while EasyOpt_Hosting::is_siteground() detects the
     * host solely via sg_cachepress_purge_everything(), and
     * do_purge_all_siteground() calls that function with no fallback. Acting
     * on our own advice stopped us recognising SiteGround and stopped us
     * purging their server cache, so every publish left stale pages. SG
     * Optimizer is auto-installed on every SiteGround account, so that was the
     * default path for an entire host's customers. This line is the retraction.
     */
    private static function host_labels() {
        // (2.6.5) Empty by choice. Host server caches (SiteGround NGINX,
        // Cloudways Varnish, Kinsta, WP Engine, …) are detected as layers but
        // are no longer surfaced on the Compatibility card — they are not a
        // conflict and the note added clutter more than clarity. Add an entry
        // here (keyed by the EasyOpt_Hosting layer id) to bring one back.
        //
        // The SiteGround "keep it active" guidance previously lived here; it
        // still holds true (we purge through SG Optimizer), so if it needs a
        // home it belongs somewhere quieter than the dashboard card.
        return array();
    }

    /* ══════════════════════════════════════════════════════════════════
     *  REST
     * ══════════════════════════════════════════════════════════════════ */

    public static function register_routes() {
        register_rest_route( 'easyopt/v1', '/compat', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_compat' ),
            'permission_callback' => function () {
                return current_user_can( 'manage_options' )
                    ? true
                    : new WP_Error( 'easyopt_forbidden', __( 'Insufficient permissions.', 'easy-optimizer' ), array( 'status' => 403 ) );
            },
            'args'                => array(
                'refresh' => array( 'required' => false, 'type' => 'boolean' ),
            ),
        ) );
    }

    /**
     * Compatibility card data. `?refresh=1` is the card's Re-check button —
     * the escape hatch for the one state change we cannot hook, a user
     * flipping a setting inside the OTHER plugin.
     *
     * @since 2.6.5
     */
    public static function rest_compat( $request ) {
        $refresh = (bool) $request->get_param( 'refresh' );
        try {
            $data = self::card_data( $refresh );
        } catch ( \Throwable $e ) {
            // The card is diagnostics. It must never be the reason a
            // dashboard fails to render.
            $data = array( 'items' => array(), 'count' => 0 );
        }
        return rest_ensure_response( array( 'ok' => true ) + $data );
    }

    /**
     * Plugins we must never offer to deactivate.
     *
     * SiteGround Speed Optimizer is infrastructure Easy Optimizer DEPENDS ON:
     * EasyOpt_Hosting::is_siteground() detects the host purely by its
     * sg_cachepress_purge_everything() function, and do_purge_all_siteground()
     * calls that function with no fallback. Deactivating it means we stop
     * recognising SiteGround AND stop being able to purge its server cache —
     * so the user gets stale pages after every publish, caused by following
     * our own advice. 2.6.4 rendered exactly that link.
     *
     * The CSS/JS/image overlap is still real and still worth reporting; the
     * action is "turn that feature off in its settings", never "remove it".
     *
     * @since 2.6.5
     * @param string $label Plugin label as shown in a notice.
     * @return bool
     */
    public static function never_deactivate( $label ) {
        $protected = array( 'SiteGround' );
        /**
         * Filter plugins that must never be offered a deactivate action.
         *
         * @since 2.6.5
         * @param string[] $protected Label fragments.
         */
        $protected = (array) apply_filters( 'easyopt_compat_never_deactivate', $protected );
        foreach ( $protected as $needle ) {
            if ( '' !== (string) $needle && false !== stripos( (string) $label, (string) $needle ) ) {
                return true;
            }
        }
        return false;
    }

    /* ══════════════════════════════════════════════════════════════════
     *  Notices
     * ══════════════════════════════════════════════════════════════════ */

    public static function render_notices() {

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $scan  = self::scan();
        $owner = isset( $scan['dropin']['owner'] ) ? (string) $scan['dropin']['owner'] : '';
        // Only offer plugin-specific actions when we actually know which
        // plugin it is. Unknown owner => Plugins list, no deactivate button.
        $owner_known = ! empty( $scan['dropin']['known'] );

        // Is our drop-in gone? If so the hijack notice below says everything
        // this one would, more urgently.
        $hijacked = (int) EasyOpt_Config::get( 'cache', 0 )
            && (int) get_option( 'easyopt_advanced_cache_installed', 0 )
            && class_exists( 'EasyOpt_Advanced_Cache' )
            && ! EasyOpt_Advanced_Cache::is_installed();

        // ── ORANGE — another plugin owns the page-cache drop-in ───────────
        //
        // Since 2.5.7 Easy Optimizer refuses to overwrite a live competitor's
        // advanced-cache.php, so "two page caches" no longer means "your site
        // is broken" — it means two plugins are configured for the same job
        // and one should be switched off. A configuration observation, not a
        // fault; notice-error overstated it enough to generate support tickets
        // on sites that were working fine.
        //
        // Deliberately shown on EVERY admin screen: two page caches is the
        // most common real misconfiguration and we want it seen.
        $red_stamp = md5( 'dropin|' . $owner );
        if ( ! $hijacked
            && '' !== $owner
            && ! ( class_exists( 'EasyOpt_Notices' ) && EasyOpt_Notices::dismissed( 'compat_cache', $red_stamp ) ) ) {

            echo '<div class="notice notice-warning is-dismissible">';
            printf(
                '<p><strong>%s</strong> %s</p>',
                esc_html__( 'Two plugins are caching your pages.', 'easy-optimizer' ),
                sprintf(
                    /* translators: %s: name of the other caching plugin. */
                    esc_html__( 'Turn page caching off in %s so Easy Optimizer can manage it cleanly.', 'easy-optimizer' ),
                    '<strong>' . esc_html( $owner ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inline.
                )
            );
            echo '<p>';
            if ( class_exists( 'EasyOpt_Notices' ) ) {
                if ( $owner_known ) {
                    echo EasyOpt_Notices::plugin_action_button( $owner ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped internally.
                    if ( ! self::never_deactivate( $owner ) ) {
                        echo ' &nbsp; ' . EasyOpt_Notices::plugin_deactivate_button( $owner, 'link' ); // phpcs:ignore WordPress.Security.EscapeOutput
                    }
                } else {
                    printf(
                        '<a href="%s" class="button button-secondary">%s</a>',
                        esc_url( admin_url( 'plugins.php' ) ),
                        esc_html__( 'Go to Plugins', 'easy-optimizer' )
                    );
                }
                echo ' &nbsp; ' . EasyOpt_Notices::link( 'compat_cache', $red_stamp, __( 'Keep both — I\'ll manage it', 'easy-optimizer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
            }
            echo '</p>';
            echo '</div>';
        }

        // ── RED — our drop-in was replaced ────────────────────────────────
        //
        // The genuine failure, and the only page-cache case that earns
        // notice-error. We installed advanced-cache.php at some point (the
        // flag is set) but the file on disk is no longer ours. Our page cache
        // has silently stopped working and nothing else would say so.
        if ( $hijacked ) {

            $hijack_stamp = md5( 'hijacked|' . $owner );

            if ( ! ( class_exists( 'EasyOpt_Notices' ) && EasyOpt_Notices::dismissed( 'compat_hijack', $hijack_stamp ) ) ) {
                echo '<div class="notice notice-error is-dismissible">';

                if ( '' !== $owner ) {
                    printf(
                        '<p><strong>%s</strong> %s</p>',
                        esc_html__( 'Easy Optimizer\'s page cache has stopped working.', 'easy-optimizer' ),
                        sprintf(
                            /* translators: %s: name of the other caching plugin. */
                            esc_html__( '%s has replaced the cache file Easy Optimizer installed, so your pages are no longer being cached by it. Deactivate it and re-save your Cache settings, or turn off Easy Optimizer\'s Page Cache and let it handle caching.', 'easy-optimizer' ),
                            '<strong>' . esc_html( $owner ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inline.
                        )
                    );
                } else {
                    printf(
                        '<p><strong>%s</strong> %s</p>',
                        esc_html__( 'Easy Optimizer\'s page cache has stopped working.', 'easy-optimizer' ),
                        esc_html__( 'Another caching plugin has replaced the cache file Easy Optimizer installed, so your pages are no longer being cached by it. Deactivate that plugin and re-save your Cache settings.', 'easy-optimizer' )
                    );
                }

                echo '<p>';
                if ( class_exists( 'EasyOpt_Notices' ) ) {
                    if ( $owner_known && ! self::never_deactivate( $owner ) ) {
                        echo EasyOpt_Notices::plugin_deactivate_button( $owner, 'primary' ); // phpcs:ignore WordPress.Security.EscapeOutput
                        echo ' &nbsp; ' . EasyOpt_Notices::plugin_action_button( $owner ); // phpcs:ignore WordPress.Security.EscapeOutput
                    } else {
                        printf(
                            '<a href="%s" class="button button-primary">%s</a>',
                            esc_url( admin_url( 'plugins.php' ) ),
                            esc_html__( 'Go to Plugins', 'easy-optimizer' )
                        );
                    }
                    echo ' &nbsp; ' . EasyOpt_Notices::link( 'compat_hijack', $hijack_stamp, __( 'Dismiss', 'easy-optimizer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
                }
                echo '</p>';
                echo '</div>';
            }
        }

        // ── YELLOW — PROVEN feature overlaps ──────────────────────────────
        //
        // (2.6.5) Proven tier only. Everything we cannot prove is on now goes
        // to the dashboard's Compatibility card instead, so this notice never
        // states something it hasn't verified. Advisory: shown only where the
        // decision is made (our screens, the Plugins list, the WP Dashboard).
        // Breaking overlaps only. A duplicated cache preloader or font handler
        // is waste, not damage, and this notice says "can break scripts and
        // styles" — so those belong on the Compatibility card instead.
        $overlaps = array();
        foreach ( (array) ( isset( $scan['proven'] ) ? $scan['proven'] : array() ) as $f ) {
            if ( ! empty( $f['breaking'] ) ) {
                $overlaps[] = $f;
            }
        }

        $yellow_stamp = md5( wp_json_encode( $overlaps ) );
        if ( ! empty( $overlaps )
            && class_exists( 'EasyOpt_Notices' ) && EasyOpt_Notices::is_advisory_screen()
            && ! EasyOpt_Notices::dismissed( 'compat_overlap', $yellow_stamp ) ) {
            echo '<div class="notice notice-warning is-dismissible">';
            printf(
                '<p><strong>%s</strong> %s</p>',
                esc_html__( 'These optimizations are running twice.', 'easy-optimizer' ),
                esc_html__( 'Turn each one off in the other plugin — Easy Optimizer is already handling it. Running both can slow pages down or break scripts and styles.', 'easy-optimizer' )
            );
            echo '<ul style="margin:4px 0 10px 18px;list-style:disc;">';
            foreach ( $overlaps as $o ) {
                printf(
                    '<li><strong>%s</strong> — %s</li>',
                    esc_html( $o['name'] ),
                    esc_html( ! empty( $o['reason_breaking'] ) ? $o['reason_breaking'] : $o['reason'] )
                );
            }
            echo '</ul>';
            echo '<p>';
            // One button, pointing at the first overlapping plugin. With
            // several, a button per plugin would out-weigh the list itself —
            // and here the fix is usually a setting in the other plugin, not
            // deactivating it, so settings leads and deactivate is a link.
            echo EasyOpt_Notices::plugin_action_button( $overlaps[0]['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped internally.
            if ( ! self::never_deactivate( $overlaps[0]['name'] ) ) {
                echo ' &nbsp; ' . EasyOpt_Notices::plugin_deactivate_button( $overlaps[0]['name'], 'link' ); // phpcs:ignore WordPress.Security.EscapeOutput
            }
            echo ' &nbsp; ' . EasyOpt_Notices::link( 'compat_overlap', $yellow_stamp, __( 'Keep both — I\'ll manage it', 'easy-optimizer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
            echo '</p>';
            echo '</div>';
        }
    }
}
