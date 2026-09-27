<?php
/**
 * Plugin Name: Easy Optimizer – PageSpeed, Cache & Core Web Vitals
 * Plugin URI:  https://fluxpress.io
 * Description: Page cache, lazy load, used CSS, delay JS, font optimization, image CDN, LCP preload and more — everything you need to ace Core Web Vitals, in one plugin.
 * Version:     2.7.1
 * Author:      FluxPress
 * Author URI:  https://fluxpress.io
 * Text Domain: easy-optimizer
 * Domain Path: /languages
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.4
 * Requires PHP: 7.4
 */

// Abort if called directly.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Plugin constants
if ( ! defined( 'EASYOPT_PLUGIN_FILE' ) ) {
    define( 'EASYOPT_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'EASYOPT_VERSION' ) ) {
    define( 'EASYOPT_VERSION', '2.7.1' );
}
if ( ! defined( 'EASYOPT_CSS_LOGIC_VERSION' ) ) {
    // (2.5.0 / A2) Last plugin version in which the Used-CSS strip/keep/
    // preload GENERATION logic changed. The update path wipes the Used CSS
    // only when this marker differs from the stored one, so routine updates
    // that don't touch CSS logic no longer force a full-site regeneration
    // burst. Bump this constant ONLY in releases that change how .used.css
    // is generated or interpreted.
    // (2.6.4) CSS parser swapped sabberworm → EasyOpt_CSS_Tokenizer. Output can
    // differ (native nesting now preserved instead of dropped), so bump to force
    // a one-time Used-CSS regeneration on update.
    define( 'EASYOPT_CSS_LOGIC_VERSION', '2.6.4' );
}
if ( ! defined( 'EASYOPT_DIR' ) ) {
    define( 'EASYOPT_DIR', plugin_dir_path( EASYOPT_PLUGIN_FILE ) );
}
if ( ! defined( 'EASYOPT_URL' ) ) {
    define( 'EASYOPT_URL', plugin_dir_url( EASYOPT_PLUGIN_FILE ) );
}

// ── Strip ?eopreload from superglobals ────────────────────────────────
// The cache preloader appends ?eopreload=1 to warm-up requests so they
// can be identified by server-side code. If left in the superglobals,
// WordPress core and third-party plugins (WooCommerce add-to-cart links,
// Contact Form 7 form actions, menu URLs, etc.) will read REQUEST_URI
// and embed the parameter into the rendered HTML. That contaminated HTML
// then gets cached and served to real visitors, producing broken links
// like /?eopreload=1&add-to-cart=123. Stripping here — before ANY
// WordPress function reads the URI — guarantees clean output while
// preserving the ability to detect preload requests via the
// EASYOPT_IS_PRELOAD_REQUEST constant set just below. (The preloader no
// longer sends a custom X-* header — a non-standard header is a bot signal
// that some host WAFs block.)
/**
 * Shared secret for authenticating preload loopbacks (2.6.0).
 *
 * MUST work at plugin-file scope, which rules out wp_salt(): that lives in
 * pluggable.php, and wp-settings.php does not load pluggable.php until AFTER
 * active plugins. The wp-config salt constants are defined before plugins
 * load, so they are the primary source; a generated option covers installs
 * that define no salt constants at all.
 *
 * @since 2.6.0
 * @return string Secret, or '' when none can be resolved.
 */
function easyopt_preload_secret() {
    static $secret = null;
    if ( null !== $secret ) {
        return $secret;
    }
    $parts = array();
    foreach ( array( 'AUTH_KEY', 'AUTH_SALT', 'NONCE_SALT' ) as $easyopt_const ) {
        if ( defined( $easyopt_const ) && '' !== (string) constant( $easyopt_const ) ) {
            $parts[] = (string) constant( $easyopt_const );
        }
    }
    if ( ! empty( $parts ) ) {
        $secret = implode( '|', $parts );
        return $secret;
    }
    // No salt constants (unusual). Fall back to a generated option; read
    // only on requests that actually carry the preload marker.
    $secret = (string) get_option( 'easyopt_preload_secret', '' );
    return $secret;
}

/**
 * HMAC binding a preload request to its slot token.
 *
 * @since 2.6.0
 * @param string $slot Slot token ('' for warms that reserve no slot).
 * @return string Hex signature, or '' when no secret is available.
 */
function easyopt_preload_signature( $slot = '' ) {
    $secret = easyopt_preload_secret();
    if ( '' === $secret ) {
        return '';
    }
    return hash_hmac( 'sha256', 'easyopt_preload|' . (string) $slot, $secret );
}

if ( isset( $_GET['eopreload'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
    // (2.6.0) AUTHENTICATE the marker. Previously any anonymous client could
    // set EASYOPT_IS_PRELOAD_REQUEST by appending ?eopreload=1, which forces a
    // guaranteed cache MISS, a full WordPress render, the entire optimization
    // pipeline, a gzip and a cache write — the most expensive request the site
    // can serve, unthrottled — while also switching off two independent
    // logged-in detection paths. The PSI bypass twenty lines below was already
    // token-gated for exactly this reason; the same standard now applies here.
    //
    // The query args are stripped either way, so a forged or stale link can
    // never leak ?eopreload=1 into rendered HTML or a canonical URL.
    $easyopt_slot = isset( $_GET['eoslot'] ) // phpcs:ignore WordPress.Security.NonceVerification
        ? preg_replace( '/[^a-f0-9]/', '', (string) $_GET['eoslot'] ) // phpcs:ignore WordPress.Security.NonceVerification
        : '';
    $easyopt_sig  = isset( $_GET['eosig'] ) // phpcs:ignore WordPress.Security.NonceVerification
        ? preg_replace( '/[^a-f0-9]/', '', (string) $_GET['eosig'] ) // phpcs:ignore WordPress.Security.NonceVerification
        : '';
    $easyopt_want = easyopt_preload_signature( $easyopt_slot );

    if ( '' !== $easyopt_want && '' !== $easyopt_sig && hash_equals( $easyopt_want, $easyopt_sig ) ) {
        define( 'EASYOPT_IS_PRELOAD_REQUEST', true );
        if ( '' !== $easyopt_slot ) {
            define( 'EASYOPT_PRELOAD_SLOT', $easyopt_slot );
        }
    } elseif ( '' === $easyopt_want ) {
        // No secret resolvable at all (no salt constants AND no generated
        // option yet — e.g. mid-activation). Preserve pre-2.6.0 behaviour
        // rather than silently breaking preload on such installs.
        define( 'EASYOPT_IS_PRELOAD_REQUEST', true );
        if ( '' !== $easyopt_slot ) {
            define( 'EASYOPT_PRELOAD_SLOT', $easyopt_slot );
        }
    }

    unset( $_GET['eoslot'], $_REQUEST['eoslot'] );       // phpcs:ignore WordPress.Security.NonceVerification
    unset( $_GET['eosig'], $_REQUEST['eosig'] );         // phpcs:ignore WordPress.Security.NonceVerification
    unset( $_GET['eopreload'], $_REQUEST['eopreload'] ); // phpcs:ignore WordPress.Security.NonceVerification
    $easyopt_clean_qs  = http_build_query( $_GET );
    $easyopt_uri_path  = strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), '?' );
    $_SERVER['REQUEST_URI']  = $easyopt_uri_path . ( '' !== $easyopt_clean_qs ? '?' . $easyopt_clean_qs : '' );
    $_SERVER['QUERY_STRING'] = $easyopt_clean_qs;
    unset( $easyopt_clean_qs, $easyopt_uri_path, $easyopt_slot, $easyopt_sig, $easyopt_want );
}
if ( ! defined( 'EASYOPT_IS_PRELOAD_REQUEST' ) ) {
    define( 'EASYOPT_IS_PRELOAD_REQUEST', false );
}
if ( ! defined( 'EASYOPT_PRELOAD_SLOT' ) ) {
    define( 'EASYOPT_PRELOAD_SLOT', '' );
}

/**
 * Query-string debug switches (2.3.0).
 *
 * Lets you disable a single optimization for one page load without touching
 * settings — handy for diagnosing whether a feature is causing an issue:
 *
 *   ?nooptimize  — skip ALL output-buffer optimizations for this request
 *   ?nocache     — bypass the page cache (serve + store) for this request
 *   ?nodelayjs   — don't delay/defer JavaScript for this request
 *   ?norucss     — don't apply Remove Unused CSS for this request
 *
 * Any query parameter already forces a cache MISS (the request is neither
 * served from nor written to the page cache), so a switch always reflects
 * fresh, switch-applied output. `?nocache` is honoured by the cache layer
 * directly; the others are read here by the relevant modules.
 *
 * @param string $name One of: nooptimize, nocache, nodelayjs, norucss.
 * @return bool
 */
function easyopt_debug_switch( $name ) {
    static $cache = array();
    if ( ! isset( $cache[ $name ] ) ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $cache[ $name ] = isset( $_GET[ $name ] );
    }
    return $cache[ $name ];
}

/**
 * (2.6.2) Portable unique temp-file suffix.
 *
 * `getmypid()` is disabled at the php.ini level on Flywheel, WP Engine and many
 * hardened hosts. Since PHP 8.0 a disabled function is REMOVED from the function
 * table rather than stubbed, so calling one throws
 * `Error: Call to undefined function` — which `@` cannot suppress. Every call to
 * a non-guaranteed function must therefore be behind function_exists().
 *
 * @return string Collision-resistant suffix for a temp filename.
 */
if ( ! function_exists( 'easyopt_tmp_token' ) ) {
    function easyopt_tmp_token() {
        static $pid = null;
        if ( null === $pid ) {
            $pid = function_exists( 'getmypid' ) ? (string) getmypid() : 'np';
        }
        return $pid . '-' . str_replace( '.', '', uniqid( '', true ) );
    }
}

/**
 * (2.6.2) Central logged-in optimization gate.
 *
 * Frontend optimization is skipped for EVERY logged-in user unless "Cache for
 * Logged-in Users" is on. With logged-in caching off a logged-in pageview is
 * rendered live every single time, so the work is repeated per request and never
 * reused — pure cost, and it makes the site owner's view differ from a visitor's
 * for no benefit. With logged-in caching on the optimized HTML is stored in a
 * role-keyed cache slot, so producing it is worth the work.
 *
 * Deliberately NOT used by Used CSS or LCP Preload. Those two write GLOBAL
 * artifacts — `{type}.used.css` and per-URL LCP records — whose keys carry no
 * role component, so a logged-in render would overwrite the very file anonymous
 * visitors read: baking in admin-bar rules and dropping selectors real visitors
 * need. Both stay unconditionally skipped for logged-in users, exactly as before.
 *
 * @param string $feature Optional feature slug, for the per-feature filter.
 * @return bool True when optimization must be skipped on this request.
 */
if ( ! function_exists( 'easyopt_skip_for_logged_in' ) ) {
    function easyopt_skip_for_logged_in( $feature = '' ) {

        if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
            return false;
        }

        $skip = true;
        if ( class_exists( 'EasyOpt_Config' )
             && 1 === (int) EasyOpt_Config::get( 'cache_logged_in', 0 ) ) {
            $skip = false;
        }

        /**
         * Filter the logged-in gate for every affected feature at once.
         *
         * Membership, LMS and community sites where most real traffic is logged
         * in can restore pre-2.6.2 behaviour with:
         *   add_filter( 'easyopt_skip_for_logged_in', '__return_false' );
         *
         * @param bool   $skip    True to skip optimization.
         * @param string $feature Feature slug ('delay_js', 'minify', …).
         */
        $skip = (bool) apply_filters( 'easyopt_skip_for_logged_in', $skip, $feature );

        if ( '' !== $feature ) {
            /** Per-feature escape hatch, e.g. easyopt_skip_for_logged_in_minify. */
            $skip = (bool) apply_filters( "easyopt_skip_for_logged_in_{$feature}", $skip );
        }

        return $skip;
    }
}

