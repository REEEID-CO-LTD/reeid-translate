<?php
/**
 * Elementor JSON Walkers (Sealed Rules + Fallback)
 * SECTION 15 MOVED FROM reeid-translate.php
 *
 * This file should contain the server-driven JSON walkers, sealed rule
 * decryptors, key filters, and fallback logic used to sanitize and reconstruct
 * Elementor data structures.
 */


/**
 * Fetch sealed walker rules from api.reeid.com and decrypt with the site keypair.
 * Returns: ['translatable_keys'=>[], 'skip_keys'=>[]].
 * Caches in a transient for 12h.
 */
if ( ! function_exists( 'reeid_elementor_rules' ) ) {
    function reeid_elementor_rules(): array {

        $cache_key = 'reeid_elem_rules_sealed_v1';
        $cached    = get_transient( $cache_key );

        if ( is_array( $cached ) && ! empty( $cached['translatable_keys'] ) ) {
            return $cached;
        }

        // Ensure handshake credentials
        $site_token  = (string) get_option( 'reeid_site_token', '' );
        $site_secret = (string) get_option( 'reeid_site_secret', '' );

        if ( ! $site_token || ! $site_secret ) {
            if ( function_exists( 'reeid_api_handshake' ) ) {

                $hs = reeid_api_handshake( false );
                if ( empty( $hs['ok'] ) ) {
                    $hs = reeid_api_handshake( true );
                }

                $site_token  = (string) get_option( 'reeid_site_token', '' );
                $site_secret = (string) get_option( 'reeid_site_secret', '' );
            }
        }

        if ( ! $site_token || ! $site_secret ) {
            return reeid_elementor_rules_fallback( 'no_creds' );
        }

        // Build signed GET
        $ts    = (string) time();
        $nonce = bin2hex( random_bytes( 12 ) );
        $sig   = hash_hmac( 'sha256', $ts . "\n" . $nonce . "\n", $site_secret );

        $base = defined( 'REEID_API_BASE' ) ? REEID_API_BASE : 'https://api.reeid.com';
        $url  = rtrim( $base, '/' ) . '/v1/walkers/elementor-rules?site_token=' . rawurlencode( $site_token );

        $resp = wp_remote_get(
            $url,
            array(
                'headers' => array(
                    'X-REEID-Ts'    => $ts,
                    'X-REEID-Nonce' => $nonce,
                    'X-REEID-Sig'   => $sig,
                    'Accept'        => 'application/json',
                ),
                'timeout' => 20,
            )
        );

        if ( is_wp_error( $resp ) ) {
            return reeid_elementor_rules_fallback( 'http_error' );
        }

        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( 200 !== $code ) {
            return reeid_elementor_rules_fallback( 'bad_http_' . $code );
        }

        $body = (string) wp_remote_retrieve_body( $resp );
        $json = json_decode( $body, true );

        if ( ! is_array( $json ) || empty( $json['ok'] ) || empty( $json['sealed'] ) || empty( $json['sip_token'] ) ) {
            return reeid_elementor_rules_fallback( 'bad_payload' );
        }

        // Decrypt sealed token with libsodium
        $kp_b64 = (string) get_option( 'reeid_kp_secret', '' );
        if ( '' === $kp_b64 || ! function_exists( 'sodium_crypto_box_seal_open' ) ) {
            return reeid_elementor_rules_fallback( 'no_keypair' );
        }

        $kp     = base64_decode( $kp_b64, true );
        $cipher = base64_decode( (string) $json['sip_token'], true );

        if ( false === $kp || false === $cipher ) {
            return reeid_elementor_rules_fallback( 'bad_b64' );
        }

        $plain = sodium_crypto_box_seal_open( $cipher, $kp );
        if ( false === $plain ) {
            return reeid_elementor_rules_fallback( 'decrypt_fail' );
        }

        $rules = json_decode( $plain, true );
        if ( ! is_array( $rules ) ) {
            return reeid_elementor_rules_fallback( 'json_fail' );
        }
        

        $out = array(
            'translatable_keys' => array_values(
                array_unique( array_map( 'strval', (array) ( $rules['translatable_keys'] ?? array() ) ) )
            ),
            'skip_keys'         => array_values(
                array_unique( array_map( 'strval', (array) ( $rules['skip_keys'] ?? array() ) ) )
            ),
        );

        if ( empty( $out['translatable_keys'] ) ) {
            return reeid_elementor_rules_fallback( 'empty_rules' );
        }

        // Provenance tracking
        update_option( 'reeid_walkers_source', 'api', false );
        update_option( 'reeid_walkers_version', (string) ( $json['version'] ?? '' ), false );
        update_option( 'reeid_walkers_fetched_at', time(), false );

        set_transient( $cache_key, $out, 12 * HOUR_IN_SECONDS );

        return $out;
    }
}

