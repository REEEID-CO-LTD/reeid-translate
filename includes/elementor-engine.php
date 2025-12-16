<?php
/**
 * Elementor Translation Engine (Remote API)
 * SECTION 14 MOVED FROM reeid-translate.php
 *
 * This file should contain the full translation engine used for Elementor pages,
 * including remote API calls, overlay handling, and error fallback.
 */



/** Overlay pass: enable during troubleshooting for stubborn body text */
if (! defined('REEID_S13_OVERLAY')) {
    define('REEID_S13_OVERLAY', false); // set to false once you're satisfied
}

/** Local logger (uploads/reeid-debug.log only) */
if (! function_exists('reeid__s13_log')) {
    function reeid__s13_log($label, $data = null)
    {
        $line = '[' . gmdate('c') . '] S13 ' . $label . ': ';
        if (is_array($data) || is_object($data)) {
            $data = wp_json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        $uploads = wp_upload_dir();
        if (! empty($uploads['basedir'])) {
            file_put_contents(trailingslashit($uploads['basedir']) . 'reeid-debug.log', $line . (string)$data . "\n", FILE_APPEND);
        }
    }
}

/** Token sanitizer for options polluted by stray HTML/whitespace */
if (! function_exists('reeid__s13_clean_token')) {
    function reeid__s13_clean_token($v): string
    {
        $v = is_string($v) ? trim($v) : '';
        if ($v === '') return '';
        $v = preg_split('/[\s<]/u', $v, 2)[0] ?? $v;
        if (preg_match('/^[A-Za-z0-9._-]{5,256}$/', $v)) return $v;
        return preg_replace('/[^A-Za-z0-9._-]/', '', $v);
    }
}

/** Helpers: API base, site host, BYOK OpenAI key, license key */
if (! function_exists('reeid__s13_api_base')) {
    function reeid__s13_api_base(): string
    {
        $opts = get_option('reeid-translate-settings', []);
        $base = '';
        if (is_array($opts) && !empty($opts['api_base'])) {
            $base = trim((string)$opts['api_base']);
        }
        if ($base === '') {
            $base = (string) get_option('reeid_api_base', 'https://api.reeid.com');
        }
        return rtrim($base, '/');
    }
}
if (! function_exists('reeid__s13_site_host')) {
    function reeid__s13_site_host(): string
    {
        if (function_exists('reeid9_site_host')) {
            $h = (string) reeid9_site_host();
            if ($h !== '') return strtolower($h);
        }
        $p = wp_parse_url(home_url('/'));
        return isset($p['host']) ? strtolower($p['host']) : 'localhost';
    }
}
if (! function_exists('reeid__s13_openai_key')) {
    function reeid__s13_openai_key(): string
    {
        $opts = get_option('reeid-translate-settings', []);
        if (is_array($opts)) {
            foreach (['openai_api_key', 'openai', 'api_key', 'reeid_openai_api_key'] as $k) {
                if (!empty($opts[$k])) return reeid__s13_clean_token($opts[$k]);
            }
        }
        $k = get_option('reeid_openai_api_key', '');
        return reeid__s13_clean_token($k);
    }
}
if (! function_exists('reeid__s13_license_key')) {
    function reeid__s13_license_key(): string
    {
        $opts = get_option('reeid-translate-settings', []);
        if (is_array($opts)) {
            foreach (['license_key', 'license', 'key', 'reeid_license_key', 'reeid_license', 'api_license'] as $k) {
                if (!empty($opts[$k])) return reeid__s13_clean_token($opts[$k]);
            }
        }
        $k = get_option('reeid_license_key', '');
        return reeid__s13_clean_token($k);
    }
}

/** Minimal collector: EVERY non-URL string under any ".settings." */
if (! function_exists('reeid__s13_collect_map')) {
    function reeid__s13_collect_map($data): array
    {
        $out = [];
        $walk = function ($node, $path) use (&$walk, &$out) {
            if (is_array($node) || is_object($node)) {
                foreach ((array) $node as $k => $v) {
                    $new = ($path === '') ? (string)$k : $path . '.' . (string)$k;
                    $walk($v, $new);
                }
                //return;
            }
            if (is_string($node)) {
                if (
                    strpos($path, '.settings.') !== false &&
                    $node !== '' &&
                    !preg_match('/^(https?:|mailto:|tel:|data:|#)/i', $node)
                ) {
                    $out[$path] = $node;
                }
            }
        };
        $walk($data, '');
        return $out;
    }
}

/** Get a value by dot-path (for before/after checks) */
if (! function_exists('reeid__s13_get_by_path')) {
    function reeid__s13_get_by_path($root, $path, &$exists = false)
    {
        $parts = explode('.', (string) $path);
        $ref   = $root;
        foreach ($parts as $p) {
            if (is_array($ref) && array_key_exists($p, $ref)) {
                $ref = $ref[$p];
            } elseif (is_object($ref) && isset($ref->$p)) {
                $ref = $ref->$p;
            } else {
                $exists = false;
                return null;
            }
        }
        $exists = true;
        return $ref;
    }
}

/** Internal: walk & replace (prefers your helper; returns bool for diagnostics) */
if (! function_exists('reeid__s13_walk_and_replace')) {
    function reeid__s13_walk_and_replace(&$data, $path, $new): bool
    {
        $had = false;
        $before = reeid__s13_get_by_path($data, $path, $had);
        if (! $had) {
            if (function_exists('reeid_elementor_walk_and_replace')) {
                reeid_elementor_walk_and_replace($data, (string)$path, $new);
                $after = reeid__s13_get_by_path($data, $path, $had);
                return $had && $after !== $before;
            }
            return false;
        }
        $parts = explode('.', (string) $path);
        $ref   = &$data;
        foreach ($parts as $p) {
            if (is_array($ref) && array_key_exists($p, $ref)) {
                $ref = &$ref[$p];
            } elseif (is_object($ref) && isset($ref->$p)) {
                $ref = &$ref->$p;
            } else {
                return false;
            }
        }
        $ref = $new;
        return true;
    }
}

/** Diff helper: compare maps to see how many strings changed */
if (! function_exists('reeid__s13_diff_maps')) {
    function reeid__s13_diff_maps(array $before, array $after): array
    {
        $common = array_intersect_key($before, $after);
        $changed = 0;
        $examples = [];
        foreach ($common as $k => $v) {
            if ((string)$v !== (string)$after[$k]) {
                $changed++;
                if (count($examples) < 8) {
                    $examples[] = $k;
                }
            }
        }
        return ['total_before' => count($before), 'total_after' => count($after), 'changed' => $changed, 'examples' => $examples];
    }
}

/** Per-string remote HTML translator (still via api.reeid.com) */
if (! function_exists('reeid__s13_remote_html')) {
    /* >>> INJECTION START: add $prompt param (BC-safe default) */
    function reeid__s13_remote_html(string $source_lang, string $target_lang, string $html, string $tone = 'Neutral', string $prompt = ''): string
    {

        $payload = [
            'license_key' => reeid__s13_license_key(),
            'domain'      => reeid__s13_site_host(),
            'editor'      => 'classic',
            'mode'        => 'single',
            'source_lang' => $source_lang,
            'target_lang' => $target_lang,
            'content'     => [
                'title' => '',
                'html'  => $html
            ],
            'options'     => [
                'tone'           => $tone ?: 'Neutral',
                'slug_policy'    => 'none',
                'seo_profile'    => 'default',
                'return_paths'   => false,

                // **Single, canonical prompt forwarding**
                'custom_prompt'  => (string)($prompt ?? ''),
            ],
            'openai_key'  => reeid__s13_openai_key(),
        ];

$api = apply_filters(
    'reeid_translate_api_url',
    reeid__s13_api_base() . '/v1/translate'
);
        $res = wp_remote_post($api, [
            'timeout'     => 45,
            'headers'     => ['Content-Type' => 'application/json; charset=utf-8', 'X-REEID-Client' => 'wp-plugin-universal/1.7'],
            'body'        => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'data_format' => 'body',
        ]);
        if (is_wp_error($res)) return '';
        if ((int) wp_remote_retrieve_response_code($res) !== 200) return '';
        $j = json_decode((string) wp_remote_retrieve_body($res), true);
        if (! is_array($j) || empty($j['translation']) || ! is_array($j['translation'])) return '';
        return (string) ($j['translation']['html'] ?? '');
    }
}
/** Batch-translate many HTML fragments (still via api.reeid.com) */
if (! function_exists('reeid__s13_remote_html_batch')) {
    function reeid__s13_remote_html_batch(string $source_lang, string $target_lang, array $path_to_html, string $tone = 'Neutral', string $prompt = ''): array
    {

        // 1) sanitize input map
        $clean = [];
        foreach ($path_to_html as $p => $html) {
            if (is_string($p) && is_string($html)) {
                $html = trim($html);
                if ($html !== '') {
                    $clean[(string)$p] = $html;
                }
            }
        }
        if (empty($clean)) {
            return [];
        }

        // 2) payload for "classic" strings channel + prompt forwarding
        $payload = [
            'license_key' => reeid__s13_license_key(),
            'domain'      => reeid__s13_site_host(),
            'editor'      => 'classic',
            'mode'        => 'single',
            'source_lang' => (string)$source_lang,
            'target_lang' => (string)$target_lang,
            'content'     => [
                'title'   => '',
                'html'    => '',
                'strings' => $clean
            ],
            'options'     => [
                'tone'           => $tone ?: 'Neutral',
                'slug_policy'    => 'none',
                'seo_profile'    => 'default',
                'return_paths'   => true,
                'prefer_channel' => 'strings',

                // **Single, canonical prompt forwarding**
                'custom_prompt'  => (string)($prompt ?? ''),
            ],
            'openai_key'  => reeid__s13_openai_key(),
            'debug'       => ['echo' => true],
        ];


        // 3) call
$api = apply_filters(
    'reeid_translate_api_url',
    reeid__s13_api_base() . '/v1/translate'
);
        $res = wp_remote_post($api, [
            'timeout'     => 60,
            'headers'     => [
                'Content-Type'   => 'application/json; charset=utf-8',
                'X-REEID-Client' => 'wp-plugin-universal/1.7',
            ],
            'body'        => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'data_format' => 'body',
        ]);

        if (is_wp_error($res)) {
            return [];
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200) {
            return [];
        }

        $j = json_decode((string) wp_remote_retrieve_body($res), true);
        if (! is_array($j) || empty($j['translation']) || ! is_array($j['translation'])) {
            return [];
        }
        $tr = $j['translation'];
        return (isset($tr['strings']) && is_array($tr['strings'])) ? $tr['strings'] : [];
    }
}

/**
 * Main S13 entry (used by SECTION 16 AJAX)
 */
if (! function_exists('reeid_elementor_translate_json_s13')) {
    function reeid_elementor_translate_json_s13(int $post_id, string $source_lang, string $target_lang, string $tone = 'Neutral', string $prompt = ''): array
    {

        // 0) Post + Elementor JSON
        $post = get_post($post_id);
        if (! $post) {
            return ['success' => false, 'error' => __('Post not found', 'reeid-translate'), 'where' => 'no_post'];
        }
        $raw = function_exists('reeid_get_sanitized_elementor_data')
            ? reeid_get_sanitized_elementor_data($post_id)
            : get_post_meta($post_id, '_elementor_data', true);
        if (empty($raw)) {
            return ['success' => false, 'error' => __('No Elementor data to translate', 'reeid-translate'), 'where' => 'no_elementor'];
        }
        $elem = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (! is_array($elem)) {
            return ['success' => false, 'error' => __('Bad Elementor JSON', 'reeid-translate'), 'where' => 'json_decode'];
        }
        $elem_before = $elem; // keep original for diff

        // --- Extract no-translate terms from the prompt: "do not translate: a, b, c"
        $reeid_no_tr_terms = [];
        if (isset($prompt) && is_string($prompt) && $prompt !== '') {
            if (preg_match('/do\s*not\s*translate\s*:\s*(.+)$/imu', $prompt, $m)) {
                // split by comma or semicolon or pipe
                $parts = preg_split('/\s*[;,|]\s*/u', (string)$m[1]);
                foreach ($parts as $t) {
                    $t = trim($t);
                    if ($t !== '') {
                        $reeid_no_tr_terms[] = $t;
                    }
                }
                // dedupe, keep longest first to avoid partial masking collisions
                usort($reeid_no_tr_terms, function ($a, $b) {
                    return mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8');
                });
                $reeid_no_tr_terms = array_values(array_unique($reeid_no_tr_terms));
            }
        }

        // 1) Build path→text map
        $string_map = function_exists('reeid_elementor_walk_and_collect')
            ? (function ($e) {
                $m = [];
                reeid_elementor_walk_and_collect($e, '', $m);
                return $m;
            })($elem)
            : reeid__s13_collect_map($elem);
        $keys   = array_keys($string_map);
        $sample = array_slice($keys, 0, 15);
        reeid__s13_log('collect', ['count' => count($string_map), 'sample' => $sample]);

        // Normalize .text -> .editor
        $norm_map = [];
        $backrefs = [];
        foreach ($string_map as $path => $string) {
            $key = substr($path, strrpos($path, '.') + 1);
            if ($key === 'text') {
                $fake_path = substr($path, 0, strrpos($path, '.')) . '.editor';
                $norm_map[$fake_path] = $string;
                $backrefs[$fake_path] = $path;
            } else {
                $norm_map[$path] = $string;
            }
        }
        $string_map = $norm_map;
        // --- Build a per-path mask map & mask terms so the remote translator won't touch them
        $reeid_mask_map = []; // path => [ token => original_text, ... ]
        if (!empty($reeid_no_tr_terms)) {
            $token_i = 1;

            foreach ($string_map as $p => $txt) {
                if (!is_string($txt) || $txt === '') continue;

                $mask_for_path = [];
                $masked = $txt;

                foreach ($reeid_no_tr_terms as $term) {
                    // skip if not present
                    if (mb_stripos($masked, $term, 0, 'UTF-8') === false) continue;

                    // create a stable token per path+term (prevents translator from altering)
                    $token = "__REEID_NO_TR_" . $token_i . "__";
                    $token_i++;

                    // replace ALL occurrences (case-insensitive, safe)
                        $pattern = '/' . preg_quote($term, '/') . '/iu';
                        $masked  = preg_replace($pattern, $token, $masked);


                    // remember what to restore
                    $mask_for_path[$token] = $term;
                }

                if (!empty($mask_for_path)) {
                    $string_map[$p] = $masked;          // send masked text to API
                    $reeid_mask_map[$p] = $mask_for_path; // keep tokens → originals for unmasking
                }
            }
        }


        /* Prompt-based no-translate masking (S13) */
        $reeid_nt_tokens = [];
        if (isset($prompt) && is_string($prompt) && $prompt !== '') {
            // Extract tokens after "do not translate:"
            if (preg_match('/do\s*not\s*translate\s*:\s*(.+)$/iu', $prompt, $m)) {
                $list  = trim((string)$m[1]);
                // split by comma/semicolon or " and "
                $parts = preg_split('/\s*(?:,|;|\band\b)\s*/iu', $list);
                foreach ((array) $parts as $t) {
                    $t = trim($t, " \t\n\r\0\x0B\"'`.");
                    if ($t !== '') {
                        $reeid_nt_tokens[] = $t;
                    }
                }
            }
        }

        $reeid_nt_locks = []; 
        $__reeid_nt_i   = 0;

        if (! empty($reeid_nt_tokens)) {
            foreach ($string_map as $path => $txt) {
                $work = (string) $txt;
                foreach ($reeid_nt_tokens as $tok) {
                    if ($tok === '') {
                        continue;
                    }
                    $pattern = '/' . preg_quote($tok, '/') . '/iu';
                    if (preg_match($pattern, $work)) {
                        // Use unique placeholders so API can’t touch them.
                        $ph = '[[' . 'REEID_LOCK_' . (++$__reeid_nt_i) . ']]';
                        $new = preg_replace($pattern, $ph, $work);
                        if ($new !== null && $new !== $work) {
                            $map = $reeid_nt_locks[$path] ?? [];
                            $map[$ph] = $tok;
                            $reeid_nt_locks[$path] = $map;
                            $work = $new;
                        }
                    }
                }
                $string_map[$path] = $work;
            }
        }
       
       // 2) Payload (cleaned)
$payload = [
    'license_key' => reeid__s13_license_key(),
    'domain'      => reeid__s13_site_host(),
    'editor'      => 'elementor',
    'mode'        => 'single',
    'source_lang' => (string) $source_lang,
    'target_lang' => (string) $target_lang,
    'content'     => [
        'title'     => (string) $post->post_title,
        'html'      => '',
        'elementor' => $elem,
        'strings'   => $string_map,
        // remove content-level prompt (or keep only if server expects it)
        //'prompt'    => (string) ($prompt ?? ''),
    ],
    'options'     => [
        'tone'           => $tone ?: 'Neutral',
        'slug_policy'    => 'native',
        'seo_profile'    => 'default',
        'return_paths'   => true,
        'prefer_channel' => 'strings',
        // canonical, easy-to-log prompt fields:
        'prompt'         => (string) ($prompt ?? ''),        // short canonical spot
        'custom_prompt'  => (string) ($prompt ?? ''),        // legacy/alias
        'system'         => (string) ($prompt ?? ''),        // if server reads 'system'
    ],
    'openai_key'  => reeid__s13_openai_key(),
    // 'debug'       => ['echo' => true], // <-- keep only while actively debugging
];

        // 3) HTTP
$api = apply_filters(
    'reeid_translate_api_url',
    reeid__s13_api_base() . '/v1/translate'
);
        $res = wp_remote_post($api, [
            'timeout'     => 90,
            'headers'     => [
                'Content-Type'   => 'application/json; charset=utf-8',
                'X-REEID-Client' => 'wp-plugin-universal/1.7',
            ],
            'body'        => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'data_format' => 'body',
        ]);
        if (is_wp_error($res)) {
            return ['success' => false, 'error' => $res->get_error_message(), 'where' => 'api_http_error'];
        }
        $code    = (int) wp_remote_retrieve_response_code($res);
        $rawBody = (string) wp_remote_retrieve_body($res);
        reeid__s13_log('http', $code);
if ( defined('WP_DEBUG') && WP_DEBUG ) {
    error_log('[REEID S13] Elementor API call');
    error_log('[REEID S13] Endpoint: ' . $api);
    error_log('[REEID S13] Payload: ' . substr(wp_json_encode($payload, JSON_UNESCAPED_UNICODE), 0, 2000));
    error_log('[REEID S13] Response code: ' . $code);
    error_log('[REEID S13] Response body: ' . substr($rawBody, 0, 2000));
}

        if ($code !== 200) {
            return ['success' => false, 'error' => 'API HTTP ' . $code, 'where' => 'api_non_2xx', 'snippet' => mb_substr($rawBody, 0, 1000, 'UTF-8')];
        }

        // 4) Parse
        $json = json_decode($rawBody, true);
        if (! is_array($json)) {
            return ['success' => false, 'error' => 'API returned non-JSON', 'where' => 'api_bad_json', 'snippet' => mb_substr($rawBody, 0, 1000, 'UTF-8')];
        }
        reeid__s13_log('resp_keys', array_keys($json));
        if (isset($json['ok']) && ! $json['ok']) {
            return ['success' => false, 'error' => (string)($json['error'] ?? 'API error'), 'where' => 'api_error', 'snippet' => mb_substr($rawBody, 0, 1000, 'UTF-8')];
        }
        if (empty($json['translation']) || ! is_array($json['translation'])) {
            return ['success' => false, 'error' => 'Missing translation in API response', 'where' => 'api_bad_payload', 'snippet' => mb_substr($rawBody, 0, 1200, 'UTF-8')];
        }

             
        reeid__s13_log('resp_keys', array_keys($json));
        if (isset($json['ok']) && ! $json['ok']) {
            return ['success' => false, 'error' => (string) ($json['error'] ?? 'API error'), 'where' => 'api_error', 'snippet' => mb_substr($rawBody, 0, 1000, 'UTF-8')];
        }
        if (empty($json['translation']) || ! is_array($json['translation'])) {
            return ['success' => false, 'error' => 'Missing translation payload', 'where' => 'bad_payload', 'snippet' => mb_substr($rawBody, 0, 1200, 'UTF-8')];
        }

        // 4.x) Decode translation payload
        $tr = (array) $json['translation'];

        // === DEBUG: log raw API answer for Elementor S13 ===
        try {
            $dbg = [
                'ts'          => gmdate('c'),
                'post_id'     => $post_id,
                'source_lang' => $source_lang,
                'target_lang' => $target_lang,
                'api_title'   => isset($tr['title']) ? $tr['title'] : null,
                'api_slug'    => isset($tr['slug']['preferred']) ? $tr['slug']['preferred'] : null,
                'api_slug_all'=> isset($tr['slug']) ? $tr['slug'] : null,
            ];

            if (isset($tr['paths']) && is_array($tr['paths'])) {
                // log only first few to keep file small
                $dbg['paths_sample'] = array_slice($tr['paths'], 0, 3, true);
            }

            if ( defined('WP_DEBUG') && WP_DEBUG ) {
    error_log('[REEID S13] Debug: ' . wp_json_encode($dbg));
}

        } catch (\Throwable $e) {
            // ignore logging errors
        }
        // === /DEBUG ===

        $title_out = (string) ($tr['title'] ?? $post->post_title);
        reeid__s13_log('tr_keys', array_keys($tr));


        // 5) Build translated Elementor tree
        $data_out = null;
        if (isset($tr['strings']) && is_array($tr['strings'])) {
            $map = $tr['strings'];
            if (! empty($backrefs)) {
                foreach ($map as $p => $val) {
                    if (isset($backrefs[$p])) {
                        $real = $backrefs[$p];
                        $map[$real] = $val;
                        unset($map[$p]);
                    }
                }
            }
            foreach ($map as $p => $v) {
                if (is_string($p) && !is_array($v) && !is_object($v)) {
                    reeid__s13_walk_and_replace($elem, $p, (string)$v);
                }
            }
            $data_out = $elem;
            reeid__s13_log('strings_applied', ['count' => count($map)]);
        } else {
            foreach (['elementor', 'data', 'json', 'elementor_data', 'body'] as $k) {
                if (isset($tr[$k])) {
                    $cand = $tr[$k];
                    if (is_string($cand)) {
                        $decoded = json_decode($cand, true);
                        if (is_array($decoded)) {
                            $data_out = $decoded;
                            reeid__s13_log('used_branch', 'elementor:json');
                            break;
                        }
                    } elseif (is_array($cand)) {
                        $data_out = $cand;
                        reeid__s13_log('used_branch', 'elementor:array');
                        break;
                    }
                }
            }
        }
        if ($data_out === null && isset($tr['patch']) && is_array($tr['patch'])) {
            foreach ($tr['patch'] as $op) {
                if (is_array($op) && strtolower((string)$op['op'] ?? '') === 'replace') {
                    reeid__s13_walk_and_replace($elem, (string)($op['path'] ?? ''), $op['value'] ?? '');
                }
            }
            $data_out = $elem;
            reeid__s13_log('patch_applied', true);
        }
        if ($data_out === null) {
            $data_out = $elem;
        }
        // --- Unmask tokens in the translated output back to the original protected terms
        if (!empty($reeid_mask_map)) {
            // collect strings from $data_out
            $map_after = function_exists('reeid_elementor_walk_and_collect')
                ? (function ($e) {
                    $m = [];
                    reeid_elementor_walk_and_collect($e, '', $m);
                    return $m;
                })($data_out)
                : reeid__s13_collect_map($data_out);

            foreach ($map_after as $p => $val) {
                if (!is_string($val) || $val === '') continue;
                if (empty($reeid_mask_map[$p])) continue;

                $restored = $val;
                // restore each token for this path
                foreach ($reeid_mask_map[$p] as $token => $orig) {
                    $restored = str_replace($token, $orig, $restored);
                }

                if ($restored !== $val) {
                    reeid__s13_walk_and_replace($data_out, $p, $restored);
                }
            }
        }

        $before_map = function_exists('reeid_elementor_walk_and_collect')
            ? (function ($e) {
                $m = [];
                reeid_elementor_walk_and_collect($e, '', $m);
                return $m;
            })($elem_before)
            : reeid__s13_collect_map($elem_before);
        $after_map = function_exists('reeid_elementor_walk_and_collect')
            ? (function ($e) {
                $m = [];
                reeid_elementor_walk_and_collect($e, '', $m);
                return $m;
            })($data_out)
            : reeid__s13_collect_map($data_out);
        $diff = reeid__s13_diff_maps($before_map, $after_map);
        reeid__s13_log('diff_strings', $diff);

        // === Overlay fallback with Elementor, then HTML batch, then plain text
if ( defined('WP_DEBUG') && WP_DEBUG && REEID_S13_OVERLAY && $diff['changed'] < $diff['total_before'] ) {

            // find unchanged paths
            $unchanged = [];
            foreach ($before_map as $p => $orig) {
                if (isset($after_map[$p]) && $after_map[$p] === $orig) {
                    $unchanged[$p] = $orig;
                }
            }

            // keep only .settings.editor
            $candidates = array_filter(
                $unchanged,
                function ($v, $p) {
                    return is_string($v) && strpos($p, '.settings.editor') !== false;
                },
                ARRAY_FILTER_USE_BOTH
            );

            if (!empty($candidates)) {
                $applied = 0;

                // ------------------ Attempt 1: Elementor endpoint ------------------
                $mini_elementor = [];
                foreach ($candidates as $p => $txt) {
                    $mini_elementor[] = [
                        'elType'   => 'widget',
                        'settings' => ['editor' => (string) $txt],
                    ];
                }

                $payload = [
                    'ok'      => true,
                    'src'     => (string) $source_lang,
                    'dst'     => (string) $target_lang,
                    'editor'  => 'elementor',
                    'content' => [
                        'title'     => '',
                        'elementor' => $mini_elementor,
                    ],
                    'options' => [],
                ];

                $resp = [];
                $url  = 'https://api.reeid.com/v1/translate';
                $args = [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body'    => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'timeout' => 30,
                ];
                $http = wp_remote_post($url, $args);
                if (! is_wp_error($http)) {
                    $resp = json_decode((string) wp_remote_retrieve_body($http), true);
                }

                $overlay_tr = [];
                if (is_array($resp) && isset($resp['translation']['elementor']) && is_array($resp['translation']['elementor'])) {
                    $paths = array_keys($candidates);
                    foreach ($resp['translation']['elementor'] as $idx => $node) {
                        $tr_txt = '';
                        if (is_array($node) && isset($node['settings']['editor'])) {
                            $tr_txt = (string) $node['settings']['editor'];
                        }
                        $path = $paths[$idx] ?? null;
                        if ($path && $tr_txt !== '') {
                            $overlay_tr[$path] = $tr_txt;
                        }
                    }
                }

                $applied = 0;
                foreach ($candidates as $p => $orig) {
                    $tr_html = (string) ($overlay_tr[$p] ?? '');
                    if ($tr_html === '') {
                        continue;
                    }
                    if (reeid__s13_walk_and_replace($data_out, $p, $tr_html)) {
                        $applied++;
                    }
                }
                reeid__s13_log('overlay_elementor', ['candidates' => count($candidates), 'applied' => $applied]);

                // ------------------ Attempt 2: HTML batch ------------------
                if ($applied === 0) {
                    $orig_list = array_values($candidates);
                    $resp2     = reeid__s13_remote_html_batch((string) $source_lang, (string) $target_lang, $orig_list, (string) $tone, (string) ($prompt ?? ''));

                    if (is_array($resp2)) {
                        $idx = 0;
                        foreach ($candidates as $p => $orig) {
                            $tr_html = (string) ($resp2[$idx] ?? '');
                            $idx++;
                            if ($tr_html === '') {
                                continue;
                            }
                            if (reeid__s13_walk_and_replace($data_out, $p, $tr_html)) {
                                $applied++;
                            }
                        }
                    }
                    reeid__s13_log('overlay_html_batch', ['candidates' => count($candidates), 'applied' => $applied]);
                }

                // ------------------ Attempt 3: Plain text batch (strip tags) ------------------
                if ($applied === 0) {
                    $stripped = [];
                    foreach ($candidates as $p => $orig) {
                        $stripped[] = wp_strip_all_tags((string) $orig);
                    }

                    $resp3 = reeid__s13_remote_html_batch((string) $source_lang, (string) $target_lang, $stripped, (string) $tone, (string) ($prompt ?? ''));

                    if (is_array($resp3)) {
                        $idx = 0;
                        foreach ($candidates as $p => $orig) {
                            $plain_tr = (string) ($resp3[$idx] ?? '');
                            $idx++;
                            if ($plain_tr === '') {
                                continue;
                            }
                            if (reeid__s13_walk_and_replace($data_out, $p, $plain_tr)) {
                                $applied++;
                            }
                        }
                    }
                    reeid__s13_log('overlay_plain_text', ['candidates' => count($candidates), 'applied' => $applied]);
                }
            }
        }


        // 6) Slug
        if (isset($tr['slug']['preferred']) && is_string($tr['slug']['preferred']) && $tr['slug']['preferred'] !== '') {
            $slug_out = (string)$tr['slug']['preferred'];
        } else {
            $slug_out = function_exists('reeid_sanitize_native_slug')
                ? reeid_sanitize_native_slug($title_out)
                : sanitize_title($title_out);
        }

        return ['success' => true, 'title' => $title_out, 'slug' => $slug_out, 'data' => $data_out];
    }
}


/* -------------------------------------------------------------------------
 * ELEMENTOR — main translator (WP_HTTP transport + slug fallback)
 * ------------------------------------------------------------------------- */
function reeid_elementor_translate_json(
    int $post_id,
    string $source_lang,
    string $target_lang,
    string $tone = 'Neutral',
    string $prompt = ''
): array {
    if ( ! function_exists('wp_kses_post') ) { require_once ABSPATH . 'wp-includes/kses.php'; }

    // Elementor panel prompt isolation (no merging with globals)
    $__elem_prompt = '';
    if (is_string($prompt) && $prompt !== '') {
        $__elem_prompt = $prompt;
    } elseif (isset($_POST['prompt'])) {
        $__elem_prompt = is_string($_POST['prompt']) ? wp_kses_post( wp_unslash($_POST['prompt']) ) : '';
    }
    $prompt = $__elem_prompt;

    // Lightweight debug logger: no filesystem; logs to PHP error_log only when WP_DEBUG is true.
    $log = static function (string $code, array $meta = []) {
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            $line = gmdate('c') . ' ' . $code . ' ' . substr( wp_json_encode( $meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 0, 1200 );
            error_log( $line );
        }
    };

    $endpoint    = trim((string) get_option('reeid_api_endpoint', ''));
    $openai_key  = (string) get_option('reeid_openai_api_key', '');

    if ($endpoint === '') {
        $log('ELEM/NO_ENDPOINT', []);
        return ['success' => false, 'code' => 'endpoint_missing', 'error' => 'API endpoint not configured'];
    }

    $raw  = get_post_meta($post_id, '_elementor_data', true);
    $data = is_array($raw) ? $raw : @json_decode((string)$raw, true);
    if (!is_array($data)) {
        $log('ELEM/DATA_MISSING', ['post_id' => $post_id]);
        return ['success' => false, 'code' => 'elementor_data_missing', 'error' => 'Elementor JSON missing'];
    }

    $tone = is_string($tone) && $tone !== '' ? $tone : 'Neutral';

    $payload = [
        'editor'      => 'elementor',
        'mode'        => 'single',
        'source_lang' => (string) $source_lang,
        'target_lang' => (string) $target_lang,
        'content'     => [
            'title'     => (string) get_the_title($post_id),
            'html'      => '',
            'elementor' => $data,
        ],
        'options'     => [
            'tone'           => $tone,
            'return_paths'   => true,
            'prefer_channel' => 'elementor',
            'custom_prompt'  => (string) $prompt,
        ],
        'openai_key'  => $openai_key, // BYOK
    ];

    $res = wp_remote_post($endpoint, [
        'timeout'     => 45,
        'headers'     => [
            'Content-Type' => 'application/json; charset=utf-8',
            'Accept'       => 'application/json',
        ],
        'body'        => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'data_format' => 'body',
    ]);

    if (is_wp_error($res)) {
        $log('ELEM/WP_HTTP_ERROR', ['error' => $res->get_error_message()]);
        return ['success' => false, 'code' => 'transport_failed', 'error' => $res->get_error_message()];
    }

    $http = (int) wp_remote_retrieve_response_code($res);
    $body = (string) wp_remote_retrieve_body($res);
    if ($http < 200 || $http >= 300) {
        $log('ELEM/HTTP_NON_2XX', ['status' => $http, 'snippet' => mb_substr($body, 0, 300, 'UTF-8')]);
        return ['success' => false, 'code' => "http_$http", 'error' => 'server_error'];
    }

    $out = json_decode($body, true);
    if (!is_array($out)) {
        $maybe = json_decode(trim($body, "\" \n\r\t"), true); // double-encoded guard
        if (is_array($maybe)) { $out = $maybe; }
    }
    if (!is_array($out)) {
        $log('ELEM/BAD_JSON', ['snippet' => mb_substr($body, 0, 300, 'UTF-8')]);
        return ['success' => false, 'code' => 'bad_response', 'error' => 'non_json_response'];
    }

    // Flatten common shapes
    if (isset($out['translation']) && is_array($out['translation'])) {
        $out = array_merge($out, $out['translation']); // title, elementor/html, slug
    }
    if (empty($out['elementor']) && !empty($out['data']) && is_array($out['data'])) {
        $out['elementor'] = $out['data'];
    }
    if (!isset($out['elementor']) && isset($out['elementor_json']) && is_string($out['elementor_json'])) {
        $tmp = json_decode($out['elementor_json'], true);
        if (is_array($tmp)) { $out['elementor'] = $tmp; }
    }

    // Slug normalization & fallback from translated title (clean entities, unicode dashes, numeric dash codes)
    $slug_candidate = '';
    if (isset($out['slug'])) {
        if (is_array($out['slug']) && isset($out['slug']['preferred'])) {
            $slug_candidate = (string) $out['slug']['preferred'];
        } elseif (is_string($out['slug'])) {
            $slug_candidate = (string) $out['slug'];
        }
    }
    if ($slug_candidate === '' && !empty($out['title'])) {
        $slug_candidate = (string) $out['title'];
    }
    if ($slug_candidate !== '' && function_exists('reeid_sanitize_native_slug')) {
        // Decode any entities, strip tags
        $slug_candidate = wp_strip_all_tags( html_entity_decode($slug_candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8') );
        // Normalize unicode dash variants → "-"
        $slug_candidate = preg_replace('/[\x{2010}\x{2011}\x{2012}\x{2013}\x{2014}\x{2212}]+/u', '-', $slug_candidate);
        // Fix numeric artifacts that sometimes leak from HTML entities
        $slug_candidate = preg_replace('/\b(8210|8211|8212|8722)\b/u', '-', $slug_candidate);
        // Collapse spaces and hyphen runs
        $slug_candidate = preg_replace('/\s+/u', ' ', $slug_candidate);
        $slug_candidate = preg_replace('/-{2,}/', '-', $slug_candidate);
        $slug_candidate = trim($slug_candidate, " \t\n\r\0\x0B-");
        // **Lowercase ASCII only** (non-ASCII untouched)
        $slug_candidate = preg_replace_callback('/[A-Z]+/', static function($m){ return strtolower($m[0]); }, $slug_candidate);
        // Final native-script slug sanitize
        $out['slug'] = reeid_sanitize_native_slug($slug_candidate);
    }

    if (!empty($out['elementor']) && is_array($out['elementor'])) {
        return [
            'success' => true,
            'title'   => isset($out['title']) && is_string($out['title']) ? $out['title'] : '',
            'data'    => $out['elementor'],
            'html'    => isset($out['html']) && is_string($out['html']) ? $out['html'] : '',
            'slug'    => isset($out['slug']) && is_string($out['slug']) ? $out['slug'] : '',
        ];
    }

    $api_code = isset($out['code']) && is_string($out['code']) ? $out['code'] : 'remote_failed';
    $api_err  = isset($out['error']) && is_string($out['error']) ? $out['error'] : 'remote_failed';
    $log('ELEM/API_FAIL', ['code' => $api_code, 'error' => $api_err]);
    return ['success' => false, 'code' => $api_code, 'error' => $api_err];
}


/**
 * REEID — Elementor translation bridge
 * This action is intentionally lightweight.
 * It only delegates to the existing Elementor translation engine.
 */
add_action( 'reeid/elementor/translate', function ( array $payload ) {

    if ( empty( $payload['post_id'] ) || ! is_numeric( $payload['post_id'] ) ) {
        return;
    }

    $post_id     = (int) $payload['post_id'];
    $target_lang = isset( $payload['target_lang'] ) ? (string) $payload['target_lang'] : '';
    $source_lang = isset( $payload['source_lang'] ) ? (string) $payload['source_lang'] : '';
    $tone        = isset( $payload['tone'] ) ? (string) $payload['tone'] : '';
    $prompt      = isset( $payload['prompt'] ) ? (string) $payload['prompt'] : '';

    reeid_elementor_translate_json_s13(
        $post_id,
        $target_lang,
        $source_lang,
        $tone,
        $prompt
    );
});
