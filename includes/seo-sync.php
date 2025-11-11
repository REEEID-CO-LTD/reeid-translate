<?php


// REEID Translate — Canonical hreflang + SEO meta + (optional) SEO title translation
// File: wp-content/plugins/reeid-translate/includes/seo-sync.php

if (!defined('ABSPATH')) exit;


/**
 * Hreflang: disable seo-sync emitters when the bridge is active (mode 'mu').
 * This leaves the bridge as the single source of truth and avoids conflicts.
 */
add_action('plugins_loaded', function () {
    if (defined('REEID_HREFLANG_MODE') && REEID_HREFLANG_MODE === 'mu') {
        // Stop seo-sync’s own hreflang emitters.
        // (kept) remove_action('wp_head', 'reeid_output_hreflang_tags_seosync', 99);
        remove_action('wp_head', 'reeid_wc_hreflang', 96);
        remove_action('wp_head', 'reeid_wc_hreflang_simple', 96);
        remove_action('wp_head', 'reeid_wc_hreflang_products_virtual', 96);

        // Also neutralize its output-buffer inserter (the anonymous callback).
        $GLOBALS['reeid_disable_hreflang_ob'] = true;
    }
}, 0);






/* ---------------------------------------------------------------------
 * Defaults (override via wp-config.php)
 * -------------------------------------------------------------------*/
if (!defined('REEID_SEO_TITLE_SYNC'))        define('REEID_SEO_TITLE_SYNC', true);
if (!defined('REEID_SEO_TITLE_OVERWRITE'))   define('REEID_SEO_TITLE_OVERWRITE', true);
if (!defined('REEID_SEO_TITLE_TRANSLATE'))   define('REEID_SEO_TITLE_TRANSLATE', true); // translate SEO title across langs
if (!defined('REEID_HREFLANG_OUTPUT'))       define('REEID_HREFLANG_OUTPUT', true);
if (!defined('REEID_WRAP_CONTENT_LANG'))     define('REEID_WRAP_CONTENT_LANG', false);  // optional wrapper around content

/* ---------------------------------------------------------------------
 * Debug helper (logs only if REEID_DEBUG === true)
 * -------------------------------------------------------------------*/
if (!function_exists('reeid_debug_log')) {
    function reeid_debug_log($label, $data = null) {
        if (!defined('REEID_DEBUG') || !REEID_DEBUG) return;
        $file = WP_CONTENT_DIR . '/uploads/reeid-debug.log';
        $line = '[' . gmdate('c') . "] {$label}: ";
        $line .= (is_array($data) || is_object($data))
            ? wp_json_encode($data, JSON_UNESCAPED_UNICODE)
            : (string) $data;
        $line .= "\n";
        @file_put_contents($file, $line, FILE_APPEND);
    }
}

/* ---------------------------------------------------------------------
 * Short-text translator for SEO fields (keeps tokens if available)
 * -------------------------------------------------------------------*/
if (!function_exists('reeid_translate_short_text')) {
    function reeid_translate_short_text($text, $from, $to, $tone = 'Neutral') {
        $text = is_string($text) ? trim($text) : '';
        if ($text === '' || !$from || !$to || strcasecmp($from, $to) === 0) {
            return $text;
        }
        // Preferred: token-preserving path if your translator is loaded
        if (function_exists('reeid_translate_preserve_tokens') && function_exists('reeid_focuskw_call_translator')) {
            return (string) reeid_translate_preserve_tokens(
                $text,
                $from,
                $to,
                [ 'meta_key' => 'seo_title', 'src' => $from, 'tgt' => $to ]
            );
        }
        // Fallback: your HTML translator wrapper
        if (function_exists('reeid_translate_html_with_openai')) {
            return (string) reeid_translate_html_with_openai($text, $from, $to, 'classic', $tone);
        }
        // Last resort: passthrough
        return $text;
    }
}

/* ============================================================
 * Title meta integration (RankMath, Yoast, SEOPress, AIOSEO)
 * ============================================================ */
if (!function_exists('reeid_enabled_plugins_for_seo')) {
    function reeid_enabled_plugins_for_seo() {
        $def = ['rankmath','yoast','seopress','aioseo'];
        $raw = defined('REEID_SEO_PLUGINS') ? @unserialize(REEID_SEO_PLUGINS) : $def;
        return is_array($raw) && $raw ? array_values(array_intersect($raw, $def)) : $def;
    }
}
if (!function_exists('reeid_seo_read_priority')) {
    function reeid_seo_read_priority() {
        $def = ['rankmath','yoast','seopress','aioseo'];
        $raw = defined('REEID_SEO_PRIORITY') ? @unserialize(REEID_SEO_PRIORITY) : $def;
        return is_array($raw) && $raw ? array_values(array_intersect($raw, $def)) : $def;
    }
}
function reeid_title_meta_map() {
    return [
        'rankmath' => ['read'=>['rank_math_title'],          'write'=>['rank_math_title'],          'compound'=>null],
        'yoast'    => ['read'=>['_yoast_wpseo_title'],       'write'=>['_yoast_wpseo_title'],       'compound'=>null],
        'seopress' => ['read'=>['_seopress_titles_title'],    'write'=>['_seopress_titles_title'],    'compound'=>null],
        'aioseo'   => ['read'=>['_aioseo_title'],             'write'=>['_aioseo_title'],             'compound'=>'_aioseo'],
    ];
}
function reeid_read_canonical_title($post_id) {
    $map = reeid_title_meta_map();

    // 1) Try plugin-specific SEO title fields in priority order.
    foreach (reeid_seo_read_priority() as $plug) {
        if (empty($map[$plug])) continue;

        foreach ($map[$plug]['read'] as $k) {
            $v = trim((string) get_post_meta($post_id, $k, true));
            if ($v !== '') return $v;
        }
        if (!empty($map[$plug]['compound'])) {
            $compound = get_post_meta($post_id, $map[$plug]['compound'], true);
            if (is_array($compound) && !empty($compound['title'])) {
                $v = trim((string) $compound['title']);
                if ($v !== '') return $v;
            }
        }
    }

    // 2) Fallback: use the post title so sync always has a value.
    $fallback = get_the_title($post_id);
    return is_string($fallback) ? trim($fallback) : '';
}
function reeid_write_title_all_plugins($post_id, $title) {
    if ($title === '') return;
    $map = reeid_title_meta_map();
    foreach (reeid_enabled_plugins_for_seo() as $plug) {
        if (empty($map[$plug])) continue;

        foreach ($map[$plug]['write'] as $k) {
            $curr = trim((string) get_post_meta($post_id, $k, true));
            if (!REEID_SEO_TITLE_OVERWRITE && $curr !== '') continue;
            if ($curr !== $title) update_post_meta($post_id, $k, $title);
        }

        if (!empty($map[$plug]['compound'])) {
            $ckey = $map[$plug]['compound'];
            $compound = get_post_meta($post_id, $ckey, true);
            if (!is_array($compound)) $compound = [];
            if (!REEID_SEO_TITLE_OVERWRITE && !empty($compound['title'])) {
                // keep
            } else {
                if (!isset($compound['title']) || $compound['title'] !== $title) {
                    $compound['title'] = $title;
                    update_post_meta($post_id, $ckey, $compound);
                }
            }
        }
    }
}

