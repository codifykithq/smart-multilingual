<?php
defined( 'ABSPATH' ) || exit;

/**
 * Language-aware Slider Revolution alias mapping for SR6 and early SR7 builds.
 *
 * Each translated module remains an independent Slider Revolution module. This
 * class only selects the correct duplicate at render time; it never writes to
 * Slider Revolution's private database tables.
 */
final class SML_RevSlider {
	const OPTION_KEY = 'sml_revslider_mappings';

	private static $instance;
	private $modules_cache = null;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 18 );
		add_action( 'admin_post_sml_save_revslider_mappings', array( $this, 'save_mappings' ) );
		add_filter( 'pre_do_shortcode_tag', array( $this, 'filter_revslider_shortcode' ), 10, 4 );
		add_action( 'elementor/frontend/before_render', array( $this, 'prepare_elementor_widget' ), 5, 1 );
		add_shortcode( 'sml_revslider', array( $this, 'shortcode' ) );
	}

	public function admin_menu() {
		add_submenu_page(
			'smart-multilingual',
			__( 'Slider Revolution Translation', 'smart-multilingual' ),
			__( 'Sliders', 'smart-multilingual' ),
			'manage_options',
			'smart-multilingual-sliders',
			array( $this, 'page' )
		);
	}

	public function mappings() {
		$stored = get_option( self::OPTION_KEY, array() );
		return is_array( $stored ) ? $stored : array();
	}

	public function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage slider translations.', 'smart-multilingual' ) );
		}

		$modules  = $this->modules();
		$mappings = $this->mappings();
		if ( empty( $mappings ) ) {
			$mappings[] = array( 'source' => '', 'translations' => array() );
		}
		?>
		<div class="wrap sml-admin-wrap">
			<h1><?php esc_html_e( 'Slider Revolution Translation', 'smart-multilingual' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Slider mappings saved.', 'smart-multilingual' ); ?></p></div>
			<?php endif; ?>
			<div class="sml-card">
				<h2><?php esc_html_e( 'Duplicate module workflow', 'smart-multilingual' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'Duplicate the source module in Slider Revolution.', 'smart-multilingual' ); ?></li>
					<li><?php esc_html_e( 'Translate the duplicate and give it a unique alias that includes its language code.', 'smart-multilingual' ); ?></li>
					<li><?php esc_html_e( 'Connect the source and translated aliases below.', 'smart-multilingual' ); ?></li>
				</ol>
				<p class="description"><?php esc_html_e( 'Keep the source module selected in Elementor. Smart Multilingual replaces it automatically on translated pages.', 'smart-multilingual' ); ?></p>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sml_save_revslider_mappings">
				<?php wp_nonce_field( 'sml_save_revslider_mappings' ); ?>
				<div class="sml-card">
					<div class="sml-slider-map-head">
						<div>
							<h2><?php esc_html_e( 'Module mappings', 'smart-multilingual' ); ?></h2>
							<p><?php echo $modules ? esc_html( sprintf( __( '%d Slider Revolution modules detected.', 'smart-multilingual' ), count( $modules ) ) ) : esc_html__( 'Module discovery is unavailable. Enter aliases manually.', 'smart-multilingual' ); ?></p>
						</div>
						<button type="button" class="button" id="sml-add-slider-map"><?php esc_html_e( 'Add slider', 'smart-multilingual' ); ?></button>
					</div>
					<div id="sml-slider-map-rows" data-next-index="<?php echo esc_attr( count( $mappings ) ); ?>">
						<?php foreach ( $mappings as $index => $mapping ) : ?>
							<?php $this->mapping_row( $index, $mapping, $modules ); ?>
						<?php endforeach; ?>
					</div>
					<?php submit_button( __( 'Save slider mappings', 'smart-multilingual' ) ); ?>
				</div>
			</form>
			<script type="text/template" id="tmpl-sml-slider-map-row"><?php $this->mapping_row( '__INDEX__', array( 'source' => '', 'translations' => array() ), $modules ); ?></script>
			<div class="sml-card">
				<h2><?php esc_html_e( 'Optional shortcode', 'smart-multilingual' ); ?></h2>
				<p><code>[sml_revslider alias="home-slider-source"]</code></p>
				<p class="description"><?php esc_html_e( 'Existing [rev_slider] shortcodes and supported Elementor Slider Revolution widgets are also mapped automatically.', 'smart-multilingual' ); ?></p>
			</div>
		</div>
		<?php
	}

	private function mapping_row( $index, $mapping, $modules ) {
		$source       = isset( $mapping['source'] ) ? (string) $mapping['source'] : '';
		$translations = ! empty( $mapping['translations'] ) && is_array( $mapping['translations'] ) ? $mapping['translations'] : array();
		$default       = SML_Languages::default_code();
		$default_cfg   = SML_Languages::get( $default );
		?>
		<div class="sml-slider-map-row">
			<div class="sml-slider-map-source">
				<label><strong><?php echo esc_html( sprintf( __( 'Source module — %s', 'smart-multilingual' ), $default_cfg['native'] ) ); ?></strong></label>
				<?php $this->module_field( 'revslider_mappings[' . $index . '][source]', $source, $modules ); ?>
			</div>
			<div class="sml-slider-map-languages">
				<?php foreach ( SML_Languages::enabled() as $lang ) : if ( $default === $lang ) continue; $cfg = SML_Languages::get( $lang ); ?>
					<div>
						<label><strong><?php echo esc_html( $cfg['native'] ); ?></strong> <small><?php echo esc_html( strtoupper( $lang ) ); ?></small></label>
						<?php $this->module_field( 'revslider_mappings[' . $index . '][translations][' . $lang . ']', isset( $translations[ $lang ] ) ? (string) $translations[ $lang ] : '', $modules ); ?>
					</div>
				<?php endforeach; ?>
			</div>
			<button type="button" class="button-link-delete sml-remove-slider-map"><?php esc_html_e( 'Remove', 'smart-multilingual' ); ?></button>
		</div>
		<?php
	}

	private function module_field( $name, $value, $modules ) {
		if ( empty( $modules ) ) {
			printf( '<input type="text" class="regular-text" name="%1$s" value="%2$s" placeholder="module-alias">', esc_attr( $name ), esc_attr( $value ) );
			return;
		}
		?>
		<select name="<?php echo esc_attr( $name ); ?>">
			<option value=""><?php esc_html_e( '— Select module —', 'smart-multilingual' ); ?></option>
			<?php foreach ( $modules as $module ) : ?>
				<option value="<?php echo esc_attr( $module['alias'] ); ?>" <?php selected( $value, $module['alias'] ); ?>><?php echo esc_html( $module['title'] . ' — ' . $module['alias'] . ( $module['id'] ? ' (#' . $module['id'] . ')' : '' ) ); ?></option>
			<?php endforeach; ?>
			<?php if ( $value && ! $this->module_exists( $value, $modules ) ) : ?><option value="<?php echo esc_attr( $value ); ?>" selected><?php echo esc_html( $value . ' — ' . __( 'not currently detected', 'smart-multilingual' ) ); ?></option><?php endif; ?>
		</select>
		<?php
	}

	private function module_exists( $identifier, $modules ) {
		foreach ( $modules as $module ) {
			if ( (string) $module['alias'] === (string) $identifier || (string) $module['id'] === (string) $identifier ) return true;
		}
		return false;
	}

	public function save_mappings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage slider translations.', 'smart-multilingual' ) );
		}
		check_admin_referer( 'sml_save_revslider_mappings' );

		$input = isset( $_POST['revslider_mappings'] ) && is_array( $_POST['revslider_mappings'] ) ? wp_unslash( $_POST['revslider_mappings'] ) : array();
		$clean = array();
		$seen  = array();
		$default = SML_Languages::default_code();
		foreach ( $input as $mapping ) {
			if ( ! is_array( $mapping ) ) continue;
			$source = $this->sanitize_identifier( isset( $mapping['source'] ) ? $mapping['source'] : '' );
			if ( ! $source || isset( $seen[ $source ] ) ) continue;
			$translations = array();
			foreach ( SML_Languages::enabled() as $lang ) {
				if ( $default === $lang ) continue;
				$value = isset( $mapping['translations'][ $lang ] ) ? $this->sanitize_identifier( $mapping['translations'][ $lang ] ) : '';
				if ( $value ) $translations[ $lang ] = $value;
			}
			$clean[]         = array( 'source' => $source, 'translations' => $translations );
			$seen[ $source ] = true;
		}
		update_option( self::OPTION_KEY, $clean, false );
		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=smart-multilingual-sliders' ) ) );
		exit;
	}

	private function sanitize_identifier( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		return preg_replace( '/[^A-Za-z0-9_.:-]/', '', $value );
	}

	public function mapped_identifier( $identifier, $lang = '' ) {
		$identifier = trim( (string) $identifier );
		$lang       = $lang ? sanitize_key( $lang ) : SML_Plugin::instance()->current_language();
		if ( ! $identifier || SML_Languages::is_default( $lang ) ) return $identifier;

		$module       = $this->find_module( $identifier );
		$source_alias = $module ? $module['alias'] : $identifier;
		foreach ( $this->mappings() as $mapping ) {
			$source = isset( $mapping['source'] ) ? (string) $mapping['source'] : '';
			if ( $source !== $identifier && $source !== $source_alias ) continue;
			$target = ! empty( $mapping['translations'][ $lang ] ) ? (string) $mapping['translations'][ $lang ] : '';
			if ( ! $target ) return $identifier;

			// Elementor integrations sometimes store the numeric module ID while
			// shortcodes store the alias. Preserve the identifier style when possible.
			if ( ctype_digit( $identifier ) ) {
				$target_module = $this->find_module( $target );
				return $target_module && $target_module['id'] ? (string) $target_module['id'] : $target;
			}
			return $target;
		}
		return $identifier;
	}

	public function filter_revslider_shortcode( $return, $tag, $attr, $m ) {
		if ( false !== $return || 'rev_slider' !== $tag || ! is_array( $attr ) ) return $return;
		$key = array_key_exists( 'alias', $attr ) ? 'alias' : ( array_key_exists( 0, $attr ) ? 0 : null );
		if ( null === $key ) return $return;

		$mapped = $this->mapped_identifier( $attr[ $key ] );
		if ( ! $mapped || (string) $mapped === (string) $attr[ $key ] ) return $return;
		$attr[ $key ] = $mapped;

		global $shortcode_tags;
		if ( empty( $shortcode_tags[ $tag ] ) || ! is_callable( $shortcode_tags[ $tag ] ) ) return $return;
		$content = isset( $m[5] ) && '' !== $m[5] ? $m[5] : null;
		return call_user_func( $shortcode_tags[ $tag ], $attr, $content, $tag );
	}

	public function shortcode( $atts ) {
		$atts  = shortcode_atts( array( 'alias' => '' ), $atts, 'sml_revslider' );
		$alias = $this->sanitize_identifier( $atts['alias'] );
		if ( ! $alias || ! shortcode_exists( 'rev_slider' ) ) return '';
		return do_shortcode( '[rev_slider alias="' . esc_attr( $alias ) . '"][/rev_slider]' );
	}

	public function prepare_elementor_widget( $element ) {
		if ( ! is_object( $element ) || ! method_exists( $element, 'get_name' ) || ! method_exists( $element, 'get_type' ) || 'widget' !== $element->get_type() ) return;
		$name = strtolower( (string) $element->get_name() );
		if ( false === strpos( $name, 'revslider' ) && false === strpos( $name, 'slider_revolution' ) && false === strpos( $name, 'slider-revolution' ) ) return;
		if ( ! method_exists( $element, 'get_settings' ) || ! method_exists( $element, 'set_settings' ) ) return;

		$settings = $element->get_settings();
		if ( ! is_array( $settings ) ) return;
		foreach ( $settings as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) || ! preg_match( '/(?:slider|module|alias)/i', (string) $key ) ) continue;
			$mapped = $this->mapped_identifier( (string) $value );
			if ( $mapped && (string) $mapped !== (string) $value ) {
				$element->set_settings( $key, ctype_digit( (string) $value ) ? absint( $mapped ) : $mapped );
			}
		}
	}

	private function modules() {
		if ( null !== $this->modules_cache ) return $this->modules_cache;
		$this->modules_cache = array();
		if ( ! class_exists( 'RevSliderSlider' ) ) return $this->modules_cache;

		try {
			$manager = new RevSliderSlider();
			$items   = method_exists( $manager, 'get_sliders' ) ? $manager->get_sliders() : array();
			foreach ( (array) $items as $item ) {
				if ( ! is_object( $item ) ) continue;
				$id    = method_exists( $item, 'get_id' ) ? absint( $item->get_id() ) : 0;
				$alias = method_exists( $item, 'get_alias' ) ? (string) $item->get_alias() : '';
				$title = method_exists( $item, 'get_title' ) ? (string) $item->get_title() : $alias;
				if ( ! $alias ) continue;
				$this->modules_cache[] = array( 'id' => $id, 'alias' => $alias, 'title' => $title ?: $alias );
			}
		} catch ( Throwable $error ) {
			$this->modules_cache = array();
		}
		return $this->modules_cache;
	}

	private function find_module( $identifier ) {
		foreach ( $this->modules() as $module ) {
			if ( (string) $module['alias'] === (string) $identifier || (string) $module['id'] === (string) $identifier ) return $module;
		}
		return null;
	}
}
