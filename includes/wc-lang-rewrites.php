<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REEID — Language prefix root + Woo product routing
 *
 * Enables:
 *   /{lang}/product/{slug}
 *
 * Safe:
 * - rewrite rules only
 * - no redirects
 * - no template_redirect
 * - WP-repo compliant
 */

add_action( 'init', function () {

    // Register language query var
    add_filter( 'query_vars', function ( $vars ) {
        $vars[] = 'reeid_lang';
        return $vars;
    } );

    // Register language root so WP accepts /pl/ as valid
    add_rewrite_rule(
        '^([a-z]{2}(?:-[a-zA-Z0-9]{2,8})?)/?$',
        'index.php?reeid_lang=$matches[1]',
        'top'
    );

    // Language-prefixed Woo product
    add_rewrite_rule(
        '^([a-z]{2}(?:-[a-zA-Z0-9]{2,8})?)/product/([^/]+)/?$',
        'index.php?post_type=product&name=$matches[2]&reeid_lang=$matches[1]',
        'top'
    );

}, 0 );
/**
 * Resolve language-prefixed Woo products to a concrete post ID.
 * Supports native UTF-8 and sanitized Latin slugs.
 */
add_action( 'parse_request', function ( $wp ) {

    if ( empty( $wp->query_vars['reeid_lang'] ) ) {
        return;
    }

    if ( empty( $wp->query_vars['name'] ) ) {
        return;
    }

    $raw = rawurldecode( $wp->query_vars['name'] );

    // 1) Try native UTF-8 slug
    $post = get_page_by_path( $raw, OBJECT, 'product' );

    // 2) Fallback: sanitized (Latin accents)
    if ( ! $post ) {
        $san = sanitize_title( $raw );
        if ( $san !== $raw ) {
            $post = get_page_by_path( $san, OBJECT, 'product' );
        }
    }

    if ( $post ) {
        // Hand WP a concrete ID — this avoids all slug ambiguity
        $wp->query_vars = array(
            'post_type' => 'product',
            'p'         => (int) $post->ID,
        );
    }

}, 1 );
