<?php
if (! function_exists('rtqf_get_translation_packet')) {
    function rtqf_get_translation_packet(int $post_id, string $lang): ?array {
        if ($post_id <= 0 || $lang === '') return null;
        $meta = get_post_meta($post_id, '_reeid_wc_tr_' . $lang, true);
        if (is_array($meta) && ! empty($meta)) return $meta;
        return null;
    }
}

if (! function_exists('rtqf_safe_text')) {
    function rtqf_safe_text($s) {
        return is_string($s) ? trim(wp_strip_all_tags($s)) : '';
    }
}
