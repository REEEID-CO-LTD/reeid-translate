<?php
/**
 * REEID Translation – Sync Jobs Stub
 * comment: keep same API as background jobs, but run now (no CPT/cron).
 */
defined('ABSPATH') || exit;

/**
 * Enqueue (sync): match the old return shape so callers don't break.
 * $args: array('type'=>'single'|'bulk', 'post_id'=>int, 'target_lang'=>string, 'user_id'=>int, 'params'=>array)
 */
if ( ! function_exists('reeid_translation_job_enqueue') ) {
    function reeid_translation_job_enqueue( $args ) {
        $type        = isset($args['type']) ? $args['type'] : 'single';
        $post_id     = isset($args['post_id']) ? (int)$args['post_id'] : 0;
        $target_lang = isset($args['target_lang']) ? (string)$args['target_lang'] : '';
        $params      = (isset($args['params']) && is_array($args['params'])) ? $args['params'] : array();
    
        if ( ! isset($params['target_lang']) ) {
            $params['target_lang'] = $target_lang;
        }
    
        // Run translation NOW (no queue)
        if ( 'bulk' === $type && function_exists('reeid_background_bulk_translation_logic') ) {
            reeid_background_bulk_translation_logic( $post_id, $params );
        } elseif ( function_exists('reeid_background_single_translation_logic') ) {
            reeid_background_single_translation_logic( $post_id, $params );
        } else {
            return new WP_Error('reeid_sync_handler_missing', 'Sync translation handlers not found.');
        }
    
        // Return a positive pseudo job id so existing UI treats it as “queued/done”
        return (int) ( time() % 2147483647 ); // e.g., 10-digit number
    }
    
}

/* No-op worker hook so admin “fire worker” buttons don’t fatal. */
add_action('reeid_translation_job_worker', function(){ /* intentionally empty (sync mode) */ });
