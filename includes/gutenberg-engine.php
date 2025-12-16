<?php
/**
 * Gutenberg / Classic Translation Engine
 * SECTION 17 MOVED FROM reeid-translate.php
 *
 * Contains:
 * - Translation mini-batch logic
 * - Reinjection of translated text
 * - Preserved attribute JSON encoding
 * - Serialization helpers
 */


/*==============================================================================
 SECTION 17 : TEXT EXTRACTOR + REINJECTOR (Gutenberg/Classic only)
 - Extract visible text lines from HTML
 - Translate line by line via OpenAI (gpt-4o)
 - Reinject preserving block structure
 - Protect inline formatting (<strong>, <em>, <i>, <u>, <mark>, bold spans…)
 - Wrap RTL output (ar/he/fa/ur)
==============================================================================*/

/*==============================================================================
  17.1 — CANONICAL OPENAI SINGLE-STRING TRANSLATOR (WP-HTTP ONLY)
==============================================================================*/
if (! function_exists('reeid_openai_translate_single')) {
    /**
     * Translate a single string using OpenAI gpt-4o.
     * 100% WP-HTTP, repo-compliant.
     */
    function reeid_openai_translate_single(
        string $text,
        string $target_lang,
        string $tone = 'Neutral',
        string $prompt = '',
        bool $return_wp_error = false
    ) {
        if (! function_exists('wp_kses_post')) {
            require_once ABSPATH . 'wp-includes/kses.php';
        }

        $api_key = (string) get_option('reeid_openai_api_key', '');
        if ($api_key === '') {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S17/NO_KEY', null);
            }
            $err = new WP_Error('no_api_key', __('OpenAI API key not configured.', 'reeid-translate'));
            return $return_wp_error ? $err : $text;
        }

        /* Build effective system prompt */
        $effective_prompt = '';
        if (is_string($prompt) && ($p = trim(wp_kses_post($prompt))) !== '') {
            $effective_prompt = $p;
        }

        if (function_exists('reeid_get_combined_prompt')) {
            $sys = reeid_get_combined_prompt(0, $target_lang, $effective_prompt);
        } else {
            $sys =
                "You are a professional translator. " .
                "Translate the source into {$target_lang} using natural, idiomatic language. " .
                "Preserve HTML tags, styling, placeholders, numbers, brand names and structure.";
            if ($effective_prompt !== '') {
                $sys .= ' ' . $effective_prompt;
            }
        }

        /* Build OpenAI chat payload (hardcoded: gpt-4o) */
        $payload = [
    'model'       => 'gpt-4o',
    'temperature' => 0,
    'messages'    => [
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user',   'content' => $text],
    ],
];


        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json; charset=utf-8',
                'Accept'        => 'application/json',
            ],
            'body'      => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout'   => 40,
            'sslverify' => apply_filters('reeid_ssl_verify', true),
        ];

        $res = wp_remote_post('https://api.openai.com/v1/chat/completions', $args);

        if (is_wp_error($res)) {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S17/WP_ERROR', $res->get_error_message());
            }
            return $return_wp_error ? $res : $text;
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        $body = (string) wp_remote_retrieve_body($res);

        if ($code < 200 || $code >= 300) {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S17/HTTP_NON_2XX', ['code' => $code, 'body' => substr($body, 0, 300)]);
            }
            $err = new WP_Error("http_$code", "OpenAI returned HTTP $code");
            return $return_wp_error ? $err : $text;
        }

        $json = json_decode($body, true);
        if (! is_array($json)) {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S17/BAD_JSON', substr($body, 0, 500));
            }
            return $text;
        }

        $out = '';
        if (! empty($json['choices'][0]['message']['content'])) {
            $out = (string) $json['choices'][0]['message']['content'];
        }

        $out = trim($out);
        if ($out === '' || $out === $text) {
            return $text;
        }

        /* Clean fences + decode escaped sequences */
        $out = preg_replace('/^```.*?\n|\n```$/s', '', $out);
        $maybe = @json_decode('"' . str_replace('"', '\"', $out) . '"');
        if ($maybe !== null) {
            $out = $maybe;
        }
        

        return trim($out);
    }
}


