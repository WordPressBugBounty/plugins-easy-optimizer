<?php
/**
 * Unused CSS removal handler for Easy Optimizer.
 *
 * Parses the final HTML buffer, extracts used selectors from the DOM,
 * strips unused rules from every local stylesheet, caches the result,
 * and injects the slim "used CSS" into <head>.
 *
 * Original stylesheets are then delayed / async-loaded / removed
 * depending on the chosen behaviour setting. External stylesheets
 * (Google Fonts, Adobe Fonts, etc.) are still delayed / async / removed
 * even though their content cannot be inlined into the Used CSS.
 *
 * CSS parsing is handled by EasyOpt_CSS_Tokenizer (dependency-free; replaced
 * sabberworm/php-css-parser in 2.6.4).
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Unused_CSS {

    /** @var string  Cache directory inside wp-content/uploads/easyopt/css/ */
    private static $cache_dir  = '';
    private static $cache_url  = '';

    /** @var string  URL-type slug used for the cache filename */
    private static $page_context   = '';

    /** @var array   Tags / IDs / classes actually present in the DOM */
    private static $active_selectors = array();

    /** @var array   Selectors to always keep (user + built-in) */
    /**
     * What may follow a safelisted pattern for it to count as a whole token.
     *
     * (2.6.7) `>`, `+` and `~` were missing. In minified CSS a combinator has
     * no surrounding space, so `.swiper-horizontal>.swiper-wrapper` failed the
     * boundary test and the rule was stripped even though the class was
     * safelisted. Of the 12 selectors carrying `.swiper-horizontal` in Swiper
     * 8's stylesheet, only 3 were being matched — and the 9 that were missed
     * are the ones that lay the slider out.
     *
     * This applies to every safelist entry, built-in and user-supplied alike,
     * so existing exclusions start covering combinator selectors they were
     * always meant to cover.
     */
    const SAFELIST_BOUNDARY = '(?=\s|\.|\:|,|\[|>|\+|~|$)';

    private static $safelist_patterns = array();

    /** (2.5.4 / perf #18) Compiled alternation of $safelist_patterns, or
     *  null when uncompiled / empty. One preg_match per selector instead of
     *  up to N (default ~55, plus user lines) — on a 30k-selector theme
     *  build that removes >1.5M regex dispatches. */
    private static $safelist_regex = null;

    /** @var string  Computed server root dir (cached) */
    private static $root_dir_path = '';

    /** @var array|null (2.5.2) Cheap regex scan of this page's classes/ids/tags,
     *  set when a cache-hit diff against the per-type selector sidecar found
     *  something new and forced an inline rebuild. Merged into
     *  $active_selectors during extraction (keep-side). */
    private static $sidecar_scan = null;

    /** @var array|null (2.6.4) Class tokens seen in class selectors across the
     *  stylesheets parsed during THIS build. null = not collecting (cache hit /
     *  outside a build); array = collecting. Persisted per type after a
     *  successful build and used to prioritise the beacon-class cap. */
    private static $css_class_universe = null;

    /* ───────────────────────────────────────────────
     *  Bootstrap – ALWAYS runs (admin + frontend)
     * ─────────────────────────────────────────────── */

    public static function init_always() {

        if ( ! (int) EasyOpt_Config::get( 'unused_css', 0 ) ) {
            return;
        }

        add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_menu' ), 999 );
        add_action( 'wp_ajax_easyopt_clear_used_css', array( __CLASS__, 'ajax_clear_used_css' ) );
        add_action( 'admin_post_easyopt_clear_used_css', array( __CLASS__, 'handle_clear_used_css' ) );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );

        // Auto-clear hooks.
        add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 1 );
        add_action( 'switch_theme', array( __CLASS__, 'clear_all_used_css' ) );
        add_action( 'customize_save_after', array( __CLASS__, 'clear_all_used_css' ) );

        // Beacon-captured JS classes survive a normal "Clear Used CSS" (they
        // are re-read on the next rebuild). They are only reset when the class
        // universe genuinely changes: theme switch or plugin activate/deactivate.
        add_action( 'switch_theme', array( __CLASS__, 'clear_beacon_classes' ) );
        add_action( 'activated_plugin', array( __CLASS__, 'clear_beacon_classes' ) );
        add_action( 'deactivated_plugin', array( __CLASS__, 'clear_beacon_classes' ) );
    }

    /* ───────────────────────────────────────────────
     *  Main entry – called from the output buffer
     * ─────────────────────────────────────────────── */

    public static function process_buffer( $html ) {

        if ( ! (int) EasyOpt_Config::get( 'unused_css', 0 ) ) {
            return $html;
        }

        // WooCommerce dynamic pages — stripping CSS on Cart, Checkout and
        // My Account can remove payment form styles, order review table
        // layout, and shipping option selectors.
        if ( EasyOpt_Config::is_woo_dynamic_page() ) {
            return $html;
        }

        // Per-request debug switch: ?norucss (or the master ?nooptimize)
        // disables Used CSS for this single request without changing settings.
        if ( function_exists( 'easyopt_debug_switch' )
             && ( easyopt_debug_switch( 'norucss' ) || easyopt_debug_switch( 'nooptimize' ) ) ) {
            return $html;
        }

        // (2.6.4) The CSS parser is now EasyOpt_CSS_Tokenizer, loaded via the
        // plugin autoloader — no Composer vendor autoload needed on this path.

        if ( is_user_logged_in() && ! apply_filters( 'easyopt_rucss_logged_in', false ) ) {
            return $html;
        }

        if ( ! self::is_valid_buffer( $html ) ) {
            return $html;
        }

        if ( self::is_url_excluded() ) {
            return $html;
        }

        // `easyopt_rucss_post_id` lets a caller pin the page identity when this
        // runs outside the page's own request (the preloader processes fetched
        // HTML in the runner's context, where the global query is the runner's,
        // not the target page's). Empty default → unchanged behaviour: derive
        // the type from the global query during a normal page render.
        self::$page_context = self::get_url_type( apply_filters( 'easyopt_rucss_post_id', '' ) );
        if ( '' === self::$page_context ) {
            return $html;
        }

        // ── Separate mobile / desktop Used CSS ──
        // When the page cache separates mobile and desktop HTML, the Used CSS
        // must be split too — mobile layouts often rely on JS-toggled classes
        // (hamburger menus, off-canvas panels, touch carousels) that are absent
        // in the server-rendered DOM and would otherwise be stripped. Mirrors
        // the approach used by EasyOpt_Cache and EasyOpt_LCP.
        if ( (int) EasyOpt_Config::get( 'cache_separate_mobile', 1 )
             && class_exists( 'EasyOpt_Cache' ) && EasyOpt_Cache::is_mobile() ) {
            self::$page_context .= '-mobile';
        }

        // Setup cache paths.
        $upload_dir      = wp_get_upload_dir();
        self::$cache_dir = trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt/css/';
        self::$cache_url = trailingslashit( content_url() ) . 'cache/easyopt/css/';

        if ( ! is_dir( self::$cache_dir ) ) {
            wp_mkdir_p( self::$cache_dir );
        }

        $used_css_path   = self::$cache_dir . self::$page_context . '.used.css';
        $used_css_url    = self::$cache_url . self::$page_context . '.used.css';
        $used_css_exists = file_exists( $used_css_path );

        // Fail-safe: a previously-written file that is suspiciously small is
        // treated as missing, so we neither strip stylesheets against it nor
        // serve it. It will be rebuilt (and re-validated) on this request.
        if ( $used_css_exists ) {
            $existing_size = @filesize( $used_css_path );
            if ( false === $existing_size || $existing_size < self::min_used_css_bytes() ) {
                $used_css_exists = false;
            }
            // (2.3.3) Mark as referenced for the unused-asset GC. Refreshes
            // mtime at most once a day per file — a stat (already cached by
            // the file_exists above) plus a rare touch().
            if ( $used_css_exists && class_exists( 'EasyOpt_Cache' ) ) {
                EasyOpt_Cache::touch_if_stale( $used_css_path );
            }
        }

        // (perf) Negative cache. When there is no valid Used CSS file yet AND a
        // recent build for THIS context already failed (low-confidence output,
        // or the file couldn't be written), skip the whole expensive build —
        // the DOMDocument walk, the CSS tokenizer, the per-selector regex — and
        // serve the page with its original stylesheets. Without this, an
        // unresolvable stylesheet set (third-party CDN, domain alias, reverse
        // proxy → $css_collected stays 0 and the confidence gate never passes)
        // made every single request repeat the full multi-second parse forever,
        // because a failing build never writes the file that would gate it off.
        // Self-heals: the marker expires (default 1h) and a manual "Clear Used
        // CSS" deletes it, so a fixed stylesheet set retries immediately.
        // Deliberately NOT set on the deferred-generation path below — deferral
        // is a success handoff to the queue, not a failure.
        if ( ! $used_css_exists && self::build_recently_failed() ) {
            return $html;
        }

        // ── Step 1: Convert matching inline <style> to external <link> ──
        // (Bails internally when no patterns are configured — no regex on full HTML.)
        $html = self::convert_inline_to_external( $html );

        // Strip comments locally for stylesheet discovery.
        $html_clean = preg_replace( '/<!--.*?-->/s', '', $html );

        // ── (2.5.2) Selector-union sidecar — shared-type mode only ──
        // In "Process Post Types Only" mode one Used CSS file is shared by
        // every URL of a type, but it was write-once: whichever page rendered
        // first defined the file, and template variants seen later (e.g. a
        // theme's "product-detail v2" layout) had their rules silently
        // stripped. Fix: on every cache-hit render of a SHARED type, do a
        // cheap regex scan of the page's classes/ids/tags and diff it against
        // the accumulated per-type sidecar. Anything new → rebuild inline on
        // this request, with the sidecar's full history unioned in so pages
        // seen earlier keep their rules too. Per-page contexts (front, home,
        // page-*) and per-URL mode are untouched — they never had the bug.
        self::$sidecar_scan = null;
        if ( $used_css_exists && self::sidecar_applicable() ) {
            $scan = self::scan_page_selectors( $html_clean );
            if ( self::scan_has_new( $scan, self::load_selector_sidecar() ) ) {
                $used_css_exists    = false;  // force rebuild in THIS request
                self::$sidecar_scan = $scan;
                if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                    EasyOpt_Debug_Log::info(
                        'rucss',
                        sprintf( 'New selectors detected for shared type "%s" — rebuilding Used CSS with unioned history.', self::$page_context )
                    );
                }
            }
        }

        // ── (2.6.0) Hand generation to the queue instead of the visitor ──
        //
        // Generating Used CSS means downloading and parsing every stylesheet
        // with a full CSS parser. On a heavy theme that measured 3.2 SECONDS
        // and up to 56MB — paid by whichever visitor happened to arrive first,
        // on a page they were only trying to read. And because Pages are keyed
        // per post ID, that cost repeats for every Page on the site.
        //
        // Generation cannot simply move to a background job: the keep-set is
        // the classes/ids/tags in THIS page's DOM, so it can only be produced
        // by rendering the page. So we queue a preload render of this URL
        // instead — same work, moved to a worker — and serve the visitor the
        // page untouched with its original stylesheets. Correct, just not yet
        // optimised. The warm render then generates AND caches the page with
        // the CSS injected, replacing what we cache here.
        //
        // Only when Cache Preload is on: without it nothing would drain the
        // queue, and the page would never get its Used CSS at all. Preload
        // renders themselves must always build inline — they are the worker.
        if ( ! $used_css_exists
            && self::should_defer_generation() ) {

            $deferred_url = class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'current_request_url' )
                ? EasyOpt_Cache::current_request_url()
                : '';

            if ( '' !== $deferred_url && class_exists( 'EasyOpt_Cache_Preload' ) ) {
                EasyOpt_Cache_Preload::enqueue_used_css_build(
                    $deferred_url,
                    self::$page_context,
                    class_exists( 'EasyOpt_Cache' ) && EasyOpt_Cache::is_mobile()
                );
            }

            return $html;
        }

        // Permissive matcher: capture every `<link>` that has any attributes.
        // We validate `rel="stylesheet"` and other attributes in the loop.
        // This catches Google Fonts (`/css2?...`), Adobe Fonts and any other
        // stylesheet whose URL doesn't end in a literal `.css` segment.
        if ( ! preg_match_all( '#<link\b([^>]*?)\/?>#i', $html_clean, $links, PREG_SET_ORDER ) ) {
            return $html;
        }

        $excluded_stylesheets   = self::get_excluded_stylesheets();
        $passthrough_stylesheets = self::get_passthrough_stylesheets();
        $behaviour              = EasyOpt_Config::get( 'unused_css_behavior', 'delayed' );

        // Only do the expensive DOM walk + selector extraction when we
        // actually need to BUILD the Used CSS cache. On cache hits this
        // is the single biggest perf win.
        if ( ! $used_css_exists ) {
            self::extract_used_selectors( $html );
            self::build_excluded_selectors();

            // (2.6.4) Begin collecting the CSS class universe for this build —
            // every class token that appears in a class selector across the
            // stylesheets we parse below. Persisted per type and used to keep
            // the beacon-class cap focused on classes that can actually match a
            // rule (junk from browser extensions / third-party widgets never
            // has a rule, so it is evicted first under cap pressure). An empty
            // array (not null) turns on collection in is_selector_used().
            self::$css_class_universe = array();

            // (2.5.2) Shared-type builds also union in (a) every selector
            // previously accumulated in the per-type sidecar and (b) this
            // request's regex scan (if a diff triggered the rebuild). Both
            // only ADD to the keep-set — existing DOM/beacon/Elementor
            // extraction is unchanged, so nothing that was kept before can
            // be stripped now.
            if ( self::sidecar_applicable() ) {
                self::union_into_active( self::load_selector_sidecar() );
                if ( null !== self::$sidecar_scan ) {
                    self::union_into_active( self::$sidecar_scan );
                }
            }
        }

        $used_css_string   = '';
        $behaviour_targets = array(); // tags eligible for delay/async/remove
        $stylesheets_seen  = 0;       // candidate stylesheets considered
        $css_collected     = 0;       // stylesheets that yielded any CSS

        foreach ( $links as $link ) {

            $full_tag = $link[0];
            $atts_str = isset( $link[1] ) ? $link[1] : '';
            $atts     = self::parse_atts( $atts_str );

            if ( empty( $atts['href'] ) ) {
                continue;
            }
            if ( empty( $atts['rel'] ) || 'stylesheet' !== strtolower( $atts['rel'] ) ) {
                continue;
            }
            if ( ! empty( $atts['media'] ) && 'print' === strtolower( $atts['media'] ) ) {
                continue;
            }

            // Stylesheet exclusion check (matches against full tag text — id, href, etc.)
            if ( self::match_in_array( $full_tag, $excluded_stylesheets ) ) {
                continue;
            }

            // Decode entities — `&#038;` etc. — so URL matching sees the real URL.
            $href_decoded = self::decode_attr( $atts['href'] );

            // Already page-specific? Copy it whole rather than filtering it.
            $is_passthrough = self::match_in_array( $full_tag, $passthrough_stylesheets );

            $stylesheets_seen++;

            // ── Used-CSS extraction (LOCAL files only, while building cache) ──
            if ( ! $used_css_exists ) {

                $file = self::get_local_path( $href_decoded );

                if ( $file && file_exists( $file ) ) {
                    $raw_css = self::read_file( $file );

                    if ( '' !== $raw_css ) {
                        $clean = $is_passthrough
                            ? self::passthrough_css( $raw_css )
                            : self::strip_unused( $href_decoded, $raw_css );

                        // A large passthrough sheet inlined into <head> is a
                        // real LCP cost. Nothing is changed automatically —
                        // that would be a surprise — but it is made visible.
                        if ( $is_passthrough
                            && strlen( $clean ) > 102400
                            && 'file' !== EasyOpt_Config::get( 'unused_css_method', 'inline' )
                            && class_exists( 'EasyOpt_Debug_Log' ) ) {
                            EasyOpt_Debug_Log::warn(
                                'used-css',
                                sprintf(
                                    'Passthrough stylesheet is %dKB and Used CSS is set to inline. Consider switching Used CSS delivery to a file: %s',
                                    (int) ( strlen( $clean ) / 1024 ),
                                    $href_decoded
                                )
                            );
                        }

                        if ( ! empty( $atts['media'] ) && 'all' !== strtolower( $atts['media'] ) ) {
                            $clean = '@media ' . $atts['media'] . '{' . $clean . '}';
                        }

                        if ( '' !== trim( $clean ) ) {
                            $used_css_string .= $clean;
                            $css_collected++;
                        }
                    }
                } else {
                    // External stylesheet (Google Fonts, Bunny Fonts, Adobe Fonts, …).
                    // We can still inline the @font-face / @import content into Used CSS
                    // by fetching the URL and caching the response. The original tag
                    // still gets the chosen behaviour applied below.
                    $remote_css = self::fetch_external_css( $href_decoded );
                    if ( '' !== $remote_css ) {
                        $clean = $is_passthrough
                            ? self::passthrough_css( $remote_css )
                            : self::strip_unused( $href_decoded, $remote_css );

                        if ( ! empty( $atts['media'] ) && 'all' !== strtolower( $atts['media'] ) ) {
                            $clean = '@media ' . $atts['media'] . '{' . $clean . '}';
                        }

                        if ( '' !== trim( $clean ) ) {
                            $used_css_string .= $clean;
                            $css_collected++;
                        }
                    }
                }
            }

            // Defer behaviour application — collect the tag now, mutate the
            // HTML only AFTER we've confirmed valid used CSS exists (C-1).
            $behaviour_targets[] = $full_tag;
        }

        // ── Step 3: Validate + save (fail-safe) ──
        // The original stylesheets are only ever delayed / async'd / removed
        // once we are sure a valid Used CSS replacement exists. If generation
        // produced nothing usable (empty, too small, or no stylesheet yielded
        // CSS — e.g. a CDN-hosted sheet that didn't resolve, or a DOM parse
        // failure), we return the page UNTOUCHED with its original styling.
        // This prevents the "stylesheets stripped, no replacement → unstyled
        // page" failure (and prevents that broken page from being cached).
        if ( ! $used_css_exists ) {
            $used_css_string = apply_filters( 'easyopt_used_css', $used_css_string );

            // (2.6.4) Final structural safety net. Guarantee the buffer we are
            // about to cache and inject is brace-balanced, so a single unclosed
            // block in ANY contributing source — a passthrough builder sheet, a
            // filtered addition, the @media media-attribute wrapper — can never
            // leak into and mobile-scope the rest of the page's CSS. Only ever
            // appends missing `}` / drops stray `}`; balanced input is untouched
            // and it never suppresses output. Runs once here, then cached.
            $used_css_string = EasyOpt_CSS_Tokenizer::balance( $used_css_string );

            if ( ! self::passes_confidence_gate( $used_css_string, $stylesheets_seen, $css_collected ) ) {
                if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                    EasyOpt_Debug_Log::warn(
                        'rucss',
                        sprintf(
                            'Used CSS generation low-confidence for "%s" (%d bytes, %d/%d stylesheets) — originals left intact.',
                            self::$page_context,
                            strlen( (string) $used_css_string ),
                            (int) $css_collected,
                            (int) $stylesheets_seen
                        )
                    );
                }
                self::mark_build_failed();
                return $html;
            }

            if ( self::write_file( $used_css_path, $used_css_string ) ) {
                // (2.6.0) A server-level cache (GoDaddy Varnish, Kinsta,
                // LiteSpeed, …) may already hold the version of this URL that
                // was served WITHOUT Used CSS. This response carries the CSS,
                // but Varnish keeps serving its stored copy until told
                // otherwise, so signal a targeted UPSTREAM invalidation.
                //
                // Deliberately NOT easyopt_cache_cleared_url: that action also
                // drives EasyOpt_Cache_Counter::decrement(), and nothing has
                // been removed from OUR cache here — this render is about to
                // write it. Firing it made the dashboard's "Pages cached"
                // counter oscillate 0→1→0→1 through an entire preload run, one
                // decrement per build racing one increment per write.
                $easyopt_built_url = class_exists( 'EasyOpt_Cache' )
                    ? EasyOpt_Cache::current_request_url()
                    : '';
                if ( '' !== $easyopt_built_url ) {
                    /**
                     * Invalidate one URL in caches ABOVE us (host, CDN) without
                     * touching our own cache state or its counters.
                     *
                     * @since 2.6.0
                     * @param string $url Absolute URL.
                     */
                    do_action( 'easyopt_purge_upstream_url', $easyopt_built_url );
                }

                $timestamps = get_option( 'easyopt_used_css_time', array() );
                if ( ! is_array( $timestamps ) ) {
                    $timestamps = array();
                }
                $timestamps[ self::$page_context ] = time();
                update_option( 'easyopt_used_css_time', $timestamps, false );
                $used_css_exists = true;

                // (2.5.2) Persist the accumulated selector union so the next
                // cache-hit diff compares against everything seen so far —
                // including this request's regex scan, which guarantees the
                // same page can never re-trigger a rebuild (no loops).
                if ( self::sidecar_applicable() ) {
                    self::save_selector_sidecar();
                }

                // (2.6.4) Persist the CSS class universe for this type (union
                // across builds → a growing superset), so the async beacon
                // endpoint can tell real classes from junk when it caps.
                self::save_class_universe();
            } else {
                // Couldn't persist the file — fail safe, don't strip.
                self::mark_build_failed();
                return $html;
            }
        }

        // At this point a valid Used CSS file is guaranteed (a cache hit that
        // passed the size check, or a freshly validated + written file).
        // Now it is safe to apply the chosen behaviour to the originals.
        foreach ( $behaviour_targets as $target_tag ) {
            self::apply_behaviour_to_tag( $target_tag, $behaviour, $html );
        }

        // ── Step 4: Inject used CSS into <head> ──
        if ( $used_css_exists ) {

            $method = EasyOpt_Config::get( 'unused_css_method', 'inline' );

            // (2.4.4) Apply the render-time font transform (strip below-fold
            // fonts when Lazyload is on + resolve ATF-gated preloads) to the
            // FULL .used.css. The strip is no longer baked into the cache file.
            // (2.5.4 / perf #2) The transformed result is cached in a
            // {context}.used.final.css sidecar keyed by every input that can
            // change it, so the steady-state hit path is one meta read + one
            // file read instead of re-running the font scan and the CDN URL
            // signing on every render. See transformed_used_css().
            $font_links = '';
            $css        = self::transformed_used_css( $used_css_path, $font_links );

            if ( 'file' === $method ) {
                // Serve the transformed CSS from a sidecar keyed by content
                // hash: the file is written BEFORE it is linked (no 404 window
                // — F6) and a changed above-the-fold signature busts the URL.
                $served = self::write_served_file( self::$page_context, $css );
                if ( $served ) {
                    $file_url = add_query_arg( 'ver', $served['ver'], $served['url'] );
                    $output   = '<link rel="preload" href="' . esc_url( $file_url ) . '" as="style" />';
                    $output  .= '<link rel="stylesheet" id="easyopt-used-css" href="' . esc_url( $file_url ) . '" media="all" />';
                } else {
                    // Couldn't write the served file — inline instead (correct).
                    $output = '<style id="easyopt-used-css">' . $css . '</style>';
                }
            } else {
                $output = '<style id="easyopt-used-css">' . $css . '</style>';
            }

            // Place preloads right after the critical CSS, order-independent:
            // (1) relocate the LCP preload that EasyOpt_LCP injected earlier
            // (before </head>) so it sits immediately after this <style>, and
            // (2) append the ATF-gated font preloads resolved above. No markers
            // and no sidecar round-trip, so nothing lands out of order. LCP
            // ends up above the font preloads, both after the CSS.
            $lcp_links = '';
            if ( preg_match_all( '/<link\b[^>]*data-easyopt-lcp[^>]*>/i', $html, $mm ) && ! empty( $mm[0] ) ) {
                $lcp_links = implode( '', $mm[0] );
                $html      = preg_replace( '/<link\b[^>]*data-easyopt-lcp[^>]*>/i', '', $html );
            }
            $output .= $lcp_links . $font_links;

            $html = preg_replace( '/(<head\b[^>]*>)/i', '$1' . $output, $html, 1 );
        }

        // ── Step 5: Delayed-style loader script before </body> ──
        if ( 'delayed' === $behaviour && $used_css_exists ) {
            $html = str_replace( '</body>', self::delayed_styles_loader() . '</body>', $html );
        }

        return $html;
    }

    /**
     * The <script> that swaps deferred stylesheets (data-easyopt-delayed → href)
     * back in on first interaction.
     *
     * Two variants, chosen by feature scope:
     *
     *   - Cloud Optimization (trial/pro) running the cloud Unused CSS engine
     *     WITH Delay JS active hands the swap to Delay JS's runtime as an
     *     easyoptscript. A self-listening loader would itself be deferred by
     *     Delay JS, then register its listeners a beat late and load the CSS one
     *     interaction behind — or never, on a single-interaction visit. Delay JS
     *     skips already-easyoptscript tags and executes this inline body (no
     *     data-easyopt-src) at interaction; the swap is safe to run more than
     *     once because it removes the attribute on the first pass.
     *
     *   - Every other site — local/beacon Unused CSS, or anyone without Delay
     *     JS — keeps the self-contained first-interaction loader, unchanged.
     *
     * @return string
     */
    private static function delayed_styles_loader() {
        if ( (int) EasyOpt_Config::get( 'delay_js', 0 )
            && (int) EasyOpt_Config::get( 'easyopt_cloud_unused_css', 0 ) ) {
            return '<script type="easyoptscript" id="easyopt-delayed-styles-js">'
                . 'document.querySelectorAll("link[data-easyopt-delayed]").forEach(function(e){'
                . 'e.setAttribute("href",e.getAttribute("data-easyopt-delayed"));e.removeAttribute("data-easyopt-delayed")});'
                . '</script>';
        }
        return '<script type="text/javascript" id="easyopt-delayed-styles-js">'
            . '!function(){var e=["keydown","mousemove","wheel","touchmove","touchstart","touchend","scroll"];'
            . 'function t(){document.querySelectorAll("link[data-easyopt-delayed]").forEach(function(e){'
            . 'e.setAttribute("href",e.getAttribute("data-easyopt-delayed"));e.removeAttribute("data-easyopt-delayed")});'
            . 'e.forEach(function(e){window.removeEventListener(e,t,{passive:!0})})}'
            . 'e.forEach(function(e){window.addEventListener(e,t,{passive:!0})})}();'
            . '</script>';
    }

    /* ───────────────────────────────────────────────
     *  (2.5.4 / perf #2) Transformed Used-CSS cache
     * ─────────────────────────────────────────────── */

    /**
     * Return the fonts+CDN-transformed Used CSS for the current context,
     * served from a `{context}.used.final.css` sidecar when every input is
     * unchanged. The sidecar's companion `.used.final.json` stores the
     * invalidation signature plus the resolved ATF font-preload links.
     *
     * Signature inputs (any change → transparent rebuild on next render):
     *   - .used.css mtime+size
     *   - fonts sidecar mtime+size for the current viewport (+ legacy path)
     *   - every setting the two transforms read (lazyload/behaviour/preload/
     *     excludes on the fonts side; FluxCDN key/quality/format/width and
     *     the live delivering/over-quota state on the CDN side)
     *
     * Fail-safe: any read/stat/write problem falls through to the exact
     * pre-2.5.4 live-transform path, so output can never differ — only the
     * work needed to produce it.
     *
     * @param string $used_css_path Absolute path of the raw .used.css.
     * @param string $font_links    OUT: ATF-gated <link rel=preload> tags.
     * @return string Final CSS ready for injection.
     */
    private static function transformed_used_css( $used_css_path, &$font_links ) {
        $font_links = '';

        $sig        = self::final_css_signature( $used_css_path );
        $final_path = self::$cache_dir . self::$page_context . '.used.final.css';
        $meta_path  = self::$cache_dir . self::$page_context . '.used.final.json';

        if ( '' !== $sig && file_exists( $meta_path ) && file_exists( $final_path ) ) {
            $meta = json_decode( (string) self::read_file( $meta_path ), true );
            if ( is_array( $meta ) && isset( $meta['sig'] ) && hash_equals( (string) $meta['sig'], $sig ) ) {
                $css = self::read_file( $final_path );
                if ( '' !== $css ) {
                    $font_links = isset( $meta['font_links'] ) ? (string) $meta['font_links'] : '';
                    if ( class_exists( 'EasyOpt_Cache' ) ) {
                        EasyOpt_Cache::touch_if_stale( $final_path );
                    }
                    return $css;
                }
            }
        }

        // Miss / stale / unreadable → run the live transforms (pre-2.5.4 path).
        $css = self::read_file( $used_css_path );
        if ( class_exists( 'EasyOpt_Fonts' ) && method_exists( 'EasyOpt_Fonts', 'apply_used_css' ) ) {
            $css = EasyOpt_Fonts::apply_used_css( $css, self::fonts_sidecar_type(), $font_links );
        }
        if ( class_exists( 'EasyOpt_CDN' ) && (int) EasyOpt_Config::get( 'img_opt', 0 ) ) {
            $css = EasyOpt_CDN::rewrite_css_urls( $css );
        }
        // (2.7.2) Fonts / static url()s → the edge. Images were handled above.
        if ( class_exists( 'EasyOpt_CDN_Assets' ) && EasyOpt_CDN_Assets::enabled() ) {
            $css = EasyOpt_CDN_Assets::rewrite_css_urls( $css );
        }

        // Persist for the next render (best-effort; failures just mean the
        // next render transforms live again).
        if ( '' !== $sig && '' !== $css ) {
            if ( self::write_file( $final_path, $css ) ) {
                self::write_file( $meta_path, (string) wp_json_encode( array(
                    'sig'        => $sig,
                    'font_links' => (string) $font_links,
                ) ) );
            }
        }

        return $css;
    }

    /**
     * Sidecar key for the fonts transform.
     *
     * The fonts sidecar filename already carries its own viewport segment
     * ({type}.mobile.eo-fonts.json / {type}.desktop.eo-fonts.json) and the
     * beacon writes it under the BASE type from get_url_type(). $page_context
     * carries an extra `-mobile` suffix for the Used-CSS file split, so
     * handing it to the fonts layer asked for `home-mobile.mobile.…json` — a
     * file nothing ever writes. read_sidecar() then returned empty and
     * apply_used_css() hit its fail-safe, so Lazyload/Preload Fonts silently
     * did nothing on mobile while working normally on desktop.
     *
     * Strip the suffix so the read key matches the write key. No-op on
     * desktop, where $page_context never carries it.
     *
     * @return string Base URL type, without the Used-CSS mobile suffix.
     */
    private static function fonts_sidecar_type() {
        return (string) preg_replace( '/-mobile$/', '', (string) self::$page_context );
    }

    /**
     * Invalidation signature for the transformed Used-CSS cache. Returns ''
     * when the source can't be stat'd (caller then always transforms live).
     *
     * @param string $used_css_path
     * @return string
     */
    private static function final_css_signature( $used_css_path ) {
        $stat = @stat( $used_css_path );
        if ( false === $stat ) {
            return '';
        }
        $parts = array( 'v1', (int) $stat['mtime'], (int) $stat['size'] );

        // Fonts-transform inputs. Mirrors EasyOpt_Fonts::apply_used_css().
        if ( class_exists( 'EasyOpt_Fonts' ) && method_exists( 'EasyOpt_Fonts', 'used_css_transform_sig' ) ) {
            $parts[] = EasyOpt_Fonts::used_css_transform_sig( self::fonts_sidecar_type() );
        } else {
            $parts[] = 'nofonts';
        }

        // CDN-transform inputs. Mirrors EasyOpt_CDN::rewrite_css_urls() +
        // build_url(): the rewrite runs only when img_opt is on AND the CDN
        // is delivering AND not over quota; its output depends on the key,
        // endpoint and the quality/format/width settings.
        $cdn_active = class_exists( 'EasyOpt_CDN' )
            && (int) EasyOpt_Config::get( 'img_opt', 0 )
            && EasyOpt_CDN::is_delivering()
            && ! EasyOpt_CDN::over_quota();
        if ( $cdn_active ) {
            $parts[] = 'cdn:' . md5(
                (string) EasyOpt_Config::get( 'fluxcdn_api_key', '' )
                . '|' . EasyOpt_CDN::endpoint()
                . '|' . (int) EasyOpt_Config::get( 'fluxcdn_quality', 0 )
                . '|' . (string) EasyOpt_Config::get( 'fluxcdn_format', 'auto' )
                . '|' . (int) EasyOpt_Config::get( 'fluxcdn_max_width', 2560 )
            );
        } else {
            $parts[] = 'cdn:off';
        }

        // (2.7.2) Static-asset transform inputs. Mirrors
        // EasyOpt_CDN_Assets::rewrite_css_urls() → cdn_url().
        if ( class_exists( 'EasyOpt_CDN_Assets' ) && EasyOpt_CDN_Assets::enabled() ) {
            $parts[] = 'assets:' . md5(
                EasyOpt_CDN_Cloud::endpoint() . '|' . EasyOpt_CDN_Cloud::account()
                . '|' . EasyOpt_CDN_Assets::REV
                . '|' . (string) EasyOpt_Config::get( 'easyopt_cloud_assets_exclude', '' )
            );
        } else {
            $parts[] = 'assets:off';
        }

        return md5( implode( '|', $parts ) );
    }

    /* ───────────────────────────────────────────────
     *  Behaviour application (targeted, encoding-safe)
     * ─────────────────────────────────────────────── */

    /**
     * Mutate $html_ref in place, replacing $full_tag with a behaviour-modified
     * version. Uses surgical regex on the original tag rather than rebuilding
     * its attributes from scratch — this preserves the source encoding of
     * pre-escaped values like `&#038;` instead of double-encoding them.
     */
    private static function apply_behaviour_to_tag( $full_tag, $behaviour, &$html_ref ) {

        if ( 'remove' === $behaviour ) {
            $html_ref = str_replace( $full_tag, '', $html_ref );
            return;
        }

        if ( 'delayed' === $behaviour ) {
            // Rename href -> data-easyopt-delayed without touching the value.
            $new_tag = preg_replace( '/\bhref\s*=/i', 'data-easyopt-delayed=', $full_tag, 1 );
            if ( $new_tag && $new_tag !== $full_tag ) {
                $html_ref = str_replace( $full_tag, $new_tag, $html_ref );
            }
            return;
        }

        if ( 'async' === $behaviour ) {
            $new_tag = $full_tag;

            // Replace existing media attribute, or insert one before the closing brace.
            if ( preg_match( '/\bmedia\s*=\s*["\'][^"\']*["\']/i', $new_tag ) ) {
                $new_tag = preg_replace( '/\bmedia\s*=\s*["\'][^"\']*["\']/i', 'media="print"', $new_tag, 1 );
            } else {
                $new_tag = preg_replace( '/\s*\/?>$/', ' media="print" />', $new_tag, 1 );
            }

            if ( ! preg_match( '/\bonload\s*=/i', $new_tag ) ) {
                $new_tag = preg_replace( '/\s*\/?>$/', ' onload="this.media=\'all\';this.onload=null;" />', $new_tag, 1 );
            }

            if ( $new_tag && $new_tag !== $full_tag ) {
                $html_ref = str_replace( $full_tag, $new_tag, $html_ref );
            }
        }
    }

    /* ───────────────────────────────────────────────
     *  Inline <style> → External <link> conversion
     * ─────────────────────────────────────────────── */

    private static function convert_inline_to_external( $html ) {

        $include_inline = EasyOpt_Config::get( 'unused_css_include_inline', '' );
        if ( empty( $include_inline ) ) {
            return $html;
        }

        $patterns = array_filter( array_map( 'trim', explode( "\n", $include_inline ) ) );
        if ( empty( $patterns ) ) {
            return $html;
        }

        $inline_dir = self::$cache_dir . 'inline/';
        if ( ! is_dir( $inline_dir ) ) {
            wp_mkdir_p( $inline_dir );
        }

        $inline_url = self::$cache_url . 'inline/';

        if ( ! preg_match_all( '#<style\b([^>]*)>(.*?)</style>#si', $html, $style_matches, PREG_SET_ORDER ) ) {
            return $html;
        }

        foreach ( $style_matches as $sm ) {

            $full_style_tag = $sm[0];
            $style_atts_str = $sm[1];
            $css_content    = $sm[2];

            $matched = false;
            foreach ( $patterns as $pattern ) {
                if ( false !== stripos( $full_style_tag, $pattern ) ) {
                    $matched = true;
                    break;
                }
            }

            if ( ! $matched ) {
                continue;
            }

            $css_trimmed = trim( $css_content );
            if ( '' === $css_trimmed ) {
                continue;
            }

            $hash     = substr( hash( 'sha256', $css_trimmed ), 0, 12 );
            $css_file = $inline_dir . $hash . '.css';
            $css_url  = $inline_url . $hash . '.css';

            if ( ! file_exists( $css_file ) ) {
                self::write_file( $css_file, $css_trimmed );
            }

            $id_attr = '';
            if ( preg_match( '/\bid=["\']([^"\']+)["\']/', $style_atts_str, $id_match ) ) {
                $id_attr = ' id="' . esc_attr( $id_match[1] ) . '"';
            }

            $link_tag = '<link' . $id_attr . ' rel="stylesheet" href="' . esc_url( $css_url ) . '" media="all" />';
            $html     = str_replace( $full_style_tag, $link_tag, $html );
        }

        return $html;
    }

    /* ───────────────────────────────────────────────
     *  DOM → Used selectors
     * ─────────────────────────────────────────────── */

    /**
     * Extract every used tag/id/class from the rendered HTML.
     *
     * NOTE: pre-1.4.1 used `mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8')`
     * which is DEPRECATED in PHP 8.2 and REMOVED in 8.4. We now use the
     * `<?xml encoding="UTF-8">` prefix + libxml flags, which keeps UTF-8
     * intact across PHP 7.4 → 8.4.
     */
    private static function extract_used_selectors( $html ) {

        $libxml_prev = libxml_use_internal_errors( true );
        $dom         = new DOMDocument();

        $loaded = false;
        if ( defined( 'LIBXML_HTML_NOIMPLIED' ) && defined( 'LIBXML_HTML_NODEFDTD' ) ) {
            $loaded = $dom->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
            );
        }
        if ( ! $loaded ) {
            $dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
        }

        libxml_clear_errors();
        libxml_use_internal_errors( $libxml_prev );

        self::$active_selectors = array( 'tags' => array(), 'ids' => array(), 'classes' => array() );

        $classes = array();
        foreach ( $dom->getElementsByTagName( '*' ) as $tag ) {
            self::$active_selectors['tags'][ $tag->tagName ] = 1;

            if ( $tag->hasAttribute( 'id' ) ) {
                self::$active_selectors['ids'][ $tag->getAttribute( 'id' ) ] = 1;
            }
            if ( $tag->hasAttribute( 'class' ) ) {
                $tag_classes = preg_split( '/\s+/', trim( $tag->getAttribute( 'class' ) ) );
                if ( $tag_classes ) {
                    array_push( $classes, ...$tag_classes );
                }
            }
        }

        $classes = array_filter( array_unique( $classes ) );
        if ( $classes ) {
            self::$active_selectors['classes'] = array_fill_keys( $classes, 1 );
        }

        // Union in JS-generated classes captured by the beacon for this URL
        // type (2.4.3). These are runtime-added classes (sliders, menus, tabs,
        // AJAX content) absent from the server-rendered DOM; treating them as
        // present stops the tree-shaker from stripping their rules. This is
        // future-only — it only affects builds that happen AFTER the data was
        // stored; it never forces a purge of existing pages or CSS.
        $beacon_classes = self::get_beacon_classes( self::get_url_type() );
        if ( ! empty( $beacon_classes ) ) {
            foreach ( $beacon_classes as $bc ) {
                self::$active_selectors['classes'][ $bc ] = 1;
            }
        }

        // Union in Elementor entrance/hover animation classes (2.5.1).
        // Elementor renders the animation name only inside the data-settings
        // JSON ({"_animation":"zoomInUp"}); its JS adds the class to the
        // element when it scrolls into view. At render time the class isn't
        // in any class="" attribute, so the tree-shaker would strip
        // `.zoomInUp{animation-name:zoomInUp}` while keeping the (safelisted)
        // @keyframes — a dead animation, or an element stuck invisible.
        // Extracting the declared names and treating them as present classes
        // is deterministic and page-accurate: pages that declare no
        // animations keep nothing extra. Scroll-gated classes also can't be
        // captured reliably by the JS beacon (they're added on viewport
        // entry, after capture), so this is the server-side fix.
        foreach ( self::extract_elementor_animation_classes( $html ) as $ac ) {
            self::$active_selectors['classes'][ $ac ] = 1;
        }
    }

    /**
     * Pull Elementor animation class names out of data-settings JSON in the
     * rendered HTML. Handles both raw and HTML-entity-encoded attributes.
     * Covered keys: _animation / animation (+ _mobile / _tablet responsive
     * variants) → the value IS the class (animate.css name, e.g. zoomInUp);
     * hover_animation → class is elementor-animation-{value}.
     * Over-capture from a non-Elementor "animation":"x" JSON key is harmless
     * (keeps at most one extra rule); under-capture breaks animations, so we
     * deliberately err on the keep side.
     *
     * @param  string $html Rendered page HTML.
     * @return string[] Unique class names, possibly empty.
     */
    private static function extract_elementor_animation_classes( $html ) {
        $out = array();
        if ( false === strpos( $html, 'animation' ) ) {
            return $out;
        }
        $q = '(?:&quot;|")'; // attribute JSON is usually entity-encoded

        // Entrance animations: "_animation":"zoomInUp", "animation_mobile":"fadeIn"…
        if ( preg_match_all( '#' . $q . '_?animation(?:_(?:mobile|tablet))?' . $q . '\s*:\s*' . $q . '([A-Za-z0-9_-]+)' . $q . '#', $html, $m ) ) {
            foreach ( $m[1] as $v ) {
                if ( '' !== $v && 'none' !== strtolower( $v ) ) {
                    $out[ $v ] = 1;
                }
            }
        }

        // Hover animations: "hover_animation":"grow" → .elementor-animation-grow
        if ( preg_match_all( '#' . $q . 'hover_animation(?:_(?:mobile|tablet))?' . $q . '\s*:\s*' . $q . '([A-Za-z0-9_-]+)' . $q . '#', $html, $m ) ) {
            foreach ( $m[1] as $v ) {
                if ( '' !== $v && 'none' !== strtolower( $v ) ) {
                    $out[ 'elementor-animation-' . $v ] = 1;
                }
            }
        }

        return array_keys( $out );
    }

    /* ───────────────────────────────────────────────
     *  Beacon — JS-generated class capture (2.4.3)
     * ─────────────────────────────────────────────── */

    /**
     * Metadata dir for JS-class sidecars (.eo-classes.json / .eo-universe.json).
     * (2.6.4) Moved out of cache/easyopt/css/ (which now holds only served CSS)
     * into a dedicated cache/easyopt/meta/classes/ folder. Computed locally so
     * it works in the AJAX beacon context too.
     */
    private static function beacon_cache_dir() {
        return trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt/meta/classes/';
    }

    /** Sidecar path for a per-type JSON store. */
    private static function beacon_sidecar_path( $type, $what ) {
        $type = sanitize_file_name( (string) $type );
        if ( '' === $type ) {
            return '';
        }
        return self::beacon_cache_dir() . $type . '.eo-' . $what . '.json';
    }

    /** Read the stored JS-class set for a URL type (array of class tokens). */
    public static function get_beacon_classes( $type ) {
        $file = self::beacon_sidecar_path( $type, 'classes' );
        if ( '' === $file || ! is_file( $file ) ) {
            return array();
        }
        $json = @file_get_contents( $file );
        if ( false === $json || '' === $json ) {
            return array();
        }
        $data = json_decode( $json, true );
        return ( is_array( $data ) && ! empty( $data['classes'] ) && is_array( $data['classes'] ) )
            ? $data['classes']
            : array();
    }

    /* ───────────────────────────────────────────────
     *  (2.6.4) Per-type CSS class universe
     *  Class tokens that appear in class selectors of the parsed stylesheets.
     *  Lets the async beacon endpoint tell real classes from junk when capping.
     * ─────────────────────────────────────────────── */

    /** Base URL type for per-type sidecars (drops the Used-CSS mobile suffix). */
    private static function base_type_of( $ctx ) {
        return (string) preg_replace( '/-mobile$/', '', (string) $ctx );
    }

    private static function class_universe_path( $type ) {
        return self::beacon_sidecar_path( $type, 'universe' );
    }

    /** Load the per-type universe as a SET (class => 1). Empty when none yet. */
    private static function load_class_universe( $type ) {
        $file = self::class_universe_path( $type );
        if ( '' === $file || ! is_file( $file ) ) {
            return array();
        }
        $json = @file_get_contents( $file );
        if ( false === $json || '' === $json ) {
            return array();
        }
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) || empty( $data['classes'] ) || ! is_array( $data['classes'] ) ) {
            return array();
        }
        return array_fill_keys( $data['classes'], 1 );
    }

    /**
     * Persist this build's collected class universe, unioned with what earlier
     * builds of the same type saw (a growing superset, so a conditionally-loaded
     * stylesheet is covered once its page has been built at least once). Called
     * after a successful build; no-op when nothing was collected.
     */
    private static function save_class_universe() {
        if ( ! is_array( self::$css_class_universe ) || empty( self::$css_class_universe ) ) {
            return;
        }
        $dir = self::beacon_cache_dir();
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return;
        }
        $type = self::base_type_of( self::$page_context );
        $file = self::class_universe_path( $type );
        if ( '' === $file ) {
            return;
        }

        $set      = self::$css_class_universe;
        $existing = self::load_class_universe( $type );
        if ( ! empty( $existing ) ) {
            $set = $set + $existing; // union across builds (superset)
        }

        $max = (int) apply_filters( 'easyopt_class_universe_max', 50000 );
        if ( count( $set ) > $max ) {
            $set = array_slice( $set, 0, $max, true );
        }

        $payload = wp_json_encode( array(
            'v'       => time(),
            'classes' => array_keys( $set ),
        ) );
        if ( false !== $payload ) {
            @file_put_contents( $file, $payload, LOCK_EX );
        }
    }

    /**
     * Does the beacon still need to collect JS classes for this type?
     * True only until the first report lands (future-only, server-friendly:
     * one good report per type, then we stop). Re-armed on theme switch and
     * plugin activate/deactivate, which genuinely change the class universe.
     */
    public static function beacon_classes_needed( $type ) {
        $file = self::beacon_sidecar_path( $type, 'classes' );
        return ( '' !== $file && ! is_file( $file ) );
    }

    /**
     * Store JS classes reported by the beacon for a URL type. Sanitizes each
     * to a valid CSS class token, unions with any existing set, caps the
     * total, and writes the sidecar. NEVER purges pages or CSS — the data is
     * applied at the next natural (re)build.
     *
     * @param string       $type
     * @param string|array $raw  Comma-separated string or array of classes.
     */
    public static function store_beacon_classes( $type, $raw ) {

        $dir = self::beacon_cache_dir();
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return;
        }
        $file = self::beacon_sidecar_path( $type, 'classes' );
        if ( '' === $file ) {
            return;
        }

        $incoming = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
        $clean    = array();
        foreach ( $incoming as $c ) {
            $c = trim( (string) $c );
            // Valid CSS class token: letters, digits, _ - and (escaped) :/ etc.
            // We accept the common safe subset and skip anything else.
            if ( '' === $c || strlen( $c ) > 120 ) {
                continue;
            }
            if ( preg_match( '/^[A-Za-z0-9_\-]+$/', $c ) ) {
                $clean[ $c ] = 1;
            }
        }
        if ( empty( $clean ) ) {
            return;
        }

        // Union with existing.
        foreach ( self::get_beacon_classes( $type ) as $existing ) {
            $clean[ $existing ] = 1;
        }

        // Cap to keep the file and the matching loop bounded. (2.6.4) When we
        // must evict, drop JUNK first — classes that appear in no stylesheet
        // rule for this type (browser-extension / third-party-widget noise) can
        // never keep a rule, so they are dropped before any real class. This
        // stops junk from evicting a genuinely JS-added class from the cap.
        // Real classes are only ever evicted (oldest first) if they alone
        // exceed the cap. Under the cap NOTHING is dropped, so a conditionally-
        // loaded real class not yet seen in a build is always safe.
        $max = (int) apply_filters( 'easyopt_beacon_classes_max', 3000 );
        if ( count( $clean ) > $max ) {
            $universe = self::load_class_universe( $type );
            if ( ! empty( $universe ) ) {
                $real = array();
                $junk = array();
                foreach ( $clean as $cls => $one ) {
                    if ( isset( $universe[ $cls ] ) ) {
                        $real[ $cls ] = 1;
                    } else {
                        $junk[ $cls ] = 1;
                    }
                }
                if ( count( $real ) >= $max ) {
                    $clean = array_slice( $real, 0, $max, true );
                } else {
                    $clean = $real + array_slice( $junk, 0, $max - count( $real ), true );
                }
            } else {
                // No universe yet — fall back to the previous behaviour.
                $clean = array_slice( $clean, 0, $max, true );
            }
        }

        $payload = wp_json_encode( array(
            'v'       => time(),
            'classes' => array_keys( $clean ),
        ) );
        if ( false !== $payload ) {
            @file_put_contents( $file, $payload, LOCK_EX );
        }
    }

    /**
     * Delete all stored beacon-class sidecars AND the per-type class universe
     * (theme switch / plugin change) — both are re-learned afterwards, since
     * those events genuinely change which classes and rules exist. (2.6.4)
     */
    public static function clear_beacon_classes() {
        $dir = self::beacon_cache_dir();
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( array( '*.eo-classes.json', '*.eo-universe.json' ) as $pattern ) {
            $files = glob( $dir . $pattern );
            if ( $files ) {
                foreach ( $files as $f ) {
                    if ( is_file( $f ) ) {
                        @unlink( $f );
                    }
                }
            }
        }
    }

    /* ───────────────────────────────────────────────
     *  Excluded selectors (always kept)
     * ─────────────────────────────────────────────── */

    /* ───────────────────────────────────────────────
     *  (2.5.2) Per-type selector-union sidecar
     * ─────────────────────────────────────────────── */

    /**
     * The sidecar applies ONLY when (a) "Process Post Types Only" is ON
     * (shared per-type files) and (b) the current context is a genuinely
     * SHARED type. front / home / page-* are per-page by design (each page
     * builds from its own HTML) so they never had the first-page-wins bug
     * and get no sidecar overhead.
     */
    private static function sidecar_applicable() {
        if ( ! class_exists( 'EasyOpt_Config' )
             || ! (int) EasyOpt_Config::get( 'unused_css_post_types_only', 1 ) ) {
            return false;
        }
        if ( '' === self::$page_context || '' === self::$cache_dir ) {
            return false;
        }
        $base = preg_replace( '/-mobile$/', '', self::$page_context );
        if ( 'front' === $base || 'home' === $base || 0 === strpos( $base, 'page-' ) ) {
            return false;
        }
        return true;
    }

    private static function selector_sidecar_path() {
        return self::$cache_dir . sanitize_file_name( self::$page_context ) . '.selectors.json';
    }

    /** @return array{classes:array,ids:array,tags:array} keys => 1 maps (possibly empty). */
    private static function load_selector_sidecar() {
        $empty = array( 'classes' => array(), 'ids' => array(), 'tags' => array() );
        $raw   = self::read_file( self::selector_sidecar_path() );
        if ( '' === $raw ) {
            return $empty;
        }
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) {
            return $empty;
        }
        foreach ( array( 'classes', 'ids', 'tags' ) as $k ) {
            $empty[ $k ] = ( isset( $data[ $k ] ) && is_array( $data[ $k ] ) ) ? $data[ $k ] : array();
        }
        return $empty;
    }

    /**
     * Cheap single-pass regex scan of the rendered page (comments already
     * stripped) — NO DOMDocument. Escaped quotes inside JS strings
     * (class=\"x\") don't match, so script payloads can't pollute the scan.
     * Any residual over-capture is harmless: scanned values are only ever
     * ADDED to the keep-set (a few extra rules kept, never rules lost).
     */
    private static function scan_page_selectors( $html_clean ) {
        $scan = array( 'classes' => array(), 'ids' => array(), 'tags' => array() );

        if ( preg_match_all( '/\bclass\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $html_clean, $m ) ) {
            foreach ( $m[2] as $i => $v ) {
                $val = '' !== $v ? $v : $m[3][ $i ];
                foreach ( preg_split( '/\s+/', trim( $val ) ) as $cls ) {
                    if ( '' !== $cls ) {
                        $scan['classes'][ $cls ] = 1;
                    }
                }
            }
        }
        if ( preg_match_all( '/\bid\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $html_clean, $m ) ) {
            foreach ( $m[2] as $i => $v ) {
                $val = trim( '' !== $v ? $v : $m[3][ $i ] );
                if ( '' !== $val ) {
                    $scan['ids'][ $val ] = 1;
                }
            }
        }
        if ( preg_match_all( '/<([a-zA-Z][a-zA-Z0-9-]*)/', $html_clean, $m ) ) {
            foreach ( $m[1] as $t ) {
                $scan['tags'][ strtolower( $t ) ] = 1;
            }
        }
        return $scan;
    }

    /** True when the scan contains any class/id/tag the sidecar hasn't seen. */
    private static function scan_has_new( $scan, $side ) {
        foreach ( array( 'classes', 'ids', 'tags' ) as $k ) {
            if ( ! empty( $scan[ $k ] ) && array_diff_key( $scan[ $k ], $side[ $k ] ) ) {
                return true;
            }
        }
        return false;
    }

    /** Union a {classes,ids,tags} map set into $active_selectors (keep-side only). */
    private static function union_into_active( $sets ) {
        foreach ( array( 'classes', 'ids', 'tags' ) as $k ) {
            if ( ! empty( $sets[ $k ] ) ) {
                self::$active_selectors[ $k ] = self::$active_selectors[ $k ] + $sets[ $k ];
            }
        }
    }

    /** Persist the post-union $active_selectors as the type's new history. */
    private static function save_selector_sidecar() {
        $payload = wp_json_encode(
            array(
                'classes' => isset( self::$active_selectors['classes'] ) ? self::$active_selectors['classes'] : array(),
                'ids'     => isset( self::$active_selectors['ids'] ) ? self::$active_selectors['ids'] : array(),
                'tags'    => isset( self::$active_selectors['tags'] ) ? self::$active_selectors['tags'] : array(),
                'time'    => time(),
            )
        );
        if ( is_string( $payload ) ) {
            self::write_file( self::selector_sidecar_path(), $payload );
        }
    }

    private static function build_excluded_selectors() {

        self::$safelist_patterns = array(
            '.elementor-popup-modal',
            '.elementor-has-item-ratio',
            '#elementor-device-mode',
            '.elementor-sticky--active',
            '.dialog-type-lightbox',
            '.dialog-widget-content',
            '.lazyloaded',
            '.elementor-nav-menu',
            '.elementor-motion-effects-container',
            '.elementor-motion-effects-layer',
            '.animated',
            // (2.5.1) Elementor entrance-animation helpers — JS-toggled, never
            // in the server DOM. `.animated` above doesn't cover the -slow/
            // -fast variants (the boundary check excludes hyphens), and
            // `.elementor-invisible` must survive or animated elements stay
            // visibility:hidden forever when their rule pair is stripped.
            '.animated-slow',
            '.animated-fast',
            '.elementor-invisible',
            '.elementor-animated-content',
            '.elementor-background-slideshow',
            '.elementor-background-slideshow__slide__image',
            '.gb-menu-container--mobile',
            '.toggled',
            '.splide-initialized',
            '.splide',
            '.splide-slider',
            // (2.6.7) Swiper state classes. Swiper is the slider inside
            // Elementor, WooCommerce galleries and most block themes, and it
            // adds all of these at init — so none of them exist in the
            // server-rendered DOM the build reads. Their rules were therefore
            // stripped while the `:not(.swiper-initialized)` pre-init rules
            // survived, which is the exact asymmetry that leaves a slider
            // collapsed or holding empty space until the visitor interacts.
            // Reported on a live store running Delay JS, where the slider's
            // own script never runs until first interaction either.
            '.swiper-initialized',
            '.swiper-horizontal',
            '.swiper-backface-hidden',
            '.swiper-slide-active',
            '.swiper-pointer-events',
            // Sticky-header spacer. Themes insert it with JS to reserve the
            // header's height once the header goes fixed; losing its rules
            // leaves a gap (or a jump) above the fold.
            '.header-placeholder',
            '.active',
            '.dropdown-nav-special-toggle',
            '.wp-embed-responsive',
            '.wp-block-embed',
            '.wp-block-embed__wrapper',
            '.wp-caption',
            // Mobile-specific patterns — JS-toggled classes that won't appear
            // in the server-rendered DOM but are essential for responsive UIs.
            '.mobile-menu',
            '.mobile-nav',
            '.menu-toggle',
            '.nav-toggle',
            '.hamburger',
            '.off-canvas',
            '.offcanvas',
            '.mobile-header',
            '.site-navigation-mobile',
            '.ast-mobile-header-wrap',
            '.ast-mobile-menu-buttons',
            '.kadence-mobile-navigation',
            '.mobile-toggled',
            '.genesis-responsive-menu',
            '.timber-mobile',
        );

        // ── Astra `.ast-header-break-point` (desktop exclusion) ──
        // Astra adds this body class via JS only *below* its header breakpoint
        // (mobile / narrow viewports), so it never appears in the desktop
        // server-rendered DOM. Safelisting it unconditionally drags Astra's
        // entire mobile-header CSS into the desktop Used CSS for no benefit.
        //
        // Keep it ONLY when:
        //   • Separate Mobile Cache is OFF — a single Used CSS file then serves
        //     every device, so the class must survive for mobile menus; OR
        //   • this build is the mobile request — its own `-mobile` variant
        //     legitimately needs the class.
        // Drop it only when building the DESKTOP variant with Separate Mobile
        // Cache ON. Mirrors the desktop/mobile split in process_buffer().
        $separate_mobile = (int) EasyOpt_Config::get( 'cache_separate_mobile', 1 );
        $is_mobile_req   = class_exists( 'EasyOpt_Cache' ) && EasyOpt_Cache::is_mobile();
        if ( ! $separate_mobile || $is_mobile_req ) {
            self::$safelist_patterns[] = '.ast-header-break-point';
        }

        $user = EasyOpt_Config::get( 'unused_css_exclude_selectors', '' );
        if ( ! empty( $user ) ) {
            $lines = array_filter( array_map( 'trim', explode( "\n", $user ) ) );
            self::$safelist_patterns = array_merge( self::$safelist_patterns, $lines );
        }

        self::$safelist_patterns = apply_filters( 'easyopt_rucss_excluded_selectors', self::$safelist_patterns );

        // (2.5.4 / perf #18) Pre-compile: every pattern was individually
        // preg_quote()d + preg_match()ed per selector; a single alternation
        // with the same boundary lookahead is semantically identical (the
        // lookahead applies to whichever alternative matched) and one
        // dispatch. Rebuilt here on every build entry, so filter/user-line
        // changes always take effect.
        self::$safelist_regex = null;
        $easyopt_quoted = array();
        foreach ( self::$safelist_patterns as $easyopt_excl ) {
            $easyopt_excl = (string) $easyopt_excl;
            if ( '' !== $easyopt_excl ) {
                $easyopt_quoted[] = preg_quote( $easyopt_excl, '#' );
            }
        }
        if ( ! empty( $easyopt_quoted ) ) {
            self::$safelist_regex = '#(' . implode( '|', $easyopt_quoted ) . ')' . self::SAFELIST_BOUNDARY . '#';
        }
    }

    /* ───────────────────────────────────────────────
     *  Excluded stylesheets
     * ─────────────────────────────────────────────── */

    private static function get_excluded_stylesheets() {

        $defaults = array(
            //'dashicons.min.css',
            //'/uploads/elementor/css/post-',
            //'animations.min.css',
            //'/animations/',
            '/uploads/oxygen/css/',
            '/uploads/bb-plugin/cache/',
            '/uploads/generateblocks/',
            //'/et-cache/',
            '/wp-content/uploads/bricks/css/post-',
        );

        $user = EasyOpt_Config::get( 'unused_css_exclude_stylesheets', '' );
        if ( ! empty( $user ) ) {
            $lines    = array_filter( array_map( 'trim', explode( "\n", $user ) ) );
            $defaults = array_merge( $defaults, $lines );
        }

        return apply_filters( 'easyopt_rucss_excluded_stylesheets', $defaults );
    }

    /* ───────────────────────────────────────────────
     *  Passthrough stylesheets
     * ─────────────────────────────────────────────── */

    /**
     * Stylesheets included in Used CSS WHOLE, without selector filtering.
     *
     * WHY THIS EXISTS
     *
     * Page builders like Divi already emit per-page CSS: /et-cache/ contains
     * only the rules for the modules on that one page. Running our selector
     * matcher over it is not just redundant, it is dangerous — Divi adds
     * classes at runtime (sliders, tabs, toggles, fullwidth headers), so a
     * matcher scanning the static HTML drops rules that are needed a moment
     * later and the page visibly breaks after JS runs.
     *
     * A passthrough sheet is treated as normal in every other respect: it is
     * collected in source order, wrapped in its media query if it has one,
     * and its original <link> still receives whatever Stylesheet Behavior is
     * configured (delay / async / remove). The ONLY difference is that its
     * rules are copied verbatim instead of filtered.
     *
     * Note the precedence: get_excluded_stylesheets() is checked first, so a
     * pattern listed in BOTH lists is excluded and never reaches here.
     */
    private static function get_passthrough_stylesheets() {

        $defaults = array(
            // Divi. Per-page by construction, and heavily JS-driven.
            '/et-cache/',
			'/uploads/elementor/css/post-',
        );
        $user = EasyOpt_Config::get( 'unused_css_passthrough_stylesheets', '' );
        if ( ! empty( $user ) ) {
            $lines    = array_filter( array_map( 'trim', explode( "\n", $user ) ) );
            $defaults = array_merge( $defaults, $lines );
        }

        return apply_filters( 'easyopt_rucss_passthrough_stylesheets', $defaults );
    }

    /**
     * Prepare a passthrough stylesheet.
     *
     * Strip the BOM, then MINIFY (strip comments + collapse whitespace) and
     * brace-balance the file. The content is NOT parsed or pruned — every rule
     * is kept and url() values are left untouched — so builder CSS that relies
     * on JS-added classes and per-page rules is preserved exactly; only comments
     * and redundant whitespace are removed. Balancing ensures a single unclosed
     * block (e.g. an Elementor "Custom CSS" typo leaving an `@media(max-width:
     * 700px){` open) cannot leak into and mobile-scope the rest of the Used
     * CSS. (2.6.4)
     */
    private static function passthrough_css( $css ) {
        $css = (string) preg_replace( '/^\xEF\xBB\xBF/', '', (string) $css );
        return EasyOpt_CSS_Tokenizer::balance( EasyOpt_CSS_Tokenizer::minify( $css ) );
    }

    /* ───────────────────────────────────────────────
     *  URL exclusion (pages to skip entirely)
     * ─────────────────────────────────────────────── */

    private static function is_url_excluded() {

        if ( is_feed() || is_embed() || is_preview() || is_customize_preview() ) {
            return true;
        }

        $excludes = EasyOpt_Config::get( 'unused_css_exclude_urls', '' );
        if ( empty( $excludes ) ) {
            return false;
        }

        $current = home_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );
        $lines   = array_filter( array_map( 'trim', explode( "\n", $excludes ) ) );

        foreach ( $lines as $pattern ) {
            if ( '' === $pattern ) {
                continue;
            }
            // (2.6.5) Shared wildcard-aware matcher — consistent with every
            // other Exclude URLs field.
            if ( self::url_excludes( $current, $pattern ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wildcard-or-substring URL match via the plugin-wide canonical matcher,
     * with a substring fallback if cache-common.php is not loaded.
     *
     * @since 2.6.5
     */
    private static function url_excludes( $url, $pattern ) {
        if ( function_exists( '\\EasyOpt\\Cache\\url_matches_pattern' ) ) {
            return \EasyOpt\Cache\url_matches_pattern( $url, $pattern );
        }
        $pattern = trim( (string) $pattern );
        return '' !== $pattern && false !== stripos( (string) $url, $pattern );
    }

    /* ───────────────────────────────────────────────
     *  CSS parsing & unused-rule removal
     * ─────────────────────────────────────────────── */

    private static function strip_unused( $stylesheet_url, $css ) {

        // BOM removal.
        $css = preg_replace( '/^\xEF\xBB\xBF/', '', $css );

        // Drop invalid UTF-8 byte sequences so a mis-encoded stylesheet never
        // leaks bad bytes into the inlined <style>. The tokenizer is byte-safe
        // and would not choke either way (unlike the old parser, which threw),
        // so this is now purely defensive. Valid UTF-8 passes through untouched.
        if ( function_exists( 'iconv' ) ) {
            $iconv_css = @iconv( 'UTF-8', 'UTF-8//IGNORE', $css );
            if ( false !== $iconv_css ) {
                $css = $iconv_css;
            }
        }

        // Normalise @layer declarations so parser doesn't consume the file.
        $css = preg_replace( '/@layer\s+([^;{}]+);/', '@layer $1 {}', $css );

        // Temporarily bump limits for large files (Avada combined CSS can be 500KB+).
        $original_memory = ini_get( 'memory_limit' );
        $original_time   = (int) ini_get( 'max_execution_time' );

        $mem_bytes = wp_convert_hr_to_bytes( $original_memory );

        // (2.5.7) VERIFY the raise. @ini_set() returns false when memory_limit
        // is locked (php_admin_value in an FPM pool, hardened php.ini,
        // ini_set in disable_functions) — common on managed and shared hosts.
        // The result was previously discarded and the @ suppressed the notice,
        // so the parse proceeded believing it had 512M when it did not.
        $mem_ok = true;
        if ( $mem_bytes > 0 && $mem_bytes < 536870912 ) {
            $mem_ok    = ( function_exists( 'ini_set' ) && false !== @ini_set( 'memory_limit', '512M' ) );
            $mem_bytes = $mem_ok ? 536870912 : $mem_bytes;
        }
        if ( $original_time > 0 && $original_time < 120 ) {
            if ( function_exists( 'set_time_limit' ) ) { @set_time_limit( 120 ); }
        }

        // A memory-exhaustion fatal is NOT a Throwable and CANNOT be caught by
        // the try/catch below — the request dies mid-output-buffer, producing a
        // truncated page or a white screen with no log line from us. So it has
        // to be prevented rather than handled. Refuse anything whose parse peak
        // cannot fit the budget we actually have and return the stylesheet
        // untouched (fail open — the page still renders, just unoptimised).
        //
        // (2.6.4) The tokenizer's peak is far lower than the old parser's (~10MB
        // vs ~86MB on an 895KB sheet in testing), so the 20x multiplier is now
        // very conservative — left as-is for safety. Filterable so it can be
        // tuned from real-world data.
        $easyopt_css_len    = strlen( $css );
        $easyopt_multiplier = (int) apply_filters( 'easyopt_used_css_memory_multiplier', 20 );
        $easyopt_budget     = ( $mem_bytes > 0 ) ? (int) ( $mem_bytes * 0.5 ) : 67108864;

        if ( $mem_bytes > 0 && ( $easyopt_css_len * $easyopt_multiplier ) > $easyopt_budget ) {
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::warn( 'used-css', sprintf(
                    'Stylesheet skipped: %d KB needs ~%d MB but only %d MB is available%s.',
                    (int) ( $easyopt_css_len / 1024 ),
                    (int) ( $easyopt_css_len * $easyopt_multiplier / 1048576 ),
                    (int) ( $easyopt_budget / 1048576 ),
                    $mem_ok ? '' : ' (memory_limit is locked by the host)'
                ) );
            }
            if ( false !== $original_memory && '' !== (string) $original_memory ) {
                if ( function_exists( 'ini_set' ) ) { @ini_set( 'memory_limit', $original_memory ); }
            }
            return $css;
        }

        try {
            // (2.6.4) Dependency-free tokenizer replaces sabberworm/php-css-parser
            // (and its thecodingmachine/safe transitive dep). Produces the same
            // item structure remove_unused_selectors() consumes, and rewrites
            // relative url() values (the old fix_relative_urls step) internally.
            $css_data = EasyOpt_CSS_Tokenizer::to_items( $css, $stylesheet_url );
            $result   = self::remove_unused_selectors( $css_data );
        } catch ( \Throwable $e ) {
            // Fail soft: never let a parse/processing error bubble up and abort
            // the output-buffer chain (which would stop the page from caching).
            // Return the original CSS untouched so the page still renders.
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::warn( 'used-css', 'Stylesheet skipped (parse/strip failed): ' . $e->getMessage() );
            }
            $result = $css;
        }

        // Always restore the original memory limit. Previously the raised 512M
        // cap leaked and persisted for the rest of the request.
        if ( false !== $original_memory && '' !== (string) $original_memory ) {
            if ( function_exists( 'ini_set' ) ) { @ini_set( 'memory_limit', $original_memory ); }
        }

        return $result;
    }

    private static function remove_unused_selectors( $data ) {

        $rendered = array();

        foreach ( $data as $item ) {

            if ( isset( $item['css'] ) ) {

                $should_render = ! isset( $item['selectors'] ) || 0 !== count(
                    array_filter(
                        $item['selectors'],
                        function ( $selector ) {
                            return self::is_selector_used( $selector );
                        }
                    )
                );

                if ( $should_render ) {
                    $rendered[] = $item['css'];
                }

                continue;
            }

            if ( ! empty( $item['rulesets'] ) ) {
                $child = self::remove_unused_selectors( $item['rulesets'] );

                if ( $child ) {
                    $rendered[] = sprintf( '%s{%s}', $item['at_rule'], $child );
                } elseif ( preg_match( '/^@layer\s/i', trim( $item['at_rule'] ) ) ) {
                    $rendered[] = trim( $item['at_rule'] ) . ';';
                }
            } elseif ( preg_match( '/^@layer\s/i', trim( $item['at_rule'] ) ) ) {
                // A bare `@layer a, b, c;` carries no rules but DEFINES cascade
                // precedence. The tokenizer delivers it as an at-rule block
                // with empty rulesets, so it lands here — and it must survive,
                // or layer order is lost and the page's styles break even though
                // every rule is still present. This branch used to be nested
                // inside the `! empty( rulesets )` guard above, where it could
                // never run for a genuinely empty layer statement.
                $rendered[] = trim( $item['at_rule'] ) . ';';
            }
        }

        return implode( '', $rendered );
    }

    /**
     * Should Used CSS generation be handed to the queue for this request?
     *
     * (2.6.0) True only when every one of these holds:
     *
     *   • Cache Preload is ON. Without it nothing drains the queue, so
     *     deferring would mean the page NEVER gets its Used CSS. On those
     *     sites we keep the old inline behaviour — slower first visit, but a
     *     result the user actually receives.
     *   • This is NOT a preload render. Preload IS the worker; if it deferred
     *     too, nothing would ever build anything.
     *   • This is NOT a live-buffer-skipped host. There the preloader is the
     *     only thing that caches pages at all, so generation must happen in
     *     the render that is doing the caching.
     *   • The context has not exhausted its build attempts. A stylesheet that
     *     cannot be fetched, or one the memory guard keeps refusing, must not
     *     leave a page permanently unoptimised AND permanently re-queued —
     *     after the cap we fall back to inline generation and let the existing
     *     confidence gate decide.
     *
     * @since 2.6.0
     * @return bool
     */
    private static function should_defer_generation() {

        // ── DEFAULT: OFF. Generate inline, as before 2.6.0. ──────────────
        //
        // Deferring was a bad trade in practice. On a preloaded site almost
        // nobody hits a cold page, so the inline cost lands on a handful of
        // visitors — while deferral leaves REAL pages serving unoptimised for
        // as long as the queue takes to reach them, and makes a PageSpeed run
        // measure a page without its Used CSS. A small bounded cost was traded
        // for a constant visible one.
        //
        // The queued path remains available for very large sites where the
        // per-page build genuinely is intolerable, but it must be asked for:
        //
        //   add_filter( 'easyopt_defer_used_css_generation', '__return_true' );
        //
        // @since 2.6.0
        // @param bool   $defer   Default false — inline generation.
        // @param string $context Used CSS context key.
        if ( ! (bool) apply_filters( 'easyopt_defer_used_css_generation', false, self::$page_context ) ) {
            return false;
        }

        // ── Contexts that are NEVER deferred, whatever the filter says ────
        //
        // The front page and blog index are the PageSpeed target and the first
        // thing a site owner looks at. Serving either unoptimised while a
        // queue catches up is the single most damaging version of this
        // behaviour, so they always build inline — 3 seconds once is the
        // correct price for those two pages.
        $never_defer = (array) apply_filters(
            'easyopt_used_css_never_defer',
            array( 'front', 'home' ),
            self::$page_context
        );
        $bare_context = preg_replace( '/-mobile$/', '', (string) self::$page_context );
        if ( in_array( $bare_context, $never_defer, true ) ) {
            return false;
        }

        if ( ! class_exists( 'EasyOpt_Cache_Preload' ) ) {
            return false;
        }
        if ( ! (int) EasyOpt_Config::get( 'cache_preload', 0 ) ) {
            return false;
        }
        // Preload renders are the worker — they must always build inline.
        if ( defined( 'EASYOPT_IS_PRELOAD_REQUEST' ) && EASYOPT_IS_PRELOAD_REQUEST ) {
            return false;
        }
        if ( method_exists( 'EasyOpt_Cache_Preload', 'is_runner_render' )
            && EasyOpt_Cache_Preload::is_runner_render() ) {
            return false;
        }
        // Hosts where our live buffer is unreliable: the preloader is the only
        // thing that caches, so it has to generate in that same render.
        if ( method_exists( 'EasyOpt_Cache_Preload', 'live_buffer_skipped' )
            && EasyOpt_Cache_Preload::live_buffer_skipped() ) {
            return false;
        }
        // While a preload run is active the crawl will reach this URL on its
        // own. Enqueuing another task for it just competes with the run that
        // is already going to fix it.
        if ( in_array( (string) get_option( 'easyopt_cache_preload_status', 'idle' ), array( 'running', 'paused' ), true ) ) {
            return false;
        }

        // Bounded: after repeated failures, build inline rather than loop.
        $attempts = (int) get_transient( 'easyopt_rucss_defer_' . md5( (string) self::$page_context ) );
        if ( $attempts >= 3 ) {
            return false;
        }
        set_transient(
            'easyopt_rucss_defer_' . md5( (string) self::$page_context ),
            $attempts + 1,
            30 * MINUTE_IN_SECONDS
        );

        return true;
    }

    private static function is_selector_used( $selector ) {

        // (2.6.4) Record every class this stylesheet's rules can actually match,
        // building the per-type CSS class universe (only while a build is
        // collecting — null on cache hits and in isolated tests). Cheap: one
        // set write per class token.
        if ( null !== self::$css_class_universe && ! empty( $selector['classes'] ) ) {
            foreach ( (array) $selector['classes'] as $easyopt_cu ) {
                self::$css_class_universe[ $easyopt_cu ] = 1;
            }
        }

        if ( ':root' === ( $selector['selector'] ?? '' ) ) {
            return true;
        }

        if ( ! empty( $selector['atts'] ) && empty( $selector['classes'] ) && empty( $selector['ids'] ) && empty( $selector['tags'] ) ) {
            return true;
        }

        if ( ! empty( self::$safelist_patterns ) ) {
            // (2.7.2) Match the raw selector AND its unescaped form, so an
            // exclusion typed as `md:hidden` protects `.md\:hidden`.
            $texts = array( $selector['selector'] ?? '' );
            if ( isset( $selector['plain'] ) ) {
                $texts[] = $selector['plain'];
            }
            foreach ( $texts as $text ) {
                // (2.5.4 / perf #18) One compiled alternation when available;
                // per-pattern loop kept as the fallback (e.g. compile skipped).
                if ( null !== self::$safelist_regex ) {
                    if ( preg_match( self::$safelist_regex, $text ) ) {
                        return true;
                    }
                } else {
                    foreach ( self::$safelist_patterns as $excl ) {
                        if ( preg_match( '#(' . preg_quote( $excl, '#' ) . ')' . self::SAFELIST_BOUNDARY . '#', $text ) ) {
                            return true;
                        }
                    }
                }
            }
        }

        foreach ( array( 'classes', 'ids', 'tags' ) as $type ) {
            if ( ! empty( $selector[ $type ] ) ) {
                foreach ( (array) $selector[ $type ] as $target ) {
                    if ( ! isset( self::$active_selectors[ $type ][ $target ] ) ) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /* ───────────────────────────────────────────────
     *  URL-type slug for cache file naming
     * ─────────────────────────────────────────────── */

    public static function get_url_type( $post_id = '' ) {

        $type = '';

        // (2.4.5) "Process Post Types Only" (default ON) → one shared Used CSS
        // per type (all posts share 'post', all categories share 'category', …)
        // with Pages always generated per-page. OFF → every post / category /
        // tag / taxonomy term is processed separately (its own per-URL context).
        $shared = ! class_exists( 'EasyOpt_Config' )
            || (int) EasyOpt_Config::get( 'unused_css_post_types_only', 1 );

        if ( ! empty( $post_id ) ) {
            $pt = get_post_type( $post_id );
            if ( 'page' === $pt ) {
                if ( (int) get_option( 'page_on_front' ) === (int) $post_id ) {
                    $type = 'front';
                } elseif ( (int) get_option( 'page_for_posts' ) === (int) $post_id ) {
                    $type = 'home';
                } else {
                    $type = 'page-' . $post_id;
                }
            } else {
                $type = $pt ? $pt : 'single';
                // (EO-02) Mirror the render-branch WooCommerce product-type
                // suffix so variable/grouped/external products invalidate the
                // same key they were written under.
                $type = self::wc_product_type_suffix( (int) $post_id, $type );
                if ( ! $shared ) {
                    $type .= '-' . (int) $post_id;
                }
            }
            return $type;
        }

        global $wp_query;

        if ( ! is_object( $wp_query ) ) {
            return '';
        }

        if ( $wp_query->is_page ) {
            $type = is_front_page() ? 'front' : ( ! empty( $wp_query->post ) ? 'page-' . $wp_query->post->ID : 'page' );
        } elseif ( $wp_query->is_home ) {
            $type = 'home';
        } elseif ( $wp_query->is_single ) {
            $pt   = get_post_type();
            $type = $pt ? $pt : 'single';
            $type = self::wc_product_type_suffix( (int) get_the_ID(), $type );

            if ( ! $shared ) {
                $type .= '-' . (int) get_the_ID();
            }
        } elseif ( $wp_query->is_category ) {
            $type = $shared ? 'category' : 'category-' . (int) get_queried_object_id();
        } elseif ( $wp_query->is_tag ) {
            $type = $shared ? 'tag' : 'tag-' . (int) get_queried_object_id();
        } elseif ( $wp_query->is_tax ) {
            $type = $shared ? 'tax' : 'tax-' . (int) get_queried_object_id();
        } elseif ( $wp_query->is_archive ) {
            if ( $wp_query->is_post_type_archive() ) {
                $type = 'archive-' . get_post_type();
            } elseif ( $wp_query->is_day ) {
                $type = 'day';
            } elseif ( $wp_query->is_month ) {
                $type = 'month';
            } elseif ( $wp_query->is_year ) {
                $type = 'year';
            } elseif ( $wp_query->is_author ) {
                $type = 'author';
            } else {
                $type = 'archive';
            }
        } elseif ( $wp_query->is_search ) {
            $type = 'search';
        } elseif ( $wp_query->is_404 ) {
            $type = '404';
        }

        return $type;
    }

    /* ───────────────────────────────────────────────
     *  Helpers
     * ─────────────────────────────────────────────── */

    /**
     * Fetch a remote stylesheet (Google Fonts, Bunny Fonts, Adobe Fonts, …)
     * and cache the response so subsequent Used-CSS rebuilds don't re-fetch.
     *
     * Only allow-listed font CDN hosts are fetched — we never blindly pull
     * arbitrary cross-origin URLs. Use the `easyopt_rucss_external_css_hosts`
     * filter to add custom hosts.
     */
    private static function fetch_external_css( $url ) {

        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( empty( $host ) ) {
            return '';
        }
        $host = strtolower( $host );

        $allowed = apply_filters( 'easyopt_rucss_external_css_hosts', array(
            'fonts.googleapis.com',
            'fonts.bunny.net',
            'use.typekit.net',
            'use.fontawesome.com',
            'cloud.typography.com',
        ) );

        if ( ! in_array( $host, array_map( 'strtolower', (array) $allowed ), true ) ) {
            return '';
        }

        $key    = 'easyopt_rcss_' . md5( $url );
        $cached = get_transient( $key );
        if ( false !== $cached ) {
            return (string) $cached;
        }

        // Pretend to be a modern browser so Google Fonts hands us woff2 (not woff/ttf).
        $response = wp_remote_get( $url, array(
            'timeout'    => 8,
            'user-agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'headers'    => array( 'Accept' => 'text/css,*/*;q=0.1' ),
        ) );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            // Cache failures briefly so we don't hammer a flaky endpoint on every page rebuild.
            set_transient( $key, '', HOUR_IN_SECONDS );
            return '';
        }

        $body = (string) wp_remote_retrieve_body( $response );
        if ( '' === $body ) {
            set_transient( $key, '', HOUR_IN_SECONDS );
            return '';
        }

        // 12-hour cache. Cleared on switch_theme / customize_save / Used CSS clear.
        set_transient( $key, $body, 12 * HOUR_IN_SECONDS );
        return $body;
    }

    /**
     * Decode HTML entities in an attribute value (e.g. `&#038;` → `&`).
     */
    private static function decode_attr( $value ) {
        return html_entity_decode( (string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    }

    /**
     * Read a file safely. Returns string content or empty string on failure.
     */
    private static function read_file( $path ) {
        if ( ! is_string( $path ) || '' === $path ) {
            return '';
        }
        $contents = @file_get_contents( $path );
        return ( false === $contents ) ? '' : $contents;
    }

    /**
     * Write a file with an exclusive lock. Returns true on success.
     */
    private static function write_file( $path, $contents ) {
        if ( ! is_string( $path ) || '' === $path ) {
            return false;
        }
        $result = @file_put_contents( $path, $contents, LOCK_EX );
        return ( false !== $result );
    }

    /**
     * Minimum byte size below which a Used CSS result is considered a failed
     * generation rather than a legitimately tiny page. Filterable.
     */
    private static function min_used_css_bytes() {
        return (int) apply_filters( 'easyopt_rucss_min_bytes', 150 );
    }

    /**
     * (perf) Per-context negative-cache key. A failed build for one context
     * must not suppress every other context, so the key is bound to
     * self::$page_context (device suffix included).
     */
    private static function rucss_fail_key() {
        return 'easyopt_rucss_fail_' . md5( (string) self::$page_context );
    }

    /** True when a build for THIS context failed within the negative-cache TTL. */
    private static function build_recently_failed() {
        if ( '' === (string) self::$page_context ) {
            return false;
        }
        return false !== get_transient( self::rucss_fail_key() );
    }

    /**
     * Record that generation for THIS context failed, so the next request
     * skips the expensive build instead of repeating it. Short, filterable
     * TTL so a transient cause self-heals; a manual clear deletes it outright.
     */
    private static function mark_build_failed() {
        if ( '' === (string) self::$page_context ) {
            return;
        }
        $ttl = (int) apply_filters( 'easyopt_rucss_fail_ttl', HOUR_IN_SECONDS );
        if ( $ttl < 1 ) {
            return; // filter can disable the negative cache entirely.
        }
        set_transient( self::rucss_fail_key(), 1, $ttl );
    }

    /**
     * Confidence gate (2.3.0). Decides whether a freshly generated Used CSS
     * string is trustworthy enough to (a) cache and (b) justify stripping /
     * delaying the original stylesheets. Conservative on purpose: a failed
     * gate simply means this page keeps its original styling — no breakage.
     *
     * @param string $css   Generated used CSS.
     * @param int    $seen  Candidate stylesheets considered.
     * @param int    $got   Stylesheets that yielded any CSS.
     */
    private static function passes_confidence_gate( $css, $seen, $got ) {
        $css = (string) $css;

        // Must be non-trivially sized.
        if ( strlen( $css ) < self::min_used_css_bytes() ) {
            return false;
        }
        // Must contain at least one real rule block.
        if ( false === strpos( $css, '{' ) || false === strpos( $css, '}' ) ) {
            return false;
        }
        // There were stylesheets to process but none produced any CSS — a
        // strong signal that resolution/parsing failed.
        if ( $seen > 0 && 0 === $got ) {
            return false;
        }
        return (bool) apply_filters( 'easyopt_rucss_confident', true, $css, $seen, $got );
    }

    private static function get_root_dir_path() {

        if ( '' !== self::$root_dir_path ) {
            return self::$root_dir_path;
        }

        $wp_content_relative = str_replace(
            array( trailingslashit( home_url() ), trailingslashit( site_url() ) ),
            '',
            content_url(),
            $count
        );

        if ( empty( $count ) ) {
            $path                = wp_parse_url( home_url(), PHP_URL_PATH );
            $parsed_url          = trailingslashit( $path ? str_replace( $path, '', home_url() ) : home_url() );
            $wp_content_relative = str_replace( $parsed_url, '', content_url() );
        }

        $pos = strrpos( WP_CONTENT_DIR, $wp_content_relative );
        if ( false !== $pos ) {
            self::$root_dir_path = substr_replace( WP_CONTENT_DIR, '', $pos, strlen( $wp_content_relative ) );
        } else {
            self::$root_dir_path = WP_CONTENT_DIR;
        }

        self::$root_dir_path = trailingslashit( self::$root_dir_path );

        return self::$root_dir_path;
    }

    /**
     * Resolve a stylesheet URL to an absolute local file path.
     * Returns false for genuinely external URLs (Google Fonts etc.).
     */
    private static function get_local_path( $url ) {

        if ( empty( $url ) ) {
            return false;
        }

        // Strip query + fragment.
        $clean_url = strtok( $url, '?#' );

        if ( 0 === strpos( $clean_url, '//' ) ) {
            $clean_url = ( is_ssl() ? 'https:' : 'http:' ) . $clean_url;
        }

        if ( 0 === strpos( $clean_url, '/' ) && 0 !== strpos( $clean_url, '//' ) ) {
            return self::get_root_dir_path() . ltrim( $clean_url, '/' );
        }

        $local_urls = array(
            trailingslashit( home_url() ),
            trailingslashit( site_url() ),
        );

        $home_no_scheme = preg_replace( '#^https?:#', '', trailingslashit( home_url() ) );
        $site_no_scheme = preg_replace( '#^https?:#', '', trailingslashit( site_url() ) );
        $local_urls[]   = $home_no_scheme;
        $local_urls[]   = $site_no_scheme;

        $cdn_url = apply_filters( 'easyopt_rucss_cdn_url', '' );
        if ( ! empty( $cdn_url ) ) {
            $local_urls[] = trailingslashit( $cdn_url );
        }

        $relative = str_ireplace( $local_urls, '', $clean_url );

        if ( preg_match( '#^https?://#i', $relative ) ) {
            return false;
        }

        return self::get_root_dir_path() . ltrim( $relative, '/' );
    }

    private static function is_valid_buffer( $html ) {

        if ( stripos( $html, '<html' ) === false || stripos( $html, '</body>' ) === false ) {
            return false;
        }
        if ( stripos( $html, '<xsl:stylesheet' ) !== false ) {
            return false;
        }

        $current = home_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );
        foreach ( array( '.xml', '.txt' ) as $ext ) {
            if ( stripos( $current, $ext ) !== false ) {
                return false;
            }
        }

        return true;
    }

    private static function parse_atts( $atts_string ) {

        $atts = array();

        if ( empty( $atts_string ) ) {
            return $atts;
        }

        preg_match_all(
            '/([a-zA-Z0-9\-_:.]+)(?:\s*=\s*(?|(?:"([^"]*)")|(?:\'([^\']*)\')|(\S+)))?/s',
            $atts_string,
            $matches,
            PREG_SET_ORDER
        );

        foreach ( $matches as $match ) {
            if ( ! empty( $match[1] ) ) {
                $atts[ $match[1] ] = isset( $match[2] ) ? $match[2] : '';
            }
        }

        return $atts;
    }

    private static function match_in_array( $string, $array ) {

        if ( empty( $array ) ) {
            return false;
        }

        foreach ( (array) $array as $item ) {
            if ( '' === $item ) {
                continue;
            }
            if ( false !== stripos( $string, $item ) ) {
                return true;
            }
        }

        return false;
    }

    /* ───────────────────────────────────────────────
     *  Cache clearing
     * ─────────────────────────────────────────────── */

    public static function clear_all_used_css() {
        // Full reset — also drops the collected-fonts sidecars. Used by theme
        // switch / Customizer save, where the fonts themselves may change.
        self::do_clear_used_css( true );
    }

    /**
     * Clear ONLY the generated Used CSS (and its option markers / remote-CSS
     * transients). Preserves the collected-fonts sidecars AND the beacon JS
     * classes — both are still valid and are re-read when the Used CSS
     * regenerates. Used by the manual "Clear used CSS" button and the lazy-load
     * toggle.
     */
    public static function clear_used_css_only() {
        self::do_clear_used_css( false );
    }

    private static function do_clear_used_css( $clear_fonts ) {

        // (perf) Drop every negative-cache marker so a clear forces a fresh
        // build attempt for all contexts (e.g. after the user fixes the
        // stylesheet set that was failing to resolve). One-time DB delete on a
        // rare admin path; markers otherwise self-expire via their TTL.
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '\\_transient\\_easyopt\\_rucss\\_fail\\_%'
                OR option_name LIKE '\\_transient\\_timeout\\_easyopt\\_rucss\\_fail\\_%'"
        );

        $upload_dir = wp_get_upload_dir();
        $dir        = trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt/css/';

        if ( is_dir( $dir ) ) {
            $files = glob( $dir . '*.used.css' );
            if ( $files ) {
                foreach ( $files as $file ) {
                    if ( is_file( $file ) ) {
                        @unlink( $file );
                    }
                }
            }

            // (2.5.2) Selector-union sidecars accumulated per shared type.
            $selector_sidecars = glob( $dir . '*.selectors.json' );
            if ( $selector_sidecars ) {
                foreach ( $selector_sidecars as $file ) {
                    if ( is_file( $file ) ) {
                        @unlink( $file );
                    }
                }
            }

            // (2.5.4 / perf #2) Transformed-CSS sidecars.
            foreach ( array( '*.used.final.css', '*.used.final.json' ) as $easyopt_final_pat ) {
                $easyopt_final_files = glob( $dir . $easyopt_final_pat );
                if ( $easyopt_final_files ) {
                    foreach ( $easyopt_final_files as $file ) {
                        if ( is_file( $file ) ) {
                            @unlink( $file );
                        }
                    }
                }
            }

            // (2.4.4) File-mode served sidecars derived from the .used.css.
            $served = glob( $dir . '*.served.css' );
            if ( $served ) {
                foreach ( $served as $file ) {
                    if ( is_file( $file ) ) {
                        @unlink( $file );
                    }
                }
            }

            $inline_dir = $dir . 'inline/';
            if ( is_dir( $inline_dir ) ) {
                $inline_files = glob( $inline_dir . '*.css' );
                if ( $inline_files ) {
                    foreach ( $inline_files as $file ) {
                        if ( is_file( $file ) ) {
                            @unlink( $file );
                        }
                    }
                }
            }

            // (2.6.4) Legacy cleanup: metadata sidecars used to live in this
            // css/ folder before they moved to cache/easyopt/meta/. Remove any
            // stragglers from pre-2.6.4 installs so css/ holds only served CSS.
            foreach ( array( '*.eo-classes.json', '*.eo-universe.json', '*.eo-fonts.json' ) as $easyopt_legacy_pat ) {
                $easyopt_legacy = glob( $dir . $easyopt_legacy_pat );
                if ( $easyopt_legacy ) {
                    foreach ( $easyopt_legacy as $file ) {
                        if ( is_file( $file ) ) {
                            @unlink( $file );
                        }
                    }
                }
            }
        }

        // The collected-fonts sidecars (now in cache/easyopt/meta/fonts/) are
        // dropped ONLY on a full reset ($clear_fonts) — theme switch / Customizer
        // save — because the fonts may have changed. The manual "Clear used CSS"
        // button and the lazy-load toggle keep them (still valid; re-read on
        // rebuild). Beacon-captured JS classes/universe are never cleared here;
        // they follow theme switch / plugin activate-deactivate instead.
        if ( $clear_fonts && class_exists( 'EasyOpt_Fonts' ) ) {
            EasyOpt_Fonts::clear_sidecars();
        }

        delete_option( 'easyopt_used_css_time' );
        // Reset fonts processed-marker (1.4.1+).
        delete_option( 'easyopt_fonts_processed' );

        // (2.3.3 / B13) Also drop the remote-CSS fetch transients. The class
        // docs always claimed these were cleared on theme switch, but nothing
        // was wired — a theme's old external-CSS payloads survived until
        // their TTLs lapsed. One bulk DELETE; same escaped-LIKE pattern the
        // uninstaller uses.
        global $wpdb;
        // (2.5.4 / perf #40) Targeted coherence instead of the old blanket
        // wp_cache_flush_group('options') — which forced a keyspace SCAN of
        // the whole options group in Redis on every Used-CSS clear (and did
        // not even cover the group transients actually live in when an
        // external object cache is active).
        //
        //  • No external object cache: rcss transients are options-table rows
        //    (deleted below) that get_option() may have cached individually —
        //    read their names first and delete exactly those keys.
        //  • External object cache: the transients never had DB rows; they
        //    live in the 'transient'/'transient_timeout' groups — flush just
        //    those two (far smaller than the options group, and the correct
        //    home of the data).
        $easyopt_rcss_names = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE '\_transient\_easyopt\_rcss\_%'
                OR option_name LIKE '\_transient\_timeout\_easyopt\_rcss\_%'"
        );
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '\_transient\_easyopt\_rcss\_%'
                OR option_name LIKE '\_transient\_timeout\_easyopt\_rcss\_%'"
        );
        if ( ! empty( $easyopt_rcss_names ) ) {
            foreach ( $easyopt_rcss_names as $easyopt_rcss_name ) {
                wp_cache_delete( $easyopt_rcss_name, 'options' );
            }
            wp_cache_delete( 'notoptions', 'options' );
        }
        if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache()
            && function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( 'transient' );
            wp_cache_flush_group( 'transient_timeout' );
        }
    }

    /**
     * Delete the Used CSS file(s) for a specific URL TYPE so they regenerate
     * on the next render of that type. Used when a type's above-the-fold font
     * set is first learned: the Used CSS was generated before the fonts were
     * known (so it stripped them) and must be re-baked to keep them. Surgical —
     * only this type's desktop + mobile variants. Does NOT touch cached pages
     * (they re-inline the fresh critical CSS when they next regenerate), so
     * there is no raw-page window on hosts where a miss serves un-optimized HTML.
     */
    /**
     * (2.4.4) File injection method: write the render-time (font-transformed)
     * Used CSS to a served sidecar and return its URL + content version.
     *
     * Keyed by content hash so (a) the file is always written BEFORE it is
     * linked — no 404'd-stylesheet window when the source/signature changes
     * (F6) — and (b) a changed above-the-fold signature produces a new ?ver,
     * busting browser/CDN caches. Written only when the content changed.
     *
     * @return array{url:string,ver:string}|false
     */
    private static function write_served_file( $context, $css ) {
        $context = sanitize_file_name( (string) $context );
        if ( '' === $context || '' === (string) $css ) {
            return false;
        }
        if ( '' === self::$cache_dir || '' === self::$cache_url ) {
            return false;
        }
        $ver  = substr( md5( $css ), 0, 12 );
        $path = self::$cache_dir . $context . '.served.css';
        $need = true;
        if ( is_file( $path ) ) {
            $cur = @md5_file( $path );
            if ( $cur && substr( $cur, 0, 12 ) === $ver ) {
                $need = false;
            }
        }
        if ( $need && ! self::write_file( $path, $css ) ) {
            return false;
        }
        return array(
            'url' => self::$cache_url . $context . '.served.css',
            'ver' => $ver,
        );
    }

    /**
     * The four Used-CSS cache filenames for a type: desktop/mobile × used/served.
     * Single source of truth so the post-save, type, and admin-bar clears can't
     * diverge (the drift that caused EO-02 / EO-04).
     */
    private static function used_css_filenames( $type ) {
        return array(
            $type . '.used.css',
            $type . '-mobile.used.css',
            $type . '.served.css',
            $type . '-mobile.served.css',
            // (2.5.2) Selector-union sidecars — cleared with their files so a
            // rebuilt type starts a fresh accumulation (stale classes from
            // deleted markup only over-keep, but a clean slate is tighter).
            $type . '.selectors.json',
            $type . '-mobile.selectors.json',
            // (2.5.4 / perf #2) Transformed-CSS sidecars. Signature-guarded
            // (a stale one is ignored), removed here for tidiness.
            $type . '.used.final.css',
            $type . '.used.final.json',
            $type . '-mobile.used.final.css',
            $type . '-mobile.used.final.json',
        );
    }

    /**
     * (EO-02) Append the WooCommerce product-type suffix (variable/grouped/
     * external) to a Used-CSS key, so a product's render key and its
     * invalidation key always match. Returns $base unchanged for simple
     * products and non-products. Used by both branches of get_url_type().
     */
    private static function wc_product_type_suffix( $post_id, $base ) {
        if ( 'product' !== get_post_type( $post_id ) || ! function_exists( 'wc_get_product' ) ) {
            return $base;
        }
        $product = wc_get_product( $post_id );
        if ( $product ) {
            $ptype = $product->get_type();
            if ( in_array( $ptype, array( 'variable', 'grouped', 'external' ), true ) ) {
                return $base . '-' . $ptype;
            }
        }
        return $base;
    }

    public static function clear_type_used_css( $type ) {
        $type = sanitize_file_name( (string) $type );
        if ( '' === $type ) {
            return;
        }
        $dir = trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt/css/';
        foreach ( self::used_css_filenames( $type ) as $filename ) {
            $file = $dir . $filename;
            if ( is_file( $file ) ) {
                @unlink( $file );
            }
        }
    }

    public static function clear_post_used_css( $post_id ) {

        $type = self::get_url_type( $post_id );
        if ( '' === $type ) {
            return;
        }

        $dir = trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt/css/';

        // Clear both desktop and mobile variants (+ file-mode served sidecars).
        foreach ( self::used_css_filenames( $type ) as $filename ) {
            $file = $dir . $filename;
            if ( is_file( $file ) ) {
                @unlink( $file );
            }
        }
    }

    /* ───────────────────────────────────────────────
     *  Admin bar
     * ─────────────────────────────────────────────── */

    public static function admin_bar_menu( $wp_admin_bar ) {

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // The shared 'easyopt-root' parent is registered by the main plugin
        // at priority 80. We attach as children at 999.
        $parent_id = 'easyopt-root';
        if ( ! $wp_admin_bar->get_node( $parent_id ) ) {
            // Defensive — main plugin didn't register (e.g. very old WP). Add ourselves.
            $wp_admin_bar->add_node( array(
                'id'    => $parent_id,
                'title' => __( 'Easy Optimizer', 'easy-optimizer' ),
                'href'  => admin_url( 'admin.php?page=easy-optimizer' ),
            ) );
        }

        // One node per context (never both):
        //   • wp-admin  → "Clear All Used CSS" (omit type ⇒ full reset)
        //   • front-end → "Clear Used CSS (Current)" for this page type
        if ( is_admin() ) {
            $wp_admin_bar->add_node( array(
                'parent' => $parent_id,
                'id'     => 'easyopt-clear-used-css-all',
                'title'  => __( 'Clear All Used CSS', 'easy-optimizer' ),
                'href'   => add_query_arg(
                    array(
                        'action'   => 'easyopt_clear_used_css',
                        '_wpnonce' => wp_create_nonce( 'easyopt_clear_used_css' ),
                    ),
                    admin_url( 'admin-post.php' )
                ),
            ) );
        } else {
            $current_type = self::get_url_type();
            if ( $current_type ) {
                $wp_admin_bar->add_node( array(
                    'parent' => $parent_id,
                    'id'     => 'easyopt-clear-used-css-current',
                    'title'  => __( 'Clear Used CSS (Current)', 'easy-optimizer' ),
                    'href'   => add_query_arg(
                        array(
                            'action'           => 'easyopt_clear_used_css',
                            'type'             => $current_type,
                            '_wp_http_referer' => rawurlencode( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' ),
                            '_wpnonce'         => wp_create_nonce( 'easyopt_clear_used_css' ),
                        ),
                        admin_url( 'admin-post.php' )
                    ),
                ) );
            }
        }
    }

    /* ───────────────────────────────────────────────
     *  Admin-post handler (admin bar GET request)
     * ─────────────────────────────────────────────── */

    public static function handle_clear_used_css() {

        // Capability check FIRST so unauthenticated users don't even see a nonce screen.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to perform this action.', 'easy-optimizer' ), '', array( 'response' => 403 ) );
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'easyopt_clear_used_css' ) ) {
            wp_nonce_ays( '' );
        }

        if ( ! empty( $_GET['type'] ) ) {
            $type = sanitize_file_name( wp_unslash( $_GET['type'] ) );
            if ( '' !== $type ) {
                $dir = trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt/css/';
                // (EO-04) Clear all four variants — used + served, desktop +
                // mobile — matching clear_post_used_css so file-mode served
                // sidecars aren't left stale after a current-page clear.
                foreach ( self::used_css_filenames( $type ) as $filename ) {
                    $file = $dir . $filename;
                    if ( is_file( $file ) ) {
                        @unlink( $file );
                    }
                }
            }
        } else {
            self::clear_all_used_css();
        }

        set_transient( 'easyopt_used_css_cleared', 1, 30 );

        $referer = wp_get_referer();
        wp_safe_redirect( $referer ? esc_url_raw( $referer ) : admin_url() );
        exit;
    }

    /* ───────────────────────────────────────────────
     *  AJAX handler (settings page button)
     * ─────────────────────────────────────────────── */

    public static function ajax_clear_used_css() {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easy-optimizer' ) ), 403 );
        }

        check_ajax_referer( 'easyopt_nonce', 'nonce' );

        self::clear_all_used_css();

        wp_send_json_success( array( 'message' => __( 'All used CSS cleared.', 'easy-optimizer' ) ) );
    }

    /* ───────────────────────────────────────────────
     *  Admin notices
     * ─────────────────────────────────────────────── */

    public static function admin_notices() {

        if ( false === get_transient( 'easyopt_used_css_cleared' ) ) {
            return;
        }

        delete_transient( 'easyopt_used_css_cleared' );
        echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Used CSS cache cleared.', 'easy-optimizer' ) . '</strong></p></div>';
    }

    /* ───────────────────────────────────────────────
     *  Auto-clear on post save
     * ─────────────────────────────────────────────── */

    public static function on_save_post( $post_id ) {

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        self::clear_post_used_css( $post_id );
    }
}
