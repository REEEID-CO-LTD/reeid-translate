<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REEID SAFETY GUARD
 *
 * Purpose:
 * - Ensure ONLY ONE template_redirect hook owned by REEID runs
 * - Prevent routing / query corruption forever
 *
 * This file DOES NOT add routing.
 * It only removes duplicates.
 */

add_action( 'init', function () {

    global $wp_filter;

    if ( empty( $wp_filter['template_redirect'] ) ) {
        return;
    }

    $allowed = [
        'reeid_emit_hreflang', // ← the ONLY allowed handler
    ];

    foreach ( $wp_filter['template_redirect']->callbacks as $priority => $callbacks ) {
        foreach ( $callbacks as $id => $cb ) {

            // Named functions only (closures are unsafe here)
            if ( is_string( $cb['function'] ) ) {

                if ( ! in_array( $cb['function'], $allowed, true ) ) {
                    remove_action( 'template_redirect', $cb['function'], $priority );
                }

            } else {
                // Remove ALL anonymous callbacks
                remove_action( 'template_redirect', $cb['function'], $priority );
            }
        }
    }

}, 0 );
