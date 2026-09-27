<?php
/**
 * Database Snapshot manager — pre-delete row capture + restore.
 *
 * Architecture (1.6.2):
 *   • Before any destructive cleanup, Database::run_task hands the rows it's
 *     about to delete to write(). We serialise them as gzipped JSON in
 *     `wp-content/uploads/easyopt-snapshots/snap-{timestamp}-{task}.json.gz`.
 *   • Each line of the JSON is one row (NDJSON). This lets restore() stream
 *     the file back without loading the whole thing into memory — important
 *     for big snapshots (think 50k spam comments).
 *   • Restore is chunked the same way Database::run_task is: read N lines,
 *     INSERT IGNORE them, return continuation info.
 *   • Retention: prune() removes snapshots older than the user-set window
 *     (default 14 days). Wired into the daily WP cron.
 *
 * What we DON'T snapshot:
 *   • `optimize_tables` — nothing is deleted, only storage layout changes.
 *   • Snapshots themselves — meta-circular nonsense.
 *
 * Caveats we accept:
 *   • Snapshot+delete isn't atomic. If the snapshot write succeeds but the
 *     delete is interrupted, we end up with an orphan snapshot referencing
 *     rows that still exist. Restore handles this via INSERT IGNORE on the
 *     primary key, so it's safe to replay over a partial-delete state.
 *   • Snapshot size grows with the data being deleted. The UI shows the
 *     projected size before each run and lets the user disable snapshotting
 *     for individual tasks (or set a global max-size cap).
 *
 * Filesystem layout:
 *   uploads/easyopt-snapshots/snap-1721234567-post_revisions.json.gz
 *   uploads/easyopt-snapshots/snap-1721234567-post_revisions.meta.json
 *
 * The .meta.json is tiny — it's what the Snapshots tab reads to render the
 * list without opening every gzip. Keeping the metadata separate also lets
 * the user delete the big file but keep the audit trail if disk pressure
 * matters more than restorability.
 *
 * @package EasyOptimizer
 * @since   1.6.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_DB_Snapshot {

    const SUBDIR              = 'easyopt-snapshots';
    const DEFAULT_RETENTION   = 14;          // days
    const PRUNE_HOOK          = 'easyopt_db_snapshot_prune';

    /* ─────────────────────────────────────────────
     *  Lifecycle
     * ───────────────────────────────────────────── */

    public static function init() {
        add_action( self::PRUNE_HOOK, array( __CLASS__, 'prune' ) );
        // Daily prune. WP-Cron is fine here — losing a tick just delays
        // cleanup by a day, no functional impact.
        // (2.5.4 / perf #5) Schedule self-heal only on admin/cron requests —
        // wp_next_scheduled() walks the whole cron array per call.
        if ( is_admin() || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
            if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
                wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
            }
        }
    }

    /* ─────────────────────────────────────────────
     *  Filesystem
     * ───────────────────────────────────────────── */

    /**
     * Resolve (and create on demand) the snapshots directory.
     * Writes a deny-all .htaccess and an empty index.html as belt-and-
     * suspenders against direct enumeration. On Nginx the .htaccess is
     * a no-op; nothing else here exposes the path, so we don't bother
     * generating an nginx.conf fragment.
     */
    public static function dir() {
        $uploads = wp_get_upload_dir();
        if ( ! empty( $uploads['error'] ) ) {
            return '';
        }
        $dir = trailingslashit( $uploads['basedir'] ) . self::SUBDIR . '/';
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
            // Best-effort hardening. Failures aren't fatal — snapshots
            // contain old WP rows the visitor can already see via normal
            // site URLs in most cases, but better-safe-than-sorry.
            @file_put_contents( $dir . '.htaccess',  "Require all denied\n" );
            @file_put_contents( $dir . 'index.html', '' );
        }
        return $dir;
    }

    /**
     * Are we able + allowed to take snapshots? Returns false when the user
     * has disabled the feature globally, when uploads isn't writable, or
     * when the directory can't be created.
     */
    public static function enabled() {
        if ( ! (int) EasyOpt_Config::get( 'db_snapshot_enabled', 1 ) ) {
            return false;
        }
        $dir = self::dir();
        if ( '' === $dir ) { return false; }
        return is_writable( $dir );
    }

    /* ─────────────────────────────────────────────
     *  Write — called from inside Database::run_task
     * ───────────────────────────────────────────── */

    /**
     * Append a batch of rows to an in-progress snapshot. The same task can
     * be called many times during a chunked run; we keep the same file open
     * (across requests) by deriving the filename from a `$session_id`.
     *
     * @param string $task         Task key (e.g. 'post_revisions').
     * @param string $session_id   Stable ID for the in-progress run (timestamp at run start works).
     * @param string $table_name   Source MySQL table (wp_posts, wp_comments, wp_options...).
     * @param array  $rows         Array of associative rows (column => value).
     * @return string|false        Filesystem path of the snapshot, or false on failure.
     */
    public static function append( $task, $session_id, $table_name, array $rows ) {
        if ( empty( $rows ) ) {
            return false;
        }
        if ( ! self::enabled() ) {
            return false;
        }
        $dir = self::dir();
        if ( '' === $dir ) { return false; }

        $basename = self::basename( $session_id, $task );
        $data_path = $dir . $basename . '.json.gz';
        $meta_path = $dir . $basename . '.meta.json';

        // Append-mode gzip stream. zlib lets concurrent appends produce a
        // valid concatenated gzip (gunzip handles back-to-back gzip members
        // transparently). This is why we use gzopen/gzwrite/gzclose instead
        // of buffering rows in PHP memory across requests.
        $fp = @gzopen( $data_path, 'ab' );
        if ( ! $fp ) {
            return false;
        }
        $row_count = 0;
        foreach ( $rows as $row ) {
            $line = wp_json_encode( array( 't' => $table_name, 'r' => $row ) );
            if ( false === $line ) {
                continue; // unencodable row (binary blob) — skip rather than abort
            }
            gzwrite( $fp, $line . "\n" );
            $row_count++;
        }
        gzclose( $fp );

        // Update sidecar metadata. Read-modify-write is fine; the chunked
        // runner is single-process per task.
        $meta = file_exists( $meta_path )
            ? (array) json_decode( (string) @file_get_contents( $meta_path ), true )
            : array(
                'task'       => $task,
                'session_id' => $session_id,
                'started_at' => time(),
                'tables'     => array(),
                'row_count'  => 0,
            );
        $meta['row_count']   = (int) ( $meta['row_count'] ?? 0 ) + $row_count;
        $meta['updated_at']  = time();
        $meta['size_bytes']  = (int) @filesize( $data_path );
        $meta['tables']      = array_values( array_unique( array_merge(
            (array) ( $meta['tables'] ?? array() ),
            array( $table_name )
        ) ) );

        @file_put_contents( $meta_path, wp_json_encode( $meta ), LOCK_EX );

        return $data_path;
    }

    /* ─────────────────────────────────────────────
     *  Read — list, restore, delete
     * ───────────────────────────────────────────── */

    /**
     * Enumerate snapshots, newest first. Reads only the .meta.json sidecars
     * so it stays fast even when there are gigabytes of compressed data.
     */
    public static function list_all() {
        $dir = self::dir();
        if ( '' === $dir ) { return array(); }
        $out = array();
        foreach ( (array) glob( $dir . 'snap-*.meta.json' ) as $meta_path ) {
            $meta = (array) json_decode( (string) @file_get_contents( $meta_path ), true );
            if ( empty( $meta ) ) { continue; }
            $basename = preg_replace( '/\.meta\.json$/', '', basename( $meta_path ) );
            $data_path = $dir . $basename . '.json.gz';
            $meta['basename']   = $basename;
            $meta['data_path']  = $data_path;
            $meta['data_exists']= file_exists( $data_path );
            $meta['size_bytes'] = $meta['data_exists'] ? (int) @filesize( $data_path ) : 0;
            $out[] = $meta;
        }
        // Newest first.
        usort( $out, function ( $a, $b ) {
            return ( $b['started_at'] ?? 0 ) <=> ( $a['started_at'] ?? 0 );
        } );
        return $out;
    }

    /**
     * Restore a snapshot in chunks. Each call reads up to $chunk_lines lines
     * starting at $offset, INSERT IGNOREs them, and returns continuation info.
     *
     * @param string $basename     The "snap-..." prefix (without .json.gz).
     * @param int    $offset       Byte offset to resume from.
     * @param int    $chunk_lines  How many lines to process this call.
     * @return array {
     *     @type bool   $ok
     *     @type int    $inserted        Rows actually inserted (excluding IGNOREd duplicates).
     *     @type int    $next_offset     Byte position to pass back on next call.
     *     @type bool   $done            True when EOF reached.
     *     @type string $error           Error message, if any.
     * }
     */
    public static function restore_chunk( $basename, $offset = 0, $chunk_lines = 500 ) {
        global $wpdb;
        $dir = self::dir();
        if ( '' === $dir ) {
            return array( 'ok' => false, 'error' => 'Snapshots directory not available' );
        }
        $basename = preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $basename );
        if ( '' === $basename ) {
            return array( 'ok' => false, 'error' => 'Invalid snapshot id' );
        }
        $path = $dir . $basename . '.json.gz';
        if ( ! file_exists( $path ) ) {
            return array( 'ok' => false, 'error' => 'Snapshot data file missing' );
        }

        // We pass offset in BYTES so the caller can resume across requests
        // without the server having to seek through compressed data line by
        // line. gzseek() is supported on read-mode gzip handles.
        $fp = @gzopen( $path, 'rb' );
        if ( ! $fp ) {
            return array( 'ok' => false, 'error' => 'Could not open snapshot' );
        }
        if ( $offset > 0 ) {
            // gzseek on compressed data is O(offset) — it decompresses to
            // skip ahead. Acceptable for our scale; large snapshots are
            // a few hundred MB at most.
            gzseek( $fp, $offset );
        }

        $inserted = 0;
        $processed = 0;
        while ( $processed < $chunk_lines && ! gzeof( $fp ) ) {
            $line = gzgets( $fp );
            if ( false === $line || '' === trim( $line ) ) { continue; }
            $entry = json_decode( $line, true );
            if ( ! is_array( $entry ) || empty( $entry['t'] ) || empty( $entry['r'] ) ) {
                continue;
            }
            $table = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $entry['t'] );
            $row   = (array) $entry['r'];
            if ( '' === $table || empty( $row ) ) { continue; }

            // INSERT IGNORE so re-running a restore over partially-present
            // data doesn't error on PK conflicts. We're explicit about
            // columns to defend against schema drift between snapshot and
            // current DB (extra columns get default values).
            $cols = array_keys( $row );
            $placeholders = implode( ',', array_fill( 0, count( $cols ), '%s' ) );
            $sql = 'INSERT IGNORE INTO `' . $table . '` (`' . implode( '`,`', $cols ) . '`) VALUES (' . $placeholders . ')';
            $suppress = $wpdb->suppress_errors( true );
            $r = $wpdb->query( $wpdb->prepare( $sql, array_values( $row ) ) );
            $wpdb->suppress_errors( $suppress );
            if ( $r ) { $inserted++; }
            $processed++;
        }

        $next_offset = gztell( $fp );
        $done        = gzeof( $fp );
        gzclose( $fp );

        return array(
            'ok'          => true,
            'inserted'    => $inserted,
            'processed'   => $processed,
            'next_offset' => $next_offset,
            'done'        => $done,
        );
    }

    /**
     * Delete a single snapshot (data + metadata).
     */
    public static function delete( $basename ) {
        $dir = self::dir();
        if ( '' === $dir ) { return false; }
        $basename = preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $basename );
        if ( '' === $basename ) { return false; }
        $a = $dir . $basename . '.json.gz';
        $b = $dir . $basename . '.meta.json';
        $ok = true;
        if ( file_exists( $a ) ) { $ok = @unlink( $a ) && $ok; }
        if ( file_exists( $b ) ) { $ok = @unlink( $b ) && $ok; }
        return $ok;
    }

    /**
     * Daily retention sweep. Deletes any snapshot whose `started_at` is
     * older than the user-configured retention window. Anything without a
     * valid meta.json is left alone (audit-trail intentionally preserved).
     */
    public static function prune() {
        $days = (int) EasyOpt_Config::get( 'db_snapshot_retention_days', self::DEFAULT_RETENTION );
        if ( $days <= 0 ) { return 0; } // 0 = keep forever
        $cutoff = time() - ( $days * DAY_IN_SECONDS );
        $deleted = 0;
        foreach ( self::list_all() as $snap ) {
            $when = (int) ( $snap['started_at'] ?? 0 );
            if ( $when > 0 && $when < $cutoff ) {
                if ( self::delete( $snap['basename'] ) ) {
                    $deleted++;
                }
            }
        }
        return $deleted;
    }

    /* ─────────────────────────────────────────────
     *  Internals
     * ───────────────────────────────────────────── */

    private static function basename( $session_id, $task ) {
        $session_id = preg_replace( '/[^0-9]/', '', (string) $session_id );
        $task       = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $task );
        if ( '' === $session_id ) {
            $session_id = (string) time();
        }
        return 'snap-' . $session_id . '-' . $task;
    }
}
