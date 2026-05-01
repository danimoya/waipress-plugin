<?php
/**
 * WAIpress → WAIPress Pro Upsell
 *
 * Adds a dedicated "Upgrade" menu page and a plugins-list row action.
 * The page describes the features available in the hosted WAIPress offering
 * and links to the product site. No tracking, no auto-installs — just a
 * clearly-labelled marketing surface, per wordpress.org guideline #7
 * (plugins may not be a vehicle for pure advertising, but a single, clearly-
 * labelled upsell page is accepted practice).
 *
 * @package WAIpress
 * @since   2.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_Upsell {

	const UPGRADE_URL  = 'https://danimoya.com/waipress?utm_source=wp-plugin&utm_medium=admin&utm_campaign=upsell';
	const HELIOSDB_URL = 'https://github.com/dimensigon/HDB-HeliosDB-Nano/';

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 99 );
		add_filter( 'plugin_action_links_' . plugin_basename( WAIPRESS_PLUGIN_DIR . 'waipress.php' ), array( __CLASS__, 'plugin_row_links' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_welcome_notice' ) );
	}

	/**
	 * Add "Upgrade to WAIPress" submenu under every WAIpress top menu.
	 */
	public static function register_menu() {
		add_submenu_page(
			'waipress-ai',
			__( 'Upgrade to WAIPress', 'waipress' ),
			'<span style="color:#ffba00;">' . esc_html__( 'Upgrade ★', 'waipress' ) . '</span>',
			'manage_options',
			'waipress-upgrade',
			array( __CLASS__, 'render_upgrade_page' )
		);
	}

	/**
	 * Add "Upgrade" link to the plugins page row.
	 */
	public static function plugin_row_links( $links ) {
		$upgrade = sprintf(
			'<a href="%s" target="_blank" rel="noopener" style="color:#d54e21;font-weight:600;">%s</a>',
			esc_url( self::UPGRADE_URL ),
			esc_html__( 'Upgrade', 'waipress' )
		);
		array_unshift( $links, $upgrade );
		return $links;
	}

	/**
	 * One-time welcome notice after activation.
	 */
	public static function maybe_show_welcome_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( 'waipress_welcome_dismissed' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || strpos( (string) $screen->id, 'waipress' ) === false ) {
			// Only surface on our screens to avoid annoying the user elsewhere.
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'waipress_dismiss_welcome', '1' ),
			'waipress_dismiss_welcome'
		);

		if ( isset( $_GET['waipress_dismiss_welcome'] ) && check_admin_referer( 'waipress_dismiss_welcome' ) ) {
			update_option( 'waipress_welcome_dismissed', 1 );
			return;
		}
		?>
		<div class="notice notice-info is-dismissible" style="border-left-color:#6366f1;">
			<p>
				<strong><?php esc_html_e( 'Thanks for installing WAIpress!', 'waipress' ); ?></strong>
				<?php esc_html_e( 'Need managed hosting, higher limits, and premium support? Check out WAIPress.', 'waipress' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=waipress-upgrade' ) ); ?>" class="button button-primary" style="margin-left:.5rem;">
					<?php esc_html_e( 'See what\'s in WAIPress', 'waipress' ); ?>
				</a>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" style="margin-left:.5rem;"><?php esc_html_e( 'Dismiss', 'waipress' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the upgrade page.
	 */
	public static function render_upgrade_page() {
		?>
		<div class="wrap waipress-upgrade">
			<h1><?php esc_html_e( 'Upgrade to WAIPress', 'waipress' ); ?></h1>
			<p class="description" style="font-size:15px;max-width:780px;">
				<?php esc_html_e( 'You already have the free WAIpress plugin — the self-hosted AI stack: content, chatbot, unified inbox, CRM, and commerce in one package. If you\'d rather skip the setup, need higher throughput, or want a managed HeliosDB-Nano vector database behind your knowledge base, WAIPress is the hosted edition from the same team.', 'waipress' ); ?>
			</p>

			<table class="widefat striped" style="max-width:900px;margin-top:1.5rem;">
				<thead>
					<tr>
						<th style="width:50%;"><?php esc_html_e( 'Feature', 'waipress' ); ?></th>
						<th><?php esc_html_e( 'WAIpress (free)', 'waipress' ); ?></th>
						<th><?php esc_html_e( 'WAIPress (hosted)', 'waipress' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$rows = array(
						array( __( 'AI content generation & streaming', 'waipress' ), true, true ),
						array( __( 'Messaging Hub (WhatsApp, Telegram, Instagram, WebChat)', 'waipress' ), true, true ),
						array( __( 'CRM, Chatbot, Digital Store modules', 'waipress' ), true, true ),
						array( __( 'Local MySQL cosine semantic search', 'waipress' ), true, true ),
						array( __( 'Bring your own OpenAI / Ollama key', 'waipress' ), true, true ),
						array( __( 'Self-host on any MySQL-compatible DB (incl. HeliosDB-Nano)', 'waipress' ), true, true ),
						array( __( 'Managed AI — no API key to configure', 'waipress' ), false, true ),
						array( __( 'Managed HeliosDB-Nano vector database for large KBs', 'waipress' ), false, true ),
						array( __( 'Dedicated real-time WebSocket server', 'waipress' ), false, true ),
						array( __( 'High-throughput image generation queue', 'waipress' ), false, true ),
						array( __( 'Team workspace, RBAC, audit log', 'waipress' ), false, true ),
						array( __( 'Priority support & SLA', 'waipress' ), false, true ),
					);
					foreach ( $rows as $row ) {
						printf(
							'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
							esc_html( $row[0] ),
							$row[1] ? '<span style="color:#46b450;">&#10003;</span>' : '&mdash;',
							$row[2] ? '<span style="color:#46b450;">&#10003;</span>' : '&mdash;'
						);
					}
					?>
				</tbody>
			</table>

			<p style="margin-top:2rem;">
				<a href="<?php echo esc_url( self::UPGRADE_URL ); ?>" target="_blank" rel="noopener" class="button button-primary button-hero">
					<?php esc_html_e( 'Learn more about WAIPress', 'waipress' ); ?> &rarr;
				</a>
				<a href="<?php echo esc_url( self::HELIOSDB_URL ); ?>" target="_blank" rel="noopener" class="button button-secondary button-hero" style="margin-left:.5rem;">
					<?php esc_html_e( 'About HeliosDB-Nano', 'waipress' ); ?>
				</a>
			</p>

			<p class="description" style="margin-top:2rem;max-width:780px;">
				<?php
				printf(
					/* translators: 1: link to the WAIPress product site, 2: link to the HeliosDB-Nano project */
					esc_html__( 'The free WAIpress plugin will always remain open source under the GPL. WAIPress is the hosted edition from the same team. WAIpress runs on any MySQL-compatible database; %2$s is our own MySQL-compatible engine, optimized for AI workloads, and a recommended (optional) accelerator for vector search. Read more at %1$s.', 'waipress' ),
					'<a href="' . esc_url( self::UPGRADE_URL ) . '" target="_blank" rel="noopener">danimoya.com/waipress</a>',
					'<a href="' . esc_url( self::HELIOSDB_URL ) . '" target="_blank" rel="noopener">HeliosDB-Nano</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
