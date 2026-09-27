<?php
/**
 * EasyOpt\Psi\Client — PageSpeed Insights benchmark client (2.4.0).
 *
 * Powers the dashboard's "Before vs after optimization" panel. Two data
 * paths, picked automatically:
 *
 *   1. DEFAULT — FluxPress proxy (api on fluxpress.io). Zero setup for the
 *      user. The site registers once: it generates a verification code,
 *      exposes it briefly on its own REST endpoint, and asks the proxy for
 *      a token; the proxy fetches the endpoint to prove the requester
 *      controls the domain, then issues a token+secret BOUND TO THAT
 *      DOMAIN. Every benchmark request is HMAC-signed and the proxy only
 *      tests URLs on the registered domain — a stolen token is useless.
 *
 *   2. BYO KEY — if the user saves their own Google API key in settings,
 *      the plugin calls Google's PSI API directly and the proxy (and all
 *      its quotas) is out of the picture.
 *
 * "Before" scores are measured live via a short-lived bypass token
 * (?easyopt_bypass=…): that one request skips the capture buffer and the
 * page cache, so PSI sees the genuinely unoptimized page. The token is
 * issued only when a baseline run starts and expires in 10 minutes — it is
 * not a public bypass parameter.
 *
 * Local quota mirror (proxy mode): 3 manual runs/day. The daily auto-scan is
 * own-key only — proxy users refresh only via the manual button. The proxy
 * enforces its own hard caps too.
 *
 * @package EasyOptimizer
 * @since   2.4.0
 */

namespace EasyOpt\Psi;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Client {

    const PROXY_BASE = 'https://fluxpress.io/wp-json/fluxpress/v1';

    const OPT_CREDENTIALS = 'easyopt_psi_credentials'; // token + secret (proxy mode)
    const OPT_RESULTS     = 'easyopt_psi_results';
    const OPT_QUOTA       = 'easyopt_psi_quota';       // {date, manual}
    const OPT_BYPASS      = 'easyopt_psi_bypass';      // {token, expires}
    const OPT_VERIFY      = 'easyopt_psi_verify_code'; // {code, expires}

    const MANUAL_RUNS_PER_DAY = 3;
    const BYPASS_TTL          = 600; // 10 min
    const GOOGLE_API          = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    const JOB_HOOK   = 'easyopt_psi_job';
    const OPT_JOB    = 'easyopt_psi_job_state';
    const OPT_KEYCHK = 'easyopt_psi_key_status';   // {hash, valid, checked}
    const OPT_FIRST  = 'easyopt_psi_first_after';  // once-only auto "after" flag
    const OPT_FIRST_FAILS = 'easyopt_psi_first_after_fails';
    const FIRST_AFTER_MAX_FAILS = 3;

