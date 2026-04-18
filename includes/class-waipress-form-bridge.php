<?php
/**
 * WAIpress Form Bridge
 *
 * Captures submissions from popular third-party form plugins (WPForms,
 * Gravity Forms, Contact Form 7, Forminator) and funnels them into the
 * WAIpress CRM as contacts + timeline activities.
 *
 * Each third-party integration is wired behind a class_exists / function_exists
 * check so the code is a safe no-op when those plugins aren't installed.
 *
 * Also fires `waipress_form_submitted` — used by the Phase 4 email automation
 * layer to run triggered workflows.
 *
 * @package WAIpress
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_Form_Bridge {

	/**
	 * Hook into supported form plugins.
	 */
	public static function init() {
		// WPForms
		add_action( 'wpforms_process_complete', array( __CLASS__, 'ingest_wpforms' ), 10, 4 );

		// Gravity Forms
		add_action( 'gform_after_submission', array( __CLASS__, 'ingest_gravity' ), 10, 2 );

		// Contact Form 7
		add_action( 'wpcf7_mail_sent', array( __CLASS__, 'ingest_cf7' ) );

		// Forminator
		add_action( 'forminator_custom_form_after_save_entry', array( __CLASS__, 'ingest_forminator' ), 10, 3 );
	}

	// ==================================================================
	//  Public ingest API — reused by the built-in AI Form builder too.
	// ==================================================================

	/**
	 * Upsert a contact and log a form_submission activity.
	 *
	 * Returns the contact_id (zero if we couldn't identify the submitter).
	 *
	 * @param array $payload {
	 *     @type string $email    Contact email (preferred identifier).
	 *     @type string $name     Contact display name.
	 *     @type string $phone    Optional phone number.
	 *     @type string $company  Optional company.
	 *     @type string $source   Source label (wpforms, gravityforms, cf7, etc.).
	 *     @type array  $fields   Full raw submission payload for timeline metadata.
	 *     @type string $title    Short activity title (e.g. "Contact form").
	 * }
	 * @return int
	 */
	public static function ingest( array $payload ): int {
		global $wpdb;

		$email   = isset( $payload['email'] ) ? sanitize_email( $payload['email'] ) : '';
		$name    = isset( $payload['name'] ) ? sanitize_text_field( $payload['name'] ) : '';
		$phone   = isset( $payload['phone'] ) ? sanitize_text_field( $payload['phone'] ) : '';
		$company = isset( $payload['company'] ) ? sanitize_text_field( $payload['company'] ) : '';
		$source  = isset( $payload['source'] ) ? sanitize_key( $payload['source'] ) : 'form';
		$fields  = isset( $payload['fields'] ) && is_array( $payload['fields'] ) ? $payload['fields'] : array();
		$title   = isset( $payload['title'] ) ? sanitize_text_field( $payload['title'] ) : __( 'Form submission', 'waipress' );

		if ( empty( $email ) && empty( $phone ) ) {
			// Can't identify the contact; record an anonymous activity only.
			return 0;
		}

		$contact_id = self::upsert_contact( $email, $name, $phone, $company, $source );

		if ( $contact_id > 0 ) {
			self::log_activity( $contact_id, $title, $fields, $source );
		}

		/**
		 * Action: a form submission has been bridged into the CRM.
		 *
		 * The Phase 4 automation engine listens for this to fire
		 * `form_submitted` triggers.
		 *
		 * @param int    $contact_id
		 * @param array  $payload
		 */
		do_action( 'waipress_form_submitted', $contact_id, $payload );

		return $contact_id;
	}

	// ==================================================================
	//  Per-plugin adapters
	// ==================================================================

	/**
	 * WPForms — `wpforms_process_complete`.
	 *
	 * @param array $fields      Sanitized field values keyed by field id.
	 * @param array $entry       Raw entry.
	 * @param array $form_data   Form definition.
	 * @param int   $entry_id    Entry id (may be false on no-entry forms).
	 */
	public static function ingest_wpforms( $fields, $entry, $form_data, $entry_id ) {
		$values = array();
		$email  = $name = $phone = $company = '';

		if ( is_array( $fields ) ) {
			foreach ( $fields as $field ) {
				$type  = isset( $field['type'] ) ? (string) $field['type'] : '';
				$name_ = isset( $field['name'] ) ? (string) $field['name'] : '';
				$val   = isset( $field['value'] ) ? (string) $field['value'] : '';
				$values[ $name_ ?: ( 'field_' . ( $field['id'] ?? '' ) ) ] = $val;

				if ( $type === 'email' && ! $email ) {
					$email = $val;
				} elseif ( $type === 'phone' && ! $phone ) {
					$phone = $val;
				} elseif ( $type === 'name' && ! $name ) {
					$name = $val;
				} elseif ( stripos( $name_, 'company' ) !== false && ! $company ) {
					$company = $val;
				}
			}
		}

		self::ingest( array(
			'email'   => $email,
			'name'    => $name,
			'phone'   => $phone,
			'company' => $company,
			'source'  => 'wpforms',
			'fields'  => $values,
			'title'   => isset( $form_data['settings']['form_title'] )
				? 'WPForms: ' . $form_data['settings']['form_title']
				: 'WPForms submission',
		) );
	}

	/**
	 * Gravity Forms — `gform_after_submission`.
	 */
	public static function ingest_gravity( $entry, $form ) {
		$values = array();
		$email  = $name = $phone = $company = '';

		if ( is_array( $form ) && ! empty( $form['fields'] ) ) {
			foreach ( $form['fields'] as $field ) {
				$fid   = isset( $field->id ) ? (string) $field->id : '';
				$type  = isset( $field->type ) ? (string) $field->type : '';
				$label = isset( $field->label ) ? sanitize_title( $field->label ) : ( 'field_' . $fid );
				$val   = isset( $entry[ $fid ] ) ? (string) $entry[ $fid ] : '';
				$values[ $label ] = $val;

				if ( $type === 'email' && ! $email ) {
					$email = $val;
				} elseif ( $type === 'phone' && ! $phone ) {
					$phone = $val;
				} elseif ( $type === 'name' && ! $name ) {
					// GF name fields can be multi-part.
					$parts = array();
					foreach ( array( '.3', '.6' ) as $suffix ) {
						if ( ! empty( $entry[ $fid . $suffix ] ) ) {
							$parts[] = $entry[ $fid . $suffix ];
						}
					}
					$name = trim( implode( ' ', $parts ) ) ?: $val;
				}
			}
		}

		self::ingest( array(
			'email'   => $email,
			'name'    => $name,
			'phone'   => $phone,
			'company' => $company,
			'source'  => 'gravityforms',
			'fields'  => $values,
			'title'   => isset( $form['title'] ) ? 'Gravity Forms: ' . $form['title'] : 'Gravity Forms submission',
		) );
	}

	/**
	 * Contact Form 7 — `wpcf7_mail_sent`.
	 */
	public static function ingest_cf7( $contact_form ) {
		if ( ! class_exists( 'WPCF7_Submission' ) ) {
			return;
		}
		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}
		$posted = $submission->get_posted_data();
		if ( ! is_array( $posted ) ) {
			return;
		}

		$email   = '';
		$name    = '';
		$phone   = '';
		$company = '';

		foreach ( $posted as $key => $val ) {
			if ( is_array( $val ) ) {
				$val = implode( ', ', $val );
			}
			$val = (string) $val;
			$lk  = strtolower( $key );

			if ( ! $email && ( strpos( $lk, 'email' ) !== false || strpos( $lk, 'your-email' ) !== false ) && is_email( $val ) ) {
				$email = $val;
			} elseif ( ! $name && ( strpos( $lk, 'name' ) !== false || strpos( $lk, 'your-name' ) !== false ) ) {
				$name = $val;
			} elseif ( ! $phone && ( strpos( $lk, 'phone' ) !== false || strpos( $lk, 'tel' ) !== false ) ) {
				$phone = $val;
			} elseif ( ! $company && strpos( $lk, 'company' ) !== false ) {
				$company = $val;
			}
		}

		self::ingest( array(
			'email'   => $email,
			'name'    => $name,
			'phone'   => $phone,
			'company' => $company,
			'source'  => 'cf7',
			'fields'  => $posted,
			'title'   => method_exists( $contact_form, 'title' )
				? 'CF7: ' . $contact_form->title()
				: 'Contact Form 7 submission',
		) );
	}

	/**
	 * Forminator — `forminator_custom_form_after_save_entry`.
	 */
	public static function ingest_forminator( $form_id, $response, $entry ) {
		$email = $name = $phone = $company = '';
		$values = array();

		// $entry can be a Forminator object or array; handle both.
		$data = is_object( $entry ) && isset( $entry->meta_data ) ? (array) $entry->meta_data : (array) $entry;
		foreach ( $data as $key => $val ) {
			$v = is_array( $val ) && isset( $val['value'] ) ? $val['value'] : $val;
			if ( is_array( $v ) ) {
				$v = implode( ', ', $v );
			}
			$v = (string) $v;
			$lk = strtolower( (string) $key );
			$values[ $key ] = $v;

			if ( ! $email && ( strpos( $lk, 'email' ) !== false ) && is_email( $v ) ) {
				$email = $v;
			} elseif ( ! $name && strpos( $lk, 'name' ) !== false ) {
				$name = $v;
			} elseif ( ! $phone && ( strpos( $lk, 'phone' ) !== false || strpos( $lk, 'tel' ) !== false ) ) {
				$phone = $v;
			} elseif ( ! $company && strpos( $lk, 'company' ) !== false ) {
				$company = $v;
			}
		}

		self::ingest( array(
			'email'   => $email,
			'name'    => $name,
			'phone'   => $phone,
			'company' => $company,
			'source'  => 'forminator',
			'fields'  => $values,
			'title'   => 'Forminator submission #' . $form_id,
		) );
	}

	// ==================================================================
	//  Internal helpers
	// ==================================================================

	/**
	 * Upsert a contact, preferring email as the identifier and falling back
	 * to phone. Enriches name / phone / company on subsequent submissions.
	 */
	private static function upsert_contact( $email, $name, $phone, $company, $source ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wai_contacts';

		$existing = null;
		if ( $email !== '' ) {
			$existing = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM $table WHERE email = %s LIMIT 1",
				$email
			) );
		}
		if ( ! $existing && $phone !== '' ) {
			$existing = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM $table WHERE phone = %s LIMIT 1",
				$phone
			) );
		}

		if ( $existing ) {
			$patch   = array( 'updated_at' => current_time( 'mysql' ) );
			$formats = array( '%s' );
			if ( empty( $existing->name ) && $name !== '' ) {
				$patch['name']   = $name;
				$formats[]       = '%s';
			}
			if ( empty( $existing->phone ) && $phone !== '' ) {
				$patch['phone']  = $phone;
				$formats[]       = '%s';
			}
			if ( empty( $existing->company ) && $company !== '' ) {
				$patch['company'] = $company;
				$formats[]        = '%s';
			}
			$wpdb->update( $table, $patch, array( 'id' => $existing->id ), $formats, array( '%d' ) );
			return (int) $existing->id;
		}

		$wpdb->insert( $table, array(
			'name'    => $name ?: ( $email ?: $phone ),
			'email'   => $email,
			'phone'   => $phone,
			'company' => $company,
			'source'  => $source,
		), array( '%s', '%s', '%s', '%s', '%s' ) );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Record a `form_submission` activity on the CRM timeline.
	 */
	private static function log_activity( $contact_id, $title, $fields, $source ) {
		global $wpdb;

		$description = '';
		if ( is_array( $fields ) ) {
			$lines = array();
			foreach ( $fields as $k => $v ) {
				if ( is_array( $v ) ) {
					$v = wp_json_encode( $v );
				}
				$lines[] = sanitize_text_field( (string) $k ) . ': ' . sanitize_text_field( (string) $v );
			}
			$description = implode( "\n", $lines );
		}

		$wpdb->insert(
			$wpdb->prefix . 'wai_activities',
			array(
				'contact_id'   => $contact_id,
				'type'         => 'form_submission',
				'title'        => $title,
				'description'  => $description,
				'performed_at' => current_time( 'mysql' ),
				'metadata'     => wp_json_encode( array( 'source' => $source ) ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
