<?php
defined( 'ABSPATH' ) || exit;

/**
 * Opt-in compatibility layer.
 *
 * Core never changes a theme's alignment or layout. Profiles are explicit,
 * independently switchable adapters for integrations that need render-time
 * behavior beyond language routing.
 */
final class SML_Compatibility {
	public static function profiles() {
		return array(
			'taxonomy_routes' => array(
				'label'       => __( 'Translated taxonomy routes', 'smart-multilingual' ),
				'description' => __( 'Registers language-prefixed routes for public categories, tags and custom taxonomies. Enable it only when translated term archives are used.', 'smart-multilingual' ),
				'color'       => 'green',
			),
			'media_translation' => array(
				'label'       => __( 'Translated media metadata', 'smart-multilingual' ),
				'description' => __( 'Applies language-specific image alt text, titles, captions and descriptions on the frontend.', 'smart-multilingual' ),
				'color'       => 'green',
			),
			'content_queries' => array(
				'label'       => __( 'Language-aware lists and archives', 'smart-multilingual' ),
				'description' => __( 'Filters post lists, widgets, archives and public taxonomy terms to the current language. Enable only when the site needs automatic list filtering.', 'smart-multilingual' ),
				'color'       => 'blue',
			),
			'elementor_rtl' => array(
				'label'       => __( 'Elementor RTL layout helper', 'smart-multilingual' ),
				'description' => __( 'Adds RTL render classes and mirrors explicitly supported Elementor layouts. Disabled by default.', 'smart-multilingual' ),
				'color'       => 'blue',
			),
			'hfe_templates' => array(
				'label'       => __( 'Elementor Header & Footer Builder templates', 'smart-multilingual' ),
				'description' => __( 'Selects a translated HFE/UAE header or footer template for the current language.', 'smart-multilingual' ),
				'color'       => 'green',
			),
			'persian_dates' => array(
				'label'       => __( 'Persian date integration', 'smart-multilingual' ),
				'description' => __( 'Uses Parsi Date output on Persian pages when the dependency is available.', 'smart-multilingual' ),
				'color'       => 'green',
			),
			'legacy_093_layout' => array(
				'label'       => __( 'Legacy 0.9.x compatibility bundle', 'smart-multilingual' ),
				'description' => __( 'Preserves historical layout fixes on upgraded installations. Do not enable it on a new site.', 'smart-multilingual' ),
				'color'       => 'red',
			),
		);
	}

	public static function enabled( $profile ) {
		/*
		 * Read the saved flag directly. Calling SML_Plugin::settings() here builds
		 * translated profile labels, which can invoke gettext while gettext itself
		 * is being registered. Direct option access is re-entry safe.
		 */
		$settings = (array) get_option( SML_Plugin::OPTION_KEY, array() );
		return ! empty( $settings['compatibility_profiles'][ sanitize_key( $profile ) ] );
	}

	public static function sanitize( $input ) {
		$output = array();
		foreach ( self::profiles() as $slug => $profile ) {
			$output[ $slug ] = empty( $input[ $slug ] ) ? 0 : 1;
		}
		return $output;
	}
}
