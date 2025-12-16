<?php
/**
 * REEID Switcher Helpers (SAFE)
 * Extracted from Section 48 without affecting existing switcher logic.
 * NO UI, NO shortcode, NO injections — helpers only.
 */

/* --------------------------------------------------------------------------
 * Normalize language code
 * ----------------------------------------------------------------------- */
if (! function_exists('reeid_lang_normalize_10')) {
    function reeid_lang_normalize_10($val)
    {
        $val = strtolower(substr(trim((string)$val), 0, 10));
        return preg_match('/^[a-z]{2}([-_][a-z0-9]{2})?$/i', $val) ? $val : '';
    }
}

/* --------------------------------------------------------------------------
 * Find the canonical source post of a translation network
 * (Used by generic map switchers; SAFE and non-intrusive)
 * ----------------------------------------------------------------------- */
if (! function_exists('reeid_find_source_post_id')) {
    function reeid_find_source_post_id($seed = null, $front = 0)
    {
        global $wp_query;

        if ($seed instanceof WP_Post) {
            $src = (int) $seed->ID;
        } elseif (is_numeric($seed)) {
            $src = (int) $seed;
        } elseif (isset($wp_query) && is_object($wp_query)) {
            $qo  = method_exists($wp_query, 'get_queried_object') ? $wp_query->get_queried_object() : null;
            $src = ($qo instanceof WP_Post) ? (int)$qo->ID : (int)$front;
        } else {
            $src = (int)$front;
        }

        if ($src <= 0) {
            return 0;
        }

        $visited  = [];
        $hops     = 0;
        $max_hops = 50;

        while ($hops < $max_hops) {
            if (isset($visited[$src])) break;
            $visited[$src] = true;

            $parent = get_post_meta($src, '_reeid_translation_source', true);
            if (empty($parent)) break;

            $parent = (int)$parent;
            if ($parent <= 0 || $parent === $src) break;

            $parent_post = get_post($parent);
            if (! ($parent_post instanceof WP_Post)) break;

            $src = $parent;
            $hops++;
        }

        return (int)$src;
    }
}

/* --------------------------------------------------------------------------
 * Build language-prefixed URL for generic pages
 * SAFE: does NOT override existing switcher logic
 * ----------------------------------------------------------------------- */
if (! function_exists('reeid_build_lang_prefixed_url')) {
    function reeid_build_lang_prefixed_url($url, $lang, $default)
    {
        $lang    = reeid_lang_normalize_10($lang);
        $default = reeid_lang_normalize_10($default);

        if ($lang === '' || $lang === $default) {
            return $url;
        }

        $parts = wp_parse_url($url);
        $path  = isset($parts['path']) ? (string)$parts['path'] : '/';

        // Prevent double prefixing
        if (preg_match('#^/' . preg_quote($lang, '#') . '(/|$)#', $path)) {
            return $url;
        }

        $prefixed = '/' . $lang . $path;
        $rebuilt  = user_trailingslashit(home_url($prefixed));

        // Preserve query+fragment
        if (! empty($parts['query'])) {
            $rebuilt .= '?' . $parts['query'];
        }
        if (! empty($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return $rebuilt;
    }
}

/* --------------------------------------------------------------------------
 * Woo Inline: Build product permalink using inline native slug
 * This is USED by existing product inline switcher logic (Section 45)
 * ----------------------------------------------------------------------- */
if (! function_exists('reeid_switcher_product_permalink')) {
    function reeid_switcher_product_permalink($post, $lang)
    {
        $pid = (int)($post instanceof WP_Post ? $post->ID : 0);
        if ($pid <= 0) return '';

        $default = sanitize_text_field(get_option('reeid_translation_source_lang', 'en'));

        // Get translated slug
        $tr = get_post_meta($pid, "_reeid_wc_tr_{$lang}", true);
        if (is_array($tr) && !empty($tr['slug'])) {
            $slug = rawurldecode($tr['slug']);
        } else {
            $tr_src = get_post_meta($pid, "_reeid_wc_tr_{$default}", true);
            $slug   = (is_array($tr_src) && !empty($tr_src['slug']))
                        ? rawurldecode($tr_src['slug'])
                        : get_post_field('post_name', $pid);
        }

        if ($lang === $default) {
            return home_url("/product/" . rawurlencode($slug) . "/");
        }

        return home_url("/{$lang}/product/" . rawurlencode($slug) . "/");
    }
}

/* --------------------------------------------------------------------------
 * Inline product items collector (product → list of languages + URLs)
 * Supports your existing URL rewrite system
 * ----------------------------------------------------------------------- */
if (! function_exists('reeid_switcher_collect_product_inline_items')) {
    function reeid_switcher_collect_product_inline_items($post, $default)
    {
        $items = [];
        $post_id = (int)($post instanceof WP_Post ? $post->ID : 0);
        if ($post_id <= 0) return $items;

        $inline = (array)get_post_meta($post_id, '_reeid_wc_inline_langs', true);
        $codes  = array_unique(array_filter(array_map('reeid_lang_normalize_10', array_merge([$default], $inline))));

        foreach ($codes as $code) {
            $items[] = [
                'code' => $code,
                'url'  => reeid_switcher_product_permalink($post, $code),
                'pid'  => $post_id,
            ];
        }

        return $items;
    }
}

/* --------------------------------------------------------------------------
 * Generic pages collector (for non-product posts)
 * ----------------------------------------------------------------------- */
if (! function_exists('reeid_switcher_collect_generic_items')) {
    function reeid_switcher_collect_generic_items($post, $default, $front)
    {
        $items = [];
        if (! ($post instanceof WP_Post)) return $items;

        $src = reeid_find_source_post_id($post, $front);
        if ($src <= 0) return $items;

        $map = (array)get_post_meta($src, '_reeid_translation_map', true);
        $map[$default] = $src;

        foreach ($map as $code => $pid) {
            $pid = absint($pid);
            if (! $pid || get_post_status($pid) !== 'publish') continue;

            $lang = get_post_meta($pid, '_reeid_translation_lang', true) ?: $code;
            $lang = strtolower($lang);

            // Homepage logic
            if ($pid === $front) {
                $url = ($lang === $default)
                    ? home_url('/')
                    : home_url("/{$lang}/");
            } else {
                $url = get_permalink($pid);
            }

            $items[] = [
                'code' => $lang,
                'pid'  => $pid,
                'url'  => $url,
            ];
        }

        return $items;
    }
}

/* --------------------------------------------------------------------------
 * Current language detection for Woo Inline product pages
 * ----------------------------------------------------------------------- */
if (! function_exists('reeid_current_lang_for_product')) {
    function reeid_current_lang_for_product($default)
    {
        if (function_exists('reeid_wc_resolve_lang_strong')) {
            $l = (string)reeid_wc_resolve_lang_strong();
            if ($l) return strtolower(substr($l, 0, 10));
        }

        if (! empty($_COOKIE['site_lang'])) {
            $ck = sanitize_text_field(wp_unslash($_COOKIE['site_lang']));
            return strtolower(substr($ck, 0, 10));
        }

        return strtolower(substr($default, 0, 10));
    }
}
