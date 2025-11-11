<?php
// File: includes/wc-inline.php (working example: lean, read-only helpers)
if (!defined('ABSPATH')) exit;

if (!defined('REEID_WC_INLINE')) {
    define('REEID_WC_INLINE', true);
}

/** Sanitize a language code for meta keys. */
if (!function_exists('reeid_wc_sanitize_lang')) {
    function reeid_wc_sanitize_lang($lang) {
        $lang = is_string($lang) ? strtolower(trim($lang)) : '';
        $lang = str_replace('_','-',$lang);
        $lang = preg_replace('~[^a-z\-]+~','',$lang);
        return $lang ?: 'en';
    }
}

/** Effective languages (primary + site default + en). */
if (!function_exists('reeid_wc_effective_langs')) {
    function reeid_wc_effective_langs($primary = 'en') {
        $langs = [];
        $p = reeid_wc_sanitize_lang($primary);
        if ($p && !in_array($p, $langs, true)) $langs[] = $p;
        $site = get_locale();
        if (is_string($site) && $site) {
            $site = reeid_wc_sanitize_lang($site);
            if ($site && !in_array($site, $langs, true)) $langs[] = $site;
        }
        if (!in_array('en', $langs, true)) $langs[] = 'en';
        return $langs;
    }
}

/** Fetch translated payload for a product/language (read-only). */
if (!function_exists('reeid_wc_payload_for_lang')) {
    function reeid_wc_payload_for_lang($product_id, $lang = 'en') {
        $product_id = (int)$product_id;
        $lang = reeid_wc_sanitize_lang($lang);
        if ($product_id <= 0) return null;

        $key = '_reeid_payload_' . $lang;
        $pl  = get_post_meta($product_id, $key, true);
        if (is_array($pl) && !empty($pl)) return $pl;

        foreach (reeid_wc_effective_langs($lang) as $try) {
            $pl = get_post_meta($product_id, '_reeid_payload_' . $try, true);
            if (is_array($pl) && !empty($pl)) return $pl;
        }
        return null;
    }
}

/** Language switcher links (read-only). */
if (!function_exists('reeid_wc_switcher_links')) {
    function reeid_wc_switcher_links($product_id) {
        $product_id = (int)$product_id;
        if ($product_id <= 0) return [];
        $langs = get_post_meta($product_id, '_reeid_langs', true);
        if (!is_array($langs)) $langs = [];
        $links = [];
        foreach ($langs as $lang) {
            $lang = reeid_wc_sanitize_lang($lang);
            $url  = get_permalink($product_id);
            if (!$url) continue;
            $links[$lang] = add_query_arg('reeid_force_lang', rawurlencode($lang), $url);
        }
        return $links;
    }
}

/** Optionally reduce switcher links to effective langs. */
if (!function_exists('reeid_wc_filter_switcher_links')) {
    function reeid_wc_filter_switcher_links($links, $primary = 'en') {
        $effective = reeid_wc_effective_langs($primary);
        $filtered = [];
        foreach ((array)$links as $lang => $url) {
            $lang = reeid_wc_sanitize_lang($lang);
            if (in_array($lang, $effective, true)) $filtered[$lang] = $url;
        }
        return $filtered ?: $links;
    }
}
// add_filter('your_lang_switcher_filter', 'reeid_wc_filter_switcher_links', 10, 2);
