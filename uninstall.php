<?php
/**
 * WAIpress Uninstall
 *
 * Removes all WAIpress data when the plugin is deleted via the WordPress UI.
 *
 * @package WAIpress
 */

// Abort if not called by WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/* ------------------------------------------------------------------ */
/*  1. Delete all waipress_* options                                  */
/* ------------------------------------------------------------------ */

$option_rows = $wpdb->get_col(
	"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'waipress\_%'"
);

foreach ( $option_rows as $option ) {
	delete_option( $option );
}

// Also clean site-meta on multisite.
if ( is_multisite() ) {
	$sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $sites as $site_id ) {
		switch_to_blog( $site_id );

		$site_options = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'waipress\_%'"
		);
		foreach ( $site_options as $opt ) {
			delete_option( $opt );
		}

		restore_current_blog();
	}
}

/* ------------------------------------------------------------------ */
/*  2. Drop all custom tables (wp_wai_*)                              */
/* ------------------------------------------------------------------ */

$wai_tables = array(
	'wai_channels',
	'wai_conversations',
	'wai_messages',
	'wai_contacts',
	'wai_deal_stages',
	'wai_deals',
	'wai_activities',
	'wai_chatbot_configs',
	'wai_chatbot_sessions',
	'wai_chatbot_messages',
	'wai_ai_prompts',
	'wai_ai_generations',
	'wai_embeddings',
	'wai_products',
	'wai_orders',
	'wai_order_items',
	'wai_coupons',
);

foreach ( $wai_tables as $table ) {
	$full = $wpdb->prefix . $table;
	$wpdb->query( "DROP TABLE IF EXISTS {$full}" );
}

/* ------------------------------------------------------------------ */
/*  3. Remove wp-content/db.php if it belongs to WAIpress             */
/* ------------------------------------------------------------------ */

$dropin = WP_CONTENT_DIR . '/db.php';

if ( file_exists( $dropin ) ) {
	$header = file_get_contents( $dropin, false, null, 0, 512 );
	if ( strpos( $header, 'WAIpress' ) !== false ) {
		@unlink( $dropin );
	}
}

/* ------------------------------------------------------------------ */
/*  4. Remove any transients                                          */
/* ------------------------------------------------------------------ */

$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_waipress\_%'
	    OR option_name LIKE '\_transient\_timeout\_waipress\_%'"
);

/* ------------------------------------------------------------------ */
/*  5. Remove scheduled cron events                                   */
/* ------------------------------------------------------------------ */

wp_clear_scheduled_hook( 'waipress_process_jobs' );
