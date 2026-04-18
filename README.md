# WAIpress

> AI-driven CMS, CRM, unified messaging hub, chatbot, and commerce platform for WordPress.

[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)](https://www.php.net/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-yellow.svg)](https://www.gnu.org/licenses/gpl-2.0)

WAIpress turns WordPress into an AI-native operations platform in a single plugin: content generation, omnichannel messaging, CRM, chatbot, and lightweight commerce — all exposed through one REST namespace (`waipress/v1`) and a suite of React admin apps.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [REST API](#rest-api)
- [Database schema](#database-schema)
- [Admin pages](#admin-pages)
- [Custom cron](#custom-cron)
- [Webhooks](#webhooks)
- [Uninstall behavior](#uninstall-behavior)
- [Development](#development)
- [License](#license)

---

## Features

| Module | What it does |
| --- | --- |
| **AI Center** | Generate, rewrite, and SEO-optimize content with streamed Server-Sent Events output. Prompt templates, generation log, block-editor sidebar. |
| **AI Images** | Background-queued image generation with attachment import. |
| **Messaging Hub** | Unified inbox for WhatsApp Business, Telegram, Instagram DM, and on-site WebChat. Per-channel webhooks, agent replies, conversation assignment. |
| **CRM** | Contacts, deals pipeline with configurable stages, activity timeline. |
| **Chatbot** | Embeddable widget with streaming replies, knowledge-base grounding, live-session takeover. |
| **Commerce** | Products (simple + variants), cart, checkout, orders, coupons. Standalone — does not require WooCommerce. |
| **Semantic Search** | Embeddings store powering KB search and chatbot retrieval. |
| **Migration Tool** | Scan and import content from legacy sources. |

**Provider-agnostic.** Bring your own OpenAI-compatible endpoint (OpenAI, Azure, Groq, Together, LocalAI, vLLM, LM Studio…) or run Ollama locally. No data is proxied through third-party services.

---

## Requirements

- WordPress **6.4+**
- PHP **8.0+**
- MySQL **5.7+** or MariaDB **10.3+**
- An OpenAI-compatible endpoint **or** a reachable Ollama server

---

## Installation

### From the WordPress.org directory

1. **Plugins → Add New → Search** for *WAIpress*.
2. Click **Install Now**, then **Activate**.

### Manual install

```bash
cd wp-content/plugins
git clone https://github.com/danielmoya/waipress.git
# or unzip a release tarball into wp-content/plugins/waipress/
```

Activate via **Plugins** in the WordPress admin.

### What activation does

- Creates the `wp_wai_*` custom tables via `dbDelta()`.
- Seeds default deal stages and chatbot config.
- Schedules the `waipress_process_jobs` cron event on a once-per-minute interval.

WAIpress does **not** install any `db.php` drop-in. It uses only the standard `wpdb` layer, so it runs on any MySQL-compatible database.

---

## Configuration

All settings live at **AI Center → Settings**.

### AI provider

| Option (`wp_options` key) | Description |
| --- | --- |
| `waipress_ai_provider` | `openai` (default) or `ollama` |
| `waipress_ai_base_url` | e.g. `https://api.openai.com` or `http://127.0.0.1:11434` |
| `waipress_ai_api_key` | API key (ignored by Ollama) |
| `waipress_ai_model` | e.g. `gpt-4o`, `llama3.1` |
| `waipress_ai_max_tokens` | Default `4096` |
| `waipress_ai_embedding_model` | e.g. `text-embedding-3-small` |
| `waipress_ai_image_model` | e.g. `gpt-image-1`, `dall-e-3` |
| `waipress_vector_rest_url` | *Optional* external vector store URL. Empty = local MySQL cosine. |

A **Test Connection** AJAX action validates credentials against the configured endpoint.

### Messaging channels

Add channels under **Messages → Channels**. For each platform, provide the access token + verification secret from the respective developer console, then register the webhook URL below.

---

## REST API

All routes are under the `waipress/v1` namespace at `/wp-json/waipress/v1/`.

### Authentication

- **Admin/agent routes** require `edit_posts` or `manage_options` and a valid nonce (`X-WP-Nonce`).
- **Public routes** (chatbot, storefront, cart, webhooks) are unauthenticated — rate-limited where appropriate.

### AI

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/ai/generate` | Generate content |
| POST | `/ai/rewrite` | Rewrite a block of text |
| POST | `/ai/seo` | SEO metadata suggestions |
| POST | `/ai/suggest-tags` | Tag suggestions |
| GET/POST | `/ai/prompts` | List / create prompt templates |
| GET | `/ai/generations` | Generation log |
| POST | `/ai/images/generate` | Queue an image job |
| GET | `/ai/images/status/{id}` | Poll job status |

Streaming is handled separately via `admin-ajax.php?action=waipress_ai_stream` (Server-Sent Events).

### Messaging

| Method | Endpoint |
| --- | --- |
| GET/POST | `/messaging/channels` |
| GET | `/messaging/conversations` |
| GET/PATCH | `/messaging/conversations/{id}` |
| POST | `/messaging/conversations/{id}/reply` |
| GET | `/messaging/updates` (long-poll) |

### CRM

| Method | Endpoint |
| --- | --- |
| GET/POST | `/crm/contacts` |
| GET/PATCH | `/crm/contacts/{id}` |
| GET | `/crm/contacts/{id}/timeline` |
| GET/POST | `/crm/deals` |
| PATCH | `/crm/deals/{id}` |
| GET | `/crm/deal-stages` |
| POST | `/crm/activities` |

### Chatbot

| Method | Endpoint | Auth |
| --- | --- | --- |
| GET/POST | `/chatbot/configs` | admin |
| PATCH | `/chatbot/configs/{id}` | admin |
| GET | `/chatbot/sessions` | agent |
| POST | `/chatbot/sessions/{id}/takeover` | agent |
| POST | `/chatbot/start` | public |
| POST | `/chatbot/{sessionId}/message` | public |
| GET | `/chatbot/{sessionId}/history` | public |

### Commerce

| Method | Endpoint | Auth |
| --- | --- | --- |
| GET/POST | `/products` | agent |
| GET/PATCH | `/products/{id}` | agent |
| GET | `/shop` | public |
| GET | `/cart` | public |
| POST | `/cart/add` | public |
| POST | `/checkout` | public |
| GET | `/orders` | agent |
| PATCH | `/orders/{id}` | agent |
| POST | `/coupons/validate` | public |

### Search & migration

| Method | Endpoint |
| --- | --- |
| POST | `/search/semantic` |
| POST | `/migration/scan` |
| POST | `/migration/start` |
| GET | `/migration/status/{id}` |

### Webhooks (inbound from messaging platforms)

| Method | Endpoint |
| --- | --- |
| GET/POST | `/webhooks/whatsapp` |
| POST | `/webhooks/telegram` |
| GET/POST | `/webhooks/instagram` |

`GET` handlers serve the verification handshakes; `POST` handlers consume inbound messages.

---

## Database schema

Custom tables (prefix shown as `wp_`):

```
wp_wai_channels              wp_wai_chatbot_configs
wp_wai_conversations         wp_wai_chatbot_sessions
wp_wai_messages              wp_wai_chatbot_messages
wp_wai_contacts              wp_wai_ai_prompts
wp_wai_deal_stages           wp_wai_ai_generations
wp_wai_deals                 wp_wai_embeddings
wp_wai_activities            wp_wai_products
wp_wai_orders                wp_wai_order_items
wp_wai_coupons
```

A `wai_knowledge` custom post type backs the chatbot knowledge base.

---

## Admin pages

Top-level menus registered under `admin.php?page=…`:

- `waipress-ai` — AI Center (Generate / Prompts / Log / Settings)
- `waipress-messaging` — Messaging Hub (Inbox / Channels)
- `waipress-crm` — CRM (Contacts / Deals)
- `waipress-chatbot` — Chatbot (Configuration / Live Sessions / Knowledge Base)
- `waipress-shop` — Commerce (Products / Orders / Coupons)
- `tools.php?page=waipress-migration` — Migration tool

Each screen mounts a React SPA into `<div id="waipress-app">`; bundles are enqueued from `wp-content/waipress-assets/build/`.

---

## Custom cron

WAIpress registers a `waipress_minute` schedule (60 s) and hooks the `waipress_process_jobs` action, which runs `WAIpress_Cron::process()` for image generation, embeddings, and webhook retry jobs.

For predictable execution on production sites, disable WP-Cron and trigger it from the system cron:

```cron
* * * * * curl -s https://example.com/wp-cron.php?doing_wp_cron >/dev/null
```

---

## Webhooks

Register these URLs in the respective developer console:

```
https://example.com/wp-json/waipress/v1/webhooks/whatsapp
https://example.com/wp-json/waipress/v1/webhooks/telegram
https://example.com/wp-json/waipress/v1/webhooks/instagram
```

Verify-token and signing secret are configured per-channel in **Messages → Channels**.

---

## Uninstall behavior

Deleting the plugin from the WordPress UI runs `uninstall.php`, which:

1. Deletes every `waipress_*` option (site and network).
2. Drops every `wp_wai_*` custom table.
3. Removes the `wp-content/db.php` drop-in if it belongs to WAIpress.
4. Clears all `_transient_waipress_*` transients.
5. Unschedules the `waipress_process_jobs` cron event.

Deactivation is non-destructive — only the drop-in and cron event are removed.

---

## Development

```
waipress/
├── waipress.php              # main plugin file + bootstrap
├── uninstall.php             # clean removal
├── includes/
│   ├── class-waipress.php              # bootstrap
│   ├── class-waipress-ai*.php          # AI providers & service
│   ├── class-waipress-chatbot.php
│   ├── class-waipress-commerce.php
│   ├── class-waipress-crm.php
│   ├── class-waipress-cron.php
│   ├── class-waipress-embeddings.php
│   ├── class-waipress-images.php
│   ├── class-waipress-messaging.php
│   ├── class-waipress-migration.php
│   ├── class-waipress-rest.php
│   ├── class-waipress-settings.php
│   ├── class-waipress-sse.php
│   ├── class-waipress-webhooks.php
│   └── waipress-schema.php
├── assets/
│   ├── build/                # compiled React bundles
│   └── js/                   # adapters
└── docker/                   # optional Helios realtime service
```

Building front-end bundles and contributing guidelines are out of scope for this README — see upstream source.

---

## License

WAIpress is released under the **GPL-2.0-or-later** license. See [`LICENSE.txt`](LICENSE.txt).

---

## Author

**Daniel Moya** — <https://danielmoya.cv>
