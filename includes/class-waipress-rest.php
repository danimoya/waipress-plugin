<?php
/**
 * WAIpress REST API Endpoints
 *
 * @package WAIpress
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_REST {

	const NAMESPACE = 'waipress/v1';

	/**
	 * Register all REST routes.
	 */
	public static function register_routes() {
		// AI Content
		register_rest_route( self::NAMESPACE, '/ai/generate', array(
			'methods'             => 'POST',
			'callback'            => array( 'WAIpress_AI', 'rest_generate' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );
		register_rest_route( self::NAMESPACE, '/ai/rewrite', array(
			'methods'             => 'POST',
			'callback'            => array( 'WAIpress_AI', 'rest_rewrite' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );
		register_rest_route( self::NAMESPACE, '/ai/seo', array(
			'methods'             => 'POST',
			'callback'            => array( 'WAIpress_AI', 'rest_seo' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );
		register_rest_route( self::NAMESPACE, '/ai/suggest-tags', array(
			'methods'             => 'POST',
			'callback'            => array( 'WAIpress_AI', 'rest_suggest_tags' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );
		register_rest_route( self::NAMESPACE, '/ai/rewrite-meta', array(
			'methods'             => 'POST',
			'callback'            => array( 'WAIpress_Yoast', 'rest_rewrite_meta' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );
		register_rest_route( self::NAMESPACE, '/ai/products/generate', array(
			'methods'             => 'POST',
			'callback'            => array( 'WAIpress_WooCommerce', 'rest_generate' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );

		// AI Prompts CRUD
		register_rest_route( self::NAMESPACE, '/ai/prompts', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( 'WAIpress_AI', 'rest_list_prompts' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( 'WAIpress_AI', 'rest_save_prompt' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			),
		) );

		// AI Generations log
		register_rest_route( self::NAMESPACE, '/ai/generations', array(
			'methods'             => 'GET',
			'callback'            => array( 'WAIpress_AI', 'rest_list_generations' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		// AI Images
		register_rest_route( self::NAMESPACE, '/ai/images/generate', array(
			'methods'             => 'POST',
			'callback'            => array( 'WAIpress_Images', 'rest_generate' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );
		register_rest_route( self::NAMESPACE, '/ai/images/status/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( 'WAIpress_Images', 'rest_status' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );

		// Messaging
		register_rest_route( self::NAMESPACE, '/messaging/channels', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( 'WAIpress_Messaging', 'rest_list_channels' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( 'WAIpress_Messaging', 'rest_create_channel' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
		) );
		register_rest_route( self::NAMESPACE, '/messaging/conversations', array(
			'methods'             => 'GET',
			'callback'            => array( 'WAIpress_Messaging', 'rest_list_conversations' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );
		register_rest_route( self::NAMESPACE, '/messaging/conversations/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( 'WAIpress_Messaging', 'rest_get_conversation' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );
		register_rest_route( self::NAMESPACE, '/messaging/conversations/(?P<id>\d+)/reply', array(
			'methods'             => 'POST',
			'callback'            => array( 'WAIpress_Messaging', 'rest_reply' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );
		register_rest_route( self::NAMESPACE, '/messaging/conversations/(?P<id>\d+)', array(
			'methods'             => 'PATCH',
			'callback'            => array( 'WAIpress_Messaging', 'rest_update_conversation' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );

		// CRM
		register_rest_route( self::NAMESPACE, '/crm/contacts', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( 'WAIpress_CRM', 'rest_list_contacts' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( 'WAIpress_CRM', 'rest_create_contact' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			),
		) );
		register_rest_route( self::NAMESPACE, '/crm/contacts/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( 'WAIpress_CRM', 'rest_get_contact' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			),
			array(
				'methods'             => 'PATCH',
				'callback'            => array( 'WAIpress_CRM', 'rest_update_contact' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			),
		) );
		register_rest_route( self::NAMESPACE, '/crm/contacts/(?P<id>\d+)/timeline', array(
			'methods'             => 'GET',
			'callback'            => array( 'WAIpress_CRM', 'rest_get_timeline' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );
		register_rest_route( self::NAMESPACE, '/crm/deals', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( 'WAIpress_CRM', 'rest_list_deals' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( 'WAIpress_CRM', 'rest_create_deal' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			),
		) );
		register_rest_route( self::NAMESPACE, '/crm/deals/(?P<id>\d+)', array(
			'methods'             => 'PATCH',
			'callback'            => array( 'WAIpress_CRM', 'rest_update_deal' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );
		register_rest_route( self::NAMESPACE, '/crm/deal-stages', array(
			'methods'             => 'GET',
			'callback'            => array( 'WAIpress_CRM', 'rest_list_stages' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );
		register_rest_route( self::NAMESPACE, '/crm/activities', array(
			'methods'             => 'POST',
			'callback'            => array( 'WAIpress_CRM', 'rest_create_activity' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );

		// Chatbot (Admin)
		register_rest_route( self::NAMESPACE, '/chatbot/configs', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( 'WAIpress_Chatbot', 'rest_list_configs' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( 'WAIpress_Chatbot', 'rest_create_config' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			),
		) );
		register_rest_route( self::NAMESPACE, '/chatbot/configs/(?P<id>\d+)', array(
			'methods'             => 'PATCH',
			'callback'            => array( 'WAIpress_Chatbot', 'rest_update_config' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );
		register_rest_route( self::NAMESPACE, '/chatbot/sessions', array(
			'methods'             => 'GET',
			'callback'            => array( 'WAIpress_Chatbot', 'rest_list_sessions' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );
		register_rest_route( self::NAMESPACE, '/chatbot/sessions/(?P<id>\d+)/takeover', array(
			'methods'             => 'POST',
			'callback'            => array( 'WAIpress_Chatbot', 'rest_takeover' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );

		// Chatbot (Public - rate limited, no auth)
		register_rest_route( self::NAMESPACE, '/chatbot/start', array(
			'methods'             => 'POST',
			'callback'            => array( 'WAIpress_Chatbot', 'rest_start_session' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NAMESPACE, '/chatbot/(?P<sessionId>[a-zA-Z0-9]+)/message', array(
			'methods'             => 'POST',
			'callback'            => array( 'WAIpress_Chatbot', 'rest_send_message' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NAMESPACE, '/chatbot/(?P<sessionId>[a-zA-Z0-9]+)/history', array(
			'methods'             => 'GET',
			'callback'            => array( 'WAIpress_Chatbot', 'rest_get_history' ),
			'permission_callback' => '__return_true',
		) );

		// Semantic Search
		register_rest_route( self::NAMESPACE, '/search/semantic', array(
			'methods'             => 'POST',
			'callback'            => array( 'WAIpress_Embeddings', 'rest_semantic_search' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );

		// ============================================================
		// E-Commerce
		// ============================================================

		// Products (Admin)
		register_rest_route( self::NAMESPACE, '/products', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( 'WAIpress_Commerce', 'rest_list_products' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( 'WAIpress_Commerce', 'rest_create_product' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			),
		) );
		register_rest_route( self::NAMESPACE, '/products/(?P<id>\d+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( 'WAIpress_Commerce', 'rest_get_product' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			),
			array(
				'methods'             => 'PATCH',
				'callback'            => array( 'WAIpress_Commerce', 'rest_update_product' ),
				'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
			),
		) );

		// Public Storefront
		register_rest_route( self::NAMESPACE, '/shop', array(
			'methods'             => 'GET',
			'callback'            => array( 'WAIpress_Commerce', 'rest_shop' ),
			'permission_callback' => '__return_true',
		) );

		// Cart (Public)
		register_rest_route( self::NAMESPACE, '/cart', array(
			'methods'             => 'GET',
			'callback'            => array( 'WAIpress_Commerce', 'rest_get_cart' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NAMESPACE, '/cart/add', array(
			'methods'             => 'POST',
			'callback'            => array( 'WAIpress_Commerce', 'rest_add_to_cart' ),
			'permission_callback' => '__return_true',
		) );

		// Checkout (Public)
		register_rest_route( self::NAMESPACE, '/checkout', array(
			'methods'             => 'POST',
			'callback'            => array( 'WAIpress_Commerce', 'rest_checkout' ),
			'permission_callback' => '__return_true',
		) );

		// Orders (Admin)
		register_rest_route( self::NAMESPACE, '/orders', array(
			'methods'             => 'GET',
			'callback'            => array( 'WAIpress_Commerce', 'rest_list_orders' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );
		register_rest_route( self::NAMESPACE, '/orders/(?P<id>\d+)', array(
			'methods'             => 'PATCH',
			'callback'            => array( 'WAIpress_Commerce', 'rest_update_order' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );

		// Coupons
		register_rest_route( self::NAMESPACE, '/coupons/validate', array(
			'methods'             => 'POST',
			'callback'            => array( 'WAIpress_Commerce', 'rest_validate_coupon' ),
			'permission_callback' => '__return_true',
		) );

		// ============================================================
		// Migration
		// ============================================================

		register_rest_route( self::NAMESPACE, '/migration/scan', array(
			'methods'             => 'POST',
			'callback'            => array( 'WAIpress_Migration', 'rest_scan' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );
		register_rest_route( self::NAMESPACE, '/migration/start', array(
			'methods'             => 'POST',
			'callback'            => array( 'WAIpress_Migration', 'rest_start' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );
		register_rest_route( self::NAMESPACE, '/migration/status/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( 'WAIpress_Migration', 'rest_status' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
		) );

		// ============================================================
		// Webhooks (public, no auth -- platforms verify via tokens)
		// ============================================================

		register_rest_route( self::NAMESPACE, '/webhooks/whatsapp', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( 'WAIpress_Webhooks', 'verify_whatsapp' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( 'WAIpress_Webhooks', 'receive_whatsapp' ),
				'permission_callback' => '__return_true',
			),
		) );
		register_rest_route( self::NAMESPACE, '/webhooks/telegram', array(
			'methods'             => 'POST',
			'callback'            => array( 'WAIpress_Webhooks', 'receive_telegram' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NAMESPACE, '/webhooks/instagram', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( 'WAIpress_Webhooks', 'verify_instagram' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( 'WAIpress_Webhooks', 'receive_instagram' ),
				'permission_callback' => '__return_true',
			),
		) );

		// ============================================================
		// Messaging Polling (SSE class)
		// ============================================================

		register_rest_route( self::NAMESPACE, '/messaging/updates', array(
			'methods'             => 'GET',
			'callback'            => array( 'WAIpress_SSE', 'rest_messaging_updates' ),
			'permission_callback' => array( __CLASS__, 'can_edit_posts' ),
		) );
	}

	/**
	 * Permission: can edit posts.
	 */
	public static function can_edit_posts() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Permission: can manage options (admin).
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}
}
