<?php

// Lightweight translation lock helpers
if ( ! function_exists('reeid_acquire_translation_lock') ) {
    function reeid_acquire_translation_lock( int $post_id, string $lang, int $ttl = 60 ): bool {
        $key = "reeid_trans_lock_{$post_id}_{$lang}";
        // If transient already exists — someone else is translating
        if ( get_transient( $key ) ) {
            return false;
        }
        // Set a short TTL; use set_transient which returns true on success
        return set_transient( $key, time(), $ttl );
    }
}

if ( ! function_exists('reeid_release_translation_lock') ) {
    function reeid_release_translation_lock( int $post_id, string $lang ): void {
        $key = "reeid_trans_lock_{$post_id}_{$lang}";
        delete_transient( $key );
    }
}



// File: wp-content/plugins/reeid-translate/includes/translator.php
if (!defined('ABSPATH')) exit;

/**
 * Optional: central translation call for focus kw and titles.
 * Replace with your own provider (DeepL, Google, etc.).
 */

if (!defined('ABSPATH')) exit;

/**
 * REEID — Minimal translator  
 * - Preserves token placeholders ({...}, %...%, [...]) and returns original text
 * - Safe no-op for title/SEO token-preserving calls
 */

if (!function_exists('reeid_focuskw_call_translator')) {
    function reeid_focuskw_call_translator($text, $from, $to, array $args = []) {
        $text = (string) $text;
        $from = strtolower(substr($from, 0, 5));
        $to   = strtolower(substr($to, 0, 5));
        if ($text === '' || $from === $to) {
            return $text;
        }

        // Preserve tokens like {shortcodes}, %placeholders% and [shortcodes]
        $tokens = [];
        $preserved = preg_replace_callback('/(\{[^}]+\}|%[^%]+%|\[[^\]]+\])/', function($m) use (&$tokens){
            $key = '__TOK'.count($tokens).'__';
            $tokens[$key] = $m[0];
            return $key;
        }, $text);

        // NO external calls here — return original text with tokens restored.
        $translated = $preserved;
        foreach ($tokens as $k => $v) {
            $translated = str_replace($k, $v, $translated);
        }

        return $translated;
    }
}

if (!function_exists('reeid_translate_preserve_tokens')) {
    function reeid_translate_preserve_tokens($text, $from, $to, array $args = []) {
        return reeid_focuskw_call_translator($text, $from, $to, $args);
    }
}



/**
 * Optional: used by title translator in seo-sync.php when available.
 * If you don't need special token handling, you can just return
 * reeid_focuskw_call_translator(...) from here.
 */
if (!function_exists('reeid_translate_preserve_tokens')) {
    function reeid_translate_preserve_tokens($text, $from, $to, array $args = []) {
        return reeid_focuskw_call_translator($text, $from, $to, $args);
    }
}
