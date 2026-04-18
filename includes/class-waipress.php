<?php
/**
 * WAIpress Main Bootstrap Class
 *
 * Initializes all WAIpress features: AI content, messaging, CRM, chatbot.
 *
 * @package WAIpress
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress {

	/** @var bool Whether WAIpress has been initialized */
	private static $initialized = false;

	/**
	 * Initialize WAIpress.
	 * Called from wp-settings.php after WordPress core loads.
	 */
	public static function init() {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		// Load WAIpress components
		self::load_dependencies();

		// Register activation/setup hooks
		add_action( 'init', array( __CLASS__, 'on_init' ) );
		add_action( 'admin_init', array( __CLASS__, 'on_admin_init' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_admin_menus' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_admin_bar_items' ), 100 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_public_assets' ) );

		// Create custom tables on first run
		add_action( 'admin_init', array( 'WAIpress_Schema', 'maybe_create_tables' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_create_commerce_tables' ) );

		// AI streaming SSE endpoint (via admin-ajax)
		add_action( 'wp_ajax_waipress_ai_stream', array( 'WAIpress_AI', 'handle_stream' ) );

		// SSE + Heartbeat hooks (chatbot streaming, messaging polling)
		if ( class_exists( 'WAIpress_SSE' ) ) {
			WAIpress_SSE::init();
		}

		// Cron hook registration
		if ( class_exists( 'WAIpress_Cron' ) ) {
			WAIpress_Cron::init();
		}

		// Webhooks (routes registered via REST, init is a no-op placeholder)
		if ( class_exists( 'WAIpress_Webhooks' ) ) {
			WAIpress_Webhooks::init();
		}

		// Upgrade / upsell surfaces.
		if ( class_exists( 'WAIpress_Upsell' ) ) {
			WAIpress_Upsell::init();
		}

		// Yoast / Rank Math meta rewrite (no-op if neither plugin is active).
		if ( class_exists( 'WAIpress_Yoast' ) ) {
			WAIpress_Yoast::init();
		}

		// WooCommerce product AI (no-op if WC is not active).
		if ( class_exists( 'WAIpress_WooCommerce' ) ) {
			WAIpress_WooCommerce::init();
		}

		// Bridge submissions from WPForms / Gravity / CF7 / Forminator → CRM.
		if ( class_exists( 'WAIpress_Form_Bridge' ) ) {
			WAIpress_Form_Bridge::init();
		}

		// Native AI form builder.
		if ( class_exists( 'WAIpress_Forms' ) ) {
			WAIpress_Forms::init();
		}
	}

	/**
	 * Load WAIpress dependency files.
	 */
	private static function load_dependencies() {
		$waipress_dir = ABSPATH . WPINC . '/waipress/';

		require_once $waipress_dir . 'waipress-schema.php';
		require_once $waipress_dir . 'class-waipress-rest.php';
		require_once $waipress_dir . 'class-waipress-ai.php';
		require_once $waipress_dir . 'class-waipress-images.php';
		require_once $waipress_dir . 'class-waipress-messaging.php';
		require_once $waipress_dir . 'class-waipress-crm.php';
		require_once $waipress_dir . 'class-waipress-chatbot.php';
		require_once $waipress_dir . 'class-waipress-embeddings.php';
		require_once $waipress_dir . 'class-waipress-commerce.php';
		require_once $waipress_dir . 'class-waipress-migration.php';
	}

	/**
	 * WordPress init hook.
	 */
	public static function on_init() {
		// Register custom post type for knowledge base articles
		register_post_type( 'wai_knowledge', array(
			'labels' => array(
				'name'          => __( 'Knowledge Base', 'waipress' ),
				'singular_name' => __( 'Article', 'waipress' ),
				'add_new_item'  => __( 'Add Knowledge Article', 'waipress' ),
				'edit_item'     => __( 'Edit Article', 'waipress' ),
			),
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => 'waipress-chatbot',
			'show_in_rest' => true,
			'supports'     => array( 'title', 'editor', 'excerpt' ),
			'menu_icon'    => 'dashicons-book',
		) );
	}

	/**
	 * Admin init hook.
	 */
	public static function on_admin_init() {
		// Register WAIpress settings
		register_setting( 'waipress_settings', 'waipress_anthropic_model', array(
			'default' => WAIPRESS_DEFAULT_MODEL,
		) );
		register_setting( 'waipress_settings', 'waipress_chatbot_enabled', array(
			'default' => '1',
		) );
	}

	/**
	 * Register REST API routes.
	 */
	public static function register_rest_routes() {
		WAIpress_REST::register_routes();
	}

	/**
	 * Register WAIpress admin menu pages.
	 */
	public static function register_admin_menus() {
		// AI Center
		add_menu_page(
			__( 'AI Center', 'waipress' ),
			__( 'AI Center', 'waipress' ),
			'edit_posts',
			'waipress-ai',
			array( __CLASS__, 'render_admin_page' ),
			'dashicons-superhero',
			26
		);
		add_submenu_page( 'waipress-ai', __( 'Generate Content', 'waipress' ), __( 'Generate Content', 'waipress' ), 'edit_posts', 'waipress-ai' );
		add_submenu_page( 'waipress-ai', __( 'Prompt Templates', 'waipress' ), __( 'Prompt Templates', 'waipress' ), 'edit_posts', 'waipress-ai-prompts', array( __CLASS__, 'render_admin_page' ) );
		// "AI Forms" submenu is registered by WAIpress_Forms so it can bind its own server-rendered callback.
		add_submenu_page( 'waipress-ai', __( 'Generation Log', 'waipress' ), __( 'Generation Log', 'waipress' ), 'manage_options', 'waipress-ai-log', array( __CLASS__, 'render_admin_page' ) );

		// Messaging Hub
		add_menu_page(
			__( 'Messages', 'waipress' ),
			__( 'Messages', 'waipress' ),
			'edit_posts',
			'waipress-messaging',
			array( __CLASS__, 'render_admin_page' ),
			'dashicons-email-alt',
			27
		);
		add_submenu_page( 'waipress-messaging', __( 'Inbox', 'waipress' ), __( 'Inbox', 'waipress' ), 'edit_posts', 'waipress-messaging' );
		add_submenu_page( 'waipress-messaging', __( 'Channels', 'waipress' ), __( 'Channels', 'waipress' ), 'manage_options', 'waipress-channels', array( __CLASS__, 'render_admin_page' ) );

		// CRM
		add_menu_page(
			__( 'CRM', 'waipress' ),
			__( 'CRM', 'waipress' ),
			'edit_posts',
			'waipress-crm',
			array( __CLASS__, 'render_admin_page' ),
			'dashicons-groups',
			28
		);
		add_submenu_page( 'waipress-crm', __( 'Contacts', 'waipress' ), __( 'Contacts', 'waipress' ), 'edit_posts', 'waipress-crm' );
		add_submenu_page( 'waipress-crm', __( 'Deals Pipeline', 'waipress' ), __( 'Deals Pipeline', 'waipress' ), 'edit_posts', 'waipress-deals', array( __CLASS__, 'render_admin_page' ) );

		// Chatbot
		add_menu_page(
			__( 'Chatbot', 'waipress' ),
			__( 'Chatbot', 'waipress' ),
			'manage_options',
			'waipress-chatbot',
			array( __CLASS__, 'render_admin_page' ),
			'dashicons-format-chat',
			29
		);
		add_submenu_page( 'waipress-chatbot', __( 'Configuration', 'waipress' ), __( 'Configuration', 'waipress' ), 'manage_options', 'waipress-chatbot' );
		add_submenu_page( 'waipress-chatbot', __( 'Live Sessions', 'waipress' ), __( 'Live Sessions', 'waipress' ), 'edit_posts', 'waipress-chatbot-live', array( __CLASS__, 'render_admin_page' ) );

		// E-Commerce
		add_menu_page(
			__( 'Shop', 'waipress' ),
			__( 'Shop', 'waipress' ),
			'edit_posts',
			'waipress-shop',
			array( __CLASS__, 'render_admin_page' ),
			'dashicons-cart',
			30
		);
		add_submenu_page( 'waipress-shop', __( 'Products', 'waipress' ), __( 'Products', 'waipress' ), 'edit_posts', 'waipress-shop' );
		add_submenu_page( 'waipress-shop', __( 'Orders', 'waipress' ), __( 'Orders', 'waipress' ), 'edit_posts', 'waipress-orders', array( __CLASS__, 'render_admin_page' ) );
		add_submenu_page( 'waipress-shop', __( 'Coupons', 'waipress' ), __( 'Coupons', 'waipress' ), 'manage_options', 'waipress-coupons', array( __CLASS__, 'render_admin_page' ) );

		// Migration
		add_submenu_page(
			'tools.php',
			__( 'WAIpress Migration', 'waipress' ),
			__( 'WAIpress Migration', 'waipress' ),
			'manage_options',
			'waipress-migration',
			array( __CLASS__, 'render_admin_page' )
		);
	}

	/**
	 * Render a WAIpress admin page.
	 * Each page loads a React SPA into a container div.
	 */
	public static function render_admin_page() {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
		?>
		<div class="wrap">
			<div id="waipress-app" data-page="<?php echo esc_attr( $page ); ?>"></div>
		</div>
		<?php
	}

	/**
	 * Enqueue admin scripts and styles.
	 */
	public static function enqueue_admin_assets( $hook ) {
		// WAIpress admin pages
		$waipress_pages = array(
			'toplevel_page_waipress-ai',
			'ai-center_page_waipress-ai-prompts',
			'ai-center_page_waipress-ai-log',
			'toplevel_page_waipress-messaging',
			'messages_page_waipress-channels',
			'toplevel_page_waipress-crm',
			'crm_page_waipress-deals',
			'toplevel_page_waipress-chatbot',
			'chatbot_page_waipress-chatbot-live',
		);

		if ( in_array( $hook, $waipress_pages, true ) ) {
			$page = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
			$script_map = array(
				'waipress-ai'           => 'ai-center',
				'waipress-ai-prompts'   => 'ai-center',
				'waipress-ai-log'       => 'ai-center',
				'waipress-messaging'    => 'messaging-inbox',
				'waipress-channels'     => 'messaging-inbox',
				'waipress-crm'          => 'crm-app',
				'waipress-deals'        => 'crm-app',
				'waipress-chatbot'      => 'chatbot-admin',
				'waipress-chatbot-live' => 'chatbot-admin',
			);

			$script = isset( $script_map[ $page ] ) ? $script_map[ $page ] : 'waipress-admin';

			wp_enqueue_script(
				'waipress-' . $script,
				content_url( 'waipress-assets/build/' . $script . '.js' ),
				array( 'wp-element', 'wp-components', 'wp-api-fetch' ),
				WAIPRESS_VERSION,
				true
			);
			wp_enqueue_style(
				'waipress-' . $script,
				content_url( 'waipress-assets/build/' . $script . '.css' ),
				array( 'wp-components' ),
				WAIPRESS_VERSION
			);
		}

		// Global admin styles for WAIpress badges/indicators
		wp_enqueue_style(
			'waipress-admin-global',
			content_url( 'waipress-assets/build/waipress-admin.css' ),
			array(),
			WAIPRESS_VERSION
		);

		// Localize WAIpress data for all admin pages
		wp_localize_script( 'wp-api-fetch', 'waipressConfig', array(
			'restUrl'      => rest_url( 'waipress/v1/' ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'realtimeUrl'  => defined( 'WAIPRESS_REALTIME_URL' ) ? WAIPRESS_REALTIME_URL : '',
			'version'      => WAIPRESS_VERSION,
			'userId'       => get_current_user_id(),
			'userRole'     => wp_get_current_user()->roles[0] ?? 'subscriber',
		) );
	}

	/**
	 * Enqueue public-facing assets (chatbot widget).
	 */
	public static function enqueue_public_assets() {
		if ( get_option( 'waipress_chatbot_enabled', '1' ) !== '1' ) {
			return;
		}

		wp_enqueue_script(
			'waipress-chatbot-widget',
			content_url( 'waipress-assets/build/chatbot-widget.js' ),
			array(),
			WAIPRESS_VERSION,
			true
		);
		wp_enqueue_style(
			'waipress-chatbot-widget',
			content_url( 'waipress-assets/build/chatbot-widget.css' ),
			array(),
			WAIPRESS_VERSION
		);
		wp_localize_script( 'waipress-chatbot-widget', 'waipressChatbot', array(
			'restUrl'     => rest_url( 'waipress/v1/chatbot/' ),
			'realtimeUrl' => defined( 'WAIPRESS_REALTIME_URL' ) ? WAIPRESS_REALTIME_URL : '',
			'siteTitle'   => get_bloginfo( 'name' ),
		) );
	}

	/**
	 * Add WAIpress items to the admin bar.
	 */
	public static function add_admin_bar_items( $wp_admin_bar ) {
		if ( ! is_admin() && ! is_admin_bar_showing() ) {
			return;
		}

		$unread = WAIpress_Messaging::get_unread_count( get_current_user_id() );

		$title = __( 'Messages', 'waipress' );
		if ( $unread > 0 ) {
			$title .= sprintf( ' <span class="wai-badge ab-item">%d</span>', $unread );
		}

		$wp_admin_bar->add_node( array(
			'id'    => 'waipress-messages',
			'title' => $title,
			'href'  => admin_url( 'admin.php?page=waipress-messaging' ),
			'meta'  => array( 'class' => 'waipress-admin-bar-messages' ),
		) );

		$wp_admin_bar->add_node( array(
			'id'    => 'waipress-ai',
			'title' => __( 'AI', 'waipress' ),
			'href'  => admin_url( 'admin.php?page=waipress-ai' ),
		) );
	}

	/**
	 * Create commerce tables if needed.
	 */
	public static function maybe_create_commerce_tables() {
		$version = get_option( 'waipress_commerce_db_version' );
		if ( $version === '1.0.0' ) return;
		WAIpress_Commerce::create_tables();
		update_option( 'waipress_commerce_db_version', '1.0.0' );
	}

	/**
	 * Get WAIpress version.
	 */
	public static function version() {
		return WAIPRESS_VERSION;
	}
}
