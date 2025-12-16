<?php
/**
 * WC Gettext Map (Strict Resolver + English Fallback)
 *
 * - Unifies resolver with product swaps: per-request current language.
 * - Per-lang cache; never falls back to another language's map.
 * - If no map exists for the current lang -> pass through original English.
 * - Targets domains: 'woocommerce' and 'woocommerce-blocks' PHP strings.
 *
 * This file relies on JSON maps stored under:
 *   /mappings/woocommerce-<lang>.json
 *   /mappings/woocommerce-blocks-<lang>.json
 *
 * Example: woocommerce-de.json, woocommerce-zh.json
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Internal debug logger for this module.
 */
if ( ! function_exists( 'reeid_271r_log' ) ) {
    function reeid_271r_log( $label, $data = null ) {
        if ( function_exists( 'reeid_debug_log' ) ) {
            reeid_debug_log( 'S27.1R ' . $label, $data );
        }
    }
}

/**
 * Normalize language code into a short, safe form.
 *
 * Examples:
 *   "de-DE" -> "de-de"
 *   "zh_CN" -> "zh-cn"
 *   bad / empty -> "en"
 */
if ( ! function_exists( 'reeid_271r_norm' ) ) {
    function reeid_271r_norm( $v ) {
        $v = strtolower( substr( (string) $v, 0, 10 ) );

        return preg_match( '/^[a-z]{2}(?:[-_][a-z0-9]{2})?$/', $v ) ? $v : 'en';
    }
}

/**
 * Current language (strict) — same rules as runtime swaps.
 *
 * 1) If Woo inline resolver is available, use that.
 * 2) Fallback to cookie "site_lang".
 * 3) Final fallback: "en".
 */
if ( ! function_exists( 'reeid_271r_lang' ) ) {
    function reeid_271r_lang(): string {
        if ( function_exists( 'reeid_wc_resolve_lang_strong' ) ) {
            $l = (string) reeid_wc_resolve_lang_strong();
            if ( $l ) {
                return reeid_271r_norm( $l );
            }
        }

        if ( ! empty( $_COOKIE['site_lang'] ) ) {
            return reeid_271r_norm( (string) $_COOKIE['site_lang'] );
        }

        return 'en';
    }
}

/**
 * Load JSON map for a domain+lang (per-request cache, no cross-lang reuse).
 */
if ( ! function_exists( 'reeid_271r_load_map' ) ) {
    function reeid_271r_load_map( string $domain, string $lang ): array {
        static $cache = array(); // [domain][lang] => array.

        $domain = strtolower( trim( $domain ?: 'woocommerce' ) );
        $lang   = reeid_271r_norm( $lang );

        if ( isset( $cache[ $domain ][ $lang ] ) ) {
            return $cache[ $domain ][ $lang ];
        }

        // IMPORTANT: /mappings/ lives in plugin root, not /includes/.
        if ( defined( 'REEID_TRANSLATE_PATH' ) ) {
            $base_dir = trailingslashit( REEID_TRANSLATE_PATH );
        } else {
            // Fallback: go one level up from /includes/ to plugin root.
            $base_dir = trailingslashit( dirname( __DIR__ ) );
        }

        $dir = $base_dir . 'mappings/';
        $try = array();

        // Try full code first (ru-RU), then primary (ru).
        $try[] = $dir . $domain . '-' . $lang . '.json';

        if ( strpos( $lang, '-' ) !== false || strpos( $lang, '_' ) !== false ) {
            $parts = preg_split( '/[-_]/', $lang );
            $base  = $parts[0] ?? '';
            if ( $base ) {
                $try[] = $dir . $domain . '-' . $base . '.json';
            }
        }

        $map = array();
        $hit = null;

        foreach ( $try as $f ) {
            if ( is_readable( $f ) ) {
                $j = json_decode( (string) file_get_contents( $f ), true );
                if ( is_array( $j ) && $j ) {
                    $map = $j;
                    $hit = $f;
                    break;
                }
            }
        }

        $cache[ $domain ][ $lang ] = $map;

        reeid_271r_log(
            'LOAD',
            array(
                'domain' => $domain,
                'lang'   => $lang,
                'found'  => (bool) $map,
                'file'   => $hit ? basename( $hit ) : null,
            )
        );

        return $map;
    }
}

/**
 * Lookup a single string (with optional context) in the JSON map.
 */
if ( ! function_exists( 'reeid_271r_xlate' ) ) {
    function reeid_271r_xlate( string $domain, string $lang, string $text, string $context = '' ): string {
        $map = reeid_271r_load_map( $domain, $lang );

        // Try current language mapping first.
        if ( ! empty( $map ) ) {
            $k = ( '' !== $context ) ? $text . '|' . $context : $text;

            if ( isset( $map[ $k ] ) && is_string( $map[ $k ] ) ) {
                return (string) $map[ $k ];
            }
            if ( isset( $map[ $text ] ) && is_string( $map[ $text ] ) ) {
                return (string) $map[ $text ];
            }
        }

        // FALLBACK: Try plugin's default language setting (admin panel).
        $default = sanitize_text_field( get_option( 'reeid_translation_source_lang', 'en' ) );
        if ( $default && $default !== $lang ) {
            $map_default = reeid_271r_load_map( $domain, $default );

            if ( ! empty( $map_default ) ) {
                $k = ( '' !== $context ) ? $text . '|' . $context : $text;

                if ( isset( $map_default[ $k ] ) && is_string( $map_default[ $k ] ) ) {
                    return (string) $map_default[ $k ];
                }
                if ( isset( $map_default[ $text ] ) && is_string( $map_default[ $text ] ) ) {
                    return (string) $map_default[ $text ];
                }
            }
        }

        // Final fallback to source text (usually English).
        return $text;
    }
}

