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

    // URL DECODE the path pieces for WordPress lookup
    $decoded_full = urldecode($rest);
    $decoded_last = urldecode(basename($rest));

    // Try to detect if this slug actually points to a PAGE.
    // First try the full path, then just the last segment.
    $page = get_page_by_path($decoded_full, OBJECT, 'page');
    if (! $page instanceof WP_Post) {
        $page = get_page_by_path($decoded_last, OBJECT, 'page');
    }

        if ($page instanceof WP_Post) {
        // PAGE MODE:
        //  - drive query by numeric page_id (most robust)
        //  - force post_type=page
        //  - clear name/pagename so WP doesn't treat it as a post query
        $page_id = (int) $page->ID;

        // Merge into existing vars
        $wp->query_vars['page_id']   = $page_id;
        $wp->query_vars['post_type'] = 'page';

        // Ensure these don't interfere
        unset($wp->query_vars['p']);
        unset($wp->query_vars['name']);
        unset($wp->query_vars['pagename']);

        if (defined('REEID_DEBUG') && REEID_DEBUG && function_exists('reeid_debug_log')) {
            reeid_debug_log('router_page_mode', [
                'uri'     => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
                'full'    => $decoded_full,
                'last'    => $decoded_last,
                'page_id' => $page_id,
            ]);
        }
    } else {

        // GENERIC MODE:
        //  - fallback to original behavior so posts/CPTs/products still work
        $wp->query_vars['pagename'] = $decoded_full;
        $wp->query_vars['name']     = $decoded_last;
        // Stay broad so pages, CPTs etc. can resolve
        if (empty($wp->query_vars['post_type'])) {
            $wp->query_vars['post_type'] = 'any';
        }

        if (defined('REEID_DEBUG') && REEID_DEBUG && function_exists('reeid_debug_log')) {
            reeid_debug_log('router_generic_mode', [
                'uri'  => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
                'full' => $decoded_full,
                'last' => $decoded_last,
            ]);
        }
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
