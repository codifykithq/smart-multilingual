<?php
defined( 'ABSPATH' ) || exit;

final class SML_Strings {
	private static $instance;
	private $maps = array();
	private $language_only_maps = array();
	private $attribute_maps = array();

	public static function instance() {
		if ( ! self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		/*
		 * wp-admin is not a translation target for frontend String Translation.
		 * Keep only the configuration page/settings there. In particular, never
		 * register gettext/gettext_with_context filters in the dashboard: WordPress
		 * uses a contextual `ltr`/`rtl` translation while constructing WP_Locale.
		 */
		if ( is_admin() && ! wp_doing_ajax() ) {
			add_action( 'admin_menu', array( $this, 'admin_menu' ), 20 );
			add_action( 'admin_init', array( $this, 'register_setting' ) );
			return;
		}

		add_filter( 'gettext', array( $this, 'translate_string' ), 20, 3 );
		add_filter( 'gettext_with_context', array( $this, 'translate_context_string' ), 20, 4 );
		add_filter( 'widget_title', array( $this, 'translate_plain_string' ), 20 );
		add_filter( 'widget_text', array( $this, 'translate_html' ), 20 );
		add_filter( 'widget_block_content', array( $this, 'translate_html' ), 20 );
		add_filter( 'sidebars_widgets', array( $this, 'translate_sidebars' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scoped_translations' ), 40 );
		add_action( 'template_redirect', array( $this, 'start_output_translation' ), 0 );
		add_shortcode( 'sml_string', array( $this, 'shortcode' ) );
	}

	public function admin_menu() {
		add_submenu_page( 'smart-multilingual', __( 'String Translation', 'smart-multilingual' ), __( 'Strings', 'smart-multilingual' ), 'manage_options', 'smart-multilingual-strings', array( $this, 'page' ) );
	}

	public function register_setting() {
		foreach ( SML_Languages::secondary() as $lang ) {
			register_setting( 'sml_strings_group', 'sml_string_translations_' . $lang, array( $this, 'sanitize' ) );
			register_setting( 'sml_strings_group', 'sml_language_only_strings_' . $lang, array( $this, 'sanitize' ) );
			register_setting( 'sml_strings_group', 'sml_attribute_translations_' . $lang, array( $this, 'sanitize' ) );
			register_setting( 'sml_strings_group', 'sml_scoped_strings_' . $lang, array( $this, 'sanitize' ) );
			register_setting( 'sml_strings_group', 'sml_sidebar_map_' . $lang, array( $this, 'sanitize' ) );
		}
	}

	public function sanitize( $value ) { return sanitize_textarea_field( (string) $value ); }

	private function map( $lang ) {
		if ( isset( $this->maps[ $lang ] ) ) return $this->maps[ $lang ];
		$raw = (string) get_option( 'sml_string_translations_' . $lang, '' );
		$map = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			if ( false === strpos( $line, '|||' ) ) continue;
			list( $source, $translation ) = array_map( 'trim', explode( '|||', $line, 2 ) );
			if ( '' !== $source && '' !== $translation ) $map[ $source ] = $translation;
		}
		uksort( $map, static function( $a, $b ) { return strlen( $b ) <=> strlen( $a ); } );
		$this->maps[ $lang ] = $map;
		return $map;
	}

	/**
	 * Text replacements that are explicitly restricted to one language.
	 *
	 * These rules are stored separately from the older global-string field so a
	 * brand name or market-specific phrase can never leak into another language.
	 * Language-only rules take precedence when the same source also exists in
	 * the legacy global map.
	 */
	private function language_only_map( $lang ) {
		$lang = sanitize_key( $lang );
		if ( isset( $this->language_only_maps[ $lang ] ) ) return $this->language_only_maps[ $lang ];

		$raw = (string) get_option( 'sml_language_only_strings_' . $lang, '' );
		$map = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			if ( false === strpos( $line, '|||' ) ) continue;
			list( $source, $translation ) = array_map( 'trim', explode( '|||', $line, 2 ) );
			if ( '' !== $source && '' !== $translation ) $map[ $source ] = $translation;
		}
		uksort( $map, static function( $a, $b ) { return strlen( $b ) <=> strlen( $a ); } );
		$this->language_only_maps[ $lang ] = $map;
		return $map;
	}

	private function effective_text_map( $lang ) {
		if ( SML_Languages::is_default( $lang ) || SML_Plugin::instance()->current_language() !== $lang ) return array();
		/* Built-ins are the lowest priority; administrator-defined translations still win. */
		return array_replace( $this->builtin_map( $lang ), $this->map( $lang ), $this->language_only_map( $lang ) );
	}
	private function builtin_map( $lang ) {
		return array();
	}

	private function attribute_map( $lang ) {
		if ( isset( $this->attribute_maps[ $lang ] ) ) return $this->attribute_maps[ $lang ];
		$raw = (string) get_option( 'sml_attribute_translations_' . $lang, '' );
		$map = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$parts = array_map( 'trim', explode( '|||', $line, 3 ) );
			if ( 3 !== count( $parts ) ) continue;
			$attribute = strtolower( $parts[0] );
			if ( ! in_array( $attribute, array( 'placeholder', 'title', 'aria-label', 'aria-placeholder', 'value' ), true ) ) continue;
			if ( '' !== $parts[1] && '' !== $parts[2] ) $map[ $attribute ][ $parts[1] ] = $parts[2];
		}
		$this->attribute_maps[ $lang ] = $map;
		return $map;
	}

