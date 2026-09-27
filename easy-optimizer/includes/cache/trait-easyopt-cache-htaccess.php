<?php
/**
 * EasyOpt_Cache_Htaccess_Trait — .htaccess generation, probing & reconcile.
 *
 * Extracted from class-easyopt-cache.php (2.3.3) purely for maintainability.
 * Traits are flattened into the class at compile time, so this split has
 * ZERO runtime cost and the public EasyOpt_Cache API is unchanged.
 *
 * Contains: reconcile/write/remove .htaccess, sandbox + live probes,
 * rule-section builders, server detection (Apache / OLS) and default mode.
 *
 * @package EasyOptimizer
 * @since   2.3.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait EasyOpt_Cache_Htaccess_Trait {

    /* ───────────────────────────────────────────────
     *  Server-mode .htaccess generation
     * ─────────────────────────────────────────────── */

    /**
     * Make sure the .htaccess state on disk matches what the current options
     * say it should be. Throttled to once every 5 minutes per request via
     * a short-lived transient so we don't read the site .htaccess on every
     * page load — only the first one after a cache flush or option change.
     */
    /**
     * Whether .htaccess SERVE mode is safe on this install. Two hard blocks:
     *
     *  1. Include-cookies registered (currency/language switchers): the
     *     rewrite rules can't key the file by cookie VALUE, so Apache would
     *     hand a switched shopper the default-currency page — wrong prices
     *     at the edge (B1, 2.3.3). Drop-in/PHP serving keys correctly.
     *  2. Multisite: the site-root .htaccess is one shared file; whichever
     *     subsite saved last would own the rewrite for the whole network.
     *
     * Gzip and browser-cache blocks are unaffected (they're host-global and
     * identical for every site/visitor) — only the page-cache REWRITE is
     * gated. Falls back to drop-in/PHP serving, which is fully functional.
     *
     * @return bool
     */
    public static function htaccess_rewrite_allowed() {
        if ( is_multisite() ) {
            return false;
        }
        if ( self::has_include_cookies() ) {
            return false;
        }
        return (bool) apply_filters( 'easyopt_htaccess_rewrite_allowed', true );
    }

    public static function reconcile_htaccess() {

        // Throttle: don't re-check more than once per 5 minutes per worker.
        // (Cleared automatically on option update via our updated_option hook.)
        if ( false !== get_transient( 'easyopt_htaccess_synced' ) ) {
            return;
        }

        if ( '' === self::$root_path ) {
            self::setup_paths();
        }

        $mode = EasyOpt_Config::get( 'cache_mode', self::default_mode() );

        // Cache-dir .htaccess: should always exist when cache is on.
        $cache_htaccess = self::$root_path . '.htaccess';
        $cache_dir_ok   = file_exists( $cache_htaccess ) && filesize( $cache_htaccess ) > 0;

        // Upgrade signature — old cache-dir .htaccess used
        // `ExpiresByType text/html "access plus 1 hour"` and
        // `Vary "Accept-Encoding, User-Agent"`. Force a rewrite when we
        // detect either of those.
        if ( $cache_dir_ok ) {
            $cache_content = (string) @file_get_contents( $cache_htaccess );
            if ( false !== strpos( $cache_content, 'ExpiresByType text/html' )
              || false !== strpos( $cache_content, 'Accept-Encoding, User-Agent' ) ) {
                $cache_dir_ok = false;
            }
        }

        // Site-root .htaccess state.
        $site_htaccess = ABSPATH . '.htaccess';
        $site_content  = file_exists( $site_htaccess ) ? (string) @file_get_contents( $site_htaccess ) : '';

        $has_block = (bool) preg_match( '/# BEGIN Easy Optimizer\s*\n/', $site_content );

        // 2.1.1+: consolidated block uses section comments to indicate presence.
        // Pre-2.1.1: separate blocks with their own BEGIN/END markers.
        $has_rewrite       = $has_block && false !== strpos( $site_content, '# ── Page Cache Rewrite' );
        $has_gzip          = ( $has_block && false !== strpos( $site_content, '# ── Gzip Compression' ) )
                          || false !== strpos( $site_content, '# BEGIN Easy Optimizer Gzip' );
        $has_browser_cache = ( $has_block && false !== strpos( $site_content, '# ── Browser Cache' ) )
                          || false !== strpos( $site_content, '# BEGIN Easy Optimizer Browser Cache' );

        // Also detect old pre-consolidated rewrite blocks.
        if ( ! $has_rewrite && $has_block ) {
            // Pre-2.1.1 single rewrite block without section comments.
            $has_rewrite = false !== strpos( $site_content, 'EASYOPT_ENC' );
        }

        $expected_rewrite       = ( 'htaccess' === $mode ) && self::htaccess_rewrite_allowed();
        $expected_gzip          = (bool) (int) EasyOpt_Config::get( 'cache_gzip', 1 )
                                  && self::should_emit_gzip_block()
                                  && ! self::is_openlitespeed()
                                  && ! get_option( 'easyopt_htaccess_gzip_unsupported' );
        $expected_browser_cache = (bool) (int) EasyOpt_Config::get( 'cache_browser_caching', 1 );

        // Detect stale rules that need refreshing (pre-1.4.8 missing wordpress_sec_).
        $stale_site_rewrite = false;
        if ( $has_rewrite && false === strpos( $site_content, 'wordpress_sec_' ) ) {
            $stale_site_rewrite = true;
        }

        // Detect pre-2.1.1 multi-block format — needs upgrade to consolidated.
        $needs_consolidation = false !== strpos( $site_content, '# BEGIN Easy Optimizer Gzip' )
                            || false !== strpos( $site_content, '# BEGIN Easy Optimizer Browser Cache' );

        if ( $cache_dir_ok
             && ! $stale_site_rewrite
             && ! $needs_consolidation
             && $has_rewrite       === $expected_rewrite
             && $has_gzip          === $expected_gzip
             && $has_browser_cache === $expected_browser_cache
        ) {
            // State matches. Skip future checks for 5 minutes.
            set_transient( 'easyopt_htaccess_synced', 1, 5 * MINUTE_IN_SECONDS );
            return;
        }

        // Sync.
        self::write_htaccess();
        set_transient( 'easyopt_htaccess_synced', 1, 5 * MINUTE_IN_SECONDS );
    }

    /**
     * Write the cache directory's own .htaccess (always). When cache options
     * call for it, ALSO update the site-root .htaccess with three optional
     * blocks (cache-rewrite, gzip, browser-cache) — each toggled independently.
     */
    public static function write_htaccess() {

        // EasyOpt_Advanced_Cache::install(): on a save that flips multiple
        // cache-affecting settings, this function can be invoked several
        // times in one request. The work (template rebuild + file write
        // + 2-second loopback probe) is identical each time. Guard with
        // a static flag — subsequent calls in the same request are free.
        static $already_ran = false;
        if ( $already_ran ) {
            return true;
        }

        if ( '' === self::$root_path ) {
            self::setup_paths();
        }
        if ( ! is_dir( self::$root_path ) ) {
            wp_mkdir_p( self::$root_path );
        }
        if ( ! is_dir( self::$root_path ) ) {
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::error( 'htaccess', 'Cache directory does not exist and could not be created: ' . self::$root_path );
            }
            return false;
        }

        // Only Apache and Apache-compatible servers (LiteSpeed Enterprise,
        // OpenLiteSpeed) interpret .htaccess. On nginx, IIS, Caddy etc. the
        // file is silently ignored at best — at worst it confuses ops who
        // see the file and assume it's doing something. Skip entirely.
        if ( ! self::server_supports_htaccess() ) {
            // Strip any prior block we may have left behind on a server change.
            self::strip_root_htaccess_block();
            return false;
        }

        // ── Per-server capability detection (sandbox probes) ────────────
        // Test the two rule classes that can 500 on a per-server basis BEFORE
        // anything touches the live root file: the Gzip directives (rejected
        // by some LiteSpeed/OpenLiteSpeed parsers) and `Options -Indexes`
        // (rejected where AllowOverride forbids Options). Each is probed in an
        // isolated throwaway dir; failures are simply omitted. The page-cache
        // rewrite and the Expires block are still emitted normally and remain
        // covered by the final live probe + rollback at the end of this method.
        $is_ols = self::is_openlitespeed();
        $mode   = EasyOpt_Config::get( 'cache_mode', self::default_mode() );

        // Gzip: skipped entirely on OpenLiteSpeed (it compresses natively and
        // its parser rejects some directives). Otherwise sandbox-tested.
        $want_gzip    = (int) EasyOpt_Config::get( 'cache_gzip', 1 ) && self::should_emit_gzip_block() && ! $is_ols;
        $gzip_section = $want_gzip ? self::build_gzip_section() : '';
        $emit_gzip    = ( '' !== $gzip_section ) && self::probe_rule_class( 'gzip', $gzip_section );

        if ( $want_gzip && ! $emit_gzip ) {
            update_option( 'easyopt_htaccess_gzip_unsupported', 1, false );
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::warn( 'htaccess', 'Gzip block failed its sandbox probe on this server — omitted to avoid a 500. Compression left to the server/CDN.' );
            }
        } else {
            delete_option( 'easyopt_htaccess_gzip_unsupported' );
        }
        if ( $is_ols && class_exists( 'EasyOpt_Debug_Log' ) ) {
            EasyOpt_Debug_Log::info( 'htaccess', 'OpenLiteSpeed detected — Gzip block skipped (server compresses natively).' );
        }

        // `Options -Indexes`: gated on its own probe; the result is consumed by
        // build_cache_dir_htaccess() below.
        update_option(
            'easyopt_htaccess_options_ok',
            self::probe_rule_class( 'options', "<IfModule mod_autoindex.c>\n  Options -Indexes\n</IfModule>\n" ) ? 1 : 0,
            false
        );

        // ── Cache directory's own .htaccess ─────────────────────────────
        $cache_dir_rules = self::build_cache_dir_htaccess();
        @file_put_contents( self::$root_path . '.htaccess', $cache_dir_rules, LOCK_EX );

        // Universal directory-listing safeguard: a blank index.html works even
        // where `Options -Indexes` is forbidden by AllowOverride.
        $cache_index = self::$root_path . 'index.html';
        if ( ! file_exists( $cache_index ) ) {
            @file_put_contents( $cache_index, '', LOCK_EX );
        }

        // ── Site-root .htaccess composition ─────────────────────────────
        $site_htaccess = ABSPATH . '.htaccess';

        // Try to create the file if it doesn't exist (some new installs).
        if ( ! file_exists( $site_htaccess ) ) {
            @file_put_contents( $site_htaccess, "", LOCK_EX );
        }
        if ( ! file_exists( $site_htaccess ) || ! is_writable( $site_htaccess ) ) {
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::error( 'htaccess', 'Site .htaccess is not writable: ' . $site_htaccess );
            }
            return false;
        }

        // ── Exclusive file lock around the ENTIRE read-modify-write ─────
        // Prevents concurrent settings saves from racing on the same file.
        $fp = @fopen( $site_htaccess, 'c+' );
        if ( false === $fp ) {
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::error( 'htaccess', 'Could not open .htaccess for locked read-modify-write.' );
            }
            return false;
        }

        if ( ! flock( $fp, LOCK_EX ) ) {
            fclose( $fp );
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::warn( 'htaccess', 'Could not acquire exclusive lock on .htaccess — skipping write.' );
            }
            return false;
        }

        // Read current content while we hold the lock.
        $existing = stream_get_contents( $fp );
        if ( false === $existing ) {
            $existing = '';
        }

        // Keep backup for rollback.
        $backup = $existing;

        // Strip any prior blocks we wrote — handles BOTH old multi-block
        // format (pre-2.1.1) and new single-block format.
        $existing = self::strip_easyopt_blocks( $existing );

        // ── Build a SINGLE consolidated block ───────────────────────────
        // $mode, $gzip_section and $emit_gzip were computed above during
        // capability detection.
        $inner = '';

        // 1) Page-cache rewrite (only in htaccess serving mode, and only
        //    when safe — see htaccess_rewrite_allowed()). When gated, the
        //    request transparently falls through to the drop-in/PHP serve
        //    path, which keys cache variants correctly.
        if ( 'htaccess' === $mode ) {
            if ( self::htaccess_rewrite_allowed() ) {
                $inner .= self::build_rewrite_section();
            } elseif ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::info( 'htaccess', is_multisite()
                    ? 'Rewrite serve mode skipped on multisite (shared root .htaccess) — serving via drop-in/PHP instead.'
                    : 'Rewrite serve mode skipped: a cookie-keyed cache variant (e.g. currency switcher) is registered and Apache rewrites cannot key by cookie value — serving via drop-in/PHP so visitors always get the correct variant.' );
            }
        }

        // 2) Gzip — emitted only if its sandbox probe passed (and not on OLS).
        if ( $emit_gzip ) {
            $inner .= $gzip_section;
        }

        // 3) Browser-cache / expires headers — independent toggle. Low-risk and
        //    IfModule-guarded; emitted normally and covered by the final probe.
        if ( (int) EasyOpt_Config::get( 'cache_browser_caching', 1 ) ) {
            $inner .= self::build_browser_cache_section();
        }

        // Wrap in a single BEGIN/END pair.
        $block = '';
        if ( '' !== $inner ) {
            $block = "# BEGIN Easy Optimizer\n" . $inner . "# END Easy Optimizer\n\n";
        }

        // Prepend our block so it runs BEFORE WordPress' own rewrites.
        $new = $block . ltrim( $existing );

        // ── Atomic write via temp file + rename ─────────────────────────
        $write_ok = self::write_htaccess_atomic( $site_htaccess, $new );

        if ( ! $write_ok ) {
            // Atomic failed — try direct write as fallback.
            ftruncate( $fp, 0 );
            rewind( $fp );
            $written = fwrite( $fp, $new );
            fflush( $fp );

            if ( false === $written || $written !== strlen( $new ) ) {
                // Total write failure — restore backup.
                ftruncate( $fp, 0 );
                rewind( $fp );
                fwrite( $fp, $backup );
                fflush( $fp );
                flock( $fp, LOCK_UN );
                fclose( $fp );
                if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                    EasyOpt_Debug_Log::error( 'htaccess', 'Failed to write .htaccess — partial write detected. Restored backup.' );
                }
                return false;
            }
        }

        // Release the file lock.
        flock( $fp, LOCK_UN );
        fclose( $fp );

        // ── Size verification — catch silent partial writes ─────────────
        clearstatcache( true, $site_htaccess );
        $on_disk = @filesize( $site_htaccess );
        if ( false !== $on_disk && $on_disk !== strlen( $new ) ) {
            // Mismatch — restore backup.
            @file_put_contents( $site_htaccess, $backup, LOCK_EX );
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::error( 'htaccess', "Size mismatch after write (expected " . strlen( $new ) . ", got {$on_disk}). Restored backup and falling back to PHP mode." );
            }
            update_option( 'easyopt_cache_mode', 'php', false );
            update_option( 'easyopt_htaccess_fallback_to_php', 1, false );
            @unlink( self::$root_path . '.htaccess' );
            return false;
        }

        // Validate the live response. If anything in the new file 500s,
        // restore the previous content immediately — silently.
        if ( ! self::probe_apache_ok() ) {
            @file_put_contents( $site_htaccess, $backup, LOCK_EX );
            update_option( 'easyopt_cache_mode', 'php', false );
            update_option( 'easyopt_htaccess_fallback_to_php', 1, false );
            @unlink( self::$root_path . '.htaccess' );
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::error( 'htaccess', 'Loopback probe returned 500 after .htaccess write. Restored backup and fell back to PHP mode.' );
            }
            return false;
        }

        // Success — clear the fallback marker.
        delete_option( 'easyopt_htaccess_fallback_to_php' );
        delete_option( 'easyopt_htaccess_validation_failed_at' );
        $already_ran = true;
        return true;
    }

    /**
     * Atomic write for .htaccess via temp file + rename.
     *
     * @param string $path    Absolute path to .htaccess.
     * @param string $content New content.
     * @return bool True on success.
     */
    private static function write_htaccess_atomic( $path, $content ) {
        $dir = dirname( $path );
        if ( ! is_writable( $dir ) ) {
            return false;
        }
        $tmp = @tempnam( $dir, 'eohta' );
        if ( false === $tmp ) {
            return false;
        }
        $written = @file_put_contents( $tmp, $content, LOCK_EX );
        if ( false === $written || $written !== strlen( $content ) ) {
            @unlink( $tmp );
            return false;
        }
        // Preserve original permissions.
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

    /**
     * Build the cache-directory .htaccess.
     *
     * 1.5.2 changes vs 1.5.1:
     *   • `Options -Indexes` wrapped in `<IfModule mod_autoindex.c>` —
     *     bare `Options` faults when `AllowOverride Options` isn't permitted
     *     (very common on shared hosting). Inside the IfModule guard the
     *     directive is just silently skipped.
     *   • All `Header set` directives kept inside `<IfModule mod_headers.c>`.
     *   • Cache files use .html_gzip extension (not .html.gz) so that
     *     LiteSpeed's built-in .gz handler can't force a download.
     */
    private static function build_cache_dir_htaccess() {

        $cdn_max_age = (int) apply_filters( 'easyopt_cache_cdn_max_age', self::DEFAULT_CDN_MAX_AGE );
        $host        = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        $host        = strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', $host ) );

        $r  = "# BEGIN Easy Optimizer Cache\n";
        // Register .html_gzip as gzip-encoded HTML. Using _gzip instead
        // of .gz avoids LiteSpeed's built-in MIME override that forces
        // .gz files to download instead of rendering as HTML.
        $r .= "<IfModule mod_mime.c>\n";
        $r .= "  AddType text/html .html_gzip\n";
        $r .= "  AddEncoding gzip .html_gzip\n";
        $r .= "</IfModule>\n";
        // Prevent mod_deflate from double-compressing already-gzipped cache files.
        $r .= "<IfModule mod_setenvif.c>\n";
        $r .= "  SetEnvIfNoCase Request_URI \\.html_gzip$ no-gzip\n";
        $r .= "</IfModule>\n";
        // Response headers via mod_headers. Content-Type / Content-Encoding /
        // Vary are SCOPED to the gzip cache files only. Setting
        // `Content-Encoding: gzip` on a plain (uncompressed) index.html served
        // to a non-gzip client yields an undecodable response — that was a
        // latent corruption bug before 2.2.1. The remaining headers are safe
        // for every response in this directory.
        $r .= "<IfModule mod_headers.c>\n";
        $r .= "  <FilesMatch \"\\.html_gzip$\">\n";
        $r .= "    Header set Content-Type \"text/html; charset=UTF-8\"\n";
        $r .= "    Header set Content-Encoding \"gzip\"\n";
        // Unset first so a mod_deflate-appended `Vary: User-Agent` (legacy
        // BrowserMatch on many hosts) can't fragment edge/CDN caches — these
        // static cache files only ever vary on Accept-Encoding.
        $r .= "    Header unset Vary\n";
        $r .= "    Header set Vary \"Accept-Encoding\"\n";
        $r .= "  </FilesMatch>\n";
        $r .= "  Header set X-Easy-Optimizer-Cache \"HIT-htaccess\"\n";
        // (2.5.5) `no-cache, must-revalidate` must apply to the cached HTML
        // ONLY. This directory also holds `css/*.served.css` (Used CSS in
        // "file" mode) and those were being revalidated on every single page
        // view. They are versioned by a content hash in `?ver=`, so they are
        // safe to cache immutably.
        $r .= "  <FilesMatch \"\\.html(_gzip)?$\">\n";
        $r .= "    Header set Cache-Control \"no-cache, must-revalidate\"\n";
        $r .= "  </FilesMatch>\n";
        $r .= "  <FilesMatch \"\\.(css|js)$\">\n";
        $r .= "    Header set Cache-Control \"public, max-age=31536000, immutable\"\n";
        $r .= "  </FilesMatch>\n";
        $r .= "  Header set CDN-Cache-Control \"max-age={$cdn_max_age}\"\n";
        if ( '' !== $host ) {
            $r .= "  Header set Cache-Tag \"{$host}\"\n";
        }
        $r .= "</IfModule>\n";
        // Disable directory listing. `Options -Indexes` 500s where AllowOverride
        // does NOT permit `Options`, and an <IfModule mod_autoindex.c> guard does
        // NOT prevent that (the module is loaded; it's the AllowOverride grant
        // that's missing). So it is emitted ONLY after passing its own isolated
        // sandbox probe in write_htaccess(). The blank index.html written there
        // is the zero-dependency fallback that blocks listing everywhere else.
        if ( get_option( 'easyopt_htaccess_options_ok' ) ) {
            $r .= "<IfModule mod_autoindex.c>\n";
            $r .= "  Options -Indexes\n";
            $r .= "</IfModule>\n";
        }
        $r .= "# END Easy Optimizer Cache\n";
        return $r;
    }

    /**
     * Detect whether the current web server understands .htaccess.
     *
     * Apache, LiteSpeed (Enterprise) and OpenLiteSpeed do. Nginx, Caddy,
     * IIS don't — a stray .htaccess on those just sits there ignored.
     * Some managed hosts run nginx in front of Apache and the .htaccess
     * IS interpreted; we conservatively return true on Apache-shaped UAs.
     *
     * Filterable via `easyopt_server_supports_htaccess` so users on edge
     * cases (e.g. nginx + Apache fallback) can override.
     */
    public static function server_supports_htaccess() {
        $sig = isset( $_SERVER['SERVER_SOFTWARE'] )
            ? strtolower( (string) wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
            : '';
        $is_apache = false !== strpos( $sig, 'apache' )
                  || false !== strpos( $sig, 'litespeed' );
        return (bool) apply_filters( 'easyopt_server_supports_htaccess', $is_apache, $sig );
    }

    /**
     * Detect OpenLiteSpeed specifically (as opposed to LiteSpeed Enterprise).
     *
     * OLS reports "LiteSpeed" in SERVER_SOFTWARE just like the Enterprise
     * edition, but its .htaccess support is partial — several mod_setenvif /
     * mod_headers constructs are rejected. We skip the Gzip block on OLS (it
     * compresses natively anyway), mirroring how FlyingPress / WP Rocket treat
     * it. The most reliable signal is the LSWS_EDITION server variable.
     *
     * @return bool
     */
    public static function is_openlitespeed() {
        if ( ! empty( $_SERVER['LSWS_EDITION'] )
             && false !== stripos( (string) wp_unslash( $_SERVER['LSWS_EDITION'] ), 'openlitespeed' ) ) {
            return (bool) apply_filters( 'easyopt_is_openlitespeed', true );
        }
        $sig = isset( $_SERVER['SERVER_SOFTWARE'] )
            ? strtolower( (string) wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) )
            : '';
        return (bool) apply_filters( 'easyopt_is_openlitespeed', false !== strpos( $sig, 'openlitespeed' ) );
    }

    /**
     * Probe a real URL on the site — typically the home page — and check
     * for a 500-class response. Used after writing .htaccess to confirm
     * we didn't break Apache's parse.
     *
     * The probe runs against the loopback / current host, so it works on
     * sites that aren't externally reachable yet (staging, behind firewalls).
     * `easyopt_skip_htaccess_probe` filter exists for hosts that block
     * loopback HTTP.
     */
    public static function probe_apache_ok() {

        if ( apply_filters( 'easyopt_skip_htaccess_probe', false ) ) {
            return true;
        }

        // optimization pipeline (see EasyOpt_Cache::is_probe_request and
        // the early-exit in start_output_buffer()). This brings probe
        // wallclock from "boot WP + run every output filter we have" down
        // to "boot WP + return a 60-byte 200" — typically a 5–20× speedup
        // on heavy themes, and an order of magnitude on Cloudways/LiteSpeed
        // setups where loopback latency is part of the cost.
        $key = self::probe_key();
        $probe = add_query_arg(
            array(
                'easyopt_probe' => '1',
                'key'           => $key,
            ),
            home_url( '/' )
        );

        // ~60-byte 200 response (see is_probe_request), so 2s is generous;
        // a host that can't return 60 bytes in 2s isn't going to recover
        // by waiting longer. This is the single biggest hot-path saving
        // on the cache-toggle save flow — the probe used to run inside
        // the (formerly synchronous) save listener, eating 2-4s per
        // cache-affecting setting change.
        $resp = wp_remote_get( $probe, array(
            'timeout'     => 2,
            'redirection' => 0,
            'sslverify'   => false,
            'blocking'    => true,
            'headers'     => array(
                'X-Easyopt-Probe' => '1',
                'Cache-Control'   => 'no-cache',
            ),
        ) );

        if ( is_wp_error( $resp ) ) {
            // Loopback blocked / DNS / TLS hiccup. Retry once with a slightly
            // longer timeout before giving up.
            $resp = wp_remote_get( $probe, array(
                'timeout'     => 3,
                'redirection' => 0,
                'sslverify'   => false,
                'blocking'    => true,
                'headers'     => array(
                    'X-Easyopt-Probe' => '1',
                    'Cache-Control'   => 'no-cache',
                ),
            ) );
        }

        if ( is_wp_error( $resp ) ) {
            // We genuinely could NOT verify the write. Don't silently pretend
            // success — flag it so an admin notice can surface (a root-file 500
            // would also take down wp-admin, so "self-heal on next pageview" is
            // not guaranteed). Default behaviour keeps the write (fail-open) so
            // loopback-blocked-but-healthy hosts don't lose features; filterable.
            update_option( 'easyopt_htaccess_unverified', time(), false );
            // Distinct, diagnosable log line (B15): the usual culprits are
            // hosts blocking loopback HTTP and security plugins / WAFs
            // (Wordfence etc.) rejecting the probe UA/header. Either way the
            // result is fail-open with an unverified flag, never a silent 500.
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::warn( 'htaccess', sprintf(
                    'Loopback probe could not run (%s). Common causes: host blocks internal HTTP, or a security plugin/WAF blocked the probe request. Rules kept (fail-open) and flagged unverified — see the notice on the settings screen.',
                    $resp->get_error_message()
                ) );
            }
            return (bool) apply_filters( 'easyopt_probe_unverified_ok', true );
        }

        // Confirmed a real response — clear any stale "unverified" flag.
        delete_option( 'easyopt_htaccess_unverified' );
        $code = (int) wp_remote_retrieve_response_code( $resp );
        return $code < 500 || $code >= 600;
    }

    /**
     * Test a single rule class in an ISOLATED throwaway directory, the way
     * WP Rocket sandboxes its rule tests. Writes an .htaccess containing only
     * $rules into a temp subdir of the cache dir, loopback-requests a probe
     * file there, and returns whether the server parsed it without a 5xx. The
     * live root .htaccess is never touched, so a directive the server rejects
     * is caught here and simply omitted instead of 500-ing the whole site.
     *
     * Fail-open: if the loopback can't be performed (blocked/DNS/TLS) the rule
     * is INCLUDED — the existing live probe + rollback in write_htaccess() is
     * the backstop, and this avoids dropping working features (e.g. Gzip) on
     * hosts that merely block loopback HTTP.
     *
     * @param string $name  Stable slug for the rule class (e.g. 'gzip').
     * @param string $rules Raw directive block for this class only.
     * @return bool True if safe to emit on this server.
     */
    public static function probe_rule_class( $name, $rules ) {

        // Honour the global probe opt-out — include everything (fail-open).
        if ( apply_filters( 'easyopt_skip_htaccess_probe', false ) ) {
            return true;
        }
        if ( '' === trim( (string) $rules ) ) {
            return false;
        }
        if ( '' === self::$root_path ) {
            self::setup_paths();
        }

        $slug = preg_replace( '/[^a-z0-9_-]/i', '', (string) $name );
        $dir  = self::$root_path . 'selftest/' . $slug . '/';

        if ( ! wp_mkdir_p( $dir ) ) {
            // Can't sandbox → don't block the feature; live probe is the net.
            return (bool) apply_filters( 'easyopt_probe_unverified_default', true, $name );
        }

        // Isolated .htaccess containing ONLY this rule class, plus a tiny file.
        @file_put_contents( $dir . '.htaccess', (string) $rules, LOCK_EX );
        @file_put_contents( $dir . 'probe.html', "ok\n", LOCK_EX );

        // Build the URL from the cache dir's path relative to ABSPATH — the
        // same scheme build_rewrite_section() uses for DOCUMENT_ROOT lookups.
        $rel = ltrim( str_replace( ABSPATH, '', self::$root_path ), '/' );
        $url = home_url( '/' . $rel . 'selftest/' . $slug . '/probe.html' );

        $resp = wp_remote_get( $url, array(
            'timeout'     => 3,
            'redirection' => 0,
            'sslverify'   => false,
            'blocking'    => true,
            'headers'     => array( 'Cache-Control' => 'no-cache' ),
        ) );

        // Clean up immediately.
        @unlink( $dir . '.htaccess' );
        @unlink( $dir . 'probe.html' );
        @rmdir( $dir );
        @rmdir( self::$root_path . 'selftest/' );

        if ( is_wp_error( $resp ) ) {
            return (bool) apply_filters( 'easyopt_probe_unverified_default', true, $name );
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        return $code < 500 || $code >= 600;
    }

    /**
     * Returns a short, stable key used to authenticate probe requests.
     * Stored on first use; not regenerated on every probe (otherwise the
     * outgoing wp_remote_get() and the inbound is_probe_request() check
     * would race). The key is not a security secret in the auth sense —
     * it just stops random bots from triggering the probe-response path
     * (which still runs WP bootstrap, just none of our pipeline).
     */
    public static function probe_key() {
        $k = (string) get_option( 'easyopt_probe_key', '' );
        if ( '' === $k ) {
            // Use the EASYOPT_VERSION constant as a default seed so
            // re-installs across versions don't collide. The constant
            // value lives in the plugin source, so a remote attacker
            // can't realistically guess the version-pinned key without
            // also knowing the install's full version string.
            $k = substr( md5( EASYOPT_VERSION . wp_generate_password( 12, false, false ) ), 0, 16 );
            update_option( 'easyopt_probe_key', $k, false );
        }
        return $k;
    }

    /**
     * True when the current request looks like our own probe loopback.
     * Called from start_output_buffer() (front-controller) BEFORE we
     * register the master output processor — letting probe requests
     * skip the full pipeline.
     */
    public static function is_probe_request() {
        if ( empty( $_GET['easyopt_probe'] ) ) {
            return false;
        }
        // Header check first — cheaper than option read on every request,
        // and our own loopback always sends it.
        $hdr = isset( $_SERVER['HTTP_X_EASYOPT_PROBE'] ) ? (string) $_SERVER['HTTP_X_EASYOPT_PROBE'] : '';
        if ( '1' !== $hdr ) {
            return false;
        }
        $key = isset( $_GET['key'] ) ? (string) $_GET['key'] : '';
        if ( '' === $key ) {
            return false;
        }
        // Compare against stored key. Fall back to "any non-empty" only
        // if the option is missing (transient race during first probe).
        $expected = (string) get_option( 'easyopt_probe_key', '' );
        if ( '' === $expected ) {
            return true;
        }
        return hash_equals( $expected, $key );
    }

    /**
     * Public version of should_emit_gzip_block — used by the settings UI to
     * grey out the gzip toggle when zlib is already on.
     */
    public static function php_zlib_active() {
        return ! self::should_emit_gzip_block();
    }

    /**
     * Whether to emit the mod_deflate block. Skipped when PHP's
     * zlib.output_compression is on, because Apache + PHP both compressing
     * is wasted CPU and can produce broken output on some configurations.
     */
    private static function should_emit_gzip_block() {

        $zlib = ini_get( 'zlib.output_compression' );
        if ( false === $zlib ) {
            return true;
        }
        $zlib_lc = is_string( $zlib ) ? strtolower( trim( $zlib ) ) : '';
        if ( '1' === $zlib_lc || 'on' === $zlib_lc || 'true' === $zlib_lc ) {
            return false;
        }
        if ( is_numeric( $zlib ) && (int) $zlib > 0 ) {
            return false;
        }
        return true;
    }

    /**
     * Build the page-cache rewrite section (htaccess mode only).
     * Returns content WITHOUT BEGIN/END markers — those are added by
     * write_htaccess() around the consolidated block.
     */
    private static function build_rewrite_section() {

        $home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
        if ( ! is_string( $home_path ) || '' === $home_path ) {
            $home_path = '/';
        }

        // Path of the cache dir relative to ABSPATH (with trailing slash).
        $cache_rel = ltrim( str_replace( ABSPATH, '', self::$root_path ), '/' );
        $cache_rel = trailingslashit( $cache_rel );

        // MIME type + encoding for the custom _gzip extension. Placed inside
        // the rewrite section so LiteSpeed, OpenLiteSpeed and Apache all
        // process it in the same request as the RewriteRule.
        $rules  = "# ── Page Cache Rewrite ──\n";
        $rules .= "<IfModule mod_mime.c>\n";
        $rules .= "  AddType text/html .html_gzip\n";
        $rules .= "  AddEncoding gzip .html_gzip\n";
        $rules .= "</IfModule>\n";
        // Prevent mod_deflate from double-compressing already-gzipped cache files.
        $rules .= "<IfModule mod_setenvif.c>\n";
        $rules .= "  SetEnvIfNoCase Request_URI \\.html_gzip$ no-gzip\n";
        $rules .= "</IfModule>\n";
        $rules .= "<IfModule mod_rewrite.c>\n";
        $rules .= "RewriteEngine On\n";
        $rules .= "RewriteBase " . $home_path . "\n";

        // (2.5.5) OpenLiteSpeed parses ONLY mod_rewrite directives from
        // .htaccess. The `AddType`/`AddEncoding` pair above and the
        // `<FilesMatch>` Content-Type/Content-Encoding headers in the
        // cache-dir .htaccess are silently ignored there. If we still
        // rewrote to `index.html_gzip`, OLS would serve that unknown
        // extension as application/octet-stream with no Content-Encoding —
        // the browser then DOWNLOADS the page instead of rendering it.
        // LiteSpeed Enterprise and Apache both honour those directives, so
        // they keep the compressed fast path. On OLS we rewrite to the plain
        // `index.html` (always written since 2.5.5) and let the server apply
        // its own native gzip/brotli compression on the way out.
        if ( ! self::is_openlitespeed() ) {
            $rules .= "RewriteCond %{HTTP:Accept-Encoding} gzip\n";
            $rules .= "RewriteRule .* - [E=EASYOPT_ENC:_gzip]\n";
        }

        // Shared conditions (must be repeated before each RewriteRule because
        // Apache's RewriteCond only applies to the immediately following rule).
        //
        // NOTE (serve-mode parity): the `-f` test below looks the file up by
        // the RAW %{REQUEST_URI}, whereas the PHP writer stores files under a
        // sanitize_file_name()'d path. For ordinary permalinks (alphanumerics
        // + hyphens) these are identical and Apache serves directly. For URLs
        // whose path sanitize_file_name() would alter (encoded/multibyte/odd
        // characters), the `-f` test misses and the request transparently
        // falls back to the PHP drop-in — which derives the same sanitized
        // path and serves correctly. That fallback is functional (it's also
        // WP Rocket's default serving model); the Caching tab surfaces which
        // mode is live. We intentionally do NOT try to replicate
        // sanitize_file_name() inside mod_rewrite, since a regex that altered
        // matching for already-working clean URLs would risk every page on
        // every site for a marginal gain on exotic URLs.
        // (2.5.5) Language cookies are included in the list below so that
        // cookie-driven multilingual setups (Polylang / WPML) never get a
        // statically-served page in the wrong language. mod_rewrite cannot
        // key a filename on a cookie VALUE, so those requests deliberately
        // fall through to the PHP drop-in, which does vary the cache file by
        // cookie value via easyopt_cache_include_cookies.
        $shared  = "RewriteCond %{HTTP:Cookie} !(easyopt_skip_cache|wordpress_logged_in_|wordpress_sec_|comment_author_|edd_items_in_cart|wp-postpass_|pll_language|wp-wpml_current_language|_icl_current_language) [NC]\n";
        $shared .= "RewriteCond %{REQUEST_METHOD} ^GET$\n";
        $shared .= "RewriteCond %{QUERY_STRING} ^$\n";
        // (2.6.1) %{HTTP_HOST} carries the port when the client sends one, but
        // the cache writer strips ':' from the directory name — so on a
        // non-standard port Apache looks for "example.com:8080/..." while the
        // page was written to "example.com8080/...". The rule could never
        // match, silently, on every request. mod_rewrite cannot rewrite the
        // variable, so skip the static path for those requests and let the PHP
        // drop-in serve them: correct content, one extra hop, and only on a
        // setup that is rare to begin with.
        $shared .= "RewriteCond %{HTTP_HOST} !:\n";
        // (EO-05) Anchor sitemap / cart / checkout / my-account to path
        // boundaries so content slugs like /sitemap-guide/ or
        // /cart-abandonment-tips/ are not wrongly excluded from cache. File
        // sitemaps stay covered by the \.xml / \.txt tokens.
        $shared .= "RewriteCond %{REQUEST_URI} !(wp-admin|wp-login|wp-cron|xmlrpc|wp-json|/feed/|/sitemap(?:_index|s)?/?\$|\\.xml|\\.txt|/cart(?:/|\$)|/checkout(?:/|\$)|/my-account(?:/|\$)|/wc-api) [NC]\n";

        // Mobile match — serve index-mobile.html(_gzip).
        $rules .= $shared;
        $rules .= "RewriteCond %{HTTP_USER_AGENT} (android.+mobile|avantgo|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|maemo|midp|mmp|mobile.+firefox|netfront|opera\\ m(ob|in)i|palm(\\ os)?|phone|p(ixi|re)\\/|plucker|pocket|psp|series(4|6)0|symbian|treo|up\\.(browser|link)|vodafone|wap|windows\\ (ce|phone)|xda|xiino) [NC]\n";
        $rules .= "RewriteCond %{DOCUMENT_ROOT}/" . $cache_rel . "%{HTTP_HOST}%{REQUEST_URI}/index-mobile.html%{ENV:EASYOPT_ENC} -f\n";
        $rules .= "RewriteRule .* /" . $cache_rel . "%{HTTP_HOST}%{REQUEST_URI}/index-mobile.html%{ENV:EASYOPT_ENC} [L]\n";

        // Desktop match — serve index.html(_gzip).
        $rules .= $shared;
        $rules .= "RewriteCond %{DOCUMENT_ROOT}/" . $cache_rel . "%{HTTP_HOST}%{REQUEST_URI}/index.html%{ENV:EASYOPT_ENC} -f\n";
        $rules .= "RewriteRule .* /" . $cache_rel . "%{HTTP_HOST}%{REQUEST_URI}/index.html%{ENV:EASYOPT_ENC} [L]\n";

        $rules .= "</IfModule>\n\n";

        return $rules;
    }

    /**
     * Build the gzip/brotli compression section.
     * Returns content WITHOUT BEGIN/END markers.
     * 2.2.1: legacy BrowserMatch directives removed; AddOutputFilterByType
     * guarded by mod_filter (Apache 2.4 correct module). Sandbox-probed and
     * skipped on OpenLiteSpeed by the caller.
     */
    private static function build_gzip_section() {

        $b  = "# ── Gzip Compression ──\n";
        $b .= "<IfModule mod_deflate.c>\n";
        // AddOutputFilterByType is provided by mod_filter on Apache 2.4 (it is
        // a compatibility shim there, not a mod_deflate directive). Guarding it
        // with mod_deflate.c is wrong on 2.4; mod_filter.c is correct and is
        // what WP Rocket uses. The compressed MIME list is unchanged from 2.2.0.
        $b .= "  <IfModule mod_filter.c>\n";
        $b .= "    AddOutputFilterByType DEFLATE text/html text/css text/javascript application/javascript application/x-javascript text/xml application/xml application/xml+rss application/rss+xml application/atom+xml application/json application/ld+json image/svg+xml font/ttf font/otf font/eot application/vnd.ms-fontobject application/font-woff application/x-font-ttf\n";
        $b .= "  </IfModule>\n";
        // 2.2.1: the legacy `BrowserMatch ^Mozilla/4 …` / `\bMSIE …` directives
        // (a Netscape-4 / IE-5–6 gzip workaround from ~2003) were removed. They
        // are rejected by the LiteSpeed/OpenLiteSpeed config parser and were the
        // cause of the post-write HTTP 500. The modern, safe replacement below
        // simply skips compression on already-compressed binaries.
        $b .= "  <IfModule mod_setenvif.c>\n";
        $b .= "    SetEnvIfNoCase Request_URI \\.(?:gif|jpe?g|png|webp|avif|ico|zip|gz|rar|7z|woff2?|mp4|webm|mp3|pdf)$ no-gzip dont-vary\n";
        $b .= "  </IfModule>\n";
        // Vary on Accept-Encoding (not User-Agent). User-Agent fragments CDN/
        // proxy caches badly; mobile cache separation is handled by the rewrite.
        $b .= "  <IfModule mod_headers.c>\n";
        $b .= "    Header append Vary Accept-Encoding\n";
        $b .= "  </IfModule>\n";
        $b .= "</IfModule>\n";
        // Brotli when available (mod_brotli, Apache 2.4.26+ / LiteSpeed).
        // AddOutputFilterByType lives in mod_filter here too.
        $b .= "<IfModule mod_brotli.c>\n";
        $b .= "  <IfModule mod_filter.c>\n";
        $b .= "    AddOutputFilterByType BROTLI_COMPRESS text/html text/css text/javascript application/javascript application/x-javascript text/xml application/xml application/json image/svg+xml\n";
        $b .= "  </IfModule>\n";
        $b .= "</IfModule>\n\n";

        /**
         * Filter the generated Gzip/Brotli .htaccess block.
         *
         * @since 2.2.1
         * @param string $b The block, including its `# ── Gzip Compression ──` header.
         */
        return (string) apply_filters( 'easyopt_htaccess_gzip', $b );
    }

    /**
     * Build the browser-cache (Expires + Cache-Control) section.
     * Returns content WITHOUT BEGIN/END markers.
     * ExpiresActive wrapped in IfModule guard for AllowOverride safety.
     */
    private static function build_browser_cache_section() {

        $b  = "# ── Browser Cache ──\n";
        $b .= "<IfModule mod_expires.c>\n";
        $b .= "  ExpiresActive On\n";
        $b .= "  ExpiresDefault \"access plus 1 month\"\n";
        // HTML — short, so visitors get fresh pages on changes.
        $b .= "  ExpiresByType text/html                 \"access plus 0 seconds\"\n";
        // (2.5.6) Plain text — short. robots.txt, llms.txt and friends are
        // GENERATED files that change whenever content or settings change.
        // Without this line they fall through to ExpiresDefault (1 month),
        // which kept a regenerated llms.txt hidden from crawlers for weeks.
        // Content-Type based, so it covers both physical files and the
        // virtual ones WordPress serves through index.php.
        $b .= "  ExpiresByType text/plain                \"access plus 5 minutes\"\n";
        // Feeds.
        $b .= "  ExpiresByType application/atom+xml      \"access plus 1 hour\"\n";
        $b .= "  ExpiresByType application/rss+xml       \"access plus 1 hour\"\n";
        // Images — long.
        $b .= "  ExpiresByType image/jpeg                \"access plus 1 year\"\n";
        $b .= "  ExpiresByType image/png                 \"access plus 1 year\"\n";
        $b .= "  ExpiresByType image/gif                 \"access plus 1 year\"\n";
        $b .= "  ExpiresByType image/webp                \"access plus 1 year\"\n";
        $b .= "  ExpiresByType image/avif                \"access plus 1 year\"\n";
        $b .= "  ExpiresByType image/svg+xml             \"access plus 1 year\"\n";
        $b .= "  ExpiresByType image/x-icon              \"access plus 1 year\"\n";
        $b .= "  ExpiresByType image/vnd.microsoft.icon  \"access plus 1 year\"\n";
        // Video / audio.
        $b .= "  ExpiresByType video/mp4                 \"access plus 1 year\"\n";
        $b .= "  ExpiresByType video/webm                \"access plus 1 year\"\n";
        $b .= "  ExpiresByType audio/mpeg                \"access plus 1 year\"\n";
        $b .= "  ExpiresByType audio/ogg                 \"access plus 1 year\"\n";
        // CSS / JS — 1 year (cache-busted via ?ver= query strings already).
        $b .= "  ExpiresByType text/css                  \"access plus 1 year\"\n";
        $b .= "  ExpiresByType text/javascript           \"access plus 1 year\"\n";
        $b .= "  ExpiresByType application/javascript    \"access plus 1 year\"\n";
        $b .= "  ExpiresByType application/x-javascript  \"access plus 1 year\"\n";
        // Fonts.
        $b .= "  ExpiresByType font/ttf                  \"access plus 1 year\"\n";
        $b .= "  ExpiresByType font/otf                  \"access plus 1 year\"\n";
        $b .= "  ExpiresByType font/woff                 \"access plus 1 year\"\n";
        $b .= "  ExpiresByType font/woff2                \"access plus 1 year\"\n";
        $b .= "  ExpiresByType application/vnd.ms-fontobject \"access plus 1 year\"\n";
        $b .= "</IfModule>\n";
        $b .= "<IfModule mod_headers.c>\n";
        // Long-lived public cache for static assets — pairs with Expires.
        $b .= "  <FilesMatch \"\\.(ico|jpe?g|png|gif|webp|avif|svg|css|js|woff2?|ttf|otf|eot|mp4|webm)$\">\n";
        $b .= "    Header set Cache-Control \"public, max-age=31536000, immutable\"\n";
        $b .= "  </FilesMatch>\n";
        $b .= "</IfModule>\n\n";
        return $b;
    }

    /**
     * Strip all of our blocks from the site-root .htaccess, plus any orphan
     * fragments left behind by the 1.4.6 strip bug. The cache-dir htaccess
     * is left in place — it's harmless when no files exist.
     */
    private static function strip_root_htaccess_block() {
        $site_htaccess = ABSPATH . '.htaccess';
        if ( ! file_exists( $site_htaccess ) || ! is_writable( $site_htaccess ) ) {
            return;
        }
        $content = (string) @file_get_contents( $site_htaccess );
        $clean   = self::strip_easyopt_blocks( $content );
        if ( $clean !== $content ) {
            @file_put_contents( $site_htaccess, $clean, LOCK_EX );
        }
    }

    /**
     * Pure-string helper: strip every Easy Optimizer block (and orphan
     * fragments) from an .htaccess body. Handles BOTH the pre-2.1.1
     * multi-block format AND the 2.1.1+ single consolidated block.
     *
     * Order matters:
     *   1. Strip the SPECIFIC named blocks first (longest BEGIN wins).
     *   2. Strip the generic consolidated block.
     *   3. Clean orphan fragments from the 1.4.6 strip bug.
     */
    public static function strip_easyopt_blocks( $content ) {

        // Pre-2.1.1: specific named blocks first.
        $content = preg_replace(
            '/# BEGIN Easy Optimizer Browser Cache.*?# END Easy Optimizer Browser Cache\s*/s',
            '',
            $content
        );
        $content = preg_replace(
            '/# BEGIN Easy Optimizer Gzip.*?# END Easy Optimizer Gzip\s*/s',
            '',
            $content
        );

        // 2.1.1+: single consolidated block — OR pre-2.1.1 generic rewrite block.
        // The pattern requires a newline immediately after "Optimizer" so it
        // won't accidentally match "Easy Optimizer Gzip" or "Easy Optimizer Browser Cache".
        $content = preg_replace(
            '/# BEGIN Easy Optimizer(?:\r?\n).*?# END Easy Optimizer(?:\r?\n)\s*/s',
            '',
            $content
        );

        // ── One-time cleanup for sites polluted by the 1.4.6 strip bug ──
        $content = preg_replace( '/^[ \t]*Gzip[ \t]*\r?\n/m',          '', $content );
        $content = preg_replace( '/^[ \t]*Browser Cache[ \t]*\r?\n/m', '', $content );

        // Collapse 3+ consecutive blank lines down to 2 — purely cosmetic.
        $content = preg_replace( "/(\r?\n){3,}/", "\n\n", $content );

        return $content;
    }

    /**
     * Build an Nginx server-block snippet that serves the page cache
     * directly, skipping PHP entirely.
     *
     * Nginx has no .htaccess equivalent, so this cannot be applied
     * automatically — the user (or their host) pastes it into the server
     * block and reloads. Without it Nginx sites still work: every request
     * falls through to the PHP drop-in, which serves the same files a
     * millisecond or two slower. This snippet closes that gap.
     *
     * Notes on the generated rules:
     *   • `gzip_static` is deliberately NOT used: it only probes for a
     *     `.gz` suffix, and our pre-compressed companions are named
     *     `index.html_gzip` (see EasyOpt_Cache::atomic_write callers).
     *     Nginx would never find them, so the directive was dead weight.
     *     Runtime `gzip on` (default on mainstream distros) compresses the
     *     served .html instead; the `_gzip` companions remain in use by
     *     the PHP drop-in and the .htaccess mod_mime path. Renaming the
     *     companions to `.gz` is off the table — the drop-in, purge
     *     matching, and stats all key on the `_gzip` suffix.
     *   • A cache MISS must fall through to PHP: the internal location
     *     carries `try_files $uri /index.php?$args;` for exactly that.
     *     Without it, `rewrite ... last` into a nonexistent file 404s
     *     every not-yet-cached page.
     *   • `$host` (normalized, port-stripped) is used rather than
     *     `$http_host`: the cache writer keys directories on the
     *     sanitized host, so `example.com:8443` or mixed-case Host
     *     headers would otherwise never match — and raw client header
     *     text does not belong in a filesystem path.
     *   • The cookie/method/query guards mirror the .htaccess conditions so
     *     both serve modes make identical decisions.
     *   • Language cookies are excluded for the same reason as .htaccess:
     *     Nginx cannot key a filename on a cookie VALUE, so those requests
     *     must reach PHP.
     *
     * @since 2.5.5
     * @return string Nginx configuration snippet.
     */
    public static function build_nginx_snippet() {

        if ( '' === self::$root_path ) {
            self::setup_paths();
        }

        if ( 0 === strpos( self::$root_path, ABSPATH ) ) {
            $cache_rel = ltrim( substr( self::$root_path, strlen( ABSPATH ) ), '/' );
        } elseif ( defined( 'WP_CONTENT_DIR' ) && 0 === strpos( self::$root_path, trailingslashit( WP_CONTENT_DIR ) ) ) {
            // Relocated content dir: map the filesystem path through the
            // public content URL instead of ABSPATH (which is not a prefix
            // here — str_replace would leave an absolute server path in
            // the generated rules).
            $content_path = wp_parse_url( content_url(), PHP_URL_PATH );
            $content_path = is_string( $content_path ) ? trim( $content_path, '/' ) : 'wp-content';
            $cache_rel    = $content_path . '/' . ltrim( substr( self::$root_path, strlen( trailingslashit( WP_CONTENT_DIR ) ) ), '/' );
        } else {
            // Cache dir resolves to neither ABSPATH nor WP_CONTENT_DIR —
            // a URL cannot be derived safely. Fall back to the default
            // location and say so in the snippet header (fail-open: the
            // PHP drop-in keeps serving the cache regardless).
            $cache_rel  = 'wp-content/cache/easyopt';
            $path_guess = true;
        }
        $cache_rel = rtrim( $cache_rel, '/' );

        $home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
        if ( ! is_string( $home_path ) || '' === $home_path ) {
            $home_path = '/';
        }

        $n  = "# ── Easy Optimizer — Nginx page cache ──────────────────────────\n";
        $n .= "# Paste inside your `server { }` block, then: nginx -t && systemctl reload nginx\n";
        $n .= "# Everything still works without this; it only skips PHP on a cache HIT.\n";
        if ( ! empty( $path_guess ) ) {
            $n .= "# WARNING: your cache directory could not be mapped to a public URL\n";
            $n .= "# automatically (relocated content directory?). The paths below use the\n";
            $n .= "# WordPress default — verify them against your setup before applying.\n";
        }
        $n .= "\n";

        $n .= "set \$easyopt_skip 0;\n\n";

        $n .= "# Never serve a cached page to a request that carries state.\n";
        $n .= "if (\$request_method != GET) { set \$easyopt_skip 1; }\n";
        $n .= "if (\$query_string != \"\") { set \$easyopt_skip 1; }\n";
        $n .= "if (\$http_cookie ~* \"(easyopt_skip_cache|wordpress_logged_in_|wordpress_sec_|comment_author_|edd_items_in_cart|wp-postpass_|pll_language|wp-wpml_current_language|_icl_current_language)\") { set \$easyopt_skip 1; }\n";
        $n .= "if (\$request_uri ~* \"(/wp-admin|/wp-login|/wp-cron|/xmlrpc|/wp-json|/feed/|/cart/|/checkout/|/my-account/|/wc-api)\") { set \$easyopt_skip 1; }\n\n";

        // (2.6.1) Honour cache_separate_mobile. The mobile branch was emitted
        // unconditionally, so with the setting OFF — when the writer only ever
        // produces index.html — every mobile visitor was rewritten to an
        // index-mobile.html that does not exist, fell through the internal
        // location to /index.php, and lost the Nginx fast path for nothing.
        // Correct output either way, just wasted work.
        $n .= "# Mobile pages are cached separately.\n";
        $n .= "set \$easyopt_device \"\";\n";
        if ( (int) EasyOpt_Config::get( 'cache_separate_mobile', 1 ) ) {
            $n .= "if (\$http_user_agent ~* \"(android.+mobile|blackberry|iemobile|ip(hone|od)|opera m(ob|in)i|mobile.+firefox|windows (ce|phone)|palm|symbian)\") { set \$easyopt_device \"-mobile\"; }\n";
        } else {
            $n .= "# (Separate Mobile Cache is off — one file serves both.)\n";
        }
        $n .= "\n";

        $n .= "location " . $home_path . " {\n";
        $n .= "    # \$host (not \$http_host): normalized and port-stripped, so it\n";
        $n .= "    # matches the directory name the cache writer keys on.\n";
        $n .= "    # The .php guard prevents a redirect cycle on the MISS fallback\n";
        $n .= "    # if this location ever handles /index.php itself.\n";
        $n .= "    if (\$uri ~* \\.php\$) { set \$easyopt_skip 1; }\n";
        $n .= "    if (\$easyopt_skip = 0) {\n";
        $n .= "        rewrite ^ /" . $cache_rel . "/\$host\$uri/index\$easyopt_device.html last;\n";
        $n .= "    }\n";
        $n .= "    try_files \$uri \$uri/ /index.php?\$args;\n";
        $n .= "}\n\n";

        $n .= "location ~* /" . $cache_rel . "/.*\\.html\$ {\n";
        $n .= "    internal;\n";
        $n .= "    # A MISS must fall through to PHP — without this line, every\n";
        $n .= "    # not-yet-cached page would return 404.\n";
        $n .= "    try_files \$uri /index.php?\$args;\n";
        $n .= "    add_header Cache-Control \"no-cache, must-revalidate\";\n";
        $n .= "    add_header X-Easy-Optimizer-Cache \"HIT-nginx\";\n";
        $n .= "    add_header Vary \"Accept-Encoding\";\n";
        $n .= "}\n\n";

        $n .= "# Used CSS sidecars are content-hash versioned — cache them hard.\n";
        $n .= "location ~* /" . $cache_rel . "/css/.*\\.css\$ {\n";
        $n .= "    add_header Cache-Control \"public, max-age=31536000, immutable\";\n";
        $n .= "}\n";

        /**
         * Filter the generated Nginx snippet.
         *
         * @since 2.5.5
         * @param string $n Snippet text.
         */
        return (string) apply_filters( 'easyopt_nginx_snippet', $n );
    }

    public static function remove_htaccess() {
        self::strip_root_htaccess_block();
        if ( '' === self::$root_path ) {
            self::setup_paths();
        }
        if ( file_exists( self::$root_path . '.htaccess' ) ) {
            @unlink( self::$root_path . '.htaccess' );
        }
        // 2.2.1 artifacts: the directory-listing safeguard and any leftover
        // sandbox-probe directory.
        if ( file_exists( self::$root_path . 'index.html' ) ) {
            @unlink( self::$root_path . 'index.html' );
        }
        if ( is_dir( self::$root_path . 'selftest' ) ) {
            self::rmdir_recursive( self::$root_path . 'selftest', true );
        }
    }

    /**
     * Detect whether the host is Apache. Used to choose the default cache mode
     * on first activation.
     */
    public static function is_apache() {
        if ( function_exists( 'apache_get_modules' ) ) {
            return true;
        }
        $sw = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( (string) wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
        return ( false !== strpos( $sw, 'apache' ) || false !== strpos( $sw, 'litespeed' ) );
    }

    /**
     * Default cache serving mode based on host detection.
     * Apache / LiteSpeed → htaccess (fastest), everything else → php (universal).
     */
    public static function default_mode() {
        if ( ! self::htaccess_rewrite_allowed() ) {
            return 'php';
        }
        return self::is_apache() ? 'htaccess' : 'php';
    }

}
