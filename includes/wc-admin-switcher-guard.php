<?php

if ( ! defined( 'ABSPATH' ) ) exit;
/*==============================================================================
    SECTION 41 : Admin “View” Language Guard (+ quick cookie clear)
  - Makes wp-admin "View" (row action & admin bar) open products in the source
    language by appending ?reeid_force_lang={source}.
  - Adds a tiny helper to clear the language cookie via ?reeid_clear_lang=1.
  - SAFE: front-end visitors unaffected; only admin links change.
==============================================================================*/

    if (! function_exists('reeid_s2612_log')) {
        function reeid_s2612_log($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S26.12 ' . $label, $data);
            }
        }
    }
    if (! function_exists('reeid_s2612_norm')) {
        function reeid_s2612_norm($v)
        {
            $v = strtolower(substr((string)$v, 0, 10));
            return preg_match('/^[a-z]{2}(?:[-_][a-z0-9]{2})?$/', $v) ? $v : 'en';
        }
    }

    /* 0) Helper: get source (default) language */
    if (! function_exists('reeid_s2612_source_lang')) {
        function reeid_s2612_source_lang(): string
        {
            $src = (string) get_option('reeid_translation_source_lang', 'en');
            return reeid_s2612_norm($src) ?: 'en';
        }
    }

    /* 1) Products list table: alter the "View" row action to include ?reeid_force_lang=source */
add_filter('post_row_actions', function (array $actions, \WP_Post $post) {
    if ($post->post_type !== 'product') return $actions;

    $src = reeid_s2612_source_lang();
    $view_url = get_permalink($post);
    if ($view_url) {
        $view_url = add_query_arg('reeid_force_lang', $src, $view_url);
        $actions['view'] = sprintf(
            '<a href="%s" rel="bookmark" aria-label="%s">%s</a>',
            esc_url($view_url),
            esc_attr( sprintf(
                // translators: %1$s is the post title; %2$s is the source language code in uppercase (e.g., EN, FR).
                __('View “%1$s” in %2$s', 'reeid-translate'),
                $post->post_title,
                strtoupper($src)
            ) ),
            esc_html__('View', 'reeid-translate')
        );
        reeid_s2612_log('ROW_ACTION_VIEW_SET', ['post' => $post->ID, 'lang' => $src]);
    }
    return $actions;
}, 10, 2);

/* 2) Edit screen admin bar “View” link: point to source language too */
add_action('admin_bar_menu', function (\WP_Admin_Bar $bar) {
    if (! is_admin()) return;
    if (! isset($_GET['post'])) return;
    $post = get_post((int) $_GET['post']);
    if (! $post || $post->post_type !== 'product') return;

    $src = reeid_s2612_source_lang();
    $url = add_query_arg('reeid_force_lang', $src, get_permalink($post));
    if ($node = $bar->get_node('view')) {
        $node->href = $url;
        $bar->add_node($node);
        reeid_s2612_log('ADMINBAR_VIEW_SET', ['post' => $post->ID, 'lang' => $src]);
    }
}, 100);


    /* 3) Optional helper: allow clearing the language cookie quickly via URL.
      Visit any front-end URL with ?reeid_clear_lang=1 to reset to source. */
    add_action('template_redirect', function () {
        if (is_admin() || wp_doing_ajax()) return;
        if (empty($_GET['reeid_clear_lang'])) return;

        $src = reeid_s2612_source_lang();
        // Delete cookie first

        // Then set back to source (so the next request is deterministic)

        $_COOKIE['site_lang'] = $src;

        // Redirect to same URL without the param
        $scheme = is_ssl() ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';
        $parts  = wp_parse_url($scheme . '://' . $host . $uri);
        $q = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $q);
            unset($q['reeid_clear_lang']);
        }
        $clean = ($parts['path'] ?? '/') . ($q ? '?' . http_build_query($q) : '');

        reeid_s2612_log('COOKIE_CLEARED', ['to' => $src, 'dest' => $clean]);
        if (! headers_sent()) {
            wp_safe_redirect($clean, 302);
            exit;
        }
    }, 9);


 /*==============================================================================
    SECTION 42 : Woo Inline — Delete One Translation (UI + Admin Action)
  - Adds a "Delete translation" control to the Translations tab.
  - Deletes meta key _reeid_wc_tr_{lang} and updates _reeid_wc_inline_langs.
  - Clears Woo transients; logs what happened.
==============================================================================*/

