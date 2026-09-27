<?php
/**
 * Resource hints — automatic `preconnect` / `dns-prefetch`.
 *
 * WHY THIS EXISTS
 * ---------------
 * Every cross-origin asset costs a DNS lookup + TCP handshake + TLS
 * negotiation before its first byte can move. On a warm cache that setup
 * is frequently the single largest bar in the waterfall — the HTML arrives
 * in ~1ms from disk, then the browser spends 200-400ms opening a
 * connection to a font or CDN host it only just discovered while parsing.
 *
 * `<link rel="preconnect">` in the <head> starts that handshake during
 * HTML parse, so the connection is already open when the asset is
 * requested. It is the cheapest meaningful win available to a caching
 * plugin: no asset is modified, nothing can break visually.
 *
 * DESIGN NOTES
 * ------------
 * • Origins are discovered from the FINAL buffer, so anything the CDN
 *   rewrite, Used CSS or LCP passes introduced is seen (this processor
 *   deliberately runs late in the chain).
 * • Hard cap (default 6). Preconnect is not free — each hint holds a
 *   socket open, and browsers throttle/ignore excessive hints. Origins
 *   are ranked so the ones that block rendering come first.
 * • `crossorigin` is REQUIRED for font origins: a font fetch uses CORS
 *   mode, and a preconnect opened without `crossorigin` lands in a
 *   different connection pool — the handshake gets thrown away and
 *   repeated. This is the single most common way preconnect is
 *   implemented wrongly.
 * • Duplicate-safe: any origin the page already hints is skipped.
 *
 * @package EasyOptimizer
 * @since   2.5.5
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Preconnect {

    /** Maximum number of hints emitted (filterable). */
    const DEFAULT_MAX = 6;

    /**
     * Origins that must be opened with crossorigin (CORS-mode fetches).
     * Fonts are the important case; the rest are known font/asset CDNs.
     */
    private static $cors_origins = array(
        'fonts.gstatic.com',
        'fonts.googleapis.com',
        'use.typekit.net',
        'p.typekit.net',
        'use.fontawesome.com',
        'kit.fontawesome.com',
    );

    /**
     * Origins ranked highest — these block first paint.
     * Lower number = higher priority.
     */
    private static $priority_hosts = array(
        'fonts.gstatic.com'   => 0,
        'fonts.googleapis.com' => 1,
        'use.typekit.net'     => 1,
        'p.typekit.net'       => 1,
    );

    /**
     * (2.5.6) `<link rel>` values that cause the browser to actually open a
     * connection. Everything else is metadata: `rel="profile"` (the
     * gmpg.org/xfn/11 line every classic theme prints in header.php),
     * `rel="me"`, `rel="alternate"`, `rel="license"`, `rel="author"`,
     * `rel="pingback"`. No browser fetches those, so a preconnect for them
     * is a wasted DNS + TCP + TLS handshake — and worse, it consumes one of
     * the capped hint slots that a real font or CDN origin needs.
     *
     * `preconnect` / `dns-prefetch` are deliberately absent: hosts the page
     * already hints are removed separately by existing_hint_hosts().
     */
    private static $fetching_rels = array(
        'stylesheet'                   => 1,
        'preload'                      => 1,
        'modulepreload'                => 1,
        'prefetch'                     => 1,
        'prerender'                    => 1,
        'icon'                         => 1,
        'apple-touch-icon'             => 1,
        'apple-touch-icon-precomposed' => 1,
        'mask-icon'                    => 1,
        'manifest'                     => 1,
    );

    /**
     * Process the HTML buffer — inject resource hints into <head>.
     *
     * @param string $html Full page HTML.
     * @return string
     */
    public static function process_buffer( $html ) {

        if ( ! (int) EasyOpt_Config::get( 'preconnect', 1 ) ) {
            return $html;
        }

        // (2.6.2) Logged-in gate — see easyopt_skip_for_logged_in().
        if ( easyopt_skip_for_logged_in( 'preconnect' ) ) {
            return $html;
        }

        if ( ! is_string( $html ) || '' === $html ) {
            return $html;
        }

        // Needs a <head> to inject into.
        if ( ! preg_match( '/<head\b[^>]*>/i', $html, $head_m, PREG_OFFSET_CAPTURE ) ) {
            return $html;
        }

        $site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

        // Only scan the head + the first slice of the body. Origins that
        // matter for early loading are declared there, and this keeps the
        // regex work bounded on very large pages.
        $scan_limit = (int) apply_filters( 'easyopt_preconnect_scan_bytes', 120000 );
        $haystack   = ( strlen( $html ) > $scan_limit ) ? substr( $html, 0, $scan_limit ) : $html;

        $origins = self::collect_origins( $haystack, $site_host );

        // Explicitly registered origins (CDN host, etc.) always win a slot.
        $forced = (array) apply_filters( 'easyopt_preconnect_origins', array() );
        foreach ( $forced as $f ) {
            $host = self::host_from( (string) $f );
            if ( '' !== $host && $host !== $site_host ) {
                $origins[ $host ] = -1; // Highest priority.
            }
        }

        if ( empty( $origins ) ) {
            return $html;
        }

        // Drop origins the page already hints, so we never duplicate.
        $existing = self::existing_hint_hosts( $haystack );
        foreach ( $existing as $host ) {
            unset( $origins[ $host ] );
        }

        if ( empty( $origins ) ) {
            return $html;
        }

        // Rank: priority ascending, then alphabetically for stable output
        // (stable output matters — the HTML is cached and diffed).
        asort( $origins, SORT_NUMERIC );

        $max  = (int) apply_filters( 'easyopt_preconnect_max', self::DEFAULT_MAX );
        $max  = max( 1, min( 12, $max ) );
        $tags = '';
        $n    = 0;

        foreach ( $origins as $host => $rank ) {
            if ( $n >= $max ) {
                break;
            }
            $tags .= self::build_tag( $host );
            $n++;
        }

        if ( '' === $tags ) {
            return $html;
        }

        // Inject immediately after <head> — hints are only useful if the
        // parser reaches them before the assets they cover.
        $insert_at = $head_m[0][1] + strlen( $head_m[0][0] );

        return substr( $html, 0, $insert_at ) . $tags . substr( $html, $insert_at );
    }

    /**
     * Build the hint markup for one origin.
     *
     * (2.5.6) A paired dns-prefetch used to be emitted as a fallback for
     * browsers that ignore preconnect. Every browser still receiving updates
     * honours preconnect, and preconnect already performs the DNS lookup, so
     * the second tag was pure head weight. Dropped.
     *
     * @param string $host Hostname.
     * @return string
     */
    private static function build_tag( $host ) {

        $origin = 'https://' . $host;
        $cors   = in_array( $host, self::$cors_origins, true );

        /**
         * Filter whether an origin is preconnected in CORS mode.
         *
         * Required for anything fetched with CORS (fonts, and any asset
         * loaded with crossorigin/module semantics). Getting this wrong
         * wastes the handshake rather than breaking the page.
         *
         * @since 2.5.5
         * @param bool   $cors Whether to add crossorigin.
         * @param string $host Hostname.
         */
        $cors = (bool) apply_filters( 'easyopt_preconnect_crossorigin', $cors, $host );

        $tag = '<link rel="preconnect" href="' . esc_url( $origin ) . '"';
        if ( $cors ) {
            $tag .= ' crossorigin';
        }
        $tag .= '>';

        return $tag;
    }

    /**
     * Find cross-origin hosts referenced by early resources.
     *
     * Only element types that actually cause an early connection are
     * considered — stylesheets, scripts, fonts, preloads and images.
     * Anchors are ignored on purpose: linking to a domain is no reason to
     * open a socket to it.
     *
     * @param string $html      Buffer slice to scan.
     * @param string $site_host Current site host (skipped).
     * @return array host => rank
     */
    private static function collect_origins( $html, $site_host ) {

        $origins = array();

        $values = array();

        // <script src>, <img src>, <source src/srcset> — plus
        // (2.5.5) the data-* forms our own passes produce: legacy lazyload
        // moves src→data-src / srcset→data-srcset and backgrounds become
        // data-bg, which made CDN image origins invisible to this scanner on
        // exactly the pages that were rewritten.
        //
        // (2.5.6) <link> is NOT matched here — it is handled below, gated on
        // rel, because most <link> elements never trigger a fetch.
        if ( preg_match_all(
            '/<(?:script|img|source|iframe|div|section|span|figure)\b[^>]*?\b(?:href|src|srcset|imagesrcset|data-src|data-srcset|data-bg)\s*=\s*(["\'])(.*?)\1/is',
            $html,
            $m
        ) ) {
            $values = $m[2];
        }

        // (2.5.6) <link> — only the rel values that actually open a
        // connection. See $fetching_rels for why. A link with no rel at all
        // is skipped: it has no defined fetch behaviour.
        if ( preg_match_all( '/<link\b[^>]*>/is', $html, $lm ) ) {
            foreach ( $lm[0] as $link_tag ) {

                if ( ! preg_match( '/\brel\s*=\s*(["\'])(.*?)\1/is', $link_tag, $rel_m ) ) {
                    continue;
                }

                // rel is a space-separated token list ("shortcut icon").
                $fetches = false;
                foreach ( preg_split( '/\s+/', strtolower( trim( $rel_m[2] ) ) ) as $rel ) {
                    if ( isset( self::$fetching_rels[ $rel ] ) ) {
                        $fetches = true;
                        break;
                    }
                }
                if ( ! $fetches ) {
                    continue;
                }

                if ( preg_match( '/\b(?:href|imagesrcset)\s*=\s*(["\'])(.*?)\1/is', $link_tag, $href_m ) ) {
                    $values[] = $href_m[2];
                }
            }
        }

        // (2.5.5) url(...) inside inline <style> blocks. The origins that
        // matter most often live ONLY here: fonts.gstatic.com is referenced
        // from CSS (including our own inline Used CSS), never from a tag —
        // without this pass a site whose fonts arrive via Used CSS showed
        // zero font origins to the scanner.
        if ( preg_match_all( '/<style\b[^>]*>(.*?)<\/style>/is', $html, $sm ) ) {
            foreach ( $sm[1] as $css ) {
                if ( preg_match_all( '/url\(\s*(["\']?)((?:https?:)?\/\/[^"\')\s]+)\1\s*\)/i', $css, $um ) ) {
                    foreach ( $um[2] as $u ) {
                        $values[] = $u;
                    }
                }
            }
        }

        if ( empty( $values ) ) {
            return $origins;
        }

        foreach ( $values as $value ) {

            // srcset can carry several URLs; the first is enough to
            // establish the origin.
            if ( false !== strpos( $value, ',' ) ) {
                $parts = explode( ',', $value );
                $value = trim( (string) reset( $parts ) );
                $value = (string) strtok( $value, ' ' );
            }

            $host = self::host_from( $value );

            if ( '' === $host || $host === $site_host ) {
                continue;
            }

            $rank = isset( self::$priority_hosts[ $host ] )
                ? self::$priority_hosts[ $host ]
                : 5;

            // Keep the best (lowest) rank seen for this host.
            if ( ! isset( $origins[ $host ] ) || $rank < $origins[ $host ] ) {
                $origins[ $host ] = $rank;
            }
        }

        /**
         * Filter the discovered origins before they are ranked and capped.
         *
         * @since 2.5.5
         * @param array  $origins   host => rank (lower sorts first).
         * @param string $site_host The current site's host.
         */
        return (array) apply_filters( 'easyopt_preconnect_discovered', $origins, $site_host );
    }

    /**
     * Hosts the page already declares a preconnect/dns-prefetch for.
     *
     * @param string $html Buffer slice.
     * @return array
     */
    private static function existing_hint_hosts( $html ) {

        $hosts = array();

        if ( ! preg_match_all(
            '/<link\b[^>]*?\brel\s*=\s*(["\'])\s*(?:preconnect|dns-prefetch)\s*\1[^>]*>/is',
            $html,
            $m
        ) ) {
            return $hosts;
        }

        foreach ( $m[0] as $tag ) {
            if ( preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/is', $tag, $h ) ) {
                $host = self::host_from( $h[2] );
                if ( '' !== $host ) {
                    $hosts[] = $host;
                }
            }
        }

        return $hosts;
    }

    /**
     * Extract a lowercase hostname from a URL.
     *
     * Protocol-relative URLs are normalised; relative URLs, data: and
     * blob: return '' so they are ignored.
     *
     * @param string $url URL.
     * @return string
     */
    private static function host_from( $url ) {

        $url = trim( (string) $url );

        if ( '' === $url || 0 === strpos( $url, 'data:' ) || 0 === strpos( $url, 'blob:' ) ) {
            return '';
        }

        if ( 0 === strpos( $url, '//' ) ) {
            $url = 'https:' . $url;
        } elseif ( false === strpos( $url, '://' ) ) {
            // Relative — same origin, nothing to preconnect.
            return '';
        }

        $host = wp_parse_url( $url, PHP_URL_HOST );

        return is_string( $host ) ? strtolower( $host ) : '';
    }
}
