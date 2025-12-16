<?php
/**
 * UNIVERSAL GETTEXT ENGINE — REEID
 * Supports:
 * - WooCommerce strings
 * - WordPress Core strings
 * - Theme strings
 * - Dynamic strings with numbers (Reviews, Comments, etc.)
 */

add_filter('gettext', 'reeid_universal_translate', 20, 3);
function reeid_universal_translate($translated, $original, $domain) {

    // Detect current language (REEID EL / WC / cookie)
    $lang = function_exists('reeid_wc_get_lang') ? reeid_wc_get_lang() : '';
    if (!$lang) {
        return $translated;
    }

    $lang = strtolower($lang);

    static $CACHE = [];

    // ----------------------------------------------
    // Load JSON files by priority:
    // 1) WooCommerce
    // 2) Theme
    // 3) WordPress core
    // ----------------------------------------------
    if (!isset($CACHE[$lang])) {

        $base = __DIR__ . '/mappings/';

        $CACHE[$lang] = array_merge(
            file_exists($base . "woocommerce-$lang.json")
                ? json_decode(file_get_contents($base . "woocommerce-$lang.json"), true)
                : [],
            file_exists($base . "theme-$lang.json")
                ? json_decode(file_get_contents($base . "theme-$lang.json"), true)
                : [],
            file_exists($base . "wp-$lang.json")
                ? json_decode(file_get_contents($base . "wp-$lang.json"), true)
                : []
        );
    }

    $map = $CACHE[$lang];

    // ----------------------------------------------------
    // 0) Direct match
    // ----------------------------------------------------
    if (isset($map[$original])) {
        return $map[$original];
    }

    // ----------------------------------------------------
    // 1) Reviews (1), Reviews (11)
    // Base key: "review" or "customer review"
    // ----------------------------------------------------
    if (preg_match('/^\((\d+)\s+(.+?)\)$/u', $original, $m)) {
        $count = (int)$m[1];
        $word  = strtolower(trim($m[2]));

        // Look up singular/plural
        if (isset($map[$word])) {
            return "(" . $count . " " . $map[$word] . ")";
        }
    }

    // ----------------------------------------------------
    // 2) Uncategorized (taxonomy)
    // Key in JSON: "Uncategorized"
    // ----------------------------------------------------
    if ($original === "Uncategorized" && isset($map["Uncategorized"])) {
        return $map["Uncategorized"];
    }

    // ----------------------------------------------------
    // 3) Comments / Reviews counters
    // "1 review", "2 reviews"
    // ----------------------------------------------------
    if (preg_match('/^(\d+)\s+(review|reviews)$/iu', $original, $m)) {
        $count = $m[1];
        $key   = strtolower($m[2]);

        if (isset($map[$key])) {
            return $count . " " . $map[$key];
        }
    }

    // ----------------------------------------------------
    // 4) WordPress comment labels
    // "(1 Comment)", "(3 Comments)"
    // ----------------------------------------------------
    if (preg_match('/^\((\d+)\s+(comment|comments)\)$/iu', $original, $m)) {
        $count = $m[1];
        $key   = strtolower($m[2]);
        if (isset($map[$key])) {
            return "(" . $count . " " . $map[$key] . ")";
        }
    }

    return $translated;
}
