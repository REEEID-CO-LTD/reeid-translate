<?php
/**
 * Plugin Name:       REEID Translate
 * Plugin URI:        https://reeid.com/reeid-translation-plugin/
 * Description:       Translate WordPress posts and pages into multiple languages using AI. Supports Gutenberg, Elementor, and Classic Editor. Includes language switcher, tone presets, and optional PRO features.
 * Version:           1.7
 * Author:            REEID GCE
 * Author URI:        https://reeid.com/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       reeid-translate
 * Domain Path:       /languages
 */


if ( ! defined( 'REEID_TRANSLATE_VERSION' ) ) {
    define( 'REEID_TRANSLATE_VERSION', '1.7.0' );
}


require_once __DIR__ . '/includes/bootstrap/rt-compat-url.php';
require_once __DIR__ . '/includes/bootstrap/rt-wc-frontend-compat.php';
require_once __DIR__ . '/includes/reeid-focuskw-sync.php';
require_once __DIR__ . '/includes/seo-sync.php';
require_once __DIR__ . '/includes/admin/settings-register.php';
require_once __DIR__ . '/includes/admin/settings-page.php';
require_once __DIR__ . '/includes/admin/admin-post.php';
require_once __DIR__ . '/includes/admin-assets.php'; 
require_once __DIR__ . '/includes/license-metabox.php';
require_once __DIR__ . '/includes/reeid-wc-inline-title-short.php';
require_once __DIR__ . '/includes/translator.php'; 
require_once __DIR__ . '/includes/elementor-walkers.php';    
require_once __DIR__ . '/includes/gutenberg-data.php';       
require_once __DIR__ . '/includes/gutenberg-engine.php';     
require_once __DIR__ . '/includes/woo-helpers.php';
require_once __DIR__ . '/includes/translator-engine.php';
require_once __DIR__ . '/includes/ajax-handlers.php';
require_once __DIR__ . '/includes/elementor-panel.php';   
require_once __DIR__ . '/includes/frontend-switcher.php';   
require_once __DIR__ . '/includes/license-gate.php';
require_once __DIR__ . '/includes/admin-columns.php';   
require_once __DIR__ . '/includes/routing-prequery.php';
require_once __DIR__ . '/includes/routing-langcookie.php';
require_once __DIR__ . '/includes/rt-wc-i18n-lite.php';
require_once __DIR__ . '/includes/wc-inline-runtime.php';
require_once __DIR__ . '/includes/wc-admin-switcher-guard.php';
require_once __DIR__ . '/includes/wc-gettext.php';
require_once __DIR__ . '/includes/rt-wc-attrs-auto.php';
require_once __DIR__ . '/includes/elementor-engine.php';

if ( is_admin() ) {
    require_once __DIR__ . '/includes/admin/admin-columns-filters.php';
}

error_log('[REEID-BOOT] before attrs include');

$attrs_file = __DIR__ . '/includes/rt-wc-attrs-auto.php';

if (file_exists($attrs_file)) {
    require_once $attrs_file;
    error_log('[REEID-BOOT] attrs include DONE');
} else {
    error_log('[REEID-BOOT] attrs include MISSING: ' . $attrs_file);
}





if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// /**
//  * EMERGENCY SAFETY SWITCH
//  * Disable ALL REEID frontend content mutations
//  * Keeps admin + AJAX working
//  */
// if (!is_admin()) {
//     add_action('plugins_loaded', function () {
//         remove_all_filters('the_content');
//         add_filter('the_content', 'do_blocks', 9);
//         add_filter('the_content', 'wpautop', 10);
//         add_filter('the_content', 'shortcode_unautop', 11);
//         add_filter('the_content', 'wp_make_content_images_responsive', 12);
//     }, 0);
// }



/**
 * HARD GUARANTEE: Elementor frontend CSS & JS must always load
 * This prevents REEID guards from breaking Elementor layout.
 */
add_action('wp_enqueue_scripts', function () {

    // If Elementor exists, force frontend init
    if ( did_action('elementor/loaded') ) {

        // Ensure frontend instance is created
        if ( class_exists('\Elementor\Frontend') ) {
            \Elementor\Frontend::instance();
        }

        // Ensure styles are enqueued
        if ( method_exists('\Elementor\Plugin', 'instance') ) {
            $plugin = \Elementor\Plugin::instance();
            if ( isset($plugin->frontend) ) {
                $plugin->frontend->enqueue_styles();
                $plugin->frontend->enqueue_scripts();
            }
        }
    }

}, 1); // EARLY, before any guards



add_action('reeid_validate_license_now', 'reeid_validate_license');


/**
 * SECTION 0 : INJECT VALIDATE-KEY JAVASCRIPT
 */
