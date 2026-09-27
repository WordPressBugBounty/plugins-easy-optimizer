<?php
/**
 * EasyOpt — server-side image encoder.
 *
 * This is the pipeline that runs wherever WordPress core's client-side
 * processing cannot: Firefox and Safari uploads, every install below 7.1,
 * WP-CLI, migrations, WooCommerce imports, media_sideload_image(), headless
 * REST, and every image that was already in the library before we arrived.
 *
 * That is the majority of the work, not an edge case. Core's in-browser
 * encoder needs BOTH WordPress 7.1+ AND a Chromium browser; together those
 * cover well under half of real installs. Treat this class as the primary
 * engine and the client-side path as an optimisation on top of it.
 *
 * Two rules that everything here obeys:
 *
 *   1. NEVER trust an encoder's return value. GD's imageavif() returns true
 *      even when libgd failed to write the file. Every write is verified by
 *      reading the magic bytes back off disk.
 *   2. NEVER decode blind. A 12000x8000 PNG needs ~384 MB as a GD truecolor
 *      bitmap and will fatal a 256 MB host mid-batch, taking the whole queue
 *      run with it. Pixel cost is computed and refused BEFORE opening.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Images_Encoder {

    /** Bytes per pixel assumed for an in-memory truecolor bitmap. */
    const BYTES_PER_PIXEL = 4;

    /** Refuse a decode needing more than this share of remaining memory. */
    const MEMORY_SAFETY_FACTOR = 0.6;

    /** Sources we will read. Anything else is left alone. */
    const SOURCE_MIME = array( 'image/jpeg', 'image/png', 'image/gif' );

    /**
     * Encode one source file into one target format.
     *
     * Writes a SIBLING file and never touches the source. The caller decides
     * whether the result is worth keeping.
     *
     * @param string $source  Absolute path to the source image.
     * @param string $dest    Absolute path to write.
     * @param string $format  'webp'|'avif'
     * @param int    $quality 1-100.
     * @param int    $max_dim Longest-edge cap in px; 0 = no resize.
     * @return array|WP_Error { bytes, width, height, encoder }
     */
    public static function encode( $source, $dest, $format, $quality, $max_dim = 0 ) {
        if ( ! in_array( $format, array( 'webp', 'avif' ), true ) ) {
            return new WP_Error( 'bad_format', 'Unsupported target format.' );
        }
        if ( ! is_readable( $source ) ) {
            return new WP_Error( 'unreadable', 'Source image is not readable.' );
        }

        $info = self::inspect( $source );
        if ( is_wp_error( $info ) ) {
            return $info;
        }

        // Animated GIFs are left untouched. Neither GD nor a plain Imagick
        // write coalesces frames, so "optimizing" one yields a single-frame
        // still — which the delivery layer then serves as the PREFERRED source,
        // silently dropping the animation. Skip it; the original keeps moving.
        if ( 'image/gif' === $info['mime'] && self::is_animated_gif( $source ) ) {
            return new WP_Error( 'animated_gif', 'Animated GIFs are left as-is; converting would drop the animation.' );
        }

        $budget = self::memory_check( $info['width'], $info['height'] );
        if ( is_wp_error( $budget ) ) {
            return $budget;
        }

        $encoder = EasyOpt_Images_Capability::encoder_for( $format );
        if ( '' === $encoder ) {
            return new WP_Error( 'no_encoder', 'This host cannot encode ' . strtoupper( $format ) . '.' );
        }

        $dir = dirname( $dest );
        if ( ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'mkdir_failed', 'Could not create the destination directory.' );
        }

        $quality = max( 1, min( 100, (int) $quality ) );

        switch ( $encoder ) {
            case 'imagick':
                $result = self::encode_imagick( $source, $dest, $format, $quality, $max_dim );
                break;
            case 'gd':
                $result = self::encode_gd( $source, $dest, $format, $quality, $max_dim, $info );
                break;
            default:
                $result = self::encode_exec( $source, $dest, $format, $quality );
                break;
        }

        if ( is_wp_error( $result ) ) {
            self::cleanup( $dest );
            return $result;
        }

        // Rule 1. Nothing below this line trusts the encoder.
        if ( ! EasyOpt_Images_Capability::valid_file( $dest, $format ) ) {
            self::cleanup( $dest );
            return new WP_Error(
                'invalid_output',
                'The encoder reported success but produced a file that is not valid ' . strtoupper( $format ) . '.'
            );
        }

        $bytes = (int) filesize( $dest );
        if ( $bytes <= 0 ) {
            self::cleanup( $dest );
            return new WP_Error( 'empty_output', 'The encoder produced an empty file.' );
        }

        return array(
            'bytes'   => $bytes,
            'width'   => isset( $result['width'] ) ? (int) $result['width'] : $info['width'],
            'height'  => isset( $result['height'] ) ? (int) $result['height'] : $info['height'],
            'encoder' => $encoder,
        );
    }

    /**
     * Source dimensions and MIME, without decoding the pixel data.
     */
    public static function inspect( $path ) {
        $size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
            return new WP_Error( 'not_an_image', 'Could not read image dimensions.' );
        }
        $mime = isset( $size['mime'] ) ? (string) $size['mime'] : '';
        if ( ! in_array( $mime, self::SOURCE_MIME, true ) ) {
            return new WP_Error( 'unsupported_source', 'Unsupported source type: ' . $mime );
        }
        return array(
            'width'  => (int) $size[0],
            'height' => (int) $size[1],
            'mime'   => $mime,
        );
    }

    /**
     * Is this GIF animated? getimagesize() cannot tell — it only reports the
     * first frame. Count Graphic Control Extension blocks that precede a frame:
     * more than one means animation. Reads at most the first 2 MB, which is far
     * more than the first two frames of any real GIF occupy.
     */
    public static function is_animated_gif( $path ) {
        if ( ! is_readable( $path ) ) {
            return false;
        }
        $data = (string) @file_get_contents( $path, false, null, 0, 2 * 1024 * 1024 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( '' === $data ) {
            return false;
        }
        // GCE introducer (00 21 F9 04) + 4-byte body + terminator (00) + the
        // image-descriptor / extension introducer (2C or 21). Two or more of
        // these is an animation. This is the widely-used GIF-frame heuristic.
        return preg_match_all( '#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $data ) > 1;
    }

    /**
     * Rule 2. Would decoding this image survive the remaining memory budget?
     *
     * Deliberately conservative: it is far better to skip one enormous image
     * and log why than to fatal a queue runner that was halfway through a
     * batch of fifty.
     */
    public static function memory_check( $width, $height ) {
        $limit = EasyOpt_Images_Capability::memory_limit_bytes();
        if ( PHP_INT_MAX === $limit ) {
            return true;
        }
        $needed    = (int) $width * (int) $height * self::BYTES_PER_PIXEL;
        $used      = function_exists( 'memory_get_usage' ) ? memory_get_usage( true ) : 0;
        $remaining = max( 0, $limit - $used );

        if ( $needed > $remaining * self::MEMORY_SAFETY_FACTOR ) {
            return new WP_Error(
                'too_large',
                sprintf(
                    'Image needs ~%s to decode; only %s of PHP memory is available.',
                    size_format( $needed ),
                    size_format( (int) ( $remaining * self::MEMORY_SAFETY_FACTOR ) )
                )
            );
        }
        return true;
    }

    /* ── Imagick ────────────────────────────────────────────── */

    private static function encode_imagick( $source, $dest, $format, $quality, $max_dim ) {
        try {
            $im = new Imagick();
            $im->readImage( $source );
            $im->setImageFormat( $format );

            // Strip metadata but keep the colour profile — dropping ICC is
            // what makes "optimized" images look washed out.
            $profiles = $im->getImageProfiles( 'icc', true );
            $im->stripImage();
            if ( ! empty( $profiles['icc'] ) ) {
                $im->profileImage( 'icc', $profiles['icc'] );
            }

            if ( $max_dim > 0 ) {
                $w = $im->getImageWidth();
                $h = $im->getImageHeight();
                if ( max( $w, $h ) > $max_dim ) {
                    $im->resizeImage(
                        $w >= $h ? $max_dim : 0,
                        $w >= $h ? 0 : $max_dim,
                        Imagick::FILTER_LANCZOS,
                        1
                    );
                }
            }

            $im->setImageCompressionQuality( $quality );
            if ( 'avif' === $format && method_exists( $im, 'setOption' ) ) {
                // Effort 4 is a deliberate middle: AVIF encode time rises
                // steeply past this with little size gain, and bulk runs on
                // shared hosting are time-boxed.
                $im->setOption( 'heic:speed', '4' );
            }

            $w = $im->getImageWidth();
            $h = $im->getImageHeight();

            $written = $im->writeImage( $dest );
            $im->clear();
            $im->destroy();

            if ( ! $written ) {
                return new WP_Error( 'imagick_write_failed', 'Imagick could not write the output file.' );
            }
            return array( 'width' => $w, 'height' => $h );
        } catch ( Exception $e ) {
            return new WP_Error( 'imagick_error', $e->getMessage() );
        } catch ( Error $e ) {
            return new WP_Error( 'imagick_error', $e->getMessage() );
        }
    }

    /* ── GD ─────────────────────────────────────────────────── */

    private static function encode_gd( $source, $dest, $format, $quality, $max_dim, $info ) {
        $img = self::gd_open( $source, $info['mime'] );
        if ( is_wp_error( $img ) ) {
            return $img;
        }

        // Palette sources (GIF, palette PNG) must be promoted to truecolor:
        // imageavif() refuses a palette image outright ("avif doesn't support
        // palette images") and imagewebp() can mishandle its alpha. GD hands
        // back a palette bitmap for both, so convert before encoding.
        if ( function_exists( 'imageistruecolor' ) && ! imageistruecolor( $img )
            && function_exists( 'imagepalettetotruecolor' ) ) {
            imagepalettetotruecolor( $img );
        }

        $w = imagesx( $img );
        $h = imagesy( $img );

        if ( $max_dim > 0 && max( $w, $h ) > $max_dim ) {
            $ratio = $max_dim / max( $w, $h );
            $nw    = max( 1, (int) round( $w * $ratio ) );
            $nh    = max( 1, (int) round( $h * $ratio ) );

            $resized = imagecreatetruecolor( $nw, $nh );
            if ( $resized ) {
                imagealphablending( $resized, false );
                imagesavealpha( $resized, true );
                imagecopyresampled( $resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h );
                imagedestroy( $img );
                $img = $resized;
                $w   = $nw;
                $h   = $nh;
            }
        }

        imagealphablending( $img, false );
        imagesavealpha( $img, true );

        if ( 'webp' === $format ) {
            imagewebp( $img, $dest, $quality );
        } else {
            // GD maps AVIF speed 0-10 (10 = fastest/largest). 6 is its default.
            imageavif( $img, $dest, $quality, 6 );
        }
        imagedestroy( $img );

        // Return value intentionally discarded — see class docblock rule 1.
        // Validation happens in encode().
        return array( 'width' => $w, 'height' => $h );
    }

    private static function gd_open( $path, $mime ) {
        switch ( $mime ) {
            case 'image/jpeg':
                $img = @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                break;
            case 'image/png':
                $img = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                break;
            case 'image/gif':
                $img = @imagecreatefromgif( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                break;
            default:
                return new WP_Error( 'unsupported_source', 'GD cannot open ' . $mime );
        }
        if ( ! $img ) {
            return new WP_Error( 'gd_open_failed', 'GD could not decode the source image.' );
        }
        return $img;
    }

    /* ── exec() binaries ────────────────────────────────────── */

    private static function encode_exec( $source, $dest, $format, $quality ) {
        $cap = EasyOpt_Images_Capability::get();
        $bin = 'webp' === $format ? $cap['exec_cwebp'] : $cap['exec_avifenc'];
        if ( ! $bin ) {
            return new WP_Error( 'no_binary', 'No encoder binary available.' );
        }

        if ( 'webp' === $format ) {
            $cmd = sprintf(
                '%s -quiet -q %d %s -o %s',
                escapeshellcmd( $bin ),
                $quality,
                escapeshellarg( $source ),
                escapeshellarg( $dest )
            );
        } else {
            // avifenc takes a 0-63 quantizer where LOWER is better quality,
            // the inverse of every other scale here.
            $qmin = (int) round( ( 100 - $quality ) * 0.63 );
            $cmd  = sprintf(
                '%s --min %d --max %d --speed 6 %s %s',
                escapeshellcmd( $bin ),
                max( 0, $qmin - 5 ),
                min( 63, $qmin + 5 ),
                escapeshellarg( $source ),
                escapeshellarg( $dest )
            );
        }

        $out = array();
        $rc  = 0;
        @exec( $cmd . ' 2>&1', $out, $rc ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

        if ( 0 !== $rc ) {
            return new WP_Error( 'exec_failed', 'Encoder exited ' . $rc . ': ' . implode( ' ', array_slice( $out, 0, 3 ) ) );
        }
        return array();
    }

    /* ── misc ───────────────────────────────────────────────── */

    private static function cleanup( $path ) {
        if ( $path && file_exists( $path ) ) {
            @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
    }
}
