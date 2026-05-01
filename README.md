# WAIpress

> **The self-hosted AI stack for WordPress.** One plugin: content, chatbot, unified inbox (WhatsApp / Telegram / Instagram / WebChat), CRM with email automation, AI form builder, and a lightweight digital store — all behind a single REST namespace.

[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)](https://www.php.net/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-yellow.svg)](https://www.gnu.org/licenses/gpl-2.0)

WAIpress turns WordPress into an AI-native operations platform. Content generation, omnichannel messaging, CRM with triggered email workflows, an AI form builder, an RAG-grounded chatbot, and a digital-download storefront — all exposed through one REST namespace (`waipress/v1`) and a suite of React/server-rendered admin apps. Bring your own OpenAI-compatible provider or run a local Ollama — your keys and data stay on your site.

### Stack

```
┌───────────────────────────────────────────────────────────────┐
│                    WordPress admin + frontend                 │
│  Gutenberg sidebar · chatbot widget · inbox SPA · storefront  │
└──────────────────────────┬────────────────────────────────────┘
                           │   REST  waipress/v1  +  SSE
┌──────────────────────────┴────────────────────────────────────┐
│                         WAIpress plugin                       │
│  AI Center · Chatbot · Messaging Hub · CRM + Automations      │
│  AI Forms · Digital Store · Integrations                      │
└───────┬──────────────────┬─────────────────────┬──────────────┘
        │                  │                     │
  AI provider       Vector store (opt.)    Messaging APIs
  ───────────       ──────────────────     ────────────────
  OpenAI-compat    MySQL cosine (built-in) WhatsApp Cloud
  Ollama local     or HeliosDB-Nano        Telegram Bot
                   (recommended, optional) Instagram Graph
```

**Built by the team behind [HeliosDB-Nano](https://github.com/dimensigon/HDB-HeliosDB-Nano/).** WAIpress runs on any MySQL-compatible database; HeliosDB-Nano is a disclosed, optional accelerator for the vector-search pipeline when your knowledge base grows beyond the ~10k-chunk range that pure-MySQL cosine handles well.

---

## Table of Contents

- [Features](#features)
- [Positioning](#positioning)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Third-party integrations](#third-party-integrations)
- [Automations](#automations)
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
| **AI Form Builder** | Describe a form in plain English → validated fields → shortcode/block embed → submissions into the CRM. |
| **Messaging Hub** | Unified inbox for WhatsApp Business, Telegram, Instagram DM, and on-site WebChat. Per-platform setup wizard, encrypted tokens, outbound dispatch, agent auto-assignment, SLA tracking, canned replies. |
| **CRM + Automations** | Contacts, deals pipeline with configurable stages, activity timeline, triggered email workflows, merge tags, optional per-recipient AI personalization. |
| **Chatbot** | Embeddable widget with streaming replies, RAG-grounded knowledge base, commerce-aware tools (product search + order status across WC and SureCart), live-session takeover. |
| **Digital Store** | Lightweight storefront for digital downloads: products, orders, coupons, license keys, HMAC-signed download URLs. For physical products, integrates with WooCommerce. |
| **Semantic Search** | Built-in MySQL cosine embedding search. Optional external vector endpoint for production scale. |
| **Migration Tool** | Scan and import content from legacy sources. |

### What makes WAIpress different from every other AI plugin

| | AI Engine | FluentCRM | Chaty | **WAIpress** |
| - | :-: | :-: | :-: | :-: |
| AI content generation | ✅ | — | — | ✅ |
| On-site chatbot | ✅ | — | — | ✅ |
| **Chatbot over WhatsApp / Telegram / Instagram** | — | — | — | ✅ |
| **Unified inbox with agent takeover, auto-assignment, SLAs** | — | — | — | ✅ |
| Built-in CRM (contacts, deals, timeline) | — | ✅ | — | ✅ |
| **Email automation / sequences** | — | ✅ | — | ✅ |
| **AI form builder with CRM routing** | — | — | — | ✅ |
| **WooCommerce + SureCart product AI + order lookup** | partial | — | — | ✅ |
| **Yoast / Rank Math AI meta rewrite** | — | — | — | ✅ |
| Semantic search + RAG | — | — | — | ✅ |
| Free + GPL | ✅ | ✅ | ✅ | ✅ |

**Provider-agnostic.** Bring your own OpenAI-compatible endpoint (OpenAI, Azure, Groq, Together, Fireworks, LocalAI, vLLM, LM Studio…) or run Ollama locally. No data is proxied through third-party services.

---

## Positioning

WAIpress is *not* a point solution. It is an **AI operations suite**: the place where AI, messaging, CRM, automation, and light commerce live together so each feature can make the others smarter.

* The **chatbot** retrieves from the **AI Forms** knowledge base and from your **WooCommerce / SureCart** catalogue.
* The **AI Forms** ingest into the **CRM**, where **Automations** fire **email sequences**.
* The **Messaging Hub** enriches contacts, which feed the **CRM timeline**, which feeds the **Chatbot** via RAG.
* **SEO rewrites** (Yoast / Rank Math) use the same AI provider as everything else.

Every module is optional, every third-party integration is opt-in, and the plugin runs fine with just one module turned on.

---

## Requirements

- WordPress **6.4+**
- PHP **8.0+**
- MySQL **5.7+** or MariaDB **10.3+** (or any MySQL-compatible database — tested on MySQL, MariaDB, Aurora, and HeliosDB-Nano)
- An OpenAI-compatible endpoint **or** a reachable Ollama server

---

## Installation

### From the WordPress.org directory

1. **Plugins → Add New → Search** for *WAIpress*.
2. Click **Install Now**, then **Activate**.

### Manual install

```bash
cd wp-content/plugins
git clone https://github.com/danimoya/waipress-plugin.git waipress
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
| `waipress_messaging_auto_assign` | `off` / `round_robin` / `least_busy` |

A **Test Connection** AJAX action validates credentials against the configured endpoint.

### Messaging channels

Add channels under **Messages → Add channel** using the step-by-step wizard for each platform. Tokens and app secrets are stored AES-256-GCM-encrypted at rest, keyed off your WordPress `AUTH_KEY` salts.

---

## Third-party integrations

Each integration is opt-in and `no-op`s cleanly if the target plugin isn't installed.

| Plugin | What WAIpress adds |
| - | - |
| **Yoast SEO** | "Rewrite meta with AI" side meta box on every edit screen. Writes to `_yoast_wpseo_*`. |
| **Rank Math** | Same meta box — writes to `rank_math_*`. |
| **WooCommerce** | AI meta box on product edit (title, short desc, long desc, SEO, tags). Chatbot product search + order status lookup. |
| **SureCart** | Chatbot product search + order status lookup via SureCart REST. |
| **WPForms / Gravity Forms / Contact Form 7 / Forminator** | Every submission upserts a CRM contact and logs a `form_submission` activity. Fires the `waipress_form_submitted` trigger. |
| **FluentSMTP / WP Mail SMTP / custom** | Email transport is pluggable via the `waipress_email_sender` filter. |

---

## Automations

Trigger → action workflows, stored in `wai_automations` and advanced step-by-step by the per-minute cron.

**Triggers**: `form_submitted`, `tag_added`, `deal_stage_changed`, `webhook_received`.

**Actions**: `send_email` (with optional AI personalization), `add_tag`, `remove_tag`, `create_activity`, `wait` (minutes), `call_webhook`, `update_deal_stage`.

```json
[
  { "type": "send_email", "template_id": 1, "ai_personalize": true },
  { "type": "add_tag", "tag": "lead" },
  { "type": "wait", "minutes": 1440 },
  { "type": "send_email", "template_id": 2 }
]
```

Merge tags available in templates: `{{contact.name}}`, `{{contact.email}}`, `{{contact.company}}`, `{{site.name}}`, `{{site.url}}`, plus any `{{form.field_name}}` passed in the trigger context.

Extension point — fire a trigger from your own code:

```php
do_action( 'waipress_trigger', 'webhook_received', array(
    'contact_id' => 42,
    'source'     => 'my_integration',
    'payload'    => array( /* … */ ),
) );
```

---

## REST API

All routes are under the `waipress/v1` namespace at `/wp-json/waipress/v1/`.

### Authentication

- **Admin/agent routes** require `edit_posts` or `manage_options` and a valid nonce (`X-WP-Nonce`).
- **Public routes** (chatbot, storefront, cart, webhooks, forms, download) are unauthenticated — rate-limited, nonce-protected, HMAC-signed, or honeypot-protected as appropriate.

### AI

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/ai/generate` | Generate content |
| POST | `/ai/rewrite` | Rewrite a block of text |
| POST | `/ai/seo` | SEO metadata suggestions |
| POST | `/ai/suggest-tags` | Tag suggestions |
| POST | `/ai/rewrite-meta` | Yoast / Rank Math meta rewrite |
| POST | `/ai/products/generate` | WooCommerce product AI |
| GET/POST | `/ai/prompts` | List / create prompt templates |
| GET | `/ai/generations` | Generation log |
| POST | `/ai/images/generate` | Queue an image job |
| GET | `/ai/images/status/{id}` | Poll job status |

Streaming: `admin-ajax.php?action=waipress_ai_stream` (SSE).

### Forms & automations

| Method | Endpoint |
| --- | --- |
| GET/POST | `/forms` |
| GET/PATCH | `/forms/{id}` |
| POST | `/forms/generate` |
| POST | `/forms/{id}/submit` (public, rate-limited, honeypot) |
| GET/POST | `/automations` |
| GET | `/automations/{id}` |
| GET/POST | `/email-templates` |

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

### Digital Store

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
| GET | `/download/{token}` | public (HMAC-signed) |

### Search, migration, webhooks

| Method | Endpoint |
| --- | --- |
| POST | `/search/semantic` |
| POST | `/migration/scan` |
| POST | `/migration/start` |
| GET | `/migration/status/{id}` |
| GET/POST | `/webhooks/whatsapp` |
| POST | `/webhooks/telegram` |
| GET/POST | `/webhooks/instagram` |

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
wp_wai_coupons               wp_wai_licenses
wp_wai_forms                 wp_wai_form_submissions
wp_wai_email_templates       wp_wai_automations
wp_wai_automation_runs       wp_wai_canned_replies
```

A `wai_knowledge` custom post type backs the chatbot knowledge base.

---

## Admin pages

Top-level menus registered under `admin.php?page=…`:

- `waipress-ai` — AI Center (Generate / Prompts / AI Forms / Log / Settings)
- `waipress-messaging` — Messaging Hub (Inbox / Channels / **Add channel** wizard)
- `waipress-crm` — CRM (Contacts / Deals / **Automations** / **Email Templates**)
- `waipress-chatbot` — Chatbot (Configuration / Live Sessions / Knowledge Base)
- `waipress-shop` — Digital Store (Products / Orders / Licenses / Coupons)
- `tools.php?page=waipress-migration` — Migration tool

Admin screens are split: stable core screens (inbox, CRM, chatbot) load React SPA bundles; rapidly-iterating ones (Forms, Automations, Email Templates, Channels wizard) are server-rendered PHP so they ship without a JS build step.

---

## Custom cron

WAIpress registers a `waipress_minute` schedule (60 s) and hooks:

- `waipress_process_jobs` — image generation, embedding ingestion.
- `waipress_run_automations` (also triggered by `waipress_process_jobs`) — advances each pending automation run by one step.
- `WAIpress_Messaging::auto_assign_sweep` — rebalances unassigned conversations across active agents.

For predictable execution on production sites, disable WP-Cron and trigger from system cron:

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

Verify-token and signing secret are configured per-channel via the **Messages → Add channel** wizard. Tokens are encrypted at rest.

---

## Uninstall behavior

Deleting the plugin from the WordPress UI runs `uninstall.php`, which:

1. Deletes every `waipress_*` option (site and network).
2. Drops every `wp_wai_*` custom table.
3. Clears all `_transient_waipress_*` transients.
4. Unschedules the `waipress_process_jobs` cron event.

Deactivation is non-destructive — only the cron event is removed.

---

## Development

```
waipress/
├── waipress.php                    # bootstrap
├── uninstall.php                   # clean removal
├── includes/
│   ├── class-waipress.php                # main dispatcher
│   ├── class-waipress-ai*.php            # AI providers & service
│   ├── class-waipress-automations.php    # trigger → action engine
│   ├── class-waipress-channels-wizard.php
│   ├── class-waipress-chatbot.php
│   ├── class-waipress-chatbot-tools.php  # commerce intent for chatbot
│   ├── class-waipress-commerce.php
│   ├── class-waipress-crm.php
│   ├── class-waipress-cron.php
│   ├── class-waipress-crypto.php         # AES-256-GCM for tokens
│   ├── class-waipress-digital-store.php
│   ├── class-waipress-email.php
│   ├── class-waipress-embeddings.php
│   ├── class-waipress-form-bridge.php    # WPForms/Gravity/CF7/Forminator
│   ├── class-waipress-forms.php          # native AI form builder
│   ├── class-waipress-images.php
│   ├── class-waipress-messaging.php
│   ├── class-waipress-migration.php
│   ├── class-waipress-outbound.php       # WA/TG/IG senders
│   ├── class-waipress-rest.php
│   ├── class-waipress-settings.php
│   ├── class-waipress-sse.php
│   ├── class-waipress-upsell.php
│   ├── class-waipress-webhooks.php
│   ├── class-waipress-woocommerce.php
│   ├── class-waipress-yoast.php
│   └── waipress-schema.php
├── assets/
│   ├── build/                # compiled React bundles
│   └── js/                   # adapters
└── docker/                   # optional dev / self-host stack
```

### Extension hooks

- `waipress_trigger( $type, $context )` — fire a workflow.
- `waipress_form_submitted( $contact_id, $payload )` — bridged to the `form_submitted` trigger.
- `waipress_order_paid( $order_id )` — fired by commerce when an order is marked paid (used by the Digital Store to issue licenses).
- `waipress_email_sender` — override the email transport with a callable `($to, $subject, $body, $headers)`.
- `waipress_embedding_post_types` — filter the post-type list that the embedding scanner ingests.

---

## License

WAIpress is released under the **GPL-2.0-or-later** license. See [`LICENSE.txt`](LICENSE.txt).

---

## Author

**Daniel Moya** — <https://danimoya.com>
