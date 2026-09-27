<?php
/**
 * EasyOpt — static asset delivery through FluxPress.
 *
 * Rewrites stylesheet, script and font URLs to the CDN:
 *
 *     https://site.com/wp-content/themes/x/style.css
 *   → https://cdn.fluxpress.io/{account}/s/https://site.com/wp-content/themes/x/style.css
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
     *   - we hold an account and the service says it is delivering (a lapsed
     *     trial sets this false, so assets fall back with the images)
     *   - this is a front-end page view
     *   - the user is logged out
     *
     * The last one is not caution. Logged-in pages carry admin-bar CSS and
     * nonce-bearing scripts, and an edge-cached copy of either is a
     * correctness problem, not a performance one.
     */
    public static function enabled() {
        if ( ! (int) EasyOpt_Config::get( 'easyopt_cloud_assets', 0 ) ) {
            return false;
        }
        if ( ! class_exists( 'EasyOpt_CDN_Cloud' ) ) {
            return false;
        }
        if ( ! EasyOpt_CDN_Cloud::is_connected() || ! EasyOpt_CDN_Cloud::is_delivering() ) {
            return false;
        }
        if ( is_admin() || is_user_logged_in() ) {
            return false;
        }
        if ( '' === (string) EasyOpt_CDN_Cloud::account() || '' === (string) EasyOpt_CDN_Cloud::endpoint() ) {
            return false;
        }
        return true;
    }

    /**
     * Rewrite a whole document.
     *
     * @param string $html
     * @return string
     */
    public static function process_buffer( $html ) {
        if ( '' === $html || ! self::enabled() ) {
            return $html;
        }

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

        return $html;
    }

    private static function rewrite_link( $m ) {
        $tag = $m[0];
        // href on a normal or async ('media=print' + onload) stylesheet;
        // data-easyopt-delayed on one Unused CSS has deferred. Same file, same
        // rewrite — the deferred loader swaps data-easyopt-delayed back to href
        // at interaction, so the value it restores must already be the CDN URL.
        if ( ! preg_match( '/\s(?:href|data-easyopt-delayed)=(["\'])([^"\']+)\1/i', $tag, $h ) ) {
            return $tag;
        }
        $new = self::cdn_url( $h[2] );
        return $new ? str_replace( $h[2], $new, $tag ) : $tag;
    }

    private static function rewrite_script( $m ) {
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
            . '/' . EasyOpt_CDN_Cloud::account() . '/s/' . $origin;
    }

    /**
     * User exclusions, one substring per line. Deliberately substring rather
     * than glob: the setting is shared in shape with every other exclude box
     * in this plugin, and people paste partial paths into all of them.
     */
    private static function excluded( $url ) {
        $raw = (string) EasyOpt_Config::get( 'easyopt_cloud_assets_exclude', '' );
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
