<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Logger */
if ( ! function_exists( 'reeid_wc_unified_log' ) ) {
	// empty stub – safe
}

/** Strong resolver */
if ( ! function_exists( 'reeid_wc_resolve_lang_strong' ) ) {
	function reeid_wc_resolve_lang_strong(): string {

		// Prefer global helper
		if ( function_exists( 'reeid_current_language' ) ) {
			$l = (string) reeid_current_language();
			if ( $l ) {
				return strtolower( substr( $l, 0, 10 ) );
			}
		}

		/*
 * GET param override (READ-ONLY routing hint)
 * Uses filter_input() to avoid direct superglobal access.
 * No nonce required: no state-changing or privileged action is performed.
 */
$forced = '';

$forced_raw = filter_input( INPUT_GET, 'reeid_force_lang', FILTER_SANITIZE_SPECIAL_CHARS );
if ( is_string( $forced_raw ) && $forced_raw !== '' ) {
	$forced = strtolower(
		substr(
			sanitize_text_field( $forced_raw ),
			0,
			10
		)
	);
}

		if ( $forced !== '' ) {
			if ( ! function_exists( 'reeid_is_allowed_lang' ) || reeid_is_allowed_lang( $forced ) ) {

				// Check cookie already sent
				$cookie_sent = false;
				if ( function_exists( 'headers_list' ) ) {
					foreach ( headers_list() as $hdr ) {
						if ( stripos( $hdr, 'Set-Cookie: site_lang=' ) === 0 ) {
							$cookie_sent = true;
							break;
						}
					}
				}

				// Safe host from home_url()
				$home_parts = wp_parse_url( home_url() );
				$domain     = ( defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN )
					? COOKIE_DOMAIN
					: ( $home_parts['host'] ?? '' );

				$current_cookie = isset( $_COOKIE['site_lang'] )
					? sanitize_text_field( wp_unslash( $_COOKIE['site_lang'] ) )
					: '';

				if (
					! headers_sent()
					&& ( ! $cookie_sent || $current_cookie !== $forced )
				) {
					setcookie(
						'site_lang',
						$forced,
						array(
							'expires'  => time() + DAY_IN_SECONDS,
							'path'     => '/',
							'domain'   => $domain ?: '',
							'secure'   => is_ssl(),
							'httponly' => true,
							'samesite' => 'Lax',
						)
					);
				}

				$_COOKIE['site_lang'] = $forced;

				if ( function_exists( 'reeid_wc_unified_log' ) ) {
					reeid_wc_unified_log( 'FORCE_PARAM', $forced );
				}

				return $forced;
			}
		}

		// URL prefix detection (sanitized)
		$uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		if ( $uri && preg_match( '#^/([a-z]{2}(?:-[a-zA-Z0-9]{2,8})?)/#', $uri, $m ) ) {
			$pathLang = strtolower( substr( $m[1], 0, 10 ) );
			if ( $pathLang ) {
				return $pathLang;
			}
		}

		// Cookie fallback
		if ( ! empty( $_COOKIE['site_lang'] ) ) {
			$ck = strtolower(
				substr(
					sanitize_text_field( wp_unslash( $_COOKIE['site_lang'] ) ),
					0,
					10
				)
			);
			if ( $ck ) {
				return $ck;
			}
		}


// WC inline fallback: if product has inline langs, prefer first available
if ( function_exists( 'get_queried_object_id' ) ) {
    $pid = (int) get_queried_object_id();
    if ( $pid > 0 ) {
        $langs = get_post_meta( $pid, '_reeid_wc_inline_langs', true );
        if ( is_array( $langs ) && ! empty( $langs ) ) {
            $l = strtolower( substr( (string) $langs[0], 0, 10 ) );
            if ( $l !== '' ) {
                return $l;
            }
        }
    }
}
add_filter(
    'woocommerce_attribute_label',
    function ( $label, $name, $product ) {

        if ( is_admin() || ! $product instanceof WC_Product ) {
            return $label;
        }

        if ( ! function_exists( 'reeid_wc_resolve_lang_strong' ) ) {
            return $label;
        }

        $lang = reeid_wc_resolve_lang_strong();
        if ( $lang === 'en' ) {
            return $label;
        }

        $pid = (int) $product->get_id();
        if ( $pid <= 0 ) {
            return $label;
        }

        $pl = get_post_meta( $pid, '_reeid_wc_tr_' . $lang, true );
        if ( ! is_array( $pl ) || empty( $pl['attributes'] ) ) {
            return $label;
        }

        foreach ( $pl['attributes'] as $attr ) {
            if (
                ! empty( $attr['name'] )
                && sanitize_title( $attr['name'] ) === sanitize_title( $label )
            ) {
                return $attr['name'];
            }
        }

        return $label;
    },
    20,
    3
);



		return 'en';
	}
}


