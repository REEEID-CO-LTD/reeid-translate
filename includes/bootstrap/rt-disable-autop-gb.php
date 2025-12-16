<?php
if (!defined('ABSPATH')) exit;
/**
 * Disable wpautop + shortcode_unautop ONLY for Gutenberg/Classic content.
 * Elementor remains untouched (it has no <!-- wp: --> markers).
 */
add_action('wp', function () {
    if (!is_singular()) return;
    $post = get_queried_object();
    if (empty($post) || empty($post->post_content)) return;
    if (stripos($post->post_content, '<!-- wp:') === false) return; // not Gutenberg/Classic

    // Kill paragraph auto-wrapping that breaks translated block HTML
    remove_filter('the_content', 'wpautop');
    remove_filter('the_content', 'shortcode_unautop');
}, 0);
