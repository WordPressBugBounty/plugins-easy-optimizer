<?php
/**
 * EasyOpt classmap autoloader (2.3.3).
 *
 * First step of the namespace migration. Two jobs:
 *
 *  1. Resolve every legacy global `EasyOpt_*` class on demand via an
 *     explicit classmap (no directory scanning, no string transforms —
 *     a static array lookup, which OPcache makes effectively free).
 *  2. Resolve the new `EasyOpt\` namespace (includes/cache/ and future
 *     namespaced modules).
 *
 * The existing require_once ladder in easy-optimizer.php is intentionally
 * KEPT: it encodes a deliberate performance optimisation (feature modules
 * are only loaded when their feature is enabled) and a known-good load
 * order. This autoloader is the safety net underneath it — anything not
 * explicitly required resolves lazily on first use, and new classes never
 * need another require line. Net runtime cost: zero (namespaces resolve at
 * compile time; spl_autoload only fires for classes that were about to be
 * used anyway).
 *
 * @package EasyOptimizer
 * @since   2.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'EasyOpt_Autoloader' ) ) {

    final class EasyOpt_Autoloader {

        /** @var array<string,string> Legacy global classes → file (relative to plugin dir). */
        private static $classmap = array(
            'EasyOpt_Accessibility'        => 'includes/class-easyopt-accessibility.php',
            'EasyOpt_Advanced_Cache'       => 'includes/class-easyopt-advanced-cache.php',
            'EasyOpt_Bloat'                => 'includes/class-easyopt-bloat.php',
            'EasyOpt_CDN'                  => 'includes/class-easyopt-cdn.php',
            'EasyOpt_CDN_Cloud'            => 'includes/class-easyopt-cdn-cloud.php',
            'EasyOpt_REST_Cloud'           => 'includes/class-easyopt-rest-cloud.php',
            'EasyOpt_Cache'                => 'includes/class-easyopt-cache.php',
            'EasyOpt_Cache_Counter'        => 'includes/class-easyopt-cache-counter.php',
            'EasyOpt_Cache_Htaccess_Trait' => 'includes/cache/trait-easyopt-cache-htaccess.php',
            'EasyOpt_Cache_Preload'        => 'includes/class-easyopt-cache-preload.php',
            'EasyOpt_Cache_Purge_Trait'    => 'includes/cache/trait-easyopt-cache-purge.php',
            'EasyOpt_Preload_Results'      => 'includes/class-easyopt-preload-results.php',
            'EasyOpt_Cloudflare'           => 'includes/class-easyopt-cloudflare.php',
            'EasyOpt_Compat_Builders'      => 'includes/compat/class-easyopt-compat-builders.php',
            'EasyOpt_Compat_Conflicts'     => 'includes/compat/class-easyopt-compat-conflicts.php',
            'EasyOpt_Notices'              => 'includes/class-easyopt-notices.php',
            'EasyOpt_Compat_Divi'          => 'includes/compat/class-easyopt-compat-divi.php',
            'EasyOpt_Compat_Fonts'         => 'includes/compat/class-easyopt-compat-fonts.php',
            'EasyOpt_Compat_LazyLoad'      => 'includes/compat/class-easyopt-compat-lazyload.php',
            'EasyOpt_Compat_WooCommerce'   => 'includes/compat/class-easyopt-compat-woocommerce.php',
            'EasyOpt_CDN_Assets'           => 'includes/class-easyopt-cdn-assets.php',
            'EasyOpt_Images'               => 'includes/class-easyopt-images.php',
            'EasyOpt_Images_Capability'    => 'includes/class-easyopt-images-capability.php',
            'EasyOpt_Images_Delivery'      => 'includes/class-easyopt-images-delivery.php',
            'EasyOpt_Images_Encoder'       => 'includes/class-easyopt-images-encoder.php',
            'EasyOpt_Config'               => 'includes/class-easyopt-config.php',
            'EasyOpt_CSS_Tokenizer'        => 'includes/class-easyopt-css-tokenizer.php',
            'EasyOpt_DB_Snapshot'          => 'includes/class-easyopt-db-snapshot.php',
            'EasyOpt_Database'             => 'includes/class-easyopt-database.php',
            'EasyOpt_Debug_Log'            => 'includes/class-easyopt-debug-log.php',
            'EasyOpt_Defer_JS'             => 'includes/class-easyopt-defer-js.php',
            'EasyOpt_Delay_JS'             => 'includes/class-easyopt-delay-js.php',
            'EasyOpt_Fonts'                => 'includes/class-easyopt-fonts.php',
            'EasyOpt_HTML_Mask'            => 'includes/class-easyopt-html-mask.php',
            'EasyOpt_Hosting'              => 'includes/class-easyopt-hosting.php',
            'EasyOpt_LCP'                  => 'includes/class-easyopt-lcp.php',
            'EasyOpt_LazyLoad'             => 'includes/class-easyopt-lazyload.php',
            'EasyOpt_Migration'            => 'includes/class-easyopt-migration.php',
            'EasyOpt_Object_Cache_Manager' => 'includes/class-easyopt-object-cache.php',
            'EasyOpt_Minify'               => 'includes/class-easyopt-minify.php',
            'EasyOpt_Navigate'             => 'includes/class-easyopt-navigate.php',
            'EasyOpt_Preconnect'           => 'includes/class-easyopt-preconnect.php',
            'EasyOpt_Preflight'            => 'includes/class-easyopt-preflight.php',
            'EasyOpt_Presets'              => 'includes/class-easyopt-presets.php',
            'EasyOpt_Queue'                => 'includes/class-easyopt-queue.php',
            'EasyOpt_Queue_Defer_Exception' => 'includes/class-easyopt-queue.php',
            'EasyOpt_Rest_Dashboard'       => 'includes/class-easyopt-rest-dashboard.php',
            'EasyOpt_Rest_Settings'        => 'includes/class-easyopt-rest-settings.php',
            'EasyOpt_SEO'                  => 'includes/class-easyopt-seo.php',
            'EasyOpt_Save_Coordinator'     => 'includes/class-easyopt-save-coordinator.php',
            'EasyOpt_Settings'             => 'includes/class-easyopt-settings.php',
            'EasyOpt_Settings_Registry'    => 'includes/class-easyopt-settings-registry.php',
            'EasyOpt_Tracker'              => 'includes/class-easyopt-tracker.php',
            'EasyOpt_Unused_CSS'           => 'includes/class-easyopt-unused-css.php',
            'EasyOpt_WP_Config'            => 'includes/class-easyopt-wp-config.php',
            'EasyOpt_Wizard'               => 'includes/class-easyopt-wizard.php',
        );

        /** Register on the SPL stack (prepend=false: explicit requires win). */
        public static function register() {
            spl_autoload_register( array( __CLASS__, 'load' ), true, false );
        }

        /**
         * @param string $class Fully-qualified class/trait name.
         */
        public static function load( $class ) {

            // Legacy global classes — direct classmap hit.
            if ( isset( self::$classmap[ $class ] ) ) {
                $file = EASYOPT_DIR . self::$classmap[ $class ];
                if ( is_file( $file ) ) {
                    require_once $file;
                }
                return;
            }

            // EasyOpt\ namespace → includes/{lowercased path}.php
            // e.g. EasyOpt\Backend\Profiler → includes/backend/class-profiler.php
            if ( 0 === strpos( $class, 'EasyOpt\\' ) ) {
                $rel  = strtolower( str_replace( '\\', '/', substr( $class, 8 ) ) );
                $pos  = strrpos( $rel, '/' );
                $dir  = ( false === $pos ) ? '' : substr( $rel, 0, $pos + 1 );
                $name = ( false === $pos ) ? $rel : substr( $rel, $pos + 1 );
                $file = EASYOPT_DIR . 'includes/' . $dir . 'class-' . str_replace( '_', '-', $name ) . '.php';
                if ( is_file( $file ) ) {
                    require_once $file;
                }
            }
        }
    }
}
