<?php
/**
 * EasyOpt — static asset delivery through FluxPress.
 *
 * Rewrites stylesheet, script and font URLs to the CDN:
 *
 *     https://site.com/wp-content/themes/x/style.css
 *   → https://cdn.fluxpress.io/{account}/s/https://site.com/wp-content/themes/x/style.css
 *
 * (2.8.0) Or, when FluxPress delivery is off, to the site's own CDN (a
 * CNAME / pull zone): the host is swapped, the path kept, and images and
 * media under /wp-content/ and /wp-includes/ go too —
 *
 *     https://site.com/wp-content/uploads/a.jpg
 *   → https://cdn.example.com/wp-content/uploads/a.jpg
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHY THIS IS NOT PART OF EasyOpt_CDN
 *
 * That class is 1,300 lines of imgproxy signing, srcset width parsing and
 * per-variant URL maps, all of which exist because an image is TRANSFORMED
 * on the way through. A stylesheet is not transformed; it is moved. It needs
 * no signature, no width, no format negotiation and no variant map. Bolting
 * this onto the image path would mean carrying all of that machinery for a
 * job that is a string rewrite, and would put the fragile part of the
 * codebase in the path of every CSS file on the site.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * ORDERING
 *
 * This pass runs LAST in the buffer pipeline, after Minify and Unused CSS.
 * Both of those GENERATE files — a minified bundle, a slim used.css — and a
 * URL that does not exist yet cannot be rewritten. Running earlier would
 * rewrite the original stylesheet and then have Unused CSS replace the tag
 * wholesale, silently doing nothing.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_CDN_Assets {

    /**
     * Extensions we will hand to the CDN.
     *
     * Must stay a subset of the edge guard's own allowlist. The guard is the
     * security control — it refuses anything else with a 403 — so a mismatch
     * here does not create a hole, it creates broken assets. Keep them in step.
     */
    const EXT = array( 'css', 'js', 'mjs', 'woff', 'woff2', 'ttf', 'otf', 'svg', 'ico' );

    /** (2.8.0) What a pull zone serves: the assets above plus images and media. */
    const CUSTOM_EXT = array( 'css', 'js', 'mjs', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'svg', 'ico', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp4', 'webm' );

    /**
     * (2.8.0) Stay on the site while a pull zone sends no CORS header. Browsers
     * refuse a cross-origin font without it, and a stylesheet's relative url()s
     * resolve to wherever the stylesheet came from, so its fonts follow it.
     */
    const NEEDS_CORS_EXT = array( 'css', 'woff', 'woff2', 'ttf', 'otf', 'eot' );

    /** (2.8.0) Fetched only to read the CORS header. A font type, because pull zones often set the header per file type. */
    const CORS_FILE = 'assets/easyopt-cors-check.woff2';

    /**
     * Edge revision, appended to every CDN asset URL as ?fx=.
     *
     * The edge serves static assets `max-age=1y, immutable`, and it TRANSFORMS
     * stylesheets (url()/@import re-pointing) on the way through. When that
     * transform changes, a purge fixes the edge but not the visitor's browser,
     * which never asks again for a year — and the origin URL (often content-
     * hashed) does not change. Set this whenever the guard changes what it
     * serves, so every browser fetches a fresh copy. Empty = no ?fx= at all.
     *
     * '' — off (owner's call, 2.7.2). Was '2' for guard 3.6.4 (3.6.3 pointed
     *      fonts inside served CSS at the imgproxy container, 403ing them);
     *      browsers still holding that copy recover only when it expires.
     *
     * The guard reads only the path, and the origin's own '?' is escaped to
     * %3F, so this query never reaches the origin or alters the asset fetched.
     */
    const REV = '';

    /** (2.8.0) Health of each CDN: array( 'cloud'|'custom' => array{ok,fails,seen,checked,error,cors?} ). 'cors' is the own CDN's only. */
    const HEALTH_OPTION = 'easyopt_cdn_health';
    const HEALTH_HOOK   = 'easyopt_cdn_health';
    const HEALTH_EVERY  = 'easyopt_quarter_hour';

    /** @var string|null mode() for the document being rewritten (null outside process_buffer). */
    private static $active = null;

    /** @var string|null Memoised site host, no www. */
    private static $host = null;

    public static function init() {
        // Nothing to hook. The buffer pipeline calls process_buffer()
        // directly, the same way it calls every other pass.
    }

    /**
     * Is asset delivery live for this request?
     *
     * Four separate gates, and all of them are load-bearing:
     *   - the feature is switched on
     *   - we hold an account and the service says the plan is active (a
     *     lapsed trial sets this false, so assets fall back with the images;
     *     the Images toggle itself does NOT gate assets — 2.7.2)
     *   - this is a front-end page view
     *   - the user is logged out
     *
     * The last one is not caution. Logged-in pages carry admin-bar CSS and
     * nonce-bearing scripts, and an edge-cached copy of either is a
     * correctness problem, not a performance one.
     */
    public static function enabled() {
        if ( ! self::configured( 'cloud' ) ) {
            return false;
        }
        if ( is_admin() || is_user_logged_in() ) {
            return false;
        }
        // (2.8.0) A CDN the health check cannot reach is not used.
        return self::healthy( 'cloud' );
    }

    /**
     * (2.8.0) The site's own CDN: switched on, a base URL saved, a logged-out
     * front-end view, reachable, and FluxPress delivery not live (that one wins).
     */
    public static function custom_enabled() {
        if ( ! self::configured( 'custom' ) ) {
            return false;
        }
        if ( is_admin() || is_user_logged_in() ) {
            return false;
        }
        if ( ! self::healthy( 'custom' ) ) {
            return false;
        }
        return ! self::enabled();
    }

    /** (2.8.0) Set up — whatever the request, and whether or not it is reachable. */
    public static function configured( $mode ) {
        if ( 'custom' === $mode ) {
            return (int) EasyOpt_Config::get( 'easyopt_cdn_custom', 0 ) && '' !== self::custom_base();
        }
        if ( ! (int) EasyOpt_Config::get( 'easyopt_cloud_assets', 0 ) ) {
            return false;
        }
        if ( ! class_exists( 'EasyOpt_CDN_Cloud' ) ) {
            return false;
        }
        if ( ! EasyOpt_CDN_Cloud::is_entitled() ) { // not is_delivering(): that also requires Images on
            return false;
        }
        return '' !== (string) EasyOpt_CDN_Cloud::account() && '' !== (string) EasyOpt_CDN_Cloud::endpoint();
    }

    /** 'cloud' (FluxPress), 'custom' (own CDN) or '' for this request. */
    public static function mode() {
        if ( null !== self::$active ) {
            return self::$active;
        }
        if ( self::enabled() ) {
            return 'cloud';
        }
        return self::custom_enabled() ? 'custom' : '';
    }

    private static function custom_base() {
        return untrailingslashit( trim( (string) EasyOpt_Config::get( 'easyopt_cdn_custom_url', '' ) ) );
    }

    /**
     * Rewrite a whole document.
     *
     * @param string $html
     * @return string
     */
    public static function process_buffer( $html ) {
        $mode = '' === $html ? '' : self::mode();
        if ( '' === $mode ) {
            return $html;
        }
        self::$active = $mode;
        $html         = self::add_fallback( $html, $mode );

        // <link> in three shapes: a plain stylesheet, a preload (as=font /
        // as=style / as=script, including the async-CSS "preload then swap to
        // stylesheet" pattern), and a modulepreload. A preload of an image or a
        // fetch target falls through cdn_url()'s extension gate untouched, so
        // broadening the rel here does not pull non-assets onto the CDN. Fonts
        // referenced from inside a stylesheet (@font-face src) are rewritten by
        // the edge's own url() pass, not here — this file moves the assets the
        // HTML names directly.
        //
        // THIS PASS RUNS LAST — after Unused CSS renamed a deferred stylesheet's
        // href to data-easyopt-delayed, and after Delay JS renamed a deferred
        // script's src to data-easyopt-src. rewrite_link/rewrite_script match
        // those renamed attributes too, or every delayed asset would be restored
        // straight from origin at interaction time and silently bypass the edge.
        $html = preg_replace_callback(
            '#<link\b[^>]*\srel=(["\'])[^"\']*\b(?:stylesheet|preload|modulepreload)\b[^"\']*\1[^>]*>#i',
            array( __CLASS__, 'rewrite_link' ),
            $html
        );
        $html = preg_replace_callback(
            '#<script\b[^>]*\s(?:src|data-easyopt-src)=(["\'])([^"\']+)\1[^>]*>#i',
            array( __CLASS__, 'rewrite_script' ),
            $html
        );

        // (2.8.0) A pull zone also takes images, media and the url()s in the
        // page's own CSS. FluxPress has its own signed image path for those.
        if ( 'custom' === $mode ) {
            $html = preg_replace_callback(
                '#<(?!script\b|link\b)[a-z][a-z0-9-]*\s[^>]*?\b(?:src|srcset|data-src|data-srcset|data-bg|poster|style)\s*=[^>]*>#i',
                array( __CLASS__, 'rewrite_media_tag' ),
                $html
            );
            $html = preg_replace_callback(
                '#(<style\b[^>]*>)(.*?)(</style>)#is',
                function ( $m ) {
                    return $m[1] . self::rewrite_css_urls( $m[2] ) . $m[3];
                },
                $html
            );
        }

        self::$active = null;
        return $html;
    }

    /**
     * (2.8.0) If a stylesheet or script fails to load from the CDN, load it from
     * the site instead. One capture-phase listener at the top of <head> — before
     * any stylesheet, since a load error does not bubble — so no per-tag
     * markup, and late-added tags are covered too. The site URL is recovered
     * from the CDN URL itself.
     *
     * A script cannot be retried by changing its src, so a copy takes over. The
     * failed tag's own load or error event fires only when the copy finishes, so
     * the Delay JS queue waits and later scripts still run in order. A script the
     * parser is blocked on cannot be held like that: the parser moves on, and an
     * inline script right after it runs before the copy arrives.
     */
    private static function add_fallback( $html, $mode ) {
        if ( ! preg_match( '/<head\b[^>]*>/i', $html, $h, PREG_OFFSET_CAPTURE ) ) {
            return $html;
        }
        if ( 'custom' === $mode ) {
            $base = self::custom_base();
            $map  = 'O+h.slice(B.length)';
        } else {
            $base = untrailingslashit( (string) EasyOpt_CDN_Cloud::endpoint() ) . '/' . EasyOpt_CDN_Cloud::account() . '/s/';
            $map  = 'h.slice(B.length).replace(/\?fx=[^#]*$/,"").replace(/%3F/gi,"?").replace(/%25/g,"%")';
        }
        $js = '(function(){var B=' . wp_json_encode( $base ) . ',O=' . wp_json_encode( self::site_origin() ) . ';'
            . 'document.addEventListener("error",function(e){var t=e.target,n=t&&t.tagName,h,s,i,a;'
            . 'if(n!=="LINK"&&n!=="SCRIPT"||t.__eo)return;'
            . 'h=(n==="LINK"?t.href:t.src)||"";if(h.indexOf(B)!==0)return;t.__eo=1;h=' . $map . ';'
            . 'if(n==="LINK"){t.href=h;return;}'
            . 'e.stopImmediatePropagation();s=document.createElement("script");'
            . 'for(i=0;i<t.attributes.length;i++){a=t.attributes[i];if(a.name!=="src")s.setAttribute(a.name,a.value);}'
            . 't.removeAttribute("id");s.async=t.async;s.src=h;'
            . 's.onload=function(){t.dispatchEvent(new Event("load"));};'
            . 's.onerror=function(){t.dispatchEvent(new Event("error"));};'
            . 't.parentNode.insertBefore(s,t.nextSibling);},true);})();';
        $at = $h[0][1] + strlen( $h[0][0] );
        return substr( $html, 0, $at ) . '<script id="easyopt-cdn-fallback">' . $js . '</script>' . substr( $html, $at );
    }

    /* ───────────────────────────────────────────────
     *  (2.8.0) Health check
     * ─────────────────────────────────────────────── */

    /** False only after the check has found this CDN unreachable. */
    public static function healthy( $mode ) {
        $all = get_option( self::HEALTH_OPTION, array() );
        return ! ( is_array( $all ) && isset( $all[ $mode ]['ok'] ) && ! $all[ $mode ]['ok'] );
    }

    /** @return array The stored health, for the settings screen. */
    public static function health() {
        $all = get_option( self::HEALTH_OPTION, array() );
        return is_array( $all ) ? $all : array();
    }

    /** True only after the check has found the own CDN sends no CORS header for fonts. */
    private static function custom_lacks_cors() {
        $all = get_option( self::HEALTH_OPTION, array() );
        return is_array( $all ) && isset( $all['custom']['cors'] ) && false === $all['custom']['cors'];
    }

    /**
     * Fetch one small file through each CDN that is set up. Two misses in a
     * row stop a CDN being used — one, for a CDN that has never answered, so
     * a mistyped address never reaches visitors; one answer brings it back.
     * Either change empties the page cache, which holds the old URLs.
     *
     * @param string $only 'cloud', 'custom' or '' for both.
     * @return array The stored health.
     */
    public static function check_health( $only = '' ) {
        $all     = self::health();
        $flipped = false;
        foreach ( array( 'cloud', 'custom' ) as $m ) {
            if ( '' !== $only && $m !== $only ) {
                continue;
            }
            if ( ! self::configured( $m ) ) {
                unset( $all[ $m ] );
                continue;
            }
            $prev   = isset( $all[ $m ] ) && is_array( $all[ $m ] ) ? $all[ $m ] : array();
            $was_ok = ! isset( $prev['ok'] ) || $prev['ok'];
            $err    = self::probe( $m );
            if ( '' === $err ) {
                $all[ $m ] = array( 'ok' => true, 'fails' => 0, 'seen' => true, 'checked' => time(), 'error' => '' );
            } else {
                $fails     = ( isset( $prev['fails'] ) ? (int) $prev['fails'] : 0 ) + 1;
                $seen      = ! empty( $prev['seen'] );
                $all[ $m ] = array( 'ok' => $was_ok && $seen && $fails < 2, 'fails' => $fails, 'seen' => $seen, 'checked' => time(), 'error' => $err );
            }
            if ( $was_ok !== $all[ $m ]['ok'] ) {
                $flipped = true;
            }
            // (2.8.0) Only a clean answer changes the verdict; a miss keeps the last one.
            if ( 'custom' === $m ) {
                $cors = '' === $err ? self::probe_cors() : null;
                if ( null === $cors && isset( $prev['cors'] ) ) {
                    $cors = $prev['cors'];
                }
                if ( null !== $cors ) {
                    $all[ $m ]['cors'] = $cors;
                }
                if ( ( false === $cors ) !== ( isset( $prev['cors'] ) && false === $prev['cors'] ) ) {
                    $flipped = true;
                }
            }
        }
        update_option( self::HEALTH_OPTION, $all, true );
        if ( $flipped && class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'clear_all' ) ) {
            EasyOpt_Cache::clear_all();
        }
        return $all;
    }

    /** '' when the CDN served our own small script, else why not. */
    private static function probe( $mode ) {
        $file = 'assets/easyopt-lazy-shim.min.js';
        if ( ! defined( 'EASYOPT_URL' ) || ! defined( 'EASYOPT_DIR' ) || ! is_readable( EASYOPT_DIR . $file ) ) {
            return ''; // nothing to test with — never switch a CDN off on a guess
        }
        $prev         = self::$active;
        self::$active = $mode;
        $url          = self::cdn_url( EASYOPT_URL . $file );
        self::$active = $prev;
        if ( '' === $url ) {
            return ''; // this file is excluded from the CDN — nothing to judge by
        }
        $res = wp_remote_get( $url, array(
            'timeout'     => 10,
            'redirection' => 2,
            'user-agent'  => 'EasyOptimizer-CDN-Check; ' . home_url( '/' ),
        ) );
        if ( is_wp_error( $res ) ) {
            return $res->get_error_message();
        }
        $code = (int) wp_remote_retrieve_response_code( $res );
        if ( 200 !== $code ) {
            return sprintf( 'HTTP %d', $code );
        }
        $body = ltrim( (string) wp_remote_retrieve_body( $res ) );
        if ( '' === $body || '<' === $body[0] ) {
            return 'The CDN did not return the file.';
        }
        return '';
    }

    /**
     * (2.8.0) May this site's pages load fonts from the own CDN? Asks the way a
     * browser does, with an Origin header, and judges the answer the way one
     * does: `*` or this exact origin, nothing else. Null when the CDN gave no
     * clean answer. Built by hand, since cdn_url() stops offering fonts once
     * this has said no and the check has to keep asking.
     *
     * @return bool|null
     */
    private static function probe_cors() {
        if ( ! defined( 'EASYOPT_URL' ) || ! defined( 'EASYOPT_DIR' ) || ! is_readable( EASYOPT_DIR . self::CORS_FILE ) ) {
            return null;
        }
        $origin = self::site_origin();
        $res    = wp_remote_get( self::custom_base() . wp_parse_url( EASYOPT_URL . self::CORS_FILE, PHP_URL_PATH ), array(
            'timeout'     => 10,
            'redirection' => 2,
            'user-agent'  => 'EasyOptimizer-CDN-Check; ' . home_url( '/' ),
            'headers'     => array( 'Origin' => $origin ),
        ) );
        if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
            return null;
        }
        $body = ltrim( (string) wp_remote_retrieve_body( $res ) );
        if ( '' === $body || '<' === $body[0] ) {
            return null;
        }
        $allow = wp_remote_retrieve_header( $res, 'access-control-allow-origin' );
        $allow = is_array( $allow ) ? '' : trim( (string) $allow );
        return '*' === $allow || 0 === strcasecmp( $origin, $allow );
    }

    public static function register_schedule( $schedules ) {
        if ( ! isset( $schedules[ self::HEALTH_EVERY ] ) ) {
            $schedules[ self::HEALTH_EVERY ] = array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => 'Every 15 minutes (Easy Optimizer CDN check)',
            );
        }
        return $schedules;
    }

    /** Checks run while a CDN is set up, and stop (state cleared) when none is. */
    public static function sync_health_schedule() {
        $want = self::configured( 'cloud' ) || self::configured( 'custom' );
        $next = wp_next_scheduled( self::HEALTH_HOOK );
        if ( $want && ! $next ) {
            wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, self::HEALTH_EVERY, self::HEALTH_HOOK );
        } elseif ( ! $want && $next ) {
            wp_clear_scheduled_hook( self::HEALTH_HOOK );
            delete_option( self::HEALTH_OPTION );
        }
    }

    /** A changed CDN setup starts from scratch; a new own-CDN address is tested before pages use it. */
    public static function on_settings_saved( $new, $old, $changed ) {
        $changed = (array) $changed;
        $custom  = (bool) array_intersect( array( 'easyopt_cdn_custom', 'easyopt_cdn_custom_url' ), $changed );
        $cloud   = in_array( 'easyopt_cloud_assets', $changed, true );
        if ( ! $custom && ! $cloud ) {
            return;
        }
        $all = self::health();
        if ( $custom ) {
            unset( $all['custom'] );
        }
        if ( $cloud ) {
            unset( $all['cloud'] );
        }
        update_option( self::HEALTH_OPTION, $all, true );
        if ( $custom && self::configured( 'custom' ) ) {
            self::check_health( 'custom' );
        }
        self::sync_health_schedule();
    }

    private static function rewrite_media_tag( $m ) {
        return preg_replace_callback(
            '/(\s(src|srcset|data-src|data-srcset|data-bg|poster|style)\s*=\s*)(["\'])(.*?)\3/i',
            function ( $a ) {
                $name = strtolower( $a[2] );
                if ( 'style' === $name ) {
                    $value = self::rewrite_css_urls( $a[4] );
                } elseif ( 'srcset' === $name || 'data-srcset' === $name ) {
                    $value = self::rewrite_srcset( $a[4] );
                } else {
                    $new   = self::cdn_url( $a[4] );
                    $value = '' === $new ? $a[4] : $new;
                }
                return $a[1] . $a[3] . $value . $a[3];
            },
            $m[0]
        );
    }

    private static function rewrite_srcset( $srcset ) {
        $out = array();
        foreach ( explode( ',', $srcset ) as $cand ) {
            $parts = preg_split( '/\s+/', trim( $cand ), 2 );
            $new   = '' === $parts[0] ? '' : self::cdn_url( $parts[0] );
            $out[] = ( '' === $new ? $parts[0] : $new ) . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' );
        }
        return implode( ', ', $out );
    }

    /**
     * (2.8.0) Tags a CDN must not take. Browsers fetch script modules with CORS,
     * so without the header they fail outright, even through FluxPress's redirect
     * to the site when its account lookup fails, and the import map names the
     * same modules on this site, so a CDN copy would load them a second time
     * anyway. On your own CDN, a tag marked crossorigin needs the header too.
     */
    private static function stays_on_site( $tag ) {
        if ( preg_match( '/\stype=(["\']?)module\1[\s>\/]/i', $tag ) || preg_match( '/\srel=(["\'])[^"\']*\bmodulepreload\b/i', $tag ) ) {
            return true;
        }
        return 'custom' === self::mode() && self::custom_lacks_cors() && preg_match( '/\scrossorigin\b/i', $tag );
    }

    private static function rewrite_link( $m ) {
        $tag = $m[0];
        if ( self::stays_on_site( $tag ) ) {
            return $tag;
        }
        // href on a normal or async ('media=print' + onload) stylesheet;
        // data-easyopt-delayed on one Unused CSS has deferred. Same file, same
        // rewrite — the deferred loader swaps data-easyopt-delayed back to href
        // at interaction, so the value it restores must already be the CDN URL.
        if ( ! preg_match( '/\s(?:href|data-easyopt-delayed)=(["\'])([^"\']+)\1/i', $tag, $h ) ) {
            return $tag;
        }
        $new = self::cdn_url( $h[2] );
        $tag = $new ? str_replace( $h[2], $new, $tag ) : $tag;
        // A responsive image preload must name the same files as the <img>,
        // or the browser fetches the image twice.
        if ( 'custom' === self::mode() && preg_match( '/\simagesrcset=(["\'])([^"\']+)\1/i', $tag, $is ) ) {
            $tag = str_replace( $is[2], self::rewrite_srcset( $is[2] ), $tag );
        }
        return $tag;
    }

    private static function rewrite_script( $m ) {
        if ( self::stays_on_site( $m[0] ) ) {
            return $m[0];
        }
        $src = $m[2];
        $new = self::cdn_url( $src );
        return $new ? str_replace( $src, $new, $m[0] ) : $m[0];
    }

    /**
     * Map one local asset URL onto the CDN, or '' to leave it alone.
     *
     * @param string $url
     * @return string
     */
    public static function cdn_url( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
            return '';
        }

        // Protocol-relative and root-relative URLs are ours; absolute ones
        // have to prove it by host.
        if ( 0 === strpos( $url, '//' ) ) {
            $url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
        } elseif ( 0 === strpos( $url, '/' ) ) {
            $url = home_url( $url );
        }

        if ( ! preg_match( '#^https?://#i', $url ) ) {
            return '';
        }

        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( '' === $host || self::site_host() !== preg_replace( '/^www\./', '', $host ) ) {
            return ''; // off-site: not ours to serve, and the guard would 403 it
        }

        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

        if ( 'custom' === self::mode() ) {
            if ( ! in_array( $ext, self::CUSTOM_EXT, true )
                || ( false === strpos( $path, '/wp-content/' ) && false === strpos( $path, '/wp-includes/' ) )
                || self::excluded( $url, 'easyopt_cdn_custom_exclude' )
                || ( in_array( $ext, self::NEEDS_CORS_EXT, true ) && self::custom_lacks_cors() ) ) {
                return '';
            }
            $query = (string) wp_parse_url( $url, PHP_URL_QUERY );
            return self::custom_base() . $path . ( '' !== $query ? '?' . $query : '' );
        }

        if ( ! in_array( $ext, self::EXT, true ) ) {
            return '';
        }

        if ( self::excluded( $url ) ) {
            return '';
        }

        // The guard rebuilds this by taking everything after '/s/', so the
        // origin is appended raw apart from the two characters that would
        // otherwise be lost to path normalisation. Order matches the image
        // path's escaping so both decode identically at the edge.
        $origin = str_replace( array( '%', '?' ), array( '%25', '%3F' ), $url );

        return untrailingslashit( (string) EasyOpt_CDN_Cloud::endpoint() )
            . '/' . EasyOpt_CDN_Cloud::account() . '/s/' . $origin
            . ( '' !== self::REV ? '?fx=' . self::REV : '' );
    }

    /**
     * (2.7.2) Re-point same-site static url()s — fonts above all — inside CSS
     * the plugin prints INLINE (the Used CSS block). process_buffer() only
     * moves assets the HTML names in <link>/<script>, so an inline
     * `url('/wp-content/…/inter-400.ttf')` resolved against the page's own
     * origin and never reached the edge. Same mapping and refusals as
     * cdn_url(): off-site, data: and non-allowlisted (image) URLs are left
     * alone — images have their own signed path.
     *
     * @param string $css
     * @return string
     */
    public static function rewrite_css_urls( $css ) {
        if ( '' === (string) $css || false === stripos( $css, 'url(' ) ) {
            return $css;
        }
        return preg_replace_callback(
            '/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i',
            function ( $m ) {
                $new = self::cdn_url( $m[2] );
                return '' === $new ? $m[0] : 'url(' . $m[1] . $new . $m[1] . ')';
            },
            $css
        );
    }

    /**
     * User exclusions, one substring per line. Deliberately substring rather
     * than glob: the setting is shared in shape with every other exclude box
     * in this plugin, and people paste partial paths into all of them.
     */
    private static function excluded( $url, $key = 'easyopt_cloud_assets_exclude' ) {
        $raw = (string) EasyOpt_Config::get( $key, '' );
        if ( '' === trim( $raw ) ) {
            return false;
        }
        foreach ( preg_split( '/\R/', $raw ) as $line ) {
            $line = trim( $line );
            if ( '' !== $line && false !== stripos( $url, $line ) ) {
                return true;
            }
        }
        return false;
    }

    private static function site_origin() {
        $p = wp_parse_url( home_url() );
        return ( isset( $p['scheme'] ) ? $p['scheme'] : 'https' ) . '://' . ( isset( $p['host'] ) ? $p['host'] : '' ) . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' );
    }

    private static function site_host() {
        if ( null === self::$host ) {
            self::$host = preg_replace(
                '/^www\./',
                '',
                strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) )
            );
        }
        return self::$host;
    }
}
