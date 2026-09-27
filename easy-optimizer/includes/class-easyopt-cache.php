<?php
/**
 * Page Cache for Easy Optimizer.
 *
 * Captures the fully-processed HTML buffer (CDN, LCP, Lazy, Unused CSS,
 * Fonts, SEO, Delay JS — everything our other modules produce) and writes
 * it to disk. Future visits get the file directly:
 *
 *   • Drop-in mode (1.5.0+) — wp-content/advanced-cache.php intercepts the
 *                   request before WordPress boots and serves the cached
 *                   .html_gzip directly. ~1ms TTFB on a HIT.
 *   • PHP mode    — served via WordPress on a very early hook (init,1) then
 *                   exit. Used as a fallback when the drop-in can't install.
 *   • Server mode — served by Apache .htaccess RewriteRules without ever
 *                   reaching PHP. Falls back to PHP mode on non-Apache hosts.
 *
 * Cache files live at:
 *   wp-content/cache/easyopt/{host}/{path}/index.html(_gzip)         (desktop)
 *   wp-content/cache/easyopt/{host}/{path}/index-mobile.html(_gzip)  (mobile)
 *   wp-content/cache/easyopt/{host}/{path}/index-logged-in-{role}.html(_gzip)
 *
 * Headers (1.5.0+):
 *   Cache-Control: no-cache, must-revalidate     ← browser revalidates
 *   CDN-Cache-Control: max-age=2592000           ← CDN holds 30 days
 *   Cache-Tag: <host>                            ← purge-by-tag for CDN
 *
 * Writes are independent of every other Easy Optimizer feature — disabling
 * cache does NOT disable Used CSS / LCP / Lazy / Fonts / Delay JS, and they
 * keep working on the live buffer just like before.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Shared cache logic (also loaded by the advanced-cache.php drop-in)
// and the two maintainability traits split out in 2.3.3. Traits are
// flattened at compile time — zero runtime cost, identical behaviour.
require_once EASYOPT_DIR . 'includes/cache/cache-common.php';
require_once EASYOPT_DIR . 'includes/cache/trait-easyopt-cache-purge.php';
require_once EASYOPT_DIR . 'includes/cache/trait-easyopt-cache-htaccess.php';

class EasyOpt_Cache {

    use EasyOpt_Cache_Purge_Trait;
    use EasyOpt_Cache_Htaccess_Trait;

    // (2.3.3) The old DEFAULT_TTL constant (36000) was removed: the settings
    // registry's schema default for cache_ttl is 0 (no server-side expiry),
    // which is also what the drop-in uses, so the constant was a misleading
    // dead fallback that never applied in practice.

    /** Default CDN max-age — 30 days. */
    const DEFAULT_CDN_MAX_AGE = 2592000;

    /** Cookie name we drop on login so the drop-in can vary cache by role. */
    const ROLE_COOKIE = 'easyopt_user_role';

    /** @var string  Absolute filesystem path of the cache root */
    private static $root_path = '';

    /** @var string  URL of the cache root (used for .htaccess rewrites) */
    private static $root_url  = '';

    /** @var bool    Did we already start the cache-write buffer this request? */
    private static $buffer_started = false;

    /* ───────────────────────────────────────────────
     *  Init
     * ─────────────────────────────────────────────── */

    /**
     * (2.6.2) Is there a cache upstream of us that we know how to purge?
     *
     * Used to decide whether the content-changed listeners are worth
     * registering when our own page cache is off. Memoised per request, and
     * only ever reached when our cache is off, so the common path pays nothing.
     *
     * @return bool
     */
    private static function has_upstream_cache() {
        static $has = null;
        if ( null !== $has ) {
            return $has;
        }
        $has = false;
        if ( class_exists( 'EasyOpt_Cloudflare' )
             && method_exists( 'EasyOpt_Cloudflare', 'is_configured' )
             && EasyOpt_Cloudflare::is_configured() ) {
            $has = true;
        } elseif ( class_exists( 'EasyOpt_Hosting' )
                   && method_exists( 'EasyOpt_Hosting', 'active_layers' ) ) {
            $layers = EasyOpt_Hosting::active_layers();
            $has    = ! empty( $layers );
        }
        return $has;
    }

    public static function init() {

        // Setup paths regardless of enable state — admin-bar/clear actions
        // need them even when cache is off.
        self::setup_paths();

        // Delayed preload restart after cache clear.
        add_action( 'easyopt_delayed_preload_start', array( 'EasyOpt_Cache_Preload', 'start' ) );

        // ── Developer API ──────────────────────────────────────────────
        // Public action hooks so other code can trigger purges without
        // calling our classes directly. Documented in readme.txt:
        //   do_action( 'easyopt_purge_all' );
        //   do_action( 'easyopt_purge_url', 'https://example.com/page/' );
        //   do_action( 'easyopt_purge_url_type', 'home' );  // home|front|posts
        add_action( 'easyopt_purge_all',      array( __CLASS__, 'clear_all' ) );
        add_action( 'easyopt_purge_url',      array( __CLASS__, 'clear_url' ), 10, 2 );
        add_action( 'easyopt_purge_url_type', array( __CLASS__, 'clear_url_type' ) );

        // Always-on hooks (admin bar, clear actions, settings handlers).
        add_action( 'admin_bar_menu',                array( __CLASS__, 'admin_bar_menu' ), 999 );
        // Cache clear + stats are served by the REST dashboard
        // (/cache/clear, /dashboard-stats); the old admin-ajax twins were
        // unused by the React app and have been removed. The admin-post
        // handlers below stay — they back the non-AJAX admin-bar links.
        add_action( 'admin_post_easyopt_clear_cache', array( __CLASS__, 'handle_clear_cache' ) );
        add_action( 'admin_post_easyopt_clear_page_current', array( __CLASS__, 'handle_clear_page_current' ) );
        add_action( 'admin_notices',                 array( __CLASS__, 'admin_notices' ) );

        //
        // We previously relied on Apache matching `wordpress_logged_in_*` in
        // the cookie header, plus the drop-in's PHP detection of the same.
        // That is correct in principle but fragile across hosts: some
        // setups proxy in a way that strips or normalises cookies before
        // .htaccess can see them, and some non-Apache servers (nginx with
        // FastCGI cache, some LiteSpeed configs) don't run the rewrites at
        // all. This always-on bouncer issues a NON-AUTH cookie named
        // `easyopt_skip_cache=1` whenever the visitor is logged in. Both
        // the .htaccess rules and the drop-in check for it explicitly, so
        // even if `wordpress_logged_in_*` is missing or stripped, this one
        // signal keeps logged-in users out of the cache.
        add_action( 'init',         array( __CLASS__, 'maybe_set_logged_in_cookie' ), 1 );
        add_action( 'wp_login',     array( __CLASS__, 'set_logged_in_cookie' ), 10, 0 );
        add_action( 'wp_logout',    array( __CLASS__, 'clear_logged_in_cookie' ), 10, 0 );

        // Auto-clear hooks (content-changed events).
        //
        // (2.5.4 / perf #49) Registered ONLY when the page cache is enabled.
        // With cache off there are no files to purge — yet these ~15
        // listeners still ran per matching event (a bulk edit fires
        // save_post per row) just to no-op inside. Toggle transitions are
        // safe: enabling the cache saves settings via the coordinator, and
        // the next request registers the hooks; disabling it wipes all files
        // via the coordinator's clear, so no stale purge listener is needed.
        // switch_theme/customize clear_all stay UNCONDITIONAL — they also
        // reset learned data (used CSS beacon classes etc. via clear_all's
        // integrations) that must reset even if the cache toggle is off.
        add_action( 'switch_theme',             array( __CLASS__, 'clear_all' ) );
        add_action( 'customize_save_after',     array( __CLASS__, 'clear_all' ) );

        $easyopt_cache_on = (int) EasyOpt_Config::get( 'cache', 0 ) === 1;

        // (2.6.2) The auto-clear listeners below are the ONLY source of
        // easyopt_cache_cleared_url / easyopt_cache_cleared_all, and those two
        // actions are what EasyOpt_Hosting::purge_url()/purge_all() and
        // EasyOpt_Cloudflare listen on. Gating registration on OUR page cache
        // therefore meant that switching our cache off silently switched off
        // host and Cloudflare purging too: a user could publish a post and the
        // edge would keep serving the old copy until its own TTL expired, with
        // nothing logged to explain it. Register the listeners whenever there
        // is anything to purge — ours, the host's, or Cloudflare's.
        //
        // The 2.5.4 perf win is preserved: with our cache on this short-circuits
        // before any detection runs, and with no upstream cache present nothing
        // is registered at all, exactly as before.
        $easyopt_purge_hooks = $easyopt_cache_on || self::has_upstream_cache();
        if ( $easyopt_purge_hooks ) {
            // post_updated gives us BOTH the old and new post objects, so a slug
            // change can purge the OLD permalink too (save_post alone can't — by
            // the time it fires the slug is already the new one). save_post is
            // kept as a catch-all for fresh inserts created in a single call
            // (REST/WP-CLI), which never fire post_updated. Both funnel through
            // purge_for_post(), which is guarded per-request so a normal edit
            // purges exactly once.
            add_action( 'post_updated',             array( __CLASS__, 'on_post_updated' ), 20, 3 );
            add_action( 'save_post',                array( __CLASS__, 'on_save_post' ), 20, 1 );
            add_action( 'deleted_post',             array( __CLASS__, 'on_deleted_post' ), 10, 2 );
        }

        // (2.5.0 / H2) Daily counter self-heal. The atomic page counter can
        // drift from reality over time — a GC that unlinks stale page files
        // doesn't decrement it, and concurrent writes can double-count. The
        // dashboard only forced a full recount when the counter was missing
        // (=-1), so drift never corrected on an established install. Piggyback
        // the existing daily queue GC (no new schedule): drop the stats
        // transient and recompute once, which fires easyopt_cache_stats_computed
        // → EasyOpt_Cache_Counter::sync_from_stats() and realigns the counter
        // to the true on-disk file count.
        // (2.6.0) Force-variant: the daily heal must never be skipped by the
        // throttle that guards the opportunistic (done-flip) reconciles.
        add_action( 'easyopt_queue_gc', array( __CLASS__, 'reconcile_page_counter_daily' ) );

        // Comments: purge ONLY the affected post's page — never the whole
        // cache. New comments, status changes (approve/spam/trash) and edits
        // all touch a single post's HTML.
        if ( $easyopt_purge_hooks ) {
            add_action( 'comment_post',             array( __CLASS__, 'on_comment' ), 10, 2 );
            add_action( 'wp_set_comment_status',    array( __CLASS__, 'on_comment_status' ), 10, 2 );
            add_action( 'edit_comment',             array( __CLASS__, 'on_edit_comment' ), 10, 1 );
        }

        // Taxonomy term create/edit/delete — refresh ONLY the affected term
        // archive (plus its pagination, ancestor archives and the homepage).
        // Public taxonomies only; skipped during imports. This is a targeted
        // purge by design, not a full-site wipe.
        if ( $easyopt_purge_hooks ) {
            add_action( 'created_term',             array( __CLASS__, 'on_term_change' ), 10, 3 );
            add_action( 'edited_term',              array( __CLASS__, 'on_term_change' ), 10, 3 );
            add_action( 'delete_term',              array( __CLASS__, 'on_term_change' ), 10, 3 );
        }

        // Author profile change — refresh that author's archive (+ pagination).
        if ( $easyopt_purge_hooks ) {
            add_action( 'profile_update',       array( __CLASS__, 'on_profile_update' ), 10, 1 );
        }

        // Attachment (media) edit — refresh the attachment page and its parent
        // post, if any. Kept intentionally light (no archives) so bulk media
        // edits don't fan out into large purges.
        if ( $easyopt_purge_hooks ) {
            add_action( 'edit_attachment',      array( __CLASS__, 'on_edit_attachment' ), 10, 1 );
        }

        //
        // Background: 1.5.3 used a single `updated_option` listener that
        // cleared the entire cache for any option whose name started with
        // `easyopt_`, with a small allowlist of "internal" options to skip.
        // The allowlist was incomplete — every preload batch wrote
        // `easyopt_cache_preload_last_avg_ms`, which fired the listener,
        // wiped the cache the preloader had just built, and reset the queue.
        // The result was a self-perpetuating loop: preload → clear → preload.
        //
        // The fix is to invert the model. Instead of "clear unless
        // allowlisted", we now hook only the specific options that actually
        // change the rendered HTML or the cache key. Every other plugin-
        // internal write (counters, timestamps, queue state, last-avg-ms,
        // version markers) is a no-op for the cache.
        //
        // Two sets of hooks:
        //   - $html_affecting_options: when changed, full cache flush.
        //   - $dropin_options: when changed, re-export the drop-in config.
        // Most options are in both lists.
        // EasyOpt_Save_Coordinator listens to `easyopt_settings_saved`
        // and runs `clear_all`/`refresh_dropin` at most once per save,
        // diffed against the previous state. The lists below are now
        // consulted by the coordinator's diff logic (read by reference
        // via the public html_affecting_options() and
        // dropin_affecting_options() methods).

        // Role cookie (drop-in reads this to vary cache by user role).
        add_action( 'set_logged_in_cookie', array( __CLASS__, 'set_role_cookie' ), 10, 4 );
        add_action( 'clear_auth_cookie',    array( __CLASS__, 'clear_role_cookie' ) );

        // (2.6.1) RE-EXPORT WHEN THE PLUGIN SET CHANGES.
        //
        // The drop-in's config is a snapshot written only when an Easy
        // Optimizer setting is saved. Activating another plugin changes no
        // such setting, so the snapshot never learned about it — and the one
        // that matters is a cookie-driven multilingual plugin.
        //
        // On a site that was already caching, the sequence was: English-only
        // site has /about/index.html on disk → Polylang is installed in cookie
        // mode → the WRITER now knows to vary by pll_language, but the
        // snapshot still has include_cookies => [] → the drop-in keeps finding
        // and serving the old monolingual index.html to every visitor in every
        // language. cache_ttl defaults to 0 (never expire), so it stayed wrong
        // until someone happened to purge or save a setting.
        //
        // Purging as well as re-exporting is the point: re-exporting alone
        // fixes which file is looked for, but leaves the wrong-language file
        // sitting at the old path.
        add_action( 'activated_plugin',   array( __CLASS__, 'on_plugin_set_changed' ) );
        add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_set_changed' ) );

        // Multisite: keep the cached sibling-directory map (used by full
        // clears to protect other sites' cache files) in sync with the
        // network's site list. (2.3.3)
        if ( is_multisite() ) {
            add_action( 'wp_initialize_site',   array( __CLASS__, 'flush_network_sibling_cache' ) );
            add_action( 'wp_uninitialize_site', array( __CLASS__, 'flush_network_sibling_cache' ) );
            add_action( 'wp_update_site',       array( __CLASS__, 'flush_network_sibling_cache' ) );
        }

        // LCP integration — when a new LCP element is detected for a URL type,
        // invalidate any cached HTML for that URL type so the next visit
        // generates a page with the freshly-detected preload tag.
        add_action( 'easyopt_lcp_saved', array( __CLASS__, 'on_lcp_saved' ), 10, 3 );

        // Bail early if cache is disabled.
        if ( ! self::is_enabled() ) {
            return;
        }

        // (2.6.0) The runtime serve path is registered in easy-optimizer.php
        // at FILE SCOPE, priority -100000. Registering it from here — inside a
        // plugins_loaded callback at priority 1 — added it to a priority that
        // had already been passed, so it never ran. maybe_serve_cache() now
        // establishes its own preconditions instead of relying on this gate.
    }

    private static function setup_paths() {
        self::$root_path = trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt/';
        self::$root_url  = trailingslashit( content_url() ) . 'cache/easyopt/';
    }

    public static function is_enabled() {
        return (int) EasyOpt_Config::get( 'cache', 0 ) > 0;
    }

    /* ───────────────────────────────────────────────
     *  Public API — buffer capture (called from main plugin)
     * ─────────────────────────────────────────────── */

    /**
     * Write $data to $target atomically: write to a unique temp file in the
     * same directory, then rename() over the target. rename() is atomic on
     * POSIX filesystems, so a concurrent reader (Apache `-f` + serve, the PHP
     * drop-in, or readfile() here) can never observe a half-written file.
     *
     * @return bool True on success.
     */
    private static function atomic_write( $target, $data ) {
        $tmp = $target . '.tmp-' . easyopt_tmp_token() . '-' . wp_rand( 1000, 9999999 );
        if ( false === @file_put_contents( $tmp, $data, LOCK_EX ) ) {
            return false;
        }
        if ( ! @rename( $tmp, $target ) ) {
            @unlink( $tmp );
            return false;
        }
        return true;
    }

    /**
     * Final-stage filter on the master buffer. Writes the HTML to disk and
     * returns it unchanged so the live request still serves the same bytes.
     * Safe to call when caching is disabled — bails internally.
     */
    public static function capture_buffer( $html ) {

        if ( ! self::is_enabled() ) {
            return $html;
        }

        // Never WRITE cache from a HEAD request — some stacks suppress the
        // body for HEAD, which would persist a truncated/empty page. HEAD is
        // allowed to be SERVED from cache (headers only); writes are GET-only.
        if ( ! isset( $_SERVER['REQUEST_METHOD'] )
             || 'GET' !== strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
            return $html;
        }

        if ( ! self::is_request_cacheable() ) {
            self::maybe_report_uncacheable( 'request not cacheable' );
            return $html;
        }

        if ( ! self::is_html_cacheable( $html ) ) {
            // Only log the SURPRISING case: a normal 200 page that still wasn't
            // cacheable — almost always a truncated document caused by another
            // component flushing the output buffer mid-render. Redirects and
            // 404s are expected here and stay silent to keep the log clean.
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                $code  = function_exists( 'http_response_code' ) ? (int) http_response_code() : 0;
                $is404 = function_exists( 'is_404' ) && is_404();
                if ( 200 === $code && ! $is404 ) {
                    EasyOpt_Debug_Log::info( 'cache', sprintf(
                        'Skip write: 200 response not cacheable (len=%d, has_html=%s, has_body_close=%s) — usually a truncated page from another component flushing the output buffer.',
                        is_string( $html ) ? strlen( $html ) : -1,
                        ( is_string( $html ) && false !== stripos( $html, '<html' ) ) ? 'yes' : 'no',
                        ( is_string( $html ) && false !== stripos( $html, '</body>' ) ) ? 'yes' : 'no'
                    ) );
                }
            }
            // (2.5.0 / H3) Tell the preloader WHY this render didn't cache,
            // using the reason is_html_cacheable() just recorded.
            self::maybe_report_uncacheable( self::$veto_reason !== '' ? self::$veto_reason : 'html not cacheable' );
            return $html;
        }

        $path = self::get_cache_file_path();
        if ( '' === $path ) {
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::info( 'cache', 'Skip write: could not resolve a cache file path for this request.' );
            }
            return $html;
        }

        // Don't let the live MISS response itself end up in a CDN edge cache —
        // the optimised HTML we're about to write is what should serve to
        // future visitors via the cache HIT path (which sends the long
        // CDN-Cache-Control). Without this header a CDN could store the very
        // first build of an uncached page (often half-built when integrations
        // run) and then keep serving it for 30 days.
        if ( ! headers_sent() ) {
            header( 'Cache-Control: no-store, s-maxage=0' );
            header( 'X-Easy-Optimizer-Cache: MISS' );
        }

        $dir = dirname( $path );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        if ( ! is_dir( $dir ) ) {
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::error( 'cache', 'Cache write failed: could not create directory ' . $dir . ' (check filesystem permissions).' );
            }
            return $html; // can't write — abort.
        }

        // Append a tiny HTML comment for debugging / visibility.
        $marker = sprintf(
            "\n<!-- Cached by Easy Optimizer %s on %s (%s) -->",
            EASYOPT_VERSION,
            gmdate( 'Y-m-d H:i:s' ),
            self::is_mobile() ? 'mobile' : 'desktop'
        );

        $contents = $html . $marker;

        // (2.5.0 / H2) Recompute existence immediately before the write to
        // shrink the TOCTOU window: the value computed here — right before
        // atomic_write — decides whether this is a NEW file for the counter
        // and stat-invalidation logic below. Computing it far above (before
        // gzencode) let two concurrent MISSes both see "new" and both
        // increment the page counter for one file.
        $wrote_new = ! file_exists( $path . '_gzip' ) && ! file_exists( $path );

        // inode count. Virtually all browsers accept gzip; the rare non-gzip
        // client gets a PHP-side gzdecode in the serve path.
        // (2.5.4 / perf #17) Level 6 → 4 (filterable): ~2-3% larger files for
        // roughly 40% less compression CPU on every MISS. The file is
        // compressed once and served thousands of times, but the compress
        // happens inside a visitor's uncached render — the latency trade
        // favours the visitor. `add_filter('easyopt_cache_gzip_level', fn()
        // => 6)` restores the old ratio for disk-constrained hosts.
        // (2.5.7) Honour the Gzip setting for GENERATION, not just for the
        // .htaccess mod_deflate block. This previously ran unconditionally, so
        // turning Gzip off still produced, stored and served a _gzip copy —
        // which made "disable Gzip" useless as a diagnostic step on stacks
        // where the compressed file is the problem, cost a gzencode() on every
        // MISS the user had opted out of, and doubled cache disk usage.
        // OpenLiteSpeed is excluded outright: it compresses natively and
        // ignores the Apache-only no-gzip guard, so pre-compressed bytes risk
        // double compression the browser cannot decode.
        $easyopt_want_gz = (int) EasyOpt_Config::get( 'cache_gzip', 1 )
            && ! ( method_exists( __CLASS__, 'is_openlitespeed' ) && self::is_openlitespeed() );

        $gz_data = false;
        if ( $easyopt_want_gz && function_exists( 'gzencode' ) ) {
            $easyopt_gz_level = (int) apply_filters( 'easyopt_cache_gzip_level', 4 );
            $easyopt_gz_level = max( 1, min( 9, $easyopt_gz_level ) );
            $gz_data          = gzencode( $contents, $easyopt_gz_level );
        }

        // (2.5.5) The PLAIN .html is now always written, with the gzip copy
        // as an optional companion — previously only the gzip file existed
        // and the plain file was a gzencode-missing fallback. Consequences of
        // the old behaviour:
        //   • OpenLiteSpeed had nothing renderable to rewrite to (it ignores
        //     the AddType/AddEncoding directives), so visitors downloaded the
        //     page instead of viewing it.
        //   • Clients that don't advertise gzip forced a PHP-side gzdecode of
        //     the whole document on every hit.
        // WP Rocket writes both files unconditionally for the same reasons.
        // Hosts that are disk-constrained and know their stack serves gzip
        // can opt out with:
        //   add_filter( 'easyopt_cache_write_plain', '__return_false' );
        // (ignored when gzencode is unavailable — something must be written).
        $write_plain = (bool) apply_filters( 'easyopt_cache_write_plain', true );
        if ( false === $gz_data ) {
            $write_plain = true;
        }

        if ( $write_plain && ! self::atomic_write( $path, $contents ) ) {
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::error( 'cache', 'Cache write failed (plain HTML): ' . $path );
            }
            return $html;
        }
        if ( false !== $gz_data && ! self::atomic_write( $path . '_gzip', $gz_data ) ) {
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::error( 'cache', 'Cache write failed (gzip): ' . $path . '_gzip' );
            }
            // The plain file above is already on disk and is fully servable,
            // so this is a soft failure — don't discard a working cache entry.
            if ( ! $write_plain ) {
                return $html;
            }
        }
        // admin UI polls notice the new cached file within ~1 second.
        // bump_state_version self-coalesces (200ms throttle), so even
        // a burst of cache writes from concurrent visitors doesn't
        // flood the options table.
        if ( $wrote_new && class_exists( 'EasyOpt_Cache_Preload' )
             && method_exists( 'EasyOpt_Cache_Preload', 'bump_state_version' ) ) {
            EasyOpt_Cache_Preload::bump_state_version();
        }
        // removed. It was an autoloaded option write on every cache MISS,
        // and dashboard stats already use a 60-second transient backed by
        // a real directory scan. The counter was strictly overhead.
        // ↑ RE-ENABLED in 2.1.0-fix: The counter gives the dashboard
        // instant O(1) page counts instead of stale 60s transient data.
        // Atomic SQL increment — one lightweight UPDATE per MISS.
        // (2.6.0) Counted per URL, not per variant file — see
        // is_first_variant_for_url(). The old `! self::is_mobile()` gate was
        // wrong in BOTH directions: a URL first cached on mobile never counted
        // at all, while every non-mobile variant (index-logged-in-<role>.html,
        // index-<cookie-tag>.html) counted separately even though get_stats()
        // counts their shared directory once.
        if ( $wrote_new && self::is_first_variant_for_url( $path ) ) {
            do_action( 'easyopt_page_cached' );
        }
        if ( $wrote_new ) {
            // Preload-on-MISS: when desktop is cached, queue a probe for
            // the mobile variant (and vice versa) so the OTHER device's
            // first visitor isn't the one paying for generation. The
            // preloader is the worker — we just push the URL.
            if ( class_exists( 'EasyOpt_Cache_Preload' )
                 && (int) EasyOpt_Config::get( 'cache_separate_mobile', 1 ) ) {
                $current_url = self::current_request_url();
                if ( '' !== $current_url ) {
                    EasyOpt_Cache_Preload::enqueue_companion( $current_url, self::is_mobile() );
                }
            }
        }
        // Only invalidate stats transients when we actually wrote a new file.
        if ( $wrote_new ) {
            delete_transient( 'easyopt_cache_stats' );
            delete_transient( 'easyopt_waiting_count' );
        }

        // (2.5.0 / C3) Ground-truth completion: a cache file for this URL now
        // exists on disk. Confirm it in the preload results ledger (no-op for
        // untracked URLs / when preload is off). This is what makes "done"
        // mean CONFIRMED CACHED — the live buffer is the write, so it is also
        // the confirmation, exactly where the bytes land.
        if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
            $current_url = self::current_request_url();
            if ( '' !== $current_url ) {
                EasyOpt_Preload_Results::confirm( $current_url, self::is_mobile() );
            }
        }

        return $html;
    }

    /**
     * (2.5.0 / H3) When a PRELOAD render is not cacheable, record why in the
     * results ledger so the dashboard can explain the coverage gap instead of
     * the URL silently never appearing. Redirects (3xx) are recorded as
     * 'redirected' with the Location; everything else as 'uncacheable'.
     * No-op outside preload requests and when the ledger is unavailable.
     *
     * @param string $reason Human-readable veto reason.
     */
    private static function maybe_report_uncacheable( $reason ) {
        if ( ! defined( 'EASYOPT_IS_PRELOAD_REQUEST' ) || ! EASYOPT_IS_PRELOAD_REQUEST ) {
            return;
        }
        if ( ! class_exists( 'EasyOpt_Preload_Results' ) ) {
            return;
        }
        $url = self::current_request_url();
        if ( '' === $url ) {
            return;
        }
        $code = function_exists( 'http_response_code' ) ? (int) http_response_code() : 0;

        if ( $code >= 300 && $code < 400 ) {
            $location = '';
            foreach ( headers_list() as $h ) {
                if ( 0 === stripos( $h, 'location:' ) ) {
                    $location = trim( substr( $h, 9 ) );
                    break;
                }
            }
            EasyOpt_Preload_Results::mark_redirected( $url, $location, $code );
            return;
        }
        // (2.5.0 / B2) Only STRUCTURAL vetoes are terminal for the run.
        // Transient causes — a WAF/CDN-injected cookie matching an exclusion
        // pattern, a truncated render, a temporary filter veto — are recorded
        // as 'failed' so the 12h revert pass retries them, instead of
        // permanently excluding the URL from coverage until the next run.
        $terminal = array(
            'DONOTCACHEPAGE',
            'password-protected post',
            'is_search',
            'is_feed',
            'is_preview',
            'is_404',
            // (2.5.5) A structural exclusion (sitemap/xml/feed/excluded URL)
            // can never succeed on retry — before this, a queued sitemap was
            // marked 'failed (retryable)' and re-warned every 12h forever.
            'request not cacheable',
        );
        $is_terminal = in_array( $reason, $terminal, true ) || 0 === strpos( $reason, 'HTTP ' );
        if ( $is_terminal ) {
            EasyOpt_Preload_Results::mark_uncacheable( $url, $reason, $code > 0 ? $code : 200 );
        } else {
            EasyOpt_Preload_Results::mark_failed( $url, 'render veto (retryable): ' . $reason, $code );
        }
        if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
            EasyOpt_Debug_Log::warn( 'preload', sprintf(
                'Preload render not cacheable: %s (%s).',
                $url,
                $reason
            ) );
        }
    }

    /**
     * Best-effort current-request absolute URL. Used by preload-on-MISS and
     * (2.6.0) by EasyOpt_Unused_CSS when it hands generation to the queue.
     */
    public static function current_request_url() {
        if ( ! isset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] ) ) {
            return '';
        }
        $scheme = is_ssl() ? 'https' : 'http';
        return $scheme . '://' . (string) wp_unslash( $_SERVER['HTTP_HOST'] ) . (string) wp_unslash( $_SERVER['REQUEST_URI'] );
    }

    /**
     * (2.6.0) Is the file just written the FIRST cached variant for its URL?
     *
     * "Pages cached" is a URL count. get_stats() produces it by collecting
     * distinct DIRECTORIES ($page_dirs), because every variant of one URL —
     * desktop, mobile, each logged-in role tag, each include-cookie value —
     * lives in the same directory. The O(1) counter has to increment on the
     * same unit or the two can never agree.
     *
     * Rules, both chosen so this costs at most two file_exists() calls:
     *
     *  - Only the two ANONYMOUS variants (index.html / index-mobile.html)
     *    participate. Role- and cookie-keyed files are extra variants of a URL
     *    that already exists as far as the count is concerned, so they never
     *    increment. This is a pure string comparison — free — and it removes
     *    the over-count that hit every site running cache_logged_in or a
     *    currency/language switcher.
     *  - Between those two, the first one written wins. That fixes the
     *    mobile-first case, where the URL previously never counted at all.
     *
     * Cost: reached only when $wrote_new is true, i.e. at most once per
     * variant per URL per cache lifetime. Every cache HIT — the overwhelming
     * majority of requests — is served and returns long before this point, so
     * there is no added cost on the hot path.
     *
     * Known, accepted imprecision: two concurrent MISSes for the desktop and
     * mobile variants of the same fresh URL can both observe the other absent
     * and both increment. capture_buffer() documents the same TOCTOU class at
     * its $wrote_new recompute; the end-of-run and daily reconciles absorb it.
     * A lock is not worth it for a display counter.
     *
     * @param string $path Absolute path of the cache file just written.
     * @return bool
     */
    private static function is_first_variant_for_url( $path ) {
        $name = basename( $path );
        if ( 'index.html' !== $name && 'index-mobile.html' !== $name ) {
            return false; // role / include-cookie variant — already counted.
        }
        $other = dirname( $path ) . '/'
            . ( 'index-mobile.html' === $name ? 'index.html' : 'index-mobile.html' );

        return ! file_exists( $other ) && ! file_exists( $other . '_gzip' );
    }

    /**
     * (2.5.0 / H2) Daily page-counter self-heal. Recomputes cache stats once
     * (dropping the 60s transient first) so get_stats() fires the
     * easyopt_cache_stats_computed action, which realigns the atomic counter
     * to the true on-disk file count. Hooked to the existing daily queue GC.
     *
     * (2.6.0) Throttled. This runs a RecursiveIteratorIterator over the whole
     * cache tree, and since 2.5.0 it is also called from maybe_mark_done() on
     * the preload done-flip. That flip is reachable from ordinary browsing:
     * preload-on-MISS calls enqueue_companion(), which sets status to
     * 'running' and starts the queue whenever preload is idle/done, so a burst
     * of visits to uncached pages could drive a full filesystem scan per
     * drain. The throttle collapses a burst to one scan; the daily GC passes
     * $force so its once-a-day heal can never be swallowed by a recent
     * opportunistic reconcile.
     *
     * @param bool $force Skip the throttle (daily GC only).
     */
    public static function reconcile_page_counter( $force = false ) {
        if ( ! self::is_enabled() ) {
            return;
        }
        $window = (int) apply_filters( 'easyopt_reconcile_pages_throttle', 15 * MINUTE_IN_SECONDS );
        if ( ! $force && $window > 0 ) {
            if ( get_transient( 'easyopt_reconcile_pages_lock' ) ) {
                return;
            }
            set_transient( 'easyopt_reconcile_pages_lock', 1, $window );
        }
        delete_transient( 'easyopt_cache_stats' );
        if ( method_exists( __CLASS__, 'get_stats' ) ) {
            self::get_stats();
        }
    }

    /**
     * (2.6.0) Daily-GC entry point for the page-counter reconcile. Exists only
     * so the once-a-day heal bypasses the throttle above — an action callback
     * cannot pass its own arguments. reconcile_page_counter() stays public and
     * unchanged for every other caller.
     */
    public static function reconcile_page_counter_daily() {
        self::reconcile_page_counter( true );
    }

    /* ───────────────────────────────────────────────
     *  Cache serving
     * ─────────────────────────────────────────────── */

    public static function maybe_serve_cache() {

        // (2.6.0) Runs at plugins_loaded:-100000, BEFORE init(). Every
        // precondition is established locally — init() no longer gates the
        // registration, because gating it there meant it never ran at all.
        if ( defined( 'EASYOPT_DISABLE_RUNTIME_SERVE' ) && EASYOPT_DISABLE_RUNTIME_SERVE ) {
            return;
        }
        if ( ! self::is_enabled() ) {
            return;
        }
        // Drop-in active? It already answered this request from cache and
        // exited, or returned false for a genuine MISS. Either way there is
        // nothing for the runtime path to do, and re-checking would be pure
        // duplicated work on every request.
        if ( self::is_dropin_active() ) {
            return;
        }
        // Two string assignments, idempotent — call unconditionally rather
        // than depending on init() having run first.
        self::setup_paths();

        if ( ! self::is_request_cacheable() ) {
            return;
        }

        $path = self::get_cache_file_path();
        if ( '' === $path ) {
            return;
        }

        $gz_path = $path . '_gzip';
        // (2.5.7) Honour the Gzip toggle on the serve path, and never serve
        // pre-compressed bytes on OpenLiteSpeed — it compresses natively and
        // ignores the Apache-only no-gzip guard, so the client would receive
        // gzip inside gzip. A stale _gzip file from an older build is now
        // ignored rather than preferred.
        $want_gz = (int) EasyOpt_Config::get( 'cache_gzip', 1 )
                   && ! ( method_exists( __CLASS__, 'is_openlitespeed' ) && self::is_openlitespeed() );
        if ( $want_gz && file_exists( $gz_path ) ) {
            $serve_gz   = true;
            $check_path = $gz_path;
        } elseif ( file_exists( $path ) ) {
            $serve_gz   = false;
            $check_path = $path;
        } else {
            return;
        }

        // TTL check — controls how long the file lives on the server.
        $ttl = (int) apply_filters( 'easyopt_cache_ttl', (int) EasyOpt_Config::get( 'cache_ttl', 0 ) );
        if ( $ttl > 0 ) {
            $age = time() - (int) filemtime( $check_path );
            if ( $age > $ttl ) {
                @unlink( $path );
                @unlink( $gz_path );
                // Keep the O(1) page counter in sync with this lazy expiry so
                // it doesn't drift (the page is about to regenerate and
                // re-increment).
                //
                // (2.6.0) Decrement only when the URL has NO variant left on
                // disk. The unlinks above remove this request's variant only,
                // so the old `! self::is_mobile()` gate subtracted a whole URL
                // while, typically, the mobile file was still sitting there —
                // get_stats() kept counting the directory and the counter
                // under-reported until the next reconcile. Two file_exists()
                // on an already-rare path (a cached page that has outlived its
                // TTL), and only for sites serving through PHP: with the
                // advanced-cache.php drop-in installed maybe_serve_cache()
                // returns at the is_dropin_active() check well above this.
                if ( class_exists( 'EasyOpt_Cache_Counter' ) ) {
                    $easyopt_left = (array) glob( dirname( $path ) . '/index*.html*' );
                    if ( empty( $easyopt_left ) ) {
                        EasyOpt_Cache_Counter::decrement();
                    }
                }
                return;
            }
        }

        // Can the client accept gzip?
        $accept_gz = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] )
            && false !== stripos( (string) wp_unslash( $_SERVER['HTTP_ACCEPT_ENCODING'] ), 'gzip' );

        $mtime          = (int) filemtime( $check_path );
        $last_modified  = gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT';

        $cdn_max_age = (int) apply_filters( 'easyopt_cache_cdn_max_age', self::DEFAULT_CDN_MAX_AGE );
        $host        = isset( $_SERVER['HTTP_HOST'] )
            ? strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', (string) wp_unslash( $_SERVER['HTTP_HOST'] ) ) )
            : '';

        // (2.5.7) Declare what this response actually varies on. The file
        // served is one of several variants selected by device, role cookie
        // and include-cookie value; declaring only Accept-Encoding while
        // advertising a 30-day CDN lifetime let shared caches serve one
        // variant to everyone. Mirrors assets/advanced-cache.php exactly.
        $rc_vary       = self::request_cache();
        $is_role_keyed = ( $rc_vary['cache_logged_in'] && '' !== self::current_user_role_tag() );
        $extra_tag     = self::include_cookie_tag();
        $edge_safe     = ( ! $is_role_keyed && '' === $extra_tag );

        $vary = array( 'Accept-Encoding' );
        if ( ! empty( $rc_vary['separate_mobile'] ) ) {
            $vary[] = 'User-Agent';
        }
        if ( ! $edge_safe ) {
            $vary[] = 'Cookie';
        }
        $vary_header = implode( ', ', $vary );

        // (2.6.0) Record the first time the runtime serve path actually
        // serves. This path was dead in production before 2.6.0, so a
        // support agent needs a positive signal that it is now live on a
        // drop-in-less host — and, if a site reports trouble, evidence of
        // exactly when it started. One option write per install, never again.
        if ( ! get_option( 'easyopt_runtime_serve_first' ) ) {
            update_option( 'easyopt_runtime_serve_first', time(), false );
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::warn( 'cache', '[info] Runtime serve path served its first cache HIT (no drop-in on this host). Disable with define( \'EASYOPT_DISABLE_RUNTIME_SERVE\', true ).' );
            }
        }

        // 304 Not Modified.
        if ( isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
            $client_ts = strtotime( (string) wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) );
            if ( $client_ts && $client_ts >= $mtime ) {
                if ( ! headers_sent() ) {
                    header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? (string) wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) : 'HTTP/1.1' ) . ' 304 Not Modified' );
                    header( 'X-Easy-Optimizer-Cache: HIT-304' );
                    header( 'X-Easy-Optimizer-Source: php' );
                    header( 'Cache-Control: no-cache, must-revalidate' );
                    if ( $edge_safe ) {
                        header( 'CDN-Cache-Control: max-age=' . $cdn_max_age );
                        if ( '' !== $host ) {
                            header( 'Cache-Tag: ' . $host );
                        }
                    } else {
                        header( 'CDN-Cache-Control: no-store' );
                        header( 'Cache-Control: private, no-cache, must-revalidate' );
                    }
                    header( 'Vary: ' . $vary_header );
                    header( 'Last-Modified: ' . $last_modified );
                }
                exit;
            }
        }

        // Send headers.
        if ( ! headers_sent() ) {
            if ( $serve_gz && $accept_gz ) {
                if ( function_exists( 'ini_set' ) ) { @ini_set( 'zlib.output_compression', '0' ); }
            }
            header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
            header( 'X-Easy-Optimizer-Cache: HIT' );
            header( 'X-Easy-Optimizer-Source: php' );
            header( 'Cache-Control: no-cache, must-revalidate' );
            if ( $edge_safe ) {
                header( 'CDN-Cache-Control: max-age=' . $cdn_max_age );
                if ( '' !== $host ) {
                    header( 'Cache-Tag: ' . $host );
                }
            } else {
                header( 'CDN-Cache-Control: no-store' );
                header( 'Cache-Control: private, no-cache, must-revalidate' );
            }
            header( 'Vary: ' . $vary_header );
            header( 'Last-Modified: ' . $last_modified );
            if ( $serve_gz && $accept_gz ) {
                header( 'Content-Encoding: gzip' );
            }
        }

        // HEAD must return headers only, never a body (RFC 7231 §4.3.2).
        // Mirrors the identical guard in the drop-in.
        if ( isset( $_SERVER['REQUEST_METHOD'] )
             && 'HEAD' === strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
            exit;
        }

        if ( $serve_gz && $accept_gz ) {
            readfile( $gz_path );
        } elseif ( $serve_gz && ! $accept_gz ) {
            // Rare: gzip-only file but client doesn't accept gzip — decompress.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            echo gzdecode( (string) file_get_contents( $gz_path ) );
        } else {
            readfile( $path );
        }
        exit;
    }

    /* ───────────────────────────────────────────────
     *  Cacheability checks
     * ─────────────────────────────────────────────── */

    /** @var array|null Per-request memoised settings. Filled lazily. */
    private static $req_cache = null;

    public static function is_request_cacheable() {

        // GET and HEAD are servable from cache (HEAD gets headers only —
        // see maybe_serve_cache). Anything else is never cacheable. The
        // drop-in already accepted HEAD; before 2.3.3 the PHP mode required
        // GET strictly, so uptime monitors booted full WP on every check.
        $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
        if ( 'GET' !== $method && 'HEAD' !== $method ) {
            return false;
        }

        // Early-reject static asset URIs BEFORE touching options or
        // cookies. When some custom rewrite or 404 handler sends a request
        // for a CSS/JS/font/image to PHP, we can bail in microseconds
        // instead of running the full cacheability gauntlet.
        $uri_raw = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        if ( '' !== $uri_raw ) {
            $q = strpos( $uri_raw, '?' );
            $path_only = false === $q ? $uri_raw : substr( $uri_raw, 0, $q );
            // Match the last 6 chars for an extension cheaply — covers
            // .css .js .map .png .jpg .gif .svg .ico .woff .woff2 .ttf .eot
            // .otf .json .xml .pdf .mp4 .webm .webp .avif .txt .zip .gz
            $tail = substr( $path_only, -6 );
            if ( false !== strpos( $tail, '.' )
                 && preg_match( '/\.(css|js|map|png|jpe?g|gif|svg|ico|woff2?|ttf|eot|otf|json|xml|pdf|mp4|webm|webp|avif|txt|zip|gz|mp3|wav|ogg|webp|bmp|tiff?)$/i', $path_only ) ) {
                return false;
            }
        }

        // Skip wp-admin / wp-login / wp-cron / xmlrpc (handled by REQUEST_URI check below too, but bail fast).
        if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
            return false;
        }
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            return false;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return false;
        }

        // Logged-in users — only cached when the explicit "cache logged-in
        // users" option is on AND we have a role tag to vary by. Otherwise
        // bail (private content, nonces, role-specific menus). Two-pronged
        // detection: cookie inspection runs at every hook depth and catches
        // custom auth cookie names; is_user_logged_in() picks up edge cases.
        $logged_in = self::has_auth_cookie();
        if ( ! $logged_in && function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
            $logged_in = true;
        }
        if ( $logged_in ) {
            $rc = self::request_cache();
            if ( ! $rc['cache_logged_in'] ) {
                return false;
            }
            // Need the role tag — without it we can't safely key the cache.
            if ( '' === self::current_user_role_tag() ) {
                return false;
            }
        }

        $uri = $uri_raw;

        // Shared exclusion logic (built-ins, suffix-matched .xml/.txt and
        // user patterns) — same function the drop-in runs, so the two serve
        // paths can never drift apart again.
        if ( \EasyOpt\Cache\is_excluded_uri( $uri, self::get_url_exclusions() ) ) {
            return false;
        }

        // Query strings — strip well-known tracking params (utm_*, fbclid,
        // gclid, ...) before deciding whether the request carries state.
        // If anything meaningful remains, bail; nonces, search results and
        // dynamic filters need fresh HTML. Tracking-only URLs are safe to
        // serve from the same cache file as the bare URL, which dramatically
        // improves campaign-link cache-hit rates.
        if ( '' !== self::effective_query_string() ) {
            return false;
        }

        // Cookie exclusions — anyone with these is treated as having state.
        if ( self::has_excluding_cookie() ) {
            return false;
        }

        // (2.5.0 / CB-4 + C2) Password-protected posts always render live. The
        // cookie exclusion above only catches visitors who have ALREADY unlocked
        // (they carry wp-postpass_); this also catches the first, cookieless
        // visit so the prompt form is never cached. Guarded so it fires ONLY for
        // a genuine singular password-protected post: calling
        // post_password_required() with no argument uses the global $post, which
        // on an archive/home whose loop happens to END on a protected post would
        // wrongly report the whole listing as uncacheable. On the early serve
        // hook parse_query hasn't run yet, so this is a safe no-op there; the
        // authoritative gate is the write-time check in is_html_cacheable().
        // (2.5.0 / B1) Test the QUERIED object, never the global $post. A
        // no-arg post_password_required() reads the global, which any
        // secondary loop (Related Posts widget, Query Loop block, builder
        // posts widget) that ends on a protected post WITHOUT
        // wp_reset_postdata() leaves polluted — vetoing an unrelated,
        // perfectly cacheable page. get_queried_object() is main-query
        // truth and immune to loop pollution.
        if ( function_exists( 'post_password_required' )
             && function_exists( 'is_singular' )
             && did_action( 'parse_query' )
             && is_singular() ) {
            $easyopt_queried = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
            if ( $easyopt_queried instanceof WP_Post && post_password_required( $easyopt_queried ) ) {
                return false;
            }
        }

        return apply_filters( 'easyopt_cache_request_cacheable', true );
    }

    /**
     * 1.5.1: Per-request memoised settings.
     *
     * is_request_cacheable() runs twice on a typical page load (once before
     * serve, once during capture_buffer). Memoising trims repeated option
     * lookups, get_strip_params parsing, and exclusion-list parsing to
     * one fill per request.
     */
    private static function request_cache() {
        if ( null !== self::$req_cache ) {
            return self::$req_cache;
        }
        self::$req_cache = array(
            'cache_logged_in' => (int) EasyOpt_Config::get( 'cache_logged_in', 0 ),
            'separate_mobile' => (int) EasyOpt_Config::get( 'cache_separate_mobile', 1 ),
        );
        return self::$req_cache;
    }

    /**
     * Check the response HTML for signals that say "don't cache me" — such
     * as WooCommerce's `wp-pay-now` flow, Easy Digital Downloads, or any
     * page that emitted a no-cache notice.
     */
    private static function is_html_cacheable( $html ) {

        // (2.5.0 / M6) Order checks cheapest-first and record WHY a page was
        // vetoed (H3) so the preloader can surface it. Length and the HTTP
        // status code are the cheapest signals; the substring scans for
        // <html>/</body> come next; the WordPress conditional gates last.
        self::$veto_reason = '';

        if ( ! is_string( $html ) || strlen( $html ) < 256 ) {
            self::$veto_reason = 'response too short (<256 bytes)';
            return false;
        }

        // 4xx/5xx responses (heuristic) — checked before the substring
        // scans because an error page may still contain <html>/</body>.
        if ( function_exists( 'http_response_code' ) ) {
            $code = (int) http_response_code();
            if ( $code >= 400 ) {
                self::$veto_reason = 'HTTP ' . $code;
                return false;
            }
        }
        if ( function_exists( 'is_404' ) && is_404() ) {
            self::$veto_reason = 'is_404';
            return false;
        }

        if ( stripos( $html, '<html' ) === false || stripos( $html, '</body>' ) === false ) {
            self::$veto_reason = 'not a complete HTML document';
            return false;
        }

        // (2.5.0 / CB-3) Honour the standard WordPress opt-out constant. Plugins
        // (WooCommerce endpoints, EDD, membership / paywall, per-request dynamic
        // pages) set define('DONOTCACHEPAGE', true) to mean "never cache this
        // response". The live capture path previously ignored the constant and
        // only string-matched it in the preload path, so such pages were cached
        // and served statically to everyone.
        if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
            self::$veto_reason = 'DONOTCACHEPAGE';
            return false;
        }

        // (2.5.5) Never cache a page carrying queued WooCommerce notices.
        //
        // Notices ("Product added to your cart", coupon errors) live in the
        // customer's session and are printed server-side into the HTML. With
        // non-AJAX add-to-cart — still the default on single product pages —
        // Woo redirects to a clean URL that renders the notice, and that URL
        // IS cacheable. Without this guard the notice would be baked into the
        // cache file and shown to every subsequent visitor.
        //
        // This is what makes it safe to have dropped wp_woocommerce_session_
        // from the cookie exclusions above: instead of refusing to cache
        // ANYTHING for a shopper with a session, we refuse to cache only the
        // handful of responses that actually contain session-specific output.
        // Checked at write time only — serving is unaffected.
        if ( function_exists( 'wc_notice_count' ) && wc_notice_count() > 0 ) {
            self::$veto_reason = 'woocommerce notices queued';
            return false;
        }

        // (2.5.0 / CB-4 + C2) Never cache the password-prompt form.
        // (2.5.0 / B1) Guarded to the QUERIED object: this runs at shutdown,
        // after the full render, when the global $post is whatever the LAST
        // loop left behind — a Related Posts / Query Loop that ends on a
        // protected post without wp_reset_postdata() would otherwise veto an
        // unrelated page forever. get_queried_object() reflects the main
        // query only.
        if ( function_exists( 'post_password_required' )
             && function_exists( 'is_singular' )
             && is_singular() ) {
            $queried = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
            if ( $queried instanceof WP_Post && post_password_required( $queried ) ) {
                self::$veto_reason = 'password-protected post';
                return false;
            }
        }

        // (2.5.0) Defence-in-depth for request types that must always render
        // live. Feeds already fail the <html>/</body> test above; is_search()
        // additionally covers pretty search permalinks (/search/term/) that
        // carry no query string and would otherwise slip past the query-string
        // gate in is_request_cacheable().
        if ( function_exists( 'is_preview' ) && is_preview() ) {
            self::$veto_reason = 'is_preview';
            return false;
        }
        if ( function_exists( 'is_search' ) && is_search() ) {
            self::$veto_reason = 'is_search';
            return false;
        }
        if ( function_exists( 'is_feed' ) && is_feed() ) {
            self::$veto_reason = 'is_feed';
            return false;
        }

        // Allow third parties to veto.
        $ok = (bool) apply_filters( 'easyopt_cache_html_cacheable', true, $html );
        if ( ! $ok ) {
            self::$veto_reason = 'vetoed by easyopt_cache_html_cacheable filter';
        }
        return $ok;
    }

    /** (2.5.0 / H3) Reason the last is_html_cacheable() call vetoed, for
     *  preload telemetry. Empty when the page was cacheable. */
    private static $veto_reason = '';

    private static function has_excluding_cookie() {

        if ( empty( $_COOKIE ) ) {
            return false;
        }

        // (2.5.0 / H6) During a preload loopback, skip the synthetic
        // wordpress_logged_in_1 cookie the preloader injects to bypass
        // SERVER-level caches (Varnish/LiteSpeed/nginx FastCGI). That cookie
        // matches the default 'wordpress_logged_in_' exclusion, which made
        // is_request_cacheable() FALSE on every preload render — so the live
        // buffer never wrote a file and fire-and-forget warms were silent
        // no-ops. is_user_logged_in() (checked in should_cache) remains the
        // cryptographic backstop, exactly as has_auth_cookie() already does,
        // so a genuinely logged-in user appending ?eopreload=1 still can't
        // poison the cache.
        $is_preload = defined( 'EASYOPT_IS_PRELOAD_REQUEST' ) && EASYOPT_IS_PRELOAD_REQUEST;
        // (2.5.3) When logged-in caching is ON, the auth cookie is the KEY
        // (role-varied filenames), not an exclusion — leaving it in this list
        // meant the writer never produced a single logged-in cache file.
        // Cart/session/postpass cookies still exclude (real per-user state).
        $li_cache_on = ( 1 === (int) self::request_cache()['cache_logged_in'] );

        $patterns = self::get_cookie_exclusions();
        foreach ( array_keys( $_COOKIE ) as $name ) {
            $name = (string) $name;
            if ( ( $is_preload || $li_cache_on ) && 0 === strpos( $name, 'wordpress_logged_in_' ) ) {
                continue;
            }
            foreach ( $patterns as $needle ) {
                if ( '' === $needle ) {
                    continue;
                }
                if ( false !== stripos( $name, $needle ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Detect a logged-in / commenter / postpass session by inspecting cookies
     * directly. Modeled on WP Super Cache's wpsc_get_auth_cookies(): works at
     * any hook depth, handles custom cookie hashes, and catches WP's
     * AUTH_COOKIE / SECURE_AUTH_COOKIE / LOGGED_IN_COOKIE constants when
     * defined in wp-config.php (e.g. multi-network installs).
     *
     * Used in addition to is_user_logged_in() so we still bail out even if
     * the user object hasn't been resolved yet on this request.
     *
     * @return bool true when the visitor is identified as logged in / has state.
     */
    private static function has_auth_cookie() {

        // (Preload) The preloader injects a synthetic wordpress_logged_in_1
        // cookie ONLY to make server-level caches (wpx, Varnish, LiteSpeed,
        // nginx FastCGI…) bypass themselves so our buffer runs and caches the
        // page. It is not a real session, so it must not count as auth here —
        // otherwise our own logged-in skip would refuse to cache every
        // preloaded page. should_cache()'s is_user_logged_in() check is the
        // authoritative backstop and still detects a genuinely logged-in
        // visitor who appends ?eopreload=1, so this cannot poison the cache.
        if ( defined( 'EASYOPT_IS_PRELOAD_REQUEST' ) && EASYOPT_IS_PRELOAD_REQUEST ) {
            return false;
        }

        if ( empty( $_COOKIE ) ) {
            return false;
        }

        $cookies = array_keys( $_COOKIE );

        // 1. WP cookie name constants (highest signal — these are exact matches).
        $constants = array( 'AUTH_COOKIE', 'SECURE_AUTH_COOKIE', 'LOGGED_IN_COOKIE' );
        foreach ( $constants as $const ) {
            if ( defined( $const ) ) {
                $value = (string) constant( $const );
                if ( '' !== $value && in_array( $value, $cookies, true ) ) {
                    return true;
                }
            }
        }

        // 2. Default WordPress cookie prefixes (covers installs without
        //    custom constants — the cookie hash is the MD5 of siteurl).
        $exact_prefixes = array(
            'wordpress_logged_in_',
            'wordpress_sec_',
            'comment_author_',
            'wp-postpass_',
            'wp-resetpass-',
        );

        foreach ( $cookies as $name ) {
            $name = (string) $name;
            foreach ( $exact_prefixes as $prefix ) {
                if ( 0 === strpos( $name, $prefix ) ) {
                    return true;
                }
            }
            // AUTH_COOKIE pattern (`wordpress_<32-hex-hash>`) — narrowed so we
            // don't false-trigger on `wordpress_test_cookie`, which is set
            // for anonymous visitors during login probes.
            if ( preg_match( '/^wordpress_[0-9a-f]{32}$/i', $name ) ) {
                return true;
            }
        }

        return false;
    }

    /* ───────────────────────────────────────────────
     * ─────────────────────────────────────────────── */

    /**
     * Cookie name used by the bouncer. Read by the .htaccess rewrite rules
     * and by advanced-cache.php to short-circuit cache HITs for any visitor
     * who is logged in (or was logged in long enough ago that their auth
     * cookies were stripped by an upstream proxy but this signal lingered).
     */
    const SKIP_COOKIE = 'easyopt_skip_cache';

    /**
     * On every front-end request, make sure the bouncer cookie's state
     * matches the user's actual login state. Three cases:
     *   - Logged in, cookie missing  → set it (this catches users who were
     *     already logged in before this version of the plugin shipped).
     *   - Not logged in, cookie present → clear it (defence against a
     *     stale cookie surviving past logout).
     *   - State already matches      → no-op (no Set-Cookie header).
     *
     * Hooked at `init` priority 1 so it runs before output starts.
     */
    public static function maybe_set_logged_in_cookie() {

        if ( headers_sent() ) {
            return;
        }
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return;
        }
        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            return;
        }
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return;
        }
        if ( ! function_exists( 'is_user_logged_in' ) ) {
            return;
        }

        // (2.5.4 / perf #16) Anonymous fast path. This runs on init prio 1
        // for EVERY frontend request; is_user_logged_in() forces full user
        // resolution (auth-cookie parse + user query + any 2FA/session
        // plugin's determine_current_user filters) even for visitors with no
        // cookies at all. A visitor with NO wordpress_logged_in_* cookie and
        // NO plugin cookies to reconcile cannot need any cookie work here:
        // logged-in requires the auth cookie, and both clear-paths below
        // require one of our cookies to be present. Bail on a raw prefix
        // scan of $_COOKIE before touching the user system. Visitors WITH
        // an auth cookie (the only ones is_user_logged_in() can return true
        // for) still take the exact pre-2.5.4 path.
        $has_cookie = isset( $_COOKIE[ self::SKIP_COOKIE ] );
        if ( ! $has_cookie && ! isset( $_COOKIE[ self::ROLE_COOKIE ] ) ) {
            $easyopt_has_auth_prefix = defined( 'LOGGED_IN_COOKIE' ) && isset( $_COOKIE[ LOGGED_IN_COOKIE ] );
            if ( ! $easyopt_has_auth_prefix ) {
                foreach ( array_keys( (array) $_COOKIE ) as $easyopt_cookie_name ) {
                    // Prefix scan covers the default name AND renamed
                    // COOKIEHASH variants; the LOGGED_IN_COOKIE constant
                    // above covers fully custom names from security plugins.
                    if ( 0 === strpos( (string) $easyopt_cookie_name, 'wordpress_logged_in_' ) ) {
                        $easyopt_has_auth_prefix = true;
                        break;
                    }
                }
            }
            if ( ! $easyopt_has_auth_prefix ) {
                return; // anonymous, nothing to set or clear.
            }
        }

        $is_logged_in = is_user_logged_in();
        $li_cache_on  = (int) EasyOpt_Config::get( 'cache_logged_in', 0 ) === 1;

        // (2.5.3) When "cache for logged-in users" is ON, the skip cookie must
        // NOT be planted — the drop-in hard-MISSes on its mere presence, which
        // made the toggle a no-op for every logged-in visitor. Instead, expire
        // any skip cookie left over from before the toggle, and backfill the
        // role cookie for sessions that logged in BEFORE the toggle (the role
        // cookie was previously only set at login, so pre-existing sessions
        // could never be served by the drop-in).
        if ( $is_logged_in && $li_cache_on ) {
            if ( $has_cookie ) {
                self::clear_logged_in_cookie();
            }
            $tag = self::current_user_role_tag();
            if ( '' !== $tag && ( ! isset( $_COOKIE[ self::ROLE_COOKIE ] ) || $tag !== (string) $_COOKIE[ self::ROLE_COOKIE ] ) ) {
                self::set_role_cookie( '', 0, time() + 14 * DAY_IN_SECONDS, get_current_user_id() );
                $_COOKIE[ self::ROLE_COOKIE ] = $tag; // visible to this request's writer
            }
            return;
        }

        if ( $is_logged_in && ! $has_cookie ) {
            self::set_logged_in_cookie();
        } elseif ( ! $is_logged_in && $has_cookie ) {
            self::clear_logged_in_cookie();
        }
    }

    /**
     * Set the bouncer cookie. Path = `/` so it's sent for every request,
     * regardless of where wp-login.php sets its own auth cookies (some
     * sites scope auth to a subpath). 14-day expiry mirrors the default
     * "remember me" auth cookie. HttpOnly is OFF — this cookie carries no
     * secret and the browser doesn't need to read it, but JavaScript might
     * (e.g. service workers checking whether to revalidate a fetch).
     */
    public static function set_logged_in_cookie() {
        if ( headers_sent() ) {
            return;
        }
        $secure = is_ssl();
        @setcookie(
            self::SKIP_COOKIE,
            '1',
            array(
                'expires'  => time() + 14 * DAY_IN_SECONDS,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $secure,
                'httponly' => false,
                'samesite' => 'Lax',
            )
        );
        // Also reflect in the in-process superglobal so any code that reads
        // $_COOKIE later in the same request sees the new state.
        $_COOKIE[ self::SKIP_COOKIE ] = '1';
    }

    /**
     * Clear the bouncer cookie. Symmetrical to `set_logged_in_cookie()`.
     */
    public static function clear_logged_in_cookie() {
        if ( headers_sent() ) {
            return;
        }
        @setcookie(
            self::SKIP_COOKIE,
            '',
            array(
                'expires'  => time() - DAY_IN_SECONDS,
                'path'     => '/',
                'domain'   => '',
                'secure'   => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            )
        );
        unset( $_COOKIE[ self::SKIP_COOKIE ] );
    }

    /* ───────────────────────────────────────────────
     * ─────────────────────────────────────────────── */

    /**
     * Whether the wp-content/advanced-cache.php drop-in is currently active
     * and serving. We don't want our `init,1` PHP-mode hook racing against
     * it: when the drop-in handles HITs, the only requests reaching `init`
     * are MISSes anyway, and re-checking is_request_cacheable() at runtime
     * is wasted work.
     */
    public static function is_dropin_active() {
        if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
            return false;
        }
        if ( ! class_exists( 'EasyOpt_Advanced_Cache' ) ) {
            return false;
        }
        return EasyOpt_Advanced_Cache::is_installed();
    }

    /**
     * Self-heal the drop-in. Runs once per admin page load: if cache is on
     * and the drop-in isn't present (and isn't owned by another plugin),
     * install it. Cheap when nothing's needed (single file_exists()), and
     * idempotent on the slow path.
     */
    public static function maybe_install_dropin_on_admin() {
        if ( ! self::is_enabled() ) {
            return;
        }
        if ( ! class_exists( 'EasyOpt_Advanced_Cache' ) ) {
            return;
        }
        if ( ! EasyOpt_Advanced_Cache::is_enabled() ) {
            return; // cache off
        }
        if ( EasyOpt_Advanced_Cache::is_installed() ) {
            return; // already installed (and ours)
        }
        // Either missing, or owned by another plugin — install (overwrites foreign).
        EasyOpt_Advanced_Cache::install();
    }

    // (2.3.3) The old html_affecting_options() / dropin_affecting_options()
    // methods were removed. They referenced settings keys that no longer
    // exist in the registry and nothing called them — the save coordinator
    // has owned the authoritative cache-affecting diff lists since 1.7.0.

    /**
     * Refresh the drop-in (re-export config). Cheap — just rewrites a
     * single PHP file. Hooked per-option in init() since 1.5.4.
     */
    public static function refresh_dropin() {
        if ( class_exists( 'EasyOpt_Advanced_Cache' ) ) {
            EasyOpt_Advanced_Cache::install();
        }
    }

    /**
     * (2.6.1) A plugin was activated or deactivated — re-export the drop-in
     * config, and purge if the cache KEY changed as a result.
     *
     * Only the key-affecting part warrants a purge: include-cookies is
     * resolved live by the writer but frozen in the drop-in's snapshot, so a
     * mismatch means the two disagree about which file a page lives in. Every
     * other config value (TTL, exclusions) is safe to update in place.
     *
     * @param string $plugin Unused — any change warrants the check.
     */
    public static function on_plugin_set_changed( $plugin = '' ) {
        unset( $plugin );
        if ( ! class_exists( 'EasyOpt_Advanced_Cache' ) || ! EasyOpt_Advanced_Cache::is_enabled() ) {
            return;
        }

        // What the drop-in currently believes, before we overwrite it.
        $exported = EasyOpt_Advanced_Cache::exported_include_cookies();
        // What the writer will actually key on from now on.
        $live = self::include_cookie_list();

        sort( $exported );
        sort( $live );
        $key_changed = ( $exported !== $live );

        self::refresh_dropin();

        if ( $key_changed ) {
            // Files written under the old key are now either unreachable or,
            // worse, still reachable at a path that no longer describes them.
            self::clear_all();
        }
    }

    /**
     * Set the role cookie on login so the drop-in can vary cache by role.
     * Same lifetime as WP's logged_in_cookie. Skipped if the option is off.
     */
    public static function set_role_cookie( $logged_in_cookie, $expire, $expiration, $user_id ) {
        unset( $logged_in_cookie, $expire );
        if ( ! (int) EasyOpt_Config::get( 'cache_logged_in', 0 ) ) {
            return;
        }
        $tag = self::role_tag_for_user_id( (int) $user_id );
        if ( '' === $tag ) {
            return;
        }
        $secure = is_ssl();
        // We only need it visible to PHP — HttpOnly + same path/domain as
        // the auth cookie. Keep it short so it doesn't bloat every request.
        @setcookie(
            self::ROLE_COOKIE,
            $tag,
            (int) $expiration,
            COOKIEPATH ? COOKIEPATH : '/',
            COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            $secure,
            true
        );
    }

    public static function clear_role_cookie() {
        if ( isset( $_COOKIE[ self::ROLE_COOKIE ] ) ) {
            unset( $_COOKIE[ self::ROLE_COOKIE ] );
            @setcookie(
                self::ROLE_COOKIE,
                '',
                time() - 3600,
                COOKIEPATH ? COOKIEPATH : '/',
                COOKIE_DOMAIN ? COOKIE_DOMAIN : ''
            );
        }
    }

    /**
     * Resolve the current user's role tag. Returns the first non-administrator
     * role, falling back to the first role; empty string for users without a
     * role. The tag is sanitised so it's safe to embed in a filename.
     */
    public static function current_user_role_tag() {
        if ( ! function_exists( 'wp_get_current_user' ) ) {
            // Fall back to cookie value (good enough for filename matching).
            if ( isset( $_COOKIE[ self::ROLE_COOKIE ] ) ) {
                return preg_replace( '/[^a-z0-9_\-]/i', '', (string) wp_unslash( $_COOKIE[ self::ROLE_COOKIE ] ) );
            }
            return '';
        }
        $u = wp_get_current_user();
        if ( ! $u || empty( $u->ID ) ) {
            return '';
        }
        return self::role_tag_for_user_id( (int) $u->ID );
    }

    private static function role_tag_for_user_id( $user_id ) {
        if ( $user_id <= 0 ) {
            return '';
        }
        // (2.5.3) Per-user cache instead of per-role — for sites with genuine
        // per-user content (nonce-heavy dashboards, personalised fragments):
        // add_filter( 'easyopt_cache_logged_in_per_user', '__return_true' );
        if ( apply_filters( 'easyopt_cache_logged_in_per_user', false ) ) {
            return 'user-' . (int) $user_id;
        }
        $u = get_userdata( $user_id );
        if ( ! $u || empty( $u->roles ) || ! is_array( $u->roles ) ) {
            return '';
        }
        $role = (string) reset( $u->roles );
        // Restrict which roles are cached (empty array = all roles):
        // add_filter( 'easyopt_cache_logged_in_roles', fn() => [ 'subscriber', 'customer' ] );
        $allowed = (array) apply_filters( 'easyopt_cache_logged_in_roles', array() );
        if ( ! empty( $allowed ) && ! in_array( $role, $allowed, true ) ) {
            return ''; // empty tag = this user is never cache-served/written
        }
        return preg_replace( '/[^a-z0-9_\-]/i', '', $role );
    }

    /* ───────────────────────────────────────────────
     *  Default tracking parameters
     * ─────────────────────────────────────────────── */

    /**
     * Default tracking parameters that are always stripped before hashing
     * the request URI into a cache key. Matches the lists used by WP Super
     * Cache, WP Rocket, LiteSpeed Cache and the major analytics platforms
     * so different campaign URLs all hit the same cache file.
     *
     * @return string[]
     */
    public static function get_default_strip_params() {
        return array(
            // Google Analytics / Ads
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'utm_id', 'utm_name', 'utm_brand', 'utm_social', 'utm_social-type',
            'utm_expid',
            'gclid', 'gbraid', 'wbraid', 'gclsrc', 'dclid', 'gad_source', 'gad_campaignid',
            'gadid', // (2.6.1) Google Ads — present in WP Rocket's list, missing here
            'srsltid', // Google Shopping
            '_ga', '_gl',
            // Meta / Facebook
            'fbclid', 'fb_action_ids', 'fb_action_types', 'fb_source',
            'fbadid', // (2.6.1) parity with WP Rocket
            // Mailchimp
            'mc_cid', 'mc_eid',
            // Microsoft Ads
            'msclkid',
            // Yandex
            'yclid',
            // Twitter / X
            'twclid',
            // TikTok
            'ttclid',
            // Instagram share
            'igshid',
            // HubSpot
            '_hsenc', '_hsmi', 'hsCtaTracking',
            // Matomo / Piwik
            'mtm_campaign', 'mtm_cid', 'mtm_content', 'mtm_keyword', 'mtm_medium', 'mtm_source',
            'pk_campaign', 'pk_cid', 'pk_content', 'pk_keyword', 'pk_medium', 'pk_source',
            // Google Analytics 4 / newer UTM variants
            'utm_source_platform', 'utm_creative_format', 'utm_marketing_tactic',
            // Google AMP viewer
            'usqp',
            // Email marketing — these arrive on campaign landing pages, which
            // are exactly the pages that most need to be cached.
            'mkt_tok',                              // Marketo
            '_kx', '_ke',                           // Klaviyo (_ke added 2.6.1)
            'ck_subscriber_id',                     // ConvertKit
            'ml_subscriber', 'ml_subscriber_hash',  // MailerLite
            'omnisendContactID',                    // Omnisend
            'vgo_ee',                               // ActiveCampaign / Vero
            '_bta_tid', '_bta_c',                   // Bronto
            'elqTrack', 'elqTrackId',               // Oracle Eloqua
            // Social / ad platforms
            'li_fat_id',                            // LinkedIn
            'rdt_cid',                              // Reddit Ads
            'ScCid', 'sc_cid',                      // Snapchat
            'irclickid',                            // Impact Radius
            'wickedid',                             // Wicked Reports
            // HubSpot paid-search parameters (appended to every Google Ads
            // click on a HubSpot-tracked site)
            'hsa_acc', 'hsa_cam', 'hsa_grp', 'hsa_ad', 'hsa_src',
            'hsa_tgt', 'hsa_kw', 'hsa_mt', 'hsa_net', 'hsa_ver',
            // Matomo / Piwik extras
            'mtm_group', 'mtm_placement', 'pk_kwd',
            // AT Internet
            'at_medium', 'at_campaign',
            // Misc ad networks
            'adgroupid', 'adid', 'campaignid', 'mkwid', 'pcrid',
            'ef_id', 'epik', 'sscid', 's_kwcid', 'cn-reloaded',
            // Misc tracking
            'ref', 'referrer', 'redirect_log_mongo_id', 'redirect_mongo_id',
            'sb_referer_host', 'pp', 'age-verified',
            'trk_contact', 'trk_msg', 'trk_module', 'trk_sid',
            'dm_i', 'gdfms', 'gdftrk', 'gdffi', 'kboard_id',
            // Optimisation bypasses (deliberately cause MISS — kept here so
            // they don't accidentally bust caches when other plugins append them)
            'ao_noptimize',
            // ?eopreload=1 so the request can be recognised by WAFs and
            // by our own code without depending on UA fingerprinting
            // (which gets flagged as bot traffic). Stripped here so the
            // cache write goes to the same path the live visitor would
            // produce — otherwise we'd warm a parallel cache file that
            // nobody ever reads.
            'eopreload',
            // ?eonobuf=1 — preloader's buffer-disabled refetch marker. Same
            // reasoning as eopreload: strip so it maps to the canonical cache
            // path and never creates a parallel cache file.
            'eonobuf',
            // ?eoslot=<token> — preload in-flight budget token. Already
            // stripped from the superglobals in easy-optimizer.php before
            // anything reads them; listed here as a second line of defence so
            // it can never key a parallel cache file.
            'eoslot',
        );
    }

    /**
     * (2.6.0) Query parameters that must NEVER be stripped before the
     * cacheability test, no matter what the user configures or a filter adds.
     *
     * Both serve paths strip the configured params from $_GET and only THEN
     * ask "is anything left?" (effective_query_string() here;
     * assets/advanced-cache.php does the same). That order means any param
     * present in the strip list becomes invisible to the cache — which is
     * correct for utm_* and friends, and catastrophic for a param whose whole
     * purpose is to force a MISS.
     *
     * Without this list, a user who added "nooptimize" to
     * Settings → Caching → Strip query params turned ?nooptimize into a cache
     * HIT: the drop-in served the stored, fully-optimised file and the debug
     * switch silently did nothing — a diagnostic tool reporting "the plugin is
     * not the problem" when the plugin was never disabled. easyopt_bypass had
     * the same exposure and would have quietly corrupted the PSI "before"
     * baseline (a suspiciously good score with no way to tell why).
     *
     * Applied AFTER the user merge and AFTER the filter, so nothing can
     * reintroduce them.
     *
     * @return string[]
     */
    /**
     * (2.6.1) Parameters the site owner wants their OWN cache file for.
     *
     * The counterpart to "Ignored Query Parameters". Ignoring a parameter
     * makes every value of it share one cache file — right for utm_* and
     * gclid, wrong for the minority of sites where the SERVER renders the
     * value into the HTML (a form that writes UTM values into hidden fields,
     * an affiliate plugin that varies content by ?ref=). Listing it here
     * gives each value its own file instead.
     *
     * Names only, one per line. The value is not configurable — it comes from
     * the request.
     *
     * @since 2.6.1
     * @return string[]
     */
    public static function get_query_string_params() {

        static $easyopt_qs_memo = null;
        if ( null !== $easyopt_qs_memo ) {
            return $easyopt_qs_memo;
        }

        $raw   = (string) EasyOpt_Config::get( 'cache_query_strings', '' );
        $lines = '' !== $raw ? array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) : array();

        /**
         * Filter the parameters that key their own cache file.
         *
         * @since 2.6.1
         * @param string[] $lines Parameter names.
         */
        $lines = (array) apply_filters( 'easyopt_cache_query_strings', $lines );

        // Per-click identifiers can never key a cache file — one file per
        // visitor, for ever. Subtracted last so neither the setting nor the
        // filter can reintroduce one. See EasyOpt\Cache\never_key_params().
        $lines = array_values( array_diff(
            array_unique( array_filter( array_map( 'strval', $lines ) ) ),
            \EasyOpt\Cache\never_key_params()
        ) );

        // A parameter cannot both be ignored and key the cache. Reserved wins
        // over everything (they are debug switches that must force a MISS), so
        // subtract those too.
        $easyopt_qs_memo = array_values( array_diff( $lines, self::get_reserved_query_params() ) );
        return $easyopt_qs_memo;
    }

    public static function get_reserved_query_params() {
        return array(
            // Per-request debug switches — see easyopt_debug_switch().
            'nooptimize',
            'nocache',
            'nodelayjs',
            'norucss',
            // Signed diagnostic-link marker (Debug Issues panel).
            'eodbg',
            // "View as a logged-out visitor" marker (Debug Issues panel).
            // MUST stay unstrippable: stripping it would make the request look
            // cacheable, and an administrator's render could then be written
            // into — or served from — the anonymous cache slot.
            'eopview',
            // Short-lived PSI baseline bypass token.
            'easyopt_bypass',
            // Prefetch Pages observability marker (2.6.7) — see
            // EasyOpt_Navigate::debug_requested().
            'eonavdebug',
        );
    }


    /**
     * User-defined strippable params + the always-stripped defaults.
     */
    public static function get_strip_params() {

        // (2.5.4 / perf #15) Parsed once per request. is_request_cacheable()
        // runs on both the serve and capture paths, and each call used to
        // rebuild the ~90-item default list + user merge + array_unique.
        // The final cacheability VERDICT stays deliberately un-memoised —
        // the password-protected gate legitimately differs between the two
        // calls (parse_query hasn't run at serve time).
        static $easyopt_strip_memo = null;
        if ( null !== $easyopt_strip_memo ) {
            return $easyopt_strip_memo;
        }

        $defaults = self::get_default_strip_params();

        $user  = (string) EasyOpt_Config::get( 'cache_strip_query_params', '' );
        $lines = array();
        if ( '' !== $user ) {
            $lines = array_filter( array_map( 'trim', explode( "\n", $user ) ) );
        }

        $merged = array_unique( array_merge( $defaults, $lines ) );
        $easyopt_strip_memo = (array) apply_filters( 'easyopt_cache_strip_query_params', $merged );

        // (2.6.0) Reserved params are subtracted LAST — after the user list and
        // after the filter — so neither can make a debug switch or the PSI
        // bypass token invisible to the cacheability test. See
        // get_reserved_query_params() for why that would break them silently.
        //
        $easyopt_strip_memo = array_values( array_diff(
            $easyopt_strip_memo,
            self::get_reserved_query_params()
        ) );

        // (2.6.1) "Cache Query String" entries are ADDED to the strip list,
        // not removed from it.
        //
        // Both serve paths ask "after stripping, is any query left?" and
        // refuse to cache if so. A parameter that is getting its own cache
        // file must therefore still be stripped for that test, or the request
        // would look uncacheable and we would render it fresh every time —
        // which is the opposite of the feature. Its value is accounted for
        // separately, in the FILENAME, via
        // \EasyOpt\Cache\query_variant_tag().
        //
        // Union rather than intersection also means a name that is not in the
        // ~90 defaults (a bespoke ?variant=) works without the user also
        // having to add it to "Ignored Query Parameters".
        $easyopt_strip_memo = array_values( array_unique( array_merge(
            $easyopt_strip_memo,
            self::get_query_string_params()
        ) ) );

        return $easyopt_strip_memo;
    }

    /**
     * Return the request's query string with all strippable parameters
     * removed. Empty string means "no significant query" — i.e. cacheable.
     */
    private static function effective_query_string() {

        if ( empty( $_GET ) ) {
            return '';
        }

        $strip = self::get_strip_params();
        $kept  = array();
        foreach ( $_GET as $key => $value ) {
            if ( in_array( (string) $key, $strip, true ) ) {
                continue;
            }
            $kept[ (string) $key ] = $value;
        }

        if ( empty( $kept ) ) {
            return '';
        }
        // Keep stable order so equivalent param-sets hash to the same key.
        ksort( $kept );
        return http_build_query( $kept );
    }

    public static function get_url_exclusions() {

        // (2.5.4 / perf #15) Parsed once per request (see get_strip_params).
        static $easyopt_urlx_memo = null;
        if ( null !== $easyopt_urlx_memo ) {
            return $easyopt_urlx_memo;
        }

        $defaults = array(
            // WooCommerce
            '/cart', '/checkout', '/my-account', '/wc-api',
            '/wp-json/wc/', '?wc-ajax=', '?add-to-cart=',
            '?remove_item=', '?undo_item=',
            // EDD
            '/edd-action',
            // Misc
            // (2.6.0) '&nocache' added alongside '?nocache'. These are plain
            // substring tests (see EasyOpt\Cache\is_excluded_uri), so the
            // original token only matched when nocache happened to be the
            // FIRST query parameter — /?nooptimize&nocache did not match at
            // all. The Debug Issues panel emits combined switch URLs, so the
            // exclusion has to bind wherever the parameter appears.
            '?nocache', '&nocache', 'preview=true', 'p_action',
        );

        $user  = EasyOpt_Config::get( 'cache_exclude_urls', '' );
        $lines = array();
        if ( ! empty( $user ) ) {
            $lines = array_filter( array_map( 'trim', explode( "\n", (string) $user ) ) );
        }
        $easyopt_urlx_memo = array_unique( array_merge( $defaults, $lines ) );
        return $easyopt_urlx_memo;
    }

    public static function get_cookie_exclusions() {

        // (2.5.4 / perf #15) Parsed once per request (see get_strip_params).
        static $easyopt_ckx_memo = null;
        if ( null !== $easyopt_ckx_memo ) {
            return $easyopt_ckx_memo;
        }

        // (2.5.5) The three WooCommerce cookies — woocommerce_items_in_cart,
        // woocommerce_cart_hash and wp_woocommerce_session_ — were removed
        // here. WooCommerce sets ALL THREE the moment a product is added to
        // the cart, so their presence meant every page a shopper viewed for
        // the rest of their session bypassed the cache entirely: no read, no
        // write. The people browsing with a full cart are exactly the ones a
        // store least wants on uncached pages.
        //
        // WP Rocket's default reject list contains none of them (see
        // get_rocket_cache_reject_cookies() — only logged-in, postpass,
        // wptouch and comment_author). Correctness comes from two other
        // mechanisms we already have, not from the cookie:
        //   • cart / checkout / my-account / wc-ajax are excluded by URL, and
        //   • the mini-cart is refreshed client-side by WooCommerce's own
        //     cart-fragments AJAX call on every page load.
        // Plus, new in 2.5.5, is_html_cacheable() refuses to WRITE a page
        // that has queued WooCommerce notices (see the wc_notice_count()
        // guard) — that was the one genuine exposure from dropping the
        // session cookie.
        //
        // Restore the old behaviour on a store whose theme prints the cart
        // count outside the standard fragment markup:
        //   add_filter( 'easyopt_cache_exclude_cookies_defaults', function ( $c ) {
        //       $c[] = 'wp_woocommerce_session_';
        //       return $c;
        //   } );
        $defaults = array(
            'wordpress_logged_in_',
            'comment_author_',
            'edd_items_in_cart',
            'wp-postpass_',
            'wp-resetpass-',
        );

        /**
         * Filter the built-in cookie exclusions before the user's list is merged.
         *
         * @since 2.5.5
         * @param array $defaults Built-in cookie name fragments.
         */
        $defaults = (array) apply_filters( 'easyopt_cache_exclude_cookies_defaults', $defaults );

        $user  = EasyOpt_Config::get( 'cache_exclude_cookies', '' );
        $lines = array();
        if ( ! empty( $user ) ) {
            $lines = array_filter( array_map( 'trim', explode( "\n", (string) $user ) ) );
        }
        $easyopt_ckx_memo = array_unique( array_merge( $defaults, $lines ) );
        return $easyopt_ckx_memo;
    }

    /* ───────────────────────────────────────────────
     *  Path resolution
     * ─────────────────────────────────────────────── */

    /**
     * (2.6.1) Hosts that legitimately serve this site, beyond home_url().
     *
     * On multisite every network domain is real, so the list is populated and
     * the unrecognised-host fallback never fires. Single-site installs can add
     * domain aliases, mapped domains or a reverse-proxy hostname through the
     * filter — without it, those hosts would all be folded onto the home host
     * and share one set of cache files.
     *
     * Exported to the drop-in so both serve paths resolve hosts identically.
     *
     * @return string[]
     */
    public static function known_cache_hosts() {
        static $easyopt_hosts_memo = null;
        if ( null !== $easyopt_hosts_memo ) {
            return $easyopt_hosts_memo;
        }
        $hosts = array();
        if ( is_multisite() && function_exists( 'get_sites' ) ) {
            foreach ( (array) get_sites( array( 'number' => 200, 'fields' => 'all' ) ) as $s ) {
                if ( ! empty( $s->domain ) ) {
                    $hosts[] = (string) $s->domain;
                }
            }
        }
        /**
         * Additional hostnames that may serve this site.
         *
         * @since 2.6.1
         * @param string[] $hosts
         */
        $hosts = (array) apply_filters( 'easyopt_cache_known_hosts', $hosts );
        $easyopt_hosts_memo = array_values( array_unique( array_filter( array_map( 'strval', $hosts ) ) ) );
        return $easyopt_hosts_memo;
    }

    public static function is_mobile() {

        $rc = self::request_cache();
        if ( ! $rc['separate_mobile'] ) {
            return false;
        }

        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';

        // Shared with the drop-in (cache-common.php) so writer and both
        // serve paths always agree on the device variant.
        return \EasyOpt\Cache\is_mobile_ua( $ua );
    }

    /**
     * Compute the absolute filesystem path of the cache file for the
     * current request. Returns '' if the request can't be cached.
     */
    public static function get_cache_file_path() {

        $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';

        // Strip query string and fragment for the file path — query strings
        // are already filtered by is_request_cacheable, so anything that
        // reaches here is either empty or only utm_*.
        $path = strtok( $uri, '?#' );
        if ( ! is_string( $path ) || '' === $path ) {
            $path = '/';
        }

        // (2.6.1) HTTP_HOST is attacker-controlled. Both serve paths key on it
        // and agree, so a forged Host never produces WRONG content — but it
        // does let anyone mint an unbounded number of junk directories under
        // wp-content/cache/easyopt/ by rotating the header, which costs disk
        // and inodes on hosts that meter both.
        //
        // Fall back to the site's own host whenever the request names one we
        // do not recognise. On multisite every network domain is legitimate,
        // so the check is skipped there rather than guessed at.
        // Shared with the drop-in via cache-common.php. Applying this fallback
        // in only one of the two is how the writer ends up storing at path A
        // while the reader looks at path B — a permanent silent MISS on every
        // domain alias, mapped domain, reverse-proxy host and .test domain.
        $host = \EasyOpt\Cache\resolve_cache_host(
            isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : '',
            (string) wp_parse_url( home_url(), PHP_URL_HOST ),
            self::known_cache_hosts()
        );

        $base = self::compute_path_base( $host, $path );
        if ( '' === $base ) {
            return '';
        }

        // Filename varies by user-role (logged-in cache) and device.
        $rc       = self::request_cache();
        $filename = 'index';
        if ( $rc['cache_logged_in'] ) {
            $role = self::current_user_role_tag();
            if ( '' !== $role ) {
                $filename .= '-logged-in-' . $role;
            }
        }
        // Include-cookie keying (e.g. currency / language switchers). MUST run
        // in the same order and with the same sanitisation as the drop-in
        // (assets/advanced-cache.php) so both read/write the identical file.
        $filename .= self::include_cookie_tag();
        // (2.6.1) Query-string keying. Same rule: identical position, identical
        // shared function. Drop-in counterpart is in assets/advanced-cache.php
        // immediately after its own cookie tag.
        $filename .= self::query_string_tag();
        if ( self::is_mobile() ) {
            $filename .= '-mobile';
        }
        return $base . $filename . '.html';
    }

    /**
     * Build the include-cookie portion of the cache filename. Mirrors the
     * drop-in exactly: same filter, same value sanitisation, same order. When
     * no include cookies are registered (the common case) this returns '' and
     * adds zero overhead.
     *
     * @return string Leading-dash tag (e.g. "-USD") or ''.
     */
    /**
     * Whether any include-cookies (currency / language switchers etc.) are
     * registered. When true, .htaccess serve mode is unsafe: Apache's rewrite
     * has no per-cookie-value keying, so a switched visitor would be handed
     * the default-currency file directly — wrong prices at the edge. The
     * write path forces PHP/drop-in serving in that case (B1 fix, 2.3.3).
     *
     * @return bool
     */
    /**
     * (2.5.4 / perf #36) Resolve the include-cookie filter ONCE per request.
     * has_include_cookies() and include_cookie_tag() both dispatched
     * easyopt_cache_include_cookies — and the WooCommerce compat callback
     * behind it re-probes currency plugins (class_exists/defined ×4) on
     * every dispatch. Registrations don't change mid-request; a single
     * resolved, sanitised list serves both callers.
     *
     * @return string[] Sanitised cookie-name list (may be empty).
     */
    private static function include_cookie_list() {
        static $easyopt_incl_memo = null;
        if ( null === $easyopt_incl_memo ) {
            $list = (array) apply_filters( 'easyopt_cache_include_cookies', array() );
            $easyopt_incl_memo = array_values( array_filter( array_map( 'strval', $list ) ) );
        }
        return $easyopt_incl_memo;
    }

    public static function has_include_cookies() {
        return array() !== self::include_cookie_list();
    }

    private static function include_cookie_tag() {
        $list = self::include_cookie_list();
        if ( empty( $list ) || empty( $_COOKIE ) ) {
            return '';
        }
        return \EasyOpt\Cache\cookie_variant_tag( wp_unslash( $_COOKIE ), $list );
    }

    /**
     * (2.6.1) Query-string portion of the cache filename.
     *
     * Empty for every site that has not filled in "Cache Query String", which
     * is the default — so the filename, and therefore every existing cache
     * entry on every existing install, is byte-identical to 2.6.0.
     *
     * @return string
     */
    private static function query_string_tag() {
        $list = self::get_query_string_params();
        if ( empty( $list ) || empty( $_GET ) ) {
            return '';
        }
        return \EasyOpt\Cache\query_variant_tag( wp_unslash( $_GET ), $list );
    }

    /**
     * Build the cache directory path from host + URL path. Shared by both
     * the live request handler and the preload skipper.
     */
    private static function compute_path_base( $host, $path ) {

        // (2.6.0) Shared with the drop-in via cache-common.php. The two used
        // different sanitisers, so any URL containing a character one stripped
        // and the other kept produced two different directories — writer
        // stored at A, drop-in read at B, permanent MISS with no signal.
        $clean_path = \EasyOpt\Cache\path_segments( $path );

        $host = strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', (string) $host ) );
        if ( '' === $host ) {
            return '';
        }

        if ( '' === self::$root_path ) {
            self::setup_paths();
        }

        $base = self::$root_path . $host . '/';
        if ( '' !== $clean_path ) {
            $base .= $clean_path . '/';
        }
        return $base;
    }

    /**
     * Resolve both desktop and mobile cache paths for an arbitrary URL.
     * Used by the preloader to skip URLs that are already warmed.
     *
     * @param string $url Absolute URL.
     * @return array{desktop:string, mobile:string} Empty strings on resolution failure.
     */
    public static function cache_paths_for_url( $url ) {

        $parsed = wp_parse_url( $url );
        if ( empty( $parsed ) || empty( $parsed['host'] ) ) {
            return array( 'desktop' => '', 'mobile' => '' );
        }

        $path = isset( $parsed['path'] ) ? (string) $parsed['path'] : '/';
        $base = self::compute_path_base( $parsed['host'], $path );
        if ( '' === $base ) {
            return array( 'desktop' => '', 'mobile' => '' );
        }

        // (2.6.0) The _gzip names stay as 'desktop'/'mobile' so every existing
        // caller keeps the exact contract it had. The plain-HTML companions
        // are ADDED because a cache entry may legitimately exist with no gzip
        // copy at all: since 2.5.7 capture_buffer() honours the Gzip setting
        // and skips gzip outright on OpenLiteSpeed (which compresses
        // natively), so on those installs a visitor-warmed page is only ever
        // index.html. Callers that ask "is this URL cached?" — chiefly
        // EasyOpt_Cache_Preload::filter_already_cached() — must accept either,
        // or they classify every such page as uncached forever and re-warm the
        // entire site on every build.
        return array(
            'desktop'       => $base . 'index.html_gzip',
            'mobile'        => $base . 'index-mobile.html_gzip',
            'desktop_plain' => $base . 'index.html',
            'mobile_plain'  => $base . 'index-mobile.html',
        );
    }

    /**
     * Write a cache file for a SPECIFIC URL from an already-rendered HTML
     * string — e.g. the response body the preloader fetched over HTTP.
     *
     * This is the capture path for environments where the in-process output
     * buffer can't be trusted (themes/hosts that rewrite or clean the whole
     * output-buffer stack, which both breaks our capture AND blanks the page
     * if we add a buffer of our own). Because it works purely from the bytes
     * the server actually returned, it's immune to all of that.
     *
     * Mirrors capture_buffer()'s on-disk format exactly (gzip file + marker)
     * so the existing serve paths (drop-in and maybe_serve_cache) read it
     * with no changes.
     *
     * @param string $url       Absolute URL that was fetched (clean, no preload args).
     * @param string $html      Full HTML body returned for $url.
     * @param bool   $is_mobile Whether this is the mobile variant.
     * @return bool True when a cache file was written.
     */
    public static function store_prefetched_html( $url, $html, $is_mobile = false ) {
        if ( ! self::is_enabled() ) {
            return false;
        }
        if ( ! is_string( $html ) || strlen( $html ) < 256 ) {
            return false;
        }
        // Must be a complete HTML document, and must not opt out of caching.
        if ( false === stripos( $html, '<html' )
          || false === stripos( $html, '</body>' )
          || false === stripos( $html, '</html>' )
          || false !== stripos( $html, 'DONOTCACHEPAGE' ) ) {
            return false;
        }

        $parsed = wp_parse_url( $url );
        if ( empty( $parsed ) || empty( $parsed['host'] ) ) {
            return false;
        }
        $base = self::compute_path_base(
            $parsed['host'],
            isset( $parsed['path'] ) ? (string) $parsed['path'] : '/'
        );
        if ( '' === $base ) {
            return false;
        }

        $file = $base . ( $is_mobile ? 'index-mobile.html' : 'index.html' );

        $dir = dirname( $file );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        if ( ! is_dir( $dir ) ) {
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::error( 'cache', 'Prefetch cache write failed: cannot create ' . $dir );
            }
            return false;
        }

        $marker = sprintf(
            "\n<!-- Cached by Easy Optimizer %s on %s (%s, prefetch) -->",
            EASYOPT_VERSION,
            gmdate( 'Y-m-d H:i:s' ),
            $is_mobile ? 'mobile' : 'desktop'
        );
        $contents  = $html . $marker;
        $wrote_new = ! file_exists( $file . '_gzip' ) && ! file_exists( $file );

        // (2.5.5) Mirror capture_buffer(): the plain .html is always written
        // and the gzip copy is a companion, so a prefetch-warmed page is
        // servable on OpenLiteSpeed and to non-gzip clients exactly like a
        // visitor-warmed one. Writing only the gzip file here was the reason
        // preloaded pages inherited the same "browser downloads the page"
        // failure as live ones.
        $gz          = function_exists( 'gzencode' ) ? gzencode( $contents, 6 ) : false;
        $write_plain = (bool) apply_filters( 'easyopt_cache_write_plain', true );
        if ( false === $gz ) {
            $write_plain = true;
        }

        $ok = true;
        if ( $write_plain ) {
            $ok = self::atomic_write( $file, $contents );
        }
        if ( $ok && false !== $gz ) {
            $gz_ok = self::atomic_write( $file . '_gzip', $gz );
            // Plain file already written → soft failure, keep the entry.
            if ( ! $gz_ok && ! $write_plain ) {
                $ok = false;
            }
        }

        if ( ! $ok ) {
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::error( 'cache', 'Prefetch cache write failed: ' . $file );
            }
            return false;
        }

        if ( $wrote_new ) {
            // (2.6.0) Same per-URL counting rule as the live capture path.
            if ( self::is_first_variant_for_url( $file ) ) {
                do_action( 'easyopt_page_cached' );
            }
            delete_transient( 'easyopt_cache_stats' );
            delete_transient( 'easyopt_waiting_count' );
            if ( class_exists( 'EasyOpt_Cache_Preload' )
                 && method_exists( 'EasyOpt_Cache_Preload', 'bump_state_version' ) ) {
                EasyOpt_Cache_Preload::bump_state_version();
            }
        }

        // (2.5.0 / C3) Ground-truth completion for the prefetch capture path.
        // Confirm on every successful write (not just $wrote_new) so a
        // re-warm of an existing file still records the device bit — the
        // ledger's confirm() is idempotent and cheap (one indexed UPDATE).
        if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
            EasyOpt_Preload_Results::confirm( $url, (bool) $is_mobile );
        }
        return true;
    }

    /* ───────────────────────────────────────────────
     *  Asset-cache GC (2.3.3)
     * ─────────────────────────────────────────────── */

    /**
     * Refresh a cached asset file's mtime when it's actually referenced by a
     * page render — at most once per day per file. This turns mtime into a
     * cheap "last used" signal so gc_asset_caches() deletes ONLY files no
     * render has touched in the retention window, never anything in use.
     * Cost: one filemtime() (stat usually already cached by the caller's
     * file_exists/is_file) plus a rare touch().
     *
     * @since 2.3.3
     * @param string $file Absolute path.
     */
    public static function touch_if_stale( $file ) {
        $m = @filemtime( $file );
        if ( false !== $m && ( time() - (int) $m ) > DAY_IN_SECONDS ) {
            @touch( $file );
        }
    }

    /**
     * Daily sweep of the hash-keyed asset caches (minified CSS/JS,
     * externalised inline JS/CSS, used-CSS files). These files were never
     * garbage-collected before 2.3.3 and grew without bound on sites with
     * frequent theme/plugin updates (every source change mints a new hash).
     *
     * Deletes ONLY files whose mtime is older than the retention window —
     * and because every reference refreshes mtime via touch_if_stale(), an
     * old mtime genuinely means "no page render has used this file in N
     * days". Anything deleted by mistake regenerates on demand anyway.
     * Hooked into the daily queue GC.
     *
     * @since 2.3.3
     * @return int Number of files removed.
     */
    public static function gc_asset_caches() {
        if ( '' === self::$root_path ) {
            self::setup_paths();
        }

        $days   = max( 7, (int) apply_filters( 'easyopt_asset_gc_days', 30 ) );
        $cutoff = time() - ( $days * DAY_IN_SECONDS );

        $dirs = array(
            self::$root_path . 'min/css/',
            self::$root_path . 'min/js/',
            self::$root_path . 'js/inline/',
            self::$root_path . 'css/inline/',
            self::$root_path . 'css/',          // *.used.css (top level only)
        );

        $deleted = 0;
        foreach ( $dirs as $dir ) {
            if ( ! is_dir( $dir ) ) {
                continue;
            }
            $files = @scandir( $dir );
            if ( false === $files ) {
                continue;
            }
            foreach ( $files as $name ) {
                if ( '.' === $name || '..' === $name
                     || '.htaccess' === $name || 'index.html' === $name ) {
                    continue;
                }
                $full = $dir . $name;
                if ( ! is_file( $full ) ) {
                    continue; // subdirs handled by their own entry above
                }
                $m = @filemtime( $full );
                if ( false !== $m && (int) $m < $cutoff ) {
                    if ( @unlink( $full ) ) {
                        $deleted++;
                    }
                }
            }
        }

        if ( $deleted > 0 && class_exists( 'EasyOpt_Debug_Log' ) ) {
            EasyOpt_Debug_Log::info( 'cache', sprintf(
                'Asset-cache GC removed %d file(s) not referenced by any render in %d days.',
                $deleted, $days
            ) );
        }
        return $deleted;
    }

    /* ───────────────────────────────────────────────
     *  Stats
     * ─────────────────────────────────────────────── */

    public static function get_stats() {

        $cached = get_transient( 'easyopt_cache_stats' );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $stats = array(
            'pages'   => 0,
            'size'    => 0,
            'cleared' => (int) get_option( 'easyopt_cache_cleared_at', 0 ),
            'oldest'  => 0,
            'newest'  => 0,
        );

        if ( ! is_dir( self::$root_path ) ) {
            set_transient( 'easyopt_cache_stats', $stats, MINUTE_IN_SECONDS );
            return $stats;
        }

        $page_dirs = array();

        try {
            $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( self::$root_path, FilesystemIterator::SKIP_DOTS ) );
            foreach ( $iterator as $f ) {
                if ( ! $f->isFile() ) {
                    continue;
                }

                $name = $f->getFilename();

                // Match every cache variant: index*.html and index*.html_gzip
                // (covers role, mobile, include-cookie tags). Skip everything else.
                if ( ! preg_match( '/^index(?:-[a-z0-9_\-]+)*\.html(?:_gzip)?$/i', $name ) ) {
                    continue;
                }

                $page_dirs[ $f->getPath() ] = true;
                $stats['size'] += (int) $f->getSize();

                // Track age range. Only count canonical .html files (skip
                // _gzip companions) so the displayed timestamps line up with
                // when each page was actually cached.
                if ( substr( $name, -5 ) !== '_gzip' ) {
                    $mtime = (int) $f->getMTime();
                    if ( 0 === $stats['oldest'] || $mtime < $stats['oldest'] ) {
                        $stats['oldest'] = $mtime;
                    }
                    if ( $mtime > $stats['newest'] ) {
                        $stats['newest'] = $mtime;
                    }
                }
            }
        } catch ( \Throwable $e ) {
            return $stats;
        }

        $stats['pages'] = count( $page_dirs );

        // 60-second cache so settings page reloads + AJAX polls don't all
        // re-scan the whole cache tree. Invalidated on clear_all().
        set_transient( 'easyopt_cache_stats', $stats, MINUTE_IN_SECONDS );

        // Let the atomic counter self-heal from the authoritative scan.
        do_action( 'easyopt_cache_stats_computed', $stats );

        return $stats;
    }

    /* ───────────────────────────────────────────────
     *  Admin handlers
     * ─────────────────────────────────────────────── */

    /**
     * Are we rendering inside the block editor (post or site)?
     *
     * Deliberately fails "no": if the screen is not available we keep the
     * existing behaviour rather than silently removing a menu item people use.
     *
     * @return bool
     */
    private static function is_block_editor_screen() {
        global $pagenow;

        // The Site Editor has no post screen to ask, so match it by page.
        if ( isset( $pagenow ) && 'site-editor.php' === $pagenow ) {
            return true;
        }
        if ( ! function_exists( 'get_current_screen' ) ) {
            return false;
        }
        $screen = get_current_screen();

        return ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() );
    }

    public static function admin_bar_menu( $wp_admin_bar ) {

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! self::is_enabled() ) {
            return;
        }

        // Shared 'easyopt-root' parent is owned by the main plugin (priority 80).
        $parent_id = 'easyopt-root';
        if ( ! $wp_admin_bar->get_node( $parent_id ) ) {
            // Defensive fallback — should not happen since main plugin registers this.
            $wp_admin_bar->add_node( array(
                'id'    => $parent_id,
                'title' => __( 'Easy Optimizer', 'easy-optimizer' ),
                'href'  => admin_url( 'admin.php?page=easy-optimizer' ),
            ) );
        }

        // One node per context (never both):
        //   • wp-admin  → site-wide "Clear All Cache"
        //   • front-end → per-page "Clear Page Cache (Current)"
        // Either way the parent gets a child, so the menu never empties out.
        if ( is_admin() ) {
            // (2.6.3) WordPress 7.1 keeps the toolbar on screen inside the Post
            // and Site Editors, where it used to be hidden. "Clear All Cache" is
            // a link to admin-post.php, so from inside an editor it is a full
            // page navigation sitting one click away from unsaved work. The
            // browser would warn, but a destructive-looking prompt mid-edit is
            // not behaviour anyone wants from a cache plugin.
            //
            // The action is still one click away on every other admin screen —
            // including the classic editor, where the toolbar was always
            // visible and nothing has changed.
            if ( self::is_block_editor_screen() ) {
                return;
            }
            $wp_admin_bar->add_node( array(
                'parent' => $parent_id,
                'id'     => 'easyopt-clear-cache',
                'title'  => esc_html__( 'Clear All Cache', 'easy-optimizer' ),
                'href'   => add_query_arg(
                    array(
                        'action'   => 'easyopt_clear_cache',
                        '_wpnonce' => wp_create_nonce( 'easyopt_clear_cache' ),
                    ),
                    admin_url( 'admin-post.php' )
                ),
                'meta'   => array(),
            ) );
        } else {
            $current_url = self::current_request_url();
            if ( '' !== $current_url ) {
                $wp_admin_bar->add_node( array(
                    'parent' => $parent_id,
                    'id'     => 'easyopt-clear-page-current',
                    'title'  => esc_html__( 'Clear Page Cache (Current)', 'easy-optimizer' ),
                    'href'   => add_query_arg(
                        array(
                            'action'   => 'easyopt_clear_page_current',
                            'eopurl'   => rawurlencode( $current_url ),
                            '_wpnonce' => wp_create_nonce( 'easyopt_clear_page_current' ),
                        ),
                        admin_url( 'admin-post.php' )
                    ),
                    'meta'   => array(),
                ) );
            }
        }
    }

    /**
     * Admin-bar handler: purge the single page the admin was viewing. The URL
     * is validated against the site host before purging, so the param can't be
     * abused to probe arbitrary off-site paths. The full-site clear_all() path
     * (handle_clear_cache / ajax_clear_cache) is left intact for the dashboard.
     */
    public static function handle_clear_page_current() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to perform this action.', 'easy-optimizer' ), '', array( 'response' => 403 ) );
        }
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'easyopt_clear_page_current' ) ) {
            wp_nonce_ays( '' );
        }

        $url = isset( $_GET['eopurl'] ) ? esc_url_raw( rawurldecode( (string) wp_unslash( $_GET['eopurl'] ) ) ) : '';
        if ( '' !== $url ) {
            $req_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
            $home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
            if ( '' !== $req_host && $req_host === $home_host ) {
                self::clear_url( $url );
            }
        }

        set_transient( 'easyopt_cache_cleared', 1, 30 );

        $referer = wp_get_referer();
        wp_safe_redirect( $referer ? esc_url_raw( $referer ) : admin_url() );
        exit;
    }

    public static function handle_clear_cache() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to perform this action.', 'easy-optimizer' ), '', array( 'response' => 403 ) );
        }
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'easyopt_clear_cache' ) ) {
            wp_nonce_ays( '' );
        }

        // clear_all() internally schedules a delayed preload restart when enabled.
        self::clear_all();
        set_transient( 'easyopt_cache_cleared', 1, 30 );

        $referer = wp_get_referer();
        wp_safe_redirect( $referer ? esc_url_raw( $referer ) : admin_url() );
        exit;
    }

    public static function ajax_clear_cache() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easy-optimizer' ) ), 403 );
        }
        check_ajax_referer( 'easyopt_nonce', 'nonce' );
        // clear_all() internally schedules a delayed preload restart when enabled.
        self::clear_all();
        wp_send_json_success( array( 'message' => __( 'Page cache cleared.', 'easy-optimizer' ) ) );
    }

    /**
     * AJAX endpoint that returns formatted cache stats for live updates
     * on the settings page (no page reload required).
     */
    public static function ajax_get_stats() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easy-optimizer' ) ), 403 );
        }
        check_ajax_referer( 'easyopt_nonce', 'nonce' );

        $stats = self::get_stats();
        wp_send_json_success( array(
            'pages'        => (int) $stats['pages'],
            'size'         => (int) $stats['size'],
            'pages_label'  => number_format_i18n( $stats['pages'] ),
            'size_label'   => size_format( $stats['size'], 1 ) ?: '0 B',
            'cleared'      => (int) $stats['cleared'],
            'cleared_ago'  => $stats['cleared'] ? human_time_diff( $stats['cleared'], time() ) : '',
            'oldest'       => (int) $stats['oldest'],
            'newest'       => (int) $stats['newest'],
            'oldest_ago'   => $stats['oldest'] ? human_time_diff( $stats['oldest'], time() ) : '',
            'newest_ago'   => $stats['newest'] ? human_time_diff( $stats['newest'], time() ) : '',
        ) );
    }

    public static function admin_notices() {
        if ( false !== get_transient( 'easyopt_cache_cleared' ) ) {
            delete_transient( 'easyopt_cache_cleared' );
            // (2.6.2) Same note as the REST clear — see
            // EasyOpt_Hosting::manual_purge_note().
            $easyopt_note = '';
            if ( class_exists( 'EasyOpt_Hosting' )
                 && method_exists( 'EasyOpt_Hosting', 'manual_purge_note' ) ) {
                $easyopt_note = EasyOpt_Hosting::manual_purge_note();
            }
            echo '<div class="notice notice-success is-dismissible"><p><strong>'
                . esc_html__( 'Page cache cleared.', 'easy-optimizer' ) . '</strong>'
                . ( '' !== $easyopt_note ? ' ' . esc_html( $easyopt_note ) : '' )
                . '</p></div>';
        }

        // .htaccess health notices — only on our own settings screen, and only
        // for users who can act on them. (2.2.1)
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || false === strpos( (string) $screen->id, 'easy-optimizer' ) ) {
            return;
        }

        // Loopback could not be verified after a write — a genuine 500 would
        // not have been caught, so tell the admin rather than failing silently.
        if ( get_option( 'easyopt_htaccess_unverified' ) ) {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>'
                . esc_html__( 'Easy Optimizer', 'easy-optimizer' ) . ':</strong> '
                . esc_html__( 'Could not confirm your .htaccess changes via a loopback request (your host may be blocking internal HTTP requests). The rules were written, but please load your homepage in a new tab to verify the site works. If you see an error, switch Cache Mode to “PHP” in the Cache tab.', 'easy-optimizer' )
                . EasyOpt_Notices::link( 'htaccess_unverified', '1', __( 'I verified it — dismiss', 'easy-optimizer' ) )
                . '</p></div>';
        }

        // Gzip block was dropped because this server rejected it in testing.
        if ( get_option( 'easyopt_htaccess_gzip_unsupported' ) ) {
            echo '<div class="notice notice-info is-dismissible"><p><strong>'
                . esc_html__( 'Easy Optimizer', 'easy-optimizer' ) . ':</strong> '
                . esc_html__( 'Gzip .htaccess rules were skipped because this server did not accept them (common on OpenLiteSpeed and some LiteSpeed setups, which compress responses themselves). Page caching and all other optimizations are unaffected.', 'easy-optimizer' )
                . EasyOpt_Notices::link( 'gzip_unsupported', '1' )
                . '</p></div>';
        }
    }
}
