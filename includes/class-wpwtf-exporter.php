<?php
/**
 * Converts users, products, and orders into Firebase records.
 *
 * @package WPW_To_Firebase
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the records sent to Firebase. Only the fields listed here are ever sent;
 * passwords, password reset keys, and other credentials are never included.
 */
final class WPWTF_Exporter {

	const BATCH_SIZE = 50;

	/**
	 * Firebase key for a record. A prefix keeps keys non-numeric, so Firebase never turns a
	 * collection of sequential IDs into an array.
	 *
	 * @param string $type Data type: users, products, or orders.
	 * @param int    $id   WordPress ID.
	 * @return string
	 */
	public static function key( $type, $id ) {
		$prefixes = array(
			'users'    => 'user_',
			'products' => 'product_',
			'orders'   => 'order_',
		);
		return $prefixes[ $type ] . absint( $id );
	}

	/**
	 * One batch of records, keyed for a Firebase multi-path update.
	 *
	 * @param string $type   Data type.
	 * @param int    $offset Number of items already processed.
	 * @return array Records keyed by Firebase key, plus the number of source items read.
	 */
	public static function batch( $type, $offset ) {
		$items = array();
		if ( 'users' === $type ) {
			$items = get_users(
				array(
					'number'      => self::BATCH_SIZE,
					'offset'      => $offset,
					'orderby'     => 'ID',
					'order'       => 'ASC',
					'count_total' => false,
				)
			);
		} elseif ( 'products' === $type ) {
			$items = wc_get_products(
				array(
					'limit'   => self::BATCH_SIZE,
					'offset'  => $offset,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'status'  => array( 'publish', 'private' ),
				)
			);
		} elseif ( 'orders' === $type ) {
			$items = wc_get_orders(
				array(
					'limit'   => self::BATCH_SIZE,
					'offset'  => $offset,
					'orderby' => 'ID',
					'order'   => 'ASC',
					'type'    => 'shop_order',
				)
			);
		}

		$records = array();
		foreach ( $items as $item ) {
			$record = self::build( $type, $item );
			if ( null !== $record ) {
				$records[ self::key( $type, $record['id'] ) ] = $record;
			}
		}
		return array(
			'records' => $records,
			'read'    => count( $items ),
		);
	}

	/**
	 * The record for one item, or null when it no longer exists or shouldn't be synced.
	 *
	 * @param string $type Data type.
	 * @param int    $id   WordPress ID.
	 * @return array|null
	 */
	public static function record( $type, $id ) {
		if ( 'users' === $type ) {
			$user = get_userdata( $id );
			return $user ? self::build( $type, $user ) : null;
		}
		if ( 'products' === $type ) {
			$product = wc_get_product( $id );
			return $product && in_array( $product->get_status(), array( 'publish', 'private' ), true ) ? self::build( $type, $product ) : null;
		}
		if ( 'orders' === $type ) {
			$order = wc_get_order( $id );
			return $order && 'shop_order' === $order->get_type() ? self::build( $type, $order ) : null;
		}
		return null;
	}

	/**
	 * Dispatches to the record builder for a type.
	 *
	 * @param string $type Data type.
	 * @param mixed  $item WP_User, WC_Product, or WC_Order.
	 * @return array|null
	 */
	private static function build( $type, $item ) {
		if ( 'users' === $type && $item instanceof WP_User ) {
			return self::user( $item );
		}
		if ( 'products' === $type && $item instanceof WC_Product && ! $item->is_type( 'variation' ) ) {
			return self::product( $item );
		}
		if ( 'orders' === $type && $item instanceof WC_Order ) {
			return self::order( $item );
		}
		return null;
	}

	/**
	 * User record: profile details only.
	 *
	 * @param WP_User $user User.
	 * @return array
	 */
	private static function user( WP_User $user ) {
		return array(
			'id'           => $user->ID,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'roles'        => array_values( $user->roles ),
			'registered'   => self::iso_date( $user->user_registered ),
		);
	}

