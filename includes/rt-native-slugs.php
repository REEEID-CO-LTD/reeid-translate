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

    // Safe REQUEST_URI parse
    $raw_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
    $raw_uri = sanitize_text_field($raw_uri);
    $url     = wp_parse_url($raw_uri);
    $path    = $url['path'] ?? '';

    if (!preg_match('#^/([a-z]{2}(?:-[a-z0-9]{2})?)/#i', $path)) return;
    global $post; if (!$post) return;
    rt_keep_native_slug_if_needed($post);
}, 1);

# === REEID: 301 redirect wrong slug → canonical native slug (singular only) ===
add_action('template_redirect', function () {
    return; // disabled to avoid switcher loops

    if (!is_singular()) return;
    global $post, $wp;
    if (!$post || empty($post->post_name)) return;

    // Request path (WP internal or fallback to REQUEST_URI)
    $req_path = isset($wp->request)
        ? '/' . ltrim($wp->request, '/')
        : (function () {
              $raw = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
              $san = sanitize_text_field($raw);
              $p   = wp_parse_url($san);
              return $p['path'] ?? '';
          })();

    if (!$req_path) return;

    // Canonical path
    $canon_url = get_permalink($post);
    if (!$canon_url) return;

    $canon_parts = wp_parse_url($canon_url);
    $canon_path  = $canon_parts['path'] ?? '';
    if (!$canon_path) return;

    // Compare paths
    $rp = rtrim($req_path, '/');
    $cp = rtrim($canon_path, '/');
    if ($rp === $cp) return;

    // Compare last segments
    $seg_req   = rawurldecode(basename($rp));
    $seg_canon = rawurldecode(basename($cp));
    if ($seg_req === $seg_canon) return;

    wp_redirect($canon_url, 301);
    exit;
}, 0);

# === REEID: 404 rescue — map /{lang}/{slug}/ ===
add_action('template_redirect', function () {
    if (!is_404()) return;

    $raw_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
    $raw_uri = sanitize_text_field($raw_uri);
    $parts   = wp_parse_url($raw_uri);
    $req_path = $parts['path'] ?? '';
    if (!$req_path) return;

    if (!preg_match('#^/([a-z]{2}(?:-[A-Za-z]{2})?)/([^/]+)/?$#', $req_path, $m)) return;

    $lang_req = strtolower($m[1]);
    $slug_req = $m[2];

    $p = get_page_by_path($slug_req, OBJECT, get_post_types(['public'=>true]));
    if (!$p && preg_match('/^(.*?)-\d+$/', $slug_req, $mm)) {
        $p = get_page_by_path($mm[1], OBJECT, get_post_types(['public'=>true]));
    }
    if (!$p) return;

    $src = (int) get_post_meta($p->ID, '_reeid_translation_source', true);
    if ($src <= 0) $src = (int) $p->ID;

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

# === REEID: native slugs for Woo products (on insert/update) ===
if (!function_exists('reeid_native_slug_products')) {
  add_filter('wp_insert_post_data','reeid_native_slug_products', 20, 2);
  function reeid_native_slug_products($data, $postarr){
    if (($data['post_type'] ?? '') !== 'product') return $data;
    if (empty($data['post_title'])) return $data;

    $id   = intval($postarr['ID'] ?? 0);
    $lang = $id ? get_post_meta($id,'_reeid_translation_lang',true) : ($postarr['_reeid_translation_lang'] ?? '');
    $is_tr = !empty($lang);

    $title = (string)$data['post_title'];
    $has_native = (bool)preg_match('/[^\x00-\x7F]/u', $title);
    if (!$is_tr && !$has_native) return $data;

    $slug = function_exists('reeid_sanitize_native_slug')
        ? reeid_sanitize_native_slug($title)
        : sanitize_title($title);

    if (!$slug) return $data;

    $status = $data['post_status'] ?? 'draft';
    $parent = intval($data['post_parent'] ?? 0);
    $uniq = wp_unique_post_slug($slug, $id, $status, 'product', $parent);
    $data['post_name'] = $uniq;
    return $data;
  }
}

# === REEID: 404 rescue — handle /{lang}/product/{slug} ===
add_action('template_redirect', function () {
    if (!is_404()) return;

    $raw_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
    $raw_uri = sanitize_text_field($raw_uri);
    $parts = wp_parse_url($raw_uri);
    $req_path = $parts['path'] ?? '';
    if (!$req_path) return;

    if (!preg_match('#^/([a-z]{2}(?:-[A-Za-z]{2})?)/(?:product/)?([^/]+)/?$#', $req_path, $m)) return;

    $lang_req = strtolower($m[1]);
    $slug_req = $m[2];

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
