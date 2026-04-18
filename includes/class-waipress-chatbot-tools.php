<?php
/**
 * WAIpress Chatbot — Commerce Tools
 *
 * Pre-generation "tool" layer for the chatbot: inspects the user message
 * for commerce intent (product search, order status lookup) and, if
 * detected, returns structured card data and a context snippet to splice
 * into the system prompt alongside RAG context.
 *
 * The goal isn't full tool-calling (that lives in newer AI provider APIs);
 * it's a pragmatic, deterministic intent classifier that works with ANY
 * OpenAI-compatible endpoint even without native function calling.
 *
 * @package WAIpress
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_Chatbot_Tools {

	/**
	 * Inspect a user message and decide which tools, if any, to run.
	 *
	 * @param string $message   The user's raw message.
	 * @param array  $sources   Parsed knowledge_sources (array of strings or objects).
	 * @return array {
	 *     @type string $context Extra context to splice into the system prompt ('' if none).
	 *     @type array  $cards   Structured cards to append to the assistant reply, ready to serialize.
	 * }
	 */
	public static function run( $message, $sources ) {
		$context = '';
		$cards   = array();

		$wants_wc = self::sources_include( $sources, array( 'woocommerce_product', 'woocommerce_products' ) );
		$wants_sc = self::sources_include( $sources, array( 'surecart_product', 'surecart_products' ) );

		if ( ! $wants_wc && ! $wants_sc ) {
			return compact( 'context', 'cards' );
		}

		$intent = self::classify_intent( $message );

		if ( 'order_status' === $intent['type'] ) {
			$status = self::lookup_order_status( $intent, $wants_wc, $wants_sc );
			if ( $status ) {
				$context .= self::format_order_context( $status );
				$cards[]  = array( 'type' => 'order_status', 'data' => $status );
			}
		}

		if ( 'product_search' === $intent['type'] || 'order_status' === $intent['type'] ) {
			$products = self::search_products( $intent['query'] ?: $message, $wants_wc, $wants_sc, 3 );
			if ( ! empty( $products ) ) {
				$context .= self::format_products_context( $products );
				foreach ( $products as $p ) {
					$cards[] = array( 'type' => 'product_card', 'data' => $p );
				}
			}
		}

		return compact( 'context', 'cards' );
	}

	// ==================================================================
	//  Intent classification
	// ==================================================================

	/**
	 * Very small regex-driven classifier. Good enough to gate tool calls
	 * without burning a round-trip through the model.
	 *
	 * @return array { type: 'product_search'|'order_status'|'none', query: string, email?: string, order_number?: string }
	 */
	private static function classify_intent( $message ) {
		$lower = strtolower( $message );

		// Order status intent?
		if ( preg_match( '/(order|purchase|bestell|pedido).{0,40}(#?[A-Z0-9-]{3,})/i', $message, $m ) ) {
			$order_number = trim( $m[2], '#' );
			$email = self::extract_email( $message );
			return array(
				'type'         => 'order_status',
				'order_number' => $order_number,
				'email'        => $email,
				'query'        => '',
			);
		}
		if ( preg_match( '/\b(where\s+is\s+my\s+order|track\s+(?:my\s+)?order|order\s+status)\b/i', $lower ) ) {
			return array(
				'type'         => 'order_status',
				'order_number' => '',
				'email'        => self::extract_email( $message ),
				'query'        => '',
			);
		}

		// Product search intent?
		if ( preg_match( '/\b(do\s+you\s+(?:have|sell|stock)|looking\s+for|recommend|show\s+me)\b\s+(.{3,80})/i', $message, $m ) ) {
			return array( 'type' => 'product_search', 'query' => trim( $m[2] ) );
		}
		if ( preg_match( '/\b(how\s+much\s+(?:is|does|for)|price\s+of|cost\s+of)\s+(.{3,80})/i', $message, $m ) ) {
			return array( 'type' => 'product_search', 'query' => trim( $m[2] ) );
		}

		return array( 'type' => 'none', 'query' => '' );
	}

	private static function extract_email( $message ) {
		if ( preg_match( '/[\w.+-]+@[\w-]+\.[\w.-]+/', $message, $m ) ) {
			return $m[0];
		}
		return '';
	}

	// ==================================================================
	//  Product search
	// ==================================================================

	/**
	 * @return array Normalized product records: [{id, title, price, url, excerpt, source}]
	 */
	private static function search_products( $query, $wants_wc, $wants_sc, $limit ) {
		$results = array();

		if ( $wants_wc && class_exists( 'WooCommerce' ) ) {
			$results = array_merge( $results, self::search_wc_products( $query, $limit ) );
		}

		if ( $wants_sc ) {
			$results = array_merge( $results, self::search_surecart_products( $query, $limit ) );
		}

		return array_slice( $results, 0, $limit );
	}

	private static function search_wc_products( $query, $limit ) {
		$posts = get_posts( array(
			'post_type'      => 'product',
			'posts_per_page' => $limit,
			's'              => $query,
			'post_status'    => 'publish',
		) );

		$out = array();
		foreach ( $posts as $post ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : null;
			$out[] = array(
				'id'      => $post->ID,
				'title'   => $post->post_title,
				'price'   => $product ? $product->get_price_html() : '',
				'url'     => get_permalink( $post->ID ),
				'excerpt' => wp_strip_all_tags( mb_substr( $post->post_excerpt ?: $post->post_content, 0, 200 ) ),
				'source'  => 'woocommerce',
			);
		}
		return $out;
	}

	/**
	 * SureCart exposes its products via `/wp-json/surecart/v1/products`.
	 * We call the site's own REST API rather than hard-coupling to SureCart
	 * internals so this keeps working across SureCart upgrades.
	 */
	private static function search_surecart_products( $query, $limit ) {
		$url = add_query_arg(
			array( 'query' => $query, 'limit' => $limit ),
			rest_url( 'surecart/v1/products' )
		);
		$res = wp_remote_get( $url, array( 'timeout' => 5 ) );
		if ( is_wp_error( $res ) ) {
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$data = is_array( $body ) && isset( $body['data'] ) ? $body['data'] : ( is_array( $body ) ? $body : array() );

		$out = array();
		foreach ( array_slice( $data, 0, $limit ) as $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$out[] = array(
				'id'      => $p['id'] ?? '',
				'title'   => $p['name'] ?? '',
				'price'   => $p['metrics']['min_price_amount'] ?? '',
				'url'     => $p['permalink'] ?? '',
				'excerpt' => wp_strip_all_tags( mb_substr( $p['description'] ?? '', 0, 200 ) ),
				'source'  => 'surecart',
			);
		}
		return $out;
	}

	// ==================================================================
	//  Order status
	// ==================================================================

	/**
	 * Look up an order by order-number and/or email across WC + SureCart.
	 *
	 * @return array|null  { source, number, status, total, currency, placed_at }
	 */
	private static function lookup_order_status( $intent, $wants_wc, $wants_sc ) {
		$order_number = $intent['order_number'] ?? '';
		$email        = $intent['email']        ?? '';

		if ( $wants_wc && class_exists( 'WooCommerce' ) ) {
			$result = self::lookup_wc_order( $order_number, $email );
			if ( $result ) {
				return $result;
			}
		}

		if ( $wants_sc ) {
			$result = self::lookup_surecart_order( $order_number, $email );
			if ( $result ) {
				return $result;
			}
		}

		return null;
	}

	private static function lookup_wc_order( $order_number, $email ) {
		if ( ! function_exists( 'wc_get_order' ) || ! $order_number ) {
			return null;
		}
		$order = wc_get_order( (int) $order_number );
		if ( ! $order ) {
			return null;
		}
		if ( $email !== '' && strcasecmp( $order->get_billing_email(), $email ) !== 0 ) {
			// Protect against guessing: if the user gave an email, it must match.
			return null;
		}
		return array(
			'source'    => 'woocommerce',
			'number'    => $order->get_order_number(),
			'status'    => wc_get_order_status_name( $order->get_status() ),
			'total'     => (string) $order->get_total(),
			'currency'  => $order->get_currency(),
			'placed_at' => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i' ) : '',
		);
	}

	private static function lookup_surecart_order( $order_number, $email ) {
		if ( ! $order_number ) {
			return null;
		}
		$url = rest_url( 'surecart/v1/orders/' . rawurlencode( $order_number ) );
		$res = wp_remote_get( $url, array( 'timeout' => 5 ) );
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) ) {
			return null;
		}
		$customer_email = $body['customer']['email'] ?? ( $body['email'] ?? '' );
		if ( $email !== '' && strcasecmp( (string) $customer_email, $email ) !== 0 ) {
			return null;
		}
		return array(
			'source'    => 'surecart',
			'number'    => $body['number'] ?? $order_number,
			'status'    => $body['status'] ?? 'unknown',
			'total'     => (string) ( $body['total_amount'] ?? '' ),
			'currency'  => $body['currency'] ?? '',
			'placed_at' => $body['created_at'] ?? '',
		);
	}

	// ==================================================================
	//  Formatting helpers
	// ==================================================================

	private static function format_products_context( $products ) {
		$lines = array( "\n\n### Available products" );
		foreach ( $products as $p ) {
			$lines[] = sprintf(
				'- %s — %s. %s',
				$p['title'],
				$p['price'] ?: 'price on page',
				$p['excerpt']
			);
		}
		return implode( "\n", $lines );
	}

	private static function format_order_context( $order ) {
		return sprintf(
			"\n\n### Order lookup result\nOrder %s (%s) — status: %s, total: %s %s, placed: %s.",
			$order['number'],
			$order['source'],
			$order['status'],
			$order['total'],
			$order['currency'],
			$order['placed_at']
		);
	}

	// ==================================================================
	//  Utility
	// ==================================================================

	/**
	 * Does the (decoded) knowledge_sources list include any of the given keys?
	 */
	public static function sources_include( $sources, array $keys ): bool {
		if ( ! is_array( $sources ) ) {
			return false;
		}
		foreach ( $sources as $s ) {
			$val = '';
			if ( is_string( $s ) ) {
				$val = $s;
			} elseif ( is_array( $s ) ) {
				$val = $s['type'] ?? ( $s['value'] ?? '' );
			}
			if ( in_array( $val, $keys, true ) ) {
				return true;
			}
		}
		return false;
	}
}
