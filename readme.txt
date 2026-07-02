=== WooCommerce Maya Gateway ===
Contributors: roguetechphilippines
Tags: woocommerce, payment gateway, maya, paymaya, philippines
Requires at least: 7.0
Tested up to: 6.7
Requires PHP: 8.3
WC requires at least: 10.6
WC tested up to: 10.7
Stable tag: 1.0.0
License: GPL-3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Accept Maya (cards, e-wallets, QR Ph) payments in WooCommerce via Maya's hosted checkout, with signed-webhook order completion, manual capture, and refunds.

== Description ==

WooCommerce Maya Gateway integrates the Maya (formerly PayMaya) hosted checkout
into WooCommerce for Philippine stores. Customers pay on Maya's secure page, so
the store never handles card data (minimal PCI scope).

**Order completion is driven by Maya's signed server-to-server webhook, not the
browser redirect** — a forged return URL cannot mark an order paid. Order state
is monotonic: once an order is paid it is never demoted by a late or replayed
failure notification.

Features:

* Hosted checkout for cards, e-wallets, and QR Ph.
* Classic and block-based (Cart/Checkout blocks) checkout support.
* HPOS (High-Performance Order Storage) compatible.
* Webhook verification: timestamp freshness + RSA-SHA256 signature + source-IP allowlist.
* Manual capture (authorize now, capture later), partial captures, void, and refunds.
* Action Scheduler-backed retry safety net for transient webhook-processing failures.
* Per-order Maya event history and a dedicated admin event-log viewer.
* Card data and customer PII redacted from logs.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` (or install the release zip).
2. Activate it through the **Plugins** screen.
3. Go to **WooCommerce → Settings → Payments → Maya Checkout**.
4. Enter your Maya public and secret keys. Start with **Test mode ON**.
5. Save — the plugin registers the required webhooks with Maya automatically.
6. Complete the go-live checklist in `docs/go-live-runbook.md` before disabling
   Test mode.

== Frequently Asked Questions ==

= A customer says they paid but there is no completed order. What do I do? =

See the incident playbook in `docs/go-live-runbook.md`. In short: check the
order's Maya event history and the `wc-maya-gateway` log, confirm the charge in
the Maya Manager dashboard by reference number, then let the retry/replay land
or complete/refund manually.

= Maya changed its signing key or webhook IPs. Do I need an update? =

No. Use the `wc_maya_webhook_public_keys` and `wc_maya_webhook_allowed_ips`
filters to patch keys/IPs without a release.

= Every webhook is being rejected as stale. =

Your server clock is likely off. Put the host on NTP. As a last resort widen the
window with the `wc_maya_webhook_timestamp_tolerance_ms` filter (it cannot be
narrowed below the 5-minute default).

== Developer hooks ==

* `wc_maya_webhook_public_keys` (filter) — RSA PEMs used to verify signatures.
* `wc_maya_webhook_allowed_ips` (filter) — source-IP allowlist; `[]` disables the IP check.
* `wc_maya_webhook_timestamp_tolerance_ms` (filter) — freshness window in ms.
* `wc_maya_payment_confirmed` (action) — fired with `($order_id, $payload)` on a confirmed payment.

== Changelog ==

= 1.0.0 =
* Initial release: hosted checkout, signed-webhook completion, manual capture,
  refunds, blocks support, HPOS support, retry queue, and event-log viewer.
* Hardening: monotonic "paid is a floor" order state, per-order webhook
  de-duplication ledger, filterable keys/IPs/timestamp tolerance, and expanded
  PII/card-data log redaction.
