<?php
// REEID COMPAT: define safe fallback for reeid_url_to_postid_prefixed if missing
if ( ! function_exists( 'reeid_url_to_postid_prefixed' ) ) {
    /**
     * Best-effort fallback: attempt WP core url_to_postid() when the real, language-aware
     * implementation isn't loaded yet. Returns int post ID or 0.
     */
    function reeid_url_to_postid_prefixed( string $url ) {
        if ( function_exists( 'url_to_postid' ) ) {
            return (int) url_to_postid( $url );
        }
        return 0;
    }
}