	/**
	 * Product record, including variations for variable products.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	private static function product( WC_Product $product ) {
		$id             = $product->get_id();
		$product_record = array(
			'id'                => $id,
			'name'              => $product->get_name(),
			'slug'              => $product->get_slug(),
			'type'              => $product->get_type(),
			'status'            => $product->get_status(),
			'sku'               => $product->get_sku(),
			'permalink'         => get_permalink( $id ),
			'short_description' => $product->get_short_description(),
			'price'             => $product->get_price(),
			'regular_price'     => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'on_sale'           => $product->is_on_sale(),
			'stock_status'      => $product->get_stock_status(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'categories'        => self::term_names( $id, 'product_cat' ),
			'tags'              => self::term_names( $id, 'product_tag' ),
			'image'             => self::image_url( $product->get_image_id() ),
			'gallery'           => array_values( array_filter( array_map( array( __CLASS__, 'image_url' ), $product->get_gallery_image_ids() ) ) ),
			'attributes'        => self::attributes( $product ),
			'date_modified'     => $product->get_date_modified() ? $product->get_date_modified()->date( 'c' ) : null,
		);

		if ( $product->is_type( 'variable' ) ) {
			$product_record['variations'] = array();
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation ) {
					continue;
				}
				$options = array();
				foreach ( $variation->get_attributes() as $name => $value ) {
					$options[] = array(
						'name'  => wc_attribute_label( $name, $product ),
						'value' => $value,
					);
				}
				$product_record['variations'][] = array(
					'id'             => $variation_id,
					'sku'            => $variation->get_sku(),
					'price'          => $variation->get_price(),
					'regular_price'  => $variation->get_regular_price(),
					'sale_price'     => $variation->get_sale_price(),
					'stock_status'   => $variation->get_stock_status(),
					'stock_quantity' => $variation->get_stock_quantity(),
					'image'          => self::image_url( $variation->get_image_id() ),
					'options'        => $options,
				);
			}
		}

		return $product_record;
	}

	/**
	 * Order record: totals, addresses, and line items.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private static function order( WC_Order $order ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$items[] = array(
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'name'         => $item->get_name(),
				'quantity'     => $item->get_quantity(),
				'subtotal'     => $item->get_subtotal(),
				'total'        => $item->get_total(),
			);
		}

		$shipping_lines = array();
		foreach ( $order->get_shipping_methods() as $shipping ) {
			$shipping_lines[] = array(
				'method_id' => $shipping->get_method_id(),
				'name'      => $shipping->get_name(),
				'total'     => $shipping->get_total(),
			);
		}

		return array(
			'id'             => $order->get_id(),
			'number'         => $order->get_order_number(),
			'status'         => $order->get_status(),
			'currency'       => $order->get_currency(),
			'total'          => $order->get_total(),
			'subtotal'       => $order->get_subtotal(),
			'total_tax'      => $order->get_total_tax(),
			'shipping_total' => $order->get_shipping_total(),
			'discount_total' => $order->get_discount_total(),
			'payment_method' => $order->get_payment_method_title(),
			'customer_id'    => $order->get_customer_id(),
			'customer_note'  => $order->get_customer_note(),
			'date_created'   => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
			'date_modified'  => $order->get_date_modified() ? $order->get_date_modified()->date( 'c' ) : null,
			'date_paid'      => $order->get_date_paid() ? $order->get_date_paid()->date( 'c' ) : null,
			'billing'        => array(
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'company'    => $order->get_billing_company(),
				'email'      => $order->get_billing_email(),
				'phone'      => $order->get_billing_phone(),
				'address_1'  => $order->get_billing_address_1(),
				'address_2'  => $order->get_billing_address_2(),
				'city'       => $order->get_billing_city(),
				'state'      => $order->get_billing_state(),
				'postcode'   => $order->get_billing_postcode(),
				'country'    => $order->get_billing_country(),
			),
			'shipping'       => array(
				'first_name' => $order->get_shipping_first_name(),
				'last_name'  => $order->get_shipping_last_name(),
				'company'    => $order->get_shipping_company(),
				'address_1'  => $order->get_shipping_address_1(),
				'address_2'  => $order->get_shipping_address_2(),
				'city'       => $order->get_shipping_city(),
				'state'      => $order->get_shipping_state(),
				'postcode'   => $order->get_shipping_postcode(),
				'country'    => $order->get_shipping_country(),
			),
			'items'          => $items,
			'shipping_lines' => $shipping_lines,
		);
	}

	/**
	 * Product attributes as name and option lists.
	 *
	 * @param WC_Product $product Product.
	 * @return array
	 */
	private static function attributes( WC_Product $product ) {
		$attributes = array();
		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}
			$attributes[] = array(
				'name'      => wc_attribute_label( $attribute->get_name(), $product ),
				'options'   => $attribute->is_taxonomy()
					? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) )
					: $attribute->get_options(),
				'visible'   => $attribute->get_visible(),
				'variation' => $attribute->get_variation(),
			);
		}
		return $attributes;
	}

	/**
	 * Term names for a taxonomy.
	 *
	 * @param int    $id       Post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return string[]
	 */
	private static function term_names( $id, $taxonomy ) {
		$names = wp_get_post_terms( $id, $taxonomy, array( 'fields' => 'names' ) );
		return is_wp_error( $names ) ? array() : array_values( $names );
	}

	/**
	 * Full URL of an attachment, or an empty string.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private static function image_url( $attachment_id ) {
		$url = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';
		return $url ? $url : '';
	}

	/**
	 * Converts a UTC MySQL date to ISO 8601.
	 *
	 * @param string $mysql_date Date in Y-m-d H:i:s format, in UTC.
	 * @return string|null
	 */
	private static function iso_date( $mysql_date ) {
		$timestamp = $mysql_date ? strtotime( $mysql_date . ' UTC' ) : false;
		return $timestamp ? gmdate( 'c', $timestamp ) : null;
	}
}
