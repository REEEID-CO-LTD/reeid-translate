<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*==============================================================================
  Admin “View” Language Guard (+ quick cookie clear)
==============================================================================*/

/**
 * Normalize language code.
 */
if ( ! function_exists( 'reeid_s2612_norm' ) ) {
	function reeid_s2612_norm( $v ): string {
		$v = strtolower( substr( (string) $v, 0, 10 ) );
		return preg_match( '/^[a-z]{2}(?:[-_][a-z0-9]{2})?$/', $v ) ? $v : 'en';
	}
}

/**
 * Source (default) language.
 */
if ( ! function_exists( 'reeid_s2612_source_lang' ) ) {
	function reeid_s2612_source_lang(): string {
		$src = (string) get_option( 'reeid_translation_source_lang', 'en' );
		return reeid_s2612_norm( $src );
	}
}

/**
 * Safe helper: current request path.
 */
if ( ! function_exists( 'reeid_get_request_path' ) ) {
	function reeid_get_request_path(): string {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '/';
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$uri   = wp_unslash( $_SERVER['REQUEST_URI'] );
		$parts = wp_parse_url( $uri );

		return isset( $parts['path'] ) ? (string) $parts['path'] : '/';
	}
}

/**
 * 1) Products list table: force "View" to source language.
 */
add_filter(
	'post_row_actions',
	function ( array $actions, WP_Post $post ) {

		if ( $post->post_type !== 'product' ) {
			return $actions;
		}

		$src      = reeid_s2612_source_lang();
		$view_url = get_permalink( $post );

		if ( ! $view_url ) {
			return $actions;
		}

		$view_url = add_query_arg(
			array(
				'reeid_force_lang' => $src,
				'_reeid_nonce'     => wp_create_nonce( 'reeid_view_lang' ),
			),
			$view_url
		);

		$actions['view'] = sprintf(
			'<a href="%s" rel="bookmark" aria-label="%s">%s</a>',
			esc_url( $view_url ),
			esc_attr(
				sprintf(
					// translators: %1$s is the post title; %2$s is the source language code.
					__( 'View “%1$s” in %2$s', 'reeid-translate' ),
					$post->post_title,
					strtoupper( $src )
				)
			),
			esc_html__( 'View', 'reeid-translate' )
		);

		return $actions;
	},
	10,
	2
);

/**
 * 2) Admin bar “View” link.
 */
