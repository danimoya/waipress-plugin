# Changelog

All notable changes to WAIpress are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.1] - 2026-04-18

### Fixed
- **Chatbot RAG is now actually wired.** Previously, `wai_chatbot_configs.knowledge_sources` was stored but never queried — every chatbot turn ran without context regardless of configuration. `WAIpress_Chatbot::rest_send_message()` now calls `WAIpress_Embeddings::semantic_search()` before generation and prepends the top chunks to the system prompt under a `### Context` heading.
- **Embeddings provider interface mismatch.** `WAIpress_Embeddings::embed_query()` called `$provider->embed([$query])`, but the interface defines `generate_embeddings(string): array`. Every vector search silently fell back to LIKE text search. Now matches the interface contract.

### Added
- ASCII stack diagram and feature-differentiation table in `README.md`.
- Disclosed HeliosDB-Nano authorship throughout the plugin: About-the-authors paragraph in `readme.txt`, vendor-neutral settings copy that names HeliosDB-Nano openly as a recommended accelerator, and a second CTA on the "Upgrade to WAIPress" admin page linking to the HeliosDB-Nano project site.

### Changed
- Repositioned as "the self-hosted AI stack for WordPress." Plugin header description, `readme.txt` tagline, and `README.md` intro all rewritten.
- "Shop" → "Digital Store" positioning in module descriptions (the menu/slug rebrand ships in v2.5.0).

### Removed
- `db-nano.php` drop-in and the activation routine that installed it to `wp-content/db.php`. WAIpress now uses only the standard `wpdb` layer and works on any MySQL-compatible database.

### Refactored
- Embeddings class rewritten around a local MySQL cosine-similarity search. An **optional** external vector REST endpoint can still be configured on the Settings screen; if empty, everything runs locally with no external calls.
- `WAIPRESS_HELIOS_REST_URL` / `waipress_helios_rest_url` renamed to the vendor-neutral `WAIPRESS_VECTOR_REST_URL` / `waipress_vector_rest_url`. Default is now empty (local-only).
- Docker image no longer copies a `db.php` drop-in.

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
