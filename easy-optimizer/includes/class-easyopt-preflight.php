<?php
/**
 * Environment pre-flight scan (2.6.0).
 *
 * Answers one question before the wizard applies anything: which jobs does
 * something else on this system already own?
 *
 * Until now the plugin collected all of these signals — hosting layers,
 * foreign drop-ins, lazy-load systems, competing plugins — and none of them
 * reached the preset decision. Worse, conflict detection could not run on the
 * apply path at all: EasyOpt_Compat_Conflicts::init() returns early unless
 * is_admin(), and the wizard applies over REST where is_admin() is false, so
 * detect() was never even registered. A fresh install with WP Rocket active
 * got Balanced applied in full — page cache on, drop-in installed, .htaccess
 * written, preload started — and only then saw a red notice telling the user
 * they had two page caches.
 *
 * DESIGN CONTRACT — all five of these are load-bearing:
 *
 *   1. NEVER touch another plugin. No deactivating, no writing to their
 *      options, no filtering their internals. Read freely; that is all.
 *   2. FAIL OPEN. Any probe that throws is skipped; a total failure returns
 *      an empty exclusion set and the wizard falls back to its three static
 *      cards. No path where detection failure can block onboarding.
 *   3. NETWORK-FREE. No loopback, no PSI, nothing that can hang. Loopback is
 *      blocked by Wordfence / Sucuri / host WAFs on a meaningful share of
 *      sites and this runs on the wizard's critical path.
 *   4. RECOMMEND, NEVER ENFORCE. Exclusions pre-select; the user confirms.
 *   5. CAPABILITY OVER SLUG wherever a capability probe exists. If we cannot
 *      prove a feature is ON, we warn — we do not exclude.
 *
 * Scope: called from EasyOpt_Presets::apply() and nowhere else, so existing
 * installs never run it. There is no migration and no background job.
 *
 * @package EasyOptimizer
 * @since   2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Preflight {

    /** @var array|null Per-request memoised scan. */
    private static $scan = null;

    /**
     * Hosts whose own page cache owns the HTML, so ours should stay off.
     *
     * Cloudways and SiteGround are deliberately NOT here. Both were verified
     * in the field to work fine alongside Easy Optimizer's page cache:
     * Cloudways' Varnish sits in front of PHP rather than competing with it,
     * and SiteGround's own optimiser is caught by the foreign-drop-in probe
     * whenever its caching is actually switched on — so the blanket host rule
     * was redundant protection that cost real users a working feature.
     *
     * @var array<string,string> hosting key => human label
     */
    private static $cache_owning_hosts = array(
        'kinsta'    => 'Kinsta',
        'wpengine'  => 'WP Engine',
        'flywheel'  => 'Flywheel',
        'rocketnet' => 'Rocket.net',
        'pressable' => 'Pressable',
        'pantheon'  => 'Pantheon',
        'wpcloud'   => 'WordPress.com',
        'closte'    => 'Closte',
        'convesio'  => 'Convesio',
    );

    /**
     * Run every probe.
     *
     * @return array{exclude:array<string,string>,layers:string[],notes:string[],warnings:string[]}
     */
    public static function scan() {

        if ( null !== self::$scan ) {
            return self::$scan;
        }

        $exclude  = array();
        $notes    = array();
        $warnings = array();
        $layers   = array();

        // ── Writability. Gates the page cache regardless of host: without a
        // writable wp-content the drop-in cannot install, and without a
        // writable cache dir nothing can be stored. ────────────────────────
        try {
            if ( defined( 'WP_CONTENT_DIR' ) && ! is_writable( WP_CONTENT_DIR ) ) {
                $reason = __( 'This host does not allow writing to wp-content.', 'easy-optimizer' );
                $exclude['easyopt_cache']         = $reason;
                $exclude['easyopt_cache_preload'] = $reason;
            }
        } catch ( \Throwable $e ) { /* fail open */ }

        // ── JOB 1: another PLUGIN full-page cache ─────────────────────────
        // (2.6.5) No longer excluded. Easy Optimizer now TAKES OVER a plugin
        // drop-in (advanced-cache.php) — see EasyOpt_Advanced_Cache::install(),
        // which backs the competitor's file up and overwrites it, then restores
        // it on hand-back. So the wizard should switch our page cache ON, not
        // step aside for a plugin cache we are going to replace anyway.
        //
        // SERVER caches (below) are different: they live in NGINX / Varnish /
        // .htaccess, we cannot take them over, so those exclusions stay.

        // ── Server-level page cache (reuses the existing hosting probe) ────
        try {
            if ( class_exists( 'EasyOpt_Hosting' ) ) {
                $layers = (array) EasyOpt_Hosting::active_layers();
                foreach ( $layers as $layer ) {
                    if ( isset( self::$cache_owning_hosts[ $layer ] ) && ! isset( $exclude['easyopt_cache'] ) ) {
                        $reason = sprintf(
                            /* translators: %s: hosting provider name. */
                            __( '%s caches your pages at the server. Easy Optimizer will clear that cache when you publish.', 'easy-optimizer' ),
                            self::$cache_owning_hosts[ $layer ]
                        );
                        $exclude['easyopt_cache']                 = $reason;
                        $exclude['easyopt_cache_preload']         = $reason;
                        $exclude['easyopt_cache_gzip']            = $reason;
                        $exclude['easyopt_cache_browser_caching'] = $reason;
                        break;
                    }
                }
                if ( ! empty( $layers ) && ! isset( $exclude['easyopt_cache'] ) ) {
                    $notes[] = __( 'Your host runs its own cache layer. Easy Optimizer will clear it when your content changes.', 'easy-optimizer' );
                }
            }
        } catch ( \Throwable $e ) { /* fail open */ }

        // ── JOB 9: another object-cache drop-in (capability probe) ────────
        try {
            if ( class_exists( 'EasyOpt_Object_Cache_Manager' )
                && method_exists( 'EasyOpt_Object_Cache_Manager', 'foreign_dropin_present' )
                && EasyOpt_Object_Cache_Manager::foreign_dropin_present() ) {

                $name = method_exists( 'EasyOpt_Object_Cache_Manager', 'foreign_dropin_name' )
                    ? EasyOpt_Object_Cache_Manager::foreign_dropin_name()
                    : __( 'another plugin', 'easy-optimizer' );

                $exclude['easyopt_object_cache'] = sprintf(
                    /* translators: %s: name of the other plugin. */
                    __( '%s is already handling object caching.', 'easy-optimizer' ),
                    $name
                );
            }
        } catch ( \Throwable $e ) { /* fail open */ }

        // NOTE: core's Speculative Loading (WP 6.8+) is deliberately NOT
        // surfaced here. Easy Optimizer's prefetch does strictly more than
        // core's — selector exclusions, rate limiting, per-page caps,
        // viewport-based prefetch, a side-effecting-URL blocklist — so ours
        // simply takes over and core's is switched off at runtime. There is
        // no decision for the user to make and nothing they need to act on,
        // so telling them about it is noise on a screen where every line has
        // to earn its place.

        // ── JOB 5: another lazy-load system (runtime capability probes) ────
        try {
            if ( class_exists( 'EasyOpt_Compat_LazyLoad' ) && method_exists( 'EasyOpt_Compat_LazyLoad', 'probe' ) ) {
                $lazy = (array) EasyOpt_Compat_LazyLoad::probe();
                if ( ! empty( $lazy ) ) {
                    $reason = sprintf(
                        /* translators: %s: name of the other plugin. */
                        __( '%s already lazy-loads your images.', 'easy-optimizer' ),
                        (string) reset( $lazy )
                    );
                    $exclude['easyopt_lazy_images']  = $reason;
                    $exclude['easyopt_lazy_iframes'] = $reason;
                    $exclude['easyopt_lazy_videos']  = $reason;
                }
            }
        } catch ( \Throwable $e ) { /* fail open */ }

        // ── JOB 12: another font handler (runtime capability probes) ───────
        try {
            if ( class_exists( 'EasyOpt_Compat_Fonts' ) && method_exists( 'EasyOpt_Compat_Fonts', 'probe' ) ) {
                $fonts = (array) EasyOpt_Compat_Fonts::probe();
                if ( ! empty( $fonts ) ) {
                    $reason = sprintf(
                        /* translators: %s: name of the other plugin. */
                        __( '%s is already handling your web fonts.', 'easy-optimizer' ),
                        (string) reset( $fonts )
                    );
                    // (2.6.0) PRELOADING only. font-display: swap and Smart
                    // Lazyload Fonts don't collide with another plugin —
                    // competing preload hints do. Excluding all three
                    // switched off features that would have worked fine.
                    $exclude['easyopt_preload_fonts'] = $reason;
                }
            }
        } catch ( \Throwable $e ) { /* fail open */ }

        // ── JOB 2/3/4: CSS & JS optimisers, PROVEN on ─────────────────────
        // The wizard previously excluded nothing here, because slug detection
        // cannot prove a feature is switched on and excluding on "installed"
        // alone would disable things users wanted. Reading the plugin's own
        // option settles it — so a fresh install alongside an ACTIVE
        // Autoptimize no longer gets Minify, Used CSS and Delay JS switched on
        // to fight it.
        try {
            if ( class_exists( 'EasyOpt_Compat_Conflicts' )
                && method_exists( 'EasyOpt_Compat_Conflicts', 'proven_css_js_overlaps' ) ) {

                foreach ( EasyOpt_Compat_Conflicts::proven_css_js_overlaps() as $label => $keys ) {
                    foreach ( (array) $keys as $key ) {
                        if ( isset( $exclude[ $key ] ) ) {
                            continue;
                        }
                        $exclude[ $key ] = sprintf(
                            /* translators: %s: name of the other plugin. */
                            __( '%s is already doing this.', 'easy-optimizer' ),
                            $label
                        );
                    }
                }
            }
        } catch ( \Throwable $e ) { /* fail open */ }

        // ── Site weight. Replaces asking the user their hosting tier, which
        // most users cannot answer correctly. ─────────────────────────────
        try {
            $mem = self::bytes( ini_get( 'memory_limit' ) );
            if ( $mem > 0 && $mem < 201326592 ) { // < 192M
                $notes[] = __( 'Limited PHP memory detected — cache preload will run gently so your site stays responsive.', 'easy-optimizer' );
            }
        } catch ( \Throwable $e ) { /* fail open */ }

        // NOTE: security plugins (Wordfence, Sucuri, Solid Security) are
        // deliberately NOT surfaced here. They CAN block the loopback requests
        // preload uses — but only on some configurations, and the user cannot
        // act on it during setup. Warning about a maybe, on the one screen
        // where every line has to earn its place, reads as "this plugin has a
        // problem with your firewall" and undermines a setup that will usually
        // work fine. If preload genuinely fails to start, that surfaces in the
        // dashboard and the debug log, where it is actionable.

        self::$scan = array(
            'exclude'  => $exclude,
            'layers'   => $layers,
            'notes'    => $notes,
            'warnings' => $warnings,
        );

        /**
         * Filter the pre-flight result before it shapes a preset.
         *
         * @since 2.6.0
         * @param array $scan {exclude, layers, notes, warnings}
         */
        $filtered = apply_filters( 'easyopt_preflight_scan', self::$scan );
        if ( is_array( $filtered ) && isset( $filtered['exclude'] ) && is_array( $filtered['exclude'] ) ) {
            self::$scan = $filtered;
        }

        return self::$scan;
    }

    /**
     * Parse a PHP shorthand byte value ("256M") into bytes.
     *
     * @param string $val
     * @return int Bytes; 0 when unlimited or unparseable.
     */
    private static function bytes( $val ) {
        $val = trim( (string) $val );
        if ( '' === $val || '-1' === $val ) {
            return 0;
        }
        $unit = strtolower( substr( $val, -1 ) );
        $num  = (int) $val;
        switch ( $unit ) {
            case 'g':
                return $num * 1024 * 1024 * 1024;
            case 'm':
                return $num * 1024 * 1024;
            case 'k':
                return $num * 1024;
        }
        return $num;
    }

    /** Reset (tests, or the environment changed mid-request). */
    public static function reset() {
        self::$scan = null;
    }
}
