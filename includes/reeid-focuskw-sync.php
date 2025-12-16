<?php
/**
 * REEID Focus Keyphrase + Meta Description Sync
 * File: wp-content/plugins/reeid-translate/includes/reeid-focuskw-sync.php
 *
 * Fix: Do NOT declare reeid_translate_preserve_tokens() here to avoid redeclare fatals.
 * All translator-related calls are wrapped with function_exists checks and passthrough fallback.
 */

if (!defined('ABSPATH')) exit;

/* One-time bootstrap guard (prevents duplicate declarations if file is included twice) */
if (defined('REEID_FOCUSKW_SYNC_BOOTSTRAPPED')) return;
define('REEID_FOCUSKW_SYNC_BOOTSTRAPPED', true);

/* Defaults (overridable via wp-config.php) */
if (!defined('REEID_FOCUSKW_SYNC'))          define('REEID_FOCUSKW_SYNC', true);
if (!defined('REEID_FOCUSKW_OVERWRITE'))     define('REEID_FOCUSKW_OVERWRITE', true);
if (!defined('REEID_FOCUSKW_TRANSLATE'))     define('REEID_FOCUSKW_TRANSLATE', true);
if (!defined('REEID_METADESC_SYNC'))         define('REEID_METADESC_SYNC', true);
if (!defined('REEID_METADESC_OVERWRITE'))    define('REEID_METADESC_OVERWRITE', true);
if (!defined('REEID_METADESC_TRANSLATE'))    define('REEID_METADESC_TRANSLATE', true);

/* Helper: enabled SEO plugins list */
if (!function_exists('reeid_enabled_plugins_for_seo')) {
    function reeid_enabled_plugins_for_seo() {
        $def = ['rankmath','yoast','seopress','aioseo'];
        $raw = defined('REEID_SEO_PLUGINS') ? @unserialize(REEID_SEO_PLUGINS) : $def;
        return is_array($raw) && $raw ? array_values(array_intersect($raw, $def)) : $def;
    }
}

/* Helper: SEO read priority */
if (!function_exists('reeid_seo_read_priority')) {
    function reeid_seo_read_priority() {
        $def = ['rankmath','yoast','seopress','aioseo'];
        $raw = defined('REEID_SEO_PRIORITY') ? @unserialize(REEID_SEO_PRIORITY) : $def;
        return is_array($raw) && $raw ? array_values(array_intersect($raw, $def)) : $def;
    }
}

/* Language detect (fallback) */
if (!function_exists('reeid_detect_lang_fallback')) {
    function reeid_detect_lang_fallback($post_id) {
        if (function_exists('reeid_focuskw_detect_lang')) {
            return reeid_focuskw_detect_lang($post_id);
        }
        $lang = get_post_meta($post_id, '_reeid_translation_lang', true);
        if (!$lang) $lang = get_post_meta($post_id, '_reeid_lang', true);
        $lang = $lang ? strtolower(str_replace('_','-',$lang)) : '';
        if (!$lang) $lang = substr(get_locale(), 0, 2);
        return $lang ?: 'en';
    }
}

/* Translation wrappers: do NOT declare translator core functions here */
if (!function_exists('reeid_translate_text_tokens_or_passthru')) {
    function reeid_translate_text_tokens_or_passthru($text, $from, $to, array $args = []) {
        if (!$text || !$from || !$to || strcasecmp($from, $to) === 0) return $text;
        if (function_exists('reeid_translate_preserve_tokens')) {
            return reeid_translate_preserve_tokens($text, $from, $to, $args);
        }
        return $text;
    }
}

/* Meta maps */
if (!function_exists('reeid_focuskw_meta_map')) {
    function reeid_focuskw_meta_map() {
        return [
            'rankmath' => [
                'read'  => ['rank_math_focus_keyword'],
                'write' => ['rank_math_focus_keyword'],
                'compound' => null,
            ],
            'yoast' => [
                'read'  => ['_yoast_wpseo_focuskw'],
                'write' => ['_yoast_wpseo_focuskw'],
                'compound' => null,
            ],
            'seopress' => [
                'read'  => ['_seopress_analysis_target_kw'],
                'write' => ['_seopress_analysis_target_kw'],
                'compound' => null,
            ],
            'aioseo' => [
                'read'  => [], // handled via compound arrays
                'write' => [],
                'compound_multi' => ['_aioseo', '_aioseo_post_settings'], // try both, v4 variants
                // structure: ['keyphrases' => ['focus' => ['keyphrase' => '...']]]
            ],
        ];
    }
}