    public static function init() {
        // (2.5.4 / perf #51) Handler for the detached auto-scan event
        // scheduled by the queue GC. Class is always loaded (see bootstrap),
        // so the event can never fire into a missing handler.
        add_action( 'easyopt_psi_auto_scan', array( __CLASS__, 'maybe_auto_scan' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( self::JOB_HOOK, array( __CLASS__, 'run_job' ) );
        // Must be registered on EVERY request. wp-cron fires this event in a
        // later, separate request; when it was only hooked inside
        // on_preset_applied() the event fired into nothing and the one-shot
        // "after" benchmark never ran at all.
        add_action( self::JOB_HOOK . '_first_after', array( __CLASS__, 'run_first_after' ) );
    }

    /**
     * Called after every successful settings save (REST save + preset
     * apply). Captures the baseline ONCE, in the background, when none
     * exists yet. The bypass token serves the unoptimized page regardless
     * of current settings, so a true "before" can be captured at any time
     * — never re-captured automatically (manual Full Benchmark refreshes
     * it at most once per 24 h).
     */
    public static function on_settings_saved() {
        if ( self::has_baseline() || self::job_running() ) {
            return;
        }
        if ( '' === self::own_key() && ! self::is_registered() ) {
            // First contact with the proxy must be a user-initiated action
            // (registration fetches a verify endpoint) — don't auto-register
            // silently on a routine settings save.
            return;
        }
        self::start_job( array( 'before' ), false );
    }

    /**
     * Preset picked. Baseline first (if missing); then, for Balanced /
     * Maximum on a site never benchmarked before, schedule a ONE-TIME
     * "after" run ~15 minutes out so preload has warmed the cache —
     * an immediate test would measure a half-cold site and look bad.
     */
    public static function on_preset_applied( $preset ) {
        self::on_settings_saved();

        if ( ! in_array( (string) $preset, array( 'balanced', 'maximum' ), true ) ) {
            return;
        }
        if ( get_option( self::OPT_FIRST ) ) {
            return;
        }
        if ( '' === self::own_key() && ! self::is_registered() ) {
            return;
        }
        update_option( self::OPT_FIRST, time(), false );
        if ( ! wp_next_scheduled( self::JOB_HOOK . '_first_after' ) ) {
            wp_schedule_single_event( time() + 15 * MINUTE_IN_SECONDS, self::JOB_HOOK . '_first_after' );
        }
    }

    /** Cron: the once-only post-preset "after" run. */
    public static function run_first_after() {
        if ( self::job_running() ) {
            return;
        }

        // (2.5.7) Don't benchmark while preload is still working. The 15-minute
        // delay was chosen so "preload warms the cache first", but nothing
        // verified that — on a large site, or on a small host where preload is
        // already slow, the "after" score measured a partly-cold site WHILE
        // preload competed for the same PHP workers. That is close to the worst
        // possible moment to benchmark, and it produced a misleading number
        // immediately after the user followed the wizard's advice.
        // Re-arm instead, with a hard ceiling so this can never become a
        // permanent cron loop.
        $easyopt_preload_state = (string) get_option( 'easyopt_cache_preload_status', 'idle' );
        if ( in_array( $easyopt_preload_state, array( 'running', 'paused' ), true ) ) {
            $tries = (int) get_option( 'easyopt_psi_after_retries', 0 );
            if ( $tries < 8 ) { // 8 x 15 min = 2 h ceiling
                update_option( 'easyopt_psi_after_retries', $tries + 1, false );
                wp_schedule_single_event(
                    time() + 15 * MINUTE_IN_SECONDS,
                    self::JOB_HOOK . '_first_after'
                );
                return;
            }
        }

        delete_option( 'easyopt_psi_after_retries' );
        $job = self::start_job( array( 'after' ), false );
        // Tagged so on_job_finished() can tell this one-shot apart from a
        // manual run and re-arm it if it fails.
        $job['first_after'] = true;
        update_option( self::OPT_JOB, $job, false );
    }

    /**
     * Called when a job finishes. Re-arms the one-shot "after" benchmark when
     * it failed outright: OPT_FIRST is set at scheduling time and never
     * cleared, and proxy users get no daily rescan, so without this a single
     * failed run means no "after" score ever again.
     *
     * Bounded by FIRST_AFTER_MAX_FAILS so this can never become a permanent
     * cron loop.
     */
    public static function on_job_finished( array $job ) {
        if ( empty( $job['first_after'] ) ) {
            return;
        }
        if ( 'error' !== (string) ( $job['status'] ?? '' ) ) {
            delete_option( self::OPT_FIRST_FAILS );
            return;
        }
        $fails = (int) get_option( self::OPT_FIRST_FAILS, 0 ) + 1;
        if ( $fails > self::FIRST_AFTER_MAX_FAILS ) {
            return;
        }
        update_option( self::OPT_FIRST_FAILS, $fails, false );
        if ( ! wp_next_scheduled( self::JOB_HOOK . '_first_after' ) ) {
            wp_schedule_single_event( time() + 30 * MINUTE_IN_SECONDS, self::JOB_HOOK . '_first_after' );
        }
    }

    /* ───────────────────────────────────────────────
     *  Baseline bypass token
     * ─────────────────────────────────────────────── */

    public static function issue_bypass() {
        $token = wp_generate_password( 24, false, false );
        update_option( self::OPT_BYPASS, array(
            'token'   => $token,
            'expires' => time() + self::BYPASS_TTL,
        ), false );
        return $token;
    }

    /** Called very early from easy-optimizer.php's buffer gate. */
    public static function validate_bypass( $token ) {
        if ( '' === $token ) {
            return false;
        }
        $stored = get_option( self::OPT_BYPASS, array() );
        if ( ! is_array( $stored ) || empty( $stored['token'] ) || empty( $stored['expires'] ) ) {
            return false;
        }
        if ( time() > (int) $stored['expires'] ) {
            return false;
        }
        return hash_equals( (string) $stored['token'], $token );
    }

    /* ───────────────────────────────────────────────
     *  REST
     * ─────────────────────────────────────────────── */

    public static function register_routes() {

        // Public verification endpoint — answers ONLY while a registration
        // is in flight (10-min window) and only with the random code; it
        // exposes nothing else and is dead the rest of the time.
        register_rest_route( 'easyopt/v1', '/psi-verify', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_verify' ),
            'permission_callback' => '__return_true',
        ) );

        $perm = function () {
            return current_user_can( 'manage_options' );
        };
        register_rest_route( 'easyopt/v1', '/psi/data', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_data' ),
            'permission_callback' => $perm,
        ) );
        register_rest_route( 'easyopt/v1', '/psi/benchmark', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_benchmark' ),
            'permission_callback' => $perm,
        ) );
        register_rest_route( 'easyopt/v1', '/psi/auto', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_auto' ),
            'permission_callback' => $perm,
        ) );
        // (2.5.5) Loopback-free fallback worker. Behind a firewall (Sucuri
        // cloud WAF etc.) spawn_cron()'s loopback request to wp-cron.php can
        // be challenged or dropped, so the scheduled job never executes and
        // the card used to spin until the 10-minute stale unlock. The React
        // card detects that (job running, ~15s elapsed, cron never checked
        // in) and drives the job through THIS admin-authenticated route
        // instead — the request arrives via the user's browser and the PSI
        // calls themselves are outbound, so the WAF is never in the path.
        register_rest_route( 'easyopt/v1', '/psi/run-step', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_run_step' ),
            'permission_callback' => $perm,
        ) );
    }

    public static function rest_verify() {
        $pending = get_option( self::OPT_VERIFY, array() );
        if ( ! is_array( $pending ) || empty( $pending['code'] ) || time() > (int) ( $pending['expires'] ?? 0 ) ) {
            return new \WP_Error( 'easyopt_no_pending', 'No registration pending.', array( 'status' => 404 ) );
        }
        return rest_ensure_response( array( 'code' => (string) $pending['code'] ) );
    }

    public static function rest_data() {
        $results = get_option( self::OPT_RESULTS, array() );

        // (2.6.0) Recommendations are computed HERE, not in React, because the
        // decisions need server-side knowledge: which settings are on, which
        // exclusion field each finding maps to, and which audit IDs this build
        // understands. Cost is array work over at most 15 rows per device.
        $recs      = array();
        $available = array();
        foreach ( array( 'mobile', 'desktop' ) as $dev ) {
            $after           = isset( $results[ $dev ]['after'] ) ? $results[ $dev ]['after'] : array();
            $recs[ $dev ]    = self::recommendations_from( $after );
            // Distinguishes "measured, nothing to fix" from "this data source
            // cannot tell us" — the card renders those very differently.
            $available[ $dev ] = ! empty( $after['audits_available'] );
        }

        return rest_ensure_response( array(
            'results'         => empty( $results ) ? new \stdClass() : $results,
            'registered'      => self::is_registered(),
            'own_key'         => '' !== self::own_key(),
            'own_key_valid'   => self::own_key_valid(),
            'manual_left'     => self::manual_runs_left(),
            'manual_limit'    => self::MANUAL_RUNS_PER_DAY,
            'auto'            => (int) \EasyOpt_Config::get( 'psi_auto', 1 ),
            'job'             => self::job_state(),
            'recommendations' => $recs,
            'audits_available' => $available,
            // Settings-derived fallback for the proxy-without-audits and
            // never-tested cases. Labelled in the UI as NOT measured, because
            // presenting a static checklist as if it came from a real test is
            // exactly the dishonesty this card exists to avoid.
            'suggested'       => self::suggested_from_settings(),
        ) );
    }

    /**
     * (2.6.0) Fallback "recommended next step" list built purely from which
     * features are switched off. No measurement involved — the card says so.
     *
     * Ordered by the typical size of the win on a normal WordPress site, so
     * the list still reads as advice rather than an arbitrary settings dump.
     *
     * @return array
     */
    public static function suggested_from_settings() {
        // 'title' is the FEATURE NAME, not a sentence. The button supplies the
        // verb, so a row reads "Remove Unused CSS … [Enable]" instead of
        // saying "Enable Remove Unused CSS" twice in the same row.
        $candidates = array(
            array( 'opt' => 'easyopt_cache',             'title' => __( 'Page Cache', 'easy-optimizer' ),           'tab' => 'cache',        'sub' => '',     'metrics' => array( 'LCP' ) ),
            array( 'opt' => 'easyopt_img_opt',           'title' => __( 'Smart Images', 'easy-optimizer' ),         'tab' => 'imgopt',       'sub' => '',     'metrics' => array( 'LCP' ) ),
            array( 'opt' => 'easyopt_delay_js',          'title' => __( 'Delay JavaScript', 'easy-optimizer' ),     'tab' => 'optimization', 'sub' => 'js',   'metrics' => array( 'INP' ) ),
            array( 'opt' => 'easyopt_lcp_preload',       'title' => __( 'LCP Preload', 'easy-optimizer' ),          'tab' => 'optimization', 'sub' => 'lcp',  'metrics' => array( 'LCP' ) ),
            array( 'opt' => 'easyopt_unused_css',        'title' => __( 'Remove Unused CSS', 'easy-optimizer' ),    'tab' => 'optimization', 'sub' => 'css',  'metrics' => array( 'LCP' ) ),
            array( 'opt' => 'easyopt_lazy_images',       'title' => __( 'Lazyload Images', 'easy-optimizer' ),      'tab' => 'optimization', 'sub' => 'lazy', 'metrics' => array( 'LCP' ) ),
            array( 'opt' => 'easyopt_add_missing_dims',  'title' => __( 'Add Missing Dimensions', 'easy-optimizer' ), 'tab' => 'optimization', 'sub' => 'lazy', 'metrics' => array( 'CLS' ) ),
            array( 'opt' => 'easyopt_font_display_swap', 'title' => __( 'Font Display Swap', 'easy-optimizer' ),    'tab' => 'fonts',        'sub' => '',     'metrics' => array( 'CLS' ) ),
            // (2.7.1) Minify CSS / Minify JavaScript intentionally omitted — low
            // impact, and dropped from Recommended next steps by request.
        );

        $out = array();
        foreach ( $candidates as $c ) {
            if ( (int) \EasyOpt_Config::get( $c['opt'] ) === 1 ) {
                continue; // already on — nothing to suggest.
            }
            $out[] = array(
                'id'         => $c['opt'],
                'title'      => $c['title'],
                'display'    => '',
                'savings_ms' => 0,
                'metrics'    => $c['metrics'],
                'impact'     => self::impact_for( $c['opt'] ),
                'state'      => 'enable',
                'opt'        => $c['opt'],
                'label'      => __( 'Enable', 'easy-optimizer' ),
                'tab'        => $c['tab'],
                'sub'        => $c['sub'],
                'note'       => '',
            );
            if ( count( $out ) >= 5 ) {
                break;
            }
        }

        // Highest-impact first, so the list reads as advice even though no
        // measurement is involved. array_multisort keeps it stable.
        $rank = array( 'high' => 0, 'med' => 1, 'low' => 2 );
        usort( $out, function ( $a, $b ) use ( $rank ) {
            return $rank[ $a['impact'] ] - $rank[ $b['impact'] ];
        } );

        return $out;
    }

    /**
     * Start a manual benchmark as a BACKGROUND job: the four PSI tests
     * (before/after × mobile/desktop) run in parallel server-side, each
     * result is saved the moment it arrives, and the dashboard just polls
     * job state — closing the tab loses nothing. "Before" is included only
     * when missing or older than 24 h; it is never re-tested otherwise.
     */
    public static function rest_benchmark() {
        if ( self::job_running() ) {
            return rest_ensure_response( array( 'ok' => true, 'job' => self::job_state() ) );
        }

        if ( '' === self::own_key() && self::manual_runs_left() <= 0 ) {
            return new \WP_Error( 'easyopt_quota', __( 'You have used today\'s 3 free benchmarks. They reset tomorrow — or add your own free Google key in Settings for unlimited tests.', 'easy-optimizer' ), array( 'status' => 429 ) );
        }

        // Proxy mode: register on first ever run (user-initiated — good).
        if ( '' === self::own_key() && ! self::is_registered() ) {
            $reg = self::register_site();
            if ( is_wp_error( $reg ) ) {
                return $reg;
            }
        }

        $modes  = array( 'after' );
        $before = self::baseline_age();
        // Baseline ("before") is cached for 7 days — only re-measured on a
        // manual run once it's missing or older than a week.
        if ( null === $before || $before > 7 * DAY_IN_SECONDS ) {
            $modes[] = 'before';
        }

        // NOTE: the manual-run quota is charged on COMPLETION (in run_job),
        // and only when a fresh "after" score actually comes back — so a
        // failed or no-score run never costs the user one of their 3/day.
        $job = self::start_job( $modes, true );
        return rest_ensure_response( array( 'ok' => true, 'job' => $job ) );
    }

    /** Toggle daily auto-rescan from the dashboard panel (own-key users). */
    public static function rest_auto( $request ) {
        $on = (int) (bool) $request->get_param( 'on' );
        \EasyOpt_Config::update_many( array( 'easyopt_psi_auto' => $on ) );
        return rest_ensure_response( array( 'ok' => true, 'auto' => $on ) );
    }

    /* ───────────────────────────────────────────────
     *  Background job runner
     * ─────────────────────────────────────────────── */

    private static function job_state() {
        $j = get_option( self::OPT_JOB, array() );
        return is_array( $j ) ? $j : array();
    }

    private static function job_running() {
        $j = self::job_state();
        if ( empty( $j['status'] ) || 'running' !== $j['status'] ) {
            return false;
        }
        // Stale-job recovery: a crashed runner must never wedge the button.
        return ( time() - (int) ( $j['started'] ?? 0 ) ) < 10 * MINUTE_IN_SECONDS;
    }

    private static function has_baseline() {
        return null !== self::baseline_age();
    }

    /** Seconds since the newest stored baseline, or null when none. */
    private static function baseline_age() {
        $all  = get_option( self::OPT_RESULTS, array() );
        $last = 0;
        foreach ( array( 'mobile', 'desktop' ) as $s ) {
            if ( isset( $all[ $s ]['before']['tested_at'] ) ) {
                $last = max( $last, (int) $all[ $s ]['before']['tested_at'] );
            }
        }
        return $last > 0 ? time() - $last : null;
    }

    /**
     * Record job intent and kick the cron runner immediately. The actual
     * PSI work happens in run_job() on a cron request, fully detached from
     * the dashboard request that pressed the button.
     */
    private static function start_job( array $modes, $manual ) {
        $steps = array();
        foreach ( $modes as $mode ) {
            foreach ( array( 'mobile', 'desktop' ) as $strategy ) {
                $steps[ $strategy . '_' . $mode ] = 'pending';
            }
        }
        $job = array(
            'status'  => 'running',
            'started' => time(),
            'manual'  => (bool) $manual,
            'steps'   => $steps,
            'message' => '',
        );
        update_option( self::OPT_JOB, $job, false );

        if ( ! wp_next_scheduled( self::JOB_HOOK ) ) {
            wp_schedule_single_event( time() - 1, self::JOB_HOOK );
        }
        spawn_cron();

        return $job;
    }

    /**
     * Cron: execute every pending step. All tests fire IN PARALLEL via
     * Requests::request_multiple (the same engine behind wp_remote_get),
     * so a full 4-test benchmark takes one PSI round-trip (~60–90 s)
     * instead of four sequential ones. Each completed test is persisted
     * individually — a single slow or failed test can no longer blank the
     * other device's results.
     */
    public static function run_job( $via = 'cron' ) {
        $job = self::job_state();
        if ( empty( $job['steps'] ) || 'running' !== ( $job['status'] ?? '' ) ) {
            return;
        }

        // (2.5.5) Liveness flag: recorded ONLY when the cron path executes,
        // so the dashboard can tell "cron is slow" apart from "cron's
        // loopback is blocked". The browser fallback deliberately does not
        // set it — a fallback run proves nothing about wp-cron.
        if ( 'cron' === $via && empty( $job['cron_seen'] ) ) {
            $job['cron_seen'] = time();
            update_option( self::OPT_JOB, $job, false );
        }

        // (2.5.5) Single-runner lock. Once the browser fallback exists, a
        // delayed cron pass and a run-step call can overlap; PSI steps are
        // persisted individually so a double run wastes quota rather than
        // corrupting results — but quota is exactly what proxy users are
        // short on. First runner wins; the other returns and lets the poll
        // loop pick up the persisted results.
        if ( false !== get_transient( 'easyopt_psi_job_lock' ) ) {
            return;
        }
        set_transient( 'easyopt_psi_job_lock', $via, 3 * MINUTE_IN_SECONDS );

        // "before" steps share one bypass token (10-min TTL covers the run).
        $needs_before = false;
        foreach ( $job['steps'] as $step => $state ) {
            if ( 'pending' === $state && false !== strpos( $step, '_before' ) ) {
                $needs_before = true;
            }
        }
        $bypass = $needs_before ? self::issue_bypass() : '';

        $requests = array();
        foreach ( $job['steps'] as $step => $state ) {
            if ( 'pending' !== $state ) {
                continue;
            }
            list( $strategy, $mode ) = explode( '_', $step, 2 );
            $url = home_url( '/' );
            if ( 'before' === $mode ) {
                $url = add_query_arg( 'easyopt_bypass', rawurlencode( $bypass ), $url );
            }
            // Only a user-pressed run forces a fresh "after"; background and
            // "before" calls may use the proxy's cache.
            $fresh = ( 'after' === $mode ) && ! empty( $job['manual'] );
            $requests[ $step ] = self::build_request( $url, $strategy, $fresh );
        }
        if ( empty( $requests ) ) {
            $job['status'] = 'done';
            update_option( self::OPT_JOB, $job, false );
            delete_transient( 'easyopt_psi_job_lock' );
            return;
        }

        $responses = self::dispatch_parallel( $requests );

        $all = get_option( self::OPT_RESULTS, array() );
        if ( ! is_array( $all ) ) {
            $all = array();
        }
        $failures = array();

        foreach ( $requests as $step => $req ) {
            list( $strategy, $mode ) = explode( '_', $step, 2 );
            $result = self::parse_response( $responses[ $step ] ?? null, $req['path'] );
            if ( is_wp_error( $result ) ) {
                $job['steps'][ $step ] = 'fail';
                $failures[]            = ucfirst( $strategy ) . ' (' . $mode . '): ' . $result->get_error_message();
                continue;
            }
            $result['tested_at'] = time();
            // (2.6.0) Audits are kept for the "after" result only. The "before"
            // run measures the site with Easy Optimizer bypassed, so its
            // findings describe a state the user is not in and cannot act on —
            // storing them would double the option size for nothing.
            if ( 'after' !== $mode ) {
                unset( $result['audits'], $result['audits_available'] );
            }
            $all[ $strategy ][ $mode ] = $result;
            update_option( self::OPT_RESULTS, $all, false ); // persist per result
            $job['steps'][ $step ] = 'ok';
            update_option( self::OPT_JOB, $job, false );
        }

        $job['status']  = empty( $failures ) ? 'done' : ( count( $failures ) === count( $requests ) ? 'error' : 'partial' );
        $job['message'] = implode( ' · ', array_slice( $failures, 0, 2 ) );
        update_option( self::OPT_JOB, $job, false );

        // Charge one manual-run unit only when this was a user-initiated run
        // that produced at least one fresh "after" score. Proxy users only —
        // own-key users have no local cap. No-score / failed runs cost nothing.
        if ( ! empty( $job['manual'] ) && '' === self::own_key() ) {
            foreach ( $job['steps'] as $step => $st ) {
                if ( 'ok' === $st && false !== strpos( $step, '_after' ) ) {
                    self::count_manual_run();
                    break;
                }
            }
        }

        self::on_job_finished( $job );

        delete_transient( 'easyopt_psi_job_lock' );
    }

    /**
     * (2.5.5) REST: execute the pending PSI steps inline, from the admin's
     * browser session. Called by the dashboard card when it detects that
     * wp-cron's loopback never picked the job up (see route registration).
     * run_job('browser') keeps the single-runner lock and does NOT set the
     * cron liveness flag; results persist per step exactly as on the cron
     * path, and the card's normal /psi/data polling renders them.
     */
    public static function rest_run_step() {
        if ( ! self::job_running() ) {
            return rest_ensure_response( array( 'ok' => true, 'job' => self::job_state() ) );
        }
        // The four PSI tests run in parallel with a 120s HTTP timeout; give
        // this request room to wait for them.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 180 );
        }
        // The scheduled event stays in place; if wp-cron recovers later and
        // fires it, the lock (or the no-pending-steps guard) makes it a no-op.
        self::run_job( 'browser' );
        return rest_ensure_response( array( 'ok' => true, 'job' => self::job_state() ) );
    }

    /* ───────────────────────────────────────────────
     *  Data paths
     * ─────────────────────────────────────────────── */

    private static function own_key() {
        return trim( (string) \EasyOpt_Config::get( 'psi_api_key', '' ) );
    }

    private static function is_registered() {
        $c = get_option( self::OPT_CREDENTIALS, array() );
        return is_array( $c ) && ! empty( $c['token'] ) && ! empty( $c['secret'] );
    }

    /**
     * Whether the saved Google key actually works. Verified with one quick
     * (~0.3 s) probe — a deliberately invalid test URL: Google rejects the
     * KEY with a distinct "API key not valid" / permission error, while a
     * good key fails on the URL instead. Cached until the key changes, so
     * the probe runs once per saved key, not per dashboard load.
     */
    private static function own_key_valid() {
        $key = self::own_key();
        if ( '' === $key ) {
            return false;
        }
        $status = get_option( self::OPT_KEYCHK, array() );
        if ( is_array( $status ) && ( $status['hash'] ?? '' ) === md5( $key ) ) {
            return ! empty( $status['valid'] );
        }

        $resp  = wp_remote_get(
            self::GOOGLE_API . '?url=' . rawurlencode( 'https://example.invalid/' ) . '&key=' . rawurlencode( $key ),
            array( 'timeout' => 15 )
        );
        $valid = false;
        if ( ! is_wp_error( $resp ) ) {
            $body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
            $msg  = strtolower( (string) ( $body['error']['message'] ?? '' ) );
            // Key problems mention the key/permissions; URL problems don't.
            $valid = ( false === strpos( $msg, 'api key' ) )
                && ( false === strpos( $msg, 'permission' ) )
                && ( false === strpos( $msg, 'has not been used' ) );
        }
        update_option( self::OPT_KEYCHK, array( 'hash' => md5( $key ), 'valid' => $valid, 'checked' => time() ), false );
        return $valid;
    }

    /**
     * One-time registration with the FluxPress proxy (domain-bound token).
     */
    private static function register_site() {
        $code = wp_generate_password( 32, false, false );
        update_option( self::OPT_VERIFY, array(
            'code'    => $code,
            'expires' => time() + 600,
        ), false );

        $resp = wp_remote_post( self::PROXY_BASE . '/psi/register', array(
            'timeout' => 30,
            'body'    => array(
                'domain'     => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
                'code'       => $code,
                'verify_url' => rest_url( 'easyopt/v1/psi-verify' ),
            ),
        ) );

        delete_option( self::OPT_VERIFY );

        if ( is_wp_error( $resp ) ) {
            return new \WP_Error( 'easyopt_register_failed', sprintf(
                /* translators: %s: error */
                __( 'Could not reach the benchmark service: %s', 'easy-optimizer' ),
                $resp->get_error_message()
            ), array( 'status' => 502 ) );
        }

        $body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
        if ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) || empty( $body['token'] ) || empty( $body['secret'] ) ) {
            $msg = is_array( $body ) && ! empty( $body['message'] ) ? (string) $body['message'] : __( 'Registration was rejected.', 'easy-optimizer' );
            return new \WP_Error( 'easyopt_register_rejected', $msg, array( 'status' => 502 ) );
        }

        update_option( self::OPT_CREDENTIALS, array(
            'token'  => (string) $body['token'],
            'secret' => (string) $body['secret'],
        ), false );

        return true;
    }

    /** Describe one PSI test as a transport-agnostic request spec. */
    private static function build_request( $url, $strategy, $fresh = false ) {
        if ( '' !== self::own_key() ) {
            return array(
                'path'    => 'google',
                'url'     => self::GOOGLE_API
                    . '?url=' . rawurlencode( $url )
                    . '&strategy=' . $strategy
                    . '&category=performance'
                    . '&key=' . rawurlencode( self::own_key() ),
                'type'    => 'GET',
                'headers' => array(),
                'data'    => null,
            );
        }
        $c       = get_option( self::OPT_CREDENTIALS, array() );
        $payload = array( 'url' => $url, 'strategy' => $strategy );
        if ( $fresh ) {
            $payload['fresh'] = true; // bypass the proxy's 12h cache → real re-measure
        }
        // (2.6.0) Ask for audit-level findings. Explicit rather than implied so
        // the proxy can skip the extra work for plugin versions that would only
        // discard them. A proxy that predates this flag ignores it, and the
        // response is then handled by the audits_available branch in
        // parse_response(). The HMAC below signs this exact body, so adding a
        // field needs no signature change.
        $payload['audits'] = 1;
        $body = wp_json_encode( $payload );
        $ts   = (string) time();
        return array(
            'path'    => 'proxy',
            'url'     => self::PROXY_BASE . '/psi/run',
            'type'    => 'POST',
            'headers' => array(
                'Content-Type'   => 'application/json',
                'X-FP-Token'     => (string) ( $c['token'] ?? '' ),
                'X-FP-Timestamp' => $ts,
                'X-FP-Signature' => hash_hmac( 'sha256', ( $c['token'] ?? '' ) . '|' . $ts . '|' . $body, (string) ( $c['secret'] ?? '' ) ),
            ),
            'data'    => $body,
        );
    }

    /**
     * Fire all request specs IN PARALLEL via the Requests engine bundled
     * with WordPress (the same code under wp_remote_get). Falls back to
     * sequential wp_remote_* if the multiplexer is unavailable. Returns a
     * map keyed like the input: Requests response objects, WP arrays, or
     * exceptions — parse_response() normalises all three.
     */
    private static function dispatch_parallel( array $specs ) {
        $multi = null;
        if ( class_exists( '\\WpOrg\\Requests\\Requests' ) ) {
            $multi = '\\WpOrg\\Requests\\Requests';
        } elseif ( class_exists( 'Requests' ) ) {
            $multi = 'Requests';
        }

        if ( $multi ) {
            $batch = array();
            foreach ( $specs as $key => $s ) {
                $batch[ $key ] = array(
                    'url'     => $s['url'],
                    'type'    => $s['type'],
                    'headers' => $s['headers'],
                    'data'    => null === $s['data'] ? array() : $s['data'],
                    'options' => array( 'timeout' => 120, 'connect_timeout' => 20 ),
                );
            }
            try {
                return $multi::request_multiple( $batch, array( 'timeout' => 120 ) );
            } catch ( \Throwable $e ) { // phpcs:ignore
                // Fall through to sequential.
            }
        }

        $out = array();
        foreach ( $specs as $key => $s ) {
            $args = array( 'timeout' => 120, 'headers' => $s['headers'] );
            if ( 'POST' === $s['type'] ) {
                $args['body'] = $s['data'];
                $out[ $key ]  = wp_remote_post( $s['url'], $args );
            } else {
                $out[ $key ] = wp_remote_get( $s['url'], $args );
            }
        }
        return $out;
    }

    /** Normalise any transport's response into the slim result or WP_Error. */
    private static function parse_response( $resp, $path ) {
        if ( null === $resp ) {
            return new \WP_Error( 'easyopt_psi_failed', __( 'No response from the test service.', 'easy-optimizer' ) );
        }
        if ( $resp instanceof \Throwable || ( class_exists( '\\WpOrg\\Requests\\Exception' ) && $resp instanceof \WpOrg\Requests\Exception ) || $resp instanceof \Exception ) {
            return new \WP_Error( 'easyopt_psi_failed', $resp->getMessage() );
        }
        if ( is_wp_error( $resp ) ) {
            return new \WP_Error( 'easyopt_psi_failed', $resp->get_error_message() );
        }

        if ( is_object( $resp ) && isset( $resp->status_code ) ) {
            $code = (int) $resp->status_code;
            $body = (string) $resp->body;
        } else {
            $code = (int) wp_remote_retrieve_response_code( $resp );
            $body = (string) wp_remote_retrieve_body( $resp );
        }
        $data = json_decode( $body, true );

        if ( 'google' === $path ) {
            if ( 200 !== $code || empty( $data['lighthouseResult'] ) ) {
                $msg = isset( $data['error']['message'] ) ? (string) $data['error']['message'] : __( 'Google returned an unexpected response.', 'easy-optimizer' );
                return new \WP_Error( 'easyopt_psi_failed', $msg );
            }
            return self::slim_from_lighthouse( $data );
        }

        if ( 401 === $code ) {
            delete_option( self::OPT_CREDENTIALS ); // re-register next run
        }
        if ( 200 !== $code || ! is_array( $data ) || ! isset( $data['score'] ) ) {
            $msg = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : __( 'The benchmark service returned an unexpected response.', 'easy-optimizer' );
            return new \WP_Error( 'easyopt_psi_failed', $msg );
        }
        $out = array(
            'score' => (int) $data['score'],
            'lcp'   => (string) ( $data['lcp'] ?? '' ),
            'cls'   => (string) ( $data['cls'] ?? '' ),
            'inp'   => (string) ( $data['inp'] ?? '' ),
        );

        // (2.6.0) Audit-level findings, when the proxy supplies them.
        //
        // Absence and emptiness mean DIFFERENT things and the card renders
        // them differently, so the distinction is preserved deliberately:
        //   - no 'audits_available' key  → this proxy build does not return
        //     audits; fall back to the settings-derived checklist and label it
        //     as not measured.
        //   - audits_available + []      → measured, and nothing Easy
        //     Optimizer can act on is failing. That is good news, not a gap.
        //
        // An older proxy simply omits both keys, and an older plugin ignores
        // them, so no version negotiation is needed in either direction.
        if ( array_key_exists( 'audits_available', $data ) ) {
            $out['audits_available'] = (bool) $data['audits_available'];
            $out['audits']           = self::sanitize_proxy_audits(
                isset( $data['audits'] ) ? $data['audits'] : array()
            );
        }
        if ( ! empty( $data['lh_version'] ) ) {
            $out['lh_version'] = (string) $data['lh_version'];
        }

        return $out;
    }

    /**
     * (2.6.0) Normalise proxy-supplied audits to the same shape slim_audits()
     * produces locally, so the card has exactly one contract to render.
     *
     * The proxy is our own service, but this is still remote input landing in
     * an option and then in admin HTML: every field is cast, length-capped and
     * allow-listed here rather than trusted.
     *
     * @param mixed $audits Raw 'audits' array from the proxy.
     * @return array
     */
    private static function sanitize_proxy_audits( $audits ) {
        if ( ! is_array( $audits ) ) {
            return array();
        }
        $map = self::action_map();
        $out = array();

        foreach ( $audits as $a ) {
            if ( ! is_array( $a ) || empty( $a['id'] ) ) {
                continue;
            }
            $id = (string) $a['id'];
            if ( ! isset( $map[ $id ] ) ) {
                continue; // unknown / unactionable — drop rather than guess.
            }
            $metrics = array();
            if ( isset( $a['metric_savings'] ) && is_array( $a['metric_savings'] ) ) {
                foreach ( array( 'LCP', 'CLS', 'INP', 'TBT', 'FCP' ) as $m ) {
                    if ( isset( $a['metric_savings'][ $m ] ) && (float) $a['metric_savings'][ $m ] > 0 ) {
                        $metrics[ $m ] = (float) $a['metric_savings'][ $m ];
                    }
                }
            }
            $out[] = array(
                'id'             => $id,
                'title'          => isset( $a['title'] ) ? self::trim_text( wp_strip_all_tags( (string) $a['title'] ), 120 ) : $id,
                'score'          => isset( $a['score'] ) ? (float) $a['score'] : 0.0,
                'display'        => isset( $a['display'] ) ? self::trim_text( wp_strip_all_tags( (string) $a['display'] ), 60 ) : '',
                'savings_ms'     => isset( $a['savings_ms'] ) ? (int) $a['savings_ms'] : 0,
                'metric_savings' => $metrics,
            );
            if ( count( $out ) >= 15 ) {
                break;
            }
        }
        return $out;
    }

    /** Map a raw Lighthouse payload to the slim shape (BYO-key path). */
    private static function slim_from_lighthouse( array $body ) {
        $lh     = $body['lighthouseResult'];
        $audits = isset( $lh['audits'] ) ? $lh['audits'] : array();
        $metric = function ( $id ) use ( $audits ) {
            return isset( $audits[ $id ]['displayValue'] ) ? preg_replace( '/\x{00A0}/u', '', (string) $audits[ $id ]['displayValue'] ) : '';
        };
        // INP from field data when present, lab TBT otherwise (labelled by UI).
        $inp = '';
        if ( isset( $body['loadingExperience']['metrics']['INTERACTION_TO_NEXT_PAINT']['percentile'] ) ) {
            $inp = (int) $body['loadingExperience']['metrics']['INTERACTION_TO_NEXT_PAINT']['percentile'] . 'ms';
        }
        return array(
            'score' => (int) round( 100 * (float) ( $lh['categories']['performance']['score'] ?? 0 ) ),
            'lcp'   => $metric( 'largest-contentful-paint' ),
            'cls'   => $metric( 'cumulative-layout-shift' ),
            'inp'   => '' !== $inp ? $inp : $metric( 'total-blocking-time' ),
            // (2.6.0) The full Lighthouse payload is right here on the BYO-key
            // path and used to be thrown away. Keeping a slim, allow-listed
            // slice of it is what powers the "What to fix next" card. The raw
            // JSON is never stored — see slim_audits().
            'audits'           => self::slim_audits( $audits ),
            'audits_available' => true,
            'lh_version'       => isset( $lh['lighthouseVersion'] ) ? (string) $lh['lighthouseVersion'] : '',
        );
    }

    /**
     * (2.6.0) Reduce a Lighthouse audits map to the few failing entries Easy
     * Optimizer can actually act on.
     *
     * Lighthouse 13 replaced the old opportunity/diagnostic audits with
     * "insights" — image-delivery-insight in place of modern-image-formats,
     * uses-optimized-images, uses-responsive-images and
     * efficient-animated-content; render-blocking-insight in place of
     * render-blocking-resources, and so on. PSI serves whichever version
     * Google is currently running, and a handful of legacy IDs survived the
     * cut, so ACTION_MAP carries both spellings and this filter simply keeps
     * whatever it recognises. An ID we do not know is dropped rather than
     * guessed at, which means a future rename degrades to "one less
     * recommendation" instead of a broken card.
     *
     * Capped at 15 entries of ~6 scalar fields. The result is stored inside the
     * existing (non-autoloaded) easyopt_psi_results option.
     *
     * @param array $audits Lighthouse `audits` map.
     * @return array List of slim audit rows, biggest saving first.
     */
    private static function slim_audits( $audits ) {
        if ( ! is_array( $audits ) || empty( $audits ) ) {
            return array();
        }
        $map  = self::action_map();
        $out  = array();

        foreach ( $audits as $id => $a ) {
            $id = (string) $id;
            if ( ! isset( $map[ $id ] ) || ! is_array( $a ) ) {
                continue;
            }
            // score === null is an informative audit (no pass/fail); >= 0.9 is
            // effectively passing. Neither is worth a recommendation.
            if ( ! isset( $a['score'] ) || null === $a['score'] ) {
                continue;
            }
            $score = (float) $a['score'];
            if ( $score >= 0.9 ) {
                continue;
            }

            $details    = isset( $a['details'] ) && is_array( $a['details'] ) ? $a['details'] : array();
            $savings_ms = isset( $details['overallSavingsMs'] ) ? (int) round( (float) $details['overallSavingsMs'] ) : 0;

            // Lighthouse 10+ reports per-metric impact directly. It is the
            // honest source for the LCP / CLS / INP chips on the card — far
            // better than inferring the metric from the audit's identity.
            $metric_savings = array();
            if ( isset( $a['metricSavings'] ) && is_array( $a['metricSavings'] ) ) {
                foreach ( array( 'LCP', 'CLS', 'INP', 'TBT', 'FCP' ) as $m ) {
                    if ( isset( $a['metricSavings'][ $m ] ) && (float) $a['metricSavings'][ $m ] > 0 ) {
                        $metric_savings[ $m ] = (float) $a['metricSavings'][ $m ];
                    }
                }
            }
            if ( 0 === $savings_ms && isset( $metric_savings['LCP'] ) ) {
                $savings_ms = (int) round( $metric_savings['LCP'] );
            }

            $out[] = array(
                'id'      => $id,
                'title'   => isset( $a['title'] ) ? self::trim_text( (string) $a['title'], 120 ) : $id,
                'score'   => $score,
                'display' => isset( $a['displayValue'] )
                    ? self::trim_text( preg_replace( '/\x{00A0}/u', ' ', (string) $a['displayValue'] ), 60 )
                    : '',
                'savings_ms'     => $savings_ms,
                'metric_savings' => $metric_savings,
            );
        }

        // Rank by estimated saving so the biggest win is first, then by how
        // badly the audit failed for entries Lighthouse gave no ms figure.
        usort( $out, function ( $a, $b ) {
            if ( $a['savings_ms'] !== $b['savings_ms'] ) {
                return $b['savings_ms'] - $a['savings_ms'];
            }
            return ( $a['score'] < $b['score'] ) ? -1 : ( ( $a['score'] > $b['score'] ) ? 1 : 0 );
        } );

        return array_slice( $out, 0, 15 );
    }

    /** Length-cap a string on a whole character boundary. */
    private static function trim_text( $s, $max ) {
        $s = trim( (string) $s );
        if ( function_exists( 'mb_substr' ) ) {
            return ( mb_strlen( $s ) > $max ) ? rtrim( mb_substr( $s, 0, $max ) ) . '…' : $s;
        }
        return ( strlen( $s ) > $max ) ? rtrim( substr( $s, 0, $max ) ) . '…' : $s;
    }

    /**
     * (2.6.0) Lighthouse audit ID → Easy Optimizer action.
     *
     * Keyed on Lighthouse 13 insight IDs first, with the pre-13 IDs kept as
     * aliases so the card works whichever version PSI is serving and keeps
     * working for a site whose last benchmark predates the switch.
     *
     * Deliberately NOT mapped, because Easy Optimizer has no setting that
     * fixes them and a button that cannot deliver is worse than silence:
     * legacy-javascript-insight, duplicated-javascript-insight (theme/plugin
     * bundles), modern-http-insight (server protocol), dom-size-insight and
     * viewport-insight (theme markup), lcp-phases-insight (informational),
     * total-byte-weight.
     *
     * 'metric' is a fallback for the chips when Lighthouse sends no
     * metricSavings; 'note' is the honest caveat shown when the setting is
     * already on and the audit still fails.
     *
     * @return array<string, array>
     */
    public static function action_map() {
        static $map = null;
        if ( null !== $map ) {
            return $map;
        }

        $images = array(
            'opt'   => 'easyopt_img_opt',
            'label' => __( 'Enable Smart Images', 'easy-optimizer' ),
            'tab'   => 'imgopt',
            'sub'   => '',
            'metric' => 'LCP',
            'note'  => __( 'Smart Images is on but images are still flagged — likely CSS background images or an excluded path.', 'easy-optimizer' ),
        );
        $lcp = array(
            'opt'   => 'easyopt_lcp_preload',
            'label' => __( 'Enable LCP Preload', 'easy-optimizer' ),
            'tab'   => 'optimization',
            'sub'   => 'lcp',
            'metric' => 'LCP',
            'note'  => __( 'LCP Preload is on but the main image is still discovered late — it may be a CSS background, or lazy-loaded by your theme.', 'easy-optimizer' ),
        );
        $rucss = array(
            'opt'   => 'easyopt_unused_css',
            'label' => __( 'Enable Remove Unused CSS', 'easy-optimizer' ),
            'tab'   => 'optimization',
            'sub'   => 'css',
            'metric' => 'LCP',
            'note'  => __( 'Remove Unused CSS is on but stylesheets still block rendering — check the excluded stylesheets list.', 'easy-optimizer' ),
        );
        $delay = array(
            'opt'   => 'easyopt_delay_js',
            'label' => __( 'Enable Delay JavaScript', 'easy-optimizer' ),
            'tab'   => 'optimization',
            'sub'   => 'js',
            'metric' => 'INP',
            'note'  => __( 'Delay JavaScript is on but scripts still occupy the main thread — check the exclusions, or the script may load after interaction.', 'easy-optimizer' ),
        );
        $cache = array(
            'opt'   => 'easyopt_cache',
            'label' => __( 'Enable Page Cache', 'easy-optimizer' ),
            'tab'   => 'cache',
            'sub'   => '',
            'metric' => 'LCP',
            'note'  => __( 'Page Cache is on but the server still responded slowly — the tested page may be excluded, or not warmed yet.', 'easy-optimizer' ),
        );
        $dims = array(
            'opt'   => 'easyopt_add_missing_dims',
            'label' => __( 'Add Missing Dimensions', 'easy-optimizer' ),
            'tab'   => 'optimization',
            'sub'   => 'lazy',
            'metric' => 'CLS',
            'note'  => __( 'Dimensions are being added but the layout still shifts — an ad, embed or web font is the likelier cause.', 'easy-optimizer' ),
        );
        $fonts = array(
            'opt'   => 'easyopt_font_display_swap',
            'label' => __( 'Enable Font Display Swap', 'easy-optimizer' ),
            'tab'   => 'fonts',
            'sub'   => '',
            'metric' => 'CLS',
            'note'  => __( 'Font Display Swap is on but a font still blocks text — it may be loaded from an excluded origin.', 'easy-optimizer' ),
        );
        $preconnect = array(
            'opt'   => 'easyopt_preconnect',
            'label' => __( 'Enable Preconnect', 'easy-optimizer' ),
            'tab'   => 'cache',
            'sub'   => '',
            'metric' => 'LCP',
            'note'  => __( 'Preconnect is on but the request chain is still deep — this is usually your theme or a third-party script.', 'easy-optimizer' ),
        );
        $browser = array(
            'opt'   => 'easyopt_cache_browser_caching',
            'label' => __( 'Enable Browser Caching', 'easy-optimizer' ),
            'tab'   => 'cache',
            'sub'   => '',
            'metric' => 'LCP',
            'note'  => __( 'Browser Caching is on. On nginx and some managed hosts the rule has to be added to the server config — ask your host.', 'easy-optimizer' ),
        );
        // (2.7.1) Minify CSS / Minify JavaScript removed from the recommendation
        // engine entirely — the unminified-css / unminified-javascript audits no
        // longer map to an action, so they are never surfaced as next steps.

        $map = array(
            // ── Lighthouse 13 insights ───────────────────────────────────
            // Merges modern-image-formats + uses-optimized-images +
            // uses-responsive-images + efficient-animated-content, all of
            // which pointed at the same Easy Optimizer setting anyway.
            'image-delivery-insight'          => $images,
            'lcp-discovery-insight'           => $lcp,
            'render-blocking-insight'         => $rucss,
            'font-display-insight'            => $fonts,
            // Merges critical-request-chains + uses-rel-preconnect.
            'network-dependency-tree-insight' => $preconnect,
            // Merges redirects + server-response-time + uses-text-compression.
            // Page Cache is the leg Easy Optimizer can act on; the other two
            // are a redirect chain (nothing to toggle) and Gzip, which is on
            // by default. Mapping to the strongest actionable leg.
            'document-latency-insight'        => $cache,
            'use-cache-insight'               => $browser,
            // Merges layout-shifts + non-composited-animations +
            // unsized-images. Only the last has an Easy Optimizer action.
            'cls-culprits-insight'            => $dims,
            'third-parties-insight'           => $delay,
            'interaction-to-next-paint-insight' => $delay,

            // ── Pre-13 IDs, kept so the card still works on older results ──
            'modern-image-formats'      => $images,
            'uses-optimized-images'     => $images,
            'uses-responsive-images'    => $images,
            'efficient-animated-content' => $images,
            'offscreen-images'          => array(
                'opt'   => 'easyopt_lazy_images',
                'label' => __( 'Enable Lazyload Images', 'easy-optimizer' ),
                'tab'   => 'optimization',
                'sub'   => 'lazy',
                'metric' => 'LCP',
                'note'  => '',
            ),
            'prioritize-lcp-image'      => $lcp,
            'lcp-lazy-loaded'           => $lcp,
            'render-blocking-resources' => $rucss,
            'unused-css-rules'          => $rucss,
            'unused-javascript'         => $delay,
            'bootup-time'               => $delay,
            'mainthread-work-breakdown' => $delay,
            'third-party-summary'       => $delay,
            'font-display'              => $fonts,
            'uses-rel-preconnect'       => $preconnect,
            'critical-request-chains'   => $preconnect,
            'server-response-time'      => $cache,
            'uses-long-cache-ttl'       => $browser,
            'unsized-images'            => $dims,
            'uses-text-compression'     => array(
                'opt'   => 'easyopt_cache_gzip',
                'label' => __( 'Enable Gzip Compression', 'easy-optimizer' ),
                'tab'   => 'cache',
                'sub'   => '',
                'metric' => 'LCP',
                'note'  => __( 'Gzip is on. On OpenLiteSpeed the server compresses natively, so this is a server-side setting.', 'easy-optimizer' ),
            ),
        );

        return $map;
    }

    /**
     * (2.6.0) How much a given feature is typically worth on a normal
     * WordPress site: 'high' | 'med' | 'low'.
     *
     * Deliberately a fixed per-feature judgement rather than something derived
     * from the measured saving. A user comparing two sites — or reading a
     * screenshot in a support thread — should see the same badge against the
     * same feature every time; a badge that moved with each test run would be
     * noise rather than guidance. The measured saving is shown alongside it,
     * so the specific number is never hidden.
     *
     * @param string $opt Setting key.
     * @return string
     */
    private static function impact_for( $opt ) {
        static $levels = array(
            // Whole-page wins.
            'easyopt_cache'                 => 'high',
            'easyopt_cache_preload'         => 'high',
            'easyopt_img_opt'               => 'high',
            'easyopt_delay_js'              => 'high',
            'easyopt_lcp_preload'           => 'high',
            // Worthwhile, but smaller or narrower in effect.
            'easyopt_unused_css'            => 'med',
            'easyopt_lazy_images'           => 'med',
            'easyopt_add_missing_dims'      => 'med',
            'easyopt_font_display_swap'     => 'med',
            'easyopt_cache_browser_caching' => 'med',
            'easyopt_cache_gzip'            => 'med',
            'easyopt_object_cache'          => 'med',
            // Real, but rarely the thing holding a score back.
            'easyopt_minify_css'            => 'low',
            'easyopt_minify_js'             => 'low',
            'easyopt_preconnect'            => 'low',
        );
        return isset( $levels[ $opt ] ) ? $levels[ $opt ] : 'med';
    }

    /**
     * (2.6.0) Turn the stored audits for one device into ranked, actionable
     * recommendations for the "What to fix next" card.
     *
     * Behaviour that matters:
     *  - An audit whose setting is ALREADY ON is not dropped. It becomes a
     *    different, more useful message (state 'tune'): the feature is on and
     *    the audit still fails, so the answer is an exclusion, not a toggle.
     *  - Two audits mapping to the same setting collapse into one row, keeping
     *    the larger saving. Lighthouse 13 already merged most of these, but a
     *    pre-13 stored result can still carry three separate image audits.
     *
     * @param array $result One stored per-device 'after' result.
     * @return array
     */
    public static function recommendations_from( $result ) {
        if ( ! is_array( $result ) || empty( $result['audits'] ) || ! is_array( $result['audits'] ) ) {
            return array();
        }
        $map  = self::action_map();
        $rows = array();

        foreach ( $result['audits'] as $a ) {
            $id = isset( $a['id'] ) ? (string) $a['id'] : '';
            if ( '' === $id || ! isset( $map[ $id ] ) ) {
                continue;
            }
            $action = $map[ $id ];
            $opt    = (string) $action['opt'];
            $on     = (int) \EasyOpt_Config::get( $opt ) === 1;

            // Chips: Lighthouse's own per-metric impact when present, the
            // map's fallback otherwise. CLS is a unitless score, so a
            // millisecond threshold would wrongly discard it.
            $metrics = array();
            if ( ! empty( $a['metric_savings'] ) && is_array( $a['metric_savings'] ) ) {
                foreach ( array( 'LCP', 'CLS', 'INP' ) as $m ) {
                    if ( ! empty( $a['metric_savings'][ $m ] ) ) {
                        $metrics[] = $m;
                    }
                }
                // TBT is the lab proxy for INP; show it as INP so the card
                // speaks in Core Web Vitals rather than Lighthouse internals.
                if ( empty( $metrics ) && ! empty( $a['metric_savings']['TBT'] ) ) {
                    $metrics[] = 'INP';
                }
            }
            if ( empty( $metrics ) && ! empty( $action['metric'] ) ) {
                $metrics[] = (string) $action['metric'];
            }

            $row = array(
                'id'         => $id,
                'title'      => isset( $a['title'] ) ? (string) $a['title'] : $id,
                'display'    => isset( $a['display'] ) ? (string) $a['display'] : '',
                'savings_ms' => isset( $a['savings_ms'] ) ? (int) $a['savings_ms'] : 0,
                'metrics'    => $metrics,
                'impact'     => self::impact_for( $opt ),
                'state'      => $on ? 'tune' : 'enable',
                'opt'        => $opt,
                // (2.6.0) The button carries the verb only. The row title
                // already names the finding, and repeating the full feature
                // name in the button made every row say the same thing twice.
                'label'      => $on ? __( 'Review', 'easy-optimizer' ) : __( 'Enable', 'easy-optimizer' ),
                'tab'        => (string) $action['tab'],
                'sub'        => (string) $action['sub'],
                'note'       => $on ? (string) $action['note'] : '',
            );

            // Collapse duplicates that resolve to the same setting.
            if ( isset( $rows[ $opt ] ) ) {
                if ( $row['savings_ms'] > $rows[ $opt ]['savings_ms'] ) {
                    $rows[ $opt ] = $row;
                }
                continue;
            }
            $rows[ $opt ] = $row;
        }

        $rows = array_values( $rows );
        usort( $rows, function ( $a, $b ) {
            // Not-yet-enabled features first: a one-click win outranks a
            // "go and read your exclusion list" task of the same size.
            if ( $a['state'] !== $b['state'] ) {
                return ( 'enable' === $a['state'] ) ? -1 : 1;
            }
            return $b['savings_ms'] - $a['savings_ms'];
        } );

        return $rows;
    }

    /* ───────────────────────────────────────────────
     *  Local quota mirror
     * ─────────────────────────────────────────────── */

    private static function manual_runs_left() {
        $q     = get_option( self::OPT_QUOTA, array() );
        $today = gmdate( 'Y-m-d' );
        if ( ! is_array( $q ) || ( $q['date'] ?? '' ) !== $today ) {
            return self::MANUAL_RUNS_PER_DAY;
        }
        return max( 0, self::MANUAL_RUNS_PER_DAY - (int) ( $q['manual'] ?? 0 ) );
    }

    private static function count_manual_run() {
        $today = gmdate( 'Y-m-d' );
        $q     = get_option( self::OPT_QUOTA, array() );
        if ( ! is_array( $q ) || ( $q['date'] ?? '' ) !== $today ) {
            $q = array( 'date' => $today, 'manual' => 0 );
        }
        $q['manual'] = (int) $q['manual'] + 1;
        update_option( self::OPT_QUOTA, $q, false );
    }

    /**
     * Daily auto-rescan ("after" only) — own-key users only, since daily
     * automated runs against the shared FluxPress service would quietly
     * drain its quota. Called from the queue's daily GC tick; reuses the
     * same parallel job runner, executed inline (we're already in cron).
     */
    public static function maybe_auto_scan() {
        if ( ! (int) \EasyOpt_Config::get( 'psi_auto', 1 ) ) {
            return;
        }
        if ( '' === self::own_key() || ! self::own_key_valid() ) {
            return;
        }
        if ( self::job_running() ) {
            return;
        }
        $all  = get_option( self::OPT_RESULTS, array() );
        $last = 0;
        foreach ( array( 'mobile', 'desktop' ) as $s ) {
            if ( isset( $all[ $s ]['after']['tested_at'] ) ) {
                $last = max( $last, (int) $all[ $s ]['after']['tested_at'] );
            }
        }
        if ( 0 === $last || ( time() - $last ) < 22 * HOUR_IN_SECONDS ) {
            return; // never benchmarked (first run is user-initiated) or fresh
        }
        self::start_job( array( 'after' ), false );
        self::run_job();
    }
}
