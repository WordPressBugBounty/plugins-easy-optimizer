<?php
/**
 * Defer JS — adds the native `defer` attribute to external scripts.
 *
 * This is the "defer" method for the Delay JS feature. Instead of
 * rewriting script types to prevent execution until user interaction,
 * Defer uses the browser's built-in `defer` mechanism: scripts download
 * in parallel and execute after HTML parsing completes.
 *
 * Activated when Delay JS is enabled with method set to "defer".
 * Shares all settings (excludes, exclude jQuery, exclude URLs) with
 * the Delay JS module.
 *
 * @package EasyOptimizer
 * @since   2.0.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Defer_JS {

    /**
     * Process the HTML buffer — add defer to external scripts.
     *
     * @param string $html Full HTML buffer.
     * @return string Modified HTML.
     */
    public static function process_buffer( $html ) {

        // Guard: only runs when Delay JS is enabled with method = defer.
        if ( ! (int) EasyOpt_Config::get( 'delay_js', 0 ) ) {
            return $html;
        }
        if ( 'defer' !== EasyOpt_Config::get( 'delay_js_method', 'delay' ) ) {
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

        // (2.6.5) Per-URL exclusions. The class docblock has always said this
        // module shares "exclude URLs" with Delay JS; it never actually read
        // them. Choosing the Defer method silently discarded every URL a user
        // had excluded, so the documented escape hatch for a page that Defer
        // breaks did nothing and the only remaining option was switching the
        // whole feature off. Reported by a Tutor LMS user whose course pages
        // hung on a loading spinner.
        if ( class_exists( 'EasyOpt_Delay_JS' )
             && method_exists( 'EasyOpt_Delay_JS', 'is_url_excluded' )
             && EasyOpt_Delay_JS::is_url_excluded() ) {
            return $html;
        }

        // Use the same exclude list as Delay JS.
        $excludes = self::get_excludes();

        // Exclude jQuery when the shared "Exclude jQuery" toggle is on.
        if ( (int) EasyOpt_Config::get( 'delay_js_exclude_jquery', 1 ) ) {
            $excludes[] = 'jquery';
            $excludes[] = 'jQuery';
        }

        // Match external <script> tags with a src attribute.
        $mask = class_exists( 'EasyOpt_HTML_Mask' ) ? new EasyOpt_HTML_Mask() : null;
        if ( $mask ) {
            $html = $mask->mask( $html );
        }

        $html = preg_replace_callback(
            '#<script\b([^>]*\bsrc\s*=\s*["\'][^"\']+["\'][^>]*)>#i',
            function ( $match ) use ( $excludes ) {
                $tag   = $match[0];
                $attrs = $match[1];

                // Already has defer or async — skip.
                if ( preg_match( '/\b(defer|async)\b/i', $attrs ) ) {
                    return $tag;
                }

                // Skip non-JS type attributes (e.g., application/ld+json, module).
                if ( preg_match( '/type\s*=\s*["\']([^"\']+)["\']/i', $attrs, $type_m ) ) {
                    $type = strtolower( trim( $type_m[1] ) );
                    $js_types = array(
                        'text/javascript',
                        'application/javascript',
                        'application/ecmascript',
                        'application/x-javascript',
                    );
                    if ( '' !== $type && ! in_array( $type, $js_types, true ) ) {
                        return $tag;
                    }
                }

                // Check exclusion patterns.
                foreach ( $excludes as $pattern ) {
                    if ( '' !== $pattern && false !== stripos( $tag, $pattern ) ) {
                        return $tag;
                    }
                }

                // Add defer attribute.
                return preg_replace( '/\s*>$/', ' defer>', $tag, 1 );
            },
            $html
        );

        if ( $mask ) {
            $html = $mask->unmask( $html );
        }

        return $html;
    }

    /**
     * Get exclusion patterns — reads from the shared Delay JS setting.
     *
     * @return string[]
     */
    private static function get_excludes() {

        $user = EasyOpt_Config::get( 'delay_js_exclude', '' );
        if ( empty( $user ) ) {
            return array();
        }

        $lines = preg_split( '/\r\n|\r|\n/', (string) $user );
        return array_filter( array_map( 'trim', $lines ) );
    }
}
