<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unicode slug: lowercase (where applicable),
 * keep letters/numbers/marks, collapse spaces to “-”,
 * drop other symbols.
 */
function rt_unicode_slugify( $title ) {
	$t = html_entity_decode( (string) $title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	$t = strip_shortcodes( $t );
	$t = wp_strip_all_tags( $t, true );
	$t = preg_replace( '/\s+/u', ' ', trim( $t ) );
	$t = function_exists( 'mb_strtolower' ) ? mb_strtolower( $t, 'UTF-8' ) : strtolower( $t );
	$t = preg_replace( '/\s+/u', '-', $t );
	$t = preg_replace( '/[^\p{L}\p{N}\p{M}\-._]/u', '', $t );
	$t = preg_replace( '/-+/u', '-', $t );

	return trim( $t, '-._' );
}

/**
 * Enforce native (Unicode) slug if needed.
 * SAVE-TIME ONLY — never during request routing.
 */
function rt_keep_native_slug_if_needed( $post ) {
	if ( ! is_object( $post ) || empty( $post->ID ) ) {
		return;
	}

	$slug  = (string) $post->post_name;
	$title = (string) $post->post_title;
	$want  = rt_unicode_slugify( $title );

	if ( ! $want ) {
		return;
	}

	$slug_is_ascii = (bool) preg_match( '/^[\x00-\x7F\-._]+$/', $slug );

	if ( $slug !== $want || $slug_is_ascii ) {
		$post_type = $post->post_type ?: 'post';
		$unique    = wp_unique_post_slug(
			$want,
			$post->ID,
			$post->post_status,
			$post_type,
			$post->post_parent
		);

		remove_action( 'save_post', 'rt_native_slug_on_save', 20 );
		wp_update_post(
			array(
				'ID'        => $post->ID,
				'post_name'=> $unique,
			),
			true
		);
		add_action( 'save_post', 'rt_native_slug_on_save', 20, 2 );
	}
}

/**
 * Hook: save_post
 * Native slug enforcement happens ONLY here.
 */
function rt_native_slug_on_save( $post_id, $post ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}

	rt_keep_native_slug_if_needed( $post );
}
add_action( 'save_post', 'rt_native_slug_on_save', 20, 2 );

/**
 * Native slugs for WooCommerce products (insert/update).
 * Safe: runs before insert, no routing side effects.
 */
if ( ! function_exists( 'reeid_native_slug_products' ) ) {
	add_filter(
		'wp_insert_post_data',
		function ( $data, $postarr ) {
			if ( ( $data['post_type'] ?? '' ) !== 'product' ) {
				return $data;
			}

			if ( empty( $data['post_title'] ) ) {
				return $data;
			}

			$id   = absint( $postarr['ID'] ?? 0 );
			$lang = $id
				? get_post_meta( $id, '_reeid_translation_lang', true )
				: ( $postarr['_reeid_translation_lang'] ?? '' );

			$is_tr = ! empty( $lang );

			$title      = (string) $data['post_title'];
			$has_native = (bool) preg_match( '/[^\x00-\x7F]/u', $title );

			// Skip: non-translated + pure ASCII titles
			if ( ! $is_tr && ! $has_native ) {
				return $data;
			}

			$slug = function_exists( 'reeid_sanitize_native_slug' )
				? reeid_sanitize_native_slug( $title )
				: sanitize_title( $title );

			if ( ! $slug ) {
				return $data;
			}

			$status = $data['post_status'] ?? 'draft';
			$parent = absint( $data['post_parent'] ?? 0 );

			$data['post_name'] = wp_unique_post_slug(
				$slug,
				$id,
				$status,
				'product',
				$parent
			);

			return $data;
		},
		20,
		2
	);
}
