<?php
/**
 * Multilingual compatibility — cookie-driven language switching.
 *
 * Polylang and WPML can be configured WITHOUT a language segment in the
 * URL ("hide default language" / "language by cookie"), in which case the
 * SAME url renders in different languages depending on a cookie:
 *
 *   pll_language               (Polylang)
 *   wp-wpml_current_language   (WPML)
 *   _icl_current_language      (WPML, legacy)
 *
 * Without this module the first visitor's language was cached and served
 * to everyone at that URL. Two things are needed to fix that, and the
 * plugin already has both — this module just wires them up:
 *
 *   1. `easyopt_cache_include_cookies` makes the cache FILENAME vary by
 *      the cookie's value, so each language gets its own file. The PHP
 *      drop-in and EasyOpt_Cache read the identical key.
 *   2. Registering any include-cookie flips the site to PHP/drop-in
 *      serving (see EasyOpt_Cache::has_include_cookies()), because
 *      mod_rewrite cannot key a filename on a cookie VALUE — the static
 *      fast path has no way to tell the languages apart. The matching
 *      cookie names are also listed in the .htaccess bypass conditions
 *      as defence in depth.
 *
 * The cookies are only registered when the relevant plugin is actually
 * active, so single-language sites keep the .htaccess fast path and pay
 * nothing.
 *
 * @package EasyOptimizer
 * @since   2.5.5
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Compat_Multilingual {

    /**
     * Wire up. Runs on the front end and during preload writes; the filter
     * is cheap and memoised by the cache layer.
     */
    public static function init() {
        add_filter( 'easyopt_cache_include_cookies', array( __CLASS__, 'register_cookies' ) );
    }

    /**
     * Add the language cookies to the cache key when a cookie-driven
     * multilingual plugin is active.
     *
     * @param array $cookies Cookie names already registered.
     * @return array
     */
    public static function register_cookies( $cookies ) {

        if ( ! is_array( $cookies ) ) {
            $cookies = array();
        }

        if ( self::polylang_active() ) {
            $cookies[] = 'pll_language';
        }

        if ( self::wpml_active() ) {
            $cookies[] = 'wp-wpml_current_language';
            $cookies[] = '_icl_current_language';
        }

        /**
         * Filter the multilingual cookies added to the page-cache key.
         *
         * Use this for other language switchers, or return an empty array
         * to opt out entirely (restoring .htaccess serving on a site whose
         * languages are fully separated by URL path or domain).
         *
         * @since 2.5.5
         * @param array $cookies Cookie names contributed by this module.
         */
        $cookies = (array) apply_filters( 'easyopt_multilingual_cookies', $cookies );

        return array_values( array_unique( array_filter( array_map( 'strval', $cookies ) ) ) );
    }

    /**
     * Polylang (free or Pro) active?
     *
     * @return bool
     */
    private static function polylang_active() {
        return defined( 'POLYLANG_VERSION' )
            || function_exists( 'pll_current_language' )
            || class_exists( 'Polylang' );
    }

    /**
     * WPML active?
     *
     * @return bool
     */
    private static function wpml_active() {
        return defined( 'ICL_SITEPRESS_VERSION' ) || class_exists( 'SitePress' );
    }
}
