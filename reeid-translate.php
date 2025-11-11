<?php
require_once __DIR__ . "/includes/rt-wc-i18n-lite.php";
require_once __DIR__ . "/includes/reeid-focuskw-sync.php";
require_once __DIR__ . "/includes/seo-sync.php";

/**
 * Plugin Name:       REEID Translate
 * Plugin URI:        https://reeid.com/reeid-translation-plugin/
 * Description:       Translate WordPress posts and pages into multiple languages using AI. Supports Gutenberg, Elementor, and Classic Editor. Includes language switcher, tone presets, and optional PRO features.
 * Version:           1.7
 * Author:            REEID GCE
 * Author URI:        https://reeid.com/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       reeid-translate
 * Domain Path:       /languages
 */

// Load translations on init (avoid early JIT notice).
add_action('init', 'reeid_translate_load_textdomain');
function reeid_translate_load_textdomain()
{
    load_plugin_textdomain(
        'reeid-translate',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}

/**
 * SAFELY INJECT VALIDATE-KEY JAVASCRIPT (no early nonce)
 */
add_action('init', function () {

    add_action('admin_enqueue_scripts', function () {

        if (! function_exists('wp_create_nonce')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (empty($screen) || strpos($screen->id ?? '', 'reeid-translate') === false) {
            return;
        }

        $nonce = wp_create_nonce('reeid_translate_nonce_action');
        $js = <<<JS
        jQuery(document).on('click', '#reeid-validate-openai', function(e){
            e.preventDefault();
            const key = jQuery('#reeid_openai_key').val();
            jQuery.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'reeid_validate_openai_key',
                    key: key,
                    _ajax_nonce: '{$nonce}'
                },
                success: function(res){
                    alert(res?.data?.message || 'Unknown response');
                },
                error: function(xhr){
                    alert('AJAX failed (' + xhr.status + ')');
                }
            });
        });
JS;

        wp_register_script('reeid-validate-key', false);
        wp_enqueue_script('reeid-validate-key');
        wp_add_inline_script('reeid-validate-key', $js);
    });
});

if (! defined('ABSPATH')) { // guard
    exit;
}

/* ===========================================================
 * SECTION 0.1 : REEID WC HELPERS BOOTSTRAP (single source-of-truth)
 * Ensures includes/wc-inline.php is loaded early and only once.
 * Do not remove — needed to avoid 'undefined function' errors.
 * =========================================================== */
if (! defined('REEID_WC_HELPERS_LOADED')) {
    define('REEID_WC_HELPERS_LOADED', true);

    $reeid_helper_paths = array(
        __DIR__ . '/includes/wc-inline.php',
        __DIR__ . '/wc-inline.php',
        plugin_dir_path(__FILE__) . 'includes/wc-inline.php',
    );

    $loaded = false;
    foreach ($reeid_helper_paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $loaded = true;
            break;
        }
    }

    if (! $loaded) {
        if (function_exists('error_log')) {
            error_log('[REEID][WARN] wc-inline.php not found at expected paths: ' . implode(', ', $reeid_helper_paths));
        }
    }
}


/**
 * Ensure default Woo translation mappings exist and provide a helper to get the merged mapping.
 */

/**
 * Return merged mapping: defaults + option (option entries override defaults).
 *
 * @return array
 */
function reeid_get_woo_strings_map()
{
    // Default mappings you want guaranteed in the plugin
    $defaults = array(
        'Add to cart'             => 'Buy now',
        'Color'                   => 'Colour',
        'Text Color'              => 'Text Colour',
        'Background Color'        => 'Background Colour',
        'Description'             => 'Product details',
        'Additional information'  => 'More information',
        // add more defaults here as needed
    );

    $opt = get_option('reeid_woo_strings_en', array());
    if (! is_array($opt)) {
        $opt = array();
    }

    // user option should override defaults, so array_merge(defaults, option)
    return array_merge($defaults, $opt);
}

/**
 * On plugin activation make sure the option contains the merged mapping (so other code reading the option sees defaults).
 */
function reeid_ensure_woo_strings_option_on_activate()
{
    $opt = get_option('reeid_woo_strings_en', array());
    if (! is_array($opt)) {
        $opt = array();
    }

    $defaults = array(
        'Add to cart'             => 'Buy now',
        'Color'                   => 'Colour',
        'Text Color'              => 'Text Colour',
        'Background Color'        => 'Background Colour',
        'Description'             => 'Product details',
        'Additional information'  => 'More information',
        // add more defaults here as needed
    );

    $merged = array_merge($defaults, $opt);
    update_option('reeid_woo_strings_en', $merged);
}

// Register activation hook (works when this file is the main plugin file)
if (function_exists('register_activation_hook')) {
    register_activation_hook(__FILE__, 'reeid_ensure_woo_strings_option_on_activate');
}


/* ===========================================================
 SECTION 0.2 : Small, safe WooCommerce "attributes panel" CSS + JS fix
 - Conservative: runs only on frontend single-product pages,
   and only for products that actually have attributes.
 - Self-contained: IDs/classes are unique to avoid collisions.
 =========================================================== */

if (! defined('REEID_WC_ATTRS_FIX_LOADED')) {
    define('REEID_WC_ATTRS_FIX_LOADED', true);

    // Print a minimal CSS override in <head> when appropriate
    add_action('wp_head', function() {
        if (is_admin()) return;
        if (! function_exists('is_product') || ! is_product()) return;
        global $post;
        if (empty($post->ID)) return;
        if (! function_exists('wc_get_product')) return;
        $prod = wc_get_product($post->ID);
        if (! $prod) return;
        $attrs = $prod->get_attributes();
        if (empty($attrs)) return; // nothing to fix

        // Scoped CSS override (very specific to product pages)
        $css = <<<CSS
/* REEID: ensure WooCommerce "Additional information" panel and attributes table are not collapsed */
.single-product .woocommerce-Tabs-panel--additional_information,
.single-product #tab-additional_information {
  display: block !important;
  visibility: visible !important;
  opacity: 1 !important;
  height: auto !important;
  max-height: none !important;
  transform: none !important;
  pointer-events: auto !important;
}

/* table rendering for attributes */
.single-product #tab-additional_information table.shop_attributes,
.single-product .woocommerce-Tabs-panel--additional_information .woocommerce-product-attributes,
.single-product table.shop_attributes.woocommerce-product-attributes {
  display: table !important;
}
#reeid-wc-attrs-forced-visible { }
CSS;
        echo "<style id='reeid-wc-attrs-fix' data-reeid='1'>\n" . $css . "\n</style>\n";
    }, 9999); // late priority so it wins over earlier stylesheets


    // Print a JS fallback in footer that removes inline collapses if panel size is zero
    add_action('wp_footer', function() {
        if (is_admin()) return;
        if (! function_exists('is_product') || ! is_product()) return;
        global $post;
        if (empty($post->ID)) return;
        if (! function_exists('wc_get_product')) return;
        $prod = wc_get_product($post->ID);
        if (! $prod) return;
        $attrs = $prod->get_attributes();
        if (empty($attrs)) return;

        // Output JS (keeps minimal footprint, no console spam)
        ?>
<script id="reeid-wc-attrs-unique-v1">
(function(){
  try {
    const PFX = '[REEID-ATTRS-UNIQUE]';

    // selectors for attributes
    const ATTR_SEL = 'table.shop_attributes, .woocommerce-product-attributes, table.woocommerce-product-attributes';

    // small helpers (re-used from previous script)
    function scopeChildren(parent, selector){
      try { return Array.from(parent.querySelectorAll(':scope > ' + selector)); }
      catch(e){ return Array.from(parent.children).filter(ch=>{ try { return ch.matches && ch.matches(selector); } catch(e){ return false; } }); }
    }
    function findTabsWrapper(){
      const candidates = ['.woocommerce-tabs', '.wc-tabs-wrapper', '.woocommerce-tabs-wrapper', '.woocommerce-tabs .wc-tabs-wrapper'];
      for(const s of candidates){ const el=document.querySelector(s); if(el) return el; }
      // fallback: find element that contains multiple panels
      const panels = Array.from(document.querySelectorAll('.woocommerce-Tabs-panel, [id^="tab-"]'));
      for(const p of panels){ if(!p.parentElement) continue; const siblings = Array.from(p.parentElement.querySelectorAll('.woocommerce-Tabs-panel, [id^="tab-"]')); if(siblings.length>1) return p.parentElement; }
      return null;
    }
    function findPanelDirect(wrapper, idName){
      if(!wrapper) return null;
      let p = Array.from(scopeChildren(wrapper, '[id="' + idName + '"]'))[0];
      if(p) return p;
      p = Array.from(scopeChildren(wrapper, '.woocommerce-Tabs-panel--additional_information, .woocommerce-Tabs-panel')).
            find(el => (el.className||'').toLowerCase().indexOf('additional_information') !== -1);
      if(p) return p;
      p = Array.from(scopeChildren(wrapper, '*')).find(ch => (ch.id||'').toLowerCase().indexOf('additional_information') !== -1);
      return p || null;
    }
    function createPanelUnderWrapper(wrapper, panelId, labelledById){
      const panel = document.createElement('div');
      panel.id = panelId;
      panel.className = 'woocommerce-Tabs-panel woocommerce-Tabs-panel--additional_information panel entry-content wc-tab';
      panel.setAttribute('role','tabpanel');
      if(labelledById) panel.setAttribute('aria-labelledby', labelledById);
      // append near end; try after description panel else append
      const desc = Array.from(scopeChildren(wrapper, '.woocommerce-Tabs-panel, [id^="tab-"]')).find(ch => (ch.className||'').toLowerCase().indexOf('description') !== -1 || ch.id === 'tab-description');
      if(desc && desc.parentElement === wrapper && desc.nextSibling) wrapper.insertBefore(panel, desc.nextSibling);
      else wrapper.appendChild(panel);
      return panel;
    }
    function findTabHeader(wrapper){
      const listSelectors=['ul.wc-tabs','ul.tabs','.wc-tabs-wrapper ul','.woocommerce-tabs ul'];
      let list=null;
      for(const s of listSelectors){ list = wrapper.querySelector ? wrapper.querySelector(s) : null; if(list) break; }
      if(!list) list = document.querySelector('ul.wc-tabs, ul.tabs, .woocommerce-tabs ul');
      return list;
    }
    function createTabHeaderIfMissing(wrapper, panelId, panelLabelText){
      const tabList = findTabHeader(wrapper);
      if(!tabList) return null;
      const existing = tabList.querySelector('[aria-controls="' + panelId + '"], a[href="#' + panelId + '"], li#tab-title-' + panelId);
      if(existing) return existing.closest('li') || existing;
      const li = document.createElement('li');
      li.id = 'tab-title-' + panelId;
      li.className = 'additional_information_tab';
      li.setAttribute('role','presentation');
      const a = document.createElement('a');
      a.setAttribute('href','#' + panelId);
      a.setAttribute('role','tab');
      a.setAttribute('aria-controls', panelId);
      a.setAttribute('tabindex','0');
      a.textContent = panelLabelText || 'Additional information';
      li.appendChild(a);
      // try place after description tab
      const desc = tabList.querySelector('.description_tab, #tab-title-tab-description, li.tab-item--description, a[href="#tab-description"]');
      if(desc && desc.parentElement === tabList && desc.nextSibling) tabList.insertBefore(li, desc.nextSibling);
      else tabList.appendChild(li);
      // basic click behavior to show/hide panels (keeps UX functional if theme JS not present)
      a.addEventListener('click', function(ev){
        ev.preventDefault();
        Array.from(tabList.children).forEach(ch=>ch.classList && ch.classList.remove('active'));
        li.classList.add('active');
        const panels = wrapper.querySelectorAll('.woocommerce-Tabs-panel, [id^="tab-"]');
        panels.forEach(p => {
          if(p.id === panelId){ p.style.display = ''; p.classList && p.classList.add('active'); }
          else { p.style.display = 'none'; p.classList && p.classList.remove('active'); }
        });
      }, {passive:false});
      return li;
    }

    function chooseOrCreateCorrectPanel(){
      const wrapper = findTabsWrapper();
      if(!wrapper) return null;
      const desiredId = 'tab-additional_information';
      let correctPanel = findPanelDirect(wrapper, desiredId);
      if(correctPanel && correctPanel.parentElement !== wrapper){
        const alt = findPanelDirect(wrapper, desiredId);
        if(alt && alt.parentElement === wrapper) correctPanel = alt;
        else correctPanel = createPanelUnderWrapper(wrapper, desiredId, 'tab-title-' + desiredId);
      } else if(!correctPanel){
        correctPanel = createPanelUnderWrapper(wrapper, desiredId, 'tab-title-' + desiredId);
      }
      // ensure header exists
      const tabList = findTabHeader(wrapper);
      if(tabList){
        let labelText = null;
        const globalAnchor = document.querySelector('a[href="#' + desiredId + '"], [aria-controls="' + desiredId + '"]');
        if(globalAnchor && globalAnchor.textContent && globalAnchor.textContent.trim()) labelText = globalAnchor.textContent.trim();
        if(!labelText){
          const altLi = document.querySelector('.additional_information_tab, li[id*="additional_information"], a[href*="additional_information"]');
          if(altLi && altLi.textContent && altLi.textContent.trim()) labelText = altLi.textContent.trim();
        }
        if(!labelText) labelText = 'Additional information';
        createTabHeaderIfMissing(wrapper, correctPanel.id, labelText);
      }
      return correctPanel;
    }

    // Core: ensure single attributes table inside correct panel
    function enforceSingleAttributes() {
      try {
        const panel = chooseOrCreateCorrectPanel();
        if(!panel) { console.log(PFX, 'no tabs wrapper/panel found'); return false; }

        const list = Array.from(document.querySelectorAll(ATTR_SEL));
        if(list.length === 0) { console.log(PFX, 'no attributes table found'); return false; }

        // If any table is already inside target panel, keep the first such as canonical
        let canonical = list.find(t => panel.contains(t));
        if(!canonical) {
          // prefer the first table that is visible (non-zero size) or just use list[0]
          canonical = list.find(t=> (t.offsetWidth>0 || t.offsetHeight>0)) || list[0];
          // move canonical into panel
          let container = panel.querySelector('#reeid-attrs-wrapper');
          if(!container){ container = document.createElement('div'); container.id = 'reeid-attrs-wrapper'; container.style.padding='0'; container.style.margin='0'; panel.appendChild(container); }
          container.appendChild(canonical);
          console.log(PFX, 'moved canonical attributes table into', '#'+panel.id);
        } else {
          console.log(PFX, 'canonical attributes already inside', '#'+panel.id);
        }

        // Remove any other attributes tables (duplicates) anywhere except the canonical
        Array.from(document.querySelectorAll(ATTR_SEL)).forEach(t => {
          if(t === canonical) return;
          // safe removal: mark for server-side debugging then remove
          try { t.setAttribute('data-reeid-removed', '1'); } catch(e){}
          if(t.parentElement) t.parentElement.removeChild(t);
          console.log(PFX, 'removed duplicate attributes table (removed from DOM)');
        });

        // tidy: ensure panel visible if it's the active tab; otherwise keep as panel
        try { panel.classList.add('reeid-attrs-present'); } catch(e){}
        return true;
      } catch(e) {
        console.log(PFX, 'error during enforceSingleAttributes', e && e.message);
        return false;
      }
    }

    // attempt repeatedly briefly; if not successful, observe mutations
    let tries = 0, maxTries = 12, interval = 250, timer = null, obs = null;
    function attemptOnce() {
      tries++;
      const ok = enforceSingleAttributes();
      if(ok) { if(timer) { clearInterval(timer); timer = null; } if(obs) { obs.disconnect(); obs = null; } return true; }
      if(tries >= maxTries) {
        if(timer) { clearInterval(timer); timer = null; }
        // set up mutation observer to catch late renders/overwrites
        obs = new MutationObserver(function(muts, o){
          if(enforceSingleAttributes()) { o.disconnect(); obs = null; }
        });
        obs.observe(document.documentElement || document.body, { childList:true, subtree:true, attributes:true });
      }
      return false;
    }

    function start() {
      attemptOnce();
      if(!timer) timer = setInterval(attemptOnce, interval);
      setTimeout(function(){ if(timer){ clearInterval(timer); timer = null; } }, (maxTries + 3) * interval);
    }

    if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {passive:true});
    else start();

    // expose quick debug for console: window.reeidEnforceAttrsNow()
    try { window.reeidEnforceAttrsNow = enforceSingleAttributes; } catch(e){}
  } catch(e) {}
})();
</script>


        <?php
    }, 9999);
}

/* =======================================================
   SECTION: LOCALIZE & ENQUEUE — REEID_TRANSLATE global object
   Purpose: register a small plugin script, localize REEID_TRANSLATE
            and provide a safe inline fallback for contexts where
            the localized inline block might not render.
   Place: plugin main file (reeid-translate.php) near other enqueue code
   ======================================================= */
if (! function_exists('reeid_register_localize_asset')) {
    function reeid_register_localize_asset() {

        // safety: require constants to be defined (plugin main defines these)
        if (! defined('REEID_TRANSLATE_DIR') || ! defined('REEID_TRANSLATE_URL')) {
            return;
        }

        $handle = 'reeid-translate-localize';
        $src    = REEID_TRANSLATE_URL . 'assets/js/reeid-localize.js';
        $path   = REEID_TRANSLATE_DIR . 'assets/js/reeid-localize.js';

        // versioning by filemtime when file exists; otherwise use plugin version fallback.
        $ver = file_exists($path) ? filemtime($path) : ( defined('REEID_PLUGIN_VERSION') ? REEID_PLUGIN_VERSION : null );

        // Register + enqueue a tiny JS file (no content required, but ensures a handle prints)
        if (! wp_script_is($handle, 'registered')) {
            wp_register_script( $handle, $src, array('jquery'), $ver, true );
        }
        wp_enqueue_script( $handle );

        // Localize object for use by JS — nonce matches your AJAX handlers.
        $localized = array(
            'nonce'    => wp_create_nonce( 'reeid_translate_nonce_action' ),
            'ajax_url' => admin_url( 'admin-ajax.php' ),
        );
        wp_localize_script( $handle, 'REEID_TRANSLATE', $localized );

        // Also add a small inline fallback to protect against rare cases where wp_localize_script
        // doesn't print (Elementor/editor variations). This will create the object only if none.
        $fallback_js = sprintf(
            "if (typeof window.REEID_TRANSLATE === 'undefined' || !window.REEID_TRANSLATE) { window.REEID_TRANSLATE = %s; }",
            wp_json_encode( $localized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
        );
        wp_add_inline_script( $handle, $fallback_js, 'after' );
    }

    // Hook in both frontend and admin; choose priority so other plugin enqueues run before ours.
    add_action( 'wp_enqueue_scripts',    'reeid_register_localize_asset', 20 );
    add_action( 'admin_enqueue_scripts', 'reeid_register_localize_asset', 20 );

    // Elementor-specific hooks — safe to add if Elementor is active; otherwise hook does nothing.
    add_action( 'elementor/editor/after_enqueue_scripts', 'reeid_register_localize_asset', 20 );
    add_action( 'elementor/frontend/after_enqueue_styles',  'reeid_register_localize_asset', 20 );
}

/**=======================================================
 SECTION 0.3: SAFETY & UTILITIES (repo compliance helpers)
 - Centralizes nonce verification and unslashing/sanitization.
 - Neutral logger: disabled by default to avoid user box spam.
 - No ini_set() usage anywhere in this file.
 ========================================================*/
if (!defined('ABSPATH')) exit;


// i18n must load on/after init (WP 6.7+ warning fix)
add_action('init', 'reeid_load_textdomain');
function reeid_load_textdomain()
{
    load_plugin_textdomain(
        'reeid-translate',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}


add_filter('the_content', function ($content) {
    if (!is_singular('product')) return $content;
    global $post;
    if (!$post || $post->post_type !== 'product') return $content;
    if (!function_exists('reeid_wc_effective_lang') || !function_exists('reeid_wc_get_translation_meta')) return $content;
    $lang = reeid_wc_effective_lang('en');
    $tr = reeid_wc_get_translation_meta($post->ID, $lang);
    if (!empty($tr['content'])) return $tr['content'];
    return $content;
}, 999);

if (!defined('REEID_TRANSLATE_DIR')) {
    define('REEID_TRANSLATE_DIR', plugin_dir_path(__FILE__));
    define('REEID_TRANSLATE_URL', plugin_dir_url(__FILE__));
    
}

/* Back-compat for older constant name */
if (!defined('REEID_SEO_HELPERS_PATH')) {
    define('REEID_SEO_HELPERS_PATH', REEID_TRANSLATE_DIR);
}

/* Optional defaults */
if (!defined('REEID_HREFLANG_OUTPUT')) {
    define('REEID_HREFLANG_OUTPUT', true);
}

/**
 * Load plugin modules in a safe order after all plugins are loaded.
 * Order:
 *  1) translator (optional)
 *  2) (hreflang.php) DISABLED — use seo-sync.php as canonical
 *  3) focus keyphrase sync
 *  4) SEO title sync + hreflang output
 */
add_action('plugins_loaded', function () {
    $base = REEID_TRANSLATE_DIR . 'includes/';

    // 1) Translator (so other modules can call it if present)
    $file = $base . 'translator.php';
    if (file_exists($file)) require_once $file;

    // 2) Hreflang generator — disabled to avoid duplicate output
    // $file = $base . 'hreflang.php';
    // if (file_exists($file)) require_once $file;
    // 2.5) WC inline helpers (tiny, used by Section 18/19 only)
    if (! defined('REEID_WC_INLINE_HELPERS_LOADED')) {
        $f = REEID_TRANSLATE_DIR . 'includes/wc-inline.php';
        if (file_exists($f)) {
            require_once $f;
            if (! defined('REEID_WC_INLINE_HELPERS_LOADED')) {
                define('REEID_WC_INLINE_HELPERS_LOADED', true);
            }
        }
    }


    // 3) Focus keyphrase + meta description sync
    $file = $base . 'reeid-focuskw-sync.php';
    if (file_exists($file)) require_once $file;

    // 4) SEO title sync + hreflang output
    $file = $base . 'seo-sync.php';
    if (file_exists($file)) require_once $file;
}, 1);




/** Toggle debug logs explicitly (kept OFF for .org compliance). */
if (!defined('REEID_DEBUG')) {
    define('REEID_DEBUG', false);
}

/** Safe, no-op logger unless explicitly enabled by the developer. */
if (!function_exists('reeid_debug_log')) {
    function reeid_debug_log($label, $data = null)
    {
        $file = WP_CONTENT_DIR . '/uploads/reeid-debug.log';
        $line = '[' . gmdate('c') . "] {$label}: ";
        if (is_array($data) || is_object($data)) {
            $line .= wp_json_encode($data, JSON_UNESCAPED_UNICODE);
        } else {
            $line .= (string) $data;
        }
        $line .= "\n";
        file_put_contents($file, $line, FILE_APPEND);
    }
}

/** Verify a nonce coming from GET safely. */
if (!function_exists('reeid_verify_get_nonce')) {
    function reeid_verify_get_nonce($action, $param = '_wpnonce')
    {
        $raw = filter_input(INPUT_GET, $param, FILTER_UNSAFE_RAW);
        $raw = is_string($raw) ? wp_unslash($raw) : '';
        $nonce = sanitize_text_field($raw);
        return ($nonce !== '' && wp_verify_nonce($nonce, $action));
    }
}

/** Safely fetch the requested action name. */
if (!function_exists('reeid_request_action')) {
    function reeid_request_action()
    {
        $raw_post = filter_input(INPUT_POST, 'action', FILTER_UNSAFE_RAW);
        $raw_get  = filter_input(INPUT_GET,  'action', FILTER_UNSAFE_RAW);
        $raw      = is_string($raw_post) ? $raw_post : (is_string($raw_get) ? $raw_get : '');
        $raw      = wp_unslash($raw);
        return sanitize_key($raw);
    }
}


/*==============================================================================
  SECTION 1: GLOBAL HELPERS
==============================================================================*/

/**
 * Keep Unicode-friendly slugs (allow native scripts), collapse invalid chars,
 * decode percent-encoding when valid, trim, and ensure a non-empty fallback.
 */
if (! function_exists('reeid_sanitize_native_slug')) {
    function reeid_sanitize_native_slug($slug)
    {
        $slug = (string) $slug;
        $slug = wp_strip_all_tags($slug);
        $slug = trim($slug);

        // Decode HTML entities and normalize dashes
        if (function_exists('html_entity_decode')) {
            $slug = html_entity_decode($slug, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        // NBSP → space
        $slug = preg_replace('/\x{00A0}/u', ' ', $slug);
        // Unicode dash variants → "-"
        $slug = preg_replace('/[\x{2010}\x{2011}\x{2012}\x{2013}\x{2014}\x{2212}]+/u', '-', $slug);

        // Decode %xx if present, but only keep if valid UTF-8
        if (strpos($slug, '%') !== false && preg_match('/%[0-9A-Fa-f]{2}/', $slug)) {
            $decoded = rawurldecode($slug);
            $is_utf8 = function_exists('seems_utf8') ? seems_utf8($decoded) : (bool) @mb_check_encoding($decoded, 'UTF-8');
            if ($is_utf8) {
                $slug = $decoded;
            }
        }

        // Remove reserved URL characters; keep native letters intact
        $slug = preg_replace('/[\/\?#\[\]@!$&\'()*+,;=%"<>|`^{}\\\\]/u', ' ', $slug);

        // Allow Unicode letters, numbers, combining marks, and hyphen; others → hyphen
        $slug = preg_replace('/[^\p{L}\p{N}\p{M}\-]+/u', '-', $slug);

        // Collapse hyphens & trim
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');

        // Lowercase ASCII only (leave non-ASCII as is)
        $slug = preg_replace_callback('/[A-Z]+/', static function($m){ return strtolower($m[0]); }, $slug);

        // Fallback if empty
        if ($slug === '') {
            if (function_exists('wp_generate_uuid4')) {
                $slug = 'translated-' . wp_generate_uuid4();
            } else {
                $slug = 'translated-' . uniqid('', true);
            }
        }

        // Limit length
        if (function_exists('mb_substr')) {
            $slug = mb_substr($slug, 0, 200, 'UTF-8');
        } else {
            $slug = substr($slug, 0, 200);
        }

        return $slug;
    }
}


/**
 * Coerce a variable that might be a post object/array/ID into int post_id.
 */
if (! function_exists('reeid_coerce_post_id')) {
    function reeid_coerce_post_id($maybe_post)
    {
        if (is_object($maybe_post)) {
            if (isset($maybe_post->ID)) {
                return (int) $maybe_post->ID;
            }
            // fallback: try cast if it's numeric-like
            return (int) $maybe_post;
        }
        if (is_array($maybe_post) && isset($maybe_post['ID'])) {
            return (int) $maybe_post['ID'];
        }
        return (int) $maybe_post;
    }
}

/**
 * Detect editor type for a post. Returns one of: 'elementor', 'gutenberg', 'classic'.
 * Uses safe heuristics based on common postmeta and content.
 */
if (! function_exists('reeid_detect_editor_type')) {
    function reeid_detect_editor_type($post_or_id)
    {
        $post_id = reeid_coerce_post_id($post_or_id);
        if ($post_id <= 0) {
            return 'classic';
        }

        // Elementor detection (meta keys used by Elementor)
        $edit_mode = get_post_meta($post_id, '_elementor_edit_mode', true);
        $has_data  = get_post_meta($post_id, '_elementor_data', true);

        if (
            'builder' === $edit_mode
            || (is_string($has_data) && $has_data !== '')
            || (is_array($has_data) && ! empty($has_data))
        ) {
            return 'elementor';
        }

        // Gutenberg: lightweight heuristic (blocks present)
        $post = get_post($post_id);
        if ($post && function_exists('has_blocks') && has_blocks($post->post_content)) {
            return 'gutenberg';
        }

        return 'classic';
    }
}

/* --- Small utility wrappers for regex/printf safety --- */

if (!function_exists('rt_regex_replacement')) {
    function rt_regex_replacement($s)
    {
        return strtr((string) $s, ['\\' => '\\\\', '$' => '\\$']);
    }
}

if (!function_exists('rt_regex_quote')) {
    function rt_regex_quote($text, $delim = '/')
    {
        return preg_quote((string) $text, (string) $delim);
    }
}

if (!function_exists('rt_printf_literal')) {
    function rt_printf_literal($s)
    {
        return str_replace('%', '%%', (string) $s);
    }
}

/* --- Prompt helpers: global custom instructions + merge --- */

/**
 * Read global "Custom Instructions (PRO)" from Settings.
 * Supports multiple legacy/alias option keys; returns the first non-empty.
 */
if (! function_exists('reeid_get_global_custom_instructions')) {
    function reeid_get_global_custom_instructions()
    {
        $candidates = array(
            'reeid_translation_custom_prompt', // primary (what you already set)
            'reeid_custom_instructions',
            'reeid_custom_prompt',
            'reeid_pro_custom_instructions',
        );
        foreach ($candidates as $key) {
            $raw = get_option($key, '');
            if (is_string($raw) && trim($raw) !== '') {
                // Sanitize for storage/usage: admin-provided text area — allow basic markup
                // but strip scripts/unsafe tags; limit length to reasonable size.
                $clean = trim(wp_kses_post($raw));
                if (function_exists('mb_substr')) {
                    $clean = mb_substr($clean, 0, 4000);
                } else {
                    $clean = substr($clean, 0, 4000);
                }
                return $clean;
            }
        }
        return '';
    }
}

/**
 * Merge global instructions + per-request prompt into canonical string.
 * Uses a clear separator to preserve layering and avoid accidental concatenation that confuses the model.
 */
if (! function_exists('reeid_effective_prompt')) {
    function reeid_effective_prompt($request_prompt = '')
    {
        // Return only the per-request prompt (sanitized). Global/admin prompt is applied only
        // by the canonical builder reeid_get_combined_prompt to avoid duplicate layering.
        if (! is_string($request_prompt) || trim($request_prompt) === '') {
            return '';
        }
        return trim( wp_kses_post( $request_prompt ) );
    }
}



/*==============================================================================
  SECTION 2: FRONT-END — EARLY ELEMENTOR SHIM 
  (All dynamic output escaped; runs only on front-end.)
==============================================================================*/
add_action(
    'wp_head',
    function () {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        // Build assets URL from the actual Elementor plugin
        if (file_exists(WP_PLUGIN_DIR . '/elementor/elementor.php')) {
            $assets = trailingslashit(plugins_url('', WP_PLUGIN_DIR . '/elementor/elementor.php')) . 'assets/';
        } else {
            // conservative fallback
            $assets = content_url('/plugins/elementor/assets/');
            $assets = trailingslashit($assets);
        }

        $ver   = defined('ELEMENTOR_VERSION') ? (string) ELEMENTOR_VERSION : '3.x';
        $upurl = content_url('uploads/');
        $isdbg = (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) ? 'true' : 'false';

        // Print minimal inline config (escaped).
        echo "<script>(function(){\n";
        echo "if(!window.elementorFrontendConfig){\n";
        echo "var cfg=window.elementorFrontendConfig={\n";
        echo 'urls:{assets:"' . esc_js($assets) . '",uploadUrl:"' . esc_js($upurl) . "\"},\n";
        echo 'environmentMode:{edit:false,wpPreview:false,isScriptDebug:' . esc_js($isdbg) . "},\n";
        echo 'version:"' . esc_js($ver) . "\",\n";
        echo "settings:{page:{}},\n";
        echo "responsive:{hasCustomBreakpoints:false,breakpoints:{}},\n";
        echo 'experimentalFeatures:{"nested-elements":"active","container":"active"}' . "\n";
        echo "};\n";
        echo "window.__webpack_public_path__=cfg.urls.assets.replace(/\\/+$/,'')+'/js/';\n";
        echo "}\n";
        echo "})();</script>\n";
    },
    0
);

/*==============================================================================
  SECTION 3: ROUTING — DISABLE CORE CANONICAL ON /{lang}/ PATHS
  Prevent WP canonical redirects from breaking language-prefixed URLs.
==============================================================================*/
remove_filter('template_redirect', 'redirect_canonical');
add_filter(
    'redirect_canonical',
    function ($redirect_url, $requested_url) {
        // sanitize and parse the incoming URL
        $raw_url = esc_url_raw((string) $requested_url);
        $parts   = wp_parse_url($raw_url);
        $path    = isset($parts['path']) ? (string) $parts['path'] : '';

        // if it’s "/zz/…" (two-letter lang at the front) stop WP canonical
        if (preg_match('#^/[a-z]{2}(?:/|$)#i', $path)) {
            return false;
        }
        return $redirect_url;
    },
    PHP_INT_MIN,
    2
);

/*==============================================================================
  SECTION 4: NATIVE-SLUG PRESERVATION — CONTROLLED
  When $GLOBALS['reeid_force_native_slug'] is truthy, the sanitize_title filter
  will return the raw input, preventing percent-encoding of native scripts.
==============================================================================*/
add_filter(
    'sanitize_title',
    function ($slug, $raw_title, $context) {
        if (! empty($GLOBALS['reeid_force_native_slug'])) {
            // Return raw title intentionally; caller must sanitize beforehand.
            return (string) $raw_title;
        }
        return $slug;
    },
    10,
    3
);


/*==============================================================================
  SECTION 5: HELPERS — Supported Languages, Flags, Premium Logic
==============================================================================*/

/**
 * Returns true if premium features are enabled (valid license key).
 */
if (! function_exists('reeid_is_premium')) {
    function reeid_is_premium()
    {
        $status = (string) get_option('reeid_license_status', '');
        return ($status === 'valid');
    }
}

/**
 * Returns all supported languages (code => label).
 */

if (! function_exists('reeid_get_supported_languages')) {
    function reeid_get_supported_languages()
    {
        // Canonical list (unsorted here; sorted by label below)
        $langs = array(
            'ar'    => 'Arabic',
            'bg'    => 'Bulgarian',
            'bn'    => 'Bengali',
            'cs'    => 'Czech',
            'da'    => 'Danish',
            'de'    => 'German',
            'el'    => 'Greek',
            'en'    => 'English',
            'es'    => 'Spanish',
            'fa'    => 'Persian',
            'fi'    => 'Finnish',
            'fr'    => 'French',
            'gu'    => 'Gujarati',
            'he'    => 'Hebrew',
            'hi'    => 'Hindi',
            'hr'    => 'Croatian',
            'hu'    => 'Hungarian',
            'id'    => 'Indonesian',
            'it'    => 'Italian',
            'ja'    => 'Japanese',
            'km'    => 'Khmer',
            'ko'    => 'Korean',
            'lo'    => 'Lao',
            'mr'    => 'Marathi',
            'ms'    => 'Malay',
            'my'    => 'Burmese',       
            'nb'    => 'Norwegian',
            'ne'    => 'Nepali',
            'nl'    => 'Dutch',
            'pl'    => 'Polish',
            'pt'    => 'Portuguese',
            'ro'    => 'Romanian',
            'ru'    => 'Russian',
            'si'    => 'Sinhala',
            'sk'    => 'Slovak',
            'sl'    => 'Slovenian',
            'sr'    => 'Serbian',
            'sv'    => 'Swedish',
            'ta'    => 'Tamil',
            'te'    => 'Telugu',
            'th'    => 'Thai',
            'tl'    => 'Filipino',
            'tr'    => 'Turkish',
            'uk'    => 'Ukrainian',
            'ur'    => 'Urdu',
            'vi'    => 'Vietnamese',
            'zh'    => 'Chinese',
        );

        asort($langs, SORT_NATURAL | SORT_FLAG_CASE);
        return $langs;
    }
}

/**
 * Language → flag asset (ISO country for /assets/flags/*.svg)
 * Keys must match canonical codes above.
 */
if (! function_exists('reeid_get_language_flags')) {
    function reeid_get_language_flags()
    {
        return array(
            'ar'    => 'sa',
            'bg'    => 'bg',
            'bn'    => 'bd',
            'cs'    => 'cz',
            'da'    => 'dk',
            'de'    => 'de',
            'el'    => 'gr',
            'en'    => 'us',
            'es'    => 'es',
            'fa'    => 'ir',
            'fi'    => 'fi',
            'fr'    => 'fr',
            'gu'    => 'in',
            'he'    => 'il',
            'hi'    => 'in',
            'hr'    => 'hr',
            'hu'    => 'hu',
            'id'    => 'id',
            'it'    => 'it',
            'ja'    => 'jp',
            'km'    => 'kh',
            'ko'    => 'kr',
            'lo'    => 'la',
            'mr'    => 'in',
            'ms'    => 'my', // Malaysia
            'my'    => 'mm', // Myanmar (Burmese) — NEW
            'nb'    => 'no',
            'ne'    => 'np',
            'nl'    => 'nl',
            'pl'    => 'pl',
            'pt'    => 'pt',
            'pt'    => 'br',
            'ro'    => 'ro',
            'ru'    => 'ru',
            'si'    => 'lk',
            'sk'    => 'sk',
            'sl'    => 'si',
            'sr'    => 'rs',
            'sv'    => 'se',
            'ta'    => 'in',
            'te'    => 'in',
            'th'    => 'th',
            'tl'    => 'ph',
            'tr'    => 'tr',
            'uk'    => 'ua',
            'ur'    => 'pk',
            'vi'    => 'vn',
            'zh'    => 'cn',
        );
    }
}

// /**
//  * Resolve an incoming language/locale to your canonical supported key.
//  */
// if (! function_exists('reeid_resolve_language_code')) {
//     function reeid_resolve_language_code(string $code): string
//     {
//         $c = strtolower(trim($code));
//         if ($c === '') return '';
//         $c = str_replace('_', '-', $c); // tolerate WP locales

//         $supported = array_keys(reeid_get_supported_languages());

//         // Legacy/alias heads
//         $head_alias = [
//             'no' => 'nb',
//             'iw' => 'he',
//             'in' => 'id',
//         ];

//         // Exact supported hit
//         if (in_array($c, $supported, true)) {
//             return $c;
//         }

//         // Apply alias if the whole input is a bare head
//         if (isset($head_alias[$c])) {
//             $c = $head_alias[$c];
//             if (in_array($c, $supported, true)) return $c;
//         }

//         // Split head/region
//         $head = $c;
//         $region = '';
//         if (strpos($c, '-') !== false) {
//             $head   = substr($c, 0, strpos($c, '-'));
//             $region = substr($c, strpos($c, '-') + 1);
//         }
//         // Alias the head if needed
//         if (isset($head_alias[$head])) {
//             $head = $head_alias[$head];
//         }

//         // Chinese collapse (single canonical)
//         if ($head === 'zh' && in_array('zh', $supported, true)) {
//             return 'zh';
//         }

//         // Portuguese split logic
//         if ($head === 'pt') {
//             // If region explicitly says BR/PT, map accordingly
//             if ($region === 'br' && in_array('pt-br', $supported, true)) {
//                 return 'pt-br';
//             }
//             if ($region === 'pt' && in_array('pt-pt', $supported, true)) {
//                 return 'pt-pt';
//             }
//             // For any other pt-XX or bare pt, choose a default canonical:
//             // default = European (pt-pt). Change to pt-br if you prefer.
//             if (in_array('pt-pt', $supported, true)) {
//                 return 'pt-pt';
//             }
//         }

//         // Italian (regional canonical)
//         if ($head === 'it' && in_array('it-it', $supported, true)) {
//             return 'it-it';
//         }

//         // Burmese (regional canonical)
//         if ($head === 'my' && in_array('my-mm', $supported, true)) {
//             return 'my-mm';
//         }

//         // Generic “Burmese pattern” for other regionals that may be added later:
//         // If there exists exactly one supported key with this head and a region, use it.
//         $matches = array_values(array_filter($supported, static function ($k) use ($head) {
//             return strpos($k, $head . '-') === 0;
//         }));
//         if (count($matches) === 1) {
//             return $matches[0];
//         }

//         // If base head itself is a canonical, use it
//         if (in_array($head, $supported, true)) {
//             return $head;
//         }

//         // Fallback: return cleaned input
//         return $c;
//     }
// }

/**
 * Returns array of allowed languages (10 in free, all in premium).
 * NOTE: Use canonical codes here (pt-pt for free tier).
 */
if (! function_exists('reeid_get_allowed_languages')) {
    function reeid_get_allowed_languages()
    {
        $all = reeid_get_supported_languages();

        if (reeid_is_premium()) {
            return $all;
        }

        // Free tier: 10 popular canonicals (adjust as needed)
        $free = array('en', 'es', 'fr', 'de', 'zh', 'ja', 'ar', 'ru', 'th', 'it' /* or 'pt-pt' instead of 'it-it' if you want */);

        return array_intersect_key($all, array_flip($free));
    }
}

/**
 * Returns true if bulk translation is allowed (premium only).
 */
if (! function_exists('reeid_can_bulk_translate')) {
    function reeid_can_bulk_translate()
    {
        return reeid_is_premium();
    }
}

/**
 * Returns true if custom prompt is allowed (premium only).
 */
if (! function_exists('reeid_can_use_custom_prompt')) {
    function reeid_can_use_custom_prompt()
    {
        return reeid_is_premium();
    }
}

/* === Optional convenience: check if a code is allowed after resolution === */
if (! function_exists('reeid_is_language_allowed')) {
    function reeid_is_language_allowed(string $code): bool
    {
        $resolved = reeid_resolve_language_code($code);
        $allowed  = reeid_get_allowed_languages();
        return isset($allowed[$resolved]);
    }
}

/*==============================================================================
  SECTION 6: ACTIVATION & DEACTIVATION
==============================================================================*/

/** Register rewrite rules (guard if function exists elsewhere). */
if (! function_exists('reeid_register_rewrite_rules')) {
    function reeid_register_rewrite_rules()
    {
        // Intentionally empty here; real rules should live in the routing section.
    }
}

/** On activation: ensure rules are registered, then flush. */
function reeid_activation()
{
    if (function_exists('reeid_register_rewrite_rules')) {
        reeid_register_rewrite_rules();
    }
    flush_rewrite_rules();
}

/** On deactivation: flush rules. */
function reeid_deactivation()
{
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'reeid_activation');
register_deactivation_hook(__FILE__, 'reeid_deactivation');

/*==============================================================================
  SECTION 7: ADMIN SETTINGS REGISTRATION
==============================================================================*/

// Admin-only: prevent any settings/forms code from being reachable on frontend.
if (is_admin()) {
    add_action('admin_init', 'reeid_register_settings');
    add_action('admin_menu', 'reeid_add_settings_page');
}


function reeid_register_settings()
{
    register_setting('reeid_translate_settings', 'reeid_openai_api_key', array(
        'type'              => 'string',
        'sanitize_callback' => function ($v) {
            return sanitize_text_field((string) wp_unslash($v));
        },
        'default'           => '',
    ));

    // Enforce gpt-4o only.
    register_setting('reeid_translate_settings', 'reeid_openai_model', array(
        'type'              => 'string',
        'sanitize_callback' => 'reeid_sanitize_model',
        'default'           => 'gpt-4o',
    ));

    register_setting('reeid_translate_settings', 'reeid_translation_tones', array(
        'type'              => 'array',
        'sanitize_callback' => 'reeid_sanitize_tones',
        'default'           => array('Neutral'),
    ));

    register_setting('reeid_translate_settings', 'reeid_translation_custom_prompt', array(
        'type'              => 'string',
        'sanitize_callback' => function ($v) {
            return wp_strip_all_tags((string) wp_unslash($v));
        },
        'default'           => '',
    ));

    register_setting('reeid_translate_settings', 'reeid_translation_source_lang', array(
        'type'              => 'string',
        'sanitize_callback' => function ($v) {
            return sanitize_text_field((string) wp_unslash($v));
        },
        'default'           => 'en',
    ));

    register_setting('reeid_translate_settings', 'reeid_bulk_translation_langs', array(
        'type'              => 'array',
        'sanitize_callback' => 'reeid_sanitize_bulk_langs',
        'default'           => array(),
    ));

    add_settings_section(
        'reeid_section_general',
        __('General Settings', 'reeid-translate'),
        function () {
            echo '<p>' . esc_html__('Configure your API key, tone presets, default source language, custom instructions (PRO), bulk languages (PRO), and license key.', 'reeid-translate') . '</p>';
        },
        'reeid-translate-settings'
    );

    add_settings_field(
        'reeid_openai_api_key',
        __('OpenAI API Key', 'reeid-translate'),
        'reeid_render_api_key_field',
        'reeid-translate-settings',
        'reeid_section_general'
    );

    add_settings_field(
        'reeid_translation_tones',
        __('Tone / Style', 'reeid-translate'),
        'reeid_render_tone_checkbox_list',
        'reeid-translate-settings',
        'reeid_section_general'
    );

    add_settings_field(
        'reeid_translation_source_lang',
        __('Default Source Language', 'reeid-translate'),
        'reeid_render_source_lang_field',
        'reeid-translate-settings',
        'reeid_section_general'
    );

    add_settings_field(
        'reeid_translation_custom_prompt',
        __('Custom Instructions (PRO)', 'reeid-translate'),
        'reeid_render_custom_prompt_field',
        'reeid-translate-settings',
        'reeid_section_general'
    );

    add_settings_field(
        'reeid_bulk_translation_langs',
        __('Bulk Translation Languages (PRO)', 'reeid-translate'),
        'reeid_render_bulk_langs_field',
        'reeid-translate-settings',
        'reeid_section_general'
    );

    add_settings_field(
        'reeid_pro_license_key',
        __('License Key', 'reeid-translate'),
        'reeid_render_license_key_field',
        'reeid-translate-settings',
        'reeid_section_general'
    );
}

/** Strict model sanitizer — locks to gpt-4o. */
function reeid_sanitize_model($input)
{
    $input = sanitize_text_field((string) wp_unslash($input));
    return 'gpt-4o';
}

/** Sanitize tone array (max 2). */
function reeid_sanitize_tones($input)
{
    $valid = array('Neutral', 'Formal', 'Informal', 'Friendly', 'Technical', 'Persuasive', 'Concise', 'Verbose');
    $out   = array();
    if (is_array($input)) {
        foreach ($input as $tone) {
            $tone = sanitize_text_field((string) wp_unslash($tone));
            if (in_array($tone, $valid, true)) {
                $out[] = $tone;
            }
            if (count($out) >= 2) {
                break;
            }
        }
    }
    return $out ?: array('Neutral');
}

/** Sanitize bulk language array — PRO only. */
function reeid_sanitize_bulk_langs($input)
{
    if (! function_exists('reeid_is_premium') || ! reeid_is_premium()) {
        return array();
    }
    $allowed = array_keys(reeid_get_supported_languages());
    $out     = array();
    if (is_array($input)) {
        foreach ($input as $lang) {
            $lang = sanitize_text_field((string) wp_unslash($lang));
            if (in_array($lang, $allowed, true)) {
                $out[] = $lang;
            }
        }
    }
    return $out;
}

/** Render API key field with validation button. */
function reeid_render_api_key_field()
{
    $key    = get_option('reeid_openai_api_key', '');
    $status = get_option('reeid_openai_status', '');
?>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
        <input type="password"
            name="reeid_openai_api_key"
            value="<?php echo esc_attr($key); ?>"
            style="width:300px;"
            autocomplete="off"
            id="reeid_openai_api_key">
        <button type="button" class="button" id="reeid_validate_openai_key"><?php esc_html_e('Validate API Key', 'reeid-translate'); ?></button>
    </div>
    <div id="reeid_openai_key_status">
        <?php if ($status === 'valid') : ?>
            <span style="color:green;font-weight:bold;">&#10004; <?php esc_html_e('Valid API Key', 'reeid-translate'); ?></span>
        <?php elseif ($status === 'invalid') : ?>
            <span style="color:red;font-weight:bold;">&#10060; <?php esc_html_e('Invalid API Key', 'reeid-translate'); ?></span>
        <?php endif; ?>
    </div>
<?php
}

/** (Kept for completeness; UI disabled.) */
function reeid_render_model_field()
{
    $val     = 'gpt-4o';
    $options = array('gpt-4o' => 'gpt-4o (Fast, affordable, precise)');
    echo '<select name="reeid_openai_model" style="width:300px;" disabled>';
    foreach ($options as $model => $label) {
        printf(
            '<option value="%s"%s>%s</option>',
            esc_attr($model),
            selected($val, $model, false),
            esc_html($label)
        );
    }
    echo '</select>';
    echo '<p class="description" style="margin-top:6px;">' .
        esc_html__('Model selection is locked to gpt-4o in this build.', 'reeid-translate') .
        '</p>';
}

/** Render tone selection field. */
function reeid_render_tone_checkbox_list()
{
    $presets = array('Neutral', 'Formal', 'Informal', 'Friendly', 'Technical', 'Persuasive', 'Concise', 'Verbose');
    $current = (array) get_option('reeid_translation_tones', array('Neutral'));
?>
    <select name="reeid_translation_tones[]"
        multiple
        size="8"
        style="width:300px;">
        <?php foreach ($presets as $tone) : ?>
            <option value="<?php echo esc_attr($tone); ?>" <?php selected(in_array($tone, $current, true)); ?>>
                <?php echo esc_html($tone); ?>
            </option>
        <?php endforeach; ?>
    </select>
    <p class="description">
        <?php esc_html_e('Hold Ctrl (⌘) to select up to 2 tones.', 'reeid-translate'); ?>
    </p>
<?php
}

/** Render source language field. */
function reeid_render_source_lang_field()
{
    $cur = get_option('reeid_translation_source_lang', 'en');
    $all = reeid_get_supported_languages();
    echo '<select name="reeid_translation_source_lang" style="width:300px;">';
    foreach ($all as $code => $label) {
        printf(
            '<option value="%s"%s>%s</option>',
            esc_attr($code),
            selected($cur, $code, false),
            esc_html($label)
        );
    }
    echo '</select>';
}

/** Render custom prompt field — PRO only. */
function reeid_render_custom_prompt_field()
{
    $val        = get_option('reeid_translation_custom_prompt', '');
    $status     = get_option('reeid_license_status', 'invalid');
    $is_premium = function_exists('reeid_is_premium') && reeid_is_premium();
    $placeholder = ($status !== 'valid') ? esc_attr__('PRO feature – upgrade to unlock.', 'reeid-translate') : '';
?>
    <textarea name="reeid_translation_custom_prompt"
        rows="5"
        style="width:100%;"
        <?php echo $is_premium ? '' : 'readonly'; ?>
        placeholder="<?php echo esc_attr($placeholder); ?>"><?php echo esc_textarea($val); ?></textarea>
    <?php if (! $is_premium) : ?>
        <p class="description" style="color:#b00;"><?php esc_html_e('Custom Instructions are available in the PRO version.', 'reeid-translate'); ?></p>
    <?php endif; ?>
<?php
}

/** Render bulk languages field — PRO only. */
function reeid_render_bulk_langs_field()
{
    $is_premium = function_exists('reeid_is_premium') && reeid_is_premium();
    $current    = (array) get_option('reeid_bulk_translation_langs', array());
    $supported  = reeid_get_supported_languages();

    echo '<select name="reeid_bulk_translation_langs[]" multiple size="8" style="width:300px;" ' . ($is_premium ? '' : 'disabled') . '>';
    foreach ($supported as $code => $label) {
        printf(
            '<option value="%s"%s>%s</option>',
            esc_attr($code),
            ($is_premium && in_array($code, $current, true)) ? ' selected' : '',
            esc_html($label)
        );
    }
    echo '</select>';

    if ($is_premium) {
        echo '<p class="description">' .
            esc_html__('Hold Ctrl (⌘) to select multiple languages for bulk translation.', 'reeid-translate') .
            '</p>';
    } else {
        echo '<p class="description" style="color:#b00;">' .
            esc_html__('Bulk translation is a PRO feature. Upgrade to enable.', 'reeid-translate') .
            '</p>';
    }
}

/** Render License Key field with validation and status. */
function reeid_render_license_key_field()
{
    $key    = get_option('reeid_pro_license_key', '');
    $status = get_option('reeid_license_status', 'invalid');

    echo '<div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">';

    printf(
        '<input type="text" id="reeid_pro_license_key" name="reeid_pro_license_key" value="%s" style="width:300px;" placeholder="%s">',
        esc_attr($key),
        esc_attr__('Enter your PRO license key', 'reeid-translate')
    );

    echo '<button type="button" class="button" id="reeid_validate_key">' .
        esc_html__('Validate License Key', 'reeid-translate') .
        '</button>';

    echo '</div>';

    echo '<div id="reeid_license_key_status" style="margin:4px 0 10px; font-weight:bold;">';
    if ($status === 'valid') {
        echo '<span style="color:green;">&#10004; ' . esc_html__('License key is valid.', 'reeid-translate') . '</span>';
    } else {
        echo '<span style="color:red;">&#10060; ' . esc_html__('License key is invalid. Only basic functionality is available.', 'reeid-translate') . '</span>';
    }
    echo '</div>';

    if ($status !== 'valid') {
        $pro_url = 'https://reeid.com/reeid-translation-plugin/';
        echo '<a href="' . esc_url($pro_url) . '" target="_blank" rel="noopener" ' .
            'style="display:inline-block;margin-top:4px;padding:6px 14px;background:#ec6d00;color:#fff;' .
            'font-weight:600;border-radius:4px;text-decoration:none;font-size:13px;">' .
            '&#128275; ' . esc_html__('Get PRO Version', 'reeid-translate') .
            '</a>';
    }
}

/* ===========================================================
 * Canonical: get enabled target languages from Admin Settings
 * Used by: Gutenberg/Classic/Woo bulk + Elementor bulk + background jobs
 * =========================================================== */
if (! function_exists('reeid_get_enabled_languages')) {
    function reeid_get_enabled_languages(): array
    {
        static $cache = null;
        if (is_array($cache)) return $cache;

        // 1) Gather from known option keys (array or CSV)
        $candidates = [
            'reeid_bulk_translation_langs',        // current
            'reeid_bulk_languages',                // legacy
            'reeid_enabled_languages',             // alt
            'reeid_translation_target_languages',  // alt
            'reeid_translation_languages',         // alt
        ];
        $acc = [];
        foreach ($candidates as $opt) {
            $val = get_option($opt, []);
            if (is_array($val)) {
                foreach ($val as $v) {
                    $acc[] = (string)$v;
                }
            } elseif (is_string($val) && $val !== '') {
                $acc = array_merge($acc, array_map('trim', explode(',', $val)));
            }
        }

        // 2) Sanitize + uniq (codes like "de", "zh-cn")
        $acc = array_values(array_unique(array_map(static function ($l) {
            $l = strtolower(trim((string)$l));
            $l = preg_replace('/[^a-z0-9\-_]/i', '', $l);
            return $l;
        }, $acc)));

        // 3) Filter empties
        $acc = array_values(array_filter($acc, static fn($l) => $l !== ''));

        // 4) Optional: intersect with supported list if helper exists
        if (function_exists('reeid_get_supported_languages')) {
            $supported = array_keys((array) reeid_get_supported_languages());
            $acc = array_values(array_intersect($acc, $supported));
        }

        // 5) Cache
        $cache = $acc;
        return $cache;
    }
}


/*==============================================================================
  SECTION 8: SETTINGS PAGE MARKUP
==============================================================================*/

function reeid_add_settings_page()
{
    add_options_page(
        __('REEID Translate Settings', 'reeid-translate'),
        __('REEID Translate', 'reeid-translate'),
        'manage_options',
        'reeid-translate-settings',
        'reeid_render_settings_page' // ← do NOT call this directly anywhere else
    );
}

/**
 * Renders the FAQ tab for the REEID Translate settings page (Accordion style).
 */
function reeid_render_faq_tab()
{
    // Strict admin guard (renderer)
    if (! is_admin() || ! current_user_can('manage_options')) {
        echo '<div class="notice notice-error"><p>' . esc_html__('Unauthorized.', 'reeid-translate') . '</p></div>';
        return;
    }
    $faqs_col1 = array(
        array(
            'q' => __('How do I translate a post or page?', 'reeid-translate'),
            'a' => __('Use the "REEID Translate" Meta-box in the editor panel, or the Elementor sidebar. Pick a language, tone, or refine translation using Custom Prompt and click “Translate Now”.', 'reeid-translate'),
        ),
        array(
            'q' => __('Compatible with Elementor / Gutenberg / Classic?', 'reeid-translate'),
            'a' => __('Yes. The plugin auto-detects Elementor, Gutenberg, or Classic Editor and works perfectly preserving layout. The fastest way to go global!', 'reeid-translate'),
        ),
        array(
            'q' => __('What gets translated?', 'reeid-translate'),
            'a' => __('Titles, slugs, main content, SEO/meta fields, and supported editor blocks (Elementor/Gutenberg, Woo Commerce).', 'reeid-translate'),
        ),
        array(
            'q' => __('Do slugs stay native (non-Latin)?', 'reeid-translate'),
            'a' => __('Yes. Chinese, Thai, Arabic, etc. are preserved so URLs remain SEO-safe and readable. If a slug is taken, WordPress will ensure uniqueness.', 'reeid-translate'),
        ),
        array(
            'q' => __('How do I bulk translate?', 'reeid-translate'),
            'a' => __('This is PRO feature. Set your Bulk Translation Languages in Settings, then use the Bulk button in the editors to process them sequentially with inline progress.', 'reeid-translate'),
        ),
        array(
            'q' => __('Where do I put the language switcher?', 'reeid-translate'),
            'a' => __('Add the shortcode [reeid_language_switcher] to a page, widget, or template. Adjust its appearance in the Tools tab.', 'reeid-translate'),
        ),
        array(
            'q' => __('How do I customize the switcher appearance?', 'reeid-translate'),
            'a' => __('Go to Tools → Switcher Appearance. Choose a style (Default, Compact, Minimal, Outline, Pill, Flat, Glass) and a theme (Light, Dark, Auto), or customize in /wp-content/plugins/reeid-translate/assets/css/switcher.css', 'reeid-translate'),
        ),
        array(
            'q' => __('How does SEO integrate with Yoast or Rank Math?', 'reeid-translate'),
            'a' => __('The plugin manages hreflang and canonical output itself for translated pages (and the front page). It also translates common SEO meta fields so your snippets stay consistent.', 'reeid-translate'),
        ),
        array(
            'q' => __('Will search engines index translated pages?', 'reeid-translate'),
            'a' => __('Yes. The plugin ensures appropriate indexing on singular pages and the front page and outputs proper hreflang tags per language.', 'reeid-translate'),
        ),
        // NEW: Usage limits (placed in col1)
        array(
            'q' => __('Are there usage limits?', 'reeid-translate'),
            'a' => __('Yes. Translations are counted as operations (one page → one language = 1 operation). Your site may have a daily operations allowance and a per-minute request throttle. Limits can vary by plan and may change. See your current allowance in Settings → REEID Translate → Stat.', 'reeid-translate'),
        ),
        array(
            'q' => __('What happens if I hit a limit?', 'reeid-translate'),
            'a' => __('If you hit the per-minute throttle, wait for the one-minute window to reset or reduce the batch size. If you hit the daily allowance, you’ll need to wait until the next day before running more translations. The plugin does not automatically resume — you’ll need to re-start translation manually once the limit resets.', 'reeid-translate'),
        ),
    );

    $faqs_col2 = array(
        array(
            'q' => __('Which OpenAI model is used?', 'reeid-translate'),
            'a' => __('This changes over time as new releases become available. REEID selects high-quality, cost-effective models automatically. If model get retired (net less than few years), you may need to upgrade REEID Translate plugin plan to keep it up-to-date. ', 'reeid-translate'),
        ),
        array(
            'q' => __('Can I change tone or add custom instructions?', 'reeid-translate'),
            'a' => __('Yes. Pick a tone (e.g., Friendly, Technical) and optionally add custom prompt instructions in Settings (e.g., “Don’t translate names”).', 'reeid-translate'),
        ),
        array(
            'q' => __('How do I validate my OpenAI API key?', 'reeid-translate'),
            'a' => __('Paste your key in Plugin Settings and click Validate. You’ll see a green/red status inline if the connection succeeds.', 'reeid-translate'),
        ),
        array(
            'q' => __('What is the license key for?', 'reeid-translate'),
            'a' => __('It unlocks PRO features (e.g., bulk translation, Elementor, more languages), updates, and support.', 'reeid-translate'),
        ),
        array(
            'q' => __('What is Map Repair?', 'reeid-translate'),
            'a' => __('Map Repair fixes missing links among a source page and its translations so the switcher and menus work correctly.', 'reeid-translate'),
        ),
        array(
            'q' => __('Can I re-translate or update a translation?', 'reeid-translate'),
            'a' => __('Yes. Running Translate again updates the existing language version rather than creating duplicates.', 'reeid-translate'),
        ),
        array(
            'q' => __('What if a slug already exists?', 'reeid-translate'),
            'a' => __('WordPress keeps URLs unique (e.g., by adding “-2”). You can edit the slug manually anytime.', 'reeid-translate'),
        ),
        array(
            'q' => __('Where are translations stored?', 'reeid-translate'),
            'a' => __('Each translation is a real post/page linked to the original via a translation map. You can manage them like normal content.', 'reeid-translate'),
        ),
        array(
            'q' => __('Troubleshooting tips?', 'reeid-translate'),
            'a' => __('Check your API key, run Map Repair, and ensure your server can reach OpenAI. Use the plugin’s debug log if needed.', 'reeid-translate'),
        ),
    );
?>
    <div class="reeid-faq-tab">
        <h2><?php esc_html_e('Frequently Asked Questions', 'reeid-translate'); ?></h2>
        <div class="reeid-faq-cols">
            <div class="reeid-faq-col">
                <?php foreach ($faqs_col1 as $i => $item) : $id = 'faq-l-' . $i; ?>
                    <div class="reeid-faq-item">
                        <button class="reeid-faq-q" aria-expanded="false" aria-controls="<?php echo esc_attr($id); ?>">
                            <?php echo esc_html($item['q']); ?>
                            <span class="reeid-faq-chevron">&#9662;</span>
                        </button>
                        <div id="<?php echo esc_attr($id); ?>" class="reeid-faq-a" hidden>
                            <?php echo esc_html($item['a']); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="reeid-faq-col">
                <?php foreach ($faqs_col2 as $i => $item) : $id = 'faq-r-' . $i; ?>
                    <div class="reeid-faq-item">
                        <button class="reeid-faq-q" aria-expanded="false" aria-controls="<?php echo esc_attr($id); ?>">
                            <?php echo esc_html($item['q']); ?>
                            <span class="reeid-faq-chevron">&#9662;</span>
                        </button>
                        <div id="<?php echo esc_attr($id); ?>" class="reeid-faq-a" hidden>
                            <?php echo esc_html($item['a']); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php
}

/**
 * Renders the Info tab for the REEID Translate settings page.
 */
function reeid_render_info_tab()
{
    // Strict admin guard (renderer)
    if (! is_admin() || ! current_user_can('manage_options')) {
        echo '<div class="notice notice-error"><p>' . esc_html__('Unauthorized.', 'reeid-translate') . '</p></div>';
        return;
    }
    $url = 'https://reeid.com/';
?>
    <div class="reeid-info-tab">
        <h2><?php esc_html_e('About REEID Translation ', 'reeid-translate'); ?></h2>
        <p><?php esc_html_e('REEID Translation is a next-generation WordPress plugin for multilingual content management. It supports translation of posts, pages, Elementor layouts, and Gutenberg blocks.', 'reeid-translate'); ?></p>
        <hr>
        <ul>
            <li><?php esc_html_e('Supports 25+ languages (Free version supports 10 languages)', 'reeid-translate'); ?></li>
            <li><?php esc_html_e('One-click translation for posts and pages', 'reeid-translate'); ?></li>
            <li><?php esc_html_e('Bulk translation with automatic mapping (PRO version)', 'reeid-translate'); ?></li>
            <li><?php esc_html_e('Works with Elementor, Gutenberg, and Classic Editor (Elementor extension will be activated in PRO version)', 'reeid-translate'); ?></li>
            <li><?php esc_html_e('Customizable frontend language switcher shortcode', 'reeid-translate'); ?></li>
        </ul>
        <p>
            <?php esc_html_e('For more details, documentation, and support, visit:', 'reeid-translate'); ?>
            <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer">
                <?php echo esc_html($url); ?>
            </a>
        </p>
    </div>
<?php
}

/**
 * Render the translation map as a styled HTML list (utility).
 */
function reeid_render_translation_map_html($map)
{
    if (! is_array($map) || ! count($map)) {
        return '';
    }

    $out = '<h4 style="margin-top:20px;">' . esc_html__('Current Map:', 'reeid-translate') . '</h4><ul>';

    foreach ($map as $lang => $post_id) {
        $post_id   = absint($post_id);
        $title     = get_the_title($post_id);
        $status    = get_post_status($post_id);
        $edit_link = get_edit_post_link($post_id);

        $out .= sprintf(
            '<li><b>%s</b> &#8594; <a href="%s" target="_blank" rel="noopener noreferrer">%s</a> <span style="color:#888;">(%s, %s)</span></li>',
            esc_html(strtoupper((string) $lang)),
            esc_url((string) $edit_link),
            esc_html((string) $title),
            esc_html((string) $post_id),
            esc_html((string) $status)
        );
    }

    $out .= '</ul>';
    return $out;
}


function reeid_force_repair_translation_map($post_id)
{
    if (! $post_id) {
        return false;
    }
    $source_id    = (int) $post_id;
    $default_lang = get_option('reeid_translation_source_lang', 'en');
    $map          = array();

    $cache_key = 'reeid_children_' . $source_id;
    $children  = get_transient($cache_key);

    if (false === $children) {
        // Use WP_Query with minimal overhead and numeric compare; fetch only IDs.
        $q = new WP_Query(array(
            'post_type'              => array('post', 'page', 'product'),
            'post_status'            => array('publish', 'draft', 'pending'),
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'ignore_sticky_posts'    => true,
            'orderby'                => 'none',
            'meta_query'             => array(
                array(
                    'key'     => '_reeid_translation_source',
                    'value'   => $source_id,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ),
            ),
            // cut extra caches/work
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'cache_results'          => false,
            'suppress_filters'       => false,
        ));

        $children = $q->posts; // already IDs
        set_transient($cache_key, $children, 300); // 5 min cache
    }

    foreach ((array) $children as $child_id) {
        $lang      = get_post_meta($child_id, '_reeid_translation_lang', true);
        $post_stat = get_post_status($child_id);
        if ($lang && 'publish' === $post_stat) {
            $map[$lang] = (int) $child_id;
        }
    }

    // Always include the original if it's published.
    if ('publish' === get_post_status($source_id)) {
        $map[$default_lang] = $source_id;
    }

    update_post_meta($source_id, '_reeid_translation_map', $map);

    return $map;
}

/*==============================================================================
  SECTION 9: ADMIN-POST HANDLER (Metabox & Elementor submit → Job queue)
==============================================================================*/

/**
 * One endpoint used by the metabox (Gutenberg/Classic) and, later, Elementor panel.
 * It validates, enqueues a background job, and redirects back.
 *
 * Form should post to: admin_url('admin-post.php')
 * with: action=reeid_translate, _wpnonce=reeid_translate_nonce, post_id, target_lang, tone, publish_mode, prompt
 */
add_action('admin_post_reeid_translate', 'reeid_admin_post_translate');
function reeid_admin_post_translate()
{
    if (! is_admin()) {
        wp_die(esc_html__('Unauthorized.', 'reeid-translate'));
    }

    // Basic required params
    $post_id_raw = filter_input(INPUT_POST, 'post_id', FILTER_UNSAFE_RAW);
    $post_id     = $post_id_raw ? absint(wp_unslash($post_id_raw)) : 0;

    // Capability check against the post
    if (! $post_id || ! current_user_can('edit_post', $post_id)) {
        wp_die(esc_html__('Insufficient permissions.', 'reeid-translate'));
    }

    // Nonce
    $nonce_raw = filter_input(INPUT_POST, '_wpnonce', FILTER_UNSAFE_RAW);
    $nonce     = is_string($nonce_raw) ? sanitize_text_field(wp_unslash($nonce_raw)) : '';
    if (! $nonce || ! wp_verify_nonce($nonce, 'reeid_translate_nonce_' . $post_id)) {
        wp_die(esc_html__('Security check failed.', 'reeid-translate'));
    }

    // Inputs
    $lang_raw   = filter_input(INPUT_POST, 'target_lang', FILTER_UNSAFE_RAW);
    $tone_raw   = filter_input(INPUT_POST, 'tone', FILTER_UNSAFE_RAW);
    $mode_raw   = filter_input(INPUT_POST, 'publish_mode', FILTER_UNSAFE_RAW);
    $prompt_raw = filter_input(INPUT_POST, 'prompt', FILTER_UNSAFE_RAW);

    $target_lang = $lang_raw   ? sanitize_text_field(wp_unslash($lang_raw))   : '';
    $tone        = $tone_raw   ? sanitize_text_field(wp_unslash($tone_raw))   : 'Neutral';
    $publish_mode = $mode_raw   ? sanitize_text_field(wp_unslash($mode_raw))   : 'draft';
    $prompt      = $prompt_raw ? wp_strip_all_tags((string) wp_unslash($prompt_raw)) : '';

    // Enqueue job (single)
    if (! function_exists('reeid_translation_job_enqueue')) {
        wp_die(esc_html__('Background system not available.', 'reeid-translate'));
    }

    $job_id = reeid_translation_job_enqueue(array(
        'type'        => 'single',
        'post_id'     => $post_id,
        'target_lang' => $target_lang,
        'user_id'     => get_current_user_id(),
        'params'      => array(
            'tone'         => $tone,
            'publish_mode' => $publish_mode,
            'prompt'       => $prompt,
        ),
    ));

    // Redirect back to the post edit screen with a status flag
    $back = get_edit_post_link($post_id, 'raw');
    $back = add_query_arg(
        array(
            'reeid_job' => (int) $job_id,
            'reeid_msg' => 'queued',
        ),
        $back ?: admin_url('edit.php')
    );
    wp_safe_redirect($back);
    exit;
}

/*==============================================================================
  SECTION 10: SETTINGS PAGE RENDERING & TABS 
==============================================================================*/

/**
 * Ensures every translation in a group shares the same map.
 *
 * Note: This uses a meta_key/meta_value query to find all children. This can be slow on very large sites,
 * so we cache the result for 5 minutes. This function is intended for admin/tools use only, not for every page load.
 */
function reeid_repair_translation_group($group_source_id)
{
    $group_source_id = absint($group_source_id);

    // Use transient cache to avoid repeated slow queries.
    $cache_key = 'reeid_group_children_' . $group_source_id;
    $children  = get_transient($cache_key);

    if (false === $children) {
        // 1) Gather all published children of this source.
        $children = get_posts(array(
            'post_type'        => array('post', 'page', 'product'),
            'posts_per_page'   => -1,
            'post_status'      => array('publish'),
            'meta_key'         => '_reeid_translation_source',
            'meta_value'       => $group_source_id,
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ));
        set_transient($cache_key, $children, 300); // 5 min cache.
    }

    // 2) Build a clean map: lang => post_id.
    $translations = array();
    foreach ((array) $children as $child_id) {
        $lang = get_post_meta($child_id, '_reeid_translation_lang', true);
        if ($lang) {
            $translations[$lang] = (int) $child_id;
        }
    }

    // 3) Add the original source (if published).
    if (get_post_status($group_source_id) === 'publish') {
        $default_lang                 = get_option('reeid_translation_source_lang', 'en');
        $translations[$default_lang] = (int) $group_source_id;
    }

    // 4) Write the same map into every post in this group.
    foreach ($translations as $lang => $post_id) {
        update_post_meta($post_id, '_reeid_translation_map', $translations);
    }

    return $translations;
}

/**
 * Render the main settings page with tabs.
 */
function reeid_render_settings_page()
{
    $logo_id        = absint(apply_filters('reeid_logo_attachment_id', 123));

    $active_tab_raw = filter_input(INPUT_GET, 'tab', FILTER_UNSAFE_RAW);
    $active_tab     = $active_tab_raw ? sanitize_text_field(wp_unslash($active_tab_raw)) : 'settings';

    $base = admin_url('options-general.php');
?>
    <div class="wrap">
        <h1>
            <?php
            if ($logo_id && get_post($logo_id)) {
                echo wp_get_attachment_image(
                    $logo_id,
                    'medium',
                    false,
                    array(
                        'alt' => esc_attr__('REEID Translate Settings', 'reeid-translate'),
                        'style' => 'max-height:50px; margin-bottom:10px;'
                    )
                );
            }
            ?>
        </h1>

        <h2 class="nav-tab-wrapper" style="margin-bottom:18px;">
            <?php
            $tabs = array(
                'settings' => __('SETTINGS', 'reeid-translate'),
                'tools'    => __('TOOLS', 'reeid-translate'),
                //'jobs'     => __( 'JOBS', 'reeid-translate' ), 
                'faq'      => __('FAQ', 'reeid-translate'),
                'info'     => __('INFO', 'reeid-translate'),

            );

            if (defined('REEID_SHOW_JOBS_TAB') && REEID_SHOW_JOBS_TAB) {
                $tabs['jobs'] = 'Jobs';
            }

            //  hard-block direct access:
            if (isset($_GET['tab']) && $_GET['tab'] === 'jobs' && ! (defined('REEID_SHOW_JOBS_TAB') && REEID_SHOW_JOBS_TAB)) {
                wp_die(esc_html__('This view is disabled.', 'reeid-translate'));
            }


            foreach ($tabs as $slug => $label) {
                $url = add_query_arg(array(
                    'page' => 'reeid-translate-settings',
                    'tab'  => $slug,
                ), $base);
                $cls = 'nav-tab' . ($active_tab === $slug ? ' nav-tab-active' : '');
                echo '<a href="' . esc_url($url) . '" class="' . esc_attr($cls) . '">' . esc_html($label) . '</a>';
            }
            ?>
        </h2>

        <div class="reeid-tab-content">
            <?php
            switch ($active_tab) {
                case 'tools':
                    reeid_render_tools_tab();
                    break;
                case 'jobs':                        // ← NEW
                    reeid_render_jobs_tab();
                    break;
                case 'faq':
                    reeid_render_faq_tab();
                    break;
                case 'info':
                    reeid_render_info_tab();
                    break;
                case 'stat':
                    if (function_exists('reeid_render_stat_tab')) {
                        reeid_render_stat_tab();
                    } else {
                        echo '<p>' . esc_html__('Stat tab not available.', 'reeid-translate') . '</p>';
                    }
                    break;
                case 'settings':
                default:
                    reeid_render_settings_tab();
                    break;
            }
            ?>
        </div>
    </div>
<?php
}


/**
 * Render the “Settings” tab contents.
 */
function reeid_render_settings_tab()
{
    // Strict admin guard (renderer)
    if (! is_admin() || ! current_user_can('manage_options') || ! function_exists('settings_fields')) {
        echo '<div class="notice notice-error"><p>' . esc_html__('Unauthorized.', 'reeid-translate') . '</p></div>';
        return;
    }
?>
    <form method="post" action="options.php">
        <?php
        settings_fields('reeid_translate_settings');
        do_settings_sections('reeid-translate-settings');
        submit_button();
        ?>
    </form>
<?php
}

function reeid_render_tools_tab()
{
    // Strict admin guard (renderer)
    if (! is_admin() || ! current_user_can('manage_options')) {
        echo '<div class="notice notice-error"><p>' . esc_html__('Unauthorized.', 'reeid-translate') . '</p></div>';
        return;
    }

    $msg        = '';
    $map_output = '';

    // Securely get and check request method.
    $method     = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
    $tools_post = ('POST' === strtoupper($method));

    $nonce_post        = filter_input(INPUT_POST, 'reeid_tools_tab_nonce', FILTER_DEFAULT);
    $nonce_unslashed   = $nonce_post ? wp_unslash($nonce_post) : '';
    $nonce             = sanitize_text_field($nonce_unslashed);
    $valid_tools_nonce = $nonce && wp_verify_nonce($nonce, 'reeid_tools_tab_action') && current_user_can('manage_options');

    if ($tools_post && $valid_tools_nonce) {
        // Save switcher appearance.
        if (isset($_POST['reeid_switcher_style'])) {
            update_option(
                'reeid_switcher_style',
                sanitize_text_field(wp_unslash($_POST['reeid_switcher_style']))
            );
        }
        if (isset($_POST['reeid_switcher_theme'])) {
            update_option(
                'reeid_switcher_theme',
                sanitize_text_field(wp_unslash($_POST['reeid_switcher_theme']))
            );
        }
        // Global Purge Cache.
        if (! empty($_POST['reeid_purge_all_cache'])) {
            global $wpdb;

            // 1. Delete all REEID transients.
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_reeid_ht_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_reeid_ht_%'");

            // 2. Delete WooCommerce string caches.
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'reeid_woo_strings_%'");

            $msg = '<div style="color:green;font-weight:bold;">&#10004; '
                . esc_html__('All REEID translation caches have been purged.', 'reeid-translate')
                . '</div>';
        }
        // Repair All Maps (group-wide).
        if (! empty($_POST['reeid_maprepair_all'])) {
            // find every potential source post/page.
            $all_ids = get_posts(array(
                'post_type'        => array('post', 'page'),
                'post_status'      => array('publish'),
                'numberposts'      => -1,
                'fields'           => 'ids',
                'no_found_rows'    => true,
                'suppress_filters' => false,
            ));
            $updated_groups = 0;
            $summary        = array();

            foreach ((array) $all_ids as $source_id) {
                $meta_val        = (int) get_post_meta($source_id, '_reeid_translation_source', true);
                $is_group_source = ($meta_val === 0 || $meta_val === (int) $source_id);
                if (! $is_group_source) {
                    continue;
                }
                $map = reeid_repair_translation_group((int) $source_id);
                if (is_array($map) && ! empty($map)) {
                    $updated_groups++;
                    $langs      = implode(', ', array_keys($map));
                    $summary[]  = 'Group Source ID ' . (int) $source_id . ': ' . $langs;
                }
            }

            if ($updated_groups > 0) {
                $msg        = '<div style="color:green;font-weight:bold;">&#10004; '
                    . esc_html__('All groups repaired. Total groups updated:', 'reeid-translate')
                    . ' ' . esc_html((string) $updated_groups) . '.</div>';
                $map_output = '<details style="margin:10px 0;"><summary style="cursor:pointer;">'
                    . esc_html__('Show details', 'reeid-translate')
                    . '</summary><pre style="background:#fafaff;border:1px solid #ddd;padding:8px;">'
                    . esc_html(implode("\n", $summary))
                    . '</pre></details>';
            } else {
                $msg = '<div style="color:orange;font-weight:bold;">'
                    . esc_html__('No groups required repair.', 'reeid-translate')
                    . '</div>';
            }
        }
        // Repair Single Map (by Group).
        elseif (isset($_POST['reeid_maprepair_post_id'])) {
            $pid  = absint(wp_unslash($_POST['reeid_maprepair_post_id']));
            $post = $pid ? get_post($pid) : false;

            if ($pid && $post && in_array($post->post_type, array('post', 'page'), true)) {
                // determine group source.
                $meta_val        = (int) get_post_meta($pid, '_reeid_translation_source', true);
                $group_source_id = ($meta_val === 0 || $meta_val === $pid) ? $pid : $meta_val;

                $map = reeid_repair_translation_group((int) $group_source_id);

                if (is_array($map) && ! empty($map)) {
                    $langs = implode(', ', array_keys($map));
                    $msg   = '<div style="color:green;font-weight:bold;">&#10004; '
                        . sprintf(
                            /* translators: %1$d = post ID, %2$s = comma-separated langs */
                            esc_html__('Group map repaired for Post ID %1$d. Languages: %2$s.', 'reeid-translate'),
                            $pid,
                            esc_html($langs)
                        )
                        . '</div>';
                    $map_output = reeid_render_translation_map_html($map);
                } elseif (is_array($map) && empty($map)) {
                    $msg = '<div style="color:orange;font-weight:bold;margin-top:10px;">'
                        . esc_html__('No translations found for Post ID', 'reeid-translate')
                        . ' ' . esc_html((string) $pid) . '.</div>';
                } else {
                    $msg = '<div style="color:red;font-weight:bold;">'
                        . esc_html__('Failed to repair map for Post ID', 'reeid-translate')
                        . ' ' . esc_html((string) $pid) . '.</div>';
                }
            } else {
                $msg = '<div style="color:red;font-weight:bold;">'
                    . esc_html__('Please enter a valid Post/Page ID.', 'reeid-translate')
                    . '</div>';
            }
        }
    } elseif ($tools_post) {
        // nonce failed.
        $msg = '<div style="color:red;font-weight:bold;">'
            . esc_html__('Security check failed. Please try again.', 'reeid-translate')
            . '</div>';
    }

    // Current switcher settings.
    $style = get_option('reeid_switcher_style', 'default');
    $theme = get_option('reeid_switcher_theme', 'auto');

    $tools_action_url = add_query_arg(
        array(
            'page' => 'reeid-translate-settings',
            'tab'  => 'tools',
        ),
        admin_url('options-general.php')
    );
?>
    <div class="reeid-tools-row">
        <!-- Left Column: Map Repair -->
        <div class="reeid-tools-col">
            <h2><?php esc_html_e('Translation Map Repair', 'reeid-translate'); ?></h2>

            <form method="post" action="<?php echo esc_url($tools_action_url); ?>">
                <?php wp_nonce_field('reeid_tools_tab_action', 'reeid_tools_tab_nonce'); ?>
                <label for="reeid_maprepair_post_id">
                    <strong><?php esc_html_e('Repair a Map:', 'reeid-translate'); ?></strong>
                </label>
                <input
                    type="number"
                    name="reeid_maprepair_post_id"
                    id="reeid_maprepair_post_id"
                    min="1"
                    placeholder="<?php esc_attr_e('Enter ID', 'reeid-translate'); ?>"
                    style="width:150px; margin:0 12px 0 8px;" />
                <button type="submit" class="button">
                    <?php esc_html_e('Repair Map', 'reeid-translate'); ?>
                </button>

                <button
                    type="submit"
                    name="reeid_maprepair_all"
                    value="1"
                    class="button"
                    style="margin-left:10px;"
                    onclick="return confirm( '<?php echo esc_js(__('Are you sure? This will scan and repair all translation groups site-wide.', 'reeid-translate')); ?>' )">
                    <?php esc_html_e('Repair All Maps', 'reeid-translate'); ?>
                </button>
            </form>

            <?php if (! empty($msg)) : ?>
                <div class="notice notice-info">
                    <?php echo wp_kses_post($msg); ?>
                </div>
            <?php endif; ?>

            <?php if (! empty($map_output)) : ?>
                <div class="reeid-map-output">
                    <?php echo wp_kses_post($map_output); ?>
                </div>
            <?php endif; ?>

            <hr>

            <p>
                <?php
                esc_html_e(
                    'This tool rebuilds the translation map (_reeid_translation_map) for a specific post/page or all translation groups. This ensures proper linking of translations for the switcher and admin tools.',
                    'reeid-translate'
                );
                ?>
            </p>
        </div>

        <!-- Right Column: Switcher Appearance -->
        <div class="reeid-tools-col">
            <h2><?php esc_html_e('Switcher Appearance', 'reeid-translate'); ?></h2>

            <form method="post" action="<?php echo esc_url($tools_action_url); ?>">
                <?php wp_nonce_field('reeid_tools_tab_action', 'reeid_tools_tab_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Style', 'reeid-translate'); ?></th>
                        <td>
                            <select name="reeid_switcher_style">
                                <option value="default" <?php selected($style, 'default'); ?>>Default</option>
                                <option value="compact" <?php selected($style, 'compact'); ?>>Compact</option>
                                <option value="minimal" <?php selected($style, 'minimal'); ?>>Minimal</option>
                                <option value="outline" <?php selected($style, 'outline'); ?>>Outline</option>
                                <option value="pill" <?php selected($style, 'pill'); ?>>Pill</option>
                                <option value="flat" <?php selected($style, 'flat'); ?>>Flat</option>
                                <option value="glass" <?php selected($style, 'glass'); ?>>Glass / Blur</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Theme', 'reeid-translate'); ?></th>
                        <td>
                            <select name="reeid_switcher_theme">
                                <option value="light" <?php selected($theme, 'light'); ?>>Light</option>
                                <option value="dark" <?php selected($theme, 'dark');  ?>>Dark</option>
                                <option value="auto" <?php selected($theme, 'auto');  ?>>Auto (Match Device)</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Save Appearance Settings', 'reeid-translate'); ?>
                    </button>
                </p>
            </form>
        </div>

        <!-- Global Purge Cache -->
        <div class="reeid-tools-col" style="margin-top:30px;">
            <h2><?php esc_html_e('Global Purge Cache', 'reeid-translate'); ?></h2>

            <form method="post" action="<?php echo esc_url($tools_action_url); ?>">
                <?php wp_nonce_field('reeid_tools_tab_action', 'reeid_tools_tab_nonce'); ?>
                <p>
                    <button
                        type="submit"
                        name="reeid_purge_all_cache"
                        value="1"
                        class="button button-secondary"
                        style="background:#b32d2e;color:#fff;"
                        onclick="return confirm('<?php echo esc_js(__('⚠️ WARNING: This will delete ALL REEID translation caches. The next translations will trigger new API calls and may incur costs. Continue?', 'reeid-translate')); ?>')">
                        <?php esc_html_e('Purge All Translation Cache', 'reeid-translate'); ?>
                    </button>
                </p>
                <p style="max-width:400px;color:#666;">
                    <?php esc_html_e('This clears all cached translations (transients + WooCommerce string caches). Use only if translations are stale or broken. The next requests will re-call the API and consume tokens.', 'reeid-translate'); ?>
                </p>
            </form>
        </div>
    </div>
<?php
}


/*==============================================================================
  SECTION 11: ENQUEUE ADMIN, META BOX & ELEMENTOR ASSETS 
==============================================================================*/

add_action('admin_enqueue_scripts', 'reeid_enqueue_admin_and_metabox_assets');
function reeid_enqueue_admin_and_metabox_assets($hook)
{
    // Settings screen assets
    if ('settings_page_reeid-translate-settings' === $hook) {
        wp_enqueue_style(
            'reeid-admin-styles',
            plugins_url('assets/css/admin-styles.css', __FILE__),
            array(),
            '1.0'
        );

        $admin_js = plugin_dir_path(__FILE__) . 'assets/js/admin-settings.js';
        if (file_exists($admin_js)) {
            wp_enqueue_script(
                'reeid-admin-settings',
                plugins_url('assets/js/admin-settings.js', __FILE__),
                array('jquery'),
                (string) filemtime($admin_js),
                true
            );

            wp_localize_script(
                'reeid-admin-settings',
                'REEID_TRANSLATE',
                array(
                    'ajaxurl' => esc_url_raw(admin_url('admin-ajax.php')),
                    'nonce'   => wp_create_nonce('reeid_translate_nonce_action'),
                )
            );
        }
    }

    // Post edit screens (meta-box UI)
    if (in_array($hook, array('post.php', 'post-new.php'), true)) {
        // CSS
        $mb_css = plugin_dir_path(__FILE__) . 'assets/css/meta-box.css';
        if (file_exists($mb_css)) {
            wp_enqueue_style(
                'reeid-meta-box-styles',
                plugins_url('assets/css/meta-box.css', __FILE__),
                array(),
                (string) filemtime($mb_css)
            );
        }

        // JS
        $mb_js = plugin_dir_path(__FILE__) . 'assets/js/translation-meta-box.js';
        if (file_exists($mb_js)) {
            wp_enqueue_script(
                'reeid-translation-meta-box',
                plugins_url('assets/js/translation-meta-box.js', __FILE__),
                array('jquery'),
                (string) filemtime($mb_js),
                true
            );

            // Localize data used by meta-box JS
            $lang_names = function_exists('reeid_get_supported_languages') ? (array) reeid_get_supported_languages() : array();
            $bulk       = get_option('reeid_bulk_translation_langs', array());

            if (empty($bulk)) {
                $bulk = get_option('reeid_bulk_languages', array());
            }

            if (! is_array($bulk)) {
                $bulk = array_filter(array_map('sanitize_text_field', explode(',', (string) $bulk)));
            } else {
                $bulk = array_map('sanitize_text_field', $bulk);
            }

            if ($lang_names) {
                $bulk = array_values(array_intersect($bulk, array_keys($lang_names)));
            }

            wp_localize_script(
                'reeid-translation-meta-box',
                'reeidData',
                array(
                    'ajaxurl'   => esc_url_raw(admin_url('admin-ajax.php')),
                    'nonce'     => wp_create_nonce('reeid_translate_nonce_action'),
                    'langNames' => $lang_names,
                    'bulkLangs' => $bulk, // [] when none selected
                )
            );

            // SelectWoo + initializer for Translations tab =====
            // Only enhance when editing WooCommerce products
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen && isset($screen->post_type) && $screen->post_type === 'product') {
                // Ensure Woo admin styles (includes SelectWoo styles)
                wp_enqueue_style('woocommerce_admin_styles');

                // Woo's SelectWoo via wc-enhanced-select
                wp_enqueue_script('wc-enhanced-select'); // depends on selectWoo + jQuery

                // Our tiny initializer (create file at assets/js/reeid-wc-translate-admin.js)
                wp_register_script(
                    'reeid-wc-translate-admin',
                    plugins_url('assets/js/reeid-wc-translate-admin.js', __FILE__),
                    array('jquery', 'wc-enhanced-select'),
                    '1.0',
                    true
                );

                wp_localize_script(
                    'reeid-wc-translate-admin',
                    'REEID_WC_TR',
                    array(
                        'ajax'   => esc_url_raw(admin_url('admin-ajax.php')),
                        'nonce'  => wp_create_nonce('reeid_wc_bulk_delete'),
                        'labels' => array(
                            'placeholder' => __('Select languages to remove…', 'reeid'),
                            'confirm'     => __('Delete the selected translations? This cannot be undone.', 'reeid'),
                            'deleted'     => __('Deleted', 'reeid'),
                            'noneLeft'    => __('No translations found.', 'reeid'),
                        ),
                    )
                );

                wp_enqueue_script('reeid-wc-translate-admin');

                // Compact layout for the picklist in Product Data > Translations
                $reeid_wc_tr_css = '
			.reeid-wc-tr-toolbar{display:flex;gap:8px;align-items:center;margin:6px 0 10px;}
			.reeid-wc-tr-select{min-width:260px;max-width:420px;}
			.reeid-wc-tr-toolbar .button{height:32px}
			';
                wp_add_inline_style('woocommerce_admin_styles', $reeid_wc_tr_css);
            }
            
        }
    }
}

add_action('elementor/editor/after_enqueue_scripts', 'reeid_enqueue_elementor_assets');
function reeid_enqueue_elementor_assets()
{
    $el_js = plugin_dir_path(__FILE__) . 'assets/js/elementor-translate.js';
    if (! file_exists($el_js)) {
        return;
    }

    // Ensure we only load OUR clean file; do NOT enqueue any “alert/popup” helpers here
    wp_enqueue_script(
        'reeid-elementor-translate',
        plugins_url('assets/js/elementor-translate.js', __FILE__),
        array('jquery'),
        time(), // bump in dev to force reload
        true
    );


    $lang_names = function_exists('reeid_get_supported_languages') ? (array) reeid_get_supported_languages() : array();
    $bulk       = get_option('reeid_bulk_translation_langs', array());

    if (empty($bulk)) {
        $bulk = get_option('reeid_bulk_languages', array());
    }

    if (! is_array($bulk)) {
        $bulk = array_filter(array_map('sanitize_text_field', explode(',', (string) $bulk)));
    } else {
        $bulk = array_map('sanitize_text_field', $bulk);
    }

    if ($lang_names) {
        $bulk = array_values(array_intersect($bulk, array_keys($lang_names)));
    }

    // NEW: detect current Elementor document post type for "Go to list" button
    $post_id   = isset($_GET['post']) ? absint($_GET['post']) : 0;
    $post_type = $post_id ? get_post_type($post_id) : 'post';

    $post_id   = isset($_GET['post']) ? absint($_GET['post']) : 0;
    $post_type = $post_id ? get_post_type($post_id) : 'post';

    wp_localize_script(
        'reeid-elementor-translate',
        'reeidData',
        array(
            'ajaxurl'   => esc_url_raw(admin_url('admin-ajax.php')),
            'nonce'     => wp_create_nonce('reeid_translate_nonce_action'),
            'langNames' => $lang_names,
            'bulkLangs' => $bulk,
            'postType'  => $post_type,
            'listUrls'  => array(
            'post'    => admin_url('edit.php'),
            'page'    => admin_url('edit.php?post_type=page'),
            'product' => admin_url('edit.php?post_type=product'),
            ),
            'panelColor' => '#cf616a', // Elementor header color
        )
    );
}

/* Optional safety: if an older alert script was registered elsewhere, forcibly dequeue it in the editor */
add_action('elementor/editor/before_enqueue_scripts', function () {
    wp_dequeue_script('reeid-block-alert');
    wp_dequeue_script('reeid-translation-block-alert');
}, 1000);

/*==============================================================================
  SECTION 12: LICENSE VALIDATION & SECURE METABOX (FREEMIUM)
==============================================================================*/

/**
 * Small helpers (scoped; function_exists guards for safety)
 */
if (! function_exists('reeid9_bool')) {
    function reeid9_bool($v)
    {
        // Accept strict boolean or common string-y truthy values
        if (true === $v || 1 === $v) {
            return true;
        }
        if (is_string($v)) {
            $vs = strtolower(trim($v));
            return in_array($vs, array('1', 'true', 'yes', 'ok', 'success'), true);
        }
        return false;
    }
}
if (! function_exists('reeid9_site_host')) {
    function reeid9_site_host()
    {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        if (! $host && function_exists('network_home_url')) {
            $host = wp_parse_url(network_home_url(), PHP_URL_HOST);
        }
        if (! $host && isset($_SERVER['HTTP_HOST'])) {
            $host = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']));
        }
        return strtolower((string) $host); // keep as-is (no stripping of www)
    }
}

/**
 * 1) License key setting + sanitizer (preserves status if key unchanged)
 */
add_action(
    'admin_init',
    function () {
        register_setting(
            'reeid_translate_settings',
            'reeid_pro_license_key',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'reeid_sanitize_license_key',
                'default'           => '',
            )
        );
    }
);

/**
 * Sanitize license key and preserve license status when the key didn't change.
 */
function reeid_sanitize_license_key($input)
{
    $input   = sanitize_text_field(trim((string) $input));
    $old_key = trim((string) get_option('reeid_pro_license_key', ''));

    // If key changed → reset status (will revalidate)
    if ($input !== $old_key) {
        update_option('reeid_license_status', 'invalid');
    }
    return $input;
}

/**
 * After license key option updates, auto-validate if present or invalidate if empty.
 */
add_action(
    'update_option_reeid_pro_license_key',
    function ($old, $new) {
        $new = trim((string) $new);
        if ('' === $new) {
            update_option('reeid_license_status', 'invalid');
            update_option('reeid_license_last_code', 0);
            update_option('reeid_license_last_raw', '');
            update_option('reeid_license_last_msg', '');
        } else {
            reeid_validate_license($new);
        }
    },
    10,
    2
);

/**
 * 2) AJAX: Validate License Key (does NOT save it)
 */
add_action('wp_ajax_reeid_validate_license_key', 'reeid_handle_validate_license_key');
function reeid_handle_validate_license_key()
{
    $in = filter_input_array(
        INPUT_POST,
        array(
            'nonce' => FILTER_UNSAFE_RAW,
            'key'   => FILTER_UNSAFE_RAW,
        )
    );
    $in = is_array($in) ? $in : array();

    // Security.
    /* BEGIN REEID tolerant nonce check */
$nonce = $_REQUEST['nonce'] ?? $_POST['nonce'] ?? $_GET['nonce'] ?? '';
$ok = false;
if (! empty($nonce)) {
    if ( wp_verify_nonce( $nonce, 'reeid_translate_nonce' ) ) {
        $ok = true;
    } elseif ( wp_verify_nonce( $nonce, 'reeid_translate_nonce_action' ) ) {
        $ok = true;
    }
}
if ( ! $ok ) {
    wp_send_json_error( array( 'error' => 'bad_nonce', 'msg' => 'Invalid/missing nonce. Please reload editor.' ) );
}
/* END REEID tolerant nonce check */

    $key_raw = isset($in['key']) ? wp_unslash($in['key']) : '';
    $key     = sanitize_text_field(trim((string) $key_raw));
    $domain  = reeid9_site_host();

    $valid   = false;
    $message = '';

    if ('' !== $key && '' !== $domain) {
        $resp = wp_remote_post(
            'https://go.reeid.com/validate-license.php',
            array(
                'timeout' => 15,
                'body'    => array(
                    'license_key' => $key,
                    'domains'     => $domain,
                ),
            )
        );

        if (is_wp_error($resp)) {
            wp_send_json_error(
                array(
                    'valid'   => false,
                    'message' => __('Could not connect to license server.', 'reeid-translate'),
                )
            );
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);

        // Diagnostics (helpful for support).
        update_option('reeid_license_last_code', $code);
        update_option('reeid_license_last_raw', substr($body, 0, 800));

        if (200 === $code && '' !== $body) {
            $data = json_decode($body, true);
            if (is_array($data)) {
                $valid   = isset($data['valid']) ? reeid9_bool($data['valid']) : false;
                $message = isset($data['message']) ? (string) $data['message'] : '';
                update_option('reeid_license_last_msg', $message);
            }
        }

        // If this key matches the SAVED key, persist status.
        $saved_key = trim((string) get_option('reeid_pro_license_key', ''));
        if ('' !== $saved_key && $saved_key === $key) {
            update_option('reeid_license_status', $valid ? 'valid' : 'invalid');
            update_option('reeid_license_checked_at', time());
        }

        // User-facing message.
        if ($valid) {
            if ('' !== $saved_key && $saved_key !== $key) {
                $message = $message ? $message : __('License is valid.', 'reeid-translate');
                $message .= ' ' . __('Click “Save Changes” to store this key.', 'reeid-translate');
            } else {
                $message = $message ? $message : __('✔ License key is valid.', 'reeid-translate');
            }
        } else {
            $message = $message ? $message : __('❌ License key is invalid.', 'reeid-translate');
        }
    } else {
        $message = __('Please enter a license key.', 'reeid-translate');
    }

    // Always 200 OK – front-end handles success flag.
    wp_send_json_success(
        array(
            'valid'   => $valid,
            'message' => $message,
        )
    );
}

/**
 * 3) License validation (manual or after save)
 */
function reeid_validate_license($license_key = '')
{
    $license_key = $license_key ? $license_key : trim((string) get_option('reeid_pro_license_key', ''));
    if ('' === $license_key) {
        update_option('reeid_license_status', 'invalid');
        update_option('reeid_license_checked_at', time());
        return;
    }

    $domain = reeid9_site_host();

    $resp = wp_remote_post(
        'https://go.reeid.com/validate-license.php',
        array(
            'timeout' => 15,
            'body'    => array(
                'license_key' => $license_key,
                'domains'     => $domain,
            ),
        )
    );

    if (is_wp_error($resp)) {
        // Keep last-known status; store diagnostics.
        update_option('reeid_license_checked_at', time());
        update_option('reeid_license_last_code', 0);
        update_option('reeid_license_last_raw', '');
        update_option('reeid_license_last_msg', 'WP_Error: ' . $resp->get_error_message());
        return;
    }

    $code = (int) wp_remote_retrieve_response_code($resp);
    $body = (string) wp_remote_retrieve_body($resp);

    update_option('reeid_license_last_code', $code);
    update_option('reeid_license_last_raw', substr($body, 0, 800));

    if (200 !== $code || '' === $body) {
        update_option('reeid_license_checked_at', time());
        update_option('reeid_license_last_msg', 'HTTP ' . $code . ' empty/body');
        return;
    }

    $data = json_decode($body, true);
    if (! is_array($data)) {
        update_option('reeid_license_checked_at', time());
        update_option('reeid_license_last_msg', 'Non-JSON response');
        return;
    }

    $ok      = isset($data['valid']) ? reeid9_bool($data['valid']) : false;
    $message = isset($data['message']) ? (string) $data['message'] : '';

    update_option('reeid_license_status', $ok ? 'valid' : 'invalid');
    update_option('reeid_license_checked_at', time());
    update_option('reeid_license_last_msg', $message);
}

/**
 * 4) Helper: Is PRO active?
 */
function reeid_is_pro_active()
{
    return 'valid' === get_option('reeid_license_status', 'invalid');
}

/**
 * 5) Add metabox (hide on Elementor; gate features inside)
 */
add_action(
    'add_meta_boxes',
    function () {
        global $post;
        $post_id = 0;

        $get_post     = filter_input(INPUT_GET, 'post', FILTER_SANITIZE_NUMBER_INT);
        $post_post_id = filter_input(INPUT_POST, 'post_ID', FILTER_SANITIZE_NUMBER_INT);

        if ($get_post) {
            $post_id = (int) $get_post;
        } elseif ($post_post_id) {
            $post_id = (int) $post_post_id;
        } elseif (isset($post)) {
            if (! $post instanceof WP_Post) {
                $post = get_post($post);
            }
            if ($post instanceof WP_Post) {
                $post_id = (int) $post->ID;
            }
        }

        // Hide on Elementor pages.
        $is_elementor = $post_id ? ('builder' === get_post_meta($post_id, '_elementor_edit_mode', true)) : false;
        if ($is_elementor) {
            return;
        }

        add_meta_box(
            'reeid-translation-meta-box',
            __('REEID TRANSLATION', 'reeid-translate'),
            'reeid_render_meta_box',
            array('post', 'page', 'product'),
            'side',
            'high'
        );
    }
);


/**
 * 6) Enqueue Metabox assets only on edit screens (Classic + Gutenberg)
 *    Localize data needed by JS (nonce, langs, postType, listUrls, panelColor).
 */
add_action(
    'admin_enqueue_scripts',
    function () {
        if (! is_admin()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen || ! in_array($screen->base, array('post', 'post-new'), true)) {
            return; // only post.php / post-new.php
        }

        $post_type = $screen->post_type ? $screen->post_type : 'post';
        if (! in_array($post_type, array('post', 'page', 'product'), true)) {
            return;
        }

        // Optional CSS for metabox.
        wp_enqueue_style(
            'reeid-meta-box',
            plugins_url('assets/css/meta-box.css', __FILE__),
            array(),
            '1.0.0'
        );

        // JS for metabox (matches translation-meta-box.js we assembled).
        wp_enqueue_script(
            'reeid-translation-metabox',
            plugins_url('assets/js/translation-meta-box.js', __FILE__),
            array('jquery'),
            '1.0.1',
            true
        );

        // Build language data.
        $bulk_langs = (array) get_option('reeid_bulk_translation_langs', array());
        $lang_names = function_exists('reeid_get_supported_languages') ? (array) reeid_get_supported_languages() : array();

        // Resolve post id if available (not strictly required for JS).
        $post_id     = 0;
        $get_post    = filter_input(INPUT_GET, 'post', FILTER_SANITIZE_NUMBER_INT);
        $post_postid = filter_input(INPUT_POST, 'post_ID', FILTER_SANITIZE_NUMBER_INT);
        if ($get_post) {
            $post_id = (int) $get_post;
        } elseif ($post_postid) {
            $post_id = (int) $post_postid;
        }
        if (! $post_id && isset($GLOBALS['post']) && $GLOBALS['post'] instanceof WP_Post) {
            $post_id = (int) $GLOBALS['post']->ID;
        }

        // Localize for JS (nonce matches check_ajax_referer/JS usage).
        wp_localize_script(
            'reeid-translation-metabox',
            'reeidData',
            array(
                'ajaxurl'   => esc_url_raw(admin_url('admin-ajax.php')),
                'nonce'     => wp_create_nonce('reeid_translate_nonce_action'),
                'bulkLangs' => array_values($bulk_langs),
                'langNames' => $lang_names,
                // Modal/navigation helpers:
                'postType'  => $post_type,
                'listUrls'  => array(
                    'post'    => admin_url('edit.php'),
                    'page'    => admin_url('edit.php?post_type=page'),
                    'product' => admin_url('edit.php?post_type=product'),
                ),
                'panelColor' => '#cf616a',
            )
        );
    }
);

/**
 * 7) Render metabox UI (safe output, freemium gating)
 */
function reeid_render_meta_box($post)
{
    if (! $post instanceof WP_Post) {
        $post = get_post($post);
    }
    wp_nonce_field('reeid_translate_nonce_action', 'reeid_translate_nonce');
    reeid_render_meta_box_ui_controls($post);
}

function reeid_render_meta_box_ui_controls($post)
{
    if (! $post instanceof WP_Post) {
        $post = get_post($post);
    }

    $languages        = function_exists('reeid_get_supported_languages') ? (array) reeid_get_supported_languages() : array();
    $flags            = function_exists('reeid_get_language_flags') ? (array) reeid_get_language_flags() : array();
    $saved_tone       = get_post_meta($post->ID, '_reeid_post_tone', true);
    $saved_prompt     = get_post_meta($post->ID, '_reeid_post_prompt', true);
    $source_lang      = get_post_meta($post->ID, '_reeid_translation_lang', true) ?: get_option('reeid_translation_source_lang', 'en');
    $saved_lang       = get_post_meta($post->ID, '_reeid_target_lang', true);
    $bulk_targets     = (array) get_option('reeid_bulk_translation_langs', array());
    $is_pro           = reeid_is_pro_active();
    $max_free_langs   = 10;
    $free_bulk_disabled   = ! $is_pro;
    $free_prompt_disabled = ! $is_pro;

    if (! $saved_lang || $saved_lang === $source_lang) {
        foreach ($languages as $code => $_label) {
            if ($code !== $source_lang) {
                $saved_lang = $code;
                break;
            }
        }
    }
?>
    <div class="reeid-field">
        <strong><?php esc_html_e('Select target language:', 'reeid-translate'); ?></strong>
        <select name="reeid_target_lang" id="reeid_target_lang">
            <?php
            $langs_list = $is_pro ? array_keys($languages) : array_slice(array_keys($languages), 0, $max_free_langs + 1);
            foreach ($langs_list as $code) {
                if ($code === $source_lang) {
                    continue;
                }
                printf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr($code),
                    selected($saved_lang, $code, false),
                    esc_html($languages[$code])
                );
            }
            ?>
        </select>
    </div>

    <div class="reeid-field">
        <strong><?php esc_html_e('Tone override:', 'reeid-translate'); ?></strong>
        <select name="reeid_post_tone" id="reeid_tone_pick" style="width:100%;margin-bottom:8px;">
            <option value=""><?php esc_html_e('Use default', 'reeid-translate'); ?></option>
            <?php
            $tones = array('Neutral', 'Formal', 'Informal', 'Friendly', 'Technical', 'Persuasive', 'Concise', 'Verbose');
            foreach ($tones as $tone) {
                printf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr($tone),
                    selected($saved_tone, $tone, false),
                    esc_html($tone)
                );
            }
            ?>
        </select>
    </div>

    <div class="reeid-field">
        <strong><?php esc_html_e('Prompt override:', 'reeid-translate'); ?></strong>
        <textarea name="reeid_post_prompt" id="reeid_prompt" rows="3" style="width:100%;" <?php echo $free_prompt_disabled ? 'disabled placeholder="' . esc_attr__('Available in PRO', 'reeid-translate') . '"' : ''; ?>><?php echo esc_textarea($saved_prompt); ?></textarea>
        <?php if ($free_prompt_disabled) : ?>
            <div style="color:#888;font-size:11px;"><?php esc_html_e('Custom prompt is available in PRO version.', 'reeid-translate'); ?></div>
        <?php endif; ?>
    </div>

    <div class="reeid-field">
        <strong><?php esc_html_e('Translation Status', 'reeid-translate'); ?></strong><br>
        <label>
            <input type="radio" name="reeid_publish_mode" value="publish" checked>
            <?php esc_html_e('Translate and Publish', 'reeid-translate'); ?>
        </label><br>
        <label>
            <input type="radio" name="reeid_publish_mode" value="draft">
            <?php esc_html_e('Translate and Save as Draft', 'reeid-translate'); ?>
        </label>
    </div>

    <div id="reeid-bulk-progress-list" class="reeid-bulk-progress-list"></div>

    <div class="reeid-buttons">
        <button id="reeid-translate-btn" class="reeid-button primary" data-postid="<?php echo esc_attr($post->ID); ?>">
            <?php esc_html_e('Translate', 'reeid-translate'); ?>
        </button>

        <button id="reeid-bulk-translate-btn" class="reeid-button secondary" data-postid="<?php echo esc_attr($post->ID); ?>" <?php echo $free_bulk_disabled ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''; ?>>
            <?php esc_html_e('Bulk Translation', 'reeid-translate'); ?>
        </button>

        <?php if ($free_bulk_disabled) : ?>
            <div style="color:#888;font-size:11px;">
                <?php esc_html_e('Bulk Translation is available in PRO version.', 'reeid-translate'); ?>
            </div>
        <?php endif; ?>
    </div>

    <div id="reeid-status"></div>
    <hr>

    <?php if (! $is_pro) : ?>
        <div style="font-size:12px;color:#888;">
            <?php
            $upgrade_url  = esc_url('https://reeid.com/pro/');
            $upgrade_link = sprintf(
                /* translators: %s: URL to REEID PRO upgrade page */
                __('Upgrade to <a href="%s" target="_blank" rel="noopener" style="color:#2271b1;text-decoration:underline;">REEID PRO</a> for unlimited languages, bulk translation, and custom instructions.', 'reeid-translate'),
                $upgrade_url
            );
            echo wp_kses_post($upgrade_link);
            ?>
        </div>
    <?php else : ?>
        <div style="font-size:12px;color:#32c24d;">
            <?php esc_html_e('PRO features unlocked! Enjoy unlimited languages, bulk, and advanced options.', 'reeid-translate'); ?>
        </div>
    <?php endif; ?>
    <?php
}


/*==============================================================================
 SECTION 13: ELEMENTOR DATA SANITIZER (ARRAY ONLY, JSON UNSERIALIZE FALLBACK)
==============================================================================*/

/**
 * Safely retrieve and decode Elementor data for a given post.
 *
 * @param int $post_id Post ID.
 * @return array|object|false Decoded Elementor data, or false if invalid.
 */
function reeid_get_sanitized_elementor_data($post_id)
{
    $raw = get_post_meta($post_id, '_elementor_data', true);

    if ('' === $raw || null === $raw) {
        return false;
    }

    if (is_array($raw) || is_object($raw)) {
        return $raw;
    }

    $decoded = json_decode($raw, true);
    if (JSON_ERROR_NONE === json_last_error()) {
        return $decoded;
    }

    if (is_serialized($raw)) {
        $maybe = maybe_unserialize($raw);
        if (is_array($maybe) || is_object($maybe)) {
            return $maybe;
        }
    }

    // Optional: only dump if WP_DEBUG and explicit flag
    if (defined('WP_DEBUG') && WP_DEBUG && defined('REEID_DEBUG_ELEMENTOR_DUMP') && REEID_DEBUG_ELEMENTOR_DUMP) {
        file_put_contents(
            plugin_dir_path(__FILE__) . "failed-elementor-{$post_id}.dump",
            wp_json_encode(['raw' => $raw], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    return false;
}


/*==============================================================================
  SECTION 14: ELEMENTOR TRANSLATION ENGINE — Remote API Only (with overlay)
==============================================================================*/

/** Overlay pass: enable during troubleshooting for stubborn body text */
if (! defined('REEID_S13_OVERLAY')) {
    define('REEID_S13_OVERLAY', false); // set to false once you're satisfied
}

/** Local logger (uploads/reeid-debug.log only) */
if (! function_exists('reeid__s13_log')) {
    function reeid__s13_log($label, $data = null)
    {
        $line = '[' . gmdate('c') . '] S13 ' . $label . ': ';
        if (is_array($data) || is_object($data)) {
            $data = wp_json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        $uploads = wp_upload_dir();
        if (! empty($uploads['basedir'])) {
            @file_put_contents(trailingslashit($uploads['basedir']) . 'reeid-debug.log', $line . (string)$data . "\n", FILE_APPEND);
        }
    }
}

/** Token sanitizer for options polluted by stray HTML/whitespace */
if (! function_exists('reeid__s13_clean_token')) {
    function reeid__s13_clean_token($v): string
    {
        $v = is_string($v) ? trim($v) : '';
        if ($v === '') return '';
        $v = preg_split('/[\s<]/u', $v, 2)[0] ?? $v;
        if (preg_match('/^[A-Za-z0-9._-]{5,256}$/', $v)) return $v;
        return preg_replace('/[^A-Za-z0-9._-]/', '', $v);
    }
}

/** Helpers: API base, site host, BYOK OpenAI key, license key */
if (! function_exists('reeid__s13_api_base')) {
    function reeid__s13_api_base(): string
    {
        $opts = get_option('reeid-translate-settings', []);
        $base = '';
        if (is_array($opts) && !empty($opts['api_base'])) {
            $base = trim((string)$opts['api_base']);
        }
        if ($base === '') {
            $base = (string) get_option('reeid_api_base', 'https://api.reeid.com');
        }
        return rtrim($base, '/');
    }
}
if (! function_exists('reeid__s13_site_host')) {
    function reeid__s13_site_host(): string
    {
        if (function_exists('reeid9_site_host')) {
            $h = (string) reeid9_site_host();
            if ($h !== '') return strtolower($h);
        }
        $p = wp_parse_url(home_url('/'));
        return isset($p['host']) ? strtolower($p['host']) : 'localhost';
    }
}
if (! function_exists('reeid__s13_openai_key')) {
    function reeid__s13_openai_key(): string
    {
        $opts = get_option('reeid-translate-settings', []);
        if (is_array($opts)) {
            foreach (['openai_api_key', 'openai', 'api_key', 'reeid_openai_api_key'] as $k) {
                if (!empty($opts[$k])) return reeid__s13_clean_token($opts[$k]);
            }
        }
        $k = get_option('reeid_openai_api_key', '');
        return reeid__s13_clean_token($k);
    }
}
if (! function_exists('reeid__s13_license_key')) {
    function reeid__s13_license_key(): string
    {
        $opts = get_option('reeid-translate-settings', []);
        if (is_array($opts)) {
            foreach (['license_key', 'license', 'key', 'reeid_license_key', 'reeid_license', 'api_license'] as $k) {
                if (!empty($opts[$k])) return reeid__s13_clean_token($opts[$k]);
            }
        }
        $k = get_option('reeid_license_key', '');
        return reeid__s13_clean_token($k);
    }
}

/** Minimal collector: EVERY non-URL string under any ".settings." */
if (! function_exists('reeid__s13_collect_map')) {
    function reeid__s13_collect_map($data): array
    {
        $out = [];
        $walk = function ($node, $path) use (&$walk, &$out) {
            if (is_array($node) || is_object($node)) {
                foreach ((array) $node as $k => $v) {
                    $new = ($path === '') ? (string)$k : $path . '.' . (string)$k;
                    $walk($v, $new);
                }
                return;
            }
            if (is_string($node)) {
                if (
                    strpos($path, '.settings.') !== false &&
                    $node !== '' &&
                    !preg_match('/^(https?:|mailto:|tel:|data:|#)/i', $node)
                ) {
                    $out[$path] = $node;
                }
            }
        };
        $walk($data, '');
        return $out;
    }
}

/** Get a value by dot-path (for before/after checks) */
if (! function_exists('reeid__s13_get_by_path')) {
    function reeid__s13_get_by_path($root, $path, &$exists = false)
    {
        $parts = explode('.', (string) $path);
        $ref   = $root;
        foreach ($parts as $p) {
            if (is_array($ref) && array_key_exists($p, $ref)) {
                $ref = $ref[$p];
            } elseif (is_object($ref) && isset($ref->$p)) {
                $ref = $ref->$p;
            } else {
                $exists = false;
                return null;
            }
        }
        $exists = true;
        return $ref;
    }
}

/** Internal: walk & replace (prefers your helper; returns bool for diagnostics) */
if (! function_exists('reeid__s13_walk_and_replace')) {
    function reeid__s13_walk_and_replace(&$data, $path, $new): bool
    {
        $had = false;
        $before = reeid__s13_get_by_path($data, $path, $had);
        if (! $had) {
            if (function_exists('reeid_elementor_walk_and_replace')) {
                reeid_elementor_walk_and_replace($data, (string)$path, $new);
                $after = reeid__s13_get_by_path($data, $path, $had);
                return $had && $after !== $before;
            }
            return false;
        }
        $parts = explode('.', (string) $path);
        $ref   = &$data;
        foreach ($parts as $p) {
            if (is_array($ref) && array_key_exists($p, $ref)) {
                $ref = &$ref[$p];
            } elseif (is_object($ref) && isset($ref->$p)) {
                $ref = &$ref->$p;
            } else {
                return false;
            }
        }
        $ref = $new;
        return true;
    }
}

/** Diff helper: compare maps to see how many strings changed */
if (! function_exists('reeid__s13_diff_maps')) {
    function reeid__s13_diff_maps(array $before, array $after): array
    {
        $common = array_intersect_key($before, $after);
        $changed = 0;
        $examples = [];
        foreach ($common as $k => $v) {
            if ((string)$v !== (string)$after[$k]) {
                $changed++;
                if (count($examples) < 8) {
                    $examples[] = $k;
                }
            }
        }
        return ['total_before' => count($before), 'total_after' => count($after), 'changed' => $changed, 'examples' => $examples];
    }
}

/** Per-string remote HTML translator (still via api.reeid.com) */
if (! function_exists('reeid__s13_remote_html')) {
    /* >>> INJECTION START: add $prompt param (BC-safe default) */
    function reeid__s13_remote_html(string $source_lang, string $target_lang, string $html, string $tone = 'Neutral', string $prompt = ''): string
    {

        $payload = [
            'license_key' => reeid__s13_license_key(),
            'domain'      => reeid__s13_site_host(),
            'editor'      => 'classic',
            'mode'        => 'single',
            'source_lang' => $source_lang,
            'target_lang' => $target_lang,
            'content'     => [
                'title' => '',
                'html'  => $html
            ],
            'options'     => [
                'tone'           => $tone ?: 'Neutral',
                'slug_policy'    => 'none',
                'seo_profile'    => 'default',
                'return_paths'   => false,

                // **Single, canonical prompt forwarding**
                'custom_prompt'  => (string)($prompt ?? ''),
            ],
            'openai_key'  => reeid__s13_openai_key(),
        ];

        $api = reeid__s13_api_base() . '/v1/translate';
        $res = wp_remote_post($api, [
            'timeout'     => 45,
            'headers'     => ['Content-Type' => 'application/json; charset=utf-8', 'X-REEID-Client' => 'wp-plugin-universal/1.7'],
            'body'        => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'data_format' => 'body',
        ]);
        if (is_wp_error($res)) return '';
        if ((int) wp_remote_retrieve_response_code($res) !== 200) return '';
        $j = json_decode((string) wp_remote_retrieve_body($res), true);
        if (! is_array($j) || empty($j['translation']) || ! is_array($j['translation'])) return '';
        return (string) ($j['translation']['html'] ?? '');
    }
}
/** Batch-translate many HTML fragments (still via api.reeid.com) */
if (! function_exists('reeid__s13_remote_html_batch')) {
    function reeid__s13_remote_html_batch(string $source_lang, string $target_lang, array $path_to_html, string $tone = 'Neutral', string $prompt = ''): array
    {

        // 1) sanitize input map
        $clean = [];
        foreach ($path_to_html as $p => $html) {
            if (is_string($p) && is_string($html)) {
                $html = trim($html);
                if ($html !== '') {
                    $clean[(string)$p] = $html;
                }
            }
        }
        if (empty($clean)) {
            return [];
        }

        // 2) payload for "classic" strings channel + prompt forwarding
        $payload = [
            'license_key' => reeid__s13_license_key(),
            'domain'      => reeid__s13_site_host(),
            'editor'      => 'classic',
            'mode'        => 'single',
            'source_lang' => (string)$source_lang,
            'target_lang' => (string)$target_lang,
            'content'     => [
                'title'   => '',
                'html'    => '',
                'strings' => $clean
            ],
            'options'     => [
                'tone'           => $tone ?: 'Neutral',
                'slug_policy'    => 'none',
                'seo_profile'    => 'default',
                'return_paths'   => true,
                'prefer_channel' => 'strings',

                // **Single, canonical prompt forwarding**
                'custom_prompt'  => (string)($prompt ?? ''),
            ],
            'openai_key'  => reeid__s13_openai_key(),
            'debug'       => ['echo' => true],
        ];


        // 3) call
        $api = reeid__s13_api_base() . '/v1/translate';
        $res = wp_remote_post($api, [
            'timeout'     => 60,
            'headers'     => [
                'Content-Type'   => 'application/json; charset=utf-8',
                'X-REEID-Client' => 'wp-plugin-universal/1.7',
            ],
            'body'        => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'data_format' => 'body',
        ]);

        if (is_wp_error($res)) {
            return [];
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200) {
            return [];
        }

        $j = json_decode((string) wp_remote_retrieve_body($res), true);
        if (! is_array($j) || empty($j['translation']) || ! is_array($j['translation'])) {
            return [];
        }
        $tr = $j['translation'];
        return (isset($tr['strings']) && is_array($tr['strings'])) ? $tr['strings'] : [];
    }
}

/**
 * Main S13 entry (used by SECTION 16 AJAX)
 */
if (! function_exists('reeid_elementor_translate_json_s13')) {
    function reeid_elementor_translate_json_s13(int $post_id, string $source_lang, string $target_lang, string $tone = 'Neutral', string $prompt = ''): array
    {

        // 0) Post + Elementor JSON
        $post = get_post($post_id);
        if (! $post) {
            return ['success' => false, 'error' => __('Post not found', 'reeid-translate'), 'where' => 'no_post'];
        }
        $raw = function_exists('reeid_get_sanitized_elementor_data')
            ? reeid_get_sanitized_elementor_data($post_id)
            : get_post_meta($post_id, '_elementor_data', true);
        if (empty($raw)) {
            return ['success' => false, 'error' => __('No Elementor data to translate', 'reeid-translate'), 'where' => 'no_elementor'];
        }
        $elem = is_array($raw) ? $raw : json_decode((string)$raw, true);
        if (! is_array($elem)) {
            return ['success' => false, 'error' => __('Bad Elementor JSON', 'reeid-translate'), 'where' => 'json_decode'];
        }
        $elem_before = $elem; // keep original for diff

        // --- Extract no-translate terms from the prompt: "do not translate: a, b, c"
        $reeid_no_tr_terms = [];
        if (isset($prompt) && is_string($prompt) && $prompt !== '') {
            if (preg_match('/do\s*not\s*translate\s*:\s*(.+)$/imu', $prompt, $m)) {
                // split by comma or semicolon or pipe
                $parts = preg_split('/\s*[;,|]\s*/u', (string)$m[1]);
                foreach ($parts as $t) {
                    $t = trim($t);
                    if ($t !== '') {
                        $reeid_no_tr_terms[] = $t;
                    }
                }
                // dedupe, keep longest first to avoid partial masking collisions
                usort($reeid_no_tr_terms, function ($a, $b) {
                    return mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8');
                });
                $reeid_no_tr_terms = array_values(array_unique($reeid_no_tr_terms));
            }
        }

        // 1) Build path→text map
        $string_map = function_exists('reeid_elementor_walk_and_collect')
            ? (function ($e) {
                $m = [];
                reeid_elementor_walk_and_collect($e, '', $m);
                return $m;
            })($elem)
            : reeid__s13_collect_map($elem);
        $keys   = array_keys($string_map);
        $sample = array_slice($keys, 0, 15);
        reeid__s13_log('collect', ['count' => count($string_map), 'sample' => $sample]);

        // Normalize .text -> .editor
        $norm_map = [];
        $backrefs = [];
        foreach ($string_map as $path => $string) {
            $key = substr($path, strrpos($path, '.') + 1);
            if ($key === 'text') {
                $fake_path = substr($path, 0, strrpos($path, '.')) . '.editor';
                $norm_map[$fake_path] = $string;
                $backrefs[$fake_path] = $path;
            } else {
                $norm_map[$path] = $string;
            }
        }
        $string_map = $norm_map;
        // --- Build a per-path mask map & mask terms so the remote translator won't touch them
        $reeid_mask_map = []; // path => [ token => original_text, ... ]
        if (!empty($reeid_no_tr_terms)) {
            $token_i = 1;

            foreach ($string_map as $p => $txt) {
                if (!is_string($txt) || $txt === '') continue;

                $mask_for_path = [];
                $masked = $txt;

                foreach ($reeid_no_tr_terms as $term) {
                    // skip if not present
                    if (mb_stripos($masked, $term, 0, 'UTF-8') === false) continue;

                    // create a stable token per path+term (prevents translator from altering)
                    $token = "__REEID_NO_TR_" . $token_i . "__";
                    $token_i++;

                    // replace ALL occurrences (case-insensitive, multibyte safe)
                    $escaped = preg_quote($term, '/');
                    $masked = preg_replace('/' . $escaped . '/iu', $token, $masked);

                    // remember what to restore
                    $mask_for_path[$token] = $term;
                }

                if (!empty($mask_for_path)) {
                    $string_map[$p] = $masked;          // send masked text to API
                    $reeid_mask_map[$p] = $mask_for_path; // keep tokens → originals for unmasking
                }
            }
        }


        /* Prompt-based no-translate masking (S13) */
        $reeid_nt_tokens = [];
        if (isset($prompt) && is_string($prompt) && $prompt !== '') {
            // Extract tokens after "do not translate:"
            if (preg_match('/do\s*not\s*translate\s*:\s*(.+)$/iu', $prompt, $m)) {
                $list  = trim((string)$m[1]);
                // split by comma/semicolon or " and "
                $parts = preg_split('/\s*(?:,|;|\band\b)\s*/iu', $list);
                foreach ((array) $parts as $t) {
                    $t = trim($t, " \t\n\r\0\x0B\"'`.");
                    if ($t !== '') {
                        $reeid_nt_tokens[] = $t;
                    }
                }
            }
        }

        $reeid_nt_locks = []; 
        $__reeid_nt_i   = 0;

        if (! empty($reeid_nt_tokens)) {
            foreach ($string_map as $path => $txt) {
                $work = (string) $txt;
                foreach ($reeid_nt_tokens as $tok) {
                    if ($tok === '') {
                        continue;
                    }
                    $pattern = '/' . preg_quote($tok, '/') . '/iu';
                    if (preg_match($pattern, $work)) {
                        // Use unique placeholders so API can’t touch them.
                        $ph = '[[' . 'REEID_LOCK_' . (++$__reeid_nt_i) . ']]';
                        $new = preg_replace($pattern, $ph, $work);
                        if ($new !== null && $new !== $work) {
                            $map = $reeid_nt_locks[$path] ?? [];
                            $map[$ph] = $tok;
                            $reeid_nt_locks[$path] = $map;
                            $work = $new;
                        }
                    }
                }
                $string_map[$path] = $work;
            }
        }
       
       // 2) Payload (cleaned)
$payload = [
    'license_key' => reeid__s13_license_key(),
    'domain'      => reeid__s13_site_host(),
    'editor'      => 'elementor',
    'mode'        => 'single',
    'source_lang' => (string) $source_lang,
    'target_lang' => (string) $target_lang,
    'content'     => [
        'title'     => (string) $post->post_title,
        'html'      => '',
        'elementor' => $elem,
        'strings'   => $string_map,
        // remove content-level prompt (or keep only if server expects it)
        //'prompt'    => (string) ($prompt ?? ''),
    ],
    'options'     => [
        'tone'           => $tone ?: 'Neutral',
        'slug_policy'    => 'native',
        'seo_profile'    => 'default',
        'return_paths'   => true,
        'prefer_channel' => 'strings',
        // canonical, easy-to-log prompt fields:
        'prompt'         => (string) ($prompt ?? ''),        // short canonical spot
        'custom_prompt'  => (string) ($prompt ?? ''),        // legacy/alias
        'system'         => (string) ($prompt ?? ''),        // if server reads 'system'
    ],
    'openai_key'  => reeid__s13_openai_key(),
    // 'debug'       => ['echo' => true], // <-- keep only while actively debugging
];

        // 3) HTTP
        $api = reeid__s13_api_base() . '/v1/translate';
        $res = wp_remote_post($api, [
            'timeout'     => 90,
            'headers'     => [
                'Content-Type'   => 'application/json; charset=utf-8',
                'X-REEID-Client' => 'wp-plugin-universal/1.7',
            ],
            'body'        => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'data_format' => 'body',
        ]);
        if (is_wp_error($res)) {
            return ['success' => false, 'error' => $res->get_error_message(), 'where' => 'api_http_error'];
        }
        $code    = (int) wp_remote_retrieve_response_code($res);
        $rawBody = (string) wp_remote_retrieve_body($res);
        reeid__s13_log('http', $code);
// --- START TEMP DEBUG: dump elementor API request + response (REMOVE AFTER USE) ---
@file_put_contents('/tmp/reeid_elementor_api_debug.log',
    "=== TIME: " . gmdate('c') . " ===\n" .
    "ENDPOINT: " . ($api ?? '') . "\n\n" .
    "---- REQUEST PAYLOAD ----\n" .
    wp_json_encode($payload ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n" .
    "---- RESPONSE CODE ----\n" . (isset($code) ? (string)$code : '') . "\n\n" .
    "---- RESPONSE BODY ----\n" . ($rawBody ?? '') . "\n\n" .
    "---- END ----\n\n",
    FILE_APPEND
);
// --- END TEMP DEBUG ---
        if ($code !== 200) {
            return ['success' => false, 'error' => 'API HTTP ' . $code, 'where' => 'api_non_2xx', 'snippet' => mb_substr($rawBody, 0, 1000, 'UTF-8')];
        }

        // 4) Parse
        $json = json_decode($rawBody, true);
        if (! is_array($json)) {
            return ['success' => false, 'error' => 'API returned non-JSON', 'where' => 'api_bad_json', 'snippet' => mb_substr($rawBody, 0, 1000, 'UTF-8')];
        }
        reeid__s13_log('resp_keys', array_keys($json));
        if (isset($json['ok']) && ! $json['ok']) {
            return ['success' => false, 'error' => (string)($json['error'] ?? 'API error'), 'where' => 'api_error', 'snippet' => mb_substr($rawBody, 0, 1000, 'UTF-8')];
        }
        if (empty($json['translation']) || ! is_array($json['translation'])) {
            return ['success' => false, 'error' => 'Missing translation in API response', 'where' => 'api_bad_payload', 'snippet' => mb_substr($rawBody, 0, 1200, 'UTF-8')];
        }

        $tr        = (array) $json['translation'];
        $title_out = (string) ($tr['title'] ?? $post->post_title);
        reeid__s13_log('tr_keys', array_keys($tr));

        // 5) Build translated Elementor tree
        $data_out = null;
        if (isset($tr['strings']) && is_array($tr['strings'])) {
            $map = $tr['strings'];
            if (! empty($backrefs)) {
                foreach ($map as $p => $val) {
                    if (isset($backrefs[$p])) {
                        $real = $backrefs[$p];
                        $map[$real] = $val;
                        unset($map[$p]);
                    }
                }
            }
            foreach ($map as $p => $v) {
                if (is_string($p) && !is_array($v) && !is_object($v)) {
                    reeid__s13_walk_and_replace($elem, $p, (string)$v);
                }
            }
            $data_out = $elem;
            reeid__s13_log('strings_applied', ['count' => count($map)]);
        } else {
            foreach (['elementor', 'data', 'json', 'elementor_data', 'body'] as $k) {
                if (isset($tr[$k])) {
                    $cand = $tr[$k];
                    if (is_string($cand)) {
                        $decoded = json_decode($cand, true);
                        if (is_array($decoded)) {
                            $data_out = $decoded;
                            reeid__s13_log('used_branch', 'elementor:json');
                            break;
                        }
                    } elseif (is_array($cand)) {
                        $data_out = $cand;
                        reeid__s13_log('used_branch', 'elementor:array');
                        break;
                    }
                }
            }
        }
        if ($data_out === null && isset($tr['patch']) && is_array($tr['patch'])) {
            foreach ($tr['patch'] as $op) {
                if (is_array($op) && strtolower((string)$op['op'] ?? '') === 'replace') {
                    reeid__s13_walk_and_replace($elem, (string)($op['path'] ?? ''), $op['value'] ?? '');
                }
            }
            $data_out = $elem;
            reeid__s13_log('patch_applied', true);
        }
        if ($data_out === null) {
            $data_out = $elem;
        }
        // --- Unmask tokens in the translated output back to the original protected terms
        if (!empty($reeid_mask_map)) {
            // collect strings from $data_out
            $map_after = function_exists('reeid_elementor_walk_and_collect')
                ? (function ($e) {
                    $m = [];
                    reeid_elementor_walk_and_collect($e, '', $m);
                    return $m;
                })($data_out)
                : reeid__s13_collect_map($data_out);

            foreach ($map_after as $p => $val) {
                if (!is_string($val) || $val === '') continue;
                if (empty($reeid_mask_map[$p])) continue;

                $restored = $val;
                // restore each token for this path
                foreach ($reeid_mask_map[$p] as $token => $orig) {
                    $restored = str_replace($token, $orig, $restored);
                }

                if ($restored !== $val) {
                    reeid__s13_walk_and_replace($data_out, $p, $restored);
                }
            }
        }

        $before_map = function_exists('reeid_elementor_walk_and_collect')
            ? (function ($e) {
                $m = [];
                reeid_elementor_walk_and_collect($e, '', $m);
                return $m;
            })($elem_before)
            : reeid__s13_collect_map($elem_before);
        $after_map = function_exists('reeid_elementor_walk_and_collect')
            ? (function ($e) {
                $m = [];
                reeid_elementor_walk_and_collect($e, '', $m);
                return $m;
            })($data_out)
            : reeid__s13_collect_map($data_out);
        $diff = reeid__s13_diff_maps($before_map, $after_map);
        reeid__s13_log('diff_strings', $diff);

        // === Overlay fallback with Elementor, then HTML batch, then plain text
        if (REEID_S13_OVERLAY && $diff['changed'] < $diff['total_before']) {

            // find unchanged paths
            $unchanged = [];
            foreach ($before_map as $p => $orig) {
                if (isset($after_map[$p]) && $after_map[$p] === $orig) {
                    $unchanged[$p] = $orig;
                }
            }

            // keep only .settings.editor
            $candidates = array_filter(
                $unchanged,
                function ($v, $p) {
                    return is_string($v) && strpos($p, '.settings.editor') !== false;
                },
                ARRAY_FILTER_USE_BOTH
            );

            if (!empty($candidates)) {
                $applied = 0;

                // ------------------ Attempt 1: Elementor endpoint ------------------
                $mini_elementor = [];
                foreach ($candidates as $p => $txt) {
                    $mini_elementor[] = [
                        'elType'   => 'widget',
                        'settings' => ['editor' => (string) $txt],
                    ];
                }

                $payload = [
                    'ok'      => true,
                    'src'     => (string) $source_lang,
                    'dst'     => (string) $target_lang,
                    'editor'  => 'elementor',
                    'content' => [
                        'title'     => '',
                        'elementor' => $mini_elementor,
                    ],
                    'options' => [],
                ];

                $resp = [];
                $url  = 'https://api.reeid.com/v1/translate';
                $args = [
                    'headers' => ['Content-Type' => 'application/json'],
                    'body'    => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'timeout' => 30,
                ];
                $http = wp_remote_post($url, $args);
                if (! is_wp_error($http)) {
                    $resp = json_decode((string) wp_remote_retrieve_body($http), true);
                }

                $overlay_tr = [];
                if (is_array($resp) && isset($resp['translation']['elementor']) && is_array($resp['translation']['elementor'])) {
                    $paths = array_keys($candidates);
                    foreach ($resp['translation']['elementor'] as $idx => $node) {
                        $tr_txt = '';
                        if (is_array($node) && isset($node['settings']['editor'])) {
                            $tr_txt = (string) $node['settings']['editor'];
                        }
                        $path = $paths[$idx] ?? null;
                        if ($path && $tr_txt !== '') {
                            $overlay_tr[$path] = $tr_txt;
                        }
                    }
                }

                $applied = 0;
                foreach ($candidates as $p => $orig) {
                    $tr_html = (string) ($overlay_tr[$p] ?? '');
                    if ($tr_html === '') {
                        continue;
                    }
                    if (reeid__s13_walk_and_replace($data_out, $p, $tr_html)) {
                        $applied++;
                    }
                }
                reeid__s13_log('overlay_elementor', ['candidates' => count($candidates), 'applied' => $applied]);

                // ------------------ Attempt 2: HTML batch ------------------
                if ($applied === 0) {
                    $orig_list = array_values($candidates);
                    $resp2     = reeid__s13_remote_html_batch((string) $source_lang, (string) $target_lang, $orig_list, (string) $tone, (string) ($prompt ?? ''));

                    if (is_array($resp2)) {
                        $idx = 0;
                        foreach ($candidates as $p => $orig) {
                            $tr_html = (string) ($resp2[$idx] ?? '');
                            $idx++;
                            if ($tr_html === '') {
                                continue;
                            }
                            if (reeid__s13_walk_and_replace($data_out, $p, $tr_html)) {
                                $applied++;
                            }
                        }
                    }
                    reeid__s13_log('overlay_html_batch', ['candidates' => count($candidates), 'applied' => $applied]);
                }

                // ------------------ Attempt 3: Plain text batch (strip tags) ------------------
                if ($applied === 0) {
                    $stripped = [];
                    foreach ($candidates as $p => $orig) {
                        $stripped[] = wp_strip_all_tags((string) $orig);
                    }

                    $resp3 = reeid__s13_remote_html_batch((string) $source_lang, (string) $target_lang, $stripped, (string) $tone, (string) ($prompt ?? ''));

                    if (is_array($resp3)) {
                        $idx = 0;
                        foreach ($candidates as $p => $orig) {
                            $plain_tr = (string) ($resp3[$idx] ?? '');
                            $idx++;
                            if ($plain_tr === '') {
                                continue;
                            }
                            if (reeid__s13_walk_and_replace($data_out, $p, $plain_tr)) {
                                $applied++;
                            }
                        }
                    }
                    reeid__s13_log('overlay_plain_text', ['candidates' => count($candidates), 'applied' => $applied]);
                }
            }
        }


        // 6) Slug
        if (isset($tr['slug']['preferred']) && is_string($tr['slug']['preferred']) && $tr['slug']['preferred'] !== '') {
            $slug_out = (string)$tr['slug']['preferred'];
        } else {
            $slug_out = function_exists('reeid_sanitize_native_slug')
                ? reeid_sanitize_native_slug($title_out)
                : sanitize_title($title_out);
        }

        return ['success' => true, 'title' => $title_out, 'slug' => $slug_out, 'data' => $data_out];
    }
}


/* -------------------------------------------------------------------------
 * ELEMENTOR — main translator (WP_HTTP transport + slug fallback)
 * ------------------------------------------------------------------------- */
function reeid_elementor_translate_json(
    int $post_id,
    string $source_lang,
    string $target_lang,
    string $tone = 'Neutral',
    string $prompt = ''
): array {
    if ( ! function_exists('wp_kses_post') ) { require_once ABSPATH . 'wp-includes/kses.php'; }

    // Elementor panel prompt isolation (no merging with globals)
    $__elem_prompt = '';
    if (is_string($prompt) && $prompt !== '') {
        $__elem_prompt = $prompt;
    } elseif (isset($_POST['prompt'])) {
        $__elem_prompt = is_string($_POST['prompt']) ? wp_kses_post( wp_unslash($_POST['prompt']) ) : '';
    }
    $prompt = $__elem_prompt;

    // Tiny logger
    $log = static function (string $code, array $meta = []) {
        $upload_dir = wp_upload_dir();
        $file = rtrim((string)($upload_dir['basedir'] ?? WP_CONTENT_DIR . '/uploads'), '/') . '/reeid-client.log';
        $line = gmdate('c') . ' ' . $code . ' ' . substr(json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), 0, 1200);
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        @chmod($file, 0664);
    };

    $endpoint    = trim((string) get_option('reeid_api_endpoint', ''));
    $openai_key  = (string) get_option('reeid_openai_api_key', '');

    if ($endpoint === '') {
        $log('ELEM/NO_ENDPOINT', []);
        return ['success' => false, 'code' => 'endpoint_missing', 'error' => 'API endpoint not configured'];
    }

    $raw  = get_post_meta($post_id, '_elementor_data', true);
    $data = is_array($raw) ? $raw : @json_decode((string)$raw, true);
    if (!is_array($data)) {
        $log('ELEM/DATA_MISSING', ['post_id' => $post_id]);
        return ['success' => false, 'code' => 'elementor_data_missing', 'error' => 'Elementor JSON missing'];
    }

    $tone = is_string($tone) && $tone !== '' ? $tone : 'Neutral';

    $payload = [
        'editor'      => 'elementor',
        'mode'        => 'single',
        'source_lang' => (string) $source_lang,
        'target_lang' => (string) $target_lang,
        'content'     => [
            'title'     => (string) get_the_title($post_id),
            'html'      => '',
            'elementor' => $data,
        ],
        'options'     => [
            'tone'           => $tone,
            'return_paths'   => true,
            'prefer_channel' => 'elementor',
            'custom_prompt'  => (string) $prompt,
        ],
        'openai_key'  => $openai_key, // BYOK
    ];

    $res = wp_remote_post($endpoint, [
        'timeout'     => 45,
        'headers'     => [
            'Content-Type' => 'application/json; charset=utf-8',
            'Accept'       => 'application/json',
        ],
        'body'        => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'data_format' => 'body',
    ]);

    if (is_wp_error($res)) {
        $log('ELEM/WP_HTTP_ERROR', ['error' => $res->get_error_message()]);
        return ['success' => false, 'code' => 'transport_failed', 'error' => $res->get_error_message()];
    }

    $http = (int) wp_remote_retrieve_response_code($res);
    $body = (string) wp_remote_retrieve_body($res);
    if ($http < 200 || $http >= 300) {
        $log('ELEM/HTTP_NON_2XX', ['status' => $http, 'snippet' => mb_substr($body, 0, 300, 'UTF-8')]);
        return ['success' => false, 'code' => "http_$http", 'error' => 'server_error'];
    }

    $out = json_decode($body, true);
    if (!is_array($out)) {
        $maybe = json_decode(trim($body, "\" \n\r\t"), true); // double-encoded guard
        if (is_array($maybe)) { $out = $maybe; }
    }
    if (!is_array($out)) {
        $log('ELEM/BAD_JSON', ['snippet' => mb_substr($body, 0, 300, 'UTF-8')]);
        return ['success' => false, 'code' => 'bad_response', 'error' => 'non_json_response'];
    }

    // Flatten common shapes
    if (isset($out['translation']) && is_array($out['translation'])) {
        $out = array_merge($out, $out['translation']); // title, elementor/html, slug
    }
    if (empty($out['elementor']) && !empty($out['data']) && is_array($out['data'])) {
        $out['elementor'] = $out['data'];
    }
    if (!isset($out['elementor']) && isset($out['elementor_json']) && is_string($out['elementor_json'])) {
        $tmp = json_decode($out['elementor_json'], true);
        if (is_array($tmp)) { $out['elementor'] = $tmp; }
    }

   // Slug normalization & fallback from translated title (clean entities, unicode dashes, numeric dash codes)
$slug_candidate = '';
if (isset($out['slug'])) {
    if (is_array($out['slug']) && isset($out['slug']['preferred'])) {
        $slug_candidate = (string) $out['slug']['preferred'];
    } elseif (is_string($out['slug'])) {
        $slug_candidate = (string) $out['slug'];
    }
}
if ($slug_candidate === '' && !empty($out['title'])) {
    $slug_candidate = (string) $out['title'];
}
if ($slug_candidate !== '' && function_exists('reeid_sanitize_native_slug')) {
    // Decode any entities, strip tags
    $slug_candidate = wp_strip_all_tags( html_entity_decode($slug_candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8') );
    // Normalize unicode dash variants → "-"
    $slug_candidate = preg_replace('/[\x{2010}\x{2011}\x{2012}\x{2013}\x{2014}\x{2212}]+/u', '-', $slug_candidate);
    // Fix numeric artifacts that sometimes leak from HTML entities
    $slug_candidate = preg_replace('/\b(8210|8211|8212|8722)\b/u', '-', $slug_candidate);
    // Collapse spaces and hyphen runs
    $slug_candidate = preg_replace('/\s+/u', ' ', $slug_candidate);
    $slug_candidate = preg_replace('/-{2,}/', '-', $slug_candidate);
    $slug_candidate = trim($slug_candidate, " \t\n\r\0\x0B-");
    // **Lowercase ASCII only** (non-ASCII untouched)
    $slug_candidate = preg_replace_callback('/[A-Z]+/', static function($m){ return strtolower($m[0]); }, $slug_candidate);
    // Final native-script slug sanitize
    $out['slug'] = reeid_sanitize_native_slug($slug_candidate);
}


if (!empty($out['elementor']) && is_array($out['elementor'])) {
    return [
        'success' => true,
        'title'   => isset($out['title']) && is_string($out['title']) ? $out['title'] : '',
        'data'    => $out['elementor'],
        'html'    => isset($out['html']) && is_string($out['html']) ? $out['html'] : '',
        'slug'    => isset($out['slug']) && is_string($out['slug']) ? $out['slug'] : '',
    ];
}

$api_code = isset($out['code']) && is_string($out['code']) ? $out['code'] : 'remote_failed';
$api_err  = isset($out['error']) && is_string($out['error']) ? $out['error'] : 'remote_failed';
$log('ELEM/API_FAIL', ['code' => $api_code, 'error' => $api_err]);
return ['success' => false, 'code' => $api_code, 'error' => $api_err];

}





/*==============================================================================
  SECTION 15: ELEMENTOR JSON WALKERS — SERVER-DRIVEN (SEALED) WITH FALLBACK
==============================================================================*/

/**
 * Fetch sealed walker rules from api.reeid.com and decrypt with the site keypair.
 * Returns: ['translatable_keys'=>[], 'skip_keys'=>[]]  (both arrays of strings)
 * Caches in a transient for 12h.
 */
if (! function_exists('reeid_elementor_rules')) {
    function reeid_elementor_rules(): array
    {
        $cache_key = 'reeid_elem_rules_sealed_v1';
        $cached    = get_transient($cache_key);
        if (is_array($cached) && ! empty($cached['translatable_keys'])) {
            return $cached;
        }

        // Ensure handshake creds
        $site_token  = (string) get_option('reeid_site_token', '');
        $site_secret = (string) get_option('reeid_site_secret', '');
        if (! $site_token || ! $site_secret) {
            if (function_exists('reeid_api_handshake')) {
                $hs = reeid_api_handshake(false);
                if (empty($hs['ok'])) {
                    $hs = reeid_api_handshake(true);
                }
                $site_token  = (string) get_option('reeid_site_token', '');
                $site_secret = (string) get_option('reeid_site_secret', '');
            }
        }
        if (! $site_token || ! $site_secret) {
            return reeid_elementor_rules_fallback('no_creds');
        }

        // Signed GET to /v1/walkers/elementor-rules
        $ts    = (string) time();
        $nonce = bin2hex(random_bytes(12));
        $sig   = hash_hmac('sha256', $ts . "\n" . $nonce . "\n", $site_secret); // GET = empty body

        $base = defined('REEID_API_BASE') ? REEID_API_BASE : 'https://api.reeid.com';
        $url  = rtrim($base, '/') . '/v1/walkers/elementor-rules?site_token=' . rawurlencode($site_token);

        $resp = wp_remote_get($url, [
            'headers' => [
                'X-REEID-Ts'    => $ts,
                'X-REEID-Nonce' => $nonce,
                'X-REEID-Sig'   => $sig,
                'Accept'        => 'application/json',
            ],
            'timeout' => 20,
        ]);
        if (is_wp_error($resp)) {
            return reeid_elementor_rules_fallback('http_error');
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code !== 200) {
            return reeid_elementor_rules_fallback('bad_http_' . $code);
        }

        $j = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (! is_array($j) || empty($j['ok']) || empty($j['sealed']) || empty($j['sip_token'])) {
            return reeid_elementor_rules_fallback('bad_payload');
        }

        // Decrypt the SIP with our sodium keypair
        $kp_b64 = (string) get_option('reeid_kp_secret', '');
        if ($kp_b64 === '' || ! function_exists('sodium_crypto_box_seal_open')) {
            return reeid_elementor_rules_fallback('no_keypair');
        }
        $kp     = base64_decode($kp_b64, true);
        $cipher = base64_decode((string) $j['sip_token'], true);
        if ($kp === false || $cipher === false) {
            return reeid_elementor_rules_fallback('bad_b64');
        }
        $plain = sodium_crypto_box_seal_open($cipher, $kp);
        if ($plain === false) {
            return reeid_elementor_rules_fallback('decrypt_fail');
        }

        $rules = json_decode($plain, true);
        if (! is_array($rules)) {
            return reeid_elementor_rules_fallback('json_fail');
        }

        $out = [
            'translatable_keys' => array_values(array_unique(array_map('strval', (array) ($rules['translatable_keys'] ?? [])))),
            'skip_keys'         => array_values(array_unique(array_map('strval', (array) ($rules['skip_keys'] ?? [])))),
        ];
        if (empty($out['translatable_keys'])) {
            return reeid_elementor_rules_fallback('empty_rules');
        }

        // Provenance breadcrumbs for your Stat page
        update_option('reeid_walkers_source',    'api', false);
        update_option('reeid_walkers_version',   (string) ($j['version'] ?? ''), false);
        update_option('reeid_walkers_fetched_at', time(), false);

        set_transient($cache_key, $out, 12 * HOUR_IN_SECONDS);
        return $out;
    }
}

/** Minimal safe built-in rules if API is down (or forced-off switch). */
if (! function_exists('reeid_elementor_rules_fallback')) {
    function reeid_elementor_rules_fallback(string $reason = ''): array
    {
        $force_server_only = (string) get_option('reeid_require_server_rules', '0') === '1';
        if ($force_server_only) {
            update_option('reeid_walkers_source', 'forced-off', false);
            update_option('reeid_walkers_reason', $reason, false);
            return ['translatable_keys' => [], 'skip_keys' => []];
        }

        update_option('reeid_walkers_source', 'fallback', false);
        update_option('reeid_walkers_reason', $reason, false);

        return [
            // tiny emergency subset only
            'translatable_keys' => [
                'editor',
                'text',
                'content',
                'title',
                'description',
                'html',
                'heading',
                'subtitle',
                'button_text',
                'label',
                'link_text',
                'caption',
                'placeholder',
            ],
            'skip_keys' => [
                'type',
                'widgetType',
                'elType',
                'container_type',
                'layout',
                'skin',
                'tag',
                'id',
                'image_size',
                'columns',
                'column_width',
                'isInner',
                'isInnerSection',
                'elements',
                'settings',
                'key',
                'anchor',
                'gap',
                'html_tag',
            ],
        ];
    }
}

/**
 * Recursively collect strings under any ".settings." node, using server rules.
 *
 * @param mixed  $data Current subtree (array|object/scalar).
 * @param string $path Dotted path (e.g., "0.elements.1.settings.editor").
 * @param array  &$out Accumulator: path ⇒ original-string.
 */
if (! function_exists('reeid_elementor_walk_and_collect')) {
    function reeid_elementor_walk_and_collect($data, $path, &$out)
    {
        static $rules = null;
        if ($rules === null) {
            $rules = reeid_elementor_rules();
        }
        $translatable = (array) ($rules['translatable_keys'] ?? []);
        $skip_keys    = (array) ($rules['skip_keys'] ?? []);

        $key = substr((string) $path, (int) strrpos((string) $path, '.') + 1);

        if (is_array($data) || is_object($data)) {
            foreach ($data as $k => $v) {
                $new_path = ($path === '') ? (string) $k : "{$path}.{$k}";
                reeid_elementor_walk_and_collect($v, $new_path, $out);
            }
            return;
        }

        if (
            is_string($data)
            && strpos((string) $path, '.settings.') !== false
            && trim($data) !== ''
            && ! preg_match('/^(https?:|mailto:|tel:|data:|#)/i', $data)
            && ! in_array($key, $skip_keys, true)
            && in_array($key, $translatable, true)
        ) {
            $out[$path] = $data;
        }
    }
}

/**
 * Walk the dotted path into Elementor data and replace the value.
 *
 * @param array|object &$data Elementor data tree (by reference).
 * @param string       $path  Dotted path as recorded by collector.
 * @param mixed        $new   New translated value.
 */
if (! function_exists('reeid_elementor_walk_and_replace')) {
    function reeid_elementor_walk_and_replace(&$data, $path, $new)
    {
        $parts = explode('.', (string) $path);
        $ref   = &$data;
        foreach ($parts as $p) {
            if (is_array($ref) && isset($ref[$p])) {
                $ref = &$ref[$p];
            } elseif (is_object($ref) && isset($ref->$p)) {
                $ref = &$ref->$p;
            } else {
                return; // path does not exist
            }
        }
        $ref = $new;
    }
}


/*=================================================================
  SECTION 16 : Helper Stubs for Section 15 API Calls
=================================================================*/

if (! function_exists('reeid__s15_license_key')) {
    function reeid__s15_license_key()
    {
        // pull from options (adjust if your license is stored differently)
        return sanitize_text_field(get_option('reeid_license_key', ''));
    }
}

if (! function_exists('reeid__s15_site_host')) {
    function reeid__s15_site_host()
    {
        // safe domain for identification
        return preg_replace('#^www\.#', '', wp_parse_url(home_url(), PHP_URL_HOST) ?? '');
    }
}

if (! function_exists('reeid__s15_api_base')) {
    function reeid__s15_api_base()
    {
        // central API base
        return 'https://api.reeid.com';
    }
}



if (! function_exists('reeid__s15_slug_from_api')) {
    /**
     * Request slug from API, fallback to sanitize_title().
     */
    function reeid__s15_slug_from_api(string $target_lang, string $title, string $fallback = '', string $policy = 'native'): array
    {
        $api = reeid__s15_api_base() . '/v1/slug';
        $payload = [
            'target_lang' => $target_lang,
            'title'       => $title,
            'policy'      => $policy,
        ];

        $res = wp_remote_post($api, [
            'timeout'     => 20,
            'headers'     => ['Content-Type' => 'application/json; charset=utf-8'],
            'body'        => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
            'data_format' => 'body',
        ]);

        if (is_wp_error($res)) {
            return [
                'ok'        => false,
                'preferred' => sanitize_title($fallback ?: $title),
                'error'     => $res->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code($res);
        $raw  = (string) wp_remote_retrieve_body($res);
        $json = json_decode($raw, true);

        if ($code === 200 && is_array($json) && ! empty($json['slug'])) {
            return ['ok' => true, 'preferred' => (string) $json['slug']];
        }

        // fallback
        return [
            'ok'        => false,
            'preferred' => sanitize_title($fallback ?: $title),
            'error'     => 'bad_slug_api',
        ];
    }
}


if (! function_exists('reeid_safe_json_decode')) {
    /**
     * Safely decode JSON even if it contains stray control chars or invalid UTF‑8.
     */
    function reeid_safe_json_decode(string $json)
    {
        // Strip control chars except tab/newline/carriage return
        $json = preg_replace('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/u', '', $json);

        // Ensure UTF‑8
        if (!mb_detect_encoding($json, 'UTF-8', true)) {
            $json = mb_convert_encoding($json, 'UTF-8', 'auto');
        }

        return json_decode($json, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
    }
}



/*==============================================================================
  SECTION 17 : TEXT EXTRACTOR + REINJECTOR (Gutenberg/Classic only)
  - Extract visible text lines from HTML
  - Translate line by line 
  - Reinject preserving block structure
  - Wrap RTL output (Arabic, Hebrew, Persian, Urdu) with <div dir="rtl">
==============================================================================*/

if (! function_exists('reeid_openai_translate_single')) {
    function reeid_openai_translate_single(string $text, string $target_lang, string $tone = 'Neutral', string $prompt = ''): string
    {
// TEMP DEBUG LOG (REMOVE after troubleshooting)
// Writes a small JSON line to /tmp/reeid_call_debug.log (non-blocking, safe)
try {
    $dbg = [
        'time' => date('c'),
        'request_uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null,
        'is_admin' => (function_exists('is_admin') ? (bool) is_admin() : null),
        'target_lang' => isset($target_lang) ? (string)$target_lang : null,
        'tone' => isset($tone) ? (string)$tone : null,
        'prompt_param' => isset($prompt) ? (string)$prompt : '',
        'effective_prompt_preview' => isset($effective_prompt) ? (function_exists('mb_substr') ? mb_substr((string)$effective_prompt,0,300) : substr((string)$effective_prompt,0,300)) : null,
        'text_snippet' => (function_exists('mb_substr') ? mb_substr((string)$text, 0, 300) : substr((string)$text, 0, 300)),
        'stack' => array_map(function($f){ return isset($f['function']) ? $f['function'] : (isset($f['class']) ? $f['class'] : ''); }, array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 0, 6)),
        'ajax' => (defined('DOING_AJAX') ? (bool) DOING_AJAX : null),
    ];
    @file_put_contents('/tmp/reeid_call_debug.log', date('c').' '.json_encode($dbg, JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
} catch (Throwable $e) {
    @file_put_contents('/tmp/reeid_call_debug.log', date('c').' DEBUG-ERROR '.json_encode(['err'=>$e->getMessage()]).PHP_EOL, FILE_APPEND | LOCK_EX);
}
// END TEMP DEBUG LOG

// TEMP DEBUG LOG (remove after troubleshooting)
// Records call parameters and a short text snippet to /tmp/reeid_call_debug.log
try {
    $dbg = [
        'time' => date('c'),
        'request_uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null,
        'is_admin' => function_exists('is_admin') ? (bool) is_admin() : null,
        'target_lang' => isset($target_lang) ? (string)$target_lang : null,
        'tone' => isset($tone) ? (string)$tone : null,
        'prompt_param' => isset($prompt) ? (string)$prompt : '',
        'text_snippet' => (function_exists('mb_substr') ? mb_substr((string)$text, 0, 300) : substr((string)$text, 0, 300)),
        // small, readable call stack (names only)
        'stack' => array_map(function($f){ return isset($f['function']) ? $f['function'] : (isset($f['class']) ? $f['class'] : ''); }, array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 0, 6)),
        // minimal environment flags
        'ajax' => (defined('DOING_AJAX') ? (bool) DOING_AJAX : null),
    ];
    @file_put_contents('/tmp/reeid_call_debug.log', date('c').' '.json_encode($dbg, JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
} catch (Throwable $e) {
    // don't break execution for any reason
    @file_put_contents('/tmp/reeid_call_debug.log', date('c').' DEBUG-ERROR '.json_encode(['err'=>$e->getMessage()]).PHP_EOL, FILE_APPEND | LOCK_EX);
}
// END TEMP DEBUG LOG

/* REEID_DEBUG_CALL_LOG_START */
/* Temporary debug: log incoming prompt params & effective prompt for troubleshooting.
   Remove this block after debugging. */
$__reeid_dbg = array(
    'target_lang'     => isset($target_lang) ? (string)$target_lang : '',
    'tone'            => isset($tone) ? (string)$tone : '',
    'prompt_param'    => isset($prompt) ? (string)$prompt : '',
    'effective_prompt'=> isset($effective_prompt) ? (string)$effective_prompt : '',
);
// safe truncated sys preview (avoid huge writes)
$__reeid_dbg['sys_preview'] = isset($sys) && is_string($sys) ? mb_substr($sys,0,1000) : '';
@file_put_contents('/tmp/reeid_call_debug.log', date('c') . ' ' . json_encode($__reeid_dbg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
/* REEID_DEBUG_CALL_LOG_END */


        $api_key = get_option('reeid_openai_api_key', '');
        if (!$api_key) {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S17/NO_KEY', null);
            }
            return $text;
        }

        // Per-request prompt only: sanitize the incoming UI/meta prompt and pass it as the
// override to the canonical prompt builder. Do NOT merge the global/admin prompt
// here — reeid_get_combined_prompt() will append it exactly once.
$effective_prompt = '';
if ( is_string( $prompt ) && ( $p = trim( wp_kses_post( $prompt ) ) ) !== '' ) {
    $effective_prompt = $p;
}

// Build system prompt using canonical helper (preferred). Fallback to safe, neutral system message.
if ( function_exists( 'reeid_get_combined_prompt' ) ) {
    // post_id not available in this context — pass 0; pass per-request override only.
    $sys = reeid_get_combined_prompt( 0, $target_lang, (string) $effective_prompt );
} else {
    // safe fallback (no absolutist rules)
    $sys = "You are a professional translator. Translate the source text into the target language, preserving HTML, placeholders and the original tone. Preserve brand names and placeholders unless instructed otherwise. Produce natural, idiomatic output.";
    if ( is_string( $effective_prompt ) && trim( $effective_prompt ) !== '' ) {
        $sys .= ' ' . trim( $effective_prompt );
    }
}



        $payload = json_encode([
            "model"       => "gpt-4o",   // FIXED: use gpt-4o instead of gpt-4o-mini
            "temperature" => 0,
            "messages"    => [
                ["role" => "system", "content" => $sys],
                ["role" => "user",   "content" => $text]
            ]
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init("https://api.openai.com/v1/chat/completions");
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: Bearer " . $api_key
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S17/CURL_ERROR', curl_error($ch));
            }
            curl_close($ch);
            return $text;
        }
        curl_close($ch);

        $json = json_decode($resp, true);
        $out  = $json['choices'][0]['message']['content'] ?? '';
        $out  = trim($out);

        if (function_exists('reeid_debug_log')) {
            reeid_debug_log('S17/TRANSLATED', [
                'in'      => mb_substr($text, 0, 200),
                'out'     => mb_substr($out, 0, 200),
                'promptH' => md5($effective_prompt ?: ''),
            ]);
        }

        if ($out === '' || strcasecmp($out, $text) === 0) {
            return $text; // fallback only if empty or identical
        }

        // Strip common wrappers / fences and decode escaped unicode safely
        $out = preg_replace('/^```(?:[a-zA-Z0-9_-]+)?\s*|\s*```$/', '', $out); // strip fences
        $out = preg_replace('/^<style\b[^>]*>|<\/style>$/i', '', $out);        // remove surrounding style tags
        $decoded = @json_decode('"' . str_replace('"', '\"', $out) . '"');     // decode JSON-style escapes
        if ($decoded !== null) {
            $out = $decoded;
        }
        $out = trim($out);

        return $out;
    }
}

if (! function_exists('reeid_extract_text_lines')) {

    // --- BEGIN: Inline-formatting placeholder guards (for extractor path) ---
// We temporarily replace inline formatting tags with opaque placeholders so the
// extractor/translator sees one continuous sentence, then restore tags 1:1.
// Supported: <strong>, <b>, <em>, <i>, <u>, <mark>, <span style="font-weight:bold|...">.

if ( ! function_exists('reeid_inline_placehold_formatting') ) {
    function reeid_inline_placehold_formatting(string $html, array &$ph): string {
        // Normalize simple <span> bold/italic markers into strong/em buckets first
        // (keeps restore simple and deterministic).
        // Bold-like spans -> <strong>, italic-like -> <em>.
        $norm = preg_replace_callback(
            '#<span\b([^>]*)>(.*?)</span>#is',
            function($m){
                $attrs = $m[1]; $body = $m[2];
                $attr_lc = strtolower($attrs);
                $is_bold = (strpos($attr_lc,'font-weight') !== false && preg_match('#font-weight\s*:\s*(bold|[6-9]00)\b#i',$attr_lc))
                           || preg_match('#\bfont-weight\s*=\s*"(bold|[6-9]00)"#i',$attr_lc);
                $is_italic = (strpos($attr_lc,'font-style') !== false && preg_match('#font-style\s*:\s*italic\b#i',$attr_lc))
                           || preg_match('#\bfont-style\s*=\s*"italic"#i',$attr_lc);
                if ($is_bold && !$is_italic) return '<strong>'.$body.'</strong>';
                if ($is_italic && !$is_bold) return '<em>'.$body.'</em>';
                if ($is_bold && $is_italic)  return '<strong><em>'.$body.'</em></strong>';
                return '<span'.$attrs.'>'.$body.'</span>';
            },
            $html
        );

        $ph = []; // placeholder map: id => ['open'=>'<strong>', 'close'=>'</strong>']
        $id = 0;

        // List of tag pairs to protect (self-contained inline styles)
        $pairs = [
            ['open'=>'<strong>','close'=>'</strong>'],
            ['open'=>'<b>','close'=>'</b>'],
            ['open'=>'<em>','close'=>'</em>'],
            ['open'=>'<i>','close'=>'</i>'],
            ['open'=>'<u>','close'=>'</u>'],
            ['open'=>'<mark>','close'=>'</mark>'],
        ];

        $out = $norm;
        foreach ($pairs as $p) {
            // Greedy but safe inside a single block/paragraph; we wrap minimal content
            // and avoid crossing block boundaries.
            $pattern = '#'.preg_quote($p['open'],'#').'(.*?)'.preg_quote($p['close'],'#').'#is';
            $out = preg_replace_callback($pattern, function($m) use (&$ph, &$id, $p){
                $hid = ++$id;
                $openPh  = "[[PHO:$hid]]";
                $closePh = "[[PHC:$hid]]";
                $ph[$hid] = $p; // remember which tags to re-apply
                return $openPh.$m[1].$closePh;
            }, $out);
        }

        return $out;
    }
}

if ( ! function_exists('reeid_inline_restore_formatting') ) {
    function reeid_inline_restore_formatting(string $html, array $ph): string {
        // Replace close tokens first (safer when numbers overlap)
        foreach ($ph as $hid => $p) {
            $html = str_replace("[[PHC:$hid]]", $p['close'], $html);
        }
        foreach ($ph as $hid => $p) {
            $html = str_replace("[[PHO:$hid]]", $p['open'], $html);
        }
        return $html;
    }
}
// --- END: Inline-formatting placeholder guards ---

    function reeid_extract_text_lines(string $html): array
    {
        $lines = [];
        // Capture any text between tags (including spaces & NBSP), but exclude tags themselves
        


if (preg_match_all('/>(.*?)</us', $html, $m)) {
    foreach ($m[1] as $txt) {
        // $txt may include inner inline tags now
        $has_html = (strpos($txt, '<') !== false);

        // keep your left/core/right whitespace split (still useful)
        if (preg_match('/^([\x{00A0}\s]*)(.*?)([\x{00A0}\s]*)$/u', $txt, $mm)) {
            $left = $mm[1]; $core = $mm[2]; $right = $mm[3];
        } else {
            $left = ''; $core = $txt; $right = '';
        }

        // for “core” when HTML is present, feed the WHOLE inner HTML to the model
        $core_for_model = $has_html ? $txt : $core;

        if ($core_for_model !== '') {
            $lines[] = [
                'full'     => $txt,            // exact inner HTML between the outer tags
                'left'     => $left,
                'core'     => $core_for_model, // if has_html, this is HTML; else plain text
                'right'    => $right,
                'has_html' => $has_html,       // <-- NEW flag
            ];
        }
    }
}

        if (function_exists('reeid_debug_log')) {
            // Log a small sample of cores for sanity
            $sample = [];
            foreach (array_slice($lines, 0, 5) as $row) {
                $sample[] = $row['core'];
            }
            reeid_debug_log('S17/EXTRACTED_V2', ['count' => count($lines), 'sample' => $sample]);
        }
        return $lines;
    }
}

if (! function_exists('reeid_translate_lines')) {
    /**
     * Translate an array of text lines (now supports extractor rows with left/core/right).
     * Returns an array of translated CORES keyed by the same numeric indexes.
     *
     * @param array<int,mixed> $lines  Each item is either a string or ['full','left','core','right']
     * @param string $target_lang
     * @param string $tone
     * @param string $prompt
     * @return array<int,string>  Translated cores indexed to input
     */
    function reeid_translate_lines(array $lines, string $target_lang, string $tone = 'Neutral', string $prompt = ''): array
    {
        if (empty($lines)) {
            return $lines;
        }

        

        $out = [];
        foreach ($lines as $i => $line) {
            $core = is_array($line) ? (string)$line['core'] : (string)$line;
            // Pass RAW $prompt; merging happens inside reeid_openai_translate_single()
            $out[$i] = reeid_openai_translate_single($core, $target_lang, $tone, $prompt);
        }

        if (function_exists('reeid_debug_log')) {
            reeid_debug_log('S17/LINES/Done_V2', [
                'count' => count($lines),
                'lang'  => $target_lang,
                'tone'  => $tone,
            ]);
        }

        return $out;
    }
}

if (! function_exists('reeid_reinject_lines')) {
    /**
     * Reinject translated cores back into the original HTML.
     * $en comes from extractor: array of rows with 'full','left','core','right'
     * $ar are translated cores aligned by index.
     */
    function reeid_reinject_lines(string $html, array $en, array $ar, string $target_lang): string
    {
        // sanitize a model-returned snippet: remove code fences/styles, decode \uXXXX
        $sanitize = function (string $s): string {
            $s = trim($s);
            if (preg_match('/^```(?:[a-zA-Z0-9_-]+)?\s*(.*)\s*```$/s', $s, $m)) {
                $s = $m[1];
            }
            if (preg_match('/^<style\b[^>]*>(.*)<\/style>$/is', $s, $m)) {
                $s = $m[1];
            }
            $maybe = @json_decode('"' . str_replace('"', '\"', $s) . '"');
            if ($maybe !== null) $s = $maybe;
            return trim($s);
        };

        // IMPORTANT:
        // We must replace the exact original "full" text between tags,
        // NOT a whitespace-normalized version, so that glue spaces inside inline tags survive.
        foreach ($en as $i => $row) {
    if (!isset($ar[$i])) continue;

    // Sanitize model output
    $core_tr = $sanitize((string)$ar[$i]);

    if (is_array($row) && isset($row['full'], $row['left'], $row['core'], $row['right'])) {
        if (!empty($row['has_html'])) {
            // Row contained inline HTML: model returned translated HTML for the whole inner chunk.
            $translated_full = $core_tr;
        } else {
            // No HTML inside: keep original edge whitespace
            $translated_full = (string)$row['left'] . $core_tr . (string)$row['right'];
        }

        // Replace the exact inner content once
        $pattern = '/>' . preg_quote($row['full'], '/') . '</u';
        $html = preg_replace_callback(
            $pattern,
            function () use ($translated_full) { return '>' . $translated_full . '<'; },
            $html,
            1
        );
    } else {
        // legacy fallback (unchanged)
        $orig = (string)$row;
        $pattern = '/>' . preg_quote($orig, '/') . '</u';
        $html = preg_replace_callback(
            $pattern,
            function () use ($core_tr) { return '>' . $core_tr . '<'; },
            $html,
            1
        );
    }
}


        // Wrap RTL languages
        $rtl_langs = ['ar', 'he', 'fa', 'ur'];
        if (in_array(strtolower($target_lang), $rtl_langs, true)) {
            $lang_attr = function_exists('esc_attr') ? esc_attr($target_lang) : htmlspecialchars($target_lang, ENT_QUOTES);
            $html = "<div dir=\"rtl\" lang=\"" . $lang_attr . "\">\n" . $html . "\n</div>";
        }

        if (function_exists('reeid_debug_log')) {
            reeid_debug_log('S17/REINJECT_SAFE_V2', ['input' => count($en), 'output' => count($ar)]);
        }

        return $html;
    }
}

if (! function_exists('reeid_gutenberg_classic_translate_via_extractor')) {
    
    /**
 * Respects merged prompt: Global "Custom Instructions (PRO)" + per-request $prompt.
 * Adds inline-formatting placeholder guard so <strong>/<em>/<b>/<i>/<u>/<mark> (and bold/italic spans)
 * do not split sentences during extraction/translation.
 */
if ( ! function_exists('reeid_gutenberg_classic_translate_via_extractor') ) {
    function reeid_gutenberg_classic_translate_via_extractor(string $html, string $target_lang, string $tone = 'Neutral', string $prompt = ''): string
    {
        // ------- 0) Build effective prompt (global + per-request) -------
        $global_prompt = '';
        foreach (['reeid_custom_instructions', 'reeid_custom_prompt', 'reeid_pro_custom_instructions'] as $opt_key) {
            $val = get_option($opt_key, '');
            if (is_string($val) && trim($val) !== '') { $global_prompt = $val; break; }
        }
        if (! function_exists('wp_kses_post')) {
            require_once ABSPATH . 'wp-includes/kses.php';
        }
        $parts = [];
        if (is_string($global_prompt) && ($g = trim(wp_kses_post($global_prompt))) !== '') $parts[] = $g;
        if (is_string($prompt)        && ($p = trim(wp_kses_post($prompt)))        !== '') $parts[] = $p;
        if (!empty($parts)) $parts = array_values(array_unique($parts));
        $effective_prompt = implode(' ', $parts);

        // ------- 1) Ensure extractor helpers exist -------
        if (
            ! function_exists('reeid_extract_text_lines') ||
            ! function_exists('reeid_translate_lines') ||
            ! function_exists('reeid_reinject_lines')
        ) {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S17/HELPERS_MISSING', [
                    'extract'   => function_exists('reeid_extract_text_lines'),
                    'translate' => function_exists('reeid_translate_lines'),
                    'reinject'  => function_exists('reeid_reinject_lines'),
                ]);
            }
            return $html;
        }

        // ------- 2) Pre: inline-formatting placeholders (lossless) -------
        $ph_map = [];
        $did_ph = false;
        if (function_exists('reeid_inline_placehold_formatting')) {
            $html   = reeid_inline_placehold_formatting($html, $ph_map);
            $did_ph = !empty($ph_map);
        }

        // ------- 3) Extract -------
        $en_lines = reeid_extract_text_lines($html);
        if (empty($en_lines)) {
            if ($did_ph && function_exists('reeid_inline_restore_formatting')) {
                $html = reeid_inline_restore_formatting($html, $ph_map);
            }
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S17/NONE_EXTRACTED', null);
            }
            return $html;
        }

        // ------- 4) Translate with effective prompt -------
        $tr_lines = reeid_translate_lines($en_lines, $target_lang, $tone, $effective_prompt);

        // ------- 5) Reinject -------
        $out = reeid_reinject_lines($html, $en_lines, $tr_lines, $target_lang);

        // ------- 6) Post: restore inline formatting if we placeholderized -------
        if ($did_ph && function_exists('reeid_inline_restore_formatting')) {
            $out = reeid_inline_restore_formatting(is_string($out) && $out !== '' ? $out : $html, $ph_map);
        }

        if (function_exists('reeid_debug_log')) {
            reeid_debug_log('S17/COMPLETE', [
                'lang'       => $target_lang,
                'orig'       => count($en_lines),
                'translated' => is_array($tr_lines) ? count($tr_lines) : 0,
                'promptH'    => md5($effective_prompt ?: ''),
            ]);
        }

        return is_string($out) && $out !== '' ? $out : $html;
    }
}}


/* ===========================================================
 * HELPER: Translate a single line via OpenAI
 * Used by Section 16.0 extractor
 * =========================================================== */
if (! function_exists('reeid_openai_translate_single')) {
    function reeid_openai_translate_single(string $text, string $target_lang, string $tone = 'Neutral', string $prompt = ''): string
    {
        $apiKey = get_option('reeid_openai_api_key', '');
        if (empty($apiKey)) {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S16.0/HELPER no_api_key', null);
            }
            return $text; // fallback: return source
        }

// Ensure we build the same system prompt used by the main path
$eff = '';
if (is_string($prompt) && ($p = trim(wp_kses_post($prompt))) !== '') { $eff = $p; }
if (function_exists('reeid_get_combined_prompt')) {
    $sys = reeid_get_combined_prompt(0, $target_lang, (string)$eff);
} else {
    $sys = "You are a professional translator. Translate the source text into the target language, preserving HTML, placeholders and the original tone. Preserve brand names and placeholders unless instructed otherwise. Produce natural, idiomatic output.";
    if ($eff !== '') { $sys .= ' ' . $eff; }
}
// (temp) note sys length for sanity
@file_put_contents('/tmp/reeid_call_debug.log', date('c')." helper_sys_len=".strlen((string)$sys).PHP_EOL, FILE_APPEND);        $payload = json_encode([
            "model" => get_option('reeid_openai_model', 'gpt-4o-mini'),
           // ensure $sys is prepared above (see replacement #1); then:
"messages" => [
    [
        "role" => "system",
        "content" => $sys
    ],
    [
        "role" => "user",
        "content" => $text
    ]
]

        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init("https://api.openai.com/v1/chat/completions");
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "Authorization: " . "Bearer " . $apiKey
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 30,
        ]);

        $resp = curl_exec($ch);
        if ($resp === false) {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S16.0/HELPER curl_error', curl_error($ch));
            }
            curl_close($ch);
            return $text;
        }
        curl_close($ch);

        $json = json_decode($resp, true);
        $translated = $json['choices'][0]['message']['content'] ?? '';

        if (trim($translated) === '') {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S16.0/HELPER empty_translation', $resp);
            }
            return $text;
        }

        return $translated;
    }
}


/*==============================================================================
  SECTION 18: TRANSLATION ENGINE (Hybrid Batch + Mini‑Batch Fallback + Debug)
==============================================================================*/

/**
 * Minimal JSON sanitizer: strip ASCII control chars except \t, \n, \r
 */
if (! function_exists('reeid_json_sanitize_controls_v2')) {
    function reeid_json_sanitize_controls_v2(string $text, array &$stats = []): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);
        if ($clean !== $text) $stats['removed_controls'] = true;
        return $clean;
    }
}

/* === Helper: detect & neutralize engine "INVALID LANGUAGE PAIR" responses === */
if (! function_exists('reeid_harden_invalid_lang_pair')) {
    /**
     * If $val contains an engine error like "INVALID LANGUAGE PAIR", clear it and log.
     * Returns true if the value was considered invalid and cleared.
     *
     * Usage: reeid_harden_invalid_lang_pair( $translated_var );
     */
    function reeid_harden_invalid_lang_pair(&$val): bool
    {
        if (! is_string($val)) return false;
        if (stripos($val, 'INVALID LANGUAGE PAIR') !== false) {
            // log for diagnosis (non-fatal)
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S18/INVALID_LANGUAGE_PAIR', ['original' => $val]);
            } else {
                error_log('REEID: INVALID LANGUAGE PAIR detected and blocked from being saved.');
            }
            // neutralize — do not write this into SEO/meta
            $val = '';
            return true;
        }
        return false;
    }
}


/**
 * Helper: looks like a shortcode-only chunk (e.g., [contact_form id="1"])
 */
if (! function_exists('reeid_is_shortcode_like')) {
    function reeid_is_shortcode_like(string $s): bool
    {
        return (bool) preg_match('/^\s*\[[^\[\]]+\]\s*$/', $s);
    }
}

/**
 * Internal helper: determine if an attribute key/value is definitely NOT text content
 * and should not be sent to translation (className, style, colors, urls, ids, etc.).
 */
if (! function_exists('reeid_gutenberg_is_non_text_attr')) {
    function reeid_gutenberg_is_non_text_attr(string $attr_key, $attr_val, string $full_path = ''): bool
    {
        $k = strtolower($attr_key);

        // Skip high-level design/meta keys wholesale
        static $skip_prefixes = [
            'style',
            'color',
            'background',
            'border',
            'epcustom',
            'epanimation',
            'layout',
            'spacing',
        ];
        foreach ($skip_prefixes as $prefix) {
            if (strpos($k, $prefix) === 0) return true;
        }

        // Known non-text attribute keys to skip
        static $skip_keys = [
            'classname',
            'anchor',
            'align',
            'backgroundcolor',
            'textcolor',
            'gradient',
            'layout',
            'id',
            'aria',
            'arialabel',
            'url',
            'href',
            'rel',
            'target',
            'name',
            'slug',
            'tagname',
            'tag',
            'reference',
            // design keys
            'width',
            'height',
            'minheight',
            'maxheight',
            'gap',
            'padding',
            'margin',
            'border',
            'radius',
            'opacity',
            // media/urls
            'mediaurl',
            'src',
            'srcset',
            'sizes',
        ];
        if (in_array($k, $skip_keys, true)) return true;

        // Skip anything under ".attrs.style." (Gutenberg stores design there)
        if ($full_path !== '' && stripos($full_path, '.attrs.style.') !== false) {
            return true;
        }

        // If value is not a string, not for translation
        if (! is_string($attr_val)) return true;

        $v = trim($attr_val);
        if ($v === '') return true;

        // Skip URLs, anchors, protocols
        if (preg_match('/^(https?:|mailto:|tel:|data:|\/\/|#)/i', $v)) return true;

        // Skip CSS color/gradient tokens (prevent rgba/hex/var loss)
        if (preg_match('/^(#[0-9a-f]{3,8})$/i', $v)) return true;               // hex colors
        if (preg_match('/^(rgb|rgba|hsl|hsla)\s*\(/i', $v)) return true;        // rgb/rgba/hsl
        if (stripos($v, 'linear-gradient(') !== false || stripos($v, 'radial-gradient(') !== false) return true;
        if (preg_match('/^var\(\s*[-a-z0-9_]+\s*\)$/i', $v)) return true;       // CSS var(--token)

        // Skip pure numbers or unit values (sizes)
        if (preg_match('/^\d+([.,]\d+)?(px|em|rem|vh|vw|%)?$/i', $v)) return true;

        // Otherwise, treat as textual
        return false;
    }
}

/* =============================================================================================================
    SECTION 19 : Gutenberg walker - collect translatable strings
  - Always collect innerHTML/innerContent (so visible text never gets skipped)
  - Collect only "textish" attrs (content/title/caption/label/text/value/description/alt/subtitle/placeholder)
  - Skip design/URL/non-text attributes and shortcodes
 ==============================================================================================================*/
if (! function_exists('reeid_gutenberg_walk_and_collect')) {
    function reeid_gutenberg_walk_and_collect(array $blocks, &$map = [], string $prefix = ''): array
    {
        // Attributes typically holding user-facing text
        static $textish_keys = [
            'content',
            'title',
            'caption',
            'label',
            'text',
            'value',
            'placeholder',
            'description',
            'alt',
            'subtitle',
        ];

        foreach ($blocks as $i => $block) {
            $key = $prefix . $i;

            // Attributes: collect whitelisted textish keys only
            if (isset($block['attrs']) && is_array($block['attrs'])) {
                foreach ($block['attrs'] as $attrk => $attrv) {
                    $path = $key . '.attrs.' . $attrk;

                    // Only consider textish keys and textual values; also skip any known non-text/URL/design
                    if (! in_array(strtolower((string)$attrk), $textish_keys, true)) {
                        continue;
                    }
                    if (reeid_gutenberg_is_non_text_attr((string)$attrk, $attrv, $path)) {
                        continue;
                    }
                    if (is_string($attrv)) {
                        $val = trim($attrv);
                        if ($val !== '' && ! reeid_is_shortcode_like($val)) {
                            $map[$path] = $attrv;
                        }
                    }
                }
            }

            // innerHTML: always collect if it contains visible text and is not a pure shortcode
            if (!empty($block['innerHTML']) && is_string($block['innerHTML'])) {
                $plain = trim(wp_strip_all_tags($block['innerHTML']));
                if ($plain !== '' && ! reeid_is_shortcode_like(trim($block['innerHTML']))) {
            
                    // (removed: special-case skip for Woo attributes table)
            
                    // Still skip obvious CSS/style blobs
                    if (preg_match('/[{}]|\\.eplus_styles|flex-basis|gap:|<style\b|\.[a-zA-Z0-9_-]+\s*\{/i', $block['innerHTML'])) {
                        // looks like CSS or style blob — do not collect
                    } else {
                        $map[$key . '.innerHTML'] = $block['innerHTML'];
                    }
                }
            }
            


            // innerContent pieces: collect each that has visible text and is not a shortcode
if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
    foreach ($block['innerContent'] as $ci => $segment) {
        if (is_string($segment)) {
            $seg_trim = trim($segment);
            if ($seg_trim === '') continue;
            if (reeid_is_shortcode_like($seg_trim)) continue;

            // 🚫 NEW: Skip Woo attributes table if present in this segment
            if (preg_match('/woocommerce-product-attributes|woocommerce-Tabs-panel--additional_information/i', $segment)) {
                continue;
            }

            $plain = trim(wp_strip_all_tags($segment));
            if ($plain === '') continue;

            // Skip CSS-like or style blobs (selectors/rules) that shouldn't be translated
            if (preg_match('/[{}]|\\.eplus_styles|flex-basis|gap:|;\\s*$/i', $segment)) {
                continue;
            }

            $map[$key . '.innerContent.' . $ci] = $segment;
        }
    }
}


            // innerBlocks recurse
            if (!empty($block['innerBlocks'])) {
                reeid_gutenberg_walk_and_collect($block['innerBlocks'], $map, $key . '.');
            }
        }
        return $map;
    }
}

/**
 * Gutenberg walker: replace translated strings (in-memory)
 */
if (! function_exists('reeid_gutenberg_walk_and_replace')) {
    function reeid_gutenberg_walk_and_replace(array $blocks, array $translated_map, string $prefix = ''): array
    {
        foreach ($blocks as $i => &$block) {
            $key = $prefix . $i;

            // Attributes
            if (isset($block['attrs']) && is_array($block['attrs'])) {
                foreach ($block['attrs'] as $attrk => &$attrv) {
                    $map_key = $key . '.attrs.' . $attrk;
                    if (isset($translated_map[$map_key]) && is_string($attrv)) {
                        $attrv = $translated_map[$map_key];
                    }
                }
            }

            // innerHTML
            if (!empty($block['innerHTML']) && is_string($block['innerHTML'])) {
                $map_key = $key . '.innerHTML';
                if (isset($translated_map[$map_key])) {
                    $block['innerHTML'] = $translated_map[$map_key];
                }
            }

            // innerContent pieces
            if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
                foreach ($block['innerContent'] as $ci => &$segment) {
                    $map_key = $key . '.innerContent.' . $ci;
                    if (isset($translated_map[$map_key]) && is_string($segment)) {
                        $segment = $translated_map[$map_key];
                    }
                }
            }

            // innerBlocks recurse
            if (!empty($block['innerBlocks'])) {
                $block['innerBlocks'] = reeid_gutenberg_walk_and_replace($block['innerBlocks'], $translated_map, $key . '.');
            }
        }
        return $blocks;
    }
}

/* ==========================================================================
 * HTML Text-Node–Only Translator for Gutenberg Segments (with chunking)
 * - Preserves ALL tags and attributes by translating only text nodes
 * - NOW preserves whitespace-only text nodes so words don't collapse
 * - Pins language pair (FROM → TO); keeps NBSP; guarded for old libxml/PCRE
 * ========================================================================== */
if (! function_exists('reeid_html_translate_textnodes_fragment')) {
    function reeid_html_translate_textnodes_fragment(string $html, string $source_lang, string $target_lang)
    {
        // Split out left/right whitespace so the model never sees it.
        // NOTE: \s does NOT match NBSP; include U+00A0 explicitly to preserve it.
        $split_ws = static function (string $s): array {
            if (preg_match('/^([\x{00A0}\s]*)(.*?)([\x{00A0}\s]*)$/u', $s, $m)) {
                return [$m[1], $m[2], $m[3]]; // [left, core, right]
            }
            return ['', $s, ''];
        };

        // ── Fast path: no tags → translate plain text with edge spaces preserved
        if (strpos($html, '<') === false || !preg_match('/<[^>]+>/', $html)) {
            [$left, $core, $right] = $split_ws($html);
            if ($core === '') return $html;
            $out = reeid_translate_html_with_openai($core, $source_lang, $target_lang, 'gutenberg', 'neutral');
            $translated = is_wp_error($out) ? $core : (string) $out;
            return $left . $translated . $right;
        }

        // ── DOM path: when there ARE tags in the fragment
        if (!class_exists('DOMDocument')) {
            [$left, $core, $right] = $split_ws($html);
            if ($core === '') return $html;
            $out = reeid_translate_html_with_openai($core, $source_lang, $target_lang, 'gutenberg', 'neutral');
            $translated = is_wp_error($out) ? $core : (string) $out;
            return $left . $translated . $right;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        // Ensure libxml does NOT discard insignificant whitespace
        $dom->preserveWhiteSpace = true;
        libxml_use_internal_errors(true);

        // Ensure UTF-8
        $html_utf8 = $html;
        if (!function_exists('mb_detect_encoding') || !mb_detect_encoding($html_utf8, 'UTF-8', true)) {
            if (function_exists('mb_convert_encoding')) {
                $html_utf8 = mb_convert_encoding($html_utf8, 'UTF-8', 'auto');
            }
        }

        // Guard libxml flags
        $flags = 0;
        if (defined('LIBXML_HTML_NOIMPLIED')) {
            $flags |= LIBXML_HTML_NOIMPLIED;
        }
        if (defined('LIBXML_HTML_NODEFDTD')) {
            $flags |= LIBXML_HTML_NODEFDTD;
        }

        $ok = $dom->loadHTML('<?xml encoding="utf-8" ?><div id="reeid-x-root">' . $html_utf8 . '</div>', $flags);
        libxml_clear_errors();
        if (!$ok) {
            return $html;
        }

        $xpath = new DOMXPath($dom);

        // IMPORTANT: select ALL text nodes (including whitespace-only), skip only script/style ancestors.
        $textNodes = $xpath->query('//div[@id="reeid-x-root"]//text()[not(ancestor::script) and not(ancestor::style)]');
        if (!$textNodes || $textNodes->length === 0) {
            $root = $dom->getElementById('reeid-x-root');
            if (!$root) return $html;
            $out = '';
            foreach ($root->childNodes as $child) {
                $out .= $dom->saveHTML($child);
            }
            return $out === '' ? $html : $out;
        }

        // Build map ONLY for nodes with non-empty core; remember whitespace for all.
        $map = [];      // key => core text (to translate)
        $ws  = [];      // key => [left, right]
        $raw_cache = []; // key => ORIGINAL raw nodeValue (for whitespace-only nodes)
        $idx = 0;

        foreach ($textNodes as $n) {
            /** @var DOMText $n */
            $raw = (string) $n->nodeValue;
            $raw_cache[(string)$idx] = $raw;

            [$left, $core, $right] = $split_ws($raw);
            $ws[(string)$idx] = [$left, $right];

            // Only enqueue for translation if there is some non-whitespace core
            if ($core !== '') {
                $map[(string)$idx] = $core;
            }
            $idx++;
        }

        // === Chunking: split into safe pieces for translation
        $chunks = !empty($map) && function_exists('reeid_chunk_translation_map')
            ? reeid_chunk_translation_map($map, 4000)
            : (!empty($map) ? [$map] : []);

        $translated_data = [];

        foreach ($chunks as $chunk) {
            // Skip completely empty chunk quickly (shouldn't happen)
            $has_content = false;
            foreach ($chunk as $v) {
                if ($v !== '') {
                    $has_content = true;
                    break;
                }
            }
            if (!$has_content) {
                $translated_data += $chunk;
                continue;
            }

            // Pin FROM→TO to avoid model drifting into other scripts/languages
            $prompt = "Translate each JSON value FROM {$source_lang} TO {$target_lang}. Keys MUST remain identical.\n"
                . "Translate ONLY the natural-language text. Do NOT modify punctuation, numbers, variables, or JSON structure.\n"
                . "Do not add or remove leading/trailing whitespace in any value.\n"
                . "Return STRICT JSON only.";

            $json_in = wp_json_encode($chunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $resp = reeid_translate_html_with_openai(
                $prompt . "\n" . $json_in,
                $source_lang,
                $target_lang,
                'gutenberg',
                'neutral'
            );

            if (is_wp_error($resp) || !is_string($resp) || !preg_match('/\{.*\}/s', $resp, $m)) {
                $translated_data += $chunk; // fallback: copy originals
                continue;
            }

            $json_clean = function_exists('reeid_json_sanitize_controls_v2')
                ? reeid_json_sanitize_controls_v2($m[0], $s = [])
                : $m[0];

            $data = function_exists('reeid_safe_json_decode')
                ? reeid_safe_json_decode($json_clean)
                : json_decode($json_clean, true);

            if (is_array($data)) {
                $translated_data += $data;
            } else {
                $translated_data += $chunk; // fallback
            }
        }

        // Put back into DOM:
        // - If node had a non-empty core: set left + TRANSLATED(core) + right
        // - If node was whitespace-only: re-set ORIGINAL raw to keep the glue
        $idx = 0;
        foreach ($textNodes as $n) {
            /** @var DOMText $n */
            $k = (string)$idx;

            $raw = $raw_cache[$k] ?? (string)$n->nodeValue;
            [$left, $core, $right] = $split_ws($raw);

            if ($core !== '') {
                $core_tr = isset($translated_data[$k]) && is_string($translated_data[$k])
                    ? (string)$translated_data[$k]
                    : $core;
                $n->nodeValue = $left . $core_tr . $right;
            } else {
                // whitespace-only node: re-assign original raw whitespace to prevent collapse
                $n->nodeValue = $raw;
            }

            $idx++;
        }

        // Extract innerHTML of wrapper
        $root = $dom->getElementById('reeid-x-root');
        if (!$root) return $html;
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out;
    }
}

/* ==========================================================================
 * NEW: Safe chunker for Gutenberg translation maps
 * - Splits long associative arrays into smaller pieces by token count
 * - Avoids single huge JSON payloads
 * ========================================================================== */
if (! function_exists('reeid_chunk_translation_map')) {
    function reeid_chunk_translation_map(array $map, int $max_bytes = 4000): array
    {
        $chunks = [];
        $cur    = [];
        $size   = 0;

        foreach ($map as $k => $v) {
            $entry = is_scalar($v) ? (string)$v : json_encode($v);
            $len   = strlen($entry);

            // if current chunk + new entry would overflow, start a new chunk
            if ($size + $len > $max_bytes && !empty($cur)) {
                $chunks[] = $cur;
                $cur      = [];
                $size     = 0;
            }

            $cur[$k] = $v;
            $size   += $len;
        }

        if (!empty($cur)) {
            $chunks[] = $cur;
        }
        return $chunks;
    }
}


/*==============================================================================
  SECTION 20 : WOO-COMMERCE HELPER: TRANSLATE PRODUCT
==============================================================================*/

if (! function_exists('reeid_translate_product')) {
    function reeid_translate_product(
        int $src_id,
        string $src_lang,
        string $dst_lang,
        string $tone,
        string $prompt,
        int $dst_id,
        string $publish_mode = 'publish'
    ) {
        if (! class_exists('WC_Product')) {
            return new WP_Error('no_wc', 'WooCommerce not active.');
        }

        $src_product = wc_get_product($src_id);
        if (! $src_product) {
            return new WP_Error('no_product', 'Source product missing.');
        }

        // === Translate title & slug ===
        $title_src = $src_product->get_name();
        $title_tr  = reeid_translate_html_with_openai($title_src, $src_lang, $dst_lang, 'classic', $tone);
        if (is_wp_error($title_tr) || empty($title_tr)) {
            $title_tr = $title_src;
        }

        // Always sanitize translated title into a slug
        $slug_tr = function_exists('reeid_sanitize_native_slug')
            ? reeid_sanitize_native_slug($title_tr)
            : sanitize_title($title_tr);

        // === Translate content ===
        $long_src  = $src_product->get_description();
        $long_tr   = reeid_translate_html_with_openai($long_src, $src_lang, $dst_lang, 'classic', $tone);

        $short_src = $src_product->get_short_description();
        $short_tr  = reeid_translate_html_with_openai($short_src, $src_lang, $dst_lang, 'classic', $tone);

        // === Update/create target product ===
        $update = [
            'ID'           => $dst_id,
            'post_title'   => $title_tr,
            'post_name'    => $slug_tr,
            // 'post_content' intentionally omitted to avoid overwriting main content
            'post_excerpt' => is_string($short_tr) ? $short_tr : $short_src,
            'post_status'  => $publish_mode === 'draft' ? 'draft' : 'publish',
            'post_type'    => 'product',
        ];

        // If you still want to *store* the translated long description in DB without touching post_content,
        // store it as postmeta instead:
        if (is_string($long_tr) && $long_tr !== '') {
            update_post_meta($dst_id, '_reeid_translated_description_' . $dst_lang, $long_tr);
        }


        $new_id = reeid_safe_wp_update_post($update, true);
        if (is_wp_error($new_id)) {
            return $new_id;
        }

        // === Copy non-text meta (price, stock etc.) ===
        $dst_product = wc_get_product($new_id);
        if ($dst_product) {
            $fields_to_copy = [
                'sku',
                'regular_price',
                'sale_price',
                'manage_stock',
                'stock_quantity',
                'stock_status',
                'weight',
                'length',
                'width',
                'height',
                'tax_class',
                'downloadable',
                'virtual'
            ];
            foreach ($fields_to_copy as $key) {
                $getter = "get_$key";
                $setter = "set_$key";
                if (method_exists($src_product, $getter) && method_exists($dst_product, $setter)) {
                    $dst_product->$setter($src_product->$getter());
                }
            }
            $dst_product->save();



 /* ---------------------------------------------------------------------------
 * 1) Translate & attach product attributes
 * --------------------------------------------------------------------------- */

            if (! function_exists('reeid_translate_product_attributes')) {
                /**
                 * Translate product attributes from $src_product and attach them to $dst_product.
                 *
                 * Behavior:
                 * - Does NOT create/modify global attribute taxonomies. Creates product-level
                 *   (non-taxonomy) attributes on the target product with translated label & option values.
                 * - Preserves visibility and variation flags.
                 *
                 * @param WC_Product $src_product
                 * @param WC_Product $dst_product
                 * @param string     $src_lang
                 * @param string     $dst_lang
                 * @param string     $tone
                 */


                function reeid_translate_product_attributes($src_product, $dst_product, $src_lang, $dst_lang, $tone = 'neutral')
                {
                    if (! class_exists('WC_Product')) {
                        return;
                    }

                    if (! $src_product || ! $dst_product) {
                        return;
                    }

                    $src_attrs = $src_product->get_attributes();
                    if (empty($src_attrs) || ! is_array($src_attrs)) {
                        return;
                    }

                    // Collect strings to translate in a single batch to minimize API calls.
                    // We'll build a map key -> original and later map translated results back.
                    $batch_map = [];
                    // Keep metadata of each attribute to reconstruct after translation.
                    $attrs_meta = [];

                    foreach ($src_attrs as $attr_key => $attr) {
                        // Normalize attribute info
                        if (is_object($attr) && method_exists($attr, 'get_options')) {
                            $label     = (string) $attr->get_name();
                            $options   = (array) $attr->get_options();
                            $visible   = (bool) $attr->get_visible();
                            $variation = (bool) $attr->get_variation();
                            $is_tax    = method_exists($attr, 'is_taxonomy') ? (bool) $attr->is_taxonomy() : false;
                            $tax_name  = method_exists($attr, 'get_name') ? $attr->get_name() : '';
                        } else {
                            // Legacy/array format
                            $label     = isset($attr['name']) ? (string) $attr['name'] : (string) $attr_key;
                            $options   = isset($attr['options']) ? (array) $attr['options'] : (isset($attr['value']) ? array_map('trim', explode('|', $attr['value'])) : []);
                            $visible   = ! empty($attr['visible']);
                            $variation = ! empty($attr['variation']);
                            $is_tax    = ! empty($attr['is_taxonomy']);
                            $tax_name  = isset($attr['taxonomy']) ? $attr['taxonomy'] : '';
                        }

                        // Prepare label key
                        $label_key = "attr:label:{$attr_key}";
                        $batch_map[$label_key] = $label;

                        // Prepare options keys
                        $opt_keys = [];
                        foreach ($options as $oi => $opt) {
                            $opt_key = "attr:opt:{$attr_key}:{$oi}";
                            $opt_value = (string) $opt;

                            // If taxonomy-based attribute, try to resolve term to human name
                            if ($is_tax && ! empty($tax_name)) {
                                $resolved = null;
                                if (is_numeric($opt_value)) {
                                    $t = get_term(intval($opt_value), $tax_name);
                                    if ($t && ! is_wp_error($t)) $resolved = $t->name;
                                }
                                if ($resolved === null) {
                                    $t = get_term_by('slug', $opt_value, $tax_name);
                                    if ($t && ! is_wp_error($t)) $resolved = $t->name;
                                }
                                if ($resolved !== null) {
                                    $opt_value = (string) $resolved;
                                }
                            }

                            $batch_map[$opt_key] = $opt_value;
                            $opt_keys[] = $opt_key;
                        }

                        $attrs_meta[$attr_key] = [
                            'label_key'  => $label_key,
                            'opt_keys'   => $opt_keys,
                            'visible'    => $visible,
                            'variation'  => $variation,
                        ];
                    }

                    // If nothing to translate, exit early
                    if (empty($batch_map)) {
                        return;
                    }

                    // Batch translate map -> tries to preserve HTML structure if present
                    $json_in = wp_json_encode($batch_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    $prompt = "Task: Translate the JSON VALUES to {$dst_lang}. Keep keys EXACTLY unchanged.\n"
                        . "- Preserve HTML tags/attributes if values contain HTML. Do NOT alter placeholders like %s, numbers, or punctuation.\n"
                        . "- Return STRICT JSON object only.";

                    $api_args = ['response_format' => ['type' => 'json_object']];

                    $out = reeid_translate_html_with_openai($prompt . "\n" . $json_in, $src_lang, $dst_lang, 'classic', $tone, $api_args);

                    $translated_map = [];
                    if (is_string($out)) {
                        if (preg_match('/\{.*\}/s', $out, $m)) $clean = $m[0];
                        else $clean = $out;
                        if (function_exists('reeid_json_sanitize_controls_v2')) {
                            $clean = reeid_json_sanitize_controls_v2($clean, []);
                        }
                        if (function_exists('reeid_safe_json_decode')) {
                            $decoded = reeid_safe_json_decode($clean);
                        } else {
                            $decoded = json_decode($clean, true);
                        }
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $translated_map = $decoded;
                        }
                    }

                    // Fallback: if decoding failed, translate item-by-item
                    if (empty($translated_map)) {
                        foreach ($batch_map as $k => $v) {
                            $tr = reeid_translate_html_with_openai($v, $src_lang, $dst_lang, 'classic', $tone);
                            $translated_map[$k] = (is_wp_error($tr) || empty($tr)) ? $v : (string) $tr;
                        }
                    }

                    // Reconstruct attributes for target product
                    $new_attrs = [];

                    foreach ($attrs_meta as $attr_key => $meta) {
                        $label_tr = isset($translated_map[$meta['label_key']]) ? (string) $translated_map[$meta['label_key']] : '';

                        $options_tr = [];
                        foreach ($meta['opt_keys'] as $ok) {
                            $val = isset($translated_map[$ok]) ? (string) $translated_map[$ok] : '';
                            if ($val !== '') $options_tr[] = $val;
                        }

                        // Build WC_Product_Attribute (product-level)
                        if (class_exists('WC_Product_Attribute')) {
                            $new_attr = new WC_Product_Attribute();
                            if (method_exists($new_attr, 'set_id')) {
                                $new_attr->set_id(0);
                            }
                            if (method_exists($new_attr, 'set_name')) {
                                // Name should be a string; use translated label or fallback to attr_key
                                $new_attr->set_name($label_tr !== '' ? $label_tr : $attr_key);
                            }
                            if (method_exists($new_attr, 'set_options')) {
                                $new_attr->set_options($options_tr);
                            } else {
                                $new_attr->options = $options_tr;
                            }
                            if (method_exists($new_attr, 'set_visible')) {
                                $new_attr->set_visible($meta['visible']);
                            } else {
                                $new_attr->visible = $meta['visible'];
                            }
                            if (method_exists($new_attr, 'set_variation')) {
                                $new_attr->set_variation($meta['variation']);
                            } else {
                                $new_attr->variation = $meta['variation'];
                            }
                            if (method_exists($new_attr, 'set_taxonomy')) {
                                $new_attr->set_taxonomy(false);
                            }

                            $new_attrs[] = $new_attr;
                        } else {
                            // Fallback array structure for older WC
                            $key = sanitize_title($label_tr !== '' ? $label_tr : $attr_key);
                            $new_attrs[$key] = [
                                'name'        => ($label_tr !== '' ? $label_tr : $attr_key),
                                'value'       => implode(' | ', $options_tr),
                                'is_visible'  => $meta['visible'] ? 1 : 0,
                                'is_variation' => $meta['variation'] ? 1 : 0,
                                'is_taxonomy' => 0,
                            ];
                        }
                    }

                    // Attach attributes and save
                    try {
                        if (! empty($new_attrs)) {
                            if (method_exists($dst_product, 'set_attributes')) {
                                $dst_product->set_attributes($new_attrs);
                            } else {
                                // Older Woo: write _product_attributes post meta
                                $post_id = method_exists($dst_product, 'get_id') ? $dst_product->get_id() : 0;
                                if ($post_id) {
                                    update_post_meta($post_id, '_product_attributes', $new_attrs);
                                }
                            }
                            if (method_exists($dst_product, 'save')) {
                                $dst_product->save();
                            }
                        }
                    } catch (Throwable $e) {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log('reeid_translate_product_attributes error: ' . $e->getMessage());
                        }
                    }
                }
            }


/* ---------------------------------------------------------------------------
 * 2) Translate WooCommerce static strings (gettext filter + caching)
 * --------------------------------------------------------------------------- */


            /**
             * Detect current target language code.
             * Supports Polylang (pll_current_language), WPML (ICL_LANGUAGE_CODE) and falls back to get_locale().
             *
             * Returns a short language code like 'de' or locale like 'en_US' fallback.
             *
             * @return string
             */
            if (! function_exists('reeid_detect_target_language')) {
                function reeid_detect_target_language(): string
                {
                    // Polylang
                    if (function_exists('pll_current_language')) {
                        $lang = pll_current_language();
                        if ($lang) return (string) $lang;
                    }

                    // WPML
                    if (defined('ICL_LANGUAGE_CODE')) {
                        return (string) ICL_LANGUAGE_CODE;
                    }

                    // Fallback to WP locale (en_US -> en)
                    $locale = get_locale() ?: '';
                    if ($locale === '') return '';
                    if (strpos($locale, '_') !== false) {
                        return substr($locale, 0, strpos($locale, '_'));
                    }
                    return (string) $locale;
                }
            }


            /**
             * Clear cached static translations for a language (helper).
             *
             * @param string $lang
             * @return void
             */
            if (! function_exists('reeid_clear_woo_string_cache')) {
                function reeid_clear_woo_string_cache($lang): void
                {
                    $opt_name = 'reeid_woo_strings_' . sanitize_key((string) $lang);
                    delete_option($opt_name);
                }
            }


            /**
             * Return an associative map original => translated for common Woo strings for $target_lang.
             * Caches translations in an option named 'reeid_woo_strings_<lang>'.
             *
             * NOTE: This function may call reeid_translate_html_with_openai(). The outer gettext filter
             * sets a recursion guard to avoid infinite loops.
             *
             * @param string $target_lang
             * @param bool   $force_refresh Force re-translation even if cached
             * @return array
             */
            if (! function_exists('reeid_get_translated_woocommerce_strings')) {
                function reeid_get_translated_woocommerce_strings($target_lang, $force_refresh = false): array
                {
                    $target_lang = (string) $target_lang;
                    if ($target_lang === '') return [];

                    $opt_name = 'reeid_woo_strings_' . sanitize_key($target_lang);
                    if (! $force_refresh) {
                        $cached = get_option($opt_name);
                        if (is_array($cached) && ! empty($cached)) {
                            return $cached;
                        }
                    }
                    // === HARD GUARD: never build translations on the frontend ===
                    // If cache is missing and this is not admin or AJAX, don't call OpenAI here.
                    // Return empty map so gettext falls back to original strings.
                    if (! is_admin() && ! wp_doing_ajax()) {
                        return [];
                    }

                    // List of strings to translate (extendable)
                    $strings = [
                        'Home',
                        'Description',
                        'Additional information',
                        'Reviews',
                        'Related products',
                        'You may also like&hellip;',
                        'SKU:',
                        'Category:',
                        'Tags:',
                        'Only %s left in stock - order soon.',
                        'Add to cart',
                        'Out of stock',
                        'In stock',
                        'View cart',
                        'Uncategorized',
                    ];

                    // Prepare input map
                    $input_map = [];
                    foreach ($strings as $s) $input_map[$s] = $s;

                    $json_in = wp_json_encode($input_map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    $prompt = "Task: Translate the JSON VALUES to {$target_lang}. Keep keys EXACTLY unchanged and return STRICT JSON object only.\n"
                        . "- Do not change formatting placeholders like %s, %d, HTML entities, or markup.\n"
                        . "- Preserve punctuation and trailing colons where present.\n"
                        . "- Return JSON only.";

                    // We expect the outer filter to have set $GLOBALS['reeid_in_gettext'] to avoid recursion.
                    // Call translator - this might trigger other WP functions, so ensure recursion guard is active where needed.
                    $out = reeid_translate_html_with_openai($prompt . "\n" . $json_in, 'en', $target_lang, 'classic', 'neutral', ['response_format' => ['type' => 'json_object']]);

                    $translations = [];
                    if (is_string($out)) {
                        if (preg_match('/\{.*\}/s', $out, $m)) {
                            $clean = $m[0];
                        } else {
                            $clean = $out;
                        }
                        if (function_exists('reeid_json_sanitize_controls_v2')) {
                            $clean = reeid_json_sanitize_controls_v2($clean, []);
                        }
                        if (function_exists('reeid_safe_json_decode')) {
                            $decoded = reeid_safe_json_decode($clean);
                        } else {
                            $decoded = json_decode($clean, true);
                        }
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $translations = $decoded;
                        }
                    }

                    // Fallback to one-by-one translations if batch failed
                    if (empty($translations)) {
                        foreach ($input_map as $k => $v) {
                            $tr = reeid_translate_html_with_openai($v, 'en', $target_lang, 'classic', 'neutral');
                            $translations[$k] = (is_wp_error($tr) || empty($tr)) ? $v : (string) $tr;
                        }
                    }

                    // Persist (non-autoload)
                    update_option($opt_name, $translations, false);

                    return $translations;
                }
            }

            /**
             * Normalize a candidate original string to attempt better matching.
             *
             * Returns array of candidate keys (most specific first).
             *
             * @param string $text
             * @return array
             */
            if (! function_exists('reeid_woo_normalize_candidates')) {
                function reeid_woo_normalize_candidates($text): array
                {
                    $cands = [];
                    $orig = (string) $text;
                    $cands[] = $orig;

                    $trim = trim($orig);
                    if ($trim !== $orig) $cands[] = $trim;

                    $decoded = html_entity_decode($trim, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($decoded !== $trim) $cands[] = $decoded;

                    // Remove trailing colon (common in "SKU:" / "Category:")
                    if (substr($trim, -1) === ':') {
                        $cands[] = rtrim($trim, ':');
                    }

                    // Lowercase variant may help in some cases (keep original priority)
                    $lower = mb_strtolower($trim);
                    if ($lower !== $trim) $cands[] = $lower;

                    // Unique and preserve order
                    $out = [];
                    foreach ($cands as $v) {
                        if ($v === '') continue;
                        if (! in_array($v, $out, true)) $out[] = $v;
                    }
                    return $out;
                }
            }


            /**
             * Main gettext filter that replaces selected WooCommerce strings with translations.
             *
             * Recursion guard: sets $GLOBALS['reeid_in_gettext'] while building/fetching translations so any
             * sub-calls into gettext do not re-enter the same filter and cause infinite recursion.
             *
             * @param string $translated Current translated text (by WP)
             * @param string $text       Original text
             * @param string $domain     Text domain
             * @return string
             */
            if (! function_exists('reeid_woocommerce_gettext_filter')) {
                function reeid_woocommerce_gettext_filter($translated, $text, $domain)
                {
                    // Only run on frontend product listing/details to avoid unintended site-wide replacements.
                    if (! (is_product() || is_shop() || is_product_category() || is_product_tag())) {
                        return $translated;
                    }

                    // Filter domains: we care about 'woocommerce' and 'default', but allow empty domain too.
                    if ($domain !== 'woocommerce' && $domain !== 'default' && $domain !== '') {
                        return $translated;
                    }

                    // Quick bailout
                    if ($text === '') return $translated;

                    // Avoid recursion: if already inside our gettext pipeline, do nothing and return current value.
                    if (! empty($GLOBALS['reeid_in_gettext'])) {
                        return $translated;
                    }

                    // Set guard while we compute translations (so any translator calls that use gettext don't re-enter)
                    $GLOBALS['reeid_in_gettext'] = true;

                    $target_lang = reeid_detect_target_language();
                    if ($target_lang === '') {
                        unset($GLOBALS['reeid_in_gettext']);
                        return $translated;
                    }

                    $map = reeid_get_translated_woocommerce_strings($target_lang);

                    // Try several candidate keys for robust matching
                    $candidates = reeid_woo_normalize_candidates($text);
                    foreach ($candidates as $cand) {
                        if (isset($map[$cand]) && $map[$cand] !== '') {
                            $out = $map[$cand];
                            unset($GLOBALS['reeid_in_gettext']);
                            return $out;
                        }
                    }

                    // Final attempt: exact match with trimmed original text
                    $trim = trim($text);
                    if (isset($map[$trim]) && $map[$trim] !== '') {
                        $out = $map[$trim];
                        unset($GLOBALS['reeid_in_gettext']);
                        return $out;
                    }

                    // Nothing matched — return original translated value
                    unset($GLOBALS['reeid_in_gettext']);
                    return $translated;
                }

                add_filter('gettext', 'reeid_woocommerce_gettext_filter', 20, 3);
            }


            /**
             * Also handle gettext_with_context (some Woo strings use context).
             *
             * Signature: ( $translated, $text, $context, $domain )
             */
            if (! function_exists('reeid_woocommerce_gettext_with_context_filter')) {
                function reeid_woocommerce_gettext_with_context_filter($translated, $text, $context, $domain)
                {
                    // Reuse the same logic but prefer context-aware mapping first (context appended)
                    if (empty($text)) return $translated;
                    if (! empty($GLOBALS['reeid_in_gettext'])) return $translated;

                    // Only run on frontend product contexts
                    if (! (is_product() || is_shop() || is_product_category() || is_product_tag())) {
                        return $translated;
                    }

                    if ($domain !== 'woocommerce' && $domain !== 'default' && $domain !== '') {
                        return $translated;
                    }

                    $GLOBALS['reeid_in_gettext'] = true;
                    $target_lang = reeid_detect_target_language();
                    if ($target_lang === '') {
                        unset($GLOBALS['reeid_in_gettext']);
                        return $translated;
                    }

                    $map = reeid_get_translated_woocommerce_strings($target_lang);

                    // prefer context-aware key: "text|context"
                    $ctx_key = $text . '|' . $context;
                    if (isset($map[$ctx_key]) && $map[$ctx_key] !== '') {
                        $out = $map[$ctx_key];
                        unset($GLOBALS['reeid_in_gettext']);
                        return $out;
                    }

                    // fallback to normal filter
                    $out = reeid_woocommerce_gettext_filter($translated, $text, $domain);

                    unset($GLOBALS['reeid_in_gettext']);
                    return $out;
                }

                add_filter('gettext_with_context', 'reeid_woocommerce_gettext_with_context_filter', 20, 4);
            }


            /* Optional: If you notice "Add to cart" is not translated by gettext in your theme, also hook specific Woo filters.
 * These filters are used by some themes/plugins to change button text.
 */
            if (! function_exists('reeid_translate_add_to_cart_button')) {
                function reeid_translate_add_to_cart_button($text)
                {
                    if (! (is_product() || is_shop())) return $text;
                    if (! empty($GLOBALS['reeid_in_gettext'])) return $text;

                    $GLOBALS['reeid_in_gettext'] = true;
                    $target_lang = reeid_detect_target_language();
                    if ($target_lang) {
                        $map = reeid_get_translated_woocommerce_strings($target_lang);
                        $cands = reeid_woo_normalize_candidates($text);
                        foreach ($cands as $c) {
                            if (isset($map[$c]) && $map[$c] !== '') {
                                $out = $map[$c];
                                unset($GLOBALS['reeid_in_gettext']);
                                return $out;
                            }
                        }
                    }
                    unset($GLOBALS['reeid_in_gettext']);
                    return $text;
                }
                add_filter('woocommerce_product_add_to_cart_text', 'reeid_translate_add_to_cart_button', 20);
                add_filter('woocommerce_product_single_add_to_cart_text', 'reeid_translate_add_to_cart_button', 20);
            }
        }

        return $new_id;
    }
}


/*------------------------------------------------------------------------------
  Custom serializer that PRESERVES ALL ATTRIBUTES
  We DO NOT call serialize_blocks() (which drops unknown attrs).
  Instead we output <!-- wp:block {"attrs":...} --> content <!-- /wp:block -->
  and rebuild content from innerContent/innerBlocks while applying translations.
-------------------------------------------------------------------------------*/
if (! function_exists('reeid_gutenberg_encode_attrs_json')) {
    function reeid_gutenberg_encode_attrs_json($attrs): string
    {
        if (empty($attrs) || !is_array($attrs)) return '';
        // Keep unicode/slashes; avoid escaping hyphens inside CSS var(--x)
        return wp_json_encode($attrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

if (! function_exists('reeid_gutenberg_serialize_one_block_preserve_attrs')) {
    function reeid_gutenberg_serialize_one_block_preserve_attrs(array $block, array $translated_map, string $path): string
    {
        // Freeform HTML block (no name) → just return (with translated innerHTML if any)
        if (empty($block['blockName'])) {
            $val = $block['innerHTML'] ?? '';
            $key = $path . '.innerHTML';
            if (isset($translated_map[$key])) {
                $val = $translated_map[$key];
            }
            return (string) $val;
        }

        $name  = (string) $block['blockName'];
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
        $attrs_json = reeid_gutenberg_encode_attrs_json($attrs);
        $open = '<!-- wp:' . $name . ($attrs_json !== '' ? ' ' . $attrs_json : '') . ' -->';

        // Rebuild content
        $html = '';
        $content = $block['innerContent'] ?? null;
        $children = $block['innerBlocks'] ?? [];
        if (is_array($content) && !empty($content)) {
            $child_idx = 0;
            foreach ($content as $ci => $piece) {
                if ($piece === null) {
                    // slot for a child
                    $child_path = $path . '.innerBlocks.' . $child_idx; // not used in map; map uses own indexes
                    $html .= reeid_gutenberg_serialize_one_block_preserve_attrs($children[$child_idx], $translated_map, $path . '.' . $child_idx);
                    $child_idx++;
                } elseif (is_string($piece)) {
                    $k = $path . '.innerContent.' . $ci;
                    $html .= isset($translated_map[$k]) ? $translated_map[$k] : $piece;
                }
            }
        } else {
            // Fallback: use innerHTML (possibly translated)
            $k = $path . '.innerHTML';
            $val = $block['innerHTML'] ?? '';
            if (isset($translated_map[$k])) {
                $val = $translated_map[$k];
            }
            $html .= (string) $val;
            // And append children if present
            if (!empty($block['innerBlocks'])) {
                foreach ($block['innerBlocks'] as $ci => $child) {
                    $html .= reeid_gutenberg_serialize_one_block_preserve_attrs($child, $translated_map, $path . '.' . $ci);
                }
            }
        }

        $close = '<!-- /wp:' . $name . ' -->';

        return $open . $html . $close;
    }
}

if (! function_exists('reeid_gutenberg_serialize_preserve_attrs')) {
    function reeid_gutenberg_serialize_preserve_attrs(array $blocks, array $translated_map): string
    {
        $out = '';
        foreach ($blocks as $i => $block) {
            $out .= reeid_gutenberg_serialize_one_block_preserve_attrs($block, $translated_map, (string)$i);
        }
        return $out;
    }
}

/**
 * Main translation engine with slug generation
 * - SINGLE translation: metabox (or auto) for source; target is func arg.
 * - BULK translation: admin options/ctx drive source/target.
 * - Uses reeid_lang_normalize() (Italian => it-it, Burmese => my-mm).
 */
if (! function_exists('reeid_translate_via_openai_with_slug')) {
    function reeid_translate_via_openai_with_slug(string $content, string $target_lang, array $ctx = []): array
    {
        $log_file = WP_CONTENT_DIR . '/uploads/reeid-debug.log';
        $post_id  = intval($ctx['post_id'] ?? 0);
        $post     = $post_id ? get_post($post_id) : null;

        // Editor + mode
        $editor = $post ? reeid_detect_editor_type($post) : 'classic';
        $mode   = isset($ctx['mode']) ? strtolower((string)$ctx['mode']) : (isset($_REQUEST['reeid_bulk']) ? 'bulk' : 'single');
        if ($mode !== 'bulk') {
            $mode = 'single';
        }

        // Read metabox/admin options
        $meta_src = $post ? (string) get_post_meta($post_id, '_reeid_source_lang', true) : '';
        $meta_tgt = $post ? (string) get_post_meta($post_id, '_reeid_target_lang', true) : '';

        $opt_src  = (string) get_option('reeid_default_source_lang', 'auto'); // bulk only
        $opt_tgt  = (string) get_option('reeid_default_target_lang', '');     // bulk only

        // Detection helper
        $detect_lang = static function (string $html): string {
            $probe = trim(wp_strip_all_tags($html));
            $det   = function_exists('reeid_detect_language') ? (string) reeid_detect_language($probe) : '';
            if ($det === '' || strlen($det) > 16) {
                $det = 'en';
            }
            return reeid_lang_normalize($det);
        };

        // Resolve prefs by MODE (no admin override in SINGLE mode)
        if ($mode === 'bulk') {
            $source_pref = $opt_src !== '' ? strtolower($opt_src) : 'auto';
            $target_pref = isset($ctx['target_lang']) && $ctx['target_lang'] !== '' ? strtolower((string)$ctx['target_lang'])
                : ($opt_tgt !== '' ? strtolower($opt_tgt) : strtolower((string)$target_lang));
        } else {
            $source_pref = $meta_src !== '' ? strtolower($meta_src) : 'auto';
            $target_pref = $target_lang !== '' ? strtolower($target_lang)
                : ($meta_tgt !== '' ? strtolower($meta_tgt) : '');
        }

        // Normalize to your supported keys (this is where 'it' -> 'it-it', 'my' -> 'my-mm' happens)
        $source_pref = reeid_lang_normalize($source_pref);
        $target_pref = reeid_lang_normalize($target_pref);

        // Final effective languages
        $source_lang = ($source_pref === '' || $source_pref === 'auto') ? $detect_lang($content) : $source_pref;
        $target_lang = $target_pref !== '' ? $target_pref : 'en'; // last resort

        file_put_contents($log_file, "[" . gmdate('c') . "] S17 ENTRY mode={$mode} editor={$editor} post_id={$post_id} src={$source_lang} tgt={$target_lang}\n", FILE_APPEND);

        // No-op if src == tgt
        if ($source_lang === $target_lang) {
            $title = $post ? $post->post_title : 'translation';
            $slug  = reeid_sanitize_native_slug(mb_substr(wp_strip_all_tags($title), 0, 60));
            return ['ok' => true, 'content' => $content, 'slug' => $slug];
        }

        $translated = '';
        $slug       = '';

        try {
            if ($editor === 'gutenberg') {
                // === Collect
                $blocks = parse_blocks($content);
                $map    = reeid_gutenberg_walk_and_collect($blocks);
                file_put_contents($log_file, "[" . gmdate('c') . "] S17/GUTENBERG collected " . count($map) . " texts\n", FILE_APPEND);

                // === Partition
                $map_plain = [];
                $map_html  = [];
                foreach ($map as $k => $v) {
                    if (is_string($v) && strpos($v, '<') !== false && preg_match('/<[^>]+>/', $v)) {
                        $map_html[$k] = $v;
                    } else {
                        $map_plain[$k] = $v;
                    }
                }

                $translated_map = [];

                // === Translate plain-text (whitespace-preserving)
                if (!empty($map_plain)) {
                    $ws_plain       = [];
                    $map_plain_core = [];

                    foreach ($map_plain as $k => $raw) {
                        $raw = (string) $raw;
                        if (preg_match('/^([\x{00A0}\s]*)(.*?)([\x{00A0}\s]*)$/u', $raw, $m)) {
                            $left = $m[1];
                            $core = $m[2];
                            $right = $m[3];
                        } else {
                            $left = $right = '';
                            $core = $raw;
                        }
                        $ws_plain[$k]       = [$left, $right];
                        $map_plain_core[$k] = $core;
                    }

                    $plain_chunks = function_exists('reeid_chunk_translation_map')
                        ? reeid_chunk_translation_map($map_plain_core, 4000)
                        : [$map_plain_core];

                    foreach ($plain_chunks as $chunk) {
                        $has_content = false;
                        foreach ($chunk as $v) {
                            if ($v !== '') {
                                $has_content = true;
                                break;
                            }
                        }
                        if (!$has_content) {
                            foreach ($chunk as $ck => $core_in) {
                                [$l, $r] = $ws_plain[$ck] ?? ['', ''];
                                $translated_map[$ck] = $l . $core_in . $r;
                            }
                            continue;
                        }

                        $prompt  = "Task: Translate all JSON values FROM {$source_lang} TO {$target_lang}. Keep keys EXACTLY unchanged.\n"
                            . "Translate ONLY the values. Do not add/remove keys or change JSON.\n"
                            . "Do not add or remove leading/trailing whitespace in any value.\n"
                            . "Return STRICT JSON only (no comments/markdown).";

                        $json_in = wp_json_encode($chunk, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                        $json_out = reeid_translate_html_with_openai(
                            $prompt . "\n" . $json_in,
                            $source_lang,
                            $target_lang,
                            'gutenberg',
                            'neutral'
                        );

                        $decoded = null;
                        if (is_string($json_out) && preg_match('/\{.*\}/s', $json_out, $m)) {
                            $clean = function_exists('reeid_json_sanitize_controls_v2')
                                ? reeid_json_sanitize_controls_v2($m[0], $s = [])
                                : $m[0];
                            $decoded = function_exists('reeid_safe_json_decode')
                                ? reeid_safe_json_decode($clean)
                                : json_decode($clean, true);
                        }

                        if (is_array($decoded)) {
                            foreach ($chunk as $ck => $core_in) {
                                $core_out = array_key_exists($ck, $decoded) && is_string($decoded[$ck])
                                    ? (string) $decoded[$ck]
                                    : $core_in;
                                [$l, $r]  = $ws_plain[$ck] ?? ['', ''];
                                $translated_map[$ck] = $l . $core_out . $r;
                            }
                        } else {
                            foreach ($chunk as $ck => $core_in) {
                                [$l, $r] = $ws_plain[$ck] ?? ['', ''];
                                $translated_map[$ck] = $l . $core_in . $r;
                            }
                        }
                    }

                    file_put_contents($log_file, "[" . gmdate('c') . "] S17/GUTENBERG plain map translated (ws-preserved)\n", FILE_APPEND);
                }

                // === Translate HTML via text-node path
                if (!empty($map_html)) {
                    foreach ($map_html as $k => $frag) {
                        $tr = function_exists('reeid_html_translate_textnodes_fragment')
                            ? reeid_html_translate_textnodes_fragment($frag, $source_lang, $target_lang)
                            : $frag;
                        $translated_map[$k] = is_string($tr) && $tr !== '' ? $tr : $frag;
                    }
                    file_put_contents($log_file, "[" . gmdate('c') . "] S17/GUTENBERG HTML segments translated via textnodes-only\n", FILE_APPEND);
                }

                // === Rebuild (preserve ALL attrs)
                $translated = reeid_gutenberg_serialize_preserve_attrs(
                    reeid_gutenberg_walk_and_replace($blocks, $translated_map),
                    $translated_map
                );
            } elseif ($editor === 'elementor') {
                // Elementor: use SAME resolved languages (no admin override in single mode)
                $out = reeid_translate_html_with_openai($content, $source_lang, $target_lang, 'elementor', 'neutral');
                if (is_wp_error($out)) $out = $content;
                $translated = (string) $out;
            } else {
                // Classic
                $out = reeid_translate_html_with_openai($content, $source_lang, $target_lang, 'classic', 'neutral');
                if (is_wp_error($out)) $out = $content;
                $out = preg_replace('/<!DOCTYPE.*?<body[^>]*>/is', '', $out);
                $out = preg_replace('/<\/body>\s*<\/html>\s*$/is', '', $out);
                $translated = trim($out);
            }
        } catch (Throwable $e) {
            file_put_contents($log_file, "[" . gmdate('c') . "] S17 ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
            return ['ok' => false, 'error' => 'engine_failed', 'content' => $content, 'slug' => ''];
        }

        // === Slug generation
        $title_src = $post ? $post->post_title : 'translation';
        $slug = '';
        if (function_exists('reeid__s15_slug_from_api')) {
            $res = reeid__s15_slug_from_api($target_lang, $title_src, $title_src, 'native');
            if (!empty($res['ok']) && !empty($res['preferred'])) $slug = $res['preferred'];
        }
        if (empty($slug)) {
            $title_tr  = reeid_translate_html_with_openai($title_src, $source_lang, $target_lang, $editor, 'neutral');
            if (is_wp_error($title_tr) || empty($title_tr)) $title_tr = $title_src;
            if (function_exists('reeid_sanitize_native_slug')) {
                $slug = reeid_sanitize_native_slug(mb_substr(wp_strip_all_tags((string)$title_tr), 0, 60));
            } else {
                $slug = sanitize_title(mb_substr(wp_strip_all_tags((string)$title_tr), 0, 60));
            }
        }

        file_put_contents($log_file, "[" . gmdate('c') . "] S17 SLUG={$slug}, len=" . mb_strlen($translated) . "\n", FILE_APPEND);

        return ['ok' => true, 'content' => $translated, 'slug' => $slug];
    }
}


if (! function_exists('reeid_elementor_commit_post')) {
    /**
     * Save Elementor JSON consistently and regenerate CSS so frontend reflects new widgets immediately.
     *
     * @param int          $post_id
     * @param array|string $elementor_data Array (Elementor tree) or JSON string.
     */
    function reeid_elementor_commit_post(int $post_id, $elementor_data): void
    {
        // 1) Store as JSON string (Elementor expects JSON; avoid serialized arrays).
        $json = (is_array($elementor_data) || is_object($elementor_data))
            ? wp_json_encode($elementor_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) $elementor_data;

        update_post_meta($post_id, '_elementor_data', wp_slash($json));
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta(
            $post_id,
            '_elementor_data_version',
            defined('ELEMENTOR_VERSION') ? (string) ELEMENTOR_VERSION : '3.x'
        );

        // Ensure correct template type.
        $ptype = get_post_type($post_id);
        $tmpl  = ($ptype === 'page') ? 'wp-page' : 'wp-post';
        update_post_meta($post_id, '_elementor_template_type', $tmpl);

        // Drop old CSS meta if it exists (legacy installations).
        delete_post_meta($post_id, '_elementor_css');

        // 2) Try to use Elementor's document save to trigger internal rebuilds.
        try {
            if (class_exists('\Elementor\Plugin')) {
                $doc = \Elementor\Plugin::$instance->documents->get($post_id);
                if ($doc) {
                    $arr = json_decode($json, true);
                    if (is_array($arr)) {
                        // Save minimal payload; this triggers internal CSS regeneration pathways.
                        $doc->save([
                            'elements'       => $arr,
                            'settings'       => (array) $doc->get_settings(),
                            'page_settings'  => (array) $doc->get_settings('page'),
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // If Elementor internals change, fall back to manual CSS generation.
        }

        // 3) Regenerate post CSS (covers cases where document save path did not fire).
        try {
            if (class_exists('\Elementor\Core\Files\CSS\Post')) {
                $css = new \Elementor\Core\Files\CSS\Post($post_id);
                $css->clear_cache();
                $css->update();
            } elseif (class_exists('\Elementor\Post_CSS_File')) {
                // Back-compat older Elementor.
                $css = new \Elementor\Post_CSS_File($post_id);
                $css->clear_cache();
                $css->update();
            }
            if (class_exists('\Elementor\Plugin')) {
                if (isset(\Elementor\Plugin::$instance->files_manager)) {
                    \Elementor\Plugin::$instance->files_manager->clear_cache();
                }
                if (method_exists(\Elementor\Plugin::$instance, 'clear_cache')) {
                    \Elementor\Plugin::$instance->clear_cache();
                }
            }
        } catch (\Throwable $e) {
            // Never hard-fail if Elementor internals change.
        }

        // 4) Clear WP object cache for this post.
        clean_post_cache($post_id);
    }
}


/* ===========================================================
 * UNIVERSAL SAFETY NET (optional but recommended)
 * Add a save_post hook that ensures Elementor data has version
 * and CSS is regenerated whenever a translated post is updated.
 * Works for single and bulk paths without touching Gutenberg.
 * =========================================================== */
add_action('save_post', function ($post_id, $post) {
    // Quick exits
    if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
    }
    if (! $post instanceof WP_Post) {
        return;
    }

    // Only run for posts that have Elementor builder mode
    $edit_mode = get_post_meta($post_id, '_elementor_edit_mode', true);
    if ($edit_mode !== 'builder') {
        return;
    }

    // If this is one of our translations (or any builder post), ensure version + CSS
    $has_elem = get_post_meta($post_id, '_elementor_data', true);
    if (empty($has_elem)) {
        return;
    }

    // Ensure version meta is present
    $ver = get_post_meta($post_id, '_elementor_data_version', true);
    if (! $ver) {
        update_post_meta(
            $post_id,
            '_elementor_data_version',
            defined('ELEMENTOR_VERSION') ? (string) ELEMENTOR_VERSION : '3.x'
        );
    }

    // Rebuild CSS (safe try)
    try {
        if (class_exists('\Elementor\Core\Files\CSS\Post')) {
            $css = new \Elementor\Core\Files\CSS\Post($post_id);
            $css->clear_cache();
            $css->update();
        } elseif (class_exists('\Elementor\Post_CSS_File')) {
            $css = new \Elementor\Post_CSS_File($post_id);
            $css->clear_cache();
            $css->update();
        }
        if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) {
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        }
    } catch (\Throwable $e) {
        // swallow
    }
}, 10, 2);



// WOO HELPER

function reeid_copy_wc_data_to_translation($src_id, $new_id)
{
    if (get_post_type($src_id) !== 'product' || get_post_type($new_id) !== 'product') {
        return;
    }

    // --- WooCommerce meta keys ---
    $wc_keys = [
        '_regular_price',
        '_sale_price',
        '_price',
        '_stock',
        '_stock_status',
        '_manage_stock',
        '_sku',
        '_downloadable',
        '_virtual',
        '_weight',
        '_length',
        '_width',
        '_height',
        '_tax_class',
        '_tax_status',
        '_product_attributes',
        '_product_version',
        'total_sales',
        '_sold_individually',
        '_backorders'
    ];
    foreach ($wc_keys as $key) {
        $val = get_post_meta($src_id, $key, true);
        if ($val !== '') {
            update_post_meta($new_id, $key, $val);
        }
    }

    // Copy product gallery and thumbnail
    $thumb = get_post_thumbnail_id($src_id);
    if ($thumb) set_post_thumbnail($new_id, $thumb);

    $gallery = get_post_meta($src_id, '_product_image_gallery', true);
    if ($gallery) update_post_meta($new_id, '_product_image_gallery', $gallery);

    // --- Copy taxonomies (categories, tags, product type, attrs) ---
    $taxes = ['product_cat', 'product_tag', 'product_type', 'product_visibility'];
    if (function_exists('wc_get_attribute_taxonomies')) {
        foreach ((array) wc_get_attribute_taxonomies() as $att) {
            $taxes[] = 'pa_' . $att->attribute_name;
        }
    }
    foreach ($taxes as $tax) {
        $terms = wp_get_object_terms($src_id, $tax, ['fields' => 'ids']);
        if (!is_wp_error($terms) && $terms) {
            wp_set_object_terms($new_id, $terms, $tax, false);
        }
    }
}



/*===========================================================================
  SECTION 21 : Map-based translation wrapper (used by Section 18 + 19)
 *===========================================================================*/
if (! function_exists('reeid_call_translation_engine_for_map')) {
    /**
     * Translate associative map of { key => text } using plugin's engine.
     * Returns same shape array with translated values.
     *
     * @param array  $map         Keys → text/html
     * @param string $target_lang ISO language code
     * @param array  $ctx         Extra context (post_id, tone, prompt, etc.)
     * @return array
     */
    function reeid_call_translation_engine_for_map(array $map, string $target_lang, array $ctx = []): array
    {
        if (empty($map)) {
            return $map;
        }
        if (!function_exists('reeid_translate_map_via_openai')) {
            // Fallback: identity (English stays untranslated)
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S17.9/NO_ENGINE', ['map_count' => count($map)]);
            }
            return $map;
        }

        // Delegate to engine
        $result = reeid_translate_map_via_openai($map, $target_lang, $ctx);

        if (empty($result)) {
            // Defensive fallback: return original if translation failed
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S17.9/TRANSLATION_FAILED', ['map_count' => count($map)]);
            }
            return $map;
        }

        if (function_exists('reeid_debug_log')) {
            reeid_debug_log('S17.9/TRANSLATED', [
                'orig_count' => count($map),
                'out_count'  => count($result),
                'lang'       => $target_lang
            ]);
        }

        return $result;
    }
}

/* ===========================================================
   SECTION 22 : Single translation via AJAX
 * Function: reeid_handle_ajax_translation
 * =========================================================== */

 if ( ! function_exists( 'reeid_get_combined_prompt' ) ) {
    function reeid_get_combined_prompt( $post_id = 0, $target_lang = '', $override_prompt = '' ) {
    $post_id = (int) $post_id;
    $target_lang = is_string( $target_lang ) ? trim( $target_lang ) : '';
    $override_prompt = is_string( $override_prompt ) ? trim( $override_prompt ) : '';

    // Get admin/global prompt (primary option)
    $admin = (string) get_option( 'reeid_translation_custom_prompt', '' );
    $admin = trim( $admin );

    // Per-post prompt (optional)
    $post_prompt = '';
    if ( $post_id ) {
        $post_prompt = (string) get_post_meta( $post_id, 'reeid_translation_custom_prompt', true );
        $post_prompt = trim( $post_prompt );
    }

    // Base prompt: prefer cached global base if available
    $base = '';
    if ( isset( $GLOBALS['reeid_base_prompt_cached'] ) && is_string( $GLOBALS['reeid_base_prompt_cached'] ) ) {
        $base = $GLOBALS['reeid_base_prompt_cached'];
    } else {
        $base = "You are a professional translator and editor. Produce a natural, idiomatic, human-quality translation of the SOURCE text into the TARGET language. Return only the translated text — do not add titles, labels, examples, or extra lines. Preserve HTML structure and tags; translate text nodes only. Preserve brand names, placeholders, shortcodes and tokens exactly (for example: REEID, {{price}}, %s, [shortcode]). Do not mix languages; respond only in the target language. Prefer fluent, idiomatic phrasing over literal word-for-word translation.";
    }

    // Raw ordered parts
    $raw_parts = array();
    if ( strlen( trim( $base ) ) ) { $raw_parts[] = trim( $base ); }
    if ( strlen( $admin ) ) { $raw_parts[] = $admin; }
    if ( strlen( $post_prompt ) ) { $raw_parts[] = $post_prompt; }
    if ( strlen( $override_prompt ) ) { $raw_parts[] = $override_prompt; }

    // Normalizer: strip tags, lowercase (UTF-8 aware), collapse whitespace
    $normalize = function( $s ) {
        $s = strip_tags( (string) $s );
        if ( function_exists( 'mb_strtolower' ) ) {
            $s = mb_strtolower( $s, 'UTF-8' );
        } else {
            $s = strtolower( $s );
        }
        $s = preg_replace( '/\s+/u', ' ', trim( $s ) );
        return trim( $s );
    };

    // Deduplicate by normalized string, preserve first-seen order.
    $seen = array();
    $final = array();
    foreach ( $raw_parts as $orig ) {
        $norm = $normalize( $orig );
        if ( $norm === '' ) {
            continue;
        }
        if ( isset( $seen[ $norm ] ) ) {
            // already added an equivalent/near-identical item; skip
            continue;
        }
        $seen[ $norm ] = true;
        $final[] = $orig;
    }

    if ( empty( $final ) ) {
        return '';
    }

    $combined = implode("\n\n---\n\n", $final);
$combined = preg_replace('/(\n){3,}/', "\n\n", $combined);

// If a target language code is provided, prepend a concise explicit instruction
// that references the code (avoids repeating full language names handled elsewhere).
if ( is_string( $target_lang ) && trim( $target_lang ) !== '' ) {
    $code = trim( $target_lang );
    $code_instruction = "Translate the following text into the language identified by code: {$code}.";
    // Avoid duplicating similar instruction if already present
    if ( stripos( $combined, 'translate the following text into the language identified by code' ) === false ) {
        $combined = $code_instruction . "\n\n---\n\n" . $combined;
    }
}

// Guard: prevent mixed-language outputs (language-neutral short guard).
$guard_phrase = 'Respond only in the target language. Do not mix languages or include text in any other language.';
if ( stripos( $combined, 'respond only in the target language' ) === false ) {
    $combined = $guard_phrase . "\n\n---\n\n" . $combined;
}

return $combined;
$inline_rule = 'Treat inline formatting tags (<strong>, <em>, <b>, <i>, <span>) as invisible for word order: translate as if tags were not there, but keep them on the same semantic words in the final result (you may move tags if natural word order changes).';
if ( stripos( $combined, 'inline formatting tags' ) === false ) {
    $combined .= "\n\n---\n\n" . $inline_rule;
}


    
    }
    }

 

remove_action('wp_ajax_reeid_translate_openai', 'reeid_handle_ajax_translation');
add_action('wp_ajax_reeid_translate_openai', 'reeid_handle_ajax_translation');

if (! function_exists('reeid_handle_ajax_translation')) {
    function reeid_handle_ajax_translation()
    {
// >>> START TEMP HANDLER DEBUG (REMOVE AFTER USE)
@file_put_contents('/tmp/reeid_handler_start_debug.log', date('c') . " POST: " . json_encode(array_intersect_key($_POST, array_flip(['post_id','lang','tone','prompt','prompt_override','reeid_publish_mode','single_mode']))) . PHP_EOL, FILE_APPEND | LOCK_EX);
@file_put_contents('/tmp/reeid_handler_start_debug.log', date('c') . " CURRENT_USER: " . (function_exists('wp_get_current_user') ? json_encode(wp_get_current_user()->user_login) : 'N/A') . PHP_EOL, FILE_APPEND | LOCK_EX);
// >>> END TEMP HANDLER DEBUG

        $post_id      = isset($_POST['post_id']) ? (int) wp_unslash($_POST['post_id']) : 0;
        $target_lang  = sanitize_text_field(wp_unslash($_POST['lang'] ?? ''));
        $tone         = sanitize_text_field(wp_unslash($_POST['tone'] ?? 'Neutral'));
        $publish_mode = sanitize_text_field(wp_unslash($_POST['reeid_publish_mode'] ?? 'publish'));
        // === Normalize & validate target language early ===
        // normalise separators and lowercase (allow en, en-us, pt-br etc)
        $target_lang = strtolower(str_replace('_', '-', (string) $target_lang));
        $target_lang = preg_replace('/[^a-z0-9-]/', '', $target_lang); // defensive

        // enforce basic, strict format: two-letter base optionally followed by -subtag
        if (! preg_match('/^[a-z]{2}(?:-[a-z0-9]{2,8})?$/', $target_lang)) {
            // log for diagnosis and refuse to call engine
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S18/INVALID_TARGET_LANG', ['raw' => $_POST['lang'] ?? '', 'norm' => $target_lang]);
            } else {
                error_log("REEID: invalid target_lang format: " . ($_POST['lang'] ?? '') . " -> normalized: {$target_lang}");
            }
            wp_send_json_error(['error' => 'invalid_target_lang', 'message' => 'Target language format invalid']);
        }
        // Optional: canonicalize e.g. "en-us" -> keep as is — plugin expects lowercase-with-dash.


        /* >>> INJECTION START: prompt override (Elementor/Metabox/Woo) - REPLACED */
/* Accept either `prompt_override` (preferred) or legacy `prompt`. Keep raw, then trim. */
$ui_override_raw = '';
if ( isset( $_POST['prompt_override'] ) ) {
    $ui_override_raw = wp_unslash( $_POST['prompt_override'] );
} elseif ( isset( $_POST['prompt'] ) ) {
    $ui_override_raw = wp_unslash( $_POST['prompt'] );
}
$ui_override = is_string( $ui_override_raw ) ? trim( $ui_override_raw ) : '';

/* Build combined system prompt (base + admin + post + UI override) */
$system_prompt = function_exists( 'reeid_get_combined_prompt' )
    ? reeid_get_combined_prompt( $post_id, $target_lang, $ui_override )
    : trim( "Translate the content into the target language preserving HTML, placeholders and brand names." );

/* Keep legacy var name $prompt for downstream compatibility (some code expects it) */
$prompt = $system_prompt;
/* >>> INJECTION END */
// >>> TEMP: log computed system prompt
@file_put_contents('/tmp/reeid_handler_sys.log',
  date('c').' SYS: '.json_encode([
    'post_id'=>(int)($_POST['post_id']??0),
    'lang'=>$_POST['lang']??'',
    'tone'=>$_POST['tone']??'',
    'ui_prompt'=>isset($ui_override)?$ui_override:'',
    'len'=>strlen((string)($system_prompt??'')),
    'head'=>substr((string)($system_prompt??''),0,400)
  ], JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND);
// <<< END TEMP

// >>> TEMP DEBUG: record AJAX POST and computed system prompt for metabox calls
@file_put_contents('/tmp/reeid_handler_debug.log', date('c') . ' AJAX_POST: ' . json_encode(array_intersect_key($_POST, array_flip(['post_id','lang','tone','prompt','prompt_override','reeid_publish_mode'])), JSON_UNESCAPED_UNICODE) . PHP_EOL, FILE_APPEND);
@file_put_contents('/tmp/reeid_handler_debug.log', date('c') . ' SYSTEM_PROMPT: ' . substr((string)($system_prompt ?? ''), 0, 4000) . PHP_EOL . PHP_EOL, FILE_APPEND);
// <<< END TEMP DEBUG




        // Language detector helper (uses plugin’s hreflang helper if present)
        $detect_lang = function ($id) {
            if (function_exists('reeid_post_lang_for_hreflang')) {
                return reeid_post_lang_for_hreflang($id);
            }
            $lang = get_post_meta($id, '_reeid_translation_lang', true);
            if (! $lang) $lang = get_option('reeid_translation_source_lang', 'en');
            return $lang ?: 'en';
        };

        if (! $post_id || ! $target_lang) {
            wp_send_json_error(['error' => 'missing_parameters']);
        }

        $post = get_post($post_id);
        if (! $post) {
            wp_send_json_error(['error' => 'post_not_found']);
        }

        $editor = function_exists('reeid_detect_editor_type') ? reeid_detect_editor_type($post_id) : 'classic';
        if (function_exists('reeid_debug_log')) {
            reeid_debug_log('S18 ENTRY', [
                'post_id'     => $post_id,
                'post_type'   => $post->post_type,
                'editor'      => $editor,
                'target_lang' => $target_lang,
                'mode'        => $publish_mode,
            ]);
        }

        $prompt = isset($prompt) ? trim((string)$prompt) : '';
        if ($prompt !== '') {
            $prompt = "CRITICAL INSTRUCTIONS (enforce strictly):\n" . $prompt;
        }
        if (function_exists('reeid_debug_log')) {
            reeid_debug_log('S18 PROMPT (final)', ['len' => strlen($prompt), 'preview' => mb_substr($prompt, 0, 80, 'UTF-8')]);
        }

        /* >>> DEBUG (optional) — confirm prompt reached S18 */
        if (function_exists('reeid_debug_log')) {
            reeid_debug_log('S18 PROMPT RECEIVED', [
                'len'     => is_string($prompt) ? strlen($prompt) : 0,
                'preview' => is_string($prompt) ? mb_substr($prompt, 0, 120) : '',
                'tone'    => $tone,
            ]);
        }
        /* <<< DEBUG */

        if ($prompt === '' && function_exists('reeid_build_prompt')) {
            $source_lang = (function_exists('reeid_post_lang_for_hreflang'))
                ? (string) reeid_post_lang_for_hreflang($post_id)
                : ((string) get_post_meta($post_id, '_reeid_translation_lang', true));

            if ($source_lang === '') {
                $source_lang = (string) get_option('reeid_translation_source_lang', 'en');
                if ($source_lang === '') $source_lang = 'en';
            }

            $prompt = (string) reeid_build_prompt([
                'source_lang' => $source_lang,
                'target_lang' => $target_lang,
                'tone'        => $tone,
                'context'     => 'single_ajax',
                'post_type'   => $post->post_type,
                'editor'      => $editor,
            ]);
        }


       /* ===========================================================
        WooCommerce products — store inline (no new post)
       =========================================================== */
        if ($post->post_type === 'product') {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S18/WC INLINE START', ['post_id' => $post_id, 'lang' => $target_lang]);
            }

            // Resolve cluster root for products (store on source)
            $src_id = (int) get_post_meta($post_id, '_reeid_translation_source', true);
            if (! $src_id) $src_id = $post_id;

            // Build context for translator
            $ctx = [
                'post_id' => $post_id,
                'tone'    => $tone,
                'prompt'  => $prompt,
                'title'   => $post->post_title,
                'slug'    => $post->post_name,
                'excerpt' => $post->post_excerpt,
                'domain'  => 'woocommerce',
                'entity'  => 'product',
            ];

            // Defaults (source as fallback)
            $title   = (string) $post->post_title;
            $content = (string) $post->post_content;  // long description
            $excerpt = (string) $post->post_excerpt;  // short description
            $slug    = (string) $post->post_name;

            $src_lang = (string) get_option('reeid_translation_source_lang', 'en');
            if ($src_lang === '') $src_lang = 'en';

            // === Collect WooCommerce product attributes for translation ===
            $attributes = [];
            $raw_attrs = get_post_meta($post_id, '_product_attributes', true);
            if (is_array($raw_attrs)) {
                foreach ($raw_attrs as $key => $attr) {
                    if (!empty($attr['name']) && !empty($attr['value'])) {
                        $attributes[$attr['name']] = $attr['value'];
                    }
                }
            }
            // === End collect attributes ===

            // Preferred: bulk map translator if available (bypasses extractor entirely)
            if (function_exists('reeid_translate_map_via_openai')) {
                $in = [
                    'title'      => $title,
                    'excerpt'    => $excerpt,
                    'content'    => $content,
                    'slug'       => $slug,
                    'attributes' => $attributes, // <-- Now included
                ];
                $out = (array) reeid_translate_map_via_openai($in, $target_lang, $ctx);
                $title   = is_string($out['title']   ?? '') ? $out['title']   : $title;
                $excerpt = is_string($out['excerpt'] ?? '') ? $out['excerpt'] : $excerpt;
                $content = is_string($out['content'] ?? '') ? $out['content'] : $content;
                $slug    = is_string($out['slug']    ?? '') ? $out['slug']    : $slug;
                // You will later need to re-inject translated attributes (see next steps)
                if (!empty($out['attributes']) && is_array($out['attributes'])) {
                    $attributes = $out['attributes'];
                }
            } elseif ($editor === 'elementor' && function_exists('reeid_elementor_translate_json')) {
                // Elementor product content
                $res = reeid_elementor_translate_json($post_id, $src_lang, $target_lang, $tone, $prompt);

// REEID: auto-inject elementor 'data' into _elementor_data for translated post (non-destructive)
$__reeid_inject_target = (isset($tid) && $tid) ? $tid : (isset($translated_post_id) && $translated_post_id ? $translated_post_id : $post_id);
if (! empty($res['data']) && is_array($res['data'])) {
    $json = wp_json_encode($res['data']);
    if ($json !== false) {
        update_post_meta($__reeid_inject_target, '_elementor_data', wp_slash($json));
        // keep a simple version stamp so other code knows data changed
        update_post_meta($__reeid_inject_target, '_elementor_data_version', (string) time());
        // defensive: attempt to clear Elementor document caches if available
        if (class_exists('\Elementor\Plugin')) {
            try {
                $docs = \Elementor\Plugin::instance()->documents ?? null;
                if ($docs && method_exists($docs, 'clear_doc_caches')) {
                    $docs->clear_doc_caches($__reeid_inject_target);
                } elseif ($docs && method_exists($docs, 'clear_cache')) {
                    $docs->clear_cache($__reeid_inject_target);
                }
            } catch (Throwable $e) {
                // intentionally ignore cache-clear failures
            }
        }
    }
}

                if (empty($res['success'])) {
                    if (function_exists('reeid_debug_log')) {
                        reeid_debug_log('S18/WC ELEMENTOR FAILED', $res);
                    }
                    wp_send_json_error(['error' => 'elementor_failed', 'detail' => $res]);
                }
                $title   = $res['title']   ?? $title;
                $excerpt = $res['excerpt'] ?? $excerpt;
                $content = $res['content'] ?? $content;
                $slug    = $res['slug']    ?? (function_exists('reeid_sanitize_native_slug') ? reeid_sanitize_native_slug($title) : sanitize_title($title));
            // Ignore API slug artefacts (ndash/mdash rendered as 8211/8212)
            if (is_string($slug) && preg_match('/(?:^|-)82(?:11|12)(?:-|$)/', $slug)) {
                $slug = '';
            // Fallback if slug empty after guard
              if (!is_string($slug) || $slug === '') {
                  $slug = (function_exists('reeid_sanitize_native_slug') ? reeid_sanitize_native_slug($title) : sanitize_title($title));
              }
            }
            // Ignore API slug artefacts (ndash/mdash rendered as 8211/8212)
            } elseif (function_exists('reeid_gutenberg_classic_translate_via_extractor') || function_exists('reeid_translate_html_with_openai')) {
                // Gutenberg/Classic products — fall back to extractor + short-text translators

                // CHANGE: pass $tone and $prompt to extractor so Custom Prompt is honored
                if (function_exists('reeid_gutenberg_classic_translate_via_extractor')) {
                    $content_tr = reeid_gutenberg_classic_translate_via_extractor($post->post_content, $target_lang, $tone, $prompt);
                    if (is_string($content_tr) && $content_tr !== '') $content = $content_tr;
                }

                if (function_exists('reeid_translate_html_with_openai')) {
                    // Note: this helper doesn’t accept $prompt; leave as-is unless you extend its signature
                    $title_tr   = (string) reeid_translate_html_with_openai($post->post_title, 'en', $target_lang, $editor, $tone, $prompt);
                    $excerpt_tr = (string) reeid_translate_html_with_openai($post->post_excerpt, 'en', $target_lang, $editor, $tone, $prompt);

                    if ($title_tr   !== '') $title   = $title_tr;
                    if ($excerpt_tr !== '') $excerpt = $excerpt_tr;
                    $slug = function_exists('reeid_sanitize_native_slug') ? reeid_sanitize_native_slug($title) : sanitize_title($title);
                }

                if (!empty($attributes) && function_exists('reeid_translate_html_with_openai')) {
                    foreach ($attributes as $attr_key => $attr_val) {
                        // Pass RAW $prompt; the helper merges with Global Instructions internally
                        $translated = (string) reeid_translate_html_with_openai(
                            $attr_val,
                            $src_lang,
                            $target_lang,
                            $editor,
                            $tone,
                            $prompt
                        );
                        if ($translated !== '') {
                            $attributes[$attr_key] = $translated;
                        }
                    }
                }
            }

            // Store inline translation (no new product post) — ALWAYS on src_id
            if (! function_exists('reeid_wc_store_translation_meta')) {
                if (function_exists('reeid_debug_log')) {
                    reeid_debug_log('S18/WC MISSING STORE FUNCTION', null);
                }
                wp_send_json_error(['error' => 'storage_unavailable']);
            }

            // Sanitize minimally
            $safe_title = trim(wp_strip_all_tags((string) $title));
            $safe_slug  = reeid_sanitize_native_slug($slug ?: $safe_title);
            $payload = [
                'title'      => (string) $safe_title,
                'content'    => (string) wp_kses_post($content),
                'excerpt'    => (string) wp_kses_post($excerpt),
                'slug'       => (string) $safe_slug,
                'updated'    => gmdate('c'),
                'editor'     => $editor,
                'attributes' => $attributes, // Save translated attributes to meta
            ];
            $ok = reeid_wc_store_translation_meta($src_id, $target_lang, $payload);

            if (
                get_post_type($post_id) === 'product' &&
                class_exists('WC_Product') &&
                function_exists('reeid_translate_product_attributes')
            ) {
                $src_product = wc_get_product($src_id);      // Always original English product
                $dst_product = wc_get_product($src_id);      // Inline mode points to same product

                if ($src_product && $dst_product) {
                    if (empty($tone)) $tone = 'neutral';
                    // Attribute translation call:
                    reeid_translate_product_attributes($src_product, $dst_product, $src_lang, $target_lang, $tone);
                    // Always save the product to commit attribute changes
                    if (method_exists($dst_product, 'save')) {
                        $dst_product->save();
                    }
                    if (function_exists('reeid_debug_log')) {
                        $after = wc_get_product($src_id);
                        reeid_debug_log('WC ATTR SINGLE TRANSLATE: After', [
                            'dst_attrs' => $after ? $after->get_attributes() : []
                        ]);
                    }
                }
            }

            // Update map on the cluster root to point this language to the same product (inline mode)
            $map      = (array) get_post_meta($src_id, '_reeid_translation_map', true);
            $map[$src_lang]    = $src_id;
            $map[$target_lang] = $src_id;
            update_post_meta($src_id, '_reeid_translation_map', $map);

            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S18/WC INLINE STORED', [
                    'post_id' => $post_id,
                    'src_id' => $src_id,
                    'lang' => $target_lang,
                    'ok' => $ok,
                    'lens' => [
                        'title' => strlen($payload['title']),
                        'excerpt' => strlen($payload['excerpt']),
                        'content' => strlen($payload['content']),
                        'attributes' => is_array($payload['attributes']) ? count($payload['attributes']) : 0,
                    ],
                ]);
            }

            wp_send_json_success([
                'ok'               => (bool) $ok,
                'action'           => 'stored_inline',
                'post_id'          => $src_id,
                'lang'             => $target_lang,
                'inline'           => true,
                'collected_count'  => 0,
                'translated_count' => 0,
            ]);
        }


       /*===========================================================
            DEFAULT PATH (Pages/Posts/etc.)
=========================================================== */
$src_id = $post_id;
$map    = (array) get_post_meta($src_id, '_reeid_translation_map', true);
$default_source_lang       = get_option('reeid_translation_source_lang', 'en');
$map[$default_source_lang] = $src_id;

$target_id = isset($map[$target_lang]) ? (int) $map[$target_lang] : 0;
$action    = 'updated';
if (! $target_id || ! get_post_status($target_id)) {

    // Determine canonical source post and type (defensive).
    $source_id   = isset($src_id) && $src_id ? (int) $src_id : (int) ($post->ID ?? 0);
    $source_type = $source_id ? get_post_type($source_id) : ($post->post_type ?? '');

    // If the source is a WooCommerce product or variation, do NOT create a new post.
    // Instead use inline-storage: point $target_id to the original product and mark action.
    if (in_array($source_type, array('product', 'product_variation'), true)) {
        $target_id = $source_id;
        $action    = 'stored_inline';
        // Optional downstream flag (harmless if unused)
        $reeid_translation_inline_mode = true;
    } else {
        // Non-product: create a new draft post as before.
        $target_id = wp_insert_post(array(
            'post_type'   => $post->post_type,
            'post_status' => 'draft',
            'post_title'  => $post->post_title,
            'post_author' => $post->post_author,
        ), true);

        $action = 'created';

        if (is_wp_error($target_id)) {
            wp_send_json_error(array('error' => 'create_failed', 'detail' => $target_id->get_error_message()));
        }
    }
}

$collected_count  = 0;
$translated_count = 0;
$translated_slug  = '';

// Keep these visible for the SEO tail-section
$result     = null;
$title_tr   = '';
$excerpt_tr = '';

if ($editor === 'elementor') {
    $result = reeid_elementor_translate_json($post_id, $default_source_lang, $target_lang, $tone, $prompt);

    // Collections for diff counts (optional)
    $raw_elem = function_exists('reeid_get_sanitized_elementor_data')
        ? reeid_get_sanitized_elementor_data($post_id)
        : get_post_meta($post_id, '_elementor_data', true);

    $elem_before = is_array($raw_elem) ? $raw_elem : @json_decode((string)$raw_elem, true);
    if (is_array($elem_before) && function_exists('reeid_elementor_walk_and_collect')) {
        $before_map = [];
        reeid_elementor_walk_and_collect($elem_before, '', $before_map);
        $collected_count = count($before_map);
    }
    if (isset($result['data']) && is_array($result['data']) && function_exists('reeid_elementor_walk_and_collect')) {
        $after_map = [];
        reeid_elementor_walk_and_collect($result['data'], '', $after_map);
        $translated_count = count($after_map);
    }

    if (empty($result['success'])) {
        wp_send_json_error(['error' => 'elementor_failed', 'detail' => $result]);
    }

    // --- Build a clean, native slug candidate from result -> title fallback
    $slug_candidate = '';
    if (!empty($result['slug']) && is_string($result['slug'])) {
        $slug_candidate = $result['slug'];
    } elseif (!empty($result['title'])) {
        $slug_candidate = (string) $result['title'];
    } else {
        $slug_candidate = (string) $post->post_title;
    }
    $slug_candidate = function_exists('reeid_sanitize_native_slug')
        ? reeid_sanitize_native_slug($slug_candidate)
        : sanitize_title($slug_candidate);

    // Ensure uniqueness before saving
    $slug_unique = wp_unique_post_slug(
        $slug_candidate,
        (int) $target_id,
        get_post_status($target_id),
        $post->post_type,
        (int) get_post_field('post_parent', $target_id)
    );

    // Save translated post (Elementor)
    $new_post = [
        'ID'           => $target_id,
        'post_title'   => $result['title']   ?? $post->post_title,
        'post_status'  => $publish_mode,
        'post_type'    => $post->post_type,
        'post_name'    => $slug_unique ?: $slug_candidate,
        'post_author'  => $post->post_author,
        'post_excerpt' => $result['excerpt'] ?? $post->post_excerpt,
    ];

    // === Force native slug for this update only (Elementor scope) ===
    $reeid_slug_to_force = $new_post['post_name'];
    $reeid_cb_sanitize = static function ($title, $raw_title, $context) use (&$reeid_slug_to_force) {
        return (is_string($reeid_slug_to_force) && $reeid_slug_to_force !== '') ? $reeid_slug_to_force : $title;
    };
    $reeid_cb_insert = static function ($data, $postarr) use (&$reeid_slug_to_force) {
        if (is_string($reeid_slug_to_force) && $reeid_slug_to_force !== '') {
            $data['post_name'] = $reeid_slug_to_force;
        }
        return $data;
    };
    add_filter('sanitize_title', $reeid_cb_sanitize, PHP_INT_MAX, 3);
    add_filter('wp_insert_post_data', $reeid_cb_insert, PHP_INT_MAX, 2);

    $target_id = function_exists('reeid_safe_wp_update_post')
        ? reeid_safe_wp_update_post($new_post, true)
        : wp_update_post($new_post, true);

    remove_filter('sanitize_title', $reeid_cb_sanitize, PHP_INT_MAX);
    remove_filter('wp_insert_post_data', $reeid_cb_insert, PHP_INT_MAX);
    unset($reeid_slug_to_force, $reeid_cb_sanitize, $reeid_cb_insert);

    if (is_wp_error($target_id)) {
        wp_send_json_error(['error' => 'update_failed', 'detail' => $target_id->get_error_message()]);
    }

    if (isset($result['data']) && function_exists('reeid_elementor_commit_post')) {
        reeid_elementor_commit_post($target_id, $result['data']);
    }
    // Ensure Elementor meta is present and cached assets are refreshed
if (isset($result['data'])) {
    $elem_json = is_array($result['data'])
        ? wp_json_encode($result['data'], JSON_UNESCAPED_UNICODE)
        : (string) $result['data'];
    if (is_string($elem_json) && $elem_json !== '') {
        update_post_meta($target_id, '_elementor_data', wp_slash($elem_json));
    }
    update_post_meta($target_id, '_elementor_edit_mode', 'builder');

    // Copy template type if source has one (keeps theme layout consistent)
    $tpl_type = get_post_meta($post_id, '_elementor_template_type', true);
    if (!empty($tpl_type)) {
        update_post_meta($target_id, '_elementor_template_type', $tpl_type);
    }

    if (did_action('elementor/loaded')) {
        try {
            \Elementor\Plugin::$instance->posts_css_manager->clear_cache($target_id);
            \Elementor\Plugin::$instance->files_manager->clear_cache();
        } catch (\Throwable $e) { /* noop */ }
    }
}


    // --- Guard: if translated title is native but saved slug reverted to ASCII, fix immediately
    if (!is_wp_error($target_id)) {
        $title_for_slug = (string) get_post_field('post_title', $target_id);
        $saved = get_post($target_id);
        if ($saved && isset($saved->post_name)) {
            $title_has_non_ascii = (bool) preg_match('/[^\x00-\x7F]/u', $title_for_slug);
            $slug_has_non_ascii  = (bool) preg_match('/[^\x00-\x7F]/u', (string) $saved->post_name);
            if ($title_has_non_ascii && !$slug_has_non_ascii) {
                $native = function_exists('reeid_sanitize_native_slug')
                    ? reeid_sanitize_native_slug($title_for_slug)
                    : sanitize_title($title_for_slug);
                $unique = wp_unique_post_slug(
                    $native,
                    $target_id,
                    get_post_status($target_id),
                    get_post_type($target_id),
                    (int) get_post_field('post_parent', $target_id)
                );
                wp_update_post([
                    'ID'        => $target_id,
                    'post_name' => $unique ?: $native,
                ]);
            }
        }
    }

    $translated_slug = (string) ($result['slug'] ?? '');





} else {

    // Gutenberg/Classic path
    $en_lines   = function_exists('reeid_extract_text_lines') ? reeid_extract_text_lines($post->post_content) : [];

    // IMPORTANT: pass $tone and RAW $prompt (merging happens inside helpers)
    $content_tr = function_exists('reeid_gutenberg_classic_translate_via_extractor')
        ? reeid_gutenberg_classic_translate_via_extractor($post->post_content, $target_lang, $tone, $prompt)
        : '';

    // Titles / excerpts via short-text helper — now supports $prompt
    $title_tr   = function_exists('reeid_translate_html_with_openai')
        ? reeid_translate_html_with_openai($post->post_title,   'en', $target_lang, $editor, $tone, $prompt)
        : '';
    $excerpt_tr = function_exists('reeid_translate_html_with_openai')
        ? reeid_translate_html_with_openai($post->post_excerpt, 'en', $target_lang, $editor, $tone, $prompt)
        : '';

    $ar_lines = [];
    if (is_string($content_tr) && $content_tr !== '' && $en_lines && function_exists('reeid_extract_text_lines')) {
        $ar_lines = reeid_extract_text_lines($content_tr);
    }

    $collected_count  = is_array($en_lines) ? count($en_lines) : 0;
    $translated_count = is_array($ar_lines) ? count($ar_lines) : 0;

    // Slug handling (Gutenberg/Classic)
    $translated_slug = '';
    if (is_string($title_tr) && $title_tr !== '') {
        $translated_slug = reeid_sanitize_native_slug($title_tr);
    }
    if ($translated_slug === '') {
        $translated_slug = reeid_sanitize_native_slug($post->post_title);
    }

    $translated_slug = wp_unique_post_slug(
        $translated_slug,
        $target_id,
        $publish_mode === 'publish' ? 'publish' : 'draft',
        $post->post_type,
        $post->post_parent
    );

    // Save
    $new_post = [
        'ID'           => $target_id,
        'post_title'   => ($title_tr !== '' ? $title_tr : $post->post_title),
        'post_status'  => $publish_mode,
        'post_type'    => $post->post_type,
        'post_name'    => $translated_slug,
        'post_author'  => $post->post_author,
        'post_excerpt' => ($excerpt_tr !== '' ? $excerpt_tr : $post->post_excerpt),
    ];

    $target_id = function_exists('reeid_safe_wp_update_post')
        ? reeid_safe_wp_update_post($new_post, true)
        : wp_update_post($new_post, true);

    if (is_wp_error($target_id)) {
        wp_send_json_error(['error' => 'update_failed', 'detail' => $target_id->get_error_message()]);
    }

    // Commit translated content if available
    if (isset($content_tr)) {
        $clean = is_string($content_tr) ? trim($content_tr) : '';
        if ($clean !== '') {
            if (function_exists('reeid_safe_wp_update_post')) {
                reeid_safe_wp_update_post(['ID' => $target_id, 'post_content' => $clean]);
            } else {
                wp_update_post(['ID' => $target_id, 'post_content' => $clean]);
            }
            delete_post_meta($target_id, '_reeid_translated_content_' . $target_lang);
        } else {
            update_post_meta($target_id, '_reeid_translated_content_' . $target_lang, $content_tr);
        }
    }

    // --- Guard: if translated title is native but saved slug is ASCII, fix immediately
    $title_for_slug = ($title_tr !== '' ? $title_tr : $post->post_title);
    $saved = get_post($target_id);
    if ($saved && isset($saved->post_name)) {
        $title_has_non_ascii = (bool) preg_match('/[^\x00-\x7F]/u', (string) $title_for_slug);
        $slug_has_non_ascii  = (bool) preg_match('/[^\x00-\x7F]/u', (string) $saved->post_name);
        if ($title_has_non_ascii && !$slug_has_non_ascii) {
            $native = reeid_sanitize_native_slug($title_for_slug);
            $unique = wp_unique_post_slug(
                $native,
                $target_id,
                get_post_status($target_id),
                get_post_type($target_id),
                (int) get_post_field('post_parent', $target_id)
            );
            wp_update_post([
                'ID'        => $target_id,
                'post_name' => $unique ?: $native,
            ]);
        }
    }
}

// Common bookkeeping
update_post_meta($target_id, '_reeid_translation_lang', $target_lang);
update_post_meta($target_id, '_reeid_translation_source', $src_id);
$map[$target_lang] = $target_id;
update_post_meta($src_id, '_reeid_translation_map', $map);

if (function_exists('reeid_clone_seo_meta')) {
    reeid_clone_seo_meta($src_id, $target_id, $target_lang);
}

// === Write translated SEO meta (title + description) — single place ===
if (function_exists('reeid_write_title_all_plugins')) {
    // Resolve langs
    $src_lang = $detect_lang($src_id);
    $tgt_lang = $target_lang;

    // Final title for target
    $final_title = ($editor === 'elementor')
        ? (string)($result['title'] ?? $post->post_title)
        : (string)($title_tr !== '' ? $title_tr : $post->post_title);
    $final_title_trim = trim((string)$final_title);
    if ($final_title_trim !== '' && stripos($final_title_trim, 'INVALID LANGUAGE PAIR') === false) {
        reeid_safe_write_title_all_plugins($target_id, $final_title_trim);
    } else {
        if (function_exists('reeid_debug_log')) {
            reeid_debug_log('S18/SEO_SKIPPED_TITLE', [
                'post_id' => $target_id,
                'preview' => mb_substr($final_title_trim, 0, 80, 'UTF-8'),
            ]);
        }
    }
}

if (function_exists('reeid_write_description_all_plugins')) {
    // Always derive description from SOURCE (avoid double-translating target excerpts)
    $src_desc = '';
    if (function_exists('reeid_read_canonical_description')) {
        $src_desc = (string) reeid_read_canonical_description($src_id);
    } else {
        $src_desc = wp_strip_all_tags(get_post_field('post_excerpt', $src_id) ?: get_post_field('post_content', $src_id));
    }
    $src_desc = trim(preg_replace('/\s+/', ' ', (string)$src_desc));

    if ($src_desc !== '') {
        $src_lang = $detect_lang($src_id);
        $tgt_lang = $target_lang;

        $desc_tr = $src_desc;
        if ($src_lang && $tgt_lang && strcasecmp($src_lang, $tgt_lang) !== 0 && function_exists('reeid_translate_short_text')) {
            $desc_tr = (string) reeid_translate_short_text($src_desc, $src_lang, $tgt_lang, $tone);
            reeid_harden_invalid_lang_pair($desc_tr);
            $desc_tr_trim = trim($desc_tr);
            if ($desc_tr_trim !== '' && stripos($desc_tr_trim, 'INVALID LANGUAGE PAIR') === false) {
                reeid_write_description_all_plugins($target_id, $desc_tr_trim);
            } else {
                if (function_exists('reeid_debug_log')) {
                    reeid_debug_log('S18/SEO_SKIPPED_DESC', [
                        'post_id' => $target_id,
                        'preview' => mb_substr($desc_tr_trim, 0, 160, 'UTF-8'),
                    ]);
                }
            }
        }
    }

    if (get_post_type($target_id) === 'product' && class_exists('WC_Product') && function_exists('reeid_translate_product_attributes')) {
        $src_product = wc_get_product($src_id);
        $dst_product = wc_get_product($target_id);
        $src_lang = $default_source_lang;
        $dst_lang = $target_lang;
        if ($src_product && $dst_product) {
            if (empty($tone)) $tone = 'neutral';
            reeid_translate_product_attributes($src_product, $dst_product, $src_lang, $dst_lang, $tone);
        }
    }

    wp_send_json_success([
        'ok'               => true,
        'action'           => $action,
        'post_id'          => $target_id,
        'lang'             => $target_lang,
        'slug'             => $translated_slug,
        'collected_count'  => $collected_count,
        'translated_count' => $translated_count,
    ]);
}
    }
 /*===========================================================================
    SECTION 23 AJAX — Bulk translation (STRICT + preflight + SEO sync)
    Function: reeid_handle_ajax_bulk_translation_v3
 *===========================================================================*/

    if (function_exists('remove_all_actions')) {
        remove_all_actions('wp_ajax_reeid_translate_openai_bulk');
    }
    if (function_exists('remove_action') && function_exists('has_action')) {
        if (has_action('wp_ajax_reeid_translate_openai_bulk', 'reeid_handle_ajax_bulk_translation')) {
            remove_action('wp_ajax_reeid_translate_openai_bulk', 'reeid_handle_ajax_bulk_translation');
        }
    }
    add_action('wp_ajax_reeid_translate_openai_bulk', 'reeid_handle_ajax_bulk_translation_v3', 1);

    if (! function_exists('reeid_handle_ajax_bulk_translation_v3')) :
        function reeid_handle_ajax_bulk_translation_v3()
        {

            /* ---------- Nonce ---------- */
            $nonce_post      = filter_input(INPUT_POST, 'reeid_translate_nonce', FILTER_DEFAULT);
            $nonce_unslashed = $nonce_post ? wp_unslash($nonce_post) : '';
            $nonce           = sanitize_text_field($nonce_unslashed);
            if (! $nonce || ! wp_verify_nonce($nonce, 'reeid_translate_nonce_action')) {
                wp_send_json_error(['code' => 'bad_nonce', 'message' => __('Invalid security token', 'reeid-translate')]);
            }

            /* ---------- Capability ---------- */
            if (! current_user_can('edit_posts')) {
                wp_send_json_error(['code' => 'forbidden', 'message' => __('Permission denied', 'reeid-translate')]);
            }

            /* ---------- Root post ---------- */
            $post_id_raw = filter_input(INPUT_POST, 'post_id', FILTER_DEFAULT);
            $post_id     = $post_id_raw ? absint(wp_unslash($post_id_raw)) : 0;
            if (! $post_id) {
                wp_send_json_error(['code' => 'no_post', 'message' => __('Missing post ID', 'reeid-translate')]);
            }

            $src = (int) get_post_meta($post_id, '_reeid_translation_source', true);
            if ($src > 0 && $src !== $post_id) {
                $post_id = $src;
            }

            $root_post = get_post($post_id);
            if (! $root_post) {
                wp_send_json_error(['code' => 'no_root', 'message' => __('Original post not found', 'reeid-translate')]);
            }

            /* ---------- Params ---------- */
            $tone   = sanitize_text_field(wp_unslash(filter_input(INPUT_POST, 'tone', FILTER_DEFAULT) ?: 'Neutral'));
            $prompt = sanitize_textarea_field(wp_unslash(filter_input(INPUT_POST, 'prompt', FILTER_DEFAULT) ?: ''));
            $mode   = sanitize_text_field(wp_unslash(filter_input(INPUT_POST, 'reeid_publish_mode', FILTER_DEFAULT) ?: 'publish'));

            // Admin-enabled languages (single source of truth)
            $configured = function_exists('reeid_get_enabled_languages')
                ? (array) reeid_get_enabled_languages()
                : (array) get_option('reeid_bulk_translation_langs', []);

            // Sanitize + intersect with supported (if list exists)
            $configured = array_values(array_filter(array_map(function ($v) {
                $v = strtolower(trim((string)$v));
                return preg_replace('/[^a-z0-9\-_]/i', '', $v);
            }, $configured)));

            if (function_exists('reeid_get_supported_languages')) {
                $supported_codes = array_keys((array) reeid_get_supported_languages());
                $configured      = array_values(array_intersect($configured, $supported_codes));
            }

            if (empty($configured)) {
                wp_send_json_error(['code' => 'no_bulk_langs', 'message' => __('No bulk languages selected', 'reeid-translate')]);
            }

            $check_only_raw = filter_input(INPUT_POST, 'check_only', FILTER_DEFAULT);
            $check_only     = $check_only_raw ? (bool) wp_unslash($check_only_raw) : false;

            $source_lang    = get_option('reeid_translation_source_lang', 'en');
            if (! is_string($source_lang) || $source_lang === '') {
                $source_lang = 'en';
            }

            $map = (array) get_post_meta($post_id, '_reeid_translation_map', true);
            $map[$source_lang] = $post_id;

            $selected = $configured;
            $details  = [];
            $results  = [];

            // If enqueuing jobs exists, prefer enqueue (fast client return)
            if (function_exists('reeid_translation_job_enqueue')) {
                $queued = [];
                foreach ($selected as $lang) {
                    $lang = strtolower(trim((string)$lang));
                    if ($lang === '' || $lang === $source_lang) {
                        $details[] = strtoupper($lang) . ': ⏭️ ' . __('Skipped (same as source)', 'reeid-translate');
                        continue;
                    }
                    $job_id = reeid_translation_job_enqueue(array(
                        'type'        => 'single',
                        'post_id'     => $post_id,
                        'target_lang' => $lang,
                        'user_id'     => get_current_user_id() ?: 1,
                        'params'      => array(
                            'tone'         => $tone,
                            'publish_mode' => $mode,
                            'prompt'       => $prompt,
                        ),
                    ));
                    if ($job_id) {
                        $queued[] = $lang;
                        $details[] = strtoupper($lang) . ': ⏳ ' . __('Queued', 'reeid-translate');
                        $results[$lang] = ['success' => true, 'queued' => true, 'job_id' => $job_id];
                    } else {
                        $details[] = strtoupper($lang) . ': ❌ ' . __('Queue failed', 'reeid-translate');
                        $results[$lang] = ['success' => false, 'error' => 'queue_failed'];
                    }
                }

                wp_send_json_success(array(
                    'queued'  => $queued,
                    'details' => $details,
                    'results' => $results,
                    'message' => sprintf(_n('Queued %d language', 'Queued %d languages', count($queued), 'reeid-translate'), count($queued))
                ));
                // worker will process jobs in background
            }

            if ($check_only) {
                wp_send_json_success([
                    'ok'         => true,
                    'action'     => 'plan_only',
                    'post_id'    => $post_id,
                    'langs'      => $selected,
                    'tone'       => $tone,
                    'publish'    => $mode,
                    'has_prompt' => ($prompt !== ''),
                ]);
            }

            /* ---------- Mini helpers ---------- */

            // Language resolver (post -> hreflang code)
            $resolve_lang = function ($id, $fallback = 'en') {
                if (function_exists('reeid_post_lang_for_hreflang')) {
                    $v = (string) reeid_post_lang_for_hreflang($id);
                    if ($v !== '') return $v;
                }
                $v = get_post_meta($id, '_reeid_translation_lang', true);
                if (! $v) $v = get_option('reeid_translation_source_lang', 'en');
                return $v ?: $fallback;
            };

            // Short-text translator
            $translate_short = function ($text, $from, $to) use ($tone) {
                $text = is_string($text) ? trim($text) : '';
                if ($text === '' || ! $from || ! $to || strcasecmp($from, $to) === 0) {
                    return $text;
                }
                if (function_exists('reeid_translate_short_text')) {
                    return (string) reeid_translate_short_text($text, $from, $to, $tone);
                }
                if (function_exists('reeid_translate_preserve_tokens') && function_exists('reeid_focuskw_call_translator')) {
                    return (string) reeid_translate_preserve_tokens($text, $from, $to, ['meta_key' => 'seo_text']);
                }
                if (function_exists('reeid_translate_html_with_openai')) {
                    return (string) reeid_translate_html_with_openai($text, $from, $to, 'classic', $tone);
                }
                return $text;
            };

            // SEO write helpers (safe if functions exist)
            $write_seo_title = function ($pid, $title) {
                $t = is_string($title) ? trim($title) : '';
                if ($t === '' || stripos($t, 'INVALID LANGUAGE PAIR') !== false) return;
                if (function_exists('reeid_write_title_all_plugins')) {
                    reeid_safe_write_title_all_plugins($pid, $t);
                }
            };
            $write_seo_desc = function ($pid, $desc) {
                $d = is_string($desc) ? trim($desc) : '';
                if ($d === '' || stripos($d, 'INVALID LANGUAGE PAIR') !== false) return;
                if (function_exists('reeid_write_description_all_plugins')) {
                    reeid_write_description_all_plugins($pid, $d);
                }
            };


            // Read canonical SEO fields from SOURCE
            $read_src_seo = function ($src_id) {
                $out = ['title' => '', 'desc' => ''];
                if (function_exists('reeid_read_canonical_title')) {
                    $out['title'] = (string) reeid_read_canonical_title($src_id);
                } else {
                    $out['title'] = get_the_title($src_id);
                }
                if (function_exists('reeid_read_canonical_description')) {
                    $out['desc'] = (string) reeid_read_canonical_description($src_id);
                } else {
                    $ex = get_post_field('post_excerpt', $src_id);
                    $out['desc'] = is_string($ex) ? trim(wp_strip_all_tags($ex)) : '';
                }
                return $out;
            };

            // Simple lock helpers to avoid double-runs
            if (! function_exists('reeid__bulk_try_lock')) {
                function reeid__bulk_try_lock($post_id, $lang, $ttl = 60)
                {
                    $key = 'reeid_bulk_' . $post_id . '_' . $lang;
                    $acq = set_transient($key, '1', $ttl);
                    return [$acq ? true : false, $key];
                }
                function reeid__bulk_release_lock($key)
                {
                    if (function_exists('wp_cache_delete')) wp_cache_delete($key, 'reeid');
                    delete_transient($key);
                }
            }

            /* ---------- Process each language (inline) ---------- */
            foreach ($selected as $lang) {
                $lang = strtolower(substr((string)$lang, 0, 10));

                if ($lang === $source_lang) {
                    $details[]      = strtoupper($lang) . ': ⏭️ ' . __('Skipped (same as source)', 'reeid-translate');
                    $results[$lang] = ['success' => true, 'skipped' => true, 'reason' => 'same_as_source'];
                    continue;
                }

                [$acquired, $lock_key] = reeid__bulk_try_lock($post_id, $lang, 120);
                if (! $acquired) {
                    $details[]      = strtoupper($lang) . ': ⏭️ ' . __('Skipped (already in progress)', 'reeid-translate');
                    $results[$lang] = ['success' => true, 'skipped' => true, 'reason' => 'locked'];
                    continue;
                }

                // Decide editor
                $editor = function_exists('reeid_detect_editor_type') ? reeid_detect_editor_type($post_id) : 'classic';

                // WooCommerce inline path (do not create separate posts)
                if ($root_post->post_type === 'product') {
                    $title   = $root_post->post_title;
                    $content = $root_post->post_content;
                    $excerpt = $root_post->post_excerpt;
                    $slug    = $root_post->post_name;

                    if ($editor === 'elementor' && function_exists('reeid_elementor_translate_json')) {
                        $result = reeid_elementor_translate_json($post_id, $source_lang, $lang, $tone, $prompt);
                        if (empty($result['success'])) {
                            $details[]      = strtoupper($lang) . ': ❌ ' . __('Elementor translation failed', 'reeid-translate');
                            $results[$lang] = ['success' => false, 'error' => 'elementor_failed', 'message' => ($result['message'] ?? '')];
                            reeid__bulk_release_lock($lock_key);
                            continue;
                        }
                        $title   = $result['title']   ?? $title;
                        $excerpt = $result['excerpt'] ?? $excerpt;
                        $content = $result['content'] ?? $content;
                        $slug    = $result['slug']    ?? $slug;
                    } elseif (function_exists('reeid_translate_via_openai_with_slug')) {
                        $ctx = ['tone' => $tone, 'prompt' => $prompt, 'title' => $title, 'slug' => $slug, 'excerpt' => $excerpt];
                        $result = reeid_translate_via_openai_with_slug($content, $lang, $ctx);
                        if (empty($result['success'])) {
                            $details[]      = strtoupper($lang) . ': ❌ ' . __('Translation failed', 'reeid-translate');
                            $results[$lang] = ['success' => false, 'error' => 'translation_failed', 'message' => ($result['message'] ?? '')];
                            reeid__bulk_release_lock($lock_key);
                            continue;
                        }
                        $title   = $result['title']   ?? $title;
                        $content = $result['content'] ?? $content;
                        $excerpt = $result['excerpt'] ?? $excerpt;
                        $slug    = $result['slug']    ?? $slug;
                    }

                    if (! function_exists('reeid_wc_store_translation_meta')) {
                        $details[]      = strtoupper($lang) . ': ❌ ' . __('Storage unavailable', 'reeid-translate');
                        $results[$lang] = ['success' => false, 'error' => 'storage_unavailable'];
                        reeid__bulk_release_lock($lock_key);
                        continue;
                    }

                    $payload = [
                        'title'   => (string) $title,
                        'content' => (string) $content,
                        'excerpt' => (string) $excerpt,
                        'slug'    => (string) $slug,
                        'updated' => gmdate('c'),
                        'editor'  => $editor,
                    ];
                    $ok = reeid_wc_store_translation_meta($post_id, $lang, $payload);

                    $map[$lang] = $post_id;

                    $details[]      = strtoupper($lang) . ': ✅ ' . __('Stored inline', 'reeid-translate');
                    $results[$lang] = ['success' => (bool) $ok, 'inline' => true, 'post_id' => $post_id];

                    reeid__bulk_release_lock($lock_key);
                    continue;
                }

                /* ---------- Default (non-product) path ---------- */
                $tid     = isset($map[$lang]) ? (int)$map[$lang] : 0;
                $tr_post = $tid ? get_post($tid) : null;

                if (! $tr_post || in_array(($tr_post->post_status ?? ''), ['trash', 'auto-draft'], true)) {
                    $tid = wp_insert_post([
                        'post_type'   => $root_post->post_type,
                        'post_status' => 'draft',
                        'post_title'  => $root_post->post_title,
                        'post_author' => $root_post->post_author,
                    ], true);
                    if (is_wp_error($tid)) {
                        $details[]      = strtoupper($lang) . ': ❌ ' . $tid->get_error_message();
                        $results[$lang] = ['success' => false, 'error' => 'create_failed', 'message' => $tid->get_error_message()];
                        reeid__bulk_release_lock($lock_key);
                        continue;
                    }
                }

                if ($editor === 'elementor' && function_exists('reeid_elementor_translate_json')) {
                    // Elementor branch
                    $result = reeid_elementor_translate_json($post_id, $source_lang, $lang, $tone, $prompt);
                    if (empty($result['success'])) {
                        $details[]      = strtoupper($lang) . ': ❌ ' . __('Elementor translation failed', 'reeid-translate');
                        $results[$lang] = ['success' => false, 'error' => 'elementor_failed', 'message' => ($result['message'] ?? '')];
                        reeid__bulk_release_lock($lock_key);
                        continue;
                    }

                   // --- START: elementor slug hardening ---
$title    = $result['title']   ?? $root_post->post_title;
$content  = $root_post->post_content;
$excerpt  = $result['excerpt'] ?? $root_post->post_excerpt;

// Prefer API-provided slug; otherwise derive from translated title (or source title)
// and sanitize to native script if helper exists. Keep length conservative.
$api_slug = (isset($result['slug']) && is_string($result['slug']) && $result['slug'] !== '')
    ? trim((string) $result['slug'])
    : '';

$title_candidate = (isset($result['title']) && is_string($result['title']) && $result['title'] !== '')
    ? (string) $result['title']
    : (string) $root_post->post_title;
/* --- START: elementor content->title fallback (when both API title and short-translator title are empty) --- */
if ($title_candidate === '' && ! empty($result['content'])) {
    // extract first meaningful text from translated HTML content and use it as title candidate
    $raw_from_content = wp_strip_all_tags((string) $result['content']);
    if ($raw_from_content !== '') {
        // keep it short and safe for slug generation
        $title_candidate = mb_substr($raw_from_content, 0, 200);
    }
}
/* --- END: elementor content->title fallback --- */
// --- START: elementor slug/title fallback via short translator ---
if ($title_candidate === '' && function_exists('reeid_translate_via_openai_with_slug')) {
    // Build minimal ctx matching other paths
    $ctx = [
        'tone'   => $tone,
        'prompt' => $prompt,
        'title'  => (string) $root_post->post_title,
        'slug'   => '',
        'excerpt'=> (string) $root_post->post_excerpt,
    ];

    // Ask the short translator (safe, already present in plugin)
    $short_res = reeid_translate_via_openai_with_slug((string) $root_post->post_content, $lang, $ctx);

    if (! empty($short_res['success'])) {
        // prefer returned title if present
        if (! empty($short_res['title']) && is_string($short_res['title'])) {
            $title_candidate = (string) $short_res['title'];
        }
        // prefer returned slug if present (will be used later as api_slug)
        if (! empty($short_res['slug']) && is_string($short_res['slug'])) {
            $api_slug = trim((string) $short_res['slug']);
        }
    }
}
// --- END: elementor slug/title fallback via short translator ---

if ($api_slug !== '') {
    $slug_raw = $api_slug;
} else {
    $base_for_slug = $title_candidate !== '' ? $title_candidate : $root_post->post_title;
    $candidate = mb_substr(wp_strip_all_tags((string) $base_for_slug), 0, 60);
/* --- START: elementor final slug-from-content fallback --- */
/*
 If earlier slug logic produced an ASCII fallback (copied source slug), but the
 translated HTML (post content) contains native-script characters, derive a new
 native slug from the translated content here just before we call wp_update_post().
 This avoids race and API-format differences for Elementor flows.
*/
$maybe_content = isset($new_post['post_content']) ? wp_strip_all_tags((string) $new_post['post_content']) : '';
// Only run when content exists and we still have a fallback-like slug.
if ($maybe_content !== '') {
    // detect if post_name appears to be the original source slug or ASCII-only
    $current_slug = (string) ($new_post['post_name'] ?? '');
    $is_ascii_slug = ($current_slug === '' || preg_match('/^[a-z0-9\-]+$/i', $current_slug));

    if ($is_ascii_slug) {
        $candidate = mb_substr($maybe_content, 0, 120);
        if (function_exists('reeid_sanitize_native_slug')) {
            $derived_slug = reeid_sanitize_native_slug($candidate);
        } else {
            $derived_slug = sanitize_title($candidate);
        }
        if ($derived_slug !== '') {
            // make unique using same parameters as earlier
            $derived_slug = wp_unique_post_slug(
                $derived_slug,
                $tid,
                $new_post['post_status'] ?? 'draft',
                $new_post['post_type'] ?? $root_post->post_type,
                $root_post->post_parent ?? 0
            );
            // trust this derived slug (overwrite fallback)
            $new_post['post_name'] = $derived_slug;
        }
    }
}
/* --- END: elementor final slug-from-content fallback --- */

    if (function_exists('reeid_sanitize_native_slug')) {
        // prefer native slug sanitizer if present
        $slug_raw = reeid_sanitize_native_slug($candidate);
    } else {
        $slug_raw = sanitize_title($candidate);
    }
}

// Ensure uniqueness immediately (use the new/inserted post id $tid)
$slug_raw = wp_unique_post_slug(
    $slug_raw,
    $tid,
    'draft',
    $root_post->post_type,
    $root_post->post_parent
);
// --- END: elementor slug hardening ---

                    if (! empty($result['data'])) {
                        $decoded = is_array($result['data']) ? $result['data'] : json_decode($result['data'], true);
                        update_post_meta($tid, '_elementor_data', $decoded);
                        update_post_meta($tid, '_elementor_edit_mode', 'builder');
                        update_post_meta($tid, '_elementor_template_type', 'wp-post');
                    }

                    $new_post = [
                        'ID'           => $tid,
                        'post_title'   => $title,
                        'post_content' => $content,
                        'post_status'  => $mode,
                        'post_type'    => $root_post->post_type,
                        'post_name'    => $slug_raw,
                        'post_author'  => $root_post->post_author,
                        'post_excerpt' => $excerpt,
                    ];
                    $tid2 = function_exists('reeid_safe_wp_update_post')
                        ? reeid_safe_wp_update_post($new_post, true)
                        : wp_update_post($new_post, true);

                    if (is_wp_error($tid2)) {
                        $details[]      = strtoupper($lang) . ': ❌ ' . $tid2->get_error_message();
                        $results[$lang] = ['success' => false, 'error' => 'update_failed', 'message' => $tid2->get_error_message()];
                        reeid__bulk_release_lock($lock_key);
                        continue;
                    }

                    // SEO write
                    $src_seo = $read_src_seo($post_id);
                    $src_l   = $resolve_lang($post_id, $source_lang);
                    $tgt_l   = $resolve_lang($tid2, $lang);
                    $title_tr = $src_seo['title'] !== '' ? $translate_short($src_seo['title'], $src_l, $tgt_l) : '';
                    $desc_tr  = $src_seo['desc']  !== '' ? $translate_short($src_seo['desc'],  $src_l, $tgt_l) : '';
                    $write_seo_title($tid2, $title_tr !== '' ? $title_tr : $title);
                    if ($desc_tr !== '') $write_seo_desc($tid2, $desc_tr);

                    update_post_meta($tid2, '_reeid_translation_lang', $lang);
                    update_post_meta($tid2, '_reeid_translation_source', $post_id);

                    $map[$lang]     = $tid2;
                    $details[]      = strtoupper($lang) . ': ✅ ' . __('Done', 'reeid-translate');
                    $results[$lang] = ['success' => true, 'post_id' => $tid2];

                    reeid__bulk_release_lock($lock_key);
                    continue;
                } else {
                    // Classic / Gutenberg branch
                    $title    = $root_post->post_title;
                    $content  = $root_post->post_content;
                    $excerpt  = $root_post->post_excerpt;
                    $slug_raw = $root_post->post_name;

                    // Extractor path — prompt-aware
                    if (function_exists('reeid_gutenberg_classic_translate_via_extractor')) {
                        $content_tr = reeid_gutenberg_classic_translate_via_extractor($root_post->post_content, $lang, $tone, $prompt);
                        if (is_string($content_tr) && $content_tr !== '') {
                            $content = $content_tr;
                        }
                    }

                    // Short-text translator — prompt-aware and using $source_lang
                    if (function_exists('reeid_translate_html_with_openai')) {
                        $t = reeid_translate_html_with_openai($root_post->post_title,   $source_lang, $lang, $editor, $tone, $prompt);
                        if (is_string($t) && $t !== '') $title = $t;

                        $e = reeid_translate_html_with_openai($root_post->post_excerpt, $source_lang, $lang, $editor, $tone, $prompt);
                        if (is_string($e) && $e !== '') $excerpt = $e;
                    }

                    // Slug uniqueness
                    // Prefer native base when title is native but slug_raw is ASCII/fallback
                    $base = $slug_raw;
                    if ($base === '' || (preg_match('/[^\x00-\x7F]/u', (string)$title) && !preg_match('/[^\x00-\x7F]/u', (string)$base))) {
                        $base = $title;
                    }
                    $slug_final = reeid_sanitize_native_slug($base);
                    $slug_final = wp_unique_post_slug(
                        $slug_final,
                        $tid,
                        $mode === 'publish' ? 'publish' : 'draft',
                        $root_post->post_type,
                        $root_post->post_parent
                    );

                    $new_post = [
                        'ID'           => $tid,
                        'post_title'   => $title,
                        'post_content' => $content,
                        'post_status'  => $mode,
                        'post_type'    => $root_post->post_type,
                        'post_name'    => $slug_final,
                        'post_author'  => $root_post->post_author,
                        'post_excerpt' => $excerpt,
                    ];
                    $tid2 = function_exists('reeid_safe_wp_update_post')
                        ? reeid_safe_wp_update_post($new_post, true)
                        : wp_update_post($new_post, true);

                    if (is_wp_error($tid2)) {
                        $details[]      = strtoupper($lang) . ': ❌ ' . $tid2->get_error_message();
                        $results[$lang] = ['success' => false, 'error' => 'update_failed', 'message' => $tid2->get_error_message()];
                        reeid__bulk_release_lock($lock_key);
                        continue;
                    }

                    // WooCommerce attribute translation (if product)
                    if (
                        get_post_type($tid2) === 'product' &&
                        class_exists('WC_Product') &&
                        function_exists('reeid_translate_product_attributes')
                    ) {
                        $src_product = wc_get_product($post_id);
                        $dst_product = wc_get_product($tid2);
                        $src_l = $source_lang;
                        $dst_l = $lang;
                        if ($src_product && $dst_product) {
                            if (empty($tone)) $tone = 'neutral';
                            reeid_translate_product_attributes($src_product, $dst_product, $src_l, $dst_l, $tone);
                        }
                    }

                    // SEO write
                    $src_seo = $read_src_seo($post_id);
                    $src_l   = $resolve_lang($post_id, $source_lang);
                    $tgt_l   = $resolve_lang($tid2, $lang);
                    $title_tr = $src_seo['title'] !== '' ? $translate_short($src_seo['title'], $src_l, $tgt_l) : '';
                    $desc_tr  = $src_seo['desc']  !== '' ? $translate_short($src_seo['desc'],  $src_l, $tgt_l) : '';
                    $write_seo_title($tid2, $title_tr !== '' ? $title_tr : $title);
                    if ($desc_tr !== '') $write_seo_desc($tid2, $desc_tr);

                    update_post_meta($tid2, '_reeid_translation_lang', $lang);
                    update_post_meta($tid2, '_reeid_translation_source', $post_id);

                    $map[$lang]     = $tid2;
                    $details[]      = strtoupper($lang) . ': ✅ ' . __('Done', 'reeid-translate');
                    $results[$lang] = ['success' => true, 'post_id' => $tid2];

                    reeid__bulk_release_lock($lock_key);
                    continue;
                }
            } // end foreach $selected

            /* ---------- Persist final map on SOURCE (single clean merge) ---------- */
            if (! function_exists('reeid_sanitize_translation_map')) {
                function reeid_sanitize_translation_map($m)
                {
                    if (! is_array($m)) return [];
                    $out = [];
                    foreach ($m as $k => $v) {
                        if (! is_string($k) || ! preg_match('/^[a-zA-Z_-]+$/', $k)) continue;
                        if (is_numeric($v) && (int)$v > 0) {
                            $out[$k] = (int)$v;
                        }
                    }
                    return $out;
                }
            }

            $existing_map = (array) get_post_meta($post_id, '_reeid_translation_map', true);
            $existing_map = reeid_sanitize_translation_map($existing_map);
            $map          = is_array($map) ? reeid_sanitize_translation_map($map) : [];
            $merged_map   = array_merge($existing_map, $map);
            update_post_meta($post_id, '_reeid_translation_map', $merged_map);

            wp_send_json_success([
                'message' => __('Bulk translations completed', 'reeid-translate'),
                'details' => $details,
                'results' => $results,
            ]);
        }
    endif;




/* ===========================================================
 * WC RUNTIME (INLINE TRANSLATIONS) — non-duplicating products
 * Priority >1000 to override earlier fallbacks safely.
 * =========================================================== */

    if (function_exists('is_product')) {

        // 3) Safety net for themes that still read the_content on single product
        add_filter('the_content', function ($content) {
            try {
                if (is_admin() || ! is_product()) return $content;
                global $post;
                if (! $post || $post->post_type !== 'product') return $content;

                $id   = (int) $post->ID;
                $lang = function_exists('reeid_wc_current_lang') ? reeid_wc_current_lang('en')
                    : (function_exists('reeid_current_language') ? reeid_current_language('en') : 'en');

                $src  = (int) get_post_meta($id, '_reeid_translation_source', true);
                if (! $src) $src = $id;

                if (function_exists('reeid_wc_get_translation_meta')) {
                    $meta = (array) reeid_wc_get_translation_meta($src, $lang);
                    if (! empty($meta['content'])) return (string) $meta['content'];
                }
            } catch (\Throwable $e) { /* swallow */
            }
            return $content;
        }, 1100);
    }

    /* ========================================================================
    Generates nonce only when WordPress admin is fully loaded
    and injects the inline JS via admin_enqueue_scripts.
========================================================================*/
    if (! function_exists('reeid_admin_validate_key_script')) {
        function reeid_admin_validate_key_script($hook)
        {
            // Only on our settings page (best-effort)
            if ('settings_page_reeid-translate-settings' !== $hook) {
                return;
            }

            // Ensure pluggable functions are available
            if (! function_exists('wp_create_nonce')) {
                return;
            }

            $nonce = wp_create_nonce('reeid_translate_nonce_action');
            // Inline JS: uses ajaxurl (admin pages) and sends the nonce safely
            $js = <<<JS
jQuery(function(){
    var statusBox = document.getElementById('reeid_openai_key_status');
    document.getElementById('reeid_validate_openai_key')?.addEventListener('click', function (e) {
        e.preventDefault();
        if (statusBox) statusBox.innerHTML = '⏳ Validating...';
        var keyEl = document.getElementById('reeid_openai_api_key');
        var key = keyEl ? keyEl.value : '';
        fetch(ajaxurl, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
    action: 'reeid_validate_openai_key',
    key: key,
    _wpnonce: '{$nonce}',
    _ajax_nonce: '{$nonce}'
})

        }).then(function(res){ return res.json(); })
          .then(function(data){
              if (statusBox) {
                  if (data && data.success) {
                      statusBox.innerHTML = '<span style="color:green;font-weight:bold;">✔ Valid API Key</span>';
                  } else {
                      statusBox.innerHTML = '<span style="color:red;font-weight:bold;">❌ Invalid API Key</span>';
                  }
              }
          }).catch(function(){
              if (statusBox) statusBox.innerHTML = '<span style="color:red;">❌ AJAX failed</span>';
          });
    });
});
JS;

            // Register an inert script handle and attach our inline script to it
            wp_register_script('reeid-validate-key', false);
            wp_enqueue_script('reeid-validate-key');
            wp_add_inline_script('reeid-validate-key', $js);
            // register + enqueue the elementor wiring script (depends on localized handle)
wp_register_script( 'reeid-elementor-wires', REEID_TRANSLATE_URL . 'assets/js/reeid-elementor-wires.js', array('jquery','reeid-translate-localize'), $ver, true );
wp_enqueue_script( 'reeid-elementor-wires' );

        }
        add_action('admin_enqueue_scripts', 'reeid_admin_validate_key_script', 20);
    }

/*============================================================================================
  SECTION 23: TRANSLATE FUNCTION — OpenAI Wrapper with Collectors (LEGACY, FORCE NATIVE SLUG)
============================================================================================*/

    /**
     * Legacy translator using direct OpenAI call.
     * Keeps native-script slug handling.
     */
    function reeid_translate_via_openai_with_slug_legacy(
        $source_lang,
        $target_lang,
        $title,
        $content,
        $slug,
        $tone = 'Neutral',
        $prompt_override = ''
    ) {
        $source_lang = sanitize_text_field($source_lang);
        $target_lang = sanitize_text_field($target_lang);
        $title       = trim(wp_strip_all_tags($title));
        $content     = trim($content); // allow HTML
        $slug        = reeid_sanitize_native_slug($slug);
        $tone        = sanitize_text_field($tone);
        $prompt_override = trim($prompt_override);

        // --- System prompt enforces TITLE, SLUG, CONTENT (strict) ---
        $system_prompt = "You are a professional website translator. Translate from {$source_lang} to {$target_lang} for a multilingual WordPress website. "
            . "Translate TITLE, SLUG, and CONTENT as follows:\n"
            . "- TITLE: Full translation, human-sounding, natural.\n"
            . "- SLUG: ALWAYS generate a native-script or transliterated version for the target language. "
            . "Never return English/Latin unless the target language is English. "
            . "The slug must reflect the translated title, be short, SEO-friendly, and in the target script if supported. "
            . "Do not copy or reuse the English slug. If the language requires, transliterate the title into a slug using the target script.\n"
            . "- CONTENT: Full translation. Preserve all HTML and formatting.\n"
            . "Return strictly in this format (nothing else):\n"
            . "TITLE: ...\n"
            . "SLUG: ...\n"
            . "CONTENT:\n...";

        if (!empty($prompt_override)) {
            $system_prompt .= "\nAdditional instructions: {$prompt_override}";
        }

        $user_message = "TITLE:\n{$title}\n\nSLUG:\n{$slug}\n\nCONTENT:\n{$content}";

        $api_key = sanitize_text_field(get_option('reeid_openai_api_key'));
        $model   = sanitize_text_field(get_option('reeid_openai_model', 'gpt-4o'));

        $resp = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'    => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user',   'content' => $user_message],
                ],
                'temperature' => 0.2,
                'max_tokens'  => 4000,
            ]),
            'timeout'         => 180,
            'connect_timeout' => 30,
            'blocking'        => true,
        ]);

        if (is_wp_error($resp)) {
            return [
                'success' => false,
                'error'   => $resp->get_error_message(),
            ];
        }

        $body    = wp_remote_retrieve_body($resp);
        $json    = json_decode($body, true);
        $ai_text = $json['choices'][0]['message']['content'] ?? '';

        if (empty($ai_text)) {
            return ['success' => false, 'error' => 'Empty OpenAI response'];
        }

        // --- Parse response ---
        preg_match('/TITLE:\s*(.*?)\n/i', $ai_text, $m_title);
        preg_match('/SLUG:\s*(.*?)\n/i', $ai_text, $m_slug);
        preg_match('/CONTENT:\s*([\s\S]+)/i', $ai_text, $m_content);

        $translated_title = trim($m_title[1] ?? $title);
        $translated_slug  = trim($m_slug[1] ?? $slug);
        $translated_slug  = reeid_sanitize_native_slug($translated_slug);

        if (empty($translated_slug) && !empty($translated_title)) {
        $translated_slug = reeid_sanitize_native_slug($translated_title);
        }
        if (empty($translated_slug)) {
            $translated_slug = $slug;
        }

        return [
            'success' => true,
            'title'   => $translated_title,
            'slug'    => $translated_slug,
            'content' => trim($m_content[1] ?? $ai_text),
        ];
    }

/*==============================================================================
 SECTION 24: INJECT BLOCK ALERTS INTO ELEMENTOR EDITOR PANEL (iframe safe)
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
                                    if (/v1/translate.*(already|child).*translation/i.test(message)) {
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
                                    if (/v1/translate.*(already|child).*translation/i.test(message)) {
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

/*==============================================================================
 SECTION 25 : FRONT-END SWITCHER ASSETS (CLEANED for Modern CSS)
==============================================================================*/

    add_action('wp_enqueue_scripts', 'reeid_enqueue_switcher_assets');
    function reeid_enqueue_switcher_assets()
    {
        wp_enqueue_style(
            'reeid-switcher-style',
            plugins_url('assets/css/switcher.css', __FILE__),
            [],
            '1.0'
        );
        wp_enqueue_script(
            'reeid-switcher-script',
            plugins_url('assets/js/switcher.js', __FILE__),
            ['jquery'],
            '1.0',
            true
        );
    }

    // Optional: Only inject dynamic alignment (center/left/right) as needed.
    add_action('wp_head', 'reeid_inject_switcher_alignment');
    function reeid_inject_switcher_alignment()
    {
        $align = esc_attr(get_option('reeid_switcher_alignment', 'center'));
        // Only output if not default, or if you want to let admin override.
        if (in_array($align, ['left', 'center', 'right'], true)) {
            printf(
                '<style id="reeid-switcher-alignment-css">#reeid-switcher-container { text-align: %s; }</style>',
                esc_html($align)
            );
        }
    }

/*===========================================================================
  SECTION 26 : FINAL REWRITE 
  (LANGUAGE-PREFIXED URLs with native-slug decoding + Unicode query slugs)
 ===========================================================================*/

    // 1) Add our custom query var for language codes
    add_filter('query_vars', function ($vars) {
        $vars[] = 'reeid_lang_code';
        return $vars;
    }, 10, 1);

    // 2) Decode percent-encoded slugs 
    add_filter('request', function ($vars) {
        if (! empty($vars['reeid_lang_code']) && ! empty($vars['name'])) {
            $vars['name'] = rawurldecode($vars['name']);
        }
        return $vars;
    }, 10, 1);

    // 2a) Allow Unicode slug in the “name” query var
    add_filter('sanitize_title_for_query', function ($title, $raw_title, $context) {
        if ('query' === $context) {
            return $raw_title;
        }
        return $title;
    }, 10, 3);

    // 3) Inject language-prefix rules at the top of WP's rewrite rules
    add_filter('rewrite_rules_array', function ($rules) {
        //reeid_debug_log( 'REWRITE_RULES_IN', array_slice( $rules, 0, 5, true ) );

        $raw = function_exists('reeid_get_enabled_languages')
            ? reeid_get_enabled_languages()
            : array('en' => 'English', 'zh' => '中文', 'ar' => 'العربية');

        // Normalize as code list
        $langs = [];
        if (is_array($raw)) {
            $keys = array_keys($raw);
            $langs = ($keys === range(0, count($raw) - 1)) ? array_values($raw) : $keys;
        } else {
            $langs = ['en', 'zh', 'ar'];
        }
        //reeid_debug_log( 'LANG_LIST', $langs );

        $new = [];
        foreach ($langs as $lang) {
            $pattern = "^{$lang}/([^/]+)/?$";
            $query   = "index.php?name=\$matches[1]&reeid_lang_code={$lang}";
            $new[$pattern] = $query;
            //reeid_debug_log( 'ADD_RULE', compact( 'lang', 'pattern', 'query' ) );
        }

        $merged = $new + $rules;
        //reeid_debug_log( 'REWRITE_RULES_OUT', array_slice( $merged, 0, 5, true ) );
        return $merged;
    }, 10, 1);

    // 4) Prefix all translated permalinks with /{lang}/{decoded-slug}/
    add_filter('post_link', 'reeid_prefix_permalink', 10, 2);
    add_filter('page_link', 'reeid_prefix_permalink', 10, 2);
    function reeid_prefix_permalink($permalink, $post)
    {
        if (! is_object($post)) {
            $post = get_post($post);
            if (! $post) {
                //reeid_debug_log( 'PERMALINK_NO_POST', func_get_args() );
                return $permalink;
            }
        }

        // Skip originals (default language) and posts with no translation_lang
        $lang = get_post_meta($post->ID, '_reeid_translation_lang', true) ?: '';
        if (! $lang) {
            return $permalink;
        }

        // Decode percent-encoding so native characters remain
        $decoded = rawurldecode($post->post_name);
        //reeid_debug_log( 'PREFIX_SLUG', array( 'lang' => $lang, 'decoded' => $decoded ) );

        $home = untrailingslashit(home_url());
        return "{$home}/{$lang}/{$decoded}/";
    }


 /*==============================================================================
  SECTION 27 : WooCommerce — Language-Prefixed Product Permalinks
  - Accepts /{lang}/product/{slug}/ and resolves to the product.
  - Sets language cookie from rewritten query var so inline runtime uses it.
  - One-time rewrite flush with nuke-debug trace.
==============================================================================*/

    /** Nuke-debug for this section (writes to uploads/reeid-debug.log if available) */
    if (! function_exists('reeid_s241_log')) {
        function reeid_s241_log($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S24.1 ' . $label, $data);
            }
        }
    }

    /** Allow our rewritten query var */
    add_filter('query_vars', function (array $qv) {
        $qv[] = 'reeid_force_lang';
        return $qv;
    });

    /**
     * Register rewrite rules for language-prefixed product permalinks.
     * - If product base is simple (e.g. "product"), map:
     *     ^{lang}/{base}/{slug}/?$  ->  index.php?post_type=product&name={slug}&reeid_force_lang={lang}
     * - If product base includes tokens (e.g. %product_cat%), install a broad fallback.
     *   (Product-cat-based structures can be added later if needed.)
     * - Flush once (tracked by option).
     */
    add_action('init', function () {
        // Detect WooCommerce product base
        $wc_permalinks = get_option('woocommerce_permalinks', []);
        $product_base  = (is_array($wc_permalinks) && !empty($wc_permalinks['product_base']))
            ? ltrim((string) $wc_permalinks['product_base'], '/')
            : 'product';

        // Simple base ==> precise rule
        if (strpos($product_base, '%') === false) {
            $regex = '^([a-z]{2}(?:-[a-zA-Z]{2})?)/' . preg_quote($product_base, '#') . '/([^/]+)/?$';
            $dest  = 'index.php?post_type=product&name=$matches[2]&reeid_force_lang=$matches[1]';
            add_rewrite_rule($regex, $dest, 'top');
            reeid_s241_log('RULE_ADDED', ['base' => $product_base, 'regex' => $regex]);
        } else {
            // Fallback: strip the lang prefix and let the rest route as a page (best-effort).
            // (If you use %product_cat% in the base, consider adding a dedicated rule later.)
            $regex = '^([a-z]{2}(?:-[a-zA-Z]{2})?)/(.*)$';
            $dest  = 'index.php?pagename=$matches[2]&reeid_force_lang=$matches[1]';
            add_rewrite_rule($regex, $dest, 'top');
            reeid_s241_log('RULE_FALLBACK', ['base' => $product_base, 'regex' => $regex]);
        }

        // One-time flush to activate rules (safe, guarded)
        $ver = (int) get_option('reeid_s241_rules', 0);
        if ($ver < 1) {
            flush_rewrite_rules(false);
            update_option('reeid_s241_rules', 1);
            reeid_s241_log('FLUSHED', true);
        }
    }, 9);

    /**
     * - Language cookie — canonical & duplicate-safe.
     * - If ?reeid_force_lang=xx is present, set exactly one good cookie.
     * - Skip if MU already handled it (RT_LANG_COOKIE_SET).
     * - If a queued Set-Cookie for site_lang already has Path=/, skip.
     * - If a queued Set-Cookie uses a wrong Path (e.g. "reeid.com" or non-/),
     *   emit an *expire* for that specific path and then set the canonical one.
     * - Robust against bad COOKIEPATH without requiring wp-config edits.
     */

    /** Inspect currently queued Set-Cookie headers for site_lang and extract paths. */
    if (! function_exists('reeid_inspect_lang_cookie_headers')) {
        function reeid_inspect_lang_cookie_headers(): array
        {
            $has = false;
            $good = false;
            $paths = [];
            foreach (headers_list() as $h) {
                if (stripos($h, 'Set-Cookie:') !== 0) continue;
                if (stripos($h, 'site_lang=') === false) continue;

                $has = true;

                // Try to extract "path=..." (case-insensitive).
                if (preg_match('~;\s*path=([^;]+)~i', $h, $m)) {
                    $p = trim($m[1]);
                    $paths[] = $p;
                    if ($p === '/') {
                        $good = true;
                    }
                }
            }
            return ['has' => $has, 'good' => $good, 'paths' => array_values(array_unique($paths))];
        }
    }

    /** Set the canonical cookie (Path=/, Lax, secure/httponly), optionally expiring known-bad paths first. */
    if (! function_exists('reeid_set_lang_cookie_canonical')) {
        function reeid_set_lang_cookie_canonical(string $lang, array $expire_paths = []): void
        {
            if (headers_sent()) return;

            $lang   = strtolower(substr(sanitize_text_field($lang), 0, 10));
            if ($lang === '') return;

            $domain = (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) ? COOKIE_DOMAIN : '';

            // If WordPress was misconfigured, also expire that specific bad path.
            if (defined('COOKIEPATH') && COOKIEPATH && COOKIEPATH !== '/' && !in_array(COOKIEPATH, $expire_paths, true)) {
                $expire_paths[] = COOKIEPATH;
            }
            // (Optional) If you *know* a literal "reeid.com" path was previously sent, expire it.
            if (defined('REEID_EXPIRE_HOSTPATH') && REEID_EXPIRE_HOSTPATH && !in_array('reeid.com', $expire_paths, true)) {
                $expire_paths[] = 'reeid.com';
            }

            foreach ($expire_paths as $bp) {
                // Only expire non-root paths; expiring "/" would wipe the good cookie too.
                if ($bp && $bp !== '/') {
                    @setcookie('site_lang', '', [
                        'expires'  => time() - 3600,
                        'path'     => $bp,
                        'domain'   => $domain,
                        'secure'   => is_ssl(),
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                }
            }

            // Set the canonical cookie.
            setcookie('site_lang', $lang, [
                'expires'  => time() + DAY_IN_SECONDS,
                'path'     => '/',
                'domain'   => $domain,   // host-only if ''
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            // Make available immediately during this request.
            $_COOKIE['site_lang'] = $lang;

            if (!headers_sent()) header('X-RT-LangCookie: 1');

            if (function_exists('reeid_s241_log')) {
                reeid_s241_log('SET_COOKIE_CANON', $lang);
            }

            // Mark so later hooks in this request can skip.
            if (!defined('REEID_LANG_COOKIE_CANONICAL_SET')) {
                define('REEID_LANG_COOKIE_CANONICAL_SET', true);
            }
        }
    }


/*==============================================================================
  SECTION 27.1 : WooCommerce — Checkout/Cart URL Guard + Misassignment Detector
  - If "Proceed to checkout" or "View cart" resolves to a product URL, fix it.
  - Logs misassignment so you can see the culprit quickly.
  - Also shows a small admin notice if Checkout/Cart are pointing to the wrong type.
  - Does NOT touch your translation runtime (Elementor/Gutenberg safe).
==============================================================================*/

    if (! function_exists('reeid_s243_log')) {
        function reeid_s243_log($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S24.3 ' . $label, $data);
            }
        }
    }

    /** Helper: detect product-like URL paths (with or without lang prefix) */
    if (! function_exists('reeid_s243_is_product_url')) {
        function reeid_s243_is_product_url(string $url): bool
        {
            $p = wp_parse_url($url);
            if (empty($p['path'])) return false;
            return (bool) preg_match('#/(?:[a-z]{2}(?:-[a-zA-Z]{2})?/)?product/[^/]+/?$#', $p['path']);
        }
    }

    /** Guard checkout URL */
    add_filter('woocommerce_get_checkout_url', function ($url) {
        try {
            $orig = $url;
            if (reeid_s243_is_product_url($url)) {
                $checkout_id = wc_get_page_id('checkout');
                $fixed = $checkout_id > 0 ? get_permalink($checkout_id) : home_url('/checkout/');
                reeid_s243_log('CHECKOUT_URL_BROKEN', ['got' => $orig, 'fix' => $fixed, 'checkout_id' => $checkout_id, 'type' => get_post_type($checkout_id)]);
                if ($fixed) {
                    $url = $fixed;
                }
            }
        } catch (\Throwable $e) {
            reeid_s243_log('CHECKOUT_URL_ERR', $e->getMessage());
        }
        return $url;
    }, 99);

    /** Guard cart URL (just in case) */
    add_filter('woocommerce_get_cart_url', function ($url) {
        try {
            $orig = $url;
            if (reeid_s243_is_product_url($url)) {
                $cart_id = wc_get_page_id('cart');
                $fixed = $cart_id > 0 ? get_permalink($cart_id) : home_url('/cart/');
                reeid_s243_log('CART_URL_BROKEN', ['got' => $orig, 'fix' => $fixed, 'cart_id' => $cart_id, 'type' => get_post_type($cart_id)]);
                if ($fixed) {
                    $url = $fixed;
                }
            }
        } catch (\Throwable $e) {
            reeid_s243_log('CART_URL_ERR', $e->getMessage());
        }
        return $url;
    }, 99);

    /** Admin notice if Checkout/Cart are mis-assigned */
    add_action('admin_init', function () {
        if (! current_user_can('manage_woocommerce')) return;

        $notices = [];

        $cid = wc_get_page_id('checkout');
        if ($cid && get_post_type($cid) !== 'page') {
            $notices[] = 'Checkout page is assigned to a non-Page (e.g., a product).';
            reeid_s243_log('MISASSIGN_CHECKOUT', ['id' => $cid, 'type' => get_post_type($cid), 'url' => get_permalink($cid)]);
        }
        $cart = wc_get_page_id('cart');
        if ($cart && get_post_type($cart) !== 'page') {
            $notices[] = 'Cart page is assigned to a non-Page (e.g., a product).';
            reeid_s243_log('MISASSIGN_CART', ['id' => $cart, 'type' => get_post_type($cart), 'url' => get_permalink($cart)]);
        }

        if ($notices) {
            add_action('admin_notices', function () use ($notices) {
                $link = esc_url(admin_url('admin.php?page=wc-settings&tab=advanced'));
                echo '<div class="notice notice-error"><p><strong>WooCommerce page assignment issue:</strong></p><ul>';
                foreach ($notices as $n) {
                    echo '<li>' . esc_html($n) . '</li>';
                }
                echo '</ul><p>Fix in <a href="' . $link . '">WooCommerce → Settings → Advanced (Page setup)</a>.</p></div>';
            });
        }
    });

/*==============================================================================
  SECTION 28 : License Gate — Woo Translation Options (admin + runtime)
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

    /** Block saving inline translations when unlicensed */
    add_action('woocommerce_admin_process_product_object', function (\WC_Product $product) {
        if (! reeid_s247_gate_active()) return;
        if (isset($_POST['reeid_tr'])) {
            unset($_POST['reeid_tr']);
            reeid_s247_log('SAVE_BLOCKED', ['post' => $product->get_id()]);
        }
    }, 1, 1);

    /** Defense-in-depth: prevent meta writes to our keys */
    add_filter('update_post_metadata', function ($check, $object_id, $meta_key, $meta_value) {
        if (! reeid_s247_gate_active()) return $check;
        if (is_string($meta_key) && ($meta_key === '_reeid_wc_inline_langs' || strpos($meta_key, '_reeid_wc_tr_') === 0)) {
            reeid_s247_log('META_BLOCKED', ['post' => $object_id, 'key' => $meta_key]);
            return true; // short-circuit: claim success but do nothing
        }
        return $check;
    }, 1, 4);

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

        // (C) Content areas some themes use
        add_filter('the_content', function ($content) {
            if (defined('REEID_LAYOUT_SAFE') && REEID_LAYOUT_SAFE) return $content;

            if (is_admin() || ! function_exists('is_product') || ! is_product()) return $content;
            global $post;
            if ($post && $post->post_type === 'product') {
                return (string) $post->post_content;
            }
            return $content;
        }, 5);

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

        $settings = esc_url(admin_url('options-general.php'));
        echo '<div class="notice notice-warning"><p><strong>REEID Translation:</strong> Woo translation options are disabled until a valid license is activated.</p>';
        echo '<p>Enter or activate your license in <a href="' . $settings . '">Settings</a>. If you already have a valid license, ensure the status option <code>reeid_license_status</code> is set to <code>valid</code>.</p></div>';
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



/*==============================================================================
 SECTION 28 : UNIVERSAL MENU LINK SPINNER (LANGUAGE FILTER + REWRITE)
==============================================================================*/

    add_filter('wp_nav_menu_objects', function ($items, $args) {
        if (!function_exists('reeid_current_language')) {
            return $items;
        }

        $lang = sanitize_text_field(reeid_current_language());
        $filtered = [];

        foreach ($items as $item) {
            if (is_object($item) && property_exists($item, "object") && property_exists($item, "object_id") && in_array($item->object, ["page", "post"], true) && !empty($item->object_id)) {
                $item_lang = get_post_meta($item->object_id, '_reeid_translation_lang', true) ?: 'en';

                if ($item_lang === $lang) {
                    $slug = get_post_field('post_name', $item->object_id);

                    $item->url = ($lang === 'en')
                        ? home_url("/{$slug}/")
                        : home_url("/{$lang}/{$slug}/");

                    $filtered[] = $item;
                }
            } else {
                $filtered[] = $item; // Keep all other items
            }
        }

        return $filtered;
    }, 27, 2);

    // Helper: detect current language
    function reeid_current_language()
    {
        // 1. From query vars
        $f = get_query_var('reeid_lang_front');
        if (!empty($f)) {
            return sanitize_text_field($f);
        }

        $c = get_query_var('reeid_lang_code');
        if (!empty($c)) {
            return sanitize_text_field($c);
        }

        // 2. From cookie
        if (!empty($_COOKIE['site_lang'])) {
            return sanitize_text_field(wp_unslash($_COOKIE['site_lang']));
        }

        // 3. From URL path
        if (isset($_SERVER['REQUEST_URI']) && !empty($_SERVER['REQUEST_URI'])) {
            $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));
            if (preg_match('#^/([a-z]{2})(/|$)#', $request_uri, $m)) {
                $code = strtolower($m[1]);
                $langs = array_keys(reeid_get_supported_languages());
                if (in_array($code, $langs, true)) {
                    return $code;
                }
            }
        }


        // 4. Fallback
        return 'en';
    }


/*==============================================================================
  SECTION 29 - Admin List Column: Source Post
 * Adds a "Source Post" column to Pages and Posts admin list tables.
 * Shows link to source if current item is translation, otherwise marks as source.
==============================================================================*/

    // Add column header
    add_filter('manage_page_posts_columns', function ($columns) {
        $columns['reeid_source_post'] = __('Source Post', 'reeid-translate');
        return $columns;
    });
    add_filter('manage_post_posts_columns', function ($columns) {
        $columns['reeid_source_post'] = __('Source Post', 'reeid-translate');
        return $columns;
    });

    // Render column content
    add_action('manage_page_posts_custom_column', function ($column, $post_id) {
        if ($column === 'reeid_source_post') {
            $src = (int) get_post_meta($post_id, '_reeid_translation_source', true);
            if ($src && $src !== $post_id) {
                $title = get_the_title($src) ?: __('(no title)', 'reeid-translate');
                $url   = get_edit_post_link($src);
                echo $url
                    ? '<a href="' . esc_url($url) . '">' . esc_html($title) . '</a>'
                    : esc_html($title);
            } else {
                // current is the source
                echo '<em>' . __('This is source post', 'reeid-translate') . '</em>';
            }
        }
    }, 10, 2);

    add_action('manage_post_posts_custom_column', function ($column, $post_id) {
        if ($column === 'reeid_source_post') {
            $src = (int) get_post_meta($post_id, '_reeid_translation_source', true);
            if ($src && $src !== $post_id) {
                $title = get_the_title($src) ?: __('(no title)', 'reeid-translate');
                $url   = get_edit_post_link($src);
                echo $url
                    ? '<a href="' . esc_url($url) . '">' . esc_html($title) . '</a>'
                    : esc_html($title);
            } else {
                echo '<em>' . __('This is source post', 'reeid-translate') . '</em>';
            }
        }
    }, 10, 2);



 /*==============================================================================
     SECTION 30 — Make "Source Post" column sortable and add filter dropdown
 * - Sorts by meta _reeid_translation_source (numeric)
 * - Adds dropdown to filter by source post or show only source posts
 *==============================================================================*/

    /* 1) Register the column as sortable for posts and pages */
    add_filter('manage_edit-post_sortable_columns', function ($cols) {
        $cols['reeid_source_post'] = 'reeid_source_post';
        return $cols;
    });
    add_filter('manage_edit-page_sortable_columns', function ($cols) {
        $cols['reeid_source_post'] = 'reeid_source_post';
        return $cols;
    });

    /* 2) Adjust query when sorting by our column */
    add_action('pre_get_posts', function ($query) {
        if (! is_admin() || ! $query->is_main_query()) {
            return;
        }

        $orderby = $query->get('orderby');
        if ('reeid_source_post' === $orderby) {
            // Sort by numeric meta value (source post ID)
            $query->set('meta_key', '_reeid_translation_source');
            // meta_value_num will treat missing values as 0
            $query->set('orderby', 'meta_value_num ID');
        }

        // Handle our custom filter parameter (from the dropdown)
        $filter = filter_input(INPUT_GET, 'reeid_source_filter', FILTER_SANITIZE_STRING);
        if ($filter) {
            // Only apply to posts/pages list screens
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen && in_array($screen->id, array('edit-post', 'edit-page'), true)) {
                global $wpdb;

                if ('is_source' === $filter) {
                    // Show only source posts: those that do NOT have a translation-source meta.
                    $meta_query = array(
                        'relation' => 'OR',
                        array(
                            'key'     => '_reeid_translation_source',
                            'compare' => 'NOT EXISTS',
                        ),
                        array(
                            'key'     => '_reeid_translation_source',
                            'value'   => '',
                            'compare' => '=',
                        ),
                    );
                    $query->set('meta_query', $meta_query);
                } elseif ('has_source' === $filter) {
                    // Show only translations (posts that have the meta set)
                    $query->set('meta_key', '_reeid_translation_source');
                    $query->set('meta_compare', 'EXISTS'); // keep simple - meta exists
                } elseif (preg_match('/^\d+$/', $filter)) {
                    // Filter translations whose source == $filter (source post ID)
                    $query->set('meta_key', '_reeid_translation_source');
                    $query->set('meta_value', intval($filter));
                    $query->set('meta_compare', '=');
                }
            }
        }
    });

    /* 3) Add the dropdown above the list table (posts/pages) */
    add_action('restrict_manage_posts', function () {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen || ! in_array($screen->id, array('edit-post', 'edit-page'), true)) {
            return;
        }

        // Gather distinct source IDs from postmeta
        global $wpdb;
        $meta_key = '_reeid_translation_source';
        $sql = $wpdb->prepare(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
            $meta_key
        );
        $rows = $wpdb->get_col($sql);

        // Build options array: special + per-source post
        $options = array();
        $options[''] = __('All posts', 'reeid-translate');
        $options['is_source'] = __('Show only source posts', 'reeid-translate');
        $options['has_source'] = __('Show only translations', 'reeid-translate');

    
        // current selection
        $current = filter_input(INPUT_GET, 'reeid_source_filter', FILTER_SANITIZE_STRING);

        echo '<select name="reeid_source_filter" id="reeid_source_filter" style="margin-left:6px">';
        foreach ($options as $val => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($val),
                selected((string) $val, (string) $current, false),
                esc_html($label)
            );
        }
        echo '</select>';
    });


/*==============================================================================
 SECTION 31 : PRE-QUERY MODIFICATIONS & LANGUAGE ROUTING
==============================================================================*/

    add_action('pre_get_posts', function (WP_Query $q) {
        static $busy = false;
        if ($busy) return;
        $busy = true;

        // Only touch front-end main queries, never admin, feeds, ajax, preview, etc.
        if (
            ! $q->is_main_query() ||
            is_admin() ||
            is_feed() ||
            (defined('REST_REQUEST') && REST_REQUEST) ||
            (defined('DOING_AJAX') && DOING_AJAX) ||
            is_preview() ||
            filter_input(INPUT_GET, 'preview', FILTER_DEFAULT) !== null
        ) {
            $busy = false;
            return;
        }

        // ✅ Get language info early
        $front = sanitize_text_field(get_query_var('reeid_lang_front', ''));
        $code  = sanitize_text_field(get_query_var('reeid_lang_code', ''));

        // ✅ 🛑 SKIP plugin routing if no language prefix or front override
        if (empty($code) && empty($front)) {
            $busy = false;
            return;
        }

        // -- Normal translated front page logic
        if ($front) {
            $default_id = (int) get_option('page_on_front');
            $map        = (array) get_post_meta($default_id, '_reeid_translation_map', true);
            $map['en']  = $default_id;
            $target_id  = isset($map[$front]) ? (int) $map[$front] : $default_id;
            if ($target_id && get_post_status($target_id)) {
                $q->set('page_id', $target_id);
                $q->set('post_type', 'page');
                $q->set('name', '');
                $q->set('pagename', '');
                $q->is_page       = true;
                $q->is_singular   = true;
                $q->is_front_page = true;
                $q->is_home       = false;
            }
            $busy = false;
            return;
        }

        // -- Normal translated slug logic
        $slug     = get_query_var('name', '');
        $pagename = get_query_var('pagename', '');
        $the_slug = $slug ?: $pagename;

        if ($the_slug && $code) {
            // Meta query is necessary here to match the post to its translation language code.
            // Only one post is ever fetched. This is not run on every page load, only when resolving a translation.
            $found = get_posts([
                'name'           => $the_slug,
                'post_type'      => ['post', 'page'],
                'meta_key'       => '_reeid_translation_lang',
                'meta_value'     => $code,
                'posts_per_page' => 1,
                'no_found_rows'  => true,
            ]);
            if (empty($found)) {
                $busy = false;
                return;
            }

            $target = $found[0];
            $id     = (int) $target->ID;

            if ($target->post_type === 'page') {
                $q->set('page_id', $id);
                $q->set('post_type', 'page');
                $q->set('pagename', $the_slug);
                $q->set('name', '');
                $q->set('meta_query', []);
                $q->is_page       = true;
                $q->is_singular   = true;
                $q->is_front_page = false;
                $q->is_home       = false;
            } else {
                $q->set('name', $the_slug);
                $q->set('post_type', 'post');
                $q->set('meta_query', [
                    [
                        'key'     => '_reeid_translation_lang',
                        'value'   => $code,
                        'compare' => '=',
                    ]
                ]);
                $q->is_single     = true;
                $q->is_singular   = true;
                $q->is_page       = false;
                $q->is_front_page = false;
                $q->is_home       = false;
            }
        }

        $busy = false;
    });



    // 2. DOUBLE-SLASH FIXER
    add_action('template_redirect', 'reeid_fix_double_slash', 0);

    function reeid_fix_double_slash()
    {
        $uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        $path = wp_parse_url($uri, PHP_URL_PATH);
    }


/*==============================================================================
  SECTION 32 : Language Param & Cookie Sync (Global + Woo)
  - Accepts ?lang=xx or ?reeid_force_lang=xx to set `site_lang` cookie.
  - Syncs cookie from URL prefix '/{lang}/...' on every request.
  - Cleans the URL by redirecting (302) to the same path without the query param.
  - Adds detailed logs to trace what was applied.
==============================================================================*/

    if (! function_exists('reeid_langdbg')) {
        function reeid_langdbg($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S26.7 ' . $label, $data);
            }
        }
    }

    /**
     * Normalize a candidate language code: lowercases and caps to 10 chars.
     */
    if (! function_exists('reeid_normalize_lang')) {
        function reeid_normalize_lang($val): string
        {
            $val = strtolower(substr(trim((string)$val), 0, 10));
            return preg_match('/^[a-z]{2}([-_][a-z0-9]{2})?$/i', $val) ? $val : '';
        }
    }

    /**
     * 1) If ?reeid_force_lang=xx or ?lang=xx present:
     *    - set `site_lang` cookie for a day
     *    - store to $_COOKIE for this request
     *    - redirect to same URL WITHOUT that query param (clean)
     *
     * 2) If no param, but URL prefix '/xx/' exists, set cookie from that.
     */
    add_action('template_redirect', function () {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        // ---- (1) Query param force ----
        $forced = '';
        if (isset($_GET['reeid_force_lang'])) {
            $forced = reeid_normalize_lang(wp_unslash($_GET['reeid_force_lang']));
        } elseif (isset($_GET['lang'])) {
            $forced = reeid_normalize_lang(wp_unslash($_GET['lang']));
        }

        if ($forced) {
            // Normalize/Sanitize forced lang
            $forced = strtolower(substr(sanitize_text_field((string) $forced), 0, 10));

            // Avoid sending duplicate Set-Cookie for site_lang within the same request
            $cookie_already_sent = false;
            if (function_exists('headers_list')) {
                foreach (headers_list() as $hdr) {
                    if (stripos($hdr, 'Set-Cookie: site_lang=') === 0) {
                        $cookie_already_sent = true;
                        break;
                    }
                }
            }

            // Set the cookie if needed (also update $_COOKIE for this request)
            if (! $cookie_already_sent || ! isset($_COOKIE['site_lang']) || $_COOKIE['site_lang'] !== $forced) {
                // Prefer explicit domain if defined; otherwise derive from site URL host
                $domain = (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN)
                    ? COOKIE_DOMAIN
                    : parse_url(home_url(), PHP_URL_HOST);

                // Use PHP 7.3+ options signature for SameSite
                setcookie('site_lang', $forced, [
                    'expires'  => time() + DAY_IN_SECONDS,
                    'path'     => '/',              // keep consistent to prevent dup cookies
                    'domain'   => $domain ?: '',    // host-only cookie if empty
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);

                $_COOKIE['site_lang'] = $forced;
            }

            // Debug hook
            if (function_exists('reeid_langdbg')) {
                reeid_langdbg('FORCE_PARAM', ['set' => $forced]);
            }

            // Build clean URL (drop only the lang params)
            $scheme = is_ssl() ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
            $uri    = $_SERVER['REQUEST_URI'] ?? '/';
            $parts  = wp_parse_url($scheme . '://' . $host . $uri);

            $q = [];
            if (! empty($parts['query'])) {
                parse_str($parts['query'], $q);
                unset($q['lang'], $q['reeid_force_lang']);
            }

            $clean = ($parts['path'] ?? '/');
            if (! empty($q)) {
                $clean .= '?' . http_build_query($q);
            }
            if (! empty($parts['fragment'])) {
                $clean .= '#' . $parts['fragment'];
            }

            // Only redirect if we actually removed a param AND the param was present
            $current = ($parts['path'] ?? '/')
                . (!empty($parts['query']) ? '?' . $parts['query'] : '')
                . (!empty($parts['fragment']) ? '#' . $parts['fragment'] : '');

            // visible query string (what the browser actually sent)
            $queryString = $_SERVER['QUERY_STRING'] ?? '';

            // True only when the lang param was present in the browser-visible URL ($_GET or QUERY_STRING)
            $visibleParamPresent = (strpos($queryString, 'reeid_force_lang=') !== false
                || strpos($queryString, 'lang=') !== false
                || isset($_GET['reeid_force_lang'])
                || isset($_GET['lang']));

            if ($visibleParamPresent && $clean !== $current && ! headers_sent()) {
                wp_safe_redirect($clean, 302, 'reeid-translate');
                exit;
            }

            return;
        }


        // ---- (2) URL prefix -> cookie sync ----
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($uri && preg_match('#^/([a-z]{2}(?:-[a-zA-Z0-9]{2})?)/#', $uri, $m)) {
            $pathLang = reeid_normalize_lang($m[1]);
            if ($pathLang && (empty($_COOKIE['site_lang']) || strtolower($_COOKIE['site_lang']) !== $pathLang)) {

                $_COOKIE['site_lang'] = $pathLang;
                reeid_langdbg('URL_PREFIX_SYNC', ['uri' => $uri, 'lang' => $pathLang]);
            }
        }
    }, 9);


/*==============================================================================
  SECTION 33 : WooCommerce Inline Translations — Unified Runtime 
==============================================================================*/

    /** Logger */
    if (! function_exists('reeid_wc_unified_log')) {

        }
    

    /** Strong resolver */
    if (! function_exists('reeid_wc_resolve_lang_strong')) {
        function reeid_wc_resolve_lang_strong(): string
        {
            // Prefer global helper if available
            if (function_exists('reeid_current_language')) {
                $l = (string) reeid_current_language();
                if ($l) return strtolower(substr($l, 0, 10));
            }

            // Forced via param (Section 26.7 also handles redirect+cookie)
            if (isset($_GET['reeid_force_lang'])) {
                $forced_raw = wp_unslash($_GET['reeid_force_lang']);
                $forced     = strtolower(substr(sanitize_text_field((string) $forced_raw), 0, 10));
                if ($forced !== '') {
                    // Optional allowlist
                    if (! function_exists('reeid_is_allowed_lang') || reeid_is_allowed_lang($forced)) {
                        // Set cookie if needed
                        $cookie_already_sent = false;
                        if (function_exists('headers_list')) {
                            foreach (headers_list() as $hdr) {
                                if (stripos($hdr, 'Set-Cookie: site_lang=') === 0) {
                                    $cookie_already_sent = true;
                                    break;
                                }
                            }
                        }
                        $domain = (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN)
                            ? COOKIE_DOMAIN
                            : parse_url(home_url(), PHP_URL_HOST);

                        if (! headers_sent() && (! $cookie_already_sent || ! isset($_COOKIE['site_lang']) || $_COOKIE['site_lang'] !== $forced)) {
                            setcookie('site_lang', $forced, [
                                'expires'  => time() + DAY_IN_SECONDS,
                                'path'     => '/',
                                'domain'   => $domain ?: '',
                                'secure'   => is_ssl(),
                                'httponly' => true,
                                'samesite' => 'Lax',
                            ]);
                        }
                        $_COOKIE['site_lang'] = $forced;

                        if (function_exists('reeid_wc_unified_log')) {
                            reeid_wc_unified_log('FORCE_PARAM', $forced);
                        }
                        return $forced;
                    }
                }
            }

            // URL prefix (/{lang}/...)
            $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
            if ($uri && preg_match('#^/([a-z]{2}(?:-[a-zA-Z0-9]{2,8})?)/#', $uri, $m)) {
                $pathLang = strtolower(substr($m[1], 0, 10));
                if ($pathLang) return $pathLang;
            }

            // Cookie
            if (! empty($_COOKIE['site_lang'])) {
                $ck = strtolower(substr(sanitize_text_field(wp_unslash($_COOKIE['site_lang'])), 0, 10));
                if ($ck) return $ck;
            }

            return 'en';
        }
    }

    /** Request-scope payload cache (get/set) */
    if (! function_exists('reeid_wc_payload_cache')) {
        function reeid_wc_payload_cache(int $product_id, string $lang, ?array $payload = null)
        {
            static $bucket = [];
            $k = $product_id . '|' . strtolower(substr($lang, 0, 10));
            if ($payload !== null) {
                $bucket[$k] = $payload;
                return $payload;
            }
            return $bucket[$k] ?? null;
        }
    }

    /** Reader with back-compat + base fallback (tries new, legacy, then base variants) */
    if (! function_exists('reeid_wc_read_inline_payload')) {
        function _reeid_dup_reeid_wc_read_inline_payload(int $product_id, string $lang): array
        {
            $L = strtolower(substr(trim($lang), 0, 10));
            if ($product_id <= 0 || $L === '') return [];

            $keys = [
                '_reeid_wc_tr_'     . $L,
                '_reeid_wc_inline_' . $L,
            ];

            if (strpos($L, '-') !== false || strpos($L, '_') !== false) {
                $base = preg_split('/[-_]/', $L)[0] ?? '';
                if ($base) {
                    $base = strtolower(substr($base, 0, 10));
                    $keys[] = '_reeid_wc_tr_'     . $base;
                    $keys[] = '_reeid_wc_inline_' . $base;
                }
            }

            foreach ($keys as $meta_key) {
                $val = get_post_meta($product_id, $meta_key, true);

                // PATCH: Decode JSON if string
                if (is_string($val) && strlen($val) && $val[0] === '{') {
                    $decoded = json_decode($val, true);
                    if (is_array($decoded)) $val = $decoded;
                }

                if (is_array($val) && (
                    !empty($val['title']) ||
                    !empty($val['excerpt']) ||
                    !empty($val['content'])
                )) {
                    return $val;
                }
            }
            return [];
        }
    }


    /** Cached getter */
    if (! function_exists('reeid_wc_payload_for_lang')) {
        function reeid_wc_payload_for_lang(int $product_id, string $lang): array
        {
            $cached = reeid_wc_payload_cache($product_id, $lang, null);
            if (is_array($cached)) return $cached;
            $pl = reeid_wc_read_inline_payload($product_id, $lang);
            reeid_wc_payload_cache($product_id, $lang, $pl);
            return $pl;
        }
    }

    /** Runtime swaps (priority 99) */
    add_filter('woocommerce_product_get_name', function ($name, $product) {
        if (is_admin()) return $name;
        try {
            $pid  = (int) $product->get_id();
            $lang = reeid_wc_resolve_lang_strong();
            $pl   = reeid_wc_payload_for_lang($pid, $lang);
            if (! empty($pl['title'])) {
                reeid_wc_unified_log('NAME@99', ['id' => $pid, 'lang' => $lang]);
                return (string)$pl['title'];
            }
        } catch (\Throwable $e) {
            reeid_wc_unified_log('NAME@99/ERR', $e->getMessage());
        }
        return $name;
    }, 99, 2);


    /** Optional last-resort title override (single) */
    add_filter('the_title', function ($title, $post_id) {
        if (is_admin()) return $title;
        try {
            $p = get_post($post_id);
            if (! $p || $p->post_type !== 'product') return $title;
            $lang = reeid_wc_resolve_lang_strong();
            $pl   = reeid_wc_payload_for_lang((int)$post_id, $lang);
            if (! empty($pl['title'])) {
                reeid_wc_unified_log('THETITLE@99', ['id' => $post_id, 'lang' => $lang]);
                return (string)$pl['title'];
            }
        } catch (\Throwable $e) {
            reeid_wc_unified_log('THETITLE@99/ERR', $e->getMessage());
        }
        return $title;
    }, 99, 2);

    /** Theme fallbacks (long/short description) */
    add_filter('the_content', function ($content) {
        if (is_admin() || ! function_exists('is_product') || ! is_product()) return $content;
        global $post;
        if (! $post || $post->post_type !== 'product') return $content;
        $lang = reeid_wc_resolve_lang_strong();
        $pl   = reeid_wc_payload_for_lang((int)$post->ID, $lang);
        if (! empty($pl['content'])) {
            reeid_wc_unified_log('CONTENT@the_content', ['id' => $post->ID, 'lang' => $lang]);
            return (string)$pl['content'];
        }
        return $content;
    }, 99);



    /*==============================================================================
  SECTION 34 : Effective WC Resolver + Late Overrides (priority 199)
==============================================================================*/

    if (! function_exists('reeid_wc_effective_lang')) {
        function reeid_wc_effective_lang(): string
        {
            // 1) GET param wins (same param S26.7 persists)
            if (isset($_GET['reeid_force_lang'])) {
                $forced = strtolower(substr(sanitize_text_field((string) wp_unslash($_GET['reeid_force_lang'])), 0, 10));
                if ($forced && preg_match('/^[a-z]{2}(?:[-_][a-z0-9]{2,8})?$/', $forced)) {
                    return $forced;
                }
            }
            // 2) Cookie
            if (! empty($_COOKIE['site_lang'])) {
                $ck = strtolower(substr(sanitize_text_field((string) wp_unslash($_COOKIE['site_lang'])), 0, 10));
                if ($ck && preg_match('/^[a-z]{2}(?:[-_][a-z0-9]{2,8})?$/', $ck)) {
                    return $ck;
                }
            }
            // 3) URL prefix /xx/
            $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
            if ($uri && preg_match('#^/([a-z]{2}(?:-[a-z0-9]{2,8})?)/#i', $uri, $m)) {
                $px = strtolower(substr($m[1], 0, 10));
                if ($px) return $px;
            }
            // 4) Fallback to any global helper
            if (function_exists('reeid_current_language')) {
                $g = strtolower(substr((string) reeid_current_language(), 0, 10));
                if ($g) return $g;
            }
            return 'en';
        }
    }

    /* Late-run versions of the three swap filters that use our effective resolver.
       Priority 199 guarantees they override earlier 26.U/26.8 filters that might
       still call the stale resolver. Elementor/Gutenberg not touched. */

    add_filter('woocommerce_product_get_name', function ($name, $product) {
        if (is_admin()) return $name;
        $pid  = (int) $product->get_id();
        $lang = reeid_wc_effective_lang();
        // Read using your existing cached getter (tries both _tr_ and _inline_)
        if (function_exists('reeid_wc_payload_for_lang')) {
            $pl = reeid_wc_payload_for_lang($pid, $lang);
            if (!empty($pl['title'])) return (string)$pl['title'];
        }
        return $name;
    }, 199, 2);



/*==============================================================================
  SECTION 35 : Woo Inline - Content Swap (+ request cache)
  - Adds a tiny request cache for payload lookups to reduce duplicate work/logs.
  - Covers themes that render product content using the_content instead of
    WooCommerce getters, and themes that filter short description differently.
==============================================================================*/

    if (! function_exists('reeid_wc_dbg26_8')) {
        function reeid_wc_dbg26_8($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('WC/S26.8 ' . $label, $data);
            }
        }
    }

    /** Request-scope payload cache: get/set */
    if (! function_exists('reeid_wc_payload_cache')) {
        function reeid_wc_payload_cache(int $product_id, string $lang, ?array $payload = null)
        {
            static $bucket = [];
            $k = $product_id . '|' . strtolower(substr($lang, 0, 10));
            if ($payload !== null) {
                $bucket[$k] = $payload;
                return $payload;
            }
            return $bucket[$k] ?? null;
        }
    }

    /**
     * Some themes output long description via the_content on single-product.
     * This high-priority filter swaps in translated content if available.
     */
    add_filter('the_content', function ($content) {
        if (is_admin() || ! function_exists('is_product') || ! is_product()) {
            return $content;
        }
        global $post;
        if (! $post || $post->post_type !== 'product') {
            return $content;
        }

        // Determine language using strong resolver if present
        if (function_exists('reeid_wc_resolve_lang_strong')) {
            $lang = reeid_wc_resolve_lang_strong();
        } else {
            $lang = function_exists('reeid_wc_current_lang') ? reeid_wc_current_lang() : 'en';
        }

        $pl = reeid_wc_payload_for_lang((int)$post->ID, $lang);
        if (! empty($pl['content'])) {
            reeid_wc_dbg26_8('CONTENT@the_content', ['id' => $post->ID, 'lang' => $lang, 'len' => strlen((string)$pl['content'])]);
            return (string) $pl['content'];
        } else {
            reeid_wc_dbg26_8('CONTENT@the_content/MISS', ['id' => $post->ID, 'lang' => $lang]);
        }
        return $content;
    }, 99);

    
/*==============================================================================
  SECTION 36 : WooCommerce Admin — Product “Translations” Tab
  (Table layout: perfect alignment + compact selects + live badge)
==============================================================================*/

    /** Nuke-debug helper */
    if (! function_exists('reeid_s269_log')) {
        function reeid_s269_log($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S26.9 ' . $label, $data);
            }
        }
    }

    /** Helpers */
    if (! function_exists('reeid_s269_lang_norm')) {
        function reeid_s269_lang_norm($val)
        {
            $val = strtolower(substr(trim((string)$val), 0, 10));
            return preg_match('/^[a-z]{2}([-_][a-z0-9]{2})?$/i', $val) ? $val : '';
        }
    }
    if (! function_exists('reeid_s269_supported_langs')) {
        function reeid_s269_supported_langs(): array
        {
            if (function_exists('reeid_get_supported_languages')) {
                $map = (array) reeid_get_supported_languages();
                $out = [];
                foreach ($map as $k => $v) {
                    $out[reeid_s269_lang_norm($k)] = (string) $v;
                }
                return $out;
            }
            return ['en' => 'English'];
        }
    }
    if (! function_exists('reeid_s269_default_lang')) {
        function reeid_s269_default_lang(): string
        {
            $d = (string) get_option('reeid_translation_source_lang', 'en');
            $d = reeid_s269_lang_norm($d);
            return $d ?: 'en';
        }
    }

    /** Add a "Translations" tab to Product Data tabs */
    add_filter('woocommerce_product_data_tabs', function (array $tabs) {
        $tabs['reeid_translations'] = [
            'label'    => __('Translations', 'reeid-translate'),
            'target'   => 'reeid_translations_panel',
            'class'    => ['show_if_simple', 'show_if_variable', 'show_if_external', 'show_if_grouped'],
            'priority' => 81,
        ];
        return $tabs;
    }, 10);

    /** Render the Translations panel (TABLE layout) */
    add_action('woocommerce_product_data_panels', function () {
        global $post;
        if (! $post || $post->post_type !== 'product') {
            return;
        }

        $post_id = (int) $post->ID;
        $default = reeid_s269_default_lang();
        $langs   = reeid_s269_supported_langs();

        // Union: default + inline index
        $inline_index = (array) get_post_meta($post_id, '_reeid_wc_inline_langs', true);
        $codes = array_unique(array_values(array_filter(array_map('reeid_s269_lang_norm', array_merge([$default], $inline_index)))));

        // Build entry map (status + meta)
        $entries = [];
        foreach ($codes as $code) {
            $key  = '_reeid_wc_tr_' . $code;
            $meta = get_post_meta($post_id, $key, true);
            $meta = is_array($meta) ? $meta : [];

            $status = $meta['status'] ?? '';
            if ($status === '') {
                // Heuristic: published if any content exists
                $status = (! empty($meta['title']) || ! empty($meta['content']) || ! empty($meta['excerpt'])) ? 'published' : 'draft';
            }

            $entries[$code] = [
                'label'  => $langs[$code] ?? strtoupper($code),
                'status' => $status, // draft|published|outdated
                'meta'   => $meta,
            ];
        }

        $first_code = array_key_first($entries);
        $initial_status = $first_code ? ($entries[$first_code]['status'] ?? 'draft') : 'draft';

        // Detect if there are ANY translation metas saved (new or legacy)
        $_reeid_has_translations = false;
        $all_meta_keys = array_keys((array) get_post_meta($post_id));
        foreach ($all_meta_keys as $__k) {
            if (strpos($__k, '_reeid_wc_tr_') === 0 || strpos($__k, '_reeid_wc_inline_') === 0) {
                $_reeid_has_translations = true;
                break;
            }
        }

        // Log
        reeid_s269_log('PANEL', ['post_id' => $post_id, 'codes' => array_keys($entries)]);
    ?>
        <div id="reeid_translations_panel" class="panel woocommerce_options_panel">
            <?php wp_nonce_field('reeid_tr_save', 'reeid_tr_nonce'); ?>

            <table class="form-table reeid-tr-table">
                <tbody>
                    <!-- Header: Language + Badge -->
                    <tr>
                        <th scope="row"><label for="reeid-tr-langselect"><?php esc_html_e('Language', 'reeid-translate'); ?></label></th>
                        <td>
                            <select id="reeid-tr-langselect">
                                <?php
                                $first = true;
                                foreach ($entries as $code => $row):
                                    $status = strtolower((string)$row['status']);
                                    $chip   = $status === 'published' ? '✅' : ($status === 'outdated' ? '⚠️' : '•');
                                    $text   = $chip . ' ' . ($row['label']) . ' (' . strtolower($code) . ')';
                                    printf(
                                        '<option value="%s"%s>%s</option>',
                                        esc_attr($code),
                                        selected($first, true, false),
                                        esc_html($text)
                                    );
                                    $first = false;
                                endforeach;
                                ?>
                            </select>

                            <span id="reeid-tr-statusbadge" class="reeid-tr-badge" data-status="<?php echo esc_attr($initial_status); ?>">
                                <span class="reeid-tr-badge-dot"></span>
                                <span class="reeid-tr-badge-text">
                                    <?php
                                    echo esc_html(
                                        $initial_status === 'published'
                                            ? __('Published', 'reeid-translate')
                                            : ($initial_status === 'outdated'
                                                ? __('Outdated', 'reeid-translate')
                                                : __('Draft', 'reeid-translate')
                                            )
                                    );
                                    ?>
                                </span>
                            </span>
                        </td>
                    </tr>

                    <?php if ( $_reeid_has_translations ) : ?>
<tr>
    <th scope="row"><label><?php esc_html_e('Danger zone', 'reeid-translate'); ?></label></th>
    <td>
        <button type="button"
                id="reeid-wc-del-all"
                class="button button-link-delete"
                data-product-id="<?php echo (int) $post_id; ?>"
                data-action-url="<?php echo esc_url( admin_url('admin-post.php') ); ?>"
                data-nonce="<?php echo esc_attr( wp_create_nonce( 'reeid_wc_delete_all' ) ); ?>"
                data-msg="<?php echo esc_attr__( 'Removes every saved translation (title, short/long description, etc.) for all languages on this product. This action cannot be undone.', 'reeid-translate' ); ?>">
            <?php esc_html_e('Delete all translations', 'reeid-translate'); ?>
        </button>
    </td>
</tr>
<?php endif; ?>
                    <!-- Language panes -->
                    <?php
                    $first = true;
                    foreach ($entries as $code => $row):
                        $m = $row['meta'];
                        $title   = isset($m['title'])   ? (string)$m['title']   : '';
                        $excerpt = isset($m['excerpt']) ? (string)$m['excerpt'] : '';
                        $content = isset($m['content']) ? (string)$m['content'] : '';
                        $status  = isset($m['status'])  ? (string)$m['status']  : 'draft';
                    ?>
                <tbody class="reeid-tr-pane" data-code="<?php echo esc_attr($code); ?>" style="<?php echo $first ? '' : 'display:none;'; ?>">
                    <tr>
                        <th scope="row"><label for="reeid_tr_<?php echo esc_attr($code); ?>_title"><?php esc_html_e('Translated Title', 'reeid-translate'); ?></label></th>
                        <td>
                            <input type="text"
                                id="reeid_tr_<?php echo esc_attr($code); ?>_title"
                                name="reeid_tr[<?php echo esc_attr($code); ?>][title]"
                                value="<?php echo esc_attr($title); ?>" />
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="reeid_tr_<?php echo esc_attr($code); ?>_excerpt"><?php esc_html_e('Short Description', 'reeid-translate'); ?></label></th>
                        <td>
                            <textarea id="reeid_tr_<?php echo esc_attr($code); ?>_excerpt"
                                name="reeid_tr[<?php echo esc_attr($code); ?>][excerpt]"
                                rows="4"><?php echo esc_textarea($excerpt); ?></textarea>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="reeid_tr_<?php echo esc_attr($code); ?>_content"><?php esc_html_e('Long Description', 'reeid-translate'); ?></label></th>
                        <td>
                            <textarea id="reeid_tr_<?php echo esc_attr($code); ?>_content"
                                name="reeid_tr[<?php echo esc_attr($code); ?>][content]"
                                rows="8" class="reeid-wide-textarea"><?php echo esc_textarea($content); ?></textarea>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><label for="reeid_tr_<?php echo esc_attr($code); ?>_status"><?php esc_html_e('Status', 'reeid-translate'); ?></label></th>
                        <td>
                            <select class="reeid-tr-status-select"
                                data-code="<?php echo esc_attr($code); ?>"
                                id="reeid_tr_<?php echo esc_attr($code); ?>_status"
                                name="reeid_tr[<?php echo esc_attr($code); ?>][status]">
                                <?php
                                $opts = [
                                    'draft'     => __('Draft', 'reeid-translate'),
                                    'published' => __('Published', 'reeid-translate'),
                                    'outdated'  => __('Outdated', 'reeid-translate'),
                                ];
                                foreach ($opts as $k => $lbl) {
                                    printf('<option value="%s"%s>%s</option>', esc_attr($k), selected($status, $k, false), esc_html($lbl));
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                </tbody>
            <?php
                        $first = false;
                    endforeach; ?>
            </tbody>
            </table>
        </div>

        <style>
            /* Table container */
            #reeid_translations_panel .reeid-tr-table {
                width: 100%;
                max-width: 1100px;
            }

            #reeid_translations_panel .reeid-tr-table th {
                width: 220px;
                text-align: left;
                vertical-align: top;
                padding-top: 10px;
            }

            #reeid_translations_panel .reeid-tr-table td {
                vertical-align: top;
            }

            /* Inputs / textareas width */
            #reeid_translations_panel .reeid-tr-table input[type="text"],
            #reeid_translations_panel .reeid-tr-table textarea {
                width: 100%;
                max-width: 820px;
                box-sizing: border-box;
            }

            /* Compact selects */
            #reeid_translations_panel #reeid-tr-langselect {
                width: 260px;
                max-width: 260px;
                vertical-align: middle;
            }

            #reeid_translations_panel .reeid-tr-status-select {
                width: 200px;
                max-width: 200px;
            }

            /* Live status badge */
            .reeid-tr-badge {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                margin-left: 12px;
                padding: 4px 10px;
                border-radius: 999px;
                font-size: 12px;
                background: #f3f4f6;
                color: #111;
                line-height: 1.2;
            }

            .reeid-tr-badge .reeid-tr-badge-dot {
                width: 8px;
                height: 8px;
                border-radius: 50%;
                display: inline-block;
                background: #9aa3af;
            }

            .reeid-tr-badge.is-published {
                background: #e8f7ee;
                color: #0f5132;
            }

            .reeid-tr-badge.is-published .reeid-tr-badge-dot {
                background: #2eb872;
            }

            .reeid-tr-badge.is-outdated {
                background: #fff6e5;
                color: #7a5200;
            }

            .reeid-tr-badge.is-outdated .reeid-tr-badge-dot {
                background: #ffa500;
            }

            .reeid-tr-badge.is-draft {
                background: #eef1f5;
                color: #3a3a3a;
            }

            .reeid-tr-badge.is-draft .reeid-tr-badge-dot {
                background: #9aa3af;
            }
        </style>

        <script>
            (function($) {
                function applyBadge(status) {
                    var $b = $('#reeid-tr-statusbadge');
                    $b.removeClass('is-published is-outdated is-draft');
                    var t = 'Draft';
                    if (status === 'published') {
                        $b.addClass('is-published');
                        t = 'Published';
                    } else if (status === 'outdated') {
                        $b.addClass('is-outdated');
                        t = 'Outdated';
                    } else {
                        $b.addClass('is-draft');
                    }
                    $b.attr('data-status', status);
                    $b.find('.reeid-tr-badge-text').text(t);
                }

                function currentPaneStatus(code) {
                    var $pane = $('.reeid-tr-pane[data-code="' + code + '"]');
                    if (!$pane.length) return 'draft';
                    var $sel = $pane.find('.reeid-tr-status-select');
                    var v = $sel.val();
                    return v ? v : 'draft';
                }

                // Switch language panes
                $(document).on('change', '#reeid-tr-langselect', function() {
                    var code = $(this).val();
                    $('.reeid-tr-pane').hide();
                    $('.reeid-tr-pane[data-code="' + code + '"]').show();
                    applyBadge(currentPaneStatus(code));
                    console.log('[S26.9] Pane →', code);
                });

                // Status badge live update
                $(document).on('change', '.reeid-tr-status-select', function() {
                    var code = $(this).data('code');
                    var active = $('#reeid-tr-langselect').val();
                    if (code === active) {
                        applyBadge($(this).val());
                    }
                });

 // Delete-all via a detached POST form (avoid nested forms)
$(document).on('click', '#reeid-wc-del-all', function(e){
    e.preventDefault();
    var $btn  = $(this);
    var pid   = parseInt($btn.data('product-id'), 10);
    var url   = String($btn.data('action-url') || '');
    var nonce = String($btn.data('nonce') || '');
    var msg   = String($btn.data('msg') || 'Delete ALL translations? This cannot be undone.');

    if (!pid || !url || !nonce) { alert('Missing configuration (pid/url/nonce).'); return; }
    if (!window.confirm(msg)) return;

    // Build a standalone form and submit it
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = url;

    var fAction = document.createElement('input');
    fAction.type = 'hidden';
    fAction.name = 'action';
    fAction.value = 'reeid_wc_delete_all_translations';
    form.appendChild(fAction);

    var fPid = document.createElement('input');
    fPid.type = 'hidden';
    fPid.name = 'product_id';
    fPid.value = String(pid);
    form.appendChild(fPid);

    var fNonce = document.createElement('input');
    fNonce.type = 'hidden';
    fNonce.name = '_wpnonce';
    fNonce.value = nonce;
    form.appendChild(fNonce);

    document.body.appendChild(form);
    form.submit();
});




                // Init
                $(function() {
                    applyBadge($('#reeid-tr-statusbadge').attr('data-status') || 'draft');
                });
            })(jQuery);
        </script>

    <?php
    });

    /** Save handler — persists inline per-language fields into _reeid_wc_tr_{lang} */
    add_action('woocommerce_admin_process_product_object', function (\WC_Product $product) {
        if (empty($_POST['reeid_tr_nonce']) || ! wp_verify_nonce(wp_unslash($_POST['reeid_tr_nonce']), 'reeid_tr_save')) {
            return;
        }

        $post_id = (int) $product->get_id();

        // Default/source language with a safe fallback if helper doesn't exist
        $default = function_exists('reeid_s269_default_lang')
            ? (string) reeid_s269_default_lang()
            : (string) get_option('reeid_translation_source_lang', 'en');

        $payload = isset($_POST['reeid_tr']) ? wp_unslash($_POST['reeid_tr']) : [];
        if (!is_array($payload)) {
            return;
        }

        $index       = (array) get_post_meta($post_id, '_reeid_wc_inline_langs', true);
        $saved_codes = [];

        // helper for placeholder/empty detection
        $norm = function ($s) {
            $s = (string) $s;
            $s = wp_strip_all_tags($s);
            $s = preg_replace('/\s+/u', ' ', $s);
            return strtolower(trim($s));
        };

        foreach ($payload as $code => $data) {
            // Normalize code with fallback
            $code = function_exists('reeid_s269_lang_norm')
                ? reeid_s269_lang_norm($code)
                : strtolower(substr(trim((string)$code), 0, 10));

            if (!$code) {
                continue;
            }

            // Never write source language here (protect originals)
            if (strcasecmp($code, $default) === 0) {
                continue;
            }

            $title   = isset($data['title'])   ? (string) $data['title']   : '';
            $excerpt = isset($data['excerpt']) ? (string) $data['excerpt'] : '';
            $content = isset($data['content']) ? (string) $data['content'] : '';
            $status  = isset($data['status'])  ? strtolower((string) $data['status']) : 'draft';
            if (!in_array($status, ['draft', 'published', 'outdated'], true)) {
                $status = 'draft';
            }

            // Block empty/placeholder packets and purge any old bad meta
            $content_norm = $norm($content);
            if ($content_norm === '' || $content_norm === 'trasnlation plugin no') {
                delete_post_meta($post_id, '_reeid_wc_tr_'     . $code);
                delete_post_meta($post_id, '_reeid_wc_inline_' . $code);
                continue;
            }

            $packet = [
                'title'   => $title,
                'excerpt' => $excerpt,
                'content' => $content,
                'status'  => $status,
                'updated' => gmdate('c'),
                'editor'  => (string) get_current_user_id(),
            ];

            // optional sanitizers if present
            if (function_exists('reeid_wc_bp_clean_payload')) {
                $packet = reeid_wc_bp_clean_payload($packet);
            } elseif (function_exists('reeid_wc_sanitize_payload_fields')) {
                $packet = reeid_wc_sanitize_payload_fields($packet);
            }

            update_post_meta($post_id, '_reeid_wc_tr_' . $code, $packet);
            $saved_codes[] = $code;

            // maintain availability index (no source lang)
            if (!in_array($code, $index, true)) {
                $index[] = $code;
            }
        } // end foreach

        // Re-save consolidated index
        $index = array_values(array_unique(array_filter(array_map('reeid_s269_lang_norm', $index))));
        update_post_meta($post_id, '_reeid_wc_inline_langs', $index);

        // Log summary (lengths only)
        $lens = [];
        foreach ($saved_codes as $c) {
            $m = get_post_meta($post_id, '_reeid_wc_tr_' . $c, true);
            $lens[$c] = [
                't' => isset($m['title'])   ? mb_strlen((string)$m['title'])   : 0,
                'e' => isset($m['excerpt']) ? mb_strlen((string)$m['excerpt']) : 0,
                'c' => isset($m['content']) ? mb_strlen((string)$m['content']) : 0,
                's' => isset($m['status'])  ? $m['status'] : '',
            ];
        }
        reeid_s269_log('SAVE', ['post_id' => $post_id, 'langs' => $saved_codes, 'lens' => $lens]);
    }, 10);

    add_action('admin_head', function () {
        if (function_exists('reeid_debug_log')) {
            reeid_debug_log('S26.9.I ICON', 'loaded');
        }
    ?>
        <style id="reeid-translate-tab-icon">
            .product_data_tabs .reeid_translations_options a::before {
                font-family: "Dashicons" !important;
                content: "\f319" !important;
                /* globe */
                font-size: 18px;
                line-height: 1;
            }

            .product_data_tabs .reeid_translations_options a {
                font-family: inherit !important;
            }
        </style>
        <?php
    });



/*==============================================================================
    SECTION 37 : Cart/Checkout Language Integrity (Multilingual, Consolidated)
  - One compact section that:
    1) Makes Woo URLs language-sticky (adds ?reeid_force_lang=xx)
    2) Provides a global-aware language switcher (Cart/Checkout/Pages)
    3) Injects JS i18n for Woo Blocks (Cart/Checkout) from /mappings JSON
  - SAFE: does not touch Elementor/Gutenberg content or your product inline swaps.
==============================================================================*/

    if (! function_exists('reeid_s2610_log')) {
        function reeid_s2610_log($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S26.10 ' . $label, $data);
            }
        }
    }

    /* ---------- Helpers ------------------------------------------------------- */
    if (! function_exists('reeid_s2610_norm')) {
        function reeid_s2610_norm($v)
        {
            $v = strtolower(trim((string)$v));
            // allow: en, pt-br, zh-hant, es-419, etc. (2nd tag 2–8 chars or digits)
            if (!preg_match('/^[a-z]{2}(?:[-_][a-z0-9]{2,8})?$/', $v)) return '';
            // keep it short/safe but AFTER validation
            return substr($v, 0, 10);
        }
    }
    if (! function_exists('reeid_s2610_lang')) {
        function reeid_s2610_lang(): string
        {
            if (function_exists('reeid_wc_resolve_lang_strong')) {
                $l = (string) reeid_wc_resolve_lang_strong();
                if ($l) return reeid_s2610_norm($l);
            }
            if (! empty($_COOKIE['site_lang'])) {
                $l = reeid_s2610_norm((string) $_COOKIE['site_lang']);
                if ($l) return $l;
            }
            return 'en';
        }
    }
    if (! function_exists('reeid_s2610_with_lang')) {
        /** Append/replace ?reeid_force_lang=xx, preserve other params */
        function reeid_s2610_with_lang(string $url, string $lang): string
        {
            $lang = reeid_s2610_norm($lang) ?: 'en';
            if ($url === '') return $url;

            $parts = wp_parse_url($url);
            $path  = $parts['path'] ?? '';
            $q     = [];
            if (! empty($parts['query'])) parse_str($parts['query'], $q);

            unset($q['lang'], $q['reeid_force_lang']);
            $q['reeid_force_lang'] = $lang;

            $out = $path ?: $url;
            if (isset($parts['scheme'], $parts['host'])) {
                $out = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path;
            }
            if ($q) {
                $out .= '?' . http_build_query($q);
            }
            if (!empty($parts['fragment'])) {
                $out .= '#' . $parts['fragment'];
            }
            return $out;
        }
    }

    /* ---------- (1) Language-sticky Woo URLs (funnel safe) ------------------- */
    /* Single-product form action */
    add_filter('woocommerce_add_to_cart_form_action', function ($url) {
        if (is_admin()) return $url;
        $lang = reeid_s2610_lang();
        $new  = reeid_s2610_with_lang($url, $lang);
        if ($new !== $url) reeid_s2610_log('FORM_ACTION', ['lang' => $lang]);
        return $new;
    }, 99);

    /* Catalog add-to-cart links */
    add_filter('woocommerce_product_add_to_cart_url', function ($url) {
        if (is_admin()) return $url;
        return reeid_s2610_with_lang($url, reeid_s2610_lang());
    }, 99);

    /* Cart / Checkout / Return */
    add_filter('woocommerce_get_cart_url', function ($url) {
        if (is_admin()) return $url;
        return reeid_s2610_with_lang($url, reeid_s2610_lang());
    }, 99);

    add_filter('woocommerce_get_checkout_url', function ($url) {
        if (is_admin()) return $url;
        return reeid_s2610_with_lang($url, reeid_s2610_lang());
    }, 99);

    add_filter('woocommerce_get_return_url', function ($url) {
        if (is_admin()) return $url;
        return reeid_s2610_with_lang($url, reeid_s2610_lang());
    }, 99);

    /* Cover Woo page permalinks + endpoints (my-account flows) */
    add_filter('woocommerce_get_page_permalink', function ($permalink, $page) {
        if (is_admin()) return $permalink;
        if (in_array($page, ['cart', 'checkout', 'myaccount'], true)) {
            return reeid_s2610_with_lang($permalink, reeid_s2610_lang());
        }
        return $permalink;
    }, 99, 2);

    add_filter('woocommerce_get_endpoint_url', function ($url) {
        if (is_admin()) return $url;
        return reeid_s2610_with_lang($url, reeid_s2610_lang());
    }, 99);

    /* After add-to-cart redirects (some themes override) */
    add_filter('woocommerce_add_to_cart_redirect', function ($url) {
        if (is_admin()) return $url;
        return $url ? reeid_s2610_with_lang($url, reeid_s2610_lang()) : $url;
    }, 99);

    /* ---------- (2) Global-aware Language Switcher --------------------------- */
    /* Discover site languages (labels) from helper or /mappings/ scan */
    if (! function_exists('reeid_s2610_site_langs')) {
        function reeid_s2610_site_langs(): array
        {
            if (function_exists('reeid_get_supported_languages')) {
                $map = (array) reeid_get_supported_languages();
                $out = [];
                foreach ($map as $c => $label) {
                    $c = reeid_s2610_norm($c);
                    if ($c) {
                        $out[$c] = (string)$label;
                    }
                }
                if ($out) return $out;
            }
            $dir = trailingslashit(dirname(__FILE__)) . 'mappings/';
            $out = [];
            if (is_dir($dir) && ($h = @opendir($dir))) {
                while (($f = readdir($h)) !== false) {
                    if (preg_match('/^woocommerce-([a-z]{2}(?:-[a-z0-9]{2,8})?)\.json$/i', $f, $m)) {
                        $code = reeid_s2610_norm($m[1]);
                        if ($code) $out[$code] = strtoupper($code);
                    }
                }
                closedir($h);
            }
            $def = reeid_s2610_norm((string) get_option('reeid_translation_source_lang', 'en')) ?: 'en';
            if (!isset($out[$def])) $out = [$def => 'English'] + $out;
            return $out;
        }
    }
    /* Inline/duplicate product langs (actual availability) */
    if (! function_exists('reeid_s2610_product_langs')) {
        function reeid_s2610_product_langs(int $pid): array
        {
            $codes = [];

            // Prefer canonical availability from wc-inline helper if present
            if (function_exists('reeid_wc_available_langs')) {
                $codes = (array) reeid_wc_available_langs($pid); // inline + duplicated langs
            } else {
                // Fallback to the meta index if helper isn’t loaded
                $codes = (array) get_post_meta($pid, '_reeid_wc_inline_langs', true);
            }

            // Normalize & de-dup
            $codes = array_values(array_unique(array_filter(array_map('reeid_s2610_norm', $codes))));

            // Always include the source language first
            $def = reeid_s2610_norm((string) get_option('reeid_translation_source_lang', 'en')) ?: 'en';
            if (!in_array($def, $codes, true)) array_unshift($codes, $def);

            // FINAL SAFETY: prune against reality if the filter helper exists
            if (function_exists('reeid_wc_filter_switcher_langs')) {
                $codes = array_values(array_map('strval', (array) reeid_wc_filter_switcher_langs($codes, $pid)));
            }

            // Label map
            $labels = function_exists('reeid_get_supported_languages') ? (array) reeid_get_supported_languages() : [];
            $out = [];
            foreach ($codes as $c) {
                $out[$c] = isset($labels[$c]) ? (string)$labels[$c] : strtoupper($c);
            }
            return $out;
        }
    }
    /* Render/override [reeid_lang_switcher] shortcode */
    if (! function_exists('reeid_render_lang_switcher')) {
        function reeid_render_lang_switcher($atts = []): string
        {
            $atts = shortcode_atts(['class' => 'reeid-lang-switcher', 'style' => 'list'], $atts, 'reeid_lang_switcher');
            $current = reeid_s2610_lang();

            $langs = [];
            if (function_exists('is_product') && is_product()) {
                global $post;
                $pid = $post ? (int)$post->ID : 0;
                if ($pid) {
                    $langs = reeid_s2610_product_langs($pid);
                    reeid_s2610_log('MODE=PRODUCT', ['post_id' => $pid, 'codes' => array_keys($langs)]);
                }
            }
            if (!$langs) {
                $langs = reeid_s2610_site_langs();
                reeid_s2610_log('MODE=GLOBAL', ['codes' => array_keys($langs)]);
            }

            if (!$langs) return '';
            $items = [];
            $here = (is_ssl() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
            foreach ($langs as $code => $label) {
                $href = esc_url(reeid_s2610_with_lang($here, $code));
                $active = ($code === $current) ? ' aria-current="true" class="active"' : '';
                $items[] = sprintf('<a href="%s"%s data-code="%s">%s</a>', $href, $active, esc_attr($code), esc_html($label));
            }

            $html = '<nav class="' . esc_attr($atts['class']) . '">';
            if ($atts['style'] === 'inline') $html .= implode(' <span class="sep">•</span> ', $items);
            else {
                $html .= '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
            }
            $html .= '</nav>';
            return $html;
        }
    }
    /* Register/override shortcode */
    add_action('init', function () {
        if (shortcode_exists('reeid_lang_switcher')) remove_shortcode('reeid_lang_switcher');
        add_shortcode('reeid_lang_switcher', 'reeid_render_lang_switcher');
        reeid_s2610_log('SHORTCODE_READY', 1);
    }, 20);

    /* ---------- (3) Woo Blocks Cart/Checkout JS i18n ------------------------- */
    /* Reuse S27.1 loader if present; else tiny reader */
    if (! function_exists('reeid_s271_load_map')) {
        function reeid_s271_load_map(string $lang): array
        {
            $lang = strtolower(substr(trim($lang), 0, 10));
            $dir  = trailingslashit(dirname(__FILE__)) . 'mappings/';
            $c = [$dir . "woocommerce-$lang.json"];
            if (strpos($lang, '-') !== false || strpos($lang, '_') !== false) {
                $xx = preg_split('/[-_]/', $lang)[0] ?? '';
                if ($xx) {
                    $c[] = $dir . "woocommerce-$xx.json";
                }
            }
            foreach ($c as $f) if (is_readable($f)) {
                $j = json_decode((string)file_get_contents($f), true);
                if (is_array($j) && $j) return $j;
            }
            return [];
        }
    }
    if (! function_exists('reeid_s2610_to_jed')) {
        function reeid_s2610_to_jed(array $map, string $domain = 'woocommerce'): array
        {
            $jed = ['' => ['domain' => $domain]];
            foreach ($map as $k => $v) {
                if (!is_string($k) || !is_string($v)) continue;
                if (strpos($k, '|') !== false) {
                    list($text, $ctx) = explode('|', $k, 2);
                    $jed[$ctx . "\004" . $text] = [$v];
                } else {
                    $jed[$k] = [$v];
                }
            }
            return $jed;
        }
    }
    /* Inject locale data on Cart/Checkout (Blocks) */
    add_action('wp_enqueue_scripts', function () {
        if (is_admin() || wp_doing_ajax()) return;
        if ((function_exists('is_cart') && is_cart()) || (function_exists('is_checkout') && is_checkout())) {
            $lang = reeid_s2610_lang();
            if ($lang === 'en') return; // skip EN by default

            $map = reeid_s271_load_map($lang);
            if (empty($map)) {
                reeid_s2610_log('NO_MAP_JS', $lang);
                return;
            }

            $jed = reeid_s2610_to_jed($map, 'woocommerce');

            wp_enqueue_script('wp-i18n');
            $payload = wp_json_encode($jed);

            $inline = 'try{if(window.wp&&wp.i18n&&wp.i18n.setLocaleData){'
                . 'wp.i18n.setLocaleData(' . $payload . ',"woocommerce");'
                . 'wp.i18n.setLocaleData(' . $payload . ',"woocommerce-blocks");'
                . '}}catch(e){console&&console.warn&&console.warn("[S26.10] i18n inject failed",e);}';
            wp_add_inline_script('wp-i18n', $inline, 'after');

            reeid_s2610_log('JED_INJECTED', ['lang' => $lang, 'keys' => count($map)]);
        }
    }, 20);

/* ============================================================================
   SECTION 38 : WC SWITCHER PRUNE (universal) — works with any [reeid_lang_switcher] renderer
 * Does NOT change Elementor/Gutenberg logic.
 * ============================================================================ */
    add_filter('do_shortcode_tag', function ($output, $tag, $attrs, $m) {
        if ($tag !== 'reeid_lang_switcher') return $output;
        if (is_admin() || ! function_exists('is_product') || ! is_product()) return $output;
        if (! function_exists('reeid_wc_available_langs')) return $output;

        global $post;
        if (! $post || $post->post_type !== 'product') return $output;

        $allowed = array_map('strval', (array) reeid_wc_available_langs((int) $post->ID));
        if (empty($allowed)) return $output;

        // remove any links with data-code not in $allowed
        $out = preg_replace_callback('#<a\b[^>]*data-code=["\']([^"\']+)["\'][^>]*>.*?</a>#si', function ($m) {
            // placeholder, replaced below with closure use
            return $m[0];
        }, $output);

        // Because PHP < 7.4 anonymous use syntax varies, re-run with a closure that captures $allowed
        $out = preg_replace_callback(
            '#<a\b[^>]*data-code=["\']([^"\']+)["\'][^>]*>.*?</a>#si',
            function ($mm) use ($allowed) {
                $code = strtolower($mm[1]);
                return in_array($code, $allowed, true) ? $mm[0] : '';
            },
            $output
        );

        // Clean up any empty <li></li> pairs caused by removals
        $out = preg_replace('#<li>\s*</li>#', '', $out);
        // And stray separators if your inline style uses them
        $out = preg_replace('#\s*<span class="sep">•</span>\s*(?=</nav>)#', '', $out);

        return $out;
    }, 20, 4);

/*==============================================================================
    SECTION 39 : Admin “View” Language Guard (+ quick cookie clear)
  - Makes wp-admin "View" (row action & admin bar) open products in the source
    language by appending ?reeid_force_lang={source}.
  - Adds a tiny helper to clear the language cookie via ?reeid_clear_lang=1.
  - SAFE: front-end visitors unaffected; only admin links change.
  - Nuke debug prefix: "S26.12".
==============================================================================*/

    if (! function_exists('reeid_s2612_log')) {
        function reeid_s2612_log($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S26.12 ' . $label, $data);
            }
        }
    }
    if (! function_exists('reeid_s2612_norm')) {
        function reeid_s2612_norm($v)
        {
            $v = strtolower(substr((string)$v, 0, 10));
            return preg_match('/^[a-z]{2}(?:[-_][a-z0-9]{2})?$/', $v) ? $v : 'en';
        }
    }

    /* 0) Helper: get source (default) language */
    if (! function_exists('reeid_s2612_source_lang')) {
        function reeid_s2612_source_lang(): string
        {
            $src = (string) get_option('reeid_translation_source_lang', 'en');
            return reeid_s2612_norm($src) ?: 'en';
        }
    }

    /* 1) Products list table: alter the "View" row action to include ?reeid_force_lang=source */
    add_filter('post_row_actions', function (array $actions, \WP_Post $post) {
        if ($post->post_type !== 'product') return $actions;

        $src = reeid_s2612_source_lang();
        $view_url = get_permalink($post);
        if ($view_url) {
            $view_url = add_query_arg('reeid_force_lang', $src, $view_url);
            $actions['view'] = sprintf(
                '<a href="%s" rel="bookmark" aria-label="%s">%s</a>',
                esc_url($view_url),
                esc_attr(sprintf(__('View “%s” in %s', 'reeid-translate'), $post->post_title, strtoupper($src))),
                esc_html__('View', 'reeid-translate')
            );
            reeid_s2612_log('ROW_ACTION_VIEW_SET', ['post' => $post->ID, 'lang' => $src]);
        }
        return $actions;
    }, 10, 2);

    /* 2) Edit screen admin bar “View” link: point to source language too */
    add_action('admin_bar_menu', function (\WP_Admin_Bar $bar) {
        if (! is_admin()) return;
        if (! isset($_GET['post'])) return;
        $post = get_post((int) $_GET['post']);
        if (! $post || $post->post_type !== 'product') return;

        $src = reeid_s2612_source_lang();
        $url = add_query_arg('reeid_force_lang', $src, get_permalink($post));
        if ($node = $bar->get_node('view')) {
            $node->href = $url;
            $bar->add_node($node);
            reeid_s2612_log('ADMINBAR_VIEW_SET', ['post' => $post->ID, 'lang' => $src]);
        }
    }, 100);

    /* 3) Optional helper: allow clearing the language cookie quickly via URL.
      Visit any front-end URL with ?reeid_clear_lang=1 to reset to source. */
    add_action('template_redirect', function () {
        if (is_admin() || wp_doing_ajax()) return;
        if (empty($_GET['reeid_clear_lang'])) return;

        $src = reeid_s2612_source_lang();
        // Delete cookie first

        // Then set back to source (so the next request is deterministic)

        $_COOKIE['site_lang'] = $src;

        // Redirect to same URL without the param
        $scheme = is_ssl() ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';
        $parts  = wp_parse_url($scheme . '://' . $host . $uri);
        $q = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $q);
            unset($q['reeid_clear_lang']);
        }
        $clean = ($parts['path'] ?? '/') . ($q ? '?' . http_build_query($q) : '');

        reeid_s2612_log('COOKIE_CLEARED', ['to' => $src, 'dest' => $clean]);
        if (! headers_sent()) {
            wp_safe_redirect($clean, 302);
            exit;
        }
    }, 9);



/*==============================================================================
    SECTION 40 : Woo Inline — Delete One Translation (UI + Admin Action)
  - Adds a "Delete translation" control to the Translations tab.
  - Deletes meta key _reeid_wc_tr_{lang} and updates _reeid_wc_inline_langs.
  - Clears Woo transients; logs what happened.
  - Nuke debug prefix: "S26.13".
==============================================================================*/

    if (! function_exists('reeid_s2613_log')) {
        function reeid_s2613_log($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S26.13 ' . $label, $data);
            }
        }
    }

    /** Core helper: delete one language payload from a product */
    if (! function_exists('reeid_wc_delete_translation_meta')) {
        function reeid_wc_delete_translation_meta(int $product_id, string $lang): bool
        {
            $lang = strtolower(substr(trim($lang), 0, 10));
            if (! $lang) return false;

            $key = '_reeid_wc_tr_' . $lang;

            // Remove payload meta
            delete_post_meta($product_id, $key);

            // Remove from inline langs index
            $langs = (array) get_post_meta($product_id, '_reeid_wc_inline_langs', true);
            $langs = array_values(array_filter($langs, function ($c) use ($lang) {
                return strtolower(trim((string)$c)) !== $lang;
            }));
            update_post_meta($product_id, '_reeid_wc_inline_langs', $langs);

            // Optional: clean any per-lang SEO meta if you added them later
            delete_post_meta($product_id, '_reeid_wc_seo_' . $lang);           // umbrella (if used)
            delete_post_meta($product_id, '_reeid_wc_seo_title_' . $lang);     // if split keys were used
            delete_post_meta($product_id, '_reeid_wc_seo_desc_' . $lang);
            delete_post_meta($product_id, '_reeid_wc_seo_slug_' . $lang);

            // Clear Woo caches
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($product_id);
            }

            do_action('reeid_wc_translation_deleted', $product_id, $lang);
            reeid_s2613_log('DELETE_OK', ['product_id' => $product_id, 'lang' => $lang]);

            return true;
        }
    }

    /** Admin notice after delete */
    add_action('admin_notices', function () {
        if (! is_admin() || empty($_GET['reeid_tr_deleted'])) return;
        $lang = esc_html((string) $_GET['reeid_tr_deleted']);
        $ok   = ! empty($_GET['reeid_tr_ok']);
        $msg  = $ok
            ? sprintf(__('Translation "%s" removed from this product.', 'reeid-translate'), $lang)
            : sprintf(__('Could not remove translation "%s".', 'reeid-translate'), $lang);
        $cls  = $ok ? 'updated' : 'error';
        echo '<div class="notice ' . $cls . '"><p>' . $msg . '</p></div>';
    });

    /** UI: add a "Delete translation" button next to the language picklist */
    add_action('admin_footer-post.php', 'reeid_s2613_inject_delete_ui', 20);
    add_action('admin_footer-post-new.php', 'reeid_s2613_inject_delete_ui', 20);
    if (! function_exists('reeid_s2613_inject_delete_ui')) {
        function reeid_s2613_inject_delete_ui()
        {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if (! $screen || $screen->id !== 'product') return;

            $product_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
            if (! $product_id) return;

            $nonce   = wp_create_nonce('reeid_del_tr');
            $action  = esc_url(admin_url('admin-post.php'));
            $label   = esc_js(__('Delete this translation', 'reeid-translate'));
            $confirm = esc_js(__('This will remove the selected language content for this product. Continue?', 'reeid-translate'));

            /* We try to place the button after your Translations tab language <select>.
       Common ids used earlier: #reeid-tr-langselect  (adjusts itself if not found) */
        ?>
            <script>
                (function() {
                    function addDelBtn() {
                        var sel = document.getElementById('reeid-tr-langselect') ||
                            document.querySelector('#reeid_translations_panel select, .reeid-translations select');

                        if (!sel) return;

                        if (document.getElementById('reeid-del-tr-btn')) return; // avoid dupes

                        var btn = document.createElement('a');
                        btn.id = 'reeid-del-tr-btn';
                        btn.className = 'button button-link-delete';
                        btn.style.marginLeft = '8px';
                        btn.textContent = '<?php echo $label; ?>';

                        btn.addEventListener('click', function(e) {
                            e.preventDefault();
                            var lang = (sel.value || '').toLowerCase().slice(0, 10);
                            if (!lang) {
                                return;
                            }
                            if (!confirm('<?php echo $confirm; ?>')) return;

                            var url = '<?php echo $action; ?>' +
                                '?action=reeid_wc_delete_translation' +
                                '&post=<?php echo (int) $product_id; ?>' +
                                '&lang=' + encodeURIComponent(lang) +
                                '&_wpnonce=<?php echo $nonce; ?>';

                            window.location.href = url;
                        });

                        sel.parentNode.appendChild(btn);
                    }
                    document.addEventListener('DOMContentLoaded', addDelBtn);
                    setTimeout(addDelBtn, 800); // in case panel loads late
                })();
            </script>
        <?php
        }
    }


 /*==============================================================================
  SECTION 41 : Switcher placement
  A) Always render our language switcher on Cart & Checkout (top of the page)
  B) (Optional) Add our switcher to the primary nav menu in the header
==============================================================================*/

    if (! function_exists('reeid_s283_log')) {
        function reeid_s283_log($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S28.3 ' . $label, $data);
            }
        }
    }

    /* ---------- A) Cart & Checkout inline switcher --------------------------- */
    /* Renders above the form/contents so users can switch language there too. */
    add_action('woocommerce_before_cart', function () {
        echo do_shortcode('[reeid_lang_switcher style="inline" class="reeid-switcher-cart"]');
        reeid_s283_log('RENDER@cart', true);
    }, 5);

    add_action('woocommerce_before_checkout_form', function () {
        echo do_shortcode('[reeid_lang_switcher style="inline" class="reeid-switcher-checkout"]');
        reeid_s283_log('RENDER@checkout', true);
    }, 5);

    /* Small CSS to keep it neat */
    add_action('wp_head', function () {
        ?>
        <style>
            .reeid-switcher-cart,
            .reeid-switcher-checkout {
                margin: 6px 0 16px;
                text-align: right;
            }

            .reeid-switcher-cart a,
            .reeid-switcher-checkout a {
                text-decoration: none;
            }

            .reeid-switcher-cart a.active,
            .reeid-switcher-checkout a.active {
                font-weight: 600;
                text-decoration: underline;
            }

            .reeid-switcher-cart .sep,
            .reeid-switcher-checkout .sep {
                opacity: .6;
                margin: 0 .35em;
            }
        </style>
    <?php
    });

    /* ---------- B) OPTIONAL Header bridge ----------------------------------- */
    /* Append our switcher to the primary menu. Change 'primary' if your theme
   uses another location slug. Comment this block out if you don't want it. */
    add_filter('wp_nav_menu_items', function ($items, $args) {
        if (! isset($args->theme_location)) return $items;

        $target_locations = ['primary']; // ← adjust to your theme’s main menu slug(s)
        if (in_array($args->theme_location, $target_locations, true)) {
            $html = do_shortcode('[reeid_lang_switcher style="inline" class="reeid-switcher-nav"]');
            // Wrap as a menu item so it inherits header styling
            $items .= '<li class="menu-item menu-item-type-custom menu-item-reeid-switcher">' . $html . '</li>';
            reeid_s283_log('HEADER_BRIDGE@' . $args->theme_location, true);
        }
        return $items;
    }, 10, 2);

    add_action('wp_head', function () {
    ?>
        <style>
            /* Header switcher tweaks */
            .menu-item-reeid-switcher .reeid-switcher-nav {
                white-space: nowrap;
            }

            .menu-item-reeid-switcher .reeid-switcher-nav a {
                padding: 0 .25em;
            }

            .menu-item-reeid-switcher .reeid-switcher-nav a.active {
                font-weight: 600;
                text-decoration: underline;
            }
        </style>
    <?php
    });

    /*==============================================================================
    SECTION 42 : Switcher UI — add dropdown mode (list | inline | dropdown)
  - Replaces [reeid_lang_switcher] renderer with a version that supports
    style="dropdown", using the same language discovery from S26.10.
  - Nuke debug: S28.4 ...
==============================================================================*/

    if (! function_exists('reeid_s284_log')) {
        function reeid_s284_log($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S28.4 ' . $label, $data);
            }
        }
    }

    /* Use helpers from S26.10 (reeid_s2610_lang, _site_langs, _product_langs, _with_lang) */

    if (! function_exists('reeid_render_lang_switcher_v2')) {
        function reeid_render_lang_switcher_v2($atts = []): string
        {
            $atts = shortcode_atts([
                'class' => 'reeid-lang-switcher',
                'style' => 'list',  // list | inline | dropdown
                'id'    => '',      // optional custom id for the element
            ], $atts, 'reeid_lang_switcher');

            $current = function_exists('reeid_s2610_lang') ? reeid_s2610_lang() : 'en';

            // Discover languages (prefer product-specific; else global)
            $langs = [];
            if (function_exists('is_product') && is_product() && function_exists('reeid_s2610_product_langs')) {
                global $post;
                $pid = $post ? (int) $post->ID : 0;
                if ($pid) {
                    $langs = reeid_s2610_product_langs($pid);
                }
            }
            if (! $langs && function_exists('reeid_s2610_site_langs')) {
                $langs = reeid_s2610_site_langs();
            }
            if (! $langs) return '';

            $here = (is_ssl() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');

            // Dropdown mode
            if (strtolower($atts['style']) === 'dropdown') {
                $id = $atts['id'] ? preg_replace('/[^a-z0-9_\-]/i', '', $atts['id']) : 'reeid-sw-' . wp_generate_uuid4();
                $opts = '';
                foreach ($langs as $code => $label) {
                    $href = function_exists('reeid_s2610_with_lang') ? reeid_s2610_with_lang($here, $code) : $here;
                    $sel  = ($code === $current) ? ' selected' : '';
                    $opts .= '<option value="' . esc_attr($href) . '" data-code="' . esc_attr($code) . '"' . $sel . '>' . esc_html($label) . '</option>';
                }
                $html  = '<div class="' . esc_attr($atts['class']) . ' reeid-switcher--dropdown">';
                $html .= '<select id="' . esc_attr($id) . '" aria-label="Language switcher">' . $opts . '</select>';
                $html .= '</div>';
                $html .= '<script>document.addEventListener("change",function(e){var el=e.target;if(el && el.id==="' . esc_js($id) . '"){window.location.href=el.value;}});</script>';
                return $html;
            }

            // Link modes (list / inline)
            $items = [];
            foreach ($langs as $code => $label) {
                $href = function_exists('reeid_s2610_with_lang') ? reeid_s2610_with_lang($here, $code) : $here;
                $active = ($code === $current) ? ' aria-current="true" class="active"' : '';
                $items[] = sprintf('<a href="%s"%s data-code="%s">%s</a>', esc_url($href), $active, esc_attr($code), esc_html($label));
            }

            $html = '<nav class="' . esc_attr($atts['class']) . '">';
            if (strtolower($atts['style']) === 'inline') {
                $html .= implode(' <span class="sep">•</span> ', $items);
            } else {
                $html .= '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
            }
            $html .= '</nav>';
            return $html;
        }
    }

    /* Replace the shortcode renderer with the v2 (dropdown-capable) */
    add_action('init', function () {
        if (shortcode_exists('reeid_lang_switcher')) remove_shortcode('reeid_lang_switcher');
        add_shortcode('reeid_lang_switcher', 'reeid_render_lang_switcher_v2');
        reeid_s284_log('SHORTCODE_UPGRADED', true);
    }, 40);

    /* Minimal styling */
    add_action('wp_head', function () {
    ?>
        <style>
            .reeid-switcher--dropdown select {
                max-width: 260px;
            }

            .reeid-lang-switcher .sep {
                opacity: .6;
                margin: 0 .35em;
            }

            .reeid-lang-switcher a.active {
                font-weight: 600;
                text-decoration: underline;
            }
        </style>
    <?php
    });

    /*==============================================================================
  SECTION 44 : Cart/Checkout Switcher Gate (single dropdown, deduped)
  - Ensures only ONE switcher shows on Cart/Checkout, as a compact dropdown.
  - Suppresses any other [reeid_lang_switcher] instances on Cart/Checkout
    (widgets, page content, header) to avoid duplicates.
  - Keeps shortcode normal behavior elsewhere (e.g., product pages).
  - Works with S26.10 (multilingual funnel) and S27.1/JS i18n.
  - Nuke debug prefix: "S28.5".
==============================================================================*/

    if (! function_exists('reeid_s285_log')) {
        function reeid_s285_log($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S28.5 ' . $label, $data);
            }
        }
    }

    /* -------------------------------------------------------
 *  A) Dropdown-capable renderer (v3) with Cart/Checkout GATE
 * ----------------------------------------------------- */

    /* uses helpers from S26.10: reeid_s2610_lang / _site_langs / _product_langs / _with_lang */

    if (! function_exists('reeid_render_lang_switcher_v3')) {
        function reeid_render_lang_switcher_v3($atts = []): string
        {
            // IMPORTANT: Gate — on Cart/Checkout ONLY render when our gate is open.
            $is_cc = (function_exists('is_cart') && is_cart()) || (function_exists('is_checkout') && is_checkout());
            if ($is_cc && empty($GLOBALS['reeid_sw_gate_open'])) {
                // Someone (page content/header/widget) tried to render it; block to avoid duplicates.
                reeid_s285_log('BLOCKED_EXTRA_CC_INSTANCE', true);
                return '';
            }

            $atts = shortcode_atts([
                'class' => 'reeid-lang-switcher',
                'style' => 'list',      // list | inline | dropdown
                'id'    => '',          // optional element id
            ], $atts, 'reeid_lang_switcher');

            $current = function_exists('reeid_s2610_lang') ? reeid_s2610_lang() : 'en';

            // Discover languages (prefer product-specific; else global)
            $langs = [];
            if (function_exists('is_product') && is_product() && function_exists('reeid_s2610_product_langs')) {
                global $post;
                $pid = $post ? (int) $post->ID : 0;
                if ($pid) {
                    $langs = reeid_s2610_product_langs($pid);
                }
            }
            if (! $langs && function_exists('reeid_s2610_site_langs')) {
                $langs = reeid_s2610_site_langs();
            }
            if (! $langs) return '';

            $here = (is_ssl() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');

            // --- Dropdown mode ---
            if (strtolower($atts['style']) === 'dropdown') {
                $id = $atts['id'] ? preg_replace('/[^a-z0-9_\-]/i', '', $atts['id']) : 'reeid-sw-' . wp_generate_uuid4();
                $opts = '';
                foreach ($langs as $code => $label) {
                    $href = function_exists('reeid_s2610_with_lang') ? reeid_s2610_with_lang($here, $code) : $here;
                    $sel  = ($code === $current) ? ' selected' : '';
                    $opts .= '<option value="' . esc_attr($href) . '" data-code="' . esc_attr($code) . '"' . $sel . '>' . esc_html($label) . '</option>';
                }
                $html  = '<div class="' . esc_attr($atts['class']) . ' reeid-switcher--dropdown">';
                $html .= '<select id="' . esc_attr($id) . '" aria-label="Language switcher">' . $opts . '</select>';
                $html .= '</div>';
                $html .= '<script>document.addEventListener("change",function(e){var el=e.target;if(el && el.id==="' . esc_js($id) . '"){window.location.href=el.value;}});</script>';
                return $html;
            }

            // --- Links (list / inline) ---
            $items = [];
            foreach ($langs as $code => $label) {
                $href = function_exists('reeid_s2610_with_lang') ? reeid_s2610_with_lang($here, $code) : $here;
                $active = ($code === $current) ? ' aria-current="true" class="active"' : '';
                $items[] = sprintf('<a href="%s"%s data-code="%s">%s</a>', esc_url($href), $active, esc_attr($code), esc_html($label));
            }

            $html = '<nav class="' . esc_attr($atts['class']) . '">';
            if (strtolower($atts['style']) === 'inline') {
                $html .= implode(' <span class="sep">•</span> ', $items);
            } else {
                $html .= '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
            }
            $html .= '</nav>';
            return $html;
        }
    }

    /* Replace shortcode with the gated renderer */
    add_action('init', function () {
        if (shortcode_exists('reeid_lang_switcher')) remove_shortcode('reeid_lang_switcher');
        add_shortcode('reeid_lang_switcher', 'reeid_render_lang_switcher_v3');
        reeid_s285_log('SHORTCODE_GATED', true);
    }, 50);

    /* -------------------------------------------------------
 *  B) Our ONE Cart/Checkout injection (dropdown)
 * ----------------------------------------------------- */

    add_action('woocommerce_before_cart', function () {
        // Open gate only for this controlled render
        $GLOBALS['reeid_sw_gate_open'] = true;
        echo do_shortcode('[reeid_lang_switcher style="dropdown" class="reeid-switcher-cart"]');
        unset($GLOBALS['reeid_sw_gate_open']);
        reeid_s285_log('INJECT@cart', true);
    }, 5);

    add_action('woocommerce_before_checkout_form', function () {
        $GLOBALS['reeid_sw_gate_open'] = true;
        echo do_shortcode('[reeid_lang_switcher style="dropdown" class="reeid-switcher-checkout"]');
        unset($GLOBALS['reeid_sw_gate_open']);
        reeid_s285_log('INJECT@checkout', true);
    }, 5);

    /* -------------------------------------------------------
 *  C) Minimal CSS (compact & tidy)
 * ----------------------------------------------------- */
    add_action('wp_head', function () { ?>
        <style>
            .reeid-switcher--dropdown select {
                max-width: 260px;
            }

            .reeid-switcher-cart,
            .reeid-switcher-checkout {
                margin: 6px 0 16px;
                text-align: right;
            }

            .reeid-lang-switcher .sep {
                opacity: .6;
                margin: 0 .35em;
            }

            .reeid-lang-switcher a.active {
                font-weight: 600;
                text-decoration: underline;
            }
        </style>
    <?php });


 /*==============================================================================
 SECTION 45: ADMIN COLUMNS & LANGUAGE FILTERS
==============================================================================*/

    // Add "Language" column to posts and pages
    add_filter('manage_posts_columns', 'reeid_add_language_column', 99);
    add_filter('manage_pages_columns', 'reeid_add_language_column', 99);

    function reeid_add_language_column($columns)
    {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'title') {
                $new['reeid_lang'] = __('Language', 'reeid-translate');
            }
        }
        return $new;
    }

    // Render the language column in admin
    add_action('manage_posts_custom_column', 'reeid_render_language_column', 10, 2);
    add_action('manage_pages_custom_column', 'reeid_render_language_column', 10, 2);

    function reeid_render_language_column($column, $post_id)
    {
        if ('reeid_lang' !== $column) return;
        $lang = get_post_meta($post_id, '_reeid_translation_lang', true) ?: 'en';
        echo esc_html(strtoupper($lang));
    }

    // Add a dropdown language filter above post/page list
    add_action('restrict_manage_posts', 'reeid_language_filter_dropdown', 20);
    function reeid_language_filter_dropdown()
    {
        global $typenow, $pagenow;
        if ($pagenow !== 'edit.php' || !in_array($typenow, ['post', 'page'], true)) return;

        $langs = function_exists('reeid_get_supported_languages')
            ? reeid_get_supported_languages()
            : ['en' => 'English'];
        $current_raw = filter_input(INPUT_GET, 'reeid_lang_filter', FILTER_DEFAULT);
        $current = $current_raw ? sanitize_text_field(wp_unslash($current_raw)) : '';

        echo '<select name="reeid_lang_filter" style="margin-left:8px;">';
        echo '<option value="">' . esc_html__('All Languages', 'reeid-translate') . '</option>';
        foreach ($langs as $code => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($code),
                selected($current, $code, false),
                esc_html($label)
            );
        }
        echo '</select>';
    }

    // Actually filter posts/pages in admin list by language
    add_action('pre_get_posts', 'reeid_language_filter_query', 20);
    function reeid_language_filter_query($query)
    {
        global $pagenow, $typenow;
        if (
            !is_admin() ||
            $pagenow !== 'edit.php' ||
            !in_array($typenow, ['post', 'page'], true)
        ) return;

        $lang_raw = filter_input(INPUT_GET, 'reeid_lang_filter', FILTER_DEFAULT);
        $lang = $lang_raw ? sanitize_text_field(wp_unslash($lang_raw)) : '';
        if (empty($lang)) return;

        $meta_query = ('en' === $lang)
            ? [
                'relation' => 'OR',
                ['key' => '_reeid_translation_lang', 'value' => 'en', 'compare' => '='],
                ['key' => '_reeid_translation_lang', 'compare' => 'NOT EXISTS'],
            ]
            : [
                ['key' => '_reeid_translation_lang', 'value' => $lang, 'compare' => '='],
            ];

        $query->set('meta_query', $meta_query);
    }

    // Add a quick "Translate" link to post/page row actions in admin
    add_filter('post_row_actions', 'reeid_add_translate_row_action', 10, 2);
    add_filter('page_row_actions', 'reeid_add_translate_row_action', 10, 2);

    function reeid_add_translate_row_action($actions, $post)
    {
        if (!current_user_can('edit_post', $post->ID) || $post->post_status !== 'publish') return $actions;
        $url = admin_url('post.php?post=' . intval($post->ID) . '&action=edit#reeid-translation-box');
        $actions['reeid_translate'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($url),
            esc_html__('Translate (REEID)', 'reeid-translate')
        );
        return $actions;
    }


    // HELPER: Build translated URL (native slugs + language prefix)

    if (! function_exists('reeid_get_translated_url')) {
        function reeid_get_translated_url($post_id, $lang)
        {
            $default = sanitize_text_field(get_option('reeid_translation_source_lang', 'en'));
            $raw_slug = get_post_field('post_name', $post_id);
            $slug = rawurldecode($raw_slug);
            if ($lang === $default) {
                return user_trailingslashit(home_url("/{$slug}"));
            }
            return user_trailingslashit(home_url("/{$lang}/{$slug}"));
        }
    }


/*==============================================================================
    SECTION 46 : Woo Gettext Map (Strict Resolver + English Fallback)
  - Unifies resolver with product swaps: per-request current language.
  - Per-lang cache; never falls back to another language's map.
  - If no map exists for the current lang -> pass through original English.
  - Targets domains: 'woocommerce' and (optionally) 'woocommerce-blocks' PHP strings.
==============================================================================*/

    if (! function_exists('reeid_271r_log')) {
        function reeid_271r_log($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S27.1R ' . $label, $data);
            }
        }
    }

    /** Normalize language code */
    if (! function_exists('reeid_271r_norm')) {
        function reeid_271r_norm($v)
        {
            $v = strtolower(substr((string)$v, 0, 10));
            return preg_match('/^[a-z]{2}(?:[-_][a-z0-9]{2})?$/', $v) ? $v : 'en';
        }
    }

    /** Current lang (strict) — same rules as runtime swaps */
    if (! function_exists('reeid_271r_lang')) {
        function reeid_271r_lang(): string
        {
            if (function_exists('reeid_wc_resolve_lang_strong')) {
                $l = (string) reeid_wc_resolve_lang_strong();
                if ($l) return reeid_271r_norm($l);
            }
            // Soft fallback to cookie then EN
            if (! empty($_COOKIE['site_lang'])) return reeid_271r_norm((string) $_COOKIE['site_lang']);
            return 'en';
        }
    }

    /** Load JSON map for a domain+lang (per-request cache, no cross-lang reuse) */
    if (! function_exists('reeid_271r_load_map')) {
        function reeid_271r_load_map(string $domain, string $lang): array
        {
            static $cache = []; // [domain][lang] => array
            $domain = strtolower(trim($domain ?: 'woocommerce'));
            $lang   = reeid_271r_norm($lang);

            if (isset($cache[$domain][$lang])) return $cache[$domain][$lang];

            $dir = trailingslashit(dirname(__FILE__)) . 'mappings/';
            $try = [];

            // Try full code first (ru-RU), then primary (ru)
            $try[] = "{$dir}{$domain}-{$lang}.json";
            if (strpos($lang, '-') !== false || strpos($lang, '_') !== false) {
                $base = preg_split('/[-_]/', $lang)[0] ?? '';
                if ($base) $try[] = "{$dir}{$domain}-{$base}.json";
            }

            $map = [];
            foreach ($try as $f) {
                if (is_readable($f)) {
                    $j = json_decode((string) file_get_contents($f), true);
                    if (is_array($j) && $j) {
                        $map = $j;
                        break;
                    }
                }
            }

            $cache[$domain][$lang] = $map;
            reeid_271r_log('LOAD', ['domain' => $domain, 'lang' => $lang, 'found' => (bool)$map, 'file' => ($map ? basename($f) : null)]);
            return $map;
        }
    }

    function reeid_271r_xlate(string $domain, string $lang, string $text, string $context = ''): string
    {
        $map = reeid_271r_load_map($domain, $lang);

        // Try current language mapping first
        if (!empty($map)) {
            $k = $context !== '' ? "{$text}|{$context}" : $text;
            if (isset($map[$k]) && is_string($map[$k])) return (string)$map[$k];
            if (isset($map[$text]) && is_string($map[$text])) return (string)$map[$text];
        }

        // FALLBACK: Try plugin's default language setting (admin panel)
        $default = sanitize_text_field(get_option('reeid_translation_source_lang', 'en'));
        if ($default && $default !== $lang) {
            $map_default = reeid_271r_load_map($domain, $default);
            if (!empty($map_default)) {
                $k = $context !== '' ? "{$text}|{$context}" : $text;
                if (isset($map_default[$k]) && is_string($map_default[$k])) return (string)$map_default[$k];
                if (isset($map_default[$text]) && is_string($map_default[$text])) return (string)$map_default[$text];
            }
        }

        // Final fallback to source text (usually English)
        return $text;
    }


    /** Domains to handle */
    if (! function_exists('reeid_271r_domains')) {
        function reeid_271r_domains(): array
        {
            return ['woocommerce', 'woocommerce-blocks'];
        }
    }

    /** gettext */
    add_filter('gettext', function ($translated, $text, $domain) {
        if (! in_array($domain, reeid_271r_domains(), true)) return $translated;
        // Safety: do not run gettext replacement logic in wp-admin
        if (is_admin()) {
            return $translated;
        }
        $lang = reeid_271r_lang();
        $out  = reeid_271r_xlate($domain, $lang, (string)$text, '');
        // Log only when a different non-EN lang is present without a map (first time)
        if ($lang !== 'en' && $out === $text) {
            static $pinged = [];
            $k = $domain . '|' . $lang;
            if (empty($pinged[$k])) {
                $pinged[$k] = 1;
                reeid_271r_log('NO_MAP_FALLBACK', ['domain' => $domain, 'lang' => $lang]);
            }
        }
        return $out;
    }, 20, 3);

    /** gettext with context */
    add_filter('gettext_with_context', function ($translated, $text, $context, $domain) {
        if (! in_array($domain, reeid_271r_domains(), true)) return $translated;
        // Safety: do not run gettext replacement logic in wp-admin
        if (is_admin()) {
            return $translated;
        }
        $lang = reeid_271r_lang();
        return reeid_271r_xlate($domain, $lang, (string)$text, (string)$context);
    }, 20, 4);

    /** ngettext (plural) */
    add_filter('ngettext', function ($translated, $single, $plural, $number, $domain) {
        if (! in_array($domain, reeid_271r_domains(), true)) return $translated;
        // Safety: do not run gettext replacement logic in wp-admin
        if (is_admin()) {
            return $translated;
        }
        // We map keys by their singular/plural strings independently; if not found, fall back to English pair
        $lang = reeid_271r_lang();
        $one  = reeid_271r_xlate($domain, $lang, (string)$single, '');
        $many = reeid_271r_xlate($domain, $lang, (string)$plural, '');
        return (absint($number) === 1) ? $one : $many;
    }, 20, 5);


    add_filter('the_title', function ($title, $post_id) {
        if (get_post_type($post_id) === 'product') {
            $lang = isset($_GET['reeid_force_lang']) ? $_GET['reeid_force_lang'] : '';
            if ($lang) {
                $meta = get_post_meta($post_id, '_reeid_wc_tr_' . $lang, true);
                if (is_array($meta) && !empty($meta['title'])) {
                    return $meta['title'];
                }
            }
        }
        return $title;
    }, 10, 2);

    add_filter('the_content', function ($content) {
        global $post;
        if (isset($post->ID) && get_post_type($post->ID) === 'product') {
            $lang = isset($_GET['reeid_force_lang']) ? $_GET['reeid_force_lang'] : '';
            if ($lang) {
                $meta = get_post_meta($post->ID, '_reeid_wc_tr_' . $lang, true);
                if (is_array($meta) && !empty($meta['content'])) {
                    return $meta['content'];
                }
            }
        }
        return $content;
    }, 10);

    add_filter('get_the_excerpt', function ($excerpt, $post) {
        if (get_post_type($post) === 'product') {
            $lang = isset($_GET['reeid_force_lang']) ? $_GET['reeid_force_lang'] : '';
            if ($lang) {
                $meta = get_post_meta($post, '_reeid_wc_tr_' . $lang, true);
                if (is_array($meta) && !empty($meta['excerpt'])) {
                    return $meta['excerpt'];
                }
            }
        }
        return $excerpt;
    }, 10, 2);


/*==============================================================================
  SECTION 47 : SHORTCODE REGISTRATION & LANGUAGE SWITCHER (FIXED URL BUILDER)
==============================================================================*/

    add_action('init', 'reeid_register_shortcodes');
    function reeid_register_shortcodes()
    {
        add_shortcode('reeid_language_switcher', 'reeid_language_switcher_shortcode');
    }

    /**
     * Cycle-safe resolver for the canonical "source" post.
     */
    if (! function_exists('reeid_find_source_post_id')) {
        function reeid_find_source_post_id($seed = null, $front = 0)
        {
            global $wp_query;

            if ($seed instanceof WP_Post) {
                $src = (int) $seed->ID;
            } elseif (is_numeric($seed)) {
                $src = (int) $seed;
            } elseif (isset($wp_query) && is_object($wp_query)) {
                $qo  = method_exists($wp_query, 'get_queried_object') ? $wp_query->get_queried_object() : null;
                $src = ($qo instanceof WP_Post) ? (int) $qo->ID : (int) $front;
            } else {
                $src = (int) $front;
            }

            if ($src <= 0) return 0;

            $visited  = [];
            $hops     = 0;
            $max_hops = 50;

            while ($hops < $max_hops) {
                if (isset($visited[$src])) break;
                $visited[$src] = true;

                $parent = get_post_meta($src, '_reeid_translation_source', true);
                if (empty($parent)) break;

                $parent = (int) $parent;
                if ($parent <= 0 || $parent === $src) break;

                $parent_post = get_post($parent);
                if (! ($parent_post instanceof WP_Post)) break;

                $src = $parent;
                $hops++;
            }

            return (int) $src;
        }
    }

    /**
     * Shortcode callback: renders the dropdown language switcher.
     */
    function reeid_language_switcher_shortcode()
    {
        global $post, $wp_query;

        $langs   = function_exists('reeid_get_supported_languages') ? (array) reeid_get_supported_languages() : [];
        $flags   = function_exists('reeid_get_language_flags') ? (array) reeid_get_language_flags() : [];
        $default = sanitize_text_field(get_option('reeid_translation_source_lang', 'en'));
        $front   = (int) get_option('page_on_front');

        if (! ($post instanceof WP_Post)) return '';

        // 1) Resolve source post
        $src = reeid_find_source_post_id($post, $front);

        // 🛡 If current post is a translation, force source = EN post
        $curr_lang = get_post_meta($post->ID, '_reeid_translation_lang', true);
        if ($curr_lang && $curr_lang !== $default) {
            $maybe_source = (int) get_post_meta($post->ID, '_reeid_translation_source', true);
            if ($maybe_source > 0) {
                $src = $maybe_source;
            }
        }

        if ($src <= 0) return '';

        // 2) Build translation map
        $map = (array) get_post_meta($src, '_reeid_translation_map', true);
        $map[$default] = $src;

        $filtered = [];
        foreach ($map as $code => $pid) {
            $pid = absint($pid);
            if ($pid && get_post_status($pid) === 'publish') {
                $filtered[strtolower($code)] = $pid;
            }
        }

        // 3) Current language detection (prefer $post->ID)
        $current_id = ($post instanceof WP_Post) ? $post->ID : get_queried_object_id();
        $current = get_post_meta($current_id, '_reeid_translation_lang', true) ?: $default;
        $current = strtolower($current);

        // 4) Render dropdown
        $out  = '<div id="reeid-switcher-container" class="reeid-dropdown">';
        $out .= '<button type="button" class="reeid-dropdown__btn">';

        if (isset($flags[$current])) {
            $out .= '<img class="reeid-flag-img" src="'
                . esc_url(plugins_url('assets/flags/' . $flags[$current] . '.svg', __FILE__))
                . '" alt="' . esc_attr($langs[$current] ?? strtoupper($current)) . '">';
        }

        $out .= '<span class="reeid-dropdown__btn-label">'
            . esc_html($langs[$current] ?? strtoupper($current))
            . '</span><span class="reeid-dropdown__btn-arrow">▾</span></button>';
        $out .= '<ul class="reeid-dropdown__menu">';

        // Render links
        foreach ($filtered as $code => $pid) {
            $perm = get_permalink($pid);
            if (! $perm) continue;

            $lang = get_post_meta($pid, '_reeid_translation_lang', true) ?: $code;
            $lang = strtolower($lang);

            // 🏠 Special handling for homepage
            if ((int)$pid === (int)$front) {
                if ($lang === $default) {
                    $url = home_url('/');
                } else {
                    $url = home_url('/' . $lang . '/');
                }
            } else {
                $url = $perm; // normal page, just use permalink
            }

            $out .= '<li class="reeid-dropdown__item"><a class="reeid-dropdown__link" href="' . esc_url($url) . '">';
            if (isset($flags[$code])) {
                $out .= '<img class="reeid-flag-img" src="'
                    . esc_url(plugins_url('assets/flags/' . $flags[$code] . '.svg', __FILE__))
                    . '" alt="' . esc_attr($langs[$code] ?? strtoupper($code)) . '">';
            }
            $out .= '<span class="reeid-dropdown__label">'
                . esc_html($langs[$code] ?? strtoupper($code))
                . '</span></a></li>';
        }

        $out .= '</ul></div>';
        return $out;
    }

    /**
     *  SHORTCODE EXTENSION — LANGUAGE SWITCHER (Woo Inline Products) 
     */

    /** Local logger for this section */
    if (! function_exists('reeid_sw_debug')) {
        function reeid_sw_debug($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S28.1 ' . $label, $data);
            }
        }
    }

    /**
     * Helper: Normalize language code (lowercase, max 10 chars, simple validation).
     */
    if (! function_exists('reeid_lang_normalize_10')) {
        function reeid_lang_normalize_10($val)
        {
            $val = strtolower(substr(trim((string)$val), 0, 10));
            return preg_match('/^[a-z]{2}([-_][a-z0-9]{2})?$/i', $val) ? $val : '';
        }
    }

    /**
     * Helper: Build a language-prefixed URL for arbitrary permalinks.
     * - If $lang === $default, returns original $url unchanged (callers may append force param).
     * - Else injects "/{lang}" as the first path segment before the existing path.
     */
    if (! function_exists('reeid_build_lang_prefixed_url')) {
        function reeid_build_lang_prefixed_url($url, $lang, $default)
        {
            $lang    = reeid_lang_normalize_10($lang);
            $default = reeid_lang_normalize_10($default);
            if ($lang === '' || $lang === $default) {
                return $url;
            }
            $parts = wp_parse_url($url);
            $path  = isset($parts['path']) ? (string) $parts['path'] : '/';
            // Avoid double-prefixing if already present.
            if (preg_match('#^/' . preg_quote($lang, '#') . '(/|$)#', $path)) {
                return $url;
            }
            $prefixed = '/' . $lang . $path;
            $rebuilt  = user_trailingslashit(home_url($prefixed));
            // Preserve query/fragment if present.
            if (! empty($parts['query'])) {
                $rebuilt .= (strpos($rebuilt, '?') === false ? '?' : '&') . $parts['query'];
            }
            if (! empty($parts['fragment'])) {
                $rebuilt .= '#' . $parts['fragment'];
            }
            return $rebuilt;
        }
    }

    /**
     * Collect switcher items for NON-product posts/pages using §28 logic.
     * Returns array of [ 'code' => 'xx', 'pid' => int, 'url' => '...' ].
     */
    if (! function_exists('reeid_switcher_collect_generic_items')) {
        function reeid_switcher_collect_generic_items($post, $default, $front)
        {
            $items = array();

            // Resolve translation group "source"
            $src = function_exists('reeid_find_source_post_id')
                ? (int) reeid_find_source_post_id($post, (int)$front)
                : (int) ($post instanceof WP_Post ? $post->ID : 0);
            if ($src <= 0) {
                reeid_sw_debug('GENERIC/NO_SRC', ['post_id' => ($post->ID ?? 0)]);
                return $items;
            }

            $map = (array) get_post_meta($src, '_reeid_translation_map', true);
            $map[$default] = $src; // ensure default present

            // Filter for published only.
            $filtered = array();
            foreach ($map as $code => $pid) {
                $pid = absint($pid);
                if ($pid && get_post_status($pid) === 'publish') {
                    $filtered[strtolower((string)$code)] = $pid;
                }
            }

            foreach ($filtered as $code => $pid) {
                $lang = get_post_meta($pid, '_reeid_translation_lang', true) ?: $code;
                $lang = strtolower((string)$lang);

                // Homepage special-case
                if ((int)$pid === (int)$front) {
                    $url = ($lang === $default) ? home_url('/') : home_url('/' . $lang . '/');
                } else {
                    $url = get_permalink($pid);
                }

                $items[] = ['code' => $lang, 'pid' => $pid, 'url' => $url];
            }

            reeid_sw_debug('GENERIC/ITEMS', ['count' => count($items), 'src' => $src]);
            return $items;
        }
    }

    // Patch: Always use native-slug-aware permalink for each lang
    if (! function_exists('reeid_switcher_collect_product_inline_items')) {
        function reeid_switcher_collect_product_inline_items($post, $default)
        {
            $items = array();
            $post_id = (int) ($post instanceof WP_Post ? $post->ID : 0);
            if ($post_id <= 0) return $items;

            // Gather available inline languages from index meta.
            $inline = (array) get_post_meta($post_id, '_reeid_wc_inline_langs', true);
            $codes  = array_unique(array_filter(array_map('reeid_lang_normalize_10', array_merge([$default], $inline))));

            if (empty($codes)) {
                reeid_sw_debug('PRODUCT_INLINE/NONE', ['post_id' => $post_id]);
                return $items;
            }

            foreach ($codes as $code) {
                // Fetch product translation meta for this lang
                $tr = get_post_meta($post_id, '_reeid_wc_tr_' . $code, true);
                // Use translated slug if present, else fallback to post_name
                $slug = (is_array($tr) && !empty($tr['slug'])) ? $tr['slug'] : get_post_field('post_name', $post_id);
                $slug = rawurldecode($slug);

                if ($code === $default) {
                    $url = home_url("/product/{$slug}/");
                } else {
                    $url = home_url("/{$code}/product/{$slug}/");
                }
                $items[] = ['code' => $code, 'pid' => $post_id, 'url' => $url];
            }


            reeid_sw_debug('PRODUCT_INLINE/ITEMS', ['post_id' => $post_id, 'codes' => $codes, 'count' => count($items)]);
            return $items;
        }
    }


    /**
     * Resolver used to determine CURRENT language on product pages:
     */
    if (! function_exists('reeid_current_lang_for_product')) {
        function reeid_current_lang_for_product($default)
        {
            if (function_exists('reeid_wc_resolve_lang_strong')) {
                $l = (string) reeid_wc_resolve_lang_strong();
                if ($l) return strtolower(substr($l, 0, 10));
            }
            if (! empty($_COOKIE['site_lang'])) {
                $ck = sanitize_text_field(wp_unslash($_COOKIE['site_lang']));
                $ck = strtolower(substr($ck, 0, 10));
                if ($ck) return $ck;
            }
            return strtolower(substr($default, 0, 10));
        }
    }

    /**
     * Enhanced shortcode callback that extends §28.
    */
    if (! function_exists('reeid_language_switcher_shortcode_v2')) {
        function reeid_language_switcher_shortcode_v2()
        {
            global $post;

            $langs   = function_exists('reeid_get_supported_languages') ? (array) reeid_get_supported_languages() : [];
            $flags   = function_exists('reeid_get_language_flags') ? (array) reeid_get_language_flags() : [];
            $default = sanitize_text_field(get_option('reeid_translation_source_lang', 'en'));
            $front   = (int) get_option('page_on_front');

            if (! ($post instanceof WP_Post)) return '';

            $is_product = ($post->post_type === 'product');
            $inline     = $is_product ? (array) get_post_meta($post->ID, '_reeid_wc_inline_langs', true) : [];

            $items   = [];
            $current = '';

            if ($is_product && ! empty($inline)) {
                // Woo inline mode
                $items   = reeid_switcher_collect_product_inline_items($post, $default);
                $current = reeid_current_lang_for_product($default);
                reeid_sw_debug('MODE=PRODUCT_INLINE', ['post_id' => $post->ID, 'current' => $current]);
            } else {
                // Generic §28 map mode
                $items   = reeid_switcher_collect_generic_items($post, $default, $front);
                // Prefer per-post meta (same as §28)
                $current_id = ($post instanceof WP_Post) ? $post->ID : get_queried_object_id();
                $curr_meta  = get_post_meta($current_id, '_reeid_translation_lang', true);
                $current    = strtolower($curr_meta ? $curr_meta : $default);
                reeid_sw_debug('MODE=GENERIC_MAP', ['post_id' => $post->ID, 'current' => $current]);
            }

            if (empty($items)) return '';

            // --- Markup (matches §28 classes/structure) ---
            $out  = '<div id="reeid-switcher-container" class="reeid-dropdown">';
            $out .= '<button type="button" class="reeid-dropdown__btn">';

            if (isset($flags[$current])) {
                $out .= '<img class="reeid-flag-img" src="'
                    . esc_url(plugins_url('assets/flags/' . $flags[$current] . '.svg', __FILE__))
                    . '" alt="' . esc_attr($langs[$current] ?? strtoupper($current)) . '">';
            }

            $out .= '<span class="reeid-dropdown__btn-label">'
                . esc_html($langs[$current] ?? strtoupper($current))
                . '</span><span class="reeid-dropdown__btn-arrow">▾</span></button>';

            $out .= '<ul class="reeid-dropdown__menu">';

            foreach ($items as $it) {
                $code = (string) $it['code'];
                $url  = (string) $it['url'];

                $out .= '<li class="reeid-dropdown__item"><a class="reeid-dropdown__link" href="' . esc_url($url) . '">';
                if (isset($flags[$code])) {
                    $out .= '<img class="reeid-flag-img" src="'
                        . esc_url(plugins_url('assets/flags/' . $flags[$code] . '.svg', __FILE__))
                        . '" alt="' . esc_attr($langs[$code] ?? strtoupper($code)) . '">';
                }
                $out .= '<span class="reeid-dropdown__label">'
                    . esc_html($langs[$code] ?? strtoupper($code))
                    . '</span></a></li>';
            }

            $out .= '</ul></div>';
            return $out;
        }
    }

    /**
     * Re-register the shortcode late (priority 99) to extend §28.
     * Always use v2 logic for [reeid_language_switcher].
     */
    add_action('init', function () {
        remove_shortcode('reeid_language_switcher');
        add_shortcode('reeid_language_switcher', 'reeid_language_switcher_shortcode_v2');
        if (function_exists('reeid_sw_debug')) {
            reeid_sw_debug('SHORTCODE_V2_ENABLED', true);
        }
    }, 99);


    // Helper: Build WooCommerce product URL using inline native slug (if exists)
    if (!function_exists('reeid_switcher_product_permalink')) {
        function reeid_switcher_product_permalink($post, $lang)
        {
            $pid = (int)($post instanceof WP_Post ? $post->ID : 0);
            if ($pid <= 0) return '';
            $default = sanitize_text_field(get_option('reeid_translation_source_lang', 'en'));

            // Always try to get translated slug from WC meta
            $tr = get_post_meta($pid, "_reeid_wc_tr_{$lang}", true);
            if (is_array($tr) && !empty($tr['slug'])) {
                $slug = rawurldecode($tr['slug']);
            } else {
                // fallback: try to use source language
                $tr_src = get_post_meta($pid, "_reeid_wc_tr_{$default}", true);
                $slug = (is_array($tr_src) && !empty($tr_src['slug'])) ? rawurldecode($tr_src['slug']) : $post->post_name;
            }

            // Build permalinks
            if ($lang === $default) {
                return home_url("/product/" . rawurlencode($slug) . "/");
            }
            return home_url("/{$lang}/product/" . rawurlencode($slug) . "/");
        }
    }

/*==============================================================================
    SECTION 48 : REEID Switcher Hard-Off (site-wide header only)
  - Disables the [reeid_lang_switcher] shortcode globally.
  - Neutralizes any previous REEID cart/checkout injections (since they render
    via the shortcode, which now returns '').
  - Strips menu bridge items and hides any stray switcher markup.
==============================================================================*/

    if (! function_exists('reeid_s289_log')) {
        function reeid_s289_log($label, $data = null)
        {
            if (function_exists('reeid_debug_log')) {
                reeid_debug_log('S28.9 ' . $label, $data);
            }
        }
    }

    /* 1) Replace the shortcode with a no-op so any injection echoes nothing */
    add_action('init', function () {
        if (shortcode_exists('reeid_lang_switcher')) {
            remove_shortcode('reeid_lang_switcher');
        }
        add_shortcode('reeid_lang_switcher', function () {
            reeid_s289_log('SHORTCODE_BLOCKED', true);
            return '';
        });
        reeid_s289_log('SHORTCODE_REPLACED', true);
    }, 5);

    /* 2) Double safety: if some plugin/theme calls do_shortcode() directly */
    add_filter('do_shortcode_tag', function ($output, $tag) {
        if ($tag === 'reeid_lang_switcher') {
            reeid_s289_log('DO_SHORTCODE_INTERCEPT', true);
            return '';
        }
        return $output;
    }, 1, 2);

    /* 3) Remove header/menu bridge items that might have been added earlier */
    add_filter('wp_nav_menu_items', function ($items) {
        if (strpos($items, 'menu-item-reeid-switcher') !== false) {
            $clean = preg_replace('#<li[^>]*\bmenu-item-reeid-switcher\b[^>]*>.*?</li>#si', '', $items);
            if ($clean !== null) {
                reeid_s289_log('MENU_BRIDGE_REMOVED', true);
                return $clean;
            }
        }
        return $items;
    }, 5);

    /* 4) CSS guard: hide any leftover switcher markup that might be cached */
    add_action('wp_head', function () { ?>
        <style>
            .menu-item-reeid-switcher,
            .reeid-lang-switcher,
            .reeid-switcher-cart,
            .reeid-switcher-checkout {
                display: none !important;
            }
        </style>
<?php }, 99);

/*==============================================================================
 SECTION 49: UTILITIES & HOUSEKEEPING
==============================================================================*/

    // Force static front page for clean URLs
    add_filter('pre_option_show_on_front', function () {
        return 'page';
    });

    add_filter('pre_option_page_for_posts', function () {
        return 0;
    });

    add_filter('pre_option_page_on_front', function ($value) {
        $page = get_page_by_path('translation-explained');
        return ($page instanceof WP_Post) ? $page->ID : $value;
    });

    function reeid_update_translation_map($source_id, $target_lang, $target_id)
    {
        $map = (array) get_post_meta($source_id, '_reeid_translation_map', true);
        $map[$target_lang] = $target_id;
        update_post_meta($source_id, '_reeid_translation_map', $map);
        update_post_meta($target_id, '_reeid_translation_source', $source_id);
        update_post_meta($target_id, '_reeid_translation_lang', $target_lang);
    }




/*==============================================================================
 SECTION 50: ELEMENTOR PANEL INJECTION 
==============================================================================*/

    if (!function_exists('reeid_get_enabled_languages')) {
        function reeid_get_enabled_languages()
        {
            // STRICT: use only Admin Settings -> reeid_bulk_translation_langs; no fallbacks
            $enabled = get_option('reeid_bulk_translation_langs');
            if (is_array($enabled) && count($enabled)) {
                // Optional: filter against supported, but do NOT introduce any fallback list
                if (function_exists('reeid_get_supported_languages')) {
                    $supported = array_keys(reeid_get_supported_languages());
                    $filtered  = array_values(array_intersect($enabled, $supported));
                    return $filtered;
                }
                return array_values($enabled);
            }
            // If none selected in Admin Settings, return EMPTY (no processing)
            return [];
        }
    }

    add_action('elementor/editor/after_enqueue_styles', function () {
        wp_enqueue_style(
            'reeid-meta-box-styles',
            plugins_url('assets/css/meta-box.css', __FILE__),
            [],
            filemtime(plugin_dir_path(__FILE__) . 'assets/css/meta-box.css')
        );
        wp_add_inline_style('reeid-meta-box-styles', '/* Elementor panel styles omitted for brevity */');
    });

    add_action('elementor/editor/after_enqueue_scripts', function () {
        if (get_option('reeid_license_status', 'invalid') !== 'valid') return;

        $languages = function_exists('reeid_get_supported_languages')
            ? reeid_get_supported_languages()
            : ['fr' => 'French', 'de' => 'German', 'ja' => 'Japanese', 'th' => 'Thai', 'es' => 'Spanish', 'ru' => 'Russian'];

        $picklist_languages = function_exists('reeid_get_allowed_languages')
            ? array_keys(reeid_get_allowed_languages())
            : ['en', 'es', 'fr', 'de', 'zh', 'ja', 'ar', 'ru', 'th', 'it'];

        $enabled_languages = function_exists('reeid_get_enabled_languages')
            ? reeid_get_enabled_languages()
            : [];

        $languages_json          = wp_json_encode($languages, JSON_UNESCAPED_UNICODE);
        $picklist_languages_json = wp_json_encode($picklist_languages, JSON_UNESCAPED_UNICODE);
        $enabled_languages_json  = wp_json_encode($enabled_languages, JSON_UNESCAPED_UNICODE);
        $ajaxurl = esc_url(admin_url('admin-ajax.php'));
        $nonce   = esc_js(wp_create_nonce('reeid_translate_nonce_action'));

        $js =
            '(function(){
        var langs = ' . $languages_json . ';
        var picklistLanguages = ' . $picklist_languages_json . ';
        var enabledLangs = ' . $enabled_languages_json . ';
        var ajaxurl = "' . $ajaxurl . '";
        var nonce   = "' . $nonce . '";
        var panelId = "elementor-panel-page-settings-controls";
        var panelHtml = ' .
            '\'<div id="reeid-elementor-panel" class="reeid-panel">\' +
                \'<div class="reeid-panel-header">REEID TRANSLATION</div>\' +
                \'<div class="reeid-field"><strong>Target Language</strong><select id="reeid_elementor_lang" class="reeid-picklist">\' +
                    Object.entries(langs).filter(function(entry){ return picklistLanguages.includes(entry[0]); }).map(function(entry){ return \'<option value="\' + entry[0] + \'">\' + entry[1] + \'</option>\'; }).join(\'\') +
                \'</select></div>\' +
                \'<div class="reeid-field"><strong>Tone</strong><select id="reeid_elementor_tone" style="width:100%;">\' +
                    \'<option value="">Use default</option>\' +
                    \'<option value="Neutral">Neutral</option>\' +
                    \'<option value="Formal">Formal</option>\' +
                    \'<option value="Informal">Informal</option>\' +
                    \'<option value="Friendly">Friendly</option>\' +
                    \'<option value="Technical">Technical</option>\' +
                    \'<option value="Persuasive">Persuasive</option>\' +
                    \'<option value="Concise">Concise</option>\' +
                    \'<option value="Verbose">Verbose</option>\' +
                \'</select></div>\' +
                \'<div class="reeid-field"><strong>Custom Prompt</strong><textarea id="reeid_elementor_prompt" rows="3" style="width:100%;"></textarea></div>\' +
                \'<div class="reeid-field"><strong>Publish Mode</strong><select id="reeid_elementor_mode" style="width:100%;">\' +
                    \'<option value="publish">Publish</option>\' +
                    \'<option value="draft">Save as Draft</option>\' +
                \'</select></div>\' +
                \'<div class="reeid-buttons">\' +
                    \'<button type="button" class="reeid-button primary" id="reeid_elementor_translate">Translate Now</button>\' +
                    \'<button type="button" class="reeid-button secondary" id="reeid_elementor_bulk">Bulk Translate</button>\' +
                \'</div>\' +
                \'<div id="reeid-status"></div>\' +
            \'</div>\';

        function getPostId(){
            if (window.elementor && window.elementor.config && window.elementor.config.post_id) return elementor.config.post_id;
            if (window.elementorCommon && window.elementorCommon.config && window.elementorCommon.config.post_id) return elementorCommon.config.post_id;
            if (window.elementor && window.elementor.settings && window.elementor.settings.page && window.elementor.settings.page.model && window.elementor.settings.page.model.id) return elementor.settings.page.model.id;
            var match = window.location.search.match(/[?&]post=(\\d+)/);
            return match ? match[1] : null;
        }

        function startBulkTranslation() {
            var jq    = window.jQuery;
            var pid   = getPostId();
            var tone  = jq("#reeid_elementor_tone").val() || "Neutral";
            var prompt= jq("#reeid_elementor_prompt").val() || "";
            var mode  = jq("#reeid_elementor_mode").val() || "publish";

            // STRICT: only Admin-enabled languages; do not fallback to any list
            var languageCodes = Array.isArray(enabledLangs)
                ? enabledLangs.filter(function(lang){ return Object.prototype.hasOwnProperty.call(langs, lang); })
                : [];

            // === HARD STOP (single message) if none selected in Admin Settings ===
            if (!languageCodes.length) {
                jq("#reeid-status").html(\'<span style="color:#c00;">❌ No bulk languages selected in Settings. Please choose at least one in “Bulk Translation Languages”.</span>\');
                return;
            }

            var results = {};
            var currentIndex = 0;
            var statusEl = document.getElementById("reeid-status");
            statusEl.innerHTML = "";
            var progressContainer = document.createElement("div");
            progressContainer.style.marginBottom = "10px";
            progressContainer.innerHTML = \'<div style="font-weight:bold;">Progress: <span id="reeid-bulk-progress">0/\' + languageCodes.length + \'</span></div>\';
            statusEl.appendChild(progressContainer);

            function processNextLanguage() {
                if (currentIndex >= languageCodes.length) {
                    return;
                }
                var lang  = languageCodes[currentIndex];
                var label = langs[lang] || lang.toUpperCase();
                document.getElementById("reeid-bulk-progress").textContent = (currentIndex + 1) + "/" + languageCodes.length;

                var row = document.createElement("div");
                row.className = "reeid-status-row";
                row.innerHTML =
                    \'<span class="reeid-status-emoji">⏳</span>\' +
                    \'<span class="reeid-status-lang">\' + label + \':</span>\' +
                    \'<span>Processing...</span>\';
                statusEl.appendChild(row);

                jq.post(ajaxurl, {
                    action: "reeid_translate_openai",
                    reeid_translate_nonce: nonce,
                    post_id: pid,
                    lang: lang,
                    tone: tone,
                    prompt: prompt,
                    reeid_publish_mode: mode
                }).done(function(res) {
                    row.innerHTML =
                        \'<span class="reeid-status-emoji">\' + (res.success ? "✅" : "❌") + \'</span>\' +
                        \'<span class="reeid-status-lang">\' + label + \':</span>\' +
                        \'<span>\' + (res.success ? "Done" : (res.data && (res.data.error || res.data.message) ? (res.data.error || res.data.message) : "Failed")) + \'</span>\';
                    results[lang] = { success: res.success };
                }).fail(function() {
                    row.innerHTML =
                        \'<span class="reeid-status-emoji">❌</span>\' +
                        \'<span class="reeid-status-lang">\' + label + \':</span>\' +
                        \'<span>AJAX failed</span>\';
                    results[lang] = { success: false };
                }).always(function() {
                    currentIndex++;
                    setTimeout(processNextLanguage, 500);
                });
            }
            processNextLanguage();
        }

        function bindReeidBulkButtonHandler() {
            // No popup, no beforeunload — either run or show one message
            window.jQuery("#reeid_elementor_bulk").off("click.reeidmodal").on("click.reeidmodal", function(e){
                e.preventDefault();
                startBulkTranslation();
            });
        }

        function injectPanel() {
            var panel = document.getElementById(panelId);
            if (!panel || document.getElementById("reeid-elementor-panel")) return;
            panel.insertAdjacentHTML("beforeend", panelHtml);
            var jq = window.jQuery;
            jq("#reeid_elementor_translate").off().on("click", function(e){
                e.preventDefault();
                var $btn = jq(this);
                $btn.prop("disabled", true).text("Translating...");
                jq("#reeid-status").html("⏳ Translating...");
                var pid = getPostId();
                if (!pid) {
                    jq("#reeid-status").html(\'<span style="color:#c00;">❌ Post ID not found</span>\');
                    $btn.prop("disabled", false).text("Translate Now");
                    return;
                }
                jq.post(ajaxurl, {
                    action: "reeid_translate_openai",
                    reeid_translate_nonce: nonce,
                    post_id: pid,
                    lang: jq("#reeid_elementor_lang").val(),
                    tone: jq("#reeid_elementor_tone").val() || "Neutral",
                    prompt: jq("#reeid_elementor_prompt").val() || "",
                    reeid_publish_mode: jq("#reeid_elementor_mode").val() || "publish"
                }).done(function(res){
                    if (res.success) {
                        jq("#reeid-status").html(\'<span style="color:#32c24d;font-weight:bold;">✅ \' + (res.data && res.data.message ? res.data.message : "Translation completed.") + \'</span>\');
                    } else {
                        jq("#reeid-status").html(\'<span style="color:#c00;font-weight:bold;">❌ \' + (res.data && (res.data.error || res.data.message) ? (res.data.error || res.data.message) : "Translation failed.") + \'</span>\');
                    }
                }).fail(function(){
                    jq("#reeid-status").html(\'<span style="color:#c00;font-weight:bold;">❌ AJAX failed. Try again.</span>\');
                }).always(function(){
                    $btn.prop("disabled", false).text("Translate Now");
                });
            });
            bindReeidBulkButtonHandler();
        }

        function watchdog() {
            var currentPanel = null;
            var mo = null;
            function attachObserver() {
                var panel = document.getElementById(panelId);
                if (!panel) {
                    setTimeout(attachObserver, 400);
                    return;
                }
                if (currentPanel && currentPanel !== panel && mo) {
                    mo.disconnect();
                    mo = null;
                }
                if (!mo) {
                    mo = new MutationObserver(function(){ injectPanel(); });
                    mo.observe(panel, { childList:true, subtree:true });
                }
                currentPanel = panel;
                injectPanel();
            }
            setInterval(attachObserver, 1000);
            attachObserver();
        }
        watchdog();
    })();';

        wp_add_inline_script('elementor-editor', $js);
    });




 /*==============================================================================
  SECTION 51 : UTF-8 Slug Router
  - Allows non-Latin slugs (/ar/الذكاء-الاصطناعي-في-الترجمة/) to resolve.
  - Queries post by name with UTF-8 aware regex.
==============================================================================*/
    if (! function_exists('reeid_utf8_slug_router')) {
        add_action('template_redirect', 'reeid_utf8_slug_router', 1);
        function reeid_utf8_slug_router()
        {
            if (is_admin() || is_feed() || is_robots()) return;

            $request_uri = isset($_SERVER['REQUEST_URI']) ? urldecode($_SERVER['REQUEST_URI']) : '';
            $parts = wp_parse_url($request_uri);
            $path  = isset($parts['path']) ? trim($parts['path'], '/') : '';

            // Match /{lang}/{slug...}
            if (preg_match('#^([a-z]{2})/(.+)$#u', $path, $m)) {
                $lang = $m[1];
                $slug = $m[2];

                $q = new WP_Query([
                    'name'           => $slug,
                    'post_type'      => 'any',
                    'posts_per_page' => 1,
                ]);

                if ($q->have_posts()) {
                    global $wp_query;
                    $wp_query = $q;
                    status_header(200);
                    return;
                }
            }
        }
    }


    /* ==================================================================================
    SECTION 52 :  REEID API INTEGRATION — COMBINED 
    =================================================================================== */

    if (! defined('REEID_API_BASE'))          define('REEID_API_BASE', 'https://api.reeid.com');

    if (! defined('REEID_OPT_SITE_UUID'))     define('REEID_OPT_SITE_UUID',   'reeid_site_uuid');
    if (! defined('REEID_OPT_SITE_TOKEN'))    define('REEID_OPT_SITE_TOKEN',  'reeid_site_token');
    if (! defined('REEID_OPT_SITE_SECRET'))   define('REEID_OPT_SITE_SECRET', 'reeid_site_secret');
    if (! defined('REEID_OPT_KP_SECRET'))     define('REEID_OPT_KP_SECRET',   'reeid_kp_secret');   // base64 libsodium keypair
    if (! defined('REEID_OPT_TOKEN_TS'))      define('REEID_OPT_TOKEN_TS',    'reeid_token_issued_at');
    if (! defined('REEID_OPT_FEATURES'))      define('REEID_OPT_FEATURES',    'reeid_features');
    if (! defined('REEID_OPT_LIMITS'))        define('REEID_OPT_LIMITS',      'reeid_limits');

    if (! function_exists('reeid_get_site_uuid')) {
        /** Ensure we have a persistent site UUID. */
        function reeid_get_site_uuid(): string
        {
            $uuid = get_option(REEID_OPT_SITE_UUID);
            if (! $uuid) {
                $uuid = wp_generate_uuid4();
                update_option(REEID_OPT_SITE_UUID, $uuid, true);
            }
            return (string)$uuid;
        }
    }

    if (! function_exists('reeid_nonce_hex')) {
        function reeid_nonce_hex(int $bytes = 12): string
        {
            return bin2hex(random_bytes($bytes));
        }
    }
    if (! function_exists('reeid_hmac_sig')) {
        function reeid_hmac_sig(string $ts, string $nonce, string $body, string $secret): string
        {
            return hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $body, $secret);
        }
    }
    if (! function_exists('reeid_wp_post')) {
        function reeid_wp_post(string $path, array $bodyArr, array $headers = [], int $timeout = 20)
        {
            $url  = rtrim(REEID_API_BASE, '/') . $path;
            $resp = wp_remote_post($url, [
                'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
                'body'    => wp_json_encode($bodyArr, JSON_UNESCAPED_UNICODE),
                'timeout' => $timeout,
            ]);
            if (is_wp_error($resp)) return $resp;
            $code = (int) wp_remote_retrieve_response_code($resp);
            $json = json_decode((string) wp_remote_retrieve_body($resp), true);
            return ['code' => $code, 'json' => $json];
        }
    }

    /* ---------- Libsodium helpers: persist keypair and derive public key ---------- */
    if (! function_exists('reeid_sodium_have')) {
        function reeid_sodium_have(): bool
        {
            return function_exists('sodium_crypto_box_keypair')
                && function_exists('sodium_crypto_box_publickey')
                && function_exists('sodium_crypto_box_seal_open');
        }
    }
    if (! function_exists('reeid_get_or_make_kp_b64')) {
        /** Returns [keypair_b64, pub_b64]; persists the keypair option if new. */
        function reeid_get_or_make_kp_b64(): array
        {
            if (! reeid_sodium_have()) return [null, null];
            $kp_b64 = get_option(REEID_OPT_KP_SECRET, '');
            if (! $kp_b64) {
                $kp     = sodium_crypto_box_keypair();
                $kp_b64 = base64_encode($kp);
                update_option(REEID_OPT_KP_SECRET, $kp_b64, true);
            } else {
                $kp = base64_decode($kp_b64, true);
                if ($kp === false) {
                    // corrupted option, rebuild
                    $kp     = sodium_crypto_box_keypair();
                    $kp_b64 = base64_encode($kp);
                    update_option(REEID_OPT_KP_SECRET, $kp_b64, true);
                }
            }
            $pub_b64 = base64_encode(sodium_crypto_box_publickey(base64_decode($kp_b64, true)));
            return [$kp_b64, $pub_b64];
        }
    }

    /* ---------------------------- Handshake (cached) ---------------------------- */
    if (! function_exists('reeid_api_handshake')) {
        /**
         * Fetch site_token + site_secret. Refreshes every ~22h or on $force.
         * Requires libsodium (server requires client_pubkey).
         */
        function reeid_api_handshake(bool $force = false): array
        {
            $site_token = (string) get_option(REEID_OPT_SITE_TOKEN, '');
            $issued_at  = (int)    get_option(REEID_OPT_TOKEN_TS, 0);

            if (! $force && $site_token && (time() - $issued_at) < 22 * 3600) {
                return [
                    'ok'          => true,
                    'site_token'  => $site_token,
                    'site_secret' => (string) get_option(REEID_OPT_SITE_SECRET, ''),
                    'sealed'      => (bool) get_option(REEID_OPT_KP_SECRET, ''),
                ];
            }

            if (! reeid_sodium_have()) {
                return ['ok' => false, 'error' => 'libsodium_missing'];
            }
            list($kp_b64, $pub_b64) = reeid_get_or_make_kp_b64();
            if (empty($pub_b64)) {
                return ['ok' => false, 'error' => 'keypair_init_failed'];
            }

            $payload = [
                'license_key'    => trim((string) get_option('reeid_license_key', 'REPLACE_ME')), // adjust to your stored license option
                'site_url'       => home_url(),
                'site_uuid'      => reeid_get_site_uuid(),
                'plugin_version' => defined('REEID_PLUGIN_VERSION') ? REEID_PLUGIN_VERSION : '1.0.0',
                'wp_version'     => get_bloginfo('version'),
                'php_version'    => PHP_VERSION,
                'client_pubkey'  => $pub_b64, // REQUIRED by server
            ];

            $res = reeid_wp_post('/v1/license/handshake', $payload);
            if (is_wp_error($res) || !is_array($res['json'] ?? null) || empty($res['json']['ok'])) {
                return ['ok' => false, 'error' => 'handshake_failed', 'detail' => is_wp_error($res) ? $res->get_error_message() : ($res['json']['code'] ?? 'http_' . $res['code'])];
            }

            $j = $res['json'];
            update_option(REEID_OPT_SITE_TOKEN,  (string)($j['site_token']  ?? ''), true);
            update_option(REEID_OPT_SITE_SECRET, (string)($j['site_secret'] ?? ''), true);
            update_option(REEID_OPT_TOKEN_TS,    time(), true);
            if (isset($j['features'])) update_option(REEID_OPT_FEATURES, (array)$j['features'], false);
            if (isset($j['limits']))   update_option(REEID_OPT_LIMITS,   (array)$j['limits'],   false);

            return ['ok' => true, 'site_token' => $j['site_token'] ?? '', 'site_secret' => $j['site_secret'] ?? '', 'sealed' => true];
        }
    }

    /* -------------------- /v1/rules/plan (signed + sealed) --------------------- */
    if (! function_exists('reeid_api_rules_plan')) {
        /**
         * Ask server for model/params/system (rulepack), decrypt sealed response.
         * Returns array ['model','params','system','flags',...] or WP_Error.
         */
        function reeid_api_rules_plan(string $source_lang, string $target_lang, string $editor, string $tone = 'neutral', $content_ctx = '')
        {
            // Ensure we have token/secret (auto-refresh if needed)
            $hs = reeid_api_handshake(false);
            if (! $hs['ok']) {
                $hs = reeid_api_handshake(true);
                if (! $hs['ok']) return new WP_Error('reeid_handshake', 'Handshake failed: ' . ($hs['detail'] ?? $hs['error'] ?? 'unknown'));
            }

            $site_token  = (string) get_option(REEID_OPT_SITE_TOKEN, '');
            $site_secret = (string) get_option(REEID_OPT_SITE_SECRET, '');
            if ($site_token === '' || $site_secret === '') {
                return new WP_Error('reeid_missing_creds', 'Missing site token/secret');
            }
            if (! reeid_sodium_have()) {
                return new WP_Error('reeid_sodium_missing', 'PHP libsodium missing');
            }
            list($kp_b64, $pub_b64) = reeid_get_or_make_kp_b64();
            $kp_bin = base64_decode((string)$kp_b64, true);
            if ($kp_bin === false) return new WP_Error('reeid_kp_corrupt', 'Stored sodium keypair invalid');

            $tone = strtolower((string)($tone ?: 'neutral'));
            $content_hash = (is_string($content_ctx) && preg_match('/^[a-f0-9]{64}$/', $content_ctx))
                ? $content_ctx
                : hash('sha256', is_string($content_ctx) ? $content_ctx : wp_json_encode($content_ctx));

            $bodyArr = [
                'site_token'   => $site_token,
                'source_lang'  => $source_lang,
                'target_lang'  => $target_lang,
                'editor'       => $editor,     // 'gutenberg'|'elementor'|'classic'
                'tone'         => $tone,
                'content_hash' => $content_hash,
            ];
            $bodyJson = wp_json_encode($bodyArr, JSON_UNESCAPED_UNICODE);
            $ts       = (string) time();
            $nonce    = reeid_nonce_hex(12);
            $sig      = reeid_hmac_sig($ts, $nonce, $bodyJson, $site_secret);

            $res = reeid_wp_post('/v1/rules/plan', $bodyArr, [
                'X-REEID-Ts'    => $ts,
                'X-REEID-Nonce' => $nonce,
                'X-REEID-Sig'   => $sig,
            ]);
            if (is_wp_error($res))                  return $res;
            if ((int)($res['code'] ?? 500) === 401) return new WP_Error('reeid_unauthorized', 'Invalid token/secret');
            if ((int)($res['code'] ?? 500) === 429) return new WP_Error('reeid_throttled',   'Rate limit exceeded');
            if (empty($res['json']['ok']))         return new WP_Error('reeid_api_error',    'API error: ' . (string)($res['json']['code'] ?? 'http_' . $res['code']));

            $sip_b64 = (string)($res['json']['sip_token'] ?? '');
            $cipher  = base64_decode($sip_b64, true);
            if ($cipher === false) return new WP_Error('reeid_sip_decode', 'Bad sip_token');

            $plain = sodium_crypto_box_seal_open($cipher, $kp_bin);
            if ($plain === false) return new WP_Error('reeid_sip_decrypt', 'Failed to decrypt sip_token');

            $rulepack = json_decode($plain, true);
            if (! is_array($rulepack)) return new WP_Error('reeid_sip_parse', 'Decrypted rulepack not JSON');

            return $rulepack;
        }
    }

    /* -------------------- Handshake auto-refresh (cron + first run) -------------------- */

    /** Schedule every 12h (first run ~5 min after activation) */
    add_action('init', function () {
        if (! wp_next_scheduled('reeid_refresh_handshake_event')) {
            wp_schedule_event(time() + 300, 'twicedaily', 'reeid_refresh_handshake_event');
        }
    });

    /** Unschedule on deactivation */
    if (function_exists('register_deactivation_hook')) {
        register_deactivation_hook(__FILE__, function () {
            $ts = wp_next_scheduled('reeid_refresh_handshake_event');
            if ($ts) wp_unschedule_event($ts, 'reeid_refresh_handshake_event');
        });
    }

    /** The job: call handshake (cached; will refresh as needed) */
    add_action('reeid_refresh_handshake_event', function () {
        try {
            reeid_api_handshake(false);
        } catch (Throwable $e) {
        }
    });

    /** Kick a first handshake if missing when an admin page is loaded */
    add_action('admin_init', function () {
        if (get_option(REEID_OPT_SITE_TOKEN) && get_option(REEID_OPT_SITE_SECRET)) return;
        try {
            reeid_api_handshake(true);
        } catch (Throwable $e) {
        }
    });

    if (! function_exists('reeid_translate_html_with_openai')) {
        /**
         * Prompt-aware short/medium text translator used by Gutenberg/Classic/Woo.
         * Backward-compatible signature: we add $prompt as the last, optional param.
         *
         * @param string $text
         * @param string $source_lang  e.g., 'en'
         * @param string $target_lang  e.g., 'th'
         * @param string $editor       'gutenberg' | 'classic' | 'elementor' | 'woocommerce'
         * @param string $tone         default 'Neutral'
         * @param string $prompt       (NEW) merged custom prompt (per-request/admin); can be ''
         * @return string              translated text (or original on failure)
         */
        function reeid_translate_html_with_openai(
            string $text,
            string $source_lang,
            string $target_lang,
            string $editor,
            string $tone = 'Neutral',
            string $prompt = ''
        ): string {
            $api_key = (string) get_option('reeid_openai_api_key', '');
            if ($text === '' || $source_lang === $target_lang || $api_key === '') {
                return $text;
            }

            // Build strict system message + optional custom instructions
            // Prefer layered helper; fall back to a safe neutral system message
if ( function_exists( 'reeid_get_combined_prompt' ) ) {
    // function has $prompt param (already merged at call sites); post_id not available => 0
    $sys = reeid_get_combined_prompt( 0, $target_lang, (string) $prompt );
} else {
    $sys = "You are a professional translator. Translate the source text from {$source_lang} to {$target_lang}, preserving structure, tags and placeholders. Match tone and produce idiomatic, human-quality output.";
    if ( is_string( $prompt ) && trim( $prompt ) !== '' ) {
        $sys .= ' ' . trim( $prompt );
    }
}


            // Compose OpenAI payload
            $payload = json_encode([
                "model"       => "gpt-4o",
                "temperature" => 0,
                "messages"    => [
                    ["role" => "system", "content" => $sys],
                    ["role" => "user",   "content" => (string) $text]
                ]
            ], JSON_UNESCAPED_UNICODE);

            // Call OpenAI
            $ch = curl_init("https://api.openai.com/v1/chat/completions");
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER     => [
                    "Content-Type: application/json",
                    "Authorization: Bearer " . $api_key
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_TIMEOUT        => 45,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_ENCODING       => ''
            ]);
            $resp = curl_exec($ch);
            if ($resp === false) {
                if (function_exists('reeid_debug_log')) {
                    reeid_debug_log('S17/SHORT/CURL', curl_error($ch));
                }
                curl_close($ch);
                return $text;
            }
            curl_close($ch);

            $json = json_decode($resp, true);
            $out  = isset($json['choices'][0]['message']['content']) ? (string)$json['choices'][0]['message']['content'] : '';
            $out  = trim($out);

            // Strip code fences & accidental wrappers
            if ($out !== '') {
                $out = preg_replace('/^```(?:json|[a-zA-Z0-9_-]+)?\s*/', '', $out);
                $out = preg_replace('/\s*```$/', '', $out);
                $out = trim($out);
            }

            // Safety: avoid returning empty or identical content
            if ($out === '' || $out === $text) {
                return $text;
            }

            return $out;
        }
    }

    /*=======================================================================================
         SECTION 53: Elementor Frontend bootstrap 
     * A) Ensure elementorFrontendConfig exists BEFORE Elementor scripts (inline BEFORE handles)
     * B) Force init if an optimizer delayed things
     * C) Strip async/defer from critical Elementor/jQuery handles
     ========================================================================================*/

    /* === A) Provide config BEFORE Elementor’s own bundle === */
    add_action('wp_enqueue_scripts', function () {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        $assets_url = '';
        $version    = '';
        $is_debug   = (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG);

        if (class_exists('\Elementor\Plugin')) {
            try {
                $assets_url = plugins_url('elementor/assets/', WP_PLUGIN_DIR . '/elementor/elementor.php');
                if (method_exists(\Elementor\Plugin::$instance, 'get_version')) {
                    $version = (string) \Elementor\Plugin::$instance->get_version();
                }
            } catch (\Throwable $e) {
            }
        }
        if (! $assets_url) {
            $assets_url = plugins_url('elementor/assets/');
        }
        if (! $version) {
            $version    = '3.x';
        }

        $cfg = array(
            'urls' => array(
                'assets'    => rtrim($assets_url, '/') . '/',
                'uploadUrl' => content_url('uploads/'),
            ),
            'environmentMode' => array(
                'edit'          => false,
                'wpPreview'     => false,
                'isScriptDebug' => $is_debug,
            ),
            'version'    => $version,
            'settings'   => array('page' => new stdClass()),
            'responsive' => array(
                'hasCustomBreakpoints' => false,
                'breakpoints'          => new stdClass(),
            ),
            'is_rtl' => is_rtl(),
            'i18n'   => new stdClass(),
        );

        $shim = 'window.elementorFrontendConfig = window.elementorFrontendConfig || ' .
            wp_json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';';

        $handles_before = array(
            'elementor-webpack-runtime',
            'elementor-frontend',
            'elementor-pro-frontend',
        );

        foreach ($handles_before as $handle) {
            if (wp_script_is($handle, 'registered') || wp_script_is($handle, 'enqueued')) {
                wp_add_inline_script($handle, $shim, 'before');
            }
        }
    }, 1);

    /* === B) Force-init Elementor if an optimizer delayed execution === */
    add_action('wp_enqueue_scripts', function () {
        if (is_admin() || wp_doing_ajax()) {
            return;
        }

        $bootstrap =
            '(function(w,$){' .
            ' function safeInit(){' .
            '  if(!w.elementorFrontend) return;' .
            '  try{' .
            '   if(!elementorFrontend.hooks){elementorFrontend.init();}' .
            '   if(elementorFrontend.onDocumentLoaded){elementorFrontend.onDocumentLoaded();}' .
            '  }catch(e){}' .
            ' }' .
            ' if($){$(safeInit);$(w).on("load",safeInit);}else{' .
            '  w.addEventListener("DOMContentLoaded",safeInit);w.addEventListener("load",safeInit);' .
            ' }' .
            '})(window,window.jQuery);';

        $targets_after = array('elementor-frontend', 'elementor-pro-frontend');
        foreach ($targets_after as $handle) {
            if (wp_script_is($handle, 'registered') || wp_script_is($handle, 'enqueued')) {
                wp_add_inline_script($handle, $bootstrap, 'after');
            }
        }
    }, 20);

    /* === C) Remove async/defer attributes from critical handles so order is preserved === */
    add_filter('script_loader_tag', function ($tag, $handle) {
        $critical = array(
            'jquery',
            'jquery-core',
            'jquery-migrate',
            'elementor-webpack-runtime',
            'elementor-frontend',
            'elementor-frontend-modules',
            'elementor-pro-frontend',
        );
        if (in_array($handle, $critical, true)) {
            $tag = str_replace(array(' async="async"', ' async', ' defer="defer"', ' defer'), '', $tag);
        }
        return $tag;
    }, 20, 2);

    /**
     * REEID — Elementor meta verifier/repair for translated pages
     * ?reeid_check=1&_wpnonce=...  |  ?reeid_repair=1&_wpnonce=...
     */
    add_action('template_redirect', function () {
        if (! is_user_logged_in() || ! current_user_can('manage_options')) {
            return;
        }

        $check_raw  = filter_input(INPUT_GET, 'reeid_check', FILTER_DEFAULT);
        $repair_raw = filter_input(INPUT_GET, 'reeid_repair', FILTER_DEFAULT);
        $nonce_raw  = filter_input(INPUT_GET, '_wpnonce', FILTER_DEFAULT);

        $do_check  = ($check_raw  && '1' === sanitize_text_field(wp_unslash($check_raw)));
        $do_repair = ($repair_raw && '1' === sanitize_text_field(wp_unslash($repair_raw)));
        $nonce     = $nonce_raw ? sanitize_text_field(wp_unslash($nonce_raw)) : '';

        if (! $do_check && ! $do_repair) {
            return;
        }
        if (! $nonce || ! wp_verify_nonce($nonce, 'reeid_diag_action')) {
            wp_die(esc_html__('Security check failed for REEID diagnostic.', 'reeid-translate'));
        }

        global $post;
        if (! $post) {
            wp_die(esc_html__('No global $post on this request.', 'reeid-translate'));
        }
        $pid = (int) $post->ID;

        $pp = static function ($label, $value) {
            echo esc_html((string) $label), ': ';
            if (is_array($value) || is_object($value)) {
                echo wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
            } else {
                echo esc_html((string) $value), "\n";
            }
        };

        header('Content-Type: text/plain; charset=UTF-8');
        echo esc_html__('REEID Elementor Meta', 'reeid-translate') . ' ' . esc_html($do_repair ? 'REPAIR' : 'CHECK') . ' — ' .
            esc_html__('Post ID:', 'reeid-translate') . ' ' . esc_html((string) $pid) . "\n";
        echo esc_html(str_repeat('=', 72)) . "\n\n";

        $keys = array(
            '_elementor_data',
            '_elementor_edit_mode',
            '_elementor_template_type',
            '_elementor_version',
            '_elementor_page_settings',
            '_elementor_css',
        );

        $meta = array();
        foreach ($keys as $k) {
            $meta[$k] = get_post_meta($pid, $k, true);
        }

        foreach ($meta as $k => $v) {
            if ('_elementor_data' === $k) {
                $head = substr((string) $v, 0, 400);
                $pp($k . ' (head 400 chars)', $head);
            } else {
                $pp($k, is_scalar($v) ? $v : (is_array($v) ? '[array]' : (is_object($v) ? '[object]' : gettype($v))));
            }
        }
        echo "\n";

        $problems = array();

        $raw     = $meta['_elementor_data'];
        $decoded = null;
        if (is_string($raw) && '' !== $raw) {
            $decoded = json_decode($raw, true);
            if (JSON_ERROR_NONE !== json_last_error() || ! is_array($decoded)) {
                $try = json_decode(stripslashes($raw), true);
                if (JSON_ERROR_NONE === json_last_error() && is_array($try)) {
                    $decoded = $try;
                }
            }
        } else {
            $problems[] = '_elementor_data is empty or missing';
        }

        if (is_null($decoded)) {
            $problems[] = '_elementor_data not valid JSON for Elementor';
        }
        if (empty($meta['_elementor_edit_mode']) || 'builder' !== $meta['_elementor_edit_mode']) {
            $problems[] = '_elementor_edit_mode should be "builder"';
        }
        if (empty($meta['_elementor_template_type'])) {
            $problems[] = '_elementor_template_type is missing (typically "wp-page")';
        }
        if (empty($meta['_elementor_version'])) {
            $problems[] = '_elementor_version is missing';
        }

        if (empty($problems)) {
            echo esc_html__('Checks: OK — Elementor should render this page from _elementor_data.', 'reeid-translate') . "\n";
        } else {
            echo esc_html__('Problems detected:', 'reeid-translate') . "\n - " . esc_html(implode("\n - ", $problems)) . "\n";
        }

        if (! $do_repair) {
            exit;
        }

        echo "\n" . esc_html__('Attempting repair…', 'reeid-translate') . "\n";

        if (is_null($decoded) && is_string($raw) && '' !== $raw) {
            $decoded = json_decode(stripslashes($raw), true);
        }

        if (is_array($decoded)) {
            $json = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            update_post_meta($pid, '_elementor_data', $json);
            echo esc_html__('Fixed _elementor_data (normalised JSON, unescaped).', 'reeid-translate') . "\n";
        } else {
            echo esc_html__('WARNING: Could not decode/repair _elementor_data. (It may be missing.)', 'reeid-translate') . "\n";
        }

        if (empty($meta['_elementor_edit_mode'])) {
            update_post_meta($pid, '_elementor_edit_mode', 'builder');
            echo esc_html__('Set _elementor_edit_mode=builder', 'reeid-translate') . "\n";
        }
        if (empty($meta['_elementor_template_type'])) {
            update_post_meta($pid, '_elementor_template_type', 'wp-page');
            echo esc_html__('Set _elementor_template_type=wp-page', 'reeid-translate') . "\n";
        }
        if (empty($meta['_elementor_version'])) {
            $ver = defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.x';
            update_post_meta($pid, '_elementor_version', $ver);
            echo esc_html__('Set _elementor_version=', 'reeid-translate') . esc_html((string) $ver) . "\n";
        }
        if (empty($meta['_elementor_page_settings'])) {
            update_post_meta($pid, '_elementor_page_settings', array());
            echo esc_html__('Initialised _elementor_page_settings', 'reeid-translate') . "\n";
        }

        $css_ok = false;
        if (class_exists('\Elementor\Core\Files\CSS\Post')) {
            try {
                $css = \Elementor\Core\Files\CSS\Post::create($pid);
                if ($css) {
                    $css->update();
                    $css_ok = true;
                }
            } catch (\Throwable $e) {
            }
        }
        if ($css_ok) {
            echo esc_html__('Regenerated Elementor CSS for post.', 'reeid-translate') . "\n";
        }

        if (class_exists('\Elementor\Plugin')) {
            try {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
                echo esc_html__('Cleared Elementor files cache.', 'reeid-translate') . "\n";
            } catch (\Throwable $e) {
            }
        }

        echo "\n" . esc_html__('Repair complete. Hard-refresh this page.', 'reeid-translate') . "\n";
        exit;
    });

    /* === Page 2095: harden Elementor bootstrap & init (tabs/text-path) === */
    add_action('template_redirect', function () { if (function_exists('reeid_hreflang_print')) remove_action('wp_head', 'reeid_hreflang_print', 90); add_action('wp_head', 'reeid_hreflang_print_canonical', 90); }, 0);

    add_filter('rest_pre_serve_request', function ($served, $result, $request, $server) {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (! headers_sent()) {
            header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        }
        return $served; // let core print the JSON
    }, 9999, 4);

    /**
     * REEID — AJAX JSON guard (prevents stray output from breaking admin-ajax responses)
     * Applies only to our plugin's AJAX actions (prefix "reeid_" or "reeid-").
     */
    add_action('init', function () {
        if (! wp_doing_ajax()) {
            return;
        }

        // Get action without superglobals.
        $act_post = filter_input(INPUT_POST, 'action', FILTER_UNSAFE_RAW);
        $act_get  = filter_input(INPUT_GET,  'action', FILTER_UNSAFE_RAW);
        $act_raw  = is_string($act_post) ? $act_post : (is_string($act_get) ? $act_get : '');
        $action   = sanitize_key(wp_unslash((string) $act_raw));

        // Scope to our plugin’s AJAX only.
        if ('' === $action || ! preg_match('/^reeid[_-]/', $action)) {
            return;
        }

        // ---- Nonce verification (soft: only enforce if a nonce was provided) ----
        $nonce_candidates = array(
            array(INPUT_POST, 'nonce'),
            array(INPUT_POST, 'security'),
            array(INPUT_POST, '_ajax_nonce'),
            array(INPUT_POST, '_wpnonce'),
            array(INPUT_GET,  'nonce'),
            array(INPUT_GET,  'security'),
            array(INPUT_GET,  '_ajax_nonce'),
            array(INPUT_GET,  '_wpnonce'),
        );

        $nonce_seen = false;
        $nonce_ok   = false;

        foreach ($nonce_candidates as $src) {
            $val = filter_input($src[0], $src[1], FILTER_UNSAFE_RAW);
            if (is_string($val) && $val !== '') {
                $nonce_seen = true;
                $nonce_val  = sanitize_text_field(wp_unslash($val));
                // Accept either the shared plugin action or an action-specific nonce.
                if (wp_verify_nonce($nonce_val, 'reeid_translate_nonce_action') || wp_verify_nonce($nonce_val, $action)) {
                    $nonce_ok = true;
                }
                break;
            }
        }

        if ($nonce_seen && ! $nonce_ok) {
            if (! headers_sent()) {
                status_header(403);
                header('Content-Type: application/json; charset=' . get_option('blog_charset'));
                nocache_headers();
            }
            echo wp_json_encode(array('ok' => false, 'error' => 'invalid_nonce'));
            exit;
        }
        // If no nonce provided, do not block here (your concrete action should verify).

        // ---- Output guard: clean buffer and normalize trailing JSON (if valid) ----
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        ob_start();

        // JSON headers early (idempotent).
        add_action('send_headers', function () {
            if (! headers_sent()) {
                header('Content-Type: application/json; charset=' . get_option('blog_charset'));
                header('X-Content-Type-Options: nosniff');
                nocache_headers();
            }
        }, 0);

        // Normalize ONLY when a valid JSON tail is present; otherwise leave output intact.
        add_action('shutdown', function () {
            // Do not interfere with WP fatal handler.
            $last = error_get_last();
            if ($last && in_array($last['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
                return;
            }

            $out = ob_get_contents();
            if (! is_string($out) || '' === $out) {
                return;
            }

            if (preg_match('/\{[\s\S]*\}\s*$/u', $out, $match)) {
                $decoded = json_decode($match[0], true);
                if (JSON_ERROR_NONE === json_last_error()) {
                    if (! headers_sent()) {
                        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
                        header('X-Content-Type-Options: nosniff');
                    }
                    while (ob_get_level()) {
                        @ob_end_clean();
                    }
                    echo wp_json_encode($decoded); // safe JSON output
                }
                // If invalid, do nothing — let original output flush.
            }
        }, 9999);
    });

    /* RT_CORE_HELPERS (idempotent) — cookie force + asset ensure */
    if (! defined('RT_CORE_HELPERS_BOOT')) {
        define('RT_CORE_HELPERS_BOOT', 1);

        // A) Honor ?reeid_force_lang=xx -> set ONE canonical cookie (Path=/, SameSite=Lax)
        add_action('template_redirect', function () {
            if (empty($_GET['reeid_force_lang'])) return;
            $lang = strtolower(substr(sanitize_text_field((string)$_GET['reeid_force_lang']), 0, 10));
            if ($lang === '') return;

            $domain = (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) ? COOKIE_DOMAIN : '';
            setcookie('site_lang', $lang, [
                'expires'  => time() + DAY_IN_SECONDS,
                'path'     => '/',
                'domain'   => $domain,   // host-only if ''
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            $_COOKIE['site_lang'] = $lang; // available immediately this request
        }, 1);

        // B) Ensure switcher assets load on the front-end (when injected via menu)
        add_action('wp_enqueue_scripts', function () {
            if (function_exists('reeid_enqueue_switcher_assets')) {
                reeid_enqueue_switcher_assets();
            }
        }, 20);
    }
    /* RT_SWITCHER_DEFAULTS_FILTER: make [reeid_language_switcher] honor Admin settings unless overridden */
    if (! function_exists('rt_switcher_defaults_filter_boot')) {
        function rt_switcher_defaults_filter_boot()
        {
            add_filter('shortcode_atts_reeid_language_switcher', function ($out, $pairs, $atts) {
                if (empty($atts['style'])) {
                    $out['style'] = get_option('reeid_switcher_style', 'dropdown');
                }
                if (empty($atts['theme'])) {
                    $out['theme'] = get_option('reeid_switcher_theme', 'auto');
                }
                return $out;
            }, 10, 3);
        }
        add_action('init', 'rt_switcher_defaults_filter_boot', 9);
    }
    /* RT_SWITCHER_DEFAULTS_FILTER_PATCH: map 'default' -> 'dropdown' and pass theme */
    if (! function_exists('rt_switcher_defaults_filter_patch_boot')) {
        function rt_switcher_defaults_filter_patch_boot()
        {
            add_filter('shortcode_atts_reeid_language_switcher', function ($out, $pairs, $atts) {
                if (empty($atts['style'])) {
                    $style = get_option('reeid_switcher_style', 'dropdown');
                    if ($style === 'default') {
                        $style = 'dropdown';
                    }
                    $out['style'] = $style;
                }
                if (empty($atts['theme'])) {
                    $theme = get_option('reeid_switcher_theme', 'auto');
                    $out['theme'] = $theme;
                }
                return $out;
            }, 9, 3);
        }
        add_action('init', 'rt_switcher_defaults_filter_patch_boot', 8);
    }
    /* RT_SWITCHER_OUTPUT_TWEAK: enforce style/theme on the switcher container */
    if (! function_exists('rt_switcher_output_tweak_boot')) {
        function rt_switcher_output_tweak_boot()
        {
            add_filter('do_shortcode_tag', function ($output, $tag, $attr) {
                if ($tag !== 'reeid_language_switcher' || !is_string($output) || $output === '') return $output;

                // Defaults from Admin unless explicitly set
                $style = isset($attr['style']) && $attr['style'] !== '' ? (string)$attr['style'] : (string)get_option('reeid_switcher_style', 'dropdown');
                if ($style === 'default') $style = 'dropdown';
                $theme = isset($attr['theme']) && $attr['theme'] !== '' ? (string)$attr['theme'] : (string)get_option('reeid_switcher_theme', 'auto');

                // Compute class we expect to exist on the container
                $style_class = ($style === 'dropdown') ? 'reeid-dropdown' : ('reeid-switcher--' . preg_replace('/[^a-z0-9_-]/i', '', $style));
                $theme_class = $theme ? ('reeid-theme-' . preg_replace('/[^a-z0-9_-]/i', '', $theme)) : '';

                // Patch the first container with id="reeid-switcher-container"
                $output = preg_replace_callback(
                    '#<([a-z0-9]+)([^>]*)id=("|\')reeid-switcher-container\\3([^>]*)>#i',
                    function ($m) use ($style_class, $theme, $theme_class) {
                        $tag = $m[1];
                        $before = $m[2];
                        $q = $m[3];
                        $after = $m[4];
                        $full = $before . $after;

                        // Ensure class includes our style/theme classes
                        if (preg_match('/class=("|\')(.*?)\\1/i', $full, $cm)) {
                            $classes = $cm[2];
                            if (stripos($classes, $style_class) === false) $classes .= ' ' . $style_class;
                            if ($theme_class && stripos($classes, 'reeid-theme-') === false) $classes .= ' ' . $theme_class;
                            $full = preg_replace('/class=("|\')(.*?)\\1/i', 'class="' . $classes . '"', $full, 1);
                        } else {
                            $full = rtrim($before) . ' class="' . $style_class . ($theme_class ? ' ' . $theme_class : '') . '" ' . ltrim($after);
                        }

                        // Ensure a data-theme attribute exists (useful for CSS targeting)
                        if ($theme && stripos($full, 'data-theme=') === false) {
                            // esc_attr exists in WP; if not, fall back to raw
                            $t = function_exists('esc_attr') ? esc_attr($theme) : $theme;
                            $full .= ' data-theme="' . $t . '"';
                        }

                        return '<' . $tag . $full . '>';
                    },
                    $output,
                    1
                );

                return $output;
            }, 10, 3);
        }
        add_action('init', 'rt_switcher_output_tweak_boot', 10);
    }
    /* RT_CSS_DIAG: dump & optional kill-switch for CSS (request-scoped) */
    if (! function_exists('rt_css_diag_boot')) {
        function rt_css_diag_boot()
        {
            add_action('wp_enqueue_scripts', function () {
                if (empty($_GET['rt_kill'])) return;
                global $wp_styles;
                if (!$wp_styles) return;
                $pat = (string) $_GET['rt_kill'];
                foreach ((array)$wp_styles->queue as $h) {
                    if (function_exists('fnmatch') ? fnmatch($pat, $h) : preg_match('#^' . str_replace('\*', '.*', preg_quote($pat, '#')) . '$#i', $h)) {
                        wp_dequeue_style($h);
                    }
                }
            }, 100);

            add_action('wp_footer', function () {
                global $wp_styles;
                if ($wp_styles) {
                    echo "\n<!-- RT_CSS_DIAG STYLES: " . esc_html(implode(', ', (array)$wp_styles->queue)) . " -->\n";
                }
            }, 9999);
        }
        add_action('init', 'rt_css_diag_boot', 9);
    }
    /* RT_CSS_BLOCK: permanently dequeue known conflicting plugin CSS */
    if (! function_exists('rt_css_block_boot')) {
        function rt_css_block_boot()
        {
            add_action('wp_enqueue_scripts', function () {
                foreach (
                    array(
                        // ← Fill these with the exact handle(s) you saw in RT_CSS_DIAG
                        'reeid-translate-styles',
                        'reeid-translate-frontend',
                    ) as $h
                ) {
                    if (wp_style_is($h, 'enqueued')) wp_dequeue_style($h);
                }
            }, 100);
        }
        add_action('init', 'rt_css_block_boot', 10);
    }
    /* RT_TRANSLATED_LAYOUT_FIXES: minimal language-scoped content resets */
    if (! function_exists('rt_translated_layout_fixes')) {
        function rt_translated_layout_fixes()
        {
            echo '<style id="rt-translation-layout-fixes">'
                /* Hindi / Devanagari */
                . 'html[lang="hi"] .entry-content, html[lang^="hi-"] .entry-content{'
                . 'word-break:normal; overflow-wrap:normal; letter-spacing:normal;'
                . 'line-height:1.6; font-variant-ligatures:normal;}'
                /* Nepali */
                . 'html[lang="ne"] .entry-content, html[lang^="ne-"] .entry-content{'
                . 'word-break:normal; overflow-wrap:normal; letter-spacing:normal; line-height:1.6;}'
                /* Greek */
                . 'html[lang="el"] .entry-content, html[lang^="el-"] .entry-content{'
                . 'word-break:normal; overflow-wrap:anywhere; letter-spacing:normal; line-height:1.6;}'
                . '</style>' . "\n";
        }
        add_action('wp_head', 'rt_translated_layout_fixes', 70);
    }
    /* RT_FRONTEND_SAFE_MODE: neutralize layout-affecting filters + correct text direction */
    if (! function_exists('rt_frontend_safe_mode_boot')) {
        function rt__cb_file_from_callable($cb)
        {
            try {
                if (is_string($cb) && function_exists($cb)) {
                    $rf = new ReflectionFunction($cb);
                    return $rf->getFileName();
                }
                if (is_array($cb)) {
                    if (is_object($cb[0])) {
                        $rm = new ReflectionMethod($cb[0], $cb[1]);
                        return $rm->getFileName();
                    }
                    if (is_string($cb[0])) {
                        $rm = new ReflectionMethod($cb[0], $cb[1]);
                        return $rm->getFileName();
                    }
                }
            } catch (Throwable $e) {
            }
            return '';
        }
        function rt__remove_plugin_filters($tag)
        {
            global $wp_filter;
            if (empty($wp_filter[$tag]) || !($wp_filter[$tag] instanceof WP_Hook)) return;
            foreach ($wp_filter[$tag]->callbacks as $prio => $cbs) {
                foreach ($cbs as $id => $data) {
                    $cb   = $data['function'];
                    $file = rt__cb_file_from_callable($cb);
                    // remove only callbacks coming from this plugin file tree
                    if ($file && strpos($file, '/plugins/reeid-translate/') !== false) {
                        // keep the shortcode defaults filter if present
                        $keep = is_string($cb) && stripos($cb, 'shortcode_atts_reeid_language_switcher') !== false;
                        if (!$keep) remove_filter($tag, $cb, $prio);
                    }
                }
            }
        }
        function rt_frontend_safe_mode_boot()
        {
            foreach (array('the_content', 'render_block', 'the_excerpt', 'the_title') as $tag) {
                rt__remove_plugin_filters($tag);
            }
        }
        add_action('init', 'rt_frontend_safe_mode_boot', 50);

        // Direction fix: RTL only for ar/he/fa/ur; everyone else LTR
        add_filter('language_attributes', function ($out) {
            if (preg_match('/lang="(ar|he|fa|ur)(-[^"]*)?"/i', $out)) {
                $out = (stripos($out, 'dir=') === false) ? ($out . ' dir="rtl"') : preg_replace('/dir="(ltr|rtl)"/i', 'dir="rtl"', $out);
            } else {
                $out = (stripos($out, 'dir=') === false) ? ($out . ' dir="ltr"') : preg_replace('/dir="(ltr|rtl)"/i', 'dir="ltr"', $out);
            }
            return $out;
        }, 100);
    }
    /* RT_LAYOUT_DIAG: diagnose layout issues in this plugin.
   Use ?rt_layout_diag=styles | filters | both | log   (front-end only)
   Logs to wp-content/debug.log */
    if (! function_exists('rt_layout_diag_boot')) {
        function rtld_cb_file_from_callable($cb)
        {
            try {
                if (is_string($cb) && function_exists($cb)) {
                    $rf = new ReflectionFunction($cb);
                    return [$rf->getFileName(), $rf->getName()];
                }
                if (is_array($cb)) {
                    $cls = is_object($cb[0]) ? get_class($cb[0]) : $cb[0];
                    $rm = new ReflectionMethod($cls, $cb[1]);
                    return [$rm->getFileName(), $cls . '::' . $cb[1]];
                }
            } catch (Throwable $e) {
            }
            return ['', is_string($cb) ? $cb : (is_array($cb) ? 'array_cb' : 'closure')];
        }
        function rtld_remove_plugin_filters($tag)
        {
            global $wp_filter;
            $removed = [];
            if (empty($wp_filter[$tag]) || !($wp_filter[$tag] instanceof WP_Hook)) return $removed;
            foreach ($wp_filter[$tag]->callbacks as $prio => $cbs) {
                foreach ($cbs as $id => $data) {
                    $cb = $data['function'];
                    [$file, $name] = rtld_cb_file_from_callable($cb);
                    if ($file && strpos($file, '/plugins/reeid-translate/') !== false) {
                        remove_filter($tag, $cb, $prio);
                        $removed[] = [$tag, $name, $file, $prio];
                    }
                }
            }
            return $removed;
        }
        function rt_layout_diag_boot()
        {
            if (is_admin()) return;
            $mode = isset($_GET['rt_layout_diag']) ? sanitize_text_field($_GET['rt_layout_diag']) : '';
            if (!$mode) return;
            if ($mode === 'styles' || $mode === 'both') {
                add_action('wp_enqueue_scripts', function () {
                    global $wp_styles, $wp_scripts;
                    if ($wp_styles instanceof WP_Styles) {
                        foreach (array_keys($wp_styles->registered) as $h) {
                            if (stripos($h, 'reeid') !== false) {
                                wp_dequeue_style($h);
                                error_log("RT_LAYOUT_DIAG: dequeued style handle=$h");
                            }
                        }
                    }
                    if ($wp_scripts instanceof WP_Scripts) {
                        foreach (array_keys($wp_scripts->registered) as $h) {
                            if (stripos($h, 'reeid') !== false) {
                                wp_dequeue_script($h);
                                error_log("RT_LAYOUT_DIAG: dequeued script handle=$h");
                            }
                        }
                    }
                }, 100);
            }
            if ($mode === 'filters' || $mode === 'both' || $mode === 'log') {
                add_action('wp', function () use ($mode) {
                    $tags = ['the_content', 'render_block', 'the_excerpt', 'the_title'];
                    foreach ($tags as $tag) {
                        $list = rtld_remove_plugin_filters($tag);
                        foreach ($list as $row) {
                            list($t, $name, $file, $prio) = $row;
                            $msg = "RT_LAYOUT_DIAG: " . (($mode === 'log') ? 'saw' : 'removed') . " tag=$t prio=$prio cb=$name file=$file";
                            error_log($msg);
                        }
                    }
                }, 0);
            }
        }
        add_action('init', 'rt_layout_diag_boot', 1);
    }
    /* RT_LAYOUT_FIX: keep JS, drop broad CSS that breaks theme */
    if (! function_exists('rt_layout_fix_boot')) {
        function rt_layout_fix_boot()
        {
            if (is_admin()) return;
            add_action('wp_enqueue_scripts', function () {
                // Never load Stripe checkout CSS on public pages
                if (wp_style_is('reeid-stripe-checkout-style', 'enqueued')) {
                    wp_dequeue_style('reeid-stripe-checkout-style');
                }
                // Prefer our scoped switcher CSS instead of the plugin’s global stylesheet
                if (! defined('REEID_SWITCHER_GLOBAL_CSS') || ! REEID_SWITCHER_GLOBAL_CSS) {
                    if (wp_style_is('reeid-switcher-style', 'enqueued')) {
                        if (defined('REEID_DEQUEUE_SWITCHER_CSS') && REEID_DEQUEUE_SWITCHER_CSS) {
                            wp_dequeue_style('reeid-translate-styles');
                        }
                    }
                }
            }, 100);
        }
        add_action('init', 'rt_layout_fix_boot', 9);
    }
    /* RT_SCOPED_SWITCHER_CSS: minimal, scoped styling for the switcher only */
    if (! function_exists('rt_print_scoped_switcher_css')) {
        function rt_print_scoped_switcher_css()
        {
            echo '<style id="rt-scoped-switcher-css">'
                . '#reeid-switcher-container{display:inline-block;vertical-align:middle}'
                . '.reeid-dropdown{display:inline-block;position:relative}'
                . '.reeid-dropdown__btn{padding:.25rem .5rem;line-height:1}'
                . '.reeid-flag-img{width:16px;height:auto;margin-right:.25rem;vertical-align:-2px}'
                . '.reeid-dropdown__menu{position:absolute;display:none;z-index:9999}'
                . '.reeid-dropdown.open .reeid-dropdown__menu{display:block}'
                . '</style>' . "\n";
        }
        add_action('wp_head', 'rt_print_scoped_switcher_css', 60);
    }
    /* RT_STRICT_ISOLATE: probe & optionally remove only REEID callbacks (gated by query param) */
    if (! function_exists('rt_strict_isolate_boot')) {
        function rt_strict_isolate_boot()
        {
            if (is_admin()) return;
            $q = isset($_GET['rt_isolate']) ? strtolower((string)$_GET['rt_isolate']) : '';
            if ($q === '') return; // do nothing unless explicitly requested

            // Pick which hooks to act on
            $soft = ['the_content', 'render_block', 'the_excerpt'];
            $hard = array_merge($soft, ['wp_head', 'wp_footer', 'body_class', 'post_class', 'the_posts']);

            $targets = ($q === 'hard') ? $hard : $soft;

            foreach ($targets as $tag) {
                if (empty($GLOBALS['wp_filter'][$tag])) continue;
                $hook = $GLOBALS['wp_filter'][$tag]; // WP_Hook
                if (empty($hook->callbacks) || !is_array($hook->callbacks)) continue;

                foreach ($hook->callbacks as $prio => $group) {
                    foreach ($group as $cb_id => $cb) {
                        $fn = (is_array($cb) ? ($cb["function"] ?? null) : (is_object($cb) ? ($cb->function ?? null) : null));
                        // Build a readable name
                        if (is_string($fn)) {
                            $name = $fn;
                        } elseif (is_array($fn)) {
                            $obj = $fn[0];
                            $name = (is_object($obj) ? get_class($obj) : (string)$obj) . '::' . (string)$fn[1];
                        } else {
                            $name = is_object($fn) ? get_class($fn) : 'closure';
                        }

                        // Only touch REEID callbacks
                        if (stripos($name, 'reeid_') !== false) {
                            // Log every REEID callback we see on these hooks
                            if (function_exists('error_log')) {
                                error_log("RT_STRICT_ISOLATE: seen tag={$tag} prio={$prio} cb={$name}");
                            }
                            // Remove it to test impact
                            remove_filter($tag, $fn, $prio);
                            if (function_exists('error_log')) {
                                error_log("RT_STRICT_ISOLATE: removed tag={$tag} prio={$prio} cb={$name}");
                            }
                        }
                    }
                }
            }
        }
        // Run late so we can see & remove what the plugin added
        add_action('wp', 'rt_strict_isolate_boot', 99);
    }
    /* RT_KILL_ALIGNMENT: disable broad alignment CSS injected into <head> */
    if (! function_exists('rt_kill_alignment_boot')) {
        function rt_kill_alignment_boot()
        {
            if (is_admin()) return;
            // Stop the inline alignment CSS from being printed on the front-end
            remove_action('wp_head', 'reeid_inject_switcher_alignment', 10);
            // If it was attached through enqueue APIs, belt-and-suspenders:
            add_action('wp_enqueue_scripts', function () {
                wp_dequeue_style('reeid-switcher-alignment');
                wp_dequeue_style('reeid-switcher-alignment-css');
            }, 100);
        }
        add_action('init', 'rt_kill_alignment_boot', 11);
    }
    /* RT_FRONT_NOCONTENT: remove REEID content filters on front-end only */
    if (! function_exists('rt_front_nocontent_boot')) {
        function rt_front_nocontent_boot()
        {
            if (is_admin()) return;
            $tags = array('the_content', 'render_block', 'widget_block_content', 'pre_kses', 'wp_kses_allowed_html');

            foreach ($tags as $tag) {
                global $wp_filter;
                if (empty($wp_filter[$tag]) || empty($wp_filter[$tag]->callbacks)) continue;
                foreach ($wp_filter[$tag]->callbacks as $prio => $cbs) {
                    foreach ($cbs as $entry) {
                        $f = $entry['function'];
                        $name = is_string($f) ? $f
                            : (is_array($f) ? (is_object($f[0]) ? get_class($f[0]) . '::' . $f[1] : implode('::', $f))
                                : ($f instanceof Closure ? 'closure' : '?'));
                        if (stripos($name, 'reeid') !== false) {
                            remove_filter($tag, $f, $prio);
                        }
                    }
                }
            }
        }
        // Run late enough to catch plugin's attachments
        // disabled: add_action('wp', 'rt_front_nocontent_boot', 1);
    }
    
    /* RT_CONTENT_GUARD: strip REEID the_content closures just-in-time (front-end only) */
    if (! function_exists('rt_content_guard_boot')) {
        function rt_content_guard_boot()
        {
            if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) return;

            add_filter('the_content', function ($content) {
                global $wp_filter;
                $tag = 'the_content';
                if (empty($wp_filter[$tag]) || empty($wp_filter[$tag]->callbacks)) return $content;

                foreach ($wp_filter[$tag]->callbacks as $prio => $cbs) {
                    foreach ($cbs as $entry) {
                        $cb = $entry['function'];
                        $remove = false;
                        $name = '';

                        if (is_string($cb)) {
                            $name = $cb;
                            $remove = stripos($name, 'reeid') !== false;
                        } elseif (is_array($cb)) {
                            $name = (is_object($cb[0]) ? get_class($cb[0]) . '::' : '') . $cb[1];
                            $remove = stripos($name, 'reeid') !== false;
                        } elseif ($cb instanceof Closure) {
                            try {
                                $rf = new ReflectionFunction($cb);
                                $file = $rf->getFileName();
                                if ($file && strpos($file, '/wp-content/plugins/reeid-translate/') !== false) {
                                    $remove = true;
                                    $name = 'closure@' . basename($file) . ':' . $rf->getStartLine();
                                }
                            } catch (Throwable $e) {
                            }
                        }

                        if ($remove) {
                            remove_filter($tag, $cb, $prio);
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log("RT_CONTENT_GUARD: removed $tag prio=$prio cb=$name");
                            }
                        }
                    }
                }
                return $content;
            }, 0);
        }
        add_action('wp', 'rt_content_guard_boot', 0);
    }
    /* RT_RENDER_BLOCK_GUARD: strip REEID render_block closures JIT (front-end only) */
    if (! function_exists('rt_render_block_guard_boot')) {
        function rt_render_block_guard_boot()
        {
            if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) return;

            add_filter('render_block', function ($block_content, $block) {
                if (defined('REEID_LAYOUT_SAFE') && REEID_LAYOUT_SAFE) return $block_content;

                global $wp_filter;
                $tag = 'render_block';
                if (empty($wp_filter[$tag]) || empty($wp_filter[$tag]->callbacks)) return $block_content;

                foreach ($wp_filter[$tag]->callbacks as $prio => $cbs) {
                    foreach ($cbs as $entry) {
                        $cb = $entry['function'];
                        $remove = false;
                        $name = '';

                        if (is_string($cb)) {
                            $name = $cb;
                            $remove = stripos($name, 'reeid') !== false;
                        } elseif (is_array($cb)) {
                            $name = (is_object($cb[0]) ? get_class($cb[0]) . '::' : '') . $cb[1];
                            $remove = stripos($name, 'reeid') !== false;
                        } elseif ($cb instanceof Closure) {
                            try {
                                $rf = new ReflectionFunction($cb);
                                $file = $rf->getFileName();
                                if ($file && strpos($file, '/wp-content/plugins/reeid-translate/') !== false) {
                                    $remove = true;
                                    $name = 'closure@' . basename($file) . ':' . $rf->getStartLine();
                                }
                            } catch (Throwable $e) {
                            }
                        }

                        if ($remove) {
                            remove_filter($tag, $cb, $prio);
                            if (defined('WP_DEBUG') && WP_DEBUG) error_log("RT_RENDER_BLOCK_GUARD: removed $tag prio=$prio cb=$name");
                        }
                    }
                }
                return $block_content;
            }, 0, 2);
        }
        // disabled: add_action('wp', 'rt_render_block_guard_boot', 0);
    }
    /* RT_CONTENT_CORE_ONLY: on ?rt_content_safe=1, run only core content filters */
    if (! function_exists('rt_content_core_only_boot')) {
        function rt_content_core_only_boot()
        {
            if (is_admin()) return;
            if (empty($_GET['rt_content_safe'])) return;

            add_filter('the_content', function ($c) {
                # Core-ish stack (subset to keep layout sane)
                $filters = [
                    'wptexturize' => 10,
                    'convert_smilies' => 20,
                    'convert_chars' => 30,
                    'wpautop' => 40,
                    'shortcode_unautop' => 50,
                    'do_shortcode' => 60,
                    'wp_make_content_images_responsive' => 70,
                    'wp_filter_content_tags' => 80,
                    'capital_P_dangit' => 11
                ];
                # wipe all then add back our stack
                remove_all_filters('the_content');
                foreach ($filters as $fn => $prio) {
                    if (function_exists($fn)) add_filter('the_content', $fn, $prio);
                }
                return $c;
            }, -9999); // run before anything else on this request
        }
        add_action('wp', 'rt_content_core_only_boot', 0);
    }
    /* RT_LAYOUT_SAFE_MODE: strip only REEID layout fixes; keep switcher intact */
    if (! function_exists('rt_layout_safe_mode_boot')) {
        function rt_layout_safe_mode_boot()
        {

            if (defined('REEID_LAYOUT_SAFE') && ! REEID_LAYOUT_SAFE) return;

            if (is_admin()) return;

            // 1) Belt/suspenders: prevent alignment injector from running
            remove_action('wp_head', 'reeid_inject_switcher_alignment', 10);
            add_action('wp_enqueue_scripts', function () {
                foreach (['reeid-switcher-alignment', 'reeid-switcher-alignment-css'] as $h) {
                    if (wp_style_is($h, 'enqueued')) wp_dequeue_style($h);
                }
            }, 100);

            // 2) Strip ONLY the inline layout fixes style by id from the final HTML
            add_action('template_redirect', function () {
                ob_start(function ($html) {
                    // remove <style id="rt-translation-layout-fixes">…</style>
                    return preg_replace(
                        '#<style[^>]*\bid=("|\')rt-translation-layout-fixes\1[^>]*>.*?</style>#is',
                        '',
                        $html
                    );
                });
            });
        }
        add_action('init', 'rt_layout_safe_mode_boot', 20);
    }
    /* RT_NEUTRALIZE_SCRIPTS: when REEID_LAYOUT_SAFE, remove ONLY this plugin's
 * script_loader_tag + wp_enqueue_scripts closures (keeps Astra/others intact) */
    if (! function_exists('rt_neutralize_scripts_boot')) {
        function rt_neutralize_scripts_boot_DISABLED()
        {
            if (is_admin()) return;
            if (! defined('REEID_LAYOUT_SAFE') || ! REEID_LAYOUT_SAFE) return;

            $tags = array('script_loader_tag', 'wp_enqueue_scripts');
            foreach ($tags as $tag) {
                global $wp_filter;
                if (empty($wp_filter[$tag]) || empty($wp_filter[$tag]->callbacks)) continue;

                foreach ($wp_filter[$tag]->callbacks as $prio => $cbs) {
                    foreach ($cbs as $entry) {
                        $cb = $entry['function'];
                        $kill = false;

                        if ($cb instanceof Closure) {
                            try {
                                $rf = new ReflectionFunction($cb);
                                $file = $rf->getFileName();
                                // only remove closures coming from *this* plugin
                                if ($file && strpos($file, '/wp-content/plugins/reeid-translate/') !== false) $kill = true;
                            } catch (Throwable $e) {
                            }
                        } elseif (is_array($cb)) {
                            // static methods from this plugin namespace/class if any
                            $cls = is_object($cb[0]) ? get_class($cb[0]) : (is_string($cb[0]) ? $cb[0] : '');
                            if (stripos($cls, 'reeid') !== false) $kill = true;
                        } elseif (is_string($cb)) {
                            if (stripos($cb, 'reeid_') === 0) $kill = true;
                        }

                        if ($kill) remove_filter($tag, $cb, $prio);
                    }
                }
            }
        }
    }
    /* RT_KILL_ANON_STYLES: remove anonymous <style> blocks that mention our switcher/menu
 * classes (front-end only; keeps other inline styles intact) */
    if (! function_exists('rt_kill_anon_styles_boot')) {
        function rt_kill_anon_styles_boot_DISABLED()
        {
            if (is_admin()) return;
            if (! defined('REEID_LAYOUT_SAFE') || ! REEID_LAYOUT_SAFE) return;

            add_action('template_redirect', function () {
                ob_start(function ($html) {
                    // nuke any <style> (no id) that contains these selectors
                    $needle = '(?:menu-item-reeid-switcher|reeid-lang-switcher|reeid-switcher-cart|reeid-switcher-checkout|reeid-dropdown__)';
                    return preg_replace('#<style(?![^>]*\bid=)[^>]*>[^<]*' . $needle . '[\s\S]*?</style>#i', '', $html);
                });
            }, 1);
        }
    }

    // require_once __DIR__ . "/includes/rt-router-lang.php";

     require_once __DIR__ . "/includes/rt-native-slugs.php";

    // RT: Gutenberg guard (migrated from mu-plugins)
    require_once __DIR__ . "/includes/rt-gb-guard.php";

    // RT: Gutenberg safety pack (self-contained)
    require_once __DIR__ . "/includes/rt-gb-safety-pack.php";

    // RT_BOOTSTRAP_AUTOLOAD: load formerly MU helpers from includes/mu-migrated
    add_action('plugins_loaded', function () {
        $dir = __DIR__ . "/includes/bootstrap";
        if (is_dir($dir)) {
            foreach (glob($dir . "/*.php") as $file) {
                if (is_file($file)) {
                    require_once $file;
                }
            }
        }
    }, 0);

    // RT: strip bracketed ASCII duplicates from translations
    require_once __DIR__ . "/includes/rt-clean-dup-bracketed.php";

    require_once __DIR__ . "/includes/rt-strip-ascii-paragraphs.php";

    // RT TEMP: trace who injects ASCII eplus-wrapper
    require_once __DIR__ . "/includes/rt-trace-dup-source.php";

    
    // S26: Disable any inline→post_content sync so translations never overwrite the source.
    add_action('plugins_loaded', function () {
        // if these handlers exist and were hooked, unhook them.
        if (function_exists('reeid_inline_sync_handle_meta')) {
            remove_action('added_post_meta',   'reeid_inline_sync_handle_meta', 10);
            remove_action('updated_post_meta', 'reeid_inline_sync_handle_meta', 10);
        }
        if (function_exists('reeid_inline_sync_save_post_backstop')) {
            remove_action('save_post_product', 'reeid_inline_sync_save_post_backstop', 20);
        }
    });



    add_action('pre_get_posts', function ($q) {
        // Only run for main query, products, and language-prefixed paths
        if (!is_admin() && $q->is_main_query() && isset($q->query_vars['post_type']) && $q->query_vars['post_type'] === 'product') {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
            if (preg_match('#^/([a-z]{2}(?:-[a-z0-9]{2})?)/product/([^/]+)/?$#u', $path, $mm)) {
                $lang = strtolower($mm[1]);
                $req_slug = rawurldecode($mm[2]);
                global $wpdb;
                // Find product by matching inline meta slug
                $products = $wpdb->get_results("SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type='product' AND post_status IN ('publish','private','draft')");
                foreach ($products as $prod) {
                    $meta = get_post_meta($prod->ID, '_reeid_wc_tr_' . $lang, true);
                    if (is_array($meta) && !empty($meta['slug']) && rawurldecode($meta['slug']) === $req_slug) {
                        // "Rewrite" the query to fetch this product
                        $q->set('name', $prod->post_name);
                        $q->set('pagename', false);
                        break;
                    }
                }
            }
        }
    }, 1); // Priority 1, must run before WooCommerce

    /**
     * Safe late-init registration for the Validate-Key JS
     * Runs after pluggables (no fatal under CLI or early load)
     */
    add_action('init', function () {
        add_action('admin_enqueue_scripts', 'reeid_admin_validate_key_script', 99);
    });

/* ============================================================
    SECTION 54 : ACTIVE AJAX HANDLER — VALIDATE OPENAI API KEY
 * - Safe: wrapped in function_exists guard
 * - Nonce: expects 'reeid_translate_nonce_action' via _wpnonce/_ajax_nonce
 * - Endpoint: uses chat completions to support project-scoped keys
 * ============================================================ */
    if (! function_exists('reeid_validate_openai_key')) {
        add_action('wp_ajax_reeid_validate_openai_key', 'reeid_validate_openai_key');
        function reeid_validate_openai_key()
        {
            // verify nonce (accept _wpnonce)
            /* BEGIN REEID tolerant nonce check */
$nonce = $_REQUEST['nonce'] ?? $_POST['nonce'] ?? $_GET['nonce'] ?? '';
$ok = false;
if (! empty($nonce)) {
    if ( wp_verify_nonce( $nonce, 'reeid_translate_nonce' ) ) {
        $ok = true;
    } elseif ( wp_verify_nonce( $nonce, 'reeid_translate_nonce_action' ) ) {
        $ok = true;
    }
}
if ( ! $ok ) {
    wp_send_json_error( array( 'error' => 'bad_nonce', 'msg' => 'Invalid/missing nonce. Please reload editor.' ) );
}
/* END REEID tolerant nonce check */

            if (! current_user_can('manage_options')) {
                wp_send_json_error(array('message' => 'Permission denied'), 403);
            }

            // retrieve key safely
            $key_raw = isset($_POST['key']) ? wp_unslash($_POST['key']) : '';
            $key     = sanitize_text_field($key_raw);

            if (empty($key)) {
                wp_send_json_error(array('message' => 'API key is empty'), 400);
            }

            // call OpenAI (chat completions) — small ping, low cost
            $args = array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $key,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode(array(
                    'model'     => 'gpt-4o-mini',
                    'messages'  => array(array('role' => 'system', 'content' => 'ping')),
                    'max_tokens' => 1,
                )),
                'timeout' => 12,
            );

            $response = wp_remote_post('https://api.openai.com/v1/chat/completions', $args);

            if (is_wp_error($response)) {
                error_log('REEID: wp_remote_post error: ' . $response->get_error_message());
                update_option('reeid_openai_status', 'invalid');
                wp_send_json_error(array('message' => 'Connection failed: ' . $response->get_error_message()));
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            // debug short snippet
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('REEID: openai validate code=' . $code . ' body=' . substr($body, 0, 1000));
            }

            // treat 200 and 429 as valid (429 = rate limit but key valid)
            if (in_array($code, array(200, 429), true)) {
                update_option('reeid_openai_status', 'valid');
                wp_send_json_success(array('message' => '✅ Valid API Key'));
            }

            update_option('reeid_openai_status', 'invalid');
            wp_send_json_error(array('message' => '❌ Invalid API Key (' . $code . ')'));
        }
    }


    /**
     * Safe wrapper: sanitize & harden translated SEO title before writing to other plugins
     * Usage: reeid_safe_write_title_all_plugins($post_id, $title_string);
     */
    if (! function_exists('reeid_safe_write_title_all_plugins')) {
        function reeid_safe_write_title_all_plugins($post_id, $title)
        {
            // quick validation
            $post_id = (int) $post_id;
            if ($post_id <= 0) return;

            // normalize to string
            $title = is_scalar($title) ? (string) $title : '';

            // if nothing to write — quit
            $title_trim = trim($title);
            if ($title_trim === '') return;

            // If plugin provides invalid-lang marker hardener, use it (may clear $title_trim)
            if (function_exists('reeid_harden_invalid_lang_pair')) {
                // the helper may modify the string or clear it; pass by reference if that is its contract
                // some versions return or modify — handle both possibilities
                $maybe = reeid_harden_invalid_lang_pair($title_trim);
                if (is_string($maybe)) {
                    $title_trim = $maybe;
                }
            }

            // After hardening, bail if clearly invalid
            if ($title_trim === '' || stripos($title_trim, 'INVALID LANGUAGE PAIR') !== false) return;

            // Decode any escaped unicode sequences if helper exists
            if (function_exists('reeid_decode_unicode_escapes')) {
                $title_trim = reeid_decode_unicode_escapes($title_trim);
            }

            // Final sanitize for safe storage
            if (function_exists('sanitize_text_field')) {
                $title_trim = sanitize_text_field($title_trim);
            } else {
                $title_trim = trim(preg_replace('/\s+/', ' ', strip_tags($title_trim)));
            }

            if ($title_trim === '') return;

            // Finally call the provider that writes to all SEO plugins
            if (function_exists('reeid_write_title_all_plugins')) {
                reeid_write_title_all_plugins($post_id, $title_trim);
            }
        }
    }

    // Prefer sync-only jobs stub; never load the background worker together.
    if (file_exists(__DIR__ . '/includes/jobs-sync.php')) {
        require_once __DIR__ . '/includes/jobs-sync.php';
    } elseif (file_exists(__DIR__ . '/includes/jobs.php') && ! defined('REEID_FORCE_SYNC')) {
        // Fallback only if you ever want background back (leave commented for now)
        // require_once __DIR__ . '/includes/jobs.php';
    }
}

/**
 * Non-AJAX fallback: delete ALL translations for a product, then redirect back.
 * URL endpoint: wp-admin/admin-post.php?action=reeid_wc_delete_all_translations
 */
add_action('admin_post_reeid_wc_delete_all_translations', function () {

    // Must be logged in
    if (! is_user_logged_in()) {
        wp_die(esc_html__('You must be logged in.', 'reeid-translate'), 403);
    }

    // Read product id
    $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
    if (! $product_id) {
        wp_die(esc_html__('Missing product.', 'reeid-translate'), 400);
    }

    // Check capability for this specific post
    if (! current_user_can('edit_post', $product_id)) {
        wp_die(esc_html__('Insufficient permissions.', 'reeid-translate'), 403);
    }

    // Nonce (uses the standard _wpnonce field)
    $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
    if (! $nonce || ! wp_verify_nonce($nonce, 'reeid_wc_delete_all')) {
        wp_die(esc_html__('Invalid request (nonce).', 'reeid-translate'), 403);
    }

    // Validate WC product if available
    if (function_exists('wc_get_product') && ! wc_get_product($product_id)) {
        wp_die(esc_html__('Invalid product.', 'reeid-translate'), 404);
    }

    // Remove all translation packets (new + legacy)
    $meta    = get_post_meta($product_id);
    $removed = array();
    foreach (array_keys($meta) as $k) {
        if (preg_match('/^_reeid_wc_tr_([a-zA-Z-]+)$/', $k, $m)) {
            delete_post_meta($product_id, $k);
            $removed[] = $m[1];
        }
        if (preg_match('/^_reeid_wc_inline_([a-zA-Z-]+)$/', $k, $m)) {
            delete_post_meta($product_id, $k);
            $removed[] = $m[1];
        }
    }
    $removed = array_values(array_unique($removed));

    // Clear caches
    if (function_exists('wc_delete_product_transients')) {
        wc_delete_product_transients($product_id);
    }
    clean_post_cache($product_id);

    do_action('reeid_wc_translations_deleted_all', $product_id, $removed);

    // Redirect directly to the product editor (avoids referer issues)
    $back = admin_url('post.php?post=' . $product_id . '&action=edit');
    $back = add_query_arg(array(
        'reeid_del_all' => '1',
        'deleted_langs' => implode(',', $removed),
    ), $back);

    wp_redirect($back);
    exit;

    // Add a query arg so you can show an admin notice if desired
    $back = add_query_arg(array(
        'reeid_del_all' => '1',
        'deleted_langs' => implode(',', $removed),
    ), $back);

    wp_safe_redirect($back);
    exit;
});


/**
 * Swap long product description with REEID packet content on the frontend
 * ONLY for non-source languages. Source language (e.g., en) stays untouched.
 */
add_filter('the_content', function ($content) {
    // Frontend only
    if ( is_admin() || wp_doing_ajax() || wp_is_json_request() ) {
        return $content;
    }

    // Must be a single product
    if ( ! is_singular('product') ) {
        return $content;
    }

    global $post;
    if ( ! $post || $post->post_type !== 'product' ) {
        return $content;
    }

    // Resolve source/default language
    $default = function_exists('reeid_s269_default_lang')
        ? strtolower( (string) reeid_s269_default_lang() )
        : strtolower( (string) get_option('reeid_translation_source_lang', 'en') );

    // -------- URL/Cookie/Prefix + tolerant mapping (e.g., "zh" -> "zh-CN") --------
    $lang = '';

    // 1) Explicit param wins
    if ( isset($_GET['reeid_force_lang']) ) {
        $lang = sanitize_text_field( wp_unslash( $_GET['reeid_force_lang'] ) );
    }

    // 2) Cookie fallback
    if ( $lang === '' && isset($_COOKIE['site_lang']) ) {
        $lang = sanitize_text_field( wp_unslash( $_COOKIE['site_lang'] ) );
    }

    // 3) URL prefix (/xx/ or /xx-yy/) fallback with tolerant mapping
    if ( $lang === '' ) {
        $uri   = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $seg   = '';
        if ( $uri !== '' ) {
            $path  = trim( parse_url( $uri, PHP_URL_PATH ), '/' );
            $parts = $path !== '' ? explode( '/', $path ) : array();
            $seg   = isset($parts[0]) ? strtolower( str_replace('_','-',$parts[0]) ) : '';
        }

        // Supported set (normalized)
        $supported = function_exists('reeid_s269_supported_langs') ? array_keys( (array) reeid_s269_supported_langs() ) : array();
        $supported = array_map( function($c){ return strtolower( str_replace('_','-',$c) ); }, $supported );

        if ( $seg !== '' ) {
            if ( empty($supported) ) {
                if ( preg_match( '/^[a-z]{2}(-[a-z]{2})?$/i', $seg ) ) {
                    $lang = $seg;
                }
            } else {
                // Exact match?
                if ( in_array( $seg, $supported, true ) ) {
                    $lang = $seg;
                } else {
                    // Prefix match: e.g., "zh" -> first supported starting with "zh-" (zh-cn, zh-hk, ...)
                    foreach ( $supported as $code ) {
                        if ( strpos( $code, $seg . '-' ) === 0 || $seg === substr( $code, 0, 2 ) ) {
                            $lang = $code;
                            break;
                        }
                    }
                }
            }
        }
    }

    $lang = strtolower( (string) $lang );

    // If no language or it's the source language, do NOT override
    if ( $lang === '' || $lang === $default ) {
        return $content;
    }

    // Load REEID packet for this language and return translated long description if present
    $packet = get_post_meta( (int) $post->ID, "_reeid_wc_tr_{$lang}", true );
    if ( is_array( $packet ) && ! empty( $packet['content'] ) ) {
        return (string) $packet['content'];
    }

    // Fallback to original content
    return $content;
}, 5);



/**
 * Force WooCommerce long description to use REEID packet on the frontend
 * (non-source languages only). Works even if the theme uses product getters.
 */
add_filter('woocommerce_product_get_description', function( $desc, $product ) {
    if ( is_admin() || wp_doing_ajax() || wp_is_json_request() ) {
        return $desc;
    }
    if ( ! $product instanceof WC_Product ) {
        return $desc;
    }

    // Resolve default/source language
    $default = function_exists('reeid_s269_default_lang')
        ? strtolower( (string) reeid_s269_default_lang() )
        : strtolower( (string) get_option('reeid_translation_source_lang', 'en') );

    // Resolve current language: param > cookie > URL prefix (tolerant: "zh" -> "zh-CN")
    $lang = '';
    if ( isset($_GET['reeid_force_lang']) ) {
        $lang = sanitize_text_field( wp_unslash( $_GET['reeid_force_lang'] ) );
    }
    if ( $lang === '' && isset($_COOKIE['site_lang']) ) {
        $lang = sanitize_text_field( wp_unslash( $_COOKIE['site_lang'] ) );
    }
    if ( $lang === '' && isset($_SERVER['REQUEST_URI']) ) {
        $path  = trim( parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
        $seg   = $path !== '' ? strtolower( str_replace('_','-', explode('/', $path)[0] ) ) : '';
        if ( $seg !== '' ) {
            $supported = function_exists('reeid_s269_supported_langs') ? array_keys( (array) reeid_s269_supported_langs() ) : array();
            $supported = array_map( function($c){ return strtolower( str_replace('_','-',$c) ); }, $supported );
            if ( empty($supported) ) {
                if ( preg_match('/^[a-z]{2}(-[a-z]{2})?$/i', $seg) ) { $lang = $seg; }
            } else {
                if ( in_array($seg, $supported, true) ) {
                    $lang = $seg;
                } else {
                    foreach ($supported as $code) {
                        if ( strpos($code, $seg.'-') === 0 || $seg === substr($code, 0, 2) ) { $lang = $code; break; }
                    }
                }
            }
        }
    }
    $lang = strtolower( (string) $lang );

    // Do not override for source language or when no lang resolved
    if ( $lang === '' || $lang === $default ) {
        return $desc;
    }

    // Use REEID packet if present
    $packet = get_post_meta( (int) $product->get_id(), "_reeid_wc_tr_{$lang}", true );
    if ( is_array($packet) && ! empty($packet['content']) ) {
        return (string) $packet['content'];
    }

    return $desc;
}, 9, 2);
/**
 * REEID: Elementor long description swap (frontend, single product, non-source langs)
 * Moves the working MU logic into the plugin so Elementor's "Post Content" widget
 * renders the translated long description from our REEID packet.
 */
if ( ! function_exists( 'reeid_resolve_lang_from_request' ) ) {
    function reeid_resolve_lang_from_request(): string {
        // 1) URL param
        if ( isset($_GET['reeid_force_lang']) ) {
            $l = sanitize_text_field( wp_unslash( $_GET['reeid_force_lang'] ) );
            if ( $l !== '' ) return strtolower( str_replace('_','-',$l) );
        }
        // 2) Cookie
        if ( isset($_COOKIE['site_lang']) ) {
            $l = sanitize_text_field( wp_unslash( $_COOKIE['site_lang'] ) );
            if ( $l !== '' ) return strtolower( str_replace('_','-',$l) );
        }
        // 3) URL prefix (/xx/ or /xx-yy/)
        $uri   = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $seg   = '';
        if ( $uri !== '' ) {
            $path  = trim( parse_url( $uri, PHP_URL_PATH ), '/' );
            $parts = $path !== '' ? explode( '/', $path ) : array();
            $seg   = isset($parts[0]) ? strtolower( str_replace('_','-', $parts[0] ) ) : '';
        }
        if ( $seg === '' ) return '';

        // Map against supported languages; allow tolerant prefix match (e.g., "zh" -> "zh-CN")
        $supported = function_exists('reeid_s269_supported_langs') ? array_keys( (array) reeid_s269_supported_langs() ) : array();
        $supported = array_map( function($c){ return strtolower( str_replace('_','-',$c) ); }, $supported );

        if ( empty($supported) ) {
            return preg_match('/^[a-z]{2}(-[a-z]{2})?$/i', $seg) ? $seg : '';
        }
        if ( in_array( $seg, $supported, true ) ) {
            return $seg;
        }
        foreach ( $supported as $code ) {
            if ( strpos( $code, $seg.'-' ) === 0 || $seg === substr( $code, 0, 2 ) ) {
                return $code;
            }
        }
        return '';
    }
}

// Elementor: translate ONLY the long-description widget content on single product pages,
// preserve attributes markup/shortcodes if they are inside that same widget.
add_action('elementor/init', function () {

    add_filter('elementor/widget/render_content', function( $content, $widget ) {
        if ( is_admin() || wp_doing_ajax() || wp_is_json_request() ) {
            return $content;
        }

        // Single product only
        $is_product = function_exists('is_product') ? is_product() : is_singular('product');
        if ( ! $is_product ) {
            return $content;
        }
        global $post;
        if ( ! $post || $post->post_type !== 'product' ) {
            return $content;
        }

        // Detect likely long-description widgets
        $name = method_exists( $widget, 'get_name' ) ? strtolower( (string) $widget->get_name() ) : '';
        $is_known   = in_array( $name, array('post-content','woocommerce-product-content','theme-post-content'), true );
        $is_general = ( strpos($name,'content') !== false || strpos($name,'description') !== false );

        if ( ! $is_known && ! $is_general ) {
            return $content; // not a description-like widget
        }

        // Decide if this widget's content looks like the long description:
        // - contains Woo description panel classes, or
        // - contains a good chunk of the base post_content text
        $looks_like_longdesc = false;
        if ( preg_match('/woocommerce-Tabs-panel--description|product-description|woocommerce-product-details__short-description/i', $content) ) {
            $looks_like_longdesc = true;
        } else {
            $base_raw   = (string) $post->post_content;
            $base_plain = trim( wp_strip_all_tags( $base_raw ) );
            $cont_plain = trim( wp_strip_all_tags( $content ) );
            if ( $base_plain !== '' ) {
                // If 20+ chars of base text appear in this widget, assume it's the long description
                $needle = mb_substr( $base_plain, 0, 120 ); // take a chunk
                if ( $needle !== '' && mb_stripos( $cont_plain, $needle ) !== false ) {
                    $looks_like_longdesc = true;
                }
            }
        }

        if ( ! $looks_like_longdesc ) {
            return $content; // do not touch unrelated content widgets
        }

        // Default/source language
        $default = function_exists('reeid_s269_default_lang')
            ? strtolower( (string) reeid_s269_default_lang() )
            : strtolower( (string) get_option('reeid_translation_source_lang', 'en') );

        // Resolve lang (param > cookie > URL prefix)
        $lang = function_exists('reeid_resolve_lang_from_request') ? reeid_resolve_lang_from_request() : '';
        if ( $lang === '' || $lang === $default ) {
            return $content; // do not override source language
        }

        // Look up translated long description
        $packet = get_post_meta( (int) $post->ID, "_reeid_wc_tr_{$lang}", true );
        if ( ! is_array($packet) || empty($packet['content']) ) {
            return $content;
        }

        $translated = (string) $packet['content'];

        // --- Preserve Woo attributes markup / shortcodes found in this widget (if any) ---
        $preserve = '';

        // 1) Attributes table HTML
        if ( preg_match( '/<table[^>]*class="[^"]*woocommerce-product-attributes[^"]*"[^>]*>.*?<\/table>/is', $content, $m ) ) {
            $preserve .= "\n" . $m[0];
        }

        // 2) Attributes shortcode variants
        if ( preg_match_all( '/\[(product_)?attributes[^\]]*\]/i', $content, $ms ) ) {
            $preserve .= "\n" . implode( "\n", array_unique( $ms[0] ) );
        }

        if ( $preserve !== '' ) {
            $translated .= "\n" . $preserve;
        }

        return $translated;

    }, 9999, 2);
});



// Frontend swap for long description on single product pages (no attributes here)
if (false) { // disabled: content-swap on template_redirect (we now use the Woo tabs callback instead)
    add_action('template_redirect', function () {
        if ( is_admin() || wp_doing_ajax() || wp_is_json_request() ) { return; }
        $is_product = function_exists('is_product') ? is_product() : is_singular('product');
        if ( ! $is_product ) { return; }
        global $post; if ( ! $post || $post->post_type !== 'product' ) { return; }
    
        $default = function_exists('reeid_s269_default_lang')
            ? strtolower( (string) reeid_s269_default_lang() )
            : strtolower( (string) get_option('reeid_translation_source_lang', 'en') );
    
        $lang = function_exists('reeid_resolve_lang_from_request') ? reeid_resolve_lang_from_request() : '';
        if ( $lang === '' || $lang === $default ) { return; }
    
        $packet = get_post_meta( (int) $post->ID, "_reeid_wc_tr_{$lang}", true );
        if ( is_array($packet) && ! empty($packet['content']) ) {
            $post->post_content = (string) $packet['content'];
        }
    }, 1);
    } 
    

// Track if Woo's "Additional information" (attributes) section was rendered
$GLOBALS['reeid_attrs_rendered'] = false;
add_action('woocommerce_product_additional_information', function( $product ) {
    $GLOBALS['reeid_attrs_rendered'] = true;
}, 1); // runs when Woo prints the official attributes table



/**
 * Resolve current language (param > cookie > URL prefix).
 * Keep if you already have the same helper.
 */
if ( ! function_exists('reeid_resolve_lang_from_request') ) {
    function reeid_resolve_lang_from_request(): string {
        if ( isset($_GET['reeid_force_lang']) ) {
            $l = sanitize_text_field( wp_unslash($_GET['reeid_force_lang']) );
            if ($l !== '') return strtolower(str_replace('_','-',$l));
        }
        if ( isset($_COOKIE['site_lang']) ) {
            $l = sanitize_text_field( wp_unslash($_COOKIE['site_lang']) );
            if ($l !== '') return strtolower(str_replace('_','-',$l));
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $seg = '';
        if ($uri !== '') {
            $path  = trim(parse_url($uri, PHP_URL_PATH), '/');
            $parts = $path !== '' ? explode('/', $path) : [];
            $seg   = isset($parts[0]) ? strtolower(str_replace('_','-',$parts[0])) : '';
        }
        if ($seg === '') return '';
        $supported = function_exists('reeid_s269_supported_langs') ? array_keys((array)reeid_s269_supported_langs()) : [];
        $supported = array_map(fn($c)=>strtolower(str_replace('_','-',$c)), $supported);
        if (empty($supported)) {
            return preg_match('/^[a-z]{2}(-[a-z]{2})?$/i', $seg) ? $seg : '';
        }
        if (in_array($seg, $supported, true)) return $seg;
        foreach ($supported as $code) {
            if (strpos($code, $seg.'-') === 0 || $seg === substr($code,0,2)) return $code;
        }
        return '';
    }
}

/**
 * Hard-set Woo tabs so we always have:
 *  - #tab-description               (translated long description)
 *  - #tab-additional_information    (live attributes table)
 *
 * We replace the callbacks on the default tabs instead of injecting HTML into content.
 */
add_filter('woocommerce_product_tabs', function ($tabs) {
    if (is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax()) || (function_exists('wp_is_json_request') && wp_is_json_request())) {
        return $tabs;
    }

    // Ensure the two keys exist so Woo will create panels with the exact IDs.
    // Woo uses the array keys for IDs: "description" => #tab-description, etc.
    $tabs['description'] = $tabs['description'] ?? [
        'title'    => __('Description', 'woocommerce'),
        'priority' => 10,
        'callback' => '__return_null', // will be replaced below
    ];
    $tabs['additional_information'] = $tabs['additional_information'] ?? [
        'title'    => __('Additional information', 'woocommerce'),
        'priority' => 50,
        'callback' => '__return_null', // will be replaced below
    ];

    // Source/default language
    $default = function_exists('reeid_s269_default_lang')
        ? strtolower((string) reeid_s269_default_lang())
        : strtolower((string) get_option('reeid_translation_source_lang', 'en'));

    // Resolve current language
    $lang = function_exists('reeid_resolve_lang_from_request') ? reeid_resolve_lang_from_request() : '';
    $lang = strtolower((string) $lang);

    /**
     * DESCRIPTION tab callback: print translated long description.
     *
     * IMPORTANT: we intentionally avoid apply_filters('the_content', ...)
     * because that re-runs plugin the_content closures which may move/duplicate
     * the attributes table into the description panel. Instead we:
     *  - pull the translated content packet (if present),
     *  - strip any attributes table / debug wrapper from that content,
     *  - then run a conservative set of core formatters (do_blocks, wptexturize, wpautop, shortcodes, etc).
     */
    $tabs['description']['callback'] = function () use ($default, $lang) {
        global $post;
        if (!$post || $post->post_type !== 'product') {
            return;
        }

        $content = (string) $post->post_content;

        // If non-source language, try REEID packet
        if ($lang !== '' && $lang !== $default) {
            $packet = get_post_meta((int) $post->ID, "_reeid_wc_tr_{$lang}", true);
            if (is_array($packet) && !empty($packet['content'])) {
                $content = (string) $packet['content'];
            }
        }

        // Remove any attributes HTML that may be present to avoid leakage/duplicates:
        // - debug wrapper DIV (reeid-debug-attrs-wrapper)
        // - any <table class="...shop_attributes..." or "woocommerce-product-attributes"
        $content = preg_replace('#<div[^>]+id=(["\'])reeid-debug-attrs-wrapper\1[^>]*>[\s\S]*?</div>#i', '', $content);
        $content = preg_replace('#<table[^>]*\b(class=["\'][^"\']*(?:woocommerce-product-attributes|shop_attributes)[^"\']*["\'])[^>]*>[\s\S]*?</table>#i', '', $content);

        // Now safely format the content using core formatters (avoid apply_filters('the_content')).
        if (function_exists('do_blocks')) {
            $content = do_blocks($content);
        }

        if (function_exists('wptexturize')) {
            $content = wptexturize($content);
        }

        if (function_exists('wpautop')) {
            $content = wpautop($content);
        }

        if (function_exists('shortcode_unautop')) {
            $content = shortcode_unautop($content);
        }

        if (function_exists('do_shortcode')) {
            $content = do_shortcode($content);
        }

        if (function_exists('prepend_attachment')) {
            $content = prepend_attachment($content);
        }

        if (function_exists('wp_replace_insecure_home_url')) {
            $content = wp_replace_insecure_home_url($content);
        }

        // Final echo: this is the description panel content.
        // REEID: ensure attributes table never leaks into Description
        if (function_exists("reeid_strip_attrs_from_content")) {
            $content = reeid_strip_attrs_from_content($content);
        } else {
            // Fallback: remove Woo attributes table by class name
            $content = preg_replace('#<table[^>]*class=("|\'\')woocommerce-product-attributes\1[\s\S]*?</table>#i', '', $content);
        }

        echo $content;
    };

    /**
     * ADDITIONAL INFORMATION tab: only if product actually has attributes.
     * We rely on wc_display_product_attributes() to output the live attributes table.
     */
    $tabs['additional_information']['callback'] = function () {
        global $post;
        if (!$post || $post->post_type !== 'product') {
            return;
        }

        if (!function_exists('wc_get_product')) {
            return;
        }

        $product = wc_get_product((int) $post->ID);
        if (!$product) {
            return;
        }

        // If no attributes, Woo normally removes the tab; we mimic that.
        if (method_exists($product, 'has_attributes') && ! $product->has_attributes()) {
            return;
        }

        if (function_exists('wc_display_product_attributes')) {
            wc_display_product_attributes($product);
        }
    };

    // If no attributes, drop the tab entirely so themes don’t render an empty panel.
    if (function_exists('wc_get_product')) {
        $product = wc_get_product((int) get_the_ID());
        if ($product && method_exists($product, 'has_attributes') && ! $product->has_attributes()) {
            unset($tabs['additional_information']);
        }
    }

    return $tabs;
}, 20);


// PROBE: prove we’re wrapping final HTML on product pages.
add_action('template_redirect', function () {
    if (is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax()) || (function_exists('wp_is_json_request') && wp_is_json_request())) return;

    // IMPORTANT: use is_singular('product') here; is_product() can be false too early on some stacks.
    if (!is_singular('product')) return;

    if (function_exists('error_log')) error_log('[REEID-PROBE] starting output buffer on single-product');

    ob_start(function ($html) {
        // Log what we’re seeing
        $has_table = preg_match('#<table[^>]+(woocommerce-product-attributes|shop_attributes)#i', $html) ? 'Y' : 'N';
        $has_panel = preg_match('#id=(["\'])tab-additional_information\1|woocommerce-Tabs-panel--additional_information#i', $html) ? 'Y' : 'N';
        if (function_exists('error_log')) error_log("[REEID-PROBE] seen: table={$has_table} panel={$has_panel} size=" . strlen($html));

        return $html; // no changes, just proof
    });
}, 0);


/* DISABLED: REEID-WC-HREFLANG v1 block removed to avoid duplicates */
// after Rank Math canonical but before very-late stuff
/* ============================================================================
 * SECTION L: Legacy wiring (frontend) — loads MU equivalents from /legacy
 * - Keeps Woo SEO bridge intact; hreflang-force prints only if bridge didn’t.
 * - Avoids duplicate title filters by preferring title-force only.
 * ==========================================================================*/
add_action('plugins_loaded', function () {
    if (is_admin()) return;

    $legacy = __DIR__ . '/legacy/';

    // 1) UTF-8 product router (query var fixer) — must load first
    if (file_exists($legacy.'reeid-utf8-router.php')) {
        include_once $legacy.'reeid-utf8-router.php';
    }

    // 2) Product title localization (choose ONE)
    if (!function_exists('reeid_product_title_for_lang') && file_exists($legacy.'reeid-title-force.php')) {
        include_once $legacy.'reeid-title-force.php';
    }
    // DO NOT load title-local when title-force is present to avoid function collisions.
    // if (!function_exists('reeid_product_title_for_lang') && file_exists($legacy.'reeid-title-local.php')) {
    //     include_once $legacy.'reeid-title-local.php';
    // }

    // 3) Hreflang late injector — prints only if bridge didn’t (self-guarded)
    if (file_exists($legacy.'reeid-hreflang-force.php')) {
        include_once $legacy.'reeid-hreflang-force.php';
    }
}, 1);
/* ============================================================================
 * SECTION S.HREFLANG — Canonical printer shim for Woo products only
 * - Leaves pages/posts to seo-sync
 * - Does NOT modify the bridge file; only swaps the printer on product requests
 * - Languages source = reeid_get_enabled_languages() (fallback: ['en'])
 * ==========================================================================*/
if (!function_exists('reeid_hreflang_print_canonical')) {
    function reeid_hreflang_print_canonical() {
        if (!is_singular('product')) return;

        // 1) Language set = enabled languages (no hardcoding)
        $langs = function_exists('reeid_get_enabled_languages')
            ? (array) reeid_get_enabled_languages()
            : array('en');

        if (empty($langs)) $langs = array('en');

        // 2) Ensure default is included and first
        $default = get_option('reeid_default_lang', 'en');
        if (!in_array($default, $langs, true)) {
            array_unshift($langs, $default);
        }
        // Dedup while preserving order
        $seen = array();
        $langs = array_values(array_filter($langs, function($c) use (&$seen){
            $k = strtolower((string)$c);
            if (isset($seen[$k])) return false;
            return $seen[$k] = true;
        }));

        // 3) Render via bridge renderer (uses localized slugs)
        if (function_exists('reeid_hreflang_render')) {
            $snippet = reeid_hreflang_render(get_queried_object_id(), $langs, $default);
            if ($snippet) {
                // Optional marker for diagnostics
                echo "<!-- REEID-HREFLANG-SHIM -->\n";
                echo $snippet;
                $GLOBALS['reeid_hreflang_already_echoed'] = true;
            }
        }
    }

    // Swap printers only on product requests; pages/posts untouched
    add_action('wp', function () {
        if (!is_singular('product')) return;
        // Unhook default bridge printer (priority 90)
        remove_action('wp_head', 'reeid_hreflang_print', 90);
        // Hook our canonical printer late enough to win de-duplication
        add_action('wp_head', 'reeid_hreflang_print_canonical', 100);
    }, 0);
}

/* REEID_HREFLANG_DEDUPE: keep exactly one hreflang cluster on products */
if (!function_exists('reeid_hreflang_dedupe_buffer')) {
    function reeid_hreflang_dedupe_buffer($html) {
        if (stripos($html, '</head>') === false) return $html;
        $head_start = stripos($html, '<head');
        $head_end   = stripos($html, '</head>', $head_start);
        if ($head_start === false || $head_end === false) return $html;

        $head_len = $head_end + 7 - $head_start; // include </head>
        $head = substr($html, $head_start, $head_len);

        $pattern = '/<link[^>]+rel=[\'"]alternate[\'"][^>]+hreflang=[\'"]([^\'"]+)[\'"][^>]*>\s*/i';
        if (!preg_match_all($pattern, $head, $m)) return $html;

        // Keep the LAST occurrence of each code
        $map = [];
        for ($i = 0; $i < count($m[0]); $i++) {
            $code = strtolower($m[1][$i]);
            $map[$code] = $m[0][$i];
        }

        // Build final cluster: all non x-default, then x-default last if present
        $final = [];
        foreach ($map as $code => $tag) {
            if ($code !== 'x-default') $final[$code] = $tag;
        }
        if (isset($map['x-default'])) $final['x-default'] = $map['x-default'];

        // Strip all hreflang tags from head and re-insert the de-duped block just before </head>
        $head_clean = preg_replace($pattern, '', $head);
        $block = implode("", $final);
        $head_fixed = preg_replace('/<\/head>/i', $block . '</head>', $head_clean, 1);

        // Reassemble document
        return substr($html, 0, $head_start) . $head_fixed . substr($html, $head_start + $head_len);
    }

    // Run very late so we see all emitters
    add_action('template_redirect', function () {
        if (is_singular('product')) ob_start('reeid_hreflang_dedupe_buffer');
    }, 999);
}