/**
 * (2.6.0) `?eopview` — render this ONE request the way a logged-out visitor
 * would see it, for an administrator who is logged in.
 *
 * Why this exists: several optimizations deliberately skip logged-in users —
 *
 *   Delay JS        class-easyopt-delay-js.php   (any logged-in user, 2.6.2)
 *   Defer JS        class-easyopt-defer-js.php   (any logged-in user, 2.6.2)
 *   Minify CSS/JS   class-easyopt-minify.php     (any logged-in user, 2.6.2)
 *   Lazy load       class-easyopt-lazyload.php   (any logged-in user, 2.6.2)
 *   Font optimize   class-easyopt-fonts.php      (any logged-in user, 2.6.2)
 *   Preconnect      class-easyopt-preconnect.php (any logged-in user, 2.6.2)
 *   Remove Unused CSS  class-easyopt-unused-css.php (any logged-in user)
 *   LCP Preload     class-easyopt-lcp.php        (any logged-in user)
 *
 * That is correct for day-to-day editing, but it made the debug switches
 * useless from an admin session: ?nodelayjs turns off something that was
 * already off, so the page looks identical and the switch reports "not the
 * cause" — a false negative on the exact question the user is asking. The
 * only reliable workaround was a private window.
 *
 * All four gates already expose a filter, so this flips those filters and
 * hides the admin bar. It only ever turns optimizations ON, never off, and
 * only for a user who can manage_options — there is no capability to gain
 * here, since a logged-out visitor receives this treatment anyway.
 *
 * Cache safety: the marker is a real query parameter, so the request is a
 * guaranteed MISS and is never written to disk. An admin render (even with
 * the admin bar suppressed) can therefore never be stored into the anonymous
 * cache slot. `eopview` is listed in EasyOpt_Cache::get_reserved_query_params()
 * so it can never be stripped into the cache key either.
 */
add_action( 'init', function () {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( ! isset( $_GET['eopview'] ) ) {
        return;
    }
    if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
        return;
    }
    add_filter( 'easyopt_delay_js_admin',  '__return_true' );
    add_filter( 'easyopt_defer_js_admin',  '__return_true' );
    add_filter( 'easyopt_rucss_logged_in', '__return_true' );
    add_filter( 'easyopt_lcp_logged_in',   '__return_true' );
    // (2.6.2) The unified gate now covers Delay JS, Defer JS, Minify, Lazy load,
    // Fonts and Preconnect. Forcing it off is what makes ?eopview show the real
    // visitor render rather than a partially-optimized one.
    add_filter( 'easyopt_skip_for_logged_in', '__return_false', 99 );
    // The admin bar injects its own CSS/JS and shifts the layout, so leaving
    // it in place would misrepresent what a visitor gets.
    add_filter( 'show_admin_bar', '__return_false' );
}, 1 );

// Composer autoload (matthiasmullie/minify) is loaded on-demand by its
// consumer (EasyOpt_Minify), NOT on every request. RUCSS no longer needs it
// (2.6.4): CSS parsing moved to the dependency-free EasyOpt_CSS_Tokenizer.

// BEFORE any module so that EasyOpt_Config::get() is available everywhere.
// the schema, the config class is the read/write API, the save
// coordinator listens to easyopt_settings_saved and runs side-effects
// at most once per save, and the REST class is the HTTP entrypoint.
// (2.3.3) Classmap autoloader — safety net under the explicit requires
// below. The require ladder is KEPT deliberately: it encodes the
// load-only-enabled-features optimisation and a known-good order. The
// autoloader resolves anything not explicitly required (new namespaced
// modules, traits, rarely-used classes) lazily on first use.
require_once EASYOPT_DIR . 'includes/class-easyopt-autoloader.php';
EasyOpt_Autoloader::register();

require_once EASYOPT_DIR . 'includes/class-easyopt-settings-registry.php';
require_once EASYOPT_DIR . 'includes/class-easyopt-config.php';
require_once EASYOPT_DIR . 'includes/class-easyopt-migration.php';
require_once EASYOPT_DIR . 'includes/class-easyopt-save-coordinator.php';
require_once EASYOPT_DIR . 'includes/class-easyopt-rest-settings.php';
require_once EASYOPT_DIR . 'includes/class-easyopt-rest-dashboard.php';
require_once EASYOPT_DIR . 'includes/class-easyopt-presets.php';
require_once EASYOPT_DIR . 'includes/class-easyopt-hosting.php';
require_once EASYOPT_DIR . 'includes/class-easyopt-debug-log.php';
require_once EASYOPT_DIR . 'includes/class-easyopt-cache-counter.php';
require_once EASYOPT_DIR . 'includes/class-easyopt-tracker.php';
EasyOpt_Config::init();
EasyOpt_Debug_Log::init();
EasyOpt_Save_Coordinator::init();
EasyOpt_Rest_Settings::init();
EasyOpt_Rest_Dashboard::init();
EasyOpt_Presets::init();

// Lightweight opt-in usage tracking.
EasyOpt_Tracker::register();
// FluxPress Adaptive Images (v3 cloud). Registers its REST routes, the
// admin/cron upkeep hook, and nothing else — dormant until an account
// exists, so the legacy path is unaffected.
if ( class_exists( 'EasyOpt_REST_Cloud' ) ) { EasyOpt_REST_Cloud::register(); }

// Register 'weekly' cron interval if not already available.
add_filter( 'cron_schedules', function ( $schedules ) {
    if ( ! isset( $schedules['weekly'] ) ) {
        $schedules['weekly'] = array(
            'interval' => WEEK_IN_SECONDS,
            'display'  => __( 'Once Weekly', 'easy-optimizer' ),
        );
    }
    return $schedules;
} );
EasyOpt_Hosting::init();
EasyOpt_Cache_Counter::init();
if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
    // (2.5.0) Per-URL preload results ledger — creates its table on upgrade
    // (plugins_loaded prio 6) and drops it on uninstall.
    EasyOpt_Preload_Results::init();
}
// One-shot migration from 1.6.x → 1.7.0. Self-guards via a marker
// option so it's a single option-read no-op once already run. We hook
// `init` rather than `admin_init` so the migration completes even if
// the first request after upgrade is a frontend pageview, ensuring
// the user's existing settings take effect without waiting for an
// admin visit. The shim has a pre-migration $wpdb fallback so reads
// before this fires still return the correct values.
add_action( 'init', array( 'EasyOpt_Migration', 'maybe_run' ), 0 );

// Includes — module files. Loaded in dependency order: cache infra first
// (wp-config writer, drop-in installer, cloudflare) then features.
// Always-needed infrastructure
$easyopt_includes = array(
    'class-easyopt-wp-config.php',
    'class-easyopt-advanced-cache.php',
    'class-easyopt-object-cache.php',
    'class-easyopt-cloudflare.php',
    'class-easyopt-settings.php',
    'class-easyopt-wizard.php',
    'class-easyopt-cache.php',
    // its only consumer for now; future image-opt + db-opt jobs will
    // share it).
    'class-easyopt-queue.php',
    'class-easyopt-preload-results.php',
    'class-easyopt-cache-preload.php',
    'class-easyopt-db-snapshot.php',
    'class-easyopt-database.php',
    'class-easyopt-bloat.php',
);
foreach ( $easyopt_includes as $easyopt_file ) {
    $easyopt_path = EASYOPT_DIR . 'includes/' . $easyopt_file;
    if ( file_exists( $easyopt_path ) ) {
        require_once $easyopt_path;
    }
}

// Object Cache controller — registers an admin notice only; install/uninstall
// is driven by the save coordinator and the activation/deactivation hooks.
if ( class_exists( 'EasyOpt_Object_Cache_Manager' ) ) {
    EasyOpt_Object_Cache_Manager::init();
}

// Feature modules — loaded only when their feature is enabled.
// This saves ~0.5ms and memory on every request when features are off.
$easyopt_opts = EasyOpt_Config::all();
$easyopt_conditional = array();
if ( ! empty( $easyopt_opts['easyopt_img_opt'] ) || ! empty( $easyopt_opts['easyopt_elementor_bg_cdn'] ) ) {
    $easyopt_conditional[] = 'class-easyopt-cdn.php';
}
if ( ! empty( $easyopt_opts['easyopt_unused_css'] ) ) {
    $easyopt_conditional[] = 'class-easyopt-unused-css.php';
}
if ( ! empty( $easyopt_opts['easyopt_minify_css'] ) || ! empty( $easyopt_opts['easyopt_minify_js'] ) ) {
    $easyopt_conditional[] = 'class-easyopt-minify.php';
}
if ( ! empty( $easyopt_opts['easyopt_delay_js'] ) ) {
    $js_method = isset( $easyopt_opts['easyopt_delay_js_method'] ) ? $easyopt_opts['easyopt_delay_js_method'] : 'delay';
    if ( 'defer' === $js_method ) {
        $easyopt_conditional[] = 'class-easyopt-defer-js.php';
    } else {
        $easyopt_conditional[] = 'class-easyopt-delay-js.php';
    }
}
if ( ! empty( $easyopt_opts['easyopt_lazy_images'] ) || ! empty( $easyopt_opts['easyopt_lazy_iframes'] )
     || ! empty( $easyopt_opts['easyopt_lazy_videos'] ) || ! empty( $easyopt_opts['easyopt_add_missing_dims'] ) ) {
    $easyopt_conditional[] = 'class-easyopt-lazyload.php';
}
if ( ! empty( $easyopt_opts['easyopt_font_display_swap'] ) || ! empty( $easyopt_opts['easyopt_lazyload_fonts'] ) ) {
    $easyopt_conditional[] = 'class-easyopt-fonts.php';
}
if ( ! empty( $easyopt_opts['easyopt_lcp_preload'] ) ) {
    $easyopt_conditional[] = 'class-easyopt-lcp.php';
}
// Images: the delivery pass rewrites <img> into <picture>, so it must be
// present in the buffer pipeline (run_processor uses class_exists(.., false)
// and will not autoload it).
if ( ! empty( $easyopt_opts['easyopt_images'] ) ) {
    $easyopt_conditional[] = 'class-easyopt-images-delivery.php';
}
// FluxPress static assets. Same reason as above: run_processor() uses
// class_exists( .., false ) and will not autoload the class.
if ( ! empty( $easyopt_opts['easyopt_cloud_assets'] ) ) {
    $easyopt_conditional[] = 'class-easyopt-cdn-assets.php';
}
// Prefetch Pages (2.6.7) — the speculative navigation engine. Loaded only when
// the toggle is on; when it is off nothing is registered and WordPress Core's
// own speculative loading is left completely untouched.
if ( ! empty( $easyopt_opts['easyopt_instant_preload'] ) ) {
    $easyopt_conditional[] = 'class-easyopt-navigate.php';
}
// Accessibility & SEO — check any toggle
$a11y_keys = array( 'easyopt_a11y_inputs', 'easyopt_a11y_links', 'easyopt_a11y_buttons',
    'easyopt_a11y_viewport', 'easyopt_a11y_role_elements', 'easyopt_a11y_iframes',
    'easyopt_a11y_progressbar', 'easyopt_a11y_tabindex' );
foreach ( $a11y_keys as $ak ) {
    if ( ! empty( $easyopt_opts[ $ak ] ) ) {
        $easyopt_conditional[] = 'class-easyopt-accessibility.php';
        break;
    }
}
if ( ! empty( $easyopt_opts['easyopt_seo_crawlable_links'] ) || ! empty( $easyopt_opts['easyopt_seo_image_alts'] ) ) {
    $easyopt_conditional[] = 'class-easyopt-seo.php';
}
// (2.5.7) Preconnect was absent from BOTH require arrays and reached the
// buffer pipeline only because run_processor()'s class_exists() autoloaded
// it. Now that autoloading is off there, it needs a real ladder entry or the
// module silently stops running. Registry default is 1, so a missing key is
// treated as on — matching EasyOpt_Preconnect::process_buffer()'s own default.
if ( ! isset( $easyopt_opts['easyopt_preconnect'] ) || ! empty( $easyopt_opts['easyopt_preconnect'] ) ) {
    $easyopt_conditional[] = 'class-easyopt-preconnect.php';
}
// Backend Analyzer (2.4.0) — namespaced module, resolved by the classmap
// autoloader (no require line needed). Loaded only when enabled; analyzers
// inside it additionally arm only on token-authenticated profile requests,
// so steady-state overhead is zero either way.
if ( ! empty( $easyopt_opts['easyopt_backend_analyzer'] ) ) {
    \EasyOpt\Backend\Backend::init();
}
// PSI benchmark client (2.4.0) — dashboard Before/After scores. Always
// loaded: it only registers REST routes + the baseline bypass check, and
// the bypass validation must work regardless of which features are on.
\EasyOpt\Psi\Client::init();
foreach ( $easyopt_conditional as $easyopt_cfile ) {
    $easyopt_cpath = EASYOPT_DIR . 'includes/' . $easyopt_cfile;
    if ( file_exists( $easyopt_cpath ) ) {
        require_once $easyopt_cpath;
    }
}
if ( class_exists( 'EasyOpt_Navigate' ) ) {
    EasyOpt_Navigate::init();
}
unset( $easyopt_opts, $easyopt_conditional, $a11y_keys );

// ── Compatibility modules — always loaded, self-guard internally ────
$easyopt_compat_dir = EASYOPT_DIR . 'includes/compat/';
$easyopt_compat_files = array(
    'class-easyopt-compat-conflicts.php',
    'class-easyopt-compat-builders.php',
    'class-easyopt-compat-woocommerce.php',
    'class-easyopt-compat-lazyload.php',
    'class-easyopt-compat-fonts.php',
    'class-easyopt-compat-divi.php',
    'class-easyopt-compat-multilingual.php',
);
foreach ( $easyopt_compat_files as $easyopt_cf ) {
    $easyopt_cfp = $easyopt_compat_dir . $easyopt_cf;
    if ( file_exists( $easyopt_cfp ) ) {
        require_once $easyopt_cfp;
    }
}
unset( $easyopt_compat_dir, $easyopt_compat_files, $easyopt_cf, $easyopt_cfp );

