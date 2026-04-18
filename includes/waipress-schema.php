<?php
/**
 * WAIpress Database Schema
 *
 * Creates all custom tables for messaging, CRM, chatbot, and AI features.
 *
 * @package WAIpress
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WAIpress_Schema {

	const DB_VERSION = '1.1.0';

	/**
	 * Create tables if they don't exist or need updating.
	 */
	public static function maybe_create_tables() {
		$installed_version = get_option( 'waipress_db_version' );
		if ( $installed_version === self::DB_VERSION ) {
			return;
		}

		self::create_tables();
		self::seed_data();
		update_option( 'waipress_db_version', self::DB_VERSION );
	}

	/**
	 * Create all WAIpress custom tables.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$prefix = $wpdb->prefix;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// ============================================================
		// Messaging Tables
		// ============================================================

		$sql = "CREATE TABLE {$prefix}wai_channels (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			platform VARCHAR(20) NOT NULL DEFAULT 'webchat',
			name VARCHAR(255) NOT NULL DEFAULT '',
			account_id VARCHAR(500) NOT NULL DEFAULT '',
			access_token TEXT,
			webhook_secret VARCHAR(255) DEFAULT NULL,
			config LONGTEXT DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			connected_by BIGINT(20) UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY platform (platform),
			KEY status (status)
		) $charset_collate;";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$prefix}wai_conversations (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			channel_id BIGINT(20) UNSIGNED NOT NULL,
			contact_id BIGINT(20) UNSIGNED DEFAULT NULL,
			platform_conversation_id VARCHAR(500) DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			assigned_to BIGINT(20) UNSIGNED DEFAULT NULL,
			last_message_at DATETIME DEFAULT NULL,
			unread_count INT(11) NOT NULL DEFAULT 0,
			metadata LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY channel_id (channel_id),
			KEY contact_id (contact_id),
			KEY status (status),
			KEY assigned_to (assigned_to),
			KEY last_message_at (last_message_at)
		) $charset_collate;";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$prefix}wai_messages (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			conversation_id BIGINT(20) UNSIGNED NOT NULL,
			platform_message_id VARCHAR(500) DEFAULT NULL,
			sender_type VARCHAR(20) NOT NULL DEFAULT 'contact',
			sender_id BIGINT(20) UNSIGNED DEFAULT NULL,
			content LONGTEXT NOT NULL,
			content_type VARCHAR(30) NOT NULL DEFAULT 'text',
			media_url TEXT DEFAULT NULL,
			media_id BIGINT(20) UNSIGNED DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'sent',
			error_message TEXT DEFAULT NULL,
			metadata LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY conversation_id (conversation_id),
			KEY conversation_created (conversation_id, created_at),
			KEY platform_message_id (platform_message_id(191))
		) $charset_collate;";
		dbDelta( $sql );

		// ============================================================
		// CRM Tables
		// ============================================================

		$sql = "CREATE TABLE {$prefix}wai_contacts (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			name VARCHAR(255) NOT NULL DEFAULT '',
			email VARCHAR(320) DEFAULT NULL,
			phone VARCHAR(50) DEFAULT NULL,
			avatar_url TEXT DEFAULT NULL,
			company VARCHAR(255) DEFAULT NULL,
			job_title VARCHAR(255) DEFAULT NULL,
			source VARCHAR(50) NOT NULL DEFAULT 'manual',
			notes LONGTEXT DEFAULT NULL,
			tags TEXT DEFAULT NULL,
			custom_fields LONGTEXT DEFAULT NULL,
			created_by BIGINT(20) UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY email (email(191)),
			KEY phone (phone),
			KEY source (source),
			KEY wp_user_id (wp_user_id)
		) $charset_collate;";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$prefix}wai_deal_stages (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(100) NOT NULL,
			slug VARCHAR(100) NOT NULL,
			sort_order INT(11) NOT NULL DEFAULT 0,
			color VARCHAR(7) NOT NULL DEFAULT '#6366f1',
			probability INT(11) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug)
		) $charset_collate;";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$prefix}wai_deals (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(500) NOT NULL,
			contact_id BIGINT(20) UNSIGNED NOT NULL,
			stage_id BIGINT(20) UNSIGNED NOT NULL,
			value_cents BIGINT(20) NOT NULL DEFAULT 0,
			currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
			expected_close_at DATETIME DEFAULT NULL,
			assigned_to BIGINT(20) UNSIGNED DEFAULT NULL,
			notes LONGTEXT DEFAULT NULL,
			created_by BIGINT(20) UNSIGNED DEFAULT NULL,
			closed_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY contact_id (contact_id),
			KEY stage_id (stage_id),
			KEY assigned_to (assigned_to)
		) $charset_collate;";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$prefix}wai_activities (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_id BIGINT(20) UNSIGNED DEFAULT NULL,
			deal_id BIGINT(20) UNSIGNED DEFAULT NULL,
			type VARCHAR(30) NOT NULL DEFAULT 'note',
			title VARCHAR(500) NOT NULL DEFAULT '',
			description LONGTEXT DEFAULT NULL,
			performed_by BIGINT(20) UNSIGNED DEFAULT NULL,
			performed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			metadata LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY contact_id (contact_id),
			KEY deal_id (deal_id),
			KEY type (type)
		) $charset_collate;";
		dbDelta( $sql );

		// ============================================================
		// Chatbot Tables
		// ============================================================

		$sql = "CREATE TABLE {$prefix}wai_chatbot_configs (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL DEFAULT 'Default Chatbot',
			welcome_message TEXT DEFAULT NULL,
			system_prompt LONGTEXT DEFAULT NULL,
			model VARCHAR(100) NOT NULL DEFAULT 'claude-sonnet-4-20250514',
			max_tokens INT(11) NOT NULL DEFAULT 1024,
			temperature DECIMAL(3,2) NOT NULL DEFAULT 0.70,
			escalation_enabled TINYINT(1) NOT NULL DEFAULT 1,
			escalation_message TEXT DEFAULT NULL,
			knowledge_sources LONGTEXT DEFAULT NULL,
			appearance LONGTEXT DEFAULT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_by BIGINT(20) UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) $charset_collate;";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$prefix}wai_chatbot_sessions (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			config_id BIGINT(20) UNSIGNED NOT NULL,
			visitor_id VARCHAR(255) NOT NULL DEFAULT '',
			contact_id BIGINT(20) UNSIGNED DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			escalated_to BIGINT(20) UNSIGNED DEFAULT NULL,
			escalated_at DATETIME DEFAULT NULL,
			metadata LONGTEXT DEFAULT NULL,
			started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			ended_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY config_id (config_id),
			KEY visitor_id (visitor_id(191)),
			KEY status (status)
		) $charset_collate;";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$prefix}wai_chatbot_messages (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			session_id BIGINT(20) UNSIGNED NOT NULL,
			role VARCHAR(20) NOT NULL DEFAULT 'user',
			content LONGTEXT NOT NULL,
			tokens_used INT(11) DEFAULT NULL,
			model VARCHAR(100) DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY session_id (session_id),
			KEY session_created (session_id, created_at)
		) $charset_collate;";
		dbDelta( $sql );

		// ============================================================
		// AI Tables
		// ============================================================

		$sql = "CREATE TABLE {$prefix}wai_ai_prompts (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			description TEXT DEFAULT NULL,
			system_prompt LONGTEXT DEFAULT NULL,
			user_prompt_template LONGTEXT DEFAULT NULL,
			category VARCHAR(50) NOT NULL DEFAULT 'blog_post',
			model VARCHAR(100) NOT NULL DEFAULT 'claude-sonnet-4-20250514',
			max_tokens INT(11) NOT NULL DEFAULT 4096,
			temperature DECIMAL(3,2) NOT NULL DEFAULT 0.70,
			created_by BIGINT(20) UNSIGNED DEFAULT NULL,
			is_system TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY category (category)
		) $charset_collate;";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$prefix}wai_ai_generations (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			prompt_id BIGINT(20) UNSIGNED DEFAULT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			generation_type VARCHAR(30) NOT NULL DEFAULT 'text',
			input_text LONGTEXT NOT NULL,
			output_text LONGTEXT DEFAULT NULL,
			output_url TEXT DEFAULT NULL,
			model VARCHAR(100) NOT NULL DEFAULT '',
			input_tokens INT(11) NOT NULL DEFAULT 0,
			output_tokens INT(11) NOT NULL DEFAULT 0,
			cost_cents INT(11) NOT NULL DEFAULT 0,
			metadata LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY user_id (user_id),
			KEY generation_type (generation_type),
			KEY created_at (created_at)
		) $charset_collate;";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$prefix}wai_embeddings (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			content_type VARCHAR(20) NOT NULL DEFAULT 'post',
			content_id BIGINT(20) UNSIGNED NOT NULL,
			chunk_index INT(11) NOT NULL DEFAULT 0,
			chunk_text LONGTEXT NOT NULL,
			embedding LONGTEXT DEFAULT NULL,
			model VARCHAR(100) NOT NULL DEFAULT 'default',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY content_lookup (content_type, content_id),
			KEY content_id (content_id)
		) $charset_collate;";
		dbDelta( $sql );

		// ============================================================
		// AI Form Builder
		// ============================================================

		$sql = "CREATE TABLE {$prefix}wai_forms (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL DEFAULT '',
			slug VARCHAR(100) NOT NULL DEFAULT '',
			prompt TEXT DEFAULT NULL,
			fields LONGTEXT NOT NULL,
			settings LONGTEXT DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			created_by BIGINT(20) UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug),
			KEY status (status)
		) $charset_collate;";
		dbDelta( $sql );

		$sql = "CREATE TABLE {$prefix}wai_form_submissions (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT(20) UNSIGNED NOT NULL,
			contact_id BIGINT(20) UNSIGNED DEFAULT NULL,
			data LONGTEXT NOT NULL,
			ip VARCHAR(45) DEFAULT NULL,
			user_agent VARCHAR(255) DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY form_id (form_id),
			KEY contact_id (contact_id),
			KEY created_at (created_at)
		) $charset_collate;";
		dbDelta( $sql );
	}

	/**
	 * Seed default data.
	 */
	public static function seed_data() {
		global $wpdb;
		$prefix = $wpdb->prefix;

		// Seed deal stages
		$stages = array(
			array( 'Lead',        'lead',        1, '#94a3b8', 10 ),
			array( 'Qualified',   'qualified',   2, '#60a5fa', 25 ),
			array( 'Proposal',    'proposal',    3, '#a78bfa', 50 ),
			array( 'Negotiation', 'negotiation', 4, '#f59e0b', 75 ),
			array( 'Won',         'won',         5, '#22c55e', 100 ),
			array( 'Lost',        'lost',        6, '#ef4444', 0 ),
		);

		foreach ( $stages as $stage ) {
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$prefix}wai_deal_stages (name, slug, sort_order, color, probability)
				 VALUES (%s, %s, %d, %s, %d)",
				$stage[0], $stage[1], $stage[2], $stage[3], $stage[4]
			) );
		}

		// Seed default chatbot config
		$existing = $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}wai_chatbot_configs" );
		if ( ! $existing ) {
			$wpdb->insert( $prefix . 'wai_chatbot_configs', array(
				'name'               => 'Default Chatbot',
				'welcome_message'    => 'Hello! How can I help you today?',
				'system_prompt'      => 'You are a helpful customer support assistant for this website. Answer questions based on the site content. If you cannot help, offer to connect the visitor with a human agent.',
				'model'              => WAIPRESS_DEFAULT_MODEL,
				'max_tokens'         => 1024,
				'temperature'        => 0.7,
				'escalation_enabled' => 1,
				'escalation_message' => 'Let me connect you with a team member who can help further.',
				'is_active'          => 1,
			) );
		}

		// Seed default AI prompt templates
		$existing_prompts = $wpdb->get_var( "SELECT COUNT(*) FROM {$prefix}wai_ai_prompts WHERE is_system = 1" );
		if ( ! $existing_prompts ) {
			$prompts = array(
				array(
					'name'        => 'Blog Post Generator',
					'description' => 'Generate a complete blog post from a topic or brief.',
					'system_prompt' => 'You are a professional blog writer. Write engaging, well-structured blog posts with clear headings, subheadings, and paragraphs. Include an introduction and conclusion. Use a conversational yet authoritative tone.',
					'user_prompt_template' => 'Write a blog post about: {topic}',
					'category'    => 'blog_post',
					'is_system'   => 1,
				),
				array(
					'name'        => 'SEO Optimizer',
					'description' => 'Generate SEO-optimized title, meta description, and keywords.',
					'system_prompt' => 'You are an SEO expert. Analyze the content and generate optimized metadata. Return JSON with keys: seo_title (max 60 chars), seo_description (max 160 chars), keywords (comma-separated list of 5-10 keywords).',
					'user_prompt_template' => 'Generate SEO metadata for this content:\n\n{content}',
					'category'    => 'seo',
					'is_system'   => 1,
				),
				array(
					'name'        => 'Content Rewriter',
					'description' => 'Rewrite content with a different tone or style.',
					'system_prompt' => 'You are a skilled editor. Rewrite the provided content according to the instructions while preserving the core message and facts.',
					'user_prompt_template' => 'Rewrite the following content to be {style}:\n\n{content}',
					'category'    => 'rewrite',
					'is_system'   => 1,
				),
				array(
					'name'        => 'Social Media Post',
					'description' => 'Generate social media posts from blog content.',
					'system_prompt' => 'You are a social media manager. Create engaging social media posts. Return JSON with keys: twitter (max 280 chars), instagram (with hashtags), linkedin (professional tone).',
					'user_prompt_template' => 'Create social media posts from this blog content:\n\n{content}',
					'category'    => 'social',
					'is_system'   => 1,
				),
			);

			foreach ( $prompts as $prompt ) {
				$wpdb->insert( $prefix . 'wai_ai_prompts', array_merge( $prompt, array(
					'model'      => WAIPRESS_DEFAULT_MODEL,
					'max_tokens' => 4096,
					'temperature' => 0.7,
				) ) );
			}
		}
	}
}
