<?php
/**
 * Drop-in installer for the advanced-cache.php file.
 *
 * Copies <plugin>/assets/advanced-cache.php to wp-content/advanced-cache.php
 * with the live config inlined as a var_export()'d array. Re-runs on every
 * cache-related setting save so the drop-in always reflects the current
 * options without ever needing to read the database on a request.
 *
 * The drop-in is a defence-in-depth optimisation. When it can't be installed
 * (read-only wp-content, hardened hosts, multi-network rules, etc.) the
 * runtime `EasyOpt_Cache::maybe_serve_cache()` path keeps working as the
 * fallback — slower, but functional.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Advanced_Cache {

    /** Drop-in target path. */
    public static function target_path() {
        // WordPress.com / Atomic hosting manages its own advanced-cache.php.
        // Use a separate filename to avoid conflicts.
        if ( class_exists( 'Atomic_Persistent_Data' ) ) {
            return trailingslashit( WP_CONTENT_DIR ) . 'easy-optimizer-advanced-cache.php';
        }
        return trailingslashit( WP_CONTENT_DIR ) . 'advanced-cache.php';
    }

    /** Source template path. */
    public static function source_path() {
        return trailingslashit( EASYOPT_DIR ) . 'assets/advanced-cache.php';
    }

    /**
     * Whether OUR drop-in is currently installed.
     */
    public static function is_installed() {
        $path = self::target_path();
        if ( ! file_exists( $path ) ) {
            return false;
        }
        $head = (string) @file_get_contents( $path, false, null, 0, 600 );
        return false !== strpos( $head, 'Easy Optimizer' );
    }

    /**
     * Known drop-in header signatures → the plugin file that owns them.
     * Presentation and last-resort ownership only; the load-bearing test is
     * dropin_owner_is_live(), which needs no list at all.
     *
     * @since 2.5.7
     * @var array<string,string>
     */
    /**
     * Extra header needles per plugin (2.6.5).
     *
     * The signature map below is keyed by DISPLAY LABEL and was matched with
     * stripos( $head, $label ) — which silently assumed every drop-in prints
     * its own name the way we spell it. WP Rocket does not: its
     * advanced-cache.php contains `WP_ROCKET_ADVANCED_CACHE` and
     * `WP_Rocket\Buffer\Cache` — underscores, never the string "WP Rocket".
     * So the owner label fell through to "another caching plugin", and users
     * were shown a notice with a "Deactivate another caching plugin" button.
     * It also broke the 2.6.5 de-duplication, which skips a plugin from the
     * overlap notice by matching that owner name.
     *
     * Detection itself was never affected — dropin_owner_is_live()'s primary
     * probe is a file-reference check that needs no names at all.
     *
     * @since 2.6.5
     * @var array<string,string[]>
     */
    private static $dropin_aliases = array(
        'WP Rocket'        => array( 'WP_ROCKET', 'WP_Rocket' ),
        'LiteSpeed Cache'  => array( 'LiteSpeed', 'LSCWP' ),
        'W3 Total Cache'   => array( 'W3TC', 'w3-total-cache' ),
        'WP Super Cache'   => array( 'WPCACHEHOME', 'wp-super-cache' ),
        'WP Fastest Cache' => array( 'WpFastestCache', 'wpFastestCache', 'wp-fastest-cache' ),
        'Comet Cache'      => array( 'comet_cache', 'comet-cache' ),
        'Cache Enabler'    => array( 'CACHE_ENABLER', 'cache-enabler' ),
        'Breeze'           => array( 'BREEZE_', 'breeze' ),
        'Powered Cache'    => array( 'POWERED_CACHE', 'powered-cache' ),
        'SpeedyCache'      => array( 'speedycache' ),
        'WP-Optimize'      => array( 'WPO_CACHE', 'wp-optimize' ),
        'NitroPack'        => array( 'nitropack' ),
        'Hummingbird'      => array( 'Hummingbird' ),
    );

    private static $dropin_signatures = array(
        'WP Rocket'       => 'wp-rocket/wp-rocket.php',
        'LiteSpeed Cache' => 'litespeed-cache/litespeed-cache.php',
        'W3 Total Cache'  => 'w3-total-cache/w3-total-cache.php',
        'WP Super Cache'  => 'wp-super-cache/wp-cache.php',
        // (2.6.5) Was 'WpFastestCache', which is also how it was displayed.
        // The spaced form is what EasyOpt_Notices::$plugin_settings keys on,
        // so this now resolves to their settings page instead of the generic
        // Plugins list. The old spelling survives as an alias needle.
        'WP Fastest Cache' => 'wp-fastest-cache/wpFastestCache.php',
        'Comet Cache'     => 'comet-cache/comet-cache.php',
        'Cache Enabler'   => 'cache-enabler/cache-enabler.php',
        'Breeze'          => 'breeze/breeze.php',
        'Surge'           => 'surge/surge.php',
        'Powered Cache'   => 'powered-cache/powered-cache.php',
        'SpeedyCache'     => 'speedycache/speedycache.php',
        'Hummingbird'     => 'hummingbird-performance/wp-hummingbird.php',
        // (2.6.0) Named so the UI can say WHICH plugin owns the drop-in.
        // These needles and paths are best-effort: the load-bearing probe is
        // the file-reference check in dropin_owner_is_live(), which catches
        // every one of these regardless. A wrong needle here degrades the
        // label to "another caching plugin" — it never breaks detection.
        'NitroPack'        => 'nitropack/main.php',
        '10Web'            => 'tenweb-speed-optimizer/tenweb-speed-optimizer.php',
        'Clearfy'          => 'clearfy/clearfy.php',
        'WP-Optimize'      => 'wp-optimize/wp-optimize.php',
        'Super Page Cache' => 'wp-cloudflare-page-cache/wp-cloudflare-super-page-cache.php',
    );

    /**
     * Is the drop-in at $target owned by a plugin that is STILL INSTALLED?
     *
     * Leftovers are safe to replace; live drop-ins are not. The primary test
     * needs no slug list and never goes stale: virtually every drop-in
     * references a file inside its own plugin folder, so if none of those
     * paths still exists the drop-in is orphaned.
     *
     * @since 2.5.7
     * @param string $target Absolute path to the drop-in.
     * @return bool
     */
    public static function dropin_owner_is_live( $target ) {

        $head = (string) @file_get_contents( $target, false, null, 0, 2048 );
        if ( '' === trim( $head ) ) {
            return false; // Empty/unreadable — nothing to protect.
        }

        // Primary probe: does the drop-in point at code that still exists?
        if ( preg_match_all( '#[\'"]([^\'"]*?(?:wp-content|plugins)/[^\'"]+\.php)[\'"]#i', $head, $m ) ) {
            foreach ( $m[1] as $ref ) {
                // (2.6.5) Drop-ins embed ABSOLUTE paths, and "absolute" is not
                // just a leading slash. On Windows it is a drive letter
                // (C:\…) or a UNC share (\\server\…), neither of which starts
                // with "/" — so those were treated as RELATIVE and had ABSPATH
                // prepended, producing "C:\site/C:\site/wp-content/…". That
                // never exists, so every reference looked dead and the branch
                // below declared a live competitor's drop-in an orphan. Easy
                // Optimizer would then overwrite it and silently switch off
                // their page cache, which is the exact failure 2.5.7 added
                // this probe to prevent.
                $path = self::is_absolute_path( $ref ) ? $ref : ABSPATH . ltrim( $ref, '/' );
                // Drop-ins written on Windows escape their backslashes for the
                // PHP string literal; we read the raw source, so undo that.
                if ( ! @file_exists( $path ) && false !== strpos( $path, '\\\\' ) ) {
                    $path = str_replace( '\\\\', '\\', $path );
                }
                if ( @file_exists( $path ) ) {
                    return true; // Owner's code is on disk — live.
                }
            }
            // References only dead paths. Before calling it an orphan, fall
            // through to the signature check: being wrong here means
            // overwriting a working plugin's cache, so the tie must break
            // toward leaving the file alone.
        }

        // Secondary: a recognised header, and that plugin is still installed.
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $label = self::match_dropin_label( $head );
        if ( '' !== $label && isset( self::$dropin_signatures[ $label ] ) ) {
            return is_plugin_active( self::$dropin_signatures[ $label ] );
        }

        return false; // Unrecognised and no live file reference — replaceable.
    }

    /**
     * Is this an absolute filesystem path on ANY platform?
     *
     * Unix "/var/www/…", Windows "C:\site\…" or "C:/site/…", and UNC
     * "\\server\share\…". A leading-slash test alone silently misclassifies
     * the last two as relative.
     *
     * @since 2.6.5
     * @param string $path
     * @return bool
     */
    private static function is_absolute_path( $path ) {
        $path = (string) $path;
        if ( '' === $path ) {
            return false;
        }
        if ( '/' === $path[0] ) {
            return true;                        // Unix absolute, or UNC "//".
        }
        if ( 0 === strpos( $path, '\\\\' ) ) {
            return true;                        // UNC share.
        }
        return (bool) preg_match( '#^[A-Za-z]:[\\\\/]#', $path ); // C:\ or C:/
    }

    /**
     * Which known plugin does this drop-in header belong to?
     *
     * Checks the display label first, then that plugin's alias needles — the
     * constants and namespaces a drop-in actually prints, which are often not
     * the plugin's marketing name (see $dropin_aliases).
     *
     * @since 2.6.5
     * @param string $head First bytes of the drop-in.
     * @return string Display label, or '' when unrecognised.
     */
    private static function match_dropin_label( $head ) {

        if ( '' === trim( (string) $head ) ) {
            return '';
        }

        foreach ( self::$dropin_signatures as $label => $file ) {
            if ( false !== stripos( $head, $label ) ) {
                return $label;
            }
            if ( empty( self::$dropin_aliases[ $label ] ) ) {
                continue;
            }
            foreach ( self::$dropin_aliases[ $label ] as $needle ) {
                if ( '' !== $needle && false !== stripos( $head, $needle ) ) {
                    return $label;
                }
            }
        }

        return '';
    }

    /**
     * Display label for a foreign drop-in's owner, for logs and the UI.
     *
     * @since 2.5.7
     * @param string $target Absolute path to the drop-in.
     * @return string
     */
    public static function foreign_dropin_owner( $target ) {

        $head = (string) @file_get_contents( $target, false, null, 0, 2048 );

        $label = self::match_dropin_label( $head );
        if ( '' !== $label ) {
            return $label;
        }

        // Fall back to the drop-in's own "Plugin Name:" header if it has one.
        if ( preg_match( '#Plugin Name:\s*(.+)#i', $head, $m ) ) {
            return trim( wp_strip_all_tags( $m[1] ) );
        }

        return __( 'another caching plugin', 'easy-optimizer' );
    }

    /**
     * Remove wp-content/advanced-cache.php IF it belongs to a specific plugin
     * that the caller has just deactivated (2.6.5).
     *
     * The setup wizard offers to deactivate a conflicting cache plugin so Easy
     * Optimizer can take over. But WordPress's deactivate_plugins() does NOT
     * remove a plugin's advanced-cache.php — and neither do most cache plugins
     * on deactivation — so the stale drop-in stays on disk. Its owner's files
     * also stay on disk (deactivation is not deletion), so dropin_owner_is_live()
     * still reads the drop-in as "owned by an installed plugin" and refuses to
     * overwrite it: Easy Optimizer then can't install its own page cache and
     * logs "Refused to overwrite advanced-cache.php owned by … Breeze".
     *
     * This is the narrow, explicit fix: only when the drop-in's owner is the
     * exact plugin just deactivated do we delete it. We never touch a drop-in
     * belonging to a DIFFERENT plugin, and the global "don't overwrite a live
     * competitor" guard is unchanged — so a plugin briefly inactive mid-update
     * is never affected, because nobody deactivated it through the wizard.
     *
     * @since 2.6.5
     * @param string $plugin_file The plugin just deactivated (e.g. 'breeze/breeze.php').
     * @return bool True if a drop-in was removed.
     */
    public static function remove_dropin_owned_by( $plugin_file ) {

        $plugin_file = (string) $plugin_file;
        if ( '' === $plugin_file || ! defined( 'WP_CONTENT_DIR' ) ) {
            return false;
        }

        $target = trailingslashit( WP_CONTENT_DIR ) . 'advanced-cache.php';
        if ( ! file_exists( $target ) || self::is_installed() ) {
            return false; // No drop-in, or it's ours — nothing to clean up.
        }

        // Which plugin owns the drop-in, and is that the one just deactivated?
        $head  = (string) @file_get_contents( $target, false, null, 0, 2048 );
        $label = self::match_dropin_label( $head );
        $owner_file = ( '' !== $label && isset( self::$dropin_signatures[ $label ] ) )
            ? self::$dropin_signatures[ $label ]
            : '';

        if ( $owner_file !== $plugin_file ) {
            return false; // The drop-in belongs to some other plugin — leave it.
        }

        $removed = @unlink( $target );
        if ( $removed ) {
            // Our installed-flag is already 0 here, but be explicit: the file is
            // gone, so a fresh install can proceed on the next Cache save.
            delete_option( 'easyopt_advanced_cache_installed' );
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::info(
                    'dropin',
                    sprintf( 'Removed stale advanced-cache.php left by deactivated plugin: %s (%s).', $label, $plugin_file )
                );
            }
        }
        return (bool) $removed;
    }

    /** Option holding the backed-up competitor drop-in (non-autoloaded). */
    const BACKUP_OPTION = 'easyopt_foreign_dropin_backup';

    /**
     * Snapshot a competitor's advanced-cache.php before we overwrite it (2.6.5).
     *
     * Stored in a non-autoloaded option: { owner, file, content, time }. The
     * raw content is kept so restore is exact; drop-ins are a few KB, well
     * within option limits, and this option is never autoloaded.
     *
     * Idempotent and conservative: it will NOT overwrite an existing backup
     * with something that is already ours (guarded by the caller's
     * !is_installed() check) and will NOT replace an existing competitor backup
     * — the FIRST competitor we displaced is the one to restore, not whatever
     * happens to be on disk on a later re-install.
     *
     * @since 2.6.5
     * @param string $target advanced-cache.php path.
     * @return void
     */
    private static function backup_foreign_dropin( $target ) {
        // Already have a competitor backup? Keep the original.
        $existing = get_option( self::BACKUP_OPTION, array() );
        if ( is_array( $existing ) && ! empty( $existing['content'] ) ) {
            return;
        }

        $content = (string) @file_get_contents( $target );
        if ( '' === $content ) {
            return;
        }
        // Never back up our own file as if it were a competitor's.
        if ( false !== strpos( $content, 'Easy Optimizer' ) ) {
            return;
        }

        $label = self::foreign_dropin_owner( $target );
        $file  = ( isset( self::$dropin_signatures[ $label ] ) ) ? self::$dropin_signatures[ $label ] : '';

        update_option( self::BACKUP_OPTION, array(
            'owner'   => $label,
            'file'    => $file,
            'content' => $content,
            'time'    => time(),
        ), false );
    }

    /**
     * Restore a backed-up competitor drop-in, if it still makes sense (2.6.5).
     *
     * Called when Easy Optimizer's page cache is turned off or the plugin is
     * uninstalled — the point at which we hand caching back.
     *
     *   • If the competitor is STILL ACTIVE → write its drop-in back so its
     *     cache works again. It would regenerate the file on its next save
     *     anyway, but restoring immediately means no gap.
     *   • If the competitor is NOT active (deactivated or removed) → do NOT
     *     restore a stale drop-in (WordPress would load it every request for a
     *     plugin that no longer runs). Just clear the backup; the caller has
     *     already removed our file.
     *
     * @since 2.6.5
     * @return bool True if a competitor drop-in was written back.
     */
    public static function restore_foreign_dropin() {

        $backup = get_option( self::BACKUP_OPTION, array() );
        if ( ! is_array( $backup ) || empty( $backup['content'] ) ) {
            return false;
        }

        // Always consume the backup — one restore attempt, then it's spent.
        delete_option( self::BACKUP_OPTION );

        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $file = isset( $backup['file'] ) ? (string) $backup['file'] : '';

        // Owner gone or deactivated → a restored drop-in would be a harmful
        // orphan. Leave it removed.
        if ( '' === $file || ! is_plugin_active( $file ) ) {
            return false;
        }

        if ( ! defined( 'WP_CONTENT_DIR' ) || ! is_writable( WP_CONTENT_DIR ) ) {
            return false;
        }

        $target = self::target_path();
        // Only restore over our own file or an empty slot — never clobber a
        // drop-in the competitor has already re-created for itself.
        if ( file_exists( $target ) && ! self::is_installed() ) {
            return false;
        }

        $ok = ( false !== @file_put_contents( $target, (string) $backup['content'], LOCK_EX ) );
        if ( $ok ) {
            if ( function_exists( 'opcache_invalidate' ) ) {
                @opcache_invalidate( $target, true );
            }
            update_option( 'easyopt_advanced_cache_installed', 0, false );
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::info(
                    'dropin',
                    sprintf( 'Restored %s advanced-cache.php after Easy Optimizer handed caching back.', (string) $backup['owner'] )
                );
            }
        }
        return $ok;
    }

    /**
     * Install / refresh the drop-in. Idempotent — safe to call repeatedly.
     *
     * As of 1.5.1 this overwrites whatever is at wp-content/advanced-cache.php
     * when that file is a LEFTOVER — the owning plugin is gone, so the file is
     * stale and (because WordPress loads it every request) actively harmful.
     *
     * (2.5.7) It no longer overwrites a drop-in whose owner is still installed.
     * See dropin_owner_is_live(). Enabling our page cache alongside an active
     * WP Rocket / LiteSpeed / W3TC now returns 'foreign_dropin' and records
     * easyopt_dropin_blocked_by instead of silently disabling their cache.
     *
     * @return bool|string True on success, or a string error code:
     *                     'no_template' | 'no_write' | 'wp_config_failed'
     *                     | 'syntax_check_failed' | 'foreign_dropin'
     */
    public static function install() {

        // cache options at once, several code paths (the save coordinator,
        // legacy update_option hooks if any third-party plugin re-fires
        // them, deactivation handlers) can all call install() in the same
        // request. The work is pure file IO and config re-export — cheap
        // individually, but ~3x called for nothing. Guard with a static
        // signature: if the same config hash is being installed again,
        // skip silently. A different signature (config changed mid-
        // request, very rare) bypasses the guard.
        static $last_signature = null;

        if ( ! self::is_enabled() ) {
            // Caller didn't actually want it installed — make sure it isn't.
            self::uninstall();
            return true;
        }

        $template_path = self::source_path();
        if ( ! file_exists( $template_path ) ) {
            return 'no_template';
        }
        $template = (string) @file_get_contents( $template_path );
        if ( '' === $template ) {
            return 'no_template';
        }

        $config = self::build_config();
        $php    = var_export( $config, true );

        // Cheap per-request guard: if this exact config was already
        // written in this request, no need to rewrite the file.
        //
        // (2.6.2) EASYOPT_VERSION is part of the signature. Previously this
        // hashed the exported CONFIG only, so a release that changed the
        // drop-in TEMPLATE — as 2.6.2 does, guarding ini_set() for hosts that
        // disable it — was never written to disk on sites whose settings had
        // not changed. They kept running the old drop-in indefinitely. Keying
        // on the version forces exactly one re-export per release.
        $signature = md5( $php . '|' . ( defined( 'EASYOPT_VERSION' ) ? EASYOPT_VERSION : '' ) );
        if ( null !== $last_signature && $signature === $last_signature ) {
            return true;
        }

        // Replace the placeholder array literal in the template with our
        // exported config. Sentinel comments make the regex unambiguous so
        // re-installs keep working even if the template is patched later.
        // Using a callback (vs. preg_replace + escape) so dollar signs in
        // user-provided strings don't get interpreted as backreferences.
        $replacement = '/*EASYOPT_CONFIG_START*/ ' . $php . ' /*EASYOPT_CONFIG_END*/';
        $count       = 0;
        $rendered    = preg_replace_callback(
            '#/\*EASYOPT_CONFIG_START\*/.*?/\*EASYOPT_CONFIG_END\*/#s',
            function () use ( $replacement ) { return $replacement; },
            $template,
            1,
            $count
        );
        if ( null === $rendered || 0 === $count ) {
            return 'no_template';
        }

        if ( ! is_dir( WP_CONTENT_DIR ) || ! is_writable( WP_CONTENT_DIR ) ) {
            return 'no_write';
        }

        $target = self::target_path();

        // (2.6.5) TAKEOVER. When a live competitor owns advanced-cache.php we
        // now take it over — same as WP Rocket, LiteSpeed and W3TC, all of
        // which overwrite advanced-cache.php unconditionally with no ownership
        // check (verified in WP Rocket 3.21: AdvancedCache::update_advanced_cache
        // calls put_contents() with no file_exists guard).
        //
        // 2.5.7 refused to do this because it silently killed the competitor's
        // cache with no restore path. We keep that concern honestly addressed:
        // BEFORE overwriting, we snapshot the competitor's drop-in and record
        // its owner. If Easy Optimizer's page cache is later turned off, or the
        // plugin is uninstalled, restore_foreign_dropin() puts it back (when the
        // owner is still active) — so the hand-off is reversible, which WP
        // Rocket's is not.
        if ( file_exists( $target ) && ! self::is_installed() && self::dropin_owner_is_live( $target ) ) {
            self::backup_foreign_dropin( $target );
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::info(
                    'dropin',
                    'Taking over advanced-cache.php from ' . self::foreign_dropin_owner( $target ) . ' (backed up for restore).'
                );
            }
            // fall through — overwrite with our drop-in below.
        }

        // Overwrite whatever is there (leftover, ours, or a just-backed-up
        // competitor). easyopt_dropin_blocked_by is legacy from the refuse era.
        delete_option( 'easyopt_dropin_blocked_by' );
        if ( false === @file_put_contents( $target, $rendered, LOCK_EX ) ) {
            // Write failed (most often: read-only wp-content).
            // We may have left a foreign drop-in on disk plus WP_CACHE=true
            // in wp-config. That's a 500 on the next request. Roll back to
            // the safest possible state: delete whatever's there and unset
            // WP_CACHE so WordPress boots normally without our cache.
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::error( 'dropin', 'Failed to write advanced-cache.php — wp-content may be read-only. Emergency disable triggered.' );
            }
            self::emergency_disable();
            return 'no_write';
        }

        // Invalidate OPcache so the new drop-in takes effect immediately
        // instead of waiting for opcache.revalidate_freq to expire.
        if ( function_exists( 'opcache_invalidate' ) ) {
            @opcache_invalidate( $target, true );
        }

        // Sanity-check: confirm the file we just wrote actually parses. If
        // disk corruption / partial write happened, we want to catch it
        // here, NOT on the next pageview where the parse error 500s the site.
        $check = self::syntax_check( $target );
        if ( true !== $check ) {
            if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
                EasyOpt_Debug_Log::error( 'dropin', 'Syntax check failed after writing advanced-cache.php: ' . (string) $check . '. Emergency disable triggered.' );
            }
            self::emergency_disable();
            return 'syntax_check_failed';
        }

        // Flip WP_CACHE on so wp-settings.php actually loads our drop-in.
        // when the constant is already true. On re-activations (the common
        // case) this is the prevailing state, so this short-circuit cuts a
        // chunk of disk I/O off the deferred install.
        if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
            if ( class_exists( 'EasyOpt_WP_Config' ) ) {
                if ( ! EasyOpt_WP_Config::set_wp_cache( true ) ) {
                    return 'wp_config_failed';
                }
            }
        }

        // Export this site's per-host config file (2.3.3). On multisite every
        // site writes its own at save time, so the shared drop-in serves each
        // site with the correct exclusions/TTL instead of the last writer's.
        self::write_site_config( $config );

        update_option( 'easyopt_advanced_cache_installed', 1, false );
        $last_signature = $signature;
        return true;
    }

    /**
     * Write wp-content/cache/easyopt/config/{host}[-{seg}].php for the
     * CURRENT site. The drop-in resolves it per request via
     * \EasyOpt\Cache\locate_site_config(). Best-effort: a failed write just
     * means the drop-in keeps using its embedded config (pre-2.3.3 behaviour).
     *
     * @since 2.3.3
     * @param array $config The exact config array embedded into the drop-in.
     * @return bool
     */
    public static function write_site_config( array $config ) {
        $dir = trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt/config/';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
            return false;
        }
        // Belt-and-suspenders: never browsable / listable.
        if ( ! file_exists( $dir . 'index.html' ) ) {
            @file_put_contents( $dir . 'index.html', '', LOCK_EX );
        }

        $home = home_url( '/' );
        $host = strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', (string) wp_parse_url( $home, PHP_URL_HOST ) ) );
        if ( '' === $host ) {
            return false;
        }
        // Subdirectory multisite: discriminate sites sharing a host by the
        // first path segment — mirrors locate_site_config()'s lookup order.
        $name = $host;
        $path = trim( (string) wp_parse_url( $home, PHP_URL_PATH ), '/' );
        if ( '' !== $path ) {
            $seg = preg_replace( '/[^a-z0-9_.\-]/i', '', (string) strtok( $path, '/' ) );
            if ( '' !== $seg ) {
                $name .= '-' . $seg;
            }
        }

        $php = "<?php\n"
            . "// Easy Optimizer per-site drop-in config — generated, do not edit.\n"
            . "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
            . 'return ' . var_export( $config, true ) . ";\n";

        $target = $dir . $name . '.php';
        $tmp    = $target . '.tmp-' . easyopt_tmp_token();
        if ( false === @file_put_contents( $tmp, $php, LOCK_EX ) ) {
            return false;
        }
        if ( ! @rename( $tmp, $target ) ) {
            @unlink( $tmp );
            return false;
        }
        if ( function_exists( 'opcache_invalidate' ) ) {
            @opcache_invalidate( $target, true );
        }
        return true;
    }

    /**
     * Emergency disable — used when install() fails partway. We MUST leave
     * the site in a state where WordPress boots, even if it means turning
     * cache off entirely. Removing the drop-in + unsetting WP_CACHE is the
     * safest pair: wp-settings.php will then no-op past the
     * `if ( WP_CACHE ) require advanced-cache.php` line.
     */
    private static function emergency_disable() {
        $target = self::target_path();
        if ( file_exists( $target ) ) {
            @unlink( $target );
        }
        if ( class_exists( 'EasyOpt_WP_Config' ) ) {
            EasyOpt_WP_Config::unset_wp_cache();
        }
        update_option( 'easyopt_advanced_cache_installed', 0, false );
        update_option( 'easyopt_dropin_install_failed_at', time(), false );
    }

    /**
     * Lint a generated drop-in file via `php -l` if available, else
     * via a defensive include in a sandboxed try/catch. Returns true on
     * success, error string otherwise.
     */
    private static function syntax_check( $path ) {
        // here previously. token_get_all(TOKEN_PARSE) gives the same
        // coverage (PHP throws ParseError on malformed input) without the
        // overhead of forking a php binary on every install. The fork was
        // ~100–300 ms on shared hosts and contributed materially to the
        // activation CPU spike on hosts where activation includes a
        // re-install of the drop-in.
        $contents = @file_get_contents( $path );
        if ( false === $contents ) {
            return 'unreadable';
        }
        try {
            $tokens = @token_get_all( $contents, TOKEN_PARSE );
            if ( ! is_array( $tokens ) || empty( $tokens ) ) {
                return 'parse_failed';
            }
        } catch ( \ParseError $e ) {
            return 'parse_error: ' . $e->getMessage();
        } catch ( \Error $e ) {
            return 'error: ' . $e->getMessage();
        }
        return true;
    }

    /** @deprecated 1.5.6 — kept as a stub so any user-land callers don't fatal. */
    private static function shell_exec_disabled() {
        return true;
    }

    /**
     * Remove the drop-in if (and only if) it's ours.
     */
    public static function uninstall() {

        $target = self::target_path();
        if ( file_exists( $target ) && self::is_installed() ) {
            @unlink( $target );
        }

        // (2.6.5) Hand caching back. If we took over a competitor's drop-in,
        // restore it now (when that competitor is still active). Must run AFTER
        // our file is removed so restore_foreign_dropin() can write into the
        // empty slot. When there's no backup this is a cheap no-op.
        self::restore_foreign_dropin();

        // Only unset WP_CACHE if nothing else now owns the drop-in — a restore
        // above may have put a competitor's cache back, which still needs it.
        $restored = file_exists( $target ) && ! self::is_installed();
        if ( ! $restored && class_exists( 'EasyOpt_WP_Config' ) ) {
            EasyOpt_WP_Config::unset_wp_cache();
        }

        update_option( 'easyopt_advanced_cache_installed', 0, false );
        return true;
    }

    /**
     * The drop-in is active whenever cache is on. The user-facing toggle
     * was removed in 1.5.1 — letting the plugin auto-fall-back to the
     * runtime PHP path was strictly slower than the drop-in for every host
     * we tested, so the option only existed as a footgun.
     */
    public static function is_enabled() {
        if ( ! (int) EasyOpt_Config::get( 'cache', 0 ) ) {
            return false;
        }
        return (bool) apply_filters( 'easyopt_use_advanced_cache_dropin', true );
    }

    /**
     * Build the config array embedded into the drop-in. Every value here
     * comes from options — no closures, no objects — so var_export()
     * round-trips cleanly and the drop-in can be parsed without loading
     * any of our classes.
     */
    /**
     * (2.6.1) The include-cookie list the CURRENTLY INSTALLED drop-in is using.
     *
     * Read back from the exported per-site config rather than recomputed, so
     * callers can tell whether the snapshot on disk still agrees with what the
     * writer would key on now. EasyOpt_Cache::on_plugin_set_changed() uses it
     * to decide whether a plugin activation invalidated the cache key.
     *
     * @return string[] Empty when nothing is installed or readable.
     */
    public static function exported_include_cookies() {
        $dir  = trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt/config';
        $host = strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
        $file = $dir . '/' . $host . '.php';
        if ( '' === $host || ! is_file( $file ) ) {
            return array();
        }
        $cfg = include $file;
        if ( ! is_array( $cfg ) || empty( $cfg['include_cookies'] ) ) {
            return array();
        }
        return array_values( array_filter( array_map( 'strval', (array) $cfg['include_cookies'] ) ) );
    }

    private static function build_config() {

        $cache_dir = trailingslashit( WP_CONTENT_DIR ) . 'cache/easyopt';

        $exclude_urls = self::lines_from_option( 'easyopt_cache_exclude_urls' );
        // Ship the always-on URL exclusions baked in.
        $exclude_urls = array_values( array_unique( array_merge( $exclude_urls, array(
            '/cart', '/checkout', '/my-account', '/wc-api',
            '?nocache', 'preview=true',
        ) ) ) );

        // When WooCommerce uses custom or translated slugs (e.g. /panier,
        // /kasse), bake the real cart/checkout/account paths so the drop-in
        // also bypasses them at serve time. The write-time
        // `easyopt_cache_html_cacheable` filter already prevents these pages
        // being cached; this additionally guards against any stale file left
        // by an older build. Mirrors EasyOpt_Config::is_woo_dynamic_page().
        if ( function_exists( 'wc_get_page_permalink' ) ) {
            foreach ( array( 'cart', 'checkout', 'myaccount' ) as $easyopt_wc_page ) {
                $easyopt_wc_url  = wc_get_page_permalink( $easyopt_wc_page );
                $easyopt_wc_path = $easyopt_wc_url ? wp_parse_url( $easyopt_wc_url, PHP_URL_PATH ) : '';
                if ( is_string( $easyopt_wc_path ) && '' !== $easyopt_wc_path && '/' !== $easyopt_wc_path ) {
                    $exclude_urls[] = rtrim( $easyopt_wc_path, '/' );
                }
            }
            $exclude_urls = array_values( array_unique( $exclude_urls ) );
        }

        $exclude_cookies = self::lines_from_option( 'easyopt_cache_exclude_cookies' );
        // (2.5.5) WooCommerce cookies removed here too — this list is the
        // drop-in's own copy and is consulted BEFORE WordPress loads, so
        // leaving them would have kept the cache bypass alive on the PHP
        // serving path even after the main list was cleaned. See the long
        // note in EasyOpt_Cache::get_cookie_exclusions() for the reasoning.
        $defaults_cookies = array( 'edd_items_in_cart', 'wp-postpass_' );
        if ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'get_cookie_exclusions' ) ) {
            // Prefer the canonical list (honours the filter) when available.
            $defaults_cookies = (array) EasyOpt_Cache::get_cookie_exclusions();
        }
        $exclude_cookies = array_values( array_unique( array_merge( $exclude_cookies, $defaults_cookies ) ) );

        // (2.6.6) When "cache for logged-in users" is ON, the auth cookie is the
        // cache KEY (role-varied filenames), not a bypass — exactly as the
        // runtime writer treats it in EasyOpt_Cache::has_excluding_cookie(). The
        // exported exclude list still carried 'wordpress_logged_in_' from the
        // defaults above, so the drop-in's generic exclusion loop MISSed on
        // every logged-in visitor's own auth cookie BEFORE reaching its
        // dedicated logged-in/role block — the page was generated and rewritten
        // on every hit but never served (reported: logged-in pages stuck on
        // MISS). Strip ONLY the auth-cookie prefix here; wp-postpass_ / session
        // / cart cookies stay so password-protected and per-user pages still
        // bypass, and the drop-in's own logged-in detection still MISSes when
        // this toggle is off.
        if ( 1 === (int) EasyOpt_Config::get( 'cache_logged_in', 0 ) ) {
            $exclude_cookies = array_values( array_filter(
                $exclude_cookies,
                static function ( $easyopt_ck ) {
                    return 0 !== strpos( (string) $easyopt_ck, 'wordpress_logged_in_' );
                }
            ) );
        }

        // Tracking-only params. (2.5.5) default_strip_params() now delegates
        // to EasyOpt_Cache when it is loaded, so the two lists cannot drift.
        $strip = self::lines_from_option( 'easyopt_cache_strip_query_params' );
        $strip = array_values( array_unique( array_merge( self::default_strip_params(), $strip ) ) );

        // (2.6.1) APPLY THE FILTER. This line was missing.
        //
        // EasyOpt_Cache::get_strip_params() dispatches
        // easyopt_cache_strip_query_params; this builder did not. The drop-in
        // runs BEFORE WordPress loads and decides HIT/MISS from the snapshot
        // written here, so a developer filtering a parameter out of the strip
        // list changed the runtime copy and nothing else — the drop-in kept
        // stripping it, served the cached file, and exited. The filter
        // appeared to do nothing at all, with no error and no warning.
        //
        // Dispatched in the same position as the runtime — after the user's
        // list is merged, before the reserved list is subtracted — so both
        // paths compute an identical result. build_config() already applies
        // easyopt_cache_include_cookies and easyopt_cache_cdn_max_age below,
        // so this was an omission rather than a decision.
        $strip = (array) apply_filters( 'easyopt_cache_strip_query_params', $strip );

        // (2.6.0) Mirror the runtime guard: the drop-in strips these from $_GET
        // BEFORE testing whether any query remains, so a reserved param landing
        // in this list would turn a deliberate cache-buster (?nooptimize,
        // ?nocache, the PSI bypass token) into a silent cache HIT. Subtracted
        // here, after the user's own list is merged in, exactly as
        // EasyOpt_Cache::get_strip_params() does for the PHP serve path.
        if ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'get_reserved_query_params' ) ) {
            $strip = array_values( array_diff( $strip, EasyOpt_Cache::get_reserved_query_params() ) );
        }

        // (2.6.1) Union in the "Cache Query String" names, exactly as
        // EasyOpt_Cache::get_strip_params() does and in the same position.
        // Without this the drop-in would see a bespoke keyed parameter still
        // sitting in $_GET, decide the request was uncacheable and MISS —
        // while the writer, whose list DID contain it, kept happily writing
        // the variant nobody would ever read.
        if ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'get_query_string_params' ) ) {
            $strip = array_values( array_unique( array_merge(
                $strip,
                EasyOpt_Cache::get_query_string_params()
            ) ) );
        }

        $include_cookies = (array) apply_filters( 'easyopt_cache_include_cookies', array() );

        // (2.6.1) "Cache Query String". Resolved through the canonical getter
        // so the deny-list of per-click identifiers, the reserved-param
        // subtraction and the easyopt_cache_query_strings filter all apply
        // exactly once, in one place, for both serve paths.
        $query_strings = ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'get_query_string_params' ) )
            ? (array) EasyOpt_Cache::get_query_string_params()
            : array();

        return array(
            'cache_dir'           => $cache_dir,
            // Shared decision logic loaded by the drop-in (2.3.3). If the
            // plugin folder disappears, the drop-in fails safe to a MISS.
            'common'              => trailingslashit( EASYOPT_DIR ) . 'includes/cache/cache-common.php',
            // Per-site config directory — lets one shared drop-in serve every
            // multisite site with its OWN settings (2.3.3).
            'config_dir'          => $cache_dir . '/config',
            // (2.6.1) Host resolution inputs. The drop-in applies the SAME
            // \EasyOpt\Cache\resolve_cache_host() the writer does; without
            // these it keyed on the raw request host while the writer folded
            // unrecognised hosts onto the home host, so every domain alias
            // silently missed the cache on every request.
            'home_host'           => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
            'known_hosts'         => ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'known_cache_hosts' ) )
                                     ? (array) EasyOpt_Cache::known_cache_hosts() : array(),
            'ttl'                 => (int) EasyOpt_Config::get( 'cache_ttl', 0 ),
            'separate_mobile'     => (int) EasyOpt_Config::get( 'cache_separate_mobile', 1 ),
            // (2.5.7) Exported so the drop-in honours the Gzip toggle on the
            // SERVE path too. Previously the setting only controlled the
            // .htaccess mod_deflate block, so turning Gzip off still served
            // (and generated) pre-compressed files.
            'gzip'                => (int) EasyOpt_Config::get( 'cache_gzip', 1 ),
            // (2.5.7) OpenLiteSpeed compresses natively on the way out and
            // ignores the SetEnvIfNoCase no-gzip guard we emit for Apache (it
            // parses only mod_rewrite from .htaccess). Handing it
            // pre-compressed bytes risks double compression, which the browser
            // cannot decode — it downloads the file instead of rendering it.
            'is_ols'              => ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'is_openlitespeed' ) )
                                     ? (int) EasyOpt_Cache::is_openlitespeed() : 0,
            'cache_logged_in'     => (int) EasyOpt_Config::get( 'cache_logged_in', 0 ),
            'cdn_max_age'         => (int) apply_filters( 'easyopt_cache_cdn_max_age', 2592000 ), // 30 days
            'exclude_urls'        => $exclude_urls,
            'exclude_cookies'     => $exclude_cookies,
            'strip_query_params'  => $strip,
            'include_cookies'     => array_values( array_filter( array_map( 'strval', $include_cookies ) ) ),
            'query_strings'       => array_values( array_filter( array_map( 'strval', $query_strings ) ) ),
        );
    }

    /** Mirrors EasyOpt_Cache::get_default_strip_params() so the drop-in is independent of the runtime class. */
    public static function default_strip_params() {
        // (2.5.5) Single source of truth. This list used to be a hand-copied
        // duplicate of EasyOpt_Cache::get_default_strip_params() carrying a
        // "keep in sync" comment — and it drifted the moment that list grew.
        // Config generation always runs inside WordPress, so the canonical
        // list is available; the literal below stays only as a fallback for
        // the (theoretical) case where it is not.
        if ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'get_default_strip_params' ) ) {
            return (array) EasyOpt_Cache::get_default_strip_params();
        }

        // (2.6.1) Resynced with EasyOpt_Cache::get_default_strip_params().
        // This literal is unreachable in practice — the delegation above
        // always wins, because build_config() only ever runs inside WordPress
        // — but it had drifted badly (no email-marketing block, no hsa_*, no
        // eopreload/eonobuf/eoslot) and a stale copy of a list this important
        // is a trap for whoever reads it next and assumes it is current.
        return array(
            // Google Analytics / Ads
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'utm_id', 'utm_name', 'utm_brand', 'utm_social', 'utm_social-type',
            'utm_expid',
            'gclid', 'gbraid', 'wbraid', 'gclsrc', 'dclid', 'gad_source', 'gad_campaignid',
            'gadid', 'srsltid',
            '_ga', '_gl',
            // Meta
            'fbclid', 'fb_action_ids', 'fb_action_types', 'fb_source', 'fbadid',
            // Mailchimp
            'mc_cid', 'mc_eid',
            // Microsoft
            'msclkid',
            // Yandex
            'yclid',
            // Twitter / X
            'twclid',
            // TikTok
            'ttclid',
            // Instagram share
            'igshid',
            // HubSpot
            '_hsenc', '_hsmi', 'hsCtaTracking',
            // Matomo / Piwik
            'mtm_campaign', 'mtm_cid', 'mtm_content', 'mtm_keyword', 'mtm_medium', 'mtm_source',
            'pk_campaign', 'pk_cid', 'pk_content', 'pk_keyword', 'pk_medium', 'pk_source',
            // GA4 / newer UTM variants
            'utm_source_platform', 'utm_creative_format', 'utm_marketing_tactic',
            // Google AMP viewer
            'usqp',
            // Email marketing
            'mkt_tok', '_kx', '_ke', 'ck_subscriber_id',
            'ml_subscriber', 'ml_subscriber_hash', 'omnisendContactID', 'vgo_ee',
            '_bta_tid', '_bta_c', 'elqTrack', 'elqTrackId',
            // Social / ad platforms
            'li_fat_id', 'rdt_cid', 'ScCid', 'sc_cid', 'irclickid', 'wickedid',
            // HubSpot paid search
            'hsa_acc', 'hsa_cam', 'hsa_grp', 'hsa_ad', 'hsa_src',
            'hsa_tgt', 'hsa_kw', 'hsa_mt', 'hsa_net', 'hsa_ver',
            // Matomo / Piwik extras
            'mtm_group', 'mtm_placement', 'pk_kwd',
            // AT Internet
            'at_medium', 'at_campaign',
            // Misc ad
            'adgroupid', 'adid', 'campaignid', 'mkwid', 'pcrid',
            'ef_id', 'epik', 'sscid', 's_kwcid', 'cn-reloaded',
            // Misc tracking
            'ref', 'referrer', 'redirect_log_mongo_id', 'redirect_mongo_id',
            'sb_referer_host', 'pp', 'age-verified',
            'trk_contact', 'trk_msg', 'trk_module', 'trk_sid',
            'dm_i', 'gdfms', 'gdftrk', 'gdffi', 'kboard_id',
            // Optimisation bypasses (they cause MISS deliberately)
            'ao_noptimize',
            // Our own preload markers
            'eopreload', 'eonobuf', 'eoslot',
        );
    }

    /** Helper: split a textarea option into a clean array of trimmed lines. */
    private static function lines_from_option( $name ) {
        $raw = (string) get_option( $name, '' );
        if ( '' === $raw ) {
            return array();
        }
        $lines = preg_split( '/\r\n|\r|\n/', $raw );
        $out   = array();
        foreach ( (array) $lines as $line ) {
            $line = trim( $line );
            if ( '' !== $line ) {
                $out[] = $line;
            }
        }
        return $out;
    }
}
