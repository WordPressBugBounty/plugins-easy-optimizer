<?php
/**
 * Easy Optimizer — object-cache.php drop-in (persistent Redis object cache).
 * Supports Relay, PhpRedis and the bundled Predis client.
 *
 * Intentionally NO standard plugin header here. This template lives at
 * easy-optimizer/assets/object-cache.php, and WordPress's get_plugins() scan
 * during plugin upload/update reads one level into every subfolder. A plugin
 * header here would make WordPress treat the drop-in as a separate installable
 * plugin and mis-target the post-update "Activate" link. A plain comment keeps
 * it invisible to that scan while remaining a valid drop-in.
 *
 * EASYOPT_OBJECT_CACHE_DROPIN — identity marker, do not remove. The plugin
 * only ever overwrites or deletes a drop-in that carries this marker, so a
 * competing object cache (Redis Object Cache, Object Cache Pro, …) is never
 * touched.
 *
 * SELF-CONTAINED BY DESIGN: this file requires NOTHING from the Easy
 * Optimizer plugin folder. Deactivating or deleting the plugin therefore
 * can never fatal here (unlike drop-ins that `require` a plugin class).
 * Connection settings are read from WP_REDIS_* constants or, when present,
 * an Easy Optimizer config file under wp-content/cache/easyopt/. If no Redis
 * backend can be reached the cache silently degrades to a request-local
 * (non-persistent) cache — the site keeps working.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ───────────────────────── WordPress API surface ─────────────────────────
 * Thin delegators to the EasyOpt_Object_Cache instance. Every function below
 * is part of the documented WP object-cache drop-in contract.
 * ------------------------------------------------------------------------ */

function wp_cache_init() {
    global $wp_object_cache;
    if ( ! ( $wp_object_cache instanceof EasyOpt_Object_Cache ) ) {
        $wp_object_cache = new EasyOpt_Object_Cache();
    }
}

function wp_cache_add( $key, $data, $group = 'default', $expire = 0 ) {
    global $wp_object_cache;
    return $wp_object_cache->add( $key, $data, $group, (int) $expire );
}

function wp_cache_add_multiple( array $data, $group = 'default', $expire = 0 ) {
    global $wp_object_cache;
    return $wp_object_cache->add_multiple( $data, $group, (int) $expire );
}

function wp_cache_replace( $key, $data, $group = 'default', $expire = 0 ) {
    global $wp_object_cache;
    return $wp_object_cache->replace( $key, $data, $group, (int) $expire );
}

function wp_cache_set( $key, $data, $group = 'default', $expire = 0 ) {
    global $wp_object_cache;
    return $wp_object_cache->set( $key, $data, $group, (int) $expire );
}

function wp_cache_set_multiple( array $data, $group = 'default', $expire = 0 ) {
    global $wp_object_cache;
    return $wp_object_cache->set_multiple( $data, $group, (int) $expire );
}

function wp_cache_get( $key, $group = 'default', $force = false, &$found = null ) {
    global $wp_object_cache;
    return $wp_object_cache->get( $key, $group, $force, $found );
}

function wp_cache_get_multiple( $keys, $group = 'default', $force = false ) {
    global $wp_object_cache;
    return $wp_object_cache->get_multiple( $keys, $group, $force );
}

function wp_cache_delete( $key, $group = 'default' ) {
    global $wp_object_cache;
    return $wp_object_cache->delete( $key, $group );
}

function wp_cache_delete_multiple( array $keys, $group = 'default' ) {
    global $wp_object_cache;
    return $wp_object_cache->delete_multiple( $keys, $group );
}

function wp_cache_incr( $key, $offset = 1, $group = 'default' ) {
    global $wp_object_cache;
    return $wp_object_cache->incr( $key, (int) $offset, $group );
}

function wp_cache_decr( $key, $offset = 1, $group = 'default' ) {
    global $wp_object_cache;
    return $wp_object_cache->decr( $key, (int) $offset, $group );
}

function wp_cache_flush() {
    global $wp_object_cache;
    return $wp_object_cache->flush();
}

function wp_cache_flush_runtime() {
    global $wp_object_cache;
    return $wp_object_cache->flush_runtime();
}

