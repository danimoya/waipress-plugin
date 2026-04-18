=== WAIpress ===
Contributors: danielmoya
Tags: ai, chatbot, crm, messaging, ecommerce
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 2.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The self-hosted AI stack for WordPress. One plugin, one REST namespace: content, chatbot, unified inbox (WhatsApp/Telegram/Instagram/WebChat), CRM, and commerce. Bring your own OpenAI-compatible or Ollama endpoint — your keys and data stay on your site.

== Description ==

**WAIpress is the self-hosted AI stack for WordPress.** One plugin consolidates what most teams stitch together from five or six: AI content generation, an omnichannel unified inbox, a contact/deal CRM, an on-site chatbot with RAG-grounded knowledge base, and a lightweight commerce module — all driven through a single REST namespace (`waipress/v1`) and a set of React admin apps.

Everything runs on your server. WAIpress never proxies requests through a third-party service, never ships data to the plugin author, and works with any OpenAI-compatible endpoint — OpenAI, Azure OpenAI, Groq, Together, Fireworks, LocalAI, vLLM, LM Studio, or a local Ollama install.

= Why WAIpress =

Most AI plugins do one thing: content generation *or* a chatbot *or* a CRM. WAIpress is the first free plugin that ships the full operations stack together, so the chatbot knows your products, the CRM knows your chats, and the inbox knows your customers.

* **Omnichannel chatbot** — the same bot that answers on your website also answers on WhatsApp, Telegram, and Instagram. No other free plugin does this.
* **Unified inbox with agent takeover** — chat sessions become real conversations assignable to human agents, with unread badges in the WordPress admin bar.
* **Built-in CRM** — contacts, deals, activity timeline, auto-enriched from every chat, form, and webhook.
* **Self-hosted vector search** — embeddings store ships built in; no external vector database required.

= About the authors =

WAIpress is built by the team behind **HeliosDB-Nano**, a lightweight MySQL-compatible database optimized for AI workloads. The plugin runs on *any* MySQL-compatible database (MySQL, MariaDB, Aurora, HeliosDB-Nano, etc.) with no drop-in required. HeliosDB-Nano is a disclosed, optional accelerator for the vector-search pipeline — leave the vector endpoint blank and everything works out of the box on stock MySQL. For large knowledge bases (>10k chunks), we recommend HeliosDB-Nano because it's what we built it for, and we've optimized WAIpress against it.

= Core modules =

* **AI Center** — Generate, rewrite, and SEO-optimize content with streamed output (Server-Sent Events). Saved prompt templates, generation log, tag suggestions, and a block editor sidebar.
* **AI Images** — Queue-based image generation with background jobs and attachment auto-import.
* **Messaging Hub** — Unified inbox across WhatsApp Business, Telegram, Instagram DM, and on-site WebChat. Per-channel verification, inbound webhooks, agent replies, assignment, and unread badges in the admin bar.
* **CRM** — Contacts, deals pipeline with configurable stages, activity timeline, and automatic enrichment from every conversation.
* **Chatbot** — Embeddable widget with streaming replies, RAG-grounded knowledge base (custom post type + your own content), live-session takeover for human agents, and per-bot configuration.
* **Digital Store** — Lightweight storefront for digital downloads and headless commerce. For physical products with shipping, install WooCommerce — WAIpress integrates with it.
* **Semantic Search** — Built-in embeddings store with local MySQL cosine similarity. Optional external vector endpoint for production-scale knowledge bases.
* **Migration Tool** — Scan and import content from legacy sites into WAIpress tables.

= Developer-friendly =

* REST namespace `waipress/v1` — every feature is scriptable.
* React admin apps enqueued as separate bundles per screen.
* Custom cron schedule (once per minute) for background jobs.
* Clean uninstall — options, custom tables, transients, cron, and the optional DB drop-in are all removed on plugin delete.

= Bring your own AI =

WAIpress does not bundle or proxy any AI service. You supply the endpoint (`Base URL`) and API key in the Settings screen. Supported out of the box:

* OpenAI and any OpenAI-compatible API (Azure OpenAI, Groq, Together, Fireworks, LocalAI, vLLM, LM Studio, etc.)
* Ollama (local)

Separate models can be configured for chat, embeddings, and images.

= External services =

Because WAIpress is provider-agnostic, it only contacts external services you configure yourself:

* The **AI Base URL** you enter on the Settings screen (chat, embeddings, images). Supported providers include OpenAI, Azure OpenAI, Groq, Together, Fireworks, LocalAI, vLLM, LM Studio, and any OpenAI-compatible endpoint. No data is sent until you enter credentials and generate content.
* **Ollama** if you configure it as the provider (local).
* The optional **Vector search endpoint** on the Settings screen — only contacted if you explicitly set one. Leave empty to use the built-in MySQL cosine search (no external call). HeliosDB-Nano is a recommended option by the same team that builds WAIpress; any OpenAI-compatible vector REST endpoint works.
* **WhatsApp / Telegram / Instagram** webhooks — only if you add those channels. Inbound messages POST to your site; outbound replies go to the respective Graph or Bot API.
* No telemetry, no analytics, no calls to the plugin author's servers.

== Installation ==

1. Upload the `waipress` folder to `/wp-content/plugins/` (or install via the Plugins screen).
2. Activate **WAIpress** through the *Plugins* menu in WordPress.
3. Go to **AI Center → Settings** and choose your provider (OpenAI-compatible or Ollama), base URL, API key, and default model.
4. (Optional) Add messaging channels under **Messages → Channels**.
5. (Optional) Enable the chatbot widget under **Chatbot → Configuration**.

= System requirements =

* WordPress 6.4 or newer
* PHP 8.0 or newer
* MySQL 5.7+ or MariaDB 10.3+ (or any MySQL-compatible database)
* An OpenAI-compatible endpoint **or** a reachable Ollama server

WAIpress uses only the standard WordPress database layer (`wpdb`) and does **not** install any `db.php` drop-in. It works on any MySQL-compatible database, including lightweight MySQL-compatible engines such as HeliosDB-Nano.

== Frequently Asked Questions ==

= Does WAIpress require an OpenAI subscription? =

No. WAIpress works with any OpenAI-compatible endpoint (including self-hosted ones like LocalAI, vLLM, LM Studio) or a local Ollama server. You are free to choose your provider; the plugin never contacts a paid service on your behalf.

= Does it replace WooCommerce? =

It is not a drop-in WooCommerce replacement. The commerce module is intentionally lightweight and API-first — suitable for digital products, simple catalogs, and headless storefronts. Use WooCommerce if you need its ecosystem of payment gateways, shipping, and extensions.

= Can I use WAIpress on a multisite install? =

Yes. Activation, schema creation, and uninstall are multisite-aware.

= Where is my data stored? =

Everything stays in your WordPress database, in tables prefixed `wp_wai_*` (options are `waipress_*`). On uninstall, all tables and options are removed.

= How do I connect WhatsApp / Telegram / Instagram? =

Go to **Messages → Channels → Add Channel**, pick the platform, and paste the access token and verification secret from your Meta / Telegram developer console. WAIpress exposes the webhook URLs under `/wp-json/waipress/v1/webhooks/{platform}`.

= Does the chatbot need any extra services? =

No. The chatbot runs against your configured AI provider. Knowledge-base articles use the built-in embeddings store; no external vector database is required.

= Can I stream tokens to the block editor? =

Yes. The AI sidebar uses Server-Sent Events via `admin-ajax` for real-time streaming.

== Screenshots ==

1. AI Center — generate, rewrite, and SEO-optimize with live streaming.
2. Messaging Hub — unified inbox across WhatsApp, Telegram, Instagram, and WebChat.
3. CRM — contacts list and deal pipeline with drag-and-drop stages.
4. Chatbot — configuration screen and live session takeover.
5. Shop — products, orders, and coupon management.
6. Settings — choose your AI provider and models.

== Changelog ==

