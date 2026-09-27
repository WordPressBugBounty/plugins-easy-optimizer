<?php
/**
 * SEO fixes for Easy Optimizer.
 *
 * Migrated from AccessibilityPlus's SEO section. Two features:
 *  1. Links are not crawlable — ensures every <a> has a resolvable href.
 *  2. Image elements do not have [alt] attributes — derives alt from filename.
 *
 * Uses WP_HTML_Tag_Processor for attribute updates. If the core class is
 * unavailable (WP < 6.2), features no-op.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_SEO {

    /**
     * Process the HTML buffer.
     */
    public static function process_buffer( $html ) {

        if ( empty( $html ) ) {
            return $html;
        }

        // WooCommerce dynamic pages — link and alt modifications can
        // interfere with WooCommerce's JS-driven actions on Cart,
        // Checkout and My Account. These pages are typically noindex.
        if ( EasyOpt_Config::is_woo_dynamic_page() ) {
            return $html;
        }

        if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
            return $html;
        }

        $do_links = (int) EasyOpt_Config::get( 'seo_crawlable_links', 0 );
        $do_alts  = (int) EasyOpt_Config::get( 'seo_image_alts', 0 );

        if ( ! $do_links && ! $do_alts ) {
            return $html;
        }

        $p = new WP_HTML_Tag_Processor( $html );

        while ( $p->next_tag() ) {

            $tag = $p->get_tag();

            if ( $do_links && 'A' === $tag ) {
                self::fix_link_href( $p );
            }

            if ( $do_alts && 'IMG' === $tag ) {
                self::fix_image_alt( $p );
            }
        }

        return $p->get_updated_html();
    }

    /**
     * Ensure every <a> has a resolvable href. If missing, empty, or set to
     * a javascript void pattern, replace with "#".
     */
    private static function fix_link_href( $p ) {

        $href = $p->get_attribute( 'href' );

        // Missing entirely.
        if ( null === $href ) {
            $p->set_attribute( 'href', '#' );
            return;
        }

        $href = trim( (string) $href );

        if ( '' === $href ) {
            $p->set_attribute( 'href', '#' );
            return;
        }

        // Normalize common void-JS patterns.
        $lc = strtolower( str_replace( array( ' ', "\t" ), '', $href ) );

        if ( 'javascript:void(0)'  === $lc
            || 'javascript:void(0);' === $lc
            || 'javascript:;'        === $lc
            || 'javascript:'         === $lc ) {
            $p->set_attribute( 'href', '#' );
        }
    }

    /**
     * Generate alt from title or filename when missing.
     * Replicates the original plugin's derivation algorithm.
     */
    private static function fix_image_alt( $p ) {

        $alt = $p->get_attribute( 'alt' );
        // Only fill when completely missing. An empty alt ("") is intentional
        // for decorative images and must be preserved.
        if ( null !== $alt ) {
            return;
        }

        $title = (string) $p->get_attribute( 'title' );
        if ( '' !== trim( $title ) ) {
            $p->set_attribute( 'alt', trim( $title ) );
            return;
        }

        $src = (string) $p->get_attribute( 'src' );
        if ( '' === $src ) {
            // No src to derive from — use a generic alt rather than leaving blank.
            $p->set_attribute( 'alt', 'image' );
            return;
        }

        // Strip query/hash, take basename, remove extension, remove digits,
        // replace dashes/underscores with spaces.
        $clean = strtok( $src, '?#' );
        $name  = basename( (string) $clean );
        $name  = preg_replace( '/\.[^.]+$/', '', $name );
        $name  = preg_replace( '/\d+/', '', $name );
        $name  = str_replace( array( '-', '_' ), ' ', $name );
        $name  = trim( preg_replace( '/\s+/', ' ', $name ) );

        if ( '' === $name || is_numeric( $name ) ) {
            $name = 'image';
        }

        $p->set_attribute( 'alt', $name );
    }
}
