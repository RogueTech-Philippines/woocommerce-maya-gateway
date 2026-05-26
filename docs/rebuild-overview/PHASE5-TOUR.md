# Phase 5 tour — Manual capture (authorize-then-capture)

Status: **DONE** (2026-05-26).

> The merchant picks a `manual_capture` mode → checkout *authorizes* the
> funds instead of charging them → a Capture panel on the order edit page
> lets the merchant capture part or all of the authorization → the
> PAYMENT_SUCCESS webhook promotes the order to `completed` once the
> cumulative captured amount matches the authorization.

Delta tour. Assumes you've read [PHASE1-TOUR.md](PHASE1-TOUR.md) through
[PHASE4-TOUR.md](PHASE4-TOUR.md).

---

## 1. Definition-of-done — confirmed

The rebuild plan asked for:

> Authorize a sandbox payment → see Capture button on order → capture
> partial → balances update → capture remainder → order completes via
> webhook.

What was shipped:

- **131 tests passing, 430 assertions** — net delta of **+22** over Phase 4's
  109-test baseline.
- PHP lint clean (`php-cs-fixer` + `php -l`).
- 5 new source files: `Api/Endpoints/Payments`, `Gateway/CaptureProcessor`,
  `Admin/Ajax/CapturePayment`, `Admin/OrderActions/CaptureButton`,
  `Admin/OrderActions/CapturePanel`.
- 1 new template: `templates/admin/capture-panel.php`.
- Extended: `FormFields`, `SettingsHelper`, `PaymentProcessor`,
  `EventDispatcher`, `AdminAssets`, `MayaGateway` (new
  `META_AUTHORIZATION_TYPE` constant), `Plugin` (three new
  `register()` calls).
- 2 new test files (`PaymentsTest`, `CaptureProcessorTest`) + 3 extended
  (`PaymentProcessorTest`, `EventDispatcherTest`, `SettingsHelperTest`).

---

## 2. Before / after file tree

```text
src/
├── Admin/
│   ├── AdminAssets.php              # EXTENDED: enqueue on order-edit screens too
│   ├── FormFields.php               # EXTENDED: manual_capture select
│   ├── Ajax/
│   │   └── CapturePayment.php       # NEW (Phase 5) — thin AJAX wrapper
│   └── OrderActions/                # NEW directory (Phase 5)
│       ├── CaptureButton.php        # NEW — "Capture" button beside Refund
│       └── CapturePanel.php         # NEW — capture form rendered under order totals
├── Api/
│   └── Endpoints/
│       └── Payments.php             # NEW (Phase 5) — get_by_rrn + capture
├── Gateway/
│   ├── MayaGateway.php              # EXTENDED: META_AUTHORIZATION_TYPE
│   ├── PaymentProcessor.php         # EXTENDED: authorizationType in payload + meta
│   └── CaptureProcessor.php         # NEW (Phase 5) — testable capture business logic
├── Settings/
│   └── SettingsHelper.php           # EXTENDED: manual_capture() accessor
└── Webhook/
    └── EventDispatcher.php          # EXTENDED: manual-capture branches

templates/                           # NEW directory
└── admin/
    └── capture-panel.php            # NEW — the capture form HTML

assets/
├── js/maya-admin.js                 # EXTENDED: attachCaptureFlow()
└── css/maya-admin.css               # EXTENDED: capture panel styles
```

```text
tests/Unit/
├── Api/Endpoints/PaymentsTest.php     # NEW (5 cases)
├── Gateway/
│   ├── CaptureProcessorTest.php       # NEW (8 cases)
│   └── PaymentProcessorTest.php       # EXTENDED (+3 cases for manual_capture)
├── Settings/SettingsHelperTest.php    # EXTENDED (+2 cases for manual_capture())
└── Webhook/EventDispatcherTest.php    # EXTENDED (+4 cases for manual-capture branches)
```

---

## 3. The manual-capture life cycle

When `manual_capture != none`, the order's life cycle changes shape:

