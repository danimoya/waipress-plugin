<?php
/**
 * WAIpress WP-Cron Job Handlers
 *
 * Replaces the Node.js Worker service. Processes pending AI text, image,
 * and embedding jobs on a one-minute cron schedule.
 *
 * @package WAIpress
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_Cron {

	/**
	 * Register the cron action hook.
	 */
	public static function init() {
		add_action( 'waipress_process_jobs', array( __CLASS__, 'run' ) );
	}

	/**
	 * Main entry point called by wp-cron.
	 * Alias kept for backward compat with the waipress.php bootstrap.
	 */
	public static function process() {
		self::run();
	}

	/**
	 * Run all pending job processors in sequence.
	 */
	public static function run() {
		try {
			self::process_text_jobs();
			self::process_image_jobs();
			self::process_embedding_jobs();
		} catch ( \Throwable $e ) {
			error_log( '[WAIpress Cron] Fatal: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
		}
	}

	/**
	 * Get the configured AI provider instance.
	 *
	 * @return WAIpress_AI_Provider
	 */
	private static function get_provider(): WAIpress_AI_Provider {
		$provider_type = get_option( 'waipress_ai_provider', 'openai' );

		if ( $provider_type === 'ollama' ) {
			return new WAIpress_AI_Ollama( array(
				'base_url'        => get_option( 'waipress_ollama_url', 'http://localhost:11434' ),
				'model'           => get_option( 'waipress_ai_model', 'llama3.1' ),
				'embedding_model' => get_option( 'waipress_embedding_model', 'nomic-embed-text' ),
			) );
		}

		return new WAIpress_AI_OpenAI( array(
			'base_url'        => get_option( 'waipress_openai_base_url', 'https://api.openai.com' ),
			'api_key'         => get_option( 'waipress_ai_api_key', defined( 'WAIPRESS_ANTHROPIC_API_KEY' ) ? WAIPRESS_ANTHROPIC_API_KEY : '' ),
			'model'           => get_option( 'waipress_ai_model', WAIPRESS_DEFAULT_MODEL ),
			'max_tokens'      => (int) get_option( 'waipress_ai_max_tokens', WAIPRESS_MAX_TOKENS ),
			'embedding_model' => get_option( 'waipress_embedding_model', 'text-embedding-3-small' ),
			'image_model'     => get_option( 'waipress_image_model', 'dall-e-3' ),
		) );
	}

	// ================================================================
	// Text Generation Jobs
	// ================================================================

	/**
	 * Process pending text/rewrite generation jobs.
	 */
	private static function process_text_jobs() {
		global $wpdb;

		$jobs = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wai_ai_generations
			 WHERE generation_type IN ('text','rewrite')
			   AND output_text IS NULL
			   AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
			 ORDER BY created_at ASC
			 LIMIT %d",
			5
		) );

		if ( empty( $jobs ) ) {
			return;
		}

		foreach ( $jobs as $job ) {
			try {
				$metadata      = $job->metadata ? json_decode( $job->metadata, true ) : array();
				$system_prompt = $metadata['system_prompt'] ?? '';

				$result = WAIpress_AI::generate_content(
					$job->input_text,
					$system_prompt,
					$job->model ?: ''
				);

				if ( is_wp_error( $result ) ) {
					error_log( sprintf(
						'[WAIpress Cron] Text job %d failed: %s',
						$job->id,
						$result->get_error_message()
					) );
					continue;
				}

				$wpdb->update(
					$wpdb->prefix . 'wai_ai_generations',
					array(
						'output_text'   => $result['output'],
						'input_tokens'  => $result['input_tokens'] ?? 0,
						'output_tokens' => $result['output_tokens'] ?? 0,
						'model'         => $result['model'] ?? $job->model,
					),
					array( 'id' => $job->id ),
					array( '%s', '%d', '%d', '%s' ),
					array( '%d' )
				);
			} catch ( \Throwable $e ) {
				error_log( sprintf(
					'[WAIpress Cron] Text job %d exception: %s',
					$job->id,
					$e->getMessage()
				) );
			}
		}
	}

	// ================================================================
	// Image Generation Jobs
	// ================================================================

	/**
	 * Process pending image generation jobs.
	 */
	private static function process_image_jobs() {
		global $wpdb;

		$jobs = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wai_ai_generations
			 WHERE generation_type = 'image'
			   AND output_text IS NULL
			   AND output_url IS NULL
			   AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
			 ORDER BY created_at ASC
			 LIMIT %d",
			3
		) );

		if ( empty( $jobs ) ) {
			return;
		}

		$image_provider = get_option( 'waipress_image_provider', 'openai' );

		foreach ( $jobs as $job ) {
			try {
				$metadata  = $job->metadata ? json_decode( $job->metadata, true ) : array();
				$image_url = null;

				if ( $image_provider === 'banana' ) {
					$image_url = self::generate_image_banana( $job->input_text, $metadata );
				} else {
					$image_url = self::generate_image_openai( $job->input_text, $metadata );
				}

				if ( is_wp_error( $image_url ) ) {
					error_log( sprintf(
						'[WAIpress Cron] Image job %d failed: %s',
						$job->id,
						$image_url->get_error_message()
					) );
					continue;
				}

				// Download and save to media library
				$attachment_url = self::save_image_to_media_library( $image_url, $job->input_text );

				if ( is_wp_error( $attachment_url ) ) {
					error_log( sprintf(
						'[WAIpress Cron] Image job %d media save failed: %s',
						$job->id,
						$attachment_url->get_error_message()
					) );
					continue;
				}

				$wpdb->update(
					$wpdb->prefix . 'wai_ai_generations',
					array( 'output_url' => $attachment_url ),
					array( 'id' => $job->id ),
					array( '%s' ),
					array( '%d' )
				);
			} catch ( \Throwable $e ) {
				error_log( sprintf(
					'[WAIpress Cron] Image job %d exception: %s',
					$job->id,
					$e->getMessage()
				) );
			}
		}
	}

	/**
	 * Generate an image via the OpenAI-compatible provider.
	 *
	 * @param string $prompt   Image description.
	 * @param array  $metadata Job metadata (style, width, height).
	 * @return string|WP_Error The remote image URL, or WP_Error on failure.
	 */
	private static function generate_image_openai( string $prompt, array $metadata ) {
		$provider = self::get_provider();

		if ( ! $provider->supports_images() ) {
			return new WP_Error( 'no_images', 'Configured provider does not support image generation.' );
		}

		$options = array();
		if ( ! empty( $metadata['width'] ) && ! empty( $metadata['height'] ) ) {
			$options['size'] = $metadata['width'] . 'x' . $metadata['height'];
		}
		if ( ! empty( $metadata['style'] ) ) {
			$options['style'] = $metadata['style'];
		}

		$result = $provider->generate_image( $prompt, $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $result['url'] ?? new WP_Error( 'no_url', 'No image URL returned from provider.' );
	}

	/**
	 * Generate an image via the Banana API.
	 *
	 * @param string $prompt   Image description.
	 * @param array  $metadata Job metadata (style, width, height).
	 * @return string|WP_Error The remote image URL, or WP_Error on failure.
	 */
	private static function generate_image_banana( string $prompt, array $metadata ) {
		$api_key = get_option( 'waipress_banana_api_key', defined( 'WAIPRESS_BANANA_API_KEY' ) ? WAIPRESS_BANANA_API_KEY : '' );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'Banana API key is not configured.' );
		}

		$body = array(
			'prompt' => $prompt,
			'width'  => $metadata['width'] ?? 1024,
			'height' => $metadata['height'] ?? 1024,
		);

		if ( ! empty( $metadata['style'] ) ) {
			$body['style'] = $metadata['style'];
		}

		$response = wp_remote_post( 'https://api.banana.dev/v1/images/generate', array(
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 120,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$data        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status_code !== 200 ) {
			$msg = $data['error']['message'] ?? $data['error'] ?? 'Banana API request failed.';
			if ( is_array( $msg ) ) {
				$msg = wp_json_encode( $msg );
			}
			return new WP_Error( 'banana_error', $msg );
		}

		$url = $data['image_url'] ?? ( $data['data'][0]['url'] ?? null );

		if ( ! $url ) {
			return new WP_Error( 'banana_error', 'No image URL in Banana API response.' );
		}

		return $url;
	}

	/**
	 * Download a remote image and save it to the WordPress media library.
	 *
	 * @param string $url    Remote image URL.
	 * @param string $prompt The prompt used (for alt text / title).
	 * @return string|WP_Error The local attachment URL, or WP_Error on failure.
	 */
	private static function save_image_to_media_library( string $url, string $prompt ) {
		$response = wp_remote_get( $url, array( 'timeout' => 60 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code !== 200 ) {
			return new WP_Error( 'download_failed', 'Failed to download generated image (HTTP ' . $status_code . ').' );
		}

		$image_data  = wp_remote_retrieve_body( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		// Determine file extension from content type
		$extension_map = array(
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
		);
		$ext = $extension_map[ $content_type ] ?? 'png';

		$filename = 'waipress-ai-' . wp_generate_uuid4() . '.' . $ext;

		$upload = wp_upload_bits( $filename, null, $image_data );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'upload_failed', $upload['error'] );
		}

		$file_path = $upload['file'];
		$file_url  = $upload['url'];
		$file_type = wp_check_filetype( basename( $file_path ), null );

		$attachment_data = array(
			'post_mime_type' => $file_type['type'],
			'post_title'     => wp_trim_words( $prompt, 10, '' ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment_data, $file_path );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Make sure image functions are available
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attach_meta = wp_generate_attachment_metadata( $attachment_id, $file_path );
		wp_update_attachment_metadata( $attachment_id, $attach_meta );

		// Set alt text from prompt
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_trim_words( $prompt, 15, '' ) );

		return $file_url;
	}

	// ================================================================
	// Embedding Jobs
	// ================================================================

	/**
	 * Process posts that need vector embeddings generated.
	 */
	private static function process_embedding_jobs() {
		global $wpdb;

		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_content, p.post_type
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->prefix}wai_embeddings e
			   ON e.content_type = p.post_type AND e.content_id = p.ID
			 WHERE p.post_status = 'publish'
			   AND p.post_type IN ('post','page')
			   AND e.id IS NULL
			   AND p.post_content != ''
			 ORDER BY p.post_date DESC
			 LIMIT %d",
			5
		) );

		if ( empty( $posts ) ) {
			return;
		}

		$provider = self::get_provider();

		if ( ! $provider->supports_embeddings() ) {
			error_log( '[WAIpress Cron] Configured AI provider does not support embeddings. Skipping.' );
			return;
		}

		$embedding_model = get_option( 'waipress_embedding_model', 'text-embedding-3-small' );

		foreach ( $posts as $post ) {
			try {
				// Strip HTML and chunk the text
				$plain_text = wp_strip_all_tags( $post->post_content );
				$plain_text = trim( $post->post_title . "\n\n" . $plain_text );

				$chunks = self::chunk_text( $plain_text, 2000, 200 );

				if ( empty( $chunks ) ) {
					continue;
				}

				// Delete existing embeddings for this content
				$wpdb->delete(
					$wpdb->prefix . 'wai_embeddings',
					array(
						'content_type' => $post->post_type,
						'content_id'   => $post->ID,
					),
					array( '%s', '%d' )
				);

				foreach ( $chunks as $chunk_index => $chunk_text ) {
					$embedding = $provider->generate_embeddings( $chunk_text );

					if ( is_wp_error( $embedding ) ) {
						error_log( sprintf(
							'[WAIpress Cron] Embedding failed for post %d chunk %d: %s',
							$post->ID,
							$chunk_index,
							$embedding->get_error_message()
						) );
						continue;
					}

					$embedding_json = wp_json_encode( $embedding );

					$wpdb->insert(
						$wpdb->prefix . 'wai_embeddings',
						array(
							'content_type' => $post->post_type,
							'content_id'   => $post->ID,
							'chunk_index'  => $chunk_index,
							'chunk_text'   => $chunk_text,
							'embedding'    => $embedding_json,
							'model'        => $embedding_model,
							'created_at'   => current_time( 'mysql' ),
						),
						array( '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
					);

					// Best-effort upsert to the external vector store (if configured).
					self::upsert_remote_vector( $post, $chunk_index, $chunk_text, $embedding );
				}
			} catch ( \Throwable $e ) {
				error_log( sprintf(
					'[WAIpress Cron] Embedding job for post %d exception: %s',
					$post->ID,
					$e->getMessage()
				) );
			}
		}
	}

	/**
	 * Upsert a chunk embedding to an external vector REST store, if configured.
	 * Best effort -- failures are logged but do not stop processing.
	 *
	 * @param object $post        The WP post object.
	 * @param int    $chunk_index Index of this chunk.
	 * @param string $chunk_text  The chunk text.
	 * @param array  $embedding   The embedding vector.
	 */
	private static function upsert_remote_vector( $post, int $chunk_index, string $chunk_text, array $embedding ) {
		$vector_url = defined( 'WAIPRESS_VECTOR_REST_URL' ) ? WAIPRESS_VECTOR_REST_URL : get_option( 'waipress_vector_rest_url', '' );

		if ( empty( $vector_url ) ) {
			return;
		}

		$vector_id = $post->post_type . '_' . $post->ID . '_' . $chunk_index;

		$response = wp_remote_post( rtrim( $vector_url, '/' ) . '/v1/vectors/waipress_content/upsert', array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'vectors' => array(
					array(
						'id'       => $vector_id,
						'values'   => $embedding,
						'metadata' => array(
							'content_type' => $post->post_type,
							'content_id'   => (int) $post->ID,
							'chunk_index'  => $chunk_index,
							'text'         => $chunk_text,
							'title'        => $post->post_title,
						),
					),
				),
			) ),
			'timeout' => 10,
		) );

		if ( is_wp_error( $response ) ) {
			error_log( sprintf(
				'[WAIpress Cron] Vector store upsert failed for %s: %s',
				$vector_id,
				$response->get_error_message()
			) );
		}
	}

	/**
	 * Split text into chunks with overlap, breaking at sentence boundaries.
	 *
	 * Ports the chunking logic from the TypeScript worker service.
	 *
	 * @param string $text         The full text to chunk.
	 * @param int    $max_chars    Maximum characters per chunk (default 2000).
	 * @param int    $overlap_chars Number of overlap characters between chunks (default 200).
	 * @return string[] Array of text chunks.
	 */
	private static function chunk_text( string $text, int $max_chars = 2000, int $overlap_chars = 200 ): array {
		$text = trim( $text );

		if ( $text === '' ) {
			return array();
		}

		// If the entire text fits in one chunk, return it as-is
		if ( mb_strlen( $text ) <= $max_chars ) {
			return array( $text );
		}

		// Split text into sentences
		$sentences = preg_split(
			'/(?<=[.!?])\s+/',
			$text,
			-1,
			PREG_SPLIT_NO_EMPTY
		);

		if ( empty( $sentences ) ) {
			// No sentence boundaries found -- fall back to hard splitting
			return self::chunk_text_hard( $text, $max_chars, $overlap_chars );
		}

		$chunks        = array();
		$current_chunk = '';

		foreach ( $sentences as $sentence ) {
			$sentence = trim( $sentence );
			if ( $sentence === '' ) {
				continue;
			}

			// If a single sentence exceeds the max, split it further
			if ( mb_strlen( $sentence ) > $max_chars ) {
				// Flush the current chunk first
				if ( $current_chunk !== '' ) {
					$chunks[]      = trim( $current_chunk );
					$current_chunk = self::get_overlap_text( $current_chunk, $overlap_chars );
				}
				// Hard-split the long sentence
				$sub_chunks = self::chunk_text_hard( $sentence, $max_chars, $overlap_chars );
				foreach ( $sub_chunks as $sub ) {
					$chunks[] = trim( $sub );
				}
				$current_chunk = self::get_overlap_text( end( $sub_chunks ), $overlap_chars );
				continue;
			}

			$candidate = $current_chunk === '' ? $sentence : $current_chunk . ' ' . $sentence;

			if ( mb_strlen( $candidate ) > $max_chars ) {
				// Current chunk is full -- save it and start a new one with overlap
				$chunks[]      = trim( $current_chunk );
				$overlap       = self::get_overlap_text( $current_chunk, $overlap_chars );
				$current_chunk = $overlap === '' ? $sentence : $overlap . ' ' . $sentence;
			} else {
				$current_chunk = $candidate;
			}
		}

		// Don't forget the last chunk
		if ( trim( $current_chunk ) !== '' ) {
			$chunks[] = trim( $current_chunk );
		}

		return $chunks;
	}

	/**
	 * Hard-split text at a character boundary when no sentence breaks are available.
	 *
	 * Attempts to break at word boundaries when possible.
	 *
	 * @param string $text          Text to split.
	 * @param int    $max_chars     Max characters per chunk.
	 * @param int    $overlap_chars Overlap between chunks.
	 * @return string[]
	 */
	private static function chunk_text_hard( string $text, int $max_chars, int $overlap_chars ): array {
		$chunks = array();
		$offset = 0;
		$length = mb_strlen( $text );

		while ( $offset < $length ) {
			$end = min( $offset + $max_chars, $length );

			if ( $end < $length ) {
				// Try to break at a word boundary (look backwards for a space)
				$break_pos = mb_strrpos( mb_substr( $text, $offset, $max_chars ), ' ' );
				if ( $break_pos !== false && $break_pos > ( $max_chars * 0.5 ) ) {
					$end = $offset + $break_pos;
				}
			}

			$chunks[] = trim( mb_substr( $text, $offset, $end - $offset ) );

			// Advance with overlap
			$offset = $end - $overlap_chars;
			if ( $offset <= ( $end - $max_chars ) ) {
				// Prevent infinite loop: ensure forward progress
				$offset = $end;
			}
		}

		return $chunks;
	}

	/**
	 * Extract overlap text from the end of a chunk.
	 *
	 * Takes the last $overlap_chars characters, starting at a word boundary.
	 *
	 * @param string $text          Source text.
	 * @param int    $overlap_chars Desired overlap size.
	 * @return string
	 */
	private static function get_overlap_text( string $text, int $overlap_chars ): string {
		if ( mb_strlen( $text ) <= $overlap_chars ) {
			return $text;
		}

		$tail = mb_substr( $text, -$overlap_chars );

		// Try to start at a word boundary
		$space_pos = mb_strpos( $tail, ' ' );
		if ( $space_pos !== false && $space_pos < ( $overlap_chars * 0.5 ) ) {
			$tail = mb_substr( $tail, $space_pos + 1 );
		}

		return $tail;
	}
}
