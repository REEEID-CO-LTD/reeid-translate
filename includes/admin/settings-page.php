<?php


function reeid_add_settings_page() {

    add_options_page(
        __( 'REEID Translate Settings', 'reeid-translate' ),
        __( 'REEID Translate', 'reeid-translate' ),
        'manage_options',
        'reeid-translate-settings',
        'reeid_render_settings_page'
    );
}


/* =============================================================================
   FAQ TAB RENDERER (accordion)
   ============================================================================= */
function reeid_render_faq_tab() {

    if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
        echo '<div class="notice notice-error"><p>' .
            esc_html__( 'Unauthorized.', 'reeid-translate' ) .
        '</p></div>';
        return;
    }

    /* Column 1 FAQ items */
    $faqs_col1 = array(
        array(
            'q' => __( 'How do I translate a post or page?', 'reeid-translate' ),
            'a' => __( 'Use the "REEID Translate" Meta-box or Elementor sidebar. Choose a language, tone, or custom prompt.', 'reeid-translate' ),
        ),
        array(
            'q' => __( 'Compatible with Elementor / Gutenberg / Classic?', 'reeid-translate' ),
            'a' => __( 'Yes. The plugin auto-detects the editor and preserves layout.', 'reeid-translate' ),
        ),
        array(
            'q' => __( 'What gets translated?', 'reeid-translate' ),
            'a' => __( 'Titles, slugs, content, SEO/meta fields, and supported editor blocks.', 'reeid-translate' ),
        ),
        array(
            'q' => __( 'Do slugs stay native (non-Latin)?', 'reeid-translate' ),
            'a' => __( 'Yes. Chinese, Thai, Arabic, etc. are preserved as native Unicode slugs.', 'reeid-translate' ),
        ),
        array(
            'q' => __( 'How do I bulk translate?', 'reeid-translate' ),
            'a' => __( 'Bulk translation is PRO. Set languages in Settings → Bulk Languages.', 'reeid-translate' ),
        ),
        array(
            'q' => __( 'Where do I put the language switcher?', 'reeid-translate' ),
            'a' => __( 'Use shortcode [reeid_language_switcher] or theme templates.', 'reeid-translate' ),
        ),
        array(
            'q' => __( 'How do I customize the switcher appearance?', 'reeid-translate' ),
            'a' => __( 'Tools → Switcher Appearance. Choose style, theme, or override CSS.', 'reeid-translate' ),
        ),
        array(
            'q' => __( 'How does SEO integrate with Yoast or Rank Math?', 'reeid-translate' ),
            'a' => __( 'Hreflang + canonical logic is synced and safe for multilingual SEO.', 'reeid-translate' ),
        ),
        array(
            'q' => __( 'Will search engines index translated pages?', 'reeid-translate' ),
            'a' => __( 'Yes. The plugin ensures proper indexing + hreflang output.', 'reeid-translate' ),
        ),
        array(
            'q' => __( 'Are there usage limits?', 'reeid-translate' ),
            'a' => __( 'Yes. You may have a daily or per-minute limit depending on plan.', 'reeid-translate' ),
        ),
        array(
            'q' => __( 'What happens if I hit a limit?', 'reeid-translate' ),
            'a' => __( 'Wait for reset or reduce batch. Plugin will not auto-resume.', 'reeid-translate' ),
        ),
    );

    /* Column 2 FAQ items */
    $faqs_col2 = array(
        array(
            'q' => __( 'Which OpenAI model is used?', 'reeid-translate' ),
            'a' => __( 'REEID selects the most optimal model (currently gpt-4o).', 'reeid-translate' ),
        ),
        array(
            'q' => __( 'Can I change tone or add custom instructions?', 'reeid-translate' ),
            'a' => __( 'Yes. Select tone and optionally add custom instructions in Settings.', 'reeid-translate' ),
        ),
        array(
            'q' => __( 'How do I validate my API key?', 'reeid-translate' ),
            'a' => __( 'Enter your key and click Validate. Status appears inline.', 'reeid-translate' ),
        ),
        array(
            'q' => __( 'What is the license key for?', 'reeid-translate' ),
            'a' => __( 'Unlocks PRO features like bulk translation and more languages.', 'reeid-translate' ),
        ),
        array(
            'q' => __( 'What is Map Repair?', 'reeid-translate' ),
            'a' => __( 'Fixes missing relationships among translated posts.', 'reeid-translate' ),
        ),
        array(
            'q' => __( 'Can I re-translate or update a translation?', 'reeid-translate' ),
            'a' => __( 'Yes. Re-running Translate updates the existing post safely.', 'reeid-translate' ),
        ),
        array(
            'q' => __( 'What if a slug already exists?', 'reeid-translate' ),
            'a' => __( 'WordPress makes slugs unique automatically.', 'reeid-translate' ),
        ),
        array(
            'q' => __( 'Where are translations stored?', 'reeid-translate' ),
            'a' => __( 'Each translation is a normal WP post linked via metadata.', 'reeid-translate' ),
        ),
        array(
            'q' => __( 'Troubleshooting tips?', 'reeid-translate' ),
            'a' => __( 'Check API key, run Map Repair, enable debug log if needed.', 'reeid-translate' ),
        ),
    );
    ?>
    <div class="reeid-faq-tab">
        <h2><?php esc_html_e( 'Frequently Asked Questions', 'reeid-translate' ); ?></h2>

        <div class="reeid-faq-cols">
            <div class="reeid-faq-col">
                <?php foreach ( $faqs_col1 as $i => $item ) :
                    $id = 'faq-l-' . $i;
                    ?>
                    <div class="reeid-faq-item">
                        <button class="reeid-faq-q"
                            aria-expanded="false"
                            aria-controls="<?php echo esc_attr( $id ); ?>">
                            <?php echo esc_html( $item['q'] ); ?>
                            <span class="reeid-faq-chevron">&#9662;</span>
                        </button>

                        <div id="<?php echo esc_attr( $id ); ?>"
                            class="reeid-faq-a" hidden>
                            <?php echo esc_html( $item['a'] ); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="reeid-faq-col">
                <?php foreach ( $faqs_col2 as $i => $item ) :
                    $id = 'faq-r-' . $i;
                    ?>
                    <div class="reeid-faq-item">
                        <button class="reeid-faq-q"
                            aria-expanded="false"
                            aria-controls="<?php echo esc_attr( $id ); ?>">
                            <?php echo esc_html( $item['q'] ); ?>
                            <span class="reeid-faq-chevron">&#9662;</span>
                        </button>

                        <div id="<?php echo esc_attr( $id ); ?>"
                            class="reeid-faq-a" hidden>
                            <?php echo esc_html( $item['a'] ); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php
}