/** Payload cache */
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

/** Inline payload reader */
if (! function_exists('reeid_wc_read_inline_payload')) {
function reeid_wc_read_inline_payload(int $product_id, string $lang): array
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
}}
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

if ( ! function_exists( 'reeid_wc_fallback_inline_lang' ) ) {
    function reeid_wc_fallback_inline_lang( int $product_id ): string {

        $langs = get_post_meta( $product_id, '_reeid_wc_inline_langs', true );

        if ( is_array( $langs ) && ! empty( $langs ) ) {
            $l = strtolower( substr( (string) $langs[0], 0, 10 ) );
            if ( $l !== '' ) {
                return $l;
            }
        }

        return 'en';
    }
}


/** Runtime swaps (priority 99) */
add_filter('woocommerce_product_get_name', function ($name, $product) {
    if (is_admin()) return $name;

    try {
        $pid  = (int) $product->get_id();
        $lang = reeid_wc_resolve_lang_strong();
        $pl   = reeid_wc_payload_for_lang( $pid, $lang );
        if (! empty($pl['title'])) {
            reeid_wc_unified_log('NAME@99', ['id'=>$pid,'lang'=>$lang]);
            return (string)$pl['title'];
        }
    } catch (\Throwable $e) {
        reeid_wc_unified_log('NAME@99/ERR', $e->getMessage());
    }
    return $name;
}, 99, 2);

/** Last-resort fallback title override */
add_filter('the_title', function ($title, $post_id) {
    if (is_admin()) return $title;

    try {
        $p = get_post($post_id);
        if (! $p || $p->post_type !== 'product') return $title;

        $lang = reeid_wc_resolve_lang_strong();
        $pl   = reeid_wc_payload_for_lang((int)$post_id, $lang);

        if (! empty($pl['title'])) {
            reeid_wc_unified_log('THETITLE@99', ['id'=>$post_id,'lang'=>$lang]);
            return (string)$pl['title'];
        }

    } catch (\Throwable $e) {
        reeid_wc_unified_log('THETITLE@99/ERR', $e->getMessage());
    }
    return $title;
}, 99, 2);

/*==============================================================================
  SECTION 36 : Effective WC Resolver (priority 199)
==============================================================================*/

if ( ! function_exists( 'reeid_wc_effective_lang' ) ) {
	function reeid_wc_effective_lang(): string {

		/* -------------------------
		 * 1) GET param override (read-only routing hint)
		 *    Use filter_input() to avoid nonce requirement.
		 * ------------------------- */
		$forced_raw = filter_input( INPUT_GET, 'reeid_force_lang', FILTER_SANITIZE_SPECIAL_CHARS );
		if ( is_string( $forced_raw ) && $forced_raw !== '' ) {
			$forced = strtolower(
				substr(
					sanitize_text_field( $forced_raw ),
					0,
					10
				)
			);

			if ( preg_match( '/^[a-z]{2}(?:[-_][a-z0-9]{2,8})?$/', $forced ) ) {
				return $forced;
			}
		}

		/* -------------------------
		 * 2) Cookie
		 * ------------------------- */
		if ( ! empty( $_COOKIE['site_lang'] ) ) {
			$ck = strtolower(
				substr(
					sanitize_text_field( wp_unslash( $_COOKIE['site_lang'] ) ),
					0,
					10
				)
			);
			if ( $ck ) {
				return $ck;
			}
		}

		/* -------------------------
		 * 3) URL prefix detection
		 * ------------------------- */
		$uri_raw = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_UNSAFE_RAW );
		$uri     = is_string( $uri_raw )
			? sanitize_text_field( wp_unslash( $uri_raw ) )
			: '';

		if (
			$uri
			&& preg_match(
				'#^/([a-z]{2}(?:-[a-z0-9]{2,8})?)/#i',
				$uri,
				$m
			)
		) {
			$px = strtolower( substr( $m[1], 0, 10 ) );
			if ( $px ) {
				return $px;
			}
		}

		/* -------------------------
		 * 4) Global fallback
		 * ------------------------- */
		if ( function_exists( 'reeid_current_language' ) ) {
			$g = strtolower( substr( (string) reeid_current_language(), 0, 10 ) );
			if ( $g ) {
				return $g;
			}
		}

		return 'en';
	}
}


