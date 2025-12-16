<?php
/**
 * REEID MU Store Shim — restores the minimal storage routine the translator expects.
 * - Keeps logic tiny and safe.
 * - Compatible with the working example (_reeid_wc_tr_<lang>, _reeid_wc_inline_langs).
 * - Also updates _reeid_langs for legacy switchers.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('reeid_wc_unified_log')) {
    function reeid_wc_unified_log($tag = '', $data = null) {
        // no-op logger to avoid fatals (uncomment next line to debug)
        // error_log('[REEID] '.$tag.' '.(is_scalar($data)?$data:json_encode($data)));
    }
}

if (!function_exists('reeid_wc_store_translation_meta')) {
    function reeid_wc_store_translation_meta(int $product_id, string $lang, array $payload): bool {
        $product_id = (int) $product_id;
        $lang = strtolower(trim(substr((string)$lang, 0, 10)));
        if ($product_id <= 0 || $lang === '') return false;

        // sanitize minimal payload
        $pl = array(
            'title'   => (string) ($payload['title']   ?? ''),
            'content' => (string) ($payload['content'] ?? ''),
            'excerpt' => (string) ($payload['excerpt'] ?? ''),
            'slug'    => (string) ($payload['slug']    ?? ''),
            'updated' => (string) ($payload['updated'] ?? gmdate('c')),
            'editor'  => (string) ($payload['editor']  ?? ''),
        );

        // 1) Store per-language blob under _reeid_wc_tr_<lang>
        $key = '_reeid_wc_tr_' . $lang;
        $ok  = update_post_meta($product_id, $key, $pl);

        // 2) Maintain inline langs index (used by working example)
        $inline_idx_key = '_reeid_wc_inline_langs';
        $idx = (array) get_post_meta($product_id, $inline_idx_key, true);
        if (!in_array($lang, $idx, true)) {
            $idx[] = $lang;
            update_post_meta($product_id, $inline_idx_key, array_values(array_unique($idx)));
        }

        // 3) Maintain legacy index too (used by some switchers)
        $legacy_idx_key = '_reeid_langs';
        $langs = (array) get_post_meta($product_id, $legacy_idx_key, true);
        if (!in_array($lang, $langs, true)) {
            $langs[] = $lang;
            update_post_meta($product_id, $legacy_idx_key, array_values(array_unique($langs)));
        }

        reeid_wc_unified_log('STORE', ['product_id'=>$product_id,'lang'=>$lang,'ok'=>$ok,'keys'=>array_keys($pl)]);
        return (bool) $ok;
    }
}