/* =============================================================================
   INFO TAB RENDERER
   ============================================================================= */
function reeid_render_info_tab() {

    if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
        echo '<div class="notice notice-error"><p>' .
            esc_html__( 'Unauthorized.', 'reeid-translate' ) .
        '</p></div>';
        return;
    }

    $url = 'https://reeid.com/';
    ?>
    <div class="reeid-info-tab">
        <h2><?php esc_html_e( 'About REEID Translation ', 'reeid-translate' ); ?></h2>

        <p><?php esc_html_e(
            'REEID Translation is a next-generation plugin for multilingual content, supporting Elementor layouts, Gutenberg blocks, posts, pages, and WooCommerce.',
            'reeid-translate'
        ); ?></p>

        <hr>

        <ul>
            <li><?php esc_html_e( 'Supports 25+ languages (Free supports 10)', 'reeid-translate' ); ?></li>
            <li><?php esc_html_e( 'One-click translation for posts/pages', 'reeid-translate' ); ?></li>
            <li><?php esc_html_e( 'Bulk translation with automatic mapping (PRO)', 'reeid-translate' ); ?></li>
            <li><?php esc_html_e( 'Elementor / Gutenberg / Classic Editor support', 'reeid-translate' ); ?></li>
            <li><?php esc_html_e( 'Customizable frontend language switcher shortcode', 'reeid-translate' ); ?></li>
        </ul>

        <p>
            <?php esc_html_e( 'For documentation and support, visit:', 'reeid-translate' ); ?>
            <a href="<?php echo esc_url( $url ); ?>"
               target="_blank"
               rel="noopener noreferrer"><?php echo esc_html( $url ); ?></a>
        </p>
    </div>
    <?php
}


/* =============================================================================
   Render translation map as HTML
   ============================================================================= */
function reeid_render_translation_map_html( $map ) {

    if ( ! is_array( $map ) || ! count( $map ) ) {
        return '';
    }

    $out  = '<h4 style="margin-top:20px;">' .
            esc_html__( 'Current Map:', 'reeid-translate' ) .
            '</h4><ul>';

    foreach ( $map as $lang => $post_id ) {

        $post_id   = absint( $post_id );
        $title     = get_the_title( $post_id );
        $status    = get_post_status( $post_id );
        $edit_link = get_edit_post_link( $post_id );

        $out .= sprintf(
            '<li><b>%s</b> &#8594; <a href="%s" target="_blank" rel="noopener noreferrer">%s</a> <span style="color:#888;">(%s, %s)</span></li>',
            esc_html( strtoupper( (string) $lang ) ),
            esc_url( (string) $edit_link ),
            esc_html( (string) $title ),
            esc_html( (string) $post_id ),
            esc_html( (string) $status )
        );
    }

    $out .= '</ul>';
    return $out;
}


