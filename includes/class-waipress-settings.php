<?php
/**
 * WAIpress Settings Page
 *
 * WordPress Settings API page for AI provider, embeddings, images, and messaging.
 *
 * @package WAIpress
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_Settings {

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_ajax_waipress_test_connection', array( __CLASS__, 'ajax_test_connection' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Menu registration                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Add Settings submenu under AI Center.
	 */
	public static function add_menu() {
		add_submenu_page(
			'waipress-ai',
			__( 'Settings', 'waipress' ),
			__( 'Settings', 'waipress' ),
			'manage_options',
			'waipress-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Settings registration                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Register all WAIpress settings, sections, and fields.
	 */
	public static function register_settings() {

		/* ----- AI Provider section -------------------------------- */

		add_settings_section(
			'waipress_section_ai',
			__( 'AI Provider', 'waipress' ),
			array( __CLASS__, 'render_section_ai' ),
			'waipress-settings'
		);

		register_setting( 'waipress-settings', 'waipress_ai_provider', array(
			'type'              => 'string',
			'default'           => 'openai',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		add_settings_field( 'waipress_ai_provider', __( 'Provider', 'waipress' ), array( __CLASS__, 'field_ai_provider' ), 'waipress-settings', 'waipress_section_ai' );

		register_setting( 'waipress-settings', 'waipress_ai_base_url', array(
			'type'              => 'string',
			'default'           => 'https://api.openai.com',
			'sanitize_callback' => 'esc_url_raw',
		) );
		add_settings_field( 'waipress_ai_base_url', __( 'Base URL', 'waipress' ), array( __CLASS__, 'field_ai_base_url' ), 'waipress-settings', 'waipress_section_ai' );

		register_setting( 'waipress-settings', 'waipress_ai_api_key', array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		add_settings_field( 'waipress_ai_api_key', __( 'API Key', 'waipress' ), array( __CLASS__, 'field_ai_api_key' ), 'waipress-settings', 'waipress_section_ai' );

		register_setting( 'waipress-settings', 'waipress_ai_model', array(
			'type'              => 'string',
			'default'           => 'gpt-4o',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		add_settings_field( 'waipress_ai_model', __( 'Model', 'waipress' ), array( __CLASS__, 'field_ai_model' ), 'waipress-settings', 'waipress_section_ai' );

		register_setting( 'waipress-settings', 'waipress_ai_max_tokens', array(
			'type'              => 'integer',
			'default'           => 4096,
			'sanitize_callback' => 'absint',
		) );
		add_settings_field( 'waipress_ai_max_tokens', __( 'Max Tokens', 'waipress' ), array( __CLASS__, 'field_ai_max_tokens' ), 'waipress-settings', 'waipress_section_ai' );

		/* ----- Embeddings section --------------------------------- */

		add_settings_section(
			'waipress_section_embeddings',
			__( 'Embeddings', 'waipress' ),
			array( __CLASS__, 'render_section_embeddings' ),
			'waipress-settings'
		);

		register_setting( 'waipress-settings', 'waipress_ai_embedding_model', array(
			'type'              => 'string',
			'default'           => 'text-embedding-3-small',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		add_settings_field( 'waipress_ai_embedding_model', __( 'Embedding Model', 'waipress' ), array( __CLASS__, 'field_embedding_model' ), 'waipress-settings', 'waipress_section_embeddings' );

		register_setting( 'waipress-settings', 'waipress_vector_rest_url', array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'esc_url_raw',
		) );
		add_settings_field( 'waipress_vector_rest_url', __( 'Vector search endpoint (optional)', 'waipress' ), array( __CLASS__, 'field_vector_rest_url' ), 'waipress-settings', 'waipress_section_embeddings' );

		/* ----- Images section ------------------------------------- */

		add_settings_section(
			'waipress_section_images',
			__( 'Images', 'waipress' ),
			array( __CLASS__, 'render_section_images' ),
			'waipress-settings'
		);

		register_setting( 'waipress-settings', 'waipress_image_provider', array(
			'type'              => 'string',
			'default'           => 'openai',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		add_settings_field( 'waipress_image_provider', __( 'Image Provider', 'waipress' ), array( __CLASS__, 'field_image_provider' ), 'waipress-settings', 'waipress_section_images' );

		register_setting( 'waipress-settings', 'waipress_banana_api_key', array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		add_settings_field( 'waipress_banana_api_key', __( 'Banana API Key', 'waipress' ), array( __CLASS__, 'field_banana_api_key' ), 'waipress-settings', 'waipress_section_images' );

		/* ----- Messaging section ---------------------------------- */

		add_settings_section(
			'waipress_section_messaging',
			__( 'Messaging', 'waipress' ),
			array( __CLASS__, 'render_section_messaging' ),
			'waipress-settings'
		);

		register_setting( 'waipress-settings', 'waipress_whatsapp_verify_token', array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		add_settings_field( 'waipress_whatsapp_verify_token', __( 'WhatsApp Verify Token', 'waipress' ), array( __CLASS__, 'field_whatsapp_verify_token' ), 'waipress-settings', 'waipress_section_messaging' );

		register_setting( 'waipress-settings', 'waipress_telegram_bot_token', array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		add_settings_field( 'waipress_telegram_bot_token', __( 'Telegram Bot Token', 'waipress' ), array( __CLASS__, 'field_telegram_bot_token' ), 'waipress-settings', 'waipress_section_messaging' );

		register_setting( 'waipress-settings', 'waipress_instagram_app_secret', array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		add_settings_field( 'waipress_instagram_app_secret', __( 'Instagram App Secret', 'waipress' ), array( __CLASS__, 'field_instagram_app_secret' ), 'waipress-settings', 'waipress_section_messaging' );

		register_setting( 'waipress-settings', 'waipress_webhook_secret', array(
			'type'              => 'string',
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		) );
		add_settings_field( 'waipress_webhook_secret', __( 'Webhook Secret', 'waipress' ), array( __CLASS__, 'field_webhook_secret' ), 'waipress-settings', 'waipress_section_messaging' );
	}

	/* ------------------------------------------------------------------ */
	/*  Section descriptions                                              */
	/* ------------------------------------------------------------------ */

	public static function render_section_ai() {
		echo '<p>' . esc_html__( 'Configure the AI provider used for content generation, rewriting, and chatbot responses.', 'waipress' ) . '</p>';
	}

	public static function render_section_embeddings() {
		echo '<p>' . esc_html__( 'Configure the embedding model and vector database endpoint for semantic search and RAG.', 'waipress' ) . '</p>';
	}

	public static function render_section_images() {
		echo '<p>' . esc_html__( 'Configure the image generation provider.', 'waipress' ) . '</p>';
	}

	public static function render_section_messaging() {
		echo '<p>' . esc_html__( 'Tokens and secrets for WhatsApp, Telegram, and Instagram messaging channels.', 'waipress' ) . '</p>';
	}

	/* ------------------------------------------------------------------ */
	/*  Field renderers                                                   */
	/* ------------------------------------------------------------------ */

	public static function field_ai_provider() {
		$value = get_option( 'waipress_ai_provider', 'openai' );
		?>
		<select name="waipress_ai_provider" id="waipress_ai_provider">
			<option value="openai" <?php selected( $value, 'openai' ); ?>><?php esc_html_e( 'OpenAI-compatible', 'waipress' ); ?></option>
			<option value="ollama" <?php selected( $value, 'ollama' ); ?>><?php esc_html_e( 'Ollama (local)', 'waipress' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'OpenAI-compatible works with OpenAI, Anthropic, Groq, and any provider exposing the /v1/chat/completions endpoint.', 'waipress' ); ?></p>
		<?php
	}

	public static function field_ai_base_url() {
		$value = get_option( 'waipress_ai_base_url', 'https://api.openai.com' );
		?>
		<input type="text" name="waipress_ai_base_url" id="waipress_ai_base_url"
			   value="<?php echo esc_attr( $value ); ?>" class="regular-text"
			   placeholder="https://api.openai.com" />
		<p class="description"><?php esc_html_e( 'Base URL of the API. For Ollama, use http://127.0.0.1:11434.', 'waipress' ); ?></p>
		<?php
	}

	public static function field_ai_api_key() {
		$value = get_option( 'waipress_ai_api_key', '' );
		?>
		<input type="password" name="waipress_ai_api_key" id="waipress_ai_api_key"
			   value="<?php echo esc_attr( $value ); ?>" class="regular-text"
			   autocomplete="off" />
		<p class="description"><?php esc_html_e( 'API key for the chosen provider. Leave blank for Ollama.', 'waipress' ); ?></p>
		<?php
	}

	public static function field_ai_model() {
		$value = get_option( 'waipress_ai_model', 'gpt-4o' );
		?>
		<input type="text" name="waipress_ai_model" id="waipress_ai_model"
			   value="<?php echo esc_attr( $value ); ?>" class="regular-text"
			   placeholder="gpt-4o" />
		<p class="description"><?php esc_html_e( 'Model identifier (e.g. gpt-4o, claude-sonnet-4-20250514, llama3).', 'waipress' ); ?></p>
		<?php
	}

	public static function field_ai_max_tokens() {
		$value = get_option( 'waipress_ai_max_tokens', 4096 );
		?>
		<input type="number" name="waipress_ai_max_tokens" id="waipress_ai_max_tokens"
			   value="<?php echo esc_attr( $value ); ?>" class="small-text"
			   min="256" max="128000" step="256" />
		<p class="description"><?php esc_html_e( 'Maximum tokens per generation response.', 'waipress' ); ?></p>
		<?php
	}

	public static function field_embedding_model() {
		$value = get_option( 'waipress_ai_embedding_model', 'text-embedding-3-small' );
		?>
		<input type="text" name="waipress_ai_embedding_model" id="waipress_ai_embedding_model"
			   value="<?php echo esc_attr( $value ); ?>" class="regular-text"
			   placeholder="text-embedding-3-small" />
		<p class="description"><?php esc_html_e( 'Model used to generate text embeddings for semantic search.', 'waipress' ); ?></p>
		<?php
	}

	public static function field_vector_rest_url() {
		$value = get_option( 'waipress_vector_rest_url', '' );
		?>
		<input type="text" name="waipress_vector_rest_url" id="waipress_vector_rest_url"
			   value="<?php echo esc_attr( $value ); ?>" class="regular-text"
			   placeholder="https://vectors.example.com" />
		<p class="description">
			<strong><?php esc_html_e( 'Optional.', 'waipress' ); ?></strong>
			<?php esc_html_e( 'Leave empty to use the built-in MySQL cosine similarity search (works everywhere, no extra service required).', 'waipress' ); ?>
			<br />
			<?php
			printf(
				/* translators: %s: HeliosDB-Nano link */
				esc_html__( 'WAIpress is built by the team behind %s. For production knowledge bases with more than ~10k chunks we recommend it, but any OpenAI-compatible vector REST endpoint works here.', 'waipress' ),
				'<a href="https://danielmoya.cv/heliosdb-nano" target="_blank" rel="noopener">HeliosDB-Nano</a>'
			);
			?>
		</p>
		<?php
	}

	public static function field_image_provider() {
		$value = get_option( 'waipress_image_provider', 'openai' );
		?>
		<select name="waipress_image_provider" id="waipress_image_provider">
			<option value="openai" <?php selected( $value, 'openai' ); ?>><?php esc_html_e( 'OpenAI (DALL-E)', 'waipress' ); ?></option>
			<option value="banana" <?php selected( $value, 'banana' ); ?>><?php esc_html_e( 'Banana (Stable Diffusion)', 'waipress' ); ?></option>
		</select>
		<?php
	}

	public static function field_banana_api_key() {
		$value          = get_option( 'waipress_banana_api_key', '' );
		$image_provider = get_option( 'waipress_image_provider', 'openai' );
		$hidden         = ( $image_provider !== 'banana' ) ? ' style="display:none;"' : '';
		?>
		<div id="waipress-banana-key-row"<?php echo $hidden; ?>>
			<input type="password" name="waipress_banana_api_key" id="waipress_banana_api_key"
				   value="<?php echo esc_attr( $value ); ?>" class="regular-text"
				   autocomplete="off" />
			<p class="description"><?php esc_html_e( 'API key for the Banana image generation service.', 'waipress' ); ?></p>
		</div>
		<?php
	}

	public static function field_whatsapp_verify_token() {
		$value = get_option( 'waipress_whatsapp_verify_token', '' );
		?>
		<input type="password" name="waipress_whatsapp_verify_token" id="waipress_whatsapp_verify_token"
			   value="<?php echo esc_attr( $value ); ?>" class="regular-text"
			   autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Verification token for the WhatsApp Business API webhook.', 'waipress' ); ?></p>
		<?php
	}

	public static function field_telegram_bot_token() {
		$value = get_option( 'waipress_telegram_bot_token', '' );
		?>
		<input type="password" name="waipress_telegram_bot_token" id="waipress_telegram_bot_token"
			   value="<?php echo esc_attr( $value ); ?>" class="regular-text"
			   autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Bot token from @BotFather on Telegram.', 'waipress' ); ?></p>
		<?php
	}

	public static function field_instagram_app_secret() {
		$value = get_option( 'waipress_instagram_app_secret', '' );
		?>
		<input type="password" name="waipress_instagram_app_secret" id="waipress_instagram_app_secret"
			   value="<?php echo esc_attr( $value ); ?>" class="regular-text"
			   autocomplete="off" />
		<p class="description"><?php esc_html_e( 'App Secret from your Meta/Instagram developer app.', 'waipress' ); ?></p>
		<?php
	}

	public static function field_webhook_secret() {
		$value = get_option( 'waipress_webhook_secret', '' );
		?>
		<input type="password" name="waipress_webhook_secret" id="waipress_webhook_secret"
			   value="<?php echo esc_attr( $value ); ?>" class="regular-text"
			   autocomplete="off" />
		<p class="description"><?php esc_html_e( 'Shared secret used to verify incoming webhook payloads (HMAC-SHA256).', 'waipress' ); ?></p>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/*  Page renderer                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Render the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Show success notice after save.
		if ( isset( $_GET['settings-updated'] ) ) {
			add_settings_error( 'waipress_messages', 'waipress_updated', __( 'Settings saved.', 'waipress' ), 'updated' );
		}
		settings_errors( 'waipress_messages' );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WAIpress Settings', 'waipress' ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'waipress-settings' );
				do_settings_sections( 'waipress-settings' );
				submit_button();
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Connection Test', 'waipress' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Send a test prompt to the configured AI provider to verify connectivity.', 'waipress' ); ?></p>
			<p>
				<button type="button" id="waipress-test-connection" class="button button-secondary">
					<?php esc_html_e( 'Test Connection', 'waipress' ); ?>
				</button>
				<span id="waipress-test-spinner" class="spinner" style="float:none;"></span>
			</p>
			<div id="waipress-test-result" style="margin-top:10px;"></div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/*  Admin scripts (inline)                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Enqueue a small inline script on the settings page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_scripts( $hook ) {
		if ( $hook !== 'ai-center_page_waipress-settings' ) {
			return;
		}

		wp_register_script( 'waipress-settings', '', array(), WAIPRESS_VERSION, true );
		wp_enqueue_script( 'waipress-settings' );

		wp_localize_script( 'waipress-settings', 'waipressSettings', array(
			'nonce' => wp_create_nonce( 'waipress_test_connection' ),
		) );

		$inline_js = <<<'JS'
(function(){
	/* Toggle Banana API key visibility based on image provider select */
	var imgSelect = document.getElementById('waipress_image_provider');
	var bananaRow = document.getElementById('waipress-banana-key-row');
	if (imgSelect && bananaRow) {
		imgSelect.addEventListener('change', function(){
			bananaRow.style.display = (this.value === 'banana') ? '' : 'none';
		});
	}

	/* Test Connection button */
	var btn     = document.getElementById('waipress-test-connection');
	var spinner = document.getElementById('waipress-test-spinner');
	var result  = document.getElementById('waipress-test-result');

	if (btn) {
		btn.addEventListener('click', function(){
			btn.disabled = true;
			spinner.classList.add('is-active');
			result.innerHTML = '';

			var data = new FormData();
			data.append('action', 'waipress_test_connection');
			data.append('_ajax_nonce', waipressSettings.nonce);

			fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
				.then(function(r){ return r.json(); })
				.then(function(json){
					spinner.classList.remove('is-active');
					btn.disabled = false;
					if (json.success) {
						result.innerHTML = '<div class="notice notice-success inline"><p>' +
							json.data.message + '</p></div>';
					} else {
						result.innerHTML = '<div class="notice notice-error inline"><p>' +
							(json.data && json.data.message ? json.data.message : 'Unknown error') +
							'</p></div>';
					}
				})
				.catch(function(err){
					spinner.classList.remove('is-active');
					btn.disabled = false;
					result.innerHTML = '<div class="notice notice-error inline"><p>' +
						err.message + '</p></div>';
				});
		});
	}
})();
JS;

		wp_add_inline_script( 'waipress-settings', $inline_js );
	}

	/* ------------------------------------------------------------------ */
	/*  AJAX: Test Connection                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Handle wp_ajax_waipress_test_connection.
	 *
	 * Sends a trivial prompt to WAIpress_AI::generate_content() and returns
	 * success or error.
	 */
	public static function ajax_test_connection() {
		check_ajax_referer( 'waipress_test_connection' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'waipress' ) ) );
		}

		if ( ! class_exists( 'WAIpress_AI' ) ) {
			wp_send_json_error( array( 'message' => __( 'WAIpress AI class is not loaded.', 'waipress' ) ) );
		}

		$test_result = WAIpress_AI::generate_content(
			'Respond with exactly: CONNECTION_OK',
			'You are a connection test. Reply with the exact text the user asks for, nothing else.',
			'',
			64
		);

		if ( is_wp_error( $test_result ) ) {
			wp_send_json_error( array(
				'message' => sprintf(
					__( 'Connection failed: %s', 'waipress' ),
					$test_result->get_error_message()
				),
			) );
		}

		$output = isset( $test_result['output'] ) ? trim( $test_result['output'] ) : '';
		$model  = isset( $test_result['model'] )  ? $test_result['model']  : '?';
		$tokens = isset( $test_result['output_tokens'] ) ? $test_result['output_tokens'] : 0;

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: 1: model name, 2: output text, 3: token count */
				__( 'Connection successful. Model: %1$s | Response: "%2$s" | Tokens used: %3$d', 'waipress' ),
				esc_html( $model ),
				esc_html( mb_substr( $output, 0, 80 ) ),
				$tokens
			),
		) );
	}
}

/* Auto-initialise when loaded. */
WAIpress_Settings::init();
