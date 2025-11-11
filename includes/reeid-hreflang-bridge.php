<?php
/*
Plugin Name: REEID Translate – Hreflang Bridge (Core)
Description: Hreflang for inline WooCommerce translations. Includes late injector + head normalization (former mu-plugins).
Version: 2025.10.24-core14
Author: REEID
*/
if (!defined('ABSPATH')) exit;

/** ------------------------------------------------------------------------
 *  One-time guard
 *  --------------------------------------------------------------------- */
if (defined('REEID_HREFLANG_BRIDGE_LOADED')) return;
define('REEID_HREFLANG_BRIDGE_LOADED', true);

/** ------------------------------------------------------------------------
 *  Lightweight flags (don’t depend on activation hooks)
 *  --------------------------------------------------------------------- */
if (!defined('REEID_HREFLANG_MODE')) {
    $mode = get_option('reeid_hreflang_mode', 'mu');
    $mode = apply_filters('reeid/hreflang_mode', $mode);
    define('REEID_HREFLANG_MODE', in_array($mode, array('mu','seo'), true) ? $mode : 'mu');
}
if (!defined('REEID_HREFLANG_DEBUG')) {
    define('REEID_HREFLANG_DEBUG', false);
}

/** remember if we already echoed a block this request */
if (!isset($GLOBALS['reeid_hreflang_already_echoed'])) {
    $GLOBALS['reeid_hreflang_already_echoed'] = false;
}

/** ------------------------------------------------------------------------
 *  Utils / Normalizers
 *  --------------------------------------------------------------------- */
if (!function_exists('reeid_slug_norm')) {
    /** canonicalize percent-encoding: decode once, encode once (upper-case hex) */
    function reeid_slug_norm($s) {
        return rawurlencode(rawurldecode((string)$s));
    }
}
if (!function_exists('reeid_norm_seg')) {
    /** normalize a *single* path segment (no leading/trailing slashes) */
    function reeid_norm_seg($seg) {
        $seg = trim((string)$seg, '/');
        return rawurlencode(rawurldecode($seg));
    }
}
if (!function_exists('reeid_normalize_last_segment')) {
    /** normalize only the last path segment of a URL */
    function reeid_normalize_last_segment($url) {
        $p = wp_parse_url($url);
        if (empty($p['path'])) return $url;

        $bits = array_values(array_filter(explode('/', $p['path']), 'strlen'));
        if ($bits) {
            $bits[count($bits)-1] = reeid_norm_seg($bits[count($bits)-1]);
            $p['path'] = '/' . implode('/', $bits) . '/';
        }

        $host   = $p['host']   ?? parse_url(home_url('/'), PHP_URL_HOST);
        $scheme = $p['scheme'] ?? (is_ssl() ? 'https' : 'http');
        $out = $scheme . '://' . $host . ($p['path'] ?? '/');
        if (!empty($p['query']))    $out .= '?' . $p['query'];
        if (!empty($p['fragment'])) $out .= '#' . $p['fragment'];
        return $out;
    }
}

/** ------------------------------------------------------------------------
 *  Helpers: languages + meta
 *  --------------------------------------------------------------------- */
function reeid_hreflang_get_langs_defaults(&$langs_out, &$default_out) {
    $enabled = get_option('reeid_enabled_languages', '["en"]');
    $langs   = json_decode(is_string($enabled) ? $enabled : '["en"]', true);
    if (!is_array($langs) || empty($langs)) $langs = array('en');

    $default = get_option('reeid_translation_source_lang', $langs[0] ?? 'en');
    if (!in_array($default, $langs, true)) array_unshift($langs, $default);

    $langs_out   = array_values(array_unique(array_map('strval', $langs)));
    $default_out = (string) $default;
}

function reeid_meta_slug_parse($meta) {
    if (is_array($meta)) {
        return !empty($meta['slug']) ? (string)$meta['slug'] : '';
    }
    if (is_string($meta) && $meta !== '') {
        $maybe = json_decode($meta, true);
        if (is_array($maybe) && !empty($maybe['slug'])) return (string)$maybe['slug'];
        return $meta; // plain slug string case
    }
    return '';
}

