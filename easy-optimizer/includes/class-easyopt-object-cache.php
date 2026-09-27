<?php
/**
 * Object Cache controller (plugin side).
 *
 * Detects the environment, installs/removes the Easy Optimizer object-cache
 * drop-in (mirroring the discipline of EasyOpt_Advanced_Cache — marker-guarded,
 * syntax-checked, opcache-invalidated, emergency-disabled on failure), and
 * gathers diagnostics for the admin panel.
 *
 * It NEVER overwrites or deletes a drop-in owned by another plugin, and never
 * implements caching itself — the engine lives entirely in the drop-in.
 *
 * @package EasyOptimizer
 * @since   2.4.7
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * NOTE: this controller is intentionally named EasyOpt_Object_Cache_Manager,
 * NOT EasyOpt_Object_Cache. The drop-in (assets/object-cache.php) defines the
 * engine class EasyOpt_Object_Cache and WordPress loads it very early; if this
 * controller used the same name the two declarations would collide and fatal.
 */
class EasyOpt_Object_Cache_Manager {

    /** Identity marker the drop-in carries; gates overwrite/delete. */
    const MARKER = 'EASYOPT_OBJECT_CACHE_DROPIN';

    /** Boot — light. Heavy work happens on save/activation, not per request. */
    public static function init() {
        // (2.5.4 / perf #5) The only hook is an admin notice.
        if ( ! is_admin() ) {
            return;
        }
        add_action( 'admin_notices', array( __CLASS__, 'maybe_admin_notice' ) );
    }

    /* ── Paths ───────────────────────────────────────────────────────── */

    public static function target_path() {
        return trailingslashit( WP_CONTENT_DIR ) . 'object-cache.php';
    }

    public static function source_path() {
        return trailingslashit( EASYOPT_DIR ) . 'assets/object-cache.php';
    }

    public static function config_path() {
        return trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt/object-cache-config.php';
    }

    private static function predis_autoload_path() {
        return trailingslashit( EASYOPT_DIR ) . 'vendor/predis/predis/autoload.php';
    }

    /* ── State ───────────────────────────────────────────────────────── */

    public static function is_enabled() {
        return 1 === (int) EasyOpt_Config::get( 'object_cache', 0 );
    }

    public static function dropin_exists() {
        return file_exists( self::target_path() );
    }

    /** True when the installed drop-in is the one WE ship (marker present). */
    public static function dropin_is_ours() {
        if ( ! self::dropin_exists() ) {
            return false;
        }
        $head = (string) @file_get_contents( self::target_path(), false, null, 0, 1200 );
        return false !== strpos( $head, self::MARKER );
    }

    /** True when a DIFFERENT plugin's drop-in occupies the slot. */
    public static function foreign_dropin_present() {
        return self::dropin_exists() && ! self::dropin_is_ours();
    }

    /** Best-effort "Plugin Name:" header of a foreign drop-in, for the UI. */
    public static function foreign_dropin_name() {
        if ( ! self::foreign_dropin_present() ) {
            return '';
        }
        $head = (string) @file_get_contents( self::target_path(), false, null, 0, 1200 );
        if ( preg_match( '/Plugin Name:\s*(.+)/i', $head, $m ) ) {
            return trim( $m[1] );
        }
        return __( 'another plugin', 'easy-optimizer' );
    }

    /* ── Install / uninstall ─────────────────────────────────────────── */

