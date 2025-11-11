<?php
// REEID: Woo i18n (safe) — short description + attributes + path-aware switcher
if (!defined('ABSPATH')) exit;

/**
 * Current language resolver:
 * - Query (?reeid_force_lang=)
 * - Leading URL segment (/zh/ or /en-US/)
 * - Cookie (reeid_lang)
 * - Site default
 */
if (!function_exists('reeid_wc_current_lang')) {
    function reeid_wc_current_lang(){
        $norm = function($l){
            $l = is_string($l) ? strtolower(trim($l)) : '';
            $l = str_replace('_','-',$l);
            return preg_replace('~[^a-z\-]+~','',$l) ?: 'en';
        };

        // 1) Query param
        if (!empty($_GET['reeid_force_lang'])) {
            return $norm($_GET['reeid_force_lang']);
        }

        // 2) Leading URL segment like /zh/ or /en-US/
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if ($uri && preg_match('~^/([a-z]{2}(?:-[a-zA-Z]{2})?)(?:/|$)~', $uri, $m)) {
            $seg = $norm($m[1]);
            // Persist for 7 days so subsequent views keep same language
            if (!headers_sent()) {
                $host = $_SERVER['HTTP_HOST'] ?? '';
                setcookie('reeid_lang', $seg, time()+7*DAY_IN_SECONDS, COOKIEPATH ?: '/', $host, is_ssl(), true);
            }
            return $seg;
        }

        // 3) Cookie
        if (!empty($_COOKIE['reeid_lang'])) {
            return $norm($_COOKIE['reeid_lang']);
        }

        // 4) Site default
        $site = function_exists('get_locale') ? get_locale() : 'en';
        return $norm($site ?: 'en');
    }
}

/** Fetch translated payload (supports both storage styles). */
if (!function_exists('reeid_wc_get_payload')){
    function reeid_wc_get_payload($product_id, $lang){
        $pid  = (int)$product_id;
        $lang = is_string($lang) ? strtolower(trim($lang)) : 'en';
        if ($pid <= 0 || $lang === '') return null;

        // New storage (shim): _reeid_wc_tr_<lang>
        $p = get_post_meta($pid, '_reeid_wc_tr_'.$lang, true);
        if (is_array($p) && !empty($p)) return $p;

        // Older storage (former): _reeid_payload_<lang>
        $p = get_post_meta($pid, '_reeid_payload_'.$lang, true);
        if (is_array($p) && !empty($p)) return $p;

        return null;
    }
}

/** SHORT DESCRIPTION — translate via payload['excerpt'] if present. */
add_filter('woocommerce_short_description', function($desc){
    if (!function_exists('is_product') || !is_product()) return $desc;
    global $post; if (!$post) return $desc;
    $pl = reeid_wc_get_payload($post->ID, reeid_wc_current_lang());
    return (!empty($pl['excerpt'])) ? (string)$pl['excerpt'] : $desc;
}, 5);

/**
 * ATTRIBUTES — translate labels & values.
 * Per-product meta (preferred):
 *   _reeid_wc_attr_labels_<lang> = [ "Color" => "颜色", ... ]
 *   _reeid_wc_attr_values_<lang> = [ "Yellow blended with blue" => "黄蓝混合", ... ]
 * Global options fallback:
 *   reeid_attr_labels_<lang>,  reeid_attr_values_<lang>
 */
add_filter('woocommerce_attribute_label', function($label, $name = '', $product = null){
    if (!function_exists('is_product') || !is_product()) return $label;

    $lang = reeid_wc_current_lang();
    $pid  = (is_object($product) && method_exists($product,'get_id')) ? (int)$product->get_id() : (get_the_ID() ?: 0);

    $map = ($pid > 0) ? get_post_meta($pid, '_reeid_wc_attr_labels_'.$lang, true) : array();
    if (!is_array($map) || empty($map)) $map = get_option('reeid_attr_labels_'.$lang, array());
    if (!is_array($map)) $map = array();

    if (isset($map[$label]) && $map[$label] !== '') return (string)$map[$label];
    if ($name && isset($map[$name]) && $map[$name] !== '') return (string)$map[$name];

    return $label;
}, 10, 3);

add_filter('woocommerce_display_product_attributes', function($attrs, $product){
    if (!function_exists('is_product') || !is_product()) return $attrs;

    $lang = reeid_wc_current_lang();
    $pid  = (is_object($product) && method_exists($product,'get_id')) ? (int)$product->get_id() : (get_the_ID() ?: 0);

    $labelMap = ($pid > 0) ? get_post_meta($pid, '_reeid_wc_attr_labels_'.$lang, true) : array();
    if (!is_array($labelMap) || empty($labelMap)) $labelMap = get_option('reeid_attr_labels_'.$lang, array());
    if (!is_array($labelMap)) $labelMap = array();

    $valueMap = ($pid > 0) ? get_post_meta($pid, '_reeid_wc_attr_values_'.$lang, true) : array();
    if (!is_array($valueMap) || empty($valueMap)) $valueMap = get_option('reeid_attr_values_'.$lang, array());
    if (!is_array($valueMap)) $valueMap = array();

    foreach ($attrs as &$A) {
        if (isset($A['label'])) {
            $lab = wp_strip_all_tags($A['label']);
            if (isset($labelMap[$lab]) && $labelMap[$lab] !== '') {
                $A['label'] = esc_html($labelMap[$lab]);
            } elseif (isset($labelMap[$A['label']])) {
                $A['label'] = esc_html($labelMap[$A['label']]);
            }
        }
        if (isset($A['value']) && $A['value'] !== '') {
            $plain = trim(wp_strip_all_tags($A['value']));
            if ($plain !== '' && isset($valueMap[$plain]) && $valueMap[$plain] !== '') {
                $A['value'] = esc_html($valueMap[$plain]);
            }
        }
    }
    unset($A);
    return $attrs;
}, 10, 2);

/** Available languages for a product (from indexes; ensure 'en' fallback). */
if (!function_exists('reeid_wc_available_langs')) {
    function reeid_wc_available_langs($product_id){
        $pid = (int)$product_id;
        $langs = get_post_meta($pid, '_reeid_wc_inline_langs', true);
        if (!is_array($langs) || empty($langs)) $langs = get_post_meta($pid, '_reeid_langs', true);
        if (!is_array($langs)) $langs = array('en');
        $langs = array_values(array_unique(array_map(function($l){
            $l = strtolower(trim(str_replace('_','-',$l)));
            return preg_replace('~[^a-z\-]+~','',$l);
        }, $langs)));
        if (!in_array('en', $langs, true)) $langs[] = 'en';
        return $langs;
    }
}

