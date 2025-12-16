<?php
if (!defined('ABSPATH')) exit;
/** Log ASCII-only eplus-wrapper paragraphs stack to wp-content/debug-rt.log */
add_filter('the_content', function ($html) {
if ($html === '' || stripos($html, '<!-- wp:') === false) return $html;
if (!preg_match('#<p\b[^>]*\bclass="[^"]*\beplus-wrapper\b[^"]*"[^>]*>\s*[ -~]+\s*</p>#u', $html)) return $html;

$log = WP_CONTENT_DIR . '/debug-rt.log';
$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
$lines = ["RT_TRACE_DUP: ASCII eplus-wrapper detected"];
foreach ($trace as $i => $t) {
$file = isset($t['file']) ? $t['file'] : '';
$line = isset($t['line']) ? $t['line'] : '';
$func = isset($t['function']) ? $t['function'] : '';
$cls  = isset($t['class']) ? $t['class'] : '';
$lines[] = sprintf('#%02d %s%s%s%s',
$i,
$cls ? $cls.'::' : '',
$func ?: '',
$file ? ' @ '.$file : '',
$line ? ':'.$line : ''
);
}
file_put_contents($log, implode("\n", $lines)."\n", FILE_APPEND);
return $html;
}, 998);