add_action(
	'admin_bar_menu',
	function ( WP_Admin_Bar $bar ) {

		if ( ! is_admin() || empty( $_GET['post'] ) || empty( $_GET['_reeid_nonce'] ) ) {
			return;
		}

		$post_id = absint( wp_unslash( $_GET['post'] ) );
		if ( ! $post_id ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['_reeid_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'reeid_view_lang' ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'product' ) {
			return;
		}

		$src = reeid_s2612_source_lang();

		$url = add_query_arg(
			array(
				'reeid_force_lang' => $src,
				'_reeid_nonce'     => wp_create_nonce( 'reeid_view_lang' ),
			),
			get_permalink( $post )
		);

		if ( $node = $bar->get_node( 'view' ) ) {
			$node->href = esc_url( $url );
			$bar->add_node( $node );
		}
	},
	100
);

/**
 * 3) Optional helper: clear language cookie via ?reeid_clear_lang=1
 */
add_action(
	'template_redirect',
	function () {

		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		if ( empty( $_GET['reeid_clear_lang'] ) || empty( $_GET['_reeid_nonce'] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['_reeid_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'reeid_view_lang' ) ) {
			return;
		}

		$src = reeid_s2612_source_lang();
		$_COOKIE['site_lang'] = $src;

		$path = reeid_get_request_path();

		$query = array();
		if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$uri   = wp_unslash( $_SERVER['REQUEST_URI'] );
			$parts = wp_parse_url( $uri );
			if ( ! empty( $parts['query'] ) ) {
				parse_str( $parts['query'], $query );
				unset( $query['reeid_clear_lang'], $query['_reeid_nonce'] );
			}
		}

		$dest = $path . ( $query ? '?' . http_build_query( $query ) : '' );

		if ( ! headers_sent() ) {
			wp_safe_redirect( esc_url_raw( $dest ), 302 );
			exit;
		}
	},
	9
);



/*==============================================================================
  Woo Inline — Delete One Translation (UI + Admin Action)
==============================================================================*/

/** Core helper: delete one language payload from a product */
if ( ! function_exists( 'reeid_wc_delete_translation_meta' ) ) {
	function reeid_wc_delete_translation_meta( int $product_id, string $lang ): bool {

		$lang = strtolower( substr( trim( $lang ), 0, 10 ) );
		if ( $lang === '' ) {
			return false;
		}

		$key = '_reeid_wc_tr_' . $lang;

		delete_post_meta( $product_id, $key );

		$langs = (array) get_post_meta( $product_id, '_reeid_wc_inline_langs', true );
		$langs = array_values(
			array_filter(
				$langs,
				static function ( $c ) use ( $lang ) {
					return strtolower( trim( (string) $c ) ) !== $lang;
				}
			)
		);
		update_post_meta( $product_id, '_reeid_wc_inline_langs', $langs );

		delete_post_meta( $product_id, '_reeid_wc_seo_' . $lang );
		delete_post_meta( $product_id, '_reeid_wc_seo_title_' . $lang );
		delete_post_meta( $product_id, '_reeid_wc_seo_desc_' . $lang );
		delete_post_meta( $product_id, '_reeid_wc_seo_slug_' . $lang );

		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( $product_id );
		}

		do_action( 'reeid_wc_translation_deleted', $product_id, $lang );

		return true;
	}
}

/**
 * Admin action: delete translation
 */
add_action(
	'admin_post_reeid_wc_delete_translation',
	function () {

		if ( empty( $_GET['_wpnonce'] ) ) {
			wp_die( esc_html__( 'Security check failed.', 'reeid-translate' ) );
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'reeid_del_tr' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'reeid-translate' ) );
		}

		$post_id = isset( $_GET['post'] )
			? absint( wp_unslash( $_GET['post'] ) )
			: 0;

		$lang = isset( $_GET['lang'] )
			? sanitize_text_field( wp_unslash( $_GET['lang'] ) )
			: '';

		$ok = false;
		if ( $post_id && $lang ) {
			$ok = reeid_wc_delete_translation_meta( $post_id, $lang );
		}

		$redirect = add_query_arg(
			array(
				'post'             => $post_id,
				'action'           => 'edit',
				'reeid_tr_deleted' => rawurlencode( $lang ),
				'reeid_tr_ok'      => $ok ? '1' : '0',
				'_wpnonce'         => wp_create_nonce( 'reeid_tr_notice' ),
			),
			admin_url( 'post.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}
);

/**
 * Admin notice after delete
 */
add_action(
	'admin_notices',
	function () {

		if (
			! is_admin()
			|| empty( $_GET['_wpnonce'] )
			|| empty( $_GET['reeid_tr_deleted'] )
		) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'reeid_tr_notice' ) ) {
			return;
		}

		$lang = sanitize_text_field( wp_unslash( $_GET['reeid_tr_deleted'] ) );
		$ok   = ! empty( $_GET['reeid_tr_ok'] );

		$msg = $ok
			? sprintf(
				// translators: %1$s is the language code removed.
				__( 'Translation "%1$s" removed from this product.', 'reeid-translate' ),
				$lang
			)
			: sprintf(
				// translators: %1$s is the language code that failed removal.
				__( 'Could not remove translation "%1$s".', 'reeid-translate' ),
				$lang
			);

		printf(
			'<div class="notice %1$s"><p>%2$s</p></div>',
			esc_attr( $ok ? 'updated' : 'error' ),
			esc_html( $msg )
		);
	}
);


/**
 * UI: inject delete translation button
 */
