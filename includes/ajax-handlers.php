<?php
/**
 * REEID Translate — AJAX Handlers
 *
 * Contains:
 *  - Section 22: Single Translation via AJAX
 */
$ver = defined('REEID_TRANSLATE_VERSION') ? REEID_TRANSLATE_VERSION : 'dev';


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}



if ( ! function_exists( 'reeid_get_combined_prompt' ) ) {
    function reeid_get_combined_prompt( $post_id = 0, $target_lang = '', $override_prompt = '' ) {
    $post_id = (int) $post_id;
    $target_lang = is_string( $target_lang ) ? trim( $target_lang ) : '';
    $override_prompt = is_string( $override_prompt ) ? trim( $override_prompt ) : '';

    // Get admin/global prompt (primary option)
    $admin = (string) get_option( 'reeid_translation_custom_prompt', '' );
    $admin = trim( $admin );

    // Per-post prompt (optional)
    $post_prompt = '';
    if ( $post_id ) {
        $post_prompt = (string) get_post_meta( $post_id, 'reeid_translation_custom_prompt', true );
        $post_prompt = trim( $post_prompt );
    }

    // Base prompt: prefer cached global base if available
    $base = '';
    if ( isset( $GLOBALS['reeid_base_prompt_cached'] ) && is_string( $GLOBALS['reeid_base_prompt_cached'] ) ) {
        $base = $GLOBALS['reeid_base_prompt_cached'];
    } else {
        $base = "You are a professional translator and editor. Produce a natural, idiomatic, human-quality translation of the SOURCE text into the TARGET language. Return only the translated text — do not add titles, labels, examples, or extra lines. Preserve HTML structure and tags; translate text nodes only. Preserve brand names, placeholders, shortcodes and tokens exactly (for example: REEID, {{price}}, %s, [shortcode]). Do not mix languages; respond only in the target language. Prefer fluent, idiomatic phrasing over literal word-for-word translation.";
    }

    // Raw ordered parts
    $raw_parts = array();
    if ( strlen( trim( $base ) ) ) { $raw_parts[] = trim( $base ); }
    if ( strlen( $admin ) ) { $raw_parts[] = $admin; }
    if ( strlen( $post_prompt ) ) { $raw_parts[] = $post_prompt; }
    if ( strlen( $override_prompt ) ) { $raw_parts[] = $override_prompt; }

        // Normalizer: strip tags, lowercase (UTF-8 aware), collapse whitespace
    $normalize = function( $s ) {
        $s = wp_strip_all_tags( (string) $s );
        if ( function_exists( 'mb_strtolower' ) ) {
            $s = mb_strtolower( $s, 'UTF-8' );
        } else {
            $s = strtolower( $s );
        }
        $s = preg_replace( '/\s+/u', ' ', trim( $s ) );
        return trim( $s );
    };


    // Deduplicate by normalized string, preserve first-seen order.
    $seen = array();
    $final = array();
    foreach ( $raw_parts as $orig ) {
        $norm = $normalize( $orig );
        if ( $norm === '' ) {
            continue;
        }
        if ( isset( $seen[ $norm ] ) ) {
            // already added an equivalent/near-identical item; skip
            continue;
        }
        $seen[ $norm ] = true;
        $final[] = $orig;
    }

    if ( empty( $final ) ) {
        return '';
    }

    $combined = implode("\n\n---\n\n", $final);
$combined = preg_replace('/(\n){3,}/', "\n\n", $combined);

// If a target language code is provided, prepend a concise explicit instruction
// that references the code (avoids repeating full language names handled elsewhere).
if ( is_string( $target_lang ) && trim( $target_lang ) !== '' ) {
    $code = trim( $target_lang );
    $code_instruction = "Translate the following text into the language identified by code: {$code}.";
    // Avoid duplicating similar instruction if already present
    if ( stripos( $combined, 'translate the following text into the language identified by code' ) === false ) {
        $combined = $code_instruction . "\n\n---\n\n" . $combined;
    }
}

// Guard: prevent mixed-language outputs (language-neutral short guard).
$guard_phrase = 'Respond only in the target language. Do not mix languages or include text in any other language.';
if ( stripos( $combined, 'respond only in the target language' ) === false ) {
    $combined = $guard_phrase . "\n\n---\n\n" . $combined;
}

return $combined;
$inline_rule = 'Treat inline formatting tags (<strong>, <em>, <b>, <i>, <span>) as invisible for word order: translate as if tags were not there, but keep them on the same semantic words in the final result (you may move tags if natural word order changes).';
if ( stripos( $combined, 'inline formatting tags' ) === false ) {
    $combined .= "\n\n---\n\n" . $inline_rule;
}   
    }
    }

remove_action('wp_ajax_reeid_translate_openai', 'reeid_handle_ajax_translation');
add_action('wp_ajax_reeid_translate_openai', 'reeid_handle_ajax_translation');

if (! function_exists('reeid_handle_ajax_translation')) {
    function reeid_handle_ajax_translation(){
    
   

// Validate security nonce first
if (
    ! isset( $_POST['reeid_translate_nonce'] ) ||
    ! wp_verify_nonce(
        sanitize_text_field( wp_unslash( $_POST['reeid_translate_nonce'] ) ),
        'reeid_translate_nonce_action'
    )
) {
    wp_send_json_error( array(
        'error'   => 'invalid_nonce',
        'message' => 'Security check failed.',
    ));
}

// Sanitize all incoming POST variables (sanitize at read time)
$post_id = isset( $_POST['post_id'] )
	? absint( wp_unslash( $_POST['post_id'] ) )
	: 0;

$raw_lang = isset( $_POST['lang'] )
	? sanitize_text_field( wp_unslash( $_POST['lang'] ) )
	: '';

$raw_tone = isset( $_POST['tone'] )
	? sanitize_text_field( wp_unslash( $_POST['tone'] ) )
	: '';

$raw_mode = isset( $_POST['reeid_publish_mode'] )
	? sanitize_text_field( wp_unslash( $_POST['reeid_publish_mode'] ) )
	: '';

$target_lang  = $raw_lang;
$tone         = $raw_tone !== '' ? $raw_tone : 'Neutral';
$publish_mode = $raw_mode !== '' ? $raw_mode : 'publish';

// Normalize language format
$target_lang = strtolower( str_replace( '_', '-', $target_lang ) );
$target_lang = preg_replace( '/[^a-z0-9-]/', '', $target_lang );

/* >>> INJECTION START: prompt override (Elementor/Metabox/Woo) <<< */

// Accept either prompt_override or legacy prompt (sanitize at read time)
if ( isset( $_POST['prompt_override'] ) ) {
	$ui_override = sanitize_textarea_field( wp_unslash( $_POST['prompt_override'] ) );
} elseif ( isset( $_POST['prompt'] ) ) {
	$ui_override = sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) );
} else {
	$ui_override = '';
}

$ui_override = trim( $ui_override );


// Build final system prompt
$system_prompt = function_exists( 'reeid_get_combined_prompt' )
    ? reeid_get_combined_prompt( $post_id, $target_lang, $ui_override )
    : "Translate the content into the target language preserving HTML, placeholders and brand names.";

