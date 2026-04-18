<?php
/**
 * WAIpress Ollama AI Provider
 *
 * Extends the OpenAI provider for Ollama-specific behavior:
 * - No authentication required
 * - Fallback embedding endpoint (/api/embed)
 * - Model listing via /api/tags
 * - No image generation support
 *
 * @package WAIpress
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_AI_Ollama extends WAIpress_AI_OpenAI {

	/**
	 * Constructor.
	 *
	 * @param array $config {
	 *     @type string $base_url        Ollama server URL. Default from waipress_ollama_url option or 'http://localhost:11434'.
	 *     @type string $api_key         Not used by Ollama, defaults to ''.
	 *     @type string $model           Chat model. Default 'llama3.1'.
	 *     @type int    $max_tokens      Max tokens. Default 4096.
	 *     @type string $embedding_model Embedding model. Default 'nomic-embed-text'.
	 *     @type string $image_model     Not used by Ollama.
	 * }
	 */
	public function __construct( array $config ) {
		$config['base_url'] = $config['base_url'] ?? get_option( 'waipress_ollama_url', 'http://localhost:11434' );
		$config['api_key']  = $config['api_key'] ?? '';

		// Ollama-friendly defaults
		if ( ! isset( $config['model'] ) ) {
			$config['model'] = 'llama3.1';
		}
		if ( ! isset( $config['embedding_model'] ) ) {
			$config['embedding_model'] = 'nomic-embed-text';
		}

		parent::__construct( $config );
	}

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'Ollama';
	}

	/**
	 * Generate embeddings with fallback to Ollama's native /api/embed endpoint.
	 *
	 * Tries the OpenAI-compatible /v1/embeddings first. If that fails,
	 * falls back to Ollama's native /api/embed endpoint.
	 *
	 * @inheritDoc
	 */
	public function generate_embeddings( string $text ): array {
		// Try OpenAI-compatible endpoint first
		$result = parent::generate_embeddings( $text );

		if ( ! is_wp_error( $result ) ) {
			return $result;
		}

		// Fallback to Ollama native /api/embed endpoint
		$response = wp_remote_post( $this->base_url . '/api/embed', array(
			'headers' => array( 'Content-Type' => 'application/json' ),
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
			$error_msg = $data['error'] ?? __( 'Ollama embedding generation failed.', 'waipress' );
			if ( is_array( $error_msg ) ) {
				$error_msg = wp_json_encode( $error_msg );
			}
			return new WP_Error( 'embedding_error', $error_msg );
		}

		// Ollama returns either "embedding" (single) or "embeddings" (array)
		if ( isset( $data['embedding'] ) && is_array( $data['embedding'] ) ) {
			return $data['embedding'];
		}
		if ( isset( $data['embeddings'][0] ) && is_array( $data['embeddings'][0] ) ) {
			return $data['embeddings'][0];
		}

		return new WP_Error( 'embedding_error', __( 'No embedding data in Ollama response.', 'waipress' ) );
	}

	/**
	 * List available models from the Ollama server.
	 *
	 * @return array|WP_Error Array of model name strings, or WP_Error on failure.
	 */
	public function list_models(): array {
		$response = wp_remote_get( $this->base_url . '/api/tags', array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$data        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code !== 200 ) {
			$error_msg = $data['error'] ?? __( 'Failed to list Ollama models.', 'waipress' );
			return new WP_Error( 'ollama_error', $error_msg );
		}

		$models = array();
		if ( isset( $data['models'] ) && is_array( $data['models'] ) ) {
			foreach ( $data['models'] as $model ) {
				if ( isset( $model['name'] ) ) {
					$models[] = $model['name'];
				}
			}
		}

		return $models;
	}

	/**
	 * Ollama does not support image generation.
	 *
	 * @inheritDoc
	 */
	public function supports_images(): bool {
		return false;
	}

	/**
	 * @inheritDoc
	 */
	public function generate_image( string $prompt, array $options = [] ): array {
		return new WP_Error( 'not_supported', __( 'Ollama does not support image generation.', 'waipress' ) );
	}
}
