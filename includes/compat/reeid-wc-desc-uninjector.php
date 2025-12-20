<?php
/**
 * REEID — Woo Description uninjection (surgical)
 * On single product pages, remove ONLY the anonymous closures from reeid-translate.php
 * that are hooked into `the_content` and known to meddle with Description rendering.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp', function () {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	$hook = 'the_content';
	global $wp_filter;

	if ( empty( $wp_filter[ $hook ] ) ) {
		return;
	}

	// Line numbers observed in logs
	$target_lines       = array( 450, 9861, 9990, 11825, 14159, 14695 );
	$target_file_suffix = '/wp-content/plugins/reeid-translate/reeid-translate.php';

	$callbacks = is_object( $wp_filter[ $hook ] )
		? $wp_filter[ $hook ]->callbacks
		: (array) $wp_filter[ $hook ];

	foreach ( $callbacks as $prio => $items ) {
		foreach ( $items as $arr ) {
			$fn = $arr['function'];

			if ( $fn instanceof \Closure ) {
				try {
					$rf   = new \ReflectionFunction( $fn );
					$file = $rf->getFileName();
					$line = (int) $rf->getStartLine();

					if (
						$file
						&& substr( $file, -strlen( $target_file_suffix ) ) === $target_file_suffix
						&& in_array( $line, $target_lines, true )
					) {
						remove_filter( $hook, $fn, (int) $prio );
					}
				} catch ( \Throwable $e ) {
					// silently ignore reflection errors
				}
			}
		}
	}
}, 0 );
