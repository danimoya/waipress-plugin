<?php
/**
 * WAIpress ↔ WooCommerce integration.
 *
 * Adds an "AI tools" meta-box on the product edit screen with one-click
 * generation for title, short description, long description, SEO, and tags.
 * Exposes a REST endpoint that the chatbot and bulk-action handlers reuse.
 *
 * Gracefully no-ops if WooCommerce is not active.
 *
 * @package WAIpress
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_WooCommerce {

	/**
	 * Hook into WordPress / WooCommerce.
	 */
	public static function init() {
		if ( ! self::is_active() ) {
			return;
		}

		add_action( 'add_meta_boxes_product', array( __CLASS__, 'register_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function is_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Product-edit screen meta box.
	 */
	public static function register_meta_box() {
		add_meta_box(
			'waipress_wc_ai',
			__( 'WAIpress — AI product tools', 'waipress' ),
			array( __CLASS__, 'render_meta_box' ),
			'product',
			'side',
			'default'
		);
	}

	public static function render_meta_box( $post ) {
		$fields = array(
			'title'      => __( 'Generate title', 'waipress' ),
			'short_desc' => __( 'Generate short description', 'waipress' ),
			'long_desc'  => __( 'Generate long description', 'waipress' ),
			'seo'        => __( 'Generate SEO title + meta', 'waipress' ),
			'tags'       => __( 'Suggest product tags', 'waipress' ),
		);
		?>
		<div class="waipress-wc-ai" data-product-id="<?php echo esc_attr( $post->ID ); ?>">
			<p class="description">
				<?php esc_html_e( 'AI-generate product copy using the current product\'s attributes (name, categories, existing description).', 'waipress' ); ?>
			</p>
			<?php foreach ( $fields as $field => $label ) : ?>
				<p>
					<button type="button" class="button waipress-wc-ai-btn" data-field="<?php echo esc_attr( $field ); ?>">
						<?php echo esc_html( $label ); ?>
					</button>
				</p>
			<?php endforeach; ?>
			<p class="waipress-wc-ai-status" style="color:#666;font-style:italic;"></p>
		</div>
		<script>
		(function(){
			const wrap = document.querySelector('.waipress-wc-ai');
			if (!wrap) return;
			const status  = wrap.querySelector('.waipress-wc-ai-status');
			const productId = wrap.dataset.productId;
			const nonce   = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			const restUrl = <?php echo wp_json_encode( rest_url( 'waipress/v1/ai/products/generate' ) ); ?>;

			wrap.querySelectorAll('.waipress-wc-ai-btn').forEach(btn => {
				btn.addEventListener('click', async () => {
					const field = btn.dataset.field;
					status.textContent = 'Generating ' + field + '…';
					btn.disabled = true;
					try {
						const res = await fetch(restUrl, {
							method: 'POST',
							headers: { 'Content-Type':'application/json', 'X-WP-Nonce': nonce },
							body: JSON.stringify({ product_id: productId, fields: [field] })
						});
						const data = await res.json();
						if (!res.ok) throw new Error(data.message || 'Request failed');
						status.textContent = 'Saved. Refresh to see changes.';
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

	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_script( 'wp-api-fetch' );
	}

	// ==================================================================
	//  REST handler — POST /waipress/v1/ai/products/generate
	//  Body: { product_id:int, fields:string[] }
	// ==================================================================

	public static function rest_generate( $request ) {
		if ( ! self::is_active() ) {
			return new WP_Error( 'waipress_no_wc', __( 'WooCommerce is not active.', 'waipress' ), array( 'status' => 400 ) );
		}

		$product_id = absint( $request->get_param( 'product_id' ) );
		$fields     = $request->get_param( 'fields' );

		if ( ! $product_id || ! is_array( $fields ) || empty( $fields ) ) {
			return new WP_Error( 'waipress_invalid_args', __( 'product_id and fields are required.', 'waipress' ), array( 'status' => 400 ) );
		}

		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			return new WP_Error( 'waipress_forbidden', __( 'You cannot edit this product.', 'waipress' ), array( 'status' => 403 ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'waipress_no_product', __( 'Product not found.', 'waipress' ), array( 'status' => 404 ) );
		}

		$context = self::build_context( $product );
		$results = array();

		foreach ( $fields as $raw_field ) {
			$field = sanitize_key( $raw_field );
			$value = self::generate_field( $product, $field, $context );
			if ( is_wp_error( $value ) ) {
				$results[ $field ] = array( 'error' => $value->get_error_message() );
				continue;
			}
			self::persist( $product, $field, $value );
			$results[ $field ] = array( 'value' => $value );
		}

		return rest_ensure_response( array(
			'product_id' => $product_id,
			'results'    => $results,
		) );
	}

	/**
	 * Build a structured context snippet from existing product data.
	 */
	private static function build_context( $product ) {
		$cats = wp_list_pluck( get_the_terms( $product->get_id(), 'product_cat' ) ?: array(), 'name' );
		$tags = wp_list_pluck( get_the_terms( $product->get_id(), 'product_tag' ) ?: array(), 'name' );

		return sprintf(
			"Product name: %s\nPrice: %s %s\nCategories: %s\nTags: %s\nExisting short description: %s\nExisting long description: %s",
			$product->get_name(),
			$product->get_price(),
			get_woocommerce_currency(),
			implode( ', ', $cats ),
			implode( ', ', $tags ),
			wp_strip_all_tags( $product->get_short_description() ),
			mb_substr( wp_strip_all_tags( $product->get_description() ), 0, 2000 )
		);
	}

	/**
	 * Generate a specific field. Returns the string value or WP_Error.
	 */
	private static function generate_field( $product, $field, $context ) {
		$prompts = array(
			'title'      => array(
				'user'   => $context,
				'system' => 'You are an e-commerce copywriter. Return ONLY a product title: concise, descriptive, benefit-led, under 70 characters, no quotes, no trailing punctuation.',
			),
			'short_desc' => array(
				'user'   => $context,
				'system' => 'You are an e-commerce copywriter. Return ONLY a short product description: 1–2 sentences (max 160 characters), plain text, no quotes, no markdown, no bullet list.',
			),
			'long_desc'  => array(
				'user'   => $context,
				'system' => 'You are an e-commerce copywriter. Return a full product description in HTML: an opening paragraph, a bulleted list of 3–5 benefits, and a closing call-to-action paragraph. Use only <p>, <ul>, <li>, <strong> tags.',
			),
			'seo'        => array(
				'user'   => $context,
				'system' => 'You are an SEO copywriter. Return ONLY a JSON object with keys "seo_title" (max 60 chars) and "seo_description" (max 155 chars). No prose, no markdown fences.',
			),
			'tags'       => array(
				'user'   => $context,
				'system' => 'You are an e-commerce taxonomist. Return ONLY a comma-separated list of 5–8 product tags. Lowercase, short, specific. No numbering, no quotes, no prose.',
			),
		);

		if ( ! isset( $prompts[ $field ] ) ) {
			return new WP_Error( 'waipress_unknown_field', sprintf( 'Unknown field: %s', $field ) );
		}

		$result = WAIpress_AI::generate_content( $prompts[ $field ]['user'], $prompts[ $field ]['system'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$value = trim( (string) ( $result['output'] ?? '' ) );
		if ( $value === '' ) {
			return new WP_Error( 'waipress_empty', 'Model returned empty output.' );
		}
		return $value;
	}

	/**
	 * Persist the generated value back onto the product.
	 */
	private static function persist( $product, $field, $value ) {
		switch ( $field ) {
			case 'title':
				$clean = trim( $value, " \t\n\r\0\x0B\"'" );
				wp_update_post( array( 'ID' => $product->get_id(), 'post_title' => $clean ) );
				break;

			case 'short_desc':
				$clean = trim( $value, " \t\n\r\0\x0B\"'" );
				$product->set_short_description( $clean );
				$product->save();
				break;

			case 'long_desc':
				$product->set_description( wp_kses_post( $value ) );
				$product->save();
				break;

			case 'seo':
				$decoded = json_decode( $value, true );
				if ( is_array( $decoded ) ) {
					$title = $decoded['seo_title']       ?? '';
					$desc  = $decoded['seo_description'] ?? '';
					if ( class_exists( 'WAIpress_Yoast' ) && WAIpress_Yoast::any_seo_plugin_active() ) {
						$title_key = WAIpress_Yoast::meta_key_for( 'title' );
						$desc_key  = WAIpress_Yoast::meta_key_for( 'description' );
						if ( $title_key && $title !== '' ) {
							update_post_meta( $product->get_id(), $title_key, $title );
						}
						if ( $desc_key && $desc !== '' ) {
							update_post_meta( $product->get_id(), $desc_key, $desc );
						}
					}
				}
				break;

			case 'tags':
				$tags = array_filter( array_map( 'trim', explode( ',', $value ) ) );
				if ( ! empty( $tags ) ) {
					wp_set_object_terms( $product->get_id(), $tags, 'product_tag', false );
				}
				break;
		}
	}
}
