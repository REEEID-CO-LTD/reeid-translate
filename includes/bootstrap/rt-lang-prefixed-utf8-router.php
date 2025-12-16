<?php
if (!defined('ABSPATH')) exit;

/**
 * Cleanly resolve /xx/ or /xx-YY/ prefixed URLs with UTF-8 slugs.
 * - Sets pagename/name from the URL **urldecoded**.
 * - Temporarily disables sanitize_title / sanitize_title_for_query callbacks coming from reeid-translate.php
 *   for the main query only (prevents Greek/etc. from being stripped).
 * - Elementor unaffected.
 */
add_action('parse_request', function ($wp) {
    if (is_admin() || !isset($_SERVER['REQUEST_URI'])) return;

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
    if (!preg_match('#^/([a-z]{2}(?:-[a-z0-9]{2})?)/(.*)$#i', $path, $m)) return;

    $rest = trim($m[2], '/');
    if ($rest === '') return;

    // UR L DECODE the path pieces for WordPress lookup
    $decoded_full = urldecode($rest);
    $decoded_last = urldecode(basename($rest));

    // Feed into main query vars so WP resolves hierarchies properly
    $wp->query_vars['pagename'] = $decoded_full;
    $wp->query_vars['name']     = $decoded_last;
    // Stay broad so pages, CPTs etc. can resolve
    if (empty($wp->query_vars['post_type'])) {
        $wp->query_vars['post_type'] = 'any';
    }
}, 0);

/**
 * Before main query runs, temporarily remove sanitize callbacks from reeid-translate.php
 * so native-script slugs (Greek, etc.) survive resolution.
 */
add_action('pre_get_posts', function ($q) {
    if (! $q->is_main_query()) return;
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    if (!preg_match('#^/([a-z]{2}(?:-[a-z0-9]{2})?)/#i', $path)) return;

    $strip_from = function ($tag) {
        global $wp_filter;
        if (empty($wp_filter[$tag]) || !is_object($wp_filter[$tag])) return;
        foreach ($wp_filter[$tag]->callbacks ?? [] as $prio => $cbs) {
            foreach ($cbs as $cb) {
                $fn = $cb['function']; $file = null;
                try {
                    if ($fn instanceof Closure) { $ref=new ReflectionFunction($fn); $file=$ref->getFileName(); }
                    elseif (is_array($fn) && isset($fn[1])) { $ref=is_object($fn[0])?new ReflectionMethod($fn[0],$fn[1]):new ReflectionFunction($fn[1]); $file=$ref->getFileName(); }
                    elseif (is_string($fn) && function_exists($fn)) { $ref=new ReflectionFunction($fn); $file=$ref->getFileName(); }
                } catch (Throwable $e) { $file = null; }
                if ($file && substr($file, -19) === 'reeid-translate.php') {
                    remove_filter($tag, $fn, $prio);
                }
            }
        }
    };
    $strip_from('sanitize_title');
    $strip_from('sanitize_title_for_query');
}, 0);

/** Stop canonical “fixes” on lang-prefixed paths that cause false 404s */
add_filter('redirect_canonical', function ($redirect, $requested) {
    $path = parse_url($requested, PHP_URL_PATH) ?: '';
    if (preg_match('#^/([a-z]{2}(?:-[a-z0-9]{2})?)/#i', $path)) return false;
    return $redirect;
}, 0, 2);
// --- REEID compatibility helper: resolve post ID from lang-prefixed URLs ---
if (! function_exists('reeid_url_to_postid_prefixed')) {
    /**
     * Return post ID for URLs that may start with /{lang}/... .
     * Non-destructive: delegates to url_to_postid() after stripping the prefix.
     *
     * Usage: reeid_url_to_postid_prefixed('/de/some-slug/')
     */
    function reeid_url_to_postid_prefixed(string $url) {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        // If path contains language prefix (/xx/ or /xx-YY/), strip it
        if (preg_match('#^/([a-z]{2}(?:-[a-z0-9]{2})?)/(.*)$#i', $path, $m)) {
            $path = '/'.$m[2];
        }
        // Ensure decoding matches what the router does
        $path = rawurldecode($path);
        // Try url_to_postid on stripped path first
        $id = url_to_postid($path);
        if ($id) return (int)$id;
        // fallback: try get_page_by_path (covers pages/CPTs)
        $p = get_page_by_path(trim($path, '/'), OBJECT, 'any');
        return $p ? (int)$p->ID : 0;
    }
}