add_action( 'admin_footer-post.php', 'reeid_s2613_inject_delete_ui', 20 );
add_action( 'admin_footer-post-new.php', 'reeid_s2613_inject_delete_ui', 20 );

if ( ! function_exists( 'reeid_s2613_inject_delete_ui' ) ) {
	function reeid_s2613_inject_delete_ui() {

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'product' ) {
			return;
		}

		global $post;
		if ( ! $post || empty( $post->ID ) ) {
			return;
		}

		$product_id = (int) $post->ID;

		$nonce   = wp_create_nonce( 'reeid_del_tr' );
		$action  = admin_url( 'admin-post.php' );
		$label   = __( 'Delete translation', 'reeid-translate' );
		$confirm = __( 'Are you sure you want to delete this translation?', 'reeid-translate' );
		?>
		<script>
		(function(){
			'use strict';

			var label = <?php echo wp_json_encode( $label ); ?>;
			var confirmMsg = <?php echo wp_json_encode( $confirm ); ?>;
			var actionUrl = <?php echo wp_json_encode( $action ); ?>;
			var productId = <?php echo wp_json_encode( $product_id ); ?>;
			var nonceVal = <?php echo wp_json_encode( $nonce ); ?>;

			function findSelect() {
				return document.getElementById('reeid-tr-langselect')
					|| document.querySelector('#reeid_translations_panel select, .reeid-translations select');
			}

			function buildUrl(lang) {
				return actionUrl +
					'?action=reeid_wc_delete_translation' +
					'&post=' + encodeURIComponent(productId) +
					'&lang=' + encodeURIComponent(lang) +
					'&_wpnonce=' + encodeURIComponent(nonceVal);
			}

			function addButton(sel) {
				if (!sel || document.getElementById('reeid-del-tr-btn')) return;

				var btn = document.createElement('a');
				btn.id = 'reeid-del-tr-btn';
				btn.className = 'button button-link-delete';
				btn.style.marginLeft = '8px';
				btn.textContent = label;

				btn.addEventListener('click', function(e){
					e.preventDefault();
					var lang = (sel.value || '').toLowerCase().slice(0,10);
					if (!lang || !confirm(confirmMsg)) return;
					window.location.assign(buildUrl(lang));
				});

				sel.parentNode.appendChild(btn);
			}

			function init(){
				var sel = findSelect();
				if (sel) {
					addButton(sel);
					return;
				}

				var obs = new MutationObserver(function(){
					var s = findSelect();
					if (s) {
						addButton(s);
						obs.disconnect();
					}
				});
				obs.observe(document.body, {childList:true, subtree:true});
			}

			if (document.readyState === 'loading') {
				document.addEventListener('DOMContentLoaded', init);
			} else {
				init();
			}
		})();
		</script>
		<?php
	}
}