/* =============================================================================
   Force repair translation map for a given post ID
   ============================================================================= */
function reeid_force_repair_translation_map( $post_id ) {

    $post_id = absint( $post_id );
    if ( ! $post_id ) {
        return false;
    }

    $source_id    = $post_id;
    $default_lang = sanitize_text_field( (string) get_option( 'reeid_translation_source_lang', 'en' ) );
    $map          = array();

    $cache_key = 'reeid_children_' . $source_id;
    $children  = get_transient( $cache_key );

    if ( false === $children ) {

        // Query only IDs; avoid heavy caches.
        $q = new WP_Query(
            array(
                'post_type'           => array( 'post', 'page', 'product' ),
                'post_status'         => array( 'publish', 'draft', 'pending' ),
                'posts_per_page'      => -1,
                'fields'              => 'ids',
                'no_found_rows'       => true,
                'ignore_sticky_posts' => true,
                'orderby'             => 'none',
                'meta_query'          => array(
                    array(
                        'key'     => '_reeid_translation_source',
                        'value'   => $source_id,
                        'compare' => '=',
                        'type'    => 'NUMERIC',
                    ),
                ),
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'cache_results'          => false,
                'suppress_filters'       => false,
            )
        );

        $children = $q->posts;
        set_transient( $cache_key, $children, 300 );
    }

    foreach ( (array) $children as $child_id ) {

        $child_id  = absint( $child_id );
        $lang      = get_post_meta( $child_id, '_reeid_translation_lang', true );
        $lang      = sanitize_text_field( (string) $lang );

        if ( $lang && 'publish' === get_post_status( $child_id ) ) {
            $map[ $lang ] = $child_id;
        }
    }

    // include source
    if ( 'publish' === get_post_status( $source_id ) ) {
        $map[ $default_lang ] = $source_id;
    }

    update_post_meta( $source_id, '_reeid_translation_map', $map );

    return $map;
}



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
                'faq'      => __('FAQ', 'reeid-translate'),
                'info'     => __('INFO', 'reeid-translate'),
            );

            if (defined('REEID_SHOW_JOBS_TAB') && REEID_SHOW_JOBS_TAB) {
                $tabs['jobs'] = 'Jobs';
            }

            // Block direct access to disabled Jobs tab
if (
	isset( $_GET['tab'], $_GET['_wpnonce'] )
	&& 'jobs' === sanitize_key( wp_unslash( $_GET['tab'] ) )
	&& wp_verify_nonce(
		sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ),
		'reeid_settings_tab'
	)
	&& ! ( defined( 'REEID_SHOW_JOBS_TAB' ) && REEID_SHOW_JOBS_TAB )
) {
	wp_die( esc_html__( 'This view is disabled.', 'reeid-translate' ) );
}


foreach ( $tabs as $slug => $label ) {
	$url = add_query_arg(
		array(
			'page' => 'reeid-translate-settings',
			'tab'  => $slug,
		),
		$base
	);
	$cls = 'nav-tab' . ( $active_tab === $slug ? ' nav-tab-active' : '' );
	echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</a>';
}
?>
</h2>

