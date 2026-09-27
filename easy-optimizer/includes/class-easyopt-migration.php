<?php
/**
 * One-shot migration from the per-option storage of pre-1.7.0 to the
 * single `easyopt_settings` array introduced in 1.7.0.
 *
 * Runs at most once per install. Detected via the `easyopt_settings_migrated`
 * marker option (kept after migration so we never re-run). For sites that
 * never had a previous version installed, this is a fast no-op: no old
 * rows exist, the marker is set, the storage option is left to its schema
 * defaults.
 *
 * Snapshot: before deleting the old rows we serialise them into a JSON
 * file under wp-content/uploads/easyopt-snapshots/ (same directory the
 * DB-cleanup snapshots already use). This is a safety net only — the
 * UI doesn't expose a "rollback to 1.6.x options" button. If something
 * goes wrong an admin can restore manually with the file.
 *
 * @package EasyOptimizer
 * @since   1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Migration {

    const MARKER_OPTION = 'easyopt_settings_migrated';

    /**
     * Run migration if it hasn't run yet. Cheap (one option read) when
     * already migrated, so it's safe to call on every admin_init.
     */
    public static function maybe_run() {
        if ( (int) get_option( self::MARKER_OPTION, 0 ) >= 1 ) {
            return;
        }

        global $wpdb;

        // Read every easyopt_* row in one query. This is the exact pattern
        // the old EasyOpt_Config::ensure_loaded() used pre-1.7.0.
        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'easyopt\\_%'",
            ARRAY_A
        );
        if ( ! is_array( $rows ) ) {
            $rows = array();
        }

        $setting_keys_flipped = array_flip( EasyOpt_Settings_Registry::keys() );
        $migrated  = array();
        $snapshot  = array();

        foreach ( $rows as $row ) {
            $name  = isset( $row['option_name'] ) ? (string) $row['option_name'] : '';
            $value = isset( $row['option_value'] ) ? $row['option_value'] : '';
            if ( '' === $name ) {
                continue;
            }
            $snapshot[ $name ] = $value;

            // Only collapse known settings. Runtime-state rows (queue
            // counters, version markers, etc.) stay where they are.
            if ( ! isset( $setting_keys_flipped[ $name ] ) ) {
                continue;
            }
            $migrated[ $name ] = maybe_unserialize( $value );
        }

        // Persist the snapshot before we touch anything (best-effort —
        // a failed snapshot is logged but doesn't block migration).
        self::write_snapshot( $snapshot );

        // Build the new storage row. Start from schema defaults so any
        // setting the old install never touched gets its default value.
        $defaults = EasyOpt_Settings_Registry::defaults();
        $combined = array_replace( $defaults, $migrated );

        // Write the single storage row. autoload=true so it lives in
        // alloptions and never costs a DB hit on the frontend.
        update_option( EasyOpt_Config::STORAGE_OPTION, $combined, true );

        // Delete the old individual rows. Single bulk DELETE — much
        // cheaper than 80 separate delete_option() calls (each of which
        // would invalidate the alloptions cache).
        if ( ! empty( $migrated ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $migrated ), '%s' ) );
            $names        = array_keys( $migrated );
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name IN ($placeholders)",
                $names
            ) );
            // Bust alloptions once. delete_option() would do this per call.
            wp_cache_delete( 'alloptions', 'options' );
        }

        update_option( self::MARKER_OPTION, 1, true );
    }

    /**
     * Best-effort JSON snapshot of all easyopt_* rows at migration time.
     * Failures are silent — we don't want a write-protected uploads dir
     * to block migration entirely.
     */
    private static function write_snapshot( array $rows ) {
        if ( empty( $rows ) ) {
            return;
        }
        $uploads = wp_upload_dir();
        if ( empty( $uploads['basedir'] ) ) {
            return;
        }
        $dir = trailingslashit( $uploads['basedir'] ) . 'easyopt-snapshots';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
            return;
        }
        // Protect the directory from public listing.
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "Require all denied\nDeny from all\n", LOCK_EX );
        }
        $file = $dir . '/pre-1.7.0-options-' . gmdate( 'Y-m-d-His' ) . '.json';
        $payload = wp_json_encode( array(
            'created_at' => gmdate( 'c' ),
            'plugin'     => 'easy-optimizer',
            'reason'     => '1.7.0 single-row settings migration',
            'rows'       => $rows,
        ), JSON_PRETTY_PRINT );
        @file_put_contents( $file, (string) $payload, LOCK_EX );
    }
}