    /**
     * Install (or refresh) our drop-in. No-ops when disabled. Refuses to
     * overwrite a foreign drop-in. Writes the connection config first so the
     * engine has it on the very next request. Syntax-checks after writing and
     * rolls back on failure so a bad write can never white-screen the site.
     *
     * @return true|WP_Error
     */
    public static function install() {
        if ( ! self::is_enabled() ) {
            return true;
        }
        if ( self::foreign_dropin_present() ) {
            return new WP_Error(
                'easyopt_oc_foreign',
                sprintf(
                    /* translators: %s: other plugin name */
                    __( 'A different object-cache drop-in (%s) is already installed. Easy Optimizer will not overwrite it.', 'easy-optimizer' ),
                    self::foreign_dropin_name()
                )
            );
        }
        if ( ! is_dir( WP_CONTENT_DIR ) || ! is_writable( WP_CONTENT_DIR ) ) {
            return new WP_Error( 'easyopt_oc_unwritable', __( 'wp-content is not writable — cannot install the object cache drop-in.', 'easy-optimizer' ) );
        }

        // Connection config the drop-in reads. Written before the drop-in so a
        // concurrent request can't read a drop-in with no config.
        self::write_config();

        $source = @file_get_contents( self::source_path() );
        if ( false === $source ) {
            return new WP_Error( 'easyopt_oc_no_source', __( 'Object cache template is missing.', 'easy-optimizer' ) );
        }

        $target = self::target_path();
        // (2.5.3) Atomic install: write to a temp file, validate THAT, and
        // only then rename over object-cache.php. A failed validation leaves
        // any existing (working) drop-in completely untouched — the old flow
        // wrote directly to the target and unlinked it on failure, which
        // deleted a working drop-in whenever the check false-positived.
        $tmp = $target . '.tmp';
        if ( false === @file_put_contents( $tmp, $source, LOCK_EX ) ) {
            return new WP_Error( 'easyopt_oc_write_failed', __( 'Failed to write the object cache drop-in.', 'easy-optimizer' ) );
        }
        $check = self::syntax_check( $tmp );
        if ( true !== $check ) {
            @unlink( $tmp );
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::error( 'objectcache', 'Syntax check failed for the new object-cache.php (' . (string) $check . '). Existing drop-in left untouched.' );
            }
            return new WP_Error( 'easyopt_oc_syntax', __( 'The new object cache drop-in failed its safety check and was not installed.', 'easy-optimizer' ) );
        }
        if ( false === @rename( $tmp, $target ) ) {
            @unlink( $tmp );
            return new WP_Error( 'easyopt_oc_write_failed', __( 'Failed to install the object cache drop-in.', 'easy-optimizer' ) );
        }
        if ( function_exists( 'opcache_invalidate' ) ) {
            @opcache_invalidate( $target, true );
        }

