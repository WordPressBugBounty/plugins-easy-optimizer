<?php
/**
 * EasyOpt\Backend\Profiler — Slow Callback Analyzer (v2, per-callback timing).
 *
 * Armed ONLY on token-authenticated profile requests (see Backend::init()),
 * so it never runs for real visitors.
 *
 * How it measures (v2, "per-callback exclusive time"): when armed, every hook
 * callback that actually fires is wrapped once with a tiny timer. We record
 * each callback's EXCLUSIVE (self) time — its own wall time minus the time
 * spent inside any nested hooks it triggered — and attribute it to the exact
 * plugin/theme/core file that defined the callback (via reflection, cached).
 *
 * This fixes the v1 "gap timing" inaccuracy where time between hooks was
 * blamed on whichever component happened to sit nearby (often the theme).
 * Now each plugin's time is its own code, summed across everything it did, so
 * the dashboard can show one honest row per plugin/theme. Time that isn't
 * inside any callback (template rendering, core get_option()/esc_html(), the
 * database layer, etc.) is reported as a single "(page render / core)" row
 * rather than being mislabelled as a plugin or the theme.
 *
 * Safety: wrapping keeps each callback's original array key, so remove_filter(),
 * has_filter() and priorities all keep working. Wrappers live only for the
 * single throwaway profile request and are never persisted.
 *
 * @package EasyOptimizer
 * @since   2.4.0
 */

namespace EasyOpt\Backend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Profiler {

    /** Keep only the N most expensive rows per profiled URL. */
    const MAX_ROWS_STORED = 200;

    /** @var bool */
    private static $armed = false;

    /** @var float ns timestamp when profiling started. */
    private static $t0 = 0;

    /** @var array<string,bool> Hooks already wrapped (wrap each once). */
    private static $wrapped = array();

    /** @var array<string,string> callable-key → component label (cache). */
    private static $cache = array();

    /** @var float[] Exclusive-time stack: each frame accumulates child ns. */
    private static $stack = array();

    /** @var array<string,array<string,array{calls:int,total:float,max:float,mem:int}>> component → hook → agg */
    private static $agg = array();

    /** @var array<string,float> Lifecycle phase → elapsed ms since t0. */
    private static $phases = array();

    public static function arm() {
        if ( self::$armed ) {
            return;
        }
        self::$armed = true;
        self::$t0    = hrtime( true );

        // 'all' fires before each hook dispatch — we use it to wrap that hook's
        // callbacks the first time it runs.
        add_action( 'all', array( __CLASS__, 'on_all' ) );

        // Lifecycle phase markers (very late priority = phase fully done).
        foreach ( array( 'plugins_loaded', 'init', 'wp_loaded', 'template_redirect' ) as $phase ) {
            add_action( $phase, static function () use ( $phase ) {
                Profiler::mark_phase( $phase );
            }, PHP_INT_MAX );
        }

        // Persist after the page is rendered.
        add_action( 'shutdown', array( __CLASS__, 'flush' ), 1 );
    }

    /**
     * 'all' handler — wrap the about-to-run hook's callbacks once. Kept light:
     * after a hook is wrapped this is just an isset() check and return.
     */
    public static function on_all() {
        $hook = current_filter();
        if ( 'all' === $hook || isset( self::$wrapped[ $hook ] ) ) {
            return;
        }
        self::$wrapped[ $hook ] = true;
        self::wrap_hook( $hook );
    }

