<?php
/**
 * REST API endpoint for settings save & discard (1.7.0).
 *
 * Replaces the WP Settings API form-POST → options.php → 302 cycle with
 * a single `POST /wp-json/easyopt/v1/settings` request that returns the
 * canonical state after save. The admin UI uses this to render the
 * sticky save bar and the "Saved" / "Discarded" toast without a reload.
 *
 * Auth model: `manage_options` capability + WP REST nonce. Nonce is
 * emitted on the settings page only, so callers from outside the admin
 * UI are rejected.
 *
 * Endpoints:
 *   GET  /easyopt/v1/settings         → { settings: {...full array...} }
 *   POST /easyopt/v1/settings         body: { changes: {key: value, ...} }
 *                                     → { settings: {...}, changed: [keys] }
 *
 * @package EasyOptimizer
 * @since   1.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Rest_Settings {

    const NAMESPACE_V1 = 'easyopt/v1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route( self::NAMESPACE_V1, '/settings', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'get_settings' ),
                'permission_callback' => array( __CLASS__, 'permission_check' ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'save_settings' ),
                'permission_callback' => array( __CLASS__, 'permission_check' ),
                'args'                => array(
                    'changes' => array(
                        'required'    => true,
                        'type'        => 'object',
                        'description' => 'Key/value pairs to save. Keys must be known easyopt_ settings.',
                    ),
                ),
            ),
        ) );
    }

    /**
     * Permission gate. Requires both:
     *   - manage_options capability (admin-level)
     *   - Valid REST nonce in X-WP-Nonce header
     *
     * The capability check is what makes this safe; the nonce is the
     * CSRF token preventing same-origin browser drive-bys.
     */
    public static function permission_check( $request ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'easyopt_forbidden', __( 'Insufficient permissions.', 'easy-optimizer' ), array( 'status' => 403 ) );
        }
        return true;
    }

    /**
     * Return the current state of every setting (defaults applied for
     * keys never explicitly set). Used by the UI to hydrate its in-memory
     * "last saved" snapshot on page load.
     */
    public static function get_settings( $request ) {
        return rest_ensure_response( array(
            'settings' => EasyOpt_Config::get_all(),
        ) );
    }

    /**
     * Save handler. Hands off to EasyOpt_Config::update_many() which
     * sanitises, diffs, writes, and fires the saved-action. The save
     * coordinator's deferred drainer runs after this response is
     * flushed (via fastcgi_finish_request) so the UI sees a sub-second
     * round trip even when cache clears and htaccess writes are
     * triggered.
     */
    public static function save_settings( $request ) {
        $changes = $request->get_param( 'changes' );
        if ( ! is_array( $changes ) ) {
            return new WP_Error(
                'easyopt_bad_payload',
                __( 'changes must be an object.', 'easy-optimizer' ),
                array( 'status' => 400 )
            );
        }

        $changed_keys = EasyOpt_Config::update_many( $changes );

        // Run deferred side-effects (cache clear, preload restart, htaccess)
        // synchronously so the response includes post-clear state.
        // The shutdown drain() becomes a no-op because flags are reset.
        if ( class_exists( 'EasyOpt_Save_Coordinator' ) ) {
            EasyOpt_Save_Coordinator::drain_now();
        }

        // (2.4.0) Capture the PageSpeed baseline once, in the background,
        // if it doesn't exist yet. No-op on every later save.
        if ( class_exists( '\\EasyOpt\\Psi\\Client' ) ) {
            \EasyOpt\Psi\Client::on_settings_saved();
        }

        // Include fresh dashboard stats so React can update the UI
        // immediately without waiting for a separate poll.
        $stats = null;
        if ( class_exists( 'EasyOpt_Rest_Dashboard' ) && method_exists( 'EasyOpt_Rest_Dashboard', 'get_dashboard_stats' ) ) {
            // Fast path: never block a settings save on a cache-directory scan.
            // The counter is O(1); on a not-yet-seeded counter we report 0 and
            // let the dashboard's own GET /dashboard-stats fill it in.
            $stats_response = EasyOpt_Rest_Dashboard::get_dashboard_stats( null, true );
            $stats = $stats_response->get_data();
        }

        return rest_ensure_response( array(
            'settings' => EasyOpt_Config::get_all(),
            'changed'  => $changed_keys,
            'saved_at' => time(),
            'stats'    => $stats,
        ) );
    }
}
