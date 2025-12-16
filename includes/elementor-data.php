<?php
// Elementor data sanitizer and helpers
// File: includes/elementor-data.php



/**
 * Safely retrieve and decode Elementor data for a given post.
 *
 * @param int $post_id Post ID.
 * @return array|object|false Decoded Elementor data, or false if invalid.
 */
function reeid_get_sanitized_elementor_data( $post_id ) {

    $post_id = absint( $post_id );
    if ( ! $post_id ) {
        return false;
    }

    // Always read raw meta (Elementor stores JSON)
    $raw = get_post_meta( $post_id, '_elementor_data', true );

    // Nothing stored → safe exit
    if ( '' === $raw || null === $raw ) {
        return false;
    }

    /*--------------------------------------------------------------------------
       1) Already array or object → Elementor sometimes pre-processes this
    --------------------------------------------------------------------------*/
    if ( is_array( $raw ) || is_object( $raw ) ) {
        return $raw;
    }

    /*--------------------------------------------------------------------------
       2) Try strict JSON decode
    --------------------------------------------------------------------------*/
    $decoded = json_decode( $raw, true );
    if ( JSON_ERROR_NONE === json_last_error() && ( is_array( $decoded ) || is_object( $decoded ) ) ) {
        return $decoded;
    }

    /*--------------------------------------------------------------------------
       3) Serialized fallback (WordPress serialization)
       Note: Use wp_unslash first, avoid direct is_serialized() warnings.
    --------------------------------------------------------------------------*/
    $raw_unslashed = wp_unslash( $raw );

    if ( is_string( $raw_unslashed ) ) {
        // Avoid direct call to is_serialized() without sanitizing
        if ( preg_match( '/^[aOs]:[0-9]+:/', $raw_unslashed ) && is_serialized( $raw_unslashed ) ) {
            $maybe = maybe_unserialize( $raw_unslashed );
            if ( is_array( $maybe ) || is_object( $maybe ) ) {
                return $maybe;
            }
        }
    }

    /*--------------------------------------------------------------------------
       4) Optional debug dump (allowed *only* in full debugging mode).
       - Respect WP_DEBUG  
       - Respect explicit REEID_DEBUG_ELEMENTOR_DUMP flag  
       - Never write files silently
    --------------------------------------------------------------------------*/
    if (
        defined( 'WP_DEBUG' ) && WP_DEBUG &&
        defined( 'REEID_DEBUG_ELEMENTOR_DUMP' ) &&
        REEID_DEBUG_ELEMENTOR_DUMP
    ) {
        $dump_path = plugin_dir_path( __FILE__ ) . 'failed-elementor-' . $post_id . '.dump.json';

        $payload = array(
            'post_id' => $post_id,
            'raw'     => $raw,
            'error'   => 'Could not decode Elementor JSON or serialized data.',
        );

        // Safe JSON encoding
        $json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

        // File writes only permitted for debugging, safe mode:
        file_put_contents( $dump_path, $json );
    }

    /*--------------------------------------------------------------------------
       5) Return failure
    --------------------------------------------------------------------------*/
    return false;
}
