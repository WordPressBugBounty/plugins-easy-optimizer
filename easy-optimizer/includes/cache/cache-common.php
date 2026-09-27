<?php
/**
 * EasyOpt\Cache — shared cache-decision logic.
 *
 * Single source of truth for the request-classification rules that BOTH the
 * runtime serve path (EasyOpt_Cache) and the wp-content/advanced-cache.php
 * drop-in must agree on. Before 2.3.3 these rules were duplicated in two
 * places and had already drifted (the drop-in's bare ".xml"/".txt" substring
 * exclusion wrongly skipped permalinks like /guide-to-xml-sitemaps/ while the
 * runtime used correct suffix matching). Keeping them here kills that entire
 * class of drift bug.
 *
 * HARD CONSTRAINTS for this file:
 *   • NO WordPress functions. The drop-in loads this before WP bootstraps.
 *   • NO side effects. Pure functions only.
 *   • Namespaced (EasyOpt\Cache) — first namespaced module of the plugin;
 *     namespaces are resolved at compile time and add zero runtime cost.
 *
 * @package EasyOptimizer
 * @since   2.3.3
 */

namespace EasyOpt\Cache;

// Loaded both inside WP (ABSPATH defined) and by the drop-in (ABSPATH also
// defined by wp-load before advanced-cache.php runs). Direct web access has
// neither — bail.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( __NAMESPACE__ . '\\is_excluded_uri' ) ) {

    /**
     * Built-in URI exclusions shared by runtime + drop-in.
     *
     * @return string[] Substring patterns (safe to match anywhere in the URI).
     */
    function builtin_uri_exclusions() {
        // Sitemaps are intentionally NOT listed here — they're matched by a
        // path-segment-anchored test in is_excluded_uri() (EO-05) so content
        // permalinks like /sitemap-guide/ stay cacheable.
        return array(
            '/wp-admin', '/wp-login', '/wp-cron', '/xmlrpc.php',
            '/wp-json/', '/?rest_route=', '/feed/', 'feed=',
        );
    }

    /**
     * Canonical URL / exclusion-pattern matcher for the whole plugin (2.6.5).
     *
     * Every "Exclude URLs" field routes through here so they behave the same:
     * page cache, Delay/Defer JS, LCP preload, Fonts, Remove Unused CSS. Before
     * 2.6.5 only Delay/Defer JS understood `*` — the four others matched it as a
     * literal character, so a user who learned `/shop/*` worked in one field
     * found it silently ignored in the rest.
     *
     * Semantics, chosen to be the least surprising:
     *   • A pattern with NO `*` keeps its original case-insensitive SUBSTRING
     *     behaviour, so every setting saved before 2.6.5 matches exactly as it
     *     did — this change can only start honouring a `*`, never re-interpret
     *     an existing plain pattern.
     *   • A pattern WITH `*` is a wildcard: `*` stands for "any run of
     *     characters", every other character stays literal (dots, parens and
     *     regex metacharacters are quoted, so `/a.b/*` means a literal dot).
     *
     * Lives here, in the namespaced cache-common module, because the
     * advanced-cache.php drop-in loads before the plugin and can reach this but
     * not the plugin's classes. Plugin-side callers reach it as
     * \EasyOpt\Cache\url_matches_pattern().
     *
     * @param string $url     Absolute or relative URL of the current request.
     * @param string $pattern One line from an exclusion field.
     * @return bool
     */
    function url_matches_pattern( $url, $pattern ) {
        $pattern = trim( (string) $pattern );
        if ( '' === $pattern ) {
            return false;
        }
        if ( false === strpos( $pattern, '*' ) ) {
            return false !== stripos( (string) $url, $pattern );
        }
        // preg_quote escapes the asterisk to \*; turn only that back into .*
        $regex = '#' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '#i';
        return 1 === preg_match( $regex, (string) $url );
    }

    /**
     * Decide whether a request URI is excluded from caching.
     *
     * Built-in tokens are substring-matched; user patterns support `*`
     * wildcards (2.6.5) via url_matches_pattern(). The static-file extensions
     * (.xml / .txt) are matched as PATH SUFFIXES only, so permalinks that
     * merely contain ".xml"/".txt" in a slug stay cacheable.
     *
     * @param string   $uri           Raw REQUEST_URI (may include query).
     * @param string[] $user_patterns User exclusions; `*` = wildcard, else substring.
     * @return bool True when the URI must NOT be cached/served from cache.
     */
    function is_excluded_uri( $uri, array $user_patterns = array() ) {
        $uri = (string) $uri;
        if ( '' === $uri ) {
            return false;
        }

        foreach ( builtin_uri_exclusions() as $needle ) {
            if ( false !== stripos( $uri, $needle ) ) {
                return true;
            }
        }

        // Suffix-only extension test on the path component.
        $q        = strpos( $uri, '?' );
        $uri_path = ( false === $q ) ? $uri : substr( $uri, 0, $q );
        $h        = strpos( $uri_path, '#' );
        if ( false !== $h ) {
            $uri_path = substr( $uri_path, 0, $h );
        }
        foreach ( array( '.xml', '.txt' ) as $ext ) {
            $elen = strlen( $ext );
            if ( strlen( $uri_path ) >= $elen
                 && 0 === substr_compare( $uri_path, $ext, -$elen, $elen, true ) ) {
                return true;
            }
        }

        // (EO-05) Sitemaps — anchored to a path SEGMENT so content permalinks
        // like /sitemap-guide/ or /our-sitemap-tips/ stay cacheable. Standard
        // .xml/.txt sitemaps are also covered by the suffix test above; this
        // adds bare /sitemap endpoints and .xsl/.gz sitemap variants.
        foreach ( explode( '/', trim( $uri_path, '/' ) ) as $seg ) {
            if ( '' === $seg ) {
                continue;
            }
            $low = strtolower( $seg );
            if ( 'sitemap' === $low || 'sitemaps' === $low
                 || 'sitemap_index' === $low || 'sitemapindex' === $low
                 || preg_match( '/sitemap[\w.-]*\.(?:xml|xsl|txt|gz)$/', $low ) ) {
                return true;
            }
        }

        foreach ( $user_patterns as $pattern ) {
            if ( url_matches_pattern( $uri, (string) $pattern ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mobile UA detection — identical logic for runtime and drop-in so the
     * writer and both serve paths always pick the same device variant.
     *
     * Fast path: ~99% of mobile UAs contain "Mobi" (case-sensitive strpos is
     * ~50× cheaper than the regex). Fallback regex covers niche/legacy UAs.
     *
     * @param string $ua HTTP_USER_AGENT value.
     * @return bool
     */
    function is_mobile_ua( $ua ) {
        $ua = (string) $ua;
        if ( '' === $ua ) {
            return false;
        }
        if ( false !== strpos( $ua, 'Mobi' ) ) {
            return true;
        }
        return (bool) preg_match(
            '#(avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino)#i',
            $ua
        );
    }

    /**
     * Build the include-cookie portion of the cache filename (e.g. currency /
     * language switcher keying). Same sanitisation and ordering everywhere so
     * the writer and both serve paths read/write the identical file.
     *
     * @param array    $cookies         $_COOKIE-shaped map.
     * @param string[] $include_cookies Cookie NAMES that key the cache.
     * @return string Leading-dash tag (e.g. "-EUR") or ''.
     */
    function cookie_variant_tag( array $cookies, array $include_cookies ) {
        if ( empty( $include_cookies ) || empty( $cookies ) ) {
            return '';
        }
        $tag = '';
        foreach ( $include_cookies as $name ) {
            $name = (string) $name;
            if ( '' !== $name && isset( $cookies[ $name ] ) ) {
                $tag .= '-' . preg_replace( '/[^a-z0-9_\-]/i', '', (string) $cookies[ $name ] );
            }
        }
        return $tag;
    }

    /**
     * Resolve the host a request's cache files live under.
     *
     * HTTP_HOST is attacker-controlled, so rotating it mints unbounded junk
     * directories under the cache root. Falling back to the site's own host
     * for anything unrecognised stops that — but the fallback MUST be applied
     * identically by the writer and by the drop-in, or they disagree about
     * where a page lives and every request is a silent permanent MISS.
     *
     * That drift is exactly what happened when the writer alone was changed,
     * and it is the failure mode this whole file exists to prevent: no error,
     * no log line, no dashboard signal, the page simply re-renders forever.
     *
     * @since 2.6.1
     * @param string   $raw_host  Raw HTTP_HOST.
     * @param string   $home_host The site's own host. '' skips the fallback.
     * @param string[] $known     Additional legitimate hosts (multisite
     *                            domains, mapped domains, configured aliases).
     *                            Non-empty means "trust this list", so the
     *                            fallback never fires on a network install.
     * @return string Sanitised host, or '' when nothing usable was supplied.
     */
    function resolve_cache_host( $raw_host, $home_host = '', array $known = array() ) {
        $host = strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', (string) $raw_host ) );
        $home = strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', (string) $home_host ) );

        if ( '' === $host ) {
            return $home;
        }
        if ( '' === $home ) {
            return $host; // nothing to compare against — accept as-is
        }
        if ( $host === $home || $host === 'www.' . $home || 'www.' . $host === $home ) {
            return $host;
        }
        foreach ( $known as $k ) {
            $k = strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', (string) $k ) );
            if ( '' !== $k && ( $host === $k || $host === 'www.' . $k || 'www.' . $host === $k ) ) {
                return $host;
            }
        }
        return $home;
    }

    /**
     * Query parameters that must NEVER key a cache variant, whatever the site
     * owner types into "Cache Query String".
     *
     * Every one of these carries a value that is unique per click, per
     * recipient or per session. Keying the cache on one does not create a
     * handful of extra files — it creates one file per visitor, plus a gzip
     * sidecar, forever, until the disk or the inode table runs out. `gclid`
     * alone would do it on any site running Google Ads.
     *
     * This is a hard floor, enforced at the point of use in BOTH serve paths
     * rather than only in the settings UI, so a value arriving by filter or by
     * a direct option write cannot get past it either.
     *
     * @since 2.6.1
     * @return string[]
     */
    function never_key_params() {
        return array(
            // Per-click advertising identifiers.
            'gclid', 'gbraid', 'wbraid', 'gclsrc', 'dclid', 'gad_source',
            'gad_campaignid', 'gadid', 'srsltid',
            'fbclid', 'fbadid', 'msclkid', 'yclid', 'twclid', 'ttclid',
            'igshid', 'epik', 'irclickid', 'li_fat_id', 'rdt_cid',
            'ScCid', 'sc_cid', 'wickedid', 'sscid', 'ef_id', 's_kwcid',
            // Per-recipient email / marketing automation identifiers.
            'mc_eid', '_ke', '_kx', 'mkt_tok', 'ck_subscriber_id',
            'ml_subscriber', 'ml_subscriber_hash', 'omnisendContactID',
            'vgo_ee', '_bta_tid', 'elqTrack', 'elqTrackId',
            '_hsenc', '_hsmi', 'trk_contact', 'trk_sid', 'dm_i',
            // Per-session analytics identifiers.
            '_ga', '_gl',
        );
    }

    /**
     * Build the query-string portion of the cache filename.
     *
     * Mirrors cookie_variant_tag(): one shared function so the writer and both
     * serve paths cannot disagree about which file a request maps to. Drift
     * here is the single most expensive bug class in this plugin's history —
     * the writer stores at path A, the drop-in reads at path B, and the result
     * is a permanent silent MISS with no error and no dashboard signal.
     *
     * Rules, all of which exist to bound cardinality:
     *   - Only NAMED parameters key anything. An unlisted parameter is either
     *     stripped (if it is on the ignore list) or makes the request
     *     uncacheable — it can never mint a file.
     *   - Names are sorted, so ?a=1&b=2 and ?b=2&a=1 are one file.
     *   - Values are sanitised to [a-z0-9_-] and capped at 32 characters, so a
     *     scanner sending two kilobytes of junk cannot become a filename.
     *   - never_key_params() is refused outright.
     *
     * The whole tag is prefixed "-q" so the query segment can never be
     * confused with the include-cookie tag that precedes it: without it, a
     * currency cookie whose value happened to equal a parameter name would
     * produce the same filename as that parameter.
     *
     * Array parameters (?a[]=1) are recorded as present-but-valueless rather
     * than skipped. Skipping them would make ?a[]=1 resolve to the same file
     * as a request with no `a` at all, which is wrong for a parameter the
     * owner has explicitly declared significant. Distinguishing ?a[]=1 from
     * ?a[]=2 is out of scope — this feature is for scalar values.
     *
     * @since 2.6.1
     * @param array    $get    $_GET-shaped map.
     * @param string[] $params Parameter NAMES that key the cache.
     * @return string Leading-dash tag (e.g. "-q-utm_source-google") or ''.
     */
    function query_variant_tag( array $get, array $params ) {
        if ( empty( $params ) || empty( $get ) ) {
            return '';
        }
        $never = array_map( 'strtolower', never_key_params() );

        $pairs = array();
        foreach ( $params as $name ) {
            $name = trim( (string) $name );
            if ( '' === $name || in_array( strtolower( $name ), $never, true ) ) {
                continue;
            }
            if ( ! isset( $get[ $name ] ) ) {
                continue;
            }
            $key = preg_replace( '/[^a-z0-9_\-]/i', '', $name );
            if ( '' === $key ) {
                continue;
            }
            // Arrays are serialised rather than flattened to '', so ?a[]=1 and
            // ?a[]=2 hash differently. serialize() is PHP core — this file
            // loads before WordPress and must not call a WP function.
            $raw = is_array( $get[ $name ] ) ? serialize( $get[ $name ] ) : (string) $get[ $name ]; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

            // THE READABLE PART IS NOT THE IDENTITY.
            //
            // Sanitising to [a-z0-9_-] and truncating at 32 both MERGE
            // distinct values: ?ref=john.doe and ?ref=johndoe produced the
            // same filename, as did ?ref=a/b and ?ref=ab, and any two values
            // sharing their first 32 characters. For a feature whose entire
            // premise is that the SERVER renders this value into the page, a
            // collision serves one visitor the HTML built for another — which
            // is the exact bug this feature exists to fix.
            //
            // So the readable slug stays for debuggability, but identity comes
            // from a digest of the RAW value appended to it. Two different raw
            // values can now never resolve to one file.
            $slug = substr( (string) preg_replace( '/[^a-z0-9_\-]/i', '', $raw ), 0, 24 );
            $pairs[ $key ] = ( '' !== $slug ? $slug . '-' : '' ) . substr( md5( $raw ), 0, 8 );
        }
        if ( empty( $pairs ) ) {
            return '';
        }
        // Sorted: two URLs carrying the same parameters in a different order
        // are the same page and must share one file.
        ksort( $pairs );

        $tag = '-q';
        foreach ( $pairs as $k => $v ) {
            $tag .= '-' . $k . ( '' !== $v ? '-' . $v : '' );
        }
        return $tag;
    }

    /**
     * Sanitise ONE URL path segment into a cache directory name.
     *
     * (2.6.0) Path construction is now shared, like every other cache
     * decision in this file. Previously the writer used WordPress'
     * sanitize_file_name() while the drop-in hand-rolled a much narrower
     * regex, so any segment containing a character one stripped and the
     * other kept resolved to two DIFFERENT directories: the writer stored
     * the page at path A, the drop-in looked for it at path B, found
     * nothing, and returned MISS. Every request. Permanently. With no
     * error, no log line and no dashboard signal — the page simply
     * re-rendered forever while the cache appeared to be working.
     *
     * Deliberately NOT a reimplementation of sanitize_file_name(): that
     * function is built for uploaded filenames, its seems_utf8() fallback
     * and mime/extension branch both need WordPress, and the drop-in runs
     * before WordPress exists. Instead this mirrors the transformations
     * that matter for a path segment. For the slugs WordPress actually
     * produces — sanitize_title() output, i.e. [a-z0-9-] — it is a no-op
     * and therefore byte-identical to the previous writer behaviour, so
     * ordinary sites keep every existing cache entry.
     *
     * Order matters: control characters and separators are stripped BEFORE
     * the traversal test, so a segment like "..%00" (which decodes to
     * "..\0", is not equal to "..", and became ".." only after the old
     * drop-in's strip ran) can no longer smuggle a traversal component
     * into the resolved path.
     *
     * @since 2.6.0
     * @param string $seg Raw (still percent-encoded) path segment.
     * @return string Safe directory name, or '' when the segment must be dropped.
     */
    function path_segment( $seg ) {

        $seg = rawurldecode( (string) $seg );

        // Control chars, DEL, NUL and both path separators — first, so
        // nothing below can reconstitute a traversal.
        $seg = preg_replace( '/[\x00-\x1f\x7f\/\\\\]/', '', $seg );
        if ( '' === $seg || '.' === $seg || '..' === $seg ) {
            return '';
        }

        // Mirrors sanitize_file_name(), INCLUDING its operation order: the
        // special-character class is removed first (which is why '+' is
        // deleted rather than turned into a dash — the %20/+ replacement
        // below can never see it), then runs of whitespace/dashes collapse,
        // then leading/trailing .-_ are trimmed. Reproducing the order
        // matters: it is what keeps output byte-identical to the previous
        // writer for every existing cache entry.
        $seg = str_replace(
            array( '?', '[', ']', '=', '<', '>', ':', ';', ',', "'", '"', '&',
                   '$', '#', '*', '(', ')', '|', '~', '`', '!', '{', '}', '%', '+' ),
            '',
            $seg
        );
        $seg = str_replace( array( '%20', '+' ), '-', $seg );
        $seg = preg_replace( '/[\r\n\t -]+/', '-', $seg );
        $seg = trim( $seg, '.-_' );

        // Re-test: the trim above can expose a traversal ("..." → "").
        if ( '' === $seg || '.' === $seg || '..' === $seg ) {
            return '';
        }

        return $seg;
    }

    /**
     * Build the cache sub-path for a URL path component.
     *
     * @since 2.6.0
     * @param string $path URL path (already query/fragment-stripped).
     * @return string Cleaned relative path, no leading or trailing slash.
     */
    function path_segments( $path ) {
        $out = array();
        foreach ( explode( '/', (string) $path ) as $seg ) {
            $clean = path_segment( $seg );
            if ( '' !== $clean ) {
                $out[] = $clean;
            }
        }
        return implode( '/', $out );
    }

    /**
     * Resolve the per-site drop-in config file for a request. Written by
     * EasyOpt_Advanced_Cache::install() — one file per site, keyed by host
     * (and first path segment on subdirectory multisite). This is what makes
     * the single shared advanced-cache.php drop-in serve every network site
     * with ITS OWN exclusion lists / TTL instead of site 1's.
     *
     * Lookup order: {host}-{first-segment}.php → {host}.php → '' (caller
     * falls back to the config embedded in the drop-in itself).
     *
     * @param string $config_dir Absolute dir holding the per-site configs.
     * @param string $host       Sanitised request host.
     * @param string $uri        Raw REQUEST_URI.
     * @return string Absolute path of the matching config file, or ''.
     */
    function locate_site_config( $config_dir, $host, $uri ) {
        $config_dir = rtrim( (string) $config_dir, '/' );
        $host       = strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', (string) $host ) );
        if ( '' === $config_dir || '' === $host || ! is_dir( $config_dir ) ) {
            return '';
        }

        // First path segment (subdirectory-multisite discriminator).
        $path = (string) parse_url( (string) $uri, PHP_URL_PATH );
        $seg  = '';
        if ( '' !== $path && '/' !== $path ) {
            $parts = explode( '/', trim( $path, '/' ) );
            if ( isset( $parts[0] ) ) {
                $seg = preg_replace( '/[^a-z0-9_.\-]/i', '', $parts[0] );
            }
        }

        if ( '' !== $seg ) {
            $candidate = $config_dir . '/' . $host . '-' . $seg . '.php';
            if ( is_file( $candidate ) ) {
                return $candidate;
            }
        }
        $candidate = $config_dir . '/' . $host . '.php';
        return is_file( $candidate ) ? $candidate : '';
    }
}
