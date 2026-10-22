<?php
/**
 * Settings screen.
 *
 * @package WPW_To_Firebase
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page, connection test, manual sync, and privacy policy text.
 */
final class WPWTF_Admin {

	const PAGE = 'wpw-to-firebase';

	/**
	 * Registers admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
		add_action( 'admin_post_wpwtf_test_connection', array( __CLASS__, 'test_connection' ) );
		add_action( 'admin_post_wpwtf_sync_now', array( __CLASS__, 'sync_now' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPWTF_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Adds the page under Settings.
	 */
	public static function add_page() {
		add_options_page(
			__( 'WPW to Firebase', 'wpw-to-firebase' ),
			__( 'WPW to Firebase', 'wpw-to-firebase' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Adds a Settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'wpw-to-firebase' ) . '</a>' );
		return $links;
	}

	/**
	 * Registers the setting and its fields.
	 */
	public static function register_settings() {
		register_setting(
			'wpwtf_settings_group',
			WPWTF_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'WPWTF_Settings', 'sanitize' ),
				'default'           => WPWTF_Settings::defaults(),
			)
		);

		add_settings_section( 'wpwtf_connection', __( 'Firebase connection', 'wpw-to-firebase' ), array( __CLASS__, 'render_connection_intro' ), self::PAGE );
		add_settings_field( 'wpwtf_database_url', __( 'Realtime Database URL', 'wpw-to-firebase' ), array( __CLASS__, 'render_database_url' ), self::PAGE, 'wpwtf_connection', array( 'label_for' => 'wpwtf_database_url' ) );
		add_settings_field( 'wpwtf_service_account', __( 'Service account key', 'wpw-to-firebase' ), array( __CLASS__, 'render_service_account' ), self::PAGE, 'wpwtf_connection', array( 'label_for' => 'wpwtf_service_account_json' ) );
		add_settings_field( 'wpwtf_path', __( 'Database path', 'wpw-to-firebase' ), array( __CLASS__, 'render_path' ), self::PAGE, 'wpwtf_connection', array( 'label_for' => 'wpwtf_path' ) );

		add_settings_section( 'wpwtf_data', __( 'What to sync', 'wpw-to-firebase' ), '__return_false', self::PAGE );
		add_settings_field( 'wpwtf_types', __( 'Data', 'wpw-to-firebase' ), array( __CLASS__, 'render_types' ), self::PAGE, 'wpwtf_data' );
		add_settings_field( 'wpwtf_live_updates', __( 'Live updates', 'wpw-to-firebase' ), array( __CLASS__, 'render_live_updates' ), self::PAGE, 'wpwtf_data' );
		add_settings_field( 'wpwtf_schedule', __( 'Full sync schedule', 'wpw-to-firebase' ), array( __CLASS__, 'render_schedule' ), self::PAGE, 'wpwtf_data', array( 'label_for' => 'wpwtf_schedule' ) );
	}

	/**
	 * Explains where to find the connection details.
	 */
	public static function render_connection_intro() {
		echo '<p>' . esc_html__( 'In the Firebase console, open Project settings, then Service accounts, and click "Generate new private key". Paste the contents of the downloaded file below.', 'wpw-to-firebase' ) . '</p>';
	}

	/**
	 * Database URL field.
	 */
	public static function render_database_url() {
		printf(
			'<input type="url" id="wpwtf_database_url" class="regular-text code" name="%1$s[database_url]" value="%2$s" placeholder="https://your-project-default-rtdb.firebaseio.com" />',
			esc_attr( WPWTF_Settings::OPTION ),
			esc_attr( WPWTF_Settings::get()['database_url'] )
		);
	}

	/**
	 * Service account field. The saved private key is never printed.
	 */
	public static function render_service_account() {
		$account = WPWTF_Settings::service_account();
		if ( $account ) {
			echo '<p>';
			/* translators: %s: service account email address. */
			printf( esc_html__( 'Connected as %s.', 'wpw-to-firebase' ), '<code>' . esc_html( $account['client_email'] ) . '</code>' );
			echo '</p><p>';
			printf(
				'<label><input type="checkbox" name="%1$s[remove_service_account]" value="1" /> %2$s</label>',
				esc_attr( WPWTF_Settings::OPTION ),
				esc_html__( 'Remove the saved key', 'wpw-to-firebase' )
			);
			echo '</p><p class="description">' . esc_html__( 'To replace the key, paste a new one below.', 'wpw-to-firebase' ) . '</p>';
		}
		printf(
			'<textarea id="wpwtf_service_account_json" class="large-text code" rows="6" name="%1$s[service_account_json]" autocomplete="off" spellcheck="false" placeholder="{&quot;type&quot;: &quot;service_account&quot;, ...}"></textarea>',
			esc_attr( WPWTF_Settings::OPTION )
		);
		echo '<p class="description">' . esc_html__( 'The key is stored in your WordPress database and is never shown again after saving.', 'wpw-to-firebase' ) . '</p>';
	}

	/**
	 * Root path field.
	 */
	public static function render_path() {
		printf(
			'<input type="text" id="wpwtf_path" class="regular-text code" name="%1$s[path]" value="%2$s" />',
			esc_attr( WPWTF_Settings::OPTION ),
			esc_attr( WPWTF_Settings::get()['path'] )
		);
		echo '<p class="description">' . esc_html__( 'Data is written under this path, for example wordpress/products/product_42.', 'wpw-to-firebase' ) . '</p>';
	}

	/**
	 * Data type checkboxes.
	 */
	public static function render_types() {
		$settings    = WPWTF_Settings::get();
		$woocommerce = WPWTF_Settings::woocommerce_active();
		$types       = array(
			'sync_users'    => array( __( 'Users', 'wpw-to-firebase' ), __( 'Email, name, roles, and registration date. Passwords are never sent.', 'wpw-to-firebase' ), false ),
			'sync_products' => array( __( 'WooCommerce products', 'wpw-to-firebase' ), __( 'Prices, stock, images, categories, attributes, and variations.', 'wpw-to-firebase' ), true ),
			'sync_orders'   => array( __( 'WooCommerce orders', 'wpw-to-firebase' ), __( 'Totals, line items, and customer billing and shipping details.', 'wpw-to-firebase' ), true ),
		);

		echo '<fieldset>';
		foreach ( $types as $key => $type ) {
			$disabled = $type[2] && ! $woocommerce;
			printf(
				'<p><label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s %4$s /> <strong>%5$s</strong></label><br /><span class="description">%6$s</span></p>',
				esc_attr( WPWTF_Settings::OPTION ),
				esc_attr( $key ),
				checked( $settings[ $key ], true, false ),
				disabled( $disabled, true, false ),
				esc_html( $type[0] ),
				esc_html( $disabled ? __( 'Requires WooCommerce.', 'wpw-to-firebase' ) : $type[1] )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Users and orders contain personal data. Make sure your privacy policy covers sending it to Firebase.', 'wpw-to-firebase' ) . '</p>';
	}

	/**
	 * Live updates checkbox.
	 */
	public static function render_live_updates() {
		printf(
			'<label><input type="checkbox" name="%1$s[live_updates]" value="1" %2$s /> %3$s</label>',
			esc_attr( WPWTF_Settings::OPTION ),
			checked( WPWTF_Settings::get()['live_updates'], true, false ),
			esc_html__( 'Send changes to Firebase a few seconds after an item is created, updated, or deleted', 'wpw-to-firebase' )
		);
	}

	/**
	 * Recurring full sync select.
	 */
	public static function render_schedule() {
		$labels = array(
			'none'       => __( 'Only when I click Sync now', 'wpw-to-firebase' ),
			'hourly'     => __( 'Hourly', 'wpw-to-firebase' ),
			'twicedaily' => __( 'Twice daily', 'wpw-to-firebase' ),
			'daily'      => __( 'Daily', 'wpw-to-firebase' ),
		);
		printf( '<select id="wpwtf_schedule" name="%s[schedule]">', esc_attr( WPWTF_Settings::OPTION ) );
		foreach ( $labels as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( WPWTF_Settings::get()['schedule'], $value, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Renders the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php self::render_notice(); ?>
			<?php self::render_status(); ?>
			<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
				<?php
				settings_fields( 'wpwtf_settings_group' );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>
			<hr />
			<h2><?php esc_html_e( 'Sync', 'wpw-to-firebase' ); ?></h2>
			<p><?php esc_html_e( 'A full sync sends every enabled item in the background, 50 at a time. It continues as your site receives visits, so it may take a few minutes on a quiet site.', 'wpw-to-firebase' ); ?></p>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline-block;margin-right:8px">
				<input type="hidden" name="action" value="wpwtf_test_connection" />
				<?php wp_nonce_field( 'wpwtf_test_connection' ); ?>
				<?php submit_button( __( 'Test connection', 'wpw-to-firebase' ), 'secondary', 'submit', false ); ?>
			</form>
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline-block">
				<input type="hidden" name="action" value="wpwtf_sync_now" />
				<?php wp_nonce_field( 'wpwtf_sync_now' ); ?>
				<?php submit_button( __( 'Sync now', 'wpw-to-firebase' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Shows the latest sync status and error.
	 */
	private static function render_status() {
		$state  = WPWTF_Sync::state();
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$labels = array(
			'users'    => __( 'users', 'wpw-to-firebase' ),
			'products' => __( 'products', 'wpw-to-firebase' ),
			'orders'   => __( 'orders', 'wpw-to-firebase' ),
		);
		$counts = array();
		foreach ( (array) $state['counts'] as $type => $count ) {
			if ( isset( $labels[ $type ] ) ) {
				$counts[] = number_format_i18n( $count ) . ' ' . $labels[ $type ];
			}
		}
		$counts = implode( ', ', $counts );

		if ( $state['running'] ) {
			/* translators: %s: items synced so far, such as "50 products, 0 orders". */
			$message = sprintf( __( 'Full sync in progress: %s so far.', 'wpw-to-firebase' ), $counts );
			$class   = 'notice-info';
		} elseif ( $state['error'] ) {
			/* translators: 1: date and time, 2: error message. */
			$message = sprintf( __( 'The last full sync stopped on %1$s: %2$s', 'wpw-to-firebase' ), wp_date( $format, $state['finished'] ), $state['error'] );
			$class   = 'notice-error';
		} elseif ( $state['finished'] ) {
			/* translators: 1: date and time, 2: items synced, such as "120 products, 35 orders". */
			$message = sprintf( __( 'Last full sync finished on %1$s: %2$s.', 'wpw-to-firebase' ), wp_date( $format, $state['finished'] ), $counts );
			$class   = 'notice-success';
		} else {
			return;
		}
		printf( '<div class="notice %1$s inline"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );

		$error = get_option( WPWTF_Sync::ERROR_OPTION );
		if ( is_array( $error ) && ! empty( $error['message'] ) && $error['time'] > $state['started'] ) {
			/* translators: 1: date and time, 2: error message. */
			$message = sprintf( __( 'A live update failed on %1$s: %2$s', 'wpw-to-firebase' ), wp_date( $format, $error['time'] ), $error['message'] );
			printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html( $message ) );
		}
	}

	/**
	 * Tests the connection and reports the result.
	 */
	public static function test_connection() {
		check_admin_referer( 'wpwtf_test_connection' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wpw-to-firebase' ) );
		}
		$result = WPWTF_Firebase::request( 'GET', WPWTF_Firebase::path(), null, array( 'shallow' => 'true' ) );
		self::redirect_with_notice(
			is_wp_error( $result ) ? 'error' : 'success',
			is_wp_error( $result ) ? $result->get_error_message() : __( 'Connected to Firebase successfully.', 'wpw-to-firebase' )
		);
	}

	/**
	 * Starts a full sync.
	 */
	public static function sync_now() {
		check_admin_referer( 'wpwtf_sync_now' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wpw-to-firebase' ) );
		}
		$result = WPWTF_Sync::start();
		if ( true === $result ) {
			spawn_cron();
		}
		self::redirect_with_notice(
			is_wp_error( $result ) ? 'error' : 'success',
			is_wp_error( $result ) ? $result->get_error_message() : __( 'Full sync started. Reload this page to see progress.', 'wpw-to-firebase' )
		);
	}

	/**
	 * Stores a one-time notice for the current user and returns to the settings page.
	 *
	 * @param string $type    success or error.
	 * @param string $message Message to show.
	 */
	private static function redirect_with_notice( $type, $message ) {
		set_transient(
			'wpwtf_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Prints and clears the current user's one-time notice.
	 */
	private static function render_notice() {
		$key    = 'wpwtf_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Suggested privacy policy text.
	 */
	public static function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'WPW to Firebase', 'wpw-to-firebase' ),
			'<p>' . esc_html__( 'This site may send account details (email address, name, and role) and order details (billing and shipping addresses, contact details, and purchased items) to a Firebase Realtime Database operated by Google, depending on the plugin settings. The data is used to power connected apps and services.', 'wpw-to-firebase' ) . '</p>'
		);
	}

	/**
	 * Settings page URL.
	 *
	 * @return string
	 */
	private static function page_url() {
		return admin_url( 'options-general.php?page=' . self::PAGE );
	}
}