/** Late runtime override (199) */
add_filter('woocommerce_product_get_name', function ($name, $product) {
    if (is_admin()) return $name;

    $pid  = (int) $product->get_id();
    $lang = reeid_wc_effective_lang();

    if (function_exists('reeid_wc_payload_for_lang')) {
        $pl = reeid_wc_payload_for_lang($pid, $lang);
        if (! empty($pl['title'])) {
            return (string)$pl['title'];
        }
    }
    return $name;
}, 199, 2);



/*==============================================================================
  Woo Inline - Content Swap (+ request cache)
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

        // Determine language using strong/strict resolver (no cookies)
if (function_exists('reeid_wc_resolve_lang_strong')) {
    $lang = (string) reeid_wc_resolve_lang_strong();
} elseif (function_exists('_reeid_wc_resolve_lang_strict')) {
    global $post;
    $pid  = (isset($post) && $post) ? (int) $post->ID : 0;
    $lang = (string) _reeid_wc_resolve_lang_strict($pid);
} else {
    if (function_exists('reeid_s269_default_lang')) {
        $lang = (string) reeid_s269_default_lang();
    } else {
        $lang = (string) get_option('reeid_translation_source_lang', 'en');
    }
}

$lang = strtolower(trim((string) $lang));


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
  WooCommerce Admin — Product “Translations” Tab
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
add_action( 'woocommerce_admin_process_product_object', function ( \WC_Product $product ) {

	if (
		empty( $_POST['reeid_tr_nonce'] )
		|| ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['reeid_tr_nonce'] ) ),
			'reeid_tr_save'
		)
	) {
		return;
	}

	$post_id = (int) $product->get_id();

	// Default/source language with a safe fallback
	$default = function_exists( 'reeid_s269_default_lang' )
		? (string) reeid_s269_default_lang()
		: (string) get_option( 'reeid_translation_source_lang', 'en' );

	/* ===============================
 * Sanitize payload at assignment
 * =============================== */
if ( empty( $_POST['reeid_tr'] ) || ! is_array( $_POST['reeid_tr'] ) ) {
	return;
}

$payload = map_deep(
	wp_unslash( $_POST['reeid_tr'] ),
	'sanitize_textarea_field'
);

