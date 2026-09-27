<?php
/**
 * Easy Optimizer — advanced-cache.php drop-in.
 *
 * Loaded by WordPress in wp-settings.php BEFORE plugins, theme functions.php
 * and the database query layer initialise. On a cache HIT, this file reads
 * a static .html_gzip from disk, sends headers, and exits — total work on a
 * hit is roughly: parse this file, file_exists(), readfile(), exit. No WP
 * bootstrap, no autoloaded options, no plugin code.
 *
 * The %%EASYOPT_CONFIG%% token is replaced with a var_export()'d array at
 * install time by EasyOpt_Advanced_Cache::install(). Updating any cache
 * setting in wp-admin re-runs that installer to keep this file in sync.
 *
 * NEVER hand-edit this file — it will be overwritten on the next setting
 * save. Instead, edit the source template in
 * <plugin>/assets/advanced-cache.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    return false;
}

// Allow forced bypasses (e.g. WP-CLI, our own preloader probes).
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    return false;
}
if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
    return false;
}

$easyopt_cfg = /*EASYOPT_CONFIG_START*/ array() /*EASYOPT_CONFIG_END*/;

if ( ! is_array( $easyopt_cfg ) || empty( $easyopt_cfg['cache_dir'] ) ) {
    return false;
}

// ── Shared decision logic (2.3.3) ────────────────────────────────────────
// URI exclusions, mobile-UA detection and cookie-variant keying live in ONE
// file used by both this drop-in and the runtime class, so the two serve
// paths can never drift apart. If the plugin folder is missing (deleted or
// renamed without uninstall), fail safe: serve nothing from cache and let
// WordPress generate the page normally.
if ( empty( $easyopt_cfg['common'] ) || ! is_file( $easyopt_cfg['common'] ) ) {
    return false;
}
require_once $easyopt_cfg['common'];
if ( ! function_exists( '\\EasyOpt\\Cache\\is_excluded_uri' ) ) {
    return false;
}

// ── Per-site config override (2.3.3) ─────────────────────────────────────
// On multisite, one shared drop-in used to serve EVERY site with the config
// of whichever site installed it last. Each site now exports its own config
// to cache/easyopt/config/{host}[-{first-path-segment}].php at save time and
// the drop-in resolves the right one per request. Single-site installs also
// write theirs (harmless); the embedded config above remains the fallback.
$easyopt_req_host = isset( $_SERVER['HTTP_HOST'] )
    ? strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', (string) $_SERVER['HTTP_HOST'] ) )
    : '';
$easyopt_req_uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
if ( ! empty( $easyopt_cfg['config_dir'] ) && '' !== $easyopt_req_host ) {
    $easyopt_site_cfg_file = \EasyOpt\Cache\locate_site_config( $easyopt_cfg['config_dir'], $easyopt_req_host, $easyopt_req_uri );
    if ( '' !== $easyopt_site_cfg_file ) {
        $easyopt_site_cfg = include $easyopt_site_cfg_file;
        if ( is_array( $easyopt_site_cfg ) && ! empty( $easyopt_site_cfg['cache_dir'] ) ) {
            // Preserve the shared keys the per-site file doesn't carry.
            $easyopt_site_cfg['common'] = $easyopt_cfg['common'];
            $easyopt_cfg                = $easyopt_site_cfg;
        }
    }
}

// ── Method check (only GET / HEAD are cacheable) ────────────────────────
if ( ! isset( $_SERVER['REQUEST_METHOD'] )
     || ! in_array( $_SERVER['REQUEST_METHOD'], array( 'GET', 'HEAD' ), true ) ) {
    return false;
}

// (2.5.7) The X-Easyopt-Preload header check was REMOVED. The preloader has
// not sent that header since the query-arg switch (a non-standard header is a
// bot signal some host WAFs block), so the check served no purpose for us —
// but it remained a live cache bypass for anyone else: any client sending
// `X-Easyopt-Preload: 1` forced a guaranteed MISS and a full render, with no
// query string to make it visible in log analysis grouped by URL.

// ── URI exclusions (built-in + user) — shared logic ─────────────────────
// 2.3.3: the old inline list bare-substring-matched ".xml"/".txt", wrongly
// excluding permalinks like /guide-to-xml-sitemaps/ from drop-in serving.
// The shared function suffix-matches extensions, identical to the runtime.
$easyopt_uri = $easyopt_req_uri;
if ( \EasyOpt\Cache\is_excluded_uri(
    $easyopt_uri,
    ! empty( $easyopt_cfg['exclude_urls'] ) ? (array) $easyopt_cfg['exclude_urls'] : array()
) ) {
    return false;
}

