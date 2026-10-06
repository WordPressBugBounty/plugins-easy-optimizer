<?php
/**
 * Preload LCP — Auto Preload Largest Image.
 *
 * Runtime LCP detection via PerformanceObserver beacon. On first visit per
 * URL type + viewport bucket (mobile/desktop), the beacon reports the LCP
 * image. Subsequent visits get a matching <link rel="preload" as="image"
 * fetchpriority="high"> in <head> and fetchpriority="high" loading="eager"
 * added to the matching <img> tag.
 *
 * Mirrors the Unused CSS module's architecture:
 *  - Per URL type cache (custom table instead of option, plus viewport key)
 *  - Admin-bar "Clear LCP Cache" nodes (all / current)
 *  - Auto-clear on switch_theme
 *  - 30-day TTL on rows
 *  - Beacon stops injecting once both mobile + desktop buckets are warm
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_LCP {

    const DB_VERSION   = '2.0';
    const TTL_DAYS     = 30;
    const MIN_LCP_SIZE = 10000; // pixels² — filter out tiny icons / noise
    const MOBILE_BREAK = 768;   // viewport bucket threshold in px

    /** Hard cap on stored LCP rows (LRU-evicted beyond this). Filterable.
     *  Guards against unbounded growth on sites with effectively-infinite
     *  URLs (faceted search, tracking params, bot query strings). */
    const MAX_ROWS     = 5000;

    /** @var string Cached URL type for current request */
    private static $url_type = '';

    /** @var string Cached normalized URL for current request */
    private static $norm_url = '';

    /** @var string Custom table name (with prefix) */
    private static $table = '';

    /* ───────────────────────────────────────────────
     *  Bootstrap
     * ─────────────────────────────────────────────── */

    /**
     * Called on plugin load. Registers hooks that run on every request.
     */
    public static function init_always() {

        global $wpdb;
        self::$table = $wpdb->prefix . 'easyopt_lcp';

        // Ensure table exists (upgrade path for users updating from older version).
        self::maybe_install_table();

        // ── Unified beacon (2.4.3) ─────────────────────────────────────────
        // The beacon feeds three independent features (LCP preload, JS-class
        // capture for Used CSS, and ATF font preload). It must therefore be
        // wired up if ANY of them is enabled — not only when LCP is on.
        // maybe_enqueue_beacon() computes per-feature "need" flags and ships
        // nothing when everything is already warm.
        add_action( 'wp_ajax_easyopt_beacon',        array( __CLASS__, 'ajax_beacon' ) );
        add_action( 'wp_ajax_nopriv_easyopt_beacon', array( __CLASS__, 'ajax_beacon' ) );
        // Legacy LCP-only endpoint kept for pages cached by an older version
        // whose HTML still references the old beacon script.
        add_action( 'wp_ajax_easyopt_report_lcp',        array( __CLASS__, 'ajax_report_lcp' ) );
        add_action( 'wp_ajax_nopriv_easyopt_report_lcp', array( __CLASS__, 'ajax_report_lcp' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_beacon' ) );

        // ── LCP-specific admin surface ─────────────────────────────────────
        // Only the LCP preload feature owns the admin-bar clear nodes and the
        // theme-switch auto-clear of the LCP table.
        if ( ! (int) EasyOpt_Config::get( 'lcp_preload', 0 ) ) {
            return;
        }

        // Admin-bar: "Clear LCP Cache" nodes. Priority 1000 runs after Unused CSS (999)
        // so we can reuse its parent node if it exists.
        add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_menu' ), 1000 );

        // admin-post.php handler for admin-bar links.
        add_action( 'admin_post_easyopt_clear_lcp', array( __CLASS__, 'handle_clear_lcp' ) );

        // Admin notice after cache clear.
        add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );

        // Auto-clear on theme switch.
        add_action( 'switch_theme', array( __CLASS__, 'clear_all' ) );
        add_action( 'customize_save_after', array( __CLASS__, 'clear_all' ) );
    }

    /* ───────────────────────────────────────────────
     *  Database
     * ─────────────────────────────────────────────── */

    /**
     * Check whether our custom table physically exists.
     */
    private static function table_exists() {

        global $wpdb;

        if ( empty( self::$table ) ) {
            self::$table = $wpdb->prefix . 'easyopt_lcp';
        }

        $found = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::$table ) )
        );

        return ! empty( $found );
    }

    /**
     * Install the custom table if missing. Self-healing:
     *
     *  1. Always verifies the table physically exists (not just the version option)
     *     so a failed earlier install can recover on the next request.
     *  2. Only sets the `easyopt_lcp_db_version` option AFTER confirming the table
     *     was actually created — prevents the "marked installed but no table" trap.
     *  3. Lowercase column types + `PRIMARY KEY  (id)` with two spaces — maximum
     *     dbDelta compatibility across MySQL / MariaDB versions.
     */
    public static function maybe_install_table() {

        global $wpdb;

        if ( empty( self::$table ) ) {
            self::$table = $wpdb->prefix . 'easyopt_lcp';
        }

        // (2.5.4 / perf #26) Version-first fast path — the same trust model
        // the queue and results tables already use. The old order ran a
        // SHOW TABLES probe on EVERY request (init_always) before even
        // reading the version option; now a matching version (shared
        // autoloaded map, legacy option as fallback/seed) returns without
        // touching information_schema. The full path below still verifies
        // real table existence whenever the version doesn't match.
        if ( class_exists( 'EasyOpt_Config' ) && method_exists( 'EasyOpt_Config', 'schema_version' )
            && EasyOpt_Config::schema_version( 'lcp' ) === (string) self::DB_VERSION ) {
            return true;
        }
        if ( self::DB_VERSION === get_option( 'easyopt_lcp_db_version' ) ) {
            if ( class_exists( 'EasyOpt_Config' ) && method_exists( 'EasyOpt_Config', 'set_schema_version' ) ) {
                EasyOpt_Config::set_schema_version( 'lcp', (string) self::DB_VERSION );
            }
            return true;
        }

        $exists = self::table_exists();

        $table           = self::$table;
        $charset_collate = $wpdb->get_charset_collate();

        // Upgrade from the pre-2.0 per-type schema: the old rows are keyed by
        // url_type and are semantically incompatible with the new per-URL
        // model. LCP data is cheap to re-warm from live traffic, so we drop
        // and recreate rather than attempt a fragile dbDelta key migration.
        if ( $exists ) {
            $prev = (string) get_option( 'easyopt_lcp_db_version', '1.0' );
            if ( version_compare( $prev, '2.0', '<' ) ) {
                $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
                self::flush_all_cache_rows();
                $exists = false;
            }
        }

        // dbDelta-friendly SQL: lowercase types, no NULL keywords, two spaces
        // after PRIMARY KEY, column-name lists on one line for KEY.
        //
        // Per-URL keying (2.0): url_hash = md5 of the normalized page URL;
        // the unique key (url_hash, viewport) keeps one LCP per URL+viewport.
        // url_type is retained for "clear current type" grouping; element_kind
        // distinguishes img / img-srcset / background / picture / video-poster.
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            url_hash char(32) NOT NULL,
            url text NOT NULL,
            url_type varchar(191) NOT NULL,
            viewport varchar(20) NOT NULL,
            element_tag varchar(20) NOT NULL,
            element_kind varchar(20) NOT NULL DEFAULT 'img',
            image_url text NOT NULL,
            srcset text,
            sizes varchar(500),
            selector varchar(500),
            lcp_size int(10) unsigned NOT NULL DEFAULT 0,
            created_at int(10) unsigned NOT NULL,
            updated_at int(10) unsigned NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY url_hash_viewport (url_hash,viewport),
            KEY url_type (url_type),
            KEY updated_at (updated_at)
        ) {$charset_collate};";

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta( $sql );

        // Verify. Only stamp the version option if the table really exists now.
        if ( self::table_exists() ) {
            update_option( 'easyopt_lcp_db_version', self::DB_VERSION );
            if ( class_exists( 'EasyOpt_Config' ) && method_exists( 'EasyOpt_Config', 'set_schema_version' ) ) {
                EasyOpt_Config::set_schema_version( 'lcp', (string) self::DB_VERSION ); // (2.5.4 / perf #26)
            }
            return true;
        }

        // Still missing — log once for debugging.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Easy Optimizer] LCP table install failed. wpdb->last_error: ' . $wpdb->last_error );
        }

        // Clear any stale version stamp so the next request retries cleanly.
        delete_option( 'easyopt_lcp_db_version' );

        return false;
    }

    /* ───────────────────────────────────────────────
     *  Beacon — enqueue
     * ─────────────────────────────────────────────── */

    public static function maybe_enqueue_beacon() {

        // Skip on admin, AJAX, feeds.
        if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || is_feed() ) {
            return;
        }

        // WooCommerce dynamic pages — LCP detection is meaningless on
        // Cart/Checkout/My Account (content varies per session).
        if ( EasyOpt_Config::is_woo_dynamic_page() ) {
            return;
        }

        // Skip logged-in users unless explicitly allowed.
        if ( is_user_logged_in() && ! apply_filters( 'easyopt_lcp_logged_in', false ) ) {
            return;
        }

        // Skip excluded URLs.
        if ( self::is_url_excluded() ) {
            return;
        }

        // Determine URL type. Prefer the Used CSS module's value as the single
        // source of truth, since it is what reads the class/font sidecars back
        // (keeps write-key == read-key even if the two implementations drift).
        $url_type = ( class_exists( 'EasyOpt_Unused_CSS' ) && method_exists( 'EasyOpt_Unused_CSS', 'get_url_type' ) )
            ? EasyOpt_Unused_CSS::get_url_type()
            : self::get_url_type();
        if ( empty( $url_type ) ) {
            return;
        }

        $norm_url  = self::get_normalized_url();
        $url_hash  = $norm_url ? md5( $norm_url ) : '';

        // Compute what the server still needs, so the beacon only collects and
        // ships the parts that are cold. Once everything is warm the beacon is
        // not enqueued at all.
        $need_lcp     = (int) EasyOpt_Config::get( 'lcp_preload', 0 )
                        && $url_hash && ! self::is_fully_warm( $url_hash );

        $need_classes = class_exists( 'EasyOpt_Unused_CSS' )
                        && (int) EasyOpt_Config::get( 'unused_css', 0 )
                        && EasyOpt_Unused_CSS::beacon_classes_needed( $url_type );

        // (2.4.4) Collect the above-the-fold font set when EITHER Smart Preload
        // Fonts OR Smart Lazyload Fonts is on. Lazyload's strip and Preload's
        // links are both driven by this signature, so gating collection on
        // Preload alone made Lazyload a silent no-op when used by itself.
        $need_fonts   = class_exists( 'EasyOpt_Fonts' )
                        && ( (int) EasyOpt_Config::get( 'preload_fonts', 0 )
                          || (int) EasyOpt_Config::get( 'lazyload_fonts', 0 ) )
                        && EasyOpt_Fonts::beacon_fonts_needed( $url_type );

        if ( ! $need_lcp && ! $need_classes && ! $need_fonts ) {
            return;
        }

        wp_enqueue_script(
            'easyopt-beacon',
            EASYOPT_URL . 'assets/easyopt-beacon.min.js',
            array(),
            EASYOPT_VERSION,
            true
        );

        wp_localize_script( 'easyopt-beacon', 'easyoptBeacon', array(
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'easyopt_beacon' ),
            'token'       => self::build_beacon_token( $url_type, $norm_url ),
            'urlType'     => $url_type,
            'pageUrl'     => $norm_url,
            'mobileBreak' => self::MOBILE_BREAK,
            'minLcpSize'  => self::MIN_LCP_SIZE,
            'need'        => array(
                'lcp'     => $need_lcp ? 1 : 0,
                'classes' => $need_classes ? 1 : 0,
                'fonts'   => $need_fonts ? 1 : 0,
            ),
        ) );
    }

    /* ───────────────────────────────────────────────
     *  Beacon — AJAX receiver
     * ─────────────────────────────────────────────── */

    /**
     * Validate an incoming beacon: a fresh wp_nonce OR a non-expiring,
     * URL-type-bound HMAC token. See ajax_beacon() for why the token exists.
     *
     * @param string $url_type Sanitized URL type the request claims.
     * @return bool
     */
    private static function beacon_request_is_valid( $url_type, $norm_url = '' ) {
        if ( isset( $_POST['nonce'] )
            && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['nonce'] ) ), 'easyopt_beacon' ) ) {
            return true;
        }
        if ( isset( $_POST['token'] ) ) {
            $token = sanitize_text_field( wp_unslash( $_POST['token'] ) );
            if ( '' === $token ) {
                return false;
            }
            // (2.6.0) Page-bound token.
            if ( '' !== $norm_url
                && hash_equals( self::build_beacon_token( $url_type, $norm_url ), $token ) ) {
                return true;
            }
            // DEPRECATED type-only token. Pages cached by 2.5.x carry it, and
            // rejecting them outright would silently kill LCP / ATF-font
            // collection until every cached page happened to re-render. Accept
            // for one release, then remove — it is what let a token lifted from
            // any page authorise writes for every page of that type.
            if ( (bool) apply_filters( 'easyopt_beacon_accept_legacy_token', true )
                && hash_equals( self::build_beacon_token( $url_type, '' ), $token ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Non-expiring, off-site-unforgeable beacon validation token, bound to the
     * URL type. Mirrors the queue runner-token scheme (wp_salt-keyed HMAC) so
     * the beacon survives the full page-cache lifetime, unlike a wp_nonce.
     *
     * @param string $url_type
     * @return string
     */
    private static function build_beacon_token( $url_type, $norm_url = '' ) {
        return hash_hmac(
            'sha256',
            'easyopt_beacon|' . (string) $url_type . '|' . (string) $norm_url,
            wp_salt( 'auth' )
        );
    }

    /**
     * Unified beacon receiver (2.4.3). One request may carry any combination
     * of: LCP element data, the post-load JS class set, and above-the-fold
     * font URLs. Each part is routed to the module that owns it.
     */
    public static function ajax_beacon() {

        $url_type = isset( $_POST['url_type'] ) ? sanitize_key( wp_unslash( $_POST['url_type'] ) ) : '';
        if ( '' === $url_type ) {
            wp_send_json_error( 'invalid', 400 );
        }
        $url_type = substr( $url_type, 0, 191 );

        // (2.4.5) Accept EITHER a fresh wp_nonce OR a URL-type-bound HMAC
        // token. The nonce is the CSRF fast-path for freshly rendered pages,
        // but it is frozen into the page HTML at render time and that HTML is
        // then page-cached — so on a long-lived cache entry the nonce expires
        // (12–24h) and every subsequent beacon 403s, silently losing LCP and
        // above-the-fold-font collection for the slow-to-cover viewport
        // bucket. The HMAC token is derived from a server secret and never
        // expires, so it stays valid for the entire cache lifetime while
        // remaining unforgeable off-site. No extra request is added.
        // Page URL: prefer the explicit field, fall back to Referer. Must be
        // same-origin; used for LCP per-URL keying, targeted purge, and
        // (2.6.0) for validating the page-bound beacon token — so it has to be
        // resolved BEFORE the auth check, not after.
        $page_url = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';
        if ( '' === $page_url || ! self::is_same_origin( $page_url ) ) {
            $ref = wp_get_referer();
            $page_url = ( is_string( $ref ) && self::is_same_origin( $ref ) ) ? $ref : '';
        }

        if ( ! self::beacon_request_is_valid( $url_type, self::normalize_url( $page_url ) ) ) {
            wp_send_json_error( 'nonce', 403 );
        }

        // ── LCP ──────────────────────────────────────────────────────────
        if ( (int) EasyOpt_Config::get( 'lcp_preload', 0 ) && isset( $_POST['image_url'] ) ) {
            self::store_lcp( $page_url, $url_type, $_POST );
        }

        // ── JS-generated classes → Used CSS active-selector universe ───────
        if ( isset( $_POST['classes'] ) && class_exists( 'EasyOpt_Unused_CSS' )
            && method_exists( 'EasyOpt_Unused_CSS', 'store_beacon_classes' ) ) {
            EasyOpt_Unused_CSS::store_beacon_classes( $url_type, wp_unslash( $_POST['classes'] ) );
        }

        // ── Above-the-fold fonts → preload list ────────────────────────────
        if ( isset( $_POST['fonts'] ) && class_exists( 'EasyOpt_Fonts' )
            && method_exists( 'EasyOpt_Fonts', 'store_beacon_fonts' ) ) {
            $vp = isset( $_POST['viewport'] ) ? sanitize_key( wp_unslash( $_POST['viewport'] ) ) : '';
            EasyOpt_Fonts::store_beacon_fonts( $url_type, wp_unslash( $_POST['fonts'] ), $page_url, $vp );
        }

        wp_send_json_success();
    }

    /**
     * Legacy LCP-only endpoint. Pages cached by a pre-2.4.3 build still embed
     * the old beacon, which posts here with the old nonce. We accept it and
     * route through the same per-URL store using the Referer as the page URL.
     */
    public static function ajax_report_lcp() {

        if ( ! self::maybe_install_table() ) {
            wp_send_json_error( 'db', 500 );
        }
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['nonce'] ), 'easyopt_lcp_beacon' ) ) {
            wp_send_json_error( 'nonce', 403 );
        }

        $url_type = isset( $_POST['url_type'] ) ? sanitize_key( wp_unslash( $_POST['url_type'] ) ) : '';
        if ( '' === $url_type ) {
            wp_send_json_error( 'invalid', 400 );
        }

        $ref      = wp_get_referer();
        $page_url = ( is_string( $ref ) && self::is_same_origin( $ref ) ) ? $ref : '';

        self::store_lcp( $page_url, substr( $url_type, 0, 191 ), $_POST );
        wp_send_json_success();
    }

    /**
     * True when the URL points at a video / streaming resource. The client
     * beacon can pick a large above-the-fold <video> (often a decorative
     * background video) and post its <source> as the LCP "image". Preloading
     * that with as="image" is invalid, wastes bandwidth, and can delay the real
     * LCP. A video's actual paint is its poster, which the beacon reports
     * separately as a 'video-poster' element kind — so raw video URLs are never
     * preloaded as images.
     *
     * @param string $url
     * @return bool
     */
    private static function is_video_url( $url ) {
        return (bool) preg_match( '/\.(mp4|webm|mov|m4v|ogv|ogg|avi|mkv|m3u8|mpd|ts|flv|wmv|3gp)(\?.*)?$/i', (string) $url );
    }

    /**
     * Shared per-URL LCP store. Normalizes the page URL, dedups against the
     * existing row, writes (insert/update) keyed on (url_hash, viewport), and
     * — only on a genuinely new/changed element — fires easyopt_lcp_saved so
     * the page cache refreshes just that one URL.
     *
     * @param string $page_url Absolute same-origin URL ('' if unknown).
     * @param string $url_type URL type (grouping / "clear current type").
     * @param array  $src      Raw $_POST (already nonce-checked by caller).
     */
    private static function store_lcp( $page_url, $url_type, $src ) {

        if ( ! self::maybe_install_table() ) {
            return;
        }

        // Without a resolvable same-origin URL we cannot key per-URL. Bail
        // rather than guess — the next visit with a valid Referer will warm it.
        if ( '' === $page_url ) {
            return;
        }
        $norm_url = self::normalize_url( $page_url );
        if ( '' === $norm_url ) {
            return;
        }
        $url_hash = md5( $norm_url );

        $viewport     = isset( $src['viewport'] )     ? sanitize_key( wp_unslash( $src['viewport'] ) ) : '';
        $element_tag  = isset( $src['element_tag'] )  ? sanitize_key( wp_unslash( $src['element_tag'] ) ) : 'img';
        $element_kind = isset( $src['element_kind'] ) ? sanitize_key( wp_unslash( $src['element_kind'] ) ) : 'img';
        $image_url    = isset( $src['image_url'] )    ? esc_url_raw( wp_unslash( $src['image_url'] ) ) : '';
        $srcset       = isset( $src['srcset'] )       ? self::sanitize_srcset( wp_unslash( $src['srcset'] ) ) : '';
        $sizes        = isset( $src['sizes'] )        ? self::sanitize_sizes( wp_unslash( $src['sizes'] ) ) : '';
        $selector     = isset( $src['selector'] )     ? self::sanitize_selector( wp_unslash( $src['selector'] ) ) : '';
        $lcp_size     = isset( $src['lcp_size'] )     ? absint( $src['lcp_size'] ) : 0;

        $allowed_kinds = array( 'img', 'img-srcset', 'background', 'background-set', 'picture', 'video-poster', 'none', 'video' );
        if ( ! in_array( $element_kind, $allowed_kinds, true ) ) {
            $element_kind = 'img';
        }

        if ( ! in_array( $viewport, array( 'mobile', 'desktop' ), true ) ) {
            return;
        }
        if ( $lcp_size < self::MIN_LCP_SIZE ) {
            return;
        }

        if ( 'none' === $element_kind ) {
            // (2.7.2) Measured, nothing to preload (text LCP). Stored as an
            // empty marker so the page counts as warm and the beacon stops;
            // process_buffer() emits nothing for it.
            $image_url = '';
            $srcset    = '';
            $sizes     = '';
        } elseif ( 'video' === $element_kind ) {
            // (2.7.2) Poster-less <video>. Its source is kept only so the lazy
            // pass can recognise and never defer it — it is never preloaded,
            // so a third-party video host is fine here.
            if ( '' === $image_url ) {
                return;
            }
            $srcset = '';
            $sizes  = '';
        } else {
            if ( '' === $image_url ) {
                return;
            }
            // Image (or background) must be same-origin (or configured CDN host).
            if ( ! self::is_same_origin( $image_url ) ) {
                return;
            }
            // Never store a video URL as an LCP image preload (see is_video_url).
            if ( self::is_video_url( $image_url ) ) {
                return;
            }
        }

        global $wpdb;
        $now      = time();
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, element_tag, element_kind, image_url, srcset, sizes, selector
               FROM " . self::$table . " WHERE url_hash = %s AND viewport = %s LIMIT 1",
            $url_hash,
            $viewport
        ), ARRAY_A );

        $new_tag = $element_tag ? substr( $element_tag, 0, 20 ) : 'img';
        $changed = true;
        if ( $existing ) {
            // Stable, cross-visitor image identity. The beacon reports
            // image_url from el.currentSrc, which the browser resolves PER
            // DEVICE (by DPR + viewport) for responsive images — so two
            // visitors on the SAME page legitimately report different
            // image_url values for the same element. Comparing image_url
            // directly treated that as a "changed" LCP element and fired
            // easyopt_lcp_saved → clear_url() on nearly every visit, causing a
            // cache-clear → re-preload loop (cached count collapsing, CPU
            // spikes, and raw pages served between clear and re-warm on
            // skip-live-buffer hosts).
            //
            // When a srcset is present it IS the device-independent identity:
            // currentSrc is merely one candidate picked from it, and the
            // rendered preload already emits imagesrcset (the browser chooses
            // from that and ignores the href), so the resolved URL never needs
            // to drive invalidation. If srcset is unchanged the candidate set
            // — and therefore the image — is unchanged. Only when there is no
            // srcset is image_url the literal <img src> (already stable across
            // visitors), so we compare on it in that case.
            $id_existing = ( '' !== (string) $existing['srcset'] )
                ? (string) $existing['srcset']
                : (string) $existing['image_url'];
            $id_new = ( '' !== $srcset ) ? $srcset : $image_url;

            $changed = ( (string) $existing['element_tag']  !== (string) $new_tag )
                    || ( (string) $existing['element_kind'] !== (string) $element_kind )
                    || ( $id_existing                       !== $id_new )
                    || ( (string) $existing['sizes']        !== (string) $sizes )
                    || ( (string) $existing['selector']     !== (string) $selector );
        }

        $data = array(
            'url_hash'     => $url_hash,
            'url'          => substr( $norm_url, 0, 2000 ),
            'url_type'     => $url_type,
            'viewport'     => $viewport,
            'element_tag'  => $new_tag,
            'element_kind' => $element_kind,
            'image_url'    => $image_url,
            'srcset'       => $srcset,
            'sizes'        => $sizes,
            'selector'     => $selector,
            'lcp_size'     => $lcp_size,
            'updated_at'   => $now,
        );

        // (2.4.5) Atomic write keyed on the (url_hash, viewport) UNIQUE index.
        // INSERT … ON DUPLICATE KEY UPDATE replaces the previous
        // read-then-insert/update, closing the race where two first-time
        // beacons for the same page+viewport both saw "no row" and the second
        // INSERT then failed on the unique key (one report silently lost).
        // created_at is written only on first insert; the unique-key columns
        // are never updated. VALUES() is used for MySQL 5.7 / MariaDB
        // portability (the 8.0.20+ alias form isn't universally supported).
        $wpdb->query( $wpdb->prepare(
            'INSERT INTO ' . self::$table . '
                (url_hash, url, url_type, viewport, element_tag, element_kind,
                 image_url, srcset, sizes, selector, lcp_size, updated_at, created_at)
             VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s)
             ON DUPLICATE KEY UPDATE
                url          = VALUES(url),
                url_type     = VALUES(url_type),
                element_tag  = VALUES(element_tag),
                element_kind = VALUES(element_kind),
                image_url    = VALUES(image_url),
                srcset       = VALUES(srcset),
                sizes        = VALUES(sizes),
                selector     = VALUES(selector),
                lcp_size     = VALUES(lcp_size),
                updated_at   = VALUES(updated_at)',
            $data['url_hash'], $data['url'], $data['url_type'], $data['viewport'],
            $data['element_tag'], $data['element_kind'], $data['image_url'],
            $data['srcset'], $data['sizes'], $data['selector'], (int) $data['lcp_size'],
            $data['updated_at'], $now
        ) );

        // rows_affected: 1 = brand-new row inserted, 2 = existing row updated,
        // 0 = update was a no-op. Evict only when we actually grew the table.
        if ( ! $existing && 1 === (int) $wpdb->rows_affected ) {
            self::maybe_evict_lru();
        }

        self::flush_cache_rows( $url_hash );

        if ( $changed ) {
            /**
             * Fires after a NEW or CHANGED LCP element is recorded for a URL.
             * The page cache refreshes ONLY that URL so it gains the preload.
             *
             * @param string $url_type URL type.
             * @param string $viewport 'desktop' or 'mobile'.
             * @param string $page_url Absolute URL of the triggering page.
             */
            do_action( 'easyopt_lcp_saved', $url_type, $viewport, $norm_url );
        }
    }

    /**
     * Normalize a page URL for stable per-URL keying:
     *  - resolve to absolute on the site origin,
     *  - force the site's canonical scheme (so http/https map to one key and
     *    so EasyOpt_Cache::clear_url() can parse host + path correctly),
     *  - drop the query string and fragment (page cache only serves
     *    query-less URLs, and this bounds cardinality against junk params),
     *  - collapse to scheme://host/path with a single trailing slash.
     *
     * The render path and the beacon-report path both pass their URL through
     * this function, so the same page always yields the same key.
     *
     * @param string $url
     * @return string Canonical URL, or '' if it can't be parsed.
     */
    private static function normalize_url( $url ) {

        $url = trim( (string) $url );
        if ( '' === $url ) {
            return '';
        }
        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) ) {
            return '';
        }

        $scheme = (string) wp_parse_url( home_url(), PHP_URL_SCHEME );
        if ( '' === $scheme ) {
            $scheme = 'https';
        }
        $host = strtolower( $parts['host'] );
        $port = ! empty( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
        $path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';

        // Normalize trailing slash: keep exactly one, except for file-like
        // paths (e.g. /sitemap.xml) which keep their extension.
        if ( '/' !== $path && false === strpos( basename( $path ), '.' ) ) {
            $path = rtrim( $path, '/' ) . '/';
        }

        return $scheme . '://' . $host . $port . $path;
    }

    /**
     * Evict least-recently-updated rows when the table exceeds MAX_ROWS.
     * Runs only on INSERT of a brand-new URL (cheap, amortized).
     */
    private static function maybe_evict_lru() {

        global $wpdb;
        $cap = (int) apply_filters( 'easyopt_lcp_max_rows', self::MAX_ROWS );
        if ( $cap < 100 ) {
            $cap = 100;
        }

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::$table );
        if ( $total <= $cap ) {
            return;
        }

        // Delete the oldest (by updated_at) beyond the cap. Add a small batch
        // headroom so we don't run this on every single insert at steady state.
        $remove = $total - $cap + 50;
        $ids    = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM " . self::$table . " ORDER BY updated_at ASC LIMIT %d",
            $remove
        ) );
        if ( ! empty( $ids ) ) {
            $ids = array_map( 'intval', $ids );
            $in  = implode( ',', $ids );
            $wpdb->query( "DELETE FROM " . self::$table . " WHERE id IN ({$in})" );
            self::flush_all_cache_rows();
        }
    }

    /* ───────────────────────────────────────────────
     *  Output buffer — inject preload + fetchpriority
     * ─────────────────────────────────────────────── */

    public static function process_buffer( $html ) {

        if ( ! (int) EasyOpt_Config::get( 'lcp_preload', 0 ) ) {
            return $html;
        }

        // WooCommerce dynamic pages — skip preload injection on Cart,
        // Checkout and My Account where content varies per session.
        if ( EasyOpt_Config::is_woo_dynamic_page() ) {
            return $html;
        }

        if ( is_user_logged_in() && ! apply_filters( 'easyopt_lcp_logged_in', false ) ) {
            return $html;
        }

        if ( ! self::is_valid_buffer( $html ) ) {
            return $html;
        }

        if ( self::is_url_excluded() ) {
            return $html;
        }

        // Per-URL lookup (2.4.3): the LCP element is keyed by the normalized
        // page URL, so each URL gets its own correct hero — no more "first
        // crawled post of a type wins" coarseness.
        $norm_url = self::get_normalized_url();
        if ( '' === $norm_url ) {
            return $html;
        }
        $url_hash = md5( $norm_url );

        $rows = self::get_cache_rows( $url_hash );
        if ( empty( $rows ) ) {
            return $html;
        }

        $preload_html  = '';
        $matching_urls = array();

        foreach ( $rows as $row ) {
            $kind = isset( $row['element_kind'] ) ? $row['element_kind'] : 'img';

            // (2.7.2) Measured, nothing to preload: text LCP or poster-less video.
            if ( 'none' === $kind || 'video' === $kind || '' === (string) $row['image_url'] ) {
                continue;
            }

            // Defence-in-depth for rows written by a pre-2.4.9 build: never
            // emit an as="image" preload for a video URL (see is_video_url).
            if ( self::is_video_url( $row['image_url'] ) ) {
                continue;
            }

            // Every kind that resolves to an image URL gets a preload.
            $link  = '<link rel="preload" as="image" fetchpriority="high"';
            $link .= ' href="' . esc_url( $row['image_url'] ) . '"';

            // Responsive preload — only meaningful for <img>/<picture> sources.
            if ( 'background' !== $kind && ! empty( $row['srcset'] ) ) {
                $link .= ' imagesrcset="' . esc_attr( $row['srcset'] ) . '"';
                if ( ! empty( $row['sizes'] ) ) {
                    $link .= ' imagesizes="' . esc_attr( $row['sizes'] ) . '"';
                }
            }
            // (2.7.2) Always scoped to the viewport it was measured on. Serving a
            // single measured image to every viewport made desktop fetch a
            // mobile-only hero (display:none there); the unmeasured viewport
            // now gets nothing until its own beacon reports.
            $media = ( 'mobile' === $row['viewport'] )
                ? '(max-width: ' . ( self::MOBILE_BREAK - 1 ) . 'px)'
                : '(min-width: ' . self::MOBILE_BREAK . 'px)';
            $link .= ' media="' . esc_attr( $media ) . '"';
            $link .= ' data-easyopt-lcp="' . esc_attr( $row['viewport'] ) . '">';
            $preload_html .= $link;

            // Only element kinds backed by an <img> get the fetchpriority/eager
            // boost. Background-image LCP has no <img> to mark (the preload is
            // the whole win); video-poster is handled by the lazyload module.
            if ( in_array( $kind, array( 'img', 'img-srcset', 'picture' ), true ) ) {
                $matching_urls[] = $row['image_url'];
            }
        }

        // IDEMPOTENCE: skip if this document already carries an LCP preload.
        //
        // The preload runner fetches pages over HTTP with a synthetic
        // logged-in cookie so the live output buffer runs (see
        // EasyOpt_Cache_Preload::build_warm_headers), then
        // optimize_prefetched_html() runs the whole pipeline over that
        // already-optimized body a second time. Without this check a
        // prefetch-generated page carried two copies of every LCP preload.
        // Harmless to the browser, which dedupes identical preloads, but it
        // is wasted <head> weight and the same defect that put two delay-JS
        // loaders on the page.
        if ( '' !== $preload_html && false === stripos( $html, 'data-easyopt-lcp=' ) ) {
            // Inject before </head>. The Used-CSS injector runs after this
            // processor and relocates this preload to sit right after the
            // critical CSS (above the font preloads); if Used CSS is disabled on
            // this page, it stays here.
            $head_close = stripos( $html, '</head>' );
            if ( false !== $head_close ) {
                $html = substr( $html, 0, $head_close ) . $preload_html . substr( $html, $head_close );
            }
        }

        if ( ! empty( $matching_urls ) ) {
            $html = self::boost_matching_images( $html, $matching_urls );
        }

        return $html;
    }

    /**
     * Find <img> tags matching any stored LCP URL and add fetchpriority="high"
     * + loading="eager". Strips pre-existing loading="lazy" / fetchpriority.
     *
     * Matching is by FULL URL PATH (2.4.3) — not bare filename — so two
     * different images that happen to share a basename in different folders
     * are never confused (the old false-positive boost).
     */
    private static function boost_matching_images( $html, $urls ) {

        // Map normalized full path => 1 for O(1) lookup.
        $paths = array();
        foreach ( $urls as $u ) {
            $p = self::url_path_key( $u );
            if ( '' !== $p ) {
                $paths[ $p ] = 1;
            }
        }
        if ( empty( $paths ) ) {
            return $html;
        }

        // ── (2.5.2) Picture-aware pass FIRST ──
        // When the LCP <img> lives inside a <picture> whose <source> tags are
        // theme-lazyloaded (data-srcset, no real srcset — lazysizes pattern),
        // boosting only the <img> leaves the browser waiting on the lazy
        // sources. Promote data-srcset → srcset on those <source> tags, but
        // ONLY inside the picture that contains the confirmed LCP image.
        $html = preg_replace_callback(
            '#<picture\b[^>]*>.*?</picture>#is',
            function ( $pm ) use ( $paths ) {
                if ( ! preg_match( '#<img\b[^>]*>#i', $pm[0], $im )
                     || ! self::img_atts_match_paths( $im[0], $paths ) ) {
                    return $pm[0];
                }
                return preg_replace_callback(
                    '#<source\b([^>]*?)(/?)>#i',
                    function ( $sm ) {
                        $atts = $sm[1];
                        if ( preg_match( '/\bdata-srcset\s*=\s*["\']([^"\']+)["\']/i', $atts, $ds )
                             && ! preg_match( '/(?<![-\w])srcset\s*=/i', $atts ) ) {
                            $atts = preg_replace( '/\s*\bdata-srcset\s*=\s*["\'][^"\']*["\']/i', '', $atts );
                            $atts = rtrim( $atts ) . ' srcset="' . $ds[1] . '"';
                        }
                        return '<source' . $atts . $sm[2] . '>';
                    },
                    $pm[0]
                );
            },
            $html
        );

        return preg_replace_callback(
            '#<img\b([^>]*)>#i',
            function ( $m ) use ( $paths ) {
                $atts = $m[1];

                // (2.5.2) Match by src OR data-src path — third-party
                // lazyloaders (lazysizes & friends) keep the real URL in
                // data-src, sometimes with only a placeholder in src.
                if ( ! self::img_atts_match_paths( $m[0], $paths ) ) {
                    return $m[0];
                }

                // Already marked high? Leave alone.
                if ( preg_match( '/\bfetchpriority\s*=\s*["\']high["\']/i', $atts ) ) {
                    return $m[0];
                }

                // ── (2.5.2) De-lazify third-party lazy markup — LCP element
                // only. fetchpriority/eager alone can't beat a JS lazyloader:
                // lazysizes still gates rendering via the `lazyload` class and
                // swaps data-src in on viewport entry. Promote the real URLs
                // into src/srcset and hand the class off to its "done" state
                // so opacity-transition CSS (.lazyloaded{opacity:1}) still
                // applies. Scoped to the single confirmed LCP <img>, so other
                // images keep their theme lazyload untouched.
                $has_real_src = preg_match( '/(?<![-\w])src\s*=\s*["\']([^"\']+)["\']/i', $atts, $src_m )
                    && '' !== trim( $src_m[1] )
                    && '#' !== trim( $src_m[1] )
                    && false === stripos( $src_m[1], 'data:image' )
                    && false === stripos( $src_m[1], 'base64' );

                if ( preg_match( '/\bdata-src\s*=\s*["\']([^"\']+)["\']/i', $atts, $dsrc_m ) ) {
                    if ( ! $has_real_src ) {
                        $atts = preg_replace( '/\s*(?<![-\w])src\s*=\s*["\'][^"\']*["\']/i', '', $atts );
                        $atts = rtrim( $atts ) . ' src="' . $dsrc_m[1] . '"';
                    }
                    $atts = preg_replace( '/\s*\bdata-src\s*=\s*["\'][^"\']*["\']/i', '', $atts );
                }
                if ( preg_match( '/\bdata-srcset\s*=\s*["\']([^"\']+)["\']/i', $atts, $dss_m )
                     && ! preg_match( '/(?<![-\w])srcset\s*=/i', $atts ) ) {
                    $atts = preg_replace( '/\s*\bdata-srcset\s*=\s*["\'][^"\']*["\']/i', '', $atts );
                    $atts = rtrim( $atts ) . ' srcset="' . $dss_m[1] . '"';
                }
                // lazyload → lazyloaded: satisfies both `.lazyload{opacity:0}`
                // (class removed) and `.lazyloaded{opacity:1}` themes. EO's own
                // lazyload module then skips this tag via its
                // fetchpriority="high" eligibility guard.
                $atts = ! preg_match( '/\bclass\s*=\s*["\'][^"\']*\blazyload(ing)?\b/i', $atts ) ? $atts : preg_replace_callback(
                    '/\bclass\s*=\s*(["\'])(.*?)\1/is',
                    function ( $cm ) {
                        $classes = preg_split( '/\s+/', trim( $cm[2] ) );
                        $classes = array_diff( $classes, array( 'lazyload', 'lazyloading' ) );
                        if ( ! in_array( 'lazyloaded', $classes, true ) ) {
                            $classes[] = 'lazyloaded';
                        }
                        return 'class=' . $cm[1] . implode( ' ', $classes ) . $cm[1];
                    },
                    $atts,
                    1
                );

                $atts = preg_replace( '/\s+loading\s*=\s*["\'][^"\']*["\']/i', '', $atts );
                $atts = preg_replace( '/\s+fetchpriority\s*=\s*["\'][^"\']*["\']/i', '', $atts );
                $atts = ' fetchpriority="high" loading="eager"' . $atts;

                return '<img' . $atts . '>';
            },
            $html
        );
    }

    /**
     * (2.5.2) Does this <img> tag reference any stored LCP path via src OR
     * data-src? Shared by the picture pass and the img pass so both match
     * identically.
     *
     * @param string $img_tag Full <img ...> tag text.
     * @param array  $paths   Normalized path => 1 lookup map.
     * @return bool
     */
    private static function img_atts_match_paths( $img_tag, $paths ) {
        foreach ( array( '/(?<![-\w])src\s*=\s*["\']([^"\']+)["\']/i', '/\bdata-src\s*=\s*["\']([^"\']+)["\']/i' ) as $rx ) {
            if ( preg_match( $rx, $img_tag, $m ) ) {
                $key = self::url_path_key( $m[1] );
                if ( '' !== $key && isset( $paths[ $key ] ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Reduce an image URL to a stable path key for matching: the URL path,
     * lowercased, leading slash trimmed. Query string and host are ignored so
     * a CDN-rewritten src still matches its origin counterpart by path.
     *
     * @param string $url
     * @return string
     */
    public static function url_path_key( $url ) {
        $url = (string) $url;
        // (2.7.2) A Cloud URL's path is /{account}/{sig}/{transform}/plain/…,
        // so it never matched by path. Compare by the origin it wraps — a
        // stored edge URL then matches the <img> at whatever width it was
        // painted, and an origin URL is returned unchanged.
        if ( false !== stripos( $url, '/plain/' ) && class_exists( 'EasyOpt_Rest_Cloud' ) ) {
            $url = EasyOpt_Rest_Cloud::unwrap_cdn_url( $url );
        }
        $path = wp_parse_url( $url, PHP_URL_PATH );
        if ( ! is_string( $path ) || '' === $path ) {
            return '';
        }
        return ltrim( strtolower( $path ), '/' );
    }

    /* ───────────────────────────────────────────────
     *  Cache accessors
     * ─────────────────────────────────────────────── */

    /**
     * Fetch cached LCP rows for a URL type, excluding TTL-expired ones.
     *
     * @param  string $url_type
     * @return array  Array of assoc arrays (or empty).
     */
    /**
     * Recorded LCP image URLs for the CURRENT page.
     *
     * Exposed so other processors can keep the measured hero out of any
     * deferral path — EasyOpt_LazyLoad uses it to leave a <video> alone
     * when its poster is the LCP element. Returns an empty array when the
     * beacon has not measured this URL yet, so callers degrade to their
     * normal behaviour rather than guessing.
     *
     * Memoised per request; get_cache_rows() is itself transient-cached.
     *
     * @since 2.5.5
     * @return string[] Image URLs (may be empty).
     */
    public static function get_lcp_urls() {

        static $memo = null;
        if ( null !== $memo ) {
            return $memo;
        }
        $memo = array();

        $norm_url = self::get_normalized_url();
        if ( '' === $norm_url ) {
            return $memo;
        }

        $rows = self::get_cache_rows( md5( $norm_url ) );
        if ( empty( $rows ) ) {
            return $memo;
        }

        foreach ( $rows as $row ) {
            if ( ! empty( $row['image_url'] ) ) {
                $memo[] = (string) $row['image_url'];
            }
        }

        $memo = array_values( array_unique( $memo ) );

        return $memo;
    }

    private static function get_cache_rows( $url_hash ) {

        // 24-hour cached lookup, keyed by the normalized-URL hash. The LCP
        // table is otherwise hit on every page render. Transient (non-
        // autoloaded) + per-request memo. Invalidated on any write/clear for
        // this URL and bounded to 24h regardless.
        static $req_cache = array();
        if ( isset( $req_cache[ $url_hash ] ) ) {
            return $req_cache[ $url_hash ];
        }

        $cache_key = 'easyopt_lcp_rows_' . $url_hash;
        $cached    = get_transient( $cache_key );
        if ( false !== $cached && is_array( $cached ) ) {
            $req_cache[ $url_hash ] = $cached;
            return $cached;
        }

        global $wpdb;
        $ttl_cutoff = time() - ( self::TTL_DAYS * DAY_IN_SECONDS );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT viewport, element_tag, element_kind, image_url, srcset, sizes, selector
                 FROM " . self::$table . "
                 WHERE url_hash = %s AND updated_at >= %d",
                $url_hash,
                $ttl_cutoff
            ),
            ARRAY_A
        );

        $rows = is_array( $rows ) ? $rows : array();
        set_transient( $cache_key, $rows, DAY_IN_SECONDS );
        $req_cache[ $url_hash ] = $rows;
        return $rows;
    }

    /** Drop the cached rows for a single URL hash. Called on row writes / clears. */
    private static function flush_cache_rows( $url_hash ) {
        delete_transient( 'easyopt_lcp_rows_' . $url_hash );
    }

    /** Drop ALL LCP row caches at once. Used by clear_all(). */
    private static function flush_all_cache_rows() {
        global $wpdb;
        // Transients are stored as `_transient_<key>` and `_transient_timeout_<key>`.
        // We only created one prefix family so we can wipe them in one query.
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '\\_transient\\_easyopt\\_lcp\\_rows\\_%'
             OR option_name LIKE '\\_transient\\_timeout\\_easyopt\\_lcp\\_rows\\_%'"
        );
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( 'transient' );
        }
    }

    /**
     * Are both viewport buckets warm and non-expired for this URL hash?
     */
    private static function is_fully_warm( $url_hash ) {

        global $wpdb;
        $ttl_cutoff = time() - ( self::TTL_DAYS * DAY_IN_SECONDS );

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::$table . "
                 WHERE url_hash = %s AND updated_at >= %d",
                $url_hash,
                $ttl_cutoff
            )
        );

        return $count >= 2;
    }

    /**
     * Normalized URL for the CURRENT request (memoized). Built from the
     * request URI on the home origin and run through normalize_url() so it
     * matches the key the beacon produced for the same page.
     */
    public static function get_normalized_url() {
        if ( '' !== self::$norm_url ) {
            return self::$norm_url;
        }
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        self::$norm_url = self::normalize_url( home_url( $uri ) );
        return self::$norm_url;
    }

    /* ───────────────────────────────────────────────
     *  Admin bar
     * ─────────────────────────────────────────────── */

    public static function admin_bar_menu( $wp_admin_bar ) {

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Shared 'easyopt-root' parent is owned by the main plugin (priority 80).
        $parent_id = 'easyopt-root';
        if ( ! $wp_admin_bar->get_node( $parent_id ) ) {
            $wp_admin_bar->add_node( array(
                'id'    => $parent_id,
                'title' => __( 'Easy Optimizer', 'easy-optimizer' ),
                'href'  => admin_url( 'admin.php?page=easy-optimizer' ),
            ) );
        }

        // Front-end only → "Clear LCP Image (This Page)" for the current page.
        // The backend "Clear All LCP Images" node has been removed from the
        // admin bar; full clears are now done from the Preload LCP settings tab
        // ("Clear all LCP data" button), which is a less error-prone place for a
        // destructive, site-wide action.
        if ( ! is_admin() ) {
            $cur_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
            $wp_admin_bar->add_node( array(
                'parent' => $parent_id,
                'id'     => 'easyopt-clear-lcp-current',
                'title'  => __( 'Clear LCP Image (This Page)', 'easy-optimizer' ),
                'href'   => add_query_arg(
                    array(
                        'action'           => 'easyopt_clear_lcp',
                        'lcp_url'          => rawurlencode( home_url( $cur_uri ) ),
                        '_wp_http_referer' => rawurlencode( $cur_uri ),
                        '_wpnonce'         => wp_create_nonce( 'easyopt_clear_lcp' ),
                    ),
                    admin_url( 'admin-post.php' )
                ),
            ) );
        }
    }

    public static function handle_clear_lcp() {

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'easyopt_clear_lcp' ) ) {
            wp_nonce_ays( '' );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to perform this action.', 'easy-optimizer' ) );
        }

        $lcp_url = isset( $_GET['lcp_url'] ) ? esc_url_raw( rawurldecode( (string) wp_unslash( $_GET['lcp_url'] ) ) ) : '';
        $type    = isset( $_GET['type'] )    ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';

        if ( '' !== $lcp_url ) {
            self::clear_by_url( $lcp_url );
        } elseif ( '' !== $type ) {
            self::clear_by_type( $type );
        } else {
            self::clear_all();
        }

        $redirect = isset( $_GET['_wp_http_referer'] ) ? rawurldecode( (string) wp_unslash( $_GET['_wp_http_referer'] ) ) : admin_url();
        $redirect = add_query_arg( 'easyopt_lcp_cleared', '1', $redirect );

        wp_safe_redirect( $redirect );
        exit;
    }

    public static function admin_notices() {

        if ( isset( $_GET['easyopt_lcp_cleared'] ) && '1' === $_GET['easyopt_lcp_cleared'] ) {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html__( 'LCP cache cleared.', 'easy-optimizer' )
            );
        }
    }

    public static function clear_all() {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE " . self::$table );
        self::flush_all_cache_rows();
    }

    public static function clear_by_type( $type ) {
        global $wpdb;
        $wpdb->delete( self::$table, array( 'url_type' => $type ), array( '%s' ) );
        self::flush_all_cache_rows();
    }

    /** Clear the LCP rows for a single (normalized) URL. */
    public static function clear_by_url( $url ) {
        $norm = self::normalize_url( $url );
        if ( '' === $norm ) {
            return;
        }
        $hash = md5( $norm );
        global $wpdb;
        $wpdb->delete( self::$table, array( 'url_hash' => $hash ), array( '%s' ) );
        self::flush_cache_rows( $hash );
    }

    /* ───────────────────────────────────────────────
     *  Helpers
     * ─────────────────────────────────────────────── */

    /**
     * Determine URL type for cache key. Mirrors the Unused CSS module's logic
     * so both modules share the same cache key namespace.
     */
    public static function get_url_type() {

        if ( '' !== self::$url_type ) {
            return self::$url_type;
        }

        global $wp_query;
        if ( ! is_object( $wp_query ) ) {
            return '';
        }

        $type = '';

        if ( $wp_query->is_page ) {
            $type = is_front_page() ? 'front' : ( ! empty( $wp_query->post ) ? 'page-' . $wp_query->post->ID : 'page' );
        } elseif ( $wp_query->is_home ) {
            $type = 'home';
        } elseif ( $wp_query->is_single ) {
            $pt   = get_post_type();
            $type = $pt ? $pt : 'single';
            if ( 'product' === $pt && function_exists( 'wc_get_product' ) ) {
                $product = wc_get_product( get_the_ID() );
                if ( $product ) {
                    $ptype = $product->get_type();
                    if ( in_array( $ptype, array( 'variable', 'grouped', 'external' ), true ) ) {
                        $type .= '-' . $ptype;
                    }
                }
            }
        } elseif ( $wp_query->is_category ) {
            $type = 'category';
        } elseif ( $wp_query->is_tag ) {
            $type = 'tag';
        } elseif ( $wp_query->is_tax ) {
            $type = 'tax';
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

        self::$url_type = $type;
        return $type;
    }

    private static function is_url_excluded() {

        $excludes = EasyOpt_Config::get( 'lcp_exclude_urls', '' );
        if ( empty( $excludes ) ) {
            return false;
        }

        $current = home_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );
        $lines   = array_filter( array_map( 'trim', explode( "\n", $excludes ) ) );

        foreach ( $lines as $pattern ) {
            // (2.6.5) Shared matcher — supports `*` wildcards, same as every
            // other Exclude URLs field. See \EasyOpt\Cache\url_matches_pattern().
            if ( self::url_excludes( $current, $pattern ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wildcard-or-substring URL match, via the plugin-wide canonical matcher.
     *
     * Falls back to a plain case-insensitive substring test only if
     * cache-common.php is somehow not loaded — which keeps the pre-2.6.5
     * behaviour rather than throwing.
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

    private static function is_valid_buffer( $html ) {

        if ( stripos( $html, '<html' ) === false || stripos( $html, '</head>' ) === false ) {
            return false;
        }
        if ( stripos( $html, '<xsl:stylesheet' ) !== false ) {
            return false;
        }
        return true;
    }

    private static function is_same_origin( $url ) {

        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( empty( $host ) ) {
            return false;
        }

        $allowed = array(
            wp_parse_url( home_url(), PHP_URL_HOST ),
            wp_parse_url( site_url(), PHP_URL_HOST ),
        );

        // Also allow configured CDN host (if Image CDN rewriting is on).
        $cdn_host = EasyOpt_Config::get( 'cdn_host', '' );
        if ( ! empty( $cdn_host ) ) {
            $allowed[] = $cdn_host;
        }
        // FluxCDN: the painted LCP element on a CDN-enabled page IS the CDN
        // URL — without this, store_lcp() rejects every LCP candidate and
        // the preload feature silently dies the moment FluxCDN connects.
        $flux = (string) EasyOpt_Config::get( 'fluxcdn_endpoint', '' );
        if ( '' !== $flux ) {
            $fh = wp_parse_url( $flux, PHP_URL_HOST );
            if ( ! empty( $fh ) ) {
                $allowed[] = $fh;
            }
        }
        // (2.7.2) Cloud Optimization, same reason. Missing until now, so with
        // the CDN connected every LCP report was rejected and the table
        // stayed empty site-wide.
        if ( class_exists( 'EasyOpt_CDN_Cloud' ) && EasyOpt_CDN_Cloud::is_connected() ) {
            $ch = wp_parse_url( (string) EasyOpt_CDN_Cloud::endpoint(), PHP_URL_HOST );
            if ( ! empty( $ch ) ) {
                $allowed[] = $ch;
            }
        }

        return in_array( strtolower( $host ), array_map( 'strtolower', array_filter( $allowed ) ), true );
    }

    private static function sanitize_srcset( $srcset ) {
        // srcset is a comma-separated list of "url widthDescriptor". Preserve commas,
        // spaces, URL chars, and digit+w/x descriptors. Strip tags & quotes.
        $srcset = wp_strip_all_tags( $srcset );
        $srcset = str_replace( array( '"', "'", '<', '>' ), '', $srcset );
        return substr( trim( $srcset ), 0, 4000 );
    }

    private static function sanitize_sizes( $sizes ) {
        // sizes is a CSS-like media query list. Strip tags/quotes, limit length.
        $sizes = wp_strip_all_tags( $sizes );
        $sizes = str_replace( array( '"', "'", '<', '>' ), '', $sizes );
        return substr( trim( $sizes ), 0, 500 );
    }

    private static function sanitize_selector( $selector ) {
        // Allow only common CSS selector chars.
        $selector = preg_replace( '/[^a-zA-Z0-9#.\-_ >:()\[\]]/', '', (string) $selector );
        return substr( (string) $selector, 0, 500 );
    }
}
