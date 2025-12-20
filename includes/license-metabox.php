<?php

// File: includes/license-metabox.php



/**
 * Small helpers (scoped; function_exists guards for safety)
 */
if ( ! function_exists( 'reeid9_bool' ) ) {
    function reeid9_bool( $v ) {
        if ( true === $v || 1 === $v ) {
            return true;
        }
        if ( is_string( $v ) ) {
            $vs = strtolower( trim( $v ) );
            return in_array( $vs, array( '1', 'true', 'yes', 'ok', 'success' ), true );
        }
        return false;
    }
}

if ( ! function_exists( 'reeid9_site_host' ) ) {
    function reeid9_site_host() {
        $host = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( ! $host && function_exists( 'network_home_url' ) ) {
            $host = wp_parse_url( network_home_url(), PHP_URL_HOST );
        }
        if ( ! $host && isset( $_SERVER['HTTP_HOST'] ) ) {
            $host = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
        }
        return strtolower( (string) $host );
    }
}

/*------------------------------------------------------------------------------
  1) LICENSE KEY OPTION + SANITIZER
------------------------------------------------------------------------------*/

add_action( 'admin_init', function() {
    register_setting(
        'reeid_translate_settings',
        'reeid_pro_license_key',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'reeid_sanitize_license_key',
            'default'           => '',
        )
    );
} );

/**
 * Sanitize the license key and preserve status if unchanged.
 */
function reeid_sanitize_license_key( $input ) {
    $input   = sanitize_text_field( trim( (string) $input ) );
    $old_key = trim( (string) get_option( 'reeid_pro_license_key', '' ) );

    if ( $input !== $old_key ) {
        update_option( 'reeid_license_status', 'invalid' );
    }
    return $input;
}

/*------------------------------------------------------------------------------
  2) AUTO-VALIDATE AFTER SAVING LICENSE KEY
------------------------------------------------------------------------------*/

add_action(
    'update_option_reeid_pro_license_key',
    function( $old, $new ) {
        $new = trim( (string) $new );

        if ( '' === $new ) {
            update_option( 'reeid_license_status', 'invalid' );
            update_option( 'reeid_license_last_code', 0 );
            update_option( 'reeid_license_last_raw', '' );
            update_option( 'reeid_license_last_msg', '' );
            update_option( 'reeid_license_checked_at', time() );
        } else {
            reeid_validate_license( $new );
        }
    },
    10,
    2
);

/*------------------------------------------------------------------------------
  3) AJAX: VALIDATE LICENSE KEY (test only, do NOT save)
------------------------------------------------------------------------------*/

