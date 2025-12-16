<?php



 /*==============================================================================
 SECTION 46: ADMIN COLUMNS & LANGUAGE FILTERS
==============================================================================*/

    // Add "Language" column to posts and pages
    add_filter('manage_posts_columns', 'reeid_add_language_column', 99);
    add_filter('manage_pages_columns', 'reeid_add_language_column', 99);

    function reeid_add_language_column($columns)
    {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'title') {
                $new['reeid_lang'] = __('Language', 'reeid-translate');
            }
        }
        return $new;
    }

    // Render the language column in admin
    add_action('manage_posts_custom_column', 'reeid_render_language_column', 10, 2);
    add_action('manage_pages_custom_column', 'reeid_render_language_column', 10, 2);

    function reeid_render_language_column($column, $post_id)
    {
        if ('reeid_lang' !== $column) return;
        $lang = get_post_meta($post_id, '_reeid_translation_lang', true) ?: 'en';
        echo esc_html(strtoupper($lang));
    }

    // Add a dropdown language filter above post/page list
    add_action('restrict_manage_posts', 'reeid_language_filter_dropdown', 20);
    function reeid_language_filter_dropdown()
    {
        global $typenow, $pagenow;
        if ($pagenow !== 'edit.php' || !in_array($typenow, ['post', 'page'], true)) return;

        $langs = function_exists('reeid_get_supported_languages')
            ? reeid_get_supported_languages()
            : ['en' => 'English'];
        $current_raw = filter_input(INPUT_GET, 'reeid_lang_filter', FILTER_DEFAULT);
        $current = $current_raw ? sanitize_text_field(wp_unslash($current_raw)) : '';

        echo '<select name="reeid_lang_filter" style="margin-left:8px;">';
        echo '<option value="">' . esc_html__('All Languages', 'reeid-translate') . '</option>';
        foreach ($langs as $code => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($code),
                selected($current, $code, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }

    // Actually filter posts/pages in admin list by language
    add_action('pre_get_posts', 'reeid_language_filter_query', 20);
    function reeid_language_filter_query($query)
    {
        global $pagenow, $typenow;
        if (
            !is_admin() ||
            $pagenow !== 'edit.php' ||
            !in_array($typenow, ['post', 'page'], true)
        ) return;

        $lang_raw = filter_input(INPUT_GET, 'reeid_lang_filter', FILTER_DEFAULT);
        $lang = $lang_raw ? sanitize_text_field(wp_unslash($lang_raw)) : '';
        if (empty($lang)) return;

        $meta_query = ('en' === $lang)
            ? [
                'relation' => 'OR',
                ['key' => '_reeid_translation_lang', 'value' => 'en', 'compare' => '='],
                ['key' => '_reeid_translation_lang', 'compare' => 'NOT EXISTS'],
            ]
            : [
                ['key' => '_reeid_translation_lang', 'value' => $lang, 'compare' => '='],
            ];

        $query->set('meta_query', $meta_query);
    }

    // Add a quick "Translate" link to post/page row actions in admin
    add_filter('post_row_actions', 'reeid_add_translate_row_action', 10, 2);
    add_filter('page_row_actions', 'reeid_add_translate_row_action', 10, 2);

    function reeid_add_translate_row_action($actions, $post)
    {
        if (!current_user_can('edit_post', $post->ID) || $post->post_status !== 'publish') return $actions;
        $url = admin_url('post.php?post=' . intval($post->ID) . '&action=edit#reeid-translation-box');
        $actions['reeid_translate'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($url),
            esc_html__('Translate (REEID)', 'reeid-translate')
        );
        return $actions;
    }


    // HELPER: Build translated URL (native slugs + language prefix)

    if (! function_exists('reeid_get_translated_url')) {
        function reeid_get_translated_url($post_id, $lang)
        {
            $default = sanitize_text_field(get_option('reeid_translation_source_lang', 'en'));
            $raw_slug = get_post_field('post_name', $post_id);
            $slug = rawurldecode($raw_slug);
            if ($lang === $default) {
                return user_trailingslashit(home_url("/{$slug}"));
            }
            return user_trailingslashit(home_url("/{$lang}/{$slug}"));
        }
    }