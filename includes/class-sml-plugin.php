<?php
defined( 'ABSPATH' ) || exit;

final class SML_Plugin {
	const META_LANG        = '_sml_language';
	const META_GROUP       = '_sml_translation_group';
	const META_SOURCE_PATH = '_sml_source_path';
	const OPTION_KEY       = 'sml_settings';
	const REWRITE_VERSION  = '1.0.18';

	private static $instance;
	private static $settings_cache = null;
	private $current_language = '';
	private $default_language = '';
	private $current_locale = '';
	private $locale_filter_registered = false;
	private $resolved_language_request = false;
	private $booted = false;

	public static function instance() {
		if ( ! self::$instance ) {
			/*
			 * Assign the singleton before registering any hook. Some WordPress filters
			 * (notably gettext) can run while integrations are being registered. The
			 * old constructor assigned the instance only after construction finished,
			 * so a filter calling instance() recursively created objects until PHP ran
			 * out of memory and the web server returned 503.
			 */
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function __construct() {}

	private function boot() {
		if ( $this->booted ) return;
		$this->booted = true;
		$this->default_language = SML_Languages::default_code();
		$this->current_language = $this->early_request_language( $this->default_language );
		$this->prime_current_locale();

		/*
		 * HARD ADMIN BOUNDARY.
		 *
		 * wp-admin must be completely owned by WordPress and the current user's
		 * locale.  Previous builds continued registering frontend multilingual
		 * hooks during dashboard requests and then tried to guard each callback.
		 * That is fragile because WordPress determines parts of the admin locale,
		 * RTL state and screen markup at different bootstrap stages.
		 *
		 * In the dashboard we therefore register ONLY the hooks that are actually
		 * needed by Smart Multilingual's admin screens. No frontend locale, query,
		 * permalink, body class, language attributes, canonical or rendering hooks
		 * are registered at all.
		 */
		if ( $this->is_dashboard_request() ) {
			/*
			 * CRITICAL ADMIN LOCALE BOUNDARY.
			 *
			 * Active plugins are loaded before WordPress calls load_default_textdomain()
			 * and before the global WP_Locale object is constructed. Registering gettext
			 * or gettext_with_context filters during plugins_loaded can therefore affect
			 * the core `_x( 'ltr', 'text orientation' )` lookup that decides whether the
			 * dashboard is RTL.
			 *
			 * Smart Multilingual must never participate in that bootstrap. Delay every
			 * admin integration until `init`, which runs after WordPress has created its
			 * locale object and fixed the dashboard direction from the user's own locale.
			 */
			add_action( 'init', array( $this, 'boot_admin_integrations' ), 1 );
			add_action( 'init', array( $this, 'register_rewrites' ), 999 );
			add_action( 'admin_post_sml_refresh_rewrites', array( $this, 'refresh_rewrites' ) );
			add_action( 'update_option_' . self::OPTION_KEY, array( __CLASS__, 'clear_settings_cache' ), 1 );
			add_action( 'add_option_' . self::OPTION_KEY, array( __CLASS__, 'clear_settings_cache' ), 1 );
			add_action( 'update_option_' . SML_Languages::OPTION_KEY, array( 'SML_Languages', 'clear_cache' ), 1 );
			add_action( 'add_option_' . SML_Languages::OPTION_KEY, array( 'SML_Languages', 'clear_cache' ), 1 );
			add_action( 'delete_option_' . SML_Languages::OPTION_KEY, array( 'SML_Languages', 'clear_cache' ), 1 );
			return;
		}

		add_action( 'init', array( $this, 'register_rewrites' ), 999 );
		// Rewrites are registered on every request. Flushing is intentionally an
		// explicit administrator action because some sites have very large rule
		// tables and cannot safely rebuild them during activation or page load.
		add_action( 'admin_post_sml_refresh_rewrites', array( $this, 'refresh_rewrites' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'parse_request', array( $this, 'detect_language' ), 1 );
		/* Always register the main-query hook. The callback itself stays scoped,
		 * but translated Shop/Blog archives must be filtered even when the optional
		 * content_queries compatibility profile is disabled. */
		add_action( 'pre_get_posts', array( $this, 'filter_frontend_queries' ), 20 );
		$this->maybe_register_locale_filter();
		/* Public-language markup belongs to the frontend only. Do not even register
		 * these filters in wp-admin: the dashboard's lang/dir/body classes are 100%
		 * owned by WordPress and the current user's profile. */
		if ( ! $this->is_dashboard_request() ) {
			add_filter( 'language_attributes', array( $this, 'language_attributes' ) );
			add_filter( 'body_class', array( $this, 'body_class' ) );
		}
		add_filter( 'wp_nav_menu_args', array( $this, 'localized_menu' ) );
		add_filter( 'upload_mimes', array( $this, 'allow_font_uploads' ) );
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'validate_font_filetype' ), 10, 5 );
		add_filter( 'post_link', array( $this, 'localized_permalink' ), 10, 2 );
		add_filter( 'post_type_link', array( $this, 'localized_permalink' ), 10, 2 );
		add_filter( 'page_link', array( $this, 'localized_permalink' ), 10, 2 );
		add_filter( 'attachment_link', array( $this, 'localized_attachment_permalink' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ), 20 );
		/* Some themes (including customized Woodmart headers) do not call
		 * language_attributes() / body_class(). Emit deterministic language
		 * metadata in <head> as a frontend fallback so RTL and typography do
		 * not depend on theme markup quality. */
		add_action( 'wp_head', array( $this, 'frontend_language_bootstrap' ), 1 );
		add_action( 'wp_head', array( $this, 'frontend_typography_head' ), 99 );
		add_action( 'wp_head', array( $this, 'seo_links' ), 2 );
		add_filter( 'redirect_canonical', array( $this, 'disable_language_canonical_redirect' ), 10, 2 );
		add_filter( 'pre_handle_404', array( $this, 'prevent_resolved_language_404' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'enforce_seo_canonical_language_url' ), 1 );
		add_action( 'init', array( $this, 'register_shortcodes' ), 5 );
		add_action( 'update_option_' . self::OPTION_KEY, array( __CLASS__, 'clear_settings_cache' ), 1 );
		add_action( 'add_option_' . self::OPTION_KEY, array( __CLASS__, 'clear_settings_cache' ), 1 );
		add_action( 'update_option_' . SML_Languages::OPTION_KEY, array( 'SML_Languages', 'clear_cache' ), 1 );
		add_action( 'add_option_' . SML_Languages::OPTION_KEY, array( 'SML_Languages', 'clear_cache' ), 1 );
		add_action( 'delete_option_' . SML_Languages::OPTION_KEY, array( 'SML_Languages', 'clear_cache' ), 1 );
		$this->boot_integrations();
	}

	/**
	 * Missing CSS, JavaScript and font files are sometimes routed through
	 * WordPress by shared-hosting rewrite rules. Multilingual routing cannot help
	 * those requests, so booting every integration only consumes PHP workers.
	 */
	public static function should_skip_request() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = wp_parse_url( (string) $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || ! preg_match( '/\.(?:css|js|mjs|map|json|xml|txt|ico|png|jpe?g|gif|webp|avif|svg|woff2?|ttf|otf|eot|mp4|webm|mp3|wav|pdf)$/i', $path ) ) {
			return false;
		}

		return false !== strpos( $path, '/wp-content/' ) || false !== strpos( $path, '/wp-includes/' );
	}

	private function early_request_language( $default ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $default;
		}

