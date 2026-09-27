<?php
/**
 * Detects other font-handling systems and warns when they overlap Easy
 * Optimizer's own font features (2.6.0).
 *
 * Font optimisation had NO conflict detection of any kind before this — the
 * only overlap surface in the plugin with none. Two systems rewriting
 * @font-face, both emitting above-the-fold preloads into a finite hint
 * budget, and (with Smart Lazyload Fonts on) one stripping declarations the
 * other is preloading, is a genuinely bad combination that produced no
 * warning whatsoever.
 *
 * It also covers a self-conflict: Easy Fonts is FluxPress's own plugin, and
 * a user running both got exactly this collision from a single vendor.
 *
 * Detection follows the compat-lazyload pattern — RUNTIME capability probes
 * (is the feature actually switched on?) rather than "is the plugin
 * installed?", because an installed-but-disabled plugin is not a conflict
 * and excluding on that basis would be a false positive.
 *
 * Advisory only. This module never disables anything, in either plugin.
 *
 * @package EasyOptimizer
 * @since   2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Compat_Fonts {

    /** @var string[] Detected conflicting font systems. */
    private static $conflicts = array();

    /**
     * Wire up. Detection + notice are admin-only.
     */
    public static function init() {
        if ( ! is_admin() ) {
            return;
        }
        add_action( 'admin_init',    array( __CLASS__, 'detect' ) );
        add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
    }

    /**
     * True when any Easy Optimizer font feature is enabled.
     *
     * @return bool
     */
    public static function eo_fonts_active() {
        // (2.6.0) Smart Preload Fonts ONLY. font-display: swap and Smart
        // Lazyload Fonts don't collide with another plugin the way preloading
        // does — two systems emitting preload hints compete for a finite hint
        // budget and can preload fonts the other has stripped. Gating on all
        // three made this notice fire on setups where nothing was wrong.
        return (bool) (int) EasyOpt_Config::get( 'preload_fonts', 0 );
    }

    /**
     * Probe for other active font handlers.
     *
     * Public and side-effect free so the preflight scanner can reuse it
     * without duplicating any of this logic.
     *
     * @return string[] Human-readable names of conflicting systems.
     */
    public static function probe() {

        $found = array();

        // ── OMGF (Host Webfonts Local) ──
        // Constant first; fall back to its option so a renamed folder or an
        // older build is still caught.
        if ( defined( 'OMGF_PLUGIN_FILE' ) || defined( 'OMGF_DB_VERSION' ) ) {
            $found[] = 'OMGF (Host Webfonts Local)';
        } elseif ( '' !== (string) get_option( 'omgf_optimize_fonts', '' ) ) {
            $found[] = 'OMGF (Host Webfonts Local)';
        }

        // ── Perfmatters local fonts ──
        if ( function_exists( 'perfmatters_get_option' ) ) {
            $pm = get_option( 'perfmatters_options' );
            if ( is_array( $pm ) && ! empty( $pm['fonts']['local_google_fonts'] ) ) {
                $found[] = 'Perfmatters';
            }
        }

        // (2.6.0) Easy Fonts, LiteSpeed Cache and SiteGround Speed Optimizer
        // were removed from this list. Easy Fonts is verified compatible, and
        // the other two localise or rewrite font FILES rather than competing
        // for preload hints — flagging them produced warnings about setups
        // that work fine.

        /**
         * Filter the detected font-handling conflicts.
         *
         * @since 2.6.0
         * @param string[] $found Names of detected systems.
         */
        return (array) apply_filters( 'easyopt_font_conflicts', $found );
    }

    /**
     * Populate the conflict list, but only when our own font features are on.
     */
    public static function detect() {
        if ( ! self::eo_fonts_active() ) {
            return;
        }
        self::$conflicts = self::probe();
    }

    /**
     * Advisory notice on the screens where the decision is actually made.
     */
    public static function render_notice() {

        if ( empty( self::$conflicts ) ) {
            return;
        }

        $stamp = md5( implode( '|', self::$conflicts ) );
        if ( class_exists( 'EasyOpt_Notices' )
            && ( ! EasyOpt_Notices::is_advisory_screen() || EasyOpt_Notices::dismissed( 'compat_fonts', $stamp ) ) ) {
            return;
        }

        $names = implode( ', ', array_map( 'esc_html', self::$conflicts ) );

        echo '<div class="notice notice-warning is-dismissible">';
        printf(
            '<p><strong>%s</strong> %s</p>',
            esc_html__( 'Two plugins are preloading fonts.', 'easy-optimizer' ),
            sprintf(
                /* translators: %s: name(s) of the other plugin(s). */
                esc_html__( 'Turn font handling off in %s so the preload hints stop competing.', 'easy-optimizer' ),
                '<strong>' . $names . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
            )
        );
        echo '<p>';
        if ( class_exists( 'EasyOpt_Notices' ) ) {
            echo EasyOpt_Notices::plugin_action_button( self::$conflicts[0] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped internally.
            echo ' &nbsp; ' . EasyOpt_Notices::plugin_deactivate_button( self::$conflicts[0], 'link' ); // phpcs:ignore WordPress.Security.EscapeOutput
            echo ' &nbsp; ' . EasyOpt_Notices::link( 'compat_fonts', $stamp, __( 'Keep both — I\'ll manage it', 'easy-optimizer' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
        }
        echo '</p>';
        echo '</div>';
    }
}
