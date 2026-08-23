<?php
defined( 'ABSPATH' ) || exit;

/**
 * Per-language typography registry and CSS compiler.
 *
 * Typography is opt-in. When a target is disabled SML emits no typography CSS
 * for it, allowing Elementor, the active theme, and other builders to keep
 * complete control of the source language and every unconfigured target.
 */
final class SML_Typography {
	public static function targets() {
		return array(
			'body'       => array( 'label' => __( 'Body / general text', 'smart-multilingual' ), 'selector' => 'body.%1$s' ),
			'h1'         => array( 'label' => 'H1', 'selector' => 'body.%1$s h1,body.%1$s .sml-type-h1' ),
			'h2'         => array( 'label' => 'H2', 'selector' => 'body.%1$s h2,body.%1$s .sml-type-h2' ),
			'h3'         => array( 'label' => 'H3', 'selector' => 'body.%1$s h3,body.%1$s .sml-type-h3' ),
			'h4'         => array( 'label' => 'H4', 'selector' => 'body.%1$s h4,body.%1$s .sml-type-h4' ),
			'h5'         => array( 'label' => 'H5', 'selector' => 'body.%1$s h5,body.%1$s .sml-type-h5' ),
			'h6'         => array( 'label' => 'H6', 'selector' => 'body.%1$s h6,body.%1$s .sml-type-h6' ),
			'paragraph'  => array( 'label' => __( 'Paragraphs, excerpts and rich text', 'smart-multilingual' ), 'selector' => 'body.%1$s p,body.%1$s p strong,body.%1$s .sml-type-paragraph,body.%1$s .sml-type-paragraph strong,body.%1$s .elementor-widget-text-editor,body.%1$s .elementor-widget-text-editor strong,body.%1$s .excerpt-content,body.%1$s .excerpt-content p,body.%1$s .excerpt-content strong' ),
			'strong'     => array( 'label' => __( 'Strong and bold text', 'smart-multilingual' ), 'selector' => 'body.%1$s strong,body.%1$s b,body.%1$s .sml-type-strong' ),
			'span'       => array( 'label' => __( 'Elementor title and icon-list text', 'smart-multilingual' ), 'selector' => 'body.%1$s span.elementor-title-span,body.%1$s span.elementor-icon-list-text' ),
			'author'     => array( 'label' => __( 'Author and byline metadata', 'smart-multilingual' ), 'selector' => 'body.%1$s .post-author,body.%1$s .post-author span,body.%1$s .post-author a,body.%1$s .author-name,body.%1$s .byline,body.%1$s [rel="author"],body.%1$s .elementor-post-author,body.%1$s .elementor-post-author span,body.%1$s .elementor-post-author a,body.%1$s .elementor-post-date,body.%1$s .elementor-post-date span,body.%1$s .elementor-post-date a,body.%1$s .elementor-post-date time' ),
			'list'       => array( 'label' => __( 'Lists and table text', 'smart-multilingual' ), 'selector' => 'body.%1$s li,body.%1$s dt,body.%1$s dd,body.%1$s th,body.%1$s td,body.%1$s figcaption,body.%1$s blockquote' ),
			'link'       => array( 'label' => __( 'Links', 'smart-multilingual' ), 'selector' => 'body.%1$s a:not([class*="icon"]),body.%1$s .sml-type-link' ),
			'button'     => array( 'label' => __( 'Buttons', 'smart-multilingual' ), 'selector' => 'body.%1$s button,body.%1$s .button,body.%1$s .elementor-button,body.%1$s input[type="submit"],body.%1$s .sml-type-button' ),
			'menu'       => array( 'label' => __( 'Navigation menus', 'smart-multilingual' ), 'selector' => 'body.%1$s nav,body.%1$s nav a,body.%1$s .menu,body.%1$s .menu a,body.%1$s .elementor-nav-menu,body.%1$s .elementor-nav-menu a' ),
			'form'       => array( 'label' => __( 'Forms and placeholders', 'smart-multilingual' ), 'selector' => 'body.%1$s input,body.%1$s textarea,body.%1$s select,body.%1$s label,body.%1$s input::placeholder,body.%1$s textarea::placeholder' ),
			'breadcrumb' => array( 'label' => __( 'Breadcrumbs', 'smart-multilingual' ), 'selector' => 'body.%1$s .breadcrumb,body.%1$s .breadcrumbs,body.%1$s .woocommerce-breadcrumb,body.%1$s [class*="breadcrumb"]' ),
			'sidebar'    => array( 'label' => __( 'Sidebar and widgets', 'smart-multilingual' ), 'selector' => 'body.%1$s #secondary,body.%1$s #secondary .widget,body.%1$s #secondary .widget *:not(i):not(svg):not(path):not([class*="icon"]),body.%1$s .widget-area,body.%1$s .widget-area *:not(i):not(svg):not(path):not([class*="icon"])' ),
			'footer'     => array( 'label' => __( 'Footer', 'smart-multilingual' ), 'selector' => 'body.%1$s #colophon,body.%1$s #colophon *:not(i):not(svg):not(path):not([class*="icon"]),body.%1$s .elementor-location-footer,body.%1$s .elementor-location-footer *:not(i):not(svg):not(path):not([class*="icon"])' ),
			'product'    => array( 'label' => __( 'WooCommerce product content', 'smart-multilingual' ), 'selector' => 'body.%1$s.woocommerce .product,body.%1$s.woocommerce .product :is(h1,h2,h3,h4,h5,h6,p,li,dt,dd,th,td,label,button,a,span,strong,b,em):not(i):not([class*="icon"])' ),
			'slider'     => array( 'label' => __( 'Slider Revolution', 'smart-multilingual' ), 'selector' => 'body.%1$s :is(sr7-txt,sr7-btn,rs-layer,.tp-caption),body.%1$s :is(sr7-txt,sr7-btn,rs-layer,.tp-caption) *:not(i):not(svg):not(path):not([class*="icon"])' ),
			'custom'     => array( 'label' => __( 'Custom marked elements', 'smart-multilingual' ), 'selector' => 'body.%1$s .sml-type-custom,body.%1$s .sml-type-custom *:not(i):not(svg):not(path):not([class*="icon"])' ),
		);
	}