// Keep legacy $prompt var
$prompt = $system_prompt;



        // Language detector helper (uses plugin’s hreflang helper if present)
        $detect_lang = function ($id) {
            if (function_exists('reeid_post_lang_for_hreflang')) {
                return reeid_post_lang_for_hreflang($id);
            }
            $lang = get_post_meta($id, '_reeid_translation_lang', true);
            if (! $lang) $lang = get_option('reeid_translation_source_lang', 'en');
            return $lang ?: 'en';
        };

        if (! $post_id || ! $target_lang) {
            wp_send_json_error(['error' => 'missing_parameters']);
        }

        $post = get_post($post_id);
        if (! $post) {
            wp_send_json_error(['error' => 'post_not_found']);
        }

        $editor = function_exists('reeid_detect_editor_type') ? reeid_detect_editor_type($post_id) : 'classic';
        if (function_exists('reeid_debug_log')) {
            reeid_debug_log('S18 ENTRY', [
                'post_id'     => $post_id,
                'post_type'   => $post->post_type,
                'editor'      => $editor,
                'target_lang' => $target_lang,
                'mode'        => $publish_mode,
            ]);
        }

        $prompt = isset($prompt) ? trim((string)$prompt) : '';
        if ($prompt !== '') {
            $prompt = "CRITICAL INSTRUCTIONS (enforce strictly):\n" . $prompt;
        }
        if (function_exists('reeid_debug_log')) {
            reeid_debug_log('S18 PROMPT (final)', ['len' => strlen($prompt), 'preview' => mb_substr($prompt, 0, 80, 'UTF-8')]);
        }

        /* >>> DEBUG (optional) — confirm prompt reached S18 */
        if (function_exists('reeid_debug_log')) {
            reeid_debug_log('S18 PROMPT RECEIVED', [
                'len'     => is_string($prompt) ? strlen($prompt) : 0,
                'preview' => is_string($prompt) ? mb_substr($prompt, 0, 120) : '',
                'tone'    => $tone,
            ]);
        }
        /* <<< DEBUG */

        if ($prompt === '' && function_exists('reeid_build_prompt')) {
            $source_lang = (function_exists('reeid_post_lang_for_hreflang'))
                ? (string) reeid_post_lang_for_hreflang($post_id)
                : ((string) get_post_meta($post_id, '_reeid_translation_lang', true));

            if ($source_lang === '') {
                $source_lang = (string) get_option('reeid_translation_source_lang', 'en');
                if ($source_lang === '') $source_lang = 'en';
            }

            $prompt = (string) reeid_build_prompt([
                'source_lang' => $source_lang,
                'target_lang' => $target_lang,
                'tone'        => $tone,
                'context'     => 'single_ajax',
                'post_type'   => $post->post_type,
                'editor'      => $editor,
            ]);
        }


       /* ===========================================================
        WooCommerce products — store inline (no new post)
       =========================================================== */
        if ($post->post_type === 'product') {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S18/WC INLINE START', ['post_id' => $post_id, 'lang' => $target_lang]);
            }

            // Resolve cluster root for products (store on source)
            $src_id = (int) get_post_meta($post_id, '_reeid_translation_source', true);
            if (! $src_id) $src_id = $post_id;

            // Build context for translator
            $ctx = [
                'post_id' => $post_id,
                'tone'    => $tone,
                'prompt'  => $prompt,
                'title'   => $post->post_title,
                'slug'    => $post->post_name,
                'excerpt' => $post->post_excerpt,
                'domain'  => 'woocommerce',
                'entity'  => 'product',
            ];

            // Defaults (source as fallback)
            $title   = (string) $post->post_title;
            $content = (string) $post->post_content;  // long description
            $excerpt = (string) $post->post_excerpt;  // short description
            $slug    = (string) $post->post_name;

            $src_lang = (string) get_option('reeid_translation_source_lang', 'en');
            if ($src_lang === '') $src_lang = 'en';

            // === Collect WooCommerce product attributes for translation ===
            $attributes = [];
            $raw_attrs = get_post_meta($post_id, '_product_attributes', true);
            if (is_array($raw_attrs)) {
                foreach ($raw_attrs as $key => $attr) {
                    if (!empty($attr['name']) && !empty($attr['value'])) {
                        $attributes[$attr['name']] = $attr['value'];
                    }
                }
            }
            // === End collect attributes ===

// ======================================================
// REEID: persist translated attributes per language
// ======================================================
if (!empty($attributes) && is_array($attributes)) {

    $meta_key = '_reeid_wc_tr_' . strtolower($target_lang);
    $payload  = get_post_meta($src_id, $meta_key, true);

    if (!is_array($payload)) {
        $payload = [];
    }

    $payload['attributes'] = [];

    foreach ($attributes as $label => $value) {
        $payload['attributes'][] = [
            'label' => (string) $label,
            'value' => (string) $value,
        ];
    }

    update_post_meta($src_id, $meta_key, $payload);
}



            // Preferred: bulk map translator if available (bypasses extractor entirely)
            if (function_exists('reeid_translate_map_via_openai')) {
                $in = [
                    'title'      => $title,
                    'excerpt'    => $excerpt,
                    'content'    => $content,
                    'slug'       => $slug,
                    'attributes' => $attributes, // <-- Now included
                ];
                $out = (array) reeid_translate_map_via_openai($in, $target_lang, $ctx);
                error_log('[REEID ATTR DEBUG] translate_map_via_openai OUT: ' . print_r($out, true));

                $title   = is_string($out['title']   ?? '') ? $out['title']   : $title;
                $excerpt = is_string($out['excerpt'] ?? '') ? $out['excerpt'] : $excerpt;
                $content = is_string($out['content'] ?? '') ? $out['content'] : $content;
                $slug    = is_string($out['slug']    ?? '') ? $out['slug']    : $slug;
                if (!empty($out['attributes']) && is_array($out['attributes'])) {
                    $attributes = $out['attributes'];
                }

            } elseif ($editor === 'elementor') {

                // Elementor product content (prefer S13 engine)
                if (function_exists('reeid_elementor_translate_json_s13')) {

                    $res = reeid_elementor_translate_json_s13(
                        (int) $post_id,
                        (string) $src_lang,
                        (string) $target_lang,
                        (string) $tone,
                        (string) $prompt
                    );

                } elseif (function_exists('reeid_elementor_translate_json')) {

                    // Legacy Elementor engine fallback
                    $res = reeid_elementor_translate_json(
                        (int) $post_id,
                        (string) $src_lang,
                        (string) $target_lang,
                        (string) $tone,
                        (string) $prompt
                    );

                } else {

                    $res = [
                        'success' => false,
                        'message' => 'Elementor translation engine not available',
                    ];
                }

// REEID: auto-inject elementor 'data' into _elementor_data for translated post (non-destructive)
$__reeid_inject_target = (isset($tid) && $tid)
    ? $tid
    : ((isset($translated_post_id) && $translated_post_id) ? $translated_post_id : $post_id);

if (! empty($res['data']) && is_array($res['data'])) {
    // Prefer the dedicated Elementor saver so edit_mode/template/meta are consistent.
    if (function_exists('reeid_el_save_json')) {
        reeid_el_save_json($__reeid_inject_target, $res['data']);
    } elseif (function_exists('reeid_elementor_commit_post')) {
        // Fallback to the other helper if present.
        reeid_elementor_commit_post($__reeid_inject_target, $res['data']);
    } else {
        // Last-resort: minimal meta write, but make sure we mark as builder.
        $json = wp_json_encode($res['data'], JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
// Normalize Elementor JSON to avoid <\/tag> being stored
$__dec = json_decode($json, true);
if (is_array($__dec)) {
    $json = wp_json_encode($__dec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// Normalize escaped closing tags from JSON transport
$json = str_replace('<\\/', '</', $json);

// Store raw JSON (no wp_slash — JSON already escaped correctly)
update_post_meta($__reeid_inject_target, '_elementor_data', $json);

            update_post_meta($__reeid_inject_target, '_elementor_edit_mode', 'builder');
            update_post_meta(
                $__reeid_inject_target,
                '_elementor_data_version',
                defined('ELEMENTOR_VERSION') ? (string) ELEMENTOR_VERSION : (string) time()
            );
        }
    }

    // (Optional) Keep the documents cache clear for legacy paths – safe no-op if not needed.
    if (class_exists('\Elementor\Plugin')) {
        try {
            $docs = \Elementor\Plugin::instance()->documents ?? null;
            if ($docs && method_exists($docs, 'clear_doc_caches')) {
                $docs->clear_doc_caches($__reeid_inject_target);
            } elseif ($docs && method_exists($docs, 'clear_cache')) {
                $docs->clear_cache($__reeid_inject_target);
            }
        } catch (\Throwable $e) {
            // intentionally ignore cache-clear failures
        }
    }
}


                if (empty($res['success'])) {
                    if (function_exists('reeid_debug_log')) {
                        reeid_debug_log('S18/WC ELEMENTOR FAILED', $res);
                    }
                    wp_send_json_error(['error' => 'elementor_failed', 'detail' => $res]);
                }
                $title   = $res['title']   ?? $title;
                $excerpt = $res['excerpt'] ?? $excerpt;
                $content = $res['content'] ?? $content;
                $slug    = $res['slug']    ?? (function_exists('reeid_sanitize_native_slug') ? reeid_sanitize_native_slug($title) : sanitize_title($title));
            // Ignore API slug artefacts (ndash/mdash rendered as 8211/8212)
            if (is_string($slug) && preg_match('/(?:^|-)82(?:11|12)(?:-|$)/', $slug)) {
                $slug = '';
            // Fallback if slug empty after guard
              if (!is_string($slug) || $slug === '') {
                  $slug = (function_exists('reeid_sanitize_native_slug') ? reeid_sanitize_native_slug($title) : sanitize_title($title));
              }
            }
            // Ignore API slug artefacts (ndash/mdash rendered as 8211/8212)
            } elseif (function_exists('reeid_gutenberg_classic_translate_via_extractor') || function_exists('reeid_translate_html_with_openai')) {
                // Gutenberg/Classic products — fall back to extractor + short-text translators

                // CHANGE: pass $tone and $prompt to extractor so Custom Prompt is honored
                if (function_exists('reeid_gutenberg_classic_translate_via_extractor')) {
                    $content_tr = reeid_gutenberg_classic_translate_via_extractor($post->post_content, $target_lang, $tone, $prompt);
                    if (is_string($content_tr) && $content_tr !== '') $content = $content_tr;
                }

                if (function_exists('reeid_translate_html_with_openai')) {
                    // Note: this helper doesn’t accept $prompt; leave as-is unless you extend its signature
                    $title_tr   = (string) reeid_translate_html_with_openai($post->post_title, 'en', $target_lang, $editor, $tone, $prompt);
                    $excerpt_tr = (string) reeid_translate_html_with_openai($post->post_excerpt, 'en', $target_lang, $editor, $tone, $prompt);

                    if ($title_tr   !== '') $title   = $title_tr;
                    if ($excerpt_tr !== '') $excerpt = $excerpt_tr;
                    $slug = function_exists('reeid_sanitize_native_slug') ? reeid_sanitize_native_slug($title) : sanitize_title($title);
                }

                if (!empty($attributes) && function_exists('reeid_translate_html_with_openai')) {

    $attributes_tr = [];

    foreach ($attributes as $attr_name => $attr_val) {

        // Translate ATTRIBUTE NAME (label)
        $name_tr = (string) reeid_translate_html_with_openai(
            $attr_name,
            $src_lang,
            $target_lang,
            $editor,
            $tone,
            $prompt
        );
        if ($name_tr === '') {
            $name_tr = $attr_name;
        }

        // Translate ATTRIBUTE VALUE
        $value_tr = (string) reeid_translate_html_with_openai(
            $attr_val,
            $src_lang,
            $target_lang,
            $editor,
            $tone,
            $prompt
        );
        if ($value_tr === '') {
            $value_tr = $attr_val;
        }

        // Store translated label + value
        $attributes_tr[$name_tr] = $value_tr;
    }

    // Replace original attributes with translated set
    $attributes = $attributes_tr;
}

            }

            // Store inline translation (no new product post) — ALWAYS on src_id
            if (! function_exists('reeid_wc_store_translation_meta')) {
                if (function_exists('reeid_debug_log')) {
                    reeid_debug_log('S18/WC MISSING STORE FUNCTION', null);
                }
                wp_send_json_error(['error' => 'storage_unavailable']);
            }

            // Sanitize minimally
            $safe_title = trim(wp_strip_all_tags((string) $title));
            $safe_slug  = reeid_sanitize_native_slug($slug ?: $safe_title);
            $payload = [
                'title'      => (string) $safe_title,
                'content'    => (string) wp_kses_post($content),
                'excerpt'    => (string) wp_kses_post($excerpt),
                'slug'       => (string) $safe_slug,
                'updated'    => gmdate('c'),
                'editor'     => $editor,
                'attributes' => $attributes, // Save translated attributes to meta
            ];
           error_log('[REEID TRACE] BEFORE STORE lang=' . $target_lang);
error_log('[REEID TRACE] payload keys=' . implode(',', array_keys($payload)));
error_log('[REEID TRACE] attributes isset=' . (isset($payload['attributes']) ? 'YES' : 'NO'));
error_log('[REEID TRACE] attributes type=' . gettype($payload['attributes'] ?? null));
error_log('[REEID TRACE] attributes value=' . json_encode($payload['attributes'] ?? null));


            $ok = reeid_wc_store_translation_meta($src_id, $target_lang, $payload);


           

            // Update map on the cluster root to point this language to the same product (inline mode)
            $map      = (array) get_post_meta($src_id, '_reeid_translation_map', true);
            $map[$src_lang]    = $src_id;
            $map[$target_lang] = $src_id;
            update_post_meta($src_id, '_reeid_translation_map', $map);

            


            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S18/WC INLINE STORED', [
                    'post_id' => $post_id,
                    'src_id' => $src_id,
                    'lang' => $target_lang,
                    'ok' => $ok,
                    'lens' => [
                        'title' => strlen($payload['title']),
                        'excerpt' => strlen($payload['excerpt']),
                        'content' => strlen($payload['content']),
                        'attributes' => is_array($payload['attributes']) ? count($payload['attributes']) : 0,
                    ],
                ]);
            }

            wp_send_json_success([
                'ok'               => (bool) $ok,
                'action'           => 'stored_inline',
                'post_id'          => $src_id,
                'lang'             => $target_lang,
                'inline'           => true,
                'collected_count'  => 0,
                'translated_count' => 0,
            ]);
        }


       /*===========================================================
            DEFAULT PATH (Pages/Posts/etc.)
=========================================================== */
$src_id = $post_id;
$map    = (array) get_post_meta($src_id, '_reeid_translation_map', true);
$default_source_lang       = get_option('reeid_translation_source_lang', 'en');
$map[$default_source_lang] = $src_id;

