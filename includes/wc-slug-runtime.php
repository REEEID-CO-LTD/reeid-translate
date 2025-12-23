<?php
/**
 * REEID — WooCommerce Native Slug Runtime
 *
 * Purpose:
 * - Restore native-language slugs for products (SEO-safe)
 * - Use stored inline packets: _reeid_wc_tr_{lang}['slug']
 * - NO rewrite rules
 * - NO template_redirect
 * - NO hardcoded languages
 * - Source language comes ONLY from plugin settings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolve current frontend language.
 * Relies on existing runtime (wc-lang-runtime.php).
 */
if ( ! function_exists( 'reeid_wc_get_active_lang' ) ) {
    function reeid_wc_get_active_lang(): string {
        if ( function_exists( 'reeid_wc_current_lang' ) ) {
            return (string) reeid_wc_current_lang();
        }
        return strtolower( (string) get_option( 'reeid_translation_source_lang', 'en' ) );
    }
}

/**
 * Filter product permalink to inject native translated slug.
 */
add_filter(
    'post_link',
    function ( $permalink, $post, $leavename ) {

        if ( ! $post || $post->post_type !== 'product' ) {
            return $permalink;
        }

        $lang = reeid_wc_get_active_lang();
        $source_lang = strtolower(
            (string) get_option( 'reeid_translation_source_lang', 'en' )
        );

        // Do NOT touch source language URLs
        if ( ! $lang || $lang === $source_lang ) {
            return $permalink;
        }

        $packet = get_post_meta( $post->ID, '_reeid_wc_tr_' . $lang, true );
        if (
            ! is_array( $packet ) ||
            empty( $packet['slug'] )
        ) {
            return $permalink;
        }

        $slug = (string) $packet['slug'];

        // Replace only the final path segment (slug)
        $parsed = wp_parse_url( $permalink );
        if ( empty( $parsed['path'] ) ) {
            return $permalink;
        }

        $path = $parsed['path'];
        $path = untrailingslashit( $path );

        // Replace last segment safely (UTF-8 safe)
        $path = preg_replace(
            '#/[^/]+$#u',
            '/' . rawurlencode( urldecode( $slug ) ),
            $path
        );

        $new = $path . '/';

        // Rebuild full URL
        $rebuilt =
            ( $parsed['scheme'] ?? 'https' ) . '://' .
            ( $parsed['host'] ?? '' ) .
            ( isset( $parsed['port'] ) ? ':' . $parsed['port'] : '' ) .
            $new;

        return $rebuilt;

    },
    10,
    3
);

/**
 * WooCommerce-specific permalink safety (some themes bypass post_link)
 */
add_filter(
    'woocommerce_product_get_permalink',
    function ( $permalink, $product ) {

        if ( ! $product instanceof WC_Product ) {
            return $permalink;
        }

        $lang = reeid_wc_get_active_lang();
        $source_lang = strtolower(
            (string) get_option( 'reeid_translation_source_lang', 'en' )
        );

        if ( ! $lang || $lang === $source_lang ) {
            return $permalink;
        }

        $pid = $product->get_id();
        $packet = get_post_meta( $pid, '_reeid_wc_tr_' . $lang, true );

        if (
            ! is_array( $packet ) ||
            empty( $packet['slug'] )
        ) {
            return $permalink;
        }

        $slug = (string) $packet['slug'];

        $parsed = wp_parse_url( $permalink );
        if ( empty( $parsed['path'] ) ) {
            return $permalink;
        }

        $path = untrailingslashit( $parsed['path'] );

        $path = preg_replace(
            '#/[^/]+$#u',
            '/' . rawurlencode( urldecode( $slug ) ),
            $path
        );

        $new = $path . '/';

        $rebuilt =
            ( $parsed['scheme'] ?? 'https' ) . '://' .
            ( $parsed['host'] ?? '' ) .
            ( isset( $parsed['port'] ) ? ':' . $parsed['port'] : '' ) .
            $new;

        return $rebuilt;

    },
    10,
    2
);
