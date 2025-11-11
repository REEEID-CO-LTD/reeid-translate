// REEID — Elementor Panel Translation
// - Translate Now: pastel bar with CSS-driven fake progress
// - Bulk Translate: microtask-driven (no bg throttling) + shows current language
// - Uses only Admin bulk languages (reeidData.bulkLangs)
// - Global guard + capture-phase interceptor prevent duplicate runs
// - LOCK MODAL blocks editor during translation (Cancel / Go to list / OK)

(function ($) {
  'use strict';

  // ---------- Data localized from PHP ----------
  var AJAX_URL = (window.reeidData && window.reeidData.ajaxurl)
              || (window.REEID_TRANSLATE && window.REEID_TRANSLATE.ajaxurl)
              || (window.ajaxurl || '/wp-admin/admin-ajax.php');

  var NONCE = (window.reeidData && window.reeidData.nonce)
           || (window.REEID_TRANSLATE && window.REEID_TRANSLATE.nonce)
           || '';

  var LANG_NAMES = (window.reeidData && window.reeidData.langNames) || {};
  var ADMIN_BULK_LANGS = (window.reeidData && Array.isArray(window.reeidData.bulkLangs))
    ? window.reeidData.bulkLangs.slice()
    : [];

  // ---- List URLs + post type normalization ----
  var RAW_PT = (window.reeidData && window.reeidData.postType) || 'post';
  var LIST_URLS = (window.reeidData && window.reeidData.listUrls) || {
    post: '/wp-admin/edit.php',
    page: '/wp-admin/edit.php?post_type=page',
    product: '/wp-admin/edit.php?post_type=product'
  };
  function normalizePostType(pt) {
    pt = (pt || '').toString().toLowerCase();
    if (pt === 'elementor_library' || pt === 'elementor_post') return 'post';
    if (pt === 'product_variation') return 'product';
    if (!LIST_URLS[pt]) return 'post';
    return pt;
  }
  var POST_TYPE = normalizePostType(RAW_PT);
  function listUrlForType(pt){ return (LIST_URLS && LIST_URLS[pt]) || LIST_URLS.post; }

  // ---------- STRICT DEDUPE ----------
  (function(){
    function uniqueCodes(list){
      var seen = Object.create(null), out = [];
      list.forEach(function(code){
        var orig = String(code == null ? '' : code).trim();
        var key  = orig.toLowerCase();
        if (!orig || seen[key]) return;
        seen[key] = true;
        out.push(orig);
      });
      return out;
    }
    ADMIN_BULK_LANGS = uniqueCodes(ADMIN_BULK_LANGS)
      .filter(function (code) { return Object.prototype.hasOwnProperty.call(LANG_NAMES, code); });
  })();

  // ---------- Helpers ----------
  function escapeHTML(s) {
    var div = document.createElement('div');
    div.textContent = (s == null ? '' : String(s));
    return div.innerHTML;
  }
  function langLabel(code){
    return (LANG_NAMES && LANG_NAMES[code]) ? LANG_NAMES[code] : String(code || '').toUpperCase();
  }

  function ensureStatusBox() {
    var $box = $('#reeid-status');
    if ($box.length) return $box;
    var $host = $('#reeid-elementor-panel');
    if (!$host.length) $host = $('.elementor-panel .elementor-panel-navigation');
    if (!$host.length) $host = $('body');
    $box = $('<div id="reeid-status" style="margin-top:8px;"></div>');
    $host.append($box);
    return $box;
  }

  function setStatusHTML(html) { ensureStatusBox().html(html); }
  function setStatusOK(msg)    { setStatusHTML('<span style="color:#2e7d32;font-weight:600;">✅ ' + escapeHTML(msg) + '</span>'); }
  function setStatusErr(msg)   { setStatusHTML('<span style="color:#c00;font-weight:600;">❌ ' + escapeHTML(msg) + '</span>'); }
  function setStatusInfo(msg)  { setStatusHTML('⏳ ' + escapeHTML(msg)); }

  function getPostId() {
    try {
      if (window.elementor && elementor.config && elementor.config.post_id) return elementor.config.post_id;
      if (window.elementorCommon && elementorCommon.config && elementorCommon.config.post_id) return elementorCommon.config.post_id;
      if (window.elementor && elementor.settings && elementor.settings.page && elementor.settings.page.model && elementor.settings.page.model.id) return elementor.settings.page.model.id;
    } catch (_){}
    var m = (window.location.search || '').match(/[?&]post=(\d+)/);
    return m ? m[1] : '';
  }

  function getLang()   { return $('#reeid_elementor_lang').val() || ''; }
  function getTone()   { return $('#reeid_elementor_tone').val() || 'Neutral'; }
  function getPrompt() { return $('#reeid_elementor_prompt').val() || ''; }
  function getMode()   { return $('#reeid_elementor_mode').val() || 'publish'; }

  function buttonDisable($btn, txt) { if ($btn && $btn.length){ $btn.prop('disabled', true); if (txt) $btn.data('orig', $btn.text()).text(txt); } }
  function buttonEnable($btn)       { if ($btn && $btn.length){ $btn.prop('disabled', false); var o=$btn.data('orig'); if(o) $btn.text(o); } }

  // ---------- Inline (status) progress for single — CSS driven ----------
  (function injectInlineCSS(){
    if (document.getElementById('reeid-inline-progress-css')) return;
    var css = '@keyframes reInlineGrow{0%{width:5%}60%{width:72%}100%{width:87%}}' +
              '.re-inline-wrap{margin-top:6px; background:#eef1f5; border-radius:10px; overflow:hidden;}' +
              '.re-inline-wrap.thick{height:14px}.re-inline-wrap.thin{height:8px}' +
              '.re-inline-fill{height:100%; width:0; background:linear-gradient(90deg,#a9c4ff 0%, var(--reeid-brand,#7aa7f7) 100%)}' +
              '.re-inline-fill.faking{animation:reInlineGrow 16s ease-out forwards}';
    var s = document.createElement('style'); s.id='reeid-inline-progress-css'; s.textContent=css;
    document.head.appendChild(s);
  })();

  function startFakeProgress($container, label, thick) {
    var $wrap = $('<div class="re-inline-wrap '+(thick?'thick':'thin')+'"></div>');
    var $fill = $('<div class="re-inline-fill"></div>');
    $wrap.append($fill); $container.append($wrap);
    if ('requestAnimationFrame' in window) {
      requestAnimationFrame(function(){ requestAnimationFrame(function(){ $fill.addClass('faking'); });});
    } else {
      setTimeout(function(){ $fill.addClass('faking'); }, 120);
    }
    return {
      setLabel: function(){},
      stop: function(ok){
        $fill.removeClass('faking').css('width', ok ? '100%' : '0%');
        setTimeout(function(){ $wrap.fadeOut(200, function(){ $wrap.remove(); }); }, 300);
      }
    };
  }

  // === LOCK MODAL (pastel progress bar with reliable fake start) ===
  var BRAND = (window.reeidData && window.reeidData.panelColor) || '#7aa7f7';
  function createLockModal(titleText, opts){
    opts = opts || {};
    var thinInBulk = !!opts.thinInBulk;

    if (!document.getElementById('reeid-modal-css-el-v4')) {
      var css =
        ':root{--reeid-brand:'+BRAND+';--reeid-text:#1e293b;--reeid-sub:#475569;--reeid-bg:#ffffff;--reeid-border:#e5e7eb;--reeid-shadow:0 20px 40px rgba(2,6,23,.18);--reeid-bar:#e6eefb;--reeid-fill:#a9c4ff}' +
        '#reeid-lock-overlay{position:fixed;inset:0;background:rgba(15,23,42,.45);backdrop-filter:blur(2px);z-index:2147483646;display:flex;align-items:center;justify-content:center;pointer-events:auto}' +
        '.reeid-modal{width:min(560px,92vw);background:var(--reeid-bg);border-radius:12px;box-shadow:var(--reeid-shadow);overflow:hidden;font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Inter;z-index:2147483647;pointer-events:auto}' +
        '.reeid-modal__head{display:flex;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--reeid-border)}' +
        '.reeid-pill{width:12px;height:12px;border-radius:50%;background:var(--reeid-brand)}' +
        '.reeid-title{font-weight:700;color:var(--reeid-text);font-size:16px}' +
        '.reeid-modal__body{padding:18px}' +
        '.reeid-sub{color:var(--reeid-sub);margin:4px 0 12px}' +
        '.reeid-progress{position:relative;width:100%;background:var(--reeid-bar);border-radius:10px;overflow:hidden}' +
        '.reeid-progress--thick{height:14px}' +
        '.reeid-progress--thin{height:8px}' +
        '.reeid-progress__fill{height:100%;width:0%;background:linear-gradient(90deg,var(--reeid-fill) 0%, var(--reeid-brand) 100%);transition:width .35s ease;will-change:width}' +
        '@keyframes reeidFakeGrow{0%{width:5%} 60%{width:72%} 100%{width:87%}}' +
        '.reeid-progress__fill.is-faking{animation:reeidFakeGrow 16s ease-out forwards;transition:none!important}' +
        '.reeid-progress__label{margin-top:8px;font:600 13px/1.4 system-ui,-apple-system,Segoe UI,Roboto,Inter;color:var(--reeid-text)}' +
        '.reeid-progress__pct{float:right;font-weight:700}' +
        '.reeid-modal__footer{display:flex;gap:10px;justify-content:flex-end;padding:14px 18px;border-top:1px solid var(--reeid-border);background:#fafafa}' +
        '.reeid-btn{appearance:none;border:1px solid var(--reeid-border);background:#fff;border-radius:8px;padding:8px 14px;font-weight:600;cursor:pointer;transition:transform .04s ease,background .2s ease,border-color .2s ease;pointer-events:auto}' +
        '.reeid-btn:hover{transform:translateY(-1px)}' +
        '.reeid-btn--ghost{color:#0f172a}' +
        '.reeid-btn--danger{color:#7f1d1d;border-color:#fecaca;background:#fff7f7}' +
        '.reeid-btn--primary{background:var(--reeid-brand);border-color:var(--reeid-brand);color:white}' +
        '.reeid-hidden{display:none !important}';
      var s = document.createElement('style'); s.id='reeid-modal-css-el-v4'; s.textContent=css; document.head.appendChild(s);
    }

    var $overlay = $('<div id="reeid-lock-overlay" role="presentation" aria-hidden="false"></div>');
    var $modal   = $('<div class="reeid-modal" role="dialog" aria-modal="true" aria-labelledby="reeid-modal-title" style="--reeid-brand:'+BRAND+';"></div>');
    var $head    = $('<div class="reeid-modal__head"></div>');
    var $pill    = $('<span class="reeid-pill" aria-hidden="true"></span>');
    var $title   = $('<div id="reeid-modal-title" class="reeid-title"></div>').text(titleText || 'Please Wait. Translation is in progress');
    var $body    = $('<div class="reeid-modal__body"></div>');
    var $msg     = $('<div class="reeid-sub"></div>').text('This editor is locked until translation finishes.');

    // Progress bar
    var $pwrap = $('<div class="reeid-progress '+(thinInBulk?'reeid-progress--thin':'reeid-progress--thick')+'"></div>');
    var $pfill = $('<div class="reeid-progress__fill"></div>');
    var $plabel= $('<div class="reeid-progress__label"><span class="lang"></span><span class="reeid-progress__pct">0%</span></div>');
    $pwrap.append($pfill); $body.append($msg, $pwrap, $plabel);

    var $foot    = $('<div class="reeid-modal__footer"></div>');
    var $btnCancel = $('<button type="button" class="reeid-btn reeid-btn--danger">Cancel Translation</button>');
    var listUrl  = listUrlForType(POST_TYPE);
    var $btnList = $('<a class="reeid-btn reeid-btn--ghost" target="_blank" rel="noopener">Go to the list of posts</a>').attr('href', listUrl);
    var $btnOK   = $('<button type="button" class="reeid-btn reeid-btn--primary reeid-hidden">OK</button>');

    $head.append($pill,$title); $foot.append($btnCancel,$btnList,$btnOK);
    $modal.append($head,$body,$foot); $overlay.append($modal);
    $('body').append($overlay).css('overflow','hidden');

    // Focus trap
    var lastFocus=document.activeElement; $btnCancel.trigger('focus');
    function trap(e){
      if (e.key==='Tab'){
        var f=$modal.find('button, a[role="button"], a[href]').toArray();
        var i=f.indexOf(document.activeElement);
        if (e.shiftKey && (i<=0)) { e.preventDefault(); f[f.length-1].focus(); }
        else if (!e.shiftKey && (i===f.length-1)) { e.preventDefault(); f[0].focus(); }
      }
    }
    document.addEventListener('keydown',trap,true);

    var cancelHandler=function(){};
    $btnCancel.on('click', function(){ cancelHandler(); });
    $btnList.on('click', function(){ var w=window.open(listUrl,'_blank','noopener'); });

    function cleanup(){ $('body').css('overflow',''); document.removeEventListener('keydown',trap,true); if(lastFocus&&lastFocus.focus)try{lastFocus.focus();}catch(_){ } $overlay.remove(); }
    $btnOK.on('click', cleanup);
    $overlay.on('click', function(e){ e.stopPropagation(); });

    function setCancelHandler(fn){ cancelHandler=(typeof fn==='function')?fn:function(){}; }
    function setMessage(t){ $msg.text(t||'Working...'); }

    // Progress API
    var labelText='', labelTimer=null;
    function readPct(){
      var wFill = $pfill[0].getBoundingClientRect().width;
      var wWrap = $pwrap[0].getBoundingClientRect().width || 1;
      var p = Math.max(0, Math.min(100, Math.round((wFill / wWrap) * 100)));
      $plabel.find('.reeid-progress__pct').text(p + '%');
      if (labelText) $plabel.find('.lang').text(labelText);
    }
    function setProgress(pct,label){
      $pfill.removeClass('is-faking').css('width', Math.max(0, Math.min(100, Math.round(pct))) + '%');
      if (label != null) { labelText = String(label); $plabel.find('.lang').text(labelText); }
      $plabel.find('.reeid-progress__pct').text(Math.max(0, Math.min(100, Math.round(pct))) + '%');
      if (labelTimer) { clearInterval(labelTimer); labelTimer=null; }
    }
    function startFake(label){
      labelText = label || '';
      $plabel.find('.lang').text(labelText);
      function start(){
        $pfill.removeClass('is-faking').css('width','5%');
        void $pfill[0].offsetWidth;
        $pfill.addClass('is-faking');
        if (labelTimer) clearInterval(labelTimer);
        labelTimer = setInterval(readPct, 300);
        readPct();
      }
      if ('requestAnimationFrame' in window) {
        requestAnimationFrame(function(){ requestAnimationFrame(start); });
      } else {
        setTimeout(start, 120);
      }
    }
    function finish(ok,label){
      if (label != null) { labelText = String(label); $plabel.find('.lang').text(labelText); }
      if (labelTimer) { clearInterval(labelTimer); labelTimer=null; }
      $pfill.removeClass('is-faking').css('width', ok ? '100%' : '0%');
      $plabel.find('.reeid-progress__pct').text(ok ? '100%' : '0%');
    }
    function setDone(okText){
      $btnCancel.addClass('reeid-hidden');
      $btnList.addClass('reeid-hidden');
      $btnOK.removeClass('reeid-hidden');
      $title.text('Translation finished');
      $msg.text(okText || 'You can continue editing.');
      setProgress(100);
    }
    function setFailed(err){
      $btnCancel.addClass('reeid-hidden');
      $btnList.removeClass('reeid-hidden');
      $btnOK.removeClass('reeid-hidden');
      $title.text('Translation failed');
      $msg.text(err || 'Please check logs.');
      setProgress(0);
    }

    return {
      setMessage:setMessage,
      setDone:setDone,
      setFailed:setFailed,
      setCancelHandler:setCancelHandler,
      setProgress:setProgress,
      progress:{ startFake:startFake, set:setProgress, finish:finish },
      close:function(){ $btnOK.trigger('click'); }
    };
  }

  // ---------- Single translate ----------
  function handleSingleTranslate(e) {
    e.preventDefault();

    var $btn = $('#reeid_elementor_translate');
    var pid  = getPostId();
    var lang = getLang();
    if (!pid || !lang) { setStatusErr('Missing post or language'); return; }

    setStatusInfo('Job queued. The worker will process it shortly.');
    var fp = startFakeProgress(ensureStatusBox(), 'Translating: ' + langLabel(lang), true);
    buttonDisable($btn, 'Queued…');

    var payload = {
      action: 'reeid_translate_openai',
      reeid_translate_nonce: NONCE,
      post_id: pid,
      lang: lang,
      tone: getTone(),
      prompt: getPrompt(),
      reeid_publish_mode: getMode(),
      single_mode: '1'
    };

    var modal = createLockModal('Please Wait. Translation is in progress');
    var jqxhr = null;

    // start modal fake immediately after paint
    try {
      if (modal && modal.progress && typeof modal.progress.startFake === 'function') {
        if ('requestAnimationFrame' in window) {
          requestAnimationFrame(function(){ requestAnimationFrame(function(){
            modal.progress.startFake('Translating ' + langLabel(lang));
          });});
        } else {
          setTimeout(function(){ modal.progress.startFake('Translating ' + langLabel(lang)); }, 120);
        }
      }
    } catch(_) {}

    modal.setCancelHandler(function(){
      try { if (jqxhr && jqxhr.abort) jqxhr.abort(); } catch(_){}
      modal.setFailed('Cancelled by user');
      if (modal.progress && typeof modal.progress.finish==='function') modal.progress.finish(false, langLabel(lang));
      fp.stop(false);
    });

    jqxhr = $.post(AJAX_URL, payload, function (resp) {
      if (resp && resp.success) {
        setStatusOK((resp.data && resp.data.message) || 'Translation completed');
        modal.setDone('Translation completed');
        if (modal.progress && typeof modal.progress.finish==='function') modal.progress.finish(true, langLabel(lang));
        fp.stop(true);
      } else {
        var m = (resp && resp.data && (resp.data.message || resp.data.error)) || 'Translation failed';
        setStatusErr(m);
        modal.setFailed(m);
        if (modal.progress && typeof modal.progress.finish==='function') modal.progress.finish(false, langLabel(lang));
        fp.stop(false);
      }
    }).fail(function () {
      setStatusErr('AJAX failed');
      modal.setFailed('AJAX failed');
      if (modal.progress && typeof modal.progress.finish==='function') modal.progress.finish(false, langLabel(lang));
      fp.stop(false);
    }).always(function () {
      buttonEnable($btn);
    });
  }

  // ---------- Bulk translate (microtask-driven; shows current language) ----------
  function handleBulkTranslate(e) {
    e.preventDefault();
    e.stopImmediatePropagation();
    if (window.__REEID_BULK_RUNNING__) return;
    window.__REEID_BULK_RUNNING__ = true;

    try { window.onbeforeunload = null; } catch(_){}
    $('#reeid-bulk-warning').remove();

    if (!ADMIN_BULK_LANGS.length) {
      setStatusErr('No bulk languages selected in Settings. Please choose at least one in “Bulk Translation Languages”.');
      window.__REEID_BULK_RUNNING__ = false;
      return;
    }

    var pid = getPostId();
    if (!pid) { setStatusErr('Missing post'); window.__REEID_BULK_RUNNING__ = false; return; }

    var tone   = getTone();
    var prompt = getPrompt();
    var mode   = getMode();

    var asap = window.queueMicrotask
      ? window.queueMicrotask.bind(window)
      : function (fn) { Promise.resolve().then(fn); };

    var $status = ensureStatusBox().empty();

    // Overall progress header
    var total = ADMIN_BULK_LANGS.length;
    var done  = 0;
    var $overall = $(
      '<div class="reeid-bulk-overall" style="margin-bottom:8px;">' +
        '<div style="font-weight:600;">Progress: <span class="reeid-bulk-count">0/' + total + '</span></div>' +
        '<div class="reeid-bulk-bar" style="margin-top:6px;height:8px;background:#eef1f5;border-radius:5px;overflow:hidden;position:relative;">' +
          '<div class="reeid-bulk-bar-inner" style="height:100%;width:0%;background:#4c8bf7;transition:width .35s ease;"></div>' +
        '</div>' +
      '</div>'
    );
    var $list = $('<div class="reeid-bulk-list" style="margin-top:6px;"></div>');
    $status.append($overall).append($list);

    function updateOverall() {
      done = Math.min(done, total);
      $overall.find('.reeid-bulk-count').text(done + '/' + total);
      var pct = total ? Math.round((done / total) * 100) : 0;
      $overall.find('.reeid-bulk-bar-inner').css('width', pct + '%');
    }

    // Build queue + rows
    var queue = ADMIN_BULK_LANGS.slice();
    var rows = {};
    queue.forEach(function (code) {
      var label = langLabel(code);
      var $row = $(
        '<div class="reeid-status-row" style="display:flex;gap:8px;align-items:center;margin:4px 0;">' +
          '<span class="reeid-status-emoji">⏳</span>' +
          '<span class="reeid-status-lang" style="min-width:140px;display:inline-block;">' + escapeHTML(label) + ':</span>' +
          '<span class="reeid-status-text">Waiting…</span>' +
        '</div>'
      );
      rows[code] = $row;
      $list.append($row);
    });

    var modal = createLockModal('Please Wait. Bulk translation is in progress', { thinInBulk: true });
    var jqxhr = null, CANCELLED=false;

    // Show FIRST language immediately on modal bar
    (function showFirst(){
      var first = queue[0];
      var label = first ? langLabel(first) : 'Preparing…';
      try {
        if (modal && modal.progress && typeof modal.progress.startFake === 'function') {
          if ('requestAnimationFrame' in window) {
            requestAnimationFrame(function(){ requestAnimationFrame(function(){
              modal.progress.startFake('Translating ' + label + ' (1/'+total+')');
            });});
          } else {
            setTimeout(function(){ modal.progress.startFake('Translating ' + label + ' (1/'+total+')'); }, 120);
          }
        }
      } catch(_){}
    })();

    modal.setCancelHandler(function(){
      CANCELLED = true;
      try { if (jqxhr && jqxhr.abort) jqxhr.abort(); } catch(_){}
      modal.setFailed('Cancelled by user');
      window.__REEID_BULK_RUNNING__ = false;
    });

    // Abort on hard navigation (not on tab blur)
    window.addEventListener('beforeunload', function _reeidBulkNavGuard(){
      try{ if (jqxhr && jqxhr.abort) jqxhr.abort(); }catch(_){}
      window.__REEID_BULK_RUNNING__ = false;
      window.removeEventListener('beforeunload', _reeidBulkNavGuard);
    });

    // Core loop (microtask-driven: no throttling)
    (function next(){
      if (CANCELLED) { window.__REEID_BULK_RUNNING__ = false; return; }
      if (!queue.length){
        try { if (modal && modal.progress && typeof modal.progress.finish==='function') modal.progress.finish(true, 'Overall '+done+'/'+total); } catch(_){}
        try { if (modal && typeof modal.setDone==='function') modal.setDone('Bulk translation completed'); } catch(_){}
        window.__REEID_BULK_RUNNING__ = false; return;
      }

      var lang = queue.shift();
      var curLabel = langLabel(lang);
      var $row = rows[lang];
      if ($row && $row.length) {
        $row.find('.reeid-status-emoji').text('⏳');
        $row.find('.reeid-status-text').text('Processing…');
      }

      // Reflect current language on modal immediately
      try {
        if (modal && modal.progress && typeof modal.progress.startFake === 'function') {
          modal.progress.startFake('Translating ' + curLabel + ' ('+(done+1)+'/'+total+')');
        } else if (modal && typeof modal.setProgress === 'function') {
          var pctNow = total ? Math.round((done/total)*100) : 0;
          modal.setProgress(pctNow, 'Translating ' + curLabel + ' ('+(done+1)+'/'+total+')');
        }
      } catch(_){}

      var payload = {
        action: 'reeid_translate_openai',
        reeid_translate_nonce: NONCE,
        post_id: pid,
        lang: lang,
        tone: tone,
        prompt: prompt,
        reeid_publish_mode: mode
      };

      jqxhr = $.ajax({
        url: AJAX_URL,
        type: 'POST',
        data: payload,
        cache: false,
        timeout: 0
      }).done(function(res){
        var ok = (res && res.success);
        if ($row && $row.length) {
          $row.find('.reeid-status-emoji').text(ok ? '✅' : '❌');
          var msg = ok ? 'Done' : ((res && res.data && (res.data.error || res.data.message)) || 'Failed');
          $row.find('.reeid-status-text').text(msg);
        }
        done++; updateOverall();
        try {
          if (modal && typeof modal.setProgress === 'function') {
            var pct = total ? Math.round((done/total)*100) : 0;
            modal.setProgress(pct, 'Overall '+done+'/'+total+' — Last: ' + curLabel);
          }
        } catch(_){}
        asap(next); // move on immediately (no setTimeout)
      }).fail(function(xhr){
        if ($row && $row.length) {
          $row.find('.reeid-status-emoji').text('❌');
          var detail = (xhr && xhr.status) ? ('AJAX failed: ' + xhr.status + ' ' + (xhr.statusText || '')) : 'AJAX failed';
          $row.find('.reeid-status-text').text(detail);
        }
        done++; updateOverall();
        try {
          if (modal && typeof modal.setProgress === 'function') {
            var pct = total ? Math.round((done/total)*100) : 0;
            modal.setProgress(pct, 'Overall '+done+'/'+total+' — Last: ' + curLabel);
          }
        } catch(_){}
        asap(next);
      });
    })();
  }

  // ---------- Bindings ----------
  function bindOnce() {
    $(document)
      .off('click', '#reeid_elementor_translate')
      .on('click',  '#reeid_elementor_translate', handleSingleTranslate);

    $(document)
      .off('click', '#reeid_elementor_bulk')
      .on('click',  '#reeid_elementor_bulk', handleBulkTranslate);

    $('#reeid_elementor_translate').off('click');
    $('#reeid_elementor_bulk').off('click');
  }

  $(bindOnce);
  if (window.elementor && elementor.channels && elementor.channels.editor) {
    try { elementor.channels.editor.on('change:editor', bindOnce); } catch (_){}
  }
  setInterval(bindOnce, 1500);

  // ---------- Capture-phase interceptor ----------
  function bulkCaptureInterceptor(ev){
    var target = ev.target && ev.target.closest ? ev.target.closest('#reeid_elementor_bulk') : null;
    if (!target) return;
    ev.preventDefault();
    ev.stopImmediatePropagation();
    if (window.__REEID_BULK_RUNNING__) return;
    handleBulkTranslate.call(target, ev);
  }
  try { document.removeEventListener('click', bulkCaptureInterceptor, true); } catch(_){}
  document.addEventListener('click', bulkCaptureInterceptor, true);

  // Cleanup if returning to the page with stale overlay/flag
  $(document).ready(function(){
    if (window.__REEID_BULK_RUNNING__) window.__REEID_BULK_RUNNING__ = false;
    $('#reeid-lock-overlay').remove();
  });

})(jQuery);
