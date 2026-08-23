<?php
defined( 'ABSPATH' ) || exit;

final class SML_Elementor {
	private static $instance;

	/**
	 * Elementor widgets whose visible content can be aligned for an RTL language.
	 *
	 * @var string[]
	 */
	private $text_widgets = array(
		'heading',
		'text-editor',
		'button',
		'icon-list',
		'icon-box',
		'image-box',
		'testimonial',
		'toggle',
		'accordion',
		'tabs',
		'alert',
		'counter',
		'progress',
		'blockquote',
		'star-rating',
		'price-list',
		'price-table',
		'call-to-action',
		'animated-headline',
		'form',
		'login',
		'search-form',
		'post-info',
		'posts',
		'portfolio',
		'archive-posts',
		'woocommerce-breadcrumb',
		'woocommerce-product-title',
		'woocommerce-product-content',
		'woocommerce-product-short-description',
		'woocommerce-product-add-to-cart',
		'woocommerce-product-data-tabs',
		'woocommerce-product-meta',
	);

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action( 'elementor/widgets/register', array( $this, 'register_widget' ) );
		if ( SML_Compatibility::enabled( 'elementor_rtl' ) || ( class_exists( 'SML_Plugin' ) && SML_Plugin::is_woodmart_site() ) ) {
			add_action( 'elementor/frontend/before_render', array( $this, 'prepare_rtl_element' ), 10, 1 );
		}
	}

	public function register_widget( $widgets_manager ) {
		if ( ! class_exists( '\\Elementor\\Widget_Base' ) || ! is_object( $widgets_manager ) ) {
			return;
		}

		require_once SML_DIR . 'includes/widgets/class-sml-elementor-switcher-widget.php';

		if ( class_exists( 'SML_Elementor_Switcher_Widget' ) ) {
			$widgets_manager->register( new SML_Elementor_Switcher_Widget() );
		}
	}

	/**
	 * Adds RTL-specific render classes without modifying saved Elementor JSON.
	 *
	 * @param object $element Elementor element instance.
	 * @return void
	 */
	public function prepare_rtl_element( $element ) {
		if ( ! $this->is_rtl_request() || ! is_object( $element ) || ! method_exists( $element, 'add_render_attribute' ) ) {
			return;
		}

		$name = method_exists( $element, 'get_name' ) ? (string) $element->get_name() : '';
		$type = method_exists( $element, 'get_type' ) ? (string) $element->get_type() : '';

		$element->add_render_attribute( '_wrapper', 'class', 'sml-elementor-rtl' );
		$element->add_render_attribute( '_wrapper', 'dir', 'rtl' );

		if ( 'widget' === $type ) {
			if ( $this->is_text_widget( $name ) ) {
				if ( $this->has_center_alignment( $element ) ) {
					$element->add_render_attribute( '_wrapper', 'class', 'sml-preserve-center' );
				} else {
					$element->add_render_attribute( '_wrapper', 'class', 'sml-rtl-text-widget' );
				}
			}

			if ( false !== strpos( $name, 'icon' ) || in_array( $name, array( 'button', 'form', 'search-form' ), true ) ) {
				$element->add_render_attribute( '_wrapper', 'class', 'sml-rtl-icon-widget' );
			}
		}

		if ( in_array( $type, array( 'container', 'section', 'column' ), true ) ) {
			$element->add_render_attribute( '_wrapper', 'class', 'sml-rtl-layout-element' );
			$this->maybe_add_reverse_class( $element );
		}
	}

	private function is_rtl_request() {
		if ( ! class_exists( 'SML_Plugin' ) ) return false; $cfg = SML_Languages::get( SML_Plugin::instance()->current_language() ); return 'rtl' === $cfg['dir'];
	}

	private function is_text_widget( $name ) {
		if ( in_array( $name, $this->text_widgets, true ) ) {
			return true;
		}

		$text_markers = array( 'text', 'title', 'heading', 'content', 'description', 'menu', 'nav', 'faq', 'review' );
		foreach ( $text_markers as $marker ) {
			if ( false !== strpos( $name, $marker ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Preserve Elementor widgets that are explicitly centered in the source
	 * language. RTL conversion should mirror left-aligned content, but it must
	 * not turn intentional centered footer blocks into right-aligned blocks.
	 *
	 * @param object $element Elementor element instance.
	 * @return bool
	 */
	private function has_center_alignment( $element ) {
		if ( ! method_exists( $element, 'get_settings_for_display' ) ) {
			return false;
		}

		$css_classes = (string) $element->get_settings_for_display( '_css_classes' );
		if ( false !== strpos( ' ' . $css_classes . ' ', ' sml-keep-center ' ) ) {
			return true;
		}

		$alignment_keys = array(
			'align',
			'text_align',
			'alignment',
			'title_align',
			'content_align',
			'button_align',
		);

		foreach ( $alignment_keys as $key ) {
			$value = $element->get_settings_for_display( $key );
			if ( is_array( $value ) ) {
				$value = isset( $value['value'] ) ? $value['value'] : ( isset( $value['size'] ) ? $value['size'] : '' );
			}

			if ( 'center' === strtolower( trim( (string) $value ) ) ) {
				return true;
			}
		}

		return false;
	}

	private function maybe_add_reverse_class( $element ) {
		$css_classes = '';
		if ( method_exists( $element, 'get_settings_for_display' ) ) {
			$css_classes = (string) $element->get_settings_for_display( '_css_classes' );
		}
		if ( false !== strpos( ' ' . $css_classes . ' ', ' sml-keep-layout ' ) || false !== strpos( ' ' . $css_classes . ' ', ' sml-keep-direction ' ) ) {
			return;
		}

		$settings = SML_Plugin::settings();
		if ( empty( $settings['rtl_reverse_columns'] ) ) {
			return;
		}

		$direction = '';
		if ( method_exists( $element, 'get_settings_for_display' ) ) {
			$direction = $element->get_settings_for_display( 'flex_direction' );
		}

		if ( is_array( $direction ) ) {
			$direction = isset( $direction['size'] ) ? $direction['size'] : '';
		}

		if ( in_array( $direction, array( '', 'row' ), true ) ) {
			$element->add_render_attribute( '_wrapper', 'class', 'sml-rtl-row-reverse' );
		}
	}
}
