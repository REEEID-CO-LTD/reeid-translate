<?php
/**
 * REEID — WooCommerce Attribute Runtime (INLINE MODE)
 *
 * - Runtime-only (NO DB writes)
 * - Uses _reeid_wc_tr_{lang}['attributes']
 * - Source language resolved from plugin setting
 * - Frontend render layer ONLY
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Debug marker (safe, optional)
 */
add_action('wp_footer', function () {
    echo '<!-- REEID LANG = ' .
        esc_html(
            function_exists('reeid_wc_current_lang')
                ? reeid_wc_current_lang()
                : 'NO_FUNC'
        ) .
    ' -->';
});

/**
 * ============================================================
 * ATTRIBUTE VALUE RUNTIME OVERRIDE (RENDER LAYER)
 * ============================================================
 *
 * Hook: woocommerce_display_product_attributes
 * Purpose:
 *  - Replace rendered attribute VALUES per language
 *  - Does NOT touch _product_attributes in DB
 */
add_filter(
    'woocommerce_display_product_attributes',
    function ( $rows, $product ) {

        // 🔒 FRONTEND ONLY
        if ( is_admin() || wp_doing_ajax() ) {
            return $rows;
        }

        if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
            return $rows;
        }

        if ( ! function_exists( 'reeid_wc_current_lang' ) ) {
            return $rows;
        }

        $lang = reeid_wc_current_lang();

        $source_lang = strtolower(
            (string) get_option( 'reeid_translation_source_lang', 'en' )
        );

        // ❗ Respect plugin setting — NO hardcoding
        if ( ! $lang || $lang === $source_lang ) {
            return $rows;
        }

        $pid = (int) $product->get_id();
        if ( ! $pid ) {
            return $rows;
        }

        $packet = get_post_meta( $pid, '_reeid_wc_tr_' . $lang, true );

        if (
            ! is_array( $packet ) ||
            empty( $packet['attributes'] ) ||
            ! is_array( $packet['attributes'] )
        ) {
            return $rows;
        }

        foreach ( $rows as $key => $row ) {

            if ( empty( $row['label'] ) ) {
                continue;
            }

            $label = $row['label'];

            if ( isset( $packet['attributes'][ $label ] ) ) {
                // ✅ Replace rendered VALUE only
                $rows[ $key ]['value'] = esc_html(
                    (string) $packet['attributes'][ $label ]
                );
            }
        }

        return $rows;
    },
    20,
    2
);

/**
 * ============================================================
 * ATTRIBUTE LABEL RUNTIME OVERRIDE
 * ============================================================
 *
 * Hook: woocommerce_attribute_label
 * Purpose:
 *  - Replace rendered attribute LABEL per language
 *  - Uses same inline packet as values
 */
add_filter(
    'woocommerce_attribute_label',
    function ( $label, $name ) {

        // 🔒 FRONTEND ONLY
        if ( is_admin() || wp_doing_ajax() ) {
            return $label;
        }

        if ( ! function_exists( 'reeid_wc_current_lang' ) ) {
            return $label;
        }

        $lang = reeid_wc_current_lang();
        if ( ! $lang ) {
            return $label;
        }

        global $product;
        if ( ! ( $product instanceof WC_Product ) ) {
            return $label;
        }

        $pid = (int) $product->get_id();
        if ( ! $pid ) {
            return $label;
        }

        $packet = get_post_meta( $pid, '_reeid_wc_tr_' . $lang, true );

        if (
            ! is_array( $packet ) ||
            empty( $packet['attributes'] ) ||
            ! is_array( $packet['attributes'] )
        ) {
            return $label;
        }

        // Match original label → translated label
        foreach ( $packet['attributes'] as $translated_label => $_ ) {
            if (
                strcasecmp( $label, $name ) === 0 ||
                strcasecmp( $label, $translated_label ) === 0
            ) {
                return $translated_label;
            }
        }

        return $label;
    },
    20,
    2
);