= 2.2.0 =
* **AI Form builder.** Describe the form you need in plain English — WAIpress generates the fields, validates them, saves the form, and gives you a shortcode (`[waipress_form id="1"]`) or Gutenberg block to embed it. Submissions land in the CRM as contacts with a `form_submission` activity on the timeline.
* New menu: **AI Center → AI Forms**.
* New REST endpoints: `GET/POST /waipress/v1/forms`, `GET/PATCH /waipress/v1/forms/{id}`, `POST /waipress/v1/forms/generate`, `POST /waipress/v1/forms/{id}/submit`.
* New tables: `wp_wai_forms`, `wp_wai_form_submissions` (cleaned up on uninstall).

= 2.1.0 =
* **Yoast SEO / Rank Math integration.** New "Rewrite meta with AI" meta box on every edit screen — regenerates SEO title, meta description, focus keyword, or slug with one click. Writes directly into the active SEO plugin's meta keys. No-op if neither Yoast nor Rank Math is installed.
* **WooCommerce product AI.** Meta box on every WC product edit screen with buttons to generate title, short description, long description, SEO title+meta, and tags. Persists directly back into WooCommerce. No-op if WooCommerce isn't active.
* **Chatbot ↔ WooCommerce + SureCart.** Chatbot now detects product-search and order-status intent, looks up matching products/orders, and injects results as structured cards in the reply (and as context for the model). Works across ANY OpenAI-compatible provider — no native function-calling required.
* **Form bridge for WPForms / Gravity Forms / Contact Form 7 / Forminator.** Every submission from a supported form plugin auto-upserts a CRM contact and logs a `form_submission` activity on the timeline. Fires `waipress_form_submitted` for the upcoming automation engine.
* Embedding scanner now indexes WooCommerce products (and SureCart products) when those plugins are active, so the chatbot's RAG pipeline can retrieve them.

= 2.0.1 =
* Chatbot now actually uses its configured knowledge sources — RAG retrieval wired into every turn (previously the `knowledge_sources` config was stored but never queried).
* Fixed embeddings provider interface mismatch that caused every semantic search to silently fall back to LIKE text search.
* Repositioned as the self-hosted AI stack for WordPress. Disclosed that WAIpress is built by the HeliosDB-Nano team; local MySQL cosine remains the default vector backend with HeliosDB-Nano as an optional accelerator.
* Removed the `db-nano.php` drop-in. WAIpress now uses only the standard `wpdb` layer and works on any MySQL-compatible database.

= 2.0.0 =
* Pluggable AI provider system — OpenAI-compatible and Ollama out of the box.
* Separate model configuration for chat, embeddings, and images.
* New Settings screen (WordPress Settings API) with connection-test AJAX.
* Commerce module: products, variants, cart, checkout, orders, coupons.
* Migration tool for importing legacy content.
* Custom per-minute cron schedule for background jobs.
* Multisite-aware uninstall.

= 1.0.0 =
* Initial release: AI content, messaging hub (WhatsApp/Telegram/Instagram/WebChat), CRM, chatbot, semantic search.

== Upgrade Notice ==

= 2.2.0 =
New AI Form builder module. Adds two tables (`wai_forms`, `wai_form_submissions`) — no action required, they are created automatically on the next admin load.

= 2.1.0 =
Adds Yoast/Rank Math AI meta rewrite, WooCommerce product AI, chatbot integration with WooCommerce + SureCart, and a CRM bridge for WPForms/Gravity/CF7/Forminator. All integrations are opt-in — each one no-ops unless the third-party plugin is installed.

= 2.0.1 =
Important fix: chatbot knowledge sources are now actually used for RAG retrieval (previously silently ignored). Removes the legacy db.php drop-in — no action required on your side.

= 2.0.0 =
Major release. Adds the Settings screen, Commerce, Migration tool, and a pluggable AI provider system. Re-enter your API credentials under **AI Center → Settings** after upgrading.

== License ==

WAIpress is released under the GPLv2 (or later) license. See `LICENSE.txt`.
