<?php
/**
 * WAIpress AI Content Generation
 *
 * Handles all AI text generation via pluggable provider system.
 * Supports OpenAI-compatible APIs and Ollama out of the box.
 *
 * @package WAIpress
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_AI {

	/**
	 * Get the configured AI provider instance.
	 *
	 * Reads the provider type from options and constructs the appropriate
	 * WAIpress_AI_Provider implementation with settings from the database.
	 *
	 * @return WAIpress_AI_Provider
	 */
	public static function get_provider(): WAIpress_AI_Provider {
		$provider_type = get_option( 'waipress_ai_provider', 'openai' );

		$config = array(
			'base_url'        => get_option( 'waipress_ai_base_url', '' ),
			'api_key'         => get_option( 'waipress_ai_api_key', '' ),
			'model'           => get_option( 'waipress_ai_model', '' ),
			'max_tokens'      => (int) get_option( 'waipress_ai_max_tokens', 4096 ),
			'embedding_model' => get_option( 'waipress_ai_embedding_model', '' ),
			'image_model'     => get_option( 'waipress_ai_image_model', '' ),
		);

		// Remove empty strings so provider defaults apply
		$config = array_filter( $config, function ( $v ) {
			return $v !== '' && $v !== 0;
		} );

		switch ( $provider_type ) {
			case 'ollama':
				return new WAIpress_AI_Ollama( $config );

			case 'openai':
			default:
				return new WAIpress_AI_OpenAI( $config );
		}
	}

	/**
	 * Stream content generation via Server-Sent Events.
	 * Called directly (not via REST) for streaming output.
	 */
	public static function handle_stream() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( 'Unauthorized', 403 );
		}

		check_ajax_referer( 'waipress_stream', '_nonce' );

		$prompt        = sanitize_textarea_field( $_POST['prompt'] ?? '' );
		$system_prompt = sanitize_textarea_field( $_POST['system_prompt'] ?? '' );
		$mode          = sanitize_text_field( $_POST['mode'] ?? 'generate' );
		$content       = sanitize_textarea_field( $_POST['content'] ?? '' );

		if ( empty( $prompt ) && $mode !== 'seo' ) {
			header( 'Content-Type: text/event-stream' );
			header( 'Cache-Control: no-cache' );
			echo "data: " . wp_json_encode( array( 'error' => 'Prompt is required' ) ) . "\n\n";
			exit;
		}

		// Build prompts based on mode
		if ( $mode === 'rewrite' ) {
			$system_prompt = $system_prompt ?: 'You are a skilled editor. Rewrite the content according to the instructions. Return only the rewritten content.';
			$prompt = "Instruction: {$prompt}\n\nContent:\n{$content}";
		} elseif ( $mode === 'seo' ) {
			$system_prompt = 'You are an SEO expert. Return JSON with: seo_title (max 60 chars), seo_description (max 160 chars), keywords (comma-separated), excerpt (2-3 sentences). Return ONLY valid JSON.';
			$prompt = "Title: {$prompt}\n\nContent:\n{$content}";
		} elseif ( empty( $system_prompt ) ) {
			$system_prompt = 'You are a professional content writer. Write engaging, well-structured content with clear headings and paragraphs.';
		}

		$provider = self::get_provider();
		$model    = get_option( 'waipress_ai_model', '' ) ?: $provider->get_name();

		// Delegate streaming to the provider (it sets SSE headers and outputs chunks)
		$provider->stream( $prompt, $system_prompt );

		// Log the generation
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'wai_ai_generations', array(
			'user_id'         => get_current_user_id(),
			'generation_type' => $mode === 'rewrite' ? 'rewrite' : 'text',
			'input_text'      => wp_trim_words( $prompt, 50 ),
			'model'           => $model,
		) );

		exit;
	}

	/**
	 * Generate content from a prompt.
	 *
	 * @param string $prompt        The user prompt.
	 * @param string $system_prompt System prompt (optional).
	 * @param string $model         Model override (optional).
	 * @param int    $max_tokens    Max tokens override (optional).
	 * @return array|WP_Error
	 */
	public static function generate_content( $prompt, $system_prompt = '', $model = '', $max_tokens = 0 ) {
		if ( empty( $system_prompt ) ) {
			$system_prompt = 'You are a professional content writer for a website. Write engaging, well-structured content.';
		}

		$options = array();
		if ( $model !== '' ) {
			$options['model'] = $model;
		}
		if ( $max_tokens > 0 ) {
			$options['max_tokens'] = $max_tokens;
		}

		return self::get_provider()->generate( $prompt, $system_prompt, $options );
	}

	/**
	 * Log a generation to the audit table.
	 */
	private static function log_generation( $type, $input, $result, $prompt_id = null ) {
		global $wpdb;

		if ( is_wp_error( $result ) ) {
			return;
		}

		$wpdb->insert( $wpdb->prefix . 'wai_ai_generations', array(
			'prompt_id'       => $prompt_id,
			'user_id'         => get_current_user_id(),
			'generation_type' => $type,
			'input_text'      => $input,
			'output_text'     => $result['output'] ?? null,
			'model'           => $result['model'] ?? '',
			'input_tokens'    => $result['input_tokens'] ?? 0,
			'output_tokens'   => $result['output_tokens'] ?? 0,
		) );
	}

	// ============================================================
	// REST API Callbacks
	// ============================================================

	/**
	 * POST /ai/generate - Generate content from prompt.
	 */
	public static function rest_generate( $request ) {
		$prompt = sanitize_textarea_field( $request->get_param( 'prompt' ) );
		$system = sanitize_textarea_field( $request->get_param( 'system_prompt' ) ?? '' );
		$prompt_id = absint( $request->get_param( 'prompt_id' ) ?? 0 );
		$context = $request->get_param( 'context' ) ?? array();

		if ( empty( $prompt ) ) {
			return new WP_Error( 'missing_prompt', __( 'Prompt is required.', 'waipress' ), array( 'status' => 400 ) );
		}

		// If using a saved prompt template
		if ( $prompt_id ) {
			global $wpdb;
			$template = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wai_ai_prompts WHERE id = %d",
				$prompt_id
			) );
			if ( $template ) {
				$system = $template->system_prompt;
				$prompt = str_replace( '{topic}', $prompt, $template->user_prompt_template );
				$prompt = str_replace( '{content}', $context['content'] ?? $prompt, $prompt );
			}
		}

		$result = self::generate_content( $prompt, $system );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::log_generation( 'text', $prompt, $result, $prompt_id ?: null );

		return rest_ensure_response( $result );
	}

	/**
	 * POST /ai/rewrite - Rewrite existing content.
	 */
	public static function rest_rewrite( $request ) {
		$content = sanitize_textarea_field( $request->get_param( 'content' ) );
		$instruction = sanitize_textarea_field( $request->get_param( 'instruction' ) ?? 'Rewrite this content to be more engaging.' );

		if ( empty( $content ) ) {
			return new WP_Error( 'missing_content', __( 'Content is required.', 'waipress' ), array( 'status' => 400 ) );
		}

		$prompt = "Instruction: {$instruction}\n\nContent to rewrite:\n\n{$content}";
		$system = 'You are a skilled editor. Rewrite the provided content according to the instructions. Return only the rewritten content, no explanations.';

		$result = self::generate_content( $prompt, $system );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		self::log_generation( 'rewrite', $content, $result );

		return rest_ensure_response( $result );
	}

	/**
	 * POST /ai/seo - Generate SEO metadata.
	 */
	public static function rest_seo( $request ) {
		$content = sanitize_textarea_field( $request->get_param( 'content' ) );
		$title = sanitize_text_field( $request->get_param( 'title' ) ?? '' );

		if ( empty( $content ) ) {
			return new WP_Error( 'missing_content', __( 'Content is required.', 'waipress' ), array( 'status' => 400 ) );
		}

		$prompt = "Title: {$title}\n\nContent:\n{$content}";
		$system = 'You are an SEO expert. Analyze the content and return a JSON object with: "seo_title" (max 60 chars), "seo_description" (max 160 chars), "keywords" (comma-separated, 5-10 keywords), "excerpt" (2-3 sentence summary). Return ONLY valid JSON.';

		$result = self::generate_content( $prompt, $system );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Try to parse JSON from response
		$seo_data = json_decode( $result['output'], true );
		if ( $seo_data ) {
			$result['seo'] = $seo_data;
		}

		self::log_generation( 'text', $prompt, $result );

		return rest_ensure_response( $result );
	}

	/**
	 * POST /ai/suggest-tags - Suggest categories/tags for content.
	 */
	public static function rest_suggest_tags( $request ) {
		$content = sanitize_textarea_field( $request->get_param( 'content' ) );

		if ( empty( $content ) ) {
			return new WP_Error( 'missing_content', __( 'Content is required.', 'waipress' ), array( 'status' => 400 ) );
		}

		// Get existing categories and tags
		$categories = get_categories( array( 'hide_empty' => false ) );
		$tags = get_tags( array( 'hide_empty' => false ) );

		$cat_list = implode( ', ', wp_list_pluck( $categories, 'name' ) );
		$tag_list = implode( ', ', wp_list_pluck( $tags, 'name' ) );

		$prompt = "Content:\n{$content}\n\nExisting categories: {$cat_list}\nExisting tags: {$tag_list}";
		$system = 'Analyze the content and suggest the most relevant categories and tags. Return JSON with: "categories" (array of category names from the existing list), "tags" (array of tag names, can include new ones). Return ONLY valid JSON.';

		$result = self::generate_content( $prompt, $system, '', 512 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$suggestions = json_decode( $result['output'], true );
		if ( $suggestions ) {
			$result['suggestions'] = $suggestions;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * GET /ai/prompts - List prompt templates.
	 */
	public static function rest_list_prompts( $request ) {
		global $wpdb;

		$category = sanitize_text_field( $request->get_param( 'category' ) ?? '' );

		$sql = "SELECT * FROM {$wpdb->prefix}wai_ai_prompts";
		$params = array();

		if ( $category ) {
			$sql .= " WHERE category = %s";
			$params[] = $category;
		}

		$sql .= " ORDER BY is_system DESC, name ASC";

		$prompts = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

		return rest_ensure_response( $prompts );
	}

	/**
	 * POST /ai/prompts - Save a prompt template.
	 */
	public static function rest_save_prompt( $request ) {
		global $wpdb;

		$data = array(
			'name'                 => sanitize_text_field( $request->get_param( 'name' ) ),
			'description'          => sanitize_textarea_field( $request->get_param( 'description' ) ?? '' ),
			'system_prompt'        => sanitize_textarea_field( $request->get_param( 'system_prompt' ) ?? '' ),
			'user_prompt_template' => sanitize_textarea_field( $request->get_param( 'user_prompt_template' ) ?? '' ),
			'category'             => sanitize_text_field( $request->get_param( 'category' ) ?? 'blog_post' ),
			'model'                => sanitize_text_field( $request->get_param( 'model' ) ?? '' ),
			'max_tokens'           => absint( $request->get_param( 'max_tokens' ) ?? 4096 ),
			'temperature'          => floatval( $request->get_param( 'temperature' ) ?? 0.7 ),
			'created_by'           => get_current_user_id(),
		);

		$wpdb->insert( $wpdb->prefix . 'wai_ai_prompts', $data );

		return rest_ensure_response( array(
			'id' => $wpdb->insert_id,
			'message' => __( 'Prompt template saved.', 'waipress' ),
		) );
	}

	/**
	 * GET /ai/generations - List generation audit log.
	 */
	public static function rest_list_generations( $request ) {
		global $wpdb;

		$page = absint( $request->get_param( 'page' ) ?? 1 );
		$per_page = absint( $request->get_param( 'per_page' ) ?? 20 );
		$offset = ( $page - 1 ) * $per_page;

		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wai_ai_generations" );

		$generations = $wpdb->get_results( $wpdb->prepare(
			"SELECT g.*, u.display_name as user_name
			 FROM {$wpdb->prefix}wai_ai_generations g
			 LEFT JOIN {$wpdb->users} u ON g.user_id = u.ID
			 ORDER BY g.created_at DESC
			 LIMIT %d OFFSET %d",
			$per_page, $offset
		) );

		return rest_ensure_response( array(
			'items' => $generations,
			'total' => (int) $total,
			'page'  => $page,
			'pages' => ceil( $total / $per_page ),
		) );
	}
}
