<?php
/**
 * WAIpress E-Commerce Module
 *
 * Products, cart, checkout, orders, and coupons.
 * Inspired by SureCart (API-first) and WooCommerce (WordPress-integrated).
 *
 * @package WAIpress
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_Commerce {

	/**
	 * Create e-commerce tables.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$prefix = $wpdb->prefix;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$prefix}wai_products (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(500) NOT NULL,
			slug VARCHAR(500) NOT NULL,
			description LONGTEXT DEFAULT NULL,
			short_description TEXT DEFAULT NULL,
			sku VARCHAR(100) DEFAULT NULL,
			price_cents BIGINT(20) NOT NULL DEFAULT 0,
			sale_price_cents BIGINT(20) DEFAULT NULL,
			currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
			stock_quantity INT(11) DEFAULT NULL,
			stock_status VARCHAR(20) NOT NULL DEFAULT 'in_stock',
			type VARCHAR(20) NOT NULL DEFAULT 'simple',
			images LONGTEXT DEFAULT NULL,
			categories LONGTEXT DEFAULT NULL,
			tags LONGTEXT DEFAULT NULL,
			metadata LONGTEXT DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			featured TINYINT(1) NOT NULL DEFAULT 0,
			created_by BIGINT(20) UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug(191)),
			KEY sku (sku),
			KEY status (status),
			KEY type (type),
			KEY stock_status (stock_status)
		) $charset_collate;";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$prefix}wai_product_variants (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT(20) UNSIGNED NOT NULL,
			title VARCHAR(255) NOT NULL DEFAULT '',
			sku VARCHAR(100) DEFAULT NULL,
			price_cents BIGINT(20) NOT NULL DEFAULT 0,
			stock_quantity INT(11) DEFAULT NULL,
			attributes LONGTEXT DEFAULT NULL,
			sort_order INT(11) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY product_id (product_id)
		) $charset_collate;";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$prefix}wai_orders (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_number VARCHAR(50) NOT NULL,
			customer_id BIGINT(20) UNSIGNED DEFAULT NULL,
			customer_email VARCHAR(320) DEFAULT NULL,
			customer_name VARCHAR(255) DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			subtotal_cents BIGINT(20) NOT NULL DEFAULT 0,
			tax_cents BIGINT(20) NOT NULL DEFAULT 0,
			shipping_cents BIGINT(20) NOT NULL DEFAULT 0,
			discount_cents BIGINT(20) NOT NULL DEFAULT 0,
			total_cents BIGINT(20) NOT NULL DEFAULT 0,
			currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
			shipping_address LONGTEXT DEFAULT NULL,
			billing_address LONGTEXT DEFAULT NULL,
			payment_method VARCHAR(50) DEFAULT NULL,
			payment_id VARCHAR(255) DEFAULT NULL,
			coupon_code VARCHAR(100) DEFAULT NULL,
			notes LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY order_number (order_number),
			KEY customer_id (customer_id),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$prefix}wai_order_items (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT(20) UNSIGNED NOT NULL,
			product_id BIGINT(20) UNSIGNED NOT NULL,
			variant_id BIGINT(20) UNSIGNED DEFAULT NULL,
			product_title VARCHAR(500) NOT NULL DEFAULT '',
			variant_title VARCHAR(255) DEFAULT NULL,
			quantity INT(11) NOT NULL DEFAULT 1,
			unit_price_cents BIGINT(20) NOT NULL DEFAULT 0,
			total_cents BIGINT(20) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY order_id (order_id)
		) $charset_collate;";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$prefix}wai_carts (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			visitor_id VARCHAR(255) NOT NULL,
			customer_id BIGINT(20) UNSIGNED DEFAULT NULL,
			items LONGTEXT NOT NULL DEFAULT '[]',
			coupon_code VARCHAR(100) DEFAULT NULL,
			expires_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY visitor_id (visitor_id(191)),
			KEY customer_id (customer_id)
		) $charset_collate;";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$prefix}wai_coupons (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(100) NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'percentage',
			value DECIMAL(10,2) NOT NULL DEFAULT 0,
			min_order_cents BIGINT(20) NOT NULL DEFAULT 0,
			max_uses INT(11) DEFAULT NULL,
			uses_count INT(11) NOT NULL DEFAULT 0,
			expires_at DATETIME DEFAULT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY code (code)
		) $charset_collate;";
		dbDelta( $sql );
	}

	// ============================================================
	// Products
	// ============================================================

	public static function rest_list_products( $request ) {
		global $wpdb;
		$status = sanitize_text_field( $request->get_param( 'status' ) ?? 'active' );
		$type = sanitize_text_field( $request->get_param( 'type' ) ?? '' );
		$search = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
		$page = max( 1, absint( $request->get_param( 'page' ) ?? 1 ) );
		$per_page = min( 50, absint( $request->get_param( 'per_page' ) ?? 20 ) );
		$offset = ( $page - 1 ) * $per_page;

		$where = "WHERE status = %s";
		$params = array( $status );

		if ( $type ) {
			$where .= " AND type = %s";
			$params[] = $type;
		}
		if ( $search ) {
			$where .= " AND (title LIKE %s OR sku LIKE %s)";
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$params[] = $per_page;
		$params[] = $offset;

		$products = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wai_products {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$params
		) );

		foreach ( $products as &$p ) {
			$p->images = json_decode( $p->images, true ) ?: array();
			$p->categories = json_decode( $p->categories, true ) ?: array();
			$p->tags = json_decode( $p->tags, true ) ?: array();
			$p->price = number_format( $p->price_cents / 100, 2 );
			if ( $p->sale_price_cents ) {
				$p->sale_price = number_format( $p->sale_price_cents / 100, 2 );
			}
		}

		return rest_ensure_response( array( 'items' => $products, 'page' => $page ) );
	}

	public static function rest_create_product( $request ) {
		global $wpdb;

		$title = sanitize_text_field( $request->get_param( 'title' ) );
		$slug = sanitize_title( $title );

		// Ensure unique slug
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wai_products WHERE slug = %s",
			$slug
		) );
		if ( $existing ) {
			$slug .= '-' . wp_generate_uuid4();
		}

		$wpdb->insert( $wpdb->prefix . 'wai_products', array(
			'title'             => $title,
			'slug'              => $slug,
			'description'       => wp_kses_post( $request->get_param( 'description' ) ?? '' ),
			'short_description' => sanitize_textarea_field( $request->get_param( 'short_description' ) ?? '' ),
			'sku'               => sanitize_text_field( $request->get_param( 'sku' ) ?? '' ),
			'price_cents'       => absint( $request->get_param( 'price_cents' ) ?? 0 ),
			'sale_price_cents'  => $request->get_param( 'sale_price_cents' ) ? absint( $request->get_param( 'sale_price_cents' ) ) : null,
			'currency'          => sanitize_text_field( $request->get_param( 'currency' ) ?? 'EUR' ),
			'stock_quantity'    => $request->get_param( 'stock_quantity' ) !== null ? absint( $request->get_param( 'stock_quantity' ) ) : null,
			'stock_status'      => sanitize_text_field( $request->get_param( 'stock_status' ) ?? 'in_stock' ),
			'type'              => sanitize_text_field( $request->get_param( 'type' ) ?? 'simple' ),
			'images'            => wp_json_encode( $request->get_param( 'images' ) ?? array() ),
			'categories'        => wp_json_encode( $request->get_param( 'categories' ) ?? array() ),
			'tags'              => wp_json_encode( $request->get_param( 'tags' ) ?? array() ),
			'metadata'          => wp_json_encode( $request->get_param( 'metadata' ) ?? array() ),
			'status'            => sanitize_text_field( $request->get_param( 'status' ) ?? 'draft' ),
			'created_by'        => get_current_user_id(),
		) );

		return rest_ensure_response( array( 'id' => $wpdb->insert_id, 'slug' => $slug, 'message' => 'Product created.' ) );
	}

	public static function rest_get_product( $request ) {
		global $wpdb;
		$id = absint( $request->get_param( 'id' ) );

		$product = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wai_products WHERE id = %d", $id
		) );

		if ( ! $product ) {
			return new WP_Error( 'not_found', 'Product not found.', array( 'status' => 404 ) );
		}

		$product->images = json_decode( $product->images, true ) ?: array();
		$product->variants = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wai_product_variants WHERE product_id = %d ORDER BY sort_order",
			$id
		) );

		foreach ( $product->variants as &$v ) {
			$v->attributes = json_decode( $v->attributes, true ) ?: array();
		}

		return rest_ensure_response( $product );
	}

	public static function rest_update_product( $request ) {
		global $wpdb;
		$id = absint( $request->get_param( 'id' ) );
		$data = array( 'updated_at' => current_time( 'mysql' ) );

		$fields = array( 'title', 'description', 'short_description', 'sku', 'currency', 'stock_status', 'type', 'status' );
		foreach ( $fields as $f ) {
			if ( $request->has_param( $f ) ) {
				$data[ $f ] = $f === 'description' ? wp_kses_post( $request->get_param( $f ) ) : sanitize_text_field( $request->get_param( $f ) );
			}
		}
		$int_fields = array( 'price_cents', 'sale_price_cents', 'stock_quantity' );
		foreach ( $int_fields as $f ) {
			if ( $request->has_param( $f ) ) $data[ $f ] = absint( $request->get_param( $f ) );
		}
		$json_fields = array( 'images', 'categories', 'tags', 'metadata' );
		foreach ( $json_fields as $f ) {
			if ( $request->has_param( $f ) ) $data[ $f ] = wp_json_encode( $request->get_param( $f ) );
		}

		$wpdb->update( $wpdb->prefix . 'wai_products', $data, array( 'id' => $id ) );
		return rest_ensure_response( array( 'message' => 'Product updated.' ) );
	}

	// ============================================================
	// Public Storefront
	// ============================================================

	public static function rest_shop( $request ) {
		global $wpdb;
		$search = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
		$category = sanitize_text_field( $request->get_param( 'category' ) ?? '' );
		$sort = sanitize_text_field( $request->get_param( 'sort' ) ?? 'newest' );
		$min_price = absint( $request->get_param( 'min_price' ) ?? 0 );
		$max_price = absint( $request->get_param( 'max_price' ) ?? 0 );
		$page = max( 1, absint( $request->get_param( 'page' ) ?? 1 ) );
		$per_page = min( 50, absint( $request->get_param( 'per_page' ) ?? 20 ) );
		$offset = ( $page - 1 ) * $per_page;

		$where = "WHERE status = 'active'";
		$params = array();

		if ( $search ) {
			$where .= " AND (title LIKE %s OR short_description LIKE %s)";
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		if ( $min_price > 0 ) {
			$where .= " AND price_cents >= %d";
			$params[] = $min_price;
		}
		if ( $max_price > 0 ) {
			$where .= " AND price_cents <= %d";
			$params[] = $max_price;
		}

		$order = $sort === 'price_asc' ? 'price_cents ASC' :
				 ( $sort === 'price_desc' ? 'price_cents DESC' :
				 ( $sort === 'title' ? 'title ASC' : 'created_at DESC' ) );

		$params[] = $per_page;
		$params[] = $offset;

		$products = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, slug, short_description, price_cents, sale_price_cents, currency, stock_status, images, type
			 FROM {$wpdb->prefix}wai_products {$where} ORDER BY {$order} LIMIT %d OFFSET %d",
			$params
		) );

		foreach ( $products as &$p ) {
			$p->images = json_decode( $p->images, true ) ?: array();
			$p->price = number_format( $p->price_cents / 100, 2 );
		}

		return rest_ensure_response( array( 'items' => $products, 'page' => $page ) );
	}

	// ============================================================
	// Cart
	// ============================================================

	public static function rest_get_cart( $request ) {
		global $wpdb;
		$visitor_id = sanitize_text_field( $request->get_param( 'visitor_id' ) ?? '' );

		if ( ! $visitor_id ) {
			return rest_ensure_response( array( 'items' => array(), 'total_cents' => 0 ) );
		}

		$cart = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wai_carts WHERE visitor_id = %s",
			$visitor_id
		) );

		if ( ! $cart ) {
			return rest_ensure_response( array( 'items' => array(), 'total_cents' => 0 ) );
		}

		$items = json_decode( $cart->items, true ) ?: array();
		$total = 0;

		// Enrich items with current product data
		foreach ( $items as &$item ) {
			$product = $wpdb->get_row( $wpdb->prepare(
				"SELECT title, price_cents, sale_price_cents, images FROM {$wpdb->prefix}wai_products WHERE id = %d",
				$item['product_id']
			) );
			if ( $product ) {
				$price = $product->sale_price_cents ?: $product->price_cents;
				$item['title'] = $product->title;
				$item['unit_price_cents'] = $price;
				$item['total_cents'] = $price * $item['quantity'];
				$item['images'] = json_decode( $product->images, true ) ?: array();
				$total += $item['total_cents'];
			}
		}

		return rest_ensure_response( array(
			'items'       => $items,
			'total_cents' => $total,
			'coupon_code' => $cart->coupon_code,
		) );
	}

	public static function rest_add_to_cart( $request ) {
		global $wpdb;
		$visitor_id = sanitize_text_field( $request->get_param( 'visitor_id' ) );
		$product_id = absint( $request->get_param( 'product_id' ) );
		$variant_id = absint( $request->get_param( 'variant_id' ) ?? 0 );
		$quantity = max( 1, absint( $request->get_param( 'quantity' ) ?? 1 ) );

		if ( ! $visitor_id || ! $product_id ) {
			return new WP_Error( 'missing_params', 'visitor_id and product_id are required.', array( 'status' => 400 ) );
		}

		// Get or create cart
		$cart = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wai_carts WHERE visitor_id = %s",
			$visitor_id
		) );

		$items = $cart ? ( json_decode( $cart->items, true ) ?: array() ) : array();

		// Check if product already in cart
		$found = false;
		foreach ( $items as &$item ) {
			if ( $item['product_id'] == $product_id && ( $item['variant_id'] ?? 0 ) == $variant_id ) {
				$item['quantity'] += $quantity;
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			$items[] = array(
				'product_id' => $product_id,
				'variant_id' => $variant_id ?: null,
				'quantity'   => $quantity,
			);
		}

		if ( $cart ) {
			$wpdb->update(
				$wpdb->prefix . 'wai_carts',
				array( 'items' => wp_json_encode( $items ), 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => $cart->id )
			);
		} else {
			$wpdb->insert( $wpdb->prefix . 'wai_carts', array(
				'visitor_id' => $visitor_id,
				'items'      => wp_json_encode( $items ),
				'expires_at' => date( 'Y-m-d H:i:s', strtotime( '+7 days' ) ),
			) );
		}

		return rest_ensure_response( array( 'message' => 'Item added to cart.', 'items_count' => count( $items ) ) );
	}

	// ============================================================
	// Checkout / Orders
	// ============================================================

	public static function rest_checkout( $request ) {
		global $wpdb;
		$visitor_id = sanitize_text_field( $request->get_param( 'visitor_id' ) );
		$email = sanitize_email( $request->get_param( 'email' ) );
		$name = sanitize_text_field( $request->get_param( 'name' ) ?? '' );
		$shipping = $request->get_param( 'shipping_address' ) ?? array();
		$billing = $request->get_param( 'billing_address' ) ?? array();
		$payment_method = sanitize_text_field( $request->get_param( 'payment_method' ) ?? 'stripe' );

		if ( ! $visitor_id || ! $email ) {
			return new WP_Error( 'missing_params', 'visitor_id and email are required.', array( 'status' => 400 ) );
		}

		// Get cart
		$cart = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wai_carts WHERE visitor_id = %s",
			$visitor_id
		) );

		if ( ! $cart ) {
			return new WP_Error( 'empty_cart', 'Cart is empty.', array( 'status' => 400 ) );
		}

		$items = json_decode( $cart->items, true ) ?: array();
		if ( empty( $items ) ) {
			return new WP_Error( 'empty_cart', 'Cart is empty.', array( 'status' => 400 ) );
		}

		// Calculate totals
		$subtotal = 0;
		$order_items = array();

		foreach ( $items as $item ) {
			$product = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, title, price_cents, sale_price_cents FROM {$wpdb->prefix}wai_products WHERE id = %d",
				$item['product_id']
			) );
			if ( ! $product ) continue;

			$price = $product->sale_price_cents ?: $product->price_cents;
			$line_total = $price * $item['quantity'];
			$subtotal += $line_total;

			$variant_title = null;
			if ( ! empty( $item['variant_id'] ) ) {
				$variant_title = $wpdb->get_var( $wpdb->prepare(
					"SELECT title FROM {$wpdb->prefix}wai_product_variants WHERE id = %d",
					$item['variant_id']
				) );
			}

			$order_items[] = array(
				'product_id'      => $product->id,
				'variant_id'      => $item['variant_id'] ?? null,
				'product_title'   => $product->title,
				'variant_title'   => $variant_title,
				'quantity'        => $item['quantity'],
				'unit_price_cents' => $price,
				'total_cents'     => $line_total,
			);
		}

		// Apply coupon
		$discount = 0;
		if ( $cart->coupon_code ) {
			$coupon = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wai_coupons WHERE code = %s AND is_active = 1",
				$cart->coupon_code
			) );
			if ( $coupon ) {
				if ( $coupon->type === 'percentage' ) {
					$discount = (int) round( $subtotal * ( $coupon->value / 100 ) );
				} else {
					$discount = (int) ( $coupon->value * 100 );
				}
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$wpdb->prefix}wai_coupons SET uses_count = uses_count + 1 WHERE id = %d",
					$coupon->id
				) );
			}
		}

		$total = max( 0, $subtotal - $discount );
		$order_number = 'WAI-' . strtoupper( substr( md5( microtime() ), 0, 8 ) );

		// Create CRM contact if needed
		$contact_id = null;
		$existing_contact = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}wai_contacts WHERE email = %s LIMIT 1",
			$email
		) );
		if ( $existing_contact ) {
			$contact_id = $existing_contact->id;
		} else {
			$wpdb->insert( $wpdb->prefix . 'wai_contacts', array(
				'name'   => $name,
				'email'  => $email,
				'source' => 'web',
			) );
			$contact_id = $wpdb->insert_id;
		}

		// Create order
		$wpdb->insert( $wpdb->prefix . 'wai_orders', array(
			'order_number'     => $order_number,
			'customer_id'      => $contact_id,
			'customer_email'   => $email,
			'customer_name'    => $name,
			'status'           => 'pending',
			'subtotal_cents'   => $subtotal,
			'discount_cents'   => $discount,
			'total_cents'      => $total,
			'currency'         => 'EUR',
			'shipping_address' => wp_json_encode( $shipping ),
			'billing_address'  => wp_json_encode( $billing ),
			'payment_method'   => $payment_method,
			'coupon_code'      => $cart->coupon_code,
		) );
		$order_id = $wpdb->insert_id;

		// Create order items
		foreach ( $order_items as $oi ) {
			$oi['order_id'] = $order_id;
			$wpdb->insert( $wpdb->prefix . 'wai_order_items', $oi );
		}

		// Clear cart
		$wpdb->delete( $wpdb->prefix . 'wai_carts', array( 'id' => $cart->id ) );

		// Log CRM activity
		if ( $contact_id ) {
			$wpdb->insert( $wpdb->prefix . 'wai_activities', array(
				'contact_id' => $contact_id,
				'type'       => 'note',
				'title'      => sprintf( 'Placed order %s (%s %.2f)', $order_number, 'EUR', $total / 100 ),
				'metadata'   => wp_json_encode( array( 'order_id' => $order_id ) ),
			) );
		}

		return rest_ensure_response( array(
			'order_id'     => $order_id,
			'order_number' => $order_number,
			'total_cents'  => $total,
			'status'       => 'pending',
			'message'      => 'Order created successfully.',
		) );
	}

	// ============================================================
	// Orders (Admin)
	// ============================================================

	public static function rest_list_orders( $request ) {
		global $wpdb;
		$status = sanitize_text_field( $request->get_param( 'status' ) ?? '' );
		$page = max( 1, absint( $request->get_param( 'page' ) ?? 1 ) );
		$per_page = min( 50, absint( $request->get_param( 'per_page' ) ?? 20 ) );
		$offset = ( $page - 1 ) * $per_page;

		$where = "WHERE 1=1";
		$params = array();
		if ( $status ) {
			$where .= " AND status = %s";
			$params[] = $status;
		}
		$params[] = $per_page;
		$params[] = $offset;

		$orders = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wai_orders {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$params
		) );

		return rest_ensure_response( array( 'items' => $orders, 'page' => $page ) );
	}

	public static function rest_update_order( $request ) {
		global $wpdb;
		$id = absint( $request->get_param( 'id' ) );
		$data = array( 'updated_at' => current_time( 'mysql' ) );

		if ( $request->has_param( 'status' ) ) $data['status'] = sanitize_text_field( $request->get_param( 'status' ) );
		if ( $request->has_param( 'notes' ) ) $data['notes'] = sanitize_textarea_field( $request->get_param( 'notes' ) );
		if ( $request->has_param( 'payment_id' ) ) $data['payment_id'] = sanitize_text_field( $request->get_param( 'payment_id' ) );

		$wpdb->update( $wpdb->prefix . 'wai_orders', $data, array( 'id' => $id ) );
		return rest_ensure_response( array( 'message' => 'Order updated.' ) );
	}

	// ============================================================
	// Coupons
	// ============================================================

	public static function rest_validate_coupon( $request ) {
		global $wpdb;
		$code = sanitize_text_field( $request->get_param( 'code' ) );

		$coupon = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wai_coupons WHERE code = %s AND is_active = 1",
			$code
		) );

		if ( ! $coupon ) {
			return new WP_Error( 'invalid_coupon', 'Invalid or expired coupon code.', array( 'status' => 400 ) );
		}

		if ( $coupon->max_uses && $coupon->uses_count >= $coupon->max_uses ) {
			return new WP_Error( 'coupon_exhausted', 'This coupon has been fully used.', array( 'status' => 400 ) );
		}

		if ( $coupon->expires_at && strtotime( $coupon->expires_at ) < time() ) {
			return new WP_Error( 'coupon_expired', 'This coupon has expired.', array( 'status' => 400 ) );
		}

		return rest_ensure_response( array(
			'code'  => $coupon->code,
			'type'  => $coupon->type,
			'value' => $coupon->value,
			'valid' => true,
		) );
	}
}