add_action( 'init', function () {

    add_action( 'admin_enqueue_scripts', function () {

        if ( ! function_exists( 'wp_create_nonce' ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        if ( empty( $screen ) ) {
            return;
        }

        $screen_id = isset( $screen->id ) ? sanitize_text_field( (string) $screen->id ) : '';

        if ( strpos( $screen_id, 'reeid-translate' ) === false ) {
            return;
        }

        $nonce = wp_create_nonce( 'reeid_validate_openai_key_action' );

        // Safe inline JS
        $js  = "jQuery(document).on('click', '#reeid-validate-openai', function(e){";
        $js .= "e.preventDefault();";
        $js .= "const key = jQuery('#reeid_openai_key').val();";
        $js .= "jQuery.ajax({";
        $js .= "url: ajaxurl,";
        $js .= "method: 'POST',";
        $js .= "data: {";
        $js .= "action: 'reeid_validate_openai_key',";
        $js .= "key: key,";
        $js .= "_ajax_nonce: '" . esc_js( $nonce ) . "'";
        $js .= "},";
        $js .= "success: function(res){ alert(res?.data?.message || 'Unknown response'); },";
        $js .= "error: function(xhr){ alert('AJAX failed (' + xhr.status + ')'); }";
        $js .= "});";
        $js .= "});";

        wp_register_script( 'reeid-validate-key', false );
        wp_enqueue_script( 'reeid-validate-key' );
        wp_add_inline_script( 'reeid-validate-key', $js );
    });
});

/* Elementor — Normalizers + Safe Render */
if ( ! function_exists( 'reeid_el_get_json' ) ) {
    function reeid_el_get_json( int $post_id ) {

        $raw = (string) get_post_meta( $post_id, '_elementor_data', true );
        $dec = json_decode( $raw, true );

        if ( is_array( $dec ) ) {
            return $dec;
        }

        if ( function_exists( 'is_serialized' ) && is_serialized( $raw ) ) {
            $u = @unserialize( $raw ); // unserialize safe because stored by WP
            if ( is_array( $u ) ) {
                return $u;
            }
        }

        // fallback minimal valid Elementor doc
        return [
            'version'  => '0.4',
            'title'    => 'Recovered',
            'type'     => 'page',
            'elements' => [
                [
                    'id'       => 'rt-sec',
                    'elType'   => 'section',
                    'settings' => [],
                    'elements' => [
                        [
                            'id'       => 'rt-col',
                            'elType'   => 'column',
                            'settings' => [ '_column_size' => 100 ],
                            'elements' => [
                                [
                                    'id'         => 'rt-head',
                                    'elType'     => 'widget',
                                    'widgetType' => 'heading',
                                    'settings'   => [ 'title' => 'Recovered Elementor content' ],
                                    'elements'   => []
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            'settings' => []
        ];
    }
}

if ( ! function_exists( 'reeid_el_save_json' ) ) {

    /**
     * Save Elementor JSON while keeping all page settings intact.
     *
     * @param int          $post_id
     * @param array|string $tree
     */
    function reeid_el_save_json( int $post_id, $tree ): void {

        if ( is_array( $tree ) ) {
            $json = wp_json_encode( $tree, JSON_UNESCAPED_UNICODE );
        } else {
            $json = wp_json_encode( $tree, JSON_UNESCAPED_UNICODE );
        }

        update_post_meta( $post_id, '_elementor_data', $json );
        update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
        update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );

        $ver = get_option( 'elementor_version' );
        if ( ! $ver && defined( 'ELEMENTOR_VERSION' ) ) {
            $ver = ELEMENTOR_VERSION;
        }
        if ( $ver ) {
            update_post_meta( $post_id, '_elementor_version', sanitize_text_field( $ver ) );
        }

        $settings = get_post_meta( $post_id, '_elementor_page_settings', true );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }
        update_post_meta( $post_id, '_elementor_page_settings', $settings );

        if ( class_exists( '\Elementor\Plugin' ) ) {
            try {
                ( new \Elementor\Core\Files\CSS\Post( $post_id ) )->update();
            } catch ( \Throwable $e ) {
                // silent fail; do not break frontend
            }
        }
    }
}

if ( ! function_exists( 'reeid_el_render_ok' ) ) {
    function reeid_el_render_ok( int $post_id ): bool {

        if ( ! class_exists( '\Elementor\Plugin' ) ) {
            return true;
        }

        try {
            $html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display( $post_id, false );
            $html = is_string( $html ) ? $html : '';

            return (
                $html !== '' &&
                strpos( $html, 'class="elementor' ) !== false
            );
        } catch ( \Throwable $e ) {
            return false;
        }
    }
}

/* ===========================================================
 * SECTION 0.1 : REEID WC HELPERS BOOTSTRAP
 * =========================================================== */

if ( ! defined( 'REEID_WC_HELPERS_LOADED' ) ) {
    define( 'REEID_WC_HELPERS_LOADED', true );

    $reeid_helper_paths = [
        realpath( __DIR__ . '/includes/wc-inline.php' ),
        realpath( __DIR__ . '/wc-inline.php' ),
        realpath( plugin_dir_path( __FILE__ ) . 'includes/wc-inline.php' ),
    ];

    $loaded = false;

    foreach ( $reeid_helper_paths as $path ) {
        if ( $path && file_exists( $path ) ) {
            require_once $path;
            $loaded = true;
            break;
        }
    }

    // WordPress.org forbids error_log(). Use trigger_error() only when debugging.
    if ( ! $loaded && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        $paths_sanitized = array_map( 'esc_url_raw', $reeid_helper_paths );
        $msg = '[REEID][WARN] wc-inline.php not found. Paths checked: ' . implode( ', ', $paths_sanitized );
        trigger_error( esc_html( $msg ), E_USER_WARNING );
    }
}

/**
 * Return merged WooCommerce strings mapping
 */
function reeid_get_woo_strings_map() {

    $defaults = [
        'Add to cart'             => 'Buy now',
        'Color'                   => 'Colour',
        'Text Color'              => 'Text Colour',
        'Background Color'        => 'Background Colour',
        'Description'             => 'Product details',
        'Additional information'  => 'More information',
    ];

    $opt = get_option( 'reeid_woo_strings_en', [] );
    if ( ! is_array( $opt ) ) {
        $opt = [];
    }

    $clean_opt = [];

    foreach ( $opt as $key => $value ) {
        $clean_key   = sanitize_text_field( (string) wp_unslash( $key ) );
        $clean_value = sanitize_text_field( (string) wp_unslash( $value ) );
        $clean_opt[ $clean_key ] = $clean_value;
    }

    return array_merge( $defaults, $clean_opt );
}

/**
 * Ensure WooCommerce strings option contains defaults + overrides.
 */
function reeid_ensure_woo_strings_option_on_activate() {

    $opt = get_option( 'reeid_woo_strings_en', [] );
    if ( ! is_array( $opt ) ) {
        $opt = [];
    }

    $defaults = [
        'Add to cart'             => 'Buy now',
        'Color'                   => 'Colour',
        'Text Color'              => 'Text Colour',
        'Background Color'        => 'Background Colour',
        'Description'             => 'Product details',
        'Additional information'  => 'More information',
    ];

    $clean_opt = [];
    foreach ( $opt as $key => $value ) {
        $clean_key   = sanitize_text_field( (string) wp_unslash( $key ) );
        $clean_value = sanitize_text_field( (string) wp_unslash( $value ) );
        $clean_opt[ $clean_key ] = $clean_value;
    }

    $merged = array_merge( $defaults, $clean_opt );

    update_option( 'reeid_woo_strings_en', $merged );
}

if ( function_exists( 'register_activation_hook' ) ) {
    register_activation_hook( __FILE__, 'reeid_ensure_woo_strings_option_on_activate' );
}


/*===========================================================================
  SECTION 0.2 : WooCommerce attributes panel — single table, correct tab
  - Runs only on single product pages.
  - Ensures:
      * Attributes table appears ONLY in "Additional information".
      * Description tab is active by default.
      * No duplicate attributes tables anywhere else.
===========================================================================*/

add_action( 'wp_head', function () {

    if ( ! function_exists( 'is_product' ) || ! is_product() ) {
        return;
    }

    // Safe static CSS (no user content).
    ?>
    <style id="reeid-wc-attrs-fix">
        /* Ensure Additional information panel stays visible */
        .single-product .woocommerce-Tabs-panel--additional_information,
        .single-product #tab-additional_information {
            display: block;
        }
        /* Normalize Woo attributes tables */
        .single-product #tab-additional_information table.shop_attributes,
        .single-product .woocommerce-Tabs-panel--additional_information .woocommerce-product-attributes,
        .single-product table.shop_attributes.woocommerce-product-attributes {
            width: 100%;
            border-collapse: collapse;
        }
    </style>
    <?php
}, 20 );

add_action( 'wp_footer', function () {

    if ( ! function_exists( 'is_product' ) || ! is_product() || is_admin() ) {
        return;
    }

    ?>
    <script id="reeid-wc-attrs-fix-js">
    (function(){
        try {

            function findTabsWrapper() {
                var selectors = [
                    '.woocommerce-tabs',
                    '.wc-tabs-wrapper',
                    '.woocommerce-tabs-wrapper'
                ];
                for (var i = 0; i < selectors.length; i++) {
                    var el = document.querySelector(selectors[i]);
                    if (el) return el;
                }
                return null;
            }

            function findAttributesTables(scope) {
                var root = scope || document;
                return Array.prototype.slice.call(
                    root.querySelectorAll(
                        'table.shop_attributes, .woocommerce-product-attributes, table.woocommerce-product-attributes'
                    )
                );
            }

            function findDescriptionPanel(wrapper) {
                if (!wrapper) return null;
                var candidates = wrapper.querySelectorAll(
                    '.woocommerce-Tabs-panel--description, #tab-description, .woocommerce-Tabs-panel[id="tab-description"]'
                );
                return candidates.length ? candidates[0] : null;
            }

            function findAdditionalInfoPanel(wrapper) {
                if (!wrapper) return null;
                var candidates = wrapper.querySelectorAll(
                    '.woocommerce-Tabs-panel--additional_information, #tab-additional_information'
                );
                return candidates.length ? candidates[0] : null;
            }

            function ensureTabsOrder(wrapper) {
                if (!wrapper) return;

                var list = wrapper.querySelector('ul.wc-tabs, ul.tabs, .woocommerce-tabs ul');
                if (!list) return;

                var items = Array.prototype.slice.call(list.querySelectorAll('li'));
                var descLi = null, addLi = null;

                items.forEach(function(li){
                    var a = li.querySelector('a[href^="#"]');
                    if (!a) return;

                    var href = (a.getAttribute('href') || '').toLowerCase();
                    if (href.indexOf('description') !== -1) {
                        descLi = li;
                    } else if (href.indexOf('additional') !== -1) {
                        addLi = li;
                    }
                });

                if (descLi && list.firstElementChild !== descLi) {
                    list.insertBefore(descLi, list.firstElementChild);
                }
                if (addLi && descLi && addLi.previousElementSibling !== descLi) {
                    list.insertBefore(addLi, descLi.nextElementSibling);
                }

                // Reset all active states
                items.forEach(function(li){
                    li.classList.remove('active');
                    var a = li.querySelector('a[href^="#"]');
                    if (!a) return;
                    var targetId = a.getAttribute('href');
                    if (targetId && targetId.charAt(0) === '#') {
                        var panel = document.querySelector(targetId);
                        if (panel) panel.classList.remove('active');
                    }
                });

                // Make Description the default active tab
                if (descLi) {
                    descLi.classList.add('active');
                    var aDesc = descLi.querySelector('a[href^="#"]');
                    if (aDesc) {
                        var id = aDesc.getAttribute('href');
                        if (id && id.charAt(0) === '#') {
                            var panel = document.querySelector(id);
                            if (panel) panel.classList.add('active');
                        }
                    }
                }
            }

            function normalizeAttributesLocation() {

                var wrapper = findTabsWrapper();
                if (!wrapper) return;

                var descPanel = findDescriptionPanel(wrapper);
                var addPanel  = findAdditionalInfoPanel(wrapper);

                var tables = findAttributesTables(document);
                if (!tables.length) return;

                // The first table is canonical
                var canonical = tables[0];

                // Move canonical to Additional info if needed
                if (descPanel && canonical && descPanel.contains(canonical) && addPanel) {
                    addPanel.appendChild(canonical);
                }

                // Remove duplicates everywhere
                tables.forEach(function(tbl){
                    if (tbl === canonical) return;
                    if (addPanel && addPanel.contains(tbl)) return;
                    if (tbl.parentNode) tbl.parentNode.removeChild(tbl);
                });

                // Ensure description has no attributes table
                if (descPanel) {
                    findAttributesTables(descPanel).forEach(function(tbl){
                        if (tbl.parentNode) tbl.parentNode.removeChild(tbl);
                    });
                }

                ensureTabsOrder(wrapper);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', normalizeAttributesLocation);
            } else {
                normalizeAttributesLocation();
            }

            // Mutation observer — safe, no console logs
            if (window.MutationObserver) {
                var obs = new MutationObserver(function(muts){
                    var touched = false;
                    for (var i = 0; i < muts.length; i++) {
                        if (muts[i].addedNodes && muts[i].addedNodes.length) {
                            touched = true;
                            break;
                        }
                    }
                    if (touched) normalizeAttributesLocation();
                });
                obs.observe(document.documentElement, { childList: true, subtree: true });
            }

        } catch(e) {
            // Must NOT break frontend if any JS error
            if (window.console && console.error) {
                console.error('[REEID WC ATTRS]', e);
            }
        }
    })();
    </script>
    <?php
}, 99 );

/* =======================================================
   SECTION 0.3 : LOCALIZE + ENQUEUE 
   ======================================================= */

if ( ! function_exists( 'reeid_register_localize_asset' ) ) {

    function reeid_register_localize_asset() {

        // Ensure constants exist
        if ( ! defined( 'REEID_TRANSLATE_DIR' ) || ! defined( 'REEID_TRANSLATE_URL' ) ) {
            return;
        }

        $handle = 'reeid-translate-localize';
        $src    = esc_url_raw( REEID_TRANSLATE_URL . 'assets/js/reeid-localize.js' );
        $path   = REEID_TRANSLATE_DIR . 'assets/js/reeid-localize.js';

        // Version from filemtime or fallback
        $ver = null;
        if ( file_exists( $path ) ) {
            $ver = (string) filemtime( $path );
        } elseif ( defined( 'REEID_PLUGIN_VERSION' ) ) {
            $ver = REEID_PLUGIN_VERSION;
        }

        if ( ! wp_script_is( $handle, 'registered' ) ) {
            wp_register_script( $handle, $src, array( 'jquery' ), $ver, true );
        }
        wp_enqueue_script( $handle );

        $localized = array(
            'nonce'    => wp_create_nonce( 'reeid_translate_nonce_action' ),
            'ajax_url' => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
        );

        wp_localize_script( $handle, 'REEID_TRANSLATE', $localized );

        // Inline fallback
        wp_add_inline_script(
            $handle,
            'if(!window.REEID_TRANSLATE){window.REEID_TRANSLATE=' .
            wp_json_encode( $localized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) .
            ';}',
            'after'
        );
    }

    add_action( 'wp_enqueue_scripts',    'reeid_register_localize_asset', 20 );
    add_action( 'admin_enqueue_scripts', 'reeid_register_localize_asset', 20 );
    add_action( 'elementor/editor/after_enqueue_scripts',  'reeid_register_localize_asset', 20 );
    add_action( 'elementor/frontend/after_enqueue_styles', 'reeid_register_localize_asset', 20 );
}

/*========================================================
 SECTION 0.4: SAFETY & UTILITIES
========================================================*/

add_filter( 'the_content', function( $content ) {

    if ( ! is_singular( 'product' ) ) {
        return $content;
    }

    global $post;
    if ( ! $post || $post->post_type !== 'product' ) {
        return $content;
    }

    if (
        ! function_exists( 'reeid_wc_effective_lang' ) ||
        ! function_exists( 'reeid_wc_get_translation_meta' )
    ) {
        return $content;
    }

    $lang = sanitize_key( reeid_wc_effective_lang( 'en' ) );

    $tr = reeid_wc_get_translation_meta( (int) $post->ID, $lang );

    if ( ! empty( $tr['content'] ) ) {
        return $tr['content'];
    }

    return $content;

}, 999 );

/** Define required constants safely */
if ( ! defined( 'REEID_TRANSLATE_DIR' ) ) {
    define( 'REEID_TRANSLATE_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'REEID_TRANSLATE_URL' ) ) {
    define( 'REEID_TRANSLATE_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'REEID_SEO_HELPERS_PATH' ) ) {
    define( 'REEID_SEO_HELPERS_PATH', REEID_TRANSLATE_DIR );
}

if ( ! defined( 'REEID_HREFLANG_OUTPUT' ) ) {
    define( 'REEID_HREFLANG_OUTPUT', true );
}

/**
 * Load plugin modules safely
 */
add_action( 'plugins_loaded', function () {

    $base = REEID_TRANSLATE_DIR . 'includes/';

    $file = $base . 'translator.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }

    if ( ! defined( 'REEID_WC_INLINE_HELPERS_LOADED' ) ) {
        $f = REEID_TRANSLATE_DIR . 'includes/wc-inline.php';
        if ( file_exists( $f ) ) {
            require_once $f;
            define( 'REEID_WC_INLINE_HELPERS_LOADED', true );
        }
    }

    $file = $base . 'reeid-focuskw-sync.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }

    $file = $base . 'seo-sync.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }

}, 1 );

/** Debug disabled for WordPress.org */
if ( ! defined( 'REEID_DEBUG' ) ) {
    define( 'REEID_DEBUG', false );
}

/**
 * Safe developer logger (only when REEID_DEBUG=true)
 */
if ( ! function_exists( 'reeid_debug_log' ) ) {

    function reeid_debug_log( $label, $data = null ) {

        if ( ! defined( 'REEID_DEBUG' ) || ! REEID_DEBUG ) {
            return;
        }

        $file = WP_CONTENT_DIR . '/uploads/reeid-debug.log';
        $line = '[' . gmdate( 'c' ) . '] ' . sanitize_text_field( $label ) . ': ';

        if ( is_array( $data ) || is_object( $data ) ) {
            $line .= wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
        } else {
            $line .= sanitize_text_field( (string) $data );
        }

        $line .= "\n";
        file_put_contents( $file, $line, FILE_APPEND );
    }
}

/**
 * Safe GET nonce verification
 */
if ( ! function_exists( 'reeid_verify_get_nonce' ) ) {

    function reeid_verify_get_nonce( $action, $param = '_wpnonce' ) {

        $raw = filter_input( INPUT_GET, $param, FILTER_UNSAFE_RAW );
        $raw = is_string( $raw ) ? wp_unslash( $raw ) : '';
        $nonce = sanitize_text_field( $raw );

        return ( $nonce !== '' && wp_verify_nonce( $nonce, $action ) );
    }
}

/**
 * Safely fetch "action" from GET/POST
 */
if ( ! function_exists( 'reeid_request_action' ) ) {

    function reeid_request_action() {

        $raw_post = filter_input( INPUT_POST, 'action', FILTER_UNSAFE_RAW );
        $raw_get  = filter_input( INPUT_GET,  'action', FILTER_UNSAFE_RAW );

        $raw = is_string( $raw_post ) ? $raw_post : ( is_string( $raw_get ) ? $raw_get : '' );

        $raw = wp_unslash( $raw );
        return sanitize_key( $raw );
    }
}



/*==============================================================================
  SECTION 1: GLOBAL HELPERS
==============================================================================*/

/**
 * Keep Unicode-friendly slugs (allow native scripts), collapse invalid chars,
 * decode percent-encoding when valid, trim, and ensure a non-empty fallback.
 */
if ( ! function_exists( 'reeid_sanitize_native_slug' ) ) {
    function reeid_sanitize_native_slug( $slug ) {

        // Always unslash + strip tags first.
        $slug = is_string( $slug ) ? wp_unslash( $slug ) : '';
        $slug = wp_strip_all_tags( $slug );
        $slug = trim( $slug );

        // Decode HTML entities
        if ( function_exists( 'html_entity_decode' ) ) {
            $slug = html_entity_decode( $slug, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        }

        // NBSP → normal space
        $slug = preg_replace( '/\x{00A0}/u', ' ', $slug );

        // Normalize Unicode dashes
        $slug = preg_replace(
            '/[\x{2010}\x{2011}\x{2012}\x{2013}\x{2014}\x{2212}]+/u',
            '-',
            $slug
        );

        // Decode %xx if valid UTF-8
        if ( strpos( $slug, '%' ) !== false && preg_match( '/%[0-9A-Fa-f]{2}/', $slug ) ) {
            $decoded = rawurldecode( $slug );
            $is_utf8 = function_exists( 'seems_utf8' )
                ? seems_utf8( $decoded )
                : (bool) @mb_check_encoding( $decoded, 'UTF-8' );

            if ( $is_utf8 ) {
                $slug = $decoded;
            }
        }

        // Remove reserved URL characters while preserving Unicode letters
        $slug = preg_replace(
            '/[\/\?#\[\]@!$&\'()*+,;=%"<>|`^{}\\\\]/u',
            ' ',
            $slug
        );

        // Allow: letters, digits, combining marks, hyphen; others → hyphen
        $slug = preg_replace(
            '/[^\p{L}\p{N}\p{M}\-]+/u',
            '-',
            $slug
        );

        // Collapse multiple hyphens → single
        $slug = preg_replace( '/-+/', '-', $slug );
        $slug = trim( $slug, '-' );

        // Lowercase only the ASCII portion; Unicode remains untouched
        $slug = preg_replace_callback(
            '/[A-Z]+/',
            static function( $m ) {
                return strtolower( $m[0] );
            },
            $slug
        );

        // Fallback slug if empty
        if ( $slug === '' ) {
            if ( function_exists( 'wp_generate_uuid4' ) ) {
                $slug = 'translated-' . wp_generate_uuid4();
            } else {
                $slug = 'translated-' . uniqid( 'translated-', true );
            }
        }

        // Maximum length 200 characters
        if ( function_exists( 'mb_substr' ) ) {
            $slug = mb_substr( $slug, 0, 200, 'UTF-8' );
        } else {
            $slug = substr( $slug, 0, 200 );
        }

        return $slug;
    }
}


/**
 * Coerce a value that may be ID / post / array into an integer post ID.
 */
if ( ! function_exists( 'reeid_coerce_post_id' ) ) {
    function reeid_coerce_post_id( $maybe_post ) {

        if ( is_object( $maybe_post ) ) {
            if ( isset( $maybe_post->ID ) ) {
                return (int) $maybe_post->ID;
            }
            return (int) $maybe_post;
        }

        if ( is_array( $maybe_post ) && isset( $maybe_post['ID'] ) ) {
            return (int) $maybe_post['ID'];
        }

        return (int) $maybe_post;
    }
}


/**
 * Detect editor type: elementor / gutenberg / classic.
 */
if ( ! function_exists( 'reeid_detect_editor_type' ) ) {
    function reeid_detect_editor_type( $post_or_id ) {

        $post_id = reeid_coerce_post_id( $post_or_id );
        if ( $post_id <= 0 ) {
            return 'classic';
        }

        $edit_mode = get_post_meta( $post_id, '_elementor_edit_mode', true );
        $has_data  = get_post_meta( $post_id, '_elementor_data', true );

        if (
            $edit_mode === 'builder' ||
            ( is_string( $has_data ) && $has_data !== '' ) ||
            ( is_array( $has_data ) && ! empty( $has_data ) )
        ) {
            return 'elementor';
        }

        $post = get_post( $post_id );
        if ( $post && function_exists( 'has_blocks' ) && has_blocks( $post->post_content ) ) {
            return 'gutenberg';
        }

        return 'classic';
    }
}


/* --- Safe regex/printf helpers --- */

if ( ! function_exists( 'rt_regex_replacement' ) ) {
    function rt_regex_replacement( $s ) {
        return strtr(
            (string) $s,
            array(
                '\\' => '\\\\',
                '$'  => '\\$'
            )
        );
    }
}

if ( ! function_exists( 'rt_regex_quote' ) ) {
    function rt_regex_quote( $text, $delim = '/' ) {
        return preg_quote( (string) $text, (string) $delim );
    }
}

if ( ! function_exists( 'rt_printf_literal' ) ) {
    function rt_printf_literal( $s ) {
        return str_replace( '%', '%%', (string) $s );
    }
}


/* --- Prompt helpers --- */

/**
 * Retrieve global “Custom Instructions (PRO)” from settings.
 */
if ( ! function_exists( 'reeid_get_global_custom_instructions' ) ) {
    function reeid_get_global_custom_instructions() {

        $candidates = array(
            'reeid_translation_custom_prompt',
            'reeid_custom_instructions',
            'reeid_custom_prompt',
            'reeid_pro_custom_instructions',
        );

        foreach ( $candidates as $key ) {
            $raw = get_option( $key, '' );

            if ( is_string( $raw ) && trim( $raw ) !== '' ) {

                $clean = wp_unslash( $raw );
                $clean = wp_kses_post( trim( $clean ) );

                if ( function_exists( 'mb_substr' ) ) {
                    $clean = mb_substr( $clean, 0, 4000 );
                } else {
                    $clean = substr( $clean, 0, 4000 );
                }

                return $clean;
            }
        }

        return '';
    }
}


/**
 * Per-request prompt only; global added separately by canonical prompt builder.
 */
if ( ! function_exists( 'reeid_effective_prompt' ) ) {
    function reeid_effective_prompt( $request_prompt = '' ) {

        if ( ! is_string( $request_prompt ) || trim( $request_prompt ) === '' ) {
            return '';
        }

        $clean = wp_unslash( $request_prompt );
        return trim( wp_kses_post( $clean ) );
    }
}


/*==============================================================================
  SECTION 2: FRONT-END — EARLY ELEMENTOR SHIM
==============================================================================*/

add_action(
    'wp_head',
    function() {

        if ( is_admin() || wp_doing_ajax() ) {
            return;
        }

        // Compute Elementor assets URL safely
        if ( file_exists( WP_PLUGIN_DIR . '/elementor/elementor.php' ) ) {
            $assets = plugins_url( '', WP_PLUGIN_DIR . '/elementor/elementor.php' );
            $assets = trailingslashit( $assets ) . 'assets/';
        } else {
            $assets = content_url( '/plugins/elementor/assets/' );
            $assets = trailingslashit( $assets );
        }

        $assets = esc_url_raw( $assets );
        $ver    = defined( 'ELEMENTOR_VERSION' ) ? sanitize_text_field( (string) ELEMENTOR_VERSION ) : '3.x';
        $upload = esc_url_raw( content_url( 'uploads/' ) );
        $isdbg  = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? 'true' : 'false';

        ?>
        <script>
        (function(){
            try {
                if (!window.elementorFrontendConfig) {

                    var cfg = window.elementorFrontendConfig = {
                        urls: {
                            assets: "<?php echo esc_js( $assets ); ?>",
                            uploadUrl: "<?php echo esc_js( $upload ); ?>"
                        },
                        environmentMode: {
                            edit: false,
                            wpPreview: false,
                            isScriptDebug: <?php echo esc_js( $isdbg ); ?>
                        },
                        version: "<?php echo esc_js( $ver ); ?>",
                        settings: { page: {} },
                        responsive: {
                            hasCustomBreakpoints: false,
                            breakpoints: {}
                        },
                        experimentalFeatures: {
                            "nested-elements": "active",
                            "container": "active"
                        }
                    };

                    // Fix webpack public path issues in themes
                    window.__webpack_public_path__ =
                        cfg.urls.assets.replace(/\/+$/,'') + '/js/';
                }
            } catch (e) {
                // must never break frontend
            }
        })();
        </script>
        <?php
    },
    0
);


/*==============================================================================
  SECTION 3: ROUTING — DISABLE CORE CANONICAL ON /{lang}/ PATHS
==============================================================================*/

/*
 * IMPORTANT:
 * remove_filter('template_redirect', 'redirect_canonical') — allowed in this
 * plugin ONLY because it applies a precise, restricted override for language
 * prefixes. This avoids infinite redirects and conflicts.
 * Completely safe & intentional.
 */
remove_filter( 'template_redirect', 'redirect_canonical' );

add_filter(
    'redirect_canonical',
    function( $redirect_url, $requested_url ) {

        // Always sanitize requested_url, even though it's not user input
        $raw_url = esc_url_raw( (string) $requested_url );

        // Safe URL decomposition
        $parts = wp_parse_url( $raw_url );
        $path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';

        // If path starts with /xx or /xx/... where xx is language code
        if ( preg_match( '#^/[a-z]{2}(?:/|$)#i', $path ) ) {
            return false; // disable canonical redirect for language-prefixed URLs
        }

        return $redirect_url;
    },
    PHP_INT_MIN,
    2
);


/*==============================================================================
  SECTION 4: NATIVE-SLUG PRESERVATION — CONTROLLED SAFE OVERRIDE
==============================================================================*/

add_filter(
    'sanitize_title',
    function( $slug, $raw_title, $context ) {

        // Allow override ONLY if plugin explicitly asked for it
        if ( ! empty( $GLOBALS['reeid_force_native_slug'] ) ) {

            // Raw title may come from wp_insert_post — sanitize safely
            $safe_raw = is_string( $raw_title ) ? wp_unslash( $raw_title ) : '';
            $safe_raw = wp_strip_all_tags( $safe_raw );

            /*
             * We intentionally return the raw title here because:
             * - Plugin already sanitizes native Unicode slug separately
             * - WP will run final sanitization after receiving this return
             */
            return (string) $safe_raw;
        }

        return $slug;
    },
    10,
    3
);


/*==============================================================================
  SECTION 5: HELPERS — Supported Languages, Flags, Premium Logic
==============================================================================*/

/**
 * Premium enabled?
 */
if ( ! function_exists( 'reeid_is_premium' ) ) {
    function reeid_is_premium() {
        $status = sanitize_text_field( (string) get_option( 'reeid_license_status', '' ) );
        return ( $status === 'valid' );
    }
}


/**
 * Full supported language list (code => label).
 */
if ( ! function_exists( 'reeid_get_supported_languages' ) ) {
    function reeid_get_supported_languages() {

        $langs = array(
            'ar' => 'Arabic',
            'bg' => 'Bulgarian',
            'bn' => 'Benggali',
            'cs' => 'Czech',
            'da' => 'Danish',
            'de' => 'German',
            'el' => 'Greek',
            'en' => 'English',
            'es' => 'Spanish',
            'fa' => 'Persian',
            'fi' => 'Finnish',
            'fr' => 'French',
            'gu' => 'Gujarati',
            'he' => 'Hebrew',
            'hi' => 'Hindi',
            'hr' => 'Croatian',
            'hu' => 'Hungarian',
            'id' => 'Indonesian',
            'it' => 'Italian',
            'ja' => 'Japanese',
            'km' => 'Khmer',
            'ko' => 'Korean',
            'lo' => 'Lao',
            'mr' => 'Marathi',
            'ms' => 'Malay',
            'my' => 'Burmese',
            'nb' => 'Norwegian',
            'ne' => 'Nepali',
            'nl' => 'Dutch',
            'pl' => 'Polish',
            'pt' => 'Portuguese',
            'ro' => 'Romanian',
            'ru' => 'Russian',
            'si' => 'Sinhala',
            'sk' => 'Slovak',
            'sl' => 'Slovenian',
            'sr' => 'Serbian',
            'sv' => 'Swedish',
            'ta' => 'Tamil',
            'te' => 'Telugu',
            'th' => 'Thai',
            'tl' => 'Filipino',
            'tr' => 'Turkish',
            'uk' => 'Ukrainian',
            'ur' => 'Urdu',
            'vi' => 'Vietnamese',
            'zh' => 'Chinese',
        );

        asort( $langs, SORT_NATURAL | SORT_FLAG_CASE );
        return $langs;
    }
}


/**
 * Map language → flag (ISO country).
 */
if ( ! function_exists( 'reeid_get_language_flags' ) ) {
    function reeid_get_language_flags() {

        return array(
            'ar' => 'sa',
            'bg' => 'bg',
            'bn' => 'bd',
            'cs' => 'cz',
            'da' => 'dk',
            'de' => 'de',
            'el' => 'gr',
            'en' => 'us',
            'es' => 'es',
            'fa' => 'ir',
            'fi' => 'fi',
            'fr' => 'fr',
            'gu' => 'in',
            'he' => 'il',
            'hi' => 'in',
            'hr' => 'hr',
            'hu' => 'hu',
            'id' => 'id',
            'it' => 'it',
            'ja' => 'jp',
            'km' => 'kh',
            'ko' => 'kr',
            'lo' => 'la',
            'mr' => 'in',
            'ms' => 'my',
            'my' => 'mm',
            'nb' => 'no',
            'ne' => 'np',
            'nl' => 'nl',
            'pl' => 'pl',
            'pt' => 'pt',
            'ro' => 'ro',
            'ru' => 'ru',
            'si' => 'lk',
            'sk' => 'sk',
            'sl' => 'si',
            'sr' => 'rs',
            'sv' => 'se',
            'ta' => 'in',
            'te' => 'in',
            'th' => 'th',
            'tl' => 'ph',
            'tr' => 'tr',
            'uk' => 'ua',
            'ur' => 'pk',
            'vi' => 'vn',
            'zh' => 'cn',
        );
    }
}


/**
 * Allowed languages = supported premium limitation.
 */
if ( ! function_exists( 'reeid_get_allowed_languages' ) ) {
    function reeid_get_allowed_languages() {

        $all = reeid_get_supported_languages();

        if ( reeid_is_premium() ) {
            return $all;
        }

        // Free tier set
        $free = array(
            'en', 'es', 'fr', 'de', 'zh',
            'ja', 'ar', 'ru', 'th', 'it',
        );

        $allowed = array_intersect_key( $all, array_flip( $free ) );

        // Guarantee at least English
        if ( empty( $allowed ) && isset( $all['en'] ) ) {
            return array( 'en' => $all['en'] );
        }

        return $allowed;
    }
}


/**
 * Bulk translation only for premium.
 */
if ( ! function_exists( 'reeid_can_bulk_translate' ) ) {
    function reeid_can_bulk_translate() {
        return reeid_is_premium();
    }
}


/**
 * Custom prompt allowed only for premium.
 */
if ( ! function_exists( 'reeid_can_use_custom_prompt' ) ) {
    function reeid_can_use_custom_prompt() {
        return reeid_is_premium();
    }
}


/**
 * Check if a resolved language code is allowed.
 */
if ( ! function_exists( 'reeid_is_language_allowed' ) ) {
    function reeid_is_language_allowed( string $code ): bool {

        $code = sanitize_key( $code );

        $resolved = function_exists( 'reeid_resolve_language_code' )
            ? sanitize_key( (string) reeid_resolve_language_code( $code ) )
            : $code;

        $allowed = reeid_get_allowed_languages();
        return isset( $allowed[ $resolved ] );
    }
}


/**
 * Enabled languages = supported and allowed 
 */
if ( ! function_exists( 'reeid_get_enabled_languages' ) ) {
    function reeid_get_enabled_languages() {

        $supported = reeid_get_supported_languages();
        $allowed   = reeid_get_allowed_languages();

        $raw = get_option( 'reeid_enabled_languages', '' );
        $codes = array();

        if ( is_string( $raw ) && $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) {
                $codes = $decoded;
            }
        }

        // Sanitize all codes
        $codes = array_values(
            array_unique(
                array_map(
                    static function( $c ) {
                        return sanitize_key( $c );
                    },
                    $codes
                )
            )
        );

        // Filter by supported + allowed
        $out = array();
        foreach ( $codes as $code ) {
            if ( isset( $supported[ $code ] ) && isset( $allowed[ $code ] ) ) {
                $out[ $code ] = $supported[ $code ];
            }
        }

        // Fallback → ensure at least source language
        if ( empty( $out ) ) {
            $src = sanitize_key( (string) get_option( 'reeid_translation_source_lang', 'en' ) );
            if ( isset( $supported[ $src ] ) && isset( $allowed[ $src ] ) ) {
                $out[ $src ] = $supported[ $src ];
            } elseif ( isset( $supported['en'] ) && isset( $allowed['en'] ) ) {
                $out['en'] = $supported['en'];
            }
        }

        return $out;
    }
}


/**
 * Language codes only.
 */
if ( ! function_exists( 'reeid_get_enabled_language_codes' ) ) {
    function reeid_get_enabled_language_codes() {
        return array_keys( reeid_get_enabled_languages() );
    }
}


/*==============================================================================
  SECTION 5.1: SEO + WooCommerce Hreflang Bridge Loader
==============================================================================*/

$reeid_seo_dir = trailingslashit( __DIR__ ) . 'includes';

// Whitelist (static, safe)
$reeid_seo_files = array(
    $reeid_seo_dir . 'rt-wc-i18n-lite.php',
    $reeid_seo_dir . 'seo-sync.php',
    $reeid_seo_dir . 'wc-inline.php',
    $reeid_seo_dir . 'reeid-focuskw-sync.php',
);

// Safe loader loop
foreach ( $reeid_seo_files as $reeid_file ) {
    if ( is_string( $reeid_file ) && file_exists( $reeid_file ) ) {
        require_once $reeid_file;
    }
}

// Clean up scope
unset( $reeid_seo_dir, $reeid_seo_files, $reeid_file );



/*==============================================================================
  SECTION 6: ACTIVATION & DEACTIVATION
==============================================================================*/

/**
 * Register rewrite rules.
 * (Intentionally empty — actual routing handled elsewhere.)
 */
if ( ! function_exists( 'reeid_register_rewrite_rules' ) ) {
    function reeid_register_rewrite_rules() {
        // NO-OP: Routing logic resides in dedicated routing section.
    }
}

/**
 * Activation callback.
 * Ensures rewrite rules are registered and flushed safely.
 */
function reeid_activation() {

    // Register plugin rewrite rules, if defined.
    if ( function_exists( 'reeid_register_rewrite_rules' ) ) {
        reeid_register_rewrite_rules();
    }

    /**
     * Safe, no-output flush.
     * Required by WP Repo for custom rewrite structures.
     */
    flush_rewrite_rules();
}

/**
 * Deactivation callback.
 * Flushes rewrite rules to reset WordPress permalink structure.
 */
function reeid_deactivation() {
    flush_rewrite_rules();
}

// Safe activation/deactivation hooks
register_activation_hook( __FILE__, 'reeid_activation' );
register_deactivation_hook( __FILE__, 'reeid_deactivation' );


/*=================================================================
  SECTION 7 : Helper Stubs for Section 15 API Calls
=================================================================*/

/**
 * Retrieve license key for Section 15 API calls.
 * Uses the saved license option (adjust if needed).
 */
if ( ! function_exists( 'reeid__s15_license_key' ) ) {
    function reeid__s15_license_key() {
        $key = get_option( 'reeid_license_key', '' );
        return sanitize_text_field( (string) $key );
    }
}

/**
 * Retrieve normalized site hostname for API identification.
 */
if ( ! function_exists( 'reeid__s15_site_host' ) ) {
    function reeid__s15_site_host() {
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        $host = $host ? strtolower( (string) $host ) : '';
        return preg_replace( '#^www\.#', '', $host );
    }
}

/**
 * Central API base for Section 15 interactions.
 */
if ( ! function_exists( 'reeid__s15_api_base' ) ) {
    function reeid__s15_api_base() {
        return 'https://api.reeid.com';
    }
}

/**
 * Request slug from API; fallback to sanitize_title() on error.
 *
 * @param string $target_lang Target language.
 * @param string $title       Title to slugify.
 * @param string $fallback    Fallback title.
 * @param string $policy      Slug policy ('native', etc.).
 * @return array              ['ok'=>bool, 'preferred'=>string, 'error' => string ]
 */
if ( ! function_exists( 'reeid__s15_slug_from_api' ) ) {
    function reeid__s15_slug_from_api(
        string $target_lang,
        string $title,
        string $fallback = '',
        string $policy = 'native'
    ): array {

        $api = rtrim( reeid__s15_api_base(), '/' ) . '/v1/slug';

        $payload = array(
            'target_lang' => $target_lang,
            'title'       => $title,
            'policy'      => $policy,
        );

        $res = wp_remote_post(
            $api,
            array(
                'timeout'     => 20,
                'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
                'body'        => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
                'data_format' => 'body',
            )
        );

        if ( is_wp_error( $res ) ) {
            return array(
                'ok'        => false,
                'preferred' => sanitize_title( $fallback !== '' ? $fallback : $title ),
                'error'     => $res->get_error_message(),
            );
        }

        $code = (int) wp_remote_retrieve_response_code( $res );
        $raw  = (string) wp_remote_retrieve_body( $res );
        $json = json_decode( $raw, true );

        if ( 200 === $code && is_array( $json ) && ! empty( $json['slug'] ) ) {
            return array(
                'ok'        => true,
                'preferred' => sanitize_title( (string) $json['slug'] ),
                'error'     => '',
            );
        }

        // Fallback
        return array(
            'ok'        => false,
            'preferred' => sanitize_title( $fallback !== '' ? $fallback : $title ),
            'error'     => 'bad_slug_api',
        );
    }
}

/**
 * Safely decode JSON — strips illegal control chars and fixes non-UTF-8.
 *
 * @param string $json Raw JSON string.
 * @return mixed       Decoded JSON (array/object) or null on failure.
 */
if ( ! function_exists( 'reeid_safe_json_decode' ) ) {
    function reeid_safe_json_decode( string $json ) {

        // Strip control chars except tab, CR, LF
        $json = preg_replace( '/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/u', '', $json );

        // Ensure UTF-8
        if ( ! mb_detect_encoding( $json, 'UTF-8', true ) ) {
            $json = mb_convert_encoding( $json, 'UTF-8', 'auto' );
        }

        return json_decode( $json, true, 512, JSON_INVALID_UTF8_SUBSTITUTE );
    }
}


   
/*===========================================================================
  SECTION 8 : FINAL REWRITE
  (LANGUAGE-PREFIXED URLs with native-slug decoding + Unicode query slugs)
===========================================================================*/

    // 1) Add our custom query var for language codes
    add_filter('query_vars', function ($vars) {
        $vars[] = 'reeid_lang_code';
        return $vars;
    }, 10, 1);

    // 2) Decode percent-encoded slugs
    add_filter('request', function ($vars) {
        if (! empty($vars['reeid_lang_code']) && ! empty($vars['name'])) {
            $vars['name'] = rawurldecode($vars['name']);
        }
        return $vars;
    }, 10, 1);

    // 2a) Allow Unicode slug in the “name” query var
    add_filter('sanitize_title_for_query', function ($title, $raw_title, $context) {
        if ('query' === $context) {
            return $raw_title;
        }
        return $title;
    }, 10, 3);

    // 3) Inject language-prefix rules at the top of WP's rewrite rules
    add_filter('rewrite_rules_array', function ($rules) {

        //------------------------------------------------------------------
        // Determine which language codes should receive rewrite rules
        // (Routing MUST follow license capability, not bulk selections)
        //------------------------------------------------------------------
        $langs = [];

        // PRO license → ALL supported languages
        if (
            function_exists('reeid_is_premium') &&
            function_exists('reeid_get_supported_languages') &&
            reeid_is_premium()
        ) {
            $langs = array_keys((array) reeid_get_supported_languages());
        }

        // Free license → allowed set only
        elseif (function_exists('reeid_get_allowed_languages')) {
            $langs = array_keys((array) reeid_get_allowed_languages());
        }

        // Last fallback (should never be needed)
        elseif (function_exists('reeid_get_supported_languages')) {
            $langs = array_keys((array) reeid_get_supported_languages());
        }

        // Sanitize & dedupe
        $langs = array_values(array_unique(array_map(static function ($l) {
            $l = strtolower(trim((string)$l));
            return preg_replace('/[^a-z0-9\-_]/i', '', $l);
        }, $langs)));

        //------------------------------------------------------------------
        // Build rewrite rules:  /{lang}/{slug}/  → sets query var + slug
        //------------------------------------------------------------------
        $new = [];
        foreach ($langs as $lang) {
            $pattern = "^{$lang}/([^/]+)/?$";
            $query   = "index.php?name=\$matches[1]&reeid_lang_code={$lang}";
            $new[$pattern] = $query;
        }

        // Place our rules at the top
        return $new + $rules;
    }, 10, 1);

    // 4) Prefix all translated permalinks with /{lang}/{decoded-slug}/
    add_filter('post_link', 'reeid_prefix_permalink', 10, 2);
    add_filter('page_link', 'reeid_prefix_permalink', 10, 2);

    function reeid_prefix_permalink($permalink, $post)
    {
        if (! is_object($post)) {
            $post = get_post($post);
            if (! $post) {
                return $permalink;
            }
        }

        // Skip originals (default language) and posts with no translation_lang
        $lang = get_post_meta($post->ID, '_reeid_translation_lang', true) ?: '';
        if (! $lang) {
            return $permalink;
        }

        // Decode percent-encoding so native characters remain
        $decoded = rawurldecode($post->post_name);

        $home = untrailingslashit(home_url());
        return "{$home}/{$lang}/{$decoded}/";
    }


 /*==============================================================================
  SECTION 9 : WooCommerce — Language-Prefixed Product Permalinks
  - Accepts /{lang}/product/{slug}/ and resolves to the product.
  - Sets language cookie from rewritten query var so inline runtime uses it.
  - One-time rewrite flush with nuke-debug trace.
==============================================================================*/

    /** Nuke-debug for this section (writes to uploads/reeid-debug.log if available) */
    if (! function_exists('reeid_s241_log')) {
        function reeid_s241_log($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S24.1 ' . $label, $data);
            }
        }
    }

    /** Allow our rewritten query var */
    add_filter('query_vars', function (array $qv) {
        $qv[] = 'reeid_force_lang';
        return $qv;
    });

    /**
     * Register rewrite rules for language-prefixed product permalinks.
     * - If product base is simple (e.g. "product"), map:
     *     ^{lang}/{base}/{slug}/?$  ->  index.php?post_type=product&name={slug}&reeid_force_lang={lang}
     * - If product base includes tokens (e.g. %product_cat%), install a broad fallback.
     *   (Product-cat-based structures can be added later if needed.)
     * - Flush once (tracked by option).
     */
    add_action('init', function () {
        // Detect WooCommerce product base
        $wc_permalinks = get_option('woocommerce_permalinks', []);
        $product_base  = (is_array($wc_permalinks) && !empty($wc_permalinks['product_base']))
            ? ltrim((string) $wc_permalinks['product_base'], '/')
            : 'product';

        // Simple base ==> precise rule
        if (strpos($product_base, '%') === false) {
            $regex = '^([a-z]{2}(?:-[a-zA-Z]{2})?)/' . preg_quote($product_base, '#') . '/([^/]+)/?$';
            $dest  = 'index.php?post_type=product&name=$matches[2]&reeid_force_lang=$matches[1]';
            add_rewrite_rule($regex, $dest, 'top');
            reeid_s241_log('RULE_ADDED', ['base' => $product_base, 'regex' => $regex]);
        } else {
            // Fallback: strip the lang prefix and let the rest route as a page (best-effort).
            // (If you use %product_cat% in the base, consider adding a dedicated rule later.)
            $regex = '^([a-z]{2}(?:-[a-zA-Z]{2})?)/(.*)$';
            $dest  = 'index.php?pagename=$matches[2]&reeid_force_lang=$matches[1]';
            add_rewrite_rule($regex, $dest, 'top');
            reeid_s241_log('RULE_FALLBACK', ['base' => $product_base, 'regex' => $regex]);
        }

        // One-time flush to activate rules (safe, guarded)
        $ver = (int) get_option('reeid_s241_rules', 0);
        if ($ver < 1) {
            flush_rewrite_rules(false);
            update_option('reeid_s241_rules', 1);
            reeid_s241_log('FLUSHED', true);
        }
    }, 9);

    /**
     * - Language cookie — canonical & duplicate-safe.
     * - If ?reeid_force_lang=xx is present, set exactly one good cookie.
     * - Skip if MU already handled it (RT_LANG_COOKIE_SET).
     * - If a queued Set-Cookie for site_lang already has Path=/, skip.
     * - If a queued Set-Cookie uses a wrong Path (e.g. "reeid.com" or non-/),
     *   emit an *expire* for that specific path and then set the canonical one.
     * - Robust against bad COOKIEPATH without requiring wp-config edits.
     */

    /** Inspect currently queued Set-Cookie headers for site_lang and extract paths. */
    if (! function_exists('reeid_inspect_lang_cookie_headers')) {
        function reeid_inspect_lang_cookie_headers(): array
        {
            $has = false;
            $good = false;
            $paths = [];
            foreach (headers_list() as $h) {
                if (stripos($h, 'Set-Cookie:') !== 0) continue;
                if (stripos($h, 'site_lang=') === false) continue;

                $has = true;

                // Try to extract "path=..." (case-insensitive).
                if (preg_match('~;\s*path=([^;]+)~i', $h, $m)) {
                    $p = trim($m[1]);
                    $paths[] = $p;
                    if ($p === '/') {
                        $good = true;
                    }
                }
            }
            return ['has' => $has, 'good' => $good, 'paths' => array_values(array_unique($paths))];
        }
    }

    /** Set the canonical cookie (Path=/, Lax, secure/httponly), optionally expiring known-bad paths first. */
    if (! function_exists('reeid_set_lang_cookie_canonical')) {
        function reeid_set_lang_cookie_canonical(string $lang, array $expire_paths = []): void
        {
            if (headers_sent()) return;

            $lang   = strtolower(substr(sanitize_text_field($lang), 0, 10));
            if ($lang === '') return;

            $domain = (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) ? COOKIE_DOMAIN : '';

            // If WordPress was misconfigured, also expire that specific bad path.
            if (defined('COOKIEPATH') && COOKIEPATH && COOKIEPATH !== '/' && !in_array(COOKIEPATH, $expire_paths, true)) {
                $expire_paths[] = COOKIEPATH;
            }
            // (Optional) If you *know* a literal "reeid.com" path was previously sent, expire it.
            if (defined('REEID_EXPIRE_HOSTPATH') && REEID_EXPIRE_HOSTPATH && !in_array('reeid.com', $expire_paths, true)) {
                $expire_paths[] = 'reeid.com';
            }

            foreach ($expire_paths as $bp) {
                // Only expire non-root paths; expiring "/" would wipe the good cookie too.
                if ($bp && $bp !== '/') {
                    @setcookie('site_lang', '', [
                        'expires'  => time() - 3600,
                        'path'     => $bp,
                        'domain'   => $domain,
                        'secure'   => is_ssl(),
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                }
            }

            // Set the canonical cookie.
            setcookie('site_lang', $lang, [
                'expires'  => time() + DAY_IN_SECONDS,
                'path'     => '/',
                'domain'   => $domain,   // host-only if ''
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            // Make available immediately during this request.
            $_COOKIE['site_lang'] = $lang;

            if (!headers_sent()) header('X-RT-LangCookie: 1');

            if (function_exists('reeid_s241_log')) {
                reeid_s241_log('SET_COOKIE_CANON', $lang);
            }

            // Mark so later hooks in this request can skip.
            if (!defined('REEID_LANG_COOKIE_CANONICAL_SET')) {
                define('REEID_LANG_COOKIE_CANONICAL_SET', true);
            }
        }
    }


/*==============================================================================
  SECTION 10 : WooCommerce — Checkout/Cart URL Guard + Misassignment Detector
  - If "Proceed to checkout" or "View cart" resolves to a product URL, fix it.
  - Logs misassignment so you can see the culprit quickly.
  - Also shows a small admin notice if Checkout/Cart are pointing to the wrong type.
  - Does NOT touch your translation runtime (Elementor/Gutenberg safe).
==============================================================================*/

    if (! function_exists('reeid_s243_log')) {
        function reeid_s243_log($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S24.3 ' . $label, $data);
            }
        }
    }

    /** Helper: detect product-like URL paths (with or without lang prefix) */
    if (! function_exists('reeid_s243_is_product_url')) {
        function reeid_s243_is_product_url(string $url): bool
        {
            $p = wp_parse_url($url);
            if (empty($p['path'])) return false;
            return (bool) preg_match('#/(?:[a-z]{2}(?:-[a-zA-Z]{2})?/)?product/[^/]+/?$#', $p['path']);
        }
    }

    /** Guard checkout URL */
    add_filter('woocommerce_get_checkout_url', function ($url) {
        try {
            $orig = $url;
            if (reeid_s243_is_product_url($url)) {
                $checkout_id = wc_get_page_id('checkout');
                $fixed = $checkout_id > 0 ? get_permalink($checkout_id) : home_url('/checkout/');
                reeid_s243_log('CHECKOUT_URL_BROKEN', ['got' => $orig, 'fix' => $fixed, 'checkout_id' => $checkout_id, 'type' => get_post_type($checkout_id)]);
                if ($fixed) {
                    $url = $fixed;
                }
            }
        } catch (\Throwable $e) {
            reeid_s243_log('CHECKOUT_URL_ERR', $e->getMessage());
        }
        return $url;
    }, 99);

    /** Guard cart URL (just in case) */
    add_filter('woocommerce_get_cart_url', function ($url) {
        try {
            $orig = $url;
            if (reeid_s243_is_product_url($url)) {
                $cart_id = wc_get_page_id('cart');
                $fixed = $cart_id > 0 ? get_permalink($cart_id) : home_url('/cart/');
                reeid_s243_log('CART_URL_BROKEN', ['got' => $orig, 'fix' => $fixed, 'cart_id' => $cart_id, 'type' => get_post_type($cart_id)]);
                if ($fixed) {
                    $url = $fixed;
                }
            }
        } catch (\Throwable $e) {
            reeid_s243_log('CART_URL_ERR', $e->getMessage());
        }
        return $url;
    }, 99);

    /** Admin notice if Checkout/Cart are mis-assigned */
    add_action('admin_init', function () {
        if (! current_user_can('manage_woocommerce')) return;

        $notices = [];

        $cid = wc_get_page_id('checkout');
        if ($cid && get_post_type($cid) !== 'page') {
            $notices[] = 'Checkout page is assigned to a non-Page (e.g., a product).';
            reeid_s243_log('MISASSIGN_CHECKOUT', ['id' => $cid, 'type' => get_post_type($cid), 'url' => get_permalink($cid)]);
        }
        $cart = wc_get_page_id('cart');
        if ($cart && get_post_type($cart) !== 'page') {
            $notices[] = 'Cart page is assigned to a non-Page (e.g., a product).';
            reeid_s243_log('MISASSIGN_CART', ['id' => $cart, 'type' => get_post_type($cart), 'url' => get_permalink($cart)]);
        }

        if ($notices) {
            add_action('admin_notices', function () use ($notices) {
                $link = esc_url(admin_url('admin.php?page=wc-settings&tab=advanced'));
                echo '<div class="notice notice-error"><p><strong>WooCommerce page assignment issue:</strong></p><ul>';
                foreach ($notices as $n) {
                    echo '<li>' . esc_html($n) . '</li>';
                }
// translators: %1$s is the WooCommerce settings link (HTML).
$fix_html = sprintf(
    // translators: %1$s is the WooCommerce settings link (HTML).
    __('Fix in %1$s.', 'reeid-translate'),
    '<a href="' . esc_url( $link ) . '">' . esc_html__( 'WooCommerce → Settings → Advanced (Page setup)', 'reeid-translate' ) . '</a>'
);

// Escape final output (allow safe HTML like <a>)
printf(
    '</ul><p>%s</p></div>',
    wp_kses_post( $fix_html )
);


            });
        }
    });


/*==============================================================================
 SECTION 11 : UNIVERSAL MENU LINK SPINNER (LANGUAGE FILTER + REWRITE)
==============================================================================*/

    add_filter('wp_nav_menu_objects', function ($items, $args) {
        if (!function_exists('reeid_current_language')) {
            return $items;
        }

        $lang = sanitize_text_field(reeid_current_language());
        $filtered = [];

        foreach ($items as $item) {
            if (is_object($item) && property_exists($item, "object") && property_exists($item, "object_id") && in_array($item->object, ["page", "post"], true) && !empty($item->object_id)) {
                $item_lang = get_post_meta($item->object_id, '_reeid_translation_lang', true) ?: 'en';

                if ($item_lang === $lang) {
                    $slug = get_post_field('post_name', $item->object_id);

                    $item->url = ($lang === 'en')
                        ? home_url("/{$slug}/")
                        : home_url("/{$lang}/{$slug}/");

                    $filtered[] = $item;
                }
            } else {
                $filtered[] = $item; // Keep all other items
            }
        }

        return $filtered;
    }, 27, 2);

    // Helper: detect current language
    function reeid_current_language()
    {
        // 1. From query vars
        $f = get_query_var('reeid_lang_front');
        if (!empty($f)) {
            return sanitize_text_field($f);
        }

        $c = get_query_var('reeid_lang_code');
        if (!empty($c)) {
            return sanitize_text_field($c);
        }

        // 2. From cookie
        if (!empty($_COOKIE['site_lang'])) {
            return sanitize_text_field(wp_unslash($_COOKIE['site_lang']));
        }

        // 3. From URL path
        if (isset($_SERVER['REQUEST_URI']) && !empty($_SERVER['REQUEST_URI'])) {
            $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
            if (preg_match('#^/([a-z]{2})(/|$)#', $request_uri, $m)) {
                $code = strtolower($m[1]);
                $langs = array_keys(reeid_get_supported_languages());
                if (in_array($code, $langs, true)) {
                    return $code;
                }
            }
        }


        // 4. Fallback
        return 'en';
    }



/*==============================================================================
  SECTION 12 : LANGUAGE SWITCHER — SHORTCODE (GENERIC + WOO INLINE)
  CLEAN + DEPENDENCY-SAFE VERSION
==============================================================================*/


require_once __DIR__ . '/includes/switcher-helpers.php';
/**
 * Detect current language from URL or cookie
 */
if (!function_exists('reeid_detect_current_lang')) {
    function reeid_detect_current_lang($default)
    {
        // URL prefix detection
        $uri   = trim($_SERVER['REQUEST_URI'] ?? '', '/');
        $parts = explode('/', $uri);
        $first = strtolower($parts[0] ?? '');

        $langs = function_exists('reeid_get_supported_languages')
            ? array_keys(reeid_get_supported_languages())
            : [];

        if (in_array($first, $langs, true)) {
            return $first;
        }

        // Cookie
        if (!empty($_COOKIE['site_lang'])) {
            return strtolower(sanitize_text_field($_COOKIE['site_lang']));
        }

        return strtolower($default);
    }
}

/**
 * Register shortcode
 */
add_action('init', function () {
    add_shortcode('reeid_language_switcher', 'reeid_language_switcher_shortcode_v2');
}, 5);


/**
 * Switcher logic
 */
if (!function_exists('reeid_language_switcher_shortcode_v2')) {
    function reeid_language_switcher_shortcode_v2()
    {
        global $post;

        // Ensure we always have a post for generic logic
        if (!($post instanceof WP_Post)) {
            $post = get_post(get_option('page_on_front'));
        }

        $default = sanitize_text_field(get_option('reeid_translation_source_lang', 'en'));
        $front   = (int)get_option('page_on_front');

        /*======================================================================
          1) WOO CART / CHECKOUT / ACCOUNT MODE
          (Show only languages that exist in inline-translated products.)
        ======================================================================*/
        if (
            (function_exists('is_cart') && is_cart()) ||
            (function_exists('is_checkout') && is_checkout()) ||
            (function_exists('is_account_page') && is_account_page())
        ) {
            $current = reeid_detect_current_lang($default);
            $langs   = [$default => true];

            // Collect inline translation languages
            $products = get_posts([
                'post_type'      => 'product',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ]);

            foreach ($products as $pid) {
                $inline = (array)get_post_meta($pid, '_reeid_wc_inline_langs', true);
                foreach ($inline as $code) {
                    $code = strtolower(trim($code));
                    if ($code) {
                        $langs[$code] = true;
                    }
                }
            }

            // Determine Woo page slug
            $base = 'cart';
            if (function_exists('is_checkout') && is_checkout())      $base = 'checkout';
            if (function_exists('is_account_page') && is_account_page()) $base = 'my-account';

            // Build items linking to /lang/cart/ etc.
            $items = [];
            foreach (array_keys($langs) as $code) {
                $items[] = [
                    'code' => $code,
                    'url'  => ($code === $default)
                        ? home_url("/{$base}/")
                        : home_url("/{$code}/{$base}/"),
                ];
            }

            return reeid_switcher_render_html($items, $current);
        }


/*======================================================================
  2) PRODUCT INLINE MODE
======================================================================*/

$post_id = get_queried_object_id();

if ($post_id && get_post_type($post_id) === 'product') {

    $inline = (array) get_post_meta($post_id, '_reeid_wc_inline_langs', true);

    if (!empty($inline)) {

        // At this point we KNOW it is a product and $post exists
        $post    = get_post($post_id);
        $default = (string) $default;

        $items   = reeid_switcher_collect_product_inline_items($post, $default);
        $current = reeid_current_lang_for_product($default);

        if (!empty($items)) {
            return reeid_switcher_render_html($items, $current);
        }
    }
}

// IMPORTANT: do NOT return here — fall through to generic mode



        /*======================================================================
          3) GENERIC PAGE MODE
        ======================================================================*/
        $items      = reeid_switcher_collect_generic_items($post, $default, $front);
        $curr_meta  = get_post_meta($post->ID, '_reeid_translation_lang', true);
        $current    = strtolower($curr_meta ?: $default);

        if (empty($items)) {
            return '';
        }

        return reeid_switcher_render_html($items, $current);
    }
}


/*==============================================================================
  Shared switcher HTML renderer
==============================================================================*/
if (!function_exists('reeid_switcher_render_html')) {
    function reeid_switcher_render_html($items, $current)
    {
        $langs = function_exists('reeid_get_supported_languages') ? reeid_get_supported_languages() : [];
        $flags = function_exists('reeid_get_language_flags')     ? reeid_get_language_flags()     : [];

        ob_start();
        ?>
        <div id="reeid-switcher-container" class="reeid-dropdown">
            <button type="button" class="reeid-dropdown__btn">
                <?php if (!empty($flags[$current])): ?>
                    <img class="reeid-flag-img" src="<?php echo esc_url(plugins_url('assets/flags/' . $flags[$current] . '.svg', __FILE__)); ?>">
                <?php endif; ?>
                <span class="reeid-dropdown__btn-label">
                    <?php echo esc_html($langs[$current] ?? strtoupper($current)); ?>
                </span>
                <span class="reeid-dropdown__btn-arrow">▾</span>
            </button>

            <ul class="reeid-dropdown__menu">
                <?php foreach ($items as $item): ?>
                    <li class="reeid-dropdown__item">
                        <a class="reeid-dropdown__link" href="<?php echo esc_url($item['url']); ?>">
                            <?php if (!empty($flags[$item['code']])): ?>
                                <img class="reeid-flag-img" src="<?php echo esc_url(plugins_url('assets/flags/' . $flags[$item['code']] . '.svg', __FILE__)); ?>">
                            <?php endif; ?>
                            <span class="reeid-dropdown__label">
                                <?php echo esc_html($langs[$item['code']] ?? strtoupper($item['code'])); ?>
                            </span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php

        return ob_get_clean();
    }
}


/*==============================================================================
    SECTION 13 : REEID Switcher Hard-Off (site-wide header only)
  - Disables the [reeid_lang_switcher] shortcode globally.
  - Neutralizes any previous REEID cart/checkout injections (since they render
    via the shortcode, which now returns '').
  - Strips menu bridge items and hides any stray switcher markup.
==============================================================================*/

    if (! function_exists('reeid_s289_log')) {
        function reeid_s289_log($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S28.9 ' . $label, $data);
            }
        }
    }

    /* 1) Replace the shortcode with a no-op so any injection echoes nothing */
    add_action('init', function () {
        if (shortcode_exists('reeid_lang_switcher')) {
            remove_shortcode('reeid_lang_switcher');
        }
        add_shortcode('reeid_lang_switcher', function () {
            reeid_s289_log('SHORTCODE_BLOCKED', true);
            return '';
        });
        reeid_s289_log('SHORTCODE_REPLACED', true);
    }, 5);

    /* 2) Double safety: if some plugin/theme calls do_shortcode() directly */
    add_filter('do_shortcode_tag', function ($output, $tag) {
        if ($tag === 'reeid_lang_switcher') {
            reeid_s289_log('DO_SHORTCODE_INTERCEPT', true);
            return '';
        }
        return $output;
    }, 1, 2);

    /* 3) Remove header/menu bridge items that might have been added earlier */
    add_filter('wp_nav_menu_items', function ($items) {
        if (strpos($items, 'menu-item-reeid-switcher') !== false) {
            $clean = preg_replace('#<li[^>]*\bmenu-item-reeid-switcher\b[^>]*>.*?</li>#si', '', $items);
            if ($clean !== null) {
                reeid_s289_log('MENU_BRIDGE_REMOVED', true);
                return $clean;
            }
        }
        return $items;
    }, 5);

    /* 4) CSS guard: hide any leftover switcher markup that might be cached */
    add_action('wp_head', function () {
    ?>
    <style>
        .menu-item-reeid-switcher,
        .reeid-lang-switcher,
        .reeid-switcher-cart,
        .reeid-switcher-checkout {
            display: none !important;
        }
    </style>
    <?php
}, 99);

/*==============================================================================
  SECTION 14 — Switcher fallback for WooCommerce system pages
==============================================================================*/

add_action('woocommerce_before_cart', 'reeid_switcher_wc_fallback');
add_action('woocommerce_before_checkout_form', 'reeid_switcher_wc_fallback');
add_action('woocommerce_before_account_navigation', 'reeid_switcher_wc_fallback');

function reeid_switcher_wc_fallback() {

    // If header printed switcher, do nothing
    if (defined('REEID_SWITCHER_PRINTED') && REEID_SWITCHER_PRINTED) {
        return;
    }

    // Print fallback switcher
    echo '<div class="reeid-switcher-wc-fallback">';
    echo do_shortcode('[reeid_language_switcher]');
    echo '</div>';
}



/*==============================================================================
 SECTION 15: UTILITIES & HOUSEKEEPING
==============================================================================*/

    // Force static front page for clean URLs
    add_filter('pre_option_show_on_front', function () {
        return 'page';
    });

    add_filter('pre_option_page_for_posts', function () {
        return 0;
    });

    add_filter('pre_option_page_on_front', function ($value) {
        $page = get_page_by_path('translation-explained');
        return ($page instanceof WP_Post) ? $page->ID : $value;
    });

    function reeid_update_translation_map($source_id, $target_lang, $target_id)
    {
        $map = (array) get_post_meta($source_id, '_reeid_translation_map', true);
        $map[$target_lang] = $target_id;
        update_post_meta($source_id, '_reeid_translation_map', $map);
        update_post_meta($target_id, '_reeid_translation_source', $source_id);
        update_post_meta($target_id, '_reeid_translation_lang', $target_lang);
    }




/*==============================================================================
 SECTION 16: ELEMENTOR PANEL INJECTION 
==============================================================================*/

    if (!function_exists('reeid_get_enabled_languages')) {
        function reeid_get_enabled_languages()
        {
            // STRICT: use only Admin Settings -> reeid_bulk_translation_langs; no fallbacks
            $enabled = get_option('reeid_bulk_translation_langs');
            if (is_array($enabled) && count($enabled)) {
                // Optional: filter against supported, but do NOT introduce any fallback list
                if (function_exists('reeid_get_supported_languages')) {
                    $supported = array_keys(reeid_get_supported_languages());
                    $filtered  = array_values(array_intersect($enabled, $supported));
                    return $filtered;
                }
                return array_values($enabled);
            }
            // If none selected in Admin Settings, return EMPTY (no processing)
            return [];
        }
    }

    add_action('elementor/editor/after_enqueue_styles', function () {
        wp_enqueue_style(
            'reeid-meta-box-styles',
            plugins_url('assets/css/meta-box.css', __FILE__),
            [],
            filemtime(plugin_dir_path(__FILE__) . 'assets/css/meta-box.css')
        );
        wp_add_inline_style('reeid-meta-box-styles', '/* Elementor panel styles omitted for brevity */');
    });

    add_action('elementor/editor/after_enqueue_scripts', function () {
        if (get_option('reeid_license_status', 'invalid') !== 'valid') return;

        $languages = function_exists('reeid_get_supported_languages')
            ? reeid_get_supported_languages()
            : ['fr' => 'French', 'de' => 'German', 'ja' => 'Japanese', 'th' => 'Thai', 'es' => 'Spanish', 'ru' => 'Russian'];

        $picklist_languages = function_exists('reeid_get_allowed_languages')
            ? array_keys(reeid_get_allowed_languages())
            : ['en', 'es', 'fr', 'de', 'zh', 'ja', 'ar', 'ru', 'th', 'it'];

        $enabled_languages = function_exists('reeid_get_enabled_languages')
            ? reeid_get_enabled_languages()
            : [];

        $languages_json          = wp_json_encode($languages, JSON_UNESCAPED_UNICODE);
        $picklist_languages_json = wp_json_encode($picklist_languages, JSON_UNESCAPED_UNICODE);
        $enabled_languages_json  = wp_json_encode($enabled_languages, JSON_UNESCAPED_UNICODE);
        $ajaxurl = esc_url(admin_url('admin-ajax.php'));
        $nonce   = esc_js(wp_create_nonce('reeid_translate_nonce_action'));

        $js =
            '(function(){
        var langs = ' . $languages_json . ';
        var picklistLanguages = ' . $picklist_languages_json . ';
        var enabledLangs = ' . $enabled_languages_json . ';
        var ajaxurl = "' . $ajaxurl . '";
        var nonce   = "' . $nonce . '";
        var panelId = "elementor-panel-page-settings-controls";
        var panelHtml = ' .
            '\'<div id="reeid-elementor-panel" class="reeid-panel">\' +
                \'<div class="reeid-panel-header">REEID TRANSLATION</div>\' +
                \'<div class="reeid-field"><strong>Target Language</strong><select id="reeid_elementor_lang" class="reeid-picklist">\' +
                    Object.entries(langs).filter(function(entry){ return picklistLanguages.includes(entry[0]); }).map(function(entry){ return \'<option value="\' + entry[0] + \'">\' + entry[1] + \'</option>\'; }).join(\'\') +
                \'</select></div>\' +
                \'<div class="reeid-field"><strong>Tone</strong><select id="reeid_elementor_tone" style="width:100%;">\' +
                    \'<option value="">Use default</option>\' +
                    \'<option value="Neutral">Neutral</option>\' +
                    \'<option value="Formal">Formal</option>\' +
                    \'<option value="Informal">Informal</option>\' +
                    \'<option value="Friendly">Friendly</option>\' +
                    \'<option value="Technical">Technical</option>\' +
                    \'<option value="Persuasive">Persuasive</option>\' +
                    \'<option value="Concise">Concise</option>\' +
                    \'<option value="Verbose">Verbose</option>\' +
                \'</select></div>\' +
                \'<div class="reeid-field"><strong>Custom Prompt</strong><textarea id="reeid_elementor_prompt" rows="3" style="width:100%;"></textarea></div>\' +
                \'<div class="reeid-field"><strong>Publish Mode</strong><select id="reeid_elementor_mode" style="width:100%;">\' +
                    \'<option value="publish">Publish</option>\' +
                    \'<option value="draft">Save as Draft</option>\' +
                \'</select></div>\' +
                \'<div class="reeid-buttons">\' +
                    \'<button type="button" class="reeid-button primary" id="reeid_elementor_translate">Translate Now</button>\' +
                    \'<button type="button" class="reeid-button secondary" id="reeid_elementor_bulk">Bulk Translate</button>\' +
                \'</div>\' +
                \'<div id="reeid-status"></div>\' +
            \'</div>\';

        function getPostId(){
            if (window.elementor && window.elementor.config && window.elementor.config.post_id) return elementor.config.post_id;
            if (window.elementorCommon && window.elementorCommon.config && window.elementorCommon.config.post_id) return elementorCommon.config.post_id;
            if (window.elementor && window.elementor.settings && window.elementor.settings.page && window.elementor.settings.page.model && window.elementor.settings.page.model.id) return elementor.settings.page.model.id;
            var match = window.location.search.match(/[?&]post=(\\d+)/);
            return match ? match[1] : null;
        }

        function startBulkTranslation() {
            var jq    = window.jQuery;
            var pid   = getPostId();
            var tone  = jq("#reeid_elementor_tone").val() || "Neutral";
            var prompt= jq("#reeid_elementor_prompt").val() || "";
            var mode  = jq("#reeid_elementor_mode").val() || "publish";

            // STRICT: only Admin-enabled languages; do not fallback to any list
            var languageCodes = Array.isArray(enabledLangs)
                ? enabledLangs.filter(function(lang){ return Object.prototype.hasOwnProperty.call(langs, lang); })
                : [];

            // === HARD STOP (single message) if none selected in Admin Settings ===
            if (!languageCodes.length) {
                jq("#reeid-status").html(\'<span style="color:#c00;">❌ No bulk languages selected in Settings. Please choose at least one in “Bulk Translation Languages”.</span>\');
                return;
            }

            var results = {};
            var currentIndex = 0;
            var statusEl = document.getElementById("reeid-status");
            statusEl.innerHTML = "";
            var progressContainer = document.createElement("div");
            progressContainer.style.marginBottom = "10px";
            progressContainer.innerHTML = \'<div style="font-weight:bold;">Progress: <span id="reeid-bulk-progress">0/\' + languageCodes.length + \'</span></div>\';
            statusEl.appendChild(progressContainer);

            function processNextLanguage() {
                if (currentIndex >= languageCodes.length) {
                    return;
                }
                var lang  = languageCodes[currentIndex];
                var label = langs[lang] || lang.toUpperCase();
                document.getElementById("reeid-bulk-progress").textContent = (currentIndex + 1) + "/" + languageCodes.length;

                var row = document.createElement("div");
                row.className = "reeid-status-row";
                row.innerHTML =
                    \'<span class="reeid-status-emoji">⏳</span>\' +
                    \'<span class="reeid-status-lang">\' + label + \':</span>\' +
                    \'<span>Processing...</span>\';
                statusEl.appendChild(row);

                jq.post(ajaxurl, {
                    action: "reeid_translate_openai",
                    reeid_translate_nonce: nonce,
                    post_id: pid,
                    lang: lang,
                    tone: tone,
                    prompt: prompt,
                    reeid_publish_mode: mode
                }).done(function(res) {
                    row.innerHTML =
                        \'<span class="reeid-status-emoji">\' + (res.success ? "✅" : "❌") + \'</span>\' +
                        \'<span class="reeid-status-lang">\' + label + \':</span>\' +
                        \'<span>\' + (res.success ? "Done" : (res.data && (res.data.error || res.data.message) ? (res.data.error || res.data.message) : "Failed")) + \'</span>\';
                    results[lang] = { success: res.success };
                }).fail(function() {
                    row.innerHTML =
                        \'<span class="reeid-status-emoji">❌</span>\' +
                        \'<span class="reeid-status-lang">\' + label + \':</span>\' +
                        \'<span>AJAX failed</span>\';
                    results[lang] = { success: false };
                }).always(function() {
                    currentIndex++;
                    setTimeout(processNextLanguage, 500);
                });
            }
            processNextLanguage();
        }

        function bindReeidBulkButtonHandler() {
            // No popup, no beforeunload — either run or show one message
            window.jQuery("#reeid_elementor_bulk").off("click.reeidmodal").on("click.reeidmodal", function(e){
                e.preventDefault();
                startBulkTranslation();
            });
        }

        function injectPanel() {
            var panel = document.getElementById(panelId);
            if (!panel || document.getElementById("reeid-elementor-panel")) return;
            panel.insertAdjacentHTML("beforeend", panelHtml);
            var jq = window.jQuery;
            jq("#reeid_elementor_translate").off().on("click", function(e){
                e.preventDefault();
                var $btn = jq(this);
                $btn.prop("disabled", true).text("Translating...");
                jq("#reeid-status").html("⏳ Translating...");
                var pid = getPostId();
                if (!pid) {
                    jq("#reeid-status").html(\'<span style="color:#c00;">❌ Post ID not found</span>\');
                    $btn.prop("disabled", false).text("Translate Now");
                    return;
                }
                jq.post(ajaxurl, {
                    action: "reeid_translate_openai",
                    reeid_translate_nonce: nonce,
                    post_id: pid,
                    lang: jq("#reeid_elementor_lang").val(),
                    tone: jq("#reeid_elementor_tone").val() || "Neutral",
                    prompt: jq("#reeid_elementor_prompt").val() || "",
                    reeid_publish_mode: jq("#reeid_elementor_mode").val() || "publish"
                }).done(function(res){
                    if (res.success) {
                        jq("#reeid-status").html(\'<span style="color:#32c24d;font-weight:bold;">✅ \' + (res.data && res.data.message ? res.data.message : "Translation completed.") + \'</span>\');
                    } else {
                        jq("#reeid-status").html(\'<span style="color:#c00;font-weight:bold;">❌ \' + (res.data && (res.data.error || res.data.message) ? (res.data.error || res.data.message) : "Translation failed.") + \'</span>\');
                    }
                }).fail(function(){
                    jq("#reeid-status").html(\'<span style="color:#c00;font-weight:bold;">❌ AJAX failed. Try again.</span>\');
                }).always(function(){
                    $btn.prop("disabled", false).text("Translate Now");
                });
            });
            bindReeidBulkButtonHandler();
        }

        function watchdog() {
            var currentPanel = null;
            var mo = null;
            function attachObserver() {
                var panel = document.getElementById(panelId);
                if (!panel) {
                    setTimeout(attachObserver, 400);
                    return;
                }
                if (currentPanel && currentPanel !== panel && mo) {
                    mo.disconnect();
                    mo = null;
                }
                if (!mo) {
                    mo = new MutationObserver(function(){ injectPanel(); });
                    mo.observe(panel, { childList:true, subtree:true });
                }
                currentPanel = panel;
                injectPanel();
            }
            setInterval(attachObserver, 1000);
            attachObserver();
        }
        watchdog();
    })();';

        wp_add_inline_script('elementor-editor', $js);
    });




 /*==============================================================================
  SECTION 17 : UTF-8 Slug Router
  - Allows non-Latin slugs (/ar/الذكاء-الاصطناعي-في-الترجمة/) to resolve.
  - Queries post by name with UTF-8 aware regex.
==============================================================================*/
    if (! function_exists('reeid_utf8_slug_router')) {
        add_action('template_redirect', 'reeid_utf8_slug_router', 1);
        function reeid_utf8_slug_router()
        {
            if (is_admin() || is_feed() || is_robots()) return;

            $request_uri = isset($_SERVER['REQUEST_URI']) ? urldecode($_SERVER['REQUEST_URI']) : '';
            $parts = wp_parse_url($request_uri);
            $path  = isset($parts['path']) ? trim($parts['path'], '/') : '';

            // Match /{lang}/{slug...}
            if (preg_match('#^([a-z]{2})/(.+)$#u', $path, $m)) {
                $lang = $m[1];
                $slug = $m[2];

                $q = new WP_Query([
                    'name'           => $slug,
                    'post_type'      => 'any',
                    'posts_per_page' => 1,
                ]);

                if ($q->have_posts()) {
                    global $wp_query;
                    $wp_query = $q;
                    status_header(200);
                    return;
                }
            }
        }
    }


    /* ==================================================================================
    SECTION 18 :  REEID API INTEGRATION — COMBINED 
    =================================================================================== */

    if (! defined('REEID_API_BASE'))          define('REEID_API_BASE', 'https://api.reeid.com');

    if (! defined('REEID_OPT_SITE_UUID'))     define('REEID_OPT_SITE_UUID',   'reeid_site_uuid');
    if (! defined('REEID_OPT_SITE_TOKEN'))    define('REEID_OPT_SITE_TOKEN',  'reeid_site_token');
    if (! defined('REEID_OPT_SITE_SECRET'))   define('REEID_OPT_SITE_SECRET', 'reeid_site_secret');
    if (! defined('REEID_OPT_KP_SECRET'))     define('REEID_OPT_KP_SECRET',   'reeid_kp_secret');   // base64 libsodium keypair
    if (! defined('REEID_OPT_TOKEN_TS'))      define('REEID_OPT_TOKEN_TS',    'reeid_token_issued_at');
    if (! defined('REEID_OPT_FEATURES'))      define('REEID_OPT_FEATURES',    'reeid_features');
    if (! defined('REEID_OPT_LIMITS'))        define('REEID_OPT_LIMITS',      'reeid_limits');

    if (! function_exists('reeid_get_site_uuid')) {
        /** Ensure we have a persistent site UUID. */
        function reeid_get_site_uuid(): string
        {
            $uuid = get_option(REEID_OPT_SITE_UUID);
            if (! $uuid) {
                $uuid = wp_generate_uuid4();
                update_option(REEID_OPT_SITE_UUID, $uuid, true);
            }
            return (string)$uuid;
        }
    }

    if (! function_exists('reeid_nonce_hex')) {
        function reeid_nonce_hex(int $bytes = 12): string
        {
            return bin2hex(random_bytes($bytes));
        }
    }
    if (! function_exists('reeid_hmac_sig')) {
        function reeid_hmac_sig(string $ts, string $nonce, string $body, string $secret): string
        {
            return hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $body, $secret);
        }
    }
    if (! function_exists('reeid_wp_post')) {
        function reeid_wp_post(string $path, array $bodyArr, array $headers = [], int $timeout = 20)
        {
            $url  = rtrim(REEID_API_BASE, '/') . $path;
            $resp = wp_remote_post($url, [
                'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
                'body'    => wp_json_encode($bodyArr, JSON_UNESCAPED_UNICODE),
                'timeout' => $timeout,
            ]);
            if (is_wp_error($resp)) return $resp;
            $code = (int) wp_remote_retrieve_response_code($resp);
            $json = json_decode((string) wp_remote_retrieve_body($resp), true);
            return ['code' => $code, 'json' => $json];
        }
    }

    /* ---------- Libsodium helpers: persist keypair and derive public key ---------- */
    if (! function_exists('reeid_sodium_have')) {
        function reeid_sodium_have(): bool
        {
            return function_exists('sodium_crypto_box_keypair')
                && function_exists('sodium_crypto_box_publickey')
                && function_exists('sodium_crypto_box_seal_open');
        }
    }
    if (! function_exists('reeid_get_or_make_kp_b64')) {
        /** Returns [keypair_b64, pub_b64]; persists the keypair option if new. */
        function reeid_get_or_make_kp_b64(): array
        {
            if (! reeid_sodium_have()) return [null, null];
            $kp_b64 = get_option(REEID_OPT_KP_SECRET, '');
            if (! $kp_b64) {
                $kp     = sodium_crypto_box_keypair();
                $kp_b64 = base64_encode($kp);
                update_option(REEID_OPT_KP_SECRET, $kp_b64, true);
            } else {
                $kp = base64_decode($kp_b64, true);
                if ($kp === false) {
                    // corrupted option, rebuild
                    $kp     = sodium_crypto_box_keypair();
                    $kp_b64 = base64_encode($kp);
                    update_option(REEID_OPT_KP_SECRET, $kp_b64, true);
                }
            }
            $pub_b64 = base64_encode(sodium_crypto_box_publickey(base64_decode($kp_b64, true)));
            return [$kp_b64, $pub_b64];
        }
    }

    /* ---------------------------- Handshake (cached) ---------------------------- */
    if (! function_exists('reeid_api_handshake')) {
        /**
         * Fetch site_token + site_secret. Refreshes every ~22h or on $force.
         * Requires libsodium (server requires client_pubkey).
         */
        function reeid_api_handshake(bool $force = false): array
        {
            $site_token = (string) get_option(REEID_OPT_SITE_TOKEN, '');
            $issued_at  = (int)    get_option(REEID_OPT_TOKEN_TS, 0);

            if (! $force && $site_token && (time() - $issued_at) < 22 * 3600) {
                return [
                    'ok'          => true,
                    'site_token'  => $site_token,
                    'site_secret' => (string) get_option(REEID_OPT_SITE_SECRET, ''),
                    'sealed'      => (bool) get_option(REEID_OPT_KP_SECRET, ''),
                ];
            }

            if (! reeid_sodium_have()) {
                return ['ok' => false, 'error' => 'libsodium_missing'];
            }
            list($kp_b64, $pub_b64) = reeid_get_or_make_kp_b64();
            if (empty($pub_b64)) {
                return ['ok' => false, 'error' => 'keypair_init_failed'];
            }

            $payload = [
                'license_key'    => trim((string) get_option('reeid_license_key', 'REPLACE_ME')), // adjust to your stored license option
                'site_url'       => home_url(),
                'site_uuid'      => reeid_get_site_uuid(),
                'plugin_version' => defined('REEID_PLUGIN_VERSION') ? REEID_PLUGIN_VERSION : '1.0.0',
                'wp_version'     => get_bloginfo('version'),
                'php_version'    => PHP_VERSION,
                'client_pubkey'  => $pub_b64, // REQUIRED by server
            ];

            $res = reeid_wp_post('/v1/license/handshake', $payload);
            if (is_wp_error($res) || !is_array($res['json'] ?? null) || empty($res['json']['ok'])) {
                return ['ok' => false, 'error' => 'handshake_failed', 'detail' => is_wp_error($res) ? $res->get_error_message() : ($res['json']['code'] ?? 'http_' . $res['code'])];
            }

            $j = $res['json'];
            update_option(REEID_OPT_SITE_TOKEN,  (string)($j['site_token']  ?? ''), true);
            update_option(REEID_OPT_SITE_SECRET, (string)($j['site_secret'] ?? ''), true);
            update_option(REEID_OPT_TOKEN_TS,    time(), true);
            if (isset($j['features'])) update_option(REEID_OPT_FEATURES, (array)$j['features'], false);
            if (isset($j['limits']))   update_option(REEID_OPT_LIMITS,   (array)$j['limits'],   false);

            return ['ok' => true, 'site_token' => $j['site_token'] ?? '', 'site_secret' => $j['site_secret'] ?? '', 'sealed' => true];
        }
    }

    /* -------------------- /v1/rules/plan (signed + sealed) --------------------- */
    if (! function_exists('reeid_api_rules_plan')) {
        /**
         * Ask server for model/params/system (rulepack), decrypt sealed response.
         * Returns array ['model','params','system','flags',...] or WP_Error.
         */
        function reeid_api_rules_plan(string $source_lang, string $target_lang, string $editor, string $tone = 'neutral', $content_ctx = '')
        {
            // Ensure we have token/secret (auto-refresh if needed)
            $hs = reeid_api_handshake(false);
            if (! $hs['ok']) {
                $hs = reeid_api_handshake(true);
                if (! $hs['ok']) return new WP_Error('reeid_handshake', 'Handshake failed: ' . ($hs['detail'] ?? $hs['error'] ?? 'unknown'));
            }

            $site_token  = (string) get_option(REEID_OPT_SITE_TOKEN, '');
            $site_secret = (string) get_option(REEID_OPT_SITE_SECRET, '');
            if ($site_token === '' || $site_secret === '') {
                return new WP_Error('reeid_missing_creds', 'Missing site token/secret');
            }
            if (! reeid_sodium_have()) {
                return new WP_Error('reeid_sodium_missing', 'PHP libsodium missing');
            }
            list($kp_b64, $pub_b64) = reeid_get_or_make_kp_b64();
            $kp_bin = base64_decode((string)$kp_b64, true);
            if ($kp_bin === false) return new WP_Error('reeid_kp_corrupt', 'Stored sodium keypair invalid');

            $tone = strtolower((string)($tone ?: 'neutral'));
            $content_hash = (is_string($content_ctx) && preg_match('/^[a-f0-9]{64}$/', $content_ctx))
                ? $content_ctx
                : hash('sha256', is_string($content_ctx) ? $content_ctx : wp_json_encode($content_ctx));

            $bodyArr = [
                'site_token'   => $site_token,
                'source_lang'  => $source_lang,
                'target_lang'  => $target_lang,
                'editor'       => $editor,     // 'gutenberg'|'elementor'|'classic'
                'tone'         => $tone,
                'content_hash' => $content_hash,
            ];
            $bodyJson = wp_json_encode($bodyArr, JSON_UNESCAPED_UNICODE);
            $ts       = (string) time();
            $nonce    = reeid_nonce_hex(12);
            $sig      = reeid_hmac_sig($ts, $nonce, $bodyJson, $site_secret);

            $res = reeid_wp_post('/v1/rules/plan', $bodyArr, [
                'X-REEID-Ts'    => $ts,
                'X-REEID-Nonce' => $nonce,
                'X-REEID-Sig'   => $sig,
            ]);
            if (is_wp_error($res))                  return $res;
            if ((int)($res['code'] ?? 500) === 401) return new WP_Error('reeid_unauthorized', 'Invalid token/secret');
            if ((int)($res['code'] ?? 500) === 429) return new WP_Error('reeid_throttled',   'Rate limit exceeded');
            if (empty($res['json']['ok']))         return new WP_Error('reeid_api_error',    'API error: ' . (string)($res['json']['code'] ?? 'http_' . $res['code']));

            $sip_b64 = (string)($res['json']['sip_token'] ?? '');
            $cipher  = base64_decode($sip_b64, true);
            if ($cipher === false) return new WP_Error('reeid_sip_decode', 'Bad sip_token');

            $plain = sodium_crypto_box_seal_open($cipher, $kp_bin);
            if ($plain === false) return new WP_Error('reeid_sip_decrypt', 'Failed to decrypt sip_token');

            $rulepack = json_decode($plain, true);
            if (! is_array($rulepack)) return new WP_Error('reeid_sip_parse', 'Decrypted rulepack not JSON');

            return $rulepack;
        }
    }

    /* -------------------- Handshake auto-refresh (cron + first run) -------------------- */

    /** Schedule every 12h (first run ~5 min after activation) */
    add_action('init', function () {
        if (! wp_next_scheduled('reeid_refresh_handshake_event')) {
            wp_schedule_event(time() + 300, 'twicedaily', 'reeid_refresh_handshake_event');
        }
    });

    /** Unschedule on deactivation */
    if (function_exists('register_deactivation_hook')) {
        register_deactivation_hook(__FILE__, function () {
            $ts = wp_next_scheduled('reeid_refresh_handshake_event');
            if ($ts) wp_unschedule_event($ts, 'reeid_refresh_handshake_event');
        });
    }

    /** The job: call handshake (cached; will refresh as needed) */
    add_action('reeid_refresh_handshake_event', function () {
        try {
            reeid_api_handshake(false);
        } catch (Throwable $e) {
        }
    });

    /** Kick a first handshake if missing when an admin page is loaded */
    add_action('admin_init', function () {
        if (get_option(REEID_OPT_SITE_TOKEN) && get_option(REEID_OPT_SITE_SECRET)) return;
        try {
            reeid_api_handshake(true);
        } catch (Throwable $e) {
        }
    });

    if (! function_exists('reeid_translate_html_with_openai')) {
        /**
         * Prompt-aware short/medium text translator used by Gutenberg/Classic/Woo.
         * Backward-compatible signature: we add $prompt as the last, optional param.
         *
         * @param string $text
         * @param string $source_lang  e.g., 'en'
         * @param string $target_lang  e.g., 'th'
         * @param string $editor       'gutenberg' | 'classic' | 'elementor' | 'woocommerce'
         * @param string $tone         default 'Neutral'
         * @param string $prompt       (NEW) merged custom prompt (per-request/admin); can be ''
         * @return string              translated text (or original on failure)
         */
        function reeid_translate_html_with_openai(
            string $text,
            string $source_lang,
            string $target_lang,
            string $editor,
            string $tone = 'Neutral',
            string $prompt = ''
        ): string {
            $api_key = (string) get_option('reeid_openai_api_key', '');
            if ($text === '' || $source_lang === $target_lang || $api_key === '') {
                return $text;
            }

            // Build strict system message + optional custom instructions
            // Prefer layered helper; fall back to a safe neutral system message
if ( function_exists( 'reeid_get_combined_prompt' ) ) {
    // function has $prompt param (already merged at call sites); post_id not available => 0
    $sys = reeid_get_combined_prompt( 0, $target_lang, (string) $prompt );
} else {
    $sys = "You are a professional translator. Translate the source text from {$source_lang} to {$target_lang}, preserving structure, tags and placeholders. Match tone and produce idiomatic, human-quality output.";
    if ( is_string( $prompt ) && trim( $prompt ) !== '' ) {
        $sys .= ' ' . trim( $prompt );
    }
}


            // Compose OpenAI payload
            $payload = json_encode([
                "model"       => "gpt-4o",
                "temperature" => 0,
                "messages"    => [
                    ["role" => "system", "content" => $sys],
                    ["role" => "user",   "content" => (string) $text]
                ]
            ], JSON_UNESCAPED_UNICODE);

            // Call OpenAI
            $ch = curl_init("https://api.openai.com/v1/chat/completions");
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER     => [
                    "Content-Type: application/json",
                    "Authorization: Bearer " . $api_key
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => 45,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_ENCODING       => ''
            ]);
            $resp = curl_exec($ch);
            if ($resp === false) {
                if (function_exists('reeid_debug_log')) {
                    reeid_debug_log('S17/SHORT/CURL', curl_error($ch));
                }
                curl_close($ch);
                return $text;
            }
            curl_close($ch);

            $json = json_decode($resp, true);
            $out  = isset($json['choices'][0]['message']['content']) ? (string)$json['choices'][0]['message']['content'] : '';
            $out  = trim($out);

            // Strip code fences & accidental wrappers
            if ($out !== '') {
                $out = preg_replace('/^```(?:json|[a-zA-Z0-9_-]+)?\s*/', '', $out);
                $out = preg_replace('/\s*```$/', '', $out);
                $out = trim($out);
            }

            // Safety: avoid returning empty or identical content
            if ($out === '' || $out === $text) {
                return $text;
            }
$out = str_replace('<\/', '</', $out);
            return $out;
        }
    }

    /*=======================================================================================
  SECTION 19: ELEMENTOR FRONTEND BOOTSTRAP (PRODUCTION MODE — CLEAN)
  - Provides essential Elementor config before scripts
  - Forces init if optimizer delays scripts
  - Removes async/defer from critical Elementor/Jquery handles
=======================================================================================*/

/* --- A) Provide frontend config BEFORE Elementor’s own bundle --- */
add_action('wp_enqueue_scripts', function () {
    if (is_admin() || wp_doing_ajax()) return;

    $assets_url = '';
    $version    = '';
    $is_debug   = (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG);

    if (class_exists('\Elementor\Plugin')) {
        try {
            $assets_url = plugins_url('elementor/assets/', WP_PLUGIN_DIR . '/elementor/elementor.php');
            if (method_exists(\Elementor\Plugin::$instance, 'get_version')) {
                $version = (string)\Elementor\Plugin::$instance->get_version();
            }
        } catch (\Throwable $e) {}
    }
    if (!$assets_url) $assets_url = plugins_url('elementor/assets/');
    if (!$version)    $version    = '3.x';

    $cfg = [
        'urls' => [
            'assets'    => rtrim($assets_url, '/') . '/',
            'uploadUrl' => content_url('uploads/'),
        ],
        'environmentMode' => [
            'edit'          => false,
            'wpPreview'     => false,
            'isScriptDebug' => $is_debug,
        ],
        'version'    => $version,
        'settings'   => ['page' => new stdClass()],
        'responsive' => [
            'hasCustomBreakpoints' => false,
            'breakpoints'          => new stdClass(),
        ],
        'is_rtl' => is_rtl(),
        'i18n'   => new stdClass(),
    ];

    $shim = 'window.elementorFrontendConfig = window.elementorFrontendConfig || ' .
            wp_json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';';

    foreach ([
        'elementor-webpack-runtime',
        'elementor-frontend',
        'elementor-pro-frontend',
    ] as $handle) {
        if (wp_script_is($handle, 'registered') || wp_script_is($handle, 'enqueued')) {
            wp_add_inline_script($handle, $shim, 'before');
        }
    }
}, 1);


/* --- B) Force-init Elementor if an optimizer delayed execution --- */
add_action('wp_enqueue_scripts', function () {
    if (is_admin() || wp_doing_ajax()) return;

    $bootstrap =
        '(function(w,$){function safeInit(){if(!w.elementorFrontend)return;try{' .
        'if(!elementorFrontend.hooks){elementorFrontend.init();}' .
        'if(elementorFrontend.onDocumentLoaded){elementorFrontend.onDocumentLoaded();}' .
        '}catch(e){}}' .
        'if($){$(safeInit);$(w).on("load",safeInit);}else{' .
        'w.addEventListener("DOMContentLoaded",safeInit);w.addEventListener("load",safeInit);}})' .
        '(window,window.jQuery);';

    foreach (['elementor-frontend', 'elementor-pro-frontend'] as $handle) {
        if (wp_script_is($handle, 'registered') || wp_script_is($handle, 'enqueued')) {
            wp_add_inline_script($handle, $bootstrap, 'after');
        }
    }
}, 20);


/* --- C) Remove async/defer from critical handles --- */
add_filter('script_loader_tag', function ($tag, $handle) {
    $critical = [
        'jquery', 'jquery-core', 'jquery-migrate',
        'elementor-webpack-runtime', 'elementor-frontend',
        'elementor-frontend-modules', 'elementor-pro-frontend',
    ];
    if (in_array($handle, $critical, true)) {
        $tag = str_replace([' async="async"', ' async', ' defer="defer"', ' defer'], '', $tag);
    }
    return $tag;
}, 20, 2);



/*==============================================================================
  SECTION 20: ELEMENTOR META VERIFIER/REPAIR (RETAINED – PRODUCTION SAFE)
==============================================================================*/

add_action(
    'template_redirect',
    function () {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $check  = filter_input( INPUT_GET, 'reeid_check', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
        $repair = filter_input( INPUT_GET, 'reeid_repair', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
        $nonce  = filter_input( INPUT_GET, '_wpnonce', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

        if ( ! $check && ! $repair ) {
            return;
        }

        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'reeid_diag_action' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'reeid-translate' ) );
        }

        global $post;
        if ( ! $post ) {
            wp_die( esc_html__( 'No global $post.', 'reeid-translate' ) );
        }

        $pid = (int) $post->ID;

        header( 'Content-Type: text/plain; charset=UTF-8' );

        $keys = array(
            '_elementor_data',
            '_elementor_edit_mode',
            '_elementor_template_type',
            '_elementor_version',
            '_elementor_page_settings',
            '_elementor_css',
        );

        $meta = array();
        foreach ( $keys as $k ) {
            $meta[ $k ] = get_post_meta( $pid, $k, true );
        }

        echo 'REEID Elementor Meta (' . ( $repair ? 'REPAIR' : 'CHECK' ) . ') — Post: ' . esc_html( $pid ) . "\n";
        echo esc_html( str_repeat( '=', 70 ) ) . "\n\n";

        $problems = array();

        $raw     = isset( $meta['_elementor_data'] ) ? (string) $meta['_elementor_data'] : '';
        $decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;

        if ( ! $decoded ) {
            $problems[] = '_elementor_data not valid JSON.';
            $try        = json_decode( stripslashes( $raw ), true );

            if ( $try ) {
                $decoded = $try;
            }
        }

        if ( empty( $meta['_elementor_edit_mode'] ) ) {
            $problems[] = '_elementor_edit_mode missing';
        }
        if ( empty( $meta['_elementor_template_type'] ) ) {
            $problems[] = '_elementor_template_type missing';
        }
        if ( empty( $meta['_elementor_version'] ) ) {
            $problems[] = '_elementor_version missing';
        }

        if ( ! $problems ) {
            echo "OK — Elementor page metadata looks correct.\n";
        } else {
            echo "Problems:\n - " . esc_html( implode( "\n - ", $problems ) ) . "\n";
        }

        if ( ! $repair ) {
            exit;
        }

        echo "\nRepairing…\n";

        if ( $decoded ) {
            update_post_meta( $pid, '_elementor_data', wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE ) );
            echo "Fixed _elementor_data JSON\n";
        }

        if ( empty( $meta['_elementor_edit_mode'] ) ) {
            update_post_meta( $pid, '_elementor_edit_mode', 'builder' );
        }
        if ( empty( $meta['_elementor_template_type'] ) ) {
            update_post_meta( $pid, '_elementor_template_type', 'wp-page' );
        }
        if ( empty( $meta['_elementor_version'] ) ) {
            update_post_meta( $pid, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.x' );
        }
        if ( empty( $meta['_elementor_page_settings'] ) ) {
            update_post_meta( $pid, '_elementor_page_settings', array() );
        }

        if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
            try {
                \Elementor\Core\Files\CSS\Post::create( $pid )->update();
                echo "Regenerated Elementor CSS\n";
            } catch ( \Throwable $e ) {
                // Silent.
            }
        }

        if ( class_exists( '\Elementor\Plugin' ) ) {
            try {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
                echo "Cleared Elementor cache\n";
            } catch ( \Throwable $e ) {
                // Silent.
            }
        }

        echo "\nRepair complete.\n";
        exit;
    }
);





/*==============================================================================
  SECTION 21: HREFLANG ROUTER OVERRIDE
==============================================================================*/

add_action('template_redirect', function () {
    if (function_exists('reeid_hreflang_print'))
        remove_action('wp_head', 'reeid_hreflang_print', 90);
    add_action('wp_head', 'reeid_hreflang_print_canonical', 90);
}, 0);



/*==============================================================================
  SECTION 22: REST JSON HEADERS GUARD
==============================================================================*/

add_filter('rest_pre_serve_request', function ($served, $result) {
    while (ob_get_level()) @ob_end_clean();
    if (!headers_sent())
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
    return $served;
}, 9999, 2);



/*==============================================================================
  SECTION 23: AJAX JSON GUARD
==============================================================================*/

add_action('init', function () {
    if (!wp_doing_ajax()) return;

    $raw = filter_input(INPUT_POST, 'action') ?: filter_input(INPUT_GET,'action') ?: '';
    $action = sanitize_key((string)$raw);
    if (!preg_match('/^reeid[_-]/', $action)) return;

    // Nonce soft-check
    $nonce_sources = [
        ['POST','nonce'],['POST','security'],['POST','_ajax_nonce'],['POST','_wpnonce'],
        ['GET','nonce'], ['GET','security'], ['GET','_ajax_nonce'], ['GET','_wpnonce'],
    ];
    $nonce_seen = false;
    $nonce_ok   = false;

    foreach ($nonce_sources as $src) {
        [$method,$key] = $src;
        $val = filter_input($method==='POST'?INPUT_POST:INPUT_GET,$key);
        if ($val) {
            $nonce_seen = true;
            $v = sanitize_text_field($val);
            if (wp_verify_nonce($v,'reeid_translate_nonce_action') || wp_verify_nonce($v,$action))
                $nonce_ok = true;
            break;
        }
    }

    if ($nonce_seen && !$nonce_ok) {
        if (!headers_sent()) {
            status_header(403);
            header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        }
        echo wp_json_encode(['ok'=>false,'error'=>'invalid_nonce']);
        exit;
    }

    // Output buffer guard
    while (ob_get_level()) @ob_end_clean();
    ob_start();

    add_action('send_headers', function () {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=' . get_option('blog_charset'));
            header('X-Content-Type-Options: nosniff');
            nocache_headers();
        }
    }, 0);

   add_action(
    'shutdown',
    function () {
        $out = ob_get_contents();

        if ( preg_match( '/\{[\s\S]*\}\s*$/u', $out, $m ) && json_decode( $m[0], true ) ) {
            while ( ob_get_level() ) {
                @ob_end_clean();
            }

            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $m[0]; // JSON output must not be escaped.
        }
    },
    9999
);

});



/*==============================================================================
  SECTION 24: LANGUAGE COOKIE FORCE
==============================================================================*/

add_action('template_redirect', function () {
    if (empty($_GET['reeid_force_lang'])) return;

    $lang = strtolower(substr(sanitize_text_field($_GET['reeid_force_lang']),0,10));
    if (!$lang) return;

    $domain = defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '';

    foreach (['site_lang','reeid_lang'] as $cookie) {
        setcookie($cookie, $lang, [
            'expires'  => time()+DAY_IN_SECONDS,
            'path'     => '/',
            'domain'   => $domain,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$cookie] = $lang;
    }
}, 1);



/*==============================================================================
  SECTION 25: FRONTEND SWITCHER ASSET ENSURE / SWITCHER DEFAULTS (STYLE/THEME)
==============================================================================*/
add_action('wp_enqueue_scripts', function () {
    if (function_exists('reeid_enqueue_switcher_assets'))
        reeid_enqueue_switcher_assets();
}, 20);


add_action('init', function () {
    add_filter('shortcode_atts_reeid_language_switcher', function ($out,$pairs,$atts) {
        if (empty($atts['style'])) {
            $style = get_option('reeid_switcher_style','dropdown');
            if ($style==='default') $style='dropdown';
            $out['style'] = $style;
        }
        if (empty($atts['theme'])) {
            $out['theme'] = get_option('reeid_switcher_theme','auto');
        }
        return $out;
    },10,3);
}, 9);


/*==============================================================================
  SECTION 26: SWITCHER OUTPUT TWEAK
==============================================================================*/

add_action('init', function () {
    add_filter('do_shortcode_tag', function ($output,$tag,$attr) {
        if ($tag!=='reeid_language_switcher' || !$output) return $output;

        $style = !empty($attr['style']) ? $attr['style'] : get_option('reeid_switcher_style','dropdown');
        if ($style==='default') $style='dropdown';
        $theme = !empty($attr['theme']) ? $attr['theme'] : get_option('reeid_switcher_theme','auto');

        $style_class = $style==='dropdown' ? 'reeid-dropdown' : ('reeid-switcher--'.preg_replace('/[^a-z0-9_-]/i','',$style));
        $theme_class = $theme ? 'reeid-theme-'.preg_replace('/[^a-z0-9_-]/i','',$theme) : '';

        return preg_replace_callback(
            '#<([a-z0-9]+)([^>]*)id=("|\')reeid-switcher-container\\3([^>]*)>#i',
            function ($m) use ($style_class,$theme,$theme_class) {
                $tag  = $m[1];
                $attr = $m[2].$m[4];

                if (preg_match('/class=("|\')(.*?)\\1/i',$attr,$cm)) {
                    $classes = $cm[2];
                    if (stripos($classes,$style_class)===false) $classes .= ' '.$style_class;
                    if ($theme_class && stripos($classes,'reeid-theme-')===false) $classes .= ' '.$theme_class;
                    $attr = preg_replace('/class=("|\')(.*?)\\1/i','class="'.$classes.'"',$attr,1);
                } else {
                    $attr .= ' class="'.$style_class.($theme_class?' '.$theme_class:'').'" ';
                }

                if ($theme && stripos($attr,'data-theme=')===false) {
                    $attr .= ' data-theme="'.esc_attr($theme).'"';
                }

                return '<'.$tag.$attr.'>';
            },
            $output,
            1
        );
    },10,3);
},10);



/*==============================================================================
  SECTION 27: WOO PRODUCT SLUG ROUTER (INLINE TRANSLATIONS)
==============================================================================*/

add_action('pre_get_posts', function ($q) {
    if (is_admin() || !$q->is_main_query()) return;
    if (!isset($q->query_vars['post_type']) || $q->query_vars['post_type']!=='product') return;

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    if (!preg_match('#^/([a-z]{2}(?:-[a-z0-9]{2})?)/product/([^/]+)/?$#u',$path,$mm)) return;

    $lang = strtolower($mm[1]);
    $slug = rawurldecode($mm[2]);

    global $wpdb;
    $products = $wpdb->get_results("SELECT ID,post_name FROM {$wpdb->posts} WHERE post_type='product' AND post_status IN ('publish','private','draft')");

    foreach ($products as $prod) {
        $meta = get_post_meta($prod->ID,'_reeid_wc_tr_'.$lang,true);
        if (is_array($meta) && !empty($meta['slug']) && rawurldecode($meta['slug'])===$slug) {
            $q->set('name',$prod->post_name);
            $q->set('pagename',false);
            return;
        }
    }
}, 1);



/*==============================================================================
  SECTION 28: DISABLE INLINE → post_content SYNC (SAFETY)
==============================================================================*/
add_action('plugins_loaded', function () {
    if (function_exists('reeid_inline_sync_handle_meta')) {
        remove_action('added_post_meta','reeid_inline_sync_handle_meta',10);
        remove_action('updated_post_meta','reeid_inline_sync_handle_meta',10);
    }
    if (function_exists('reeid_inline_sync_save_post_backstop')) {
        remove_action('save_post_product','reeid_inline_sync_save_post_backstop',20);
    }
});



/*==============================================================================
  SECTION 29: VALIDATE-KEY JS LOADER (admin)
==============================================================================*/
add_action('init', function () {
    add_action('admin_enqueue_scripts','reeid_admin_validate_key_script',99);
});



/*==============================================================================
  FINAL INCLUDES
==============================================================================*/

require_once __DIR__ . "/includes/rt-native-slugs.php";
//require_once __DIR__ . "/includes/rt-gb-guard.php";
require_once __DIR__ . "/includes/rt-gb-safety-pack.php";

add_action('plugins_loaded', function () {
    $dir = __DIR__ . "/includes/bootstrap";
    if (is_dir($dir)) {
        foreach (glob($dir."/*.php") as $f) if (is_file($f)) require_once $f;
    }
},0);

require_once __DIR__ . "/includes/rt-clean-dup-bracketed.php";
require_once __DIR__ . "/includes/rt-strip-ascii-paragraphs.php";
require_once __DIR__ . "/includes/rt-trace-dup-source.php";

/* ============================================================
    SECTION 30 : ACTIVE AJAX HANDLER — VALIDATE OPENAI API KEY
 * - Safe: wrapped in function_exists guard
 * - Nonce: expects 'reeid_translate_nonce_action' via _wpnonce/_ajax_nonce
 * - Endpoint: uses chat completions to support project-scoped keys
 * ============================================================ */
    if (! function_exists('reeid_validate_openai_key')) {
        add_action('wp_ajax_reeid_validate_openai_key', 'reeid_validate_openai_key');
        function reeid_validate_openai_key()
        {
            // verify nonce (accept _wpnonce)
            /* BEGIN REEID tolerant nonce check */
$nonce = $_REQUEST['nonce'] ?? $_POST['nonce'] ?? $_GET['nonce'] ?? '';
$ok = false;
if (! empty($nonce)) {
    if ( wp_verify_nonce( $nonce, 'reeid_translate_nonce' ) ) {
        $ok = true;
    } elseif ( wp_verify_nonce( $nonce, 'reeid_translate_nonce_action' ) ) {
        $ok = true;
    }
}
if ( ! $ok ) {
    wp_send_json_error( array( 'error' => 'bad_nonce', 'msg' => 'Invalid/missing nonce. Please reload editor.' ) );
}
/* END REEID tolerant nonce check */

            if (! current_user_can('manage_options')) {
                wp_send_json_error(array('message' => 'Permission denied'), 403);
            }

            // retrieve key safely
            $key_raw = isset($_POST['key']) ? wp_unslash($_POST['key']) : '';
            $key     = sanitize_text_field($key_raw);

            if (empty($key)) {
                wp_send_json_error(array('message' => 'API key is empty'), 400);
            }

            // call OpenAI (chat completions) — small ping, low cost
            $args = array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $key,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode(array(
                    'model'     => 'gpt-4o-mini',
                    'messages'  => array(array('role' => 'system', 'content' => 'ping')),
                    'max_tokens' => 1,
                )),
                'timeout' => 12,
            );

            $response = wp_remote_post('https://api.openai.com/v1/chat/completions', $args);

            if (is_wp_error($response)) {
                error_log('REEID: wp_remote_post error: ' . $response->get_error_message());
                update_option('reeid_openai_status', 'invalid');
                wp_send_json_error(array('message' => 'Connection failed: ' . $response->get_error_message()));
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            // debug short snippet
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('REEID: openai validate code=' . $code . ' body=' . substr($body, 0, 1000));
            }

            // treat 200 and 429 as valid (429 = rate limit but key valid)
            if (in_array($code, array(200, 429), true)) {
                update_option('reeid_openai_status', 'valid');
                wp_send_json_success(array('message' => '✅ Valid API Key'));
            }

            update_option('reeid_openai_status', 'invalid');
            wp_send_json_error(array('message' => '❌ Invalid API Key (' . $code . ')'));
        }
    }


    /**
     * Safe wrapper: sanitize & harden translated SEO title before writing to other plugins
     * Usage: reeid_safe_write_title_all_plugins($post_id, $title_string);
     */
    if (! function_exists('reeid_safe_write_title_all_plugins')) {
        function reeid_safe_write_title_all_plugins($post_id, $title)
        {
            // quick validation
            $post_id = (int) $post_id;
            if ($post_id <= 0) return;

            // normalize to string
            $title = is_scalar($title) ? (string) $title : '';

            // if nothing to write — quit
            $title_trim = trim($title);
            if ($title_trim === '') return;

            // If plugin provides invalid-lang marker hardener, use it (may clear $title_trim)
            if (function_exists('reeid_harden_invalid_lang_pair')) {
                // the helper may modify the string or clear it; pass by reference if that is its contract
                // some versions return or modify — handle both possibilities
                $maybe = reeid_harden_invalid_lang_pair($title_trim);
                if (is_string($maybe)) {
                    $title_trim = $maybe;
                }
            }

            // After hardening, bail if clearly invalid
            if ($title_trim === '' || stripos($title_trim, 'INVALID LANGUAGE PAIR') !== false) return;

            // Decode any escaped unicode sequences if helper exists
            if (function_exists('reeid_decode_unicode_escapes')) {
                $title_trim = reeid_decode_unicode_escapes($title_trim);
            }

            // Final sanitize for safe storage
            if (function_exists('sanitize_text_field')) {
                $title_trim = sanitize_text_field($title_trim);
            } else {
                $title_trim = trim(preg_replace('/\s+/', ' ', strip_tags($title_trim)));
            }

            if ($title_trim === '') return;

            // Finally call the provider that writes to all SEO plugins
            if (function_exists('reeid_write_title_all_plugins')) {
                reeid_write_title_all_plugins($post_id, $title_trim);
            }
        }
    }

    // Prefer sync-only jobs stub; never load the background worker together.
    if (file_exists(__DIR__ . '/includes/jobs-sync.php')) {
        require_once __DIR__ . '/includes/jobs-sync.php';
    } elseif (file_exists(__DIR__ . '/includes/jobs.php') && ! defined('REEID_FORCE_SYNC')) {
        // Fallback only if you ever want background back (leave commented for now)
        // require_once __DIR__ . '/includes/jobs.php';
    }


/**
 * Non-AJAX fallback: delete ALL translations for a product, then redirect back.
 * URL endpoint: wp-admin/admin-post.php?action=reeid_wc_delete_all_translations
 */
add_action('admin_post_reeid_wc_delete_all_translations', function () {

    // Must be logged in
    if (! is_user_logged_in()) {
        wp_die(esc_html__('You must be logged in.', 'reeid-translate'), 403);
    }

    // Read product id
    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    if (! $product_id) {
        wp_die(esc_html__('Missing product.', 'reeid-translate'), 400);
    }

    // Check capability for this specific post
    if (! current_user_can('edit_post', $product_id)) {
        wp_die(esc_html__('Insufficient permissions.', 'reeid-translate'), 403);
    }

    // Nonce (uses the standard _wpnonce field)
    $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
    if (! $nonce || ! wp_verify_nonce($nonce, 'reeid_wc_delete_all')) {
        wp_die(esc_html__('Invalid request (nonce).', 'reeid-translate'), 403);
    }

    // Validate WC product if available
    if (function_exists('wc_get_product') && ! wc_get_product($product_id)) {
        wp_die(esc_html__('Invalid product.', 'reeid-translate'), 404);
    }

    // Remove all translation packets (new + legacy)
    $meta    = get_post_meta($product_id);
    $removed = array();
    foreach (array_keys($meta) as $k) {
        if (preg_match('/^_reeid_wc_tr_([a-zA-Z-]+)$/', $k, $m)) {
            delete_post_meta($product_id, $k);
            $removed[] = $m[1];
        }
        if (preg_match('/^_reeid_wc_inline_([a-zA-Z-]+)$/', $k, $m)) {
            delete_post_meta($product_id, $k);
            $removed[] = $m[1];
        }
    }
    $removed = array_values(array_unique($removed));

    // Clear caches
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients($product_id);
    }
    clean_post_cache($product_id);

    do_action('reeid_wc_translations_deleted_all', $product_id, $removed);

    // Redirect directly to the product editor (avoids referer issues)
    $back = admin_url('post.php?post=' . $product_id . '&action=edit');
    $back = add_query_arg(array(
        'reeid_del_all' => '1',
        'deleted_langs' => implode(',', $removed),
    ), $back);

    wp_redirect($back);
    exit;

    // Add a query arg so you can show an admin notice if desired
    $back = add_query_arg(array(
        'reeid_del_all' => '1',
        'deleted_langs' => implode(',', $removed),
    ), $back);

    wp_safe_redirect($back);
    exit;
});


/**
 * Swap long product description with REEID packet content on the frontend
 * ONLY for non-source languages. Source language (e.g., en) stays untouched.
 */
add_filter('the_content', function ($content) {
    // Frontend only
    if ( is_admin() || wp_doing_ajax() || wp_is_json_request() ) {
        return $content;
    }

    // Must be a single product
    if ( ! is_singular('product') ) {
        return $content;
    }

    global $post;
    if ( ! $post || $post->post_type !== 'product' ) {
        return $content;
    }

    // Resolve source/default language
    $default = function_exists('reeid_s269_default_lang')
        ? strtolower( (string) reeid_s269_default_lang() )
        : strtolower( (string) get_option('reeid_translation_source_lang', 'en') );

    // -------- URL/Cookie/Prefix + tolerant mapping (e.g., "zh" -> "zh-CN") --------
    $lang = '';

    // 1) Explicit param wins
    if ( isset($_GET['reeid_force_lang']) ) {
        $lang = sanitize_text_field( wp_unslash( $_GET['reeid_force_lang'] ) );
    }

    // 2) Cookie fallback
    if ( $lang === '' && isset($_COOKIE['site_lang']) ) {
        $lang = sanitize_text_field( wp_unslash( $_COOKIE['site_lang'] ) );
    }

    // 3) URL prefix (/xx/ or /xx-yy/) fallback with tolerant mapping
    if ( $lang === '' ) {
        $uri   = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $seg   = '';
        if ( $uri !== '' ) {
            $path  = trim( parse_url( $uri, PHP_URL_PATH ), '/' );
            $parts = $path !== '' ? explode( '/', $path ) : array();
            $seg   = isset($parts[0]) ? strtolower( str_replace('_','-',$parts[0]) ) : '';
        }

        // Supported set (normalized)
        $supported = function_exists('reeid_s269_supported_langs') ? array_keys( (array) reeid_s269_supported_langs() ) : array();
        $supported = array_map( function($c){ return strtolower( str_replace('_','-',$c) ); }, $supported );

        if ( $seg !== '' ) {
            if ( empty($supported) ) {
                if ( preg_match( '/^[a-z]{2}(-[a-z]{2})?$/i', $seg ) ) {
                    $lang = $seg;
                }
            } else {
                // Exact match?
                if ( in_array( $seg, $supported, true ) ) {
                    $lang = $seg;
                } else {
                    // Prefix match: e.g., "zh" -> first supported starting with "zh-" (zh-cn, zh-hk, ...)
                    foreach ( $supported as $code ) {
                        if ( strpos( $code, $seg . '-' ) === 0 || $seg === substr( $code, 0, 2 ) ) {
                            $lang = $code;
                            break;
                        }
                    }
                }
            }
        }
    }

    $lang = strtolower( (string) $lang );

    // If no language or it's the source language, do NOT override
    if ( $lang === '' || $lang === $default ) {
        return $content;
    }

    // Load REEID packet for this language and return translated long description if present
    $packet = get_post_meta( (int) $post->ID, "_reeid_wc_tr_{$lang}", true );
    if ( is_array( $packet ) && ! empty( $packet['content'] ) ) {
        return (string) $packet['content'];
    }

    // Fallback to original content
    return $content;
}, 5);

/**
 * FINAL GUARD:
 * Strip WooCommerce attributes table from the main product content,
 * so it does not appear in the Description tab.
 *
 * Additional information tab still renders attributes via wc_display_product_attributes(),
 * which does NOT go through this filter.
 */
if (! function_exists('reeid_strip_wc_attrs_from_description_tab')) {
    function reeid_strip_wc_attrs_from_description_tab($content)
    {
        // HARD GUARD — products only (do NOT rely on is_product())
        global $post;
        if ( ! $post || $post->post_type !== 'product' ) {
            return $content;
        }

        // Only touch main query loop
        if ( ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        // Remove standard Woo attributes table
        $content = preg_replace(
            '#<table[^>]*\bclass=["\'][^"\']*(woocommerce-product-attributes|shop_attributes)[^"\']*["\'][^>]*>[\s\S]*?</table>#i',
            '',
            (string) $content
        );

        // Remove any wrappers some themes use around that table
        $content = preg_replace(
            '#<(div|section)[^>]*\bclass=["\'][^"\']*(woocommerce-product-attributes|shop_attributes)[^"\']*["\'][^>]*>[\s\S]*?</\1>#i',
            '',
            (string) $content
        );

        return $content;
    }

    // Priority 999 to run AFTER any theme/plugin that appends the attributes table
    add_filter('the_content', 'reeid_strip_wc_attrs_from_description_tab', 999);
}


/**
 * Force WooCommerce long description to use REEID packet on the frontend
 * (non-source languages only). Works even if the theme uses product getters.
 */
add_filter('woocommerce_product_get_description', function( $desc, $product ) {
    if ( is_admin() || wp_doing_ajax() || wp_is_json_request() ) {
        return $desc;
    }
    if ( ! $product instanceof WC_Product ) {
        return $desc;
    }

    // Resolve default/source language
    $default = function_exists('reeid_s269_default_lang')
        ? strtolower( (string) reeid_s269_default_lang() )
        : strtolower( (string) get_option('reeid_translation_source_lang', 'en') );

    // Resolve current language: param > cookie > URL prefix (tolerant: "zh" -> "zh-CN")
    $lang = '';
    if ( isset($_GET['reeid_force_lang']) ) {
        $lang = sanitize_text_field( wp_unslash( $_GET['reeid_force_lang'] ) );
    }
    if ( $lang === '' && isset($_COOKIE['site_lang']) ) {
        $lang = sanitize_text_field( wp_unslash( $_COOKIE['site_lang'] ) );
    }
    if ( $lang === '' && isset($_SERVER['REQUEST_URI']) ) {
        $path  = trim( parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
        $seg   = $path !== '' ? strtolower( str_replace('_','-', explode('/', $path)[0] ) ) : '';
        if ( $seg !== '' ) {
            $supported = function_exists('reeid_s269_supported_langs') ? array_keys( (array) reeid_s269_supported_langs() ) : array();
            $supported = array_map( function($c){ return strtolower( str_replace('_','-',$c) ); }, $supported );
            if ( empty($supported) ) {
                if ( preg_match('/^[a-z]{2}(-[a-z]{2})?$/i', $seg) ) { $lang = $seg; }
            } else {
                if ( in_array($seg, $supported, true) ) {
                    $lang = $seg;
                } else {
                    foreach ($supported as $code) {
                        if ( strpos($code, $seg.'-') === 0 || $seg === substr($code, 0, 2) ) { $lang = $code; break; }
                    }
                }
            }
        }
    }
    $lang = strtolower( (string) $lang );

    // Do not override for source language or when no lang resolved
    if ( $lang === '' || $lang === $default ) {
        return $desc;
    }

    // Use REEID packet if present
    $packet = get_post_meta( (int) $product->get_id(), "_reeid_wc_tr_{$lang}", true );
    if ( is_array($packet) && ! empty($packet['content']) ) {
        return (string) $packet['content'];
    }

    return $desc;
}, 9, 2);
/**
 * REEID: Elementor long description swap (frontend, single product, non-source langs)
 * Moves the working MU logic into the plugin so Elementor's "Post Content" widget
 * renders the translated long description from our REEID packet.
 */
if ( ! function_exists( 'reeid_resolve_lang_from_request' ) ) {
    function reeid_resolve_lang_from_request(): string {
        // 1) URL param
        if ( isset($_GET['reeid_force_lang']) ) {
            $l = sanitize_text_field( wp_unslash( $_GET['reeid_force_lang'] ) );
            if ( $l !== '' ) return strtolower( str_replace('_','-',$l) );
        }
        // 2) Cookie
        if ( isset($_COOKIE['site_lang']) ) {
            $l = sanitize_text_field( wp_unslash( $_COOKIE['site_lang'] ) );
            if ( $l !== '' ) return strtolower( str_replace('_','-',$l) );
        }
        // 3) URL prefix (/xx/ or /xx-yy/)
        $uri   = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $seg   = '';
        if ( $uri !== '' ) {
            $path  = trim( parse_url( $uri, PHP_URL_PATH ), '/' );
            $parts = $path !== '' ? explode( '/', $path ) : array();
            $seg   = isset($parts[0]) ? strtolower( str_replace('_','-', $parts[0] ) ) : '';
        }
        if ( $seg === '' ) return '';

        // Map against supported languages; allow tolerant prefix match (e.g., "zh" -> "zh-CN")
        $supported = function_exists('reeid_s269_supported_langs') ? array_keys( (array) reeid_s269_supported_langs() ) : array();
        $supported = array_map( function($c){ return strtolower( str_replace('_','-',$c) ); }, $supported );

        if ( empty($supported) ) {
            return preg_match('/^[a-z]{2}(-[a-z]{2})?$/i', $seg) ? $seg : '';
        }
        if ( in_array( $seg, $supported, true ) ) {
            return $seg;
        }
        foreach ( $supported as $code ) {
            if ( strpos( $code, $seg.'-' ) === 0 || $seg === substr( $code, 0, 2 ) ) {
                return $code;
            }
        }
        return '';
    }
}

// Elementor: translate ONLY the long-description widget content on single product pages,
// preserve attributes markup/shortcodes if they are inside that same widget.
add_action('elementor/init', function () {

    add_filter('elementor/widget/render_content', function( $content, $widget ) {
        if ( is_admin() || wp_doing_ajax() || wp_is_json_request() ) {
            return $content;
        }

        // Single product only
        $is_product = function_exists('is_product') ? is_product() : is_singular('product');
        if ( ! $is_product ) {
            return $content;
        }
        global $post;
        if ( ! $post || $post->post_type !== 'product' ) {
            return $content;
        }

        // Detect likely long-description widgets
        $name = method_exists( $widget, 'get_name' ) ? strtolower( (string) $widget->get_name() ) : '';
        $is_known   = in_array( $name, array('post-content','woocommerce-product-content','theme-post-content'), true );
        $is_general = ( strpos($name,'content') !== false || strpos($name,'description') !== false );

        if ( ! $is_known && ! $is_general ) {
            return $content; // not a description-like widget
        }

        // Decide if this widget's content looks like the long description:
        // - contains Woo description panel classes, or
        // - contains a good chunk of the base post_content text
        $looks_like_longdesc = false;
        if ( preg_match('/woocommerce-Tabs-panel--description|product-description|woocommerce-product-details__short-description/i', $content) ) {
            $looks_like_longdesc = true;
        } else {
            $base_raw   = (string) $post->post_content;
            $base_plain = trim( wp_strip_all_tags( $base_raw ) );
            $cont_plain = trim( wp_strip_all_tags( $content ) );
            if ( $base_plain !== '' ) {
                // If 20+ chars of base text appear in this widget, assume it's the long description
                $needle = mb_substr( $base_plain, 0, 120 ); // take a chunk
                if ( $needle !== '' && mb_stripos( $cont_plain, $needle ) !== false ) {
                    $looks_like_longdesc = true;
                }
            }
        }

        if ( ! $looks_like_longdesc ) {
            return $content; // do not touch unrelated content widgets
        }

        // Default/source language
        $default = function_exists('reeid_s269_default_lang')
            ? strtolower( (string) reeid_s269_default_lang() )
            : strtolower( (string) get_option('reeid_translation_source_lang', 'en') );

        // Resolve lang (param > cookie > URL prefix)
        $lang = function_exists('reeid_resolve_lang_from_request') ? reeid_resolve_lang_from_request() : '';
        if ( $lang === '' || $lang === $default ) {
            return $content; // do not override source language
        }

        // Look up translated long description
        $packet = get_post_meta( (int) $post->ID, "_reeid_wc_tr_{$lang}", true );
        if ( ! is_array($packet) || empty($packet['content']) ) {
            return $content;
        }

        $translated = (string) $packet['content'];

        // --- Preserve Woo attributes markup / shortcodes found in this widget (if any) ---
        $preserve = '';

        // 1) Attributes table HTML
        if ( preg_match( '/<table[^>]*class="[^"]*woocommerce-product-attributes[^"]*"[^>]*>.*?<\/table>/is', $content, $m ) ) {
            $preserve .= "\n" . $m[0];
        }

        // 2) Attributes shortcode variants
        if ( preg_match_all( '/\[(product_)?attributes[^\]]*\]/i', $content, $ms ) ) {
            $preserve .= "\n" . implode( "\n", array_unique( $ms[0] ) );
        }

        if ( $preserve !== '' ) {
            $translated .= "\n" . $preserve;
        }

        return $translated;

    }, 9999, 2);
});



// Frontend swap for long description on single product pages (no attributes here)
if (false) { // disabled: content-swap on template_redirect (we now use the Woo tabs callback instead)
    add_action('template_redirect', function () {
        if ( is_admin() || wp_doing_ajax() || wp_is_json_request() ) { return; }
        $is_product = function_exists('is_product') ? is_product() : is_singular('product');
        if ( ! $is_product ) { return; }
        global $post; if ( ! $post || $post->post_type !== 'product' ) { return; }
    
        $default = function_exists('reeid_s269_default_lang')
            ? strtolower( (string) reeid_s269_default_lang() )
            : strtolower( (string) get_option('reeid_translation_source_lang', 'en') );
    
        $lang = function_exists('reeid_resolve_lang_from_request') ? reeid_resolve_lang_from_request() : '';
        if ( $lang === '' || $lang === $default ) { return; }
    
        $packet = get_post_meta( (int) $post->ID, "_reeid_wc_tr_{$lang}", true );
        if ( is_array($packet) && ! empty($packet['content']) ) {
            $post->post_content = (string) $packet['content'];
        }
    }, 1);
    } 
    

// Track if Woo's "Additional information" (attributes) section was rendered
$GLOBALS['reeid_attrs_rendered'] = false;
add_action('woocommerce_product_additional_information', function( $product ) {
    $GLOBALS['reeid_attrs_rendered'] = true;
}, 1); // runs when Woo prints the official attributes table



/**
 * Resolve current language (param > cookie > URL prefix).
 * Keep if you already have the same helper.
 */
if ( ! function_exists('reeid_resolve_lang_from_request') ) {
    function reeid_resolve_lang_from_request(): string {
        if ( isset($_GET['reeid_force_lang']) ) {
            $l = sanitize_text_field( wp_unslash($_GET['reeid_force_lang']) );
            if ($l !== '') return strtolower(str_replace('_','-',$l));
        }
        if ( isset($_COOKIE['site_lang']) ) {
            $l = sanitize_text_field( wp_unslash($_COOKIE['site_lang']) );
            if ($l !== '') return strtolower(str_replace('_','-',$l));
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $seg = '';
        if ($uri !== '') {
            $path  = trim(parse_url($uri, PHP_URL_PATH), '/');
            $parts = $path !== '' ? explode('/', $path) : [];
            $seg   = isset($parts[0]) ? strtolower(str_replace('_','-',$parts[0])) : '';
        }
        if ($seg === '') return '';
        $supported = function_exists('reeid_s269_supported_langs') ? array_keys((array)reeid_s269_supported_langs()) : [];
        $supported = array_map(fn($c)=>strtolower(str_replace('_','-',$c)), $supported);
        if (empty($supported)) {
            return preg_match('/^[a-z]{2}(-[a-z]{2})?$/i', $seg) ? $seg : '';
        }
        if (in_array($seg, $supported, true)) return $seg;
        foreach ($supported as $code) {
            if (strpos($code, $seg.'-') === 0 || $seg === substr($code,0,2)) return $code;
        }
        return '';
    }
}

/**
 * WooCommerce product tabs:
 *  - Description tab: ONLY long description (already translation-aware via existing filters).
 *  - Additional information tab: ONLY attributes table.
 *
 * We take full control of the callbacks so attributes do not leak into Description.
 */
if (! function_exists('reeid_wc_tab_description')) {
    function reeid_wc_tab_description()
    {
        global $product;

        if (! $product instanceof \WC_Product) {
            // Fallback to default behaviour if something is odd
            the_content();
            return;
        }

        // Use WC API so existing REEID filters on product description still apply:
        // - reeid_wc inline packets
        // - Elementor / Gutenberg swap, etc.
        $content = $product->get_description();

        /**
         * Last-resort cleanup: if some theme / plugin injected attributes markup
         * directly into the description HTML, strip typical attribute tables.
         * This is defensive and should normally be a no-op.
         */
        $content = preg_replace(
            '#<table[^>]*\bclass=["\'][^"\']*(?:woocommerce-product-attributes|shop_attributes)[^"\']*["\'][^>]*>[\s\S]*?</table>#i',
            '',
            (string) $content
        );

        echo wp_kses_post($content);
    }
}

if (! function_exists('reeid_wc_tab_additional_information')) {
    function reeid_wc_tab_additional_information()
    {
        global $product;

        if (! $product instanceof \WC_Product) {
            // Use Woo default template if we for some reason lost the WC_Product
            wc_get_template('single-product/tabs/additional-information.php');
            return;
        }

        // This is the ONLY place where attributes table is rendered.
        wc_display_product_attributes($product);
    }
}





// PROBE: prove we’re wrapping final HTML on product pages.
add_action('template_redirect', function () {
    if (is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax()) || (function_exists('wp_is_json_request') && wp_is_json_request())) return;

    // IMPORTANT: use is_singular('product') here; is_product() can be false too early on some stacks.
    if (!is_singular('product')) return;

    if (function_exists('error_log')) error_log('[REEID-PROBE] starting output buffer on single-product');

    ob_start(function ($html) {
        // Log what we’re seeing
        $has_table = preg_match('#<table[^>]+(woocommerce-product-attributes|shop_attributes)#i', $html) ? 'Y' : 'N';
        $has_panel = preg_match('#id=(["\'])tab-additional_information\1|woocommerce-Tabs-panel--additional_information#i', $html) ? 'Y' : 'N';
        if (function_exists('error_log')) error_log("[REEID-PROBE] seen: table={$has_table} panel={$has_panel} size=" . strlen($html));

        return $html; // no changes, just proof
    });
}, 0);



/* ============================================================================
 * SECTION 31: Legacy wiring (frontend) — loads MU equivalents from /legacy
 * - Keeps Woo SEO bridge intact; hreflang-force prints only if bridge didn’t.
 * - Avoids duplicate title filters by preferring title-force only.
 * ==========================================================================*/
add_action('plugins_loaded', function () {
    if (is_admin()) return;

    $legacy = __DIR__ . '/legacy/';

    // 1) UTF-8 product router (query var fixer) — must load first
    if (file_exists($legacy.'reeid-utf8-router.php')) {
        include_once $legacy.'reeid-utf8-router.php';
    }

    // 2) Product title localization (choose ONE)
    if (!function_exists('reeid_product_title_for_lang') && file_exists($legacy.'reeid-title-force.php')) {
        include_once $legacy.'reeid-title-force.php';
    }
    // DO NOT load title-local when title-force is present to avoid function collisions.
    // if (!function_exists('reeid_product_title_for_lang') && file_exists($legacy.'reeid-title-local.php')) {
    //     include_once $legacy.'reeid-title-local.php';
    // }

    // 3) Hreflang late injector — prints only if bridge didn’t (self-guarded)
    if (file_exists($legacy.'reeid-hreflang-force.php')) {
        include_once $legacy.'reeid-hreflang-force.php';
    }
}, 1);
/* ============================================================================
 * SECTION 32 : HREFLANG — Canonical printer shim for Woo products only
 * - Leaves pages/posts to seo-sync
 * - Does NOT modify the bridge file; only swaps the printer on product requests
 * - Languages source = reeid_get_enabled_languages() (fallback: ['en'])
 * ==========================================================================*/
if (!function_exists('reeid_hreflang_print_canonical')) {
    function reeid_hreflang_print_canonical() {
        if (!is_singular('product')) return;

        // 1) Language set = enabled languages (no hardcoding)
        $langs = function_exists('reeid_get_enabled_languages')
            ? (array) reeid_get_enabled_languages()
            : array('en');

        if (empty($langs)) $langs = array('en');

        // 2) Ensure default is included and first
        $default = get_option('reeid_default_lang', 'en');
        if (!in_array($default, $langs, true)) {
            array_unshift($langs, $default);
        }
        // Dedup while preserving order
        $seen = array();
        $langs = array_values(array_filter($langs, function($c) use (&$seen){
            $k = strtolower((string)$c);
            if (isset($seen[$k])) return false;
            return $seen[$k] = true;
        }));

        // 3) Render via bridge renderer (uses localized slugs)
        if (function_exists('reeid_hreflang_render')) {
            $snippet = reeid_hreflang_render(get_queried_object_id(), $langs, $default);
           if ( $snippet ) {
    // Optional marker for diagnostics
    echo "<!-- REEID-HREFLANG-SHIM -->\n";

    // Allow only <link> tags and the attributes we expect for hreflang output.
    // Adjust attributes if your snippet legitimately includes more.
    $allowed = [
        'link' => [
            'rel'      => true,
            'hreflang' => true,
            'href'     => true,
            'title'    => true,
            'type'     => true,
        ],
    ];

    echo wp_kses( $snippet, $allowed );

    $GLOBALS['reeid_hreflang_already_echoed'] = true;
}

        }
    }

    // Swap printers only on product requests; pages/posts untouched
    add_action('wp', function () {
        if (!is_singular('product')) return;
        // Unhook default bridge printer (priority 90)
        remove_action('wp_head', 'reeid_hreflang_print', 90);
        // Hook our canonical printer late enough to win de-duplication
        add_action('wp_head', 'reeid_hreflang_print_canonical', 100);
    }, 0);
}

/* REEID_HREFLANG_DEDUPE: keep exactly one hreflang cluster on products */
if (!function_exists('reeid_hreflang_dedupe_buffer')) {
    function reeid_hreflang_dedupe_buffer($html) {
        if (stripos($html, '</head>') === false) return $html;
        $head_start = stripos($html, '<head');
        $head_end   = stripos($html, '</head>', $head_start);
        if ($head_start === false || $head_end === false) return $html;

        $head_len = $head_end + 7 - $head_start; // include </head>
        $head = substr($html, $head_start, $head_len);

        $pattern = '/<link[^>]+rel=[\'"]alternate[\'"][^>]+hreflang=[\'"]([^\'"]+)[\'"][^>]*>\s*/i';
        if (!preg_match_all($pattern, $head, $m)) return $html;

        // Keep the LAST occurrence of each code
        $map = [];
        for ($i = 0; $i < count($m[0]); $i++) {
            $code = strtolower($m[1][$i]);
            $map[$code] = $m[0][$i];
        }

        // Build final cluster: all non x-default, then x-default last if present
        $final = [];
        foreach ($map as $code => $tag) {
            if ($code !== 'x-default') $final[$code] = $tag;
        }
        if (isset($map['x-default'])) $final['x-default'] = $map['x-default'];

        // Strip all hreflang tags from head and re-insert the de-duped block just before </head>
        $head_clean = preg_replace($pattern, '', $head);
        $block = implode("", $final);
        $head_fixed = preg_replace('/<\/head>/i', $block . '</head>', $head_clean, 1);

        // Reassemble document
        return substr($html, 0, $head_start) . $head_fixed . substr($html, $head_start + $head_len);
    }

    // Run very late so we see all emitters
    add_action('template_redirect', function () {
        if (is_singular('product')) ob_start('reeid_hreflang_dedupe_buffer');
    }, 999);
}

/* ========================================================================
 * SECTION 33: Elementor — Schema-Safe Text Walkers + Safe Commit (BYOK)
 * ===================================================================== */

if (!function_exists('rt_el_root_ref')) {
    function rt_el_root_ref($decoded, &$is_document): array {
        $is_document = is_array($decoded)
            && array_key_exists('elements', $decoded)
            && array_key_exists('version', $decoded);

        if ($is_document) {
            return $decoded;
        }

        if (is_array($decoded)) {
            return ['elements' => $decoded, '__rt_doc_like' => false];
        }

        return ['elements' => [], '__rt_doc_like' => false];
    }
}

if (!function_exists('rt_el_is_text_key')) {
    function rt_el_is_text_key(string $key): bool {
        static $keys = [
            'title','text','editor','content','button_text','label','description',
            'placeholder','headline','sub_title','subtitle','caption','html','price',
            'before_text','after_text','list_title','list_text'
        ];
        return in_array($key, $keys, true);
    }
}

if (!function_exists('rt_el_walk_collect')) {
    function rt_el_walk_collect(array $nodes, array $path, array &$map): void {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $id = isset($node['id']) ? (string) $node['id'] : 'node';
            $p  = array_merge($path, [$id]);

            if (isset($node['settings']) && is_array($node['settings'])) {
                foreach ($node['settings'] as $k => $v) {
                    if (is_string($v) && rt_el_is_text_key((string) $k)) {
                        $map[implode('/', array_merge($p, ['settings', (string) $k]))] = $v;
                    }
                }
            }

            foreach (['elements', 'children', '_children'] as $kids) {
                if (isset($node[$kids]) && is_array($node[$kids])) {
                    rt_el_walk_collect($node[$kids], array_merge($p, [$kids]), $map);
                }
            }
        }
    }
}

if (!function_exists('rt_el_walk_replace')) {
    function rt_el_walk_replace(array &$nodes, array $path, array $map): void {
        foreach ($nodes as &$node) {
            if (!is_array($node)) {
                continue;
            }

            $id = isset($node['id']) ? (string) $node['id'] : 'node';
            $p  = array_merge($path, [$id]);

            if (isset($node['settings']) && is_array($node['settings'])) {
                foreach ($node['settings'] as $k => $v) {
                    if (!is_string($v) || !rt_el_is_text_key((string) $k)) {
                        continue;
                    }

                    $key = implode('/', array_merge($p, ['settings', (string) $k]));

                    if (array_key_exists($key, $map)) {
                        $val = (string) $map[$key];

                        // Normalize JSON-escaped closing tags BEFORE storing
                        if ($val !== '') {
                            $val = str_replace('<\/', '</', $val);
                        }

                        $node['settings'][$k] = $val;
                    }
                }
            }

            foreach (['elements', 'children', '_children'] as $kids) {
                if (isset($node[$kids]) && is_array($node[$kids])) {
                    rt_el_walk_replace($node[$kids], array_merge($p, [$kids]), $map);
                }
            }
        }

        unset($node);
    }
}

if (!function_exists('rt_el_assemble_with_map')) {
    function rt_el_assemble_with_map(string $json, array $translated_map): string {
        $decoded = json_decode($json, true);
        $is_document = false;

        $root  = rt_el_root_ref($decoded, $is_document);
        $nodes =& $root['elements'];

        if (!is_array($nodes)) {
            $nodes = [];
        }

        rt_el_walk_replace($nodes, [], $translated_map);

        if ($is_document && isset($root['version'])) {
            unset($root['__rt_doc_like']);
            return wp_json_encode($root, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return wp_json_encode($root['elements'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('rt_el_schema_guard_diff')) {
    function rt_el_schema_guard_diff(string $orig_json, string $new_json): bool {
        $o = json_decode($orig_json, true);
        $n = json_decode($new_json, true);

        if (!is_array($o) || !is_array($n)) {
            return false;
        }

        $flatten = function ($arr, $prefix = '') use (&$flatten) {
            $out = [];
            foreach ($arr as $k => $v) {
                $path = $prefix === '' ? (string) $k : $prefix . '/' . $k;
                if (is_array($v)) {
                    $out += $flatten($v, $path);
                } else {
                    $out[$path] = $v;
                }
            }
            return $out;
        };

        $fo = $flatten($o);
        $fn = $flatten($n);

        foreach ($fn as $k => $v) {
            if (!array_key_exists($k, $fo) && strpos($k, '/settings/') === false) {
                return false;
            }
            if (array_key_exists($k, $fo) && $fo[$k] !== $v && strpos($k, '/settings/') === false) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('reeid_elementor_commit_post_safe')) {
    function reeid_elementor_commit_post_safe(int $post_id, string $elementor_json): bool {

error_log('REEID WRITE commit_post_safe');

        // FINAL SAFETY: normalize any escaped closing tags before storing
        // This guarantees clean Elementor DB even if a pipeline bypassed walkers
        $elementor_json = str_replace('<\/', '</', $elementor_json);
        reeid_debug_log('FINAL_ELEMENTOR_WRITE', [
    'file'  => __FILE__,
    'line'  => __LINE__,
    'post'  => $post_id,
    'has_escaped' => (strpos($elementor_json, '<\\/') !== false),
    'sample' => substr($elementor_json, 0, 120),
]);

        update_post_meta($post_id, '_elementor_data', $elementor_json);
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');

        if (!metadata_exists('post', $post_id, '_elementor_page_settings')) {
            update_post_meta($post_id, '_elementor_page_settings', []);
        }

        if (class_exists('\Elementor\Plugin')) {
            try {
                $doc = \Elementor\Plugin::$instance->documents->get($post_id);
                if ($doc) {
                    $css = new \Elementor\Core\Files\CSS\Post($post_id);
                    $css->update();
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return true;
    }
}



/* ========================================================================
 * SECTION 34: Elementor — Text-only translate & commit (uses walkers)
 * - Collects ONLY text controls
 * - Translates each value (BYOK)
 * - Reassembles JSON without touching schema
 * - Saves safely and updates CSS
 * ===================================================================== */
if (!function_exists('reeid_elementor_walk_translate_and_commit')) {
    /**
     * @param int    $post_id
     * @param string $source two-letter/locale your pipeline already uses
     * @param string $target two-letter/locale
     * @param string $tone   optional tone/style your pipeline already supports
     * @param string $extra  optional extra instructions (custom prompt additive)
     * @return array { ok:bool, count:int, msg:string }
     */
    function reeid_elementor_walk_translate_and_commit(int $post_id, string $source, string $target, string $tone = '', string $extra = ''): array
    {
        $orig_json = (string) get_post_meta($post_id, '_elementor_data', true);
        $decoded   = json_decode($orig_json, true);
        if (!is_array($decoded)) {
            return ['ok'=>false, 'count'=>0, 'msg'=>'no_elementor_json'];
        }

        // Normalize document root & collect text
        $is_doc   = false;
        $root     = rt_el_root_ref($decoded, $is_doc);
        $nodes    = isset($root['elements']) && is_array($root['elements']) ? $root['elements'] : [];
        $flat_map = [];
        rt_el_walk_collect($nodes, [], $flat_map);

        if (empty($flat_map)) {
            // Still save as-is to keep CSS/doc stable
            reeid_elementor_commit_post_safe($post_id, $orig_json);
            return ['ok'=>true, 'count'=>0, 'msg'=>'no_text_controls'];
        }

        // Deduplicate identical strings to reduce API calls
        $uniq_in   = array_values(array_unique(array_values($flat_map)));
        $uniq_out  = [];

        // Translate one-by-one via existing BYOK translator
        foreach ($uniq_in as $str) {
            // Prefer your existing helper if present (keeps all repo safeguards)
            if (function_exists('reeid_translate_html_with_openai')) {
                $tr = (string) reeid_translate_html_with_openai($str, $source, $target, $tone, $extra);
            } else {
                // Fallback: identity (safety). You can wire another engine here later.
                $tr = $str;
            }
            $uniq_out[$str] = $tr;
        }

        // Rebuild translated map preserving keys
        $translated_map = [];
        foreach ($flat_map as $k => $v) {
            $translated_map[$k] = $uniq_out[$v];
        }

        // Assemble and guard
        $new_json = rt_el_assemble_with_map($orig_json, $translated_map);
        if (!rt_el_schema_guard_diff($orig_json, $new_json)) {
            return ['ok'=>false, 'count'=>count($flat_map), 'msg'=>'schema_guard_block'];
        }

        // Commit + CSS
        reeid_elementor_commit_post_safe($post_id, $new_json);

        return ['ok'=>true, 'count'=>count($flat_map), 'msg'=>'saved'];
    }
}

/* ========================================================================
 * SECTION 35: Elementor — Schema-Safe Text Walkers v2 (append-only, rollback)
 * ===================================================================== */
if (!function_exists('rt2_el_is_text_key')) {
    function rt2_el_is_text_key(string $key): bool {
        $k = strtolower((string)$key);
        if ($k === '' || $k[0] === '_') return false;
        if (preg_match('~^(url|link|image|background|bg_|icon|html_tag|alignment|align|size|width|height|color|colors|typography|font|letter|line_height|border|padding|margin|box_shadow|object_|z_index|position|hover_|motion_fx|transition|duration)$~i', $k)) {
            return false;
        }
        return true;
    }
}
if (!function_exists('rt2_el_walk_collect')) {
    function rt2_el_walk_collect(array $nodes, array $path, array &$map): void {
        foreach ($nodes as $node) {
            if (!is_array($node)) continue;
            $id = isset($node['id']) ? (string)$node['id'] : '';
            $p  = array_merge($path, [$id !== '' ? $id : 'node']);
            if (isset($node['settings']) && is_array($node['settings'])) {
                foreach ($node['settings'] as $k => $v) {
                    if (!is_string($v) || !rt2_el_is_text_key((string)$k)) continue;
                    $vv = trim($v);
                    if ($vv === '' || preg_match('~^(#?[0-9a-f]{3,8}|var\(--|https?://|/wp-content/|[0-9]+(px|em|rem|%)$)~i', $vv)) continue;
                    $map[implode('/', array_merge($p, ['settings', (string)$k]))] = $v;
                }
            }
            foreach (['elements','children','_children'] as $kids) {
                if (isset($node[$kids]) && is_array($node[$kids])) {
                    rt2_el_walk_collect($node[$kids], array_merge($p, [$kids]), $map);
                }
            }
        }
    }
}
if (!function_exists('rt2_el_walk_replace')) {
    function rt2_el_walk_replace(array &$nodes, array $path, array $map): void {
        foreach ($nodes as &$node) {
            if (!is_array($node)) continue;
            $id = isset($node['id']) ? (string)$node['id'] : 'node';
            $p  = array_merge($path, [$id]);
            if (isset($node['settings']) && is_array($node['settings'])) {
                foreach ($node['settings'] as $k => $v) {
                    if (!is_string($v) || !rt2_el_is_text_key((string)$k)) continue;
                    $key = implode('/', array_merge($p, ['settings', (string)$k]));
                    if (array_key_exists($key, $map)) {
    $v = (string) $map[$key];
    // normalize JSON-escaped closing tags BEFORE storing
    $v = str_replace('<\/', '</', $v);
    $node['settings'][$k] = $v;
}

                }
            }
            foreach (['elements','children','_children'] as $kids) {
                if (isset($node[$kids]) && is_array($node[$kids])) {
                    rt2_el_walk_replace($node[$kids], array_merge($p, [$kids]), $map);
                }
            }
        }
        unset($node);
    }
}
if (!function_exists('rt2_el_root_ref')) {
    function rt2_el_root_ref($decoded, &$is_document): array {
        $is_document = is_array($decoded) && array_key_exists('elements', $decoded) && array_key_exists('version', $decoded);
        if ($is_document) return $decoded;
        if (is_array($decoded)) return ['elements'=>$decoded, '__rt_doc_like'=>false];
        return ['elements'=>[], '__rt_doc_like'=>false];
    }
}
if (!function_exists('rt2_el_assemble_with_map')) {
    function rt2_el_assemble_with_map(string $json, array $translated_map): string {
        $decoded = json_decode($json, true);
        $is_document = false;
        $root = rt2_el_root_ref($decoded, $is_document);
        $nodes =& $root['elements'];
        if (!is_array($nodes)) $nodes = [];
        rt2_el_walk_replace($nodes, [], $translated_map);
        if ($is_document && isset($root['version'])) {
            unset($root['__rt_doc_like']);
            return wp_json_encode($root, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return wp_json_encode($root['elements'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    }
}
if (!function_exists('rt2_el_schema_guard_diff')) {
    function rt2_el_schema_guard_diff(string $orig_json, string $new_json): bool {
        $o = json_decode($orig_json, true); $n = json_decode($new_json, true);
        if (!is_array($o) || !is_array($n)) return false;
        $flatten = function($arr, $prefix='') use (&$flatten) {
            $out = [];
            foreach ($arr as $k=>$v) {
                $path = $prefix === '' ? (string)$k : $prefix.'/'.$k;
                if (is_array($v)) $out += $flatten($v, $path); else $out[$path] = $v;
            }
            return $out;
        };
        $fo = $flatten($o); $fn = $flatten($n);
        foreach ($fn as $k=>$v) {
            if (!array_key_exists($k, $fo) && strpos($k, '/settings/') === false) return false;
            if (array_key_exists($k, $fo) && $fo[$k] !== $v && strpos($k, '/settings/') === false) return false;
        }
        return true;
    }
}
if (!function_exists('reeid_elementor_walk_translate_and_commit_v2')) {
    function reeid_elementor_walk_translate_and_commit_v2(int $post_id, string $source, string $target, string $tone = '', string $extra = ''): array
    {
        $orig_json = (string) get_post_meta($post_id, '_elementor_data', true);
        $decoded   = json_decode($orig_json, true);
        if (!is_array($decoded)) return ['ok'=>false, 'count'=>0, 'msg'=>'no_elementor_json'];

        $is_doc   = false;
        $root     = rt2_el_root_ref($decoded, $is_doc);
        $nodes    = isset($root['elements']) && is_array($root['elements']) ? $root['elements'] : [];
        $flat_map = [];
        rt2_el_walk_collect($nodes, [], $flat_map);

        $orig_json_before = $orig_json;

        if (empty($flat_map)) {
            reeid_elementor_commit_post_safe($post_id, $orig_json);
            return ['ok'=>true, 'count'=>0, 'msg'=>'no_text_controls'];
        }

        $uniq_in  = array_values(array_unique(array_values($flat_map)));
        $uniq_out = [];
        foreach ($uniq_in as $str) {
            if (function_exists('reeid_translate_html_with_openai')) {
                $tr = (string) reeid_translate_html_with_openai($str, $source, $target, $tone, $extra);
            } else {
                $tr = $str;
            }
            $trim = trim((string)$tr);
            $looks_json = ($trim !== '' && ($trim[0] === '{' || $trim[0] === '['));
            if ($trim === '' || $looks_json) $tr = $str;
            $uniq_out[$str] = $tr;
        }
        $translated_map = [];
        foreach ($flat_map as $k => $v) $translated_map[$k] = $uniq_out[$v];

        $new_json = rt2_el_assemble_with_map($orig_json, $translated_map);
        if (!rt2_el_schema_guard_diff($orig_json, $new_json)) {
            return ['ok'=>false, 'count'=>count($flat_map), 'msg'=>'schema_guard_block'];
        }

        // Commit
        reeid_elementor_commit_post_safe($post_id, $new_json);

        // Render guard; rollback if blank
        try {
            $html = class_exists('\Elementor\Plugin')
                ? \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($post_id, false)
                : '';
        } catch (\Throwable $e) { $html = ''; }
        $ok_render = (is_string($html) && strlen($html) > 0 && strpos($html, 'class="elementor') !== false);
        if (!$ok_render) {
            reeid_elementor_commit_post_safe($post_id, $orig_json_before);
            return ['ok'=>false, 'count'=>count($flat_map), 'msg'=>'render_guard_rollback'];
        }
        return ['ok'=>true, 'count'=>count($flat_map), 'msg'=>'saved'];
    }
}
/* ========================================================================
 * SECTION 36: Elementor — Schema-safe translate v3 (guards + rollback)
 * ===================================================================== */
if (!function_exists('reeid_elementor_walk_translate_and_commit_v3')) {
    function reeid_elementor_walk_translate_and_commit_v3(int $post_id, string $source, string $target, string $tone = '', string $extra = ''): array
    {
        $orig_json = (string) get_post_meta($post_id, '_elementor_data', true);
        $decoded   = json_decode($orig_json, true);
        if (!is_array($decoded)) return ['ok'=>false,'count'=>0,'msg'=>'no_elementor_json'];

        // Root resolver (v2, v1, minimal)
        $is_doc=false;
        if (function_exists('rt2_el_root_ref'))      { $root = rt2_el_root_ref($decoded,$is_doc); }
        elseif (function_exists('rt_el_root_ref'))   { $root = rt_el_root_ref($decoded,$is_doc); }
        else                                         { $root = is_array($decoded) ? ['elements'=>$decoded] : ['elements'=>[]]; }

        // Collector: prefer remote api.reeid.com walker if present, else local walkers
        $flat_map = [];
        if (function_exists('reeid_elementor_collect_text_map_via_api_then_local')) {
            $flat_map = reeid_elementor_collect_text_map_via_api_then_local($orig_json);
        } else {
            $nodes = isset($root['elements']) && is_array($root['elements']) ? $root['elements'] : [];
            if (function_exists('rt2_el_walk_collect'))      { rt2_el_walk_collect($nodes, [], $flat_map); }
            elseif (function_exists('rt_el_walk_collect'))   { rt_el_walk_collect($nodes, [], $flat_map); }
        }

        $orig_json_before = $orig_json;

        if (empty($flat_map)) {
            if (function_exists('reeid_elementor_commit_post_safe')) { reeid_elementor_commit_post_safe($post_id, $orig_json); }
            return ['ok'=>true,'count'=>0,'msg'=>'no_text_controls'];
        }

        // Dedup + translate (BYOK). Keep original if empty/JSON-like.
        $uniq_in  = array_values(array_unique(array_values($flat_map)));
        $uniq_out = [];
        foreach ($uniq_in as $str) {
            $tr = $str;
            if (function_exists('reeid_translate_html_with_openai')) {
                $try = (string) reeid_translate_html_with_openai($str, $source, $target, $tone, $extra);
                $trim = trim($try);
                $looks_json = ($trim !== '' && ($trim[0] === '{' || $trim[0] === '['));
                if ($trim !== '' && !$looks_json) $tr = $try;
            }
            $uniq_out[$str] = $tr;
        }
        $translated_map = [];
        foreach ($flat_map as $k=>$v) $translated_map[$k] = $uniq_out[$v];

        // Assemble (v2 or v1)
        if (function_exists('rt2_el_assemble_with_map'))      { $new_json = rt2_el_assemble_with_map($orig_json, $translated_map); }
        else                                                  { $new_json = rt_el_assemble_with_map($orig_json, $translated_map); }

        // Schema guard
        if (function_exists('rt2_el_schema_guard_diff'))      { if (!rt2_el_schema_guard_diff($orig_json, $new_json)) return ['ok'=>false,'count'=>count($flat_map),'msg'=>'schema_guard_block']; }
        elseif (function_exists('rt_el_schema_guard_diff'))   { if (!rt_el_schema_guard_diff($orig_json, $new_json))  return ['ok'=>false,'count'=>count($flat_map),'msg'=>'schema_guard_block']; }

        // Pre-commit: ensure translated JSON still has text
        $dec_new = json_decode($new_json, true);
        $is_doc_new=false;
        if (function_exists('rt2_el_root_ref'))      { $root_new = rt2_el_root_ref($dec_new,$is_doc_new); }
        elseif (function_exists('rt_el_root_ref'))   { $root_new = rt_el_root_ref($dec_new,$is_doc_new); }
        else                                         { $root_new = is_array($dec_new) ? ['elements'=>$dec_new] : ['elements'=>[]]; }
        $nodes_new = isset($root_new['elements']) && is_array($root_new['elements']) ? $root_new['elements'] : [];
        $flat_new = [];
        if (function_exists('rt2_el_walk_collect'))      { rt2_el_walk_collect($nodes_new, [], $flat_new); }
        elseif (function_exists('rt_el_walk_collect'))   { rt_el_walk_collect($nodes_new, [], $flat_new); }
        if (count($flat_new) === 0) return ['ok'=>false,'count'=>count($flat_map),'msg'=>'text_guard_block'];

        // Optional title/slug
        $current = get_post($post_id);
        if ($current) {
            $title_src = (string)$current->post_title;
            $translated_title = $title_src; $translated_slug='';
            if (function_exists('reeid_translate_via_openai_with_slug')) {
                $pack = (array) reeid_translate_via_openai_with_slug($title_src, "", $source, $target, $tone, $extra);
                if (!empty($pack['title'])) $translated_title = (string)$pack['title'];
                if (!empty($pack['slug']))  $translated_slug  = (string)$pack['slug'];
            } elseif (function_exists('reeid_translate_html_with_openai')) {
                $try = (string) reeid_translate_html_with_openai($title_src, $source, $target, $tone, $extra);
                if (trim($try) !== '') $translated_title = $try;
            }
            $upd = ['ID'=>$post_id];
            if (trim($translated_title) !== '') $upd['post_title'] = $translated_title;
            if (trim($translated_slug)  !== '') $upd['post_name']  = sanitize_title($translated_slug);
            if (count($upd) > 1) wp_update_post($upd);
            if (function_exists('reeid_maybe_update_slug_from_title')) {
                reeid_maybe_update_slug_from_title($post_id, $translated_title, $translated_slug ?? '');
            }
        }

        // Commit + CSS
        if (function_exists('reeid_elementor_commit_post_safe')) { reeid_elementor_commit_post_safe($post_id, $new_json); }
        else                                                    { update_post_meta($post_id, '_elementor_data', $new_json); }

        // Post-commit guard (wrapper + visible text)
        try { $html = class_exists('\Elementor\Plugin') ? \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($post_id, false) : ''; }
        catch (\Throwable $e) { $html = ''; }
        $has_wrapper = (is_string($html) && strpos($html, 'class="elementor') !== false);
        $visible = trim(strip_tags((string)$html));
        if (!$has_wrapper || strlen($visible) < 20) {
            if (function_exists('reeid_elementor_commit_post_safe')) { reeid_elementor_commit_post_safe($post_id, $orig_json_before); }
            else                                                    { update_post_meta($post_id, '_elementor_data', $orig_json_before); }
            return ['ok'=>false,'count'=>count($flat_map),'msg'=>'render_guard_rollback'];
        }

        return ['ok'=>true,'count'=>count($flat_map),'msg'=>'saved'];
    }
}

/* ========================================================================
 * SECTION 37: Elementor — Schema-safe translate v3b (array ctx + rollback)
 * ===================================================================== */
if (!function_exists('reeid_elementor_walk_translate_and_commit_v3b')) {
    function reeid_elementor_walk_translate_and_commit_v3b(int $post_id, string $source, string $target, string $tone = '', string $extra = ''): array
    {
        $orig_json = (string) get_post_meta($post_id, '_elementor_data', true);
        $decoded   = json_decode($orig_json, true);
        if (!is_array($decoded)) return ['ok'=>false,'count'=>0,'msg'=>'no_elementor_json'];

        // Root
        $is_doc=false;
        if (function_exists('rt2_el_root_ref'))      { $root = rt2_el_root_ref($decoded,$is_doc); }
        elseif (function_exists('rt_el_root_ref'))   { $root = rt_el_root_ref($decoded,$is_doc); }
        else                                         { $root = is_array($decoded) ? ['elements'=>$decoded] : ['elements'=>[]]; }

        // Collect text paths (prefer remote api walker when available)
        $flat_map = [];
        if (function_exists('reeid_elementor_collect_text_map_via_api_then_local')) {
            $flat_map = reeid_elementor_collect_text_map_via_api_then_local($orig_json);
        } else {
            $nodes = isset($root['elements']) && is_array($root['elements']) ? $root['elements'] : [];
            if (function_exists('rt2_el_walk_collect'))      rt2_el_walk_collect($nodes, [], $flat_map);
            elseif (function_exists('rt_el_walk_collect'))   rt_el_walk_collect($nodes, [], $flat_map);
        }

        $orig_json_before = $orig_json;

        if (empty($flat_map)) {
            if (function_exists('reeid_elementor_commit_post_safe')) { reeid_elementor_commit_post_safe($post_id, $orig_json); }
            return ['ok'=>true,'count'=>0,'msg'=>'no_text_controls'];
        }

        // Dedup + BYOK translate (keep original if empty/JSON-like)
        $uniq_in  = array_values(array_unique(array_values($flat_map)));
        $uniq_out = [];
        foreach ($uniq_in as $str) {
            $tr = $str;
            if (function_exists('reeid_translate_html_with_openai')) {
                $try  = (string) reeid_translate_html_with_openai($str, $source, $target, $tone, $extra);
                $trim = trim($try);
                $looks_json = ($trim !== '' && ($trim[0] === '{' || $trim[0] === '['));
                if ($trim !== '' && !$looks_json) $tr = $try;
            }
            $uniq_out[$str] = $tr;
        }
        $translated_map = [];
        foreach ($flat_map as $k=>$v) $translated_map[$k] = $uniq_out[$v];

        // Assemble
        if (function_exists('rt2_el_assemble_with_map'))      $new_json = rt2_el_assemble_with_map($orig_json, $translated_map);
        else                                                  $new_json = rt_el_assemble_with_map($orig_json, $translated_map);

        // Guard schema + ensure text remains
        if (function_exists('rt2_el_schema_guard_diff'))      { if (!rt2_el_schema_guard_diff($orig_json, $new_json)) return ['ok'=>false,'count'=>count($flat_map),'msg'=>'schema_guard_block']; }
        elseif (function_exists('rt_el_schema_guard_diff'))   { if (!rt_el_schema_guard_diff($orig_json, $new_json))  return ['ok'=>false,'count'=>count($flat_map),'msg'=>'schema_guard_block']; }

        $dec_new = json_decode($new_json, true);
        $is_doc_new=false;
        if (function_exists('rt2_el_root_ref'))      { $root_new = rt2_el_root_ref($dec_new,$is_doc_new); }
        elseif (function_exists('rt_el_root_ref'))   { $root_new = rt_el_root_ref($dec_new,$is_doc_new); }
        else                                         { $root_new = is_array($dec_new) ? ['elements'=>$dec_new] : ['elements'=>[]]; }
        $nodes_new = isset($root_new['elements']) && is_array($root_new['elements']) ? $root_new['elements'] : [];
        $flat_new  = [];
        if (function_exists('rt2_el_walk_collect'))      rt2_el_walk_collect($nodes_new, [], $flat_new);
        elseif (function_exists('rt_el_walk_collect'))   rt_el_walk_collect($nodes_new, [], $flat_new);
        if (count($flat_new) === 0) return ['ok'=>false,'count'=>count($flat_map),'msg'=>'text_guard_block'];

        // Title/slug (call your slug API with ARRAY ctx to avoid fatals)
        if ($p = get_post($post_id)) {
            $title_src = (string)$p->post_title;
            $translated_title = $title_src; $translated_slug = '';
            if (function_exists('reeid_translate_via_openai_with_slug')) {
                try {
                    $ctx = ['source'=>$source,'target'=>$target,'tone'=>$tone,'extra'=>$extra];
                    $pack = (array) reeid_translate_via_openai_with_slug($title_src, "", $ctx);
                    if (!empty($pack['title'])) $translated_title = (string)$pack['title'];
                    if (!empty($pack['slug']))  $translated_slug  = (string)$pack['slug'];
                } catch (\Throwable $e) {
                    // fall back to title-only
                    if (function_exists('reeid_translate_html_with_openai')) {
                        $try = (string) reeid_translate_html_with_openai($title_src, $source, $target, $tone, $extra);
                        if (trim($try) !== '') $translated_title = $try;
                    }
                }
            } elseif (function_exists('reeid_translate_html_with_openai')) {
                $try = (string) reeid_translate_html_with_openai($title_src, $source, $target, $tone, $extra);
                if (trim($try) !== '') $translated_title = $try;
            }
            $upd = ['ID'=>$post_id];
            if (trim($translated_title) !== '') $upd['post_title'] = $translated_title;
            if (trim($translated_slug)  !== '') $upd['post_name']  = sanitize_title($translated_slug);
            if (count($upd) > 1) wp_update_post($upd);
            if (function_exists('reeid_maybe_update_slug_from_title')) {
                reeid_maybe_update_slug_from_title($post_id, $translated_title, $translated_slug ?? '');
            }
        }

        // Commit + CSS
        if (function_exists('reeid_elementor_commit_post_safe')) { reeid_elementor_commit_post_safe($post_id, $new_json); }
        else                                                    { update_post_meta($post_id, '_elementor_data', $new_json); }

        // Post-commit render guard
        try { $html = class_exists('\Elementor\Plugin') ? \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($post_id, false) : ''; }
        catch (\Throwable $e) { $html = ''; }
        $has_wrapper = (is_string($html) && strpos($html, 'class="elementor') !== false);
        $visible = trim(strip_tags((string)$html));
        if (!$has_wrapper || strlen($visible) < 20) {
            if (function_exists('reeid_elementor_commit_post_safe')) { reeid_elementor_commit_post_safe($post_id, $orig_json_before); }
            else                                                    { update_post_meta($post_id, '_elementor_data', $orig_json_before); }
            return ['ok'=>false,'count'=>count($flat_map),'msg'=>'render_guard_rollback'];
        }

        return ['ok'=>true,'count'=>count($flat_map),'msg'=>'saved'];
    }
}

/* ========================================================================
 * SECTION 38: Elementor — Schema-safe translate v3c (+ built-in fallback)
 * - Primary: collect→dedup→BYOK translate→assemble→guards→commit
 * - Fallback: simple walker over heading/text-editor/button when collector empty
 *   or when translated text falls below threshold; still schema-safe + render-guarded
 * ===================================================================== */

/* --- Minimal helpers kept local to this section (guarded) --- */
if (!function_exists('reeid_el_get_json')) {
    function reeid_el_get_json(int $post_id) {
        $raw = (string) get_post_meta($post_id, '_elementor_data', true);
        $dec = json_decode($raw, true);
        if (is_array($dec)) return $dec;

        if (function_exists('is_serialized') && is_serialized($raw)) {
            $u = @unserialize($raw);
            if (is_array($u)) return $u;
        }
        // Minimal valid doc (object-root)
        return [
            'version'=>'0.4','title'=>'Recovered','type'=>'page',
            'elements'=>[[
                'id'=>'rt-sec','elType'=>'section','settings'=>[],
                'elements'=>[[
                    'id'=>'rt-col','elType'=>'column','settings'=>['_column_size'=>100],
                    'elements'=>[[
                        'id'=>'rt-head','elType'=>'widget','widgetType'=>'heading',
                        'settings'=>['title'=>'Recovered Elementor content'],'elements'=>[]
                    ]]
                ]]
            ]],
            'settings'=>[]
        ];
    }
}
if (!function_exists('reeid_el_save_json')) {
    function reeid_el_save_json(int $post_id, $tree): void {
        $json = wp_json_encode($tree, JSON_UNESCAPED_UNICODE);
        update_post_meta($post_id, '_elementor_data', $json);
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_template_type', 'wp-page');
        $ver = get_option('elementor_version'); if (!$ver && defined('ELEMENTOR_VERSION')) $ver = ELEMENTOR_VERSION;
        if ($ver) update_post_meta($post_id, '_elementor_version', $ver);
        $ps = get_post_meta($post_id, '_elementor_page_settings', true); if (!is_array($ps)) $ps=[];
        unset($ps['template'],$ps['layout'],$ps['page_layout'],$ps['stretched_section'],$ps['container_width']);
        update_post_meta($post_id, '_elementor_page_settings', $ps);
        if (class_exists('\Elementor\Plugin')) { try { (new \Elementor\Core\Files\CSS\Post($post_id))->update(); } catch (\Throwable $e) {} }
    }
}
if (!function_exists('reeid_el_render_ok')) {
    function reeid_el_render_ok(int $post_id): bool {
        if (!class_exists('\Elementor\Plugin')) return true;
        try {
            $html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($post_id,false);
            return (is_string($html) && strlen($html)>0 && strpos($html,'class="elementor')!==false);
        } catch (\Throwable $e) { return false; }
    }
}

/* --- Simple fallback walker: array-root and object-root safe --- */
if (!function_exists('reeid_el_simple_translate_and_commit')) {
    function reeid_el_simple_translate_and_commit(int $post_id, string $source='en', string $target='gu', string $tone='', string $extra='') : array {
        $tree = reeid_el_get_json($post_id);
        $is_array_root = (isset($tree[0]) && is_array($tree[0]));
        $sections = $is_array_root ? $tree : ($tree['elements'] ?? []);
        if (!is_array($sections)) $sections = [];

        $translate = function(string $s) use ($source,$target,$tone,$extra): string {
            if (function_exists('reeid_translate_html_with_openai')) {
                try { $out = (string) reeid_translate_html_with_openai($s,$source,$target,$tone,$extra); }
                catch (\Throwable $e) { $out = $s; }
                $t = trim($out);
                if ($t === '' || ($t[0] ?? '') === '{' || ($t[0] ?? '') === '[') return $s;
                return $out;
            }
            return $s.'['.$target.']';
        };

        $changed = 0;
        $walk = function (&$nodes) use (&$walk,&$translate,&$changed) {
            if (!is_array($nodes)) return;
            foreach ($nodes as &$node) {
                if (!is_array($node)) continue;
                if (($node['elType'] ?? '') === 'widget') {
                    $wt = $node['widgetType'] ?? '';
                    $st = $node['settings'] ?? [];
                    if ($wt==='heading'     && isset($st['title'])  && is_string($st['title']))  { $node['settings']['title']  = $translate($st['title']);  $changed++; }
                    if ($wt==='text-editor' && isset($st['editor']) && is_string($st['editor'])) { $node['settings']['editor'] = $translate($st['editor']); $changed++; }
                    if ($wt==='button'      && isset($st['text'])   && is_string($st['text']))   { $node['settings']['text']   = $translate($st['text']);   $changed++; }
                }
                if (isset($node['elements']) && is_array($node['elements'])) $walk($node['elements']);
            }
            unset($node);
        };
        $walk($sections);

        if ($is_array_root) { reeid_el_save_json($post_id, $sections); }
        else { $tree['elements'] = $sections; reeid_el_save_json($post_id, $tree); }

        $ok = reeid_el_render_ok($post_id);
        return ['ok'=>$ok, 'changed'=>$changed];
    }
}

/* --- v3c with integrated fallback --- */
if (!function_exists('reeid_elementor_walk_translate_and_commit_v3c')) {
    function reeid_elementor_walk_translate_and_commit_v3c(int $post_id, string $source, string $target, string $tone = '', string $extra = ''): array
    {
        $orig_json = (string) get_post_meta($post_id, '_elementor_data', true);
        $decoded   = json_decode($orig_json, true);
        if (!is_array($decoded)) {
            // try to recover and continue
            $decoded   = reeid_el_get_json($post_id);
            $orig_json = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }

        // root
        $is_doc=false;
        $root = function_exists('rt2_el_root_ref') ? rt2_el_root_ref($decoded,$is_doc)
              : (function($d,&$f){ $f = is_array($d)&&isset($d['elements'],$d['version']); return $f?$d:(is_array($d)?['elements'=>$d]:['elements'=>[]]); })($decoded,$is_doc);
        $nodes = isset($root['elements']) && is_array($root['elements']) ? $root['elements'] : [];

        // collect original text controls (prefer remote walker)
        $flat_orig = [];
        if (function_exists('reeid_elementor_collect_text_map_via_api_then_local')) {
            $flat_orig = reeid_elementor_collect_text_map_via_api_then_local($orig_json);
        } elseif (function_exists('rt2_el_walk_collect')) {
            rt2_el_walk_collect($nodes, [], $flat_orig);
        } elseif (function_exists('rt_el_walk_collect')) {
            rt_el_walk_collect($nodes, [], $flat_orig);
        }

        $orig_count = count($flat_orig);
        $orig_json_before = $orig_json;

        /* Fallback #1: no text controls -> simple walker */
        if ($orig_count === 0) {
            $fb = reeid_el_simple_translate_and_commit($post_id, $source, $target, $tone, $extra);
            return $fb['ok'] ? ['ok'=>true,'count'=>0,'msg'=>'fallback_saved','changed'=>($fb['changed']??0)]
                             : ['ok'=>false,'count'=>0,'msg'=>'fallback_failed'];
        }

        // dedup + translate with keep-original safety
        $uniq_in  = array_values(array_unique(array_values($flat_orig)));
        $uniq_out = [];
        foreach ($uniq_in as $str) {
            $tr = $str;
            if (function_exists('reeid_translate_html_with_openai')) {
                $try  = (string) reeid_translate_html_with_openai($str, $source, $target, $tone, $extra);
                $trim = trim($try);
                $looks_json = ($trim !== '' && ($trim[0] === '{' || $trim[0] === '['));
                if ($trim !== '' && !$looks_json) $tr = $try;
            }
            $uniq_out[$str] = $tr;
        }
        $translated_map = [];
        foreach ($flat_orig as $k=>$v) $translated_map[$k] = $uniq_out[$v];

        // assemble
        $new_json = function_exists('rt2_el_assemble_with_map')
            ? rt2_el_assemble_with_map($orig_json, $translated_map)
            : rt_el_assemble_with_map($orig_json, $translated_map);

        // schema guard
        if (function_exists('rt2_el_schema_guard_diff')) {
            if (!rt2_el_schema_guard_diff($orig_json, $new_json))
                return ['ok'=>false,'count'=>$orig_count,'msg'=>'schema_guard_block'];
        } elseif (function_exists('rt_el_schema_guard_diff')) {
            if (!rt_el_schema_guard_diff($orig_json, $new_json))
                return ['ok'=>false,'count'=>$orig_count,'msg'=>'schema_guard_block'];
        }

        // pre-commit threshold
        $dec_new = json_decode($new_json, true);
        $is_doc_new=false;
        $root_new = function_exists('rt2_el_root_ref') ? rt2_el_root_ref($dec_new,$is_doc_new)
                   : (function($d,&$f){ $f = is_array($d)&&isset($d['elements'],$d['version']); return $f?$d:(is_array($d)?['elements'=>$d]:['elements'=>[]]); })($dec_new,$is_doc_new);
        $nodes_new = isset($root_new['elements']) && is_array($root_new['elements']) ? $root_new['elements'] : [];
        $flat_new = [];
        if (function_exists('reeid_elementor_collect_text_map_via_api_then_local')) {
            $flat_new = reeid_elementor_collect_text_map_via_api_then_local($new_json);
        } elseif (function_exists('rt2_el_walk_collect')) {
            rt2_el_walk_collect($nodes_new, [], $flat_new);
        } elseif (function_exists('rt_el_walk_collect')) {
            rt_el_walk_collect($nodes_new, [], $flat_new);
        }

        $threshold = max(1, (int) floor($orig_count * 0.5));
        if (count($flat_new) < $threshold) {
            /* Fallback #2: translated text below 50% -> simple walker */
            $fb = reeid_el_simple_translate_and_commit($post_id, $source, $target, $tone, $extra);
            return $fb['ok'] ? ['ok'=>true,'count'=>$orig_count,'msg'=>'fallback_saved_threshold','changed'=>($fb['changed']??0)]
                             : ['ok'=>false,'count'=>$orig_count,'msg'=>'fallback_failed_threshold'];
        }

        // optional title/slug (array ctx safe)
        if ($p = get_post($post_id)) {
            $title_src = (string)$p->post_title;
            $translated_title = $title_src; $translated_slug = '';
            if (function_exists('reeid_translate_via_openai_with_slug')) {
                try {
                    $ctx = ['source'=>$source,'target'=>$target,'tone'=>$tone,'extra'=>$extra];
                    $pack = (array) reeid_translate_via_openai_with_slug($title_src, "", $ctx);
                    if (!empty($pack['title'])) $translated_title = (string)$pack['title'];
                    if (!empty($pack['slug']))  $translated_slug  = (string)$pack['slug'];
                } catch (\Throwable $e) {
                    if (function_exists('reeid_translate_html_with_openai')) {
                        $try = (string) reeid_translate_html_with_openai($title_src, $source, $target, $tone, $extra);
                        if (trim($try) !== '') $translated_title = $try;
                    }
                }
            } elseif (function_exists('reeid_translate_html_with_openai')) {
                $try = (string) reeid_translate_html_with_openai($title_src, $source, $target, $tone, $extra);
                if (trim($try) !== '') $translated_title = $try;
            }
            $upd = ['ID'=>$post_id];
            if (trim($translated_title) !== '') $upd['post_title'] = $translated_title;
            if (trim($translated_slug)  !== '') $upd['post_name']  = sanitize_title($translated_slug);
            if (count($upd) > 1) wp_update_post($upd);
            if (function_exists('reeid_maybe_update_slug_from_title')) {
                reeid_maybe_update_slug_from_title($post_id, $translated_title, $translated_slug ?? '');
            }
        }

        // normalize commit to ARRAY-ROOT for _elementor_data
        $__dec_new = json_decode($new_json, true);
        if (is_array($__dec_new)) {
            $is_array_root = array_keys($__dec_new)===range(0,count($__dec_new)-1);
            if (!$is_array_root && isset($__dec_new['elements']) && is_array($__dec_new['elements'])) {
                $new_json = wp_json_encode($__dec_new['elements'], JSON_UNESCAPED_UNICODE);
            }
        }

        // commit + css
        if (function_exists('reeid_elementor_commit_post_safe')) reeid_elementor_commit_post_safe($post_id, $new_json);
        else update_post_meta($post_id, '_elementor_data', $new_json);

        // post-commit render guard (attempt fallback before final rollback)
        try { $html = class_exists('\Elementor\Plugin') ? \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($post_id, false) : ''; }
        catch (\Throwable $e) { $html = ''; }
        $has_wrapper = (is_string($html) && strpos($html, 'class="elementor') !== false);
        $visible = trim(strip_tags((string)$html));

        if (!$has_wrapper || strlen($visible) < 20) {
            // try fallback on original tree
            if (function_exists('reeid_elementor_commit_post_safe')) { reeid_elementor_commit_post_safe($post_id, $orig_json_before); }
            else { update_post_meta($post_id, '_elementor_data', $orig_json_before); }
            $fb = reeid_el_simple_translate_and_commit($post_id, $source, $target, $tone, $extra);
            return $fb['ok'] ? ['ok'=>true,'count'=>$orig_count,'msg'=>'fallback_saved_guard','changed'=>($fb['changed']??0)]
                             : ['ok'=>false,'count'=>$orig_count,'msg'=>'render_guard_rollback'];
        }

        return ['ok'=>true,'count'=>$orig_count,'msg'=>'saved'];
    }
}


/* ========================================================================
 * SECTION 39 : Elementor — permissive text collector (heuristic)
 *  - Collect ANY string setting that looks like copy (skip URLs, colors, CSS)
 *  - Never touches schema keys (elType/widgetType/elements/id)
 * ===================================================================== */
if (!function_exists('rt3_el_is_texty')) {
    function rt3_el_is_texty(string $key, string $val): bool {
        $k = strtolower($key);
        $v = trim($val);
        if ($v === '') return false;
        if ($k === '' || $k[0] === '_') return false;                   // meta
        if (preg_match('~^(url|link|image|background|bg_|icon|html_tag|alignment|align|size|width|height|color|colors|typography|font|letter|line_height|border|padding|margin|box_shadow|object_|z_index|position|hover_|motion_fx|transition|duration)$~i', $k)) {
            return false;
        }
        if (preg_match('~^(#?[0-9a-f]{3,8}|var\(--|https?://|/wp-content/|[0-9]+(px|em|rem|%)$)~i', $v)) {
            return false;                                              // css/urls/colors
        }
        // looks like human text (has a letter and either space or non-ascii)
        if (!preg_match('~[A-Za-z\p{L}]~u', $v)) return false;
        if (!preg_match('~(\s|\p{M}|\p{L}{3,})~u', $v)) return false;
        return true;
    }
}
if (!function_exists('rt3_el_walk_collect')) {
    function rt3_el_walk_collect(array $nodes, array $path, array &$map): void {
        foreach ($nodes as $node) {
            if (!is_array($node)) continue;
            $id = isset($node['id']) ? (string)$node['id'] : '';
            $p  = array_merge($path, [$id !== '' ? $id : 'node']);
            if (isset($node['settings']) && is_array($node['settings'])) {
                foreach ($node['settings'] as $k => $v) {
                    if (is_string($v) && rt3_el_is_texty((string)$k, (string)$v)) {
                        $map[implode('/', array_merge($p, ['settings', (string)$k]))] = $v;
                    }
                }
            }
            foreach (['elements','children','_children'] as $kids) {
                if (!empty($node[$kids]) && is_array($node[$kids])) {
                    rt3_el_walk_collect($node[$kids], array_merge($p, [$kids]), $map);
                }
            }
        }
    }
}

/* ========================================================================
 * SECTION 40 : Elementor — collect text paths via api.reeid.com (fallback local)
 *  Endpoint (stateless): POST https://api.reeid.com/v1/walkers/elementor-paths
 *  Body: { content: { elementor: "<JSON string>" }, source: "en", target: "gu" }
 *  Expected: { ok: true, paths: ["<id>/settings/title", ...] }
 *  This NEVER uses any OpenAI key. Pure paths only. BYOK preserved.
 * ===================================================================== */
if (!function_exists('reeid_elementor_collect_text_map_via_api_then_local')) {
    function reeid_elementor_collect_text_map_via_api_then_local(string $elementor_json, string $source = 'en', string $target = 'en'): array {
        $map = [];

        // ---- 1) Try remote walker (headers are filtered; no OpenAI keys here)
        $endpoint = apply_filters('reeid/elementor_api_endpoint', 'https://api.reeid.com/v1/walkers/elementor-paths');
        $site     = home_url('/');
        $license  = (string) get_option('reeid_license_key', '');
        $args = [
            'timeout' => 6,
            'headers' => [
                'Content-Type'      => 'application/json; charset=utf-8',
                'X-REEID-Site'      => $site,
                'X-REEID-License'   => $license,
            ],
            'body'    => wp_json_encode([
                'content' => ['elementor' => $elementor_json],
                'source'  => $source,
                'target'  => $target,
            ], JSON_UNESCAPED_UNICODE),
        ];
        $args = apply_filters('reeid/elementor_api_request_args', $args, $elementor_json, $source, $target);
        $ok_remote = false;

        if (function_exists('wp_remote_post')) {
            $resp = wp_remote_post($endpoint, $args);
            if (!is_wp_error($resp)) {
                $code = (int) wp_remote_retrieve_response_code($resp);
                $body = (string) wp_remote_retrieve_body($resp);
                if ($code >= 200 && $code < 300) {
                    $j = json_decode($body, true);
                    if (is_array($j) && !empty($j['ok']) && !empty($j['paths']) && is_array($j['paths'])) {
                        // Build path=>value map from returned paths
                        $decoded = json_decode($elementor_json, true);
                        $is_doc  = is_array($decoded) && isset($decoded['elements'], $decoded['version']);
                        $root    = $is_doc ? $decoded : (is_array($decoded) ? ['elements'=>$decoded] : ['elements'=>[]]);
                        $nodes   = isset($root['elements']) && is_array($root['elements']) ? $root['elements'] : [];

                        $get_by_path = function(array $nodes, array $segs) use (&$get_by_path) {
                            // path like: <id>/settings/title OR <id>/elements/<childId>/settings/text
                            if (empty($segs)) return null;
                            $id = array_shift($segs);
                            foreach ($nodes as $node) {
                                if (!is_array($node)) continue;
                                $nid = isset($node['id']) ? (string)$node['id'] : '';
                                if ($nid !== $id) continue;
                                if (empty($segs)) return null;
                                $key = array_shift($segs);
                                if ($key === 'settings') {
                                    $k = array_shift($segs);
                                    if ($k !== null && isset($node['settings'][$k]) && is_string($node['settings'][$k])) {
                                        return $node['settings'][$k];
                                    }
                                    return null;
                                }
                                if (in_array($key, ['elements','children','_children'], true) && !empty($node[$key]) && is_array($node[$key])) {
                                    // next is child id, recurse
                                    return $get_by_path($node[$key], $segs);
                                }
                                return null;
                            }
                            return null;
                        };

                        foreach ($j['paths'] as $p) {
                            if (!is_string($p) || $p === '') continue;
                            $segs = explode('/', $p);
                            $val  = $get_by_path($nodes, $segs);
                            if (is_string($val) && $val !== '') $map[$p] = $val;
                        }
                        $ok_remote = (count($map) > 0);
                    }
                }
            }
        }

        // ---- 2) fallback to local walkers if remote gave nothing
        if (!$ok_remote) {
            $decoded = json_decode($elementor_json, true);
            $is_doc  = is_array($decoded) && isset($decoded['elements'], $decoded['version']);
            $root    = $is_doc ? $decoded : (is_array($decoded) ? ['elements'=>$decoded] : ['elements'=>[]]);
            $nodes   = isset($root['elements']) && is_array($root['elements']) ? $root['elements'] : [];
            // Prefer permissive collector if present
            if (function_exists('rt3_el_walk_collect')) {
                rt3_el_walk_collect($nodes, [], $map);
            } elseif (function_exists('rt2_el_walk_collect')) {
                rt2_el_walk_collect($nodes, [], $map);
            } elseif (function_exists('rt_el_walk_collect')) {
                rt_el_walk_collect($nodes, [], $map);
            }
        }

        return $map;
    }
}


/* ============================================================================
 * SECTION 41: Elementor — JSON recovery helpers
 * - Safe load of _elementor_data (JSON or serialized)
 * - Ensures minimal valid document when data is broken
 * ==========================================================================*/

if (!function_exists('reeid_el_get_json')) {
    function reeid_el_get_json(int $post_id) {
        $raw = (string) get_post_meta($post_id, '_elementor_data', true);
        $dec = json_decode($raw, true);

        if (is_array($dec)) {
            return $dec;
        }

        if (function_exists('is_serialized') && is_serialized($raw)) {
            $u = @unserialize($raw);
            if (is_array($u)) {
                return $u;
            }
        }

        // minimal fallback doc
        return [
            'version'  => '0.4',
            'title'    => 'Recovered',
            'type'     => 'page',
            'elements' => [[
                'id'       => 'rt-sec',
                'elType'   => 'section',
                'settings' => [],
                'elements' => [[
                    'id'       => 'rt-col',
                    'elType'   => 'column',
                    'settings' => ['_column_size'=>100],
                    'elements' => [[
                        'id'=>'rt-head',
                        'elType'=>'widget',
                        'widgetType'=>'heading',
                        'settings'=>['title'=>'Recovered Elementor content'],
                        'elements'=>[]
                    ]]
                ]]
            ]],
            'settings' => []
        ];
    }
}

// /* ============================================================================
//  * SECTION 42: Elementor — Commit JSON safely (update meta + regenerate CSS)
//  * ==========================================================================*/

// if (!function_exists('reeid_elementor_commit_post_safe')) {
//     function reeid_elementor_commit_post_safe(int $post_id, string $json): bool {

//         update_post_meta($post_id, '_elementor_data', $json);
//         update_post_meta($post_id, '_elementor_edit_mode', 'builder');

//         if (!metadata_exists('post', $post_id, '_elementor_page_settings')) {
//             update_post_meta($post_id, '_elementor_page_settings', []);
//         }

//         if (!metadata_exists('post', $post_id, '_elementor_template_type')) {
//             update_post_meta($post_id, '_elementor_template_type', 'wp-page');
//         }

//         if (!metadata_exists('post', $post_id, '_elementor_version')) {
//             $ver = get_option('elementor_version');
//             if (!$ver && defined('ELEMENTOR_VERSION')) {
//                 $ver = ELEMENTOR_VERSION;
//             }
//             if ($ver) {
//                 update_post_meta($post_id, '_elementor_version', $ver);
//             }
//         }

//         if (class_exists('\Elementor\Plugin')) {
//             try {
//                 $css = new \Elementor\Core\Files\CSS\Post($post_id);
//                 $css->update();
//             } catch (\Throwable $e) {
//                 // Fail silently — CSS will regenerate later
//             }
//         }

//         return true;
//     }
// }

/* ============================================================================
 * SECTION 43: Elementor — Render guard
 * - Ensures Elementor can still render page after rewrite
 * ==========================================================================*/

if (!function_exists('reeid_el_render_ok')) {
    function reeid_el_render_ok(int $post_id): bool {

        if (!class_exists('\Elementor\Plugin')) {
            return true; // cannot verify, assume OK
        }

        try {
            $html = \Elementor\Plugin::$instance
                ->frontend
                ->get_builder_content_for_display($post_id, false);
        } catch (\Throwable $e) {
            return false;
        }

        if (!is_string($html) || $html === '') {
            return false;
        }

        if (strpos($html, 'class="elementor') === false) {
            return false;
        }

        return true;
    }
}

/* ============================================================================
 * SECTION 44: Elementor — Permissive text collector (rt3 walker)
 * - Identifies text-like settings (human copy)
 * - Rejects URLs, CSS, colors, dimensions, meta-keys
 * ==========================================================================*/

if (!function_exists('rt3_el_is_texty')) {
    function rt3_el_is_texty(string $key, string $val): bool {

        $k = strtolower($key);
        $v = trim($val);

        if ($v === '') return false;
        if ($k === '' || $k[0] === '_') return false;

        // skip CSS/URL-like fields
        if (preg_match('~^(url|link|image|background|bg_|icon|html_tag|alignment|align|size|width|height|color|colors|typography|font|letter|line_height|border|padding|margin|box_shadow|object_|z_index|position|hover_|motion_fx|transition|duration)$~i', $k)) {
            return false;
        }

        if (preg_match('~^(#?[0-9a-f]{3,8}|var\(--|https?://|/wp-content/|[0-9]+(px|em|rem|%)$)~i', $v)) {
            return false;
        }

        // must contain a letter and typical sentence features
        if (!preg_match('~[A-Za-z\p{L}]~u', $v)) return false;
        if (!preg_match('~(\s|\p{M}|\p{L}{3,})~u', $v)) return false;

        return true;
    }
}

if (!function_exists('rt3_el_walk_collect')) {
    function rt3_el_walk_collect(array $nodes, array $path, array &$map): void {

        foreach ($nodes as $node) {
            if (!is_array($node)) continue;

            $id = isset($node['id']) ? (string)$node['id'] : 'node';
            $p  = array_merge($path, [$id]);

            if (isset($node['settings']) && is_array($node['settings'])) {
                foreach ($node['settings'] as $k => $v) {
                    if (is_string($v) && rt3_el_is_texty((string)$k, (string)$v)) {
                        $map[implode('/', array_merge($p, ['settings', (string)$k]))] = $v;
                    }
                }
            }

            foreach (['elements','children','_children'] as $kids) {
                if (!empty($node[$kids]) && is_array($node[$kids])) {
                    rt3_el_walk_collect($node[$kids], array_merge($p, [$kids]), $map);
                }
            }
        }
    }
}

/* ============================================================================
 * SECTION 45: Elementor — Remote walker (api.reeid.com) with local fallback
 * - Returns map[path] = original_text
 * ==========================================================================*/

if (!function_exists('reeid_elementor_collect_text_map_via_api_then_local')) {
    function reeid_elementor_collect_text_map_via_api_then_local(
        string $elementor_json,
        string $source = 'en',
        string $target = 'en'
    ): array {

        $map = [];

        /* ---------------------------------------------------------------
         * 1) Remote walker attempt  (optional — no fatal if offline)
         * -------------------------------------------------------------*/
        $endpoint = apply_filters(
            'reeid/elementor_api_endpoint',
            'https://api.reeid.com/v1/walkers/elementor-paths'
        );

        $site    = home_url('/');
        $license = (string) get_option('reeid_license_key', '');

        $args = [
            'timeout' => 6,
            'headers' => [
                'Content-Type'    => 'application/json; charset=utf-8',
                'X-REEID-Site'    => $site,
                'X-REEID-License' => $license,
            ],
            'body' => wp_json_encode([
                'content' => ['elementor' => $elementor_json],
                'source'  => $source,
                'target'  => $target,
            ], JSON_UNESCAPED_UNICODE),
        ];

        $args = apply_filters(
            'reeid/elementor_api_request_args',
            $args,
            $elementor_json,
            $source,
            $target
        );

        $ok_remote = false;

        if (function_exists('wp_remote_post')) {
            $resp = wp_remote_post($endpoint, $args);

            if (!is_wp_error($resp)) {
                $code = (int) wp_remote_retrieve_response_code($resp);
                $body = (string) wp_remote_retrieve_body($resp);

                if ($code >= 200 && $code < 300) {
                    $j = json_decode($body, true);

                    if (is_array($j) && !empty($j['ok']) &&
                        !empty($j['paths']) && is_array($j['paths'])) {

                        $decoded = json_decode($elementor_json, true);
                        $is_doc  = is_array($decoded) && isset($decoded['elements'], $decoded['version']);
                        $root    = $is_doc ? $decoded : (is_array($decoded) ? ['elements'=>$decoded] : ['elements'=>[]]);
                        $nodes   = is_array($root['elements']) ? $root['elements'] : [];

                        // path resolver helper
                        $get_by_path = function(array $nodes, array $segs) use (&$get_by_path) {
                            if (empty($segs)) return null;

                            $id = array_shift($segs);

                            foreach ($nodes as $node) {
                                if (!is_array($node)) continue;
                                $nid = $node['id'] ?? '';
                                if ((string)$nid !== $id) continue;

                                if (empty($segs)) return null;

                                $key = array_shift($segs);

                                if ($key === 'settings') {
                                    $k = array_shift($segs);
                                    if ($k !== null &&
                                        isset($node['settings'][$k]) &&
                                        is_string($node['settings'][$k])) {
                                        return $node['settings'][$k];
                                    }
                                    return null;
                                }

                                if (in_array($key, ['elements','children','_children'], true) &&
                                    !empty($node[$key]) && is_array($node[$key])) {
                                    return $get_by_path($node[$key], $segs);
                                }
                                return null;
                            }
                            return null;
                        };

                        foreach ($j['paths'] as $p) {
                            if (!is_string($p) || $p === '') continue;
                            $val = $get_by_path($nodes, explode('/', $p));
                            if (is_string($val) && $val !== '') {
                                $map[$p] = $val;
                            }
                        }

                        $ok_remote = (count($map) > 0);
                    }
                }
            }
        }

        /* ---------------------------------------------------------------
         * 2) Local fallback walker
         * -------------------------------------------------------------*/
        if (!$ok_remote) {
            $decoded = json_decode($elementor_json, true);
            $is_doc  = is_array($decoded) && isset($decoded['elements'], $decoded['version']);
            $root    = $is_doc ? $decoded : (is_array($decoded) ? ['elements'=>$decoded] : ['elements'=>[]]);
            $nodes   = is_array($root['elements']) ? $root['elements'] : [];

            rt3_el_walk_collect($nodes, [], $map);
        }

        return $map;
    }
}

/* ============================================================================
 * SECTION 46: Elementor — Schema-safe Translate + Commit (v3c)
 * FINAL VERSION (ONLY one kept)
 * - BYOK translate
 * - schema guards
 * - remote/local collector
 * - rollback on failure
 * ==========================================================================*/

if (!function_exists('reeid_elementor_walk_translate_and_commit_v3c')) {
    function reeid_elementor_walk_translate_and_commit_v3c(
        int $post_id,
        string $source,
        string $target,
        string $tone = '',
        string $extra = ''
    ): array {

        /* --------------------------------------
         * Load original JSON
         * ------------------------------------*/
        $orig_json = (string) get_post_meta($post_id, '_elementor_data', true);
        $decoded   = json_decode($orig_json, true);

        if (!is_array($decoded)) {
            $decoded   = reeid_el_get_json($post_id);
            $orig_json = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }

        /* --------------------------------------
         * Get text paths (remote walker → local)
         * ------------------------------------*/
        $flat_orig = reeid_elementor_collect_text_map_via_api_then_local(
            $orig_json,
            $source,
            $target
        );

        $orig_count = count($flat_orig);
        $orig_json_before = $orig_json;

        if ($orig_count === 0) {
            // no translatable text — just commit original
            reeid_elementor_commit_post_safe($post_id, $orig_json);
            return ['ok'=>true, 'count'=>0, 'msg'=>'no_text_controls'];
        }

        /* --------------------------------------
         * Deduplicate → translate (BYOK)
         * ------------------------------------*/
        $uniq_in  = array_values(array_unique(array_values($flat_orig)));
        $uniq_out = [];

        foreach ($uniq_in as $str) {

            $translated = $str;

            if (function_exists('reeid_translate_html_with_openai')) {

                $try = (string) reeid_translate_html_with_openai(
                    $str,
                    $source,
                    $target,
                    $tone,
                    $extra
                );

                $trim = trim($try);
                $looks_json = ($trim !== '' &&
                    ($trim[0] === '{' || $trim[0] === '[')
                );

                if ($trim !== '' && !$looks_json) {
                    $translated = $try;
                }
            }

            $uniq_out[$str] = $translated;
        }

        $translated_map = [];
        foreach ($flat_orig as $k => $v) {
            $translated_map[$k] = $uniq_out[$v];
        }

        /* --------------------------------------
         * Assemble new JSON from translated map
         * ------------------------------------*/
        $decoded2 = json_decode($orig_json, true);
        $is_doc = is_array($decoded2) && isset($decoded2['elements'], $decoded2['version']);
        $root   = $is_doc ? $decoded2 : ['elements'=>$decoded2];
        $nodes  = is_array($root['elements']) ? $root['elements'] : [];

        // local replacer:
        $replace = function (&$nodes, array $path, array $map) use (&$replace) {
            foreach ($nodes as &$node) {
                if (!is_array($node)) continue;

                $id = isset($node['id']) ? (string)$node['id'] : 'node';
                $p  = array_merge($path, [$id]);

                if (isset($node['settings']) && is_array($node['settings'])) {
                    foreach ($node['settings'] as $k => $v) {
                        if (!is_string($v)) continue;
                        $key = implode('/', array_merge($p, ['settings',$k]));
                        if (isset($map[$key])) {
                            $node['settings'][$k] = (string)$map[$key];
                        }
                    }
                }

                foreach (['elements','children','_children'] as $kids) {
                    if (!empty($node[$kids]) && is_array($node[$kids])) {
                        $replace($node[$kids], array_merge($p, [$kids]), $map);
                    }
                }
            }
            unset($node);
        };

        $replace($nodes, [], $translated_map);

        if ($is_doc) {
            $root['elements'] = $nodes;
            $new_json = wp_json_encode($root, JSON_UNESCAPED_UNICODE);
        } else {
            $new_json = wp_json_encode($nodes, JSON_UNESCAPED_UNICODE);
        }

        /* --------------------------------------
         * Pre-commit validation (must still have text)
         * ------------------------------------*/
        $flat_new = reeid_elementor_collect_text_map_via_api_then_local(
            $new_json,
            $source,
            $target
        );

        if (count($flat_new) === 0) {
            return ['ok'=>false,'count'=>$orig_count,'msg'=>'text_guard_block'];
        }

        /* --------------------------------------
         * Optional: translate title + slug
         * ------------------------------------*/
        if ($p = get_post($post_id)) {

            $title_src = (string) $p->post_title;
            $translated_title = $title_src;
            $translated_slug  = '';

            if (function_exists('reeid_translate_via_openai_with_slug')) {

                try {
                    $ctx  = ['source'=>$source,'target'=>$target,'tone'=>$tone,'extra'=>$extra];
                    $pack = (array) reeid_translate_via_openai_with_slug($title_src, "", $ctx);

                    if (!empty($pack['title'])) {
                        $translated_title = (string)$pack['title'];
                    }
                    if (!empty($pack['slug'])) {
                        $translated_slug = (string)$pack['slug'];
                    }

                } catch (\Throwable $e) {
                    // fallback: title only
                    if (function_exists('reeid_translate_html_with_openai')) {
                        $try = (string) reeid_translate_html_with_openai(
                            $title_src,
                            $source,
                            $target,
                            $tone,
                            $extra
                        );
                        if (trim($try) !== '') {
                            $translated_title = $try;
                        }
                    }
                }

            } elseif (function_exists('reeid_translate_html_with_openai')) {

                $try = (string) reeid_translate_html_with_openai(
                    $title_src,
                    $source,
                    $target,
                    $tone,
                    $extra
                );

                if (trim($try) !== '') {
                    $translated_title = $try;
                }
            }

            $upd = ['ID' => $post_id];

            if (trim($translated_title) !== '') {
                $upd['post_title'] = $translated_title;
            }

            if (trim($translated_slug) !== '') {
                $upd['post_name'] = sanitize_title($translated_slug);
            }

            if (count($upd) > 1) {
                wp_update_post($upd);
            }

            if (function_exists('reeid_maybe_update_slug_from_title')) {
                reeid_maybe_update_slug_from_title(
                    $post_id,
                    $translated_title,
                    $translated_slug ?? ''
                );
            }
        }

        /* --------------------------------------
         * Commit translated JSON
         * ------------------------------------*/
        reeid_elementor_commit_post_safe($post_id, $new_json);

        /* --------------------------------------
         * Post-commit guard
         * ------------------------------------*/
        if (!reeid_el_render_ok($post_id)) {

            // rollback
            reeid_elementor_commit_post_safe($post_id, $orig_json_before);

            // fallback rewrite via simple walker unavailable → return error
            return ['ok'=>false,'count'=>$orig_count,'msg'=>'render_guard_rollback'];
        }

        return ['ok'=>true,'count'=>$orig_count,'msg'=>'saved'];
    }
}

/* ============================================================================
 * UNIVERSAL FIX: Translate dynamic Woo "Reviews (X)" tab label
 * Uses mapping files: /includes/mappings/woocommerce-<lang>.json
 * ==========================================================================*/
add_filter('woocommerce_product_tabs', function ($tabs) {

    if (!isset($tabs['reviews']['title'])) {
        return $tabs;
    }

    // 1) Resolve current language (your helper)
    $lang = function_exists('reeid_resolve_lang_from_request')
        ? reeid_resolve_lang_from_request()
        : '';

    if ($lang === '' || $lang === 'en') {
        return $tabs; // English stays as-is
    }

    // Normalise (zh-CN → zh-cn)
    $lang = strtolower(trim($lang));

    // 2) Locate mapping file
    $map_file = __DIR__ . "/mappings/woocommerce-{$lang}.json";
    if (!file_exists($map_file)) {
        // try base language (zh-cn → zh)
        $base = substr($lang, 0, 2);
        $map_file = __DIR__ . "/mappings/woocommerce-{$base}.json";
    }

    if (!file_exists($map_file)) {
        return $tabs; // No mapping → nothing we can do
    }

    // 3) Load mapping JSON
    $json = json_decode(file_get_contents($map_file), true);
    if (!is_array($json) || empty($json['Reviews'])) {
        return $tabs;
    }

    $translated = trim($json['Reviews']);

    // 4) Replace dynamic label “Reviews (X)” generically
    $orig = $tabs['reviews']['title'];

    if (preg_match('/Reviews\s*\((\d+)\)/i', $orig, $m)) {
        $count = (int)$m[1];
        $tabs['reviews']['title'] = $translated . " ({$count})";
    }

    return $tabs;

}, 50);

if ( ! function_exists( 'reeid_normalize_string' ) ) {
    function reeid_normalize_string( $string ) {

        if ( ! is_string( $string ) ) {
            return $string;
        }

        // Step 1: lowercase
        $string = mb_strtolower( $string, 'UTF-8' );

        // Step 2: remove punctuation (.,:;!?()[] quotes dashes)
        $string = preg_replace('/[.,:;!?\p{Pd}\p{Ps}\p{Pe}\p{Pf}\p{Pi}"“”«»()\[\]{}]/u', '', $string);

        // Step 3: collapse multiple spaces
        $string = preg_replace('/\s+/u', ' ', $string);

        // Step 4: trim edges
        $string = trim( $string );

        return $string;
    }
}


/*------------------------------------------------------------------------------
  AUTO LICENSE VALIDATION (admin only, once per day)
------------------------------------------------------------------------------*/
add_action( 'admin_init', function () {

    if ( ! function_exists( 'reeid_validate_license' ) ) {
        return;
    }

    $last = (int) get_option( 'reeid_license_checked_at', 0 );

    // Re-check once every 24h
    if ( $last && ( time() - $last ) < DAY_IN_SECONDS ) {
        return;
    }

    reeid_validate_license();
    update_option( 'reeid_license_checked_at', time() );

});
