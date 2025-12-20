<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/*==============================================================================
 Language Param & Cookie Sync (Global + Woo)
==============================================================================*/

if ( ! function_exists( 'reeid_langdbg' ) ) {
	function reeid_langdbg( $label, $data = null ) {
		if ( function_exists( 'reeid_debug_log' ) ) {
			reeid_debug_log( 'S26.7 ' . $label, $data );
		}
	}
}

/**
 * Normalize a candidate language code: lowercases and caps to 10 chars.
 */
if ( ! function_exists( 'reeid_normalize_lang' ) ) {
	function reeid_normalize_lang( $val ): string {
		$val = strtolower( substr( trim( (string) $val ), 0, 10 ) );
		return preg_match( '/^[a-z]{2}([-_][a-z0-9]{2})?$/i', $val ) ? $val : '';
	}
}

/**
 * 1) If ?reeid_force_lang=xx or ?lang=xx (WITH nonce):
 *      - set `site_lang` cookie
 *      - clean URL
 * 2) If no param but prefix /xx/... exists → sync cookie
 */
add_action( 'template_redirect', function () {

	if ( is_admin() || wp_doing_ajax() ) {
		return;
	}

	/* ---------------------------
	   (1) Query param override
	---------------------------- */

	$forced = '';

	// Resolve nonce (required to act)
	$nonce = '';
	if ( isset( $_GET['_reeid_nonce'] ) ) {
		$nonce = sanitize_text_field( wp_unslash( $_GET['_reeid_nonce'] ) );
	}

	$nonce_ok = $nonce && wp_verify_nonce( $nonce, 'reeid_force_lang' );

	// Securely pull GET parameters (but only act if nonce is valid)
	if ( $nonce_ok ) {
		if ( isset( $_GET['reeid_force_lang'] ) ) {
			$forced = reeid_normalize_lang(
				sanitize_text_field( wp_unslash( $_GET['reeid_force_lang'] ) )
			);
		} elseif ( isset( $_GET['lang'] ) ) {
			$forced = reeid_normalize_lang(
				sanitize_text_field( wp_unslash( $_GET['lang'] ) )
			);
		}
	}

	if ( $forced ) {

		// Avoid duplicate cookie sends
		$cookie_already_sent = false;
		if ( function_exists( 'headers_list' ) ) {
			foreach ( headers_list() as $hdr ) {
				if ( stripos( $hdr, 'Set-Cookie: site_lang=' ) === 0 ) {
					$cookie_already_sent = true;
					break;
				}
			}
		}

		$current_cookie = isset( $_COOKIE['site_lang'] )
			? sanitize_text_field( wp_unslash( $_COOKIE['site_lang'] ) )
			: '';

		if ( ! $cookie_already_sent || $current_cookie !== $forced ) {

			$home_parts = wp_parse_url( home_url() );
			$domain = ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN )
				? COOKIE_DOMAIN
				: ( $home_parts['host'] ?? '' );

			setcookie(
				'site_lang',
				$forced,
				array(
					'expires'  => time() + DAY_IN_SECONDS,
					'path'     => '/',
					'domain'   => $domain ?: '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);

			$_COOKIE['site_lang'] = $forced;
		}

		reeid_langdbg( 'FORCE_PARAM', array( 'set' => $forced ) );

		// Safe host
		$raw_host = '';
		if ( isset( $_SERVER['HTTP_HOST'] ) ) {
			$raw_host = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
		} elseif ( isset( $_SERVER['SERVER_NAME'] ) ) {
			$raw_host = sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) );
		}

		// Safe URI
		$raw_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '/';

		$scheme = is_ssl() ? 'https' : 'http';
		$parts  = wp_parse_url( $scheme . '://' . $raw_host . $raw_uri );

		$q = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $q );
			unset( $q['lang'], $q['reeid_force_lang'], $q['_reeid_nonce'] );
		}

		$clean = $parts['path'] ?? '/';
		if ( ! empty( $q ) ) {
			$clean .= '?' . http_build_query( $q );
		}
		if ( ! empty( $parts['fragment'] ) ) {
			$clean .= '#' . $parts['fragment'];
		}

		if ( ! headers_sent() ) {
			wp_safe_redirect( $clean, 302, 'reeid-translate' );
			exit;
		}

		return;
	}

	/* ---------------------------
	   (2) URL prefix → cookie sync
	---------------------------- */

	$uri = isset( $_SERVER['REQUEST_URI'] )
		? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
		: '';

	if ( $uri && preg_match( '#^/([a-z]{2}(?:-[a-zA-Z0-9]{2})?)/#', $uri, $m ) ) {
		$pathLang = reeid_normalize_lang( $m[1] );

		$current_cookie = isset( $_COOKIE['site_lang'] )
			? strtolower( sanitize_text_field( wp_unslash( $_COOKIE['site_lang'] ) ) )
			: '';

		if ( $pathLang && $current_cookie !== $pathLang ) {
			$_COOKIE['site_lang'] = $pathLang;
			reeid_langdbg( 'URL_PREFIX_SYNC', array( 'uri' => $uri, 'lang' => $pathLang ) );
		}
	}

}, 9 );