if (! function_exists('reeid_s2613_log')) {
    function reeid_s2613_log($label, $data = null)
    {
        if (function_exists('reeid_debug_log')) {
            reeid_debug_log('S26.13 ' . $label, $data);
        }
    }
}

/** Core helper: delete one language payload from a product */
if (! function_exists('reeid_wc_delete_translation_meta')) {
    function reeid_wc_delete_translation_meta(int $product_id, string $lang): bool
    {
        $lang = strtolower(substr(trim($lang), 0, 10));
        if (! $lang) {
            return false;
        }

        $key = '_reeid_wc_tr_' . $lang;

        // Remove payload meta
        delete_post_meta($product_id, $key);

        // Remove from inline langs index
        $langs = (array) get_post_meta($product_id, '_reeid_wc_inline_langs', true);
        $langs = array_values(array_filter($langs, function ($c) use ($lang) {
            return strtolower(trim((string)$c)) !== $lang;
        }));
        update_post_meta($product_id, '_reeid_wc_inline_langs', $langs);

        // Optional: clean any per-lang SEO meta if you added them later
        delete_post_meta($product_id, '_reeid_wc_seo_' . $lang);
        delete_post_meta($product_id, '_reeid_wc_seo_title_' . $lang);
        delete_post_meta($product_id, '_reeid_wc_seo_desc_' . $lang);
        delete_post_meta($product_id, '_reeid_wc_seo_slug_' . $lang);

        // Clear Woo caches
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product_id);
        }

        do_action('reeid_wc_translation_deleted', $product_id, $lang);
        reeid_s2613_log('DELETE_OK', ['product_id' => $product_id, 'lang' => $lang]);

        return true;
    }
}

/** Admin notice after delete */
add_action('admin_notices', function () {
    if (! is_admin() || empty($_GET['reeid_tr_deleted'])) {
        return;
    }

    // raw value for translators comment clarity; escape for output
    $lang_raw = (string) ($_GET['reeid_tr_deleted'] ?? '');
    $lang     = esc_html( $lang_raw );
    $ok       = ! empty($_GET['reeid_tr_ok']);

    $msg = $ok
        ? sprintf(
            // translators: %1$s is the language code (e.g. "fr", "zh") that was removed from the product.
            __('Translation "%1$s" removed from this product.', 'reeid-translate'),
            $lang
        )
        : sprintf(
            // translators: %1$s is the language code (e.g. "fr", "zh") that could not be removed.
            __('Could not remove translation "%1$s".', 'reeid-translate'),
            $lang
        );

    $cls = $ok ? 'updated' : 'error';

    printf(
        '<div class="notice %s"><p>%s</p></div>',
        esc_attr( $cls ),
        esc_html( $msg )
    );
});

/** UI: add a "Delete translation" button next to the language picklist */
add_action('admin_footer-post.php', 'reeid_s2613_inject_delete_ui', 20);
add_action('admin_footer-post-new.php', 'reeid_s2613_inject_delete_ui', 20);

