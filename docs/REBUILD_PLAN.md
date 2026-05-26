# Plan: rebuild `woocommerce-maya-gateway` to production quality

The current plugin is a clean scaffold. The old plugin (`wc-maya-payment-gateway`) is a feature-complete but monolithic implementation. This plan ports every feature of the old plugin into the new structure, modernizes the parts that were never great in the old plugin, and avoids backwards-compat baggage.

## Phase progress

| # | Phase | Status | Tour |
| --- | --- | --- | --- |
| 1 | Foundation refactor | ✅ Done (2026-05-26) | [rebuild-overview/PHASE1-TOUR.md](rebuild-overview/PHASE1-TOUR.md) |
| 2 | Webhook reception | ✅ Done (2026-05-26) | [rebuild-overview/PHASE2-TOUR.md](rebuild-overview/PHASE2-TOUR.md) |
| 3 | Webhook registration | ✅ Done (2026-05-26) | [rebuild-overview/PHASE3-TOUR.md](rebuild-overview/PHASE3-TOUR.md) |
| 4 | Payment processing | ✅ Done (2026-05-26) | [rebuild-overview/PHASE4-TOUR.md](rebuild-overview/PHASE4-TOUR.md) |
| 5 | Manual capture | ✅ Done (2026-05-26) | [rebuild-overview/PHASE5-TOUR.md](rebuild-overview/PHASE5-TOUR.md) |
| 6 | Refund + void | ⏳ Pending | — |
| 7 | WC Blocks support | ⏳ Pending | — |
| 8 | Polish + release | ⏳ Pending | — |

## Per-phase tour-doc convention

When a phase completes, ship a tour doc at
`docs/rebuild-overview/PHASE<N>-TOUR.md` modeled on
[rebuild-overview/PHASE1-TOUR.md](rebuild-overview/PHASE1-TOUR.md). It is the
narrative companion to that phase's PR — written for someone who *wasn't*
following the day-to-day work and needs to understand both *what* changed
and *why* each shape was chosen.

Sections every PHASE-TOUR doc should include:

- **One-sentence summary** at the top — the phase's purpose, plainly.
- **Definition-of-done — confirmed.** Restate the DoD from this plan and show
  what was actually delivered (test counts, file additions, lint status).
- **Before / after file tree.** A visual diff so the reader sees the
  structural shape without reading every PR.
- **Walkthrough of new files / classes.** What it does, why it's shaped that
  way, code excerpt. Each subsystem gets a section.
- **Notable refactors.** When existing code moved/changed, show before/after
  and explain the motivation.
- **Anti-patterns deliberately avoided.** Anything tempting we said no to,
  with reasoning. This is load-bearing for future-you's "while we're in
  here…" impulses.
- **Real-world bugs caught**, if any. Mini case-studies — what failed during
  testing, root cause, the fix, test added.
- **Test coverage delta** — table of new test files and what they cover.
- **End-to-end walkthrough** of at least one user flow through the new
  structure, with arrows showing where each new file is touched.
- **Try it yourself** — repro/verify instructions (run tests, manual smoke
  test, log inspection).
- **Where to read next** — cross-links to the master plan,
  architecture.md, the previous phase tour, and the next phase's tour once
  it exists.

Phase 1's tour also serves as the **comprehensive codebase primer** (it
absorbs the WordPress/WooCommerce vocabulary, plugin-loading mechanics,
testing primer, and glossary that a brand-new contributor needs). Later
phase tours should be smaller delta docs that assume the reader has read
Phase 1's tour first — don't re-explain DTOs, hooks, the settings API,
etc. in every doc.

Tone: in-depth, primer-style, written for a junior developer.

## Vision

A blocks-ready, signature-verified, fully-tested Maya gateway. PHP 8.3, WC 10.7+, WP 6.7+. Old plugin retired at end.

## Guiding constraints

- **Old plugin is the spec, not the template.** Borrow contracts (event names, signature algorithm, capture payload shape, IP lists, RSA public keys). Discard procedural shape.
- **One phase = one mergeable PR.** Each phase ends with green tests and a working subset usable in admin.
- **No backwards-compat with old plugin.** Option keys, meta keys, route names may diverge. Easier than smuggling Cynder aliases forward.
- **Modern WC patterns:** HPOS (already declared), WC Blocks payment method, Action Scheduler for webhook retries, REST endpoint (primary) with `wc-api` shim (fallback), typed enums.

## Target architecture (end state)

