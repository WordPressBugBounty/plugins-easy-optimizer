<?php
/**
 * Usage tracking v2 — explicit opt-in, 12 typed events, meta payloads.
 *
 * No data is sent until the administrator opts in (wizard checkbox or the
 * Settings toggle). Everything below is gated on that consent.
 *
 * Events sent (only after opt-in):
 *  Lifecycle:    activation, deactivation (+reason), heartbeat, uninstall
 *  Smart Images: smartimg_tab_viewed, smartimg_connect_attempted,
 *                smartimg_delivering, smartimg_fallback (+reason),
 *                smartimg_disconnected
 *  Core health:  wizard_completed (+preset), safe_mode_triggered (+trigger),
 *                error_occurred (+code, throttled 1/code/day)
 *
 * Payload fields: site_id (SHA-256 of normalised domain + AUTH_SALT),
 * site_url, plugin slug/version, WP/PHP version, server software, hosting
 * provider, WooCommerce flag, and — on activation/heartbeat — feature flags
 * marked user-set ("u") vs default ("d").
 *
 * Never collected: email, IP address, theme, locale, site content, or any
 * personal data beyond the site URL itself.
 *
 * Deactivation feedback: the one-click reason modal is shown to everyone.
 * For opted-in sites the reason rides on the normal deactivation event. For
 * sites that never opted in, clicking "Submit" is explicit consent for a
 * single minimal message (reason, note, plugin version, site URL) — nothing
 * is sent if they Skip.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Tracker {

    /* ── Constants ──────────────────────────────────────────────────────── */

    const ENDPOINT       = 'https://fluxpress.io/wp-json/fluxpress/v1/track';
    const API_TOKEN      = 'RHk36MDnYeeqncpx2c6qrLDJFSKW0zRa4IkJ8zmJbTQe21ir';
    const CRON_HOOK      = 'easyopt_weekly_tracking';
    const OPTIN_KEY      = 'easyopt_tracking_optin';     // 'yes' | 'later' | 'no' | ''
    const OPTIN_ASKED    = 'easyopt_tracking_asked_at';
    const VERSION_KEY    = 'easyopt_tracked_version';
    const PLUGIN_SLUG    = 'easy-optimizer';
    const REASK_INTERVAL = 604800;

    /** Pending deactivation reason (written by the modal, read by the hook). */
    const DEACT_REASON_KEY = 'easyopt_deact_reason';

    /** Once-flags so funnel events fire on transitions, not on every check. */
    const DELIVERING_FLAG = 'easyopt_trk_delivering_sent';
    const QUOTA_FLAG      = 'easyopt_trk_quota_fallback';

    /* ── Bootstrap ──────────────────────────────────────────────────────── */

    public static function register() {
        register_activation_hook( EASYOPT_PLUGIN_FILE, array( __CLASS__, 'on_activation' ) );
        register_deactivation_hook( EASYOPT_PLUGIN_FILE, array( __CLASS__, 'on_deactivation' ) );
        add_action( self::CRON_HOOK, array( __CLASS__, 'send_heartbeat' ) );
        add_action( 'admin_init', array( __CLASS__, 'check_update' ) );

        // Consent is collected by the setup wizard / Settings toggle.
        add_action( 'wp_ajax_easyopt_tracking_optin', array( __CLASS__, 'ajax_optin' ) );

        // Deactivation-reason modal (plugins screen only, opted-in only).
        add_action( 'wp_ajax_easyopt_deact_reason', array( __CLASS__, 'ajax_deact_reason' ) );
        add_action( 'admin_footer-plugins.php', array( __CLASS__, 'render_deact_modal' ) );

        // Loose-coupling entry point: do_action( 'easyopt_track_error', 'EO_CODE' )
        add_action( 'easyopt_track_error', array( __CLASS__, 'error' ), 10, 2 );

        // Smart Images tab-view ping (fired by a tiny admin script when the
        // Smart Images tab is opened — connection state irrelevant, so the
        // TOP of the funnel is measured, not just already-connected sites).
        add_action( 'rest_api_init', function() {
            register_rest_route( 'easyopt/v1', '/track/si-view', array(
                'methods'             => 'POST',
                'callback'            => function() {
                    EasyOpt_Tracker::smartimg_tab_viewed();
                    return rest_ensure_response( array( 'ok' => true ) );
                },
                'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            ) );
        } );
    }

    /* ── Opt-in state ───────────────────────────────────────────────────── */

    public static function is_opted_in() {
        return get_option( self::OPTIN_KEY, '' ) === 'yes';
    }

    public static function set_optin( $value ) {
        update_option( self::OPTIN_KEY, $value, true );
        if ( $value === 'yes' ) {
            self::send( 'activation' );
            update_option( self::VERSION_KEY, EASYOPT_VERSION, true );
            if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
                wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_HOOK );
            }
        } elseif ( $value === 'no' || $value === 'later' ) {
            update_option( self::OPTIN_ASKED, time(), true );
            $ts = wp_next_scheduled( self::CRON_HOOK );
            if ( $ts ) {
                wp_unschedule_event( $ts, self::CRON_HOOK );
            }
        }
    }

    public static function ajax_optin() {
        check_ajax_referer( 'easyopt_tracking_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $choice = isset( $_POST['choice'] ) ? sanitize_key( $_POST['choice'] ) : '';
        if ( ! in_array( $choice, array( 'yes', 'later', 'no' ), true ) ) {
            wp_send_json_error( 'Invalid choice', 400 );
        }
        self::set_optin( $choice );
        wp_send_json_success();
    }

    /* ── Lifecycle events ───────────────────────────────────────────────── */

    public static function check_update() {
        if ( ! self::is_opted_in() ) {
            return;
        }
        $stored = get_option( self::VERSION_KEY, '' );
        if ( $stored === EASYOPT_VERSION ) {
            return;
        }
        // Version changed. No dedicated 'update' event (server derives version
        // adoption from the latest row) — an immediate heartbeat carries the
        // new version + fresh flags so release-health data lands within
        // minutes of the update instead of at the next weekly beat.
        $event = empty( $stored ) ? 'activation' : 'heartbeat';
        self::send( $event );
        update_option( self::VERSION_KEY, EASYOPT_VERSION, true );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_HOOK );
        }
    }

    public static function on_activation() {
        if ( get_option( self::OPTIN_KEY, '' ) === '' ) {
            add_option( self::OPTIN_KEY, '', '', 'yes' );
        }
        // Reactivation of an opted-in site: previously nothing fired here and
        // — worse — the weekly cron was never rescheduled when the version
        // hadn't changed (check_update() returns early), so a deactivate →
        // reactivate cycle silently killed all future heartbeats. Send the
        // activation (server dedupes within 24h) and restore the cron.
        if ( self::is_opted_in() ) {
            self::send( 'activation' );
            update_option( self::VERSION_KEY, EASYOPT_VERSION, true );
            if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
                wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::CRON_HOOK );
            }
        }
    }

    public static function on_deactivation() {
        $stored = get_option( self::DEACT_REASON_KEY, array() );
        $meta   = array();
        if ( is_array( $stored ) && ! empty( $stored['reason'] ) ) {
            $meta['reason'] = sanitize_key( $stored['reason'] );
            if ( ! empty( $stored['note'] ) ) {
                $meta['note'] = mb_substr( sanitize_text_field( $stored['note'] ), 0, 300 );
            }
        }

        if ( self::is_opted_in() ) {
            self::send( 'deactivation', $meta );
        } elseif ( ! empty( $meta['reason'] ) ) {
            // Not opted in, but they clicked "Submit" on the reason modal —
            // that is explicit consent for this ONE minimal message.
            self::send_minimal_deactivation( $meta );
        }

        delete_option( self::DEACT_REASON_KEY );
        // NOTE: VERSION_KEY is intentionally KEPT. Deleting it made every
        // reactivation look like a brand-new install to check_update().
        delete_option( self::DELIVERING_FLAG );
        $ts = wp_next_scheduled( self::CRON_HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::CRON_HOOK );
        }
    }

    public static function send_heartbeat() {
        if ( ! self::is_opted_in() ) {
            return;
        }
        self::send( 'heartbeat' );
    }

    /* ── Core-health events ─────────────────────────────────────────────── */

    /**
     * Fire an error event, throttled to once per code per day.
     * Also reachable via do_action( 'easyopt_track_error', $code, $extra ).
     *
     * @param string $code  Short stable code, e.g. 'EO_PRELOAD_TIMEOUT'.
     * @param array  $extra Optional small context (kept tiny on purpose).
     */
    public static function error( $code, $extra = array() ) {
        if ( ! self::is_opted_in() ) {
            return;
        }
        $code = strtoupper( preg_replace( '/[^A-Za-z0-9_]/', '', (string) $code ) );
        if ( '' === $code ) {
            return;
        }
        $tkey = 'easyopt_trk_err_' . md5( $code );
        if ( get_transient( $tkey ) ) {
            return; // Already reported today.
        }
        set_transient( $tkey, 1, DAY_IN_SECONDS );
        $meta = array( 'code' => $code );
        if ( is_array( $extra ) && ! empty( $extra ) ) {
            $meta['extra'] = array_slice( array_map( 'sanitize_text_field', array_map( 'strval', $extra ) ), 0, 3 );
        }
        self::send( 'error_occurred', $meta );
    }

    /** Wizard finished — {preset, smartimg_taken}. */
    public static function wizard_completed( $preset ) {
        $taken = ( class_exists( 'EasyOpt_Config' ) && 1 === (int) EasyOpt_Config::get( 'fluxcdn_verified', 0 ) ) ? 1 : 0;
        self::send( 'wizard_completed', array(
            'preset'         => sanitize_key( $preset ),
            'smartimg_taken' => $taken,
        ) );
    }

    /** Safe Mode engaged — {trigger: user|auto}. */
    public static function safe_mode_triggered( $trigger = 'user' ) {
        self::send( 'safe_mode_triggered', array( 'trigger' => sanitize_key( $trigger ) ) );
    }

    /* ── Smart Images funnel ────────────────────────────────────────────── */

    /** Smart Images tab opened (throttled to once per day). */
    public static function smartimg_tab_viewed() {
        if ( get_transient( 'easyopt_trk_si_tab' ) ) {
            return;
        }
        // Was DAY_IN_SECONDS. Every funnel event downstream of this one is
        // per-action, and a per-action numerator over a per-site-per-DAY
        // denominator is not a conversion rate — it made the first row of
        // the funnel uncomputable.
        set_transient( 'easyopt_trk_si_tab', 1, HOUR_IN_SECONDS );
        self::send( 'smartimg_tab_viewed' );
    }

    /** User submitted a license / key — {mode: license|key}. */
    public static function smartimg_connect_attempted( $mode = 'license' ) {
        self::send( 'smartimg_connect_attempted', array( 'mode' => sanitize_key( $mode ) ) );
    }

    /**
     * Probe-state transition. Called from connect / test / reprobe whenever a
     * fresh probe state is known. Emits:
     *  - smartimg_delivering ONCE per connection when state becomes 'ok'
     *  - smartimg_fallback {reason} (daily-throttled) for stuck states
     */
    public static function smartimg_state( $state ) {
        $state = sanitize_key( (string) $state );

        if ( 'ok' === $state ) {
            if ( ! get_option( self::DELIVERING_FLAG ) ) {
                update_option( self::DELIVERING_FLAG, time(), false );
                self::send( 'smartimg_delivering' );
            }
            return;
        }

        $reason = '';
        if ( 'origin_blocked' === $state ) {
            $reason = 'firewall';
        } elseif ( 'ssl_pending' === $state || 'warming' === $state ) {
            // Transient by design — only report if it persists past the
            // self-heal window (first sighting sets a marker; a repeat
            // sighting 10+ minutes later reports it).
            $first = (int) get_option( 'easyopt_trk_ssl_first', 0 );
            if ( ! $first ) {
                update_option( 'easyopt_trk_ssl_first', time(), false );
                return;
            }
            if ( ( time() - $first ) < 600 ) {
                return;
            }
            $reason = 'ssl';
        }
        if ( '' === $reason ) {
            return;
        }
        self::smartimg_fallback( $reason );
    }

    /** Delivery interrupted — {reason: quota|firewall|ssl|revoked|moved|error}. */
    public static function smartimg_fallback( $reason ) {
        $reason = sanitize_key( $reason );
        $tkey   = 'easyopt_trk_si_fb_' . $reason;
        if ( get_transient( $tkey ) ) {
            return; // One report per reason per day.
        }
        set_transient( $tkey, 1, DAY_IN_SECONDS );
        self::send( 'smartimg_fallback', array( 'reason' => $reason ) );
    }

    /** User disconnected the license from this site. */
    public static function smartimg_disconnected() {
        delete_option( self::DELIVERING_FLAG );
        delete_option( 'easyopt_trk_ssl_first' );
        self::send( 'smartimg_disconnected' );
    }

    /** Quota crossing detector — call with fresh usage numbers. */
    public static function smartimg_quota_check( $used, $quota ) {
        if ( $quota <= 0 ) {
            return;
        }
        if ( $used >= $quota ) {
            if ( ! get_option( self::QUOTA_FLAG ) ) {
                update_option( self::QUOTA_FLAG, time(), false );
                self::smartimg_fallback( 'quota' );
            }
        } else {
            delete_option( self::QUOTA_FLAG ); // back under quota → re-arm
        }
    }

    /* ── Deactivation-reason modal (plugins.php) ────────────────────────── */

    public static function ajax_deact_reason() {
        check_ajax_referer( 'easyopt_deact_nonce', 'nonce' );
        if ( ! current_user_can( 'activate_plugins' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }
        $reason  = sanitize_key( $_POST['reason'] ?? '' );
        $allowed = array( 'temporary', 'broke_site', 'switching', 'too_complex', 'missing_feature', 'no_longer_needed', 'other' );
        if ( ! in_array( $reason, $allowed, true ) ) {
            wp_send_json_error( 'Invalid reason', 400 );
        }
        update_option( self::DEACT_REASON_KEY, array(
            'reason' => $reason,
            'note'   => mb_substr( sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ), 0, 300 ),
        ), false );
        wp_send_json_success();
    }

    public static function render_deact_modal() {
        // Shown to EVERYONE — for non-opted-in sites the Submit button itself
        // is the (single-message) consent; Skip sends nothing at all.
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        $opted   = self::is_opted_in();
        $nonce   = wp_create_nonce( 'easyopt_deact_nonce' );
        $reasons = array(
            'temporary'        => __( 'Temporary — debugging or testing something', 'easy-optimizer' ),
            'broke_site'       => __( 'It broke my site or caused a conflict', 'easy-optimizer' ),
            'switching'        => __( 'Switching to another optimization plugin', 'easy-optimizer' ),
            'too_complex'      => __( 'Too complicated to set up', 'easy-optimizer' ),
            'missing_feature'  => __( "It's missing a feature I need", 'easy-optimizer' ),
            'no_longer_needed' => __( "I no longer need it on this site", 'easy-optimizer' ),
            'other'            => __( 'Other', 'easy-optimizer' ),
        );
        ?>
        <div id="easyopt-deact-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100000;">
            <div style="max-width:440px;margin:8vh auto 0;background:#fff;border-radius:8px;padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.3);">
                <h2 style="margin:0 0 6px;font-size:16px;"><?php esc_html_e( 'Quick question before you go', 'easy-optimizer' ); ?></h2>
                <p style="margin:0 0 14px;color:#50575e;font-size:13px;"><?php esc_html_e( 'Why are you deactivating Easy Optimizer? One click helps us fix the right things.', 'easy-optimizer' ); ?></p>
                <div id="easyopt-deact-reasons">
                    <?php foreach ( $reasons as $key => $label ) : ?>
                        <label style="display:block;margin:0 0 8px;font-size:13px;cursor:pointer;">
                            <input type="radio" name="easyopt_deact_reason" value="<?php echo esc_attr( $key ); ?>" style="margin-right:6px;">
                            <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <textarea id="easyopt-deact-note" rows="2" placeholder="<?php esc_attr_e( 'Anything else? (optional)', 'easy-optimizer' ); ?>" style="display:none;width:100%;margin:6px 0 0;font-size:13px;"></textarea>
                <p style="font-size:11px;color:#8c8f94;margin:12px 0 0;line-height:1.5;">
                    <?php esc_html_e( 'Sending this submits a single message — your reason, plugin version, and site URL. Nothing else.', 'easy-optimizer' ); ?>
                </p>
                <p style="margin:12px 0 0;display:flex;gap:10px;align-items:center;">
                    <button type="button" class="button button-primary" id="easyopt-deact-submit" disabled><?php esc_html_e( 'Submit & Deactivate', 'easy-optimizer' ); ?></button>
                    <a href="#" id="easyopt-deact-skip" style="font-size:12px;color:#50575e;text-decoration:underline;"><?php esc_html_e( 'Skip & Deactivate', 'easy-optimizer' ); ?></a>
                    <a href="#" id="easyopt-deact-cancel" style="font-size:12px;color:#50575e;margin-left:auto;"><?php esc_html_e( 'Cancel', 'easy-optimizer' ); ?></a>
                </p>
            </div>
        </div>
        <script>
        (function(){
            var link = document.querySelector('[data-slug="easy-optimizer"] .deactivate a, #deactivate-easy-optimizer');
            if (!link) { return; }
            var overlay = document.getElementById('easyopt-deact-overlay');
            var submit  = document.getElementById('easyopt-deact-submit');
            var note    = document.getElementById('easyopt-deact-note');
            var href    = '';

            link.addEventListener('click', function(e){
                e.preventDefault();
                href = link.href;
                overlay.style.display = 'block';
            });
            overlay.addEventListener('click', function(e){ if (e.target === overlay) { overlay.style.display = 'none'; } });
            document.getElementById('easyopt-deact-cancel').addEventListener('click', function(e){
                e.preventDefault(); overlay.style.display = 'none';
            });
            document.querySelectorAll('input[name="easyopt_deact_reason"]').forEach(function(r){
                r.addEventListener('change', function(){
                    submit.disabled = false;
                    note.style.display = (this.value === 'other' || this.value === 'broke_site' || this.value === 'missing_feature') ? 'block' : 'none';
                });
            });
            document.getElementById('easyopt-deact-skip').addEventListener('click', function(e){
                e.preventDefault(); window.location = href;
            });
            submit.addEventListener('click', function(){
                var sel = document.querySelector('input[name="easyopt_deact_reason"]:checked');
                if (!sel) { window.location = href; return; }
                submit.disabled = true;
                var data = new FormData();
                data.append('action', 'easyopt_deact_reason');
                data.append('nonce', '<?php echo esc_js( $nonce ); ?>');
                data.append('reason', sel.value);
                data.append('note', note.value || '');
                fetch(ajaxurl, {method:'POST', body:data, credentials:'same-origin'})
                    .finally(function(){ window.location = href; });
                // Fail-safe: never trap the user if the request hangs.
                setTimeout(function(){ window.location = href; }, 1500);
            });
        })();
        </script>
        <?php
    }

    /* ── Feature flags (tri-state: user-set vs default) ─────────────────── */

    /**
     * Each flag is encoded "u1" / "u0" (user explicitly set it on/off) or
     * "d1" / "d0" (running on the shipped default). Distinguishing the two is
     * what makes adoption numbers mean something: "d1" measures our defaults,
     * "u1" measures a decision.
     */
    private static function get_feature_flags() {
        $toggles = array(
            'cache', 'cache_preload', 'cache_separate_mobile', 'cache_logged_in',
            'cache_browser_caching', 'cache_gzip',
            'lazy_images', 'lazy_iframes', 'lazy_videos', 'add_missing_dims',
            'delay_js', 'unused_css',
            'minify_css', 'minify_js',
            'font_display_swap', 'lazyload_fonts',
            'lcp_preload', 'elementor_bg_cdn',
            'instant_preload', 'instant_prerender',
            'bloat_emojis', 'bloat_dashicons', 'bloat_embeds', 'bloat_xmlrpc',
            'bloat_rss_feeds', 'bloat_heartbeat', 'bloat_jquery_migrate',
            'bloat_wc_cart_fragments',
            'seo_crawlable_links', 'seo_image_alts',
            'img_opt', // Smart Images delivery toggle
        );

        $stored   = get_option( 'easyopt_settings', array() );
        $stored   = is_array( $stored ) ? $stored : array();
        $defaults = class_exists( 'EasyOpt_Settings_Registry' ) ? EasyOpt_Settings_Registry::defaults() : array();

        $flags = array();
        foreach ( $toggles as $key ) {
            $full     = 'easyopt_' . $key;
            $user_set = array_key_exists( $full, $stored );
            if ( $user_set ) {
                $val = (int) (bool) $stored[ $full ];
            } elseif ( array_key_exists( $full, $defaults ) ) {
                $val = (int) (bool) $defaults[ $full ];
            } else {
                continue; // Unknown key — don't report noise.
            }
            $flags[ $key ] = ( $user_set ? 'u' : 'd' ) . $val;
        }
        return $flags;
    }

    /* ── Hosting detection ──────────────────────────────────────────────── */

    private static function fs_probe_ok( $path ) {
        $obd = ini_get( 'open_basedir' );
        if ( empty( $obd ) ) {
            return true;
        }
        foreach ( explode( PATH_SEPARATOR, $obd ) as $base ) {
            $base = rtrim( trim( $base ), '/' );
            if ( '' !== $base && 0 === strpos( $path, $base ) ) {
                return true;
            }
        }
        return false;
    }

    private static function detect_hosting() {
        if ( defined( 'IS_WPE' ) && IS_WPE )                     { return 'WP Engine'; }
        if ( defined( 'STARTER_CACHE' ) )                         { return 'SiteGround'; }
        if ( defined( 'KINSTA_CACHE_ZONE' ) )                     { return 'Kinsta'; }
        if ( defined( 'IS_FLYWHEEL' ) && IS_FLYWHEEL )            { return 'Flywheel'; }
        if ( defined( 'PANTHEON_ENVIRONMENT' ) )                   { return 'Pantheon'; }
        if ( defined( 'IS_FLAVOR_AV2' ) )                         { return 'DreamHost'; }
        if ( isset( $_SERVER['cw_allowed_ip'] ) )                  { return 'Cloudways'; }
        if ( ! empty( $_ENV['PLATFORM_PROJECT'] ) )                { return 'Platform.sh'; }
        if ( ! empty( $_ENV['LANDO'] ) )                           { return 'Lando (local)'; }
        if ( strpos( ABSPATH, '/srv/htdocs' ) !== false )          { return 'WordPress.com'; }
        if ( strpos( ABSPATH, '/home/customer/' ) !== false )      { return 'SiteGround'; }
        if ( strpos( ABSPATH, '/nas/content/' ) !== false )        { return 'WP Engine'; }
        if ( strpos( ABSPATH, '/www/kinsta/' ) !== false )         { return 'Kinsta'; }
        if ( strpos( ABSPATH, '/bitnami/' ) !== false )            { return 'Bitnami'; }
        if ( self::fs_probe_ok( '/opt/cloudlinux' ) && @file_exists( '/opt/cloudlinux' ) ) { return 'CloudLinux-based'; }
        if ( self::fs_probe_ok( '/etc/runcloud' )   && @file_exists( '/etc/runcloud' ) )   { return 'RunCloud'; }
        if ( self::fs_probe_ok( '/etc/gridpane' )   && @file_exists( '/etc/gridpane' ) )   { return 'GridPane'; }
        return '';
    }

    /* ── Domain → anonymous site id ─────────────────────────────────────── */

    private static function normalise_domain( $url ) {
        $parsed = wp_parse_url( $url );
        $host   = isset( $parsed['host'] ) ? $parsed['host'] : $url;
        $host   = strtolower( $host );
        if ( 0 === strpos( $host, 'www.' ) ) {
            $host = substr( $host, 4 );
        }
        return rtrim( $host, '.' );
    }

    public static function site_id() {
        $domain = self::normalise_domain( home_url() );
        $salt   = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'easyopt';
        return hash( 'sha256', $domain . $salt );
    }

    /* ── Send ───────────────────────────────────────────────────────────── */

    /**
     * @param string $event One of the 12 supported events.
     * @param array  $meta  Small event context; JSON-encoded server-side.
     */
    /**
     * Is this a PAYING install?
     *
     * The tracker has always had an is_pro column and Easy Optimizer never
     * populated it, so every EO row defaulted to 0 and the server's "Pro"
     * count was structurally always zero — on the one plugin that actually
     * sells. A Trial and a grandfathered Free Plan are both connected but
     * unpaid, which is exactly what is_unpaid() answers.
     *
     * @return int 1 when connected on a paid plan, else 0.
     */
    private static function is_pro() {
        if ( ! class_exists( 'EasyOpt_CDN_Cloud' ) ) {
            return 0;
        }
        if ( ! EasyOpt_CDN_Cloud::is_connected() ) {
            return 0;
        }
        return EasyOpt_CDN_Cloud::is_unpaid() ? 0 : 1;
    }

    public static function send( $event, $meta = array() ) {
        if ( ! self::is_opted_in() ) {
            return;
        }

        $body = array(
            'site_id'          => self::site_id(),
            'site_url'         => home_url(),
            'event'            => sanitize_key( $event ),
            'plugin_slug'      => self::PLUGIN_SLUG,
            'plugin_version'   => EASYOPT_VERSION,
            'wp_version'       => get_bloginfo( 'version' ),
            'php_version'      => phpversion(),
            'server_software'  => isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : '',
            'hosting_provider' => self::detect_hosting(),
            'woocommerce'      => class_exists( 'WooCommerce' ) ? 1 : 0,
            'is_pro'           => self::is_pro(),
        );

        if ( is_array( $meta ) && ! empty( $meta ) ) {
            $body['meta'] = $meta;
        }

        // Feature flags ride on activation + heartbeat so the server's
        // latest-row state is never stale, marked user-set vs default.
        if ( 'heartbeat' === $event || 'activation' === $event ) {
            $body['features'] = self::get_feature_flags();
        }

        $headers = array( 'Content-Type' => 'application/json' );
        if ( self::API_TOKEN ) {
            $headers['X-FPT-Token'] = self::API_TOKEN;
        }

        wp_remote_post( self::ENDPOINT, array(
            'body'     => wp_json_encode( $body ),
            'headers'  => $headers,
            'timeout'  => 2,
            'blocking' => false,
        ) );
    }

    /**
     * Minimal deactivation report for sites that never opted in but clicked
     * "Submit" on the reason modal. Exactly what the modal discloses —
     * reason, note, plugin version, site URL (+ the hashed id the server
     * keys on). No environment data, no feature flags. Bypasses the opt-in
     * gate deliberately: the Submit click IS the consent, for this one
     * message only.
     */
    private static function send_minimal_deactivation( $meta ) {
        $headers = array( 'Content-Type' => 'application/json' );
        if ( self::API_TOKEN ) {
            $headers['X-FPT-Token'] = self::API_TOKEN;
        }
        wp_remote_post( self::ENDPOINT, array(
            'body'     => wp_json_encode( array(
                'site_id'        => self::site_id(),
                'site_url'       => home_url(),
                'event'          => 'deactivation',
                'plugin_slug'    => self::PLUGIN_SLUG,
                'plugin_version' => EASYOPT_VERSION,
                'meta'           => $meta,
            ) ),
            'headers'  => $headers,
            'timeout'  => 2,
            'blocking' => false,
        ) );
    }
}
