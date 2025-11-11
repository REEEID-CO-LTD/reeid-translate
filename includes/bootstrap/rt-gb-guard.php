<?php
if (!defined('ABSPATH')) exit;

/**
 * RT Gutenberg guard:
 * - If content contains Gutenberg markers, remove any the_content filters registered from reeid-translate.php.
 * - Then, normalize bad <p> wrappers that break block layout.
 */

add_filter('the_content', function($html){
// Only intervene for Gutenberg content
if ($html === '' || stripos($html, '<!-- wp:') === false) return $html;

// 1) Unhook the_content callbacks sourced from the translation plugin file
global $wp_filter;
if (isset($wp_filter['the_content'])) {
$filter = $wp_filter['the_content'];
$callbacks = is_object($filter) && isset($filter->callbacks) ? $filter->callbacks : (array)$filter;
foreach ($callbacks as $prio => $items) {
foreach ($items as $id => $cb) {
$fn = $cb['function'] ?? null; $file = null;
try {
if ($fn instanceof Closure) {
$ref = new ReflectionFunction($fn); $file = $ref->getFileName();
} elseif (is_array($fn) && isset($fn[0], $fn[1])) {
$ref = is_object($fn[0]) ? new ReflectionMethod($fn[0], $fn[1]) : new ReflectionMethod($fn[0], $fn[1]);
$file = $ref->getFileName();
} elseif (is_string($fn) && function_exists($fn)) {
$ref = new ReflectionFunction($fn); $file = $ref->getFileName();
}
} catch (Throwable $e) { $file = null; }
// Only remove callbacks that live in THIS plugin's main file
if ($file && substr($file, -strlen('reeid-translate.php')) === 'reeid-translate.php') {
remove_filter('the_content', $fn, (int)$prio);
}
}
}
}

// 2) Normalize block-breaking wrappers: <p>...<div/section/...>...</...></p>  -> unwrap
$html = preg_replace(
'#<p[^>]*>\s*((?:<(?:div|section|article|header|footer|nav|main|aside|figure|blockquote|ul|ol|table|h[1-6])[^>]*>).*?(?:</(?:div|section|article|header|footer|nav|main|aside|figure|blockquote|ul|ol|table|h[1-6])>))\s*</p>#is',
'$1',
$html
);

// 3) Remove truly empty paragraphs
$html = preg_replace('#<p[^>]*>\s*</p>#i', '', $html);

return $html;
}, 1);
