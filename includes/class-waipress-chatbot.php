<?php
/**
 * WAIpress Chatbot
 *
 * Customer-facing AI chatbot with RAG and human escalation.
 *
 * @package WAIpress
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_Chatbot {

	// Admin REST endpoints

	public static function rest_list_configs( $request ) {
		global $wpdb;
		return rest_ensure_response(
			$wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wai_chatbot_configs ORDER BY created_at DESC" )
		);
	}

	public static function rest_create_config( $request ) {
		global $wpdb;

		$wpdb->insert( $wpdb->prefix . 'wai_chatbot_configs', array(
			'name'               => sanitize_text_field( $request->get_param( 'name' ) ),
			'welcome_message'    => sanitize_textarea_field( $request->get_param( 'welcome_message' ) ?? '' ),
			'system_prompt'      => sanitize_textarea_field( $request->get_param( 'system_prompt' ) ?? '' ),
			'model'              => sanitize_text_field( $request->get_param( 'model' ) ?? WAIPRESS_DEFAULT_MODEL ),
			'max_tokens'         => absint( $request->get_param( 'max_tokens' ) ?? 1024 ),
			'temperature'        => floatval( $request->get_param( 'temperature' ) ?? 0.7 ),
			'escalation_enabled' => (int) $request->get_param( 'escalation_enabled' ),
			'escalation_message' => sanitize_textarea_field( $request->get_param( 'escalation_message' ) ?? '' ),
			'knowledge_sources'  => wp_json_encode( $request->get_param( 'knowledge_sources' ) ?? array() ),
			'appearance'         => wp_json_encode( $request->get_param( 'appearance' ) ?? array() ),
			'is_active'          => 1,
			'created_by'         => get_current_user_id(),
		) );

		return rest_ensure_response( array( 'id' => $wpdb->insert_id, 'message' => 'Chatbot config created.' ) );
	}

	public static function rest_update_config( $request ) {
		global $wpdb;
		$id = absint( $request->get_param( 'id' ) );
		$data = array( 'updated_at' => current_time( 'mysql' ) );

		$text_fields = array( 'name', 'welcome_message', 'system_prompt', 'model', 'escalation_message' );
		foreach ( $text_fields as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = sanitize_textarea_field( $request->get_param( $field ) );
			}
		}
		if ( $request->has_param( 'max_tokens' ) ) $data['max_tokens'] = absint( $request->get_param( 'max_tokens' ) );
		if ( $request->has_param( 'temperature' ) ) $data['temperature'] = floatval( $request->get_param( 'temperature' ) );
		if ( $request->has_param( 'escalation_enabled' ) ) $data['escalation_enabled'] = (int) $request->get_param( 'escalation_enabled' );
		if ( $request->has_param( 'is_active' ) ) $data['is_active'] = (int) $request->get_param( 'is_active' );
		if ( $request->has_param( 'knowledge_sources' ) ) $data['knowledge_sources'] = wp_json_encode( $request->get_param( 'knowledge_sources' ) );
		if ( $request->has_param( 'appearance' ) ) $data['appearance'] = wp_json_encode( $request->get_param( 'appearance' ) );

		$wpdb->update( $wpdb->prefix . 'wai_chatbot_configs', $data, array( 'id' => $id ) );
		return rest_ensure_response( array( 'message' => 'Config updated.' ) );
	}

	public static function rest_list_sessions( $request ) {
		global $wpdb;

		$status = sanitize_text_field( $request->get_param( 'status' ) ?? 'active' );

		$sessions = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.*, cc.name as config_name,
			        ct.name as contact_name,
			        (SELECT COUNT(*) FROM {$wpdb->prefix}wai_chatbot_messages WHERE session_id = s.id) as message_count
			 FROM {$wpdb->prefix}wai_chatbot_sessions s
			 LEFT JOIN {$wpdb->prefix}wai_chatbot_configs cc ON s.config_id = cc.id
			 LEFT JOIN {$wpdb->prefix}wai_contacts ct ON s.contact_id = ct.id
			 WHERE s.status = %s
			 ORDER BY s.started_at DESC
			 LIMIT 50",
			$status
		) );

		return rest_ensure_response( $sessions );
	}

	public static function rest_takeover( $request ) {
		global $wpdb;
		$id = absint( $request->get_param( 'id' ) );

		// Update session to escalated
		$wpdb->update(
			$wpdb->prefix . 'wai_chatbot_sessions',
			array(
				'status'       => 'escalated',
				'escalated_to' => get_current_user_id(),
				'escalated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id )
		);

		// Create a messaging conversation from this chatbot session
		$session = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wai_chatbot_sessions WHERE id = %d",
			$id
		) );

		if ( $session ) {
			// Get or create webchat channel
			$channel_id = $wpdb->get_var(
				"SELECT id FROM {$wpdb->prefix}wai_channels WHERE platform = 'webchat' LIMIT 1"
			);
			if ( ! $channel_id ) {
				$wpdb->insert( $wpdb->prefix . 'wai_channels', array(
					'platform' => 'webchat',
					'name'     => 'Website Chat',
					'status'   => 'active',
				) );
				$channel_id = $wpdb->insert_id;
			}

			$wpdb->insert( $wpdb->prefix . 'wai_conversations', array(
				'channel_id'               => $channel_id,
				'contact_id'               => $session->contact_id,
				'platform_conversation_id' => 'chatbot_' . $id,
				'status'                   => 'assigned',
				'assigned_to'              => get_current_user_id(),
				'last_message_at'          => current_time( 'mysql' ),
			) );
		}

		return rest_ensure_response( array( 'message' => 'Session taken over. Check messaging inbox.' ) );
	}

	// Public REST endpoints

	public static function rest_start_session( $request ) {
		global $wpdb;

		$visitor_id = sanitize_text_field( $request->get_param( 'visitor_id' ) ?? wp_generate_uuid4() );
		$config_id = absint( $request->get_param( 'config_id' ) ?? 0 );

		// Get active config
		if ( ! $config_id ) {
			$config_id = $wpdb->get_var(
				"SELECT id FROM {$wpdb->prefix}wai_chatbot_configs WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
			);
		}

		if ( ! $config_id ) {
			return new WP_Error( 'no_config', 'No active chatbot configuration.', array( 'status' => 503 ) );
		}

		$config = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wai_chatbot_configs WHERE id = %d",
			$config_id
		) );

		$wpdb->insert( $wpdb->prefix . 'wai_chatbot_sessions', array(
			'config_id'  => $config_id,
			'visitor_id' => $visitor_id,
			'status'     => 'active',
			'started_at' => current_time( 'mysql' ),
		) );

		$session_id = $wpdb->insert_id;

		return rest_ensure_response( array(
			'session_id'      => $session_id,
			'visitor_id'      => $visitor_id,
			'welcome_message' => $config->welcome_message,
			'appearance'      => json_decode( $config->appearance, true ),
		) );
	}

	public static function rest_send_message( $request ) {
		global $wpdb;

		$session_id = absint( $request->get_param( 'sessionId' ) );
		$content = sanitize_textarea_field( $request->get_param( 'content' ) );

		if ( empty( $content ) ) {
			return new WP_Error( 'missing_content', 'Message content required.', array( 'status' => 400 ) );
		}

		// Verify session exists and is active
		$session = $wpdb->get_row( $wpdb->prepare(
			"SELECT s.*, c.system_prompt, c.model, c.max_tokens, c.temperature
			 FROM {$wpdb->prefix}wai_chatbot_sessions s
			 JOIN {$wpdb->prefix}wai_chatbot_configs c ON s.config_id = c.id
			 WHERE s.id = %d AND s.status = 'active'",
			$session_id
		) );

		if ( ! $session ) {
			return new WP_Error( 'invalid_session', 'Session not found or not active.', array( 'status' => 404 ) );
		}

		// Save user message
		$wpdb->insert( $wpdb->prefix . 'wai_chatbot_messages', array(
			'session_id' => $session_id,
			'role'       => 'user',
			'content'    => $content,
		) );

		// Get conversation history
		$history = $wpdb->get_results( $wpdb->prepare(
			"SELECT role, content FROM {$wpdb->prefix}wai_chatbot_messages
			 WHERE session_id = %d ORDER BY created_at ASC",
			$session_id
		) );

		$messages = array();
		foreach ( $history as $msg ) {
			$messages[] = array(
				'role'    => $msg->role === 'user' ? 'user' : 'assistant',
				'content' => $msg->content,
			);
		}

		// Generate AI response
		$result = WAIpress_AI::generate_content(
			$content,
			$session->system_prompt ?: 'You are a helpful customer support assistant.',
			$session->model,
			$session->max_tokens
		);

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( array(
				'role'    => 'assistant',
				'content' => 'I apologize, but I\'m having trouble responding right now. Would you like to speak with a team member?',
			) );
		}

		// Save assistant response
		$wpdb->insert( $wpdb->prefix . 'wai_chatbot_messages', array(
			'session_id'  => $session_id,
			'role'        => 'assistant',
			'content'     => $result['output'],
			'tokens_used' => ( $result['input_tokens'] ?? 0 ) + ( $result['output_tokens'] ?? 0 ),
			'model'       => $result['model'] ?? '',
		) );

		return rest_ensure_response( array(
			'role'    => 'assistant',
			'content' => $result['output'],
		) );
	}

	public static function rest_get_history( $request ) {
		global $wpdb;
		$session_id = absint( $request->get_param( 'sessionId' ) );

		$messages = $wpdb->get_results( $wpdb->prepare(
			"SELECT role, content, created_at FROM {$wpdb->prefix}wai_chatbot_messages
			 WHERE session_id = %d ORDER BY created_at ASC",
			$session_id
		) );

		return rest_ensure_response( $messages );
	}
}