if (! function_exists('reeid_s2613_inject_delete_ui')) {
    function reeid_s2613_inject_delete_ui()
    {
        // Only run on product edit screen
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen || $screen->id !== 'product') {
            return;
        }

        $product_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        if (! $product_id) {
            return;
        }

        // Prepare safe JS/PHP values
        $nonce      = wp_create_nonce('reeid_del_tr');
        $action_base= admin_url('admin-post.php'); // use admin-post.php as the action receiver
        $label      = esc_html__( 'Delete translation', 'reeid-translate' ); // plain text
        $confirm    = esc_html__( 'Are you sure you want to delete this translation?', 'reeid-translate' ); // plain text

        // Output inline script with JSON-encoded values (keeps this block enclosed in PHP)
        ?>
        <script>
        (function() {
            'use strict';

            var label     = <?php echo wp_json_encode( $label ); ?>;
            var confirmMsg= <?php echo wp_json_encode( $confirm ); ?>;
            var actionUrl = <?php echo wp_json_encode( $action_base ); ?>;
            var productId = <?php echo wp_json_encode( (int) $product_id ); ?>;
            var nonceVal  = <?php echo wp_json_encode( (string) $nonce ); ?>;

            function findSelect() {
                return document.getElementById('reeid-tr-langselect') ||
                    document.querySelector('#reeid_translations_panel select, .reeid-translations select');
            }

            function buildDeleteUrl(lang) {
                var base = actionUrl || '/wp-admin/admin-post.php';
                var sep = (base.indexOf('?') === -1) ? '?' : '&';
                return base + sep +
                    'action=reeid_wc_delete_translation' +
                    '&post=' + encodeURIComponent(productId) +
                    '&lang=' + encodeURIComponent(lang) +
                    '&_wpnonce=' + encodeURIComponent(nonceVal);
            }

            function tryAddButtonOnce(sel) {
                try {
                    if (!sel || !sel.parentNode) return false;
                    if (document.getElementById('reeid-del-tr-btn')) return true;

                    var btn = document.createElement('a');
                    btn.id = 'reeid-del-tr-btn';
                    btn.className = 'button button-link-delete';
                    btn.style.marginLeft = '8px';
                    btn.href = '#';
                    btn.textContent = label;

                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        try {
                            var lang = (sel.value || '').toLowerCase().slice(0, 10);
                            if (!lang) {
                                console.warn('reeid: no language selected');
                                return;
                            }
                            if (!confirm(confirmMsg)) return;

                            var url = buildDeleteUrl(lang);
                            btn.href = url;
                            window.location.assign(url);
                        } catch (innerErr) {
                            console.error('reeid: error in delete handler', innerErr);
                        }
                    });

                    sel.parentNode.appendChild(btn);
                    console.info('reeid: delete translation button added');
                    return true;
                } catch (err) {
                    console.error('reeid: failed to add button', err);
                    return false;
                }
            }

            function addDelBtn() {
                var sel = findSelect();
                if (tryAddButtonOnce(sel)) return;

                var observer = new MutationObserver(function(mutations, obs) {
                    var s = findSelect();
                    if (s && tryAddButtonOnce(s)) {
                        obs.disconnect();
                    }
                });
                observer.observe(document.body, { childList: true, subtree: true });

                setTimeout(function() {
                    var s = findSelect();
                    if (s) tryAddButtonOnce(s);
                }, 1500);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', addDelBtn);
            } else {
                addDelBtn();
            }
        })();
        </script>
        <?php
    }
}

