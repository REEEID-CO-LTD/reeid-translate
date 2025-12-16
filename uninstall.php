<?php
/**
 * Uninstall script for REEID Translation (Universal)
 *
 * Safe by default:
 * - Always removes plugin settings
 * - Deletes content ONLY if user explicitly opted in
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/* ---------------------------------------------------------------------------
 * SECTION 1: Always delete plugin options
 * ------------------------------------------------------------------------- */
$opts = [
    'reeid_openai_api_key',
    'reeid_openai_model',
    'reeid_translation_tones',
    'reeid_translation_custom_prompt',
    'reeid_translation_source_lang',
    'reeid_bulk_translation_langs',
    'reeid_license_key',
    'reeid_license_status',
    'reeid_license_last_msg',
    'reeid_license_last_code',
    'reeid_license_checked_at',
    'reeid_delete_data_on_uninstall',
];

foreach ( $opts as $opt ) {
    delete_option( $opt );
}

/* ---------------------------------------------------------------------------
 * SECTION 2: Stop here unless user explicitly allowed data removal
 * ------------------------------------------------------------------------- */
$delete_data = (bool) get_option( 'reeid_delete_data_on_uninstall', false );

if ( ! $delete_data ) {
    return;
}

/* ---------------------------------------------------------------------------
 * SECTION 3: Delete REEID translation metadata ONLY
 * ------------------------------------------------------------------------- */
delete_post_meta_by_key( '_reeid_translation_lang' );
delete_post_meta_by_key( '_reeid_translation_source' );
delete_post_meta_by_key( '_reeid_translation_map' );
delete_post_meta_by_key( '_reeid_wc_inline_langs' );

/* Pattern-based cleanup (safe, REEID only) */
global $wpdb;
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta}
     WHERE meta_key LIKE '_reeid_%'"
);

/*
 * IMPORTANT:
 * - Elementor core meta is NOT touched
 * - Posts are NOT deleted
 * - Users must delete translations manually if desired
 */