$target_id = isset($map[$target_lang]) ? (int) $map[$target_lang] : 0;
$action    = 'updated';
if (! $target_id || ! get_post_status($target_id)) {

    // Determine canonical source post and type (defensive).
    $source_id   = isset($src_id) && $src_id ? (int) $src_id : (int) ($post->ID ?? 0);
    $source_type = $source_id ? get_post_type($source_id) : ($post->post_type ?? '');

    // If the source is a WooCommerce product or variation, do NOT create a new post.
    // Instead use inline-storage: point $target_id to the original product and mark action.
    if (in_array($source_type, array('product', 'product_variation'), true)) {
        $target_id = $source_id;
        $action    = 'stored_inline';
        // Optional downstream flag (harmless if unused)
        $reeid_translation_inline_mode = true;
    } else {
        // Non-product: create a new draft post as before.
        $target_id = wp_insert_post(array(
            'post_type'   => $post->post_type,
            'post_status' => 'draft',
            'post_title'  => $post->post_title,
            'post_author' => $post->post_author,
        ), true);

        $action = 'created';

        if (is_wp_error($target_id)) {
            wp_send_json_error(array('error' => 'create_failed', 'detail' => $target_id->get_error_message()));
        }
    }
}

$collected_count  = 0;
$translated_count = 0;
$translated_slug  = '';

// Keep these visible for the SEO tail-section
$result     = null;
$title_tr   = '';
$excerpt_tr = '';

