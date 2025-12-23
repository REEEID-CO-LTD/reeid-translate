<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp', function () {

    if ( ! is_product() ) {
        return;
    }

    $uri    = $_SERVER['REQUEST_URI'] ?? 'NO_URI';
    $cookie = $_COOKIE['site_lang'] ?? 'NO_COOKIE';
    $lang   = function_exists('reeid_wc_current_lang')
        ? reeid_wc_current_lang()
        : 'NO_FUNC';

    error_log(
        '[REEID LANG TRACE] URI=' . $uri .
        ' | COOKIE=' . $cookie .
        ' | LANG=' . $lang
    );
}, 1 );
