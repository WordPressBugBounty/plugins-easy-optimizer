<?php
/**
 * Detects other active lazyload systems and shows an admin warning
 * so the user doesn't end up with double lazy-loading (which causes
 * permanently broken images).
 *
 * Plugin-level detection runs on admin_init. Per-element detection
 * (data-src, data-lazy-src, plugin CSS classes) is handled inside
 * class-easyopt-lazyload.php via the extended eligibility checks.
 *
 * @package EasyOptimizer
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Compat_LazyLoad {

    /** @var array Detected conflicting lazyload systems. */
    private static $conflicts = array();

    /**
     * Wire up.
     */
    public static function init() {
        // (2.5.4 / perf #5) Detection + notice are admin-only.
        if ( ! is_admin() ) {
            return;
        }
        add_action( 'admin_init',    array( __CLASS__, 'detect' ) );
        add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
    }

    /**
     * Check whether Easy Optimizer's lazyload is on and another system
     * is also active at the plugin level.
     */
    public static function detect() {

        // Only relevant when our lazyload is enabled.
        $lazy_images  = (int) EasyOpt_Config::get( 'lazy_images', 0 );
        $lazy_iframes = (int) EasyOpt_Config::get( 'lazy_iframes', 0 );
        $lazy_videos  = (int) EasyOpt_Config::get( 'lazy_videos', 0 );

        if ( ! $lazy_images && ! $lazy_iframes && ! $lazy_videos ) {
            return;
        }

        self::$conflicts = self::probe();
    }

    /**
     * Probe for other ACTIVE lazy-load systems, independently of whether our
     * own lazyload is enabled.
     *
     * (2.6.0) Extracted from detect() so the pre-flight scanner can reuse the
     * exact same runtime capability checks instead of duplicating them. Every
     * probe here asks "is the feature actually switched on?", not "is the
     * plugin installed?" — an installed-but-disabled plugin is not a conflict,
     * and excluding on that basis would be a false positive that silently
     * disables a feature the user wanted.
     *
     * Side-effect free.
     *
     * @since 2.6.0
     * @return string[] Names of detected systems.
     */
    public static function probe() {

        $conflicts = array();

        // ── Avada built-in lazy load ──
        $fusion = get_option( 'fusion_options' );
        if ( is_array( $fusion ) && ! empty( $fusion['lazy_load'] ) && 'avada' === $fusion['lazy_load'] ) {
            $conflicts[] ='Avada (built-in lazy load)';
        }

        // ── Jetpack Lazy Images module ──
        if ( class_exists( 'Jetpack' ) && method_exists( 'Jetpack', 'is_module_active' ) ) {
            if ( \Jetpack::is_module_active( 'lazy-images' ) ) {
                $conflicts[] ='Jetpack Lazy Images';
            }
        }

        // ── EWWW Image Optimizer lazy load ──
        if ( function_exists( 'ewww_image_optimizer_get_option' ) ) {
            if ( ewww_image_optimizer_get_option( 'ewww_image_optimizer_lazy_load' ) ) {
                $conflicts[] ='EWWW Image Optimizer (lazy load)';
            }
        }

        // ── Smush lazy load ──
        // (2.6.5) 'wp-smush-lazy_load' is not where Smush keeps this. Verified
        // against Smush 4.3.2: Settings::$settings_option_id is
        // 'wp-smush-settings', read with get_site_option(), and lazy_load is a
        // key inside it. The old read returned nothing on every current
        // install, so Smush's lazy load was never actually detected here.
        // The legacy name is still checked for much older versions.
        if ( class_exists( 'WP_Smush' ) || class_exists( 'Smush\\Core\\Settings' ) ) {
            $smush = get_site_option( 'wp-smush-settings', array() );
            $on    = is_array( $smush ) && ! empty( $smush['lazy_load'] );
            if ( ! $on ) {
                $on = ! empty( get_option( 'wp-smush-lazy_load' ) );
            }
            if ( $on ) {
                $conflicts[] ='Smush (lazy load)';
            }
        }

        // ── LiteSpeed Cache lazy load ──
        if ( defined( 'LSCWP_V' ) ) {
            $ls_options = get_option( 'litespeed.conf.media-lazy' );
            if ( $ls_options ) {
                $conflicts[] ='LiteSpeed Cache (lazy load)';
            }
        }

        // ── Perfmatters lazy load ──
        if ( function_exists( 'perfmatters_get_option' ) ) {
            $pm = get_option( 'perfmatters_options' );
            if ( is_array( $pm ) && ! empty( $pm['lazyload']['lazy_loading'] ) ) {
                $conflicts[] = 'Perfmatters (lazy load)';
            }
        }

        /**
         * Filter the detected lazy-load conflicts.
         *
         * @since 2.6.0
         * @param string[] $conflicts Names of detected systems.
         */
        return (array) apply_filters( 'easyopt_lazyload_conflicts', $conflicts );
    }

    /**
     * Show a warning when duplicate lazyload is detected.
     */
    public static function render_notice() {

        if ( empty( self::$conflicts ) ) {
            return;
        }
        $stamp = md5( implode( '|', self::$conflicts ) );
        if ( class_exists( 'EasyOpt_Notices' )
            && ( ! EasyOpt_Notices::is_advisory_screen() || EasyOpt_Notices::dismissed( 'compat_lazyload', $stamp ) ) ) {
            return;
        }

        $names = implode( ', ', array_map( 'esc_html', self::$conflicts ) );

        echo '<div class="notice notice-warning is-dismissible">';
        printf(
            '<p><strong>%s</strong> %s</p>',
            esc_html__( 'Images are being lazy-loaded twice.', 'easy-optimizer' ),
            sprintf(
                /* translators: %s: name(s) of the other plugin(s). */
                esc_html__( 'Turn lazy loading off in %s — Easy Optimizer is already handling it. Running both can leave images stuck blank.', 'easy-optimizer' ),
                '<strong>' . $names . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
            )
        );
        echo '<p>';
        if ( class_exists( 'EasyOpt_Notices' ) ) {
            echo EasyOpt_Notices::plugin_action_button( self::$conflicts[0] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped internally.
            echo ' &nbsp; ' . EasyOpt_Notices::plugin_deactivate_button( self::$conflicts[0], 'link' ); // phpcs:ignore WordPress.Security.EscapeOutput
            echo ' &nbsp; ' . EasyOpt_Notices::link( 'compat_lazyload', $stamp, __( 'Keep both — I\'ll manage it', 'easy-optimizer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</p>';
        echo '</div>';
    }
}