/**
 * Domains to handle.
 */
if ( ! function_exists( 'reeid_271r_domains' ) ) {
    function reeid_271r_domains(): array {
        return array( 'woocommerce', 'woocommerce-blocks' );
    }
}

/**
 * gettext filter (simple strings).
 */
add_filter(
    'gettext',
    function ( $translated, $text, $domain ) {
        if ( ! in_array( $domain, reeid_271r_domains(), true ) ) {
            return $translated;
        }

        // Do not run mapping in wp-admin.
        if ( is_admin() ) {
            return $translated;
        }

        $lang = reeid_271r_lang();
        $out  = reeid_271r_xlate( $domain, $lang, (string) $text, '' );

        // Log only when a different non-EN lang is present without a map (first time).
        if ( 'en' !== $lang && $out === $text ) {
            static $pinged = array();
            $k             = $domain . '|' . $lang;

            if ( empty( $pinged[ $k ] ) ) {
                $pinged[ $k ] = 1;
                reeid_271r_log(
                    'NO_MAP_FALLBACK',
                    array(
                        'domain' => $domain,
                        'lang'   => $lang,
                    )
                );
            }
        }

        return $out;
    },
    20,
    3
);

/**
 * gettext_with_context filter.
 */
add_filter(
    'gettext_with_context',
    function ( $translated, $text, $context, $domain ) {
        if ( ! in_array( $domain, reeid_271r_domains(), true ) ) {
            return $translated;
        }

        if ( is_admin() ) {
            return $translated;
        }

        $lang = reeid_271r_lang();

        return reeid_271r_xlate( $domain, $lang, (string) $text, (string) $context );
    },
    20,
    4
);

/**
 * ngettext (plural) filter.
 */
add_filter(
    'ngettext',
    function ( $translated, $single, $plural, $number, $domain ) {
        if ( ! in_array( $domain, reeid_271r_domains(), true ) ) {
            return $translated;
        }

        if ( is_admin() ) {
            return $translated;
        }

        $lang = reeid_271r_lang();
        $one  = reeid_271r_xlate( $domain, $lang, (string) $single, '' );
        $many = reeid_271r_xlate( $domain, $lang, (string) $plural, '' );

        return ( absint( $number ) === 1 ) ? $one : $many;
    },
    20,
    5
);
/**
 * UNIVERSAL WOO GETTEXT ENGINE (REEID)
 * - Loads woocommerce-<lang>.json
 * - Supports dynamic patterns, placeholders, counters
 */
add_filter('gettext', 'reeid_wc_translate_dynamic', 20, 3);
function reeid_wc_translate_dynamic($translated, $original, $domain) {

    // Only modify WooCommerce domain
    if ($domain !== 'woocommerce') {
        return $translated;
    }

    // Detect REEID language
    $lang = function_exists('reeid_wc_get_lang') ? reeid_wc_get_lang() : '';
    if (!$lang) {
        return $translated;
    }

    // JSON file exists?
    $file = __DIR__ . '/mappings/woocommerce-' . strtolower($lang) . '.json';
    if (!file_exists($file)) {
        return $translated;
    }

    static $CACHE = [];
    if (!isset($CACHE[$lang])) {
        $CACHE[$lang] = json_decode(file_get_contents($file), true);
    }
    $map = $CACHE[$lang];

    // -------------------------------------------------------
    // 1) DIRECT MATCH
    // -------------------------------------------------------
    if (isset($map[$original])) {
        return $map[$original];
    }

    // -------------------------------------------------------
    // 2) MATCH WITHOUT COUNT → e.g. "Reviews (12)"
    // Original JSON key: "Reviews"
    // -------------------------------------------------------
    if (preg_match('/^(.+?)\s*\((\d+)\)$/u', $original, $m)) {
        $base = trim($m[1]);
        $count = (int)$m[2];

        if (isset($map[$base])) {
            return $map[$base] . ' (' . $count . ')';
        }
    }

    // -------------------------------------------------------
    // 3) MATCH "Rated 4.50 out of 5"
    // JSON key: "Rated %s out of 5"
    // -------------------------------------------------------
    if (preg_match('/^Rated\s+([\d\.]+)\s+out\s+of\s+5$/u', $original, $m)) {
        if (isset($map['Rated %s out of 5'])) {
            return sprintf($map['Rated %s out of 5'], $m[1]);
        }
    }

    // -------------------------------------------------------
    // 4) MATCH "Showing 1–12 of 89 results"
    // JSON key: "Showing %1$s–%2$s of %3$s results"
    // -------------------------------------------------------
    if (preg_match('/^Showing\s+(\d+)[–-](\d+)\s+of\s+(\d+)\s+results$/u', $original, $m)) {
        if (isset($map['Showing %1$s–%2$s of %3$s results'])) {
            return sprintf($map['Showing %1$s–%2$s of %3$s results'], $m[1], $m[2], $m[3]);
        }
    }

    // -------------------------------------------------------
    // 5) MATCH ADD-TO-CART NOTICES ("Added to your cart")
    // JSON key: "%s has been added to your cart"
    // -------------------------------------------------------
    if (preg_match('/^(.+?)\s+has\s+been\s+added\s+to\s+your\s+cart$/u', $original, $m)) {
        if (isset($map['%s has been added to your cart'])) {
            return sprintf($map['%s has been added to your cart'], $m[1]);
        }
    }

    // -------------------------------------------------------
    // 6) MATCH SIMPLE COUNTS ("Out of stock", "In stock")
    // -------------------------------------------------------
    if (isset($map[$original])) {
        return $map[$original];
    }

    return $translated; // fallback
}