/* -------------------- Optional: Server-side handler skeleton --------------------
   If you already have a handler elsewhere, you can remove this. It handles admin-post.php
   and redirects back to the referring page with query args indicating success/failure.
-------------------------------------------------------------------------------*/
add_action( 'admin_post_reeid_wc_delete_translation', 'reeid_wc_delete_translation_handler' );
if (! function_exists('reeid_wc_delete_translation_handler')) {
    function reeid_wc_delete_translation_handler() {
        $referer = wp_get_referer() ? wp_get_referer() : admin_url();
        $post_id = isset( $_REQUEST['post'] ) ? intval( wp_unslash( $_REQUEST['post'] ) ) : 0;
        $lang    = isset( $_REQUEST['lang'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['lang'] ) ) : '';

        if ( empty( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( wp_unslash( $_REQUEST['_wpnonce'] ), 'reeid_del_tr' ) ) {
            $url = add_query_arg( array( 'reeid_tr_deleted' => rawurlencode( $lang ), 'reeid_tr_ok' => 0 ), $referer );
            wp_safe_redirect( $url );
            exit;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            $url = add_query_arg( array( 'reeid_tr_deleted' => rawurlencode( $lang ), 'reeid_tr_ok' => 0 ), $referer );
            wp_safe_redirect( $url );
            exit;
        }

        $deleted_ok = false;

        // If plugin-specific deletion helper exists, use it.
        if ( function_exists( 'reeid_delete_translation_for_product' ) ) {
            try {
                $deleted_ok = (bool) reeid_delete_translation_for_product( $post_id, $lang );
            } catch ( Exception $e ) {
                $deleted_ok = false;
            }
        } else {
            // Basic fallback: delete a common meta key
            $meta_key = '_reeid_wc_tr_' . $lang;
            if ( delete_post_meta( $post_id, $meta_key ) ) {
                // Also update inline langs index
                $langs = (array) get_post_meta( $post_id, '_reeid_wc_inline_langs', true );
                $langs = array_values(array_filter($langs, function ($c) use ($lang) {
                    return strtolower(trim((string)$c)) !== $lang;
                }));
                update_post_meta( $post_id, '_reeid_wc_inline_langs', $langs );
                if ( function_exists('wc_delete_product_transients') ) {
                    wc_delete_product_transients( $post_id );
                }
                $deleted_ok = true;
            } else {
                $deleted_ok = false;
            }
        }

        $url = add_query_arg( array( 'reeid_tr_deleted' => rawurlencode( $lang ), 'reeid_tr_ok' => $deleted_ok ? 1 : 0 ), $referer );
        wp_safe_redirect( $url );
        exit;
    }
}



 /*==============================================================================
  SECTION 43 : Switcher placement
  A) Always render our language switcher on Cart & Checkout (top of the page)
  B) (Optional) Add our switcher to the primary nav menu in the header
==============================================================================*/

    if (! function_exists('reeid_s283_log')) {
        function reeid_s283_log($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S28.3 ' . $label, $data);
            }
        }
    }

    // /* ---------- A) Cart & Checkout inline switcher --------------------------- */
    // /* Renders above the form/contents so users can switch language there too. */
    // add_action('woocommerce_before_cart', function () {
    //     echo do_shortcode('[reeid_lang_switcher style="inline" class="reeid-switcher-cart"]');
    //     reeid_s283_log('RENDER@cart', true);
    // }, 5);

    // add_action('woocommerce_before_checkout_form', function () {
    //     echo do_shortcode('[reeid_lang_switcher style="inline" class="reeid-switcher-checkout"]');
    //     reeid_s283_log('RENDER@checkout', true);
    // }, 5);

    /* Small CSS to keep it neat */
    add_action('wp_head', function () {
        ?>
        <style>
            .reeid-switcher-cart,
            .reeid-switcher-checkout {
                margin: 6px 0 16px;
                text-align: right;
            }

            .reeid-switcher-cart a,
            .reeid-switcher-checkout a {
                text-decoration: none;
            }

            .reeid-switcher-cart a.active,
            .reeid-switcher-checkout a.active {
                font-weight: 600;
                text-decoration: underline;
            }

            .reeid-switcher-cart .sep,
            .reeid-switcher-checkout .sep {
                opacity: .6;
                margin: 0 .35em;
            }
        </style>
    <?php
    });

    /* ---------- B) OPTIONAL Header bridge ----------------------------------- */
    /* Append our switcher to the primary menu. Change 'primary' if your theme
   uses another location slug. Comment this block out if you don't want it. */
    add_filter('wp_nav_menu_items', function ($items, $args) {
        if (! isset($args->theme_location)) return $items;

        $target_locations = ['primary']; // ← adjust to your theme’s main menu slug(s)
        if (in_array($args->theme_location, $target_locations, true)) {
            $html = do_shortcode('[reeid_lang_switcher style="inline" class="reeid-switcher-nav"]');
            // Wrap as a menu item so it inherits header styling
            $items .= '<li class="menu-item menu-item-type-custom menu-item-reeid-switcher">' . $html . '</li>';
            reeid_s283_log('HEADER_BRIDGE@' . $args->theme_location, true);
        }
        return $items;
    }, 10, 2);

    add_action('wp_head', function () {
    ?>
        <style>
            /* Header switcher tweaks */
            .menu-item-reeid-switcher .reeid-switcher-nav {
                white-space: nowrap;
            }

            .menu-item-reeid-switcher .reeid-switcher-nav a {
                padding: 0 .25em;
            }

            .menu-item-reeid-switcher .reeid-switcher-nav a.active {
                font-weight: 600;
                text-decoration: underline;
            }
        </style>
    <?php
    });

    /*==============================================================================
    SECTION 44 : Switcher UI — add dropdown mode (list | inline | dropdown)
  - Replaces [reeid_lang_switcher] renderer with a version that supports
    style="dropdown", using the same language discovery from S26.10.
  - Nuke debug: S28.4 ...
==============================================================================*/

    if (! function_exists('reeid_s284_log')) {
        function reeid_s284_log($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S28.4 ' . $label, $data);
            }
        }
    }

    /* Use helpers from S26.10 (reeid_s2610_lang, _site_langs, _product_langs, _with_lang) */

    if (! function_exists('reeid_render_lang_switcher_v2')) {
        function reeid_render_lang_switcher_v2($atts = []): string
        {
            $atts = shortcode_atts([
                'class' => 'reeid-lang-switcher',
                'style' => 'list',  // list | inline | dropdown
                'id'    => '',      // optional custom id for the element
            ], $atts, 'reeid_lang_switcher');

            $current = function_exists('reeid_s2610_lang') ? reeid_s2610_lang() : 'en';

            // Discover languages (prefer product-specific; else global)
            $langs = [];
            if (function_exists('is_product') && is_product() && function_exists('reeid_s2610_product_langs')) {
                global $post;
                $pid = $post ? (int) $post->ID : 0;
                if ($pid) {
                    $langs = reeid_s2610_product_langs($pid);
                }
            }
            if (! $langs && function_exists('reeid_s2610_site_langs')) {
                $langs = reeid_s2610_site_langs();
            }
            if (! $langs) return '';

            $here = (is_ssl() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');

            // Dropdown mode
            if (strtolower($atts['style']) === 'dropdown') {
                $id = $atts['id'] ? preg_replace('/[^a-z0-9_\-]/i', '', $atts['id']) : 'reeid-sw-' . wp_generate_uuid4();
                $opts = '';
                foreach ($langs as $code => $label) {
                    $href = function_exists('reeid_s2610_with_lang') ? reeid_s2610_with_lang($here, $code) : $here;
                    $sel  = ($code === $current) ? ' selected' : '';
                    $opts .= '<option value="' . esc_attr($href) . '" data-code="' . esc_attr($code) . '"' . $sel . '>' . esc_html($label) . '</option>';
                }
                $html  = '<div class="' . esc_attr($atts['class']) . ' reeid-switcher--dropdown">';
                $html .= '<select id="' . esc_attr($id) . '" aria-label="Language switcher">' . $opts . '</select>';
                $html .= '</div>';
                $html .= '<script>document.addEventListener("change",function(e){var el=e.target;if(el && el.id==="' . esc_js($id) . '"){window.location.href=el.value;}});</script>';
                return $html;
            }

            // Link modes (list / inline)
            $items = [];
            foreach ($langs as $code => $label) {
                $href = function_exists('reeid_s2610_with_lang') ? reeid_s2610_with_lang($here, $code) : $here;
                $active = ($code === $current) ? ' aria-current="true" class="active"' : '';
                $items[] = sprintf('<a href="%s"%s data-code="%s">%s</a>', esc_url($href), $active, esc_attr($code), esc_html($label));
            }

            $html = '<nav class="' . esc_attr($atts['class']) . '">';
            if (strtolower($atts['style']) === 'inline') {
                $html .= implode(' <span class="sep">•</span> ', $items);
            } else {
                $html .= '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
            }
            $html .= '</nav>';
            return $html;
        }
    }

    /* ============================================================================
   FIX: Disable legacy [reeid_lang_switcher] and delegate to the main switcher
   - Prevents WooCommerce fallback switcher from rendering
   - Ensures only [reeid_language_switcher] runs
   ============================================================================
*/

add_action('init', function () {

    // Fully neutralize the old shortcode if it exists
    if (shortcode_exists('reeid_lang_switcher')) {
        remove_shortcode('reeid_lang_switcher');
    }

    // Re-register it as a no-output shim for old themes/plugins
    add_shortcode('reeid_lang_switcher', function () {
        if (function_exists('reeid_s284_log')) {
            reeid_s284_log('SHORTCODE_SUPPRESSED', true);
        }
        return ''; // return nothing → prevents duplicate Woo switcher
    });

}, 40);

/* ============================================================================
   OPTIONAL MINIMAL CSS (kept for backwards compatibility)
   Safe because it only affects `.reeid-lang-switcher` which now outputs nothing
   ============================================================================
*/
add_action('wp_head', function () {
?>
<style>
    .reeid-switcher--dropdown select {
        max-width: 260px;
    }
    .reeid-lang-switcher .sep {
        opacity: .6;
        margin: 0 .35em;
    }
    .reeid-lang-switcher a.active {
        font-weight: 600;
        text-decoration: underline;
    }
</style>
<?php
});


    /*==============================================================================
  SECTION 45 : Cart/Checkout Switcher Gate (single dropdown, deduped)
  - Ensures only ONE switcher shows on Cart/Checkout, as a compact dropdown.
  - Suppresses any other [reeid_lang_switcher] instances on Cart/Checkout
    (widgets, page content, header) to avoid duplicates.
  - Keeps shortcode normal behavior elsewhere (e.g., product pages).
  - Works with S26.10 (multilingual funnel) and S27.1/JS i18n.
  - Nuke debug prefix: "S28.5".
==============================================================================*/

    if (! function_exists('reeid_s285_log')) {
        function reeid_s285_log($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S28.5 ' . $label, $data);
            }
        }
    }

    /* -------------------------------------------------------
 *  A) Dropdown-capable renderer (v3) with Cart/Checkout GATE
 * ----------------------------------------------------- */

    /* uses helpers from S26.10: reeid_s2610_lang / _site_langs / _product_langs / _with_lang */

    if (! function_exists('reeid_render_lang_switcher_v3')) {
        function reeid_render_lang_switcher_v3($atts = []): string
        {
            // IMPORTANT: Gate — on Cart/Checkout ONLY render when our gate is open.
            $is_cc = (function_exists('is_cart') && is_cart()) || (function_exists('is_checkout') && is_checkout());
            if ($is_cc && empty($GLOBALS['reeid_sw_gate_open'])) {
                // Someone (page content/header/widget) tried to render it; block to avoid duplicates.
                reeid_s285_log('BLOCKED_EXTRA_CC_INSTANCE', true);
                return '';
            }

            $atts = shortcode_atts([
                'class' => 'reeid-lang-switcher',
                'style' => 'list',      // list | inline | dropdown
                'id'    => '',          // optional element id
            ], $atts, 'reeid_lang_switcher');

            $current = function_exists('reeid_s2610_lang') ? reeid_s2610_lang() : 'en';

            // Discover languages (prefer product-specific; else global)
            $langs = [];
            if (function_exists('is_product') && is_product() && function_exists('reeid_s2610_product_langs')) {
                global $post;
                $pid = $post ? (int) $post->ID : 0;
                if ($pid) {
                    $langs = reeid_s2610_product_langs($pid);
                }
            }
            if (! $langs && function_exists('reeid_s2610_site_langs')) {
                $langs = reeid_s2610_site_langs();
            }
            if (! $langs) return '';

            $here = (is_ssl() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');

            // --- Dropdown mode ---
            if (strtolower($atts['style']) === 'dropdown') {
                $id = $atts['id'] ? preg_replace('/[^a-z0-9_\-]/i', '', $atts['id']) : 'reeid-sw-' . wp_generate_uuid4();
                $opts = '';
                foreach ($langs as $code => $label) {
                    $href = function_exists('reeid_s2610_with_lang') ? reeid_s2610_with_lang($here, $code) : $here;
                    $sel  = ($code === $current) ? ' selected' : '';
                    $opts .= '<option value="' . esc_attr($href) . '" data-code="' . esc_attr($code) . '"' . $sel . '>' . esc_html($label) . '</option>';
                }
                $html  = '<div class="' . esc_attr($atts['class']) . ' reeid-switcher--dropdown">';
                $html .= '<select id="' . esc_attr($id) . '" aria-label="Language switcher">' . $opts . '</select>';
                $html .= '</div>';
                $html .= '<script>document.addEventListener("change",function(e){var el=e.target;if(el && el.id==="' . esc_js($id) . '"){window.location.href=el.value;}});</script>';
                return $html;
            }

            // --- Links (list / inline) ---
            $items = [];
            foreach ($langs as $code => $label) {
                $href = function_exists('reeid_s2610_with_lang') ? reeid_s2610_with_lang($here, $code) : $here;
                $active = ($code === $current) ? ' aria-current="true" class="active"' : '';
                $items[] = sprintf('<a href="%s"%s data-code="%s">%s</a>', esc_url($href), $active, esc_attr($code), esc_html($label));
            }

            $html = '<nav class="' . esc_attr($atts['class']) . '">';
            if (strtolower($atts['style']) === 'inline') {
                $html .= implode(' <span class="sep">•</span> ', $items);
            } else {
                $html .= '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
            }
            $html .= '</nav>';
            return $html;
        }
    }

    /* Replace shortcode with the gated renderer */
    add_action('init', function () {
        if (shortcode_exists('reeid_lang_switcher')) remove_shortcode('reeid_lang_switcher');
        add_shortcode('reeid_lang_switcher', 'reeid_render_lang_switcher_v3');
        reeid_s285_log('SHORTCODE_GATED', true);
    }, 50);

    /* -------------------------------------------------------
 *  B) Our ONE Cart/Checkout injection (dropdown)
 * ----------------------------------------------------- */

    // add_action('woocommerce_before_cart', function () {
    //     // Open gate only for this controlled render
    //     $GLOBALS['reeid_sw_gate_open'] = true;
    //     echo do_shortcode('[reeid_lang_switcher style="dropdown" class="reeid-switcher-cart"]');
    //     unset($GLOBALS['reeid_sw_gate_open']);
    //     reeid_s285_log('INJECT@cart', true);
    // }, 5);

    // add_action('woocommerce_before_checkout_form', function () {
    //     $GLOBALS['reeid_sw_gate_open'] = true;
    //     echo do_shortcode('[reeid_lang_switcher style="dropdown" class="reeid-switcher-checkout"]');
    //     unset($GLOBALS['reeid_sw_gate_open']);
    //     reeid_s285_log('INJECT@checkout', true);
    // }, 5);

    /* -------------------------------------------------------
 *  C) Minimal CSS (compact & tidy)
 * ----------------------------------------------------- */
    add_action('wp_head', function () { ?>
        <style>
            .reeid-switcher--dropdown select {
                max-width: 260px;
            }

            .reeid-switcher-cart,
            .reeid-switcher-checkout {
                margin: 6px 0 16px;
                text-align: right;
            }

            .reeid-lang-switcher .sep {
                opacity: .6;
                margin: 0 .35em;
            }

            .reeid-lang-switcher a.active {
                font-weight: 600;
                text-decoration: underline;
            }
        </style>
    <?php });


add_action('init', function () {
    // Disable Woo fallback switcher completely
    remove_shortcode('reeid_lang_switcher');
}, 999);