function reeid_meta_slug_for_lang($base_id, $lang) {
    $meta = get_post_meta($base_id, '_reeid_wc_tr_' . $lang, true);
    return reeid_meta_slug_parse($meta);
}

/**
 * Find base product by scanning mapping meta for a language+slug pair.
 * LIKE shortlist + exact compare using normalized encoding.
 */
function reeid_find_base_by_lang_slug($lang, $slug) {
    if (!$lang || !$slug) return 0;
    $slug_raw = (string) $slug;
    $slug_can = reeid_slug_norm($slug_raw);

    // shortlist with LIKE on what we were given
    $q = new WP_Query(array(
        'post_type'           => 'product',
        'posts_per_page'      => 20,
        'fields'              => 'ids',
        'no_found_rows'       => true,
        'ignore_sticky_posts' => true,
        'meta_query'          => array(array(
            'key'     => '_reeid_wc_tr_' . $lang,
            'value'   => $slug_raw,
            'compare' => 'LIKE',
        )),
    ));
    if (empty($q->posts)) {
        // second pass: shortlist with canonicalized value
        $q = new WP_Query(array(
            'post_type'           => 'product',
            'posts_per_page'      => 20,
            'fields'              => 'ids',
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
            'meta_query'          => array(array(
                'key'     => '_reeid_wc_tr_' . $lang,
                'value'   => $slug_can,
                'compare' => 'LIKE',
            )),
        ));
        if (empty($q->posts)) return 0;
    }

    foreach ($q->posts as $pid) {
        $mapped = reeid_meta_slug_for_lang((int)$pid, $lang);
        if ($mapped !== '' && reeid_slug_norm($mapped) === $slug_can) {
            return (int)$pid;
        }
    }
    return 0;
}

/** ------------------------------------------------------------------------
 *  Resolver: base (default) product id from any locale product URL
 *  --------------------------------------------------------------------- */
function reeid_hreflang_resolve_product_id(&$langs_out, &$default_out) {
    // 1) languages + default
    reeid_hreflang_get_langs_defaults($langs_out, $default_out);
    $langs = $langs_out; $default = $default_out;

    // 2) trust the main query when available
    if (function_exists('get_queried_object_id')) {
        $qo = get_queried_object_id();
        if ($qo && get_post_type($qo) === 'product') return (int)$qo;
    }

    // 3) current $post is a product?
    if (isset($GLOBALS['post']) && !empty($GLOBALS['post']->ID) && get_post_type($GLOBALS['post']->ID) === 'product') {
        return (int) $GLOBALS['post']->ID;
    }

    // 4) parse the request path
    $req   = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path  = trim((string) parse_url($req, PHP_URL_PATH), '/');
    $parts = $path === '' ? array() : explode('/', $path);

    $maybe_lang     = $parts[0] ?? '';
    $lang_from_path = in_array($maybe_lang, $langs, true) ? $maybe_lang : $default;

    // last non-empty segment as slug (decode once)
    $slug = '';
    for ($i = count($parts) - 1; $i >= 0; $i--) {
        if ($parts[$i] !== '') { $slug = $parts[$i]; break; }
    }
    if ($slug) $slug = rawurldecode($slug);
    if (!$slug) return 0;

    // 5) If localized path (/xx/product/{slug}), try meta mapping back to base
    if ($lang_from_path !== $default) {
        $pid = reeid_find_base_by_lang_slug($lang_from_path, $slug);
        if ($pid) return $pid;
    }

    // 6) exact product slug as base
    $p = get_page_by_path($slug, OBJECT, 'product');
    if ($p && !is_wp_error($p)) return (int)$p->ID;

    // 7) strip "-{lang}" suffix and retry
    $suffix_re = '/-(' . implode('|', array_map('preg_quote', $langs)) . ')$/i';
    $base_slug = preg_replace($suffix_re, '', $slug);
    if ($base_slug && $base_slug !== $slug) {
        $p2 = get_page_by_path($base_slug, OBJECT, 'product');
        if ($p2 && !is_wp_error($p2)) return (int)$p2->ID;

        $pid = reeid_find_base_by_lang_slug($default, $base_slug);
        if ($pid) return $pid;
    }

    // 8) final: default-language lookup on raw slug
    $pid = reeid_find_base_by_lang_slug($default, $slug);
    return $pid ?: 0;
}

