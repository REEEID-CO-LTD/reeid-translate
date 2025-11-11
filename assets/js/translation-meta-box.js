// REEID — Metabox Translation (Classic/Gutenberg)
// - Single + Bulk with pro lock modal (Cancel / Go to list / OK)
// - Bulk runs via single endpoint (no preflight), sequential with progress
// - Uses only Admin bulk languages (reeidData.bulkLangs); strict dedupe
// - Global guard + capture-phase interceptor prevent duplicate runs

(function ($) {
  'use strict';

  // ---------- Data from PHP ----------
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

  // ---- Post type → list URL mapping ----
  var RAW_PT = (window.reeidData && window.reeidData.postType) || 'post';
  var LIST_URLS = (window.reeidData && window.reeidData.listUrls) || {
    post: '/wp-admin/edit.php',
    page: '/wp-admin/edit.php?post_type=page',
    product: '/wp-admin/edit.php?post_type=product'
  };
  function normalizePostType(pt){
    pt = (pt || '').toString().toLowerCase();
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
  function ensureStatusBox() {
    var $box = $('#reeid-status');
    if ($box.length) return $box;
    var $host = $('#reeid-translate-panel');
    if (!$host.length) $host = $('.reeid-panel');
    if (!$host.length) $host = $('#post-body-content');
    if (!$host.length) $host = $('body');
    $box = $('<div id="reeid-status" style="margin-top:8px;"></div>');
    $host.append($box);
    return $box;
  }
  function setStatusHTML(html){ ensureStatusBox().html(html); }
  function setStatusOK(msg){ setStatusHTML('<span style="color:#2e7d32;font-weight:600;">✅ '+escapeHTML(msg)+'</span>'); }
  function setStatusErr(msg){ setStatusHTML('<span style="color:#c00;font-weight:600;">❌ '+escapeHTML(msg)+'</span>'); }
  function setStatusInfo(msg){ setStatusHTML('⏳ '+escapeHTML(msg)); }

  function escapeHTML(s){ var d=document.createElement('div'); d.textContent=(s==null?'':String(s)); return d.innerHTML; }

  function getPostId() {
    // Gutenberg
    try {
      if (window.wp && wp.data && wp.data.select) {
        var id = wp.data.select('core/editor').getCurrentPostId && wp.data.select('core/editor').getCurrentPostId();
        if (id) return id;
      }
    } catch(_){}
    // Classic + fallback
    return $('input[name="post_ID"]').val()
        || $('input[name="post_id"]').val()
        || (location.search.match(/[?&]post=(\d+)/)||[])[1]
        || '';
  }
  function getTargetLang(){ return $('#reeid_target_lang').val() || $('#reeid_lang_pick').val() || ''; }
  function getTone(){ return $('#reeid_tone_pick').val() || $('[name="reeid_post_tone"]').val() || 'Neutral'; }
  function getPrompt(){ var v=$('#reeid_prompt').val(); if(v==null) v=$('[name="reeid_post_prompt"]').val(); return v || ''; }
  function getMode(){ return $('input[name="reeid_publish_mode"]:checked').val() || 'publish'; }

  function buttonDisable($btn, txt) { if($btn && $btn.length){ $btn.prop('disabled', true); if (txt) $btn.data('origText', $btn.text()).text(txt); } }
  function buttonEnable($btn){ if($btn && $btn.length){ $btn.prop('disabled', false); var o=$btn.data('origText'); if(o) $btn.text(o); } }

  // Fake progress (single) — CSS driven, no timers blocking network
function startFakeProgress($container, label, thick) {
  // inject CSS once
  if (!document.getElementById('reeid-inline-progress-css')) {
    var css = '@keyframes reInlineGrow{0%{width:5%}60%{width:72%}100%{width:87%}}' +
              '.re-inline-wrap{margin-top:6px; background:#eef1f5; border-radius:10px; overflow:hidden;}' +
              '.re-inline-wrap.thick{height:14px}.re-inline-wrap.thin{height:8px}' +
              '.re-inline-fill{height:100%; width:0; background:linear-gradient(90deg,#a9c4ff 0%, var(--reeid-brand,#7aa7f7) 100%)}' +
              '.re-inline-fill.faking{animation:reInlineGrow 16s ease-out forwards}';
    var s = document.createElement('style'); s.id='reeid-inline-progress-css'; s.textContent=css;
    document.head.appendChild(s);
  }
  var $wrap = $('<div class="re-inline-wrap '+(thick?'thick':'thin')+'"></div>');
  var $fill = $('<div class="re-inline-fill"></div>');
  $wrap.append($fill); $container.append($wrap);

  // kick animation after paint
  if ('requestAnimationFrame' in window) {
    requestAnimationFrame(function(){ requestAnimationFrame(function(){ $fill.addClass('faking'); });});
  } else {
    setTimeout(function(){ $fill.addClass('faking'); }, 120);
  }

  return {
    setLabel: function(){}, // kept for API compatibility
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

  // New style id to bust cache
  if (!document.getElementById('reeid-modal-css-v4')) {
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
      /* Fake to ~87% with animation; disable transition while faking to avoid conflicts */
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
    var s=document.createElement('style'); s.id='reeid-modal-css-v4'; s.textContent=css; document.head.appendChild(s);
  }

  var $overlay=$('<div id="reeid-lock-overlay" role="presentation" aria-hidden="false"></div>');
  var $modal=$('<div class="reeid-modal" role="dialog" aria-modal="true" aria-labelledby="reeid-modal-title" style="--reeid-brand:'+BRAND+';"></div>');
  var $head=$('<div class="reeid-modal__head"></div>');
  var $pill=$('<span class="reeid-pill" aria-hidden="true"></span>');
  var $title=$('<div id="reeid-modal-title" class="reeid-title"></div>').text(titleText||'Please Wait. Translation is in progress...');
  var $body=$('<div class="reeid-modal__body"></div>');
  var $msg =$('<div class="reeid-sub"></div>').text('This editor is locked until translation finishes. You can continue by opening separate tab.');

  // Progress bar (thick for single, thin for bulk)
  var $pwrap = $('<div class="reeid-progress '+(thinInBulk?'reeid-progress--thin':'reeid-progress--thick')+'"></div>');
  var $pfill = $('<div class="reeid-progress__fill"></div>');
  var $plabel= $('<div class="reeid-progress__label"><span class="lang"></span><span class="reeid-progress__pct">0%</span></div>');
  $pwrap.append($pfill); $body.append($msg, $pwrap, $plabel);

  var $foot=$('<div class="reeid-modal__footer"></div>');
  var $btnCancel=$('<button type="button" class="reeid-btn reeid-btn--danger">Cancel Translation</button>');
  var listUrl=(window.reeidData && window.reeidData.listUrls && window.reeidData.listUrls[(window.reeidData.postType||'post')]) || '/wp-admin/edit.php';
  var $btnList=$('<a class="reeid-btn reeid-btn--ghost" target="_blank" rel="noopener">Go to the list of posts</a>').attr('href', listUrl);
  var $btnOK=$('<button type="button" class="reeid-btn reeid-btn--primary reeid-hidden">OK</button>');

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

  // Progress API (CSS animation + JS label updates)
  var labelText='', labelTimer=null;

  function readPct(){
    var wFill = $pfill[0].getBoundingClientRect().width;
    var wWrap = $pwrap[0].getBoundingClientRect().width || 1;
    var p = Math.max(0, Math.min(100, Math.round((wFill / wWrap) * 100)));
    $plabel.find('.reeid-progress__pct').text(p + '%');
    if (labelText) $plabel.find('.lang').text(labelText);
  }

  function setProgress(pct,label){
    // stop fake & set explicit %
    $pfill.removeClass('is-faking').css('width', Math.max(0, Math.min(100, Math.round(pct))) + '%');
    if (label != null) { labelText = String(label); $plabel.find('.lang').text(labelText); }
    $plabel.find('.reeid-progress__pct').text(Math.max(0, Math.min(100, Math.round(pct))) + '%');
    if (labelTimer) { clearInterval(labelTimer); labelTimer=null; }
  }

  function startFake(label){
    labelText = label || '';
    $plabel.find('.lang').text(labelText);

    // Ensure we start AFTER the modal has painted: double RAF, then trigger animation
    function start(){
      $pfill.removeClass('is-faking').css('width','5%'); // reset
      // force reflow
      void $pfill[0].offsetWidth;
      $pfill.addClass('is-faking');
      // keep % label in sync while faking
      if (labelTimer) clearInterval(labelTimer);
      labelTimer = setInterval(readPct, 300);
      readPct();
    }
    if ('requestAnimationFrame' in window) {
      requestAnimationFrame(function(){
        requestAnimationFrame(start); // 2nd frame => painted
      });
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

  function setDone(okMsg){
    $btnCancel.addClass('reeid-hidden');
    $btnList.addClass('reeid-hidden');
    $btnOK.removeClass('reeid-hidden');
    $title.text('Translation finished');
    $msg.text(okMsg || 'You can continue editing.');
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


  // ---------- Single Translation ----------
function handleSingleTranslate(e) {
  e.preventDefault();
  var $btn = $(this);
  var pid  = getPostId();
  var lang = getTargetLang();
  if (!pid || !lang) { setStatusErr('Missing post or language'); return; }

  var label = (LANG_NAMES && LANG_NAMES[lang]) ? LANG_NAMES[lang] : String(lang || '').toUpperCase();

  var $status = ensureStatusBox(); setStatusInfo('Job queued. The worker will process it shortly.');
  var fp = startFakeProgress($status); // keep your existing inline fake
  buttonDisable($btn, 'Queued…');

  var modal = createLockModal('Please Wait. Translation is in progress...');
  var jqxhr = null;

  // start modal fake bar immediately after paint (no setMessage required)
  try {
    if (modal && modal.progress && typeof modal.progress.startFake === 'function') {
      if ('requestAnimationFrame' in window) {
        requestAnimationFrame(function(){ requestAnimationFrame(function(){
          modal.progress.startFake(label);
        });});
      } else {
        setTimeout(function(){ modal.progress.startFake(label); }, 120);
      }
    }
  } catch(_) {}

  modal.setCancelHandler(function(){
    try{ if(jqxhr && jqxhr.abort) jqxhr.abort(); }catch(_){}
    if (modal && typeof modal.setFailed === 'function') modal.setFailed('Cancelled by user');
    if (modal && modal.progress && typeof modal.progress.finish === 'function') modal.progress.finish(false, label);
    fp.stop(false);
  });

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

  jqxhr = $.ajax({ url: AJAX_URL, type: 'POST', data: payload, cache: false, timeout: 0 })
    .done(function(resp){
      if (resp && resp.success) {
        setStatusOK((resp.data && resp.data.message) || 'Translation completed');
        if (modal && typeof modal.setDone === 'function') modal.setDone('Translation completed');
        if (modal && modal.progress && typeof modal.progress.finish === 'function') modal.progress.finish(true, label);
        fp.stop(true);
      } else {
        var m=(resp&&resp.data&&(resp.data.message||resp.data.error))||'Translation failed';
        setStatusErr(m);
        if (modal && typeof modal.setFailed === 'function') modal.setFailed(m);
        if (modal && modal.progress && typeof modal.progress.finish === 'function') modal.progress.finish(false, label);
        fp.stop(false);
      }
    })
    .fail(function(xhr){
      var detail = (xhr && xhr.status) ? ('AJAX failed: '+xhr.status+' '+(xhr.statusText||'')) : 'AJAX failed';
      setStatusErr(detail);
      if (modal && typeof modal.setFailed === 'function') modal.setFailed(detail);
      if (modal && modal.progress && typeof modal.progress.finish === 'function') modal.progress.finish(false, label);
      fp.stop(false);
    })
    .always(function(){ buttonEnable($btn); });
}


// ---------- Bulk Translation (sequential; no preflight; microtask-driven) ----------
function handleBulkTranslate(e) {
  e.preventDefault();
  e.stopImmediatePropagation();
  if (window.__REEID_BULK_RUNNING__) return;
  window.__REEID_BULK_RUNNING__ = true;

  if (!ADMIN_BULK_LANGS.length) {
    setStatusErr('No bulk languages selected in Settings. Please choose at least one in “Bulk Translation Languages”.');
    window.__REEID_BULK_RUNNING__ = false; return;
  }

  var pid = getPostId(); if (!pid) { setStatusErr('Missing post'); window.__REEID_BULK_RUNNING__ = false; return; }
  var tone=getTone(), prompt=getPrompt(), mode=getMode();

  // microtask helper (no throttling in bg tabs)
  var asap = window.queueMicrotask
    ? window.queueMicrotask.bind(window)
    : function (fn) { Promise.resolve().then(fn); };

  var $status = ensureStatusBox().empty();
  var total=ADMIN_BULK_LANGS.length, done=0;

  // Page overall bar (kept)
  var $overall = $('<div style="margin-bottom:8px;"><div style="font-weight:600;">Progress: <span class="reeid-bulk-count">0/'+total+'</span></div><div style="margin-top:6px;height:8px;background:#eef1f5;border-radius:5px;overflow:hidden;position:relative;"><div class="reeid-bulk-bar-inner" style="height:100%;width:0%;background:#4c8bf7;transition:width .35s ease;"></div></div></div>');
  var $list = $('<div style="margin-top:6px;"></div>');
  $status.append($overall).append($list);
  function updateOverall(){ done=Math.min(done,total); $overall.find('.reeid-bulk-count').text(done+'/'+total); var pct=total?Math.round((done/total)*100):0; $overall.find('.reeid-bulk-bar-inner').css('width',pct+'%'); }

  var queue = ADMIN_BULK_LANGS.slice(), rows={};
  queue.forEach(function(code){
    var label = (LANG_NAMES && LANG_NAMES[code]) ? LANG_NAMES[code] : String(code||'').toUpperCase();
    var $row = $('<div style="display:flex;gap:8px;align-items:center;margin:4px 0;"><span class="e">⏳</span><span style="min-width:140px;display:inline-block;">'+escapeHTML(label)+':</span><span class="t">Waiting…</span></div>');
    rows[code] = $row; $list.append($row);
  });

  var modal = createLockModal('Please Wait. Bulk translation is in progress', { thinInBulk: true });
  var jqxhr = null, CANCELLED=false;

  // show FIRST language immediately on the bar label
  (function showFirst(){
    var first = queue[0];
    if (first) {
      var firstLabel = (LANG_NAMES && LANG_NAMES[first]) ? LANG_NAMES[first] : String(first||'').toUpperCase();
      try { if (modal && modal.progress && typeof modal.progress.startFake === 'function') modal.progress.startFake('Translating ' + firstLabel + ' (1/'+total+')'); } catch(_) {}
    } else {
      try { if (modal && modal.progress && typeof modal.progress.startFake === 'function') modal.progress.startFake('Preparing…'); } catch(_) {}
    }
  })();

  modal.setCancelHandler(function(){
    CANCELLED=true;
    try{ if(jqxhr&&jqxhr.abort) jqxhr.abort(); }catch(_){}
    try { if (modal && typeof modal.setFailed==='function') modal.setFailed('Cancelled by user'); } catch(_){}
    window.__REEID_BULK_RUNNING__ = false;
  });

  // Abort on hard navigation (not on simple tab blur)
  window.addEventListener('beforeunload', function _reeidBulkNavGuard(){
    try{ if (jqxhr && jqxhr.abort) jqxhr.abort(); }catch(_){}
    window.__REEID_BULK_RUNNING__ = false;
    window.removeEventListener('beforeunload', _reeidBulkNavGuard);
  });

  // Core loop without timers (no throttling)
  (function next(){
    if (CANCELLED) { window.__REEID_BULK_RUNNING__ = false; return; }
    if (!queue.length){
      try { if (modal && modal.progress && typeof modal.progress.finish==='function') modal.progress.finish(true, 'Overall '+done+'/'+total); } catch(_){}
      try { if (modal && typeof modal.setDone==='function') modal.setDone('Bulk translation completed'); } catch(_){}
      window.__REEID_BULK_RUNNING__ = false; return;
    }

    var lang = queue.shift();
    var curLabel = (LANG_NAMES && LANG_NAMES[lang]) ? LANG_NAMES[lang] : String(lang||'').toUpperCase();
    var $row = rows[lang];
    if ($row && $row.length) { $row.find('.e').text('⏳'); $row.find('.t').text('Processing…'); }

    // reflect current language immediately (fake anim keeps moving while request is in flight)
    try {
      if (modal && modal.progress && typeof modal.progress.startFake === 'function') {
        modal.progress.startFake('Translating ' + curLabel + ' ('+(done+1)+'/'+total+')');
      } else if (modal && typeof modal.setProgress === 'function') {
        var pctNow = total ? Math.round((done/total)*100) : 0;
        modal.setProgress(pctNow, 'Translating ' + curLabel + ' ('+(done+1)+'/'+total+')');
      }
    } catch(_) {}

    var payload = { action:'reeid_translate_openai', reeid_translate_nonce:NONCE, post_id:pid, lang:lang, tone:tone, prompt:prompt, reeid_publish_mode:mode };
    jqxhr = $.ajax({ url: AJAX_URL, type:'POST', data: payload, cache:false, timeout:0 })
      .done(function(res){
        var ok = (res && res.success);
        if ($row && $row.length) { $row.find('.e').text(ok?'✅':'❌'); $row.find('.t').text(ok?'Done':((res&&res.data&&(res.data.error||res.data.message))||'Failed')); }
        done++; updateOverall();
        try {
          if (modal && typeof modal.setProgress === 'function') {
            var pct = total ? Math.round((done/total)*100) : 0;
            modal.setProgress(pct, 'Overall '+done+'/'+total+' — Last: ' + curLabel);
          }
        } catch(_){}
        // advance immediately via microtask (no setTimeout)
        asap(next);
      })
      .fail(function(xhr){
        if ($row && $row.length) { $row.find('.e').text('❌'); $row.find('.t').text((xhr&&xhr.status)?('AJAX failed: '+xhr.status+' '+(xhr.statusText||'')):'AJAX failed'); }
        done++; updateOverall();
        try {
          if (modal && typeof modal.setProgress === 'function') {
            var pct = total ? Math.round((done/total)*100) : 0;
            modal.setProgress(pct, 'Overall '+done+'/'+total+' — Last: ' + curLabel);
          }
        } catch(_){}
        // advance immediately via microtask (no setTimeout)
        asap(next);
      });
  })();
}


  // ---------- Bindings (defensive selectors) ----------
  $(document).off('click', '#reeid-translate-btn, #reeid-translate-single-btn');
  $(document).off('click', '#reeid-bulk-translate-btn');
  $('#reeid-bulk-translate-btn').off('click');

  $(document).on('click', '#reeid-translate-btn, #reeid-translate-single-btn', handleSingleTranslate);
  $(document).on('click', '#reeid-bulk-translate-btn', handleBulkTranslate);

  

})(jQuery);
