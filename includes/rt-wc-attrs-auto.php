<?php
if (!defined('ABSPATH')) exit;

/** Detect current lang (use the function we already ship) */
if (!function_exists('reeid_wc_current_lang')) {
    function reeid_wc_current_lang(){
        $norm = function($l){ $l=is_string($l)?strtolower(trim($l)):'';
            $l=str_replace('_','-',$l); return preg_replace('~[^a-z\-]+~','',$l) ?: 'en'; };
        if (!empty($_GET['reeid_force_lang'])) return $norm($_GET['reeid_force_lang']);
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($uri && preg_match('~^/([a-z]{2}(?:-[a-zA-Z]{2})?)(?:/|$)~',$uri,$m)) return $norm($m[1]);
        if (!empty($_COOKIE['reeid_lang'])) return $norm($_COOKIE['reeid_lang']);
        $site = function_exists('get_locale') ? get_locale() : 'en'; return $norm($site);
    }
}

/** Core translator adapter: try REEID engine entry points, else fallback */
if (!function_exists('reeid_wc_translate_string')) {
    function reeid_wc_translate_string($text, $lang, $context='attr'){
        $text = (string)$text; if ($text === '') return $text;

        // 1) Direct function(s) if available
        foreach ([
            'reeid_inline_translate',       // hypothetical
            'reeid_wc_inline_translate',    // hypothetical
            'reeid_translate_line',         // working-example style
        ] as $fn) {
            if (function_exists($fn)) {
                try { $out = call_user_func($fn, $text, $lang, $context);
                      if (is_string($out) && $out !== '') return $out; } catch(Throwable $e){}
            }
        }
        // 2) Filter hook if engine exposes one
        $maybe = apply_filters('reeid_translate_string', null, $text, $lang, $context);
        if (is_string($maybe) && $maybe !== '') return $maybe;

        // 3) Fallback — no translation available
        return $text;
    }
}

/** Cache helper (per product + lang) */
if (!function_exists('reeid_wc_attr_cache')) {
    function reeid_wc_attr_cache($product_id, $lang){
        $key = '_reeid_wc_attr_cache_' . strtolower($lang);
        $arr = get_post_meta($product_id, $key, true);
        return is_array($arr) ? $arr : [];
    }
}
if (!function_exists('reeid_wc_attr_cache_set')) {
    function reeid_wc_attr_cache_set($product_id, $lang, $hash, $value){
        $key = '_reeid_wc_attr_cache_' . strtolower($lang);
        $arr = get_post_meta($product_id, $key, true);
        if (!is_array($arr)) $arr = [];
        $arr[$hash] = (string)$value;
        update_post_meta($product_id, $key, $arr);
    }
}

/**
 * FORCE-LATE translation (works with all themes):
 * - Runs after Woo has assembled the attributes array, before HTML.
 * - Translates both labels and values using engine + cache.
 */
add_filter('woocommerce_display_product_attributes', function($attrs, $product){
    if (!function_exists('is_product') || !is_product()) return $attrs;
    if (!is_object($product) || !method_exists($product,'get_id')) return $attrs;

    $pid  = (int)$product->get_id();
    $lang = reeid_wc_current_lang();

    $cache = reeid_wc_attr_cache($pid, $lang);

    foreach ($attrs as &$A) {
        // Label
        if (isset($A['label'])) {
            $src = wp_strip_all_tags($A['label']);
            $h   = 'L:'.md5($src);
            if (isset($cache[$h])) {
                $A['label'] = esc_html($cache[$h]);
            } else {
                $tr = reeid_wc_translate_string($src, $lang, 'attr_label');
                $A['label'] = esc_html($tr);
                reeid_wc_attr_cache_set($pid, $lang, $h, $tr);
            }
        }
        // Value (plain text of the cell)
        if (isset($A['value']) && $A['value'] !== '') {
            $srcPlain = trim(wp_strip_all_tags($A['value']));
            if ($srcPlain !== '') {
                $h = 'V:'.md5($srcPlain);
                if (isset($cache[$h])) {
                    $A['value'] = esc_html($cache[$h]);
                } else {
                    $tr = reeid_wc_translate_string($srcPlain, $lang, 'attr_value');
                    $A['value'] = esc_html($tr);
                    reeid_wc_attr_cache_set($pid, $lang, $h, $tr);
                }
            }
        }
    }
    unset($A);
    return $attrs;
}, 999, 2);

/**
 * OPTIONAL: translate custom attribute objects earlier (non-taxonomy),
 * so any template that reads WC_Product->get_attributes() sees translated text.
 */
add_filter('woocommerce_product_get_attributes', function($attributes, $product){
    if (!function_exists('is_product') || !is_product()) return $attributes;
    if (!is_object($product) || !method_exists($product,'get_id')) return $attributes;

    $pid  = (int)$product->get_id();
    $lang = reeid_wc_current_lang();
    $cache = reeid_wc_attr_cache($pid, $lang);

    foreach ($attributes as $key => $attr) {
        if (!is_object($attr) || !method_exists($attr,'is_taxonomy') || $attr->is_taxonomy()) continue;

        // Name/Label
        if (method_exists($attr,'get_name') && method_exists($attr,'set_name')) {
            $src = (string)$attr->get_name();
            $h   = 'L:'.md5($src);
            $tr  = isset($cache[$h]) ? $cache[$h] : reeid_wc_translate_string($src, $lang, 'attr_label');
            if (!isset($cache[$h])) reeid_wc_attr_cache_set($pid, $lang, $h, $tr);
            if ($tr !== '' && $tr !== $src) $attr->set_name($tr);
        }

        // Value (options array → join → translate → set back)
        if (method_exists($attr,'get_options') && method_exists($attr,'set_options')) {
            $opts = (array)$attr->get_options();
            $src  = trim(implode(' | ', array_map('wp_strip_all_tags', $opts)));
            if ($src !== '') {
                $h  = 'V:'.md5($src);
                $tr = isset($cache[$h]) ? $cache[$h] : reeid_wc_translate_string($src, $lang, 'attr_value');
                if (!isset($cache[$h])) reeid_wc_attr_cache_set($pid, $lang, $h, $tr);
                if ($tr !== '' && $tr !== $src) $attr->set_options([$tr]);
            }
        }
        $attributes[$key] = $attr;
    }
    return $attributes;
}, 9, 2);