```text
0. Merchant chooses "PREAUTHORIZATION" (or NORMAL/FINAL) on the gateway settings.

1. Customer pays → PaymentProcessor::build_payload() adds
   authorizationType: PREAUTHORIZATION to the createCheckout call.
   ↓
2. PaymentProcessor::process() persists _maya_authorization_type=preauthorization
   alongside the existing _maya_checkout_id + _maya_idempotency_key.
   ↓
3. Customer returns from Maya → ReturnHandler flips pending → processing.
   ↓
4. AUTHORIZED webhook → EventDispatcher::note_authorized() adds
   "Authorized. Use the Capture panel to capture funds." (no state change.)
   ↓
5. Merchant opens the order edit screen:
   ├── CaptureButton::should_render() does a live Payments::get_by_rrn lookup
   │       and confirms AUTHORIZED + canCapture is present
   │       → renders "Capture Maya payment" button beside Refund
   └── CapturePanel::render() does the same lookup, then includes
           templates/admin/capture-panel.php with the live
           authorized/captured/remaining trio
   ↓
6. Merchant clicks Capture, types an amount (defaults to remaining), submits.
   ↓
7. JS POSTs to wc_maya_capture_payment AJAX:
   └── CapturePayment::handle() → CaptureProcessor::capture()
           ├── validate amount > 0
           ├── Payments::get_by_rrn(<order_id>)
           ├── find AUTHORIZED + canCapture payment
           ├── validate amount ≤ (authorized - captured)
           ├── Payments::capture(payment_id, payload)
           ├── add_order_note(updated balances)
           └── return { amount_authorized, amount_captured, amount_remaining }
   ↓
8. PAYMENT_SUCCESS webhook arrives (async, from the capture):
   └── EventDispatcher detects _maya_authorization_type != none
           ├── if capturedAmount === amount → payment_complete()
           └── else → "partial capture confirmed: X of Y" note; status stays processing
   ↓
9. Repeat 6-8 for partial captures. The last capture's webhook is the one
   that promotes the order to completed.
```

The system has **two webhook sources of truth**:

- The capture API's response tells us what *should* have happened.
- The PAYMENT_SUCCESS webhook tells us what *did* happen — that's the
  authoritative signal, identical to Phase 4's immediate-capture flow.

The capture endpoint's response is informational; the order's state flip
waits for the webhook. Keeps the data flow consistent across both capture
modes.

---

## 4. New files

### 4.1 `Api/Endpoints/Payments`

```php
public function get_by_rrn(string $rrn): array|WP_Error;
public function capture(string $payment_id, array $payload): PaymentRecord|WP_Error;
```

Both authenticate with the Checkout *secret* key (Phase 1's tour flagged
the Payment Vault product as a separate key family, but Maya accepts the
Checkout secret for these `/payments/v1/*` endpoints in practice — the
legacy plugin's been doing it for years in production).

`get_by_rrn` URL-encodes the RRN; `capture` does the same with the payment
id. Both methods convert decoded responses into `PaymentRecord` DTOs so
downstream code never juggles raw arrays.

### 4.2 `Gateway/CaptureProcessor`

The business logic seam, separated from the AJAX handler so unit tests can
exercise validation + dispatch without booting `wp_send_json_*` exits.

```php
public function capture(WC_Order $order, float $amount): array|WP_Error;
public static function find_capturable_payment(array $payments): ?PaymentRecord;
```

The validation pipeline:

1. `amount > 0` (else `wc_maya_capture_invalid_amount`).
2. `Payments::get_by_rrn(order_id)` — bubble any transport error.
3. `find_capturable_payment()` returns the first `AUTHORIZED + canCapture: true`
   record (else `wc_maya_capture_no_authorized_payment`).
4. `amount ≤ (authorized − captured)` with 0.005 currency tolerance
   (else `wc_maya_capture_exceeds_remaining`).
5. `Payments::capture()` — bubble any transport error.
6. Add an order note with the updated cumulative balance.
7. Return the new `[amount_authorized, amount_captured, amount_remaining]`
   trio so the JS can re-render without a second API call.

`find_capturable_payment` is `public static` because both `CaptureButton`
and `CapturePanel` need to ask the same question — "is there capturable
authorization for this order?" — without paying a redundant Maya call.

### 4.3 `Admin/Ajax/CapturePayment`

Thin AJAX wrapper following the same pattern as `SimulateWebhook` /
`RefreshWebhooks`: permission + nonce + input validation, then delegates
to `CaptureProcessor`. Returns the success payload verbatim (so the JS
can `Number(data.amount_remaining).toFixed(2)` straight into the panel)
or the `WP_Error` `code` + `message`.

### 4.4 `Admin/OrderActions/CaptureButton`

Hooks `woocommerce_order_item_add_action_buttons`. Renders the "Capture
Maya payment" button only when *all* of these are true:

- Order's payment method is `maya_checkout`.
- `_maya_authorization_type` meta is anything other than `none`.
- A live `Payments::get_by_rrn` lookup finds an `AUTHORIZED + canCapture`
  payment.

The synchronous Maya call is acceptable here because the order edit
screen already runs many heavyweight queries and merchants reach it
infrequently. If Maya 4xxs or 5xxs, `should_render()` returns false
without crashing — worst case is a hidden button on a transient outage.

### 4.5 `Admin/OrderActions/CapturePanel`

Hooks `woocommerce_admin_order_totals_after_total`. Same gating as
CaptureButton, plus it reads the actual `PaymentRecord` so it can pass
`authorized / captured / remaining` Money values into the template.

`templates/admin/capture-panel.php` is a proper template file (not a
heredoc string inside the class), replacing the legacy plugin's inline
`views/manual-capture.php` partial with all its half-closed `<table>`
tags. The template is `include`-rendered with `$order`, `$auth_type`,
`$payment`, `$authorized`, `$captured`, `$remaining` in scope.

The panel is `hidden` by default (HTML attribute) and toggled visible by
the CaptureButton's click handler in `maya-admin.js`. If the button
isn't rendered (e.g. all funds already captured), the panel auto-reveals
so the merchant still sees the balances.

### 4.6 `Webhook/EventDispatcher` (extended)

Two new branches added on top of Phase 4's immediate-capture path:

```php
if (WebhookEvent::PaymentSuccess === $event) {
    return $auth_type->is_manual_capture()
        ? $this->complete_manual_capture($order, $payload)
        : $this->complete_payment($order, $payload);  // Phase 4 path
}

if (WebhookEvent::Authorized === $event && $auth_type->is_manual_capture()) {
    return $this->note_authorized($order, $payload);
}
```

- **`complete_manual_capture`** compares `payload['amount']` (the
  authorization total) against `payload['capturedAmount']` (cumulative
  captured). If equal within 0.005, `payment_complete()` fires; if less,
  a partial-capture note is added and the order stays in `processing`.
- **`note_authorized`** records "Authorized. Use the Capture panel to
  capture funds." (no state change — ReturnHandler already flipped to
  `processing`).

