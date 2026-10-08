<?php
defined( 'ABSPATH' ) || exit;

final class SML_Migrations {
	const DB_VERSION_OPTION = 'sml_db_version';

	public static function run() {
		$installed = (string) get_option( self::DB_VERSION_OPTION, '' );
		$saved_registry = get_option( SML_Languages::OPTION_KEY, false );
		$registry_valid = is_array( $saved_registry ) && ! empty( $saved_registry );
		if ( SML_VERSION === $installed && $registry_valid ) {
			return;
		}

		$had_settings = false !== get_option( SML_Plugin::OPTION_KEY, false );
		$had_registry = $registry_valid;

		if ( ! $had_registry ) {
			$legacy_registry = get_option( 'sml_languages', array() );
			$registry        = self::migrate_legacy_registry( $legacy_registry );
			$registry        = $registry ?: SML_Languages::initial_registry();
			if ( false === $saved_registry ) {
				add_option( SML_Languages::OPTION_KEY, $registry, '', false );
			} else {
				update_option( SML_Languages::OPTION_KEY, $registry, false );
			}
		} else {
			// Preserve every existing language exactly; updates never inject a language.
			$registry = $saved_registry;
		}

		$settings = wp_parse_args( (array) get_option( SML_Plugin::OPTION_KEY, array() ), SML_Plugin::defaults() );
		$default  = SML_Languages::default_code();
		$settings['default_language'] = $default;

		/* Keep enabled/switcher lists in sync with a repaired baseline registry. */
		$registry_codes = array_keys( SML_Languages::registry() );
		if ( $had_settings ) {
			$settings['enabled_languages'] = array_values( array_unique( array_merge( (array) ( $settings['enabled_languages'] ?? array() ), $registry_codes ) ) );
			$settings['switcher_languages'] = array_values( array_unique( array_merge( (array) ( $settings['switcher_languages'] ?? array() ), $registry_codes ) ) );
		}

		if ( ! $had_settings ) {
			$settings['enabled_languages']  = array_keys( SML_Languages::initial_registry() );
			$settings['switcher_languages'] = $settings['enabled_languages'];
			$settings['compatibility_profiles'] = array_fill_keys( array_keys( SML_Compatibility::profiles() ), 0 );
		} elseif ( in_array( $installed, array( '', '1.0.0', '1.0.1' ), true ) ) {
			/*
			 * Keep every legacy option and translation, but do not silently enable
			 * render-time adapters. On a large WooCommerce installation, historical
			 * list/term filters can turn one page view into thousands of metadata
			 * lookups. Version 1.0.0 enabled these adapters during legacy migration,
			 * so 1.0.1 also repairs installations that already recorded that version.
			 * Site owners can enable each adapter explicitly after the safe upgrade.
			 */
			$settings['compatibility_profiles'] = array_fill_keys( array_keys( SML_Compatibility::profiles() ), 0 );
		}

		update_option( SML_Plugin::OPTION_KEY, $settings, false );
		update_option( self::DB_VERSION_OPTION, SML_VERSION, false );
		update_option( 'sml_rewrite_flush_required', 1, false );

		// Never launch the historical all-post taxonomy repair automatically.
		// No content is deleted; existing term relationships remain untouched.
		update_option( 'sml_term_relationship_sync_version', '1.0.4-manual', false );
	}

	private static function migrate_legacy_registry( $legacy ) {
		if ( ! is_array( $legacy ) || ! $legacy ) return array();
		$default = '';
		foreach ( $legacy as $code => $config ) {
			if ( is_array( $config ) && '' === trim( (string) ( $config['prefix'] ?? '' ) ) ) {
				$default = sanitize_key( $code );
				break;
			}
		}
		if ( ! $default ) $default = sanitize_key( (string) array_key_first( $legacy ) );
		$output = array();
		foreach ( $legacy as $code => $config ) {
			$code = sanitize_key( $code );
			if ( ! $code || ! is_array( $config ) ) continue;
			$output[ $code ] = SML_Languages::sanitize_language( $code, $config, $code === $default );
		}
		return $output;
	}
}
