# Go-live & incident runbook — WooCommerce Maya Gateway

This is the operational companion to the code. The plugin can be technically
correct and still lose money if nobody watches it or knows what to do when a
webhook goes missing. Read this before taking real payments.

## Trust model (why this matters)

Maya is a **hosted** gateway: the customer pays on Maya's page, and the store is
told the result two ways — the customer's browser returning, and a separate
signed server-to-server **webhook**. **Only the signed webhook completes an
order.** The browser return never marks an order paid. Order state is
**monotonic: once paid, an order is never demoted** — a late or replayed
failure webhook is ignored (see `EventDispatcher::dispatch`).

## Pre-flight checklist (before enabling for customers)

- [ ] Run the test suite green: `ddev exec -d /var/www/html/web/app/plugins/woocommerce-maya-gateway vendor/bin/pest`.
- [ ] Sandbox keys entered; **Test mode ON**; place a full sandbox order and confirm the **webhook** (not the redirect) moves the order to *Processing/Completed*.
- [ ] Abandon a payment at Maya's page → order does **not** complete.
- [ ] Exercise: manual capture, partial capture, void, refund in sandbox; confirm each in both WooCommerce and the Maya dashboard.
- [ ] Break the webhook on purpose: wrong signature, expired timestamp (>5 min), off-allowlist IP → each is rejected and appears in **WooCommerce → Status → Logs → wc-maya-gateway**.
- [ ] Replay a captured `PAYMENT_FAILED` after a success → order stays paid (monotonic-state check).
- [ ] Confirm **Action Scheduler is running** (WooCommerce → Status → Scheduled Actions): a stalled cron means the webhook-replay safety net never fires.
- [ ] Host clock is on **NTP** (the webhook timestamp check is ±5 min; large clock skew rejects every webhook — see the tolerance filter below only as a last resort).
- [ ] Switch to production keys; **Test mode OFF**; do **one** low-value real purchase with your own card, then refund it, watching the money move and return.

## Kill switch

Disable the gateway: **WooCommerce → Settings → Payments → Maya Checkout → toggle off.**
New orders can no longer select Maya. In-flight webhooks for existing orders
still process (idempotent + monotonic, so this is safe).

## Rollback

- **Config rollback:** flip Test mode back on / disable the gateway (above).
- **Version rollback:** deactivate the plugin, reinstall the previous release zip
  (`bin/build-release.sh` builds one), reactivate. Settings persist in
  `woocommerce_maya_checkout_settings`; order data is untouched. Decide *who*
  authorizes a rollback and how they're reachable out-of-hours **before** launch.

## Incident: "money left my account but I have no order"

This is the failure mode to rehearse. Cause is usually a webhook that was
delayed, dropped, or rejected.

1. Find the order (by customer email / amount / time) in **WooCommerce → Orders**.
2. Open the order and read the **Maya event history** (per-order webhook log,
   meta `_maya_webhook_log`) plus **Status → Logs → wc-maya-gateway** — did a
   webhook arrive? Was it rejected (signature/timestamp/IP), or never seen?
3. Cross-check the **Maya Manager dashboard** for a matching successful payment
   by reference number (the WC order id) — this is the source of truth that the
   charge is real.
4. If the charge is real but the order didn't complete: either let Maya's retry
   / the plugin's Action Scheduler replay land, use **Simulate webhook** (sandbox
   only) to reproduce, or complete the order manually and reconcile.
5. If the charge should not stand: issue a refund (below).

**Who may refund:** refunds go through WooCommerce's native order **Refund**
button, which requires the `manage_woocommerce` capability and a nonce — so only
shop managers/admins can trigger one, and every refund is recorded as an order
note. There is no other refund path in this plugin. Refund amounts are validated
against Maya (partial voids are rejected; the planner can never refund more than
was captured), and over-refunds are additionally capped by WooCommerce and
rejected by Maya server-side.

## Operational filters (no release needed)

- **Maya changed its signing key:** add the new PEM via `wc_maya_webhook_public_keys`.
- **Maya changed its egress IPs:** patch via `wc_maya_webhook_allowed_ips`
  (returning `[]` disables the IP check entirely — signature verification still
  applies). This is code-level on purpose; it is not an admin toggle.
- **Persistent clock skew you can't fix via NTP:** widen the freshness window via
  `wc_maya_webhook_timestamp_tolerance_ms` (cannot be narrowed below the 5-min
  default). Prefer fixing the clock.
- **React to a confirmed payment:** hook the `wc_maya_payment_confirmed` action
  (`$order_id, $payload`).

## Logs & PII

Card data and customer PII are redacted from logs (`Logger::REDACT_KEYS`).
**Logs written before upgrading to the version that added webhook-payload
redaction may still contain PII** — rotate/purge old `wc-maya-gateway-*.log`
files before sharing them.

## Known follow-ups (deferred, not blockers)

- **Settlement reconciliation view:** the per-order webhook snapshot (`_maya_webhook_log`)
  already captures the data; a "WC paid vs Maya settlement" report is not yet built.
- **Automated/remote key rotation:** keys are bundled + filterable; there is no
  auto-fetch from Maya yet.
- **Simultaneous duplicate-delivery side-effects:** monotonic state prevents double
  payment; a truly concurrent double-delivery could still produce a duplicate order
  note (never a double charge). A DB-unique-constraint claim is the future fix.