The `AUTHORIZED` arm only runs for manual-capture orders. For immediate-
capture orders an `AUTHORIZED` webhook would be unexpected; it falls
through to the catch-all `ignored` branch and the merchant sees nothing
weird happen.

---

## 5. Settings + meta

**Field:**

```php
'manual_capture' => [
    'type'    => 'select',
    'default' => 'none',
    'options' => [
        'none'             => 'None — auto-capture at checkout (recommended)',
        'normal'           => 'NORMAL — authorize at checkout, capture later (full amount)',
        'final'            => 'FINAL — authorize for the final amount only (no partial captures)',
        'preauthorization' => 'PREAUTHORIZATION — authorize, then capture in one or more pieces',
    ],
]
```

`SettingsHelper::manual_capture()` returns the `AuthorizationType` enum
case (defaults to `None` for missing/typo'd values via
`AuthorizationType::from_setting()`).

**Meta:**

- `MayaGateway::META_AUTHORIZATION_TYPE = '_maya_authorization_type'` —
  stores the lowercase enum value (`'none'`, `'normal'`, `'final'`,
  `'preauthorization'`). Used by `EventDispatcher` to pick the
  manual-capture branch and by `CaptureButton`/`CapturePanel` to gate
  rendering.

The meta is set on *every* order's PaymentProcessor::process(), including
the default `'none'` case, so downstream code can `from_setting()` it
safely without null-checking the meta lookup.

---

## 6. Anti-patterns deliberately avoided

| Tempted to | Why we didn't |
| --- | --- |
| Have CaptureProcessor flip the order to `completed` after a successful capture | Source-of-truth is the webhook, same as Phase 4. The capture API can succeed but the webhook take 20s to land; the order's completion needs to wait for the signed signal so other plugins fire once. |
| Persist Maya's payment id on the order and skip the `get_by_rrn` lookup in CaptureButton/CapturePanel | A single order can have multiple Maya payments over its lifetime (refund-and-recapture, multiple auths). The lookup is the only safe way to find the *currently capturable* payment. |
| Render the Capture panel via PHP echo strings inside `CapturePanel.php` | Plan explicitly calls for a template file. Templates are easier to override and easier to scan for HTML mistakes. The legacy plugin's inline `<table>` had a missing `</tr>` we found while rebuilding. |
| Cache the `get_by_rrn` response in a transient for the panel | Adds invalidation complexity (when does the cache expire? Cache miss on capture-then-immediately-reload). Order edit screen is rare enough to not matter; revisit in Phase 8 polish if profiling says so. |
| Allow `manual_capture=none` to still write `_maya_authorization_type` meta | Done deliberately — it lets EventDispatcher and CaptureButton call `from_setting()` without a null guard. The cost of one extra meta write is negligible. |
| Build a separate `CaptureProcessor` for partial vs full captures | Single processor; the cumulative-captured comparison lives in the EventDispatcher (which gets the authoritative state from the webhook). Splitting along that axis would require holding state across two HTTP roundtrips, which is the opposite of what we want. |
| Honor the `FINAL` authorization type by validating "must be the full amount" client-side | Maya's API already returns `canCapture: false` for `FINAL` once the auth is consumed. The processor's existing capturable-payment lookup handles this without a special case. |
| Auto-capture immediately if the merchant set NORMAL but never clicks the button | Defeats the purpose of authorize-then-capture (merchant might be waiting for fulfillment confirmation). Maya auto-expires uncaptured auths after ~7 days; that's the right signal, not our timer. |

