<?php
/**
 * Cache Preloader.
 *
 * Collects URLs from sitemaps, menus and post types, then warms the
 * page cache by fetching each URL over HTTP and writing the response
 * body to disk through the background job queue. Supports automatic
 * restart on cache clear.
 *
 * Public surface stays compatible with prior versions:
 *   start(), stop(), on_preload_toggle(), enqueue_companion(),
 *   ajax_start/stop/status/waiting_count, run_batch (legacy shim),
 *   collect_urls(), filter_already_cached().
 *
 * Status state (option storage, derived from queue counts):
 *   easyopt_cache_preload_total    int     URLs queued for current run
 *   easyopt_cache_preload_status   string  idle | running | paused | done
 *
 *   Counts ARE derived:
 *     remaining = queue->get_pending_count()  // pending + processing
 *     done      = total - remaining
 *     status    = 'done' once remaining hits 0 with total > 0
 *
 *   Tasks are DELETED on success (the queue table only holds live
 *   work), so we don't get "ghost done" rows lingering in the DB.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Cache_Preload {

    /* ───────────────────────────────────────────────
     *  Queue identity
     * ─────────────────────────────────────────────── */

    /** Queue group. One per logical workload; preload is one group, future
     *  image-opt / db-opt jobs will be their own groups in the same table. */
    const QUEUE_GROUP = 'preload';

    /** Callback hook fired by the queue runner for the URL-warming task.
     *  Receives a task payload `array('url' => string)`. */
    const HOOK_WARM_URL = 'easyopt_preload_warm_url';

    /** Callback hook for the queue-build task — the heavy one that runs
     *  collect_urls + filter_already_cached + filter_out_redirects and
     *  enqueues per-URL warms. Receives an empty payload. */
    const HOOK_BUILD_QUEUE = 'easyopt_preload_build_queue';

    // (2.5.5) CPU_PAUSE_THRESHOLD / CPU_CONTINUE_THRESHOLD removed. The
    // /proc metrics behind the CPU/memory gates (loadavg, meminfo) are
    // HOST-scoped on shared hosting while the core-count denominator is
    // TENANT-scoped (CageFS-virtualised cpuinfo, cgroup quota) — a busy
    // NEIGHBOUR paused this site's preload with readings like "cpu:114%"
    // while the account's own cPanel CPU sat at 4%. The tenant-scoped
    // truth is the site's own responses, and that already drives pacing:
    // the EWMA/AIMD latency governor plus governor_record()'s 429/5xx
    // congestion handling with Retry-After, and (2.5.5) WAF-challenge
    // backoff in task_warm_url().

    /** Default queue concurrency for preload. 1 means sequential — best
     *  for shared hosts. Override via filter `easyopt_preload_concurrency`
     *  or constant `EASYOPT_PRELOAD_CONCURRENCY`. */
    /** Default runner concurrency for the warm group.
     *  1.7.1 — reverted to 1 (was 2 in 1.6.1). Sequential processing
     *  is far safer on shared hosting and low-resource servers. Power
     *  users can still set define('EASYOPT_PRELOAD_CONCURRENCY', 2)
     *  or use the `easyopt_preload_concurrency` filter. Matches
     *  default of 1. */
    const DEFAULT_CONCURRENCY = 1;

    /**
     * Warm-priority tiers (lower number = claimed sooner). The queue claims
     * ORDER BY priority ASC, then FIFO, so the cache warms in this order:
     * homepage → menu/navigation pages → posts/pages/products → taxonomy
     * archives (categories/tags). The most important pages are hot first even
     * on a 25k-URL site where a full warm takes hours.
     */
    const PRIO_HOME = 5;
    const PRIO_MENU = 10;
    const PRIO_POST = 20;
    const PRIO_TERM = 30;

    /**
     * url => priority map produced as a side-effect of collect_urls() and read
     * by task_build_queue() in the same request. Avoids changing collect_urls()'
     * flat-list return (which filter_already_cached() and the count-only caller
     * both rely on).
     *
     * @var array<string,int>
     */
    private static $collected_priorities = array();

    /** (2.5.4 / perf #13) url => post ID recorded at collection time, carried
     *  into warm-task payloads so the runner's optimization pass no longer
     *  needs a url_to_postid() (multiple LIKE queries) per warmed URL. */
    private static $collected_post_ids = array();

    /** (2.5.4 / perf #13) url => post ID for tasks in-flight this process. */
    private static $task_post_ids = array();

    /** Retries per warm task before giving up (and counting as 'failed'
     *  in the queue table, not silently lost). */
    const WARM_MAX_RETRIES = 3;

    /** Retries for the build_queue task. Lower than warm because if
     *  collect_urls is breaking, retrying won't help. */
    /** 1.6.1 — bumped 1 → 3. The build task does heavy lifting
     *  (sitemap fetch, redirect-filter HEAD probes) that can occasionally
     *  exceed TASK_LOCK_TIMEOUT on slow upstreams. A single reclaim
     *  no longer immediately marks the build 'failed'. The "suspenders"
     *  partner: collect_urls now also calls EasyOpt_Queue::touch_lock()
     *  during its long phases to avoid the reclaim in the first place. */
    const BUILD_MAX_RETRIES = 3;

    /** Legacy hook names — only kept here so init() can scrub leftover
     *  WP-Cron and AS state at upgrade time. Not registered as handlers. */
    const LEGACY_HOOK         = 'easyopt_cache_preload_tick';
    const LEGACY_ACTION_GROUP = 'easyopt-preload';

    /* ───────────────────────────────────────────────
     *  Queue instance (single, lazy-instantiated)
     * ─────────────────────────────────────────────── */

    /**
     * Memoized queue for the preload group. Lazy so we don't construct
     * (and register concurrency config) during plugin bootstrap before
     * options are loaded.
     */
    private static function warm_queue() {
        static $q = null;
        if ( null === $q ) {
            $q = new EasyOpt_Queue(
                self::QUEUE_GROUP,
                self::HOOK_WARM_URL,
                self::WARM_MAX_RETRIES,
                self::resolve_concurrency()
            );
        }
        return $q;
    }

    private static function build_queue_instance() {
        static $q = null;
        if ( null === $q ) {
            $q = new EasyOpt_Queue(
                self::QUEUE_GROUP,
                self::HOOK_BUILD_QUEUE,
                self::BUILD_MAX_RETRIES,
                1 // build is always single-runner
            );
        }
        return $q;
    }

    private static function resolve_concurrency() {
        $c = defined( 'EASYOPT_PRELOAD_CONCURRENCY' )
            ? (int) EASYOPT_PRELOAD_CONCURRENCY
            : self::DEFAULT_CONCURRENCY;
        $c = (int) apply_filters( 'easyopt_preload_concurrency', $c );
        return max( 1, min( EasyOpt_Queue::MAX_CONCURRENCY, $c ) );
    }

    /* ───────────────────────────────────────────────
     *  Adaptive preload governor (AIMD)
     *
     *  Auto-tunes concurrency + inter-task delay to the host's real capacity:
     *  additive-increase on a clean streak, multiplicative-decrease (halve) on
     *  any congestion signal (429 / 5xx / network error / very slow response),
     *  plus a hard pause that honours Retry-After. Bounds come from the
     *  Gentle / Balanced / Turbo "preload speed" setting — the user picks a
     *  safety envelope and the governor optimises within it. State lives in one
     *  non-autoloaded option shared by all runner processes (last-write-wins is
     *  fine for a heuristic). Preload-only: wired via the easyopt_queue_*
     *  filters, so other queue groups are untouched. Define
     *  EASYOPT_PRELOAD_CONCURRENCY to pin a fixed concurrency and disable it.
     * ─────────────────────────────────────────────── */

    const GOV_OPTION          = 'easyopt_preload_governor';
    // (2.6.0) 15000 → 5000. A site whose pages take fifteen seconds to render
    // has been unusable for a long time already: every visitor arriving in
    // that window sees a page that only just crosses into "struggling" by the
    // old definition. The plugin's own product target is LCP ≤ 2.5s, so a
    // render-latency ceiling in the same order lets the governor back off
    // while the site is still serving acceptably rather than after it has
    // stopped. Filterable for origins that are legitimately slow uncached
    // (heavy WooCommerce archives).
    const GOV_LATENCY_CEIL_MS = 5000;
    const GOV_RAMP_AFTER      = 12;    // clean responses before a step up (fallback when no latency samples yet).

    /** (2.4.x preload-engine v2) Response-time-driven tuning.
     *  When we have a measured average render latency (EWMA over real
     *  *blocking* warms/probes), the governor sets concurrency directly from
     *  it instead of creeping +1 every GOV_RAMP_AFTER cleans — so it reaches
     *  the right operating point in ~one control tick rather than never.
     *  target_c ≈ clamp( K / avg_ms ), so a ~500 ms origin runs at the mode
     *  ceiling and a multi-second origin backs off. AIMD backoff on any
     *  congestion signal still overrides this downward. */
    const GOV_TARGET_K   = 25000; // ms. avg 500→50(→ceil); 3000→~8; 8000→~3.
    const GOV_AVG_ALPHA  = 0.30;  // EWMA weight for the newest sample.
    // (2.5.5) GOV_CPU_SOFT / GOV_MEM_SOFT removed with the load gates —
    // see the note above CPU pacing at the top of this constant block.

    private static function governor_bounds( $level ) {
        switch ( $level ) {
            case 'gentle':
                return array( 'start_c' => 1, 'min_c' => 1, 'max_c' => 2, 'd_floor' => 0.5, 'd_ceil' => 1.5, 'd_start' => 0.7 );
            case 'turbo':
                // (2.5.4 / perf #11) d_floor 0.1 → 0.05: on a healthy origin
                // the response-time signal converges to the floor, and the old
                // floor alone added ~3 min of pure sleep per 2,000 URLs. The
                // AIMD backoff + soft CPU/memory throttle still lengthen the
                // delay the moment anything strains — the floor only caps how
                // fast a *provably calm* run may go.
                return array( 'start_c' => 3, 'min_c' => 1, 'max_c' => 8, 'd_floor' => 0.05, 'd_ceil' => 0.8, 'd_start' => 0.2 );
            case 'auto':
                // Self-tuning envelope: wide bounds, the response-time signal
                // + soft CPU/mem throttle pick the operating point live. Safe
                // default behaviour is Balanced-like until samples arrive.
                // (2.5.4 / perf #11) d_floor 0.2 → 0.05 — see turbo note; auto
                // mode is self-tuning by definition, so the measured signal
                // (not the floor) is the real limiter. Gentle/Balanced floors
                // are unchanged: their delay IS the product promise.
                return array( 'start_c' => 2, 'min_c' => 1, 'max_c' => 6, 'd_floor' => 0.05, 'd_ceil' => 1.2, 'd_start' => 0.4 );
            case 'balanced':
            default:
                return array( 'start_c' => 2, 'min_c' => 1, 'max_c' => 4, 'd_floor' => 0.3, 'd_ceil' => 1.0, 'd_start' => 0.4 );
        }
    }

    /** EWMA of measured render latency (ms) for the current run, or 0 when we
     *  have no sample yet. Lives in the governor state so all runners share it. */
    private static function governor_avg_ms() {
        $st = self::governor_state();
        return isset( $st['avg_ms'] ) ? (float) $st['avg_ms'] : 0.0;
    }

    /** Fold one measured latency sample into the shared EWMA. Only *blocking*
     *  warms/probes call this — fire-and-forget dispatches have no meaningful
     *  latency and must never feed the average (they'd read ~0 and wrongly
     *  peg concurrency). */
    private static function governor_record_latency( $latency_ms ) {
        if ( null === $latency_ms || (float) $latency_ms <= 0.0 ) {
            return;
        }
        $st  = self::governor_state();
        $cur = isset( $st['avg_ms'] ) ? (float) $st['avg_ms'] : 0.0;
        $new = ( $cur <= 0.0 )
            ? (float) $latency_ms
            : ( $cur * ( 1 - self::GOV_AVG_ALPHA ) + (float) $latency_ms * self::GOV_AVG_ALPHA );
        if ( abs( $new - $cur ) >= 1.0 ) {
            $st['avg_ms'] = round( $new, 1 );
            self::governor_save( $st );
        }
    }

    /** Concurrency target derived from the measured average, clamped to the
     *  mode envelope. Returns null when there is no sample yet (caller then
     *  keeps the legacy additive-increase behaviour). */
    private static function governor_target_c( $b ) {
        $avg = self::governor_avg_ms();
        if ( $avg <= 0.0 ) {
            return null;
        }
        $t = (int) round( self::GOV_TARGET_K / max( 1.0, $avg ) );
        return max( (int) $b['min_c'], min( (int) $b['max_c'], $t ) );
    }

    /** Delay target derived from the measured average, clamped to the mode
     *  envelope. Faster origin ⇒ shorter delay. */
    private static function governor_target_d( $b ) {
        $avg = self::governor_avg_ms();
        if ( $avg <= 0.0 ) {
            return null;
        }
        // ~ one delay-second per ~4 s of render, inside the envelope.
        $d = $avg / 4000.0;
        return max( (float) $b['d_floor'], min( (float) $b['d_ceil'], round( $d, 3 ) ) );
    }

    /** (2.5.5) Pressure factor is a constant 1.0 now that the CPU/memory
     *  soft ease-off is removed (host-scoped metrics — see the note by the
     *  removed constants). The function is retained so callers keep a
     *  single multiply; latency and HTTP congestion pace the governor. */
    private static function governor_pressure_factor() {
        $factor = 1.0;
        return max( 0.25, $factor );
    }

    private static function governor_level() {
        // (2.5.4) Default 'gentle' — matches the registry default so the
        // fallback here (used only if the key is somehow unresolved or
        // Config is unavailable on a cold path) can't disagree with it.
        return class_exists( 'EasyOpt_Config' )
            ? (string) EasyOpt_Config::get( 'cache_preload_speed', 'gentle' )
            : 'gentle';
    }

    /** Read governor state, (re)initialising when empty or the level changed.
     *  Cross-process sharing: when a persistent object cache is present the
     *  state lives there (a short-TTL key) and the DB option is only touched
     *  on change — cutting the per-warm `wp_options` write + per-read cache
     *  bust that used to hammer a single row at high concurrency. Without an
     *  object cache it falls back to the original option-with-cache-bust path,
     *  so behaviour is unchanged on those hosts. */
    /** (2.5.4 / perf #14) Per-process state cache + its read timestamp. */
    private static $gov_state_cache    = null;
    private static $gov_state_cache_at = 0.0;

    /** Seconds a runner may trust its in-process copy of the shared state.
     *  Cross-process coordination (concurrency target, pause) tolerates this
     *  staleness by design — it's a heuristic, and every save refreshes the
     *  copy immediately. On non-object-cache hosts this collapses the
     *  cache-bust + option SELECT that used to run per warm AND per delay
     *  filter AND per pause filter into at most one read per window. */
    const GOV_STATE_TTL = 5.0;

    private static function governor_state() {
        // (2.5.4 / perf #14) Serve the recent in-process copy when fresh.
        if ( is_array( self::$gov_state_cache )
            && ( microtime( true ) - self::$gov_state_cache_at ) < self::GOV_STATE_TTL
            && ( isset( self::$gov_state_cache['level'] ) ? self::$gov_state_cache['level'] : '' ) === self::governor_level() ) {
            return self::$gov_state_cache;
        }

        $level = self::governor_level();
        $b     = self::governor_bounds( $level );

        $ext = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
        $st  = false;
        if ( $ext ) {
            $st = wp_cache_get( self::GOV_OPTION, 'easyopt' );
        }
        if ( false === $st || ! is_array( $st ) ) {
            // Bust the per-process options cache so runners see each other's writes.
            wp_cache_delete( self::GOV_OPTION, 'options' );
            $st = get_option( self::GOV_OPTION, array() );
        }

        if ( ! is_array( $st ) || empty( $st ) || ( isset( $st['level'] ) ? $st['level'] : '' ) !== $level ) {
            $st = array(
                'level'        => $level,
                'c'            => $b['start_c'],
                'd'            => $b['d_start'],
                'ok'           => 0,
                'paused_until' => 0,
                'avg_ms'       => 0.0,
                't'            => time(),
            );
            update_option( self::GOV_OPTION, $st, false );
            if ( $ext ) {
                wp_cache_set( self::GOV_OPTION, $st, 'easyopt', 120 );
            }
        }
        self::$gov_state_cache    = $st;                  // (2.5.4 / perf #14)
        self::$gov_state_cache_at = microtime( true );
        return $st;
    }

    /** Persist governor state. Writes the DB option only when a field actually
     *  changed (last-write-wins is fine for a heuristic), and mirrors to the
     *  object cache when available. Keeps write traffic proportional to real
     *  state transitions rather than to warm count. */
    private static function governor_save( $st ) {
        $st['t'] = time();
        // (2.5.4 / perf #14) A save is authoritative for this process.
        self::$gov_state_cache    = $st;
        self::$gov_state_cache_at = microtime( true );
        $ext     = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
        if ( $ext ) {
            wp_cache_set( self::GOV_OPTION, $st, 'easyopt', 120 );
        }

        // Compare against what's on disk (ignoring the timestamp) and write
        // only on a meaningful change.
        wp_cache_delete( self::GOV_OPTION, 'options' );
        $prev = get_option( self::GOV_OPTION, array() );
        $a    = $st;   unset( $a['t'] );
        $b    = is_array( $prev ) ? $prev : array();   unset( $b['t'] );
        if ( $a !== $b ) {
            update_option( self::GOV_OPTION, $st, false );
        }
    }

    /** Reset to the current level's starting point (called when a run begins). */
    public static function governor_reset() {
        self::$gov_state_cache    = null; // (2.5.4 / perf #14)
        self::$gov_state_cache_at = 0.0;
        delete_option( self::GOV_OPTION );
        if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
            wp_cache_delete( self::GOV_OPTION, 'easyopt' );
        }
    }

    /** Record one warm outcome and apply the control step.
     *
     *  $latency_ms is the measured request time for *blocking* warms/probes
     *  (null for fire-and-forget dispatches, which carry no useful latency).
     *  When a measured average exists, concurrency + delay are set directly
     *  from it (fast convergence); otherwise we fall back to the original
     *  additive-increase / multiplicative-decrease creep. A soft CPU/memory
     *  factor then trims the result — it can only lower load, never raise it. */
    public static function governor_record( $latency_ms, $http_code, $retry_after = 0 ) {
        $b  = self::governor_bounds( self::governor_level() );

        // Fold the sample into the shared EWMA first (blocking samples only).
        self::governor_record_latency( $latency_ms );

        $st = self::governor_state();

        $ceiling = (float) apply_filters( 'easyopt_preload_latency_ceiling_ms', self::GOV_LATENCY_CEIL_MS );

        $congested = ( 429 === (int) $http_code )
            || ( (int) $http_code >= 500 )
            || ( (int) $http_code <= 0 )
            || ( null !== $latency_ms && (float) $latency_ms > $ceiling );

        if ( $congested ) {
            // Multiplicative decrease + lengthen the delay; drop the streak.
            $st['c']  = max( $b['min_c'], (int) floor( (int) $st['c'] / 2 ) );
            $st['d']  = min( $b['d_ceil'], round( (float) $st['d'] * 1.5, 3 ) );
            $st['ok'] = 0;
            if ( (int) $retry_after > 0 ) {
                $st['paused_until'] = time() + min( 120, (int) $retry_after );
            }
        } else {
            $target_c = self::governor_target_c( $b );
            $target_d = self::governor_target_d( $b );
            if ( null !== $target_c ) {
                // Response-time-driven: move toward the target. Step up by at
                // most 1/tick (gentle ramp so we never spike the origin), snap
                // down immediately if the target dropped.
                $cur = (int) $st['c'];
                if ( $target_c > $cur ) {
                    $st['ok'] = (int) $st['ok'] + 1;
                    if ( (int) $st['ok'] >= 3 ) { // brief confirmation before a step up
                        $st['c']  = min( $target_c, $cur + 1 );
                        $st['ok'] = 0;
                    }
                } else {
                    $st['c']  = $target_c;
                    $st['ok'] = 0;
                }
                if ( null !== $target_d ) {
                    $st['d'] = $target_d;
                }
            } else {
                // Legacy additive-increase (no latency samples yet).
                $st['ok'] = (int) $st['ok'] + 1;
                if ( (int) $st['ok'] >= self::GOV_RAMP_AFTER ) {
                    $st['c']  = min( $b['max_c'], (int) $st['c'] + 1 );
                    $st['d']  = max( $b['d_floor'], round( (float) $st['d'] - 0.05, 3 ) );
                    $st['ok'] = 0;
                }
            }
        }

        // Soft CPU/memory throttle (Phase 6): trim concurrency + lengthen delay
        // under pressure, always staying within the mode envelope. Trim only.
        $factor = self::governor_pressure_factor();
        if ( $factor < 1.0 ) {
            $st['c'] = max( (int) $b['min_c'], (int) floor( (int) $st['c'] * $factor ) );
            $eased   = round( (float) $st['d'] * ( 2.0 - $factor ), 3 ); // factor<1 ⇒ >1 multiplier
            $st['d'] = min( (float) $b['d_ceil'], max( (float) $b['d_floor'], $eased ) );
        }

        self::governor_save( $st );
    }

    /* ───────────────────────────────────────────────
     *  In-flight budget
     *
     *  WHY THIS EXISTS:
     *  The fire-and-forget warm path dispatches a render and returns without
     *  waiting, so the number of renders actually EXECUTING at once was never
     *  counted anywhere. It settled at (dispatch rate x render time), which
     *  means the slower the host, the more PHP workers preload occupied —
     *  precisely backwards. On a small FPM pool that starves wp-admin, the
     *  REST dashboard and ordinary visitors until the run finishes.
     *
     *  A ceiling per speed level fixes the units. The setting now describes
     *  SIMULTANEOUS RENDERS, which is what the Gentle label ("one page at a
     *  time") always claimed. Throughput then falls out of the host's own
     *  speed for free — at a ceiling of 3, a 0.5 s origin sustains ~6 URL/s
     *  and a 4 s origin ~0.75 URL/s — with no measurement, no telemetry and
     *  no host probing involved. That is what makes it safe to ship to hosts
     *  we cannot inspect.
     *
     *  BOOKKEEPING:
     *  A { token => expiry } map in the object cache, mirroring the queue's
     *  runner heartbeat registry. The RUNNER is the only writer of the map
     *  (concurrency is pinned to 1 whenever this path is live — see
     *  filter_queue_concurrency), and each render worker only ever writes its
     *  own unique release key, so the two can never clobber each other. A
     *  render that dies without releasing just expires out of the map, which
     *  fails in the SAFE direction: the slot comes back late rather than
     *  leaking forever and stalling the run.
     * ─────────────────────────────────────────────── */

    const INFLIGHT_KEY = 'easyopt_preload_inflight';

    /** A slot is reclaimed this many seconds after dispatch even if the render
     *  never reported back (fatal, host kill, worker recycle). Comfortably
     *  longer than the 12 s warm timeout so a slow-but-alive render is never
     *  double-counted. */
    const INFLIGHT_TTL = 45;

    /** Hard ceiling on simultaneous preload renders, per speed level. */
    private static function inflight_ceiling() {
        switch ( self::governor_level() ) {
            case 'gentle':
                return 1;
            case 'turbo':
                return 5;
            case 'balanced':
            default:
                return 3;
        }
    }

    /** Effective budget: the level's ceiling, lowered (never raised) by the
     *  governor's current concurrency, so every existing back-off signal —
     *  429 / 5xx / Retry-After / WAF challenge / latency ceiling — still
     *  throttles the run exactly as it does today. The governor keeps its
     *  meaning; it simply steers a number that now bounds real load. */
    public static function inflight_cap() {
        $st  = self::governor_state();
        $gov = isset( $st['c'] ) ? max( 1, (int) $st['c'] ) : 1;
        $cap = min( self::inflight_ceiling(), $gov );

        // (2.5.7) On the BLOCKING path each unit of concurrency costs TWO
        // PHP-FPM children, not one: the runner holds a child for the whole
        // RUNNER_WINDOW_SECONDS while it waits, and servicing the loopback it
        // is waiting for needs a second child. The concurrency model counted
        // only runners, so on a small pool a preload run could occupy every
        // available child — runners blocking on renders that could not be
        // scheduled because the runners held the pool. That presents exactly
        // as reported: whole-site degradation, ~35% CPU (workers blocked on
        // sockets, not computing) and upstream timeouts on dashboard-stats.
        if ( ! self::async_path_available() && ! self::batch_path_available() ) {
            $cap = min( $cap, self::pool_safe_concurrency() );
        }

        // Operator overrides for anyone who knows their pool size.
        if ( defined( 'EASYOPT_PRELOAD_MAX_INFLIGHT' ) ) {
            $cap = (int) EASYOPT_PRELOAD_MAX_INFLIGHT;
        }
        $cap = (int) apply_filters( 'easyopt_preload_max_inflight', $cap );

        return max( 1, min( EasyOpt_Queue::MAX_CONCURRENCY, $cap ) );
    }

    /**
     * Build an AUTHENTICATED warm URL.
     *
     * (2.6.0) Every preload loopback now carries an HMAC (`eosig`) bound to
     * its slot token, verified at the very top of easy-optimizer.php before
     * anything reads the URI. Without it, `?eopreload=1` was an unauthenticated
     * switch any visitor could flip to force a guaranteed cache MISS plus a
     * full render — and to disable two logged-in detection paths.
     *
     * Every warm/probe/capture request in this class must go through here;
     * a request that omits the signature is treated as an ordinary visitor.
     *
     * @since 2.6.0
     * @param string $url   Target URL.
     * @param string $slot  In-flight slot token, or '' when none is reserved.
     * @param array  $extra Additional query args (e.g. eonobuf).
     * @return string
     */
    private static function warm_url( $url, $slot = '', array $extra = array() ) {
        $args = array( 'eopreload' => '1' );
        if ( '' !== (string) $slot ) {
            $args['eoslot'] = (string) $slot;
        }
        if ( function_exists( 'easyopt_preload_signature' ) ) {
            $sig = easyopt_preload_signature( (string) $slot );
            if ( '' !== $sig ) {
                $args['eosig'] = $sig;
            }
        }
        foreach ( $extra as $k => $v ) {
            $args[ $k ] = $v;
        }
        return add_query_arg( $args, $url );
    }

    /**
     * Estimated ceiling on how many FPM children this preloader may occupy,
     * expressed in units of BLOCKING concurrency (each unit ≈ 2 children).
     *
     * PHP has no portable way to read pm.max_children, but memory_limit is
     * the quantity hosts size their pool from, so it is a usable proxy. We
     * deliberately claim only a fraction: on a 512 MB box with a 128 M limit
     * the pool is realistically 3-4 children, and a single unit already takes
     * two of them.
     *
     * @since 2.5.7
     * @return int
     */
    public static function pool_safe_concurrency() {

        $limit = self::bytes( ini_get( 'memory_limit' ) );

        // Unlimited or unreadable — assume a managed host with headroom, but
        // stay conservative; the governor can still ramp within its bounds.
        if ( $limit <= 0 ) {
            return 2;
        }
        if ( $limit <= 134217728 ) {   // <=128M — small box
            return 1;
        }
        if ( $limit <= 268435456 ) {   // <=256M
            return 2;
        }
        return 3;
    }

    /**
     * Parse a PHP shorthand byte value ("256M") into bytes.
     *
     * @since 2.5.7
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

    /**
     * Is a cross-process slot store available?
     *
     * (2.6.0) Now true on virtually every host. Previously this required a
     * persistent object cache, which meant fire-and-forget — the ONE dispatch
     * path where a runner does not hold an FPM child while it waits — was
     * unavailable precisely on the small VPS and shared hosts that can least
     * afford the blocking path's two-children-per-unit cost. The stated reason
     * (no atomic store without a per-dispatch DB write) overlooked atomic
     * filesystem primitives: fopen(…, 'x') is atomic create-if-absent, and the
     * plugin already writes to the cache directory on every MISS.
     */
    private static function inflight_available() {
        if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
            return true;
        }
        return self::inflight_fs_available();
    }

    /** True when the object cache is the active slot backend. */
    private static function inflight_use_object_cache() {
        return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();
    }

    /** Directory holding one file per in-flight render slot. */
    private static function inflight_dir() {
        return trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt/slots/';
    }

    /** Can we use the filesystem slot backend on this host? */
    private static function inflight_fs_available() {
        static $ok = null;
        if ( null !== $ok ) {
            return $ok;
        }
        $dir = self::inflight_dir();
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
            // Never browsable/listable — same guard the config dir gets.
            if ( is_dir( $dir ) && ! file_exists( $dir . 'index.html' ) ) {
                @file_put_contents( $dir . 'index.html', '', LOCK_EX );
            }
        }
        $ok = ( is_dir( $dir ) && is_writable( $dir ) );
        return $ok;
    }

    /**
     * Count live slots on the filesystem backend, reclaiming expired ones.
     *
     * Bounded by MAX_CONCURRENCY entries, so the glob is O(8).
     *
     * @return int
     */
    private static function inflight_fs_count() {
        $dir = self::inflight_dir();
        $now = time();
        $live = 0;
        foreach ( (array) glob( $dir . '*.slot' ) as $slot ) {
            if ( (int) @filemtime( $slot ) + self::INFLIGHT_TTL <= $now ) {
                @unlink( $slot ); // aged out — that render died or never started
                continue;
            }
            $live++;
        }
        return $live;
    }

    /**
     * Reserve a slot on the filesystem backend.
     *
     * fopen(…, 'x') is atomic create-if-absent, so two runners racing for the
     * last slot cannot both win.
     *
     * @return string Token, or '' when the budget is full.
     */
    private static function inflight_fs_acquire() {
        if ( ! self::inflight_fs_available() ) {
            return '';
        }
        if ( self::inflight_fs_count() >= self::inflight_cap() ) {
            return '';
        }
        $token = md5( uniqid( (string) wp_rand( 0, PHP_INT_MAX ), true ) );
        $fh    = @fopen( self::inflight_dir() . $token . '.slot', 'x' );
        if ( false === $fh ) {
            return '';
        }
        fclose( $fh );
        return $token;
    }

    /** Release a filesystem slot. Token shape is enforced before touching disk. */
    private static function inflight_fs_release( $token ) {
        if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
            return;
        }
        @unlink( self::inflight_dir() . $token . '.slot' );
    }

    /** Drop every filesystem slot (run start / stop). */
    private static function inflight_fs_reset() {
        if ( ! self::inflight_fs_available() ) {
            return;
        }
        foreach ( (array) glob( self::inflight_dir() . '*.slot' ) as $slot ) {
            @unlink( $slot );
        }
    }

    /** Live slot map, pruned of expired and released entries. Called only by
     *  the runner, which is the map's single writer.
     *
     *  @param bool $persist Write the pruned map back.
     *  @return array token => expiry
     */
    private static function inflight_live( $persist = true ) {
        $map = wp_cache_get( self::INFLIGHT_KEY, 'easyopt' );
        if ( ! is_array( $map ) || empty( $map ) ) {
            return array();
        }
        $now  = time();
        $live = array();
        foreach ( $map as $token => $exp ) {
            if ( (int) $exp <= $now ) {
                continue; // aged out — that render died or never started.
            }
            if ( false !== wp_cache_get( 'eoslot_' . $token, 'easyopt' ) ) {
                continue; // the render reported itself finished.
            }
            $live[ $token ] = (int) $exp;
        }
        if ( $persist && count( $live ) !== count( $map ) ) {
            wp_cache_set( self::INFLIGHT_KEY, $live, 'easyopt', self::INFLIGHT_TTL * 2 );
        }
        return $live;
    }

    /** How many preload renders are believed to be executing right now. */
    public static function inflight_count() {
        if ( ! self::inflight_available() ) {
            return 0;
        }
        if ( ! self::inflight_use_object_cache() ) {
            return self::inflight_fs_count();
        }
        return count( self::inflight_live() );
    }

    /** Reserve a slot. Returns the token, or '' when the budget is full — in
     *  which case the caller must NOT dispatch a non-blocking warm. */
    private static function inflight_acquire() {
        if ( ! self::inflight_available() ) {
            return '';
        }
        if ( ! self::inflight_use_object_cache() ) {
            return self::inflight_fs_acquire();
        }
        $live = self::inflight_live( false );
        if ( count( $live ) >= self::inflight_cap() ) {
            return '';
        }
        $token          = md5( uniqid( (string) wp_rand( 0, PHP_INT_MAX ), true ) );
        $live[ $token ] = time() + self::INFLIGHT_TTL;
        wp_cache_set( self::INFLIGHT_KEY, $live, 'easyopt', self::INFLIGHT_TTL * 2 );
        return $token;
    }

    /** Publish a release marker under this slot's own key. The runner folds it
     *  in on its next poll. Writing a PRIVATE key — never the shared map — is
     *  what keeps concurrent render workers from overwriting each other or the
     *  runner's acquisitions. */
    public static function inflight_release( $token ) {
        if ( ! is_string( $token ) || '' === $token || ! self::inflight_available() ) {
            return;
        }
        if ( ! self::inflight_use_object_cache() ) {
            self::inflight_fs_release( $token );
            return;
        }
        wp_cache_set( 'eoslot_' . $token, 1, 'easyopt', self::INFLIGHT_TTL * 2 );
    }

    /** Called on shutdown of a preload RENDER request (registered in init()
     *  only when this request carries a slot token). Runs at the very end so
     *  the slot frees once the page really is done — including the cache
     *  write, which happens in the output buffer. */
    public static function release_own_slot() {
        if ( defined( 'EASYOPT_PRELOAD_SLOT' ) && '' !== (string) EASYOPT_PRELOAD_SLOT ) {
            self::inflight_release( (string) EASYOPT_PRELOAD_SLOT );
        }
    }

    /** Drop every outstanding slot (run start / stop) so a new run never
     *  inherits stale bookkeeping from an interrupted one. */
    public static function inflight_reset() {
        if ( ! self::inflight_available() ) {
            return;
        }
        if ( ! self::inflight_use_object_cache() ) {
            self::inflight_fs_reset();
            return;
        }
        wp_cache_delete( self::INFLIGHT_KEY, 'easyopt' );
    }

    /** True when the fire-and-forget path is structurally usable on this host.
     *  Side-effect free — unlike should_fire_and_forget(), which consumes the
     *  once-a-minute probe slot — so filters and the runner's admission gate
     *  can call it freely. */
    public static function async_path_available() {
        if ( ! self::fire_and_forget_enabled() ) {
            return false;
        }
        if ( ! self::fire_and_forget_confirmed() ) {
            return false;
        }
        if ( self::live_buffer_skipped() ) {
            return false;
        }
        // No shared counter → no way to bound in-flight renders here.
        return self::inflight_available();
    }

    /** True when the curl_multi capture path will be used for this group. */
    private static function batch_path_available() {
        return function_exists( 'curl_multi_init' )
            && (bool) apply_filters( 'easyopt_preload_batch_capture', true )
            && self::live_buffer_skipped();
    }

    /* Filter callbacks — preload group only. */

    public static function filter_queue_concurrency( $c, $group, $callback ) {
        if ( self::QUEUE_GROUP !== $group ) {
            return $c;
        }
        // An explicit constant override pins concurrency and disables the
        // governor ($c already reflects resolve_concurrency()/the constant).
        if ( defined( 'EASYOPT_PRELOAD_CONCURRENCY' ) ) {
            return $c;
        }
        // Concurrency used to mean "runner processes", which on the
        // fire-and-forget path bounded nothing: one runner could have any
        // number of renders outstanding. The in-flight budget is the limit
        // now, so runners are only multiplied on the paths where a runner IS
        // the limit — and each runner costs an FPM child for the whole
        // RUNNER_WINDOW_SECONDS, so spawning ones that only wait is pure loss:
        //
        //   fire-and-forget  → 1 runner holding up to inflight_cap() renders
        //   curl_multi batch → 1 runner; the batch size carries the budget
        //   blocking warm    → inflight_cap() runners, one render each
        if ( self::async_path_available() || self::batch_path_available() ) {
            return 1;
        }
        return self::inflight_cap();
    }

    /**
     * Admission gate for the runner loop. Only the fire-and-forget path needs
     * one: the blocking and curl_multi paths are self-limiting, because the
     * runner is still inside the request it dispatched. Returning false parks
     * the runner briefly rather than failing or deferring a task.
     *
     * @param bool   $can
     * @param string $group
     * @param string $callback
     * @return bool
     */
    public static function filter_queue_can_claim( $can, $group, $callback ) {
        if ( self::QUEUE_GROUP !== $group || ! $can ) {
            return $can;
        }
        if ( ! self::async_path_available() ) {
            return $can;
        }
        return self::inflight_count() < self::inflight_cap();
    }

    public static function filter_queue_delay( $delay, $group = '' ) {
        if ( self::QUEUE_GROUP !== $group || defined( 'EASYOPT_PRELOAD_CONCURRENCY' ) ) {
            return $delay;
        }
        $st = self::governor_state();
        return (float) $st['d'];
    }

    public static function filter_queue_pause_until( $until, $group, $callback ) {
        if ( self::QUEUE_GROUP !== $group ) {
            return $until;
        }
        $st = self::governor_state();
        return (int) ( isset( $st['paused_until'] ) ? $st['paused_until'] : 0 );
    }

    /**
     * (2.5.5) Identify a WAF/challenge vendor from response headers/body.
     * Returns a short vendor name ('' when this doesn't look like a
     * challenge). Header names per vendor docs; the body sniff is a
     * fallback for edges that strip identifying headers.
     *
     * @param array|\WP_Error $response wp_remote_* response.
     * @return string
     */
    private static function waf_vendor_from_response( $response ) {

        $server = strtolower( (string) wp_remote_retrieve_header( $response, 'server' ) );

        if ( '' !== (string) wp_remote_retrieve_header( $response, 'x-sucuri-id' )
            || '' !== (string) wp_remote_retrieve_header( $response, 'x-sucuri-cache' )
            || false !== strpos( $server, 'sucuri' ) ) {
            return 'Sucuri';
        }
        if ( '' !== (string) wp_remote_retrieve_header( $response, 'cf-ray' )
            || '' !== (string) wp_remote_retrieve_header( $response, 'cf-mitigated' )
            || false !== strpos( $server, 'cloudflare' ) ) {
            return 'Cloudflare';
        }
        if ( false !== strpos( $server, 'awselb' )
            || '' !== (string) wp_remote_retrieve_header( $response, 'x-amzn-waf-action' ) ) {
            return 'AWS WAF';
        }

        $body = (string) wp_remote_retrieve_body( $response );
        if ( '' !== $body && strlen( $body ) < 20000 ) {
            $lb = strtolower( $body );
            if ( false !== strpos( $lb, 'sucuri website firewall' ) ) {
                return 'Sucuri';
            }
            if ( false !== strpos( $lb, 'cloudflare' ) && false !== strpos( $lb, 'attention required' ) ) {
                return 'Cloudflare';
            }
        }

        return '';
    }

    /** Read the Retry-After header (delta-seconds or HTTP-date) → seconds. */
    private static function parse_retry_after( $response ) {
        $ra = wp_remote_retrieve_header( $response, 'retry-after' );
        if ( '' === $ra || null === $ra ) {
            return 0;
        }
        if ( is_numeric( $ra ) ) {
            return max( 0, (int) $ra );
        }
        $ts = strtotime( (string) $ra );
        return ( $ts && $ts > time() ) ? ( $ts - time() ) : 0;
    }

    /**
     * Same-origin primary-navigation menu URLs. Used by collect_urls() so the
     * whole nav is warmed in its own priority tier — including custom-link and
     * archive menu items that the public post-type crawl would otherwise miss.
     * Best-effort: any failure just yields fewer URLs, never an error.
     *
     * @return string[]
     */
    private static function menu_urls() {
        $out = array();

        if ( ! function_exists( 'wp_get_nav_menus' ) || ! function_exists( 'wp_get_nav_menu_items' ) ) {
            return $out;
        }
        $home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        $home_scheme = ( 'https' === strtolower( (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) ) ) ? 'https' : 'http';

        foreach ( (array) wp_get_nav_menus() as $menu ) {
            if ( empty( $menu->term_id ) ) {
                continue;
            }
            $items = wp_get_nav_menu_items( $menu->term_id );
            if ( ! is_array( $items ) ) {
                continue;
            }
            foreach ( $items as $item ) {
                $u = isset( $item->url ) ? (string) $item->url : '';
                if ( '' === $u ) {
                    continue;
                }
                $u = trim( $u );
                // (2.5.0 / L3) Skip pure #anchors, but RESOLVE relative
                // menu links instead of dropping them — custom menu items
                // and some themes emit root-relative ("/contact") or
                // protocol-relative ("//host/path") URLs that the old
                // host-equality check discarded, silently under-warming
                // the menu (the most important pages on the site).
                if ( '' === $u || '#' === $u[0] ) {
                    continue;
                }
                if ( 0 === strpos( $u, '//' ) ) {
                    $u = $home_scheme . ':' . $u;
                } elseif ( 0 === strpos( $u, '/' ) ) {
                    $u = home_url( $u );
                }
                // Same-origin only — skip custom external links.
                $u_host = strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) );
                if ( '' === $u_host || self::strip_www( $u_host ) !== self::strip_www( $home_host ) ) {
                    continue;
                }
                $out[] = $u;
            }
        }
        return $out;
    }

    /* ───────────────────────────────────────────────
     *  Lifecycle
     * ─────────────────────────────────────────────── */

    public static function init() {
        // Task handlers (the queue runner calls these with the task payload).
        add_action( self::HOOK_WARM_URL,    array( __CLASS__, 'task_warm_url'    ), 10, 1 );
        add_action( self::HOOK_BUILD_QUEUE, array( __CLASS__, 'task_build_queue' ), 10, 1 );

        // Adaptive governor — drives concurrency / inter-task delay / pause for
        // the preload group only (other queue groups fall through unchanged).
        add_filter( 'easyopt_queue_concurrency', array( __CLASS__, 'filter_queue_concurrency' ), 10, 3 );
        add_filter( 'easyopt_queue_task_delay',  array( __CLASS__, 'filter_queue_delay' ),       10, 2 );
        add_filter( 'easyopt_queue_pause_until', array( __CLASS__, 'filter_queue_pause_until' ), 10, 3 );

        // Admission gate — the runner asks before CLAIMING a task, so a full
        // in-flight budget parks the runner inside its window instead of
        // claiming work it would have to defer (which would burn retries).
        add_filter( 'easyopt_queue_can_claim', array( __CLASS__, 'filter_queue_can_claim' ), 10, 3 );

        // This request IS a preload render carrying an in-flight slot: release
        // it at the very end, once the output buffer has written the cache.
        if ( defined( 'EASYOPT_PRELOAD_SLOT' ) && '' !== (string) EASYOPT_PRELOAD_SLOT ) {
            add_action( 'shutdown', array( __CLASS__, 'release_own_slot' ), PHP_INT_MAX );
        }

        // Preload start/stop/status, the waiting count and the state-version
        // poll are all served by the REST dashboard now
        // (/cache/preload-start, /cache/preload-stop, /cache/preload-status,
        // /dashboard-stats). The old admin-ajax twins were unused by the
        // React app and have been removed to shrink the surface.

        // on_preload_toggle() directly when the toggle appears in the
        // save diff. (Method still public; callable externally too.)

        // Preload health diagnostics (W2 stalled / W3 growth). Piggybacks on
        // the queue watchdog cron — no new schedule. One option read + write
        // per minute; logs only when the queue stalls or grows abnormally.
        add_action( 'easyopt_queue_watchdog', array( __CLASS__, 'watchdog_health_check' ) );

        // Phase 7 — traffic-aware retention. Opt-in stale page-cache pruning on
        // the existing daily GC tick. Disabled by default (TTL 0) so it can
        // never change behaviour or add load unless a site turns it on.
        add_action( 'easyopt_queue_gc', array( __CLASS__, 'gc_stale_cache' ) );

        // (2.5.0) Results-ledger integration — the queue emits these
        // authoritative signals; the preload module owns the reaction:
        //   - group drained  → maybe flip status to a CONFIRMED 'done'
        //   - task failed     → record the URL as failed (retryable)
        //   - daily GC        → revert stale failures + GC old rows
        add_action( 'easyopt_queue_group_drained', array( __CLASS__, 'on_group_drained' ), 10, 2 );
        add_action( 'easyopt_queue_task_failed',   array( __CLASS__, 'on_task_failed' ),    10, 4 );
        if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
            add_action( 'easyopt_queue_gc', array( 'EasyOpt_Preload_Results', 'revert_stale_failed' ) );
            add_action( 'easyopt_queue_gc', array( 'EasyOpt_Preload_Results', 'gc' ) );
        }

        // Phase 3/4 — concurrent-HTTP capture path. Register a batch handler for
        // the preload group and tell the queue how large a batch to claim. The
        // batch ONLY engages on the capture path (buffer flagged unreliable) and
        // when curl_multi is available — exactly where warming several full-body
        // fetches at once helps. On the common fire-and-forget path the size
        // stays 1, so the runner keeps its per-task loop. Disable via the
        // easyopt_preload_batch_capture filter.
        EasyOpt_Queue::register_batch_handler(
            self::QUEUE_GROUP,
            self::HOOK_WARM_URL,
            array( __CLASS__, 'warm_batch_multi' )
        );
        add_filter( 'easyopt_queue_batch_size', array( __CLASS__, 'filter_queue_batch_size' ), 10, 3 );

        self::maybe_run_legacy_cleanup();

        // Backward-compat: route any late-firing WP-Cron events from
        // pre-1.5.8 installs into the new start() so users don't end
        // up with a stuck preload after upgrading.
        add_action( 'easyopt_cache_preload_restart', array( __CLASS__, 'start' ) );
    }

    /**
     * Preload health check (W2/W3). Runs on the queue watchdog tick (~1/min).
     * Compares pending count to the previous tick:
     *   - W3: pending grew while status is 'running' → re-seeding faster than
     *     it drains (the fingerprint of a clear → re-crawl loop).
     *   - W2: pending unchanged and > 0 for N consecutive ticks → stalled.
     * Cost: one option read + one option write per minute. No filesystem work.
     */
    public static function watchdog_health_check() {
        if ( ! class_exists( 'EasyOpt_Debug_Log' ) ) {
            return;
        }

        // (2.5.4 / perf #21) Read the cheap status option BEFORE the pending
        // COUNT query. Outside a live/paused run there is nothing for any of
        // the checks below to observe — the per-minute tick becomes one
        // cached option read instead of a queue COUNT + reconcile work.
        $status = (string) get_option( 'easyopt_cache_preload_status', 'idle' );
        if ( 'running' !== $status && 'paused' !== $status ) {
            // (2.5.0 / B4) One exception: for 30 min after the done-flip,
            // in-flight fire-and-forget rows still need reconciling — keep
            // that path exactly as before, then take the fast exit.
            if ( 'done' === $status
                && get_transient( 'easyopt_preload_post_done_reconcile' )
                && class_exists( 'EasyOpt_Preload_Results' ) ) {
                EasyOpt_Preload_Results::reconcile();
            }
            return;
        }

        $pending = (int) self::warm_queue()->get_pending_count();

        $state = get_option( 'easyopt_preload_watch', array( 'pending' => -1, 'stalls' => 0 ) );
        if ( ! is_array( $state ) ) {
            $state = array( 'pending' => -1, 'stalls' => 0 );
        }
        $prev   = isset( $state['pending'] ) ? (int) $state['pending'] : -1;
        $stalls = isset( $state['stalls'] ) ? (int) $state['stalls'] : 0;

        // W3 — re-crawl loop. Queue growth ALONE is normal: a fresh preload
        // seeds 0 → N, and the build phase legitimately grows the queue. So we
        // only treat growth as a loop when it is corroborated by EXCESSIVE
        // FULL CACHE CLEARS in a short window (the actual loop cause — same
        // signal as the cache-side W1 detector). A normal start/build, or a
        // single legitimate clear + restart, never trips this.
        if ( 'running' === $status && $prev >= 0 && $pending > $prev ) {
            $clear_log = get_option( 'easyopt_clear_log', array() );
            if ( is_array( $clear_log ) ) {
                $now    = time();
                $window = (int) apply_filters( 'easyopt_purge_window', 600 );
                $limit  = (int) apply_filters( 'easyopt_purge_limit', 5 );
                $recent = 0;
                foreach ( $clear_log as $ts ) {
                    if ( ( $now - (int) $ts ) <= $window ) {
                        $recent++;
                    }
                }
                if ( $recent >= $limit ) {
                    EasyOpt_Debug_Log::warn( 'preload', sprintf(
                        'Preload queue re-seeding (%d → %d) alongside %d full cache clears in %ds — likely an invalidation/re-crawl loop.',
                        $prev, $pending, $recent, $window
                    ) );
                }
            }
        }

        // W2 — stalled. Only meaningful while preload is ACTIVELY running: an
        // idle/paused/finished queue legitimately sits unchanged and must not
        // warn. We also require the watchdog to have actually been firing
        // (status 'running') so a low-traffic site whose WP-Cron runs
        // irregularly doesn't get flagged. A genuine stall = running + work
        // pending + no movement across several ticks.
        //
        // (2.5.0 / M4) A governor PAUSE (Retry-After / CPU backoff) is
        // deliberate non-movement, not a stall. Detecting a pause here and
        // holding the stall counter unchanged prevents a false "stalled"
        // warning during a legitimate server-busy hold. The pause can come
        // from the AIMD governor (paused_until in the future) or the CPU
        // gate (paused_reason transient set).
        $gov          = self::governor_state();
        $gov_paused   = is_array( $gov ) && isset( $gov['paused_until'] ) && (int) $gov['paused_until'] > time();
        $cpu_paused   = '' !== (string) get_transient( 'easyopt_cache_preload_paused_reason' );
        $is_paused    = ( 'paused' === $status ) || $gov_paused || $cpu_paused;

        // (2.5.0 / B7) While paused, HOLD the counter — neither increment
        // (no false stall) nor reset (a real stall that began before the
        // pause is still caught once movement should have resumed).
        if ( ! $is_paused ) {
            if ( 'running' === $status && $pending > 0 && $pending === $prev ) {
                $stalls++;
            } else {
                $stalls = 0;
            }
        }
        $limit = (int) apply_filters( 'easyopt_preload_stall_ticks', 5 );
        if ( $stalls >= $limit ) {
            EasyOpt_Debug_Log::warn( 'preload', sprintf(
                'Preload stalled: %d URLs pending, unchanged across %d watchdog ticks while running.',
                $pending, $stalls
            ) );
            $stalls = 0; // reset so we warn periodically, not every tick
        }

        update_option( 'easyopt_preload_watch', array( 'pending' => $pending, 'stalls' => $stalls ), false );

        // (2.5.0 / C3) Ground-truth reconcile + completion, on the existing
        // per-minute tick (no new cron). Only while a run is ACTIVE — when
        // idle/done there is nothing to reconcile, so we skip the queries
        // entirely and keep the watchdog light.
        if ( 'running' === $status || 'paused' === $status ) {
            if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
                EasyOpt_Preload_Results::reconcile();
            }
            self::maybe_mark_done();
        } elseif ( 'done' === $status
            && get_transient( 'easyopt_preload_post_done_reconcile' )
            && class_exists( 'EasyOpt_Preload_Results' ) ) {
            // (2.5.0 / B4) Short post-completion window: rows still 'pending'
            // at the moment of the done-flip (fire-and-forget renders that
            // were in flight when the queue drained) used to freeze forever
            // because reconciliation stopped with the run. Keep reconciling
            // for 30 minutes after 'done' (transient set by maybe_mark_done)
            // so those last rows resolve to cached/failed; the pass is a
            // no-op empty SELECT once nothing is pending.
            EasyOpt_Preload_Results::reconcile();
        }
    }

    /**
     * One-time migration on first 1.6.0 boot (per version):
     *
     *  - Unschedule legacy WP-Cron events (1.5.7 and earlier)
     *  - Delete pre-1.6.0 AS pending rows for our former hooks
     *  - Delete the legacy flat-file queue (1.5.7 → 1.5.8 migrator)
     *
     * Gated by `easyopt_preload_migrated_version` so we run once per
     * upgrade, not on every init().
     */
    private static function maybe_run_legacy_cleanup() {
        $migrated = (string) get_option( 'easyopt_preload_migrated_version', '' );
        if ( $migrated === EASYOPT_VERSION ) {
            return;
        }

        wp_clear_scheduled_hook( self::LEGACY_HOOK );
        wp_clear_scheduled_hook( 'easyopt_cache_preload_restart' );

        if ( method_exists( 'EasyOpt_Queue', 'clear_legacy_action_scheduler_tasks' ) ) {
            EasyOpt_Queue::clear_legacy_action_scheduler_tasks();
        }

        // Legacy flat-file queue.
        $legacy_file = trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt/preload-queue.json';
        if ( file_exists( $legacy_file ) ) {
            @unlink( $legacy_file );
        }

        update_option( 'easyopt_preload_migrated_version', EASYOPT_VERSION, false );
    }

    /* ───────────────────────────────────────────────
     *  start / stop / toggle (public API)
     * ─────────────────────────────────────────────── */

    /**
     * Start (or restart) a preload run.
     *
     * Non-blocking: this method does no HTTP work, no sitemap parsing,
     * no `wp_remote_get`. It only:
     *
     *   1. Cancels any in-flight tasks (restart semantics)
     *   2. Resets `_total` to 0 and flips status to 'running'
     *   3. Inserts ONE row into wp_easyopt_queue for the build_queue task
     *   4. Fires the non-blocking REST loopback to start the runner
     *
     * The actual queue build (collect_urls + filter_already_cached +
     * filter_out_redirects + N warm enqueues) happens inside the runner
     * loop, not in the caller's request. Safe to call from activation
     * hooks, save_post, settings save, etc.
     */
    public static function start() {
        // Guard: start() is not idempotent — a second call would clear
        // the queue the first one just populated. Run once per request.
        static $did_start = false;
        if ( $did_start ) {
            return true;
        }
        $did_start = true;

        // Reset state.
        update_option( 'easyopt_cache_preload_total',  0,         false );
        update_option( 'easyopt_cache_preload_status', 'running', false );
        delete_transient( 'easyopt_cache_preload_paused_reason' );
        delete_transient( 'easyopt_waiting_count' );
        // Phase 1: re-prove on-visit caching each run before the fast path
        // engages (first warms stay blocking-capture until confirmed).
        delete_transient( 'easyopt_preload_ff_confirmed' );
        delete_transient( 'easyopt_preload_probe_gate' );

        // Cancel any in-flight work.
        self::warm_queue()->clear_queue();
        self::build_queue_instance()->clear_queue();

        // Start the adaptive governor fresh at this level's conservative start
        // point — don't inherit a backed-off (or over-ramped) state from a
        // previous run or a different host condition.
        self::governor_reset();

        // Clear stale in-flight bookkeeping from any interrupted run.
        self::inflight_reset();

        // Enqueue exactly one build task. The runner will pick it up
        // within the loopback dispatch (~immediate) or, worst case,
        // within ~60 s when the watchdog fires.
        $id = self::build_queue_instance()->add_task( array( 'origin' => 'start' ), 10 );
        if ( ! $id ) {
            // Insert failed (DB issue?) — leave status as 'running' so
            // the UI shows we tried. Watchdog or a retry click will
            // re-attempt.
            return false;
        }

        // Kick the dispatcher.
        self::build_queue_instance()->start_queue();

        // (2.4.5) When WP-Cron is disabled we can't rely on the per-minute
        // watchdog firing, so make the manual trigger itself do a small,
        // time-boxed amount of work — this builds the URL list (and starts
        // warming) even on a host that ALSO blocks the loopback. Healthy
        // hosts (WP-Cron enabled) skip this entirely and add zero latency;
        // the watchdog handles everything there.
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON
            && class_exists( 'EasyOpt_Queue' )
            && method_exists( 'EasyOpt_Queue', 'drain_pending_inline' ) ) {
            EasyOpt_Queue::drain_pending_inline();
        }

        return true;
    }

    public static function stop() {
        self::warm_queue()->clear_queue();
        self::build_queue_instance()->clear_queue();
        self::inflight_reset();
        update_option( 'easyopt_cache_preload_status', 'idle', false );
        delete_transient( 'easyopt_cache_preload_paused_reason' );
    }

    public static function on_preload_toggle() {
        $enabled = (int) EasyOpt_Config::get( 'cache_preload', 0 );
        if ( $enabled ) {
            self::start();
        } else {
            self::stop();
        }
    }

    /**
     * Companion-warm hook fired from the cache-write buffer when a URL
     * gets cached for one device but its companion variant is missing.
     *
     * 1.7.1 — simplified: removed burst-lock transient (was an option
     * write per URL) and sync_total_with_queue (another option write).
     * Dedup is handled atomically by the queue's task_hash UNIQUE key.
     *
     * @param string $url               The URL we just cached.
     * @param bool   $current_is_mobile Reserved for asymmetric warming.
     */
    /**
     * Queue a render of THIS url so Used CSS is generated off the visitor's
     * request (2.6.0).
     *
     * Used CSS can only be generated from a rendered page — the keep-set is
     * the classes/ids/tags present in that page's DOM — so "generating in the
     * background" means re-rendering the URL on a preload worker, not calling
     * a standalone job. That is exactly what the warm queue already does.
     *
     * The warm render generates the CSS AND caches the resulting HTML with it
     * injected, in one pass, overwriting whatever the visitor's uncached
     * render stored. Server-level caches are then invalidated for that URL by
     * the normal easyopt_cache_cleared_url path.
     *
     * De-duplicated per context for 10 minutes so a burst of visitors to a
     * cold page enqueues one build, not one per visit.
     *
     * @since 2.6.0
     * @param string $url       Absolute URL to (re)render.
     * @param string $context   Used CSS context key, for de-duplication.
     * @param bool   $is_mobile Device variant this request represents.
     * @return bool True when a build was enqueued.
     */
    public static function enqueue_used_css_build( $url, $context, $is_mobile ) {

        $url = is_string( $url ) ? trim( $url ) : '';
        if ( '' === $url || ! self::is_warmable_url( $url ) ) {
            return false;
        }
        if ( ! (int) EasyOpt_Config::get( 'cache_preload', 0 ) ) {
            return false;
        }

        $lock = 'easyopt_rucss_q_' . md5( (string) $context );
        if ( false !== get_transient( $lock ) ) {
            return false; // a build for this context is already queued
        }
        set_transient( $lock, 1, 10 * MINUTE_IN_SECONDS );

        if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
            EasyOpt_Preload_Results::adopt( $url );
        }

        // Priority 20 — the SAME as an ordinary warm, deliberately.
        //
        // This ran at 10 (ahead of the crawl) in the first 2.6.0 build and it
        // was wrong: during an initial preload every page is cold, so every
        // live visitor injected a queue-jumping task, the crawl was
        // continuously interrupted and made no progress, and pages stayed
        // unoptimised far longer than if nothing had been enqueued at all.
        // A deferred build is not more urgent than the crawl — it IS the
        // crawl, arriving from a different direction.
        self::warm_queue()->add_task(
            array( 'url' => $url, 'device' => $is_mobile ? 'mobile' : 'desktop' ),
            20
        );

        if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
            EasyOpt_Debug_Log::info( 'rucss', sprintf(
                'Queued Used CSS build for "%s" (%s) — page served with original CSS meanwhile.',
                (string) $context,
                $url
            ) );
        }

        return true;
    }

    public static function enqueue_companion( $url, $current_is_mobile ) {
        $url = is_string( $url ) ? trim( preg_replace( '/#.*$/', '', $url ) ) : '';
        if ( '' === $url ) {
            return;
        }
        // (2.5.5) Pages only — see is_warmable_url().
        if ( ! self::is_warmable_url( $url ) ) {
            return;
        }
        if ( ! (int) EasyOpt_Config::get( 'cache_preload', 0 ) ) {
            return;
        }

        $needed_device = $current_is_mobile ? 'desktop' : 'mobile';

        // (2.5.0 / H4) Adopt this URL into the current run WITHOUT resetting
        // its state, so a companion enqueued mid-run joins the live total
        // instead of making the denominator drift.
        if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
            EasyOpt_Preload_Results::adopt( $url );
        }

        $queue = self::warm_queue();
        $id    = $queue->add_task(
            array( 'url' => $url, 'device' => $needed_device ),
            20
        );
        if ( ! $id ) {
            return;
        }

        $status = (string) get_option( 'easyopt_cache_preload_status', 'idle' );

        // (2.5.0 CPU fix) Only KICK the runner when preload is idle/done —
        // i.e. this companion came from a REAL visitor hitting an uncached
        // page (preload-on-MISS). During an active run ('running'/'paused')
        // the runner is already draining the queue, so firing a fresh
        // loopback dispatch here for every MISS produced a storm of
        // redundant runner starts — the main CPU spike on Turbo, where
        // 8-way concurrency multiplied it. The task is still enqueued above
        // (deduped) so coverage is unchanged; it's just picked up by the
        // already-running drain instead of a new dispatch.
        if ( 'running' === $status || 'paused' === $status ) {
            return;
        }
        update_option( 'easyopt_cache_preload_status', 'running', false );
        $queue->start_queue();
    }

    // (2.3.3) sync_total_with_queue() removed — dead code with self-admitted
    // confused math and no call sites. enqueue_companion() relies on the
    // queue's task_hash dedup; the X/Y progress display reads live counts.

    /* ───────────────────────────────────────────────
     *  Queue task handlers
     * ─────────────────────────────────────────────── */

    /**
     * Build the warm queue. Runs inside EasyOpt_Queue's runner loop.
     *
     * If anything in here throws, the queue marks the task pending +
     * retries with exponential backoff. After BUILD_MAX_RETRIES it's
     * marked 'failed' and stays in the table for diagnosis.
     */
    public static function task_build_queue( $task_data ) {
        // refresh the row's lock during long collect_urls walks. We
        // can't change the queue's callback signature without breaking
        // the contract, so we read it from the task_data instead. They
        // get injected by the queue runner just before dispatch (see
        // EasyOpt_Queue::process_task → 1.6.1 patch).
        $build_task_id    = isset( $task_data['_task_id']    ) ? (int)    $task_data['_task_id']    : 0;
        $build_lock_token = isset( $task_data['_lock_token'] ) ? (string) $task_data['_lock_token'] : '';

        if ( ! (int) EasyOpt_Config::get( 'cache_preload', 0 ) ) {
            // Toggle flipped off between scheduling and execution.
            update_option( 'easyopt_cache_preload_status', 'idle', false );
            return;
        }

        // Long-running step #1: collect_urls (sitemap fetch + post-type
        // walk + redirect filter). Can run 30–60s on a 500-URL site.
        // Refresh the lock just before we hand off control.
        if ( $build_task_id > 0 && '' !== $build_lock_token && class_exists( 'EasyOpt_Queue' ) ) {
            EasyOpt_Queue::touch_lock( $build_task_id, $build_lock_token );
        }

        $urls = self::collect_urls();

        if ( $build_task_id > 0 && '' !== $build_lock_token && class_exists( 'EasyOpt_Queue' ) ) {
            EasyOpt_Queue::touch_lock( $build_task_id, $build_lock_token );
        }

        // (2.5.0 / C3+H4) Seed the results ledger with the FULL collected
        // set — before the already-cached filter — so the run's total is
        // the true universe of URLs, and every URL has a row to confirm or
        // fail against. begin_run() bumps the run id and resets rows to
        // pending; it is chunked internally, so refresh the build lock
        // around it.
        $all_collected = $urls;
        if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
            EasyOpt_Preload_Results::begin_run( $all_collected );
            if ( $build_task_id > 0 && '' !== $build_lock_token && class_exists( 'EasyOpt_Queue' ) ) {
                EasyOpt_Queue::touch_lock( $build_task_id, $build_lock_token );
            }
        }

        $urls = self::filter_already_cached( $urls );

        // (2.5.0 / C3) URLs already on disk are confirmed cached now, so
        // they count toward progress instead of being invisible.
        if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
            $prewarmed = array_values( array_diff( $all_collected, $urls ) );
            if ( ! empty( $prewarmed ) ) {
                EasyOpt_Preload_Results::mark_prewarmed( $prewarmed );
            }
        }

        if ( empty( $urls ) ) {
            update_option( 'easyopt_cache_preload_total',  0,      false );
            update_option( 'easyopt_cache_preload_status', 'done', false );
            self::bump_state_version();
            return;
        }

        // Pre-set total so the dashboard UI shows the right denominator
        // immediately. The total is in URLs (UI-collapsed), not tasks.
        $url_count       = count( $urls );
        $separate_mobile = (int) EasyOpt_Config::get( 'cache_separate_mobile', 1 );
        update_option( 'easyopt_cache_preload_total',  $url_count, false );
        update_option( 'easyopt_cache_preload_status', 'running',  false );
        self::bump_state_version();

        // that's 1000 INSERTs in one transaction; chunking lets us also
        // touch_lock between batches AND yields to other DB clients.
        $queue        = self::warm_queue();
        $chunk_size   = (int) apply_filters( 'easyopt_preload_enqueue_chunk', 100 );
        $chunk_size   = max( 10, min( 500, $chunk_size ) );
        $i            = 0;

        // Priority tiers (set as a side-effect of collect_urls): homepage → menu
        // → posts/pages/products → taxonomy archives. The pages visitors hit
        // most are hot within minutes even on a 25k-URL site. Claim order is
        // priority ASC, then FIFO. Anything not tagged (e.g. a URL injected by
        // the easyopt_cache_preload_urls filter) defaults to the post tier.
        foreach ( $urls as $url ) {
            $prio = isset( self::$collected_priorities[ $url ] ) ? self::$collected_priorities[ $url ] : self::PRIO_POST;
            // (2.5.4 / perf #13) The collector already knows the post behind
            // this permalink — ship it in the payload so the runner never has
            // to reverse-resolve it with url_to_postid().
            $task = array( 'url' => $url, 'device' => 'desktop' );
            if ( isset( self::$collected_post_ids[ $url ] ) ) {
                // Underscore prefix = advisory: excluded from the queue's
                // dedup identity (see EasyOpt_Queue::canonicalize_for_hash).
                $task['_post_id'] = (int) self::$collected_post_ids[ $url ];
            }
            $queue->add_task( $task, $prio );
            if ( $separate_mobile ) {
                $task['device'] = 'mobile';
                $queue->add_task( $task, $prio );
            }
            $i++;
            if ( 0 === $i % $chunk_size ) {
                // Refresh build lock and yield briefly so other DB
                // clients aren't starved. usleep is 1ms — negligible.
                if ( $build_task_id > 0 && '' !== $build_lock_token && class_exists( 'EasyOpt_Queue' ) ) {
                    EasyOpt_Queue::touch_lock( $build_task_id, $build_lock_token );
                }
                usleep( 1000 );
            }
        }

        // Kick the dispatcher. If the build_queue runner is the only
        // active runner, this fires a fresh loopback for the warm group
        // before this runner exits — no idle gap between build and warm.
        $queue->start_queue();
    }

    /**
     * Warm a single URL. Runs once per URL inside the runner. Fetches the
     * full page over HTTP and writes the response body straight to the page
     * cache via EasyOpt_Cache::store_prefetched_html() — capture that does
     * not depend on the in-process output buffer, so it works even where a
     * theme/host rewrites the buffer stack.
     *
     * Throws on 5xx / 429 → queue marks pending + retries with backoff.
     * Other non-2xx responses (e.g. 404) are treated as terminal (a 404
     * isn't going to become a 200 next attempt) and just return cleanly.
     *
     * (2.5.5) The former CPU pause is removed; backoff comes only from
     * the site's own responses (429/5xx + Retry-After, WAF challenges,
     * EWMA latency governor).
     *
     * @param array $task_data ['url' => string]
     * @throws Exception on retryable HTTP failure
     */
    public static function task_warm_url( $task_data ) {
        $url    = is_array( $task_data ) ? (string) ( $task_data['url']    ?? '' ) : '';
        $device = is_array( $task_data ) ? (string) ( $task_data['device'] ?? '' ) : '';

        if ( '' === $url ) {
            return; // junk task — let the runner DELETE it.
        }

        // (2.5.4 / perf #13) Payload-supplied post ID for the optimization
        // pass. Bounded map (a runner processes a handful of tasks/window).
        if ( isset( $task_data['_post_id'] ) && (int) $task_data['_post_id'] > 0 ) {
            if ( count( self::$task_post_ids ) > 200 ) {
                self::$task_post_ids = array();
            }
            self::$task_post_ids[ $url ] = (int) $task_data['_post_id'];
        }

        if ( ! (int) EasyOpt_Config::get( 'cache_preload', 0 ) ) {
            return; // toggle flipped off mid-run — drop silently.
        }

        // before the per-device split: treat missing device as "both".
        // New rows always specify 'desktop' or 'mobile'.
        $separate_mobile = (int) EasyOpt_Config::get( 'cache_separate_mobile', 1 );
        if ( '' === $device ) {
            $device = 'desktop';
            // also re-enqueue a mobile companion if needed (only if not
            // already cached) — handled by the early-cached check below.
            if ( $separate_mobile ) {
                self::warm_queue()->add_task( array( 'url' => $url, 'device' => 'mobile' ), self::PRIO_POST );
            }
        }

        // (2.5.5) The CPU pause that used to sit here is gone — host-scoped
        // metric, see the note by the removed constants. Backoff is now
        // driven solely by the site's own responses: governor_record()'s
        // 429/5xx + Retry-After handling, the WAF-challenge defer below,
        // and the EWMA latency governor.

        // Clear paused state on first successful run. Kept deliberately —
        // it is also the recovery path for sites left stuck 'paused' by
        // the old CPU gate at upgrade time.
        if ( 'paused' === get_option( 'easyopt_cache_preload_status', 'idle' ) ) {
            update_option( 'easyopt_cache_preload_status', 'running', false );
            delete_transient( 'easyopt_cache_preload_paused_reason' );
        }

        // Skip if THIS device's cache file already exists. Each task is
        // independent now — desktop done ≠ skip mobile, and vice versa.
        if ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'cache_paths_for_url' ) ) {
            $paths = EasyOpt_Cache::cache_paths_for_url( $url );
            $key   = ( 'mobile' === $device ) ? 'mobile' : 'desktop';
            $path  = isset( $paths[ $key ] ) ? (string) $paths[ $key ] : '';
            // (2.6.0) The 'desktop'/'mobile' keys are ALREADY the *.html_gzip
            // names, so the old companion test appended a second suffix and
            // looked for index.html_gzip_gzip — a filename that can never
            // exist. The plain .html was therefore never considered, and on
            // OpenLiteSpeed / Gzip-off installs (where 2.5.7 stopped writing
            // gzip copies from the live path) an already-cached page was
            // re-fetched and re-rendered on every run. Use the plain companion
            // key that cache_paths_for_url() now returns.
            $plain = isset( $paths[ $key . '_plain' ] ) ? (string) $paths[ $key . '_plain' ] : '';
            if ( '' !== $path && ( file_exists( $path ) || ( '' !== $plain && file_exists( $plain ) ) ) ) {
                // (2.5.0 / C3) File on disk = ground truth. Record it so
                // "already cached" URLs count toward the cached total
                // instead of silently vanishing from progress.
                if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
                    EasyOpt_Preload_Results::confirm( $url, ( 'mobile' === $device ) );
                }
                return; // already cached — runner will DELETE the row
            }
            // Edge case: separate_mobile=0 and device=mobile → no mobile
            // file is expected, the desktop cache serves both. Skip.
            if ( 'mobile' === $device && ! $separate_mobile ) {
                return;
            }
        }

        // Warm THIS device. Path selection (Phase 1 — fire-and-forget):
        //   • Default fast path: a non-blocking dispatch (blocking=false) that
        //     triggers the render and returns immediately — the on-visit output
        //     buffer writes the cache in the background, exactly like the normal
        //     visitor path. One runner then rips through many URLs per window
        //     instead of blocking on each origin render.
        //   • It only engages once on-visit caching is CONFIRMED working this
        //     run (a successful blocking warm with a reliable buffer) AND the
        //     self-healing `easyopt_skip_live_buffer` flag says the buffer is
        //     trustworthy. On server-cached / buffer-hostile hosts that flag is
        //     set, so we stay on the proven blocking-capture path — no
        //     regression, same robustness.
        //   • Roughly once a minute a warm is forced back onto the blocking
        //     path as a probe: it measures render latency for the governor's
        //     response-time tuning AND keeps the blank-page self-heal alive.
        $async = self::should_fire_and_forget();
        if ( $async ) {
            self::warm_one_async( $url, $device );
        } else {
            // Blocking capture path. (2.5.0 / H6-fix) The fast path is now
            // confirmed INSIDE warm_one(), and only when the on-visit
            // buffer demonstrably wrote a cache file for this render —
            // the old unconditional confirmation here engaged the async
            // path even on hosts where the buffer never writes, turning
            // the majority of warms into silent no-ops.
            self::warm_one( $url, $device );
        }

        // They caused 2-4 DB writes per URL (option update + transient delete).
        // Completion detection now happens in the dashboard stats endpoint's
        // auto-correct logic: when remaining=0 and total>0, status flips to 'done'.
    }

    /* ───────────────────────────────────────────────
     *  Fire-and-forget fast path (Phase 1)
     * ─────────────────────────────────────────────── */

    /** Master switch for the non-blocking warm. On by default; disable with
     *  define('EASYOPT_PRELOAD_NO_FIRE_AND_FORGET', true) or the filter below. */
    private static function fire_and_forget_enabled() {
        if ( defined( 'EASYOPT_PRELOAD_NO_FIRE_AND_FORGET' ) && EASYOPT_PRELOAD_NO_FIRE_AND_FORGET ) {
            return false;
        }
        return (bool) apply_filters( 'easyopt_preload_fire_and_forget', true );
    }

    /** True when on-visit caching has been confirmed working this run. */
    private static function fire_and_forget_confirmed() {
        return (bool) get_transient( 'easyopt_preload_ff_confirmed' );
    }

    /** Mark on-visit caching confirmed (run-scoped; refreshed as the run works). */
    private static function mark_fire_and_forget_confirmed() {
        set_transient( 'easyopt_preload_ff_confirmed', 1, 6 * HOUR_IN_SECONDS );
    }

    /** Decide whether THIS warm should use the fast path. Requires: feature on,
     *  caching confirmed, buffer flagged reliable — and NOT the ~1/min probe
     *  slot (which stays blocking to feed the governor + self-heal). */
    private static function should_fire_and_forget() {
        // Feature on, caching proven, buffer reliable — and a shared store to
        // bound in-flight renders with. Without the last one we stay on the
        // blocking path, where in-flight equals runner count by construction
        // and no counter is needed.
        if ( ! self::async_path_available() ) {
            return false;
        }
        // Periodic blocking probe: at most one per minute across all runners.
        if ( false === get_transient( 'easyopt_preload_probe_gate' ) ) {
            set_transient( 'easyopt_preload_probe_gate', 1, MINUTE_IN_SECONDS );
            return false; // this warm is the probe.
        }
        return true;
    }

    /**
     * Non-blocking warm dispatch. Sends the same request warm_one() would
     * (real-browser UA, synthetic login cookie so server caches bypass, the
     * ?eopreload=1 marker, Range: bytes=0-0 to keep bandwidth ~1 byte) but with
     * blocking=false / timeout≈0.01 — it fires and returns without waiting for
     * the render. The render completes in a separate FPM worker and the on-visit
     * output buffer writes the cache, so the cache result is identical to the
     * blocking path; only the *waiting* is removed.
     *
     * Every dispatch first reserves a slot from the in-flight budget and
     * carries its token as ?eoslot=, which the render releases on shutdown.
     * When the budget is full we fall back to the BLOCKING warm rather than
     * deferring the task: waiting for one render is itself the backpressure we
     * want, the URL is still warmed, and no retry attempt is consumed (three
     * of those would mark the URL failed — a URL must never fail because the
     * host was momentarily busy). Throws only on the blocking fallback, which
     * is the normal retry contract for warm_one().
     *
     * @param string $url
     * @param string $device 'desktop' | 'mobile'
     */
    private static function warm_one_async( $url, $device ) {
        $slot = self::inflight_acquire();
        if ( '' === $slot ) {
            self::warm_one( $url, $device );
            return;
        }

        $headers          = self::build_warm_headers( $device );
        $headers['Range'] = 'bytes=0-0';
        $warm_url         = self::warm_url( $url, $slot );

        $response = wp_remote_get(
            $warm_url,
            array(
                'timeout'     => 0.01,
                'blocking'    => false,
                'sslverify'   => false,
                'redirection' => 0,
                'headers'     => $headers,
            )
        );

        // Nothing was dispatched, so nothing will ever release this slot —
        // hand it straight back instead of waiting out the TTL.
        if ( is_wp_error( $response ) ) {
            self::inflight_release( $slot );
        }
    }

    /**
     * Issue one warm HTTP request. Throws on retryable failure.
     *
     * @param string $url
     * @param string $device 'desktop' | 'mobile'
     * @throws Exception
     */
    private static function warm_one( $url, $device ) {
        // Changes:
        //   • timeout 30 → 12s. A real origin that takes 12s+ to render
        //     is in trouble; eating the rest of the runner window waiting
        //     just hurts throughput. Filter `easyopt_preload_warm_timeout`
        //     to override.
        //   • Both desktop and mobile now use REAL browser User-Agents
        //     (Chrome / Safari iOS). A custom UA like the old
        //     "EasyOptimizerPreload/1.0" is fingerprinted as automation by
        //     host WAFs / bot protection (wpx.net, Wordfence, Cloudflare,
        //     Sucuri) and was blocked or challenged on some hosts, causing
        //     warm requests to fail and pages never to cache.
        //   • No custom X-* header is sent (a non-standard header is itself a
        //     bot signal). Preload requests are identified server-side via the
        //     ?eopreload=1 query arg, which sets EASYOPT_IS_PRELOAD_REQUEST.
        //   • A synthetic wordpress_logged_in_1 cookie is sent (see
        //     build_warm_headers) so server-level caches bypass themselves and
        //     our buffer actually runs — the core reason preload now works on
        //     hosts with their own page cache (wpx, Varnish, LiteSpeed, …).
        $timeout = (int) apply_filters( 'easyopt_preload_warm_timeout', 12, $url, $device );
        $timeout = max( 3, min( 60, $timeout ) );

        $headers = self::build_warm_headers( $device );

        // Transfer only the first byte. PHP still generates the FULL page —
        // our output buffer captures and caches it in full before the web
        // server slices the response — so the cache file is complete while
        // per-URL bandwidth drops to ~1 byte (a big saving across tens of
        // thousands of URLs). The fallback refetch_without_buffer() below
        // intentionally omits Range so it can read the whole body when the
        // live buffer didn't capture the page.
        $headers['Range'] = 'bytes=0-0';

        // Marks this as a preload request (sets EASYOPT_IS_PRELOAD_REQUEST).
        // Stripped before path hashing in cache.php's normalize_url so it
        // doesn't create a separate cache file. (2.6.0) Carries the HMAC.
        $warm_url = self::warm_url( $url );

        // (2.5.0 / CB-1) Do NOT blindly follow redirects. Before 2.5.0 this
        // blocking warm used the WP default (redirection => 5), so a warmed URL
        // that 301/302-redirected was followed and the *target's* HTML was
        // written under the *source* URL by store_prefetched_html(). An SEO /
        // redirect plugin sending a retired, date or author URL to the homepage
        // therefore cached the HOMEPAGE under that URL, which then served for
        // what should have been a 404. We now stop at the redirect and decide
        // per-Location below (see the 3xx branch): a redirect that stays on the
        // SAME cache key (http↔https / trailing-slash canonicalisation) is
        // warmed correctly, while a redirect to a DIFFERENT page is never cached
        // under the source key. The async and curl_multi warm paths already set
        // redirection=0 / FOLLOWLOCATION=false, so this brings the last blocking
        // path in line with them.
        $args = array(
            'timeout'     => $timeout,
            'blocking'    => true,
            'sslverify'   => false,
            'redirection' => 0,
            'headers'     => $headers,
        );

        $gov_t0   = microtime( true );
        $response = wp_remote_get( $warm_url, $args );

        if ( is_wp_error( $response ) ) {
            // Network failure / timeout → congestion signal + retryable.
            self::governor_record( null, 0, 0 );
            throw new \Exception( sprintf(
                'HTTP error warming %s (%s): %s',
                $url,
                $device,
                $response->get_error_message()
            ) );
        }

        $code   = (int) wp_remote_retrieve_response_code( $response );
        $gov_ms = ( microtime( true ) - $gov_t0 ) * 1000;

        // With Range: bytes=0-0 the server replies 206 (Partial Content) when
        // it honours the range, or 200 when it ignores Range for dynamic
        // output. Either way PHP ran and our buffer cached the full page; the
        // body is only inspected in the fallback path below.
        if ( 200 === $code || 206 === $code ) {
            // Success → feeds the governor's additive-increase streak.
            self::governor_record( $gov_ms, $code, 0 );
            $is_mobile = ( 'mobile' === $device );

            // (a) Live buffer captured this render → it works here. Keep
            //     visitors on the buffer (it optimizes + caches in the page's
            //     real context). This also self-heals a previously-set skip
            //     flag if the environment changed.
            if ( self::cache_already_written( $url, $is_mobile ) ) {
                self::set_live_buffer_reliable( true );
                // (2.5.0 / H6-fix) Fire-and-forget confirmation is now
                // EVIDENCE-BASED: it only engages once a blocking warm has
                // PROVEN the on-visit buffer writes cache files on preload
                // renders (this branch). The old confirmation fired after
                // any blocking warm regardless, so on hosts where the
                // buffer never wrote, every async warm was silently a
                // no-op. If the buffer can't capture here, warms simply
                // stay on the blocking path — slower, but every URL still
                // gets cached via store_prefetched_html().
                self::mark_fire_and_forget_confirmed();
                // (2.5.0 / C3) capture_buffer() already confirmed this
                // write in the render process; confirm here too as a
                // belt-and-braces no-op-if-done (single indexed UPDATE).
                if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
                    EasyOpt_Preload_Results::confirm( $url, $is_mobile );
                }
                return;
            }

            // (b) Live buffer did NOT write the cache. Either it can't capture
            //     here, or it blanked the response. Get a clean body and cache
            //     it ourselves.
            $body  = wp_remote_retrieve_body( $response );
            $blank = ( ! is_string( $body ) || strlen( trim( $body ) ) < 256 );

            if ( $blank ) {
                // A 200 with an (almost) empty body means our own output buffer
                // blanked the page on this site (a theme/host rewrites the
                // buffer stack). Stop visitors from using the buffer, then
                // refetch with our buffer disabled to recover the real HTML.
                self::set_live_buffer_reliable( false );
                $body = self::refetch_without_buffer( $url, $device, $timeout );
            }

            if ( is_string( $body ) && '' !== $body && class_exists( 'EasyOpt_Cache' ) ) {
                $body = self::optimize_prefetched_html( $url, $body );
                EasyOpt_Cache::store_prefetched_html( $url, $body, $is_mobile );

                // (2.4.9) We deliberately do NOT flag the live buffer unreliable
                // here. A full page our loopback didn't find pre-cached is almost
                // always a server-level cache (Varnish / LiteSpeed / host XC)
                // answering before PHP ran, or simply a non-cacheable page — NOT
                // a broken output buffer. Only the genuine blank-page signal
                // above (the buffer corrupting output) disables on-visit caching.
                // Treating "didn't cache" as "buffer broken" wrongly turned off
                // on-visit caching on server-cached hosts (e.g. WPX), leaving the
                // preloader as the only thing that ever wrote the cache.
            }
            return;
        }

        // Retry on 429 (rate-limit) and 5xx (transient origin errors).
        if ( 429 === $code || $code >= 500 ) {
            // Congestion → governor backs off concurrency + honours Retry-After.
            self::governor_record( $gov_ms, $code, self::parse_retry_after( $response ) );
            throw new \Exception( sprintf(
                'Retryable HTTP %d warming %s (%s)',
                $code,
                $url,
                $device
            ) );
        }

        // (2.5.5) WAF challenge (Sucuri / Cloudflare / generic). When the
        // site sits behind a firewall, our loopback warms traverse the WAF
        // edge like any external client, and a challenged request comes back
        // 403/401/503 with the vendor's headers. That is NOT a property of
        // the URL — marking it 'failed' poisons coverage for pages that are
        // perfectly fine in a browser. Treat it as congestion instead: back
        // the governor off hard and DEFER without burning a retry, exactly
        // like the old load pause but driven by a tenant-scoped, WAF-aware
        // signal. The paused reason names the vendor so the dashboard's
        // existing status line explains itself.
        if ( in_array( $code, array( 401, 403, 405, 406 ), true ) ) {
            $vendor = self::waf_vendor_from_response( $response );
            if ( '' !== $vendor ) {
                self::governor_record( $gov_ms, 429, 60 );
                set_transient(
                    'easyopt_cache_preload_paused_reason',
                    sprintf( 'firewall challenge (%s) — allowlist the server IP', $vendor ),
                    10 * MINUTE_IN_SECONDS
                );
                throw new EasyOpt_Queue_Defer_Exception(
                    sprintf( 'Deferred: HTTP %d %s challenge warming %s', $code, $vendor, $url ),
                    120
                );
            }
        }

        // (2.5.0 / CB-1 + CB-2) Redirect handling. collect_urls() emits
        // canonical URLs (get_permalink / home_url / get_term_link) that do not
        // normally redirect, so on a well-configured site this branch is rarely
        // reached. When it is (menu custom-links, server-level scheme/host
        // normalisation, SEO redirects) we chase the redirect ONLY when its
        // target resolves to the SAME cache key as the source — so http↔https
        // and trailing-slash canonicalisation still warm with zero coverage
        // loss — and drop it otherwise, so we never cache a different page (e.g.
        // the homepage) under a URL that should 404. The `filter_out_redirects`
        // safeguard the older comments referred to was never implemented; this
        // branch supersedes it.
        if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
            self::governor_record( $gov_ms, $code, 0 );
            $location = trim( (string) wp_remote_retrieve_header( $response, 'location' ) );
            $first    = ( '' !== $location )
                ? self::resolve_redirect_target( $location, $warm_url )
                : '';
            // (2.5.0 / C1-fix) Shared redirect handler: resolves the FULL
            // chain (up to 3 hops), warms + caches genuine same-key
            // canonicalisation — including multi-hop chains like
            // http → https → trailing-slash that the single-hop logic used
            // to drop — and records everything else as 'redirected' in the
            // results ledger instead of silently deleting the task. Same
            // policy on every transport (see warm_batch_multi).
            self::handle_redirect( $url, $device, $first, $code, $timeout );
            return;
        }

        // 4xx (other) = terminal — nothing to cache. Auth-walls, geoblocks
        // and deleted pages land here. (2.5.0 / C1-fix) Recorded in the
        // results ledger so the dashboard can show WHY the URL isn't
        // cached instead of silently counting it as done.
        self::governor_record( $gov_ms, $code, 0 );
        if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
            EasyOpt_Preload_Results::mark_uncacheable( $url, 'HTTP ' . $code, $code );
        }
        if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
            EasyOpt_Debug_Log::warn( 'preload', sprintf(
                'Skip warm: %s (%s) returned HTTP %d — nothing to cache.',
                $url,
                $device,
                $code
            ) );
        }
    }

    /**
     * (2.5.0 / C1-fix) One redirect policy for EVERY warm transport.
     *
     * Resolves the redirect chain to its FINAL destination (bounded at
     * 3 hops), then:
     *
     *   - final target resolves to the SAME cache key as the source
     *     (scheme / trailing-slash / path-case / www↔apex normalisation)
     *     → warm the final target once and cache it under the source key.
     *     Multi-hop canonical chains (http → https → slash — the Apache +
     *     redirect_canonical default) now warm correctly; before this fix
     *     only single-hop redirects were rescued and everything else was
     *     silently dropped.
     *
     *   - final target is a DIFFERENT page (SEO redirect, language
     *     redirect, cross-domain) → never cached under the source key
     *     (the CB-1 guarantee is preserved), but the outcome is now
     *     RECORDED: a 'redirected' row in the results ledger with the
     *     target in the note, plus a warning-level log line. The URL is
     *     excluded from the "cached" count instead of being counted done.
     *
     * @param string $url          Source URL (defines the cache key).
     * @param string $device       'desktop' | 'mobile'.
     * @param string $first_target First Location, resolved to absolute ('' if unresolvable).
     * @param int    $code         The original 3xx status code.
     * @param int    $timeout      Per-request timeout (seconds).
     */
    /** (2.5.4 / perf #48) Redirect-chain probes spent this process. */
    private static $redirect_probe_budget = 100;

    private static function handle_redirect( $url, $device, $first_target, $code, $timeout ) {
        $final    = '';
        $final_ok = false;
        if ( '' !== $first_target ) {
            // (2.5.0 / C1b) Cross-host first hop = a different SITE
            // (language redirect, sale-domain, tracking hop). No chain of
            // probes can ever turn that into a same-cache-key target, so
            // skip resolve_final_target() entirely — saving up to 3 full
            // loopback renders per such URL — and record it as redirected
            // straight away. Same-host (modulo www) hops still resolve the
            // full chain so multi-hop canonicalisation keeps warming.
            $ph = wp_parse_url( $first_target );
            $ps = wp_parse_url( (string) $url );
            $cross_host = is_array( $ph ) && is_array( $ps )
                && ! empty( $ph['host'] ) && ! empty( $ps['host'] )
                && self::strip_www( strtolower( (string) $ph['host'] ) ) !== self::strip_www( strtolower( (string) $ps['host'] ) );

            if ( ! $cross_host ) {
                // (2.5.4 / perf #48) Chain resolution costs up to 3 extra
                // probes per URL. On a healthy site this branch is rare, but a
                // site-wide redirect misconfiguration (scheme/host mismatch in
                // WP Address, an overzealous SEO redirect rule) could triple
                // the whole run's traffic. Budget the probes per runner
                // process; once exhausted, further redirects are recorded as
                // redirected (visible on the dashboard with the reason)
                // instead of probed — coverage identical to a pre-2.5.0
                // install, cost bounded.
                if ( self::$redirect_probe_budget > 0 ) {
                    self::$redirect_probe_budget -= 3; // worst-case hops
                    $resolved = self::resolve_final_target( $first_target, $device, $timeout );
                    $final    = $resolved['final'];
                    $final_ok = $resolved['ok'];
                } else {
                    $final = $first_target;
                }
            } else {
                $final = $first_target;
            }
        }

        if ( $final_ok && self::same_cache_target( $url, $final ) ) {
            // Harmless canonicalisation → warm the canonical URL once (no
            // further redirects) and cache it under the source key.
            self::warm_same_key_redirect( $url, $final, $device, $timeout );
            return;
        }

        $shown = '' !== $final ? $final : ( '' !== $first_target ? $first_target : '(unresolved Location)' );
        if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
            EasyOpt_Preload_Results::mark_redirected( $url, $shown, $code );
        }
        if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
            EasyOpt_Debug_Log::warn( 'preload', sprintf(
                'Skip warm: %s (%s) redirects to %s (different page, HTTP %d) — not caching the target under the source key. Update the menu link / permalink / redirect rule if this URL should be cached.',
                $url,
                $device,
                $shown,
                $code
            ) );
        }
    }

    /**
     * (2.5.0 / C1-fix) Follow a redirect chain to its final destination.
     * Bounded at 3 additional hops; each hop is a Range: bytes=0-0 probe
     * with redirects disabled so we observe every step ourselves. Total
     * worst case (initial warm + 3 probes + canonical warm) stays well
     * under the queue's 120 s task lock.
     *
     * @param string $target  First redirect target (absolute URL).
     * @param string $device  'desktop' | 'mobile'.
     * @param int    $timeout Per-request timeout (seconds).
     * @return array{final:string, ok:bool} Final URL and whether it answered 200/206.
     */
    private static function resolve_final_target( $target, $device, $timeout ) {
        $current   = (string) $target;
        $probe_t   = max( 3, min( 8, (int) $timeout ) );
        $headers   = self::build_warm_headers( $device );
        $headers['Range'] = 'bytes=0-0';

        for ( $hop = 0; $hop < 3; $hop++ ) {
            $probe_url = self::warm_url( $current );
            $resp      = wp_remote_get(
                $probe_url,
                array(
                    'timeout'     => $probe_t,
                    'blocking'    => true,
                    'sslverify'   => false,
                    'redirection' => 0,
                    'headers'     => $headers,
                )
            );
            if ( is_wp_error( $resp ) ) {
                return array( 'final' => $current, 'ok' => false );
            }
            $code = (int) wp_remote_retrieve_response_code( $resp );
            if ( 200 === $code || 206 === $code ) {
                return array( 'final' => $current, 'ok' => true );
            }
            if ( ! in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
                return array( 'final' => $current, 'ok' => false );
            }
            $location = trim( (string) wp_remote_retrieve_header( $resp, 'location' ) );
            $next     = ( '' !== $location )
                ? self::resolve_redirect_target( $location, $probe_url )
                : '';
            if ( '' === $next ) {
                return array( 'final' => $current, 'ok' => false );
            }
            $current = $next;
        }
        // Hop budget exhausted with the chain still redirecting.
        return array( 'final' => $current, 'ok' => false );
    }

    /**
     * (2.5.0 / CB-1) True when two URLs resolve to the SAME page-cache key —
     * same host and same path, ignoring scheme, query string and a trailing
     * slash. compute_path_base() keys files on host + sanitised path only, so
     * http/https and slash/no-slash variants share one cache file; this mirrors
     * that so a redirect between such variants is recognised as harmless
     * canonicalisation rather than a jump to a different page.
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    private static function same_cache_target( $a, $b ) {
        $pa = wp_parse_url( (string) $a );
        $pb = wp_parse_url( (string) $b );
        if ( ! is_array( $pa ) || ! is_array( $pb )
             || empty( $pa['host'] ) || empty( $pb['host'] ) ) {
            return false;
        }
        // (2.5.0 / C1-fix) www ↔ apex normalisation redirects are the same
        // SITE canonicalising its host — treating them as "a different
        // page" (as the first 2.5.0 build did) silently dropped every URL
        // on sites whose WordPress Address and server canonical host
        // disagree in www. Strip one leading "www." from each side before
        // comparing. Genuinely different domains still differ after the
        // strip and are still rejected.
        $host_a = self::strip_www( strtolower( (string) $pa['host'] ) );
        $host_b = self::strip_www( strtolower( (string) $pb['host'] ) );
        if ( $host_a !== $host_b ) {
            return false;
        }
        // (2.5.0 / C1-fix) Case-insensitive path comparison: WordPress's
        // redirect_canonical() issues case-fix redirects (/Blog → /blog);
        // those are the same page canonicalising its path, not a jump to
        // different content. The canonical body is warmed and stored under
        // the SOURCE key, so the source URL serves the right content.
        $path_a = strtolower( rawurldecode( untrailingslashit( (string) ( isset( $pa['path'] ) ? $pa['path'] : '/' ) ) ) );
        $path_b = strtolower( rawurldecode( untrailingslashit( (string) ( isset( $pb['path'] ) ? $pb['path'] : '/' ) ) ) );
        return ( $path_a === $path_b );
    }

    /**
     * Strip a single leading "www." label from an already-lowercased host.
     *
     * (2.5.0 / B6) This is the plugin's SINGLE www-normalisation point, and
     * the intent of the three key-identity helpers is deliberately split:
     *
     *  - compute_path_base() (EasyOpt_Cache) and
     *    EasyOpt_Preload_Results::hash_url() key cache files / ledger rows
     *    on the EXACT host — www and apex are distinct cache keys, because
     *    the server decides which host visitors actually reach.
     *  - same_cache_target() and normalize_collected_url() strip www when
     *    deciding whether a redirect / collected link is the SAME SITE
     *    canonicalising its host — because after the server's own
     *    canonical redirect, both spellings serve one page.
     *
     * Public so other modules reuse THIS implementation instead of growing
     * their own subtly-different copy.
     *
     * @param string $host Lowercased hostname.
     * @return string Host without one leading "www." label.
     */
    public static function strip_www( $host ) {
        return ( 0 === strpos( $host, 'www.' ) ) ? substr( $host, 4 ) : $host;
    }

    /**
     * (2.5.0 / CB-1) Resolve a redirect Location (absolute, protocol-relative
     * or root-relative) to an absolute URL, using the requested URL as the
     * base. Anything that cannot be resolved confidently returns '' so the
     * caller treats it as "different page" and does not cache — the safe
     * default. WordPress redirects (wp_redirect / redirect_canonical) always
     * emit absolute URLs, so the common paths are covered.
     *
     * @param string $location Raw Location header value.
     * @param string $base     The URL that was requested (absolute).
     * @return string Absolute URL, or ''.
     */
    private static function resolve_redirect_target( $location, $base ) {
        $location = trim( (string) $location );
        if ( '' === $location ) {
            return '';
        }
        // Absolute URL with scheme.
        if ( preg_match( '#^https?://#i', $location ) ) {
            return $location;
        }
        $b = wp_parse_url( (string) $base );
        if ( ! is_array( $b ) || empty( $b['host'] ) ) {
            return '';
        }
        $scheme = ! empty( $b['scheme'] ) ? $b['scheme'] : 'https';
        // Protocol-relative (//host/path).
        if ( 0 === strpos( $location, '//' ) ) {
            return $scheme . ':' . $location;
        }
        // Root-relative (/path).
        if ( 0 === strpos( $location, '/' ) ) {
            return $scheme . '://' . $b['host']
                 . ( isset( $b['port'] ) ? ':' . $b['port'] : '' )
                 . $location;
        }
        // (2.5.0 / C1-fix) Relative-path Location (e.g. "post-name/") —
        // legal per RFC 7231 §7.1.2 and emitted by some servers/plugins.
        // Previously treated as unresolvable, which classified the
        // redirect as "different page" and dropped the URL. Resolve it
        // against the request URL per RFC 3986 §5.3 (merge with the base
        // path's directory, then normalise ./ and ../ segments).
        $base_path = isset( $b['path'] ) ? (string) $b['path'] : '/';
        $slash     = strrpos( $base_path, '/' );
        $dir       = ( false === $slash ) ? '/' : substr( $base_path, 0, $slash + 1 );
        // Strip any query/fragment the Location carries before merging;
        // the cache key ignores them anyway.
        $loc_path  = (string) strtok( $location, '?#' );

        $merged   = $dir . $loc_path;
        $segments = array();
        foreach ( explode( '/', $merged ) as $seg ) {
            if ( '.' === $seg || '' === $seg ) {
                continue;
            }
            if ( '..' === $seg ) {
                array_pop( $segments );
                continue;
            }
            $segments[] = $seg;
        }
        $path = '/' . implode( '/', $segments );
        if ( '/' !== substr( $merged, -1 ) && '/..' !== substr( $merged, -3 ) && '/.' !== substr( $merged, -2 ) ) {
            // Preserve "no trailing slash" only when the merged path didn't
            // end in one; same_cache_target ignores it either way.
            $path = untrailingslashit( $path );
            if ( '' === $path ) {
                $path = '/';
            }
        } elseif ( '/' !== $path ) {
            $path = trailingslashit( $path );
        }

        return $scheme . '://' . $b['host']
             . ( isset( $b['port'] ) ? ':' . $b['port'] : '' )
             . $path;
    }

    /**
     * (2.5.0 / CB-1) Warm a same-cache-key canonical redirect target once (no
     * further redirects) and cache it under the SOURCE key, preserving the
     * coverage the pre-2.5.0 redirect-following provided for http↔https and
     * trailing-slash canonicalisation — WITHOUT the cross-page corruption.
     * Fires only from the 3xx branch above (rare on well-configured sites), so
     * it adds no steady-state request or CPU cost. Single-hop and non-recursive:
     * if the canonical target itself redirects or errors, nothing is cached.
     *
     * @param string $source_url URL originally warmed (defines the cache key).
     * @param string $target_url Same-key canonical URL it redirected to.
     * @param string $device     'desktop' | 'mobile'.
     * @param int    $timeout    Request timeout (seconds).
     * @return void
     */
    private static function warm_same_key_redirect( $source_url, $target_url, $device, $timeout ) {
        if ( ! class_exists( 'EasyOpt_Cache' ) ) {
            return;
        }
        $is_mobile = ( 'mobile' === $device );

        // The loopback that rendered the canonical page may already have cached
        // it via the live buffer (source and target share a cache key).
        if ( self::cache_already_written( $source_url, $is_mobile ) ) {
            self::set_live_buffer_reliable( true );
            // (2.5.0 / C3) Ground truth: the file exists on disk.
            if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
                EasyOpt_Preload_Results::confirm( $source_url, $is_mobile );
            }
            return;
        }

        $warm_url = self::warm_url( $target_url );
        $resp     = wp_remote_get(
            $warm_url,
            array(
                'timeout'     => $timeout,
                'blocking'    => true,
                'sslverify'   => false,
                'redirection' => 0,
                'headers'     => self::build_warm_headers( $device ),
            )
        );
        if ( is_wp_error( $resp ) ) {
            // (2.5.0 / C3) Retryable via the revert pass — recorded, not lost.
            if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
                EasyOpt_Preload_Results::mark_failed( $source_url, 'canonical warm failed: ' . $resp->get_error_message(), 0 );
            }
            return;
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( 200 !== $code && 206 !== $code ) {
            // Canonical target itself redirected/errored (race with a rule
            // change since the chain was resolved) → do not cache, record.
            if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
                EasyOpt_Preload_Results::mark_failed( $source_url, 'canonical target answered HTTP ' . $code, $code );
            }
            return;
        }

        $body  = wp_remote_retrieve_body( $resp );
        $blank = ( ! is_string( $body ) || strlen( trim( $body ) ) < 256 );
        if ( $blank ) {
            self::set_live_buffer_reliable( false );
            $body = self::refetch_without_buffer( $target_url, $device, $timeout );
        }
        if ( is_string( $body ) && '' !== $body ) {
            // Optimise + store under the SOURCE key (same file as the target).
            $body = self::optimize_prefetched_html( $source_url, $body );
            EasyOpt_Cache::store_prefetched_html( $source_url, $body, $is_mobile );
        }
    }

    /**
     * Build the HTTP request headers used to warm a URL for a given device.
     * Shared by warm_one() and refetch_without_buffer().
     *
     * @param string $device 'desktop' or 'mobile'.
     * @return array
     */
    private static function build_warm_headers( $device ) {
        // Real browser User-Agents for BOTH devices. A custom UA like the old
        // "EasyOptimizerPreload/1.0" is fingerprinted as automation and blocked
        // / challenged by host WAFs and bot protection (wpx.net and others),
        // which made warm requests fail and pages never cache. We identify our
        // own preload requests via the ?eopreload=1 query arg instead of a
        // custom header (a non-standard header is itself a bot signal).
        $desktop_ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36';
        $mobile_ua  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

        $ua = ( 'mobile' === $device ) ? $mobile_ua : $desktop_ua;
        /** Filter the preload User-Agent (per device). */
        $ua = (string) apply_filters( 'easyopt_preload_user_agent', $ua, $device );

        return array(
            'User-Agent'      => $ua,
            'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Accept-Encoding' => 'gzip',
            // Synthetic logged-in cookie. WP-aware server caches (wpx, Varnish,
            // LiteSpeed LSCache, nginx FastCGI, Kinsta, WP Engine, SiteGround…)
            // bypass themselves whenever a wordpress_logged_in_* cookie is
            // present — they check only that it EXISTS, never that it is valid.
            // That bypass is what lets our PHP/output buffer run and write the
            // cache during preload; without it the host serves its own cached
            // copy and nothing of ours is generated ("numbers move, 0 cached").
            // The cookie is intentionally invalid, so is_user_logged_in() stays
            // false and a normal GUEST page is cached (has_auth_cookie() skips
            // the synthetic cookie on preload requests). Same technique as
            // FlyingPress. Filterable for hosts that need it disabled/changed.
            'Cookie'          => (string) apply_filters( 'easyopt_preload_cookie', 'wordpress_logged_in_1=1', $device ),
        );
    }

    /**
     * Refetch a URL with EO's live output buffer disabled (?eonobuf=1), to
     * recover the real HTML on sites where our buffer blanks the response.
     *
     * @param string $url     Clean target URL.
     * @param string $device  'desktop' or 'mobile'.
     * @param int    $timeout Request timeout (seconds).
     * @return string Response body, or '' on failure.
     */
    private static function refetch_without_buffer( $url, $device, $timeout ) {
        $warm_url = self::warm_url( $url, '', array( 'eonobuf' => '1' ) );
        $resp = wp_remote_get(
            $warm_url,
            array(
                'timeout'     => $timeout,
                'blocking'    => true,
                'sslverify'   => false,
                'redirection' => 0, // (2.5.0 / CB-1) recover only a genuine 200 body for THIS url.
                'headers'     => self::build_warm_headers( $device ),
            )
        );
        if ( is_wp_error( $resp ) ) {
            return '';
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( 200 !== $code && 206 !== $code ) {
            return '';
        }
        $body = wp_remote_retrieve_body( $resp );
        return is_string( $body ) ? $body : '';
    }

    /* ───────────────────────────────────────────────
     *  Concurrent-HTTP capture path (Phase 3/4) — opt-in
     * ─────────────────────────────────────────────── */

    /**
     * Decide the batch size for the preload queue. Batching only helps — and
     * only engages — on the CAPTURE path (buffer flagged unreliable, so we
     * fetch full bodies and write the cache ourselves) with curl_multi
     * available. On the common fire-and-forget path it returns 1 so the runner
     * keeps its per-task loop. Size tracks the governor's concurrency and drops
     * to 1 under load, so it never adds pressure the governor is trying to shed.
     *
     * @param int    $size     Incoming default (1).
     * @param string $group
     * @param string $callback
     * @return int
     */
    public static function filter_queue_batch_size( $size, $group, $callback ) {
        if ( self::QUEUE_GROUP !== $group ) {
            return $size;
        }
        // Only the capture path benefits; the fast path fires-and-forgets 1×1.
        if ( ! self::batch_path_available() ) {
            return 1;
        }
        // The batch IS the in-flight count on this path — curl_multi drives
        // every handle at once — and filter_queue_concurrency pins the runner
        // count to 1 here, so the budget maps straight onto the batch size
        // instead of being multiplied by the number of runners.
        $cap = self::inflight_cap();
        if ( $cap < 2 ) {
            return 1; // budget backed off → single path (keeps DEFER semantics).
        }
        $want = (int) apply_filters( 'easyopt_preload_batch_size', 5 );
        return max( 2, min( $want, $cap, 10 ) );
    }

    /** Convert an assoc header array to curl "Key: Value" lines, dropping
     *  Accept-Encoding (CURLOPT_ENCODING manages gzip + auto-decompress). */
    private static function curl_header_lines( $headers ) {
        $lines = array();
        foreach ( (array) $headers as $k => $v ) {
            if ( 0 === strcasecmp( $k, 'Accept-Encoding' ) ) {
                continue;
            }
            $lines[] = $k . ': ' . $v;
        }
        return $lines;
    }

    /**
     * Batch handler for the capture path: warm several URLs concurrently with
     * curl_multi (one process, shared connection pool), then optimize + store
     * each returned body. Mirrors every guard of the single task_warm_url path
     * so nothing is lost — the plugin toggle, the separate-mobile companion
     * enqueue, the paused-state clear, and the per-device already-cached skip.
     *
     * Registered via EasyOpt_Queue::register_batch_handler(); only invoked when
     * filter_queue_batch_size() returns ≥ 2. Feeds the governor ONE latency
     * sample (batch average) + the worst status code per batch, so a 429/5xx in
     * the batch still triggers backoff.
     *
     * @param array $tasks Claimed queue rows (each with id, lock_token, task_data).
     * @return array{done:int[],retry:array<int,string>}
     */
    public static function warm_batch_multi( $tasks ) {
        $done  = array();
        $retry = array();
        if ( empty( $tasks ) || ! is_array( $tasks ) ) {
            return array( 'done' => $done, 'retry' => $retry );
        }

        // Toggle flipped off mid-run → drop all (rows get DELETEd).
        if ( ! (int) EasyOpt_Config::get( 'cache_preload', 0 ) ) {
            foreach ( $tasks as $t ) {
                $done[] = (int) $t['id'];
            }
            return array( 'done' => $done, 'retry' => $retry );
        }

        // (2.5.5) curl_multi availability is the only structural guard left;
        // the CPU/memory load guard is gone (host-scoped metric).
        if ( ! function_exists( 'curl_multi_init' ) ) {
            foreach ( $tasks as $t ) {
                $retry[ (int) $t['id'] ] = 'deferred (no curl_multi)';
            }
            return array( 'done' => $done, 'retry' => $retry );
        }

        // First successful batch clears any paused UI state.
        if ( 'paused' === get_option( 'easyopt_cache_preload_status', 'idle' ) ) {
            update_option( 'easyopt_cache_preload_status', 'running', false );
            delete_transient( 'easyopt_cache_preload_paused_reason' );
        }

        $separate_mobile = (int) EasyOpt_Config::get( 'cache_separate_mobile', 1 );
        $timeout = (int) apply_filters( 'easyopt_preload_warm_timeout', 12, '', 'desktop' );
        $timeout = max( 3, min( 60, $timeout ) );

        $mh      = curl_multi_init();
        $handles = array(); // id => [h, url, is_mobile]
        foreach ( $tasks as $t ) {
            $id = (int) $t['id'];
            $td = json_decode( isset( $t['task_data'] ) ? $t['task_data'] : '', true );
            $td = is_array( $td ) ? $td : array();
            $url    = (string) ( $td['url'] ?? '' );
            $device = (string) ( $td['device'] ?? '' );

            if ( '' === $url ) {
                $done[] = $id; // junk → delete
                continue;
            }
            if ( '' === $device ) {
                $device = 'desktop';
                if ( $separate_mobile ) {
                    // Preserve the mobile companion exactly like task_warm_url.
                    self::warm_queue()->add_task( array( 'url' => $url, 'device' => 'mobile' ), self::PRIO_POST );
                }
            }
            $is_mobile = ( 'mobile' === $device );

            // Per-device already-cached skip.
            if ( self::cache_already_written( $url, $is_mobile ) ) {
                // (2.5.0 / C3) Confirm the existing file so it counts.
                if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
                    EasyOpt_Preload_Results::confirm( $url, $is_mobile );
                }
                $done[] = $id;
                continue;
            }
            if ( $is_mobile && ! $separate_mobile ) {
                $done[] = $id;
                continue;
            }

            // Capture fetch: full body, EO buffer disabled (?eonobuf=1).
            $warm_url = self::warm_url( $url, '', array( 'eonobuf' => '1' ) );
            $ch = curl_init();
            curl_setopt_array( $ch, array(
                CURLOPT_URL            => $warm_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => (int) min( 10, $timeout ),
                CURLOPT_ENCODING       => 'gzip',
                CURLOPT_HTTPHEADER     => self::curl_header_lines( self::build_warm_headers( $device ) ),
            ) );
            curl_multi_add_handle( $mh, $ch );
            $handles[ $id ] = array( 'h' => $ch, 'url' => $url, 'is_mobile' => $is_mobile );
        }

        if ( empty( $handles ) ) {
            curl_multi_close( $mh );
            return array( 'done' => array_values( array_unique( $done ) ), 'retry' => $retry );
        }

        // Drive all transfers to completion.
        $t0 = microtime( true );
        do {
            $status = curl_multi_exec( $mh, $running );
            if ( $running ) {
                curl_multi_select( $mh, 1.0 );
            }
        } while ( $running && CURLM_OK === $status );
        $elapsed_ms = ( microtime( true ) - $t0 ) * 1000;

        $worst_code = 200;
        $count      = max( 1, count( $handles ) );
        $redirects  = array(); // deferred: [url, device, redir_target, code]
        foreach ( $handles as $id => $info ) {
            $ch    = $info['h'];
            $errno = curl_errno( $ch );
            $code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $redir = (string) curl_getinfo( $ch, CURLINFO_REDIRECT_URL );
            $body  = ( 0 === $errno ) ? curl_multi_getcontent( $ch ) : '';
            curl_multi_remove_handle( $mh, $ch );
            curl_close( $ch );

            if ( 0 !== $errno ) {
                $retry[ $id ] = 'curl error: ' . curl_strerror( $errno );
                $worst_code   = max( $worst_code, 599 );
                continue;
            }
            if ( 200 === $code || 206 === $code ) {
                if ( is_string( $body ) && '' !== $body && class_exists( 'EasyOpt_Cache' ) ) {
                    $opt = self::optimize_prefetched_html( $info['url'], $body );
                    // store_prefetched_html() confirms the results ledger
                    // (C3) after a successful write.
                    EasyOpt_Cache::store_prefetched_html( $info['url'], $opt, $info['is_mobile'] );
                }
                $done[] = $id;
            } elseif ( 429 === $code || $code >= 500 ) {
                $retry[ $id ] = 'HTTP ' . $code;
                $worst_code   = max( $worst_code, $code );
            } elseif ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
                // (2.5.0 / M1) Same redirect POLICY as warm_one(): resolve
                // the chain and either warm the same-key canonical target or
                // record 'redirected'. CURLINFO_REDIRECT_URL gives the
                // absolute Location even though FOLLOWLOCATION is off.
                // Deferred until after the multi handle is closed so the
                // follow-up blocking probes don't contend with it.
                $redirects[] = array( $info['url'], $info['is_mobile'] ? 'mobile' : 'desktop', $redir, $code );
                $done[]      = $id; // terminal for this task row either way
            } else {
                // Plain 4xx terminal → record why, then delete the row.
                if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
                    EasyOpt_Preload_Results::mark_uncacheable( $info['url'], 'HTTP ' . $code, $code );
                }
                $done[] = $id;
            }
        }
        curl_multi_close( $mh );

        // (2.5.0 / M1) Resolve collected redirects now that the multi
        // handle is closed. handle_redirect() may issue a few small
        // blocking probes per URL; batches are ≤10 and redirects are rare.
        foreach ( $redirects as $r ) {
            self::handle_redirect( $r[0], $r[1], $r[2], (int) $r[3], $timeout );
        }

        // One governor step for the whole batch: average latency + worst code
        // (so a single 429/5xx still backs the whole group off).
        self::governor_record( $elapsed_ms / $count, $worst_code, 0 );

        return array( 'done' => array_values( array_unique( $done ) ), 'retry' => $retry );
    }

    /**
     * Record whether the in-process output buffer reliably captures pages on
     * this site. When unreliable, start_output_buffer() stops opening the
     * buffer for normal visitors (preventing blank pages caused by a
     * theme/host that rewrites the buffer stack); caching + optimization then
     * run through the preloader instead. Preload loopbacks keep using the
     * buffer, so this flag is continuously re-evaluated and self-heals.
     *
     * @param bool $reliable True if the buffer captured; false if it didn't.
     */
    private static function set_live_buffer_reliable( $reliable ) {
        if ( $reliable ) {
            // Proof the buffer captured → clear the flag AND the strike
            // counter. Any single healthy capture resets the world.
            delete_transient( 'easyopt_live_buffer_blank_streak' );
            if ( 0 !== (int) get_option( 'easyopt_skip_live_buffer', 0 ) ) {
                delete_option( 'easyopt_skip_live_buffer' );
                if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                    EasyOpt_Debug_Log::error( 'buffer', 'Live buffer verified healthy again — skip-live-buffer flag cleared; on-visit optimization and caching re-enabled.' );
                }
            }
            return;
        }

        // (2.5.0 hardening) The old behaviour flipped a PERMANENT site-wide
        // kill switch on the FIRST blank loopback body — one WAF challenge,
        // server-cache hiccup or truncated response silently disabled ALL
        // live optimization and visit-driven caching for every real visitor,
        // indefinitely. Three defences now:
        //
        //  1. THREE consecutive blank loopbacks within 15 minutes are
        //     required before the flag trips (transient-backed streak; a
        //     healthy capture or the TTL resets it). One-off flukes never
        //     reach the threshold.
        //  2. The flag itself EXPIRES: it is stored as a future timestamp
        //     and honoured for 12 hours. Genuinely broken environments
        //     re-trip it within one preload pass; a transient environment
        //     issue heals automatically. (Bonus: legacy installs whose
        //     option holds the old literal 1 are treated as EXPIRED, so
        //     stale flags from earlier versions self-heal on update.)
        //  3. Tripping is LOGGED at error level — this switch is never
        //     silent again.
        $streak = (int) get_transient( 'easyopt_live_buffer_blank_streak' );
        $streak++;
        set_transient( 'easyopt_live_buffer_blank_streak', $streak, 15 * MINUTE_IN_SECONDS );

        $threshold = max( 2, (int) apply_filters( 'easyopt_live_buffer_blank_threshold', 3 ) );
        if ( $streak < $threshold ) {
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::error( 'buffer', sprintf( 'Preload loopback returned a blank body (strike %d of %d). Live buffer stays ON; the flag trips only on %d consecutive blanks within 15 minutes.', $streak, $threshold, $threshold ) );
            }
            return;
        }

        $until = time() + 12 * HOUR_IN_SECONDS;
        update_option( 'easyopt_skip_live_buffer', $until, false );
        delete_transient( 'easyopt_live_buffer_blank_streak' );
        if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
            EasyOpt_Debug_Log::error( 'buffer', sprintf( '%d consecutive blank loopback bodies — live output buffer disabled for normal visitors for 12 hours (until %s UTC). Optimization + caching are handled by the preloader meanwhile; preload loopbacks keep re-testing the buffer and clear the flag on the first healthy capture.', $threshold, gmdate( 'Y-m-d H:i:s', $until ) ) );
        }
    }

    /**
     * Is the live output buffer currently disabled for normal visitors?
     *
     * (2.5.0) THE single read point for the skip-live-buffer flag. The flag
     * is a future UNIX timestamp; it is honoured only until it expires. A
     * legacy literal 1 (pre-2.5.0-release installs) reads as long-expired and is
     * ignored — and lazily deleted so the row doesn't linger.
     *
     * @return bool
     */
    /** (2.5.4 / perf #55) True while optimize_prefetched_html() runs. */
    private static $runner_render = false;

    /**
     * (2.5.4 / perf #55) Whether the current process is rendering on behalf
     * of the preloader — either a warm loopback (?eopreload=1 sets the
     * EASYOPT_IS_PRELOAD_REQUEST constant very early) or the queue runner's
     * in-process optimization of prefetched HTML.
     *
     * @return bool
     */
    public static function is_runner_render() {
        if ( self::$runner_render ) {
            return true;
        }
        return defined( 'EASYOPT_IS_PRELOAD_REQUEST' ) && EASYOPT_IS_PRELOAD_REQUEST;
    }

    public static function live_buffer_skipped() {
        // (2.5.0) Manual override, evaluated BEFORE the auto-detection flag:
        //   true  → always skip the live buffer (preloader-only strategy;
        //           immune to the self-heal that deletes the option)
        //   false → never skip it, regardless of blank-loopback strikes
        //   null  → automatic behaviour (default)
        $forced = apply_filters( 'easyopt_skip_live_buffer', null );
        if ( is_bool( $forced ) ) {
            return $forced;
        }
        $until = (int) get_option( 'easyopt_skip_live_buffer', 0 );
        if ( 0 === $until ) {
            return false;
        }
        if ( time() >= $until ) {
            delete_option( 'easyopt_skip_live_buffer' );
            return false;
        }
        return true;
    }

    /**
     * Did a cache file for this URL/device already get written (e.g. by the
     * live output buffer during this very render on a normal site)? Used to
     * decide whether the preloader needs to optimize + write the fetched body
     * itself. Checks both the gzip and plain variants.
     *
     * @param string $url       Target URL.
     * @param bool   $is_mobile Mobile variant?
     * @return bool
     */
    private static function cache_already_written( $url, $is_mobile ) {
        if ( ! class_exists( 'EasyOpt_Cache' )
             || ! method_exists( 'EasyOpt_Cache', 'cache_paths_for_url' ) ) {
            return false;
        }
        $paths = EasyOpt_Cache::cache_paths_for_url( $url );
        $gz    = $is_mobile
            ? ( isset( $paths['mobile'] ) ? (string) $paths['mobile'] : '' )
            : ( isset( $paths['desktop'] ) ? (string) $paths['desktop'] : '' );
        if ( '' === $gz ) {
            return false;
        }
        if ( file_exists( $gz ) ) {
            return true;
        }
        // cache_paths_for_url() returns the gzip path; also check the plain one.
        $plain = preg_replace( '/_gzip$/', '', $gz );
        return ( is_string( $plain ) && '' !== $plain && file_exists( $plain ) );
    }

    /**
     * Run EO's optimization passes on HTML fetched for a specific URL.
     *
     * The preloader fetches each page over HTTP and (on hostile sites) is the
     * only place optimization can run, because the in-process output buffer is
     * rewritten by the theme/host. We can't rely on the global WP query here —
     * the runner's query is the queue/run request, not the target page — so we
     * pin the page identity for the URL/page-keyed passes:
     *   - REQUEST_URI -> the target path, so home_url(REQUEST_URI) checks in
     *     Delay JS / Fonts / Unused CSS resolve THIS page's exclusion rules.
     *   - easyopt_rucss_post_id -> the target post ID, so Unused CSS writes its
     *     used-CSS under the correct per-page key, not the runner's. For
     *     non-singular URLs the ID is 0; Unused CSS then resolves an empty
     *     page-context and safely skips rather than mis-keying.
     * The fetched body is the logged-out page, so we also lift the logged-in
     * guards in case the runner is authenticated (admin-triggered synchronous
     * preload).
     *
     * Fully bulletproof: any failure returns the unoptimized HTML, so caching
     * still succeeds.
     *
     * @param string $url  Target URL whose HTML this is.
     * @param string $html Fetched HTML body.
     * @return string Optimized HTML, or the input unchanged on failure.
     */
    private static function optimize_prefetched_html( $url, $html ) {
        if ( ! is_string( $html ) || '' === $html ) {
            return $html;
        }
        // (2.5.4 / perf #55) Mark this process as a runner-originated render
        // for the duration of the pipeline — clear_learned_url() uses it to
        // suppress per-URL edge-purge hooks while the run itself is writing
        // the very files being "cleared".
        self::$runner_render = true;
        if ( ! class_exists( 'Easy_Optimizer' ) || ! method_exists( 'Easy_Optimizer', 'instance' ) ) {
            return $html;
        }
        $eo = Easy_Optimizer::instance();
        if ( ! is_object( $eo ) || ! method_exists( $eo, 'run_buffer_processors' ) ) {
            return $html;
        }

        // ── Pin the target page's context ────────────────────────────────
        $saved_uri = array_key_exists( 'REQUEST_URI', $_SERVER ) ? $_SERVER['REQUEST_URI'] : null;
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        if ( '' === $path ) {
            $path = '/';
        }
        $qs = (string) wp_parse_url( $url, PHP_URL_QUERY );
        $_SERVER['REQUEST_URI'] = $path . ( '' !== $qs ? '?' . $qs : '' );

        // (2.5.4 / perf #13) Use the collection-time post ID from the task
        // payload when available; url_to_postid() (several LIKE queries per
        // call) remains only as the fallback for companion-enqueued URLs
        // that never went through collect_urls().
        $post_id = isset( self::$task_post_ids[ $url ] ) ? (int) self::$task_post_ids[ $url ] : 0;
        if ( ! $post_id ) {
            $post_id = (int) url_to_postid( $url );
        }
        if ( ! $post_id ) {
            // The static front page resolves to 0 via url_to_postid(); recover
            // its ID so Unused CSS keys it as 'front' rather than skipping.
            if ( untrailingslashit( $url ) === untrailingslashit( home_url() )
                 && 'page' === get_option( 'show_on_front' ) ) {
                $post_id = (int) get_option( 'page_on_front' );
            }
        }
        $pin_pid = function () use ( $post_id ) {
            return $post_id ? $post_id : '';
        };
        add_filter( 'easyopt_rucss_post_id', $pin_pid );
        add_filter( 'easyopt_rucss_logged_in', '__return_true' );
        add_filter( 'easyopt_lcp_logged_in', '__return_true' );
        add_filter( 'easyopt_delay_js_admin', '__return_true' );
        add_filter( 'easyopt_defer_js_admin', '__return_true' );

        try {
            $out = $eo->run_buffer_processors( $html );
        } catch ( \Throwable $e ) {
            $out = $html;
        }

        // ── Restore ──────────────────────────────────────────────────────
        remove_filter( 'easyopt_rucss_post_id', $pin_pid );
        remove_filter( 'easyopt_rucss_logged_in', '__return_true' );
        remove_filter( 'easyopt_lcp_logged_in', '__return_true' );
        remove_filter( 'easyopt_delay_js_admin', '__return_true' );
        remove_filter( 'easyopt_defer_js_admin', '__return_true' );
        if ( null === $saved_uri ) {
            unset( $_SERVER['REQUEST_URI'] );
        } else {
            $_SERVER['REQUEST_URI'] = $saved_uri;
        }
        self::$runner_render = false; // (2.5.4 / perf #55)

        return ( is_string( $out ) && '' !== $out ) ? $out : $html;
    }

    // (2.3.3) on_warm_done() removed — it was never hooked anywhere. The
    // done-status flip is owned by the dashboard/status auto-correct path.

    /* ───────────────────────────────────────────────
     *  Traffic-aware retention (Phase 7) — opt-in, default OFF
     * ─────────────────────────────────────────────── */

    /**
     * Prune page-cache files older than a configurable age on the daily GC
     * tick, so the cache directory doesn't grow without bound on very large
     * sites. Pruned pages simply regenerate on their next visit/preload.
     *
     * DISABLED BY DEFAULT: the TTL filter returns 0, in which case this method
     * does nothing at all (no filesystem walk, no load) — an explicit opt-in is
     * required, so it can never regress an existing install. Bounded per run so
     * it stays a light addition to the existing GC pass.
     *
     * @return void
     */
    public static function gc_stale_cache() {
        $ttl_days = (int) apply_filters( 'easyopt_preload_stale_ttl_days', 0 );
        if ( $ttl_days <= 0 ) {
            return; // opt-in only.
        }
        if ( ! defined( 'WP_CONTENT_DIR' ) ) {
            return;
        }
        $root = trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt/';
        if ( ! is_dir( $root ) ) {
            return;
        }

        $cutoff   = time() - ( $ttl_days * DAY_IN_SECONDS );
        $max      = (int) apply_filters( 'easyopt_preload_stale_max_per_run', 5000 );
        $max      = max( 100, $max );
        $removed  = 0;
        $touched_dirs = array(); // dirs where we unlinked a desktop page file

        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ( $it as $file ) {
                if ( $removed >= $max ) {
                    break;
                }
                $name = $file->getFilename();
                // Only the rendered page files — never touch minified assets,
                // used-CSS, markers, or the directory scaffolding here.
                $is_page = ( 0 === strpos( $name, 'index' ) )
                    && ( '.html' === substr( $name, -5 ) || '.html_gzip' === substr( $name, -10 ) );
                if ( ! $is_page || ! $file->isFile() ) {
                    continue;
                }
                $mtime = @$file->getMTime();
                if ( $mtime && $mtime < $cutoff ) {
                    $dir = $file->getPath();
                    if ( @unlink( $file->getPathname() ) ) {
                        $removed++;
                        // (2.6.0) Track EVERY directory we removed a page file
                        // from, not only ones that lost a desktop variant. A
                        // URL cached mobile-only (or under a role tag) still
                        // occupies one directory in the get_stats() count, so
                        // when GC empties it the counter has to follow. The
                        // emptiness test below is what decides; this set is
                        // just the candidate list.
                        $touched_dirs[ $dir ] = true;
                    }
                }
            }
        } catch ( \Throwable $e ) {
            // Best-effort GC: never let a filesystem hiccup break the cron.
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::error( 'preload', 'Stale-cache GC aborted: ' . $e->getMessage() );
            }
            return;
        }

        // (2.5.0 / H2) Decrement the atomic page counter for each directory
        // whose desktop page file we removed and where no desktop variant
        // remains — the counter only tracks desktop pages (mobile is the same
        // page). Without this the dashboard's "Pages cached" would drift
        // upward forever as stale pages were pruned. The daily
        // reconcile_page_counter() is the coarse backstop; this keeps the
        // count accurate between full recomputes.
        if ( ! empty( $touched_dirs ) && class_exists( 'EasyOpt_Cache_Counter' )
             && method_exists( 'EasyOpt_Cache_Counter', 'decrement' ) ) {
            $easyopt_gone = 0;
            foreach ( array_keys( $touched_dirs ) as $dir ) {
                // (2.6.0) Test for ANY surviving variant, not just the desktop
                // pair. "Pages cached" counts directories (get_stats() builds
                // $page_dirs), so a directory that still holds index-mobile.html
                // or a role-keyed variant is still one cached URL and must not
                // be subtracted. One glob replaces the two file_exists() calls
                // and covers every variant name without enumerating them.
                $easyopt_left = (array) glob( trailingslashit( $dir ) . 'index*.html*' );
                if ( empty( $easyopt_left ) ) {
                    $easyopt_gone++;
                }
            }
            if ( $easyopt_gone > 0 ) {
                // One UPDATE for the whole GC pass instead of one per directory.
                EasyOpt_Cache_Counter::decrement( $easyopt_gone );
            }
        }

        if ( $removed > 0 && class_exists( 'EasyOpt_Debug_Log' ) ) {
            EasyOpt_Debug_Log::info( 'preload', sprintf( 'Stale-cache GC removed %d page file(s) older than %dd.', $removed, $ttl_days ) );
        }
    }

    /* ───────────────────────────────────────────────
     *  CPU / memory helpers (kept verbatim from 1.5.x)
     * ─────────────────────────────────────────────── */




    /** Count CPUs described by a sysfs range spec such as "0-3" or "0-1,4-7". */
    private static function count_cpu_range( $spec ) {
        $spec = trim( (string) $spec );
        if ( '' === $spec ) {
            return 0;
        }
        $total = 0;
        foreach ( explode( ',', $spec ) as $part ) {
            $part = trim( $part );
            if ( '' === $part ) {
                continue;
            }
            if ( false !== strpos( $part, '-' ) ) {
                $bounds = explode( '-', $part, 2 );
                $a      = (int) $bounds[0];
                $b      = (int) $bounds[1];
                if ( $b >= $a ) {
                    $total += ( $b - $a + 1 );
                }
            } else {
                $total += 1;
            }
        }
        return $total;
    }

    /* ───────────────────────────────────────────────
     *  Cached-URL filter (kept from 1.5.x)
     * ─────────────────────────────────────────────── */

    public static function filter_already_cached( $urls ) {
        if ( ! class_exists( 'EasyOpt_Cache' ) || empty( $urls ) ) {
            return $urls;
        }
        if ( ! method_exists( 'EasyOpt_Cache', 'cache_paths_for_url' ) ) {
            return $urls;
        }
        $separate_mobile = (int) EasyOpt_Config::get( 'cache_separate_mobile', 1 );

        // (2.6.0) A variant counts as cached when EITHER the gzip file or the
        // plain .html exists. Checking only *.html_gzip meant that on
        // OpenLiteSpeed — and anywhere the user turned Gzip off — every page
        // warmed by a live visitor looked uncached forever, because since
        // 2.5.7 capture_buffer() writes no gzip copy in those configurations.
        // The whole site was then re-queued on every build: "URLs in waiting"
        // climbed while "Pages cached" never moved, since the re-warm found
        // index.html already present and so never fired easyopt_page_cached.
        // Ordering is deliberate — gzip first, because on a normally
        // configured site it is present and the second stat() never runs.
        $exists = function ( $gz, $plain ) {
            if ( '' !== (string) $gz && file_exists( $gz ) ) {
                return true;
            }
            return '' !== (string) $plain && file_exists( $plain );
        };

        return array_values( array_filter( $urls, function ( $url ) use ( $separate_mobile, $exists ) {
            $paths = EasyOpt_Cache::cache_paths_for_url( $url );
            if ( empty( $paths ) ) {
                return true; // can't resolve — let it through.
            }
            if ( ! $exists(
                isset( $paths['desktop'] ) ? $paths['desktop'] : '',
                isset( $paths['desktop_plain'] ) ? $paths['desktop_plain'] : ''
            ) ) {
                return true;
            }
            if ( $separate_mobile && ! $exists(
                isset( $paths['mobile'] ) ? $paths['mobile'] : '',
                isset( $paths['mobile_plain'] ) ? $paths['mobile_plain'] : ''
            ) ) {
                return true;
            }
            return false; // already fully cached — skip.
        } ) );
    }

    /* ───────────────────────────────────────────────
     *  Legacy public API shims (third-party plugins may call these)
     * ─────────────────────────────────────────────── */

    /**
     * 1.5.x exposed run_batch() as the cron worker. 1.6.0 doesn't have
     * "batches" — each URL is its own queue task — but anything that
     * still calls this gets routed into kicking the dispatcher.
     */
    public static function run_batch() {
        self::warm_queue()->start_queue();
    }

    /* ───────────────────────────────────────────────
     *  AJAX handlers (status surface for the dashboard UI)
     * ─────────────────────────────────────────────── */

    public static function ajax_start() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easy-optimizer' ) ), 403 );
        }
        check_ajax_referer( 'easyopt_nonce', 'nonce' );

        if ( self::start() ) {
            wp_send_json_success( array(
                'message' => __( 'Preload started.', 'easy-optimizer' ),
                'total'   => (int) get_option( 'easyopt_cache_preload_total', 0 ),
            ) );
        }
        wp_send_json_error( array( 'message' => __( 'Could not start preload (DB issue?).', 'easy-optimizer' ) ) );
    }

    public static function ajax_stop() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easy-optimizer' ) ), 403 );
        }
        check_ajax_referer( 'easyopt_nonce', 'nonce' );
        self::stop();
        wp_send_json_success( array( 'message' => __( 'Preload stopped.', 'easy-optimizer' ) ) );
    }

    public static function ajax_status() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easy-optimizer' ) ), 403 );
        }
        check_ajax_referer( 'easyopt_nonce', 'nonce' );

        // for the same URL are 2 rows internally, but 1 in the UI). We
        // count DISTINCT urls in pending+processing to get "URLs left",
        // independent of how many device rows back each one.
        $total           = (int) get_option( 'easyopt_cache_preload_total', 0 );
        $urls_remaining  = EasyOpt_Queue::count_distinct_urls(
            self::QUEUE_GROUP,
            self::HOOK_WARM_URL
        );
        $build_pending   = self::build_queue_instance()->get_pending_count();
        $remaining_total = $urls_remaining + $build_pending;
        $done            = max( 0, $total - $urls_remaining );

        $status = (string) get_option( 'easyopt_cache_preload_status', 'idle' );

        // (2.5.0 / M2) Display-only completion: never WRITE state from a
        // status read. The authoritative running→done transition happens on
        // the queue's group-drained action / the watchdog. Here we only
        // reflect an already-empty queue as 'done' for the response.
        if ( 'running' === $status && 0 === $remaining_total && $total > 0 ) {
            $status = 'done';
        }

        $failed  = self::warm_queue()->get_failed_count();
        $results = class_exists( 'EasyOpt_Preload_Results' )
            ? EasyOpt_Preload_Results::counts()
            : array();

        // (2.5.0 / C3) Prefer the results ledger's confirmed totals when a
        // run exists — "done" then means CONFIRMED CACHED, not dequeued.
        if ( ! empty( $results ) && (int) $results['total'] > 0 ) {
            $total = (int) $results['total'];
            $done  = (int) $results['cached'];
        }

        wp_send_json_success( array(
            'status'        => $status,
            'total'         => $total,
            'done'          => $done,
            'paused_reason' => (string) get_transient( 'easyopt_cache_preload_paused_reason' ),
            'failed'        => $failed,
            'results'       => $results,
            'version'       => (int) get_option( 'easyopt_state_version', 0 ),
        ) );
    }

    /**
     * Increment the global state version. Called only on major state
     * changes (start, stop, build complete) — NOT per-warm.
     *
     * 1.7.1 — no longer called per-warm. The transient deletion was
     * removed; cache_stats expires on its own 60s TTL. This reduces
     * DB writes from ~N per preload run to ~3 (start + build + done).
     */
    public static function bump_state_version() {
        // (2.5.0 / M7) Coalesce bursts. A cache-clear that fires several
        // purge hooks in one request, or a batch handler confirming many
        // URLs, could otherwise bump the option many times per request.
        // A 0.2 s process-local throttle collapses those into one write
        // without affecting the cross-request cadence the dashboard polls.
        static $last     = 0.0;
        static $deferred = false;
        $now = microtime( true );
        if ( $now - $last < 0.2 ) {
            // (2.5.0 / B7) Don't DROP a throttled bump — the skipped call
            // might be the request's LAST state change, leaving the
            // dashboard's version poll blind to it. Coalesce into a single
            // guaranteed flush at shutdown instead.
            if ( ! $deferred && function_exists( 'add_action' ) ) {
                $deferred = true;
                add_action( 'shutdown', array( __CLASS__, 'flush_state_version_bump' ), 0 );
            }
            return;
        }
        $last = $now;

        $v = (int) get_option( 'easyopt_state_version', 0 );
        update_option( 'easyopt_state_version', $v + 1, false );
    }

    /** (2.5.0 / B7) Shutdown flush for a bump coalesced by the throttle. */
    public static function flush_state_version_bump() {
        $v = (int) get_option( 'easyopt_state_version', 0 );
        update_option( 'easyopt_state_version', $v + 1, false );
    }

    /**
     * (2.5.0 / M2 + C3) The queue emitted "group drained" for the preload
     * group — the authoritative, write-once signal that the warm queue is
     * empty. Resolve the run to a CONFIRMED-done state here instead of as a
     * side effect of a GET status read.
     *
     * @param string $group    Drained group.
     * @param string $callback Drained callback action.
     */
    public static function on_group_drained( $group, $callback ) {
        if ( self::QUEUE_GROUP !== $group ) {
            return;
        }
        self::maybe_mark_done();
    }

    /**
     * (2.5.0 / C3) Flip status → 'done' only when there is genuinely no
     * outstanding work: no pending warm tasks, no pending build task, and
     * a run actually happened (option total or ledger rows > 0). "Done"
     * now means the queue is empty AND every confirmed outcome is recorded
     * in the results ledger — not merely "dequeued".
     */
    public static function maybe_mark_done() {
        $status = (string) get_option( 'easyopt_cache_preload_status', 'idle' );
        if ( 'running' !== $status ) {
            // (2.5.0 / B3) A run can DRAIN while status is 'paused' (last
            // tasks complete, then the governor/CPU gate pauses). The old
            // running-only guard left such a run stuck on 'paused' forever
            // — nothing ever flipped it back. Resolve from 'paused' too,
            // but ONLY once the pause itself has lapsed; a live pause is
            // still deliberate non-movement and is left alone.
            if ( 'paused' !== $status ) {
                return;
            }
            $gov        = self::governor_state();
            $gov_paused = is_array( $gov ) && isset( $gov['paused_until'] ) && (int) $gov['paused_until'] > time();
            $cpu_paused = '' !== (string) get_transient( 'easyopt_cache_preload_paused_reason' );
            if ( $gov_paused || $cpu_paused ) {
                return;
            }
        }
        $warm_pending  = EasyOpt_Queue::count_distinct_urls( self::QUEUE_GROUP, self::HOOK_WARM_URL );
        $build_pending = self::build_queue_instance()->get_pending_count();
        if ( $warm_pending > 0 || $build_pending > 0 ) {
            return;
        }

        $option_total = (int) get_option( 'easyopt_cache_preload_total', 0 );
        $ledger_total = 0;
        if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
            $counts       = EasyOpt_Preload_Results::counts();
            $ledger_total = (int) $counts['total'];
        }
        if ( $option_total < 1 && $ledger_total < 1 ) {
            // (2.6.0) Nothing was ever seeded, and (per the checks above) the
            // queue is empty — so no run is in progress and the status must
            // not stay 'running'.
            //
            // This is reachable in normal use and used to be a permanent dead
            // end. enqueue_companion() flips the status to 'running' whenever
            // a visitor hits an uncached page while preload is idle/done, but
            // it never seeds easyopt_cache_preload_total — only start() does.
            // So on any site where the user never pressed "Preload Cache",
            // ordinary browsing set the status to 'running' and NOTHING could
            // ever clear it: this guard returned early, and the dashboard's
            // display-only fallback is itself gated on $preload_total > 0.
            // The dashboard then showed a preload permanently in progress with
            // a frozen "URLs in waiting" figure.
            //
            // Resolve to 'idle' rather than 'done' — no run happened, so
            // claiming completion would be a lie, and 'done' would also make
            // the progress display read "0 of 0".
            if ( 'idle' !== $status ) {
                update_option( 'easyopt_cache_preload_status', 'idle', false );
                self::bump_state_version();
            }
            return;
        }

        update_option( 'easyopt_cache_preload_status', 'done', false );
        self::bump_state_version();

        // (2.5.0 / B4) Open the post-completion reconcile window (see the
        // watchdog): rows still 'pending' right now resolve within 30 min
        // instead of freezing until the next run.
        set_transient( 'easyopt_preload_post_done_reconcile', 1, 30 * MINUTE_IN_SECONDS );

        // (2.5.0 consistency fix) Reconcile "Pages cached" to disk truth the
        // moment a run finishes. The atomic counter can lag or drift (object
        // cache staleness, a clear that reset it, writes that landed via a
        // path the increment hook missed), which showed up as "Pages cached:
        // 0" even though pages were on disk. A single authoritative recount
        // here realigns the dashboard number to the actual file count.
        if ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'reconcile_page_counter' ) ) {
            EasyOpt_Cache::reconcile_page_counter();
        }

        if ( class_exists( 'EasyOpt_Debug_Log' ) && class_exists( 'EasyOpt_Preload_Results' ) ) {
            $c = EasyOpt_Preload_Results::counts();
            EasyOpt_Debug_Log::info( 'preload', sprintf(
                'Preload run #%d complete: %d cached, %d redirected, %d uncacheable, %d failed (of %d URLs).',
                (int) $c['run'],
                (int) $c['cached'],
                (int) $c['redirected'],
                (int) $c['uncacheable'],
                (int) $c['failed'],
                (int) $c['total']
            ) );
        }
    }

    /**
     * (2.5.0 / C3) A warm task exhausted its retries. Record the URL as
     * failed in the results ledger so the dashboard shows it and the daily
     * revert pass can retry it later.
     *
     * @param string $group     Queue group.
     * @param string $callback  Callback action.
     * @param array  $task_data Decoded task payload.
     * @param string $error     Last error message.
     */
    public static function on_task_failed( $group, $callback, $task_data, $error ) {
        if ( self::QUEUE_GROUP !== $group || self::HOOK_WARM_URL !== $callback ) {
            return;
        }
        if ( ! is_array( $task_data ) || empty( $task_data['url'] ) ) {
            return;
        }
        if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
            EasyOpt_Preload_Results::mark_failed(
                (string) $task_data['url'],
                'warm failed: ' . (string) $error,
                0
            );
        }
    }

    /**
     * (2.5.0 / H5) Re-enqueue a URL's warm tasks (both devices when
     * separate_mobile is on). Does NOT kick the runner per-URL — the
     * revert pass calls kick_warm_queue() once after re-queuing a batch,
     * so a large revert doesn't fire hundreds of loopback dispatches.
     *
     * @param string $url
     */
    public static function requeue_url( $url ) {
        $url = is_string( $url ) ? trim( $url ) : '';
        if ( '' === $url ) {
            return;
        }
        $queue = self::warm_queue();
        $queue->add_task( array( 'url' => $url, 'device' => 'desktop' ), self::PRIO_POST );
        $separate_mobile = class_exists( 'EasyOpt_Config' )
            ? (int) EasyOpt_Config::get( 'cache_separate_mobile', 1 )
            : 1;
        if ( $separate_mobile ) {
            $queue->add_task( array( 'url' => $url, 'device' => 'mobile' ), self::PRIO_POST );
        }
    }

    /** (2.5.0 / H5) Kick the warm queue once (used after a revert batch). */
    public static function kick_warm_queue() {
        $status = (string) get_option( 'easyopt_cache_preload_status', 'idle' );
        if ( 'running' !== $status && 'paused' !== $status ) {
            update_option( 'easyopt_cache_preload_status', 'running', false );
            self::bump_state_version();
        }
        self::warm_queue()->start_queue();
    }

    /**
     * 1.6.1 — Ultra-cheap version poll for dashboard "instant" updates.
     * Returns a single integer. Two get_option calls; no DB joins.
     */
    public static function ajax_state_version() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easy-optimizer' ) ), 403 );
        }
        check_ajax_referer( 'easyopt_nonce', 'nonce' );
        wp_send_json_success( array(
            'version' => (int) get_option( 'easyopt_state_version', 0 ),
        ) );
    }

    /**
     * Count URLs that exist in our inventory but don't yet have a cache
     * file on disk. Result cached 5 min so dashboard polling doesn't
     * re-walk collect_urls every refresh.
     */
    public static function ajax_waiting_count() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easy-optimizer' ) ), 403 );
        }
        check_ajax_referer( 'easyopt_nonce', 'nonce' );

        $cached = get_transient( 'easyopt_waiting_count' );
        if ( false !== $cached && is_array( $cached ) ) {
            wp_send_json_success( $cached );
        }

        // (2.5.0 / M3) Fast path: when a results run exists, derive the
        // waiting count from the ledger (one grouped query) instead of
        // re-walking collect_urls() — the sitemap fetch + full post/term
        // crawl — inside a dashboard poll. The expensive scan only runs as
        // a fallback before the first run has seeded the ledger.
        if ( class_exists( 'EasyOpt_Preload_Results' ) ) {
            $counts = EasyOpt_Preload_Results::counts();
            if ( (int) $counts['total'] > 0 ) {
                $payload = array(
                    'total'   => (int) $counts['total'],
                    'waiting' => (int) $counts['pending'],
                );
                set_transient( 'easyopt_waiting_count', $payload, MINUTE_IN_SECONDS );
                wp_send_json_success( $payload );
            }
        }

        $urls    = self::collect_urls();
        $total   = count( $urls );
        $waiting = 0;
        if ( class_exists( 'EasyOpt_Cache' ) ) {
            $root = trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt/';
            foreach ( $urls as $url ) {
                if ( ! self::is_url_cached( $url, $root ) ) {
                    $waiting++;
                }
            }
        }
        $payload = array( 'total' => $total, 'waiting' => $waiting );
        set_transient( 'easyopt_waiting_count', $payload, 5 * MINUTE_IN_SECONDS );
        wp_send_json_success( $payload );
    }

    /**
     * Cheap "is this URL cached" check — file_exists only on the canonical
     * desktop variant. Skips gzip + role/mobile variants on purpose: if
     * even desktop isn't there, the URL is "waiting".
     */
    private static function is_url_cached( $url, $cache_root ) {
        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) ) {
            return false;
        }
        $host = strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', (string) $parts['host'] ) );
        $path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';

        $segments = array();
        foreach ( explode( '/', $path ) as $seg ) {
            $seg = sanitize_file_name( rawurldecode( $seg ) );
            if ( '' !== $seg ) {
                $segments[] = $seg;
            }
        }
        $clean = implode( '/', $segments );
        $dir   = $cache_root . $host . '/';
        if ( '' !== $clean ) {
            $dir .= $clean . '/';
        }
        return file_exists( $dir . 'index.html' ) || file_exists( $dir . 'index.html_gzip' );
    }

    /**
     * Phase 8 — recency priority within the post tier.
     *
     * Maps a post's newest-first rank to a claim priority: rank 0..(band-1) →
     * PRIO_POST, next band → PRIO_POST+1, and so on, capped at PRIO_TERM-1 so
     * posts are ALWAYS claimed before taxonomy archives. On a small site every
     * post lands in the first band (== PRIO_POST) so behaviour is unchanged;
     * on a large site the freshest pages warm first. No traffic tracking, no
     * per-request cost — the rank comes from the discovery loop we already run.
     *
     * @param int $rank Zero-based newest-first index of the post.
     * @return int Priority (lower = warmed sooner).
     */
    private static function post_recency_priority( $rank ) {
        if ( ! (bool) apply_filters( 'easyopt_preload_recency_priority', true ) ) {
            return self::PRIO_POST;
        }
        $band = (int) apply_filters( 'easyopt_preload_recency_band', 500 );
        $band = max( 50, $band );
        $prio = self::PRIO_POST + (int) floor( max( 0, (int) $rank ) / $band );
        return min( self::PRIO_TERM - 1, $prio );
    }

    /* ───────────────────────────────────────────────
     *  URL collection (1.7.1)
     *
     *  Pure DB queries, zero HTTP requests. Fast even on shared hosting.
     *
     *  1. Home URL
     *  2. All public post types (page, post, product, custom CPTs)
     *  3. Public taxonomies with rewrite enabled, EXCLUDING:
     *     - post_tag, product_tag (low SEO value, near-duplicates)
     *     - post_format (internal taxonomy)
     *
     *  Uses wp_suspend_cache_addition() during discovery to prevent
     *  the object cache from bloating with thousands of post objects.
     * ─────────────────────────────────────────────── */

    /**
     * (2.5.0 / M5) Canonicalise a discovered URL to the home scheme+host
     * and the site's trailing-slash convention, so warms land on 200
     * directly instead of paying a same-key redirect round-trip.
     *
     * - Host: if it matches home_url()'s host modulo a leading "www.",
     *   adopt the home host+scheme verbatim (fixes http↔https and
     *   www↔apex divergence). A genuinely different host is left untouched
     *   (it'll be dropped by the same-origin checks upstream anyway).
     * - Path: apply user_trailingslashit() ONLY to paths whose last
     *   segment has no "." (i.e. not a file like sitemap.xml), matching
     *   WordPress's own permalink slashing without touching feeds/files.
     *
     * @param string $url Absolute URL.
     * @return string Normalised URL, or '' if unparseable.
     */
    private static function normalize_collected_url( $url ) {
        $p = wp_parse_url( $url );
        if ( ! is_array( $p ) || empty( $p['host'] ) ) {
            return $url; // leave odd inputs to upstream filters.
        }
        static $home_host = null, $home_scheme = null;
        if ( null === $home_host ) {
            $home_host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
            $home_scheme = ( 'https' === strtolower( (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) ) ) ? 'https' : 'http';
        }

        $host   = strtolower( (string) $p['host'] );
        $scheme = isset( $p['scheme'] ) ? strtolower( (string) $p['scheme'] ) : $home_scheme;
        if ( self::strip_www( $host ) === self::strip_www( $home_host ) ) {
            // Same site canonicalising host/scheme → adopt home's exactly.
            $host   = $home_host;
            $scheme = $home_scheme;
        }

        $path = isset( $p['path'] ) ? (string) $p['path'] : '/';
        // Only slash "directory-like" paths (no dot in the last segment),
        // leaving files (sitemap.xml, foo.html) and the root alone.
        $last = substr( strrchr( '/' . $path, '/' ), 1 );
        if ( '/' !== $path && false === strpos( $last, '.' ) && function_exists( 'user_trailingslashit' ) ) {
            $path = user_trailingslashit( $path );
        }

        $out  = $scheme . '://' . $host;
        if ( isset( $p['port'] ) ) {
            $out .= ':' . (int) $p['port'];
        }
        $out .= ( '' !== $path ) ? $path : '/';
        if ( isset( $p['query'] ) && '' !== $p['query'] ) {
            $out .= '?' . $p['query'];
        }
        return $out;
    }

    /**
     * (2.5.5) Is this URL a warmable PAGE?
     *
     * The warm queue is for HTML pages only. Sitemaps, feeds and other
     * XML/text documents are the SOURCE of URLs, never targets — the cache
     * layer excludes them by design (\.xml / \.txt / /feed/ in both the
     * .htaccess conditions and is_request_cacheable), so queueing one
     * guarantees a "render not cacheable" veto on every run. Before this
     * gate, a sitemap could enter the queue (e.g. via a menu link or an
     * external hit picked up by preload-on-MISS) and, because the veto
     * reason wasn't terminal, be retried and re-warned every 12 hours
     * forever.
     *
     * Applied at BOTH intake points: collect_urls()'s $add closure and
     * enqueue_companion().
     *
     * @since 2.5.5
     * @param string $url Absolute URL.
     * @return bool
     */
    public static function is_warmable_url( $url ) {

        $path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
        if ( '' === $path ) {
            $path = '/';
        }

        // Non-HTML documents by extension.
        if ( preg_match( '/\.(xml|xsl|txt|gz|json|pdf|ico|css|js|jpe?g|png|gif|webp|avif|svg|woff2?)$/', $path ) ) {
            return false;
        }

        // Feeds and sitemap-style pretty paths.
        if ( false !== strpos( $path, '/feed/' )
            || preg_match( '#/(?:wp-sitemap[^/]*|sitemap(?:_index|s)?)/?$#', $path ) ) {
            return false;
        }

        // Endpoints the cache never stores.
        if ( preg_match( '#/(?:wp-admin|wp-login|wp-cron|xmlrpc|wp-json|wc-api)(?:[/.]|$)#', $path ) ) {
            return false;
        }

        /**
         * Filter whether a URL may enter the warm queue.
         *
         * @since 2.5.5
         * @param bool   $warmable Whether the URL is a warmable page.
         * @param string $url      The URL.
         */
        return (bool) apply_filters( 'easyopt_preload_warmable_url', true, $url );
    }

    public static function collect_urls() {

        $urls = array();
        $seen = array();

        // Reset the side-effect priority map for this build.
        self::$collected_priorities = array();
        self::$collected_post_ids   = array(); // (2.5.4 / perf #13)

        $add = function ( $url, $priority, $post_id = 0 ) use ( &$urls, &$seen ) {
            $url = is_string( $url ) ? trim( preg_replace( '/#.*$/', '', $url ) ) : '';
            if ( '' === $url ) {
                return;
            }
            // (2.5.0 / M5) Normalise to the home scheme+host and the
            // permalink structure's canonical trailing slash BEFORE dedup.
            // WordPress can hand back links in a scheme/host that differs
            // from home_url() (mixed-content installs, get_term_link
            // building from siteurl), which would otherwise 3xx-redirect on
            // warm and cost a second request per URL every run. Collecting
            // the already-canonical form means the warm hits 200 directly.
            $url = self::normalize_collected_url( $url );
            if ( '' === $url ) {
                return;
            }
            // (2.5.5) Pages only — see is_warmable_url().
            if ( ! self::is_warmable_url( $url ) ) {
                return;
            }
            // Keep the most-important (lowest) priority seen for this URL.
            if ( ! isset( self::$collected_priorities[ $url ] ) || $priority < self::$collected_priorities[ $url ] ) {
                self::$collected_priorities[ $url ] = $priority;
            }
            // (2.5.4 / perf #13) Remember which post produced this URL.
            if ( $post_id > 0 && ! isset( self::$collected_post_ids[ $url ] ) ) {
                self::$collected_post_ids[ $url ] = (int) $post_id;
            }
            if ( isset( $seen[ $url ] ) ) {
                return;
            }
            $seen[ $url ] = true;
            $urls[] = $url;
        };

        // ── 1. Home URL (tier: homepage) ──
        $add( home_url( '/' ), self::PRIO_HOME );

        // ── 2. Menu / navigation URLs (tier: menu) — incl. custom-link and
        //       archive items the post-type crawl below would otherwise miss. ──
        foreach ( self::menu_urls() as $menu_url ) {
            $add( $menu_url, self::PRIO_MENU );
        }

        // Suspend object cache additions during discovery — saves memory
        // on large sites.
        wp_suspend_cache_addition( true );

        // ── 3. All public post types (tier: posts/pages/products) ──
        $post_types = get_post_types( array(
            'public'              => true,
            'exclude_from_search' => false,
        ) );

        $paged      = 1;
        $post_index = 0; // running rank across all posts (query is date-DESC).
        do {
            $query = new WP_Query( array(
                'post_status'            => 'publish',
                'has_password'           => false,
                'ignore_sticky_posts'    => true,
                'no_found_rows'          => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'order'                  => 'DESC',
                'orderby'               => 'date',
                'post_type'             => $post_types,
                'posts_per_page'        => 500,
                'paged'                 => $paged,
                'fields'                => 'ids',
            ) );

            // (2.5.4 / perf #12) With cache additions suspended, every
            // get_permalink() below forced its own get_post() query — and,
            // on tag-containing permalink structures, per-post term queries
            // too. Prime the whole page (1–2 queries for 500 posts) with
            // additions temporarily resumed, resolve permalinks against the
            // warm cache, then evict the primed posts so peak memory stays
            // where the suspension put it.
            $easyopt_primed = false;
            if ( function_exists( '_prime_post_caches' ) && ! empty( $query->posts ) ) {
                wp_suspend_cache_addition( false );
                $easyopt_need_terms = false;
                $easyopt_plink      = (string) get_option( 'permalink_structure', '' );
                if ( false !== strpos( $easyopt_plink, '%category%' ) || false !== strpos( $easyopt_plink, '%tag%' ) ) {
                    $easyopt_need_terms = true;
                } elseif ( class_exists( 'WooCommerce' ) ) {
                    $easyopt_wc_pl = get_option( 'woocommerce_permalinks', array() );
                    if ( is_array( $easyopt_wc_pl ) && ! empty( $easyopt_wc_pl['product_base'] )
                        && false !== strpos( (string) $easyopt_wc_pl['product_base'], '%' ) ) {
                        $easyopt_need_terms = true;
                    }
                }
                _prime_post_caches( $query->posts, $easyopt_need_terms, false );
                $easyopt_primed = true;
            }

            foreach ( $query->posts as $post_id ) {
                $link = get_permalink( $post_id );
                if ( $link ) {
                    // Phase 8: recency banding. The query is newest-first, so a
                    // priority that rises with rank warms recently-published
                    // pages before the long tail — the URLs visitors are most
                    // likely to hit go hot first on large sites. Purely reorders
                    // WITHIN the post tier (always before taxonomy archives) and
                    // costs nothing extra (uses the index we already iterate).
                    $add( $link, self::post_recency_priority( $post_index ), (int) $post_id ); // (2.5.4 / perf #13)
                    $post_index++;
                }
            }
            // (2.5.4 / perf #12) Release the primed page before the next one.
            if ( $easyopt_primed ) {
                foreach ( $query->posts as $easyopt_evict_id ) {
                    wp_cache_delete( $easyopt_evict_id, 'posts' );
                }
                wp_suspend_cache_addition( true );
            }

            $paged++;
        } while ( $query->have_posts() );

        // ── 4. Public taxonomies (tier: categories/terms; tags excluded) ──
        $exclude_taxonomies = array(
            'post_tag',
            'product_tag',
            'post_format',
        );
        $exclude_taxonomies = apply_filters( 'easyopt_preload_exclude_taxonomies', $exclude_taxonomies );

        $taxonomies = get_taxonomies( array(
            'public'  => true,
            'rewrite' => true,
        ) );

        foreach ( $taxonomies as $taxonomy ) {
            if ( in_array( $taxonomy, $exclude_taxonomies, true ) ) {
                continue;
            }
            // (2.5.0 / L4) Page through terms in blocks of 1000. The old
            // unbounded get_terms() could exhaust memory on sites with tens
            // of thousands of terms (large WooCommerce catalogs, big
            // directories) — and if it errored, the ENTIRE taxonomy tier
            // was silently skipped. Paging bounds peak memory and makes a
            // single failing page non-fatal to the rest.
            $term_offset = 0;
            $term_batch  = 1000;
            do {
                $term_ids = get_terms( array(
                    'taxonomy'               => $taxonomy,
                    'hide_empty'             => true,
                    'hierarchical'           => false,
                    'update_term_meta_cache' => false,
                    'fields'                 => 'ids',
                    'number'                 => $term_batch,
                    'offset'                 => $term_offset,
                ) );
                if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
                    break;
                }
                foreach ( $term_ids as $term_id ) {
                    $link = get_term_link( (int) $term_id, $taxonomy );
                    if ( ! is_wp_error( $link ) && $link ) {
                        $add( $link, self::PRIO_TERM );
                    }
                }
                $got          = count( $term_ids );
                $term_offset += $got;
            } while ( $got === $term_batch );
        }

        // Resume object cache additions.
        wp_suspend_cache_addition( false );

        // Filter out non-cacheable URLs (Woo cart, checkout, etc.).
        if ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'get_url_exclusions' ) ) {
            $excludes = EasyOpt_Cache::get_url_exclusions();
            $urls = array_filter( $urls, function ( $u ) use ( $excludes ) {
                foreach ( $excludes as $needle ) {
                    if ( '' !== $needle && false !== stripos( $u, $needle ) ) {
                        return false;
                    }
                }
                return true;
            } );
        }

        return apply_filters( 'easyopt_cache_preload_urls', array_values( $urls ) );
    }
}