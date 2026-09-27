<?php
/**
 * FluxCDN helper (2.5.2) — imgproxy edition.
 *
 * Rewrites image URLs to the FluxPress image CDN: Bunny CDN caching in front of
 * an imgproxy instance (running on Bunny Magic Containers) that performs the
 * WebP/AVIF conversion, resizing and compression. Bunny Optimizer is NOT used
 * (testing showed Optimizer will not transform responses produced by an edge
 * script, which the multi-tenant proxy design requires).
 *
 * URL grammar produced by this class (imgproxy signed URLs):
 *
 *   {endpoint}/{signature}/{processing}/plain/{origin-url}
 *
 *   e.g. https://fluxpress.b-cdn.net/AbCd…/rs:fit:2560:0/q:80/f:avif/plain/https://site.com/img.png
 *
 *   - signature  = base64url( HMAC-SHA256( key, salt + "/{processing}/plain/{origin}" ) )
 *   - processing = imgproxy options: rs:fit:{w}:0 (resize, width cap, no upscale),
 *                  q:{quality}, f:{avif|webp} (omitted for auto)
 *   - origin-url = customer's absolute https image URL, appended verbatim
 *
 * AUTHENTICATION is cryptographic: every URL is HMAC-signed with the account's
 * imgproxy key+salt, so (a) only this plugin can generate valid URLs and
 * (b) the transform params cannot be tampered with. imgproxy is additionally
 * locked to registered origins via IMGPROXY_ALLOWED_SOURCES on the container.
 *
 * API key format:  fpcdn_{account}.{key_hex}.{salt_hex}
 *   - account   short id (metering / logs)
 *   - key_hex   imgproxy IMGPROXY_KEY   (hex, >= 16 bytes → >= 32 hex chars)
 *   - salt_hex  imgproxy IMGPROXY_SALT  (hex, >= 16 bytes → >= 32 hex chars)
 * The key material is embedded so the plugin can sign locally with no server
 * round-trip. Until SureCart issues keys, use the bundled test key (see
 * FLUXCDN-IMGPROXY-SETUP.md); its key/salt match the container's env vars.
 *
 * Gate: nothing is rewritten unless a valid API key has been connected
 * (easyopt_fluxcdn_verified = 1) and its account is allowlisted.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EasyOpt_CDN {

	/** Default CDN endpoint (the Bunny pull zone in front of imgproxy). */
	const DEFAULT_ENDPOINT = 'https://cdn.fluxpress.io';

	/**
	 * SureCart store PUBLIC token (pt_…, safe to ship in the plugin — it can
	 * only read/activate licenses, never manage the store). Get it from
	 * app.surecart.com → API → Public Token. Filterable for testing.
	 */
	const SURECART_PUBLIC_TOKEN = 'pt_afCaB4Ck9BQbpPusaopGjHAe';

	/** SureCart public API base. */
	const SURECART_API = 'https://api.surecart.com/';

	/**
	 * FluxPress provisioning endpoint: after a license is validated and
	 * activated, the plugin exchanges it here for imgproxy signing
	 * credentials (see fluxcdn-provision.php, installed on fluxpress.io).
	 */
	const PROVISION_URL = 'https://fluxpress.io/wp-json/fluxcdn/v1/provision';

	public function __construct() {}

	/* ─────────────────────────────────────────────
	 *  SureCart licensing
	 * ───────────────────────────────────────────── */

	/** The effective public token (constant or filter override). */
	private static function sc_public_token() {
		return apply_filters( 'easyopt_fluxcdn_sc_public_token', self::SURECART_PUBLIC_TOKEN );
	}

	/** Signed request to the SureCart public API. Returns decoded body|WP_Error. */
	private static function sc_request( $method, $route, $body = null ) {
		$token = self::sc_public_token();
		if ( '' === $token ) {
			return new WP_Error( 'easyopt_sc_no_token', __( 'SureCart public token is not configured in this build.', 'easy-optimizer' ) );
		}
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		// Retry once on transient failures (network error, 429, 5xx) — SureCart
		// occasionally 404s/times-out on the first hit right after license or
		// activation changes propagate.
		$res  = wp_remote_request( self::SURECART_API . $route, $args );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( is_wp_error( $res ) || 429 === $code || $code >= 500 || 404 === $code ) {
			usleep( 600000 ); // 0.6s backoff
			$res  = wp_remote_request( self::SURECART_API . $route, $args );
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		}
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'easyopt_sc_net', __( 'Could not reach the licensing server. Check your connection and try again.', 'easy-optimizer' ) );
		}
		$json = json_decode( wp_remote_retrieve_body( $res ) );
		if ( $code < 200 || $code >= 300 ) {
			if ( 404 === $code ) {
				return new WP_Error( 'easyopt_sc_404', __( 'License not found. Double-check the key was copied correctly (no extra spaces). If you just purchased, wait a moment and try again.', 'easy-optimizer' ) );
			}
			if ( 429 === $code ) {
				return new WP_Error( 'easyopt_sc_429', __( 'The licensing server is busy. Please wait a few seconds and try again.', 'easy-optimizer' ) );
			}
			$msg = isset( $json->message ) ? (string) $json->message : sprintf( 'Licensing error (HTTP %d).', $code );
			return new WP_Error( 'easyopt_sc_http_' . $code, $msg );
		}
		return $json;
	}

	/**
	 * Full SureCart connect flow:
	 *  1. GET  v1/public/licenses/{key}      → must exist, status active
	 *  2. POST v1/public/activations         → fingerprint = this site URL
	 *  3. POST fluxpress.io …/provision      → exchange for imgproxy key/salt
	 * Returns array{success,message} + on success account/internal_key/activation_id.
	 *
	 * @param string $license_key SureCart license key.
	 */
	public static function sc_connect( $license_key ) {
		// 1) Validate the license.
		$license = self::sc_request( 'GET', 'v1/public/licenses/' . rawurlencode( $license_key ) );
		if ( is_wp_error( $license ) ) {
			return array( 'success' => false, 'message' => sprintf( __( 'License lookup failed: %s', 'easy-optimizer' ), $license->get_error_message() ) );
		}
		if ( empty( $license->id ) ) {
			return array( 'success' => false, 'message' => __( 'That license key was not found.', 'easy-optimizer' ) );
		}
		// SureCart statuses: 'inactive' = valid but not yet activated anywhere
		// (every fresh license starts here), 'active' = has activations,
		// 'revoked' = cancelled/refunded. Only revoked is a hard stop; the
		// activation call below enforces seat limits and everything else.
		if ( isset( $license->status ) && 'revoked' === (string) $license->status ) {
			return array( 'success' => false, 'message' => __( 'This license has been revoked. Please check your subscription or contact support.', 'easy-optimizer' ) );
		}

		// 2) Activate this site. First check whether an activation already
		// exists for this exact fingerprint (from a prior connect or a
		// remove+re-add in the dashboard) and reuse it, so we never create a
		// duplicate that trips the activation limit.
		$fingerprint = esc_url_raw( get_site_url() );
		$activation  = null;
		$other_sites = array();
		$act_count   = -1;
		$act_limit   = -1;
		// (2.5.3) SureCart's PUBLIC token cannot list activations (404) — the
		// old client-side dedupe was silently dead, so every reconnect burned
		// a seat. The provisioning server holds a SECRET token and does the
		// lookup for us: /preflight returns this site's existing activation
		// (if any) plus the other activated sites for the move flow.
		$pf = wp_remote_post(
			apply_filters( 'easyopt_fluxcdn_preflight_url', 'https://fluxpress.io/wp-json/fluxcdn/v1/preflight' ),
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'license_key' => $license_key, 'site' => $fingerprint ) ),
			)
		);
		$pfb = is_wp_error( $pf ) ? null : json_decode( wp_remote_retrieve_body( $pf ), true );
		if ( is_array( $pfb ) && ! empty( $pfb['ok'] ) ) {
			if ( ! empty( $pfb['activation_id'] ) ) {
				$activation     = new stdClass();
				$activation->id = (string) $pfb['activation_id'];
			}
			$other_sites = isset( $pfb['other_sites'] ) ? (array) $pfb['other_sites'] : array();
			$act_count   = isset( $pfb['activations_count'] ) ? (int) $pfb['activations_count'] : -1;
			$act_limit   = isset( $pfb['activation_limit'] ) ? (int) $pfb['activation_limit'] : -1;
		}
		$create = function () use ( $license, $fingerprint ) {
			return self::sc_request(
				'POST',
				'v1/public/activations',
				array(
					'activation' => array(
						'license'     => (string) $license->id,
						'fingerprint' => $fingerprint,
						'name'        => get_bloginfo( 'name' ),
					),
				)
			);
		};
		if ( null === $activation ) {
			$activation = $create();
			$is_limit = is_wp_error( $activation )
				&& ( false !== stripos( $activation->get_error_message(), 'activation' )
					|| false !== stripos( $activation->get_error_message(), 'limit' )
					|| 0 === strpos( $activation->get_error_code(), 'easyopt_sc_http_422' ) );
			if ( $is_limit ) {
				// (2.5.3) No automatic "move" — releasing another site's seat
				// from here proved too surprising. The user disconnects on the
				// other site, or deletes the activation in their SureCart
				// customer dashboard, then connects here.
				return array(
					'success' => false,
					'code'    => 'activation_limit',
					'message' => sprintf(
						/* translators: %s: list of activated site URLs */
						__( 'This license has reached its site limit (currently active on: %s). Disconnect it on that site (Smart Images tab), or remove the activation from your account dashboard on fluxpress.io, then connect here.', 'easy-optimizer' ),
						$other_sites ? implode( ', ', array_map( 'sanitize_text_field', $other_sites ) ) : __( 'another site', 'easy-optimizer' )
					),
				);
			}
		}
		if ( is_wp_error( $activation ) || empty( $activation->id ) ) {
			$msg = is_wp_error( $activation ) ? $activation->get_error_message() : __( 'no activation id returned', 'easy-optimizer' );
			return array( 'success' => false, 'message' => sprintf( __( 'Could not activate this site: %s', 'easy-optimizer' ), $msg ) );
		}

		// 3) Exchange the validated license for imgproxy signing credentials.
		$prov = wp_remote_post(
			apply_filters( 'easyopt_fluxcdn_provision_url', self::PROVISION_URL ),
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'license_key'   => $license_key,
					'activation_id' => (string) $activation->id,
					'site'          => esc_url_raw( get_site_url() ),
				) ),
			)
		);
		$pjson = is_wp_error( $prov ) ? null : json_decode( wp_remote_retrieve_body( $prov ), true );
		$pcode = is_wp_error( $prov ) ? 0 : (int) wp_remote_retrieve_response_code( $prov );
		if ( 200 !== $pcode || empty( $pjson['key'] ) || empty( $pjson['salt'] ) || empty( $pjson['account'] ) ) {
			// Roll back the activation so the seat isn't burned.
			self::sc_request( 'DELETE', 'v1/public/activations/' . rawurlencode( (string) $activation->id ) );
			$detail = is_wp_error( $prov ) ? $prov->get_error_message() : ( isset( $pjson['message'] ) ? $pjson['message'] : 'HTTP ' . $pcode );
			return array( 'success' => false, 'message' => sprintf( __( 'License is valid, but provisioning failed: %s', 'easy-optimizer' ), $detail ) );
		}

		return array(
			'success'       => true,
			'account'       => sanitize_key( $pjson['account'] ),
			'internal_key'  => 'fpcdn_' . sanitize_key( $pjson['account'] ) . '.' . strtolower( preg_replace( '/[^0-9a-fA-F]/', '', $pjson['key'] ) ) . '.' . strtolower( preg_replace( '/[^0-9a-fA-F]/', '', $pjson['salt'] ) ),
			'endpoint'      => isset( $pjson['endpoint'] ) ? esc_url_raw( $pjson['endpoint'] ) : '',
			'activation_id' => (string) $activation->id,
			'message'       => __( 'License activated and FluxCDN provisioned for this site.', 'easy-optimizer' ),
		);
	}

	/**
	 * Release a specific activation id. Checked (M2): a failed DELETE is
	 * queued and retried from admin_init so seats are never silently leaked.
	 */
	public static function sc_release_activation_by_id( $activation_id ) {
		$activation_id = (string) $activation_id;
		if ( '' === $activation_id ) {
			return true;
		}
		$r = self::sc_request( 'DELETE', 'v1/public/activations/' . rawurlencode( $activation_id ) );
		if ( is_wp_error( $r ) && 'easyopt_sc_404' !== $r->get_error_code() ) { // 404 = already gone
			$q = get_option( 'easyopt_fluxcdn_pending_release', array() );
			if ( ! is_array( $q ) ) { $q = array(); }
			$q[ $activation_id ] = time();
			update_option( 'easyopt_fluxcdn_pending_release', $q, false );
			return false;
		}
		return true;
	}

	/** Release this site's activation on disconnect. */
	public static function sc_release_activation() {
		$aid = (string) EasyOpt_Config::get( 'fluxcdn_activation_id', '' );
		if ( '' !== $aid ) {
			self::sc_release_activation_by_id( $aid );
		}
	}

	/** Retry any queued (failed) activation releases. Called from admin_init (throttled). */
	public static function retry_pending_releases() {
		$q = get_option( 'easyopt_fluxcdn_pending_release', array() );
		if ( ! is_array( $q ) || empty( $q ) ) {
			return;
		}
		foreach ( array_keys( $q ) as $aid ) {
			$r = self::sc_request( 'DELETE', 'v1/public/activations/' . rawurlencode( (string) $aid ) );
			if ( ! is_wp_error( $r ) || 'easyopt_sc_404' === $r->get_error_code() ) {
				unset( $q[ $aid ] );
			}
		}
		if ( empty( $q ) ) {
			delete_option( 'easyopt_fluxcdn_pending_release' );
		} else {
			update_option( 'easyopt_fluxcdn_pending_release', $q, false );
		}
	}

	/**
	 * Tell fluxpress.io this site disconnected (H3): the zone origin is
	 * swapped to the graceful redirector + purged, so we stop paying for a
	 * churned customer's bandwidth. Best-effort; reconnect heals the zone.
	 */
	public static function deprovision() {
		$key = (string) EasyOpt_Config::get( 'fluxcdn_license_key', '' );
		if ( '' === $key ) {
			return;
		}
		wp_remote_post(
			apply_filters( 'easyopt_fluxcdn_deprovision_url', 'https://fluxpress.io/wp-json/fluxcdn/v1/deprovision' ),
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'license_key' => $key, 'site' => esc_url_raw( get_site_url() ) ) ),
			)
		);
	}

	/**
	 * Ask fluxpress.io to purge this license's CDN zone cache (M9): called
	 * when a transform setting (quality/format/max-width) changes, so stale
	 * variants don't linger and double the cache footprint.
	 */
	public static function remote_purge() {
		$key = (string) EasyOpt_Config::get( 'fluxcdn_license_key', '' );
		if ( '' === $key ) {
			return;
		}
		wp_remote_post(
			apply_filters( 'easyopt_fluxcdn_purge_url', 'https://fluxpress.io/wp-json/fluxcdn/v1/purge' ),
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'license_key' => $key, 'site' => esc_url_raw( get_site_url() ) ) ),
			)
		);
	}

	/**
	 * M9: signature of the transform settings. When it changes (admin_init,
	 * throttled), the CDN zone is purged and the local page cache cleared.
	 */
	public static function transform_signature() {
		return md5( wp_json_encode( array(
			(int) EasyOpt_Config::get( 'fluxcdn_quality', 0 ),
			(string) EasyOpt_Config::get( 'fluxcdn_format', 'auto' ),
			(int) EasyOpt_Config::get( 'fluxcdn_max_width', 2560 ),
		) ) );
	}

	/* ─────────────────────────────────────────────
	 *  API key / connection state
	 * ───────────────────────────────────────────── */

	/**
	 * Accounts accepted while licensing is in test mode.
	 *
	 * @return string[]
	 */
	public static function allowed_test_accounts() {
		return apply_filters(
			'easyopt_fluxcdn_allowed_accounts',
			array( 'test01', 'test02', 'demo01' )
		);
	}

	/**
	 * Parse an API key: fpcdn_{account}.{key_hex}.{salt_hex}
	 *
	 * @param string $key
	 * @return array{account:string,key:string,salt:string}|null
	 */
	public static function parse_key( $key ) {
		$key = trim( (string) $key );
		if ( ! preg_match( '/^fpcdn_([a-z0-9][a-z0-9_-]{2,31})\.([0-9a-fA-F]{32,128})\.([0-9a-fA-F]{32,128})$/', $key, $m ) ) {
			return null;
		}
		return array(
			'account' => $m[1],
			'key'     => strtolower( $m[2] ),
			'salt'    => strtolower( $m[3] ),
		);
	}

	/**
	 * EXACT host comparison against this site (www-variant tolerant).
	 * Replaces the old stripos() substring checks, which matched hostile
	 * URLs like https://mysite.com.evil.com/… (H6).
	 */
	public static function same_host( $url ) {
		static $hosts = null;
		if ( null === $hosts ) {
			$h = strtolower( (string) wp_parse_url( get_site_url(), PHP_URL_HOST ) );
			$hosts = array( $h, 0 === strpos( $h, 'www.' ) ? substr( $h, 4 ) : 'www.' . $h );
		}
		$uh = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		return '' !== $uh && in_array( $uh, $hosts, true );
	}

	/** Normalize a site URL for fingerprint comparison: host+path, no scheme/www/trailing slash. */
	public static function norm_site( $url ) {
		$p = wp_parse_url( strtolower( trim( (string) $url ) ) );
		$h = isset( $p['host'] ) ? $p['host'] : '';
		if ( 0 === strpos( $h, 'www.' ) ) {
			$h = substr( $h, 4 );
		}
		return untrailingslashit( $h . ( isset( $p['path'] ) ? $p['path'] : '' ) );
	}

	/** Guard a preg_* pass: on PCRE failure (NULL) log + keep the previous HTML (M4). */
	private static function pcre( $result, $fallback, $label ) {
		if ( null === $result ) {
			if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
				EasyOpt_Debug_Log::warn( 'cdn', 'PCRE failure in ' . $label . ' pass (code ' . preg_last_error() . ')' );
			}
			return $fallback;
		}
		return $result;
	}

	/** Site origin (scheme+host) as https, for resolving root-relative URLs. */
	private static function site_base() {
		$home = get_site_url();
		$p    = wp_parse_url( $home );
		$host = isset( $p['host'] ) ? $p['host'] : '';
		return 'https://' . $host;
	}

	/** Effective CDN endpoint (no trailing slash). Per-customer hostname
	 * returned by provisioning (cdn-{hash}.fluxpress.io); DEFAULT as fallback. */
	public static function endpoint() {
		$ep = trim( (string) EasyOpt_Config::get( 'fluxcdn_endpoint', '' ) );
		if ( '' === $ep ) {
			$ep = self::DEFAULT_ENDPOINT;
		}
		return apply_filters( 'easyopt_fluxcdn_endpoint', untrailingslashit( $ep ) );
	}

	/** Is a key stored, parseable, and marked verified? */
	public static function is_connected() {
		if ( 1 !== (int) EasyOpt_Config::get( 'fluxcdn_verified', 0 ) ) {
			return false;
		}
		// Revoked/expired license: stop rewriting everywhere (front-end included),
		// not just on the settings page. The zone also redirects to originals, so
		// images still load — but we don't emit CDN URLs at all.
		if ( get_option( 'easyopt_fluxcdn_revoked' ) ) {
			return false;
		}
		// Site-URL rebinding (H4): the license was activated for a specific
		// site URL. If this install's URL no longer matches (migration, clone,
		// staging copy), stop emitting CDN URLs until the user reconnects —
		// otherwise every DB clone silently keeps consuming the license.
		$fp = (string) EasyOpt_Config::get( 'fluxcdn_fingerprint', '' );
		if ( '' !== $fp && self::norm_site( $fp ) !== self::norm_site( get_site_url() ) ) {
			return false;
		}
		$parsed = self::parse_key( (string) EasyOpt_Config::get( 'fluxcdn_api_key', '' ) );
		if ( null === $parsed ) {
			return false;
		}
		// License mode: credentials were provisioned after a SureCart license
		// was validated + activated — no dev allowlist involved.
		if ( '' !== (string) EasyOpt_Config::get( 'fluxcdn_license_key', '' ) ) {
			return true;
		}
		// Dev/test mode: fpcdn_ key pasted directly must be allowlisted.
		return in_array( $parsed['account'], self::allowed_test_accounts(), true );
	}

	/**
	 * Validate a key and (optionally) probe the CDN end-to-end.
	 *
	 * @param string $key
	 * @param string $endpoint   Optional endpoint override (saved on success).
	 * @param bool   $skip_probe Local-dev mode: accept without a live probe.
	 * @return array{success:bool,message:string,account?:string}
	 */
	public static function verify_key( $key, $endpoint = '', $skip_probe = false ) {
		$parsed = self::parse_key( $key );
		if ( null === $parsed ) {
			return array(
				'success' => false,
				'message' => __( 'That does not look like a valid FluxCDN API key (expected fpcdn_account.key.salt).', 'easy-optimizer' ),
			);
		}
		$license_mode = ( '' !== (string) EasyOpt_Config::get( 'fluxcdn_license_key', '' ) );
		if ( ! $license_mode && ! in_array( $parsed['account'], self::allowed_test_accounts(), true ) ) {
			return array(
				'success' => false,
				'message' => __( 'This key is not recognized. Enter your FluxCDN license key, or a dev test key.', 'easy-optimizer' ),
			);
		}

		$endpoint = untrailingslashit( trim( (string) $endpoint ) );
		if ( '' !== $endpoint && 0 !== strpos( $endpoint, 'https://' ) ) {
			return array(
				'success' => false,
				'message' => __( 'The CDN endpoint must be an https:// URL.', 'easy-optimizer' ),
			);
		}
		$ep = '' !== $endpoint ? $endpoint : self::endpoint();

		if ( ! $skip_probe ) {
			$probe_origin = EASYOPT_URL . 'assets/icons/icon-128x128.png';
			$probe_url    = self::compose_url(
				$probe_origin,
				$parsed,
				$ep,
				array( 'w' => 64, 'q' => 80, 'f' => 'webp' )
			);
			if ( '' === $probe_url ) {
				return array(
					'success' => false,
					'message' => __( 'Could not build a signed test URL from this key.', 'easy-optimizer' ),
				);
			}

			$res  = wp_remote_get(
				$probe_url,
				array(
					'timeout'   => 15,
					'sslverify' => true,
					'headers'   => array( 'Accept' => 'image/webp,image/avif,image/*' ),
				)
			);
			$err  = is_wp_error( $res ) ? $res->get_error_message() : '';
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			$type = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_header( $res, 'content-type' );

			// A brand-new per-customer hostname needs a minute for Bunny to issue
			// its SSL certificate. Don't block connect on that — the zone already
			// exists and the cert lands in the background. Treat SSL/handshake
			// errors as soft-success; only a definite non-image HTTP response
			// (server reachable but misconfigured) is a hard failure.
			$is_ssl = ( '' !== $err && ( false !== stripos( $err, 'ssl' ) || false !== stripos( $err, 'certificate' ) || false !== stripos( $err, 'handshake' ) ) );
			if ( $is_ssl ) {
				return array(
					'success'    => true,
					'account'    => $parsed['account'],
					'ssl_prov'   => true,
					'state'      => 'ssl_pending',
					'message'    => __( 'Connected. Your CDN is finishing its SSL setup in the background (usually 1–3 minutes) — images will start being delivered automatically once it completes.', 'easy-optimizer' ),
				);
			}
			// NON-BLOCKING probe: provisioning already validated the license
			// and created/reset the zone server-side. A failed probe here
			// (SSL still issuing, a stale redirect from prior revoke testing,
			// a cold cache, or a transient network error) must NOT block the
			// connection — delivery self-heals within a minute or two. We
			// connect regardless and surface an informational note.
			if ( 200 === $code && 0 === stripos( $type, 'image/' ) ) {
				return array(
					'success' => true,
					'account' => $parsed['account'],
					'state'   => 'ok',
					'message' => __( 'Connected — a test image was served through your CDN.', 'easy-optimizer' ),
				);
			}
			// 401/403 with the server reachable = the customer's HOST is
			// blocking imgproxy's origin fetch (Imunify360/BitNinja etc.).
			// This will NOT self-heal — surface an actionable diagnosis (H9)
			// instead of "warming up" forever.
			if ( 401 === $code || 403 === $code ) {
				return array(
					'success'    => true,
					'account'    => $parsed['account'],
					'ssl_prov'   => true,
					'state'      => 'origin_blocked',
					'message'    => __( 'Connected, but your hosting firewall appears to be blocking our image fetcher (FluxCDN/1.0). Ask your host to whitelist the "FluxCDN/1.0" user agent — see fluxpress.io/docs/whitelist-fluxcdn. Images serve from your own server until then.', 'easy-optimizer' ),
				);
			}
			return array(
				'success'    => true,
				'account'    => $parsed['account'],
				'ssl_prov'   => true, // treat as "warming up" state in the UI
				'state'      => 'warming',
				'message'    => __( 'Connected. Your CDN is warming up (SSL/first-image can take 1–3 minutes) — images begin delivering automatically once ready. If images do not appear after a few minutes, use Test again.', 'easy-optimizer' ),
			);
		}

		return array(
			'success' => true,
			'account' => $parsed['account'],
			'state'   => 'ok',
			'message' => $skip_probe
				? __( 'Key accepted (probe skipped — dev mode).', 'easy-optimizer' )
				: __( 'Connected! A transformed test image was served through the CDN.', 'easy-optimizer' ),
		);
	}

	/* ─────────────────────────────────────────────
	 *  URL building (imgproxy signed URLs)
	 * ───────────────────────────────────────────── */

	/**
	 * Compose a signed imgproxy URL.
	 *
	 * @param string                          $origin_url Absolute https URL, no query/frag.
	 * @param array{account:string,key:string,salt:string} $parsed  Parsed key.
	 * @param string                          $endpoint   No trailing slash.
	 * @param array<string,scalar>            $opts       Keys: w (width), q (quality), f (format).
	 * @return string  '' if the origin URL is not safely embeddable.
	 */
	private static function compose_url( $origin_url, array $parsed, $endpoint, array $opts ) {
		// Never rewrite URLs whose bytes could be re-encoded in transit.
		// Opt-in (M5): easyopt_fluxcdn_query_urls percent-encodes query
		// strings into the /plain/ segment per imgproxy's escaping rules
		// (?→%3F etc). Default OFF until verified against Bunny's edge path
		// normalization on a live zone — a wrongly-normalized signed path
		// 403s, and skipping is safer than breaking.
		if ( preg_match( '/[?#%\s]|[^\x21-\x7E]/', $origin_url ) ) {
			if ( ! (int) EasyOpt_Config::get( 'fluxcdn_query_urls', 0 ) ) {
				return '';
			}
			if ( preg_match( '/[#\s]|[^\x21-\x7E]/', $origin_url ) ) {
				return ''; // fragments / raw unicode: still skipped
			}
			$origin_url = str_replace( array( '%', '?' ), array( '%25', '%3F' ), $origin_url );
		}
		if ( 0 !== strpos( $origin_url, 'https://' ) ) {
			return '';
		}
		// (2.6.1) PARSER DIFFERENTIAL — '\' and '@' in the authority.
		//
		// The edge guard reads the origin host with WHATWG new URL(); imgproxy
		// is Go and uses net/url. They disagree on both characters:
		//
		//   https://mysite.com\@evil.com/x.jpg
		//     WHATWG → '\' ends the authority like '/'      → mysite.com
		//     Go     → '\' is ordinary, userinfo splits at
		//              the LAST '@'                          → evil.com
		//
		// The guard would authorise our own host while imgproxy fetched
		// somebody else's — an open image proxy on the account's bill. Paid
		// plans sign locally, right here, so this is the only place that can
		// refuse it before a URL is published.
		//
		// Mirrored in fxv3_url_eligible() on the server and in the guard.
		// All three must refuse the same set, or the analyzer promises savings
		// on images that will never be delivered.
		//
		// SCOPED TO THE AUTHORITY. Testing the whole URL refused every "@2x"
		// retina filename — a convention most WordPress themes use — and
		// dropped those images out of delivery entirely. '@' only matters
		// before the first '/', where it separates userinfo from host.
		//
		// '\' stays banned everywhere: WHATWG normalises it to '/' inside a
		// path and Go does not, so it yields a path both sides sign
		// differently even when the host agrees.
		if ( false !== strpos( $origin_url, '\\' ) ) {
			return '';
		}
		$easyopt_after     = substr( $origin_url, 8 ); // past 'https://'
		$easyopt_slash     = strpos( $easyopt_after, '/' );
		$easyopt_authority = ( false === $easyopt_slash )
			? $easyopt_after
			: substr( $easyopt_after, 0, $easyopt_slash );
		if ( false !== strpos( $easyopt_authority, '@' ) ) {
			return '';
		}

		// Build imgproxy processing segment. Order is fixed for stable cache keys.
		$parts = array();
		$w     = isset( $opts['w'] ) ? (int) $opts['w'] : 0;
		if ( $w > 0 ) {
			// rs:fit:{w}:0 — fit within width, height auto, no enlargement
			// (imgproxy does not upscale by default: IMGPROXY_ENABLE_ENLARGE off).
			$parts[] = 'rs:fit:' . $w . ':0';
		}
		$q = isset( $opts['q'] ) ? (int) $opts['q'] : 0;
		if ( $q > 0 ) {
			$parts[] = 'q:' . $q;
		}

		// Format handling (Task 1 — Auto = AVIF→WebP→original):
		//  - 'auto'  → f:avif AS A REQUEST, but imgproxy is configured with
		//              IMGPROXY_ENFORCE_AVIF / AUTO_WEBP so it downgrades to
		//              WebP (or the original) when the *browser's* Accept header
		//              doesn't advertise AVIF. To keep a shared CDN cache correct
		//              across browsers, the zone must Vary on Accept (see setup).
		//              We therefore DON'T pin a format here in auto mode; we let
		//              imgproxy negotiate and emit Vary: Accept.
		//  - 'webp'/'avif' → pin explicitly (power users / testing).
		$fmt = isset( $opts['f'] ) ? (string) $opts['f'] : 'auto';
		if ( 'webp' === $fmt || 'avif' === $fmt ) {
			$parts[] = 'f:' . $fmt;
		}
		// auto → no f: segment; imgproxy AUTO_WEBP/AUTO_AVIF picks per Accept.

		$processing = implode( '/', $parts );
		if ( '' === $processing ) {
			// Smart quality + no width can yield no options; imgproxy still
			// needs a processing segment — rs:fit:0:0 is a documented no-op.
			$processing = 'rs:fit:0:0';
		}

		// Source encoding: use the /plain/ form. It is proven working on the
		// FluxCDN container; the base64 form returned 404s there (root cause
		// on the imgproxy/Bunny side not yet identified). Images working beats
		// URLs looking tidy. Revisit base64 only after it's verified live.
		$path = '/' . $processing . '/plain/' . $origin_url;

		$key  = @hex2bin( $parsed['key'] );
		$salt = @hex2bin( $parsed['salt'] );
		if ( false === $key || false === $salt || '' === $key || '' === $salt ) {
			return '';
		}
		$digest = hash_hmac( 'sha256', $salt . $path, $key, true );
		// Truncated to 6 bytes — container must set IMGPROXY_SIGNATURE_SIZE=6.
		$sig    = rtrim( strtr( base64_encode( substr( $digest, 0, 6 ) ), '+/', '-_' ), '=' );

		return $endpoint . '/' . $sig . $path;
	}

	/**
	 * Build the full CDN URL for an origin image URL, applying configured
	 * transforms. Returns '' when rewriting should be skipped for this URL.
	 *
	 * @param string $origin_url Absolute https origin URL, no query/fragment.
	 * @param int    $width      Intended display width (0 = unknown → max-width cap).
	 */
	public static function build_url( $origin_url, $width = 0 ) {
		// (2.5.4 / perf #9) Per-request memo. Galleries and product grids
		// repeat the same origin URL + width across <img>, <source> and
		// srcset candidates; each build used to redo hex2bin + HMAC-SHA256 +
		// base64. Keyed on the RAW input (before normalization) so the memo
		// check costs one array lookup. build_url() is deterministic within a
		// request: the config context below is already static-cached and
		// rebuilt on key change, which also resets this memo.
		static $memo = array(), $memo_key_guard = null;
		$raw_guard = (string) EasyOpt_Config::get( 'fluxcdn_api_key', '' );
		if ( $memo_key_guard !== $raw_guard ) {
			$memo           = array();
			$memo_key_guard = $raw_guard;
		}
		$memo_k = $origin_url . '|' . (int) $width;
		if ( isset( $memo[ $memo_k ] ) ) {
			return $memo[ $memo_k ];
		}
		if ( count( $memo ) > 2000 ) { // pathological pages: bound memory
			$memo = array();
		}

		// Normalize root-relative (/wp-content/...) and protocol-relative
		// (//host/...) URLs to absolute https so same-origin images written
		// without the domain are still rewritten to the CDN.
		$origin_url = (string) $origin_url;
		if ( 0 === strpos( $origin_url, '//' ) ) {
			$origin_url = 'https:' . $origin_url;
		} elseif ( '' !== $origin_url && '/' === $origin_url[0] && '/' !== ( $origin_url[1] ?? '' ) ) {
			$origin_url = untrailingslashit( self::site_base() ) . $origin_url;
		}

		static $ctx = null;
		static $ctx_key = null;
		$stored_key = (string) EasyOpt_Config::get( 'fluxcdn_api_key', '' );
		if ( $ctx_key !== $stored_key ) { // M7: rebuild if the key changed mid-request
			$ctx     = null;
			$ctx_key = $stored_key;
		}
		if ( null === $ctx ) {
			$parsed = self::parse_key( $stored_key );
			if ( null === $parsed ) {
				$ctx = false;
			} else {
				$ctx = array(
					'parsed'    => $parsed,
					'endpoint'  => self::endpoint(),
					// 0 = Smart: omit q:, the container's IMGPROXY_FORMAT_QUALITY
					// per-format defaults apply (e.g. avif=50, webp=72, jpeg=80).
					'quality'   => (int) EasyOpt_Config::get( 'fluxcdn_quality', 0 ) > 0
						? max( 30, min( 100, (int) EasyOpt_Config::get( 'fluxcdn_quality', 0 ) ) ) : 0,
					'format'    => (string) EasyOpt_Config::get( 'fluxcdn_format', 'auto' ),
					'max_width' => max( 0, min( 4096, (int) EasyOpt_Config::get( 'fluxcdn_max_width', 2560 ) ) ),
				);
			}
		}
		if ( false === $ctx ) {
			$memo[ $memo_k ] = '';
			return '';
		}

		$opts = array( 'q' => $ctx['quality'] );

		$w = (int) $width;
		if ( $w > 0 && $ctx['max_width'] > 0 ) {
			$w = min( $w, $ctx['max_width'] );
		} elseif ( 0 === $w ) {
			$w = $ctx['max_width'];
		}
		if ( $w > 0 ) {
			$opts['w'] = $w;
		}

		// Format: 'auto' → let imgproxy negotiate AVIF/WebP/original via the
		// Accept header (container has AUTO_AVIF/AUTO_WEBP on, zone Varies on
		// Accept). 'webp'/'avif' → pin explicitly.
		$fmt = $ctx['format'];
		if ( 'webp' === $fmt || 'avif' === $fmt ) {
			$opts['f'] = $fmt;
		} else {
			$opts['f'] = 'auto';
		}

		$memo[ $memo_k ] = self::compose_url( $origin_url, $ctx['parsed'], $ctx['endpoint'], $opts );
		return $memo[ $memo_k ];
	}

	/** Does a URL already point at the CDN endpoint? */
	private static function is_cdn_url( $url ) {
		return 0 === strpos( $url, self::endpoint() . '/' );
	}

	/* ─────────────────────────────────────────────
	 *  Buffer rewrite (public API unchanged since 2.x)
	 * ───────────────────────────────────────────── */

	/**
	 * Rewrite image URLs in the HTML buffer to the CDN.
	 *
	 * @param string $html
	 * @return string
	 */
	/**
	 * Elementor integration (2.5.6): inject CDN URLs at CSS GENERATION time
	 * via the elementor/files/css/selectors filter — Elementor's own lifecycle
	 * then maintains them across edits/regenerations. Replaces the previous
	 * approach of mutating files in uploads/elementor/css.
	 *
	 * @param array $control Elementor control definition (selectors map).
	 * @param array $value   Control value; background image URL in $value['url'].
	 * @return array
	 */
	public static function elementor_selectors_filter( $control, $value ) {
		// (2.6.1) any_delivering()/any_sign() so this works on the v3 cloud
		// path. Gating on is_delivering()/build_url() (fluxcdn_* only) meant
		// the filter did nothing on every cloud site, so the Elementor hero
		// background — the LCP element on most builder pages — was never
		// optimized, for free OR paid. The gate is still absolute: nothing is
		// baked into Elementor's generated CSS unless a path is genuinely
		// delivering, and on free a URL not in the signed map returns '' and
		// is left as the original.
		if ( ! (int) EasyOpt_Config::get( 'img_opt', 0 )
			|| ! (int) EasyOpt_Config::get( 'elementor_bg_cdn', 0 )
			|| ! self::any_delivering()
			|| empty( $value['url'] )
			|| empty( $control['selectors'] ) || ! is_array( $control['selectors'] ) ) {
			return $control;
		}
		$cdn = self::any_sign( (string) $value['url'] );
		if ( '' === $cdn ) {
			return $control;
		}
		foreach ( $control['selectors'] as $selector => $css_property ) {
			if ( is_string( $css_property )
				&& 0 === strpos( $css_property, 'background-image' )
				&& false === strpos( $css_property, 'background-color' ) ) {
				$control['selectors'][ $selector ] = str_replace(
					'url("{{URL}}")',
					'url("' . $cdn . '")',
					$css_property
				);
			}
		}
		return $control;
	}

	/**
	 * Rewrite local image url(...) references inside a CSS string to the CDN.
	 * Used for Used CSS output and Elementor CSS files. Only same-origin
	 * https image URLs are touched; fonts, data: URIs and externals are left
	 * alone. Any legacy proxied-URL prefixes are migrated back to the original.
	 *
	 * @param string $css
	 * @return string
	 */
	public static function rewrite_css_urls( $css ) {
		// (2.6.1) any_delivering()/any_sign() so this covers the cloud path
		// too. Previously gated on is_delivering()/build_url(), which are
		// fluxcdn_*-only, so Used CSS and Elementor CSS backgrounds were never
		// rewritten on a v3 cloud site — free or paid.
		if ( ! is_string( $css ) || '' === $css || ! self::any_delivering() ) {
			return $css;
		}
		// Both endpoints so an already-CDN'd URL (either path) is left alone.
		$eps = array();
		foreach ( array( 'fluxcdn_endpoint', 'cloud_endpoint' ) as $k ) {
			$e = (string) EasyOpt_Config::get( $k, '' );
			if ( '' !== $e ) {
				$eps[] = untrailingslashit( $e ) . '/';
			}
		}
		return preg_replace_callback(
			'/url\(\s*(["\']?)([^"\')\s]+)\1\s*\)/i',
			function ( $m ) use ( $eps ) {
				$url = $m[2];
				if ( 0 === stripos( $url, 'data:' ) ) {
					return $m[0];
				}
				foreach ( $eps as $ep ) {
					if ( 0 === strpos( $url, $ep ) ) {
						return $m[0]; // already ours
					}
				}
				// Migrate any legacy proxied-URL prefix back to the original.
				if ( 0 === strpos( $url, 'https://cdn.shortpixel.ai/spai/' ) ) {
					$pos = strpos( $url, '/http' );
					if ( false !== $pos ) {
						$url = substr( $url, $pos + 1 );
					}
				}
				if ( ! EasyOpt_CDN::same_host( $url ) ) { // H6: exact host, not substring
					return $m[0];
				}
				$path = wp_parse_url( $url, PHP_URL_PATH );
				$ext  = $path ? strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) : '';
				if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'avif' ), true ) ) {
					return $m[0];
				}
				$cdn = self::any_sign( $url );
				return '' === $cdn ? $m[0] : 'url(' . $cdn . ')';
			},
			$css
		);
	}

	/**
	 * (a) Global JS fallback, replaces per-tag onerror attributes. Inlined in
	 * <head> (capture-phase: error events don't bubble). The original URL is
	 * recovered from the CDN URL itself (everything after /plain/), so no
	 * per-image markup is needed and late-injected images are covered too.
	 */
	public static function print_fallback_script() {
		// v3 delegation — the cloud endpoint differs, so the recovery
		// script must be built against it, not the legacy zone.
		if ( class_exists( 'EasyOpt_CDN_Cloud' ) && EasyOpt_CDN_Cloud::is_delivering() ) {
			EasyOpt_CDN_Cloud::print_fallback_script();
			return;
		}
		if ( ! (int) EasyOpt_Config::get( 'img_opt', 0 )
			|| ! (int) EasyOpt_Config::get( 'fluxcdn_error_fallback', 1 )
			|| ! self::is_connected() ) {
			return;
		}
		$ep = esc_js( self::endpoint() );
		echo '<script>(function(){var E="' . $ep . '";document.addEventListener("error",function(e){var t=e.target;if(!t||t.tagName!=="IMG"||t.__fx)return;var s=t.currentSrc||t.src||"";if(s.indexOf(E)!==0)return;var i=s.indexOf("/plain/");if(i<0)return;t.__fx=1;t.removeAttribute("srcset");t.removeAttribute("sizes");t.src=s.slice(i+7);},true);})();</script>' . "\n";
	}

	/**
	 * (f) Cached usage/quota from the FluxPress metering endpoint. Returns
	 * array{used_bytes:int,quota_bytes:int}|null. 6h transient; license mode only.
	 */
	public static function usage_status( $force = false ) {
		if ( '' === (string) EasyOpt_Config::get( 'fluxcdn_license_key', '' ) ) {
			return null;
		}
		$t = get_transient( 'easyopt_fluxcdn_usage' );
		if ( ! $force && is_array( $t ) ) {
			return $t;
		}
		// H1: NEVER make a network call from a front-end render. The refresh
		// only runs in wp-admin, cron, or an explicit force (REST panel).
		// Front-end callers get the cached value or null (fail open); the
		// admin_init throttle keeps the cache warm.
		if ( ! $force && ! is_admin() && ! wp_doing_cron() ) {
			return is_array( $t ) ? $t : null;
		}
		// M11: POST — keeps the license key out of access/proxy logs.
		$res  = wp_remote_post(
			apply_filters( 'easyopt_fluxcdn_usage_url', 'https://fluxpress.io/wp-json/fluxcdn/v1/usage' ),
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'license_key' => (string) EasyOpt_Config::get( 'fluxcdn_license_key', '' ),
					'site'        => esc_url_raw( get_site_url() ),
				) ),
			)
		);
		$body = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
		// Server-side license re-check rides on the usage response: if the
		// license was revoked (refund/cancel), disconnect automatically.
		// 'moved' = this license's seat now belongs to a DIFFERENT site (the
		// user connected it elsewhere): stop emitting CDN URLs here too —
		// otherwise N clones keep serving off one seat and drain the
		// legitimate site's quota.
		if ( is_array( $body ) && isset( $body['license_status'] ) && in_array( $body['license_status'], array( 'revoked', 'moved' ), true ) ) {
			// (2.5.4) TRANSITION-ONLY handling. The server keeps answering
			// 'revoked' on every usage check for as long as the license stays
			// revoked — previously each of those answers re-ran this whole
			// branch: a FULL page-cache wipe every check (with the 2.5.4
			// maintenance cron that meant every ~10 minutes, forever) and a
			// refreshed dismissal timestamp that resurrected an already-
			// dismissed admin notice. The easyopt_fluxcdn_revoked option IS
			// the transition marker: absent → this is the moment the license
			// died, do everything once; present → already handled, only keep
			// the moved-flag honest. Reconnecting deletes the marker (REST
			// dashboard), so a future re-revocation is a fresh transition.
			$is_moved = ( 'moved' === $body['license_status'] );
			if ( ! get_option( 'easyopt_fluxcdn_revoked' ) ) {
				EasyOpt_Config::set( 'easyopt_fluxcdn_verified', 0 );
				update_option( 'easyopt_fluxcdn_revoked', time(), false ); // timestamp = dismissal state-stamp
				update_option( 'easyopt_fluxcdn_moved', $is_moved ? 1 : 0, false );
				if ( class_exists( 'EasyOpt_Tracker' ) ) {
					EasyOpt_Tracker::smartimg_fallback( $is_moved ? 'moved' : 'revoked' );
				}
				// Turn the "Enable Real-Time Delivery" toggle OFF so the UI
				// reflects reality (delivery is already hard-stopped by
				// is_connected()). Going through Config::set routes the
				// change through the save-coordinator's FluxCDN branch,
				// which regenerates Elementor CSS and clears Used CSS + LCP
				// rows still carrying signed CDN URLs — exactly the cleanup
				// a dead license needs. The revoked warning notice stays up
				// independently until the user dismisses it.
				if ( (int) EasyOpt_Config::get( 'img_opt', 0 ) ) {
					EasyOpt_Config::set( 'easyopt_img_opt', 0 );
				}
				if ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'clear_all' ) ) {
					EasyOpt_Cache::clear_all();
				}
			} elseif ( $is_moved !== (bool) get_option( 'easyopt_fluxcdn_moved' ) ) {
				update_option( 'easyopt_fluxcdn_moved', $is_moved ? 1 : 0, false );
			}
		}
		$out  = ( is_array( $body ) && isset( $body['used_bytes'], $body['quota_bytes'] ) )
			? array(
				'used_bytes'     => (int) $body['used_bytes'],
				'quota_bytes'    => (int) $body['quota_bytes'],
				'license_status' => isset( $body['license_status'] ) ? (string) $body['license_status'] : '',
			)
			: array( 'used_bytes' => 0, 'quota_bytes' => 0, 'license_status' => '' ); // unknown → fail open
		set_transient( 'easyopt_fluxcdn_usage', $out, HOUR_IN_SECONDS );
		if ( class_exists( 'EasyOpt_Tracker' ) ) {
			EasyOpt_Tracker::smartimg_quota_check( $out['used_bytes'], $out['quota_bytes'] );
		}
		return $out;
	}

	/**
	 * (2.5.3) DELIVERY GATE: connected is not enough — CDN URLs are only
	 * emitted once a probe has CONFIRMED an image serves over HTTPS from
	 * this endpoint (probe_state === 'ok'). Emitting earlier meant every
	 * SSL-provisioning hiccup shipped pages full of un-servable URLs:
	 * ERR_CERT_COMMON_NAME_INVALID kills the TLS handshake before HTTP, so
	 * even the revoke/parking redirect can't reach the browser — CSS
	 * backgrounds broke with no possible fallback. Until the gate opens,
	 * images serve from origin (site perfect, just unoptimized) and a
	 * background re-probe flips the gate automatically.
	 */
	public static function is_delivering() {
		return self::is_connected()
			&& 'ok' === (string) EasyOpt_Config::get( 'fluxcdn_probe_state', '' );
	}

	/* ─────────────────────────────────────────────
	 *  (2.6.1) Cloud-aware signing bridge.
	 *
	 *  The CSS-side rewriters below (Elementor generated CSS, Used CSS) were
	 *  written for the v2.5 per-zone path: they gate on this class's
	 *  is_delivering()/over_quota() and sign with build_url(), all of which
	 *  read fluxcdn_* options. The v3 shared-endpoint (cloud) path stores
	 *  cloud_* instead and never sets fluxcdn_probe_state, so on every cloud
	 *  site — free AND paid — those rewriters silently returned the CSS
	 *  untouched. Elementor's default "External Files" mode puts the hero
	 *  background (usually the LCP element) in a generated CSS file, so it was
	 *  never optimized on cloud.
	 *
	 *  These two helpers pick the right delivery gate and the right signer for
	 *  whichever path is actually live, so one rewriter serves both. The
	 *  legacy per-zone behaviour is unchanged: when cloud is not delivering,
	 *  both fall straight through to the old methods.
	 * ───────────────────────────────────────────── */

	/** Is EITHER delivery path live and within quota right now? */
	public static function any_delivering() {
		// Cloud folds quota into is_delivering(): the server flips cloud_active
		// to 0 when over quota, so there is no separate over_quota() to call.
		if ( class_exists( 'EasyOpt_CDN_Cloud' ) && EasyOpt_CDN_Cloud::is_delivering() ) {
			return true;
		}
		return self::is_delivering() && ! self::over_quota();
	}

	/**
	 * Sign one origin URL through whichever path is delivering.
	 *
	 * Cloud: EasyOpt_CDN_Cloud::cdn_url() — signs on the fly for paid, looks
	 * the URL up in the signed map for free (a miss returns '', which is what
	 * scopes free coverage to the homepage set it actually signed).
	 * Legacy: build_url().
	 *
	 * @return string CDN URL, or '' when this URL is not (or not yet) signable.
	 */
	public static function any_sign( $url, $width = 0 ) {
		if ( class_exists( 'EasyOpt_CDN_Cloud' ) && EasyOpt_CDN_Cloud::is_delivering() ) {
			return (string) EasyOpt_CDN_Cloud::cdn_url( $url, $width );
		}
		return (string) self::build_url( $url, $width );
	}

	/**
	 * Background re-probe (scheduled after connect, retried from admin_init
	 * while not yet 'ok'). When the probe flips to 'ok' it clears the page
	 * cache / Used CSS / Elementor CSS so pages regenerate WITH CDN URLs.
	 */
	public static function reprobe() {
		if ( ! self::is_connected() || 'ok' === (string) EasyOpt_Config::get( 'fluxcdn_probe_state', '' ) ) {
			return;
		}
		$r = self::verify_key(
			(string) EasyOpt_Config::get( 'fluxcdn_api_key', '' ),
			(string) EasyOpt_Config::get( 'fluxcdn_endpoint', '' ),
			false
		);
		$state = isset( $r['state'] ) ? (string) $r['state'] : 'warming';
		EasyOpt_Config::set( 'easyopt_fluxcdn_probe_state', $state );
		if ( class_exists( 'EasyOpt_Tracker' ) ) {
			EasyOpt_Tracker::smartimg_state( $state );
		}
		if ( 'ok' === $state ) {
			if ( class_exists( 'EasyOpt_Unused_CSS' ) && method_exists( 'EasyOpt_Unused_CSS', 'clear_used_css_only' ) ) {
				EasyOpt_Unused_CSS::clear_used_css_only();
			}
			if ( (int) EasyOpt_Config::get( 'elementor_bg_cdn', 0 ) ) {
				self::regenerate_elementor_css();
			}
			if ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'clear_all' ) ) {
				EasyOpt_Cache::clear_all();
			}
		} elseif ( ! wp_next_scheduled( 'easyopt_fluxcdn_reprobe' ) ) {
			wp_schedule_single_event( time() + 300, 'easyopt_fluxcdn_reprobe' ); // keep retrying
		}
	}

	/**
	 * True when a known quota is exhausted (soft cutoff → serve origin).
	 * H1: reads ONLY the cached transient — zero network cost on renders.
	 * Unknown state fails open (rewrite); the server-side hard cap +
	 * origin-swap are the actual enforcement (see fluxcdn-provision.php).
	 */
	public static function over_quota() {
		$u = get_transient( 'easyopt_fluxcdn_usage' );
		return ( is_array( $u ) && ! empty( $u['quota_bytes'] ) && $u['used_bytes'] >= $u['quota_bytes'] );
	}

	public function modify_content_with_cdn( $html ) {
		// v3 delegation: when a shared-endpoint (cloud) account is connected
		// it owns the rewrite. Legacy per-zone delivery below is untouched
		// and still runs for every site that has not migrated.
		if ( class_exists( 'EasyOpt_CDN_Cloud' ) && EasyOpt_CDN_Cloud::is_delivering() ) {
			return EasyOpt_CDN_Cloud::rewrite( $html );
		}
		if ( ! (int) EasyOpt_Config::get( 'img_opt', 0 ) || empty( $html ) ) {
			return $html;
		}
		if ( ! self::is_delivering() ) {
			return $html; // not connected, or SSL not yet confirmed → serve origin, never break
		}
		if ( self::over_quota() ) {
			return $html; // monthly quota reached → graceful origin fallback
		}
		// WooCommerce dynamic pages — avoid CDN latency on product images
		// during the critical Cart/Checkout purchase flow.
		if ( EasyOpt_Config::is_woo_dynamic_page() ) {
			return $html;
		}

		$old_url       = get_site_url();
		$srcset_resize = (int) EasyOpt_Config::get( 'fluxcdn_srcset_resize', 1 );

		// Parse exclude list once for all callbacks. Entries like ".no-cdn"
		// are class-name shorthand — HTML carries class="no-cdn" without the
		// dot, so match the bare name too (fixes silently-dead exclusions).
		$raw_exclude = (string) EasyOpt_Config::get( 'image_exclude', '' );
		$excludes    = array();
		foreach ( array_filter( array_map( 'trim', explode( "\n", $raw_exclude ) ) ) as $e ) {
			$excludes[] = $e;
			if ( strlen( $e ) > 2 && '.' === $e[0] && false === strpos( $e, '/' ) ) {
				$excludes[] = substr( $e, 1 );
			}
		}

		// M3: lift <script> blocks (inline JS, JSON-LD, JS templates) out of
		// the buffer so none of the URL regexes below can touch code. They
		// are restored verbatim at the end.
		$scripts = array();
		$prev    = $html;
		$html    = self::pcre(
			preg_replace_callback(
				'#<script\b[^>]*>.*?</script>#is',
				function ( $m ) use ( &$scripts ) {
					$scripts[] = $m[0];
					return "\x01EOSC" . ( count( $scripts ) - 1 ) . "\x01";
				},
				$html
			),
			$prev,
			'script-strip'
		);

		// Normalise protocol-relative URLs to absolute.
		$current_domain = wp_parse_url( home_url(), PHP_URL_HOST );
		$scheme         = is_ssl() ? 'https://' : 'http://';
		$prev           = $html;
		$html           = self::pcre(
			preg_replace(
				'/(["\'])\/\/' . preg_quote( $current_domain, '/' ) . '/',
				'$1' . $scheme . $current_domain,
				$html
			),
			$prev,
			'proto-rel'
		);

		// Rewrite one origin URL to the CDN. No-op for off-origin URLs,
		// already-CDN URLs, and URLs build_url() refuses to sign.
		$to_cdn = function ( $url, $width = 0 ) use ( $old_url ) {
			$url = trim( $url );
			if ( '' === $url || self::is_cdn_url( $url ) ) {
				return $url;
			}
			// Accept absolute same-origin URLs, and root-relative (/wp-content/…)
			// or protocol-relative (//host/…) URLs — build_url() absolutizes the
			// relative forms. H6: EXACT host comparison — the old stripos()
			// substring check matched mysite.com.evil.com and query-embedded
			// look-alikes, turning the CDN into an open proxy lever.
			$is_relative = ( '/' === ( $url[0] ?? '' ) );
			if ( ! $is_relative && ! self::same_host( $url ) ) {
				return $url;
			}
			$cdn = self::build_url( $url, $width );
			return '' === $cdn ? $url : esc_url_raw( $cdn );
		};

		// 1) <img> — rewrite src AND srcset/data-srcset. Attribute-scoped
		//    replacement avoids hitting the same URL in other attributes.
		//    srcset "NNNw" descriptors carry the true rendered width, so the
		//    CDN can deliver exactly-sized files per candidate.
		$prev = $html;
		$html = self::pcre( preg_replace_callback(
			'/<img\s+[^>]*src=[\'"]([^\'"]+)[\'"][^>]*>/i',
			function ( $m ) use ( $excludes, $to_cdn, $srcset_resize ) {
				$tag = $m[0];
				$src = $m[1];
				if ( preg_match( '/\.(woff2?|ttf|eot|svg)(\?.*)?$/i', $src ) ) {
					return $tag;
				}
				foreach ( $excludes as $v ) {
					if ( false !== stripos( $tag, $v ) ) {
						return $tag;
					}
				}

				// src (first occurrence only; one per <img>).
				$tag = preg_replace_callback(
					'/(\ssrc\s*=\s*)([\'"])([^\'"]+)\2/i',
					function ( $a ) use ( $to_cdn ) {
						return $a[1] . $a[2] . $to_cdn( $a[3] ) . $a[2];
					},
					$tag,
					1
				);

				// srcset and data-srcset (lazy-load renames srcset→data-srcset
				// after this pass, so its URLs are already CDN-rewritten here).
				$tag = preg_replace_callback(
					'/(\s(?:data-)?srcset\s*=\s*)([\'"])([^\'"]+)\2/i',
					function ( $a ) use ( $to_cdn, $srcset_resize ) {
						$rewritten = array();
						foreach ( explode( ',', $a[3] ) as $cand ) {
							$cand = trim( $cand );
							if ( '' === $cand ) {
								continue;
							}
							// "URL [descriptor]" where descriptor is e.g. 300w or 2x.
							$parts = preg_split( '/\s+/', $cand, 2 );
							$w     = 0;
							if ( $srcset_resize && isset( $parts[1] ) && preg_match( '/^(\d+)w$/', $parts[1], $wm ) ) {
								$w = (int) $wm[1];
							}
							$u           = $to_cdn( $parts[0], $w );
							$rewritten[] = isset( $parts[1] ) ? $u . ' ' . $parts[1] : $u;
						}
						return $a[1] . $a[2] . implode( ', ', $rewritten ) . $a[2];
					},
					$tag
				);

				return $tag;
			},
			$html
		), $prev, 'img' );

		// Collapse duplicate fetchpriority attributes down to the first.
		// Some page builders/themes (e.g. certain Elementor widgets) emit
		// fetchpriority="high" twice on the same <img>, which is invalid HTML.
		// This isn't produced by the CDN pass — it arrives in the source markup
		// — but since we're already rewriting every <img>, clean it up here.
		// Guarded by a cheap count check so the extra regex only runs on pages
		// that actually have a duplicate.
		// 1b) <picture><source srcset> (and any <source srcset> images,
		//     incl. pre-existing WebP variants) — same rewrite as img srcset.
		$prev = $html;
		$html = self::pcre( preg_replace_callback(
			'/<source\s+[^>]*(?:data-)?srcset\s*=\s*[\'"][^\'"]+[\'"][^>]*>/i',
			function ( $m ) use ( $excludes, $to_cdn, $srcset_resize ) {
				$tag = $m[0];
				foreach ( $excludes as $v ) {
					if ( false !== stripos( $tag, $v ) ) {
						return $tag;
					}
				}
				return preg_replace_callback(
					'/(\s(?:data-)?srcset\s*=\s*)([\'"])([^\'"]+)\2/i',
					function ( $a ) use ( $to_cdn, $srcset_resize ) {
						$rewritten = array();
						foreach ( explode( ',', $a[3] ) as $cand ) {
							$cand = trim( $cand );
							if ( '' === $cand ) {
								continue;
							}
							$parts = preg_split( '/\s+/', $cand, 2 );
							$w     = 0;
							if ( $srcset_resize && isset( $parts[1] ) && preg_match( '/^(\d+)w$/', $parts[1], $wm ) ) {
								$w = (int) $wm[1];
							}
							$u           = $to_cdn( $parts[0], $w );
							$rewritten[] = isset( $parts[1] ) ? $u . ' ' . $parts[1] : $u;
						}
						return $a[1] . $a[2] . implode( ', ', $rewritten ) . $a[2];
					},
					$tag
				);
			},
			$html
		), $prev, 'source' );

		if ( substr_count( $html, 'fetchpriority' ) > 1 ) {
			$html = preg_replace_callback(
				'#<img\b[^>]*>#i',
				function ( $m ) {
					$tag = $m[0];
					if ( substr_count( strtolower( $tag ), 'fetchpriority' ) < 2 ) {
						return $tag;
					}
					// Keep the first fetchpriority=... occurrence, drop the rest.
					$seen = false;
					return preg_replace_callback(
						'/\sfetchpriority\s*=\s*(["\'])[^"\']*\1/i',
						function ( $fm ) use ( &$seen ) {
							if ( $seen ) {
								return ''; // remove subsequent duplicates
							}
							$seen = true;
							return $fm[0]; // keep the first
						},
						$tag
					);
				},
				$html
			);
		}
		$prev = $html;
		$html = self::pcre( preg_replace_callback(
			'/background(?:-image)?\s*:[^;\"\'{}]*?url\((["\']?)([^"\')]+)\1\)/i',
			function ( $m ) use ( $excludes, $to_cdn ) {
				$original = $m[0];
				$url      = $m[2];
				foreach ( $excludes as $v ) {
					if ( false !== stripos( $original, $v ) ) {
						return $original;
					}
				}
				if ( self::same_host( $url ) ) { // H6: exact host, not substring
					$cdn = $to_cdn( $url );
					return ( $cdn === trim( $url ) ) ? $original : 'background-image: url(' . $cdn . ')';
				}
				return $original;
			},
			$html
		), $prev, 'background' );

		// 3) data-bg attributes
		$prev = $html;
		$html = self::pcre( preg_replace_callback(
			'/data-bg\s*=\s*([\'"])([^\'"]+)\1/i',
			function ( $m ) use ( $excludes, $to_cdn ) {
				$attr = $m[0];
				$url  = $m[2];
				foreach ( $excludes as $v ) {
					if ( false !== stripos( $attr, $v ) ) {
						return $attr;
					}
				}
				if ( self::same_host( $url ) ) { // H6
					$cdn = $to_cdn( $url );
					return ( $cdn === trim( $url ) ) ? $attr : 'data-bg="' . $cdn . '"';
				}
				return $attr;
			},
			$html
		), $prev, 'data-bg' );

		// M3: restore the lifted <script> blocks verbatim.
		if ( $scripts ) {
			$html = preg_replace_callback(
				'/\x01EOSC(\d+)\x01/',
				function ( $m ) use ( $scripts ) {
					return isset( $scripts[ (int) $m[1] ] ) ? $scripts[ (int) $m[1] ] : '';
				},
				$html
			);
		}

		return $html;
	}

	/* ─────────────────────────────────────────────
	 *  Elementor Background Images CDN
	 * ───────────────────────────────────────────── */

	/**
	 * Trigger Elementor CSS regeneration. This clears Elementor's CSS
	 * cache so it rewrites all CSS files from scratch. After
	 * regeneration, if the Elementor BG CDN setting is enabled,
	 * the files will be reprocessed on the next run.
	 */
	public static function regenerate_elementor_css() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}
		$instance = \Elementor\Plugin::$instance;
		if ( isset( $instance->files_manager ) && method_exists( $instance->files_manager, 'clear_cache' ) ) {
			$instance->files_manager->clear_cache();
			return true;
		}
		return false;
	}
}
