<?php

/**
 * REEID Translate — Gutenberg data helpers
 * 
 * Contains:
 * - SECTION 18 : Sanitizers + guards + Gutenberg attr rules
 * - SECTION 19 : Gutenberg JSON walker — collect translatable strings
 */




/*==============================================================================
CORE SANITIZERS + LANGUAGE PAIR GUARD + GUTENBERG TEXT ATTR
==============================================================================*/

/**
 * Minimal JSON sanitizer: strip ASCII control chars except \t \n \r
 */
if (! function_exists('reeid_json_sanitize_controls_v2')) {
    function reeid_json_sanitize_controls_v2(string $text, array &$stats = []): string
    {
        // Remove illegal ASCII control characters (except tab/newline/carriage return)
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);
        if ($clean !== $text) {
            $stats['removed_controls'] = true;
        }
        return $clean;
    }
}

/**
 * Detect and neutralize OpenAI error block "INVALID LANGUAGE PAIR".
 * Prevents accidental storing into SEO/meta fields.
 */
if (! function_exists('reeid_harden_invalid_lang_pair')) {
    function reeid_harden_invalid_lang_pair(&$val): bool
    {
        if (! is_string($val)) {
            return false;
        }

        if (stripos($val, 'INVALID LANGUAGE PAIR') !== false) {

            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S18/INVALID_LANGUAGE_PAIR', [
                    'original' => $val,
                ]);
            } else {
                error_log('REEID: INVALID LANGUAGE PAIR detected — neutralized.');
            }

            $val = '';
            return true;
        }

        return false;
    }
}

/**
 * Helper: detect shortcode-only strings (e.g. "[contact_form id="1"]")
 */
if (! function_exists('reeid_is_shortcode_like')) {
    function reeid_is_shortcode_like(string $s): bool
    {
        return (bool) preg_match('/^\s*\[[^\[\]]+\]\s*$/', $s);
    }
}

/**
 * Gutenberg/Classic: detect attribute keys that are NOT text.
 * Prevents colors, URLs, IDs, numeric tokens, CSS from being sent to OpenAI.
 */
if (! function_exists('reeid_gutenberg_is_non_text_attr')) {
    function reeid_gutenberg_is_non_text_attr(string $attr_key, $attr_val, string $full_path = ''): bool
    {
        $k = strtolower($attr_key);

        /* Skip prefixes where attributes are always non-textual */
        static $skip_prefixes = [
            'style', 'color', 'background', 'border', 'epcustom', 'epanimation',
            'layout', 'spacing',
        ];
        foreach ($skip_prefixes as $pre) {
            if (strpos($k, $pre) === 0) {
                return true;
            }
        }

        /* Explicit non-text keys */
        static $skip_keys = [
            'classname', 'anchor', 'align', 'backgroundcolor', 'textcolor',
            'gradient', 'layout', 'id', 'aria', 'arialabel', 'url', 'href',
            'rel', 'target', 'name', 'slug', 'tagname', 'tag', 'reference',
            // design keys
            'width', 'height', 'minheight', 'maxheight', 'gap', 'padding',
            'margin', 'border', 'radius', 'opacity',
            // media/urls
            'mediaurl', 'src', 'srcset', 'sizes',
        ];
        if (in_array($k, $skip_keys, true)) {
            return true;
        }

        /* Skip Gutenberg design blocks (attrs.style.*) */
        if ($full_path !== '' && stripos($full_path, '.attrs.style.') !== false) {
            return true;
        }

        /* Value must be string for translation */
        if (! is_string($attr_val)) {
            return true;
        }

        $v = trim($attr_val);
        if ($v === '') {
            return true;
        }

        /* Skip URLs / protocols / anchors */
        if (preg_match('/^(https?:|mailto:|tel:|data:|\/\/|#)/i', $v)) {
            return true;
        }

        /* Skip color tokens: hex, RGB, gradients */
        if (preg_match('/^(#[0-9a-f]{3,8})$/i', $v)) return true;
        if (preg_match('/^(rgb|rgba|hsl|hsla)\s*\(/i', $v)) return true;
        if (stripos($v, 'linear-gradient(') !== false) return true;
        if (stripos($v, 'radial-gradient(') !== false) return true;

        /* CSS variables */
        if (preg_match('/^var\(\s*[-a-z0-9_]+\s*\)$/i', $v)) {
            return true;
        }

        
       // Skip pure numbers but NOT prices like $39 or 39$
if (preg_match('/^[0-9]+([.,][0-9]+)?$/', $v)) {
    return true;
}

// Currency formats must be kept
if (preg_match('/[\$\€\£\฿\₱\¥]/u', $v)) {
    return false;
}

        /* Everything else → textual */
        return false;
    }
}


