<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REEID — Single hreflang authority (wp_head only)
 *
 * Keeps:
 *   - reeid_output_hreflang_tags_seosync
 *
 * Removes:
 *   - ALL other REEID hreflang emitters
 *   - Woo-specific, simple, virtual, OB, force variants
 */

add_action( 'init', function () {

    // List of allowed wp_head callbacks
    $allow = [
        'reeid_output_hreflang_tags_seosync',
    ];

    // Known hreflang emitters to disable
    $kill = [
        'reeid_wc_hreflang',
        'reeid_wc_hreflang_simple',
        'reeid_wc_hreflang_products_virtual',
        'reeid_hreflang_products_virtual',
        'reeid_hreflang_emit_minimal',
        'reeid_hreflang_print',
        'reeid_hreflang_print_canonical',
    ];

    foreach ( $kill as $fn ) {
        remove_action( 'wp_head', $fn, 90 );
        remove_action( 'wp_head', $fn, 96 );
        remove_action( 'wp_head', $fn, 99 );
        remove_action( 'wp_head', $fn, 100 );
    }

    // Defensive sweep: remove any other REEID hreflang emitter
    global $wp_filter;
    if ( empty( $wp_filter['wp_head'] ) ) {
        return;
    }

    foreach ( $wp_filter['wp_head']->callbacks as $priority => $callbacks ) {
        foreach ( $callbacks as $cb ) {
            if ( is_string( $cb['function'] )
                && str_starts_with( $cb['function'], 'reeid_' )
                && str_contains( $cb['function'], 'hreflang' )
                && ! in_array( $cb['function'], $allow, true )
            ) {
                remove_action( 'wp_head', $cb['function'], $priority );
            }
        }
    }

}, 0 );
