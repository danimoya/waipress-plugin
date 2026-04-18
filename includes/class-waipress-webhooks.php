<?php
/**
 * WAIpress Webhook Receivers
 *
 * Replaces the Node.js Webhooks service. Handles incoming webhook
 * payloads from WhatsApp, Telegram, and Instagram messaging platforms.
 *
 * @package WAIpress
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_Webhooks {

	/**
	 * Initialize.
	 * Routes are registered in class-waipress-rest.php; nothing needed here.
	 */
	public static function init() {
		// All routes registered via WAIpress_REST::register_routes().
	}

	// ================================================================
	// WhatsApp Cloud API
	// ================================================================

	/**
	 * GET handler for WhatsApp webhook verification.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function verify_whatsapp( $request ) {
		$hub_mode         = $request->get_param( 'hub_mode' ) ?? $request->get_param( 'hub.mode' ) ?? '';
		$hub_verify_token = $request->get_param( 'hub_verify_token' ) ?? $request->get_param( 'hub.verify_token' ) ?? '';
		$hub_challenge    = $request->get_param( 'hub_challenge' ) ?? $request->get_param( 'hub.challenge' ) ?? '';

		$expected_token = get_option( 'waipress_whatsapp_verify_token', '' );

		if ( $hub_mode === 'subscribe' && $hub_verify_token === $expected_token && $expected_token !== '' ) {
			$response = new WP_REST_Response( $hub_challenge, 200 );
			$response->header( 'Content-Type', 'text/plain' );
			return $response;
		}

		return new WP_Error( 'forbidden', 'Verification failed.', array( 'status' => 403 ) );
	}

	/**
	 * POST handler for incoming WhatsApp messages.
	 *
	 * Parses the WhatsApp Cloud API webhook payload, upserts contacts
	 * and conversations, and inserts incoming messages.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function receive_whatsapp( $request ) {
		$body = $request->get_json_params();

		$entry = $body['entry'] ?? array();
		if ( empty( $entry ) ) {
			return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
		}

		$value    = $entry[0]['changes'][0]['value'] ?? array();
		$messages = $value['messages'] ?? array();
		$contacts = $value['contacts'] ?? array();

		if ( empty( $messages ) ) {
			return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
		}

		global $wpdb;

		// Build a contact lookup from the payload
		$contact_map = array();
		foreach ( $contacts as $c ) {
			$wa_id = $c['wa_id'] ?? '';
			$name  = $c['profile']['name'] ?? $wa_id;
			if ( $wa_id !== '' ) {
				$contact_map[ $wa_id ] = $name;
			}
		}

		// Get or create the WhatsApp channel
		$channel_id = self::get_or_create_channel( 'whatsapp', 'WhatsApp' );

		foreach ( $messages as $message ) {
			$from       = $message['from'] ?? '';
			$wa_id      = $from;
			$msg_id     = $message['id'] ?? '';
			$msg_type   = $message['type'] ?? 'text';

			if ( $wa_id === '' ) {
				continue;
			}

			// Determine message content
			$content      = '';
			$content_type = 'text';
			$media_url    = null;

			if ( $msg_type === 'text' ) {
				$content = $message['text']['body'] ?? '';
			} elseif ( in_array( $msg_type, array( 'image', 'video', 'audio', 'document', 'sticker' ), true ) ) {
				$content_type = $msg_type;
				$media_data   = $message[ $msg_type ] ?? array();
				$content      = $media_data['caption'] ?? ( '[' . $msg_type . ']' );
				$media_url    = $media_data['id'] ?? null; // WhatsApp media ID for later download
			} elseif ( $msg_type === 'location' ) {
				$content_type = 'location';
				$loc = $message['location'] ?? array();
				$content = sprintf(
					'Location: %s, %s',
					$loc['latitude'] ?? '0',
					$loc['longitude'] ?? '0'
				);
			} elseif ( $msg_type === 'contacts' ) {
				$content_type = 'contact';
				$shared = $message['contacts'][0] ?? array();
				$content = sprintf(
					'Shared contact: %s',
					$shared['name']['formatted_name'] ?? 'Unknown'
				);
			} else {
				$content = '[' . $msg_type . ' message]';
			}

			$contact_name = $contact_map[ $wa_id ] ?? $wa_id;

			// Upsert contact
			$contact_id = self::get_or_create_contact( $wa_id, $contact_name, 'whatsapp' );

			// Get or create conversation
			$conversation_id = self::get_or_create_conversation( $channel_id, $contact_id, $wa_id );

			// Insert message
			$wpdb->insert(
				$wpdb->prefix . 'wai_messages',
				array(
					'conversation_id'     => $conversation_id,
					'platform_message_id' => $msg_id,
					'sender_type'         => 'contact',
					'sender_id'           => $contact_id,
					'content'             => $content,
					'content_type'        => $content_type,
					'media_url'           => $media_url,
					'status'              => 'received',
				),
				array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);

			// Update conversation timestamps and unread count
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->prefix}wai_conversations
				 SET last_message_at = %s,
				     unread_count = unread_count + 1,
				     updated_at = %s
				 WHERE id = %d",
				current_time( 'mysql' ),
				current_time( 'mysql' ),
				$conversation_id
			) );
		}

		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	// ================================================================
	// Telegram Bot API
	// ================================================================

	/**
	 * POST handler for incoming Telegram updates.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function receive_telegram( $request ) {
		$body = $request->get_json_params();

		$message = $body['message'] ?? ( $body['edited_message'] ?? null );

		if ( ! $message ) {
			return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
		}

		$chat       = $message['chat'] ?? array();
		$from       = $message['from'] ?? array();
		$chat_id    = (string) ( $chat['id'] ?? '' );
		$text       = $message['text'] ?? '';
		$message_id = (string) ( $message['message_id'] ?? '' );

		if ( $chat_id === '' ) {
			return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
		}

		// Determine content and type
		$content      = $text;
		$content_type = 'text';
		$media_url    = null;

		if ( $text === '' ) {
			// Check for media types
			if ( isset( $message['photo'] ) ) {
				$content_type = 'image';
				$photos = $message['photo'];
				$largest = end( $photos );
				$media_url = $largest['file_id'] ?? null;
				$content = $message['caption'] ?? '[photo]';
			} elseif ( isset( $message['video'] ) ) {
				$content_type = 'video';
				$media_url = $message['video']['file_id'] ?? null;
				$content = $message['caption'] ?? '[video]';
			} elseif ( isset( $message['voice'] ) ) {
				$content_type = 'audio';
				$media_url = $message['voice']['file_id'] ?? null;
				$content = '[voice message]';
			} elseif ( isset( $message['document'] ) ) {
				$content_type = 'document';
				$media_url = $message['document']['file_id'] ?? null;
				$content = $message['caption'] ?? ( $message['document']['file_name'] ?? '[document]' );
			} elseif ( isset( $message['sticker'] ) ) {
				$content_type = 'sticker';
				$media_url = $message['sticker']['file_id'] ?? null;
				$content = $message['sticker']['emoji'] ?? '[sticker]';
			} elseif ( isset( $message['location'] ) ) {
				$content_type = 'location';
				$content = sprintf(
					'Location: %s, %s',
					$message['location']['latitude'] ?? '0',
					$message['location']['longitude'] ?? '0'
				);
			} elseif ( isset( $message['contact'] ) ) {
				$content_type = 'contact';
				$shared = $message['contact'];
				$content = sprintf(
					'Shared contact: %s (%s)',
					$shared['first_name'] ?? 'Unknown',
					$shared['phone_number'] ?? ''
				);
			} else {
				$content = '[unsupported message type]';
			}
		}

		$first_name = $from['first_name'] ?? '';
		$last_name  = $from['last_name'] ?? '';
		$full_name  = trim( $first_name . ' ' . $last_name );
		if ( $full_name === '' ) {
			$full_name = $from['username'] ?? ( 'Telegram ' . $chat_id );
		}

		global $wpdb;

		// Get or create Telegram channel
		$channel_id = self::get_or_create_channel( 'telegram', 'Telegram' );

		// Upsert contact by telegram chat ID
		$contact_id = self::get_or_create_contact( $chat_id, $full_name, 'telegram' );

		// Get or create conversation
		$conversation_id = self::get_or_create_conversation( $channel_id, $contact_id, $chat_id );

		// Insert message
		$wpdb->insert(
			$wpdb->prefix . 'wai_messages',
			array(
				'conversation_id'     => $conversation_id,
				'platform_message_id' => $message_id,
				'sender_type'         => 'contact',
				'sender_id'           => $contact_id,
				'content'             => $content,
				'content_type'        => $content_type,
				'media_url'           => $media_url,
				'status'              => 'received',
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		// Update conversation
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}wai_conversations
			 SET last_message_at = %s,
			     unread_count = unread_count + 1,
			     updated_at = %s
			 WHERE id = %d",
			current_time( 'mysql' ),
			current_time( 'mysql' ),
			$conversation_id
		) );

		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	// ================================================================
	// Instagram Messaging
	// ================================================================

	/**
	 * GET handler for Instagram webhook verification.
	 *
	 * Same pattern as WhatsApp, but uses waipress_instagram_app_secret
	 * as the verify token.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function verify_instagram( $request ) {
		$hub_mode         = $request->get_param( 'hub_mode' ) ?? $request->get_param( 'hub.mode' ) ?? '';
		$hub_verify_token = $request->get_param( 'hub_verify_token' ) ?? $request->get_param( 'hub.verify_token' ) ?? '';
		$hub_challenge    = $request->get_param( 'hub_challenge' ) ?? $request->get_param( 'hub.challenge' ) ?? '';

		$expected_token = get_option( 'waipress_instagram_app_secret', '' );

		if ( $hub_mode === 'subscribe' && $hub_verify_token === $expected_token && $expected_token !== '' ) {
			$response = new WP_REST_Response( $hub_challenge, 200 );
			$response->header( 'Content-Type', 'text/plain' );
			return $response;
		}

		return new WP_Error( 'forbidden', 'Verification failed.', array( 'status' => 403 ) );
	}

	/**
	 * POST handler for incoming Instagram DMs.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function receive_instagram( $request ) {
		$body = $request->get_json_params();

		$entry = $body['entry'] ?? array();
		if ( empty( $entry ) ) {
			return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
		}

		$messaging = $entry[0]['messaging'] ?? array();
		if ( empty( $messaging ) ) {
			return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
		}

		$event = $messaging[0];

		$sender_id  = (string) ( $event['sender']['id'] ?? '' );
		$msg_data   = $event['message'] ?? null;
		$timestamp  = $event['timestamp'] ?? 0;

		if ( $sender_id === '' || ! $msg_data ) {
			return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
		}

		// Determine content
		$content      = '';
		$content_type = 'text';
		$media_url    = null;
		$msg_id       = $msg_data['mid'] ?? '';

		if ( isset( $msg_data['text'] ) ) {
			$content = $msg_data['text'];
		}

		if ( isset( $msg_data['attachments'] ) && is_array( $msg_data['attachments'] ) ) {
			$attachment   = $msg_data['attachments'][0] ?? array();
			$attach_type  = $attachment['type'] ?? 'fallback';
			$payload      = $attachment['payload'] ?? array();

			$content_type = $attach_type === 'image' ? 'image'
				: ( $attach_type === 'video' ? 'video'
				: ( $attach_type === 'audio' ? 'audio' : 'file' ) );

			$media_url = $payload['url'] ?? null;

			if ( $content === '' ) {
				$content = '[' . $content_type . ']';
			}
		}

		if ( $content === '' ) {
			$content = '[empty message]';
		}

		global $wpdb;

		// Get or create Instagram channel
		$channel_id = self::get_or_create_channel( 'instagram', 'Instagram' );

		// Upsert contact by Instagram sender ID
		$contact_id = self::get_or_create_contact( $sender_id, 'Instagram ' . $sender_id, 'instagram' );

		// Get or create conversation
		$conversation_id = self::get_or_create_conversation( $channel_id, $contact_id, $sender_id );

		// Insert message
		$wpdb->insert(
			$wpdb->prefix . 'wai_messages',
			array(
				'conversation_id'     => $conversation_id,
				'platform_message_id' => $msg_id,
				'sender_type'         => 'contact',
				'sender_id'           => $contact_id,
				'content'             => $content,
				'content_type'        => $content_type,
				'media_url'           => $media_url,
				'status'              => 'received',
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		// Update conversation
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->prefix}wai_conversations
			 SET last_message_at = %s,
			     unread_count = unread_count + 1,
			     updated_at = %s
			 WHERE id = %d",
			current_time( 'mysql' ),
			current_time( 'mysql' ),
			$conversation_id
		) );

		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	// ================================================================
	// Shared Helpers
	// ================================================================

	/**
	 * Get or create a messaging channel by platform.
	 *
	 * @param string $platform Platform slug (whatsapp, telegram, instagram).
	 * @param string $name     Human-readable channel name.
	 * @return int Channel ID.
	 */
	private static function get_or_create_channel( string $platform, string $name ): int {
		global $wpdb;

		$channel_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}wai_channels WHERE platform = %s AND status = 'active' LIMIT 1",
			$platform
		) );

		if ( $channel_id ) {
			return (int) $channel_id;
		}

		$wpdb->insert(
			$wpdb->prefix . 'wai_channels',
			array(
				'platform' => $platform,
				'name'     => $name,
				'status'   => 'active',
			),
			array( '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get or create a contact by phone number or platform ID.
	 *
	 * Looks up the contact by phone field. If not found, creates a new one.
	 *
	 * @param string $platform_id The platform-specific user identifier (phone, chat_id, etc.).
	 * @param string $name        Contact display name.
	 * @param string $source      Source platform (whatsapp, telegram, instagram).
	 * @return int Contact ID.
	 */
	private static function get_or_create_contact( string $platform_id, string $name, string $source ): int {
		global $wpdb;

		// Look up by phone field (stores the platform-specific ID)
		$contact_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}wai_contacts WHERE phone = %s LIMIT 1",
			$platform_id
		) );

		if ( $contact_id ) {
			// Update name if it was previously just the platform ID
			$existing_name = $wpdb->get_var( $wpdb->prepare(
				"SELECT name FROM {$wpdb->prefix}wai_contacts WHERE id = %d",
				$contact_id
			) );
			if ( $existing_name === $platform_id && $name !== $platform_id ) {
				$wpdb->update(
					$wpdb->prefix . 'wai_contacts',
					array(
						'name'       => $name,
						'updated_at' => current_time( 'mysql' ),
					),
					array( 'id' => $contact_id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			}
			return (int) $contact_id;
		}

		$wpdb->insert(
			$wpdb->prefix . 'wai_contacts',
			array(
				'name'   => $name,
				'phone'  => $platform_id,
				'source' => $source,
			),
			array( '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get or create a conversation by platform conversation ID.
	 *
	 * @param int    $channel_id  Channel ID.
	 * @param int    $contact_id  Contact ID.
	 * @param string $platform_id Platform-specific conversation identifier.
	 * @return int Conversation ID.
	 */
	private static function get_or_create_conversation( int $channel_id, int $contact_id, string $platform_id ): int {
		global $wpdb;

		$conversation_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}wai_conversations
			 WHERE channel_id = %d AND platform_conversation_id = %s
			 LIMIT 1",
			$channel_id,
			$platform_id
		) );

		if ( $conversation_id ) {
			return (int) $conversation_id;
		}

		$wpdb->insert(
			$wpdb->prefix . 'wai_conversations',
			array(
				'channel_id'               => $channel_id,
				'contact_id'               => $contact_id,
				'platform_conversation_id' => $platform_id,
				'status'                   => 'open',
				'last_message_at'          => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}
}