/* =============================================================================================================
    Gutenberg walker - collect translatable strings
 ==============================================================================================================*/

if ( ! function_exists('reeid_gutenberg_walk_and_collect') ) {
    function reeid_gutenberg_walk_and_collect(array $blocks, &$map = [], string $prefix = ''): array
    {
        // Keys likely containing user-facing text
        static $textish_keys = [
            'content',
            'title',
            'caption',
            'label',
            'text',
            'value',
            'placeholder',
            'description',
            'alt',
            'subtitle',
        ];

        foreach ($blocks as $i => $block) {
            $key = $prefix . $i;

            /* ============================================================
               ATTRIBUTES (collect selected textish keys only)
               ============================================================ */
            if (isset($block['attrs']) && is_array($block['attrs'])) {
                foreach ($block['attrs'] as $attrk => $attrv) {

                    $path = $key . '.attrs.' . $attrk;

                    // Only textish keys
                    if (! in_array(strtolower((string) $attrk), $textish_keys, true)) {
                        continue;
                    }
                    // Skip design/URLs/empty/etc.
                    if (reeid_gutenberg_is_non_text_attr((string)$attrk, $attrv, $path)) {
                        continue;
                    }

                    if (is_string($attrv)) {
                        $val = trim($attrv);
                        if ($val !== '' && ! reeid_is_shortcode_like($val)) {
                            $map[$path] = $attrv;
                        }
                    }
                }
            }

            /* ============================================================
               innerHTML
               ============================================================ */
            if (!empty($block['innerHTML']) && is_string($block['innerHTML'])) {

                $plain = trim(wp_strip_all_tags($block['innerHTML']));
                if ($plain !== '' && ! reeid_is_shortcode_like(trim($block['innerHTML']))) {

                    // Skip obvious CSS/style blobs
                    if (preg_match('/[{}]|\\.eplus_styles|flex-basis|gap:|<style\b|\.[a-zA-Z0-9_-]+\s*\{/i', $block['innerHTML'])) {
                        // design chunk, ignore
                    } else {
                        $map[$key . '.innerHTML'] = $block['innerHTML'];
                    }
                }
            }

            /* ============================================================
               innerContent segments
               ============================================================ */
            if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
                foreach ($block['innerContent'] as $ci => $segment) {

                    if (!is_string($segment)) {
                        continue;
                    }

                    $seg_trim = trim($segment);
                    if ($seg_trim === '') {
                        continue;
                    }
                    if (reeid_is_shortcode_like($seg_trim)) {
                        continue;
                    }

                    // NEW: skip Woo attributes table
                    if (preg_match('/woocommerce-product-attributes|woocommerce-Tabs-panel--additional_information/i', $segment)) {
                        continue;
                    }

                    $plain = trim(wp_strip_all_tags($segment));
                    if ($plain === '') {
                        continue;
                    }

                    // Skip CSS/style-like pieces
                    if (preg_match('/[{}]|\\.eplus_styles|flex-basis|gap:|;\\s*$/i', $segment)) {
                        continue;
                    }

                    $map[$key . '.innerContent.' . $ci] = $segment;
                }
            }

            /* ============================================================
               innerBlocks recurse
               ============================================================ */
            if (!empty($block['innerBlocks'])) {
                reeid_gutenberg_walk_and_collect($block['innerBlocks'], $map, $key . '.');
            }
        }

        return $map;
    }
}

