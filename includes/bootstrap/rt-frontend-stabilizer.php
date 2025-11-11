<?php
/**
 * RT frontend stabilizer:
 * 1) Guard menu items to avoid stdClass::$object notices.
 * 2) For Gutenberg/Classic pages ONLY, remove any the_content filters originating from reeid-translate.php
 *    so saved blocks render cleanly. Elementor untouched.
 */
if (!defined('ABSPATH')) exit;

/* 1) MENU GUARD */
add_filter('wp_nav_menu_objects', function(array $items, $args){
    foreach ($items as $it) {
        if (!is_object($it)) continue;
        if (!property_exists($it, 'object') || !is_string($it->object)) $it->object = 'custom';
        if (!property_exists($it, 'object_id') || !is_numeric($it->object_id)) $it->object_id = 0;
    }
    return $items;
}, 1, 2);

/* 2) KILL REEID the_content CLOSURES for Gutenberg/Classic only */
add_filter('the_content', function($content){
    // Skip Elementor/non-Gutenberg (we only act if Gutenberg markers exist)
    if ($content === '' || stripos($content, '<!-- wp:') === false) return $content;

    global $wp_filter;
    if (!isset($wp_filter['the_content'])) return $content;

    $callbacks = $wp_filter['the_content']->callbacks;
    if (!is_array($callbacks)) return $content;

    foreach ($callbacks as $prio => $list) {
        foreach ($list as $cb_key => $cb) {
            $fn = $cb['function'];
            $file = null;
            if ($fn instanceof Closure) {
                $ref = new ReflectionFunction($fn);
                $file = $ref->getFileName();
            } elseif (is_array($fn) && is_object($fn[0])) {
                try { $ref = new ReflectionMethod($fn[0], $fn[1]); $file = $ref->getFileName(); } catch (Throwable $e) {}
            } elseif (is_string($fn) && function_exists($fn)) {
                $ref = new ReflectionFunction($fn);
                $file = $ref->getFileName();
            }
            if ($file && substr($file, -19) === 'reeid-translate.php') {
                remove_filter('the_content', $fn, $prio);
            }
        }
    }
    return $content;
}, 1);
