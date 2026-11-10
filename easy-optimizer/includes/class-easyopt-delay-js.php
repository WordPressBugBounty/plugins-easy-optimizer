<?php
/**
 * Delay JavaScript execution for Easy Optimizer.
 *
 * Rewrites <script> tags in the output buffer so they are not executed
 * until the first user interaction (mouse / keyboard / touch / scroll).
 *
 * External scripts: src → data-easyopt-src, type → data-easyopt-type, type="easyoptscript"
 * Inline scripts:   type → data-easyopt-type, type="easyoptscript"
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Delay_JS {

    /**
     * Process the HTML buffer — rewrite scripts + inject the loader.
     */
    public static function process_buffer( $html ) {

        // (2.8.0) The engine flag: a not-yet-migrated "method = defer" site
        // has delay_js on but runs Defer JS instead.
        if ( ! EasyOpt_Config::js_delay_on() ) {
            return $html;
        }

        // WooCommerce dynamic pages — delaying JS on Cart, Checkout and
        // My Account breaks payment gateways, cart AJAX, coupon handling
        // and shipping calculators.
        if ( EasyOpt_Config::is_woo_dynamic_page() ) {
            return $html;
        }

        // Per-request debug switch: ?nodelayjs (or the master ?nooptimize).
        if ( function_exists( 'easyopt_debug_switch' )
             && ( easyopt_debug_switch( 'nodelayjs' ) || easyopt_debug_switch( 'nooptimize' ) ) ) {
            return $html;
        }

        // (2.6.2) Logged-in gate — skipped for EVERY logged-in user,
        // administrators included, unless "Cache for Logged-in Users" is on.
        // The legacy easyopt_delay_js_admin filter still forces it back on,
        // which is also how ?eopview keeps working.
        if ( easyopt_skip_for_logged_in( 'delay_js' )
             && ! apply_filters( 'easyopt_delay_js_admin', false ) ) {
            return $html;
        }

        // URL exclusion.
        if ( self::is_url_excluded() ) {
            return $html;
        }

        // Protect <textarea>/<pre>/<code>/<xmp>/<noscript>/<svg> so literal
        // <script> markup shown as text on the page is never rewritten.
        $mask = class_exists( 'EasyOpt_HTML_Mask' ) ? new EasyOpt_HTML_Mask() : null;
        if ( $mask ) {
            $html = $mask->mask( $html );
        }

        // ── Step 0: Convert matching inline <script> blocks to external files ──
        $html = self::convert_inline_to_external( $html );

        // Build exclusion list.
        $exclusions = self::get_exclusions();

        // (2.8.0) Scripts that stay immediate keep what they need immediate.
        $kept = self::kept_handles( $html, $exclusions );

        // Find all <script> tags.
        // We process the raw HTML to avoid DOM parser issues with inline JS.
        $html = preg_replace_callback(
            '#<script\b([^>]*)>(.*?)</script>#si',
            function ( $match ) use ( $exclusions, $kept ) {
                return self::rewrite_script_tag( $match[0], $match[1], $match[2], $exclusions, $kept );
            },
            $html
        );

        // Inject the loader script before the LAST </body> tag.
        // Using strrpos() so multiple </body> tags (from theme bugs or
        // accidental copy-paste) don't result in multiple loader injections.
        //
        // IDEMPOTENCE: skip if this document already carries the loader.
        //
        // The preload runner fetches pages over HTTP with a synthetic
        // logged-in cookie, deliberately so the live output buffer runs and
        // writes the cache (see EasyOpt_Cache_Preload::build_warm_headers).
        // The body it receives is therefore ALREADY optimized — and
        // optimize_prefetched_html() then runs the whole pipeline over it a
        // second time to pin the correct per-page Used CSS key. Every other
        // processor is naturally idempotent; this one appended
        // unconditionally, so prefetch-generated pages ended up with two
        // loaders. Two copies of the delay bootstrap means listeners bound
        // twice and delayed scripts potentially executed twice.
        if ( false === stripos( $html, 'id="easyopt-delay-js"' ) ) {
            $loader = '<script type="text/javascript" id="easyopt-delay-js" src="' . esc_url( EASYOPT_URL . 'assets/easyopt-delay.min.js' ) . '"></script>';
            $pos    = strripos( $html, '</body>' );
            if ( false !== $pos ) {
                $html = substr( $html, 0, $pos ) . $loader . substr( $html, $pos );
            } else {
                // No </body> found — append at end as a last resort.
                $html .= $loader;
            }
        }

        if ( $mask ) {
            $html = $mask->unmask( $html );
        }

        return $html;
    }

    /**
     * Convert matching inline <script> blocks to external files cached on disk.
     * The replacement <script src="…"> tags are then delayed by the main loop.
     *
     * Match keywords come from the `easyopt_delay_js_include_inline` option
     * (one per line). Each pattern is checked against the entire script tag
     * (id, attributes, body) so things like `gtag(`, `fbq(`, `_paq.push` work.
     */
    private static function convert_inline_to_external( $html ) {

        $include = EasyOpt_Config::get( 'delay_js_include_inline', '' );
        if ( empty( $include ) ) {
            return $html;
        }

        $patterns = array_filter( array_map( 'trim', explode( "\n", $include ) ) );
        if ( empty( $patterns ) ) {
            return $html;
        }

        // Cache directory: wp-content/cache/easyopt/js/inline/
        $cache_dir  = trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt/js/inline/';
        $cache_url  = trailingslashit( content_url() ) . 'cache/easyopt/js/inline/';
        if ( ! is_dir( $cache_dir ) ) {
            wp_mkdir_p( $cache_dir );
        }
        if ( ! is_dir( $cache_dir ) ) {
            return $html; // can't write — give up cleanly.
        }

        if ( ! preg_match_all( '#<script\b([^>]*)>(.*?)</script>#si', $html, $matches, PREG_SET_ORDER ) ) {
            return $html;
        }

        foreach ( $matches as $sm ) {

            $full_tag    = $sm[0];
            $atts_string = $sm[1];
            $content     = $sm[2];

            // Skip external scripts (have src=).
            if ( preg_match( '/\bsrc\s*=/i', $atts_string ) ) {
                continue;
            }

            // Skip non-JS types. Allow text/javascript, application/javascript,
            // or no type. Matches what the main loop accepts.
            // (2.5.7) 'module' removed here too. This path externalises an
            // inline script to a file and delays it — for a script module that
            // is doubly wrong: it postpones hydration past the interaction
            // that needs it, AND moving the code to a different URL changes
            // how its relative imports resolve.
            if ( preg_match( '/type\s*=\s*["\']([^"\']+)["\']/i', $atts_string, $type_match ) ) {
                $type_val = strtolower( trim( $type_match[1] ) );
                $allowed  = array( 'text/javascript', 'application/javascript', '' );
                if ( ! in_array( $type_val, $allowed, true ) ) {
                    continue;
                }
            }

            // Empty body — nothing to externalise.
            $body = trim( $content );
            if ( '' === $body ) {
                continue;
            }

            // Match against entire tag (id, attrs, body).
            $matched = false;
            foreach ( $patterns as $p ) {
                if ( '' !== $p && false !== stripos( $full_tag, $p ) ) {
                    $matched = true;
                    break;
                }
            }
            if ( ! $matched ) {
                continue;
            }

            // Deterministic filename — same content reuses the same file across pages.
            $hash = substr( hash( 'sha256', $body ), 0, 12 );
            $file = $cache_dir . $hash . '.js';
            $url  = $cache_url . $hash . '.js';

            if ( file_exists( $file ) ) {
                // Referenced — refresh the GC last-used marker (≤1 touch/day).
                if ( class_exists( 'EasyOpt_Cache' ) ) {
                    EasyOpt_Cache::touch_if_stale( $file );
                }
            } elseif ( false === @file_put_contents( $file, $body, LOCK_EX ) ) {
                continue;
            }

            // Preserve id if present so debugging stays sane.
            $id_attr = '';
            if ( preg_match( '/\bid=["\']([^"\']+)["\']/', $atts_string, $idm ) ) {
                $id_attr = ' id="' . esc_attr( $idm[1] ) . '"';
            }

            $new_tag = '<script' . $id_attr . ' src="' . esc_url( $url ) . '"></script>';
            $html    = str_replace( $full_tag, $new_tag, $html );
        }

        return $html;
    }

    /**
     * (2.8.0) Handles that must not be delayed because a script the user (or
     * a built-in) excluded depends on them.
     *
     * Excluding `slider.js` used to leave its jQuery delayed, so the slider
     * ran first and threw — the user then had to find and exclude every
     * dependency by hand. Now an excluded script keeps, through WordPress'
     * own registry: its dependencies (recursively), its own inline parts
     * (-js-extra / -before / -translations / -after), and jQuery when
     * excluded inline code calls it.
     *
     * @param string   $html
     * @param string[] $exclusions
     * @return array<string,bool> handle => true
     */
    private static function kept_handles( $html, array $exclusions ) {
        if ( ! class_exists( 'EasyOpt_JS_Graph' )
             || ! preg_match_all( '#<script\b([^>]*)>(.*?)</script>#si', $html, $tags, PREG_SET_ORDER ) ) {
            return array();
        }
        $kept   = array();
        $jquery = false;
        foreach ( $tags as $t ) {
            if ( ! self::is_delayable_type( $t[1] )
                 || ! ( self::is_excluded( $t[0], $exclusions ) || self::is_own_script( $t[0] ) ) ) {
                continue;
            }
            $ref = EasyOpt_JS_Graph::tag_handle( $t[1] );
            if ( null !== $ref ) {
                if ( '' === $ref[1] || 'after' === $ref[1] ) {
                    $kept[ $ref[0] ] = true;
                } elseif ( 'before' === $ref[1] || 'translations' === $ref[1] ) {
                    foreach ( EasyOpt_JS_Graph::deps( $ref[0] ) as $d ) {
                        $kept[ $d ] = true;
                    }
                }
                continue;
            }
            if ( ! preg_match( '/\bsrc\s*=/i', $t[1] ) && '' !== trim( $t[2] ) && EasyOpt_JS_Graph::uses_jquery( $t[2] ) ) {
                $jquery = true;
            }
        }
        if ( $jquery ) {
            foreach ( EasyOpt_JS_Graph::JQUERY as $h ) {
                $kept[ $h ] = true;
            }
        }
        return EasyOpt_JS_Graph::with_deps( $kept );
    }

    /** Classic script (no type, text/javascript, application/javascript)? */
    private static function is_delayable_type( $atts_string ) {
        if ( preg_match( '/type\s*=\s*["\']([^"\']+)["\']/i', $atts_string, $type_match ) ) {
            return in_array( strtolower( trim( $type_match[1] ) ), array( 'text/javascript', 'application/javascript', '' ), true );
        }
        return true;
    }

    /** Does any exclusion pattern match the whole tag (src, id, content)? */
    private static function is_excluded( $full_tag, array $exclusions ) {
        foreach ( $exclusions as $excl ) {
            if ( '' !== $excl && false !== stripos( $full_tag, $excl ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * (2.8.0) "Delay external scripts only": the site's own files, jQuery
     * wherever it is hosted, and inline code naming them stay immediate.
     *
     * Other inline code stays delayed: that is where GTM and pixel snippets
     * live, and run early they load their scripts straight away. So do the
     * files Include Inline Scripts makes, which only look like our own.
     */
    private static function is_own_script( $full_tag ) {
        return (int) EasyOpt_Config::get( 'delay_js_external_only', 0 )
            && false === stripos( $full_tag, '/easyopt/js/inline/' )
            && self::is_excluded( $full_tag, array( '/wp-content/', '/wp-includes/', 'jquery.min.js', 'jquery.js', 'jquery-migrate' ) );
    }

    /**
     * Decide whether to rewrite a single script tag.
     */
    private static function rewrite_script_tag( $full_tag, $atts_string, $content, $exclusions, array $kept = array() ) {

        // Never touch our own loader.
        if ( false !== stripos( $atts_string, 'easyopt-delay-js' ) ) {
            return $full_tag;
        }

        // Skip already-rewritten tags.
        if ( false !== stripos( $atts_string, 'easyoptscript' ) ) {
            return $full_tag;
        }

        // Skip non-JS types (application/json, application/ld+json, text/template, etc.)
        // (2.5.7) 'module' REMOVED from the allowlist. Script modules
        // (Interactivity API, wp_register_script_module) are deferred by
        // default, evaluated once per resolved URL, and ordered against the
        // import map — none of which survives being re-inserted by a delay
        // scheduler. Delaying them postponed block hydration until the first
        // user interaction, which swallowed that interaction. Defer JS already
        // excluded them; this aligns the two.
        if ( preg_match( '/type\s*=\s*["\']([^"\']+)["\']/i', $atts_string, $type_match ) ) {
            $type_val = strtolower( trim( $type_match[1] ) );
            $allowed  = array( 'text/javascript', 'application/javascript', '' );
            if ( ! in_array( $type_val, $allowed, true ) ) {
                return $full_tag;
            }
        }

        // Check exclusions — match against entire tag (src, id, content).
        if ( self::is_excluded( $full_tag, $exclusions ) || self::is_own_script( $full_tag ) ) {
            return $full_tag;
        }

        // (2.8.0) Needed, directly or not, by a script that stays immediate.
        if ( ! empty( $kept ) && class_exists( 'EasyOpt_JS_Graph' ) ) {
            $ref = EasyOpt_JS_Graph::tag_handle( $atts_string );
            if ( null !== $ref && isset( $kept[ $ref[0] ] ) ) {
                return $full_tag;
            }
        }

        // Determine if external or inline.
        $has_src = preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $atts_string );

        if ( $has_src ) {
            return self::rewrite_external( $full_tag, $atts_string, $content );
        } else {
            return self::rewrite_inline( $full_tag, $atts_string, $content );
        }
    }

    /**
     * Rewrite an external <script src="..."> tag.
     */
    private static function rewrite_external( $full_tag, $atts_string, $content ) {

        $new_atts = $atts_string;

        // src → data-easyopt-src
        $new_atts = preg_replace( '/\bsrc\s*=/i', 'data-easyopt-src=', $new_atts, 1 );

        // type → data-easyopt-type (if exists)
        if ( preg_match( '/\btype\s*=\s*["\'][^"\']*["\']/i', $new_atts ) ) {
            $new_atts = preg_replace( '/\btype\s*=/i', 'data-easyopt-type=', $new_atts, 1 );
        }

        // Add type="easyoptscript"
        $new_tag = '<script type="easyoptscript" ' . trim( $new_atts ) . '>' . $content . '</script>';

        return $new_tag;
    }

    /**
     * Rewrite an inline <script> tag (no src).
     */
    private static function rewrite_inline( $full_tag, $atts_string, $content ) {

        // Skip empty scripts.
        if ( '' === trim( $content ) ) {
            return $full_tag;
        }

        $new_atts = $atts_string;

        // type → data-easyopt-type (if exists)
        if ( preg_match( '/\btype\s*=\s*["\'][^"\']*["\']/i', $new_atts ) ) {
            $new_atts = preg_replace( '/\btype\s*=/i', 'data-easyopt-type=', $new_atts, 1 );
        }

        $new_tag = '<script type="easyoptscript" ' . trim( $new_atts ) . '>' . $content . '</script>';

        return $new_tag;
    }

    /**
     * Get exclusion patterns from settings + built-in.
     */
    private static function get_exclusions() {

        // Built-in: never delay these.
        $defaults = array(
            'easyopt-delay',
            'easyopt-delayed-styles',
            'easyopt-navigate',
            'easyopt-lazysizes',
            'easyopt-lazy-shim',
            'easyopt-beacon',
            'easyopt-lcp-beacon',
            // (2.8.0) URL Inspector error capture — must run before everything.
            'easyopt-page-test',
            // (2.7.5) Independent Analytics' inline page-view tracker. Delayed,
            // it waited for an interaction on Mac/Linux desktops, so visitors
            // who left without scrolling or moving the mouse were never counted.
            'independent-analytics-script',
        );

        // jQuery exclusions — on by default (preserves existing behavior).
        if ( (int) EasyOpt_Config::get( 'delay_js_exclude_jquery', 1 ) ) {
            $defaults[] = 'jquery.min.js';
            $defaults[] = 'jquery.js';
            $defaults[] = 'wp-includes/js/dist/';
        }

        $user = EasyOpt_Config::get( 'delay_js_exclude', '' );
        if ( ! empty( $user ) ) {
            $lines    = array_filter( array_map( 'trim', explode( "\n", $user ) ) );
            $defaults = array_merge( $defaults, $lines );
        }

        // (2.8.0) One-click exclusions — only the entries someone ticked.
        $oneclick = (string) EasyOpt_Config::get( 'delay_js_oneclick', '' );
        if ( '' !== trim( $oneclick ) && class_exists( 'EasyOpt_Delay_Oneclick' ) ) {
            $defaults = array_merge( $defaults, EasyOpt_Delay_Oneclick::patterns( $oneclick ) );
        }

        return apply_filters( 'easyopt_delay_js_exclusions', $defaults );
    }

    /**
     * Check if current URL should be excluded.
     *
     * (2.6.5) PUBLIC, because EasyOpt_Defer_JS needs the same answer. Its
     * docblock has always claimed it "shares all settings (excludes, exclude
     * jQuery, exclude URLs) with the Delay JS module" — but it never read
     * delay_js_exclude_urls at all. Switching the method from Delay to Defer
     * silently threw every URL exclusion away, so a user excluding a page that
     * Defer broke saw no change and had to turn the whole feature off.
     *
     * @since 2.0.0
     * @return bool
     */
    public static function is_url_excluded() {

        $excludes = EasyOpt_Config::get( 'delay_js_exclude_urls', '' );
        if ( '' === trim( (string) $excludes ) ) {
            return false;
        }

        $current = home_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );
        $lines   = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $excludes ) ) );

        foreach ( $lines as $pattern ) {
            if ( self::url_pattern_matches( $current, $pattern ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does one exclusion pattern match this URL?
     *
     * Plain patterns keep their original substring behaviour, so existing
     * settings are unaffected. A pattern containing `*` is treated as a
     * wildcard — which is what the field's own placeholder invites people to
     * write, and what they reasonably expect. Before 2.6.5 the `*` was matched
     * LITERALLY, so `/course/*` could only ever match a URL that really
     * contained an asterisk: the most natural thing to type was the one thing
     * guaranteed not to work, and it failed silently.
     *
     * @since 2.6.5
     * @param string $url     Absolute URL of the current request.
     * @param string $pattern One line from the exclusion field.
     * @return bool
     */
    public static function url_pattern_matches( $url, $pattern ) {

        // (2.6.5) Delegate to the plugin-wide canonical matcher so every
        // Exclude URLs field — cache, JS, LCP, fonts, unused CSS — behaves
        // identically. The inline copy below is the fallback for the rare
        // front-end path where cache-common.php has not loaded.
        if ( function_exists( '\\EasyOpt\\Cache\\url_matches_pattern' ) ) {
            return \EasyOpt\Cache\url_matches_pattern( $url, $pattern );
        }

        $pattern = trim( (string) $pattern );
        if ( '' === $pattern ) {
            return false;
        }
        if ( false === strpos( $pattern, '*' ) ) {
            return false !== stripos( (string) $url, $pattern );
        }
        $regex = '#' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '#i';
        return 1 === preg_match( $regex, (string) $url );
    }
}
