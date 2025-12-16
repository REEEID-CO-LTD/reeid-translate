<?php
/**
 * SECTION X.1 — FRONTEND: Ensure WooCommerce product title/content use inline translations
 * Safe, minimal compatibility file for REEID Translate plugin.
 *
 * Place under: includes/bootstrap/rt-wc-frontend-compat.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Helper - get inline product translation meta for current lang
 * returns array or null
 */
function reeid_wc_get_inline_translation( int $post_id, string $lang ) {
    if ( empty( $lang ) || $lang === 'en' ) {
        return null;
    }
    $meta_key = "_reeid_wc_tr_" . $lang;
    $m = get_post_meta( $post_id, $meta_key, true );
    if ( ! is_array( $m ) || empty( $m ) ) {
        return null;
    }
    return $m;
}

/**
 * Determine requested language (query var first, then cookie)
 */
function reeid_wc_detect_frontend_lang() : string {
    $lang = '';
    $q = get_query_var( 'reeid_lang_code' );
    if ( ! empty( $q ) ) {
        $lang = sanitize_text_field( $q );
    } elseif ( ! empty( $_COOKIE['reeid_lang'] ) ) {
        $lang = sanitize_text_field( wp_unslash( $_COOKIE['reeid_lang'] ) );
    }
    return strtolower( trim( $lang ) );
}

/**
 * Replace title for frontend single product and WooCommerce product name API.
 */
add_filter( 'the_title', function ( $title, $post_id = null ) {
    if ( is_admin() || empty( $post_id ) ) {
        return $title;
    }
    $post = get_post( $post_id );
    if ( ! $post || $post->post_type !== 'product' ) {
        return $title;
    }
    $lang = reeid_wc_detect_frontend_lang();
    $m = reeid_wc_get_inline_translation( $post_id, $lang );
    if ( $m && ! empty( $m['title'] ) ) {
        // return sanitized HTML (the_title() may expect escaped content)
        return wp_kses_post( $m['title'] );
    }
    return $title;
}, 10, 2 );

/**
 * Replace the_content for product single pages
 */
add_filter( 'the_content', function ( $content ) {
    if ( is_admin() ) {
        return $content;
    }
    global $post;
    if ( ! isset( $post ) || $post->post_type !== 'product' ) {
        return $content;
    }
    $lang = reeid_wc_detect_frontend_lang();
    $m = reeid_wc_get_inline_translation( $post->ID, $lang );
    if ( $m && ! empty( $m['content'] ) ) {
        return wp_kses_post( $m['content'] );
    }
    return $content;
}, 10, 1 );

/**
 * Hook WooCommerce product name getter as well (covers templates using $product->get_name()).
 */
add_filter( 'woocommerce_product_get_name', function ( $name, $product ) {
    if ( is_admin() || ! $product ) {
        return $name;
    }
    $post_id = is_object( $product ) && method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
    if ( ! $post_id ) {
        return $name;
    }
    $lang = reeid_wc_detect_frontend_lang();
    $m = reeid_wc_get_inline_translation( $post_id, $lang );
    if ( $m && ! empty( $m['title'] ) ) {
        return wp_kses_post( $m['title'] );
    }
    return $name;
}, 10, 2 );


