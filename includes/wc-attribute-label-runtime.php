<?php
/**
 * REEID — WooCommerce Attribute LABEL Runtime Override
 * Fixes translated attribute labels using inline meta:
 *   _reeid_wc_tr_<lang>['attributes']
 */

if ( ! defined('ABSPATH') ) {
    exit;
}

add_filter(
    'woocommerce_attribute_label',
    function ( $label, $name, $product ) {

        if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
            return $label;
        }

        if ( ! function_exists( 'reeid_wc_current_lang' ) ) {
            return $label;
        }

        $lang = reeid_wc_current_lang();
        if ( ! $lang || $lang === 'en' ) {
            return $label;
        }

        $pid = (int) $product->get_id();
        if ( $pid <= 0 ) {
            return $label;
        }

        $pl = get_post_meta( $pid, '_reeid_wc_tr_' . $lang, true );
        if (
            empty( $pl ) ||
            ! is_array( $pl ) ||
            empty( $pl['attributes'] ) ||
            ! is_array( $pl['attributes'] )
        ) {
            return $label;
        }

        /*
         * $name is the raw attribute name (e.g. "Item properties 234")
         * Match against stored translated attributes
         */
        if ( isset( $pl['attributes'][ $name ] ) ) {
            return (string) $name; // label already translated & stored as key
        }

        return $label;

    },
    10,
    3
);