if ($editor === 'elementor') {
    // Prefer the S13 Elementor translator (rulepack + /v1/translate) when available.
    $translator_fn = null;
    if (function_exists('reeid_elementor_translate_json_s13')) {
        $translator_fn = 'reeid_elementor_translate_json_s13';
    } elseif (function_exists('reeid_elementor_translate_json')) {
        $translator_fn = 'reeid_elementor_translate_json';
    }

    if (! $translator_fn) {
        wp_send_json_error([
            'error'  => 'elementor_translator_missing',
            'detail' => 'No Elementor JSON translator function is available.',
        ]);
    }

    // Call the translator (driven by api.reeid.com walker)
    $result = $translator_fn(
        $post_id,
        $default_source_lang,
        $target_lang,
        $tone,
        $prompt
    );

    // Pre-translation stats (optional)
    $raw_elem    = function_exists('reeid_get_sanitized_elementor_data')
        ? reeid_get_sanitized_elementor_data($post_id)
        : get_post_meta($post_id, '_elementor_data', true);

    $elem_before = is_array($raw_elem)
        ? $raw_elem
        : @json_decode((string) $raw_elem, true);

    if (is_array($elem_before) && function_exists('reeid_elementor_walk_and_collect')) {
        $before_map = [];
        reeid_elementor_walk_and_collect($elem_before, '', $before_map);
        $collected_count = count($before_map);
    }

    if (isset($result['data']) && is_array($result['data']) && function_exists('reeid_elementor_walk_and_collect')) {
        $after_map = [];
        reeid_elementor_walk_and_collect($result['data'], '', $after_map);
        $translated_count = count($after_map);
    }

    if (empty($result['success'])) {
        wp_send_json_error([
            'error'  => 'elementor_failed',
            'detail' => $result,
        ]);
    }

    // --- Build clean native slug candidate from translator result (title/slug) ---
    $slug_candidate = '';
    if (!empty($result['slug']) && is_string($result['slug'])) {
        $slug_candidate = $result['slug'];
    } elseif (!empty($result['title'])) {
        $slug_candidate = (string) $result['title'];
    } else {
        $slug_candidate = (string) $post->post_title;
    }

    // Use native slug sanitizer (handles non-ASCII without percent-encoding)
    $slug_candidate = function_exists('reeid_sanitize_native_slug')
        ? reeid_sanitize_native_slug($slug_candidate)
        : sanitize_title($slug_candidate);

    // Ensure uniqueness before saving
    $slug_unique = wp_unique_post_slug(
        $slug_candidate,
        (int) $target_id,
        get_post_status($target_id),
        $post->post_type,
        (int) get_post_field('post_parent', $target_id)
    );

    // Build translated post array
    $new_post = [
        'ID'           => $target_id,
        'post_title'   => $result['title']   ?? $post->post_title,
        'post_status'  => $publish_mode,
        'post_type'    => $post->post_type,
        'post_name'    => $slug_unique ?: $slug_candidate,
        'post_author'  => $post->post_author,
        'post_excerpt' => $result['excerpt'] ?? $post->post_excerpt,
    ];

    // === Force this exact slug at save time (Elementor scope only) ===
    $reeid_slug_to_force = $new_post['post_name'];

    $reeid_cb_sanitize = static function ($title, $raw_title, $context) use (&$reeid_slug_to_force) {
        return (is_string($reeid_slug_to_force) && $reeid_slug_to_force !== '')
            ? $reeid_slug_to_force
            : $title;
    };

    $reeid_cb_insert = static function ($data, $postarr) use (&$reeid_slug_to_force) {
        if (is_string($reeid_slug_to_force) && $reeid_slug_to_force !== '') {
            $data['post_name'] = $reeid_slug_to_force;
        }
        return $data;
    };

    add_filter('sanitize_title',       $reeid_cb_sanitize, PHP_INT_MAX, 3);
    add_filter('wp_insert_post_data',  $reeid_cb_insert,   PHP_INT_MAX, 2);

    // Safe update
    $target_id = function_exists('reeid_safe_wp_update_post')
        ? reeid_safe_wp_update_post($new_post, true)
        : wp_update_post($new_post, true);

    remove_filter('sanitize_title',      $reeid_cb_sanitize, PHP_INT_MAX);
    remove_filter('wp_insert_post_data', $reeid_cb_insert,   PHP_INT_MAX);
    unset($reeid_slug_to_force, $reeid_cb_sanitize, $reeid_cb_insert);

    if (is_wp_error($target_id)) {
        wp_send_json_error([
            'error'  => 'update_failed',
            'detail' => $target_id->get_error_message(),
        ]);
    }

            // Elementor branch: commit translated tree + ensure meta and CSS
if (isset($result['data'])) {

    // --- NORMALIZE ELEMENTOR ARRAY FIRST (CRITICAL) ---
    if (is_array($result['data'])) {
        array_walk_recursive($result['data'], function (&$v) {
            if (is_string($v)) {
                $v = str_replace('<\/', '</', $v);
            }
        });
    }

    // 1) Prefer the dedicated saver so JSON + meta + CSS are consistent
    if (function_exists('reeid_elementor_commit_post')) {

        // Always pass CLEAN data
        reeid_elementor_commit_post($target_id, $result['data']);

    } else {

        // 2) Fallback: encode clean Elementor JSON
        $elem_json = is_array($result['data'])
            ? wp_json_encode(
                $result['data'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
            : (string) $result['data'];

        if ($elem_json !== '') {
            update_post_meta($target_id, '_elementor_data', $elem_json);
        }

        update_post_meta($target_id, '_elementor_edit_mode', 'builder');

        $ptype = get_post_type($target_id);
        $tmpl  = ($ptype === 'page') ? 'wp-page' : 'wp-post';
        update_post_meta($target_id, '_elementor_template_type', $tmpl);

        $ver = get_option('elementor_version');
        if (! $ver && defined('ELEMENTOR_VERSION')) {
            $ver = ELEMENTOR_VERSION;
        }
        if ($ver) {
            update_post_meta($target_id, '_elementor_data_version', $ver);
        }
    }

    // 3) Copy template type from source if it exists
    $tpl_type = get_post_meta($post_id, '_elementor_template_type', true);
    if (!empty($tpl_type)) {
        update_post_meta($target_id, '_elementor_template_type', $tpl_type);
    }

    // 4) Clear Elementor caches
    if (did_action('elementor/loaded')) {
        try {
            if (class_exists('\Elementor\Core\Files\CSS\Post')) {
                $css = new \Elementor\Core\Files\CSS\Post($target_id);
                if (method_exists($css, 'delete')) $css->delete();
                if (method_exists($css, 'update')) $css->update();
            }

            if (isset(\Elementor\Plugin::$instance->files_manager)) {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }
}



    // --- Guard: if translated title contains non-ASCII but saved slug fell back to ASCII, fix now ---
    if (!is_wp_error($target_id)) {
        $title_for_slug = (string) get_post_field('post_title', $target_id);
        $saved          = get_post($target_id);

        if ($saved && isset($saved->post_name)) {
            $title_has_non_ascii = (bool) preg_match('/[^\x00-\x7F]/u', $title_for_slug);
            $slug_has_non_ascii  = (bool) preg_match('/[^\x00-\x7F]/u', (string) $saved->post_name);

            if ($title_has_non_ascii && ! $slug_has_non_ascii) {
                $native = function_exists('reeid_sanitize_native_slug')
                    ? reeid_sanitize_native_slug($title_for_slug)
                    : sanitize_title($title_for_slug);

                $unique = wp_unique_post_slug(
                    $native,
                    $target_id,
                    get_post_status($target_id),
                    get_post_type($target_id),
                    (int) get_post_field('post_parent', $target_id)
                );

                wp_update_post([
                    'ID'        => $target_id,
                    'post_name' => $unique ?: $native,
                ]);
            }
        }
    }

    // Expose for SEO tail section
    $translated_slug = (string) ($result['slug'] ?? '');
} else {

    // Gutenberg/Classic path
    $en_lines   = function_exists('reeid_extract_text_lines') ? reeid_extract_text_lines($post->post_content) : [];

    // IMPORTANT: pass $tone and RAW $prompt (merging happens inside helpers)
    $content_tr = function_exists('reeid_gutenberg_classic_translate_via_extractor')
        ? reeid_gutenberg_classic_translate_via_extractor($post->post_content, $target_lang, $tone, $prompt)
        : '';

    // Titles / excerpts via short-text helper — now supports $prompt
    $title_tr   = function_exists('reeid_translate_html_with_openai')
        ? reeid_translate_html_with_openai($post->post_title,   'en', $target_lang, $editor, $tone, $prompt)
        : '';
    $excerpt_tr = function_exists('reeid_translate_html_with_openai')
        ? reeid_translate_html_with_openai($post->post_excerpt, 'en', $target_lang, $editor, $tone, $prompt)
        : '';

    $ar_lines = [];
    if (is_string($content_tr) && $content_tr !== '' && $en_lines && function_exists('reeid_extract_text_lines')) {
        $ar_lines = reeid_extract_text_lines($content_tr);
    }

    $collected_count  = is_array($en_lines) ? count($en_lines) : 0;
    $translated_count = is_array($ar_lines) ? count($ar_lines) : 0;

    // Slug handling (Gutenberg/Classic)
    $translated_slug = '';
    if (is_string($title_tr) && $title_tr !== '') {
        $translated_slug = reeid_sanitize_native_slug($title_tr);
    }
    if ($translated_slug === '') {
        $translated_slug = reeid_sanitize_native_slug($post->post_title);
    }

    $translated_slug = wp_unique_post_slug(
        $translated_slug,
        $target_id,
        $publish_mode === 'publish' ? 'publish' : 'draft',
        $post->post_type,
        $post->post_parent
    );

    // Save
    $new_post = [
        'ID'           => $target_id,
        'post_title'   => ($title_tr !== '' ? $title_tr : $post->post_title),
        'post_status'  => $publish_mode,
        'post_type'    => $post->post_type,
        'post_name'    => $translated_slug,
        'post_author'  => $post->post_author,
        'post_excerpt' => ($excerpt_tr !== '' ? $excerpt_tr : $post->post_excerpt),
    ];

    $target_id = function_exists('reeid_safe_wp_update_post')
        ? reeid_safe_wp_update_post($new_post, true)
        : wp_update_post($new_post, true);

    if (is_wp_error($target_id)) {
        wp_send_json_error(['error' => 'update_failed', 'detail' => $target_id->get_error_message()]);
    }

    // Commit translated content if available
    if (isset($content_tr)) {
        $clean = is_string($content_tr) ? trim($content_tr) : '';
        if ($clean !== '') {
            if (function_exists('reeid_safe_wp_update_post')) {
                reeid_safe_wp_update_post(['ID' => $target_id, 'post_content' => $clean]);
            } else {
                wp_update_post(['ID' => $target_id, 'post_content' => $clean]);
            }
            delete_post_meta($target_id, '_reeid_translated_content_' . $target_lang);
        } else {
            update_post_meta($target_id, '_reeid_translated_content_' . $target_lang, $content_tr);
        }
    }

    // --- Guard: if translated title is native but saved slug is ASCII, fix immediately
    $title_for_slug = ($title_tr !== '' ? $title_tr : $post->post_title);
    $saved = get_post($target_id);
    if ($saved && isset($saved->post_name)) {
        $title_has_non_ascii = (bool) preg_match('/[^\x00-\x7F]/u', (string) $title_for_slug);
        $slug_has_non_ascii  = (bool) preg_match('/[^\x00-\x7F]/u', (string) $saved->post_name);
        if ($title_has_non_ascii && !$slug_has_non_ascii) {
            $native = reeid_sanitize_native_slug($title_for_slug);
            $unique = wp_unique_post_slug(
                $native,
                $target_id,
                get_post_status($target_id),
                get_post_type($target_id),
                (int) get_post_field('post_parent', $target_id)
            );
            wp_update_post([
                'ID'        => $target_id,
                'post_name' => $unique ?: $native,
            ]);
        }
    }
}

// Common bookkeeping
update_post_meta($target_id, '_reeid_translation_lang', $target_lang);
update_post_meta($target_id, '_reeid_translation_source', $src_id);
$map[$target_lang] = $target_id;
update_post_meta($src_id, '_reeid_translation_map', $map);


if (function_exists('reeid_clone_seo_meta')) {
    reeid_clone_seo_meta($src_id, $target_id, $target_lang);
}

// === Write translated SEO meta (title + description) — single place ===
if (function_exists('reeid_write_title_all_plugins')) {
    // Resolve langs
    $src_lang = $detect_lang($src_id);
    $tgt_lang = $target_lang;

    // Final title for target
    $final_title = ($editor === 'elementor')
        ? (string)($result['title'] ?? $post->post_title)
        : (string)($title_tr !== '' ? $title_tr : $post->post_title);
    $final_title_trim = trim((string)$final_title);
    if ($final_title_trim !== '' && stripos($final_title_trim, 'INVALID LANGUAGE PAIR') === false) {
        reeid_safe_write_title_all_plugins($target_id, $final_title_trim);
    } else {
        if (function_exists('reeid_debug_log')) {
            reeid_debug_log('S18/SEO_SKIPPED_TITLE', [
                'post_id' => $target_id,
                'preview' => mb_substr($final_title_trim, 0, 80, 'UTF-8'),
            ]);
        }
    }
}

if (function_exists('reeid_write_description_all_plugins')) {
    // Always derive description from SOURCE (avoid double-translating target excerpts)
    $src_desc = '';
    if (function_exists('reeid_read_canonical_description')) {
        $src_desc = (string) reeid_read_canonical_description($src_id);
    } else {
        $src_desc = wp_strip_all_tags(get_post_field('post_excerpt', $src_id) ?: get_post_field('post_content', $src_id));
    }
    $src_desc = trim(preg_replace('/\s+/', ' ', (string)$src_desc));

    if ($src_desc !== '') {
        $src_lang = $detect_lang($src_id);
        $tgt_lang = $target_lang;

        $desc_tr = $src_desc;
        if ($src_lang && $tgt_lang && strcasecmp($src_lang, $tgt_lang) !== 0 && function_exists('reeid_translate_short_text')) {
            $desc_tr = (string) reeid_translate_short_text($src_desc, $src_lang, $tgt_lang, $tone);
            reeid_harden_invalid_lang_pair($desc_tr);
            $desc_tr_trim = trim($desc_tr);
            if ($desc_tr_trim !== '' && stripos($desc_tr_trim, 'INVALID LANGUAGE PAIR') === false) {
                reeid_write_description_all_plugins($target_id, $desc_tr_trim);
            } else {
                if (function_exists('reeid_debug_log')) {
                    reeid_debug_log('S18/SEO_SKIPPED_DESC', [
                        'post_id' => $target_id,
                        'preview' => mb_substr($desc_tr_trim, 0, 160, 'UTF-8'),
                    ]);
                }
            }
        }
    }

    if (get_post_type($target_id) === 'product' && class_exists('WC_Product') && function_exists('reeid_translate_product_attributes')) {
        $src_product = wc_get_product($src_id);
        $dst_product = wc_get_product($target_id);
        $src_lang = $default_source_lang;
        $dst_lang = $target_lang;
        if ($src_product && $dst_product) {
            if (empty($tone)) $tone = 'neutral';
            reeid_translate_product_attributes($src_product, $dst_product, $src_lang, $dst_lang, $tone);
        }
    }

    wp_send_json_success([
        'ok'               => true,
        'action'           => $action,
        'post_id'          => $target_id,
        'lang'             => $target_lang,
        'slug'             => $translated_slug,
        'collected_count'  => $collected_count,
        'translated_count' => $translated_count,
    ]);
}
    }}

    /*===========================================================================
    SECTION 23 AJAX — Bulk translation (STRICT + preflight + SEO sync)
    Function: reeid_handle_ajax_bulk_translation_v3
 *===========================================================================*/

    if (function_exists('remove_all_actions')) {
        remove_all_actions('wp_ajax_reeid_translate_openai_bulk');
    }
    if (function_exists('remove_action') && function_exists('has_action')) {
        if (has_action('wp_ajax_reeid_translate_openai_bulk', 'reeid_handle_ajax_bulk_translation')) {
            remove_action('wp_ajax_reeid_translate_openai_bulk', 'reeid_handle_ajax_bulk_translation');
        }
    }
    add_action('wp_ajax_reeid_translate_openai_bulk', 'reeid_handle_ajax_bulk_translation_v3', 1);

    if (! function_exists('reeid_handle_ajax_bulk_translation_v3')) :
        function reeid_handle_ajax_bulk_translation_v3()
        {

            /* ---------- Nonce ---------- */
            $nonce_post      = filter_input(INPUT_POST, 'reeid_translate_nonce', FILTER_DEFAULT);
            $nonce_unslashed = $nonce_post ? wp_unslash($nonce_post) : '';
            $nonce           = sanitize_text_field($nonce_unslashed);
            if (! $nonce || ! wp_verify_nonce($nonce, 'reeid_translate_nonce_action')) {
                wp_send_json_error(['code' => 'bad_nonce', 'message' => __('Invalid security token', 'reeid-translate')]);
            }

            /* ---------- Capability ---------- */
            if (! current_user_can('edit_posts')) {
                wp_send_json_error(['code' => 'forbidden', 'message' => __('Permission denied', 'reeid-translate')]);
            }

            /* ---------- Root post ---------- */
            $post_id_raw = filter_input(INPUT_POST, 'post_id', FILTER_DEFAULT);
            $post_id     = $post_id_raw ? absint(wp_unslash($post_id_raw)) : 0;
            if (! $post_id) {
                wp_send_json_error(['code' => 'no_post', 'message' => __('Missing post ID', 'reeid-translate')]);
            }

            $src = (int) get_post_meta($post_id, '_reeid_translation_source', true);
            if ($src > 0 && $src !== $post_id) {
                $post_id = $src;
            }

            $root_post = get_post($post_id);
            if (! $root_post) {
                wp_send_json_error(['code' => 'no_root', 'message' => __('Original post not found', 'reeid-translate')]);
            }

            /* ---------- Params ---------- */
            $tone   = sanitize_text_field(wp_unslash(filter_input(INPUT_POST, 'tone', FILTER_DEFAULT) ?: 'Neutral'));
            $prompt = sanitize_textarea_field(wp_unslash(filter_input(INPUT_POST, 'prompt', FILTER_DEFAULT) ?: ''));
            $mode   = sanitize_text_field(wp_unslash(filter_input(INPUT_POST, 'reeid_publish_mode', FILTER_DEFAULT) ?: 'publish'));

            // Admin-enabled languages (single source of truth)
            $configured = function_exists('reeid_get_enabled_languages')
                ? (array) reeid_get_enabled_languages()
                : (array) get_option('reeid_bulk_translation_langs', []);

            // Sanitize + intersect with supported (if list exists)
            $configured = array_values(array_filter(array_map(function ($v) {
                $v = strtolower(trim((string)$v));
                return preg_replace('/[^a-z0-9\-_]/i', '', $v);
            }, $configured)));

            if (function_exists('reeid_get_supported_languages')) {
                $supported_codes = array_keys((array) reeid_get_supported_languages());
                $configured      = array_values(array_intersect($configured, $supported_codes));
            }

            if (empty($configured)) {
                wp_send_json_error(['code' => 'no_bulk_langs', 'message' => __('No bulk languages selected', 'reeid-translate')]);
            }

            $check_only_raw = filter_input(INPUT_POST, 'check_only', FILTER_DEFAULT);
            $check_only     = $check_only_raw ? (bool) wp_unslash($check_only_raw) : false;

            $source_lang    = get_option('reeid_translation_source_lang', 'en');
            if (! is_string($source_lang) || $source_lang === '') {
                $source_lang = 'en';
            }

            $map = (array) get_post_meta($post_id, '_reeid_translation_map', true);
            $map[$source_lang] = $post_id;

            $selected = $configured;
            $details  = [];
            $results  = [];

            // If enqueuing jobs exists, prefer enqueue (fast client return)
            if (function_exists('reeid_translation_job_enqueue')) {
                $queued = [];
                foreach ($selected as $lang) {
                    $lang = strtolower(trim((string)$lang));
                    if ($lang === '' || $lang === $source_lang) {
                        $details[] = strtoupper($lang) . ': ⏭️ ' . __('Skipped (same as source)', 'reeid-translate');
                        continue;
                    }
                    $job_id = reeid_translation_job_enqueue(array(
                        'type'        => 'single',
                        'post_id'     => $post_id,
                        'target_lang' => $lang,
                        'user_id'     => get_current_user_id() ?: 1,
                        'params'      => array(
                            'tone'         => $tone,
                            'publish_mode' => $mode,
                            'prompt'       => $prompt,
                        ),
                    ));
                    if ($job_id) {
                        $queued[] = $lang;
                        $details[] = strtoupper($lang) . ': ⏳ ' . __('Queued', 'reeid-translate');
                        $results[$lang] = ['success' => true, 'queued' => true, 'job_id' => $job_id];
                    } else {
                        $details[] = strtoupper($lang) . ': ❌ ' . __('Queue failed', 'reeid-translate');
                        $results[$lang] = ['success' => false, 'error' => 'queue_failed'];
                    }
                }

                wp_send_json_success(array(
                    'queued'  => $queued,
                    'details' => $details,
                    'results' => $results,
'message' => sprintf(
    // translators: %1$d is the number of languages queued.
    _n(
        '%1$d language queued',
        '%1$d languages queued',
        count($queued),
        'reeid-translate'
    ),
    count($queued)
),
                ));
                // worker will process jobs in background
            }

            if ($check_only) {
                wp_send_json_success([
                    'ok'         => true,
                    'action'     => 'plan_only',
                    'post_id'    => $post_id,
                    'langs'      => $selected,
                    'tone'       => $tone,
                    'publish'    => $mode,
                    'has_prompt' => ($prompt !== ''),
                ]);
            }

            /* ---------- Mini helpers ---------- */

            // Language resolver (post -> hreflang code)
            $resolve_lang = function ($id, $fallback = 'en') {
                if (function_exists('reeid_post_lang_for_hreflang')) {
                    $v = (string) reeid_post_lang_for_hreflang($id);
                    if ($v !== '') return $v;
                }
                $v = get_post_meta($id, '_reeid_translation_lang', true);
                if (! $v) $v = get_option('reeid_translation_source_lang', 'en');
                return $v ?: $fallback;
            };

            // Short-text translator
            $translate_short = function ($text, $from, $to) use ($tone) {
                $text = is_string($text) ? trim($text) : '';
                if ($text === '' || ! $from || ! $to || strcasecmp($from, $to) === 0) {
                    return $text;
                }
                if (function_exists('reeid_translate_short_text')) {
                    return (string) reeid_translate_short_text($text, $from, $to, $tone);
                }
                if (function_exists('reeid_translate_preserve_tokens') && function_exists('reeid_focuskw_call_translator')) {
                    return (string) reeid_translate_preserve_tokens($text, $from, $to, ['meta_key' => 'seo_text']);
                }
                if (function_exists('reeid_translate_html_with_openai')) {
                    return (string) reeid_translate_html_with_openai($text, $from, $to, 'classic', $tone);
                }
                return $text;
            };

            // SEO write helpers (safe if functions exist)
            $write_seo_title = function ($pid, $title) {
                $t = is_string($title) ? trim($title) : '';
                if ($t === '' || stripos($t, 'INVALID LANGUAGE PAIR') !== false) return;
                if (function_exists('reeid_write_title_all_plugins')) {
                    reeid_safe_write_title_all_plugins($pid, $t);
                }
            };
            $write_seo_desc = function ($pid, $desc) {
                $d = is_string($desc) ? trim($desc) : '';
                if ($d === '' || stripos($d, 'INVALID LANGUAGE PAIR') !== false) return;
                if (function_exists('reeid_write_description_all_plugins')) {
                    reeid_write_description_all_plugins($pid, $d);
                }
            };


            // Read canonical SEO fields from SOURCE
            $read_src_seo = function ($src_id) {
                $out = ['title' => '', 'desc' => ''];
                if (function_exists('reeid_read_canonical_title')) {
                    $out['title'] = (string) reeid_read_canonical_title($src_id);
                } else {
                    $out['title'] = get_the_title($src_id);
                }
                if (function_exists('reeid_read_canonical_description')) {
                    $out['desc'] = (string) reeid_read_canonical_description($src_id);
                } else {
                    $ex = get_post_field('post_excerpt', $src_id);
                    $out['desc'] = is_string($ex) ? trim(wp_strip_all_tags($ex)) : '';
                }
                return $out;
            };

            // Simple lock helpers to avoid double-runs
            if (! function_exists('reeid__bulk_try_lock')) {
                function reeid__bulk_try_lock($post_id, $lang, $ttl = 60)
                {
                    $key = 'reeid_bulk_' . $post_id . '_' . $lang;
                    $acq = set_transient($key, '1', $ttl);
                    return [$acq ? true : false, $key];
                }
                function reeid__bulk_release_lock($key)
                {
                    if (function_exists('wp_cache_delete')) wp_cache_delete($key, 'reeid');
                    delete_transient($key);
                }
            }

            /* ---------- Process each language (inline) ---------- */
            foreach ($selected as $lang) {
                $lang = strtolower(substr((string)$lang, 0, 10));

                if ($lang === $source_lang) {
                    $details[]      = strtoupper($lang) . ': ⏭️ ' . __('Skipped (same as source)', 'reeid-translate');
                    $results[$lang] = ['success' => true, 'skipped' => true, 'reason' => 'same_as_source'];
                    continue;
                }

                [$acquired, $lock_key] = reeid__bulk_try_lock($post_id, $lang, 120);
                if (! $acquired) {
                    $details[]      = strtoupper($lang) . ': ⏭️ ' . __('Skipped (already in progress)', 'reeid-translate');
                    $results[$lang] = ['success' => true, 'skipped' => true, 'reason' => 'locked'];
                    continue;
                }

                // Decide editor
                $editor = function_exists('reeid_detect_editor_type') ? reeid_detect_editor_type($post_id) : 'classic';

                // WooCommerce inline path (do not create separate posts)
                if ($root_post->post_type === 'product') {
                    $title   = $root_post->post_title;
                    $content = $root_post->post_content;
                    $excerpt = $root_post->post_excerpt;
                    $slug    = $root_post->post_name;

                    if ($editor === 'elementor') {

                        if (function_exists('reeid_elementor_translate_json_s13')) {

                            $result = reeid_elementor_translate_json_s13(
                                (int) $post_id,
                                (string) $source_lang,
                                (string) $lang,
                                (string) $tone,
                                (string) $prompt
                            );

                        } elseif (function_exists('reeid_elementor_translate_json_s13')) {

    // Preferred: modern S13 Elementor engine (API-based, no local extractor)
    $result = reeid_elementor_translate_json_s13(
        (int) $post_id,
        (string) $source_lang,
        (string) $lang,
        (string) $tone,
        (string) $prompt
    );

} elseif (
    function_exists('reeid_elementor_translate_json')
    && get_post_meta((int) $post_id, '_elementor_data', true)
) {

    // Legacy Elementor engine — ONLY if Elementor data exists
    $result = reeid_elementor_translate_json(
        (int) $post_id,
        (string) $source_lang,
        (string) $lang,
        (string) $tone,
        (string) $prompt
    );

} else {

    $result = [
        'success' => false,
        'message' => 'Elementor engine unavailable or no Elementor data',
    ];
}

if (empty($result['success'])) {
    $details[]      = strtoupper($lang) . ': ❌ ' . __('Elementor translation failed', 'reeid-translate');
    $results[$lang] = [
        'success' => false,
        'error'   => 'elementor_failed',
        'message' => ($result['message'] ?? ''),
    ];
    reeid__bulk_release_lock($lock_key);
    continue;
}

$title   = $result['title']   ?? $title;
$excerpt = $result['excerpt'] ?? $excerpt;
$content = $result['content'] ?? $content;
$slug    = $result['slug']    ?? $slug;

} elseif (
    $editor !== 'elementor'
    && function_exists('reeid_translate_via_openai_with_slug')
) {


                        $ctx = [
                            'tone'    => $tone,
                            'prompt'  => $prompt,
                            'title'   => $title,
                            'slug'    => $slug,
                            'excerpt' => $excerpt,
                        ];

                        $result = reeid_translate_via_openai_with_slug($content, $lang, $ctx);

                        if (empty($result['success'])) {
                            $details[]      = strtoupper($lang) . ': ❌ ' . __('Translation failed', 'reeid-translate');
                            $results[$lang] = ['success' => false, 'error' => 'translation_failed', 'message' => ($result['message'] ?? '')];
                            reeid__bulk_release_lock($lock_key);
                            continue;
                        }

                        $title   = $result['title']   ?? $title;
                        $content = $result['content'] ?? $content;
                        $excerpt = $result['excerpt'] ?? $excerpt;
                        $slug    = $result['slug']    ?? $slug;
                    }

                    if (! function_exists('reeid_wc_store_translation_meta')) {
                        $details[]      = strtoupper($lang) . ': ❌ ' . __('Storage unavailable', 'reeid-translate');
                        $results[$lang] = ['success' => false, 'error' => 'storage_unavailable'];
                        reeid__bulk_release_lock($lock_key);
                        continue;
                    }

                    $payload = [
                        'title'   => (string) $title,
                        'content' => (string) $content,
                        'excerpt' => (string) $excerpt,
                        'slug'    => (string) $slug,
                        'updated' => gmdate('c'),
                        'editor'  => $editor,
                    ];

                    $ok = reeid_wc_store_translation_meta($post_id, $lang, $payload);

                    $map[$lang] = $post_id;

                    $details[]      = strtoupper($lang) . ': ✅ ' . __('Stored inline', 'reeid-translate');
                    $results[$lang] = ['success' => (bool) $ok, 'inline' => true, 'post_id' => $post_id];

                    reeid__bulk_release_lock($lock_key);
                    continue;
                }

/* ---------- Default (non-product) path ---------- */

                $tid     = isset($map[$lang]) ? (int) $map[$lang] : 0;
                $tr_post = $tid ? get_post($tid) : null;

                if (! $tr_post || in_array(($tr_post->post_status ?? ''), ['trash', 'auto-draft'], true)) {
                    $tid = wp_insert_post([
                        'post_type'   => $root_post->post_type,
                        'post_status' => 'draft',
                        'post_title'  => $root_post->post_title,
                        'post_author' => $root_post->post_author,
                    ], true);

                    if (is_wp_error($tid)) {
                        $details[]      = strtoupper($lang) . ': ❌ ' . $tid->get_error_message();
                        $results[$lang] = ['success' => false, 'error' => 'create_failed', 'message' => $tid->get_error_message()];
                        reeid__bulk_release_lock($lock_key);
                        continue;
                    }
                }

                if ($editor === 'elementor') {

                    if (function_exists('reeid_elementor_translate_json_s13')) {

                        $result = reeid_elementor_translate_json_s13(
                            (int) $post_id,
                            (string) $source_lang,
                            (string) $lang,
                            (string) $tone,
                            (string) $prompt
                        );

                    } elseif (function_exists('reeid_elementor_translate_json')) {

                        $result = reeid_elementor_translate_json(
                            (int) $post_id,
                            (string) $source_lang,
                            (string) $lang,
                            (string) $tone,
                            (string) $prompt
                        );

                    } else {

                        $result = ['success' => false, 'message' => 'Elementor engine unavailable'];
                    }

                    if (empty($result['success'])) {
                        $details[]      = strtoupper($lang) . ': ❌ ' . __('Elementor translation failed', 'reeid-translate');
                        $results[$lang] = [
                            'success' => false,
                            'error'   => 'elementor_failed',
                            'message' => ($result['message'] ?? ''),
                        ];
                        reeid__bulk_release_lock($lock_key);
                        continue;
                    }


                   // --- START: elementor slug hardening ---
$title    = $result['title']   ?? $root_post->post_title;
$content  = $root_post->post_content;
$excerpt  = $result['excerpt'] ?? $root_post->post_excerpt;

// Prefer API-provided slug; otherwise derive from translated title (or source title)
// and sanitize to native script if helper exists. Keep length conservative.
$api_slug = (isset($result['slug']) && is_string($result['slug']) && $result['slug'] !== '')
    ? trim((string) $result['slug'])
    : '';

$title_candidate = (isset($result['title']) && is_string($result['title']) && $result['title'] !== '')
    ? (string) $result['title']
    : (string) $root_post->post_title;
/* --- START: elementor content->title fallback (when both API title and short-translator title are empty) --- */
if ($title_candidate === '' && ! empty($result['content'])) {
    // extract first meaningful text from translated HTML content and use it as title candidate
    $raw_from_content = wp_strip_all_tags((string) $result['content']);
    if ($raw_from_content !== '') {
        // keep it short and safe for slug generation
        $title_candidate = mb_substr($raw_from_content, 0, 200);
    }
}
/* --- END: elementor content->title fallback --- */
// --- START: elementor slug/title fallback via short translator ---
if ($title_candidate === '' && function_exists('reeid_translate_via_openai_with_slug')) {
    // Build minimal ctx matching other paths
    $ctx = [
        'tone'   => $tone,
        'prompt' => $prompt,
        'title'  => (string) $root_post->post_title,
        'slug'   => '',
        'excerpt'=> (string) $root_post->post_excerpt,
    ];

    // Ask the short translator (safe, already present in plugin)
    $short_res = reeid_translate_via_openai_with_slug((string) $root_post->post_content, $lang, $ctx);

    if (! empty($short_res['success'])) {
        // prefer returned title if present
        if (! empty($short_res['title']) && is_string($short_res['title'])) {
            $title_candidate = (string) $short_res['title'];
        }
        // prefer returned slug if present (will be used later as api_slug)
        if (! empty($short_res['slug']) && is_string($short_res['slug'])) {
            $api_slug = trim((string) $short_res['slug']);
        }
    }
}
// --- END: elementor slug/title fallback via short translator ---

if ($api_slug !== '') {
    $slug_raw = $api_slug;
} else {
    $base_for_slug = $title_candidate !== '' ? $title_candidate : $root_post->post_title;
    $candidate = mb_substr(wp_strip_all_tags((string) $base_for_slug), 0, 60);
/* --- START: elementor final slug-from-content fallback --- */
/*
 If earlier slug logic produced an ASCII fallback (copied source slug), but the
 translated HTML (post content) contains native-script characters, derive a new
 native slug from the translated content here just before we call wp_update_post().
 This avoids race and API-format differences for Elementor flows.
*/
$maybe_content = isset($new_post['post_content']) ? wp_strip_all_tags((string) $new_post['post_content']) : '';
// Only run when content exists and we still have a fallback-like slug.
if ($maybe_content !== '') {
    // detect if post_name appears to be the original source slug or ASCII-only
    $current_slug = (string) ($new_post['post_name'] ?? '');
    $is_ascii_slug = ($current_slug === '' || preg_match('/^[a-z0-9\-]+$/i', $current_slug));

    if ($is_ascii_slug) {
        $candidate = mb_substr($maybe_content, 0, 120);
        if (function_exists('reeid_sanitize_native_slug')) {
            $derived_slug = reeid_sanitize_native_slug($candidate);
        } else {
            $derived_slug = sanitize_title($candidate);
        }
        if ($derived_slug !== '') {
            // make unique using same parameters as earlier
            $derived_slug = wp_unique_post_slug(
                $derived_slug,
                $tid,
                $new_post['post_status'] ?? 'draft',
                $new_post['post_type'] ?? $root_post->post_type,
                $root_post->post_parent ?? 0
            );
            // trust this derived slug (overwrite fallback)
            $new_post['post_name'] = $derived_slug;
        }
    }
}
/* --- END: elementor final slug-from-content fallback --- */

    if (function_exists('reeid_sanitize_native_slug')) {
        // prefer native slug sanitizer if present
        $slug_raw = reeid_sanitize_native_slug($candidate);
    } else {
        $slug_raw = sanitize_title($candidate);
    }
}

// Ensure uniqueness immediately (use the new/inserted post id $tid)
$slug_raw = wp_unique_post_slug(
    $slug_raw,
    $tid,
    'draft',
    $root_post->post_type,
    $root_post->post_parent
);
// --- END: elementor slug hardening ---

                    if (! empty($result['data']) && function_exists('reeid_elementor_commit_post')) {
                        // Use shared committer: writes _elementor_data, sets meta, regenerates CSS
                        reeid_elementor_commit_post($tid, $result['data']);
                    }



                    $new_post = [
                        'ID'           => $tid,
                        'post_title'   => $title,
                        // Elementor: content lives in _elementor_data; keep existing post_content
                        'post_status'  => $mode,
                        'post_type'    => $root_post->post_type,
                        'post_name'    => $slug_raw,
                        'post_author'  => $root_post->post_author,
                        'post_excerpt' => $excerpt,
                    ];

                        
                    $tid2 = function_exists('reeid_safe_wp_update_post')
                        ? reeid_safe_wp_update_post($new_post, true)
                        : wp_update_post($new_post, true);

                    if (is_wp_error($tid2)) {
                        $details[]      = strtoupper($lang) . ': ❌ ' . $tid2->get_error_message();
                        $results[$lang] = ['success' => false, 'error' => 'update_failed', 'message' => $tid2->get_error_message()];
                        reeid__bulk_release_lock($lock_key);
                        continue;
                    }

                    // SEO write
                    $src_seo = $read_src_seo($post_id);
                    $src_l   = $resolve_lang($post_id, $source_lang);
                    $tgt_l   = $resolve_lang($tid2, $lang);
                    $title_tr = $src_seo['title'] !== '' ? $translate_short($src_seo['title'], $src_l, $tgt_l) : '';
                    $desc_tr  = $src_seo['desc']  !== '' ? $translate_short($src_seo['desc'],  $src_l, $tgt_l) : '';
                    $write_seo_title($tid2, $title_tr !== '' ? $title_tr : $title);
                    if ($desc_tr !== '') $write_seo_desc($tid2, $desc_tr);

                    update_post_meta($tid2, '_reeid_translation_lang', $lang);
                    update_post_meta($tid2, '_reeid_translation_source', $post_id);

                    $map[$lang]     = $tid2;
                    $details[]      = strtoupper($lang) . ': ✅ ' . __('Done', 'reeid-translate');
                    $results[$lang] = ['success' => true, 'post_id' => $tid2];

                    reeid__bulk_release_lock($lock_key);
                    continue;
                } else {
                    // Classic / Gutenberg branch
                    $title    = $root_post->post_title;
                    $content  = $root_post->post_content;
                    $excerpt  = $root_post->post_excerpt;
                    $slug_raw = $root_post->post_name;

                    // Extractor path — prompt-aware
                    if (function_exists('reeid_gutenberg_classic_translate_via_extractor')) {
                        $content_tr = reeid_gutenberg_classic_translate_via_extractor($root_post->post_content, $lang, $tone, $prompt);
                        if (is_string($content_tr) && $content_tr !== '') {
                            $content = $content_tr;
                        }
                    }

                    // Short-text translator — prompt-aware and using $source_lang
                    if (function_exists('reeid_translate_html_with_openai')) {
                        $t = reeid_translate_html_with_openai($root_post->post_title,   $source_lang, $lang, $editor, $tone, $prompt);
                        if (is_string($t) && $t !== '') $title = $t;

                        $e = reeid_translate_html_with_openai($root_post->post_excerpt, $source_lang, $lang, $editor, $tone, $prompt);
                        if (is_string($e) && $e !== '') $excerpt = $e;
                    }

                    // Slug uniqueness
                    // Prefer native base when title is native but slug_raw is ASCII/fallback
                    $base = $slug_raw;
                    if ($base === '' || (preg_match('/[^\x00-\x7F]/u', (string)$title) && !preg_match('/[^\x00-\x7F]/u', (string)$base))) {
                        $base = $title;
                    }
                    $slug_final = reeid_sanitize_native_slug($base);
                    $slug_final = wp_unique_post_slug(
                        $slug_final,
                        $tid,
                        $mode === 'publish' ? 'publish' : 'draft',
                        $root_post->post_type,
                        $root_post->post_parent
                    );

                    $new_post = [
                        'ID'           => $tid,
                        'post_title'   => $title,
                        'post_content' => $content,
                        'post_status'  => $mode,
                        'post_type'    => $root_post->post_type,
                        'post_name'    => $slug_final,
                        'post_author'  => $root_post->post_author,
                        'post_excerpt' => $excerpt,
                    ];
                    $tid2 = function_exists('reeid_safe_wp_update_post')
                        ? reeid_safe_wp_update_post($new_post, true)
                        : wp_update_post($new_post, true);

                    if (is_wp_error($tid2)) {
                        $details[]      = strtoupper($lang) . ': ❌ ' . $tid2->get_error_message();
                        $results[$lang] = ['success' => false, 'error' => 'update_failed', 'message' => $tid2->get_error_message()];
                        reeid__bulk_release_lock($lock_key);
                        continue;
                    }

                    // WooCommerce attribute translation (if product)
                    if (
                        get_post_type($tid2) === 'product' &&
                        class_exists('WC_Product') &&
                        function_exists('reeid_translate_product_attributes')
                    ) {
                        $src_product = wc_get_product($post_id);
                        $dst_product = wc_get_product($tid2);
                        $src_l = $source_lang;
                        $dst_l = $lang;
                        if ($src_product && $dst_product) {
                            if (empty($tone)) $tone = 'neutral';
                            reeid_translate_product_attributes($src_product, $dst_product, $src_l, $dst_l, $tone);
                        }
                    }

                    // SEO write
                    $src_seo = $read_src_seo($post_id);
                    $src_l   = $resolve_lang($post_id, $source_lang);
                    $tgt_l   = $resolve_lang($tid2, $lang);
                    $title_tr = $src_seo['title'] !== '' ? $translate_short($src_seo['title'], $src_l, $tgt_l) : '';
                    $desc_tr  = $src_seo['desc']  !== '' ? $translate_short($src_seo['desc'],  $src_l, $tgt_l) : '';
                    $write_seo_title($tid2, $title_tr !== '' ? $title_tr : $title);
                    if ($desc_tr !== '') $write_seo_desc($tid2, $desc_tr);

                    update_post_meta($tid2, '_reeid_translation_lang', $lang);
                    update_post_meta($tid2, '_reeid_translation_source', $post_id);

                    $map[$lang]     = $tid2;
                    $details[]      = strtoupper($lang) . ': ✅ ' . __('Done', 'reeid-translate');
                    $results[$lang] = ['success' => true, 'post_id' => $tid2];

                    reeid__bulk_release_lock($lock_key);
                    continue;
                }
            } // end foreach $selected

            /* ---------- Persist final map on SOURCE (single clean merge) ---------- */
            if (! function_exists('reeid_sanitize_translation_map')) {
                function reeid_sanitize_translation_map($m)
                {
                    if (! is_array($m)) return [];
                    $out = [];
                    foreach ($m as $k => $v) {
                        if (! is_string($k) || ! preg_match('/^[a-zA-Z_-]+$/', $k)) continue;
                        if (is_numeric($v) && (int)$v > 0) {
                            $out[$k] = (int)$v;
                        }
                    }
                    return $out;
                }
            }

            $existing_map = (array) get_post_meta($post_id, '_reeid_translation_map', true);
            $existing_map = reeid_sanitize_translation_map($existing_map);
            $map          = is_array($map) ? reeid_sanitize_translation_map($map) : [];
            $merged_map   = array_merge($existing_map, $map);
            update_post_meta($post_id, '_reeid_translation_map', $merged_map);

            wp_send_json_success([
                'message' => __('Bulk translations completed', 'reeid-translate'),
                'details' => $details,
                'results' => $results,
            ]);
        }
    endif;


   /* ========================================================================
   Generates nonce only when WordPress admin is fully loaded
   and injects the inline JS via admin_enqueue_scripts.
========================================================================*/
if ( ! function_exists( 'reeid_admin_validate_key_script' ) ) {
    function reeid_admin_validate_key_script( $hook ) {

        // Only on our settings page (best-effort).
        if ( 'settings_page_reeid-translate-settings' !== $hook ) {
            return;
        }

        // Ensure pluggable is available.
        if ( ! function_exists( 'wp_create_nonce' ) ) {
            return;
        }

        $nonce = wp_create_nonce( 'reeid_translate_nonce_action' );

        // Build inline JS without HEREDOC — required for WP.org repo.
        $js  = "jQuery(function(){";
        $js .= "var statusBox=document.getElementById('reeid_openai_key_status');";
        $js .= "var btn=document.getElementById('reeid_validate_openai_key');";
        $js .= "if(btn){btn.addEventListener('click',function(e){";
        $js .= "e.preventDefault();";
        $js .= "if(statusBox){statusBox.innerHTML='⏳ Validating...';}";
        $js .= "var keyEl=document.getElementById('reeid_openai_api_key');";
        $js .= "var key=keyEl?keyEl.value:'';";
        $js .= "fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},";
        $js .= "body:new URLSearchParams({";
        $js .= "action:'reeid_validate_openai_key',";
        $js .= "key:key,";
        $js .= "_wpnonce:'" . esc_js( $nonce ) . "',";
        $js .= "_ajax_nonce:'" . esc_js( $nonce ) . "'";
        $js .= "})";
        $js .= "}).then(function(res){return res.json();})";
        $js .= ".then(function(data){";
        $js .= "if(statusBox){";
        $js .= "if(data && data.success){";
        $js .= "statusBox.innerHTML='<span style=\"color:green;font-weight:bold;\">✔ Valid API Key</span>';";
        $js .= "}else{";
        $js .= "statusBox.innerHTML='<span style=\"color:red;font-weight:bold;\">❌ Invalid API Key</span>';";
        $js .= "}";
        $js .= "}";
        $js .= "}).catch(function(){";
        $js .= "if(statusBox){statusBox.innerHTML='<span style=\"color:red;\">❌ AJAX failed</span>';}";
        $js .= "});";
        $js .= "});}";
        $js .= "});";

        // Register an inert script handle and attach inline JS.
        wp_register_script( 'reeid-validate-key', false, array(), REEID_TRANSLATE_VERSION, true );
        wp_enqueue_script( 'reeid-validate-key' );
        wp_add_inline_script( 'reeid-validate-key', $js );

        // Safely enqueue elementor wiring script
        $ver = defined( 'REEID_TRANSLATE_VERSION' ) ? REEID_TRANSLATE_VERSION : '1.0.0';
        wp_register_script(
            'reeid-elementor-wires',
            REEID_TRANSLATE_URL . 'assets/js/reeid-elementor-wires.js',
            array( 'jquery', 'reeid-translate-localize' ),
            $ver,
            true
        );
        wp_enqueue_script( 'reeid-elementor-wires' );
    }
    add_action( 'admin_enqueue_scripts', 'reeid_admin_validate_key_script', 20 );
}
