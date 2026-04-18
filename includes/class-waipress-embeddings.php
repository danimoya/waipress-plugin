<?php
/**
 * WAIpress Embeddings
 *
 * Manages vector embeddings for RAG-powered chatbot and semantic search.
 * Uses HeliosDB REST API for vector similarity search.
 *
 * @package WAIpress
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_Embeddings {

	/**
	 * Perform semantic search via HeliosDB REST API.
	 */
	public static function semantic_search( $query, $top_k = 5, $content_type = '' ) {
		$rest_url = WAIPRESS_HELIOS_REST_URL;

		// First, get embedding for the query
		$response = wp_remote_post( $rest_url . '/v1/embeddings', array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'input' => $query,
				'model' => 'default',
			) ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			// Fallback to SQL-based text search
			return self::fallback_text_search( $query, $top_k, $content_type );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$query_embedding = $body['data'][0]['embedding'] ?? null;

		if ( ! $query_embedding ) {
			return self::fallback_text_search( $query, $top_k, $content_type );
		}

		// Search via HeliosDB vector store
		$search_response = wp_remote_post( $rest_url . '/v1/vectors/waipress_content/search', array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'vector' => $query_embedding,
				'top_k'  => $top_k,
				'filter' => $content_type ? array( 'content_type' => $content_type ) : array(),
			) ),
			'timeout' => 10,
		) );

		if ( is_wp_error( $search_response ) ) {
			return self::fallback_text_search( $query, $top_k, $content_type );
		}

		$search_body = json_decode( wp_remote_retrieve_body( $search_response ), true );
		return $search_body['results'] ?? array();
	}

	/**
	 * Fallback: simple text search when vector search is unavailable.
	 */
	private static function fallback_text_search( $query, $top_k, $content_type ) {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $query ) . '%';
		$where = "";
		$params = array( $like );

		if ( $content_type ) {
			$where = "AND content_type = %s";
			$params[] = $content_type;
		}
		$params[] = $top_k;

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT content_type, content_id, chunk_index, chunk_text
			 FROM {$wpdb->prefix}wai_embeddings
			 WHERE chunk_text LIKE %s {$where}
			 LIMIT %d",
			$params
		) );

		return array_map( function( $row ) {
			return array(
				'id'       => $row->content_type . '_' . $row->content_id . '_' . $row->chunk_index,
				'score'    => 0.5,
				'metadata' => array(
					'content_type' => $row->content_type,
					'content_id'   => $row->content_id,
					'text'         => $row->chunk_text,
				),
			);
		}, $results );
	}

	/**
	 * POST /search/semantic - REST endpoint for semantic search.
	 */
	public static function rest_semantic_search( $request ) {
		$query = sanitize_text_field( $request->get_param( 'query' ) );
		$top_k = min( 20, absint( $request->get_param( 'top_k' ) ?? 5 ) );
		$content_type = sanitize_text_field( $request->get_param( 'content_type' ) ?? '' );

		if ( empty( $query ) ) {
			return new WP_Error( 'missing_query', 'Search query is required.', array( 'status' => 400 ) );
		}

		$results = self::semantic_search( $query, $top_k, $content_type );

		return rest_ensure_response( array(
			'query'   => $query,
			'results' => $results,
		) );
	}
}