```
src/
├── Plugin.php
├── Admin/
│   ├── AdminAssets.php
│   ├── FormFields.php
│   ├── FieldRenderers.php
│   ├── OrderActions/
│   │   ├── CaptureButton.php          # add_action_buttons hook
│   │   └── CapturePanel.php           # order_totals_after_total
│   └── Ajax/
│       ├── TestConnection.php
│       ├── RefreshWebhooks.php
│       └── CapturePayment.php
├── Api/
│   ├── MayaApiClient.php              # transport only: request(), auth, logging
│   └── Endpoints/
│       ├── Checkouts.php              # createCheckout
│       ├── Payments.php               # getPaymentViaRrn, capture, void, refund
│       └── Webhooks.php               # list/create/delete
├── Blocks/
│   ├── MayaBlocksPaymentMethod.php    # AbstractPaymentMethodType
│   └── assets/                        # block JS bundle
├── Gateway/
│   ├── MayaGateway.php                # thin WC bridge
│   ├── PaymentProcessor.php           # builds checkout payload + create + persist meta
│   ├── ReturnHandler.php              # customer redirect from Maya
│   └── RefundProcessor.php            # void/refund decision tree
├── Settings/
│   └── SettingsHelper.php
├── Util/
│   ├── Logger.php
│   └── IdempotencyKey.php
├── Value/                              # immutable DTOs
│   ├── Money.php                       # amount + currency
│   ├── CheckoutSession.php             # API response wrapper
│   ├── PaymentRecord.php               # API response wrapper
│   ├── AuthorizationType.php           # enum: NONE/NORMAL/FINAL/PREAUTHORIZATION
│   └── WebhookEvent.php                # enum: PAYMENT_SUCCESS/FAILED/EXPIRED/AUTHORIZED/...
└── Webhook/
    ├── WebhookHandler.php              # routes inbound REST/wc-api hits
    ├── SignatureVerifier.php           # RSA-SHA256 against PublicKeyBundle
    ├── PublicKeyBundle.php             # Maya's sandbox + prod RSA keys
    ├── PayloadFlattener.php            # `key.subkey=value` sorted-join algorithm
    ├── TimestampVerifier.php           # 300s tolerance
    ├── IpAllowlist.php                 # 4 IPs total
    ├── EventDispatcher.php             # WebhookEvent → WC order action
    └── Simulator.php                   # local-dev forged payload poster
```

## Phase plan

Each phase has a hard scope. No scope creep across phases.

### Phase 1 — Foundation refactor ✅ Done

Mechanical splits with **no behavioral change** to set up everything that follows.

- Split `MayaApiClient` transport from per-endpoint methods. Move endpoint methods into `Api/Endpoints/*`.
- Add `Value/*` DTOs and the two enums (`AuthorizationType`, `WebhookEvent`).
- Add `Util/IdempotencyKey` (UUID v4 + Maya `requestReferenceNumber` builder).
- Tests: round-trip JSON ↔ DTO for each value object.

**DoD:** 8 existing tests still pass + ~10 new DTO tests.

**Delivered:** 44 tests passing (was 8), 96 assertions, lint clean. Plus two bonus
fixes from real sandbox testing: 36-char `requestReferenceNumber` cap honored;
Maya per-field validation errors surfaced via `format_parameter_details`. Full
walkthrough: [rebuild-overview/PHASE1-TOUR.md](rebuild-overview/PHASE1-TOUR.md).

### Phase 2 — Webhook *reception* (read-only side) ✅ Done

Receive and verify, but don't yet dispatch business logic.

- `PublicKeyBundle` constants (the four RSA PEMs from old plugin lines 39-87).
- `PayloadFlattener` — recursive `key.subkey=value` flatten + ASCII sort + `&`-join + `&nonce=...` suffix. Pure function, unit-tested with golden samples (capture real samples from a sandbox tx, store as fixtures).
- `SignatureVerifier` — calls `openssl_verify(..., 'sha256WithRSAEncryption')`, returns bool. `TimestampVerifier` (±300s). `IpAllowlist`.
- `WebhookHandler` becomes a REST route `/wp-json/wc-maya/v1/webhook` (POST) + a `wc-api=maya_webhook` shim. Both call the same handler. Verification runs first; on success the parsed event is logged and a 200 returned. **No order updates yet.**
- `Simulator` exposed via an admin button "Simulate webhook" with status options (success/failed/expired) and a target order — POSTs a forged payload to our own endpoint with `X-Simulated-Webhook: true` bypass.

