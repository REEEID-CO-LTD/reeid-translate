<?php
/**
 * REEID Translate — WooCommerce helper utilities
 *
 * Contains:
 * - SECTION 20 : WC sanitizers, normalizers, language resolvers
 * - WC inline guards
 * - Product payload field safe extractor
 */




if (! function_exists('reeid_translate_product')) {
    function reeid_translate_product(
        int $src_id,
        string $src_lang,
        string $dst_lang,
        string $tone,
        string $prompt,
        int $dst_id,
        string $publish_mode = 'publish'
    ) {
        if (! class_exists('WC_Product')) {
            return new WP_Error('no_wc', 'WooCommerce not active.');
        }

        $src_product = wc_get_product($src_id);
        if (! $src_product) {
            return new WP_Error('no_product', 'Source product missing.');
        }

        // === Translate title & slug ===
        $title_src = $src_product->get_name();
        $title_tr  = reeid_translate_html_with_openai($title_src, $src_lang, $dst_lang, 'classic', $tone);
        if (is_wp_error($title_tr) || empty($title_tr)) {
            $title_tr = $title_src;
        }

        // Always sanitize translated title into a slug
        $slug_tr = function_exists('reeid_sanitize_native_slug')
            ? reeid_sanitize_native_slug($title_tr)
            : sanitize_title($title_tr);

        // === Translate content ===
        $long_src  = $src_product->get_description();
        $long_tr   = reeid_translate_html_with_openai($long_src, $src_lang, $dst_lang, 'classic', $tone);

        $short_src = $src_product->get_short_description();
        $short_tr  = reeid_translate_html_with_openai($short_src, $src_lang, $dst_lang, 'classic', $tone);

        // === Update/create target product ===
        $update = [
            'ID'           => $dst_id,
            'post_title'   => $title_tr,
            'post_name'    => $slug_tr,
            // 'post_content' intentionally omitted to avoid overwriting main content
            'post_excerpt' => is_string($short_tr) ? $short_tr : $short_src,
            'post_status'  => $publish_mode === 'draft' ? 'draft' : 'publish',
            'post_type'    => 'product',
        ];

        // If you still want to *store* the translated long description in DB without touching post_content,
        // store it as postmeta instead:
        if (is_string($long_tr) && $long_tr !== '') {
            update_post_meta($dst_id, '_reeid_translated_description_' . $dst_lang, $long_tr);
        }


        $new_id = reeid_safe_wp_update_post($update, true);
        if (is_wp_error($new_id)) {
            return $new_id;
        }

        // === Copy non-text meta (price, stock etc.) ===
        $dst_product = wc_get_product($new_id);
        if ($dst_product) {
            $fields_to_copy = [
                'sku',
                'regular_price',
                'sale_price',
                'manage_stock',
                'stock_quantity',
                'stock_status',
                'weight',
                'length',
                'width',
                'height',
                'tax_class',
                'downloadable',
                'virtual'
            ];
            foreach ($fields_to_copy as $key) {
                $getter = "get_$key";
                $setter = "set_$key";
                if (method_exists($src_product, $getter) && method_exists($dst_product, $setter)) {
                    $dst_product->$setter($src_product->$getter());
                }
            }
            $dst_product->save();



 /* ---------------------------------------------------------------------------
 * 1) Translate & attach product attributes
 * --------------------------------------------------------------------------- */

            if (! function_exists('reeid_translate_product_attributes')) {
                /**
                 * Translate product attributes from $src_product and attach them to $dst_product.
                 *
                 * Behavior:
                 * - Does NOT create/modify global attribute taxonomies. Creates product-level
                 *   (non-taxonomy) attributes on the target product with translated label & option values.
                 * - Preserves visibility and variation flags.
                 *
                 * @param WC_Product $src_product
                 * @param WC_Product $dst_product
                 * @param string     $src_lang
                 * @param string     $dst_lang
                 * @param string     $tone
                 */


                function reeid_translate_product_attributes($src_product, $dst_product, $src_lang, $dst_lang, $tone = 'neutral')
                {
                    if (! class_exists('WC_Product')) {
                        return;
                    }

                    if (! $src_product || ! $dst_product) {
                        return;
                    }

                    $src_attrs = $src_product->get_attributes();
                    if (empty($src_attrs) || ! is_array($src_attrs)) {
                        return;
                    }

                    // Collect strings to translate in a single batch to minimize API calls.
                    // We'll build a map key -> original and later map translated results back.
                    $batch_map = [];
                    // Keep metadata of each attribute to reconstruct after translation.
                    $attrs_meta = [];

                    foreach ($src_attrs as $attr_key => $attr) {
                        // Normalize attribute info
                        if (is_object($attr) && method_exists($attr, 'get_options')) {
                            $label     = (string) $attr->get_name();
                            $options   = (array) $attr->get_options();
                            $visible   = (bool) $attr->get_visible();
                            $variation = (bool) $attr->get_variation();
                            $is_tax    = method_exists($attr, 'is_taxonomy') ? (bool) $attr->is_taxonomy() : false;
                            $tax_name  = method_exists($attr, 'get_name') ? $attr->get_name() : '';
                        } else {
                            // Legacy/array format
                            $label     = isset($attr['name']) ? (string) $attr['name'] : (string) $attr_key;
                            $options   = isset($attr['options']) ? (array) $attr['options'] : (isset($attr['value']) ? array_map('trim', explode('|', $attr['value'])) : []);
                            $visible   = ! empty($attr['visible']);
                            $variation = ! empty($attr['variation']);
                            $is_tax    = ! empty($attr['is_taxonomy']);
                            $tax_name  = isset($attr['taxonomy']) ? $attr['taxonomy'] : '';
                        }

                        // Prepare label key
                        $label_key = "attr:label:{$attr_key}";
                        $batch_map[$label_key] = $label;

                        // Prepare options keys
                        $opt_keys = [];
                        foreach ($options as $oi => $opt) {
                            $opt_key = "attr:opt:{$attr_key}:{$oi}";
                            $opt_value = (string) $opt;

                            // If taxonomy-based attribute, try to resolve term to human name
                            if ($is_tax && ! empty($tax_name)) {
                                $resolved = null;
                                if (is_numeric($opt_value)) {
                                    $t = get_term(intval($opt_value), $tax_name);
                                    if ($t && ! is_wp_error($t)) $resolved = $t->name;
                                }
                                if ($resolved === null) {
                                    $t = get_term_by('slug', $opt_value, $tax_name);
                                    if ($t && ! is_wp_error($t)) $resolved = $t->name;
                                }
                                if ($resolved !== null) {
                                    $opt_value = (string) $resolved;
                                }
                            }

                            $batch_map[$opt_key] = $opt_value;
                            $opt_keys[] = $opt_key;
                        }

                        $attrs_meta[$attr_key] = [
                            'label_key'  => $label_key,
                            'opt_keys'   => $opt_keys,
                            'visible'    => $visible,
                            'variation'  => $variation,
                        ];
                    }

                    // If nothing to translate, exit early
                    if (empty($batch_map)) {
                        return;
                    }

                    // Batch translate map -> tries to preserve HTML structure if present
                    $json_in = wp_json_encode($batch_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    $prompt = "Task: Translate the JSON VALUES to {$dst_lang}. Keep keys EXACTLY unchanged.\n"
                        . "- Preserve HTML tags/attributes if values contain HTML. Do NOT alter placeholders like %s, numbers, or punctuation.\n"
                        . "- Return STRICT JSON object only.";

                    $api_args = ['response_format' => ['type' => 'json_object']];

                    $out = reeid_translate_html_with_openai($prompt . "\n" . $json_in, $src_lang, $dst_lang, 'classic', $tone, $api_args);

                    $translated_map = [];
                    if (is_string($out)) {
                        if (preg_match('/\{.*\}/s', $out, $m)) $clean = $m[0];
                        else $clean = $out;
                        if (function_exists('reeid_json_sanitize_controls_v2')) {
                            $clean = reeid_json_sanitize_controls_v2($clean, []);
                        }
                        if (function_exists('reeid_safe_json_decode')) {
                            $decoded = reeid_safe_json_decode($clean);
                        } else {
                            $decoded = json_decode($clean, true);
                        }
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $translated_map = $decoded;
                        }
                    }

                    // Fallback: if decoding failed, translate item-by-item
                    if (empty($translated_map)) {
                        foreach ($batch_map as $k => $v) {
                            $tr = reeid_translate_html_with_openai($v, $src_lang, $dst_lang, 'classic', $tone);
                            $translated_map[$k] = (is_wp_error($tr) || empty($tr)) ? $v : (string) $tr;
                        }
                    }

                    // Reconstruct attributes for target product
                    $new_attrs = [];

                    foreach ($attrs_meta as $attr_key => $meta) {
                        $label_tr = isset($translated_map[$meta['label_key']]) ? (string) $translated_map[$meta['label_key']] : '';

                        $options_tr = [];
                        foreach ($meta['opt_keys'] as $ok) {
                            $val = isset($translated_map[$ok]) ? (string) $translated_map[$ok] : '';
                            if ($val !== '') $options_tr[] = $val;
                        }

                        // Build WC_Product_Attribute (product-level)
                        if (class_exists('WC_Product_Attribute')) {
                            $new_attr = new WC_Product_Attribute();
                            if (method_exists($new_attr, 'set_id')) {
                                $new_attr->set_id(0);
                            }
                            if (method_exists($new_attr, 'set_name')) {
                                // Name should be a string; use translated label or fallback to attr_key
                                $new_attr->set_name($label_tr !== '' ? $label_tr : $attr_key);
                            }
                            if (method_exists($new_attr, 'set_options')) {
                                $new_attr->set_options($options_tr);
                            } else {
                                $new_attr->options = $options_tr;
                            }
                            if (method_exists($new_attr, 'set_visible')) {
                                $new_attr->set_visible($meta['visible']);
                            } else {
                                $new_attr->visible = $meta['visible'];
                            }
                            if (method_exists($new_attr, 'set_variation')) {
                                $new_attr->set_variation($meta['variation']);
                            } else {
                                $new_attr->variation = $meta['variation'];
                            }
                            if (method_exists($new_attr, 'set_taxonomy')) {
                                $new_attr->set_taxonomy(false);
                            }

                            $new_attrs[] = $new_attr;
                        } else {
                            // Fallback array structure for older WC
                            $key = sanitize_title($label_tr !== '' ? $label_tr : $attr_key);
                            $new_attrs[$key] = [
                                'name'        => ($label_tr !== '' ? $label_tr : $attr_key),
                                'value'       => implode(' | ', $options_tr),
                                'is_visible'  => $meta['visible'] ? 1 : 0,
                                'is_variation' => $meta['variation'] ? 1 : 0,
                                'is_taxonomy' => 0,
                            ];
                        }
                    }

                    // Attach attributes and save
                    try {
                        if (! empty($new_attrs)) {
                            if (method_exists($dst_product, 'set_attributes')) {
                                $dst_product->set_attributes($new_attrs);
                            } else {
                                // Older Woo: write _product_attributes post meta
                                $post_id = method_exists($dst_product, 'get_id') ? $dst_product->get_id() : 0;
                                if ($post_id) {
                                    update_post_meta($post_id, '_product_attributes', $new_attrs);
                                }
                            }
                            if (method_exists($dst_product, 'save')) {
                                $dst_product->save();
                            }
                        }
                    } catch (Throwable $e) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('reeid_translate_product_attributes error: ' . $e->getMessage());
                        }
                    }
                }
            }


/* ---------------------------------------------------------------------------
 * 2) Translate WooCommerce static strings (gettext filter + caching)
 * --------------------------------------------------------------------------- */


            /**
             * Detect current target language code.
             * Supports Polylang (pll_current_language), WPML (ICL_LANGUAGE_CODE) and falls back to get_locale().
             *
             * Returns a short language code like 'de' or locale like 'en_US' fallback.
             *
             * @return string
             */
            if (! function_exists('reeid_detect_target_language')) {
                function reeid_detect_target_language(): string
                {
                    // Polylang
                    if (function_exists('pll_current_language')) {
                        $lang = pll_current_language();
                        if ($lang) return (string) $lang;
                    }

                    // WPML
                    if (defined('ICL_LANGUAGE_CODE')) {
                        return (string) ICL_LANGUAGE_CODE;
                    }

                    // Fallback to WP locale (en_US -> en)
                    $locale = get_locale() ?: '';
                    if ($locale === '') return '';
                    if (strpos($locale, '_') !== false) {
                        return substr($locale, 0, strpos($locale, '_'));
                    }
                    return (string) $locale;
                }
            }


            /**
             * Clear cached static translations for a language (helper).
             *
             * @param string $lang
             * @return void
             */
            if (! function_exists('reeid_clear_woo_string_cache')) {
                function reeid_clear_woo_string_cache($lang): void
                {
                    $opt_name = 'reeid_woo_strings_' . sanitize_key((string) $lang);
                    delete_option($opt_name);
                }
            }


            /**
             * Return an associative map original => translated for common Woo strings for $target_lang.
             * Caches translations in an option named 'reeid_woo_strings_<lang>'.
             *
             * NOTE: This function may call reeid_translate_html_with_openai(). The outer gettext filter
             * sets a recursion guard to avoid infinite loops.
             *
             * @param string $target_lang
             * @param bool   $force_refresh Force re-translation even if cached
             * @return array
             */
            if (! function_exists('reeid_get_translated_woocommerce_strings')) {
                function reeid_get_translated_woocommerce_strings($target_lang, $force_refresh = false): array
                {
                    $target_lang = (string) $target_lang;
                    if ($target_lang === '') return [];

                    $opt_name = 'reeid_woo_strings_' . sanitize_key($target_lang);
                    if (! $force_refresh) {
                        $cached = get_option($opt_name);
                        if (is_array($cached) && ! empty($cached)) {
                            return $cached;
                        }
                    }
                    // === HARD GUARD: never build translations on the frontend ===
                    // If cache is missing and this is not admin or AJAX, don't call OpenAI here.
                    // Return empty map so gettext falls back to original strings.
                    if (! is_admin() && ! wp_doing_ajax()) {
                        return [];
                    }

                    // List of strings to translate (extendable)
                    $strings = [
                        'Home',
                        'Description',
                        'Additional information',
                        'Reviews',
                        'Related products',
                        'You may also like&hellip;',
                        'SKU:',
                        'Category:',
                        'Tags:',
                        'Only %s left in stock - order soon.',
                        'Add to cart',
                        'Out of stock',
                        'In stock',
                        'View cart',
                        'Uncategorized',
                    ];

                    // Prepare input map
                    $input_map = [];
                    foreach ($strings as $s) $input_map[$s] = $s;

                    $json_in = wp_json_encode($input_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    $prompt = "Task: Translate the JSON VALUES to {$target_lang}. Keep keys EXACTLY unchanged and return STRICT JSON object only.\n"
                        . "- Do not change formatting placeholders like %s, %d, HTML entities, or markup.\n"
                        . "- Preserve punctuation and trailing colons where present.\n"
                        . "- Return JSON only.";

                    // We expect the outer filter to have set $GLOBALS['reeid_in_gettext'] to avoid recursion.
                    // Call translator - this might trigger other WP functions, so ensure recursion guard is active where needed.
                    $out = reeid_translate_html_with_openai($prompt . "\n" . $json_in, 'en', $target_lang, 'classic', 'neutral', ['response_format' => ['type' => 'json_object']]);

                    $translations = [];
                    if (is_string($out)) {
                        if (preg_match('/\{.*\}/s', $out, $m)) {
                            $clean = $m[0];
                        } else {
                            $clean = $out;
                        }
                        if (function_exists('reeid_json_sanitize_controls_v2')) {
                            $clean = reeid_json_sanitize_controls_v2($clean, []);
                        }
                        if (function_exists('reeid_safe_json_decode')) {
                            $decoded = reeid_safe_json_decode($clean);
                        } else {
                            $decoded = json_decode($clean, true);
                        }
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $translations = $decoded;
                        }
                    }

                    // Fallback to one-by-one translations if batch failed
                    if (empty($translations)) {
                        foreach ($input_map as $k => $v) {
                            $tr = reeid_translate_html_with_openai($v, 'en', $target_lang, 'classic', 'neutral');
                            $translations[$k] = (is_wp_error($tr) || empty($tr)) ? $v : (string) $tr;
                        }
                    }

                    // Persist (non-autoload)
                    update_option($opt_name, $translations, false);

                    return $translations;
                }
            }

            /**
             * Normalize a candidate original string to attempt better matching.
             *
             * Returns array of candidate keys (most specific first).
             *
             * @param string $text
             * @return array
             */
            if (! function_exists('reeid_woo_normalize_candidates')) {
                function reeid_woo_normalize_candidates($text): array
                {
                    $cands = [];
                    $orig = (string) $text;
                    $cands[] = $orig;

                    $trim = trim($orig);
                    if ($trim !== $orig) $cands[] = $trim;

                    $decoded = html_entity_decode($trim, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($decoded !== $trim) $cands[] = $decoded;

                    // Remove trailing colon (common in "SKU:" / "Category:")
                    if (substr($trim, -1) === ':') {
                        $cands[] = rtrim($trim, ':');
                    }

                    // Lowercase variant may help in some cases (keep original priority)
                    $lower = mb_strtolower($trim);
                    if ($lower !== $trim) $cands[] = $lower;

                    // Unique and preserve order
                    $out = [];
                    foreach ($cands as $v) {
                        if ($v === '') continue;
                        if (! in_array($v, $out, true)) $out[] = $v;
                    }
                    return $out;
                }
            }


            /**
             * Main gettext filter that replaces selected WooCommerce strings with translations.
             *
             * Recursion guard: sets $GLOBALS['reeid_in_gettext'] while building/fetching translations so any
             * sub-calls into gettext do not re-enter the same filter and cause infinite recursion.
             *
             * @param string $translated Current translated text (by WP)
             * @param string $text       Original text
             * @param string $domain     Text domain
             * @return string
             */
            if (! function_exists('reeid_woocommerce_gettext_filter')) {
                function reeid_woocommerce_gettext_filter($translated, $text, $domain)
                {
                    // Only run on frontend product listing/details to avoid unintended site-wide replacements.
                    if (! (is_product() || is_shop() || is_product_category() || is_product_tag())) {
                        return $translated;
                    }

                    // Filter domains: we care about 'woocommerce' and 'default', but allow empty domain too.
                    if ($domain !== 'woocommerce' && $domain !== 'default' && $domain !== '') {
                        return $translated;
                    }

                    // Quick bailout
                    if ($text === '') return $translated;

                    // Avoid recursion: if already inside our gettext pipeline, do nothing and return current value.
                    if (! empty($GLOBALS['reeid_in_gettext'])) {
                        return $translated;
                    }

                    // Set guard while we compute translations (so any translator calls that use gettext don't re-enter)
                    $GLOBALS['reeid_in_gettext'] = true;

                    $target_lang = reeid_detect_target_language();
                    if ($target_lang === '') {
                        unset($GLOBALS['reeid_in_gettext']);
                        return $translated;
                    }

                    $map = reeid_get_translated_woocommerce_strings($target_lang);

                    // Try several candidate keys for robust matching
                    $candidates = reeid_woo_normalize_candidates($text);
                    foreach ($candidates as $cand) {
                        if (isset($map[$cand]) && $map[$cand] !== '') {
                            $out = $map[$cand];
                            unset($GLOBALS['reeid_in_gettext']);
                            return $out;
                        }
                    }

                    // Final attempt: exact match with trimmed original text
                    $trim = trim($text);
                    if (isset($map[$trim]) && $map[$trim] !== '') {
                        $out = $map[$trim];
                        unset($GLOBALS['reeid_in_gettext']);
                        return $out;
                    }

                    // Nothing matched — return original translated value
                    unset($GLOBALS['reeid_in_gettext']);
                    return $translated;
                }

                add_filter('gettext', 'reeid_woocommerce_gettext_filter', 20, 3);
            }


            /**
             * Also handle gettext_with_context (some Woo strings use context).
             *
             * Signature: ( $translated, $text, $context, $domain )
             */
            if (! function_exists('reeid_woocommerce_gettext_with_context_filter')) {
                function reeid_woocommerce_gettext_with_context_filter($translated, $text, $context, $domain)
                {
                    // Reuse the same logic but prefer context-aware mapping first (context appended)
                    if (empty($text)) return $translated;
                    if (! empty($GLOBALS['reeid_in_gettext'])) return $translated;

                    // Only run on frontend product contexts
                    if (! (is_product() || is_shop() || is_product_category() || is_product_tag())) {
                        return $translated;
                    }

                    if ($domain !== 'woocommerce' && $domain !== 'default' && $domain !== '') {
                        return $translated;
                    }

                    $GLOBALS['reeid_in_gettext'] = true;
                    $target_lang = reeid_detect_target_language();
                    if ($target_lang === '') {
                        unset($GLOBALS['reeid_in_gettext']);
                        return $translated;
                    }

                    $map = reeid_get_translated_woocommerce_strings($target_lang);

                    // prefer context-aware key: "text|context"
                    $ctx_key = $text . '|' . $context;
                    if (isset($map[$ctx_key]) && $map[$ctx_key] !== '') {
                        $out = $map[$ctx_key];
                        unset($GLOBALS['reeid_in_gettext']);
                        return $out;
                    }

                    // fallback to normal filter
                    $out = reeid_woocommerce_gettext_filter($translated, $text, $domain);

                    unset($GLOBALS['reeid_in_gettext']);
                    return $out;
                }

                add_filter('gettext_with_context', 'reeid_woocommerce_gettext_with_context_filter', 20, 4);
            }


            /* Optional: If you notice "Add to cart" is not translated by gettext in your theme, also hook specific Woo filters.
 * These filters are used by some themes/plugins to change button text.
 */
            if (! function_exists('reeid_translate_add_to_cart_button')) {
                function reeid_translate_add_to_cart_button($text)
                {
                    if (! (is_product() || is_shop())) return $text;
                    if (! empty($GLOBALS['reeid_in_gettext'])) return $text;

                    $GLOBALS['reeid_in_gettext'] = true;
                    $target_lang = reeid_detect_target_language();
                    if ($target_lang) {
                        $map = reeid_get_translated_woocommerce_strings($target_lang);
                        $cands = reeid_woo_normalize_candidates($text);
                        foreach ($cands as $c) {
                            if (isset($map[$c]) && $map[$c] !== '') {
                                $out = $map[$c];
                                unset($GLOBALS['reeid_in_gettext']);
                                return $out;
                            }
                        }
                    }
                    unset($GLOBALS['reeid_in_gettext']);
                    return $text;
                }
                add_filter('woocommerce_product_add_to_cart_text', 'reeid_translate_add_to_cart_button', 20);
                add_filter('woocommerce_product_single_add_to_cart_text', 'reeid_translate_add_to_cart_button', 20);
            }
        }

        return $new_id;
    }
}


