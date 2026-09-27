<?php
/**
 * Divi theme compatibility.
 *
 * Divi (ET Core ≥ 4.10) moves jQuery from <head> to <body> for performance
 * via the `et_builder_enable_jquery_body` filter. When Easy Optimizer's
 * Delay JS rewrites script tags, jQuery in the body gets delayed — but
 * Divi's own inline scripts reference jQuery immediately after the moved
 * tag, causing "jQuery is not defined" errors that break the layout.
 *
 * Fix: when Delay JS is active and Divi ≥ 4.10 is detected, keep jQuery
 * in the <head> by returning false from the filter.
 *
 * @package EasyOptimizer
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Compat_Divi {

    /**
     * Wire up — only when Divi/Extra is the active theme.
     */
    public static function init() {

        // ET_CORE_VERSION is defined by Divi, Extra, and the Divi Builder plugin.
        if ( ! defined( 'ET_CORE_VERSION' ) ) {
            return;
        }

        self::maybe_disable_jquery_body();
    }

    /**
     * Prevent Divi from moving jQuery to <body> when Delay JS is on.
     */
    public static function maybe_disable_jquery_body() {

        // Only needed when Delay JS is enabled.
        if ( ! (int) EasyOpt_Config::get( 'delay_js', 0 ) ) {
            return;
        }

        // The jQuery-body feature was added in ET Core 4.10.
        if ( version_compare( ET_CORE_VERSION, '4.10', '<' ) ) {
            return;
        }

        add_filter( 'et_builder_enable_jquery_body', '__return_false' );
    }
}
