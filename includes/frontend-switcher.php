<?php
/**
 * Front-End Switcher Assets
 * SECTION 26 : FRONT-END SWITCHER ASSETS (Modularized)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*==============================================================================
 SECTION 26 : FRONT-END SWITCHER ASSETS (CLEANED for Modern CSS)
==============================================================================*/

add_action('wp_enqueue_scripts', 'reeid_enqueue_switcher_assets');
function reeid_enqueue_switcher_assets()
{
    wp_enqueue_style(
        'reeid-switcher-style',
        plugins_url('assets/css/switcher.css', dirname(__FILE__) . '/../reeid-translate.php'),
        [],
        '1.0'
    );

    wp_enqueue_script(
        'reeid-switcher-script',
        plugins_url('assets/js/switcher.js', dirname(__FILE__) . '/../reeid-translate.php'),
        ['jquery'],
        '1.0',
        true
    );
}

/**
 * Inject alignment CSS in <head>
 */
add_action('wp_head', 'reeid_inject_switcher_alignment');
function reeid_inject_switcher_alignment()
{
    $align = esc_attr(get_option('reeid_switcher_alignment', 'center'));

    if (in_array($align, ['left', 'center', 'right'], true)) {
        printf(
            '<style id="reeid-switcher-alignment-css">#reeid-switcher-container { text-align: %s; }</style>',
            esc_html($align)
        );
    }
}