/*------------------------------------------------------------------------------
  Custom serializer that PRESERVES ALL ATTRIBUTES
  We DO NOT call serialize_blocks() (which drops unknown attrs).
  Instead we output <!-- wp:block {"attrs":...} --> content <!-- /wp:block -->
  and rebuild content from innerContent/innerBlocks while applying translations.
-------------------------------------------------------------------------------*/
if (! function_exists('reeid_gutenberg_encode_attrs_json')) {
    function reeid_gutenberg_encode_attrs_json($attrs): string
    {
        if (empty($attrs) || !is_array($attrs)) return '';
        // Keep unicode/slashes; avoid escaping hyphens inside CSS var(--x)
        return wp_json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (! function_exists('reeid_gutenberg_serialize_one_block_preserve_attrs')) {
    function reeid_gutenberg_serialize_one_block_preserve_attrs(array $block, array $translated_map, string $path): string
    {
        // Freeform HTML block (no name) → just return (with translated innerHTML if any)
        if (empty($block['blockName'])) {
            $val = $block['innerHTML'] ?? '';
            $key = $path . '.innerHTML';
            if (isset($translated_map[$key])) {
                $val = $translated_map[$key];
            }
            return (string) $val;
        }

        $name  = (string) $block['blockName'];
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
        $attrs_json = reeid_gutenberg_encode_attrs_json($attrs);
        $open = '<!-- wp:' . $name . ($attrs_json !== '' ? ' ' . $attrs_json : '') . ' -->';

        // Rebuild content
        $html = '';
        $content = $block['innerContent'] ?? null;
        $children = $block['innerBlocks'] ?? [];
        if (is_array($content) && !empty($content)) {
            $child_idx = 0;
            foreach ($content as $ci => $piece) {
                if ($piece === null) {
                    // slot for a child
                    $child_path = $path . '.innerBlocks.' . $child_idx; // not used in map; map uses own indexes
                    $html .= reeid_gutenberg_serialize_one_block_preserve_attrs($children[$child_idx], $translated_map, $path . '.' . $child_idx);
                    $child_idx++;
                } elseif (is_string($piece)) {
                    $k = $path . '.innerContent.' . $ci;
                    $html .= isset($translated_map[$k]) ? $translated_map[$k] : $piece;
                }
            }
        } else {
            // Fallback: use innerHTML (possibly translated)
            $k = $path . '.innerHTML';
            $val = $block['innerHTML'] ?? '';
            if (isset($translated_map[$k])) {
                $val = $translated_map[$k];
            }
            $html .= (string) $val;
            // And append children if present
            if (!empty($block['innerBlocks'])) {
                foreach ($block['innerBlocks'] as $ci => $child) {
                    $html .= reeid_gutenberg_serialize_one_block_preserve_attrs($child, $translated_map, $path . '.' . $ci);
                }
            }
        }

        $close = '<!-- /wp:' . $name . ' -->';

        return $open . $html . $close;
    }
}

if (! function_exists('reeid_gutenberg_serialize_preserve_attrs')) {
    function reeid_gutenberg_serialize_preserve_attrs(array $blocks, array $translated_map): string
    {
        $out = '';
        foreach ($blocks as $i => $block) {
            $out .= reeid_gutenberg_serialize_one_block_preserve_attrs($block, $translated_map, (string)$i);
        }
        return $out;
    }
}

/**
 * Main translation engine with slug generation
 * - SINGLE translation: metabox (or auto) for source; target is func arg.
 * - BULK translation: admin options/ctx drive source/target.
 * - Uses reeid_lang_normalize() (Italian => it-it, Burmese => my-mm).
 */
if (! function_exists('reeid_translate_via_openai_with_slug')) {
    function reeid_translate_via_openai_with_slug(string $content, string $target_lang, array $ctx = []): array
    {
        $log_file = WP_CONTENT_DIR . '/uploads/reeid-debug.log';
        $post_id  = intval($ctx['post_id'] ?? 0);
        $post     = $post_id ? get_post($post_id) : null;

        // Editor + mode
        $editor = $post ? reeid_detect_editor_type($post) : 'classic';
        $mode   = isset($ctx['mode']) ? strtolower((string)$ctx['mode']) : (isset($_REQUEST['reeid_bulk']) ? 'bulk' : 'single');
        if ($mode !== 'bulk') {
            $mode = 'single';
        }

        // Read metabox/admin options
        $meta_src = $post ? (string) get_post_meta($post_id, '_reeid_source_lang', true) : '';
        $meta_tgt = $post ? (string) get_post_meta($post_id, '_reeid_target_lang', true) : '';

        $opt_src  = (string) get_option('reeid_default_source_lang', 'auto'); // bulk only
        $opt_tgt  = (string) get_option('reeid_default_target_lang', '');     // bulk only

        // Detection helper
        $detect_lang = static function (string $html): string {
            $probe = trim(wp_strip_all_tags($html));
            $det   = function_exists('reeid_detect_language') ? (string) reeid_detect_language($probe) : '';
            if ($det === '' || strlen($det) > 16) {
                $det = 'en';
            }
            return reeid_lang_normalize($det);
        };

        // Resolve prefs by MODE (no admin override in SINGLE mode)
        if ($mode === 'bulk') {
            $source_pref = $opt_src !== '' ? strtolower($opt_src) : 'auto';
            $target_pref = isset($ctx['target_lang']) && $ctx['target_lang'] !== '' ? strtolower((string)$ctx['target_lang'])
                : ($opt_tgt !== '' ? strtolower($opt_tgt) : strtolower((string)$target_lang));
        } else {
            $source_pref = $meta_src !== '' ? strtolower($meta_src) : 'auto';
            $target_pref = $target_lang !== '' ? strtolower($target_lang)
                : ($meta_tgt !== '' ? strtolower($meta_tgt) : '');
        }

        // Normalize to your supported keys (this is where 'it' -> 'it-it', 'my' -> 'my-mm' happens)
        $source_pref = reeid_lang_normalize($source_pref);
        $target_pref = reeid_lang_normalize($target_pref);

        // Final effective languages
        $source_lang = ($source_pref === '' || $source_pref === 'auto') ? $detect_lang($content) : $source_pref;
        $target_lang = $target_pref !== '' ? $target_pref : 'en'; // last resort

        file_put_contents($log_file, "[" . gmdate('c') . "] S17 ENTRY mode={$mode} editor={$editor} post_id={$post_id} src={$source_lang} tgt={$target_lang}\n", FILE_APPEND);

        // No-op if src == tgt
        if ($source_lang === $target_lang) {
            $title = $post ? $post->post_title : 'translation';
            $slug  = reeid_sanitize_native_slug(mb_substr(wp_strip_all_tags($title), 0, 60));
            return ['ok' => true, 'content' => $content, 'slug' => $slug];
        }

        $translated = '';
        $slug       = '';

        try {
            if ($editor === 'gutenberg') {
                // === Collect
                $blocks = parse_blocks($content);
                $map    = reeid_gutenberg_walk_and_collect($blocks);
                file_put_contents($log_file, "[" . gmdate('c') . "] S17/GUTENBERG collected " . count($map) . " texts\n", FILE_APPEND);

                // === Partition
                $map_plain = [];
                $map_html  = [];
                foreach ($map as $k => $v) {
                    if (is_string($v) && strpos($v, '<') !== false && preg_match('/<[^>]+>/', $v)) {
                        $map_html[$k] = $v;
                    } else {
                        $map_plain[$k] = $v;
                    }
                }

                $translated_map = [];

                // === Translate plain-text (whitespace-preserving)
                if (!empty($map_plain)) {
                    $ws_plain       = [];
                    $map_plain_core = [];

                    foreach ($map_plain as $k => $raw) {
                        $raw = (string) $raw;
                        if (preg_match('/^([\x{00A0}\s]*)(.*?)([\x{00A0}\s]*)$/u', $raw, $m)) {
                            $left = $m[1];
                            $core = $m[2];
                            $right = $m[3];
                        } else {
                            $left = $right = '';
                            $core = $raw;
                        }
                        $ws_plain[$k]       = [$left, $right];
                        $map_plain_core[$k] = $core;
                    }

                    $plain_chunks = function_exists('reeid_chunk_translation_map')
                        ? reeid_chunk_translation_map($map_plain_core, 4000)
                        : [$map_plain_core];

                    foreach ($plain_chunks as $chunk) {
                        $has_content = false;
                        foreach ($chunk as $v) {
                            if ($v !== '') {
                                $has_content = true;
                                break;
                            }
                        }
                        if (!$has_content) {
                            foreach ($chunk as $ck => $core_in) {
                                [$l, $r] = $ws_plain[$ck] ?? ['', ''];
                                $translated_map[$ck] = $l . $core_in . $r;
                            }
                            continue;
                        }

                        $prompt  = "Task: Translate all JSON values FROM {$source_lang} TO {$target_lang}. Keep keys EXACTLY unchanged.\n"
                            . "Translate ONLY the values. Do not add/remove keys or change JSON.\n"
                            . "Do not add or remove leading/trailing whitespace in any value.\n"
                            . "Return STRICT JSON only (no comments/markdown).";

                        $json_in = wp_json_encode($chunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                        $json_out = reeid_translate_html_with_openai(
                            $prompt . "\n" . $json_in,
                            $source_lang,
                            $target_lang,
                            'gutenberg',
                            'neutral'
                        );

                        $decoded = null;
                        if (is_string($json_out) && preg_match('/\{.*\}/s', $json_out, $m)) {
                            $clean = function_exists('reeid_json_sanitize_controls_v2')
                                ? reeid_json_sanitize_controls_v2($m[0], $s = [])
                                : $m[0];
                            $decoded = function_exists('reeid_safe_json_decode')
                                ? reeid_safe_json_decode($clean)
                                : json_decode($clean, true);
                        }

                        if (is_array($decoded)) {
                            foreach ($chunk as $ck => $core_in) {
                                $core_out = array_key_exists($ck, $decoded) && is_string($decoded[$ck])
                                    ? (string) $decoded[$ck]
                                    : $core_in;
                                [$l, $r]  = $ws_plain[$ck] ?? ['', ''];
                                $translated_map[$ck] = $l . $core_out . $r;
                            }
                        } else {
                            foreach ($chunk as $ck => $core_in) {
                                [$l, $r] = $ws_plain[$ck] ?? ['', ''];
                                $translated_map[$ck] = $l . $core_in . $r;
                            }
                        }
                    }

                    file_put_contents($log_file, "[" . gmdate('c') . "] S17/GUTENBERG plain map translated (ws-preserved)\n", FILE_APPEND);
                }

                // === Translate HTML via text-node path
                if (!empty($map_html)) {
                    foreach ($map_html as $k => $frag) {
                        $tr = function_exists('reeid_html_translate_textnodes_fragment')
                            ? reeid_html_translate_textnodes_fragment($frag, $source_lang, $target_lang)
                            : $frag;
                        $translated_map[$k] = is_string($tr) && $tr !== '' ? $tr : $frag;
                    }
                    file_put_contents($log_file, "[" . gmdate('c') . "] S17/GUTENBERG HTML segments translated via textnodes-only\n", FILE_APPEND);
                }

                // === Rebuild (preserve ALL attrs)
                $translated = reeid_gutenberg_serialize_preserve_attrs(
                    reeid_gutenberg_walk_and_replace($blocks, $translated_map),
                    $translated_map
                );
            } elseif ($editor === 'elementor') {
            /* Elementor schema-safe pipeline v2 */
            $post_id = isset($_POST["post_id"]) ? (int) $_POST["post_id"] : 0; 
            $source  = isset($_POST["source"])  ? sanitize_text_field($_POST["source"])  : ""; 
            $target  = isset($_POST["target"])  ? sanitize_text_field($_POST["target"])  : ""; 
            $tone    = isset($_POST["tone"])    ? sanitize_text_field($_POST["tone"])    : ""; 
            $extra   = isset($_POST["prompt"])  ? wp_kses_post($_POST["prompt"])        : ""; 
            $res = reeid_elementor_walk_translate_and_commit_v2($post_id, $source, $target, $tone, $extra); 
            if(!$res["ok"]) { wp_send_json_error($res); } 
            wp_send_json_success($res);
            /* Elementor schema-safe pipeline */
            $post_id = isset($_POST["post_id"]) ? (int) $_POST["post_id"] : 0; 
            $source  = isset($_POST["source"])  ? sanitize_text_field($_POST["source"])  : ""; 
            $target  = isset($_POST["target"])  ? sanitize_text_field($_POST["target"])  : ""; 
            $tone    = isset($_POST["tone"])    ? sanitize_text_field($_POST["tone"])    : ""; 
            $extra   = isset($_POST["prompt"])  ? wp_kses_post($_POST["prompt"])        : ""; 
            $res = reeid_elementor_walk_translate_and_commit($post_id, $source, $target, $tone, $extra); 
            if(!$res["ok"]) { wp_send_json_error($res); } 
            wp_send_json_success($res);
                // Elementor: use SAME resolved languages (no admin override in single mode)
                $out = reeid_translate_html_with_openai($content, $source_lang, $target_lang, 'elementor', 'neutral');
                if (is_wp_error($out)) $out = $content;
                $translated = (string) $out;
            } else {
                // Classic
                $out = reeid_translate_html_with_openai($content, $source_lang, $target_lang, 'classic', 'neutral');
                if (is_wp_error($out)) $out = $content;
                $out = preg_replace('/<!DOCTYPE.*?<body[^>]*>/is', '', $out);
                $out = preg_replace('/<\/body>\s*<\/html>\s*$/is', '', $out);
                $translated = trim($out);
            }
        } catch (Throwable $e) {
            file_put_contents($log_file, "[" . gmdate('c') . "] S17 ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
            return ['ok' => false, 'error' => 'engine_failed', 'content' => $content, 'slug' => ''];
        }

        // === Slug generation
        $title_src = $post ? $post->post_title : 'translation';
        $slug = '';
        if (function_exists('reeid__s15_slug_from_api')) {
            $res = reeid__s15_slug_from_api($target_lang, $title_src, $title_src, 'native');
            if (!empty($res['ok']) && !empty($res['preferred'])) $slug = $res['preferred'];
        }
        if (empty($slug)) {
            $title_tr  = reeid_translate_html_with_openai($title_src, $source_lang, $target_lang, $editor, 'neutral');
            if (is_wp_error($title_tr) || empty($title_tr)) $title_tr = $title_src;
            if (function_exists('reeid_sanitize_native_slug')) {
                $slug = reeid_sanitize_native_slug(mb_substr(wp_strip_all_tags((string)$title_tr), 0, 60));
            } else {
                $slug = sanitize_title(mb_substr(wp_strip_all_tags((string)$title_tr), 0, 60));
            }
        }

        file_put_contents($log_file, "[" . gmdate('c') . "] S17 SLUG={$slug}, len=" . mb_strlen($translated) . "\n", FILE_APPEND);

        return ['ok' => true, 'content' => $translated, 'slug' => $slug];
    }
}


if (! function_exists('reeid_elementor_commit_post')) {
    /**
     * Save Elementor JSON consistently and regenerate CSS so frontend reflects new widgets immediately.
     *
     * @param int          $post_id
     * @param array|string $elementor_data Array (Elementor tree) or JSON string.
     */
    function reeid_elementor_commit_post(int $post_id, $elementor_data): void
    {
        // Normalize to JSON string
        if (is_array($elementor_data)) {
            $json = wp_json_encode($elementor_data, JSON_UNESCAPED_UNICODE);
        } else {
            $json = (string) $elementor_data;
        }

        if (! is_string($json) || $json === '') {
            return;
        }

        // Store JSON in meta with proper slashing
        update_post_meta($post_id, '_elementor_data', wp_slash($json));

        // Ensure Elementor builder meta is set
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');

        $ptype = get_post_type($post_id);
        $tmpl  = ($ptype === 'page') ? 'wp-page' : 'wp-post';
        update_post_meta($post_id, '_elementor_template_type', $tmpl);

        // Keep/clone page settings (layout, width, etc.)
        $ps = get_post_meta($post_id, '_elementor_page_settings', true);
        if (is_array($ps) || is_object($ps)) {
            update_post_meta($post_id, '_elementor_page_settings', $ps);
        }

        // Data version: prefer Elementor version, fallback to timestamp
        $ver = get_option('elementor_version');
        if (! $ver && defined('ELEMENTOR_VERSION')) {
            $ver = ELEMENTOR_VERSION;
        }
        update_post_meta(
            $post_id,
            '_elementor_data_version',
            $ver ? (string) $ver : (string) time()
        );

        // Clean legacy CSS meta
        delete_post_meta($post_id, '_elementor_css');

        // Regenerate CSS in a version-safe way
        if (did_action('elementor/loaded')) {
            try {
                if (class_exists('\Elementor\Core\Files\CSS\Post')) {
                    $css = new \Elementor\Core\Files\CSS\Post($post_id);

                    if (method_exists($css, 'delete')) {
                        $css->delete();
                    }

                    if (method_exists($css, 'update')) {
                        $css->update();
                    }
                }

                // Global cache clear – safe across Elementor versions
                if (isset(\Elementor\Plugin::$instance->files_manager)) {
                    \Elementor\Plugin::$instance->files_manager->clear_cache();
                }
            } catch (\Throwable $e) {
                // Silently ignore CSS regen failures
            }
        }
    }
}




/* ===========================================================
 * UNIVERSAL SAFETY NET (optional but recommended)
 * Add a save_post hook that ensures Elementor data has version
 * and CSS is regenerated whenever a translated post is updated.
 * Works for single and bulk paths without touching Gutenberg.
 * =========================================================== */
add_action('save_post', function ($post_id, $post) {
    // Quick exits
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }
    if (! $post instanceof WP_Post) {
        return;
    }

    // Only run for posts that have Elementor builder mode
    $edit_mode = get_post_meta($post_id, '_elementor_edit_mode', true);
    if ($edit_mode !== 'builder') {
        return;
    }

    // If this is one of our translations (or any builder post), ensure version + CSS
    $has_elem = get_post_meta($post_id, '_elementor_data', true);
    if (empty($has_elem)) {
        return;
    }

    // Ensure version meta is present
    $ver = get_post_meta($post_id, '_elementor_data_version', true);
    if (! $ver) {
        update_post_meta(
            $post_id,
            '_elementor_data_version',
            defined('ELEMENTOR_VERSION') ? (string) ELEMENTOR_VERSION : '3.x'
        );
    }

    // Rebuild CSS (safe try)
    try {
        if (class_exists('\Elementor\Core\Files\CSS\Post')) {
            $css = new \Elementor\Core\Files\CSS\Post($post_id);
            $css->clear_cache();
            $css->update();
        } elseif (class_exists('\Elementor\Post_CSS_File')) {
            $css = new \Elementor\Post_CSS_File($post_id);
            $css->clear_cache();
            $css->update();
        }
        if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }
    } catch (\Throwable $e) {
        // swallow
    }
}, 10, 2);



// WOO HELPER

function reeid_copy_wc_data_to_translation($src_id, $new_id)
{
    if (get_post_type($src_id) !== 'product' || get_post_type($new_id) !== 'product') {
        return;
    }

    // --- WooCommerce meta keys ---
    $wc_keys = [
        '_regular_price',
        '_sale_price',
        '_price',
        '_stock',
        '_stock_status',
        '_manage_stock',
        '_sku',
        '_downloadable',
        '_virtual',
        '_weight',
        '_length',
        '_width',
        '_height',
        '_tax_class',
        '_tax_status',
        '_product_attributes',
        '_product_version',
        'total_sales',
        '_sold_individually',
        '_backorders'
    ];
    foreach ($wc_keys as $key) {
        $val = get_post_meta($src_id, $key, true);
        if ($val !== '') {
            update_post_meta($new_id, $key, $val);
        }
    }

    // Copy product gallery and thumbnail
    $thumb = get_post_thumbnail_id($src_id);
    if ($thumb) set_post_thumbnail($new_id, $thumb);

    $gallery = get_post_meta($src_id, '_product_image_gallery', true);
    if ($gallery) update_post_meta($new_id, '_product_image_gallery', $gallery);

    // --- Copy taxonomies (categories, tags, product type, attrs) ---
    $taxes = ['product_cat', 'product_tag', 'product_type', 'product_visibility'];
    if (function_exists('wc_get_attribute_taxonomies')) {
        foreach ((array) wc_get_attribute_taxonomies() as $att) {
            $taxes[] = 'pa_' . $att->attribute_name;
        }
    }
    foreach ($taxes as $tax) {
        $terms = wp_get_object_terms($src_id, $tax, ['fields' => 'ids']);
        if (!is_wp_error($terms) && $terms) {
            wp_set_object_terms($new_id, $terms, $tax, false);
        }
    }
}