    /**
     * Replace each callback on $hook with a timing wrapper, preserving the
     * original array key so remove_filter()/has_filter() keep matching.
     */
    private static function wrap_hook( $hook ) {
        global $wp_filter;
        if ( empty( $wp_filter[ $hook ] ) || ! ( $wp_filter[ $hook ] instanceof \WP_Hook ) ) {
            return;
        }
        $wp_hook = $wp_filter[ $hook ];

        foreach ( $wp_hook->callbacks as $prio => $cbs ) {
            if ( ! is_array( $cbs ) ) {
                continue;
            }
            foreach ( $cbs as $id => $entry ) {
                if ( ! is_array( $entry ) || empty( $entry['function'] ) || ! empty( $entry['_eo'] ) ) {
                    continue;
                }
                $orig = $entry['function'];

                // Never wrap our own profiler callbacks.
                if ( is_array( $orig ) && isset( $orig[0] ) && is_string( $orig[0] )
                    && false !== strpos( ltrim( $orig[0], '\\' ), 'EasyOpt\\Backend\\Profiler' ) ) {
                    continue;
                }

                $component = self::component_of( $orig );

                $wrapped = static function ( ...$args ) use ( $orig, $component, $hook ) {
                    $start = hrtime( true );
                    $mem0  = memory_get_usage();
                    self::$stack[] = 0.0; // this frame's accumulated child time (ns)
                    try {
                        $ret = $orig( ...$args );
                    } finally {
                        $end      = hrtime( true );
                        $children = (float) array_pop( self::$stack );
                        $incl     = $end - $start;          // inclusive ns
                        $self     = $incl - $children;       // exclusive ns
                        if ( $self < 0 ) {
                            $self = 0;
                        }
                        $depth = count( self::$stack );
                        if ( $depth > 0 ) {
                            self::$stack[ $depth - 1 ] += $incl; // bubble inclusive to parent
                        }
                        self::record( $component, $hook, $self / 1e6, max( 0, memory_get_usage() - $mem0 ) );
                    }
                    return $ret;
                };

                // Keep the SAME key — only swap the stored callable.
                $wp_hook->callbacks[ $prio ][ $id ]['function'] = $wrapped;
                $wp_hook->callbacks[ $prio ][ $id ]['_eo']      = true;
            }
        }
    }

    public static function record( $component, $hook, $ms, $mem ) {
        if ( ! isset( self::$agg[ $component ] ) ) {
            self::$agg[ $component ] = array();
        }
        if ( ! isset( self::$agg[ $component ][ $hook ] ) ) {
            self::$agg[ $component ][ $hook ] = array( 'calls' => 0, 'total' => 0.0, 'max' => 0.0, 'mem' => 0 );
        }
        $a           = &self::$agg[ $component ][ $hook ];
        $a['calls'] += 1;
        $a['total'] += $ms;
        if ( $ms > $a['max'] ) {
            $a['max'] = $ms;
        }
        $a['mem'] += (int) $mem;
        unset( $a );
    }

    public static function mark_phase( $phase ) {
        self::$phases[ $phase ] = ( hrtime( true ) - self::$t0 ) / 1e6;
    }

    /** Resolve a callable to its component label (plugin:slug / theme:slug / core), cached. */
    private static function component_of( $cb ) {
        $key = self::callable_key( $cb );
        if ( isset( self::$cache[ $key ] ) ) {
            return self::$cache[ $key ];
        }
        $c = Backend::component_from_file( Backend::file_of_callable( $cb ) );
        if ( '' === $c ) {
            $c = 'core';
        }
        self::$cache[ $key ] = $c;
        return $c;
    }

