<?php
/**
 * REEID WC inline TITLE + SHORT DESCRIPTION overrides.
 *
 * - Uses REEID inline packets (_reeid_wc_tr_<lang>) for:
 *   - Product title (single product H1).
 *   - Short description (box above price).
 *
 * - Only affects single product pages on the frontend.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detect current WC language for inline payloads.
 *
 * Preference:
 *  1) reeid_wc_request_lang() helper if available.
 *  2) First path segment in REQUEST_URI (e.g. /zh/product/...).
 */
if ( ! function_exists( 'reeid_wc_inline_detect_lang' ) ) {
    function reeid_wc_inline_detect_lang(): string {

        $lang = '';

        if ( function_exists( 'reeid_wc_request_lang' ) ) {
            $lang = (string) reeid_wc_request_lang();
        }

        if ( ! is_string( $lang ) ) {
            $lang = '';
        }

        $lang = strtolower( trim( $lang ) );

        // Fallback: derive from URL path /{lang}/product/...
        if ( $lang === '' || $lang === 'en' ) {

            // Safely read REQUEST_URI
            $req_uri = isset( $_SERVER['REQUEST_URI'] )
                ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
                : '';

            if ( $req_uri !== '' ) {

                // wp_parse_url() required for WP.org compliance
                $parsed = wp_parse_url( $req_uri );

                $path = '';
                if ( is_array( $parsed ) && isset( $parsed['path'] ) ) {
                    $path = $parsed['path'];
                }

                if ( is_string( $path ) ) {
                    $parts = explode( '/', trim( $path, '/' ) );

                    if ( isset( $parts[0] ) && $parts[0] !== '' && strlen( $parts[0] ) <= 5 ) {
                        $lang = strtolower( sanitize_text_field( $parts[0] ) );
                    }
                }
            }
        }

        // Do not override EN; empty string means "no inline override".
        if ( $lang === 'en' ) {
            return '';
        }

        return $lang;
    }
}


/**
 * Helper: get inline payload array for current product + lang.
 *
 * Returns array with keys like 'title', 'excerpt', 'content', 'slug',
 * or null if nothing is available / not on product page.
 */
if ( ! function_exists( 'reeid_wc_inline_get_payload_for_current' ) ) {
    function reeid_wc_inline_get_payload_for_current( int $post_id = 0 ) {
        if ( $post_id <= 0 ) {
            $post_id = get_the_ID() ?: 0;
        }

        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) {
            return null;
        }

        if ( get_post_type( $post_id ) !== 'product' ) {
            return null;
        }

        $lang = reeid_wc_inline_detect_lang();
        if ( $lang === '' ) {
            return null;
        }

        $packet = get_post_meta( $post_id, "_reeid_wc_tr_{$lang}", true );
        return is_array( $packet ) ? $packet : null;
    }
}

/**
 * Override product TITLE (H1) on single product pages.
 */
if ( ! function_exists( 'reeid_wc_inline_title_filter' ) ) {
    function reeid_wc_inline_title_filter( $title, $post_id = 0 ) {
        if ( is_admin() ) {
            return $title;
        }

        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return $title;
        }

        $post_id = (int) ( $post_id ?: ( get_the_ID() ?: 0 ) );
        if ( $post_id <= 0 || get_post_type( $post_id ) !== 'product' ) {
            return $title;
        }

        $pl = reeid_wc_inline_get_payload_for_current( $post_id );
        if ( ! is_array( $pl ) ) {
            return $title;
        }

        if ( ! empty( $pl['title'] ) && is_string( $pl['title'] ) ) {
            return (string) $pl['title'];
        }

        return $title;
    }

    // Priority very high to win over other filters.
    add_filter( 'the_title', 'reeid_wc_inline_title_filter', 9999, 2 );
}

/**
 * Also override WooCommerce internal product name getter so themes/widgets
 * using $product->get_name() see the inline translated title.
 */
if ( ! function_exists( 'reeid_wc_inline_product_name_filter' ) ) {
    function reeid_wc_inline_product_name_filter( $name, $product ) {
        if ( is_admin() ) {
            return $name;
        }

        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return $name;
        }

        if ( ! $product || ! method_exists( $product, 'get_id' ) ) {
            return $name;
        }

        $post_id = (int) $product->get_id();
        if ( $post_id <= 0 || get_post_type( $post_id ) !== 'product' ) {
            return $name;
        }

        $pl = reeid_wc_inline_get_payload_for_current( $post_id );
        if ( ! is_array( $pl ) ) {
            return $name;
        }

        if ( ! empty( $pl['title'] ) && is_string( $pl['title'] ) ) {
            return (string) $pl['title'];
        }

        return $name;
    }

    add_filter( 'woocommerce_product_get_name', 'reeid_wc_inline_product_name_filter', 9999, 2 );
}

/**
 * Override SHORT DESCRIPTION (box above price) using inline "excerpt".
 */
if ( ! function_exists( 'reeid_wc_inline_short_desc_filter' ) ) {
    function reeid_wc_inline_short_desc_filter( $short ) {
        if ( is_admin() ) {
            return $short;
        }

        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return $short;
        }

        $post_id = get_the_ID() ?: 0;
        $post_id = (int) $post_id;

        if ( $post_id <= 0 || get_post_type( $post_id ) !== 'product' ) {
            return $short;
        }

        $pl = reeid_wc_inline_get_payload_for_current( $post_id );
        if ( ! is_array( $pl ) ) {
            return $short;
        }

        if ( ! empty( $pl['excerpt'] ) && is_string( $pl['excerpt'] ) ) {
            return (string) $pl['excerpt'];
        }

        return $short;
    }

    // Woo uses apply_filters( 'woocommerce_short_description', $post->post_excerpt )
    add_filter( 'woocommerce_short_description', 'reeid_wc_inline_short_desc_filter', 9999, 1 );
}

/**
 * Ensure our inline title filters are attached on frontend product views,
 * even if other code removed filters earlier.
 */
if ( ! function_exists( 'reeid_wc_attach_inline_title_late' ) ) {
    function reeid_wc_attach_inline_title_late() {
        if ( is_admin() ) {
            return;
        }
        // Re-attach with very high priority; safe if already added.
        add_filter( 'the_title', 'reeid_wc_inline_title_filter', 9999, 2 );
        add_filter( 'woocommerce_product_get_name', 'reeid_wc_inline_product_name_filter', 9999, 2 );
    }
    add_action( 'template_redirect', 'reeid_wc_attach_inline_title_late', 1 );
}
