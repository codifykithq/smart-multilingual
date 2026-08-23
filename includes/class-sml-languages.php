<?php
defined( 'ABSPATH' ) || exit;

/**
 * Language registry and source-language discovery.
 *
 * Version 1.0 no longer assumes that English is the source language. Existing
 * installations remain English-first because their saved registry already has
 * an empty English prefix. Fresh installations use the WordPress site locale
 * as the source language and add Persian as the first secondary language.
 */
final class SML_Languages {
	const OPTION_KEY = 'sml_language_registry';

	private static $initial_registry_cache = null;
	private static $registry_cache = null;
	private static $default_code_cache = null;

	public static function builtins() {
		return array(
			'en' => array( 'name' => 'English', 'native' => 'English', 'locale' => 'en_US', 'prefix' => 'en', 'dir' => 'ltr', 'hreflang' => 'en', 'enabled' => 1 ),
			'fa' => array( 'name' => 'Persian', 'native' => 'فارسی', 'locale' => 'fa_IR', 'prefix' => 'fa', 'dir' => 'rtl', 'hreflang' => 'fa-IR', 'enabled' => 1 ),
			'tr' => array( 'name' => 'Turkish', 'native' => 'Türkçe', 'locale' => 'tr_TR', 'prefix' => 'tr', 'dir' => 'ltr', 'hreflang' => 'tr-TR', 'enabled' => 1 ),
			'ar' => array( 'name' => 'Arabic', 'native' => 'العربية', 'locale' => 'ar', 'prefix' => 'ar', 'dir' => 'rtl', 'hreflang' => 'ar', 'enabled' => 1 ),
			'de' => array( 'name' => 'German', 'native' => 'Deutsch', 'locale' => 'de_DE', 'prefix' => 'de', 'dir' => 'ltr', 'hreflang' => 'de-DE', 'enabled' => 1 ),
		);
	}

