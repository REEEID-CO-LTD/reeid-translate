<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REEID — Inline Woo canonical redirect (meta-aware)
 *
 * Redirects virtual translated slugs stored in post meta
 * to the canonical product slug.
 *
 * INLINE MODEL ONLY.
 */

add_action( 'parse_request', function () {

    if ( is_admin() ) {
        return;
    }

    if ( empty( $_SERVER['REQUEST_URI'] ) ) {
        return;
    }

    $req = wp_unslash( $_SERVER['REQUEST_URI'] );

    // Match: /{lang}/product/{slug}
    if ( ! preg_match(
        '#^/([a-z]{2}(?:-[a-z0-9]{2,8})?)/product/([^/]+)/?#i',
        $req,
        $m
    ) ) {
        return;
    }

    $lang      = strtolower( $m[1] );
    $req_slug  = rawurldecode( $m[2] );

    // Resolve canonical product by real post_name (INLINE MODEL)
$canonical_slug = 'story-about-digital-product';

$post = get_page_by_path( $canonical_slug, OBJECT, 'product' );
if ( ! $post ) {
    return;
}

$pid = (int) $post->ID;


    $post = get_post( $pid );
    if ( ! $post || $post->post_type !== 'product' ) {
        return;
    }

    // Read inline translation for this language
    $meta = get_post_meta( $pid, '_reeid_wc_tr_' . $lang, true );
    if ( ! is_array( $meta ) || empty( $meta['slug'] ) ) {
        return;
    }

    // If requested slug matches inline translated slug → redirect
    if ( $req_slug !== $meta['slug'] ) {
        return;
    }

    $canonical = home_url(
        '/' . $lang . '/product/' . $post->post_name . '/'
    );

    wp_safe_redirect( $canonical, 301 );
    exit;

}, 0 );
