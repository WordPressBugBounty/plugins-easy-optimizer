<?php
/**
 * First-run setup wizard (2.3.0).
 *
 * On a brand-new install the plugin seeds an all-off baseline (so nothing
 * is enabled until the user chooses), flags a one-time redirect, and sends
 * the admin to this wizard. The wizard offers three presets (Safe /
 * Balanced / Maximum) plus a diagnostics opt-in, then applies the chosen
 * preset via EasyOpt_Presets and returns to the dashboard.
 *
 * Existing installs are never shown the wizard: the redirect flag is only
 * set from the activation hook when no prior install marker exists, and a
 * plugin *update* doesn't fire activation.
 *
 * @package EasyOptimizer
 * @since   2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Wizard {

    const PAGE_SLUG  = 'easyopt-wizard';
    const SHOW_FLAG  = 'easyopt_show_wizard';
    const DONE_FLAG  = 'easyopt_wizard_completed';

    public static function init() {
        // (2.5.4 / perf #5) Every hook below is admin-UI-only — skip the
        // registrations entirely on frontend/REST/cron requests.
        if ( ! is_admin() ) {
            return;
        }
        add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 30 );
        // (2.6.0) Clear other plugins' notices and upsells off the wizard.
        add_action( 'in_admin_header', array( __CLASS__, 'suppress_notices' ), 1 );
        // Hide the menu link AFTER the page-access check has run (which is
        // during/after admin_menu) but BEFORE the menu is rendered in
        // admin-header.php. Removing it on admin_menu would make WordPress
        // deny access to the page ("Sorry, you are not allowed…").
        add_action( 'admin_head', array( __CLASS__, 'hide_menu_link' ) );
        add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ), 20 );
    }

    /**
     * Seed an all-off baseline on a genuinely fresh install and flag the
     * one-time wizard redirect. Called from the activation hook.
     *
     * Detection: the install-version marker is empty only before the first
     * finalize, i.e. on a brand-new activation. Re-activations and updates
     * already have it set, so they keep their settings and skip the wizard.
     */
    public static function seed_fresh_install() {
        $already_installed = '' !== (string) get_option( 'easyopt_installed_version', '' );
        $already_done      = (int) get_option( self::DONE_FLAG, 0 );

        if ( $already_installed || $already_done ) {
            // Existing install — make sure the wizard never appears.
            update_option( self::DONE_FLAG, 1, false );
            return;
        }

        // Brand-new install: write an explicit all-off baseline so no
        // feature runs until a preset is chosen. Defaults that don't enable
        // anything on their own (cache mode, sub-option flags, log_errors)
        // are left to the schema.
        $baseline = array(
            'easyopt_cache'                 => 0,
            'easyopt_cache_preload'         => 0,
            'easyopt_cache_browser_caching' => 0,
            'easyopt_cache_gzip'            => 0,
            'easyopt_font_display_swap'     => 0,
            'easyopt_lcp_preload'           => 0,
            'easyopt_lazy_images'           => 0,
            'easyopt_lazy_iframes'          => 0,
            'easyopt_lazy_videos'           => 0,
            'easyopt_add_missing_dims'      => 0,
            'easyopt_instant_preload'       => 0,
            'easyopt_unused_css'            => 0,
            'easyopt_delay_js'              => 0,
            'easyopt_minify_css'            => 0,
            'easyopt_minify_js'             => 0,
            'easyopt_lazyload_fonts'        => 0,
            // Requested defaults for a fresh, un-configured install.
            'easyopt_delete_on_uninstall'   => 0,
            'easyopt_log_warnings'          => 0,
        );

        // Write directly (not via update_many) so no save side-effects fire
        // for an all-off state.
        $existing = get_option( EasyOpt_Config::STORAGE_OPTION, array() );
        if ( ! is_array( $existing ) ) {
            $existing = array();
        }
        update_option( EasyOpt_Config::STORAGE_OPTION, array_replace( $existing, $baseline ) );

        update_option( self::SHOW_FLAG, 1, false );
        update_option( self::DONE_FLAG, 0, false );
    }

    /** Register the wizard page under the main menu (routable + access-checked). */
    public static function register_page() {
        $hook = add_submenu_page(
            EasyOpt_Settings::$page_slug,
            __( 'Easy Optimizer Setup', 'easy-optimizer' ),
            __( 'Setup', 'easy-optimizer' ),
            'manage_options',
            self::PAGE_SLUG,
            array( __CLASS__, 'render' )
        );

        if ( $hook ) {
            add_action( 'admin_print_scripts-' . $hook, array( __CLASS__, 'enqueue' ) );
        }
    }

    /**
     * Strip admin notices from the setup wizard screen (2.6.0).
     *
     * The wizard is a full-screen takeover with one decision on it. Other
     * plugins' "rate us" prompts, Black Friday banners and update nags land in
     * the middle of that decision and make a 30-second setup look like a
     * cluttered dashboard. Removing them here is the same thing WooCommerce
     * and Yoast do for their onboarding flows.
     *
     * Scope is deliberately narrow and non-negotiable:
     *
     *   • THIS PAGE ONLY. Never the settings screens. Suppressing notices on a
     *     screen users spend real time on can hide genuine security and update
     *     warnings, and is hostile to other developers. A brief onboarding
     *     flow is defensible; a permanent settings page is not.
     *   • Our OWN notices go too. The pre-flight panel already reports what
     *     matters; a compat-conflict notice firing beside it is duplicate
     *     information competing with the same decision.
     *
     * `in_admin_header` fires before notices are rendered; priority 1 puts us
     * ahead of callbacks registered late.
     */
    public static function suppress_notices() {

        // phpcs:ignore WordPress.Security.NonceVerification -- read-only screen check.
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( self::PAGE_SLUG !== $page ) {
            return;
        }

        remove_all_actions( 'admin_notices' );
        remove_all_actions( 'all_admin_notices' );
        remove_all_actions( 'network_admin_notices' );
        remove_all_actions( 'user_admin_notices' );
    }

    /** Remove the visible submenu link (kept accessible by direct URL/redirect). */
    public static function hide_menu_link() {
        remove_submenu_page( EasyOpt_Settings::$page_slug, self::PAGE_SLUG );
    }

    /** One-time redirect to the wizard after a fresh activation. */
    public static function maybe_redirect() {
        if ( ! get_option( self::SHOW_FLAG ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }
        // Bulk plugin activation — don't hijack the screen, but KEEP the
        // entitlement. (2.5.7) This previously deleted the flag, which left a
        // fresh install permanently in the all-off baseline: the redirect never
        // fired again, DONE_FLAG stayed 0 but is read nowhere, and
        // hide_menu_link() removes the only menu entry — so the wizard became
        // unreachable except by typing the URL. The user installed a
        // performance plugin that then did nothing, silently, forever.
        // Setting 0 suppresses the redirect exactly as deletion did (the guard
        // above is `! get_option( SHOW_FLAG )`) while leaving DONE_FLAG at 0 so
        // the dashboard can offer setup on the user's own terms.
        if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            update_option( self::SHOW_FLAG, 0, false );
            return;
        }
        // Already heading to the wizard.
        if ( isset( $_GET['page'] ) && self::PAGE_SLUG === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }

        // One-shot: clear before redirecting so we never loop.
        delete_option( self::SHOW_FLAG );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
        exit;
    }

    public static function enqueue() {
        wp_enqueue_script(
            'easyopt-wizard',
            EASYOPT_URL . 'assets/wizard.js',
            array( 'wp-element' ),
            defined( 'EASYOPT_VERSION' ) ? EASYOPT_VERSION : '2.3.0',
            true
        );
        wp_enqueue_style( 'easyopt-admin', EASYOPT_URL . 'assets/style.css', array(), EASYOPT_VERSION );
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $data = array(
            'restUrl'     => esc_url_raw( rest_url( 'easyopt/v1' ) ),
            'restNonce'   => wp_create_nonce( 'wp_rest' ),
            'dashboardUrl'=> admin_url( 'admin.php?page=easy-optimizer' ),
            'version'     => defined( 'EASYOPT_VERSION' ) ? EASYOPT_VERSION : '2.3.0',
        );
        ?>
        <script>window.__EASYOPT_WIZARD__ = <?php echo wp_json_encode( $data ); ?>;</script>
        <div id="easyopt-wizard-app"></div>
        <?php
    }
}
