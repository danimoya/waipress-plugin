<?php
/**
 * WAIpress AI Form Builder
 *
 * Generate forms from a natural-language prompt, render them on the front
 * end (shortcode + block render callback), accept submissions, and route
 * everything into the CRM via WAIpress_Form_Bridge.
 *
 * Storage:
 *   {prefix}wai_forms             — one row per form
 *   {prefix}wai_form_submissions  — one row per submission (linked to a contact)
 *
 * @package WAIpress
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_Forms {

	/**
	 * Allowed field types. Anything not in this list is rejected by the
	 * AI JSON cleaner and the render path.
	 */
	const FIELD_TYPES = array( 'text', 'email', 'tel', 'number', 'textarea', 'select', 'radio', 'checkbox' );

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_shortcode( 'waipress_form', array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menu' ), 20 );
	}

	/**
	 * Register the "AI Forms" submenu under AI Center with a server-rendered
	 * callback — works without needing a dedicated React bundle.
	 */
	public static function register_admin_menu() {
		add_submenu_page(
			'waipress-ai',
			__( 'AI Forms', 'waipress' ),
			__( 'AI Forms', 'waipress' ),
			'edit_posts',
			'waipress-forms',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Server-rendered Forms admin page — list forms, create from prompt,
	 * show the embed shortcode. Uses the REST endpoints for all actions.
	 */
	public static function render_admin_page() {
		global $wpdb;
		$forms    = $wpdb->get_results( "SELECT id, name, slug, status, updated_at FROM {$wpdb->prefix}wai_forms ORDER BY updated_at DESC" );
		$rest_url = rest_url( 'waipress/v1/forms' );
		$nonce    = wp_create_nonce( 'wp_rest' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Forms', 'waipress' ); ?>
				<button type="button" id="waipress-form-new" class="page-title-action">
					<?php esc_html_e( 'Create with AI', 'waipress' ); ?>
				</button>
			</h1>

			<p class="description" style="max-width:720px;">
				<?php esc_html_e( 'Describe the form you need — WAIpress will generate the fields. Embed it with the shortcode shown next to each form, or with the "WAIpress Form" block in the editor.', 'waipress' ); ?>
			</p>

			<div id="waipress-form-builder" style="display:none;background:#fff;padding:16px;border:1px solid #c3c4c7;margin:16px 0;max-width:720px;">
				<label for="waipress-form-prompt"><strong><?php esc_html_e( 'Describe the form', 'waipress' ); ?></strong></label>
				<textarea id="waipress-form-prompt" rows="3" style="width:100%;margin:8px 0;"
				          placeholder="<?php esc_attr_e( 'e.g. Lead form for B2B SaaS — ask name, company size, budget range, expected timeline.', 'waipress' ); ?>"></textarea>
				<div>
					<button type="button" id="waipress-form-generate" class="button button-primary">
						<?php esc_html_e( 'Generate fields', 'waipress' ); ?>
					</button>
					<button type="button" id="waipress-form-cancel" class="button">
						<?php esc_html_e( 'Cancel', 'waipress' ); ?>
					</button>
				</div>
				<div id="waipress-form-preview" style="margin-top:16px;"></div>
				<div id="waipress-form-save" style="display:none;margin-top:16px;">
					<label><strong><?php esc_html_e( 'Form name', 'waipress' ); ?></strong>
						<input type="text" id="waipress-form-name" class="regular-text" />
					</label>
					<button type="button" id="waipress-form-publish" class="button button-primary" style="margin-left:8px;">
						<?php esc_html_e( 'Save & publish', 'waipress' ); ?>
					</button>
				</div>
			</div>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'waipress' ); ?></th>
						<th><?php esc_html_e( 'Status', 'waipress' ); ?></th>
						<th><?php esc_html_e( 'Embed', 'waipress' ); ?></th>
						<th><?php esc_html_e( 'Updated', 'waipress' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $forms ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No forms yet. Click "Create with AI" to build your first one.', 'waipress' ); ?></td></tr>
					<?php else : foreach ( $forms as $f ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $f->name ); ?></strong></td>
							<td><?php echo esc_html( $f->status ); ?></td>
							<td><code>[waipress_form id="<?php echo (int) $f->id; ?>"]</code></td>
							<td><?php echo esc_html( $f->updated_at ); ?></td>
						</tr>
					<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>

		<script>
		(function(){
			const restUrl = <?php echo wp_json_encode( $rest_url ); ?>;
			const nonce   = <?php echo wp_json_encode( $nonce ); ?>;
			const $ = (sel) => document.querySelector(sel);

			$('#waipress-form-new').addEventListener('click', () => {
				$('#waipress-form-builder').style.display = 'block';
				$('#waipress-form-prompt').focus();
			});
			$('#waipress-form-cancel').addEventListener('click', () => {
				$('#waipress-form-builder').style.display = 'none';
				$('#waipress-form-preview').innerHTML = '';
				$('#waipress-form-save').style.display = 'none';
			});

			let generatedFields = null;

			$('#waipress-form-generate').addEventListener('click', async () => {
				const prompt = $('#waipress-form-prompt').value.trim();
				if (!prompt) return;
				$('#waipress-form-preview').textContent = 'Generating…';
				try {
					const res = await fetch(restUrl + '/generate', {
						method: 'POST',
						headers: { 'Content-Type':'application/json', 'X-WP-Nonce': nonce },
						body: JSON.stringify({ prompt })
					});
					const data = await res.json();
					if (!res.ok) throw new Error(data.message || 'Failed');
					generatedFields = data.fields;
					$('#waipress-form-preview').innerHTML = '<pre style="background:#f0f0f1;padding:12px;overflow:auto;max-height:300px;">' +
						JSON.stringify(data.fields, null, 2) + '</pre>';
					$('#waipress-form-save').style.display = 'block';
					$('#waipress-form-name').value = prompt.slice(0, 60);
				} catch (e) {
					$('#waipress-form-preview').textContent = 'Error: ' + e.message;
				}
			});

			$('#waipress-form-publish').addEventListener('click', async () => {
				if (!generatedFields) return;
				const name = $('#waipress-form-name').value.trim() || 'Untitled form';
				try {
					const res = await fetch(restUrl, {
						method: 'POST',
						headers: { 'Content-Type':'application/json', 'X-WP-Nonce': nonce },
						body: JSON.stringify({ name, fields: generatedFields, status: 'published', prompt: $('#waipress-form-prompt').value })
					});
					const data = await res.json();
					if (!res.ok) throw new Error(data.message || 'Failed');
					window.location.reload();
				} catch (e) {
					alert('Error: ' + e.message);
				}
			});
		})();
		</script>
		<?php
	}

	// ==================================================================
	//  Admin REST — CRUD + AI generate
	// ==================================================================

	public static function rest_list( $request ) {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, name, slug, status, created_at, updated_at FROM {$wpdb->prefix}wai_forms ORDER BY updated_at DESC LIMIT 200" );
		return rest_ensure_response( $rows );
	}

	public static function rest_get( $request ) {
		global $wpdb;
		$id  = absint( $request->get_param( 'id' ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wai_forms WHERE id = %d", $id ) );
		if ( ! $row ) {
			return new WP_Error( 'waipress_not_found', 'Form not found.', array( 'status' => 404 ) );
		}
		$row->fields   = json_decode( $row->fields, true );
		$row->settings = $row->settings ? json_decode( $row->settings, true ) : new stdClass();
		return rest_ensure_response( $row );
	}

	public static function rest_create( $request ) {
		global $wpdb;
		$name   = sanitize_text_field( $request->get_param( 'name' ) ?? 'Untitled form' );
		$slug   = self::unique_slug( sanitize_title( $request->get_param( 'slug' ) ?? $name ) );
		$fields = self::sanitize_fields( $request->get_param( 'fields' ) );
		$wpdb->insert( $wpdb->prefix . 'wai_forms', array(
			'name'       => $name,
			'slug'       => $slug,
			'prompt'     => sanitize_textarea_field( (string) $request->get_param( 'prompt' ) ),
			'fields'     => wp_json_encode( $fields ),
			'settings'   => wp_json_encode( (array) $request->get_param( 'settings' ) ),
			'status'     => sanitize_key( $request->get_param( 'status' ) ?? 'draft' ),
			'created_by' => get_current_user_id(),
		), array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' ) );

		return rest_ensure_response( array( 'id' => (int) $wpdb->insert_id, 'slug' => $slug ) );
	}

	public static function rest_update( $request ) {
		global $wpdb;
		$id = absint( $request->get_param( 'id' ) );
		if ( ! $id ) {
			return new WP_Error( 'waipress_invalid_id', 'Missing id.', array( 'status' => 400 ) );
		}

		$patch = array( 'updated_at' => current_time( 'mysql' ) );
		$formats = array( '%s' );

		if ( $request->has_param( 'name' ) ) {
			$patch['name'] = sanitize_text_field( $request->get_param( 'name' ) );
			$formats[] = '%s';
		}
		if ( $request->has_param( 'fields' ) ) {
			$patch['fields'] = wp_json_encode( self::sanitize_fields( $request->get_param( 'fields' ) ) );
			$formats[] = '%s';
		}
		if ( $request->has_param( 'settings' ) ) {
			$patch['settings'] = wp_json_encode( (array) $request->get_param( 'settings' ) );
			$formats[] = '%s';
		}
		if ( $request->has_param( 'status' ) ) {
			$patch['status'] = sanitize_key( $request->get_param( 'status' ) );
			$formats[] = '%s';
		}

		$wpdb->update( $wpdb->prefix . 'wai_forms', $patch, array( 'id' => $id ), $formats, array( '%d' ) );
		return rest_ensure_response( array( 'id' => $id, 'message' => 'Updated.' ) );
	}

	/**
	 * POST /waipress/v1/forms/generate — returns a field array without persisting.
	 */
	public static function rest_generate( $request ) {
		$prompt = sanitize_textarea_field( (string) $request->get_param( 'prompt' ) );
		if ( $prompt === '' ) {
			return new WP_Error( 'waipress_empty_prompt', 'Prompt is required.', array( 'status' => 400 ) );
		}

		$system = 'You are a form designer. Given a short user description, return ONLY a JSON array of form fields. '
			. 'Each field is an object with these keys: '
			. '{ "name": string (snake_case, unique), "label": string, '
			. '"type": one of "text"|"email"|"tel"|"number"|"textarea"|"select"|"radio"|"checkbox", '
			. '"required": boolean, "placeholder": string (optional), '
			. '"options": string[] (required for select/radio/checkbox, omit otherwise) }. '
			. 'Always include an email field if contact is implied. '
			. 'Return ONLY valid JSON — no prose, no markdown fences, no comments.';

		$result = WAIpress_AI::generate_content( $prompt, $system );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$raw_output = (string) ( $result['output'] ?? '' );
		$json       = self::extract_json_array( $raw_output );
		$fields     = self::sanitize_fields( $json );

		if ( empty( $fields ) ) {
			return new WP_Error( 'waipress_bad_ai_output', 'The model did not return a valid field array.', array( 'status' => 502, 'raw' => $raw_output ) );
		}

		return rest_ensure_response( array( 'fields' => $fields ) );
	}

	// ==================================================================
	//  Public REST — submission
	// ==================================================================

	/**
	 * POST /waipress/v1/forms/{id}/submit
	 */
	public static function rest_submit( $request ) {
		global $wpdb;

		$id = absint( $request->get_param( 'id' ) );
		if ( ! $id ) {
			return new WP_Error( 'waipress_invalid_id', 'Missing form id.', array( 'status' => 400 ) );
		}

		// Rate-limit by IP (1 submission / 10 seconds per IP per form).
		$ip = self::client_ip();
		if ( $ip ) {
			$key = 'waipress_form_submit_' . md5( $id . '|' . $ip );
			if ( get_transient( $key ) ) {
				return new WP_Error( 'waipress_rate_limited', 'Please wait a moment before submitting again.', array( 'status' => 429 ) );
			}
			set_transient( $key, 1, 10 );
		}

		$form = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wai_forms WHERE id = %d AND status = 'published'",
			$id
		) );
		if ( ! $form ) {
			return new WP_Error( 'waipress_form_not_found', 'Form not found or not published.', array( 'status' => 404 ) );
		}

		$fields = json_decode( $form->fields, true );
		if ( ! is_array( $fields ) ) {
			return new WP_Error( 'waipress_broken_form', 'Form definition is invalid.', array( 'status' => 500 ) );
		}

		// Honeypot — any non-empty _wai_hp value is a bot.
		if ( ! empty( $request->get_param( '_wai_hp' ) ) ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}

		// Collect, validate, sanitize submitted values.
		$values = array();
		$missing = array();
		foreach ( $fields as $field ) {
			$name = $field['name'] ?? '';
			if ( ! $name ) {
				continue;
			}
			$raw  = $request->get_param( $name );
			$clean = self::sanitize_value( $raw, $field['type'] ?? 'text' );

			if ( ! empty( $field['required'] ) && ( $clean === '' || $clean === null || $clean === array() ) ) {
				$missing[] = $name;
			}
			$values[ $name ] = $clean;
		}

		if ( $missing ) {
			return new WP_Error( 'waipress_missing_fields', 'Required fields missing.', array( 'status' => 422, 'fields' => $missing ) );
		}

		// Extract CRM-bound fields.
		$email = '';
		$name  = '';
		$phone = '';
		foreach ( $fields as $field ) {
			$fname = $field['name'] ?? '';
			$type  = $field['type'] ?? '';
			if ( ! $fname ) {
				continue;
			}
			if ( $type === 'email' && ! $email ) {
				$email = (string) $values[ $fname ];
			} elseif ( $type === 'tel' && ! $phone ) {
				$phone = (string) $values[ $fname ];
			} elseif ( ! $name && preg_match( '/name/i', $fname ) ) {
				$name = (string) $values[ $fname ];
			}
		}

		$contact_id = 0;
		if ( class_exists( 'WAIpress_Form_Bridge' ) && ( $email !== '' || $phone !== '' ) ) {
			$contact_id = WAIpress_Form_Bridge::ingest( array(
				'email'   => $email,
				'name'    => $name,
				'phone'   => $phone,
				'source'  => 'waipress_form',
				'fields'  => $values,
				'title'   => 'WAIpress form: ' . $form->name,
			) );
		}

		$wpdb->insert(
			$wpdb->prefix . 'wai_form_submissions',
			array(
				'form_id'    => $id,
				'contact_id' => $contact_id ?: null,
				'data'       => wp_json_encode( $values ),
				'ip'         => $ip,
				'user_agent' => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
		$submission_id = (int) $wpdb->insert_id;

		do_action( 'waipress_form_submitted', $contact_id, array(
			'form_id'       => $id,
			'submission_id' => $submission_id,
			'values'        => $values,
			'source'        => 'waipress_form',
		) );

		$settings = json_decode( (string) $form->settings, true ) ?: array();
		$message  = $settings['success_message'] ?? __( 'Thanks! Your submission was received.', 'waipress' );

		return rest_ensure_response( array(
			'ok'            => true,
			'message'       => $message,
			'submission_id' => $submission_id,
		) );
	}

	// ==================================================================
	//  Rendering (shortcode + block)
	// ==================================================================

	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0, 'slug' => '' ), $atts, 'waipress_form' );
		return self::render( (int) $atts['id'], (string) $atts['slug'] );
	}

	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type( 'waipress/form', array(
			'attributes'      => array(
				'id'   => array( 'type' => 'integer', 'default' => 0 ),
				'slug' => array( 'type' => 'string',  'default' => '' ),
			),
			'render_callback' => array( __CLASS__, 'render_block_callback' ),
		) );
	}

	public static function render_block_callback( $attributes ) {
		return self::render( (int) ( $attributes['id'] ?? 0 ), (string) ( $attributes['slug'] ?? '' ) );
	}

	/**
	 * Render a form by id or slug. Returns HTML string.
	 */
	public static function render( $id, $slug = '' ) {
		global $wpdb;

		if ( ! $id && $slug ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wai_forms WHERE slug = %s", $slug ) );
		}
		if ( ! $id ) {
			return '';
		}

		$form = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wai_forms WHERE id = %d", $id ) );
		if ( ! $form || $form->status !== 'published' ) {
			return current_user_can( 'edit_posts' )
				? '<!-- waipress form ' . (int) $id . ' is not published -->'
				: '';
		}

		$fields = json_decode( $form->fields, true );
		if ( ! is_array( $fields ) ) {
			return '';
		}

		ob_start();
		?>
		<form class="waipress-form" data-form-id="<?php echo esc_attr( $id ); ?>" novalidate
		      style="display:flex;flex-direction:column;gap:12px;max-width:560px;">
			<?php wp_nonce_field( 'wp_rest', '_wpnonce' ); ?>
			<input type="text" name="_wai_hp" tabindex="-1" autocomplete="off"
			       style="position:absolute;left:-9999px;" aria-hidden="true" />
			<?php foreach ( $fields as $field ) : ?>
				<?php echo self::render_field( $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endforeach; ?>
			<button type="submit" style="align-self:flex-start;padding:10px 18px;background:#2271b1;color:#fff;border:none;border-radius:4px;cursor:pointer;">
				<?php esc_html_e( 'Submit', 'waipress' ); ?>
			</button>
			<div class="waipress-form-status" role="status" aria-live="polite" style="font-size:14px;"></div>
		</form>
		<script>
		(function(){
			const form = document.currentScript.previousElementSibling;
			if (!form || !form.matches('.waipress-form')) return;
			const status = form.querySelector('.waipress-form-status');
			const formId = form.dataset.formId;
			form.addEventListener('submit', async (e) => {
				e.preventDefault();
				status.textContent = '';
				const data = {};
				new FormData(form).forEach((v, k) => {
					if (data[k] !== undefined) {
						data[k] = [].concat(data[k], v);
					} else {
						data[k] = v;
					}
				});
				try {
					const res = await fetch(<?php echo wp_json_encode( rest_url( 'waipress/v1/forms/' ) ); ?> + formId + '/submit', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify(data)
					});
					const json = await res.json();
					if (!res.ok) {
						status.textContent = json.message || 'There was a problem submitting the form.';
						status.style.color = '#b32d2e';
						return;
					}
					form.reset();
					status.textContent = json.message || 'Thanks!';
					status.style.color = '#00a32a';
				} catch (err) {
					status.textContent = 'Network error.';
					status.style.color = '#b32d2e';
				}
			});
		})();
		</script>
		<?php
		return ob_get_clean();
	}

	private static function render_field( $field ) {
		$type    = in_array( ( $field['type'] ?? '' ), self::FIELD_TYPES, true ) ? $field['type'] : 'text';
		$name    = sanitize_key( $field['name'] ?? '' );
		$label   = (string) ( $field['label'] ?? '' );
		$placeh  = (string) ( $field['placeholder'] ?? '' );
		$req     = ! empty( $field['required'] );
		$opts    = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
		if ( ! $name ) {
			return '';
		}

		$req_attr = $req ? ' required' : '';
		$label_html = $label ? '<label for="wai_f_' . esc_attr( $name ) . '" style="font-weight:500;font-size:14px;">' . esc_html( $label ) . ( $req ? ' *' : '' ) . '</label>' : '';
		$input_style = 'padding:8px 12px;border:1px solid #c3c4c7;border-radius:4px;font-size:14px;font-family:inherit;';

		switch ( $type ) {
			case 'textarea':
				$input = '<textarea id="wai_f_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" placeholder="' . esc_attr( $placeh ) . '" rows="4" style="' . esc_attr( $input_style ) . '"' . $req_attr . '></textarea>';
				break;

			case 'select':
				$input = '<select id="wai_f_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" style="' . esc_attr( $input_style ) . '"' . $req_attr . '>';
				$input .= '<option value="">' . esc_html__( 'Choose…', 'waipress' ) . '</option>';
				foreach ( $opts as $opt ) {
					$input .= '<option value="' . esc_attr( $opt ) . '">' . esc_html( $opt ) . '</option>';
				}
				$input .= '</select>';
				break;

			case 'radio':
			case 'checkbox':
				$input = '<div style="display:flex;flex-direction:column;gap:6px;">';
				foreach ( $opts as $opt ) {
					$input .= '<label style="font-weight:normal;display:flex;gap:8px;align-items:center;font-size:14px;">';
					$input .= '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . ( $type === 'checkbox' ? '[]' : '' ) . '" value="' . esc_attr( $opt ) . '"' . ( $req && $type === 'radio' ? ' required' : '' ) . ' />';
					$input .= esc_html( $opt ) . '</label>';
				}
				$input .= '</div>';
				break;

			default: // text, email, tel, number
				$input = '<input id="wai_f_' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" placeholder="' . esc_attr( $placeh ) . '" style="' . esc_attr( $input_style ) . '"' . $req_attr . ' />';
		}

		return '<div style="display:flex;flex-direction:column;gap:4px;">' . $label_html . $input . '</div>';
	}

	// ==================================================================
	//  Sanitization helpers
	// ==================================================================

	/**
	 * Normalize + sanitize an input array into a safe form definition.
	 */
	public static function sanitize_fields( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$clean = array();
		$seen_names = array();

		foreach ( $raw as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$type = in_array( ( $field['type'] ?? '' ), self::FIELD_TYPES, true ) ? $field['type'] : 'text';
			$name = sanitize_key( (string) ( $field['name'] ?? '' ) );
			if ( ! $name || isset( $seen_names[ $name ] ) ) {
				continue;
			}
			$seen_names[ $name ] = true;

			$obj = array(
				'name'     => $name,
				'label'    => sanitize_text_field( (string) ( $field['label'] ?? $name ) ),
				'type'     => $type,
				'required' => ! empty( $field['required'] ),
			);
			if ( ! empty( $field['placeholder'] ) ) {
				$obj['placeholder'] = sanitize_text_field( (string) $field['placeholder'] );
			}
			if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) {
				$opts = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
				$obj['options'] = array_values( array_filter( array_map( function ( $o ) {
					return sanitize_text_field( (string) $o );
				}, $opts ) ) );
			}
			$clean[] = $obj;
		}
		return $clean;
	}

	private static function sanitize_value( $value, $type ) {
		if ( is_array( $value ) ) {
			return array_map( function ( $v ) use ( $type ) {
				return self::sanitize_value( $v, $type );
			}, $value );
		}
		$value = (string) $value;
		switch ( $type ) {
			case 'email':
				return sanitize_email( $value );
			case 'number':
				return is_numeric( $value ) ? $value : '';
			case 'tel':
				return preg_replace( '/[^0-9+\-\s()]/', '', $value );
			case 'textarea':
				return sanitize_textarea_field( $value );
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Best-effort extractor: pull the first JSON array out of a potentially-chatty AI response.
	 */
	private static function extract_json_array( $text ) {
		$text = trim( $text );
		// Strip common markdown fences.
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );

		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		// Last-ditch: grab the substring between the first '[' and last ']'.
		$start = strpos( $text, '[' );
		$end   = strrpos( $text, ']' );
		if ( $start !== false && $end !== false && $end > $start ) {
			$slice = substr( $text, $start, $end - $start + 1 );
			$decoded = json_decode( $slice, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
	}

	private static function unique_slug( $slug ) {
		global $wpdb;
		if ( $slug === '' ) {
			$slug = 'form-' . wp_generate_uuid4();
		}
		$base = $slug;
		$n    = 2;
		while ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wai_forms WHERE slug = %s", $slug ) ) > 0 ) {
			$slug = $base . '-' . $n++;
		}
		return $slug;
	}

	private static function client_ip() {
		$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
		foreach ( $candidates as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				$ip = trim( explode( ',', (string) $_SERVER[ $k ] )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '';
	}
}
