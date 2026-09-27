<?php
/**
 * Safe wp-config.php constant manager.
 *
 * Adding `define( 'WP_CACHE', true );` is the single switch that tells WordPress
 * to load advanced-cache.php from wp-content/. We keep the editing logic in one
 * place so the rules — placement before the "stop editing!" line, idempotent
 * adds, atomic writes, ABSPATH-or-parent-dir lookup — are guaranteed.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_WP_Config {

    /**
     * Locate wp-config.php. WordPress allows the file to live one directory
     * above ABSPATH for security; we honour the same convention WP core uses
     * in wp-load.php.
     *
     * @return string Absolute path, or '' if not found / not writable.
     */
    public static function locate() {
        $candidates = array(
            ABSPATH . 'wp-config.php',
            dirname( ABSPATH ) . '/wp-config.php',
        );
        // (2.6.2) Hosts that split core from content — Flywheel keeps core in
        // /wordpress and content in /www/wp-content — put wp-config.php beside
        // wp-content, which neither candidate above can reach.
        if ( defined( 'WP_CONTENT_DIR' ) ) {
            $candidates[] = dirname( WP_CONTENT_DIR ) . '/wp-config.php';
        }
        foreach ( $candidates as $path ) {
            if ( file_exists( $path ) ) {
                return $path;
            }
        }
        return '';
    }

    /**
     * Add (or update) `define( 'WP_CACHE', true );`.
     *
     * @return bool True on success, false on any failure (file missing,
     *              not writable, regex parse failure).
     */
    public static function set_wp_cache( $value = true ) {

        $path = self::locate();
        if ( '' === $path || ! is_writable( $path ) ) {
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::error( 'wp-config', 'wp-config.php not found or not writable.' );
            }
            return false;
        }

        $content = (string) @file_get_contents( $path );
        if ( '' === $content ) {
            return false;
        }

        $literal = $value ? 'true' : 'false';
        $line    = "define( 'WP_CACHE', {$literal} ); // Added by Easy Optimizer";

        // Replace existing definition (any quoting / spacing / case).
        $pattern = '/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*[^)]+\)\s*;[^\n]*/i';
        if ( preg_match( $pattern, $content ) ) {
            $new = preg_replace( $pattern, $line, $content, 1 );
            if ( null === $new ) {
                return false;
            }
            return self::write_atomic( $path, $new );
        }

        // Otherwise insert before the "stop editing" marker. Falling back to
        // top of file if the marker isn't there (heavily customised configs).
        $marker = "/* That's all, stop editing!";
        $pos    = strpos( $content, $marker );
        if ( false !== $pos ) {
            $new = substr( $content, 0, $pos ) . $line . "\n\n" . substr( $content, $pos );
        } else {
            // After opening <?php tag.
            $new = preg_replace( '/^(<\?php\s*)/', "$1\n" . $line . "\n", $content, 1 );
            if ( null === $new || $new === $content ) {
                $new = "<?php\n" . $line . "\n?>" . $content;
            }
        }

        return self::write_atomic( $path, $new );
    }

    /**
     * Remove our WP_CACHE definition (only if we placed it). Leaves any
     * pre-existing definition the user wrote themselves alone.
     */
    public static function unset_wp_cache() {

        $path = self::locate();
        if ( '' === $path || ! is_writable( $path ) ) {
            return false;
        }
        $content = (string) @file_get_contents( $path );
        if ( '' === $content ) {
            return false;
        }

        // Only remove lines we annotated.
        $pattern = '/define\s*\(\s*[\'"]WP_CACHE[\'"]\s*,\s*[^)]+\)\s*;\s*\/\/\s*Added by Easy Optimizer\s*\n?/i';
        $new     = preg_replace( $pattern, '', $content );
        if ( null === $new || $new === $content ) {
            return true; // nothing to do, treat as success.
        }
        return self::write_atomic( $path, $new );
    }

    /**
     * Atomic write via temp file + rename. Avoids leaving wp-config.php
     * truncated if PHP is killed mid-write.
     */
    private static function write_atomic( $path, $content ) {

        $dir = dirname( $path );
        if ( ! is_writable( $dir ) ) {
            // Fallback to direct write — better than nothing on hardened hosts.
            return false !== @file_put_contents( $path, $content, LOCK_EX );
        }
        $tmp = @tempnam( $dir, 'eowpc' );
        if ( false === $tmp ) {
            return false !== @file_put_contents( $path, $content, LOCK_EX );
        }
        if ( false === @file_put_contents( $tmp, $content, LOCK_EX ) ) {
            @unlink( $tmp );
            return false;
        }
        // Preserve original perms when possible.
        $perm = @fileperms( $path );
        if ( false !== $perm ) {
            if ( function_exists( 'chmod' ) ) { @chmod( $tmp, $perm & 0777 ); }
        }
        if ( ! @rename( $tmp, $path ) ) {
            @unlink( $tmp );
            return false;
        }
        return true;
    }
}