// ── Strip tracking-only params; if anything meaningful remains, MISS. ───
// (2.6.1) 'strip_query_params' already contains any "Cache Query String"
// names, because EasyOpt_Cache::get_strip_params() unions them in. That is
// deliberate: a parameter getting its own cache file must still be invisible
// to THIS test, or the request would look uncacheable. Its value is accounted
// for in the filename instead — see $easyopt_query_tag below.
$easyopt_get = $_GET;
if ( ! empty( $easyopt_get ) && ! empty( $easyopt_cfg['strip_query_params'] ) ) {
    foreach ( $easyopt_cfg['strip_query_params'] as $easyopt_strip ) {
        unset( $easyopt_get[ $easyopt_strip ] );
    }
}
if ( ! empty( $easyopt_get ) ) {
    return false;
}

// ── Cookie-based bypass (custom session cookies, etc.) ──────────────────
$easyopt_cookies = ! empty( $_COOKIE ) ? array_keys( $_COOKIE ) : array();

// 1.5.5 — Plugin-set bouncer cookie. Issued by EasyOpt_Cache for every
// logged-in visitor at `init` priority 1, before the buffer hooks run.
// We treat its mere presence as a hard MISS regardless of the
// `cache_logged_in` setting, because by the time this drop-in is reached
// the cookie has already been accepted by the browser — and on hosts
// that strip `wordpress_logged_in_*` upstream this is the only signal
// the drop-in has that the visitor is logged in.
if ( isset( $_COOKIE['easyopt_skip_cache'] ) ) {
    return false;
}

if ( ! empty( $easyopt_cfg['exclude_cookies'] ) ) {
    foreach ( $easyopt_cookies as $easyopt_cn ) {
        foreach ( $easyopt_cfg['exclude_cookies'] as $easyopt_cookie_pat ) {
            if ( '' !== $easyopt_cookie_pat && false !== stripos( $easyopt_cn, $easyopt_cookie_pat ) ) {
                return false;
            }
        }
    }
}

// ── Detect logged-in user via WP auth cookies. ──────────────────────────
$easyopt_logged_in = false;
$easyopt_role_tag  = '';
foreach ( $easyopt_cookies as $easyopt_cn ) {
    if ( 0 === strpos( $easyopt_cn, 'wordpress_logged_in_' )
         || 0 === strpos( $easyopt_cn, 'wp-postpass_' )
         || 0 === strpos( $easyopt_cn, 'comment_author_' ) ) {
        $easyopt_logged_in = true;
        break;
    }
}
if ( $easyopt_logged_in && empty( $easyopt_cfg['cache_logged_in'] ) ) {
    return false;
}
if ( $easyopt_logged_in && ! empty( $easyopt_cfg['cache_logged_in'] ) ) {
    // Role tag is provided to us by the runtime side via a non-auth cookie.
    if ( isset( $_COOKIE['easyopt_user_role'] ) ) {
        $easyopt_role_tag = preg_replace( '/[^a-z0-9_\-]/i', '', (string) $_COOKIE['easyopt_user_role'] );
    }
    if ( '' === $easyopt_role_tag ) {
        // Logged in but role unknown yet → MISS, runtime will set the cookie.
        return false;
    }
}

// ── Mobile detection — shared logic, identical to the cache WRITER ──────
$easyopt_is_mobile = false;
if ( ! empty( $easyopt_cfg['separate_mobile'] ) && isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
    $easyopt_is_mobile = \EasyOpt\Cache\is_mobile_ua( (string) $_SERVER['HTTP_USER_AGENT'] );
}

// ── Per-include-cookie keying (e.g. WPML language, currency) ────────────
// Shared logic — identical sanitisation/ordering to the cache writer.
$easyopt_extra_tag = '';
if ( ! empty( $easyopt_cfg['include_cookies'] ) ) {
    $easyopt_extra_tag = \EasyOpt\Cache\cookie_variant_tag( $_COOKIE, (array) $easyopt_cfg['include_cookies'] );
}

// ── Per-query-string keying (2.6.1, "Cache Query String") ───────────────
// Shared logic — identical sanitisation, ordering and 32-char value cap to
// the cache writer, so both resolve the same filename. Empty for every site
// that has not configured the setting, which keeps the path byte-identical
// to 2.6.0 for existing cache entries.
$easyopt_query_tag = '';
if ( ! empty( $easyopt_cfg['query_strings'] ) ) {
    $easyopt_query_tag = \EasyOpt\Cache\query_variant_tag( $_GET, (array) $easyopt_cfg['query_strings'] );
}