// Boot compat modules.
EasyOpt_Compat_Conflicts::init();
if ( class_exists( 'EasyOpt_Compat_Multilingual' ) ) {
    EasyOpt_Compat_Multilingual::init();
}
EasyOpt_Notices::init();
// Images. The orchestrator is admin/queue-side only: it hooks uploads,
// deletion and the two queue callbacks, none of which exist on a frontend
// pageview. Loading it there would autoload ~30 KB of PHP per request for
// nothing -- the same cost the conditional require ladder above exists to
// avoid. Frontend delivery is a separate class (EasyOpt_Images_Delivery),
// loaded by that ladder only when the feature is on.
//
// The string callback below does NOT autoload: rest_api_init fires only on
// REST requests, which is how the queue runner's loopback reaches the
// optimize/restore handlers without costing frontend requests anything.
add_action( 'rest_api_init', array( 'EasyOpt_Images', 'init_always' ) );
if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    EasyOpt_Images::init_always();
    EasyOpt_Images::init();
}
EasyOpt_Compat_Builders::init();
add_action( 'plugins_loaded', array( 'EasyOpt_Compat_WooCommerce', 'init' ), 20 );
EasyOpt_Compat_LazyLoad::init();
if ( class_exists( 'EasyOpt_Compat_Fonts' ) ) {
    EasyOpt_Compat_Fonts::init();
}
add_action( 'after_setup_theme', array( 'EasyOpt_Compat_Divi', 'init' ), 9999 );

// First-run setup wizard (admin page + one-time redirect).
if ( class_exists( 'EasyOpt_Wizard' ) ) {
    EasyOpt_Wizard::init();
}

/**
 * Main plugin class (singleton)
 */
final class Easy_Optimizer {

    private static $instance = null;

