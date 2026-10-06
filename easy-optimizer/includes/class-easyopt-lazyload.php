<?php
/**
 * Lazy Load handler for Easy Optimizer.
 *
 * Handles images (including <picture> > <source>, webp, avif), iframes,
 * videos, and CSS background images. Adds missing width/height dimensions
 * by reading the actual file headers.
 *
 * tree build per request). Now uses WordPress core's WP_HTML_Tag_Processor
 * for both the nesting pre-pass and the in-place attribute mutations. The new pipeline avoids the recursive PHP DOM walk that was
 * the previous bottleneck.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_LazyLoad {

    /** @var array Cached image dimensions to avoid repeat disk reads */
    private static $dim_cache = array();

    /** (2.5.4 / perf #8) Persistent dims store, lazily loaded once/request. */
    private static $dim_store = null;

    /** (2.5.4 / perf #8) New entries were added — write back at shutdown. */
    private static $dim_store_dirty = false;

    /** (2.5.4 / perf #8) Hard cap on persisted entries (oldest evicted). */
    const DIM_STORE_MAX = 1500;

    /** Option name for the persisted dims map. */
    const DIM_STORE_OPTION = 'easyopt_img_dims';

    /** @var array|null Cached `easyopt_dims_exclude` patterns for the request */
    private static $dims_exclude_patterns = null;

    /**
     * (2.5.5) Set to true by any mutation that leaves markup only the JS
     * runtime can resolve — `data-bg` backgrounds and deferred `<video>`.
     * easy-optimizer.php reads it AFTER the buffer pass and injects the
     * runtime only when it is true, so the common native-mode page
     * (images + iframes) ships no lazy-load JavaScript at all.
     *
     * Never set a `lazyload` CLASS on an element unless this flag is also
     * set: if no runtime ships, nothing would ever remove that class and a
     * theme rule like `.lazyload{opacity:0}` would hide the element
     * permanently.
     *
     * @var bool
     */
    private static $needs_runtime = false;

    /**
     * Whether this request emitted markup that requires the JS runtime.
     *
     * @since 2.5.5
     * @return bool
     */
    public static function needs_runtime() {
        return self::$needs_runtime;
    }

    /**
     * Whether browser-native lazy loading is in effect.
     *
     * Native mode applies to IMAGES ONLY: the real `src`/`srcset`/`sizes`
     * stay on the element and `loading="lazy"` is added, so the browser's
     * preload scanner can discover, prioritise and schedule the image while
     * the HTML is still parsing. The legacy JS mode replaces `src` with a
     * placeholder, which hides the real URL from the scanner until the
     * runtime executes. Iframes, backgrounds and <video> use the JS path in
     * both modes (see the IFRAME case for why).
     *
     * @since 2.5.5
     * @return bool
     */
    public static function native_mode() {
        return (bool) apply_filters(
            'easyopt_lazy_native',
            (int) EasyOpt_Config::get( 'lazy_native', 1 ) === 1
        );
    }

    /**
     * Process the HTML buffer.
     *
     * Two-pass design:
     *   1. Tag Processor pre-pass — tracks nesting via closers. Builds a token-index
     *      lookup of (a) tags to skip (inside <noscript> or #wpadminbar)
     *      and (b) <source> tags whose sibling <img> we'll lazy-load
     *      (so we transform their srcset to data-srcset).
     *   2. WP_HTML_Tag_Processor mutation pass — fast, forward-only,
     *      flat-attribute work using the lookup tables from pass 1.
     *
     * The `next_tag()` token-index counter increments identically in both
     * passes — that's the contract that lets pass 2 use pass 1's bookmarks
     * without re-walking the tree.
     */
    public static function process_buffer( $html ) {

        $lazy_images  = (int) EasyOpt_Config::get( 'lazy_images', 0 );
        $lazy_iframes = (int) EasyOpt_Config::get( 'lazy_iframes', 0 );
        $lazy_videos  = (int) EasyOpt_Config::get( 'lazy_videos', 0 );
        $add_dims     = (int) EasyOpt_Config::get( 'add_missing_dims', 0 );

        if ( ! $lazy_images && ! $lazy_iframes && ! $lazy_videos && ! $add_dims ) {
            return $html;
        }

        // (2.6.2) Logged-in gate — see easyopt_skip_for_logged_in().
        if ( easyopt_skip_for_logged_in( 'lazyload' ) ) {
            return $html;
        }

        // WooCommerce dynamic pages — lazy-loading on Cart and Checkout
        // delays product thumbnails, payment gateway logos and trust badges.
        if ( EasyOpt_Config::is_woo_dynamic_page() ) {
            return $html;
        }

        if ( empty( $html ) ) {
            return $html;
        }

        // Tag_Processor since 6.2. If missing, return unchanged — same
        // fallback pattern as class-easyopt-accessibility.php.
        if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
            return $html;
        }

        $exclude_values = (string) EasyOpt_Config::get( 'lazyload_exclude', '' );
        $exclude_first  = absint( EasyOpt_Config::get( 'lazyload_exclude_first', 2 ) );
        $exclude_lines  = self::parse_exclude_lines( $exclude_values );

        // ── Pass 1 — nesting metadata (noscript / adminbar / picture) ──
        $meta = self::collect_metadata( $html );

        $native = self::native_mode();

        // ── Pass 2 — mutation ──
        $p = new WP_HTML_Tag_Processor( $html );

        $image_index = 0;
        $token_index = 0;

        while ( $p->next_tag() ) {

            $token_index++;
            $tag = $p->get_tag(); // Always uppercase per HTML spec.

            $skip_this = isset( $meta['skip_bookmarks'][ $token_index ] );

            switch ( $tag ) {

                case 'IFRAME':
                case 'FRAME':
                    if ( $skip_this ) {
                        break;
                    }
                    if ( $add_dims && self::tag_should_add_dims( $p ) ) {
                        self::iframe_add_dims( $p );
                    }
                    if ( $lazy_iframes && self::iframe_eligible( $p, $exclude_lines ) ) {
                        // (2.5.5) Iframes stay on the JS data-src path in BOTH
                        // modes — deliberately. Native loading="lazy" on
                        // iframes is the weakest native lazy feature (Safari
                        // only since 16.4), and even where supported the
                        // browser's near-viewport distance lets a YouTube
                        // embed boot its ~500KB player long before the user
                        // reaches it. data-src blocks the third party
                        // entirely until scroll. In native mode the ~2KB
                        // inline shim (not lazysizes) restores these.
                        self::iframe_lazyify( $p );
                    }
                    break;

                case 'VIDEO':
                    // (2.7.2) Measured as this page's LCP — never defer it.
                    if ( $skip_this || isset( $meta['lcp_videos'][ $token_index ] ) ) {
                        break;
                    }
                    if ( $lazy_videos && self::video_eligible( $p, $exclude_lines ) ) {
                        self::video_lazyify( $p, $native );
                    }
                    break;

                case 'IMG':
                    if ( $skip_this ) {
                        $image_index++;
                        break;
                    }
                    if ( ! self::img_eligible( $p, $exclude_lines ) ) {
                        $image_index++;
                        break;
                    }
                    if ( $add_dims ) {
                        self::img_add_dims( $p );
                    }
                    if ( $lazy_images && $image_index >= $exclude_first ) {
                        if ( $native ) {
                            self::img_lazyify_native( $p );
                        } else {
                            self::img_lazyify( $p );
                        }
                    }
                    $image_index++;
                    break;

                case 'SOURCE':
                    // Native mode leaves <picture>/<source> completely alone:
                    // `loading="lazy"` on the inner <img> already defers the
                    // whole element, and the browser still picks the correct
                    // source. This also fixes a long-standing bug — sliders and
                    // Ajax filters that CLONE a <picture> after load used to
                    // copy `data-srcset` with no live runtime to resolve it,
                    // leaving permanently blank images in the clone.
                    if ( ! $native && $lazy_images && isset( $meta['picture_source_pairs'][ $token_index ] ) ) {
                        self::source_lazyify( $p );
                    }
                    break;

                default:
                    // Background-image lazyload can target any element.
                    if ( $lazy_images && ! $skip_this ) {
                        $style = (string) $p->get_attribute( 'style' );
                        if ( '' !== $style && false !== stripos( $style, 'background-image' ) ) {
                            self::bg_image_lazyify( $p, $style, $exclude_lines );
                        }
                    }
                    break;
            }
        }

        $out = $p->get_updated_html();
        return is_string( $out ) && '' !== $out ? $out : $html;
    }

    /* ─────────────────────────────────────────────
     *  Pass 1 — tree-aware metadata
     * ───────────────────────────────────────────── */

    private static function collect_metadata( $html ) {

        // (2.7.2) Same tokenizer as pass 2, NOT WP_HTML_Processor. The tree
        // parser numbered tags differently from the Tag Processor — as a body
        // fragment it never yields <html>/<head>/<body>, and it adds virtual
        // tags — so every skip index landed on the wrong tag and a GTM
        // <noscript><iframe> got data-src. Counting the Tag Processor's own
        // openers keeps both passes aligned by construction; closers are
        // visited only to track nesting.
        $p = new WP_HTML_Tag_Processor( $html );

        $skip_bookmarks       = array();
        $picture_source_pairs = array();
        $noscript_depth       = 0;
        $wpadminbar           = null;    // array( tag, depth ) while inside #wpadminbar
        $picture_stack        = array(); // pending <source> indexes per open <picture>
        $lcp_videos           = array(); // <video> token indexes measured as the LCP
        $video_open           = null;    // token index of the open <video>
        $token_index          = 0;

        while ( $p->next_tag( array( 'tag_closers' => 'visit' ) ) ) {
            $tag = $p->get_tag();

            if ( $p->is_tag_closer() ) {
                if ( 'NOSCRIPT' === $tag && $noscript_depth > 0 ) {
                    $noscript_depth--;
                } elseif ( 'PICTURE' === $tag && $picture_stack ) {
                    array_pop( $picture_stack );
                } elseif ( 'VIDEO' === $tag ) {
                    $video_open = null;
                }
                if ( null !== $wpadminbar && $tag === $wpadminbar[0] && 0 === --$wpadminbar[1] ) {
                    $wpadminbar = null;
                }
                continue;
            }

            $token_index++;

            // Same-name nesting inside #wpadminbar, so its own closer is found.
            if ( null !== $wpadminbar && $tag === $wpadminbar[0] ) {
                $wpadminbar[1]++;
            }
            if ( null === $wpadminbar && 'wpadminbar' === (string) $p->get_attribute( 'id' ) ) {
                $wpadminbar = array( $tag, 1 );
            }

            if ( $noscript_depth > 0 || null !== $wpadminbar ) {
                $skip_bookmarks[ $token_index ] = true;
            }
            if ( 'NOSCRIPT' === $tag ) {
                $noscript_depth++;
            }

            // <picture> / <source> bookkeeping: a <source> is paired once its
            // picture's (non-skipped) <img> appears.
            // (2.7.2) A poster-less LCP <video> is recorded by its source URL,
            // which usually sits on a child <source> the mutation pass never
            // sees from the <video> tag — resolve it here.
            if ( 'VIDEO' === $tag ) {
                $video_open = $token_index;
                if ( self::is_lcp_url( (string) $p->get_attribute( 'src' ) ) ) {
                    $lcp_videos[ $token_index ] = true;
                }
            } elseif ( 'SOURCE' === $tag && null !== $video_open
                && self::is_lcp_url( (string) $p->get_attribute( 'src' ) ) ) {
                $lcp_videos[ $video_open ] = true;
            }

            if ( 'PICTURE' === $tag ) {
                $picture_stack[] = array();
            } elseif ( 'SOURCE' === $tag && $picture_stack ) {
                $picture_stack[ count( $picture_stack ) - 1 ][] = $token_index;
            } elseif ( 'IMG' === $tag && $picture_stack && ! isset( $skip_bookmarks[ $token_index ] ) ) {
                $top = count( $picture_stack ) - 1;
                foreach ( $picture_stack[ $top ] as $idx ) {
                    $picture_source_pairs[ $idx ] = true;
                }
                $picture_stack[ $top ] = array();
            }
        }

        return array(
            'skip_bookmarks'       => $skip_bookmarks,
            'picture_source_pairs' => $picture_source_pairs,
            'lcp_videos'           => $lcp_videos,
        );
    }

    /* ─────────────────────────────────────────────
     *  Pass 2 — eligibility checks
     * ───────────────────────────────────────────── */

    private static function img_eligible( $p, $exclude_lines ) {
        // Skip if another lazyload plugin already processed this element.
        if ( self::has_class( $p, 'lazyload' )
          || self::has_class( $p, 'rplg-blazy' )
          || self::has_class( $p, 'rs-lazyload' )
          || self::has_class( $p, 'jetpack-lazy-image' )
          || self::has_class( $p, 'ewww-lazy-load' )
          || self::has_class( $p, 'a3-lazy-load' )
          || self::has_class( $p, 'perfmatters-lazy' )
          || self::has_class( $p, 'ls-is-cached' )
          || self::has_class( $p, 'litespeed-lazyloaded' ) ) {
            return false;
        }
        // data-lazy-src = Jetpack / Rocket Lazy Load already handled it.
        if ( null !== $p->get_attribute( 'data-lazy-src' ) ) {
            return false;
        }
        // (2.5.4 / perf #44) WooCommerce product-gallery images. The gallery
        // JS (flexslider + zoom) reads the real src/srcset at init; swapping
        // them to data-src breaks zoom until the image scrolls in. Gallery
        // images carry data-large_image (main image) or live under the
        // woocommerce-product-gallery wrapper with the wp-post-image class —
        // both directly detectable on the tag. They're above the fold on
        // product pages anyway, so lazyloading them had no LCP value.
        if ( null !== $p->get_attribute( 'data-large_image' )
            || null !== $p->get_attribute( 'data-thumb' )
            || self::has_class( $p, 'woocommerce-product-gallery__image' ) ) {
            return false;
        }
        $src = trim( (string) $p->get_attribute( 'src' ) );
        if ( false !== stripos( $src, 'base64' ) || false !== stripos( $src, 'data:image' ) ) {
            return false;
        }
        $fp      = strtolower( (string) $p->get_attribute( 'fetchpriority' ) );
        $loading = strtolower( (string) $p->get_attribute( 'loading' ) );
        if ( 'high' === $fp || 'eager' === $loading ) {
            return false;
        }
        $srcset = (string) $p->get_attribute( 'srcset' );
        if ( ( '' === $src || '#' === $src ) && '' === trim( $srcset ) ) {
            return false;
        }
        return ! self::tag_excluded_by_lines( $p, $exclude_lines );
    }

    private static function iframe_eligible( $p, $exclude_lines ) {
        if ( self::has_class( $p, 'lazyload' )
          || self::has_class( $p, 'jetpack-lazy-image' )
          || self::has_class( $p, 'litespeed-lazyloaded' )
          || self::has_class( $p, 'ls-is-cached' ) ) {
            return false;
        }
        if ( null !== $p->get_attribute( 'data-lazy-src' ) ) {
            return false;
        }
        $src = trim( (string) $p->get_attribute( 'src' ) );
        if ( '' === $src
          || '#' === $src
          || 0 === stripos( $src, 'about:blank' )
          || 0 === stripos( $src, 'javascript:' )
        ) {
            return false;
        }
        if ( false === filter_var( $src, FILTER_VALIDATE_URL ) ) {
            return false;
        }
        return ! self::tag_excluded_by_lines( $p, $exclude_lines );
    }

    private static function video_eligible( $p, $exclude_lines ) {
        if ( self::has_class( $p, 'lazyload' )
          || self::has_class( $p, 'litespeed-lazyloaded' )
          || self::has_class( $p, 'ls-is-cached' ) ) {
            return false;
        }
        if ( null !== $p->get_attribute( 'data-lazy-src' ) ) {
            return false;
        }
        return ! self::tag_excluded_by_lines( $p, $exclude_lines );
    }

    /* ─────────────────────────────────────────────
     *  Pass 2 — mutations
     * ───────────────────────────────────────────── */

    private static function img_lazyify( $p ) {

        $w = (string) $p->get_attribute( 'width' );
        $h = (string) $p->get_attribute( 'height' );

        if ( '' !== $w && '' !== $h ) {
            $w_clean = preg_replace( '/[^0-9]/', '', $w );
            $h_clean = preg_replace( '/[^0-9]/', '', $h );
            $placeholder = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$w_clean} {$h_clean}'%3E%3C/svg%3E";
        } else {
            $placeholder = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
        }

        $srcset = (string) $p->get_attribute( 'srcset' );
        if ( '' !== $srcset ) {
            $p->set_attribute( 'data-srcset', $srcset );
            $p->remove_attribute( 'srcset' );
        }
        $sizes = (string) $p->get_attribute( 'sizes' );
        if ( '' !== $sizes ) {
            $p->set_attribute( 'data-sizes', $sizes );
            $p->remove_attribute( 'sizes' );
        }

        $src = (string) $p->get_attribute( 'src' );
        if ( '' !== $src ) {
            $p->set_attribute( 'data-src', $src );
        }
        $p->set_attribute( 'src', $placeholder );

        $p->add_class( 'lazyload' );
        self::$needs_runtime = true;
    }

    /**
     * (2.5.5) Native image lazy-load.
     *
     * Keeps `src`, `srcset` and `sizes` intact and simply marks the element
     * lazy. That preserves everything the legacy path destroyed:
     *   • the preload scanner sees the real URL during HTML parse;
     *   • responsive selection happens at parse time from a live `sizes`
     *     rather than being re-derived by JS after layout;
     *   • cloned nodes (sliders, Ajax filters, infinite scroll) still work,
     *     because the clone carries a real `src`;
     *   • no placeholder swap, so no flash and no runtime dependency.
     *
     * `decoding="async"` keeps image decode off the main thread. No
     * `lazyload` class is added — nothing would ever remove it, since this
     * path deliberately ships no JavaScript.
     */
    private static function img_lazyify_native( $p ) {

        // Never override an explicit eager/lazy choice made by the theme.
        $existing = strtolower( (string) $p->get_attribute( 'loading' ) );
        if ( '' === $existing ) {
            $p->set_attribute( 'loading', 'lazy' );
        }

        $decoding = strtolower( (string) $p->get_attribute( 'decoding' ) );
        if ( '' === $decoding ) {
            $p->set_attribute( 'decoding', 'async' );
        }
    }

    private static function source_lazyify( $p ) {
        $srcset = (string) $p->get_attribute( 'srcset' );
        if ( '' !== $srcset ) {
            $p->set_attribute( 'data-srcset', $srcset );
            $p->remove_attribute( 'srcset' );
        }
        $sizes = (string) $p->get_attribute( 'sizes' );
        if ( '' !== $sizes ) {
            $p->set_attribute( 'data-sizes', $sizes );
            $p->remove_attribute( 'sizes' );
        }
    }

    private static function iframe_lazyify( $p ) {
        $src = (string) $p->get_attribute( 'src' );
        if ( '' === $src ) {
            return;
        }
        $p->set_attribute( 'data-src', $src );
        $p->remove_attribute( 'src' );
        $p->add_class( 'lazyload' );
        self::$needs_runtime = true;
    }

    /**
     * Defer a <video>.
     *
     * (2.5.5) Reworked. Previously the poster was ALSO deferred
     * (`poster` -> `data-poster`), which meant an empty box until the
     * runtime executed — and on video-hero pages the poster is frequently
     * the LCP element, so deferring it directly delayed LCP while saving
     * almost nothing. The expensive part of a video is the media data, and
     * `preload="none"` alone stops that.
     *
     * So now:
     *   • the poster stays a real attribute (discoverable, paints early);
     *   • a video whose poster is the recorded LCP image is skipped;
     *   • the ORIGINAL preload value is stashed and restored when the
     *     element reaches the viewport, so metadata (duration, scrub bar)
     *     is ready by the time the visitor can press play;
     *   • `autoplay` is deferred so off-screen videos don't start.
     *
     * @param bool $native Whether native mode is active (affects nothing
     *                     here — <video> has no native lazy equivalent —
     *                     but is passed for symmetry and future use).
     */
    private static function video_lazyify( $p, $native = true ) {

        // Skip when the poster is this page's measured LCP image.
        $poster = (string) $p->get_attribute( 'poster' );
        if ( '' !== $poster && self::is_lcp_url( $poster ) ) {
            return;
        }

        // Stash the effective original preload so the runtime can restore
        // it on intersect. `metadata` is the practical browser default when
        // the attribute is absent.
        $orig_preload = strtolower( trim( (string) $p->get_attribute( 'preload' ) ) );
        if ( ! in_array( $orig_preload, array( 'none', 'metadata', 'auto' ), true ) ) {
            $orig_preload = 'metadata';
        }

        // Nothing to defer: no autoplay AND already preload="none" means the
        // browser is already doing exactly what we want — don't add a class
        // or force the runtime to load for no reason.
        $autoplay = (string) $p->get_attribute( 'autoplay' );
        if ( '' === $autoplay && 'none' === $orig_preload ) {
            return;
        }

        $p->set_attribute( 'data-preload', $orig_preload );
        $p->set_attribute( 'preload', 'none' );

        if ( '' !== $autoplay ) {
            $p->set_attribute( 'data-autoplay', $autoplay );
            $p->remove_attribute( 'autoplay' );
        }

        $p->add_class( 'lazyload' );
        self::$needs_runtime = true;
    }

    /**
     * Is this URL the LCP image recorded for the current page?
     *
     * Used to keep a video poster (or any element we would otherwise defer)
     * out of the lazy path when the beacon has measured it as the largest
     * contentful paint. Returns false when no measurement exists, which is
     * the safe default — we simply lose the exemption, never correctness.
     *
     * @since 2.5.5
     * @param string $url Candidate URL.
     * @return bool
     */
    private static function is_lcp_url( $url ) {

        $url = trim( (string) $url );
        if ( '' === $url || ! class_exists( 'EasyOpt_LCP' )
            || ! method_exists( 'EasyOpt_LCP', 'get_lcp_urls' ) ) {
            return false;
        }

        $lcp = (array) EasyOpt_LCP::get_lcp_urls();
        if ( empty( $lcp ) ) {
            return false;
        }

        // Compare path-only so CDN rewriting and protocol differences
        // between the recorded URL and the rendered one don't cause a miss.
        $needle = (string) wp_parse_url( $url, PHP_URL_PATH );
        if ( '' === $needle ) {
            return false;
        }

        foreach ( $lcp as $candidate ) {
            $path = (string) wp_parse_url( (string) $candidate, PHP_URL_PATH );
            if ( '' !== $path && $path === $needle ) {
                return true;
            }
        }

        return false;
    }

    private static function bg_image_lazyify( $p, $style, $exclude_lines ) {

        if ( self::has_class( $p, 'lazyload' ) ) {
            return;
        }
        if ( '' !== (string) $p->get_attribute( 'data-bg' ) ) {
            return;
        }
        if ( self::tag_excluded_by_lines( $p, $exclude_lines ) ) {
            return;
        }

        if ( ! preg_match( '/background-image\s*:\s*url\(\s*([^\)]+)\s*\)/i', $style, $m ) ) {
            return;
        }
        $url = trim( $m[1], " \t\n\r\0\x0B'\"" );
        if ( '' === $url ) {
            return;
        }

        $new_style = preg_replace( '/background-image\s*:\s*url\(\s*([^\)]+)\s*\)\s*;?/i', '', $style );
        $new_style = trim( (string) $new_style );

        $p->set_attribute( 'data-bg', $url );
        if ( '' === $new_style ) {
            $p->remove_attribute( 'style' );
        } else {
            $p->set_attribute( 'style', $new_style );
        }
        $p->add_class( 'lazyload' );
        // No native equivalent for CSS backgrounds — this element can only
        // be resolved by the runtime, so guarantee it ships.
        self::$needs_runtime = true;
    }

    /* ─────────────────────────────────────────────
     *  Dimension injection
     * ───────────────────────────────────────────── */

    private static function img_add_dims( $p ) {

        if ( ! self::tag_should_add_dims( $p ) ) {
            return;
        }
        $w = (string) $p->get_attribute( 'width' );
        $h = (string) $p->get_attribute( 'height' );
        if ( '' !== $w && '' !== $h ) {
            return;
        }
        $src = (string) $p->get_attribute( 'src' );
        if ( '' === $src || false !== stripos( $src, 'data:' ) ) {
            return;
        }
        $dims = self::get_image_dims( $src );
        if ( ! $dims ) {
            return;
        }
        if ( '' === $w ) {
            $p->set_attribute( 'width', (string) $dims[0] );
        }
        if ( '' === $h ) {
            $p->set_attribute( 'height', (string) $dims[1] );
        }
    }

    private static function iframe_add_dims( $p ) {

        $w = (string) $p->get_attribute( 'width' );
        $h = (string) $p->get_attribute( 'height' );
        if ( '' !== $w && '' !== $h ) {
            return;
        }
        $src = (string) $p->get_attribute( 'src' );
        $is_video = ( false !== stripos( $src, 'youtube' )
                   || false !== stripos( $src, 'vimeo' )
                   || false !== stripos( $src, 'dailymotion' ) );

        if ( '' === $w ) {
            $p->set_attribute( 'width', $is_video ? '560' : '600' );
            $w = $is_video ? '560' : '600';
        }
        if ( '' === $h ) {
            $given_w = (int) $w;
            // (2.5.2) Unknown-size iframes now reserve a 16:9 box instead of
            // 600x400 (3:2). Most lazyloaded iframes are video/map embeds, so
            // 16:9 is closer to the real render → less layout shift when the
            // embed loads (part of the sticky-threshold flicker fix).
            $p->set_attribute( 'height', (string) round( $given_w * 9 / 16 ) );
        }
    }

    private static function tag_should_add_dims( $p ) {

        if ( null === self::$dims_exclude_patterns ) {
            $raw = (string) EasyOpt_Config::get( 'dims_exclude', '' );
            self::$dims_exclude_patterns = '' !== $raw
                ? array_filter( array_map( 'trim', explode( "\n", $raw ) ) )
                : array();
        }
        if ( empty( self::$dims_exclude_patterns ) ) {
            return true;
        }

        $needles = array(
            (string) $p->get_attribute( 'class' ),
            (string) $p->get_attribute( 'id' ),
            (string) $p->get_attribute( 'src' ),
        );
        $haystack = implode( ' ', array_filter( $needles ) );
        if ( '' === $haystack ) {
            return true;
        }
        foreach ( self::$dims_exclude_patterns as $pattern ) {
            if ( '' !== $pattern && false !== stripos( $haystack, $pattern ) ) {
                return false;
            }
        }
        return true;
    }

    /* ─────────────────────────────────────────────
     *  Image-dimension lookup (unchanged from 1.5.3)
     * ───────────────────────────────────────────── */

    private static function get_image_dims( $url ) {

        $cache_key = md5( $url );
        if ( isset( self::$dim_cache[ $cache_key ] ) ) {
            return self::$dim_cache[ $cache_key ];
        }

        // (2.5.4 / perf #8) Persistent map: one lazy option read replaces the
        // per-render fopen/getimagesize of every unique image. Media library
        // files are content-addressed by filename (an edited image gets a new
        // URL), so dims for a given URL are stable; the map is wiped on every
        // full cache clear as a safety valve and hard-capped in size.
        $store = self::dim_store();
        if ( isset( $store[ $cache_key ] ) ) {
            $val = $store[ $cache_key ];
            // 0 = known-missing/unreadable sentinel.
            $dims = ( is_array( $val ) && isset( $val[0], $val[1] ) ) ? array( (int) $val[0], (int) $val[1] ) : false;
            self::$dim_cache[ $cache_key ] = $dims;
            return $dims;
        }

        $file = self::url_to_path( $url );
        if ( ! $file || ! file_exists( $file ) ) {
            self::$dim_cache[ $cache_key ] = false;
            return false;
        }

        $ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
        $dims = false;

        switch ( $ext ) {
            case 'webp':
                $dims = self::get_webp_dims( $file );
                break;
            case 'avif':
                $dims = self::get_avif_dims( $file );
                break;
            case 'svg':
                $dims = self::get_svg_dims( $file );
                break;
            default:
                $info = @getimagesize( $file );
                if ( $info && $info[0] > 0 && $info[1] > 0 ) {
                    $dims = array( $info[0], $info[1] );
                }
                break;
        }

        if ( ! $dims && in_array( $ext, array( 'webp', 'avif' ), true ) ) {
            $info = @getimagesize( $file );
            if ( $info && $info[0] > 0 && $info[1] > 0 ) {
                $dims = array( $info[0], $info[1] );
            }
        }

        self::$dim_cache[ $cache_key ] = $dims;
        self::dim_store_put( $cache_key, $dims );
        return $dims;
    }

    /* ── (2.5.4 / perf #8) Persistent dims store ───────────────────────── */

    /** Lazy-load the persisted map (non-autoloaded option, read at most once). */
    private static function dim_store() {
        if ( null === self::$dim_store ) {
            $stored          = get_option( self::DIM_STORE_OPTION, array() );
            self::$dim_store = is_array( $stored ) ? $stored : array();
        }
        return self::$dim_store;
    }

    /** Record a freshly measured result and schedule the shutdown write-back. */
    private static function dim_store_put( $key, $dims ) {
        self::dim_store(); // ensure loaded
        self::$dim_store[ $key ] = ( is_array( $dims ) && isset( $dims[0], $dims[1] ) )
            ? array( (int) $dims[0], (int) $dims[1] )
            : 0;
        if ( ! self::$dim_store_dirty ) {
            self::$dim_store_dirty = true;
            add_action( 'shutdown', array( __CLASS__, 'persist_dim_store' ), 9 );
        }
    }

    /**
     * Shutdown write-back: one option write per request, and only on requests
     * that actually measured something new (steady state writes nothing).
     * Insertion order doubles as age — overflow evicts the oldest entries.
     */
    public static function persist_dim_store() {
        if ( ! self::$dim_store_dirty || ! is_array( self::$dim_store ) ) {
            return;
        }
        self::$dim_store_dirty = false;
        $store = self::$dim_store;
        if ( count( $store ) > self::DIM_STORE_MAX ) {
            $store = array_slice( $store, -self::DIM_STORE_MAX, null, true );
        }
        update_option( self::DIM_STORE_OPTION, $store, false );
    }

    /** Wipe the persisted map (called from full cache clears). */
    public static function flush_dim_store() {
        self::$dim_store       = null;
        self::$dim_store_dirty = false;
        delete_option( self::DIM_STORE_OPTION );
    }

    private static function get_webp_dims( $file ) {
        $fp = @fopen( $file, 'rb' );
        if ( ! $fp ) {
            return false;
        }
        $header = fread( $fp, 30 );
        fclose( $fp );
        if ( strlen( $header ) < 30 ) {
            return false;
        }
        if ( 'RIFF' !== substr( $header, 0, 4 ) || 'WEBP' !== substr( $header, 8, 4 ) ) {
            return false;
        }
        $type = substr( $header, 12, 4 );
        if ( 'VP8 ' === $type ) {
            $w = unpack( 'v', substr( $header, 26, 2 ) )[1] & 0x3FFF;
            $h = unpack( 'v', substr( $header, 28, 2 ) )[1] & 0x3FFF;
            return ( $w > 0 && $h > 0 ) ? array( $w, $h ) : false;
        } elseif ( 'VP8L' === $type ) {
            $bits = unpack( 'V', substr( $header, 21, 4 ) )[1];
            $w = ( $bits & 0x3FFF ) + 1;
            $h = ( ( $bits >> 14 ) & 0x3FFF ) + 1;
            return ( $w > 0 && $h > 0 ) ? array( $w, $h ) : false;
        } elseif ( 'VP8X' === $type ) {
            $w = unpack( 'V', substr( $header, 24, 3 ) . "\x00" )[1] + 1;
            $h = unpack( 'V', substr( $header, 27, 3 ) . "\x00" )[1] + 1;
            return ( $w > 0 && $h > 0 ) ? array( $w, $h ) : false;
        }
        return false;
    }

    private static function get_avif_dims( $file ) {
        $fp = @fopen( $file, 'rb' );
        if ( ! $fp ) {
            return false;
        }
        $data = fread( $fp, 512 );
        fclose( $fp );
        $pos = strpos( $data, 'ispe' );
        if ( false === $pos || ( $pos + 12 ) > strlen( $data ) ) {
            return false;
        }
        $w = unpack( 'N', substr( $data, $pos + 8, 4 ) )[1];
        $h = unpack( 'N', substr( $data, $pos + 12, 4 ) )[1];
        return ( $w > 0 && $h > 0 ) ? array( $w, $h ) : false;
    }

    private static function get_svg_dims( $file ) {
        $content = @file_get_contents( $file, false, null, 0, 4096 );
        if ( ! $content ) {
            return false;
        }
        $w = 0;
        $h = 0;
        if ( preg_match( '/\bwidth\s*=\s*["\']([0-9.]+)/i', $content, $mw ) ) {
            $w = (int) round( (float) $mw[1] );
        }
        if ( preg_match( '/\bheight\s*=\s*["\']([0-9.]+)/i', $content, $mh ) ) {
            $h = (int) round( (float) $mh[1] );
        }
        if ( $w > 0 && $h > 0 ) {
            return array( $w, $h );
        }
        if ( preg_match( '/viewBox\s*=\s*["\']([^"\']+)["\']/i', $content, $mv ) ) {
            $parts = preg_split( '/[\s,]+/', trim( $mv[1] ) );
            if ( count( $parts ) === 4 ) {
                $vw = (int) round( (float) $parts[2] );
                $vh = (int) round( (float) $parts[3] );
                if ( $vw > 0 && $vh > 0 ) {
                    return array( $vw, $vh );
                }
            }
        }
        return false;
    }

    /* ─────────────────────────────────────────────
     *  Helpers
     * ───────────────────────────────────────────── */

    private static function url_to_path( $url ) {
        if ( empty( $url ) ) {
            return false;
        }
        if ( 0 === strpos( $url, '//' ) ) {
            $url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
        }
        if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
            return ABSPATH . ltrim( $url, '/' );
        }
        $local = array(
            trailingslashit( home_url() ),
            trailingslashit( site_url() ),
        );
        $clean = explode( '?', $url )[0];
        $rel   = str_ireplace( $local, '', $clean );
        if ( preg_match( '#^https?://#i', $rel ) ) {
            return false;
        }
        return ABSPATH . ltrim( $rel, '/' );
    }

    private static function has_class( $p, $class ) {
        if ( method_exists( $p, 'has_class' ) ) {
            return (bool) $p->has_class( $class );
        }
        $c = (string) $p->get_attribute( 'class' );
        if ( '' === $c ) {
            return false;
        }
        $tokens = preg_split( '/\s+/', strtolower( $c ) );
        return in_array( strtolower( $class ), $tokens, true );
    }

    private static function parse_exclude_lines( $raw ) {
        static $cache = array();
        $key = md5( (string) $raw );
        if ( isset( $cache[ $key ] ) ) {
            return $cache[ $key ];
        }
        $lines = '' !== (string) $raw
            ? array_filter( array_map( 'trim', explode( "\n", $raw ) ) )
            : array();
        $cache[ $key ] = $lines;
        return $lines;
    }

    /**
     * Match the tag against the user-configured exclude lines. Replaces
     * the substring-of-outerHTML check from the simple_html_dom era — we
     * now check class, id, src, srcset and data-src, which cover the
     * patterns users actually configure (CSS selectors, file paths, CDN
     * URL fragments, lazy-pre-applied data-src markers).
     */
    private static function tag_excluded_by_lines( $p, $exclude_lines ) {
        if ( empty( $exclude_lines ) ) {
            return false;
        }
        $needles = array(
            (string) $p->get_attribute( 'class' ),
            (string) $p->get_attribute( 'id' ),
            (string) $p->get_attribute( 'src' ),
            (string) $p->get_attribute( 'srcset' ),
            (string) $p->get_attribute( 'data-src' ),
        );
        $haystack = implode( ' ', array_filter( $needles ) );
        if ( '' === $haystack ) {
            return false;
        }
        foreach ( $exclude_lines as $line ) {
            if ( '' !== $line && false !== stripos( $haystack, $line ) ) {
                return true;
            }
        }
        return false;
    }
}