/** ------------------------------------------------------------------------
 *  Renderers
 *  --------------------------------------------------------------------- */
function reeid_hreflang_render($post_id = null, $langs = null, $default = null) {
    if ($post_id === null) {
        $langs_var = null; $default_var = null;
        $post_id = reeid_hreflang_resolve_product_id($langs_var, $default_var);
        if (!$post_id) return '';
        if ($langs === null || $default === null) { $langs = $langs_var; $default = $default_var; }
    }
    if ($langs === null || $default === null) reeid_hreflang_get_langs_defaults($langs, $default);

    $home      = rtrim(home_url('/'), '/');
    $base_slug = get_post_field('post_name', $post_id);
    $lines     = array();

    foreach ($langs as $lang) {
        $slug_for_lang = reeid_meta_slug_for_lang($post_id, $lang);
        if ($slug_for_lang === '') $slug_for_lang = $base_slug;

        $seg = reeid_norm_seg($slug_for_lang);
        $url = ($lang === $default)
            ? $home . '/product/' . $seg . '/'
            : $home . '/' . $lang . '/product/' . $seg . '/';

        $lines[$lang] = $url;
    }

    $xdefault = isset($lines[$default]) ? $lines[$default] : reset($lines);

    $out = "<!-- REEID-WC-HREFLANG-FIX -->\n";
    foreach ($lines as $code => $u) {
        $out .= sprintf('<link rel="alternate" hreflang="%s" href="%s" />' . "\n", esc_attr($code), esc_url($u));
    }
    $out .= sprintf('<link rel="alternate" hreflang="x-default" href="%s" />' . "\n", esc_url($xdefault));
    return $out;
}

function reeid_hreflang_render_normalized($post_id = null, $langs = null, $default = null) {
    if ($post_id === null) {
        $langs_var = null; $default_var = null;
        $post_id = reeid_hreflang_resolve_product_id($langs_var, $default_var);
        if (!$post_id) return '';
        if ($langs === null || $default === null) { $langs = $langs_var; $default = $default_var; }
    }
    if ($langs === null || $default === null) reeid_hreflang_get_langs_defaults($langs, $default);

    $home      = rtrim(home_url('/'), '/');
    $base_slug = get_post_field('post_name', $post_id);
    $lines     = array();

    foreach ($langs as $lang) {
        $slug_for_lang = reeid_meta_slug_for_lang($post_id, $lang);
        if ($slug_for_lang === '') $slug_for_lang = $base_slug;

        $url = ($lang === $default)
            ? $home . '/product/' . trim((string)$slug_for_lang, '/') . '/'
            : $home . '/' . $lang . '/product/' . trim((string)$slug_for_lang, '/') . '/';

        $lines[$lang] = reeid_normalize_last_segment($url);
    }

    $xdefault = isset($lines[$default]) ? $lines[$default] : reset($lines);

    $out = "<!-- REEID-WC-HREFLANG-FIX -->\n";
    foreach ($lines as $code => $u) {
        $out .= sprintf('<link rel="alternate" hreflang="%s" href="%s" />' . "\n", esc_attr($code), esc_url($u));
    }
    $out .= sprintf('<link rel="alternate" hreflang="x-default" href="%s" />' . "\n", esc_url($xdefault));
    return $out;
}

/** ------------------------------------------------------------------------
 *  Printer (plain) — no “mode” gate (prevents silent no-op)
 *  --------------------------------------------------------------------- */
