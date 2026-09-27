<?php
/**
 * EasyOpt — image encoder capability probe.
 *
 * Everything in the local image pipeline depends on one question: what can
 * THIS host actually encode? The answer is not knowable from PHP version or
 * from function_exists() alone, for two reasons that both bite in practice:
 *
 *  1. GD may be present and report imageavif() while having been compiled
 *     WITHOUT libavif. The function exists and does nothing useful.
 *  2. php.net documents that imageavif() "returns true" even when libgd
 *     fails to output the image. The return value is not a success signal.
 *     A pipeline that trusts it writes zero-byte and corrupt files silently.
 *
 * So we do not ask. We encode a real 8x8 image, write it, stat it, and read
 * its magic bytes back. That is the only answer that cannot lie. It costs
 * microseconds, once, and the result is cached until PHP or the imaging
 * extension versions change.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Images_Capability {

    /** Option holding the cached probe result. */
    const OPTION = 'easyopt_images_capability';

    /** Bump when the probe logic itself changes and old results are stale. */
    const PROBE_VERSION = 1;

    /** Magic byte signatures, keyed by format. */
    const MAGIC = array(
        'webp' => array( 'offset' => 8, 'bytes' => 'WEBP' ),
        'avif' => array( 'offset' => 4, 'bytes' => 'ftyp' ),
        'jpeg' => array( 'offset' => 0, 'bytes' => "\xFF\xD8\xFF" ),
    );

    /** @var array|null Request-level memo. */
    private static $memo = null;

    /**
     * Full capability report. Cached in an option; re-probed automatically
     * when the environment signature changes (PHP upgrade, GD/Imagick
     * rebuild, host migration).
     *
     * @param bool $force Re-probe even if cached.
     * @return array
     */
    public static function get( $force = false ) {
        if ( ! $force && null !== self::$memo ) {
            return self::$memo;
        }

        $cached = get_option( self::OPTION, array() );
        if ( ! $force
            && is_array( $cached )
            && isset( $cached['signature'] )
            && $cached['signature'] === self::environment_signature() ) {
            self::$memo = $cached;
            return $cached;
        }

        $report = self::probe();
        update_option( self::OPTION, $report, false );
        self::$memo = $report;
        return $report;
    }

    /**
     * Identity of the imaging environment. Any change invalidates the probe.
     * Deliberately includes the extension versions, not just their presence:
     * a host rebuilding GD with libavif is exactly the case we must notice.
     */
    private static function environment_signature() {
        $parts = array(
            'p' . self::PROBE_VERSION,
            PHP_VERSION,
            extension_loaded( 'gd' ) ? (string) phpversion( 'gd' ) : '-',
            extension_loaded( 'imagick' ) ? (string) phpversion( 'imagick' ) : '-',
        );
        return md5( implode( '|', $parts ) );
    }

    /**
     * Run every probe. Never throws — a host that fatals on one encoder must
     * still get a usable report for the others.
     */
    private static function probe() {
        $report = array(
            'signature'      => self::environment_signature(),
            'probed_at'      => time(),
            'php'            => PHP_VERSION,
            'imagick'        => false,
            'imagick_webp'   => false,
            'imagick_avif'   => false,
            'gd'             => false,
            'gd_webp'        => false,
            'gd_avif'        => false,
            'exec'           => false,
            'exec_cwebp'     => '',
            'exec_avifenc'   => '',
            'memory_limit'   => self::memory_limit_bytes(),
            'max_execution'  => (int) ini_get( 'max_execution_time' ),
            'notes'          => array(),
        );

        /* ── Imagick ────────────────────────────────────────── */
        if ( class_exists( 'Imagick' ) ) {
            $report['imagick'] = true;
            try {
                $formats = array_map( 'strtoupper', (array) Imagick::queryFormats() );
                // queryFormats() is a delegate list, not proof of a working
                // encode path, so both are still round-trip tested below.
                if ( in_array( 'WEBP', $formats, true ) ) {
                    $report['imagick_webp'] = self::test_imagick( 'webp' );
                }
                if ( in_array( 'AVIF', $formats, true ) ) {
                    $report['imagick_avif'] = self::test_imagick( 'avif' );
                }
            } catch ( Exception $e ) {
                $report['notes'][] = 'imagick: ' . $e->getMessage();
            } catch ( Error $e ) {
                $report['notes'][] = 'imagick: ' . $e->getMessage();
            }
        }

        /* ── GD ─────────────────────────────────────────────── */
        if ( extension_loaded( 'gd' ) && function_exists( 'imagecreatetruecolor' ) ) {
            $report['gd'] = true;
            if ( function_exists( 'imagewebp' ) ) {
                $report['gd_webp'] = self::test_gd( 'webp' );
            }
            // imageavif() is PHP 8.1+ AND needs GD built against libavif.
            if ( function_exists( 'imageavif' ) ) {
                $report['gd_avif'] = self::test_gd( 'avif' );
                if ( ! $report['gd_avif'] ) {
                    $report['notes'][] = 'gd: imageavif() exists but produced no valid AVIF (GD likely built without libavif).';
                }
            }
        }

        /* ── exec() binaries ────────────────────────────────── */
        if ( self::exec_available() ) {
            $report['exec']         = true;
            $report['exec_cwebp']   = self::which( 'cwebp' );
            $report['exec_avifenc'] = self::which( 'avifenc' );
        }

        return $report;
    }

    /**
     * Encode an 8x8 image through Imagick and verify the bytes that come out.
     */
    private static function test_imagick( $format ) {
        try {
            $im = new Imagick();
            $im->newImage( 8, 8, new ImagickPixel( 'red' ) );
            $im->setImageFormat( $format );
            if ( 'webp' === $format || 'avif' === $format ) {
                $im->setImageCompressionQuality( 80 );
            }
            $blob = $im->getImageBlob();
            $im->clear();
            $im->destroy();
            return self::valid_magic( $blob, $format );
        } catch ( Exception $e ) {
            return false;
        } catch ( Error $e ) {
            return false;
        }
    }

    /**
     * Encode an 8x8 image through GD to a temp file and verify the bytes.
     *
     * Writes to a real file rather than an output buffer because that is the
     * path the pipeline actually uses, and because imageavif()'s failure mode
     * is specifically a bad/empty FILE with a true return value.
     */
    private static function test_gd( $format ) {
        // The probe runs lazily wherever available_formats() is first called
        // with a cold cache — WP-Cron and the REST loopback that drive the
        // optimize queue, and even a front-end pageview through
        // EasyOpt_Images_Delivery::target_formats(). wp_tempnam() lives in
        // wp-admin/includes/file.php, which none of those contexts load, so
        // pull it in on demand or the probe fatals the entire request.
        if ( ! function_exists( 'wp_tempnam' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tmp = wp_tempnam( 'easyopt-probe' );
        if ( ! $tmp ) {
            return false;
        }

        $ok = false;
        try {
            $img = imagecreatetruecolor( 8, 8 );
            if ( ! $img ) {
                return false;
            }
            imagefilledrectangle( $img, 0, 0, 7, 7, imagecolorallocate( $img, 255, 0, 0 ) );

            if ( 'webp' === $format ) {
                imagewebp( $img, $tmp, 80 );
            } else {
                // quality 52 is GD's own default; speed 6 likewise.
                imageavif( $img, $tmp, 52, 6 );
            }
            imagedestroy( $img );

            // The return value above is deliberately ignored — see class docblock.
            if ( file_exists( $tmp ) && filesize( $tmp ) > 0 ) {
                $ok = self::valid_magic( (string) file_get_contents( $tmp ), $format );
            }
        } catch ( Exception $e ) {
            $ok = false;
        } catch ( Error $e ) {
            $ok = false;
        }

        if ( file_exists( $tmp ) ) {
            @unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        return $ok;
    }

    /**
     * Verify a byte string really is the format we asked for.
     *
     * This is the single most load-bearing function in the local pipeline.
     * Every write path calls it before declaring success.
     *
     * @param string $bytes  Raw file contents.
     * @param string $format webp|avif|jpeg
     */
    public static function valid_magic( $bytes, $format ) {
        if ( ! isset( self::MAGIC[ $format ] ) ) {
            return false;
        }
        $sig = self::MAGIC[ $format ];
        $len = strlen( $sig['bytes'] );
        if ( strlen( $bytes ) < $sig['offset'] + $len ) {
            return false;
        }
        return substr( $bytes, $sig['offset'], $len ) === $sig['bytes'];
    }

    /** Verify an on-disk file. Cheap: reads only the header. */
    public static function valid_file( $path, $format ) {
        if ( ! is_readable( $path ) ) {
            return false;
        }
        $size = filesize( $path );
        if ( ! $size ) {
            return false;
        }
        $fh = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( ! $fh ) {
            return false;
        }
        $head = fread( $fh, 16 );
        fclose( $fh );
        return self::valid_magic( (string) $head, $format );
    }

    /**
     * Best available encoder for a format, or '' when the host cannot do it.
     * Imagick first: better resampling and colour handling than GD.
     *
     * @param string $format webp|avif
     * @return string 'imagick'|'gd'|'exec'|''
     */
    public static function encoder_for( $format ) {
        $c = self::get();
        if ( 'webp' === $format ) {
            if ( $c['imagick_webp'] ) { return 'imagick'; }
            if ( $c['gd_webp'] ) { return 'gd'; }
            if ( $c['exec_cwebp'] ) { return 'exec'; }
            return '';
        }
        if ( 'avif' === $format ) {
            if ( $c['imagick_avif'] ) { return 'imagick'; }
            if ( $c['gd_avif'] ) { return 'gd'; }
            if ( $c['exec_avifenc'] ) { return 'exec'; }
            return '';
        }
        return '';
    }

    /**
     * The formats this host can actually produce, best first.
     * Empty array means the honest answer is "this host cannot do modern
     * formats" — which the UI states plainly rather than hiding.
     */
    public static function available_formats() {
        $out = array();
        if ( self::encoder_for( 'avif' ) ) { $out[] = 'avif'; }
        if ( self::encoder_for( 'webp' ) ) { $out[] = 'webp'; }
        return $out;
    }

    /**
     * One-line human summary for the settings screen. Names the missing
     * capability specifically — "your host lacks X" converts better than a
     * generic failure, and is genuinely more actionable.
     */
    public static function summary() {
        $formats = self::available_formats();
        if ( ! $formats ) {
            return __( 'This host cannot encode WebP or AVIF. Images will be losslessly re-compressed only.', 'easy-optimizer' );
        }
        if ( ! in_array( 'avif', $formats, true ) ) {
            return __( 'WebP is available. AVIF is not — this host\'s image library was built without AVIF support.', 'easy-optimizer' );
        }
        return __( 'AVIF and WebP are both available on this host.', 'easy-optimizer' );
    }

    /* ── helpers ────────────────────────────────────────────── */

    /** memory_limit in bytes; PHP_INT_MAX when unlimited. */
    public static function memory_limit_bytes() {
        $raw = trim( (string) ini_get( 'memory_limit' ) );
        if ( '' === $raw || '-1' === $raw ) {
            return PHP_INT_MAX;
        }
        $unit  = strtolower( substr( $raw, -1 ) );
        $value = (int) $raw;
        switch ( $unit ) {
            case 'g': return $value * 1024 * 1024 * 1024;
            case 'm': return $value * 1024 * 1024;
            case 'k': return $value * 1024;
        }
        return $value;
    }

    /** Is exec() usable, or has the host disabled it? */
    private static function exec_available() {
        if ( ! function_exists( 'exec' ) ) {
            return false;
        }
        $disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
        if ( in_array( 'exec', $disabled, true ) ) {
            return false;
        }
        return ! ( function_exists( 'ini_get' ) && filter_var( ini_get( 'safe_mode' ), FILTER_VALIDATE_BOOLEAN ) );
    }

    /** Locate a binary on PATH. Returns '' when absent. */
    private static function which( $binary ) {
        if ( ! self::exec_available() ) {
            return '';
        }
        $binary = preg_replace( '/[^a-z0-9_-]/i', '', $binary );
        if ( '' === $binary ) {
            return '';
        }
        $cmd = ( 0 === stripos( PHP_OS, 'WIN' ) ? 'where ' : 'command -v ' ) . escapeshellarg( $binary );
        $out = array();
        $rc  = 0;
        @exec( $cmd . ' 2>' . ( 0 === stripos( PHP_OS, 'WIN' ) ? 'NUL' : '/dev/null' ), $out, $rc ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        if ( 0 !== $rc || empty( $out[0] ) ) {
            return '';
        }
        $path = trim( (string) $out[0] );
        return is_executable( $path ) ? $path : '';
    }
}
