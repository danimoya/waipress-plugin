<?php
/**
 * WAIpress CRM
 *
 * Contact management, deal pipeline, and activity tracking.
 *
 * @package WAIpress
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_CRM {

	// Contacts

	public static function rest_list_contacts( $request ) {
		global $wpdb;

		$search = sanitize_text_field( $request->get_param( 'search' ) ?? '' );
		$source = sanitize_text_field( $request->get_param( 'source' ) ?? '' );
		$page = max( 1, absint( $request->get_param( 'page' ) ?? 1 ) );
		$per_page = min( 50, absint( $request->get_param( 'per_page' ) ?? 20 ) );
		$offset = ( $page - 1 ) * $per_page;

		$where = "WHERE 1=1";
		$params = array();

		if ( $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= " AND (name LIKE %s OR email LIKE %s OR phone LIKE %s OR company LIKE %s)";
			$params = array_merge( $params, array( $like, $like, $like, $like ) );
		}
		if ( $source ) {
			$where .= " AND source = %s";
			$params[] = $source;
		}

		$sql = "SELECT * FROM {$wpdb->prefix}wai_contacts {$where} ORDER BY updated_at DESC LIMIT %d OFFSET %d";
		$params[] = $per_page;
		$params[] = $offset;

		$contacts = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		$count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}wai_contacts {$where}";
		$total = ( $search || $source )
			? $wpdb->get_var( $wpdb->prepare( $count_sql, array_slice( $params, 0, -2 ) ) )
			: $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wai_contacts" );

		return rest_ensure_response( array(
			'items' => $contacts,
			'total' => (int) $total,
			'page'  => $page,
		) );
	}

	public static function rest_create_contact( $request ) {
		global $wpdb;

		$wpdb->insert( $wpdb->prefix . 'wai_contacts', array(
			'name'          => sanitize_text_field( $request->get_param( 'name' ) ),
			'email'         => sanitize_email( $request->get_param( 'email' ) ?? '' ),
			'phone'         => sanitize_text_field( $request->get_param( 'phone' ) ?? '' ),
			'company'       => sanitize_text_field( $request->get_param( 'company' ) ?? '' ),
			'job_title'     => sanitize_text_field( $request->get_param( 'job_title' ) ?? '' ),
			'source'        => sanitize_text_field( $request->get_param( 'source' ) ?? 'manual' ),
			'notes'         => sanitize_textarea_field( $request->get_param( 'notes' ) ?? '' ),
			'tags'          => sanitize_text_field( $request->get_param( 'tags' ) ?? '' ),
			'custom_fields' => wp_json_encode( $request->get_param( 'custom_fields' ) ?? array() ),
			'created_by'    => get_current_user_id(),
		) );

		return rest_ensure_response( array( 'id' => $wpdb->insert_id, 'message' => 'Contact created.' ) );
	}

	public static function rest_get_contact( $request ) {
		global $wpdb;
		$id = absint( $request->get_param( 'id' ) );

		$contact = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wai_contacts WHERE id = %d",
			$id
		) );

		if ( ! $contact ) {
			return new WP_Error( 'not_found', 'Contact not found.', array( 'status' => 404 ) );
		}

		// Get conversation count
		$contact->conversation_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wai_conversations WHERE contact_id = %d",
			$id
		) );

		// Get deal count
		$contact->deal_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wai_deals WHERE contact_id = %d",
			$id
		) );

		return rest_ensure_response( $contact );
	}

	public static function rest_update_contact( $request ) {
		global $wpdb;
		$id = absint( $request->get_param( 'id' ) );
		$data = array( 'updated_at' => current_time( 'mysql' ) );

		$fields = array( 'name', 'email', 'phone', 'company', 'job_title', 'source', 'notes', 'tags' );
		foreach ( $fields as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = sanitize_text_field( $request->get_param( $field ) );
			}
		}
		if ( $request->has_param( 'custom_fields' ) ) {
			$data['custom_fields'] = wp_json_encode( $request->get_param( 'custom_fields' ) );
		}

		$wpdb->update( $wpdb->prefix . 'wai_contacts', $data, array( 'id' => $id ) );
		return rest_ensure_response( array( 'message' => 'Contact updated.' ) );
	}

	public static function rest_get_timeline( $request ) {
		global $wpdb;
		$id = absint( $request->get_param( 'id' ) );

		// Activities
		$activities = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.*, u.display_name as performer_name, 'activity' as entry_type
			 FROM {$wpdb->prefix}wai_activities a
			 LEFT JOIN {$wpdb->users} u ON a.performed_by = u.ID
			 WHERE a.contact_id = %d
			 ORDER BY a.performed_at DESC LIMIT 50",
			$id
		) );

		// Messages from conversations
		$messages = $wpdb->get_results( $wpdb->prepare(
			"SELECT m.content, m.content_type, m.sender_type, m.created_at as performed_at,
			        ch.platform, 'message' as entry_type, 'message' as type
			 FROM {$wpdb->prefix}wai_messages m
			 JOIN {$wpdb->prefix}wai_conversations c ON m.conversation_id = c.id
			 JOIN {$wpdb->prefix}wai_channels ch ON c.channel_id = ch.id
			 WHERE c.contact_id = %d
			 ORDER BY m.created_at DESC LIMIT 50",
			$id
		) );

		// Merge and sort by date
		$timeline = array_merge( $activities, $messages );
		usort( $timeline, function( $a, $b ) {
			return strtotime( $b->performed_at ) - strtotime( $a->performed_at );
		} );

		return rest_ensure_response( array_slice( $timeline, 0, 50 ) );
	}

	// Deals

	public static function rest_list_deals( $request ) {
		global $wpdb;

		$stage = sanitize_text_field( $request->get_param( 'stage' ) ?? '' );

		$sql = "SELECT d.*, s.name as stage_name, s.color as stage_color,
		               ct.name as contact_name
		        FROM {$wpdb->prefix}wai_deals d
		        JOIN {$wpdb->prefix}wai_deal_stages s ON d.stage_id = s.id
		        LEFT JOIN {$wpdb->prefix}wai_contacts ct ON d.contact_id = ct.id";

		if ( $stage ) {
			$sql .= $wpdb->prepare( " WHERE s.slug = %s", $stage );
		}

		$sql .= " ORDER BY s.sort_order ASC, d.created_at DESC";

		return rest_ensure_response( $wpdb->get_results( $sql ) );
	}

	public static function rest_create_deal( $request ) {
		global $wpdb;

		$wpdb->insert( $wpdb->prefix . 'wai_deals', array(
			'title'             => sanitize_text_field( $request->get_param( 'title' ) ),
			'contact_id'        => absint( $request->get_param( 'contact_id' ) ),
			'stage_id'          => absint( $request->get_param( 'stage_id' ) ),
			'value_cents'       => absint( $request->get_param( 'value_cents' ) ?? 0 ),
			'currency'          => sanitize_text_field( $request->get_param( 'currency' ) ?? 'EUR' ),
			'expected_close_at' => sanitize_text_field( $request->get_param( 'expected_close_at' ) ?? null ),
			'assigned_to'       => absint( $request->get_param( 'assigned_to' ) ?? 0 ) ?: null,
			'notes'             => sanitize_textarea_field( $request->get_param( 'notes' ) ?? '' ),
			'created_by'        => get_current_user_id(),
		) );

		return rest_ensure_response( array( 'id' => $wpdb->insert_id, 'message' => 'Deal created.' ) );
	}

	public static function rest_update_deal( $request ) {
		global $wpdb;
		$id = absint( $request->get_param( 'id' ) );
		$data = array( 'updated_at' => current_time( 'mysql' ) );

		$fields = array( 'title', 'stage_id', 'value_cents', 'currency', 'expected_close_at', 'assigned_to', 'notes' );
		foreach ( $fields as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = sanitize_text_field( $request->get_param( $field ) );
			}
		}

		// Track stage changes
		if ( isset( $data['stage_id'] ) ) {
			$old_stage = $wpdb->get_var( $wpdb->prepare(
				"SELECT stage_id FROM {$wpdb->prefix}wai_deals WHERE id = %d",
				$id
			) );
			if ( $old_stage != $data['stage_id'] ) {
				$deal = $wpdb->get_row( $wpdb->prepare(
					"SELECT contact_id FROM {$wpdb->prefix}wai_deals WHERE id = %d",
					$id
				) );
				$new_stage_name = $wpdb->get_var( $wpdb->prepare(
					"SELECT name FROM {$wpdb->prefix}wai_deal_stages WHERE id = %d",
					$data['stage_id']
				) );
				$wpdb->insert( $wpdb->prefix . 'wai_activities', array(
					'contact_id'   => $deal->contact_id,
					'deal_id'      => $id,
					'type'         => 'note',
					'title'        => sprintf( 'Deal moved to %s', $new_stage_name ),
					'performed_by' => get_current_user_id(),
				) );
			}
		}

		$wpdb->update( $wpdb->prefix . 'wai_deals', $data, array( 'id' => $id ) );
		return rest_ensure_response( array( 'message' => 'Deal updated.' ) );
	}

	public static function rest_list_stages( $request ) {
		global $wpdb;
		$stages = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wai_deal_stages ORDER BY sort_order ASC" );
		return rest_ensure_response( $stages );
	}

	// Activities

	public static function rest_create_activity( $request ) {
		global $wpdb;

		$wpdb->insert( $wpdb->prefix . 'wai_activities', array(
			'contact_id'   => absint( $request->get_param( 'contact_id' ) ) ?: null,
			'deal_id'      => absint( $request->get_param( 'deal_id' ) ) ?: null,
			'type'         => sanitize_text_field( $request->get_param( 'type' ) ?? 'note' ),
			'title'        => sanitize_text_field( $request->get_param( 'title' ) ),
			'description'  => sanitize_textarea_field( $request->get_param( 'description' ) ?? '' ),
			'performed_by' => get_current_user_id(),
			'metadata'     => wp_json_encode( $request->get_param( 'metadata' ) ?? array() ),
		) );

		return rest_ensure_response( array( 'id' => $wpdb->insert_id, 'message' => 'Activity logged.' ) );
	}
}
