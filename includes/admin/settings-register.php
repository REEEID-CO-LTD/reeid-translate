<?php
if ( ! defined( "ABSPATH" ) ) { exit; }


/*
 * Admin-only: settings should never load on frontend.
 */
if ( is_admin() ) {
    add_action( 'admin_init', 'reeid_register_settings' );
    add_action( 'admin_menu', 'reeid_add_settings_page' );
}

function reeid_register_settings() {

    /* --- API KEY --- */
    register_setting(
        'reeid_translate_settings',
        'reeid_openai_api_key',
        array(
            'type'              => 'string',
            'sanitize_callback' => function( $v ) {
                return sanitize_text_field( (string) wp_unslash( $v ) );
            },
            'default'           => '',
        )
    );

    /* --- MODEL (locked to gpt-4o) --- */
    register_setting(
        'reeid_translate_settings',
        'reeid_openai_model',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'reeid_sanitize_model',
            'default'           => 'gpt-4o',
        )
    );

    /* --- Tone presets --- */
    register_setting(
        'reeid_translate_settings',
        'reeid_translation_tones',
        array(
            'type'              => 'array',
            'sanitize_callback' => 'reeid_sanitize_tones',
            'default'           => array( 'Neutral' ),
        )
    );

    /* --- Custom prompt --- */
    register_setting(
        'reeid_translate_settings',
        'reeid_translation_custom_prompt',
        array(
            'type'              => 'string',
            'sanitize_callback' => function( $v ) {
                $v = wp_unslash( (string) $v );
                $v = wp_kses_post( $v );
                return trim( $v );
            },
            'default'           => '',
        )
    );

    /* --- Default source language --- */
    register_setting(
        'reeid_translate_settings',
        'reeid_translation_source_lang',
        array(
            'type'              => 'string',
            'sanitize_callback' => function( $v ) {
                return sanitize_text_field( (string) wp_unslash( $v ) );
            },
            'default'           => 'en',
        )
    );

    /* --- Bulk translation languages --- */
    register_setting(
        'reeid_translate_settings',
        'reeid_bulk_translation_langs',
        array(
            'type'              => 'array',
            'sanitize_callback' => 'reeid_sanitize_bulk_langs',
            'default'           => array(),
        )
    );


    /* =======================================================================
       SETTINGS SECTION
       ======================================================================= */
    add_settings_section(
        'reeid_section_general',
        __( 'General Settings', 'reeid-translate' ),
        function() {
            echo '<p>' . esc_html__( 'Configure your API key, tone presets, default source language, custom instructions (PRO), bulk languages (PRO), and license key.', 'reeid-translate' ) . '</p>';
        },
        'reeid-translate-settings'
    );

    /* --- Fields --- */

    add_settings_field(
        'reeid_openai_api_key',
        __( 'OpenAI API Key', 'reeid-translate' ),
        'reeid_render_api_key_field',
        'reeid-translate-settings',
        'reeid_section_general'
    );

    add_settings_field(
        'reeid_translation_tones',
        __( 'Tone / Style', 'reeid-translate' ),
        'reeid_render_tone_checkbox_list',
        'reeid-translate-settings',
        'reeid_section_general'
    );

    add_settings_field(
        'reeid_translation_source_lang',
        __( 'Default Source Language', 'reeid-translate' ),
        'reeid_render_source_lang_field',
        'reeid-translate-settings',
        'reeid_section_general'
    );

    add_settings_field(
        'reeid_translation_custom_prompt',
        __( 'Custom Instructions (PRO)', 'reeid-translate' ),
        'reeid_render_custom_prompt_field',
        'reeid-translate-settings',
        'reeid_section_general'
    );

    add_settings_field(
        'reeid_bulk_translation_langs',
        __( 'Bulk Translation Languages (PRO)', 'reeid-translate' ),
        'reeid_render_bulk_langs_field',
        'reeid-translate-settings',
        'reeid_section_general'
    );

    add_settings_field(
        'reeid_pro_license_key',
        __( 'License Key', 'reeid-translate' ),
        'reeid_render_license_key_field',
        'reeid-translate-settings',
        'reeid_section_general'
    );
}


/*==============================================================================
  SANITIZERS
==============================================================================*/

/** Enforce model hard-lock to gpt-4o. */
function reeid_sanitize_model( $input ) {
    $input = sanitize_text_field( (string) wp_unslash( $input ) );
    return 'gpt-4o';
}

/** Sanitize tone presets (max two). */
function reeid_sanitize_tones( $input ) {

    $valid = array(
        'Neutral', 'Formal', 'Informal', 'Friendly',
        'Technical', 'Persuasive', 'Concise', 'Verbose'
    );

    $out = array();
    if ( is_array( $input ) ) {
        foreach ( $input as $tone ) {
            $tone = sanitize_text_field( (string) wp_unslash( $tone ) );
            if ( in_array( $tone, $valid, true ) ) {
                $out[] = $tone;
            }
            if ( count( $out ) >= 2 ) {
                break;
            }
        }
    }

    return $out ?: array( 'Neutral' );
}

