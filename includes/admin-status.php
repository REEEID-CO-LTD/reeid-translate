<?php
defined('ABSPATH') || exit;

/**
 * Simple admin metabox showing translation job status and a 'Queue' button.
 * Uses reeid_translation_job_get_latest_for_post() and enqueues a job via admin-ajax (Section 18).
 */

add_action('add_meta_boxes', function() {
    $types = array('post','page','product'); // adjust if you want other CPTs
    foreach ( $types as $t ) {
        add_meta_box('reeid_translation_status', __('REEID Translation Status','reeid-translate'), 'reeid_render_translation_status_metabox', $t, 'side', 'high');
    }
});

function reeid_render_translation_status_metabox($post) {
    // Source map and supported languages (map stores target->post_id)
    $map = (array) get_post_meta($post->ID, '_reeid_translation_map', true);
    if ( ! is_array($map) ) $map = array();

    echo '<div id="reeid-translation-metabox">';

    if ( empty($map) ) {
        echo '<p>' . __('No translations found for this source post.', 'reeid-translate') . '</p>';
    } else {
        echo '<table style="width:100%;font-size:13px;">';
        echo '<thead><tr><th style="text-align:left">' . esc_html__('Lang','reeid-translate') . '</th><th style="text-align:left">' . esc_html__('Status','reeid-translate') . '</th><th></th></tr></thead><tbody>';
        foreach ( $map as $lang => $tid ) {
            $status = function_exists('reeid_translation_job_get_status') ? reeid_translation_job_get_status($post->ID, $lang, 'single') : false;
            $stat_label = $status ? esc_html(ucfirst($status['status'])) : esc_html__('none','reeid-translate');
            $updated = ($status && ! empty($status['updated_at'])) ? ' <small>(' . esc_html($status['updated_at']) . ')</small>' : '';
            echo '<tr>';
            echo '<td style="vertical-align:top">' . esc_html($lang) . '</td>';
            echo '<td style="vertical-align:top">' . $stat_label . $updated . '</td>';
            echo '<td style="vertical-align:top">';
            printf(
                '<button class="button button-small reeid-queue-translate" data-post="%d" data-lang="%s">%s</button>',
                (int)$post->ID,
                esc_attr($lang),
                esc_html__('Queue','reeid-translate')
            );
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    // Quick ad-hoc translate: allow entering a language not in map
    echo '<hr style="margin:8px 0">';
    echo '<label for="reeid_target_lang">' . esc_html__('Queue new language','reeid-translate') . '</label><br>';
    echo '<input type="text" id="reeid_target_lang" placeholder="de" style="width:60px;margin-right:6px">';
    printf('<button class="button button-small" id="reeid-queue-new" data-post="%d">%s</button>', (int)$post->ID, esc_html__('Queue','reeid-translate'));

    // Status output
    echo '<div id="reeid-queue-result" style="margin-top:8px;font-size:13px"></div>';

    // JS (inline, minimal)
    ?>
    <script>
    (function(){
        function postAjax(data, cb){
            var xhr = jQuery.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                method: 'POST',
                data: data,
            });
            xhr.done(function(res){ try{ cb(null, res); }catch(e){ cb(e); } });
            xhr.fail(function(jqXHR, status, err){ cb(err || status); });
        }

        jQuery(document).on('click', '.reeid-queue-translate', function(e){
            e.preventDefault();
            var btn = jQuery(this);
            var post_id = btn.data('post');
            var lang = btn.data('lang');
            btn.prop('disabled', true).text('Queuing...');
            postAjax({ action: 'reeid_translate_openai', post_id: post_id, lang: lang }, function(err, res){
                btn.prop('disabled', false).text('Queue');
                var out = document.getElementById('reeid-queue-result');
                if (err) { out.innerText = 'Error: ' + err; return; }
                if (typeof res === 'object' && res.success) {
                    out.innerHTML = '<span style="color:green">Queued: ' + (res.data.job_id || '') + '</span>';
                } else if (typeof res === 'object' && res.error) {
                    out.innerHTML = '<span style="color:red">' + (res.error || 'error') + '</span>';
                } else {
                    out.innerHTML = '<pre>' + JSON.stringify(res, null, 2) + '</pre>';
                }
            });
        });

        jQuery(document).on('click', '#reeid-queue-new', function(e){
            e.preventDefault();
            var btn = jQuery(this);
            var post_id = btn.data('post');
            var lang = jQuery('#reeid_target_lang').val();
            if (!lang) { alert('Enter language code'); return; }
            btn.prop('disabled', true).text('Queuing...');
            postAjax({ action: 'reeid_translate_openai', post_id: post_id, lang: lang }, function(err, res){
                btn.prop('disabled', false).text('Queue');
                var out = document.getElementById('reeid-queue-result');
                if (err) { out.innerText = 'Error: ' + err; return; }
                if (typeof res === 'object' && res.success) {
                    out.innerHTML = '<span style="color:green">Queued: ' + (res.data.job_id || '') + '</span>';
                } else if (typeof res === 'object' && res.error) {
                    out.innerHTML = '<span style="color:red">' + (res.error || 'error') + '</span>';
                } else {
                    out.innerHTML = '<pre>' + JSON.stringify(res, null, 2) + '</pre>';
                }
            });
        });
    })();
    </script>
    <?php

    echo '</div>';
}
