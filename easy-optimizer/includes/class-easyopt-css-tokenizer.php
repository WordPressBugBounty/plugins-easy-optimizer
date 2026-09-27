<?php
/**
 * Dependency-free CSS tokenizer for Easy Optimizer's Remove-Unused-CSS engine.
 *
 * REPLACES sabberworm/php-css-parser (and its 2.1 MB thecodingmachine/safe
 * transitive dependency). It produces the EXACT `$items` structure the old
 * `EasyOpt_Unused_CSS::prep_css_data()` produced, so the keep/drop core
 * (`remove_unused_selectors()` + `is_selector_used()`) is reused UNCHANGED:
 *
 *     to_items( $css, $url )  →  [ item, item, … ]
 *
 * Item shapes (identical to the old prep_css_data output):
 *   • declaration block : [ 'css' => '<minified rule>', 'selectors' => [ … ] ]
 *   • keep-whole rule   : [ 'css' => '<minified rule>' ]            (no selectors)
 *                         (@font-face, @page, @keyframes, @property, @import, …)
 *   • group at-rule     : [ 'rulesets' => [ … ], 'at_rule' => '@media …' ]
 *                         (@media/@supports/@container/@scope/@layer{…}/…)
 *   • bare @layer stmt  : [ 'rulesets' => [], 'at_rule' => '@layer a, b' ]
 *
 * At-rule classification mirrors sabberworm exactly (CSSList::parseAtRule +
 * AtRule::BLOCK_RULES): @charset is dropped; @import/@namespace are kept as
 * statements; @keyframes / @font-face / @page / @property / … are kept WHOLE;
 * only the block-group rules (media, supports, container, scope, layer,
 * document, region-style, font-feature-values, starting-style — plus vendor
 * prefixes) are recursed so their inner rules can be tree-shaken.
 *
 * Improvement over the old parser: native CSS nesting (`& .btn { … }`,
 * `&:hover`, `.nav &`, nested `@media`) is PRESERVED. sabberworm 9.x silently
 * dropped every nested rule; here a nested block is kept verbatim whenever its
 * parent selector is kept (conservative — can only ever keep more, never less).
 *
 * The scan is byte-oriented and string/comment/`url()`-aware, so it never
 * throws on invalid UTF-8 (the reason the 2.6.3 iconv `//IGNORE` guard existed)
 * and a stray `{ } ;` inside a string or data-URI can't desync it.
 *
 * @package EasyOptimizer
 * @since   2.6.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyOpt_CSS_Tokenizer {

    /** @var string  Directory of the stylesheet being parsed (for relative url()). */
    private static $base_url = '';

    /** @var bool    Upgrade internal http:// url() to https://. */
    private static $force_https = false;

    /** @var string[]  Lowercased local hostnames eligible for the http→https upgrade. */
    private static $local_hosts = array();

    /* ───────────────────────────────────────────────
     *  Entry point
     * ─────────────────────────────────────────────── */

    /**
     * Parse a stylesheet into the item list consumed by
     * EasyOpt_Unused_CSS::remove_unused_selectors().
     *
     * @param string $css            Raw stylesheet text.
     * @param string $stylesheet_url Absolute URL the stylesheet was loaded from.
     * @return array
     */
    public static function to_items( $css, $stylesheet_url ) {
        self::$base_url = (string) preg_replace( '#[^/]+(\?.*)?$#', '', (string) $stylesheet_url );
        self::init_url_context();

        $i = 0;
        $n = strlen( $css );
        return self::parse_list( $css, $i, $n );
    }

    /* ───────────────────────────────────────────────
     *  Structural safety net — brace balancing
     * ─────────────────────────────────────────────── */

    /**
     * Guarantee CSS is brace-balanced WITHOUT parsing or pruning it.
     *
     * This is the structural safety net for CSS that bypasses the tokenizer:
     * passthrough builder CSS (Elementor/Divi per-page sheets copied verbatim)
     * and the final concatenated Used-CSS buffer. A single unclosed block in a
     * source stylesheet — e.g. an Elementor "Custom CSS" typo leaving an
     * `@media(max-width:700px){ … ` open — would otherwise leak into and
     * mobile-scope every rule that follows it, collapsing the desktop layout.
     *
     * It is string/comment-aware (braces inside strings and comments never
     * count), appends any missing closing braces, and drops stray extra `}`
     * (which are inert no-ops on their own). Well-formed CSS is returned
     * BYTE-FOR-BYTE unchanged via a fast pre-scan, so passthrough sheets stay
     * verbatim and nothing is ever stripped. It never suppresses output: the
     * worst case is returning the input untouched.
     *
     * @param string $css
     * @return string
     */
    public static function balance( $css ) {
        if ( ! is_string( $css ) || '' === $css ) {
            return (string) $css;
        }

        $n = strlen( $css );

        // ── Fast pre-scan: is it already balanced? Then return it verbatim. ──
        $i = 0; $depth = 0; $needs_fix = false;
        while ( $i < $n ) {
            $c = $css[ $i ];
            if ( '/' === $c && $i + 1 < $n && '*' === $css[ $i + 1 ] ) {
                $i += 2;
                while ( $i < $n && ! ( '*' === $css[ $i ] && $i + 1 < $n && '/' === $css[ $i + 1 ] ) ) { $i++; }
                $i += 2;
                continue;
            }
            if ( '"' === $c || "'" === $c ) { self::consume_string( $css, $i, $n ); continue; }
            if ( '{' === $c ) { $depth++; $i++; continue; }
            if ( '}' === $c ) { $depth--; if ( $depth < 0 ) { $needs_fix = true; $depth = 0; } $i++; continue; }
            $i++;
        }
        if ( ! $needs_fix && 0 === $depth ) {
            return $css; // already balanced — untouched
        }

        // ── Rebuild: copy verbatim, drop stray `}`, append missing `}`. ──
        $out = ''; $i = 0; $depth = 0;
        while ( $i < $n ) {
            $c = $css[ $i ];
            if ( '/' === $c && $i + 1 < $n && '*' === $css[ $i + 1 ] ) {
                $start = $i;
                $i    += 2;
                while ( $i < $n && ! ( '*' === $css[ $i ] && $i + 1 < $n && '/' === $css[ $i + 1 ] ) ) { $i++; }
                $i   += ( $i < $n ) ? 2 : 0;
                $out .= substr( $css, $start, $i - $start );
                continue;
            }
            if ( '"' === $c || "'" === $c ) { $out .= self::consume_string( $css, $i, $n ); continue; }
            if ( '{' === $c ) { $depth++; $out .= '{'; $i++; continue; }
            if ( '}' === $c ) {
                if ( $depth > 0 ) { $depth--; $out .= '}'; } // else: drop stray closer
                $i++;
                continue;
            }
            $out .= $c;
            $i++;
        }
        if ( $depth > 0 ) {
            $out .= str_repeat( '}', $depth );
        }
        return $out;
    }

    /* ───────────────────────────────────────────────
     *  Structural parser
     * ─────────────────────────────────────────────── */

    /**
     * Read a list of rules/at-rules until the `}` that closes THIS level
     * (which it consumes) or EOF. Comments are skipped; strings and `url()`
     * tokens are consumed opaquely so their contents can't be mistaken for
     * structure.
     *
     * @param string $s
     * @param int    $i  Cursor (by ref); left just past the closing `}`.
     * @param int    $n
     * @return array
     */
    private static function parse_list( $s, &$i, $n ) {
        $items  = array();
        $buffer = '';

        while ( $i < $n ) {
            $c = $s[ $i ];

            // Comment — skip entirely (never part of a prelude).
            if ( '/' === $c && $i + 1 < $n && '*' === $s[ $i + 1 ] ) {
                $i += 2;
                while ( $i < $n && ! ( '*' === $s[ $i ] && $i + 1 < $n && '/' === $s[ $i + 1 ] ) ) {
                    $i++;
                }
                if ( $i < $n ) {
                    $i += 2;
                }
                continue;
            }

            // String — copy verbatim.
            if ( '"' === $c || "'" === $c ) {
                $buffer .= self::consume_string( $s, $i, $n );
                continue;
            }

            // url( … ) — copy verbatim so a stray brace/semicolon inside an
            // (unquoted) data-URI can't desync the scanner.
            if ( '(' === $c && self::ends_with_url( $buffer ) ) {
                $buffer .= self::consume_url_raw( $s, $i, $n );
                continue;
            }

            // End of this list.
            if ( '}' === $c ) {
                $i++;
                break;
            }

            // Statement terminator (@import, @charset, bare @layer, …).
            if ( ';' === $c ) {
                $i++;
                $item   = self::make_statement_item( trim( $buffer ) );
                $buffer = '';
                if ( null !== $item ) {
                    $items[] = $item;
                }
                continue;
            }

            // Block open — prelude is a selector list or an at-rule.
            if ( '{' === $c ) {
                $i++;
                $item   = self::make_block_item( trim( $buffer ), $s, $i, $n );
                $buffer = '';
                if ( null !== $item ) {
                    $items[] = $item;
                }
                continue;
            }

            $buffer .= $c;
            $i++;
        }

        return $items;
    }

    /**
     * Build the item for a `prelude { … }` block. The cursor starts just past
     * the opening `{`; on return it is just past the matching `}`.
     */
    private static function make_block_item( $prelude, $s, &$i, $n ) {

        // Malformed empty prelude — consume the block to stay in sync, drop it.
        if ( '' === $prelude ) {
            self::capture_raw_block( $s, $i, $n );
            return null;
        }

        if ( '@' === $prelude[0] ) {
            $name = self::at_rule_name( $prelude );

            // Group rules: recurse so inner rules can be tree-shaken.
            if ( self::is_group_at_rule( $name ) ) {
                $children = self::parse_list( $s, $i, $n );
                return array(
                    'rulesets' => $children,
                    'at_rule'  => self::process_fragment( $prelude ),
                );
            }

            // Everything else (@keyframes, @font-face, @page, @property, …):
            // keep the whole block — it has no matchable selectors.
            $raw = self::capture_raw_block( $s, $i, $n );
            $css = self::process_fragment( $prelude . '{' . $raw . '}' );
            return self::is_empty_block( $css ) ? null : array( 'css' => $css );
        }

        // Ordinary declaration block (selectors present).
        $raw = self::capture_raw_block( $s, $i, $n );
        $css = self::process_fragment( $prelude . '{' . $raw . '}' );
        if ( self::is_empty_block( $css ) ) {
            return null; // empty rule — nothing to keep (matches sabberworm).
        }

        return array(
            'css'       => $css,
            'selectors' => self::decompose_selectors( self::split_top_commas( $prelude ) ),
        );
    }

    /** True when a rendered block has no declarations (ends in `{}`). */
    private static function is_empty_block( $css ) {
        return '{}' === substr( $css, -2 );
    }

    /**
     * Build the item for an at-rule statement (terminated by `;`, no block).
     * Returns null for @charset (dropped, matching sabberworm).
     */
    private static function make_statement_item( $stmt ) {
        if ( '' === $stmt ) {
            return null;
        }

        if ( '@' === $stmt[0] ) {
            $name = self::at_rule_name( $stmt );

            // @charset is stripped (matches prep_css_data()).
            if ( 'charset' === $name ) {
                return null;
            }

            // Bare `@layer a, b, c;` — an ORDER statement carrying no rules.
            // Emitted in the exact shape remove_unused_selectors() preserves.
            if ( 'layer' === $name ) {
                return array(
                    'rulesets' => array(),
                    'at_rule'  => self::process_fragment( $stmt ),
                );
            }

            // @import / @namespace / any other bare at-rule — keep verbatim.
            return array( 'css' => self::process_fragment( $stmt ) . ';' );
        }

        // Non-@ dangling statement (rare/invalid) — keep to avoid data loss.
        return array( 'css' => self::process_fragment( $stmt ) . ';' );
    }

    /**
     * Copy the raw inner text of a block, brace-aware. Cursor starts just past
     * the opening `{`; on return it is just past the matching `}`. Comments and
     * strings/url() are preserved verbatim here (whitespace/comment cleanup is
     * done later by process_fragment()); their contents never affect nesting.
     */
    private static function capture_raw_block( $s, &$i, $n ) {
        $out   = '';
        $depth = 1;

        while ( $i < $n ) {
            $c = $s[ $i ];

            if ( '/' === $c && $i + 1 < $n && '*' === $s[ $i + 1 ] ) {
                $out .= '/*';
                $i   += 2;
                while ( $i < $n && ! ( '*' === $s[ $i ] && $i + 1 < $n && '/' === $s[ $i + 1 ] ) ) {
                    $out .= $s[ $i ];
                    $i++;
                }
                if ( $i < $n ) {
                    $out .= '*/';
                    $i   += 2;
                }
                continue;
            }

            if ( '"' === $c || "'" === $c ) {
                $out .= self::consume_string( $s, $i, $n );
                continue;
            }

            if ( '(' === $c && self::ends_with_url( $out ) ) {
                $out .= self::consume_url_raw( $s, $i, $n );
                continue;
            }

            if ( '{' === $c ) {
                $depth++;
                $out .= $c;
                $i++;
                continue;
            }

            if ( '}' === $c ) {
                $depth--;
                $i++;
                if ( 0 === $depth ) {
                    break;
                }
                $out .= $c;
                continue;
            }

            $out .= $c;
            $i++;
        }

        // Unclosed input (truncated / malformed): close any still-open inner
        // blocks so the captured body is always brace-balanced. The caller adds
        // the outer block's own closing brace.
        if ( $depth > 1 ) {
            $out .= str_repeat( '}', $depth - 1 );
        }

        return $out;
    }

    /* ───────────────────────────────────────────────
     *  Low-level consumers
     * ─────────────────────────────────────────────── */

    /**
     * Consume a quoted string (incl. quotes, `\`-escape aware). Cursor at the
     * opening quote.
     *
     * Spec-compliant termination so an UNTERMINATED string can never swallow
     * the rest of the stylesheet (and, concatenated, the rest of the page's
     * CSS): a CSS string ends at an unescaped newline (a "bad-string" per the
     * CSS Syntax spec — the newline is NOT consumed), and an escaped newline
     * (`\` + newline) is a valid line continuation that stays in the string.
     * If the closing quote is missing at a newline or at EOF, the quote is
     * auto-appended so the emitted string is always well-formed. Valid strings
     * (which always close before any raw newline) are returned unchanged.
     */
    private static function consume_string( $s, &$i, $n ) {
        $q   = $s[ $i ];
        $out = $q;
        $i++;
        while ( $i < $n ) {
            $c = $s[ $i ];
            if ( '\\' === $c && $i + 1 < $n ) {
                $out .= $c . $s[ $i + 1 ]; // escape / line-continuation
                $i   += 2;
                continue;
            }
            // Unescaped newline ends the string; do NOT consume it. Auto-close.
            if ( "\n" === $c || "\r" === $c || "\f" === $c ) {
                return $out . $q;
            }
            $out .= $c;
            $i++;
            if ( $c === $q ) {
                return $out;
            }
        }
        return $out . $q; // EOF without a closing quote — auto-close.
    }

    /**
     * Consume a `url( … )` token verbatim, INCLUDING the parentheses. Cursor
     * starts at the `(`; on return it is just past the `)`. Handles quoted and
     * unquoted (`\`-escape aware) forms. Value is left untouched — url()
     * rewriting happens in process_fragment().
     */
    private static function consume_url_raw( $s, &$i, $n ) {
        $raw = '(';
        $i++; // past (

        while ( $i < $n && self::is_space( $s[ $i ] ) ) {
            $raw .= $s[ $i ];
            $i++;
        }

        if ( $i < $n && ( '"' === $s[ $i ] || "'" === $s[ $i ] ) ) {
            $raw .= self::consume_string( $s, $i, $n );
            while ( $i < $n && ')' !== $s[ $i ] ) {
                if ( '\\' === $s[ $i ] && $i + 1 < $n ) {
                    $raw .= $s[ $i ] . $s[ $i + 1 ];
                    $i   += 2;
                    continue;
                }
                $raw .= $s[ $i ];
                $i++;
            }
        } else {
            while ( $i < $n ) {
                $c = $s[ $i ];
                if ( '\\' === $c && $i + 1 < $n ) {
                    $raw .= $c . $s[ $i + 1 ];
                    $i   += 2;
                    continue;
                }
                if ( ')' === $c ) {
                    break;
                }
                $raw .= $c;
                $i++;
            }
        }

        if ( $i < $n && ')' === $s[ $i ] ) {
            $raw .= ')';
            $i++;
        }

        return $raw;
    }

    /* ───────────────────────────────────────────────
     *  Minify + url() rewrite (string/comment/url-aware)
     * ─────────────────────────────────────────────── */

    /**
     * Minify a CSS string WITHOUT parsing or pruning it: strip comments,
     * collapse whitespace, and drop whitespace adjacent to the structural
     * characters `{ } ; , (`. Strings and url() contents are copied verbatim,
     * and whitespace around `:`, `>`, `+`, `~` or inside `calc()` is preserved,
     * so declaration values and complex selectors are never corrupted. Every
     * rule is kept, so dynamic / JS-added classes survive intact.
     *
     * This is the safe minifier used for PASSTHROUGH builder sheets
     * (Elementor/Divi), which must be shrunk but never selector-pruned. Pair it
     * with balance() to also guarantee brace safety.
     *
     * @param string $css
     * @return string
     */
    public static function minify( $css ) {
        if ( ! is_string( $css ) || '' === $css ) {
            return (string) $css;
        }
        return self::process_fragment( $css, false );
    }

    /**
     * Collapse whitespace, strip comments, drop whitespace adjacent to the
     * structural characters `{ } ; , (`, and (when $rewrite_urls) rewrite
     * relative url() values to absolute — all while copying strings and url()
     * contents verbatim. Never removes whitespace around `:`, `>`, `+`, `~` or
     * inside `calc()`, so declaration values and complex selectors are
     * preserved exactly.
     *
     * @param string $s
     * @param bool   $rewrite_urls Resolve relative url() to absolute (default
     *                             true for the parsed path; false for the
     *                             passthrough minifier, which leaves url() alone).
     */
    private static function process_fragment( $s, $rewrite_urls = true ) {
        $n          = strlen( $s );
        $out        = '';
        $pending_ws = false;
        $i          = 0;

        while ( $i < $n ) {
            $c = $s[ $i ];

            // Comment → treated as whitespace.
            if ( '/' === $c && $i + 1 < $n && '*' === $s[ $i + 1 ] ) {
                $i += 2;
                while ( $i < $n && ! ( '*' === $s[ $i ] && $i + 1 < $n && '/' === $s[ $i + 1 ] ) ) {
                    $i++;
                }
                if ( $i < $n ) {
                    $i += 2;
                }
                $pending_ws = true;
                continue;
            }

            // Whitespace → collapse.
            if ( self::is_space( $c ) ) {
                $pending_ws = true;
                $i++;
                continue;
            }

            // String → verbatim.
            if ( '"' === $c || "'" === $c ) {
                self::flush_ws( $out, $pending_ws );
                $out .= self::consume_string( $s, $i, $n );
                continue;
            }

            // url( … ) → rewrite the value, or copy it verbatim (passthrough).
            if ( '(' === $c && self::ends_with_url( $out ) ) {
                if ( $rewrite_urls ) {
                    $i++; // past (
                    $out .= '(' . self::consume_and_rewrite_url( $s, $i, $n ) . ')';
                } else {
                    $out .= self::consume_url_raw( $s, $i, $n ); // cursor at '(', verbatim
                }
                continue;
            }

            // Closing brace — also drop any trailing `;` (empty last declaration).
            if ( '}' === $c ) {
                $out        = rtrim( $out, ';' );
                $pending_ws = false;
                $out       .= '}';
                $i++;
                continue;
            }

            // Structural chars that drop the space before them.
            if ( '{' === $c || ';' === $c || ',' === $c || ')' === $c ) {
                $pending_ws = false;
                $out       .= $c;
                $i++;
                continue;
            }

            // Opening paren keeps the space before it (`and (min-width…)`),
            // but suppresses the space after it (handled by flush_ws skip-set).
            if ( '(' === $c ) {
                self::flush_ws( $out, $pending_ws );
                $out .= '(';
                $i++;
                continue;
            }

            self::flush_ws( $out, $pending_ws );
            $out .= $c;
            $i++;
        }

        return $out;
    }

    /** Emit a single pending space unless it would sit right after `{ } ; , (`. */
    private static function flush_ws( &$out, &$pending_ws ) {
        if ( ! $pending_ws ) {
            return;
        }
        $pending_ws = false;
        if ( '' === $out ) {
            return;
        }
        $last = $out[ strlen( $out ) - 1 ];
        if ( '{' !== $last && '}' !== $last && ';' !== $last && ',' !== $last && '(' !== $last ) {
            $out .= ' ';
        }
    }

    /**
     * Consume a url() value (cursor just past `(`), rewrite it, and return the
     * inner text (quotes preserved). Cursor left just past the `)`.
     */
    private static function consume_and_rewrite_url( $s, &$i, $n ) {
        while ( $i < $n && self::is_space( $s[ $i ] ) ) {
            $i++;
        }

        $quote = '';
        $value = '';

        if ( $i < $n && ( '"' === $s[ $i ] || "'" === $s[ $i ] ) ) {
            $quote = $s[ $i ];
            $qstr  = self::consume_string( $s, $i, $n );
            $value = ( strlen( $qstr ) >= 2 && substr( $qstr, -1 ) === $quote )
                ? substr( $qstr, 1, -1 )
                : substr( $qstr, 1 );
            // Skip any junk up to ')'.
            while ( $i < $n && ')' !== $s[ $i ] ) {
                $i++;
            }
        } else {
            while ( $i < $n ) {
                $c = $s[ $i ];
                if ( '\\' === $c && $i + 1 < $n ) {
                    $value .= $c . $s[ $i + 1 ];
                    $i     += 2;
                    continue;
                }
                if ( ')' === $c ) {
                    break;
                }
                $value .= $c;
                $i++;
            }
            $value = trim( $value );
        }

        if ( $i < $n && ')' === $s[ $i ] ) {
            $i++;
        }

        return $quote . self::rewrite_url_value( $value ) . $quote;
    }

    /* ───────────────────────────────────────────────
     *  url() resolution (ported from the old fix_relative_urls)
     * ─────────────────────────────────────────────── */

    private static function init_url_context() {
        self::$force_https = false;
        self::$local_hosts = array();

        if ( ! function_exists( 'home_url' ) ) {
            return; // Non-WordPress context (tests): relative→absolute still works.
        }

        self::$force_https = ( ( function_exists( 'is_ssl' ) && is_ssl() )
            || 0 === strpos( (string) home_url(), 'https:' ) );

        $hosts = array();
        $hh    = self::host_of( home_url() );
        if ( $hh ) {
            $hosts[] = strtolower( $hh );
        }
        if ( function_exists( 'site_url' ) ) {
            $sh = self::host_of( site_url() );
            if ( $sh ) {
                $hosts[] = strtolower( $sh );
            }
        }
        self::$local_hosts = array_values( array_unique( array_filter( $hosts ) ) );
    }

    private static function host_of( $url ) {
        if ( function_exists( 'wp_parse_url' ) ) {
            return wp_parse_url( $url, PHP_URL_HOST );
        }
        return parse_url( $url, PHP_URL_HOST );
    }

    /** Resolve one url() value: relative→absolute, and internal http→https. */
    private static function rewrite_url_value( $url ) {
        if ( '' === $url ) {
            return $url;
        }

        // Absolute http:// — upgrade to https only for internal hosts.
        if ( preg_match( '#^http://#i', $url ) ) {
            if ( self::$force_https ) {
                $host = strtolower( (string) self::host_of( $url ) );
                if ( $host && in_array( $host, self::$local_hosts, true ) ) {
                    return preg_replace( '#^http://#i', 'https://', $url );
                }
            }
            return $url;
        }

        // Already https / data: — leave.
        if ( preg_match( '/^(https|data):/i', $url ) ) {
            return $url;
        }

        // Protocol-relative — leave.
        if ( 0 === strpos( $url, '//' ) ) {
            return $url;
        }

        $parsed = @parse_url( $url );
        if ( ! empty( $parsed['host'] ) || empty( $parsed['path'] ) || '/' === $parsed['path'][0] ) {
            return $url;
        }

        return self::resolve_url_path( self::$base_url . $url );
    }

    /** Collapse ./ and ../ segments (and WP Rocket/cache proxy paths). Ported verbatim. */
    private static function resolve_url_path( $url ) {

        $parts = parse_url( $url );
        if ( empty( $parts['path'] ) ) {
            return $url;
        }

        $segments = explode( '/', $parts['path'] );
        $resolved = array();

        foreach ( $segments as $seg ) {
            if ( '.' === $seg ) {
                continue;
            }
            if ( '..' === $seg ) {
                array_pop( $resolved );
            } else {
                $resolved[] = $seg;
            }
        }

        $clean_path = implode( '/', $resolved );

        $scheme_host = '';
        if ( ! empty( $parts['scheme'] ) ) {
            $scheme_host .= $parts['scheme'] . '://';
        }
        if ( ! empty( $parts['host'] ) ) {
            $scheme_host .= $parts['host'];
        }

        $resolved_url = $scheme_host . $clean_path;

        if ( ! empty( $parts['query'] ) ) {
            $resolved_url .= '?' . $parts['query'];
        }
        if ( ! empty( $parts['fragment'] ) ) {
            $resolved_url .= '#' . $parts['fragment'];
        }

        // WP Rocket / cache proxy rewrite.
        if ( preg_match( '#/wp-content/cache/[^/]+/.+?(/wp-content/.+)$#i', $clean_path, $m ) ) {
            $resolved_url = $scheme_host . $m[1];
        } elseif ( preg_match( '#/wp-content/cache/[^/]+/.+?(/themes/.+)$#i', $clean_path, $m ) ) {
            $resolved_url = $scheme_host . '/wp-content' . $m[1];
        } elseif ( preg_match( '#/wp-content/cache/[^/]+/.+?(/plugins/.+)$#i', $clean_path, $m ) ) {
            $resolved_url = $scheme_host . '/wp-content' . $m[1];
        } elseif ( preg_match( '#/wp-content/cache/[^/]+/.+?(/fonts/.+)$#i', $clean_path, $m ) ) {
            if ( preg_match( '#/themes/([^/]+)/.+?(/fonts/.+)$#i', $clean_path, $tm ) ) {
                $resolved_url = $scheme_host . '/wp-content/themes/' . $tm[1] . $tm[2];
            }
        }

        return $resolved_url;
    }

    /* ───────────────────────────────────────────────
     *  Selector decomposition (ported from the old sort_selectors)
     * ─────────────────────────────────────────────── */

    /**
     * Split a selector-list prelude on TOP-LEVEL commas only, so grouping
     * pseudo-classes (`:is(.a, .b)`) and attribute values (`[x=","]`) are not
     * broken apart.
     *
     * @param string $str
     * @return string[]
     */
    private static function split_top_commas( $str ) {
        $out    = array();
        $buf    = '';
        $n      = strlen( $str );
        $paren  = 0;
        $square = 0;
        $i      = 0;

        while ( $i < $n ) {
            $c = $str[ $i ];

            if ( '"' === $c || "'" === $c ) {
                $buf .= self::consume_string( $str, $i, $n );
                continue;
            }
            if ( '(' === $c ) {
                $paren++;
                $buf .= $c;
                $i++;
                continue;
            }
            if ( ')' === $c ) {
                if ( $paren > 0 ) {
                    $paren--;
                }
                $buf .= $c;
                $i++;
                continue;
            }
            if ( '[' === $c ) {
                $square++;
                $buf .= $c;
                $i++;
                continue;
            }
            if ( ']' === $c ) {
                if ( $square > 0 ) {
                    $square--;
                }
                $buf .= $c;
                $i++;
                continue;
            }
            if ( ',' === $c && 0 === $paren && 0 === $square ) {
                $out[] = trim( $buf );
                $buf   = '';
                $i++;
                continue;
            }

            $buf .= $c;
            $i++;
        }

        $t = trim( $buf );
        if ( '' !== $t ) {
            $out[] = $t;
        }

        return $out;
    }

    /**
     * Decompose selector strings into {selector, classes, ids, tags, atts}.
     * This is the old EasyOpt_Unused_CSS::sort_selectors() body, minus the
     * (now-unneeded) render() step — is_selector_used() consumes it unchanged.
     *
     * @param string[] $selectors
     * @return array
     */
    private static function decompose_selectors( $selectors ) {

        $selectors_data = array();

        foreach ( $selectors as $selector ) {

            $data = array(
                'selector' => trim( $selector ),
                'classes'  => array(),
                'ids'      => array(),
                'tags'     => array(),
                'atts'     => array(),
            );

            $sel = preg_replace( '/(?<!\\\\)::?[a-zA-Z0-9_-]+(\(.+?\))?/', '', $selector );

            $sel = preg_replace_callback(
                '/\[([A-Za-z0-9_:-]+)(\W?=[^\]]+)?\]/',
                function ( $m ) use ( &$data ) {
                    $data['atts'][] = $m[1];
                    return '';
                },
                $sel
            );

            $sel = preg_replace_callback(
                '/\.((?:[a-zA-Z0-9_-]+|\\\\.)+)/',
                function ( $m ) use ( &$data ) {
                    $data['classes'][] = stripslashes( $m[1] );
                    return '';
                },
                $sel
            );

            $sel = preg_replace_callback(
                '/#([a-zA-Z0-9_-]+)/',
                function ( $m ) use ( &$data ) {
                    $data['ids'][] = $m[1];
                    return '';
                },
                $sel
            );

            $sel = preg_replace_callback(
                '/[a-zA-Z0-9_-]+/',
                function ( $m ) use ( &$data ) {
                    $data['tags'][] = $m[0];
                    return '';
                },
                $sel
            );

            $selectors_data[] = array_filter( $data );
        }

        return array_filter( $selectors_data );
    }

    /* ───────────────────────────────────────────────
     *  At-rule classification (mirrors sabberworm)
     * ─────────────────────────────────────────────── */

    /** Lowercased at-rule name from a prelude/statement (without the `@`). */
    private static function at_rule_name( $prelude ) {
        if ( ! preg_match( '/^@([A-Za-z-]+)/', $prelude, $m ) ) {
            return '';
        }
        return strtolower( $m[1] );
    }

    /**
     * Block-group at-rules whose bodies contain nested rules to tree-shake.
     * Matches AtRule::BLOCK_RULES (plus vendor prefixes, per identifierIs()).
     * @keyframes is deliberately excluded — it is kept whole, like sabberworm.
     */
    private static function is_group_at_rule( $name ) {
        return (bool) preg_match(
            '/^(?:-\w+-)?(?:media|document|supports|region-style|font-feature-values|container|layer|scope|starting-style)$/',
            $name
        );
    }

    /* ───────────────────────────────────────────────
     *  Tiny helpers
     * ─────────────────────────────────────────────── */

    private static function is_space( $c ) {
        return ' ' === $c || "\t" === $c || "\n" === $c || "\r" === $c || "\f" === $c || "\v" === $c;
    }

    /** True when $str ends with the CSS function token `url` (boundary-checked). */
    private static function ends_with_url( $str ) {
        $len = strlen( $str );
        if ( $len < 3 ) {
            return false;
        }
        if ( 0 !== strcasecmp( substr( $str, -3 ), 'url' ) ) {
            return false;
        }
        if ( $len === 3 ) {
            return true;
        }
        $before = $str[ $len - 4 ];
        return ! ( ctype_alnum( $before ) || '_' === $before || '-' === $before );
    }
}