**DoD:** Fire a sandbox payment → see "would dispatch `PAYMENT_SUCCESS` for order N" in the log. Simulate button works locally without tunnel.

**Delivered:** 79 tests passing (was 44 → +35), 186 assertions, lint clean.
All seven verification primitives shipped (`PublicKeyBundle`, `PayloadFlattener`,
`SignatureVerifier`, `TimestampVerifier`, `IpAllowlist`, `WebhookHandler`,
`Simulator`). REST route + `wc-api` shim both live; signature round-trip tested
against a real RSA keypair generated per-test. Simulator button rendered in
sandbox-mode only and gated by `manage_woocommerce`. Full walkthrough:
[rebuild-overview/PHASE2-TOUR.md](rebuild-overview/PHASE2-TOUR.md).

### Phase 3 — Webhook *registration* (write side) ✅ Done

Now the merchant doesn't have to click around in Maya Manager.

- `Webhooks` endpoint class: `all()`, `create($event, $url)`, `delete($id)`. (`list` is a PHP reserved word — kept `all()` from Phase 1.)
- On gateway settings save (`process_admin_options` hook): list → delete any whose name is in our managed set → create five fresh ones (`CHECKOUT_SUCCESS`, `CHECKOUT_FAILURE`, `PAYMENT_SUCCESS`, `PAYMENT_FAILED`, `PAYMENT_EXPIRED`), all pointing at our computed `webhook_url()`.
- New custom field type `webhook_status_table` — shows currently-registered webhooks live (name, URL, age). Refresh button beside it for AJAX re-sync.
- Idempotent: re-running registration deletes only our managed set, not the user's other webhooks.

**DoD:** Save settings → Maya Manager shows the five entries pointing at the local-dev override URL (or `home_url()` if blank).

**Delivered:** 95 tests passing (was 82 → +13), 251 assertions, lint clean.
New `Value/WebhookRecord` DTO; `Api/Endpoints/Webhooks` extended with
`create()` + `delete()` (and `all()` now returns typed DTOs);
`Webhook/Registrar` orchestrates idempotent reconcile (delete managed →
recreate five); `MayaGateway::process_admin_options()` overridden to trigger
reconciliation on settings save with WC_Admin_Settings notices on success
/ partial / failure; new `webhook_status_table` field type with
client-side AJAX fetch via `Admin/Ajax/RefreshWebhooks`. Full walkthrough:
[rebuild-overview/PHASE3-TOUR.md](rebuild-overview/PHASE3-TOUR.md).

### Phase 4 — Payment processing (no manual capture yet) ✅ Done

The happy path: customer can actually pay with sandbox cards.

- `PaymentProcessor`: builds the full checkout payload (totals, buyer, shipping, items, redirect URLs), calls `Checkouts::create`, persists `_maya_checkout_id`, `_maya_idempotency_key`, returns `result: success, redirect: <Maya URL>`.
- `ReturnHandler`: on `wc-api=maya_return`, validates the `order` query arg, marks the order `processing` with note "Awaiting webhook confirmation", redirects to `get_checkout_order_received_url()`. Webhook arrives separately and calls `payment_complete()`.
- `EventDispatcher` now wires up: `PAYMENT_SUCCESS` (matching amount) → `payment_complete($paymentId)`. `PAYMENT_FAILED` / `PAYMENT_EXPIRED` / `AUTH_FAILED` → `update_status('failed')`. Amount mismatch → log error, leave order alone.
- `MayaGateway::process_payment` becomes a one-liner delegating to `PaymentProcessor`.

**DoD:** Sandbox card flow works end-to-end. Order transitions: `pending` → `processing` (return) → `completed` (webhook).

**Delivered:** 109 tests passing (was 95 → +14), 349 assertions, lint
clean. `Gateway/PaymentProcessor` composes the full Maya checkout payload
(shipping-falls-back-to-billing, line items, redirects), calls
`Checkouts::create`, persists `_maya_checkout_id` + `_maya_idempotency_key`,
returns the WC `[result, redirect]` tuple. `Gateway/ReturnHandler` handles
`?wc-api=maya_return`: idempotent flip to `processing` on success, redirect
to `get_checkout_order_received_url()`; failed → notice + back to payment
page. `Webhook/EventDispatcher` wired into `WebhookHandler::process()` —
`PAYMENT_SUCCESS` with matching amount → `payment_complete()`; mismatch →
log + order note + leave alone; `PAYMENT_FAILED` / `PAYMENT_EXPIRED` /
`AUTH_FAILED` → `update_status('failed')`; already-paid orders skipped for
webhook-retry idempotency. `MayaGateway::process_payment()` is now a
delegate. Full walkthrough:
[rebuild-overview/PHASE4-TOUR.md](rebuild-overview/PHASE4-TOUR.md).