/** Sanitize PRO-only bulk language list. */
function reeid_sanitize_bulk_langs( $input ) {

    if ( ! function_exists( 'reeid_is_premium' ) || ! reeid_is_premium() ) {
        return array();
    }

    $allowed = array_keys( reeid_get_supported_languages() );
    $out     = array();

    if ( is_array( $input ) ) {
        foreach ( $input as $lang ) {
            $lang = sanitize_text_field( (string) wp_unslash( $lang ) );
            if ( in_array( $lang, $allowed, true ) ) {
                $out[] = $lang;
            }
        }
    }

    return $out;
}


/*==============================================================================
  FIELD RENDERERS
==============================================================================*/

/** API key field */
function reeid_render_api_key_field() {

    $key    = get_option( 'reeid_openai_api_key', '' );
    $status = get_option( 'reeid_openai_status', '' );
    ?>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
        <input type="password"
            name="reeid_openai_api_key"
            value="<?php echo esc_attr( $key ); ?>"
            autocomplete="off"
            style="width:300px;"
            id="reeid_openai_api_key">

        <button type="button" class="button" id="reeid_validate_openai_key">
            <?php esc_html_e( 'Validate API Key', 'reeid-translate' ); ?>
        </button>
    </div>

    <div id="reeid_openai_key_status">
        <?php if ( $status === 'valid' ) : ?>
            <span style="color:green;font-weight:bold;">&#10004; <?php esc_html_e( 'Valid API Key', 'reeid-translate' ); ?></span>
        <?php elseif ( $status === 'invalid' ) : ?>
            <span style="color:red;font-weight:bold;">&#10060; <?php esc_html_e( 'Invalid API Key', 'reeid-translate' ); ?></span>
        <?php endif; ?>
    </div>
    <?php
}

/** (Disabled model field for completeness) */
function reeid_render_model_field() {

    $val     = 'gpt-4o';
    $options = array(
        'gpt-4o' => 'gpt-4o (Fast, affordable, precise)',
    );

    echo '<select name="reeid_openai_model" style="width:300px;" disabled>';
    foreach ( $options as $model => $label ) {
        printf(
            '<option value="%s"%s>%s</option>',
            esc_attr( $model ),
            selected( $val, $model, false ),
            esc_html( $label )
        );
    }
    echo '</select>';

    echo '<p class="description" style="margin-top:6px;">' .
        esc_html__( 'Model selection is locked to gpt-4o in this build.', 'reeid-translate' ) .
        '</p>';
}

/** Tone selection list */
function reeid_render_tone_checkbox_list() {

    $presets = array( 'Neutral', 'Formal', 'Informal', 'Friendly', 'Technical', 'Persuasive', 'Concise', 'Verbose' );
    $current = (array) get_option( 'reeid_translation_tones', array( 'Neutral' ) );
    ?>
    <select name="reeid_translation_tones[]" multiple size="8" style="width:300px;">
        <?php foreach ( $presets as $tone ) : ?>
            <option value="<?php echo esc_attr( $tone ); ?>" <?php selected( in_array( $tone, $current, true ) ); ?>>
                <?php echo esc_html( $tone ); ?>
            </option>
        <?php endforeach; ?>
    </select>

    <p class="description"><?php esc_html_e( 'Hold Ctrl (⌘) to select up to 2 tones.', 'reeid-translate' ); ?></p>
    <?php
}

/** Source language dropdown */
function reeid_render_source_lang_field() {

    $cur = get_option( 'reeid_translation_source_lang', 'en' );
    $all = reeid_get_supported_languages();

    echo '<select name="reeid_translation_source_lang" style="width:300px;">';
    foreach ( $all as $code => $label ) {
        printf(
            '<option value="%s"%s>%s</option>',
            esc_attr( $code ),
            selected( $cur, $code, false ),
            esc_html( $label )
        );
    }
    echo '</select>';
}

/** Custom prompt field */
function reeid_render_custom_prompt_field() {

    $val        = get_option( 'reeid_translation_custom_prompt', '' );
    $status     = get_option( 'reeid_license_status', 'invalid' );
    $is_premium = function_exists( 'reeid_is_premium' ) && reeid_is_premium();
    $placeholder = ( $status === 'valid' ) ? '' : esc_attr__( 'PRO feature – upgrade to unlock.', 'reeid-translate' );
    ?>
    <textarea name="reeid_translation_custom_prompt"
        rows="5"
        style="width:100%;"
        <?php echo $is_premium ? '' : 'readonly'; ?>
        placeholder="<?php echo esc_attr( $placeholder ); ?>"><?php echo esc_textarea( $val ); ?></textarea>

    <?php if ( ! $is_premium ) : ?>
        <p class="description" style="color:#b00;">
            <?php esc_html_e( 'Custom Instructions are available in the PRO version.', 'reeid-translate' ); ?>
        </p>
    <?php endif; ?>
    <?php
}

