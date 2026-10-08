<?php
defined( 'ABSPATH' ) || exit;

final class SML_Theme_Compat {
	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		if ( SML_Compatibility::enabled( 'content_queries' ) ) {
			add_filter( 'widget_posts_args', array( $this, 'filter_recent_posts_args' ) );
			add_filter( 'widget_categories_args', array( $this, 'filter_category_widget_args' ) );
			add_filter( 'widget_categories_dropdown_args', array( $this, 'filter_category_widget_args' ) );
			add_filter( 'get_terms', array( $this, 'filter_frontend_terms' ), 30, 4 );
			add_filter( 'getarchives_where', array( $this, 'filter_archives_where' ), 20, 2 );
			add_filter( 'get_archives_link', array( $this, 'localize_archive_link' ), 20, 6 );
			add_filter( 'year_link', array( $this, 'localize_date_archive_url' ), 20 );
			add_filter( 'month_link', array( $this, 'localize_date_archive_url' ), 20 );
			add_filter( 'day_link', array( $this, 'localize_date_archive_url' ), 20 );
			add_filter( 'get_search_form', array( $this, 'localize_search_form' ), 20 );
			add_filter( 'get_previous_post_where', array( $this, 'filter_adjacent_post_where' ), 20, 5 );
			add_filter( 'get_next_post_where', array( $this, 'filter_adjacent_post_where' ), 20, 5 );
			add_action( 'pre_get_posts', array( $this, 'filter_secondary_post_queries' ), 35 );
		}