if (!function_exists('reeid_metadesc_meta_map')) {
    function reeid_metadesc_meta_map() {
        return [
            'rankmath' => [
                'read'  => ['rank_math_description'],
                'write' => ['rank_math_description'],
                'compound' => null,
            ],
            'yoast' => [
                'read'  => ['_yoast_wpseo_metadesc'],
                'write' => ['_yoast_wpseo_metadesc'],
                'compound' => null,
            ],
            'seopress' => [
                'read'  => ['_seopress_titles_desc'],
                'write' => ['_seopress_titles_desc'],
                'compound' => null,
            ],
            'aioseo' => [
                'read'  => [], // handled via compound arrays
                'write' => [],
                'compound_multi' => ['_aioseo', '_aioseo_post_settings'], // structure ['description' => '...']
            ],
        ];
    }
}

/* Helpers for AIOSEO compound data */
if (!function_exists('reeid_aioseo_get_array')) {
    function reeid_aioseo_get_array($post_id, $key) {
        $arr = get_post_meta($post_id, $key, true);
        return is_array($arr) ? $arr : [];
    }
}
if (!function_exists('reeid_aioseo_update_array')) {
    function reeid_aioseo_update_array($post_id, $key, array $arr) {
        update_post_meta($post_id, $key, $arr);
    }
}

/* Readers */
if (!function_exists('reeid_read_canonical_focuskw')) {
    function reeid_read_canonical_focuskw($post_id) {
        $map = reeid_focuskw_meta_map();
        // Priority read for simple keys
        foreach (reeid_seo_read_priority() as $plug) {
            if (empty($map[$plug])) continue;

            foreach ($map[$plug]['read'] as $k) {
                $v = trim((string) get_post_meta($post_id, $k, true));
                if ($v !== '') return $v;
            }

            // AIOSEO compound structures
            if (!empty($map[$plug]['compound_multi'])) {
                foreach ($map[$plug]['compound_multi'] as $ckey) {
                    $compound = reeid_aioseo_get_array($post_id, $ckey);
                    if (isset($compound['keyphrases']['focus']['keyphrase'])) {
                        $v = trim((string) $compound['keyphrases']['focus']['keyphrase']);
                        if ($v !== '') return $v;
                    }
                    // Legacy/alt fallback
                    if (isset($compound['focus']['keyphrase'])) {
                        $v = trim((string) $compound['focus']['keyphrase']);
                        if ($v !== '') return $v;
                    }
                }
            }
        }
        return '';
    }
}

if (!function_exists('reeid_read_canonical_metadesc')) {
    function reeid_read_canonical_metadesc($post_id) {
        $map = reeid_metadesc_meta_map();
        foreach (reeid_seo_read_priority() as $plug) {
            if (empty($map[$plug])) continue;

            foreach ($map[$plug]['read'] as $k) {
                $v = trim((string) get_post_meta($post_id, $k, true));
                if ($v !== '') return $v;
            }

            if (!empty($map[$plug]['compound_multi'])) {
                foreach ($map[$plug]['compound_multi'] as $ckey) {
                    $compound = reeid_aioseo_get_array($post_id, $ckey);
                    if (isset($compound['description'])) {
                        $v = trim((string) $compound['description']);
                        if ($v !== '') return $v;
                    }
                    // AIOSEO variant: nested snippet/description
                    if (isset($compound['snippet']['description'])) {
                        $v = trim((string) $compound['snippet']['description']);
                        if ($v !== '') return $v;
                    }
                }
            }
        }
        return '';
    }
}

