<?php
/**
 * EasyOpt — local image delivery.
 *
 * Because the optimizer never rewrites stored URLs (see EasyOpt_Images), the
 * database still points at photo.jpg everywhere. This class is what actually
 * gets the AVIF in front of a browser: at render time it wraps <img> in a
 * <picture> and offers the sibling variants as <source> elements.
 *
 *     <picture>
 *       <source type="image/avif" srcset="…photo.jpg.avif 1024w, …">
 *       <source type="image/webp" srcset="…photo.jpg.webp 1024w, …">
 *       <img src="…photo.jpg" srcset="…">   <- untouched, still the fallback
 *     </picture>
 *
 * Content negotiation is the browser's job, which means a browser that
 * understands neither format gets the original and nothing breaks. Turning
 * the feature off removes the wrapper and the site is byte-for-byte back to
 * where it started — no migration, no database repair.
 *
 * This is deliberately weaker than the cloud tier: it can only offer
 * variants that already exist on disk, in the sizes WordPress already
 * generated. There is no per-device sizing and no on-the-fly transform.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Images_Delivery {

    /** @var array<string,bool> Per-request memo of variant existence. */
    private static $exists = array();

    /** @var string|null Uploads base URL, normalised, scheme-relative. */
    private static $base_url = null;

    /** @var string|null Uploads base directory. */
    private static $base_dir = null;

    /** @var string|null Uploads path with the host stripped, for root-relative URLs. */
    private static $base_path = null;

    /**
     * Rewrite a full HTML document. Called from the existing output-buffer
     * pipeline, alongside EasyOpt_Unused_CSS and EasyOpt_Fonts.
     *
     * @param string $html
     * @return string
     */
    public static function process_buffer( $html ) {
        if ( ! EasyOpt_Images::enabled() || '' === $html ) {
            return $html;
        }
        if ( ! (int) EasyOpt_Config::get( 'easyopt_images_picture', 1 ) ) {
            return $html;
        }
        if ( ! EasyOpt_Images::target_formats() ) {
            return $html;
        }
        if ( false === stripos( $html, '<img' ) ) {
            return $html;
        }

        // Mask regions where an <img> must survive untouched: existing
        // <picture> blocks (already negotiated), <noscript> (lazyload
        // fallbacks), and <template> (cloned by JS at runtime).
        $masked = self::mask( $html, $stash );

        $masked = preg_replace_callback(
            '#<img\b[^>]*>#i',
            array( __CLASS__, 'rewrite_img' ),
            $masked
        );

        return self::unmask( $masked, $stash );
    }

    /**
     * Wrap one <img> tag when variants exist for it.
     *
     * @param array $m preg match.
     * @return string
     */
    private static function rewrite_img( $m ) {
        $tag = $m[0];

        // data-* driven lazyloaders move the real URL out of src; leave those
        // to the lazyload module rather than fighting it for the same tag.
        if ( preg_match( '/\sdata-(src|srcset|lazy|original)\s*=/i', $tag ) ) {
            return $tag;
        }
        if ( ! preg_match( '/\ssrc\s*=\s*(["\'])(.*?)\1/i', $tag, $src_m ) ) {
            return $tag;
        }

        $src = trim( $src_m[2] );
        if ( '' === $src || 0 === strpos( $src, 'data:' ) ) {
            return $tag;
        }

        // srcset, when present, is what a modern browser actually uses, so
        // the <source> must mirror it rather than only offering the 1x src.
        $srcset = '';
        if ( preg_match( '/\ssrcset\s*=\s*(["\'])(.*?)\1/i', $tag, $ss_m ) ) {
            $srcset = trim( $ss_m[2] );
        }

        $sources = array();
        foreach ( EasyOpt_Images::target_formats() as $format ) {
            $candidate = '' !== $srcset
                ? self::variant_srcset( $srcset, $format )
                : self::variant_srcset( $src, $format );

            if ( '' !== $candidate ) {
                $sources[] = sprintf(
                    '<source type="image/%s" srcset="%s"%s>',
                    esc_attr( $format ),
                    esc_attr( $candidate ),
                    self::sizes_attr( $tag )
                );
            }
        }

        if ( ! $sources ) {
            return $tag;
        }

        return '<picture>' . implode( '', $sources ) . $tag . '</picture>';
    }

    /**
     * Translate a srcset (or a single URL) into the variant equivalent.
     *
     * Returns '' unless EVERY candidate has a variant on disk. A partial
     * srcset would let the browser pick a descriptor that 404s, which is
     * worse than not offering the format at all.
     */
    private static function variant_srcset( $srcset, $format ) {
        $out = array();

        foreach ( explode( ',', $srcset ) as $part ) {
            $part = trim( $part );
            if ( '' === $part ) {
                continue;
            }
            // "url 1024w" or "url 2x" or bare "url".
            $bits       = preg_split( '/\s+/', $part, 2 );
            $url        = $bits[0];
            $descriptor = isset( $bits[1] ) ? ' ' . $bits[1] : '';

            if ( ! self::variant_exists( $url, $format ) ) {
                return '';
            }
            $out[] = $url . '.' . $format . $descriptor;
        }

        return $out ? implode( ', ', $out ) : '';
    }

    /**
     * Does the sibling variant for this URL exist on disk?
     *
     * Only local uploads are considered. An off-site or CDN-rewritten URL
     * has no path we can stat, and guessing would produce 404s.
     */
    private static function variant_exists( $url, $format ) {
        $key = $url . '|' . $format;
        if ( isset( self::$exists[ $key ] ) ) {
            return self::$exists[ $key ];
        }

        $path = self::url_to_path( $url );
        $ok   = ( '' !== $path ) && file_exists( $path . '.' . $format );

        self::$exists[ $key ] = $ok;
        return $ok;
    }

    /**
     * Map an uploads URL to an absolute path, or '' when it is not ours.
     */
    private static function url_to_path( $url ) {
        if ( null === self::$base_url ) {
            $up              = wp_get_upload_dir();
            self::$base_url  = preg_replace( '#^https?:#i', '', trailingslashit( $up['baseurl'] ) );
            self::$base_dir  = trailingslashit( wp_normalize_path( $up['basedir'] ) );
            // Host-less path of the uploads dir, so a root-relative src value
            // (/wp-content/uploads/...) resolves to the same file.
            self::$base_path = '/' . ltrim( (string) preg_replace( '#^//[^/]+#', '', self::$base_url ), '/' );
        }

        $url = preg_replace( '#^https?:#i', '', trim( $url ) );
        $url = strtok( $url, '?' ); // strip cache-busting query strings

        if ( 0 === strpos( $url, self::$base_url ) ) {
            // Absolute or protocol-relative (//host/...) same-site URL.
            $relative = substr( $url, strlen( self::$base_url ) );
        } elseif ( '/' === substr( $url, 0, 1 ) && '/' !== substr( $url, 1, 1 )
            && 0 === strpos( $url, self::$base_path ) ) {
            // Root-relative URL (/wp-content/uploads/...): same file, no host.
            $relative = substr( $url, strlen( self::$base_path ) );
        } else {
            return '';
        }

        $relative = ltrim( rawurldecode( $relative ), '/' );

        // Refuse traversal outright rather than normalising it.
        if ( false !== strpos( $relative, '..' ) ) {
            return '';
        }

        return self::$base_dir . $relative;
    }

    /** Carry the img's sizes attribute onto the source, when present. */
    private static function sizes_attr( $tag ) {
        if ( preg_match( '/\ssizes\s*=\s*(["\'])(.*?)\1/i', $tag, $m ) ) {
            return ' sizes="' . esc_attr( trim( $m[2] ) ) . '"';
        }
        return '';
    }

    /* ── masking ────────────────────────────────────────────── */

    /**
     * Replace regions we must not touch with placeholders.
     *
     * @param string $html
     * @param array  $stash Filled with the removed fragments.
     * @return string
     */
    private static function mask( $html, &$stash ) {
        $stash = array();
        $i     = 0;

        return preg_replace_callback(
            '#<picture\b.*?</picture>|<noscript\b.*?</noscript>|<template\b.*?</template>#is',
            function ( $m ) use ( &$stash, &$i ) {
                $token           = '<!--easyopt-img-' . ( $i++ ) . '-->';
                $stash[ $token ] = $m[0];
                return $token;
            },
            $html
        );
    }

    private static function unmask( $html, $stash ) {
        if ( empty( $stash ) ) {
            return $html;
        }
        return str_replace( array_keys( $stash ), array_values( $stash ), $html );
    }
}
