<?php
/**
 * Uninstall handler — runs when the plugin is deleted via the WordPress admin.
 *
 * Cleans up all database entries, custom tables, cache files, and the
 * advanced-cache.php drop-in so nothing is left behind.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

/*
 * Telemetry: report the uninstall BEFORE options are wiped, and only when the
 * administrator opted in. This is the only definitive churn signal a plugin
 * can send — deactivation can be temporary, deletion is not. Blocking call
 * with a short timeout: uninstall runs once and there is no cron to retry.
 */
if ( get_option( 'easyopt_tracking_optin', '' ) === 'yes' ) {
    $easyopt_un_host = wp_parse_url( home_url() );
    $easyopt_un_host = isset( $easyopt_un_host['host'] ) ? strtolower( $easyopt_un_host['host'] ) : '';
    if ( 0 === strpos( $easyopt_un_host, 'www.' ) ) {
        $easyopt_un_host = substr( $easyopt_un_host, 4 );
    }
    $easyopt_un_salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'easyopt';
    wp_remote_post( 'https://fluxpress.io/wp-json/fluxpress/v1/track', array(
        'timeout'  => 3,
        'blocking' => true,
        'headers'  => array(
            'Content-Type' => 'application/json',
            'X-FPT-Token'  => 'RHk36MDnYeeqncpx2c6qrLDJFSKW0zRa4IkJ8zmJbTQe21ir',
        ),
        'body'     => wp_json_encode( array(
            'site_id'        => hash( 'sha256', rtrim( $easyopt_un_host, '.' ) . $easyopt_un_salt ),
            'site_url'       => home_url(),
            'event'          => 'uninstall',
            'plugin_slug'    => 'easy-optimizer',
            'plugin_version' => defined( 'EASYOPT_VERSION' ) ? EASYOPT_VERSION : '',
            'wp_version'     => get_bloginfo( 'version' ),
            'php_version'    => phpversion(),
        ) ),
    ) );
}

// Check the delete-on-uninstall setting (default: ON = 1).
// If disabled, preserve all settings and data.
$settings_row = get_option( 'easyopt_settings', array() );
$delete_data  = isset( $settings_row['easyopt_delete_on_uninstall'] )
    ? (int) $settings_row['easyopt_delete_on_uninstall']
    : 0; // (2.3.3) Default OFF — matches the wizard/preset default. The old
         // fallback of 1 meant a pre-wizard install that never saved settings
         // had its data DELETED on uninstall, contradicting the product
         // default of preserving settings for reinstall.

