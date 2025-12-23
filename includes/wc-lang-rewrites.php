<?php
/**
 * REEID — Language prefix root + Woo product routing
 *
 * Enables:
 *   /{lang}/product/{slug}
 *
 * SAFE:
 * - rewrite rules only
 * - parse_request resolver
 * - NO redirects
 * - NO template_redirect
 * - NO canonical logic
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ------------------------------------------------------------
 * INIT — rewrites + query vars
 * ------------------------------------------------------------ */
add_action( 'init', function () {

    // Query vars
    add_filter( 'query_vars', function ( $vars ) {
        $vars[] = 'reeid_lang';
        $vars[] = 'reeid_slug';
        return $vars;
    } );

    // /{lang}/
    add_rewrite_rule(
        '^([a-z]{2}(?:-[a-zA-Z0-9]{2,8})?)/?$',
        'index.php?reeid_lang=$matches[1]',
        'top'
    );

    // /{lang}/product/{slug}
    add_rewrite_rule(
        '^([a-z]{2}(?:-[a-zA-Z0-9]{2,8})?)/product/(.+)/?$',
        'index.php?post_type=product&reeid_lang=$matches[1]&reeid_slug=$matches[2]',
        'top'
    );

}, 0 );

/* ------------------------------------------------------------
 * PARSE REQUEST — resolve product by slug
 * ------------------------------------------------------------ */
add_action( 'parse_request', function ( $wp ) {

    if ( empty( $wp->query_vars['reeid_lang'] ) ) {
        return;
    }

    if ( empty( $wp->query_vars['reeid_slug'] ) ) {
        return;
    }

    $raw = rawurldecode( (string) $wp->query_vars['reeid_slug'] );

    // 1) Native UTF-8 slug
    $post = get_page_by_path( $raw, OBJECT, 'product' );

    // 2) Sanitized fallback
    if ( ! $post ) {
        $san = sanitize_title( $raw );
        if ( $san !== $raw ) {
            $post = get_page_by_path( $san, OBJECT, 'product' );
        }
    }

    if ( ! $post ) {
        return;
    }

    // Force exact post ID — prevents WP canonical redirect
    $wp->query_vars = array(
        'post_type' => 'product',
        'p'         => (int) $post->ID,
        'reeid_lang'=> sanitize_key( $wp->query_vars['reeid_lang'] ),
    );

}, 1 );
