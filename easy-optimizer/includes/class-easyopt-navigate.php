<?php
/**
 * Prefetch Pages — Easy Optimizer's speculative navigation engine.
 *
 * WHY THIS EXISTS
 * ---------------
 * The next page a visitor asks for is almost always a page we could have
 * started fetching before they clicked. The browser has a native mechanism for
 * exactly that — the Speculation Rules API — and it already knows things no
 * script can cheaply learn: where the pointer is heading, how much memory and
 * battery are available, how many speculations are safe to have in flight, and
 * when to throw one away. Our job is NOT to re-implement that in JavaScript.
 * Our job is to hand the browser a small, early, correct, well-ranked
 * description of which links are worth speculating on, and then get out of the
 * way.
 *
 * WHAT THIS REPLACES (2.6.7)
 * --------------------------
 * Up to 2.6.6 this feature shipped `easyoptlink.js`, a quicklink derivative.
 * That design lost to WordPress Core in the one measurement that matters — how
 * early the browser learns about a candidate — because the chain was:
 *
 *     footer <script src> download → parse → requestIdleCallback (≤2000ms)
 *     → querySelectorAll('a') → IntersectionObserver registration
 *     → intersection callback → one <script type=speculationrules> per URL
 *     → (prefetch path only) a setInterval(…, 1000) release valve
 *
 * Every step there is latency the browser did not need to wait for, and the
 * last step deliberately added up to a full second. Worse, Core's own rules
 * were never switched off, so a page ran BOTH systems: Core prefetching on
 * pointerdown and up to eight per-URL EasyOptLink prerender scripts churning
 * in and out of the DOM on scroll.
 *
 * The engine below emits ONE `<script type="speculationrules">` in `<head>`,
 * ships no external JavaScript, and takes explicit ownership of speculative
 * loading so Core stops emitting a competing ruleset.
 *
 * THE LATENCY CHAIN NOW
 * ---------------------
 *     <head> parse → rules registered → (user intent) → browser speculates
 *
 * DESIGN NOTES
 * ------------
 * • ONE script node, at most three rules inside it. Never one rule per URL.
 * • `source: "document"` — the browser matches links itself, so a page with
 *   30 links costs exactly what a page with 10 links costs on our side: zero.
 *   There is no DOM scan, no observer, no queue, no timer.
 * • Two-stage intent escalation, expressed declaratively:
 *       prefetch  @ moderate      — ~200ms hover / focus. Cheap: document only.
 *       prerender @ conservative  — pointerdown / touchstart. Expensive, so it
 *                                   waits for the strongest pre-click signal.
 *   Chromium reuses the already-prefetched response when the prerender for the
 *   same URL starts, so the two stages compose instead of duplicating.
 * • Candidate ranking is declarative too: links in the page body and navigation
 *   get the hover-level rule; links in the footer / contentinfo landmark get
 *   the pointerdown-level rule. Footer links are real navigations but low
 *   probability, and they are the bulk of the "30 links" on a typical page.
 * • Browser-enforced budgets are the only budgets. Chromium caps non-eager
 *   prefetch at 50 and non-eager prerender at 2, and evicts by its own policy.
 *   We do not inject, remove or re-serialise rules to game those caps.
 *
 * @package EasyOptimizer
 * @since   2.6.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EasyOpt_Navigate {

	/** id of the emitted rules element — also the handle the guard script uses. */
	const SCRIPT_ID = 'easyopt-speculation';

	/** Links here are real but low-probability; they get pointerdown, not hover. */
	const LOW_INTENT_SELECTOR = 'footer a, [role="contentinfo"] a, .site-footer a';

	/** Per-request memo for is_active(). */
	private static $active = null;

	/** Populated by build(); read by the debug reporter. */
	private static $stats = array();

	/**
	 * Raw (unprefixed) exclude paths contributed by other plugins through
	 * `wp_speculation_rules_href_exclude_paths`. Populated by build(), read by
	 * guard_script() so the fallback honours them too.
	 *
	 * @var string[]
	 */
	private static $third_party = array();

	/**
	 * Register hooks.
	 *
	 * Deliberately cheap and deliberately early-exiting: when the feature is
	 * OFF we register NOTHING, so WordPress Core's speculative loading behaves
	 * exactly as it does without this plugin installed. The full gate (login
	 * state, request type, permalinks) cannot be evaluated this early, so it
	 * runs lazily inside is_active() — both consumers below fire long after
	 * `wp`, where every conditional has resolved.
	 */
	public static function init() {

		if ( 1 !== (int) EasyOpt_Config::get( 'instant_preload', 0 ) ) {
			return;
		}

		// Take ownership of speculative loading (WP 6.8+). Harmless no-op on
		// older WordPress, which has no speculative loading to own.
		add_filter( 'wp_speculation_rules_configuration', array( __CLASS__, 'disable_core' ), 99 );

		// Priority 3 is the earliest slot that costs nothing.
		//
		// Core prints the <title> and wp_preload_resources at wp_head priority
		// 1 and wp_resource_hints at 2, then stylesheets at 8 and head scripts
		// at 9. Slotting in at 3 puts the rules a few hundred bytes into the
		// document — active long before the visitor can hover a link, and
		// typically inside the first congestion window — without displacing a
		// single render-critical byte. It also means the guard script below
		// runs BEFORE the first stylesheet link, so it is never held behind a
		// CSS download.
		//
		// For comparison, Core prints its own rules on `wp_footer`.
		add_action( 'wp_head', array( __CLASS__, 'print_rules' ), 3 );
	}

	/* ─────────────────────────────────────────────
	 *  Ownership
	 * ───────────────────────────────────────────── */

	/**
	 * Turn off Core's own speculation rules for this request.
	 *
	 * Returning null from `wp_speculation_rules_configuration` is Core's
	 * documented "speculative loading is disabled" signal:
	 * wp_get_speculation_rules() bails, and wp_print_speculation_rules() — the
	 * `wp_footer` callback — prints nothing. That is what prevents a page from
	 * carrying two competing rulesets.
	 *
	 * The guard matters: if our own rules will not be printed for this request
	 * (logged-in visitor, plain permalinks, a feed), we must leave Core alone,
	 * or turning our feature on would make a page speculate LESS than it did
	 * with the plugin uninstalled.
	 *
	 * @param array<string,string>|null $config Core's configuration.
	 * @return array<string,string>|null
	 */
	public static function disable_core( $config ) {
		return self::is_active() ? null : $config;
	}

	/**
	 * Is the engine emitting rules on this request?
	 *
	 * @return bool
	 */
	public static function is_active() {

		if ( null !== self::$active ) {
			return self::$active;
		}

		/**
		 * Filter whether Easy Optimizer owns speculative loading on this request.
		 *
		 * Returning false restores WordPress Core's own behaviour completely —
		 * Core's rules are printed and ours are not.
		 *
		 * @since 2.6.7
		 * @param bool $active Whether the engine is active.
		 */
		$active = (bool) apply_filters( 'easyopt_navigate_active', self::compute_active() );

		// Conditional tags (is_feed, is_admin, the WooCommerce ones) only give a
		// real answer once `wp` has fired. A third party can call
		// wp_get_speculation_rules_configuration() earlier than that; answer it,
		// but do not freeze a memo built from unresolved conditionals.
		if ( did_action( 'wp' ) ) {
			self::$active = $active;
		}

		return $active;
	}

	/**
	 * The real gate. Split out so is_active() stays a memo + filter.
	 *
	 * @return bool
	 */
	private static function compute_active() {

		if ( 1 !== (int) EasyOpt_Config::get( 'instant_preload', 0 ) ) {
			return false;
		}

		// Master per-request debug switch (?nooptimize). No dedicated switch is
		// added here: the Debug Issues panel deliberately exposes four
		// category-level switches, and this feature cannot break rendering.
		if ( function_exists( 'easyopt_debug_switch' ) && easyopt_debug_switch( 'nooptimize' ) ) {
			return false;
		}

		// Front-end HTML page views only.
		if ( is_admin()
			|| is_feed()
			|| is_embed()
			|| ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
			return false;
		}

		// Plain permalinks make every URL a query-string URL, and query-string
		// URLs are exactly the ones speculation must not touch (they are
		// uncacheable and are where nonces and cart actions live). Core refuses
		// to speculate on such sites for the same reason; matching it keeps the
		// two systems' answers identical instead of merely different.
		if ( '' === (string) get_option( 'permalink_structure', '' ) ) {
			return false;
		}

		// Logged-in visitors: personalised markup, admin-bar links, nonce-bearing
		// action links. Core disables speculative loading outright for them; this
		// plugin's own gate is a superset (it also lets a membership site opt back
		// in via `easyopt_skip_for_logged_in`), so route through it.
		if ( function_exists( 'easyopt_skip_for_logged_in' ) && easyopt_skip_for_logged_in( 'prefetch' ) ) {
			return false;
		}

		return true;
	}

	/* ─────────────────────────────────────────────
	 *  Output
	 * ───────────────────────────────────────────── */

	/**
	 * Print the ruleset (and the tiny runtime guard) into <head>.
	 */
	public static function print_rules() {

		// A theme or a broken output buffer can run wp_head twice; two copies
		// of the same ruleset would be two rule sets to the browser.
		static $done = false;
		if ( $done || ! self::is_active() ) {
			return;
		}
		$done = true;

		$rules = self::build();
		if ( empty( $rules ) ) {
			return;
		}

		$json = wp_json_encode( $rules, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES );
		if ( false === $json ) {
			return;
		}

		$debug = self::debug_requested();

		$attrs = ' type="speculationrules" id="' . self::SCRIPT_ID . '"';
		if ( $debug ) {
			foreach ( self::$stats as $k => $v ) {
				$attrs .= ' data-eo-' . $k . '="' . esc_attr( (string) $v ) . '"';
			}
		}

		// Not escaped through esc_html(): this is a JSON document body, encoded
		// with JSON_HEX_TAG so no `<` can survive to close the element early.
		echo '<script' . $attrs . '>' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::guard_script( $debug ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( class_exists( 'EasyOpt_Debug_Log' ) && EasyOpt_Debug_Log::level_enabled( 'info' ) ) {
			$pairs = array();
			foreach ( self::$stats as $k => $v ) {
				$pairs[] = $k . '=' . $v;
			}
			EasyOpt_Debug_Log::info( 'navigate', 'rules emitted: ' . implode( ' ', $pairs ) );
		}
	}

	/**
	 * `?eonavdebug` — per-request observability, off in production.
	 *
	 * @return bool
	 */
	private static function debug_requested() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['eonavdebug'] );
	}

	/* ─────────────────────────────────────────────
	 *  Rule construction
	 * ───────────────────────────────────────────── */

	/**
	 * Build the complete rules payload.
	 *
	 * @return array<string,mixed> JSON-ready structure (possibly empty).
	 */
	private static function build() {

		list( $prefetch_eagerness, $prerender_eagerness ) = self::eagerness_profile();

		$excludes = self::href_exclude_patterns();
		$prerender_on = 1 === (int) EasyOpt_Config::get( 'instant_prerender', 1 );

		$href_patterns = array();
		foreach ( $excludes as $token ) {
			$href_patterns[] = self::to_url_pattern( $token );
		}

		/**
		 * Core's documented integration point for plugins that own an unsafe
		 * URL space. We disabled Core's rules, so we inherit Core's contract:
		 * anything a third party excluded there must stay excluded here.
		 *
		 * @see wp_get_speculation_rules()
		 */
		self::$third_party = array();
		foreach ( (array) apply_filters( 'wp_speculation_rules_href_exclude_paths', array(), 'prefetch' ) as $path ) {
			if ( is_string( $path ) && '' !== $path ) {
				self::$third_party[] = $path;
				$href_patterns[]     = self::prefix( $path, 'home' );
			}
		}

		$href_patterns = array_values( array_unique( $href_patterns ) );

		$root = self::prefix( '/*', 'home' );

		// URL safety, shared verbatim by every rule we emit.
		$base = array(
			array( 'href_matches' => $root ),
			array( 'not' => array( 'href_matches' => $href_patterns ) ),
		);

		$rules  = array();
		$tiered = 'conservative' !== $prefetch_eagerness;

		$prefetch_sel  = self::selector_excludes( 'prefetch' );
		$prerender_sel = self::selector_excludes( 'prerender' );

		if ( $tiered ) {
			// High-probability zone: everything that is not the footer.
			$main    = $base;
			$main[]  = array( 'not' => array( 'selector_matches' => $prefetch_sel . ', ' . self::LOW_INTENT_SELECTOR ) );
			$rules[] = array( 'prefetch', 'eo-main', $main, $prefetch_eagerness );

			// Low-probability zone: real navigations, but they do not deserve a
			// speculative fetch merely because the pointer crossed them.
			$low     = $base;
			$low[]   = array( 'not' => array( 'selector_matches' => $prefetch_sel ) );
			$low[]   = array( 'selector_matches' => self::LOW_INTENT_SELECTOR );
			$rules[] = array( 'prefetch', 'eo-low-intent', $low, 'conservative' );
		} else {
			// User asked for pointerdown-only prefetching; the two tiers would
			// be byte-for-byte identical, so emit one rule instead of two.
			$one     = $base;
			$one[]   = array( 'not' => array( 'selector_matches' => $prefetch_sel ) );
			$rules[] = array( 'prefetch', 'eo-main', $one, 'conservative' );
		}

		if ( $prerender_on ) {
			$pre     = $base;
			$pre[]   = array(
				'not' => array(
					'selector_matches' => $tiered
						? $prerender_sel . ', ' . self::LOW_INTENT_SELECTOR
						: $prerender_sel,
				),
			);
			$rules[] = array( 'prerender', 'eo-prerender', $pre, $prerender_eagerness );
		}

		self::$stats = array(
			'rules'      => count( $rules ),
			'href-excl'  => count( $href_patterns ),
			'prefetch'   => $prefetch_eagerness,
			'prerender'  => $prerender_on ? $prerender_eagerness : 'off',
			'tiered'     => $tiered ? 1 : 0,
		);

		return self::assemble( $rules );
	}

	/**
	 * Turn the internal rule list into the JSON structure.
	 *
	 * Uses Core's WP_Speculation_Rules when it exists so the documented
	 * `wp_load_speculation_rules` action still fires and third-party rules
	 * still reach the page — taking ownership of speculation should not mean
	 * silently deleting another plugin's rules. Falls back to a plain array on
	 * WordPress < 6.8, which has neither the class nor the action.
	 *
	 * @param array<int,array{0:string,1:string,2:array,3:string}> $rules
	 * @return array<string,mixed>
	 */
	private static function assemble( array $rules ) {

		if ( class_exists( 'WP_Speculation_Rules' ) ) {
			$obj = new WP_Speculation_Rules();
			foreach ( $rules as $r ) {
				$obj->add_rule(
					$r[0],
					$r[1],
					array(
						'source'    => 'document',
						'where'     => array( 'and' => $r[2] ),
						'eagerness' => $r[3],
					)
				);
			}

			/** This action is documented in wp-includes/speculative-loading.php */
			do_action( 'wp_load_speculation_rules', $obj );

			return (array) $obj->jsonSerialize();
		}

		$out = array();
		foreach ( $rules as $r ) {
			$out[ $r[0] ][] = array(
				'source'    => 'document',
				'where'     => array( 'and' => $r[2] ),
				'eagerness' => $r[3],
			);
		}
		return $out;
	}

	/**
	 * Map the user's Eagerness setting onto the two speculation stages.
	 *
	 * The setting names the PREFETCH stage — the cheap one the user is really
	 * choosing between. Prerender is always one notch more cautious, because a
	 * prerender is a complete page load (scripts, subresources, paint) and is
	 * never worth spending on a signal as weak as a pointer passing by.
	 *
	 * `immediate` is accepted for backward compatibility with settings saved by
	 * 2.6.6 and clamped to `eager`. It is forbidden on document-level rules by
	 * both Core and this engine: on a document rule it means "fetch every
	 * matching link on the page right now", which on a 50-link archive is 50
	 * uncalled-for document fetches.
	 *
	 * @return array{0:string,1:string} [prefetch eagerness, prerender eagerness]
	 */
	private static function eagerness_profile() {

		$e = (string) EasyOpt_Config::get( 'instant_eagerness', 'moderate' );

		switch ( $e ) {
			case 'conservative':
				return array( 'conservative', 'conservative' );
			case 'eager':
			case 'immediate':
				return array( 'eager', 'moderate' );
			case 'moderate':
			default:
				return array( 'moderate', 'conservative' );
		}
	}

	/* ─────────────────────────────────────────────
	 *  Exclusions
	 * ───────────────────────────────────────────── */

	/**
	 * The canonical unsafe-URL model, as `*`-wildcard tokens matched against
	 * `path[?query]`.
	 *
	 * One model, two renderers: to_url_pattern() for the speculation rules and
	 * to_js_regex() for the non-Chromium fallback. Keeping a single source is
	 * what stops the two paths from drifting into different ideas of "safe".
	 *
	 * @return string[]
	 */
	private static function href_exclude_patterns() {

		static $memo = null;
		if ( null !== $memo ) {
			return $memo;
		}

		$p = array(
			// WordPress itself. `wp-*.php` covers wp-login.php (log in, log out,
			// lost password) and every other front controller.
			'@site:/wp-*.php',
			'@site:/wp-admin/*',
			'@site:/wp-includes/*',
			'@content:/*',
			'/wp-json/*',

			// Feeds are documents, but never navigations.
			'/feed/*',
			'*/feed/*',

			// Files. Media Library downloads all live under wp-content and are
			// already excluded above; these catch the rest. `a[download]` is
			// handled on the selector side.
			'*.php',
			'*.pdf',
			'*.zip',
			'*.xml',
			'*.txt',
		);

		// The REST root is filterable and can be moved off /wp-json/.
		if ( function_exists( 'rest_get_url_prefix' ) ) {
			$prefix = trim( (string) rest_get_url_prefix(), '/' );
			if ( '' !== $prefix && 'wp-json' !== $prefix ) {
				$p[] = '/' . $prefix . '/*';
			}
		}

		// ── Query strings ────────────────────────────────────────────────
		// A query-string URL is the single most dangerous thing to speculate on
		// and the least likely to pay off: it carries nonces and cart actions,
		// and it can never be a page-cache HIT unless the site has explicitly
		// asked for that parameter to be cached. Core excludes all of them on a
		// pretty-permalink site; so do we, unless the site owner has configured
		// "Cache Query String" — in which case the faceted URLs ARE cacheable
		// and worth speculating, and we fall back to naming the unsafe
		// parameters individually.
		$cached_query_params = array();
		if ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'get_query_string_params' ) ) {
			$cached_query_params = (array) EasyOpt_Cache::get_query_string_params();
		}

		if ( empty( $cached_query_params ) ) {
			$p[] = '@raw:' . self::prefix( '/*\?(.+)', 'home' );
		} else {
			foreach ( array(
				'_wpnonce', 'nonce', 'add-to-cart', 'remove_item', 'undo_item',
				'wc-ajax', 'download_file', 'delete_item', 'apply_coupon',
				'remove_coupon', 'order_again',
			) as $param ) {
				$p[] = '*?*' . $param . '=*';
			}
			$p[] = '*?*action=logout*';
		}

		// ── WooCommerce ──────────────────────────────────────────────────
		// Cart, checkout and my-account are session-bearing: their HTML differs
		// per visitor, they are excluded from the page cache, and prerendering
		// one runs the whole WooCommerce session and template stack for a
		// navigation that may never happen. Slugs are read from WooCommerce so
		// renamed and translated pages are covered.
		foreach ( array( 'wc_get_cart_url', 'wc_get_checkout_url' ) as $fn ) {
			if ( function_exists( $fn ) ) {
				$path = wp_parse_url( (string) call_user_func( $fn ), PHP_URL_PATH );
				if ( $path ) {
					$p[] = rtrim( $path, '/' ) . '*';
				}
			}
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$path = wp_parse_url( (string) wc_get_page_permalink( 'myaccount' ), PHP_URL_PATH );
			if ( $path ) {
				$p[] = rtrim( $path, '/' ) . '*';
			}
		}

		// ── User exclusions ──────────────────────────────────────────────
		foreach ( self::user_lines( 'instant_preload_exclude_urls' ) as $line ) {
			$token = self::user_line_to_token( $line );
			if ( '' !== $token ) {
				$p[] = $token;
			}
		}

		$memo = array_values( array_unique( $p ) );
		return $memo;
	}

	/**
	 * Convert one line of the "Exclude URLs" field into an internal token.
	 *
	 * Semantics deliberately match `\EasyOpt\Cache\url_matches_pattern()`, the
	 * matcher every other Exclude URLs field in the plugin uses: a line with no
	 * `*` is a substring, a line with `*` is a wildcard. Before 2.6.7 this field
	 * was the one exception — it was shipped to the browser as a raw substring
	 * and `*` matched literally.
	 *
	 * A line naming a query parameter (`add-to-cart=`, `?ref=`) is routed to the
	 * query side of the URL rather than the path, because that is where the
	 * user meant it.
	 *
	 * @param string $line Raw setting line.
	 * @return string Token, or '' to skip.
	 */
	private static function user_line_to_token( $line ) {

		$line = trim( (string) $line );
		if ( '' === $line ) {
			return '';
		}

		// Absolute URLs: keep same-origin ones as path+query, drop the rest —
		// a cross-origin link is already excluded by the same-origin root rule.
		if ( preg_match( '#^https?://#i', $line ) ) {
			$host = wp_parse_url( $line, PHP_URL_HOST );
			$here = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
			if ( $host && $here && strtolower( $host ) !== strtolower( $here ) ) {
				return '';
			}
			$path  = (string) wp_parse_url( $line, PHP_URL_PATH );
			$query = (string) wp_parse_url( $line, PHP_URL_QUERY );
			$line  = $path . ( '' !== $query ? '?' . $query : '' );
		}

		$line = ltrim( $line, '/' );
		if ( '' === $line ) {
			return '';
		}

		// Wrap in wildcards for substring semantics, but never produce `**` —
		// a doubled wildcard is meaningless and risks an unparseable pattern.
		$wrap = static function ( $s ) {
			$s = ( '' !== $s && '*' === $s[0] ) ? $s : '*' . $s;
			return ( '' !== $s && '*' === substr( $s, -1 ) ) ? $s : $s . '*';
		};

		if ( false !== strpos( $line, '?' ) ) {
			list( $path, $query ) = array_pad( explode( '?', $line, 2 ), 2, '' );
			return $wrap( $path ) . '?' . $wrap( $query );
		}

		// `key=value` with no path: the user is naming a query parameter.
		if ( false !== strpos( $line, '=' ) && false === strpos( $line, '/' ) ) {
			return '*?' . $wrap( $line );
		}

		return $wrap( $line );
	}

	/**
	 * Read a multi-line textarea setting into trimmed, non-empty lines.
	 *
	 * @param string $key Setting key without the `easyopt_` prefix.
	 * @return string[]
	 */
	private static function user_lines( $key ) {
		$raw = (string) EasyOpt_Config::get( $key, '' );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * CSS selectors whose anchors must never be speculated.
	 *
	 * @param string $mode 'prefetch' or 'prerender'.
	 * @return string
	 */
	private static function selector_excludes( $mode ) {

		static $memo = array();
		if ( isset( $memo[ $mode ] ) ) {
			return $memo[ $mode ];
		}

		$sel = array(
			// Preserved from the 2.6.6 runtime, which skipped these anchors
			// outright, and from Core, which excludes rel=nofollow because
			// plugins mark action links with it.
			'a[rel~="nofollow"]',
			'a[rel~="external"]',
			'a[download]',
			// Core's opt-out convention. `.no-prefetch` is honoured by the
			// prerender rule too, exactly as Core does in prerender mode.
			'.no-prefetch, .no-prefetch a',
		);

		if ( 'prerender' === $mode ) {
			$sel[] = '.no-prerender, .no-prerender a';
		}

		foreach ( self::user_lines( 'instant_preload_exclude_selectors' ) as $line ) {
			$line = self::safe_selector( $line );
			if ( '' !== $line ) {
				$sel[] = $line;
			}
		}

		$memo[ $mode ] = implode( ', ', $sel );
		return $memo[ $mode ];
	}

	/**
	 * Reject a user selector that would invalidate the whole ruleset.
	 *
	 * An unparseable selector inside `selector_matches` makes the browser throw
	 * the entire rule away, so a stray `{` in one field would silently switch
	 * the feature off site-wide. This is a conservative character-class and
	 * balance check, not a CSS parser — it catches the realistic typos.
	 *
	 * @param string $sel Raw selector line.
	 * @return string Selector, or '' if it is not safely emittable.
	 */
	private static function safe_selector( $sel ) {

		$sel = trim( (string) $sel, " \t\n\r\0\x0B," );
		if ( '' === $sel ) {
			return '';
		}
		if ( ! preg_match( '/^[A-Za-z0-9_\-\.\#\[\]\=\"\'\:\(\)\,\s\>\+\~\*\^\$\|\\\\]+$/', $sel ) ) {
			return '';
		}
		foreach ( array( array( '[', ']' ), array( '(', ')' ) ) as $pair ) {
			if ( substr_count( $sel, $pair[0] ) !== substr_count( $sel, $pair[1] ) ) {
				return '';
			}
		}
		if ( 0 !== substr_count( $sel, '"' ) % 2 || 0 !== substr_count( $sel, "'" ) % 2 ) {
			return '';
		}
		return $sel;
	}

	/* ─────────────────────────────────────────────
	 *  Pattern rendering
	 * ───────────────────────────────────────────── */

	/**
	 * Render an internal token as a URLPattern string for `href_matches`.
	 *
	 * Token grammar:
	 *   `@raw:<pattern>`      — already a URLPattern; emitted verbatim.
	 *   `@site:<path>`        — prefixed with the site (WordPress) base path.
	 *   `@content:<path>`     — prefixed with the wp-content base path.
	 *   `/path/...`           — anchored at the home base path.
	 *   `*...`                — unanchored; matches anywhere in the path.
	 *   `...?...`             — the first `?` separates path from query.
	 *
	 * Every character other than `*` is escaped, so a dot in a slug stays a
	 * dot and a `(` in a permalink cannot open a regex group.
	 *
	 * @param string $token Internal token.
	 * @return string URLPattern string.
	 */
	private static function to_url_pattern( $token ) {

		$context = 'home';
		if ( 0 === strpos( $token, '@raw:' ) ) {
			return substr( $token, 5 );
		}
		if ( 0 === strpos( $token, '@site:' ) ) {
			$context = 'site';
			$token   = substr( $token, 6 );
		} elseif ( 0 === strpos( $token, '@content:' ) ) {
			$context = 'content';
			$token   = substr( $token, 9 );
		}

		$q = strpos( $token, '?' );
		if ( false === $q ) {
			$path  = $token;
			$query = null;
		} else {
			$path  = substr( $token, 0, $q );
			$query = substr( $token, $q + 1 );
		}

		$pattern = '/' . self::escape_pattern( ltrim( $path, '/' ) );
		if ( null !== $query ) {
			$pattern .= '\?' . self::escape_pattern( $query );
		}

		return self::prefix( $pattern, $context );
	}

	/**
	 * Escape URLPattern syntax, keeping `*` as the wildcard.
	 *
	 * @param string $s Literal-with-wildcards fragment.
	 * @return string
	 */
	private static function escape_pattern( $s ) {
		return preg_replace( '/([\\\\:?#{}()+])/', '\\\\$1', (string) $s );
	}

	/**
	 * Prefix a path pattern for subdirectory installs.
	 *
	 * Uses Core's WP_URL_Pattern_Prefixer when present (WP 6.8+) so our
	 * patterns are prefixed exactly the way Core's are; falls back to the same
	 * algorithm for older WordPress.
	 *
	 * @param string $pattern Path pattern beginning with '/'.
	 * @param string $context 'home' | 'site' | 'content'.
	 * @return string
	 */
	private static function prefix( $pattern, $context = 'home' ) {

		static $prefixer = null;

		if ( class_exists( 'WP_URL_Pattern_Prefixer' ) ) {
			if ( null === $prefixer ) {
				$prefixer = new WP_URL_Pattern_Prefixer();
			}
			return $prefixer->prefix_path_pattern( $pattern, $context );
		}

		$base = self::base_path( $context );
		$base = '/' === $base ? '/' : trailingslashit( $base );

		if ( 0 === strpos( $pattern, $base ) ) {
			return $pattern;
		}
		return $base . ltrim( $pattern, '/' );
	}

	/**
	 * Render the same token model as a JavaScript regular-expression source,
	 * anchored at the start of the URL's path.
	 *
	 * Only used by the non-Chromium fallback below.
	 *
	 * @param string $token Internal token.
	 * @return string Regex source fragment, or '' when the token cannot be
	 *                expressed (a `@raw:` URLPattern).
	 */
	private static function to_js_regex( $token ) {

		if ( 0 === strpos( $token, '@raw:' ) ) {
			return '';
		}
		$context = 'home';
		if ( 0 === strpos( $token, '@site:' ) ) {
			$context = 'site';
			$token   = substr( $token, 6 );
		} elseif ( 0 === strpos( $token, '@content:' ) ) {
			$context = 'content';
			$token   = substr( $token, 9 );
		}

		$base = self::base_path( $context );

		$anchored = ( '' !== $token && '*' !== $token[0] );
		$body     = self::escape_js_regex( ltrim( $token, '/' ) );

		return ( $anchored ? '^' . self::escape_js_regex( rtrim( $base, '/' ) ) . '/' : '' ) . $body;
	}

	/**
	 * Base path for a URL context, resolved once per request.
	 *
	 * site_url() / content_url() / home_url() each run a filter chain, and the
	 * exclusion list asks for a base a dozen or more times per page.
	 *
	 * @param string $context 'home' | 'site' | 'content'.
	 * @return string Path with a leading slash.
	 */
	private static function base_path( $context ) {

		static $memo = array();
		if ( isset( $memo[ $context ] ) ) {
			return $memo[ $context ];
		}

		switch ( $context ) {
			case 'site':
				$base = (string) wp_parse_url( site_url( '/' ), PHP_URL_PATH );
				break;
			case 'content':
				$base = (string) wp_parse_url( content_url( '/' ), PHP_URL_PATH );
				break;
			default:
				$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		}

		$memo[ $context ] = ( '' === $base ) ? '/' : $base;
		return $memo[ $context ];
	}

	/**
	 * Escape a literal-with-wildcards fragment for a JavaScript RegExp source,
	 * turning `*` into `.*`.
	 *
	 * Deliberately not preg_quote(): that also escapes `/` and `-`, which
	 * JavaScript does not need, and this string ships on every page view.
	 *
	 * @param string $s Fragment.
	 * @return string
	 */
	private static function escape_js_regex( $s ) {
		$out = '';
		$len = strlen( (string) $s );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $s[ $i ];
			if ( '*' === $ch ) {
				$out .= '.*';
			} elseif ( false !== strpos( '.^$+?()[]{}|\\', $ch ) ) {
				$out .= '\\' . $ch;
			} else {
				$out .= $ch;
			}
		}
		return $out;
	}

	/* ─────────────────────────────────────────────
	 *  Runtime guard (inline, ~0.7KB, no request)
	 * ───────────────────────────────────────────── */

	/**
	 * The only JavaScript this feature ships. Three jobs, in order:
	 *
	 *   1. Withdraw the rules on Save-Data or a 2G-class connection. This has
	 *      to happen on the client: the page HTML is shared by every visitor
	 *      through the page cache, so the server cannot decide it.
	 *
	 *   2. For browsers with no Speculation Rules support — today Firefox;
	 *      Safari ignores document `<link rel=prefetch>` so it is a no-op
	 *      there — a minimal `<link rel=prefetch>` fallback. One delegated
	 *      listener set, one shared dwell timer, a hard cap. No per-anchor
	 *      listeners, no observers, no DOM scan.
	 *
	 *   3. Where Speculation Rules ARE supported, the prediction layer: we
	 *      choose the candidate and hand the browser one URL to act on now,
	 *      instead of waiting for its own hover threshold. See predict_script().
	 *
	 * @param bool $debug Emit the `?eonavdebug` reporter as well.
	 * @return string <script> element.
	 */
	private static function guard_script( $debug = false ) {

		$re = array();
		foreach ( self::href_exclude_patterns() as $token ) {
			$rx = self::to_js_regex( $token );
			if ( '' !== $rx ) {
				$re[] = $rx;
			}
		}

		// Third-party exclusions arrive as URLPattern strings. The ones built
		// from literals and `*` translate to a regex exactly; anything using
		// URLPattern's group syntax (`:name`, `(re)`, `{}`) does not, and is
		// skipped rather than guessed at. Chromium — where those plugins are
		// aiming — reads the rules themselves and is unaffected either way.
		foreach ( self::$third_party as $path ) {
			if ( ! preg_match( '/[:{}()+?\\\\]/', $path ) ) {
				$rx = self::to_js_regex( $path );
				if ( '' !== $rx ) {
					$re[] = $rx;
				}
			}
		}
		$re = array_values( array_unique( $re ) );
		$re_src = implode( '|', $re );

		// When every query URL is excluded on the rules side, the fallback must
		// exclude them too — that rule is a `@raw:` URLPattern with no regex
		// equivalent, so it is enforced structurally instead.
		$block_query = false;
		foreach ( self::href_exclude_patterns() as $token ) {
			if ( 0 === strpos( $token, '@raw:' ) ) {
				$block_query = true;
				break;
			}
		}

		list( $prefetch_eagerness ) = self::eagerness_profile();

		/**
		 * Filter the prediction layer's tuning constants.
		 *
		 * `x` max URLs promoted per page view, `w` minimum ms between rule-set
		 * replacements, `z` pointer proximity radius in CSS pixels.
		 *
		 * @since 2.6.7
		 * @param array<string,int> $tuning
		 */
		$tuning = (array) apply_filters(
			'easyopt_navigate_tuning',
			array( 'x' => 4, 'w' => 700, 'z' => 50 )
		);

		$cfg = wp_json_encode(
			array(
				'r' => $re_src,
				'q' => $block_query ? 1 : 0,
				's' => self::selector_excludes( 'prefetch' ),
				'f' => self::LOW_INTENT_SELECTOR,
				'd' => $debug ? 1 : 0,
				// Prediction is pointless at 'conservative' — the user has asked
				// for pointerdown-only, which the document rule already does, so
				// the whole layer is left out of the page.
				'n' => 'conservative' === $prefetch_eagerness ? 0 : 1,
				'p' => 1 === (int) EasyOpt_Config::get( 'instant_prerender', 0 ) ? 1 : 0,
				'x' => max( 1, min( 10, (int) ( $tuning['x'] ?? 4 ) ) ),
				'w' => max( 600, min( 5000, (int) ( $tuning['w'] ?? 700 ) ) ),
				'z' => max( 0, min( 300, (int) ( $tuning['z'] ?? 50 ) ) ),
			),
			JSON_HEX_TAG | JSON_UNESCAPED_SLASHES
		);

		// The reporter is only compiled in for `?eonavdebug`, so production
		// pages never carry it.
		$report = $debug
			? 'if(w.console)console.info("[EasyOpt] navigate",{speculationRules:ok,'
				. 'rules:el?JSON.parse(el.textContent):null,excludeRe:C.r,blockQuery:!!C.q,'
				. 'excludeSelector:C.s,headTimeMs:(w.performance&&performance.now?Math.round(performance.now()):null)});'
			: '';
		$report_fb = $debug ? 'if(w.console)console.info("[EasyOpt] fallback prefetch",u);' : '';

		$js = '(function(w,d,C){'
			. 'var el=d.getElementById(' . wp_json_encode( self::SCRIPT_ID ) . '),c=w.navigator.connection;'
			// 1. Network / data-saving conditions.
			. 'if(c&&(c.saveData||/(^|-)2g$/.test(c.effectiveType||""))){if(el&&el.parentNode)el.parentNode.removeChild(el);return}'
			// 2. Chromium and anything else that speaks speculation rules: done.
			. 'var S=w.HTMLScriptElement,ok=!!(S&&S.supports&&S.supports("speculationrules"));'
			. $report
			. 'if(ok){' . self::predict_script( $debug ) . 'return}'
			// 3. Fallback: <link rel=prefetch> on the same intent signal.
			//    One delegated listener set, one shared dwell timer, hard cap.
			. 'var L=d.createElement("link").relList;'
			. 'if(!(L&&L.supports&&L.supports("prefetch")))return;'
			. 'var re=C.r?new RegExp(C.r,"i"):null,seen={},n=0,t=0;'
			. 'function go(u){if(n>7||seen[u])return;seen[u]=1;n++;'
			. 'var k=d.createElement("link");k.rel="prefetch";k.href=u;d.head.appendChild(k);' . $report_fb . '}'
			. 'function pick(e){var a=e.target&&e.target.closest?e.target.closest("a[href]"):0;'
			. 'if(!a||a.origin!==location.origin)return 0;'
			. 'var u=a.href.split("#")[0];'
			. 'if(u===location.href.split("#")[0])return 0;'
			. 'if(C.q&&u.indexOf("?")>-1)return 0;'
			. 'if(re&&re.test(a.pathname+(a.search||"")))return 0;'
			. 'try{if(a.matches(C.s))return 0}catch(x){}'
			. 'return u}'
			. 'function warm(e){var u=pick(e);if(u)go(u)}'
			. 'function hover(e){var u=pick(e);clearTimeout(t);if(u)t=setTimeout(function(){go(u)},150)}'
			. 'var o={passive:true,capture:true};'
			. 'd.addEventListener("pointerover",hover,o);'
			. 'd.addEventListener("focusin",warm,o);'
			. 'd.addEventListener("pointerdown",warm,o);'
			. 'd.addEventListener("touchstart",warm,o)'
			. '})(window,document,' . $cfg . ');';

		return '<script id="easyopt-navigate">' . $js . "</script>\n";
	}

	/* ─────────────────────────────────────────────
	 *  Prediction layer
	 * ───────────────────────────────────────────── */

	/**
	 * Choose the candidate ourselves, and hand the browser one URL to act on now.
	 *
	 * WHY THIS EXISTS
	 * ---------------
	 * The document rule in <head> is good, but its trigger is fixed: the browser
	 * starts on a ~200ms hover ('moderate') or on pointerdown ('conservative').
	 * By the time the pointer is ON a link the visitor has already decided, so
	 * that is a small head start — and on a touchscreen there is no hover at all,
	 * which is why mobile gains almost nothing from the document rule alone.
	 *
	 * This layer adds a second, dynamic `source: "list"` rule set. When one of the
	 * signals below fires we append a URL to it with `eagerness: "immediate"` —
	 * meaning "we have already decided, start now". The browser still performs the
	 * speculation, so we keep its privileged navigation-prefetch cache (which
	 * survives `Cache-Control: no-store`, unlike anything JavaScript can fetch);
	 * we only take over the decision of WHEN.
	 *
	 * SIGNALS, earliest first
	 * -----------------------
	 *   • `rel="next"` at page load — pagination, seconds of lead, no interaction.
	 *   • A dropdown menu opening — every item in it just became likely.
	 *   • Scrolling stopping (touch devices) — the link nearest the middle of the
	 *     screen is what they are reading. Fires seconds before the tap, and is
	 *     the only early signal a touchscreen offers.
	 *   • Pointer resting within `z` px of a link (pointer devices) — earlier than
	 *     hover, because the pointer passes "near" before it arrives.
	 *
	 * THREE MEASURED CONSTRAINTS THAT SHAPE ALL OF THIS
	 * -------------------------------------------------
	 * These were established against Chromium 152 before the code was written;
	 * every one of them contradicts the obvious implementation:
	 *
	 *   1. Rewriting a live rule set element's textContent does NOT re-register
	 *      it. The element must be removed and a fresh one inserted.
	 *
	 *   2. Removing a URL from the list EVICTS its prefetch — Chromium reports
	 *      `PrefetchEvictedAfterCandidateRemoved`. So the list is APPEND-ONLY and
	 *      capped, never "the current best guess". A promotion is permanent for
	 *      the life of the page view, which is also why each signal must be
	 *      reasonably confident before it fires.
	 *
	 *   3. Replacing the element faster than roughly every 600ms produces NO
	 *      speculation at all — worse than doing nothing. Updates are therefore
	 *      debounced to `w` ms and batched.
	 *
	 * COST CEILING
	 * ------------
	 * At most `x` (default 4) extra document fetches per page view, ever. The
	 * anchor rectangle cache is rebuilt only after a scroll or resize, and is
	 * shared by both position-based signals, so a 50-link page costs one pass on
	 * settle rather than a measurement per pointer move.
	 *
	 * @param bool $debug Emit the `?eonavdebug` reporter.
	 * @return string JavaScript, no wrapper.
	 */
	private static function predict_script( $debug = false ) {

		$log = $debug ? 'if(w.console)console.info("[EasyOpt] promote",u,U.length);' : '';

		return 'if(!C.n)return;'
			// ── append-only candidate set + debounced rule-set replacement ──
			. 'var U=[],T=0,LAST=0,ID="easyopt-speculation-live";'
			. 'function build(){var o={prefetch:[{source:"list",urls:U,eagerness:"immediate"}]};'
			// One prerender at most, fixed to the first promotion. Re-targeting it
			// would mean removing a URL, and removal evicts.
			. 'if(C.p&&U.length)o.prerender=[{source:"list",urls:[U[0]],eagerness:"immediate"}];'
			. 'return o}'
			. 'function flush(){var e=d.getElementById(ID);'
			. 'if(e&&e.parentNode)e.parentNode.removeChild(e);'
			. 'e=d.createElement("script");e.type="speculationrules";e.id=ID;'
			. 'e.textContent=JSON.stringify(build());d.head.appendChild(e);LAST=Date.now()}'
			// Always collect for at least one batch window before the first
			// flush. Firing immediately and then replacing the element again a
			// moment later is the single worst thing we can do: measurement put
			// the reliability boundary at roughly 600ms between replacements, so
			// two promotions arriving together must become ONE insertion, not two.
			. 'function promote(u){'
			. 'if(!u||U.length>=C.x||U.indexOf(u)>-1)return;'
			. 'U.push(u);' . $log
			. 'if(T)return;'
			. 'T=setTimeout(function(){T=0;flush()},Math.max(250,C.w-(Date.now()-LAST)))}'
			// ── candidate filter: same exclusions the rules themselves carry ──
			. 'var re=C.r?new RegExp(C.r,"i"):null,here=location.href.split("#")[0];'
			. 'function ok(a){'
			. 'if(!a||!a.href||a.origin!==location.origin)return 0;'
			. 'var u=a.href.split("#")[0];'
			. 'if(u===here)return 0;'
			. 'if(C.q&&u.indexOf("?")>-1)return 0;'
			. 'if(re&&re.test(a.pathname+(a.search||"")))return 0;'
			. 'try{if(a.matches(C.s)||a.matches(C.f))return 0}catch(x){}'
			. 'return u}'
			// ── shared anchor-rectangle cache, invalidated by scroll / resize ──
			. 'var R=null;'
			. 'function rects(){'
			. 'if(R)return R;R=[];'
			. 'var a=d.querySelectorAll("a[href]"),i=0,h=w.innerHeight;'
			. 'for(;i<a.length&&i<200;i++){var r=a[i].getBoundingClientRect();'
			. 'if(!r.width||r.bottom<0||r.top>h)continue;'
			. 'R.push([a[i],r.left,r.top,r.right,r.bottom])}'
			. 'return R}'
			. 'function drop(){R=null}'
			. 'd.addEventListener("scroll",drop,{passive:true});'
			. 'w.addEventListener("resize",drop,{passive:true});'
			// ── DOM-dependent signals ──
			//
			// This script runs in <head>, where document.body is still null and
			// no anchor has been parsed yet. The two signals below therefore have
			// to wait for the document: querying for `rel="next"` here would
			// always find nothing, and observing document.body would never
			// install an observer at all. The event-driven signals below do not
			// need this, because they only read the DOM once something happens.
			. 'function boot(){'
			// ── signal 1: the page's own most-linked destination, on idle ──
			//
			// A site links what matters from everywhere — header, hero button,
			// mid-page call to action, footer, mobile drawer — and links
			// everything else once. So counting how often each destination
			// appears reads a vote the site owner already cast, with no
			// interaction required at all. On a real page measured for this,
			// two destinations appeared 9 times each out of 65 same-site links
			// while the privacy policy appeared once.
			//
			// Deliberately on IDLE rather than at load. A visitor cannot click
			// before the page renders, so lead time bought ahead of first paint
			// buys nothing — while the fetch would compete with the stylesheet
			// and images they are actually waiting for. Idle still leaves
			// seconds of head start before any realistic click. The timeout
			// stops a busy page starving it forever.
			. 'var rIC=w.requestIdleCallback||function(f){return setTimeout(f,1200)};'
			. 'rIC(function(){'
			. 'var a=d.querySelectorAll("a[href]"),c={},best=0,bn=1,i=0,u;'
			. 'for(;i<a.length&&i<200;i++){u=ok(a[i]);if(!u)continue;'
			. 'c[u]=(c[u]||0)+1;if(c[u]>bn){bn=c[u];best=u}}'
			// One link is not a vote — only promote a genuine favourite.
			. 'if(best&&bn>2)promote(best);'
			. '},{timeout:2000});'
			// ── signal 2: pagination, as soon as the document exists. ──
			. 'promote(ok(d.querySelector(\'a[rel~="next"]\')));'
			// ── signal 3: a dropdown or drawer menu opening ──
			//
			// Two conventions in the wild, and a theme may use either:
			//   • aria-controls="id" — the menu lives elsewhere in the document.
			//     This is the accessible form, and the one a mobile drawer
			//     almost always uses, so it is checked first.
			//   • a nested <ul>/<ol> inside the toggle's parent — the classic
			//     WordPress submenu shape.
			. 'if(w.MutationObserver&&d.body){new MutationObserver(function(ms){'
			. 'for(var i=0;i<ms.length;i++){var t=ms[i].target;'
			. 'if(!t.getAttribute||t.getAttribute("aria-expanded")!=="true")continue;'
			. 'var c=t.getAttribute("aria-controls"),s=c?d.getElementById(c):0;'
			. 'if(!s){var p=t.parentNode;s=p&&p.querySelector?p.querySelector("ul,ol"):0}'
			. 'if(!s)continue;var l=s.querySelectorAll("a[href]");'
			. 'for(var j=0;j<l.length&&j<2;j++)promote(ok(l[j]))}'
			. '}).observe(d.body,{subtree:true,attributes:true,attributeFilter:["aria-expanded"]})}'
			. '}'
			. 'if(d.readyState==="loading")d.addEventListener("DOMContentLoaded",boot);else boot();'
			// ── signal 4 (touch) vs 5 (pointer): a device has one or the other ──
			. 'var HOV=w.matchMedia&&matchMedia("(hover:hover)").matches;'
			. 'if(!HOV){'
			// Scrolling stopped: take the link nearest the middle of the screen.
			. 'var st=0;'
			. 'd.addEventListener("scroll",function(){clearTimeout(st);'
			. 'st=setTimeout(function(){var m=w.innerHeight/2,b=0,bd=1e9,L=rects(),i=0;'
			. 'for(;i<L.length;i++){var dd=Math.abs((L[i][2]+L[i][4])/2-m);'
			. 'if(dd<bd){bd=dd;b=L[i][0]}}'
			. 'if(b)promote(ok(b))},200)},{passive:true})}'
			. 'else if(C.z){'
			// Pointer came to REST near a link — earlier than hover, because the
			// pointer passes "near" on its way to "on".
			//
			// "Came to rest" is the important half. Simply promoting whatever is
			// within range every 80ms turns a single sweep across a navigation bar
			// into four speculations, one per link passed. So the position is
			// sampled when the timer is set and re-checked when it fires: if the
			// pointer is still travelling, the decision is deferred rather than
			// taken. Only a pointer that has actually settled counts as intent.
			// A single pointermove is not movement. Chromium dispatches one on
			// load when the cursor already happens to be over the page, so
			// acting on the first event promotes whatever the mouse was parked
			// next to — a guess made from where the visitor left it, not from
			// anything they did. Waiting for a second event costs nothing and
			// removes a false positive that fired on every desktop page view.
			. 'var pt=0,px=0,py=0,sx=0,sy=0,mv=0;'
			. 'function rest(){pt=0;'
			. 'if(Math.abs(px-sx)>12||Math.abs(py-sy)>12){arm();return}'
			. 'var b=0,bd=C.z,L=rects(),i=0;'
			. 'for(;i<L.length;i++){'
			. 'var x=Math.max(L[i][1]-px,0,px-L[i][3]),y=Math.max(L[i][2]-py,0,py-L[i][4]);'
			. 'var dd=Math.sqrt(x*x+y*y);if(dd<bd){bd=dd;b=L[i][0]}}'
			. 'if(b)promote(ok(b))}'
			. 'function arm(){sx=px;sy=py;pt=setTimeout(rest,90)}'
			. 'd.addEventListener("pointermove",function(e){px=e.clientX;py=e.clientY;'
			. 'if(++mv<2)return;if(!pt)arm()},{passive:true})}';
	}
}
