<?php
/**
 * REEID — Gutenberg Safety Pack (SAFE VERSION)
 * - Scope: only Gutenberg content (has <!-- wp:) on front-end singular views.
 * - Explicitly DOES NOT run on WooCommerce single products (is_product()).
 * - Purpose: avoid double wpautop/shortcode wrappers and clean minor empty wrappers.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('reeid_gb_safetypack_should_run')) {
    function reeid_gb_safetypack_should_run($html = null) {
        if (is_admin() && !wp_doing_ajax()) return false;
        if (function_exists('is_product') && is_product()) return false; // <-- do NOT touch Woo products
        if (!is_singular()) return false;
        // If buffer provided, check for block marker quickly
        if (is_string($html)) {
            if ($html === '' || stripos($html, '<!-- wp:') === false) return false;
            return true;
        }
        // Fallback: try current post_content if available
        global $post;
        if (empty($post) || empty($post->post_content)) return false;
        return (stripos($post->post_content, '<!-- wp:') !== false);
    }
}

/**
 * Pass A — very early: prevent wpautop and shortcode_unautop from re-wrapping blocks.
 * No aggressive unhooking of third-party filters in this safe version.
 */
add_filter('the_content', function ($html) {
    if (!reeid_gb_safetypack_should_run($html)) return $html;

    // Only remove core paragraph wrappers; leave other plugin filters intact.
    remove_filter('the_content', 'wpautop');
    remove_filter('the_content', 'shortcode_unautop');

    return $html;
}, 0);

/**
 * Pass B — very late cleanup: strip truly empty <p>, <div>, and fix <p><br></p> artifacts.
 * Still never runs on single product pages.
 */
add_filter('the_content', function ($html) {
    if (!reeid_gb_safetypack_should_run($html)) return $html;

    // Remove <p><br></p> and empty paragraphs/divs created by mixed filters
    $html = preg_replace('#<p>\s*(?:<br\s*/?>)?\s*</p>#i', '', $html);
    $html = preg_replace('#<div>\s*</div>#i', '', $html);

    // Normalize multiple blank lines
    $html = preg_replace("/\n{3,}/", "\n\n", $html);

    return $html;
}, 999);
