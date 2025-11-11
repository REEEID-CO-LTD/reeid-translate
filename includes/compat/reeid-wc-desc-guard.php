<?php
/**
 * REEID — WC Description guard (WYSIWYG-safe)
 * - Runs only on single products.
 * - Removes [product_attributes] shortcode and any mistakenly nested
 *   Additional Information panel markup from the **Description** output.
 * - Does NOT change the real Additional Information tab (Woo renders that separately).
 */
if (!defined('ABSPATH')) exit;

add_filter('the_content', function($content){
    if ((is_admin() && !wp_doing_ajax())) return $content;
    if (!function_exists('is_product') || !is_product()) return $content;

    // Strip Woo attributes shortcode if someone pasted it into Description
    $content = preg_replace('/\[product_attributes[^\]]*\]/i', '', $content);

    // Strip a wrongly nested "Additional information" panel wrapper inside Description only
    $content = preg_replace('#<div[^>]*class=("|\')[^"\']*woocommerce-Tabs-panel--additional_information[^"\']*\\1[^>]*>[\s\S]*?</div>#i', '', $content);

    return $content;
}, 100000); // super-late; only affects Description
