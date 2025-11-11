<?php
if (!defined('ABSPATH')) exit;

/**
 * Remove translator leftovers like &gt;English sentence&lt; inside Gutenberg content.
 * - Only acts if <!-- wp: --> marker is present.
 * - Only strips &gt;...&lt; (entity form), and only when the inside is plain ASCII
 *   and contains no HTML tags, to avoid touching legitimate markup or translated text.
 * - Cleans up extra spaces left after removal.
 */
add_filter('the_content', function ($html) {
if ($html === '' || stripos($html, '<!-- wp:') === false) return $html;

$pattern = '/&gt;\s*([A-Za-z0-9\s.,;:!?\-–—"\'()\/$€£¥%]+?)\s*&lt;/u';

// Remove repeated occurrences until stable (in case there are multiple)
$prev = null;
for ($i = 0; $i < 5 && $prev !== $html; $i++) {
$prev = $html;
$html = preg_replace($pattern, '', $html);
}

// Collapse double spaces created by removals
$html = preg_replace('/\s{2,}/u', ' ', $html);

return $html;
}, 9999);
