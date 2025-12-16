<?php
if ( ! defined('ABSPATH') ) exit;

function reeid_validate_license( $license_key = '' ) {

    $license_key = $license_key
        ? $license_key
        : trim( (string) get_option('reeid_license_key', '') );

    if ($license_key === '') {
        update_option('reeid_license_status', 'invalid');
        update_option('reeid_license_last_msg', 'Empty license key');
        return false;
    }

    $domain = wp_parse_url(home_url(), PHP_URL_HOST);

    $resp = wp_remote_post(
        'https://reeid.com/validate-license.php',
        [
            'timeout' => 15,
            'body' => [
                'license_key' => $license_key,
                'domains'     => $domain,
            ],
        ]
    );

    if (is_wp_error($resp)) {
        update_option('reeid_license_status', 'invalid');
        update_option('reeid_license_last_msg', $resp->get_error_message());
        return false;
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $body = (string) wp_remote_retrieve_body($resp);

    update_option('reeid_license_last_code', $code);
    update_option('reeid_license_last_raw', substr($body, 0, 800));

    if ($code !== 200 || $body === '') {
        update_option('reeid_license_status', 'invalid');
        update_option('reeid_license_last_msg', 'HTTP error');
        return false;
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        update_option('reeid_license_status', 'invalid');
        update_option('reeid_license_last_msg', 'Non-JSON response');
        return false;
    }

    $ok = ! empty($data['valid']);
    update_option('reeid_license_status', $ok ? 'valid' : 'invalid');
    update_option('reeid_license_last_msg', (string) ($data['message'] ?? ''));

    return $ok;
}