function reeid_handle_validate_license_key() {

	$in = filter_input_array(
		INPUT_POST,
		array(
			'nonce' => FILTER_UNSAFE_RAW,
			'key'   => FILTER_UNSAFE_RAW,
		)
	);
	$in = is_array( $in ) ? $in : array();

	// Resolve nonce safely from POST first, then fallbacks
	if ( isset( $in['nonce'] ) && $in['nonce'] !== '' ) {
		$nonce = sanitize_text_field( wp_unslash( $in['nonce'] ) );
	} elseif ( isset( $_REQUEST['nonce'] ) ) {
		$nonce = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) );
	} elseif ( isset( $_POST['nonce'] ) ) {
		$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ) );
	} elseif ( isset( $_GET['nonce'] ) ) {
		$nonce = sanitize_text_field( wp_unslash( $_GET['nonce'] ) );
	} else {
		$nonce = '';
	}

	$ok_nonce = false;
	if ( $nonce !== '' ) {
		if ( wp_verify_nonce( $nonce, 'reeid_translate_nonce' ) ) {
			$ok_nonce = true;
		} elseif ( wp_verify_nonce( $nonce, 'reeid_translate_nonce_action' ) ) {
			$ok_nonce = true;
		}
	}

	if ( ! $ok_nonce ) {
		wp_send_json_error(
			array(
				'valid'   => false,
				'message' => __( 'Invalid or missing security token. Please reload the page and try again.', 'reeid-translate' ),
			)
		);
	}

	$key_raw = isset( $in['key'] ) ? wp_unslash( $in['key'] ) : '';
	$key     = sanitize_text_field( trim( (string) $key_raw ) );

	if ( '' === $key ) {
		wp_send_json_success(
			array(
				'valid'   => false,
				'message' => __( 'Please enter a license key.', 'reeid-translate' ),
			)
		);
	}

	$domain = reeid9_site_host();

	$resp = wp_remote_post(
		'https://go.reeid.com/validate-license.php',
		array(
			'timeout' => 15,
			'body'    => array(
				'license_key' => $key,
				'domains'     => $domain,
			),
		)
	);

	if ( is_wp_error( $resp ) ) {
	wp_send_json_error(
		array(
			'valid' => false,
			'message' => sprintf(
				// translators: %s is the WP_Error message returned when the license server cannot be reached.
				__( 'Could not connect to license server: %s', 'reeid-translate' ),
				$resp->get_error_message()
			),
		)
	);
}


	$code = (int) wp_remote_retrieve_response_code( $resp );
	$body = (string) wp_remote_retrieve_body( $resp );

	update_option( 'reeid_license_last_code', $code );
	update_option( 'reeid_license_last_raw', substr( $body, 0, 800 ) );

	$valid   = false;
	$message = '';

	if ( 200 === $code && '' !== $body ) {

		$data = json_decode( $body, true );
		if ( is_array( $data ) ) {
			$valid   = isset( $data['valid'] ) ? reeid9_bool( $data['valid'] ) : false;
			$message = isset( $data['message'] ) ? (string) $data['message'] : '';
			update_option( 'reeid_license_last_msg', $message );
		} else {
			// translators: shown when the license server responds with non-JSON output.
			$message = __( 'Non-JSON response from license server.', 'reeid-translate' );
		}

	} else {
		
		$message = sprintf(
            // translators: %1$d is the HTTP response code returned by the license server.
			__( 'HTTP %1$d empty or invalid response.', 'reeid-translate' ),
			$code
		);
	}

	$saved_key = trim( (string) get_option( 'reeid_pro_license_key', '' ) );
	if ( '' !== $saved_key && $saved_key === $key ) {
		update_option( 'reeid_license_status', $valid ? 'valid' : 'invalid' );
		update_option( 'reeid_license_checked_at', time() );
	}

	if ( $valid ) {
		if ( '' !== $saved_key && $saved_key !== $key ) {
			if ( '' === $message ) {
				$message = __( 'License key is valid.', 'reeid-translate' );
			}
			$message .= ' ' . __( 'Click “Save Changes” to store this key.', 'reeid-translate' );
		} else {
			if ( '' === $message ) {
				$message = __( 'License key is valid for this domain.', 'reeid-translate' );
			}
		}
	} else {
		if ( '' === $message ) {
			$message = __( 'License key is invalid or not active.', 'reeid-translate' );
		}
	}

	wp_send_json_success(
		array(
			'valid'   => $valid,
			'message' => $message,
		)
	);
}

add_action( 'wp_ajax_reeid_validate_license_key', 'reeid_handle_validate_license_key' );



/*------------------------------------------------------------------------------
  4) CANONICAL LICENSE VALIDATOR
------------------------------------------------------------------------------*/

function reeid_validate_license( $license_key = '' ) {

    $license_key = $license_key ? $license_key : trim( (string) get_option( 'reeid_pro_license_key', '' ) );
    if ( '' === $license_key ) {
        update_option( 'reeid_license_status', 'invalid' );
        update_option( 'reeid_license_checked_at', time() );
        update_option( 'reeid_license_last_code', 0 );
        update_option( 'reeid_license_last_raw', '' );
        update_option( 'reeid_license_last_msg', __( 'Empty license key.', 'reeid-translate' ) );
        return false;
    }

    $domain = reeid9_site_host();

    $resp = wp_remote_post(
        'https://go.reeid.com/validate-license.php',
        array(
            'timeout' => 15,
            'body'    => array(
                'license_key' => $license_key,
                'domains'     => $domain,
            ),
        )
    );

    if ( is_wp_error( $resp ) ) {
        update_option( 'reeid_license_checked_at', time() );
        update_option( 'reeid_license_last_code', 0 );
        update_option( 'reeid_license_last_raw', '' );
        update_option( 'reeid_license_last_msg', 'WP_Error: ' . $resp->get_error_message() );
        return false;
    }

    $code = (int) wp_remote_retrieve_response_code( $resp );
    $body = (string) wp_remote_retrieve_body( $resp );

    update_option( 'reeid_license_last_code', $code );
    update_option( 'reeid_license_last_raw', substr( $body, 0, 800 ) );

    if ( 200 !== $code || '' === $body ) {
        update_option( 'reeid_license_checked_at', time() );
        update_option( 'reeid_license_last_msg', 'HTTP ' . $code . ' empty/body' );
        return false;
    }

    $data = json_decode( $body, true );
    if ( ! is_array( $data ) ) {
        update_option( 'reeid_license_checked_at', time() );
        update_option( 'reeid_license_last_msg', 'Non-JSON response' );
        return false;
    }

    $ok      = isset( $data['valid'] ) ? reeid9_bool( $data['valid'] ) : false;
    $message = isset( $data['message'] ) ? (string) $data['message'] : '';

    update_option( 'reeid_license_status', $ok ? 'valid' : 'invalid' );
    update_option( 'reeid_license_checked_at', time() );
    update_option( 'reeid_license_last_msg', $message );

    return $ok;
}

