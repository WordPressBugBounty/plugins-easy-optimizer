<?php
/**
 * Defer JS — adds the native `defer` attribute to external scripts.
 *
 * Scripts download in parallel and execute after HTML parsing completes,
 * in document order, before DOMContentLoaded.
 *
 * (2.8.0) Its own engine with its own toggle (easyopt_defer_js) and its own
 * exclusion list (easyopt_defer_js_exclude), so it can run alongside Delay
 * JS. Defer runs first in the buffer pipeline; Delay then rewrites the
 * delayed scripts and keeps `defer`, so its loader replays plain scripts,
 * then deferred, then async. Exclude URLs and Exclude jQuery stay shared
 * with Delay JS.
 *
 * (2.8.0) Works the way WP Rocket's Defer JS does (3.24, DeferJS.php):
 *   - every script file gets `defer`, unless it is already async/defer or
 *     matches an exclusion: the user's list, Exclude jQuery, or the built-in
 *     list of files known to break when deferred (EXTERNAL_EXCLUSIONS);
 *   - inline code that calls jQuery is wrapped in a DOMContentLoaded
 *     listener so it runs after the deferred jQuery, unless it matches
 *     INLINE_EXCLUSIONS. When jQuery itself is excluded nothing is wrapped.
 * Script dependencies are not checked: inline code that calls another
 * deferred file straight away runs before it, and the fix is to exclude
 * that file. Two things differ on purpose: non-JavaScript blocks (JSON,
 * templates, modules) are never wrapped, and neither are our own scripts.
 *
 * @package EasyOptimizer
 * @since   2.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Defer_JS {

    /** Classic-script type values. Anything else (module, JSON, easyoptscript) is left alone. */
    const JS_TYPES = array( '', 'text/javascript', 'application/javascript', 'application/ecmascript', 'application/x-javascript' );

    /**
     * (2.8.0) Script files that break when deferred, matched as regular
     * expressions against the src. WP Rocket's defer_js_external_exclusions
     * list (dynamic-lists.json, 3.24), with its LCP beacon swapped for ours.
     */
    const EXTERNAL_EXCLUSIONS = array(
        'gist.github.com',
        'content.jwplatform.com',
        'js.hsforms.net',
        'www.uplaunch.com',
        'google.com/recaptcha',
        'widget.reviews.co.uk',
        'verify.authorize.net/anetseal',
        'lib/admin/assets/lib/webfont/webfont.min.js',
        'app.mailerlite.com',
        'widget.reviews.io',
        'simplybook.(.*)/v2/widget/widget.js',
        '/wp-includes/js/dist/i18n.min.js',
        '/wp-content/plugins/wpfront-notification-bar/js/wpfront-notification-bar(.*).js',
        '/wp-content/plugins/oxygen/component-framework/vendor/aos/aos.js',
        '/wp-content/plugins/ewww-image-optimizer/includes/check-webp(.min)?.js',
        'static.mailerlite.com/data/(.*).js',
        'cdn.voxpow.com/static/libs/v1/(.*).js',
        'cdn.voxpow.com/media/trackers/js/(.*).js',
        'use.typekit.net',
        'www.idxhome.com',
        '/wp-includes/js/dist/vendor/lodash(.min)?.js',
        '/wp-includes/js/dist/api-fetch(.min)?.js',
        '/wp-includes/js/dist/i18n(.min)?.js',
        '/wp-includes/js/dist/vendor/wp-polyfill(.min)?.js',
        '/wp-includes/js/dist/url(.min)?.js',
        '/wp-includes/js/dist/hooks(.min)?.js',
        'www.paypal.com/sdk/js',
        'js-eu1.hsforms.net',
        'yanovis.Voucher.js',
        '/carousel-upsells-and-related-product-for-woocommerce/assets/js/glide.min.js',
        'use.typekit.com',
        '/artale/modules/kirki/assets/webfont.js',
        '/api/scripts/lb_cs.js',
        'js.hscta.net/cta/current.js',
        'widget.refari.co',
        'player.vdocipher.com',
        '/assets/easyopt-beacon(.min)?.js',
    );

    /**
     * (2.8.0) Inline code that is never wrapped, matched as plain text.
     * WP Rocket's defer_js_inline_exclusions list (dynamic-lists.json, 3.24).
     */
    const INLINE_EXCLUSIONS = array(
        'DOMContentLoaded',
        'document.write',
        'window.lazyLoadOptions',
        'N.N2_',
        'rev_slider_wrapper',
        'FB3D_CLIENT_LOCALE',
        'ewww_webp_supported',
        'anr_captcha_field_div',
        'renderInvisibleReCaptcha',
        'bookingInProgress',
    );

    /** (2.8.0) What marks inline code as calling jQuery (WP Rocket's rocket_defer_jquery_patterns). */
    const JQUERY_PATTERNS = 'jQuery|\$\.\(|\$\(';

    /**
     * Process the HTML buffer — add defer to external scripts.
     *
     * @param string $html Full HTML buffer.
     * @return string Modified HTML.
     */
    public static function process_buffer( $html ) {

        if ( ! EasyOpt_Config::js_defer_on() ) {
            return $html;
        }

        // WooCommerce dynamic pages — skip entirely.
        if ( EasyOpt_Config::is_woo_dynamic_page() ) {
            return $html;
        }

        // Per-request debug switch: ?nodelayjs (or the master ?nooptimize).
        if ( function_exists( 'easyopt_debug_switch' )
             && ( easyopt_debug_switch( 'nodelayjs' ) || easyopt_debug_switch( 'nooptimize' ) ) ) {
            return $html;
        }

        // (2.6.2) Logged-in gate — see easyopt_skip_for_logged_in(). The
        // legacy easyopt_defer_js_admin filter still forces it back on.
        if ( easyopt_skip_for_logged_in( 'defer_js' )
             && ! apply_filters( 'easyopt_defer_js_admin', false ) ) {
            return $html;
        }

        // (2.6.5) Per-URL exclusions, shared with Delay JS. Reported by a
        // Tutor LMS user whose course pages hung on a loading spinner.
        if ( class_exists( 'EasyOpt_Delay_JS' )
             && method_exists( 'EasyOpt_Delay_JS', 'is_url_excluded' )
             && EasyOpt_Delay_JS::is_url_excluded() ) {
            return $html;
        }

        $excludes = self::get_excludes();
        $builtin  = '#(' . str_replace( '#', '\#', implode( '|', self::EXTERNAL_EXCLUSIONS ) ) . ')#i';
        // Once jQuery is kept out of Defer, inline code has nothing to wait for.
        $wrap = ! in_array( 'jquery', $excludes, true )
            && 1 !== preg_match( '/jquery(?:.*)?\.js/i', implode( '|', $excludes ) );

        $mask = class_exists( 'EasyOpt_HTML_Mask' ) ? new EasyOpt_HTML_Mask() : null;
        if ( $mask ) {
            $html = $mask->mask( $html );
        }

        $html = preg_replace_callback(
            '#<script\b([^>]*)>(.*?)</script>#si',
            function ( $m ) use ( $excludes, $builtin, $wrap ) {
                return self::rewrite( $m[0], $m[1], $m[2], $excludes, $builtin, $wrap );
            },
            $html
        );

        if ( $mask ) {
            $html = $mask->unmask( $html );
        }

        return $html;
    }

    /** Defer one script file, or wrap one inline jQuery snippet. */
    private static function rewrite( $tag, $atts, $body, array $excludes, $builtin, $wrap ) {
        if ( ! self::is_classic( $atts ) ) {
            return $tag;
        }

        if ( ! self::has_src( $atts ) ) {
            if ( $wrap && self::wraps( $tag, $atts, $body, $excludes ) ) {
                return '<script' . $atts . ">window.addEventListener('DOMContentLoaded', function() {" . $body . '});</script>';
            }
            return $tag;
        }

        if ( ! self::eligible( $tag, $atts, $excludes, $builtin ) ) {
            return $tag;
        }
        $open = '<script' . $atts . '>';
        return preg_replace( '/\s*>$/', ' defer>', $open, 1 ) . $body . '</script>';
    }

    /** Should this script file get defer? */
    private static function eligible( $tag, $atts, array $excludes, $builtin ) {
        if ( preg_match( '/\b(?:defer|async)\b/i', $atts ) ) {
            return false;
        }
        foreach ( $excludes as $pattern ) {
            if ( '' !== $pattern && false !== stripos( $tag, $pattern ) ) {
                return false;
            }
        }
        return ! ( preg_match( '/(?<![\w-])src\s*=\s*["\']([^"\']+)["\']/i', $atts, $src )
                   && preg_match( $builtin, $src[1] ) );
    }

    /** Does this inline code call jQuery, and may it be wrapped? */
    private static function wraps( $tag, $atts, $body, array $excludes ) {
        if ( '' === trim( $body ) || preg_match( '/\bid\s*=\s*["\']easyopt-/i', $atts ) ) {
            return false;
        }
        if ( 1 !== preg_match( '/(' . self::JQUERY_PATTERNS . ')/msi', $body ) ) {
            return false;
        }
        foreach ( array_merge( self::INLINE_EXCLUSIONS, $excludes ) as $pattern ) {
            if ( '' !== $pattern && false !== stripos( $tag, $pattern ) ) {
                return false;
            }
        }
        return true;
    }

    /** A classic script? (Not a module, JSON, template, or a tag Delay JS already rewrote.) */
    private static function is_classic( $atts ) {
        if ( preg_match( '/\btype\s*=\s*["\']([^"\']*)["\']/i', $atts, $m ) ) {
            return in_array( strtolower( trim( $m[1] ) ), self::JS_TYPES, true );
        }
        return true;
    }

    /** Has a real src (not Delay JS's data-easyopt-src)? */
    private static function has_src( $atts ) {
        return 1 === preg_match( '/(?<![\w-])src\s*=\s*["\'][^"\']+["\']/i', $atts );
    }

    /**
     * The user's exclusions, matched as text against the whole tag: the
     * Delay loader, Defer's own list, and jQuery when the shared "Exclude
     * jQuery" toggle is on.
     *
     * @return string[]
     */
    private static function get_excludes() {
        // The Delay JS loader is injected after this pass; kept as a guard.
        $list = array( 'easyopt-delay-js' );

        $user = EasyOpt_Config::defer_js_exclude();
        if ( '' !== trim( $user ) ) {
            $list = array_merge( $list, array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $user ) ) ) );
        }
        if ( (int) EasyOpt_Config::get( 'delay_js_exclude_jquery', 1 ) ) {
            $list[] = 'jquery';
            $list[] = 'jQuery';
        }
        return (array) apply_filters( 'easyopt_defer_js_exclusions', $list );
    }
}