	public function translate_plain_string( $text ) {
		if ( is_admin() || ! is_string( $text ) ) return $text;
		$lang = SML_Plugin::instance()->current_language();
		if ( SML_Languages::is_default( $lang ) ) return $text;
		$map = $this->effective_text_map( $lang );
		$trim = trim( wp_strip_all_tags( $text ) );
		if ( isset( $map[ $text ] ) ) return $map[ $text ];
		if ( isset( $map[ $trim ] ) && $trim === $text ) return $map[ $trim ];
		return $text;
	}

	public function translate_html( $html ) {
		if ( is_admin() || ! is_string( $html ) ) return $html;
		$lang = SML_Plugin::instance()->current_language();
		if ( SML_Languages::is_default( $lang ) ) return $html;
		return $this->translate_document( $html, $lang );
	}

	public function translate_string( $translation, $text, $domain ) {
		$translated = $this->translate_plain_string( $translation );
		if ( $translated === $translation && $text !== $translation ) $translated = $this->translate_plain_string( $text );
		return $translated;
	}
	public function translate_context_string( $translation, $text, $context, $domain ) { return $this->translate_string( $translation, $text, $domain ); }

	public function start_output_translation() {
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_robots() || is_trackback() ) return;
		$lang = SML_Plugin::instance()->current_language();
		if ( SML_Languages::is_default( $lang ) ) return;
		if ( empty( $this->effective_text_map( $lang ) ) && empty( $this->attribute_map( $lang ) ) ) return;
		ob_start( array( $this, 'translate_frontend_output' ) );
	}

	public function translate_frontend_output( $html ) {
		$lang = SML_Plugin::instance()->current_language();
		if ( SML_Languages::is_default( $lang ) || ! is_string( $html ) || '' === $html ) return $html;
		return $this->translate_document( $html, $lang );
	}

	private function translate_document( $html, $lang ) {
		$text_map = $this->effective_text_map( $lang );
		$attribute_map = $this->attribute_map( $lang );
		if ( empty( $text_map ) && empty( $attribute_map ) ) return $html;

		$protected = array();
		$html = preg_replace_callback(
			'#<(script|style|noscript|pre|code|textarea)\b[^>]*>.*?</\1>#isu',
			static function( $m ) use ( &$protected ) {
				$key = '%%SML_PROTECTED_' . count( $protected ) . '%%';
				$protected[ $key ] = $m[0];
				return $key;
			},
			$html
		);

		$html = preg_replace_callback(
			'/\b(placeholder|title|aria-label|aria-placeholder|value)=("|\')(.*?)\2/isu',
			static function( $m ) use ( $attribute_map, $text_map ) {
				$attribute = strtolower( $m[1] );
				$value = html_entity_decode( $m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$translated = $value;
				if ( isset( $attribute_map[ $attribute ][ $value ] ) ) {
					$translated = $attribute_map[ $attribute ][ $value ];
				} elseif ( isset( $text_map[ $value ] ) ) {
					$translated = $text_map[ $value ];
				}
				return $m[1] . '=' . $m[2] . esc_attr( $translated ) . $m[2];
			},
			$html
		);

		if ( ! empty( $text_map ) ) {
			$html = preg_replace_callback(
				'/>[^<]+</u',
				static function( $m ) use ( $text_map ) {
					$text = substr( $m[0], 1, -1 );
					foreach ( $text_map as $source => $translation ) $text = str_replace( $source, $translation, $text );
					return '>' . $text . '<';
				},
				$html
			);
		}

		if ( ! empty( $protected ) ) $html = strtr( $html, $protected );
		return $html;
	}

	public function translate_sidebars( $sidebars ) {
		$lang = SML_Plugin::instance()->current_language();
		if ( is_admin() || SML_Languages::is_default( $lang ) || ! is_array( $sidebars ) ) return $sidebars;
		$raw = (string) get_option( 'sml_sidebar_map_' . $lang, '' );
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			if ( false === strpos( $line, '|||' ) ) continue;
			list( $source, $target ) = array_map( 'sanitize_key', array_map( 'trim', explode( '|||', $line, 2 ) ) );
			if ( $source && $target && isset( $sidebars[ $target ] ) ) $sidebars[ $source ] = $sidebars[ $target ];
		}
		return $sidebars;
	}

	public function enqueue_scoped_translations() {
		$lang = SML_Plugin::instance()->current_language();
		if ( SML_Languages::is_default( $lang ) ) return;
		$raw = (string) get_option( 'sml_scoped_strings_' . $lang, '' );
		$rules = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$parts = array_map( 'trim', explode( '|||', $line, 3 ) );
			if ( 3 === count( $parts ) && $parts[0] && $parts[1] && $parts[2] ) $rules[] = array( 'selector' => $parts[0], 'source' => $parts[1], 'translation' => $parts[2] );
		}
		if ( empty( $rules ) ) return;
		wp_register_script( 'sml-scoped-strings', '', array(), SML_VERSION, true );
		wp_enqueue_script( 'sml-scoped-strings' );
		$js = 'document.addEventListener("DOMContentLoaded",function(){var rules=' . wp_json_encode( $rules ) . ';rules.forEach(function(r){document.querySelectorAll(r.selector).forEach(function(root){var w=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);var n;while(n=w.nextNode()){if(n.nodeValue.indexOf(r.source)!==-1){n.nodeValue=n.nodeValue.split(r.source).join(r.translation);}}["placeholder","value","aria-label","aria-placeholder","title"].forEach(function(a){if(root.getAttribute&&root.getAttribute(a)===r.source)root.setAttribute(a,r.translation);root.querySelectorAll&&root.querySelectorAll("["+a+"]").forEach(function(el){if(el.getAttribute(a)===r.source)el.setAttribute(a,r.translation);});});});});});';
		wp_add_inline_script( 'sml-scoped-strings', $js );
	}

	public function shortcode( $atts, $content = '' ) {
		$atts = shortcode_atts( array( 'key' => '' ), $atts, 'sml_string' );
		$source = '' !== $atts['key'] ? (string) $atts['key'] : (string) $content;
		return esc_html( $this->translate_plain_string( $source ) );
	}

	public function page() { ?>
		<div class="wrap sml-admin-wrap"><h1><?php esc_html_e( 'String Translation', 'smart-multilingual' ); ?></h1>
		<p><?php echo esc_html( SML_Admin::ui_text( 'Translate visible theme/plugin strings and HTML attributes without editing the theme.', __( 'Translate visible theme/plugin strings and HTML attributes without editing the theme.', 'smart-multilingual' ) ) ); ?></p>
		<form action="options.php" method="post"><?php settings_fields( 'sml_strings_group' ); ?>
		<?php foreach ( SML_Languages::secondary() as $lang ) : $cfg = SML_Languages::get( $lang ); ?>
		<div class="sml-card"><h2><?php echo esc_html( $cfg['native'] ); ?></h2>
		<h3><?php echo esc_html( SML_Admin::ui_text( 'Language-only strings', __( 'Language-only strings', 'smart-multilingual' ) ) ); ?></h3>
		<p class="description"><?php echo esc_html( sprintf( SML_Admin::ui_text( 'Recommended for brand names and market-specific text. Rules in this box run only when the current language is %s and never affect the source or other languages.', __( 'Recommended for brand names and market-specific text. Rules in this box run only when the current language is %s and never affect the source or other languages.', 'smart-multilingual' ) ), $cfg['native'] ) ); ?><br><code><?php echo esc_html( SML_Admin::ui_text( 'Brand name|||Translated brand name', __( 'Brand name|||Translated brand name', 'smart-multilingual' ) ) ); ?></code></p>
		<textarea name="sml_language_only_strings_<?php echo esc_attr( $lang ); ?>" class="large-text code" rows="7" placeholder="<?php echo esc_attr( SML_Admin::ui_text( 'Brand name|||Translated brand name', __( 'Brand name|||Translated brand name', 'smart-multilingual' ) ) ); ?>"><?php echo esc_textarea( (string) get_option( 'sml_language_only_strings_' . $lang, '' ) ); ?></textarea>
		<h3><?php echo esc_html( SML_Admin::ui_text( 'Frontend strings', __( 'Frontend strings', 'smart-multilingual' ) ) ); ?></h3><p class="description"><code><?php echo esc_html( SML_Admin::ui_text( 'Original text|||Translation', __( 'Original text|||Translation', 'smart-multilingual' ) ) ); ?></code></p>
		<textarea name="sml_string_translations_<?php echo esc_attr( $lang ); ?>" class="large-text code" rows="10" placeholder="<?php echo esc_attr( SML_Admin::ui_text( 'Original text|||Translation', __( 'Original text|||Translation', 'smart-multilingual' ) ) ); ?>"><?php echo esc_textarea( (string) get_option( 'sml_string_translations_' . $lang, '' ) ); ?></textarea>
		<h3><?php echo esc_html( SML_Admin::ui_text( 'HTML attributes', __( 'HTML attributes', 'smart-multilingual' ) ) ); ?></h3><p class="description"><?php echo esc_html( sprintf( SML_Admin::ui_text( 'Format: %s. Supported attributes: %s', __( 'Format: %s. Supported attributes: %s', 'smart-multilingual' ) ), SML_Admin::ui_text( 'placeholder|||Search …|||...', __( 'placeholder|||Search …|||...', 'smart-multilingual' ) ), SML_Admin::ui_text( 'placeholder, title, aria-label, aria-placeholder, value', 'placeholder, title, aria-label, aria-placeholder, value' ) ) ); ?></p>
		<textarea name="sml_attribute_translations_<?php echo esc_attr( $lang ); ?>" class="large-text code" rows="7" placeholder="<?php echo esc_attr( SML_Admin::ui_text( 'placeholder|||Search …|||...', __( 'placeholder|||Search …|||...', 'smart-multilingual' ) ) ); ?>"><?php echo esc_textarea( (string) get_option( 'sml_attribute_translations_' . $lang, '' ) ); ?></textarea>
		<h3><?php echo esc_html( SML_Admin::ui_text( 'Scoped strings', __( 'Scoped strings', 'smart-multilingual' ) ) ); ?></h3><p class="description"><?php echo esc_html( sprintf( SML_Admin::ui_text( 'Add a CSS class to an element, then use: %s', __( 'Add a CSS class to an element, then use: %s', 'smart-multilingual' ) ), '.sml-header-contact|||Contact|||تماس با ما' ) ); ?></p>
		<textarea name="sml_scoped_strings_<?php echo esc_attr( $lang ); ?>" class="large-text code" rows="5" placeholder="<?php echo esc_attr( '.sml-header-contact|||Contact|||...' ); ?>"><?php echo esc_textarea( (string) get_option( 'sml_scoped_strings_' . $lang, '' ) ); ?></textarea>
		<h3><?php echo esc_html( SML_Admin::ui_text( 'Sidebar mapping', __( 'Sidebar mapping', 'smart-multilingual' ) ) ); ?></h3><textarea name="sml_sidebar_map_<?php echo esc_attr( $lang ); ?>" class="large-text code" rows="4" placeholder="<?php echo esc_attr( sprintf( 'sidebar-1|||sidebar-%s', $lang ) ); ?>"><?php echo esc_textarea( (string) get_option( 'sml_sidebar_map_' . $lang, '' ) ); ?></textarea></div>
		<?php endforeach; submit_button(); ?></form></div><?php
	}
}