/*------------------------------------------------------------------------------
  5) CHECK PRO ACTIVE
------------------------------------------------------------------------------*/

function reeid_is_pro_active() {
    return 'valid' === get_option( 'reeid_license_status', 'invalid' );
}

/*------------------------------------------------------------------------------
  6) META BOX REGISTRATION (Hidden on Elementor)
------------------------------------------------------------------------------*/

add_action( 'add_meta_boxes', function() {

    global $post;
    $post_id = 0;

    $get_post     = filter_input( INPUT_GET, 'post', FILTER_SANITIZE_NUMBER_INT );
    $post_post_id = filter_input( INPUT_POST, 'post_ID', FILTER_SANITIZE_NUMBER_INT );

    if ( $get_post ) {
        $post_id = (int) $get_post;
    } elseif ( $post_post_id ) {
        $post_id = (int) $post_post_id;
    } elseif ( isset( $post ) ) {
        if ( ! $post instanceof WP_Post ) {
            $post = get_post( $post );
        }
        if ( $post instanceof WP_Post ) {
            $post_id = (int) $post->ID;
        }
    }

    $is_elementor = $post_id ? ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) ) : false;
    if ( $is_elementor ) {
        return;
    }

    add_meta_box(
        'reeid-translation-meta-box',
        __( 'REEID TRANSLATION', 'reeid-translate' ),
        'reeid_render_meta_box',
        array( 'post', 'page', 'product' ),
        'side',
        'high'
    );
} );

/*------------------------------------------------------------------------------
  7) META BOX WRAPPER
------------------------------------------------------------------------------*/

function reeid_render_meta_box( $post ) {
    if ( ! $post instanceof WP_Post ) {
        $post = get_post( $post );
    }
    wp_nonce_field( 'reeid_translate_nonce_action', 'reeid_translate_nonce' );
    reeid_render_meta_box_ui_controls( $post );
}

/*------------------------------------------------------------------------------
  8) META BOX UI CONTROLS (RESTORED)
------------------------------------------------------------------------------*/