// (2.6.5) Takeover restore. If Easy Optimizer took over a competitor's
// advanced-cache.php, we snapshotted it. Read that snapshot NOW — before the
// options table is wiped below — and, after our own drop-in is removed, put
// the competitor's file back IF that plugin is still active. An inactive owner
// gets nothing: a restored drop-in for a plugin that no longer runs is a
// harmful orphan WordPress would load on every request.
$easyopt_dropin_backup   = get_option( 'easyopt_foreign_dropin_backup', array() );
$easyopt_restore_foreign = static function () use ( $easyopt_dropin_backup ) {
    if ( ! is_array( $easyopt_dropin_backup ) || empty( $easyopt_dropin_backup['content'] ) ) {
        return;
    }
    $file = isset( $easyopt_dropin_backup['file'] ) ? (string) $easyopt_dropin_backup['file'] : '';
    if ( '' === $file ) {
        return;
    }
    if ( ! function_exists( 'is_plugin_active' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if ( ! is_plugin_active( $file ) ) {
        return; // Owner deactivated/removed — do not restore a stale orphan.
    }
    $target = trailingslashit( WP_CONTENT_DIR ) . 'advanced-cache.php';
    if ( file_exists( $target ) ) {
        return; // Ours is already gone; if something else is there, leave it.
    }
    if ( ! is_writable( WP_CONTENT_DIR ) ) {
        return;
    }
    @file_put_contents( $target, (string) $easyopt_dropin_backup['content'], LOCK_EX );
};

if ( ! $delete_data ) {
    // User wants to keep settings for reinstall. Only clean up runtime artifacts.
    // Remove advanced-cache.php drop-in and .htaccess rules since those would
    // break without the plugin active.
    $dropin = trailingslashit( WP_CONTENT_DIR ) . 'advanced-cache.php';
    if ( file_exists( $dropin ) ) {
        $head = (string) @file_get_contents( $dropin, false, null, 0, 600 );
        if ( false !== strpos( $head, 'Easy Optimizer' ) ) {
            @unlink( $dropin );
        }
    }
    $easyopt_restore_foreign(); // Hand caching back to the competitor if still active.
    // Remove the object-cache.php drop-in too (only if ours).
    $oc_dropin = trailingslashit( WP_CONTENT_DIR ) . 'object-cache.php';
    if ( file_exists( $oc_dropin ) ) {
        $head = (string) @file_get_contents( $oc_dropin, false, null, 0, 600 );
        if ( false !== strpos( $head, 'Easy Optimizer' ) ) {
            @unlink( $oc_dropin );
        }
    }
    $htaccess = trailingslashit( ABSPATH ) . '.htaccess';
    if ( file_exists( $htaccess ) && is_writable( $htaccess ) ) {
        $content = (string) @file_get_contents( $htaccess );
        $pattern = '/# BEGIN Easy Optimizer.*?# END Easy Optimizer\s*/s';
        $new     = preg_replace( $pattern, '', $content );
        if ( null !== $new && $new !== $content ) {
            @file_put_contents( $htaccess, $new, LOCK_EX );
        }
    }
    return;
}

// 1. Remove the consolidated settings row.
delete_option( 'easyopt_settings' );

// 2. Remove all individual easyopt_* options (runtime state, markers, counters).
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'easyopt\_%'" );

// 3. Remove per-user dismiss meta.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'easyopt\_%'" );

// 4. Drop custom tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}easyopt_queue" );
// (2.4.0) Backend Analyzer tables.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}easyopt_callbacks" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}easyopt_queries" );
// (2.4.0) PSI benchmark options.
foreach ( array( 'easyopt_psi_credentials', 'easyopt_psi_results', 'easyopt_psi_quota', 'easyopt_psi_bypass', 'easyopt_psi_verify_code', 'easyopt_psi_job_state', 'easyopt_psi_key_status', 'easyopt_psi_first_after' ) as $easyopt_psi_opt ) {
    delete_option( $easyopt_psi_opt );
}
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}easyopt_lcp" );
// (2.5.0) Preload results ledger table. (Its options easyopt_preload_results_schema
// and easyopt_preload_run_id are already removed by the easyopt_% wildcard above.)
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}easyopt_preload_results" );

// 5. Remove the advanced-cache.php drop-in (only if ours), then hand caching
//    back to a competitor we took over (only if it is still active).
$dropin = trailingslashit( WP_CONTENT_DIR ) . 'advanced-cache.php';
if ( file_exists( $dropin ) ) {
    $head = (string) @file_get_contents( $dropin, false, null, 0, 600 );
    if ( false !== strpos( $head, 'Easy Optimizer' ) ) {
        @unlink( $dropin );
    }
}
$easyopt_restore_foreign();

// 5b. Remove the object-cache.php drop-in + its config (only if ours).
$oc_dropin = trailingslashit( WP_CONTENT_DIR ) . 'object-cache.php';
if ( file_exists( $oc_dropin ) ) {
    $head = (string) @file_get_contents( $oc_dropin, false, null, 0, 600 );
    if ( false !== strpos( $head, 'Easy Optimizer' ) ) {
        @unlink( $oc_dropin );
    }
}
$oc_config = trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt/object-cache-config.php';
if ( file_exists( $oc_config ) ) {
    @unlink( $oc_config );
}