/* -------------------- Optional: Server-side handler skeleton --------------------
   Handles admin-post.php and redirects back with success/failure flags.
-------------------------------------------------------------------------------*/
add_action( 'admin_post_reeid_wc_delete_translation', 'reeid_wc_delete_translation_handler' );
if ( ! function_exists( 'reeid_wc_delete_translation_handler' ) ) {
	function reeid_wc_delete_translation_handler() {

		$referer = wp_get_referer() ? wp_get_referer() : admin_url();

		if ( empty( $_GET['_wpnonce'] ) ) {
			wp_safe_redirect(
				add_query_arg(
					array( 'reeid_tr_deleted' => '', 'reeid_tr_ok' => 0 ),
					$referer
				)
			);
			exit;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'reeid_del_tr' ) ) {
			wp_safe_redirect(
				add_query_arg(
					array( 'reeid_tr_deleted' => '', 'reeid_tr_ok' => 0 ),
					$referer
				)
			);
			exit;
		}

		$post_id = isset( $_GET['post'] )
			? absint( wp_unslash( $_GET['post'] ) )
			: 0;

		$lang = isset( $_GET['lang'] )
			? sanitize_text_field( wp_unslash( $_GET['lang'] ) )
			: '';

		if ( ! $post_id || ! $lang || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'reeid_tr_deleted' => rawurlencode( $lang ),
						'reeid_tr_ok'      => 0,
					),
					$referer
				)
			);
			exit;
		}

		$deleted_ok = false;

		// Preferred: plugin-specific deletion helper
		if ( function_exists( 'reeid_delete_translation_for_product' ) ) {
			try {
				$deleted_ok = (bool) reeid_delete_translation_for_product( $post_id, $lang );
			} catch ( Exception $e ) {
				$deleted_ok = false;
			}
		} else {
			// Fallback deletion
			$meta_key = '_reeid_wc_tr_' . $lang;
			if ( delete_post_meta( $post_id, $meta_key ) ) {

				$langs = (array) get_post_meta( $post_id, '_reeid_wc_inline_langs', true );
				$langs = array_values(
					array_filter(
						$langs,
						static function ( $c ) use ( $lang ) {
							return strtolower( trim( (string) $c ) ) !== $lang;
						}
					)
				);
				update_post_meta( $post_id, '_reeid_wc_inline_langs', $langs );

				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $post_id );
				}

				$deleted_ok = true;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'reeid_tr_deleted' => rawurlencode( $lang ),
					'reeid_tr_ok'      => $deleted_ok ? 1 : 0,
				),
				$referer
			)
		);
		exit;
	}
}




 /*==============================================================================
    Switcher placement
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

    // /* ---------- A) Cart & Checkout inline switcher --------------------------- */
    // /* Renders above the form/contents so users can switch language there too. */
    // add_action('woocommerce_before_cart', function () {
    //     echo do_shortcode('[reeid_lang_switcher style="inline" class="reeid-switcher-cart"]');
    //     reeid_s283_log('RENDER@cart', true);
    // }, 5);

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
    Switcher UI — add dropdown mode (list | inline | dropdown)
==============================================================================*/

if ( ! function_exists( 'reeid_s284_log' ) ) {
	function reeid_s284_log( $label, $data = null ) {
		if ( function_exists( 'reeid_debug_log' ) ) {
			reeid_debug_log( 'S28.4 ' . $label, $data );
		}
	}
}

/* Use helpers from S26.10 (reeid_s2610_lang, _site_langs, _product_langs, _with_lang) */

