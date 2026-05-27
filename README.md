# WooCommerce Maya Gateway

Production-grade WooCommerce payment gateway for [Maya](https://maya.ph)
(Philippines). Hosted checkout, signed webhooks, manual capture,
void / refund, block-based and classic checkout, HPOS, translatable.

## Requirements

- PHP **8.3+**
- WordPress **6.7+**
- WooCommerce **10.6+** (tested up to 10.7)

## Features

- Hosted Maya Checkout flow — customer enters card / wallet on a
  Maya-hosted page, signed webhook drives the order state machine.
- **Manual capture** with four modes (`none` / `normal` / `final` /
  `preauthorization`); Capture button + panel on the order edit screen.
- **Smart refund / void**: full void when Maya still permits it,
  otherwise refund; partial refunds across multiple captures are split
  chronologically.
- **Webhook reception** with RSA-SHA256 signature verification, ±300s
  timestamp tolerance, source-IP allowlist. REST endpoint
  (`/wp-json/wc-maya/v1/webhook`) primary, `wc-api=maya_webhook` shim
  for compatibility.
- **Idempotent webhook registration** — saving the gateway settings
  reconciles the five managed events with Maya Manager; unmanaged
  webhooks on the same account are left alone.
- **Block-based Cart and Checkout** integration alongside the classic
  shortcode checkout.
- **"Maya events" admin log viewer** under WooCommerce → Status, with
  level + free-text filters.
- **Action Scheduler retry** safety net for transient dispatch failures
  (order DB lag, Maya lookup hiccups) with exponential backoff.
- **Local-dev webhook simulator** — admin button posts a forged signed
  payload to the local endpoint with a bypass header, so the dispatch
  pipeline can be exercised without a public tunnel.
- **Test connection** probe — verifies both API keys end-to-end against
  Maya's sandbox before the first real order.
- **HPOS** (`custom_order_tables`) and `cart_checkout_blocks`
  compatibility declared.
- Translatable: bundled `languages/wc-maya-gateway.pot` covering 139
  strings.

## Installation

### From a release build

1. Download the latest `wc-maya-gateway-<version>.zip` from the project's
   releases page.
2. WordPress admin → Plugins → Add New → Upload Plugin → choose the zip.
3. Activate.
4. WooCommerce → Settings → Payments → **Maya Checkout** → enter your
   sandbox or production keys, click *Test connection*, then *Save changes*.
   Saving (with both keys present and the gateway enabled) automatically
   registers the five managed webhooks in your Maya Manager account.

### From source (developers)

```bash
git clone https://github.com/TaniKyuun/woocommerce-maya-gateway.git \
    wp-content/plugins/woocommerce-maya-gateway
cd wp-content/plugins/woocommerce-maya-gateway
composer install
```

Then activate in WP admin as above.

## Configuration

| Setting | What it does |
| --- | --- |
| **Enable / Disable** | Master switch for the gateway. |
| **Title / Description** | Shown to the customer on classic + block checkout. |
| **Sandbox mode** | Toggles between Maya's sandbox (`pg-sandbox.paymaya.com`) and production (`pg.maya.ph`). |
| **Public key / Secret key** | Checkout-product API keys from Maya Manager → Developers. Sandbox shared keys are documented at developers.maya.ph. |
| **Test connection** | Creates a small sandbox checkout + lists registered webhooks. Confirms both keys before merchant attempts a real order. |
| **Debug log** | Writes outgoing requests + successful response bodies to the `wc-maya-gateway` log channel (off by default). |
| **Manual capture** | Choose `None` for auto-capture, or `NORMAL` / `FINAL` / `PREAUTHORIZATION` to authorize-now-capture-later. |
| **Local dev webhook URL** | Optional. Public tunnel URL (ngrok / cloudflared) used as the callback host while developing locally. |
| **Registered webhooks (live)** | Live read-back of every webhook on the Maya account, marked **managed** or **external**. |
| **Simulate webhook** | Sandbox-only. Posts a forged payload at the local endpoint with a bypass header. |

## Architecture

The plugin is organized by responsibility — see
[docs/architecture.md](docs/architecture.md) for the file map and the
"which file do I open?" table. Each phase of the rebuild has its own
tour doc under [docs/rebuild-overview/](docs/rebuild-overview/).

## Development

### Run the test suite

```bash
./vendor/bin/pest
```

The plugin ships **200+ Pest unit tests, ~600 assertions**, all pure-
function or Brain-Monkey-stubbed. No WordPress test scaffold required.

### Format

```bash
composer format       # apply php-cs-fixer
composer format:check # CI: fail on drift
```

### Regenerate the .pot translation template

```bash
php bin/make-pot.php
```

Extracts every `__() / _e() / esc_html__() / esc_attr__() / _n() / _x()`
call across `src/` and `templates/` into
`languages/wc-maya-gateway.pot`.

### Build a release zip

```bash
bin/build-release.sh
```

Produces `dist/wc-maya-gateway-<version>.zip` containing only the
runtime files: `src/`, a re-`composer install --no-dev`-ed `vendor/`,
`assets/`, `templates/`, `languages/`, the main plugin file, README,
LICENSE, CHANGELOG. Dev / docs / tests / bin are excluded.

## License

GPL-3.0
