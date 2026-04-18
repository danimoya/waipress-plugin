<?php
/**
 * WAIpress Yoast SEO / Rank Math Integration
 *
 * Adds an "AI rewrite" meta-box and REST endpoint that writes directly into
 * the active SEO plugin's meta keys. Gracefully no-ops when neither Yoast
 * nor Rank Math is installed.
 *
 * @package WAIpress
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_Yoast {

	const META_YOAST_TITLE          = '_yoast_wpseo_title';
	const META_YOAST_METADESC       = '_yoast_wpseo_metadesc';
	const META_YOAST_FOCUSKW        = '_yoast_wpseo_focuskw';
	const META_RANKMATH_TITLE       = 'rank_math_title';
	const META_RANKMATH_DESCRIPTION = 'rank_math_description';
	const META_RANKMATH_FOCUSKW     = 'rank_math_focus_keyword';

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		if ( ! self::any_seo_plugin_active() ) {
			return;
		}

		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Detect whether Yoast SEO is active.
	 */
	public static function is_yoast_active() {
		return defined( 'WPSEO_VERSION' );
	}

	/**
	 * Detect whether Rank Math is active.
	 */
	public static function is_rankmath_active() {
		return class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' );
	}

	/**
	 * Is any supported SEO plugin active?
	 */
	public static function any_seo_plugin_active() {
		return self::is_yoast_active() || self::is_rankmath_active();
	}

	/**
	 * Which SEO plugin to write to. Prefers Yoast if both are installed.
	 *
	 * @return string 'yoast' | 'rankmath' | ''
	 */
	public static function active_seo_plugin() {
		if ( self::is_yoast_active() ) {
			return 'yoast';
		}
		if ( self::is_rankmath_active() ) {
			return 'rankmath';
		}
		return '';
	}

	/**
	 * Map a logical field to the active SEO plugin's meta key.
	 *
	 * @param string $field 'title' | 'description' | 'focus_keyword'
	 * @return string       meta key, or '' if unknown field / no SEO plugin
	 */
	public static function meta_key_for( $field ) {
		$plugin = self::active_seo_plugin();
		$map    = array(
			'yoast'    => array(
				'title'         => self::META_YOAST_TITLE,
				'description'   => self::META_YOAST_METADESC,
				'focus_keyword' => self::META_YOAST_FOCUSKW,
			),
			'rankmath' => array(
				'title'         => self::META_RANKMATH_TITLE,
				'description'   => self::META_RANKMATH_DESCRIPTION,
				'focus_keyword' => self::META_RANKMATH_FOCUSKW,
			),
		);
		return $map[ $plugin ][ $field ] ?? '';
	}

	/**
	 * Register a WAIpress meta box on public post types.
	 */
	public static function register_meta_box() {
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $post_types as $pt ) {
			add_meta_box(
				'waipress_seo_ai',
				__( 'WAIpress — Rewrite meta with AI', 'waipress' ),
				array( __CLASS__, 'render_meta_box' ),
				$pt,
				'side',
				'default'
			);
		}
	}

	/**
	 * Meta box markup (lightweight — uses admin-ajax style fetch to REST).
	 */
	public static function render_meta_box( $post ) {
		$plugin = self::active_seo_plugin();
		$label  = 'yoast' === $plugin ? 'Yoast SEO' : 'Rank Math';
		?>
		<div class="waipress-seo-ai" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
			<p class="description">
				<?php
				printf(
					/* translators: %s: SEO plugin name */
					esc_html__( 'Use AI to rewrite %s meta fields. The generated value is written directly into the active SEO plugin\'s meta.', 'waipress' ),
					esc_html( $label )
				);
				?>
			</p>
			<p>
				<button type="button" class="button waipress-seo-ai-btn" data-field="title">
					<?php esc_html_e( 'Rewrite SEO title', 'waipress' ); ?>
				</button>
			</p>
			<p>
				<button type="button" class="button waipress-seo-ai-btn" data-field="description">
					<?php esc_html_e( 'Rewrite meta description', 'waipress' ); ?>
				</button>
			</p>
			<p>
				<button type="button" class="button waipress-seo-ai-btn" data-field="focus_keyword">
					<?php esc_html_e( 'Suggest focus keyword', 'waipress' ); ?>
				</button>
			</p>
			<p class="waipress-seo-ai-status" style="color:#666;font-style:italic;"></p>
		</div>
		<script>
		(function(){
			const wrap = document.querySelector('.waipress-seo-ai');
			if (!wrap) return;
			const status = wrap.querySelector('.waipress-seo-ai-status');
			const postId = wrap.dataset.postId;
			const nonce  = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			const restUrl = <?php echo wp_json_encode( rest_url( 'waipress/v1/ai/rewrite-meta' ) ); ?>;
			wrap.querySelectorAll('.waipress-seo-ai-btn').forEach(btn => {
				btn.addEventListener('click', async () => {
					const field = btn.dataset.field;
					status.textContent = 'Generating…';
					btn.disabled = true;
					try {
						const res = await fetch(restUrl, {
							method: 'POST',
							headers: { 'Content-Type':'application/json', 'X-WP-Nonce': nonce },
							body: JSON.stringify({ post_id: postId, field: field })
						});
						const data = await res.json();
						if (!res.ok) throw new Error(data.message || 'Request failed');
						status.textContent = field + ' updated: ' + (data.value || '').slice(0, 120);
					} catch (e) {
						status.textContent = 'Error: ' + e.message;
					} finally {
						btn.disabled = false;
					}
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Ensures wp-api nonces are available on the edit screen
	 * (post.php / post-new.php already enqueue them).
	 */
	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_script( 'wp-api-fetch' );
	}

	// ==================================================================
	//  REST handler
	// ==================================================================

	/**
	 * POST /waipress/v1/ai/rewrite-meta
	 *
	 * Body:
	 *   - post_id (int)
	 *   - field   ('title' | 'description' | 'focus_keyword' | 'slug')
	 *
	 * Writes the generated value directly into the active SEO plugin's meta key
	 * (except 'slug', which updates the post itself).
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_rewrite_meta( $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$field   = sanitize_key( $request->get_param( 'field' ) ?? '' );

		if ( ! $post_id || ! in_array( $field, array( 'title', 'description', 'focus_keyword', 'slug' ), true ) ) {
			return new WP_Error( 'waipress_invalid_args', __( 'post_id and a valid field are required.', 'waipress' ), array( 'status' => 400 ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'waipress_no_post', __( 'Post not found.', 'waipress' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'waipress_forbidden', __( 'You cannot edit this post.', 'waipress' ), array( 'status' => 403 ) );
		}

		$title   = $post->post_title;
		$excerpt = $post->post_excerpt;
		$content = wp_strip_all_tags( $post->post_content );
		$content = mb_substr( $content, 0, 4000 );

		list( $prompt, $system ) = self::prompt_for( $field, $title, $excerpt, $content );

		$result = WAIpress_AI::generate_content( $prompt, $system );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$value = trim( (string) ( $result['output'] ?? '' ) );
		$value = self::clean_value( $value, $field );

		if ( $value === '' ) {
			return new WP_Error( 'waipress_empty', __( 'The model returned an empty value.', 'waipress' ), array( 'status' => 502 ) );
		}

		$written_to = self::write_value( $post_id, $field, $value );

		return rest_ensure_response( array(
			'post_id'   => $post_id,
			'field'     => $field,
			'value'     => $value,
			'target'    => $written_to,
		) );
	}

	/**
	 * Field-specific prompt + system-prompt pair.
	 *
	 * @return array{0:string,1:string}  [ prompt, system ]
	 */
	private static function prompt_for( $field, $title, $excerpt, $content ) {
		$base = "Title: {$title}\n\nExcerpt: {$excerpt}\n\nContent:\n{$content}";

		switch ( $field ) {
			case 'title':
				return array(
					$base,
					'You are an SEO copywriter. Return ONLY a concise SEO title for this page, under 60 characters, no quotes, no trailing punctuation.',
				);
			case 'description':
				return array(
					$base,
					'You are an SEO copywriter. Return ONLY a meta description under 155 characters. Plain text, no quotes, no markdown. Must include the primary topic and be compelling for search click-through.',
				);
			case 'focus_keyword':
				return array(
					$base,
					'You are an SEO strategist. Return ONLY a single focus keyword or short keyphrase (2–4 words) that best represents this page. Plain text, lowercase, no quotes, no punctuation.',
				);
			case 'slug':
				return array(
					$base,
					'Return ONLY a URL slug for this page: lowercase, hyphens only, max 5 words, no stop words, no punctuation.',
				);
		}

		return array( $base, 'Return a concise plain-text value for the requested field.' );
	}

	/**
	 * Trim quotes, strip newlines, enforce per-field length caps.
	 */
	private static function clean_value( $value, $field ) {
		$value = trim( $value, " \t\n\r\0\x0B\"'" );
		$value = preg_replace( '/\s+/', ' ', $value );

		$caps = array(
			'title'         => 70,
			'description'   => 160,
			'focus_keyword' => 80,
			'slug'          => 80,
		);
		$cap = $caps[ $field ] ?? 160;

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $value ) > $cap ) {
			$value = rtrim( mb_substr( $value, 0, $cap ) );
		}

		if ( $field === 'slug' ) {
			$value = sanitize_title( $value );
		}

		return $value;
	}

	/**
	 * Persist the generated value into the correct meta key (or post slug).
	 *
	 * @return string  Identifier of where we wrote — for UI feedback.
	 */
	private static function write_value( $post_id, $field, $value ) {
		if ( 'slug' === $field ) {
			wp_update_post( array(
				'ID'        => $post_id,
				'post_name' => $value,
			) );
			return 'post_name';
		}

		$meta_key = self::meta_key_for( $field );
		if ( $meta_key === '' ) {
			return 'noop_no_seo_plugin';
		}
		update_post_meta( $post_id, $meta_key, $value );
		return $meta_key;
	}
}