// ── Resolve cache file path ─────────────────────────────────────────────
// (2.6.1) Identical host resolution to the writer, via the shared function.
// This read the raw request host while the writer folded any unrecognised
// HTTP_HOST onto the site's own host, so on a domain alias, a mapped domain,
// a reverse-proxy hostname or a .test domain the writer stored the page under
// one directory and this file looked for it under another — a permanent,
// silent, every-request MISS with nothing anywhere to explain it.
$easyopt_host = \EasyOpt\Cache\resolve_cache_host(
    $easyopt_req_host,
    isset( $easyopt_cfg['home_host'] ) ? (string) $easyopt_cfg['home_host'] : '',
    ! empty( $easyopt_cfg['known_hosts'] ) ? (array) $easyopt_cfg['known_hosts'] : array()
);
if ( '' === $easyopt_host ) {
    return false;
}

$easyopt_path = parse_url( $easyopt_uri, PHP_URL_PATH );
if ( ! is_string( $easyopt_path ) || '' === $easyopt_path ) {
    $easyopt_path = '/';
}
// (2.6.0) Path construction is shared with the runtime writer via
// cache-common.php. It previously used a narrower regex than the writer's
// sanitize_file_name(), so the two disagreed about where a page lived for any
// URL containing a character only one of them stripped — a permanent, silent
// cache MISS. The shared function also strips control characters BEFORE the
// traversal test, closing the "..%00" ordering hole this block had.
$easyopt_clean_path = \EasyOpt\Cache\path_segments( $easyopt_path );

$easyopt_filename  = 'index';
if ( $easyopt_logged_in ) {
    $easyopt_filename .= '-logged-in-' . $easyopt_role_tag;
}
if ( $easyopt_extra_tag ) {
    $easyopt_filename .= $easyopt_extra_tag;
}
if ( $easyopt_query_tag ) {
    $easyopt_filename .= $easyopt_query_tag;
}
if ( $easyopt_is_mobile ) {
    $easyopt_filename .= '-mobile';
}

$easyopt_dir = rtrim( $easyopt_cfg['cache_dir'], '/' ) . '/' . $easyopt_host . '/';
if ( '' !== $easyopt_clean_path ) {
    $easyopt_dir .= $easyopt_clean_path . '/';
}

$easyopt_file_html = $easyopt_dir . $easyopt_filename . '.html';
$easyopt_file_gz   = $easyopt_file_html . '_gzip';

