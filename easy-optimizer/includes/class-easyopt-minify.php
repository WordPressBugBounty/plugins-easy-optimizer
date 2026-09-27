<?php
/**
 * CSS / JS minification for Easy Optimizer (2.3.0).
 *
 * Minifies LOCAL stylesheet and script files referenced in the page,
 * caches each minified result on disk keyed by a hash of the source file
 * contents, and rewrites the tag to point at the cached copy. Because the
 * cache key is the content hash, a given file is only ever minified once
 * — every later request that references the same file just swaps the URL
 * string. That keeps the steady-state cost to a regex scan plus a few
 * stat()/str_replace() calls, which is what makes it safe on shared and
 * otherwise constrained hosts.
 *
 * Design notes / safety guarantees:
 *   - Local files only. Remote/CDN URLs are skipped (left untouched).
 *   - Already-minified files (.min.css / .min.js) are versioned, not
 *     re-minified.
 *   - For JS, a minified file is only used when it saves a meaningful
 *     amount (otherwise the original is kept, versioned).
 *   - Relative url(...) / @import references inside CSS are rewritten to
 *     absolute URLs before the file is moved to the cache directory, so
 *     background images and fonts keep resolving.
 *   - The whole pass is wrapped so any failure leaves the HTML untouched
 *     — minification can never blank or break a page.
 *   - The Delay-JS loader and the lazy-load runtime are always excluded so
 *     their behaviour is never disturbed.
 *
 * Uses matthiasmullie/minify (Composer) — the same well-tested minifier
 * used by other performance plugins. This file is an original
 * implementation; only the public library is shared.
 *
 * @package EasyOptimizer
 * @since   2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Minify {

    /** Max source size we will minify inline on a first hit (safety valve). */
    const MAX_FILE_BYTES = 3145728; // 3 MB

    /**
     * Path fragment that identifies a URL as our own minified output. Used to
     * make the pass idempotent: process_buffer() must be safe to run twice.
     */
    const CACHE_MARKER = '/cache/easyopt/min/';

    /** @var string */
    private static $cache_dir = '';
    /** @var string */
    private static $cache_url = '';
    /** @var string */
    private static $root_dir = '';
    /** @var bool */
    private static $autoloaded = false;

    /**
     * (2.5.4 / perf #1) Per-request memo: source path -> stat-derived key.
     * Repeated references to the same asset on one page cost one stat total.
     *
     * @var array<string,string>
     */
    private static $key_memo = array();

    /**
     * (2.5.4 / perf #1) Cheap, stable 12-char cache key for a source asset.
     *
     * Derived from path + size + mtime instead of hashing the full file
     * contents. The old md5 of the whole source cost a full-file read PER
     * ASSET PER UNCACHED RENDER in steady state; path|size|mtime is the
     * same trust model opcache and rsync use, changes whenever the file
     * actually changes, and hashes a ~100-byte string instead. A touched-
     * but-identical file simply re-minifies once — harmless.
     *
     * Returns '' when the file can't be stat'd or exceeds MAX_FILE_BYTES;
     * on '' the caller skips the tag exactly like the old empty-hash path.
     *
     * @param string $file Absolute path (already is_file-verified by caller).
     * @return string 12 hex chars or ''.
     */
    private static function asset_key( $file ) {
        if ( isset( self::$key_memo[ $file ] ) ) {
            return self::$key_memo[ $file ];
        }
        $stat = @stat( $file );
        if ( false === $stat || empty( $stat['size'] ) || $stat['size'] > self::MAX_FILE_BYTES ) {
            self::$key_memo[ $file ] = '';
            return '';
        }
        $key = substr( md5( $file . '|' . $stat['size'] . '|' . $stat['mtime'] ), 0, 12 );
        self::$key_memo[ $file ] = $key;
        return $key;
    }

    /**
     * Master entry — called from the output buffer. Independently applies
     * CSS and JS minification based on their own toggles.
     */
    public static function process_buffer( $html ) {

        $do_css = (int) EasyOpt_Config::get( 'minify_css', 0 );
        $do_js  = (int) EasyOpt_Config::get( 'minify_js', 0 );

        if ( ! $do_css && ! $do_js ) {
            return $html;
        }

        // (2.6.2) Logged-in gate — see easyopt_skip_for_logged_in().
        if ( easyopt_skip_for_logged_in( 'minify' ) ) {
            return $html;
        }

        // Don't process WooCommerce dynamic pages (cart/checkout/account).
        if ( EasyOpt_Config::is_woo_dynamic_page() ) {
            return $html;
        }

        if ( ! self::ensure_cache_dirs() ) {
            return $html;
        }
        self::ensure_autoload();

        // Protect literal <link>/<script> markup shown inside
        // <textarea>/<pre>/<code>/<xmp>/<noscript>/<svg> from being rewritten.
        $mask = class_exists( 'EasyOpt_HTML_Mask' ) ? new EasyOpt_HTML_Mask() : null;
        if ( $mask ) {
            $html = $mask->mask( $html );
        }

        if ( $do_css ) {
            $html = self::minify_css( $html );
        }
        if ( $do_js ) {
            $html = self::minify_js( $html );
        }

        if ( $mask ) {
            $html = $mask->unmask( $html );
        }

        return $html;
    }

    /* ── CSS ────────────────────────────────────────────────────────────── */

    private static function minify_css( $html ) {
        if ( ! class_exists( 'MatthiasMullie\\Minify\\CSS' ) ) {
            return $html;
        }

        if ( ! preg_match_all( '#<link\b[^>]*?>#i', $html, $matches ) ) {
            return $html;
        }

        $excludes = self::css_excludes();

        try {
            foreach ( $matches[0] as $tag ) {

                // Stylesheets only.
                if ( ! preg_match( '/\brel\s*=\s*["\']?stylesheet["\']?/i', $tag ) ) {
                    continue;
                }
                // Never touch our own injected used-CSS link.
                if ( false !== stripos( $tag, 'easyopt-used-css' ) ) {
                    continue;
                }
                if ( self::matches_any( $tag, $excludes ) ) {
                    continue;
                }

                $href = self::attr( $tag, 'href' );
                if ( '' === $href ) {
                    continue;
                }

                $file = self::local_path( $href );
                if ( false === $file || ! is_file( $file ) ) {
                    continue;
                }

                // (2.5.4 / perf #1) stat-derived key — no full-file hashing
                // on the hot path. Also enforces size floor/ceiling in one stat.
                $hash = self::asset_key( $file );
                if ( '' === $hash ) {
                    continue;
                }

                // Our own output from an earlier pass. The buffer can run twice
                // (nested ob_start, or stored HTML re-processed), and the
                // hashed filename we emit matches looks_hashed() below — so
                // without this the second pass appends a ?ver= and the asset
                // URL changes for identical content, busting the browser cache
                // for nothing. Leaving it alone makes the pass idempotent.
                if ( false !== strpos( $href, self::CACHE_MARKER ) ) {
                    continue;
                }

                // Already minified, OR already a content-hashed asset produced
                // by another optimizer (e.g. self-hosted fonts) — just add a
                // cache-busting version. Re-minifying yields little and would
                // create an ugly doubly-hashed filename.
                if ( preg_match( '/\.min\.css(?:$|[?#])/i', $href ) || self::looks_hashed( $file ) ) {
                    $html = str_replace( $href, self::versioned( $href, $hash ), $html );
                    continue;
                }

                // FlyingPress-style filename: {12-char hash}.{original basename}
                // e.g. a1b2c3d4e5f6.style.css — keeps the source name readable.
                $min_name = $hash . '.' . basename( $file );
                $min_path = self::$cache_dir . 'css/' . $min_name;
                $min_url  = self::$cache_url . 'css/' . $min_name;

                if ( is_file( $min_path ) ) {
                    // Referenced — refresh the GC last-used marker (≤1 touch/day).
                    if ( class_exists( 'EasyOpt_Cache' ) ) {
                        EasyOpt_Cache::touch_if_stale( $min_path );
                    }
                } else {
                    $css = (string) @file_get_contents( $file );
                    if ( '' === $css ) {
                        continue;
                    }
                    $css = self::absolutize_css_urls( $css, $href );
                    $minifier = new \MatthiasMullie\Minify\CSS();
                    $minifier->add( $css );
                    $out = (string) $minifier->minify();
                    if ( '' === $out ) {
                        continue; // never replace with empty
                    }
                    if ( false === @file_put_contents( $min_path, $out, LOCK_EX ) ) {
                        continue;
                    }
                }

                // Only use the minified copy if it actually helps — the same
                // guard minify_js() has always had. absolutize_css_urls()
                // rewrites every relative url() to an absolute one, which on a
                // small sheet full of relative paths makes the "minified" file
                // LARGER than the original (measured: 108 B → 268 B). Serving
                // that is a straight regression, so fall back to the original
                // plus a cache-busting version.
                $orig_size = (int) filesize( $file );
                $min_size  = (int) filesize( $min_path );
                $saved     = $orig_size - $min_size;
                if ( $saved < 256 || ( $orig_size > 0 && ( $saved / $orig_size ) < 0.05 ) ) {
                    $html = str_replace( $href, self::versioned( $href, $hash ), $html );
                    continue;
                }

                $html = str_replace( $href, $min_url, $html );
            }
        } catch ( \Throwable $e ) {
            return $html;
        } catch ( \Exception $e ) {
            return $html;
        }

        return $html;
    }

    /* ── JS ─────────────────────────────────────────────────────────────── */

    private static function minify_js( $html ) {
        if ( ! class_exists( 'MatthiasMullie\\Minify\\JS' ) ) {
            return $html;
        }

        if ( ! preg_match_all( '#<script\b[^>]*\bsrc\s*=\s*["\'][^"\']+["\'][^>]*>\s*</script>#i', $html, $matches ) ) {
            return $html;
        }

        $excludes = self::js_excludes();

        try {
            foreach ( $matches[0] as $tag ) {

                // Skip scripts already rewritten by Delay JS (no real src).
                if ( false !== stripos( $tag, 'easyoptscript' ) ) {
                    continue;
                }
                if ( self::matches_any( $tag, $excludes ) ) {
                    continue;
                }

                $src = self::attr( $tag, 'src' );
                if ( '' === $src ) {
                    continue;
                }

                $file = self::local_path( $src );
                if ( false === $file || ! is_file( $file ) ) {
                    continue;
                }

                // (2.5.4 / perf #1) stat-derived key — see asset_key().
                $hash = self::asset_key( $file );
                if ( '' === $hash ) {
                    continue;
                }

                // Our own output from an earlier pass — see the CSS branch.
                if ( false !== strpos( $src, self::CACHE_MARKER ) ) {
                    continue;
                }

                // Already minified, OR already a content-hashed asset — version only.
                if ( preg_match( '/\.min\.js(?:$|[?#])/i', $src ) || self::looks_hashed( $file ) ) {
                    $html = str_replace( $src, self::versioned( $src, $hash ), $html );
                    continue;
                }

                // FlyingPress-style filename: {12-char hash}.{original basename}.
                $min_name = $hash . '.' . basename( $file );
                $min_path = self::$cache_dir . 'js/' . $min_name;
                $min_url  = self::$cache_url . 'js/' . $min_name;

                if ( is_file( $min_path ) ) {
                    // Referenced — refresh the GC last-used marker (≤1 touch/day).
                    if ( class_exists( 'EasyOpt_Cache' ) ) {
                        EasyOpt_Cache::touch_if_stale( $min_path );
                    }
                } else {
                    $minifier = new \MatthiasMullie\Minify\JS( $file );
                    $minifier->minify( $min_path );
                    if ( ! is_file( $min_path ) ) {
                        continue;
                    }
                }

                // Only use the minified copy if it actually helps. Otherwise
                // keep the original (versioned) to avoid an extra cache file.
                $orig_size = (int) filesize( $file );
                $min_size  = (int) filesize( $min_path );
                $saved     = $orig_size - $min_size;
                if ( $saved < 256 || ( $orig_size > 0 && ( $saved / $orig_size ) < 0.05 ) ) {
                    $html = str_replace( $src, self::versioned( $src, $hash ), $html );
                    continue;
                }

                $html = str_replace( $src, $min_url, $html );
            }
        } catch ( \Throwable $e ) {
            return $html;
        } catch ( \Exception $e ) {
            return $html;
        }

        return $html;
    }

    /* ── Exclude lists ──────────────────────────────────────────────────── */

    private static function css_excludes() {
        // Reuse the Unused-CSS "Exclude Stylesheets" field, as requested.
        $list = array();
        $user = (string) EasyOpt_Config::get( 'unused_css_exclude_stylesheets', '' );
        if ( '' !== $user ) {
            $list = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $user ) ) );
        }
        return apply_filters( 'easyopt_minify_css_excludes', $list );
    }

    private static function js_excludes() {
        // Built-ins: never touch the Delay-JS loader or the lazy-load
        // runtime — rewriting those would disturb their behaviour. Our other
        // scripts (instant-load runtime, LCP beacon, etc.) remain eligible.
        $list = array(
            'easyopt-delay',
            'easyopt-delayed-styles',
            'lazyload.min.js',
            'easyopt-lazysizes',
        );
        // Reuse the Delay-JS "Exclude Scripts" field, as requested.
        $user = (string) EasyOpt_Config::get( 'delay_js_exclude', '' );
        if ( '' !== $user ) {
            $list = array_merge( $list, array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $user ) ) ) );
        }
        return apply_filters( 'easyopt_minify_js_excludes', $list );
    }

    private static function matches_any( $haystack, $needles ) {
        foreach ( (array) $needles as $n ) {
            if ( '' !== $n && false !== stripos( $haystack, $n ) ) {
                return true;
            }
        }
        return false;
    }

    /* ── URL / path helpers ─────────────────────────────────────────────── */

    /** Append/refresh a ?ver= cache-buster on a URL. */
    private static function versioned( $url, $ver ) {
        $base = strtok( $url, '#' );
        $frag = '';
        if ( false !== strpos( $url, '#' ) ) {
            $frag = substr( $url, strpos( $url, '#' ) );
        }
        $base = preg_replace( '/([?&])ver=[^&]*/', '$1ver=' . $ver, $base, 1, $count );
        if ( ! $count ) {
            $base .= ( false === strpos( $base, '?' ) ? '?' : '&' ) . 'ver=' . $ver;
        }
        return $base . $frag;
    }

    /** Read an attribute value (decoded for &amp; etc.). */
    private static function attr( $tag, $name ) {
        if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '\s*=\s*["\']([^"\']+)["\']/i', $tag, $m ) ) {
            return html_entity_decode( $m[1], ENT_QUOTES );
        }
        return '';
    }

    /**
     * True when a file is already a content-addressed / optimized asset, so
     * we should version it rather than minify it again (which would create a
     * doubly-hashed filename). Covers our own minified output and the hashed
     * output of other optimizers / self-hosted-font tools.
     */
    private static function looks_hashed( $file ) {
        $file = (string) $file;
        if ( false !== strpos( $file, '/cache/easyopt/min/' ) ) {
            return true;
        }
        $name = basename( $file );
        $name = preg_replace( '/\.(css|js)$/i', '', $name );
        return (bool) preg_match( '/[a-f0-9]{16,}/i', (string) $name );
    }

    /** Resolve a URL to an absolute local file path, or false if remote. */
    private static function local_path( $url ) {
        if ( '' === $url ) {
            return false;
        }
        $clean = strtok( $url, '?#' );

        if ( 0 === strpos( $clean, '//' ) ) {
            $clean = ( is_ssl() ? 'https:' : 'http:' ) . $clean;
        }

        // Root-relative path.
        if ( 0 === strpos( $clean, '/' ) && 0 !== strpos( $clean, '//' ) ) {
            return self::root_dir() . ltrim( $clean, '/' );
        }

        $locals = array(
            trailingslashit( home_url() ),
            trailingslashit( site_url() ),
        );
        $locals[] = preg_replace( '#^https?:#', '', trailingslashit( home_url() ) );
        $locals[] = preg_replace( '#^https?:#', '', trailingslashit( site_url() ) );

        $relative = str_ireplace( $locals, '', $clean );
        if ( preg_match( '#^https?://#i', $relative ) ) {
            return false; // genuinely remote
        }
        return self::root_dir() . ltrim( $relative, '/' );
    }

    /** Web root directory (where root-relative URLs resolve from). */
    private static function root_dir() {
        if ( '' !== self::$root_dir ) {
            return self::$root_dir;
        }
        $count       = 0; // (2.3.3) explicit init for the by-ref counter
        $content_rel = str_replace(
            array( trailingslashit( home_url() ), trailingslashit( site_url() ) ),
            '',
            content_url(),
            $count
        );
        if ( empty( $count ) ) {
            $path        = wp_parse_url( home_url(), PHP_URL_PATH );
            $base        = trailingslashit( $path ? str_replace( $path, '', home_url() ) : home_url() );
            $content_rel = str_replace( $base, '', content_url() );
        }
        $pos = strrpos( WP_CONTENT_DIR, $content_rel );
        if ( false !== $pos ) {
            self::$root_dir = substr_replace( WP_CONTENT_DIR, '', $pos, strlen( $content_rel ) );
        } else {
            self::$root_dir = WP_CONTENT_DIR;
        }
        self::$root_dir = trailingslashit( self::$root_dir );
        return self::$root_dir;
    }

    /**
     * Rewrite relative url(...) and @import targets in a stylesheet to
     * absolute URLs, based on the stylesheet's own location. Needed because
     * the minified copy lives in a different directory.
     */
    private static function absolutize_css_urls( $css, $sheet_url ) {
        $base = self::dir_url( $sheet_url );
        if ( '' === $base ) {
            return $css;
        }

        // url(...) references.
        $css = preg_replace_callback(
            '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
            function ( $m ) use ( $base ) {
                $u = trim( $m[2] );
                if ( '' === $u || self::is_absolute_or_data( $u ) || 0 === strpos( $u, '#' ) ) {
                    return $m[0];
                }
                return 'url(' . $m[1] . self::resolve_relative( $base, $u ) . $m[1] . ')';
            },
            $css
        );

        // @import "..." / @import url(...) (url() form already handled above).
        $css = preg_replace_callback(
            '/@import\s+([\'"])([^\'"]+)\1/i',
            function ( $m ) use ( $base ) {
                $u = trim( $m[2] );
                if ( '' === $u || self::is_absolute_or_data( $u ) ) {
                    return $m[0];
                }
                return '@import ' . $m[1] . self::resolve_relative( $base, $u ) . $m[1];
            },
            $css
        );

        return $css;
    }

    private static function is_absolute_or_data( $u ) {
        return (bool) preg_match( '#^(https?:)?//#i', $u )
            || 0 === stripos( $u, 'data:' )
            || 0 === strpos( $u, '/' );
    }

    /** Directory URL of a stylesheet (absolute, scheme + host + path dir). */
    private static function dir_url( $sheet_url ) {
        $clean = strtok( $sheet_url, '?#' );
        if ( 0 === strpos( $clean, '//' ) ) {
            $clean = ( is_ssl() ? 'https:' : 'http:' ) . $clean;
        }
        if ( 0 === strpos( $clean, '/' ) && 0 !== strpos( $clean, '//' ) ) {
            $origin = rtrim( ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : wp_parse_url( home_url(), PHP_URL_HOST ) ), '/' );
            $clean  = $origin . $clean;
        }
        $slash = strrpos( $clean, '/' );
        return ( false === $slash ) ? '' : substr( $clean, 0, $slash + 1 );
    }

    /** Join a relative reference onto an absolute directory URL, collapsing ../ */
    private static function resolve_relative( $base_dir, $rel ) {
        $combined = $base_dir . $rel;
        // Split scheme://host from path so ../ collapsing stays in the path.
        if ( preg_match( '#^(https?://[^/]+)(/.*)$#i', $combined, $m ) ) {
            $origin = $m[1];
            $path   = $m[2];
        } else {
            return $combined;
        }
        $segments = array();
        foreach ( explode( '/', $path ) as $seg ) {
            if ( '..' === $seg ) {
                array_pop( $segments );
            } elseif ( '.' === $seg || '' === $seg ) {
                continue;
            } else {
                $segments[] = $seg;
            }
        }
        // Preserve a trailing slash if the original had one.
        $trailing = ( '/' === substr( $path, -1 ) ) ? '/' : '';
        return $origin . '/' . implode( '/', $segments ) . $trailing;
    }

    /* ── Cache dir + autoload ───────────────────────────────────────────── */

    private static function ensure_cache_dirs() {
        if ( '' === self::$cache_dir ) {
            self::$cache_dir = trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt/min/';
            self::$cache_url = trailingslashit( content_url() ) . 'cache/easyopt/min/';
        }
        foreach ( array( 'css', 'js' ) as $sub ) {
            $d = self::$cache_dir . $sub . '/';
            if ( ! is_dir( $d ) ) {
                wp_mkdir_p( $d );
            }
            if ( ! is_dir( $d ) ) {
                return false;
            }
        }
        return true;
    }

    private static function ensure_autoload() {
        if ( self::$autoloaded ) {
            return;
        }
        $autoload = defined( 'EASYOPT_DIR' ) ? EASYOPT_DIR . 'vendor/autoload.php' : '';
        if ( $autoload && file_exists( $autoload ) ) {
            require_once $autoload;
        }
        self::$autoloaded = true;
    }
}