    /** @var EasyOpt_CDN|null */
    public $cdn = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
            self::$instance->setup_hooks();
        }
        return self::$instance;
    }

    private function __construct() {}

    private function setup_hooks() {
        // path is no longer used. Settings live in a single wp_options row
        // (`easyopt_settings`) and the save flow is REST-driven. The
        // schema is owned by EasyOpt_Settings_Registry.
        add_action( 'admin_menu', array( 'EasyOpt_Settings', 'register_options_page' ), 20 );
        add_filter( 'plugin_action_links_' . plugin_basename( EASYOPT_PLUGIN_FILE ), array( $this, 'plugin_action_links' ) );

        // frontend & assets
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // "noojs" body class: rendered into the HTML server-side and removed by
        // a normal inline script on DOMContentLoaded. The remover is NOT
        // excluded from Delay JS, so when Delay JS is enabled it is delayed too
        // and the class is dropped only when delayed JS executes — giving CSS a
        // `body.noojs` hook for the pre-JS / pre-delay state. With Delay JS off
        // it is removed on the normal DOMContentLoaded.
        add_filter( 'body_class', array( $this, 'add_noojs_body_class' ) );
        add_action( 'wp_footer', array( $this, 'print_noojs_remover' ), 99 );

        // (2.7.1) Cloud Unused CSS reveal. When Unused CSS is enabled via
        // the Smart Images add-on, the full stylesheets are held as
        // data-easyopt-delayed (the used-CSS is inlined). This swaps them
        // back in. When Delay JS is on it is emitted as an easyoptscript so
        // it runs on first interaction rather than blocking load.
        // Priority 9999 so the reveal is the last thing in the footer — it runs
        // after every stylesheet link exists. Emitted only when the Unused CSS
        // cloud option (easyopt_cloud_unused_css) is on; see the method.
        add_action( 'wp_footer', array( $this, 'print_cloud_ucss_reveal' ), 9999 );

        // Output processing — open our capture buffer as the OUTERMOST
        // userland buffer (on plugins_loaded, before the theme loads and
        // before any rendering-stage buffers). Being outermost is what makes
        // capture robust: a theme/plugin doing a bare ob_end_flush(),
        // ob_end_clean() or ob_get_clean() (the cause of the duplicated
        // </body></html> we've seen) closes the INNER buffer, never ours, so
        // we still receive the complete document and the live page is never
        // dropped or blanked. This mirrors how mature caching plugins
        // (FlyingPress, WP Rocket) capture output. It runs AFTER
        // EasyOpt_Cache::maybe_serve_cache() (priority -PHP_INT_MAX), so a
        // cache HIT is served and exits before we ever open a buffer.
        add_action( 'plugins_loaded', array( $this, 'start_output_buffer' ), -99999 );

        // textdomain
        add_action( 'init', array( $this, 'load_textdomain' ) );

        // CDN module — only instantiated when the class was conditionally loaded.
        if ( class_exists( 'EasyOpt_CDN' ) ) {
            $this->cdn = new EasyOpt_CDN();
        }

        // Unused CSS module
        if ( class_exists( 'EasyOpt_Unused_CSS' ) ) {
            // Register admin bar, AJAX, admin-post, auto-clear hooks (runs everywhere).
            add_action( 'init', array( 'EasyOpt_Unused_CSS', 'init_always' ) );
        }

        // LCP Preload module
        if ( class_exists( 'EasyOpt_LCP' ) ) {
            add_action( 'init', array( 'EasyOpt_LCP', 'init_always' ) );
        }

        // Fonts module (registers filters even outside the output buffer).
        if ( class_exists( 'EasyOpt_Fonts' ) ) {
            add_action( 'init', array( 'EasyOpt_Fonts', 'init_always' ) );
        }

        // so the REST endpoint, watchdog cron, and table-creation hook
        // are all wired before anything else (preload, cache invalidation)
        // tries to enqueue. Idempotent on every page load.
        if ( class_exists( 'EasyOpt_Queue' ) ) {
            add_action( 'plugins_loaded', array( 'EasyOpt_Queue', 'init' ), 1 );
        }

        // Page cache module — independent of all other features. Cache off?
        // Capture function bails internally and the rest of the chain still runs.
        if ( class_exists( 'EasyOpt_Cache' ) ) {
            // (2.6.0) The runtime serve path MUST be registered here, at file
            // scope, and at a priority lower than start_output_buffer's
            // -99999. It was previously registered from inside
            // EasyOpt_Cache::init() — itself a plugins_loaded callback at
            // priority 1 — which added it to a priority that had already been
            // passed. WP_Hook::resort_active_iterations() skips exactly that
            // case, so maybe_serve_cache() never executed on any request. On
            // hosts where the drop-in cannot be installed (Pantheon,
            // read-only filesystems, hardened containers) that meant the page
            // cache WROTE a file on every request and never once served one.
            //
            // Kill switch: define( 'EASYOPT_DISABLE_RUNTIME_SERVE', true ) in
            // wp-config.php disables this path without a downgrade, for any
            // host where the newly-live serve path misbehaves.
            if ( ! defined( 'EASYOPT_DISABLE_RUNTIME_SERVE' ) || ! EASYOPT_DISABLE_RUNTIME_SERVE ) {
                add_action( 'plugins_loaded', array( 'EasyOpt_Cache', 'maybe_serve_cache' ), -100000 );
            }
            add_action( 'plugins_loaded', array( 'EasyOpt_Cache', 'init' ), 1 );
        }
        if ( class_exists( 'EasyOpt_Cache_Preload' ) ) {
            add_action( 'init', array( 'EasyOpt_Cache_Preload', 'init' ) );
            // Handler for 'easyopt_delayed_preload_start' lives in
            // EasyOpt_Cache::init() (class-easyopt-cache.php:69).
            // Do NOT duplicate it here — start() is not idempotent.
        }
        // 0.5s inter-task delay replaces the EMA-based controller which
        // added 2 DB operations per warm task.
        // Cloudflare integration — purges edge cache when our cache clears.
        if ( class_exists( 'EasyOpt_Cloudflare' ) ) {
            add_action( 'init', array( 'EasyOpt_Cloudflare', 'init' ) );
        }

        // Database optimisation tab — AJAX handlers + cron.
        if ( class_exists( 'EasyOpt_Database' ) ) {
            add_action( 'init', array( 'EasyOpt_Database', 'init' ) );
        }

        if ( class_exists( 'EasyOpt_DB_Snapshot' ) ) {
            add_action( 'init', array( 'EasyOpt_DB_Snapshot', 'init' ) );
        }

        // Bloat removal — runs only if any toggle is on; must hook early so
        // emoji removal etc. catches the right wp_head priorities.
        if ( class_exists( 'EasyOpt_Bloat' ) ) {
            add_action( 'init', array( 'EasyOpt_Bloat', 'init' ), 1 );
        }

        // Unified admin bar parent node — a single "Easy Optimizer" entry that
        // every module attaches its actions to. Registered at priority 80 so
        // it always exists before child nodes are added at 999.
        add_action( 'admin_bar_menu', array( $this, 'admin_bar_parent' ), 80 );
    }

    /**
     * Unified admin-bar root node. Modules add children to 'easyopt-root'.
     */
    public function admin_bar_parent( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Only render when at least one module wants something in the menu.
        $any_active = (
            (int) EasyOpt_Config::get( 'unused_css', 0 )
            || (int) EasyOpt_Config::get( 'cache', 0 )
            || (int) EasyOpt_Config::get( 'lcp_preload', 0 )
        );
        if ( ! $any_active ) {
            return;
        }

        $wp_admin_bar->add_node( array(
            'id'    => 'easyopt-root',
            'title' => esc_html__( 'Easy Optimizer', 'easy-optimizer' ),
            'href'  => admin_url( 'admin.php?page=easy-optimizer' ),
        ) );
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'easy-optimizer', false, dirname( plugin_basename( EASYOPT_PLUGIN_FILE ) ) . '/languages' );
    }

    /**
     * Append the "noojs" marker class to <body>. Removed client-side by
     * print_noojs_remover() once JS runs (or once delayed JS runs, when
     * Delay JS is enabled).
     *
     * @param array $classes Body classes.
     * @return array
     */
    public function add_noojs_body_class( $classes ) {
        $classes[] = 'noojs';
        return $classes;
    }

    /**
     * Print the inline "noojs" remover in the footer.
     *
     * Hardened for sites with broken output buffering / duplicated footers:
     *   - Emits at most ONCE per request (static guard), even if wp_footer
     *     fires multiple times.
     *   - Only on real front-end HTML page views (never admin / AJAX / REST /
     *     JSON / feed / embed / 404), so it can't leak into non-HTML responses.
     *   - The script self-guards with window.__eoNoojs, so if a misbehaving
     *     buffer duplicates the whole document the remover still runs only once
     *     and never redeclares a global.
     *
     * Still a plain inline script (not excluded from Delay JS): when Delay JS
     * is on it is delayed and the class drops when delayed JS runs; when off it
     * drops on the normal DOMContentLoaded.
     */
    /**
     * Reveal delayed stylesheets when Unused CSS is on via the cloud add-on.
     *
     * Emitted as type="easyoptscript" when Delay JS is active so the Delay JS
     * runtime executes it on first interaction; as a normal inline script
     * otherwise. Same front-end guards as the noojs remover.
     */
    public function print_cloud_ucss_reveal() {
        static $done = false;
        if ( $done ) {
            return;
        }
        if ( ! (int) EasyOpt_Config::get( 'easyopt_cloud_unused_css', 0 ) ) {
            return;
        }
        if ( is_admin()
            || is_feed()
            || is_embed()
            || is_404()
            || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
            || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
            || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
            return;
        }
        $done = true;

        $type = (int) EasyOpt_Config::get( 'easyopt_delay_js', 0 ) ? ' type="easyoptscript"' : '';
        echo '<script' . $type . '>document.querySelectorAll("link[data-easyopt-delayed]").forEach(function(e){e.setAttribute("href",e.getAttribute("data-easyopt-delayed"));e.removeAttribute("data-easyopt-delayed")});</script>' . "
"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function print_noojs_remover() {

        static $done = false;
        if ( $done ) {
            return;
        }
        if ( is_admin()
            || is_feed()
            || is_embed()
            || is_404()
            || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
            || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
            || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
            return;
        }
        $done = true;

        echo '<script>'
            . 'if(!window.__eoNoojs){window.__eoNoojs=1;'
            . 'var eopfn=function(){var b=document.body;if(b){b.classList.remove("noojs")}};'
            . 'document.addEventListener("DOMContentLoaded",function(){eopfn()})}'
            . '</script>' . "\n";
    }

    public function plugin_action_links( $links ) {
        $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=easy-optimizer' ) ) . '">' . esc_html__( 'Settings', 'easy-optimizer' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function enqueue_frontend_scripts() {
        $lazy_images     = (int) EasyOpt_Config::get( 'lazy_images', 0 );
        $lazy_iframes    = (int) EasyOpt_Config::get( 'lazy_iframes', 0 );
        $lazy_videos     = (int) EasyOpt_Config::get( 'lazy_videos', 0 );
        $any_lazy        = $lazy_images || $lazy_iframes || $lazy_videos;

        // Prefetch Pages enqueues nothing here. Since 2.6.7 it is a
        // server-emitted <script type="speculationrules"> printed into <head>
        // by EasyOpt_Navigate — no external file, no footer boot call, and no
        // dependency on when scripts happen to execute. See
        // includes/class-easyopt-navigate.php.

        // (2.5.5) In native mode the browser handles images, iframes and
        // <picture> itself, so the full lazysizes build is not enqueued at
        // all. The only markup that still needs JavaScript — CSS background
        // images and deferred <video> — is served by a ~2KB shim injected
        // inline by maybe_inject_lazy_runtime(), and ONLY on pages that
        // actually contain it. Most native-mode pages therefore ship zero
        // lazy-load JavaScript and make one fewer request.
        //
        // Legacy mode is deliberately unchanged: the external, footer,
        // browser-cacheable file stays exactly as it was.
        $easyopt_native_lazy = class_exists( 'EasyOpt_LazyLoad' )
            && method_exists( 'EasyOpt_LazyLoad', 'native_mode' )
            && EasyOpt_LazyLoad::native_mode();

        if ( $any_lazy && ! $easyopt_native_lazy ) {
            wp_register_script( 'easyopt-lazysizes', EASYOPT_URL . 'assets/lazyload.min.js', array(), EASYOPT_VERSION, true );

            wp_enqueue_script( 'easyopt-lazysizes' );
        }
    }

    public function enqueue_admin_assets( $hook ) {
        // 2.0 — The React dashboard handles its own script/style loading
        // via EasyOpt_Settings::enqueue_app(), hooked to admin_print_scripts.
        // This function is now a no-op for the settings page.
        // Old script.js (jQuery + admin-ajax polling) and easyopt-save.js
        // (vanilla JS save bar) are fully replaced by assets/app.js (React).
    }

    public function start_output_buffer() {
        // Only buffer genuine front-end HTML page requests. This now runs at
        // plugins_loaded (before init/template_redirect), so the check relies
        // on signals available that early; anything non-HTML that slips
        // through is returned untouched by master_output_processor().
        if ( ! $this->should_buffer_request() ) {
            return;
        }

        // Skip output buffering entirely when no buffer-processing feature is on.
        if ( ! $this->any_buffer_feature_active() ) {
            return;
        }

        //
        // EasyOpt_Cache::write_htaccess() does a loopback GET against the
        // homepage to verify our new rules don't 500 the site. That request
        // hits this hook, which would otherwise install the master output
        // processor and run every optimization filter we have (CDN,
        // lazyload, unused-CSS, fonts, a11y, delay-JS, cache write, …) on
        // a response that we throw away anyway.
        //
        // For activation/install loopbacks the work is wasted. We bail
        // immediately with a 60-byte 200 so Apache's job (parse the new
        // rules without errors) is the only thing being measured. On a
        // Cloudways droplet this brings probe wallclock from 1–3 seconds
        // (and a one-core CPU spike) down to ~30 ms.
        if ( class_exists( 'EasyOpt_Cache' ) && EasyOpt_Cache::is_probe_request() ) {
            // Don't allow caching, indexing, or further filtering of this
            // synthetic response — it's intentionally not real content.
            nocache_headers();
            header( 'X-Robots-Tag: noindex, nofollow', true );
            header( 'Content-Type: text/plain; charset=utf-8', true );
            echo "easyopt_probe_ok\n";
            exit;
        }

        // A preload loopback carrying ?eonobuf=1 explicitly wants the page
        // rendered WITHOUT our buffer — the preloader uses it to recover a
        // clean body on sites where our buffer can't capture. Never buffer it.
        if ( isset( $_GET['eonobuf'] ) ) {
            return;
        }

        // On sites where the in-process buffer has proven unreliable (a theme
        // or host rewrites/cleans the whole output-buffer stack, which can
        // blank the page), we don't open our buffer for normal visitors at all
        // — caching and optimization are handled by the preloader instead.
        // Preload loopbacks (?eopreload) STILL open the buffer, so the
        // condition is continuously re-tested and self-heals if the
        // environment changes (e.g. the offending plugin is removed).
        //
        // (2.4.5) skip_live_buffer is a CACHE-DEPENDENT strategy: "skip the
        // live render, let the preloader optimize and cache instead." That
        // fallback only exists when the page cache is ON. With the cache OFF
        // the live buffer is the ONLY way to optimize, so we must NOT skip it
        // — otherwise turning the cache off silently disables ALL optimization
        // (no live render, and no cache to serve a pre-optimized page from).
        $easyopt_cache_on = class_exists( 'EasyOpt_Config' )
            ? (int) EasyOpt_Config::get( 'cache', 0 )
            : 1;
        // (2.5.0) Read via the expiring-flag helper: the skip flag now
        // auto-expires after 12h and needs 3 consecutive blank loopbacks to
        // trip (see EasyOpt_Cache_Preload::set_live_buffer_reliable), so a
        // single flaky loopback can no longer silently disable site-wide
        // optimization. Legacy literal-1 flags read as expired.
        // (2.5.7) Use the CONSTANT, not the superglobal. `eopreload` is
        // stripped from $_GET at the top of this file (see the strip block
        // above), so `! isset( $_GET['eopreload'] )` was always true and this
        // guard collapsed to "cache on && skip flag set" — meaning preload
        // loopbacks skipped the buffer too. The self-heal described below
        // never ran, and while the flag was set the site served completely
        // unoptimized pages for the full 12h expiry with every setting still
        // showing as enabled. Every other consumer already uses the constant.
        if ( ! EASYOPT_IS_PRELOAD_REQUEST && $easyopt_cache_on
            && class_exists( 'EasyOpt_Cache_Preload' )
            && EasyOpt_Cache_Preload::live_buffer_skipped() ) {
            return;
        }

        // PSI baseline bypass (2.4.0): a request carrying a valid short-lived
        // bypass token gets the RAW, unoptimized page — no capture buffer, so
        // no minify/delay-JS/RUCSS/lazyload/LCP processing — and the cache
        // already skips it (real query param ⇒ MISS + no write). This is how
        // the dashboard's "Before" PageSpeed score is measured on a live site
        // without flipping any settings. Token-gated (10-min expiry, issued
        // only by the benchmark runner), so it is NOT a public bypass anyone
        // could use to cache-bust. Hook-level tweaks (bloat removal,
        // heartbeat) still apply — the buffer pipeline is the big lever.
        if ( isset( $_GET['easyopt_bypass'] ) && class_exists( '\\EasyOpt\\Psi\\Client' )
             && \EasyOpt\Psi\Client::validate_bypass( (string) wp_unslash( $_GET['easyopt_bypass'] ) ) ) {
            return;
        }

        // Single, OUTERMOST capture buffer. master_output_processor() runs as
        // PHP unwinds the buffer stack at end of request, by which point every
        // inner buffer has flushed its bytes up into ours — so it always sees
        // the COMPLETE document, even on themes that flush early or emit a
        // duplicate </body></html>. We use NO shield buffer and NO manual
        // shutdown flushing: the former can be hijacked by a foreign
        // ob_get_clean() (blanking the page), the latter races WordPress's own
        // wp_ob_end_flush_all() (also blanking the page). Being outermost is
        // the robust, self-contained approach.
        ob_start( array( $this, 'master_output_processor' ) );
    }

    /**
     * Decide whether to open the page-capture buffer for this request. Runs at
     * plugins_loaded, so it only uses signals available that early. Non-HTML
     * that still slips through (e.g. feeds, whose conditional isn't ready yet)
     * is handled safely downstream — master_output_processor() transforms only
     * complete HTML documents and returns everything else unchanged.
     *
     * @return bool
     */
    private function should_buffer_request() {
        if ( is_admin() ) {
            return false;
        }
        if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX )
          || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
            return false;
        }
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return false;
        }
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return false;
        }
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            return false;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return false;
        }

        // Only GET/HEAD can be a cacheable page view.
        $method = isset( $_SERVER['REQUEST_METHOD'] )
            ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
            : 'GET';
        if ( 'GET' !== $method && 'HEAD' !== $method ) {
            return false;
        }

        // REST by URI (REST_REQUEST isn't defined this early).
        $uri = isset( $_SERVER['REQUEST_URI'] )
            ? (string) wp_unslash( $_SERVER['REQUEST_URI'] )
            : '';
        if ( '' !== $uri && ( false !== stripos( $uri, '/wp-json/' ) || false !== stripos( $uri, 'rest_route=' ) ) ) {
            return false;
        }

        return true;
    }

    /**
     * Quick check: is any feature that processes the output buffer enabled?
     * When everything is off, we skip ob_start() entirely.
     */
    private function any_buffer_feature_active() {
        static $result = null;
        if ( null !== $result ) {
            return $result;
        }
        $checks = array(
            'img_opt', 'elementor_bg_cdn',  // CDN
            'lcp_preload',                  // LCP
            'lazy_images', 'lazy_iframes', 'lazy_videos', 'add_missing_dims', // Lazy
            'unused_css',                   // Used CSS
            'minify_css', 'minify_js',      // Minify
            'font_display_swap', 'lazyload_fonts', // Fonts
            'delay_js',                     // Delay JS (includes defer method)
            'preconnect',                   // Resource hints (2.5.5)
            'cache',                        // Page cache capture
        );
        // A11y + SEO toggles
        $a11y = array( 'a11y_inputs', 'a11y_links', 'a11y_buttons', 'a11y_viewport',
                       'a11y_role_elements', 'a11y_iframes', 'a11y_progressbar', 'a11y_tabindex',
                       'seo_crawlable_links', 'seo_image_alts' );
        $checks = array_merge( $checks, $a11y );
        foreach ( $checks as $k ) {
            if ( (int) EasyOpt_Config::get( $k, 0 ) ) {
                $result = true;
                return true;
            }
        }
        $result = false;
        return false;
    }

    /**
     * Inline the minimal lazy runtime, but only when this page emitted
     * markup that cannot resolve without it.
     *
     * EasyOpt_LazyLoad::needs_runtime() is set during the mutation pass by
     * the two cases with no native equivalent — `data-bg` backgrounds and
     * deferred `<video>`. Everything else in native mode is handled by the
     * browser, so the common page ships nothing.
     *
     * Inlining (rather than enqueuing) is what makes the conditional
     * possible: the decision can only be made after the buffer has been
     * processed, which is long past wp_enqueue_scripts.
     *
     * @since 2.5.5
     * @param string $buffer Full page HTML.
     * @return string
     */
    private function maybe_inject_lazy_runtime( $buffer ) {

        if ( ! is_string( $buffer ) || '' === $buffer ) {
            return $buffer;
        }

        if ( ! class_exists( 'EasyOpt_LazyLoad' )
            || ! method_exists( 'EasyOpt_LazyLoad', 'needs_runtime' )
            || ! method_exists( 'EasyOpt_LazyLoad', 'native_mode' ) ) {
            return $buffer;
        }

        // Legacy mode already loaded the full runtime via wp_enqueue_script.
        if ( ! EasyOpt_LazyLoad::native_mode() ) {
            return $buffer;
        }

        if ( ! EasyOpt_LazyLoad::needs_runtime()
            && ! apply_filters( 'easyopt_lazy_force_runtime', false ) ) {
            return $buffer;
        }

        $file = EASYOPT_DIR . 'assets/easyopt-lazy-shim.min.js';
        if ( ! is_readable( $file ) ) {
            $file = EASYOPT_DIR . 'assets/easyopt-lazy-shim.js';
        }
        if ( ! is_readable( $file ) ) {
            return $buffer;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $js = (string) file_get_contents( $file );
        if ( '' === $js ) {
            return $buffer;
        }

        $tag = '<script id="easyopt-lazy-shim">' . $js . '</script>';

        $pos = strripos( $buffer, '</body>' );
        if ( false === $pos ) {
            // Malformed markup (some builders omit </body>) — appending
            // still executes, and a background that never resolves is a
            // far worse failure than a script outside the body.
            return $buffer . $tag;
        }

        return substr( $buffer, 0, $pos ) . $tag . substr( $buffer, $pos );
    }

    public function master_output_processor( $buffer ) {

        // ── Absolute safety contract ──────────────────────────────────────
        // This callback produces the LIVE response. It must NEVER throw and
        // NEVER return an empty/non-string result for non-empty input — either
        // would blank the page. So we snapshot the incoming bytes and, on any
        // failure anywhere below, fall back to them verbatim. This keeps us
        // safe no matter how many other output buffers exist, whether we sit
        // above or below them, or how broken the markup is.
        if ( ! is_string( $buffer ) || '' === $buffer ) {
            return $buffer;
        }
        $original = $buffer;

        // (2.6.0) Opt-in pipeline instrumentation. Enable with
        // define( 'EASYOPT_PROFILE_BUFFER', true ) in wp-config.php, then read
        // the plugin's debug log. Measures the real cost of the multi-pass
        // HTML rewrite on YOUR heaviest pages, which is the number that
        // decides whether migrating to WP_HTML_Tag_Processor is worth the
        // regression risk: a pipeline costing single-digit milliseconds does
        // not justify an architectural rewrite, and one costing 150ms with a
        // large memory spike clearly does. Zero cost when the constant is
        // absent — two function calls guarded by one defined() check.
        $easyopt_profile    = defined( 'EASYOPT_PROFILE_BUFFER' ) && EASYOPT_PROFILE_BUFFER;
        $easyopt_prof_start = $easyopt_profile ? microtime( true ) : 0.0;
        $easyopt_prof_mem   = $easyopt_profile ? memory_get_peak_usage( true ) : 0;

        try {
            // Per-request master debug switch: ?nooptimize returns the raw,
            // unmodified buffer (and the request is never cached because it
            // carries a query parameter).
            if ( easyopt_debug_switch( 'nooptimize' ) ) {
                return $buffer;
            }

            // Only transform genuine, COMPLETE HTML documents. Non-HTML
            // responses (JSON/XML/feeds), AJAX fragments, and pages that were
            // truncated by another component flushing the buffer stack lack a
            // closing </html>; leaving those untouched means we can never
            // corrupt or blank output that isn't a full page.
            $is_full_html = ( false !== stripos( $buffer, '<html' ) )
                         && ( false !== stripos( $buffer, '</html>' ) );

            if ( $is_full_html ) {
                // (2.5.2) Very large live-rendered pages (filtered archives,
                // huge inline scripts) can exceed the default PCRE backtrack
                // limit (~1M), making preg_replace* return NULL mid-module.
                // Raise it once per request, WP Rocket-style, so the passes
                // succeed instead of being discarded by validated_buffer().
                if ( (int) ini_get( 'pcre.backtrack_limit' ) < 5000000 ) {
                    if ( function_exists( 'ini_set' ) ) { @ini_set( 'pcre.backtrack_limit', '5000000' ); }
                }
                // Minify local CSS/JS first so downstream passes (Unused CSS,
                // Delay JS) operate on the minified asset URLs.
                $buffer = $this->run_processor( 'EasyOpt_Minify', $buffer );

                // CDN rewrite (instance, not static).
                if ( $this->cdn ) {
                    $buffer = $this->run_cdn( $buffer );
                }

                // LCP preload → before lazy-load so it skips our high-priority images.
                $buffer = $this->run_processor( 'EasyOpt_LCP',           $buffer );
                $buffer = $this->run_processor( 'EasyOpt_LazyLoad',      $buffer );
                $buffer = $this->run_processor( 'EasyOpt_Images_Delivery', $buffer );
                $buffer = $this->run_processor( 'EasyOpt_Unused_CSS',    $buffer );
                $buffer = $this->run_processor( 'EasyOpt_Fonts',         $buffer );
                $buffer = $this->run_processor( 'EasyOpt_Accessibility', $buffer );
                $buffer = $this->run_processor( 'EasyOpt_SEO',           $buffer );
                $buffer = $this->run_processor( 'EasyOpt_Delay_JS',      $buffer );
                $buffer = $this->run_processor( 'EasyOpt_Defer_JS',      $buffer );
                // Resource hints LAST: it scans the finished document, so it
                // sees origins introduced by the CDN rewrite, Used CSS font
                // preloads and the LCP preload rather than the raw markup.
                // LAST. Minify and Unused CSS above GENERATE files; a URL that
                // does not exist yet cannot be rewritten to the CDN.
                $buffer = $this->run_processor( 'EasyOpt_CDN_Assets',   $buffer );
                $buffer = $this->run_processor( 'EasyOpt_Preconnect',    $buffer );

                // Runtime shim LAST — after Delay JS, so it is never
                // rewritten into a delayed script, and after the lazy pass,
                // which is what decides whether it is needed at all.
                $buffer = $this->maybe_inject_lazy_runtime( $buffer );
            }

            // Page cache — final step. Writes the fully-processed HTML to disk
            // and returns it unchanged. Has its own internal gates, so it's
            // safe to call for any buffer (it bails on non-pages).
            if ( class_exists( 'EasyOpt_Cache' ) ) {
                $result = EasyOpt_Cache::capture_buffer( $buffer );
                if ( is_string( $result ) && '' !== $result ) {
                    $buffer = $result;
                }
            }
        } catch ( \Throwable $e ) {
            // Anything unexpected → serve the page exactly as we received it.
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::error( 'buffer', 'Output processing failed; serving unmodified page: ' . $e->getMessage() );
            }
            return $original;
        }

        // (2.6.0) Emit the pipeline measurement. One log line per buffered
        // request while the constant is defined; nothing at all otherwise.
        if ( $easyopt_profile && class_exists( 'EasyOpt_Debug_Log' ) ) {
            EasyOpt_Debug_Log::warn( 'profile', sprintf(
                '[buffer] %.1fms, peak +%.1fMB, doc %dKB -> %dKB, url=%s',
                ( microtime( true ) - $easyopt_prof_start ) * 1000,
                ( memory_get_peak_usage( true ) - $easyopt_prof_mem ) / 1048576,
                strlen( $original ) / 1024,
                ( is_string( $buffer ) ? strlen( $buffer ) : 0 ) / 1024,
                isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''
            ) );
            $easyopt_breakdown = self::profile_summary();
            if ( '' !== $easyopt_breakdown ) {
                EasyOpt_Debug_Log::warn( 'profile', '[modules] ' . $easyopt_breakdown );
            }
        }

        // Final contract check: never hand back empty for non-empty input.
        if ( ! is_string( $buffer ) || '' === $buffer ) {
            return $original;
        }

        return $buffer;
    }

    /**
     * Run the optimization passes on an HTML string WITHOUT caching it.
     *
     * Identical pipeline to master_output_processor(), minus the final cache
     * write. Used by the preloader to optimize HTML it fetched over HTTP, for
     * environments where the in-process output buffer can't be used (the
     * buffer is rewritten/cleaned by the theme or host). The preloader writes
     * the result to the page cache itself via
     * EasyOpt_Cache::store_prefetched_html().
     *
     * Same absolute-safety contract as master_output_processor(): never
     * throws, never returns empty/non-string for non-empty input. On any
     * failure it returns the input verbatim, so a processing error degrades to
     * caching the page unoptimized rather than corrupting it.
     *
     * @param string $buffer Full HTML document to optimize.
     * @return string Optimized HTML, or the input unchanged on any failure.
     */
    public function run_buffer_processors( $buffer ) {
        if ( ! is_string( $buffer ) || '' === $buffer ) {
            return $buffer;
        }
        $original = $buffer;

        try {
            $is_full_html = ( false !== stripos( $buffer, '<html' ) )
                         && ( false !== stripos( $buffer, '</html>' ) );

            if ( $is_full_html ) {
                // (2.5.2) Very large live-rendered pages (filtered archives,
                // huge inline scripts) can exceed the default PCRE backtrack
                // limit (~1M), making preg_replace* return NULL mid-module.
                // Raise it once per request, WP Rocket-style, so the passes
                // succeed instead of being discarded by validated_buffer().
                if ( (int) ini_get( 'pcre.backtrack_limit' ) < 5000000 ) {
                    if ( function_exists( 'ini_set' ) ) { @ini_set( 'pcre.backtrack_limit', '5000000' ); }
                }
                $buffer = $this->run_processor( 'EasyOpt_Minify', $buffer );

                if ( $this->cdn ) {
                    $buffer = $this->run_cdn( $buffer );
                }

                $buffer = $this->run_processor( 'EasyOpt_LCP',           $buffer );
                $buffer = $this->run_processor( 'EasyOpt_LazyLoad',      $buffer );
                $buffer = $this->run_processor( 'EasyOpt_Images_Delivery', $buffer );
                $buffer = $this->run_processor( 'EasyOpt_Unused_CSS',    $buffer );
                $buffer = $this->run_processor( 'EasyOpt_Fonts',         $buffer );
                $buffer = $this->run_processor( 'EasyOpt_Accessibility', $buffer );
                $buffer = $this->run_processor( 'EasyOpt_SEO',           $buffer );
                $buffer = $this->run_processor( 'EasyOpt_Delay_JS',      $buffer );
                $buffer = $this->run_processor( 'EasyOpt_Defer_JS',      $buffer );
                // Resource hints LAST: it scans the finished document, so it
                // sees origins introduced by the CDN rewrite, Used CSS font
                // preloads and the LCP preload rather than the raw markup.
                // LAST. Minify and Unused CSS above GENERATE files; a URL that
                // does not exist yet cannot be rewritten to the CDN.
                $buffer = $this->run_processor( 'EasyOpt_CDN_Assets',   $buffer );
                $buffer = $this->run_processor( 'EasyOpt_Preconnect',    $buffer );

                // Runtime shim LAST — after Delay JS, so it is never
                // rewritten into a delayed script, and after the lazy pass,
                // which is what decides whether it is needed at all.
                $buffer = $this->maybe_inject_lazy_runtime( $buffer );
            }
        } catch ( \Throwable $e ) {
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::error( 'preload-opt', 'Prefetch optimization failed; caching unoptimized: ' . $e->getMessage() );
            }
            return $original;
        }

        return ( is_string( $buffer ) && '' !== $buffer ) ? $buffer : $original;
    }

    /**
     * Run one buffer processor defensively. If the class/method is missing,
     * the call throws, or it returns anything other than a non-empty string,
     * the input is returned unchanged. Guarantees a single processor can never
     * blank or break the page.
     *
     * @param string $class  Processor class name (static process_buffer()).
     * @param string $buffer Current buffer.
     * @return string
     */
    /** @var array<string,array{ms:float,delta:int}> Per-module profile accumulator. */
    private static $profile = array();

    /**
     * Record one module's cost. Only called when EASYOPT_PROFILE_BUFFER is on.
     *
     * @param string $class  Module class name.
     * @param float  $start  microtime(true) before the pass.
     * @param int    $before strlen() before the pass.
     * @param string $after  Buffer after the pass.
     */
    private static function profile_module( $class, $start, $before, $after ) {
        $short = str_replace( 'EasyOpt_', '', $class );
        if ( ! isset( self::$profile[ $short ] ) ) {
            self::$profile[ $short ] = array( 'ms' => 0.0, 'delta' => 0 );
        }
        self::$profile[ $short ]['ms']    += ( microtime( true ) - $start ) * 1000;
        self::$profile[ $short ]['delta'] += ( is_string( $after ) ? strlen( $after ) : $before ) - $before;
    }

    /** Formatted per-module breakdown, slowest first. */
    private static function profile_summary() {
        if ( empty( self::$profile ) ) {
            return '';
        }
        uasort( self::$profile, function ( $a, $b ) {
            return ( $b['ms'] < $a['ms'] ) ? -1 : ( ( $b['ms'] > $a['ms'] ) ? 1 : 0 );
        } );
        $parts = array();
        foreach ( self::$profile as $name => $d ) {
            $parts[] = sprintf( '%s %.1fms/%+dKB', $name, $d['ms'], (int) ( $d['delta'] / 1024 ) );
        }
        return implode( ' | ', $parts );
    }

    private function run_processor( $class, $buffer ) {
        // (2.5.7) autoload = FALSE. class_exists() defaults to autoloading, so
        // asking each of the ten processors whether it exists made the
        // classmap autoloader LOAD every disabled module — roughly 308 KB of
        // PHP per buffered request — purely to discover it had nothing to do.
        // That is precisely what the conditional require ladder above exists
        // to prevent, so the ladder was a no-op for front-end page views.
        // Any module reaching the pipeline only via autoload must have a
        // ladder entry; Preconnect was the sole such case and now has one.
        if ( ! class_exists( $class, false ) || ! method_exists( $class, 'process_buffer' ) ) {
            return $buffer;
        }
        // (2.6.0) Per-module timing, so a slow pipeline can be attributed to
        // the module actually responsible instead of guessed at. Same constant
        // as the whole-pipeline measurement; zero cost when it is absent.
        $easyopt_prof   = defined( 'EASYOPT_PROFILE_BUFFER' ) && EASYOPT_PROFILE_BUFFER;
        $easyopt_t0     = $easyopt_prof ? microtime( true ) : 0.0;
        $easyopt_len0   = $easyopt_prof ? strlen( $buffer ) : 0;

        try {
            $out = $class::process_buffer( $buffer );
        } catch ( \Throwable $e ) {
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::warn( 'buffer', $class . ' skipped (error): ' . $e->getMessage() );
            }
            return $buffer;
        }

        $result = $this->validated_buffer( $out, $buffer, $class );

        if ( $easyopt_prof ) {
            self::profile_module( $class, $easyopt_t0, $easyopt_len0, $result );
        }

        return $result;
    }

    /**
     * (2.5.2) Validate a processor's output against the input it was given.
     *
     * The empty/non-string guard alone cannot catch every corruption: when
     * PCRE aborts mid-module (backtrack limit on very large live-rendered
     * pages), preg_replace* returns NULL, and a module that keeps working on
     * that null can still emit a small NON-empty string (e.g. Delay JS
     * appending its loader tag to a nulled page → the whole response is one
     * <script> tag). Precise, zero-false-positive test: no module ever
     * removes the document skeleton, so if the input contained </html> the
     * output must too. Anything else → the module's pass is discarded and
     * the page continues unoptimized-by-that-module (fail open, never blank).
     *
     * @param mixed  $out    Processor return value.
     * @param string $buffer Input buffer (known non-empty string).
     * @param string $who    Module name for the log line.
     * @return string
     */
    private function validated_buffer( $out, $buffer, $who ) {
        if ( ! is_string( $out ) || '' === $out ) {
            return $buffer;
        }
        if ( false !== stripos( $buffer, '</html>' )
             && false === stripos( $out, '</html>' ) ) {
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                $pcre = function_exists( 'preg_last_error_msg' ) ? preg_last_error_msg() : (string) preg_last_error();
                EasyOpt_Debug_Log::warn(
                    'buffer',
                    $who . ' output lost the document skeleton (likely PCRE failure: ' . $pcre . ') — pass discarded, page served unmodified by this module.'
                );
            }
            return $buffer;
        }
        return $out;
    }

    /**
     * CDN rewrite, guarded like run_processor() (it's an instance method, so
     * it can't go through the static helper above).
     *
     * @param string $buffer Current buffer.
     * @return string
     */
    private function run_cdn( $buffer ) {
        try {
            $out = $this->cdn->modify_content_with_cdn( $buffer );
        } catch ( \Throwable $e ) {
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::warn( 'buffer', 'CDN rewrite skipped (error): ' . $e->getMessage() );
            }
            return $buffer;
        }
        return $this->validated_buffer( $out, $buffer, 'CDN rewrite' );
    }
}