		// Elementor Header & Footer Builder / Ultimate Addons compatibility.
		// The builder may return an arbitrary template when multiple templates use
		// the same display conditions. Resolve the matching language template here.
		if ( SML_Compatibility::enabled( 'hfe_templates' ) ) {
			/* Elementor Pro Theme Builder resolves cached header/footer template IDs
			 * through this filter before loading the document. Map the source template
			 * to its translation for the current public language. */
			add_filter( 'elementor/theme/get_location_templates/template_id', array( $this, 'filter_elementor_theme_builder_template_id' ), 999, 2 );
			/*
			 * HFE/UAE versions do not all expose the same final template-ID filter.
			 * Filter the underlying elementor-hf query as well, so the builder only
			 * sees templates belonging to the public request language. This makes
			 * /en/, /tr/, etc. deterministic even when legacy ID filters are skipped.
			 */
			add_action( 'pre_get_posts', array( $this, 'filter_hfe_template_query' ), 5 );
			add_filter( 'hfe_get_settings_type_header', array( $this, 'filter_hfe_header_id' ), 999 );
			add_filter( 'hfe_get_settings_type_footer', array( $this, 'filter_hfe_footer_id' ), 999 );
			add_filter( 'hfe_get_settings_type_before_footer', array( $this, 'filter_hfe_before_footer_id' ), 999 );
			add_filter( 'get_hfe_header_id', array( $this, 'filter_hfe_header_id' ), 999 );
			add_filter( 'get_hfe_footer_id', array( $this, 'filter_hfe_footer_id' ), 999 );
			add_filter( 'get_hfe_before_footer_id', array( $this, 'filter_hfe_before_footer_id' ), 999 );
		}
	}

	private function language_meta_query( $lang ) {
		$default = SML_Languages::default_code();
		if ( $default === $lang ) {
			return array( 'relation' => 'OR', array( 'key' => SML_Plugin::META_LANG, 'value' => $default ), array( 'key' => SML_Plugin::META_LANG, 'compare' => 'NOT EXISTS' ) );
		}
		return array( 'key' => SML_Plugin::META_LANG, 'value' => $lang );
	}

	public function filter_recent_posts_args( $args ) {
		if ( is_admin() ) return $args;
		$lang = SML_Plugin::instance()->current_language();
		$args['meta_query'] = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array();
		$args['meta_query'][] = $this->language_meta_query( $lang );
		$args['_sml_language_filtered'] = 1;
		return $args;
	}

	public function filter_category_widget_args( $args ) {
		if ( is_admin() ) return $args;
		$lang = SML_Plugin::instance()->current_language();
		$taxonomy = isset( $args['taxonomy'] ) ? sanitize_key( $args['taxonomy'] ) : 'category';
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids', 'sml_all_languages' => true ) );
		if ( ! is_wp_error( $terms ) ) {
			$allowed = array();
			foreach ( $terms as $term_id ) {
				$term_lang = get_term_meta( $term_id, SML_Taxonomy::META_LANG, true ) ?: SML_Languages::default_code();
				if ( $term_lang === $lang ) $allowed[] = (int) $term_id;
			}
			$args['include'] = $allowed ? implode( ',', $allowed ) : '0';
		}
		return $args;
	}

	public function filter_frontend_terms( $terms, $taxonomies, $args, $term_query ) {
		if ( is_admin() || ! is_array( $terms ) || ! empty( $args['sml_all_languages'] ) ) return $terms;
		$lang = SML_Plugin::instance()->current_language();
		$filtered = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) { $filtered[] = $term; continue; }
			$taxonomy = get_taxonomy( $term->taxonomy );
			if ( ! $taxonomy || empty( $taxonomy->public ) ) { $filtered[] = $term; continue; }
			$term_lang = get_term_meta( $term->term_id, SML_Taxonomy::META_LANG, true ) ?: SML_Languages::default_code();
			if ( $term_lang === $lang ) $filtered[] = $term;
		}
		return $filtered;
	}

	public function filter_archives_where( $where, $parsed_args ) {
		if ( is_admin() ) return $where;
		global $wpdb;
		$lang = SML_Plugin::instance()->current_language();
		$default = SML_Languages::default_code();
		if ( $default === $lang ) {
			$where .= $wpdb->prepare( " AND (NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} smlpm WHERE smlpm.post_id={$wpdb->posts}.ID AND smlpm.meta_key=%s) OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} smlpm WHERE smlpm.post_id={$wpdb->posts}.ID AND smlpm.meta_key=%s AND smlpm.meta_value=%s))", SML_Plugin::META_LANG, SML_Plugin::META_LANG, $default );
		} else {
			$where .= $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} smlpm WHERE smlpm.post_id={$wpdb->posts}.ID AND smlpm.meta_key=%s AND smlpm.meta_value=%s)", SML_Plugin::META_LANG, $lang );
		}
		return $where;
	}

	public function localize_archive_link( $link_html, $url, $text, $format, $before, $after ) {
		$lang = SML_Plugin::instance()->current_language();
		if ( SML_Languages::is_default( $lang ) ) return $link_html;
		$new_url = $this->localize_date_archive_url( $url );
		$new_text = $text;
		return $before . '<a href="' . esc_url( $new_url ) . '">' . esc_html( $new_text ) . '</a>' . $after;
	}

	public function localize_date_archive_url( $url ) {
		$lang = SML_Plugin::instance()->current_language();
		if ( SML_Languages::is_default( $lang ) ) return $url;
		return SML_Plugin::language_url( $lang, SML_Plugin::relative_url_path( $url ) );
	}

	public function localize_search_form( $form ) {
		$lang = SML_Plugin::instance()->current_language();
		if ( SML_Languages::is_default( $lang ) ) return $form;
		$action = SML_Plugin::language_url( $lang );
		$form = preg_replace( '/action=(["\']).*?\1/i', 'action="' . esc_url( $action ) . '"', $form, 1 );
		return $form;
	}


	public function filter_adjacent_post_where( $where, $in_same_term, $excluded_terms, $taxonomy, $post ) {
		if ( is_admin() || ! $post instanceof WP_Post ) {
			return $where;
		}

		global $wpdb;
		$lang = get_post_meta( $post->ID, SML_Plugin::META_LANG, true ) ?: SML_Languages::default_code();

		$default = SML_Languages::default_code();
		if ( $default === $lang ) {
			$where .= $wpdb->prepare( " AND (NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} sml_adj_lang WHERE sml_adj_lang.post_id = p.ID AND sml_adj_lang.meta_key = %s) OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} sml_adj_lang WHERE sml_adj_lang.post_id = p.ID AND sml_adj_lang.meta_key = %s AND sml_adj_lang.meta_value = %s))", SML_Plugin::META_LANG, SML_Plugin::META_LANG, $default );
		} else {
			$where .= $wpdb->prepare(
				" AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} sml_adj_lang WHERE sml_adj_lang.post_id = p.ID AND sml_adj_lang.meta_key = %s AND sml_adj_lang.meta_value = %s)",
				SML_Plugin::META_LANG,
				$lang
			);
		}

		return $where;
	}

	public function filter_secondary_post_queries( $query ) {
		if ( is_admin() || $query->is_main_query() || $query->get( '_sml_language_filtered' ) ) {
			return;
		}

		$plugin = SML_Plugin::instance();
		if ( ! $plugin->query_targets_language_content( $query ) ) {
			return;
		}

		// Elementor/Aitechfy post grids and WooCommerce product carousels are
		// filtered here. Internal template/media queries are excluded by the helper.
		$plugin->apply_strict_language_filter( $query, $plugin->current_language() );
	}



	/**
	 * Restrict Elementor Header & Footer Builder template discovery to the
	 * current frontend language before HFE chooses the winning template.
	 *
	 * This intentionally targets only the elementor-hf post type and leaves all
	 * normal page/post/product queries untouched. For the source language,
	 * legacy HFE templates with no SML language meta are still accepted.
	 *
	 * @param WP_Query $query Query being prepared.
	 * @return void
	 */
	public function filter_hfe_template_query( $query ) {
		if ( is_admin() || ! $query instanceof WP_Query || $query->get( '_sml_hfe_language_filtered' ) ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		$post_types = is_array( $post_type ) ? $post_type : array( $post_type );
		if ( ! in_array( 'elementor-hf', $post_types, true ) ) {
			return;
		}

		$current_lang = SML_Plugin::instance()->current_language();
		if ( ! $current_lang || ! SML_Languages::is_enabled( $current_lang ) ) {
			return;
		}

		$meta_query = $query->get( 'meta_query' );
		$meta_query = is_array( $meta_query ) ? $meta_query : array();
		$default     = SML_Languages::default_code();

		if ( $current_lang === $default ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'   => SML_Plugin::META_LANG,
					'value' => $default,
				),
				array(
					'key'     => SML_Plugin::META_LANG,
					'compare' => 'NOT EXISTS',
				),
			);
		} else {
			$meta_query[] = array(
				'key'   => SML_Plugin::META_LANG,
				'value' => $current_lang,
			);
		}

		$query->set( 'meta_query', $meta_query );
		$query->set( '_sml_hfe_language_filtered', 1 );
	}


	/**
	 * Map Elementor Pro Theme Builder header/footer IDs to the translation that
	 * belongs to the current language. Elementor Pro's conditions cache can keep
	 * the source template ID; this filter is intentionally the final translation
	 * layer before Elementor creates the Theme_Document.
	 *
	 * It also repairs older SML clones that Elementor saved as template type
	 * "page" instead of inheriting "header" / "footer" from the source.
	 */
	public function filter_elementor_theme_builder_template_id( $template_id, $location ) {
		if ( is_admin() && empty( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $template_id;
		}

		$source_id = absint( $template_id );
		if ( ! $source_id || ! in_array( $location, array( 'header', 'footer' ), true ) ) {
			return $template_id;
		}

		$current_lang = SML_Plugin::instance()->current_language();
		if ( ! $current_lang || ! SML_Languages::is_enabled( $current_lang ) ) {
			return $template_id;
		}

		$group = get_post_meta( $source_id, SML_Plugin::META_GROUP, true );
		if ( ! $group ) {
			return $template_id;
		}

		/* Do not use SML_Plugin::get_group_translations() here. That generic
		 * helper queries post_type=any, while Elementor Theme Builder templates
		 * live in elementor_library and may be excluded from an `any` query.
		 * Resolve the translated Theme Builder document explicitly by its actual
		 * post type + translation group + target language. */
		$target_ids = get_posts( array(
			'post_type'              => get_post_type( $source_id ) ?: 'elementor_library',
			'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'suppress_filters'       => true,
			'meta_query'             => array(
				'relation' => 'AND',
				array(
					'key'   => SML_Plugin::META_GROUP,
					'value' => sanitize_text_field( $group ),
				),
				array(
					'key'   => SML_Plugin::META_LANG,
					'value' => sanitize_key( $current_lang ),
				),
			),
		) );

		$target_id = ! empty( $target_ids[0] ) ? absint( $target_ids[0] ) : 0;
		if ( ! $target_id || 'publish' !== get_post_status( $target_id ) ) {
			return $template_id;
		}

		/* Keep Elementor's document type aligned with the original Theme Builder
		 * template. Older translations created by SML could become "page" after
		 * opening/saving in Elementor, which makes them invisible to header/footer
		 * rendering even though their translation relationship is correct. */
		$source_type = get_post_meta( $source_id, '_elementor_template_type', true );
		$expected_type = in_array( $source_type, array( 'header', 'footer' ), true ) ? $source_type : $location;
		$target_type = get_post_meta( $target_id, '_elementor_template_type', true );
		if ( $expected_type && $target_type !== $expected_type ) {
			delete_post_meta( $target_id, '_elementor_template_type' );
			update_post_meta( $target_id, '_elementor_template_type', $expected_type );
			wp_cache_delete( $target_id, 'post_meta' );
			clean_post_cache( $target_id );
		}

		/* Conditions are evaluated from Elementor's cached source entry, but keeping
		 * the target metadata synchronized makes future cache regeneration safe. */
		$source_conditions = get_post_meta( $source_id, '_elementor_conditions', true );
		if ( ! empty( $source_conditions ) ) {
			$target_conditions = get_post_meta( $target_id, '_elementor_conditions', true );
			if ( $target_conditions !== $source_conditions ) {
				delete_post_meta( $target_id, '_elementor_conditions' );
				update_post_meta( $target_id, '_elementor_conditions', $source_conditions );
			}
		}

		return $target_id;
	}

	public function filter_hfe_header_id( $template_id ) {
		return $this->resolve_hfe_template_id( $template_id, 'type_header' );
	}

	public function filter_hfe_footer_id( $template_id ) {
		return $this->resolve_hfe_template_id( $template_id, 'type_footer' );
	}

	public function filter_hfe_before_footer_id( $template_id ) {
		return $this->resolve_hfe_template_id( $template_id, 'type_before_footer' );
	}

	/**
	 * Select the Header/Footer Builder template matching the current language.
	 *
	 * Priority:
	 * 1. Translation linked to the builder-selected template.
	 * 2. Selected template itself when its language matches.
	 * 3. A language template with identical HFE display conditions.
	 * 4. Any published language template of the same HFE type.
	 * 5. Original builder selection as a safe fallback.
	 *
	 * @param int|string|false $template_id Selected HFE template ID.
	 * @param string           $type        HFE template type.
	 * @return int|string|false
	 */
	private function resolve_hfe_template_id( $template_id, $type ) {
		if ( is_admin() && empty( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $template_id;
		}

		$current_lang = SML_Plugin::instance()->current_language();
		$selected_id  = absint( $template_id );

		if ( $selected_id ) {
			$group = get_post_meta( $selected_id, SML_Plugin::META_GROUP, true );
			if ( $group ) {
				$translations = SML_Plugin::get_group_translations( $group );
				if ( ! empty( $translations[ $current_lang ] ) && 'publish' === get_post_status( $translations[ $current_lang ] ) ) {
					return (int) $translations[ $current_lang ];
				}
			}

			$selected_lang = get_post_meta( $selected_id, SML_Plugin::META_LANG, true ) ?: SML_Languages::default_code();
			if ( $selected_lang === $current_lang && 'publish' === get_post_status( $selected_id ) ) {
				return $selected_id;
			}
		}

		$candidates = get_posts(
			array(
				'post_type'              => 'elementor-hf',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'meta_query'             => array(
					array(
						'key'   => 'ehf_template_type',
						'value' => $type,
					),
				),
			)
		);

		$language_candidates = array();
		foreach ( $candidates as $candidate ) {
			$candidate_lang = get_post_meta( $candidate->ID, SML_Plugin::META_LANG, true ) ?: SML_Languages::default_code();
			if ( $candidate_lang === $current_lang ) {
				$language_candidates[] = $candidate;
			}
		}

		if ( $selected_id && $language_candidates ) {
			$condition_keys = array( 'ehf_target_include_locations', 'ehf_target_exclude_locations', 'ehf_target_user_roles' );
			foreach ( $language_candidates as $candidate ) {
				$same_conditions = true;
				foreach ( $condition_keys as $condition_key ) {
					if ( get_post_meta( $candidate->ID, $condition_key, true ) !== get_post_meta( $selected_id, $condition_key, true ) ) {
						$same_conditions = false;
						break;
					}
				}
				if ( $same_conditions ) {
					return (int) $candidate->ID;
				}
			}
		}

		if ( $language_candidates ) {
			return (int) $language_candidates[0]->ID;
		}

		return $template_id;
	}
}
