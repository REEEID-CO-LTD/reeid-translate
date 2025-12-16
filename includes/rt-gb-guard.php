<?php
if (!defined('ABSPATH')) exit;

/**
 * REEID — Guard tweak for the_content removals
 *
 * Purpose:
 *  - Provide small whitelist helpers so the guard will NOT remove callbacks
 *    that belong to the REEID plugin (or wc-inline.php / reeid-translate.php).
 *  - Preserve the existing behavior of unwrapping stray <p> wrappers around
 *    block-level elements when the post content contains Gutenberg blocks.
 *
 * Install:
 *  - Place this file (or the code below) in the existing guard file that
 *    currently removes the_content callbacks; or include it at the top of
 *    that guard file. The helpers are defensive and will not conflict if
 *    included multiple times.
 */

/* ---- WHITELIST HELPERS (safe, minimal) ---- */

if (!function_exists('reeid_rt_get_callback_file')) {
    function reeid_rt_get_callback_file($fn) {
        try {
            if (is_array($fn) && isset($fn[0], $fn[1])) {
                $owner  = $fn[0];
                $method = $fn[1];
                // ReflectionMethod works for object + method or class + method
                $rm = new ReflectionMethod($owner, $method);
                return $rm->getFileName() ?: '';
            }

            if ($fn instanceof Closure) {
                $rf = new ReflectionFunction($fn);
                return $rf->getFileName() ?: '';
            }

            if (is_string($fn) && function_exists($fn)) {
                $rf = new ReflectionFunction($fn);
                return $rf->getFileName() ?: '';
            }
        } catch (Throwable $e) {
            // Reflection can fail (internal functions, evaled code, etc.)
            return '';
        }
        return '';
    }
}

if (!function_exists('reeid_rt_describe_callback')) {
    function reeid_rt_describe_callback($fn) {
        if (is_string($fn)) return $fn;
        if ($fn instanceof Closure) {
            try {
                $rf = new ReflectionFunction($fn);
                $file = $rf->getFileName() ?: '(internal)';
                $start = $rf->getStartLine() ?: 0;
                return sprintf('closure@%s:%d', $file, $start);
            } catch (Throwable $e) {
                return 'closure@(unknown)';
            }
        }
        if (is_array($fn) && isset($fn[0], $fn[1])) {
            $owner = $fn[0];
            $method = $fn[1];
            if (is_object($owner)) {
                return get_class($owner) . '::' . $method;
            }
            return $owner . '::' . $method;
        }
        return '(unknown)';
    }
}

if (!function_exists('reeid_rt_should_skip_removal')) {
    function reeid_rt_should_skip_removal($fn) {
        // 1) Named REEID functions (prefix)
        if (is_string($fn) && strpos($fn, 'reeid_') === 0) return true;

        // 2) Try to resolve file via reflection and match plugin path / filenames
        $file = reeid_rt_get_callback_file($fn);
        if ($file) {
            $lower = strtolower($file);
            // Adjust these patterns if your plugin folder or filenames differ
            if (strpos($lower, '/wp-content/plugins/reeid-translate/') !== false) return true;
            if (strpos($lower, '/wp-content/plugins/reeid/') !== false) return true; // defensive
            if (strpos($lower, 'wc-inline.php') !== false) return true;
            if (strpos($lower, 'reeid-translate.php') !== false) return true;
        }

        return false;
    }
}

/* ---- End helpers ---- */


/**
 * Guard Gutenberg layout:
 *  - If content has <!-- wp: -->, remove the_content callbacks coming from
 *    non-REEID sources that are known to break block rendering.
 *  - Also unwrap block-level elements accidentally wrapped in <p>…</p> and
 *    drop empty paragraphs.
 *
 * Notes:
 *  - We *do not* remove callbacks that match reeid_rt_should_skip_removal()
 *    (so REEID's translation closures remain attached).
 *  - This filter runs early (prio 1) to perform the guard before most content
 *    processing takes place.
 */
add_filter('the_content', function($html){
    // Quick exit: nothing to do
    if ($html === '' || stripos($html, '<!-- wp:') === false) {
        return $html;
    }

    // We only run on frontend context
    if (is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax())) {
        return $html;
    }

    // Inspect registered the_content callbacks and remove offending ones,
    // but skip REEID-owned callbacks (using the helper above).
    global $wp_filter;
    if (!empty($wp_filter['the_content'])) {
        $filter = $wp_filter['the_content'];
        $callbacks = is_object($filter) && isset($filter->callbacks) ? $filter->callbacks : (array)$filter;

        foreach ($callbacks as $prio => $items) {
            foreach ($items as $cb) {
                if (!isset($cb['function'])) continue;
                $fn = $cb['function'];
                // If this callback should be kept (belongs to REEID) — skip removal.
                if (function_exists('reeid_rt_should_skip_removal') && reeid_rt_should_skip_removal($fn)) {
                    if (function_exists('error_log')) {
                        $desc = reeid_rt_describe_callback($fn);
                        error_log('RT_CONTENT_GUARD: SKIP removing the_content prio=' . intval($prio) . ' cb=' . $desc);
                    }
                    continue;
                }

                // Otherwise remove the callback (original guard behavior)
                try {
                    remove_filter('the_content', $fn, (int)$prio);
                    if (function_exists('error_log')) {
                        $desc = reeid_rt_describe_callback($fn);
                        error_log('RT_CONTENT_GUARD: removed the_content prio=' . intval($prio) . ' cb=' . $desc);
                    }
                } catch (Throwable $e) {
                    // if removal fails, log and continue
                    if (function_exists('error_log')) {
                        $desc = reeid_rt_describe_callback($fn);
                        error_log('RT_CONTENT_GUARD: failed remove the_content prio=' . intval($prio) . ' cb=' . $desc . ' err=' . $e->getMessage());
                    }
                }
            }
        }
    }

    // Unwrap containers wrongly wrapped in <p>…</p> (common block-wrapping bug)
    $html = preg_replace(
        '#<p[^>]*>\s*((?:<(?:div|section|article|header|footer|nav|main|aside|figure|blockquote|ul|ol|table|h[1-6])[^>]*>).*?(?:</(?:div|section|article|header|footer|nav|main|aside|figure|blockquote|ul|ol|table|h[1-6])>))\s*</p>#is',
        '$1',
        $html
    );

    // Remove empty paragraphs
    $html = preg_replace('#<p[^>]*>\s*</p>#i', '', $html);

    return $html;
}, 1);
