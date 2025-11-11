<?php
declare(strict_types=1);

// ---- DROP-IN: performance helpers -----------------------------------------

/** Micro-profiler */
function reeid_t($k = null) {
    static $t = [];
    if ($k !== null) $t[$k] = microtime(true);
    return $t;
}

/** Real parallel HTTP (curl_multi) with small pool */
function reeid_post_multi(array $jobs, int $concurrency = 6, int $timeout = 25): array {
    if (empty($jobs)) return [];

    $mh = curl_multi_init();
    $handles = [];
    $queue = $jobs;
    $results = [];
    $active = 0;

    // local helper to add a job
    $add = function() use (&$queue, $mh, &$handles, $timeout) {
        if (!$queue) return;
        $job = array_shift($queue);

        // Basic validation of job shape
        if (!isset($job['url']) || !isset($job['id']) || !isset($job['body'])) {
            error_log('[REEID] post_multi: malformed job (missing url/id/body)');
            return;
        }

        $ch = curl_init($job['url']);

// --- REEID LOCAL INTERCEPT (raw cURL -> local translate) ---
$__reeid_fake = null;
try {
    $u = isset($job['url']) ? strtolower((string)$job['url']) : '';
    $is_oai = (strpos($u,'api.openai.com/v1/chat/completions')!==false)
           || (strpos($u,'api.openai.com/v1/responses')!==false)
           || (strpos($u,'api.reeid.com/v1/chat/completions')!==false)
           || (strpos($u,'api.reeid.com/v1/responses')!==false);

    if ($is_oai && function_exists('reeid_openai_translate_single')) {
        // The batching code usually passes JSON in $job['body'] or $job['payload'].
        $raw = null;
        if     (isset($job['body']))    { $raw = is_string($job['body']) ? $job['body'] : json_encode($job['body']); }
        elseif (isset($job['payload'])) { $raw = is_string($job['payload']) ? $job['payload'] : json_encode($job['payload']); }
        $pl = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);

        if (is_array($pl)) {
            // Extract user text and target language from either shape
            $user_text = '';
            $sys_text  = '';
            $instr     = '';
            if (!empty($pl['messages']) && is_array($pl['messages'])) {
                foreach ($pl['messages'] as $m) {
                    if (($m['role'] ?? '') === 'user'   && isset($m['content'])) { $user_text = (string)$m['content']; }
                    if (($m['role'] ?? '') === 'system' && isset($m['content'])) { $sys_text  = (string)$m['content']; }
                }
            }
            if ($user_text === '' && isset($pl['input'])) {
                if (is_string($pl['input'])) { $user_text = $pl['input']; }
                elseif (is_array($pl['input'])) { $user_text = json_encode($pl['input'], JSON_UNESCAPED_UNICODE); }
            }
            if (isset($pl['instructions']) && is_string($pl['instructions'])) {
                $instr = $pl['instructions'];
            }

            if ($user_text !== '') {
                // Guess target language from prompt text
                $hint   = $sys_text.' '.$instr;
                $target = 'en';
                if     (preg_match('/\bto\s+([a-z]{2,5})\b/i', $hint, $mm))       { $target = strtolower($mm[1]); }
                elseif (preg_match('/\binto\s+([a-z]{2,5})\b/i', $hint, $mm))     { $target = strtolower($mm[1]); }
                elseif (preg_match('/\bDeutsch\b/i', $hint))                      { $target = 'de'; }
                elseif (preg_match('/\bGerman\b/i', $hint))                       { $target = 'de'; }
                elseif (preg_match('/\bHindi\b/i', $hint))                        { $target = 'hi'; }

                $tone = 'Neutral';
                if (preg_match('/\bTone:\s*([A-Za-z]+)/', $hint, $tt)) { $tone = $tt[1]; }

                $translated = reeid_openai_translate_single($user_text, $target, $tone);

                if (strpos($u, '/responses') !== false) {
                    $__reeid_fake = json_encode([
                        'id'      => 'resp_local_'.(function_exists('wp_generate_uuid4')?wp_generate_uuid4():uniqid()),
                        'object'  => 'response',
                        'created' => time(),
                        'model'   => $pl['model'] ?? 'gpt-4o',
                        'output'  => [[
                            'id' => 'msg_1',
                            'type' => 'message',
                            'role' => 'assistant',
                            'content' => [['type'=>'output_text','text'=>$translated]],
                        ]],
                        'usage' => ['prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0],
                    ], JSON_UNESCAPED_UNICODE);
                } else {
                    $__reeid_fake = json_encode([
                        'id'      => 'chatcmpl_local_'.(function_exists('wp_generate_uuid4')?wp_generate_uuid4():uniqid()),
                        'object'  => 'chat.completion',
                        'created' => time(),
                        'model'   => $pl['model'] ?? 'gpt-4o',
                        'choices' => [[
                            'index'   => 0,
                            'message' => ['role'=>'assistant','content'=>$translated],
                            'finish_reason' => 'stop',
                        ]],
                        'usage' => ['prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0],
                    ], JSON_UNESCAPED_UNICODE);
                }
            }
        }
    }
} catch (\Throwable $e) {
    // swallow; fall back to normal cURL if anything goes wrong
}
// --- /REEID LOCAL INTERCEPT ---
        // encode JSON body (check for errors)
        $jsonBody = json_encode($job['body'], JSON_UNESCAPED_UNICODE);
        if ($jsonBody === false) {
            error_log('[REEID] post_multi: json_encode failed for job ' . (string)$job['id']);
            // still enqueue an empty payload so we can capture and report server-side error
            $jsonBody = json_encode([]);
        }

        $headers = array_merge(['Content-Type: application/json'], $job['headers'] ?? []);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_ENCODING       => '',                 // accept all encodings
            CURLOPT_FOLLOWLOCATION => false,
            // don't force HTTP/2 here; leave curl choose best available
        ]);

        $handles[(int)$ch] = $job; // keep meta for mapping
        curl_multi_add_handle($mh, $ch);
    };

    // prime pool
    for ($i = 0; $i < $concurrency && !empty($queue); $i++) $add();

    do {
        $mrc = curl_multi_exec($mh, $active);
        // read completed handles
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $key = (int)$ch;
            $meta = $handles[$key] ?? null;

            if ($meta === null) {
                // Unexpected: unknown handle
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                continue;
            }

            $body = curl_multi_getcontent($ch);
            $err  = curl_error($ch);
            $http = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: curl_getinfo($ch, CURLINFO_HTTP_CODE);

            $results[$meta['id']] = $err
                ? ['error' => $err, 'meta' => $meta, 'http_code' => $http, 'body' => $body]
                : ['ok' => true, 'body' => $body, 'meta' => $meta, 'http_code' => $http];

            unset($handles[$key]);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            // keep pipeline full
            $add();
        }

        // curl_multi_select can return -1 on some platforms; fallback to small usleep
        $select = curl_multi_select($mh, 0.05);
        if ($select === -1) {
            usleep(10000); // 10ms
        }
    } while ($active || !empty($handles));

    curl_multi_close($mh);
    return $results;
}