/** Build switcher URLs; prefer path prefix when current request has one. */
if (!function_exists('reeid_wc_switcher_url')) {
    function reeid_wc_switcher_url($product_id, $lang){
        $lang = strtolower(trim(str_replace('_','-',$lang)));
        $lang = preg_replace('~[^a-z\-]+~','',$lang) ?: 'en';

        $url = get_permalink($product_id);
        if (!$url) return '#';

        $req = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if ($req && preg_match('~^/([a-z]{2}(?:-[a-zA-Z]{2})?)(/|$)~', $req)) {
            $urlp = wp_parse_url($url);
            $path = isset($urlp['path']) ? $urlp['path'] : '/';
            if (preg_match('~^/([a-z]{2}(?:-[a-zA-Z]{2})?)(/|$)~', $path)) {
                $path = preg_replace('~^/([a-z]{2}(?:-[a-zA-Z]{2})?)~', '/'.$lang, $path);
            } else {
                $path = '/'.$lang . rtrim($path,'/');
            }
            return (isset($urlp['scheme'])?$urlp['scheme'].'://':'') . ($urlp['host']??'') . ($urlp['port']?':'.$urlp['port']:'') . $path . (isset($urlp['query'])?'?'.$urlp['query']:'');
        }
        return add_query_arg('reeid_force_lang', rawurlencode($lang), $url);
    }
}

/** Minimal switcher renderer (respects path prefixes). */
if (!function_exists('reeid_wc_render_switcher')) {
    function reeid_wc_render_switcher(){
        if (!function_exists('is_product') || !is_product()) return;
        global $post; if (!$post) return;

        $current = reeid_wc_current_lang();
        $langs   = reeid_wc_available_langs($post->ID);
        if (empty($langs)) return;

        echo '<nav class="reeid-wc-switcher" aria-label="Product languages"><ul style="list-style:none;display:flex;gap:.5rem;padding:0;margin:.5rem 0;">';
        foreach ($langs as $l) {
            $href = esc_url(reeid_wc_switcher_url($post->ID, $l));
            $cls  = ($l === $current) ? ' style="font-weight:600;text-decoration:underline;"' : '';
            echo '<li><a href="'.$href.'"'.$cls.'>'.esc_html(strtoupper($l)).'</a></li>';
        }
        echo '</ul></nav>';
    }
    add_action('woocommerce_single_product_summary', 'reeid_wc_render_switcher', 3);
}
add_shortcode('reeid_product_lang_switcher', function(){ ob_start(); reeid_wc_render_switcher(); return ob_get_clean(); });

/** Ensure Gutenberg renders translated Description by setting raw post content early. */
add_action('wp', function(){
    if (!function_exists('is_product') || !is_product()) return;
    global $post; if (!$post || (int)$post->ID <= 0) return;
    $pl = reeid_wc_get_payload($post->ID, reeid_wc_current_lang());
    if (!is_array($pl) || empty($pl['content'])) return;
    $post->post_content = (string) $pl['content'];
}, 1);

/** Title + excerpt translation (early; WYSIWYG-safe). */
add_filter('the_title', function($title, $id){
    if (!function_exists('is_product') || !is_product()) return $title;
    if ((int)$id <= 0) return $title;
    $pl = reeid_wc_get_payload($id, reeid_wc_current_lang());
    return (!empty($pl['title'])) ? (string)$pl['title'] : $title;
}, 5, 2);

add_filter('get_the_excerpt', function($excerpt, $post){
    if (!function_exists('is_product') || !is_product()) return $excerpt;
    if (!$post) return $excerpt;
    $pl = reeid_wc_get_payload($post->ID, reeid_wc_current_lang());
    return (!empty($pl['excerpt'])) ? (string)$pl['excerpt'] : $excerpt;
}, 5, 2);

/** REEID: Translate attributes at source so all templates inherit translated text. */
add_filter('woocommerce_product_get_attributes', function($attributes, $product){
    if (!function_exists('is_product') || !is_product()) return $attributes;

    $lang = reeid_wc_current_lang();
    $pid  = (is_object($product) && method_exists($product,'get_id')) ? (int)$product->get_id() : 0;

    // Load maps (per-product first, then global)
    $labelMap = ($pid>0) ? get_post_meta($pid, '_reeid_wc_attr_labels_'.$lang, true) : array();
    if (!is_array($labelMap) || empty($labelMap)) $labelMap = get_option('reeid_attr_labels_'.$lang, array());
    if (!is_array($labelMap)) $labelMap = array();

    $valueMap = ($pid>0) ? get_post_meta($pid, '_reeid_wc_attr_values_'.$lang, true) : array();
    if (!is_array($valueMap) || empty($valueMap)) $valueMap = get_option('reeid_attr_values_'.$lang, array());
    if (!is_array($valueMap)) $valueMap = array();

    foreach ($attributes as $key => $attr) {
        // Only handle non-taxonomy (custom) attributes here; taxonomy terms are translated elsewhere
        if (!empty($attr) && is_object($attr) && method_exists($attr,'get_data')) {
            $data = $attr->get_data();

            // Label
            if (!empty($data['name'])) {
                $label = $data['name'];
                if (isset($labelMap[$label]) && $labelMap[$label] !== '') {
                    $data['name'] = (string)$labelMap[$label];
                }
            }

            // Value (comma-separated string for custom attributes)
            if (!empty($data['value']) && is_string($data['value'])) {
                $raw = trim($data['value']);
                if ($raw !== '' && isset($valueMap[$raw]) && $valueMap[$raw] !== '') {
                    $data['value'] = (string)$valueMap[$raw];
                }
            }

            // Push back if changed
            if ($data !== $attr->get_data()) {
                foreach ($data as $k => $v) {
                    if (method_exists($attr, 'set_' . $k)) {
                        call_user_func(array($attr, 'set_' . $k), $v);
                    }
                }
                $attributes[$key] = $attr;
            }
        }
    }
    return $attributes;
}, 10, 2);

/**
 * REEID: Translate attributes at source using correct setters for custom attributes.
 * - Custom (non-taxonomy): translate label via set_name(), value via set_options([translated]).
 * - Taxonomy attributes: leave to the theme/tax term translations (we don't touch terms here).
 */