$index       = (array) get_post_meta( $post_id, '_reeid_wc_inline_langs', true );
$saved_codes = [];

	// Helper for placeholder/empty detection
	$norm = function ( $s ) {
		$s = (string) $s;
		$s = wp_strip_all_tags( $s );
		$s = preg_replace( '/\s+/u', ' ', $s );
		return strtolower( trim( $s ) );
	};

	foreach ( $payload as $code => $data ) {

		$code = function_exists( 'reeid_s269_lang_norm' )
			? reeid_s269_lang_norm( $code )
			: strtolower( substr( trim( (string) $code ), 0, 10 ) );

		if ( ! $code || strcasecmp( $code, $default ) === 0 ) {
			continue;
		}

		$title   = isset( $data['title'] )   ? (string) $data['title']   : '';
		$excerpt = isset( $data['excerpt'] ) ? (string) $data['excerpt'] : '';
		$content = isset( $data['content'] ) ? (string) $data['content'] : '';

		// Auto-translate long description if unchanged
		if (
			$content !== ''
			&& function_exists( 'reeid_translate_html_with_openai' )
			&& method_exists( $product, 'get_description' )
		) {
			$source_lang = strtolower( (string) $default );
			$target_lang = strtolower( (string) $code );

			if ( $source_lang && $target_lang && $source_lang !== $target_lang ) {
				$src_desc = (string) $product->get_description();

				if ( $src_desc !== '' ) {
					$norm_src     = $norm( $src_desc );
					$norm_content = $norm( $content );

					if ( $norm_content === $norm_src || strpos( $norm_content, $norm_src ) === 0 ) {

						$tones = get_option( 'reeid_translation_tones', array( 'Neutral' ) );
						$tone  = ( is_array( $tones ) && ! empty( $tones ) )
							? (string) reset( $tones )
							: 'Neutral';

						$translated = reeid_translate_html_with_openai(
							$src_desc,
							$source_lang,
							$target_lang,
							'classic',
							$tone
						);

						if ( ! is_wp_error( $translated ) ) {
							$translated_norm = $norm( $translated );
							if ( $translated_norm !== '' && $translated_norm !== $norm_src ) {
								$content = (string) $translated;
							}
						}
					}
				}
			}
		}

		$status = isset( $data['status'] ) ? strtolower( (string) $data['status'] ) : 'draft';
		if ( ! in_array( $status, array( 'draft', 'published', 'outdated' ), true ) ) {
			$status = 'draft';
		}

		$content_norm = $norm( $content );
		if ( $content_norm === '' || $content_norm === 'trasnlation plugin no' ) {
			delete_post_meta( $post_id, '_reeid_wc_tr_' . $code );
			delete_post_meta( $post_id, '_reeid_wc_inline_' . $code );
			continue;
		}

		$packet = array(
			'title'   => $title,
			'excerpt' => $excerpt,
			'content' => $content,
			'status'  => $status,
			'updated' => gmdate( 'c' ),
			'editor'  => (string) get_current_user_id(),
		);

		/* === WC INLINE ATTRIBUTES (custom, non-taxonomy) ================== */
		if (
			isset( $_POST['attribute_names'], $_POST['attribute_values'] )
			&& is_array( $_POST['attribute_names'] )
			&& is_array( $_POST['attribute_values'] )
		) {

			$names = array_map(
				'sanitize_text_field',
				wp_unslash( $_POST['attribute_names'] )
			);

			$values = array_map(
				'sanitize_text_field',
				wp_unslash( $_POST['attribute_values'] )
			);

			$packet['attributes'] = [];

			foreach ( $names as $attr_key => $name ) {

				if ( empty( $name ) || empty( $values[ $attr_key ] ) ) {
					continue;
				}

				$src_name  = wp_strip_all_tags( (string) $name );
				$src_value = wp_strip_all_tags( (string) $values[ $attr_key ] );

				if ( $src_name === '' && $src_value === '' ) {
					continue;
				}

				$tr_name = function_exists( 'reeid_translate_line' )
					? reeid_translate_line( $src_name, $code, 'wc_attr_label' )
					: $src_name;

				$tr_val = function_exists( 'reeid_translate_line' )
					? reeid_translate_line( $src_value, $code, 'wc_attr_value' )
					: $src_value;

				$packet['attributes'][ $attr_key ] = array(
					'name'  => $tr_name !== '' ? $tr_name : $src_name,
					'value' => $tr_val  !== '' ? $tr_val  : $src_value,
				);
			}

			if ( empty( $packet['attributes'] ) ) {
				unset( $packet['attributes'] );
			}
		}

		// Optional sanitizers
		if ( function_exists( 'reeid_wc_bp_clean_payload' ) ) {
			$packet = reeid_wc_bp_clean_payload( $packet );
		} elseif ( function_exists( 'reeid_wc_sanitize_payload_fields' ) ) {
			$packet = reeid_wc_sanitize_payload_fields( $packet );
		}

		update_post_meta( $post_id, '_reeid_wc_tr_' . $code, $packet );
		$saved_codes[] = $code;

		if ( ! in_array( $code, $index, true ) ) {
			$index[] = $code;
		}
	}

	$index = array_values(
		array_unique(
			array_filter(
				array_map( 'reeid_s269_lang_norm', $index )
			)
		)
	);
	update_post_meta( $post_id, '_reeid_wc_inline_langs', $index );

	// Log summary
	$lens = [];
	foreach ( $saved_codes as $c ) {
		$m = get_post_meta( $post_id, '_reeid_wc_tr_' . $c, true );
		$lens[ $c ] = array(
			't' => isset( $m['title'] )   ? mb_strlen( (string) $m['title'] )   : 0,
			'e' => isset( $m['excerpt'] ) ? mb_strlen( (string) $m['excerpt'] ) : 0,
			'c' => isset( $m['content'] ) ? mb_strlen( (string) $m['content'] ) : 0,
			's' => isset( $m['status'] )  ? $m['status'] : '',
		);
	}

	reeid_s269_log(
		'SAVE',
		array(
			'post_id' => $post_id,
			'langs'   => $saved_codes,
			'lens'    => $lens,
		)
	);

}, 10 );




