=== WAIpress ===
Contributors: danielmoya
Tags: ai, chatbot, crm, messaging, ecommerce
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-driven CMS, CRM, unified messaging hub, chatbot, and commerce platform for WordPress. Bring your own OpenAI-compatible or Ollama endpoint.

== Description ==

**WAIpress** turns WordPress into an AI-native operations platform. One plugin consolidates the features most teams stitch together from five or six: AI content generation, omnichannel messaging, contact/deal CRM, on-site chatbot with knowledge base, and a lightweight commerce module — all driven through a single REST namespace and a set of React admin apps.

WAIpress is **provider-agnostic**. Point it at OpenAI, Azure OpenAI, Groq, Together, LocalAI, or any other OpenAI-compatible endpoint, or run a local Ollama server. Your API keys and data stay on your site.

= Core modules =

* **AI Center** — Generate, rewrite, and SEO-optimize content with streamed output (Server-Sent Events). Saved prompt templates, generation log, tag suggestions, and a block editor sidebar.
* **AI Images** — Queue-based image generation with background jobs and attachment auto-import.
* **Messaging Hub** — Unified inbox across WhatsApp Business, Telegram, Instagram DM, and on-site WebChat. Per-channel verification, inbound webhooks, agent replies, assignment, and unread badges in the admin bar.
* **CRM** — Contacts, deals pipeline with configurable stages, activity timeline, and automatic enrichment from conversations.
* **Chatbot** — Embeddable widget with streaming replies, knowledge-base grounding (custom post type), live-session takeover for human agents, and per-bot configuration.
* **Commerce** — Products (simple + variants), cart, checkout, orders, coupons, and a public storefront REST surface. Ships its own tables — does not require WooCommerce.
* **Semantic Search** — Embeddings store for knowledge-base search and chatbot retrieval.
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

* The **AI Base URL** you enter on the Settings screen (chat, embeddings, images). No data is sent until you enter credentials and generate content.
* The optional **Vector search endpoint** on the Settings screen — only if you explicitly set one. Leave empty to use the built-in MySQL cosine search (no external call).
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

= 2.0.0 =
Major release. Adds the Settings screen, Commerce, Migration tool, and a pluggable AI provider system. Re-enter your API credentials under **AI Center → Settings** after upgrading.

== License ==

WAIpress is released under the GPLv2 (or later) license. See `LICENSE.txt`.