/* Legacy helper (token-preserving short text) used by save_post sync */
if (!function_exists('reeid_translate_text_tokens_or_passthru')) {
    function reeid_translate_text_tokens_or_passthru($text, $from, $to, array $args = []) {
        if (!$text || !$from || !$to || strcasecmp($from, $to) === 0) return $text;
        if (function_exists('reeid_translate_preserve_tokens') && function_exists('reeid_focuskw_call_translator')) {
            return reeid_translate_preserve_tokens($text, $from, $to, $args);
        }
        return $text;
    }
}

/* =======================================
 * Sync titles (with optional translation)
 * =======================================*/
if (REEID_SEO_TITLE_SYNC) {
    add_action('save_post', function($post_id, $post, $update){
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!$post instanceof WP_Post) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if ($post->post_status === 'auto-draft') return;

        static $lock = [];
        if (!empty($lock[$post_id])) return;
        $lock[$post_id] = true;

        try {
            $source_id = (int) get_post_meta($post_id, '_reeid_translation_source', true);
            if ($source_id > 0) {
                // pull source title
                $title = reeid_read_canonical_title($source_id);
                if ($title !== '') {
                    // translate to target lang if enabled
                    if (REEID_SEO_TITLE_TRANSLATE) {
                        $src_lang = reeid_post_lang_for_hreflang($source_id);
                        $tgt_lang = reeid_post_lang_for_hreflang($post_id);
                        if ($src_lang && $tgt_lang && strcasecmp($src_lang, $tgt_lang) !== 0) {
                            $title = reeid_translate_text_tokens_or_passthru(
                                $title, $src_lang, $tgt_lang,
                                ['src_id'=>$source_id,'tgt_id'=>$post_id,'meta_key'=>'seo_title','plugin'=>'multi']
                            );
                        }
                    }
                    reeid_write_title_all_plugins($post_id, $title);
                }
            } else {
                // this is the source: push to all targets
                $targets = get_posts([
                    'post_type'        => $post->post_type,
                    'post_status'      => 'any',
                    'posts_per_page'   => -1,
                    'fields'           => 'ids',
                    'suppress_filters' => true,
                    'meta_query'       => [[ 'key' => '_reeid_translation_source', 'value' => (string) $post_id ]],
                ]);
                $source_title = reeid_read_canonical_title($post_id);
                foreach ($targets as $tgt_id) {
                    if (!$source_title) break;
                    $title = $source_title;
                    if (REEID_SEO_TITLE_TRANSLATE) {
                        $src_lang = reeid_post_lang_for_hreflang($post_id);
                        $tgt_lang = reeid_post_lang_for_hreflang($tgt_id);
                        if ($src_lang && $tgt_lang && strcasecmp($src_lang, $tgt_lang) !== 0) {
                            $title = reeid_translate_text_tokens_or_passthru(
                                $source_title, $src_lang, $tgt_lang,
                                ['src_id'=>$post_id,'tgt_id'=>$tgt_id,'meta_key'=>'seo_title','plugin'=>'multi']
                            );
                        }
                    }
                    reeid_write_title_all_plugins((int)$tgt_id, $title);
                }
            }
        } finally {
            unset($lock[$post_id]);
        }
    }, 10050, 3);
}

/* ============================================================
 * Hreflang & language helpers
 * ============================================================ */
if (!function_exists('reeid_normalize_hreflang')) {
    function reeid_normalize_hreflang($code) {
        if (empty($code)) return '';
        $code = str_replace('_','-',$code);
        $parts = explode('-', $code);
        $lang = strtolower($parts[0] ?? '');
        if (!preg_match('/^[a-z]{2}$/',$lang)) return '';
        $out = $lang;
        if (!empty($parts[1])) {
            $region = strtoupper($parts[1]);
            if ($region === 'UK') $region = 'GB';
            if (preg_match('/^[A-Z]{2}$/',$region)) $out .= '-' . $region;
        }
        return $out;
    }
}
if (!function_exists('reeid_post_lang_for_hreflang')) {
    function reeid_post_lang_for_hreflang($post_id) {
        $candidates = [
            get_post_meta($post_id, '_reeid_translation_lang', true),
            get_post_meta($post_id, '_reeid_lang', true),
        ];
        foreach ($candidates as $c) {
            $norm = reeid_normalize_hreflang($c);
            if ($norm) return $norm;
        }
        return reeid_normalize_hreflang(get_locale());
    }
}

/**
 * Build a unified hreflang cluster from the SOURCE map.
 * Same set on every sibling; normalize EN and add x-default.
 */
if (!function_exists('reeid_get_hreflang_pairs')) {
    function reeid_get_hreflang_pairs($post_id) {
        $src_id = (int) get_post_meta($post_id, '_reeid_translation_source', true);
        if (!$src_id) $src_id = $post_id;

        $map = (array) get_post_meta($src_id, '_reeid_translation_map', true);
        $ids = [$src_id];
        foreach ($map as $lang => $id) {
            $id = (int) $id;
            if ($id && get_post_status($id)) $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));

        $pairs = [];
        foreach ($ids as $id) {
            $lang = reeid_post_lang_for_hreflang($id);
            $norm = reeid_normalize_hreflang($lang);
            if (!$norm) continue;
            $url = get_permalink($id);
            if (!$url) continue;
            $pairs[$norm] = $url;
        }

        // Normalize EN: prefer 'en' over 'en-US'
        if (isset($pairs['en-US']) && !isset($pairs['en'])) {
            $pairs['en'] = $pairs['en-US'];
            unset($pairs['en-US']);
        }
        if (!isset($pairs['en'])) {
            $pairs['en'] = get_permalink($src_id);
        }
        $pairs['x-default'] = $pairs['en'];

        ksort($pairs);

        reeid_debug_log('SEO/HREFLANG-PAIRS', ['current'=>$post_id, 'source'=>$src_id, 'pairs'=>$pairs]);

        return $pairs;
    }
}

/* ============================================================
 * Neutralize SEO plugin hreflang & canonical to avoid duplicates
 * ============================================================ */
add_action('init', function() {
    // Disable hreflang from popular SEO plugins (we output our own).
    add_filter('wpseo_hreflang_links', '__return_empty_array', 99);
    add_filter('wpseo_hreflang_xdefault', '__return_false', 99);
    add_filter('rank_math/frontend/hreflang', '__return_false', 99);
    add_filter('rank_math/frontend/hreflang/links', '__return_empty_array', 99);
    add_filter('seopress_hreflang/links', '__return_empty_array', 99);
    add_filter('aioseo_hreflang_links', '__return_empty_array', 99);

    // Force self-canonical through plugin filters.
    $force_self_canonical = function($url = '') {
        if (!is_singular()) return $url;
        $id = get_queried_object_id();
        return $id ? get_permalink($id) : $url;
    };
    add_filter('wpseo_canonical', $force_self_canonical, 99);
    add_filter('rank_math/frontend/canonical', $force_self_canonical, 99);
    add_filter('seopress_titles_canonical', $force_self_canonical, 99, 1);
    add_filter('aioseo_canonical_url', $force_self_canonical, 99, 1);

    // Force indexable robots across plugins.
    add_filter('wpseo_robots', function($robots){
        if (!is_array($robots)) $robots = [];
        $robots['index']  = 'index';
        $robots['follow'] = 'follow';
        return $robots;
    }, 99);
    add_filter('rank_math/frontend/robots', function($robots){
        if (!is_array($robots)) $robots = [];
        $robots['index']  = 'index';
        $robots['follow'] = 'follow';
        return $robots;
    }, 99);
    add_filter('seopress_robots_single', function($str){ return 'index,follow'; }, 99);
    add_filter('aioseo_robots_meta', function($meta){
        if (!is_array($meta)) $meta = [];
        $meta['index']  = true;
        $meta['follow'] = true;
        return $meta;
    }, 99);
}, 1);

