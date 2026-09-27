<?php
/**
 * Settings page — React SPA mount (2.0).
 * Replaces the 1335-line PHP template with a React mount point.
 * Renders: SVG icon sprite + initial config + <div id="easyopt-app">.
 * React takes over from there.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class EasyOpt_Settings {

    public static $page_slug = 'easy-optimizer';

    public static function register_options_page() {
        $icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>';
        $icon     = 'data:image/svg+xml;base64,' . base64_encode( $icon_svg );

        $hook = add_menu_page(
            __( 'Easy Optimizer Settings', 'easy-optimizer' ),
            __( 'Easy Optimizer', 'easy-optimizer' ),
            'manage_options',
            self::$page_slug,
            array( __CLASS__, 'render_app' ),
            $icon,
            81
        );

        add_action( 'admin_print_scripts-' . $hook, array( __CLASS__, 'enqueue_app' ) );

        // add_menu_page() auto-creates a submenu item that duplicates the
        // top-level label. Remove it on admin_head — after WordPress' page
        // access check (so the page stays reachable) but before the menu is
        // rendered — so only the single top-level "Easy Optimizer" item shows.
        add_action( 'admin_head', array( __CLASS__, 'hide_duplicate_submenu' ) );
    }

    public static function hide_duplicate_submenu() {
        remove_submenu_page( self::$page_slug, self::$page_slug );
    }

    public static function enqueue_app() {
        // Dequeue old scripts.
        wp_dequeue_script( 'easyopt-admin' );
        wp_dequeue_script( 'easyopt-save' );

        // Freemius Checkout.js — the hosted overlay that starts the no-card
        // trial. Standalone build; NOT the Freemius PHP SDK. It only opens the
        // modal and hands back the trial/license ids — activation still runs
        // through our own /cloud/connect with the licence key Freemius emails
        // the buyer, so nothing about entitlement is decided in the browser.
        wp_enqueue_script(
            'freemius-checkout',
            'https://checkout.freemius.com/checkout.min.js',
            array(),
            null,
            true
        );

        // React dashboard — depends on wp-element (WordPress's bundled React).
        wp_enqueue_script(
            'easyopt-dashboard',
            EASYOPT_URL . 'assets/app.js',
            array( 'wp-element', 'freemius-checkout' ), // React via WordPress
            defined( 'EASYOPT_VERSION' ) ? EASYOPT_VERSION : '2.0.0',
            true
        );

        wp_enqueue_style( 'easyopt-admin', EASYOPT_URL . 'assets/style.css', array(), EASYOPT_VERSION );

        // Smart Images tab-view ping — top-of-funnel measurement. Fires when
        // the imgopt tab is clicked OR is already the restored tab on load.
        // Once per page load client-side; the tracker throttles to once per
        // day server-side, so this is at most one tiny request daily.
        wp_add_inline_script( 'easyopt-dashboard', "
(function(){
    var sent = false;
    function ping(){
        if (sent || !window.__EASYOPT__ || !window.__EASYOPT__.restUrl) { return; }
        sent = true;
        fetch(window.__EASYOPT__.restUrl.replace(/\\/$/, '') + '/track/si-view', {
            method: 'POST',
            headers: { 'X-WP-Nonce': window.__EASYOPT__.restNonce },
            credentials: 'same-origin'
        }).catch(function(){});
    }
    document.addEventListener('click', function(e){
        var el = e.target && e.target.closest ? e.target.closest('[data-tab=\"imgopt\"]') : null;
        if (el) { ping(); }
    });
    // Restored-tab case: panel already mounted shortly after load.
    var tries = 0;
    var t = setInterval(function(){
        if (document.getElementById('eop-panel-imgopt')) { ping(); clearInterval(t); }
        if (++tries > 20) { clearInterval(t); }
    }, 500);
})();
" );
    }

    public static function render_app() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        // Gather ALL initial data so React renders instantly (no loading state).
        $settings = EasyOpt_Config::get_all();
        $version  = defined( 'EASYOPT_VERSION' ) ? EASYOPT_VERSION : '2.0.0';
        $rest_url = esc_url_raw( rest_url( 'easyopt/v1' ) );
        $rest_nonce = wp_create_nonce( 'wp_rest' );

        // Dashboard stats — NOT computed inline (can take 30s+ on large sites
        // and cause 502). React fetches via REST API after mount instead.
        $stats = array(
            'pages'          => 0,
            'pages_label'    => '—',
            'waiting'        => 0,
            'preload_status' => array( 'status' => 'idle', 'done' => 0, 'total' => 0 ),
            'state_version'  => 0,
        );

        // Module statuses.
        $modules = array(
            array( 'opt' => 'easyopt_cache',          'title' => __( 'Page Cache', 'easy-optimizer' ),                      'tab' => 'cache',        'sub' => '' ),
            array( 'opt' => 'easyopt_delay_js',       'title' => __( 'Delay Javascript', 'easy-optimizer' ),                'tab' => 'optimization', 'sub' => 'js' ),
            array( 'opt' => 'easyopt_unused_css',     'title' => __( 'Remove Unused CSS', 'easy-optimizer' ),               'tab' => 'optimization', 'sub' => 'css' ),
            array( 'opt' => 'easyopt_lazy_images',    'title' => __( 'Lazyload Images', 'easy-optimizer' ),                 'tab' => 'optimization', 'sub' => 'lazy' ),
            array( 'opt' => 'easyopt_lcp_preload',    'title' => __( 'Preload Largest Contentful Paint', 'easy-optimizer' ),'tab' => 'optimization', 'sub' => 'lcp' ),
            array( 'opt' => 'easyopt_img_opt',        'title' => __( 'Image Optimizer (CDN)', 'easy-optimizer' ),           'tab' => 'imgopt',       'sub' => '' ),
        );
        foreach ( $modules as &$m ) { $m['enabled'] = (bool) (int) EasyOpt_Config::get( $m['opt'] ); }

        // DB counts — fetched async via REST API (was synchronous pre-2.1.0,
        // causing 502 on large WooCommerce sites with millions of posts).

        // DB tasks.
        $db_tasks = array();
        if ( class_exists( 'EasyOpt_Database' ) && method_exists( 'EasyOpt_Database', 'tasks' ) ) {
            $db_tasks = EasyOpt_Database::tasks();
        }

        // Is Apache?
        $is_apache = class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'is_apache' ) ? EasyOpt_Cache::is_apache() : false;

        // (2.5.5) Nginx has no .htaccess, so direct static serving has to be
        // configured in the server block. We generate the exact snippet for
        // this install and show it in the Caching tab. Only computed when the
        // server actually needs it, so Apache/LiteSpeed pay nothing.
        $nginx_snippet   = '';
        $is_openlitespeed = false;
        if ( class_exists( 'EasyOpt_Cache' ) ) {
            if ( method_exists( 'EasyOpt_Cache', 'is_openlitespeed' ) ) {
                $is_openlitespeed = (bool) EasyOpt_Cache::is_openlitespeed();
            }
            if ( ! $is_apache && method_exists( 'EasyOpt_Cache', 'build_nginx_snippet' ) ) {
                $nginx_snippet = (string) EasyOpt_Cache::build_nginx_snippet();
            }
        }

        // Is Elementor active?
        $is_elementor = defined( 'ELEMENTOR_VERSION' ) || did_action( 'elementor/loaded' );

        // Is WooCommerce active? Drives the WooCommerce-specific benefit line
        // in the Object Cache "no Redis server" panel.
        $is_woo = class_exists( 'WooCommerce' );

        // Setting field names for dirty tracking.
        $fields = array();
        if ( class_exists( 'EasyOpt_Settings_Registry' ) ) {
            $fields = EasyOpt_Settings_Registry::keys();
        }

        $initial_data = array(
            'settings'  => $settings,
            'stats'     => $stats,
            'modules'   => $modules,
            'dbTasks'   => $db_tasks,
            'fields'    => $fields,
            'version'   => $version,
            'restUrl'   => $rest_url,
            'restNonce' => $rest_nonce,
            'homeUrl'   => home_url( '/' ),
            // Cloudways affiliate CTA shows only when NOT already on Cloudways.
            'onCloudways' => class_exists( 'EasyOpt_Hosting' ) && in_array( 'cloudways', (array) EasyOpt_Hosting::active_layers(), true ),
            'siteUrl'   => home_url( '/' ),
            'isApache'    => $is_apache,
            'isOpenLiteSpeed' => $is_openlitespeed,
            'nginxSnippet'    => $nginx_snippet,
            'isElementor' => $is_elementor,
            'wooActive'   => $is_woo,
            'safeMode'    => (bool) (int) get_option( 'easyopt_safe_mode_active', 0 ),
            'trackingOptin' => ( get_option( 'easyopt_tracking_optin', '' ) !== 'no' ),
            // Where "Start free trial" sends people. The trial itself is run
            // by Freemius, not by us, so this points at the Freemius-hosted
            // trial/checkout. Filterable so the URL is not hard-coded in JS.
            'trialUrl'  => apply_filters( 'easyopt_cloud_trial_url', 'https://fluxpress.io/pricing?utm_source=plugin&utm_medium=cloud_tab' ),
            // Freemius Checkout overlay identifiers. All three are public keys
            // (safe to ship in page source); filterable so they are not frozen
            // into the JS bundle.
            'checkout'  => array(
                'productId' => (string) apply_filters( 'easyopt_freemius_product_id', '36614' ),
                'planId'    => (string) apply_filters( 'easyopt_freemius_plan_id', '60626' ),
                'publicKey' => (string) apply_filters( 'easyopt_freemius_public_key', 'pk_ec60221c78219494e358fc1a0bd01' ),
            ),
            // Where a customer who lost their licence key recovers it — the
            // Freemius customer portal, not our own resend endpoint any more.
            'recoverUrl' => apply_filters( 'easyopt_cloud_recover_url', 'https://customers.freemius.com/store/14517/password/recover' ),
        );

        // Render icon sprite + config + mount point.
        ?>
        <form id="easyopt-settings-form" onsubmit="event.preventDefault();return false;">
        <script>window.__EASYOPT__ = <?php echo wp_json_encode( $initial_data ); ?>;</script>
        <?php self::render_icon_sprite(); ?>
        <div id="easyopt-app"></div>
        </form>
        <?php
    }

    /**
     * SVG icon sprite — EXACT same as original. React references these via <use href="#eop-i-xxx"/>.
     */
    public static function render_icon_sprite() {
        ?>
        <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
            <defs>
                <symbol id="eop-i-gauge" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 14l4-4"/><path d="M3 12a9 9 0 1 1 18 0"/><circle cx="12" cy="14" r="1"/></symbol>
                <symbol id="eop-i-cache" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></symbol>
                <symbol id="eop-i-bolt" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></symbol>
                <symbol id="eop-i-zap" viewBox="0 0 24 24" fill="currentColor"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></symbol>
                <symbol id="eop-i-refresh" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></symbol>
                <symbol id="eop-i-font" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></symbol>
                <symbol id="eop-i-image" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/></symbol>
                <symbol id="eop-i-broom" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19.36 2.72l1.42 1.42a1 1 0 0 1 0 1.41l-7.07 7.07-2.83-2.83 7.07-7.07a1 1 0 0 1 1.41 0z"/><path d="M11 13l-1.41 1.41a3 3 0 0 0 0 4.24l1.06 1.06a3 3 0 0 0 4.24 0L16.3 18.3"/><path d="M3 21h7"/></symbol>
                <symbol id="eop-i-cloud" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.5 19a4.5 4.5 0 0 0 0-9c-.04 0-.07 0-.11 0a6 6 0 0 0-11.6 1.7A4 4 0 0 0 7 19h10.5z"/></symbol>
                <symbol id="eop-i-db" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></symbol>
                <symbol id="eop-i-eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></symbol>
                <symbol id="eop-i-chev-right" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></symbol>
                <symbol id="eop-i-chev-down" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></symbol>
                <symbol id="eop-i-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></symbol>
                <symbol id="eop-i-x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></symbol>
                <symbol id="eop-i-trash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></symbol>
                <symbol id="eop-i-edit" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></symbol>
                <symbol id="eop-i-warn" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></symbol>
                <symbol id="eop-i-info" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></symbol>
                <symbol id="eop-i-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></symbol>
                <symbol id="eop-i-ext" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></symbol>
                <symbol id="eop-i-lock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></symbol>
                <symbol id="eop-i-heart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></symbol>
                <symbol id="eop-i-gear" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></symbol>
                <symbol id="eop-i-code" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></symbol>
                <?php /* (2.6.5) Three new glyphs. eop-i-cache and eop-i-db are
                         byte-identical cylinders, and NAV pointed Caching,
                         Object Cache and Database at them while Dashboard and
                         Backend Analyzer both used eop-i-gauge — five of
                         thirteen sidebar items rendering two pictures. With the
                         Advanced group collapsed by default the eight remaining
                         items each have to be recognisable on their own. */ ?>
                <symbol id="eop-i-layers" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></symbol>
                <symbol id="eop-i-chip" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="6" width="12" height="12" rx="1.5"/><line x1="10" y1="2" x2="10" y2="6"/><line x1="14" y1="2" x2="14" y2="6"/><line x1="10" y1="18" x2="10" y2="22"/><line x1="14" y1="18" x2="14" y2="22"/><line x1="2" y1="10" x2="6" y2="10"/><line x1="2" y1="14" x2="6" y2="14"/><line x1="18" y1="10" x2="22" y2="10"/><line x1="18" y1="14" x2="22" y2="14"/></symbol>
                <symbol id="eop-i-pulse" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="2 12 6 12 9 4 15 20 18 12 22 12"/></symbol>
                <symbol id="eop-i-brush" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.06 11.9l8.07-8.06a2.85 2.85 0 1 1 4.03 4.03l-8.06 8.08"/><path d="M7.07 14.94c-1.66 0-3 1.35-3 3.02 0 1.33-2.5 1.52-2 2.02 1.08 1.1 2.49 2.02 4 2.02 2.2 0 4-1.8 4-4.04a3.01 3.01 0 0 0-3-3.02z"/></symbol>
                <symbol id="eop-i-download" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></symbol>
                <symbol id="eop-i-upload" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></symbol>
                <?php // (2.6.0) Debug Issues panel — wrench reads as "diagnose / fix" and none of the existing glyphs carried that meaning. Feather "tool" outline, matching the stroke style of every symbol above. ?>
                <symbol id="eop-i-wrench" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></symbol>
            </defs>
        </svg>
        <?php
    }

    // Keep the old sanitize methods that other parts of the plugin reference.
    public static function sanitize_eagerness( $v ) {
        $v = is_string( $v ) ? strtolower( trim( $v ) ) : '';
        return in_array( $v, array( 'conservative', 'moderate', 'eager', 'immediate' ), true )
            ? $v : 'moderate';
    }
}
