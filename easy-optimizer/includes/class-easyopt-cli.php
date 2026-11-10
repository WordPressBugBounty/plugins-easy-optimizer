<?php
/**
 * (2.8.0) WP-CLI commands: `wp easyopt …`.
 *
 * Thin wrappers over the callbacks the settings screen uses, so each command
 * does exactly what its button does.
 *
 *   wp easyopt cache clear [<url>]
 *   wp easyopt cache status
 *   wp easyopt preload start | stop | status
 *   wp easyopt inspect <url>
 *   wp easyopt settings export [<file>]
 *   wp easyopt settings import <file>
 *
 * Loaded only under WP-CLI (see easy-optimizer.php).
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manage Easy Optimizer's page cache, preload and settings.
 */
class EasyOpt_CLI {

    public static function register() {
        WP_CLI::add_command( 'easyopt', 'EasyOpt_CLI' );
        WP_CLI::add_command( 'easyopt cache', 'EasyOpt_CLI_Cache' );
        WP_CLI::add_command( 'easyopt preload', 'EasyOpt_CLI_Preload' );
        WP_CLI::add_command( 'easyopt settings', 'EasyOpt_CLI_Settings' );
    }

    /**
     * Show everything Easy Optimizer knows about one page: cache, preload,
     * Used CSS, LCP, JavaScript, and which features skip it.
     *
     * ## OPTIONS
     *
     * <url>
     * : A page address on this site.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: text
     * options:
     *   - text
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp easyopt inspect https://example.com/about/
     */
    public function inspect( $args, $assoc_args ) {
        $req = new WP_REST_Request( 'GET', '/easyopt/v1/debug/url-inspect' );
        $req->set_param( 'url', (string) $args[0] );
        $data = self::data( EasyOpt_Rest_Dashboard::debug_url_inspect( $req ) );
        if ( 'json' === self::flag( $assoc_args, 'format', 'text' ) ) {
            WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
            return;
        }
        foreach ( self::flatten( $data ) as $k => $v ) {
            WP_CLI::line( str_pad( $k . ':', 32 ) . $v );
        }
    }

    /** A callback's response data — or stop with its error. */
    public static function data( $res ) {
        if ( is_wp_error( $res ) ) {
            WP_CLI::error( $res->get_error_message() );
        }
        return is_object( $res ) && method_exists( $res, 'get_data' ) ? (array) $res->get_data() : (array) $res;
    }

    public static function flag( $assoc_args, $name, $default ) {
        return isset( $assoc_args[ $name ] ) ? (string) $assoc_args[ $name ] : $default;
    }

    /** Same host as the site (www. or not). */
    public static function own_url( $url ) {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        return '' !== $host && preg_replace( '/^www\./', '', $host ) === preg_replace( '/^www\./', '', $home );
    }

    /** Nested data as "a.b.c" => printable value. */
    private static function flatten( array $data, $prefix = '' ) {
        $out = array();
        foreach ( $data as $k => $v ) {
            $key = '' === $prefix ? (string) $k : $prefix . '.' . $k;
            if ( is_array( $v ) && ! empty( $v ) ) {
                $out += self::flatten( $v, $key );
            } elseif ( is_array( $v ) ) {
                $out[ $key ] = '-';
            } elseif ( is_bool( $v ) || null === $v ) {
                $out[ $key ] = $v ? 'yes' : 'no';
            } else {
                $out[ $key ] = (string) $v;
            }
        }
        return $out;
    }
}

/**
 * Clear the page cache or show its state.
 */
class EasyOpt_CLI_Cache {

    /**
     * Empty the page cache: the whole site, or one page.
     *
     * ## OPTIONS
     *
     * [<url>]
     * : Clear only this page (with its /page/N/ copies), then warm it again
     * when preload is on.
     *
     * ## EXAMPLES
     *
     *     wp easyopt cache clear
     *     wp easyopt cache clear https://example.com/about/
     */
    public function clear( $args, $assoc_args ) {
        if ( empty( $args[0] ) ) {
            $data = EasyOpt_CLI::data( EasyOpt_Rest_Dashboard::clear_cache() );
            WP_CLI::success( $data['message'] );
            return;
        }
        $url = (string) $args[0];
        if ( ! EasyOpt_CLI::own_url( $url ) ) {
            WP_CLI::error( 'Enter a page address on this site.' );
        }
        EasyOpt_Cache::queue_url_purge( $url, true );
        EasyOpt_Cache::drain_purge_queue();
        WP_CLI::success( 'Cleared ' . $url );
    }

