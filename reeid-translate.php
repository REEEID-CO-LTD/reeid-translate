<?php
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


require_once __DIR__ . "/includes/rt-wc-i18n-lite.php";
require_once __DIR__ . "/includes/reeid-focuskw-sync.php";
require_once __DIR__ . "/includes/seo-sync.php";

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


/* SECTION: Elementor — Normalizers + Safe Render */
if ( ! function_exists('reeid_el_get_json') ) {
    function reeid_el_get_json(int $post_id) {
        $raw = (string) get_post_meta($post_id, '_elementor_data', true);
        $dec = json_decode($raw, true);
        if (is_array($dec)) return $dec;

        // try unserialize → JSON
        if (function_exists('is_serialized') && is_serialized($raw)) {
            $u = @unserialize($raw);
            if (is_array($u)) return $u;
        }

        // last resort: minimal valid doc (object-root)
        return [
            'version'  => '0.4',
            'title'    => 'Recovered',
            'type'     => 'page',
            'elements' => [[
                'id'=>'rt-sec','elType'=>'section','settings'=>[],
                'elements'=> [[
                    'id'=>'rt-col','elType'=>'column','settings'=>['_column_size'=>100],
                    'elements'=> [[
                        'id'=>'rt-head','elType'=>'widget','widgetType'=>'heading',
                        'settings'=>['title'=>'Recovered Elementor content'], 'elements'=>[]
                    ]]
                ]]
            ]],
            'settings' => []
        ];
    }
}

if ( ! function_exists('reeid_el_save_json') ) {
    /**
     * Save Elementor JSON and keep page settings intact.
     *
     * @param int          $post_id
     * @param array|string $tree Elementor data (array or JSON string).
     */
    function reeid_el_save_json(int $post_id, $tree): void {
        // Accept array-root (sections array) or object-root; store JSON string
        if (is_array($tree) && isset($tree[0]) && is_array($tree[0])) {
            $json = wp_json_encode($tree, JSON_UNESCAPED_UNICODE);
        } else {
            $json = wp_json_encode($tree, JSON_UNESCAPED_UNICODE);
        }

        update_post_meta($post_id, '_elementor_data', $json);
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        update_post_meta($post_id, '_elementor_template_type', 'wp-page');

        $ver = get_option('elementor_version');
        if (!$ver && defined('ELEMENTOR_VERSION')) {
            $ver = ELEMENTOR_VERSION;
        }
        if ($ver) {
            update_post_meta($post_id, '_elementor_version', $ver);
        }

        // 🔁 Keep _elementor_page_settings as-is (do NOT strip layout keys)
        $ps = get_post_meta($post_id, '_elementor_page_settings', true);
        if (! is_array($ps)) {
            $ps = array();
        }
        update_post_meta($post_id, '_elementor_page_settings', $ps);

        // Regen CSS (safe if Elementor exists)
        if (class_exists('\Elementor\Plugin')) {
            try {
                (new \Elementor\Core\Files\CSS\Post($post_id))->update();
            } catch (\Throwable $e) {
                // silent fail, no fatal for frontend
            }
        }
    }
}


if ( ! function_exists('reeid_el_render_ok') ) {
    function reeid_el_render_ok(int $post_id): bool {
        if (!class_exists('\Elementor\Plugin')) return true; // cannot render but don't block
        try {
            $html = \Elementor\Plugin::$instance->frontend->get_builder_content_for_display($post_id, false);
            return (is_string($html) && strlen($html)>0 && strpos($html,'class="elementor')!==false);
        } catch (\Throwable $e) { return false; }
    }
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
    register_setting('reeid_translate_settings', 'reeid_openai_model', a