add_filter('woocommerce_product_get_attributes', function($attributes, $product){
    if (!function_exists('is_product') || !is_product()) return $attributes;

    $lang = reeid_wc_current_lang();
    $pid  = (is_object($product) && method_exists($product,'get_id')) ? (int)$product->get_id() : 0;

    // Load maps
    $labelMap = ($pid>0) ? get_post_meta($pid, '_reeid_wc_attr_labels_'.$lang, true) : array();
    if (!is_array($labelMap) || empty($labelMap)) $labelMap = get_option('reeid_attr_labels_'.$lang, array());
    if (!is_array($labelMap)) $labelMap = array();

    $valueMap = ($pid>0) ? get_post_meta($pid, '_reeid_wc_attr_values_'.$lang, true) : array();
    if (!is_array($valueMap) || empty($valueMap)) $valueMap = get_option('reeid_attr_values_'.$lang, array());
    if (!is_array($valueMap)) $valueMap = array();

    foreach ($attributes as $key => $attr) {
        if (!is_object($attr)) continue;

        // Only handle custom (non-taxonomy) attributes here
        if (method_exists($attr, 'is_taxonomy') && !$attr->is_taxonomy()) {
            // LABEL
            if (method_exists($attr, 'get_name') && method_exists($attr, 'set_name')) {
                $orig = (string) $attr->get_name();
                if ($orig !== '' && isset($labelMap[$orig]) && $labelMap[$orig] !== '') {
                    $attr->set_name((string)$labelMap[$orig]);
                }
            }

            // VALUE (stored as options array for custom attributes)
            if (method_exists($attr, 'get_options') && method_exists($attr, 'set_options')) {
                $opts = (array) $attr->get_options();
                // Implode to one line to match our map, then translate
                $raw  = trim(implode(' | ', array_map('wp_strip_all_tags', $opts)));
                if ($raw !== '' && isset($valueMap[$raw]) && $valueMap[$raw] !== '') {
                    $attr->set_options(array((string)$valueMap[$raw]));
                }
            }
            $attributes[$key] = $attr;
        }
    }
    return $attributes;
}, 9, 2);

/**
 * REEID: Force-last attribute translation (runs after other filters).
 * Ensures labels/values are in the target language before HTML is generated.
 */
add_filter('woocommerce_display_product_attributes', function($attrs, $product){
    if (!function_exists('is_product') || !is_product()) return $attrs;

    $lang = reeid_wc_current_lang();
    $pid  = (is_object($product) && method_exists($product,'get_id')) ? (int)$product->get_id() : (get_the_ID() ?: 0);

    // Load per-product maps, fall back to global options
    $labelMap = ($pid>0) ? get_post_meta($pid, '_reeid_wc_attr_labels_'.$lang, true) : array();
    if (!is_array($labelMap) || empty($labelMap)) $labelMap = get_option('reeid_attr_labels_'.$lang, array());
    if (!is_array($labelMap)) $labelMap = array();

    $valueMap = ($pid>0) ? get_post_meta($pid, '_reeid_wc_attr_values_'.$lang, true) : array();
    if (!is_array($valueMap) || empty($valueMap)) $valueMap = get_option('reeid_attr_values_'.$lang, array());
    if (!is_array($valueMap)) $valueMap = array();

    foreach ($attrs as &$A) {
        // Label fix (exact match only)
        if (isset($A['label'])) {
            $lab = wp_strip_all_tags($A['label']);
            if ($lab !== '' && isset($labelMap[$lab]) && $labelMap[$lab] !== '') {
                $A['label'] = esc_html($labelMap[$lab]);
            }
        }
        // Value fix (exact match on plain text)
        if (isset($A['value']) && $A['value'] !== '') {
            $plain = trim(wp_strip_all_tags($A['value']));
            if ($plain !== '' && isset($valueMap[$plain]) && $valueMap[$plain] !== '') {
                $A['value'] = esc_html($valueMap[$plain]);
            }
        }
    }
    unset($A);
    return $attrs;
}, 999, 2);
/** =========================================================
 * REEID: force-last Woo Attributes translator (prio 999)
 * - Single source of truth; runs only on product pages.
 * - Calls your inline translator(s) with graceful fallback.
 * - Guarded to avoid redeclare / double-hook.
 * ========================================================= */
if (!defined('REEID_WC_ATTR_FORCE_LAST')) { define('REEID_WC_ATTR_FORCE_LAST', 1); }

if (!function_exists('reeid_wc__translate_scalar')) {
    function reeid_wc__translate_scalar($text, $lang, $ctx){
        $text = (string)$text;
        if ($text === '') return $text;

        // Try known REEID translators (fast-fail)
        foreach (['reeid_inline_translate','reeid_wc_inline_translate','reeid_translate_line'] as $fn) {
            if (function_exists($fn)) {
                try {
                    $o = call_user_func($fn, $text, $lang, $ctx);
                    if (is_string($o) && $o !== '') return $o;
                } catch (Throwable $e) { /* swallow */ }
            }
        }

        // Filter fallback (other bridges may hook this)
        $maybe = apply_filters('reeid_translate_string', null, $text, $lang, $ctx);
        if (is_string($maybe) && $maybe !== '') return $maybe;

        // Final fallback: original string
        return $text;
    }
}

if (!function_exists('reeid_wc__attr_force_last_hook')) {
    function reeid_wc__attr_force_last_hook($attrs, $product){
        if (!function_exists('is_product') || !is_product()) return $attrs;

        $lang = function_exists('reeid_wc_current_lang') ? (string) reeid_wc_current_lang() : 'en';
        foreach ($attrs as &$A) {
            // Label
            if (isset($A['label'])) {
                $src = wp_strip_all_tags($A['label']);
                $A['label'] = esc_html(reeid_wc__translate_scalar($src, $lang, 'attr_label'));
            }
            // Value (flatten HTML to plain, then re-escape)
            if (!empty($A['value'])) {
                $plain = trim(wp_strip_all_tags(is_array($A['value']) ? implode(', ', $A['value']) : $A['value']));
                if ($plain !== '') {
                    $A['value'] = esc_html(reeid_wc__translate_scalar($plain, $lang, 'attr_value'));
                }
            }
        }
        unset($A);
        return $attrs;
    }
}

// Attach once only, at very end of the chain
if (!has_filter('woocommerce_display_product_attributes', 'reeid_wc__attr_force_last_hook')) {
    add_filter('woocommerce_display_product_attributes', 'reeid_wc__attr_force_last_hook', 999, 2);
}
/** =========================================================
 * REEID: deepest attribute hooks (labels + values)
 * Runs at prio 999 on Woo filters used inside wc_display_product_attributes().
 * Works even with most theme overrides.
 * ========================================================= */
if (!defined('REEID_WC_ATTR_DEEPEST')) { define('REEID_WC_ATTR_DEEPEST', 1); }

if (!function_exists('reeid_wc__current_lang_safe')) {
    function reeid_wc__current_lang_safe(){
        if (function_exists('reeid_wc_current_lang')) {
            $l = (string) reeid_wc_current_lang();
            return $l !== '' ? $l : 'en';
        }
        return 'en';
    }
}