<div class="reeid-tab-content">
	<?php
	switch ( $active_tab ) {
		case 'tools':
			reeid_render_tools_tab();
			break;
		case 'jobs':
			reeid_render_jobs_tab();
			break;
		case 'faq':
			reeid_render_faq_tab();
			break;
		case 'info':
			reeid_render_info_tab();
			break;
		case 'stat':
			if ( function_exists( 'reeid_render_stat_tab' ) ) {
				reeid_render_stat_tab();
			} else {
				echo '<p>' . esc_html__( 'Stat tab not available.', 'reeid-translate' ) . '</p>';
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
function reeid_render_settings_tab() {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) || ! function_exists( 'settings_fields' ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Unauthorized.', 'reeid-translate' ) . '</p></div>';
		return;
	}
	?>
	<form method="post" action="options.php">
		<?php
		settings_fields( 'reeid_translate_settings' );
		do_settings_sections( 'reeid-translate-settings' );
		submit_button();
		?>
	</form>
	<?php
}

function reeid_render_tools_tab() {

	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Unauthorized.', 'reeid-translate' ) . '</p></div>';
		return;
	}

	$msg        = '';
	$map_output = '';



    $method     = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
$tools_post = ( 'POST' === strtoupper($method) && isset($_POST['reeid_tools_tab_nonce']) );

    $nonce_post        = filter_input(INPUT_POST, 'reeid_tools_tab_nonce', FILTER_DEFAULT);
    $nonce_unslashed   = $nonce_post ? wp_unslash($nonce_post) : '';
    $nonce             = sanitize_text_field($nonce_unslashed);
    $valid_tools_nonce = $nonce && wp_verify_nonce($nonce, 'reeid_tools_tab_action') && current_user_can('manage_options');

    if ($tools_post && $valid_tools_nonce) {
/* Save uninstall behavior */
$delete = isset($_POST['reeid_delete_data_on_uninstall']) ? '1' : '0';
update_option('reeid_delete_data_on_uninstall', $delete);



        /* Save uninstall behavior preference */
if (array_key_exists('reeid_delete_data_on_uninstall', $_POST)) {
    update_option('reeid_delete_data_on_uninstall', 1);
} else {
    update_option('reeid_delete_data_on_uninstall', 0);
}


        /* Save switcher appearance */
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

        /* Global Purge Cache */
        if (! empty($_POST['reeid_purge_all_cache'])) {
            global $wpdb;

            // 1. Delete all REEID transients.
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_reeid_ht_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_reeid_ht_%'");

            // 2. Delete WooCommerce caches.
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'reeid_woo_strings_%'");

            $msg = '<div style="color:green;font-weight:bold;">&#10004; '
                . esc_html__('All REEID translation caches have been purged.', 'reeid-translate')
                . '</div>';
        }

        /* Repair ALL maps */
        if (! empty($_POST['reeid_maprepair_all'])) {

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

        /* Repair SINGLE map */
        elseif (isset($_POST['reeid_maprepair_post_id'])) {

            $pid = absint(wp_unslash($_POST['reeid_maprepair_post_id']));
            $post = $pid ? get_post($pid) : false;

            if ($pid && $post && in_array($post->post_type, array('post', 'page'), true)) {

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
                        . ' ' . esc_html((string)$pid) . '.</div>';

                } else {
                    $msg = '<div style="color:red;font-weight:bold;">'
                        . esc_html__('Failed to repair map for Post ID', 'reeid-translate')
                        . ' ' . esc_html((string)$pid) . '.</div>';
                }

            } else {
                $msg = '<div style="color:red;font-weight:bold;">'
                    . esc_html__('Please enter a valid Post/Page ID.', 'reeid-translate')
                    . '</div>';
            }
        }

    } elseif ($tools_post) {
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

<style>
/* REEID Tools Layout */
.reeid-tools-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 24px;
    align-items: start;
}

.reeid-tools-col {
    background: #fff;
    border: 1px solid #dcdcde;
    padding: 16px 18px;
    border-radius: 6px;
}

.reeid-tools-col h2 {
    margin-top: 0;
}

/* Stack on small screens */
@media (max-width: 1100px) {
    .reeid-tools-row {
        grid-template-columns: 1fr;
    }
}
</style>




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
                    onclick="return confirm('<?php echo esc_js(__('Are you sure? This will scan and repair all translation groups site-wide.', 'reeid-translate')); ?>')">
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
        <div class="reeid-tools-col">
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


        <!-- Uninstall Behavior -->
<div class="reeid-tools-col" style="margin-top:30px;">
    <h2><?php esc_html_e('Uninstall Behavior', 'reeid-translate'); ?></h2>

    <form method="post" action="<?php echo esc_url($tools_action_url); ?>">
        <?php wp_nonce_field('reeid_tools_tab_action', 'reeid_tools_tab_nonce'); ?>

        <?php $delete_data = (bool) get_option('reeid_delete_data_on_uninstall', false); ?>

        <label style="display:flex; gap:10px; align-items:flex-start; max-width:520px;">
            <input type="checkbox"
                   name="reeid_delete_data_on_uninstall"
                   value="1"
                   <?php checked($delete_data); ?> />

            <span>
                <strong><?php esc_html_e('Delete all REEID data when plugin is uninstalled', 'reeid-translate'); ?></strong><br>
                <span style="color:#b32d2e;font-size:12px;">
                    <?php esc_html_e(
                        'This will permanently remove plugin settings, license data, caches, and translation metadata. This action cannot be undone.',
                        'reeid-translate'
                    ); ?>
                </span>
            </span>
        </label>

        <p style="margin-top:12px;">
            <button type="submit" class="button button-primary">
                <?php esc_html_e('Save Uninstall Preference', 'reeid-translate'); ?>
            </button>
        </p>
    </form>
</div>


    </div>



<?php
}
