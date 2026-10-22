<?php
/**
 * Plugin settings and service account storage.
 *
 * @package WPW_To_Firebase
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, validates, and stores the plugin settings.
 */
final class WPWTF_Settings {

	const OPTION             = 'wpwtf_settings';
	const CREDENTIALS_OPTION = 'wpwtf_service_account';
	const SCHEDULES          = array( 'none', 'hourly', 'twicedaily', 'daily' );

	/**
	 * Default settings. Personal data (users and orders) is off until the site owner opts in.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'database_url'  => '',
			'path'          => 'wordpress',
			'sync_users'    => false,
			'sync_products' => true,
			'sync_orders'   => false,
			'live_updates'  => true,
			'schedule'      => 'none',
		);
	}

	/**
	 * Current settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	/**
	 * Whether WooCommerce is active, which products and orders depend on.
	 *
	 * @return bool
	 */
	public static function woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Data types the site owner chose to sync and that can be read on this site.
	 *
	 * @return string[]
	 */
	public static function enabled_types() {
		$settings = self::get();
		$types    = array();
		if ( $settings['sync_users'] ) {
			$types[] = 'users';
		}
		if ( self::woocommerce_active() ) {
			if ( $settings['sync_products'] ) {
				$types[] = 'products';
			}
			if ( $settings['sync_orders'] ) {
				$types[] = 'orders';
			}
		}
		return $types;
	}

	/**
	 * The saved service account, or null when none is stored.
	 *
	 * @return array|null
	 */
	public static function service_account() {
		$raw     = get_option( self::CREDENTIALS_OPTION, '' );
		$account = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : null;
		return is_array( $account ) ? $account : null;
	}

	/**
	 * Validates a service account key file's contents.
	 *
	 * @param string $json Contents of the JSON key file.
	 * @return array|WP_Error The fields the plugin uses, or an error.
	 */
	public static function parse_service_account( $json ) {
		$account = json_decode( $json, true );
		if ( ! is_array( $account ) ) {
			return new WP_Error( 'wpwtf_invalid_json', __( 'The service account key is not valid JSON. Paste the full contents of the downloaded key file.', 'wpw-to-firebase' ) );
		}
		if ( ( $account['type'] ?? '' ) !== 'service_account' || empty( $account['client_email'] ) || empty( $account['private_key'] ) ) {
			return new WP_Error( 'wpwtf_invalid_account', __( 'This JSON is not a service account key. In the Firebase console, go to Project settings, Service accounts, and generate a new private key.', 'wpw-to-firebase' ) );
		}
		if ( ! is_email( $account['client_email'] ) ) {
			return new WP_Error( 'wpwtf_invalid_account', __( 'The service account key has an invalid client email.', 'wpw-to-firebase' ) );
		}
		return array(
			'project_id'   => sanitize_text_field( $account['project_id'] ?? '' ),
			'client_email' => sanitize_email( $account['client_email'] ),
			'private_key'  => $account['private_key'],
		);
	}

	/**
	 * Sanitizes the settings form. A pasted service account key is stored separately and never echoed back.
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$current  = self::get();
		$settings = self::defaults();

		$url = isset( $input['database_url'] ) ? trim( (string) $input['database_url'] ) : '';
		if ( '' !== $url ) {
			$parts = wp_parse_url( esc_url_raw( $url ) );
			$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
			if ( isset( $parts['scheme'] ) && 'https' === $parts['scheme'] && preg_match( '/\.(firebaseio\.com|firebasedatabase\.app)$/', $host ) ) {
				$settings['database_url'] = 'https://' . $host;
			} else {
				$settings['database_url'] = $current['database_url'];
				add_settings_error( self::OPTION, 'wpwtf_database_url', __( 'Enter your Realtime Database URL, ending in firebaseio.com or firebasedatabase.app.', 'wpw-to-firebase' ) );
			}
		}

		$path             = isset( $input['path'] ) ? preg_replace( '/[^A-Za-z0-9_\-\/]/', '', (string) $input['path'] ) : '';
		$path             = trim( preg_replace( '#/+#', '/', $path ), '/' );
		$settings['path'] = '' === $path ? 'wordpress' : $path;

		foreach ( array( 'sync_users', 'sync_products', 'sync_orders', 'live_updates' ) as $key ) {
			$settings[ $key ] = ! empty( $input[ $key ] );
		}

		$schedule             = isset( $input['schedule'] ) ? (string) $input['schedule'] : 'none';
		$settings['schedule'] = in_array( $schedule, self::SCHEDULES, true ) ? $schedule : 'none';

		if ( ! empty( $input['remove_service_account'] ) ) {
			delete_option( self::CREDENTIALS_OPTION );
			delete_transient( WPWTF_Firebase::TOKEN_TRANSIENT );
		}

		$json = isset( $input['service_account_json'] ) ? trim( (string) $input['service_account_json'] ) : '';
		if ( '' !== $json ) {
			$account = self::parse_service_account( $json );
			if ( is_wp_error( $account ) ) {
				add_settings_error( self::OPTION, 'wpwtf_service_account', $account->get_error_message() );
			} else {
				update_option( self::CREDENTIALS_OPTION, wp_json_encode( $account ), false );
				delete_transient( WPWTF_Firebase::TOKEN_TRANSIENT );
			}
		}

		return $settings;
	}
}