/* Label translator: wc_attribute_label() -> 'woocommerce_attribute_label' */
if (!function_exists('reeid_wc__attr_label_filter')) {
    function reeid_wc__attr_label_filter($label, $name = '', $product = null){
        if (function_exists('is_product') && !is_product()) return $label;
        $lang = reeid_wc__current_lang_safe();
        $src  = wp_strip_all_tags((string)$label);
        if ($src === '') return $label;
        $out  = $src;
        if (function_exists('reeid_wc__translate_scalar')) {
            $t = reeid_wc__translate_scalar($src, $lang, 'attr_label');
            if (is_string($t) && $t !== '') $out = $t;
        }
        return esc_html($out);
    }
    add_filter('woocommerce_attribute_label', 'reeid_wc__attr_label_filter', 999, 3);
}

/* Value translator: inside wc_display_product_attributes() -> 'woocommerce_attribute' */
if (!function_exists('reeid_wc__attr_value_filter')) {
    // Signature varies by WC; accept variable args safely.
    function reeid_wc__attr_value_filter($text /*, ... */){
        if (function_exists('is_product') && !is_product()) return $text;
        $lang  = reeid_wc__current_lang_safe();
        $plain = trim(wp_strip_all_tags(is_array($text) ? implode(', ', $text) : (string)$text));
        if ($plain === '') return $text;

        $out = $plain;
        if (function_exists('reeid_wc__translate_scalar')) {
            $t = reeid_wc__translate_scalar($plain, $lang, 'attr_value');
            if (is_string($t) && $t !== '') $out = $t;
        }
        return esc_html($out);
    }
    // Most installs use 3 args; we hook with a high arity to be safe.
    add_filter('woocommerce_attribute', 'reeid_wc__attr_value_filter', 999, 3);
}
/** REEID: ensure our Additional Information template overrides theme one */
if (!defined('REEID_WC_TPL_BRIDGE')) { define('REEID_WC_TPL_BRIDGE', 1); }
if (!function_exists('reeid_wc_locate_template')) {
    function reeid_wc_locate_template($template, $template_name, $template_path) {
        $plugin_path = dirname(__DIR__) . '/templates/';
        $candidate   = $plugin_path . $template_name;
        if (strpos($template_name, 'single-product/tabs/additional-information.php') !== false && file_exists($candidate)) {
            return $candidate;
        }
        return $template;
    }
    add_filter('woocommerce_locate_template', 'reeid_wc_locate_template', 10, 3);
}
/** =========================================================
 * REEID: Final HTML pass — translate "Additional information" table in-place.
 * - Targets only the Woo attributes table (class shop_attributes / woocommerce-product-attributes).
 * - Works regardless of theme overrides / earlier filters.
 * - Prio 9999 to run dead last on the_content.
 * ========================================================= */
if (!defined('REEID_WC_ATTR_HTML_PASS')) { define('REEID_WC_ATTR_HTML_PASS', 1); }

if (!function_exists('reeid_wc__current_lang_safe')) {
    function reeid_wc__current_lang_safe(){
        if (function_exists('reeid_wc_current_lang')) {
            $l = (string) reeid_wc_current_lang();
            return $l !== '' ? $l : 'en';
        }
        return 'en';
    }
}
if (!function_exists('reeid_wc__translate_scalar')) {
    function reeid_wc__translate_scalar($text, $lang, $ctx){
        $text = (string)$text; if ($text==='') return $text;
        foreach (['reeid_inline_translate','reeid_wc_inline_translate','reeid_translate_line'] as $fn) {
            if (function_exists($fn)) {
                try { $o = call_user_func($fn, $text, $lang, $ctx);
                      if (is_string($o) && $o!=='') return $o; } catch (Throwable $e) {}
            }
        }
        $maybe = apply_filters('reeid_translate_string', null, $text, $lang, $ctx);
        return (is_string($maybe) && $maybe!=='') ? $maybe : $text;
    }
}

