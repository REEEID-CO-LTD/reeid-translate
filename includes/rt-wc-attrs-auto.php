<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper: safely get request path once.
 */
if ( ! function_exists( 'reeid_get_request_path' ) ) {
	function reeid_get_request_path(): string {

		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$uri   = wp_unslash( $_SERVER['REQUEST_URI'] );
		$parts = wp_parse_url( $uri );

		return isset( $parts['path'] ) ? (string) $parts['path'] : '';
	}
}

/**
 * Inline WC attribute injector (packet-based)
 *
 * Reads translated attributes from:
 *   _reeid_wc_tr_{lang}['attributes']
 *
 * This is the ONLY correct way for inline Woo translations.
 */
add_filter(
	'woocommerce_display_product_attributes',
	function ( $attrs, $product ) {

		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $attrs;
		}

		// --- FIX #1: correct language detection ---
		$lang = function_exists( 'reeid_wc_current_lang' )
			? (string) reeid_wc_current_lang()
			: '';

		// Hard fallback: derive from URL if helper fails
		if ( $lang === '' || $lang === 'en' ) {

			$path = reeid_get_request_path();
			if ( $path !== '' ) {
				$seg = explode( '/', trim( $path, '/' ) );
				if ( ! empty( $seg[0] ) && strlen( $seg[0] ) === 2 ) {
					$lang = $seg[0];
				}
			}
		}

		// Source language → do nothing
		if ( $lang === '' || $lang === 'en' ) {
			return $attrs;
		}

		// Get original attributes from DB
		$pid  = (int) $product->get_id();
		$orig = get_post_meta( $pid, '_product_attributes', true );

		if ( ! is_array( $orig ) || empty( $orig ) ) {
			return $attrs;
		}

		foreach ( $attrs as $wc_key => &$row ) {

			// Woo key format: attribute_{slug}
			if ( strpos( $wc_key, 'attribute_' ) !== 0 ) {
				continue;
			}

			$slug = substr( $wc_key, 10 ); // remove "attribute_"

			if ( empty( $orig[ $slug ] ) ) {
				continue;
			}

			$src_name  = (string) ( $orig[ $slug ]['name'] ?? '' );
			$src_value = (string) ( $orig[ $slug ]['value'] ?? '' );

			if ( $src_name === '' && $src_value === '' ) {
				continue;
			}

			if ( function_exists( 'reeid_translate_line' ) ) {

				$tr_name = reeid_translate_line( $src_name, $lang, 'wc_attr_label' );
				$tr_val  = reeid_translate_line( $src_value, $lang, 'wc_attr_value' );

				if ( $tr_name !== '' ) {
					$row['label'] = esc_html( $tr_name );
				}

				if ( $tr_val !== '' ) {
					$row['value'] = esc_html( $tr_val );
				}
			}
		}
		unset( $row );

		return $attrs;
	},
	20,
	2
);
