<?php
/**
 * REEID Translate — Translator Engine Wrappers
 *
 * Contains:
 *  - Section 21: Map-based translation wrapper
 *  - (Later) any shared translator helpers used by AJAX handlers, Gutenberg, Woo, Elementor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


if (! function_exists('reeid_call_translation_engine_for_map')) {
    /**
     * Translate associative map of { key => text } using plugin's engine.
     * Returns same shape array with translated values.
     *
     * @param array  $map         Keys → text/html
     * @param string $target_lang ISO language code
     * @param array  $ctx         Extra context (post_id, tone, prompt, etc.)
     * @return array
     */
    function reeid_call_translation_engine_for_map(array $map, string $target_lang, array $ctx = []): array
    {
        if (empty($map)) {
            return $map;
        }
        if (!function_exists('reeid_translate_map_via_openai')) {
            // Fallback: identity (English stays untranslated)
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S17.9/NO_ENGINE', ['map_count' => count($map)]);
            }
            return $map;
        }

        // Delegate to engine
        $result = reeid_translate_map_via_openai($map, $target_lang, $ctx);

        if (empty($result)) {
            // Defensive fallback: return original if translation failed
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S17.9/TRANSLATION_FAILED', ['map_count' => count($map)]);
            }
            return $map;
        }

        if (function_exists('reeid_debug_log')) {
            reeid_debug_log('S17.9/TRANSLATED', [
                'orig_count' => count($map),
                'out_count'  => count($result),
                'lang'       => $target_lang
            ]);
        }

        return $result;
    }
}

/*============================================================================================
  SECTION 24: TRANSLATE FUNCTION — OpenAI Wrapper with Collectors (LEGACY, FORCE NATIVE SLUG)
============================================================================================*/

    /**
     * Legacy translator using direct OpenAI call.
     * Keeps native-script slug handling.
     */
    function reeid_translate_via_openai_with_slug_legacy(
        $source_lang,
        $target_lang,
        $title,
        $content,
        $slug,
        $tone = 'Neutral',
        $prompt_override = ''
    ) {
        $source_lang = sanitize_text_field($source_lang);
        $target_lang = sanitize_text_field($target_lang);
        $title       = trim(wp_strip_all_tags($title));
        $content     = trim($content); // allow HTML
        $slug        = reeid_sanitize_native_slug($slug);
        $tone        = sanitize_text_field($tone);
        $prompt_override = trim($prompt_override);

        // --- System prompt enforces TITLE, SLUG, CONTENT (strict) ---
        $system_prompt = "You are a professional website translator. Translate from {$source_lang} to {$target_lang} for a multilingual WordPress website. "
            . "Translate TITLE, SLUG, and CONTENT as follows:\n"
            . "- TITLE: Full translation, human-sounding, natural.\n"
            . "- SLUG: ALWAYS generate a native-script or transliterated version for the target language. "
            . "Never return English/Latin unless the target language is English. "
            . "The slug must reflect the translated title, be short, SEO-friendly, and in the target script if supported. "
            . "Do not copy or reuse the English slug. If the language requires, transliterate the title into a slug using the target script.\n"
            . "- CONTENT: Full translation. Preserve all HTML and formatting.\n"
            . "Return strictly in this format (nothing else):\n"
            . "TITLE: ...\n"
            . "SLUG: ...\n"
            . "CONTENT:\n...";

        if (!empty($prompt_override)) {
            $system_prompt .= "\nAdditional instructions: {$prompt_override}";
        }

        $user_message = "TITLE:\n{$title}\n\nSLUG:\n{$slug}\n\nCONTENT:\n{$content}";

        $api_key = sanitize_text_field(get_option('reeid_openai_api_key'));
        $model   = sanitize_text_field(get_option('reeid_openai_model', 'gpt-4o'));

        $resp = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'    => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user',   'content' => $user_message],
                ],
                'temperature' => 0.2,
                'max_tokens'  => 4000,
            ]),
            'timeout'         => 180,
            'connect_timeout' => 30,
            'blocking'        => true,
        ]);

        if (is_wp_error($resp)) {
            return [
                'success' => false,
                'error'   => $resp->get_error_message(),
            ];
        }

        $body    = wp_remote_retrieve_body($resp);
        $json    = json_decode($body, true);
        $ai_text = $json['choices'][0]['message']['content'] ?? '';

        if (empty($ai_text)) {
            return ['success' => false, 'error' => 'Empty OpenAI response'];
        }

        // --- Parse response ---
        preg_match('/TITLE:\s*(.*?)\n/i', $ai_text, $m_title);
        preg_match('/SLUG:\s*(.*?)\n/i', $ai_text, $m_slug);
        preg_match('/CONTENT:\s*([\s\S]+)/i', $ai_text, $m_content);

        $translated_title = trim($m_title[1] ?? $title);
        $translated_slug  = trim($m_slug[1] ?? $slug);
        $translated_slug  = reeid_sanitize_native_slug($translated_slug);

        if (empty($translated_slug) && !empty($translated_title)) {
        $translated_slug = reeid_sanitize_native_slug($translated_title);
        }
        if (empty($translated_slug)) {
            $translated_slug = $slug;
        }

        return [
            'success' => true,
            'title'   => $translated_title,
            'slug'    => $translated_slug,
            'content' => trim($m_content[1] ?? $ai_text),
        ];
    }