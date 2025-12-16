<?php
if (!defined('ABSPATH')) exit;

/**
 * TEMPORARILY DISABLED
 *
 * This filter caused Gutenberg embeds (oEmbed / wp:embed) to break
 * by stripping paragraph content at runtime.
 *
 * Disabled on 2025-12-15 to restore core WP behavior.
 *
 * DO NOT DELETE — kept for future refactor.
 */

// add_filter('the_content', function ($html) {
//     return $html;
// }, 9998);