/* ==========================================================================
 * Gutenberg walker: replace translated strings (in-memory)
 * ==========================================================================*/
if ( ! function_exists('reeid_gutenberg_walk_and_replace') ) {
    function reeid_gutenberg_walk_and_replace(array $blocks, array $translated_map, string $prefix = ''): array
    {
        foreach ($blocks as $i => &$block) {
            $key = $prefix . $i;

            // Attributes
            if (isset($block['attrs']) && is_array($block['attrs'])) {
                foreach ($block['attrs'] as $attrk => &$attrv) {
                    $map_key = $key . '.attrs.' . $attrk;
                    if (isset($translated_map[$map_key]) && is_string($attrv)) {
                        $attrv = $translated_map[$map_key];
                    }
                }
            }

            // innerHTML
            if (!empty($block['innerHTML']) && is_string($block['innerHTML'])) {
                $map_key = $key . '.innerHTML';
                if (isset($translated_map[$map_key])) {
                    $block['innerHTML'] = $translated_map[$map_key];
                }
            }

            // innerContent
            if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
                foreach ($block['innerContent'] as $ci => &$segment) {
                    $map_key = $key . '.innerContent.' . $ci;
                    if (isset($translated_map[$map_key]) && is_string($segment)) {
                        $segment = $translated_map[$map_key];
                    }
                }
            }

            // Recursive innerBlocks
            if (!empty($block['innerBlocks'])) {
                $block['innerBlocks'] = reeid_gutenberg_walk_and_replace(
                    $block['innerBlocks'],
                    $translated_map,
                    $key . '.'
                );
            }
        }
        return $blocks;
    }
}

/* ==========================================================================
 * HTML Text-Node Translator (preserves whitespace + tags)
 * ========================================================================== */