---

## 7. Test coverage delta

| File | Cases | Covers |
| --- | --- | --- |
| `Api/Endpoints/PaymentsTest.php` (new) | 5 | get_by_rrn happy path + URL encoding + WP_Error bubble, capture happy path + WP_Error bubble |
| `Gateway/CaptureProcessorTest.php` (new) | 8 | Non-positive amount, list error bubble, no capturable payment, exceeds remaining, happy capture with balance return, capture error bubble, find_capturable_payment ordering, empty list |
| `Gateway/PaymentProcessorTest.php` (extended) | +3 | omit authorizationType for None, uppercase for each manual mode, persist `_maya_authorization_type` meta |
| `Webhook/EventDispatcherTest.php` (extended) | +4 | CHECKOUT_SUCCESS ignored, AUTHORIZED with immediate-capture ignored, AUTHORIZED with manual-capture → note, PAYMENT_SUCCESS manual-capture full → payment_complete, PAYMENT_SUCCESS manual-capture partial → note only |
| `Settings/SettingsHelperTest.php` (extended) | +2 | default None, each enum case mapping |

**22 net new tests.**

---

## 8. Try it yourself

### Run the tests

```bash
cd web/app/plugins/woocommerce-maya-gateway
./vendor/bin/pest
```

Expected: `Tests: 131 passed (430 assertions)`.

### Manual sandbox smoke test — preauthorization flow

1. Gateway settings: pick **PREAUTHORIZATION** for "Manual capture". Save.
2. Place a sandbox order (₱200), pay with sandbox card on Maya's hosted
   page.
3. After return: order is `processing` with note "Customer returned from
   Maya checkout. Awaiting webhook confirmation."
4. ~5 seconds later the `AUTHORIZED` webhook lands → order gets a second
   note: "Maya authorized the payment. Use the Capture panel to capture
   funds." Order stays in `processing`.
5. Open the order edit screen. You should see:
   - **Capture Maya payment** button next to **Refund**.
   - **Maya capture** row in the order totals table with
     Authorized=200.00 / Captured=0.00 / Remaining=200.00.
6. Click Capture, change the amount to `50.00`, submit. The panel
   re-renders: Captured=50.00 / Remaining=150.00. The order timeline
   adds two notes (one from CaptureProcessor, one from EventDispatcher
   when the PAYMENT_SUCCESS for the partial arrives).
7. Click Capture again, leave the amount at the default (150.00), submit.
   Panel re-renders: Captured=200.00 / Remaining=0.00. Within seconds,
   the order's status promotes to `completed` via the
   `payment_complete_full_capture` branch.

### Manual sandbox smoke test — NORMAL flow

Same as above but pick **NORMAL** for manual capture. Capture in one go
(full amount); the order completes after the single PAYMENT_SUCCESS
webhook lands. No partial-capture path exercised.

### Try a failure path

Use the sandbox card that fails CVV (per Maya docs). The order flips to
`failed` via the `PAYMENT_FAILED` webhook with no capture step ever
becoming available.

---

## 9. Where to read next

- [../REBUILD_PLAN.md](../REBUILD_PLAN.md) — master plan; Phase 6 lands
  the full `RefundProcessor` (void vs refund decision tree, partial
  refund split across multiple captured payments).
- [../architecture.md](../architecture.md) — updated map, new
  "Manual capture" flow section.
- [PHASE4-TOUR.md](PHASE4-TOUR.md) — the payment-processing foundation
  this phase layers on top of. The Phase 4 PaymentProcessor /
  ReturnHandler / EventDispatcher are *unchanged* for immediate-capture
  orders; manual-capture mode is purely additive.
- [PHASE2-TOUR.md](PHASE2-TOUR.md) — the verification pipeline that
  every webhook (including the PAYMENT_SUCCESS-from-capture ones)
  traverses before reaching the dispatcher.
