<?php
/**
 * RT hotfix: normalize translated fragments that break Gutenberg layout.
 * - Unwrap <div> accidentally injected inside <p> (keep alignment).
 * - Remove empty <p></p>.
 * Scope: only when content has Gutenberg markers (<!-- wp: -->).
 */
if (!defined('ABSPATH')) exit;

add_filter('the_content', function ($html) {
    if (stripos($html, '<!-- wp:') === false) return $html; // skip non-Gutenberg (incl. Elementor)

    // 1) <p ...><div ...>TEXT</div></p> -> <p ... class="has-text-align-center">TEXT</p> (if inner had center)
    $html = preg_replace_callback(
        '#<p([^>]*)>\s*<div([^>]*)>(.*?)</div>\s*</p>#is',
        function ($m) {
            $p_attrs   = $m[1] ?? '';
            $div_attrs = $m[2] ?? '';
            $inner     = $m[3] ?? '';

            $aligned = preg_match('#text-align\s*:\s*center#i', $div_attrs)
                   || preg_match('#class\s*=\s*"[^"]*\bhas-text-align-center\b[^"]*"#i', $div_attrs);

            if ($aligned) {
                if (preg_match('#class\s*=#i', $p_attrs)) {
                    $p_attrs = preg_replace('#class\s*=\s*"([^"]*)"#i', 'class="$1 has-text-align-center"', $p_attrs);
                } else {
                    $p_attrs .= ' class="has-text-align-center"';
                }
            }
            return '<p' . $p_attrs . '>' . $inner . '</p>';
        },
        $html
    );

    // 2) Remove empty paragraphs
    $html = preg_replace('#<p[^>]*>\s*</p>#i', '', $html);

    return $html;
}, 12);
