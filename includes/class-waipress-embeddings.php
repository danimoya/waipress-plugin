<?php
/**
 * WAIpress Embeddings
 *
 * Vector store and semantic search for the RAG-powered chatbot and KB search.
 *
 * Storage: embeddings are persisted locally in {prefix}wai_embeddings as a
 * JSON-encoded float array. Two search backends are available:
 *
 *   1. Local cosine-similarity search (default) — pure PHP over the local
 *      embeddings table. Works on any WordPress install with no extra
 *      services. Suitable for up to ~10k chunks.
 *
 *   2. External vector REST endpoint (optional) — if the admin has set a
 *      URL under Settings → Embeddings, WAIpress delegates similarity
 *      search and embedding generation to that endpoint. Use this to
 *      offload vector work to a dedicated vector database when your
 *      knowledge base grows beyond what local cosine search can handle.
 *
 * @package WAIpress
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_Embeddings {

	/**
	 * Perform a semantic search against the local embeddings table.
	 *
	 * If a vector REST endpoint is configured the call is delegated to it;
	 * otherwise we compute cosine similarity in PHP over the local store.
	 */
	public static function semantic_search( $query, $top_k = 5, $content_type = '' ) {
		$rest_url = defined( 'WAIPRESS_VECTOR_REST_URL' ) ? WAIPRESS_VECTOR_REST_URL : '';

		if ( $rest_url ) {
			$remote = self::remote_search( $rest_url, $query, $top_k, $content_type );
			if ( ! is_wp_error( $remote ) ) {
				return $remote;
			}
			// Fall through to the local backend on remote failure.
		}

		return self::local_search( $query, $top_k, $content_type );
	}

	/**
	 * Local cosine-similarity search over the embeddings table.
	 *
	 * 1. Generate an embedding for the query via the configured AI provider.
	 * 2. Stream rows from wai_embeddings in batches and score them.
	 * 3. Return the top_k highest-scoring chunks.
	 *
	 * Falls back to a plain text LIKE search if the provider cannot produce
	 * an embedding (no API key, offline, etc.).
	 */
	private static function local_search( $query, $top_k, $content_type ) {
		global $wpdb;

		$query_vector = self::embed_query( $query );

		if ( empty( $query_vector ) ) {
			return self::text_search( $query, $top_k, $content_type );
		}

		$batch_size = 500;
		$offset     = 0;
		$heap       = array();

		$where  = $content_type ? 'WHERE content_type = %s' : '';
		$params = $content_type ? array( $content_type ) : array();

		do {
			$sql = "SELECT content_type, content_id, chunk_index, chunk_text, embedding
			        FROM {$wpdb->prefix}wai_embeddings
			        $where
			        LIMIT %d OFFSET %d";

			$batch_params = array_merge( $params, array( $batch_size, $offset ) );
			$rows         = $wpdb->get_results( $wpdb->prepare( $sql, $batch_params ) );

			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $row ) {
				$vector = json_decode( $row->embedding, true );
				if ( ! is_array( $vector ) || count( $vector ) !== count( $query_vector ) ) {
					continue;
				}

				$score = self::cosine_similarity( $query_vector, $vector );
				$heap[] = array(
					'id'       => $row->content_type . '_' . $row->content_id . '_' . $row->chunk_index,
					'score'    => $score,
					'metadata' => array(
						'content_type' => $row->content_type,
						'content_id'   => (int) $row->content_id,
						'text'         => $row->chunk_text,
					),
				);
			}

			$offset += $batch_size;
		} while ( count( $rows ) === $batch_size );

		usort( $heap, static function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );

		return array_slice( $heap, 0, $top_k );
	}

	/**
	 * Delegate search to an external OpenAI-compatible vector REST endpoint.
	 *
	 * @return array|WP_Error
	 */
	private static function remote_search( $rest_url, $query, $top_k, $content_type ) {
		$embed_response = wp_remote_post( rtrim( $rest_url, '/' ) . '/v1/embeddings', array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'input' => $query,
				'model' => 'default',
			) ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $embed_response ) ) {
			return $embed_response;
		}

		$embed_body = json_decode( wp_remote_retrieve_body( $embed_response ), true );
		$vector     = $embed_body['data'][0]['embedding'] ?? null;

		if ( empty( $vector ) ) {
			return new WP_Error( 'waipress_no_vector', 'Vector endpoint returned no embedding.' );
		}

		$search_response = wp_remote_post( rtrim( $rest_url, '/' ) . '/v1/vectors/waipress_content/search', array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'vector' => $vector,
				'top_k'  => $top_k,
				'filter' => $content_type ? array( 'content_type' => $content_type ) : new stdClass(),
			) ),
			'timeout' => 10,
		) );

		if ( is_wp_error( $search_response ) ) {
			return $search_response;
		}

		$body = json_decode( wp_remote_retrieve_body( $search_response ), true );
		return $body['results'] ?? array();
	}

	/**
	 * Generate an embedding for the query text via the configured AI provider.
	 *
	 * Matches the WAIpress_AI_Provider::generate_embeddings( string ): array contract.
	 */
	private static function embed_query( $query ) {
		if ( ! class_exists( 'WAIpress_AI' ) ) {
			return array();
		}

		try {
			$provider = WAIpress_AI::get_provider();
			if ( ! $provider->supports_embeddings() ) {
				return array();
			}
			$vector = $provider->generate_embeddings( $query );
			return is_array( $vector ) ? $vector : array();
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	/**
	 * Cosine similarity over two equal-length numeric vectors.
	 */
	private static function cosine_similarity( array $a, array $b ) {
		$dot = 0.0;
		$na  = 0.0;
		$nb  = 0.0;
		$len = count( $a );

		for ( $i = 0; $i < $len; $i++ ) {
			$va   = (float) $a[ $i ];
			$vb   = (float) $b[ $i ];
			$dot += $va * $vb;
			$na  += $va * $va;
			$nb  += $vb * $vb;
		}

		if ( $na == 0.0 || $nb == 0.0 ) {
			return 0.0;
		}

		return $dot / ( sqrt( $na ) * sqrt( $nb ) );
	}

	/**
	 * Last-resort text search (LIKE) when no embedding can be generated.
	 */
	private static function text_search( $query, $top_k, $content_type ) {
		global $wpdb;

		$like   = '%' . $wpdb->esc_like( $query ) . '%';
		$where  = '';
		$params = array( $like );

		if ( $content_type ) {
			$where    = 'AND content_type = %s';
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

		return array_map( static function ( $row ) {
			return array(
				'id'       => $row->content_type . '_' . $row->content_id . '_' . $row->chunk_index,
				'score'    => 0.5,
				'metadata' => array(
					'content_type' => $row->content_type,
					'content_id'   => (int) $row->content_id,
					'text'         => $row->chunk_text,
				),
			);
		}, $results );
	}

	/**
	 * POST /search/semantic — REST endpoint for semantic search.
	 */
	public static function rest_semantic_search( $request ) {
		$query        = sanitize_text_field( $request->get_param( 'query' ) );
		$top_k        = min( 20, absint( $request->get_param( 'top_k' ) ?? 5 ) );
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
