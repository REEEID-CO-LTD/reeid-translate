<?php
require_once __DIR__ . "/rtqf-helpers.php"
/**
 * force-product-i18n.php
 * Safe head-only patch for single product pages.
 * Replaces <title>, OG/Twitter meta and updates application/ld+json blocks (Rank Math) when possible.
 *
 * Remove this file to disable.
 */
if (! defined('ABSPATH')) {
    exit;
}

add_action('template_redirect', function() {
    if (is_admin()) return;
    if (! function_exists('is_singular') || ! is_singular('product')) return;
    global $post;
    if (! $post || $post->post_type !== 'product') return;

    $lang = function_exists('rtqf_detect_lang') ? rtqf_detect_lang() : '';
    $src  = get_option('reeid_translation_source_lang', 'en');
    if ($lang === '' || $lang === $src) return;

    $packet = function_exists('rtqf_get_translation_packet') ? rtqf_get_translation_packet((int)$post->ID, $lang) : null;
    if (! is_array($packet)) return;

    $t_title   = isset($packet['title'])   ? trim(wp_strip_all_tags($packet['title']))   : '';
    $t_excerpt = isset($packet['excerpt']) ? trim(wp_strip_all_tags($packet['excerpt'])) : '';
    if ($t_title === '' && $t_excerpt === '') return;

    // Short mapping for inLanguage values (best-effort)
    $map = [
        'en' => 'en-US','zh' => 'zh-CN','de' => 'de-DE','fr' => 'fr-FR','es' => 'es-ES',
        'ar' => 'ar-SA','th' => 'th-TH','ja' => 'ja-JP','ko' => 'ko-KR'
    ];
    $inlang = isset($map[$lang]) ? $map[$lang] : strtoupper($lang) . '-' . strtoupper($lang);

    // Start buffering and transform only the <head> block.
    ob_start(function($html) use ($t_title, $t_excerpt, $inlang, $post) {
        if (! is_string($html) || $html === '') return $html;

        // Extract head block
        if (! preg_match('#<(head)([^>]*)>(.*?)</head>#is', $html, $m)) {
            return $html;
        }
        $head_full  = $m[0];
        $head_inner = $m[3];

        // 1) <title>
        if ($t_title !== '') {
            $head_inner = preg_replace('/<title>.*?<\/title>/is', '<title>' . esc_html($t_title) . '</title>', $head_inner, 1);
        }

        // 2) OG/Twitter meta (first occurrences only)
        if ($t_title !== '') {
            $head_inner = preg_replace(
                '/<meta[^>]*property=(["\'])og:title\1[^>]*>/is',
                '<meta property="og:title" content="' . esc_attr($t_title) . '" />',
                $head_inner,
                1
            );
            $head_inner = preg_replace(
                '/<meta[^>]*name=(["\'])twitter:title\1[^>]*>/is',
                '<meta name="twitter:title" content="' . esc_attr($t_title) . '" />',
                $head_inner,
                1
            );
        }
        if ($t_excerpt !== '') {
            $head_inner = preg_replace(
                '/<meta[^>]*property=(["\'])og:description\1[^>]*>/is',
                '<meta property="og:description" content="' . esc_attr($t_excerpt) . '" />',
                $head_inner,
                1
            );
            $head_inner = preg_replace(
                '/<meta[^>]*name=(["\'])twitter:description\1[^>]*>/is',
                '<meta name="twitter:description" content="' . esc_attr($t_excerpt) . '" />',
                $head_inner,
                1
            );
        }

        // 3) Replace textual occurrence of original title inside head (conservative)
        $src_title = trim(wp_strip_all_tags(get_the_title($post->ID)));
        if ($src_title !== '' && $t_title !== '') {
            $head_inner = preg_replace('/' . preg_quote($src_title, '/') . '/u', $t_title, $head_inner, 1);
        }

        // 4) Best-effort: find <script type="application/ld+json"> blocks and try to update them.
        //    If JSON decodes, update name/description/inLanguage for Product/ItemPage/WebPage nodes.
        $head_inner = preg_replace_callback(
            '#<script([^>]*)type=(["\'])application/ld\+json\2([^>]*)>(.*?)</script>#is',
            function($sc) use ($t_title, $t_excerpt, $inlang) {
                $attrs = $sc[1] . $sc[3];
                $body  = trim($sc[4]);
                // Try JSON decode
                $json = json_decode($body, true);
                if (! is_array($json)) {
                    // fallback: safe string replacements (conservative)
                    $body2 = $body;
                    if ($t_title !== '') {
                        $body2 = preg_replace('/"name"\s*:\s*"(.*?)"/i', '"name":"' . addcslashes($t_title, '"') . '"', $body2, 1);
                    }
                    if ($t_excerpt !== '') {
                        $body2 = preg_replace('/"description"\s*:\s*"(.*?)"/i', '"description":"' . addcslashes($t_excerpt, '"') . '"', $body2, 1);
                    }
                    $body2 = preg_replace('/"inLanguage"\s*:\s*"(.*?)"/i', '"inLanguage":"' . addcslashes($inlang, '"') . '"', $body2);
                    return '<script type="application/ld+json"' . $attrs . '>' . $body2 . '</script>';
                }

                // walk & update nodes
                $walker = function (&$node) use (&$walker, $t_title, $t_excerpt, $inlang) {
                    if (! is_array($node)) return;
                    // If node has @type and looks like Product/ItemPage/WebPage, update
                    $type = $node['@type'] ?? $node['type'] ?? null;
                    if ($type && in_array($type, ['Product', 'ItemPage', 'WebPage', 'Article', 'NewsArticle', 'ItemList'], true)) {
                        if ($t_title !== '' && isset($node['name'])) {
                            $node['name'] = $t_title;
                        }
                        if ($t_excerpt !== '' && isset($node['description'])) {
                            $node['description'] = $t_excerpt;
                        }
                        $node['inLanguage'] = $inlang;
                    }
                    // Recurse
                    foreach ($node as &$v) {
                        if (is_array($v)) $walker($v);
                    }
                };

                $walker($json);

                $enc = json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($enc === false) {
                    // encoding failed: return original
                    return $sc[0];
                }
                return '<script type="application/ld+json"' . $attrs . '>' . $enc . '</script>';
            },
            $head_inner
        );

        // 5) fix inLanguage occurrences elsewhere in head (limited)
        $head_inner = preg_replace('/"inLanguage"\s*:\s*"(.*?)"/is', '"inLanguage":"'. $inlang . '"', $head_inner, 5);

        // rebuild head and replace in HTML
        $new_head = '<head' . $m[2] . '>' . $head_inner . '</head>';
        $html = str_replace($head_full, $new_head, $html);

        return $html;
    }, PHP_OUTPUT_HANDLER_STDFLAGS);

}, 0);