/* ============================================================
 * Output cluster + canonical + language metas
 * ============================================================ */
if (REEID_HREFLANG_OUTPUT && !function_exists('reeid_output_hreflang_tags_seosync')) {
    function reeid_output_hreflang_tags_seosync(){
        if ((function_exists("is_product") && is_product()) || is_singular("product")) return;
if (!is_singular()) return;
        $post_id = get_queried_object_id();
        if (!$post_id) return;

        $pairs = reeid_get_hreflang_pairs($post_id);
        if (empty($pairs)) return;

        // Canonical (self) — print late so we override theme tags.
        $self_url = get_permalink($post_id);
        echo '<link rel="canonical" href="' . esc_url($self_url) . "\" />\n";

        // Hreflang cluster
        foreach ($pairs as $lang => $url) {
            echo '<link rel="alternate" hreflang="' . esc_attr($lang) . '" href="' . esc_url($url) . "\" />\n";
        }

        // Language hints
        $current_lang = reeid_post_lang_for_hreflang($post_id);
        if ($current_lang) {
            echo '<meta http-equiv="Content-Language" content="' . esc_attr($current_lang) . "\" />\n";
            echo '<meta name="language" content="' . esc_attr($current_lang) . "\" />\n";
            echo '<meta property="og:locale" content="' . esc_attr(str_replace('-', '_', $current_lang)) . "\" />\n";
        }
        if (isset($pairs['en'])) {
            echo '<meta property="og:locale:alternate" content="en_US" />' . "\n";
        }

        // Final safety: ensure robots is indexable in the final head.
        echo '<meta name="robots" content="index,follow" />' . "\n";
    }
    // Priority 99 so we print after most themes/plugins
    add_action('wp_head', 'reeid_output_hreflang_tags_seosync', 99);

    // <html lang> + dir for RTL
    add_filter('language_attributes', function($output) {
        if (is_singular() && !is_singular("product")) {//* Woo handled by bridge */ 
            $post_id = get_queried_object_id();
            if ($post_id) {
                $lang = reeid_post_lang_for_hreflang($post_id);
                if ($lang) {
                    $output = preg_replace('/lang="[^"]*"/', '', $output);
                    $rtl = in_array(substr($lang,0,2), ['ar','he','fa','ur'], true) ? ' dir="rtl"' : '';
                    $output .= ' lang="' . esc_attr($lang) . '"' . $rtl;
                }
            }
        }
        return $output;
    }, 20);

    // Optional: wrap content in a language container to strengthen detection (OFF by default).
    if (REEID_WRAP_CONTENT_LANG) {
        add_filter('the_content', function($content) {
            if (!is_singular()) return $content;
            $post_id = get_queried_object_id();
            if (!$post_id) return $content;
            $lang = reeid_post_lang_for_hreflang($post_id);
            if (!$lang) return $content;
            // Minimal wrapper to avoid layout breakage.
            return '<div lang="' . esc_attr($lang) . '">' . $content . '</div>';
        }, 9999);
    }
}

/* ============================================================
 * One-shot cluster sync (source -> all siblings) with translation
 * ============================================================ */
if (!function_exists('reeid_sync_seo_title_cluster')) {
    function reeid_sync_seo_title_cluster($src_id) {
        if (!$src_id || !get_post_status($src_id)) return;

        $src_title = reeid_read_canonical_title($src_id);
        if ($src_title === '') $src_title = get_the_title($src_id);

        $src_lang = function_exists('reeid_post_lang_for_hreflang') ? reeid_post_lang_for_hreflang($src_id) : 'en';
        if (!$src_lang) $src_lang = 'en';

        $map = (array) get_post_meta($src_id, '_reeid_translation_map', true);
        $map['en'] = isset($map['en']) ? (int) $map['en'] : (int) $src_id; // normalize ensure EN present

        foreach ($map as $lang => $pid) {
            $pid = (int) $pid;
            if (!$pid || !get_post_status($pid)) continue;

            $tgt_lang = function_exists('reeid_post_lang_for_hreflang') ? reeid_post_lang_for_hreflang($pid) : $lang;
            $title    = $src_title;

            if (defined('REEID_SEO_TITLE_TRANSLATE') && REEID_SEO_TITLE_TRANSLATE && $tgt_lang && $src_lang && strcasecmp($src_lang, $tgt_lang) !== 0) {
                $title = reeid_translate_short_text($src_title, $src_lang, $tgt_lang, 'Neutral');
            }

            if (function_exists('reeid_write_title_all_plugins')) {
                reeid_write_title_all_plugins($pid, $title);
            }
        }
    }
}


// Use the stored SEO title for the <title> tag on singular views.
add_filter('pre_get_document_title', function($title) {
    if (!is_singular()) return $title;
    $id = get_queried_object_id();
    if (!$id) return $title;

    if (function_exists('reeid_read_canonical_title')) {
        $seo_title = trim((string) reeid_read_canonical_title($id));
        if ($seo_title !== '') {
            return wp_strip_all_tags($seo_title, true);
        }
    }
    return $title;
}, 99);


/* ============================================================
 * SEO DESCRIPTION HELPERS (read/write + normalization)
 * - Works with Rank Math, Yoast, SEOPress, AIOSEO (v4 array)
 * - Mirrors the title helpers you already have
 * ============================================================ */

if (!defined('REEID_SEO_DESC_SYNC'))       define('REEID_SEO_DESC_SYNC', true);
if (!defined('REEID_SEO_DESC_OVERWRITE'))  define('REEID_SEO_DESC_OVERWRITE', true);

/** Sanitize/normalize a meta description to a tidy single line (soft limit). */
if (!function_exists('reeid_sanitize_seo_description')) {
    function reeid_sanitize_seo_description($text, $soft_limit = 300) {
        $text = is_string($text) ? wp_strip_all_tags($text, true) : '';
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if ($soft_limit > 0 && strlen($text) > $soft_limit) {
            // Soft truncate on word boundary
            $cut = substr($text, 0, $soft_limit);
            $space = strrpos($cut, ' ');
            if ($space !== false) $cut = substr($cut, 0, $space);
            $text = rtrim($cut, ".,;:!?\xC2\xA0") . '…';
        }
        return $text;
    }
}

/** Which plugins to target (reuses your title helpers if present). */
if (!function_exists('reeid_enabled_plugins_for_seo_desc')) {
    function reeid_enabled_plugins_for_seo_desc() {
        if (function_exists('reeid_enabled_plugins_for_seo')) {
            return reeid_enabled_plugins_for_seo();
        }
        $def = ['rankmath','yoast','seopress','aioseo'];
        $raw = defined('REEID_SEO_PLUGINS') ? @unserialize(REEID_SEO_PLUGINS) : $def;
        return is_array($raw) && $raw ? array_values(array_intersect($raw, $def)) : $def;
    }
}

/** Preferred read priority (reuses your title priority if present). */
if (!function_exists('reeid_seo_desc_read_priority')) {
    function reeid_seo_desc_read_priority() {
        if (function_exists('reeid_seo_read_priority')) {
            return reeid_seo_read_priority();
        }
        $def = ['rankmath','yoast','seopress','aioseo'];
        $raw = defined('REEID_SEO_PRIORITY') ? @unserialize(REEID_SEO_PRIORITY) : $def;
        return is_array($raw) && $raw ? array_values(array_intersect($raw, $def)) : $def;
    }
}

