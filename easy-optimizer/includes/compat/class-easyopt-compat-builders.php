<?php
/**
 * Page-builder template awareness.
 *
 * When a page-builder *template* (header, footer, global widget, theme
 * builder layout) is saved, the normal selective cache invalidation
 * (clear post URL + homepage + taxonomy archives) is insufficient because
 * the template appears on every page. This module hooks into `save_post`
 * and, for recognised template post-types, performs a full site cache
 * purge and — if preload is enabled — kicks off a fresh preload crawl.
 *
 * @package EasyOptimizer
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Compat_Builders {

    /**
     * Post types registered by page builders for global / shared templates.
     * Saving any of these affects every page on the site.
     */
    private static $template_post_types = array(
        // Elementor
        'elementor_library',
        'elementor_snippet',
        'elementor_font',
        'elementor_icons',
        'elementor-hf',           // Elementor Header & Footer Builder

        // Divi / Extra
        'et_pb_layout',
        'et_header_layout',
        'et_footer_layout',
        'et_body_layout',
        'et_template',
        'et_code_snippet',
        'et_theme_options',

        // Gutenberg / Full-Site Editing
        'wp_block',               // Reusable blocks
        'wp_navigation',
        'wp_template',
        'wp_template_part',
        'wp_global_styles',

        // Beaver Builder
        'fl-builder-template',

        // Oxygen
        'ct_template',

        // Bricks
        'bricks_template',

        // Breakdance
        'breakdance_template',
        'breakdance_header',
        'breakdance_footer',
        'breakdance_block',

        // Brizy
        'brizy-layout',
        'brizy-global-block',

        // Visual Composer / WPBakery
        'vcv_templates',

        // Thrive
        'tve_form_type',
        'tve_lead_group',

        // Kadence
        'kadence_element',

        // GeneratePress
        'gp_elements',
    );

    /** Throttle window (seconds) between builder-triggered full purges. */
    const PURGE_THROTTLE = 60;

    /** Deferred-purge cron hook (fires when saves land inside the window). */
    const DEFERRED_HOOK = 'easyopt_builder_deferred_purge';

    /** @var bool A template save in THIS request needs a purge at shutdown. */
    private static $pending = false;

    /**
     * Wire up.
     */
    public static function init() {
        add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 10, 2 );
        add_action( self::DEFERRED_HOOK, array( __CLASS__, 'run_purge' ) );
    }

    /**
     * When a builder template is saved (published), clear the entire
     * site cache and restart preload.
     *
     * @param int      $post_id Post ID.
     * @param \WP_Post $post    Post object.
     */
    public static function on_save_post( $post_id, $post ) {

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( 'publish' !== $post->post_status ) {
            return;
        }

        if ( ! in_array( $post->post_type, self::$template_post_types, true ) ) {
            return;
        }

        // ── Debounced full purge (2.3.3) ─────────────────────────────────
        // Pre-2.3.3 this ran clear_all() + a full preload restart
        // SYNCHRONOUSLY inside the editor's save request, once PER template
        // save — and `wp_block` (reusable blocks) is in the list, so routine
        // editing nuked the whole site cache over and over (purge storms).
        //
        // Now: one purge per request maximum, deferred to `shutdown` (after
        // the editor response is on its way on FPM), and throttled to one
        // full purge per PURGE_THROTTLE window. Saves landing INSIDE the
        // window schedule a single deferred purge for when the window ends,
        // so the LAST save's changes are always reflected — nothing is ever
        // skipped, only coalesced. Same feature, none of the storm.
        if ( ! self::$pending ) {
            self::$pending = true;
            add_action( 'shutdown', array( __CLASS__, 'flush_pending' ), 5 );
        }

        if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
            EasyOpt_Debug_Log::info(
                'compat',
                sprintf(
                    'Builder template saved (post type: %s, ID: %d). Full cache purge queued (debounced).',
                    $post->post_type,
                    $post_id
                )
            );
        }
    }

    /**
     * Shutdown handler — run the purge now, or coalesce it into a single
     * scheduled event when one already ran inside the throttle window.
     *
     * @since 2.3.3
     */
    public static function flush_pending() {
        if ( ! self::$pending ) {
            return;
        }
        self::$pending = false;

        if ( false !== get_transient( 'easyopt_builder_purged' ) ) {
            // A builder purge already ran in the last PURGE_THROTTLE seconds.
            // Coalesce: ensure exactly ONE deferred purge is scheduled for
            // just after the window closes, so this save is still reflected.
            if ( ! wp_next_scheduled( self::DEFERRED_HOOK ) ) {
                wp_schedule_single_event( time() + self::PURGE_THROTTLE + 1, self::DEFERRED_HOOK );
            }
            return;
        }

        self::run_purge();
    }

    /**
     * The actual full purge + preload restart (shared by the shutdown path
     * and the deferred cron event).
     *
     * @since 2.3.3
     */
    public static function run_purge() {
        set_transient( 'easyopt_builder_purged', 1, self::PURGE_THROTTLE );

        if ( class_exists( 'EasyOpt_Cache' ) && method_exists( 'EasyOpt_Cache', 'clear_all' ) ) {
            EasyOpt_Cache::clear_all();
        }
        if ( class_exists( 'EasyOpt_Cache_Preload' ) && method_exists( 'EasyOpt_Cache_Preload', 'start' ) ) {
            EasyOpt_Cache_Preload::start();
        }
        if ( class_exists( 'EasyOpt_Debug_Log' ) ) {
            EasyOpt_Debug_Log::info( 'compat', 'Builder-template full cache purge executed (debounced).' );
        }
    }
}
