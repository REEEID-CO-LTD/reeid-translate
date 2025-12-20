<?php

// =====================================================
// Admin & editor asset enqueues for REEID Translate.
// Bulletproof asset loading from plugin ROOT, not /includes/
// =====================================================

// Point directly to MAIN PLUGIN FILE → never breaks
$reeid_main_file  = dirname( __DIR__ ) . '/reeid-translate.php';
$reeid_base_path  = dirname( __DIR__ ); // /wp-content/plugins/reeid-translate
$reeid_base_url   = plugins_url( '', $reeid_main_file ); // correct root URL


add_action( 'admin_enqueue_scripts', 'reeid_enqueue_admin_and_metabox_assets' );
function reeid_enqueue_admin_and_metabox_assets( $hook ) {
    global $reeid_base_path, $reeid_base_url;

    /* =====================================================
       A) Settings Page Assets
       ===================================================== */
    if ( 'settings_page_reeid-translate-settings' === $hook ) {

        wp_enqueue_style(
            'reeid-admin-styles',
            $reeid_base_url . '/assets/css/admin-styles.css',
            array(),
            '1.0'
        );

        $admin_js = $reeid_base_path . '/assets/js/admin-settings.js';
        if ( file_exists( $admin_js ) ) {

            wp_enqueue_script(
                'reeid-admin-settings',
                $reeid_base_url . '/assets/js/admin-settings.js',
                array( 'jquery' ),
                (string) filemtime( $admin_js ),
                true
            );

            wp_localize_script(
                'reeid-admin-settings',
                'REEID_TRANSLATE',
                array(
                    'ajaxurl'        => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
                    'nonce'          => wp_create_nonce( 'reeid_translate_nonce_action' ),
                    'license_status' => (string) get_option( 'reeid_license_status', '' ),
                    'license_msg'    => (string) get_option( 'reeid_license_last_msg', '' ),
                )
            );
        }
    }

    /* =====================================================
       B) Post Edit Screens (Metabox)
       ===================================================== */
    if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {

        /* --- CSS --- */
        $mb_css = $reeid_base_path . '/assets/css/meta-box.css';
        if ( file_exists( $mb_css ) ) {
            wp_enqueue_style(
                'reeid-meta-box-styles',
                $reeid_base_url . '/assets/css/meta-box.css',
                array(),
                (string) filemtime( $mb_css )
            );
        }

        /* --- JS --- */
        $mb_js = $reeid_base_path . '/assets/js/translation-meta-box.js';
        if ( file_exists( $mb_js ) ) {

            wp_enqueue_script(
                'reeid-translation-meta-box',
                $reeid_base_url . '/assets/js/translation-meta-box.js',
                array( 'jquery' ),
                (string) filemtime( $mb_js ),
                true
            );

            /* --- Localize meta-box data --- */
            $lang_names = function_exists( 'reeid_get_supported_languages' )
                ? (array) reeid_get_supported_languages()
                : array();

            $bulk = get_option( 'reeid_bulk_translation_langs', array() );
            if ( empty( $bulk ) ) {
                $bulk = get_option( 'reeid_bulk_languages', array() );
            }

            if ( ! is_array( $bulk ) ) {
                $bulk = array_filter(
                    array_map( 'sanitize_text_field', explode( ',', (string) $bulk ) )
                );
            } else {
                $bulk = array_map( 'sanitize_text_field', $bulk );
            }

            if ( $lang_names ) {
                $bulk = array_values(
                    array_intersect( $bulk, array_keys( $lang_names ) )
                );
            }

            wp_localize_script(
                'reeid-translation-meta-box',
                'reeidData',
                array(
                    'ajaxurl'   => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
                    'nonce'     => wp_create_nonce( 'reeid_translate_nonce_action' ),
                    'langNames' => $lang_names,
                    'bulkLangs' => $bulk,
                )
            );

            /* =====================================================
               WooCommerce product editor enhancements
               ===================================================== */
            $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

            if ( $screen && ! empty( $screen->post_type ) && $screen->post_type === 'product' ) {

                wp_enqueue_style( 'woocommerce_admin_styles' );
                wp_enqueue_script( 'wc-enhanced-select' );

                wp_register_script(
                    'reeid-wc-translate-admin',
                    $reeid_base_url . '/assets/js/reeid-wc-translate-admin.js',
                    array( 'jquery', 'wc-enhanced-select' ),
                    '1.0',
                    true
                );

                wp_localize_script(
                    'reeid-wc-translate-admin',
                    'REEID_WC_TR',
                    array(
                        'ajax'  => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
                        'nonce' => wp_create_nonce( 'reeid_wc_bulk_delete' ),
                        'labels' => array(
                            'placeholder' => __( 'Select languages to remove…', 'reeid-translate' ),
                            'confirm'     => __( 'Delete the selected translations? This cannot be undone.', 'reeid-translate' ),
                            'deleted'     => __( 'Deleted', 'reeid-translate' ),
                            'noneLeft'    => __( 'No translations found.', 'reeid-translate' ),
                        ),
                    )
                );

                wp_enqueue_script( 'reeid-wc-translate-admin' );

                $reeid_wc_tr_css = '
                    .reeid-wc-tr-toolbar{display:flex;gap:8px;align-items:center;margin:6px 0 10px;}
                    .reeid-wc-tr-select{min-width:260px;max-width:420px;}
                    .reeid-wc-tr-toolbar .button{height:32px}
                ';
                wp_add_inline_style( 'woocommerce_admin_styles', $reeid_wc_tr_css );
            }
        }
    }
}

