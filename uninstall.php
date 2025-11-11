<?php
/**
 * Uninstall script for REEID Translation (Universal)
 *
 * This file is executed when the plugin is uninstalled. It removes all plugin-related
 * options, transients, and postmeta entries to clean up the database.
 * 
 * If you want to also delete all translated posts, uncomment the relevant section below.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/* ---------------------------------------------------------------------------
 * SECTION 1: Delete plugin options & settings
 * ------------------------------------------------------------------------- */
delete_option( 'reeid_openai_api_key' );
delete_option( 'reeid_openai_model' );
delete_option( 'reeid_translation_tones' );
delete_option( 'reeid_translation_custom_prompt' );
delete_option( 'reeid_translation_source_lang' );
delete_option( 'reeid_bulk_translation_langs' );
delete_option( 'reeid_license_key' );
delete_option( 'reeid_license_status' );
delete_option( 'reeid_translation_map' );
delete_option( 'reeid_translation_enabled_languages' ); // If present

/* ---------------------------------------------------------------------------
 * SECTION 2: Delete transients & cached statuses
 * ------------------------------------------------------------------------- */
delete_transient( 'reeid_license_status' );
delete_transient( 'reeid_translation_debug' );

/* ---------------------------------------------------------------------------
 * SECTION 3: Delete all postmeta entries associated with translations
 * ------------------------------------------------------------------------- */
delete_post_meta_by_key( '_reeid_translation_lang' );
delete_post_meta_by_key( '_reeid_translation_source' );
delete_post_meta_by_key( '_reeid_translation_map' );
delete_post_meta_by_key( '_elementor_data' );
delete_post_meta_by_key( '_elementor_edit_mode' );
delete_post_meta_by_key( '_elementor_template_type' );
delete_post_meta_by_key( '_elementor_page_settings' );

/* ---------------------------------------------------------------------------
 * SECTION 4: (Optional) Delete all translated posts
 * ---------------------------------------------------------------------------
 * If you want to remove every translated post created by REEID Translation,
 * uncomment the following block. Be cautious: this will permanently delete all
 * posts whose _reeid_translation_lang meta key is set (regardless of status).
 */
// $args = array(
//     'post_type'      => array( 'post', 'page' ),
//     'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
//     'meta_key'       => '_reeid_translation_lang',
//     'posts_per_page' => -1,
//     'fields'         => 'ids',
// );
// $translated_posts = get_posts( $args );
// if ( ! empty( $translated_posts ) ) {
//     foreach ( $translated_posts as $translated_id ) {
//         wp_delete_post( $translated_id, true );
//     }
// }

/* ---------------------------------------------------------------------------
 * SECTION 5: Extra house-cleaning (if needed)
 * ---------------------------------------------------------------------------
 * Add any additional plugin-specific cleanup here.
 */