### Phase 5 — Manual capture (authorize-then-capture) ✅ Done

- Add `manual_capture` select to `FormFields` (`none`/`normal`/`final`/`preauthorization`).
- `PaymentProcessor` honors it: when not `none`, adds `authorizationType: <UPPER>` to the checkout payload and stores `_maya_authorization_type` on the order.
- `Payments::capture($paymentId, $payload)` endpoint.
- `Admin/OrderActions/CaptureButton` — adds "Capture" next to the refund button when an `AUTHORIZED + canCapture` payment is found via `Payments::getByRrn`.
- `Admin/OrderActions/CapturePanel` — replaces the inline `views/manual-capture.php` from the old plugin with a proper template at `templates/admin/capture-panel.php`.
- `Admin/Ajax/CapturePayment` — receives `order_id + capture_amount`, validates against `amount_authorized - amount_captured`, calls `Payments::capture`. Returns updated balances.
- `EventDispatcher` extended: for orders with `_maya_authorization_type !== 'none'`, completes only when `amount === capturedAmount`, otherwise just adds a status note.

**DoD:** Authorize a sandbox payment → see Capture button on order → capture partial → balances update → capture remainder → order completes via webhook.

**Delivered:** 131 tests passing (was 109 → +22), 430 assertions, lint
clean. New `Api/Endpoints/Payments` (`get_by_rrn` + `capture`); new
`Gateway/CaptureProcessor` owning the validation + delegation business
logic; new `Admin/Ajax/CapturePayment` AJAX wrapper; new
`Admin/OrderActions/CaptureButton` + `CapturePanel` + template at
`templates/admin/capture-panel.php`; `FormFields` + `SettingsHelper`
gained the `manual_capture` enum-backed setting; `PaymentProcessor` adds
`authorizationType` to the payload + persists
`_maya_authorization_type` meta; `EventDispatcher` extended with the
manual-capture branch (full-capture → `payment_complete`, partial →
note-only, `AUTHORIZED` → note-only). `AdminAssets` now also enqueues on
order-edit screens (classic + HPOS). Full walkthrough:
[rebuild-overview/PHASE5-TOUR.md](rebuild-overview/PHASE5-TOUR.md).

### Phase 6 — Refund + void

- `Payments::void($paymentId, $reason)`, `Payments::refund($paymentId, $payload)`, `Payments::getRefunds($paymentId)`.
- `RefundProcessor` handles `process_refund`: smart-picks `void` (if `canVoid` + full amount) vs `refund`; handles partial refund split across multiple captured payments (port the algorithm from old plugin lines 953-1063, but isolated and unit-testable).
- Order notes record each void/refund with API IDs.

**DoD:** Refund flow works for: full void, full refund, partial refund single payment, partial refund split across two captures.

### Phase 7 — WC Blocks support

The classic checkout works after Phase 4; this phase adds Blocks.

- `Blocks/MayaBlocksPaymentMethod extends AbstractPaymentMethodType` — registers via `woocommerce_blocks_payment_method_type_registration`.
- Build a JS payment method bundle (`@wordpress/scripts`-based) — title/description/icon, no fields (hosted checkout).
- Use `wp.element` for the React payment method content.
- Compatibility flag declared in main plugin file via `FeaturesUtil::declare_compatibility('cart_checkout_blocks', ...)`.

**DoD:** A site using the block-based checkout sees Maya as a selectable payment method and the flow completes.

### Phase 8 — Polish, observability, release

- Extract all strings via `wp i18n make-pot` → ship `languages/wc-maya-gateway.pot`.
- Admin "Event log" viewer (a custom WC Status Tools tab) showing parsed webhook events with timestamps, signature-valid flag, dispatched action. Reads from a dedicated WC log channel.
- Action Scheduler: enqueue webhook reprocessing as scheduled actions so transient failures retry. (Maya already retries 4 times — this is a safety net for our side.)
- Browser tests with Playwright for: settings save + Test connection, checkout happy path, capture flow.
- CHANGELOG, README sections, release build (`composer install --no-dev && zip ...`).
- Retire old plugin: archive the repo, drop a redirect notice.

