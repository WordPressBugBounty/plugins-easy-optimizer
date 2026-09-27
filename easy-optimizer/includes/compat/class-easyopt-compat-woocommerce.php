<?php
/**
 * WooCommerce compatibility layer.
 *
 * Cache-level integrations for WooCommerce:
 *
 *  1. Dynamic-page exclusion — never WRITE the cart, checkout, account or any
 *     WC endpoint page to cache. The check runs at buffer time via the
 *     `easyopt_cache_html_cacheable` filter, where WooCommerce's conditional
 *     tags (is_cart()/is_checkout()/is_account_page()/is_wc_endpoint_url())
 *     are reliable, so it also covers custom and translated page slugs that a
 *     static URL list would miss.
 *
 *  2. Currency-cookie keying — when a multi-currency switcher is active, vary
 *     the cache by its currency cookie so shoppers always see prices in the
 *     currency they selected. Only registers a cookie when its plugin is
 *     detected, so single-currency stores keep one cache variant per page.
 *
 *  3. Stock-change cache purge — when a product's stock level changes
 *     (purchase, manual edit, variation update, REST inventory sync), purge
 *     the product page, shop page and related taxonomy archives so stock
 *     badges, counts and "Add to Cart" buttons stay accurate.
 *
 * @package EasyOptimizer
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Compat_WooCommerce {

    /**
     * Wire up — only when WooCommerce is present.
     */
    public static function init() {

        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // ── 1. Never cache cart / checkout / account / WC endpoints ──
        // Buffer-time filter: at this point WC conditional tags are resolved,
        // so custom/translated slugs are handled correctly.
        add_filter( 'easyopt_cache_html_cacheable', array( __CLASS__, 'exclude_dynamic_pages' ), 10, 2 );

        // ── 2. Vary cache by currency-switcher cookie (when one is active) ──
        add_filter( 'easyopt_cache_include_cookies', array( __CLASS__, 'register_currency_cookies' ) );

        // ── 3. Stock change purge ──
        add_action( 'woocommerce_product_set_stock',  array( __CLASS__, 'on_stock_change' ) );
        add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'on_stock_change' ) );

        // REST inventory sync.
        add_action( 'woocommerce_rest_insert_product_object', array( __CLASS__, 'on_rest_product_update' ) );
    }

    /* ─────────────────────────────────────────────
     *  1. Dynamic-page exclusion (write-time)
     * ───────────────────────────────────────────── */

    /**
     * Block caching of WooCommerce dynamic pages. Returns false (do not cache)
     * for cart / checkout / account / WC endpoint pages; otherwise leaves the
     * decision untouched.
     *
     * @param  bool        $cacheable Current cacheable decision.
     * @param  string|null $html      Buffered HTML (unused).
     * @return bool
     */
    public static function exclude_dynamic_pages( $cacheable, $html = null ) {
        unset( $html );
        if ( ! $cacheable ) {
            return $cacheable;
        }
        if ( class_exists( 'EasyOpt_Config' ) && EasyOpt_Config::is_woo_dynamic_page() ) {
            return false;
        }
        return $cacheable;
    }

    /* ─────────────────────────────────────────────
     *  2. Currency-cookie keying
     * ───────────────────────────────────────────── */

    /**
     * Add the active currency switcher's cookie to the cache key. Detection is
     * per-plugin so stores without a switcher are never fragmented.
     *
     * Both the runtime writer (EasyOpt_Cache::include_cookie_tag()) and the
     * drop-in read this same filter, so the keys always line up.
     *
     * @param  array $cookies Existing include-cookie names.
     * @return array
     */
    public static function register_currency_cookies( $cookies ) {

        $cookies = (array) $cookies;

        // WooCommerce Multi Currency / CURCY (VillaTheme).
        if ( defined( 'WOOMULTI_CURRENCY_VERSION' )
             || defined( 'WOOMULTI_CURRENCY_F_VERSION' )
             || class_exists( 'WOOMULTI_CURRENCY_Data' )
             || class_exists( 'WOOMULTI_CURRENCY_F_Data' ) ) {
            $cookies[] = 'wmc_current_currency';
        }

        // WPML WooCommerce Multilingual (WCML).
        if ( class_exists( 'woocommerce_wpml' ) ) {
            $cookies[] = 'wcml_currency';
        }

        // Aelia Currency Switcher.
        if ( class_exists( 'WC_Aelia_CurrencySwitcher' ) ) {
            $cookies[] = 'aelia_cs_selected_currency';
        }

        // FOX – Currency Switcher Professional for WooCommerce (WOOCS).
        if ( class_exists( 'WOOCS' ) ) {
            $cookies[] = 'woocommerce_current_currency';
        }

        /**
         * Filter the WooCommerce currency cookies added to the cache key.
         * Lets store owners register a switcher we don't detect.
         *
         * @param array $cookies Currency cookie names.
         */
        $cookies = (array) apply_filters( 'easyopt_woo_currency_cookies', $cookies );

        return array_values( array_unique( array_filter( array_map( 'strval', $cookies ) ) ) );
    }

    /* ─────────────────────────────────────────────
     *  3. Stock-change cache purge
     * ───────────────────────────────────────────── */

    /**
     * Purge product page + shop page + taxonomy archives when stock changes.
     *
     * @param \WC_Product $product Product object (passed by WooCommerce).
     */
    public static function on_stock_change( $product ) {
        self::purge_product_pages( $product );
    }

    /**
     * Purge when a product is created / updated via the WC REST API.
     *
     * @param \WC_Product $product Product object.
     */
    public static function on_rest_product_update( $product ) {
        self::purge_product_pages( $product );
    }

    /* ─────────────────────────────────────────────
     *  Shared purge helper
     * ───────────────────────────────────────────── */

    /**
     * Purge a product's URL, the shop page, and its taxonomy archives. Archive
     * pages (shop, categories, tags, home) are purged WITH their pagination;
     * the single product page is purged on its own.
     *
     * @param \WC_Product $product WooCommerce product object.
     */
    private static function purge_product_pages( $product ) {

        if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
            return;
        }

        $product_id = $product->get_id();
        if ( ! $product_id ) {
            return;
        }

        // For variations, purge the parent (the page visitors actually see).
        if ( method_exists( $product, 'is_type' ) && $product->is_type( 'variation' ) ) {
            $parent_id = $product->get_parent_id();
            if ( $parent_id ) {
                $product_id = $parent_id;
            }
        }

        if ( ! class_exists( 'EasyOpt_Cache' ) ) {
            return;
        }

        // (2.5.4 / perf #3) Stock changes fire inside CHECKOUT requests — a
        // multi-line-item order used to run this whole fan-out (globs + the
        // per-URL hosting/Cloudflare hooks) once per line item while the
        // customer waited. All URLs now go through the deduplicated purge
        // queue and are drained once on shutdown, after the response has been
        // flushed. Same URLs purged, none of the checkout latency.
        $queue = method_exists( 'EasyOpt_Cache', 'queue_url_purge' );

        // Product page (single — no pagination).
        $url = get_permalink( $product_id );
        if ( $url ) {
            $queue ? EasyOpt_Cache::queue_url_purge( $url ) : EasyOpt_Cache::clear_url( $url );
        }

        // Shop page (archive — with pagination).
        if ( function_exists( 'wc_get_page_id' ) ) {
            $shop_id = wc_get_page_id( 'shop' );
            if ( $shop_id > 0 ) {
                $shop_url = get_permalink( $shop_id );
                if ( $shop_url ) {
                    $queue ? EasyOpt_Cache::queue_url_purge( $shop_url, true ) : EasyOpt_Cache::clear_url( $shop_url, true );
                }
            }
        }

        // Homepage (often shows featured / latest products) — with pagination.
        $queue ? EasyOpt_Cache::queue_url_purge( home_url( '/' ), true ) : EasyOpt_Cache::clear_url( home_url( '/' ), true );

        // Taxonomy archives (categories, tags) — with pagination.
        $taxonomies = get_object_taxonomies( get_post_type( $product_id ) );
        foreach ( $taxonomies as $tax ) {
            $tax_obj = get_taxonomy( $tax );
            if ( ! $tax_obj || empty( $tax_obj->public ) ) {
                continue;
            }
            $terms = wp_get_post_terms( $product_id, $tax, array( 'fields' => 'all' ) );
            if ( is_wp_error( $terms ) ) {
                continue;
            }
            foreach ( $terms as $term ) {
                $link = get_term_link( $term );
                if ( ! is_wp_error( $link ) ) {
                    $queue ? EasyOpt_Cache::queue_url_purge( $link, true ) : EasyOpt_Cache::clear_url( $link, true );
                }
            }
        }

        // Log.
        if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
            EasyOpt_Debug_Log::warn(
                'compat',
                sprintf( 'WooCommerce product %d changed — purged product and related pages.', $product_id )
            );
        }
    }
}
