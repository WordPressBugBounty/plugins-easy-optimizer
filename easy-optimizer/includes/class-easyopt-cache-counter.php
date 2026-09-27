<?php
/**
 * Cache Page Counter — O(1) cache stats without filesystem scanning.
 *
 * PROBLEM (2.x):
 * EasyOpt_Cache::get_stats() runs a RecursiveIteratorIterator over the
 * entire cache directory (potentially thousands of stat() calls) every
 * 60 seconds when the transient expires. On sites with 5000+ cached
 * pages, this can take 200–500ms and spike CPU.
 *
 * not scan at all.
 *
 * SOLUTION:
 * Maintain an atomic counter in wp_options (autoloaded, so it's always
 * in memory). Increment when a page is cached, reset to 0 on full clear.
 * The REST dashboard reads this counter in O(1) — no filesystem access.
 *
 * The counter is self-healing: if it ever gets out of sync, the next
 * get_stats() transient expiry will correct it via a background recount.
 *
 * HOOKS USED:
 *   easyopt_cache_cleared_all  → reset counter to 0
 *   easyopt_page_cached        → increment counter (fired by cache engine)
 *   easyopt_cache_cleared_url  → decrement counter
 *
 * If easyopt_page_cached doesn't exist yet in the cache class, the counter
 * still works — it gets corrected on the next get_stats() transient refresh.
 *
 * @package EasyOptimizer
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Cache_Counter {

    const OPTION = 'easyopt_cache_page_count';

    public static function init() {
        // Reset to 0 when all cache is cleared.
        add_action( 'easyopt_cache_cleared_all', array( __CLASS__, 'reset' ) );

        // Increment when a new page is cached.
        add_action( 'easyopt_page_cached', array( __CLASS__, 'increment' ) );

        // Decrement when a single URL is purged.
        // (2.6.0) Routed through on_url_cleared() rather than straight to
        // decrement(). EasyOpt_Cache::clear_url() now applies the exact
        // decrement itself (it knows how many directories it actually
        // emptied); this listener remains so THIRD-PARTY code that fires
        // easyopt_cache_cleared_url directly still moves the counter.
        // Passing $url straight to decrement() would also have been wrong
        // now that decrement() takes a count.
        add_action( 'easyopt_cache_cleared_url', array( __CLASS__, 'on_url_cleared' ), 10, 1 );

        // After a full scan completes (get_stats transient refresh),
        // sync the counter to the accurate value.
        add_action( 'easyopt_cache_stats_computed', array( __CLASS__, 'sync_from_stats' ), 10, 1 );
    }

    /**
     * Get current count — O(1), single option read.
     *
     * Returns -1 when the counter row doesn't exist yet (fresh install,
     * pre-first-write) so callers can fall back to the authoritative
     * get_stats() scan instead of showing a misleading "0 cached pages".
     * (2.3.3 — the old max(0, …) clamp made the -1 contract unreachable
     * and the dashboard's fallback branch dead code.)
     */
    public static function get() {
        $v = get_option( self::OPTION, false );
        if ( false === $v ) {
            return -1;
        }
        return max( 0, (int) $v );
    }

    /**
     * Reset counter to 0 (cache cleared).
     */
    public static function reset() {
        update_option( self::OPTION, 0, false );
    }

    /**
     * Atomic increment by 1 (new page cached).
     * Uses direct SQL to avoid read-then-write race under concurrency.
     */
    public static function increment() {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE $wpdb->options SET option_value = option_value + 1 WHERE option_name = %s",
            self::OPTION
        ) );
        // Self-seed (2.3.3): when the row doesn't exist yet the UPDATE
        // matches 0 rows and the count silently never moved. Seed at 1.
        if ( 0 === (int) $wpdb->rows_affected ) {
            add_option( self::OPTION, 1, '', false );
        }
        // Bust the object cache so the next get_option() reads the new value.
        wp_cache_delete( self::OPTION, 'options' );
    }

    /**
     * Atomic decrement (single URL purged). Floor at 0.
     *
     * (2.6.0) Takes an optional count so a caller that emptied several cache
     * directories in one pass — a paginated archive purge removes /page/2/,
     * /page/3/, … and each is its own unit in get_stats() — can subtract the
     * right amount in ONE UPDATE instead of N. Defaults to 1, so every
     * existing call site and any third-party caller behaves exactly as before.
     *
     * @param int $by How many cached URLs were removed.
     */
    public static function decrement( $by = 1 ) {
        $by = max( 1, (int) $by );
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE $wpdb->options SET option_value = GREATEST(0, option_value - %d) WHERE option_name = %s",
            $by,
            self::OPTION
        ) );
        wp_cache_delete( self::OPTION, 'options' );
    }

    /**
     * (2.6.0) easyopt_cache_cleared_url listener.
     *
     * EasyOpt_Cache::clear_url() knows exactly how many cache directories it
     * emptied and applies that decrement itself, so this is a deliberate no-op
     * for our own purges. It stays bound to the action purely so third-party
     * integrations that call do_action( 'easyopt_cache_cleared_url', $url )
     * keep the behaviour they had before 2.6.0.
     *
     * Why the change: the old binding subtracted 1 per FIRED ACTION, whether
     * or not any file existed. A single post save fans the purge out across
     * the permalink, the home page and every archive, so an edit could drive
     * the counter down by 5-15 while deleting nothing — the dominant cause of
     * "Pages cached" sitting at 0 on active sites.
     *
     * @param string $url Purged URL (unused; signature matches the action).
     */
    public static function on_url_cleared( $url = '' ) {
        if ( class_exists( 'EasyOpt_Cache' )
             && method_exists( 'EasyOpt_Cache', 'counter_decrement_handled' )
             && EasyOpt_Cache::counter_decrement_handled() ) {
            return; // clear_url() already applied a precise decrement.
        }
        self::decrement();
    }

    /**
     * Sync counter from a fresh get_stats() scan result.
     * Called after the transient is recomputed so the counter
     * self-heals if it drifts.
     *
     * @param array $stats The stats array from get_stats().
     */
    public static function sync_from_stats( $stats ) {
        if ( isset( $stats['pages'] ) ) {
            // autoload=false — consistent with reset() (2.3.3). The counter
            // is read on the rare-path dashboard/clear flows, not per request.
            update_option( self::OPTION, (int) $stats['pages'], false );
        }
    }
}
