<?php
/**
 * Plugin Name: Smart Multilingual
 * Plugin URI: https://novinmaster.com/
 * Description: Lightweight multilingual toolkit for WordPress, Elementor and WooCommerce-ready websites.
 * Version: 1.0.8
 * Author: Ali Vanaei
 * Author URI: https://novinmaster.com/
 * Text Domain: smart-multilingual
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'SML_VERSION', '1.0.8' );
define( 'SML_FILE', __FILE__ );
define( 'SML_DIR', plugin_dir_path( __FILE__ ) );
define( 'SML_URL', plugin_dir_url( __FILE__ ) );

require_once SML_DIR . 'includes/class-sml-languages.php';
require_once SML_DIR . 'includes/class-sml-compatibility.php';
require_once SML_DIR . 'includes/class-sml-typography.php';
require_once SML_DIR . 'includes/class-sml-migrations.php';
require_once SML_DIR . 'includes/class-sml-plugin.php';
require_once SML_DIR . 'includes/class-sml-admin.php';
require_once SML_DIR . 'includes/class-sml-elementor.php';
require_once SML_DIR . 'includes/class-sml-taxonomy.php';
require_once SML_DIR . 'includes/class-sml-strings.php';
require_once SML_DIR . 'includes/class-sml-language-manager.php';
require_once SML_DIR . 'includes/class-sml-admin-filters.php';
require_once SML_DIR . 'includes/class-sml-media.php';
require_once SML_DIR . 'includes/class-sml-theme-compat.php';
require_once SML_DIR . 'includes/class-sml-revslider.php';
require_once SML_DIR . 'includes/class-sml-site-profile.php';

register_activation_hook( __FILE__, array( 'SML_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SML_Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		if ( SML_Plugin::should_skip_request() ) return;
		SML_Plugin::instance();
	},
	1
);

/*
 * Database migrations must never run in a public request. A large legacy site
 * can contain thousands of posts and terms; doing migration work while serving
 * the frontend can exhaust PHP workers and surface as a persistent 503. The
 * activation hook handles new installs and this admin-only fallback handles an
 * in-place plugin update where WordPress does not fire the activation hook.
 */
add_action(
	'admin_init',
	static function () {
		$registry = get_option( SML_Languages::OPTION_KEY, false );
		if ( SML_VERSION === (string) get_option( SML_Migrations::DB_VERSION_OPTION, '' ) && is_array( $registry ) && ! empty( $registry ) ) return;

		/*
		 * Never let a failed update create an endless admin retry loop. The lock is
		 * short-lived and the migration only normalizes options; it never scans all
		 * posts, products or terms.
		 */
		if ( get_transient( 'sml_migration_lock' ) ) return;
		set_transient( 'sml_migration_lock', 1, MINUTE_IN_SECONDS );
		try {
			SML_Migrations::run();
			delete_option( 'sml_activation_pending' );
		} catch ( Throwable $error ) {
			update_option( 'sml_last_migration_error', sanitize_text_field( $error->getMessage() ), false );
		} finally {
			delete_transient( 'sml_migration_lock' );
		}
	},
	1
);
