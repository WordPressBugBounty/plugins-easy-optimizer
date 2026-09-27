<?php
/**
 * Lightweight ring-buffer debug log.
 *
 * Records warnings and errors from Easy Optimizer subsystems into a
 * single file at wp-content/cache/easyopt-logs/debug.log. This lives
 * OUTSIDE the cache directory so clearing the cache never deletes the
 * log. The file is auto-truncated to ~250 KB so it never fills a disk.
 *
 * Log entries are structured as:
 *   [2026-05-28 14:30:22] [warn|error] [subsystem] message
 *
 * The log is readable via REST API and displayed in the React dashboard
 * topbar. Only manage_options users can read or clear it.
 *
 * @package EasyOptimizer
 * @since   2.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Debug_Log {

    /** Max file size before truncation (512 KB). */
    const MAX_SIZE = 524288;

    /** After truncation, keep the most recent ~256 KB. */
    const KEEP_SIZE = 262144;

    /** @var string Absolute path to the log file. */
    private static $file = '';

    /** @var bool Whether the log dir has been verified this request. */
    private static $dir_ok = false;

    /**
     * Boot — resolve the log file path once.
     */
    public static function init() {
        // IMPORTANT: the log must live OUTSIDE wp-content/cache/easyopt/,
        // because clear_all() recursively wipes that directory — which would
        // delete the very logs you're trying to read after a cache clear. We
        // use a sibling directory instead so cache clears never touch it.
        $dir        = trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt-logs';
        self::$file = $dir . '/debug.log';

        // (2.5.5) A settings save invalidates the cached level gate so a
        // long-running process (preload runner) honours a mid-run toggle.
        if ( function_exists( 'add_action' ) ) {
            add_action( 'easyopt_settings_saved', array( __CLASS__, 'reset_level_cache' ), 1, 0 );
        }

        // One-time migration: if a log exists at the old in-cache location and
        // the new one doesn't yet, move it so history isn't lost on upgrade.
        $legacy = trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt/debug.log';
        if ( file_exists( $legacy ) && ! file_exists( self::$file ) ) {
            if ( wp_mkdir_p( $dir ) ) {
                $h = $dir . '/.htaccess';
                if ( ! file_exists( $h ) ) {
                    @file_put_contents( $h, "Deny from all\n", LOCK_EX );
                }
                @rename( $legacy, self::$file );
            }
        }
    }

    /**
     * Ensure the log directory exists. Cached per-request.
     */
    private static function ensure_dir() {
        if ( self::$dir_ok ) {
            return true;
        }
        $dir = dirname( self::$file );
        if ( ! is_dir( $dir ) ) {
            if ( ! wp_mkdir_p( $dir ) ) {
                return false;
            }
            // Protect the log from public access.
            $htaccess = $dir . '/.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                @file_put_contents( $htaccess, "Deny from all\n", LOCK_EX );
            }
        }
        self::$dir_ok = true;
        return true;
    }

    /* ────────────────────────────────────────────────
     *  Public logging API
     * ──────────────────────────────────────────────── */

    /**
     * Log a warning.
     *
     * @param string $subsystem  Short tag: 'cache', 'htaccess', 'dropin', 'wp-config', 'preload', 'hosting', etc.
     * @param string $message    Human-readable message.
     */
    public static function warn( $subsystem, $message ) {
        self::write( 'warn', $subsystem, $message );
    }

    /**
     * Log an error.
     *
     * @param string $subsystem  Short tag.
     * @param string $message    Human-readable message.
     */
    public static function error( $subsystem, $message ) {
        self::write( 'error', $subsystem, $message );
    }

    /**
     * Log an informational note (low-noise diagnostics, e.g. the origin of a
     * full cache clear). Same lightweight append path as warn()/error().
     *
     * @param string $subsystem  Short tag.
     * @param string $message    Human-readable message.
     */
    public static function info( $subsystem, $message ) {
        self::write( 'info', $subsystem, $message );
    }

    /**
     * Whether a given level is enabled by the user's settings. Errors and
     * warnings are independently toggleable (both default ON). 'info' follows
     * the warnings toggle. Result is cached per-request so this adds no
     * repeated option reads on the rare logging path.
     * (2.5.4 / perf #50) Public so hot paths can skip building expensive log
     * context (e.g. debug_backtrace) when the level is off.
     */
    /**
     * (2.5.5) The gate values are cached but no longer for the life of the
     * process. The preload runner is a long-lived PHP process (heartbeat
     * loop, curl_multi batches, minutes at a time); with a once-per-process
     * memo it kept writing warn lines for its whole lifetime after the user
     * turned Log Warnings OFF — fresh-timestamped lines with the toggle
     * disabled. Now:
     *   • a settings save invalidates the memo (reset_level_cache() below,
     *     hooked to easyopt_settings_saved in init()), and
     *   • the runner re-resolves lazily via the per-key pre_option hooks,
     *     so the re-read costs one array lookup, not a DB query.
     */
    private static $gate_errors   = null;
    private static $gate_warnings = null;

    public static function level_enabled( $level ) {
        if ( null === self::$gate_errors ) {
            // Default ON if Config isn't available yet (early boot), so we
            // never silently drop a genuine early error.
            if ( class_exists( 'EasyOpt_Config' ) ) {
                self::$gate_errors   = (int) EasyOpt_Config::get( 'log_errors', 1 ) === 1;
                self::$gate_warnings = (int) EasyOpt_Config::get( 'log_warnings', 0 ) === 1;
            } else {
                self::$gate_errors = true; self::$gate_warnings = true;
            }
        }
        if ( 'error' === $level ) {
            return self::$gate_errors;
        }
        // 'warn' and 'info'
        return self::$gate_warnings;
    }

    /**
     * Drop the cached gate decision so the next log call re-reads the
     * settings. Public so the settings-save path (and long-running jobs
     * that cross a save) can invalidate it.
     *
     * @since 2.5.5
     */
    public static function reset_level_cache() {
        self::$gate_errors   = null;
        self::$gate_warnings = null;
    }

    /**
     * Core write method.
     */
    /** (2.5.4 / perf #45) Per-request line buffer + one-time flush guard. */
    private static $line_buffer     = array();
    private static $flush_registered = false;

    private static function write( $level, $subsystem, $message ) {
        if ( ! self::level_enabled( $level ) ) {
            return;
        }
        if ( '' === self::$file ) {
            self::init();
        }
        if ( ! self::ensure_dir() ) {
            return;
        }

        $subsystem = preg_replace( '/[^a-z0-9_\-]/i', '', $subsystem );
        $message   = str_replace( array( "\r\n", "\r", "\n" ), ' ', $message );

        $line = sprintf(
            "[%s] [%s] [%s] %s\n",
            gmdate( 'Y-m-d H:i:s' ),
            $level,
            $subsystem,
            $message
        );

        // (2.5.4 / perf #45) info/warn lines buffer per request and flush
        // once at shutdown — a purge fan-out that logged 30 URLs used to pay
        // 30 append+stat syscall pairs; now it's one append + one stat.
        // register_shutdown_function (not the WP hook) so lines survive
        // fatals. ERRORS still write through immediately: if the process
        // dies mid-request the error line is the one that must be on disk,
        // and read() must see it even before shutdown.
        if ( 'error' === $level ) {
            self::flush_buffer(); // keep chronological order ahead of the error
            @file_put_contents( self::$file, $line, FILE_APPEND | LOCK_EX );
            self::check_size();
            return;
        }

        self::$line_buffer[] = $line;
        if ( ! self::$flush_registered ) {
            self::$flush_registered = true;
            register_shutdown_function( array( __CLASS__, 'flush_buffer' ) );
        }
        // Safety valve: an extremely chatty request flushes early so the
        // buffer can never grow unbounded in memory.
        if ( count( self::$line_buffer ) >= 200 ) {
            self::flush_buffer();
        }
    }

    /**
     * (2.5.4 / perf #45) Write all buffered lines in one append. Public so
     * the shutdown callback can reach it; safe to call repeatedly.
     */
    public static function flush_buffer() {
        if ( empty( self::$line_buffer ) ) {
            return;
        }
        // (2.5.5) Re-check the gate at flush time. Only warn/info lines are
        // ever buffered (errors write through), so if Log Warnings was
        // turned OFF between buffering and shutdown, the whole buffer is
        // dropped rather than written minutes after the user disabled it.
        self::reset_level_cache();
        if ( ! self::level_enabled( 'warn' ) ) {
            self::$line_buffer = array();
            return;
        }
        $chunk             = implode( '', self::$line_buffer );
        self::$line_buffer = array();
        @file_put_contents( self::$file, $chunk, FILE_APPEND | LOCK_EX );
        self::check_size();
    }

    /** One post-write size check (was per line). */
    private static function check_size() {
        clearstatcache( true, self::$file );
        $size = @filesize( self::$file );
        if ( false !== $size && $size > self::MAX_SIZE ) {
            self::truncate();
        }
    }

    /**
     * Truncate to the most recent KEEP_SIZE bytes.
     */
    private static function truncate() {
        $content = @file_get_contents( self::$file );
        if ( false === $content ) {
            return;
        }
        // Keep the tail and find the first complete line.
        $tail = substr( $content, -self::KEEP_SIZE );
        $nl   = strpos( $tail, "\n" );
        if ( false !== $nl ) {
            $tail = substr( $tail, $nl + 1 );
        }
        @file_put_contents( self::$file, $tail, LOCK_EX );
    }

    /* ────────────────────────────────────────────────
     *  Read / clear (for REST API)
     * ──────────────────────────────────────────────── */

    /**
     * Read the last N lines from the log.
     *
     * @param int $max_lines Maximum lines to return (newest first).
     * @return array Array of associative arrays: [ 'time', 'level', 'subsystem', 'message' ].
     */
    public static function read( $max_lines = 200 ) {
        self::flush_buffer(); // (2.5.4 / perf #45) same-request lines visible
        if ( '' === self::$file ) {
            self::init();
        }
        if ( ! file_exists( self::$file ) ) {
            return array();
        }

        $raw = @file_get_contents( self::$file );
        if ( false === $raw || '' === trim( $raw ) ) {
            return array();
        }

        // (2.7.1) FILTER BEFORE SLICING. The file interleaves info lines (cache
        // clears etc.) with warn/error lines, but only warn/error are returned.
        // The old order — slice the last N raw lines, THEN drop info — let a
        // burst of info lines push every warning out of the window, so a log
        // with 6 real warnings behind 200+ later info lines read as empty while
        // counts() (which used a larger window) still saw them: badge said "6",
        // panel said "none". Parse the whole file to warn/error entries first,
        // then keep the newest N of THOSE.
        $lines  = explode( "\n", trim( $raw ) );
        $parsed = array();

        foreach ( $lines as $line ) {
            if ( preg_match( '/^\[([^\]]+)\] \[(warn|error)\] \[([^\]]+)\] (.+)$/', $line, $m ) ) {
                $parsed[] = array(
                    'time'      => $m[1],
                    'level'     => $m[2],
                    'subsystem' => $m[3],
                    'message'   => $m[4],
                );
            }
        }

        $parsed = array_reverse( $parsed ); // newest first

        return array_slice( $parsed, 0, max( 0, (int) $max_lines ) );
    }

    /**
     * Clear the entire log.
     *
     * @return bool
     */
    public static function clear() {
        if ( '' === self::$file ) {
            self::init();
        }
        if ( file_exists( self::$file ) ) {
            return false !== @file_put_contents( self::$file, '', LOCK_EX );
        }
        return true;
    }

    /**
     * Count of unread entries (warnings + errors).
     *
     * @return array [ 'warnings' => int, 'errors' => int, 'total' => int ]
     */
    public static function counts() {
        self::flush_buffer(); // (2.5.4 / perf #45)
        $entries = self::read( 500 );
        $w = 0;
        $e = 0;
        foreach ( $entries as $entry ) {
            if ( 'error' === $entry['level'] ) {
                $e++;
            } else {
                $w++;
            }
        }
        return array( 'warnings' => $w, 'errors' => $e, 'total' => $w + $e );
    }
}
