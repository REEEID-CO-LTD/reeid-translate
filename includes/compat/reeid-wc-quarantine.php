<?php
/**
 * REEID — Woo quarantine (single-product only)
 * Disable ANY callbacks from the reeid-translate plugin on product pages for:
 *   - the_content (to keep Description clean)
 *   - template_redirect (to block output buffers / panel movers)
 *   - wp_head / wp_footer (to block CSS/JS that rehomes tabs/tables)
 * Reversible and scoped: only runs on front-end single products.
 */
if (!defined('ABSPATH')) exit;

add_action('wp', function () {
    if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) return;
    if (!function_exists('is_product') || !is_product()) return;

    $plugin_dir = '/wp-content/plugins/reeid-translate/';

    $strip_hook = function($hook) use ($plugin_dir) {
        global $wp_filter;
        if (empty($wp_filter[$hook])) return;

        $callbacks = is_object($wp_filter[$hook]) ? $wp_filter[$hook]->callbacks : (array) $wp_filter[$hook];
        foreach ($callbacks as $prio => $items) {
            foreach ($items as $idx => $arr) {
                $fn = $arr['function'];
                $file = null;

                if ($fn instanceof \Closure) {
                    try { $rf = new \ReflectionFunction($fn); $file = $rf->getFileName(); } catch (\Throwable $e) {}
                } elseif (is_string($fn)) {
                    try { $rf = new \ReflectionFunction($fn); $file = $rf->getFileName(); } catch (\Throwable $e) {}
                } elseif (is_array($fn)) {
                    try { $rm = new \ReflectionMethod($fn[0], $fn[1]); $file = $rm->getFileName(); } catch (\Throwable $e) {}
                }

                if ($file && strpos($file, $plugin_dir) !== false) {
                    remove_filter($hook, $fn, (int)$prio);
                    // Uncomment if you want to see what was removed:
                    // error_log("[REEID-QUAR] removed $hook prio=$prio from $file");
                }
            }
        }
    };

    // Nuke plugin mutations for product views
    $strip_hook('the_content');
//     $strip_hook('template_redirect'); // allow product hreflang de-dupe
//     $strip_hook('wp_head'); // allow hreflang
    $strip_hook('the_title');
    ('get_the_excerpt');
    ('woocommerce_product_get_name');
    ('woocommerce_product_get_title');
    ('woocommerce_product_get_short_description');
    ('woocommerce_product_get_description');
    ('wp_footer');
}, 0);