/*==============================================================================
  17.2 — INLINE FORMATTING PLACEHOLDER SYSTEM
==============================================================================*/
if (! function_exists('reeid_inline_placehold_formatting')) {
    function reeid_inline_placehold_formatting(string $html, array &$ph): string
    {
        /* Normalize bold/italic spans into strong/em */
        $norm = preg_replace_callback(
            '#<span\b([^>]*)>(.*?)</span>#is',
            function ($m) {
                $attrs = strtolower($m[1]);
                $body  = $m[2];
                $bold  = preg_match('#font-weight\s*:\s*(bold|[6-9]00)#', $attrs);
                $ital  = preg_match('#font-style\s*:\s*italic#', $attrs);

                if ($bold && !$ital) return "<strong>$body</strong>";
                if ($ital && !$bold) return "<em>$body</em>";
                if ($bold && $ital)  return "<strong><em>$body</em></strong>";
                return "<span{$m[1]}>$body</span>";
            },
            $html
        );

        $ph = [];
        $id = 0;

        $tags = [
            ['<strong>', '</strong>'],
            ['<b>', '</b>'],
            ['<em>', '</em>'],
            ['<i>', '</i>'],
            ['<u>', '</u>'],
            ['<mark>', '</mark>'],
        ];

        $out = $norm;
        foreach ($tags as [$open, $close]) {
            $pattern = '#' . preg_quote($open, '#') . '(.*?)' . preg_quote($close, '#') . '#is';
            $out = preg_replace_callback($pattern, function ($m) use (&$ph, &$id, $open, $close) {
                $id++;
                $ph[$id] = ['open' => $open, 'close' => $close];
                return "[[PHO:$id]]" . $m[1] . "[[PHC:$id]]";
            }, $out);
        }

        return $out;
    }
}

if (! function_exists('reeid_inline_restore_formatting')) {
    function reeid_inline_restore_formatting(string $html, array $ph): string
    {
        foreach ($ph as $id => $p) {
            $html = str_replace("[[PHC:$id]]", $p['close'], $html);
        }
        foreach ($ph as $id => $p) {
            $html = str_replace("[[PHO:$id]]", $p['open'], $html);
        }
        return $html;
    }
}

/*==============================================================================
  17.3 — EXTRACT TEXT LINES (with currency guard)
==============================================================================*/
if ( ! function_exists( 'reeid_extract_text_lines' ) ) {
    function reeid_extract_text_lines( string $html ): array {
        $lines = [];

        if ( preg_match_all( '/>(.*?)</us', $html, $m ) ) {
            foreach ( $m[1] as $txt ) {
                $has_html = ( strpos( $txt, '<' ) !== false );

                if ( preg_match( '/^([\x{00A0}\s]*)(.*?)([\x{00A0}\s]*)$/u', $txt, $mm ) ) {
                    $left  = $mm[1];
                    $core  = $mm[2];
                    $right = $mm[3];
                } else {
                    $left  = '';
                    $core  = $txt;
                    $right = '';
                }

                // What we actually feed to the model:
                $core_for_model = $has_html ? $txt : $core;

                // -----------------------------------------------------------------
                // CURRENCY GUARD:
                // Skip pure price tokens like "$0", "$39", "€59", "¥1000", etc.
                // Pattern: optional currency symbol, number with optional decimals,
                // optional trailing currency symbol. We do NOT send these to OpenAI.
                // -----------------------------------------------------------------
                $pure = trim( $core_for_model );
                if ( $pure !== '' && preg_match( '/^\p{Sc}?\s*[0-9]+(?:[.,][0-9]+)?\s*\p{Sc}?$/u', $pure ) ) {
                    continue;
                }

                if ( $core_for_model !== '' ) {
                    $lines[] = [
                        'full'     => $txt,
                        'left'     => $left,
                        'core'     => $core_for_model,
                        'right'    => $right,
                        'has_html' => $has_html,
                    ];
                }
            }
        }

        if ( function_exists( 'reeid_debug_log' ) ) {
            reeid_debug_log( 'S17/EXTRACTED', [ 'count' => count( $lines ) ] );
        }

        return $lines;
    }
}