if (!function_exists('reeid_wc__attrs_html_pass')) {
    function reeid_wc__attrs_html_pass($content){
        if (!function_exists('is_product') || !is_product()) return $content;
        if (strpos($content, 'shop_attributes') === false
         && strpos($content, 'woocommerce-product-attributes') === false) {
            return $content;
        }
        $lang = reeid_wc__current_lang_safe();

        // Prefer DOMDocument; fallback to a conservative regex if DOM unavailable.
        $dom_ok = class_exists('DOMDocument');
        if ($dom_ok) {
            try {
                $libxml_prev = libxml_use_internal_errors(true);
                $doc = new DOMDocument();
                // Ensure proper encoding handling
                $doc->loadHTML('<?xml encoding="utf-8" ?>' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

                $xpath = new DOMXPath($doc);
                // Select any Woo attributes table
                $tables = $xpath->query('//table[contains(@class,"shop_attributes") or contains(@class,"woocommerce-product-attributes")]');
                if ($tables && $tables->length) {
                    foreach ($tables as $table) {
                        // Translate TH (labels)
                        foreach ($xpath->query('.//th', $table) as $th) {
                            $raw = trim($th->textContent);
                            if ($raw !== '') {
                                $th->nodeValue = ''; // clear
                                $th->appendChild($doc->createTextNode(
                                    reeid_wc__translate_scalar($raw, $lang, 'attr_label')
                                ));
                            }
                        }
                        // Translate TD (values) — flatten to plain text
                        foreach ($xpath->query('.//td', $table) as $td) {
                            $raw = trim($td->textContent);
                            if ($raw !== '') {
                                $td->nodeValue = '';
                                $td->appendChild($doc->createTextNode(
                                    reeid_wc__translate_scalar($raw, $lang, 'attr_value')
                                ));
                            }
                        }
                    }
                }
                $html = $doc->saveHTML();
                libxml_clear_errors();
                libxml_use_internal_errors($libxml_prev);
                if (is_string($html) && $html !== '') return $html;
            } catch (Throwable $e) {
                // fall through to regex fallback
            }
        }

        // Fallback: minimally replace text inside the attributes table
        $content = preg_replace_callback(
            '#(<table[^>]+class="[^"]*(?:shop_attributes|woocommerce-product-attributes)[^"]*"[^>]*>)(.*?)(</table>)#is',
            function($m) use ($lang){
                $open = $m[1]; $inner = $m[2]; $close = $m[3];
                // translate <th>...</th>
                $inner = preg_replace_callback('#<th[^>]*>(.*?)</th>#is', function($mm) use ($lang){
                    $raw = trim(wp_strip_all_tags($mm[1]));
                    $tr  = esc_html(reeid_wc__translate_scalar($raw, $lang, 'attr_label'));
                    return '<th class="woocommerce-product-attributes-item__label">'.$tr.'</th>';
                }, $inner);
                // translate <td>...</td>
                $inner = preg_replace_callback('#<td[^>]*>(.*?)</td>#is', function($mm) use ($lang){
                    $raw = trim(wp_strip_all_tags($mm[1]));
                    $tr  = esc_html(reeid_wc__translate_scalar($raw, $lang, 'attr_value'));
                    return '<td class="woocommerce-product-attributes-item__value">'.$tr.'</td>';
                }, $inner);
                return $open.$inner.$close;
            },
            $content
        );

        return $content;
    }
    add_filter('the_content', 'reeid_wc__attrs_html_pass', 9999);
}
/** REEID: attrs debug (temporary) */
if (!defined('REEID_WC_ATTR_DEBUG')) { define('REEID_WC_ATTR_DEBUG', 1); }
if (!function_exists('reeid_wc__dlog')) {
    function reeid_wc__dlog($tag, $data){
        if (defined('REST_REQUEST') && REST_REQUEST) return;
        if (is_admin() && !wp_doing_ajax()) return;
        @ini_set('log_errors','1'); @ini_set('display_errors','0');
        $msg = '[ATTRDBG] '.$tag.' '. wp_json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (function_exists('error_log')) error_log($msg);
    }
}

/* wrap the scalar translator */
if (!function_exists('reeid_wc__translate_scalar_dbgwrap')) {
    function reeid_wc__translate_scalar_dbgwrap($text, $lang, $ctx){
        $in = (string)$text;
        $out = function_exists('reeid_wc__translate_scalar')
            ? reeid_wc__translate_scalar($in, $lang, $ctx)
            : $in;
        reeid_wc__dlog('scalar', ['lang'=>$lang,'ctx'=>$ctx,'in'=>mb_substr($in,0,120),'out'=>mb_substr((string)$out,0,120)]);
        return $out;
    }
}

/* re-hook our deepest filters to log */
if (function_exists('remove_filter')) {
    remove_filter('woocommerce_attribute_label','reeid_wc__attr_label_filter', 999);
    remove_filter('woocommerce_attribute','reeid_wc__attr_value_filter', 999);
}
if (!function_exists('reeid_wc__attr_label_filter_dbg')) {
    function reeid_wc__attr_label_filter_dbg($label, $name='', $product=null){
        if (function_exists('is_product') && !is_product()) return $label;
        $lang = function_exists('reeid_wc__current_lang_safe') ? reeid_wc__current_lang_safe() : 'en';
        $src  = wp_strip_all_tags((string)$label);
        $out  = esc_html( reeid_wc__translate_scalar_dbgwrap($src, $lang, 'attr_label') );
        reeid_wc__dlog('label', ['pid'=>$product ? $product->get_id() : 0, 'src'=>$src, 'out'=>$out, 'lang'=>$lang]);
        return $out;
    }
    add_filter('woocommerce_attribute_label','reeid_wc__attr_label_filter_dbg',999,3);
}
if (!function_exists('reeid_wc__attr_value_filter_dbg')) {
    function reeid_wc__attr_value_filter_dbg($text){
        if (function_exists('is_product') && !is_product()) return $text;
        $lang  = function_exists('reeid_wc__current_lang_safe') ? reeid_wc__current_lang_safe() : 'en';
        $plain = trim(wp_strip_all_tags(is_array($text) ? implode(', ', $text) : (string)$text));
        if ($plain==='') return $text;
        $out = esc_html( reeid_wc__translate_scalar_dbgwrap($plain, $lang, 'attr_value') );
        reeid_wc__dlog('value', ['src'=>$plain, 'out'=>$out, 'lang'=>$lang]);
        return $out;
    }
    add_filter('woocommerce_attribute','reeid_wc__attr_value_filter_dbg',999,3);
}
/** REEID: strong language resolver for WC attrs (query > plugin > cookie > en) */
if (!function_exists('reeid_wc__current_lang_safe')) {
    function reeid_wc__current_lang_safe(){
        // 1) Explicit override via query string
        if (isset($_GET['reeid_force_lang'])) {
            $l = strtolower(preg_replace('/[^a-z\-]/i','', (string) $_GET['reeid_force_lang']));
            if ($l !== '') return $l;
        }
        // 2) Plugin’s own detector (if available)
        if (function_exists('reeid_wc_current_lang')) {
            $l = (string) reeid_wc_current_lang();
            if ($l !== '') return strtolower($l);
        }
        // 3) Cookie (your plugin commonly sets one)
        if (!empty($_COOKIE['reeid_lang'])) {
            $l = strtolower(preg_replace('/[^a-z\-]/i','', (string) $_COOKIE['reeid_lang']));
            if ($l !== '') return $l;
        }
        return 'en';
    }
}
/** REEID: attrs trace to uploads log (temporary) */
if (!function_exists('reeid_wc__dlog')) {
    function reeid_wc__dlog($tag, $data){
        if (function_exists('reeid_debug_log')) {
            reeid_debug_log('[ATTR]', $tag . ' ' . wp_json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR));
        } elseif (function_exists('error_log')) {
            error_log('[ATTR] '.$tag.' '. wp_json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PARTIAL_OUTPUT_ON_ERROR));
        }
    }
}

if (!function_exists('reeid_wc__translate_scalar_dbgwrap')) {
    function reeid_wc__translate_scalar_dbgwrap($text, $lang, $ctx){
        $in = (string)$text;
        $fn = null;
        foreach (['reeid_inline_translate','reeid_wc_inline_translate','reeid_translate_line'] as $f) {
            if (function_exists($f)) { $fn = $f; break; }
        }
        $out = $fn ? call_user_func($fn, $in, $lang, $ctx) : $in;
        reeid_wc__dlog('scalar', compact('lang','ctx') + ['in'=>$in,'out'=>$out]);
        return $out;
    }
}

/* Re-hook value/label filters with tracing (and highest prio) */
if (function_exists('remove_filter')) {
    remove_filter('woocommerce_attribute_label','reeid_wc__attr_label_filter', 999);
    remove_filter('woocommerce_attribute','reeid_wc__attr_value_filter', 999);
}
if (!function_exists('reeid_wc__attr_label_filter_dbg')) {
    function reeid_wc__attr_label_filter_dbg($label, $name='', $product=null){
        if (function_exists('is_product') && !is_product()) return $label;
        $lang = reeid_wc__current_lang_safe();
        $src  = wp_strip_all_tags((string)$label);
        $out  = esc_html( reeid_wc__translate_scalar_dbgwrap($src, $lang, 'attr_label') );
        reeid_wc__dlog('label', ['pid'=>($product&&method_exists($product,'get_id'))?$product->get_id():0, 'lang'=>$lang, 'src'=>$src, 'out'=>$out]);
        return $out;
    }
    add_filter('woocommerce_attribute_label','reeid_wc__attr_label_filter_dbg', 9999, 3);
}
if (!function_exists('reeid_wc__attr_value_filter_dbg')) {
    function reeid_wc__attr_value_filter_dbg($text){
        if (function_exists('is_product') && !is_product()) return $text;
        $lang  = reeid_wc__current_lang_safe();
        $plain = trim(wp_strip_all_tags(is_array($text) ? implode(', ', $text) : (string)$text));
        if ($plain==='') return $text;
        $out = esc_html( reeid_wc__translate_scalar_dbgwrap($plain, $lang, 'attr_value') );
        reeid_wc__dlog('value', ['lang'=>$lang, 'src'=>$plain, 'out'=>$out]);
        return $out;
    }
    add_filter('woocommerce_attribute','reeid_wc__attr_value_filter_dbg', 9999, 3);
}
/** =========================================================
 * REEID: Minimal frontend translator for short strings (WC attrs)
 * - Defines reeid_translate_line() if missing.
 * - Uses saved OpenAI API key from settings (tries common option names).
 * - Caches per-request + transient to avoid repeat API calls.
 * - Hard guards: only runs for <= 160 chars; otherwise returns original.
 * ========================================================= */