/* =====================================================
   C) Elementor Editor Assets
   ===================================================== */
add_action( 'elementor/editor/after_enqueue_scripts', 'reeid_enqueue_elementor_assets' );
function reeid_enqueue_elementor_assets() {
	global $reeid_base_path, $reeid_base_url;

	$el_js = $reeid_base_path . '/assets/js/elementor-translate.js';
	if ( ! file_exists( $el_js ) ) {
		return;
	}

	wp_enqueue_script(
		'reeid-elementor-translate',
		$reeid_base_url . '/assets/js/elementor-translate.js',
		array( 'jquery' ),
		time(),
		true
	);

	$lang_names = function_exists( 'reeid_get_supported_languages' )
		? (array) reeid_get_supported_languages()
		: array();

	$bulk = get_option( 'reeid_bulk_translation_langs', array() );
	if ( empty( $bulk ) ) {
		$bulk = get_option( 'reeid_bulk_languages', array() );
	}

	if ( ! is_array( $bulk ) ) {
		$bulk = array_filter(
			array_map( 'sanitize_text_field', explode( ',', (string) $bulk ) )
		);
	} else {
		$bulk = array_map( 'sanitize_text_field', $bulk );
	}

	if ( $lang_names ) {
		$bulk = array_values(
			array_intersect( $bulk, array_keys( $lang_names ) )
		);
	}

	/*
	 * Read-only context: Elementor editor bootstrap.
	 * No state change, no privileged action → nonce not required.
	 */
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$post_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;

	$post_type = $post_id ? get_post_type( $post_id ) : 'post';

	wp_localize_script(
		'reeid-elementor-translate',
		'reeidData',
		array(
			'ajaxurl'    => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'nonce'      => wp_create_nonce( 'reeid_translate_nonce_action' ),
			'langNames'  => $lang_names,
			'bulkLangs'  => $bulk,
			'postType'   => $post_type,
			'listUrls'   => array(
				'post'    => admin_url( 'edit.php' ),
				'page'    => admin_url( 'edit.php?post_type=page' ),
				'product' => admin_url( 'edit.php?post_type=product' ),
			),
			'panelColor' => '#cf616a',
		)
	);
}


/* =====================================================
   D) Remove old alert helpers
   ===================================================== */
add_action( 'elementor/editor/before_enqueue_scripts', function () {
    wp_dequeue_script( 'reeid-block-alert' );
    wp_dequeue_script( 'reeid-translation-block-alert' );
}, 1000 );