/**
 * Minimal built-in rules used if API fails or server-rules-only mode is set.
 */
if ( ! function_exists( 'reeid_elementor_rules_fallback' ) ) {
    function reeid_elementor_rules_fallback( string $reason = '' ): array {

        $force = (string) get_option( 'reeid_require_server_rules', '0' ) === '1';
        if ( $force ) {
            update_option( 'reeid_walkers_source', 'forced-off', false );
            update_option( 'reeid_walkers_reason', $reason, false );
            return array(
                'translatable_keys' => array(),
                'skip_keys'         => array(),
            );
        }

        update_option( 'reeid_walkers_source', 'fallback', false );
        update_option( 'reeid_walkers_reason', $reason, false );

        return array(
            'translatable_keys' => array(
                'editor',
                'text',
                'content',
                'title',
                'description',
                'html',
                'heading',
                'subtitle',
                'button_text',
                'label',
                'link_text',
                'caption',
                'placeholder',
            ),
            'skip_keys' => array(
                'type',
                'widgetType',
                'elType',
                'container_type',
                'layout',
                'skin',
                'tag',
                'id',
                'image_size',
                'columns',
                'column_width',
                'isInner',
                'isInnerSection',
                'elements',
                'settings',
                'key',
                'anchor',
                'gap',
                'html_tag',
            ),
        );
    }
}

/**
 * Recursively collect translatable text under any .settings. node.
 */
if ( ! function_exists( 'reeid_elementor_walk_and_collect' ) ) {
    function reeid_elementor_walk_and_collect( $data, string $path, array &$out ) {

        static $rules = null;
        if ( null === $rules ) {
            $rules = reeid_elementor_rules();
        }

        $translatable = (array) ( $rules['translatable_keys'] ?? array() );
        $skip_keys    = (array) ( $rules['skip_keys'] ?? array() );

        $last_key     = substr(
            (string) $path,
            (int) strrpos( (string) $path, '.' ) + 1
        );

        if ( is_array( $data ) || is_object( $data ) ) {
            foreach ( $data as $k => $v ) {
                $np = ( '' === $path ) ? (string) $k : "{$path}.{$k}";
                reeid_elementor_walk_and_collect( $v, $np, $out );
            }
            return;
        }

        if (
            is_string( $data ) &&
            strpos( (string) $path, '.settings.' ) !== false &&
            trim( $data ) !== '' &&
            ! preg_match( '/^(https?:|mailto:|tel:|data:|#)/i', $data ) &&
            ! in_array( $last_key, $skip_keys, true ) &&
            in_array( $last_key, $translatable, true )
        ) {
            $out[ $path ] = $data;
        }
    }
}

/**
 * Walk into the Elementor JSON tree by dotted path & replace value.
 */
if ( ! function_exists( 'reeid_elementor_walk_and_replace' ) ) {
    function reeid_elementor_walk_and_replace( &$data, string $path, $new ) {

        $parts = explode( '.', $path );
        $ref   = &$data;

        foreach ( $parts as $p ) {

            if ( is_array( $ref ) && array_key_exists( $p, $ref ) ) {
                $ref = &$ref[ $p ];
            }
            elseif ( is_object( $ref ) && isset( $ref->$p ) ) {
                $ref = &$ref->$p;
            }
            else {
                return; // key path not present
            }
        }

        $ref = $new;
    }
}