    private static function callable_key( $cb ) {
        if ( is_string( $cb ) ) {
            return 'f:' . $cb;
        }
        if ( $cb instanceof \Closure ) {
            return 'c:' . spl_object_id( $cb );
        }
        if ( is_array( $cb ) && isset( $cb[0], $cb[1] ) ) {
            return 'm:' . ( is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0] ) . '::' . (string) $cb[1];
        }
        if ( is_object( $cb ) ) {
            return 'o:' . get_class( $cb );
        }
        return 'x';
    }

    /**
     * Shutdown: attribute the un-tracked remainder, then persist as the
     * AVERAGE of this test session's recorded passes (replacing any older
     * data for the URL so re-testing never accumulates).
     */
    public static function flush() {
        if ( ! self::$armed ) {
            return;
        }
        self::$armed = false;
        self::mark_phase( 'shutdown' );

        $total_ms = ( hrtime( true ) - self::$t0 ) / 1e6;

        // Flatten component→hook aggregates into rows; sum the self time.
        $rows     = array();
        $sum_self = 0.0;
        foreach ( self::$agg as $component => $hooks ) {
            foreach ( $hooks as $hook => $a ) {
                $sum_self += $a['total'];
                $rows[]    = array(
                    'component' => $component,
                    'hook'      => $hook,
                    'calls'     => $a['calls'],
                    'total'     => $a['total'],
                    'max'       => $a['max'],
                    'mem'       => $a['mem'],
                );
            }
        }

        // Time not inside any tracked callback = template render + core machinery.
        $remainder = $total_ms - $sum_self;
        if ( $remainder > 0.5 ) {
            $rows[] = array(
                'component' => '(page render / core)',
                'hook'      => '_render',
                'calls'     => 1,
                'total'     => $remainder,
                'max'       => $remainder,
                'mem'       => 0,
            );
        }

        if ( empty( $rows ) ) {
            return;
        }

        usort( $rows, static function ( $x, $y ) {
            return $y['total'] <=> $x['total'];
        } );
        $rows = array_slice( $rows, 0, self::MAX_ROWS_STORED );

        $url      = home_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' );
        $url      = remove_query_arg( array( 'easyopt_profile', 'eoptoken', 'eopass' ), $url );
        $url_hash = md5( Backend::normalize_url( $url ) );

        $context = 'frontend';
        if ( is_admin() ) {
            $context = 'admin';
        } elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            $context = 'rest';
        }

        global $wpdb;
        $table = Backend::callbacks_table();
        $now   = current_time( 'mysql', true );
        $pass  = isset( $_GET['eopass'] ) ? (int) $_GET['eopass'] : 2; // phpcs:ignore WordPress.Security.NonceVerification

        // Fresh session: the first recorded pass clears this URL's previous
        // rows, so re-testing shows the new measurement (an average of this
        // session's passes) instead of piling on top of stale data.
        if ( $pass <= 2 ) {
            // phpcs:ignore WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
            $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE url_hash = %s", $url_hash ) );
        }

        // Average across the two recorded passes (pass 2 inserts, pass 3 means).
        $upsert_tail = 'ON DUPLICATE KEY UPDATE
            captured_at = VALUES(captured_at),
            calls       = ROUND((calls + VALUES(calls)) / 2),
            total_ms    = ROUND((total_ms + VALUES(total_ms)) / 2, 2),
            max_ms      = GREATEST(max_ms, VALUES(max_ms)),
            mem_kb      = ROUND((mem_kb + VALUES(mem_kb)) / 2)';

        foreach ( $rows as $r ) {
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO $table
                    (captured_at, context, url_hash, url, hook, component, calls, total_ms, max_ms, mem_kb)
                 VALUES (%s, %s, %s, %s, %s, %s, %d, %f, %f, %d)
                 $upsert_tail",
                $now,
                $context,
                $url_hash,
                $url,
                substr( (string) $r['hook'], 0, 191 ),
                substr( (string) $r['component'], 0, 64 ),
                (int) $r['calls'],
                round( $r['total'], 2 ),
                round( $r['max'], 2 ),
                (int) round( $r['mem'] / 1024 )
            ) );
        }

        // Lifecycle phase rows (durations between boundaries).
        $prev_label = 'load';
        $prev_ms    = 0.0;
        foreach ( self::$phases as $phase => $cum_ms ) {
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO $table
                    (captured_at, context, url_hash, url, hook, component, calls, total_ms, max_ms, mem_kb)
                 VALUES (%s, %s, %s, %s, %s, 'lifecycle', 1, %f, %f, 0)
                 $upsert_tail",
                $now,
                $context,
                $url_hash,
                $url,
                '_phase:' . $prev_label . '→' . $phase,
                round( $cum_ms - $prev_ms, 2 ),
                round( $cum_ms - $prev_ms, 2 )
            ) );
            $prev_label = $phase;
            $prev_ms    = $cum_ms;
        }
    }
}
