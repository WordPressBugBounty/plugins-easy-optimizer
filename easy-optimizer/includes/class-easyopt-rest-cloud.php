<?php
/**
 * FluxPress Adaptive Images — v3 REST routes.
 *
 * NEW FILE. Registers its own routes on the existing easyopt/v1 namespace
 * rather than editing class-easyopt-rest-dashboard.php, so the legacy
 * /fluxcdn/* routes are untouched and keep serving v2.5 connections.
 *
 *   POST /easyopt/v1/cloud/analyze      no account — the proof
 *   POST /easyopt/v1/cloud/signup       email only
 *   POST /easyopt/v1/cloud/connect      paste an existing licence key
 *   POST /easyopt/v1/cloud/disconnect
 *   GET  /easyopt/v1/cloud/usage
 *   POST /easyopt/v1/cloud/upgrade
 *
 * Every route requires manage_options — these are wp-admin actions, not
 * public endpoints.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EasyOpt_REST_Cloud {

	const NS = 'easyopt/v1';

	/** Images measured in one analyze pass. */
	const ANALYZE_MAX = 6;

	/** Refuse to measure originals larger than this — an outlier would
	 *  dominate the "before" number and imgproxy will refuse them anyway. */
	const MAX_SRC_BYTES = 10485760;

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );

		// Cloud upkeep: refresh usage, re-sign a stale map. Deliberately
		// NOT hooked into the legacy easyopt_fluxcdn_admin_maintenance
		// block — that one returns early unless a v2.5 licence key exists,
		// so a cloud-only site would never be maintained.
		//
		// (2.6.1) The refresh no longer blocks wp-admin.
		//
		// maintain() makes an HTTP call with a 10s timeout. Hooked directly to
		// admin_init it ran inline, so once per throttle window an admin page
		// load stalled on the network — and tightening the window to keep
		// status fresher made that worse, not better.
		//
		// Now admin_init only ARMS a single cron event. WordPress spawns due
		// cron at shutdown via a non-blocking loopback request, so the page
		// returns immediately and the work happens in a separate request a
		// moment later. Client sites have no system cron — an admin visit is
		// the trigger, which is exactly when freshness matters.
		add_action( 'admin_init', array( __CLASS__, 'arm_maintenance' ), 30 );
		add_action( 'easyopt_cloud_maintain', array( 'EasyOpt_CDN_Cloud', 'maintain' ) );
		if ( ! wp_next_scheduled( 'easyopt_cloud_maintain' ) ) {
			wp_schedule_event( time() + 900, 'hourly', 'easyopt_cloud_maintain' );
		}
	}

	/**
	 * (2.6.1) Arm the cloud status refresh without blocking the admin page.
	 *
	 * Schedules the real work for cron instead of running it inline. The
	 * throttle transient is checked here too, so the common case costs one
	 * transient read and nothing else.
	 *
	 * Falls back to running inline when WP-Cron is disabled AND no external
	 * cron is configured — but only on Easy Optimizer's own screens, so it can
	 * never slow down Posts, Media or Plugins.
	 */
	public static function arm_maintenance() {
		if ( ! EasyOpt_CDN_Cloud::is_connected() ) {
			return;
		}
		if ( get_transient( 'easyopt_cloud_maint' ) ) {
			return;
		}

		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		if ( ! $cron_disabled ) {
			if ( ! wp_next_scheduled( 'easyopt_cloud_maintain' ) ) {
				wp_schedule_single_event( time(), 'easyopt_cloud_maintain' );
			}
			return;
		}

		// No WP-Cron. Run inline, but only where the user is already looking
		// at our data and a short pause is explicable.
		$screen = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 0 === strpos( $screen, 'easy-optimizer' ) || 0 === strpos( $screen, 'easyopt' ) ) {
			EasyOpt_CDN_Cloud::maintain();
		}
	}

	public static function routes() {
		$auth = array(
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		);
		foreach ( array(
			'analyze'    => 'analyze',
			'signup'     => 'signup',
			'connect'    => 'connect',
			'disconnect' => 'disconnect',
			'upgrade'    => 'upgrade',
			'refresh'    => 'refresh',
			'resend'     => 'resend',
		) as $route => $fn ) {
			register_rest_route( self::NS, '/cloud/' . $route, array(
				'methods'  => 'POST',
				'callback' => array( __CLASS__, $fn ),
			) + $auth );
		}
		register_rest_route( self::NS, '/cloud/usage', array(
			'methods'  => 'GET',
			'callback' => array( __CLASS__, 'usage' ),
		) + $auth );
	}

	/** Fire a funnel event. Silently inert unless the user opted in. */
	private static function track( $event, $meta = array() ) {
		if ( class_exists( 'EasyOpt_Tracker' ) ) {
			EasyOpt_Tracker::send( $event, $meta );
		}
	}

	/* ─────────────────────────────────────────────
	 *  Analyze — the proof before the ask
	 * ───────────────────────────────────────────── */

	/**
	 * Measure this site's real images, before and after.
	 *
	 * No account, no email. The whole point is that the user sees their own
	 * numbers before being asked for anything.
	 */
	public static function analyze() {
		self::track( 'analyze_started' );

		$images = self::homepage_images();
		if ( empty( $images ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'code'    => 'no_images',
				'message' => __( 'We could not find any images on your homepage to measure. Add an image and try again.', 'easy-optimizer' ),
			) );
		}

		$resp = wp_remote_post(
			apply_filters( 'easyopt_cloud_api', EasyOpt_CDN_Cloud::API ) . 'analyze',
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'install_hash' => EasyOpt_CDN_Cloud::install_hash(),
					'site'         => esc_url_raw( get_site_url() ),
					'urls'         => $images,
				) ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'code'    => 'unreachable',
				'message' => __( 'Could not reach the Easy Optimizer Cloud service. Check your connection and try again.', 'easy-optimizer' ),
			) );
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) || empty( $body['ok'] ) || empty( $body['urls'] ) ) {
			$code = is_array( $body ) && ! empty( $body['code'] ) ? (string) $body['code'] : 'failed';
			self::track( 'analyze_failed', array( 'reason' => $code ) );
			return rest_ensure_response( array(
				'success' => false,
				'code'    => $code,
				'message' => is_array( $body ) && ! empty( $body['message'] )
					? (string) $body['message']
					: __( 'The analysis could not be completed. Please try again shortly.', 'easy-optimizer' ),
			) );
		}

		$rows = self::measure( $body['urls'] );
		if ( empty( $rows ) ) {
			// (2.6.1) MEASUREMENT FAILING NO LONGER BLOCKS SIGNUP.
			//
			// The analyzer exists to EARN the signup, so gating the signup on
			// it was backwards — a user whose server cannot loopback-fetch its
			// own images (Sucuri, locked-down origin, no hairpin NAT) is
			// exactly who we most want on the free tier, and we were the ones
			// stopping them. The images were still signed; only the before/after
			// number is missing. Return success WITHOUT a number — never a
			// fabricated one — and let the panel show the offer and Connect.
			//
			// The leg-specific note is informational, so a user who can act on
			// it (whitelist an IP) still sees how.
			switch ( self::$measure_fail ) {
				case 'cdn':
					$note = __( 'Your images are ready to optimize. We could not fetch the optimized preview just now (the image CDN can take a minute to warm up), so the savings figure is not shown — but connecting will start optimizing them.', 'easy-optimizer' );
					break;
				case 'origin':
					$note = __( 'Your images are ready to optimize. We could not read your originals from this server to show a preview — a security plugin or firewall may be blocking your site from requesting its own files — but connecting will still optimize them.', 'easy-optimizer' );
					break;
				default:
					$note = __( 'Your images are ready to optimize. We could not measure a before/after preview from this server, but connecting will start optimizing them.', 'easy-optimizer' );
			}
			self::track( 'analyze_no_measure', array( 'leg' => self::$measure_fail ) );
			self::track( 'signup_shown' );
			// No stale result should linger behind the note.
			delete_option( 'easyopt_cloud_analysis' );
			return rest_ensure_response( array(
				'success'  => true,
				'measured' => false,
				'rows'     => array(),
				'note'     => $note,
				'leg'      => self::$measure_fail,
				'email'    => get_option( 'admin_email' ),
				'free_image_cap' => isset( $body['free_image_cap'] ) ? (int) $body['free_image_cap'] : 0,
				'free_quota_gb'  => isset( $body['free_quota_gb'] ) ? (int) $body['free_quota_gb'] : 0,
			) );
		}

		$before = 0;
		$after  = 0;
		foreach ( $rows as $r ) {
			$before += (int) $r['before'];
			$after  += (int) $r['after'];
		}
		// Only claim a saving we actually measured. If the transformed copy
		// came back larger (already-optimised WebP, tiny PNG), that image is
		// reported honestly rather than quietly dropped from the total.
		$pct = $before > 0 ? max( 0, round( ( 1 - $after / $before ) * 100 ) ) : 0;

		usort( $rows, function ( $a, $b ) {
			return (int) $b['before'] - (int) $a['before'];
		} );

		update_option( 'easyopt_cloud_analysis', array(
			'rows'   => $rows,
			'before' => $before,
			'after'  => $after,
			'pct'    => $pct,
			'at'     => time(),
		), false );

		self::track( 'analyze_completed', array( 'pct' => $pct, 'n' => count( $rows ) ) );
		self::track( 'signup_shown' );

		return rest_ensure_response( array(
			'success'  => true,
			'measured' => true,
			'rows'    => $rows,
			'before'  => $before,
			'after'   => $after,
			'pct'     => $pct,
			'skipped' => isset( $body['skipped'] ) ? $body['skipped'] : array(),
			// Counts of what we refused BEFORE asking the server, by reason.
			// Without this "we found 2 images" is indistinguishable from a
			// broken plugin.
			'skipped_local' => get_option( 'easyopt_cloud_skipped', array() ),
			'email'   => get_option( 'admin_email' ),
			'free_image_cap' => isset( $body['free_image_cap'] ) ? (int) $body['free_image_cap'] : 0,
			'free_quota_gb'  => isset( $body['free_quota_gb'] ) ? (int) $body['free_quota_gb'] : 0,
		) );
	}

	/**
	 * Collect candidate images from the site's own homepage.
	 *
	 * Reads the rendered homepage rather than the media library, because
	 * what matters is what a visitor actually downloads. The LCP candidate
	 * — in practice the first large image in source order — goes first, so
	 * a capped run still measures the image that dominates the user's
	 * Core Web Vitals.
	 */
	/**
	 * (2.6.1) Recover the original image URL from one of our own CDN URLs.
	 *
	 * Grammar: {endpoint}/{account}/{sig}/{processing}/plain/{origin}
	 * Everything after the first "/plain/" is the origin, with imgproxy's
	 * escaping undone in the reverse of the order it was applied (? before %,
	 * because % was escaped first).
	 *
	 * Returns the input untouched when it is not one of ours, so it is safe to
	 * run over every URL scraped from a page.
	 *
	 * @since 2.6.1
	 * @param string $url
	 * @return string
	 */
	public static function unwrap_cdn_url( $url ) {
		$url = (string) $url;
		if ( false === stripos( $url, '/plain/' ) ) {
			return $url;
		}

		// Recognise our own URLs by their GRAMMAR, not by the stored endpoint.
		//
		// Matching the configured endpoint alone failed in the one case that
		// matters most: disconnect() clears cloud_endpoint, so immediately
		// afterwards the still-cached HTML is full of CDN URLs that we can no
		// longer identify — every one was then rejected as a foreign host and
		// the analyzer reported "we could not find any images on your
		// homepage". Which is exactly when someone re-runs it.
		//
		// The path shape is specific enough to be safe on its own: a minted
		// account id (fp + 12 base36), a base64url signature, a processing
		// segment, then /plain/ and an absolute URL. A customer's own upload
		// path cannot look like that.
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ours = (bool) preg_match(
			'#^/fp[a-z0-9]{12}/[A-Za-z0-9_-]{6,16}/.+?/plain/https?%3A|^/fp[a-z0-9]{12}/[A-Za-z0-9_-]{6,16}/.+?/plain/https?:/#i',
			$path
		);

		// Still honour a configured endpoint, so a custom or future hostname
		// keeps working even if the path shape ever changes.
		if ( ! $ours ) {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			foreach ( array( 'cloud_endpoint', 'fluxcdn_endpoint' ) as $k ) {
				$e = (string) EasyOpt_Config::get( $k, '' );
				if ( '' !== $e && $host === strtolower( (string) wp_parse_url( $e, PHP_URL_HOST ) ) ) {
					$ours = true;
					break;
				}
			}
		}
		if ( ! $ours ) {
			return $url;
		}

		$pos    = stripos( $url, '/plain/' );
		$origin = substr( $url, $pos + 7 );
		$origin = str_replace( array( '%3F', '%3f', '%25' ), array( '?', '?', '%' ), $origin );
		// Edge path normalisation can collapse '//' to '/'.
		if ( 0 === strpos( $origin, 'https:/' ) && 0 !== strpos( $origin, 'https://' ) ) {
			$origin = 'https://' . substr( $origin, 7 );
		}
		return ( 0 === strpos( $origin, 'http' ) ) ? $origin : $url;
	}

	/**
	 * (2.6.1) Pull background image URLs out of the homepage's linked builder
	 * CSS files.
	 *
	 * Elementor's default "External Files" CSS mode writes backgrounds to
	 * uploads/elementor/css/post-N.css, referenced by a <link>. The homepage
	 * HTML therefore never contains the hero background at all. This fetches
	 * those stylesheets (bounded, same-origin only) and extracts their
	 * background url()s so the LCP hero can enter the free sign map.
	 *
	 * Scoped tightly: only same-origin CSS under the uploads or theme dirs,
	 * at most a handful of files, short timeout. A homepage that references no
	 * builder CSS costs one preg_match and no requests.
	 *
	 * @param string $html Rendered homepage HTML.
	 * @return string[] Absolute background image URLs.
	 */
	private static function builder_css_backgrounds( $html ) {
		if ( '' === $html || ! preg_match_all( '/<link\b[^>]*\shref=["\']([^"\']+\.css[^"\']*)["\']/i', $html, $lm ) ) {
			return array();
		}
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$targets   = array();
		foreach ( $lm[1] as $href ) {
			$href = html_entity_decode( trim( $href ), ENT_QUOTES );
			if ( 0 === strpos( $href, '//' ) ) {
				$href = 'https:' . $href;
			} elseif ( '' !== $href && '/' === $href[0] ) {
				$href = untrailingslashit( home_url() ) . $href;
			}
			// Only same-origin builder stylesheets — never fetch third-party CSS.
			if ( strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) ) !== $site_host ) {
				continue;
			}
			$path = strtolower( (string) wp_parse_url( $href, PHP_URL_PATH ) );
			if ( false === strpos( $path, '/elementor/' )
				&& false === strpos( $path, '/uploads/' )
				&& false === strpos( $path, 'post-' ) ) {
				continue; // builder-generated CSS only; skip theme/plugin bundles
			}
			$targets[ $href ] = 1;
			if ( count( $targets ) >= 4 ) {
				break; // bounded — the LCP hero is on the first builder sheet
			}
		}

		$out = array();
		foreach ( array_keys( $targets ) as $css_url ) {
			$r = wp_remote_get( $css_url, array(
				'timeout'    => 8,
				'sslverify'  => true,
				'user-agent' => 'EasyOptimizer/' . ( defined( 'EASYOPT_VERSION' ) ? EASYOPT_VERSION : '3' ) . ' (image analyzer)',
			) );
			if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
				continue;
			}
			$css = (string) wp_remote_retrieve_body( $r );
			if ( preg_match_all( '/background(?:-image)?\s*:[^;{}"\']*url\((["\']?)([^"\')]+)\1\)/i', $css, $bm ) ) {
				foreach ( $bm[2] as $u ) {
					$out[] = $u;
				}
			}
		}
		return $out;
	}

	public static function homepage_images( $limit = self::ANALYZE_MAX ) {
		$res = wp_remote_get( home_url( '/' ), array(
			'timeout'   => 15,
			'sslverify' => true,
			'headers'   => array( 'Accept' => 'text/html' ),
			// Identify ourselves so a firewall log shows what this was.
			'user-agent' => 'EasyOptimizer/' . ( defined( 'EASYOPT_VERSION' ) ? EASYOPT_VERSION : '3' ) . ' (image analyzer)',
		) );
		$html = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );

		$found = array();

		if ( '' !== $html ) {
			// <img src>, in source order — the first ones are above the fold.
			if ( preg_match_all( '/<img\b[^>]*\ssrc=["\']([^"\']+)["\']/i', $html, $m ) ) {
				foreach ( $m[1] as $u ) {
					$found[] = $u;
				}
			}
			// Lazy-loaded images. On a Divi page these are most of them: the
			// slider ships <img data-src="…"> with no src at all, so reading
			// only src found 2 images out of 56.
			if ( preg_match_all( '/<img\b[^>]*\s(?:data-src|data-lazy-src|data-orig-src|data-original)=["\']([^"\']+)["\']/i', $html, $md ) ) {
				foreach ( $md[1] as $u ) {
					$found[] = $u;
				}
			}
			// First srcset candidate — some themes put the real image only there.
			if ( preg_match_all( '/<img\b[^>]*\s(?:data-)?srcset=["\']([^"\',]+)/i', $html, $ms ) ) {
				foreach ( $ms[1] as $u ) {
					$found[] = trim( $u );
				}
			}
			// CSS background-image, which page builders lean on heavily and
			// which is invisible to anything that only reads <img>. This
			// catches INLINE styles and <style> blocks in the HTML.
			if ( preg_match_all( '/background(?:-image)?\s*:[^;{}"\']*url\((["\']?)([^"\')]+)\1\)/i', $html, $m2 ) ) {
				foreach ( $m2[2] as $u ) {
					$found[] = $u;
				}
			}

			// (2.6.1) Elementor (and similar builders) default to writing
			// backgrounds into EXTERNAL CSS files, not the HTML — so the hero
			// background, usually the LCP element, is invisible to every scan
			// above and never entered the free map. Fetch the linked builder
			// CSS the homepage references and pull backgrounds out of it too,
			// so free actually signs the image that decides LCP.
			foreach ( self::builder_css_backgrounds( $html ) as $u ) {
				$found[] = $u;
			}
		}

		// Fall back to the media library when the homepage yields nothing —
		// a brand-new site is exactly the audience most likely to install a
		// fresh optimizer, and a dead-end analyzer loses them.
		if ( empty( $found ) && class_exists( 'EasyOpt_CDN_Cloud' ) ) {
			$found = EasyOpt_CDN_Cloud::collect_originals( 12 );
		}

		$out  = array();
		$seen = array();
		$skip = array();
		foreach ( $found as $u ) {
			$u = trim( html_entity_decode( (string) $u, ENT_QUOTES ) );
			if ( '' === $u || 0 === stripos( $u, 'data:' ) ) {
				continue; // inline placeholders are not images we can optimize
			}
			// (2.6.1) UN-REWRITE OUR OWN CDN URLS FIRST.
			//
			// This reads the RENDERED homepage, and on a connected site that
			// HTML has already been rewritten by us — every src is
			// https://cdn.fluxpress.io/{account}/{sig}/…/plain/https://site/x.png
			// The same-host test below then rejected every one of them as
			// foreign, so "Re-check my images" reported "we could not find any
			// images on your homepage" on precisely the sites where the plugin
			// was working. The feature could only ever succeed before it was
			// switched on.
			//
			// Recovering the origin from the /plain/ segment is the same trick
			// the inline JS error fallback and the legacy ShortPixel matcher
			// already use.
			$u = self::unwrap_cdn_url( $u );

			// One normaliser for both the analyzer and the rewriter, so what
			// we measure is exactly what we will later deliver.
			$u = EasyOpt_CDN_Cloud::absolutize( $u );
			if ( ! EasyOpt_CDN::same_host( $u ) ) {
				$skip['foreign'] = ( $skip['foreign'] ?? 0 ) + 1;
				continue;
			}
			$ext = strtolower( pathinfo( (string) wp_parse_url( $u, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ) {
				$skip[ $ext ? $ext : 'noext' ] = ( $skip[ $ext ? $ext : 'noext' ] ?? 0 ) + 1;
				continue; // gif/svg/avif gain little or cannot be transformed
			}
			// Mirror the server's refusal set exactly — measuring an image we
			// will never sign would inflate the promise.
			if ( preg_match( '/[?#%\s]|[^\x21-\x7E]/', $u ) ) {
				$skip['encoded'] = ( $skip['encoded'] ?? 0 ) + 1;
				continue;
			}
			if ( isset( $seen[ $u ] ) ) {
				continue;
			}
			$seen[ $u ] = 1;
			$out[]      = $u;
			if ( count( $out ) >= (int) $limit ) {
				break;
			}
		}
		// Surfaced so "we found 2 images" can say WHY, instead of leaving
		// someone to guess whether the plugin is broken.
		update_option( 'easyopt_cloud_skipped', $skip, false );
		return $out;
	}

	/**
	 * Measure original vs transformed size for each signed URL.
	 *
	 * Parallel when curl_multi is available, which keeps a six-image run at
	 * roughly the slowest single request instead of the sum of twelve. The
	 * sequential fallback halves the sample rather than making the user wait
	 * two minutes — a smaller honest number beats a slow one.
	 *
	 * @param array $signed origin => { width => url }  (or origin => url)
	 */
	private static function measure( array $signed ) {
		$pairs = array();
		foreach ( $signed as $origin => $variants ) {
			$url = is_array( $variants ) ? ( end( $variants ) ?: '' ) : (string) $variants;
			if ( '' === $url ) {
				continue;
			}
			$pairs[] = array( 'origin' => (string) $origin, 'cdn' => $url );
		}
		if ( empty( $pairs ) ) {
			return array();
		}

		$parallel = function_exists( 'curl_multi_init' ) && function_exists( 'curl_init' );
		if ( ! $parallel ) {
			$pairs = array_slice( $pairs, 0, 3 );
		}

		// (2.6.1) READ THE "BEFORE" SIZE OFF DISK WHEN WE CAN.
		//
		// The original is almost always a local upload, so its exact size is
		// one filesize() call away. Fetching it over HTTP instead meant the
		// measurement depended on this server being able to reach its own
		// public hostname — which fails on any host behind a WAF that blocks
		// loopback (Sucuri, Cloudflare in some modes), on locked-down origins
		// that only accept the CDN's IPs, and on NAT setups without hairpin.
		// The user saw "your server may be blocking outbound requests" and had
		// no way to act on it.
		//
		// filesize() is also exact, where HTTP could report a proxy-rewritten
		// or recompressed length. HTTP stays as the fallback for genuinely
		// remote originals.
		$targets = array();
		$local   = array();
		foreach ( $pairs as $i => $p ) {
			$bytes = self::local_file_size( $p['origin'] );
			if ( $bytes > 0 ) {
				$local[ 'o' . $i ] = $bytes;
			} else {
				$targets[ 'o' . $i ] = array( 'url' => $p['origin'], 'accept' => 'image/*' );
			}
			$targets[ 'c' . $i ] = array( 'url' => $p['cdn'], 'accept' => 'image/avif,image/webp,image/*' );
		}
		$sizes = $parallel ? self::sizes_parallel( $targets ) : self::sizes_sequential( $targets );
		$sizes = $local + $sizes;

		// (2.6.1) Record WHICH side failed. The caller reported "your server may
		// be blocking outbound requests" for any empty result, which was both
		// vague and usually wrong — the common cause is a WAF blocking the
		// INBOUND loopback, and the two halves fail for entirely different
		// reasons and need different fixes.
		self::$measure_fail = '';
		$no_origin = 0;
		$no_cdn    = 0;

		$rows = array();
		foreach ( $pairs as $i => $p ) {
			$before = (int) ( $sizes[ 'o' . $i ] ?? 0 );
			$after  = (int) ( $sizes[ 'c' . $i ] ?? 0 );
			if ( $before <= 0 ) {
				$no_origin++;
			}
			if ( $after <= 0 ) {
				$no_cdn++;
			}
			// Both numbers must be real. A zero on either side means we did
			// not measure it, and inventing the missing half is exactly the
			// fabricated-savings figure the brief forbids.
			if ( $before <= 0 || $after <= 0 || $before > self::MAX_SRC_BYTES ) {
				continue;
			}
			$rows[] = array(
				'name'   => basename( (string) wp_parse_url( $p['origin'], PHP_URL_PATH ) ),
				'url'    => $p['origin'],
				'before' => $before,
				'after'  => $after,
				'pct'    => (int) max( 0, round( ( 1 - $after / $before ) * 100 ) ),
			);
		}

		if ( empty( $rows ) ) {
			$total = count( $pairs );
			if ( $no_cdn >= $total && $no_origin < $total ) {
				// Originals read fine, optimized copies did not — nothing to do
				// with this server's firewall.
				self::$measure_fail = 'cdn';
			} elseif ( $no_origin >= $total && $no_cdn < $total ) {
				self::$measure_fail = 'origin';
			} else {
				self::$measure_fail = 'both';
			}
		}
		return $rows;
	}

	/**
	 * Which half of the last measurement came back empty: 'origin', 'cdn',
	 * 'both', or '' when it succeeded. Read once by the /cloud/analyze handler.
	 *
	 * @var string
	 */
	private static $measure_fail = '';

	/**
	 * (2.6.1) Exact byte size of a URL that maps to a local upload.
	 *
	 * Only resolves URLs inside the uploads directory, and only after
	 * realpath() confirms the result is still under the uploads root — a URL
	 * is attacker-influenceable in principle, and "../../wp-config.php" must
	 * not become a readable size probe.
	 *
	 * @param string $url
	 * @return int Bytes, or 0 when the URL is not a readable local upload.
	 */
	private static function local_file_size( $url ) {
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) {
			return 0;
		}
		// Compare host-agnostically: the stored baseurl may use a different
		// scheme or www prefix than the URL we were handed.
		$strip = function ( $u ) {
			$p = wp_parse_url( (string) $u );
			$h = isset( $p['host'] ) ? preg_replace( '/^www\./i', '', strtolower( $p['host'] ) ) : '';
			return $h . ( isset( $p['path'] ) ? $p['path'] : '' );
		};
		$base = $strip( $uploads['baseurl'] );
		$want = $strip( $url );
		if ( '' === $base || 0 !== strpos( $want, $base ) ) {
			return 0;
		}

		$rel  = ltrim( substr( $want, strlen( $base ) ), '/' );
		$rel  = rawurldecode( $rel );
		$path = rtrim( (string) $uploads['basedir'], '/\\' ) . '/' . $rel;

		$real = realpath( $path );
		$root = realpath( (string) $uploads['basedir'] );
		if ( false === $real || false === $root || 0 !== strpos( $real, $root ) ) {
			return 0; // outside uploads, or does not exist
		}
		if ( ! is_file( $real ) || ! is_readable( $real ) ) {
			return 0;
		}
		$size = @filesize( $real );
		return is_int( $size ) && $size > 0 ? $size : 0;
	}

	/** curl_multi: all requests in flight at once. */
	private static function sizes_parallel( array $targets ) {
		// (2.6.2) curl_multi_* is disabled on some hardened hosts. On PHP 8 a
		// disabled function is undefined, so an unguarded call is fatal.
		if ( ! function_exists( 'curl_multi_init' ) ) {
			return array();
		}
		$mh      = curl_multi_init();
		$handles = array();
		foreach ( $targets as $key => $t ) {
			$ch = curl_init();
			curl_setopt_array( $ch, array(
				CURLOPT_URL            => $t['url'],
				CURLOPT_NOBODY         => false, // some origins omit Content-Length on HEAD
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 3,
				CURLOPT_TIMEOUT        => 20,
				CURLOPT_CONNECTTIMEOUT => 8,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_HTTPHEADER     => array( 'Accept: ' . $t['accept'] ),
				CURLOPT_USERAGENT      => 'EasyOptimizer (image analyzer)',
			) );
			curl_multi_add_handle( $mh, $ch );
			$handles[ $key ] = $ch;
		}

		$running = null;
		do {
			curl_multi_exec( $mh, $running );
			if ( $running ) {
				curl_multi_select( $mh, 0.5 );
			}
		} while ( $running > 0 );

		$out = array();
		foreach ( $handles as $key => $ch ) {
			$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			$body = curl_multi_getcontent( $ch );
			// Measure the bytes actually received rather than trusting a
			// Content-Length header, which proxies and compression rewrite.
			$out[ $key ] = ( 200 === $code && is_string( $body ) ) ? strlen( $body ) : 0;
			curl_multi_remove_handle( $mh, $ch );
			curl_close( $ch );
		}
		curl_multi_close( $mh );
		return $out;
	}

	/** Fallback for hosts without curl_multi. */
	private static function sizes_sequential( array $targets ) {
		$out = array();
		foreach ( $targets as $key => $t ) {
			$r = wp_remote_get( $t['url'], array(
				'timeout' => 15,
				'headers' => array( 'Accept' => $t['accept'] ),
			) );
			$out[ $key ] = ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) )
				? strlen( (string) wp_remote_retrieve_body( $r ) )
				: 0;
		}
		return $out;
	}

	/* ─────────────────────────────────────────────
	 *  Signup
	 * ───────────────────────────────────────────── */

	public static function signup( WP_REST_Request $req ) {
		$email = sanitize_email( (string) $req->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'code'    => 'invalid_email',
				'message' => __( 'Please enter a valid email address.', 'easy-optimizer' ),
			) );
		}
		self::track( 'email_submitted' );

		$resp = wp_remote_post(
			apply_filters( 'easyopt_cloud_api', EasyOpt_CDN_Cloud::API ) . 'signup',
			array(
				'timeout' => 25,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'email'        => $email,
					'site'         => esc_url_raw( get_site_url() ),
					'install_hash' => EasyOpt_CDN_Cloud::install_hash(),
				) ),
			)
		);
		$body = is_wp_error( $resp ) ? null : json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( ! is_array( $body ) || empty( $body['ok'] ) ) {
			$code = is_array( $body ) && ! empty( $body['code'] ) ? (string) $body['code'] : 'failed';
			self::track( 'signup_failed', array( 'reason' => $code ) );
			return rest_ensure_response( array(
				'success' => false,
				'code'    => $code,
				'message' => is_array( $body ) && ! empty( $body['message'] )
					? (string) $body['message']
					: __( 'We could not create your account just now. Please try again in a moment.', 'easy-optimizer' ),
				// Never dead-end. The server tells us where to send them —
				// hardcoding a path here produced a 404, because only the
				// operator knows which pages actually exist.
				'fallback_url' => is_array( $body ) && ! empty( $body['fallback_url'] )
					? esc_url_raw( (string) $body['fallback_url'] )
					: '',
				// (2.6.1) On email_registered the server offers upgrading to
				// Pro as an alternative to reconnecting; pass its URL through
				// so the panel can render it as a link.
				'pricing_url' => is_array( $body ) && ! empty( $body['pricing_url'] )
					? esc_url_raw( (string) $body['pricing_url'] )
					: '',
			) );
		}

		// The service decides the Plan, not us. Hardcoding 'free' here meant
		// a Trial Account was stored as a Free Plan, so the panel showed
		// Free-Plan copy and the Trial countdown never appeared.
		$plan = (string) ( $body['plan'] ?? 'trial' );
		self::store_account( $body, $plan );
		self::track( 'account_created', array( 'plan' => $plan ) );

		// Sign this site's images immediately so the first page load after
		// signup is already optimized rather than waiting for a cron tick.
		EasyOpt_CDN_Cloud::map_refresh( EasyOpt_CDN_Cloud::collect_originals() );

		return rest_ensure_response( array(
			'success'     => true,
			'account'     => (string) $body['account'],
			'token'       => (string) $body['token'],
			'plan'        => $plan,
			'quota_bytes' => (int) ( $body['quota_bytes'] ?? 0 ),
			'image_cap'   => (int) ( $body['image_cap'] ?? 0 ),
			'trial_days_left' => (int) ( $body['trial_days_left'] ?? 0 ),
			'message'     => __( 'Your images are live. Nothing else to set up.', 'easy-optimizer' ),
		) );
	}

	/**
	 * Ask the service to email this address its account key again.
	 *
	 * "This email already has an account" was previously a dead end — the
	 * stored token is hashed, so nobody, including us, can look the original
	 * back up. The service mints a fresh one and emails it.
	 */
	public static function resend( WP_REST_Request $req ) {
		$email = sanitize_email( (string) $req->get_param( 'email' ) );
		if ( ! is_email( $email ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'message' => __( 'Please enter a valid email address.', 'easy-optimizer' ),
			) );
		}
		$resp = wp_remote_post(
			apply_filters( 'easyopt_cloud_api', EasyOpt_CDN_Cloud::API ) . 'resend',
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'email' => $email ) ),
			)
		);
		$body = is_wp_error( $resp ) ? null : json_decode( wp_remote_retrieve_body( $resp ), true );

		return rest_ensure_response( array(
			'success' => is_array( $body ) && ! empty( $body['ok'] ),
			'message' => is_array( $body ) && ! empty( $body['message'] )
				? (string) $body['message']
				: __( 'Could not reach the service. Please try again.', 'easy-optimizer' ),
		) );
	}

	/* ─────────────────────────────────────────────
	 *  Connect an existing licence
	 * ───────────────────────────────────────────── */

	public static function connect( WP_REST_Request $req ) {
		$key = sanitize_text_field( (string) $req->get_param( 'license_key' ) );
		if ( '' === $key ) {
			return rest_ensure_response( array(
				'success' => false,
				'message' => __( 'Paste your licence key first.', 'easy-optimizer' ),
			) );
		}
		self::track( 'connect_attempted', array( 'mode' => 'license' ) );

		$resp = wp_remote_post(
			apply_filters( 'easyopt_cloud_api', EasyOpt_CDN_Cloud::API ) . 'connect',
			array(
				'timeout' => 25,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'license_key'  => $key,
					'site'         => esc_url_raw( get_site_url() ),
					'install_hash' => EasyOpt_CDN_Cloud::install_hash(),
				) ),
			)
		);
		$body = is_wp_error( $resp ) ? null : json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( ! is_array( $body ) || empty( $body['ok'] ) ) {
			$code = is_array( $body ) && ! empty( $body['code'] ) ? (string) $body['code'] : 'failed';
			self::track( 'connect_failed', array( 'reason' => $code ) );
			return rest_ensure_response( array(
				'success' => false,
				'code'    => $code,
				'message' => is_array( $body ) && ! empty( $body['message'] )
					? (string) $body['message']
					: __( 'Could not connect that licence key.', 'easy-optimizer' ),
			) );
		}

		self::store_account( $body, (string) ( $body['plan'] ?? 'single' ) );
		EasyOpt_Config::set( 'easyopt_cloud_license_key', $key );
		self::track( 'connect_ok', array( 'plan' => (string) ( $body['plan'] ?? '' ) ) );

		return rest_ensure_response( array(
			'success' => true,
			'plan'    => (string) ( $body['plan'] ?? '' ),
			'message' => __( 'Connected. Your images are being optimized.', 'easy-optimizer' ),
		) );
	}

	/**
	 * Persist credentials and turn delivery ON.
	 *
	 * img_opt = 1 here is the fix for the single largest funnel loss in
	 * v2.5: users completed payment, licence, activation and provisioning,
	 * then saw nothing happen because delivery defaulted to off behind a
	 * checkbox. Routing it through EasyOpt_Config::set() fires the
	 * save-coordinator, which clears generated CSS and LCP rows.
	 *
	 * There is no probe gate to wait for: the shared endpoint's certificate
	 * is already live, so delivery is safe the moment credentials exist.
	 */
	private static function store_account( array $body, $plan ) {
		EasyOpt_Config::set( 'easyopt_cloud_account', sanitize_key( (string) ( $body['account'] ?? '' ) ) );
		EasyOpt_Config::set( 'easyopt_cloud_endpoint', esc_url_raw( (string) ( $body['endpoint'] ?? '' ) ) );
		EasyOpt_Config::set( 'easyopt_cloud_plan', sanitize_key( $plan ) );
		EasyOpt_Config::set( 'easyopt_cloud_token', (string) ( $body['token'] ?? '' ) );
		EasyOpt_Config::set( 'easyopt_cloud_key', (string) ( $body['key'] ?? '' ) );
		EasyOpt_Config::set( 'easyopt_cloud_salt', (string) ( $body['salt'] ?? '' ) );
		EasyOpt_Config::set( 'easyopt_cloud_fingerprint', esc_url_raw( get_site_url() ) );
		EasyOpt_Config::set( 'easyopt_cloud_active', 1 );
		EasyOpt_Config::set( 'easyopt_cloud_image_cap', (int) ( $body['image_cap'] ?? 0 ) );
		// A fresh (re)connect clears any prior revoke, so the reconnect prompt and
		// its admin notice disappear the moment a valid licence is connected again.
		delete_option( 'easyopt_cloud_revoked' );
		delete_option( 'easyopt_cloud_revoked_dismissed' );
		// (2.6.1) Proof that THIS site owns its seat. /v3/disconnect now
		// requires it before it will release a host from a multi-site
		// licence — otherwise any one site on the licence could strip
		// another's delivery, and stripping the last one hands the whole
		// Freemius seat back. Free plans have no install id and do not need
		// one; their token already identifies a single site.
		EasyOpt_Config::set( 'easyopt_cloud_activation_id', sanitize_text_field( (string) ( $body['activation_id'] ?? '' ) ) );

		// (2.7.1) Force the connected-account optimization set ON, every connect,
		// regardless of prior state. A connected account is opting into the whole
		// stack — images, edge assets, and the local score-movers that actually
		// shift Lighthouse (Unused CSS inlines critical CSS, which is also what
		// makes edge-served CSS/JS safe: only the deferred sheet leaves origin).
		// Unconditional by design: a reconnect re-asserts the intended config so a
		// site that drifted (or a lapsed trial that fell back) comes back correct.
		// Routed through Config::set() so the save-coordinator clears generated
		// CSS / LCP rows for each change.
		$force = array(
			'easyopt_img_opt'                 => 1,        // Images
			'easyopt_cloud_assets'            => 1,        // CSS, JS & fonts from the edge
			'easyopt_delay_js'                => 1,        // JavaScript Optimization…
			'easyopt_delay_js_method'         => 'delay',  // …delay until interaction
			'easyopt_delay_js_exclude_jquery' => 0,        // jQuery delayed too (best scores)
			'easyopt_unused_css'              => 1,        // Unused CSS…
			'easyopt_unused_css_behavior'     => 'delayed', // …delayed (not async/remove)
			'easyopt_cloud_unused_css'        => 1,        // cloud (headless) Unused CSS
			'easyopt_lazyload_fonts'          => 1,        // Smart Lazyload Fonts
		);
		foreach ( $force as $opt => $val ) {
			EasyOpt_Config::set( $opt, $val );
		}
		delete_transient( 'easyopt_cloud_maint' );

		// (2.6.1) PRIME THE USAGE CACHE from the connect response.
		//
		// Deleting the stale transient and leaving it empty meant the panel
		// had nothing to render until the next successful /usage poll — and
		// that poll cannot happen on the very next request, because the panel
		// loads over REST and EasyOpt_CDN_Cloud::usage() refuses to call out
		// on a non-admin, non-cron request. The result was a freshly connected
		// paid site reporting "Delivery is paused" and "Measuring bandwidth"
		// while its images were being served from the CDN correctly.
		//
		// /connect already returns everything needed for an honest first
		// render. used_bytes is genuinely 0 and meter_stale is genuinely 1
		// (the roll-up runs twice a day), so this states nothing we do not
		// know — it just stops the absence of data reading as bad news.
		set_transient( 'easyopt_cloud_usage', array(
			'used_bytes'  => 0,
			'quota_bytes' => (int) ( $body['quota_bytes'] ?? 0 ),
			'images_used' => 0,
			'image_cap'   => (int) ( $body['image_cap'] ?? 0 ),
			'plan'        => (string) $plan,
			'trial_days_left' => (int) ( $body['trial_days_left'] ?? 0 ),
			'active'      => 1,
			'reason'      => '',
			'period'      => gmdate( 'Y-m' ),
			'meter_stale' => 1,
			'pricing_url' => '',
		), HOUR_IN_SECONDS );

		// CLEAR THE PAGE CACHE. Without this the site keeps serving cached
		// HTML built before the connection, so nothing appears to happen and
		// the user concludes it did not work. The save-coordinator clears
		// Used CSS, Elementor CSS and LCP rows when img_opt flips, but not
		// the page cache itself — the legacy connect handler called this
		// explicitly for exactly the same reason.
		if ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'clear_all' ) ) {
			EasyOpt_Cache::clear_all();
		}

		// (2.6.1) SIGN THE FREE MAP NOW, don't wait for maintain().
		//
		// Free delivery is a lookup against the signed map, and the map is
		// built by maintain() — which is admin/cron-only and throttled 15 min.
		// So a freshly connected free site sat at "0 of 50 images optimized"
		// until an admin happened to reload a page a quarter-hour later, with
		// nothing on screen to say it was working. Signing the homepage set
		// here means the map, the image count and delivery are all live the
		// instant the user connects. Paid signs locally and needs no map, so
		// this is free-only.
		if ( class_exists( 'EasyOpt_CDN_Cloud' ) && EasyOpt_CDN_Cloud::is_unpaid()
			&& method_exists( 'EasyOpt_CDN_Cloud', 'map_refresh' ) ) {
			EasyOpt_CDN_Cloud::map_refresh( EasyOpt_CDN_Cloud::collect_originals() );
		}
	}

	/* ─────────────────────────────────────────────
	 *  Usage / disconnect / upgrade
	 * ───────────────────────────────────────────── */

	// No type hint: `WP_REST_Request $req = null` is an implicitly-nullable
	// parameter, deprecated in PHP 8.4. `?WP_REST_Request` would fix it but
	// this is also called internally with no argument, and a bare parameter
	// works identically on every PHP version the plugin supports (§8.11).
	public static function usage( $req = null ) {
		$force = ( $req instanceof WP_REST_Request ) && (bool) $req->get_param( 'force' );
		$u     = EasyOpt_CDN_Cloud::usage( $force );
		$map   = EasyOpt_CDN_Cloud::map();

		return rest_ensure_response( array(
			'connected'   => EasyOpt_CDN_Cloud::is_connected(),
			'delivering'  => EasyOpt_CDN_Cloud::is_delivering(),
			'plan'        => EasyOpt_CDN_Cloud::plan(),
			'account'     => EasyOpt_CDN_Cloud::account(),
			'usage'       => is_array( $u ) ? $u : null,
			'images_live' => is_array( $map['urls'] ) ? count( $map['urls'] ) : 0,
			'analysis'    => get_option( 'easyopt_cloud_analysis' ) ?: null,
		) );
	}

	public static function disconnect() {
		self::track( 'disconnected', array( 'plan' => EasyOpt_CDN_Cloud::plan() ) );
		EasyOpt_CDN_Cloud::disconnect();
		return rest_ensure_response( array(
			'success' => true,
			'message' => __( 'Disconnected. Images are served from your own server again.', 'easy-optimizer' ),
		) );
	}

	/**
	 * Start an upgrade.
	 *
	 * Under Freemius a purchase cannot be completed server-side — checkout is
	 * hosted. So this returns a URL for the panel to open, and the plan only
	 * changes when Freemius fires a webhook at the control plane. The panel
	 * then polls /cloud/usage and picks up the new plan.
	 *
	 * Deliberately does NOT write any plan state here. A client that could
	 * talk itself into a paid plan would be a free upgrade for anyone who
	 * can open devtools.
	 */
	public static function upgrade( WP_REST_Request $req ) {
		$plan = sanitize_key( (string) $req->get_param( 'plan' ) );
		$resp = wp_remote_post(
			apply_filters( 'easyopt_cloud_api', EasyOpt_CDN_Cloud::API ) . 'upgrade',
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'token' => EasyOpt_CDN_Cloud::token(),
					'plan'  => $plan ? $plan : 'single',
				) ),
			)
		);
		$body = is_wp_error( $resp ) ? null : json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( ! is_array( $body ) || empty( $body['ok'] ) || empty( $body['checkout_url'] ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'code'    => is_array( $body ) ? (string) ( $body['code'] ?? 'failed' ) : 'failed',
				'message' => is_array( $body ) && ! empty( $body['message'] )
					? (string) $body['message']
					: __( 'Could not open checkout. Please try again in a moment.', 'easy-optimizer' ),
			) );
		}

		self::track( 'upgrade_started', array( 'plan' => $plan ) );

		return rest_ensure_response( array(
			'success'      => true,
			'checkout_url' => esc_url_raw( (string) $body['checkout_url'] ),
			'plan'         => (string) ( $body['plan'] ?? $plan ),
		) );
	}

	/**
	 * Called by the panel after the checkout window closes.
	 *
	 * Re-reads entitlement from the control plane. If the webhook has landed
	 * the plan is now paid, and the account switches to local signing on the
	 * paid endpoint — so the stale free URL map is dropped.
	 */

	public static function refresh() {
		$u = EasyOpt_CDN_Cloud::usage( true );
		if ( is_array( $u ) && ! empty( $u['plan'] ) && 'free' !== $u['plan'] ) {
			$c = wp_remote_post(
				apply_filters( 'easyopt_cloud_api', EasyOpt_CDN_Cloud::API ) . 'connect',
				array(
					'timeout' => 20,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( array(
						'license_key'  => (string) EasyOpt_Config::get( 'cloud_license_key', '' ),
						'site'         => esc_url_raw( get_site_url() ),
						'install_hash' => EasyOpt_CDN_Cloud::install_hash(),
					) ),
				)
			);
			$cb = is_wp_error( $c ) ? null : json_decode( wp_remote_retrieve_body( $c ), true );
			if ( is_array( $cb ) && ! empty( $cb['ok'] ) ) {
				self::store_account( $cb, (string) ( $cb['plan'] ?? 'single' ) );
				// Paid rides a different zone and signs locally, so the free
				// map is both stale and unnecessary.
				delete_option( EasyOpt_CDN_Cloud::MAP_OPTION );
				self::track( 'upgraded', array( 'plan' => (string) ( $cb['plan'] ?? '' ) ) );
			}
		}
		return self::usage();
	}
}