// Boot plugin
Easy_Optimizer::instance();

/**
 * Activation hook — lightweight, non-blocking.
 * Creates tables, sets pending flag, and schedules finalize cron.
 */
register_activation_hook( EASYOPT_PLUGIN_FILE, function () {
    if ( class_exists( 'EasyOpt_LCP' ) ) {
        EasyOpt_LCP::maybe_install_table();
    }
    // idempotent so re-activation is cheap. The table is needed before
    // any preload start() call can succeed.
    if ( class_exists( 'EasyOpt_Queue' ) ) {
        EasyOpt_Queue::maybe_create_table();
    }
    // (2.5.0) Preload results ledger table — same idempotent create.
    if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
        EasyOpt_Preload_Results::maybe_create_table();
    }

    // First-run setup: on a brand-new install, seed an all-off baseline so
    // nothing is enabled until the user picks a preset, and flag the
    // one-time redirect to the setup wizard. Existing installs are detected
    // and skipped (their settings are preserved). MUST run before the
    // preload check below so a fresh install doesn't schedule a preload.
    if ( class_exists( 'EasyOpt_Wizard' ) ) {
        EasyOpt_Wizard::seed_fresh_install();
    }

    // (2.6.0) Fallback secret for authenticating preload loopbacks, used only
    // when wp-config defines no salt constants. Generated once and kept for
    // the life of the install so warm URLs stay verifiable across requests.
    if ( '' === (string) get_option( 'easyopt_preload_secret', '' ) ) {
        add_option( 'easyopt_preload_secret', wp_generate_password( 64, false, false ), '', false );
    }

    // Mark that we need to reconcile filesystem state on the next request.
    update_option( 'easyopt_pending_install', 1, false );
    // One-shot cron pickup in case the user navigates away and never opens
    // wp-admin. 5s gives the activation request itself room to finish.
    if ( ! wp_next_scheduled( 'easyopt_finalize_install' ) ) {
        wp_schedule_single_event( time() + 5, 'easyopt_finalize_install' );
    }
    // Preload was on before? Schedule a preload start after 30s so
    // the activation request finishes cleanly first.
    if ( class_exists( 'EasyOpt_Cache_Preload' ) && (int) EasyOpt_Config::get( 'cache_preload', 0 ) ) {
        if ( ! wp_next_scheduled( 'easyopt_delayed_preload_start' ) ) {
            wp_schedule_single_event( time() + 30, 'easyopt_delayed_preload_start' );
        }
    }
    // Re-install the object cache drop-in if it was enabled before (no-op on a
    // fresh install, where Object Cache defaults to off).
    if ( class_exists( 'EasyOpt_Object_Cache_Manager' ) && EasyOpt_Object_Cache_Manager::is_enabled() ) {
        EasyOpt_Object_Cache_Manager::install();
    }
} );