    /**
     * Show the page cache state.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     * ---
     */
    public function status( $args, $assoc_args ) {
        $s   = EasyOpt_CLI::data( EasyOpt_Rest_Dashboard::get_dashboard_stats() );
        $p   = isset( $s['preload_status'] ) ? (array) $s['preload_status'] : array();
        $row = array(
            'cache'        => (int) EasyOpt_Config::get( 'cache', 0 ) ? 'on' : 'off',
            'mode'         => (string) EasyOpt_Config::get( 'cache_mode', '' ),
            'pages_cached' => isset( $s['pages'] ) ? (int) $s['pages'] : 0,
            'urls_waiting' => isset( $s['waiting'] ) ? (int) $s['waiting'] : 0,
            'preload'      => isset( $p['status'] ) ? (string) $p['status'] : 'idle',
        );
        WP_CLI\Utils\format_items( EasyOpt_CLI::flag( $assoc_args, 'format', 'table' ), array( $row ), array_keys( $row ) );
    }
}

/**
 * Start, stop or check the cache preload.
 */
class EasyOpt_CLI_Preload {

    /**
     * Start a full preload. It runs in the background.
     */
    public function start( $args, $assoc_args ) {
        $data = EasyOpt_CLI::data( EasyOpt_Rest_Dashboard::preload_start() );
        WP_CLI::success( $data['message'] );
    }

    /**
     * Stop the running preload.
     */
    public function stop( $args, $assoc_args ) {
        EasyOpt_CLI::data( EasyOpt_Rest_Dashboard::preload_stop() );
        WP_CLI::success( 'Preload stopped.' );
    }

    /**
     * Show the preload's progress.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     * ---
     */
    public function status( $args, $assoc_args ) {
        $d   = EasyOpt_CLI::data( EasyOpt_Rest_Dashboard::preload_status() );
        $row = array(
            'status' => isset( $d['status'] ) ? (string) $d['status'] : 'idle',
            'done'   => isset( $d['done'] ) ? (int) $d['done'] : 0,
            'total'  => isset( $d['total'] ) ? (int) $d['total'] : 0,
            'paused' => isset( $d['paused_reason'] ) ? (string) $d['paused_reason'] : '',
        );
        WP_CLI\Utils\format_items( EasyOpt_CLI::flag( $assoc_args, 'format', 'table' ), array( $row ), array_keys( $row ) );
    }
}

/**
 * Export or import settings — the same file as Tools → Export / Import.
 */
class EasyOpt_CLI_Settings {

    /**
     * Export the settings as JSON. Credentials are left out.
     *
     * ## OPTIONS
     *
     * [<file>]
     * : Write to this file instead of the screen.
     *
     * ## EXAMPLES
     *
     *     wp easyopt settings export easy-optimizer-settings.json
     */
    public function export( $args, $assoc_args ) {
        $secret = EasyOpt_Rest_Dashboard::secret_setting_keys();
        $out    = EasyOpt_Config::get_all();
        foreach ( $secret as $k ) {
            unset( $out[ $k ] );
        }
        $count                  = count( $out );
        $out['_exported_at']    = gmdate( 'c' );
        $out['_plugin_version'] = defined( 'EASYOPT_VERSION' ) ? EASYOPT_VERSION : '';
        $out['_redacted_keys']  = array_values( $secret );
        $json                   = wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( empty( $args[0] ) ) {
            WP_CLI::line( $json );
            return;
        }
        if ( false === file_put_contents( $args[0], $json ) ) {
            WP_CLI::error( 'Could not write ' . $args[0] );
        }
        WP_CLI::success( sprintf( 'Exported %d settings to %s (credentials left out).', $count, $args[0] ) );
    }

    /**
     * Import a settings file made by Export. Credentials in it are ignored;
     * the cache and preload react as they do to a save on the settings screen.
     *
     * ## OPTIONS
     *
     * <file>
     * : The exported JSON file.
     *
     * ## EXAMPLES
     *
     *     wp easyopt settings import easy-optimizer-settings.json
     */
    public function import( $args, $assoc_args ) {
        $raw = is_readable( $args[0] ) ? file_get_contents( $args[0] ) : false;
        if ( false === $raw ) {
            WP_CLI::error( 'Could not read ' . $args[0] );
        }
        $parsed = json_decode( $raw, true );
        if ( ! is_array( $parsed ) ) {
            WP_CLI::error( 'Not a settings file (invalid JSON).' );
        }
        unset( $parsed['_exported_at'], $parsed['_plugin_version'], $parsed['_redacted_keys'] );
        $req = new WP_REST_Request( 'POST', '/easyopt/v1/settings/import' );
        $req->set_header( 'content-type', 'application/json' );
        $req->set_body( wp_json_encode( array( 'settings' => $parsed ) ) );
        $data = EasyOpt_CLI::data( EasyOpt_Rest_Dashboard::settings_import( $req ) );
        if ( empty( $data['success'] ) ) {
            WP_CLI::error( isset( $data['message'] ) ? $data['message'] : 'Import failed.' );
        }
        WP_CLI::success( $data['message'] );
    }
}
