<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/*==============================================================================
  SECTION 33 — PRE-QUERY MODIFICATIONS & LANGUAGE ROUTING
  - Translated front-page resolver
  - Language-aware slug resolver for posts/pages
  - Routing guard for main query only
  - Double-slash fixer stub
==============================================================================*/





    add_action('pre_get_posts', function (WP_Query $q) {
        static $busy = false;
        if ($busy) return;
        $busy = true;

        // Only touch front-end main queries, never admin, feeds, ajax, preview, etc.
        if (
            ! $q->is_main_query() ||
            is_admin() ||
            is_feed() ||
            (defined('REST_REQUEST') && REST_REQUEST) ||
            (defined('DOING_AJAX') && DOING_AJAX) ||
            is_preview() ||
            filter_input(INPUT_GET, 'preview', FILTER_DEFAULT) !== null
        ) {
            $busy = false;
            return;
        }

        // ✅ Get language info early
        $front = sanitize_text_field(get_query_var('reeid_lang_front', ''));
        $code  = sanitize_text_field(get_query_var('reeid_lang_code', ''));

        // ✅ 🛑 SKIP plugin routing if no language prefix or front override
        if (empty($code) && empty($front)) {
            $busy = false;
            return;
        }

        // -- Normal translated front page logic
        if ($front) {
            $default_id = (int) get_option('page_on_front');
            $map        = (array) get_post_meta($default_id, '_reeid_translation_map', true);
            $map['en']  = $default_id;
            $target_id  = isset($map[$front]) ? (int) $map[$front] : $default_id;
            if ($target_id && get_post_status($target_id)) {
                $q->set('page_id', $target_id);
                $q->set('post_type', 'page');
                $q->set('name', '');
                $q->set('pagename', '');
                $q->is_page       = true;
                $q->is_singular   = true;
                $q->is_front_page = true;
                $q->is_home       = false;
            }
            $busy = false;
            return;
        }

        // -- Normal translated slug logic
        $slug     = get_query_var('name', '');
        $pagename = get_query_var('pagename', '');
        $the_slug = $slug ?: $pagename;

                if ($the_slug && $code) {
            // Step 1: get all posts/pages in this language
            $candidates = get_posts([
                'post_type'      => ['post', 'page'],
                'meta_key'       => '_reeid_translation_lang',
                'meta_value'     => $code,
                'posts_per_page' => 50,
                'no_found_rows'  => true,
            ]);

            if (empty($candidates)) {
                $busy = false;
                return;
            }

            // Step 2: try to match by exact slug in PHP
            $target = null;
            foreach ($candidates as $p) {
                if ((string) $p->post_name === (string) $the_slug) {
                    $target = $p;
                    break;
                }
            }

            // Fallback: if we only have one candidate for this language, use it
            if (! $target && count($candidates) === 1) {
                $target = $candidates[0];
            }

            if (! $target) {
                $busy = false;
                return;
            }

            $id = (int) $target->ID;

            if ($target->post_type === 'page') {
                $q->set('page_id', $id);
                $q->set('post_type', 'page');
                $q->set('pagename', $the_slug);
                $q->set('name', '');
                $q->set('meta_query', []);
                $q->is_page       = true;
                $q->is_singular   = true;
                $q->is_front_page = false;
                $q->is_home       = false;
            } else {
                $q->set('name', $the_slug);
                $q->set('post_type', 'post');
                $q->set('meta_query', [
                    [
                        'key'     => '_reeid_translation_lang',
                        'value'   => $code,
                        'compare' => '=',
                    ],
                ]);
                $q->is_single     = true;
                $q->is_singular   = true;
                $q->is_page       = false;
                $q->is_front_page = false;
                $q->is_home       = false;
            }
        }


        $busy = false;
    });



    // 2. DOUBLE-SLASH FIXER
    add_action('template_redirect', 'reeid_fix_double_slash', 0);

    function reeid_fix_double_slash()
    {
        $uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        $path = wp_parse_url($uri, PHP_URL_PATH);
    }
