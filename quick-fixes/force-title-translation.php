<?php
/**
 * Quick fix: show translated product title for language-prefixed URLs
 * Place inside REEID plugin directory: quick-fixes/force-title-translation.php
 * Reversible: delete this file to remove behavior.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'the_title', function( $title, $post_id ) {
    // only for singular product title in loop/single contexts
    if ( ! is_singular() && ! in_the_loop() && ! is_main_query() ) {
        return $title;
    }

    // only act on post types product (safety)
    $post = get_post( $post_id );
    if ( ! $post || $post->post_type !== 'product' ) {
        return $title;
    }

    // decide language from URL prefix (simple heuristic)
    $lang = null;
    if ( isset( $_SERVER['REQUEST_URI'] ) && preg_match('#/(zh|zh-cn|zh_cn)/#i', $_SERVER['REQUEST_URI'], $m) ) {
        $lang = 'zh';
    }
    // fall back: check common query var
    if ( empty( $lang ) && ! empty( $_GET['reeid_lang'] ) ) {
        $lang = sanitize_text_field( wp_unslash( $_GET['reeid_lang'] ) );
    }
    if ( empty( $lang ) ) {
        return $title;
    }

    // meta key format used by REEID
    $meta_key = "_reeid_wc_tr_{$lang}";
    $tr = get_post_meta( $post_id, $meta_key, true );
    if ( is_array( $tr ) && ! empty( $tr['title'] ) ) {
        // return translated title
        return wp_kses_post( $tr['title'] );
    }
    return $title;
}, 20, 2 );
