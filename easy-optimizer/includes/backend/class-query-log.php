<?php
/**
 * EasyOpt\Backend\Query_Log — Slow Query Analyzer (v1).
 *
 * Armed ONLY on token-authenticated profile requests (Backend::init()).
 *
 * Capture: SAVEQUERIES is defined for the profiled request only — wpdb
 * checks the constant at runtime inside query(), so defining it at plugin
 * load captures every query from that point on (queries during core
 * bootstrap before our plugin loads are missed; acceptable for v1 and
 * called out in the UI). At shutdown the buffer is filtered to queries at
 * or above the configurable threshold (default 50 ms).
 *
 * Privacy: queries are stored as normalized FINGERPRINTS — every string
 * literal and number is replaced with '?', IN-lists are collapsed, and the
 * table prefix is abstracted. Raw values (emails, tokens, anything) never
 * persist. The redacted normalized form is also what the UI displays.
 *
 * Findings: cheap local rule checks for well-known WordPress anti-patterns
 * (no EXPLAIN in v1 — that lands with the queue-driven analysis pass).
 *
 * @package EasyOptimizer
 * @since   2.4.0
 */

namespace EasyOpt\Backend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Query_Log {

    /** Hard cap on buffered queries per request ("truncated" beyond this). */
    const MAX_BUFFER = 2000;

    /** @var bool */
    private static $armed = false;

    public static function arm() {
        if ( self::$armed ) {
            return;
        }
        self::$armed = true;

        // wpdb reads the constant at query time, so this captures everything
        // executed after our plugin loads on this request.
        if ( ! defined( 'SAVEQUERIES' ) ) {
            define( 'SAVEQUERIES', true );
        }

        add_action( 'shutdown', array( __CLASS__, 'flush' ), 2 );
    }

    public static function flush() {
        if ( ! self::$armed ) {
            return;
        }
        self::$armed = false;

        global $wpdb;
        if ( empty( $wpdb->queries ) || ! is_array( $wpdb->queries ) ) {
            return;
        }

        $threshold_ms = max( 1, (int) \EasyOpt_Config::get( 'backend_query_threshold_ms', 50 ) );

        $context = 'frontend';
        if ( is_admin() ) {
            $context = 'admin';
        } elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            $context = 'rest';
        }

        // Aggregate within the request first (one row per fingerprint).
        $batch = array();
        $count = 0;
        foreach ( $wpdb->queries as $q ) {
            if ( $count++ >= self::MAX_BUFFER ) {
                break;
            }
            if ( ! is_array( $q ) || ! isset( $q[0], $q[1] ) ) {
                continue;
            }
            $ms = (float) $q[1] * 1000;
            if ( $ms < $threshold_ms ) {
                continue;
            }

            $normalized = self::normalize_sql( (string) $q[0], (string) $wpdb->prefix );
            $fp         = md5( $normalized );

            if ( ! isset( $batch[ $fp ] ) ) {
                $batch[ $fp ] = array(
                    'sample'   => substr( $normalized, 0, 1500 ),
                    'tables'   => self::tables_of( $normalized ),
                    'caller'   => isset( $q[2] ) ? substr( (string) $q[2], 0, 191 ) : '',
                    'hits'     => 0,
                    'total_ms' => 0.0,
                    'max_ms'   => 0.0,
                );
            }
            $batch[ $fp ]['hits']     += 1;
            $batch[ $fp ]['total_ms'] += $ms;
            if ( $ms > $batch[ $fp ]['max_ms'] ) {
                $batch[ $fp ]['max_ms'] = $ms;
            }
        }

        if ( empty( $batch ) ) {
            return;
        }

        $table = Backend::queries_table();
        $now   = current_time( 'mysql', true );

        foreach ( $batch as $fp => $row ) {
            $component = self::component_from_caller( $row['caller'] );
            $findings  = self::findings( $row['sample'] );

            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query( $wpdb->prepare(
                "INSERT INTO $table
                    (fingerprint, sample_sql, tables_used, caller, component, context,
                     hits, total_ms, max_ms, findings, first_seen, last_seen)
                 VALUES (%s, %s, %s, %s, %s, %s, %d, %f, %f, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    hits        = hits + VALUES(hits),
                    total_ms    = total_ms + VALUES(total_ms),
                    max_ms      = GREATEST(max_ms, VALUES(max_ms)),
                    caller      = VALUES(caller),
                    component   = VALUES(component),
                    findings    = VALUES(findings),
                    last_seen   = VALUES(last_seen)",
                $fp,
                $row['sample'],
                substr( $row['tables'], 0, 191 ),
                $row['caller'],
                substr( $component, 0, 64 ),
                $context,
                (int) $row['hits'],
                round( $row['total_ms'], 2 ),
                round( $row['max_ms'], 2 ),
                wp_json_encode( $findings ),
                $now,
                $now
            ) );
        }
    }

    /* ───────────────────────────────────────────────
     *  Normalisation (fingerprint + redaction in one pass)
     * ─────────────────────────────────────────────── */