	public static function initial_registry() {
		if ( null !== self::$initial_registry_cache ) {
			return self::$initial_registry_cache;
		}
		/*
		 * Read the site's source locale without calling determine_locale() or
		 * get_locale(). Both functions apply WordPress' `locale` filter. This class
		 * is itself used by SML_Plugin::filter_locale(), so calling either function
		 * here when the registry option is missing creates an infinite recursion:
		 * locale -> registry -> initial_registry -> locale. On production hosts the
		 * exhausted PHP worker is commonly surfaced as HTTP 503.
		 *
		 * WPLANG is the unfiltered, persisted source for the site locale. WordPress
		 * uses en_US when it is empty, which is the same fallback used here.
		 */
		$locale = trim( (string) get_option( 'WPLANG', '' ) );
		if ( '' === $locale && defined( 'WPLANG' ) ) {
			$locale = trim( (string) WPLANG );
		}
		if ( '' === $locale ) {
			$locale = 'en_US';
		}
		$code   = sanitize_key( strtolower( strtok( str_replace( '-', '_', (string) $locale ), '_' ) ) );
		if ( ! $code ) {
			$code = 'en';
		}

		$presets = self::builtins();
		$source  = isset( $presets[ $code ] ) ? $presets[ $code ] : array(
			'name'     => strtoupper( $code ),
			'native'   => strtoupper( $code ),
			'locale'   => $locale ?: $code,
			'prefix'   => $code,
			'dir'      => in_array( $code, array( 'ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'ku', 'nqo', 'ps', 'sd', 'ug', 'ur', 'yi' ), true ) ? 'rtl' : 'ltr',
			'hreflang' => str_replace( '_', '-', $locale ?: $code ),
			'enabled'  => 1,
		);
		$source['locale']   = $locale ?: $source['locale'];
		$source['hreflang'] = str_replace( '_', '-', $locale ?: $source['hreflang'] );
		$source['prefix'] = '';

		$registry = array( $code => $source );
		if ( 'fa' !== $code ) {
			$registry['fa'] = $presets['fa'];
		}
		self::$initial_registry_cache = $registry;
		return self::$initial_registry_cache;
	}

	public static function registry() {
		if ( null !== self::$registry_cache ) {
			return self::$registry_cache;
		}

		$saved = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			self::$registry_cache = self::initial_registry();
			return self::$registry_cache;
		}

		$default  = self::discover_default_from_registry( $saved );
		$registry = array();
		foreach ( $saved as $code => $cfg ) {
			$code = sanitize_key( $code );
			if ( ! $code || ! is_array( $cfg ) ) {
				continue;
			}
			$registry[ $code ] = self::sanitize_language( $code, $cfg, $code === $default );
		}

		if ( empty( $registry ) ) {
			self::$registry_cache = self::initial_registry();
			return self::$registry_cache;
		}
		self::$registry_cache = $registry;
		return self::$registry_cache;
	}

	public static function default_code() {
		if ( null !== self::$default_code_cache ) {
			return self::$default_code_cache;
		}

		$settings = get_option( 'sml_settings', array() );
		if ( is_array( $settings ) && ! empty( $settings['default_language'] ) ) {
			$requested = sanitize_key( $settings['default_language'] );
			$saved     = get_option( self::OPTION_KEY, array() );
			if ( is_array( $saved ) && isset( $saved[ $requested ] ) ) {
				self::$default_code_cache = $requested;
				return self::$default_code_cache;
			}
		}
		self::$default_code_cache = self::discover_default_from_registry( get_option( self::OPTION_KEY, array() ) );
		return self::$default_code_cache;
	}

	/** Reset request-local caches after an administrator changes the registry. */
	public static function clear_cache() {
		self::$initial_registry_cache = null;
		self::$registry_cache         = null;
		self::$default_code_cache     = null;
	}

	private static function discover_default_from_registry( $registry ) {
		if ( is_array( $registry ) ) {
			foreach ( $registry as $code => $cfg ) {
				if ( is_array( $cfg ) && '' === trim( (string) ( $cfg['prefix'] ?? '' ) ) ) {
					return sanitize_key( $code );
				}
			}
			if ( isset( $registry['en'] ) ) {
				return 'en';
			}
			$key = key( $registry );
			if ( $key ) {
				return sanitize_key( $key );
			}
		}
		$initial = self::initial_registry();
		return sanitize_key( key( $initial ) );
	}

	public static function sanitize_language( $code, $cfg, $is_default = null ) {
		if ( null === $is_default ) {
			$is_default = self::default_code() === $code;
		}
		$prefix = $is_default ? '' : sanitize_title( $cfg['prefix'] ?? $code );
		return array(
			'name'     => sanitize_text_field( $cfg['name'] ?? strtoupper( $code ) ),
			'native'   => sanitize_text_field( $cfg['native'] ?? ( $cfg['name'] ?? strtoupper( $code ) ) ),
			'locale'   => sanitize_text_field( $cfg['locale'] ?? $code ),
			'prefix'   => $prefix,
			'dir'      => 'rtl' === ( $cfg['dir'] ?? 'ltr' ) ? 'rtl' : 'ltr',
			'hreflang' => sanitize_text_field( $cfg['hreflang'] ?? $code ),
			'enabled'  => empty( $cfg['enabled'] ) ? 0 : 1,
		);
	}

	public static function get( $code ) {
		$all      = self::registry();
		$fallback = self::default_code();
		return isset( $all[ $code ] ) ? $all[ $code ] : ( $all[ $fallback ] ?? reset( $all ) );
	}

	public static function enabled() {
		$registry = self::registry();
		$default  = self::default_code();
		$settings = class_exists( 'SML_Plugin' ) ? SML_Plugin::settings() : array();
		$selected = (array) ( $settings['enabled_languages'] ?? array_keys( $registry ) );
		$out      = array();
		foreach ( $registry as $code => $cfg ) {
			if ( ! empty( $cfg['enabled'] ) && in_array( $code, $selected, true ) ) {
				$out[] = $code;
			}
		}
		if ( ! in_array( $default, $out, true ) && isset( $registry[ $default ] ) ) {
			array_unshift( $out, $default );
		}
		return array_values( array_unique( $out ) );
	}

	public static function switcher_enabled() {
		$settings = SML_Plugin::settings();
		return array_values( array_intersect( self::enabled(), array_map( 'sanitize_key', (array) ( $settings['switcher_languages'] ?? self::enabled() ) ) ) );
	}

	public static function is_enabled( $code ) {
		return in_array( $code, self::enabled(), true );
	}

	public static function is_default( $code ) {
		return self::default_code() === sanitize_key( $code );
	}

	public static function secondary() {
		return array_values( array_diff( self::enabled(), array( self::default_code() ) ) );
	}
}
