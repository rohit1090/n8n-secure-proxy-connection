# WP n8n Connector

![License](https://img.shields.io/badge/license-GPLv2%2B-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-21759b.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)
![n8n](https://img.shields.io/badge/n8n-Public%20API%20v1-ea4b71.svg)

A WordPress admin dashboard for browsing, activating, manually triggering, and inspecting your **n8n** workflows — without leaving wp-admin.

## Table of Contents

- [Features](#features)
- [Screenshots](#screenshots)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
  - [1. n8n-side setup](#1-n8n-side-setup)
  - [2. Plugin settings](#2-plugin-settings)
  - [3. Using constants instead of the database (recommended for production)](#3-using-constants-instead-of-the-database-recommended-for-production)
- [Usage](#usage)
  - [Workflows tab](#workflows-tab)
  - [Execution Logs tab](#execution-logs-tab)
  - [Node Pipeline canvas](#node-pipeline-canvas)
- [How "Trigger Workflow" actually works](#how-trigger-workflow-actually-works-master-webhook-router)
- [Known limitations](#known-limitations)
- [Troubleshooting](#troubleshooting)
- [File structure](#file-structure)
- [Security notes](#security-notes)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [License](#license)

## Features

- 📋 Browse all workflows from your n8n instance in a clean card list
- 🔀 Drag-and-drop to reorder workflows (order is saved per WordPress site)
- ⚡ One-click manual trigger via a configurable webhook router
- 🟢 Activate / deactivate workflows directly, with real error messages surfaced instead of silent failures
- 📜 Execution log viewer with resolved workflow names and human-readable timestamps
- 🕸️ Interactive node pipeline canvas — pan, zoom, and see how a workflow's nodes actually connect, without opening n8n
- 🔒 Nonce-verified, capability-gated AJAX endpoints (`manage_options` required for every action)
- ⚙️ Store credentials in `wp-config.php` constants instead of the database, if you prefer

## Screenshots

> Add your own screenshots here after installing, e.g. `screenshots/workflows-tab.png`, `screenshots/execution-logs.png`, `screenshots/node-canvas.png`.
> Left blank intentionally in this template — avoid committing screenshots that reveal a real API key, OAuth client ID, or your production n8n URL.

## Requirements

| | |
|---|---|
| WordPress | 5.0+ |
| PHP | 7.4+ |
| n8n | Any version with the **Public REST API** enabled (`/api/v1`) |
| n8n API Key | Created under **n8n → Settings → n8n API** |

## Installation

1. Download or clone this repository.
2. Upload the `wp-n8n-connector` folder to `/wp-content/plugins/`.
   - Or zip the folder and install via **Plugins → Add New → Upload Plugin** in wp-admin.
3. Activate **WP n8n Connector** from the Plugins screen.
4. Go to **WP n8n Connector → Settings** in the WordPress admin sidebar and fill in your connection details (see [Configuration](#configuration) below).

## Configuration

### 1. n8n-side setup

1. In n8n, go to **Settings → n8n API** and create an API key. Copy it — you won't be able to view it again.
2. Confirm your n8n instance's Public API is reachable from your WordPress server:
   - Self-hosted: usually your instance's base URL, e.g. `https://n8n.example.com`
   - n8n Cloud: `https://<your-subdomain>.app.n8n.cloud`
3. Set up a **Master Webhook Router** workflow (see [below](#how-trigger-workflow-actually-works-master-webhook-router)) — required for the "Trigger Workflow" button to actually do anything.

### 2. Plugin settings

Under the **Settings** tab in the plugin:

| Field | What it is |
|---|---|
| n8n Instance URL | The base URL of your n8n instance, e.g. `https://n8n.example.com` (no trailing `/api/v1`) |
| n8n API Key | The key generated in step 1 above |
| Master Webhook Router URL | The production webhook URL of your router workflow |

### 3. Using constants instead of the database (recommended for production)

Rather than storing these values in the WordPress database via the Settings screen, you can define them in `wp-config.php`:

```php
define('N8N_API_URL', 'https://n8n.example.com');
define('N8N_API_KEY', 'your-api-key-here');
define('N8N_MASTER_WEBHOOK', 'https://n8n.example.com/webhook/master-router');
```

When any of these constants are defined, the plugin uses them and ignores the corresponding Settings field. This is useful if you manage secrets outside the database (e.g. via your hosting provider's environment config) or want them excluded from database backups.

## Usage

### Workflows tab

- **Refresh List** pulls the current workflow list from n8n.
- Drag the ☰ handle to reorder — order is saved per WordPress site and layered on top of whatever n8n returns.
- The toggle activates/deactivates a workflow directly against n8n's API. If activation fails (most commonly because the workflow has no valid trigger node), the real reason from n8n is shown next to the toggle instead of failing silently.
- **Trigger Workflow** fires the workflow via your Master Webhook Router — independent of activation state, so you can manually trigger a workflow whether or not it's currently active.
- **Inspect Nodes** opens the node pipeline canvas.

### Execution Logs tab

Shows the most recent executions from n8n, with workflow names resolved (cached briefly to avoid extra API calls) and human-readable timestamps instead of raw ISO strings.

### Node Pipeline canvas

A lightweight pan-and-zoom view of a workflow's actual node graph, built from n8n's own node and connection data:

- **Drag** to pan
- **+ / − / Fit** buttons to zoom
- Node positions and connection lines mirror what you'd see in the n8n editor

## How "Trigger Workflow" actually works (Master Webhook Router)

The plugin does **not** call each workflow's individual webhook trigger. Instead, every click POSTs a single JSON body —

```json
{ "workflow_id": "abc123" }
```

— to the one **Master Webhook Router URL** configured in Settings. This keeps the plugin simple to configure (one URL, not one per workflow), but it means you need a small routing workflow in n8n to receive that call and dispatch it to the right target workflow.

A minimal router workflow looks like this:

```
Webhook (POST)
  → Execute Workflow (Source: By ID, Workflow ID: {{$json.body.workflow_id}})
    → Respond to Webhook
```

Set that workflow's **production** webhook URL as your Master Webhook Router URL in the plugin's Settings tab.

## Known limitations

- **No credentials management.** n8n's public REST API doesn't support listing, reading, or updating existing credentials — this is a deliberate n8n platform restriction (only n8n's own logged-in UI can display saved credential values), not something this plugin can work around. An earlier iteration of this plugin attempted a partial credentials tab; it was removed because it couldn't do what people actually needed.
- **Activation depends on n8n's own rules.** A workflow can't be activated if it has no valid trigger node — the plugin surfaces n8n's error message when this happens, but can't activate a workflow n8n itself would refuse to activate.
- **Execution log name resolution is capped per lookup** by n8n's API pagination; on very large instances, some workflow names may briefly show as raw IDs until the next cache refresh.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| "Trigger Workflow" shows success but nothing happens in n8n | Master Webhook Router isn't set up, isn't active, or the URL in Settings doesn't match its production webhook URL |
| Toggle reverts right after activating | n8n rejected activation — check the error text shown next to the toggle for the specific reason (commonly "no valid trigger node") |
| Execution Logs tab is empty | API key may be missing the Executions read scope, or there are no executions yet on the instance |
| Node canvas looks like nodes are stacked/overlapping | The workflow's nodes may share identical or near-identical saved positions in n8n; try **Fit**, or reposition nodes in n8n's own editor |

## File structure

```
wp-n8n-connector/
├── wp-n8n-connector.php                 # Core: dashboard, workflow list, toggles, logs, drag-and-drop
├── includes/
│   └── n8n-advanced-features.php        # Node Pipeline canvas data proxy
└── assets/
    ├── css/
    │   └── n8n-admin.css                # Dashboard, card, modal, and canvas styles
    └── js/
        ├── n8n-admin.js                 # Core JS: tabs, toggles, logs, sortable, escaping/date helpers
        └── n8n-advanced.js              # Node pipeline pan/zoom canvas renderer
```

## Security notes

- Every AJAX action is gated behind `check_ajax_referer()` and `current_user_can('manage_options')` — only WordPress admins can use any part of this plugin.
- All data rendered from n8n API responses is HTML-escaped client-side before insertion into the DOM.
- Prefer the [`wp-config.php` constants approach](#3-using-constants-instead-of-the-database-recommended-for-production) for your API key and webhook URL in production, especially on multi-admin sites.
- Never commit your actual `N8N_API_KEY`, instance URL, or webhook URL to a public repository, README, or screenshot.

## Changelog

### 1.7.0
- Renamed the plugin to **WP n8n Connector** (menu, dashboard heading, main file, and menu slug all updated)
- API key is no longer echoed back into the Settings page HTML in plaintext; the field now stays blank with a placeholder and only overwrites the stored key when a new value is submitted
- Hardened the workflow-order save endpoint against malformed/nested payloads
- Added missing empty-`workflow_id` validation on the trigger and activate/deactivate actions
- Replaced the default Dashicon menu icon with n8n's official logo mark (embedded as an SVG data URI)

### 1.6.2
- Removed the Credentials tab — n8n's API doesn't support the list/read operations it would have needed to be genuinely useful

### 1.6.1
- Fixed workflow activation failing with HTTP 415 (explicit `Content-Type: application/json` now sent on activate/deactivate calls)

### 1.6.0
- Added HTTP status-code checking to every n8n API proxy call, fixing several silent-failure bugs
- Execution logs now resolve and display workflow names (previously IDs only)
- Replaced the flat node list in "Inspect Nodes" with a pan/zoom node pipeline canvas
- Fixed a missing script dependency between core and advanced JS
- Consistent output escaping across all rendered n8n data

### 1.5.0
- Initial release — dashboard, drag-and-drop ordering, activation toggles, execution logs, modular advanced-features file

## Contributing

Issues and pull requests are welcome. Please:

1. Fork the repo and create a feature branch
2. Keep changes scoped — one fix/feature per PR
3. Test against a real n8n instance before submitting

## License

Licensed under the [GPLv2 (or later)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html), consistent with WordPress's own plugin licensing requirements. Add a `LICENSE` file with the full GPLv2 text to the repository root before publishing if you don't already have one.
