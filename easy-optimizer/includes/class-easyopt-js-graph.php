<?php
/**
 * Script dependency helpers for Delay JS (2.8.0). Defer JS dropped them to
 * work the way WP Rocket's does (see EasyOpt_Defer_JS).
 *
 * Both engines used to decide script by script, by substring. That broke the
 * moment one script needed another: excluding `slider.js` from Delay left its
 * jQuery delayed, so the slider ran first and threw; Defer deferred a script
 * while its `-js-after` inline code still ran immediately, ahead of it. What
 * ties them together is WordPress' own registry, so this asks it.
 *
 * WordPress prints every enqueued script with a predictable id:
 *   {handle}-js               the external file
 *   {handle}-js-extra         wp_localize_script() data (plain `var`s)
 *   {handle}-js-translations  wp_set_script_translations() (needs wp-i18n)
 *   {handle}-js-before        wp_add_inline_script( …, 'before' )
 *   {handle}-js-after         wp_add_inline_script( …, 'after' )
 *
 * @package EasyOptimizer
 * @since   2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_JS_Graph {

    /** Handles that together make up jQuery in core. */
    const JQUERY = array( 'jquery', 'jquery-core', 'jquery-migrate' );

    /**
     * Handle and part from a script tag's attributes.
     *
     * @param string $atts Attribute string of the opening <script> tag.
     * @return array|null array( handle, part ) — part is '' for the file
     *                    itself, else extra|translations|before|after.
     */
    public static function tag_handle( $atts ) {
        if ( ! preg_match( '/\bid\s*=\s*["\']([^"\']+?)-js(?:-(extra|translations|before|after))?["\']/i', (string) $atts, $m ) ) {
            return null;
        }
        return array( $m[1], isset( $m[2] ) ? strtolower( $m[2] ) : '' );
    }

    /**
     * Direct dependencies of a handle, from the live script registry.
     *
     * @param string $handle
     * @return string[] Empty when the registry does not know the handle (for
     *                  example a page re-processed outside its own request).
     */
    public static function deps( $handle ) {
        $reg = self::registry();
        if ( ! isset( $reg[ $handle ] ) || ! isset( $reg[ $handle ]->deps ) ) {
            return array();
        }
        return array_map( 'strval', (array) $reg[ $handle ]->deps );
    }

    /**
     * Add every (recursive) dependency to a set of handles.
     *
     * @param array<string,bool> $set handle => true
     * @return array<string,bool>
     */
    public static function with_deps( array $set ) {
        $stack = array_keys( $set );
        while ( ! empty( $stack ) ) {
            $h = array_pop( $stack );
            foreach ( self::deps( $h ) as $d ) {
                if ( '' !== $d && ! isset( $set[ $d ] ) ) {
                    $set[ $d ] = true;
                    $stack[]   = $d;
                }
            }
        }
        return $set;
    }

    /**
     * Does inline code look like it calls jQuery?
     *
     * Deliberately generous: a false positive only keeps jQuery running
     * normally, which is the pre-optimisation behaviour.
     */
    public static function uses_jquery( $code ) {
        return 1 === preg_match( '/\bjQuery\b|(?<![\w$.])\$\s*\(|(?<![\w$.])\$\.(?:ajax|each|extend|fn|get|getJSON|post|when)\b/', (string) $code );
    }

    /** The registered-scripts map, or an empty array outside a WordPress request. */
    private static function registry() {
        global $wp_scripts;
        if ( is_object( $wp_scripts ) && isset( $wp_scripts->registered ) && is_array( $wp_scripts->registered ) ) {
            return $wp_scripts->registered;
        }
        return array();
    }
}
