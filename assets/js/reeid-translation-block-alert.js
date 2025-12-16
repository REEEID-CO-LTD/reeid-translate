(function() {
    // Helper: Show universal modal alert, supports all editors/iframes
    function showUniversalAlert(msg) {
        // Only one alert at a time
        if (document.getElementById('reeid-block-alert')) return;
        var div = document.createElement('div');
        div.id = 'reeid-block-alert';
        div.style.position = 'fixed';
        div.style.zIndex = 999999;
        div.style.left = 0; div.style.top = 0; div.style.width = '100vw'; div.style.height = '100vh';
        div.style.background = 'rgba(30,30,30,0.65)';
        div.style.display = 'flex'; div.style.alignItems = 'center'; div.style.justifyContent = 'center';
        div.innerHTML = '<div style="background:#fff;max-width:420px;border-radius:8px;padding:32px 24px;box-shadow:0 8px 28px #1114; font-size:1.14em; line-height:1.6;">'
            + '<b>Translation Blocked</b><hr style="margin:12px 0;">'
            + msg
            + '<br><br><button id="reeid-block-close" style="margin-top:10px;padding:7px 20px;font-size:1em;cursor:pointer;border-radius:5px;background:#457aff;color:#fff;border:none;">OK</button></div>';
        document.body.appendChild(div);
        document.getElementById('reeid-block-close').onclick = function() {
            div.remove();
        };
    }

    // Intercept ALL AJAX responses for "reeid_translate_openai"
    function hookAjaxBlock() {
        // Patch fetch for modern editors (Gutenberg/Elementor)
        var origFetch = window.fetch;
        window.fetch = function() {
            return origFetch.apply(this, arguments).then(function(resp) {
                // Clone so body can be read twice
                var r2 = resp.clone();
                r2.json().then(function(data) {
                    if (data && data.data && data.data.message && /v1/translate.*already.*translation/i.test(data.data.message)) {
                        showUniversalAlert(data.data.message);
                    }
                }).catch(function(){});
                return resp;
            });
        };
        // Patch jQuery.ajax for Classic/meta box
        if (window.jQuery) {
            var origAjax = jQuery.ajax;
            jQuery.ajax = function(opts) {
                var origSuccess = opts.success;
                opts.success = function(data) {
                    if (data && data.data && data.data.message && /v1/translate.*already.*translation/i.test(data.data.message)) {
                        showUniversalAlert(data.data.message);
                    }
                    if (origSuccess) origSuccess.apply(this, arguments);
                };
                return origAjax.call(this, opts);
            };
        }
    }

    // Run in top window or iframe
    try { hookAjaxBlock(); } catch(e){}

    // Elementor iframe: run in panel too (try/catch for safety)
    if (window !== window.parent) {
        t
