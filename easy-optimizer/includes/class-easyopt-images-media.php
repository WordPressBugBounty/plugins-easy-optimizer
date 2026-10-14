<?php
/**
 * EasyOpt — per-image status, Restore and Optimize in the Media Library.
 *
 * A column in the list view, and a row in the attachment details of the grid
 * view and the media modal. Both read only the attachment's own meta, which
 * WordPress has already loaded for the page, so the library makes no extra
 * queries. The buttons call REST rather than navigate, because the modal can
 * be open over a post with unsaved edits.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Images_Media {

    const KEY = 'easyopt_img';

    /** Called from EasyOpt_Images::init(): admin only, feature on. */
    public static function init() {
        add_filter( 'manage_media_columns', array( __CLASS__, 'add_column' ) );
        add_action( 'manage_media_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
        add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'add_field' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_on_screen' ) );
        add_action( 'wp_enqueue_media', array( __CLASS__, 'enqueue' ) );
    }

    /** Same capability as the dashboard's Restore all. */
    private static function allowed() {
        return current_user_can( 'manage_options' );
    }

    public static function add_column( $columns ) {
        if ( self::allowed() ) {
            $columns[ self::KEY ] = __( 'Optimization', 'easy-optimizer' );
        }
        return $columns;
    }

    public static function render_column( $column, $id ) {
        if ( self::KEY === $column && self::allowed() ) {
            echo self::status_html( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in status_html().
        }
    }

    public static function add_field( $fields, $post ) {
        if ( ! self::allowed() || ! ( $post instanceof WP_Post ) ) {
            return $fields;
        }
        $html = self::status_html( $post->ID );
        if ( '' !== $html ) {
            $fields[ self::KEY ] = array(
                'label' => __( 'Optimization', 'easy-optimizer' ),
                'input' => 'html',
                'html'  => $html,
            );
        }
        return $fields;
    }

    /** The list view (no media modal there) and the attachment edit screen. */
    public static function enqueue_on_screen( $hook ) {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( 'upload.php' === $hook || ( $screen && 'post' === $screen->base && 'attachment' === $screen->post_type ) ) {
            self::enqueue();
        }
    }

    public static function enqueue() {
        if ( ! self::allowed() || wp_script_is( 'easyopt-media', 'enqueued' ) ) {
            return;
        }
        wp_enqueue_script( 'easyopt-media', EASYOPT_URL . 'assets/easyopt-media.js', array(), EASYOPT_VERSION, true );
        wp_add_inline_script( 'easyopt-media', 'var easyoptMedia = ' . wp_json_encode( array(
            'url'    => rest_url( EasyOpt_Rest_Dashboard::NS . '/images/item' ),
            'nonce'  => wp_create_nonce( 'wp_rest' ),
            'failed' => __( 'That did not work. Reload the page and try again.', 'easy-optimizer' ),
        ) ) . ';', 'before' );
    }

    /**
     * One image's state and the one action that applies to it. Returns '' for
     * files the optimizer does not handle.
     *
     * @param int  $id
     * @param bool $queued Just queued by optimize_one() (the queue is not read
     *                     here — that would be a query per row).
     */
    public static function status_html( $id, $queued = false ) {
        $id = (int) $id;
        if ( ! in_array( (string) get_post_mime_type( $id ), EasyOpt_Images::MIME, true ) ) {
            return '';
        }
        $meta   = get_post_meta( $id, EasyOpt_Images::META, true );
        $meta   = is_array( $meta ) ? $meta : array();
        $action = '';

        if ( $queued ) {
            $text = __( 'Queued. Optimizing in the background.', 'easy-optimizer' );
        } elseif ( EasyOpt_Images::is_excluded( $id ) ) {
            $text   = __( 'Original (restored). Bulk runs skip it.', 'easy-optimizer' );
            $action = 'optimize';
        } elseif ( ! empty( $meta['variants'] ) || ! empty( $meta['original_backed_up'] ) ) {
            $found = array();
            foreach ( (array) ( $meta['variants'] ?? array() ) as $per_file ) {
                $found += array_flip( array_keys( (array) $per_file ) );
            }
            $formats = array_map( 'strtoupper', array_values( array_intersect( array( 'avif', 'webp' ), array_keys( $found ) ) ) );
            $text    = $formats
                /* translators: 1: formats, e.g. "AVIF, WebP"; 2: size saved, e.g. "120 KB". */
                ? sprintf( __( 'Optimized (%1$s), %2$s saved.', 'easy-optimizer' ), str_replace( 'WEBP', 'WebP', implode( ', ', $formats ) ), size_format( (int) ( $meta['saved'] ?? 0 ) ) )
                : __( 'Original resized. Backup kept.', 'easy-optimizer' );
            $action  = 'restore';
        } elseif ( ! empty( $meta['optimized_at'] ) ) {
            $text = __( 'Skipped. No smaller copy was possible.', 'easy-optimizer' );
        } else {
            $text   = __( 'Not optimized yet.', 'easy-optimizer' );
            $action = 'optimize';
        }

        $html = '<div class="easyopt-img-status">' . esc_html( $text );
        if ( $action ) {
            $label = 'restore' === $action ? __( 'Restore original', 'easy-optimizer' ) : __( 'Optimize', 'easy-optimizer' );
            $busy  = 'restore' === $action ? __( 'Restoring…', 'easy-optimizer' ) : __( 'Queueing…', 'easy-optimizer' );
            $html .= sprintf(
                '<br><button type="button" class="button-link" data-easyopt-img="%1$s" data-id="%2$d" data-label="%3$s" data-busy="%4$s">%3$s</button>',
                esc_attr( $action ),
                $id,
                esc_attr( $label ),
                esc_attr( $busy )
            );
        }
        return $html . '</div>';
    }
}