/** Bulk languages multiselect */
function reeid_render_bulk_langs_field() {

    $is_premium = function_exists( 'reeid_is_premium' ) && reeid_is_premium();
    $current    = (array) get_option( 'reeid_bulk_translation_langs', array() );
    $supported  = reeid_get_supported_languages();

    echo '<select name="reeid_bulk_translation_langs[]" multiple size="8" style="width:300px;" ' . ( $is_premium ? '' : 'disabled' ) . '>';

    foreach ( $supported as $code => $label ) {
        printf(
            '<option value="%s"%s>%s</option>',
            esc_attr( $code ),
            ( $is_premium && in_array( $code, $current, true ) ) ? ' selected' : '',
            esc_html( $label )
        );
    }
    echo '</select>';

    if ( $is_premium ) {
        echo '<p class="description">' .
            esc_html__( 'Hold Ctrl (⌘) to select multiple languages for bulk translation.', 'reeid-translate' ) .
            '</p>';
    } else {
        echo '<p class="description" style="color:#b00;">' .
            esc_html__( 'Bulk translation is a PRO feature. Upgrade to enable.', 'reeid-translate' ) .
            '</p>';
    }
}

/** License key input + status */
function reeid_render_license_key_field() {

    $key    = get_option( 'reeid_pro_license_key', '' );
    $status = get_option( 'reeid_license_status', 'invalid' );

    echo '<div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">';

    printf(
        '<input type="text" id="reeid_pro_license_key" name="reeid_pro_license_key" value="%s" style="width:300px;" placeholder="%s">',
        esc_attr( $key ),
        esc_attr__( 'Enter your PRO license key', 'reeid-translate' )
    );

    echo '</div>';

    echo '<div id="reeid_license_key_status" style="margin:4px 0 10px;font-weight:bold;">';
    if ( $status === 'valid' ) {
        echo '<span style="color:green;">&#10004; ' .
            esc_html__( 'License key is valid.', 'reeid-translate' ) .
            '</span>';
    } else {
        echo '<span style="color:red;">&#10060; ' .
            esc_html__( 'License key is invalid. Only basic functionality is available.', 'reeid-translate' ) .
            '</span>';
    }
    echo '</div>';

    if ( $status !== 'valid' ) {
        $pro_url = 'https://reeid.com/reeid-translation-plugin/';
        echo '<a href="' . esc_url( $pro_url ) . '" target="_blank" rel="noopener" ' .
            'style="display:inline-block;margin-top:4px;padding:6px 14px;background:#ec6d00;color:#fff;' .
            'font-weight:600;border-radius:4px;text-decoration:none;font-size:13px;">&#128275; ' .
            esc_html__( 'Get PRO Version', 'reeid-translate' ) .
            '</a>';
    }
}

/* ===========================================================
 * Canonical: get enabled target languages from Admin Settings
 * Used by: Gutenberg/Classic/Woo bulk + Elementor bulk + background jobs
 * =========================================================== */
if ( ! function_exists( 'reeid_get_enabled_languages' ) ) {
    function reeid_get_enabled_languages(): array {

        static $cache = null;
        if ( is_array( $cache ) ) {
            return $cache;
        }

        /* -------- 1) Candidate option names -------- */
        $candidates = array(
            'reeid_bulk_translation_langs',
            'reeid_bulk_languages',
            'reeid_enabled_languages',
            'reeid_translation_target_languages',
            'reeid_translation_languages',
        );

        $acc = array();

        foreach ( $candidates as $opt ) {
            $val = get_option( $opt, array() );

            if ( is_array( $val ) ) {
                foreach ( $val as $v ) {
                    $acc[] = (string) $v;
                }
            } elseif ( is_string( $val ) && $val !== '' ) {
                $parts = array_map( 'trim', explode( ',', $val ) );
                foreach ( $parts as $p ) {
                    $acc[] = (string) $p;
                }
            }
        }

        /* -------- 2) Sanitize language codes -------- */
        $acc = array_map(
            static function( $lang ) {
                $lang = strtolower( trim( (string) $lang ) );
                return preg_replace( '/[^a-z0-9\-_]/i', '', $lang );
            },
            $acc
        );

        /* -------- 3) Remove empty -------- */
        $acc = array_values( array_filter( $acc, static fn( $l ) => $l !== '' ) );

        /* -------- 4) Intersect with supported -------- */
        if ( function_exists( 'reeid_get_supported_languages' ) ) {
            $supported = array_keys( (array) reeid_get_supported_languages() );
            $acc       = array_values( array_intersect( $acc, $supported ) );
        }

        /* -------- 5) Cache & return -------- */
        $cache = $acc;
        return $acc;
    }
}
