<?php
/**
 * WAIpress AI Provider Interface
 *
 * Defines the contract all AI providers (OpenAI, Ollama, etc.) must implement.
 *
 * @package WAIpress
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface WAIpress_AI_Provider {

	/**
	 * Get the human-readable provider name.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Generate text content from a prompt.
	 *
	 * @param string $prompt        The user prompt.
	 * @param string $system_prompt The system prompt.
	 * @param array  $options       Optional overrides: model, max_tokens, temperature.
	 * @return array|WP_Error Array with keys: output (string), model (string),
	 *                        input_tokens (int), output_tokens (int). WP_Error on failure.
	 */
	public function generate( string $prompt, string $system_prompt, array $options = [] ): array;

	/**
	 * Stream text generation via Server-Sent Events.
	 *
	 * Outputs SSE data lines: {"text":"..."} chunks during generation,
	 * then {"done":true} when complete.
	 *
	 * @param string $prompt        The user prompt.
	 * @param string $system_prompt The system prompt.
	 * @param array  $options       Optional overrides: model, max_tokens, temperature.
	 * @return void
	 */
	public function stream( string $prompt, string $system_prompt, array $options = [] ): void;

	/**
	 * Generate vector embeddings for text.
	 *
	 * @param string $text The text to embed.
	 * @return array|WP_Error Float array of embedding values, or WP_Error on failure.
	 */
	public function generate_embeddings( string $text ): array;

	/**
	 * Whether this provider supports embedding generation.
	 *
	 * @return bool
	 */
	public function supports_embeddings(): bool;

	/**
	 * Whether this provider supports image generation.
	 *
	 * @return bool
	 */
	public function supports_images(): bool;

	/**
	 * Generate an image from a text prompt.
	 *
	 * @param string $prompt  The image description.
	 * @param array  $options Optional: size, quality, style, etc.
	 * @return array|WP_Error Array with key 'url' (string), or WP_Error on failure.
	 */
	public function generate_image( string $prompt, array $options = [] ): array;
}