if (!function_exists('reeid_translate_line')) {
    function reeid_translate_line($text, $target_lang = 'en', $context = 'attr'){
        $src = (string) $text; $target_lang = trim(strtolower((string)$target_lang));
        if ($src === '' || $target_lang === '' || $target_lang === 'en') return $src;

        // Short-string guard (labels/values)
        if (mb_strlen($src, 'UTF-8') > 160) return $src;

        // Static in-request cache
        static $CACHE = [];
        $k = md5($target_lang . '|' . $context . '|' . $src);
        if (isset($CACHE[$k])) return $CACHE[$k];

        // Transient cache (24h)
        $t_key = 'reeid_tline_' . $k;
        $hit = get_transient($t_key);
        if (is_string($hit) && $hit !== '') { $CACHE[$k] = $hit; return $hit; }

        // Locate API key (common option names)
        $api_key = '';
        foreach (['reeid_openai_api_key','reeid_api_key','openai_api_key'] as $opt) {
            $v = (string) get_option($opt, '');
            if ($v !== '') { $api_key = $v; break; }
        }
        if ($api_key === '') return $src; // no key available

        // Choose model (optional setting)
        $model = get_option('reeid_openai_model', 'gpt-4o-mini');
        if (!is_string($model) || $model === '') $model = 'gpt-4o-mini';

        // Build request (Chat Completions)
        $body = [
            'model' => $model,
            'temperature' => 0,
            'messages' => [
                ['role'=>'system','content'=>"You are a precise translator. Translate the user text into {$target_lang}. Output only the translation, no quotes or labels. Preserve meaning; keep it concise for a single label/value. If the text is already {$target_lang}, return it unchanged."],
                ['role'=>'user','content'=>$src],
            ],
        ];
        $args = [
            'timeout' => 7,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($body),
        ];

        $resp = wp_remote_post('https://api.openai.com/v1/chat/completions', $args);
        if (is_wp_error($resp)) return $src;

        $code = wp_remote_retrieve_response_code($resp);
        $json = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code !== 200 || !is_array($json)) return $src;

        $out = '';
        if (!empty($json['choices'][0]['message']['content'])) {
            $out = trim((string) $json['choices'][0]['message']['content']);
        }
        if ($out === '') return $src;

        // Save caches
        set_transient($t_key, $out, DAY_IN_SECONDS);
        $CACHE[$k] = $out;
        return $out;
    }
}
/** =========================================================
 * REEID: Scoped bridge for the "Additional information" tab
 * - Replaces the tab callback with a wrapper that captures just this tab's HTML,
 *   translates the attributes table (<th>/<td>) via our translator, and prints it.
 * - No global output buffering; only around this Woo tab render.
 * ========================================================= */
if (!defined('REEID_WC_TABS_BRIDGE')) { define('REEID_WC_TABS_BRIDGE', 1); }

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
if (!function_exists('reeid_wc__translate_scalar')) {
    function reeid_wc__translate_scalar($text, $lang, $ctx){
        $text = (string)$text; if ($text==='') return $text;
        foreach (['reeid_inline_translate','reeid_wc_inline_translate','reeid_translate_line'] as $fn) {
            if (function_exists($fn)) {
                try { $o = call_user_func($fn, $text, $lang, $ctx);
                      if (is_string($o) && $o!=='') return $o; } catch (Throwable $e) {}
            }
        }
        $maybe = apply_filters('reeid_translate_string', null, $text, $lang, $ctx);
        return (is_string($maybe) && $maybe!=='') ? $maybe : $text;
    }
}

if (!function_exists('reeid_wc__ai_tab_wrapper')) {
    function reeid_wc__ai_tab_wrapper($callback){
        return function($key = 'additional_information', $tab = []) use ($callback){
            ob_start();
            // Run the original callback to render the tab HTML
            if (is_callable($callback)) {
                call_user_func($callback, $key, $tab);
            } else {
                // Default Woo callback, if callable isn't resolvable
                if (function_exists('woocommerce_product_additional_information_tab')) {
                    woocommerce_product_additional_information_tab($key, $tab);
                }
            }
            $html = (string) ob_get_clean();

            $lang = reeid_wc__current_lang_safe();

            // Try DOM first
            $processed = false;
            if (class_exists('DOMDocument')) {
                try {
                    $prev = libxml_use_internal_errors(true);
                    $doc  = new DOMDocument();
                    $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                    $xp   = new DOMXPath($doc);
                    $tables = $xp->query('//table[contains(@class,"shop_attributes") or contains(@class,"woocommerce-product-attributes")]');
                    if ($tables && $tables->length) {
                        foreach ($tables as $table) {
                            foreach ($xp->query('.//th', $table) as $th) {
                                $raw = trim($th->textContent);
                                if ($raw !== '') {
                                    $th->nodeValue = '';
                                    $th->appendChild($doc->createTextNode(
                                        reeid_wc__translate_scalar($raw, $lang, 'attr_label')
                                    ));
                                }
                            }
                            foreach ($xp->query('.//td', $table) as $td) {
                                $raw = trim($td->textContent);
                                if ($raw !== '') {
                                    $td->nodeValue = '';
                                    $td->appendChild($doc->createTextNode(
                                        reeid_wc__translate_scalar($raw, $lang, 'attr_value')
                                    ));
                                }
                            }
                        }
                        $html = $doc->saveHTML();
                        $processed = true;
                    }
                    libxml_clear_errors();
                    libxml_use_internal_errors($prev);
                } catch (Throwable $e) { /* fallback below */ }
            }

            if (!$processed) {
                // Regex fallback limited to the attributes table
                $html = preg_replace_callback(
                    '#(<table[^>]+class="[^"]*(?:shop_attributes|woocommerce-product-attributes)[^"]*"[^>]*>)(.*?)(</table>)#is',
                    function($m) use ($lang){
                        $open = $m[1]; $inner = $m[2]; $close = $m[3];
                        $inner = preg_replace_callback('#<th[^>]*>(.*?)</th>#is', function($mm) use ($lang){
                            $raw = trim(wp_strip_all_tags($mm[1]));
                            $tr  = esc_html(reeid_wc__translate_scalar($raw, $lang, 'attr_label'));
                            return '<th class="woocommerce-product-attributes-item__label">'.$tr.'</th>';
                        }, $inner);
                        $inner = preg_replace_callback('#<td[^>]*>(.*?)</td>#is', function($mm) use ($lang){
                            $raw = trim(wp_strip_all_tags($mm[1]));
                            $tr  = esc_html(reeid_wc__translate_scalar($raw, $lang, 'attr_value'));
                            return '<td class="woocommerce-product-attributes-item__value">'.$tr.'</td>';
                        }, $inner);
                        return $open.$inner.$close;
                    },
                    $html
                );
            }

            echo $html;
        };
    }
}

