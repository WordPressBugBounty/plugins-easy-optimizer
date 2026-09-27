<?php
/**
 * Persistent admin-notice dismissals (2.5.3).
 *
 * WordPress's `is-dismissible` X only hides a notice for the current page
 * load — every "closable" notice in the plugin was cosmetic. This manager
 * gives notices a real "Don't show again" that persists per user, keyed to
 * a STATE STAMP: dismissing hides the notice for as long as the underlying
 * situation is unchanged; if the state re-occurs or changes (new revocation,
 * a different conflicting plugin, a new overlap set), the stamp differs and
 * the notice returns. First occurrence always shows.
 *
 * Site-wide one-time diagnostics (htaccess flags) are dismissed by deleting
 * their flag option instead — once any admin has acted on them, they're done.
 *
 * @package EasyOptimizer
 * @since   2.5.3
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EasyOpt_Notices {

	const META  = 'easyopt_notice_dismissals';
	const QUERY = 'easyopt_notice_dismiss';

	/** Notice ids whose dismissal clears a site option instead (flag => option). */
	private static $flag_notices = array(
		'htaccess_unverified' => 'easyopt_htaccess_unverified',
		'gzip_unsupported'    => 'easyopt_htaccess_gzip_unsupported',
	);

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'handle_dismiss' ) );
	}

	/** Has the current user dismissed $id at this exact state stamp? */
	public static function dismissed( $id, $stamp ) {
		$map = get_user_meta( get_current_user_id(), self::META, true );
		return is_array( $map ) && isset( $map[ $id ] ) && (string) $map[ $id ] === (string) $stamp;
	}

	/** Nonce-protected "Don't show again" link for a notice. */
	public static function link( $id, $stamp, $label = '' ) {
		$url = wp_nonce_url(
			add_query_arg( array( self::QUERY => rawurlencode( $id ), 'stamp' => rawurlencode( (string) $stamp ) ) ),
			'easyopt_notice_' . $id
		);
		return sprintf(
			' <a href="%s" style="text-decoration:none;white-space:nowrap;">%s</a>',
			esc_url( $url ),
			esc_html( '' !== $label ? $label : __( "Don't show again", 'easy-optimizer' ) )
		);
	}

	public static function handle_dismiss() {
		if ( ! isset( $_GET[ self::QUERY ] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$id = sanitize_key( wp_unslash( $_GET[ self::QUERY ] ) );
		check_admin_referer( 'easyopt_notice_' . $id );
		if ( isset( self::$flag_notices[ $id ] ) ) {
			delete_option( self::$flag_notices[ $id ] );
		} else {
			$stamp = isset( $_GET['stamp'] ) ? sanitize_text_field( wp_unslash( $_GET['stamp'] ) ) : '1';
			$map   = get_user_meta( get_current_user_id(), self::META, true );
			$map   = is_array( $map ) ? $map : array();
			$map[ $id ] = $stamp;
			update_user_meta( get_current_user_id(), self::META, $map );
		}
		wp_safe_redirect( remove_query_arg( array( self::QUERY, 'stamp', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * True when the current screen is one where advisory notices belong.
	 *
	 * (2.6.0) The Dashboard was added. Conflict notices were only reachable on
	 * Easy Optimizer's own screens and the Plugins list, so a site running two
	 * overlapping plugins could be told about it and the owner would never
	 * land on a screen that said so. The Dashboard is where people arrive.
	 */
	public static function is_advisory_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		$id = (string) $screen->id;
		return false !== strpos( $id, 'easy-optimizer' )
			|| 'plugins' === $id
			|| 'dashboard' === $id;
	}

	/**
	 * Settings-page URLs for plugins we may ask the user to change.
	 *
	 * (2.6.0) Notices used to send people to the generic Plugins list, which
	 * is one screen short of useful: the user still has to find the plugin,
	 * find its settings, then find the right toggle. Linking directly to the
	 * plugin's own settings page — and naming it in the link — removes a step
	 * and makes the action obvious.
	 *
	 * We can only ever LINK here. Turning another plugin's feature off is
	 * their decision to make in their interface, not something we do to them.
	 *
	 * Matched on substring so decorated labels ("Smush (lazy load)") resolve.
	 * Unknown plugin → the Plugins list, which is always correct if vague.
	 *
	 * @since 2.6.0
	 * @var array<string,string> label fragment => admin-relative URL
	 */
	private static $plugin_settings = array(
		'WP Rocket'        => 'options-general.php?page=wprocket',
		'LiteSpeed'        => 'admin.php?page=litespeed-cache',
		'W3 Total Cache'   => 'admin.php?page=w3tc_general',
		'WP Super Cache'   => 'options-general.php?page=wpsupercache',
		'WP Fastest Cache' => 'admin.php?page=WpFastestCacheOptions',
		'Autoptimize'      => 'options-general.php?page=autoptimize',
		'Jetpack Boost'    => 'admin.php?page=jetpack-boost',
		'Smush'            => 'admin.php?page=smush',
		'Perfmatters'      => 'options-general.php?page=perfmatters',
		'OMGF'             => 'options-general.php?page=optimize-webfonts',
		'EWWW'             => 'options-general.php?page=ewww-image-optimizer-options',
		'Breeze'           => 'admin.php?page=breeze',
		'Hummingbird'      => 'admin.php?page=wphb',
		'WP-Optimize'      => 'admin.php?page=WP-Optimize',
		'Cache Enabler'    => 'options-general.php?page=cache-enabler',
		'Asset CleanUp'    => 'admin.php?page=wpassetcleanup_settings',
		'Optimole'         => 'admin.php?page=optimole',
		'SpeedyCache'      => 'admin.php?page=speedycache',
		'NitroPack'        => 'admin.php?page=nitropack',
		'SiteGround'       => 'admin.php?page=siteground-optimizer',
		'a3 Lazy Load'     => 'admin.php?page=a3-lazy-load',
	);

	/**
	 * Admin URL for a named plugin's settings, or the Plugins list.
	 *
	 * @since 2.6.0
	 * @param string $label Plugin label as shown in a notice.
	 * @return string
	 */
	public static function plugin_settings_url( $label ) {
		foreach ( self::$plugin_settings as $needle => $path ) {
			if ( false !== stripos( (string) $label, $needle ) ) {
				return admin_url( $path );
			}
		}
		return admin_url( 'plugins.php' );
	}

	/**
	 * Strip decoration from a notice label to get a searchable plugin name.
	 *
	 * Labels carry context for the reader — "Smush (lazy load)",
	 * "Perfmatters (local Google Fonts)" — which is right in prose and wrong
	 * in a search query.
	 *
	 * @since 2.6.0
	 * @param string $label
	 * @return string
	 */
	private static function bare_plugin_name( $label ) {
		$name = preg_replace( '/\s*\([^)]*\)\s*$/', '', (string) $label );
		return trim( $name );
	}

	/**
	 * Link to the Plugins screen filtered to one plugin.
	 *
	 * (2.6.0) Deliberately NOT a deactivate action of our own. Landing the
	 * user on the Plugins list with that plugin isolated puts Deactivate one
	 * click away and lets WORDPRESS perform it — so there is no capability
	 * juggling, no nonce handling, no partial-failure state, and no question
	 * about a plugin one-click-disabling a competitor. The user also sees what
	 * they are about to do before it happens, which a button in a notice does
	 * not give them.
	 *
	 * @since 2.6.0
	 * @param string $label Plugin label as shown in a notice.
	 * @return string
	 */
	public static function plugin_deactivate_url( $label ) {
		$name = self::bare_plugin_name( $label );
		if ( '' === $name ) {
			return admin_url( 'plugins.php' );
		}
		return admin_url( 'plugins.php?plugin_status=active&s=' . rawurlencode( $name ) );
	}

	/**
	 * "Deactivate X" button — opens the Plugins list filtered to that plugin.
	 *
	 * @since 2.6.0
	 * @param string $label   Plugin label.
	 * @param string $variant 'secondary' | 'primary' | 'link'
	 * @return string Escaped HTML.
	 */
	public static function plugin_deactivate_button( $label, $variant = 'secondary' ) {
		$name = self::bare_plugin_name( $label );
		if ( '' === $name ) {
			return '';
		}
		$text = sprintf(
			/* translators: %s: plugin name. */
			__( 'Deactivate %s', 'easy-optimizer' ),
			$name
		);

		if ( 'link' === $variant ) {
			return sprintf(
				'<a href="%s" style="text-decoration:none;white-space:nowrap;">%s</a>',
				esc_url( self::plugin_deactivate_url( $label ) ),
				esc_html( $text )
			);
		}

		return sprintf(
			'<a href="%s" class="button button-%s">%s</a>',
			esc_url( self::plugin_deactivate_url( $label ) ),
			esc_attr( 'primary' === $variant ? 'primary' : 'secondary' ),
			esc_html( $text )
		);
	}

	/**
	 * Ready-made action button pointing at a plugin's own settings.
	 *
	 * @since 2.6.0
	 * @param string $label   Plugin label.
	 * @param string $variant 'secondary' | 'primary'
	 * @return string Escaped HTML.
	 */
	public static function plugin_action_button( $label, $variant = 'secondary' ) {
		$url   = self::plugin_settings_url( $label );
		$known = ( admin_url( 'plugins.php' ) !== $url );

		$text = $known
			? sprintf(
				/* translators: %s: plugin name. */
				__( 'Open %s settings', 'easy-optimizer' ),
				$label
			)
			: __( 'Go to Plugins', 'easy-optimizer' );

		return sprintf(
			'<a href="%s" class="button button-%s">%s</a>',
			esc_url( $url ),
			esc_attr( 'primary' === $variant ? 'primary' : 'secondary' ),
			esc_html( $text )
		);
	}
}
