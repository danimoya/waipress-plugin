<?php
/**
 * WAIpress Messaging Hub
 *
 * Handles unified messaging across WhatsApp, Telegram, Instagram, and WebChat.
 *
 * @package WAIpress
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_Messaging {

	/**
	 * Get unread message count for a user.
	 */
	public static function get_unread_count( $user_id ) {
		global $wpdb;

		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(unread_count), 0)
			 FROM {$wpdb->prefix}wai_conversations
			 WHERE (assigned_to = %d OR assigned_to IS NULL)
			 AND status IN ('open', 'assigned')",
			$user_id
		) );

		return (int) $count;
	}

	// REST Callbacks

	public static function rest_list_channels( $request ) {
		global $wpdb;
		$channels = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wai_channels ORDER BY created_at DESC" );
		// Strip access tokens from response
		foreach ( $channels as &$ch ) {
			$ch->access_token = $ch->access_token ? '***' : null;
		}
		return rest_ensure_response( $channels );
	}

	public static function rest_create_channel( $request ) {
		global $wpdb;

		$wpdb->insert( $wpdb->prefix . 'wai_channels', array(
			'platform'       => sanitize_text_field( $request->get_param( 'platform' ) ),
			'name'           => sanitize_text_field( $request->get_param( 'name' ) ),
			'account_id'     => sanitize_text_field( $request->get_param( 'account_id' ) ?? '' ),
			'access_token'   => sanitize_text_field( $request->get_param( 'access_token' ) ?? '' ),
			'webhook_secret' => sanitize_text_field( $request->get_param( 'webhook_secret' ) ?? '' ),
			'config'         => wp_json_encode( $request->get_param( 'config' ) ?? array() ),
			'status'         => 'active',
			'connected_by'   => get_current_user_id(),
		) );

		return rest_ensure_response( array( 'id' => $wpdb->insert_id, 'message' => 'Channel created.' ) );
	}

	public static function rest_list_conversations( $request ) {
		global $wpdb;

		$status = sanitize_text_field( $request->get_param( 'status' ) ?? '' );
		$page = max( 1, absint( $request->get_param( 'page' ) ?? 1 ) );
		$per_page = min( 50, absint( $request->get_param( 'per_page' ) ?? 20 ) );
		$offset = ( $page - 1 ) * $per_page;

		$where = "WHERE 1=1";
		$params = array();

		if ( $status ) {
			$where .= " AND c.status = %s";
			$params[] = $status;
		}

		$sql = "SELECT c.*, ch.platform, ch.name as channel_name,
		               ct.name as contact_name, ct.phone as contact_phone, ct.email as contact_email,
		               (SELECT content FROM {$wpdb->prefix}wai_messages WHERE conversation_id = c.id ORDER BY created_at DESC LIMIT 1) as last_message
		        FROM {$wpdb->prefix}wai_conversations c
		        LEFT JOIN {$wpdb->prefix}wai_channels ch ON c.channel_id = ch.id
		        LEFT JOIN {$wpdb->prefix}wai_contacts ct ON c.contact_id = ct.id
		        {$where}
		        ORDER BY c.last_message_at DESC
		        LIMIT %d OFFSET %d";

		$params[] = $per_page;
		$params[] = $offset;

		$conversations = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		$total_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}wai_conversations c {$where}";
		$total = $status
			? $wpdb->get_var( $wpdb->prepare( $total_sql, $status ) )
			: $wpdb->get_var( $total_sql );

		return rest_ensure_response( array(
			'items' => $conversations,
			'total' => (int) $total,
			'page'  => $page,
		) );
	}

	public static function rest_get_conversation( $request ) {
		global $wpdb;
		$id = absint( $request->get_param( 'id' ) );

		$conversation = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.*, ch.platform, ch.name as channel_name,
			        ct.name as contact_name, ct.phone as contact_phone, ct.email as contact_email
			 FROM {$wpdb->prefix}wai_conversations c
			 LEFT JOIN {$wpdb->prefix}wai_channels ch ON c.channel_id = ch.id
			 LEFT JOIN {$wpdb->prefix}wai_contacts ct ON c.contact_id = ct.id
			 WHERE c.id = %d",
			$id
		) );

		if ( ! $conversation ) {
			return new WP_Error( 'not_found', 'Conversation not found.', array( 'status' => 404 ) );
		}

		// Get messages
		$messages = $wpdb->get_results( $wpdb->prepare(
			"SELECT m.*, u.display_name as sender_name
			 FROM {$wpdb->prefix}wai_messages m
			 LEFT JOIN {$wpdb->users} u ON m.sender_type = 'agent' AND m.sender_id = u.ID
			 WHERE m.conversation_id = %d
			 ORDER BY m.created_at ASC",
			$id
		) );

		// Mark as read
		$wpdb->update(
			$wpdb->prefix . 'wai_conversations',
			array( 'unread_count' => 0 ),
			array( 'id' => $id )
		);

		$conversation->messages = $messages;
		return rest_ensure_response( $conversation );
	}

	public static function rest_reply( $request ) {
		global $wpdb;

		$id = absint( $request->get_param( 'id' ) );
		$content = sanitize_textarea_field( $request->get_param( 'content' ) );
		$content_type = sanitize_text_field( $request->get_param( 'content_type' ) ?? 'text' );

		if ( empty( $content ) ) {
			return new WP_Error( 'missing_content', 'Reply content is required.', array( 'status' => 400 ) );
		}

		// Get conversation with channel info
		$conversation = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.*, ch.platform, ch.access_token, ch.account_id
			 FROM {$wpdb->prefix}wai_conversations c
			 JOIN {$wpdb->prefix}wai_channels ch ON c.channel_id = ch.id
			 WHERE c.id = %d",
			$id
		) );

		if ( ! $conversation ) {
			return new WP_Error( 'not_found', 'Conversation not found.', array( 'status' => 404 ) );
		}

		// Save message locally
		$wpdb->insert( $wpdb->prefix . 'wai_messages', array(
			'conversation_id' => $id,
			'sender_type'     => 'agent',
			'sender_id'       => get_current_user_id(),
			'content'         => $content,
			'content_type'    => $content_type,
			'status'          => 'sent',
		) );
		$message_id = $wpdb->insert_id;

		// Update conversation
		$wpdb->update(
			$wpdb->prefix . 'wai_conversations',
			array(
				'last_message_at' => current_time( 'mysql' ),
				'status'          => 'assigned',
				'assigned_to'     => get_current_user_id(),
			),
			array( 'id' => $id )
		);

		// TODO: Dispatch to platform via messaging-sdk
		// This will be handled by the channel adapters (WhatsApp/Telegram/Instagram)

		return rest_ensure_response( array(
			'id'      => $message_id,
			'status'  => 'sent',
			'message' => 'Reply sent.',
		) );
	}

	public static function rest_update_conversation( $request ) {
		global $wpdb;

		$id = absint( $request->get_param( 'id' ) );
		$data = array();

		if ( $request->has_param( 'status' ) ) {
			$data['status'] = sanitize_text_field( $request->get_param( 'status' ) );
		}
		if ( $request->has_param( 'assigned_to' ) ) {
			$data['assigned_to'] = absint( $request->get_param( 'assigned_to' ) );
		}

		if ( empty( $data ) ) {
			return new WP_Error( 'nothing_to_update', 'No fields to update.', array( 'status' => 400 ) );
		}

		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( $wpdb->prefix . 'wai_conversations', $data, array( 'id' => $id ) );

		return rest_ensure_response( array( 'message' => 'Conversation updated.' ) );
	}
}