if (!function_exists('reeid_wc__bridge_additional_info_tab')) {
    function reeid_wc__bridge_additional_info_tab($tabs){
        if (!is_array($tabs) || !isset($tabs['additional_information'])) return $tabs;
        $cb = null;
        if (!empty($tabs['additional_information']['callback'])) {
            $cb = $tabs['additional_information']['callback'];
        } elseif (function_exists('woocommerce_product_additional_information_tab')) {
            $cb = 'woocommerce_product_additional_information_tab';
        }
        if ($cb) {
            $tabs['additional_information']['callback'] = reeid_wc__ai_tab_wrapper($cb);
        }
        return $tabs;
    }
    add_filter('woocommerce_product_tabs', 'reeid_wc__bridge_additional_info_tab', 99);
}




// === REEID title/i18n bridge (product pages) ===============================
// Paste into: plugins/reeid-translate/includes/rt-wc-i18n-lite.php

if (!function_exists('reeid_current_lang_from_url')) {
    function reeid_current_lang_from_url() {
        $enabled = get_option('reeid_enabled_languages', '["en"]');
        $langs   = json_decode(is_string($enabled) ? $enabled : '["en"]', true);
        if (!is_array($langs) || !$langs) $langs = ['en'];

        $req  = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
        $path = trim((string)parse_url($req, PHP_URL_PATH), '/');
        $first = $path === '' ? '' : strtok($path, '/');

        if (in_array($first, $langs, true)) return $first;
        return (string) get_option('reeid_translation_source_lang', $langs[0]);
    }
}

if (!function_exists('reeid_product_title_for_lang')) {
    function reeid_product_title_for_lang($post_id, $lang) {
        $meta = get_post_meta($post_id, "_reeid_wc_tr_$lang", true);
        if (is_array($meta) && !empty($meta['title'])) return (string)$meta['title'];
        if (is_string($meta) && $meta !== '') {
            $m = json_decode($meta, true);
            if (is_array($m) && !empty($m['title'])) return (string)$m['title'];
        }
        return get_the_title($post_id);
    }
}

/* H1 on the product page */
add_filter('the_title', function ($title, $post_id) {
    if (is_admin()) return $title;
    if (get_post_type($post_id) !== 'product') return $title;
    if (!function_exists('is_singular') || !is_singular('product')) return $title;

    $lang = reeid_current_lang_from_url();
    $def  = (string) get_option('reeid_translation_source_lang', 'en');
    if ($lang === $def) return $title;

    $t = reeid_product_title_for_lang($post_id, $lang);
    return $t ?: $title;
}, 10, 2);

/* <title> – core */
add_filter('document_title_parts', function ($parts) {
    if (is_admin() || !function_exists('is_singular') || !is_singular('product')) return $parts;
    $lang = reeid_current_lang_from_url();
    $def  = (string) get_option('reeid_translation_source_lang', 'en');
    if ($lang === $def) return $parts;

    $post_id = get_queried_object_id();
    if ($post_id) {
        $t = reeid_product_title_for_lang($post_id, $lang);
        if ($t) $parts['title'] = $t;
    }
    return $parts;
}, 11);

/* <title> – Rank Math (if active) */
add_filter('rank_math/frontend/title', function ($title) {
    if (is_admin() || !function_exists('is_singular') || !is_singular('product')) return $title;
    $lang = reeid_current_lang_from_url();
    $def  = (string) get_option('reeid_translation_source_lang', 'en');
    if ($lang === $def) return $title;

    $post_id = get_queried_object_id();
    $t = $post_id ? reeid_product_title_for_lang($post_id, $lang) : '';
    return $t ?: $title;
}, 11);

/* <title> – Yoast (if active) */
add_filter('wpseo_title', function ($title) {
    if (is_admin() || !function_exists('is_singular') || !is_singular('product')) return $title;
    $lang = reeid_current_lang_from_url();
    $def  = (string) get_option('reeid_translation_source_lang', 'en');
    if ($lang === $def) return $title;

    $post_id = get_queried_object_id();
    $t = $post_id ? reeid_product_title_for_lang($post_id, $lang) : '';
    return $t ?: $title;
}, 11);

/* HTTP Content-Language (unique name; won’t clash with existing) */
add_action('template_redirect', function () {
    if (is_admin()) return;
    $lang = reeid_current_lang_from_url();
    if (!$lang || headers_sent()) return;
    header_remove('Content-Language');
    header('Content-Language: ' . $lang);
}, 11);

/* Meta fallback for validators (harmless if duplicated) */
add_action('wp_head', function () {
    if (is_admin()) return;
    $lang = reeid_current_lang_from_url();
    if ($lang) echo '<meta http-equiv="Content-Language" content="', esc_attr($lang), "\" />\n";
}, 0);

// === REEID meta/OG/Twitter description i18n ===============================
// Paste below the earlier title i18n block in rt-wc-i18n-lite.php

if (!function_exists('reeid_product_excerpt_for_lang')) {
    function reeid_product_excerpt_for_lang($post_id, $lang) {
        $meta = get_post_meta($post_id, "_reeid_wc_tr_$lang", true);
        $txt  = '';
        if (is_array($meta) && !empty($meta['excerpt'])) $txt = (string)$meta['excerpt'];
        elseif (is_string($meta) && $meta !== '') {
            $m = json_decode($meta, true);
            if (is_array($m) && !empty($m['excerpt'])) $txt = (string)$m['excerpt'];
        }
        if ($txt === '' && has_excerpt($post_id)) $txt = get_the_excerpt($post_id);
        return wp_strip_all_tags($txt);
    }
}