/** Map of meta keys for description across plugins. */
if (!function_exists('reeid_description_meta_map')) {
    function reeid_description_meta_map() {
        return [
            'rankmath' => [
                'read'     => ['rank_math_description'],
                'write'    => ['rank_math_description'],
                'compound' => null, // none
            ],
            'yoast' => [
                'read'     => ['_yoast_wpseo_metadesc'],
                'write'    => ['_yoast_wpseo_metadesc'],
                'compound' => null, // none
            ],
            'seopress' => [
                'read'     => ['_seopress_titles_desc'],
                'write'    => ['_seopress_titles_desc'],
                'compound' => null, // none
            ],
            'aioseo' => [
                // Some installs also mirror a plain meta; we will write both.
                'read'     => ['_aioseo_description', '_aioseo'], // _aioseo array fallback
                'write'    => ['_aioseo_description'],            // plus compound['description']
                'compound' => '_aioseo',                           // array with ['description']
            ],
        ];
    }
}

/** Read the canonical/most-authoritative description for a post. */
if (!function_exists('reeid_read_canonical_description')) {
    function reeid_read_canonical_description($post_id) {
        $map = reeid_description_meta_map();

        foreach (reeid_seo_desc_read_priority() as $plug) {
            if (empty($map[$plug])) continue;

            foreach ($map[$plug]['read'] as $key) {
                $val = get_post_meta($post_id, $key, true);

                if ($plug === 'aioseo' && $key === '_aioseo') {
                    if (is_array($val) && !empty($val['description'])) {
                        $desc = trim((string) $val['description']);
                        if ($desc !== '') return reeid_sanitize_seo_description($desc);
                    }
                    continue;
                }

                $desc = trim((string) $val);
                if ($desc !== '') {
                    return reeid_sanitize_seo_description($desc);
                }
            }
        }

        // Fallback: try excerpt/content
        $fallback = get_the_excerpt($post_id);
        if ($fallback === '' || $fallback === null) {
            $fallback = get_post_field('post_content', $post_id);
        }
        return reeid_sanitize_seo_description($fallback);
    }
}

/** Write a description to all enabled SEO plugins (with overwrite control). */
if (!function_exists('reeid_write_description_all_plugins')) {
    function reeid_write_description_all_plugins($post_id, $description) {
        $desc = reeid_sanitize_seo_description($description);
        if ($desc === '') return;

        $map = reeid_description_meta_map();

        foreach (reeid_enabled_plugins_for_seo_desc() as $plug) {
            if (empty($map[$plug])) continue;

            // Write simple meta keys first.
            foreach ($map[$plug]['write'] as $k) {
                $curr = trim((string) get_post_meta($post_id, $k, true));
                if (!REEID_SEO_DESC_OVERWRITE && $curr !== '') continue;
                if ($curr !== $desc) {
                    update_post_meta($post_id, $k, $desc);
                }
            }

            // Then handle compound arrays (AIOSEO v4).
            if (!empty($map[$plug]['compound'])) {
                $ckey = $map[$plug]['compound'];
                $compound = get_post_meta($post_id, $ckey, true);
                if (!is_array($compound)) $compound = [];

                if (!REEID_SEO_DESC_OVERWRITE && !empty($compound['description'])) {
                    // keep existing
                } else {
                    if (!isset($compound['description']) || $compound['description'] !== $desc) {
                        $compound['description'] = $desc;
                        update_post_meta($post_id, $ckey, $compound);
                    }
                }
            }
        }
    }
}

// Back-compat shim for old callers:
    if (!function_exists('reeid_desc_meta_map') && function_exists('reeid_description_meta_map')) {
        function reeid_desc_meta_map() { return reeid_description_meta_map(); }
    }
    

/* ============================================================
 * Watch translation linkage/meta and resync when they change
 * ============================================================ */
if (!function_exists('reeid_watch_translation_links_for_seo')) {
    function reeid_watch_translation_links_for_seo($meta_id, $post_id, $meta_key, $val) {
        if (!in_array($meta_key, ['_reeid_translation_source','_reeid_translation_map'], true)) return;

        // Resolve source id
        $src = (int) get_post_meta($post_id, '_reeid_translation_source', true);
        if (!$src) $src = (int) $post_id;

        if ($src && get_post_status($src)) {
            reeid_sync_seo_title_cluster($src);
        }
    }
}
add_action('added_post_meta',   'reeid_watch_translation_links_for_seo', 10, 4);
add_action('updated_post_meta', 'reeid_watch_translation_links_for_seo', 10, 4);
/* === REEID: add self-referencing canonical for singulars (safe append) === */
if (!function_exists('reeid_emit_canonical_self')) {
  add_action('wp_head','reeid_emit_canonical_self',98);
  function reeid_emit_canonical_self(){
    if (defined("WPSEO_VERSION") || defined("RANK_MATH_VERSION") || has_action("wp_head","rel_canonical")) { return; }

    if (!is_singular()) return;
    $url = get_permalink();
    if ($url) echo '<link rel="canonical" href="'.esc_url($url).'" />'."\n";
  }
}

# === REEID: disable plugin canonical if any other emitter exists ===
add_action('plugins_loaded', function () {
  if (function_exists('reeid_emit_canonical_self')) {
    remove_action('wp_head','reeid_emit_canonical_self',98);
  }
}, 11);

# === REEID: universal unhook to prevent duplicate canonicals ===
add_action('init', function () {
  if (function_exists('remove_action')) {
    remove_action('wp_head', 'reeid_emit_canonical_self', 98);
  }
}, 0);