/* ---------- SAFE HEAD OVERRIDE: replace OG/Twitter + RankMath JSON-LD ---------- */
add_action('template_redirect', function(){
    if ( is_admin() ) return;
    if ( ! function_exists('is_singular') || ! is_singular('product') ) return;
    global $post;
    if ( ! $post || $post->post_type !== 'product' ) return;

    $lang = function_exists('rtqf_detect_lang') ? rtqf_detect_lang() : '';
    $src  = get_option('reeid_translation_source_lang', 'en');
    if ( $lang === '' || $lang === $src ) return;

    $packet = function_exists('rtqf_get_translation_packet') ? rtqf_get_translation_packet((int)$post->ID, $lang) : null;
    if ( ! is_array($packet) ) return;

    $translated_title   = isset($packet['title'])   ? trim(wp_strip_all_tags($packet['title']))   : '';
    $translated_excerpt = isset($packet['excerpt']) ? trim(wp_strip_all_tags($packet['excerpt'])) : '';

    if ( $translated_title === '' && $translated_excerpt === '' ) return;

    // best-effort inLanguage map
    $map = [
        'en' => 'en-US','zh' => 'zh-CN','de' => 'de-DE','fr' => 'fr-FR','es' => 'es-ES',
        'ar' => 'ar-SA','th' => 'th-TH','ja' => 'ja-JP','ko' => 'ko-KR'
    ];
    $inlang = isset($map[$lang]) ? $map[$lang] : strtoupper($lang) . '-' . strtoupper($lang);

    // start buffering and patch HEAD only
    ob_start(function($html) use ($translated_title, $translated_excerpt, $inlang) {
        if (! is_string($html) || $html === '') return $html;

        // Replace first <title>
        if ($translated_title !== '') {
            $html = preg_replace('/<title>.*?<\/title>/is', '<title>' . esc_html($translated_title) . '</title>', $html, 1);
        }

        // Replace OG/Twitter meta (first occurrences)
        if ($translated_title !== '') {
            $html = preg_replace('/<meta\s+property=(["\'])og:title\1\s+content=(["\'])(.*?)\2\s*\/?>/is',
                                 '<meta property="og:title" content="' . esc_attr($translated_title) . '" />',
                                 $html, 1);
            $html = preg_replace('/<meta\s+name=(["\'])twitter:title\1\s+content=(["\'])(.*?)\2\s*\/?>/is',
                                 '<meta name="twitter:title" content="' . esc_attr($translated_title) . '" />',
                                 $html, 1);
        }
        if ($translated_excerpt !== '') {
            $html = preg_replace('/<meta\s+property=(["\'])og:description\1\s+content=(["\'])(.*?)\2\s*\/?>/is',
                                 '<meta property="og:description" content="' . esc_attr($translated_excerpt) . '" />',
                                 $html, 1);
            $html = preg_replace('/<meta\s+name=(["\'])twitter:description\1\s+content=(["\'])(.*?)\2\s*\/?>/is',
                                 '<meta name="twitter:description" content="' . esc_attr($translated_excerpt) . '" />',
                                 $html, 1);
        }

        // Replace Rank Math JSON-LD script(s) by parsing the JSON and swapping name/description/inLanguage
        $html = preg_replace_callback(
            '/<script\b[^>]*class=(["\']?)rank-math-schema\1[^>]*>(.*?)<\/script>/is',
            function($m) use ($translated_title, $translated_excerpt, $inlang) {
                $raw = $m[2];
                // try decode - tolerant
                $json = json_decode($raw, true);
                if (!is_array($json)) {
                    // fallback: try to extract JSON object inside and replace textual matches (best-effort)
                    $out = $m[0];
                    if ($translated_title !== '') {
                        $out = preg_replace('/"name"\s*:\s*"(.*?)"/i', '"name":"' . addslashes($translated_title) . '"', $out, 1);
                    }
                    if ($translated_excerpt !== '') {
                        $out = preg_replace('/"description"\s*:\s*"(.*?)"/i', '"description":"' . addslashes($translated_excerpt) . '"', $out, 1);
                    }
                    if ($inlang !== '') {
                        $out = preg_replace('/"inLanguage"\s*:\s*"(.*?)"/i', '"inLanguage":"' . addslashes($inlang) . '"', $out, 1);
                    }
                    return $out;
                }
                // walk & replace keys
                $walker = function(&$node) use (&$walker, $translated_title, $translated_excerpt, $inlang) {
                    if (!is_array($node)) return;
                    foreach ($node as $k => &$v) {
                        if (is_string($k)) {
                            if ($k === 'name' && $translated_title !== '') $v = $translated_title;
                            if ($k === 'description' && $translated_excerpt !== '') $v = $translated_excerpt;
                            if ($k === 'inLanguage' && $inlang !== '') $v = $inlang;
                        }
                        if (is_array($v)) $walker($v);
                    }
                };
                $walker($json);
                // encode back (use wp_json_encode if available)
                if (function_exists('wp_json_encode')) $new = wp_json_encode($json);
                else $new = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return '<script type="application/ld+json" class="rank-math-schema">' . $new . '</script>';
            },
            $html,
            -1
        );

        return $html;
    }, PHP_OUTPUT_HANDLER_STDFLAGS);
}, 1);



