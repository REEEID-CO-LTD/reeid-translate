<?php
/**
 * REEID — WooCommerce Language Runtime
 * Determines current language for frontend rendering.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'reeid_wc_current_lang' ) ) {

    function reeid_wc_current_lang(): string {

        static $lang = null;
        if ( $lang !== null ) {
            return $lang;
        }

        // 1) URL prefix: /my/, /ar/, /el/, etc.
        if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $uri = trim(
                wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ),
                '/'
            );
            $seg = strtolower( strtok( $uri, '/' ) );

            if ( preg_match( '/^[a-z]{2}(-[a-z]{2})?$/', $seg ) ) {
                return $lang = $seg;
            }
        }

        // 2) Cookie fallback
        if ( ! empty( $_COOKIE['site_lang'] ) ) {
            return $lang = strtolower( $_COOKIE['site_lang'] );
        }

        // 2.5) Inline WooCommerce product language (REEID)
        if ( is_singular( 'product' ) ) {
            global $post;

            if ( $post instanceof WP_Post ) {
                $langs = (array) get_post_meta(
                    $post->ID,
                    '_reeid_wc_inline_langs',
                    true
                );

                if ( ! empty( $langs ) ) {

                    // Prefer explicit request lang if present
                    if ( ! empty( $_GET['lang'] ) ) {
                        $req = strtolower(
                            sanitize_text_field( $_GET['lang'] )
                        );

                        if ( in_array( $req, $langs, true ) ) {
                            return $lang = $req;
                        }
                    }

                    // Otherwise use first non-source language
                    $source = strtolower(
                        (string) get_option(
                            'reeid_translation_source_lang',
                            'en'
                        )
                    );

                    foreach ( $langs as $l ) {
                        $l = strtolower( (string) $l );
                        if ( $l !== $source ) {
                            return $lang = $l;
                        }
                    }
                }
            }
        }

        // 3) Default source language (plugin setting)
        return $lang = strtolower(
            (string) get_option(
                'reeid_translation_source_lang',
                'en'
            )
        );
    }
}

/**
 * ------------------------------------------------------------
 * Propagate REEID language into WP / WooCommerce runtime
 * (NO rewrites, NO redirects, NO DB writes)
 * ------------------------------------------------------------
 */
add_action(
    'parse_request',
    function ( $wp ) {

        if ( is_admin() ) {
            return;
        }

        if ( ! function_exists( 'reeid_wc_current_lang' ) ) {
            return;
        }

        $lang = reeid_wc_current_lang();
        if ( ! $lang ) {
            return;
        }

        // Expose language to query vars for downstream filters
        $wp->query_vars['reeid_lang'] = $lang;
    },
    1
);


add_filter(
    'redirect_canonical',
    function ( $redirect_url, $requested_url ) {

        // Only frontend
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

        // Do NOT block source language
        if ( $lang === $source ) {
            return $redirect_url;
        }

        // Only WooCommerce product URLs
        if ( strpos( $requested_url, '/product/' ) === false ) {
            return $redirect_url;
        }

        // 🚫 STOP WordPress from rewriting translated slugs
        return false;

    },
    1,
    2
);