/**
 * Finalize install. Idempotent — safe to call repeatedly.
 * Installs drop-in, writes htaccess, creates tables.
 */
function easyopt_finalize_install() {

    // Snapshot the previously-installed version BEFORE anything below changes
    // it. Used both for the re-entrancy guard and to tell a genuine UPDATE
    // apart from a fresh install / same-version reconcile.
    $stored = (string) get_option( 'easyopt_installed_version', '' );

    // Early-out for a same-version reconcile when there's no pending flag.
    // The heavy reconcile below (htaccess, drop-in install, version stamp) is
    // idempotent, so a cron-vs-admin_init overlap is harmless here. The one
    // NON-idempotent step — the preload restart — is atomically claimed
    // further down via add_option(), so it can never double-fire.
    if ( ! get_option( 'easyopt_pending_install' ) && $stored === EASYOPT_VERSION ) {
        return;
    }
    delete_option( 'easyopt_pending_install' );

    // A real version UPDATE = a non-empty previous version that differs from
    // the one shipping now. Fresh installs ($stored === '') and same-version
    // reconciles are deliberately excluded, so the clear+preload below fires
    // ONLY on update.
    $is_update = ( '' !== $stored && $stored !== EASYOPT_VERSION );

    // (2.6.2) These three touch the filesystem and the host's PHP environment
    // — the one part of a WordPress install we cannot fully probe from here.
    // They run on `admin_init`, so a fatal thrown inside any of them locks the
    // user out of wp-admin entirely, including the Plugins screen they would
    // need in order to deactivate us. Contain it and log instead.
    try {
        if ( class_exists( 'EasyOpt_LCP' ) ) {
            EasyOpt_LCP::maybe_install_table();
        }
        if ( class_exists( 'EasyOpt_Cache' ) && EasyOpt_Cache::is_enabled() ) {
            EasyOpt_Cache::write_htaccess();
        }
        if ( class_exists( 'EasyOpt_Advanced_Cache' ) ) {
            EasyOpt_Advanced_Cache::install();
        }
    } catch ( \Throwable $e ) {
        if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
            EasyOpt_Debug_Log::error(
                'install',
                'Install step failed: ' . $e->getMessage()
                    . ' @ ' . $e->getFile() . ':' . $e->getLine()
            );
        }
    }

    // Clean up legacy autoloaded options. These were
    // written by the 1.5.3-and-older preloader and per-cache-MISS counter;
    // they're no longer maintained. Leaving them in wp_options carries
    // forward the autoload bloat that the rewrite was meant to eliminate.
    delete_option( 'easyopt_cache_pages_count' );
    delete_option( 'easyopt_cache_preload_last_avg_ms' );
    delete_option( 'easyopt_cache_preload_paused_reason' );

    // Stamp the new version BEFORE the clear/preload below so any racing
    // pickup sees the updated value and won't re-enter the update path.
    update_option( 'easyopt_installed_version', EASYOPT_VERSION, false );

    // (EO-06) Remove the previous version's one-shot preload-restart marker so
    // the per-version claim keys (added below) don't accumulate in wp_options.
    if ( '' !== $stored ) {
        delete_option( 'easyopt_preload_restarted_' . $stored );
    }

    // (2.6.7) Prefetch Pages settings migration.
    //
    // The 2.6.6 engine was a JavaScript request queue; 2.6.7 hands scheduling
    // to the browser, so the two knobs that configured that queue no longer
    // describe anything. Delete them rather than leave rows the UI cannot show
    // and the code never reads. `immediate` eagerness is folded to `eager`:
    // on a document-level speculation rule it means "fetch every link on this
    // page now", which WordPress Core forbids for the same reason.
    //
    // Runs on every version pickup (not only $is_update) so an install that
    // upgraded while this code path was unreachable still converges. Writes
    // only when there is something to change.
    if ( class_exists( 'EasyOpt_Config' ) ) {
        $easyopt_nav_stored = get_option( 'easyopt_settings', array() );
        if ( is_array( $easyopt_nav_stored ) ) {
            $easyopt_nav_dirty = false;
            foreach ( array( 'easyopt_instant_throttle', 'easyopt_instant_limit' ) as $easyopt_dead_key ) {
                if ( array_key_exists( $easyopt_dead_key, $easyopt_nav_stored ) ) {
                    unset( $easyopt_nav_stored[ $easyopt_dead_key ] );
                    $easyopt_nav_dirty = true;
                }
            }
            if ( isset( $easyopt_nav_stored['easyopt_instant_eagerness'] )
                && 'immediate' === $easyopt_nav_stored['easyopt_instant_eagerness'] ) {
                $easyopt_nav_stored['easyopt_instant_eagerness'] = 'eager';
                $easyopt_nav_dirty = true;
            }
            // Prerender moves to opt-in. 2.6.6 defaulted it ON, so every install
            // that ever saved settings carries a stored 1 — changing only the
            // schema default would leave those installs untouched and make the
            // new default meaningless. Measurement found it never completing in
            // the time a pointerdown allows (the prefetch served every
            // navigation instead), so nobody loses a measured benefit here, and
            // the toggle turns it straight back on.
            if ( ! empty( $easyopt_nav_stored['easyopt_instant_prerender'] ) ) {
                $easyopt_nav_stored['easyopt_instant_prerender'] = 0;
                $easyopt_nav_dirty = true;
            }
            if ( $easyopt_nav_dirty ) {
                update_option( 'easyopt_settings', $easyopt_nav_stored );
                EasyOpt_Config::invalidate();
            }
        }
        unset( $easyopt_nav_stored, $easyopt_nav_dirty, $easyopt_dead_key );
    }

    // (2.4.9) Clear a stale skip-live-buffer flag on update. Older builds could
    // mis-set this on hosts with a server-level cache: the preloader's loopback
    // self-test was answered from that cache before PHP ran, which looked like a
    // broken output buffer — so on-visit caching was switched off and only the
    // preloader ever warmed the cache. The detection now trips solely on a
    // genuine blank page, so clear the flag and let it re-evaluate from a clean
    // state. On-visit caching (a real visit warming its own page) is restored
    // immediately; a truly hostile theme will re-set the flag on the next
    // preload loopback.
    if ( $is_update ) {
        delete_option( 'easyopt_skip_live_buffer' );
    }

    // (2.5.0 / A1+A2) Used-CSS refresh on update — surgical, two changes:
    //
    //  A1: NEVER wipe the collected-fonts sidecars (*.eo-fonts.json) here.
    //      They are beacon-derived SITE data (above-the-fold font sets per
    //      URL type + viewport), not version-dependent artifacts — the
    //      strip/preload decision is applied at injection time from the
    //      always-full .used.css, so a plugin update never invalidates
    //      them. The old clear_all_used_css() call deleted them on every
    //      update, silently disabling Lazyload/Preload Fonts until the
    //      beacon re-collected. clear_used_css_only() preserves them,
    //      exactly like the *.eo-classes.json sidecars always were.
    //      Fonts are still wiped where they CAN change: theme switch,
    //      Customizer save, and the explicit "Clear fonts data" button.
    //
    //  A2: Only wipe the Used CSS at all when the CSS *generation logic*
    //      actually changed since the install's last stamp (the
    //      EASYOPT_CSS_LOGIC_VERSION marker), so routine bug-fix releases
    //      don't force a full-site RUCSS regeneration burst.
    $easyopt_css_logic = defined( 'EASYOPT_CSS_LOGIC_VERSION' ) ? EASYOPT_CSS_LOGIC_VERSION : EASYOPT_VERSION;
    if ( $is_update
        && (string) get_option( 'easyopt_css_logic_version', '' ) !== $easyopt_css_logic
        && class_exists( 'EasyOpt_Unused_CSS' )
        && method_exists( 'EasyOpt_Unused_CSS', 'clear_used_css_only' ) ) {
        EasyOpt_Unused_CSS::clear_used_css_only();
    }
    update_option( 'easyopt_css_logic_version', $easyopt_css_logic, false );

    // ── On update only: clear the page cache and re-warm it. ──
    // The cache is cleared so stale HTML rendered by the previous version is
    // never served, and the preloader is restarted so the site re-caches in
    // the background instead of forcing synchronous renders onto live
    // visitors. clear_all() performs the wipe and, because preload is on,
    // restarts the crawl itself (fast, non-blocking) — nothing extra to
    // schedule. The whole block is gated on the preload toggle, so a user who
    // has preload disabled is left completely untouched (no clear, no warm).
    // No optimization behaviour changes here — this is cache lifecycle only.
    if ( $is_update
        && class_exists( 'EasyOpt_Cache' )
        && class_exists( 'EasyOpt_Cache_Preload' )
        && (int) EasyOpt_Config::get( 'cache_preload', 0 )
        // (EO-06) Atomic one-shot claim for the ONLY non-idempotent step here:
        // clear_all() restarts the preloader (start() is documented as not
        // idempotent). add_option() is atomic on the option_name UNIQUE key, so
        // if the cron event and the admin_init pickup race, exactly one wins the
        // claim and the other skips — no double preload crawl. Keyed per version
        // (the prior version's key is removed at the stamp above), autoload off.
        && add_option( 'easyopt_preload_restarted_' . EASYOPT_VERSION, time(), '', false ) ) {
        EasyOpt_Cache::clear_all();
    }
}
add_action( 'easyopt_finalize_install', 'easyopt_finalize_install' );

