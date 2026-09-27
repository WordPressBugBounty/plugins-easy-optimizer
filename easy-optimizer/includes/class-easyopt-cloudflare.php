<?php
/**
 * Cloudflare integration.
 *
 * Purpose: when the page cache is cleared (or a single URL is invalidated),
 * also tell Cloudflare to drop its edge copy so visitors don't see stale
 * HTML for the next 30 days. Two purge strategies:
 *
 *   • Purge by tag — uses the Cache-Tag header we emit on every cache HIT.
 *     One API call drops every page for the host. Enterprise plans only.
 *   • Purge by URL — falls back to the per-URL endpoint, supported on every
 *     plan. We chunk into 30-URL batches per Cloudflare's documented limits.
 *
 * Settings stored in:
 *   easyopt_cf_enabled, easyopt_cf_email (legacy key), easyopt_cf_api_token,
 *   easyopt_cf_zone_id, easyopt_cf_purge_strategy
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Cloudflare {

    const API_BASE = 'https://api.cloudflare.com/client/v4/';

    /** Cloudflare's documented per-call file limit for URL purges. */
    const URLS_PER_CALL = 30;

    /** @var array<string,bool> URL variants queued for the shutdown flush. */
    private static $url_queue = array();

    /** @var bool Shutdown flusher registered for this request. */
    private static $flush_hooked = false;

    /** @var bool A zone-wide purge already ran this request (URL purges moot). */
    private static $purged_all = false;

    public static function init() {
        // Connection test + manual purge are served by the REST dashboard
        // (/cloudflare/test, /cloudflare/purge); the old admin-ajax twins were
        // unused by the React app and have been removed.

        // (2.5.4 / perf #5) No credentials configured → the purge callbacks
        // would no-op on every event anyway; skip registering them. Gated on
        // the presence of a token/zone (not just cf_enabled) so a mid-request
        // enable via settings save still finds the listeners registered.
        if ( '' === (string) EasyOpt_Config::get( 'cf_api_token', '' )
            && '' === (string) EasyOpt_Config::get( 'cf_zone_id', '' ) ) {
            return;
        }

        // Auto-purge hooks — fire on every cache_all() / clear_url() call.
        add_action( 'easyopt_cache_cleared_all', array( __CLASS__, 'purge_all' ) );
        add_action( 'easyopt_cache_cleared_url', array( __CLASS__, 'purge_url' ), 10, 1 );
        // (2.6.0) Upstream-only invalidation — see EasyOpt_Hosting::init().
        add_action( 'easyopt_purge_upstream_url', array( __CLASS__, 'purge_url' ), 10, 1 );

        // (2.5.4 / perf #32) Changed credentials/zone/strategy can change the
        // plan behind the zone — retest tag purges on the next full purge.
        add_action( EasyOpt_Config::ACTION_SAVED, function ( $new, $old, $changed ) {
            unset( $new, $old );
            if ( ! is_array( $changed ) ) {
                return;
            }
            // $changed is a plain LIST of changed key names (see the
            // coordinator's array_flip usage) — test membership, not keys.
            foreach ( array( 'easyopt_cf_api_token', 'easyopt_cf_zone_id', 'easyopt_cf_purge_strategy', 'easyopt_cf_enabled' ) as $k ) {
                if ( in_array( $k, $changed, true ) || array_key_exists( $k, $changed ) ) {
                    delete_option( 'easyopt_cf_tags_unsupported' );
                    return;
                }
            }
        }, 10, 3 );
    }

    public static function is_configured() {
        return (int) EasyOpt_Config::get( 'cf_enabled', 0 )
            && '' !== (string) EasyOpt_Config::get( 'cf_api_token', '' )
            && '' !== (string) EasyOpt_Config::get( 'cf_zone_id', '' );
    }

    /** Wipe the entire host from Cloudflare's edge. */
    public static function purge_all() {

        if ( ! self::is_configured() ) {
            return false;
        }

        $strategy = (string) EasyOpt_Config::get( 'cf_purge_strategy', 'host' );

        // 'host' uses the Cache-Tag we emit. Falls back to purge_everything
        // automatically on free / Pro / Business plans (Cloudflare returns
        // a 4001 error on tag purges below Enterprise).
        if ( 'host' === $strategy ) {
            // (2.5.4 / perf #32) Cloudflare rejects tag purges below the
            // Enterprise plan with error 4001 — deterministically, every
            // time. Without a memo, every full purge on a Free/Pro/Business
            // zone paid TWO sequential API round-trips (the doomed tag call
            // + the purge_everything fallback) forever. Remember the 4001
            // verdict for a week (plans change rarely; the option also
            // clears when credentials change) and go straight to the
            // fallback. Success or any OTHER failure never sets the flag.
            $easyopt_tags_unsupported = (int) get_option( 'easyopt_cf_tags_unsupported', 0 );
            if ( $easyopt_tags_unsupported > 0 && ( time() - $easyopt_tags_unsupported ) > WEEK_IN_SECONDS ) {
                delete_option( 'easyopt_cf_tags_unsupported' );
                $easyopt_tags_unsupported = 0;
            }
            if ( ! $easyopt_tags_unsupported ) {
                $host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
                $payload = array( 'tags' => array( $host ) );
                $result  = self::api_post( 'purge_cache', $payload );
                if ( ! is_wp_error( $result ) && ! empty( $result['success'] ) ) {
                    update_option( 'easyopt_cf_last_purge', time(), false );
                    self::$purged_all = true;
                    self::$url_queue  = array(); // zone purge supersedes queued URLs
                    return true;
                }
                if ( ! is_wp_error( $result ) && ! empty( $result['errors'] ) && is_array( $result['errors'] ) ) {
                    foreach ( $result['errors'] as $easyopt_cf_err ) {
                        if ( isset( $easyopt_cf_err['code'] ) && 4001 === (int) $easyopt_cf_err['code'] ) {
                            update_option( 'easyopt_cf_tags_unsupported', time(), false );
                            break;
                        }
                    }
                }
                // Fallback to purge_everything below.
            }
        }

        // Default & fallback: nuke everything in the zone.
        $result = self::api_post( 'purge_cache', array( 'purge_everything' => true ) );
        if ( ! is_wp_error( $result ) && ! empty( $result['success'] ) ) {
            update_option( 'easyopt_cf_last_purge', time(), false );
            self::$purged_all = true;
            self::$url_queue  = array();
            return true;
        }
        return false;
    }

    /**
     * Queue a single URL (and its common variants) for an edge purge.
     *
     * (2.3.3) Batched + capped. Pre-2.3.3 every easyopt_cache_cleared_url
     * event fired its own Cloudflare API call: a WooCommerce order with 20
     * line items triggered 20 × (product + shop + home + every category
     * archive) — easily 100+ API calls in one request, straight into CF's
     * rate limit on a busy store. URLs now accumulate in a deduplicated
     * per-request set and flush ONCE on shutdown, chunked to Cloudflare's
     * 30-files-per-call limit and hard-capped (filterable) per request.
     */
    public static function purge_url( $url ) {

        if ( ! self::is_configured() || ! is_string( $url ) || '' === $url ) {
            return false;
        }
        if ( self::$purged_all ) {
            return true; // zone-wide purge this request already covers it
        }

        // Common variants Cloudflare may have cached separately.
        $variants = array( $url, untrailingslashit( $url ) );
        if ( false === strpos( $url, '?' ) ) {
            $variants[] = trailingslashit( $url );
        }
        foreach ( array_filter( $variants ) as $v ) {
            self::$url_queue[ $v ] = true; // keyed set = free dedup
        }

        if ( ! self::$flush_hooked ) {
            self::$flush_hooked = true;
            add_action( 'shutdown', array( __CLASS__, 'flush_url_queue' ), 20 );
        }
        return true;
    }

    /**
     * Shutdown flush of the queued URL purges. One pass, deduped, chunked to
     * URLS_PER_CALL, capped at `easyopt_cf_max_purge_calls` API calls per
     * request (default 2 = 60 URLs). When a burst exceeds the cap we escalate
     * ONCE to a full zone purge instead of dropping URLs — stale edge copies
     * are worse than one broad purge, and it's a single API call.
     *
     * @since 2.3.3
     */
    public static function flush_url_queue() {
        if ( self::$purged_all || empty( self::$url_queue ) || ! self::is_configured() ) {
            self::$url_queue = array();
            return;
        }

        $urls            = array_keys( self::$url_queue );
        self::$url_queue = array();

        $max_calls = max( 1, (int) apply_filters( 'easyopt_cf_max_purge_calls', 2 ) );

        if ( count( $urls ) > ( $max_calls * self::URLS_PER_CALL ) ) {
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::info( 'cloudflare', sprintf(
                    '%d URL variants queued exceeds the per-request cap (%d calls × %d URLs) — escalating to a single zone purge instead.',
                    count( $urls ), $max_calls, self::URLS_PER_CALL
                ) );
            }
            self::purge_all();
            return;
        }

        foreach ( array_chunk( $urls, self::URLS_PER_CALL ) as $chunk ) {
            self::api_post( 'purge_cache', array( 'files' => array_values( $chunk ) ) );
        }
    }

    /* ─────────────────────────────────────────────
     *  HTTP transport
     * ───────────────────────────────────────────── */

    private static function api_post( $endpoint, $payload ) {

        $token   = trim( (string) EasyOpt_Config::get( 'cf_api_token', '' ) );
        $zone_id = trim( (string) EasyOpt_Config::get( 'cf_zone_id', '' ) );
        if ( '' === $token || '' === $zone_id ) {
            return new WP_Error( 'easyopt_cf_missing_creds', __( 'Cloudflare credentials are not configured.', 'easy-optimizer' ) );
        }

        $url = self::API_BASE . 'zones/' . rawurlencode( $zone_id ) . '/' . $endpoint;

        $response = wp_remote_post( $url, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $body ) ? $body : new WP_Error( 'easyopt_cf_bad_response', __( 'Unexpected Cloudflare response.', 'easy-optimizer' ) );
    }

    /** Verifies token + zone access by calling the zone-details endpoint. */
    public static function api_verify() {

        $token   = trim( (string) EasyOpt_Config::get( 'cf_api_token', '' ) );
        $zone_id = trim( (string) EasyOpt_Config::get( 'cf_zone_id', '' ) );

        return self::api_verify_with( $token, $zone_id );
    }

    /**
     * Verify with explicit credentials. Used by the REST test endpoint so
     * users can test BEFORE saving, avoiding the "save first" confusion.
     *
     * @param string $token   Cloudflare API token.
     * @param string $zone_id Cloudflare Zone ID.
     * @return array|WP_Error API response or error.
     */
    public static function api_verify_with( $token, $zone_id ) {

        $token   = trim( (string) $token );
        $zone_id = trim( (string) $zone_id );
        if ( '' === $token || '' === $zone_id ) {
            return new WP_Error( 'easyopt_cf_missing_creds', __( 'Token or Zone ID missing.', 'easy-optimizer' ) );
        }

        $response = wp_remote_get( self::API_BASE . 'zones/' . rawurlencode( $zone_id ), array(
            'timeout' => 12,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['success'] ) ) {
            $msg = isset( $body['errors'][0]['message'] ) ? $body['errors'][0]['message'] : __( 'Cloudflare rejected the request.', 'easy-optimizer' );
            return new WP_Error( 'easyopt_cf_failed', $msg );
        }
        return $body;
    }

    /* ─────────────────────────────────────────────
     *  AJAX
     * ───────────────────────────────────────────── */

    public static function ajax_test_connection() {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easy-optimizer' ) ), 403 );
        }
        check_ajax_referer( 'easyopt_nonce', 'nonce' );

        $result = self::api_verify();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        $name = isset( $result['result']['name'] ) ? (string) $result['result']['name'] : '';
        $plan = isset( $result['result']['plan']['name'] ) ? (string) $result['result']['plan']['name'] : '';
        wp_send_json_success( array(
            'message' => sprintf(
                /* translators: 1: zone name, 2: plan name */
                __( 'Connected to %1$s (%2$s plan).', 'easy-optimizer' ),
                $name ?: 'Cloudflare',
                $plan ?: '—'
            ),
            'name'    => $name,
            'plan'    => $plan,
        ) );
    }

    public static function ajax_purge_now() {

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'easy-optimizer' ) ), 403 );
        }
        check_ajax_referer( 'easyopt_nonce', 'nonce' );

        if ( ! self::is_configured() ) {
            wp_send_json_error( array( 'message' => __( 'Cloudflare is not configured.', 'easy-optimizer' ) ) );
        }
        if ( self::purge_all() ) {
            wp_send_json_success( array( 'message' => __( 'Cloudflare cache purged.', 'easy-optimizer' ) ) );
        }
        wp_send_json_error( array( 'message' => __( 'Cloudflare purge failed — check credentials.', 'easy-optimizer' ) ) );
    }
}
