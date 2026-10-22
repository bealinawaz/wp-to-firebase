<?php
/**
 * Removes the plugin's settings, credentials, and scheduled events.
 *
 * @package WPW_To_Firebase
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'wpwtf_settings', 'wpwtf_service_account', 'wpwtf_sync_state', 'wpwtf_last_error', 'fbwp_to_firebase_database_url', 'fbwp_to_firebase_api_key' ) as $wpwtf_option ) {
	delete_option( $wpwtf_option );
}
delete_transient( 'wpwtf_access_token' );

foreach ( array( 'wpwtf_run_sync', 'wpwtf_scheduled_sync', 'wpwtf_sync_item', 'wpwtf_delete_item' ) as $wpwtf_hook ) {
	wp_unschedule_hook( $wpwtf_hook );
}