// ── Same-version re-upload / upload-replace pickup (2.5.0) ─────────────────
// WordPress does NOT fire activation/deactivation hooks on "Upload Plugin →
// Replace current with uploaded", and finalize_install()'s restart block is
// gated on a VERSION CHANGE plus a one-shot claim keyed per version string.
// Net effect before this handler: re-uploading a build with the SAME version
// number changed the files but restarted nothing — the dashboard showed the
// previous (finished) run, "URLs in waiting: 0", and preload never started.
//
// upgrader_process_complete is the one hook that fires on EVERY plugin
// install/update path, including zip overwrite. When OUR plugin is the one
// that was just written:
//  - flag pending_install so finalize_install() reconciles drop-ins/tables
//    on the next admin_init even though the version string didn't change;
//  - if the freshly-written files carry the SAME version as the stored one
//    (a re-upload), schedule the delayed preload start ourselves — for a
//    REAL version change we deliberately do nothing more, because
//    finalize_install() → clear_all() already restarts the crawl and a
//    second start 30s later would wastefully reset it.
// The +30s delay lets the upgrade request finish; by the time the event
// fires, the NEW files are what execute. (Note: this handler runs from the
// build that ships it onward — the upgrade request itself still executes the
// previously installed code.)
add_action( 'upgrader_process_complete', function ( $upgrader, $hook_extra ) {
    if ( ! is_array( $hook_extra ) || 'plugin' !== ( $hook_extra['type'] ?? '' ) ) {
        return;
    }
    $ours = false;
    // Update flow: our basename listed in the affected plugins.
    if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
        $ours = in_array( plugin_basename( EASYOPT_PLUGIN_FILE ), $hook_extra['plugins'], true );
    }
    // Install/overwrite flow: destination folder is our slug.
    if ( ! $ours && is_object( $upgrader ) && ! empty( $upgrader->result['destination_name'] ) ) {
        $ours = ( 'easy-optimizer' === $upgrader->result['destination_name'] );
    }
    if ( ! $ours ) {
        return;
    }

    // Reconcile on next admin_init regardless of version string.
    update_option( 'easyopt_pending_install', 1, false );

    // Read the JUST-WRITTEN files' version from disk — this request still
    // runs the OLD code, so EASYOPT_VERSION here is the previous constant.
    $new_version = '';
    $main = WP_PLUGIN_DIR . '/easy-optimizer/easy-optimizer.php';
    if ( function_exists( 'get_plugin_data' ) && is_readable( $main ) ) {
        $data        = get_plugin_data( $main, false, false );
        $new_version = isset( $data['Version'] ) ? (string) $data['Version'] : '';
    }
    $stored = (string) get_option( 'easyopt_installed_version', '' );

    if ( '' !== $new_version && $new_version !== $stored ) {
        return; // Real version change → finalize_install() restarts the crawl.
    }

    // Same-version re-upload: release the one-shot claim so a future
    // finalize pass isn't blocked, and schedule the fresh crawl.
    delete_option( 'easyopt_preload_restarted_' . $stored );
    if ( class_exists( 'EasyOpt_Cache_Preload' )
        && (int) EasyOpt_Config::get( 'cache_preload', 0 )
        && ! wp_next_scheduled( 'easyopt_delayed_preload_start' ) ) {
        wp_schedule_single_event( time() + 30, 'easyopt_delayed_preload_start' );
    }
}, 10, 2 );

/**
 * One-time migration: the warning log previously defaulted ON and may be
 * persisted as 1 on existing installs. It's noisy and only useful while
 * actively debugging, so this update turns it OFF once. The migration is
 * gated by its own flag, so if the user re-enables warnings later in Settings
 * that choice persists and is never overridden again. Errors still log.
 */
add_action( 'admin_init', function () {
    if ( get_option( 'easyopt_logwarn_off_migrated' ) ) {
        return;
    }
    if ( class_exists( 'EasyOpt_Config' ) && 1 === (int) EasyOpt_Config::get( 'log_warnings', 0 ) ) {
        EasyOpt_Config::set( 'log_warnings', 0 );
    }
    update_option( 'easyopt_logwarn_off_migrated', 1, true );
} );

/**
 * Admin-pageview pickup. Runs on every admin page load (cheap option read);
 * only does work if there's a pending install OR the version changed.
 */
add_action( 'admin_init', function () {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    $pending = (int) get_option( 'easyopt_pending_install', 0 );
    $stored  = (string) get_option( 'easyopt_installed_version', '' );
    if ( ! $pending && $stored === EASYOPT_VERSION ) {
        return;
    }
    easyopt_finalize_install();
}, 5 );

// Plugin deactivation — clean up all on-disk + cron state.
register_deactivation_hook( EASYOPT_PLUGIN_FILE, function () {
    // Drop-in must come off before WP_CACHE → otherwise wp-settings.php
    // will throw on next request looking for the file.
    if ( class_exists( 'EasyOpt_Advanced_Cache' ) ) {
        EasyOpt_Advanced_Cache::uninstall();
    }
    // Object cache drop-in — remove ours (marker-guarded). WordPress loads
    // object-cache.php only if present, so removing it is safe immediately and
    // a foreign drop-in is never touched.
    if ( class_exists( 'EasyOpt_Object_Cache_Manager' ) ) {
        EasyOpt_Object_Cache_Manager::uninstall();
    }
    if ( class_exists( 'EasyOpt_Cache' ) ) {
        EasyOpt_Cache::remove_htaccess();
        EasyOpt_Cache::clear_all();
    }
    if ( class_exists( 'EasyOpt_Cache_Preload' ) ) {
        EasyOpt_Cache_Preload::stop();
    }
    // is preserved (in case the user reactivates) and will be GC'd on
    // uninstall via the `easyopt_uninstall` action. Table is small
    // when idle and shared across all queue-using modules.
    if ( class_exists( 'EasyOpt_Queue' ) ) {
        EasyOpt_Queue::clear_watchdog();
        EasyOpt_Queue::clear_gc();
    }
    if ( class_exists( 'EasyOpt_Database' ) ) {
        EasyOpt_Database::stop_cron();
    }
    // Clear one-shot bootstrap events that may still be pending if the plugin
    // is deactivated shortly after activation. Left scheduled, they fire into
    // nothing (the classes are gone) and linger in the cron array.
    wp_clear_scheduled_hook( 'easyopt_finalize_install' );
    wp_clear_scheduled_hook( 'easyopt_delayed_preload_start' );
} );



// ── Review Request System ──────────────────────────────────────────────
// Shows a non-intrusive admin notice on the Easy Optimizer settings page
// after 3+ days of activation. Dismissible per-user.
// FluxCDN: Elementor background images — CDN URLs injected at CSS generation
// time; Elementor maintains them across edits (no file mutation).
add_filter( 'elementor/files/css/selectors', array( 'EasyOpt_CDN', 'elementor_selectors_filter' ), 10, 2 );

// FluxCDN: refresh license status when an admin loads wp-admin, so a revoked
// license flips the panel to disconnected within one admin page load (not only
// when the Image Optimization tab's usage call runs).
// (2.5.4 / perf #4) The maintenance chain below used to run INLINE in
// admin_init: usage_status(true) is a blocking HTTP call to the licensing
// service, reprobe/retry are more remote calls, and a transform-signature
// change triggered a synchronous remote purge + full local cache clear —
// all inside the admin page the user was waiting on, every 10 minutes.
// admin_init now only schedules a single cron event (one transient read +
// at most one schedule write per window); the chain itself runs detached.
// Same work, same 10-minute cadence, none of the admin TTFB.
add_action( 'admin_init', function () {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    if ( '' === (string) EasyOpt_Config::get( 'fluxcdn_license_key', '' ) ) { return; }
    if ( get_transient( 'easyopt_fluxcdn_admin_lic_check' ) ) { return; }
    set_transient( 'easyopt_fluxcdn_admin_lic_check', 1, 10 * MINUTE_IN_SECONDS );
    if ( ! wp_next_scheduled( 'easyopt_fluxcdn_admin_maintenance' ) ) {
        wp_schedule_single_event( time() - 1, 'easyopt_fluxcdn_admin_maintenance' );
        if ( function_exists( 'spawn_cron' ) ) {
            spawn_cron(); // non-blocking 0.01s loopback — fires the event now
        }
    }
} );

// (2.5.4 / perf #4) Detached handler for the chain above.
add_action( 'easyopt_fluxcdn_admin_maintenance', function () {
    if ( ! class_exists( 'EasyOpt_CDN' ) ) { return; }
    if ( '' === (string) EasyOpt_Config::get( 'fluxcdn_license_key', '' ) ) { return; }
    EasyOpt_CDN::usage_status( true );
    // (2.5.3) While the delivery gate is closed (SSL still provisioning or
    // origin blocked), re-probe on every throttled admin visit too — a
    // failed one-shot cron must never leave a paying customer stuck at
    // "warming up" forever.
    EasyOpt_CDN::reprobe();
    // M2: retry any activation releases that failed on disconnect/rollback.
    EasyOpt_CDN::retry_pending_releases();
    // M9: transform settings (quality/format/max-width) changed → every CDN
    // URL changes. Purge the zone so stale variants don't double the cache
    // footprint, and clear the local page cache so pages re-render.
    $sig = EasyOpt_CDN::transform_signature();
    if ( $sig !== (string) EasyOpt_Config::get( 'fluxcdn_transform_sig', '' ) ) {
        EasyOpt_Config::set( 'easyopt_fluxcdn_transform_sig', $sig );
        EasyOpt_CDN::remote_purge();
        if ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'clear_all' ) ) {
            EasyOpt_Cache::clear_all();
        }
    }
} );

// FluxCDN (H4): the license is bound to the site URL it was activated for.
// After a migration/clone the URLs no longer match — rewriting is already
// stopped by is_connected(); this notice tells the admin how to fix it.
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'EasyOpt_CDN' ) ) { return; }
    $fp = (string) EasyOpt_Config::get( 'fluxcdn_fingerprint', '' );
    if ( '' === $fp || '' === (string) EasyOpt_Config::get( 'fluxcdn_license_key', '' ) ) { return; }
    if ( EasyOpt_CDN::norm_site( $fp ) === EasyOpt_CDN::norm_site( get_site_url() ) ) { return; }
    // Stay quiet on obvious staging/dev copies — nagging there is noise.
    $host = (string) wp_parse_url( get_site_url(), PHP_URL_HOST );
    if ( preg_match( '/(\.local$|^localhost|staging|\.test$|\.dev$)/i', $host )
        || ( function_exists( 'wp_get_environment_type' ) && 'production' !== wp_get_environment_type() ) ) {
        return;
    }
    $fp_stamp = md5( EasyOpt_CDN::norm_site( $fp ) . '|' . EasyOpt_CDN::norm_site( get_site_url() ) );
    if ( EasyOpt_Notices::dismissed( 'fluxcdn_fingerprint', $fp_stamp ) ) { return; }
    printf(
        '<div class="notice notice-warning"><p><strong>%s</strong> %s%s</p></div>',
        esc_html__( 'FluxCDN paused:', 'easy-optimizer' ),
        sprintf(
            /* translators: %s: previously activated site URL */
            esc_html__( 'this license was activated for %s, but the site URL has changed. Open the Smart Images tab and reconnect to resume image delivery.', 'easy-optimizer' ),
            esc_html( $fp )
        ),
        EasyOpt_Notices::link( 'fluxcdn_fingerprint', $fp_stamp ) // phpcs:ignore WordPress.Security.EscapeOutput
    );
} );

