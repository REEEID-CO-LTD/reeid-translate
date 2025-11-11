<?php
if (!defined('ABSPATH')) exit;

/** Unicode slug: lowercase (where applicable), keep letters/numbers/marks, collapse spaces to “-”, drop other symbols. */
function rt_unicode_slugify($title){
$t = html_entity_decode((string)$title, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
$t = strip_shortcodes($t);
$t = wp_strip_all_tags($t, true);
$t = preg_replace('/\s+/u',' ', trim($t));
$t = function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
$t = preg_replace('/\s+/u','-', $t);
$t = preg_replace('/[^\p{L}\p{N}\p{M}\-._]/u','', $t);
$t = preg_replace('/-+/u','-', $t);
return trim($t, '-._');
}

function rt_keep_native_slug_if_needed($post){
if (!is_object($post) || empty($post->ID)) return;
$slug  = (string)$post->post_name;
$title = (string)$post->post_title;
$want  = rt_unicode_slugify($title);
if (!$want) return;

$slug_is_ascii = (bool) preg_match('/^[\x00-\x7F\-._]+$/', $slug);
if ($slug !== $want || $slug_is_ascii){
$post_type = $post->post_type ?: 'post';
$unique = wp_unique_post_slug($want, $post->ID, $post->post_status, $post_type, $post->post_parent);
remove_action('save_post', 'rt_native_slug_on_save', 20);
wp_update_post(['ID'=>$post->ID,'post_name'=>$unique], true);
add_action('save_post', 'rt_native_slug_on_save', 20, 2);
}
}

function rt_native_slug_on_save($post_id, $post){
if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
if (wp_is_post_revision($post_id)) return;
rt_keep_native_slug_if_needed($post);
}
add_action('save_post', 'rt_native_slug_on_save', 20, 2);

add_action('template_redirect', function () {
if (is_admin() || !is_singular()) return;
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
if (!preg_match('#^/([a-z]{2}(?:-[a-z0-9]{2})?)/#i', $path)) return;
global $post; if (!$post) return;
rt_keep_native_slug_if_needed($post);
}, 1);

# === REEID: 301 redirect wrong slug → canonical native slug (singular only) ===
add_action('template_redirect', function () {
    /* disabled to avoid switcher loops; rely on 404-rescue only */
    return;

    if (!is_singular()) return;
    global $post, $wp;

    if (!$post || empty($post->post_name)) return;

    // Current request path (no host/query)
    $req_path = isset($wp->request) ? '/'.ltrim($wp->request, '/') : parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (!$req_path) return;

    // Canonical path from permalink
    $canon_url  = get_permalink($post);
    if (!$canon_url) return;
    $canon_path = parse_url($canon_url, PHP_URL_PATH);

    // Compare only paths (ignore trailing slashes)
    $rp = rtrim($req_path, '/');   $cp = rtrim($canon_path, '/');
    if ($rp === $cp) return;

    // Extra guard: only redirect if last segment mismatches post_name
    $seg_req   = rawurldecode(basename($rp));
    $seg_canon = rawurldecode(basename($cp));
    if ($seg_req === $seg_canon) return;

    // 301 to canonical permalink
    wp_redirect($canon_url, 301);
    exit;
}, 0);

# === REEID: 404 rescue — map /{lang}/{old-slug[-N]}/ -> source -> lang child, 301 ===
add_action('template_redirect', function () {
    if (!is_404()) return;

    // Only simple pretty permalinks like /xx/slug/
    $req_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (!$req_path) return;

    if (!preg_match('#^/([a-z]{2}(?:-[A-Za-z]{2})?)/([^/]+)/?$#', $req_path, $m)) return;
    $lang_req = strtolower($m[1]);
    $slug_req = $m[2];

    // Try exact post_name match first
    $p = get_page_by_path($slug_req, OBJECT, get_post_types(['public'=>true]));
    // If not found, strip trailing "-123" pattern and retry (common for duplicated slugs)
    if (!$p && preg_match('/^(.*?)-\d+$/', $slug_req, $mm)) {
        $p = get_page_by_path($mm[1], OBJECT, get_post_types(['public'=>true]));
    }
    if (!$p) return;

    // Resolve source group
    $src = (int) get_post_meta($p->ID, '_reeid_translation_source', true);
    if ($src <= 0) $src = (int) $p->ID;

    // Find child in requested lang
    $child = get_posts([
        'post_type'      => 'any',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'meta_query'     => [
            ['key'=>'_reeid_translation_source','value'=>strval($src),'compare'=>'='],
            ['key'=>'_reeid_translation_lang','value'=>$lang_req,'compare'=>'='],
        ],
    ]);
    if (!$child) return;

    $dest = get_permalink($child[0]->ID);
    if ($dest) {
        wp_redirect($dest, 301);
        exit;
    }
}, 0);

# === REEID: native slugs for Woo products (on insert/update) ===
if (!function_exists('reeid_native_slug_products')) {
  add_filter('wp_insert_post_data','reeid_native_slug_products', 20, 2);
  function reeid_native_slug_products($data, $postarr){
    if (($data['post_type'] ?? '') !== 'product') return $data;
    if (empty($data['post_title'])) return $data;

    $id   = intval($postarr['ID'] ?? 0);
    $lang = $id ? get_post_meta($id,'_reeid_translation_lang',true) : ($postarr['_reeid_translation_lang'] ?? '');
    $is_tr = !empty($lang);

    // Only adjust for translations or when title has non-ASCII
    $title = (string)$data['post_title'];
    $has_native = (bool)preg_match('/[^\x00-\x7F]/u', $title);
    if (!$is_tr && !$has_native) return $data;

    // Build native slug from title
    if (function_exists('reeid_sanitize_native_slug')) {
      $slug = reeid_sanitize_native_slug($title);
    } else {
      $slug = sanitize_title($title);
    }
    if (!$slug) return $data;

    // Ensure uniqueness
    $status = $data['post_status'] ?? 'draft';
    $parent = intval($data['post_parent'] ?? 0);
    $uniq = function_exists('wp_unique_post_slug') ? wp_unique_post_slug($slug, $id, $status, 'product', $parent) : $slug;
    $data['post_name'] = $uniq;
    return $data;
  }
}

# === REEID: 404 rescue — also handle /{lang}/product/{slug} ===
add_action('template_redirect', function () {
    if (!is_404()) return;
    $req_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (!$req_path) return;

    // Accept /xx/slug  OR  /xx/product/slug
    if (!preg_match('#^/([a-z]{2}(?:-[A-Za-z]{2})?)/(?:product/)?([^/]+)/?$#', $req_path, $m)) return;
    $lang_req = strtolower($m[1]);
    $slug_req = $m[2];

    // Try exact match by path across public post types (incl. product)
    $types = get_post_types(['public'=>true]);
    $p = get_page_by_path($slug_req, OBJECT, $types);
    if (!$p && preg_match('/^(.*?)-\d+$/', $slug_req, $mm)) {
        $p = get_page_by_path($mm[1], OBJECT, $types);
    }
    if (!$p) return;

    $src = (int)get_post_meta($p->ID, '_reeid_translation_source', true);
    if ($src <= 0) $src = (int)$p->ID;

    $child = get_posts([
        'post_type'      => 'any',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'meta_query'     => [
            ['key'=>'_reeid_translation_source','value'=>strval($src),'compare'=>'='],
            ['key'=>'_reeid_translation_lang','value'=>$lang_req,'compare'=>'='],
        ],
    ]);
    if (!$child) return;

    $dest = get_permalink($child[0]->ID);
    if ($dest) { wp_redirect($dest, 301); exit; }
}, 0);