/* Writers */
if (!function_exists('reeid_write_focuskw_all_plugins')) {
    function reeid_write_focuskw_all_plugins($post_id, $focus) {
        if ($focus === '') return;
        $map = reeid_focuskw_meta_map();

        foreach (reeid_enabled_plugins_for_seo() as $plug) {
            if (empty($map[$plug])) continue;

            foreach ($map[$plug]['write'] as $k) {
                $curr = trim((string) get_post_meta($post_id, $k, true));
                if (!REEID_FOCUSKW_OVERWRITE && $curr !== '') continue;
                if ($curr !== $focus) update_post_meta($post_id, $k, $focus);
            }

            if (!empty($map[$plug]['compound_multi'])) {
                foreach ($map[$plug]['compound_multi'] as $ckey) {
                    $compound = reeid_aioseo_get_array($post_id, $ckey);
                    $curr = '';
                    if (isset($compound['keyphrases']['focus']['keyphrase'])) {
                        $curr = trim((string) $compound['keyphrases']['focus']['keyphrase']);
                    } elseif (isset($compound['focus']['keyphrase'])) {
                        $curr = trim((string) $compound['focus']['keyphrase']);
                    }
                    if (!REEID_FOCUSKW_OVERWRITE && $curr !== '') {
                        // keep
                    } else {
                        if (!isset($compound['keyphrases'])) $compound['keyphrases'] = [];
                        if (!isset($compound['keyphrases']['focus'])) $compound['keyphrases']['focus'] = [];
                        if (!isset($compound['keyphrases']['focus']['keyphrase']) || $compound['keyphrases']['focus']['keyphrase'] !== $focus) {
                            $compound['keyphrases']['focus']['keyphrase'] = $focus;
                            reeid_aioseo_update_array($post_id, $ckey, $compound);
                        }
                    }
                }
            }
        }
    }
}

if (!function_exists('reeid_write_metadesc_all_plugins')) {
    function reeid_write_metadesc_all_plugins($post_id, $desc) {
        // Allow empty description to be skipped; only write non-empty to avoid wiping content unless explicitly desired
        if ($desc === '') return;
        $map = reeid_metadesc_meta_map();

        foreach (reeid_enabled_plugins_for_seo() as $plug) {
            if (empty($map[$plug])) continue;

            foreach ($map[$plug]['write'] as $k) {
                $curr = trim((string) get_post_meta($post_id, $k, true));
                if (!REEID_METADESC_OVERWRITE && $curr !== '') continue;
                if ($curr !== $desc) update_post_meta($post_id, $k, $desc);
            }

            if (!empty($map[$plug]['compound_multi'])) {
                foreach ($map[$plug]['compound_multi'] as $ckey) {
                    $compound = reeid_aioseo_get_array($post_id, $ckey);
                    $curr = '';
                    if (isset($compound['description'])) {
                        $curr = trim((string) $compound['description']);
                    } elseif (isset($compound['snippet']['description'])) {
                        $curr = trim((string) $compound['snippet']['description']);
                    }
                    if (!REEID_METADESC_OVERWRITE && $curr !== '') {
                        // keep
                    } else {
                        // Prefer top-level 'description'
                        if (!isset($compound['description']) || $compound['description'] !== $desc) {
                            $compound['description'] = $desc;
                            // also sync nested variant if present
                            if (isset($compound['snippet']) && is_array($compound['snippet'])) {
                                $compound['snippet']['description'] = $desc;
                            }
                            reeid_aioseo_update_array($post_id, $ckey, $compound);
                        }
                    }
                }
            }
        }
    }
}

/* Translation helpers */
if (!function_exists('reeid_translate_focus_kw_if_needed')) {
    function reeid_translate_focus_kw_if_needed($text, $from, $to, array $args = []) {
        if (!$text || !$from || !$to || strcasecmp($from, $to) === 0) return $text;
        if (!REEID_FOCUSKW_TRANSLATE) return $text;
        if (function_exists('reeid_translate_preserve_tokens')) {
            return reeid_translate_preserve_tokens($text, $from, $to, $args);
        }
        return $text;
    }
}
if (!function_exists('reeid_translate_metadesc_if_needed')) {
    function reeid_translate_metadesc_if_needed($text, $from, $to, array $args = []) {
        if (!$text || !$from || !$to || strcasecmp($from, $to) === 0) return $text;
        if (!REEID_METADESC_TRANSLATE) return $text;
        if (function_exists('reeid_translate_preserve_tokens')) {
            return reeid_translate_preserve_tokens($text, $from, $to, $args);
        }
        return $text;
    }
}

