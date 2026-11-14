<?php
/**
 * Cloud Firestore REST client.
 *
 * @package WPW_To_Firebase
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes records to Cloud Firestore as documents, one collection per data type.
 */
final class WPWTF_Firestore {

	const API_BASE    = 'https://firestore.googleapis.com/v1';
	const HASH_OPTION = 'wpwtf_firestore_hashes_';

	/**
	 * Base URL of the Firestore REST API. Filterable so the Firestore emulator can be used in development.
	 *
	 * @return string
	 */
	private static function api_base() {
		/**
		 * Filters the Firestore REST API base URL, for example http://127.0.0.1:8080/v1 for the emulator.
		 *
		 * @param string $base Base URL.
		 */
		return untrailingslashit( (string) apply_filters( 'wpwtf_firestore_api_base', self::API_BASE ) );
	}

	/**
	 * Database resource name: projects/{project}/databases/{database}.
	 *
	 * @return string|WP_Error
	 */
	public static function database() {
		$account = WPWTF_Settings::service_account();
		$project = $account && ! empty( $account['project_id'] ) ? $account['project_id'] : '';
		if ( '' === $project ) {
			return new WP_Error( 'wpwtf_no_project', __( 'The service account key has no project ID. Paste a fresh key file from Project settings, Service accounts.', 'wpw-to-firebase' ) );
		}
		if ( ! preg_match( '/^[a-z0-9-]{4,30}$/', $project ) ) {
			return new WP_Error( 'wpwtf_bad_project', __( 'The service account key has an invalid project ID. Paste a fresh key file.', 'wpw-to-firebase' ) );
		}
		// Resource names are sent raw (Firestore rejects percent-encoding in them); every part is limited to safe characters.
		return 'projects/' . $project . '/databases/' . WPWTF_Settings::get()['firestore_database'];
	}

	/**
	 * Collection name for a data type, with the optional prefix.
	 *
	 * @param string $type users, products, or orders.
	 * @return string
	 */
	public static function collection( $type ) {
		return WPWTF_Settings::get()['collection_prefix'] . $type;
	}

	/**
	 * Full document name.
	 *
	 * @param string $database Database resource name.
	 * @param string $type     Data type.
	 * @param string $key      Document ID.
	 * @return string
	 */
	private static function document_name( $database, $type, $key ) {
		return $database . '/documents/' . self::collection( $type ) . '/' . $key;
	}

