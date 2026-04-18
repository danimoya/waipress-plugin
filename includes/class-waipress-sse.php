<?php
/**
 * WAIpress SSE + Polling Endpoints
 *
 * Replaces the Node.js Realtime service. Provides:
 * - Server-Sent Events streaming for the public chatbot widget
 * - REST polling endpoint for messaging updates
 * - WordPress Heartbeat integration for unread message counts
 *
 * @package WAIpress
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_SSE {

	/**
	 * Register AJAX and Heartbeat hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_nopriv_waipress_chatbot_stream', array( __CLASS__, 'chatbot_stream' ) );
		add_action( 'wp_ajax_waipress_chatbot_stream', array( __CLASS__, 'chatbot_stream' ) );
		add_filter( 'heartbeat_received', array( __CLASS__, 'heartbeat_received' ), 10, 2 );
	}

	// ================================================================
	// Chatbot SSE Streaming
	// ================================================================

	/**
	 * Stream an AI chatbot response via Server-Sent Events.
	 *
	 * Accepts POST with session_id and content. Validates the session,
	 * saves the user message, loads conversation history, streams the
	 * AI response back as SSE events, then saves the full assistant reply.
	 */
	public static function chatbot_stream() {
		global $wpdb;

		// Read and validate input
		$session_id = absint( $_POST['session_id'] ?? 0 );
		$content    = sanitize_textarea_field( $_POST['content'] ?? '' );

		if ( $session_id <= 0 || $content === '' ) {
			self::send_sse_error( 'session_id and content are required.' );
			exit;
		}

		// Verify session exists and is active, joined with its config
		$session = $wpdb->get_row( $wpdb->prepare(
			"SELECT s.*, c.system_prompt, c.model, c.max_tokens, c.temperature
			 FROM {$wpdb->prefix}wai_chatbot_sessions s
			 JOIN {$wpdb->prefix}wai_chatbot_configs c ON s.config_id = c.id
			 WHERE s.id = %d AND s.status = 'active'",
			$session_id
		) );

		if ( ! $session ) {
			self::send_sse_error( 'Session not found or not active.' );
			exit;
		}

		// Save the user message
		$wpdb->insert(
			$wpdb->prefix . 'wai_chatbot_messages',
			array(
				'session_id' => $session_id,
				'role'       => 'user',
				'content'    => $content,
			),
			array( '%d', '%s', '%s' )
		);

		// Load last 20 messages for conversation history
		$history = $wpdb->get_results( $wpdb->prepare(
			"SELECT role, content FROM {$wpdb->prefix}wai_chatbot_messages
			 WHERE session_id = %d
			 ORDER BY created_at DESC
			 LIMIT 20",
			$session_id
		) );

		// Reverse to chronological order
		$history = array_reverse( $history );

		// Build the messages array for the AI provider
		$messages = array();
		foreach ( $history as $msg ) {
			$messages[] = array(
				'role'    => $msg->role === 'user' ? 'user' : 'assistant',
				'content' => $msg->content,
			);
		}

		// Prepare system prompt
		$system_prompt = $session->system_prompt ?: 'You are a helpful customer support assistant for this website. Answer questions based on the site content. If you cannot help, offer to connect the visitor with a human agent.';
		$model         = $session->model ?: WAIPRESS_DEFAULT_MODEL;
		$max_tokens    = $session->max_tokens ?: 1024;
		$temperature   = $session->temperature ? (float) $session->temperature : 0.7;

		// Set SSE headers before any output
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		// Disable output buffering
		while ( ob_get_level() ) {
			ob_end_flush();
		}

		// Determine API endpoint and build the request
		$provider_type = get_option( 'waipress_ai_provider', 'openai' );

		if ( $provider_type === 'ollama' ) {
			$base_url = get_option( 'waipress_ollama_url', 'http://localhost:11434' );
			$api_key  = '';
			$url      = rtrim( $base_url, '/' ) . '/v1/chat/completions';
		} else {
			$base_url = get_option( 'waipress_openai_base_url', 'https://api.openai.com' );
			$api_key  = get_option( 'waipress_ai_api_key', defined( 'WAIPRESS_ANTHROPIC_API_KEY' ) ? WAIPRESS_ANTHROPIC_API_KEY : '' );
			$url      = rtrim( $base_url, '/' ) . '/v1/chat/completions';
		}

		// Build chat completion messages with system prompt
		$api_messages = array();
		if ( $system_prompt !== '' ) {
			$api_messages[] = array( 'role' => 'system', 'content' => $system_prompt );
		}
		foreach ( $messages as $msg ) {
			$api_messages[] = $msg;
		}

		$body = array(
			'model'      => $model,
			'max_tokens' => (int) $max_tokens,
			'stream'     => true,
			'messages'   => $api_messages,
		);
		if ( $temperature > 0 ) {
			$body['temperature'] = $temperature;
		}

		// Build cURL headers
		$curl_headers = array( 'Content-Type: application/json' );
		if ( $api_key !== '' ) {
			$curl_headers[] = 'Authorization: Bearer ' . $api_key;
		}

		// Track the full response text as it streams
		$full_response = '';

		$ch = curl_init( $url );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => $curl_headers,
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( &$full_response ) {
				$lines = explode( "\n", $data );
				foreach ( $lines as $line ) {
					$line = trim( $line );
					if ( $line === '' ) {
						continue;
					}
					if ( strpos( $line, 'data: ' ) !== 0 ) {
						continue;
					}

					$json = substr( $line, 6 );

					// End of stream signal
					if ( $json === '[DONE]' ) {
						echo "data: " . wp_json_encode( array( 'done' => true ) ) . "\n\n";
						if ( ob_get_level() ) {
							ob_flush();
						}
						flush();
						break;
					}

					$event = json_decode( $json, true );
					if ( ! $event ) {
						continue;
					}

					// Content delta
					$delta_content = $event['choices'][0]['delta']['content'] ?? null;
					if ( $delta_content !== null && $delta_content !== '' ) {
						$full_response .= $delta_content;
						echo "data: " . wp_json_encode( array( 'text' => $delta_content ) ) . "\n\n";
						if ( ob_get_level() ) {
							ob_flush();
						}
						flush();
					}

					// Usage info (sent by some providers at the end)
					if ( isset( $event['usage'] ) && ! empty( $event['usage'] ) ) {
						echo "data: " . wp_json_encode( array( 'usage' => $event['usage'] ) ) . "\n\n";
						if ( ob_get_level() ) {
							ob_flush();
						}
						flush();
					}
				}
				if ( ob_get_level() ) {
					ob_flush();
				}
				flush();
				return strlen( $data );
			},
		) );

		curl_exec( $ch );
		$curl_error = curl_error( $ch );
		curl_close( $ch );

		if ( $curl_error ) {
			echo "data: " . wp_json_encode( array( 'error' => $curl_error ) ) . "\n\n";
			if ( ob_get_level() ) {
				ob_flush();
			}
			flush();
		}

		// Send final done event (in case the stream didn't emit one)
		echo "data: " . wp_json_encode( array( 'done' => true ) ) . "\n\n";
		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();

		// Save the full assistant response to the database
		if ( $full_response !== '' ) {
			$wpdb->insert(
				$wpdb->prefix . 'wai_chatbot_messages',
				array(
					'session_id' => $session_id,
					'role'       => 'assistant',
					'content'    => $full_response,
					'model'      => $model,
				),
				array( '%d', '%s', '%s', '%s' )
			);
		}

		exit;
	}

	// ================================================================
	// Messaging Polling
	// ================================================================

	/**
	 * GET handler for messaging update polling.
	 *
	 * Returns conversations that have been updated since the given timestamp,
	 * with message counts and last message preview.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_messaging_updates( $request ) {
		global $wpdb;

		$since = sanitize_text_field( $request->get_param( 'since' ) ?? '' );

		if ( $since === '' ) {
			// Default to last 5 minutes
			$since = gmdate( 'Y-m-d H:i:s', time() - 300 );
		} else {
			// Convert ISO 8601 to MySQL datetime
			$ts = strtotime( $since );
			if ( $ts === false ) {
				return new WP_REST_Response( array( 'conversations' => array() ), 200 );
			}
			$since = gmdate( 'Y-m-d H:i:s', $ts );
		}

		$conversations = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id, c.status, c.unread_count, c.last_message_at, c.assigned_to,
			        ch.platform, ch.name as channel_name,
			        ct.name as contact_name,
			        (SELECT content
			         FROM {$wpdb->prefix}wai_messages
			         WHERE conversation_id = c.id
			         ORDER BY created_at DESC
			         LIMIT 1) as last_message,
			        (SELECT COUNT(*)
			         FROM {$wpdb->prefix}wai_messages
			         WHERE conversation_id = c.id
			           AND created_at > %s) as new_message_count
			 FROM {$wpdb->prefix}wai_conversations c
			 LEFT JOIN {$wpdb->prefix}wai_channels ch ON c.channel_id = ch.id
			 LEFT JOIN {$wpdb->prefix}wai_contacts ct ON c.contact_id = ct.id
			 WHERE c.updated_at > %s OR c.last_message_at > %s
			 ORDER BY c.last_message_at DESC
			 LIMIT 50",
			$since,
			$since,
			$since
		) );

		// Cast numeric fields
		foreach ( $conversations as &$conv ) {
			$conv->id                = (int) $conv->id;
			$conv->unread_count      = (int) $conv->unread_count;
			$conv->new_message_count = (int) $conv->new_message_count;
			$conv->assigned_to       = $conv->assigned_to ? (int) $conv->assigned_to : null;
		}

		return new WP_REST_Response( array(
			'conversations' => $conversations,
			'server_time'   => gmdate( 'c' ),
		), 200 );
	}

	// ================================================================
	// WordPress Heartbeat Integration
	// ================================================================

	/**
	 * Respond to WordPress Heartbeat with unread message count.
	 *
	 * The admin JS sends { waipress_check_messages: true } in the
	 * heartbeat data. We respond with the current unread count.
	 *
	 * @param array $response Current heartbeat response data.
	 * @param array $data     Heartbeat request data from the client.
	 * @return array Modified response.
	 */
	public static function heartbeat_received( $response, $data ) {
		if ( empty( $data['waipress_check_messages'] ) ) {
			return $response;
		}

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			$response['waipress_unread_count'] = 0;
			return $response;
		}

		$response['waipress_unread_count'] = WAIpress_Messaging::get_unread_count( $user_id );

		return $response;
	}

	// ================================================================
	// Private Helpers
	// ================================================================

	/**
	 * Send an SSE error event and terminate.
	 *
	 * @param string $message Error message.
	 */
	private static function send_sse_error( string $message ) {
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		echo "data: " . wp_json_encode( array( 'error' => $message ) ) . "\n\n";
		echo "data: " . wp_json_encode( array( 'done' => true ) ) . "\n\n";

		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
	}
}
