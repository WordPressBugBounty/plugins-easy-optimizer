<?php
/**
 * Lazy load background images set in CSS (2.8.0).
 *
 * The Lazy Load pass already defers backgrounds written in an element's
 * `style` attribute. This one covers the ones in the page's `<style>` blocks
 * — which includes the Used CSS, when Remove Unused CSS inlines it (the
 * default), so most of a site's stylesheet backgrounds land here.
 *
 * How: each `url()` in a `background` / `background-image` declaration
 * becomes `var(--eobg-…, none)`. A small inline script watches the elements
 * those rules select and, when one comes near the viewport, sets the
 * variable on <html> — every rule using that image then shows it. Without
 * JavaScript a <noscript> block sets them all.
 *
 * Left alone: the page's measured LCP image, anything matching "Exclude from
 * Lazy Load", @font-face / @keyframes / other non-grouping at-rules, nested
 * CSS, image-set(), data: and relative URLs, and rules that only select
 * html / body / :root (always on screen).
 *
 * @package EasyOptimizer
 * @since   2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Lazy_Bg {

    /** url => array{ v:string, u:string, s:string[] } for the current document. */
    private static $found = array();

    /** Request-scoped inputs, set by process_buffer() (tests set them directly via transform_css()). */
    private static $exclude = array();
    private static $lcp     = array();
    private static $origin  = '';

    /**
     * @param string $html
     * @return string
     */
    public static function process_buffer( $html ) {
        if ( ! (int) EasyOpt_Config::get( 'lazy_css_bg', 0 ) || '' === $html ) {
            return $html;
        }
        if ( easyopt_skip_for_logged_in( 'lazyload' ) || EasyOpt_Config::is_woo_dynamic_page() ) {
            return $html;
        }
        if ( false === stripos( $html, '<style' ) || false === stripos( $html, 'url(' ) ) {
            return $html;
        }

        self::prepare(
            (string) EasyOpt_Config::get( 'lazyload_exclude', '' ),
            ( class_exists( 'EasyOpt_LCP' ) && method_exists( 'EasyOpt_LCP', 'get_lcp_urls' ) ) ? (array) EasyOpt_LCP::get_lcp_urls() : array(),
            home_url( '/' )
        );

        // <noscript>, <textarea>, inline <svg>… stay as they are: a style
        // there that waits on our script would never get its image.
        $mask = class_exists( 'EasyOpt_HTML_Mask' ) ? new EasyOpt_HTML_Mask() : null;
        $work = $mask ? $mask->mask( $html ) : $html;

        // Scripts are matched only so a "<style>" inside a JS string is skipped.
        $work = preg_replace_callback(
            '#<script\b[^>]*>.*?</script>|<style\b([^>]*)>(.*?)</style>#is',
            array( __CLASS__, 'on_block' ),
            $work
        );
        if ( ! is_string( $work ) ) {
            return $html;
        }
        if ( $mask ) {
            $work = $mask->unmask( $work );
        }
        if ( ! self::$found ) {
            return $html;
        }

        $tail = self::runtime( array_values( self::$found ) );
        $pos  = strripos( $work, '</body>' );
        return false === $pos ? $work . $tail : substr( $work, 0, $pos ) . $tail . substr( $work, $pos );
    }

    /** @internal */
    public static function prepare( $exclude_raw, array $lcp_urls, $home ) {
        self::$found   = array();
        self::$exclude = array_values( array_filter( array_map( 'trim', explode( "\n", (string) $exclude_raw ) ) ) );
        self::$lcp     = array();
        foreach ( $lcp_urls as $u ) {
            $path = (string) wp_parse_url( (string) $u, PHP_URL_PATH );
            if ( '' !== $path ) {
                self::$lcp[ $path ] = true;
            }
        }
        $p            = wp_parse_url( (string) $home );
        self::$origin = ( isset( $p['scheme'] ) ? $p['scheme'] : 'https' ) . '://' . ( isset( $p['host'] ) ? $p['host'] : '' ) . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' );
    }

    /** @return array The images found since prepare(), as the runtime receives them. */
    public static function found() {
        return array_values( self::$found );
    }

    private static function on_block( $m ) {
        if ( 0 === stripos( $m[0], '<script' ) ) {
            return $m[0];
        }
        $attrs = $m[1];
        if ( preg_match( '/\bmedia\s*=\s*["\']?\s*print/i', $attrs ) ) {
            return $m[0];
        }
        $css = $m[2];
        if ( false === stripos( $css, 'url(' ) || false === stripos( $css, 'background' ) ) {
            return $m[0];
        }
        return '<style' . $attrs . '>' . self::transform_css( $css ) . '</style>';
    }

    /* ───────────────────────────────────────────────
     *  CSS walk
     * ─────────────────────────────────────────────── */

    /**
     * Rewrite one stylesheet's background url()s. Call prepare() first.
     *
     * @param string $css
     * @return string
     */
    public static function transform_css( $css ) {
        $out = '';
        $n   = strlen( $css );
        $i   = 0;
        while ( $i < $n ) {
            $d = self::next_delim( $css, $i, $n );
            if ( $d >= $n ) {
                $out .= substr( $css, $i );
                break;
            }
            if ( '{' !== $css[ $d ] ) {
                // A statement (`@import …;`) or a stray `}`.
                $out .= substr( $css, $i, $d - $i + 1 );
                $i    = $d + 1;
                continue;
            }
            $prelude = substr( $css, $i, $d - $i );
            $close   = self::match_brace( $css, $d + 1, $n );
            $body    = substr( $css, $d + 1, $close - $d - 1 );
            $head    = ltrim( (string) preg_replace( '#^(\s*/\*.*?\*/)*#s', '', $prelude ) );

            if ( '@' === substr( $head, 0, 1 ) ) {
                $body = self::is_group_rule( $head ) ? self::transform_css( $body ) : $body;
            } elseif ( ! self::has_block( $body ) ) {
                $body = self::rule_body( $prelude, $body );
            }
            $out .= $prelude . '{' . $body . ( $close < $n ? '}' : '' );
            $i    = $close + 1;
        }
        return $out;
    }

    private static function is_group_rule( $head ) {
        return 1 === preg_match( '/^@(?:-[a-z]+-)?(?:media|supports|container|layer|scope|document|starting-style)\b/i', $head );
    }

    /** Index of the next `{`, `}` or `;` outside strings, comments and url(). */
    private static function next_delim( $s, $i, $n ) {
        while ( $i < $n ) {
            $i += strcspn( $s, "{};\"'/(", $i );
            if ( $i >= $n ) {
                return $n;
            }
            $c = $s[ $i ];
            if ( '{' === $c || '}' === $c || ';' === $c ) {
                return $i;
            }
            $i = self::skip( $s, $i, $n );
        }
        return $n;
    }

    /** Does a rule body hold a nested block (native CSS nesting)? */
    private static function has_block( $s ) {
        $n = strlen( $s );
        for ( $i = self::next_delim( $s, 0, $n ); $i < $n; $i = self::next_delim( $s, $i + 1, $n ) ) {
            if ( '{' === $s[ $i ] ) {
                return true;
            }
        }
        return false;
    }

    /** Index of the `}` closing the block whose body starts at $i ($n when unbalanced). */
    private static function match_brace( $s, $i, $n ) {
        $depth = 1;
        while ( $i < $n ) {
            $i += strcspn( $s, "{}\"'/(", $i );
            if ( $i >= $n ) {
                return $n;
            }
            $c = $s[ $i ];
            if ( '{' === $c ) {
                $depth++;
                $i++;
            } elseif ( '}' === $c ) {
                if ( 0 === --$depth ) {
                    return $i;
                }
                $i++;
            } else {
                $i = self::skip( $s, $i, $n );
            }
        }
        return $n;
    }

    /** Step over the string, comment or url() starting at $i; other characters by one. */
    private static function skip( $s, $i, $n ) {
        $c = $s[ $i ];
        if ( '"' === $c || "'" === $c ) {
            for ( $j = $i + 1; $j < $n; $j++ ) {
                if ( '\\' === $s[ $j ] ) {
                    $j++;
                } elseif ( $c === $s[ $j ] || "\n" === $s[ $j ] ) {
                    return $j + 1;
                }
            }
            return $n;
        }
        if ( '/' === $c && $i + 1 < $n && '*' === $s[ $i + 1 ] ) {
            $e = strpos( $s, '*/', $i + 2 );
            return false === $e ? $n : $e + 2;
        }
        if ( '(' === $c && $i >= 3 && 0 === strcasecmp( substr( $s, $i - 3, 3 ), 'url' ) ) {
            $j = $i + 1;
            while ( $j < $n && ctype_space( $s[ $j ] ) ) {
                $j++;
            }
            if ( $j < $n && ( '"' === $s[ $j ] || "'" === $s[ $j ] ) ) {
                $j = self::skip( $s, $j, $n );
            }
            $e = strpos( $s, ')', $j );
            return false === $e ? $n : $e + 1;
        }
        return $i + 1;
    }

    /* ───────────────────────────────────────────────
     *  One rule
     * ─────────────────────────────────────────────── */

    private static function rule_body( $prelude, $body ) {
        if ( false === stripos( $body, 'url(' ) || false === stripos( $body, 'background' ) ) {
            return $body;
        }
        $selectors = null;
        $out       = '';
        foreach ( self::split_decls( $body ) as $decl ) {
            $colon = strpos( $decl, ':' );
            $prop  = false === $colon ? '' : strtolower( trim( (string) preg_replace( '#/\*.*?\*/#s', '', substr( $decl, 0, $colon ) ) ) );
            if ( ( 'background' !== $prop && 'background-image' !== $prop )
                || false === stripos( $decl, 'url(' )
                || false !== stripos( $decl, 'image-set(' ) ) {
                $out .= $decl;
                continue;
            }
            if ( null === $selectors ) {
                $selectors = self::js_selectors( $prelude );
            }
            $out .= $selectors
                ? substr( $decl, 0, $colon + 1 ) . self::rewrite_value( substr( $decl, $colon + 1 ), $selectors, $prelude )
                : $decl;
        }
        return $out;
    }

    /** Declarations with their `;` kept, split outside strings, comments and parentheses. */
    private static function split_decls( $body ) {
        $parts = array();
        $n     = strlen( $body );
        $start = 0;
        $depth = 0;
        $i     = 0;
        while ( $i < $n ) {
            $i += strcspn( $body, ";\"'/()", $i );
            if ( $i >= $n ) {
                break;
            }
            $c = $body[ $i ];
            if ( ';' === $c && 0 === $depth ) {
                $parts[] = substr( $body, $start, $i - $start + 1 );
                $start   = ++$i;
            } elseif ( '(' === $c ) {
                $next = self::skip( $body, $i, $n );
                if ( $next > $i + 1 ) {
                    $i = $next; // a whole url(…)
                } else {
                    $depth++;
                    $i++;
                }
            } elseif ( ')' === $c ) {
                $depth = max( 0, $depth - 1 );
                $i++;
            } else {
                $i = self::skip( $body, $i, $n );
            }
        }
        if ( $start < $n ) {
            $parts[] = substr( $body, $start );
        }
        return $parts;
    }

    private static function rewrite_value( $value, array $selectors, $prelude ) {
        return (string) preg_replace_callback(
            '/\burl\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)\s"\']*))\s*\)/i',
            function ( $m ) use ( $selectors, $prelude ) {
                $raw = isset( $m[3] ) && '' !== $m[3] ? $m[3] : ( isset( $m[2] ) && '' !== $m[2] ? $m[2] : $m[1] );
                $abs = self::absolute( trim( $raw ) );
                if ( '' === $abs || self::skipped( $abs, $prelude ) ) {
                    return $m[0];
                }
                // Own CDN on: the image comes from there, like every other one.
                if ( class_exists( 'EasyOpt_CDN_Assets' ) && 'custom' === EasyOpt_CDN_Assets::mode() ) {
                    $cdn = EasyOpt_CDN_Assets::cdn_url( $abs );
                    $abs = '' === $cdn ? $abs : $cdn;
                }
                if ( ! isset( self::$found[ $abs ] ) ) {
                    self::$found[ $abs ] = array( 'v' => 'eobg-' . substr( md5( $abs ), 0, 10 ), 'u' => $abs, 's' => array() );
                }
                foreach ( $selectors as $sel ) {
                    if ( ! in_array( $sel, self::$found[ $abs ]['s'], true ) ) {
                        self::$found[ $abs ]['s'][] = $sel;
                    }
                }
                return 'var(--' . self::$found[ $abs ]['v'] . ',none)';
            },
            $value
        );
    }

    /** Absolute URL, or '' for data:, fragments and paths relative to a file we cannot see. */
    private static function absolute( $u ) {
        if ( '' === $u || '#' === $u[0] || 0 === stripos( $u, 'data:' ) ) {
            return '';
        }
        if ( preg_match( '#^https?://#i', $u ) ) {
            return $u;
        }
        if ( 0 === strpos( $u, '//' ) ) {
            return (string) strstr( self::$origin, '//', true ) . $u;
        }
        if ( '/' === $u[0] ) {
            return self::$origin . $u;
        }
        return '';
    }

    private static function skipped( $abs, $prelude ) {
        $path = (string) wp_parse_url( $abs, PHP_URL_PATH );
        if ( '' !== $path && isset( self::$lcp[ $path ] ) ) {
            return true;
        }
        foreach ( self::$exclude as $line ) {
            if ( false !== stripos( $abs, $line ) || false !== stripos( $prelude, $line ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The rule's selectors as the runtime can query them: pseudo-classes and
     * pseudo-elements removed (so `.btn:hover` and `.hero::before` watch the
     * element itself — that only ever loads sooner, never later). Empty when
     * every selector is always on screen (html, body, :root) — then there is
     * nothing to wait for.
     *
     * @return string[]
     */
    public static function js_selectors( $prelude ) {
        $prelude = trim( (string) preg_replace( '#/\*.*?\*/#s', '', $prelude ) );
        $out     = array();
        $always  = true;
        foreach ( self::split_commas( $prelude ) as $sel ) {
            $sel = self::strip_pseudo( trim( $sel ) );
            if ( '' === $sel ) {
                continue;
            }
            if ( ! in_array( strtolower( $sel ), array( 'html', 'body', '*' ), true ) ) {
                $always = false;
            }
            $out[] = $sel;
        }
        return $always ? array() : array_values( array_unique( $out ) );
    }

    private static function split_commas( $s ) {
        $parts = array();
        $cur   = '';
        $depth = 0;
        $q     = '';
        $n     = strlen( $s );
        for ( $i = 0; $i < $n; $i++ ) {
            $c = $s[ $i ];
            if ( '\\' === $c ) {
                $cur .= $c . ( $i + 1 < $n ? $s[ ++$i ] : '' );
                continue;
            }
            if ( '' !== $q ) {
                $q    = ( $c === $q ) ? '' : $q;
                $cur .= $c;
                continue;
            }
            if ( '"' === $c || "'" === $c ) {
                $q = $c;
            } elseif ( '(' === $c || '[' === $c ) {
                $depth++;
            } elseif ( ')' === $c || ']' === $c ) {
                $depth = max( 0, $depth - 1 );
            } elseif ( ',' === $c && 0 === $depth ) {
                $parts[] = $cur;
                $cur     = '';
                continue;
            }
            $cur .= $c;
        }
        $parts[] = $cur;
        return $parts;
    }

    private static function strip_pseudo( $s ) {
        $out = '';
        $n   = strlen( $s );
        $br  = 0;
        $q   = '';
        $i   = 0;
        while ( $i < $n ) {
            $c = $s[ $i ];
            if ( '\\' === $c ) {
                $out .= $c . ( $i + 1 < $n ? $s[ $i + 1 ] : '' );
                $i   += 2;
                continue;
            }
            if ( '' !== $q ) {
                $q    = ( $c === $q ) ? '' : $q;
                $out .= $c;
                $i++;
                continue;
            }
            if ( '"' === $c || "'" === $c ) {
                $q = $c;
            } elseif ( '[' === $c ) {
                $br++;
            } elseif ( ']' === $c ) {
                $br = max( 0, $br - 1 );
            } elseif ( ':' === $c && 0 === $br ) {
                $j = $i + 1;
                if ( $j < $n && ':' === $s[ $j ] ) {
                    $j++;
                }
                while ( $j < $n && ( ctype_alnum( $s[ $j ] ) || '-' === $s[ $j ] || '_' === $s[ $j ] ) ) {
                    $j++;
                }
                if ( $j < $n && '(' === $s[ $j ] ) {
                    for ( $depth = 0; $j < $n; $j++ ) {
                        if ( '(' === $s[ $j ] ) {
                            $depth++;
                        } elseif ( ')' === $s[ $j ] && 0 === --$depth ) {
                            $j++;
                            break;
                        }
                    }
                }
                // A compound that was only pseudo-classes still selects something.
                $before = '' === $out ? ' ' : substr( $out, -1 );
                $after  = $j < $n ? $s[ $j ] : ' ';
                if ( false !== strpos( " \t\n>+~", $before ) && false !== strpos( " \t\n>+~", $after ) ) {
                    $out .= '*';
                }
                $i = $j;
                continue;
            }
            $out .= $c;
            $i++;
        }
        return trim( $out );
    }

    /* ───────────────────────────────────────────────
     *  Runtime
     * ─────────────────────────────────────────────── */

    private static function runtime( array $found ) {
        $vars = '';
        foreach ( $found as $f ) {
            $vars .= '--' . $f['v'] . ':url("' . addcslashes( $f['u'], "\"\\\n" ) . '");';
        }
        // Watches each rule's elements; the first one near the viewport sets
        // that image's variable on <html>. Elements added later (menus,
        // Ajax) are picked up by a throttled re-scan until every image is set.
        $js = '(function(){var D=' . wp_json_encode( $found ) . ',R=document.documentElement,P=D.slice(),io,mo,t=0;'
            . 'function set(e){if(!e.d){e.d=1;R.style.setProperty("--"+e.v,\'url("\'+e.u.replace(/["\\\\]/g,"\\\\$&")+\'")\');}}'
            . 'if(!("IntersectionObserver" in window)){D.forEach(set);return;}'
            . 'io=new IntersectionObserver(function(x){x.forEach(function(r){if(r.isIntersecting){io.unobserve(r.target);(r.target.eobg||[]).forEach(set);}});},{rootMargin:"300px 0px"});'
            . 'function scan(){t=0;P=P.filter(function(e){if(e.d)return 0;e.s.forEach(function(s){var n;try{n=document.querySelectorAll(s);}catch(z){set(e);return;}'
            . 'for(var k=0;k<n.length;k++){var l=n[k].eobg=n[k].eobg||[];if(l.indexOf(e)<0){l.push(e);io.observe(n[k]);}}});return !e.d;});'
            . 'if(!P.length&&mo)mo.disconnect();}'
            . 'scan();'
            . 'if(P.length&&"MutationObserver" in window){mo=new MutationObserver(function(){if(!t)t=setTimeout(scan,250);});'
            . 'mo.observe(R,{childList:true,subtree:true,attributes:true,attributeFilter:["class"]});}})();';
        return '<noscript><style>:root{' . $vars . '}</style></noscript><script id="easyopt-lazy-bg">' . $js . '</script>';
    }
}