// QUICK-FIX: replace head meta + Rank Math JSON-LD with translated product title/description
add_action( 'template_redirect', function() {
    if ( is_admin() ) {
        return;
    }
    if ( ! function_exists( 'is_singular' ) || ! is_singular( 'product' ) ) {
        return;
    }

    global $post;
    if ( ! $post || $post->post_type !== 'product' ) {
        return;
    }

    // helper detect functions (best-effort; fall back to empty)
    $lang = function_exists( 'rtqf_detect_lang' ) ? (string) rtqf_detect_lang() : '';
    $src  = get_option( 'reeid_translation_source_lang', 'en' );
    if ( $lang === '' || $lang === $src ) {
        return;
    }

    $packet = function_exists( 'rtqf_get_translation_packet' ) ? rtqf_get_translation_packet( (int) $post->ID, $lang ) : null;
    if ( ! is_array( $packet ) ) {
        return;
    }

    $translated_title   = isset( $packet['title'] ) ? trim( wp_strip_all_tags( (string) $packet['title'] ) ) : '';
    $translated_excerpt = isset( $packet['excerpt'] ) ? trim( wp_strip_all_tags( (string) $packet['excerpt'] ) ) : '';
    if ( $translated_title === '' && $translated_excerpt === '' ) {
        return;
    }

    // map language codes for JSON-LD inLanguage (best-effort)
    $map = [
        'en' => 'en-US','zh' => 'zh-CN','de' => 'de-DE','fr' => 'fr-FR','es' => 'es-ES',
        'ar' => 'ar-SA','th' => 'th-TH','ja' => 'ja-JP','ko' => 'ko-KR'
    ];
    $inlang = isset( $map[ $lang ] ) ? $map[ $lang ] : strtoupper( $lang ) . '-' . strtoupper( $lang );

    ob_start( function( $html ) use ( $translated_title, $translated_excerpt, $inlang ) {
        if ( ! is_string( $html ) || $html === '' ) {
            return $html;
        }

        // find the head block
        if ( ! preg_match( '#<head\b[^>]*>(.*?)</head>#is', $html, $m ) ) {
            return $html;
        }
        $head_inner = $m[1];
        $new_head_inner = $head_inner;

        // Replace <title>
        if ( $translated_title !== '' ) {
            $new_head_inner = preg_replace( '/<title>.*?<\/title>/is', '<title>' . esc_html( $translated_title ) . '</title>', $new_head_inner, 1 );
        }

        // Replace description meta
        if ( $translated_excerpt !== '' ) {
            $new_head_inner = preg_replace( '/<meta\s+name=(["\'])description\1\s+content=(["\'])(.*?)\2\s*\/?>/is', '<meta name="description" content="' . esc_attr( $translated_excerpt ) . '" />', $new_head_inner, 1 );
        }

        // Replace common OG / Twitter tags (first occurrences only)
        if ( $translated_title !== '' ) {
            $new_head_inner = preg_replace( '/<meta\s+property=(["\'])og:title\1\s+content=(["\'])(.*?)\2\s*\/?>/is', '<meta property="og:title" content="' . esc_attr( $translated_title ) . '" />', $new_head_inner, 1 );
            $new_head_inner = preg_replace( '/<meta\s+name=(["\'])twitter:title\1\s+content=(["\'])(.*?)\2\s*\/?>/is', '<meta name="twitter:title" content="' . esc_attr( $translated_title ) . '" />', $new_head_inner, 1 );
        }
        if ( $translated_excerpt !== '' ) {
            $new_head_inner = preg_replace( '/<meta\s+property=(["\'])og:description\1\s+content=(["\'])(.*?)\2\s*\/?>/is', '<meta property="og:description" content="' . esc_attr( $translated_excerpt ) . '" />', $new_head_inner, 1 );
            $new_head_inner = preg_replace( '/<meta\s+name=(["\'])twitter:description\1\s+content=(["\'])(.*?)\2\s*\/?>/is', '<meta name="twitter:description" content="' . esc_attr( $translated_excerpt ) . '" />', $new_head_inner, 1 );
        }

        // Find Rank Math schema script(s) and try to patch Product/ItemPage nodes
        if ( preg_match_all( '#<script\b[^>]*class=(["\'])rank-math-schema\1[^>]*>(.*?)</script>#is', $new_head_inner, $scripts, PREG_SET_ORDER ) ) {
            foreach ( $scripts as $script ) {
                $full_tag = $script[0];
                $json_text = trim( $script[2] );
                // attempt to decode; if it's valid JSON, try to modify Product/WebPage nodes
                $decoded = json_decode( $json_text, true );
                if ( is_array( $decoded ) ) {
                    $changed = false;
                    // prefer @graph arrays
                    if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
                        foreach ( $decoded['@graph'] as &$node ) {
                            if ( ! is_array( $node ) ) {
                                continue;
                            }
                            $type = isset( $node['@type'] ) ? (string) $node['@type'] : '';
                            if ( in_array( $type, [ 'Product', 'ItemPage', 'WebPage', 'ImageObject' ], true ) ) {
                                if ( $translated_title !== '' && isset( $node['name'] ) ) {
                                    $node['name'] = $translated_title;
                                    $changed = true;
                                }
                                if ( $translated_excerpt !== '' && isset( $node['description'] ) ) {
                                    $node['description'] = $translated_excerpt;
                                    $changed = true;
                                }
                                // update inLanguage if present
                                if ( isset( $node['inLanguage'] ) ) {
                                    $node['inLanguage'] = $inlang;
                                    $changed = true;
                                }
                            }
                        }
                        unset( $node );
                    } else {
                        // fallback: top-level Product / name / description replacement
                        $types = [];
                        if ( isset( $decoded['@type'] ) ) {
                            $types = (array) $decoded['@type'];
                        }
                        if ( in_array( 'Product', $types, true ) || in_array( 'ItemPage', $types, true ) ) {
                            if ( $translated_title !== '' && isset( $decoded['name'] ) ) {
                                $decoded['name'] = $translated_title;
                                $changed = true;
                            }
                            if ( $translated_excerpt !== '' && isset( $decoded['description'] ) ) {
                                $decoded['description'] = $translated_excerpt;
                                $changed = true;
                            }
                            if ( isset( $decoded['inLanguage'] ) ) {
                                $decoded['inLanguage'] = $inlang;
                                $changed = true;
                            }
                        }
                    }

                    if ( $changed ) {
                        $new_json = json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
                        // replace the whole script tag with updated JSON-LD
                        $replacement = '<script type="application/ld+json" class="rank-math-schema">' . $new_json . '</script>';
                        $new_head_inner = str_replace( $full_tag, $replacement, $new_head_inner );
                    }
                }
            }
        }

        // Re-insert modified head into HTML
        return str_replace( $head_inner, $new_head_inner, $html );
    } );
}, 5 );