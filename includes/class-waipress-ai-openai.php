<?php
/**
 * WAIpress OpenAI-Compatible AI Provider
 *
 * Implements the WAIpress_AI_Provider interface for any OpenAI-compatible API
 * (OpenAI, Azure OpenAI, LM Studio, vLLM, etc.).
 *
 * @package WAIpress
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_AI_OpenAI implements WAIpress_AI_Provider {

	/** @var string Base URL for the API (no trailing slash). */
	protected string $base_url;

	/** @var string API key for Authorization header. */
	protected string $api_key;

	/** @var string Default chat model. */
	protected string $model;

	/** @var int Default max tokens for generation. */
	protected int $max_tokens;

	/** @var string Model used for embeddings. */
	protected string $embedding_model;

	/** @var string Model used for image generation. */
	protected string $image_model;

	/**
	 * Constructor.
	 *
	 * @param array $config {
	 *     @type string $base_url        API base URL. Default 'https://api.openai.com'.
	 *     @type string $api_key         API key.
	 *     @type string $model           Chat model. Default 'gpt-4o'.
	 *     @type int    $max_tokens      Max tokens. Default 4096.
	 *     @type string $embedding_model Embedding model. Default 'text-embedding-3-small'.
	 *     @type string $image_model     Image model. Default 'dall-e-3'.
	 * }
	 */
	public function __construct( array $config ) {
		$this->base_url        = rtrim( $config['base_url'] ?? 'https://api.openai.com', '/' );
		$this->api_key         = $config['api_key'] ?? '';
		$this->model           = $config['model'] ?? 'gpt-4o';
		$this->max_tokens      = (int) ( $config['max_tokens'] ?? 4096 );
		$this->embedding_model = $config['embedding_model'] ?? 'text-embedding-3-small';
		$this->image_model     = $config['image_model'] ?? 'dall-e-3';
	}

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'OpenAI';
	}

	/**
	 * Build authorization headers.
	 *
	 * @return array
	 */
	protected function get_headers(): array {
		$headers = array(
			'Content-Type' => 'application/json',
		);
		if ( $this->api_key !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $this->api_key;
		}
		return $headers;
	}

	/**
	 * @inheritDoc
	 */
	public function generate( string $prompt, string $system_prompt, array $options = [] ): array {
		$model      = $options['model'] ?? $this->model;
		$max_tokens = (int) ( $options['max_tokens'] ?? $this->max_tokens );
		$temperature = $options['temperature'] ?? null;

		$messages = array();
		if ( $system_prompt !== '' ) {
			$messages[] = array( 'role' => 'system', 'content' => $system_prompt );
		}
		$messages[] = array( 'role' => 'user', 'content' => $prompt );

		$body = array(
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'messages'   => $messages,
		);
		if ( $temperature !== null ) {
			$body['temperature'] = (float) $temperature;
		}

		$response = wp_remote_post( $this->base_url . '/v1/chat/completions', array(
			'headers' => $this->get_headers(),
			'body'    => wp_json_encode( $body ),
			'timeout' => 120,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$data        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code !== 200 ) {
			$error_msg = $data['error']['message'] ?? ( $data['error'] ?? __( 'AI generation failed.', 'waipress' ) );
			if ( is_array( $error_msg ) ) {
				$error_msg = wp_json_encode( $error_msg );
			}
			return new WP_Error( 'api_error', $error_msg );
		}

		$output = $data['choices'][0]['message']['content'] ?? '';

		return array(
			'output'        => $output,
			'model'         => $data['model'] ?? $model,
			'input_tokens'  => $data['usage']['prompt_tokens'] ?? 0,
			'output_tokens' => $data['usage']['completion_tokens'] ?? 0,
		);
	}

	/**
	 * @inheritDoc
	 */
	public function stream( string $prompt, string $system_prompt, array $options = [] ): void {
		$model      = $options['model'] ?? $this->model;
		$max_tokens = (int) ( $options['max_tokens'] ?? $this->max_tokens );
		$temperature = $options['temperature'] ?? null;

		$messages = array();
		if ( $system_prompt !== '' ) {
			$messages[] = array( 'role' => 'system', 'content' => $system_prompt );
		}
		$messages[] = array( 'role' => 'user', 'content' => $prompt );

		$body = array(
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'stream'     => true,
			'messages'   => $messages,
		);
		if ( $temperature !== null ) {
			$body['temperature'] = (float) $temperature;
		}

		// Some providers support stream_options for usage reporting
		$body['stream_options'] = array( 'include_usage' => true );

		// Set SSE headers
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		$url     = $this->base_url . '/v1/chat/completions';
		$headers = array();
		foreach ( $this->get_headers() as $key => $value ) {
			$headers[] = $key . ': ' . $value;
		}

		$ch = curl_init( $url );
		curl_setopt_array( $ch, array(
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) {
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

					// End of stream
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

					// Usage info (sent by some providers in the final chunk)
					if ( isset( $event['usage'] ) && ! empty( $event['usage'] ) ) {
						echo "data: " . wp_json_encode( array( 'usage' => $event['usage'] ) ) . "\n\n";
						if ( ob_get_level() ) {
							ob_flush();
						}
						flush();
					}

					// Content delta
					$delta_content = $event['choices'][0]['delta']['content'] ?? null;
					if ( $delta_content !== null && $delta_content !== '' ) {
						echo "data: " . wp_json_encode( array( 'text' => $delta_content ) ) . "\n\n";
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
		$err = curl_error( $ch );
		curl_close( $ch );

		if ( $err ) {
			echo "data: " . wp_json_encode( array( 'error' => $err ) ) . "\n\n";
		}

		// Ensure done signal is always sent
		echo "data: " . wp_json_encode( array( 'done' => true ) ) . "\n\n";
		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
	}

	/**
	 * @inheritDoc
	 */
	public function generate_embeddings( string $text ): array {
		$response = wp_remote_post( $this->base_url . '/v1/embeddings', array(
			'headers' => $this->get_headers(),
			'body'    => wp_json_encode( array(
				'model' => $this->embedding_model,
				'input' => $text,
			) ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$data        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code !== 200 ) {
			$error_msg = $data['error']['message'] ?? __( 'Embedding generation failed.', 'waipress' );
			if ( is_array( $error_msg ) ) {
				$error_msg = wp_json_encode( $error_msg );
			}
			return new WP_Error( 'embedding_error', $error_msg );
		}

		$embedding = $data['data'][0]['embedding'] ?? null;
		if ( ! is_array( $embedding ) ) {
			return new WP_Error( 'embedding_error', __( 'No embedding data in response.', 'waipress' ) );
		}

		return $embedding;
	}

	/**
	 * @inheritDoc
	 */
	public function supports_embeddings(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function supports_images(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function generate_image( string $prompt, array $options = [] ): array {
		$body = array(
			'model'  => $options['model'] ?? $this->image_model,
			'prompt' => $prompt,
			'n'      => 1,
		);

		if ( isset( $options['size'] ) ) {
			$body['size'] = $options['size'];
		}
		if ( isset( $options['quality'] ) ) {
			$body['quality'] = $options['quality'];
		}
		if ( isset( $options['style'] ) ) {
			$body['style'] = $options['style'];
		}

		$response = wp_remote_post( $this->base_url . '/v1/images/generations', array(
			'headers' => $this->get_headers(),
			'body'    => wp_json_encode( $body ),
			'timeout' => 120,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$data        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code !== 200 ) {
			$error_msg = $data['error']['message'] ?? __( 'Image generation failed.', 'waipress' );
			if ( is_array( $error_msg ) ) {
				$error_msg = wp_json_encode( $error_msg );
			}
			return new WP_Error( 'image_error', $error_msg );
		}

		$url = $data['data'][0]['url'] ?? null;
		if ( ! $url ) {
			return new WP_Error( 'image_error', __( 'No image URL in response.', 'waipress' ) );
		}

		return array( 'url' => $url );
	}
}