function reeid_render_meta_box_ui_controls( $post ) {

    if ( ! $post instanceof WP_Post ) {
        $post = get_post( $post );
    }

    $languages            = function_exists( 'reeid_get_supported_languages' ) ? (array) reeid_get_supported_languages() : array();
    $saved_tone           = get_post_meta( $post->ID, '_reeid_post_tone', true );
    $saved_prompt         = get_post_meta( $post->ID, '_reeid_post_prompt', true );
    $source_lang          = get_post_meta( $post->ID, '_reeid_translation_lang', true ) ?: get_option( 'reeid_translation_source_lang', 'en' );
    $saved_lang           = get_post_meta( $post->ID, '_reeid_target_lang', true );
    $bulk_targets         = (array) get_option( 'reeid_bulk_translation_langs', array() );
    $is_pro               = reeid_is_pro_active();
    $max_free_langs       = 10;
    $free_bulk_disabled   = ! $is_pro;
    $free_prompt_disabled = ! $is_pro;

    if ( ! $saved_lang || $saved_lang === $source_lang ) {
        foreach ( $languages as $code => $_label ) {
            if ( $code !== $source_lang ) {
                $saved_lang = $code;
                break;
            }
        }
    }
?>
    <div class="reeid-field">
        <strong><?php esc_html_e( 'Select target language:', 'reeid-translate' ); ?></strong>
        <select name="reeid_target_lang" id="reeid_target_lang">
            <?php
            $langs_list = $is_pro ? array_keys( $languages ) : array_slice( array_keys( $languages ), 0, $max_free_langs + 1 );
            foreach ( $langs_list as $code ) {
                if ( $code === $source_lang ) {
                    continue;
                }
                printf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr( $code ),
                    selected( $saved_lang, $code, false ),
                    esc_html( $languages[ $code ] )
                );
            }
            ?>
        </select>
    </div>

    <div class="reeid-field">
        <strong><?php esc_html_e( 'Tone override:', 'reeid-translate' ); ?></strong>
        <select name="reeid_post_tone" id="reeid_tone_pick" style="width:100%;margin-bottom:8px;">
            <option value=""><?php esc_html_e( 'Use default', 'reeid-translate' ); ?></option>
            <?php
            $tones = array( 'Neutral', 'Formal', 'Informal', 'Friendly', 'Technical', 'Persuasive', 'Concise', 'Verbose' );
            foreach ( $tones as $tone ) {
                printf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr( $tone ),
                    selected( $saved_tone, $tone, false ),
                    esc_html( $tone )
                );
            }
            ?>
        </select>
    </div>

    <div class="reeid-field">
        <strong><?php esc_html_e( 'Prompt override:', 'reeid-translate' ); ?></strong>
        <textarea
            name="reeid_post_prompt"
            id="reeid_prompt"
            rows="3"
            style="width:100%;"
            <?php echo $free_prompt_disabled ? 'disabled placeholder="' . esc_attr__( 'Available in PRO', 'reeid-translate' ) . '"' : ''; ?>
        ><?php echo esc_textarea( $saved_prompt ); ?></textarea>

        <?php if ( $free_prompt_disabled ) : ?>
            <div style="color:#888;font-size:11px;">
                <?php esc_html_e( 'Custom prompt is available in PRO version.', 'reeid-translate' ); ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="reeid-field">
        <strong><?php esc_html_e( 'Translation Status', 'reeid-translate' ); ?></strong><br>
        <label>
            <input type="radio" name="reeid_publish_mode" value="publish" checked>
            <?php esc_html_e( 'Translate and Publish', 'reeid-translate' ); ?>
        </label><br>
        <label>
            <input type="radio" name="reeid_publish_mode" value="draft">
            <?php esc_html_e( 'Translate and Save as Draft', 'reeid-translate' ); ?>
        </label>
    </div>

    <div id="reeid-bulk-progress-list" class="reeid-bulk-progress-list"></div>

    <div class="reeid-buttons">
        <button id="reeid-translate-btn" class="reeid-button primary" data-postid="<?php echo esc_attr( $post->ID ); ?>">
            <?php esc_html_e( 'Translate', 'reeid-translate' ); ?>
        </button>

        <button id="reeid-bulk-translate-btn" class="reeid-button secondary" data-postid="<?php echo esc_attr( $post->ID ); ?>"
            <?php echo $free_bulk_disabled ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''; ?>>
            <?php esc_html_e( 'Bulk Translation', 'reeid-translate' ); ?>
        </button>

        <?php if ( $free_bulk_disabled ) : ?>
            <div style="color:#888;font-size:11px;">
                <?php esc_html_e( 'Bulk Translation is available in PRO version.', 'reeid-translate' ); ?>
            </div>
        <?php endif; ?>
    </div>

    <div id="reeid-status"></div>
    <hr>

    <?php if ( ! $is_pro ) : ?>
    <div style="font-size:12px;color:#888;">
        <?php
        $upgrade_url  = esc_url( 'https://reeid.com/pro/' );
        $upgrade_text = sprintf(
            /* translators: %s is the upgrade URL for REEID PRO. */
            __( 'Upgrade to <a href="%s" target="_blank" rel="noopener">REEID PRO</a> for unlimited languages, bulk translation, and custom instructions.', 'reeid-translate' ),
            $upgrade_url
        );

        echo wp_kses(
            $upgrade_text,
            array(
                'a' => array(
                    'href'   => array(),
                    'target' => array(),
                    'rel'    => array(),
                ),
            )
        );
        ?>
    </div>
<?php else : ?>
    <div style="font-size:12px;color:#32c24d;">
        <?php esc_html_e( 'PRO features unlocked!', 'reeid-translate' ); ?>
    </div>
<?php endif; ?>
<?php

}