# === REEID: prefer SEO plugin canonical; disable core rel_canonical ===
add_action('plugins_loaded', function () {
  if (function_exists('rel_canonical')) {
    remove_action('wp_head', 'rel_canonical');
  }
}, 20);
# REEID: canonical de-dup (front-end singulars) — keep first, drop rest
add_action('template_redirect', function () {
  if (!is_singular()) return;
  ob_start(function($html){
    $seen = false;
    return preg_replace_callback('/<link[^>]+rel=["\']canonical["\'][^>]*>\s*/i',
      function($m) use (&$seen){ if ($seen) return ''; $seen = true; return $m[0]; },
      $html, -1);
  });
}, 0);
/* REEID: ensure hreflang href uses percent-encoded path (avoid 301 on click) */
if (!function_exists('reeid_encode_url_path')) {
  function reeid_encode_url_path($url) {
    if (!is_string($url) || $url==='') return $url;
    $p = parse_url($url);
    if (!$p || empty($p['path'])) return $url;
    $segs = explode('/', $p['path']);
    foreach ($segs as $i=>$s) {
      if ($s === '' || preg_match('/^[\x00-\x7F]+$/', $s)) continue;
      $segs[$i] = rawurlencode($s);
    }
    $p['path'] = implode('/', $segs);
    $out = (isset($p['scheme'])?$p['scheme'].'://':'')
         . (isset($p['host'])?$p['host']:'')
         . (isset($p['port'])?':'.$p['port']:'')
         . (isset($p['path'])?$p['path']:'')
         . (isset($p['query'])?('?'.$p['query']):'')
         . (isset($p['fragment'])?('#'.$p['fragment']):'');
    return $out ?: $url;
  }
  add_action('wp_head', function(){
    if (!function_exists('reeid_hreflang_emit_minimal')) return; // only adjust our emitter
    // Monkey-patch output buffer to encode any hreflang href just before send
    ob_start(function($html){
      // If the bridge asked us to stand down, do nothing.
if (!empty($GLOBALS['reeid_disable_hreflang_ob'])) return $html;
      return preg_replace_callback(
        '/(<link[^>]+rel=["\']alternate["\'][^>]*hreflang=["\'][^"\']+["\'][^>]*href=["\'])([^"\']+)(["\'][^>]*>)/i',
        function($m){ return $m[1] . esc_url(reeid_encode_url_path(html_entity_decode($m[2], ENT_QUOTES))) . $m[3]; },
      $html);
    });
  }, 98);
}
/* REEID: encode hreflang href paths to avoid redirect hops */
add_action('wp_head', function () {
  ob_start(function ($html) {
    return preg_replace_callback(
      '/(<link[^>]+rel=["\']alternate["\'][^>]*hreflang=["\'][^"\']+["\'][^>]*href=["\'])([^"\']+)(["\'][^>]*>)/i',
      function ($m) {
        $u = html_entity_decode($m[2], ENT_QUOTES);
        $p = @parse_url($u);
        if (!$p || empty($p['path'])) return $m[0];
        $segs = explode('/', $p['path']);
        foreach ($segs as $i => $s) {
          if ($s === '' || preg_match('/^[\x00-\x7F]+$/', $s)) continue;
          $segs[$i] = rawurlencode($s);
        }
        $p['path'] = implode('/', $segs);
        $new = (isset($p['scheme'])?$p['scheme'].'://':'')
             . (isset($p['host'])?$p['host']:'')
             . (isset($p['port'])?':'.$p['port']:'')
             . ($p['path'] ?? '')
             . (isset($p['query'])?('?'.$p['query']):'')
             . (isset($p['fragment'])?('#'.$p['fragment']):'');
        return $m[1] . $new . $m[3];
      },
      $html
    );
  });
}, 9999);
# REEID: early buffer to percent-encode <link rel="alternate" hreflang ... href="...">
add_action('wp_head', function () {
  // Start early so our encoder sees tags printed by later callbacks.
  ob_start(function ($html) {
    return preg_replace_callback(
      '/(<link[^>]+rel=["\']alternate["\'][^>]*hreflang=["\'][^"\']+["\'][^>]*href=["\'])([^"\']+)(["\'][^>]*>)/i',
      function ($m) {
        $u = html_entity_decode($m[2], ENT_QUOTES);
        $p = @parse_url($u);
        if (!$p || empty($p['path'])) return $m[0];
        $segs = explode('/', $p['path']);
        foreach ($segs as $i => $s) {
          if ($s === '' || preg_match('/^[\x00-\x7F]+$/', $s)) continue;
          $segs[$i] = rawurlencode($s);
        }
        $p['path'] = implode('/', $segs);
        $new = (isset($p['scheme'])?$p['scheme'].'://':'')
             . (isset($p['host'])?$p['host']:'')
             . (isset($p['port'])?':'.$p['port']:'')
             . ($p['path'] ?? '')
             . (isset($p['query'])?('?'.$p['query']):'')
             . (isset($p['fragment'])?('#'.$p['fragment']):'');
        return $m[1] . $new . $m[3];
      },
      $html
    );
  });
}, 1);
# REEID: remove duplicate meta robots (keep first)
add_action('template_redirect', function () {
  if (!is_singular()) return;
  ob_start(function($html){
    $seen=false;
    return preg_replace_callback('/<meta[^>]+name=["\']robots["\'][^>]*>\s*/i',
      function($m) use (&$seen){ if($seen) return ''; $seen=true; return $m[0]; },
    $html, -1);
  }, 0);
}, 0);
# REEID: Content-Language header (self-contained)
if (!function_exists('reeid_send_content_language')) {
  add_action('template_redirect','reeid_send_content_language',11);
  function reeid_send_content_language(){
    if (is_admin() || !is_singular() || headers_sent()) return;
    $id = get_queried_object_id(); if (!$id) return;
    $lang = get_post_meta($id,'_reeid_translation_lang',true); if (!$lang) $lang='en';
    @header('Content-Language: '.$lang, true);
  }
}
/* REEID: hreflang for virtual Woo product translations (no physical child) */
if (!function_exists('reeid_hreflang_products_virtual')) {
  add_action('wp_head','reeid_hreflang_products_virtual', 100);
  function reeid_hreflang_products_virtual(){
    if (!is_singular('product')) return;
    global $post, $wp;
    if (!$post) return;

    // If our generic emitter already printed hreflang, bail.
    if (did_action('wp_head') && strpos(ob_get_level()? ob_get_contents() : '', 'hreflang=')!==false) { return; }

    // Infer current lang from URL: /{lang}/product/...
    $req_path = isset($wp->request) ? '/'.ltrim($wp->request,'/') : parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (!preg_match('#^/([a-z]{2}(?:-[A-Za-z]{2})?)/product/#', $req_path, $m)) return;
    $cur_lang = strtolower($m[1]);

    // Source is the base product (assume EN if none)
    $src_id = (int) get_post_meta($post->ID, '_reeid_translation_source', true);
    if ($src_id <= 0) $src_id = (int) $post->ID;
    $src_url = get_permalink($src_id);

    // Build minimal pair set (include self + en + x-default)
    $self_url = ( (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $req_path );
    if (substr($self_url, -1) !== '/') $self_url .= '/';
    $pairs = [
      $cur_lang   => $self_url,
      'en'        => $src_url,
      'x-default' => $src_url,
    ];

    // If we DO have physical children, add them
    $kids = get_posts([
      'post_type'=>'product','numberposts'=>20,'post_status'=>'publish',
      'meta_query'=>[['key'=>'_reeid_translation_source','value'=>strval($src_id),'compare'=>'=']]
    ]);
    foreach($kids as $p){
      $lg = get_post_meta($p->ID,'_reeid_translation_lang',true);
      if ($lg && empty($pairs[$lg])) $pairs[$lg] = get_permalink($p->ID);
    }

    foreach ($pairs as $code=>$url) {
      echo '<link rel="alternate" hreflang="'.esc_attr($code).'" href="'.esc_url($url).'" />'."\n";
    }
  }
}
/* === REEID: WooCommerce product hreflang (handles virtual translations) === */
if (!function_exists('reeid_wc_hreflang')) {
  add_action('wp_head','reeid_wc_hreflang', 96);
  function reeid_wc_hreflang(){
    if (!(function_exists('is_product') && is_product())) return;
    $id = get_queried_object_id(); if (!$id) return;

    // Resolve source group
    $src = (int)get_post_meta($id,'_reeid_translation_source',true);
    if ($src <= 0) $src = $id;

    // Build pairs from children (if any)
    $pairs = [];
    $kids = get_posts([
      'post_type'=>'product','post_status'=>'publish','posts_per_page'=>100,
      'meta_query'=>[['key'=>'_reeid_translation_source','value'=>strval($src),'compare'=>'=']]
    ]);
    foreach($kids as $p){
      $lg = get_post_meta($p->ID,'_reeid_translation_lang',true);
      $url = get_permalink($p->ID);
      if ($lg && $url) $pairs[$lg] = $url;
    }

    // Current lang from URL prefix /{lang}/product/...
    global $wp;
    $req_path = isset($wp->request) ? '/'.ltrim($wp->request,'/') : parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($req_path && preg_match('#^/([a-z]{2}(?:-[A-Za-z]{2})?)/product/#',$req_path,$m)) {
      $cur_lang = strtolower($m[1]);
      $self_url = ( (is_ssl()?'https://':'http://') . $_SERVER['HTTP_HOST'] . $req_path );
      if (substr($self_url,-1) !== '/') $self_url .= '/';
      $pairs[$cur_lang] = $self_url;
    }

    // Always include EN/x-default as the source permalink
    $src_url = get_permalink($src);
    if ($src_url) {
      if (empty($pairs['en'])) $pairs['en'] = $src_url;
      $pairs['x-default'] = $src_url;
    }

    if (!$pairs) return;
    foreach($pairs as $code=>$url){
      echo '<link rel="alternate" hreflang="'.esc_attr($code).'" href="'.esc_url($url).'" />'."\n";
    }
  }
}
/* === REEID: force hreflang on Woo single product URLs (virtual translations too) === */
if (!function_exists('reeid_wc_hreflang_ob')) {
  add_action('template_redirect', function () {
    // Detect /product/ page (with or without lang prefix)
    $req = isset($GLOBALS['wp']->request) ? '/'.ltrim($GLOBALS['wp']->request,'/') : parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (!$req || !preg_match('#^/(?:([a-z]{2}(?:-[A-Za-z]{2})?)/)?product/([^/]+)/?#', $req, $m)) return;

    ob_start(function($html) use ($m, $req) {
      // If hreflang already present, leave as is
      if (preg_match('/<link[^>]+rel=["\']alternate["\'][^>]*hreflang=/i', $html)) return $html;

      $cur_lang = isset($m[1]) && $m[1] !== '' ? strtolower($m[1]) : 'en';
      $slug     = $m[2];

      // Resolve source EN product by stripping lang prefix from path
      $host = (is_ssl() ? 'https://' : 'http://').$_SERVER['HTTP_HOST'];
      $en_path = preg_match('#^/[a-z]{2}(?:-[A-Za-z]{2})?/(product/.+)$#', $req, $mm) ? '/'.$mm[1] : $req;
      $src_id = reeid_url_to_postid_prefixed($host.$en_path); if (!$src_id) { $po = get_page_by_path($slug, OBJECT, 'product'); $src_id = $po ? $po->ID : 0; }
      if (!$src_id) return $html;
      $src_url = get_permalink($src_id);
      if (!$src_url) return $html;

      // Current URL (ensure trailing slash)
      $self_url = $host . (rtrim($req,'/').'/');

      // Gather any physical children if they exist
      $pairs = [];
      $kids = get_posts([
        'post_type'=>'product','post_status'=>'publish','posts_per_page'=>50,
        'meta_query'=>[['key'=>'_reeid_translation_source','value'=>strval($src_id),'compare'=>'=']]
      ]);
      foreach($kids as $p){
        $lg = get_post_meta($p->ID,'_reeid_translation_lang',true);
        $url= get_permalink($p->ID);
        if ($lg && $url) $pairs[$lg]=$url;
      }

      // Always include self + EN + x-default
      $pairs[$cur_lang] = $self_url;
      $pairs['en'] = $src_url;
      $pairs['x-default'] = $src_url;

      // Percent-encode non-ASCII segments
      $encode = function($u){
        $p=@parse_url($u); if(!$p||empty($p['path'])) return $u;
        $segs=explode('/',$p['path']);
        foreach($segs as $i=>$s){ if($s!=='' && preg_match('/[^\x00-\x7F]/u',$s)) $segs[$i]=rawurlencode($s); }
        $p['path']=implode('/',$segs);
        return (isset($p['scheme'])?$p['scheme'].'://':'').($p['host']??'').(isset($p['port'])?':'.$p['port']:'').($p['path']??'').(isset($p['query'])?('?'.$p['query']):'').(isset($p['fragment'])?('#'.$p['fragment']):'');
      };

      $tags = "";
      foreach ($pairs as $code=>$url){
        $tags .= '<link rel="alternate" hreflang="'.esc_attr($code).'" href="'.esc_url($encode($url)).'" />'."\n";
      }

      // Inject before </head>
      if (preg_match('/<\/head>/i', $html)) {
        $html = preg_replace('/<\/head>/i', $tags.'</head>', $html, 1);
      } else {
        $html = $tags.$html;
      }
      return $html;
    });
  }, 0);
}
# REEID: FORCE hreflang for any /product/ single (works even if Woo/templating bypasses is_product)
add_action('wp_head', function () {
  $req = isset($GLOBALS['wp']->request) ? '/'.ltrim($GLOBALS['wp']->request,'/') : parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
  if (!$req || !preg_match('#^/(?:([a-z]{2}(?:-[A-Za-z]{2})?)/)?product/([^/]+)/?#', $req, $m)) return;

  $cur_lang = isset($m[1]) && $m[1] !== '' ? strtolower($m[1]) : 'en';
  $slug     = $m[2];

  // Source URL (EN or base product)
  $host = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
  $en_path = preg_match('#^/[a-z]{2}(?:-[A-Za-z]{2})?/(product/.+)$#', $req, $mm) ? '/'.$mm[1] : $req;
  $src_id = reeid_url_to_postid_prefixed($host.$en_path);
  if (!$src_id) { $po = get_page_by_path($slug, OBJECT, 'product'); $src_id = $po ? $po->ID : 0; }
  $src_url = $src_id ? get_permalink($src_id) : ($host.$en_path);

  // Self URL
  $self_url = $host . (rtrim($req,'/').'/');

  // Encode non-ASCII path segments
  $encode = function($u){
    $p=@parse_url($u); if(!$p||empty($p['path'])) return $u;
    $segs=explode('/',$p['path']);
    foreach($segs as $i=>$s){ if($s!=='' && preg_match('/[^\x00-\x7F]/u',$s)) $segs[$i]=rawurlencode($s); }
    $p['path']=implode('/',$segs);
    return (isset($p['scheme'])?$p['scheme'].'://':'').($p['host']??'').(isset($p['port'])?':'.$p['port']:'').($p['path']??'').(isset($p['query'])?('?'.$p['query']):'').(isset($p['fragment'])?('#'.$p['fragment']):'');
  };

  // Emit minimal set (+any physical children if present)
  $pairs = [ $cur_lang => $self_url ];
  if ($src_url) { $pairs['en'] = $src_url; $pairs['x-default'] = $src_url; }

  $kids = get_posts([
    'post_type'=>'product','post_status'=>'publish','posts_per_page'=>50,
    'meta_query'=>[['key'=>'_reeid_translation_source','value'=>strval($src_id),'compare'=>'=']]
  ]);
  foreach($kids as $p){
    $lg = get_post_meta($p->ID,'_reeid_translation_lang',true);
    $url= get_permalink($p->ID);
    if ($lg && $url) $pairs[$lg]=$url;
  }

  // Marker for debugging
  echo "<!-- REEID-WC-HREFLANG -->\n";
  foreach ($pairs as $code=>$url){
    echo '<link rel="alternate" hreflang="'.esc_attr($code).'" href="'.esc_url($encode($url)).'" />'."\n";
  }
}, 3);
/* REEID: debug injector for Woo product hreflang (enable via ?rt_wc_hreflang=1) */
add_action('template_redirect', function () {
  if (empty($_GET['rt_wc_hreflang'])) return;
  $req = isset($GLOBALS['wp']->request) ? '/'.ltrim($GLOBALS['wp']->request,'/') : parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
  if (!$req || !preg_match('#^/(?:([a-z]{2}(?:-[A-Za-z]{2})?)/)?product/([^/]+)/?#', $req, $m)) return;

  ob_start(function($html) use ($m,$req){
    // bail if already present
    if (preg_match('/<link[^>]+rel=["\']alternate["\'][^>]*hreflang=/i', $html)) return $html;

    $cur_lang = isset($m[1]) && $m[1] !== '' ? strtolower($m[1]) : 'en';
    $slug     = $m[2];
    $host = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
    $en_path = preg_match('#^/[a-z]{2}(?:-[A-Za-z]{2})?/(product/.+)$#', $req, $mm) ? '/'.$mm[1] : $req;
    $src_id = reeid_url_to_postid_prefixed($host.$en_path); if(!$src_id){ $po=get_page_by_path($slug,OBJECT,'product'); $src_id=$po?$po->ID:0; }
    $src_url = $src_id ? get_permalink($src_id) : ($host.$en_path);
    $self_url = $host.(rtrim($req,'/').'/');

    $pairs = [ $cur_lang=>$self_url ];
    if ($src_url){ $pairs['en']=$src_url; $pairs['x-default']=$src_url; }

    $kids = get_posts(['post_type'=>'product','post_status'=>'publish','posts_per_page'=>50,'meta_query'=>[
      ['key'=>'_reeid_translation_source','value'=>strval($src_id),'compare'=>'=']
    ]]);
    foreach($kids as $p){ $lg=get_post_meta($p->ID,'_reeid_translation_lang',true); $url=get_permalink($p->ID); if($lg && $url) $pairs[$lg]=$url; }

    $encode=function($u){ $p=@parse_url($u); if(!$p||empty($p['path'])) return $u; $segs=explode('/',$p['path']); foreach($segs as $i=>$s){ if($s!=='' && preg_match('/[^\x00-\x7F]/u',$s)) $segs[$i]=rawurlencode($s);} $p['path']=implode('/',$segs);
      return (isset($p['scheme'])?$p['scheme'].'://':'').($p['host']??'').(isset($p['port'])?':'.$p['port']:'').($p['path']??'').(isset($p['query'])?('?'.$p['query']):'').(isset($p['fragment'])?('#'.$p['fragment']):''); };

    $tags="<!-- REEID-WC-HREFLANG -->\n";
    foreach($pairs as $code=>$url){ $tags .= '<link rel="alternate" hreflang="'.esc_attr($code).'" href="'.esc_url($encode($url)).'" />'."\n"; }

    return preg_match('/<\/head>/i',$html) ? preg_replace('/<\/head>/i',$tags.'</head>',$html,1) : ($tags.$html);
  });

  // help bypass caches in debug
  if (!headers_sent()) header('Cache-Control: no-cache, max-age=0', true);
}, 0);
/* REEID: debug injector for Woo product hreflang (enable via ?rt_wc_hreflang=1) */
add_action('template_redirect', function () {
  if (empty($_GET['rt_wc_hreflang'])) return;
  $req = isset($GLOBALS['wp']->request) ? '/'.ltrim($GLOBALS['wp']->request,'/') : parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
  if (!$req || !preg_match('#^/(?:([a-z]{2}(?:-[A-Za-z]{2})?)/)?product/([^/]+)/?#', $req, $m)) return;

  ob_start(function($html) use ($m,$req){
    // bail if already present
    if (preg_match('/<link[^>]+rel=["\']alternate["\'][^>]*hreflang=/i', $html)) return $html;

    $cur_lang = isset($m[1]) && $m[1] !== '' ? strtolower($m[1]) : 'en';
    $slug     = $m[2];
    $host = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
    $en_path = preg_match('#^/[a-z]{2}(?:-[A-Za-z]{2})?/(product/.+)$#', $req, $mm) ? '/'.$mm[1] : $req;
    $src_id = reeid_url_to_postid_prefixed($host.$en_path); if(!$src_id){ $po=get_page_by_path($slug,OBJECT,'product'); $src_id=$po?$po->ID:0; }
    $src_url = $src_id ? get_permalink($src_id) : ($host.$en_path);
    $self_url = $host.(rtrim($req,'/').'/');

    $pairs = [ $cur_lang=>$self_url ];
    if ($src_url){ $pairs['en']=$src_url; $pairs['x-default']=$src_url; }

    $kids = get_posts(['post_type'=>'product','post_status'=>'publish','posts_per_page'=>50,'meta_query'=>[
      ['key'=>'_reeid_translation_source','value'=>strval($src_id),'compare'=>'=']
    ]]);
    foreach($kids as $p){ $lg=get_post_meta($p->ID,'_reeid_translation_lang',true); $url=get_permalink($p->ID); if($lg && $url) $pairs[$lg]=$url; }

    $encode=function($u){ $p=@parse_url($u); if(!$p||empty($p['path'])) return $u; $segs=explode('/',$p['path']); foreach($segs as $i=>$s){ if($s!=='' && preg_match('/[^\x00-\x7F]/u',$s)) $segs[$i]=rawurlencode($s);} $p['path']=implode('/',$segs);
      return (isset($p['scheme'])?$p['scheme'].'://':'').($p['host']??'').(isset($p['port'])?':'.$p['port']:'').($p['path']??'').(isset($p['query'])?('?'.$p['query']):'').(isset($p['fragment'])?('#'.$p['fragment']):''); };

    $tags="<!-- REEID-WC-HREFLANG -->\n";
    foreach($pairs as $code=>$url){ $tags .= '<link rel="alternate" hreflang="'.esc_attr($code).'" href="'.esc_url($encode($url)).'" />'."\n"; }

    return preg_match('/<\/head>/i',$html) ? preg_replace('/<\/head>/i',$tags.'</head>',$html,1) : ($tags.$html);
  });

  // help bypass caches in debug
  if (!headers_sent()) header('Cache-Control: no-cache, max-age=0', true);
}, 0);

/* === REEID: Woo product hreflang (native + virtual) === */
if (!function_exists('reeid_wc_hreflang_simple')) {
  add_action('wp_head','reeid_wc_hreflang_simple', 90);
  function reeid_wc_hreflang_simple(){
    // already present? bail
    if (did_action('wp_head')) {
      // we can't read buffer; quick DOM probe via wp_query not possible; rely on flag from our emitter
      if (defined('REEID_HREFLANG_PRINTED') && REEID_HREFLANG_PRINTED) return;
    }
    // detect product request (with or w/o lang prefix)
    $req = isset($GLOBALS['wp']->request) ? '/'.ltrim($GLOBALS['wp']->request,'/') : parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (!$req || !preg_match('#^/(?:([a-z]{2}(?:-[A-Za-z]{2})?)/)?product/([^/]+)/?#', $req, $m)) return;

    $cur_lang = isset($m[1]) && $m[1]!=='' ? strtolower($m[1]) : 'en';
    $slug     = $m[2];
    $host     = (is_ssl()?'https://':'http://').$_SERVER['HTTP_HOST'];
    $en_path  = preg_match('#^/[a-z]{2}(?:-[A-Za-z]{2})?/(product/.+)$#',$req,$mm)?('/'.$mm[1]):$req;

    // resolve source/base product
    $src_id = reeid_url_to_postid_prefixed($host.$en_path);
    if(!$src_id){ $po=get_page_by_path($slug, OBJECT, 'product'); $src_id=$po?$po->ID:0; }
    $src_url = $src_id ? get_permalink($src_id) : ($host.$en_path);

    $self_url = $host.(rtrim($req,'/').'/');

    // collect children if exist
    $pairs = [];
    if ($src_id){
      $kids = get_posts(['post_type'=>'product','post_status'=>'publish','posts_per_page'=>100,'meta_query'=>[
        ['key'=>'_reeid_translation_source','value'=>strval($src_id),'compare'=>'=']
      ]]);
      foreach($kids as $p){ $lg=get_post_meta($p->ID,'_reeid_translation_lang',true); $url=get_permalink($p->ID); if($lg && $url) $pairs[$lg]=$url; }
    }
    // minimal set
    $pairs[$cur_lang]=$self_url;
    if ($src_url){ $pairs['en']=$src_url; $pairs['x-default']=$src_url; }

    // encode non-ASCII path segments
    if (!function_exists('reeid_encode_url_path')) {
      function reeid_encode_url_path($u){
        $p=@parse_url($u); if(!$p||empty($p['path'])) return $u;
        $segs=explode('/',$p['path']);
        foreach($segs as $i=>$s){ if($s!=='' && preg_match('/[^\x00-\x7F]/u',$s)) $segs[$i]=rawurlencode($s); }
        $p['path']=implode('/',$segs);
        return (isset($p['scheme'])?$p['scheme'].'://':'').($p['host']??'').(isset($p['port'])?':'.$p['port']:'').($p['path']??'').(isset($p['query'])?('?'.$p['query']):'').(isset($p['fragment'])?('#'.$p['fragment']):'');
      }
    }

    define('REEID_HREFLANG_PRINTED', true);
    foreach($pairs as $code=>$url){
      echo '<link rel="alternate" hreflang="'.esc_attr($code).'" href="'.esc_url(reeid_encode_url_path($url)).'" />'."\n";
    }
  }
}
add_action('wp_head', function(){ echo "<!-- RT: wp_head alive -->\n"; }, 1);





/* === REEID: WooCommerce product hreflang emitter (self-contained) === */
add_action('wp_head', function () {
    if (is_admin()) return;
    if (!function_exists('is_product') || !is_product()) return;

    // If another plugin already prints hreflang, skip to avoid duplicates.
    // (Quick heuristic: bail if <link rel="alternate" hreflang> already buffered by theme/plugin.)
    // We can't inspect the buffer reliably here, so keep this simple & opinionated:
    // Rank Math/Yoast typically won't emit hreflang for custom meta setups, so we proceed.

    global $post;
    if (empty($post) || empty($post->ID)) return;

    $cur_id  = (int) $post->ID;
    $cur_lng = get_post_meta($cur_id, '_reeid_translation_lang', true);
    if (!$cur_lng) $cur_lng = 'en';

    // Resolve the source (canonical) product id; default to current if not linked
    $src_meta = get_post_meta($cur_id, '_reeid_translation_source', true);
    $src_id   = $src_meta ? (int) $src_meta : $cur_id;

    $pairs = [];

    // Always include self
    $self_url = get_permalink($cur_id);
    if (!$self_url) return;
    $pairs[$cur_lng] = $self_url;

    // Include EN + x-default pointing to source (usually EN)
    $src_url = get_permalink($src_id);
    if ($src_url) {
        $pairs['en']        = $src_url;
        $pairs['x-default'] = $src_url;
    }

    // Pull translated siblings
    $kids = get_posts([
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'meta_query'     => [
            ['key' => '_reeid_translation_source', 'value' => strval($src_id), 'compare' => '=']
        ],
        'fields'         => 'ids',
    ]);

    foreach ($kids as $pid) {
        $lng = get_post_meta($pid, '_reeid_translation_lang', true);
        $url = get_permalink($pid);
        if ($lng && $url) $pairs[$lng] = $url;
    }

    // Encode non-ASCII path segments safely
    if (!function_exists('reeid_encode_url_path')) {
        function reeid_encode_url_path($u) {
            $p = @parse_url($u);
            if (!$p || empty($p['path'])) return $u;
            $segs = explode('/', $p['path']);
            foreach ($segs as $i => $s) {
                if ($s !== '' && preg_match('/[^\x00-\x7F]/u', $s)) $segs[$i] = rawurlencode($s);
            }
            $p['path'] = implode('/', $segs);
            return (isset($p['scheme']) ? $p['scheme'].'://' : '')
                 . ($p['host'] ?? '')
                 . (isset($p['port']) ? ':'.$p['port'] : '')
                 . ($p['path'] ?? '')
                 . (isset($p['query']) ? '?'.$p['query'] : '')
                 . (isset($p['fragment']) ? '#'.$p['fragment'] : '');
        }
    }

    echo "<!-- REEID-WC-HREFLANG -->\n";
    foreach ($pairs as $code => $url) {
        printf(
            "<link rel=\"alternate\" hreflang=\"%s\" href=\"%s\" />\n",
            esc_attr($code),
            esc_url(reeid_encode_url_path($url))
        );
    }
}, 9);


/* === REEID: Woo product hreflang (self-contained) === */
add_action('wp_head', function () {
    // quick marker so we know this file/version actually ran
    echo "<!-- REEID-WC-HREFLANG v1 -->\n";

    if (is_admin()) return;
    if (!function_exists('is_product') || !is_product()) return;

    global $post;
    if (empty($post) || empty($post->ID)) return;

    $cur_id  = (int) $post->ID;
    $cur_lng = get_post_meta($cur_id, '_reeid_translation_lang', true);
    if (!$cur_lng) $cur_lng = 'en';

    $src_meta = get_post_meta($cur_id, '_reeid_translation_source', true);
    $src_id   = $src_meta ? (int) $src_meta : $cur_id;

    $pairs = [];

    $self_url = get_permalink($cur_id);
    if (!$self_url) return;
    $pairs[$cur_lng] = $self_url;

    $src_url = get_permalink($src_id);
    if ($src_url) {
        $pairs['en']        = $src_url;
        $pairs['x-default'] = $src_url;
    }

    $kids = get_posts([
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'meta_query'     => [
            ['key' => '_reeid_translation_source', 'value' => strval($src_id), 'compare' => '='],
        ],
        'fields'         => 'ids',
    ]);
    foreach ($kids as $pid) {
        $lng = get_post_meta($pid, '_reeid_translation_lang', true);
        $url = get_permalink($pid);
        if ($lng && $url) $pairs[$lng] = $url;
    }

    if (!function_exists('reeid_encode_url_path')) {
        function reeid_encode_url_path($u) {
            $p = @parse_url($u);
            if (!$p || empty($p['path'])) return $u;
            $segs = explode('/', $p['path']);
            foreach ($segs as $i => $s) {
                if ($s !== '' && preg_match('/[^\x00-\x7F]/u', $s)) $segs[$i] = rawurlencode($s);
            }
            $p['path'] = implode('/', $segs);
            return (isset($p['scheme']) ? $p['scheme'].'://' : '')
                 . ($p['host'] ?? '')
                 . (isset($p['port']) ? ':'.$p['port'] : '')
                 . ($p['path'] ?? '')
                 . (isset($p['query']) ? '?'.$p['query'] : '')
                 . (isset($p['fragment']) ? '#'.$p['fragment'] : '');
        }
    }

    foreach ($pairs as $code => $url) {
        printf(
            "<link rel=\"alternate\" hreflang=\"%s\" href=\"%s\" />\n",
            esc_attr($code),
            esc_url(reeid_encode_url_path($url))
        );
    }
}, 9);

// REEID fix: re-attach seo-sync hreflang on non-product singulars only.
// Leave WooCommerce product SEO to the dedicated bridge.
add_action('wp', function () {
    if (is_singular() && !is_singular('product')) {
        add_action('wp_head', 'reeid_output_hreflang_tags_seosync', 99);
    } else {
        // Ensure products never get the seo-sync emitter.
        remove_action('wp_head', 'reeid_output_hreflang_tags_seosync', 99);
    }
});