    /**
     * Normalize SQL: literals → ?, numbers → ?, IN-lists collapsed,
     * whitespace squashed, table prefix abstracted to wp_. This IS the
     * privacy mechanism — the normalized form is the only thing stored.
     */
    public static function normalize_sql( $sql, $prefix ) {
        $sql = trim( $sql );

        // String literals (handles escaped quotes) → ?. One narrow exception:
        // transient-name patterns ('_transient_%' etc.) are preserved — they
        // are WordPress-internal option-name prefixes, never user data, and
        // the transient-bloat finding rule needs to see them post-redaction.
        $keep_literal = static function ( $m ) {
            $inner = substr( $m[0], 1, -1 );
            if ( preg_match( '/^_?(site_)?transient[a-z0-9_%\-]*$/i', $inner ) ) {
                return $m[0];
            }
            return '?';
        };
        $sql = preg_replace_callback( "/'(?:[^'\\\\]|\\\\.)*'/s", $keep_literal, $sql );
        $sql = preg_replace_callback( '/"(?:[^"\\\\]|\\\\.)*"/s', $keep_literal, $sql );

        // Numbers → ? (not inside identifiers).
        $sql = preg_replace( '/\b\d+(?:\.\d+)?\b/', '?', $sql );

        // Collapse IN (?, ?, ? …) → IN (?+)
        $sql = preg_replace( '/IN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN (?+)', $sql );

        // Whitespace.
        $sql = preg_replace( '/\s+/', ' ', $sql );

        // Abstract the prefix so fingerprints match across installs.
        if ( '' !== $prefix && 'wp_' !== $prefix ) {
            $sql = str_replace( $prefix, 'wp_', $sql );
        }

        return $sql;
    }

    /** Best-effort table extraction (FROM/JOIN/UPDATE/INTO targets). */
    private static function tables_of( $sql ) {
        $tables = array();
        if ( preg_match_all( '/\b(?:FROM|JOIN|UPDATE|INTO)\s+`?([a-z0-9_]+)`?/i', $sql, $m ) ) {
            $tables = array_values( array_unique( array_map( 'strtolower', $m[1] ) ) );
        }
        return implode( ',', array_slice( $tables, 0, 8 ) );
    }

    /**
     * Derive the originating component from wpdb's caller summary —
     * a comma-separated, oldest→newest chain like
     * "require(...), do_action('init'), My_Plugin_Class->boot, ...".
     * Walk from the NEWEST frame back and reflect the first resolvable
     * class/function to its file.
     */
    private static function component_from_caller( $caller ) {
        if ( '' === $caller ) {
            return '';
        }
        $frames = array_reverse( array_map( 'trim', explode( ',', $caller ) ) );
        foreach ( $frames as $frame ) {
            // Strip call arguments/suffixes.
            $frame = preg_replace( '/\(.*$/', '', $frame );
            $file  = '';
            if ( false !== strpos( $frame, '::' ) ) {
                list( $cls ) = explode( '::', $frame, 2 );
                if ( class_exists( $cls, false ) ) {
                    try {
                        $file = (string) ( new \ReflectionClass( $cls ) )->getFileName();
                    } catch ( \Throwable $e ) { // phpcs:ignore
                        $file = '';
                    }
                }
            } elseif ( false !== strpos( $frame, '->' ) ) {
                list( $cls ) = explode( '->', $frame, 2 );
                if ( class_exists( $cls, false ) ) {
                    try {
                        $file = (string) ( new \ReflectionClass( $cls ) )->getFileName();
                    } catch ( \Throwable $e ) { // phpcs:ignore
                        $file = '';
                    }
                }
            } elseif ( function_exists( $frame ) ) {
                $file = Backend::file_of_callable( $frame );
            }

            $component = Backend::component_from_file( $file );
            if ( '' !== $component && 'core' !== $component ) {
                return $component;
            }
        }
        return 'core';
    }

    /* ───────────────────────────────────────────────
     *  Rule-based findings (local, no EXPLAIN in v1)
     * ─────────────────────────────────────────────── */

    private static function findings( $sql ) {
        $f = array();
        $u = strtoupper( $sql );

        if ( false !== strpos( $u, 'SQL_CALC_FOUND_ROWS' ) ) {
            $f[] = 'Uses SQL_CALC_FOUND_ROWS — pass no_found_rows=true to the calling WP_Query when pagination totals are not needed.';
        }
        if ( false !== strpos( $u, 'ORDER BY RAND' ) ) {
            $f[] = 'ORDER BY RAND() forces a full sort of every matching row — replace with a random-offset pick or a cached random set.';
        }
        if ( preg_match( '/LIKE\s+\'?_?(site_)?transient/i', $sql ) ) {
            $f[] = 'Scans transient rows by LIKE — usually expired-transient bloat. Run Database → Cleanup → Expired Transients.';
        }
        if ( false !== strpos( $sql, 'wp_postmeta' ) && preg_match( '/meta_key\s*=\s*\?\s*AND\s*meta_value/i', $sql ) ) {
            $f[] = 'Filters wp_postmeta by meta_key + meta_value — a classic unindexed pattern. A composite index on meta_key(191), meta_value(100) can help (adds write overhead; test first).';
        }
        if ( false !== strpos( $sql, 'wp_options' ) && false !== stripos( $sql, 'autoload' ) ) {
            $f[] = 'Touches the autoloaded-options set — check Database → Autoload Health for oversized autoloaded options.';
        }
        if ( preg_match( '/^SELECT /i', $sql ) && false === strpos( $u, ' LIMIT ' )
             && ( false !== strpos( $sql, 'wp_posts' ) || false !== strpos( $sql, 'wp_postmeta' ) ) ) {
            $f[] = 'Unbounded SELECT on a content table (no LIMIT) — fine for small sites, risky as content grows.';
        }
        return $f;
    }
}
