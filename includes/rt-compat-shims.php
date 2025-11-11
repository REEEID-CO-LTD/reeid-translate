<?php
if (!defined('ABSPATH')) exit;

/** ---- Legacy inline translator shims (delegate to reeid_translate_line) ---- */
if (!function_exists('reeid_inline_translate')) {
    function reeid_inline_translate($text, $lang = 'en', $ctx = 'inline'){
        return function_exists('reeid_translate_line')
            ? reeid_translate_line((string)$text, (string)$lang, (string)$ctx)
            : (string)$text;
    }
}
if (!function_exists('reeid_wc_inline_translate')) {
    function reeid_wc_inline_translate($text, $lang = 'en', $ctx = 'wc_inline'){
        return function_exists('reeid_translate_line')
            ? reeid_translate_line((string)$text, (string)$lang, (string)$ctx)
            : (string)$text;
    }
}

/** ---- Static labels map loader (from /mappings) ---- */
if (!function_exists('reeid_wc__labels_map')) {
    function reeid_wc__labels_map($lang){
        static $CACHE = [];
        $lang = strtolower($lang ?: 'en');
        if (isset($CACHE[$lang])) return $CACHE[$lang];
        $file = dirname(__DIR__) . '/mappings/woocommerce-' . $lang . '.json';
        if (!is_readable($file)) { $CACHE[$lang] = []; return $CACHE[$lang]; }
        $json = file_get_contents($file);
        $map  = json_decode($json, true);
        $CACHE[$lang] = is_array($map) ? $map : [];
        return $CACHE[$lang];
    }
}

/** ---- Translate the Woo "Additional information" tab title via map ONLY ---- */
if (!function_exists('reeid_wc__current_lang_safe')) {
    function reeid_wc__current_lang_safe(){
        if (isset($_GET['reeid_force_lang'])) {
            $l = strtolower(preg_replace('/[^a-z\-]/i','', (string) $_GET['reeid_force_lang']));
            if ($l !== '') return $l;
        }
        if (function_exists('reeid_wc_current_lang')) {
            $l = (string) reeid_wc_current_lang();
            if ($l !== '') return strtolower($l);
        }
        if (!empty($_COOKIE['reeid_lang'])) {
            $l = strtolower(preg_replace('/[^a-z\-]/i','', (string) $_COOKIE['reeid_lang']));
            if ($l !== '') return $l;
        }
        return 'en';
    }
}
if (!function_exists('reeid_wc__translate_tab_label_via_map')) {
    function reeid_wc__translate_tab_label_via_map($tabs){
        if (!is_array($tabs) || !isset($tabs['additional_information']['title'])) return $tabs;
        $title = (string)$tabs['additional_information']['title']; if ($title==='') return $tabs;
        $lang = reeid_wc__current_lang_safe();
        $map  = reeid_wc__labels_map($lang);
        if ($map && isset($map[$title]) && is_string($map[$title]) && $map[$title] !== '') {
            $tabs['additional_information']['title'] = $map[$title];
        }
        return $tabs;
    }
    add_filter('woocommerce_product_tabs','reeid_wc__translate_tab_label_via_map', 99);
}