		if ( ! empty( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$preview_id   = absint( $_GET['elementor-preview'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$preview_lang = $preview_id ? sanitize_key( (string) get_post_meta( $preview_id, self::META_LANG, true ) ) : '';
			if ( $preview_lang && SML_Languages::is_enabled( $preview_lang ) ) {
				return $preview_lang;
			}
		}

		$uri          = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$request_path = trim( (string) wp_parse_url( (string) $uri, PHP_URL_PATH ), '/' );
		$home_path    = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( $home_path && ( $request_path === $home_path || 0 === strpos( $request_path, $home_path . '/' ) ) ) {
			$request_path = ltrim( substr( $request_path, strlen( $home_path ) ), '/' );
		}

		foreach ( SML_Languages::secondary() as $language_code ) {
			$cfg    = SML_Languages::get( $language_code );
			$prefix = trim( (string) ( $cfg['prefix'] ?? '' ), '/' );
			if ( $prefix && ( $request_path === $prefix || 0 === strpos( $request_path, $prefix . '/' ) ) ) {
				return $language_code;
			}
		}

		return $default;
	}

	private function prime_current_locale() {
		/* The selected website language controls only the public site. wp-admin
		 * must always keep the locale selected by WordPress / the current user. */
		if ( $this->is_dashboard_request() ) {
			$this->current_locale = '';
			return;
		}
		$cfg = SML_Languages::get( $this->current_language );
		$this->current_locale = ! empty( $cfg['locale'] ) ? (string) $cfg['locale'] : '';
	}

	private function is_dashboard_request() {
		if ( defined( 'WP_ADMIN' ) && WP_ADMIN ) return true;
		return is_admin() && ! wp_doing_ajax();
	}

	private function maybe_register_locale_filter() {
		if ( $this->locale_filter_registered || $this->is_dashboard_request() ) {
			return;
		}
		add_filter( 'locale', array( $this, 'filter_locale' ), 20 );
		$this->locale_filter_registered = true;
	}

	/**
	 * Boot dashboard-only services only after WordPress has created WP_Locale.
	 * This method intentionally runs on `init`, never during plugins_loaded.
	 */
	public function boot_admin_integrations() {
		if ( ! $this->is_dashboard_request() ) return;
		$this->boot_integrations();
	}


	/**
	 * Keep public requests lean. Configuration screens remain available in the
	 * dashboard, while frontend adapters are loaded only when configured.
	 */
	private function boot_integrations() {
		if ( is_admin() ) {
			SML_Admin::instance();
			SML_Language_Manager::instance();
			SML_Admin_Filters::instance();
			SML_Taxonomy::instance();
			SML_Media::instance();
			SML_RevSlider::instance();
			SML_Site_Profile::instance();
			SML_Elementor::instance();
			/* Register gettext last so setup labels cannot re-enter bootstrap. */
			SML_Strings::instance();
			return;
		}

		SML_Elementor::instance();

		if ( $this->has_string_configuration() ) SML_Strings::instance();
		if ( SML_Compatibility::enabled( 'media_translation' ) ) SML_Media::instance();
		if ( $this->has_slider_configuration() ) SML_RevSlider::instance();
		if ( $this->has_site_profile_configuration() ) SML_Site_Profile::instance();
		if ( SML_Compatibility::enabled( 'taxonomy_routes' ) ) SML_Taxonomy::instance();
		if ( SML_Compatibility::enabled( 'content_queries' ) || SML_Compatibility::enabled( 'hfe_templates' ) ) {
			SML_Theme_Compat::instance();
		}
	}

	private function has_string_configuration() {
		foreach ( SML_Languages::secondary() as $lang ) {
			foreach ( array( 'sml_string_translations_', 'sml_language_only_strings_', 'sml_attribute_translations_', 'sml_scoped_strings_', 'sml_sidebar_map_' ) as $prefix ) {
				$value = get_option( $prefix . $lang, '' );
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private function has_slider_configuration() {
		$mappings = get_option( SML_RevSlider::OPTION_KEY, array() );
		return is_array( $mappings ) && ! empty( $mappings );
	}

	private function has_site_profile_configuration() {
		$profile = (array) get_option( SML_Site_Profile::OPTION_KEY, array() );
		return '' !== trim( (string) ( $profile['custom_css'] ?? '' ) ) || '' !== trim( (string) ( $profile['body_classes'] ?? '' ) );
	}

	public static function defaults() {
		$initial_languages = array_keys( SML_Languages::initial_registry() );
		return array(
			'default_language'=>SML_Languages::default_code(),
			'enabled_languages'=>$initial_languages,'switcher_languages'=>$initial_languages,
			'language_menus'=>array(),'switcher_labels'=>'native','switcher_layout'=>'dropdown','switcher_missing_behavior'=>'home',
			'switcher_text_color'=>'#ffffff','switcher_bg_color'=>'transparent','switcher_hover_text_color'=>'#ffffff','switcher_hover_bg_color'=>'rgba(255,255,255,.12)','switcher_menu_text_color'=>'#222222','switcher_menu_bg_color'=>'#ffffff','switcher_border_color'=>'transparent','switcher_radius'=>'6',
			'hide_untranslated'=>0,'rtl_reverse_columns'=>0,'delete_data'=>0,
			'compatibility_profiles'=>array_fill_keys(array_keys(SML_Compatibility::profiles()),0),
			'typography_fonts'=>array(),'typography_rules'=>array(),
		);
	}
	public static function settings() {
		if ( null === self::$settings_cache ) {
			$settings = wp_parse_args( get_option( self::OPTION_KEY, array() ), self::defaults() );
			$bootstrapped = SML_Typography::bootstrap_defaults( $settings );
			if ( $bootstrapped !== $settings ) {
				update_option( self::OPTION_KEY, $bootstrapped, false );
				$settings = $bootstrapped;
			}
			self::$settings_cache = $settings;
		}
		return self::$settings_cache;
	}
	public static function clear_settings_cache() {
		self::$settings_cache = null;
		SML_Languages::clear_cache();
	}

	/**
	 * Detect Woodmart so RTL and typography compatibility can be enabled without
	 * leaking theme-specific selectors to unrelated installations.
	 */
	public static function is_woodmart_site() {
		static $matched = null;
		if ( null !== $matched ) return $matched;

		$tokens = array_filter( array( get_stylesheet(), get_template() ) );
		$matched = false;
		foreach ( $tokens as $token ) {
			$token = strtolower( (string) $token );
			if ( 'woodmart' === $token || false !== strpos( $token, 'woodmart' ) ) {
				$matched = true;
				break;
			}
		}

		return (bool) apply_filters( 'sml_is_woodmart_site', $matched );
	}

	public static function activate() {
		/* Activation must stay constant-time, even on very large stores. */
		update_option( 'sml_activation_pending', 1, false );
		update_option( 'sml_rewrite_flush_required', 1, false );
	}
	public static function deactivate() {
		// A full flush during deactivation can time out on sites with many custom
		// post types. Mark the rules stale and let WordPress refresh them safely
		// from the Permalinks screen or when SML is activated again.
		delete_option( 'sml_rewrite_version' );
	}

	public function register_rewrites() {
		foreach ( SML_Languages::secondary() as $lang ) {
			$cfg = SML_Languages::get( $lang ); $prefix = sanitize_title( $cfg['prefix'] );
			add_rewrite_rule( '^'.$prefix.'/?$', 'index.php?sml_lang='.$lang, 'top' );
			add_rewrite_rule( '^'.$prefix.'/([0-9]{4})/([0-9]{1,2})/page/([0-9]+)/?$', 'index.php?year=$matches[1]&monthnum=$matches[2]&paged=$matches[3]&sml_lang='.$lang, 'top' );
			add_rewrite_rule( '^'.$prefix.'/([0-9]{4})/([0-9]{1,2})/?$', 'index.php?year=$matches[1]&monthnum=$matches[2]&sml_lang='.$lang, 'top' );
			add_rewrite_rule( '^'.$prefix.'/([0-9]{4})/?$', 'index.php?year=$matches[1]&sml_lang='.$lang, 'top' );
			add_rewrite_rule( '^'.$prefix.'/(.+?)/?$', 'index.php?sml_path=$matches[1]&sml_lang='.$lang, 'top' );
			if ( ! SML_Compatibility::enabled( 'taxonomy_routes' ) ) continue;
			foreach ( get_taxonomies( array('public'=>true), 'objects' ) as $taxonomy=>$object ) {
				if ( empty($object->rewrite['slug']) || 'post_format'===$taxonomy ) continue;
				$base=trim((string)$object->rewrite['slug'],'/'); if(!$base) continue;
				$q=$this->taxonomy_query_var($taxonomy,$object); $common='&sml_lang='.$lang.'&sml_taxonomy='.rawurlencode($taxonomy).'&sml_term_path=$matches[1]';
				add_rewrite_rule('^'.preg_quote($prefix,'#').'/'.preg_quote($base,'#').'/((?:[^/]+/)*[^/]+)/page/([0-9]+)/?$','index.php?'.$q.'=$matches[1]&paged=$matches[2]'.$common,'top');
				add_rewrite_rule('^'.preg_quote($prefix,'#').'/'.preg_quote($base,'#').'/((?:[^/]+/)*[^/]+)/?$','index.php?'.$q.'=$matches[1]'.$common,'top');
			}
		}
	}
	private function taxonomy_query_var($taxonomy,$object){ if('category'===$taxonomy)return'category_name';if('post_tag'===$taxonomy)return'tag';return !empty($object->query_var)&&is_string($object->query_var)?sanitize_key($object->query_var):sanitize_key($taxonomy); }
	public function refresh_rewrites() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to refresh rewrite rules.', 'smart-multilingual' ) );
		}
		check_admin_referer( 'sml_refresh_rewrites' );

		/* Mark first so a terminated PHP worker cannot create a retry loop. */
		update_option( 'sml_rewrite_version', self::REWRITE_VERSION, false );
		delete_option( 'sml_rewrite_flush_required' );
		flush_rewrite_rules( false );

		$redirect = wp_get_referer() ?: admin_url( 'admin.php?page=smart-multilingual-settings' );
		wp_safe_redirect( add_query_arg( 'sml_rewrites_refreshed', '1', $redirect ) );
		exit;
	}
	public function query_vars($vars){ foreach(array('sml_lang','sml_path','sml_taxonomy','sml_term_path','sml_front_page','sml_archive')as$v)$vars[]=$v;return$vars; }

	public function detect_language( $wp ) {
		$default = SML_Languages::default_code();
		$lang    = isset( $wp->query_vars['sml_lang'] ) ? sanitize_key( $wp->query_vars['sml_lang'] ) : $default;

		$this->current_language = SML_Languages::is_enabled( $lang ) ? $lang : $default;
		$this->default_language = $default;
		$this->prime_current_locale();
		$this->maybe_register_locale_filter();

		if ( $default === $this->current_language ) {
			return;
		}

		if ( ! empty( $wp->query_vars['sml_taxonomy'] ) ) {
			if ( SML_Taxonomy::instance()->resolve_rewrite_request( $wp, $this->current_language ) ) {
				return;
			}
			/* A taxonomy-shaped translated URL that has no valid translated term is
			 * not the language home page. Treat it as a real 404. */
			$this->set_language_404( $wp );
			return;
		}

		if ( empty( $wp->query_vars['sml_path'] ) ) {
			/* Explicit language-prefixed date archive rewrites intentionally have no
			 * sml_path. They are already valid native archive queries, not /{lang}/. */
			if ( ! empty( $wp->query_vars['year'] ) || ! empty( $wp->query_vars['monthnum'] ) || ! empty( $wp->query_vars['day'] ) ) {
				$this->resolved_language_request = true;
				return;
			}

			/* A language root is valid only when it can actually be resolved. On a
			 * static-front-page site, serving the default blog loop here creates a
			 * phantom 200 URL for a language prefix when no translated home exists. */
			if ( ! $this->resolve_language_root( $wp, $this->current_language ) && 'page' === get_option( 'show_on_front' ) ) {
				$this->set_language_404( $wp );
			}
			return;
		}

		$path = trim( rawurldecode( (string) $wp->query_vars['sml_path'] ), '/' );

		/* Shop and the configured Posts page are archives, not translated singular
		 * pages. Resolve these before ordinary translation lookup. */
		if ( $this->resolve_special_archive_path( $wp, $path, $this->current_language ) ) {
			return;
		}

		$posts = get_posts(
			array(
				'post_type'      => get_post_types( array( 'public' => true ), 'names' ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array( 'key' => self::META_LANG, 'value' => $this->current_language ),
					array( 'key' => self::META_SOURCE_PATH, 'value' => $path ),
				),
			)
		);
		if ( ! empty( $posts ) ) {
			$this->set_request_object( $wp, $posts[0] );
			return;
		}

		$source = $this->find_source_by_path( $path );
		if ( ! $source ) {
			$this->set_language_404( $wp );
			return;
		}

		$group = get_post_meta( $source, self::META_GROUP, true );
		$links = $group ? self::get_group_translations( $group ) : array();
		$id    = ! empty( $links[ $this->current_language ] ) ? (int) $links[ $this->current_language ] : 0;

		if ( $id && 'publish' === get_post_status( $id ) ) {
			update_post_meta( $id, self::META_SOURCE_PATH, $path );
			$this->set_request_object( $wp, get_post( $id ) );
			return;
		}

		/* A source object without a published translation must never fall through to
		 * the posts archive. That behavior produced SEO-dangerous soft-404 pages. */
		$this->set_language_404( $wp );
	}

	/** Force an unresolved language-prefixed route to be a genuine WordPress 404. */
	private function set_language_404( $wp ) {
		foreach ( array( 'sml_path', 'sml_taxonomy', 'sml_term_path', 'name', 'pagename', 'attachment', 'attachment_id', 'p', 'page_id', 'post_type', 'taxonomy', 'term', 'category_name', 'tag', 'year', 'monthnum', 'day', 'paged' ) as $key ) {
			unset( $wp->query_vars[ $key ] );
		}
		$wp->query_vars['sml_lang'] = $this->current_language;
		$wp->query_vars['error']    = '404';
		$this->resolved_language_request = false;
	}

	/**
	 * Resolve language-prefixed archive URLs before generic translated-page lookup.
	 *
	 * - /{lang}/shop/ (and /page/N/) becomes the real WooCommerce product archive.
	 * - /{lang}/blog/ (and /page/N/) becomes the real WordPress Posts page archive,
	 *   but only when Settings > Reading has a Posts page configured.
	 *
	 * This intentionally does not hijack an ordinary custom page named "blog" when
	 * WordPress is not using it as page_for_posts; that page continues through the
	 * normal translation resolver and keeps its own Elementor/shortcode content.
	 */
	private function resolve_special_archive_path( $wp, $path, $lang ) {
		$path = trim( (string) $path, '/' );
		if ( '' === $path ) return false;

		$archive = '';
		$paged   = 0;

		/* WooCommerce Shop archive. */
		if ( function_exists( 'wc_get_page_id' ) ) {
			$shop_id = absint( wc_get_page_id( 'shop' ) );
			if ( $shop_id > 0 ) {
				$shop_path = trim( (string) get_page_uri( $shop_id ), '/' );
				if ( '' === $shop_path ) $shop_path = 'shop';
				$match = $this->match_archive_path( $path, $shop_path );
				if ( false !== $match ) {
					$archive = 'shop';
					$paged   = $match;
				}
			}
		}

		/* WordPress Posts page archive. */
		if ( '' === $archive ) {
			$posts_page_id = absint( get_option( 'page_for_posts' ) );
			if ( $posts_page_id > 0 ) {
				$blog_path = trim( (string) get_page_uri( $posts_page_id ), '/' );
				if ( '' === $blog_path ) $blog_path = 'blog';
				$match = $this->match_archive_path( $path, $blog_path );
				if ( false !== $match ) {
					$archive = 'blog';
					$paged   = $match;
				}
			}
		}

		if ( '' === $archive ) return false;

		/* Remove singular/path variables that would make WP_Query resolve a page
		 * instead of the requested archive. Preserve sml_lang and preview vars. */
		foreach ( array( 'sml_path', 'sml_taxonomy', 'sml_term_path', 'name', 'pagename', 'attachment', 'attachment_id', 'error', 'p', 'page_id' ) as $key ) {
			unset( $wp->query_vars[ $key ] );
		}

		$wp->query_vars['sml_lang']    = $lang;
		$wp->query_vars['sml_archive'] = $archive;
		if ( $paged > 1 ) $wp->query_vars['paged'] = $paged;

		if ( 'shop' === $archive ) {
			/* A direct product archive is the most stable way to make WooCommerce's
			 * is_shop()/template loop work under a language prefix. */
			$wp->query_vars['post_type'] = 'product';
		} else {
			/* post_type=post with no singular vars is parsed as the posts index/home
			 * query, so the active theme's normal blog archive template is preserved. */
			$wp->query_vars['post_type'] = 'post';
		}

		$this->resolved_language_request = true;
		return true;
	}

	/** Return 0 for the first archive page, N for /page/N/, or false if no match. */
	private function match_archive_path( $request_path, $archive_path ) {
		$request_path = trim( (string) $request_path, '/' );
		$archive_path = trim( (string) $archive_path, '/' );
		if ( '' === $archive_path ) return false;
		if ( $request_path === $archive_path ) return 0;

		$pattern = '#^' . preg_quote( $archive_path, '#' ) . '/page/([0-9]+)/?$#i';
		if ( preg_match( $pattern, $request_path, $m ) ) {
			return max( 1, absint( $m[1] ) );
		}
		return false;
	}

	private function resolve_language_root( $wp, $lang ) {
		$preview_id = ! empty( $_GET['elementor-preview'] ) ? absint( $_GET['elementor-preview'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $preview_id ) {
			$preview = get_post( $preview_id );
			if ( $preview && $this->post_matches_language( $preview, $lang ) && $this->can_render_post( $preview ) ) {
				$this->set_request_object( $wp, $preview, true );
				return true;
			}
		}

		if ( 'page' !== get_option( 'show_on_front' ) ) {
			return false;
		}

		$source_id = absint( get_option( 'page_on_front' ) );
		$source    = $source_id ? get_post( $source_id ) : null;
		$front     = null;
		if ( $source && 'page' === $source->post_type ) {
			$source_lang = get_post_meta( $source_id, self::META_LANG, true ) ?: SML_Languages::default_code();
			if ( $lang === $source_lang ) {
				$front = $source;
			} else {
				$group     = get_post_meta( $source_id, self::META_GROUP, true );
				$links     = $group ? self::get_group_translations( $group ) : array();
				$target_id = ! empty( $links[ $lang ] ) ? absint( $links[ $lang ] ) : 0;
				$front     = $target_id ? get_post( $target_id ) : null;
			}
		}
		if ( $front && 'page' === $front->post_type && $this->post_matches_language( $front, $lang ) && $this->can_render_post( $front ) ) {
			$this->set_request_object( $wp, $front, true );
			return true;
		}

		// Older translations may have the root source path saved but no complete
		// translation group. Keep those installations recoverable as well.
		$root_pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array( 'key' => self::META_LANG, 'value' => $lang ),
					array( 'key' => self::META_SOURCE_PATH, 'value' => '' ),
				),
			)
		);
		if ( ! empty( $root_pages ) ) {
			$this->set_request_object( $wp, $root_pages[0], true );
			return true;
		}

		return false;
	}

	private function post_matches_language( $post, $lang ) {
		$post_lang = get_post_meta( $post->ID, self::META_LANG, true ) ?: SML_Languages::default_code();
		return $lang === $post_lang;
	}

	private function can_render_post( $post ) {
		return 'publish' === $post->post_status || current_user_can( 'edit_post', $post->ID );
	}

	private function set_request_object( $wp, $target, $is_front_page = false ) {
		if ( ! $target instanceof WP_Post ) {
			return false;
		}

		// Keep preview/customizer query variables, but remove every variable that
		// could make WP_Query resolve a different object than the translated post.
		foreach ( array( 'sml_path', 'sml_taxonomy', 'sml_term_path', 'name', 'pagename', 'attachment', 'attachment_id', 'error', 'p', 'page_id', 'post_type' ) as $key ) {
			unset( $wp->query_vars[ $key ] );
		}

		$wp->query_vars['sml_lang'] = $this->current_language;
		if ( 'page' === $target->post_type ) {
			$wp->query_vars['page_id'] = (int) $target->ID;
		} else {
			$wp->query_vars['p']         = (int) $target->ID;
			$wp->query_vars['post_type'] = $target->post_type;
		}
		if ( $is_front_page ) {
			$wp->query_vars['sml_front_page'] = 1;
		}

		$this->resolved_language_request = true;
		return true;
	}
	private function find_source_by_path($path){$default=SML_Languages::default_code();$page=get_page_by_path($path,OBJECT,get_post_types(array('public'=>true),'names'));if($page&&$default===(get_post_meta($page->ID,self::META_LANG,true)?:$default))return(int)$page->ID;$slug=sanitize_title(basename($path));foreach(get_posts(array('name'=>$slug,'post_type'=>get_post_types(array('public'=>true),'names'),'post_status'=>'publish','posts_per_page'=>20,'no_found_rows'=>true))as$post){if($default!==(get_post_meta($post->ID,self::META_LANG,true)?:$default))continue;if(self::relative_url_path(get_permalink($post))===trim($path,'/'))return(int)$post->ID;}return 0;}
	public function mark_resolved_language_request() {
		$this->resolved_language_request = true;
	}

	public function prevent_resolved_language_404( $preempt, $q ) {
		/* Only preempt WordPress' 404 handler for requests that this plugin actually
		 * resolved to a real translated object/archive. Unresolved prefixed routes
		 * must remain real 404s. */
		if ( $this->resolved_language_request && ! SML_Languages::is_default( $this->current_language ) ) {
			$q->is_404 = false;
			status_header( 200 );
			return true;
		}
		return $preempt;
	}

	public function disable_language_canonical_redirect( $url, $requested ) {
		if ( SML_Languages::is_default( $this->current_language ) || ! $this->resolved_language_request || ! $url ) {
			return $url;
		}

		/* WordPress sometimes tries to canonicalize a translated prefixed request to
		 * its source-language permalink. Block only that destructive redirect, while
		 * preserving useful canonical redirects (slash normalization, pagination,
		 * case fixes, etc.) that stay inside the active language prefix. */
		$cfg         = SML_Languages::get( $this->current_language );
		$prefix      = trim( (string) ( $cfg['prefix'] ?? '' ), '/' );
		$target_path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		$home_path   = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( $home_path && ( $target_path === $home_path || 0 === strpos( $target_path, $home_path . '/' ) ) ) {
			$target_path = ltrim( substr( $target_path, strlen( $home_path ) ), '/' );
		}

		if ( $prefix && $target_path !== $prefix && 0 !== strpos( $target_path, $prefix . '/' ) ) {
			return false;
		}
		return $url;
	}

	/**
	 * A secondary post is safe to redirect only when its translation relationship
	 * is verifiable. Historical versions could stamp source content with the old
	 * default language. Redirecting solely from _sml_language therefore sent a
	 * source site back to another locale after changing the source language.
	 */
	private function is_verified_secondary_post( $post_id, $post_lang ) {
		$post_id   = absint( $post_id );
		$post_lang = sanitize_key( $post_lang );
		$default   = SML_Languages::default_code();
		if ( ! $post_id || ! $post_lang || $post_lang === $default ) return false;

		$group = get_post_meta( $post_id, self::META_GROUP, true );
		if ( ! $group ) return false;
		$links = self::get_group_translations( $group );
		if ( empty( $links[ $post_lang ] ) || (int) $links[ $post_lang ] !== $post_id ) return false;

		/* A secondary object must be linked to a published source/default object. */
		return ! empty( $links[ $default ] ) && 'publish' === get_post_status( (int) $links[ $default ] );
	}

	/** Verify a translated taxonomy term before enforcing a cross-language 301. */
	private function is_verified_secondary_term( $term, $term_lang ) {
		if ( ! ( $term instanceof WP_Term ) ) return false;
		$term_lang = sanitize_key( $term_lang );
		$default   = SML_Languages::default_code();
		if ( ! $term_lang || $term_lang === $default ) return false;

		$group = get_term_meta( $term->term_id, SML_Taxonomy::META_GROUP, true );
		if ( ! $group ) return false;
		$links = SML_Taxonomy::instance()->get_group_translations( $group, $term->taxonomy );
		return ! empty( $links[ $term_lang ] ) && (int) $links[ $term_lang ] === (int) $term->term_id && ! empty( $links[ $default ] );
	}

	/**
	 * Prevent verified alternate-language content from being indexable through
	 * WordPress' native, unprefixed rewrite. Unverified legacy language metadata is
	 * deliberately ignored so changing the source language cannot hijack the site.
	 */
	public function enforce_seo_canonical_language_url() {
		if ( is_admin() || wp_doing_ajax() || is_preview() || $this->resolved_language_request || is_404() ) {
			return;
		}

		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			if ( ! $post_id ) return;
			$post_lang = sanitize_key( (string) get_post_meta( $post_id, self::META_LANG, true ) );
			if ( ! $post_lang ) $post_lang = SML_Languages::default_code();
			if ( $post_lang !== $this->current_language() && SML_Languages::is_enabled( $post_lang ) && $this->is_verified_secondary_post( $post_id, $post_lang ) ) {
				$target = get_permalink( $post_id );
				if ( $target ) {
					wp_safe_redirect( $target, 301, 'Smart Multilingual' );
					exit;
				}
			}
		}

		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$term_lang = sanitize_key( (string) get_term_meta( $term->term_id, SML_Taxonomy::META_LANG, true ) );
				if ( ! $term_lang ) $term_lang = SML_Languages::default_code();
				if ( $term_lang !== $this->current_language() && SML_Languages::is_enabled( $term_lang ) && $this->is_verified_secondary_term( $term, $term_lang ) ) {
					$target = get_term_link( $term );
					if ( ! is_wp_error( $target ) && $target ) {
						wp_safe_redirect( $target, 301, 'Smart Multilingual' );
						exit;
					}
				}
			}
		}
	}
	public function current_language() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return SML_Languages::default_code();
		}

		// Elementor preview requests do not carry the public language prefix.
		// Resolve the preview language from the template/page being edited so RTL
		// and language-specific content remain predictable inside the editor.
		if ( ! empty( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$preview_id = absint( $_GET['elementor-preview'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$preview_lang = $preview_id ? get_post_meta( $preview_id, self::META_LANG, true ) : '';
			if ( $preview_lang && SML_Languages::is_enabled( $preview_lang ) ) {
				return $preview_lang;
			}
		}

		if ( SML_Languages::is_default( $this->current_language ) && ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$request_path = trim( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' );
			$home_path    = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
			if ( $home_path && ( $request_path === $home_path || 0 === strpos( $request_path, $home_path . '/' ) ) ) {
				$request_path = ltrim( substr( $request_path, strlen( $home_path ) ), '/' );
			}
			foreach ( SML_Languages::secondary() as $language_code ) {
				$prefix = trim( (string) SML_Languages::get( $language_code )['prefix'], '/' );
				if ( $prefix && ( $request_path === $prefix || 0 === strpos( $request_path, $prefix . '/' ) ) ) {
					return $language_code;
				}
			}
		}

		return $this->current_language;
	}


	/**
	 * True only on the public Blog archive URL (default or language-prefixed).
	 * This is intentionally URI-scoped so secondary post loops on other pages
	 * are left completely untouched.
	 */
	public function is_blog_archive_request() {
		if ( is_admin() || empty( $_SERVER['REQUEST_URI'] ) ) return false;

		$request_path = trim( (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ), '/' );
		$home_path    = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( $home_path && ( $request_path === $home_path || 0 === strpos( $request_path, $home_path . '/' ) ) ) {
			$request_path = ltrim( substr( $request_path, strlen( $home_path ) ), '/' );
		}

		foreach ( SML_Languages::secondary() as $language_code ) {
			$cfg    = SML_Languages::get( $language_code );
			$prefix = trim( (string) ( $cfg['prefix'] ?? '' ), '/' );
			if ( $prefix && ( $request_path === $prefix || 0 === strpos( $request_path, $prefix . '/' ) ) ) {
				$request_path = ltrim( substr( $request_path, strlen( $prefix ) ), '/' );
				break;
			}
		}

		$posts_page_id = absint( get_option( 'page_for_posts' ) );
		/* Bootstrap-safe: gettext can call this before WP_Rewrite exists. */
			$blog_path = $posts_page_id ? trim( (string) get_page_uri( $posts_page_id ), '/' ) : 'blog';
		if ( '' === $blog_path ) $blog_path = 'blog';

		return false !== $this->match_archive_path( $request_path, $blog_path );
	}

	public function filter_frontend_queries( $query ) {
		if ( is_admin() ) return;

		/* The custom NEO Blog archive renders its cards with a secondary WP_Query.
		 * Filter that one route as well, but do not broaden secondary-query filtering
		 * anywhere else on the site. */
		$is_main_query      = $query->is_main_query();
		$is_blog_secondary  = ! $is_main_query && $this->is_blog_archive_request();
		if ( ! $is_main_query && ! $is_blog_secondary ) return;

		if ( $is_blog_secondary ) {
			$post_type = $query->get( 'post_type' );
			if ( empty( $post_type ) ) $post_type = 'post';
			$post_types = array_values( array_filter( array_map( 'sanitize_key', (array) $post_type ) ) );
			if ( empty( $post_types ) || array_diff( $post_types, array( 'post' ) ) ) return;
			if ( $query->get( 'p' ) || $query->get( 'page_id' ) || $query->get( 'attachment_id' ) ) return;
			$this->apply_strict_language_filter( $query, $this->current_language() );
			return;
		}

		$special_archive    = sanitize_key( (string) $query->get( 'sml_archive' ) );
		$is_special_archive = in_array( $special_archive, array( 'shop', 'blog' ), true );
		$is_native_archive  = $query->is_home() || $query->is_archive();

		/* Archive loops are always language-strict in both directions. This covers
		 * the native English /shop/ + /blog/ routes as well as category/date/product
		 * archives, while the explicit sml_archive flag covers language-prefixed shop/blog routes.
		 * Non-archive automatic filtering remains opt-in to avoid touching unrelated
		 * framework/main queries on sites that did not enable content filtering. */
		if ( ! $is_special_archive && ! $is_native_archive && ! SML_Compatibility::enabled( 'content_queries' ) ) {
			return;
		}

		if ( $query->get( '_sml_language_filtered' ) || ! $this->query_targets_language_content( $query ) ) {
			return;
		}

		/* Singular requests are resolved to an exact post ID during parse_request.
		 * Special archive routes deliberately bypass this guard. */
		if ( ! $is_special_archive && ( $query->is_singular() || $query->get( 'p' ) || $query->get( 'page_id' ) || $query->get( 'attachment_id' ) ) ) {
			return;
		}

		$this->apply_strict_language_filter( $query, $this->current_language() );
	}

	/**
	 * Only ordinary posts and WooCommerce products are auto-filtered. This keeps
	 * Elementor templates, attachments, menus and internal framework queries out
	 * of the language postmeta join and prevents the broad-query 503 regression.
	 */
	public function query_targets_language_content( $query ) {
		$post_type = $query->get( 'post_type' );
		if ( empty( $post_type ) ) $post_type = 'post';
		$post_types = array_values( array_filter( array_map( 'sanitize_key', (array) $post_type ) ) );
		if ( empty( $post_types ) || in_array( 'any', $post_types, true ) ) return false;

		foreach ( $post_types as $type ) {
			if ( ! in_array( $type, array( 'post', 'product' ), true ) ) return false;
		}
		return true;
	}

	/**
	 * Apply one exact language condition to a content list. Secondary languages
	 * never fall back to source-language posts/products inside loops or archives.
	 */
	public function apply_strict_language_filter( $query, $lang ) {
		if ( $query->get( '_sml_language_filtered' ) ) return;

		$default = SML_Languages::default_code();
		$meta_query = (array) $query->get( 'meta_query' );
		$meta_query[] = $default === $lang
			? array(
				'relation' => 'OR',
				array( 'key' => self::META_LANG, 'value' => $default ),
				array( 'key' => self::META_LANG, 'compare' => 'NOT EXISTS' ),
			)
			: array( 'key' => self::META_LANG, 'value' => $lang );

		$query->set( 'meta_query', $meta_query );
		$query->set( '_sml_language_filtered', 1 );
	}

	public function filter_locale( $locale ) {
		/* Hard safety boundary: the multilingual website locale is never allowed
		 * to replace the WordPress dashboard/user locale. */
		if ( $this->is_dashboard_request() ) return $locale;
		return '' !== $this->current_locale ? $this->current_locale : $locale;
	}
	public function language_attributes( $out ) {
		/* Never let the public-language router alter wp-admin markup.
		 * WordPress/user locale owns the dashboard language and direction.
		 * Without this guard, selecting an RTL source language
		 * forced dir="rtl" onto an otherwise English dashboard and broke the
		 * entire admin layout. AJAX/REST responses do not render the admin HTML
		 * shell, so returning the original attributes is the safest behaviour for
		 * every admin request. */
		if ( $this->is_dashboard_request() ) {
			return $out;
		}

		$cfg  = SML_Languages::get( $this->current_language() );
		$lang = esc_attr( $cfg['hreflang'] );
		$dir  = esc_attr( $cfg['dir'] );
		$out  = (string) $out;

		if ( preg_match( '/\\blang=(["\\\']).*?\\1/i', $out ) ) {
			$out = preg_replace( '/\\blang=(["\\\']).*?\\1/i', 'lang="' . $lang . '"', $out );
		} else {
			$out = trim( $out ) . ' lang="' . $lang . '"';
		}

		if ( preg_match( '/\\bdir=(["\\\']).*?\\1/i', $out ) ) {
			$out = preg_replace( '/\\bdir=(["\\\']).*?\\1/i', 'dir="' . $dir . '"', $out );
		} else {
			$out = trim( $out ) . ' dir="' . $dir . '"';
		}

		return trim( $out );
	}
	public function body_class( $classes ) {
		$lang = $this->current_language();
		$cfg  = SML_Languages::get( $lang );

		/* Direction classes are core language metadata, not a legacy theme fix.
		 * Keeping them behind legacy_093_layout meant fresh installations had
		 * html[dir=rtl] but none of the plugin's RTL selectors could match. */
		$classes[] = 'sml-lang-' . sanitize_html_class( $lang );
		$classes[] = 'sml-language-ready';
		$classes[] = 'lang-' . sanitize_html_class( $lang );
		$classes[] = 'sml-dir-' . ( 'rtl' === $cfg['dir'] ? 'rtl' : 'ltr' );
		if ( 'rtl' === $cfg['dir'] ) {
			$classes[] = 'rtl';
			$classes[] = 'sml-rtl';
		}

		if ( self::is_woodmart_site() ) $classes[] = 'sml-site-woodmart';
		if ( class_exists( 'SML_Site_Profile' ) ) foreach ( SML_Site_Profile::body_classes() as $profile_class ) $classes[] = $profile_class;
		return array_unique( $classes );
	}
	public function localized_menu($args){$lang=$this->current_language();$settings=self::settings();$menus=!empty($settings['language_menus'])&&is_array($settings['language_menus'])?$settings['language_menus']:array();if(!empty($menus[$lang]))$args['menu']=absint($menus[$lang]);return$args;}
	public function allow_font_uploads($m){if(current_user_can('manage_options')){$m['woff']='font/woff';$m['woff2']='font/woff2';}return$m;}
	public function validate_font_filetype($d,$f,$n,$m,$r=''){if(current_user_can('manage_options')){$e=strtolower(pathinfo($n,PATHINFO_EXTENSION));if(in_array($e,array('woff','woff2'),true)){$d['ext']=$e;$d['type']='woff2'===$e?'font/woff2':'font/woff';$d['proper_filename']=$n;}}return$d;}
	public function localized_attachment_permalink($url,$id){$p=get_post($id);return$p?$this->localized_permalink($url,$p):$url;}
	public function localized_permalink( $url, $post ) {
		$id      = is_object( $post ) ? $post->ID : (int) $post;
		$default = SML_Languages::default_code();
		$lang    = get_post_meta( $id, self::META_LANG, true ) ?: $default;
		if ( $default === $lang ) return $url;

		$path = get_post_meta( $id, self::META_SOURCE_PATH, true );
		if ( ! $path ) {
			$group = get_post_meta( $id, self::META_GROUP, true );
			$links = $group ? self::get_group_translations( $group ) : array();
			if ( ! empty( $links[ $default ] ) && 'publish' === get_post_status( $links[ $default ] ) ) {
				$path = self::relative_url_path( get_permalink( $links[ $default ] ) );
			}

			/* Never collapse an orphan/incomplete translation to the language root.
			 * Falling back to its native WordPress path preserves a unique canonical
			 * URL and prevents translated objects from sharing one language root. */
			if ( ! $path ) {
				$path = self::relative_url_path( $url );
			}
			if ( $path ) update_post_meta( $id, self::META_SOURCE_PATH, $path );
		}

		return $path ? self::language_url( $lang, $path ) : $url;
	}
	public static function relative_url_path($url){if(is_wp_error($url))return'';$path=trim((string)wp_parse_url($url,PHP_URL_PATH),'/');$home=trim((string)wp_parse_url(home_url('/'),PHP_URL_PATH),'/');if($home&&($path===$home||0===strpos($path,$home.'/')))$path=ltrim(substr($path,strlen($home)),'/');foreach(SML_Languages::secondary()as$lang){$prefix=SML_Languages::get($lang)['prefix'];if($path===$prefix||0===strpos($path,$prefix.'/')){$path=ltrim(substr($path,strlen($prefix)),'/');break;}}return trim(rawurldecode($path),'/');}
	public static function language_url($lang,$path=''){if(SML_Languages::is_default($lang))return home_url(user_trailingslashit(trim((string)$path,'/')));$cfg=SML_Languages::get($lang);$route=trim($cfg['prefix'],'/').($path?'/'.trim((string)$path,'/'):'');return home_url(user_trailingslashit($route));}


	/**
	 * Ensure language metadata exists even when the active theme omits the
	 * WordPress language_attributes() and body_class() helpers.
	 *
	 * The <html> element is patched immediately because wp_head runs inside it.
	 * <body> is patched once it exists. This is deliberately tiny, synchronous
	 * and dependency-free; it never performs language detection in JavaScript.
	 */
	public function frontend_language_bootstrap() {
		if ( is_admin() ) return;

		$lang = sanitize_html_class( $this->current_language() );
		$cfg  = SML_Languages::get( $lang );
		$dir  = 'rtl' === ( $cfg['dir'] ?? 'ltr' ) ? 'rtl' : 'ltr';
		$hreflang = sanitize_text_field( $cfg['hreflang'] ?? $lang );

		$classes = array(
			'sml-lang-' . $lang,
			'sml-language-ready',
			'lang-' . $lang,
			'sml-dir-' . $dir,
		);
		if ( 'rtl' === $dir ) {
			$classes[] = 'rtl';
			$classes[] = 'sml-rtl';
		}
		if ( self::is_woodmart_site() ) $classes[] = 'sml-site-woodmart';
		if ( class_exists( 'SML_Site_Profile' ) ) {
			foreach ( SML_Site_Profile::body_classes() as $profile_class ) {
				if ( $profile_class ) $classes[] = sanitize_html_class( $profile_class );
			}
		}
		$classes = array_values( array_unique( array_filter( $classes ) ) );

		$payload = wp_json_encode(
			array(
				'lang'    => $lang,
				'hreflang'=> $hreflang,
				'dir'     => $dir,
				'classes' => $classes,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		if ( ! $payload ) return;

		echo '<script id="sml-language-bootstrap">(function(d,p){var h=d.documentElement;if(!h)return;h.setAttribute("lang",p.hreflang);h.setAttribute("dir",p.dir);h.setAttribute("data-sml-lang",p.lang);p.classes.forEach(function(c){if(c)h.classList.add(c);});function b(){if(!d.body)return false;d.body.setAttribute("data-sml-lang",p.lang);p.classes.forEach(function(c){if(c)d.body.classList.add(c);});return true;}if(!b()){d.addEventListener("DOMContentLoaded",b,{once:true});}})(document,' . $payload . ');</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Late typography fallback. Some optimization stacks and theme asset managers
	 * can detach inline CSS from an enqueued handle. Emitting only the typography
	 * compiler again near the end of <head> makes custom @font-face declarations
	 * and language-scoped font rules deterministic without affecting the default
	 * language when no typography target is enabled.
	 */
	public function frontend_typography_head() {
		if ( is_admin() ) return;
		$settings = self::settings();
		$css = SML_Typography::compile_css( $settings );
		/* This request-local layer is intentionally unscoped. It is emitted only
		 * for the detected language request, so it still works when a theme omits
		 * body_class() entirely (the exact failure seen on customized Woodmart). */
		$css .= SML_Typography::compile_active_language_css( $settings, $this->current_language() );
		if ( '' === trim( $css ) ) return;
		echo '<style id="sml-typography-head">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS is sanitized by SML_Typography.
	}

	public function frontend_assets(){
		wp_enqueue_style('sml-frontend',SML_URL.'assets/css/frontend.css',array(),SML_VERSION);
		if(SML_Compatibility::enabled('elementor_rtl') || self::is_woodmart_site())wp_enqueue_style('sml-compat-elementor-rtl',SML_URL.'assets/css/compat-elementor-rtl.css',array('sml-frontend'),SML_VERSION);
		if(SML_Compatibility::enabled('legacy_093_layout'))wp_enqueue_style('sml-compat-legacy-093',SML_URL.'assets/css/compat-legacy-093.css',array('sml-frontend'),SML_VERSION);
		if(self::is_woodmart_site())wp_enqueue_style('sml-compat-woodmart',SML_URL.'assets/css/compat-woodmart.css',array('sml-frontend'),SML_VERSION);
		wp_enqueue_script('sml-frontend',SML_URL.'assets/js/frontend.js',array(),SML_VERSION,true);
		$s=self::settings();
		$css=':root{--sml-switcher-color:'.esc_attr($s['switcher_text_color']).';--sml-switcher-bg:'.esc_attr($s['switcher_bg_color']).';--sml-switcher-hover-color:'.esc_attr($s['switcher_hover_text_color']).';--sml-switcher-hover-bg:'.esc_attr($s['switcher_hover_bg_color']).';--sml-switcher-menu-color:'.esc_attr($s['switcher_menu_text_color']).';--sml-switcher-menu-bg:'.esc_attr($s['switcher_menu_bg_color']).';--sml-switcher-border:'.esc_attr($s['switcher_border_color']).';--sml-switcher-radius:'.absint($s['switcher_radius']).'px;}';
		$is_elementor_preview = ! empty( $_GET['elementor-preview'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		foreach ( SML_Languages::enabled() as $language_code ) {
			$language_code = sanitize_html_class( $language_code );
			if ( ! $is_elementor_preview ) {
				$css .= 'body:not(.sml-lang-' . $language_code . ') .sml-only-' . $language_code . '{display:none!important;}';
				$css .= 'body.sml-lang-' . $language_code . ' .sml-except-' . $language_code . '{display:none!important;}';
			}
		}
		if ( ! $is_elementor_preview ) $css .= 'html[dir="ltr"] .sml-only-rtl,html[dir="rtl"] .sml-only-ltr{display:none!important;}';
		wp_add_inline_style('sml-frontend',$css);
	}

	public function register_shortcodes(){
		add_shortcode('sml_language_switcher',array($this,'switcher_shortcode'));
		add_shortcode('sml_switcher',array($this,'switcher_shortcode'));
		add_shortcode('sml_language_value',array($this,'language_value_shortcode'));
		add_shortcode('sml_value',array($this,'language_value_shortcode'));
		add_shortcode('sml_show',array($this,'language_show_shortcode'));
	}

	public function language_value_shortcode( $atts, $content = '' ) {
		$atts = is_array( $atts ) ? $atts : array();
		$lang = $this->current_language();
		$value = '';

		if ( isset( $atts[ $lang ] ) ) {
			$value = (string) $atts[ $lang ];
		} elseif ( isset( $atts[ SML_Languages::default_code() ] ) ) {
			$value = (string) $atts[ SML_Languages::default_code() ];
		} elseif ( '' !== (string) $content ) {
			$value = (string) $content;
		}

		return wp_kses_post( do_shortcode( $value ) );
	}

	public function language_show_shortcode( $atts, $content = '' ) {
		$atts = shortcode_atts( array( 'lang' => '', 'languages' => '' ), $atts, 'sml_show' );
		$raw = $atts['languages'] ? $atts['languages'] : $atts['lang'];
		$languages = array_filter( array_map( 'sanitize_key', preg_split( '/[\s,|]+/', (string) $raw ) ) );
		if ( empty( $languages ) || ! in_array( $this->current_language(), $languages, true ) ) {
			return '';
		}
		return do_shortcode( (string) $content );
	}

	public function seo_links() {
		if ( ! is_singular() || is_404() ) return;

		$post_id = get_queried_object_id();
		$group   = $post_id ? get_post_meta( $post_id, self::META_GROUP, true ) : '';
		if ( ! $group ) return;

		$links = self::get_group_translations( $group );
		foreach ( SML_Languages::enabled() as $lang ) {
			$id = ! empty( $links[ $lang ] ) ? absint( $links[ $lang ] ) : 0;
			if ( ! $id || 'publish' !== get_post_status( $id ) ) continue;
			$cfg = SML_Languages::get( $lang );
			printf(
				'<link rel="alternate" hreflang="%1$s" href="%2$s" />' . "\n",
				esc_attr( $cfg['hreflang'] ),
				esc_url( get_permalink( $id ) )
			);
		}

		$default = SML_Languages::default_code();
		$default_id = ! empty( $links[ $default ] ) ? absint( $links[ $default ] ) : 0;
		if ( $default_id && 'publish' === get_post_status( $default_id ) ) {
			printf( '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n", esc_url( get_permalink( $default_id ) ) );
		}
	}

	public static function get_group_translations($group){$r=array();$default=SML_Languages::default_code();foreach(get_posts(array('post_type'=>'any','post_status'=>array('publish','draft','pending','private','future'),'posts_per_page'=>50,'meta_key'=>self::META_GROUP,'meta_value'=>sanitize_text_field($group),'no_found_rows'=>true))as$p){$r[get_post_meta($p->ID,self::META_LANG,true)?:$default]=$p->ID;}return$r;}
	public function switcher_shortcode($atts){
		$settings = self::settings();
		$atts = shortcode_atts(
			array(
				'class'            => '',
				'style'            => $settings['switcher_labels'],
				'layout'           => $settings['switcher_layout'],
				'missing'          => $settings['switcher_missing_behavior'] ?? 'home',
				'show_unavailable' => '', // Backward-compatible alias: 1 = home, 0 = hide.
			),
			$atts,
			'sml_language_switcher'
		);

		if ( '' !== (string) $atts['show_unavailable'] ) {
			$atts['missing'] = '1' === (string) $atts['show_unavailable'] ? 'home' : 'hide';
		}
		if ( ! in_array( $atts['missing'], array( 'home', 'path', 'hide' ), true ) ) {
			$atts['missing'] = 'home';
		}

		$current = $this->current_language();
		$selected_languages = SML_Languages::switcher_enabled();
		if ( empty( $selected_languages ) ) {
			return '';
		}

		$available = array();
		$is_singular_context = is_singular();
		$queried_id = $is_singular_context ? get_queried_object_id() : 0;
		$group = $queried_id ? get_post_meta( $queried_id, self::META_GROUP, true ) : '';
		$links = $group ? self::get_group_translations( $group ) : array();
		$current_path = self::relative_url_path( home_url( '/' . ltrim( (string) ( $GLOBALS['wp']->request ?? '' ), '/' ) ) );

		foreach ( $selected_languages as $lang ) {
			$cfg = SML_Languages::get( $lang );
			$url = '';
			$is_translation = false;

			if ( $is_singular_context && ! empty( $links[ $lang ] ) && 'publish' === get_post_status( $links[ $lang ] ) ) {
				$url = get_permalink( $links[ $lang ] );
				$is_translation = true;
			} elseif ( $lang === $current ) {
				$url = $queried_id ? get_permalink( $queried_id ) : self::language_url( $lang, $current_path );
				$is_translation = true;
			} elseif ( ! $is_singular_context ) {
				$url = self::language_url( $lang, $current_path );
				$is_translation = true;
			} elseif ( 'path' === $atts['missing'] ) {
				$url = self::language_url( $lang, $current_path );
			} elseif ( 'home' === $atts['missing'] ) {
				$url = self::language_url( $lang );
			}

			if ( $url ) {
				$available[ $lang ] = array(
					'cfg'             => $cfg,
					'url'             => $url,
					'has_translation' => $is_translation,
				);
			}
		}

		if ( empty( $available ) ) {
			return '';
		}

		$label = static function( $lang, $cfg ) use ( $atts ) {
			if ( 'short' === $atts['style'] ) return strtoupper( $lang );
			if ( 'full' === $atts['style'] ) return $cfg['name'];
			return $cfg['native'];
		};

		if ( 'list' === $atts['layout'] ) {
			$html = '<nav class="sml-switcher sml-switcher-list ' . esc_attr( $atts['class'] ) . '" aria-label="' . esc_attr__( 'Language switcher', 'smart-multilingual' ) . '">';
			foreach ( $available as $lang => $item ) {
				$classes = 'sml-switcher-item' . ( $lang === $current ? ' is-active' : '' ) . ( empty( $item['has_translation'] ) ? ' is-fallback' : '' );
				$html .= '<a class="' . esc_attr( $classes ) . '" href="' . esc_url( $item['url'] ) . '" lang="' . esc_attr( $item['cfg']['hreflang'] ) . '" hreflang="' . esc_attr( $item['cfg']['hreflang'] ) . '">' . esc_html( $label( $lang, $item['cfg'] ) ) . '</a>';
			}
			$html .= '</nav>';
			return $html;
		}

		$current_cfg = SML_Languages::get( $current );
		$html = '<div class="sml-switcher sml-switcher-dropdown ' . esc_attr( $atts['class'] ) . '">';
		$html .= '<button type="button" class="sml-switcher-toggle" aria-expanded="false" aria-haspopup="true">' . esc_html( $label( $current, $current_cfg ) ) . '</button>';
		$html .= '<div class="sml-switcher-menu">';
		foreach ( $available as $lang => $item ) {
			$classes = 'sml-switcher-item' . ( $lang === $current ? ' is-active' : '' ) . ( empty( $item['has_translation'] ) ? ' is-fallback' : '' );
			$html .= '<a class="' . esc_attr( $classes ) . '" href="' . esc_url( $item['url'] ) . '" lang="' . esc_attr( $item['cfg']['hreflang'] ) . '" hreflang="' . esc_attr( $item['cfg']['hreflang'] ) . '">' . esc_html( $label( $lang, $item['cfg'] ) ) . '</a>';
		}
		$html .= '</div></div>';
		return $html;
	}
}