/*==============================================================================
  17.4 — TRANSLATE LINES
==============================================================================*/
if (! function_exists('reeid_translate_lines')) {
    function reeid_translate_lines(array $lines, string $target_lang, string $tone = 'Neutral', string $prompt = ''): array
    {
        $out = [];
        foreach ($lines as $i => $row) {
            $core = is_array($row) ? $row['core'] : $row;
            $out[$i] = reeid_openai_translate_single($core, $target_lang, $tone, $prompt);
        }
        return $out;
    }
}


/*==============================================================================
  17.5 — REINJECT TRANSLATED TEXT (PATCHED FOR CURRENCY BUG)
==============================================================================*/
if ( ! function_exists( 'reeid_reinject_lines' ) ) {
    function reeid_reinject_lines( string $html, array $en, array $tr, string $target_lang ): string {

        // Clean model output and normalize text
        $clean = function ( string $s ): string {
            $s = trim( $s );
            $s = preg_replace( '/^```.*?\n|\n```$/s', '', $s );
            $maybe = @json_decode( '"' . str_replace( '"', '\"', $s ) . '"' );
            return $maybe !== null ? $maybe : $s;
        };

        foreach ( $en as $i => $row ) {

            if ( ! isset( $tr[ $i ] ) ) {
                continue;
            }

            $translated = $clean( $tr[ $i ] );

            // ----------------------------------------------------------
            // PATCH: Prevent accidental wrapping: >$0< → >$0< (no change)
            // Remove any stray angle brackets injected by model.
            // ----------------------------------------------------------
            $translated = preg_replace( '/^>(.*?)<$/u', '$1', $translated );

            // Also remove any model-added leading/trailing brackets
            $translated = ltrim( $translated, '<>' );
            $translated = rtrim( $translated, '<>' );

            if ( $row['has_html'] ) {
                $new_full = $translated;
            } else {
                $new_full = $row['left'] . $translated . $row['right'];
            }

            // ----------------------------------------------------------
            // SAFE REPLACE: replace TEXT ONLY between > ... <
            // ----------------------------------------------------------
            $needle = preg_quote( $row['full'], '/' );
            $pattern = '/>' . $needle . '</u';

            $html = preg_replace(
                $pattern,
                '>' . $new_full . '<',
                $html,
                1
            );
        }

        // RTL wrapping
        $rtl = [ 'ar', 'he', 'fa', 'ur' ];
        if ( in_array( strtolower( $target_lang ), $rtl, true ) ) {
            $html = '<div dir="rtl" lang="' . esc_attr( $target_lang ) . '">' . $html . '</div>';
        }

        return $html;
    }
}


/*==============================================================================
  17.6 — MAIN GUTENBERG/CLASSIC EXTRACT→TRANSLATE→REINJECT
==============================================================================*/
if (! function_exists('reeid_gutenberg_classic_translate_via_extractor')) {
    function reeid_gutenberg_classic_translate_via_extractor(
        string $html,
        string $target_lang,
        string $tone = 'Neutral',
        string $prompt = ''
    ): string {

        /* Build merged prompt: global + per-request */
        $global = '';
        foreach (['reeid_custom_instructions','reeid_custom_prompt','reeid_pro_custom_instructions'] as $opt) {
            $v = get_option($opt, '');
            if (is_string($v) && trim($v) !== '') {
                $global = $v;
                break;
            }
        }

        $parts = [];
        if (trim($global) !== '') $parts[] = trim(wp_kses_post($global));
        if (trim($prompt) !== '') $parts[] = trim(wp_kses_post($prompt));
        $merged_prompt = implode(' ', array_unique($parts));

        /* Placeholder inline formatting */
        $ph = [];
        $html2 = reeid_inline_placehold_formatting($html, $ph);

        /* Extract */
        $en = reeid_extract_text_lines($html2);
        if (empty($en)) {
            return reeid_inline_restore_formatting($html2, $ph);
        }

        /* Translate */
        $tr = reeid_translate_lines($en, $target_lang, $tone, $merged_prompt);

        /* Reinject */
        $out = reeid_reinject_lines($html2, $en, $tr, $target_lang);

        /* Restore formatting placeholders */
        return reeid_inline_restore_formatting($out, $ph);
    }
}