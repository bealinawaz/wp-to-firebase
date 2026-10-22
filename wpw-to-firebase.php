<?php
/**
 * Plugin Name:          WP to Firebase – Sync WooCommerce & WordPress Data
 * Plugin URI:           https://wordpress.org/plugins/wpw-to-firebase/
 * Description:          Syncs WordPress users and WooCommerce products and orders to a Firebase Realtime Database.
 * Version:              2.0.1
 * Requires at least:    6.2
 * Requires PHP:         7.4
 * Author:               Ali Nawaz
 * Author URI:           https://bealinawaz.com
 * License:              GPLv2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          wpw-to-firebase
 * WC requires at least: 7.1
 * WC tested up to:      11.2
 *
 * @package WPW_To_Firebase
 */

defined( 'ABSPATH' ) || exit;

define( 'WPWTF_VERSION', '2.0.1' );
define( 'WPWTF_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-wpwtf-settings.php';
require_once __DIR__ . '/includes/class-wpwtf-firebase.php';
require_once __DIR__ . '/includes/class-wpwtf-exporter.php';
require_once __DIR__ . '/includes/class-wpwtf-sync.php';

// Orders are read through WooCommerce's CRUD API, so the plugin works with both order storage types.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WPWTF_FILE, true );
		}
	}
);

WPWTF_Sync::init();

if ( is_admin() ) {
	require_once __DIR__ . '/includes/class-wpwtf-admin.php';
	WPWTF_Admin::init();
}

register_activation_hook( __FILE__, array( 'WPWTF_Sync', 'reschedule' ) );
register_deactivation_hook( __FILE__, array( 'WPWTF_Sync', 'deactivate' ) );
