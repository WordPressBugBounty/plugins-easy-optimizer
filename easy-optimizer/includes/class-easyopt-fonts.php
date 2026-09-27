<?php
/**
 * Font optimization for Easy Optimizer.
 *
 * Forces font-display: swap on all @font-face declarations:
 *  1. Google Fonts <link> tags — appends &display=swap to URL
 *  2. Inline <style> blocks — injects font-display:swap into @font-face
 *  3. External stylesheets (including used CSS cache) — rewrites cached
 *     files ONCE and remembers the marker (mtime) so we don't pound the
 *     disk on every request.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Fonts {

    /** Option key for processed-marker store: { abs_path => mtime } */
    const PROCESSED_OPTION = 'easyopt_fonts_processed';

    /**
     * Register filters that run on every request (not only inside the output buffer).
     */
    public static function init_always() {

        // (2.4.4) Font stripping is NO LONGER applied at Used-CSS generation.
        // The .used.css on disk is the FULL, lossless critical CSS (every
        // @font-face). Stripping below-the-fold fonts and choosing which fonts
        // to preload are decided per render in apply_used_css(), driven by the
        // beacon's above-the-fold signature. This keeps the cache file as a
        // source of truth (a font that later becomes above-the-fold can be
        // re-kept — impossible once stripped out of the file) and means a plain
        // page re-render after a cache purge reflects the current signature, so
        // a cache clear alone updates font behaviour. See apply_used_css().

        // EasyOpt_Save_Coordinator calls on_lazyload_fonts_change()
        // directly when the relevant key appears in the save diff.
    }

    public static function on_lazyload_fonts_change() {
        // (2.4.4) No Used-CSS regeneration needed: the strip is applied at
        // injection time from the always-full .used.css, so toggling Lazyload
        // Fonts takes effect on the next render. The save coordinator already
        // wipes the page cache (the key is in $html_affecting), which forces
        // that render. Kept as a hook point for back-compat.
    }

    /**
     * Delete every collected-fonts sidecar so the beacon re-collects fresh
     * (e.g. after a redesign or font swap). Also resets the Used CSS cache,
     * because the keep/strip decisions depend on the collected ATF font list —
     * the same reset that runs when the lazy-load setting changes. Returns the
     * number of sidecars removed. This is an explicit admin action (the
     * "Clear fonts data" button), not a per-request operation, so it has no
     * bearing on front-end CPU.
     */
    public static function clear_all_fonts_data() {
        // Fonts-only: we do NOT clear Used CSS here. Each type's Used CSS
        // regenerates by itself when its fonts are next re-collected (the
        // change-detection in store_beacon_fonts), so this button stays
        // strictly fonts-only.
        return self::clear_sidecars();
    }

    /**
     * Delete every collected-fonts sidecar (delete-only; no Used-CSS reset).
     * Shared by the "Clear fonts data" button and the full-reset path in
     * EasyOpt_Unused_CSS (theme switch / Customizer save). (2.6.4)
     *
     * @return int Number of sidecars removed.
     */
    public static function clear_sidecars() {
        $dir     = self::fonts_cache_dir();
        $removed = 0;
        if ( '' !== $dir && is_dir( $dir ) ) {
            $files = glob( $dir . '*.eo-fonts.json' );
            if ( is_array( $files ) ) {
                foreach ( $files as $f ) {
                    if ( is_file( $f ) && @unlink( $f ) ) {
                        $removed++;
                    }
                }
            }
        }
        return $removed;
    }

    /**
     * Render-time font transform applied to the Used-CSS string at injection.
     *
     * The on-disk .used.css is the FULL critical CSS (every @font-face). This
     * single pass decides, from the beacon's above-the-fold signature ($af):
     *   - which @font-face to KEEP (strip the rest) when Lazyload Fonts is on;
     *   - which typography srcs to PRELOAD (max 2), ATF-gated.
     *
     * Because the decision is made here (not baked into the cache file), a
     * plain page re-render reflects the current signature, and nothing is ever
     * permanently lost. Stripping/preloading are SKIPPED until the beacon has
     * reported (empty $af) — that fail-safe is what stops icon/slider glyph
     * fonts (e.g. 'slick', which lives in ::before pseudo-elements the beacon
     * never measures) from being preloaded, and stops below-fold fonts from
     * vanishing before we know what is actually above the fold.
     *
     * @param string $css          Full Used-CSS (contents of .used.css).
     * @param string $type         URL type (sidecar key). Empty → current.
     * @param string $preload_html OUT: ATF-gated <link rel=preload> tags.
     * @return string CSS to inject (stripped to ATF when Lazyload Fonts is on).
     */
    public static function apply_used_css( $css, $type = '', &$preload_html = '' ) {

        $preload_html = '';
        if ( empty( $css ) || false === stripos( $css, '@font-face' ) ) {
            return $css;
        }
        if ( '' === $type ) {
            $type = self::current_type();
        }

        $do_lazyload = (int) EasyOpt_Config::get( 'lazyload_fonts', 0 )
            && 'remove' !== EasyOpt_Config::get( 'unused_css_behavior', 'delayed' );
        $do_preload  = (int) EasyOpt_Config::get( 'easyopt_preload_fonts', 0 );

        if ( ! $do_lazyload && ! $do_preload ) {
            return $css;
        }

        $af = self::get_af_signature( $type );

        // FAIL-SAFE: no measurement yet → keep all fonts, preload nothing.
        if ( empty( $af ) ) {
            return $css;
        }

        // Manual keeps from the Exclude Fonts setting (family or URL fragment).
        $manual      = array();
        $exclude_raw = EasyOpt_Config::get( 'fonts_exclude', '' );
        if ( '' !== $exclude_raw ) {
            $manual = array_filter( array_map( 'trim', explode( "\n", (string) $exclude_raw ) ) );
        }

        $preload      = array();
        $preload_fams = array();

        // Linear strpos + brace scan (no PCRE backtrack limits on huge minified
        // sheets / base64 data-URI fonts). For each @font-face: decide keep AND,
        // on an ATF match, record its typography src to preload. We accumulate
        // the kept output only when lazyloading; otherwise the CSS is injected
        // unchanged and the scan exists purely to resolve the ATF preload set.
        $out = '';
        $len = strlen( $css );
        $pos = 0;
        while ( $pos < $len ) {
            $at = stripos( $css, '@font-face', $pos );
            if ( false === $at ) {
                $out .= substr( $css, $pos );
                break;
            }
            $out  .= substr( $css, $pos, $at - $pos );
            $brace = strpos( $css, '{', $at );
            if ( false === $brace ) {
                $out .= substr( $css, $at );
                break;
            }
            $close = strpos( $css, '}', $brace );
            if ( false === $close ) {
                $out .= substr( $css, $at );
                break;
            }
            $block = substr( $css, $at, $close - $at + 1 );
            $keep  = self::should_keep_font_face( $block, $af, $manual, $preload, $preload_fams );
            // Drop below-fold blocks only when lazyloading; otherwise keep all.
            if ( ! $do_lazyload || $keep ) {
                $out .= $block;
            }
            $pos = $close + 1;
        }

        // Build the ATF-gated preload links (max 2). $preload was populated by
        // should_keep_font_face only for ATF-matched, non-icon typography — so
        // decorative/icon fonts never reach here even without a denylist.
        if ( $do_preload && ! empty( $preload ) ) {
            $links = '';
            foreach ( array_slice( $preload, 0, 2 ) as $url ) {
                $path   = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
                $mime   = ( substr( $path, -5 ) === '.woff' ) ? 'font/woff' : 'font/woff2';
                $links .= '<link rel="preload" as="font" type="' . esc_attr( $mime ) . '"'
                        . ' href="' . esc_url( $url ) . '" crossorigin data-easyopt-font="1">';
            }
            $preload_html = $links;
        }

        if ( ! $do_lazyload ) {
            return $css; // inject unchanged; we only needed the preload set
        }

        // Remove @import rules that reference font providers (those load late).
        $out = preg_replace(
            '/@import\s+(?:url\()?[\'"]?[^\'")]*(?:fonts\.|font-awesome|googleapis\.com\/css|typekit|fontello|icomoon)[^\'")]*[\'"]?\)?\s*;?/i',
            '',
            $out
        );
        $out = preg_replace( "/\n\s*\n+/", "\n", $out );

        return $out;
    }

    /**
     * Decide whether a single @font-face block is used above the fold (keep) or
     * below it (strip for lazy-loading). On keep, records the typography (non-
     * icon) src for preloading — at most ONE URL per family (#5 dedupe). Every
     * branch fails SAFE (keep) so a parse miss never drops a needed font.
     */
    private static function should_keep_font_face( $block, $af, $manual, &$preload, &$preload_fams ) {

        // Manual override always wins (keep).
        foreach ( $manual as $needle ) {
            if ( '' !== $needle && false !== stripos( $block, $needle ) ) {
                return true;
            }
        }

        $fam = self::sanitize_family( self::ff_prop( $block, 'font-family' ) );
        if ( '' === $fam ) {
            return true; // FAIL-SAFE: unknown family → keep
        }

        $weight = self::ff_weight( $block );
        $style  = ( false !== stripos( self::ff_prop( $block, 'font-style' ), 'italic' ) ) ? 'italic' : 'normal';
        $urange = self::ff_unicode_range( $block );

        $matched = false;
        foreach ( $af as $sig ) {
            if ( 0 !== strcasecmp( (string) ( isset( $sig['f'] ) ? $sig['f'] : '' ), $fam ) ) {
                continue;
            }
            if ( ! self::weight_in_range( (int) ( isset( $sig['w'] ) ? $sig['w'] : 400 ), $weight ) ) {
                continue;
            }
            if ( ( isset( $sig['s'] ) ? $sig['s'] : 'normal' ) !== $style ) {
                continue;
            }
            // Subset: with a unicode-range, at least one above-fold code point
            // must fall in it. No range (or unparseable) → matches.
            $cps = ( isset( $sig['u'] ) && is_array( $sig['u'] ) ) ? $sig['u'] : array();
            if ( ! empty( $urange ) && ! empty( $cps ) && ! self::range_covers( $urange, $cps ) ) {
                continue;
            }
            $matched = true;
            break;
        }

        if ( ! $matched ) {
            return false; // below the fold → strip (lazy-load)
        }

        // Keep. Record typography (non-icon) src for preloading — one per family.
        $src = self::ff_first_src( $block );
        if ( '' !== $src && ! self::is_icon_font( $src ) && ! self::is_icon_font( $fam ) ) {
            $fkey = strtolower( $fam );
            if ( ! isset( $preload_fams[ $fkey ] ) && ! in_array( $src, $preload, true ) ) {
                $preload[]             = $src;
                $preload_fams[ $fkey ] = 1;
            }
        }
        return true;
    }

    /* ── @font-face parsing helpers (run only at Used-CSS generation) ────── */

    private static function ff_prop( $block, $prop ) {
        if ( preg_match( '/' . preg_quote( $prop, '/' ) . '\s*:\s*([^;}]+)/i', $block, $mm ) ) {
            return trim( $mm[1] );
        }
        return '';
    }

    /** font-weight → [min,max]. Handles single, normal/bold, and ranges. */
    private static function ff_weight( $block ) {
        $val = strtolower( self::ff_prop( $block, 'font-weight' ) );
        if ( '' === $val ) {
            return array( 400, 400 );
        }
        $val = str_replace( array( 'normal', 'bold' ), array( '400', '700' ), $val );
        if ( preg_match_all( '/\d{2,4}/', $val, $nums ) && ! empty( $nums[0] ) ) {
            $ns = array_map( 'intval', $nums[0] );
            return array( min( $ns ), max( $ns ) );
        }
        return array( 400, 400 );
    }

    private static function weight_in_range( $w, $range ) {
        if ( ! is_array( $range ) || count( $range ) < 2 ) {
            return true; // FAIL-SAFE
        }
        return ( $w >= $range[0] && $w <= $range[1] );
    }

    /** unicode-range descriptor → array of [start,end] pairs. */
    private static function ff_unicode_range( $block ) {
        $val = self::ff_prop( $block, 'unicode-range' );
        if ( '' === $val ) {
            return array();
        }
        $ranges = array();
        foreach ( explode( ',', $val ) as $tok ) {
            $tok = trim( $tok );
            if ( preg_match( '/U\+([0-9A-F]+)-([0-9A-F]+)/i', $tok, $r ) ) {
                $ranges[] = array( hexdec( $r[1] ), hexdec( $r[2] ) );
            } elseif ( strpos( $tok, '?' ) !== false && preg_match( '/U\+([0-9A-F]*)\?+/i', $tok, $r ) ) {
                $qn = substr_count( $tok, '?' );
                $ranges[] = array(
                    hexdec( $r[1] . str_repeat( '0', $qn ) ),
                    hexdec( $r[1] . str_repeat( 'F', $qn ) ),
                );
            } elseif ( preg_match( '/U\+([0-9A-F]+)/i', $tok, $r ) ) {
                $cp = hexdec( $r[1] );
                $ranges[] = array( $cp, $cp );
            }
        }
        return $ranges;
    }

    private static function range_covers( $ranges, $codepoints ) {
        foreach ( $codepoints as $cp ) {
            foreach ( $ranges as $r ) {
                if ( $cp >= $r[0] && $cp <= $r[1] ) {
                    return true;
                }
            }
        }
        return false;
    }

    /** First woff2/woff URL in the src descriptor (prefers woff2), absolute. */
    private static function ff_first_src( $block ) {
        if ( preg_match_all( '/url\(\s*[\'"]?\s*([^\'")\s]+?\.woff2?)(?:[?#][^\'")\s]*)?\s*[\'"]?\s*\)/i', $block, $mm ) && ! empty( $mm[1] ) ) {
            foreach ( $mm[1] as $u ) {
                if ( preg_match( '/\.woff2$/i', $u ) ) {
                    return self::abs_url( $u );
                }
            }
            return self::abs_url( $mm[1][0] );
        }
        return '';
    }

    /** Best-effort absolute URL for a font src (handles //, /, http). */
    private static function abs_url( $u ) {
        $u = trim( $u );
        if ( '' === $u ) {
            return '';
        }
        if ( preg_match( '#^https?://#i', $u ) || 0 === strpos( $u, '//' ) ) {
            return $u;
        }
        if ( 0 === strpos( $u, '/' ) ) {
            return home_url( $u );
        }
        return home_url( '/' . ltrim( $u, '/' ) );
    }

    /**
     * Process the HTML buffer.
     */
    public static function process_buffer( $html ) {

        $do_swap    = (int) EasyOpt_Config::get( 'font_display_swap', 0 );
        $do_preload = (int) EasyOpt_Config::get( 'preload_fonts', 0 );

        if ( ! $do_swap && ! $do_preload ) {
            return $html;
        }

        // (2.6.2) Logged-in gate — see easyopt_skip_for_logged_in().
        if ( easyopt_skip_for_logged_in( 'fonts' ) ) {
            return $html;
        }

        // WooCommerce dynamic pages — font processing / preload on Cart,
        // Checkout and My Account is unnecessary and disruptive.
        if ( EasyOpt_Config::is_woo_dynamic_page() ) {
            return $html;
        }

        // URL-based exclusion (Settings → Fonts → Exclude URLs).
        if ( self::is_url_excluded() ) {
            return $html;
        }

        // 0. Font preloads are injected by the Used-CSS injector so they land
        // right after the critical CSS (and after the LCP preload), order-
        // independently — see EasyOpt_Fonts::get_preload_html(). Nothing here.

        if ( $do_swap ) {
            // 1. Google Fonts <link> tags — add &display=swap.
            $html = self::swap_google_fonts_links( $html );
            // 2. Inline <style> blocks — inject font-display:swap into @font-face.
            $html = self::swap_inline_styles( $html );
            // 3. Local stylesheet <link> tags — rewrite @font-face in linked CSS.
            $html = self::swap_linked_stylesheets( $html );
        }

        return $html;
    }

    /* ─────────────────────────────────────────────
     *  0. Above-the-fold font preload (2.4.3)
     * ───────────────────────────────────────────── */

    /**
     * Metadata dir for collected-fonts sidecars (.eo-fonts.json). (2.6.4) Moved
     * out of cache/easyopt/css/ into a dedicated cache/easyopt/meta/fonts/
     * folder. Computed locally for AJAX safety.
     */
    private static function fonts_cache_dir() {
        return trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt/meta/fonts/';
    }

    private static function fonts_sidecar_path( $type, $viewport = '' ) {
        $type = sanitize_file_name( (string) $type );
        if ( '' === $type ) {
            return '';
        }
        // (2.4.5) Viewport-keyed so the above-the-fold font set measured on
        // mobile isn't preloaded on desktop (and vice-versa). An empty/invalid
        // viewport yields the legacy (pre-2.4.5) path, which is still read as a
        // migration fallback until the per-viewport sidecars are populated.
        $vp = ( 'mobile' === $viewport || 'desktop' === $viewport ) ? ( '.' . $viewport ) : '';
        return self::fonts_cache_dir() . $type . $vp . '.eo-fonts.json';
    }

    /** Render/collection viewport used for sidecar keying. */
    private static function current_viewport() {
        // (EO-03) Use the SAME mobile test that keys the page cache file
        // (cache-common is_mobile_ua), not wp_is_mobile(). The two disagree on
        // Android tablets / Amazon Silk, which previously let a desktop cache
        // file be written with mobile font hints (and vice-versa).
        if ( function_exists( 'EasyOpt\\Cache\\is_mobile_ua' ) ) {
            $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
            return \EasyOpt\Cache\is_mobile_ua( $ua ) ? 'mobile' : 'desktop';
        }
        // Fallback only if cache-common isn't loaded for some reason.
        return ( function_exists( 'wp_is_mobile' ) && wp_is_mobile() ) ? 'mobile' : 'desktop';
    }

    /**
     * Decode a sidecar for (type, viewport), falling back to the legacy
     * un-viewporled sidecar so existing data keeps working after upgrade.
     *
     * @return array Decoded data, or array() if none / unreadable.
     */
    private static function read_sidecar( $type, $viewport ) {
        $file = self::fonts_sidecar_path( $type, $viewport );
        if ( '' === $file || ! is_file( $file ) ) {
            $file = self::fonts_sidecar_path( $type, '' ); // legacy fallback
            if ( '' === $file || ! is_file( $file ) ) {
                return array();
            }
        }
        $json = @file_get_contents( $file );
        if ( false === $json || '' === $json ) {
            return array();
        }
        $data = json_decode( $json, true );
        return is_array( $data ) ? $data : array();
    }

    /** Current request's URL type — delegate to Used CSS so keys line up. */
    private static function current_type() {
        if ( class_exists( 'EasyOpt_Unused_CSS' ) && method_exists( 'EasyOpt_Unused_CSS', 'get_url_type' ) ) {
            return EasyOpt_Unused_CSS::get_url_type();
        }
        return '';
    }

    /** Read the stored ATF font URL list for a URL type. */
    /**
     * (2.5.4 / perf #2) Invalidation signature for the transform performed by
     * apply_used_css(): the current viewport, the mtime+size of the viewport
     * sidecar and of the legacy (viewport-less) fallback sidecar, and every
     * setting apply_used_css() reads. Used by EasyOpt_Unused_CSS to key its
     * transformed-CSS cache — any change here transparently busts that cache.
     *
     * @param string $type URL type (sidecar key).
     * @return string
     */
    public static function used_css_transform_sig( $type ) {
        $vp    = self::current_viewport();
        $parts = array( 'fv1', (string) $vp );
        foreach ( array( self::fonts_sidecar_path( $type, $vp ), self::fonts_sidecar_path( $type, '' ) ) as $path ) {
            if ( '' !== $path && file_exists( $path ) ) {
                $st      = @stat( $path );
                $parts[] = false !== $st ? ( $st['mtime'] . ':' . $st['size'] ) : '0';
            } else {
                $parts[] = 'x';
            }
        }
        $parts[] = (int) EasyOpt_Config::get( 'lazyload_fonts', 0 );
        $parts[] = (string) EasyOpt_Config::get( 'unused_css_behavior', 'delayed' );
        $parts[] = (int) EasyOpt_Config::get( 'easyopt_preload_fonts', 0 );
        $parts[] = md5( (string) EasyOpt_Config::get( 'fonts_exclude', '' ) );
        return implode( '|', $parts );
    }

    /** Above-the-fold font signature collected by the beacon. */
    public static function get_af_signature( $type, $viewport = null ) {
        if ( null === $viewport ) {
            $viewport = self::current_viewport();
        }
        $data = self::read_sidecar( $type, $viewport );
        return ( ! empty( $data['af'] ) && is_array( $data['af'] ) ) ? $data['af'] : array();
    }

    /** Typography preload URLs resolved by the strip filter (max 2). */
    public static function get_preload_urls( $type, $viewport = null ) {
        if ( null === $viewport ) {
            $viewport = self::current_viewport();
        }
        $data = self::read_sidecar( $type, $viewport );
        return ( ! empty( $data['pl'] ) && is_array( $data['pl'] ) ) ? $data['pl'] : array();
    }

    /**
     * Record the typography preload URLs the strip filter resolved from the
     * kept @font-face blocks. Merges into the existing sidecar (preserves
     * 'af'); writes only when the list changed, to avoid churn.
     */
    public static function set_preload_urls( $type, $urls, $viewport = null ) {
        if ( null === $viewport ) {
            $viewport = self::current_viewport();
        }
        $file = self::fonts_sidecar_path( $type, $viewport );
        if ( '' === $file || ! is_file( $file ) ) {
            return;
        }
        $json = @file_get_contents( $file );
        if ( false === $json || '' === $json ) {
            return;
        }
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) ) {
            return;
        }
        $urls = array_values( array_slice( array_unique( array_filter( (array) $urls ) ), 0, 2 ) );
        $cur  = ( isset( $data['pl'] ) && is_array( $data['pl'] ) ) ? $data['pl'] : array();
        if ( $cur === $urls ) {
            return;
        }
        $data['pl'] = $urls;
        $payload = wp_json_encode( $data );
        if ( false !== $payload ) {
            @file_put_contents( $file, $payload, LOCK_EX );
        }
    }

    /** Sanitize a font-family name: strip quotes/control chars, cap length. */
    private static function sanitize_family( $fam ) {
        $fam = trim( (string) $fam, " \t\n\r\0\x0B\"'" );
        $fam = preg_replace( '/[\x00-\x1F\x7F]/', '', $fam );
        if ( strlen( $fam ) > 100 ) {
            $fam = substr( $fam, 0, 100 );
        }
        return trim( $fam );
    }

    /** Compare two AF signatures by family|weight|style + sorted code points. */
    private static function af_equal( $a, $b ) {
        if ( ! is_array( $a ) || ! is_array( $b ) || count( $a ) !== count( $b ) ) {
            return false;
        }
        $norm = function ( $list ) {
            $rows = array();
            foreach ( $list as $e ) {
                if ( ! is_array( $e ) ) {
                    continue;
                }
                $u = ( isset( $e['u'] ) && is_array( $e['u'] ) ) ? $e['u'] : array();
                sort( $u );
                $rows[] = strtolower( (string) ( isset( $e['f'] ) ? $e['f'] : '' ) ) . '|'
                    . (int) ( isset( $e['w'] ) ? $e['w'] : 0 ) . '|'
                    . (string) ( isset( $e['s'] ) ? $e['s'] : '' ) . '|'
                    . implode( ',', $u );
            }
            sort( $rows );
            return $rows;
        };
        return $norm( $a ) === $norm( $b );
    }

    /**
     * Does the beacon still need to collect fonts for this type? True until
     * the first report lands. Re-armed when Used CSS (and thus the sidecar)
     * is cleared, or on theme switch.
     */
    public static function beacon_fonts_needed( $type, $viewport = null ) {
        if ( null === $viewport ) {
            $viewport = self::current_viewport();
        }
        $file = self::fonts_sidecar_path( $type, $viewport );
        return ( '' !== $file && ! is_file( $file ) );
    }

    /**
     * Store ATF fonts reported by the beacon. Keeps only same-origin / known
     * font-host woff2|woff URLs, in priority order, capped at 3. Never purges.
     *
     * @param string       $type
     * @param string|array $raw  Comma-separated string or array of font URLs.
     */
    public static function store_beacon_fonts( $type, $raw, $page_url = '', $viewport = '' ) {

        if ( 'mobile' !== $viewport && 'desktop' !== $viewport ) {
            $viewport = self::current_viewport();
        }
        $dir = self::fonts_cache_dir();
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return;
        }
        $file = self::fonts_sidecar_path( $type, $viewport );
        if ( '' === $file ) {
            return;
        }

        // Payload (2.4.4): a JSON signature of the fonts that actually RENDER
        // above the fold — [{f:family, w:weight, s:style, u:[codepoints]}]. No
        // URLs; the server owns the @font-face rules and resolves URL + subset
        // itself in the strip filter. Computed-style collection means delayed
        // CSS / late fonts no longer hide typography the way resource-timing did.
        $decoded = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
        if ( ! is_array( $decoded ) ) {
            return;
        }

        $cap = (int) apply_filters( 'easyopt_preload_fonts_max', 12 );
        if ( $cap < 1 ) {
            $cap = 1;
        }

        $af = array();
        foreach ( $decoded as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            $fam = self::sanitize_family( isset( $entry['f'] ) ? (string) $entry['f'] : '' );
            if ( '' === $fam ) {
                continue;
            }
            $w = isset( $entry['w'] ) ? (int) $entry['w'] : 400;
            if ( $w < 1 || $w > 1000 ) {
                $w = 400;
            }
            $s = ( isset( $entry['s'] ) && 'italic' === $entry['s'] ) ? 'italic' : 'normal';
            $u = array();
            if ( isset( $entry['u'] ) && is_array( $entry['u'] ) ) {
                foreach ( $entry['u'] as $cp ) {
                    $cp = (int) $cp;
                    if ( $cp > 0 && $cp <= 0x10FFFF ) {
                        $u[] = $cp;
                    }
                    if ( count( $u ) >= 300 ) {
                        break;
                    }
                }
                sort( $u );
                $u = array_values( array_unique( $u ) );
            }
            $af[] = array( 'f' => $fam, 'w' => $w, 's' => $s, 'u' => $u );
            if ( count( $af ) >= $cap ) {
                break;
            }
        }

        if ( empty( $af ) ) {
            return;
        }

        // Write (and trigger a regen) only when the signature actually changed.
        // The beacon stops collecting once a sidecar exists, so this normally
        // runs once per (type, viewport); the compare also absorbs the brief
        // multi-visitor race before the first sidecar lands.
        //
        // (2.4.5) Skip only when an EXISTING per-viewport sidecar already holds
        // this exact signature. We must NOT fall back to the legacy sidecar for
        // this check — doing so would wrongly skip creating the viewport file
        // after upgrade, leaving the beacon collecting forever.
        if ( is_file( $file )
            && self::af_equal( self::get_af_signature( $type, $viewport ), $af ) ) {
            return;
        }

        $payload = wp_json_encode( array( 'v' => time(), 'af' => $af ) );
        if ( false === $payload ) {
            return;
        }
        if ( false === @file_put_contents( $file, $payload, LOCK_EX ) ) {
            return;
        }

        // (2.4.4) The .used.css is full and the strip/preload are applied at
        // injection, so NO Used-CSS regeneration is needed — every render of
        // this type now reads the new signature automatically. We only need the
        // page that was just measured to re-render so the visitor-collected
        // strip + preload appears immediately instead of waiting for TTL.
        // Targeted (one URL), no preload restart, and skipped on
        // skip_live_buffer hosts (see clear_learned_url) to avoid a raw-page
        // window. Other pages of this type pick it up on their next render.
        if ( '' !== (string) $page_url && class_exists( 'EasyOpt_Cache' )
            && method_exists( 'EasyOpt_Cache', 'clear_learned_url' ) ) {
            EasyOpt_Cache::clear_learned_url( $page_url );
        }
    }

    /** Same-origin, configured CDN, or a well-known font host. */
    /**
     * Heuristic: is this font URL an ICON font (vs body/heading typography)?
     *
     * Used only to decide PRELOAD eligibility — icon fonts are decorative and
     * never the LCP, so preloading them just competes with the real hero/text
     * resources. It does NOT affect whether the font is kept in critical CSS;
     * an above-the-fold icon font is still kept by the strip filter so its
     * glyphs don't flash/vanish.
     *
     * Detection is by URL/filename only (the beacon reports URLs). A curated
     * keyword list catches the common packs; a generic "icon" path check
     * catches the rest. A false positive only means "not preloaded" (the font
     * still renders via CSS), and the Exclude Fonts box is the manual override,
     * so we deliberately bias toward catching icon fonts. Pure string ops — no
     * measurable cost, and it only runs during render (cached afterwards).
     */
    private static function is_icon_font( $url ) {
        $u = strtolower( (string) $url );
        if ( '' === $u ) {
            return false;
        }
        $needles = array(
            'elementskit', 'jkiticon', 'eicons', 'elementor-icons',
            'fontawesome', 'font-awesome', 'fa-brands', 'fa-solid', 'fa-regular',
            'dashicons', 'material-icons', 'materialicons', 'material-symbols',
            'icomoon', 'glyphicon', 'ionicons', 'themify', 'bootstrap-icons',
            'fontello', 'simple-line-icons', 'genericons', 'line-awesome',
            'lineawesome', 'iconfont', 'icon-pack', 'iconpack',
        );
        foreach ( $needles as $n ) {
            if ( false !== strpos( $u, $n ) ) {
                return true;
            }
        }
        // Generic catch: an "icon" token in the path (e.g. /icons/, -icon-,
        // jkiticon). High recall; a misclassified body font only loses a
        // preload, never a render.
        $path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
        if ( false !== strpos( $path, 'icon' ) ) {
            return true;
        }
        return false;
    }

    private static function is_preloadable_font_host( $url ) {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( '' === $host ) {
            return false;
        }
        $allowed = array(
            strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
            strtolower( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
            'fonts.gstatic.com',
        );
        $cdn = EasyOpt_Config::get( 'cdn_host', '' );
        if ( ! empty( $cdn ) ) {
            $allowed[] = strtolower( $cdn );
        }
        return in_array( $host, array_filter( $allowed ), true );
    }

    /**
     * Back-compat shim. Preloads are now resolved by apply_used_css() in the
     * same pass that strips fonts, gated on the beacon's above-the-fold
     * signature (so icon/slider fonts like 'slick' are never preloaded). This
     * delegates so any external caller still gets the correct, ATF-gated tags.
     *
     * @param string $used_css Full Used CSS.
     * @param string $type     URL type (defaults to current).
     */
    public static function get_preload_html( $used_css, $type = '' ) {
        $preload_html = '';
        self::apply_used_css( $used_css, $type, $preload_html );
        return $preload_html;
    }

    /* ─────────────────────────────────────────────
     *  1. Google Fonts <link> tags
     * ───────────────────────────────────────────── */

    private static function swap_google_fonts_links( $html ) {

        // Match both `href="..."` and `data-easyopt-delayed="..."` since
        // Unused CSS may rename the attribute by the time Fonts runs.
        // We do NOT pre-filter on rel here — instead we extract the full tag
        // and validate inside the callback so we can reject preconnect,
        // preload, dns-prefetch etc. that also point at fonts.googleapis.com.
        return preg_replace_callback(
            '#<link\b[^>]*?(href|data-easyopt-delayed)=(["\'])([^"\']*fonts\.googleapis\.com[^"\']*)\2[^>]*/?\s*>#i',
            function ( $m ) {
                $tag   = $m[0];
                $delim = $m[2];
                $url   = $m[3];

                // Only operate on real stylesheets — never preconnect, preload, dns-prefetch.
                if ( ! preg_match( '/\brel\s*=\s*["\']?stylesheet\b/i', $tag ) ) {
                    return $tag;
                }

                // Decode entities so we operate on the actual URL.
                $decoded = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

                if ( false !== stripos( $decoded, 'display=' ) ) {
                    $new_url = preg_replace( '/display=[^&"\']+/', 'display=swap', $decoded );
                } else {
                    $sep     = ( false !== strpos( $decoded, '?' ) ) ? '&' : '?';
                    $new_url = $decoded . $sep . 'display=swap';
                }

                if ( $new_url === $decoded ) {
                    return $tag;
                }

                // Re-encode `&` as `&amp;` for HTML safety in the attribute.
                $encoded = str_replace( '&', '&amp;', $new_url );

                return str_replace( $delim . $url . $delim, $delim . $encoded . $delim, $tag );
            },
            $html
        );
    }

    /* ─────────────────────────────────────────────
     *  2. Inline <style> blocks
     * ───────────────────────────────────────────── */

    private static function swap_inline_styles( $html ) {

        return preg_replace_callback(
            '#<style\b[^>]*>(.*?)</style>#si',
            function ( $m ) {
                $full = $m[0];
                $css  = $m[1];

                if ( false === stripos( $css, '@font-face' ) ) {
                    return $full;
                }

                $new_css = self::inject_font_display_swap( $css );
                if ( $new_css === $css ) {
                    return $full;
                }
                return str_replace( $css, $new_css, $full );
            },
            $html
        );
    }

    /* ─────────────────────────────────────────────
     *  3. Linked local stylesheets
     * ───────────────────────────────────────────── */

    /**
     * Walk every <link rel="stylesheet"> in the buffer and ensure the local
     * CSS file has font-display:swap. We persist a marker map in the options
     * table { canonical_path => last_seen_mtime } so we DON'T re-read,
     * re-parse and re-write the same CSS file on every request — the 1.4.0
     * implementation did, which was the dominant cost of this feature.
     */
    private static function swap_linked_stylesheets( $html ) {

        if ( ! preg_match_all(
            '#<link\b[^>]*rel=["\']stylesheet["\'][^>]*href=["\']([^"\']+)["\'][^>]*/?\s*>#i',
            $html,
            $matches,
            PREG_SET_ORDER
        ) ) {
            return $html;
        }

        $processed       = get_option( self::PROCESSED_OPTION, array() );
        if ( ! is_array( $processed ) ) {
            $processed = array();
        }
        $processed_dirty = false;

        foreach ( $matches as $match ) {

            $href = html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );

            // Skip Google Fonts (handled in swap_google_fonts_links).
            if ( false !== stripos( $href, 'fonts.googleapis.com' ) ) {
                continue;
            }

            $file = self::url_to_path( $href );
            if ( ! $file || ! file_exists( $file ) ) {
                continue;
            }

            // Resolve to a canonical path before any further checks.
            $real = realpath( $file );
            if ( ! $real ) {
                continue;
            }

            // Path traversal protection — must live inside WP_CONTENT_DIR.
            $content_real = realpath( WP_CONTENT_DIR );
            if ( ! $content_real || 0 !== strpos( $real, $content_real ) ) {
                continue;
            }

            // Only touch files in uploads/ or cache/ directories
            // (don't modify theme/plugin source files).
            if ( false === strpos( $real, '/uploads/' ) && false === strpos( $real, '/cache/' ) ) {
                continue;
            }

            // Skip files we've already processed at the current mtime —
            // this is the perf win vs. 1.4.0.
            $mtime = filemtime( $real );
            if ( false !== $mtime && isset( $processed[ $real ] ) && (int) $processed[ $real ] === (int) $mtime ) {
                continue;
            }

            // Read + check + (maybe) rewrite.
            $css = @file_get_contents( $real );
            if ( ! $css || false === stripos( $css, '@font-face' ) ) {
                // Mark as seen so we don't re-read every page load.
                if ( false !== $mtime ) {
                    $processed[ $real ] = (int) $mtime;
                    $processed_dirty    = true;
                }
                continue;
            }

            if ( ! is_writable( $real ) ) {
                continue;
            }

            $new_css = self::inject_font_display_swap( $css );
            if ( $new_css !== $css ) {
                if ( false !== @file_put_contents( $real, $new_css, LOCK_EX ) ) {
                    clearstatcache( true, $real );
                    $new_mtime          = filemtime( $real );
                    $processed[ $real ] = ( false !== $new_mtime ) ? (int) $new_mtime : time();
                    $processed_dirty    = true;
                }
            } elseif ( false !== $mtime ) {
                $processed[ $real ] = (int) $mtime;
                $processed_dirty    = true;
            }
        }

        if ( $processed_dirty ) {
            // Cap the size of the marker map so it doesn't grow unbounded
            // on sites with churning cache filenames.
            // (2.5.4 / perf #28) 500 → 3000. Elementor/builder sites easily
            // reference >500 generated CSS files; at the old cap the map
            // thrashed — every render evicted markers, re-read the evicted
            // files from disk and re-wrote the option, forever. Entries are
            // a path + int (~100 bytes), the option is non-autoloaded and
            // read once per render, so the ceiling costs ~0.3 MB worst-case
            // only on sites that actually have that many files. Additionally
            // drop markers whose file is gone (stat is answered from the
            // request's stat cache for files touched above; for evicted
            // paths it prunes churn instead of keeping dead entries).
            if ( count( $processed ) > 3000 ) {
                foreach ( array_keys( $processed ) as $easyopt_marker_path ) {
                    if ( ! @file_exists( $easyopt_marker_path ) ) {
                        unset( $processed[ $easyopt_marker_path ] );
                    }
                }
                if ( count( $processed ) > 3000 ) {
                    $processed = array_slice( $processed, -3000, null, true );
                }
            }
            update_option( self::PROCESSED_OPTION, $processed, false );
        }

        return $html;
    }

    /* ─────────────────────────────────────────────
     *  Core: inject font-display:swap into CSS
     * ───────────────────────────────────────────── */

    private static function inject_font_display_swap( $css ) {

        return preg_replace_callback(
            '/@font-face\s*\{([^}]+)\}/i',
            function ( $m ) {
                $block = $m[1];

                if ( preg_match( '/font-display\s*:/i', $block ) ) {
                    $block = preg_replace( '/font-display\s*:\s*[^;]+/i', 'font-display:swap', $block );
                } else {
                    $block = rtrim( $block, "; \t\n\r" ) . ';font-display:swap;';
                }

                return '@font-face{' . $block . '}';
            },
            $css
        );
    }

    /* ─────────────────────────────────────────────
     *  URL exclusion (pages to skip entirely)
     * ───────────────────────────────────────────── */

    /**
     * Check if the current URL should be excluded from font optimisation.
     * Reads the `easyopt_fonts_exclude_urls` setting (one URL substring
     * per line), same pattern as Delay JS / Unused CSS / LCP.
     */
    private static function is_url_excluded() {

        $excludes = EasyOpt_Config::get( 'fonts_exclude_urls', '' );
        if ( empty( $excludes ) ) {
            return false;
        }

        $current = home_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );
        $lines   = array_filter( array_map( 'trim', explode( "\n", (string) $excludes ) ) );

        foreach ( $lines as $pattern ) {
            // (2.6.5) Shared wildcard-aware matcher — consistent with every
            // other Exclude URLs field.
            if ( self::url_excludes( $current, $pattern ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Wildcard-or-substring URL match via the plugin-wide canonical matcher,
     * with a substring fallback if cache-common.php is not loaded.
     *
     * @since 2.6.5
     */
    private static function url_excludes( $url, $pattern ) {
        if ( function_exists( '\\EasyOpt\\Cache\\url_matches_pattern' ) ) {
            return \EasyOpt\Cache\url_matches_pattern( $url, $pattern );
        }
        $pattern = trim( (string) $pattern );
        return '' !== $pattern && false !== stripos( (string) $url, $pattern );
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

        $local = array( trailingslashit( home_url() ), trailingslashit( site_url() ) );
        $clean = strtok( $url, '?#' );
        $rel   = str_ireplace( $local, '', $clean );

        if ( preg_match( '#^https?://#i', $rel ) ) {
            return false;
        }

        return ABSPATH . ltrim( $rel, '/' );
    }
}
