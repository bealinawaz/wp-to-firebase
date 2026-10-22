<?php
/**
 * Firebase Realtime Database REST client.
 *
 * @package WPW_To_Firebase
 */

defined( 'ABSPATH' ) || exit;

/**
 * Authenticates with a Google service account and calls the Realtime Database REST API.
 */
final class WPWTF_Firebase {

	const TOKEN_URL       = 'https://oauth2.googleapis.com/token';
	const TOKEN_TRANSIENT = 'wpwtf_access_token';
	const SCOPES          = 'https://www.googleapis.com/auth/firebase.database https://www.googleapis.com/auth/userinfo.email';

	/**
	 * Returns a cached OAuth access token, requesting a new one when it has expired.
	 *
	 * @return string|WP_Error
	 */
	public static function access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$account = WPWTF_Settings::service_account();
		if ( null === $account ) {
			return new WP_Error( 'wpwtf_no_credentials', __( 'Add a service account key in the plugin settings.', 'wpw-to-firebase' ) );
		}
		if ( ! function_exists( 'openssl_sign' ) ) {
			return new WP_Error( 'wpwtf_no_openssl', __( 'The PHP OpenSSL extension is required to sign Firebase requests. Ask your host to enable it.', 'wpw-to-firebase' ) );
		}

		$now      = time();
		$unsigned = self::base64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) ) . '.' . self::base64url(
			wp_json_encode(
				array(
					'iss'   => $account['client_email'],
					'scope' => self::SCOPES,
					'aud'   => self::TOKEN_URL,
					'iat'   => $now,
					'exp'   => $now + HOUR_IN_SECONDS,
				)
			)
		);

		$signature = '';
		if ( ! openssl_sign( $unsigned, $signature, $account['private_key'], 'sha256WithRSAEncryption' ) ) {
			return new WP_Error( 'wpwtf_bad_key', __( 'The service account private key could not be read. Paste a fresh key file.', 'wpw-to-firebase' ) );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $unsigned . '.' . self::base64url( $signature ),
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'wpwtf_token_failed', sprintf( __( 'Google rejected the service account key (HTTP %d). Check that the key is current and has not been deleted.', 'wpw-to-firebase' ), $code ) );
		}

		$lifetime = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : HOUR_IN_SECONDS;
		set_transient( self::TOKEN_TRANSIENT, $body['access_token'], max( MINUTE_IN_SECONDS, $lifetime - 2 * MINUTE_IN_SECONDS ) );
		return $body['access_token'];
	}

	/**
	 * Sends a request to the Realtime Database REST API.
	 *
	 * @param string     $method HTTP method: GET, PUT, PATCH, or DELETE.
	 * @param string     $path   Database path, without the .json suffix.
	 * @param array|null $data   Body to send as JSON.
	 * @param array      $query  Extra query parameters.
	 * @return mixed|WP_Error Decoded response body, or an error.
	 */
	public static function request( $method, $path, $data = null, $query = array() ) {
		$settings = WPWTF_Settings::get();
		if ( '' === $settings['database_url'] ) {
			return new WP_Error( 'wpwtf_no_database', __( 'Add your Realtime Database URL in the plugin settings.', 'wpw-to-firebase' ) );
		}

		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$query['access_token'] = $token;
		$url                   = $settings['database_url'] . '/' . $path . '.json?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array( 'Content-Type' => 'application/json' ),
		);
		if ( null !== $data ) {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $code || 403 === $code ) {
			delete_transient( self::TOKEN_TRANSIENT );
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'wpwtf_denied', sprintf( __( 'Firebase denied access (HTTP %d). Check that the service account belongs to the same project as the database.', 'wpw-to-firebase' ), $code ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'wpwtf_http_error', sprintf( __( 'Firebase returned an unexpected response (HTTP %d).', 'wpw-to-firebase' ), $code ) );
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}

	/**
	 * Joins path segments under the configured root path.
	 *
	 * @param string ...$segments Path segments below the root.
	 * @return string
	 */
	public static function path( ...$segments ) {
		$parts = array_merge( explode( '/', WPWTF_Settings::get()['path'] ), array_map( 'strval', $segments ) );
		return implode( '/', array_map( 'rawurlencode', $parts ) );
	}

	/**
	 * Base64url encoding used by JSON Web Tokens.
	 *
	 * @param string $data Raw bytes.
	 * @return string
	 */
	private static function base64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by the JWT format.
	}
}