	public static function sanitize_fonts( $input, $allowed_languages ) {
		$output = array();
		foreach ( $allowed_languages as $language ) {
			$rows = isset( $input[ $language ] ) && is_array( $input[ $language ] ) ? $input[ $language ] : array();
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) continue;
				$family = self::sanitize_family( $row['family'] ?? '' );
				if ( '' === $family ) continue;
				$type = in_array( $row['type'] ?? 'existing', array( 'existing', 'variable', 'static' ), true ) ? $row['type'] : 'existing';
				$url  = 'existing' === $type ? '' : esc_url_raw( $row['url'] ?? '' );
				if ( 'existing' !== $type && '' === $url ) continue;
				$min = self::sanitize_weight( $row['weight_min'] ?? 400, 400 );
				$max = 'variable' === $type ? self::sanitize_weight( $row['weight_max'] ?? 900, 900 ) : $min;
				if ( $max < $min ) { $swap = $min; $min = $max; $max = $swap; }
				$output[ $language ][] = array(
					'family'     => $family,
					'type'       => $type,
					'url'        => $url,
					'weight_min' => $min,
					'weight_max' => $max,
					'style'      => in_array( $row['style'] ?? 'normal', array( 'normal', 'italic', 'oblique' ), true ) ? $row['style'] : 'normal',
					'display'    => in_array( $row['display'] ?? 'swap', array( 'auto', 'block', 'swap', 'fallback', 'optional' ), true ) ? $row['display'] : 'swap',
				);
			}
		}
		return $output;
	}

	public static function sanitize_rules( $input, $allowed_languages ) {
		$output  = array();
		$targets = self::targets();
		foreach ( $allowed_languages as $language ) {
			$rules = isset( $input[ $language ] ) && is_array( $input[ $language ] ) ? $input[ $language ] : array();
			foreach ( $targets as $target => $config ) {
				$row = isset( $rules[ $target ] ) && is_array( $rules[ $target ] ) ? $rules[ $target ] : array();
				$output[ $language ][ $target ] = array(
					'enabled'        => empty( $row['enabled'] ) ? 0 : 1,
					'family'         => self::sanitize_family( $row['family'] ?? '' ),
					'fallback'       => in_array( $row['fallback'] ?? 'sans-serif', array( 'sans-serif', 'serif', 'monospace', 'cursive', 'system-ui' ), true ) ? $row['fallback'] : 'sans-serif',
					'weight'         => self::sanitize_weight_value( $row['weight'] ?? '' ),
					'style'          => in_array( $row['style'] ?? '', array( '', 'normal', 'italic', 'oblique' ), true ) ? $row['style'] : '',
					'transform'      => in_array( $row['transform'] ?? '', array( '', 'none', 'uppercase', 'lowercase', 'capitalize' ), true ) ? $row['transform'] : '',
					'decoration'     => in_array( $row['decoration'] ?? '', array( '', 'none', 'underline', 'line-through', 'overline' ), true ) ? $row['decoration'] : '',
					'size_desktop'   => self::sanitize_css_value( $row['size_desktop'] ?? '' ),
					'size_tablet'    => self::sanitize_css_value( $row['size_tablet'] ?? '' ),
					'size_mobile'    => self::sanitize_css_value( $row['size_mobile'] ?? '' ),
					'line_height'    => self::sanitize_css_value( $row['line_height'] ?? '' ),
					'letter_spacing' => self::sanitize_css_value( $row['letter_spacing'] ?? '' ),
					'word_spacing'   => self::sanitize_css_value( $row['word_spacing'] ?? '' ),
				);
			}
		}
		return $output;
	}

	public static function compile_css( $settings ) {
		$fonts   = isset( $settings['typography_fonts'] ) && is_array( $settings['typography_fonts'] ) ? $settings['typography_fonts'] : array();
		$rules   = isset( $settings['typography_rules'] ) && is_array( $settings['typography_rules'] ) ? $settings['typography_rules'] : array();
		$targets = self::targets();
		$css     = '';

		foreach ( $fonts as $language => $rows ) {
			foreach ( (array) $rows as $row ) {
				if ( empty( $row['family'] ) || empty( $row['url'] ) || 'existing' === ( $row['type'] ?? 'existing' ) ) continue;

				/*
				 * Register every uploaded font under BOTH names:
				 * 1) the exact family entered by the administrator, so ordinary theme CSS
				 *    and manual rules such as font-family:"YekanBakh-Medium" work;
				 * 2) SML's language-scoped alias, so two languages may still use different
				 *    files with the same display family without colliding.
				 *
				 * Previous builds only registered the scoped alias. The Typography UI
				 * showed "YekanBakh-Medium", but the browser only knew
				 * "YekanBakh-Medium SML fa". That made manual CSS appear to be ignored
				 * and made theme overrides difficult to diagnose.
				 */
				$public_family = self::css_family( $row['family'] );
				$scoped_family = self::registered_family( $row['family'], $language );
				$url           = esc_url( $row['url'] );
				$ext           = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
				$format        = 'woff2' === $ext ? 'woff2' : ( 'woff' === $ext ? 'woff' : 'woff2' );
				$weight        = 'variable' === ( $row['type'] ?? '' ) ? absint( $row['weight_min'] ) . ' ' . absint( $row['weight_max'] ) : absint( $row['weight_min'] );
				$style         = esc_attr( $row['style'] ?? 'normal' );
				$display       = esc_attr( $row['display'] ?? 'swap' );
				$src           = 'src:local("__sml_force_remote__"),url("' . $url . '") format("' . $format . '");';

				$css .= '@font-face{font-family:"' . $public_family . '";' . $src . 'font-weight:' . $weight . ';font-style:' . $style . ';font-display:' . $display . ';}';
				if ( 0 !== strcasecmp( $public_family, $scoped_family ) ) {
					$css .= '@font-face{font-family:"' . $scoped_family . '";' . $src . 'font-weight:' . $weight . ';font-style:' . $style . ';font-display:' . $display . ';}';
				}
			}
		}

		foreach ( $rules as $language => $language_rules ) {
			$body_class = 'sml-lang-' . sanitize_html_class( $language );
			$font_rows  = $fonts[ $language ] ?? array();
			/* Themes are not required to call body_class(). Use a composite scope so
			 * the same rules can match SML's <html> bootstrap metadata too. */
			$scope = ':is(body.' . $body_class . ',html.' . $body_class . ' body,html[data-sml-lang="' . sanitize_html_class( $language ) . '"] body)';

			/* A theme such as Woodmart assigns its own font-family directly to
			 * headings, widgets and buttons, so a font on <body> alone never reaches
			 * those descendants. When Body / general text is explicitly enabled,
			 * propagate only its family (not its size/weight) to ordinary text nodes.
			 * More specific typography targets are emitted afterwards and still win. */
			$body_rule = isset( $language_rules['body'] ) ? (array) $language_rules['body'] : array();
			if ( ! empty( $body_rule['enabled'] ) && ! empty( $body_rule['family'] ) ) {
				$stack = self::font_stack( $body_rule, $language, $font_rows );
				if ( '' !== $stack ) {
					/* Set the language body itself as well as concrete text descendants.
					 * Woodmart/Elementor frequently assign their own font on descendants,
					 * therefore the descendant rule remains important and intentionally
					 * uses !important. */
					$css .= $scope . '{font-family:' . $stack . '!important;}';
					$text_selector = $scope . ' :is(h1,h2,h3,h4,h5,h6,p,a,li,dt,dd,th,td,button,input,textarea,select,label,blockquote,figcaption,strong,b,em,small,.elementor-heading-title,.elementor-widget-text-editor,.elementor-button-text,.wd-title,.widget-title,.wd-entities-title,.product_title,.hero-title,.hero-title-blue,.hero-title-red):not(i):not(svg):not(path):not([class*="icon"])';
					$css .= $text_selector . '{font-family:' . $stack . '!important;}';

					/* Woodmart resolves most typography through inherited CSS custom
					 * properties. Defining the same variables on the language body keeps
					 * the theme's sizing/weight system intact while swapping the family. */
					$css .= $scope . '{--wd-text-font:' . $stack . ';--wd-title-font:' . $stack . ';--wd-entities-title-font:' . $stack . ';--wd-widget-title-font:' . $stack . ';--wd-header-el-font:' . $stack . ';--wd-alternative-font:' . $stack . ';}';
				}
			}

			foreach ( $targets as $target => $config ) {
				$row = isset( $language_rules[ $target ] ) ? $language_rules[ $target ] : array();
				if ( empty( $row['enabled'] ) ) continue;
				$declarations = self::declarations( $row, $language, $font_rows );
				if ( '' === $declarations ) continue;
				$selector = sprintf( $config['selector'], $body_class );
				$selector = str_replace( 'body.' . $body_class, $scope, $selector );
				$css     .= $selector . '{' . $declarations . '}';
				if ( ! empty( $row['size_tablet'] ) ) $css .= '@media(max-width:1024px){' . $selector . '{font-size:' . $row['size_tablet'] . '!important;}}';
				if ( ! empty( $row['size_mobile'] ) ) $css .= '@media(max-width:767px){' . $selector . '{font-size:' . $row['size_mobile'] . '!important;}}';
			}
		}

		return $css;
	}


	/**
	 * Compile a request-local typography layer for one already-detected language.
	 *
	 * Unlike compile_css(), selectors here intentionally do not depend on a class
	 * on <body>. The method is only called while rendering the current frontend
	 * request, so a Persian /fa/ response can safely receive Persian typography
	 * even when a custom theme omits body_class() and language_attributes().
	 */
	public static function compile_active_language_css( $settings, $language ) {
		$language = sanitize_key( $language );
		if ( ! $language ) return '';

		$fonts = isset( $settings['typography_fonts'] ) && is_array( $settings['typography_fonts'] ) ? $settings['typography_fonts'] : array();
		$rules = isset( $settings['typography_rules'] ) && is_array( $settings['typography_rules'] ) ? $settings['typography_rules'] : array();
		if ( empty( $rules[ $language ] ) || ! is_array( $rules[ $language ] ) ) return '';

		$language_rules = $rules[ $language ];
		$font_rows      = $fonts[ $language ] ?? array();
		$targets        = self::targets();
		$css            = '';

		$body_rule = isset( $language_rules['body'] ) ? (array) $language_rules['body'] : array();
		if ( ! empty( $body_rule['enabled'] ) && ! empty( $body_rule['family'] ) ) {
			$stack = self::font_stack( $body_rule, $language, $font_rows );
			if ( '' !== $stack ) {
				$css .= 'body{font-family:' . $stack . '!important;}';
				$css .= 'body :is(h1,h2,h3,h4,h5,h6,p,a,li,dt,dd,th,td,button,input,textarea,select,label,blockquote,figcaption,strong,b,em,small,.elementor-heading-title,.elementor-widget-text-editor,.elementor-button-text,.wd-title,.widget-title,.wd-entities-title,.product_title,.hero-title,.hero-title-blue,.hero-title-red):not(i):not(svg):not(path):not([class*="icon"]){font-family:' . $stack . '!important;}';
				$css .= 'body{--wd-text-font:' . $stack . ';--wd-title-font:' . $stack . ';--wd-entities-title-font:' . $stack . ';--wd-widget-title-font:' . $stack . ';--wd-header-el-font:' . $stack . ';--wd-alternative-font:' . $stack . ';}';
			}
		}

		$body_class = 'sml-lang-' . sanitize_html_class( $language );
		foreach ( $targets as $target => $config ) {
			$row = isset( $language_rules[ $target ] ) ? (array) $language_rules[ $target ] : array();
			if ( empty( $row['enabled'] ) ) continue;
			$declarations = self::declarations( $row, $language, $font_rows );
			if ( '' === $declarations ) continue;

			$selector = sprintf( $config['selector'], $body_class );
			$selector = str_replace( 'body.' . $body_class, 'body', $selector );
			$css     .= $selector . '{' . $declarations . '}';
			if ( ! empty( $row['size_tablet'] ) ) $css .= '@media(max-width:1024px){' . $selector . '{font-size:' . $row['size_tablet'] . '!important;}}';
			if ( ! empty( $row['size_mobile'] ) ) $css .= '@media(max-width:767px){' . $selector . '{font-size:' . $row['size_mobile'] . '!important;}}';
		}

		return $css;
	}

	private static function font_stack( $row, $language, $font_rows ) {
		if ( empty( $row['family'] ) ) return '';
		$public_family = self::css_family( $row['family'] );
		$family_stack  = '"' . $public_family . '"';

		foreach ( (array) $font_rows as $font_row ) {
			if ( 'existing' !== ( $font_row['type'] ?? 'existing' ) && 0 === strcasecmp( $font_row['family'] ?? '', $row['family'] ) ) {
				$scoped_family = self::registered_family( $row['family'], $language );
				/* Prefer the scoped alias but keep the public family immediately after
				 * it. Both aliases are registered by compile_css(), so this also makes
				 * manually-authored theme CSS interoperable with SML. */
				$family_stack = '"' . $scoped_family . '","' . $public_family . '"';
				break;
			}
		}

		$fallback = in_array( $row['fallback'] ?? 'sans-serif', array( 'sans-serif', 'serif', 'monospace', 'cursive', 'system-ui' ), true ) ? $row['fallback'] : 'sans-serif';
		return $family_stack . ',' . $fallback;
	}

	private static function declarations( $row, $language, $font_rows ) {
		$css = '';
		if ( ! empty( $row['family'] ) ) {
			$stack = self::font_stack( $row, $language, $font_rows );
			if ( '' !== $stack ) $css .= 'font-family:' . $stack . '!important;';
		}
		if ( ! empty( $row['weight'] ) ) $css .= 'font-weight:' . $row['weight'] . '!important;';
		if ( ! empty( $row['style'] ) ) $css .= 'font-style:' . $row['style'] . '!important;';
		if ( ! empty( $row['transform'] ) ) $css .= 'text-transform:' . $row['transform'] . '!important;';
		if ( ! empty( $row['decoration'] ) ) $css .= 'text-decoration:' . $row['decoration'] . '!important;';
		if ( ! empty( $row['size_desktop'] ) ) $css .= 'font-size:' . $row['size_desktop'] . '!important;';
		if ( ! empty( $row['line_height'] ) ) $css .= 'line-height:' . $row['line_height'] . '!important;';
		if ( ! empty( $row['letter_spacing'] ) ) $css .= 'letter-spacing:' . $row['letter_spacing'] . '!important;';
		if ( ! empty( $row['word_spacing'] ) ) $css .= 'word-spacing:' . $row['word_spacing'] . '!important;';
		return $css;
	}

	private static function sanitize_family( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		return preg_replace( '/[{};<>\\\\]/', '', $value );
	}

	private static function sanitize_weight( $value, $fallback ) {
		$value = absint( $value );
		return $value >= 1 && $value <= 1000 ? $value : $fallback;
	}

	private static function sanitize_weight_value( $value ) {
		if ( '' === trim( (string) $value ) ) return '';
		return (string) self::sanitize_weight( $value, 400 );
	}

	private static function sanitize_css_value( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $value ) return '';
		return preg_match( '/^-?(?:\d+|\d*\.\d+)(?:px|rem|em|%|vw|vh|ch|ex)?$/i', $value ) ? $value : '';
	}

	private static function css_family( $value ) {
		return str_replace( array( '"', "'", '\\' ), '', (string) $value );
	}

	private static function registered_family( $family, $language ) {
		return self::css_family( $family ) . ' SML ' . sanitize_html_class( $language );
	}
}
