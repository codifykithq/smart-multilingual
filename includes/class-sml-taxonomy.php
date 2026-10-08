<?php
defined( 'ABSPATH' ) || exit;

final class SML_Taxonomy {
	const META_LANG        = '_sml_language';
	const META_GROUP       = '_sml_translation_group';
	const META_SOURCE_PATH = '_sml_source_path';

	private static $instance;
	private $resolved_term = null;
	private $query_term    = null;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'register_taxonomy_hooks' ), 100 );
		add_action( 'admin_action_sml_create_term_translation', array( $this, 'create_translation' ) );
		add_filter( 'term_link', array( $this, 'localized_term_link' ), 10, 3 );
		add_action( 'wp_head', array( $this, 'seo_links' ), 3 );
		add_filter( 'single_term_title', array( $this, 'localized_single_term_title' ), 10, 1 );
		add_filter( 'get_the_archive_title', array( $this, 'localized_archive_title' ), 20, 1 );
		add_filter( 'term_description', array( $this, 'localized_term_description' ), 20, 2 );
	}

	public function register_taxonomy_hooks() {
		$taxonomies = get_taxonomies( array( 'show_ui' => true ), 'objects' );
		foreach ( $taxonomies as $taxonomy => $object ) {
			if ( 'post_format' === $taxonomy ) {
				continue;
			}
			add_action( $taxonomy . '_add_form_fields', array( $this, 'add_form_fields' ) );
			add_action( $taxonomy . '_edit_form_fields', array( $this, 'edit_form_fields' ), 10, 2 );
			add_action( 'created_' . $taxonomy, array( $this, 'save_term_meta' ) );
			add_action( 'edited_' . $taxonomy, array( $this, 'save_term_meta' ) );
			add_filter( $taxonomy . '_row_actions', array( $this, 'row_actions' ), 10, 2 );
		}
	}

	public function add_form_fields() { ?>
		<div class="form-field"><label for="sml-term-language"><?php esc_html_e( 'Language', 'smart-multilingual' ); ?></label><select id="sml-term-language" name="sml_term_language"><?php foreach(SML_Languages::enabled()as$code):$cfg=SML_Languages::get($code);?><option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($cfg['native']); ?></option><?php endforeach;?></select><?php wp_nonce_field('sml_save_term','sml_term_nonce');?></div><?php
	}

	public function edit_form_fields( $term, $taxonomy ) {
		$lang=get_term_meta($term->term_id,self::META_LANG,true)?:SML_Languages::default_code();$group=get_term_meta($term->term_id,self::META_GROUP,true)?:wp_generate_uuid4();$links=$this->get_group_translations($group,$taxonomy);?>
		<tr class="form-field"><th scope="row"><label for="sml-term-language"><?php esc_html_e('Language & Translations','smart-multilingual');?></label></th><td><select id="sml-term-language" name="sml_term_language"><?php foreach(SML_Languages::enabled()as$code):$cfg=SML_Languages::get($code);?><option value="<?php echo esc_attr($code); ?>" <?php selected($lang,$code);?>><?php echo esc_html($cfg['native']);?></option><?php endforeach;?></select><input type="hidden" name="sml_term_group" value="<?php echo esc_attr($group);?>"><?php wp_nonce_field('sml_save_term','sml_term_nonce');?><div class="sml-translation-panel"><?php foreach(SML_Languages::enabled()as$code):$cfg=SML_Languages::get($code);?><div class="sml-translation-row"><span><b><?php echo esc_html(strtoupper($code));?></b> <?php echo esc_html($cfg['native']);?></span><?php if($code===$lang):?><span class="sml-status-current">Current</span><?php elseif(!empty($links[$code])):?><a class="button button-small" href="<?php echo esc_url(get_edit_term_link($links[$code],$taxonomy));?>">Edit</a><?php else:?><a class="button button-small" href="<?php echo esc_url($this->translation_action_url($term->term_id,$taxonomy,$code));?>">Create</a><?php endif;?></div><?php endforeach;?></div></td></tr><?php
	}

	public function save_term_meta( $term_id ) {
		if(empty($_POST['sml_term_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['sml_term_nonce'])),'sml_save_term')||!current_user_can('manage_categories'))return;
		$default=SML_Languages::default_code();$lang=isset($_POST['sml_term_language'])?sanitize_key(wp_unslash($_POST['sml_term_language'])):$default;if(!SML_Languages::is_enabled($lang))$lang=$default;update_term_meta($term_id,self::META_LANG,$lang);if(!empty($_POST['sml_term_group']))update_term_meta($term_id,self::META_GROUP,sanitize_text_field(wp_unslash($_POST['sml_term_group'])));
	}

	public function row_actions( $actions, $term ) {
		if(current_user_can('manage_categories')){$group=get_term_meta($term->term_id,self::META_GROUP,true);$links=$group?$this->get_group_translations($group,$term->taxonomy):array();$actions['sml_translate']='<a href="'.esc_url(get_edit_term_link($term->term_id,$term->taxonomy)).'">'.sprintf(esc_html__('Translations %1$d/%2$d','smart-multilingual'),count($links),count(SML_Languages::enabled())).'</a>';}return$actions;
	}

	private function translation_action_url( $term_id, $taxonomy, $target_lang ) {
		$url=admin_url('admin.php?action=sml_create_term_translation&term_id='.absint($term_id).'&taxonomy='.rawurlencode($taxonomy).'&target_lang='.sanitize_key($target_lang));return wp_nonce_url($url,'sml_create_term_translation_'.absint($term_id).'_'.sanitize_key($target_lang));
	}

	public function create_translation() {
		$term_id=isset($_GET['term_id'])?absint($_GET['term_id']):0;$taxonomy=isset($_GET['taxonomy'])?sanitize_key(wp_unslash($_GET['taxonomy'])):'';$target=isset($_GET['target_lang'])?sanitize_key(wp_unslash($_GET['target_lang'])):'';check_admin_referer('sml_create_term_translation_'.$term_id.'_'.$target);
		if(!$term_id||!taxonomy_exists($taxonomy)||!current_user_can('manage_categories')||!SML_Languages::is_enabled($target))wp_die(esc_html__('Invalid term translation request.','smart-multilingual'));
		$source=get_term($term_id,$taxonomy);if(!$source||is_wp_error($source))wp_die(esc_html__('Source term was not found.','smart-multilingual'));
		$source_lang=get_term_meta($term_id,self::META_LANG,true)?:SML_Languages::default_code();$group=get_term_meta($term_id,self::META_GROUP,true)?:wp_generate_uuid4();update_term_meta($term_id,self::META_GROUP,$group);update_term_meta($term_id,self::META_LANG,$source_lang);$existing=$this->get_group_translations($group,$taxonomy);if(!empty($existing[$target])){wp_safe_redirect(get_edit_term_link($existing[$target],$taxonomy));exit;}
		$parent=0;if($source->parent){$pg=get_term_meta($source->parent,self::META_GROUP,true);$pl=$pg?$this->get_group_translations($pg,$taxonomy):array();$parent=!empty($pl[$target])?(int)$pl[$target]:0;}$cfg=SML_Languages::get($target);
		$translation_slug=$this->translation_term_slug($source,$target,$existing,$taxonomy);
		$created=wp_insert_term($source->name.' — '.$cfg['native'],$taxonomy,array('slug'=>$translation_slug,'description'=>$source->description,'parent'=>$parent));if(is_wp_error($created))wp_die(esc_html($created->get_error_message()));$new=(int)$created['term_id'];update_term_meta($new,self::META_GROUP,$group);update_term_meta($new,self::META_LANG,$target);
		$default=SML_Languages::default_code();$source_term=!empty($existing[$default])?get_term((int)$existing[$default],$taxonomy):$source;update_term_meta($new,self::META_SOURCE_PATH,SML_Plugin::relative_url_path(get_term_link($source_term)));wp_safe_redirect(add_query_arg(array('sml_created'=>'1','sml_language'=>$target),get_edit_term_link($new,$taxonomy)));exit;
	}


	/** Build a translated term slug from the configured target language Code. */
	private function translation_term_slug( $source, $target_lang, $existing, $taxonomy ) {
		$target_lang = sanitize_key( $target_lang );
		$default     = SML_Languages::default_code();
		$base_term   = $source;

		if ( ! empty( $existing[ $default ] ) ) {
			$default_term = get_term( (int) $existing[ $default ], $taxonomy );
			if ( $default_term && ! is_wp_error( $default_term ) ) {
				$base_term = $default_term;
			}
		}

		$base_slug = sanitize_title( (string) $base_term->slug );
		if ( '' === $base_slug ) {
			$base_slug = sanitize_title( (string) $base_term->name );
		}

		$base_lang = get_term_meta( $base_term->term_id, self::META_LANG, true );
		$base_lang = $base_lang ? sanitize_key( $base_lang ) : $default;
		if ( $base_lang !== $default ) {
			$base_code = $this->slug_language_code( $base_lang );
			$suffix    = '-' . $base_code;
			if ( $base_code && substr( $base_slug, -strlen( $suffix ) ) === $suffix ) {
				$base_slug = rtrim( substr( $base_slug, 0, -strlen( $suffix ) ), '-' );
			}
		}

		if ( $target_lang === $default ) {
			return $base_slug;
		}

		return sanitize_title( $base_slug . '-' . $this->slug_language_code( $target_lang ) );
	}

	private function slug_language_code( $language ) {
		$language = sanitize_key( $language );
		return trim( sanitize_title( str_replace( '_', '-', $language ) ), '-' );
	}

	public function get_group_translations( $group, $taxonomy ) {
		$result = array();
		$terms  = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'meta_query' => array(
					array( 'key' => self::META_GROUP, 'value' => sanitize_text_field( $group ) ),
				),
			)
		);
		if ( is_wp_error( $terms ) ) {
			return $result;
		}
		foreach ( $terms as $term ) {
			$lang            = get_term_meta( $term->term_id, self::META_LANG, true ) ?: SML_Languages::default_code();
			$result[ $lang ] = $term->term_id;
		}
		return $result;
	}

	public function localized_term_link( $url, $term, $taxonomy ) {
		$default = SML_Languages::default_code();
		if ( ! $term instanceof WP_Term || $default === ( get_term_meta( $term->term_id, self::META_LANG, true ) ?: $default ) ) {
			return $url;
		}
		$path = get_term_meta( $term->term_id, self::META_SOURCE_PATH, true );
		if ( ! $path ) {
			$group = get_term_meta( $term->term_id, self::META_GROUP, true );
			$links = $group ? $this->get_group_translations( $group, $taxonomy ) : array();
			if ( ! empty( $links[$default] ) ) {
				$source = get_term( $links[$default], $taxonomy );
				if ( $source && ! is_wp_error( $source ) ) {
					$path = SML_Plugin::relative_url_path( get_term_link( $source ) );
				}
			}
		}
		if ( ! $path ) {
			$path = SML_Plugin::relative_url_path( $url );
		}
		return $path ? SML_Plugin::language_url( get_term_meta( $term->term_id, self::META_LANG, true ), $path ) : $url;
	}


	public function resolve_rewrite_request( $wp, $language = '' ) {
		$language = $language && SML_Languages::is_enabled( $language ) ? $language : SML_Languages::default_code();
		$taxonomy = isset( $wp->query_vars['sml_taxonomy'] ) ? sanitize_key( $wp->query_vars['sml_taxonomy'] ) : '';
		$path     = isset( $wp->query_vars['sml_term_path'] ) ? trim( rawurldecode( (string) $wp->query_vars['sml_term_path'] ), '/' ) : '';
		if ( ! $taxonomy || ! taxonomy_exists( $taxonomy ) || '' === $path ) {
			return false;
		}

		$parts = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
		$slug  = sanitize_title( end( $parts ) );
		$source = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $source instanceof WP_Term ) {
			return false;
		}

		$this->query_term    = $source;
		$this->resolved_term = $this->get_translation_term( $source, $language );
		if ( ! $this->resolved_term instanceof WP_Term ) {
			/* Do not serve a source-language taxonomy archive at a translated URL.
			 * The caller will turn this unresolved route into a genuine 404. */
			$this->query_term = null;
			return false;
		}

		// Rewrite rules already populated the native taxonomy query variable.
		// Remove only plugin routing vars so WP_Query sees a normal archive.
		unset( $wp->query_vars['sml_path'], $wp->query_vars['sml_taxonomy'], $wp->query_vars['sml_term_path'] );
		SML_Plugin::instance()->mark_resolved_language_request();
		return true;
	}

	private function get_translation_term( $term, $language ) {
		if ( ! $term instanceof WP_Term ) {
			return null;
		}
		$group = get_term_meta( $term->term_id, self::META_GROUP, true );
		$links = $group ? $this->get_group_translations( $group, $term->taxonomy ) : array();
		if ( empty( $links[ $language ] ) ) {
			return null;
		}
		$translated = get_term( (int) $links[ $language ], $term->taxonomy );
		return $translated instanceof WP_Term ? $translated : null;
	}

	public function resolve_request( $path, $wp, $language = '' ) {
		$language = $language && SML_Languages::is_enabled( $language ) ? $language : SML_Plugin::instance()->current_language();
		$terms = get_terms(
			array(
				'taxonomy'   => get_taxonomies( array( 'public' => true ), 'names' ),
				'hide_empty' => false,
				'number'     => 1,
				'meta_query' => array(
					array( 'key' => self::META_LANG, 'value' => $language ),
					array( 'key' => self::META_SOURCE_PATH, 'value' => trim( $path, '/' ) ),
				),
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return false;
		}

		$this->resolved_term = $terms[0];
		$this->query_term    = $this->get_source_term( $this->resolved_term );
		$query_term          = $this->query_term instanceof WP_Term ? $this->query_term : $this->resolved_term;

		$wp->query_vars = array( 'sml_lang' => $language );
		if ( 'category' === $query_term->taxonomy ) {
			$wp->query_vars['category_name'] = $query_term->slug;
		} elseif ( 'post_tag' === $query_term->taxonomy ) {
			$wp->query_vars['tag'] = $query_term->slug;
		} else {
			$wp->query_vars['taxonomy'] = $query_term->taxonomy;
			$wp->query_vars['term']     = $query_term->slug;
			$wp->query_vars[ $query_term->taxonomy ] = $query_term->slug;
		}

		SML_Plugin::instance()->mark_resolved_language_request();
		return true;
	}

	private function get_source_term( $term ) {
		if ( ! $term instanceof WP_Term ) {
			return null;
		}
		$group = get_term_meta( $term->term_id, self::META_GROUP, true );
		$links = $group ? $this->get_group_translations( $group, $term->taxonomy ) : array();
		$default = SML_Languages::default_code();
		if ( ! empty( $links[$default] ) ) {
			$source = get_term( (int) $links[$default], $term->taxonomy );
			if ( $source instanceof WP_Term ) {
				return $source;
			}
		}
		return $term;
	}


	public function apply_resolved_archive_query( $query ) {
		// Routing resolves a translated public URL to the source taxonomy term
		// before WP_Query is built. Keeping this method as a compatibility no-op
		// prevents old integrations from failing while avoiding late tax-query
		// mutations that caused empty archives in themes such as WoodMart.
	}

	public function localized_single_term_title( $title ) {
		return $this->resolved_term instanceof WP_Term ? $this->resolved_term->name : $title;
	}

	public function localized_archive_title( $title ) {
		if ( ! $this->resolved_term instanceof WP_Term ) {
			return $title;
		}
		if ( 'category' === $this->resolved_term->taxonomy ) {
			return sprintf( __( 'Category: %s' ), $this->resolved_term->name );
		}
		if ( 'post_tag' === $this->resolved_term->taxonomy ) {
			return sprintf( __( 'Tag: %s' ), $this->resolved_term->name );
		}
		return $this->resolved_term->name;
	}

	public function localized_term_description( $description, $term_id ) {
		if ( ! $this->resolved_term instanceof WP_Term ) {
			return $description;
		}
		if ( $this->query_term instanceof WP_Term && (int) $term_id === (int) $this->query_term->term_id ) {
			return $this->resolved_term->description;
		}
		return $description;
	}


	public function seo_links() {
		if ( is_404() || ( ! is_tax() && ! is_category() && ! is_tag() ) ) {
			return;
		}
		$term = get_queried_object();
		if ( ! $term instanceof WP_Term ) return;

		$group = get_term_meta( $term->term_id, self::META_GROUP, true );
		$links = $group ? $this->get_group_translations( $group, $term->taxonomy ) : array();
		foreach ( SML_Languages::enabled() as $lang ) {
			if ( empty( $links[ $lang ] ) ) continue;
			$link = get_term_link( (int) $links[ $lang ], $term->taxonomy );
			if ( is_wp_error( $link ) ) continue;
			$cfg = SML_Languages::get( $lang );
			printf( '<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "\n", esc_attr( $cfg['hreflang'] ), esc_url( $link ) );
		}

		$default = SML_Languages::default_code();
		if ( ! empty( $links[ $default ] ) ) {
			$link = get_term_link( (int) $links[ $default ], $term->taxonomy );
			if ( ! is_wp_error( $link ) ) {
				printf( '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n", esc_url( $link ) );
			}
		}
	}
}
