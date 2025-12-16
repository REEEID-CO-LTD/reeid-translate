<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/*==============================================================================
 Admin List Column: Source Post
==============================================================================*/

/* Column headers */
add_filter('manage_page_posts_columns', function ($columns) {
    $columns['reeid_source_post'] = __('Source Post', 'reeid-translate');
    return $columns;
});
add_filter('manage_post_posts_columns', function ($columns) {
    $columns['reeid_source_post'] = __('Source Post', 'reeid-translate');
    return $columns;
});

/* Column rendering */
add_action('manage_page_posts_custom_column', function ($column, $post_id) {
    if ($column !== 'reeid_source_post') {
        return;
    }

    $src = (int) get_post_meta($post_id, '_reeid_translation_source', true);

    if ($src && $src !== $post_id) {
        $title = get_the_title($src) ?: __('(no title)', 'reeid-translate');
        $url   = get_edit_post_link($src);
        echo $url
            ? '<a href="' . esc_url($url) . '">' . esc_html($title) . '</a>'
            : esc_html($title);
    } else {
        printf('<em>%s</em>', esc_html__('This is source post', 'reeid-translate'));
    }
}, 10, 2);

add_action('manage_post_posts_custom_column', function ($column, $post_id) {
    if ($column !== 'reeid_source_post') {
        return;
    }

    $src = (int) get_post_meta($post_id, '_reeid_translation_source', true);

    if ($src && $src !== $post_id) {
        $title = get_the_title($src) ?: __('(no title)', 'reeid-translate');
        $url   = get_edit_post_link($src);
        echo $url
            ? '<a href="' . esc_url($url) . '">' . esc_html($title) . '</a>'
            : esc_html($title);
    } else {
        printf('<em>%s</em>', esc_html__('This is source post', 'reeid-translate'));
    }
}, 10, 2);

/*==============================================================================
  SECTION 35 : Sorting + Filter Dropdown
==============================================================================*/

/* Sortable column */
add_filter('manage_edit-post_sortable_columns', function ($cols) {
    $cols['reeid_source_post'] = 'reeid_source_post';
    return $cols;
});
add_filter('manage_edit-page_sortable_columns', function ($cols) {
    $cols['reeid_source_post'] = 'reeid_source_post';
    return $cols;
});

/* Query modification */
add_action('pre_get_posts', function ($query) {

    if (! is_admin() || ! $query->is_main_query()) {
        return;
    }

    /* Sorting */
    if ('reeid_source_post' === $query->get('orderby')) {
        $query->set('meta_key', '_reeid_translation_source');
        $query->set('orderby', 'meta_value_num ID');
    }

    /* Filtering — GET-based filters are normal in WP admin list tables */
    $raw_filter = filter_input(INPUT_GET, 'reeid_source_filter', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    if ($raw_filter) {

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($screen && in_array($screen->id, array('edit-post','edit-page'), true)) {

            if ($raw_filter === 'is_source') {

                $query->set('meta_query', array(
                    'relation' => 'OR',
                    array('key' => '_reeid_translation_source', 'compare' => 'NOT EXISTS'),
                    array('key' => '_reeid_translation_source', 'value' => '', 'compare' => '=')
                ));

            } elseif ($raw_filter === 'has_source') {

                $query->set('meta_key', '_reeid_translation_source');
                $query->set('meta_compare', 'EXISTS');

            } elseif (preg_match('/^\d+$/', $raw_filter)) {

                $query->set('meta_key', '_reeid_translation_source');
                $query->set('meta_value', (int) $raw_filter);
                $query->set('meta_compare', '=');
            }
        }
    }
});

/* Dropdown */
add_action('restrict_manage_posts', function () {

    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (! $screen || ! in_array($screen->id, array('edit-post','edit-page'), true)) {
        return;
    }

    global $wpdb;
    $meta_key = '_reeid_translation_source';

    // Fully WP-repo safe: no intermediate SQL variable
    $rows = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT meta_value 
             FROM {$wpdb->postmeta}
             WHERE meta_key = %s 
             AND meta_value != ''",
            $meta_key
        )
    );

    /* Build select options */
    $options = array(
        ''           => __('All posts', 'reeid-translate'),
        'is_source'  => __('Show only source posts', 'reeid-translate'),
        'has_source' => __('Show only translations', 'reeid-translate'),
    );

    $current = filter_input(INPUT_GET, 'reeid_source_filter', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    echo '<select name="reeid_source_filter" id="reeid_source_filter" style="margin-left:6px">';
    foreach ($options as $val => $label) {
        printf(
            '<option value="%s"%s>%s</option>',
            esc_attr($val),
            selected((string)$val, (string)$current, false),
            esc_html($label)
        );
    }
    echo '</select>';
});
