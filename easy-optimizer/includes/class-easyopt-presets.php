<?php
/**
 * Optimization presets (2.3.0).
 *
 * Single source of truth for the three setup presets offered by the
 * first-run wizard (Safe / Balanced / Maximum) and reused by the
 * "Safe Mode" button on the Settings tab. Each preset is a COMPLETE map
 * of the feature keys it governs, so applying one is deterministic:
 * switching from Maximum back to Safe genuinely turns the heavier
 * features back off.
 *
 * Applying a preset funnels through EasyOpt_Config::update_many(), which
 * fires the saved-action so the save coordinator installs/removes the
 * drop-in, writes/removes .htaccess, clears the cache, and restarts the
 * preloader — exactly as a normal settings save would.
 *
 * @package EasyOptimizer
 * @since   2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Presets {

    const NAMESPACE_V1 = 'easyopt/v1';

    /** Recognised preset names. */
    public static function names() {
        return array( 'safe', 'balanced', 'maximum' );
    }

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    /**
     * Settings map for a preset (full, prefixed keys). Built as
     * Safe → Balanced → Maximum so each tier extends the previous one.
     *
     * @param string $name safe|balanced|maximum
     * @return array<string,mixed>|null  Null for an unknown name.
     */
    public static function map( $name ) {
        $name = is_string( $name ) ? strtolower( trim( $name ) ) : '';

        // ── Safe ──────────────────────────────────────────────────────────
        // Page cache + browser caching + gzip + font-display swap + LCP
        // preload + lazy load (images/iframes/videos) + cache preload +
        // moderate prefetch. No Used CSS, no Delay JS.
        $safe = array(
            'easyopt_cache'                      => 1,
            'easyopt_cache_preload'              => 1,
            'easyopt_cache_browser_caching'      => 1,
            'easyopt_cache_gzip'                 => 1,
            'easyopt_font_display_swap'          => 1,
            'easyopt_lcp_preload'                => 1,
            'easyopt_lazy_images'                => 1,
            'easyopt_lazy_iframes'               => 1,
            'easyopt_lazy_videos'                => 1,
            'easyopt_add_missing_dims'           => 0,
            'easyopt_instant_preload'            => 1,
            'easyopt_instant_eagerness'          => 'moderate',
            'easyopt_unused_css'                 => 0,
            'easyopt_unused_css_post_types_only' => 1,
            'easyopt_delay_js'                   => 0,
            'easyopt_lazyload_fonts'             => 0,
            // (2.5.7) Minification is now GOVERNED by the presets. It was
            // absent from every tier, so the Safe Mode button — the emergency
            // escape hatch users are told to press when a site breaks — could
            // not turn it off. Of the three modules that visibly break sites
            // (Delay JS, Used CSS, Minify) it disabled only two.
            'easyopt_minify_css'                 => 0,
            'easyopt_minify_js'                  => 0,
            // Defaults requested for every preset.
            'easyopt_delete_on_uninstall'        => 0,
            'easyopt_log_warnings'               => 0,
        );

        if ( 'safe' === $name ) {
            /**
             * Filter a preset's complete settings map.
             *
             * Fires for every preset. The callback MUST return a complete map —
             * keys absent from it are not governed by the preset and retain
             * their current values. Unknown keys are dropped by
             * EasyOpt_Config::update_many(), so a malformed filter can only
             * under-apply, never write invalid data.
             *
             * @since 2.5.7
             * @param array<string,mixed> $map  Complete settings map.
             * @param string              $name safe|balanced|maximum
             */
            return (array) apply_filters( 'easyopt_preset_map', $safe, 'safe' );
        }

        // ── Balanced ────────────────────────────────────────────────────
        // Safe + Used CSS (Async) + Delay JS (jQuery excluded) + add missing
        // image dimensions. Process-post-types-only stays on (valid for the
        // delay/async behaviours).
        $balanced = array_replace( $safe, array(
            'easyopt_unused_css'                 => 1,
            'easyopt_unused_css_behavior'        => 'async',
            'easyopt_unused_css_post_types_only' => 1,
            'easyopt_delay_js'                   => 1,
            'easyopt_delay_js_method'            => 'delay',
            'easyopt_delay_js_exclude_jquery'    => 1,
            'easyopt_add_missing_dims'           => 1,
        ) );

        if ( 'balanced' === $name ) {
            /** This filter is documented above in map(). */
            return (array) apply_filters( 'easyopt_preset_map', $balanced, 'balanced' );
        }

        // ── Maximum ─────────────────────────────────────────────────────
        // Balanced + Used CSS (Delay) + Delay JS without jQuery excluded.
        // (2.5.4) Smart Lazyload Fonts is intentionally left OFF here — it
        // inherits 0 from Safe/Balanced. It can visibly delay icon fonts /
        // FontAwesome ::before glyphs above the fold, so it's no longer part
        // of any preset; users can still enable it manually.
        $maximum = array_replace( $balanced, array(
            'easyopt_unused_css_behavior'        => 'delayed',
            'easyopt_delay_js_exclude_jquery'    => 0,
        ) );

        if ( 'maximum' === $name ) {
            /** This filter is documented above in map(). */
            return (array) apply_filters( 'easyopt_preset_map', $maximum, 'maximum' );
        }

        return null;
    }

    /**
     * Apply a preset and run the usual save side-effects synchronously so
     * the HTTP response reflects the post-apply state.
     *
     * (2.5.7) Environment exclusions are composed ON TOP of the preset map
     * rather than inside it, so map() remains a pure, deterministic function
     * of $name and the Safe Mode snapshot/restore contract is unchanged.
     * EasyOpt_Preflight is optional — when it is absent (as in 2.5.7, where
     * only the composition layer ships) apply() behaves exactly as before.
     *
     * @param string $name
     * @param bool   $respect_environment  False reproduces pre-2.5.7 behaviour.
     * @return array|WP_Error  Changed keys on success.
     */
    public static function apply( $name, $respect_environment = true ) {
        $map = self::map( $name );
        if ( null === $map ) {
            return new WP_Error( 'easyopt_bad_preset', __( 'Unknown preset.', 'easy-optimizer' ), array( 'status' => 400 ) );
        }

        $excluded = array();
        if ( $respect_environment && class_exists( 'EasyOpt_Preflight' ) ) {
            $scan = EasyOpt_Preflight::scan();
            if ( is_array( $scan ) && ! empty( $scan['exclude'] ) ) {
                foreach ( $scan['exclude'] as $key => $reason ) {
                    if ( array_key_exists( $key, $map ) && 0 !== (int) $map[ $key ] ) {
                        $map[ $key ]      = 0;
                        $excluded[ $key ] = (string) $reason;
                    }
                }
            }
        }

        // Record what was excluded and why, so the dashboard can explain a
        // feature being off instead of the user reading it as broken. An
        // exclusion the user cannot see is indistinguishable from a bug.
        update_option( 'easyopt_preset_exclusions', $excluded, false );

        $changed = EasyOpt_Config::update_many( $map );

        if ( class_exists( 'EasyOpt_Save_Coordinator' ) ) {
            EasyOpt_Save_Coordinator::drain_now();
        }

        // (2.4.0) PageSpeed automation: capture the baseline if missing;
        // for Balanced/Maximum on a never-benchmarked site, schedule the
        // one-time "after" test ~15 min out so preload warms the cache
        // first. See Client::on_preset_applied().
        if ( class_exists( '\\EasyOpt\\Psi\\Client' ) ) {
            \EasyOpt\Psi\Client::on_preset_applied( (string) $name );
        }

        return $changed;
    }

    /* ── REST ──────────────────────────────────────────────────────────── */

    public static function register_routes() {
        register_rest_route( self::NAMESPACE_V1, '/wizard/apply', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_wizard_apply' ),
            'permission_callback' => array( __CLASS__, 'permission_check' ),
            'args'                => array(
                'preset'   => array( 'required' => true, 'type' => 'string' ),
                'tracking' => array( 'required' => false, 'type' => 'boolean' ),
            ),
        ) );

        // (2.6.0) Pre-flight scan for the wizard. Read-only: it applies
        // nothing, it only reports what the environment already owns so the
        // preset screen can pre-select correctly and explain itself.
        register_rest_route( self::NAMESPACE_V1, '/wizard/preflight', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_wizard_preflight' ),
            'permission_callback' => array( __CLASS__, 'permission_check' ),
        ) );

        register_rest_route( self::NAMESPACE_V1, '/wizard/skip', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_wizard_skip' ),
            'permission_callback' => array( __CLASS__, 'permission_check' ),
        ) );

        // (2.6.5) Deactivate ONE conflicting plugin from the wizard, so Easy
        // Optimizer can take over its jobs. User-triggered only; the file must
        // be one the wizard offered (validated against
        // wizard_conflicting_plugins), so this can never be pointed at an
        // arbitrary plugin.
        register_rest_route( self::NAMESPACE_V1, '/wizard/deactivate', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_wizard_deactivate' ),
            'permission_callback' => array( __CLASS__, 'permission_check' ),
            'args'                => array(
                'file' => array( 'required' => true, 'type' => 'string' ),
            ),
        ) );

        register_rest_route( self::NAMESPACE_V1, '/safe-mode', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_safe_mode' ),
            'permission_callback' => array( __CLASS__, 'permission_check' ),
            'args'                => array(
                'enable' => array( 'required' => true, 'type' => 'boolean' ),
            ),
        ) );

        register_rest_route( self::NAMESPACE_V1, '/tracking', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_tracking' ),
            'permission_callback' => array( __CLASS__, 'permission_check' ),
            'args'                => array(
                'enabled' => array( 'required' => true, 'type' => 'boolean' ),
            ),
        ) );
    }

    public static function permission_check() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'easyopt_forbidden', __( 'Insufficient permissions.', 'easy-optimizer' ), array( 'status' => 403 ) );
        }
        return true;
    }

    /**
     * Wizard pre-flight. Returns what the environment already owns.
     *
     * Fails open by construction: EasyOpt_Preflight::scan() catches per-probe
     * and this wraps the whole call, so a scan failure degrades to today's
     * three static cards rather than blocking setup.
     *
     * @since 2.6.0
     */
    public static function rest_wizard_preflight() {
        $scan = array( 'exclude' => array(), 'layers' => array(), 'notes' => array(), 'warnings' => array() );
        try {
            if ( class_exists( 'EasyOpt_Preflight' ) ) {
                $scan = EasyOpt_Preflight::scan();
            }
        } catch ( \Throwable $e ) {
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::warn( 'preflight', 'Scan failed; falling back to static presets: ' . $e->getMessage() );
            }
        }

        // Map excluded keys to the wizard cards they affect, so the UI can say
        // which feature will stay off without knowing the settings schema.
        // (2.6.5) Object Cache is deliberately NOT listed here, so it never
        // appears as a wizard finding — a foreign object-cache drop-in is not
        // a decision the user makes during setup (it's already installed and
        // fine), and the line was noise on the one screen where every row must
        // earn its place. The dashboard Compatibility card still reports it.
        $labels = array(
            'easyopt_cache'             => __( 'Page Cache', 'easy-optimizer' ),
            'easyopt_cache_preload'     => __( 'Cache Preload', 'easy-optimizer' ),
            'easyopt_lazy_images'       => __( 'Lazy Load', 'easy-optimizer' ),
            'easyopt_font_display_swap' => __( 'Font optimisation', 'easy-optimizer' ),
            'easyopt_preload_fonts'     => __( 'Font preloading', 'easy-optimizer' ),
            'easyopt_unused_css'        => __( 'Remove Unused CSS', 'easy-optimizer' ),
            'easyopt_minify_css'        => __( 'Minify CSS', 'easy-optimizer' ),
            'easyopt_minify_js'         => __( 'Minify JavaScript', 'easy-optimizer' ),
            'easyopt_delay_js'          => __( 'Delay JavaScript', 'easy-optimizer' ),
        );

        // (2.6.5) Page Cache is the parent toggle — Cache Preload only runs
        // while it is on. When a foreign page cache owns caching both are
        // excluded, and listing them both repeats the same sentence. Collapse
        // the child so the wizard shows one line ("Page Cache") not two.
        $exclude = (array) $scan['exclude'];
        if ( array_key_exists( 'easyopt_cache', $exclude ) ) {
            unset( $exclude['easyopt_cache_preload'] );
        }

        $findings = array();
        foreach ( $exclude as $key => $reason ) {
            if ( isset( $labels[ $key ] ) ) {
                $findings[] = array(
                    'feature' => $labels[ $key ],
                    'reason'  => (string) $reason,
                );
            }
        }

        // (2.6.5) Per-plugin deactivate rows + the "compatible with X" server
        // label. Both fail soft — a throw here must never block setup.
        $plugins = array();
        $server  = '';
        try {
            if ( class_exists( 'EasyOpt_Compat_Conflicts' ) ) {
                $plugins = EasyOpt_Compat_Conflicts::wizard_conflicting_plugins();
            }
            if ( class_exists( 'EasyOpt_Hosting' ) ) {
                $server = EasyOpt_Hosting::compatible_server_label();
            }
        } catch ( \Throwable $e ) { /* fail open */ }

        // (2.6.5) Drop the "your host runs its own cache layer" note from the
        // wizard. Like the object-cache line above and the SiteGround card
        // entry, it is caching another layer already owns — not a setup
        // decision, and noise on this screen. Other notes (e.g. the low-memory
        // preload note) stay, and the note is untouched for any other consumer.
        $notes = array();
        foreach ( (array) $scan['notes'] as $note ) {
            if ( false !== stripos( (string) $note, 'cache layer' ) ) {
                continue;
            }
            $notes[] = $note;
        }

        return rest_ensure_response( array(
            'ok'       => true,
            'findings' => $findings,
            'plugins'  => $plugins,
            'server'   => $server,
            'notes'    => array_values( $notes ),
            'warnings' => array_values( (array) $scan['warnings'] ),
        ) );
    }

    /**
     * Wizard "Apply & Finish". Applies the chosen preset, records the
     * tracking consent, and marks the wizard complete.
     */
    public static function rest_wizard_apply( $request ) {
        $preset   = (string) $request->get_param( 'preset' );
        $tracking = (bool) $request->get_param( 'tracking' );

        $changed = self::apply( $preset );
        if ( is_wp_error( $changed ) ) {
            return $changed;
        }

        // Record diagnostics consent from the wizard checkbox.
        if ( class_exists( 'EasyOpt_Tracker' ) ) {
            EasyOpt_Tracker::set_optin( $tracking ? 'yes' : 'no' );
            EasyOpt_Tracker::wizard_completed( $preset );
        }

        self::mark_wizard_done();

        return rest_ensure_response( array(
            'ok'       => true,
            'preset'   => $preset,
            'changed'  => $changed,
            'redirect' => admin_url( 'admin.php?page=easy-optimizer' ),
        ) );
    }

    /**
     * Wizard "Skip" — leaves every feature off (the seeded baseline) and
     * just marks the wizard complete so it isn't shown again. Tracking
     * consent is recorded from the checkbox if the user toggled it.
     */
    public static function rest_wizard_skip( $request ) {
        if ( class_exists( 'EasyOpt_Tracker' ) ) {
            $tracking = $request->get_param( 'tracking' );
            if ( null !== $tracking ) {
                EasyOpt_Tracker::set_optin( $tracking ? 'yes' : 'no' );
            }
        }
        // Even when skipping, enforce the two requested safe defaults so a
        // skipped setup never leaves warning-logging or delete-on-uninstall on.
        EasyOpt_Config::update_many( array(
            'easyopt_log_warnings'        => 0,
            'easyopt_delete_on_uninstall' => 0,
        ) );
        if ( class_exists( 'EasyOpt_Save_Coordinator' ) ) {
            EasyOpt_Save_Coordinator::drain_now();
        }
        self::mark_wizard_done();
        return rest_ensure_response( array(
            'ok'       => true,
            'redirect' => admin_url( 'admin.php?page=easy-optimizer' ),
        ) );
    }

    /**
     * Deactivate one conflicting plugin from the wizard (2.6.5).
     *
     * The user has asked Easy Optimizer to take over a job another plugin
     * currently owns. We deactivate that plugin directly — WordPress's own
     * deactivate_plugins() — rather than sending them to the Plugins screen,
     * because the whole point is to stay inside the setup flow.
     *
     * Safety: the file MUST be one the wizard actually offered
     * (wizard_conflicting_plugins), so a crafted request can never deactivate
     * an unrelated plugin, and a plugin we depend on (SiteGround Optimizer) is
     * excluded from that list. The caller reloads afterwards, which re-runs the
     * pre-flight with the plugin gone — so finishing setup then applies the
     * full preset with nothing excluded on its behalf.
     */
    public static function rest_wizard_deactivate( $request ) {

        $file = (string) $request->get_param( 'file' );

        // Whitelist against what the wizard offered — never trust the file.
        $allowed = array();
        if ( class_exists( 'EasyOpt_Compat_Conflicts' ) ) {
            foreach ( EasyOpt_Compat_Conflicts::wizard_conflicting_plugins() as $p ) {
                $allowed[ $p['file'] ] = true;
            }
        }
        if ( '' === $file || empty( $allowed[ $file ] ) ) {
            return new WP_Error(
                'easyopt_bad_plugin',
                __( 'That plugin can\'t be deactivated from here.', 'easy-optimizer' ),
                array( 'status' => 400 )
            );
        }

        if ( ! function_exists( 'deactivate_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        deactivate_plugins( $file );

        // (2.6.5) If the plugin we just deactivated owns advanced-cache.php,
        // remove its stale drop-in. WordPress leaves it on disk, and its files
        // are still present, so without this Easy Optimizer would refuse to
        // overwrite it ("Refused to overwrite advanced-cache.php owned by …")
        // and could never install its own page cache after the hand-over.
        if ( class_exists( 'EasyOpt_Advanced_Cache' ) ) {
            EasyOpt_Advanced_Cache::remove_dropin_owned_by( $file );
        }

        // The scan is now stale (a plugin just went away).
        if ( class_exists( 'EasyOpt_Compat_Conflicts' ) ) {
            EasyOpt_Compat_Conflicts::flush_scan();
        }
        if ( class_exists( 'EasyOpt_Preflight' ) ) {
            EasyOpt_Preflight::reset();
        }

        return rest_ensure_response( array(
            'ok'         => true,
            'file'       => $file,
            'deactivated'=> ! is_plugin_active( $file ),
        ) );
    }

    /**
     * "Safe Mode" toggle (top bar).
     *
     *   enable  → snapshot the current settings, then apply the Safe preset.
     *   disable → restore the snapshot taken when Safe Mode was enabled.
     *
     * The snapshot is the full settings array, so disabling returns the site
     * to exactly the configuration the user had before enabling Safe Mode
     * (any tweaks made while in Safe Mode are intentionally discarded).
     */
    public static function rest_safe_mode( $request ) {
        $enable = (bool) $request->get_param( 'enable' );

        if ( $enable ) {
            // Snapshot current settings BEFORE applying Safe (only the first
            // time, so a double-enable doesn't overwrite the real backup).
            if ( ! (int) get_option( 'easyopt_safe_mode_active', 0 ) ) {
                update_option( 'easyopt_safe_mode_backup', EasyOpt_Config::get_all(), false );
            }
            $changed = self::apply( 'safe' );
            if ( is_wp_error( $changed ) ) {
                return $changed;
            }
            update_option( 'easyopt_safe_mode_active', 1, false );

            if ( class_exists( 'EasyOpt_Tracker' ) ) {
                EasyOpt_Tracker::safe_mode_triggered( 'user' );
            }

            return rest_ensure_response( array(
                'ok'       => true,
                'active'   => true,
                'settings' => EasyOpt_Config::get_all(),
            ) );
        }

        // Disable → restore the snapshot.
        $backup = get_option( 'easyopt_safe_mode_backup', array() );
        if ( is_array( $backup ) && ! empty( $backup ) ) {
            EasyOpt_Config::update_many( $backup );
            if ( class_exists( 'EasyOpt_Save_Coordinator' ) ) {
                EasyOpt_Save_Coordinator::drain_now();
            }
        }
        delete_option( 'easyopt_safe_mode_backup' );
        update_option( 'easyopt_safe_mode_active', 0, false );

        return rest_ensure_response( array(
            'ok'       => true,
            'active'   => false,
            'settings' => EasyOpt_Config::get_all(),
        ) );
    }

    public static function mark_wizard_done() {
        update_option( 'easyopt_wizard_completed', 1, false );
        delete_option( 'easyopt_show_wizard' );
    }

    /** Settings-tab "Help improve Easy Optimizer" toggle → tracker opt-in. */
    public static function rest_tracking( $request ) {
        $enabled = (bool) $request->get_param( 'enabled' );
        if ( class_exists( 'EasyOpt_Tracker' ) ) {
            // Only act on an actual CHANGE. set_optin('yes') fires an
            // activation event — calling it on every settings save flooded
            // the tracker with phantom activations.
            $current = get_option( EasyOpt_Tracker::OPTIN_KEY, '' );
            $target  = $enabled ? 'yes' : 'no';
            if ( $target !== $current ) {
                EasyOpt_Tracker::set_optin( $target );
            }
        }
        return rest_ensure_response( array( 'ok' => true, 'enabled' => $enabled ) );
    }
}
