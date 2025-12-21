<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REEID — Woo inline bridge
 *
 * Ensures product title, category, and taxonomy labels
 * respect inline translation payload.
 *
 * NO routing. NO redirects. NO output buffering.
 */

/**
 * Product title (fallback-safe)
 */
add_filter( 'the_title', function ( $title, $post_id ) {

    if ( is_admin() ) {
        return $title;
    }

    $post = get_post( $post_id );
    if ( ! $post || $post->post_type !== 'product' ) {
        return $title;
    }

    if ( ! function_exists( 'reeid_wc_resolve_lang_strong' ) ) {
        return $title;
    }

    $lang = reeid_wc_resolve_lang_strong();
    if ( $lang === 'en' ) {
        return $title;
    }

    $pl = get_post_meta( $post_id, '_reeid_wc_tr_' . $lang, true );
    if ( is_array( $pl ) && ! empty( $pl['title'] ) ) {
        return $pl['title'];
    }

    return $title;

}, 20, 2 );

/**
 * Product category name (inline)
 */
add_filter( 'single_term_title', function ( $name ) {

    if ( is_admin() ) {
        return $name;
    }

    if ( ! is_product() ) {
        return $name;
    }

    if ( ! function_exists( 'reeid_wc_resolve_lang_strong' ) ) {
        return $name;
    }

    $lang = reeid_wc_resolve_lang_strong();
    if ( $lang === 'en' ) {
        return $name;
    }

    // Category translation is content-based, keep as-is for now
    return $name;

}, 20 );

/**
 * Attribute labels (safe fallback)
 */
add_filter( 'woocommerce_attribute_label', function ( $label ) {

    if ( is_admin() ) {
        return $label;
    }

    return $label;

}, 20 );
