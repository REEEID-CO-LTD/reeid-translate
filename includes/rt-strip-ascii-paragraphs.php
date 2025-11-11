<?php
if (!defined('ABSPATH')) exit;
/**
 * For non-English lang-prefixed pages (/xx/ or /xx-YY/ not 'en'), remove <p> blocks
 * that contain ONLY ASCII letters/digits/punctuation (i.e., leftover English).
 * Runs late; Elementor untouched (no <!-- wp: -->).
 */
add_filter('the_content', function ($html) {
if ($html === '' || stripos($html, '<!-- wp:') === false) return $html;
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
if (!preg_match('#^/([a-z]{2}(?:-[a-z0-9]{2})?)/#i', $path, $m)) return $html;
if (strtolower($m[1]) === 'en') return $html;

// Drop paragraphs whose inner text is strictly ASCII (no non-ASCII letters)
$pattern = '#<p\b[^>]*>\s*([A-Za-z0-9\s\.,;:!\?\-–—"\'\(\)\/$€£¥%]+)\s*</p>#u';
$prev = null;
for ($i=0; $i<3 && $prev !== $html; $i++) { $prev = $html; $html = preg_replace($pattern, '', $html); }
$html = preg_replace('/\s{2,}/u', ' ', $html);
return $html;
}, 9998);