function reeid_hreflang_print() {
    if ($GLOBALS['reeid_hreflang_already_echoed']) return;
    if (is_admin()) return;

    $snippet = reeid_hreflang_render();
    if ($snippet) {
        echo $snippet;
        if (REEID_HREFLANG_DEBUG) echo "<!-- REEID-PRINTER -->\n";
        $GLOBALS['reeid_hreflang_already_echoed'] = true;
    }
}
add_action('wp_head', 'reeid_hreflang_print', 90);

/** Late injector (former mu “hreflang-late.php”) */
add_action('wp_head', function () {
    if ($GLOBALS['reeid_hreflang_already_echoed']) return;
    if (is_admin()) return;

    $langs = $default = null;
    $pid   = (int) reeid_hreflang_resolve_product_id($langs, $default);
    if (!$pid) return;

    $snippet = reeid_hreflang_render($pid, $langs, $default);
    if ($snippet) {
        echo $snippet;
        if (REEID_HREFLANG_DEBUG) echo "<!-- REEID-LATE-INJECTOR -->\n";
        $GLOBALS['reeid_hreflang_already_echoed'] = true;
    }
}, 99990);

/** ------------------------------------------------------------------------
 *  Output-buffer fallback (former mu “hreflang-normalize.php”)
 *  - inject canonical + normalized hreflang into </head>
 *  - strip pre-existing canonical/hreflang to avoid duplicates
 *  --------------------------------------------------------------------- */
if (!function_exists('reeid_hreflang_buffer_callback')) {
function reeid_hreflang_buffer_callback($html) {
    if (is_admin()) return $html;

    // resolve now
    $langs = $default = null;
    $pid   = reeid_hreflang_resolve_product_id($langs, $default);
    if (!$pid) return $html;

    $head_pos = stripos($html, '</head>');
    if ($head_pos === false) return $html;

    // normalized hreflang block
    $snippet = reeid_hreflang_render_normalized($pid, $langs, $default);
    if (!$snippet) return $html;

    // normalized canonical (last segment)
    $req  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    $path = strtok($req, '#');
    $bits = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
    if ($bits) {
        $bits[count($bits)-1] = reeid_norm_seg($bits[count($bits)-1]);
        $path = '/' . implode('/', $bits) . '/';
    } else {
        $path = '/';
    }
    $canon = trailingslashit(set_url_scheme(home_url($path)));

    // strip existing canonical + hreflang + any prior REEID block within HEAD
    $head_html = substr($html, 0, $head_pos);
    $head_html = preg_replace('/<link[^>]+rel=[\'"]canonical[\'"][^>]*>\s*/i', '', $head_html);
    $head_html = preg_replace('/<link[^>]+rel=[\'"]alternate[\'"][^>]+hreflang=[\'"][^\'"]+[\'"][^>]*>\s*/i', '', $head_html);
    $head_html = preg_replace('/<!--\s*REEID-WC-HREFLANG-FIX\s*-->.*$/is', '', $head_html);

    $inject  = sprintf('<link rel="canonical" href="%s" />' . "\n", esc_url($canon));
    $inject .= $snippet;
    if (REEID_HREFLANG_DEBUG) $inject .= "<!-- REEID-NORMALIZED -->\n";

    return $head_html . $inject . substr($html, $head_pos);
}}
add_action('template_redirect', function () {
    if (is_admin()) return;
    ob_start('reeid_hreflang_buffer_callback');
}, 1);

/** ------------------------------------------------------------------------
 *  Canonical: product pages must canonicalize to themselves (normalized)
 *  --------------------------------------------------------------------- */
$__reeid_self_canonical = function($url) {
    // try resolver unless we’re clearly on a product loop page
    if (!(function_exists('is_singular') && is_singular('product'))) {
        $l=$d=null; $pid=(int) reeid_hreflang_resolve_product_id($l,$d);
        if (!$pid) return $url;
    }
    $req  = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    $path = strtok($req, '#');

    $bits = explode('/', trim($path, '/'));
    if (!empty($bits)) {
        $bits[count($bits)-1] = reeid_norm_seg($bits[count($bits)-1]);
        $path = '/' . implode('/', $bits) . '/';
    }
    return trailingslashit(set_url_scheme(home_url($path)));
};
add_filter('rank_math/frontend/canonical', $__reeid_self_canonical, 9999);
add_filter('wpseo_canonical',              $__reeid_self_canonical, 9999); // Yoast
add_filter('rel_canonical',                $__reeid_self_canonical, 9999);

