<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'sml_settings', array() );
if ( empty( $settings['delete_data'] ) ) {
	return;
}

$languages = get_option( 'sml_language_registry', array() );

$options = array(
	'sml_settings',
	'sml_language_registry',
	'sml_languages', // Pre-registry legacy option.
	'sml_string_translations',
	'sml_sidebar_map',
	'sml_revslider_mappings',
	'sml_attribute_labels',
	'sml_site_profile',
	'sml_db_version',
	'sml_rewrite_version',
	'sml_rewrite_flush_required',
	'sml_term_relationship_sync_version',
);
foreach ( $options as $option ) {
	delete_option( $option );
}
foreach ( array_keys( is_array( $languages ) ? $languages : array() ) as $language_code ) {
	foreach ( array( 'sml_string_translations_', 'sml_language_only_strings_', 'sml_attribute_translations_', 'sml_scoped_strings_', 'sml_sidebar_map_' ) as $prefix ) {
		delete_option( $prefix . sanitize_key( $language_code ) );
	}
}
delete_post_meta_by_key( '_sml_language' );
delete_post_meta_by_key( '_sml_translation_group' );
delete_post_meta_by_key( '_sml_source_path' );
delete_post_meta_by_key( '_sml_source_modified' );
delete_metadata( 'term', 0, '_sml_language', '', true );
delete_metadata( 'term', 0, '_sml_translation_group', '', true );
delete_metadata( 'term', 0, '_sml_source_path', '', true );

// Per-language media metadata.
global $wpdb;
$media_meta_like = $wpdb->esc_like( '_sml_media_' ) . '%';
$wpdb->query(
	$wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $media_meta_like ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
