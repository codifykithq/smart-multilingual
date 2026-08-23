<?php
defined( 'ABSPATH' ) || exit;

final class SML_Admin {
	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'add_meta_boxes', array( $this, 'meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_language_meta' ) );
		add_action( 'admin_action_sml_create_translation', array( $this, 'create_translation' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_action_sml_clone_menu', array( $this, 'clone_menu' ) );
		add_action( 'admin_post_sml_save_attribute_translations', array( $this, 'save_attribute_translations' ) );
		add_filter( 'woocommerce_attribute_label', array( $this, 'translate_attribute_label' ), 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( SML_FILE ), array( $this, 'plugin_action_links' ) );
		/*
		 * The pre-1.0 relationship repair scanned every translated post and every
		 * taxonomy in one request. On a WooCommerce site this could exceed the PHP
		 * execution limit immediately after activation. Existing relationships are
		 * already non-destructive, so repairs are now explicit rather than automatic.
		 */
	}

	public function plugin_action_links( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=smart-multilingual-settings' ) ) . '">' . esc_html__( 'Settings', 'smart-multilingual' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	public function admin_menu() {
		add_menu_page(
			__( 'Smart Multilingual', 'smart-multilingual' ),
			__( 'Languages', 'smart-multilingual' ),
			'manage_options',
			'smart-multilingual',
			array( $this, 'dashboard_page' ),
			'dashicons-translation',
			58
		);
		add_submenu_page( 'smart-multilingual', __( 'Settings', 'smart-multilingual' ), __( 'Settings', 'smart-multilingual' ), 'manage_options', 'smart-multilingual-settings', array( $this, 'settings_page' ) );
		add_submenu_page( 'smart-multilingual', __( 'Menu Translation', 'smart-multilingual' ), __( 'Menus', 'smart-multilingual' ), 'manage_options', 'smart-multilingual-menus', array( $this, 'menus_page' ) );
		add_submenu_page( 'smart-multilingual', __( 'WooCommerce Attributes', 'smart-multilingual' ), __( 'Attributes', 'smart-multilingual' ), 'manage_woocommerce', 'smart-multilingual-attributes', array( $this, 'attributes_page' ) );
	}

	public function register_settings() {
		register_setting( 'sml_settings_group', SML_Plugin::OPTION_KEY, array( $this, 'sanitize_settings' ) );
		add_action( 'update_option_' . SML_Plugin::OPTION_KEY, array( $this, 'maybe_flush_rewrites' ), 10, 2 );
	}

	public function maybe_flush_rewrites( $old, $new ) {
		if ( ( $old['enabled_languages'] ?? array() ) !== ( $new['enabled_languages'] ?? array() ) ) {
			update_option( 'sml_rewrite_flush_required', 1, false );
		}
	}

	public function sanitize_settings( $input ) {
		$registry = SML_Languages::registry();
		$default   = SML_Languages::default_code();
		$enabled  = isset( $input['enabled_languages'] ) ? array_map( 'sanitize_key', (array) $input['enabled_languages'] ) : array_keys( $registry );
		$enabled  = array_values( array_intersect( array_keys( $registry ), $enabled ) );
		if ( ! in_array( $default, $enabled, true ) ) { array_unshift( $enabled, $default ); }
		$switcher = isset( $input['switcher_languages'] ) ? array_map( 'sanitize_key', (array) $input['switcher_languages'] ) : $enabled;
		$switcher = array_values( array_intersect( $enabled, $switcher ) );
		$menus = array();
		foreach ( $enabled as $code ) { if ( ! empty( $input['language_menus'][ $code ] ) ) $menus[ $code ] = absint( $input['language_menus'][ $code ] ); }
		return array(
			'default_language' => $default,
			'persian_prefix' => 'fa',
			'persian_menu' => ! empty( $menus['fa'] ) ? $menus['fa'] : 0, 'language_menus' => $menus,
			'enabled_languages' => $enabled, 'switcher_languages' => $switcher,
			'switcher_labels' => in_array( $input['switcher_labels'] ?? 'native', array( 'short', 'full', 'native' ), true ) ? $input['switcher_labels'] : 'native',
			'switcher_layout' => in_array( $input['switcher_layout'] ?? 'dropdown', array( 'dropdown', 'list' ), true ) ? $input['switcher_layout'] : 'dropdown',
			'switcher_missing_behavior' => in_array( $input['switcher_missing_behavior'] ?? 'home', array( 'home', 'path', 'hide' ), true ) ? $input['switcher_missing_behavior'] : 'home',
			'switcher_text_color' => $this->sanitize_css_color( $input['switcher_text_color'] ?? '#ffffff', '#ffffff' ),
			'switcher_bg_color' => $this->sanitize_css_color( $input['switcher_bg_color'] ?? 'transparent', 'transparent' ),
			'switcher_hover_text_color' => $this->sanitize_css_color( $input['switcher_hover_text_color'] ?? '#ffffff', '#ffffff' ),
			'switcher_hover_bg_color' => $this->sanitize_css_color( $input['switcher_hover_bg_color'] ?? 'rgba(255,255,255,.12)', 'rgba(255,255,255,.12)' ),
			'switcher_menu_text_color' => $this->sanitize_css_color( $input['switcher_menu_text_color'] ?? '#222222', '#222222' ),
			'switcher_menu_bg_color' => $this->sanitize_css_color( $input['switcher_menu_bg_color'] ?? '#ffffff', '#ffffff' ),
			'switcher_border_color' => $this->sanitize_css_color( $input['switcher_border_color'] ?? 'transparent', 'transparent' ),
			'switcher_radius' => min( 40, absint( $input['switcher_radius'] ?? 6 ) ),
			'use_persian_dates' => empty( $input['use_persian_dates'] ) ? 0 : 1,
			'hide_untranslated' => empty( $input['hide_untranslated'] ) ? 0 : 1, 'rtl_reverse_columns' => empty( $input['rtl_reverse_columns'] ) ? 0 : 1,
			'delete_data' => empty( $input['delete_data'] ) ? 0 : 1,
			'compatibility_profiles' => SML_Compatibility::sanitize( $input['compatibility_profiles'] ?? array() ),
			// Preserve typography for temporarily disabled languages as well.
			'typography_fonts' => SML_Typography::sanitize_fonts( $input['typography_fonts'] ?? array(), array_keys( $registry ) ),
			'typography_rules' => SML_Typography::sanitize_rules( $input['typography_rules'] ?? array(), array_keys( $registry ) ),
		);
	}


	private function sanitize_css_color( $value, $fallback ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( 'transparent' === strtolower( $value ) ) return 'transparent';
		if ( sanitize_hex_color( $value ) ) return $value;
		if ( preg_match( '/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)$/i', $value ) ) return $value;
		return $fallback;
	}

	public function admin_assets( $hook ) {
		if ( false === strpos( $hook, 'smart-multilingual' ) && ! in_array( $hook, array( 'post.php', 'post-new.php', 'edit.php', 'edit-tags.php', 'term.php', 'upload.php', 'media.php' ), true ) ) { return; }
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'sml-admin', SML_URL . 'assets/css/admin.css', array( 'wp-color-picker' ), SML_VERSION );
		wp_enqueue_script( 'sml-admin', SML_URL . 'assets/js/admin.js', array( 'jquery', 'wp-color-picker' ), SML_VERSION, true );
	}

	public function meta_boxes() {
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			if ( 'attachment' !== $post_type ) {
				add_meta_box( 'sml-language', __( 'Language & Translation', 'smart-multilingual' ), array( $this, 'language_box' ), $post_type, 'side', 'high' );
			}
		}
	}

	public function language_box( $post ) {
		wp_nonce_field( 'sml_save_language', 'sml_language_nonce' );
		$lang  = get_post_meta( $post->ID, SML_Plugin::META_LANG, true ) ?: SML_Languages::default_code();
		$group = get_post_meta( $post->ID, SML_Plugin::META_GROUP, true ) ?: wp_generate_uuid4();
		$links = SML_Plugin::get_group_translations( $group );
		?>
		<p><label for="sml-language-select"><strong><?php esc_html_e( 'Content language', 'smart-multilingual' ); ?></strong></label></p>
		<select id="sml-language-select" name="sml_language" class="widefat">
			<?php foreach ( SML_Languages::enabled() as $code ) : $cfg = SML_Languages::get( $code ); ?>
				<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $lang, $code ); ?>><?php echo esc_html( $cfg['native'] ); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="hidden" name="sml_group" value="<?php echo esc_attr( $group ); ?>">
		<div class="sml-translation-panel">
			<p><strong><?php esc_html_e( 'Translations', 'smart-multilingual' ); ?></strong></p>
			<?php foreach ( SML_Languages::enabled() as $code ) : $cfg = SML_Languages::get( $code ); ?>
				<div class="sml-translation-row">
					<span><b><?php echo esc_html( strtoupper( $code ) ); ?></b> <?php echo esc_html( $cfg['native'] ); ?></span>
					<?php if ( $code === $lang ) : ?><span class="sml-status-current"><?php esc_html_e( 'Current', 'smart-multilingual' ); ?></span>
					<?php elseif ( ! empty( $links[ $code ] ) ) : ?><a class="button button-small" href="<?php echo esc_url( get_edit_post_link( $links[ $code ] ) ); ?>"><?php esc_html_e( 'Edit', 'smart-multilingual' ); ?></a>
					<?php else : ?><a class="button button-small" href="<?php echo esc_url( $this->translation_action_url( $post->ID, $code ) ); ?>"><?php esc_html_e( 'Create', 'smart-multilingual' ); ?></a><?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	public function save_language_meta( $post_id ) {
		if ( empty( $_POST['sml_language_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sml_language_nonce'] ) ), 'sml_save_language' ) ) return;
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;
		$lang = isset( $_POST['sml_language'] ) ? sanitize_key( wp_unslash( $_POST['sml_language'] ) ) : SML_Languages::default_code();
		if ( ! SML_Languages::is_enabled( $lang ) ) $lang = SML_Languages::default_code();
		update_post_meta( $post_id, SML_Plugin::META_LANG, $lang );
		if ( ! empty( $_POST['sml_group'] ) ) update_post_meta( $post_id, SML_Plugin::META_GROUP, sanitize_text_field( wp_unslash( $_POST['sml_group'] ) ) );
	}

	private function translation_action_url( $post_id, $target_lang ) {
		return wp_nonce_url( admin_url( 'admin.php?action=sml_create_translation&post_id=' . absint( $post_id ) . '&target_lang=' . sanitize_key( $target_lang ) ), 'sml_create_translation_' . absint( $post_id ) . '_' . sanitize_key( $target_lang ) );
	}

	public function row_actions( $actions, $post ) {
		if ( current_user_can( 'edit_post', $post->ID ) ) {
			$group = get_post_meta( $post->ID, SML_Plugin::META_GROUP, true );
			$links = $group ? SML_Plugin::get_group_translations( $group ) : array();
			$actions['sml_translate'] = '<a href="' . esc_url( get_edit_post_link( $post->ID ) . '#sml-language' ) . '">' . sprintf( esc_html__( 'Translations %1$d/%2$d', 'smart-multilingual' ), count( $links ), count( SML_Languages::enabled() ) ) . '</a>';
		}
		return $actions;
	}

	public function create_translation() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$target  = isset( $_GET['target_lang'] ) ? sanitize_key( wp_unslash( $_GET['target_lang'] ) ) : '';
		check_admin_referer( 'sml_create_translation_' . $post_id . '_' . $target );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || ! SML_Languages::is_enabled( $target ) ) wp_die( esc_html__( 'Invalid translation request.', 'smart-multilingual' ) );
		$source = get_post( $post_id ); if ( ! $source ) wp_die( esc_html__( 'Source content was not found.', 'smart-multilingual' ) );
		$source_lang = get_post_meta( $post_id, SML_Plugin::META_LANG, true ) ?: SML_Languages::default_code();
		$group = get_post_meta( $post_id, SML_Plugin::META_GROUP, true ) ?: wp_generate_uuid4();
		update_post_meta( $post_id, SML_Plugin::META_GROUP, $group ); update_post_meta( $post_id, SML_Plugin::META_LANG, $source_lang );
		$existing = SML_Plugin::get_group_translations( $group ); if ( ! empty( $existing[ $target ] ) ) { wp_safe_redirect( get_edit_post_link( $existing[ $target ], 'raw' ) ); exit; }
		$default_id = ! empty( $existing[ SML_Languages::default_code() ] ) ? (int) $existing[ SML_Languages::default_code() ] : $post_id;
		$source_path = SML_Plugin::relative_url_path( get_permalink( $default_id ) ); $cfg = SML_Languages::get( $target );
		$new_id = wp_insert_post( wp_slash( array( 'post_type'=>$source->post_type,'post_status'=>'draft','post_title'=>$source->post_title.' — '.$cfg['native'],'post_name'=>$source->post_name,'post_content'=>$source->post_content,'post_excerpt'=>$source->post_excerpt,'post_parent'=>$this->translated_parent($source->post_parent,$target),'menu_order'=>$source->menu_order,'comment_status'=>$source->comment_status,'ping_status'=>$source->ping_status,'post_author'=>get_current_user_id() ) ), true );
		if ( is_wp_error( $new_id ) ) wp_die( esc_html( $new_id->get_error_message() ) );
		$this->copy_taxonomies( $post_id, $new_id, $source->post_type, $target ); $this->copy_post_meta( $post_id, $new_id ); $this->refresh_elementor_data( $new_id );
		update_post_meta( $new_id, SML_Plugin::META_GROUP, $group ); update_post_meta( $new_id, SML_Plugin::META_SOURCE_PATH, $source_path ); update_post_meta( $new_id, SML_Plugin::META_LANG, $target ); update_post_meta( $new_id, '_sml_source_modified', $source->post_modified_gmt );
		wp_safe_redirect( add_query_arg( array( 'sml_created'=>'1', 'sml_language'=>$target ), get_edit_post_link( $new_id, 'raw' ) ) ); exit;
	}

	private function translated_parent( $parent_id, $target_lang ) {
		if ( ! $parent_id ) return 0;
		$group = get_post_meta( $parent_id, SML_Plugin::META_GROUP, true ); $links = $group ? SML_Plugin::get_group_translations( $group ) : array();
		return ! empty( $links[ $target_lang ] ) ? (int) $links[ $target_lang ] : 0;
	}

	private function copy_taxonomies( $source_id, $target_id, $post_type, $target_lang = '' ) {
		$target_lang = $target_lang && SML_Languages::is_enabled( $target_lang ) ? $target_lang : SML_Languages::default_code();
		$taxonomy_service = SML_Taxonomy::instance();
		foreach ( get_object_taxonomies( $post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $source_id, $taxonomy );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			$target_terms = array();
			foreach ( $terms as $term ) {
				$target_terms[] = (int) $term->term_id;
				$group = get_term_meta( $term->term_id, SML_Taxonomy::META_GROUP, true );
				$links = $group ? $taxonomy_service->get_group_translations( $group, $taxonomy ) : array();
				if ( ! empty( $links[ $target_lang ] ) ) {
					$target_terms[] = (int) $links[ $target_lang ];
				}
			}
			wp_set_object_terms( $target_id, array_values( array_unique( $target_terms ) ), $taxonomy );
		}
	}


	public function maybe_sync_translated_term_relationships() {
		if ( '0.4.2' === get_option( 'sml_term_relationship_sync_version' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$default = SML_Languages::default_code();
		$posts = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => SML_Plugin::META_LANG,
				'meta_compare'   => '!=',
				'meta_value'     => $default,
			)
		);
		$taxonomy_service = SML_Taxonomy::instance();
		foreach ( $posts as $post_id ) {
			$post_type = get_post_type( $post_id );
			foreach ( get_object_taxonomies( $post_type ) as $taxonomy ) {
				$terms = wp_get_object_terms( $post_id, $taxonomy );
				if ( is_wp_error( $terms ) || empty( $terms ) ) {
					continue;
				}
				$term_ids = wp_list_pluck( $terms, 'term_id' );
				foreach ( $terms as $term ) {
					$group = get_term_meta( $term->term_id, SML_Taxonomy::META_GROUP, true );
					$links = $group ? $taxonomy_service->get_group_translations( $group, $taxonomy ) : array();
					foreach ( SML_Languages::enabled() as $lang ) {
						if ( ! empty( $links[ $lang ] ) ) {
							$term_ids[] = (int) $links[ $lang ];
						}
					}
				}
				wp_set_object_terms( $post_id, array_values( array_unique( array_map( 'intval', $term_ids ) ) ), $taxonomy );
			}
		}
		update_option( 'sml_term_relationship_sync_version', '0.4.2', false );
	}

	private function copy_post_meta( $source_id, $target_id ) {
		global $wpdb;

		$excluded = array(
			'_edit_lock',
			'_edit_last',
			'_elementor_css',
			SML_Plugin::META_LANG,
			SML_Plugin::META_GROUP,
			'_wp_old_slug',
		);

		/*
		 * Elementor stores the complete page structure as JSON in _elementor_data.
		 * Passing this value through add_post_meta()/update_post_meta() can alter
		 * escaping in HTML widgets, CSS strings and nested JSON. Copy the raw
		 * database values byte-for-byte instead, then clear the target meta cache.
		 */
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC",
				$source_id
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$key = (string) $row['meta_key'];
			if ( in_array( $key, $excluded, true ) ) {
				continue;
			}

			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $target_id,
					'meta_key'   => $key,
					'meta_value' => $row['meta_value'],
				),
				array( '%d', '%s', '%s' )
			);
		}

		wp_cache_delete( $target_id, 'post_meta' );
		clean_post_cache( $target_id );
	}

	private function refresh_elementor_data( $post_id ) {
		// Never save the Elementor document here: saving can normalize or overwrite
		// freshly cloned JSON. Only remove generated CSS and clear caches.
		delete_post_meta( $post_id, '_elementor_css' );

		if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		wp_cache_delete( $post_id, 'post_meta' );
		clean_post_cache( $post_id );
	}

	public function dashboard_page() {
		$public_types = get_post_types( array( 'public' => true ), 'names' );
		unset( $public_types['attachment'] );
		$language_totals = array(); foreach ( SML_Languages::enabled() as $code ) { $language_totals[ $code ] = $this->count_language( $code, $public_types ); }
		?>
		<div class="wrap sml-admin-wrap">
			<h1><?php esc_html_e( 'Smart Multilingual', 'smart-multilingual' ); ?></h1>
			<p><?php esc_html_e( 'Translation overview', 'smart-multilingual' ); ?></p><div class="sml-stats"><?php foreach ( $language_totals as $code=>$total ) : $cfg=SML_Languages::get($code); ?><div><strong><?php echo esc_html($total); ?></strong><span><?php echo esc_html($cfg['native']); ?></span></div><?php endforeach; ?></div>
			<div class="sml-card">
				<h2><?php esc_html_e( 'Quick start', 'smart-multilingual' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'Open a page or post in the source language.', 'smart-multilingual' ); ?></li>
					<li><?php esc_html_e( 'Create a translation in the target language.', 'smart-multilingual' ); ?></li>
					<li><?php esc_html_e( 'Translate the copied draft with Elementor or WordPress.', 'smart-multilingual' ); ?></li>
					<li><?php esc_html_e( 'Publish it and place [sml_language_switcher] in your header.', 'smart-multilingual' ); ?></li>
				</ol>
			</div>
		</div>
		<?php
	}

	private function count_language( $lang, $post_types ) {
		$default = SML_Languages::default_code();
		$query = new WP_Query(
			array(
				'post_type'      => array_values( $post_types ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1,
				'meta_query'     => $default === $lang
					? array(
						'relation' => 'OR',
						array( 'key' => SML_Plugin::META_LANG, 'compare' => 'NOT EXISTS' ),
						array( 'key' => SML_Plugin::META_LANG, 'value' => $default ),
					)
					: array( array( 'key' => SML_Plugin::META_LANG, 'value' => $lang ) ),
			)
		);
		return (int) $query->found_posts;
	}

	public function settings_page() {
		$settings = SML_Plugin::settings();
		$registry = SML_Languages::registry();
		$menus    = wp_get_nav_menus();
		$key      = SML_Plugin::OPTION_KEY;
		$default  = SML_Languages::default_code();
		?>
		<div class="wrap sml-admin-wrap sml-settings-shell">
			<header class="sml-settings-hero"><div><span class="sml-kicker">SMART MULTILINGUAL <?php echo esc_html( SML_VERSION ); ?></span><h1><?php esc_html_e( 'Language design system', 'smart-multilingual' ); ?></h1><p><?php esc_html_e( 'Core generates language metadata only. Typography and compatibility behavior are opt-in.', 'smart-multilingual' ); ?></p></div><span class="sml-health-chip"><i></i><?php esc_html_e( 'Theme-neutral core', 'smart-multilingual' ); ?></span></header>
			<nav class="sml-settings-tabs" aria-label="Settings sections">
				<button type="button" class="is-active" data-sml-settings-tab="general"><span class="dashicons dashicons-admin-site-alt3"></span><?php esc_html_e( 'General', 'smart-multilingual' ); ?></button>
				<button type="button" data-sml-settings-tab="typography"><span class="dashicons dashicons-editor-textcolor"></span><?php esc_html_e( 'Typography', 'smart-multilingual' ); ?></button>
				<button type="button" data-sml-settings-tab="appearance"><span class="dashicons dashicons-art"></span><?php esc_html_e( 'Switcher & RTL', 'smart-multilingual' ); ?></button>
				<button type="button" data-sml-settings-tab="compatibility"><span class="dashicons dashicons-admin-plugins"></span><?php esc_html_e( 'Compatibility', 'smart-multilingual' ); ?></button>
			</nav>
			<form action="options.php" method="post" class="sml-settings-form"><?php settings_fields( 'sml_settings_group' ); ?>
				<section class="sml-settings-panel is-active" data-sml-settings-panel="general">
					<div class="sml-card sml-accent-blue"><div class="sml-card-heading"><div><h2><?php esc_html_e( 'Website languages', 'smart-multilingual' ); ?></h2><p><?php esc_html_e( 'The language with an empty URL prefix is the source language.', 'smart-multilingual' ); ?></p></div><span class="sml-info-badge">URL</span></div><div class="sml-language-grid">
					<?php foreach ( $registry as $code => $cfg ) : ?><label class="sml-language-tile"><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[enabled_languages][]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, (array) $settings['enabled_languages'], true ) ); ?> <?php disabled( $default === $code ); ?>><span class="sml-code-badge"><?php echo esc_html( strtoupper( $code ) ); ?></span><span><strong><?php echo esc_html( $cfg['native'] ); ?></strong><small><?php echo esc_html( $cfg['name'] ); ?></small></span><code><?php echo $default === $code ? '/' : '/' . esc_html( $cfg['prefix'] ) . '/'; ?></code></label><?php endforeach; ?>
					</div></div>
					<div class="sml-card sml-accent-green"><div class="sml-card-heading"><div><h2><?php esc_html_e( 'Menus by language', 'smart-multilingual' ); ?></h2><p><?php esc_html_e( 'Leave a language on Theme menu when the active theme should decide.', 'smart-multilingual' ); ?></p></div><span class="sml-info-badge is-green">NAV</span></div><div class="sml-field-grid">
					<?php foreach ( $registry as $code => $cfg ) : ?><label><span><?php echo esc_html( $cfg['native'] ); ?></span><select name="<?php echo esc_attr( $key ); ?>[language_menus][<?php echo esc_attr( $code ); ?>]"><option value="0"><?php esc_html_e( 'Use theme menu', 'smart-multilingual' ); ?></option><?php foreach ( $menus as $menu ) : ?><option value="<?php echo esc_attr( $menu->term_id ); ?>" <?php selected( absint( $settings['language_menus'][ $code ] ?? 0 ), $menu->term_id ); ?>><?php echo esc_html( $menu->name ); ?></option><?php endforeach; ?></select></label><?php endforeach; ?>
					</div></div>
				</section>

				<section class="sml-settings-panel" data-sml-settings-panel="compatibility">
					<div class="sml-card sml-accent-blue"><div class="sml-card-heading"><div><h2><?php esc_html_e( 'Compatibility Profiles', 'smart-multilingual' ); ?></h2><p><?php esc_html_e( 'Enable only the adapters required by this website. Fresh installations keep every layout adapter disabled.', 'smart-multilingual' ); ?></p></div><span class="sml-info-badge">OPT-IN</span></div>
					<div class="sml-profile-grid"><?php foreach ( SML_Compatibility::profiles() as $slug => $profile ) : ?><label class="sml-profile-card is-<?php echo esc_attr( $profile['color'] ); ?>"><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[compatibility_profiles][<?php echo esc_attr( $slug ); ?>]" value="1" <?php checked( ! empty( $settings['compatibility_profiles'][ $slug ] ) ); ?>><span><strong><?php echo esc_html( $profile['label'] ); ?></strong><small><?php echo esc_html( $profile['description'] ); ?></small></span></label><?php endforeach; ?></div></div>
				</section>

				<section class="sml-settings-panel" data-sml-settings-panel="typography">
					<div class="sml-card sml-accent-violet"><div class="sml-card-heading"><div><h2><?php esc_html_e( 'Per-language Typography Studio', 'smart-multilingual' ); ?></h2><p><?php esc_html_e( 'Register existing Elementor families, variable fonts, or multiple static files. Disabled targets emit no CSS.', 'smart-multilingual' ); ?></p></div><span class="sml-info-badge is-violet">OPT-IN</span></div>
						<div class="sml-language-tabs"><?php $first = true; foreach ( $registry as $code => $cfg ) : ?><button type="button" class="<?php echo $first ? 'is-active' : ''; ?>" data-sml-type-language="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( strtoupper( $code ) . ' · ' . $cfg['native'] ); ?></button><?php $first = false; endforeach; ?></div>
						<?php $first = true; foreach ( $registry as $code => $cfg ) : $this->render_typography_language( $code, $cfg, $settings, $first ); $first = false; endforeach; ?>
					</div>
				</section>

				<section class="sml-settings-panel" data-sml-settings-panel="appearance">
					<div class="sml-card sml-accent-green"><div class="sml-card-heading"><div><h2><?php esc_html_e( 'Language switcher', 'smart-multilingual' ); ?></h2><p><?php esc_html_e( 'Choose visible languages, labels, behavior and visual appearance.', 'smart-multilingual' ); ?></p></div><code>[sml_language_switcher]</code></div><div class="sml-language-grid">
					<?php foreach ( $registry as $code => $cfg ) : ?><label class="sml-language-tile"><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[switcher_languages][]" value="<?php echo esc_attr( $code ); ?>" <?php checked( in_array( $code, (array) $settings['switcher_languages'], true ) ); ?>><span class="sml-code-badge"><?php echo esc_html( strtoupper( $code ) ); ?></span><strong><?php echo esc_html( $cfg['native'] ); ?></strong></label><?php endforeach; ?></div><div class="sml-field-grid sml-top-space">
					<label><span><?php esc_html_e( 'Layout', 'smart-multilingual' ); ?></span><select name="<?php echo esc_attr( $key ); ?>[switcher_layout]"><option value="dropdown" <?php selected( $settings['switcher_layout'], 'dropdown' ); ?>>Dropdown</option><option value="list" <?php selected( $settings['switcher_layout'], 'list' ); ?>>Inline list</option></select></label>
					<label><span><?php esc_html_e( 'Labels', 'smart-multilingual' ); ?></span><select name="<?php echo esc_attr( $key ); ?>[switcher_labels]"><option value="native" <?php selected( $settings['switcher_labels'], 'native' ); ?>>Native names</option><option value="short" <?php selected( $settings['switcher_labels'], 'short' ); ?>>EN / FA / TR</option><option value="full" <?php selected( $settings['switcher_labels'], 'full' ); ?>>Full names</option></select></label>
					<label><span><?php esc_html_e( 'Missing translation', 'smart-multilingual' ); ?></span><select name="<?php echo esc_attr( $key ); ?>[switcher_missing_behavior]"><option value="home" <?php selected( $settings['switcher_missing_behavior'], 'home' ); ?>>Language homepage</option><option value="path" <?php selected( $settings['switcher_missing_behavior'], 'path' ); ?>>Same path</option><option value="hide" <?php selected( $settings['switcher_missing_behavior'], 'hide' ); ?>>Hide language</option></select></label>
					</div><?php $this->render_switcher_colors( $settings ); ?></div>
					<div class="sml-card sml-accent-red"><div class="sml-card-heading"><div><h2><?php esc_html_e( 'RTL behavior', 'smart-multilingual' ); ?></h2><p><?php esc_html_e( 'Layout controls are independent from typography.', 'smart-multilingual' ); ?></p></div><span class="sml-info-badge is-red">RTL</span></div><label class="sml-toggle-row"><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[rtl_reverse_columns]" value="1" <?php checked( ! empty( $settings['rtl_reverse_columns'] ) ); ?>><span><?php esc_html_e( 'Reverse horizontal Elementor rows for RTL languages', 'smart-multilingual' ); ?></span></label><label class="sml-toggle-row"><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[use_persian_dates]" value="1" <?php checked( ! empty( $settings['use_persian_dates'] ) ); ?>><span><?php esc_html_e( 'Use Parsi Date output on Persian pages when available', 'smart-multilingual' ); ?></span></label></div>
				</section>
				<div class="sml-save-bar"><span><i></i><?php esc_html_e( 'Only enabled typography targets affect the frontend.', 'smart-multilingual' ); ?></span><?php submit_button( __( 'Save design system', 'smart-multilingual' ), 'primary', 'submit', false ); ?></div>
			</form>
		</div>
		<?php
	}

	private function render_typography_language( $code, $cfg, $settings, $active ) {
		$fonts = isset( $settings['typography_fonts'][ $code ] ) ? (array) $settings['typography_fonts'][ $code ] : array();
		$rules = isset( $settings['typography_rules'][ $code ] ) ? (array) $settings['typography_rules'][ $code ] : array();
		if ( empty( $fonts ) ) $fonts[] = array( 'family'=>'', 'type'=>'existing', 'url'=>'', 'weight_min'=>400, 'weight_max'=>900, 'style'=>'normal', 'display'=>'swap' );
		?>
		<div class="sml-type-language-panel <?php echo $active ? 'is-active' : ''; ?>" data-sml-type-language-panel="<?php echo esc_attr( $code ); ?>">
			<div class="sml-type-section-head"><div><h3><?php echo esc_html( $cfg['native'] ); ?> — <?php esc_html_e( 'Font library', 'smart-multilingual' ); ?></h3><p><?php esc_html_e( 'Use Existing for an Elementor/system font. Add one Variable file or one Static row per weight.', 'smart-multilingual' ); ?></p></div><button type="button" class="button sml-add-font-source" data-language="<?php echo esc_attr( $code ); ?>">+ <?php esc_html_e( 'Add font source', 'smart-multilingual' ); ?></button></div>
			<div class="sml-font-rows" data-sml-font-rows="<?php echo esc_attr( $code ); ?>" data-next-index="<?php echo esc_attr( count( $fonts ) ); ?>"><?php foreach ( $fonts as $index => $font ) $this->render_font_source_row( $code, $index, $font ); ?></div>
			<datalist id="sml-font-families-<?php echo esc_attr( $code ); ?>"><?php foreach ( $fonts as $font ) : if ( empty( $font['family'] ) ) continue; ?><option value="<?php echo esc_attr( $font['family'] ); ?>"><?php endforeach; ?></datalist>
			<script type="text/html" id="tmpl-sml-font-source-<?php echo esc_attr( $code ); ?>"><?php $this->render_font_source_row( $code, '__INDEX__', array( 'family'=>'', 'type'=>'existing', 'url'=>'', 'weight_min'=>400, 'weight_max'=>900, 'style'=>'normal', 'display'=>'swap' ) ); ?></script>
			<div class="sml-type-section-head sml-rules-head"><div><h3><?php esc_html_e( 'Typography targets', 'smart-multilingual' ); ?></h3><p><?php esc_html_e( 'Enable only the areas that should override Elementor or the theme.', 'smart-multilingual' ); ?></p></div></div>
			<div class="sml-type-rules"><?php foreach ( SML_Typography::targets() as $target => $target_cfg ) $this->render_typography_rule( $code, $target, $target_cfg, $rules[ $target ] ?? array() ); ?></div>
		</div><?php
	}

	private function render_font_source_row( $code, $index, $font ) {
		$name = SML_Plugin::OPTION_KEY . '[typography_fonts][' . $code . '][' . $index . ']';
		$type = $font['type'] ?? 'existing'; ?>
		<div class="sml-font-source-row" data-font-type="<?php echo esc_attr( $type ); ?>"><div class="sml-font-source-main"><label><span><?php esc_html_e( 'Family', 'smart-multilingual' ); ?></span><input type="text" name="<?php echo esc_attr( $name ); ?>[family]" value="<?php echo esc_attr( $font['family'] ?? '' ); ?>" placeholder="Peyda"></label><label><span><?php esc_html_e( 'Source', 'smart-multilingual' ); ?></span><select class="sml-font-source-type" name="<?php echo esc_attr( $name ); ?>[type]"><option value="existing" <?php selected( $type, 'existing' ); ?>>Elementor / existing</option><option value="variable" <?php selected( $type, 'variable' ); ?>>Variable font</option><option value="static" <?php selected( $type, 'static' ); ?>>Static weight</option></select></label><label class="sml-font-weight-min"><span><?php esc_html_e( 'Min / weight', 'smart-multilingual' ); ?></span><input type="number" min="1" max="1000" name="<?php echo esc_attr( $name ); ?>[weight_min]" value="<?php echo esc_attr( $font['weight_min'] ?? 400 ); ?>"></label><label class="sml-font-weight-max"><span><?php esc_html_e( 'Max weight', 'smart-multilingual' ); ?></span><input type="number" min="1" max="1000" name="<?php echo esc_attr( $name ); ?>[weight_max]" value="<?php echo esc_attr( $font['weight_max'] ?? 900 ); ?>"></label></div><div class="sml-font-file-fields"><label class="sml-font-url-wrap"><span><?php esc_html_e( 'WOFF / WOFF2 URL', 'smart-multilingual' ); ?></span><span class="sml-input-action"><input class="sml-font-url" type="url" name="<?php echo esc_attr( $name ); ?>[url]" value="<?php echo esc_url( $font['url'] ?? '' ); ?>"><button type="button" class="button sml-font-upload"><?php esc_html_e( 'Choose file', 'smart-multilingual' ); ?></button></span></label><label><span><?php esc_html_e( 'Style', 'smart-multilingual' ); ?></span><select name="<?php echo esc_attr( $name ); ?>[style]"><?php foreach ( array('normal','italic','oblique') as $value ) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($font['style'] ?? 'normal',$value); ?>><?php echo esc_html(ucfirst($value)); ?></option><?php endforeach; ?></select></label><label><span>font-display</span><select name="<?php echo esc_attr( $name ); ?>[display]"><?php foreach ( array('swap','optional','fallback','block','auto') as $value ) : ?><option value="<?php echo esc_attr($value); ?>" <?php selected($font['display'] ?? 'swap',$value); ?>><?php echo esc_html($value); ?></option><?php endforeach; ?></select></label></div><button type="button" class="sml-icon-button sml-remove-font-source" aria-label="Remove"><span class="dashicons dashicons-trash"></span></button></div><?php
	}

	private function render_typography_rule( $code, $target, $cfg, $rule ) {
		$name = SML_Plugin::OPTION_KEY . '[typography_rules][' . $code . '][' . $target . ']'; ?>
		<details class="sml-type-rule <?php echo ! empty($rule['enabled']) ? 'is-enabled' : ''; ?>"><summary><label class="sml-switch"><input class="sml-rule-enabled" type="checkbox" name="<?php echo esc_attr($name); ?>[enabled]" value="1" <?php checked(!empty($rule['enabled'])); ?>><span></span></label><strong><?php echo esc_html($cfg['label']); ?></strong><code>.sml-type-<?php echo esc_html($target); ?></code><span class="dashicons dashicons-arrow-down-alt2"></span></summary><div class="sml-rule-grid">
		<label><span><?php esc_html_e('Font family','smart-multilingual'); ?></span><input type="text" list="sml-font-families-<?php echo esc_attr($code); ?>" name="<?php echo esc_attr($name); ?>[family]" value="<?php echo esc_attr($rule['family'] ?? ''); ?>" placeholder="inherit"></label><label><span><?php esc_html_e('Fallback','smart-multilingual'); ?></span><select name="<?php echo esc_attr($name); ?>[fallback]"><?php foreach(array('sans-serif','serif','system-ui','monospace','cursive') as $value): ?><option value="<?php echo esc_attr($value); ?>" <?php selected($rule['fallback'] ?? 'sans-serif',$value); ?>><?php echo esc_html($value); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e('Weight','smart-multilingual'); ?></span><input type="number" min="1" max="1000" name="<?php echo esc_attr($name); ?>[weight]" value="<?php echo esc_attr($rule['weight'] ?? ''); ?>" placeholder="inherit"></label>
		<label><span><?php esc_html_e('Desktop size','smart-multilingual'); ?></span><input type="text" name="<?php echo esc_attr($name); ?>[size_desktop]" value="<?php echo esc_attr($rule['size_desktop'] ?? ''); ?>" placeholder="2rem"></label><label><span><?php esc_html_e('Tablet size','smart-multilingual'); ?></span><input type="text" name="<?php echo esc_attr($name); ?>[size_tablet]" value="<?php echo esc_attr($rule['size_tablet'] ?? ''); ?>" placeholder="1.8rem"></label><label><span><?php esc_html_e('Mobile size','smart-multilingual'); ?></span><input type="text" name="<?php echo esc_attr($name); ?>[size_mobile]" value="<?php echo esc_attr($rule['size_mobile'] ?? ''); ?>" placeholder="1.5rem"></label>
		<label><span><?php esc_html_e('Line height','smart-multilingual'); ?></span><input type="text" name="<?php echo esc_attr($name); ?>[line_height]" value="<?php echo esc_attr($rule['line_height'] ?? ''); ?>" placeholder="1.7"></label><label><span><?php esc_html_e('Letter spacing','smart-multilingual'); ?></span><input type="text" name="<?php echo esc_attr($name); ?>[letter_spacing]" value="<?php echo esc_attr($rule['letter_spacing'] ?? ''); ?>" placeholder="0px"></label><label><span><?php esc_html_e('Word spacing','smart-multilingual'); ?></span><input type="text" name="<?php echo esc_attr($name); ?>[word_spacing]" value="<?php echo esc_attr($rule['word_spacing'] ?? ''); ?>" placeholder="0px"></label>
		<label><span><?php esc_html_e('Style','smart-multilingual'); ?></span><select name="<?php echo esc_attr($name); ?>[style]"><?php foreach(array(''=>'Inherit','normal'=>'Normal','italic'=>'Italic','oblique'=>'Oblique') as $value=>$label): ?><option value="<?php echo esc_attr($value); ?>" <?php selected($rule['style'] ?? '',$value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e('Transform','smart-multilingual'); ?></span><select name="<?php echo esc_attr($name); ?>[transform]"><?php foreach(array(''=>'Inherit','none'=>'None','uppercase'=>'Uppercase','lowercase'=>'Lowercase','capitalize'=>'Capitalize') as $value=>$label): ?><option value="<?php echo esc_attr($value); ?>" <?php selected($rule['transform'] ?? '',$value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e('Decoration','smart-multilingual'); ?></span><select name="<?php echo esc_attr($name); ?>[decoration]"><?php foreach(array(''=>'Inherit','none'=>'None','underline'=>'Underline','line-through'=>'Line through','overline'=>'Overline') as $value=>$label): ?><option value="<?php echo esc_attr($value); ?>" <?php selected($rule['decoration'] ?? '',$value); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label></div></details><?php
	}

	private function render_switcher_colors( $settings ) {
		$key = SML_Plugin::OPTION_KEY; $colors = array('switcher_text_color'=>'Text','switcher_bg_color'=>'Background','switcher_hover_text_color'=>'Hover text','switcher_hover_bg_color'=>'Hover background','switcher_menu_text_color'=>'Dropdown text','switcher_menu_bg_color'=>'Dropdown background','switcher_border_color'=>'Border'); ?>
		<h3 class="sml-subheading"><?php esc_html_e('Visual style','smart-multilingual'); ?></h3><div class="sml-color-grid"><?php foreach($colors as $setting=>$label): ?><label><span><?php echo esc_html($label); ?></span><span class="sml-color-control"><input type="text" class="sml-color-text" name="<?php echo esc_attr($key); ?>[<?php echo esc_attr($setting); ?>]" value="<?php echo esc_attr($settings[$setting]); ?>" data-default-color="<?php echo esc_attr($settings[$setting]); ?>"></span></label><?php endforeach; ?><label><span><?php esc_html_e('Border radius','smart-multilingual'); ?></span><input type="number" min="0" max="40" name="<?php echo esc_attr($key); ?>[switcher_radius]" value="<?php echo esc_attr($settings['switcher_radius']); ?>"></label></div><?php
	}

	public function admin_notices() {
		if ( isset( $_GET['sml_created'] ) ) { $code = isset($_GET['sml_language']) ? sanitize_key(wp_unslash($_GET['sml_language'])) : ''; $cfg=SML_Languages::get($code); echo '<div class="notice notice-success is-dismissible"><p>'.esc_html(sprintf(__('%s translation draft created.','smart-multilingual'),$cfg['native'])).'</p></div>'; }
		if ( isset( $_GET['sml_rewrites_refreshed'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Language permalinks refreshed.', 'smart-multilingual' ) . '</p></div>';
		}
		if ( get_option( 'sml_rewrite_flush_required', 0 ) && current_user_can( 'manage_options' ) ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=sml_refresh_rewrites' ), 'sml_refresh_rewrites' );
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Smart Multilingual permalink refresh required.', 'smart-multilingual' ) . '</strong> ' . esc_html__( 'Run it once after activation or after changing enabled languages.', 'smart-multilingual' ) . ' <a class="button button-secondary" href="' . esc_url( $url ) . '">' . esc_html__( 'Refresh language permalinks', 'smart-multilingual' ) . '</a></p></div>';
		}
	}

	public function attributes_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage attribute translations.', 'smart-multilingual' ) );
		}
		$attributes   = function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array();
		$translations = get_option( 'sml_attribute_labels', array() );
		$default      = SML_Languages::default_code();
		$targets      = array_values( array_diff( SML_Languages::enabled(), array( $default ) ) );
		// 0.9.x stored Persian labels as a flat array. Interpret it without
		// rewriting the option until the administrator saves this screen.
		if ( $translations && ! isset( $translations['fa'] ) && ! array_filter( array_keys( $translations ), array( 'SML_Languages', 'is_enabled' ) ) ) {
			$translations = array( 'fa' => $translations );
		}
		?>
		<div class="wrap sml-admin-wrap">
			<h1><?php esc_html_e( 'WooCommerce Attribute Translation', 'smart-multilingual' ); ?></h1>
			<div class="sml-card">
				<p><?php esc_html_e( 'Translate attribute labels here. Translate attribute values from the taxonomy terms screen.', 'smart-multilingual' ); ?></p>
				<?php if ( empty( $attributes ) ) : ?>
					<p><?php esc_html_e( 'No WooCommerce attributes were found.', 'smart-multilingual' ); ?></p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="sml_save_attribute_translations">
						<?php wp_nonce_field( 'sml_save_attribute_translations' ); ?>
						<table class="widefat striped">
							<thead><tr><th><?php echo esc_html( sprintf( __( '%s label', 'smart-multilingual' ), SML_Languages::get( $default )['native'] ) ); ?></th><?php foreach ( $targets as $target ) : ?><th><?php echo esc_html( SML_Languages::get( $target )['native'] ); ?></th><?php endforeach; ?><th><?php esc_html_e( 'Values', 'smart-multilingual' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $attributes as $attribute ) :
								$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );
								$key      = sanitize_key( $attribute->attribute_name );
								$url      = admin_url( 'edit-tags.php?taxonomy=' . rawurlencode( $taxonomy ) . '&post_type=product' );
							?>
							<tr>
								<td><strong><?php echo esc_html( $attribute->attribute_label ); ?></strong><br><code><?php echo esc_html( $taxonomy ); ?></code></td>
								<?php foreach ( $targets as $target ) : ?><td><input type="text" class="regular-text" name="attribute_labels[<?php echo esc_attr( $target ); ?>][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $translations[ $target ][ $key ] ?? '' ); ?>"></td><?php endforeach; ?>
								<td><a class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Configure and translate terms', 'smart-multilingual' ); ?></a></td>
							</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<?php submit_button( __( 'Save attribute translations', 'smart-multilingual' ) ); ?>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function save_attribute_translations() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage attribute translations.', 'smart-multilingual' ) );
		}
		check_admin_referer( 'sml_save_attribute_translations' );
		$input = isset( $_POST['attribute_labels'] ) && is_array( $_POST['attribute_labels'] ) ? wp_unslash( $_POST['attribute_labels'] ) : array();
		$clean = array();
		foreach ( $input as $language => $labels ) {
			$language = sanitize_key( $language );
			if ( ! SML_Languages::is_enabled( $language ) || SML_Languages::is_default( $language ) || ! is_array( $labels ) ) continue;
			foreach ( $labels as $key => $value ) {
				$key = sanitize_key( $key ); $value = sanitize_text_field( $value );
				if ( $key && '' !== $value ) $clean[ $language ][ $key ] = $value;
			}
		}
		update_option( 'sml_attribute_labels', $clean, false );
		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=smart-multilingual-attributes' ) ) );
		exit;
	}

	public function translate_attribute_label( $label, $name, $product = null ) {
		$language = SML_Plugin::instance()->current_language();
		if ( SML_Languages::is_default( $language ) ) {
			return $label;
		}
		$key = 0 === strpos( (string) $name, 'pa_' ) ? substr( (string) $name, 3 ) : (string) $name;
		$key = sanitize_key( $key );
		$translations = get_option( 'sml_attribute_labels', array() );
		// Flat options are accepted for non-destructive 0.9.x migration.
		if ( 'fa' === $language && ! isset( $translations['fa'] ) && ! empty( $translations[ $key ] ) ) return $translations[ $key ];
		return ! empty( $translations[ $language ][ $key ] ) ? $translations[ $language ][ $key ] : $label;
	}

	public function menus_page() {
		$menus = wp_get_nav_menus();
		$default = SML_Languages::default_code();
		?>
		<div class="wrap sml-admin-wrap">
			<h1><?php esc_html_e( 'Menu Translation', 'smart-multilingual' ); ?></h1>
			<div class="sml-card">
				<p><?php esc_html_e( 'Clone a source menu and replace linked content with translations for the selected target language when available.', 'smart-multilingual' ); ?></p>
				<?php if ( empty( $menus ) ) : ?>
					<p><?php esc_html_e( 'No navigation menus were found.', 'smart-multilingual' ); ?></p>
				<?php else : ?>
					<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
						<input type="hidden" name="action" value="sml_clone_menu">
						<?php wp_nonce_field( 'sml_clone_menu' ); ?>
						<select name="menu_id">
							<?php foreach ( $menus as $menu ) : ?>
								<option value="<?php echo esc_attr( $menu->term_id ); ?>"><?php echo esc_html( $menu->name ); ?></option>
							<?php endforeach; ?>
						</select>
						<select name="target_lang">
							<?php foreach ( SML_Languages::enabled() as $code ) : if ( $default === $code ) continue; $cfg = SML_Languages::get( $code ); ?><option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $cfg['native'] ); ?></option><?php endforeach; ?>
						</select>
						<?php submit_button( __( 'Create translated menu', 'smart-multilingual' ), 'primary', '', false ); ?>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function clone_menu() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to clone menus.', 'smart-multilingual' ) );
		}
		check_admin_referer( 'sml_clone_menu' );
		$menu_id = isset( $_GET['menu_id'] ) ? absint( $_GET['menu_id'] ) : 0;
		$target  = isset( $_GET['target_lang'] ) ? sanitize_key( wp_unslash( $_GET['target_lang'] ) ) : '';
		if ( ! SML_Languages::is_enabled( $target ) || SML_Languages::is_default( $target ) ) wp_die( esc_html__( 'Invalid target language.', 'smart-multilingual' ) );
		$target_cfg = SML_Languages::get( $target );
		$source  = wp_get_nav_menu_object( $menu_id );
		if ( ! $source ) {
			wp_die( esc_html__( 'Source menu was not found.', 'smart-multilingual' ) );
		}
		$new_menu_id = wp_create_nav_menu( $source->name . ' — ' . $target_cfg['native'] );
		if ( is_wp_error( $new_menu_id ) ) {
			wp_die( esc_html( $new_menu_id->get_error_message() ) );
		}
		$items   = wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) );
		$id_map  = array();
		$pending = array();
		foreach ( (array) $items as $item ) {
			$object_id = (int) $item->object_id;
			$url       = $item->url;
			if ( 'custom' !== $item->type && $object_id ) {
				if ( taxonomy_exists( $item->object ) ) {
					$group = get_term_meta( $object_id, SML_Taxonomy::META_GROUP, true );
					$links = $group ? SML_Taxonomy::instance()->get_group_translations( $group, $item->object ) : array();
					if ( ! empty( $links[$target] ) ) {
						$object_id = (int) $links[$target];
					}
				} else {
					$group = get_post_meta( $object_id, SML_Plugin::META_GROUP, true );
					$links = $group ? SML_Plugin::get_group_translations( $group ) : array();
					if ( ! empty( $links[$target] ) ) {
						$object_id = (int) $links[$target];
					}
				}
			} elseif ( 'custom' === $item->type && $url ) {
				$relative = SML_Plugin::relative_url_path( $url );
				if ( 0 === strpos( $url, home_url() ) ) {
					$url = SML_Plugin::language_url( $target, $relative );
				}
			}
			$new_item_id = wp_update_nav_menu_item(
				$new_menu_id,
				0,
				array(
					'menu-item-title'     => $item->title,
					'menu-item-description' => $item->description,
					'menu-item-attr-title' => $item->attr_title,
					'menu-item-target'    => $item->target,
					'menu-item-classes'   => implode( ' ', (array) $item->classes ),
					'menu-item-xfn'       => $item->xfn,
					'menu-item-status'    => 'publish',
					'menu-item-type'      => $item->type,
					'menu-item-object'    => $item->object,
					'menu-item-object-id' => $object_id,
					'menu-item-url'       => $url,
				)
			);
			if ( ! is_wp_error( $new_item_id ) ) {
				$id_map[ $item->ID ] = $new_item_id;
				$pending[ $new_item_id ] = (int) $item->menu_item_parent;
			}
		}
		foreach ( $pending as $new_item_id => $old_parent ) {
			if ( $old_parent && isset( $id_map[ $old_parent ] ) ) {
				update_post_meta( $new_item_id, '_menu_item_menu_item_parent', (int) $id_map[ $old_parent ] );
			}
		}
		$settings = SML_Plugin::settings();
		$settings['language_menus'][$target] = (int) $new_menu_id;
		if ( 'fa' === $target ) $settings['persian_menu'] = (int) $new_menu_id;
		update_option( SML_Plugin::OPTION_KEY, $settings );
		wp_safe_redirect( admin_url( 'nav-menus.php?action=edit&menu=' . (int) $new_menu_id ) );
		exit;
	}

}
