<?php
if ( ! defined( 'ABSPATH' ) ) exit;



/*==============================================================================
 License Gate — Woo Translation Options (admin + runtime)
  - Scopes: product "Translations" UI, meta writes, runtime swaps, label maps, blocks i18n
  - Honors existing helpers if present: reeid_license_ok() / reeid_is_license_valid()
  - Options fallback:  reeid_license_status = 'valid'; reeid_license_expires (UTC ISO8601)
  - Toggle runtime gating via filter:  add_filter('reeid/license/gate_runtime', '__return_true');
  - Nuke debug prefix: "S24.7".
==============================================================================*/

    if (! function_exists('reeid_s247_log')) {
        function reeid_s247_log($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S24.7 ' . $label, $data);
            }
        }
    }

    /** ---- License evaluator -------------------------------------------------- */
    if (! function_exists('reeid_s247_license_valid')) {
        function reeid_s247_license_valid(): bool
        {
            // 0) Hard override via constant (for staging/testing)
            if (defined('REEID_LICENSE_FORCE')) {
                $ok = (bool) REEID_LICENSE_FORCE;
                reeid_s247_log('FORCE_CONST', $ok);
                return $ok;
            }
            // 1) Existing helpers from your licensing module
            if (function_exists('reeid_license_ok')) {
                $ok = (bool) reeid_license_ok();
                reeid_s247_log('HELPER_license_ok', $ok);
                return $ok;
            }
            if (function_exists('reeid_is_license_valid')) {
                $ok = (bool) reeid_is_license_valid();
                reeid_s247_log('HELPER_is_license_valid', $ok);
                return $ok;
            }
            // 2) Options fallback
            $status  = (string) get_option('reeid_license_status', '');
            $expires = (string) get_option('reeid_license_expires', '');
            $ok = ($status === 'valid');
            if ($ok && $expires) {
                $ts = strtotime($expires);
                if ($ts && time() > $ts) {
                    $ok = false;
                }
            }
            //reeid_s247_log('OPTIONS_STATUS', ['status'=>$status, 'expires'=>$expires, 'ok'=>$ok]);
            // 3) Filter for last-mile overrides
            $ok = (bool) apply_filters('reeid/license/allow', $ok);
            return $ok;
        }
    }

    /** ---- Global gate flags -------------------------------------------------- */
    if (! function_exists('reeid_s247_gate_active')) {
        function reeid_s247_gate_active(): bool
        {
            $active = ! reeid_s247_license_valid();
            // Let admins bypass in wp-admin if desired
            if ($active && is_admin() && current_user_can('manage_options')) {
                $active = (bool) apply_filters('reeid/license/admin_enforce', true);
            }
            return $active;
        }
    }

    /** ---- Admin: hide product "Translations" tab + panel -------------------- */
    
    add_filter('woocommerce_product_data_tabs', function (array $tabs) {
        if (! reeid_s247_gate_active()) return $tabs;
        if (isset($tabs['reeid_translations'])) {
            unset($tabs['reeid_translations']);
            reeid_s247_log('TAB_HIDDEN', true);
        }
        return $tabs;
    }, 1000);

    add_action('admin_head', function () {
        if (! reeid_s247_gate_active()) return;
        // Hide any legacy panel output and gray out badges/inputs if present
        ?>
        <style id="reeid-s247-admin-css">
            #reeid_translations_panel {
                display: none !important;
            }

            .reeid-tr-badge,
            .reeid-tr-status-select,
            #reeid-tr-langselect {
                opacity: .5;
                pointer-events: none;
            }
        </style>
    <?php
    });

    

    /** ---- Frontend: optional runtime gating (falls back to source EN) --------
     * Enabled by default (safer for licensing). Turn off with:
     *    add_filter('reeid/license/gate_runtime', '__return_false');
     */
    add_action('init', function () {
        if (! apply_filters('reeid/license/gate_runtime', true)) return;
        if (! reeid_s247_gate_active()) return;

        reeid_s247_log('RUNTIME_GATE_ON', true);

        // (A) Woo product getters — force source content at the end (priority 1000)
        add_filter('woocommerce_product_get_name', function ($val, $product) {
            try {
                $p = get_post($product ? $product->get_id() : 0);
                if ($p && $p->post_type === 'product') {
                    return (string) $p->post_title;
                }
            } catch (\Throwable $e) {
                reeid_s247_log('GET_NAME_ERR', $e->getMessage());
            }
            return $val;
        }, 5, 2);

        // (B) the_title fallback on single-product templates
        add_filter('the_title', function ($title, $post_id) {
            try {
                $p = get_post($post_id);
                if ($p && $p->post_type === 'product' && ! is_admin()) {
                    return (string) $p->post_title;
                }
            } catch (\Throwable $e) {
                reeid_s247_log('TITLE_ERR', $e->getMessage());
            }
            return $title;
        }, 5, 2);

                /** (WC inline the_content override removed — handled in rt-wc-i18n-lite.php) */


        // (D) PHP gettext overrides (labels) — revert to original English in admin
        add_filter('gettext', function ($translated, $text, $domain) {
            // If we're in wp-admin and this is a WooCommerce domain string,
            // return the original English source to avoid translating admin labels.
            if (is_admin() && $domain === 'woocommerce') {
                return $text; // original English source
            }
            return $translated;
        }, 1000, 3);

        add_filter('gettext_with_context', function ($translated, $text, $context, $domain) {
            if (is_admin() && $domain === 'woocommerce') {
                return $text; // ignore context, keep source
            }
            return $translated;
        }, 1000, 4);

        add_filter('ngettext', function ($translated, $single, $plural, $number, $domain) {
            if (is_admin() && $domain === 'woocommerce') {
                return (absint($number) === 1) ? $single : $plural;
            }
            return $translated;
        }, 1000, 5);

        // (E) JS i18n (Blocks) — neutralize any prior localeData by resetting to EN
        add_action('wp_enqueue_scripts', function () {
            if (is_admin() || wp_doing_ajax()) return;
            if (! ((function_exists('is_cart') && is_cart()) || (function_exists('is_checkout') && is_checkout()))) return;
            wp_enqueue_script('wp-i18n');
            $js = 'try{if(window.wp&&wp.i18n&&wp.i18n.setLocaleData){' .
                'wp.i18n.setLocaleData({"":{"domain":"woocommerce"}},"woocommerce");' .
                'wp.i18n.setLocaleData({"":{"domain":"woocommerce-blocks"}},"woocommerce-blocks");' .
                '}}catch(e){console&&console.warn&&console.warn("[S24.7] reset i18n failed",e);}';
            wp_add_inline_script('wp-i18n', $js, 'after');
            reeid_s247_log('BLOCKS_RESET_EN', true);
        }, 21);
    }, 5);

    /** ---- Admin notice (only when unlicensed) ------------------------------- */
