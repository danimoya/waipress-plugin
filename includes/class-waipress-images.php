<?php
/**
 * WAIpress AI Image Generation
 *
 * Handles image generation via Nano Banana API.
 *
 * @package WAIpress
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_Images {

	/**
	 * POST /ai/images/generate - Queue an image generation job.
	 */
	public static function rest_generate( $request ) {
		global $wpdb;

		$prompt = sanitize_textarea_field( $request->get_param( 'prompt' ) );
		$style = sanitize_text_field( $request->get_param( 'style' ) ?? 'photo' );
		$width = absint( $request->get_param( 'width' ) ?? 1024 );
		$height = absint( $request->get_param( 'height' ) ?? 1024 );

		if ( empty( $prompt ) ) {
			return new WP_Error( 'missing_prompt', __( 'Image prompt is required.', 'waipress' ), array( 'status' => 400 ) );
		}

		// Queue job for the worker to process
		$wpdb->insert( $wpdb->prefix . 'wai_ai_generations', array(
			'user_id'         => get_current_user_id(),
			'generation_type' => 'image',
			'input_text'      => $prompt,
			'model'           => 'nano-banana',
			'metadata'        => wp_json_encode( array(
				'style'  => $style,
				'width'  => $width,
				'height' => $height,
			) ),
		) );

		$job_id = $wpdb->insert_id;

		return rest_ensure_response( array(
			'id'      => $job_id,
			'status'  => 'pending',
			'message' => __( 'Image generation queued. Check status with the status endpoint.', 'waipress' ),
		) );
	}

	/**
	 * GET /ai/images/status/:id - Check image generation status.
	 */
	public static function rest_status( $request ) {
		global $wpdb;

		$id = absint( $request->get_param( 'id' ) );

		$job = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, generation_type, input_text, output_url, model, metadata, created_at
			 FROM {$wpdb->prefix}wai_ai_generations
			 WHERE id = %d AND generation_type = 'image'",
			$id
		) );

		if ( ! $job ) {
			return new WP_Error( 'not_found', __( 'Job not found.', 'waipress' ), array( 'status' => 404 ) );
		}

		$status = $job->output_url ? 'completed' : 'pending';

		return rest_ensure_response( array(
			'id'        => (int) $job->id,
			'status'    => $status,
			'prompt'    => $job->input_text,
			'image_url' => $job->output_url,
			'metadata'  => json_decode( $job->metadata, true ),
		) );
	}
}
