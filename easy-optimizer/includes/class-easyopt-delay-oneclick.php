<?php
/**
 * One-click Delay JS exclusions (2.8.0).
 *
 * A shipped, versioned list of scripts that commonly need to run before the
 * first interaction — analytics and ad tags, cookie-consent banners, sliders,
 * page-builder animations — offered as checkboxes under Delay JavaScript.
 *
 * NOTHING HERE IS EVER APPLIED BY ITSELF. Not on install, not on update, not
 * for a detected plugin: an entry only takes effect after someone ticks it
 * (easyopt_delay_js_oneclick holds the ticked ids, one per line). Plugin and
 * theme entries are only offered when that plugin or theme is active.
 *
 * Each entry matches the same way a line in "Exclude from Delay" does
 * (substring of the whole tag), using:
 *   paths    — file path fragments (lost once Minify renames a file, hence…)
 *   handles  — WordPress script-handle prefixes, matched on the tag's id
 *              in both quote styles, so minified files still match
 *   inline   — inline config variable names (wp_localize_script data)
 * Delay JS's dependency closure then keeps whatever those scripts need.
 *
 * The list is updated with plugin releases.
 *
 * @package EasyOptimizer
 * @since   2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Delay_Oneclick {

    const VERSION = '2026.11.1';

    /**
     * The full catalog.
     *
     * @return array<string,array>
     */
    public static function items() {
        return array(
            // ── Analytics & Ads ────────────────────────────────────────
            'google-analytics' => array( 'group' => 'analytics', 'label' => 'Google Analytics & Google Ads (gtag.js)',
                'paths' => array( 'googletagmanager.com/gtag/js', 'google-analytics.com/analytics.js' ), 'inline' => array( 'gtag(' ) ),
            'google-tag-manager' => array( 'group' => 'analytics', 'label' => 'Google Tag Manager',
                'paths' => array( 'googletagmanager.com/gtm.js' ), 'inline' => array( 'gtm.start' ) ),
            'google-adsense' => array( 'group' => 'analytics', 'label' => 'Google AdSense',
                'paths' => array( 'pagead2.googlesyndication.com' ), 'inline' => array( 'adsbygoogle' ) ),
            'facebook-pixel' => array( 'group' => 'analytics', 'label' => 'Meta (Facebook) Pixel',
                'paths' => array( 'connect.facebook.net' ), 'inline' => array( 'fbq(' ) ),
            'microsoft-clarity' => array( 'group' => 'analytics', 'label' => 'Microsoft Clarity',
                'paths' => array( 'clarity.ms' ), 'inline' => array() ),
            'microsoft-ads' => array( 'group' => 'analytics', 'label' => 'Microsoft Advertising (UET)',
                'paths' => array( 'bat.bing.com' ), 'inline' => array( 'uetq' ) ),
            'hotjar' => array( 'group' => 'analytics', 'label' => 'Hotjar',
                'paths' => array( 'static.hotjar.com' ), 'inline' => array( '_hjSettings' ) ),
            'tiktok-pixel' => array( 'group' => 'analytics', 'label' => 'TikTok Pixel',
                'paths' => array( 'analytics.tiktok.com' ), 'inline' => array( 'ttq.' ) ),
            'linkedin-insight' => array( 'group' => 'analytics', 'label' => 'LinkedIn Insight Tag',
                'paths' => array( 'snap.licdn.com' ), 'inline' => array( '_linkedin_partner_id' ) ),
            'pinterest-tag' => array( 'group' => 'analytics', 'label' => 'Pinterest Tag',
                'paths' => array( 's.pinimg.com/ct/' ), 'inline' => array( 'pintrk(' ) ),
            'x-pixel' => array( 'group' => 'analytics', 'label' => 'X (Twitter) Pixel',
                'paths' => array( 'static.ads-twitter.com' ), 'inline' => array( 'twq(' ) ),
            'matomo' => array( 'group' => 'analytics', 'label' => 'Matomo',
                'paths' => array( 'matomo.js', 'piwik.js' ), 'inline' => array( '_paq' ) ),
            'plausible' => array( 'group' => 'analytics', 'label' => 'Plausible',
                'paths' => array( 'plausible.io/js' ), 'inline' => array() ),
            'fathom' => array( 'group' => 'analytics', 'label' => 'Fathom',
                'paths' => array( 'cdn.usefathom.com' ), 'inline' => array() ),

            // ── Plugins (offered only when active) ─────────────────────
            'woocommerce' => array( 'group' => 'plugins', 'label' => 'WooCommerce', 'plugins' => array( 'woocommerce' ),
                'paths' => array( '/plugins/woocommerce/assets/js/' ), 'handles' => array( 'wc-', 'woocommerce' ), 'inline' => array( 'wc_add_to_cart_params', 'woocommerce_params' ) ),
            'elementor' => array( 'group' => 'plugins', 'label' => 'Elementor & Elementor Pro', 'plugins' => array( 'elementor', 'elementor-pro' ),
                'paths' => array( '/plugins/elementor/assets/', '/plugins/elementor-pro/assets/' ), 'handles' => array( 'elementor-', 'pro-elements-handlers', 'e-sticky', 'smartmenus', 'swiper', 'flatpickr', 'jquery-numerator', 'share-link' ), 'inline' => array( 'elementorFrontendConfig', 'ElementorProFrontendConfig' ) ),
            'essential-addons' => array( 'group' => 'plugins', 'label' => 'Essential Addons for Elementor', 'plugins' => array( 'essential-addons-for-elementor-lite', 'essential-addons-elementor' ),
                'paths' => array( '/plugins/essential-addons-for-elementor-lite/', '/uploads/essential-addons-elementor/' ), 'handles' => array( 'eael-' ), 'inline' => array() ),
            'spectra' => array( 'group' => 'plugins', 'label' => 'Spectra', 'plugins' => array( 'ultimate-addons-for-gutenberg' ),
                'paths' => array( '/plugins/ultimate-addons-for-gutenberg/', '/uploads/uag-plugin/' ), 'handles' => array( 'uagb-' ), 'inline' => array() ),
            'wpbakery' => array( 'group' => 'plugins', 'label' => 'WPBakery Page Builder', 'plugins' => array( 'js_composer' ),
                'paths' => array( '/plugins/js_composer/' ), 'handles' => array( 'wpb_composer', 'vc_' ), 'inline' => array() ),
            'beaver-builder' => array( 'group' => 'plugins', 'label' => 'Beaver Builder', 'plugins' => array( 'bb-plugin', 'beaver-builder-lite-version' ),
                'paths' => array( '/plugins/bb-plugin/', '/plugins/beaver-builder-lite-version/', '/uploads/bb-plugin/' ), 'handles' => array( 'fl-' ), 'inline' => array( 'FLBuilderLayout' ) ),
            'revslider' => array( 'group' => 'plugins', 'label' => 'Slider Revolution', 'plugins' => array( 'revslider' ),
                'paths' => array( '/plugins/revslider/' ), 'handles' => array( 'tp-tools', 'revmin', 'sr7' ), 'inline' => array( 'setREVStartSize', 'revapi' ) ),
            'smart-slider' => array( 'group' => 'plugins', 'label' => 'Smart Slider 3', 'plugins' => array( 'smart-slider-3', 'nextend-smart-slider3-pro' ),
                'paths' => array( '/plugins/smart-slider-3/', '/plugins/nextend-smart-slider3-pro/' ), 'handles' => array( 'smartslider' ), 'inline' => array( '_N2' ) ),
            'metaslider' => array( 'group' => 'plugins', 'label' => 'MetaSlider', 'plugins' => array( 'ml-slider' ),
                'paths' => array( '/plugins/ml-slider/' ), 'handles' => array( 'metaslider' ), 'inline' => array( 'metaslider' ) ),
            'complianz' => array( 'group' => 'plugins', 'label' => 'Complianz', 'plugins' => array( 'complianz-gdpr', 'complianz-gdpr-premium' ),
                'paths' => array( '/plugins/complianz-gdpr/', '/plugins/complianz-gdpr-premium/' ), 'handles' => array( 'cmplz' ), 'inline' => array( 'complianz' ) ),
            'cookieyes' => array( 'group' => 'plugins', 'label' => 'CookieYes', 'plugins' => array( 'cookie-law-info' ),
                'paths' => array( '/plugins/cookie-law-info/', 'cdn-cookieyes.com' ), 'handles' => array( 'cookie-law-info' ), 'inline' => array( '_ckyConfig', 'cookieyes' ) ),
            'cookie-notice' => array( 'group' => 'plugins', 'label' => 'Cookie Notice & Compliance', 'plugins' => array( 'cookie-notice' ),
                'paths' => array( '/plugins/cookie-notice/' ), 'handles' => array( 'cookie-notice' ), 'inline' => array( 'cnArgs' ) ),
            'cookiebot' => array( 'group' => 'plugins', 'label' => 'Cookiebot', 'plugins' => array( 'cookiebot' ),
                'paths' => array( 'consent.cookiebot.com', '/plugins/cookiebot/' ), 'handles' => array( 'cookiebot' ), 'inline' => array( 'Cookiebot' ) ),
            'borlabs-cookie' => array( 'group' => 'plugins', 'label' => 'Borlabs Cookie', 'plugins' => array( 'borlabs-cookie' ),
                'paths' => array( '/plugins/borlabs-cookie/' ), 'handles' => array( 'borlabs-cookie' ), 'inline' => array( 'BorlabsCookie' ) ),
            'gdpr-cookie-compliance' => array( 'group' => 'plugins', 'label' => 'GDPR Cookie Compliance', 'plugins' => array( 'gdpr-cookie-compliance' ),
                'paths' => array( '/plugins/gdpr-cookie-compliance/' ), 'handles' => array( 'moove_gdpr' ), 'inline' => array( 'moove_frontend_gdpr_scripts' ) ),
            'popup-maker' => array( 'group' => 'plugins', 'label' => 'Popup Maker', 'plugins' => array( 'popup-maker' ),
                'paths' => array( '/plugins/popup-maker/', '/uploads/pum/' ), 'handles' => array( 'popup-maker' ), 'inline' => array( 'pum_vars' ) ),
            'contact-form-7' => array( 'group' => 'plugins', 'label' => 'Contact Form 7', 'plugins' => array( 'contact-form-7' ),
                'paths' => array( '/plugins/contact-form-7/' ), 'handles' => array( 'contact-form-7', 'swv' ), 'inline' => array( 'wpcf7' ) ),
            'wpforms' => array( 'group' => 'plugins', 'label' => 'WPForms', 'plugins' => array( 'wpforms-lite', 'wpforms' ),
                'paths' => array( '/plugins/wpforms-lite/', '/plugins/wpforms/' ), 'handles' => array( 'wpforms' ), 'inline' => array( 'wpforms_settings' ) ),
            'gravity-forms' => array( 'group' => 'plugins', 'label' => 'Gravity Forms', 'plugins' => array( 'gravityforms' ),
                'paths' => array( '/plugins/gravityforms/' ), 'handles' => array( 'gform_' ), 'inline' => array( 'gform' ) ),
            'tutor-lms' => array( 'group' => 'plugins', 'label' => 'Tutor LMS', 'plugins' => array( 'tutor', 'tutor-pro' ),
                'paths' => array( '/plugins/tutor/', '/plugins/tutor-pro/' ), 'handles' => array( 'tutor-' ), 'inline' => array( '_tutorobject' ) ),
            'monsterinsights' => array( 'group' => 'plugins', 'label' => 'MonsterInsights', 'plugins' => array( 'google-analytics-for-wordpress', 'google-analytics-premium' ),
                'paths' => array( '/plugins/google-analytics-for-wordpress/', '/plugins/google-analytics-premium/' ), 'handles' => array( 'monsterinsights' ), 'inline' => array( '__gtagTracker', 'monsterinsights' ) ),
            'site-kit' => array( 'group' => 'plugins', 'label' => 'Site Kit by Google', 'plugins' => array( 'google-site-kit' ),
                'paths' => array( '/plugins/google-site-kit/', 'googletagmanager.com/gtag/js' ), 'handles' => array( 'google_gtagjs' ), 'inline' => array( 'gtag(' ) ),
            'gtm4wp' => array( 'group' => 'plugins', 'label' => 'GTM4WP', 'plugins' => array( 'duracelltomi-google-tag-manager' ),
                'paths' => array( '/plugins/duracelltomi-google-tag-manager/', 'googletagmanager.com/gtm.js' ), 'handles' => array( 'gtm4wp' ), 'inline' => array( 'gtm4wp', 'gtm.start' ) ),
            'pixelyoursite' => array( 'group' => 'plugins', 'label' => 'PixelYourSite', 'plugins' => array( 'pixelyoursite', 'pixelyoursite-pro' ),
                'paths' => array( '/plugins/pixelyoursite/', '/plugins/pixelyoursite-pro/', 'connect.facebook.net' ), 'handles' => array( 'pys' ), 'inline' => array( 'pysOptions', 'fbq(' ) ),

            // ── Themes (offered only when active, as theme or parent) ───
            'astra' => array( 'group' => 'themes', 'label' => 'Astra', 'themes' => array( 'astra' ),
                'paths' => array( '/themes/astra/' ), 'handles' => array( 'astra-' ), 'inline' => array( 'var astra' ) ),
            'divi' => array( 'group' => 'themes', 'label' => 'Divi', 'themes' => array( 'divi' ),
                'paths' => array( '/themes/Divi/' ), 'handles' => array( 'divi-', 'et-' ), 'inline' => array( 'et_pb_custom', 'et_frontend_scripts' ) ),
            'avada' => array( 'group' => 'themes', 'label' => 'Avada', 'themes' => array( 'avada' ),
                'paths' => array( '/themes/Avada/', '/plugins/fusion-builder/', '/plugins/fusion-core/' ), 'handles' => array( 'fusion', 'avada' ), 'inline' => array() ),
            'generatepress' => array( 'group' => 'themes', 'label' => 'GeneratePress', 'themes' => array( 'generatepress' ),
                'paths' => array( '/themes/generatepress/' ), 'handles' => array( 'generate-' ), 'inline' => array( 'generatepressMenu' ) ),
            'oceanwp' => array( 'group' => 'themes', 'label' => 'OceanWP', 'themes' => array( 'oceanwp' ),
                'paths' => array( '/themes/oceanwp/' ), 'handles' => array( 'oceanwp' ), 'inline' => array( 'oceanwpLocalize' ) ),
            'kadence' => array( 'group' => 'themes', 'label' => 'Kadence', 'themes' => array( 'kadence' ),
                'paths' => array( '/themes/kadence/' ), 'handles' => array( 'kadence-' ), 'inline' => array( 'kadenceConfig' ) ),
            'flatsome' => array( 'group' => 'themes', 'label' => 'Flatsome', 'themes' => array( 'flatsome' ),
                'paths' => array( '/themes/flatsome/' ), 'handles' => array( 'flatsome' ), 'inline' => array( 'flatsomeVars' ) ),
            'blocksy' => array( 'group' => 'themes', 'label' => 'Blocksy', 'themes' => array( 'blocksy' ),
                'paths' => array( '/themes/blocksy/' ), 'handles' => array( 'ct-scripts' ), 'inline' => array( 'ct_localizations' ) ),
            'bricks' => array( 'group' => 'themes', 'label' => 'Bricks', 'themes' => array( 'bricks' ),
                'paths' => array( '/themes/bricks/' ), 'handles' => array( 'bricks-' ), 'inline' => array( 'bricksData' ) ),
            'neve' => array( 'group' => 'themes', 'label' => 'Neve', 'themes' => array( 'neve' ),
                'paths' => array( '/themes/neve/' ), 'handles' => array( 'neve-' ), 'inline' => array( 'NeveProperties' ) ),
            'enfold' => array( 'group' => 'themes', 'label' => 'Enfold', 'themes' => array( 'enfold' ),
                'paths' => array( '/themes/enfold/' ), 'handles' => array( 'avia-' ), 'inline' => array() ),
            'the7' => array( 'group' => 'themes', 'label' => 'The7', 'themes' => array( 'dt-the7' ),
                'paths' => array( '/themes/dt-the7/' ), 'handles' => array( 'dt-' ), 'inline' => array( 'dtLocal' ) ),
        );
    }

    /**
     * Exclusion patterns for the ticked ids. Unknown ids are ignored.
     *
     * @param string|string[] $ids Ticked ids (array, or the stored one-per-line string).
     * @return string[]
     */
    public static function patterns( $ids ) {
        if ( is_string( $ids ) ) {
            $ids = preg_split( '/\r\n|\r|\n/', $ids );
        }
        $items = self::items();
        $out   = array();
        foreach ( array_unique( array_map( 'trim', (array) $ids ) ) as $id ) {
            if ( '' === $id || ! isset( $items[ $id ] ) ) {
                continue;
            }
            $it  = $items[ $id ];
            $out = array_merge( $out, isset( $it['paths'] ) ? $it['paths'] : array(), isset( $it['inline'] ) ? $it['inline'] : array() );
            foreach ( isset( $it['handles'] ) ? $it['handles'] : array() as $h ) {
                $out[] = 'id="' . $h;
                $out[] = "id='" . $h;
            }
        }
        return array_values( array_unique( array_filter( $out, 'strlen' ) ) );
    }

    /**
     * What the admin UI offers: every Analytics & Ads entry, and the plugin and
     * theme entries for what is active on this site.
     *
     * @return array<int,array{id:string,group:string,label:string}>
     */
    public static function offered() {
        $plugins = self::active_plugin_slugs();
        $themes  = self::active_theme_slugs();
        $out     = array();
        foreach ( self::items() as $id => $it ) {
            if ( 'plugins' === $it['group'] && ! array_intersect( $it['plugins'], $plugins ) ) {
                continue;
            }
            if ( 'themes' === $it['group'] && ! array_intersect( $it['themes'], $themes ) ) {
                continue;
            }
            $out[] = array( 'id' => $id, 'group' => $it['group'], 'label' => $it['label'] );
        }
        return $out;
    }

    /** Directory slugs of active plugins (site and network). */
    private static function active_plugin_slugs() {
        $files = (array) get_option( 'active_plugins', array() );
        if ( function_exists( 'is_multisite' ) && is_multisite() ) {
            $files = array_merge( $files, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
        }
        $slugs = array();
        foreach ( $files as $f ) {
            $slugs[] = strtolower( strtok( (string) $f, '/' ) );
        }
        return array_values( array_unique( $slugs ) );
    }

    /** Active theme and its parent, lower-cased. */
    private static function active_theme_slugs() {
        $out = array();
        if ( function_exists( 'get_stylesheet' ) ) {
            $out[] = strtolower( (string) get_stylesheet() );
        }
        if ( function_exists( 'get_template' ) ) {
            $out[] = strtolower( (string) get_template() );
        }
        return array_values( array_unique( $out ) );
    }
}