if (!function_exists('reeid_locale_tag_from_code')) {
    function reeid_locale_tag_from_code($code) {
        // crude but safe defaults for OG/Twitter
        return $code === 'zh' ? 'zh_CN' : ($code === 'de' ? 'de_DE' : 'en_US');
    }
}

/* Core meta description (if something uses document_title, many also respect this) */
add_filter('pre_option_blogdescription', function ($v) { return $v; }); // no-op, keeps core happy

/* Rank Math description */
add_filter('rank_math/frontend/description', function ($desc) {
    if (!function_exists('is_singular') || !is_singular('product')) return $desc;
    $lang = reeid_current_lang_from_url();
    $def  = (string) get_option('reeid_translation_source_lang', 'en');
    if ($lang === $def) return $desc;
    $pid = get_queried_object_id();
    $d   = $pid ? reeid_product_excerpt_for_lang($pid, $lang) : '';
    return $d !== '' ? $d : $desc;
}, 11);

/* Yoast description */
add_filter('wpseo_metadesc', function ($desc) {
    if (!function_exists('is_singular') || !is_singular('product')) return $desc;
    $lang = reeid_current_lang_from_url();
    $def  = (string) get_option('reeid_translation_source_lang', 'en');
    if ($lang === $def) return $desc;
    $pid = get_queried_object_id();
    $d   = $pid ? reeid_product_excerpt_for_lang($pid, $lang) : '';
    return $d !== '' ? $d : $desc;
}, 11);

/* OG/Twitter fallbacks (works with/without SEO plugins) */
add_action('wp_head', function () {
    if (is_admin() || !function_exists('is_singular') || !is_singular('product')) return;

    $lang = reeid_current_lang_from_url();
    $pid  = get_queried_object_id();
    if (!$pid) return;

    $desc = reeid_product_excerpt_for_lang($pid, $lang);
    if ($desc === '') return;

    // Emit early so detectors see localized text even if theme/footer is English-heavy.
    echo '<meta name="description" content="', esc_attr($desc), "\" />\n";
    echo '<meta property="og:description" content="', esc_attr($desc), "\" />\n";
    echo '<meta name="twitter:description" content="', esc_attr($desc), "\" />\n";
    echo '<meta property="og:locale" content="', esc_attr(reeid_locale_tag_from_code($lang)), "\" />\n";
}, 3);
/* =========================
 * Product Title i18n (H1, tab, SEO, WC getters)
 * ========================= */

// 0) Reliable URL-lang helper (prefix match: /en/, /de/, /zh/)
if (!function_exists('reeid_current_lang_from_url')) {
    function reeid_current_lang_from_url() {
        $enabled = get_option('reeid_enabled_languages', '["en"]');
        $langs   = json_decode(is_string($enabled) ? $enabled : '["en"]', true);
        if (!is_array($langs) || empty($langs)) $langs = array('en');

        $req  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = trim((string) parse_url($req, PHP_URL_PATH), '/');
        $seg0 = $path === '' ? '' : explode('/', $path, 2)[0];

        return in_array($seg0, $langs, true)
            ? $seg0
            : (string) get_option('reeid_translation_source_lang', $langs[0] ?? 'en');
    }
}

// 1) Read localized title from our product meta
if (!function_exists('reeid_product_title_for_lang')) {
    function reeid_product_title_for_lang($post_id, $lang) {
        if (!$post_id || !$lang) return '';
        $meta = get_post_meta($post_id, "_reeid_wc_tr_$lang", true);
        $title = '';
        if (is_array($meta) && !empty($meta['title'])) {
            $title = (string) $meta['title'];
        } elseif (is_string($meta) && $meta !== '') {
            $m = json_decode($meta, true);
            if (is_array($m) && !empty($m['title'])) $title = (string) $m['title'];
        }
        return wp_strip_all_tags($title);
    }
}

// 2) H1 / the_title (run LAST so we win over theme/SEO tweaks)
add_filter('the_title', function ($title, $post_id = 0) {
    if (is_admin()) return $title;
    // Resolve ID if not provided (rare but safe)
    if (!$post_id) $post_id = get_the_ID();
    if (get_post_type($post_id) !== 'product') return $title;

    $def  = (string) get_option('reeid_translation_source_lang', 'en');
    $lang = reeid_current_lang_from_url();
    if ($lang === $def) return $title;

    $t = reeid_product_title_for_lang($post_id, $lang);
    return ($t !== '') ? $t : $title;
}, 999, 2);

// 3) WooCommerce internal getters (affects breadcrumbs, widgets, schema, etc.)
add_filter('woocommerce_product_get_name', function ($name, $product) {
    if (is_admin()) return $name;
    if (!is_object($product)) return $name;

    $def  = (string) get_option('reeid_translation_source_lang', 'en');
    $lang = reeid_current_lang_from_url();
    if ($lang === $def) return $name;

    $t = reeid_product_title_for_lang($product->get_id(), $lang);
    return ($t !== '') ? $t : $name;
}, 999, 2);
add_filter('woocommerce_product_variation_get_name', function ($name, $product) {
    if (is_admin()) return $name;

    $def  = (string) get_option('reeid_translation_source_lang', 'en');
    $lang = reeid_current_lang_from_url();
    if ($lang === $def) return $name;

    $t = reeid_product_title_for_lang($product->get_id(), $lang);
    return ($t !== '') ? $t : $name;
}, 999, 2);

// 4) Browser tab title (core) – use pre_get_document_title to override string directly
add_filter('pre_get_document_title', function ($title) {
    if (is_admin() || !function_exists('is_singular') || !is_singular('product')) return $title;

    $def  = (string) get_option('reeid_translation_source_lang', 'en');
    $lang = reeid_current_lang_from_url();
    if ($lang === $def) return $title;

    $pid = get_queried_object_id();
    $t   = $pid ? reeid_product_title_for_lang($pid, $lang) : '';
    return ($t !== '') ? $t : $title;
}, 999);

// 5) SEO plugin titles
add_filter('rank_math/frontend/title', function ($title) {
    if (!function_exists('is_singular') || !is_singular('product')) return $title;
    $def  = (string) get_option('reeid_translation_source_lang', 'en');
    $lang = reeid_current_lang_from_url();
    if ($lang === $def) return $title;

    $pid = get_queried_object_id();
    $t   = $pid ? reeid_product_title_for_lang($pid, $lang) : '';
    return ($t !== '') ? $t : $title;
}, 999);
add_filter('wpseo_title', function ($title) {
    if (!function_exists('is_singular') || !is_singular('product')) return $title;
    $def  = (string) get_option('reeid_translation_source_lang', 'en');
    $lang = reeid_current_lang_from_url();
    if ($lang === $def) return $title;

    $pid = get_queried_object_id();
    $t   = $pid ? reeid_product_title_for_lang($pid, $lang) : '';
    return ($t !== '') ? $t : $title;
}, 999);