        // Fresh drop-in → clear any stale entries from a previous serializer.
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }
        return true;
    }

    /** Remove OUR drop-in (marker-guarded). Never touches a foreign one. */
    public static function uninstall() {
        if ( self::dropin_is_ours() ) {
            $target = self::target_path();
            @unlink( $target );
            if ( function_exists( 'opcache_invalidate' ) ) {
                @opcache_invalidate( $target, true );
            }
        }
        $cfg = self::config_path();
        if ( file_exists( $cfg ) ) {
            @unlink( $cfg );
        }
        return true;
    }

    /**
     * Write the connection config file the drop-in includes. Stored under the
     * guarded cache dir (index.html + .htaccess deny). Prefers WP_REDIS_*
     * constants at runtime; UI values are the fallback. Never edits wp-config.
     */
    public static function write_config() {
        $dir = dirname( self::config_path() );
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
            return false;
        }
        // Defence-in-depth for the directory holding the config.
        if ( ! file_exists( $dir . '/index.html' ) ) {
            @file_put_contents( $dir . '/index.html', '', LOCK_EX );
        }
        if ( ! file_exists( $dir . '/.htaccess' ) ) {
            @file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n", LOCK_EX );
        }

        $host   = trim( (string) EasyOpt_Config::get( 'oc_host', '127.0.0.1' ) );
        $scheme = 1 === (int) EasyOpt_Config::get( 'oc_tls', 0 ) ? 'tls' : 'tcp';
        $path   = '';
        if ( '' !== $host && '/' === $host[0] ) { // absolute path = unix socket
            $scheme = 'unix';
            $path   = $host;
        }

        $config = array(
            'client'          => (string) EasyOpt_Config::get( 'oc_client', 'auto' ),
            'host'            => $host,
            'port'            => (int) EasyOpt_Config::get( 'oc_port', 6379 ),
            'username'        => trim( (string) EasyOpt_Config::get( 'oc_username', '' ) ),
            'password'        => (string) EasyOpt_Config::get( 'oc_password', '' ),
            'database'        => (int) EasyOpt_Config::get( 'oc_database', 0 ),
            'prefix'          => trim( (string) EasyOpt_Config::get( 'oc_prefix', '' ) ),
            'scheme'          => $scheme,
            'path'            => $path,
            'predis_autoload' => self::predis_autoload_path(),
        );

        $php = "<?php\n// Easy Optimizer object cache connection config. Auto-generated — do not edit.\nif ( ! defined( 'ABSPATH' ) ) { exit; }\nreturn " . var_export( $config, true ) . ";\n";

        $tmp = self::config_path() . '.tmp';
        if ( false === @file_put_contents( $tmp, $php, LOCK_EX ) ) {
            return false;
        }
        if ( true !== self::syntax_check( $tmp ) ) {
            @unlink( $tmp );
            return false;
        }
        @rename( $tmp, self::config_path() );
        if ( function_exists( 'opcache_invalidate' ) ) {
            @opcache_invalidate( self::config_path(), true );
        }
        return true;
    }

    /**
     * Validate PHP syntax in-process via token_get_all(TOKEN_PARSE) — mirrors
     * advanced-cache. The previous `PHP_BINARY -l` shell-out broke on PHP-FPM
     * hosts (xCloud etc.): PHP_BINARY resolves to php-fpm, which doesn't
     * support -l and prints its usage screen, which read as a "syntax error"
     * and made the installer delete a perfectly valid drop-in.
     * Returns true or an error string.
     */
    private static function syntax_check( $path ) {
        $contents = @file_get_contents( $path );
        if ( false === $contents ) {
            return 'unreadable';
        }
        try {
            $tokens = @token_get_all( $contents, TOKEN_PARSE );
            if ( ! is_array( $tokens ) || empty( $tokens ) ) {
                return 'parse_failed';
            }
        } catch ( \ParseError $e ) {
            return 'parse_error: ' . $e->getMessage();
        } catch ( \Error $e ) {
            return 'error: ' . $e->getMessage();
        }
        return true;
    }

    /* ── Detection ───────────────────────────────────────────────────── */

    public static function detect() {
        return array(
            'phpredis'        => class_exists( 'Redis' ) || extension_loaded( 'redis' ),
            'phpredis_version'=> phpversion( 'redis' ) ?: '',
            'relay'           => class_exists( '\\Relay\\Relay' ) || extension_loaded( 'relay' ),
            'predis'          => class_exists( '\\Predis\\Client' ) || is_readable( self::predis_autoload_path() ),
            'igbinary'        => extension_loaded( 'igbinary' ),
            'using_ext'       => function_exists( 'wp_using_ext_object_cache' ) ? (bool) wp_using_ext_object_cache() : false,
            'dropin_present'  => self::dropin_exists(),
            'dropin_ours'     => self::dropin_is_ours(),
            'dropin_foreign'  => self::foreign_dropin_present(),
            'foreign_name'    => self::foreign_dropin_name(),
            'plugin'          => self::detect_plugin(),
        );
    }

    /** Identify a known third-party object-cache provider, if any. */
    private static function detect_plugin() {
        if ( class_exists( '\\RedisCachePro\\Plugin' ) || defined( 'RedisCachePro\\Version' ) || class_exists( 'RedisCachePro' ) ) {
            return 'Object Cache Pro';
        }
        if ( class_exists( 'WP_Redis' ) || defined( 'WP_REDIS_VERSION' ) || function_exists( 'redis_object_cache' ) ) {
            return 'Redis Object Cache';
        }
        if ( defined( 'W3TC' ) ) {
            return 'W3 Total Cache';
        }
        if ( defined( 'LSCWP_V' ) ) {
            return 'LiteSpeed Cache';
        }
        return '';
    }

    /* ── Status (for REST / the admin panel) ─────────────────────────── */

    /**
     * Assemble the full diagnostic payload. When our own engine is live we
     * read its in-memory counters + INFO directly; when a foreign drop-in is
     * active (or to populate stats generally) we open a short-lived read-only
     * probe connection.
     */
    public static function get_status() {
        $detect = self::detect();

        $status = array(
            'enabled'        => self::is_enabled(),
            'active'         => $detect['using_ext'],
            'provider'       => '',
            'client'         => '',
            'connected'      => false,
            'redis_version'  => '',
            'memory_used'    => 0,
            'memory_total'   => 0,
            'database'       => (int) self::resolve_params()['database'],
            'prefix'         => '',
            'hits'           => null,
            'misses'         => null,
            'error'          => '',
            'detect'         => $detect,
            'writable'       => is_dir( WP_CONTENT_DIR ) && is_writable( WP_CONTENT_DIR ),
        );

        // Provider label.
        if ( $detect['dropin_ours'] ) {
            $status['provider'] = 'Easy Optimizer';
        } elseif ( $detect['plugin'] ) {
            $status['provider'] = $detect['plugin'];
        } elseif ( $detect['dropin_foreign'] ) {
            $status['provider'] = $detect['foreign_name'];
        }

        global $wp_object_cache;
        // Engine class lives in the drop-in (loaded by WP early). This checks
        // whether the live global cache is OUR engine — deliberately the
        // EasyOpt_Object_Cache name, not this controller (…_Manager).
        $ours_live = $wp_object_cache instanceof EasyOpt_Object_Cache;

        if ( $ours_live ) {
            $status['client']    = $wp_object_cache->client;
            $status['connected'] = (bool) $wp_object_cache->redis_connected;
            $status['hits']      = (int) $wp_object_cache->cache_hits;
            $status['misses']    = (int) $wp_object_cache->cache_misses;
            $status['prefix']    = (string) $wp_object_cache->key_prefix;
            $status['database']  = (int) $wp_object_cache->database;
            $status['error']     = (string) $wp_object_cache->error;

            $info = $wp_object_cache->info();
            if ( ! empty( $info ) ) {
                $status['redis_version'] = isset( $info['redis_version'] ) ? (string) $info['redis_version'] : '';
                $status['memory_used']   = isset( $info['used_memory'] ) ? (int) $info['used_memory'] : 0;
                $status['memory_total']  = (int) ( $info['maxmemory'] ?? 0 ) ?: (int) ( $info['total_system_memory'] ?? 0 );
            }
        } else {
            // Foreign provider or not active — read-only probe for the figures.
            $probe = self::probe();
            $status['connected']     = $probe['connected'];
            $status['redis_version'] = $probe['redis_version'];
            $status['memory_used']   = $probe['memory_used'];
            $status['memory_total']  = $probe['memory_total'];
            $status['prefix']        = $probe['prefix'];
            if ( '' === $status['error'] ) {
                $status['error'] = $probe['error'];
            }
            if ( '' === $status['client'] ) {
                $status['client'] = $probe['client'];
            }
        }

        // Actionable diagnosis of the raw error, for the UI warning box.
        $classified            = self::classify_error( $status['error'] );
        $status['error_code']  = $classified['code'];
        $status['error_hint']  = $classified['hint'];
        // Which fields wp-config.php locks (constants or WP_REDIS_CONFIG).
        $status['config_sources']     = self::config_sources();
        $status['has_wp_redis_config'] = defined( 'WP_REDIS_CONFIG' ) && is_array( WP_REDIS_CONFIG );

        return $status;
    }

    /* ── Read-only probe + connection test ───────────────────────────── */

    /**
     * Open a short-lived connection (PhpRedis/Relay preferred) purely to read
     * version/memory/prefix. Read-only; closed immediately. $override lets the
     * "Test connection" button supply unsaved credentials.
     */
    public static function probe( array $override = array() ) {
        $cfg = self::resolve_params( $override );

        $out = array(
            'connected'     => false,
            'client'        => '',
            'redis_version' => '',
            'memory_used'   => 0,
            'memory_total'  => 0,
            'prefix'        => $cfg['prefix'],
            'error'         => '',
        );

        try {
            if ( class_exists( '\\Relay\\Relay' ) || class_exists( 'Redis' ) ) {
                $r   = class_exists( '\\Relay\\Relay' ) && 'phpredis' !== $cfg['client'] && 'predis' !== $cfg['client']
                    ? new \Relay\Relay()
                    : ( class_exists( 'Redis' ) ? new \Redis() : new \Relay\Relay() );
                $out['client'] = $r instanceof \Redis ? 'phpredis' : 'relay';

                if ( 'unix' === $cfg['scheme'] && '' !== $cfg['path'] ) {
                    @$r->connect( $cfg['path'] );
                } else {
                    $probe_host = 'tls' === $cfg['scheme'] ? 'tls://' . $cfg['host'] : $cfg['host'];
                    @$r->connect( $probe_host, $cfg['port'], $cfg['timeout'] );
                }
                if ( '' !== $cfg['password'] ) {
                    $auth_ok = $r->auth( '' !== $cfg['username'] ? array( $cfg['username'], $cfg['password'] ) : $cfg['password'] );
                    if ( false === $auth_ok ) {
                        $le = method_exists( $r, 'getLastError' ) ? trim( (string) $r->getLastError(), " \0\t\r\n" ) : '';
                        throw new \Exception( '' !== $le ? $le : 'WRONGPASS invalid username-password pair' );
                    }
                }
                if ( 0 !== $cfg['database'] ) {
                    // PhpRedis select() returns false (no exception) on an
                    // invalid index — without this guard we would silently
                    // stay on DB 0 and report a bogus success.
                    $sel_ok = $r->select( $cfg['database'] );
                    if ( false === $sel_ok ) {
                        $le = method_exists( $r, 'getLastError' ) ? trim( (string) $r->getLastError(), " \0\t\r\n" ) : '';
                        throw new \Exception( '' !== $le ? $le : 'ERR DB index is out of range' );
                    }
                }
                $r->ping();
                $info = $r->info();
                $out['connected'] = true;
                $out['redis_version'] = isset( $info['redis_version'] ) ? (string) $info['redis_version'] : '';
                $out['memory_used']   = isset( $info['used_memory'] ) ? (int) $info['used_memory'] : 0;
                $out['memory_total']  = (int) ( $info['maxmemory'] ?? 0 ) ?: (int) ( $info['total_system_memory'] ?? 0 );
                if ( method_exists( $r, 'close' ) ) {
                    @$r->close();
                }
                return $out;
            }
        } catch ( \Throwable $e ) {
            $out['error'] = $e->getMessage();
            return $out;
        }

        // No extension — probe with the bundled Predis so "Test Connection"
        // still works for users on the pure-PHP client.
        try {
            if ( ! class_exists( '\\Predis\\Client' ) && is_readable( self::predis_autoload_path() ) ) {
                require_once self::predis_autoload_path();
                if ( class_exists( '\\Predis\\Autoloader' ) ) {
                    \Predis\Autoloader::register();
                }
            }
            if ( class_exists( '\\Predis\\Client' ) ) {
                $params = array(
                    'scheme'   => 'unix' === $cfg['scheme'] ? 'unix' : ( 'tls' === $cfg['scheme'] ? 'tls' : 'tcp' ),
                    'timeout'  => $cfg['timeout'],
                    'database' => $cfg['database'],
                );
                if ( 'unix' === $cfg['scheme'] && '' !== $cfg['path'] ) {
                    $params['path'] = $cfg['path'];
                } else {
                    $params['host'] = $cfg['host'];
                    $params['port'] = $cfg['port'];
                }
                if ( '' !== $cfg['password'] ) {
                    $params['password'] = $cfg['password'];
                    if ( '' !== $cfg['username'] ) {
                        $params['username'] = $cfg['username'];
                    }
                }
                $p = new \Predis\Client( $params );
                $p->connect();
                $p->ping();
                $info = $p->info();
                $out['client']    = 'predis';
                $out['connected'] = true;
                if ( isset( $info['Server']['redis_version'] ) ) {
                    $out['redis_version'] = (string) $info['Server']['redis_version'];
                } elseif ( isset( $info['redis_version'] ) ) {
                    $out['redis_version'] = (string) $info['redis_version'];
                }
                if ( isset( $info['Memory']['used_memory'] ) ) {
                    $out['memory_used'] = (int) $info['Memory']['used_memory'];
                }
                $p->disconnect();
                return $out;
            }
        } catch ( \Throwable $e ) {
            $out['client'] = 'predis';
            $out['error']  = $e->getMessage();
            return $out;
        }

        $out['error'] = __( 'No Redis PHP extension available to probe with.', 'easy-optimizer' );
        return $out;
    }

    /**
     * Values from a host-provisioned WP_REDIS_CONFIG array (Object Cache Pro
     * format — Cloudways and several managed hosts write it into wp-config.php).
     * Only connection keys are mapped; OCP-only behaviour keys (token,
     * split_alloptions, async_flush, compression, serializer…) are ignored.
     * Mirrors load_wp_redis_config() in assets/object-cache.php — keep in sync.
     *
     * @return array<string,mixed> Mapped subset, possibly empty.
     */
    public static function wp_redis_config_values() {
        if ( ! defined( 'WP_REDIS_CONFIG' ) || ! is_array( WP_REDIS_CONFIG ) ) {
            return array();
        }
        $c   = WP_REDIS_CONFIG;
        $out = array();
        foreach ( array( 'host', 'port', 'username', 'password', 'database', 'prefix', 'timeout', 'read_timeout', 'scheme', 'path' ) as $k ) {
            if ( isset( $c[ $k ] ) && '' !== $c[ $k ] ) {
                $out[ $k ] = $c[ $k ];
            }
        }
        return $out;
    }

    /**
     * Resolve effective connection params.
     * Priority per key: WP_REDIS_* constant > WP_REDIS_CONFIG array >
     * unsaved override (Test button) > saved setting > default.
     * Host values starting with "/" are treated as a unix socket path;
     * the TLS toggle (or a tls/rediss scheme) upgrades tcp to tls.
     */
    private static function resolve_params( array $override = array() ) {
        $wrc = self::wp_redis_config_values();

        $get = static function ( $const, $key, $setting, $default ) use ( $override, $wrc ) {
            if ( defined( $const ) ) {
                return constant( $const );
            }
            if ( isset( $wrc[ $key ] ) ) {
                return $wrc[ $key ];
            }
            if ( array_key_exists( $key, $override ) && '' !== $override[ $key ] && null !== $override[ $key ] ) {
                return $override[ $key ];
            }
            if ( '' === $setting ) {
                return $default;
            }
            return EasyOpt_Config::get( $setting, $default );
        };

        $cfg = array(
            'client'   => array_key_exists( 'client', $override ) ? (string) $override['client'] : (string) EasyOpt_Config::get( 'oc_client', 'auto' ),
            'host'     => trim( (string) $get( 'WP_REDIS_HOST', 'host', 'oc_host', '127.0.0.1' ) ),
            'port'     => (int) $get( 'WP_REDIS_PORT', 'port', 'oc_port', 6379 ),
            'timeout'  => (float) $get( 'WP_REDIS_TIMEOUT', 'timeout', '', 1.0 ),
            'username' => trim( (string) $get( 'WP_REDIS_USERNAME', 'username', 'oc_username', '' ) ),
            'password' => $get( 'WP_REDIS_PASSWORD', 'password', 'oc_password', '' ),
            'database' => (int) $get( 'WP_REDIS_DATABASE', 'database', 'oc_database', 0 ),
            'scheme'   => (string) $get( 'WP_REDIS_SCHEME', 'scheme', '', 'tcp' ),
            'path'     => (string) $get( 'WP_REDIS_PATH', 'path', '', '' ),
            'prefix'   => trim( (string) $get( 'WP_REDIS_PREFIX', 'prefix', 'oc_prefix', '' ) ),
        );
        // xCloud / Redis-ACL format: WP_REDIS_PASSWORD = [ username, password ].
        // The old blind (string) cast turned it into the literal "Array" and
        // auth failed. Split it; an explicit WP_REDIS_USERNAME still wins.
        if ( is_array( $cfg['password'] ) ) {
            if ( '' === $cfg['username'] && isset( $cfg['password'][0] ) ) {
                $cfg['username'] = trim( (string) $cfg['password'][0] );
            }
            $cfg['password'] = isset( $cfg['password'][1] ) ? (string) $cfg['password'][1] : '';
        } else {
            $cfg['password'] = (string) $cfg['password'];
        }

        // TLS: constants/config may say tls|rediss; otherwise honour the toggle.
        $tls = array_key_exists( 'tls', $override )
            ? (int) $override['tls']
            : (int) EasyOpt_Config::get( 'oc_tls', 0 );
        if ( in_array( $cfg['scheme'], array( 'tls', 'rediss' ), true ) || ( 'tcp' === $cfg['scheme'] && 1 === $tls ) ) {
            $cfg['scheme'] = 'tls';
        }

        // Unix socket: an absolute path in the host field, a tls://-less
        // "unix:" style, or WP_REDIS_PATH/scheme=unix all mean socket mode.
        if ( '' !== $cfg['host'] && '/' === $cfg['host'][0] ) {
            $cfg['scheme'] = 'unix';
            $cfg['path']   = $cfg['host'];
        } elseif ( 'unix' === $cfg['scheme'] && '' === $cfg['path'] && '' !== $cfg['host'] && '/' === $cfg['host'][0] ) {
            $cfg['path'] = $cfg['host'];
        }

        return $cfg;
    }

    /**
     * Which connection keys are locked by wp-config.php (constant or
     * WP_REDIS_CONFIG)? The UI uses this to mark fields as overridden.
     *
     * @return array<string,string> key => 'constant'|'wp_redis_config'
     */
    public static function config_sources() {
        $wrc = self::wp_redis_config_values();
        $map = array(
            'host'     => 'WP_REDIS_HOST',
            'port'     => 'WP_REDIS_PORT',
            'username' => 'WP_REDIS_USERNAME',
            'password' => 'WP_REDIS_PASSWORD',
            'database' => 'WP_REDIS_DATABASE',
            'prefix'   => 'WP_REDIS_PREFIX',
        );
        $out = array();
        foreach ( $map as $key => $const ) {
            if ( defined( $const ) ) {
                $out[ $key ] = 'constant';
            } elseif ( isset( $wrc[ $key ] ) ) {
                $out[ $key ] = 'wp_redis_config';
            }
        }
        return $out;
    }

    /**
     * Classify a raw Redis error into a stable code + actionable hint.
     * Codes: auth_required | auth_failed | auth_noperm | no_server |
     * bad_database | loading | ext_missing | '' (unknown).
     *
     * @param  string $error Raw exception message (never echoed with secrets —
     *                       Redis auth errors do not contain the password).
     * @return array{code:string,hint:string}
     */
    public static function classify_error( $error ) {
        $e = strtolower( (string) $error );
        if ( '' === $e ) {
            return array( 'code' => '', 'hint' => '' );
        }
        if ( false !== strpos( $e, 'noauth' ) ) {
            return array(
                'code' => 'auth_required',
                'hint' => __( 'The Redis server requires authentication. Enter the password (and username, if your host uses Redis ACLs) in the connection settings, or define WP_REDIS_PASSWORD in wp-config.php.', 'easy-optimizer' ),
            );
        }
        if ( false !== strpos( $e, 'wrongpass' ) || false !== strpos( $e, 'invalid username-password' ) || false !== strpos( $e, 'invalid password' ) ) {
            return array(
                'code' => 'auth_failed',
                'hint' => __( 'The username or password is incorrect. Check the credentials from your hosting panel — on Redis 6+ ACL setups (e.g. Cloudways) both a username and a password are required.', 'easy-optimizer' ),
            );
        }
        if ( false !== strpos( $e, 'noperm' ) ) {
            return array(
                'code' => 'auth_noperm',
                'hint' => __( 'Authenticated, but this Redis user lacks permission for the commands or database Easy Optimizer needs. Check the user\'s ACL rules or use a different database number.', 'easy-optimizer' ),
            );
        }
        if ( false !== strpos( $e, 'out of range' ) || false !== strpos( $e, 'invalid db index' ) ) {
            return array(
                'code' => 'bad_database',
                'hint' => __( 'The database number is not valid on this Redis server. Use the database your host assigned (often 0), or raise the server\'s "databases" limit.', 'easy-optimizer' ),
            );
        }
        if ( false !== strpos( $e, 'loading' ) ) {
            return array(
                'code' => 'loading',
                'hint' => __( 'Redis is still starting up and loading its dataset. Wait a moment and refresh.', 'easy-optimizer' ),
            );
        }
        if ( false !== strpos( $e, 'no redis php extension' ) || false !== strpos( $e, 'no redis client' ) ) {
            return array(
                'code' => 'ext_missing',
                'hint' => __( 'No Redis client is available. Select the bundled Predis client, or ask your host to enable the PhpRedis extension.', 'easy-optimizer' ),
            );
        }
        if ( false !== strpos( $e, 'connection refused' )
            || false !== strpos( $e, 'no such file' )
            || false !== strpos( $e, 'failed to connect' )
            || false !== strpos( $e, 'connection timed out' )
            || false !== strpos( $e, 'timed out' )
            || false !== strpos( $e, 'went away' )
            || false !== strpos( $e, 'read error on connection' )
            // Predis phrases a dropped/refused socket as "error while reading
            // line from the server" — without this it fell through to the
            // unknown bucket and the UI showed a generic fallback instead of
            // the specific "no Redis server" guidance. Cover the sibling
            // socket-drop phrasings too.
            || false !== strpos( $e, 'error while reading line' )
            || false !== strpos( $e, 'error while reading bytes' )
            || false !== strpos( $e, 'error while writing' )
            || false !== strpos( $e, 'broken pipe' )
            || false !== strpos( $e, 'connection reset' )
            || false !== strpos( $e, 'connection lost' )
            || false !== strpos( $e, 'connection closed' )
            || false !== strpos( $e, 'name or service not known' )
            || false !== strpos( $e, 'getaddrinfo' ) ) {
            return array(
                'code' => 'no_server',
                'hint' => __( 'No Redis server answered at this host/port. The PHP extension alone is not enough — an actual Redis server must be running. Check the host, port and TLS setting, or switch Object Cache off if your hosting plan has no Redis.', 'easy-optimizer' ),
            );
        }
        return array( 'code' => '', 'hint' => '' );
    }

    /**
     * Test a connection with (optionally unsaved) credentials. Returns a
     * structured result the REST layer hands straight to the UI.
     */
    public static function test_connection( array $override = array() ) {
        $probe = self::probe( $override );
        if ( $probe['connected'] ) {
            return array(
                'success'       => true,
                'message'       => sprintf(
                    /* translators: 1: client, 2: redis version */
                    __( 'Connected via %1$s (Redis %2$s).', 'easy-optimizer' ),
                    $probe['client'] ?: 'Redis',
                    $probe['redis_version'] ?: '—'
                ),
                'redis_version' => $probe['redis_version'],
                'memory_used'   => $probe['memory_used'],
            );
        }
        $classified = self::classify_error( $probe['error'] );
        $message    = '' !== $classified['hint']
            ? $classified['hint']
            : ( $probe['error'] ? $probe['error'] : __( 'Could not connect to Redis.', 'easy-optimizer' ) );
        return array(
            'success'    => false,
            'message'    => $message,
            'error'      => $probe['error'],
            'error_code' => $classified['code'],
        );
    }

    /** Flush via the standard WP API — works for whatever provider is active. */
    public static function flush() {
        if ( function_exists( 'wp_cache_flush' ) ) {
            return (bool) wp_cache_flush();
        }
        return false;
    }

    /* ── Admin notice ────────────────────────────────────────────────── */

    public static function maybe_admin_notice() {
        if ( ! current_user_can( 'manage_options' ) || ! self::is_enabled() ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || false === strpos( (string) $screen->id, 'easy-optimizer' ) ) {
            return;
        }
        if ( self::foreign_dropin_present() ) {
            // Keeping another plugin's drop-in is a legitimate permanent
            // choice — dismissal persists, keyed to THAT drop-in's name, and
            // returns only if a different foreign drop-in appears.
            $foreign = self::foreign_dropin_name();
            $stamp   = md5( (string) $foreign );
            if ( class_exists( 'EasyOpt_Notices' ) && EasyOpt_Notices::dismissed( 'oc_foreign', $stamp ) ) {
                return;
            }
            printf(
                '<div class="notice notice-warning"><p>%s%s</p></div>',
                esc_html( sprintf(
                    /* translators: %s: other plugin name */
                    __( 'Object Cache is on, but another drop-in (%s) is installed. Easy Optimizer is deferring to it and will not overwrite it.', 'easy-optimizer' ),
                    $foreign
                ) ),
                class_exists( 'EasyOpt_Notices' ) ? EasyOpt_Notices::link( 'oc_foreign', $stamp ) : '' // phpcs:ignore WordPress.Security.EscapeOutput
            );
        } elseif ( ! self::dropin_is_ours() ) {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html__( 'Object Cache is enabled but its drop-in is not installed. Re-save the Object Cache settings, or check that wp-content is writable.', 'easy-optimizer' )
            );
        }
    }
}
