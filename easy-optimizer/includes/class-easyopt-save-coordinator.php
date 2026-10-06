<?php
/**
 * Side-effect coordinator for settings saves.
 *
 * Diffs changed settings and runs cache clears, htaccess writes,
 * drop-in installs, and preload restarts at most once per save.
 * Heavy work is deferred to after the response is sent.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Save_Coordinator {

    /** Options whose change requires the .htaccess file to be re-rendered. */
    private static $htaccess_affecting = array(
        'easyopt_cache',
        'easyopt_cache_mode',
        'easyopt_cache_browser_caching',
        'easyopt_cache_gzip',
    );

    /**
     * Options whose change should invalidate the entire HTML cache.
     * Mirrors the pre-1.7.0 `EasyOpt_Cache::html_affecting_options()` list,
     * but evaluated against the diff rather than per-option hooks.
     */
    private static $html_affecting = array(
        'easyopt_cache',
        // (2.5.7) Gzip now gates cache-file GENERATION, so flipping it must
        // wipe the tree — otherwise the serve paths keep finding (and, before
        // the toggle was honoured on serve, preferring) _gzip files that
        // should no longer exist.
        'easyopt_cache_gzip',
        'easyopt_cache_separate_mobile',
        'easyopt_cache_logged_in',
        'easyopt_cache_exclude_urls',
        'easyopt_cache_exclude_cookies',
        'easyopt_cache_strip_query_params',
        // (2.6.1) Changing which parameters key their own cache file changes
        // the FILENAME of every affected page, so everything already on disk
        // for those URLs is now unreachable (or, worse, still reachable at the
        // old unkeyed path). Wipe and let it rebuild.
        'easyopt_cache_query_strings',
        'easyopt_delay_js',
        'easyopt_delay_js_method',
        'easyopt_delay_js_exclude',
        'easyopt_delay_js_exclude_urls',
        'easyopt_delay_js_exclude_jquery',
        'easyopt_delay_js_include_inline',
        'easyopt_minify_css',
        'easyopt_minify_js',
        'easyopt_unused_css',
        'easyopt_unused_css_method',
        'easyopt_unused_css_behavior',
        'easyopt_unused_css_post_types_only',
        'easyopt_unused_css_exclude_selectors',
        'easyopt_unused_css_exclude_stylesheets',
        'easyopt_unused_css_exclude_urls',
        'easyopt_unused_css_include_inline',
        'easyopt_unused_css_passthrough_stylesheets', // (2.7.2)
        'easyopt_lazy_images',
        'easyopt_lazy_iframes',
        'easyopt_lazy_videos',
        'easyopt_add_missing_dims',
        'easyopt_dims_exclude',
        'easyopt_lazyload_exclude',
        'easyopt_lazyload_exclude_first',
        'easyopt_lazy_native',             // (2.7.2) native vs JS markup
        'easyopt_font_display_swap',
        'easyopt_preload_fonts',           // (2.7.2) font preload links
        'easyopt_lazyload_fonts',
        'easyopt_fonts_exclude',
        'easyopt_fonts_exclude_urls',
        'easyopt_img_opt',
        // (2.7.2) Cloud toggles change the served HTML too (CDN asset URLs,
        // the delayed-styles loader variant, the font strip) — a Settings
        // flip must not wait for the page cache to expire.
        'easyopt_cloud_assets',
        'easyopt_cloud_unused_css',
        'easyopt_cloud_assets_exclude',    // (2.7.2)
        'easyopt_images_picture',          // (2.7.2) <picture> AVIF/WebP markup
        'easyopt_preconnect',              // (2.7.2) resource hints
        'easyopt_image_exclude',
        'easyopt_elementor_bg_cdn',
        'easyopt_fluxcdn_api_key',
        'easyopt_fluxcdn_verified',
        'easyopt_fluxcdn_endpoint',
        'easyopt_fluxcdn_format',
        'easyopt_fluxcdn_quality',
        'easyopt_fluxcdn_max_width',
        'easyopt_fluxcdn_srcset_resize',
        'easyopt_fluxcdn_error_fallback',
        'easyopt_instant_preload',
        'easyopt_instant_preload_exclude_urls',
        'easyopt_instant_preload_exclude_selectors',
        'easyopt_instant_prerender',
        'easyopt_instant_eagerness',
        'easyopt_lcp_preload',
        'easyopt_lcp_exclude_urls',
        'easyopt_a11y_inputs', 'easyopt_a11y_links', 'easyopt_a11y_buttons',
        'easyopt_a11y_viewport', 'easyopt_a11y_role_elements',
        'easyopt_a11y_iframes', 'easyopt_a11y_progressbar', 'easyopt_a11y_tabindex',
        'easyopt_seo_crawlable_links', 'easyopt_seo_image_alts',
    );

    /**
     * Options whose change alters how the Used CSS is GENERATED (not just how
     * a page renders). A full-page wipe alone re-renders pages but re-injects
     * the existing .used.css, so these must additionally drop the Used CSS
     * cache so it rebuilds with the new rules. (fonts_exclude is intentionally
     * NOT here — since 2.4.4 it is applied at injection time from the always-
     * full .used.css, so a page re-render is enough.)
     */
    private static $used_css_affecting = array(
        'easyopt_unused_css_behavior',
        'easyopt_unused_css_exclude_selectors',
        'easyopt_unused_css_exclude_stylesheets',
        'easyopt_unused_css_exclude_urls',
        'easyopt_unused_css_include_inline',
        'easyopt_unused_css_post_types_only',
        'easyopt_unused_css_passthrough_stylesheets', // (2.7.2) copied whole vs tree-shaken
    );

    /** Deferred operations queued from the save listener, drained at shutdown. */
    private static $deferred = array(
        'clear_cache'      => false,
        'clear_used_css'   => false,
        'write_htaccess'   => false,
        'remove_htaccess'  => false,
        'restart_preload'  => false,
        'stop_preload'     => false,
    );

    /** Has the deferred drain already been scheduled? */
    private static $shutdown_registered = false;

    /** Boot — subscribe to the single saved-action. */
    public static function init() {
        add_action( EasyOpt_Config::ACTION_SAVED, array( __CLASS__, 'on_saved' ), 10, 3 );
    }

    /**
     * Diff the save and decide what needs to run.
     *
     * The drop-in install on cache-toggle runs SYNCHRONOUSLY here — it's
     * a safety guarantee for cases where another request lands between
     * this save and the user's next pageview. Everything else queues up
     * for the deferred drainer so the REST response returns fast.
     *
     * @param array    $new         Full new settings array.
     * @param array    $old         Full pre-save settings array.
     * @param string[] $changed     Keys whose value strictly changed.
     */
    public static function on_saved( $new, $old, $changed ) {
        if ( empty( $changed ) ) {
            return;
        }
        $changed_flip = array_flip( $changed );

        // ── 1. Cache toggle drop-in (SYNC, mandatory). ───────────────────
        if ( isset( $changed_flip['easyopt_cache'] ) && class_exists( 'EasyOpt_Advanced_Cache' ) ) {
            $cache_on = (int) ( isset( $new['easyopt_cache'] ) ? $new['easyopt_cache'] : 0 );
            if ( 1 === $cache_on ) {
                // is_enabled() reads from EasyOpt_Config which uses the
                // pre_option shim against the storage row. By this point
                // the new row is already committed, so is_enabled() will
                // see the new value — no force-install override needed.
                EasyOpt_Advanced_Cache::install();
            } else {
                EasyOpt_Advanced_Cache::uninstall();
            }
        }

        // ── 1b. Object Cache drop-in (SYNC, 2.4.7). ──────────────────────
        if ( class_exists( 'EasyOpt_Object_Cache_Manager' ) ) {
            $oc_keys = array(
                'easyopt_object_cache', 'easyopt_oc_client', 'easyopt_oc_host',
                'easyopt_oc_port', 'easyopt_oc_username', 'easyopt_oc_password',
                'easyopt_oc_database', 'easyopt_oc_prefix', 'easyopt_oc_tls',
            );
            $oc_touched = false;
            foreach ( $oc_keys as $ock ) {
                if ( isset( $changed_flip[ $ock ] ) ) {
                    $oc_touched = true;
                    break;
                }
            }
            if ( $oc_touched ) {
                if ( isset( $changed_flip['easyopt_object_cache'] ) ) {
                    $oc_on = (int) ( isset( $new['easyopt_object_cache'] ) ? $new['easyopt_object_cache'] : 0 );
                    if ( 1 === $oc_on ) {
                        EasyOpt_Object_Cache_Manager::install();
                    } else {
                        EasyOpt_Object_Cache_Manager::uninstall();
                    }
                } elseif ( EasyOpt_Object_Cache_Manager::is_enabled() && EasyOpt_Object_Cache_Manager::dropin_is_ours() ) {
                    // Connection settings changed while active — refresh the
                    // config the drop-in reads and flush so the next request
                    // reconnects cleanly with the new parameters. Note: this
                    // flush runs through the live connection made with the OLD
                    // settings, so it purges the OLD database/prefix location —
                    // exactly what we want: no orphaned keys left behind after
                    // a database or prefix change. (Flushes are prefix-scoped
                    // SCAN+UNLINK; we never FLUSHDB a shared server.)
                    EasyOpt_Object_Cache_Manager::write_config();
                    if ( function_exists( 'wp_cache_flush' ) ) {
                        wp_cache_flush();
                    }
                }
            }
        }

        // ── 1c. FluxCDN transform / Elementor-CDN changes (2.5.3). ───────
        // Elementor CSS and Used CSS embed SIGNED CDN URLs at generation
        // time, so toggling easyopt_elementor_bg_cdn or changing any
        // transform setting (quality/format/max-width) — or switching the
        // feature itself — makes those files stale. Regenerate immediately
        // on save (no Tools-menu step, no admin_init throttle): Elementor
        // regen just deletes its generated CSS (rebuilt on next view), and
        // the CDN zone purge is deferred to shutdown so it never slows the
        // save response.
        $flux_keys = array(
            'easyopt_img_opt', 'easyopt_elementor_bg_cdn', 'easyopt_cloud_active',
            'easyopt_fluxcdn_quality', 'easyopt_fluxcdn_format', 'easyopt_fluxcdn_max_width',
        );
        $flux_touched = false;
        foreach ( $flux_keys as $fk ) {
            if ( isset( $changed_flip[ $fk ] ) ) {
                $flux_touched = true;
                break;
            }
        }
        if ( $flux_touched && class_exists( 'EasyOpt_CDN' ) ) {
            EasyOpt_CDN::regenerate_elementor_css();
            if ( class_exists( 'EasyOpt_Unused_CSS' ) && method_exists( 'EasyOpt_Unused_CSS', 'clear_used_css_only' ) ) {
                EasyOpt_Unused_CSS::clear_used_css_only();
            }
            // Stored LCP rows hold the CDN URLs that were painted; a
            // transform/feature change makes them stale (preload would fetch
            // the OLD variant alongside the new one = double download).
            if ( class_exists( 'EasyOpt_LCP' ) && method_exists( 'EasyOpt_LCP', 'clear_all' ) ) {
                EasyOpt_LCP::clear_all();
            }
            if ( EasyOpt_CDN::is_connected() ) {
                EasyOpt_Config::set( 'easyopt_fluxcdn_transform_sig', EasyOpt_CDN::transform_signature() );
                add_action( 'shutdown', array( 'EasyOpt_CDN', 'remote_purge' ), 20 ); // after the response is released
            }
        }

        // ── 2. .htaccess re-render (DEFERRED, idempotent). ───────────────
        $needs_htaccess = false;
        foreach ( self::$htaccess_affecting as $k ) {
            if ( isset( $changed_flip[ $k ] ) ) {
                $needs_htaccess = true;
                break;
            }
        }
        if ( $needs_htaccess && class_exists( 'EasyOpt_Cache' ) ) {
            if ( EasyOpt_Cache::is_enabled() ) {
                self::defer( 'write_htaccess' );
            } else {
                self::defer( 'remove_htaccess' );
            }
        }

        // ── 3. HTML cache wipe (DEFERRED). ───────────────────────────────
        $needs_wipe = false;
        foreach ( self::$html_affecting as $k ) {
            if ( isset( $changed_flip[ $k ] ) ) {
                $needs_wipe = true;
                break;
            }
        }
        if ( $needs_wipe ) {
            self::defer( 'clear_cache' );
        }

        // ── 3b. Used CSS rebuild for generation-affecting keys (DEFERRED). ──
        // A page wipe re-renders pages but re-injects the existing .used.css,
        // so changes to how Used CSS is GENERATED need the cache dropped too.
        foreach ( self::$used_css_affecting as $k ) {
            if ( isset( $changed_flip[ $k ] ) ) {
                self::defer( 'clear_used_css' );
                break;
            }
        }

        // ── 4. Preload state machine (DEFERRED). ─────────────────────────
        if ( isset( $changed_flip['easyopt_cache_preload'] ) ) {
            $on = (int) ( isset( $new['easyopt_cache_preload'] ) ? $new['easyopt_cache_preload'] : 0 );
            self::defer( $on ? 'restart_preload' : 'stop_preload' );
        }

        // ── 5. Drop-in refresh for non-toggle cache options (SYNC, light). 
        // The drop-in file is small; rewriting it is essentially a
        // serialize + atomic file_put_contents. Needs to be synchronous
        // because any concurrent request may read the drop-in before the
        // deferred handler runs. Already guarded by an internal
        // per-request flag so duplicate calls are cheap no-ops.
        $dropin_affecting = array(
            'easyopt_cache_mode', 'easyopt_cache_ttl', 'easyopt_cache_separate_mobile',
            'easyopt_cache_logged_in', 'easyopt_cache_exclude_urls',
            'easyopt_cache_exclude_cookies', 'easyopt_cache_strip_query_params',
            'easyopt_cache_query_strings',
            'easyopt_cache_browser_caching', 'easyopt_cache_gzip',
        );
        foreach ( $dropin_affecting as $k ) {
            if ( isset( $changed_flip[ $k ] ) && class_exists( 'EasyOpt_Advanced_Cache' ) ) {
                // install() is idempotent within a single request (guarded
                // via a static flag added in 1.7.0).
                EasyOpt_Advanced_Cache::install();
                break;
            }
        }

        // ── 6. Bloat any-flag recompute. ─────────────────────────────────
        foreach ( $changed as $k ) {
            if ( 0 === strpos( $k, 'easyopt_bloat_' ) && class_exists( 'EasyOpt_Bloat' ) ) {
                EasyOpt_Bloat::recalc_any_enabled();
                break;
            }
        }

        // ── 7. Lazyload fonts cache-bust. ────────────────────────────────
        if ( isset( $changed_flip['easyopt_lazyload_fonts'] ) && class_exists( 'EasyOpt_Fonts' ) ) {
            EasyOpt_Fonts::on_lazyload_fonts_change();
        }

        self::ensure_shutdown_registered();
    }

    /**
     * Mark a deferred operation. Same op queued twice in one request
     * still runs once.
     */
    private static function defer( $op ) {
        if ( array_key_exists( $op , self::$deferred ) ) {
            self::$deferred[ $op ] = true;
        }
    }

    /**
     * Hook the drainer so it runs after the response is sent (where
     * possible). Without fastcgi_finish_request the operations still
     * run, just on the response thread — but at least each only runs
     * once and the htaccess probe is no longer in the path.
     */
    private static function ensure_shutdown_registered() {
        if ( self::$shutdown_registered ) {
            return;
        }
        self::$shutdown_registered = true;

        // For REST/AJAX requests we can flush the response and continue
        // working server-side. Settings page form posts also benefit.
        add_action( 'shutdown', array( __CLASS__, 'drain' ), 999 );
    }

    /**
     * Run all queued deferred operations. Called at shutdown, after
     * the response has been (ideally) flushed via fastcgi_finish_request.
     */
    public static function drain() {
        // If drain_now() already ran, all flags are false — nothing to do.
        if ( ! array_filter( self::$deferred ) ) {
            return;
        }

        // Try to release the client first — anything we do after this
        // point is invisible to the user, so even if remove_htaccess
        // takes 3 seconds, the save UX feels instant.
        self::release_client();

        self::run_deferred();
    }

    /**
     * Synchronous drain for REST handlers — runs deferred ops BEFORE the
     * response is sent so the response can include post-clear stats.
     * The shutdown drain() becomes a no-op because flags are already reset.
     */
    public static function drain_now() {
        self::run_deferred();
    }

    /**
     * Shared core: execute all deferred operations and reset flags.
     */
    private static function run_deferred() {
        if ( self::$deferred['stop_preload'] && class_exists( 'EasyOpt_Cache_Preload' ) ) {
            EasyOpt_Cache_Preload::stop();
        }

        if ( self::$deferred['clear_cache'] && class_exists( 'EasyOpt_Cache' ) ) {
            // clear_all() calls start() directly when preload is enabled —
            // no need for the separate restart_preload step.
            EasyOpt_Cache::clear_all();
            self::$deferred['restart_preload'] = false;
        }

        // (2.4.4) Drop the Used CSS cache when a generation-affecting setting
        // changed, so it rebuilds with the new rules (clear_all preserves the
        // css/ dir, so it would otherwise survive).
        if ( self::$deferred['clear_used_css'] && class_exists( 'EasyOpt_Unused_CSS' )
            && method_exists( 'EasyOpt_Unused_CSS', 'clear_used_css_only' ) ) {
            EasyOpt_Unused_CSS::clear_used_css_only();
        }

        // Htaccess write/remove. The new probe path is non-blocking; see
        // EasyOpt_Cache::write_htaccess() for the 1.7.0 changes.
        if ( self::$deferred['write_htaccess'] && class_exists( 'EasyOpt_Cache' ) ) {
            delete_transient( 'easyopt_htaccess_synced' );
            EasyOpt_Cache::write_htaccess();
        } elseif ( self::$deferred['remove_htaccess'] && class_exists( 'EasyOpt_Cache' ) ) {
            EasyOpt_Cache::remove_htaccess();
        }

        if ( self::$deferred['restart_preload'] && class_exists( 'EasyOpt_Cache_Preload' ) ) {
            EasyOpt_Cache_Preload::on_preload_toggle();
        }

        // Reset for the (unlikely) case of a same-request re-entry.
        foreach ( self::$deferred as $k => $_ ) {
            self::$deferred[ $k ] = false;
        }
    }

    /**
     * Best-effort response flush so the deferred operations don't
     * delay the user-visible reply.
     */
    private static function release_client() {
        // FastCGI (PHP-FPM): flushes the response and lets the worker
        // keep running. Available on most modern hosts.
        if ( function_exists( 'fastcgi_finish_request' ) ) {
            @fastcgi_finish_request();
            return;
        }
        // LiteSpeed equivalent.
        if ( function_exists( 'litespeed_finish_request' ) ) {
            @litespeed_finish_request();
            return;
        }
        // Fallback: at least flush all output buffers and send a
        // Content-Length so the browser closes the connection.
        if ( ! headers_sent() ) {
            @header( 'Connection: close' );
            @header( 'Content-Encoding: none' );
        }
        if ( function_exists( 'ignore_user_abort' ) ) { @ignore_user_abort( true ); }
        while ( @ob_get_level() > 0 ) {
            @ob_end_flush();
        }
        @flush();
    }
}