function wp_cache_flush_group( $group ) {
    global $wp_object_cache;
    return $wp_object_cache->flush_group( $group );
}

function wp_cache_supports( $feature ) {
    switch ( $feature ) {
        case 'add_multiple':
        case 'set_multiple':
        case 'get_multiple':
        case 'delete_multiple':
        case 'flush_runtime':
        case 'flush_group':
            return true;
        default:
            return false;
    }
}

function wp_cache_close() {
    return true;
}

function wp_cache_add_global_groups( $groups ) {
    global $wp_object_cache;
    $wp_object_cache->add_global_groups( (array) $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
    global $wp_object_cache;
    $wp_object_cache->add_non_persistent_groups( (array) $groups );
}

function wp_cache_switch_to_blog( $blog_id ) {
    global $wp_object_cache;
    return $wp_object_cache->switch_to_blog( (int) $blog_id );
}

/* ───────────────────────────── The engine ─────────────────────────────── */

class EasyOpt_Object_Cache {

    /** Request-local cache: [group][key] => value. Always consulted first. */
    private $cache = array();

    /** @var object|null Active client: \Relay\Relay | \Redis | \Predis\Client. */
    public $redis = null;

    /** @var bool True once a live connection has been verified. */
    public $redis_connected = false;

    /** @var string 'relay' | 'phpredis' | 'predis' | '' */
    public $client = '';

    /** @var string Last connection/runtime error — surfaced in diagnostics. */
    public $error = '';

    /** Hit/miss counters for the current request. */
    public $cache_hits   = 0;
    public $cache_misses = 0;

    /** Key namespace prefix (keeps tenants on a shared Redis separated). */
    public $key_prefix = 'easyopt_';

    /** Database index in use (for diagnostics). */
    public $database = 0;

    /** Multisite-aware prefixes. */
    private $global_prefix = '';
    private $blog_prefix   = '';

    /** Group bookkeeping (flipped maps for O(1) membership tests). */
    private $global_groups         = array();
    private $non_persistent_groups = array();

    /** True only on the Predis path, where we (de)serialize in PHP ourselves. */
    private $manual_serialize = false;

    /** Absolute path to the bundled Predis autoloader (from config file). */
    private $predis_autoload = '';

    public function __construct() {
        global $blog_id, $table_prefix;

        $is_ms = function_exists( 'is_multisite' ) && is_multisite();
        $this->global_prefix = trim( $is_ms ? '' : (string) ( $table_prefix ?? '' ), '_-:$' );
        $this->blog_prefix   = trim( $is_ms ? (string) $blog_id : (string) ( $table_prefix ?? '' ), '_-:$' );

        // Standard WordPress global (network-wide) groups.
        $this->global_groups = array_flip( array(
            'blog-details', 'blog-id-cache', 'blog-lookup', 'blog_meta',
            'global-posts', 'networks', 'network-queries', 'rss', 'sites',
            'site-details', 'site-lookup', 'site-options', 'site-queries',
            'site-transient', 'users', 'useremail', 'userlogins', 'usermeta',
            'user_meta', 'userslugs',
        ) );

        // Standard non-persistent (request-only) groups.
        $this->non_persistent_groups = array_flip( array(
            'comment', 'counts', 'plugins', 'theme_json',
        ) );

        $config = self::load_config();

        if ( isset( $config['predis_autoload'] ) ) {
            $this->predis_autoload = (string) $config['predis_autoload'];
        }
        if ( ! empty( $config['prefix'] ) ) {
            $this->key_prefix = rtrim( (string) $config['prefix'], '_' ) . '_';
        }
        $this->database = (int) $config['database'];

        $this->connect( $config );
    }

    /**
     * Resolve connection settings. Priority, per key:
     *   1. WP_REDIS_* constant (host-managed — the plugin never writes these)
     *   2. Easy Optimizer config file value (entered in the admin UI)
     *   3. Localhost default
     */
    /**
     * Connection values from a host-provisioned WP_REDIS_CONFIG array (Object
     * Cache Pro format — Cloudways and other managed hosts write it into
     * wp-config.php). Only connection keys are honoured; OCP behaviour keys
     * (token, split_alloptions, async_flush, compression, serializer…) are
     * ignored. Mirrors wp_redis_config_values() in the plugin manager class.
     */
    private static function load_wp_redis_config() {
        if ( ! defined( 'WP_REDIS_CONFIG' ) || ! is_array( WP_REDIS_CONFIG ) ) {
            return array();
        }
        $c   = WP_REDIS_CONFIG;
        $out = array();
        foreach ( array( 'host', 'port', 'username', 'password', 'database', 'prefix', 'timeout', 'scheme', 'path' ) as $k ) {
            if ( isset( $c[ $k ] ) && '' !== $c[ $k ] ) {
                $out[ $k ] = $c[ $k ];
            }
        }
        return $out;
    }

    private static function load_config() {
        $file_cfg = array();
        $cfg_file = WP_CONTENT_DIR . '/cache/easyopt/object-cache-config.php';
        if ( is_readable( $cfg_file ) ) {
            $maybe = include $cfg_file;
            if ( is_array( $maybe ) ) {
                $file_cfg = $maybe;
            }
        }
        $wrc = self::load_wp_redis_config();

        // Priority per key: WP_REDIS_* constant > WP_REDIS_CONFIG array >
        // Easy Optimizer config file (admin UI) > localhost default.
        $pick = static function ( $const, $key, $default ) use ( $file_cfg, $wrc ) {
            if ( defined( $const ) ) {
                return constant( $const );
            }
            if ( isset( $wrc[ $key ] ) ) {
                return $wrc[ $key ];
            }
            if ( isset( $file_cfg[ $key ] ) && '' !== $file_cfg[ $key ] && null !== $file_cfg[ $key ] ) {
                return $file_cfg[ $key ];
            }
            return $default;
        };

        $cfg = array(
            'client'          => isset( $file_cfg['client'] ) ? $file_cfg['client'] : 'auto',
            'host'            => trim( (string) $pick( 'WP_REDIS_HOST', 'host', '127.0.0.1' ) ),
            'port'            => (int) $pick( 'WP_REDIS_PORT', 'port', 6379 ),
            'timeout'         => (float) $pick( 'WP_REDIS_TIMEOUT', 'timeout', 1.0 ),
            'username'        => trim( (string) $pick( 'WP_REDIS_USERNAME', 'username', '' ) ),
            'password'        => $pick( 'WP_REDIS_PASSWORD', 'password', '' ),
            'database'        => (int) $pick( 'WP_REDIS_DATABASE', 'database', 0 ),
            'scheme'          => (string) $pick( 'WP_REDIS_SCHEME', 'scheme', 'tcp' ),
            'path'            => (string) $pick( 'WP_REDIS_PATH', 'path', '' ),
            'prefix'          => trim( (string) $pick( 'WP_REDIS_PREFIX', 'prefix', '' ) ),
            'predis_autoload' => isset( $file_cfg['predis_autoload'] ) ? (string) $file_cfg['predis_autoload'] : '',
        );

        // xCloud / Redis-ACL format: WP_REDIS_PASSWORD = [ username, password ].
        if ( is_array( $cfg['password'] ) ) {
            if ( '' === $cfg['username'] && isset( $cfg['password'][0] ) ) {
                $cfg['username'] = trim( (string) $cfg['password'][0] );
            }
            $cfg['password'] = isset( $cfg['password'][1] ) ? (string) $cfg['password'][1] : '';
        } else {
            $cfg['password'] = (string) $cfg['password'];
        }

        // Normalise scheme: rediss:// means TLS; an absolute path in the host
        // field means a unix socket.
        if ( 'rediss' === $cfg['scheme'] ) {
            $cfg['scheme'] = 'tls';
        }
        if ( '' !== $cfg['host'] && '/' === $cfg['host'][0] ) {
            $cfg['scheme'] = 'unix';
            $cfg['path']   = $cfg['host'];
        }

        return $cfg;
    }

    /**
     * Establish a connection using the best available client. In 'auto' mode
     * we prefer Relay → PhpRedis → Predis; an explicit choice is honoured.
     * Any failure leaves redis_connected = false (request-local fallback).
     */
    private function connect( array $cfg ) {
        $want  = isset( $cfg['client'] ) ? $cfg['client'] : 'auto';
        $order = array( 'relay', 'phpredis', 'predis' );
        if ( in_array( $want, $order, true ) ) {
            $order = array( $want );
        }

        foreach ( $order as $candidate ) {
            try {
                if ( 'relay' === $candidate && class_exists( '\\Relay\\Relay' ) ) {
                    $this->connect_phpredis_like( $cfg, true );
                    return;
                }
                if ( 'phpredis' === $candidate && class_exists( 'Redis' ) ) {
                    $this->connect_phpredis_like( $cfg, false );
                    return;
                }
                if ( 'predis' === $candidate && $this->load_predis() ) {
                    $this->connect_predis( $cfg );
                    return;
                }
            } catch ( \Throwable $e ) {
                $this->error           = $e->getMessage();
                $this->redis_connected = false;
                $this->redis           = null;
                $this->client          = '';
                if ( 1 === count( $order ) ) {
                    return; // explicit client requested — don't fall through
                }
            }
        }

        if ( '' === $this->error ) {
            $this->error = 'No Redis client available (Relay, PhpRedis or Predis).';
        }
    }

    /** Connect via PhpRedis or the API-compatible Relay extension. */
    private function connect_phpredis_like( array $cfg, $relay ) {
        $this->redis  = $relay ? new \Relay\Relay() : new \Redis();
        $this->client = $relay ? 'relay' : 'phpredis';

        $persistent_id = $cfg['scheme'] . ':' . $cfg['host'] . ':' . $cfg['port'] . ':' . $cfg['database'];

        if ( 'unix' === $cfg['scheme'] && '' !== $cfg['path'] ) {
            $this->redis->pconnect( $cfg['path'], 0, $cfg['timeout'], $persistent_id );
        } else {
            $conn_host = 'tls' === $cfg['scheme'] ? 'tls://' . $cfg['host'] : $cfg['host'];
            $this->redis->pconnect( $conn_host, $cfg['port'], $cfg['timeout'], $persistent_id );
        }

        if ( '' !== $cfg['password'] ) {
            $auth = '' !== $cfg['username'] ? array( $cfg['username'], $cfg['password'] ) : $cfg['password'];
            if ( false === $this->redis->auth( $auth ) ) {
                $le = method_exists( $this->redis, 'getLastError' ) ? trim( (string) $this->redis->getLastError(), " \0\t\r\n" ) : '';
                throw new \Exception( '' !== $le ? $le : 'WRONGPASS invalid username-password pair' );
            }
        }
        if ( 0 !== $cfg['database'] ) {
            // PhpRedis select() returns false (no exception) on an invalid
            // index. Failing loudly here keeps redis_connected = false —
            // otherwise every key would silently land in database 0.
            if ( false === $this->redis->select( $cfg['database'] ) ) {
                $le = method_exists( $this->redis, 'getLastError' ) ? trim( (string) $this->redis->getLastError(), " \0\t\r\n" ) : '';
                throw new \Exception( '' !== $le ? $le : 'ERR DB index is out of range' );
            }
        }

        // Native serializer — the extension (de)serializes values for us, so
        // every PHP type round-trips correctly. incr/decr are implemented as
        // read-modify-write in PHP to stay correct under a serializer.
        if ( $relay ) {
            $opt = defined( '\\Relay\\Relay::OPT_SERIALIZER' ) ? \Relay\Relay::OPT_SERIALIZER : null;
            if ( null !== $opt ) {
                $ser = ( defined( '\\Relay\\Relay::SERIALIZER_IGBINARY' ) && extension_loaded( 'igbinary' ) )
                    ? \Relay\Relay::SERIALIZER_IGBINARY
                    : ( defined( '\\Relay\\Relay::SERIALIZER_PHP' ) ? \Relay\Relay::SERIALIZER_PHP : null );
                if ( null !== $ser ) {
                    $this->redis->setOption( $opt, $ser );
                }
            }
        } else {
            $ser = ( defined( 'Redis::SERIALIZER_IGBINARY' ) && extension_loaded( 'igbinary' ) )
                ? \Redis::SERIALIZER_IGBINARY
                : \Redis::SERIALIZER_PHP;
            $this->redis->setOption( \Redis::OPT_SERIALIZER, $ser );
            if ( defined( 'Redis::OPT_SCAN' ) && defined( 'Redis::SCAN_RETRY' ) ) {
                @$this->redis->setOption( \Redis::OPT_SCAN, \Redis::SCAN_RETRY );
            }
        }

        $this->redis->ping(); // verifies the link is genuinely live
        $this->manual_serialize = false;
        $this->redis_connected  = true;
    }

    /** Lazily make Predis available via its bundled (non-Composer) autoloader. */
    private function load_predis() {
        if ( class_exists( '\\Predis\\Client' ) ) {
            return true;
        }
        $loader = $this->predis_autoload;
        if ( '' !== $loader && is_readable( $loader ) ) {
            require_once $loader;
            if ( class_exists( '\\Predis\\Autoloader' ) ) {
                \Predis\Autoloader::register();
            }
        }
        return class_exists( '\\Predis\\Client' );
    }

    /** Connect via the pure-PHP Predis client (fallback when no extension). */
    private function connect_predis( array $cfg ) {
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

        $this->redis  = new \Predis\Client( $params );
        $this->client = 'predis';
        $this->redis->connect();
        $this->redis->ping();

        $this->manual_serialize = true; // Predis stores raw strings
        $this->redis_connected  = true;
    }

    /* ── Key + group helpers ─────────────────────────────────────────── */

    public function build_key( $key, $group ) {
        $group  = '' === $group ? 'default' : $group;
        $prefix = isset( $this->global_groups[ $group ] ) ? $this->global_prefix : $this->blog_prefix;
        return $this->key_prefix . $prefix . ':' . $group . ':' . $key;
    }

    private function is_non_persistent( $group ) {
        return isset( $this->non_persistent_groups[ $group ] );
    }

    public function add_global_groups( array $groups ) {
        foreach ( $groups as $g ) {
            $this->global_groups[ $g ] = true;
        }
    }

    public function add_non_persistent_groups( array $groups ) {
        foreach ( $groups as $g ) {
            $this->non_persistent_groups[ $g ] = true;
        }
    }

    public function switch_to_blog( $blog_id ) {
        if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
            return false;
        }
        $this->blog_prefix = (string) $blog_id;
        return true;
    }

    /* ── Read / write ────────────────────────────────────────────────── */

    public function get( $key, $group = 'default', $force = false, &$found = null ) {
        $group = $group ?: 'default';

        if ( ! $force && isset( $this->cache[ $group ] ) && array_key_exists( $key, $this->cache[ $group ] ) ) {
            $found = true;
            $this->cache_hits++;
            return $this->maybe_clone( $this->cache[ $group ][ $key ] );
        }

        if ( $this->is_non_persistent( $group ) || ! $this->redis_connected ) {
            $found = false;
            $this->cache_misses++;
            return false;
        }

        try {
            $value = $this->redis->get( $this->build_key( $key, $group ) );
        } catch ( \Throwable $e ) {
            $found = false;
            $this->cache_misses++;
            return false;
        }

        if ( false === $value || null === $value ) {
            $found = false;
            $this->cache_misses++;
            return false;
        }

        $value = $this->unserialize( $value );
        $this->cache[ $group ][ $key ] = $value;
        $found = true;
        $this->cache_hits++;
        return $this->maybe_clone( $value );
    }

    public function set( $key, $data, $group = 'default', $expire = 0 ) {
        $group = $group ?: 'default';
        if ( is_object( $data ) ) {
            $data = clone $data;
        }
        $this->cache[ $group ][ $key ] = $data;

        if ( $this->is_non_persistent( $group ) || ! $this->redis_connected ) {
            return true;
        }
        try {
            return (bool) $this->write( $this->build_key( $key, $group ), $data, (int) $expire );
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    private function write( $full_key, $data, $expire ) {
        $payload = $this->serialize( $data );
        if ( $expire > 0 ) {
            return $this->redis->setex( $full_key, $expire, $payload );
        }
        return $this->redis->set( $full_key, $payload );
    }

    public function add( $key, $data, $group = 'default', $expire = 0 ) {
        if ( function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition() ) {
            return false;
        }
        $group = $group ?: 'default';

        if ( isset( $this->cache[ $group ] ) && array_key_exists( $key, $this->cache[ $group ] ) ) {
            return false;
        }
        if ( ! $this->is_non_persistent( $group ) && $this->redis_connected ) {
            // (2.5.4 / perf #38) Atomic SET NX [EX] — one round-trip instead
            // of the old exists() + set() pair, and race-free between two
            // concurrent adders (only one wins, as core's semantics intend).
            if ( is_object( $data ) ) {
                $data = clone $data;
            }
            try {
                $won = $this->set_nx( $this->build_key( $key, $group ), $data, (int) $expire );
            } catch ( \Throwable $e ) {
                return false;
            }
            if ( ! $won ) {
                return false;
            }
            $this->cache[ $group ][ $key ] = $data;
            return true;
        }
        return $this->set( $key, $data, $group, $expire );
    }

    /**
     * (2.5.4 / perf #38) SET key value NX [EX seconds] for every client.
     * PhpRedis/Relay apply their active native serializer to the value when
     * the options form of set() is used, so serialize() (a passthrough in
     * native mode, manual for Predis) keeps payloads byte-identical to the
     * plain write() path.
     *
     * @return bool True when the key was created (didn't exist before).
     */
    private function set_nx( $full_key, $data, $expire ) {
        $payload = $this->serialize( $data );
        if ( 'predis' === $this->client ) {
            if ( $expire > 0 ) {
                $res = $this->redis->set( $full_key, $payload, 'EX', $expire, 'NX' );
                return null !== $res && false !== $res;
            }
            return (bool) $this->redis->setnx( $full_key, $payload );
        }
        $opts = $expire > 0 ? array( 'nx', 'ex' => $expire ) : array( 'nx' );
        return (bool) $this->redis->set( $full_key, $payload, $opts );
    }

    public function replace( $key, $data, $group = 'default', $expire = 0 ) {
        $group  = $group ?: 'default';
        $exists = isset( $this->cache[ $group ] ) && array_key_exists( $key, $this->cache[ $group ] );
        if ( ! $exists && ! $this->is_non_persistent( $group ) && $this->redis_connected ) {
            try {
                $exists = (bool) $this->redis->exists( $this->build_key( $key, $group ) );
            } catch ( \Throwable $e ) {
                $exists = false;
            }
        }
        if ( ! $exists ) {
            return false;
        }
        return $this->set( $key, $data, $group, $expire );
    }

    public function delete( $key, $group = 'default' ) {
        $group = $group ?: 'default';
        unset( $this->cache[ $group ][ $key ] );

        if ( $this->is_non_persistent( $group ) || ! $this->redis_connected ) {
            return true;
        }
        try {
            $this->redis->del( $this->build_key( $key, $group ) );
            return true;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    public function incr( $key, $offset = 1, $group = 'default' ) {
        return $this->incr_decr( $key, (int) $offset, $group );
    }

    public function decr( $key, $offset = 1, $group = 'default' ) {
        return $this->incr_decr( $key, -(int) $offset, $group );
    }

    /**
     * Read-modify-write integer math. Implemented in PHP (not Redis INCRBY)
     * so it stays correct while a native serializer is active. Mirrors core's
     * non-creating semantics: a missing key returns false.
     */
    private function incr_decr( $key, $offset, $group ) {
        $group   = $group ?: 'default';
        $current = $this->get( $key, $group );
        if ( false === $current ) {
            return false;
        }
        $value = (int) $current + $offset;
        if ( $value < 0 ) {
            $value = 0;
        }
        $this->set( $key, $value, $group );
        return $value;
    }

    /* ── Multiple variants ───────────────────────────────────────────── */

    public function get_multiple( $keys, $group = 'default', $force = false ) {
        $group = $group ?: 'default';
        $keys  = (array) $keys;
        $out   = array();
        $need  = array();

        // (2.5.4 / perf #10) Serve what we can from the runtime cache, then
        // fetch every remaining key in ONE MGET round-trip instead of one
        // GET per key. Core primes options/posts/meta through this method
        // with dozens of keys — per-key round-trips were the single biggest
        // avoidable Redis cost. Semantics identical to the per-key loop:
        // misses stay false, hits land in the runtime cache, input order is
        // preserved in the returned array.
        foreach ( $keys as $k ) {
            if ( ! $force && isset( $this->cache[ $group ] ) && array_key_exists( $k, $this->cache[ $group ] ) ) {
                $out[ $k ] = $this->maybe_clone( $this->cache[ $group ][ $k ] );
                $this->cache_hits++;
            } else {
                $out[ $k ] = false; // placeholder — may be filled below
                $need[]    = $k;
            }
        }

        if ( empty( $need ) ) {
            return $out;
        }
        if ( $this->is_non_persistent( $group ) || ! $this->redis_connected
            || ! method_exists( $this->redis, 'mget' ) ) {
            // Fallback: original per-key behaviour (also covers exotic clients).
            foreach ( $need as $k ) {
                $out[ $k ] = $this->get( $k, $group, $force );
            }
            return $out;
        }

        $full = array();
        foreach ( $need as $k ) {
            $full[] = $this->build_key( $k, $group );
        }
        try {
            $vals = $this->redis->mget( $full );
        } catch ( \Throwable $e ) {
            $vals = false;
        }
        if ( ! is_array( $vals ) ) {
            // Batch call failed — degrade to per-key gets (original path).
            foreach ( $need as $k ) {
                $out[ $k ] = $this->get( $k, $group, $force );
            }
            return $out;
        }

        $i = 0;
        foreach ( $need as $k ) {
            $v = array_key_exists( $i, $vals ) ? $vals[ $i ] : false;
            $i++;
            if ( false === $v || null === $v ) {
                $this->cache_misses++;
                continue;
            }
            $v = $this->unserialize( $v );
            $this->cache[ $group ][ $k ] = $v;
            $out[ $k ] = $this->maybe_clone( $v );
            $this->cache_hits++;
        }
        return $out;
    }

    public function set_multiple( array $data, $group = 'default', $expire = 0 ) {
        $group = $group ?: 'default';

        // (2.5.4 / perf #39) PhpRedis/Relay: one pipelined round-trip for the
        // whole batch. Predis and non-persistent/disconnected states keep the
        // original per-key loop (identical semantics, simpler failure modes).
        if ( ! $this->is_non_persistent( $group ) && $this->redis_connected
            && ( 'phpredis' === $this->client || 'relay' === $this->client )
            && method_exists( $this->redis, 'multi' ) && count( $data ) > 1 ) {
            try {
                $pipe_mode = ( 'relay' === $this->client && defined( '\\Relay\\Relay::PIPELINE' ) )
                    ? \Relay\Relay::PIPELINE
                    : ( defined( 'Redis::PIPELINE' ) ? \Redis::PIPELINE : null );
                if ( null !== $pipe_mode ) {
                    $pipe   = $this->redis->multi( $pipe_mode );
                    $expire = (int) $expire;
                    foreach ( $data as $k => $v ) {
                        if ( is_object( $v ) ) {
                            $v = clone $v;
                            $data[ $k ] = $v;
                        }
                        $full = $this->build_key( $k, $group );
                        if ( $expire > 0 ) {
                            $pipe->setex( $full, $expire, $this->serialize( $v ) );
                        } else {
                            $pipe->set( $full, $this->serialize( $v ) );
                        }
                    }
                    $results = $pipe->exec();
                    $out     = array();
                    $i       = 0;
                    foreach ( $data as $k => $v ) {
                        $this->cache[ $group ][ $k ] = $v;
                        $out[ $k ] = is_array( $results ) && array_key_exists( $i, $results )
                            ? (bool) $results[ $i ]
                            : true;
                        $i++;
                    }
                    return $out;
                }
            } catch ( \Throwable $e ) {
                // Fall through to the per-key loop below.
            }
        }

        $out = array();
        foreach ( $data as $k => $v ) {
            $out[ $k ] = $this->set( $k, $v, $group, $expire );
        }
        return $out;
    }

    public function add_multiple( array $data, $group = 'default', $expire = 0 ) {
        $out = array();
        foreach ( $data as $k => $v ) {
            $out[ $k ] = $this->add( $k, $v, $group, $expire );
        }
        return $out;
    }

    public function delete_multiple( array $keys, $group = 'default' ) {
        $group = $group ?: 'default';

        // (2.5.4 / perf #39) One DEL with the whole key list instead of one
        // round-trip per key. Runtime cache is cleared either way; per-key
        // return stays true/true like the loop (DEL can't partially fail).
        if ( ! $this->is_non_persistent( $group ) && $this->redis_connected && count( $keys ) > 1 ) {
            $full = array();
            foreach ( $keys as $k ) {
                unset( $this->cache[ $group ][ $k ] );
                $full[] = $this->build_key( $k, $group );
            }
            try {
                $this->redis->del( $full );
                $ok = true;
            } catch ( \Throwable $e ) {
                $ok = false;
            }
            $out = array();
            foreach ( $keys as $k ) {
                $out[ $k ] = $ok;
            }
            return $out;
        }

        $out = array();
        foreach ( $keys as $k ) {
            $out[ $k ] = $this->delete( $k, $group );
        }
        return $out;
    }

    /* ── Flush (prefix-scoped — NEVER FLUSHDB / FLUSHALL) ─────────────── */

    public function flush() {
        $this->cache = array();
        if ( ! $this->redis_connected ) {
            return true;
        }
        try {
            $this->scan_unlink( $this->key_prefix . '*' );
            return true;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    public function flush_group( $group ) {
        unset( $this->cache[ $group ] );
        if ( ! $this->redis_connected ) {
            return true;
        }
        try {
            $this->scan_unlink( $this->key_prefix . '*:' . $group . ':*' );
            return true;
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    public function flush_runtime() {
        $this->cache = array();
        return true;
    }

    /**
     * Delete every key matching $pattern via cursor SCAN + UNLINK. Only keys
     * under our own prefix are ever matched, so other apps sharing the same
     * Redis database are untouched. We never issue FLUSHDB/FLUSHALL.
     */
    private function scan_unlink( $pattern ) {
        if ( 'predis' === $this->client ) {
            $cursor = '0';
            do {
                $res    = $this->redis->scan( $cursor, 'MATCH', $pattern, 'COUNT', 500 );
                $cursor = $res[0];
                $keys   = $res[1];
                if ( ! empty( $keys ) ) {
                    $this->redis->del( $keys );
                }
            } while ( '0' !== (string) $cursor );
            return;
        }

        // PhpRedis / Relay.
        $iterator = null;
        do {
            $keys = $this->redis->scan( $iterator, $pattern, 500 );
            if ( false === $keys ) {
                break;
            }
            if ( ! empty( $keys ) ) {
                if ( method_exists( $this->redis, 'unlink' ) ) {
                    $this->redis->unlink( $keys );
                } else {
                    $this->redis->del( $keys );
                }
            }
        } while ( (int) $iterator !== 0 );
    }

    /* ── (De)serialization helpers ───────────────────────────────────── */

    private function serialize( $value ) {
        if ( ! $this->manual_serialize ) {
            return $value; // native serializer handles it
        }
        return function_exists( 'igbinary_serialize' ) ? igbinary_serialize( $value ) : serialize( $value );
    }

    private function unserialize( $value ) {
        if ( ! $this->manual_serialize ) {
            return $value;
        }
        if ( ! is_string( $value ) ) {
            return $value;
        }
        $un = function_exists( 'igbinary_unserialize' ) ? @igbinary_unserialize( $value ) : @unserialize( $value );
        // @unserialize returns false on failure AND for a serialized `false`.
        if ( false === $un && 'b:0;' !== $value ) {
            return $value;
        }
        return $un;
    }

    private function maybe_clone( $value ) {
        return is_object( $value ) ? clone $value : $value;
    }

    /* ── Diagnostics (best-effort; used by the admin panel) ──────────── */

    public function info() {
        if ( ! $this->redis_connected ) {
            return array();
        }
        try {
            $info = $this->redis->info();
            return is_array( $info ) ? $info : array();
        } catch ( \Throwable $e ) {
            return array();
        }
    }
}