if ( ! function_exists( 'reeid_render_lang_switcher_v2' ) ) {
	function reeid_render_lang_switcher_v2( $atts = [] ): string {

		$atts = shortcode_atts(
			[
				'class' => 'reeid-lang-switcher',
				'style' => 'list',   // list | inline | dropdown
				'id'    => '',
			],
			$atts,
			'reeid_lang_switcher'
		);

		$current = function_exists( 'reeid_s2610_lang' ) ? reeid_s2610_lang() : 'en';

		// Discover languages
		$langs = [];
		if ( function_exists( 'is_product' ) && is_product() && function_exists( 'reeid_s2610_product_langs' ) ) {
			global $post;
			$pid = $post ? (int) $post->ID : 0;
			if ( $pid ) {
				$langs = reeid_s2610_product_langs( $pid );
			}
		}
		if ( ! $langs && function_exists( 'reeid_s2610_site_langs' ) ) {
			$langs = reeid_s2610_site_langs();
		}
		if ( ! $langs ) {
			return '';
		}

		// Build current URL safely (no direct $_SERVER usage)
		$path = '/';
		if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$uri   = wp_unslash( $_SERVER['REQUEST_URI'] );
			$parts = wp_parse_url( $uri );
			$path  = $parts['path'] ?? '/';
			if ( ! empty( $parts['query'] ) ) {
				$path .= '?' . $parts['query'];
			}
		}

		$here = home_url( $path );

		// Dropdown mode
		if ( strtolower( $atts['style'] ) === 'dropdown' ) {

			$id   = $atts['id']
				? preg_replace( '/[^a-z0-9_\-]/i', '', $atts['id'] )
				: 'reeid-sw-' . wp_generate_uuid4();
			$opts = '';

			foreach ( $langs as $code => $label ) {
				$href = function_exists( 'reeid_s2610_with_lang' )
					? reeid_s2610_with_lang( $here, $code )
					: $here;
				$sel  = ( $code === $current ) ? ' selected' : '';
				$opts .= '<option value="' . esc_attr( $href ) . '" data-code="' . esc_attr( $code ) . '"' . $sel . '>' . esc_html( $label ) . '</option>';
			}

			$html  = '<div class="' . esc_attr( $atts['class'] ) . ' reeid-switcher--dropdown">';
			$html .= '<select id="' . esc_attr( $id ) . '" aria-label="Language switcher">' . $opts . '</select>';
			$html .= '</div>';
			$html .= '<script>document.addEventListener("change",function(e){var el=e.target;if(el&&el.id==="' . esc_js( $id ) . '"){window.location.href=el.value;}});</script>';

			return $html;
		}

		// Link modes (list / inline)
		$items = [];
		foreach ( $langs as $code => $label ) {
			$href   = function_exists( 'reeid_s2610_with_lang' )
				? reeid_s2610_with_lang( $here, $code )
				: $here;
			$active = ( $code === $current ) ? ' aria-current="true" class="active"' : '';
			$items[] = sprintf(
				'<a href="%s"%s data-code="%s">%s</a>',
				esc_url( $href ),
				$active,
				esc_attr( $code ),
				esc_html( $label )
			);
		}

		$html = '<nav class="' . esc_attr( $atts['class'] ) . '">';
		if ( strtolower( $atts['style'] ) === 'inline' ) {
			$html .= implode( ' <span class="sep">•</span> ', $items );
		} else {
			$html .= '<ul><li>' . implode( '</li><li>', $items ) . '</li></ul>';
		}
		$html .= '</nav>';

		return $html;
	}
}


    /* ============================================================================
   FIX: Disable legacy [reeid_lang_switcher] and delegate to the main switcher
   - Prevents WooCommerce fallback switcher from rendering
   - Ensures only [reeid_language_switcher] runs
   ============================================================================
*/

add_action('init', function () {

    // Fully neutralize the old shortcode if it exists
    if (shortcode_exists('reeid_lang_switcher')) {
        remove_shortcode('reeid_lang_switcher');
    }

    // Re-register it as a no-output shim for old themes/plugins
    add_shortcode('reeid_lang_switcher', function () {
        if (function_exists('reeid_s284_log')) {
            reeid_s284_log('SHORTCODE_SUPPRESSED', true);
        }
        return ''; // return nothing → prevents duplicate Woo switcher
    });

}, 40);

/* ============================================================================
   OPTIONAL MINIMAL CSS (kept for backwards compatibility)
   Safe because it only affects `.reeid-lang-switcher` which now outputs nothing
   ============================================================================
*/
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
     Cart/Checkout Switcher Gate (single dropdown, deduped)
==============================================================================*/

if ( ! function_exists( 'reeid_s285_log' ) ) {
	function reeid_s285_log( $label, $data = null ) {
		if ( function_exists( 'reeid_debug_log' ) ) {
			reeid_debug_log( 'S28.5 ' . $label, $data );
		}
	}
}

/* -------------------------------------------------------
 *  A) Dropdown-capable renderer (v3) with Cart/Checkout GATE
 * ----------------------------------------------------- */

