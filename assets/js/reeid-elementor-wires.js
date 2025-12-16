/**
 * REEID — Elementor wiring (small, robust)
 * - Listens for clicks on translate buttons in Elementor panel or front-end
 * - Sends AJAX to admin-ajax.php?action=reeid_translate_openai with nonce
 * - Emits custom events on success / failure for other scripts to hook
 * - Adds simple visual state classes to the clicked button
 */

(function ($, root) {
    'use strict';

    var selectors = [
        '.reeid-elementor-translate',
        '.reeid-translate-btn',
        '[data-reeid-action="translate"]',
        '[data-reeid-elementor-translate]'
    ].join(',');

    function findPostId($btn) {
        // try several fallbacks in order
        var id = null;
        if ($btn && $btn.data && $btn.data('post-id')) id = $btn.data('post-id');
        if (!id && window.elementor && window.elementor.config && window.elementor.config.post_id) id = window.elementor.config.post_id;
        if (!id) {
            var $f = $('#post_ID, input[name="post_ID"]');
            if ($f.length) id = $f.val();
        }
        if (!id) {
            // try URL param "post" or "post_id"
            try {
                var params = new URLSearchParams(location.search);
                id = params.get('post') || params.get('post_id') || id;
            } catch (e) { /* ignore */ }
        }
        return id ? parseInt(id, 10) : null;
    }

    function sendTranslateRequest(payload) {
        return $.ajax({
            url: REEID_TRANSLATE.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: payload,
            timeout: 120000
        });
    }

    function setBtnState($btn, state, text) {
        $btn.removeClass('reeid-translate-loading reeid-translate-success reeid-translate-failed');
        if (state) $btn.addClass('reeid-translate-' + state);
        if (text !== undefined) {
            // preserve original text in data attribute
            if (!$btn.data('reeid-orig-text')) $btn.data('reeid-orig-text', $btn.text());
            $btn.text(text);
        }
        if (!state && $btn.data('reeid-orig-text')) {
            $btn.text($btn.data('reeid-orig-text'));
            $btn.removeData('reeid-orig-text');
        }
    }

    // Delegated click handler — robust for dynamically inserted panel buttons
    $(document).on('click', selectors, function (ev) {
        ev.preventDefault();
        var $btn = $(this);
        if ($btn.data('reeid-busy')) return;

        var post_id = findPostId($btn);
        if (!post_id) {
            console.warn('REEID: no post_id found for translate action');
        }

        var target_lang = $btn.data('lang') || $btn.attr('data-lang') || ( $('#reeid-target-lang').val && $('#reeid-target-lang').val() ) || '';
        var tone = $btn.data('tone') || $btn.attr('data-tone') || 'Neutral';
        var prompt = $btn.data('prompt') || $btn.attr('data-prompt') || '';

        var action = $btn.data('ajax-action') || $btn.attr('data-ajax-action') || 'reeid_translate_openai';

        var payload = {
            action: action,
            post_id: post_id,
            target_lang: target_lang,
            tone: tone,
            prompt: prompt,
            nonce: (window.REEID_TRANSLATE && window.REEID_TRANSLATE.nonce) ? window.REEID_TRANSLATE.nonce : ''
        };

        // Allow additional data-* attributes to be forwarded automatically
        // e.g., data-extra-user="foo" -> payload.extra_user = 'foo'
        $.each($btn.get(0).attributes, function () {
            if (!this) return;
            var name = this.name || '';
            if (name.indexOf('data-') === 0 && ['data-reeid-action','data-post-id','data-lang','data-tone','data-prompt','data-ajax-action'].indexOf(name) === -1) {
                var key = name.replace(/^data-/, '').replace(/-([a-z])/g, function (m, ch) { return ch.toUpperCase(); });
                payload[key] = this.value;
            }
        });

        // UI: busy
        $btn.data('reeid-busy', true);
        setBtnState($btn, 'loading', $btn.data('busy-text') || 'Translating…');

        // Fire a "start" event
        document.dispatchEvent(new CustomEvent('reeid:elementor:translate:start', { detail: { button: $btn.get(0), payload: payload } }));

            payload.lang = payload.target_lang || payload.lang || "";
            payload.source = payload.source || payload.source_lang || payload.src || (window.REEID_TRANSLATE && window.REEID_TRANSLATE.source_lang) || "";
        sendTranslateRequest(payload).done(function (res) {
            // res is expected to be WP JSON (success / error)
            if (res && res.success) {
                setBtnState($btn, 'success', $btn.data('success-text') || 'Translated');
                document.dispatchEvent(new CustomEvent('reeid:elementor:translate:success', { detail: { button: $btn.get(0), response: res } }));
                // optional: if Elementor panel is present, try to refresh preview
                if (window.elementor && window.elementor.channels && window.elementor.channels.preview) {
                    try { window.elementor.channels.preview.trigger('document:reload'); } catch (e) { /* ignore */ }
                }
            } else {
                var errorData = (res && res.data) ? res.data : res;
                setBtnState($btn, 'failed', $btn.data('fail-text') || 'Failed');
                document.dispatchEvent(new CustomEvent('reeid:elementor:translate:failed', { detail: { button: $btn.get(0), response: res } }));
                console.error('REEID translate failed:', errorData);
            }
        }).fail(function (jqXHR, textStatus, errorThrown) {
            setBtnState($btn, 'failed', $btn.data('fail-text') || 'Failed');
            document.dispatchEvent(new CustomEvent('reeid:elementor:translate:failed', { detail: { button: $btn.get(0), jqXHR: jqXHR, textStatus: textStatus, errorThrown: errorThrown } }));
            console.error('REEID AJAX error:', textStatus, errorThrown);
        }).always(function () {
            $btn.data('reeid-busy', false);
            // optionally restore original text after short delay
            setTimeout(function () {
                setBtnState($btn, null);
            }, 2500);
        });

    });

    // Expose a small programmatic helper
    root.reeidElementor = root.reeidElementor || {};
    root.reeidElementor.translate = function (opts) {
        // opts: { post_id, target_lang, tone, prompt, buttonSelector }
        var $btn = $(opts.buttonSelector || selectors).first();
        if (!$btn.length) {
            $btn = $('<button/>', { type: 'button', class: 'reeid-translate-btn', text: 'Translate (auto)' }).appendTo('body').hide();
        }
        if (opts.post_id) $btn.data('post-id', opts.post_id);
        if (opts.lang) $btn.data('lang', opts.lang);
        if (opts.tone) $btn.data('tone', opts.tone);
        if (opts.prompt) $btn.data('prompt', opts.prompt);
        $btn.trigger('click');
    };

})(jQuery, window);
