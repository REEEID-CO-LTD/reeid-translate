<?php
/**
 * REEID — Disable wp_old_slug_redirect for language-prefixed Woo products
 *
 * WHY:
 * WordPress core forces canonical slug redirects (wp_old_slug_redirect),
 * which breaks native translated slugs like:
 *   /zh/product/关于数字产品的故事/
 *
 * This disables that redirect ONLY for:
 *   /{lang}/product/{slug}
 *
 * Safe:
 * - frontend only
 * - product only
 * - language-prefixed only
 * - no SEO plugin conflicts
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter(
    'redirect_canonical',
    function ( $redirect_url, $requested_url ) {

        // Frontend only
        if ( is_admin() || wp_doing_ajax() ) {
            return $redirect_url;
        }

        if ( ! function_exists( 'reeid_wc_current_lang' ) ) {
            return $redirect_url;
        }

        $lang = reeid_wc_current_lang();
        if ( ! $lang ) {
            return $redirect_url;
        }

        $source = strtolower(
            (string) get_option( 'reeid_translation_source_lang', 'en' )
        );

        // Do NOT interfere with source language
        if ( $lang === $source ) {
            return $redirect_url;
        }

        // Only for product URLs
        if ( strpos( $requested_url, '/product/' ) === false ) {
            return $redirect_url;
        }

        // Only if URL is language-prefixed
        if ( ! preg_match( '#/' . preg_quote( $lang, '#' ) . '/product/#', $requested_url ) ) {
            return $redirect_url;
        }

        // 🚫 BLOCK WordPress canonical slug redirect
        return false;

    },
    0,
    2
);
