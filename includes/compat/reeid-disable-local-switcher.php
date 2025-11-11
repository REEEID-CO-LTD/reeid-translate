<?php
if (!defined('ABSPATH')) exit;

/* 1) Unhook the switcher that our include registers */
add_action('init', function () {
    // It may be added at 3 or 6; remove both just in case
    remove_action('woocommerce_single_product_summary', 'reeid_wc_render_switcher', 3);
    remove_action('woocommerce_single_product_summary', 'reeid_wc_render_switcher', 6);
    remove_shortcode('reeid_product_lang_switcher');
}, 20);

/* 2) Hide any leftover markup if already printed (defensive) */
add_action('wp_head', function () {
    echo "<style>.reeid-wc-switcher{display:none!important}</style>\n";
}, 99);