// FluxCDN: license-revoked / license-moved warning (set by the usage check).
add_action( 'admin_notices', function () {
    $revoked_stamp = get_option( 'easyopt_fluxcdn_revoked' );
    if ( ! current_user_can( 'manage_options' ) || ! $revoked_stamp ) {
        return;
    }
    // Dismiss persists for THIS revocation; a future re-revocation stamps a
    // new timestamp and the notice returns.
    if ( EasyOpt_Notices::dismissed( 'fluxcdn_revoked', $revoked_stamp ) ) {
        return;
    }
    if ( get_option( 'easyopt_fluxcdn_moved' ) ) {
        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
            esc_html__( 'FluxCDN paused on this site:', 'easy-optimizer' ),
            esc_html__( 'this license is now active on a different website (its seat was moved). Images serve from your own server here. Reconnect from the Smart Images tab to move the license back, or upgrade to a multi-site plan.', 'easy-optimizer' )
            . EasyOpt_Notices::link( 'fluxcdn_revoked', $revoked_stamp ) // phpcs:ignore WordPress.Security.EscapeOutput -- link() escapes internally
        );
        return;
    }
    printf(
        '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
        esc_html__( 'FluxCDN disconnected:', 'easy-optimizer' ),
        esc_html__( 'your license was revoked or the subscription ended. Images are serving from your own server. Renew your subscription and reconnect from the Smart Images tab.', 'easy-optimizer' )
        . EasyOpt_Notices::link( 'fluxcdn_revoked', $revoked_stamp ) // phpcs:ignore WordPress.Security.EscapeOutput -- link() escapes internally
    );
} );

// FluxCDN: global image-error fallback (replaces per-tag onerror).
add_action( 'wp_head', array( 'EasyOpt_CDN', 'print_fallback_script' ), 4 );

// FluxCDN (2.5.3): background SSL/delivery re-probe — flips the emission
// gate to 'ok' once the endpoint provably serves an image over HTTPS.
add_action( 'easyopt_fluxcdn_reprobe', array( 'EasyOpt_CDN', 'reprobe' ) );

add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    // Only show on our settings page.
    $screen = get_current_screen();
    if ( ! $screen || false === strpos( $screen->id, 'easy-optimizer' ) ) {
        return;
    }
    // Already dismissed?
    $user_id = get_current_user_id();
    $dismissed = get_user_meta( $user_id, 'easyopt_review_dismissed', true );
    if ( $dismissed ) {
        return;
    }
    // Must be active for 3+ days.
    $installed = get_option( 'easyopt_first_activated', 0 );
    if ( ! $installed ) {
        update_option( 'easyopt_first_activated', time(), false );
        return;
    }
    if ( ( time() - (int) $installed ) < 3 * DAY_IN_SECONDS ) {
        return;
    }
    // Get cached pages count for the message.
    $pages = 0;
    if ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'get_stats' ) ) {
        $stats = EasyOpt_Cache::get_stats();
        $pages = isset( $stats['pages'] ) ? (int) $stats['pages'] : 0;
    }
    $review_url  = 'https://wordpress.org/support/plugin/easy-optimizer/reviews/#new-post';
    $dismiss_url = wp_nonce_url( add_query_arg( 'easyopt_dismiss_review', '1' ), 'easyopt_dismiss_review' );
    $later_url   = wp_nonce_url( add_query_arg( 'easyopt_dismiss_review', 'later' ), 'easyopt_dismiss_review' );
    ?>
    <div class="notice notice-info is-dismissible" style="padding:12px 15px;">
        <p style="font-size:14px;">
            <?php
            if ( $pages > 0 ) {
                printf(
                    esc_html__( '🎉 Easy Optimizer has cached %s pages on your site!', 'easy-optimizer' ),
                    '<strong>' . number_format_i18n( $pages ) . '</strong>'
                );
                echo '<br>';
            }
            ?>
            <?php esc_html_e( 'If it\'s helping your site speed, would you consider leaving a quick review?', 'easy-optimizer' ); ?>
            <?php esc_html_e( 'It takes 30 seconds and helps other WordPress users find us.', 'easy-optimizer' ); ?>
        </p>
        <p>
            <a href="<?php echo esc_url( $review_url ); ?>" target="_blank" rel="noopener" class="button button-primary" style="margin-right:8px;">⭐ <?php esc_html_e( 'Leave a Review', 'easy-optimizer' ); ?></a>
            <a href="<?php echo esc_url( $later_url ); ?>" class="button" style="margin-right:8px;"><?php esc_html_e( 'Maybe Later', 'easy-optimizer' ); ?></a>
            <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button"><?php esc_html_e( 'Already Did', 'easy-optimizer' ); ?></a>
            <a href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left:8px;text-decoration:none;color:#999;"><?php esc_html_e( 'No Thanks', 'easy-optimizer' ); ?></a>
        </p>
    </div>
    <?php
} );

// Handle review dismissal.
add_action( 'admin_init', function () {
    if ( ! isset( $_GET['easyopt_dismiss_review'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    check_admin_referer( 'easyopt_dismiss_review' );
    $action = sanitize_key( wp_unslash( $_GET['easyopt_dismiss_review'] ) );
    if ( 'later' === $action ) {
        // Push first_activated forward by 14 days so it re-shows later.
        update_option( 'easyopt_first_activated', time() - ( 3 * DAY_IN_SECONDS ) + ( 14 * DAY_IN_SECONDS ), false );
    } else {
        update_user_meta( get_current_user_id(), 'easyopt_review_dismissed', 1 );
    }
    wp_safe_redirect( remove_query_arg( array( 'easyopt_dismiss_review', '_wpnonce' ) ) );
    exit;
} );

// FluxCDN (3.6): the site's paid image subscription ended, so cloud delivery
// disconnected and images now serve from the origin (plus the free on-server
// optimizer). Prompt to reconnect. Cleared automatically on a successful
// reconnect (EasyOpt_REST_Cloud::store_account) or when dismissed here.
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( ! get_option( 'easyopt_cloud_revoked' ) || get_option( 'easyopt_cloud_revoked_dismissed' ) ) {
        return;
    }
    $settings_url = admin_url( 'admin.php?page=easy-optimizer' );
    $dismiss_url  = wp_nonce_url( add_query_arg( 'easyopt_dismiss_cloud_revoked', '1' ), 'easyopt_dismiss_cloud_revoked' );
    ?>
    <div class="notice notice-warning is-dismissible" style="padding:12px 15px;">
        <p style="font-size:14px;">
            <strong><?php esc_html_e( 'Your Cloud optimization subscription has ended.', 'easy-optimizer' ); ?></strong><br>
            <?php esc_html_e( 'Images & files now load from your own server, and the free on-server optimizer is still running — Reconnect a license to resume cloud delivery.', 'easy-optimizer' ); ?>
        </p>
        <p>
            <a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary" style="margin-right:8px;"><?php esc_html_e( 'Reconnect', 'easy-optimizer' ); ?></a>
            <a href="<?php echo esc_url( $dismiss_url ); ?>" class="button"><?php esc_html_e( 'Dismiss', 'easy-optimizer' ); ?></a>
        </p>
    </div>
    <?php
} );

add_action( 'admin_init', function () {
    if ( ! isset( $_GET['easyopt_dismiss_cloud_revoked'] ) ) {
        return;
    }
    check_admin_referer( 'easyopt_dismiss_cloud_revoked' );
    if ( current_user_can( 'manage_options' ) ) {
        update_option( 'easyopt_cloud_revoked_dismissed', 1, false );
    }
    wp_safe_redirect( remove_query_arg( array( 'easyopt_dismiss_cloud_revoked', '_wpnonce' ) ) );
    exit;
} );

// WP-Cron disabled warning — shown only when cache is enabled.
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    // Cache must be on for the warning to be relevant.
    if ( ! class_exists( 'EasyOpt_Config' ) || ! (int) EasyOpt_Config::get( 'cache', 0 ) ) {
        return;
    }
    // Only show when WP-Cron is actually disabled.
    if ( ! defined( 'DISABLE_WP_CRON' ) || true !== DISABLE_WP_CRON ) {
        return;
    }
    // (2.5.3) A real system cron is a fully supported setup — give sites
    // every reasonable way to tell us (or let us detect) that it exists:
    // 1. wp-config constant. 2. Filter. 3. Site-wide acknowledgement.
    // 4. Heartbeat: if any cron request actually ran recently, the system
    //    cron self-evidently works — hide the warning automatically.
    if ( defined( 'EASYOPT_SYSTEM_CRON_CONFIGURED' ) && EASYOPT_SYSTEM_CRON_CONFIGURED ) {
        return;
    }
    if ( apply_filters( 'easyopt_system_cron_configured', false ) ) {
        return;
    }
    if ( 1 === (int) get_option( 'easyopt_system_cron_ack', 0 ) ) {
        return;
    }
    if ( ( time() - (int) get_option( 'easyopt_last_cron_seen', 0 ) ) < 15 * MINUTE_IN_SECONDS ) {
        return;
    }
    // Per-user, per-version dismiss.
    $dismiss_key = 'easyopt_dismissed_cron_notice_' . EASYOPT_VERSION;
    if ( (int) get_user_meta( get_current_user_id(), $dismiss_key, true ) === 1 ) {
        return;
    }
    $dismiss_url = wp_nonce_url(
        add_query_arg( 'easyopt_dismiss', 'cron_notice' ),
        'easyopt_dismiss_cron_notice'
    );
    ?>
    <div class="notice notice-warning is-dismissible" style="position:relative;">
        <p>
            <strong><?php esc_html_e( 'Easy Optimizer:', 'easy-optimizer' ); ?></strong>
            <?php
            echo wp_kses_post( sprintf(
                /* translators: %s: <code>DISABLE_WP_CRON</code> */
                __( 'WP-Cron is disabled (%s is true in your wp-config.php). Cache preload, cache cleanup, and auto-restart-after-clear depend on background jobs that require either WP-Cron to be enabled, or a real system cron hitting %s every minute. Until one of those is in place, preload won\'t progress.', 'easy-optimizer' ),
                '<code>DISABLE_WP_CRON</code>',
                '<code>wp-cron.php</code>'
            ) );
            ?>
        </p>
        <p>
            <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'easyopt_dismiss', 'cron_ack' ), 'easyopt_dismiss_cron_notice' ) ); ?>" style="text-decoration:none;font-weight:600;">
                <?php esc_html_e( 'System cron is configured — hide this notice permanently', 'easy-optimizer' ); ?>
            </a>
            &nbsp;·&nbsp;
            <a href="<?php echo esc_url( $dismiss_url ); ?>" style="text-decoration:none;">
                <?php esc_html_e( 'Dismiss for now', 'easy-optimizer' ); ?>
            </a>
        </p>
    </div>
    <?php
} );

// Persist dismissal of the cron notice ('cron_notice' = per-user/version,
// 'cron_ack' = site-wide "system cron is configured", 2.5.3).
add_action( 'admin_init', function () {
    if ( ! isset( $_GET['easyopt_dismiss'] ) || ! in_array( $_GET['easyopt_dismiss'], array( 'cron_notice', 'cron_ack' ), true ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    check_admin_referer( 'easyopt_dismiss_cron_notice' );
    if ( 'cron_ack' === $_GET['easyopt_dismiss'] ) {
        update_option( 'easyopt_system_cron_ack', 1, false );
    } else {
        update_user_meta( get_current_user_id(), 'easyopt_dismissed_cron_notice_' . EASYOPT_VERSION, 1 );
    }
    wp_safe_redirect( remove_query_arg( array( 'easyopt_dismiss', '_wpnonce' ) ) );
    exit;
} );

// (2.5.3) Cron heartbeat: every genuine cron run (wp-cron.php via system
// cron, OR WP-Cron loopback) stamps easyopt_last_cron_seen. The warning
// notice and the Cron Events tool use it to prove background jobs actually
// run — a system cron that works silences the warning automatically.
add_action( 'init', function () {
    if ( ! wp_doing_cron() ) {
        return;
    }
    if ( ( time() - (int) get_option( 'easyopt_last_cron_seen', 0 ) ) > 55 ) {
        update_option( 'easyopt_last_cron_seen', time(), false );
    }
}, 1 );
