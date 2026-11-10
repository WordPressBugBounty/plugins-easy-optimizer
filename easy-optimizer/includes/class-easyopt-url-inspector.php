<?php
/**
 * URL Inspector + "Test this page" (2.8.0).
 *
 * One screen answering "what did Easy Optimizer do to this page?": cache
 * state per device, the last preload result, the Used CSS context and its
 * file, the measured LCP element, which features skip the URL and why, and
 * the JavaScript errors from the last "Test this page" run.
 *
 * Everything here READS state the plugin already keeps; nothing is computed
 * on a visitor's request. The one front-end piece is the error-capture
 * script, printed only for an administrator whose request carries a valid
 * `eoptest` nonce (the test frame) — never for a visitor, never cached
 * (any unknown query string is a cache MISS and is not stored).
 *
 * @package EasyOptimizer
 * @since   2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Url_Inspector {

    const TESTS_OPTION = 'easyopt_page_tests';
    const NONCE_ACTION = 'easyopt_page_test';
    const MAX_TESTS    = 20;
    const MAX_ERRORS   = 50;

    /* ───────────────────────────────────────────────
     *  Inspect
     * ─────────────────────────────────────────────── */

    /**
     * @param string $url Absolute URL on this site.
     * @return array
     */
    public static function inspect( $url ) {
        $url = (string) $url;
        return array(
            'url'      => $url,
            'cache'    => self::cache_state( $url ),
            'preload'  => self::preload_state( $url ),
            'used_css' => self::used_css_state( $url ),
            'lcp'      => self::lcp_state( $url ),
            'js'       => self::js_state( $url ),
            'skips'    => self::skips( $url ),
            'test'     => self::last_test( $url ),
        );
    }

    private static function cache_state( $url ) {
        $out = array(
            'enabled'         => (int) EasyOpt_Config::get( 'cache', 0 ),
            'separate_mobile' => (int) EasyOpt_Config::get( 'cache_separate_mobile', 1 ),
            'desktop'         => null,
            'mobile'          => null,
        );
        if ( ! class_exists( 'EasyOpt_Cache' ) || ! method_exists( 'EasyOpt_Cache', 'cache_paths_for_url' ) ) {
            return $out;
        }
        $paths = EasyOpt_Cache::cache_paths_for_url( $url );
        foreach ( array( 'desktop', 'mobile' ) as $dev ) {
            $file = '';
            foreach ( array( $dev, $dev . '_plain' ) as $k ) {
                if ( ! empty( $paths[ $k ] ) && file_exists( (string) $paths[ $k ] ) ) {
                    $file = (string) $paths[ $k ];
                    break;
                }
            }
            $out[ $dev ] = '' === $file ? array( 'cached' => false ) : array(
                'cached' => true,
                'age'    => max( 0, time() - (int) @filemtime( $file ) ),
            );
        }
        return $out;
    }

    private static function preload_state( $url ) {
        $row = class_exists( 'EasyOpt_Preload_Results' ) && method_exists( 'EasyOpt_Preload_Results', 'row_for_url' )
            ? EasyOpt_Preload_Results::row_for_url( $url )
            : null;
        return array(
            'enabled' => (int) EasyOpt_Config::get( 'cache_preload', 0 ),
            'status'  => $row ? (string) $row['status'] : '',
            'code'    => $row ? (int) $row['http_code'] : 0,
            'note'    => $row ? (string) $row['note'] : '',
            'updated' => $row ? (string) $row['updated_at'] : '',
        );
    }

    /** The Used CSS context a URL renders under, or '' when only a render can tell (archives). */
    public static function used_css_context( $url ) {
        if ( ! class_exists( 'EasyOpt_Unused_CSS' ) ) {
            return '';
        }
        $is_home = untrailingslashit( strtok( $url, '?#' ) ) === untrailingslashit( home_url( '/' ) );
        if ( $is_home ) {
            $front = (int) get_option( 'page_on_front' );
            if ( 'page' === get_option( 'show_on_front' ) && $front > 0 ) {
                return EasyOpt_Unused_CSS::get_url_type( $front );
            }
            return 'home';
        }
        $id = function_exists( 'url_to_postid' ) ? (int) url_to_postid( $url ) : 0;
        return $id > 0 ? EasyOpt_Unused_CSS::get_url_type( $id ) : '';
    }

    private static function used_css_state( $url ) {
        $out = array(
            'enabled' => (int) EasyOpt_Config::get( 'unused_css', 0 ),
            'context' => '',
            'files'   => array(),
        );
        if ( ! $out['enabled'] ) {
            return $out;
        }
        $ctx            = self::used_css_context( $url );
        $out['context'] = $ctx;
        if ( '' === $ctx ) {
            return $out;
        }
        $dir   = trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt/css/';
        $times = get_option( 'easyopt_used_css_time', array() );
        $ctxs  = array( 'desktop' => $ctx );
        if ( (int) EasyOpt_Config::get( 'cache_separate_mobile', 1 ) ) {
            $ctxs['mobile'] = $ctx . '-mobile';
        }
        foreach ( $ctxs as $dev => $c ) {
            $file   = $dir . $c . '.used.css';
            $sheets = @file_get_contents( $dir . $c . '.sheets.json' );
            $sheets = is_string( $sheets ) ? json_decode( $sheets, true ) : null;
            $out['files'][ $dev ] = array(
                'context' => $c,
                'exists'  => is_file( $file ),
                'bytes'   => is_file( $file ) ? (int) @filesize( $file ) : 0,
                'built'   => ( is_array( $times ) && isset( $times[ $c ] ) ) ? (int) $times[ $c ] : 0,
                'sheets'  => is_array( $sheets ) ? count( $sheets ) : 0,
                'failed'  => false !== get_transient( 'easyopt_rucss_fail_' . md5( $c ) ),
            );
        }
        return $out;
    }

    private static function lcp_state( $url ) {
        $out = array( 'enabled' => (int) EasyOpt_Config::get( 'lcp_preload', 0 ), 'rows' => array() );
        if ( ! $out['enabled'] || ! class_exists( 'EasyOpt_LCP' ) || ! method_exists( 'EasyOpt_LCP', 'rows_for_url' ) ) {
            return $out;
        }
        foreach ( (array) EasyOpt_LCP::rows_for_url( $url ) as $r ) {
            $out['rows'][] = array(
                'viewport' => isset( $r['viewport'] ) ? (string) $r['viewport'] : '',
                'tag'      => isset( $r['element_tag'] ) ? (string) $r['element_tag'] : '',
                'image'    => isset( $r['image_url'] ) ? (string) $r['image_url'] : '',
            );
        }
        return $out;
    }

    private static function js_state( $url ) {
        $picked = array_filter( array_map( 'trim', explode( "\n", (string) EasyOpt_Config::get( 'delay_js_oneclick', '' ) ) ) );
        $labels = array();
        if ( $picked && class_exists( 'EasyOpt_Delay_Oneclick' ) ) {
            $items = EasyOpt_Delay_Oneclick::items();
            foreach ( $picked as $id ) {
                if ( isset( $items[ $id ] ) ) {
                    $labels[] = $items[ $id ]['label'];
                }
            }
        }
        return array(
            'minify'   => (int) EasyOpt_Config::get( 'minify_js', 0 ),
            'delay'    => method_exists( 'EasyOpt_Config', 'js_delay_on' ) ? (int) EasyOpt_Config::js_delay_on() : (int) EasyOpt_Config::get( 'delay_js', 0 ),
            'defer'    => method_exists( 'EasyOpt_Config', 'js_defer_on' ) ? (int) EasyOpt_Config::js_defer_on() : (int) EasyOpt_Config::get( 'defer_js', 0 ),
            'delay_external' => (int) EasyOpt_Config::get( 'delay_js_external_only', 0 ),
            'oneclick' => $labels,
        );
    }

    /**
     * Which features skip this URL, and the rule that made them. Only the
     * URL-level rules — script- and stylesheet-level exclusions apply inside
     * a page, not to it.
     *
     * @return array<int,array{feature:string,rule:string}>
     */
    private static function skips( $url ) {
        $out = array();
        $map = array(
            array( 'Page cache', 'cache_exclude_urls' ),
            array( 'Delay & Defer JavaScript', 'delay_js_exclude_urls' ),
            array( 'Remove Unused CSS', 'unused_css_exclude_urls' ),
            array( 'Preload LCP', 'lcp_exclude_urls' ),
            array( 'Font optimization', 'fonts_exclude_urls' ),
        );
        foreach ( $map as $def ) {
            $rule = self::matching_rule( $url, (string) EasyOpt_Config::get( $def[1], '' ) );
            if ( '' !== $rule ) {
                $out[] = array( 'feature' => $def[0], 'rule' => $rule );
            }
        }
        // Built-in cache rules (cart, checkout, wp-admin, …) that the user list does not show.
        if ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'get_url_exclusions' ) && function_exists( 'EasyOpt\\Cache\\is_excluded_uri' ) ) {
            $path = wp_parse_url( $url, PHP_URL_PATH );
            $qs   = wp_parse_url( $url, PHP_URL_QUERY );
            $uri  = ( is_string( $path ) && '' !== $path ? $path : '/' ) . ( is_string( $qs ) && '' !== $qs ? '?' . $qs : '' );
            $user = self::matching_rule( $url, (string) EasyOpt_Config::get( 'cache_exclude_urls', '' ) );
            if ( '' === $user && \EasyOpt\Cache\is_excluded_uri( $uri, (array) EasyOpt_Cache::get_url_exclusions() ) ) {
                $out[] = array( 'feature' => 'Page cache', 'rule' => 'built-in rule' );
            }
        }
        return $out;
    }

    private static function matching_rule( $url, $list ) {
        foreach ( array_filter( array_map( 'trim', explode( "\n", $list ) ) ) as $pattern ) {
            $hit = function_exists( 'EasyOpt\\Cache\\url_matches_pattern' )
                ? \EasyOpt\Cache\url_matches_pattern( $url, $pattern )
                : false !== stripos( $url, $pattern );
            if ( $hit ) {
                return $pattern;
            }
        }
        return '';
    }

    /* ───────────────────────────────────────────────
     *  Test this page
     * ─────────────────────────────────────────────── */

    /**
     * `wp_head` (earliest): print the error-capture script into the test
     * frame. Administrator + valid nonce only.
     */
    public static function maybe_print_test_script() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below
        $nonce = isset( $_GET['eoptest'] ) ? sanitize_text_field( wp_unslash( $_GET['eoptest'] ) ) : '';
        if ( '' === $nonce || ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            return;
        }
        $p      = wp_parse_url( admin_url() );
        $origin = ( isset( $p['scheme'] ) ? $p['scheme'] : 'https' ) . '://' . ( isset( $p['host'] ) ? $p['host'] : '' ) . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded origin inside our own script
        echo '<script id="easyopt-page-test">' . self::test_script( $origin ) . '</script>' . "\n";
    }

    /**
     * The capture script. Records uncaught errors, unhandled rejections,
     * failed script/stylesheet loads and console.error; after load it fires
     * one synthetic interaction (so delayed scripts and styles run, exactly
     * as on a visitor's first scroll), waits, and posts the list to the
     * admin page that opened the frame.
     */
    public static function test_script( $origin ) {
        return '(function(){var E=[],O=' . wp_json_encode( (string) $origin ) . ',sent=0;'
            . 'function add(o){if(E.length<' . self::MAX_ERRORS . ')E.push(o);}'
            . 'window.addEventListener("error",function(e){var t=e.target;'
            . 'if(t&&t!==window&&(t.src||t.href)){if(t.tagName==="SCRIPT"||t.tagName==="LINK")add({type:"resource",src:String(t.src||t.href)});return;}'
            . 'add({type:"error",message:String(e.message||""),src:String(e.filename||""),line:e.lineno||0});},true);'
            . 'window.addEventListener("unhandledrejection",function(e){var r=e.reason;add({type:"rejection",message:String(r&&r.message||r||""),src:""});});'
            . 'var ce=console.error;console.error=function(){try{add({type:"console",message:Array.prototype.map.call(arguments,function(a){return String(a&&a.message||a);}).join(" ").slice(0,500),src:""});}catch(x){}return ce.apply(console,arguments);};'
            . 'function send(){if(sent)return;sent=1;try{(window.opener||window.parent).postMessage({easyoptPageTest:1,errors:E,'
            . 'delayed:document.querySelectorAll(\'script[type="easyoptscript"]\').length},O);}catch(x){}}'
            . 'window.addEventListener("load",function(){setTimeout(function(){try{var d=document.documentElement;'
            . '["mousemove","keydown","touchstart"].forEach(function(n){d.dispatchEvent(new Event(n,{bubbles:true}));});'
            . 'window.dispatchEvent(new Event("scroll"));}catch(x){}setTimeout(send,4000);},1000);});'
            . 'setTimeout(send,25000);})();';
    }

    /**
     * Store a test result and return it with suggested exclusions.
     *
     * @param string $url
     * @param array  $errors  As posted by the capture script.
     * @param int    $delayed Delayed scripts still waiting when it reported.
     * @return array
     */
    public static function record_test( $url, array $errors, $delayed ) {
        $clean = array();
        foreach ( array_slice( $errors, 0, self::MAX_ERRORS ) as $e ) {
            if ( ! is_array( $e ) ) {
                continue;
            }
            $clean[] = array(
                'type'    => sanitize_key( isset( $e['type'] ) ? $e['type'] : 'error' ),
                'message' => substr( sanitize_text_field( isset( $e['message'] ) ? (string) $e['message'] : '' ), 0, 500 ),
                'src'     => esc_url_raw( isset( $e['src'] ) ? (string) $e['src'] : '' ),
                'line'    => isset( $e['line'] ) ? (int) $e['line'] : 0,
                'viewports' => array_values( array_intersect( array( 'desktop', 'mobile' ), isset( $e['viewports'] ) ? (array) $e['viewports'] : array() ) ),
            );
        }
        $result = array(
            'time'        => time(),
            'errors'      => $clean,
            'delayed'     => (int) $delayed,
            'suggestions' => self::suggest( $clean ),
        );
        $all = get_option( self::TESTS_OPTION, array() );
        $all = is_array( $all ) ? $all : array();
        unset( $all[ $url ] );
        $all[ $url ] = $result;
        if ( count( $all ) > self::MAX_TESTS ) {
            $all = array_slice( $all, -self::MAX_TESTS, null, true );
        }
        update_option( self::TESTS_OPTION, $all, false );
        return $result;
    }

    private static function last_test( $url ) {
        $all = get_option( self::TESTS_OPTION, array() );
        return ( is_array( $all ) && isset( $all[ $url ] ) ) ? $all[ $url ] : null;
    }

    /**
     * Turn errors into exclusions worth trying, most specific first:
     *   - a one-click entry whose scripts threw (offered on this site, not yet ticked);
     *   - "Exclude jQuery" when the error is jQuery / $ not being defined;
     *   - the failing script's own file, for "Exclude from Delay" / "Exclude from Defer".
     * Only for engines that are on. Never applied by itself. A file that did
     * not download gets nothing: excluding it cannot make it load, and delayed
     * it cannot trouble the page's first load.
     *
     * @param array $errors Cleaned errors.
     * @return array<int,array{kind:string,setting:string,value:string,label:string}>
     */
    public static function suggest( array $errors ) {
        $delay = method_exists( 'EasyOpt_Config', 'js_delay_on' ) ? EasyOpt_Config::js_delay_on() : (bool) EasyOpt_Config::get( 'delay_js', 0 );
        $defer = method_exists( 'EasyOpt_Config', 'js_defer_on' ) ? EasyOpt_Config::js_defer_on() : (bool) EasyOpt_Config::get( 'defer_js', 0 );
        if ( ! $delay && ! $defer ) {
            return array();
        }
        $out    = array();
        $picked = array_filter( array_map( 'trim', explode( "\n", (string) EasyOpt_Config::get( 'delay_js_oneclick', '' ) ) ) );
        $items  = class_exists( 'EasyOpt_Delay_Oneclick' ) ? EasyOpt_Delay_Oneclick::items() : array();
        $offer  = class_exists( 'EasyOpt_Delay_Oneclick' ) ? wp_list_pluck( EasyOpt_Delay_Oneclick::offered(), 'id' ) : array();

        foreach ( $errors as $e ) {
            if ( isset( $e['type'] ) && 'resource' === $e['type'] ) {
                continue;
            }
            $src = (string) $e['src'];
            $msg = (string) $e['message'];

            if ( preg_match( '/\b(jQuery|\$) is not defined|\$ is not a function|jQuery is not a function/i', $msg )
                 && ! (int) EasyOpt_Config::get( 'delay_js_exclude_jquery', 1 ) ) {
                $out['jquery'] = array( 'kind' => 'check', 'setting' => 'easyopt_delay_js_exclude_jquery', 'value' => '1', 'label' => 'Turn on "Exclude jQuery"' );
            }
            if ( '' === $src ) {
                continue;
            }
            if ( $delay ) {
                foreach ( $items as $id => $it ) {
                    if ( in_array( $id, $picked, true ) || ! in_array( $id, $offer, true ) ) {
                        continue;
                    }
                    foreach ( isset( $it['paths'] ) ? $it['paths'] : array() as $p ) {
                        if ( false !== stripos( $src, $p ) ) {
                            $out[ 'oneclick:' . $id ] = array( 'kind' => 'oneclick', 'setting' => 'easyopt_delay_js_oneclick', 'value' => $id, 'label' => 'Tick "' . $it['label'] . '" under One-click exclusions' );
                            continue 3;
                        }
                    }
                }
            }
            $kw = self::script_keyword( $src );
            if ( '' === $kw ) {
                continue;
            }
            if ( $delay ) {
                $out[ 'delay:' . $kw ] = array( 'kind' => 'line', 'setting' => 'easyopt_delay_js_exclude', 'value' => $kw, 'label' => 'Add "' . $kw . '" to Exclude from Delay' );
            } elseif ( $defer ) {
                $out[ 'defer:' . $kw ] = array( 'kind' => 'line', 'setting' => 'easyopt_defer_js_exclude', 'value' => $kw, 'label' => 'Add "' . $kw . '" to Exclude from Defer' );
            }
        }
        return array_values( $out );
    }

    /**
     * A keyword for a script URL that still matches after Minify renames it:
     * the file name, without our 12-character hash prefix and without the
     * query string. '' for anything that is not a .js file.
     */
    public static function script_keyword( $src ) {
        $path = (string) wp_parse_url( (string) $src, PHP_URL_PATH );
        $base = basename( $path );
        if ( '' === $base || ! preg_match( '/\.js$/i', $base ) ) {
            return '';
        }
        if ( false !== strpos( $path, '/cache/easyopt/' ) ) {
            return preg_replace( '/^[0-9a-f]{12}\./', '', $base );
        }
        if ( preg_match( '#/(?:plugins|themes)/.+$#', $path, $m ) ) {
            return $m[0];
        }
        return $base;
    }
}
