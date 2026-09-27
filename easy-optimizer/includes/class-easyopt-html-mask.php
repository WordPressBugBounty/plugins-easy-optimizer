<?php
/**
 * Shared HTML region masking for Easy Optimizer (2.4.3).
 *
 * The asset-rewriting buffer passes — Delay JS, Defer JS, Minify — find
 * <script> / <link> tags with regex and rewrite them. Those passes must NOT
 * touch markup that only *looks* like a script/stylesheet because it appears
 * as literal text inside a region the browser renders verbatim:
 *
 *   <textarea>, <pre>, <code>, <xmp>  → tutorials / code samples that print
 *                                       literal <script>…</script> as text.
 *   <noscript>                        → fallback markup that must stay inert.
 *   inline <svg>…</svg>               → can contain its own <script>/<style>
 *                                       plus attribute soup that confuses the
 *                                       tag regexes.
 *
 * Without this, a delayed/deferred/minified pass rewrites that literal text
 * and corrupts what the visitor sees on the page.
 *
 * Strategy: replace each protected region with a unique, regex-inert text
 * placeholder before the caller runs its passes, then restore it afterwards.
 * Tokens are request-unique, contain only [A-Za-z0-9] (no `<`, `>`, quotes,
 * or whitespace), so:
 *   - no <script…>/<link…> regex can match inside one, and
 *   - no comment-stripping pass can delete one (it is not a comment).
 *
 * SCOPE NOTE: this is intended for the asset-tag rewriters only. It is
 * deliberately NOT used for Used-CSS selector extraction: libxml already
 * treats <textarea>/<script>/<style> content as raw text (so it never
 * harvests selectors from inside them), and masking whole <pre>/<code>
 * elements there would wrongly drop those blocks' own classes from the
 * "used" set and strip their CSS.
 *
 * Instance-based (not static) so concurrent processors in one request keep
 * independent token maps.
 *
 * @package EasyOptimizer
 * @since   2.4.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_HTML_Mask {

    /** @var array<string,string> token => original substring */
    private $map = array();

    /** @var string Per-instance random prefix so tokens never collide. */
    private $prefix;

    /** @var int Monotonic counter for token uniqueness. */
    private $seq = 0;

    /**
     * Protected regions, matched independently and non-overlapping.
     * `s` = dot matches newlines, `i` = case-insensitive.
     *
     * <pre> is matched before <code> because <code> is frequently nested
     * inside <pre>; masking the outer <pre> first means the inner <code> is
     * already inside a stashed blob and the second pass finds nothing to do.
     */
    private static $patterns = array(
        // (2.5.7) Data blocks whose contents must never be treated as
        // executable JS. Each consumer applies its own type allowlist too,
        // but this file is where protected regions are DECLARED — a pass
        // added later would reasonably assume JSON-LD is masked here.
        // Matched first so their contents cannot be re-scanned below.
        '#<script\b[^>]*\btype\s*=\s*["\'](?:application/(?:ld\+)?json|importmap|speculationrules|text/template)["\'][^>]*>.*?</script\s*>#is',
        // Conditional comments before plain comments (the plain pattern would
        // otherwise terminate at the first `-->` inside an `<!--[if]>` block).
        '#<!--\[if\b.*?<!\[endif\]-->#is',
        '#<!--(?!\[if).*?-->#s',
        '#<textarea\b[^>]*>.*?</textarea\s*>#is',
        '#<pre\b[^>]*>.*?</pre\s*>#is',
        '#<xmp\b[^>]*>.*?</xmp\s*>#is',
        '#<noscript\b[^>]*>.*?</noscript\s*>#is',
        '#<svg\b[^>]*>.*?</svg\s*>#is',
        '#<code\b[^>]*>.*?</code\s*>#is',
    );

    public function __construct() {
        $this->prefix = 'EOMASK' . substr( md5( uniqid( '', true ) ), 0, 12 );
    }

    /**
     * Replace every protected region with an inert placeholder token.
     *
     * @param string $html
     * @return string
     */
    public function mask( $html ) {
        if ( ! is_string( $html ) || '' === $html ) {
            return $html;
        }

        // Fast bail: nothing protected present → skip the callback passes.
        if ( ! preg_match( '#<(?:textarea|pre|xmp|noscript|svg|code)\b|<!--|<script\b[^>]*\btype\s*=\s*["\'](?:application/(?:ld\+)?json|importmap|speculationrules|text/template)["\']#i', $html ) ) {
            return $html;
        }

        foreach ( self::$patterns as $pattern ) {
            $replaced = preg_replace_callback( $pattern, array( $this, 'stash' ), $html );
            // null = PCRE failure (e.g. backtrack limit on pathological input).
            // Keep the last good string rather than nulling the page.
            if ( null !== $replaced ) {
                $html = $replaced;
            }
        }

        return $html;
    }

    /**
     * Restore every stashed region. Safe to call even if mask() found nothing.
     *
     * @param string $html
     * @return string
     */
    public function unmask( $html ) {
        if ( empty( $this->map ) || ! is_string( $html ) || '' === $html ) {
            return $html;
        }
        // Single linear pass; never re-scans replaced text.
        return strtr( $html, $this->map );
    }

    /**
     * preg_replace_callback handler — store the match, return a bare token.
     *
     * @param array $m
     * @return string
     */
    private function stash( $m ) {
        $token               = $this->prefix . ( ++$this->seq ) . 'X';
        $this->map[ $token ] = $m[0];
        return $token;
    }
}
