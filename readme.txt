=== REEID Translate ===
Contributors: ridgc
Tags: translation, multilingual, localization, ai, elementor
Requires at least: 5.5
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.7
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

One-click translation and publishing for posts and pages. Elementor/Gutenberg compatible. SEO-friendly with native-script slugs and hreflang.

== Description ==

**REEID Translate** instantly translates posts and pages into 20+ languages using advanced AI.  
**Elementor, Gutenberg, and the Classic Editor are fully supported.** No more copy–paste, duplicate layouts, or broken blocks—just publish-ready, SEO-optimized translations with native-script slugs.

> **Note:** This is a **freemium** plugin. The core features are free under GPL.  
> Unlock advanced **Premium** features with a valid license.

### Features

1. **Instant Post & Page Translation**  
   - Translate any post or page in one click—Gutenberg, Classic, and Elementor (Premium)  
   - Supports 20+ languages: English, Chinese, Spanish, French, German, Japanese, Thai, Arabic, Russian, Italian, Portuguese, Hindi, Korean, Bengali, Dutch, Greek, Polish, Turkish, Vietnamese, Nepali, Indonesian, and more  
   - Preserves all HTML, blocks, and shortcodes

2. **SEO-Ready URLs & Metadata**  
   - Translated URLs like `/lang/your-translated-slug/`  
   - **Native-script slug** generation for better international SEO  
   - Menus and internal links rewrite per language  
   - Canonical URLs, `<html lang="">`, and `hreflang` output (Yoast, Rank Math, AIOS compatible; native fallback included)

3. **Flexible Tone & Custom Prompts**  
   - Choose tone/style: Neutral, Formal, Informal, Friendly, Technical, Persuasive, Concise, Verbose  
   - Add custom translation instructions (glossary, never-translate terms, terminology rules)

4. **Bulk & Single Translation**  
   - Translate one post to one language—or to many at once (Bulk is **Premium**)  
   - Elementor panel for single and bulk translation (Premium)

5. **Frontend Language Switcher**  
   - Shortcode: `[reeid_language_switcher]` with multiple visual styles (compact/minimal, etc.)  
   - Language URLs auto-detected and synced  
   - “English” button can always link to the default site root

6. **Admin Panel: Everything in One Place**  
   - Settings: API key, tone/style, custom prompt, default language, bulk languages (Premium), license key  
   - Appearance tab for switcher style/theme  
   - Tools: repair translation maps, clear caches, uninstall cleanup  
   - Optional “remove all plugin data on uninstall”

7. **Editor Support**  
   - Inline meta box for translation in Classic and Gutenberg  
   - Full support for standard and Premium Elementor widgets (via Elementor panel)

8. **Advanced SEO Integration**  
   - Canonical and `hreflang` tag output for major SEO plugins or native fallback  
   - Translated meta title/description preserved where possible

9. **Safe, Scalable, User-Friendly**  
   - Translations linked by the original post/page ID (no orphaned content)  
   - Original content remains untouched  
   - Clean uninstall option (remove settings/meta), translations remain unless manually deleted

### Premium Features
- Bulk translation to multiple languages at once  
- Elementor translation panel (single + bulk)  
- License validation UI and priority updates/support  
- Continuous enhancements and exclusive settings

== Installation ==

1. Upload the `reeid-translate` folder to `/wp-content/plugins/`
2. Activate the plugin from **Plugins → Installed Plugins**
3. Go to **Settings → REEID Translate**:  
   - Enter your [OpenAI API Key](https://platform.openai.com/account/api-keys)  
   - (Optional) Choose tone/style or add custom translation instructions  
   - (Optional) Select bulk languages and add your license key to enable **Premium** features
4. Edit any post or page. Use the REEID Translate meta box (Classic/Gutenberg) or the Elementor panel (Premium) to translate in one click.
5. Add `[reeid_language_switcher]` anywhere (page, widget, template) to display a language/flag selector.

== Frequently Asked Questions ==

= Which languages are supported? =  
20+ languages: English (en), Chinese (zh), Dutch (nl), German (de), French (fr), Spanish (es), Italian (it), Indonesian (id), Arabic (ar), Russian (ru), Polish (pl), Greek (el), Japanese (ja), Korean (ko), Thai (th), Hindi (hi), Bengali (bn), Turkish (tr), Vietnamese (vi), Portuguese (pt), Nepali (ne), and more.

= How are translations linked? =  
All translations are mapped by the original post/page ID. Navigation and menus stay in sync with no orphaned links.

= Can I edit translated slugs/titles? =  
Yes. You can tweak anything, though letting the plugin generate native-script slugs and titles often gives the best SEO results.

= Will Elementor and Gutenberg pages keep their layout? =  
Yes. The plugin translates user-visible text and keeps your layout, images, and blocks intact.

= Does it work with Yoast, Rank Math, or other SEO plugins? =  
Yes. Canonical and `hreflang` tags are injected for all translated pages. SEO meta (title/description) is preserved where applicable.

= What if I don’t see a language switcher or menu link? =  
Go to **Settings → Permalinks** and click **Save** to refresh rules.  
If a translation is missing, try **Repair Maps** in the plugin’s **Tools** tab.

= Can I remove all plugin data? =  
Yes. There’s an uninstall cleanup option for settings/meta. Translated posts/pages remain unless you delete them.

== Screenshots ==

1. Admin settings: API key, languages, tones, custom prompt, license (Premium)  
2. Translation meta box in post/page editor (Classic, Gutenberg); Elementor panel (Premium)  
3. Language switcher (flags and dropdown)  
4. SEO-friendly `/lang/slug/` URLs in browser navigation  
5. Elementor layout in English and Chinese, preserved perfectly

== Changelog ==

= 1.7 =
* SEO: Canonical URL, `<html lang>`, and `hreflang` output with Yoast/Rank Math/AIOS integration and native fallback  
* Routing: Native-script slugs with language-prefixed URLs  
* Editors: Improved compatibility for Elementor (Premium) and Gutenberg/Classic  
* Admin: Tones, custom prompts, bulk languages (Premium), license UI  
* Stability: Hardened AJAX handlers, nonce checks, and safe sanitization

= 1.6 =
* Custom instructions prompt  
* Bulk translation (Premium)  
* Full uninstall/cleanup logic

= 1.5 =
* Front page and menu rewrite support for multilingual URLs

= 1.4 =
* Tone/style picker, AJAX refactor

= 1.3 =
* Flag switcher shortcode, dynamic menu/URL mapping

= 1.2 =
* First public release: AI translation for title/slug/content with mapping

== Upgrade Notice ==

= 1.7 =
SEO and routing improvements, plus editor compatibility updates. After updating, please re-save plugin settings and **flush permalinks** (Settings → Permalinks → Save).

== License ==

This plugin is free software under the GNU General Public License v3.0 or later (GPL-3.0-or-later).  
**Premium** features require a valid license for activation.  
Premium license terms: https://reeid.com/reeid-translation-pro-license/

== External Services ==

This plugin connects to OpenAI’s API (https://platform.openai.com/) to provide translation features. When translating, your post/page content and selected settings are sent to OpenAI for processing.  
See OpenAI’s terms and privacy policy:
- https://openai.com/policies/terms-of-use
- https://openai.com/policies/privacy-policy
