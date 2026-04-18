<?php
/**
 * WAIpress Migration Assistant
 *
 * Import data from WordPress, WooCommerce, and SureCart installations.
 * Supports WP REST API and WXR (WordPress eXtended RSS) XML imports.
 *
 * @package WAIpress
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_Migration {

	/**
	 * POST /migration/scan - Scan a source WordPress site.
	 */
	public static function rest_scan( $request ) {
		$source_url = trailingslashit( esc_url_raw( $request->get_param( 'source_url' ) ) );
		$username   = sanitize_text_field( $request->get_param( 'username' ) ?? '' );
		$app_password = sanitize_text_field( $request->get_param( 'app_password' ) ?? '' );
		$source_type  = sanitize_text_field( $request->get_param( 'source_type' ) ?? 'wordpress' );

		if ( empty( $source_url ) ) {
			return new WP_Error( 'missing_url', 'Source WordPress URL is required.', array( 'status' => 400 ) );
		}

		$headers = array();
		if ( $username && $app_password ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $username . ':' . $app_password );
		}

		$available = array();

		// Scan posts
		$posts_response = wp_remote_get( $source_url . 'wp-json/wp/v2/posts?per_page=1', array(
			'headers' => $headers,
			'timeout' => 15,
		) );
		if ( ! is_wp_error( $posts_response ) && wp_remote_retrieve_response_code( $posts_response ) === 200 ) {
			$total = wp_remote_retrieve_header( $posts_response, 'X-WP-Total' );
			$available['posts'] = array(
				'count' => (int) $total,
				'label' => sprintf( 'Posts (%d)', $total ),
			);
		}

		// Scan pages
		$pages_response = wp_remote_get( $source_url . 'wp-json/wp/v2/pages?per_page=1', array(
			'headers' => $headers,
			'timeout' => 15,
		) );
		if ( ! is_wp_error( $pages_response ) && wp_remote_retrieve_response_code( $pages_response ) === 200 ) {
			$total = wp_remote_retrieve_header( $pages_response, 'X-WP-Total' );
			$available['pages'] = array(
				'count' => (int) $total,
				'label' => sprintf( 'Pages (%d)', $total ),
			);
		}

		// Scan media
		$media_response = wp_remote_get( $source_url . 'wp-json/wp/v2/media?per_page=1', array(
			'headers' => $headers,
			'timeout' => 15,
		) );
		if ( ! is_wp_error( $media_response ) && wp_remote_retrieve_response_code( $media_response ) === 200 ) {
			$total = wp_remote_retrieve_header( $media_response, 'X-WP-Total' );
			$available['media'] = array(
				'count' => (int) $total,
				'label' => sprintf( 'Media (%d)', $total ),
			);
		}

		// Scan categories & tags
		$cats_response = wp_remote_get( $source_url . 'wp-json/wp/v2/categories?per_page=1', array(
			'headers' => $headers,
			'timeout' => 15,
		) );
		if ( ! is_wp_error( $cats_response ) && wp_remote_retrieve_response_code( $cats_response ) === 200 ) {
			$total = wp_remote_retrieve_header( $cats_response, 'X-WP-Total' );
			$available['categories'] = array( 'count' => (int) $total, 'label' => sprintf( 'Categories (%d)', $total ) );
		}

		// Scan users
		$users_response = wp_remote_get( $source_url . 'wp-json/wp/v2/users?per_page=1', array(
			'headers' => $headers,
			'timeout' => 15,
		) );
		if ( ! is_wp_error( $users_response ) && wp_remote_retrieve_response_code( $users_response ) === 200 ) {
			$total = wp_remote_retrieve_header( $users_response, 'X-WP-Total' );
			$available['users'] = array( 'count' => (int) $total, 'label' => sprintf( 'Users (%d)', $total ) );
		}

		// WooCommerce products
		if ( $source_type === 'woocommerce' ) {
			$products_response = wp_remote_get( $source_url . 'wp-json/wc/v3/products?per_page=1', array(
				'headers' => $headers,
				'timeout' => 15,
			) );
			if ( ! is_wp_error( $products_response ) && wp_remote_retrieve_response_code( $products_response ) === 200 ) {
				$total = wp_remote_retrieve_header( $products_response, 'X-WP-Total' );
				$available['woo_products'] = array( 'count' => (int) $total, 'label' => sprintf( 'WooCommerce Products (%d)', $total ) );
			}

			$orders_response = wp_remote_get( $source_url . 'wp-json/wc/v3/orders?per_page=1', array(
				'headers' => $headers,
				'timeout' => 15,
			) );
			if ( ! is_wp_error( $orders_response ) && wp_remote_retrieve_response_code( $orders_response ) === 200 ) {
				$total = wp_remote_retrieve_header( $orders_response, 'X-WP-Total' );
				$available['woo_orders'] = array( 'count' => (int) $total, 'label' => sprintf( 'WooCommerce Orders (%d)', $total ) );
			}

			$customers_response = wp_remote_get( $source_url . 'wp-json/wc/v3/customers?per_page=1', array(
				'headers' => $headers,
				'timeout' => 15,
			) );
			if ( ! is_wp_error( $customers_response ) && wp_remote_retrieve_response_code( $customers_response ) === 200 ) {
				$total = wp_remote_retrieve_header( $customers_response, 'X-WP-Total' );
				$available['woo_customers'] = array( 'count' => (int) $total, 'label' => sprintf( 'WooCommerce Customers (%d)', $total ) );
			}
		}

		// SureCart
		if ( $source_type === 'surecart' ) {
			$sc_products = wp_remote_get( $source_url . 'wp-json/surecart/v1/products?per_page=1', array(
				'headers' => $headers,
				'timeout' => 15,
			) );
			if ( ! is_wp_error( $sc_products ) && wp_remote_retrieve_response_code( $sc_products ) === 200 ) {
				$body = json_decode( wp_remote_retrieve_body( $sc_products ), true );
				$total = $body['pagination']['count'] ?? count( $body['data'] ?? array() );
				$available['sc_products'] = array( 'count' => (int) $total, 'label' => sprintf( 'SureCart Products (%d)', $total ) );
			}
		}

		if ( empty( $available ) ) {
			return new WP_Error( 'scan_failed', 'Could not connect to the source site. Check the URL and credentials.', array( 'status' => 400 ) );
		}

		return rest_ensure_response( array(
			'source_url'  => $source_url,
			'source_type' => $source_type,
			'available'   => $available,
		) );
	}

	/**
	 * POST /migration/start - Start a migration job.
	 */
	public static function rest_start( $request ) {
		global $wpdb;

		$source_url    = trailingslashit( esc_url_raw( $request->get_param( 'source_url' ) ) );
		$username      = sanitize_text_field( $request->get_param( 'username' ) ?? '' );
		$app_password  = sanitize_text_field( $request->get_param( 'app_password' ) ?? '' );
		$source_type   = sanitize_text_field( $request->get_param( 'source_type' ) ?? 'wordpress' );
		$content_types = $request->get_param( 'content_types' ) ?? array();

		if ( empty( $source_url ) || empty( $content_types ) ) {
			return new WP_Error( 'missing_params', 'source_url and content_types are required.', array( 'status' => 400 ) );
		}

		// Store migration job in options (for worker to pick up)
		$job_id = time();
		$job = array(
			'id'            => $job_id,
			'source_url'    => $source_url,
			'username'      => $username,
			'app_password'  => $app_password,
			'source_type'   => $source_type,
			'content_types' => $content_types,
			'status'        => 'running',
			'progress'      => array(),
			'started_at'    => current_time( 'mysql' ),
			'started_by'    => get_current_user_id(),
		);

		update_option( 'waipress_migration_' . $job_id, $job );

		// Process immediately (for now - in production this would be async via worker)
		$headers = array();
		if ( $username && $app_password ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $username . ':' . $app_password );
		}

		$results = array();

		foreach ( $content_types as $type ) {
			$count = 0;
			$errors = 0;

			switch ( $type ) {
				case 'posts':
					$result = self::import_posts( $source_url, $headers );
					$count = $result['imported'];
					$errors = $result['errors'];
					break;

				case 'pages':
					$result = self::import_pages( $source_url, $headers );
					$count = $result['imported'];
					$errors = $result['errors'];
					break;

				case 'categories':
					$result = self::import_categories( $source_url, $headers );
					$count = $result['imported'];
					$errors = $result['errors'];
					break;

				case 'woo_products':
					$result = self::import_woo_products( $source_url, $headers );
					$count = $result['imported'];
					$errors = $result['errors'];
					break;

				case 'woo_orders':
					$result = self::import_woo_orders( $source_url, $headers );
					$count = $result['imported'];
					$errors = $result['errors'];
					break;

				case 'woo_customers':
					$result = self::import_woo_customers( $source_url, $headers );
					$count = $result['imported'];
					$errors = $result['errors'];
					break;
			}

			$results[ $type ] = array( 'imported' => $count, 'errors' => $errors );
		}

		// Update job status
		$job['status'] = 'completed';
		$job['progress'] = $results;
		$job['completed_at'] = current_time( 'mysql' );
		update_option( 'waipress_migration_' . $job_id, $job );

		return rest_ensure_response( array(
			'job_id'  => $job_id,
			'status'  => 'completed',
			'results' => $results,
		) );
	}

	/**
	 * GET /migration/status/:id
	 */
	public static function rest_status( $request ) {
		$id = absint( $request->get_param( 'id' ) );
		$job = get_option( 'waipress_migration_' . $id );

		if ( ! $job ) {
			return new WP_Error( 'not_found', 'Migration job not found.', array( 'status' => 404 ) );
		}

		return rest_ensure_response( $job );
	}

	// ============================================================
	// Import Methods
	// ============================================================

	private static function import_posts( $source_url, $headers ) {
		$imported = 0;
		$errors = 0;
		$page = 1;

		do {
			$response = wp_remote_get( $source_url . 'wp-json/wp/v2/posts?per_page=50&page=' . $page, array(
				'headers' => $headers,
				'timeout' => 30,
			) );

			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
				break;
			}

			$posts = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( empty( $posts ) ) break;

			foreach ( $posts as $post ) {
				$existing = get_page_by_path( $post['slug'], OBJECT, 'post' );
				if ( $existing ) {
					$errors++;
					continue;
				}

				$result = wp_insert_post( array(
					'post_title'   => wp_strip_all_tags( $post['title']['rendered'] ?? '' ),
					'post_content' => $post['content']['rendered'] ?? '',
					'post_excerpt' => $post['excerpt']['rendered'] ?? '',
					'post_status'  => $post['status'] ?? 'draft',
					'post_type'    => 'post',
					'post_name'    => $post['slug'] ?? '',
					'post_date'    => $post['date'] ?? '',
				), true );

				if ( is_wp_error( $result ) ) {
					$errors++;
				} else {
					$imported++;
				}
			}

			$total_pages = (int) wp_remote_retrieve_header( $response, 'X-WP-TotalPages' );
			$page++;
		} while ( $page <= $total_pages && $page <= 100 );

		return array( 'imported' => $imported, 'errors' => $errors );
	}

	private static function import_pages( $source_url, $headers ) {
		$imported = 0;
		$errors = 0;

		$response = wp_remote_get( $source_url . 'wp-json/wp/v2/pages?per_page=100', array(
			'headers' => $headers,
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) return array( 'imported' => 0, 'errors' => 1 );

		$pages = json_decode( wp_remote_retrieve_body( $response ), true );

		foreach ( $pages as $page ) {
			$existing = get_page_by_path( $page['slug'], OBJECT, 'page' );
			if ( $existing ) { $errors++; continue; }

			$result = wp_insert_post( array(
				'post_title'   => wp_strip_all_tags( $page['title']['rendered'] ?? '' ),
				'post_content' => $page['content']['rendered'] ?? '',
				'post_status'  => $page['status'] ?? 'draft',
				'post_type'    => 'page',
				'post_name'    => $page['slug'] ?? '',
			), true );

			if ( is_wp_error( $result ) ) { $errors++; } else { $imported++; }
		}

		return array( 'imported' => $imported, 'errors' => $errors );
	}

	private static function import_categories( $source_url, $headers ) {
		$imported = 0;
		$errors = 0;

		$response = wp_remote_get( $source_url . 'wp-json/wp/v2/categories?per_page=100', array(
			'headers' => $headers,
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) return array( 'imported' => 0, 'errors' => 1 );

		$categories = json_decode( wp_remote_retrieve_body( $response ), true );

		foreach ( $categories as $cat ) {
			if ( term_exists( $cat['slug'], 'category' ) ) { $errors++; continue; }

			$result = wp_insert_term( $cat['name'], 'category', array(
				'slug'        => $cat['slug'],
				'description' => $cat['description'] ?? '',
			) );

			if ( is_wp_error( $result ) ) { $errors++; } else { $imported++; }
		}

		return array( 'imported' => $imported, 'errors' => $errors );
	}

	private static function import_woo_products( $source_url, $headers ) {
		global $wpdb;
		$imported = 0;
		$errors = 0;

		$response = wp_remote_get( $source_url . 'wp-json/wc/v3/products?per_page=100', array(
			'headers' => $headers,
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) return array( 'imported' => 0, 'errors' => 1 );

		$products = json_decode( wp_remote_retrieve_body( $response ), true );

		foreach ( $products as $product ) {
			$slug = sanitize_title( $product['slug'] ?? $product['name'] );
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}wai_products WHERE slug = %s",
				$slug
			) );
			if ( $existing ) { $errors++; continue; }

			$images = array();
			foreach ( ( $product['images'] ?? array() ) as $img ) {
				$images[] = $img['src'] ?? '';
			}

			$price_cents = (int) round( floatval( $product['regular_price'] ?? $product['price'] ?? 0 ) * 100 );
			$sale_cents = ! empty( $product['sale_price'] ) ? (int) round( floatval( $product['sale_price'] ) * 100 ) : null;

			$wpdb->insert( $wpdb->prefix . 'wai_products', array(
				'title'             => sanitize_text_field( $product['name'] ),
				'slug'              => $slug,
				'description'       => wp_kses_post( $product['description'] ?? '' ),
				'short_description' => wp_kses_post( $product['short_description'] ?? '' ),
				'sku'               => sanitize_text_field( $product['sku'] ?? '' ),
				'price_cents'       => $price_cents,
				'sale_price_cents'  => $sale_cents,
				'stock_quantity'    => $product['stock_quantity'] ?? null,
				'stock_status'      => $product['stock_status'] ?? 'in_stock',
				'type'              => $product['type'] === 'variable' ? 'variable' : 'simple',
				'images'            => wp_json_encode( $images ),
				'categories'        => wp_json_encode( wp_list_pluck( $product['categories'] ?? array(), 'name' ) ),
				'tags'              => wp_json_encode( wp_list_pluck( $product['tags'] ?? array(), 'name' ) ),
				'status'            => $product['status'] === 'publish' ? 'active' : 'draft',
			) );

			$product_id = $wpdb->insert_id;

			// Import variants
			foreach ( ( $product['variations'] ?? array() ) as $var_id ) {
				$var_response = wp_remote_get( $source_url . 'wp-json/wc/v3/products/' . $product['id'] . '/variations/' . $var_id, array(
					'headers' => $headers,
					'timeout' => 15,
				) );
				if ( is_wp_error( $var_response ) ) continue;

				$variant = json_decode( wp_remote_retrieve_body( $var_response ), true );
				if ( ! $variant ) continue;

				$attrs = array();
				foreach ( ( $variant['attributes'] ?? array() ) as $attr ) {
					$attrs[ $attr['name'] ] = $attr['option'];
				}

				$wpdb->insert( $wpdb->prefix . 'wai_product_variants', array(
					'product_id'     => $product_id,
					'title'          => implode( ' / ', array_values( $attrs ) ),
					'sku'            => $variant['sku'] ?? '',
					'price_cents'    => (int) round( floatval( $variant['price'] ?? 0 ) * 100 ),
					'stock_quantity' => $variant['stock_quantity'] ?? null,
					'attributes'     => wp_json_encode( $attrs ),
				) );
			}

			$imported++;
		}

		return array( 'imported' => $imported, 'errors' => $errors );
	}

	private static function import_woo_orders( $source_url, $headers ) {
		global $wpdb;
		$imported = 0;
		$errors = 0;

		$response = wp_remote_get( $source_url . 'wp-json/wc/v3/orders?per_page=100', array(
			'headers' => $headers,
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) return array( 'imported' => 0, 'errors' => 1 );

		$orders = json_decode( wp_remote_retrieve_body( $response ), true );

		foreach ( $orders as $order ) {
			$order_number = 'WOO-' . $order['id'];
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}wai_orders WHERE order_number = %s",
				$order_number
			) );
			if ( $existing ) { $errors++; continue; }

			// Find or create contact
			$email = $order['billing']['email'] ?? '';
			$contact_id = null;
			if ( $email ) {
				$contact_id = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}wai_contacts WHERE email = %s",
					$email
				) );
				if ( ! $contact_id ) {
					$wpdb->insert( $wpdb->prefix . 'wai_contacts', array(
						'name'   => trim( ( $order['billing']['first_name'] ?? '' ) . ' ' . ( $order['billing']['last_name'] ?? '' ) ),
						'email'  => $email,
						'phone'  => $order['billing']['phone'] ?? '',
						'source' => 'woocommerce',
					) );
					$contact_id = $wpdb->insert_id;
				}
			}

			$status_map = array(
				'pending'    => 'pending',
				'processing' => 'processing',
				'on-hold'    => 'pending',
				'completed'  => 'delivered',
				'cancelled'  => 'cancelled',
				'refunded'   => 'refunded',
				'failed'     => 'cancelled',
			);

			$wpdb->insert( $wpdb->prefix . 'wai_orders', array(
				'order_number'     => $order_number,
				'customer_id'      => $contact_id,
				'customer_email'   => $email,
				'customer_name'    => trim( ( $order['billing']['first_name'] ?? '' ) . ' ' . ( $order['billing']['last_name'] ?? '' ) ),
				'status'           => $status_map[ $order['status'] ] ?? 'pending',
				'subtotal_cents'   => (int) round( floatval( $order['total'] ?? 0 ) * 100 ),
				'total_cents'      => (int) round( floatval( $order['total'] ?? 0 ) * 100 ),
				'currency'         => $order['currency'] ?? 'EUR',
				'shipping_address' => wp_json_encode( $order['shipping'] ?? array() ),
				'billing_address'  => wp_json_encode( $order['billing'] ?? array() ),
				'payment_method'   => $order['payment_method_title'] ?? '',
				'created_at'       => $order['date_created'] ?? current_time( 'mysql' ),
			) );
			$wai_order_id = $wpdb->insert_id;

			// Import line items
			foreach ( ( $order['line_items'] ?? array() ) as $item ) {
				$wpdb->insert( $wpdb->prefix . 'wai_order_items', array(
					'order_id'        => $wai_order_id,
					'product_id'      => 0, // Can't reliably map WC product IDs
					'product_title'   => $item['name'] ?? '',
					'quantity'        => $item['quantity'] ?? 1,
					'unit_price_cents' => (int) round( floatval( $item['price'] ?? 0 ) * 100 ),
					'total_cents'     => (int) round( floatval( $item['total'] ?? 0 ) * 100 ),
				) );
			}

			$imported++;
		}

		return array( 'imported' => $imported, 'errors' => $errors );
	}

	private static function import_woo_customers( $source_url, $headers ) {
		global $wpdb;
		$imported = 0;
		$errors = 0;

		$response = wp_remote_get( $source_url . 'wp-json/wc/v3/customers?per_page=100', array(
			'headers' => $headers,
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) return array( 'imported' => 0, 'errors' => 1 );

		$customers = json_decode( wp_remote_retrieve_body( $response ), true );

		foreach ( $customers as $customer ) {
			$email = $customer['email'] ?? '';
			if ( ! $email ) { $errors++; continue; }

			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}wai_contacts WHERE email = %s",
				$email
			) );
			if ( $existing ) { $errors++; continue; }

			$wpdb->insert( $wpdb->prefix . 'wai_contacts', array(
				'name'    => trim( ( $customer['first_name'] ?? '' ) . ' ' . ( $customer['last_name'] ?? '' ) ),
				'email'   => $email,
				'phone'   => $customer['billing']['phone'] ?? '',
				'company' => $customer['billing']['company'] ?? '',
				'source'  => 'woocommerce',
			) );

			$imported++;
		}

		return array( 'imported' => $imported, 'errors' => $errors );
	}
}