/** ------------------------------------------------------------------------
 *  <html lang="…"> from URL prefix (safe; no-op if not language-prefixed)
 *  --------------------------------------------------------------------- */
add_filter('language_attributes', function ($output) {
    $enabled = get_option('reeid_enabled_languages', '["en"]');
    $langs   = json_decode(is_string($enabled) ? $enabled : '["en"]', true);
    if (!is_array($langs) || empty($langs)) $langs = array('en');

    $req   = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path  = trim((string) parse_url($req, PHP_URL_PATH), '/');
    $parts = $path === '' ? array() : explode('/', $path);
    $maybe = $parts[0] ?? '';

    if (in_array($maybe, $langs, true)) {
        if (preg_match('/\blang="[^"]*"/', $output))
            $output = preg_replace('/\blang="[^"]*"/', 'lang="'.$maybe.'"', $output, 1);
        else
            $output .= ' lang="'.$maybe.'"';
        if (stripos($output, 'dir=') === false) $output .= ' dir="ltr"';
    }
    return $output;
}, 99);

/** ------------------------------------------------------------------------
 *  Turn off older/simple emitters & SEO plugin hreflang on product requests
 *  --------------------------------------------------------------------- */
add_action('init', function () {
    if (is_admin()) return;
    // These were present earlier (simple/virtual emitters). If absent, no harm.
    remove_action('wp_head', 'reeid_wc_hreflang_simple', 90);
    remove_action('wp_head', 'reeid_hreflang_products_virtual', 100);
}, 0);

add_action('wp_head', function () {
    if (is_admin()) return;
    // Double-check at runtime as well
    remove_action('wp_head', 'reeid_wc_hreflang_simple', 90);
    remove_action('wp_head', 'reeid_hreflang_products_virtual', 100);
}, 1);

add_action('wp', function () {
    if (is_admin()) return;

    $l=$d=null;
    $is_productish = (function_exists('is_singular') && is_singular('product')) || (bool)reeid_hreflang_resolve_product_id($l,$d);
    if (!$is_productish) return;

    $hook = 'wp_head';
    global $wp_filter;
    if (empty($wp_filter[$hook]) || !is_object($wp_filter[$hook]) || !isset($wp_filter[$hook]->callbacks)) return;

    foreach ((array)$wp_filter[$hook]->callbacks as $priority => $callbacks) {
        foreach ((array)$callbacks as $cb) {
            if (empty($cb['function'])) continue;
            $fn = $cb['function'];
            if (is_array($fn) && is_object($fn[0])) {
                $class = get_class($fn[0]);
                // Rank Math Hreflang class
                if (stripos($class, 'RankMath') !== false && stripos($class, 'Hreflang') !== false) remove_action($hook, $fn, $priority);
                // Yoast frontend/hreflang classes
                if (stripos($class, 'WPSEO') !== false && (stripos($class, 'Frontend') !== false || stripos($class, 'Hreflang') !== false)) remove_action($hook, $fn, $priority);
                // Generic catch-all
                if (stripos($class, 'hreflang') !== false) remove_action($hook, $fn, $priority);
            }
        }
    }
}, 1);

/** ------------------------------------------------------------------------
 *  Tiny optional head debug (safe to leave on; prints only when DEBUG=1)
 *  --------------------------------------------------------------------- */
if (REEID_HREFLANG_DEBUG) {
    add_action('wp_head', function () {
        $l=$d=null; $pid=(int) reeid_hreflang_resolve_product_id($l,$d);
        echo '<meta name="REEID-HREFLANG-DBG" content="mode=' . esc_attr(REEID_HREFLANG_MODE) . ' pid=' . (int)$pid . '" />' . "\n";
    }, 1);
}