/** Simple batcher: packs strings into ~targetChars chunks to reduce request count */
function reeid_batch_segments(array $segments, int $targetChars = 800): array {
    $batches = [];
    $current = [];
    $len = 0;
    foreach ($segments as $i => $s) {
        $s = (string)$s;
        if ($len && ($len + strlen($s) + 1) > $targetChars) {
            $batches[] = $current; $current = []; $len = 0;
        }
        $current[] = ['i' => $i, 'text' => $s];
        $len += strlen($s) + 1;
    }
    if ($current) $batches[] = $current;
    return $batches;
}

/** Normalize for dedupe hash */
function reeid_norm(string $s): string {
    $s = preg_replace('/\s+/u', ' ', trim($s));
    return mb_strtolower($s, 'UTF-8');
}

// ---- DROP-IN: main fast translate for Gutenberg content --------------------

/**
 * Fast translate for Gutenberg post content.
 * - $apiUrl: your translate endpoint
 * - $apiHeaders: e.g. ['Authorization: Bearer ...']
 * - $extractCb($rawContent): returns array of translatable leaf strings (in order) + a rehydrate callback
 *   The extractor must return: ['segments'=>[...], 'rehydrate'=>callable($translatedSegments): string]
 */
function reeid_translate_gutenberg_fast(int $postId, string $apiUrl, array $apiHeaders, callable $extractCb, string $srcLang, string $tgtLang): array {
    global $wpdb;

    // 0) Ensure DB is ready once, up-front (prevents per-call reconnect storms)
    if (method_exists($wpdb, 'check_connection')) {
        $wpdb->check_connection(true);
    }

    reeid_t('start');

    // 1) Extract → get segments + a rehydrator we can call later
    $raw = get_post_field('post_content', $postId, 'raw');
    $ex  = $extractCb($raw);

    if (!is_array($ex) || !isset($ex['segments']) || !isset($ex['rehydrate'])) {
        error_log('[REEID] extractor returned invalid shape for post ' . (string)$postId);
        return ['ok' => false, 'errors' => [['error' => 'extractor_invalid']]];
    }

    $segments = (array)$ex['segments'];           // array of strings in document order
    $rehydrate = $ex['rehydrate'];                // function(array $translated) => string

    // 2) De-dupe to reduce API volume
    $uniq = []; $revMap = [];              // key => idx in uniq; and docIndex => uniqIndexKey
    foreach ($segments as $idx => $s) {
        if ($s === '' || ctype_space((string)$s)) { $revMap[$idx] = null; continue; }
        $k = md5(reeid_norm((string)$s) . "\0" . $srcLang . "\0" . $tgtLang);
        if (!array_key_exists($k, $uniq)) $uniq[$k] = ['text' => (string)$s, 'key' => $k];
        $revMap[$idx] = $k;
    }
    $uniqList = array_values($uniq);
    reeid_t('after_extract');

    // Build mapping from uniq-key => pos in uniqList
    $uniqKeyToPos = [];
    foreach ($uniqList as $pos => $row) {
        $uniqKeyToPos[$row['key']] = $pos;
    }

    // 3) Batch the unique segments (≈800 chars each) to cut request count
    $uniqTexts = array_map(fn($r) => $r['text'], $uniqList);
    $batches   = reeid_batch_segments($uniqTexts, 800);

    // 4) Build jobs for true parallel calls (each job = one batch)
    $jobs = []; $batchId = 0;
    foreach ($batches as $batch) {
        $payload = [
            'source_lang' => $srcLang,
            'target_lang' => $tgtLang,
            'texts'       => array_map(fn($row) => $row['text'], $batch),
        ];
        $jobs[] = [
            'id'      => 'b' . $batchId++,
            'url'     => $apiUrl,
            'headers' => $apiHeaders,
            'body'    => $payload,
            // Keep map to place responses back: positions within uniqTexts
            'map'     => array_map(fn($row) => $row['i'], $batch),
        ];
    }

    // 5) Fire them in parallel (pool size 6 is safe)
    $responses = reeid_post_multi($jobs, 6, 25);
    reeid_t('after_api');

    // 6) Assemble unique translations table
    $uniqTranslated = []; $jobErrors = [];
    foreach ($responses as $rid => $res) {
        if (empty($res['ok'])) {
            $jobErrors[] = ['id' => $rid, 'error' => $res['error'] ?? 'unknown', 'http' => $res['http_code'] ?? 0];
            continue;
        }
        $body = json_decode((string)$res['body'], true);
        if (!is_array($body) || empty($body['translations']) || !is_array($body['translations'])) {
            $jobErrors[] = ['id' => $rid, 'error' => 'bad JSON body', 'body' => substr((string)$res['body'], 0, 200)];
            continue;
        }
        $textsOut = $body['translations']; // array of strings
        $mapIdxs  = $res['meta']['map'] ?? [];  // which uniq indices these cover (in order)
        foreach ($textsOut as $j => $t) {
            $uniqTranslated[$mapIdxs[$j]] = (string)$t;
        }
    }

    // 7) Map back to full document order (reusing duplicates)
    $translatedFull = [];
    foreach ($segments as $i => $_) {
        $k = $revMap[$i] ?? null;
        if ($k === null) { $translatedFull[$i] = $_; continue; }    // empty or whitespace: keep
        $pos = $uniqKeyToPos[$k] ?? null;
        $translatedFull[$i] = ($pos !== null && isset($uniqTranslated[$pos])) ? $uniqTranslated[$pos] : $segments[$i];
    }

    // 8) Rehydrate Gutenberg content and write once
    wp_suspend_cache_invalidation(true);
    add_filter('wp_doing_autosave', '__return_false', 999);

    $newContent = call_user_func($rehydrate, $translatedFull);

    $update = [
        'ID'           => $postId,
        'post_content' => $newContent,
    ];
    reeid_safe_wp_update_post($update, true);

    remove_filter('wp_doing_autosave', '__return_false', 999);
    wp_suspend_cache_invalidation(false);

    reeid_t('after_save');
    $t = reeid_t();

    error_log(sprintf('[REEID TIMING] extract=%.3fs api=%.3fs save=%.3fs total=%.3fs',
        ($t['after_extract'] - $t['start']),
        ($t['after_api']     - $t['after_extract']),
        ($t['after_save']    - $t['after_api']),
        ($t['after_save']    - $t['start'])
    ));

    return ['ok' => empty($jobErrors), 'errors' => $jobErrors];
}
