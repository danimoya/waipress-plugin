# Changelog

All notable changes to WAIpress are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-04-08

### Added
- Pluggable AI provider system — OpenAI-compatible and Ollama adapters.
- Dedicated Settings screen under **AI Center → Settings** (WordPress Settings API) with a **Test Connection** AJAX action.
- Separate model configuration for chat, embeddings, and images.
- Commerce module: products (simple + variants), cart, checkout, orders, coupons, public storefront REST surface.
- Migration tool (**Tools → WAIpress Migration**) for importing legacy content.
- Custom `waipress_minute` cron schedule (60 s) driving background image and embedding jobs.
- Multisite-aware uninstall routine.

### Changed
- AI service refactored behind `WAIpress_AI_Provider` so OpenAI and Ollama share one call site.
- Admin bundles split per-screen (`ai-center`, `messaging-inbox`, `crm-app`, `chatbot-admin`) and loaded conditionally.

### Fixed
- Drop-in `wp-content/db.php` is no longer overwritten when a foreign drop-in (HyperDB, LudicrousDB, Query Monitor) is already installed.

## [1.0.0] - 2025-12-01

### Added
- Initial release: AI content generation, Messaging Hub (WhatsApp / Telegram / Instagram / WebChat), CRM (contacts, deals, activities), chatbot with knowledge-base grounding, and semantic search.