if ( ! function_exists( 'reeid_render_lang_switcher_v3' ) ) {
	function reeid_render_lang_switcher_v3( $atts = [] ): string {

		// Gate — Cart / Checkout only allow one instance
		$is_cc = ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() );

		if ( $is_cc && empty( $GLOBALS['reeid_sw_gate_open'] ) ) {
			reeid_s285_log( 'BLOCKED_EXTRA_CC_INSTANCE', true );
			return '';
		}

		$atts = shortcode_atts(
			[
				'class' => 'reeid-lang-switcher',
				'style' => 'list',      // list | inline | dropdown
				'id'    => '',
			],
			$atts,
			'reeid_lang_switcher'
		);

		$current = function_exists( 'reeid_s2610_lang' ) ? reeid_s2610_lang() : 'en';

		// Discover languages
		$langs = [];
		if ( function_exists( 'is_product' ) && is_product() && function_exists( 'reeid_s2610_product_langs' ) ) {
			global $post;
			$pid = $post ? (int) $post->ID : 0;
			if ( $pid ) {
				$langs = reeid_s2610_product_langs( $pid );
			}
		}
		if ( ! $langs && function_exists( 'reeid_s2610_site_langs' ) ) {
			$langs = reeid_s2610_site_langs();
		}
		if ( ! $langs ) {
			return '';
		}

		// Build current URL safely (no direct HTTP_HOST usage)
		$path = '/';
		if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$uri   = wp_unslash( $_SERVER['REQUEST_URI'] );
			$parts = wp_parse_url( $uri );
			$path  = $parts['path'] ?? '/';
			if ( ! empty( $parts['query'] ) ) {
				$path .= '?' . $parts['query'];
			}
		}

		$here = home_url( $path );

		// --- Dropdown mode ---
		if ( strtolower( $atts['style'] ) === 'dropdown' ) {

			$id   = $atts['id']
				? preg_replace( '/[^a-z0-9_\-]/i', '', $atts['id'] )
				: 'reeid-sw-' . wp_generate_uuid4();
			$opts = '';

			foreach ( $langs as $code => $label ) {
				$href = function_exists( 'reeid_s2610_with_lang' )
					? reeid_s2610_with_lang( $here, $code )
					: $here;
				$sel  = ( $code === $current ) ? ' selected' : '';
				$opts .= '<option value="' . esc_attr( $href ) . '" data-code="' . esc_attr( $code ) . '"' . $sel . '>' . esc_html( $label ) . '</option>';
			}

			$html  = '<div class="' . esc_attr( $atts['class'] ) . ' reeid-switcher--dropdown">';
			$html .= '<select id="' . esc_attr( $id ) . '" aria-label="Language switcher">' . $opts . '</select>';
			$html .= '</div>';
			$html .= '<script>document.addEventListener("change",function(e){var el=e.target;if(el && el.id==="' . esc_js( $id ) . '"){window.location.href=el.value;}});</script>';

			return $html;
		}

		// --- Links (list / inline) ---
		$items = [];
		foreach ( $langs as $code => $label ) {
			$href   = function_exists( 'reeid_s2610_with_lang' )
				? reeid_s2610_with_lang( $here, $code )
				: $here;
			$active = ( $code === $current ) ? ' aria-current="true" class="active"' : '';
			$items[] = sprintf(
				'<a href="%s"%s data-code="%s">%s</a>',
				esc_url( $href ),
				$active,
				esc_attr( $code ),
				esc_html( $label )
			);
		}

		$html = '<nav class="' . esc_attr( $atts['class'] ) . '">';
		if ( strtolower( $atts['style'] ) === 'inline' ) {
			$html .= implode( ' <span class="sep">•</span> ', $items );
		} else {
			$html .= '<ul><li>' . implode( '</li><li>', $items ) . '</li></ul>';
		}
		$html .= '</nav>';

		return $html;
	}
}

/* Replace shortcode with the gated renderer */
add_action(
	'init',
	function () {
		if ( shortcode_exists( 'reeid_lang_switcher' ) ) {
			remove_shortcode( 'reeid_lang_switcher' );
		}
		add_shortcode( 'reeid_lang_switcher', 'reeid_render_lang_switcher_v3' );
		reeid_s285_log( 'SHORTCODE_GATED', true );
	},
	50
);



    /* -------------------------------------------------------
 *  B) Minimal CSS (compact & tidy)
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


add_action('init', function () {
    // Disable Woo fallback switcher completely
    remove_shortcode('reeid_lang_switcher');
}, 999);
