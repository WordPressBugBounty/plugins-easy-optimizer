<?php
/**
 * FluxPress Adaptive Images — v3 cloud client.
 *
 * NEW CLASS, ADDED ALONGSIDE EasyOpt_CDN. Nothing in EasyOpt_CDN is removed
 * or changed except one three-line delegation at the top of
 * modify_content_with_cdn() (see PATCH-NOTES.md) — per constraint §8.1,
 * features are collapsed or re-labelled, never deleted. A site already
 * connected to the v2.5 per-zone service keeps working exactly as before;
 * this class only acts once a v3 account exists.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * WHAT IS DIFFERENT FROM EasyOpt_CDN
 *
 *   URL shape   {endpoint}/{account}/{sig}/{processing}/plain/{origin}
 *               The account is a path prefix, so one shared pull zone
 *               serves every tenant. No per-tenant hostname means no
 *               certificate, which means the entire probe gate
 *               (probe_state, warming, ssl_pending, reprobe) is gone —
 *               delivery starts the moment connect returns.
 *
 *   FREE tier   The plugin holds NO signing key. It asks the server for a
 *               bounded batch of pre-signed URLs and substitutes them.
 *               There is nothing in wp_options to extract, so a
 *               scope-limited free tier is actually enforceable.
 *
 *   PAID tier   Signs locally, exactly as v2.5 did. Metered by bytes, so
 *               there is no scope for a local key to circumvent, and a
 *               10,000-image site cannot round-trip on every render.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE DEGRADATION CONTRACT IS UNCHANGED AND NON-NEGOTIABLE
 *
 * If anything here fails — no map, stale map, quota exhausted, server
 * unreachable, malformed response — every affected image falls back to its
 * original URL. The site is perfect, just unoptimized. There is no path
 * through this file that can emit a URL we are not confident resolves.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EasyOpt_CDN_Cloud {

	/** Control plane. */
	const API = 'https://fluxpress.io/wp-json/fluxcdn/v3/';

	/** Signed-URL map for free accounts. Data, not configuration. */
	const MAP_OPTION = 'easyopt_cloud_map';

	/** Widths requested for free accounts, matching common WP srcset sizes. */
	const FREE_WIDTHS = array( 480, 768, 1024, 1600, 2560 );

	/* ─────────────────────────────────────────────
	 *  Identity and state
	 * ───────────────────────────────────────────── */

	/**
	 * Stable, anonymous install id.
	 *
	 * SHA-256 over the normalised host plus AUTH_SALT — the same shape
	 * EasyOpt_Tracker::site_id() uses, so the funnel can be joined without
	 * either side transmitting a site URL it did not already send.
	 */
	public static function install_hash() {
		$host = strtolower( (string) wp_parse_url( get_site_url(), PHP_URL_HOST ) );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'easyopt';
		return hash( 'sha256', rtrim( $host, '.' ) . $salt );
	}

	public static function account() {
		return (string) EasyOpt_Config::get( 'cloud_account', '' );
	}

	public static function token() {
		return (string) EasyOpt_Config::get( 'cloud_token', '' );
	}

	public static function plan() {
		$p = (string) EasyOpt_Config::get( 'cloud_plan', '' );
		// 'trial' is the default for a connected Account with no stored plan:
		// every new Account is created as a Trial, and treating an unknown
		// plan as paid would hand it the local signing path it has no key for.
		return '' !== $p ? $p : 'trial';
	}

	/**
	 * Unpaid Account: Free Trial, or a grandfathered Free Plan row.
	 *
	 * This gates a MECHANISM, not a price. Unpaid Accounts never hold key
	 * material — they ask the service for a bounded batch of already-signed
	 * URLs — and that is true of a Trial exactly as it was of the Free Plan.
	 * Every call site below means "unpaid", which is why the old is_free()
	 * name had to go: a Trial is not free, it is temporary.
	 */
	public static function is_unpaid() {
		return in_array( self::plan(), array( 'trial', 'free' ), true );
	}

	/** @deprecated 2.7.0 Use is_unpaid(). Kept so nothing external breaks. */
	public static function is_free() {
		return self::is_unpaid();
	}

	/** Is this Account inside a Free Trial (as opposed to a legacy Free Plan)? */
	public static function is_trial() {
		return 'trial' === self::plan();
	}

	/**
	 * Whole days left in the Trial, or 0 when there is no Trial running.
	 * Read from the last /cloud/usage payload; the service is authoritative
	 * and we never compute an expiry locally.
	 */
	public static function trial_days_left() {
		$u = get_transient( 'easyopt_cloud_usage' );
		return is_array( $u ) ? max( 0, (int) ( $u['trial_days_left'] ?? 0 ) ) : 0;
	}

	/** Has the Trial lapsed? Only true once the service says so. */
	public static function trial_expired() {
		$u = get_transient( 'easyopt_cloud_usage' );
		return is_array( $u ) && 'trial_expired' === (string) ( $u['reason'] ?? '' );
	}

	public static function endpoint() {
		$ep = trim( (string) EasyOpt_Config::get( 'cloud_endpoint', '' ) );
		return $ep ? untrailingslashit( $ep ) : '';
	}

	/** A v3 account exists and is bound to THIS site. */
	public static function is_connected() {
		if ( '' === self::account() || '' === self::endpoint() ) {
			return false;
		}
		// Site-URL rebinding: a clone, staging copy or migration must not
		// silently keep consuming the original site's allowance. Same guard
		// EasyOpt_CDN applies, for the same reason.
		$fp = (string) EasyOpt_Config::get( 'cloud_fingerprint', '' );
		if ( '' !== $fp && EasyOpt_CDN::norm_site( $fp ) !== EasyOpt_CDN::norm_site( get_site_url() ) ) {
			return false;
		}
		// Free needs a token (it cannot sign); paid needs key material.
		return self::is_unpaid()
			? '' !== self::token()
			: ( '' !== (string) EasyOpt_Config::get( 'cloud_key', '' ) );
	}

	/**
	 * Delivery gate.
	 *
	 * Deliberately much simpler than EasyOpt_CDN::is_delivering(): there is
	 * no probe_state to consult because there is no per-tenant certificate
	 * to wait for. Constraint §8.8 conditioned the probe gate explicitly on
	 * per-tenant zones existing — under a shared endpoint the TLS handshake
	 * is already proven by every other tenant on the same hostname.
	 */
	public static function is_delivering() {
		return self::is_connected()
			&& 1 === (int) EasyOpt_Config::get( 'cloud_active', 0 )
			&& (int) EasyOpt_Config::get( 'img_opt', 0 );
	}

	/* ─────────────────────────────────────────────
	 *  Transport
	 * ───────────────────────────────────────────── */

	/**
	 * POST to the control plane. Never throws, never fatals; a failure is
	 * always null and every caller treats null as "serve origin".
	 */
	private static function post( $route, array $body, $timeout = 15 ) {
		$res = wp_remote_post(
			apply_filters( 'easyopt_cloud_api', self::API ) . ltrim( $route, '/' ),
			array(
				'timeout' => $timeout,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		return is_array( $json ) ? $json : null;
	}

	/* ─────────────────────────────────────────────
	 *  Signing
	 * ───────────────────────────────────────────── */

	/**
	 * PAID ONLY — local signing, path-scoped.
	 *
	 * Eligibility rules are deliberately identical to
	 * EasyOpt_CDN::compose_url(): if client and server ever disagree about
	 * what is signable, the analyzer promises savings on images that will
	 * never be delivered.
	 */
	public static function sign( $origin_url, $width = 0 ) {
		static $ctx = null, $ctx_guard = null;
		$guard = self::account() . '|' . (string) EasyOpt_Config::get( 'cloud_key', '' ) . '|' . self::endpoint();
		if ( $ctx_guard !== $guard ) {
			$ctx = null;
			$ctx_guard = $guard;
		}
		if ( null === $ctx ) {
			$key  = @hex2bin( strtolower( preg_replace( '/[^0-9a-fA-F]/', '', (string) EasyOpt_Config::get( 'cloud_key', '' ) ) ) );
			$salt = @hex2bin( strtolower( preg_replace( '/[^0-9a-fA-F]/', '', (string) EasyOpt_Config::get( 'cloud_salt', '' ) ) ) );
			$ctx  = ( $key && $salt ) ? array(
				'key'       => $key,
				'salt'      => $salt,
				'account'   => self::account(),
				'endpoint'  => self::endpoint(),
				'quality'   => (int) EasyOpt_Config::get( 'fluxcdn_quality', 0 ),
				'format'    => (string) EasyOpt_Config::get( 'fluxcdn_format', 'auto' ),
				'max_width' => max( 0, min( 4096, (int) EasyOpt_Config::get( 'fluxcdn_max_width', 2560 ) ) ),
			) : false;
		}
		if ( false === $ctx ) {
			return '';
		}

		$origin_url = self::absolutize( $origin_url );
		if ( 0 !== strpos( $origin_url, 'https://' ) ) {
			return '';
		}
		// Same refusal set as the server's fxv3_url_eligible().
		if ( preg_match( '/[?#%\s]|[^\x21-\x7E]/', $origin_url ) ) {
			return '';
		}
		// (2.6.1) '\' and '@' too — the edge guard (WHATWG new URL) and
		// imgproxy (Go net/url) resolve the host of
		// https://mysite.com\@evil.com/x.jpg differently, so a locally-signed
		// URL could pass the guard as ours and be fetched from someone else's
		// server. Refused identically in fxv3_url_eligible(),
		// EasyOpt_CDN::compose_url() and the guard.
		if ( preg_match( '/[\\\\@]/', $origin_url ) ) {
			return '';
		}

		$w = (int) $width;
		if ( $w > 0 && $ctx['max_width'] > 0 ) {
			$w = min( $w, $ctx['max_width'] );
		} elseif ( 0 === $w ) {
			$w = $ctx['max_width'];
		}

		$parts = array();
		if ( $w > 0 ) {
			$parts[] = 'rs:fit:' . $w . ':0';
		}
		if ( $ctx['quality'] > 0 ) {
			$parts[] = 'q:' . max( 30, min( 100, $ctx['quality'] ) );
		}
		if ( 'webp' === $ctx['format'] || 'avif' === $ctx['format'] ) {
			$parts[] = 'f:' . $ctx['format'];
		}
		$processing = $parts ? implode( '/', $parts ) : 'rs:fit:0:0';

		$path   = '/' . $processing . '/plain/' . $origin_url;
		$digest = hash_hmac( 'sha256', $ctx['salt'] . $path, $ctx['key'], true );
		$sig    = rtrim( strtr( base64_encode( substr( $digest, 0, 6 ) ), '+/', '-_' ), '=' );

		return $ctx['endpoint'] . '/' . $ctx['account'] . '/' . $sig . $path;
	}

	/**
	 * Normalise an image URL to absolute https.
	 *
	 * Handles the four forms WordPress content actually contains:
	 *   /wp-content/…      root-relative
	 *   //host/wp-content/ protocol-relative
	 *   http://host/…      insecure, same host
	 *   https://host/…     already fine
	 *
	 * The http:// case matters more than it looks. Content written before a
	 * site moved to SSL keeps its http:// URLs in the database forever, and
	 * on a real Divi page that was 14 of 56 images silently refused. Since
	 * we only ever upgrade a URL whose host is THIS site, and the site is
	 * itself served over https, there is nothing being fetched that the site
	 * does not already publish over https.
	 */
	public static function absolutize( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || 0 === stripos( $url, 'data:' ) ) {
			return $url;
		}
		if ( 0 === strpos( $url, '//' ) ) {
			return 'https:' . $url;
		}
		if ( '/' === $url[0] && '/' !== ( $url[1] ?? '' ) ) {
			$host = (string) wp_parse_url( get_site_url(), PHP_URL_HOST );
			return 'https://' . $host . $url;
		}
		// Upgrade only when it is our own host AND we serve https ourselves.
		if ( 0 === stripos( $url, 'http://' ) ) {
			$upgraded = 'https://' . substr( $url, 7 );
			if ( EasyOpt_CDN::same_host( $url ) && 0 === stripos( get_site_url(), 'https://' ) ) {
				return $upgraded;
			}
		}
		return $url;
	}

	/* ─────────────────────────────────────────────
	 *  Free-tier signed map
	 * ───────────────────────────────────────────── */

	/**
	 * The cached map: origin URL → [ width => signed URL ].
	 *
	 * Stored as a standalone non-autoloaded option rather than a setting:
	 * it is derived data with a TTL, it can be several KB, and it must
	 * never ride along in EasyOpt_Config::get_all() on every admin render.
	 */
	public static function map() {
		$m = get_option( self::MAP_OPTION );
		return ( is_array( $m ) && isset( $m['urls'] ) && is_array( $m['urls'] ) ) ? $m : array( 'urls' => array(), 'at' => 0, 'ttl' => 0 );
	}

	public static function map_is_stale() {
		$m = self::map();
		return ( time() - (int) $m['at'] ) > max( 600, (int) $m['ttl'] );
	}

	/**
	 * Look a URL up in the map, choosing the smallest signed width that
	 * still covers the requested display width.
	 *
	 * A miss returns '' and the caller leaves the original URL in place —
	 * that is the degradation contract, and it is why an incomplete map is
	 * harmless rather than broken.
	 */
	public static function map_lookup( $origin_url, $width = 0 ) {
		$m = self::map();
		$origin_url = self::absolutize( $origin_url );
		if ( ! isset( $m['urls'][ $origin_url ] ) ) {
			return '';
		}
		$variants = $m['urls'][ $origin_url ];
		if ( ! is_array( $variants ) || empty( $variants ) ) {
			return '';
		}
		// NO WIDTH HINT MEANS FULL SIZE, NOT SMALLEST.
		//
		// A plain <img src> with no srcset descriptor gives us nothing to
		// size from. The first version treated that as "any width qualifies"
		// and then picked the narrowest — so a full-bleed slider image came
		// back as rs:fit:480, visibly soft. Fall back to the configured cap
		// instead, which is what the user set it for.
		//
		// Requesting more than the original has costs nothing: imgproxy runs
		// with enlargement off, so rs:fit:2560 on a 700px file returns 700px.
		$want = (int) $width;
		if ( $want <= 0 ) {
			$want = max( 0, min( 4096, (int) EasyOpt_Config::get( 'fluxcdn_max_width', 2560 ) ) );
		}

		$best  = '';
		$bestw = PHP_INT_MAX;
		foreach ( $variants as $w => $url ) {
			$w = (int) $w;
			// 0 means "uncapped"; only use it if nothing narrower fits.
			if ( 0 === $w ) {
				if ( '' === $best ) {
					$best = $url;
				}
				continue;
			}
			if ( $want <= 0 || $w >= $want ) {
				if ( $w < $bestw ) {
					$bestw = $w;
					$best  = $url;
				}
			}
		}
		if ( '' === $best ) {
			// Requested wider than anything we signed — take the widest.
			$maxw = 0;
			foreach ( $variants as $w => $url ) {
				if ( (int) $w >= $maxw ) {
					$maxw = (int) $w;
					$best = $url;
				}
			}
		}
		return (string) $best;
	}

	/**
	 * Exact-width variant from the free-tier map.
	 *
	 * map_lookup() deliberately picks the nearest width at or above what was
	 * asked for, which is right when substituting a single src. Building a
	 * srcset needs the opposite: the URL for EXACTLY this width, because the
	 * descriptor we print next to it must be true.
	 */
	private static function map_variant( $origin_url, $width ) {
		$m = self::map();
		$origin_url = self::absolutize( $origin_url );
		if ( ! isset( $m['urls'][ $origin_url ] ) || ! is_array( $m['urls'][ $origin_url ] ) ) {
			return '';
		}
		$k = (string) (int) $width;
		return isset( $m['urls'][ $origin_url ][ $k ] ) ? (string) $m['urls'][ $origin_url ][ $k ] : '';
	}

	/**
	 * Build a responsive srcset for one source image.
	 *
	 * WHY THIS EXISTS
	 *
	 * Divi (and plenty of themes) ship an <img> with a SINGLE srcset
	 * candidate, or none at all. The browser then has nothing to choose
	 * from: a slider rendering at 526px downloaded the 2560px file, because
	 * that was the only file on offer. Rewriting that one URL to the CDN
	 * made it smaller, but it was still the wrong size.
	 *
	 * Offering the widths we already sign turns that into the browser's
	 * decision, which is where it belongs.
	 *
	 * TWO RULES THAT KEEP THE DESCRIPTORS HONEST
	 *
	 * 1. Never advertise a width larger than the original file. imgproxy
	 *    runs with enlargement off, so a "2560w" candidate for a 1254px
	 *    original would deliver 1254px under a false label and the browser
	 *    would size its choice on a number that is not true.
	 * 2. Only emit widths we can actually produce — for a free account that
	 *    is the signed set, nothing else.
	 *
	 * `sizes` is deliberately left alone. It describes page layout, which we
	 * cannot see from here; guessing at it could make the choice worse than
	 * the browser's 100vw default.
	 *
	 * @param string $origin_url Absolute origin URL.
	 * @param int    $intrinsic  The file's real width, when known (the <img>
	 *                           width attribute). 0 = unknown.
	 * @return string srcset value, or '' when there is nothing useful to add.
	 */
	public static function srcset_for( $origin_url, $intrinsic = 0 ) {
		$origin_url = self::absolutize( $origin_url );
		if ( '' === $origin_url || ! EasyOpt_CDN::same_host( $origin_url ) ) {
			return '';
		}

		$cap = max( 0, min( 4096, (int) EasyOpt_Config::get( 'fluxcdn_max_width', 2560 ) ) );
		$out = array();

		// The file's own width is the most useful top candidate: it is the
		// largest size that is real. Without it a 746px original produced
		// only one usable rung (480) and was left alone entirely.
		//
		// A free account can only offer widths it has already had signed, so
		// map_variant() returns '' for this one and it is quietly dropped —
		// which is exactly the "paid sizes every candidate exactly" line the
		// panel promises. Paid signs it on demand.
		$ladder = self::FREE_WIDTHS;
		if ( $intrinsic > 0 && ! in_array( $intrinsic, $ladder, true ) ) {
			$ladder[] = $intrinsic;
		}

		foreach ( $ladder as $w ) {
			if ( $cap > 0 && $w > $cap ) {
				continue;
			}
			// Rule 1 — never claim more than the file actually has.
			if ( $intrinsic > 0 && $w > $intrinsic ) {
				continue;
			}
			$url = self::is_unpaid() ? self::map_variant( $origin_url, $w ) : self::sign( $origin_url, $w );
			if ( '' !== $url ) {
				$out[ $w ] = esc_url_raw( $url ) . ' ' . $w . 'w';
			}
		}

		// A single candidate is no better than what we started with.
		if ( count( $out ) < 2 ) {
			return '';
		}
		ksort( $out );
		return implode( ', ', $out );
	}

	/**
	 * Ask the server to sign this site's images.
	 *
	 * Called from admin/cron only — never from a front-end render, per
	 * constraint §8.10. A visitor hitting a cold map simply sees original
	 * images until the next admin page load or cron tick refreshes it.
	 *
	 * @param string[] $origins Full-size original URLs.
	 */
	public static function map_refresh( array $origins ) {
		if ( ! self::is_connected() || ! self::is_unpaid() ) {
			return false;
		}
		$origins = array_values( array_unique( array_filter( array_map( array( __CLASS__, 'absolutize' ), $origins ) ) ) );
		if ( empty( $origins ) ) {
			return false;
		}
		$resp = self::post(
			'sign',
			array(
				'token'     => self::token(),
				'urls'      => array_slice( $origins, 0, 200 ),
				'widths'    => self::FREE_WIDTHS,
				'quality'   => (int) EasyOpt_Config::get( 'fluxcdn_quality', 0 ),
				'format'    => (string) EasyOpt_Config::get( 'fluxcdn_format', 'auto' ),
			),
			20
		);
		if ( ! $resp || empty( $resp['ok'] ) ) {
			// Keep the previous map. Replacing a working map with nothing
			// because the server hiccuped would un-optimize a live site.
			return false;
		}

		$urls = ( isset( $resp['urls'] ) && is_array( $resp['urls'] ) ) ? $resp['urls'] : array();
		update_option(
			self::MAP_OPTION,
			array(
				'urls'    => $urls,
				'skipped' => isset( $resp['skipped'] ) && is_array( $resp['skipped'] ) ? $resp['skipped'] : array(),
				'at'      => time(),
				'ttl'     => isset( $resp['ttl'] ) ? (int) $resp['ttl'] : HOUR_IN_SECONDS,
			),
			false
		);
		EasyOpt_Config::set( 'easyopt_cloud_active', ! empty( $resp['active'] ) ? 1 : 0 );
		EasyOpt_Config::set( 'easyopt_cloud_images_used', (int) ( $resp['images_used'] ?? 0 ) );
		EasyOpt_Config::set( 'easyopt_cloud_image_cap', (int) ( $resp['image_cap'] ?? 0 ) );

		// A newly-populated map means generated CSS and cached pages still
		// hold origin URLs — clear them so pages regenerate optimized.
		if ( $urls ) {
			self::flush_generated();
		}
		return true;
	}

	/**
	 * Collect full-size original image URLs from this site's own content.
	 *
	 * Full-size only, on purpose: srcset candidates are separate files, so
	 * signing each would count each against the free image cap and one
	 * photo would consume five of fifty. The server returns several widths
	 * per original instead, and the cap counts the original once.
	 */
	public static function collect_originals( $limit = 60 ) {
		$out = array();
		// The free plan promises "your homepage". An earlier version topped
		// the list up with the 60 most RECENT uploads, which on a real site
		// meant the cap filled with blog images nobody was looking at while
		// the homepage stayed unoptimized — 50 of 50 used, 2 images actually
		// rewritten. The page is now the only source that matters; the media
		// library is a last resort for sites whose homepage yields nothing.

		// HOMEPAGE FIRST. This is the whole point of the free tier and it is
		// easy to get wrong: an earlier version collected the most RECENT
		// uploads instead, which on a real site means blog images nobody is
		// looking at while the homepage hero — usually an older upload, and
		// usually the LCP element — went unoptimized. Source order matters
		// too: the first images in the HTML are the ones above the fold.
		if ( class_exists( 'EasyOpt_REST_Cloud' ) ) {
			// Ask for the full cap, not the analyzer's sample of six — this is
			// the set the site will actually deliver from.
			foreach ( (array) EasyOpt_REST_Cloud::homepage_images( (int) $limit ) as $u ) {
				$out[] = $u;
			}
		}

		// Site logo next — it appears on every page, so it is the single
		// highest-value image after the homepage set.
		$logo = get_theme_mod( 'custom_logo' );
		if ( $logo ) {
			$u = wp_get_attachment_url( $logo );
			if ( $u ) {
				$out[] = $u;
			}
		}

		$out = array_values( array_unique( array_filter( $out ) ) );
		if ( count( $out ) >= 8 ) {
			// The page gave us a real set. Do not pad it with library images
			// the visitor will never request.
			return array_slice( $out, 0, (int) $limit );
		}

		// Fallback only: a homepage that yielded almost nothing (a builder
		// that renders images in JS, or a brand-new site).
		$q = new WP_Query( array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => array( 'image/jpeg', 'image/png', 'image/webp' ),
			'posts_per_page'         => (int) $limit,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'orderby'                => 'date',
			'order'                  => 'DESC',
		) );
		foreach ( (array) $q->posts as $id ) {
			$u = wp_get_attachment_image_url( $id, 'full' );
			if ( $u ) {
				$out[] = $u;
			}
		}

		$out = array_values( array_unique( array_filter( $out ) ) );
		return array_slice( $out, 0, (int) $limit );
	}

	/** Clear anything that has origin URLs baked into it. */
	private static function flush_generated() {
		if ( class_exists( 'EasyOpt_Unused_CSS' ) && method_exists( 'EasyOpt_Unused_CSS', 'clear_used_css_only' ) ) {
			EasyOpt_Unused_CSS::clear_used_css_only();
		}
		if ( class_exists( 'EasyOpt_LCP' ) && method_exists( 'EasyOpt_LCP', 'clear_all' ) ) {
			EasyOpt_LCP::clear_all();
		}
		// (2.6.1) Regenerate Elementor's CSS so its background URLs are rebuilt
		// through the CDN (or back to origin on disconnect). Elementor caches
		// generated CSS on disk and only rebuilds it on its own triggers, so
		// clearing the page cache alone would keep serving stale CSS with the
		// wrong URLs. Gated on the setting the filter itself reads.
		if ( class_exists( 'EasyOpt_CDN' ) && method_exists( 'EasyOpt_CDN', 'regenerate_elementor_css' )
			&& (int) EasyOpt_Config::get( 'elementor_bg_cdn', 0 ) ) {
			EasyOpt_CDN::regenerate_elementor_css();
		}
		if ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'clear_all' ) ) {
			EasyOpt_Cache::clear_all();
		}
	}

	/* ─────────────────────────────────────────────
	 *  URL dispatch
	 * ───────────────────────────────────────────── */

	/**
	 * The one entry point the rewriter uses. Returns '' to mean "leave the
	 * original alone", which is always a safe answer.
	 */
	public static function cdn_url( $origin_url, $width = 0 ) {
		$origin_url = self::absolutize( $origin_url );
		if ( ! EasyOpt_CDN::same_host( $origin_url ) ) {
			return '';
		}
		return self::is_unpaid()
			? self::map_lookup( $origin_url, $width )
			: self::sign( $origin_url, $width );
	}

	/* ─────────────────────────────────────────────
	 *  Buffer rewrite
	 * ───────────────────────────────────────────── */

	/**
	 * Rewrite image URLs in the page buffer.
	 *
	 * Structurally the same passes EasyOpt_CDN uses — <img src/srcset>,
	 * <source srcset>, CSS background-image, data-bg — with two differences:
	 * URLs come from cdn_url() so free and paid share one path, and a miss
	 * is a no-op rather than an error.
	 */
	public static function rewrite( $html ) {
		if ( ! is_string( $html ) || '' === $html || ! self::is_delivering() ) {
			return $html;
		}
		if ( class_exists( 'EasyOpt_Config' ) && EasyOpt_Config::is_woo_dynamic_page() ) {
			return $html;
		}

		$excludes = array();
		$raw      = (string) EasyOpt_Config::get( 'image_exclude', '' );
		foreach ( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) as $e ) {
			$excludes[] = $e;
			if ( strlen( $e ) > 2 && '.' === $e[0] && false === strpos( $e, '/' ) ) {
				$excludes[] = substr( $e, 1 ); // ".no-cdn" also matches class="no-cdn"
			}
		}

		// Lift <script> blocks out so no URL regex can reach inline JS,
		// JSON-LD or JS templates. Restored verbatim at the end.
		$scripts = array();
		$lifted  = preg_replace_callback(
			'#<script\b[^>]*>.*?</script>#is',
			function ( $m ) use ( &$scripts ) {
				$scripts[] = $m[0];
				return "\x01EOCL" . ( count( $scripts ) - 1 ) . "\x01";
			},
			$html
		);
		if ( null === $lifted ) {
			return $html; // PCRE failure — keep the page exactly as it was
		}
		$html = $lifted;

		$srcset_resize = (int) EasyOpt_Config::get( 'fluxcdn_srcset_resize', 1 );

		$to_cdn = function ( $url, $width = 0 ) {
			$url = trim( (string) $url );
			if ( '' === $url ) {
				return $url;
			}
			$cdn = self::cdn_url( $url, $width );
			return '' === $cdn ? $url : esc_url_raw( $cdn );
		};

		$rewrite_srcset = function ( $tag ) use ( $to_cdn, $srcset_resize ) {
			return preg_replace_callback(
				'/(\s(?:data-|data-lazy-)?srcset\s*=\s*)([\'"])([^\'"]+)\2/i',
				function ( $a ) use ( $to_cdn, $srcset_resize ) {
					$out = array();
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
						$u     = $to_cdn( $parts[0], $w );
						$out[] = isset( $parts[1] ) ? $u . ' ' . $parts[1] : $u;
					}
					return $a[1] . $a[2] . implode( ', ', $out ) . $a[2];
				},
				$tag
			);
		};

		// <img>
		$prev = $html;
		$html = preg_replace_callback(
			'/<img\s+[^>]*src=[\'"]([^\'"]+)[\'"][^>]*>/i',
			function ( $m ) use ( $excludes, $to_cdn, $rewrite_srcset ) {
				$tag = $m[0];
				if ( preg_match( '/\.(woff2?|ttf|eot|svg)(\?.*)?$/i', $m[1] ) ) {
					return $tag;
				}
				foreach ( $excludes as $v ) {
					if ( false !== stripos( $tag, $v ) ) {
						return $tag;
					}
				}
				$tag = preg_replace_callback(
					'/(\ssrc\s*=\s*)([\'"])([^\'"]+)\2/i',
					function ( $a ) use ( $to_cdn ) {
						return $a[1] . $a[2] . $to_cdn( $a[3] ) . $a[2];
					},
					$tag,
					1
				);

				// LAZY-LOAD ATTRIBUTES.
				//
				// Divi sliders, and most lazy-load implementations, ship an
				// <img> whose real URL is in data-src and whose src is either
				// absent or a placeholder. Rewriting only `src` left every one
				// of those images unoptimized — 25 of 56 on a real Divi page.
				//
				// The attribute list is explicit rather than a data-*src
				// wildcard: Divi also emits data-dbsrc, which holds a BASE64
				// copy of the URL, and rewriting that would corrupt the tag.
				$tag = preg_replace_callback(
					'/(\s(?:data-src|data-lazy-src|data-orig-src|data-original|data-echo)\s*=\s*)([\'"])([^\'"]+)\2/i',
					function ( $a ) use ( $to_cdn ) {
						return $a[1] . $a[2] . $to_cdn( $a[3] ) . $a[2];
					},
					$tag
				);

				$tag = $rewrite_srcset( $tag );

				// RESPONSIVE CANDIDATES.
				//
				// Only when the tag has fewer than two candidates of its own.
				// A theme that already ships a proper srcset knows its own
				// layout better than we do, and second-guessing it would be
				// a regression waiting to happen.
				if ( preg_match( '/\ssrcset\s*=\s*([\'"])([^\'"]*)\1/i', $tag, $ss ) ) {
					$existing = count( array_filter( array_map( 'trim', explode( ',', $ss[2] ) ) ) );
				} else {
					$existing = 0;
				}

				if ( $existing < 2 ) {
					// The width attribute is the file's intrinsic width, which
					// is what keeps the descriptors honest. Its absence just
					// means we cap by the configured max instead.
					$intrinsic = preg_match( '/\swidth\s*=\s*[\'"]?(\d+)/i', $tag, $wm ) ? (int) $wm[1] : 0;

					// Build from the ORIGINAL url — src may already be a CDN
					// URL by this point, and signing a signed URL is nonsense.
					$srcset = self::srcset_for( $m[1], $intrinsic );

					if ( '' !== $srcset ) {
						if ( $existing === 1 || 0 !== preg_match( '/\ssrcset\s*=/i', $tag ) ) {
							$tag = preg_replace(
								'/\ssrcset\s*=\s*([\'"])[^\'"]*\1/i',
								' srcset="' . $srcset . '"',
								$tag,
								1
							);
						} else {
							// No srcset at all — add one just before the close.
							$tag = preg_replace( '/\s*\/?>$/', ' srcset="' . $srcset . '">', $tag, 1 );
						}
					}
				}

				return $tag;
			},
			$html
		);
		$html = ( null === $html ) ? $prev : $html;

		// <source srcset>
		$prev = $html;
		$html = preg_replace_callback(
			'/<source\s+[^>]*(?:data-)?srcset\s*=\s*[\'"][^\'"]+[\'"][^>]*>/i',
			function ( $m ) use ( $excludes, $rewrite_srcset ) {
				foreach ( $excludes as $v ) {
					if ( false !== stripos( $m[0], $v ) ) {
						return $m[0];
					}
				}
				return $rewrite_srcset( $m[0] );
			},
			$html
		);
		$html = ( null === $html ) ? $prev : $html;

		// inline background-image
		$prev = $html;
		$html = preg_replace_callback(
			'/background(?:-image)?\s*:[^;\"\'{}]*?url\((["\']?)([^"\')]+)\1\)/i',
			function ( $m ) use ( $excludes, $to_cdn ) {
				foreach ( $excludes as $v ) {
					if ( false !== stripos( $m[0], $v ) ) {
						return $m[0];
					}
				}
				$cdn = $to_cdn( $m[2] );
				return ( $cdn === trim( $m[2] ) ) ? $m[0] : 'background-image: url(' . $cdn . ')';
			},
			$html
		);
		$html = ( null === $html ) ? $prev : $html;

		// data-bg
		$prev = $html;
		$html = preg_replace_callback(
			'/data-bg\s*=\s*([\'"])([^\'"]+)\1/i',
			function ( $m ) use ( $excludes, $to_cdn ) {
				foreach ( $excludes as $v ) {
					if ( false !== stripos( $m[0], $v ) ) {
						return $m[0];
					}
				}
				$cdn = $to_cdn( $m[2] );
				return ( $cdn === trim( $m[2] ) ) ? $m[0] : 'data-bg="' . $cdn . '"';
			},
			$html
		);
		$html = ( null === $html ) ? $prev : $html;

		if ( $scripts ) {
			$html = preg_replace_callback(
				'/\x01EOCL(\d+)\x01/',
				function ( $m ) use ( $scripts ) {
					return isset( $scripts[ (int) $m[1] ] ) ? $scripts[ (int) $m[1] ] : '';
				},
				$html
			);
		}

		return $html;
	}

	/**
	 * Browser-side last resort: if a CDN image fails to load, swap back to
	 * the original recovered from the URL itself. Capture-phase because
	 * error events do not bubble; covers late-injected images too.
	 */
	public static function print_fallback_script() {
		if ( ! self::is_delivering() || ! (int) EasyOpt_Config::get( 'fluxcdn_error_fallback', 1 ) ) {
			return;
		}
		$ep = esc_js( self::endpoint() );
		echo '<script>(function(){var E="' . $ep . '";document.addEventListener("error",function(e){var t=e.target;if(!t||t.tagName!=="IMG"||t.__fx)return;var s=t.currentSrc||t.src||"";if(s.indexOf(E)!==0)return;var i=s.indexOf("/plain/");if(i<0)return;t.__fx=1;t.removeAttribute("srcset");t.removeAttribute("sizes");t.src=s.slice(i+7);},true);})();</script>' . "\n";
	}

	/* ─────────────────────────────────────────────
	 *  Maintenance
	 * ───────────────────────────────────────────── */

	/**
	 * Admin/cron upkeep: refresh usage, and re-sign when the map is stale.
	 *
	 * Never runs on a front-end render (§8.10). Throttled so a busy admin
	 * session does not hammer the control plane.
	 */
	public static function maintain() {
		if ( ! self::is_connected() ) {
			return;
		}
		if ( ! is_admin() && ! wp_doing_cron() ) {
			return;
		}
		if ( get_transient( 'easyopt_cloud_maint' ) ) {
			return;
		}
		// (2.6.1) 10 minutes, down from 15. Affordable now that the work runs
		// on cron rather than inline on an admin page load — see
		// EasyOpt_REST_Cloud::arm_maintenance().
		set_transient( 'easyopt_cloud_maint', 1, 10 * MINUTE_IN_SECONDS );

		self::usage( true );

		if ( self::is_unpaid() && self::map_is_stale() ) {
			self::map_refresh( self::collect_originals() );
		}
	}

	/** Cached usage. Returns null when unknown — callers must fail open. */
	public static function usage( $force = false ) {
		if ( ! self::is_connected() ) {
			return null;
		}
		$t = get_transient( 'easyopt_cloud_usage' );
		if ( ! $force && is_array( $t ) ) {
			return $t;
		}
		if ( ! $force && ! is_admin() && ! wp_doing_cron() ) {
			return is_array( $t ) ? $t : null; // §8.10
		}
		$body = array( 'site' => esc_url_raw( get_site_url() ) );
		if ( self::is_unpaid() ) {
			$body['token'] = self::token();
		} else {
			$body['license_key'] = (string) EasyOpt_Config::get( 'cloud_license_key', '' );
		}
		$r = self::post( 'usage', $body, 10 );
		if ( ! $r || empty( $r['ok'] ) ) {
			return is_array( $t ) ? $t : null;
		}
		$out = array(
			'used_bytes'  => (int) ( $r['used_bytes'] ?? 0 ),
			'quota_bytes' => (int) ( $r['quota_bytes'] ?? 0 ),
			'images_used' => (int) ( $r['images_used'] ?? 0 ),
			'image_cap'   => (int) ( $r['image_cap'] ?? 0 ),
			'plan'        => (string) ( $r['plan'] ?? 'trial' ),
			'active'      => (int) ( $r['active'] ?? 0 ),
			'reason'      => (string) ( $r['reason'] ?? '' ),
			'period'      => (string) ( $r['period'] ?? '' ),
			'meter_stale' => (int) ( $r['meter_stale'] ?? 0 ),
			// Supplied by the server so the panel never hardcodes a marketing
			// URL — an earlier build shipped /pricing, which 404'd.
			'pricing_url' => isset( $r['pricing_url'] ) ? esc_url_raw( (string) $r['pricing_url'] ) : '',
			// Projected server-side. null means no honest projection exists —
			// kept as null rather than coerced to 0, which would read as
			// "no days left" and be a lie.
			'days_left'   => isset( $r['days_left'] ) && null !== $r['days_left'] ? (int) $r['days_left'] : null,
			// The Trial clock. This list is a WHITELIST — anything the server
			// sends that is not named here is dropped, which is why adding a
			// field to the service is not enough on its own.
			'trial_ends_at'   => (int) ( $r['trial_ends_at'] ?? 0 ),
			'trial_days_left' => (int) ( $r['trial_days_left'] ?? 0 ),
		);
		set_transient( 'easyopt_cloud_usage', $out, HOUR_IN_SECONDS );

		// A paid licence that ENDED (cancelled / expired / refunded / disputed)
		// fully disconnects the site — there is no free tier to fall back to. The
		// server signals it with reason 'revoked', distinct from 'quota' and
		// 'trial_expired' (temporary — connection kept, edge serves origin). Clear
		// all cloud state (revert to origin + the local optimizer) and raise a
		// one-time reconnect prompt. disconnect() reads the licence key before
		// wiping it, so the seat is released on the way out.
		if ( 'revoked' === (string) ( $out['reason'] ?? '' ) ) {
			if ( ! get_option( 'easyopt_cloud_revoked' ) ) {
				update_option( 'easyopt_cloud_revoked', time(), false );
				if ( class_exists( 'EasyOpt_Tracker' ) ) {
					EasyOpt_Tracker::smartimg_fallback( 'revoked' );
				}
			}
			self::disconnect();
			return $out;
		}

		// The server is authoritative on entitlement. A quota-exhausted or
		// moved account stops emitting CDN URLs here immediately rather
		// than waiting for the edge to redirect every image.
		EasyOpt_Config::set( 'easyopt_cloud_active', $out['active'] ? 1 : 0 );
		if ( '' !== $out['plan'] ) {
			EasyOpt_Config::set( 'easyopt_cloud_plan', $out['plan'] );
		}

		// Pick up an ENDPOINT change from the server — e.g. an account whose
		// zone moved (free ↔ trial ↔ paid). Without this the plugin keeps
		// signing and serving URLs for the OLD zone, which the edge guard then
		// 302s as 'wrong-zone' — the free-cdn redirect bug. On a real change we
		// re-point, re-sign the free map on the new endpoint, and flush whatever
		// still has the old URLs baked in. usage() is gated to admin/cron, so
		// the heavy re-sign stays off the front-end path and fires once.
		$new_ep = isset( $r['endpoint'] ) ? untrailingslashit( esc_url_raw( (string) $r['endpoint'] ) ) : '';
		if ( '' !== $new_ep && $new_ep !== self::endpoint() ) {
			EasyOpt_Config::set( 'easyopt_cloud_endpoint', $new_ep );
			if ( self::is_unpaid() ) {
				self::map_refresh( self::collect_originals() );
			}
			self::flush_generated();
		}
		return $out;
	}


	/** Disconnect: release the site, wipe local state, restore origins. */
	public static function disconnect() {
		if ( self::is_connected() ) {
			$body = array( 'site' => esc_url_raw( get_site_url() ) );
			if ( self::is_unpaid() ) {
				$body['token'] = self::token();
			} else {
				$body['license_key'] = (string) EasyOpt_Config::get( 'cloud_license_key', '' );
				// (2.6.1) Proves this site owns the seat it is releasing. The
				// server refuses to drop a host from a multi-site licence
				// without it.
				$body['activation_id'] = (string) EasyOpt_Config::get( 'cloud_activation_id', '' );
			}
			self::post( 'disconnect', $body, 10 );
		}
		foreach ( array( 'cloud_account', 'cloud_token', 'cloud_endpoint', 'cloud_plan', 'cloud_key', 'cloud_salt', 'cloud_license_key', 'cloud_fingerprint', 'cloud_activation_id' ) as $k ) {
			EasyOpt_Config::set( 'easyopt_' . $k, '' );
		}
		EasyOpt_Config::set( 'easyopt_cloud_active', 0 );
		delete_option( self::MAP_OPTION );
		delete_transient( 'easyopt_cloud_usage' );
		delete_transient( 'easyopt_cloud_maint' );
		self::flush_generated();
	}

}
