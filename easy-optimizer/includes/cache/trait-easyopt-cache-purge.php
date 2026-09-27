<?php
/**
 * EasyOpt_Cache_Purge_Trait — cache invalidation & filesystem helpers.
 *
 * Extracted from class-easyopt-cache.php (2.3.3) purely for maintainability.
 * Traits are flattened into the class at compile time, so this split has
 * ZERO runtime cost and the public EasyOpt_Cache API is unchanged.
 *
 * Contains: clear_all / clear_url / targeted purge hooks (posts, comments,
 * terms, authors, attachments, LCP), the multisite-safe file wipers, and
 * the purge-diagnostics recorder.
 *
 * @package EasyOptimizer
 * @since   2.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait EasyOpt_Cache_Purge_Trait {

    /**
     * (2.5.4 / perf #3) Request-scoped URL purge queue. Content-change purge
     * fan-out (post save → home + archives + terms, Woo stock → shop + terms,
     * comment/term events) used to run its filesystem globs and per-URL
     * integration hooks inline in the triggering request — a checkout or
     * editor save paid for dozens of globs and up to 20 hosting HTTP purges.
     * URLs now accumulate here (deduplicated, pagination-flag merged) and are
     * drained ONCE on shutdown, after the response has been flushed to the
     * client where FPM allows it. clear_url() itself is unchanged and still
     * available for callers that need a synchronous purge.
     *
     * @var array<string,int>|null url => 1 (page) | 2 (with pagination)
     */
    private static $purge_queue = null;

    /**
     * (2.5.4 / perf #29) When true, per-row purge listeners become no-ops.
     * Set by the Database cleanup around bulk wp_delete_post/comment loops:
     * deleting already-trashed posts (purged when trashed) and spam/trashed
     * comments (never rendered) cannot change public HTML, so the per-row
     * purge fan-out is pure waste there.
     *
     * @var bool
     */
    private static $bulk_purge_mode = false;

    /** (2.5.4 / perf #3d) One stats-transient delete per request from clear_url(). */
    private static $stats_transient_cleared = false;

    /** (2.5.4 / perf #55) When true, clear_url() skips its integration hook. */
    private static $suppress_url_purge_hooks = false;

    /** (2.5.4 / perf #3) True while (or after) the shutdown drain runs. */
    private static $purge_draining = false;

    /** Enable/disable bulk-purge suppression (see $bulk_purge_mode). */
    public static function set_bulk_purge_mode( $on ) {
        self::$bulk_purge_mode = (bool) $on;
    }

    /**
     * Queue a URL purge for the end-of-request drain. Duplicate URLs collapse;
     * a with-pagination request upgrades a previously queued plain one.
     *
     * @param string $url             Absolute URL.
     * @param bool   $with_pagination Also purge /page/N/ copies.
     */
    public static function queue_url_purge( $url, $with_pagination = false ) {
        $url = (string) $url;
        if ( '' === $url ) {
            return;
        }
        // Re-entrancy: a purge queued FROM the drain itself (or from any
        // later shutdown callback) can no longer ride this request's hook —
        // its priority slot has passed — so run it synchronously instead of
        // silently dropping it.
        if ( self::$purge_draining ) {
            self::clear_url( $url, $with_pagination );
            return;
        }
        if ( null === self::$purge_queue ) {
            self::$purge_queue = array();
            add_action( 'shutdown', array( __CLASS__, 'drain_purge_queue' ), 3 );
        }
        $mode = $with_pagination ? 2 : 1;
        if ( ! isset( self::$purge_queue[ $url ] ) || $mode > self::$purge_queue[ $url ] ) {
            self::$purge_queue[ $url ] = $mode;
        }
    }

    /**
     * Shutdown drain for queued purges. Flushes the response first on FPM so
     * the visitor/editor never waits on globs or hosting/CDN purge hooks.
     * Public because it's a hook callback; safe to call repeatedly.
     */
    public static function drain_purge_queue() {
        if ( empty( self::$purge_queue ) ) {
            self::$purge_queue = null;
            return;
        }
        $queue                = self::$purge_queue;
        self::$purge_queue    = null;
        self::$purge_draining = true;

        if ( function_exists( 'fastcgi_finish_request' )
            && ! ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
            && ( ! defined( 'WP_CLI' ) || ! WP_CLI ) ) {
            @fastcgi_finish_request();
        }

        foreach ( $queue as $url => $mode ) {
            self::clear_url( $url, 2 === $mode );
        }
    }

    /* ───────────────────────────────────────────────
     *  Cache invalidation
     * ─────────────────────────────────────────────── */

    /**
     * Guarded handler for the `deleted_post` hook. WordPress fires this for
     * EVERY post type — revisions, auto-drafts, trashed items, transient-style
     * CPTs, Action Scheduler rows, oEmbed caches, nav-menu items, etc. Binding
     * clear_all() directly to it meant routine background deletions (especially
     * the cleanup crons that run on every wp-cron tick) wiped the whole cache
     * and restarted the preloader — the invalidation loop behind the
     * "Excessive cache purges" warning.
     *
     * We now clear only when a genuinely public, front-end-visible post is
     * deleted, and we do NOT auto-restart the crawl from here (the next
     * scheduled preload tick repopulates gradually), so a deletion can never
     * thrash the cache.
     *
     * @param int          $post_id Deleted post ID.
     * @param WP_Post|null $post    Deleted post object (WP 5.5+).
     */
    public static function on_deleted_post( $post_id, $post = null ) {

        // Revisions / autosaves never affect public output.
        if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
            return;
        }

        $type   = ( $post instanceof WP_Post ) ? $post->post_type   : get_post_type( $post_id );
        $status = ( $post instanceof WP_Post ) ? $post->post_status : '';

        if ( '' === (string) $type ) {
            return;
        }

        // Never-public / machinery post types that churn in the background.
        $ignored_types = array(
            'revision', 'nav_menu_item', 'auto-draft', 'oembed_cache',
            'customize_changeset', 'custom_css', 'scheduled-action',
            'shop_order_refund', 'wp_global_styles', 'wp_navigation',
            'wp_template', 'wp_template_part', 'wp_font_face', 'wp_font_family',
        );
        $ignored_types = (array) apply_filters( 'easyopt_purge_ignored_post_types', $ignored_types );
        if ( in_array( (string) $type, $ignored_types, true ) ) {
            return;
        }

        // Auto-drafts were never published and never cached.
        if ( 'auto-draft' === (string) $status ) {
            return;
        }

        // Only publicly-queryable post types can have appeared in front-end HTML.
        $type_obj = get_post_type_object( $type );
        if ( $type_obj && empty( $type_obj->public ) ) {
            return;
        }

        // Genuine public-content deletion → clear, but DON'T auto-restart the
        // crawl, so a deletion can never trigger a clear→preload→delete loop.
        if ( (bool) apply_filters( 'easyopt_clear_all_on_post_delete', true, $post_id, $type ) ) {
            self::clear_all( false );
        }
    }

    public static function clear_all( $restart_preload = true ) {
        // touch 5+ cache-affecting options (each firing its own
        // update_option_X hook), and content changes can fire save_post +
        // option updates back-to-back. Without this guard, clear_all() would
        // run the full directory wipe + transient deletes + preload restart
        // 5+ times in one request.
        static $did_clear = false;
        if ( $did_clear ) {
            return;
        }
        $did_clear = true;

        // Diagnostics (W1/W7/E1): record this full clear, log its origin, and
        // warn if full clears are happening too often (the signature of an
        // invalidation loop). O(1): one non-autoloaded option write on a path
        // that is rare by design. Runs before the counter reset below so it
        // can report the pre-clear page count.
        self::record_clear_event();

        if ( '' === self::$root_path || ! is_dir( self::$root_path ) ) {
            // Still record that a clear was requested so subsequent code
            // (preload restart, etc.) runs even when root_path isn't
            // populated yet — but skip the rmdir.
            update_option( 'easyopt_cache_cleared_at', time(), false );
            delete_transient( 'easyopt_cache_stats' );
            delete_transient( 'easyopt_htaccess_synced' );
            do_action( 'easyopt_cache_cleared_all' );
            return;
        }
        // Remove cached pages for THIS site. On multisite this wipes only the
        // current site's subtree, never the rest of the network.
        self::clear_site_files();
        // Touch a marker so admins/devs can see the last clear.
        update_option( 'easyopt_cache_cleared_at', time(), false );
        // Stats and reconcile state are now stale.
        delete_transient( 'easyopt_cache_stats' );
        delete_transient( 'easyopt_htaccess_synced' );

        // ── Reset preload progress ──
        // Anything we previously preloaded was just deleted, so the old
        // counters would be misleading. Wipe them.
        if ( class_exists( 'EasyOpt_Cache_Preload' ) ) {
            EasyOpt_Cache_Preload::stop();
        }
        update_option( 'easyopt_cache_preload_total',  0,       false );
        update_option( 'easyopt_cache_preload_status', 'idle',  false );

        // (2.5.4 / perf #8) Reset the persisted image-dimension map alongside
        // the pages that were rendered from it — a full clear is the natural
        // re-measure point and keeps the map from ever serving stale sizes.
        if ( class_exists( 'EasyOpt_LazyLoad' ) && method_exists( 'EasyOpt_LazyLoad', 'flush_dim_store' ) ) {
            EasyOpt_LazyLoad::flush_dim_store();
        }

        // Notify integrations (Cloudflare, etc.) that the entire cache went away.
        do_action( 'easyopt_cache_cleared_all' );

        // ── Auto-restart preload after clear ──
        // Action Scheduler via Preload::start(). With WP-Cron, the
        // restart event was visitor-triggered: on low-traffic sites the
        // preload could sit pending for hours until a request happened
        // to fire wp-cron. We worked around that with `spawn_cron()`,
        // which itself does a self-loopback request adding 1–3 s of
        // latency to the request that triggered the clear (typically a
        // save_post → redirect).
        //
        // AS's async runner fires via admin-ajax loopback on the very
        // next request (or sooner), independent of WP-Cron's visitor
        // trigger. `start()` now just inserts a single async action and
        // returns — no spawn_cron, no schedule writes, no loopback in
        // this request's path. So clear_all() stays fast even when
        // invoked from save_post, comment_post, on_lcp_saved, etc.
        if ( $restart_preload && class_exists( 'EasyOpt_Cache_Preload' ) && (int) EasyOpt_Config::get( 'cache_preload', 0 ) ) {
            // Start preload directly — no WP-Cron delay. start() is fast
            // (4 option writes + 1 non-blocking loopback) and the static
            // $did_start guard makes repeat calls in the same request a no-op.
            // The old wp_schedule_single_event approach depended on WP-Cron
            // which is visitor-triggered and blocked by the cron throttle.
            EasyOpt_Cache_Preload::start();
        }
    }

    /**
     * Diagnostics for full cache clears — W1 (excessive purges), W7 (reset
     * after growth), E1 (origin of the clear). Lightweight and rare-path only.
     */
    private static function record_clear_event() {
        $now = time();
        $log = get_option( 'easyopt_clear_log', array() );
        if ( ! is_array( $log ) ) {
            $log = array();
        }
        $log[] = $now;
        if ( count( $log ) > 10 ) {
            $log = array_slice( $log, -10 );
        }
        update_option( 'easyopt_clear_log', $log, false );

        if ( ! class_exists( 'EasyOpt_Debug_Log' ) ) {
            return;
        }

        // Page count BEFORE the impending counter reset.
        $pages = class_exists( 'EasyOpt_Cache_Counter' ) ? (int) EasyOpt_Cache_Counter::get() : -1;

        // E1 — origin of this full clear (immediate caller from the stack).
        // (2.5.4 / perf #50) Only walk the stack when the info line will
        // actually be written — debug_backtrace isn't free. The W1/W7 warn
        // checks below still run regardless of the info level.
        if ( ! method_exists( 'EasyOpt_Debug_Log', 'level_enabled' ) || EasyOpt_Debug_Log::level_enabled( 'info' ) ) {
            $origin = 'unknown';
            $bt = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 4 ); // 0=here 1=clear_all 2=caller
            if ( isset( $bt[2]['function'] ) ) {
                $origin = ( isset( $bt[2]['class'] ) ? $bt[2]['class'] . '::' : '' ) . $bt[2]['function'];
            }
            EasyOpt_Debug_Log::info( 'cache', sprintf( 'Full cache clear (pages_before=%d, via=%s).', $pages, $origin ) );
        }

        // W1 — excessive purges in a short window = invalidation-loop signature.
        $window = (int) apply_filters( 'easyopt_purge_window', 600 );
        $limit  = (int) apply_filters( 'easyopt_purge_limit', 5 );
        $recent = 0;
        foreach ( $log as $ts ) {
            if ( ( $now - (int) $ts ) <= $window ) {
                $recent++;
            }
        }
        if ( $recent >= $limit && false === get_transient( 'easyopt_purge_warned' ) ) {
            // Emit at most once per window so a real loop doesn't flood the log
            // with identical lines (the user only needs to be told once).
            set_transient( 'easyopt_purge_warned', 1, $window );
            EasyOpt_Debug_Log::warn( 'cache', sprintf(
                'Excessive cache purges: %d full clears in %ds — preload progress is being discarded. Check for an invalidation loop.',
                $recent, $window
            ) );
            // W7 — a clear landed while the cache had meaningfully grown.
            if ( $pages >= (int) apply_filters( 'easyopt_purge_growth_floor', 5 ) ) {
                EasyOpt_Debug_Log::warn( 'cache', sprintf(
                    'Cache reset after growth (%d pages) during repeated purges — likely an invalidation loop.',
                    $pages
                ) );
            }
        }
    }

    public static function clear_url( $url, $with_pagination = false ) {
        $path = wp_parse_url( $url, PHP_URL_PATH );
        if ( ! is_string( $path ) || '' === $path ) {
            return;
        }
        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! is_string( $host ) || '' === $host ) {
            $host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        }
        $host = strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', $host ) );

        $segments = array_filter( explode( '/', $path ), function ( $s ) { return '' !== $s; } );
        $segments = array_map( function ( $s ) { return sanitize_file_name( rawurldecode( $s ) ); }, $segments );
        $clean    = implode( '/', $segments );

        $dir = self::$root_path . $host . '/';
        if ( '' !== $clean ) {
            $dir .= $clean . '/';
        }

        // Wildcard glob over all variants — handles desktop, mobile, every
        // logged-in role tag, and every include-cookie key without us
        // needing to enumerate them.
        //
        // (2.6.0) $emptied counts DIRECTORIES that actually lost their page
        // files — the same unit get_stats() counts ($page_dirs, one entry per
        // directory regardless of how many variants live in it). It is derived
        // entirely from the glob results already in hand, so it costs no extra
        // filesystem I/O. See the decrement block below for why this replaced
        // the old unconditional one-per-URL decrement.
        $emptied = 0;

        if ( is_dir( $dir ) ) {
            $files = (array) glob( $dir . 'index*.html*' );
            foreach ( $files as $f ) {
                @unlink( $f );
            }
            if ( ! empty( $files ) ) {
                $emptied++;
            }

            // Archives are paginated: /path/page/2/, /page/3/, …  Purge ONLY
            // those paginated copies (one extra glob), never the whole tree.
            if ( $with_pagination ) {
                $page_base = 'page';
                if ( isset( $GLOBALS['wp_rewrite'] ) && is_object( $GLOBALS['wp_rewrite'] )
                     && ! empty( $GLOBALS['wp_rewrite']->pagination_base ) ) {
                    $page_base = sanitize_file_name( (string) $GLOBALS['wp_rewrite']->pagination_base );
                }
                // (2.5.4 / perf #3b) Cheap dir check before the wildcard glob —
                // most purged URLs have never been paginated.
                if ( is_dir( $dir . $page_base ) ) {
                    $paged = (array) glob( $dir . $page_base . '/*/index*.html*' );
                    // (2.6.0) Each /page/N/ directory is its own entry in the
                    // get_stats() count, so a paginated purge that removes N of
                    // them has to decrement N times — not once. dirname() over
                    // an array we already hold; no extra stat() calls.
                    $paged_dirs = array();
                    foreach ( $paged as $f ) {
                        @unlink( $f );
                        $paged_dirs[ dirname( $f ) ] = true;
                    }
                    $emptied += count( $paged_dirs );
                }
            }
        }

        // (2.5.4 / perf #3d) The stats transient only needs one delete per
        // request no matter how many URLs a fan-out clears.
        if ( ! self::$stats_transient_cleared ) {
            self::$stats_transient_cleared = true;
            delete_transient( 'easyopt_cache_stats' );
        }

        // (2.6.0) Decrement the O(1) page counter HERE, by the number of
        // directories actually emptied, instead of letting the counter hook
        // itself to easyopt_cache_cleared_url and subtract exactly 1 per call.
        //
        // Two bugs made that old wiring the single largest source of the
        // "Pages cached barely moves" report:
        //
        //   1. It fired even when the URL had nothing on disk. One post save
        //      fans out to the permalink, the home page and every taxonomy /
        //      author / date archive (see on_save_post), so a save could
        //      subtract 5-15 from a counter that should not have moved at all.
        //      With the GREATEST(0, …) floor in decrement(), repeated saves
        //      pinned the displayed number at 0 while the cache filled up.
        //   2. A paginated purge deleted N directories and still subtracted 1.
        //
        // Both directions of error were live simultaneously, which is why the
        // drift looked random and then jumped when the daily reconcile landed.
        //
        // This is strictly LESS work than before: on the common "nothing was
        // cached" purge it performs zero option writes where it used to
        // perform one UPDATE + one wp_cache_delete per URL, on the shutdown
        // drain of every post save.
        if ( $emptied > 0 && class_exists( 'EasyOpt_Cache_Counter' )
             && method_exists( 'EasyOpt_Cache_Counter', 'decrement' ) ) {
            EasyOpt_Cache_Counter::decrement( $emptied );
        }

        // Notify integrations.
        // (2.5.4 / perf #55) Suppressed for preloader-originated re-learns —
        // see clear_learned_url().
        if ( ! self::$suppress_url_purge_hooks ) {
            // (2.6.0) The action still fires unconditionally — Cloudflare
            // (class-easyopt-cloudflare.php) and the server-cache purge
            // (class-easyopt-hosting.php) are bound to it and SHOULD run even
            // when we had nothing on local disk, because their caches are
            // independent of ours. Only the page counter was unhooked from it;
            // see EasyOpt_Cache_Counter::on_url_cleared() for how third-party
            // callers of this action keep their decrement.
            self::$counter_owns_decrement = true;
            do_action( 'easyopt_cache_cleared_url', $url );
            self::$counter_owns_decrement = false;
        }
    }

    /**
     * (2.6.0) True while clear_url() is dispatching easyopt_cache_cleared_url
     * for a purge whose counter decrement it has ALREADY applied precisely.
     * EasyOpt_Cache_Counter's listener checks this so it stays a no-op for our
     * own purges while still decrementing for third-party code that fires the
     * action directly.
     *
     * @var bool
     */
    private static $counter_owns_decrement = false;

    /** @return bool Whether clear_url() already handled this purge's decrement. */
    public static function counter_decrement_handled() {
        return self::$counter_owns_decrement;
    }

    /**
     * Clear cache files for a given URL-type (e.g. 'front', 'page-12').
     * Used by LCP integration.
     */
    public static function clear_url_type( $url_type ) {

        if ( '' === $url_type ) {
            return;
        }

        // 'front' = home page.
        if ( 'front' === $url_type ) {
            self::clear_url( home_url( '/' ) );
            return;
        }

        // 'page-{id}' or 'post' types — try to map to a permalink.
        if ( preg_match( '/^page-(\d+)$/', $url_type, $m ) ) {
            $link = get_permalink( (int) $m[1] );
            if ( $link ) {
                self::clear_url( $link );
            }
            return;
        }

        // For broad types ('product', 'category', 'archive', '404', …) we do
        // NOT clear anything. Previously this fell through to clear_all(),
        // which — combined with the preload auto-restart inside clear_all() —
        // created a self-perpetuating loop: an LCP report on any non-page
        // type wiped the whole cache and re-crawled the sitemap, so the cache
        // could never accumulate (count stuck at 0–10, queue stuck at 1000+).
        //
        // The LCP preload tag is applied at render time whenever a row exists,
        // so all pages of this type cached AFTER the row was saved already
        // carry it. The one page that was cached just before the row existed
        // is refreshed individually via on_lcp_saved()'s clear_url($url). Any
        // remaining stale copy simply refreshes on its normal TTL. This keeps
        // the LCP optimisation fully working while removing the cache wipe.
    }

    /**
     * `post_updated` handler. Fires with the post objects BEFORE and AFTER the
     * update, which lets us purge the OLD permalink on a slug change (save_post
     * alone can't — by the time it fires the slug is already the new one).
     *
     * @param int          $post_id     Post ID.
     * @param WP_Post|null $post_after  Post object after the update.
     * @param WP_Post|null $post_before Post object before the update.
     */
    public static function on_post_updated( $post_id, $post_after = null, $post_before = null ) {
        unset( $post_after );
        $old_url = '';
        if ( $post_before instanceof WP_Post && 'publish' === $post_before->post_status ) {
            $link = get_permalink( $post_before );
            if ( $link && ! is_wp_error( $link ) ) {
                $old_url = $link;
            }
        }
        self::purge_for_post( $post_id, $old_url );
    }

    /**
     * `save_post` catch-all for fresh inserts (REST/WP-CLI) that never fire
     * post_updated. purge_for_post() is per-request guarded, so on a normal
     * edit (where post_updated already ran) this is a no-op.
     */
    public static function on_save_post( $post_id ) {
        self::purge_for_post( $post_id, '' );
    }

    /**
     * Targeted invalidation for a single post and everything that lists it.
     *
     * Purges (deduplicated, one pass), never wiping the whole cache and never
     * restarting the preload crawl:
     *   - the post permalink (and the OLD permalink on a slug change)
     *   - the homepage + its pagination
     *   - the blog posts page (page_for_posts) for the `post` type
     *   - the post-type archive + its pagination (custom post types)
     *   - the author archive + its pagination
     *   - the date archives (year / month / day) for the `post` type
     *   - every term archive the post belongs to + ancestors + their pagination
     *
     * @param int    $post_id Post ID.
     * @param string $old_url Old permalink to purge (slug change), or ''.
     */
    private static function purge_for_post( $post_id, $old_url = '' ) {

        // Per-request guard: post_updated + save_post can both fire for one
        // edit. post_updated runs first (and carries $old_url) and does the
        // work; the save_post pass is then skipped.
        static $purged = array();
        $post_id = (int) $post_id;
        if ( isset( $purged[ $post_id ] ) ) {
            return;
        }

        // (2.5.4 / perf #29) Bulk cleanup in progress — see set_bulk_purge_mode().
        if ( self::$bulk_purge_mode ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        $post_type = get_post_type( $post_id );
        if ( '' === (string) $post_type || self::is_ignored_post_type( $post_type ) ) {
            return;
        }

        // Only public post types can ever have appeared in front-end HTML.
        $type_obj = get_post_type_object( $post_type );
        if ( $type_obj && empty( $type_obj->public ) ) {
            return;
        }

        $status = get_post_status( $post_id );
        // Skip drafts/pending unless we were handed an old URL to clean up
        // (e.g. a published post moved back to draft → unpublish the old URL).
        // 'trash' is allowed so trashing a published post clears it.
        if ( 'publish' !== $status && 'private' !== $status && 'trash' !== $status && '' === $old_url ) {
            return;
        }

        $purged[ $post_id ] = true;

        // ── Single pages (no pagination) ──
        $page_urls = array();
        if ( '' !== $old_url ) {
            $page_urls[] = $old_url;
        }
        $new_url = get_permalink( $post_id );
        if ( $new_url && ! is_wp_error( $new_url ) ) {
            $page_urls[] = $new_url;
        }

        // ── Archives (purged WITH their pagination) ──
        $archive_urls   = array();
        $archive_urls[] = home_url( '/' );

        if ( 'post' === $post_type ) {
            $blog_page = (int) get_option( 'page_for_posts' );
            if ( $blog_page > 0 ) {
                $link = get_permalink( $blog_page );
                if ( $link ) {
                    $archive_urls[] = $link;
                }
            }
            // Date archives for the post.
            $y = (int) get_the_time( 'Y', $post_id );
            $m = (int) get_the_time( 'm', $post_id );
            $d = (int) get_the_time( 'd', $post_id );
            if ( $y > 0 ) {
                $archive_urls[] = get_year_link( $y );
                if ( $m > 0 ) {
                    $archive_urls[] = get_month_link( $y, $m );
                    if ( $d > 0 ) {
                        $archive_urls[] = get_day_link( $y, $m, $d );
                    }
                }
            }
        } else {
            // Custom post type archive.
            $cpt_archive = get_post_type_archive_link( $post_type );
            if ( $cpt_archive ) {
                $archive_urls[] = $cpt_archive;
            }
        }

        // Author archive.
        $author_id = (int) get_post_field( 'post_author', $post_id );
        if ( $author_id > 0 ) {
            $author_url = get_author_posts_url( $author_id );
            if ( $author_url ) {
                $archive_urls[] = $author_url;
            }
        }

        // Term archives (+ ancestors) for every taxonomy this post type has.
        $taxonomies = get_object_taxonomies( $post_type );
        foreach ( $taxonomies as $tax ) {
            $tax_obj = get_taxonomy( $tax );
            if ( ! $tax_obj || empty( $tax_obj->public ) ) {
                continue;
            }
            $terms = wp_get_post_terms( $post_id, $tax, array( 'fields' => 'all' ) );
            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                continue;
            }
            foreach ( $terms as $term ) {
                $link = get_term_link( $term );
                if ( ! is_wp_error( $link ) ) {
                    $archive_urls[] = $link;
                }
                // Ancestor term archives — a child-post change can alter the
                // parent archive's listings/counts.
                if ( (int) $term->parent > 0 ) {
                    $ancestors = get_ancestors( $term->term_id, $tax, 'taxonomy' );
                    foreach ( $ancestors as $anc_id ) {
                        $anc_link = get_term_link( (int) $anc_id, $tax );
                        if ( ! is_wp_error( $anc_link ) ) {
                            $archive_urls[] = $anc_link;
                        }
                    }
                }
            }
        }

        /**
         * Extend the post-purge archive set (e.g. WPML/Polylang translated
         * URLs). Returned URLs are purged WITH pagination.
         *
         * @param array $archive_urls Archive URLs to purge.
         * @param int   $post_id      Post ID.
         */
        $archive_urls = (array) apply_filters( 'easyopt_purge_post_archive_urls', $archive_urls, $post_id );

        // (2.5.4 / perf #3) Queue everything for the single shutdown drain —
        // the editor/visitor response no longer waits on globs or the per-URL
        // hosting/Cloudflare integration hooks. Dedup happens in the queue.
        foreach ( array_filter( $page_urls ) as $u ) {
            self::queue_url_purge( $u );
        }
        foreach ( array_filter( $archive_urls ) as $u ) {
            self::queue_url_purge( $u, true );
        }
    }

    /** Shared "never affects public HTML" post-type denylist. */
    private static function is_ignored_post_type( $post_type ) {
        $ignored = array(
            'revision', 'nav_menu_item', 'auto-draft', 'oembed_cache',
            'customize_changeset', 'custom_css', 'scheduled-action',
            'shop_order', 'shop_order_refund', 'wp_global_styles',
            'wp_navigation', 'wp_template', 'wp_template_part',
            'wp_font_face', 'wp_font_family',
        );
        $ignored = (array) apply_filters( 'easyopt_purge_ignored_post_types', $ignored );
        return in_array( (string) $post_type, $ignored, true );
    }

    /**
     * New comment posted — purge ONLY the affected post page (approved
     * comments only, so spam never thrashes the cache).
     */
    public static function on_comment( $comment_id, $approved ) {
        if ( 1 !== (int) $approved ) {
            return;
        }
        self::purge_comment_post( $comment_id );
    }

    /**
     * Comment status changed (approve / unapprove / spam / trash) — purge ONLY
     * the affected post page. Replaces the previous full clear_all().
     *
     * @param int    $comment_id Comment ID.
     * @param string $status     New status (unused).
     */
    public static function on_comment_status( $comment_id, $status = '' ) {
        unset( $status );
        self::purge_comment_post( $comment_id );
    }

    /** Comment edited in admin — purge ONLY the affected post page. */
    public static function on_edit_comment( $comment_id ) {
        self::purge_comment_post( $comment_id );
    }

    /** Resolve a comment to its post and purge that single page. */
    private static function purge_comment_post( $comment_id ) {
        // (2.5.4 / perf #29) Bulk comment cleanup — nothing rendered changes.
        if ( self::$bulk_purge_mode ) {
            return;
        }
        $comment = get_comment( $comment_id );
        if ( $comment && $comment->comment_post_ID ) {
            $link = get_permalink( (int) $comment->comment_post_ID );
            if ( $link ) {
                // (2.5.4 / perf #3) Deferred to the shutdown drain.
                self::queue_url_purge( $link );
            }
        }
    }

    /**
     * Taxonomy term created / edited / deleted — refresh the term archive, its
     * pagination, ancestor archives and the homepage. Targeted by design: we
     * do NOT wipe the whole cache. Public taxonomies only; skipped while
     * WordPress is importing/installing to avoid bulk-import purge storms.
     *
     * @param int    $term_id  Term ID.
     * @param int    $tt_id    Term taxonomy ID (unused).
     * @param string $taxonomy Taxonomy slug.
     */
    public static function on_term_change( $term_id, $tt_id, $taxonomy ) {
        unset( $tt_id );

        if ( function_exists( 'wp_installing' ) && wp_installing() ) {
            return;
        }

        $tax_obj = get_taxonomy( $taxonomy );
        if ( ! $tax_obj || empty( $tax_obj->public ) || empty( $tax_obj->publicly_queryable ) ) {
            return;
        }

        $urls = array( home_url( '/' ) );

        $link = get_term_link( (int) $term_id, $taxonomy );
        if ( ! is_wp_error( $link ) ) {
            $urls[] = $link;
        }

        // Ancestor archives.
        $ancestors = get_ancestors( (int) $term_id, $taxonomy, 'taxonomy' );
        foreach ( $ancestors as $anc_id ) {
            $anc_link = get_term_link( (int) $anc_id, $taxonomy );
            if ( ! is_wp_error( $anc_link ) ) {
                $urls[] = $anc_link;
            }
        }

        // (2.5.4 / perf #3) Deferred to the shutdown drain (deduped there).
        foreach ( array_filter( $urls ) as $u ) {
            self::queue_url_purge( $u, true );
        }
    }

    /**
     * Author profile updated — refresh that author's archive (and pagination)
     * so a changed display name / bio shows on the archive. Kept light: we
     * don't fan out to every one of the author's posts.
     */
    public static function on_profile_update( $user_id ) {
        $author_url = get_author_posts_url( (int) $user_id );
        if ( $author_url ) {
            // (2.5.4 / perf #3) Deferred to the shutdown drain.
            self::queue_url_purge( $author_url, true );
        }
    }

    /**
     * Attachment (media) edited — refresh the attachment page and its parent
     * post, if any. Intentionally minimal so bulk media-library edits don't
     * generate large purges.
     */
    public static function on_edit_attachment( $post_id ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        $att_url = get_permalink( (int) $post_id );
        if ( $att_url && ! is_wp_error( $att_url ) ) {
            // (2.5.4 / perf #3) Deferred to the shutdown drain.
            self::queue_url_purge( $att_url );
        }
        $parent_id = (int) get_post_field( 'post_parent', $post_id );
        if ( $parent_id > 0 ) {
            $parent_url = get_permalink( $parent_id );
            if ( $parent_url && ! is_wp_error( $parent_url ) ) {
                self::queue_url_purge( $parent_url );
            }
        }
    }

    /**
     * (2.4.4) Refresh ONE page after the beacon learned new render-time data
     * (LCP element or above-the-fold fonts) so the optimization appears on the
     * next request instead of waiting for TTL. Targeted: a single URL, no
     * pagination, no preload restart, no clear_all.
     *
     * Skipped on skip_live_buffer hosts, where a cache MISS serves
     * un-optimized HTML — clearing there would open a raw-page window until the
     * (often blocked) preloader catches up. On those hosts the data still
     * applies on the page's next natural render. The per-visit clear loop the
     * old no-op guarded against is independently prevented by store_lcp()'s
     * srcset-based stable identity, so a normal host clears at most once per
     * genuine change.
     */
    public static function clear_learned_url( $url ) {
        if ( '' === (string) $url ) {
            return;
        }
        if ( class_exists( 'EasyOpt_Cache_Preload' )
            && EasyOpt_Cache_Preload::live_buffer_skipped() ) {
            return;
        }
        // (2.5.4 / perf #55) During a preloader-originated render the cache
        // file for this URL is being (re)written by the very same run — the
        // local file delete is still wanted so the fresh optimized copy
        // replaces the pre-learning one, but firing hosting/Cloudflare edge
        // purges per warmed URL turns a preload run into an edge-purge storm.
        // Suppress ONLY the integration hooks for runner renders; a normal
        // visitor-triggered learn still notifies every layer exactly as before.
        if ( class_exists( 'EasyOpt_Cache_Preload' )
            && method_exists( 'EasyOpt_Cache_Preload', 'is_runner_render' )
            && EasyOpt_Cache_Preload::is_runner_render() ) {
            self::$suppress_url_purge_hooks = true;
            self::clear_url( $url );
            self::$suppress_url_purge_hooks = false;
            return;
        }
        self::clear_url( $url );
    }

    public static function on_lcp_saved( $url_type, $viewport, $url = '' ) {
        unset( $url_type, $viewport );

        // (2.4.4) Refresh just the measured URL so the freshly-learned LCP
        // preload appears on the next request. store_lcp() fires this only on a
        // NEW or genuinely CHANGED element (stable srcset identity), so there is
        // no per-visit clear loop, and clear_learned_url() skips skip_live_buffer
        // hosts to avoid a raw-page window. No clear_all, no preload restart.
        self::clear_learned_url( $url );
    }

    /* ───────────────────────────────────────────────
     *  Filesystem helpers
     * ─────────────────────────────────────────────── */

    /**
     * Recursively delete every file inside $dir. If $remove_self is false
     * (default) the directory itself is preserved.
     */
    private static function rmdir_recursive( $dir, $remove_self = false, $preserve = array() ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $items = @scandir( $dir );
        if ( false === $items ) {
            return;
        }
        foreach ( $items as $item ) {
            if ( '.' === $item || '..' === $item ) {
                continue;
            }
            // Multisite: preserve sibling-site directories (top level only —
            // children recurse with an empty preserve list).
            if ( ! empty( $preserve ) && in_array( $item, $preserve, true ) ) {
                continue;
            }
            $full = $dir . '/' . $item;
            if ( is_dir( $full ) ) {
                self::rmdir_recursive( $full, true );
            } elseif ( is_file( $full ) ) {
                @unlink( $full );
            }
        }
        if ( $remove_self ) {
            @rmdir( $dir );
        }
    }

    /**
     * Remove cached page files for the CURRENT site only.
     *
     * Single-site: wipes the whole cache root (fastest path).
     *
     * Multisite: the cache is keyed by host + URL path, so each site already
     * lives under its own subtree and we remove only that subtree — clearing
     * one site never touches another:
     *   - Subdomain installs and mapped domains: host is unique → wipe
     *     <root>/<host>/.
     *   - Subdirectory subsites (home path like /shop): wipe
     *     <root>/<host>/<path>/.
     *   - Subdirectory MAIN site (home path "/"): its files live directly under
     *     <root>/<host>/ with subsites nested inside, so we wipe the main
     *     site's files while PRESERVING the sibling subsite directories.
     */
    private static function clear_site_files() {

        // Top-level entries that survive a full clear: the per-site drop-in
        // configs (so multisite serving stays correct between saves), the
        // dir-hardening files, the self-test sandbox, and — crucially — the
        // `css/` directory. `css/` holds the Used CSS cache and the collected-
        // fonts sidecars (.eo-fonts.json), which are keyed by page TYPE and
        // remain valid across a page-cache purge (the CSS/fonts themselves
        // didn't change). Wiping them on every clear forced the expensive RUCSS
        // DOM-walk to re-run and the font beacon to re-collect after every
        // single cache clear. They are now invalidated only by their own
        // triggers: content/theme/plugin changes, a new ATF-font set, or the
        // explicit "Clear used CSS" / "Clear fonts data" buttons. Cached PAGES
        // all live under per-host subdirectories and are still wiped.
        $keep = array( 'config', '.htaccess', 'index.html', 'css' );

        if ( ! is_multisite() ) {
            self::rmdir_recursive( self::$root_path, false, $keep );
            return;
        }

        $home = home_url( '/' );
        $host = strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', (string) wp_parse_url( $home, PHP_URL_HOST ) ) );
        if ( '' === $host ) {
            // Can't resolve the host — fall back to a full wipe rather than
            // silently leaving stale pages behind.
            self::rmdir_recursive( self::$root_path, false, $keep );
            return;
        }

        $host_dir   = self::$root_path . $host . '/';
        $path_clean = trim( (string) wp_parse_url( $home, PHP_URL_PATH ), '/' );

        if ( '' !== $path_clean ) {
            // Subdirectory subsite — wipe its own nested directory.
            $segments = array_filter( explode( '/', $path_clean ), function ( $s ) { return '' !== $s; } );
            $segments = array_map( function ( $s ) { return sanitize_file_name( rawurldecode( $s ) ); }, $segments );
            $site_dir = $host_dir . implode( '/', $segments ) . '/';
            self::rmdir_recursive( $site_dir, true );
            return;
        }

        // Main site (or a subdomain): wipe this host's tree but keep sibling
        // subsite directories that share the host on subdirectory networks.
        $preserve = self::network_sibling_dirs( $host );
        self::rmdir_recursive( $host_dir, false, $preserve );
    }

    /**
     * First-path-segment directory names of OTHER sites that share the given
     * host (subdirectory multisite). Used to avoid wiping sibling subsites
     * when clearing the main site.
     *
     * @param string $host Sanitized host of the current site.
     * @return array<int,string>
     */
    private static function network_sibling_dirs( $host ) {
        $preserve   = array();
        $current_id = get_current_blog_id();

        if ( ! function_exists( 'get_sites' ) ) {
            return $preserve;
        }

        // (2.3.3) Network-cached map: the get_home_url() loop below costs up
        // to one switch_to_blog-class lookup PER SITE on every full clear —
        // thousands of queries on big networks. Cache the computed result
        // per (host, current site) in a network option; invalidated by the
        // site-lifecycle hooks registered in flush_network_sibling_cache().
        $cache_key = 'easyopt_sibling_dirs';
        $cached    = get_site_option( $cache_key, array() );
        $entry_key = $host . '|' . $current_id;
        if ( is_array( $cached ) && isset( $cached[ $entry_key ] ) && is_array( $cached[ $entry_key ] ) ) {
            return $cached[ $entry_key ];
        }

        $sites = get_sites( array(
            'number'   => 2000,
            'fields'   => 'ids',
            'deleted'  => 0,
            'spam'     => 0,
            'archived' => 0,
        ) );

        foreach ( (array) $sites as $site_id ) {
            $site_id = (int) $site_id;
            if ( $site_id === $current_id ) {
                continue;
            }
            $home = get_home_url( $site_id, '/' );
            $h    = strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', (string) wp_parse_url( $home, PHP_URL_HOST ) ) );
            if ( $h !== $host ) {
                continue; // Different host → different top-level dir, no conflict.
            }
            $p = trim( (string) wp_parse_url( $home, PHP_URL_PATH ), '/' );
            if ( '' === $p ) {
                continue;
            }
            $first = strtok( $p, '/' );
            if ( false !== $first && '' !== $first ) {
                $preserve[] = sanitize_file_name( rawurldecode( $first ) );
            }
        }

        $preserve = array_values( array_unique( array_filter( $preserve ) ) );

        if ( ! is_array( $cached ) ) {
            $cached = array();
        }
        $cached[ $entry_key ] = $preserve;
        update_site_option( $cache_key, $cached );

        return $preserve;
    }

    /**
     * Drop the cached sibling map when the network's site list changes.
     * Hooked (multisite only) from EasyOpt_Cache::init().
     *
     * @since 2.3.3
     */
    public static function flush_network_sibling_cache() {
        delete_site_option( 'easyopt_sibling_dirs' );
    }

}
