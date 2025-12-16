// ===============================
// FAQ ACCORDION
// ===============================
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.reeid-faq-q').forEach(function(btn){
        btn.addEventListener('click', function() {
            var item = btn.closest('.reeid-faq-item');
            if (!item) return;

            var panel = item.querySelector('.reeid-faq-a');
            if (!panel) return;

            var expanded = btn.getAttribute('aria-expanded') === 'true';

            // Only close others in same column
            var col = item.parentNode;
            if (col) {
                col.querySelectorAll('.reeid-faq-item.open').forEach(function(openItem){
                    if (openItem !== item) {
                        openItem.classList.remove('open');
                        var q = openItem.querySelector('.reeid-faq-q');
                        var a = openItem.querySelector('.reeid-faq-a');
                        if (q) q.setAttribute('aria-expanded', 'false');
                        if (a) a.setAttribute('hidden', 'hidden');
                    }
                });
            }

            if (expanded) {
                btn.setAttribute('aria-expanded', 'false');
                panel.setAttribute('hidden', 'hidden');
                item.classList.remove('open');
            } else {
                btn.setAttribute('aria-expanded', 'true');
                panel.removeAttribute('hidden');
                item.classList.add('open');
            }
        });
    });
});

// ===============================
// LICENSE KEY VALIDATION BUTTON
// (no AJAX – uses stored status from PHP)
// ===============================
jQuery(document).ready(function($){
    $(document).on('click', '#reeid_validate_key', function(e){
        e.preventDefault();

        var $btn    = $(this);
        var $input  = $('input[name="reeid_pro_license_key"]');
        var key     = $.trim($input.val() || '');
        var $status = $btn.closest('div').next(); // div right below input+button

        if (!$status.length) {
            $status = $('#reeid_license_key_status');
        }

        function setStatus(message, type) {
            var color = '';
            if (type === 'ok') {
                color = 'green';
            } else if (type === 'error') {
                color = 'red';
            } else {
                color = '';
            }
            if ($status.length) {
                if (color) {
                    $status.css('color', color);
                }
                $status.html(message);
            }
        }

        if (!key || key.length < 6) {
            setStatus('❌ Please enter your license key and click “Save Changes” first.', 'error');
            return;
        }

        // Just reflect what PHP already knows from SECTION 12:
        var status = (window.REEID_TRANSLATE && REEID_TRANSLATE.license_status) || 'invalid';
        var msg    = (window.REEID_TRANSLATE && REEID_TRANSLATE.license_msg) || '';

        if (!msg) {
            msg = (status === 'valid')
                ? 'License key is valid for this domain.'
                : 'License key is invalid or not active.';
        }

        if (status === 'valid') {
            setStatus('✔ ' + msg.replace(/^✔\s*/,'').replace(/^❌\s*/,''), 'ok');
        } else {
            setStatus('❌ ' + msg.replace(/^✔\s*/,'').replace(/^❌\s*/,''), 'error');
        }
    });
});