	/**
	 * Sends a request to the Firestore API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path after the API base, starting with projects/.
	 * @param array|null $data   JSON body.
	 * @return array|WP_Error Decoded response.
	 */
	private static function request( $method, $path, $data = null ) {
		$token = WPWTF_Firebase::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $data ) {
			$args['body'] = wp_json_encode( $data );
		}
		$response = wp_remote_request( self::api_base() . '/' . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 401 === $code || 403 === $code ) {
			delete_transient( WPWTF_Firebase::TOKEN_TRANSIENT );
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'wpwtf_denied', sprintf( __( 'Firestore denied access (HTTP %d). Check that the service account belongs to this project and that Cloud Firestore is enabled.', 'wpw-to-firebase' ), $code ) );
		}
		if ( 404 === $code ) {
			return new WP_Error( 'wpwtf_no_firestore', __( 'Firestore database not found. Create a Cloud Firestore database in the Firebase console, or check the database ID in the settings.', 'wpw-to-firebase' ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			$detail = is_array( $body ) && isset( $body['error']['message'] ) ? ' ' . sanitize_text_field( (string) $body['error']['message'] ) : '';
			/* translators: 1: HTTP status code, 2: error details from Google. */
			return new WP_Error( 'wpwtf_http_error', trim( sprintf( __( 'Firestore returned an unexpected response (HTTP %1$d).%2$s', 'wpw-to-firebase' ), $code, $detail ) ) );
		}
		return is_array( $body ) ? $body : array();
	}

	/**
	 * Checks the connection by listing at most one document.
	 *
	 * @return true|WP_Error
	 */
	public static function test() {
		$database = self::database();
		if ( is_wp_error( $database ) ) {
			return $database;
		}
		$types  = WPWTF_Settings::enabled_types();
		$type   = $types ? $types[0] : 'products';
		$result = self::request( 'GET', $database . '/documents/' . self::collection( $type ) . '?pageSize=1' );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Writes records as documents. Records that have not changed since the last write are skipped,
	 * which keeps scheduled full syncs inside Firestore's write quota.
	 *
	 * @param string $type           Data type.
	 * @param array  $records        Records keyed by document ID.
	 * @param bool   $skip_unchanged Skip records with the same content as the last write.
	 * @return int|WP_Error Number of documents written.
	 */
	public static function write( $type, array $records, $skip_unchanged = true ) {
		$database = self::database();
		if ( is_wp_error( $database ) ) {
			return $database;
		}
		$hashes  = self::hashes( $type );
		$writes  = array();
		$pending = array();
		foreach ( $records as $key => $record ) {
			$fields = self::encode_fields( $record );
			$hash   = substr( md5( self::collection( $type ) . wp_json_encode( $fields ) ), 0, 12 );
			if ( $skip_unchanged && isset( $hashes[ $key ] ) && $hashes[ $key ] === $hash ) {
				continue;
			}
			$writes[]        = array(
				'update' => array(
					'name'   => self::document_name( $database, $type, (string) $key ),
					'fields' => (object) $fields,
				),
			);
			$pending[ $key ] = $hash;
		}
		if ( ! $writes ) {
			return 0;
		}

		$result = self::request( 'POST', $database . '/documents:batchWrite', array( 'writes' => $writes ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// batchWrite applies each write on its own; a status without a code (or code 0) means success.
		$keys    = array_keys( $pending );
		$failed  = array();
		$written = 0;
		foreach ( $keys as $i => $key ) {
			$code = isset( $result['status'][ $i ]['code'] ) ? (int) $result['status'][ $i ]['code'] : 0;
			if ( 0 === $code ) {
				$hashes[ $key ] = $pending[ $key ];
				++$written;
			} else {
				$failed[] = isset( $result['status'][ $i ]['message'] ) ? sanitize_text_field( (string) $result['status'][ $i ]['message'] ) : (string) $code;
			}
		}
		self::save_hashes( $type, $hashes );

		if ( $failed ) {
			/* translators: 1: number of documents, 2: first error message. */
			return new WP_Error( 'wpwtf_write_failed', sprintf( _n( '%1$d document could not be written: %2$s', '%1$d documents could not be written: %2$s', count( $failed ), 'wpw-to-firebase' ), count( $failed ), $failed[0] ) );
		}
		return $written;
	}

	/**
	 * Deletes a document.
	 *
	 * @param string $type Data type.
	 * @param string $key  Document ID.
	 * @return true|WP_Error
	 */
	public static function delete( $type, $key ) {
		$database = self::database();
		if ( is_wp_error( $database ) ) {
			return $database;
		}
		$result = self::request( 'DELETE', self::document_name( $database, $type, $key ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$hashes = self::hashes( $type );
		unset( $hashes[ $key ] );
		self::save_hashes( $type, $hashes );
		return true;
	}

	/**
	 * Forgets which records were written, so the next full sync writes everything again.
	 */
	public static function reset_hashes() {
		foreach ( array( 'users', 'products', 'orders' ) as $type ) {
			delete_option( self::HASH_OPTION . $type );
		}
	}

	/**
	 * Saved content hashes for a type.
	 *
	 * @param string $type Data type.
	 * @return array
	 */
	private static function hashes( $type ) {
		$hashes = get_option( self::HASH_OPTION . $type, array() );
		return is_array( $hashes ) ? $hashes : array();
	}

	/**
	 * Saves content hashes for a type.
	 *
	 * @param string $type   Data type.
	 * @param array  $hashes Hashes keyed by document ID.
	 */
	private static function save_hashes( $type, array $hashes ) {
		update_option( self::HASH_OPTION . $type, $hashes, false );
	}

	/**
	 * Encodes a record as Firestore document fields.
	 *
	 * @param array $record Record.
	 * @return array
	 */
	public static function encode_fields( array $record ) {
		$fields = array();
		foreach ( $record as $name => $value ) {
			$fields[ self::field_name( $name ) ] = self::encode( $value, false );
		}
		return $fields;
	}

	/**
	 * Firestore field names cannot be empty or look like __name__.
	 *
	 * @param string|int $name Key.
	 * @return string
	 */
	private static function field_name( $name ) {
		$name = (string) $name;
		if ( '' === $name ) {
			return '_';
		}
		// Reserved names such as __name__ become _name__.
		return preg_match( '/^__.*__$/', $name ) ? substr( $name, 1 ) : $name;
	}

	/**
	 * Encodes a PHP value as a Firestore Value.
	 *
	 * @param mixed $value    Value.
	 * @param bool  $in_array Whether the value is an array element (Firestore arrays cannot contain arrays).
	 * @return array
	 */
	public static function encode( $value, $in_array ) {
		if ( null === $value ) {
			return array( 'nullValue' => null );
		}
		if ( is_bool( $value ) ) {
			return array( 'booleanValue' => $value );
		}
		if ( is_int( $value ) ) {
			return array( 'integerValue' => (string) $value );
		}
		if ( is_float( $value ) ) {
			return is_finite( $value ) ? array( 'doubleValue' => $value ) : array( 'nullValue' => null );
		}
		if ( is_array( $value ) ) {
			$is_list = array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( $is_list && ! $in_array ) {
				return array(
					'arrayValue' => (object) ( $value ? array(
						'values' => array_map(
							static function ( $item ) {
								return self::encode( $item, true );
							},
							$value
						),
					) : array() ),
				);
			}
			// Maps, and lists nested directly in a list, are stored as maps.
			$fields = array();
			foreach ( $value as $key => $item ) {
				$fields[ self::field_name( $key ) ] = self::encode( $item, false );
			}
			return array( 'mapValue' => (object) ( $fields ? array( 'fields' => (object) $fields ) : array() ) );
		}
		if ( is_object( $value ) ) {
			return self::encode( (array) $value, $in_array );
		}
		return array( 'stringValue' => (string) $value );
	}
}
