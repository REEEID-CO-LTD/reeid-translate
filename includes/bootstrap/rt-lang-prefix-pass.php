<?php
if (!defined('ABSPATH')) exit;

/**
 * Lang-prefix pass-through for Gutenberg/Classic:
 * - If URL path starts with /xx/ or /xx-YY/, strip that segment and let WP resolve the remaining path.
 * - Prevent false 404s and bad canonical redirects on these paths.
 * - Elementor untouched.
 */
add_filter('request', function ($qv) {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    if (preg_match('#^/([a-z]{2}(?:-[a-z0-9]{2})?)/(.*)$#i', $path, $m)) {
        $rest = trim($m[2], '/');
        if ($rest !== '') {
            // Let WP resolve by pagename/name normally
            $qv['pagename'] = $rest;
            $qv['name']     = basename($rest);
            // Avoid category/archive misroutes
            unset($qv['category_name'], $qv['attachment'], $qv['attachment_id']);
        }
    }
    return $qv;
}, 0);

add_filter('pre_handle_404', function ($pre, $wp_query) {
    if (!is_object($wp_query) || !$wp_query->is_main_query()) return $pre;
    // If request had a lang prefix and a real post exists, force 200
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    if (preg_match('#^/([a-z]{2}(?:-[a-z0-9]{2})?)/#i', $path) && !empty($wp_query->post->ID)) {
        $wp_query->is_404 = false;
        $wp_query->query_vars['error'] = '';
        if (!headers_sent()) status_header(200);
        return true;
    }
    return $pre;
}, 0, 2);

add_filter('redirect_canonical', function ($redirect, $requested) {
    $path = parse_url($requested, PHP_URL_PATH);
    if ($path && preg_match('#^/([a-z]{2}(?:-[a-z0-9]{2})?)/#i', $path)) {
        return false; // don’t “fix” lang-prefixed URLs
    }
    return $redirect;
}, 0, 2);