add_action('admin_notices', function () {
    if (! is_admin() || ! reeid_s247_gate_active()) return;
    if (! current_user_can('manage_options')) return;

    $settings_url  = esc_url( admin_url('options-general.php') );
    $settings_link = '<a href="' . $settings_url . '">' . esc_html__( 'Settings', 'reeid-translate' ) . '</a>';

    $p1 = sprintf(
    /* translators: %1$s is the HTML link to the WooCommerce settings page (HTML <a>). */
    __(
        '<strong>REEID Translation:</strong> Woo translation options are disabled until a valid license is activated. Enter or activate your license in %1$s.',
        'reeid-translate'
    ),
    $settings_link
);


    $p2 = sprintf(
            // translators: %1$s is the option name (wrapped in <code> tags); %2$s is the expected value (wrapped in <code> tags).
        __('If you already have a valid license, ensure the status option %1$s is set to %2$s.', 'reeid-translate'),
        '<code>' . esc_html( 'reeid_license_status' ) . '</code>',
        '<code>' . esc_html( 'valid' ) . '</code>'
    );

    // Allow only a small set of tags that we intentionally output (a, strong, code, p).
    $allowed = [
        'a'      => [ 'href' => true ],
        'strong' => [],
        'code'   => [],
        'p'      => [],
    ];

    $notice_html = '<p>' . $p1 . '</p><p>' . $p2 . '</p>';

    printf(
        '<div class="%s">%s</div>',
        esc_attr( 'notice notice-warning' ),
        wp_kses( $notice_html, $allowed )
    );
});


    /** ---- Quick probe: append ?reeid_license_test=1 to any URL to log/echo ---- */
    add_action('template_redirect', function () {
        if (empty($_GET['reeid_license_test'])) return;
        $valid = reeid_s247_license_valid();
        $gate  = reeid_s247_gate_active();
        reeid_s247_log('TEST', ['valid' => $valid, 'gate' => $gate]);
        if (! headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo wp_json_encode(['valid' => $valid, 'gate' => $gate]);
        exit;
    }, 9);
