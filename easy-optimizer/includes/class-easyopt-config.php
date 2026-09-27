<?php
/**
 * Settings storage layer — single-row architecture.
 *
 * All settings live in one wp_options row (easyopt_settings).
 * A pre_option shim makes get_option('easyopt_X') work transparently.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Config {

    /** Prefix used by callers writing `EasyOpt_Config::get('cache')`. */
    const PREFIX = 'easyopt_';

    /** The single wp_options row that holds every user setting. */
    const STORAGE_OPTION = 'easyopt_settings';

    /**
     * (2.6.1) Credential store, deliberately separate from STORAGE_OPTION and
     * NEVER autoloaded.
     *
     * These four used to live in easyopt_settings, which is autoloaded and is
     * returned wholesale by get_all(). That meant the imgproxy HMAC key and
     * salt, the free-tier bearer token and the licence key were:
     *
     *   • loaded into memory on EVERY request, including every front-end page
     *     render, and mirrored into alloptions in Redis/Memcached;
     *   • serialised into the settings payload sent to the admin browser; and
     *   • included in the Settings Export JSON that users attach to support
     *     tickets.
     *
     * None of that is necessary — only server-side signing reads them.
     *
     * NOTE what this does and does not do. The keys stay declared in the
     * settings registry, so get_all() still RETURNS them — as their default,
     * an empty string, because raw_settings() no longer holds a value. They
     * are not "excluded"; they simply resolve empty.
     *
     * That distinction matters: an empty string is a real value to anything
     * downstream, and put_secrets() treats empty as DELETE (which disconnect()
     * relies on). So the four keys must also be listed in
     * EasyOpt_REST_Dashboard::secret_setting_keys() and in SECRET_KEYS in
     * react-src/app.tsx, which is what actually keeps them out of the export
     * and refuses them on import. Without that, exporting and re-importing a
     * settings file wiped the connection and the licence key.
     */
    const SECRETS_OPTION = 'easyopt_cloud_secrets';

    /** Full option names held in SECRETS_OPTION rather than STORAGE_OPTION. */
    public static function secret_keys() {
        return array(
            'easyopt_cloud_key',
            'easyopt_cloud_salt',
            'easyopt_cloud_token',
            'easyopt_cloud_license_key',
        );
    }

    public static function is_secret_key( $full_option_name ) {
        return in_array( (string) $full_option_name, self::secret_keys(), true );
    }

    /**
     * Read the credential store, migrating out of STORAGE_OPTION on first use.
     *
     * The migration is lazy and idempotent: an install that upgrades keeps its
     * connection without reconnecting, and the values are removed from the
     * autoloaded blob as they move.
     *
     * @return array<string,string>
     */
    private static function secrets() {
        static $memo = null;
        if ( null !== self::$secrets_override ) {
            return self::$secrets_override;
        }
        if ( null !== $memo ) {
            return $memo;
        }

        $store = get_option( self::SECRETS_OPTION, null );
        if ( is_array( $store ) ) {
            $memo = $store;
            return $memo;
        }

        // First run after upgrade — lift whatever is in the old blob.
        $legacy  = self::raw_settings();
        $moved   = array();
        $residue = $legacy;
        foreach ( self::secret_keys() as $k ) {
            if ( isset( $legacy[ $k ] ) && '' !== (string) $legacy[ $k ] ) {
                $moved[ $k ] = (string) $legacy[ $k ];
            }
            unset( $residue[ $k ] );
        }

        add_option( self::SECRETS_OPTION, $moved, '', false );
        if ( $residue !== $legacy ) {
            update_option( self::STORAGE_OPTION, $residue );
        }

        $memo = $moved;
        return $memo;
    }

    /** Write the credential store. Never autoloaded. */
    private static function put_secrets( array $values ) {
        $merged = array_replace( self::secrets(), $values );
        foreach ( $merged as $k => $v ) {
            if ( '' === (string) $v ) {
                unset( $merged[ $k ] );
            }
        }
        if ( false === get_option( self::SECRETS_OPTION, false ) ) {
            add_option( self::SECRETS_OPTION, $merged, '', false );
        } else {
            update_option( self::SECRETS_OPTION, $merged, false );
        }
        // secrets() memoises per request, so a caller reading a credential
        // later in the SAME request (connect → sign a probe image) would
        // otherwise see the pre-write value.
        self::$secrets_override = $merged;
    }

    /**
     * Set by put_secrets() so secrets() reflects a write made this request.
     * Null means "nothing written yet"; an array wins over the memo.
     */
    private static $secrets_override = null;

    /** Fired after a successful bulk save. */
    const ACTION_SAVED = 'easyopt_settings_saved';

    /** Lookup table for the read-shim filter: setting_key => true. */
    private static $setting_keys_flipped = null;

    /** Cached defaults map. */
    private static $defaults_cache = null;

    /**
     * Boot. Installs the read shim so existing get_option('easyopt_X')
     * call sites transparently resolve to the new array.
     */
    public static function init() {
        // (2.5.4 / perf #20) Per-key interceptors instead of one global
        // pre_option filter. The global hook fired for EVERY get_option()
        // call site-wide (~300+/request, core + every other plugin), each
        // paying an apply_filters frame + our closure just to strpos-reject.
        // The setting keys are a closed, static set (the registry schema),
        // so registering pre_option_{$key} once per key at boot (~one array
        // insert each) moves the dispatch into WordPress' own hook lookup:
        // foreign option reads no longer touch our code at all.
        // maybe_shim_read()'s signature already matches the dynamic hook's
        // ($pre, $option, $default), so the callback body — including the
        // prefix / known-key / legacy-row logic — is byte-identical.
        foreach ( EasyOpt_Settings_Registry::keys() as $easyopt_reg_key ) {
            add_filter( 'pre_option_' . $easyopt_reg_key, array( __CLASS__, 'maybe_shim_read' ), 5, 3 );
        }

        // Provide a sensible default for the storage option itself so
        // first-boot reads (before any save) return the full schema
        // defaults rather than an empty array.
        add_filter( 'default_option_easyopt_settings', array( __CLASS__, 'default_storage' ), 5 );
    }

    /**
     * @param mixed $default WordPress' own default; ignored.
     * @return array
     */
    public static function default_storage( $default ) {
        return self::defaults_array();
    }

    /**
     * Read interceptor. If the caller asks for `easyopt_<known_setting>`,
     * we resolve it from the settings array instead of looking up a
     * standalone row. Everything else (runtime state, third-party
     * options) falls through untouched.
     *
     * Pre-migration safety net: if the storage row doesn't yet contain
     * this key (e.g., a frontend request landed BEFORE the one-shot
     * 1.7.0 migration ran), we look for the old per-row option directly
     * via $wpdb so the user's saved 1.6.x settings still take effect.
     * Once the migration runs on first admin_init, this fallback path
     * is unreachable.
     *
     * @param mixed  $pre_value False if nothing has intercepted yet.
     * @param string $option    The option name being looked up.
     * @return mixed            Resolved value, or unchanged $pre_value.
     */
    public static function maybe_shim_read( $pre_value, $option, $default = false ) {
        // Someone else already handled this — don't fight them.
        if ( false !== $pre_value ) {
            return $pre_value;
        }
        // Fast prefix check — eliminates 95%+ of calls before the hash lookup.
        // Fires on every get_option() (~300+/request), so this early exit matters.
        if ( 0 !== strpos( $option, self::PREFIX ) ) {
            return $pre_value;
        }
        // Not one of our setting keys? Let WordPress resolve normally.
        if ( ! self::is_setting_key( $option ) ) {
            return $pre_value;
        }
        $settings = self::raw_settings();
        if ( is_array( $settings ) && array_key_exists( $option, $settings ) ) {
            return $settings[ $option ];
        }

        // ── Pre-migration safety net ──
        // The storage row doesn't have this key. Check whether the legacy
        // per-row option still exists (un-migrated 1.6.x install). Direct
        // $wpdb read to avoid re-entering get_option (which would re-fire
        // this same filter and recurse). Found → seed the migration marker
        // so the migration path knows to run, but return the legacy value
        // right now so the caller doesn't see stale defaults.
        $legacy = self::read_legacy_row( $option );
        if ( null !== $legacy ) {
            return $legacy;
        }

        // Setting key, no storage row, no legacy row → schema default.
        $defaults = self::defaults_array();
        if ( array_key_exists( $option, $defaults ) ) {
            return $defaults[ $option ];
        }
        return $pre_value;
    }

    /**
     * Direct $wpdb read of a single legacy `easyopt_X` row, bypassing
     * get_option() so the pre_option shim doesn't recurse. Returns null
     * when the row doesn't exist; otherwise returns the unserialised value.
     *
     * Cached per request — once we've checked for a row and found it
     * missing, subsequent checks in the same request are a no-op (no DB
     * hit). This matters because alloptions is bypassed for non-autoloaded
     * options, and we don't want every config read to be a DB roundtrip.
     */
    private static function read_legacy_row( $option ) {
        static $cache = array();
        if ( array_key_exists( $option, $cache ) ) {
            return $cache[ $option ];
        }
        // (2.5.4 / perf #53) Once the one-shot 1.7.0 migration has run (the
        // marker is kept forever), no legacy per-row option can exist — yet
        // any NEW setting key added in an update would land here (absent
        // from the storage row) and silently issue one SELECT per key per
        // request until the settings were next saved. Post-migration, skip
        // straight to the schema default. Pre-migration installs keep the
        // full safety-net lookup exactly as before.
        if ( class_exists( 'EasyOpt_Migration' ) && (int) get_option( EasyOpt_Migration::MARKER_OPTION, 0 ) >= 1 ) {
            $cache[ $option ] = null;
            return null;
        }
        global $wpdb;
        if ( ! isset( $wpdb ) ) {
            $cache[ $option ] = null;
            return null;
        }
        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            $option
        ) );
        if ( null === $row || false === $row ) {
            $cache[ $option ] = null;
            return null;
        }
        $cache[ $option ] = maybe_unserialize( $row );
        return $cache[ $option ];
    }

    /**
     * Get a single setting by its short name (without the `easyopt_`
     * prefix). Accepts the prefixed form too for callers that already
     * have the full key in hand.
     */
    public static function get( $key, $default = false ) {
        $full = self::prefix_key( $key );
        // (2.6.1) Credentials live in their own non-autoloaded option. The
        // accessor surface is unchanged — callers still use the short,
        // unprefixed name.
        if ( self::is_secret_key( $full ) ) {
            $secrets = self::secrets();
            return isset( $secrets[ $full ] ) ? $secrets[ $full ] : '';
        }
        $settings = self::raw_settings();
        if ( is_array( $settings ) && array_key_exists( $full, $settings ) ) {
            return $settings[ $full ];
        }
        $defaults = self::defaults_array();
        if ( array_key_exists( $full, $defaults ) ) {
            return $defaults[ $full ];
        }
        return $default;
    }

    /**
     * Write a single setting and trigger the saved-action with a
     * single-key diff. Equivalent to update_many([key => value]) but
     * spelt for callers that just want to flip one flag.
     */
    public static function set( $key, $value ) {
        $full = self::prefix_key( $key );
        return self::update_many( array( $full => $value ) );
    }

    /**
     * Return the full settings array (with all schema defaults applied
     * for keys that haven't been explicitly saved yet).
     */
    public static function get_all() {
        $stored   = self::raw_settings();
        $defaults = self::defaults_array();
        if ( ! is_array( $stored ) ) {
            return $defaults;
        }
        // Defaults provide every known key; stored values win where present.
        return array_replace( $defaults, $stored );
    }

    /**
     * Bulk write. THIS is the endpoint that every settings save (REST,
     * programmatic, migration) funnels through. Steps:
     *
     *   1. Sanitise each incoming value against the schema. Unknown keys
     *      are dropped silently — never trust client input.
     *   2. Merge into the stored array.
     *   3. Compute the diff (which keys actually changed value).
     *   4. Write the row.
     *   5. Fire `easyopt_settings_saved` with (new, old, changed_keys).
     *
     * Returns the array of keys that actually changed (so callers can
     * tell whether the save was a no-op).
     *
     * @param array $changes  key => value pairs to apply.
     * @return string[] Changed setting keys (full, prefixed form).
     */
    public static function update_many( array $changes ) {
        $old        = self::get_all();
        $sanitised  = array();

        foreach ( $changes as $key => $raw ) {
            if ( ! is_string( $key ) ) {
                continue;
            }
            if ( ! self::is_setting_key( $key ) ) {
                // Unknown key — refuse silently. Settings are an allowlist.
                continue;
            }
            $clean = EasyOpt_Settings_Registry::sanitize( $key, $raw );
            if ( null === $clean ) {
                continue;
            }
            $sanitised[ $key ] = $clean;
        }

        // (2.6.1) Peel credentials off before the main diff. They are not in
        // $old (get_all() no longer exposes them), so leaving them in the
        // normal path would report them as changed on every save and write
        // them straight back into the autoloaded blob we just moved them out
        // of. They are still reported in $changed so the save-coordinator's
        // listeners fire exactly as before.
        $secret_changes = array();
        foreach ( self::secret_keys() as $sk ) {
            if ( array_key_exists( $sk, $sanitised ) ) {
                $secret_changes[ $sk ] = $sanitised[ $sk ];
                unset( $sanitised[ $sk ] );
            }
        }
        $secret_changed = array();
        if ( ! empty( $secret_changes ) ) {
            $before = self::secrets();
            foreach ( $secret_changes as $sk => $val ) {
                $prev = isset( $before[ $sk ] ) ? $before[ $sk ] : '';
                if ( $prev !== $val ) {
                    $secret_changed[] = $sk;
                }
            }
            if ( ! empty( $secret_changed ) ) {
                self::put_secrets( $secret_changes );
            }
        }

        if ( empty( $sanitised ) ) {
            if ( ! empty( $secret_changed ) ) {
                $new_all = self::get_all();
                do_action( self::ACTION_SAVED, $new_all, $old, $secret_changed );
            }
            return $secret_changed;
        }

        // Strict-equality comparison so the diff is honest.
        $changed = array();
        foreach ( $sanitised as $key => $new_val ) {
            if ( ! array_key_exists( $key, $old ) || $old[ $key ] !== $new_val ) {
                $changed[] = $key;
            }
        }

        $changed = array_merge( $changed, $secret_changed );

        if ( empty( $changed ) ) {
            return array();
        }

        $new = array_replace( $old, $sanitised );

        // (2.6.1) $old came from get_all(), which fills every known key from
        // the registry defaults — including the four credential keys, as ''.
        // Writing those back would re-seed them into the autoloaded blob we
        // just migrated them out of. Harmless in content, but it would undo
        // the separation on the very next save.
        $persist = $new;
        foreach ( self::secret_keys() as $sk ) {
            unset( $persist[ $sk ] );
        }

        // The actual DB write. update_option does its own no-op check
        // (if the serialised representation hasn't changed) but our diff
        // above is more reliable.
        update_option( self::STORAGE_OPTION, $persist );

        // Fire the consolidated saved-action. Listeners diff (new, old)
        // and decide what side-effects to run, each at most once.
        do_action( self::ACTION_SAVED, $new, $old, $changed );

        return $changed;
    }

    /**
     * True if the full option name corresponds to a known user setting.
     * Cached after first call so per-request lookup is O(1).
     */
    public static function is_setting_key( $full_option_name ) {
        if ( null === self::$setting_keys_flipped ) {
            self::$setting_keys_flipped = array_flip( EasyOpt_Settings_Registry::keys() );
        }
        return isset( self::$setting_keys_flipped[ $full_option_name ] );
    }

    /**
     * Read the storage row. STORAGE_OPTION is not in the setting-keys
     * list, so the pre_option shim falls through to a real DB read —
     * no infinite recursion.
     */
    private static function raw_settings() {
        $val = get_option( self::STORAGE_OPTION, null );
        if ( null === $val ) {
            return array();
        }
        return is_array( $val ) ? $val : array();
    }

    private static function defaults_array() {
        if ( null === self::$defaults_cache ) {
            self::$defaults_cache = EasyOpt_Settings_Registry::defaults();
        }
        return self::$defaults_cache;
    }

    private static function prefix_key( $key ) {
        if ( ! is_string( $key ) ) {
            return '';
        }
        return ( 0 === strpos( $key, self::PREFIX ) ) ? $key : self::PREFIX . $key;
    }

    /** Public reset hook for tests / future maintenance. */
    public static function invalidate() {
        self::$defaults_cache = null;
    }

    /* ── (2.5.4 / perf #26) Unified schema-version registry ──────────────
     * One small AUTOLOADED map ({component: version}) replaces the per-
     * component non-autoloaded options as the per-request fast path. Each
     * table owner (queue, preload-results, LCP, backend) still keeps its
     * legacy option as the source of truth during create/upgrade; this map
     * is a read-through cache of it, so the steady state costs zero extra
     * queries (alloptions) instead of one SELECT per component per request.
     * ─────────────────────────────────────────────────────────────────── */

    /** @var array<string,string>|null Per-request copy of the map. */
    private static $schema_versions = null;

    /** Read a component's recorded schema version ('' when unknown). */
    public static function schema_version( $component ) {
        if ( null === self::$schema_versions ) {
            $stored = get_option( 'easyopt_schema_versions', array() );
            self::$schema_versions = is_array( $stored ) ? $stored : array();
        }
        $component = (string) $component;
        return isset( self::$schema_versions[ $component ] ) ? (string) self::$schema_versions[ $component ] : '';
    }

    /** Record a component's schema version (no-op when unchanged). */
    public static function set_schema_version( $component, $version ) {
        $component = (string) $component;
        $version   = (string) $version;
        if ( self::schema_version( $component ) === $version ) {
            return;
        }
        self::$schema_versions[ $component ] = $version;
        update_option( 'easyopt_schema_versions', self::$schema_versions, true );
    }

    /** Forget a component (uninstall/table-drop paths). */
    public static function clear_schema_version( $component ) {
        $component = (string) $component;
        if ( '' === self::schema_version( $component ) ) {
            return;
        }
        unset( self::$schema_versions[ $component ] );
        update_option( 'easyopt_schema_versions', self::$schema_versions, true );
    }

    /**
     * Alias for get_all(). Used by the bootstrap for conditional module
     * loading so we read options once, not per-feature.
     */
    public static function all() {
        return self::get_all();
    }

    /* ───────────────────────────────────────────────
     *  WooCommerce dynamic-page detection
     *
     *  Cart, Checkout, My Account, and endpoint pages are session-dependent
     *  and should not be processed by most optimisation modules (Delay JS,
     *  Unused CSS, Lazy Load, LCP, Fonts, CDN, A11y, SEO). Buffer-level
     *  optimisations on these pages cause broken payment gateways, missing
     *  cart items, FOUT during checkout, and broken form submissions.
     *
     *  Memoised per request so repeated calls from different modules are
     *  free after the first check.
     * ─────────────────────────────────────────────── */

    /** @var bool|null Cached result for the current request. */
    private static $is_woo_dynamic = null;

    /**
     * True when the current request is a WooCommerce Cart, Checkout,
     * My Account page, or any WC endpoint URL. Safe to call multiple
     * times per request (O(1) after first call).
     *
     * Falls back to a REQUEST_URI substring check when WooCommerce's
     * conditional functions are not available yet (e.g., very early hooks
     * before `wp` fires).
     *
     * @return bool
     */
    public static function is_woo_dynamic_page() {
        if ( null !== self::$is_woo_dynamic ) {
            return self::$is_woo_dynamic;
        }

        // No WooCommerce? Nothing to exclude.
        if ( ! class_exists( 'WooCommerce' ) ) {
            self::$is_woo_dynamic = false;
            return false;
        }

        // Primary check — WooCommerce conditional functions. These are
        // reliable after the `wp` action has fired (template_redirect,
        // output buffer callbacks, etc.).
        if ( function_exists( 'is_cart' ) && is_cart() ) {
            self::$is_woo_dynamic = true;
            return true;
        }
        if ( function_exists( 'is_checkout' ) && is_checkout() ) {
            self::$is_woo_dynamic = true;
            return true;
        }
        if ( function_exists( 'is_account_page' ) && is_account_page() ) {
            self::$is_woo_dynamic = true;
            return true;
        }
        if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
            self::$is_woo_dynamic = true;
            return true;
        }

        // Fallback — URI-based check for edge cases where WC conditionals
        // haven't resolved yet. Uses the same URL fragments as the cache
        // module's exclusion list for consistency.
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        if ( '' !== $uri ) {
            $woo_paths = array( '/cart', '/checkout', '/my-account' );

            // Honour WooCommerce's dynamic page slugs when available.
            // (2.5.4 / perf #37) The three wc_get_page_permalink() calls
            // (option read + full permalink build each) ran once per uncached
            // request just to learn three paths that change only when a shop
            // admin renames a core page. Cache the resolved list for 12 h;
            // the WC conditionals above remain the primary, always-live
            // detection, so a rename is still caught everywhere the `wp`
            // action has fired — this fallback path only covers pre-wp hook
            // depths, where a ≤12 h-stale slug list is a non-issue.
            if ( function_exists( 'wc_get_page_permalink' ) ) {
                $easyopt_wc_paths = get_transient( 'easyopt_woo_dynamic_paths' );
                if ( ! is_array( $easyopt_wc_paths ) ) {
                    $easyopt_wc_paths = array();
                    foreach ( array( 'cart', 'checkout', 'myaccount' ) as $wc_page ) {
                        $wc_url  = wc_get_page_permalink( $wc_page );
                        $wc_path = $wc_url ? wp_parse_url( $wc_url, PHP_URL_PATH ) : '';
                        if ( '' !== $wc_path && '/' !== $wc_path ) {
                            $easyopt_wc_paths[] = rtrim( $wc_path, '/' );
                        }
                    }
                    set_transient( 'easyopt_woo_dynamic_paths', $easyopt_wc_paths, 12 * HOUR_IN_SECONDS );
                }
                $woo_paths = array_merge( $woo_paths, $easyopt_wc_paths );
            }

            $woo_paths = array_unique( $woo_paths );
            foreach ( $woo_paths as $woo_path ) {
                if ( false !== stripos( $uri, $woo_path ) ) {
                    self::$is_woo_dynamic = true;
                    return true;
                }
            }
        }

        self::$is_woo_dynamic = false;
        return false;
    }
}
