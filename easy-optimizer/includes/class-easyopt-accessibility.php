<?php
/**
 * Accessibility fixes for Easy Optimizer.
 *
 * Migrated from AccessibilityPlus with the following improvements:
 *  - Uses WP_HTML_Tag_Processor (core WP 6.2+) instead of simple_html_dom.
 *    Streaming tokenizer, no full tree load, significantly faster and
 *    lower memory.
 *  - Each feature is independently gated by its own option.
 *  - All logic lives in this single file for maintainability.
 *
 * If WP_HTML_Tag_Processor is not available (WP < 6.2), every feature
 * becomes a no-op.
 *
 * @package EasyOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_Accessibility {

    /** @var array|null cached enabled-option flags for the current request */
    private static $enabled = null;

    /**
     * Process the HTML buffer. Called from the master output processor.
     */
    public static function process_buffer( $html ) {

        if ( empty( $html ) ) {
            return $html;
        }

        // WooCommerce dynamic pages — accessibility modifications can
        // conflict with WooCommerce's own form labels, ARIA roles, and
        // tabindex handling on Cart, Checkout and My Account.
        if ( EasyOpt_Config::is_woo_dynamic_page() ) {
            return $html;
        }

        // Core class requirement.
        if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
            return $html;
        }

        self::$enabled = self::get_enabled_options();
        if ( empty( self::$enabled ) ) {
            return $html;
        }

        // Viewport meta is edited first (simple regex, one tag in <head>).
        if ( self::$enabled['viewport'] ) {
            $html = self::fix_viewport_meta( $html );
        }

        // Tag-based single-pass transforms. One processor instance walks the
        // document once and every enabled feature updates the same cursor.
        $needs_tag_walk = self::$enabled['inputs']
            || self::$enabled['buttons']
            || self::$enabled['iframes']
            || self::$enabled['role_elements']
            || self::$enabled['progressbar']
            || self::$enabled['tabindex'];

        if ( $needs_tag_walk ) {
            $html = self::run_tag_processor( $html );
        }

        // The two remaining features (discernible link name, outer-HTML
        // aware) need a text-content check that the tokenizer cannot do
        // inexpensively. Run them via a scoped regex pass.
        if ( self::$enabled['links'] ) {
            $html = self::fix_link_discernible_names( $html );
        }

        if ( self::$enabled['buttons'] ) {
            // Runs after tag walk because we may need to re-inspect button
            // contents after simpler aria fills.
            $html = self::fix_button_text_fallback( $html );
        }

        return $html;
    }

    /* ─────────────────────────────────────────────
     *  Options lookup
     * ───────────────────────────────────────────── */

    private static function get_enabled_options() {

        $opts = array(
            'inputs'        => (int) EasyOpt_Config::get( 'a11y_inputs', 0 ),
            'links'         => (int) EasyOpt_Config::get( 'a11y_links', 0 ),
            'buttons'       => (int) EasyOpt_Config::get( 'a11y_buttons', 0 ),
            'viewport'      => (int) EasyOpt_Config::get( 'a11y_viewport', 0 ),
            'role_elements' => (int) EasyOpt_Config::get( 'a11y_role_elements', 0 ),
            'iframes'       => (int) EasyOpt_Config::get( 'a11y_iframes', 0 ),
            'progressbar'   => (int) EasyOpt_Config::get( 'a11y_progressbar', 0 ),
            'tabindex'      => (int) EasyOpt_Config::get( 'a11y_tabindex', 0 ),
        );

        // Any enabled?
        foreach ( $opts as $v ) {
            if ( $v ) {
                return $opts;
            }
        }
        return array();
    }

    /* ─────────────────────────────────────────────
     *  Viewport meta — regex (single tag in <head>)
     * ───────────────────────────────────────────── */

    /**
     * Lighthouse A11y rule: "[user-scalable='no'] is used in the <meta name='viewport'>
     * element or the [maximum-scale] attribute is less than 5."
     *
     * Strategy (per user decision): strip user-scalable=no and any maximum-scale
     * constraint entirely. That's what Lighthouse actually recommends and fully
     * resolves the audit.
     */
    private static function fix_viewport_meta( $html ) {

        return preg_replace_callback(
            '#<meta\b[^>]*name=["\']viewport["\'][^>]*>#i',
            function ( $m ) {
                $tag = $m[0];

                if ( ! preg_match( '/\bcontent\s*=\s*(["\'])(.*?)\1/i', $tag, $cm ) ) {
                    return $tag;
                }
                $content = $cm[2];

                // Strip user-scalable=no or user-scalable=0 (with optional surrounding commas/spaces).
                $content = preg_replace( '/\s*,?\s*user-scalable\s*=\s*(?:no|0)\s*/i', '', $content );
                // Strip any maximum-scale=… directive entirely.
                $content = preg_replace( '/\s*,?\s*maximum-scale\s*=\s*[^,\s]+/i', '', $content );
                // Trim stray leading/trailing commas.
                $content = trim( $content, " \t,\n\r" );

                // Replace the original content attribute value.
                $new_content_attr = 'content=' . $cm[1] . $content . $cm[1];
                $old_content_attr = 'content=' . $cm[1] . $cm[2] . $cm[1];

                return str_replace( $old_content_attr, $new_content_attr, $tag );
            },
            $html
        );
    }

    /* ─────────────────────────────────────────────
     *  Single-pass tag walk — 6 features share one cursor
     * ───────────────────────────────────────────── */

    private static function run_tag_processor( $html ) {

        $p = new WP_HTML_Tag_Processor( $html );

        while ( $p->next_tag() ) {

            $tag = $p->get_tag(); // Uppercase per HTML spec.

            switch ( $tag ) {

                case 'INPUT':
                    if ( self::$enabled['inputs'] ) {
                        self::handle_input( $p );
                    }
                    break;

                case 'BUTTON':
                    if ( self::$enabled['buttons'] ) {
                        self::handle_button( $p );
                    }
                    break;

                case 'IFRAME':
                case 'FRAME':
                    if ( self::$enabled['iframes'] ) {
                        self::handle_iframe( $p );
                    }
                    break;

                case 'DIV':
                case 'SPAN':
                case 'A':
                    if ( self::$enabled['role_elements'] ) {
                        self::handle_role_element( $p );
                    }
                    break;
            }

            // Features that apply to ALL tags (role=progressbar, tabindex)
            // are checked on every iteration.
            if ( self::$enabled['progressbar'] ) {
                self::handle_progressbar( $p );
            }

            if ( self::$enabled['tabindex'] ) {
                self::handle_tabindex( $p );
            }
        }

        return $p->get_updated_html();
    }

    /**
     * Lighthouse: "Form elements do not have associated labels."
     * If an <input> has no label association & no aria attribute, add aria-label
     * set to the input type (e.g. aria-label="search").
     */
    private static function handle_input( $p ) {

        $type = (string) $p->get_attribute( 'type' );
        if ( '' === $type ) {
            $type = 'text';
        }
        $type = strtolower( $type );

        // These don't need labels.
        if ( in_array( $type, array( 'hidden', 'button', 'submit', 'reset', 'image' ), true ) ) {
            return;
        }

        if ( $p->get_attribute( 'aria-label' ) ) {
            return;
        }
        if ( $p->get_attribute( 'aria-labelledby' ) ) {
            return;
        }

        // We cannot cheaply determine sibling/wrapping <label> relationships
        // from a streaming tokenizer. The original plugin used DOM traversal
        // for this. Practical compromise: if the input has a non-empty
        // placeholder OR a title, assume it's labeled sufficiently for AT.
        if ( $p->get_attribute( 'placeholder' ) || $p->get_attribute( 'title' ) ) {
            return;
        }

        // Skip if id exists AND the document contains <label for="that-id">.
        // This is a content-level check that would require a second pass,
        // so we skip it and add aria-label liberally — Lighthouse counts a
        // redundant aria-label as benign; missing labels as errors.
        $p->set_attribute( 'aria-label', $type );
    }

    /**
     * Lighthouse: "Buttons do not have an accessible name."
     * First pass (here, inside tag walk): if the button has no aria-label
     * AND no plain-text contents are detectable from attributes alone,
     * set a generic aria-label. A second pass ({@see fix_button_text_fallback})
     * runs outside the walk to refine this when we can see the full inner HTML.
     */
    private static function handle_button( $p ) {

        if ( $p->get_attribute( 'aria-label' ) ) {
            return;
        }
        if ( $p->get_attribute( 'aria-labelledby' ) ) {
            return;
        }
        // `value` on <button type=submit> acts as its accessible name.
        if ( $p->get_attribute( 'value' ) ) {
            return;
        }
        // A `title` attribute also provides an accessible name (weak but valid).
        if ( $p->get_attribute( 'title' ) ) {
            return;
        }

        // Tentatively mark. The second pass may refine this to match inner
        // text content (e.g. <span>Click me</span> → aria-label="Click me")
        // but the default 'button' value satisfies Lighthouse if text is absent.
        $p->set_attribute( 'aria-label', 'button' );
    }

    /**
     * Lighthouse A11y: "<frame> or <iframe> elements do not have a title."
     */
    private static function handle_iframe( $p ) {

        if ( $p->get_attribute( 'title' ) ) {
            return;
        }
        if ( $p->get_attribute( 'aria-label' ) ) {
            return;
        }
        if ( $p->get_attribute( 'aria-labelledby' ) ) {
            return;
        }

        $p->set_attribute( 'title', 'iframe' );
    }

    /**
     * Lighthouse: "button, link, and menuitem elements do not have accessible
     * names." When a div/span/a has role=link|button|menuitem and no aria
     * label, add one. Original plugin only hit divs; we also cover span/a
     * because they're valid targets for this pattern.
     */
    private static function handle_role_element( $p ) {

        $role = (string) $p->get_attribute( 'role' );
        if ( '' === $role ) {
            return;
        }
        $role = strtolower( $role );

        if ( ! in_array( $role, array( 'link', 'button', 'menuitem' ), true ) ) {
            return;
        }

        if ( $p->get_attribute( 'aria-label' ) || $p->get_attribute( 'aria-labelledby' ) ) {
            return;
        }

        $p->set_attribute( 'aria-label', $role );
    }

    /**
     * Lighthouse: "ARIA progressbar elements do not have accessible names."
     */
    private static function handle_progressbar( $p ) {

        $role = (string) $p->get_attribute( 'role' );
        if ( 'progressbar' !== strtolower( $role ) ) {
            return;
        }

        if ( $p->get_attribute( 'aria-label' )
            || $p->get_attribute( 'aria-labelledby' )
            || $p->get_attribute( 'title' ) ) {
            return;
        }

        $p->set_attribute( 'aria-label', 'progressbar' );
    }

    /**
     * Lighthouse: "Some elements have a [tabindex] value greater than 0."
     * Reset any tabindex > 0 to 0. Negative tabindex values are left alone
     * (they disable focus intentionally).
     */
    private static function handle_tabindex( $p ) {

        $ti = $p->get_attribute( 'tabindex' );
        if ( null === $ti || '' === $ti ) {
            return;
        }

        if ( (int) $ti > 0 ) {
            $p->set_attribute( 'tabindex', '0' );
        }
    }

    /* ─────────────────────────────────────────────
     *  Discernible link names — regex pass with inner-HTML text check
     * ───────────────────────────────────────────── */

    /**
     * Lighthouse: "Links do not have a discernible name."
     * A link is considered accessible if any of: it has text content, an
     * aria-label, an aria-labelledby, a title, or contains an <img alt="…">.
     * For everything else, we add aria-label="link".
     *
     * Implementation: find every <a … > … </a>, skip self-closing edge cases,
     * compute text content via wp_strip_all_tags() on the inner HTML. This is
     * the ONE place where regex is faster than WP_HTML_Tag_Processor because
     * we need the inner slice of the tag.
     */
    private static function fix_link_discernible_names( $html ) {

        // Match <a …> … </a>. Non-greedy inner; allow nested non-<a> tags.
        // For virtually all real-world HTML, <a> is never nested inside another
        // <a> (browsers actively unnest them), so a simple non-greedy pattern
        // is safe and fast.
        return preg_replace_callback(
            '#<a\b([^>]*)>(.*?)</a>#is',
            function ( $m ) {
                $open_atts = $m[1];
                $inner     = $m[2];

                // Already has an accessible name?
                if ( preg_match( '/\baria-label\s*=\s*["\'][^"\']+["\']/i', $open_atts )
                    || preg_match( '/\baria-labelledby\s*=\s*["\'][^"\']+["\']/i', $open_atts )
                    || preg_match( '/\btitle\s*=\s*["\'][^"\']+["\']/i', $open_atts ) ) {
                    return $m[0];
                }

                // Contains actual text?
                // (2.5.4 / perf #27) Cheap pre-filter: on menu-heavy pages
                // this callback fires for hundreds of links, and virtually
                // all of them have visible text — running the regex-based
                // wp_strip_all_tags() on each was the pass's dominant cost.
                // Two O(n) checks decide the common cases first:
                //   • no '<' in the inner HTML → the inner IS the text; a
                //     plain trim answers the question.
                //   • a '>' directly followed by a non-space, non-'<' char
                //     → there is bare text between tags → discernible.
                // Only inners that are pure nested markup (icon-only links,
                // the actual fix targets) still reach wp_strip_all_tags for
                // the authoritative answer, so behaviour is unchanged.
                if ( false === strpos( $inner, '<' ) ) {
                    if ( '' !== trim( $inner ) ) {
                        return $m[0];
                    }
                } elseif ( ( preg_match( '/>\s*[^<\s]/', $inner ) || '' !== trim( substr( $inner, 0, (int) strpos( $inner, '<' ) ) ) )
                    && false === stripos( $inner, '<style' )
                    && false === stripos( $inner, '<script' ) ) {
                    // <style>/<script> content LOOKS like bare text but is
                    // removed by wp_strip_all_tags — those rare inners fall
                    // through to the authoritative check below.
                    return $m[0];
                }
                $text = trim( wp_strip_all_tags( $inner ) );
                if ( '' !== $text ) {
                    return $m[0];
                }

                // Contains an <img> with a non-blank alt?
                if ( preg_match( '/<img\b[^>]*\balt\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $inner, $im ) ) {
                    if ( '' !== trim( $im[1] ) ) {
                        return $m[0];
                    }
                }

                // Contains an <svg> with <title>… or aria-label?
                if ( preg_match( '#<svg\b[^>]*\baria-label\s*=\s*["\'][^"\']+["\']#i', $inner ) ) {
                    return $m[0];
                }
                if ( preg_match( '#<svg\b[^>]*>.*?<title\b[^>]*>[^<]+</title>#is', $inner ) ) {
                    return $m[0];
                }

                // No discernible name — inject aria-label="link".
                $new_open = '<a aria-label="link"' . $open_atts . '>';
                return $new_open . $inner . '</a>';
            },
            $html
        );
    }

    /* ─────────────────────────────────────────────
     *  Button name refinement — second pass
     * ───────────────────────────────────────────── */

    /**
     * The tag-walk already set aria-label="button" on every anonymous button.
     * This pass upgrades that default to the button's actual visible text when
     * the button contains a <span> with text, matching the original plugin's
     * behavior. We only touch buttons where we set aria-label="button" (our
     * sentinel), so user-set labels are never overwritten.
     */
    private static function fix_button_text_fallback( $html ) {

        return preg_replace_callback(
            '#<button\b([^>]*\baria-label\s*=\s*["\']button["\'][^>]*)>(.*?)</button>#is',
            function ( $m ) {
                $atts  = $m[1];
                $inner = $m[2];

                // If there's real plaintext inside the button, use it.
                $text = trim( wp_strip_all_tags( $inner ) );
                if ( '' === $text ) {
                    return $m[0]; // Keep "button" default.
                }

                // Replace aria-label="button" with aria-label="<text>" (truncated to 100 chars).
                $text    = substr( preg_replace( '/\s+/', ' ', $text ), 0, 100 );
                $safe    = str_replace( array( '"', "'", '<', '>' ), '', $text );
                $new_atts = preg_replace(
                    '/\baria-label\s*=\s*["\']button["\']/i',
                    'aria-label="' . $safe . '"',
                    $atts,
                    1
                );
                return '<button' . $new_atts . '>' . $inner . '</button>';
            },
            $html
        );
    }
}
