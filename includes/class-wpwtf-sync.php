<?php
/**
 * Background syncing through WP-Cron.
 *
 * @package WPW_To_Firebase
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs full syncs in small batches and pushes individual changes as they happen.
 * Nothing here runs during a normal page load; all work happens in cron events.
 */
final class WPWTF_Sync {

	const STATE_OPTION = 'wpwtf_sync_state';
	const ERROR_OPTION = 'wpwtf_last_error';
	const RUN_HOOK     = 'wpwtf_run_sync';
	const CRON_HOOK    = 'wpwtf_scheduled_sync';
	const ITEM_HOOK    = 'wpwtf_sync_item';
	const DELETE_HOOK  = 'wpwtf_delete_item';

	/**
	 * Registers cron handlers and change listeners.
	 */
	public static function init() {
		add_action( self::RUN_HOOK, array( __CLASS__, 'run_batch' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'start' ) );
		add_action( self::ITEM_HOOK, array( __CLASS__, 'sync_item' ), 10, 2 );
		add_action( self::DELETE_HOOK, array( __CLASS__, 'delete_item' ), 10, 2 );
		add_action( 'add_option_' . WPWTF_Settings::OPTION, array( __CLASS__, 'reschedule' ) );
		add_action( 'update_option_' . WPWTF_Settings::OPTION, array( __CLASS__, 'reschedule' ) );

		add_action( 'user_register', array( __CLASS__, 'queue_user' ) );
		add_action( 'profile_update', array( __CLASS__, 'queue_user' ) );
		add_action( 'deleted_user', array( __CLASS__, 'queue_user_delete' ) );

		add_action( 'woocommerce_new_product', array( __CLASS__, 'queue_product' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'queue_product' ) );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'queue_variation' ) );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'queue_variation' ) );

		add_action( 'woocommerce_new_order', array( __CLASS__, 'queue_order' ) );
		add_action( 'woocommerce_update_order', array( __CLASS__, 'queue_order' ) );
		add_action( 'woocommerce_delete_order', array( __CLASS__, 'queue_order_delete' ) );
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'queue_order_delete' ) );

		add_action( 'wp_trash_post', array( __CLASS__, 'queue_post_delete' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'queue_post_delete' ) );
	}

	/**
	 * Current full-sync state.
	 *
	 * @return array
	 */
	public static function state() {
		return wp_parse_args(
			(array) get_option( self::STATE_OPTION, array() ),
			array(
				'running'  => false,
				'types'    => array(),
				'index'    => 0,
				'offset'   => 0,
				'counts'   => array(),
				'started'  => 0,
				'finished' => 0,
				'error'    => '',
			)
		);
	}

	/**
	 * Starts a full sync of every enabled data type.
	 *
	 * @return true|WP_Error
	 */
	public static function start() {
		$types = WPWTF_Settings::enabled_types();
		if ( ! $types ) {
			return new WP_Error( 'wpwtf_nothing_enabled', __( 'Choose at least one type of data to sync.', 'wpw-to-firebase' ) );
		}

		update_option(
			self::STATE_OPTION,
			array(
				'running'  => true,
				'types'    => $types,
				'index'    => 0,
				'offset'   => 0,
				'counts'   => array_fill_keys( $types, 0 ),
				'started'  => time(),
				'finished' => 0,
				'error'    => '',
			),
			false
		);
		wp_clear_scheduled_hook( self::RUN_HOOK );
		wp_schedule_single_event( time(), self::RUN_HOOK );
		return true;
	}

	/**
	 * Sends one batch, then schedules the next one until every type is done.
	 */
	public static function run_batch() {
		$state = self::state();
		if ( ! $state['running'] ) {
			return;
		}

		$type = isset( $state['types'][ $state['index'] ] ) ? $state['types'][ $state['index'] ] : null;
		if ( null !== $type && in_array( $type, WPWTF_Settings::enabled_types(), true ) ) {
			$batch = WPWTF_Exporter::batch( $type, $state['offset'] );
			if ( $batch['records'] ) {
				$result = WPWTF_Firebase::request( 'PATCH', WPWTF_Firebase::path( $type ), $batch['records'] );
				if ( is_wp_error( $result ) ) {
					$state['running']  = false;
					$state['finished'] = time();
					$state['error']    = $result->get_error_message();
					update_option( self::STATE_OPTION, $state, false );
					return;
				}
				$state['counts'][ $type ] += count( $batch['records'] );
			}

			if ( $batch['read'] < WPWTF_Exporter::BATCH_SIZE ) {
				++$state['index'];
				$state['offset'] = 0;
			} else {
				$state['offset'] += WPWTF_Exporter::BATCH_SIZE;
			}
		} else {
			++$state['index'];
			$state['offset'] = 0;
		}

		if ( $state['index'] >= count( $state['types'] ) ) {
			$state['running']  = false;
			$state['finished'] = time();
			update_option( self::STATE_OPTION, $state, false );
			return;
		}

		update_option( self::STATE_OPTION, $state, false );
		wp_schedule_single_event( time(), self::RUN_HOOK );
	}

	/**
	 * Pushes the latest version of one item, or removes it when it no longer qualifies.
	 *
	 * @param string $type Data type.
	 * @param int    $id   WordPress ID.
	 */
	public static function sync_item( $type, $id ) {
		if ( ! in_array( $type, WPWTF_Settings::enabled_types(), true ) ) {
			return;
		}
		$record = WPWTF_Exporter::record( $type, $id );
		$path   = WPWTF_Firebase::path( $type, WPWTF_Exporter::key( $type, $id ) );
		$result = null === $record
			? WPWTF_Firebase::request( 'DELETE', $path )
			: WPWTF_Firebase::request( 'PUT', $path, $record );
		self::log_result( $result );
	}

	/**
	 * Removes one item from Firebase.
	 *
	 * @param string $type Data type.
	 * @param int    $id   WordPress ID.
	 */
	public static function delete_item( $type, $id ) {
		if ( ! in_array( $type, WPWTF_Settings::enabled_types(), true ) ) {
			return;
		}
		self::log_result( WPWTF_Firebase::request( 'DELETE', WPWTF_Firebase::path( $type, WPWTF_Exporter::key( $type, $id ) ) ) );
	}

	/**
	 * Queues a user update.
	 *
	 * @param int $user_id User ID.
	 */
	public static function queue_user( $user_id ) {
		self::queue( self::ITEM_HOOK, 'users', $user_id );
	}

	/**
	 * Queues a user removal.
	 *
	 * @param int $user_id User ID.
	 */
	public static function queue_user_delete( $user_id ) {
		self::queue( self::DELETE_HOOK, 'users', $user_id );
	}

	/**
	 * Queues a product update.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function queue_product( $product_id ) {
		self::queue( self::ITEM_HOOK, 'products', $product_id );
	}

	/**
	 * Queues an update of a variation's parent product.
	 *
	 * @param int $variation_id Variation ID.
	 */
	public static function queue_variation( $variation_id ) {
		$parent_id = wp_get_post_parent_id( $variation_id );
		if ( $parent_id ) {
			self::queue( self::ITEM_HOOK, 'products', $parent_id );
		}
	}

	/**
	 * Queues an order update.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function queue_order( $order_id ) {
		self::queue( self::ITEM_HOOK, 'orders', $order_id );
	}

	/**
	 * Queues an order removal.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function queue_order_delete( $order_id ) {
		self::queue( self::DELETE_HOOK, 'orders', $order_id );
	}

	/**
	 * Handles trashed or deleted posts: products, variations, and orders stored as posts.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function queue_post_delete( $post_id ) {
		$post_type = get_post_type( $post_id );
		if ( 'product' === $post_type ) {
			self::queue( self::DELETE_HOOK, 'products', $post_id );
		} elseif ( 'product_variation' === $post_type ) {
			self::queue_variation( $post_id );
		} elseif ( 'shop_order' === $post_type ) {
			self::queue( self::DELETE_HOOK, 'orders', $post_id );
		}
	}

	/**
	 * Schedules a single sync event a few seconds out. Repeated saves of the same item collapse into one event.
	 *
	 * @param string $hook Cron hook.
	 * @param string $type Data type.
	 * @param int    $id   WordPress ID.
	 */
	private static function queue( $hook, $type, $id ) {
		$id = absint( $id );
		if ( ! $id || ! WPWTF_Settings::get()['live_updates'] || ! in_array( $type, WPWTF_Settings::enabled_types(), true ) ) {
			return;
		}
		$args = array( $type, $id );
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time() + 5, $hook, $args );
		}
	}

	/**
	 * Keeps the recurring full sync in step with the schedule setting.
	 */
	public static function reschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		$schedule = WPWTF_Settings::get()['schedule'];
		if ( 'none' !== $schedule ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, self::CRON_HOOK );
		}
	}

	/**
	 * Records the most recent error from a single-item sync.
	 *
	 * @param mixed $result Request result.
	 */
	private static function log_result( $result ) {
		if ( is_wp_error( $result ) ) {
			update_option(
				self::ERROR_OPTION,
				array(
					'time'    => time(),
					'message' => $result->get_error_message(),
				),
				false
			);
		}
	}

	/**
	 * Removes every scheduled event when the plugin is deactivated.
	 */
	public static function deactivate() {
		foreach ( array( self::RUN_HOOK, self::CRON_HOOK, self::ITEM_HOOK, self::DELETE_HOOK ) as $hook ) {
			wp_unschedule_hook( $hook );
		}
		$state            = self::state();
		$state['running'] = false;
		update_option( self::STATE_OPTION, $state, false );
	}
}
