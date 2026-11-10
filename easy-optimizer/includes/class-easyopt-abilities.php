<?php
/**
 * (2.8.0) Read-only diagnostics for AI assistants.
 *
 * Registers WordPress Abilities (core since 6.9). With the MCP Adapter plugin
 * installed, an assistant signed in as an administrator can read them; they
 * change nothing. Nothing here runs unless the abilities registry is used.
 *
 *   easy-optimizer/get-settings        settings, credentials withheld
 *   easy-optimizer/explain-setting     one setting: type, default, value, what saving it clears
 *   easy-optimizer/get-page-status     cache, preload, Used CSS, LCP, JS and skips for one page
 *   easy-optimizer/get-preload-health  preload progress and the last run's results
 *   easy-optimizer/get-conflicts       other plugins doing the same jobs, settings that cancel out
 *   easy-optimizer/get-error-log       recent warnings and errors, redacted
 *
 * Switch off with add_filter( 'easyopt_enable_abilities', '__return_false' ).
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Abilities {

    const CATEGORY = 'easy-optimizer-diagnostics';

    /** Hooked in easy-optimizer.php when the Abilities API exists. */
    public static function enabled() {
        return (bool) apply_filters( 'easyopt_enable_abilities', true );
    }

    public static function register_category() {
        if ( ! self::enabled() ) {
            return;
        }
        wp_register_ability_category( self::CATEGORY, array(
            'label'       => __( 'Easy Optimizer diagnostics', 'easy-optimizer' ),
            'description' => __( 'Read-only checks of the page cache, preload, settings and errors.', 'easy-optimizer' ),
        ) );
    }

    public static function register() {
        if ( ! self::enabled() ) {
            return;
        }
        $none = array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false, 'default' => array() );

        self::add( 'get-settings', __( 'Get settings', 'easy-optimizer' ),
            __( 'Easy Optimizer\'s settings (key => value). Credentials are never included.', 'easy-optimizer' ),
            array(
                'type'                 => 'object',
                'properties'           => array(
                    'keys' => array(
                        'type'        => 'array',
                        'items'       => array( 'type' => 'string' ),
                        'description' => __( 'Only these settings (e.g. easyopt_delay_js). All when omitted.', 'easy-optimizer' ),
                    ),
                ),
                'additionalProperties' => false,
                'default'              => array(),
            ),
            array( __CLASS__, 'get_settings' )
        );

        self::add( 'explain-setting', __( 'Explain a setting', 'easy-optimizer' ),
            __( 'One setting: its type, default and current value, and what saving a change to it clears.', 'easy-optimizer' ),
            array(
                'type'                 => 'object',
                'properties'           => array(
                    'key' => array( 'type' => 'string', 'description' => __( 'Setting key, e.g. easyopt_unused_css.', 'easy-optimizer' ) ),
                ),
                'required'             => array( 'key' ),
                'additionalProperties' => false,
            ),
            array( __CLASS__, 'explain_setting' )
        );

        self::add( 'get-page-status', __( 'Get page status', 'easy-optimizer' ),
            __( 'For one page on this site: cache state per device, preload result, Used CSS, LCP, JavaScript settings, which features skip it and the last "Test this page" result.', 'easy-optimizer' ),
            array(
                'type'                 => 'object',
                'properties'           => array(
                    'url' => array( 'type' => 'string', 'description' => __( 'Page address on this site, or a path such as /about/.', 'easy-optimizer' ) ),
                ),
                'required'             => array( 'url' ),
                'additionalProperties' => false,
            ),
            array( __CLASS__, 'get_page_status' )
        );

        self::add( 'get-preload-health', __( 'Get preload health', 'easy-optimizer' ),
            __( 'Pages cached, URLs waiting, the preload\'s state and the last run\'s results by outcome.', 'easy-optimizer' ),
            $none,
            array( __CLASS__, 'get_preload_health' )
        );

        self::add( 'get-conflicts', __( 'Get conflicts', 'easy-optimizer' ),
            __( 'Other plugins or host caches doing the same jobs, and Easy Optimizer settings that have no effect in the current combination.', 'easy-optimizer' ),
            $none,
            array( __CLASS__, 'get_conflicts' )
        );

        self::add( 'get-error-log', __( 'Get error log', 'easy-optimizer' ),
            __( 'Recent warnings and errors from Easy Optimizer\'s log, newest first. Paths, e-mail addresses and tokens are redacted.', 'easy-optimizer' ),
            array(
                'type'                 => 'object',
                'properties'           => array(
                    'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
                ),
                'additionalProperties' => false,
                'default'              => array(),
            ),
            array( __CLASS__, 'get_error_log' )
        );
    }

    private static function add( $name, $label, $description, $input, $callback ) {
        wp_register_ability( 'easy-optimizer/' . $name, array(
            'label'               => $label,
            'description'         => $description,
            'category'            => self::CATEGORY,
            'input_schema'        => $input,
            'output_schema'       => array( 'type' => 'object' ),
            'execute_callback'    => $callback,
            'permission_callback' => array( __CLASS__, 'can' ),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
                'mcp'          => array( 'public' => true ),
            ),
        ) );
    }

    public static function can() {
        return current_user_can( 'manage_options' );
    }

    /* ───────────────────────────────────────────────
     *  Abilities
     * ─────────────────────────────────────────────── */

    public static function get_settings( $input = array() ) {
        $input  = (array) $input;
        $secret = array_flip( EasyOpt_Rest_Dashboard::secret_setting_keys() );
        $out    = array_diff_key( EasyOpt_Config::get_all(), $secret );
        if ( ! empty( $input['keys'] ) ) {
            $want = array_flip( array_map( array( __CLASS__, 'full_key' ), (array) $input['keys'] ) );
            $out  = array_intersect_key( $out, $want );
        }
        return array(
            'version'  => defined( 'EASYOPT_VERSION' ) ? EASYOPT_VERSION : '',
            'settings' => $out,
        );
    }

    public static function explain_setting( $input = array() ) {
        $input  = (array) $input;
        $key    = self::full_key( isset( $input['key'] ) ? $input['key'] : '' );
        $schema = EasyOpt_Settings_Registry::schema();
        if ( ! isset( $schema[ $key ] ) ) {
            return new WP_Error( 'easyopt_unknown_setting', __( 'No such Easy Optimizer setting.', 'easy-optimizer' ) );
        }
        $secret = in_array( $key, EasyOpt_Rest_Dashboard::secret_setting_keys(), true );
        $all    = EasyOpt_Config::get_all();
        $value  = $secret || ! array_key_exists( $key, $all ) ? null : $all[ $key ];
        return array(
            'key'        => $key,
            'type'       => (string) $schema[ $key ]['type'],
            'default'    => $secret ? null : $schema[ $key ]['default'],
            'value'      => $value,
            'is_default' => ! $secret && (string) $value === (string) $schema[ $key ]['default'],
            'credential' => $secret,
            'on_save'    => EasyOpt_Save_Coordinator::effects( $key ),
        );
    }

    public static function get_page_status( $input = array() ) {
        $input = (array) $input;
        $url   = trim( isset( $input['url'] ) ? (string) $input['url'] : '' );
        if ( '' !== $url && '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
            $url = home_url( $url );
        }
        $req = new WP_REST_Request( 'GET', '/easyopt/v1/debug/url-inspect' );
        $req->set_param( 'url', $url );
        $res = EasyOpt_Rest_Dashboard::debug_url_inspect( $req );
        return is_wp_error( $res ) ? $res : (array) $res->get_data();
    }

    public static function get_preload_health( $input = array() ) {
        $s = (array) EasyOpt_Rest_Dashboard::get_dashboard_stats()->get_data();
        $p = isset( $s['preload_status'] ) ? (array) $s['preload_status'] : array();
        return array(
            'cache_on'      => (bool) (int) EasyOpt_Config::get( 'cache', 0 ),
            'preload_on'    => (bool) (int) EasyOpt_Config::get( 'cache_preload', 0 ),
            'pages_cached'  => isset( $s['pages'] ) ? (int) $s['pages'] : 0,
            'urls_waiting'  => isset( $s['waiting'] ) ? (int) $s['waiting'] : 0,
            'status'        => isset( $p['status'] ) ? (string) $p['status'] : 'idle',
            'done'          => isset( $p['done'] ) ? (int) $p['done'] : 0,
            'total'         => isset( $p['total'] ) ? (int) $p['total'] : 0,
            'paused_reason' => isset( $p['paused_reason'] ) ? (string) $p['paused_reason'] : '',
            'last_run'      => class_exists( 'EasyOpt_Preload_Results' ) ? EasyOpt_Preload_Results::counts() : array(),
        );
    }

    public static function get_conflicts( $input = array() ) {
        $plugins = array();
        if ( class_exists( 'EasyOpt_Compat_Conflicts' ) ) {
            $scan = EasyOpt_Compat_Conflicts::scan();
            foreach ( (array) $scan['proven'] as $f ) {
                if ( ! empty( $f['breaking'] ) ) {
                    $plugins[] = array(
                        'kind'   => 'conflict',
                        'title'  => (string) $f['name'],
                        'detail' => sprintf( 'Also %s, running Easy Optimizer\'s %s a second time.', $f['reason'], $f['feat'] ),
                    );
                }
            }
            $card = EasyOpt_Compat_Conflicts::card_data();
            foreach ( (array) $card['items'] as $item ) {
                unset( $item['url'] );
                $plugins[] = $item;
            }
        }
        return array(
            'plugins'  => $plugins,
            'settings' => self::setting_conflicts(),
        );
    }

    public static function get_error_log( $input = array() ) {
        $input = (array) $input;
        $limit = isset( $input['limit'] ) ? max( 1, min( 200, (int) $input['limit'] ) ) : 50;
        if ( ! class_exists( 'EasyOpt_Debug_Log' ) ) {
            return array( 'entries' => array(), 'counts' => array( 'warnings' => 0, 'errors' => 0, 'total' => 0 ) );
        }
        $entries = array();
        foreach ( EasyOpt_Debug_Log::read( $limit ) as $e ) {
            $entries[] = array(
                'time'      => (string) $e['time'],
                'level'     => (string) $e['level'],
                'subsystem' => (string) $e['subsystem'],
                'message'   => self::redact( $e['message'] ),
            );
        }
        return array( 'entries' => $entries, 'counts' => EasyOpt_Debug_Log::counts() );
    }

    /* ───────────────────────────────────────────────
     *  Helpers
     * ─────────────────────────────────────────────── */

    /** Settings switched on that do nothing in the current combination. */
    public static function setting_conflicts() {
        $on  = function ( $k ) { return (bool) (int) EasyOpt_Config::get( $k, 0 ); };
        $out = array();
        if ( ( $on( 'preload_fonts' ) || $on( 'lazyload_fonts' ) ) && ! $on( 'unused_css' ) ) {
            $out[] = array(
                'settings' => array( 'easyopt_preload_fonts', 'easyopt_lazyload_fonts', 'easyopt_unused_css' ),
                'issue'    => 'Smart Preload Fonts and Smart Lazyload Fonts work through Remove Unused CSS, which is off, so they do nothing.',
            );
        } elseif ( $on( 'lazyload_fonts' ) && 'remove' === EasyOpt_Config::get( 'unused_css_behavior', 'delayed' ) ) {
            $out[] = array(
                'settings' => array( 'easyopt_lazyload_fonts', 'easyopt_unused_css_behavior' ),
                'issue'    => 'Smart Lazyload Fonts does nothing while Remove Unused CSS removes the unused CSS outright (behavior "remove").',
            );
        }
        if ( $on( 'lazy_youtube' ) && ! $on( 'lazy_iframes' ) ) {
            $out[] = array(
                'settings' => array( 'easyopt_lazy_youtube', 'easyopt_lazy_iframes' ),
                'issue'    => 'YouTube preview images are part of iframe lazy loading, which is off, so videos load as normal.',
            );
        }
        if ( class_exists( 'EasyOpt_CDN_Assets' ) ) {
            if ( EasyOpt_CDN_Assets::configured( 'custom' ) && EasyOpt_CDN_Assets::configured( 'cloud' ) && EasyOpt_CDN_Assets::healthy( 'cloud' ) ) {
                $out[] = array(
                    'settings' => array( 'easyopt_cdn_custom', 'easyopt_cloud_assets' ),
                    'issue'    => 'Your own CDN is set up but not used: FluxPress asset delivery is on and takes precedence. Your CDN takes over only if FluxPress stops answering.',
                );
            }
            foreach ( EasyOpt_CDN_Assets::health() as $mode => $h ) {
                if ( is_array( $h ) && isset( $h['ok'] ) && ! $h['ok'] ) {
                    $out[] = array(
                        'settings' => array( 'cloud' === $mode ? 'easyopt_cloud_assets' : 'easyopt_cdn_custom_url' ),
                        'issue'    => sprintf(
                            '%s is not reachable (%s), so files load from this server until it answers. Checked every 15 minutes.',
                            'cloud' === $mode ? 'FluxPress asset delivery' : 'Your own CDN',
                            self::redact( isset( $h['error'] ) ? $h['error'] : '' )
                        ),
                    );
                }
                if ( is_array( $h ) && isset( $h['cors'] ) && false === $h['cors'] ) {
                    $out[] = array(
                        'settings' => array( 'easyopt_cdn_custom_url' ),
                        'issue'    => 'Your own CDN sends no Access-Control-Allow-Origin header for fonts, so fonts and stylesheets load from this server.',
                    );
                }
            }
        }
        return $out;
    }

    /** easyopt_ prefix optional on input. */
    private static function full_key( $key ) {
        $key = strtolower( trim( (string) $key ) );
        return 0 === strpos( $key, 'easyopt_' ) ? $key : 'easyopt_' . $key;
    }

    /** No server paths, e-mail addresses, credentials or opaque tokens; 500 characters at most. */
    public static function redact( $s ) {
        $s = (string) $s;
        foreach ( array( ABSPATH, defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '' ) as $p ) {
            if ( '' === $p ) {
                continue;
            }
            $p = rtrim( $p, '/\\' );
            $s = str_ireplace( array( $p, str_replace( '\\', '/', $p ), str_replace( '/', '\\', $p ) ), '…', $s );
        }
        $s = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $s );
        $s = preg_replace( '/\b(key|token|secret|password|passwd|pwd|auth|signature|sig|license|licence)=[^&\s"\']+/i', '$1=[redacted]', $s );
        $s = preg_replace( '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 [redacted]', $s );
        $s = preg_replace( '/\b[A-Za-z0-9_-]{32,}\b/', '[redacted]', $s );
        return mb_strlen( $s ) > 500 ? mb_substr( $s, 0, 500 ) . '…' : $s;
    }
}
