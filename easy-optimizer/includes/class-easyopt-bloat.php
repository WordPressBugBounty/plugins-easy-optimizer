<?php
/**
 * Bloat removal — every toggle on the Bloat tab has a `easyopt_bloat_*`
 * option that this class consults at init() time. Each toggle is independent
 * so users can keep just the ones they want.
 *
 * Anything that has to run on the front-end early (emoji removal, REST gating,
 * heartbeat throttling) is wired here. Anything that only matters in admin
 * (application passwords) bails on `is_admin()` checks within its handler.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Bloat {

    public static function init() {

        // WP-Cron throttle — runs independently of the bloat toggles.
        self::maybe_throttle_cron();

        // request. The flag is set/cleared by `recalc_any_enabled()` which
        // runs only when a Bloat option actually changes. Front-end pages
        // and admin pages now pay one option read on the bail path, not 14.
        if ( ! self::any_enabled() ) {
            return;
        }

        if ( self::on( 'emojis' ) ) {
            self::disable_emojis();
        }

        if ( self::on( 'embeds' ) ) {
            self::disable_embeds();
        }

        if ( self::on( 'xmlrpc' ) ) {
            self::disable_xmlrpc();
        }

        if ( self::on( 'jquery_migrate' ) ) {
            add_action( 'wp_default_scripts', array( __CLASS__, 'remove_jquery_migrate' ) );
        }

        if ( self::on( 'wp_version' ) ) {
            self::hide_wp_version();
        }

        if ( self::on( 'rsd_wlw' ) ) {
            self::strip_rsd_wlw();
        }

        if ( self::on( 'shortlinks' ) ) {
            self::strip_shortlink();
        }

        if ( self::on( 'rss_feeds' ) ) {
            self::disable_rss_feeds();
        }

        if ( self::on( 'self_pingbacks' ) ) {
            add_action( 'pre_ping', array( __CLASS__, 'block_self_pingbacks' ) );
        }

        if ( self::on( 'rest_api_logged_out' ) ) {
            add_filter( 'rest_authentication_errors', array( __CLASS__, 'rest_logged_out_only' ) );
        }

        if ( self::on( 'heartbeat' ) ) {
            self::throttle_heartbeat();
        }

        if ( self::on( 'wc_cart_fragments' ) ) {
            add_action( 'wp_enqueue_scripts', array( __CLASS__, 'kill_wc_cart_fragments' ), 11 );
        }

        if ( self::on( 'app_passwords' ) ) {
            add_filter( 'wp_is_application_passwords_available', '__return_false' );
        }

        if ( self::on( 'dashicons' ) ) {
            add_action( 'wp_enqueue_scripts', array( __CLASS__, 'kill_dashicons_for_anons' ), 100 );
        }

        if ( self::on( 'block_css' ) ) {
            add_action( 'wp_enqueue_scripts', array( __CLASS__, 'kill_block_library_css' ), 100 );
        }
    }

    /* ─────────────────────────────────────────────
     *  Helpers
     * ───────────────────────────────────────────── */

    private static function on( $key ) {
        return (int) EasyOpt_Config::get( 'bloat_' . $key, 0 ) > 0;
    }

    /**
     * Cached flag — single option read instead of 14. Recomputed only when
     * a Bloat option actually changes (see EasyOpt_Save_Coordinator).
     */
    private static function any_enabled() {
        return (int) EasyOpt_Config::get( 'bloat_any', 0 ) > 0;
    }

    /**
     * Recompute the easyopt_bloat_any flag. Called from the option-update
     * hook in easy-optimizer.php whenever any easyopt_bloat_* option saves.
     */
    public static function recalc_any_enabled() {
        $keys = array(
            'emojis', 'embeds', 'xmlrpc', 'jquery_migrate', 'wp_version',
            'rsd_wlw', 'shortlinks', 'rss_feeds', 'self_pingbacks',
            'rest_api_logged_out', 'heartbeat', 'wc_cart_fragments',
            'app_passwords', 'dashicons', 'block_css',
        );
        $any = 0;
        foreach ( $keys as $k ) {
            if ( (int) EasyOpt_Config::get( 'bloat_' . $k, 0 ) > 0 ) {
                $any = 1;
                break;
            }
        }
        update_option( 'easyopt_bloat_any', $any, true );
    }

    /* ─────────────────────────────────────────────
     *  Individual handlers
     * ───────────────────────────────────────────── */

    public static function disable_emojis() {
        remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
        remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
        remove_action( 'wp_print_styles', 'print_emoji_styles' );
        remove_action( 'admin_print_styles', 'print_emoji_styles' );
        remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
        remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
        remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
        add_filter( 'tiny_mce_plugins', function ( $plugins ) {
            return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
        } );
        add_filter( 'emoji_svg_url', '__return_false' );
    }

    public static function disable_embeds() {
        remove_action( 'rest_api_init', 'wp_oembed_register_route' );
        remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10 );
        remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
        remove_action( 'wp_head', 'wp_oembed_add_host_js' );
        // The wp-embed footer script was already being printed before the
        // old dequeue fired on some themes; deregister at the source kills
        // it everywhere reliably.
        add_action( 'init', function () {
            if ( ! is_admin() ) {
                wp_deregister_script( 'wp-embed' );
            }
        }, 1 );
    }

    public static function disable_xmlrpc() {
        add_filter( 'xmlrpc_enabled', '__return_false' );
        add_filter( 'wp_headers', function ( $headers ) {
            unset( $headers['X-Pingback'] );
            return $headers;
        } );
        // Strip the rsd link from <head>.
        remove_action( 'wp_head', 'rsd_link' );
    }

    public static function remove_jquery_migrate( $scripts ) {
        if ( ! is_admin() && isset( $scripts->registered['jquery'] ) ) {
            $jq = $scripts->registered['jquery'];
            if ( is_array( $jq->deps ) ) {
                $jq->deps = array_diff( $jq->deps, array( 'jquery-migrate' ) );
            }
        }
    }

    public static function hide_wp_version() {
        remove_action( 'wp_head', 'wp_generator' );
        add_filter( 'the_generator', '__return_empty_string' );
        // Strip ?ver= from enqueued styles/scripts so the WordPress version isn't
        // leaked there. (2.7.2) ONLY when it IS the WordPress version: a plugin's
        // or theme's own ver (or Elementor's timestamp) is the cache-buster that
        // makes an updated file a new URL. Stripping all of them froze CSS/JS on
        // the edge and in browsers for a year after every update.
        $wp_ver = (string) get_bloginfo( 'version' );
        $strip  = function ( $src ) use ( $wp_ver ) {
            if ( is_string( $src ) && '' !== $wp_ver && false !== strpos( $src, 'ver=' ) ) {
                parse_str( (string) wp_parse_url( $src, PHP_URL_QUERY ), $args );
                if ( isset( $args['ver'] ) && (string) $args['ver'] === $wp_ver ) {
                    $src = remove_query_arg( 'ver', $src );
                }
            }
            return $src;
        };
        add_filter( 'style_loader_src', $strip, 9999 );
        add_filter( 'script_loader_src', $strip, 9999 );
    }

    public static function strip_rsd_wlw() {
        remove_action( 'wp_head', 'rsd_link' );
        remove_action( 'wp_head', 'wlwmanifest_link' );
    }

    public static function strip_shortlink() {
        remove_action( 'wp_head', 'wp_shortlink_wp_head' );
        remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
    }

    public static function disable_rss_feeds() {
        $bail = function () {
            wp_die(
                esc_html__( 'RSS feeds are disabled on this site.', 'easy-optimizer' ),
                '',
                array( 'response' => 404 )
            );
        };
        add_action( 'do_feed',      $bail, 1 );
        add_action( 'do_feed_rdf',  $bail, 1 );
        add_action( 'do_feed_rss',  $bail, 1 );
        add_action( 'do_feed_rss2', $bail, 1 );
        add_action( 'do_feed_atom', $bail, 1 );
        remove_action( 'wp_head', 'feed_links', 2 );
        remove_action( 'wp_head', 'feed_links_extra', 3 );
    }

    public static function block_self_pingbacks( &$links ) {
        $home = home_url();
        foreach ( $links as $i => $link ) {
            if ( 0 === strpos( $link, $home ) ) {
                unset( $links[ $i ] );
            }
        }
    }

    public static function rest_logged_out_only( $result ) {
        // Don't override an existing error.
        if ( ! empty( $result ) ) {
            return $result;
        }
        if ( is_user_logged_in() ) {
            return $result;
        }
        // logged out. The previous implementation blocked the entire
        // /wp-json/ surface, which silently broke Contact Form 7, WPForms,
        // WooCommerce Store API, Gutenberg search, Yoast schema validators,
        // and a long tail of plugins that rely on logged-out REST. Site
        // owners can extend this list via `easyopt_rest_logged_out_allow`.
        //
        // Each entry is matched against the start of the route (after the
        // /wp-json/ prefix), so "wc/store/" allows wc/store/v1, wc/store/v2,
        // etc., in one entry.
        $allow = (array) apply_filters( 'easyopt_rest_logged_out_allow', array(
            'contact-form-7/',
            'wpcf7/',
            'wpforms/',
            'gravityforms/',
            'wc/store/',         // WooCommerce Store API (cart, checkout)
            'wp/v2/search',      // Gutenberg search block
            'oembed/',           // oEmbed discovery
            'wp-site-health/',
        ) );

        $route = isset( $GLOBALS['wp']->query_vars['rest_route'] )
            ? ltrim( (string) $GLOBALS['wp']->query_vars['rest_route'], '/' )
            : '';
        if ( '' === $route && isset( $_SERVER['REQUEST_URI'] ) ) {
            // Fallback for environments where rest_route isn't populated yet.
            $uri = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
            if ( preg_match( '#/wp-json/(.+?)(?:\?|$)#', $uri, $m ) ) {
                $route = $m[1];
            }
        }
        foreach ( $allow as $prefix ) {
            $prefix = trim( (string) $prefix );
            if ( '' !== $prefix && 0 === strpos( $route, $prefix ) ) {
                return $result; // allowed — let it through
            }
        }

        return new WP_Error(
            'rest_logged_out',
            __( 'REST API restricted to authenticated users.', 'easy-optimizer' ),
            array( 'status' => 401 )
        );
    }

    public static function throttle_heartbeat() {
        $location  = EasyOpt_Config::get( 'heartbeat_location', 'allow_admin' );
        $frequency = (int) EasyOpt_Config::get( 'heartbeat_frequency', 60 );

        // ── Location control ──
        if ( 'disabled' === $location ) {
            // Kill heartbeat everywhere.
            add_action( 'init', function () {
                wp_deregister_script( 'heartbeat' );
            }, 1 );
            return; // No frequency needed if fully disabled.
        }

        if ( 'allow_editor' === $location ) {
            // Only allow heartbeat on post editor screens.
            add_action( 'init', function () {
                global $pagenow;
                if ( ! is_admin() ) {
                    wp_deregister_script( 'heartbeat' );
                }
            }, 1 );
            add_action( 'admin_init', function () {
                global $pagenow;
                if ( ! in_array( $pagenow, array( 'post.php', 'post-new.php' ), true ) ) {
                    wp_deregister_script( 'heartbeat' );
                }
            }, 1 );
        } elseif ( 'allow_admin' === $location ) {
            // Disable on frontend, allow in admin.
            add_action( 'init', function () {
                if ( ! is_admin() ) {
                    wp_deregister_script( 'heartbeat' );
                }
            }, 1 );
        }
        // 'everywhere' — don't disable anywhere, just control frequency.

        // ── Frequency control ──
        if ( $frequency > 0 && 15 !== $frequency ) {
            add_filter( 'heartbeat_settings', function ( $settings ) use ( $frequency ) {
                $settings['interval'] = $frequency;
                return $settings;
            } );
        }
    }

    /**
     * Throttle WP-Cron spawning using a transient-based lock.
     *
     * WordPress fires `_wp_cron()` on every page load via `wp_loaded`.
     * When a throttle interval is set, we prevent `_wp_cron` from running
     * if the last spawn was less than N seconds ago. This reduces the
     * overhead of cron checks on high-traffic sites without requiring
     * DISABLE_WP_CRON or wp-config.php changes.
     */
    public static function maybe_throttle_cron() {

        // Master toggle — if cron throttle is disabled, skip entirely.
        if ( ! (int) EasyOpt_Config::get( 'cron_throttle', 0 ) ) {
            return;
        }

        $interval = (int) EasyOpt_Config::get( 'cron_frequency', 0 );
        if ( $interval <= 0 ) {
            return;
        }

        // If WP-Cron is already disabled via constant, nothing to throttle.
        if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
            return;
        }

        // Don't interfere when wp-cron.php is called directly (system cron).
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return;
        }

        $lock = get_transient( 'easyopt_cron_lock' );
        if ( $lock ) {
            // Last spawn was recent — prevent cron from firing.
            remove_action( 'wp_loaded', '_wp_cron', 20 );
        } else {
            // Allow cron to run and set the lock.
            set_transient( 'easyopt_cron_lock', time(), $interval );
        }
    }

    public static function kill_wc_cart_fragments() {
        if ( wp_script_is( 'wc-cart-fragments', 'registered' ) ) {
            wp_dequeue_script( 'wc-cart-fragments' );
        }
    }

    public static function kill_dashicons_for_anons() {
        if ( ! is_user_logged_in() ) {
            wp_dequeue_style( 'dashicons' );
            wp_deregister_style( 'dashicons' );
        }
    }

    public static function kill_block_library_css() {
        wp_dequeue_style( 'wp-block-library' );
        wp_dequeue_style( 'wp-block-library-theme' );
        if ( class_exists( 'WooCommerce' ) ) {
            wp_dequeue_style( 'wc-blocks-vendors-style' );
            wp_dequeue_style( 'wc-all-blocks-style' );
        }
    }
}