// 6. Remove WP_CACHE constant from wp-config.php (only our annotation).
$wp_config_paths = array(
    ABSPATH . 'wp-config.php',
    dirname( ABSPATH ) . '/wp-config.php',
);
foreach ( $wp_config_paths as $path ) {
    if ( file_exists( $path ) && is_writable( $path ) ) {
        $content = (string) @file_get_contents( $path );
        $pattern = '/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*[^)]+\)\s*;\s*\/\/\s*Added by Easy Optimizer\s*\n?/i';
        $new     = preg_replace( $pattern, '', $content );
        if ( null !== $new && $new !== $content ) {
            @file_put_contents( $path, $new, LOCK_EX );
        }
        break;
    }
}

// 7. Delete cache directory.
$cache_dir = trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt';
if ( is_dir( $cache_dir ) ) {
    $it    = new RecursiveDirectoryIterator( $cache_dir, RecursiveDirectoryIterator::SKIP_DOTS );
    $files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );
    foreach ( $files as $file ) {
        if ( $file->isDir() ) {
            @rmdir( $file->getRealPath() );
        } else {
            @unlink( $file->getRealPath() );
        }
    }
    @rmdir( $cache_dir );
}

// 8. Delete used-CSS and inline-JS cache directories in uploads.
$upload_dir = wp_upload_dir();
if ( ! empty( $upload_dir['basedir'] ) ) {
    $easyopt_uploads = trailingslashit( $upload_dir['basedir'] ) . 'easyopt';
    if ( is_dir( $easyopt_uploads ) ) {
        $it    = new RecursiveDirectoryIterator( $easyopt_uploads, RecursiveDirectoryIterator::SKIP_DOTS );
        $files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $files as $file ) {
            if ( $file->isDir() ) {
                @rmdir( $file->getRealPath() );
            } else {
                @unlink( $file->getRealPath() );
            }
        }
        @rmdir( $easyopt_uploads );
    }

    // 8b. Delete the snapshot directory (DB cleanup snapshots + the
    // pre-1.7.0 settings-migration backup). These can contain copies of
    // deleted database rows, so they must not survive a full uninstall.
    $easyopt_snapshots = trailingslashit( $upload_dir['basedir'] ) . 'easyopt-snapshots';
    if ( is_dir( $easyopt_snapshots ) ) {
        $it    = new RecursiveDirectoryIterator( $easyopt_snapshots, RecursiveDirectoryIterator::SKIP_DOTS );
        $files = new RecursiveIteratorIterator( $it, RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $files as $file ) {
            if ( $file->isDir() ) {
                @rmdir( $file->getRealPath() );
            } else {
                @unlink( $file->getRealPath() );
            }
        }
        @rmdir( $easyopt_snapshots );
    }
}

// 9. Remove .htaccess rules.
$htaccess = trailingslashit( ABSPATH ) . '.htaccess';
if ( file_exists( $htaccess ) && is_writable( $htaccess ) ) {
    $content = (string) @file_get_contents( $htaccess );
    $pattern = '/# BEGIN Easy Optimizer.*?# END Easy Optimizer\s*/s';
    $new     = preg_replace( $pattern, '', $content );
    if ( null !== $new && $new !== $content ) {
        @file_put_contents( $htaccess, $new, LOCK_EX );
    }
}

// 10. Clear all transients we may have set — including SITE transients,
// which the pre-2.3.3 cleanup missed. On single site, site transients live
// in wp_options as `_site_transient_*`; on multisite they live in sitemeta.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_easyopt%' OR option_name LIKE '_transient_timeout_easyopt%' OR option_name LIKE '_site_transient_easyopt%' OR option_name LIKE '_site_transient_timeout_easyopt%'" );
if ( is_multisite() && isset( $wpdb->sitemeta ) ) {
    $wpdb->query( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_easyopt%' OR meta_key LIKE '_site_transient_timeout_easyopt%' OR meta_key LIKE 'easyopt\_%'" );
}

// 11. Remove tracking opt-in data.
delete_option( 'easyopt_tracking_optin' );
delete_option( 'easyopt_tracking_asked_at' );
delete_option( 'easyopt_tracked_version' );
$ts = wp_next_scheduled( 'easyopt_weekly_tracking' );
if ( $ts ) {
    wp_unschedule_event( $ts, 'easyopt_weekly_tracking' );
}

// 12. Flush alloptions cache.
wp_cache_delete( 'alloptions', 'options' );
