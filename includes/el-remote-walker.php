<?php
/**
 * Remote-aware Elementor commit & walker helper for REEID Translate
 *
 * Usage:
 *   reeid_elementor_rewrite_via_remote_walker( int $post_id, string $source_lang, string $target_lang [, array $opts = [] ] );
 *
 * NOTE: requires plugin options:
 *   - 'reeid_api_endpoint'   => e.g. 'https://api.reeid.com/v1/walkers/elementor-rules'
 *   - 'reeid_api_key'        => public key (header)
 *   - 'reeid_api_secret'     => secret used to sign payload (HMAC-SHA256). If you use libsodium, adapt signing here.
 */

if (! function_exists('reeid_elementor_rewrite_via_remote_walker')) {
    function reeid_elementor_rewrite_via_remote_walker(int $post_id, string $source_lang, string $target_lang, array $opts = [])
    {
        // config (pull from options; fallback placeholders)
        $endpoint = get_option('reeid_api_endpoint', 'https://api.reeid.com/v1/walkers/elementor-rules');
        $api_key  = get_option('reeid_api_key', '');
        $api_secret = get_option('reeid_api_secret', '');

        // safety: small max size to avoid huge payloads
        $MAX_PAYLOAD_BYTES = isset($opts['max_payload']) ? (int)$opts['max_payload'] : 256 * 1024;

        // transient lock to avoid concurrent calls
        $lock_key = "reeid_walker_lock_{$post_id}_{$target_lang}";
        if ( get_transient($lock_key) ) {
            return new WP_Error('busy', 'Walker already running for this post');
        }
        // set short lock (10s)
        set_transient($lock_key, time(), 10);

        try {
            // gather elementor_data (source: either from $opts or target post)
            $elementor_raw = '';
            if (! empty($opts['elementor_raw']) && is_string($opts['elementor_raw'])) {
                $elementor_raw = $opts['elementor_raw'];
            } else {
                $elementor_raw = get_post_meta($post_id, '_elementor_data', true);
            }

            // fallback sanity
            if (! is_string($elementor_raw) || strlen(trim($elementor_raw)) < 10) {
                throw new Exception('Empty or invalid _elementor_data on post ' . $post_id);
            }

            // trim to max allowed bytes (if too large, try to send only 'elements' array)
            $payload_fragment = $elementor_raw;
            if (strlen($payload_fragment) > $MAX_PAYLOAD_BYTES) {
                // try to extract 'elements' array safely
                $j = json_decode($elementor_raw, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($j[0]['elements'])) {
                    $frag = ['elements' => $j[0]['elements']];
                    $payload_fragment = wp_json_encode($frag, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                } else {
                    // final fallback: abort to avoid sending huge content
                    throw new Exception('Elementor payload exceeds max size and cannot be trimmed safely');
                }
            }

            // build request body
            $body = [
                'post_id' => $post_id,
                'source'  => $source_lang,
                'target'  => $target_lang,
                'elementor' => $payload_fragment,
            ];
            if (! empty($opts['tone'])) $body['tone'] = $opts['tone'];
            $json_body = wp_json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

            // create signature (HMAC-SHA256) - adjust to libsodium if needed
            $signature = '';
            if (! empty($api_secret)) {
                $sig_raw = hash_hmac('sha256', $json_body, $api_secret, true);
                $signature = base64_encode($sig_raw); // or hex if your API expects hex
            }

            // headers
            $headers = [
                'Content-Type' => 'application/json',
            ];
            if (! empty($api_key)) $headers['X-REEID-API-KEY'] = $api_key;
            if ($signature) $headers['X-REEID-SIGN'] = $signature;

            // send request (short timeout)
            $args = [
                'body'    => $json_body,
                'headers' => $headers,
                'timeout' => 15,
                'blocking' => true,
                'sslverify' => true,
            ];

            $resp = wp_remote_post($endpoint, $args);

            if (is_wp_error($resp)) {
                throw new Exception('Remote request failed: ' . $resp->get_error_message());
            }

            $code = (int) wp_remote_retrieve_response_code($resp);
            $resp_body = wp_remote_retrieve_body($resp);

            if ($code < 200 || $code >= 300) {
                throw new Exception("Walker returned HTTP {$code}: " . substr($resp_body, 0, 400));
            }

            // parse response (expect JSON string with elementor JSON inside 'elementor' or 'result')
            $resp_j = json_decode($resp_body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Walker response JSON decode error: ' . json_last_error_msg());
            }

            // get returned elementor JSON
            $returned_elementor = '';
            if (! empty($resp_j['elementor'])) {
                // remote may return full JSON string or array
                if (is_string($resp_j['elementor'])) {
                    $returned_elementor = $resp_j['elementor'];
                } else {
                    $returned_elementor = wp_json_encode($resp_j['elementor'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                }
            } elseif (! empty($resp_j['result'])) {
                $returned_elementor = is_string($resp_j['result']) ? $resp_j['result'] : wp_json_encode($resp_j['result'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            } else {
                throw new Exception('Walker response missing elementor/result payload');
            }

            // validate returned JSON decodes and contains elements
            $test = json_decode($returned_elementor, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Returned elementor JSON invalid: ' . json_last_error_msg());
            }
            $has_elements = false;
            if (is_array($test)) {
                // naive check: top-level or index 0 contains 'elements'
                if ((isset($test['elements']) && is_array($test['elements']) && count($test['elements'])>0) ||
                    (isset($test[0]['elements']) && is_array($test[0]['elements']) && count($test[0]['elements'])>0)
                ) {
                    $has_elements = true;
                }
            }
            if (! $has_elements) {
                throw new Exception('Returned elementor JSON does not contain elements.');
            }

            // backup current target meta
            $bak_dir = WP_CONTENT_DIR . '/reeid-backups';
            @mkdir($bak_dir, 0755, true);
            $ts = gmdate('Ymd-His');
            $orig = get_post_meta($post_id, '_elementor_data', true);
            file_put_contents("{$bak_dir}/elementor_tgt_{$post_id}_orig_{$ts}.json", $orig);

            // COMMIT: write via local commit helper (preserve signature of earlier helper)
            reeid_elementor_commit_local($post_id, $returned_elementor);

            // success - cleanup lock and return success
            delete_transient($lock_key);
            return true;

        } catch (Exception $e) {
            // cleanup lock and return WP_Error
            delete_transient($lock_key);
            return new WP_Error('walker_error', $e->getMessage());
        }
    }
}

/**
 * Local commit helper: writes elementor meta + triggers Elementor regen properly.
 * Kept small and safe; can be reused by places that already wrote meta.
 */
if (! function_exists('reeid_elementor_commit_local')) {
    function reeid_elementor_commit_local(int $post_id, $elementor_raw)
    {
        // ensure string
        $json = is_string($elementor_raw) ? $elementor_raw : wp_json_encode($elementor_raw, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

        update_post_meta($post_id, '_elementor_data', wp_slash($json));
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_data_version', defined('ELEMENTOR_VERSION') ? (string) ELEMENTOR_VERSION : (string) time());

        // try Document->save with minimal arg array (avoid "Too few arguments")
        try {
            if (class_exists('\\Elementor\\Plugin') && isset(\Elementor\Plugin::instance()->documents)) {
                $docs = \Elementor\Plugin::instance()->documents;
                if (method_exists($docs, 'get')) {
                    $doc = $docs->get($post_id);
                    if ($doc && method_exists($doc, 'save')) {
                        // pass minimal payload; avoids earlier "too few args" problem
                        try {
                            $doc->save(['post_id' => $post_id]);
                        } catch (Throwable $e) {
                            // fallback: call update() if present
                            if (method_exists($doc, 'update')) {
                                $doc->update(['post_type' => get_post_type($post_id)]);
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // ignore - not fatal
        }

        // Clear caches and attempt to remove generated CSS files to force rebuild
        if (function_exists('wp_cache_flush')) wp_cache_flush();
        $css_dir = WP_CONTENT_DIR . '/uploads/elementor/css';
        if (is_dir($css_dir)) {
            $it = new DirectoryIterator($css_dir);
            foreach ($it as $fi) {
                if ($fi->isFile()) @unlink($fi->getPathname());
            }
        }
    }
}