// Gzip-only storage. Check _gzip first (normal path), fall back to
// legacy plain .html for backward compatibility during transition.
$easyopt_accept_gz = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] )
    && false !== stripos( (string) $_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip' );

// (2.5.7) Honour the Gzip toggle on the SERVE path, and never hand
// pre-compressed bytes to OpenLiteSpeed (it compresses natively on the way
// out and ignores the Apache-only SetEnvIfNoCase no-gzip guard, so the
// browser would receive gzip inside gzip and download the file).
$easyopt_want_gz = ( ! isset( $easyopt_cfg['gzip'] ) || ! empty( $easyopt_cfg['gzip'] ) )
                   && empty( $easyopt_cfg['is_ols'] );

if ( $easyopt_want_gz && file_exists( $easyopt_file_gz ) ) {
    $easyopt_serve = $easyopt_file_gz;
    $easyopt_gz_on = $easyopt_accept_gz;
    $easyopt_gz_decode = ! $easyopt_accept_gz; // decode for rare non-gzip clients
} elseif ( file_exists( $easyopt_file_html ) ) {
    $easyopt_serve = $easyopt_file_html;
    $easyopt_gz_on = false;
    $easyopt_gz_decode = false;
} else {
    return false; // MISS — let WordPress generate the page.
}

// ── TTL check (lazy purge on access when stale) ─────────────────────────
$easyopt_mtime = (int) filemtime( $easyopt_serve );
$easyopt_ttl   = isset( $easyopt_cfg['ttl'] ) ? (int) $easyopt_cfg['ttl'] : 0;
if ( $easyopt_ttl > 0 && ( time() - $easyopt_mtime ) > $easyopt_ttl ) {
    @unlink( $easyopt_file_html );
    @unlink( $easyopt_file_gz );
    return false;
}

// ── HIT — emit headers and serve ────────────────────────────────────────
$easyopt_last_modified = gmdate( 'D, d M Y H:i:s', $easyopt_mtime ) . ' GMT';

// 304 Not Modified — tiny revalidation, no body sent.
if ( isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
    $easyopt_client_ts = strtotime( (string) $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
    if ( $easyopt_client_ts && $easyopt_client_ts >= $easyopt_mtime ) {
        if ( ! headers_sent() ) {
            header(
                ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? (string) $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1' )
                . ' 304 Not Modified'
            );
            header( 'X-Easy-Optimizer-Cache: HIT-304' );
            header( 'X-Easy-Optimizer-Source: drop-in' );
            header( 'Cache-Control: no-cache, must-revalidate' );
            // (2.5.7) Same variance contract as the 200 path below — a
            // revalidation must not invite an edge cache to store a variant
            // it cannot tell apart from the others.
            $easyopt_vary304 = array( 'Accept-Encoding' );
            if ( ! empty( $easyopt_cfg['separate_mobile'] ) ) {
                $easyopt_vary304[] = 'User-Agent';
            }
            if ( ! $easyopt_logged_in && '' === $easyopt_extra_tag && '' === $easyopt_query_tag ) {
                header( 'CDN-Cache-Control: max-age=' . (int) $easyopt_cfg['cdn_max_age'] );
                header( 'Cache-Tag: ' . $easyopt_host );
            } else {
                $easyopt_vary304[] = 'Cookie';
                header( 'CDN-Cache-Control: no-store' );
                header( 'Cache-Control: private, no-cache, must-revalidate' );
            }
            header( 'Vary: ' . implode( ', ', $easyopt_vary304 ) );
            header( 'Last-Modified: ' . $easyopt_last_modified );
        }
        exit;
    }
}

if ( ! headers_sent() ) {
    // Stop PHP from compressing what's already gzipped.
    if ( $easyopt_gz_on ) {
        if ( function_exists( 'ini_set' ) ) { @ini_set( 'zlib.output_compression', '0' ); }
    }
    header( 'Content-Type: text/html; charset=UTF-8' );
    header( 'X-Easy-Optimizer-Cache: HIT' );
    header( 'X-Easy-Optimizer-Source: drop-in' );
    // Browser revalidates every visit — the revalidation cost is the 304 path
    // above (no WP boot, no DB).
    header( 'Cache-Control: no-cache, must-revalidate' );

    // (2.5.7) Declare what this response actually varies on. The file served
    // is one of several variants selected by device, role cookie and
    // include-cookie value, but only Accept-Encoding was declared — so any
    // shared cache honouring CDN-Cache-Control stored ONE variant and served
    // it to everyone for 30 days: mobile HTML to desktop visitors, one
    // currency's prices to all shoppers, and (with logged-in caching on) a
    // logged-in user's page — admin bar, greeting and live nonces — to
    // anonymous visitors. Cookie-keyed variants have no Vary a CDN will
    // honour usefully, so they must not be edge-cached at all.
    $easyopt_vary = array( 'Accept-Encoding' );
    if ( ! empty( $easyopt_cfg['separate_mobile'] ) ) {
        $easyopt_vary[] = 'User-Agent';
    }

    // (2.6.1) A query-keyed variant is told apart ONLY by its query string.
    // Bunny keys on it by default, but plenty of edges and managed-host caches
    // are configured to ignore query strings entirely — and one that does
    // would store the first variant and serve it to every campaign. There is
    // no Vary header that expresses "varies by query string", so the only safe
    // answer is not to edge-cache these at all. Browser and drop-in caching
    // are unaffected, and sites that never fill in "Cache Query String" never
    // reach this branch.
    if ( ! $easyopt_logged_in && '' === $easyopt_extra_tag && '' === $easyopt_query_tag ) {
        header( 'CDN-Cache-Control: max-age=' . (int) $easyopt_cfg['cdn_max_age'] );
        header( 'Cache-Tag: ' . $easyopt_host );
    } else {
        $easyopt_vary[] = 'Cookie';
        header( 'CDN-Cache-Control: no-store' );
        header( 'Cache-Control: private, no-cache, must-revalidate' );
    }

    header( 'Vary: ' . implode( ', ', $easyopt_vary ) );
    header( 'Last-Modified: ' . $easyopt_last_modified );
    if ( $easyopt_gz_on ) {
        header( 'Content-Encoding: gzip' );
    }
}

// HEAD must return headers only, never a body (RFC 7231 §4.3.2). Without this
// a cached HIT would stream the full page bytes in response to a HEAD request.
if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'] ) {
    exit;
}

if ( $easyopt_gz_on ) {
    readfile( $easyopt_serve );
} elseif ( ! empty( $easyopt_gz_decode ) && function_exists( 'gzdecode' ) ) {
    // Rare: gzip-only file but client doesn't accept gzip — decompress.
    echo gzdecode( (string) file_get_contents( $easyopt_serve ) );
} else {
    readfile( $easyopt_serve );
}
exit;
