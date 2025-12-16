<?php


/**
 * Endpoint used by the metabox (Gutenberg/Classic) and Elementor panel.
 * Validates input, enqueues a background job, and redirects back.
 *
 * POST to: admin_url('admin-post.php')
 * Fields:
 *   action        = reeid_translate
 *   _wpnonce      = reeid_translate_nonce_{post_id}
 *   post_id
 *   target_lang
 *   tone
 *   publish_mode
 *   prompt
 */
add_action( 'admin_post_reeid_translate', 'reeid_admin_post_translate' );

function reeid_admin_post_translate() {

    /* -------------------------------------
       0) Admin context guard
    ------------------------------------- */
    if ( ! is_admin() ) {
        wp_die( esc_html__( 'Unauthorized.', 'reeid-translate' ) );
    }

    /* -------------------------------------
       1) Required: post_id
    ------------------------------------- */
    $post_id_raw = filter_input( INPUT_POST, 'post_id', FILTER_UNSAFE_RAW );
    $post_id     = $post_id_raw ? absint( wp_unslash( $post_id_raw ) ) : 0;

    if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'reeid-translate' ) );
    }

    /* -------------------------------------
       2) Verify nonce: reeid_translate_nonce_{post_id}
    ------------------------------------- */
    $nonce_raw = filter_input( INPUT_POST, '_wpnonce', FILTER_UNSAFE_RAW );
    $nonce     = is_string( $nonce_raw ) ? sanitize_text_field( wp_unslash( $nonce_raw ) ) : '';

    if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'reeid_translate_nonce_' . $post_id ) ) {
        wp_die( esc_html__( 'Security check failed.', 'reeid-translate' ) );
    }

    /* -------------------------------------
       3) Input parameters (safe)
    ------------------------------------- */
    $lang_raw   = filter_input( INPUT_POST, 'target_lang',   FILTER_UNSAFE_RAW );
    $tone_raw   = filter_input( INPUT_POST, 'tone',          FILTER_UNSAFE_RAW );
    $mode_raw   = filter_input( INPUT_POST, 'publish_mode',  FILTER_UNSAFE_RAW );
    $prompt_raw = filter_input( INPUT_POST, 'prompt',        FILTER_UNSAFE_RAW );

    $target_lang  = $lang_raw   ? sanitize_text_field( wp_unslash( $lang_raw ) )   : '';
    $tone         = $tone_raw   ? sanitize_text_field( wp_unslash( $tone_raw ) )   : 'Neutral';
    $publish_mode = $mode_raw   ? sanitize_text_field( wp_unslash( $mode_raw ) )   : 'draft';
    $prompt       = $prompt_raw ? sanitize_text_field( wp_unslash( $prompt_raw ) ) : '';

    /* -------------------------------------
       4) Background job handler availability
    ------------------------------------- */
    if ( ! function_exists( 'reeid_translation_job_enqueue' ) ) {
        wp_die( esc_html__( 'Background system not available.', 'reeid-translate' ) );
    }

    /* -------------------------------------
       5) Enqueue job
    ------------------------------------- */
    $job_id = reeid_translation_job_enqueue(
        array(
            'type'        => 'single',
            'post_id'     => $post_id,
            'target_lang' => $target_lang,
            'user_id'     => get_current_user_id(),
            'params'      => array(
                'tone'         => $tone,
                'publish_mode' => $publish_mode,
                'prompt'       => $prompt,
            ),
        )
    );

    /* -------------------------------------
       6) Redirect back to edit screen
    ------------------------------------- */
    $back = get_edit_post_link( $post_id, 'raw' );
    if ( ! $back ) {
        $back = admin_url( 'edit.php' );
    }

    // Append status args
    $back = add_query_arg(
        array(
            'reeid_job' => absint( $job_id ),
            'reeid_msg' => 'queued',
        ),
        $back
    );

    wp_safe_redirect( esc_url_raw( $back ) );
    exit;
}
