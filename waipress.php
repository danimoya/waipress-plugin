<?php
/**
 * Plugin Name: WAIpress
 * Plugin URI:  https://danimoya.com/waipress
 * Description: The self-hosted AI stack for WordPress: content, chatbot, unified inbox (WhatsApp/Telegram/Instagram/WebChat), CRM, and digital store. Built by the team behind HeliosDB-Nano.
 * Version:     2.5.0
 * Author:      Daniel Moya
 * Author URI:  https://danimoya.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: waipress
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.0
 *
 * @package WAIpress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------ */
/*  Constants                                                         */
/* ------------------------------------------------------------------ */

define( 'WAIPRESS_VERSION',    '2.5.0' );
define( 'WAIPRESS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WAIPRESS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/* ------------------------------------------------------------------ */
/*  Legacy constant shims (read from wp_options)                      */
/* ------------------------------------------------------------------ */

if ( ! defined( 'WAIPRESS_ANTHROPIC_API_KEY' ) ) {
	define( 'WAIPRESS_ANTHROPIC_API_KEY', get_option( 'waipress_ai_api_key', '' ) );
}
if ( ! defined( 'WAIPRESS_BANANA_API_KEY' ) ) {
	define( 'WAIPRESS_BANANA_API_KEY', get_option( 'waipress_banana_api_key', '' ) );
}
if ( ! defined( 'WAIPRESS_VECTOR_REST_URL' ) ) {
	define( 'WAIPRESS_VECTOR_REST_URL', get_option( 'waipress_vector_rest_url', '' ) );
}
if ( ! defined( 'WAIPRESS_WEBHOOK_SECRET' ) ) {
	define( 'WAIPRESS_WEBHOOK_SECRET', get_option( 'waipress_webhook_secret', '' ) );
}
if ( ! defined( 'WAIPRESS_DEFAULT_MODEL' ) ) {
	define( 'WAIPRESS_DEFAULT_MODEL', get_option( 'waipress_ai_model', 'gpt-4o' ) );
}
if ( ! defined( 'WAIPRESS_MAX_TOKENS' ) ) {
	define( 'WAIPRESS_MAX_TOKENS', (int) get_option( 'waipress_ai_max_tokens', 4096 ) );
}

/* ------------------------------------------------------------------ */
/*  Custom cron schedule                                              */
/* ------------------------------------------------------------------ */

add_filter( 'cron_schedules', 'waipress_add_cron_schedules' );

/**
 * Register a one-minute cron interval.
 *
 * @param array $schedules Existing schedules.
 * @return array
 */
function waipress_add_cron_schedules( $schedules ) {
	$schedules['waipress_minute'] = array(
		'interval' => 60,
		'display'  => __( 'Once Every Minute (WAIpress)', 'waipress' ),
	);
	return $schedules;
}

/* ------------------------------------------------------------------ */
/*  Activation / Deactivation                                         */
/* ------------------------------------------------------------------ */

register_activation_hook( __FILE__, 'waipress_activate' );
register_deactivation_hook( __FILE__, 'waipress_deactivate' );

/**
 * Plugin activation: create schema, schedule cron.
 */
function waipress_activate() {

	/* --- Create custom tables ------------------------------------- */
	require_once WAIPRESS_PLUGIN_DIR . 'includes/waipress-schema.php';
	WAIpress_Schema::create_tables();
	WAIpress_Schema::seed_data();
	update_option( 'waipress_db_version', WAIpress_Schema::DB_VERSION );

	/* --- Schedule cron -------------------------------------------- */
	if ( ! wp_next_scheduled( 'waipress_process_jobs' ) ) {
		wp_schedule_event( time(), 'waipress_minute', 'waipress_process_jobs' );
	}

	/* --- Flush rewrite rules for CPTs ----------------------------- */
	flush_rewrite_rules();
}

/**
 * Plugin deactivation: clear cron.
 */
function waipress_deactivate() {

	/* --- Clear scheduled events ----------------------------------- */
	$timestamp = wp_next_scheduled( 'waipress_process_jobs' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'waipress_process_jobs' );
	}
	wp_clear_scheduled_hook( 'waipress_process_jobs' );

	flush_rewrite_rules();
}

/* ------------------------------------------------------------------ */
/*  Load includes                                                     */
/* ------------------------------------------------------------------ */

$waipress_includes = array(
	'waipress-schema.php',
	'class-waipress-ai-provider.php',
	'class-waipress-ai-openai.php',
	'class-waipress-ai-ollama.php',
	'class-waipress-ai.php',
	'class-waipress-images.php',
	'class-waipress-embeddings.php',
	'class-waipress-messaging.php',
	'class-waipress-crm.php',
	'class-waipress-chatbot.php',
	'class-waipress-chatbot-tools.php',
	'class-waipress-commerce.php',
	'class-waipress-migration.php',
	'class-waipress-rest.php',
	'class-waipress-cron.php',
	'class-waipress-webhooks.php',
	'class-waipress-sse.php',
	'class-waipress-settings.php',
	'class-waipress-upsell.php',
	'class-waipress-yoast.php',
	'class-waipress-woocommerce.php',
	'class-waipress-form-bridge.php',
	'class-waipress-forms.php',
	'class-waipress-email.php',
	'class-waipress-automations.php',
	'class-waipress-crypto.php',
	'class-waipress-outbound.php',
	'class-waipress-channels-wizard.php',
	'class-waipress-digital-store.php',
	'class-waipress.php',
);

foreach ( $waipress_includes as $file ) {
	$filepath = WAIPRESS_PLUGIN_DIR . 'includes/' . $file;
	if ( file_exists( $filepath ) ) {
		require_once $filepath;
	}
}

/* ------------------------------------------------------------------ */
/*  Bootstrap                                                         */
/* ------------------------------------------------------------------ */

add_action( 'plugins_loaded', 'waipress_init' );

/**
 * Initialise WAIpress after all plugins are loaded.
 */
function waipress_init() {
	if ( class_exists( 'WAIpress' ) ) {
		WAIpress::init();
	}
}

/* ------------------------------------------------------------------ */
/*  Cron handler                                                      */
/* ------------------------------------------------------------------ */

add_action( 'waipress_process_jobs', 'waipress_run_cron_jobs' );

/**
 * Execute pending background jobs (image generation, embeddings, etc.).
 */
function waipress_run_cron_jobs() {
	if ( class_exists( 'WAIpress_Cron' ) ) {
		WAIpress_Cron::process();
	}
}
