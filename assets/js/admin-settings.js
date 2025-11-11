document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.reeid-faq-q').forEach(function(btn){
        btn.addEventListener('click', function() {
            var item = btn.closest('.reeid-faq-item');
            var panel = item.querySelector('.reeid-faq-a');
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            // Only close others in same column
            var col = item.parentNode;
            col.querySelectorAll('.reeid-faq-item.open').forEach(function(openItem){
                if (openItem !== item) {
                    openItem.classList.remove('open');
                    openItem.querySelector('.reeid-faq-q').setAttribute('aria-expanded', 'false');
                    openItem.querySelector('.reeid-faq-a').setAttribute('hidden', 'hidden');
                }
            });
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

jQuery(document).ready(function($){
    $(document).on('click', '#reeid_validate_key', function(e){
        e.preventDefault();
        var $btn = $(this);
        var $input = $('input[name="reeid_pro_license_key"]');
        var key = $input.val();
        var $status = $btn.closest('div').next(); // div right below input+button

        if (!key || key.length < 6) {
            $status.html('<span style="color:red;font-weight:bold;">❌ Please enter your license key.</span>');
            return;
        }

        $btn.prop('disabled', true).text('Validating...');
        $status.html('⏳ Validating...');

        $.post(
            REEID_TRANSLATE.ajaxurl,
            {
                action: 'reeid_validate_license_key',
                key: key,
                nonce: REEID_TRANSLATE.nonce
            },
            function(resp) {
                $btn.prop('disabled', false).text('Validate License Key');
                if (resp.success) {
                    $status.html('<span style="color:green;font-weight:bold;">✔ ' + resp.data.message + '</span>');
                } else {
                    $status.html('<span style="color:red;font-weight:bold;">❌ ' + (resp.data && resp.data.message ? resp.data.message : 'Validation failed.') + '</span>');
                }
            }
        ).fail(function(xhr){
            $btn.prop('disabled', false).text('Validate License Key');
            $status.html('<span style="color:red;font-weight:bold;">❌ AJAX error: ' + xhr.status + '</span>');
        });
    });
});