if (! function_exists('reeid_html_translate_textnodes_fragment')) {
    function reeid_html_translate_textnodes_fragment(string $html, string $source_lang, string $target_lang)
    {
        // whitespace splitter
        $split_ws = static function (string $s): array {
            if (preg_match('/^([\x{00A0}\s]*)(.*?)([\x{00A0}\s]*)$/u', $s, $m)) {
                return [$m[1], $m[2], $m[3]];
            }
            return ['', $s, ''];
        };

        // Fast path: no tags
        if (strpos($html, '<') === false || !preg_match('/<[^>]+>/', $html)) {
            [$left, $core, $right] = $split_ws($html);
            if ($core === '') {
                return $html;
            }
            $out = reeid_translate_html_with_openai($core, $source_lang, $target_lang, 'gutenberg', 'neutral');
            $translated = is_wp_error($out) ? $core : (string) $out;
            return $left . $translated . $right;
        }

        if (!class_exists('DOMDocument')) {
            [$left, $core, $right] = $split_ws($html);
            if ($core === '') {
                return $html;
            }
            $out = reeid_translate_html_with_openai($core, $source_lang, $target_lang, 'gutenberg', 'neutral');
            $translated = is_wp_error($out) ? $core : (string) $out;
            return $left . $translated . $right;
        }

        // DOM load
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = true;
        libxml_use_internal_errors(true);

        $html_utf8 = $html;
        if (!function_exists('mb_detect_encoding') || !mb_detect_encoding($html_utf8, 'UTF-8', true)) {
            if (function_exists('mb_convert_encoding')) {
                $html_utf8 = mb_convert_encoding($html_utf8, 'UTF-8', 'auto');
            }
        }

        $flags = 0;
        if (defined('LIBXML_HTML_NOIMPLIED'))  $flags |= LIBXML_HTML_NOIMPLIED;
        if (defined('LIBXML_HTML_NODEFDTD'))   $flags |= LIBXML_HTML_NODEFDTD;

        $ok = $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div id="reeid-x-root">' . $html_utf8 . '</div>',
            $flags
        );
        libxml_clear_errors();

        if (!$ok) {
            return $html;
        }

        $xpath = new DOMXPath($dom);
        $textNodes = $xpath->query('//div[@id="reeid-x-root"]//text()[not(ancestor::script)][not(ancestor::style)]');

        if (!$textNodes || $textNodes->length === 0) {
            $root = $dom->getElementById('reeid-x-root');
            if (!$root) {
                return $html;
            }
            $out = '';
            foreach ($root->childNodes as $child) {
                $out .= $dom->saveHTML($child);
            }
            return $out === '' ? $html : $out;
        }

        $map       = [];
        $ws        = [];
        $raw_cache = [];
        $idx       = 0;

        foreach ($textNodes as $n) {
            $raw = (string)$n->nodeValue;
            $raw_cache[(string)$idx] = $raw;

            [$left, $core, $right] = $split_ws($raw);
            $ws[(string)$idx] = [$left, $right];

            if ($core !== '') {
                $map[(string)$idx] = $core;
            }
            $idx++;
        }

        // Chunking
        $chunks = !empty($map) && function_exists('reeid_chunk_translation_map')
            ? reeid_chunk_translation_map($map, 4000)
            : (!empty($map) ? [$map] : []);

        $translated_data = [];

        foreach ($chunks as $chunk) {

            $has_content = false;
            foreach ($chunk as $v) {
                if ($v !== '') {
                    $has_content = true;
                    break;
                }
            }

            if (!$has_content) {
                $translated_data += $chunk;
                continue;
            }

            $prompt = sprintf(
                            // translators: %1$s source language code, %2$s target language code.
                __('Translate each JSON value FROM %1$s TO %2$s. Keys MUST remain identical.', 'reeid-translate'),
                $source_lang,
                $target_lang
            );

            $prompt .= "\nTranslate ONLY natural language text. Do NOT modify punctuation or whitespace.\nReturn STRICT JSON.";

            $json_in = wp_json_encode(
                $chunk,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            $resp = reeid_translate_html_with_openai(
                $prompt . "\n" . $json_in,
                $source_lang,
                $target_lang,
                'gutenberg',
                'neutral'
            );

            if (
                is_wp_error($resp)
                || !is_string($resp)
                || !preg_match('/\{.*\}/s', $resp, $m)
            ) {
                $translated_data += $chunk;
                continue;
            }

            $json_clean = function_exists('reeid_json_sanitize_controls_v2')
                ? reeid_json_sanitize_controls_v2($m[0], $s = [])
                : $m[0];

            $data = function_exists('reeid_safe_json_decode')
                ? reeid_safe_json_decode($json_clean)
                : json_decode($json_clean, true);

            if (is_array($data)) {
                $translated_data += $data;
            } else {
                $translated_data += $chunk;
            }
        }

        // Reinjection
        $idx = 0;
        foreach ($textNodes as $n) {
            $k = (string)$idx;
            $raw = $raw_cache[$k] ?? (string)$n->nodeValue;
            [$left, $core, $right] = $split_ws($raw);

            if ($core !== '') {
                $core_tr = isset($translated_data[$k]) && is_string($translated_data[$k])
                    ? $translated_data[$k]
                    : $core;

                $n->nodeValue = $left . $core_tr . $right;
            } else {
                // whitespace-only
                $n->nodeValue = $raw;
            }
            $idx++;
        }

        // extract innerHTML
        $root = $dom->getElementById('reeid-x-root');
        if (!$root) {
            return $html;
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }
}

/* ==========================================================================
 * Chunker for Gutenberg translation maps
 * ========================================================================== */
if ( ! function_exists('reeid_chunk_translation_map') ) {
    function reeid_chunk_translation_map(array $map, int $max_bytes = 4000): array
    {
        $chunks = [];
        $cur    = [];
        $size   = 0;

        foreach ($map as $k => $v) {
            $entry = is_scalar($v)
                ? (string)$v
                : wp_json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $len = strlen($entry);

            if ($size + $len > $max_bytes && !empty($cur)) {
                $chunks[] = $cur;
                $cur      = [];
                $size     = 0;
            }

            $cur[$k] = $v;
            $size   += $len;
        }

        if (!empty($cur)) {
            $chunks[] = $cur;
        }

        return $chunks;
    }
}