**DoD:** Production-installable build (composer-less zip), translatable, with audit log.

## Anti-patterns from the old plugin to NOT carry forward

| Old pattern | Why drop | Replacement |
| --- | --- | --- |
| 1,690-line `Maya_Gateway` class | Untestable, every concern coupled | Already split per architecture above |
| Procedural `include_once` chain (`maya-hooks.php`) | Hidden hook registration | Static `register()` methods called from `Plugin::init()` |
| `Maya_Gateway_Bootstrap::getInstance()` singleton | Cargo culted; PSR-4 + `plugins_loaded` suffices | Already gone in new plugin |
| Hardcoded `cynder_*` route aliases | Legacy of an even older plugin's branding | New routes only (`maya_webhook`, REST primary) |
| Inline `views/manual-capture.php` partial | Half-closed `<table>` tags, output-buffered weirdness | Proper template at `templates/admin/capture-panel.php` |
| `wp_send_json` from random hook functions | Cross-cutting AJAX scattered | Each AJAX action in its own `Admin/Ajax/*` class |
| `wc-api=` URL hack as primary endpoint | Pre-REST WC pattern | REST endpoint primary, `wc-api` shim for compat |
| Manual `array_search(...)` over meta_data | O(n) hunt every call | Typed getters: `get_meta('_maya_authorization_type')` (HPOS-friendly) |

## Testing strategy

| Test type | Tool | Phase introduced | Covers |
| --- | --- | --- | --- |
| Unit (Pest) | Pest + Brain Monkey + Mockery | 1 onward | DTOs, flattener, verifier, settings helper, dispatcher |
| Integration (Pest) | Pest + wp-tests-skeleton | 4 | API client against fixtures, order processing |
| Webhook fixtures | Static JSON in `tests/Fixtures/` | 2 | Real captured Maya payloads (sanitized) for golden tests |
| Browser (Playwright) | `@playwright/test` | 8 | Admin flows: save settings, test connection, capture, refund |

## Risk register

| Risk | Mitigation |
| --- | --- |
| Maya rotates RSA public keys | `PublicKeyBundle` is a class constant; document the refresh process; consider fetching from a Maya-hosted JWKS-style endpoint if/when they ship one |
| Maya API contract drift | Fixtures-as-spec; CI runs nightly against sandbox with a basic smoke test |
| Signature algorithm edge cases (nulls, empty arrays, booleans) | `PayloadFlattener` has explicit unit tests for every primitive type and nesting depth, ported from the old plugin's `flatten_object_to_string` shape |
| Manual-capture partial-refund algorithm regression | The void-vs-refund decision tree from old plugin lines 953-1063 ported with unit tests for each branch before deletion |
| WC Blocks integration is non-trivial | Build a tiny prototype in Phase 1's spike before locking the architecture |
| Race conditions: customer return arrives before webhook | Explicit state machine in `EventDispatcher` — `payment_complete()` is idempotent in WC; the return handler only sets `processing`, never `completed` |

## Per-phase checklist (run at the end of every PR)

- [ ] Tests green (`./vendor/bin/pest`)
- [ ] PHP lint clean (`phpcs`)
- [ ] `docs/architecture.md` updated (the "which file do I open?" table)
- [ ] `.claude/skills/maya-gateway-structure/SKILL.md` updated if structure shifted
- [ ] **`docs/rebuild-overview/PHASE<N>-TOUR.md` written** following the convention above
- [ ] Phase-progress table at the top of this file updated to ✅ with the date and tour link
- [ ] Phase header in this file flipped to "✅ Done" + "Delivered:" summary paragraph linking to the tour
- [ ] Manual sandbox smoke test recorded in PR description
- [ ] No leftover `wc_print_r()` or `error_log()` debugging artifacts

## Suggested timeline

If working on this part-time:

| Phase | Effort | Cumulative |
| --- | --- | --- |
| 1. Foundation | 1 day | 1d |
| 2. Webhook reception | 3 days | 4d |
| 3. Webhook registration | 1 day | 5d |
| 4. Payment processing | 2 days | 7d |
| 5. Manual capture | 2 days | 9d |
| 6. Refund + void | 2 days | 11d |
| 7. WC Blocks | 2 days | 13d |
| 8. Polish + release | 2 days | 15d |

≈3 weeks of focused part-time work. The biggest risk to that estimate is Phase 2 (signature verification has subtle correctness traps) and Phase 7 (Blocks docs are uneven).
