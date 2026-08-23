<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
	return;
}

final class SML_Elementor_Switcher_Widget extends \Elementor\Widget_Base {
	public function get_name() {
		return 'sml-language-switcher';
	}

	public function get_title() {
		return esc_html__( 'Language Switcher', 'smart-multilingual' );
	}

	public function get_icon() {
		return 'eicon-global-settings';
	}

	public function get_categories() {
		return array( 'general' );
	}

	protected function register_controls() {
		$this->start_controls_section(
			'content',
			array(
				'label' => esc_html__( 'Switcher', 'smart-multilingual' ),
			)
		);

		$this->add_control(
			'label_style',
			array(
				'label'   => esc_html__( 'Labels', 'smart-multilingual' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'short',
				'options' => array(
					'short'  => esc_html__( 'Language codes', 'smart-multilingual' ),
					'full'   => esc_html__( 'Full language names', 'smart-multilingual' ),
					'native' => esc_html__( 'Native names', 'smart-multilingual' ),
				),
			)
		);

		$this->add_control( 'layout', array( 'label' => esc_html__( 'Layout', 'smart-multilingual' ), 'type' => \Elementor\Controls_Manager::SELECT, 'default' => 'dropdown', 'options' => array( 'dropdown' => 'Dropdown', 'list' => 'Inline list' ) ) );
		$this->end_controls_section();
	}

	protected function render() {
		$settings    = $this->get_settings_for_display();
		$label_style = isset( $settings['label_style'] ) && in_array( $settings['label_style'], array( 'short', 'full', 'native' ), true )
			? $settings['label_style']
			: 'native';

		$layout = isset( $settings['layout'] ) && in_array( $settings['layout'], array( 'dropdown', 'list' ), true ) ? $settings['layout'] : 'dropdown';
		echo do_shortcode( '[sml_language_switcher style="' . esc_attr( $label_style ) . '" layout="' . esc_attr( $layout ) . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
