<?php
/**
 * Elementor Panel Enhancements — Alerts and Editor JS Patches
 * REEID Translate — modularized Section 25
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*==============================================================================
  SECTION 25: INJECT BLOCK ALERTS INTO ELEMENTOR EDITOR PANEL (iframe safe)
==============================================================================*/

add_action('elementor/editor/init', function () {
    add_action('admin_print_footer_scripts', function () {
?>
        <script id="reeid-elementor-translate-blocker">
            (function() {
                if (window.__reeid_block_alert_loaded) return;
                window.__reeid_block_alert_loaded = true;

                function showElementorAlert(msg) {
                    if (
                        typeof elementor !== 'undefined' &&
                        elementor.notifications &&
                        typeof elementor.notifications.show === 'function'
                    ) {
                        elementor.notifications.show({
                            message: msg,
                            type: 'error'
                        });
                    } else {
                        alert(msg);
                    }
                }

                // Patch fetch
                var oldFetch = window.fetch;
                window.fetch = function() {
                    return oldFetch.apply(this, arguments).then(function(resp) {
                        if (
                            !resp.ok &&
                            resp.headers.get('content-type') &&
                            resp.headers.get('content-type').indexOf('application/json') !== -1
                        ) {
                            resp.clone().json().then(function(data) {
                                var message = (data?.message || data?.data?.message || '');
                                if (/v1\/translate.*(already|child).*translation/i.test(message)) {
                                    showElementorAlert(message);
                                }
                            });
                        }
                        return resp;
                    });
                };

                // Patch XMLHttpRequest
                var OldXHR = window.XMLHttpRequest;
                function NewXHR() {
                    var xhr = new OldXHR();
                    xhr.addEventListener('load', function() {
                        if (
                            this.status >= 400 &&
                            this.getResponseHeader('content-type') &&
                            this.getResponseHeader('content-type').indexOf('application/json') !== -1
                        ) {
                            try {
                                var data = JSON.parse(this.responseText);
                                var message = (data?.message || data?.data?.message || '');
                                if (/v1\/translate.*(already|child).*translation/i.test(message)) {
                                    showElementorAlert(message);
                                }
                            } catch (e) {}
                        }
                    }, false);
                    return xhr;
                }
                window.XMLHttpRequest = NewXHR;

            })();
        </script>
<?php
    });
});