/* Core sync */
if (!function_exists('reeid_sync_focuskw_metadesc_from_to')) {
    function reeid_sync_focuskw_metadesc_from_to($src_id, $tgt_id) {
        if (!get_post($src_id) || !get_post($tgt_id)) return;

        $src_lang = reeid_detect_lang_fallback($src_id);
        $tgt_lang = reeid_detect_lang_fallback($tgt_id);

        // Focus Keyphrase
        if (REEID_FOCUSKW_SYNC) {
            $kw = reeid_read_canonical_focuskw($src_id);
            if ($kw !== '') {
                $kw_t = reeid_translate_focus_kw_if_needed($kw, $src_lang, $tgt_lang, [
                    'src_id'=>$src_id,'tgt_id'=>$tgt_id,'meta_key'=>'focuskw','plugin'=>'multi'
                ]);
                reeid_write_focuskw_all_plugins($tgt_id, $kw_t);
            }
        }

        // Meta Description
        if (REEID_METADESC_SYNC) {
            $desc = reeid_read_canonical_metadesc($src_id);
            if ($desc !== '') {
                $desc_t = reeid_translate_metadesc_if_needed($desc, $src_lang, $tgt_lang, [
                    'src_id'=>$src_id,'tgt_id'=>$tgt_id,'meta_key'=>'metadesc','plugin'=>'multi'
                ]);
                reeid_write_metadesc_all_plugins($tgt_id, $desc_t);
            }
        }
    }
}

/* Sync on save - define and hook only if we provide the callback here */
if (!function_exists('reeid_focuskw_on_save_post')) {
    function reeid_focuskw_on_save_post($post_id, $post, $update){
        if (!REEID_FOCUSKW_SYNC && !REEID_METADESC_SYNC) return;
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
                // Current is a translation -> pull from source
                reeid_sync_focuskw_metadesc_from_to($source_id, $post_id);
            } else {
                // Current is a source -> push to all translations
                $targets = get_posts([
                    'post_type'        => $post->post_type,
                    'post_status'      => 'any',
                    'posts_per_page'   => -1,
                    'fields'           => 'ids',
                    'suppress_filters' => true,
                    'meta_query'       => [[ 'key' => '_reeid_translation_source', 'value' => (string) $post_id ]],
                ]);
                foreach ($targets as $tgt_id) {
                    $tgt_id = (int) $tgt_id;
                    if ($tgt_id === (int)$post_id) continue;
                    reeid_sync_focuskw_metadesc_from_to($post_id, $tgt_id);
                }
            }
        } finally {
            unset($lock[$post_id]);
        }
    }
    add_action('save_post', 'reeid_focuskw_on_save_post', 10040, 3);
}

/* Watch meta changes to trigger sync - define and hook only if not already provided elsewhere */
if (!function_exists('reeid_watch_focuskw_desc_meta')) {
    function reeid_watch_focuskw_desc_meta($meta_id, $post_id, $meta_key, $val) {
        if (!REEID_FOCUSKW_SYNC && !REEID_METADESC_SYNC) return;

        $watch = [
            // Focus KW
            'rank_math_focus_keyword','_yoast_wpseo_focuskw','_seopress_analysis_target_kw','_aioseo','_aioseo_post_settings',
            // Meta Description
            'rank_math_description','_yoast_wpseo_metadesc','_seopress_titles_desc',
        ];
        if (!in_array($meta_key, $watch, true)) return;

        static $lock = [];
        if (!empty($lock[$post_id])) return;
        $lock[$post_id] = true;

        $post = get_post($post_id);
        if ($post instanceof WP_Post) {
            do_action('save_post', $post_id, $post, true);
        }
        unset($lock[$post_id]);
    }
    add_action('updated_post_meta', 'reeid_watch_focuskw_desc_meta', 10, 4);
    add_action('added_post_meta',   'reeid_watch_focuskw_desc_meta', 10, 4);
}
