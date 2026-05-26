# Phase 4 tour — Payment processing (no manual capture yet)

Status: **DONE** (2026-05-26).

> Customer clicks "Place order" → sees Maya's hosted checkout → completes
> payment → bounced back to the order-received page in `processing` →
> Maya's signed webhook flips it to `completed`. End-to-end, no manual
> intervention.

Delta tour. Assumes you've read [PHASE1-TOUR.md](PHASE1-TOUR.md),
[PHASE2-TOUR.md](PHASE2-TOUR.md), and [PHASE3-TOUR.md](PHASE3-TOUR.md).
I don't re-explain DTOs, the `register()` convention, the verification
pipeline, or the reconcile loop.

---

## 1. Definition-of-done — confirmed

The rebuild plan asked for:

> Sandbox card flow works end-to-end. Order transitions: `pending` →
> `processing` (return) → `completed` (webhook).

What was shipped:

- **109 tests passing, 349 assertions** — net delta of **+14** over Phase 3's
  95-test baseline.
- PHP lint clean (`php-cs-fixer` + `php -l`).
- 3 new classes (`PaymentProcessor`, `ReturnHandler`, `EventDispatcher`),
  3 new test files, plus `WebhookHandlerTest` + `SettingsHelperTest`
  updated.
- `MayaGateway::process_payment()` is now a delegate.
- `WebhookHandler::process()` actually dispatches now — the Phase 2
  "would dispatch" log line is replaced with the real `EventDispatcher::dispatch()`
  call and its structured result rides on the 200 response body.

---

## 2. Before / after file tree

```text
src/
├── Gateway/
│   ├── MayaGateway.php              # EXTENDED: process_payment delegates; META_IDEMPOTENCY_KEY added
│   ├── PaymentProcessor.php         # NEW (Phase 4)
│   └── ReturnHandler.php            # NEW (Phase 4)
├── Settings/
│   └── SettingsHelper.php           # EXTENDED: return_url($order_id)
├── Webhook/
│   ├── WebhookHandler.php           # EXTENDED: process() wires EventDispatcher; body['dispatch']
│   └── EventDispatcher.php          # NEW (Phase 4)
└── Plugin.php                       # EXTENDED: ReturnHandler::register()
```

```text
tests/Unit/
├── Gateway/
│   └── PaymentProcessorTest.php     # NEW (Phase 4) — 5 cases
├── Settings/
│   └── SettingsHelperTest.php       # EXTENDED: +1 test for return_url
└── Webhook/
    ├── EventDispatcherTest.php      # NEW (Phase 4) — 7 cases
    └── WebhookHandlerTest.php       # EXTENDED: +1 test, 2 success-path tests now assert dispatch result
```

---

## 3. The three-act flow

Maya Checkout is a hosted-page integration: we never see the customer's
card. The flow has three independent acts, each in its own class:

```text
Act 1 — checkout intent
    MayaGateway::process_payment(order_id)
        └── PaymentProcessor::process($order)
                ├── compose Maya-shaped payload
                ├── POST /checkout/v1/checkouts
                ├── persist _maya_checkout_id + _maya_idempotency_key
                └── return [result: success, redirect: <maya URL>]
    WC sends the customer to <maya URL>.

Act 2 — customer return (UNTRUSTED)
    GET ?wc-api=maya_return&order=<id>&status=success
        └── ReturnHandler::handle()
                ├── if status=failed → notice + back to payment page
                ├── else → flip pending→processing (with note)
                ├── empty cart
                └── redirect to get_checkout_order_received_url()
    Customer sees "Thanks, your order is being processed."

Act 3 — webhook (AUTHORITATIVE, asynchronous)
    POST /wp-json/wc-maya/v1/webhook   (signed, verified)
        └── WebhookHandler::process() — Phase 2 verify pipeline
                └── EventDispatcher::dispatch(WebhookEvent, payload)
                        ├── PAYMENT_SUCCESS + amount match → $order->payment_complete($payment_id)
                        ├── PAYMENT_SUCCESS + mismatch → log + order note (no state change)
                        ├── PAYMENT_FAILED / EXPIRED / AUTH_FAILED → update_status('failed', note)
                        ├── already-paid → log + skip (retry idempotency)
                        └── other events → log + skip (Phase 5 layers on)
```

Act 2 is **deliberately weak** — the customer's browser is untrusted.
`ReturnHandler` never promotes the order past `processing`. The
authoritative state change is in Act 3, where the signed-by-Maya payload
goes through the full verification pipeline before any `payment_complete()`
fires. A forged return URL can't promote an order to `completed`.

---

## 4. New files

### 4.1 `Gateway/PaymentProcessor`

The class is two pieces glued together:

```php
public function process(WC_Order $order): array
{
    $reference = IdempotencyKey::for_order((int) $order->get_id());
    $payload   = self::build_payload($order, $reference, $this->settings->return_url((int) $order->get_id()));

    $session = $this->endpoint->create($payload);
    if ($session instanceof WP_Error) { /* notice + log + return failure */ }

    $order->update_meta_data(MayaGateway::META_CHECKOUT_ID, $session->checkout_id);
    $order->update_meta_data(MayaGateway::META_IDEMPOTENCY_KEY, $reference);
    $order->save();

    return [ 'result' => 'success', 'redirect' => $session->redirect_url ];
}

public static function build_payload(WC_Order $order, string $reference, string $return_url_base): array { ... }
```

`build_payload` is `public static` *deliberately* — it's a pure function,
no I/O, no settings access, no Maya call. That makes its shape pinnable
with unit tests against a Mockery-mocked WC_Order. If Maya rev's the
payload schema, you change one method and the tests catch every
regression.

Three shape decisions worth flagging:

- **Shipping falls back to billing field-by-field.** Maya rejects checkouts
  with empty shipping address values; WC orders without shipping
  often have a billing-only profile. The fallback (per-field, not
  whole-block) handles the common case of "customer entered shipping
  first name but no shipping address line 2" cleanly.
- **`shippingType: 'ST'` is hardcoded.** Maya wants a 2-letter shipping
  category; "ST" = Standard. Phase 6+ can revisit if we want SF (Same-day)
  or other categories.
- **`code` falls back to `'001'`.** Maya requires a `code` per line item.
  We prefer `WC_Order_Item_Product::get_product_id()` so refunds can map
  back to the WC product, but use `'001'` as a safety net.

### 4.2 `Gateway/ReturnHandler`

Registered on `woocommerce_api_maya_return` via `ReturnHandler::register()`.
Reads `?order=<id>&status=success|failed`, validates, and either:

- **`status=failed`** → adds a customer notice, redirects to the order's
  checkout payment URL so they can retry without losing context.
- **`status=success` (or absent)** → flips `pending`/`on-hold`/`failed`
  orders to `processing` (with a note that the webhook will confirm),
  empties the cart, redirects to the order-received page.

The `pending|on-hold|failed` gate is important: by the time the customer
returns, the webhook may have already arrived and promoted the order to
`processing` or even `completed`. We don't want the return handler to
*downgrade* a webhook-completed order back to `processing`. `has_status()`
narrows the flip to only states where promotion is the right answer.

### 4.3 `Webhook/EventDispatcher`

The state-machine. Decoupled from the verification pipeline by an
injection seam — `WebhookHandler::process()` accepts an optional
`?EventDispatcher $event_dispatcher_override` for tests.

```php
public function dispatch(WebhookEvent $event, array $payload): array
```

Returns a structured result so the webhook handler can ride it on the
200 response body (handy for the simulator and integration tests):

```php
['action' => 'payment_complete', 'order_id' => 42, 'payment_id' => 'pay_abc']
['action' => 'failed',           'order_id' => 42, 'event'      => 'PAYMENT_FAILED']
['action' => 'amount_mismatch',  'order_id' => 42, 'expected'   => 199.5, 'received' => 50.0]
['action' => 'already_paid',     'order_id' => 42]
['action' => 'order_not_found',  'reference' => '999']
['action' => 'ignored',          'order_id' => 42, 'event'      => 'CHECKOUT_SUCCESS']
```

#### Amount tolerance

```php
public const AMOUNT_TOLERANCE = 0.005;
```

Maya sends decimals (`199.5`). Floating-point round-trips can leave a
sub-cent difference between what we sent and what we receive back. The
legacy plugin used `PHP_FLOAT_EPSILON` (~`2.2e-16`) which is too tight —
any tiny float-precision drift would falsely flag as mismatch. Half a
cent is generous enough to absorb FP noise while still catching genuine
mismatches.

#### Idempotency for webhook retries

Maya retries unack'd webhooks up to 4 times. If our `payment_complete()`
runs twice, downstream `woocommerce_payment_complete` listeners fire
twice — bad. The `is_paid()` gate guards this:

```php
if ($order->is_paid()) {
    $this->logger->info('EventDispatcher: order already paid; skipping.');
    return [ 'action' => 'already_paid', 'order_id' => (int) $order->get_id() ];
}
```

---

## 5. The `MayaGateway` diet

Before Phase 4:

```php
public function process_payment($order_id): array { return [ 'result' => 'failure' ]; }
```

After Phase 4:

```php
public function process_payment($order_id): array
{
    $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
    if (! $order instanceof WC_Order) {
        wc_add_notice(sprintf(__('Could not load order #%d.'), (int) $order_id), 'error');
        return [ 'result' => 'failure' ];
    }

    $helper = new SettingsHelper($this);
    return (new PaymentProcessor(
        new Checkouts($this->build_api_client()),
        $helper,
        new Logger($helper->debug_log_enabled()),
    ))->process($order);
}
```

The gateway stays a thin bridge — it owns the `WC_Payment_Gateway`
contract (`process_payment` signature, supports, form fields), and
delegates the actual work. New in Phase 4: `META_IDEMPOTENCY_KEY`
constant alongside the existing `META_CHECKOUT_ID` + `META_PAYMENT_ID`.
`'refunds'` deliberately stays out of `$this->supports` until Phase 6
lands `process_refund()` — advertising a capability we can't service
would surface a refund button that silently fails.

---

## 6. The webhook handler's new responsibility

Phase 2 ended with `WebhookHandler::process()` logging "would dispatch X
for order Y" and returning a 200. Phase 4 wires the real dispatch:

```php
$dispatch = null;
if (null !== $event) {
    $dispatcher = $event_dispatcher_override ?? new EventDispatcher($logger);
    $dispatch   = $dispatcher->dispatch($event, $payload);
}

return [
    'status' => 200,
    'body'   => [
        // … plus the existing fields …
        'dispatch' => $dispatch,
    ],
];
```

- **Unknown event → no dispatch call.** If `WebhookEvent::try_from_string()`
  returns null (Maya sent a new event name we don't recognize),
  `dispatch` stays null. The handler still returns 200 — refusing the
  webhook would just trigger Maya's retry loop without us learning the
  event.
- **Body now carries the structured dispatch result.** Simulator + REST
  consumers can see exactly what happened: `{action: payment_complete,
  order_id: 42, payment_id: 'pay_abc'}`. Easier to debug than chasing
  log files.
- **`event_dispatcher_override` injection seam.** Same pattern as
  `signature_verifier_override`. Tests pass a Mockery mock so the
  pipeline runs without booting WC.

---

## 7. Anti-patterns deliberately avoided

| Tempted to | Why we didn't |
| --- | --- |
| Have `ReturnHandler` call `payment_complete()` on `status=success` | Browser is untrusted — a forged URL could promote any order. Webhook is the only authoritative signal. |
| Add a "force payment_complete" admin button for stuck orders | Sounds nice; reality is it papers over real signature-verification or webhook-routing bugs. Phase 8's audit log + sandbox simulator are the right diagnostic tools. |
| Use `PHP_FLOAT_EPSILON` for amount matching (matches legacy) | Too tight — would false-mismatch on FP noise. 0.005 (half a cent) is what currency-comparison code actually wants. |
| Persist additional meta on `payment_complete` (e.g., raw Maya payload) | YAGNI for Phase 4. Phase 6 may need it for refund matching; cross that bridge then. WC's order-notes timeline already captures the human-readable trail. |
| Make `PaymentProcessor` construct the `Checkouts` endpoint itself | Constructor-inject it. Lets tests mock the endpoint without touching the API client transport. |
| Branch on manual capture in the dispatcher now | Phase 5's job. The dispatcher's `WebhookEvent::Authorized` arm currently logs + skips; Phase 5 grows it. |
| Synchronously call `payment_complete()` from `ReturnHandler` "just in case the webhook is slow" | Race condition city. The webhook is authoritative and Maya retries until 2xx; trust the system. If the merchant disables webhooks entirely (don't!), the simulator can re-fire. |
| Unit-test `ReturnHandler::handle()` directly | It uses `exit` — hard to test without runkit. The redirect-URL composition is small enough to verify by reading. The end-to-end test is the sandbox manual smoke (§8). |

---

## 8. Test coverage delta

| File | Cases | Covers |
| --- | --- | --- |
| `Gateway/PaymentProcessorTest.php` (new) | 5 | Payload shape (totalAmount + buyer + items + redirect), shipping-falls-back-to-billing, shipping-prefers-shipping-when-present, happy-path persists meta + returns success, error-path returns failure + adds notice |
| `Webhook/EventDispatcherTest.php` (new) | 7 | PAYMENT_SUCCESS happy path, amount mismatch logs + order note, PAYMENT_FAILED maps to failed, EXPIRED + AUTH_FAILED also map to failed, already-paid idempotency, order-not-found, non-payment events ignored |
| `Webhook/WebhookHandlerTest.php` (extended) | +1 net, 2 existing rewritten | Success path now asserts dispatcher receives the event, unknown event short-circuits dispatch, simulator path returns dispatch result |
| `Settings/SettingsHelperTest.php` (extended) | +1 | `return_url` always uses `home_url` (not the local-dev override) |

**14 net new tests.**

---

## 9. End-to-end walkthrough — sandbox card flow

1. Customer adds a sandbox-priced product, picks Maya Checkout, clicks
   "Place order."
2. `MayaGateway::process_payment(123)` → `PaymentProcessor::process($order)`
   → `Checkouts::create($payload)` returns `{checkoutId, redirectUrl}`.
3. Meta written: `_maya_checkout_id = chk_abc`, `_maya_idempotency_key = '123'`.
   Order stays `pending`.
4. WC returns `[result: success, redirect: <maya URL>]` to the checkout
   block; browser is redirected to Maya's hosted page.
5. Customer enters Maya's sandbox test card (`5123 4567 8901 2346`,
   any future expiry, any CVC), clicks Pay.
6. Maya processes the payment, redirects browser back to
   `https://your-site.com/?wc-api=maya_return&order=123&status=success`.
7. `ReturnHandler::handle()` flips order to `processing` with a note
   "Customer returned from Maya checkout. Awaiting webhook confirmation."
   Cart is emptied. Browser redirects to the order-received page.
8. Independently, Maya's webhook server POSTs the signed payload to
   `https://your-site.com/?wc-api=maya_webhook` (or the REST endpoint).
9. `WebhookHandler::process()` runs the Phase 2 verification pipeline.
   On pass: `EventDispatcher::dispatch(WebhookEvent::PaymentSuccess, $payload)`
   matches the amount (199.5 == 199.5), calls `$order->payment_complete('pay_abc')`.
10. Order is now `completed`. Email notifications fire, inventory adjusts,
    every WC `payment_complete` listener gets its hook.

---

## 10. Try it yourself

### Run the tests

```bash
cd web/app/plugins/woocommerce-maya-gateway
./vendor/bin/pest
```

Expected: `Tests: 109 passed (349 assertions)`.

### Manual sandbox smoke test

1. Settings page: enable the gateway, enter sandbox API keys, save. The
   five managed webhooks should auto-register (Phase 3's reconcile).
2. Place a test order through the WC checkout (any product, sandbox
   shipping address).
3. On Maya's hosted page, use the sandbox card
   `5123 4567 8901 2346`, future expiry, CVC `100`.
4. After return: order should be `processing` with the "awaiting webhook
   confirmation" note.
5. Within ~10 seconds, the webhook should land. Refresh the order page:
   status should now be `completed` with a `payment_complete` line and
   the Maya `pay_*` payment id recorded.
6. WooCommerce → Status → Logs → `wc-maya-gateway-*`: look for
   `PaymentProcessor: checkout session created.` then
   `EventDispatcher: payment_complete().`

### Failure-path smoke

Use Maya's failing test card (per their docs — usually `4111 1111 1111 1111`
or whatever current sandbox documentation says). Order should be
`pending` after return-handler runs (or `processing` if redirect arrives
with success then webhook returns failure). The eventual webhook will
either:

- Map to `failed` (if `PAYMENT_FAILED` / `AUTH_FAILED`) → status flips.
- Map to `amount_mismatch` → log + order note, no state change (manual
  merchant review).

### Webhook simulator (sandbox-only)

The Phase 2 simulator now drives the real dispatcher too: pick an order
id, fire `PAYMENT_SUCCESS`, and the order should promote to `completed`
locally without needing a real Maya redirect.

---

## 11. Where to read next

- [../REBUILD_PLAN.md](../REBUILD_PLAN.md) — master plan; Phase 5 layers
  manual capture (authorize → capture) on top of Phase 4's foundation.
- [../architecture.md](../architecture.md) — updated map and the new
  "Payment processing — checkout → return → webhook" flow diagram.
- [PHASE3-TOUR.md](PHASE3-TOUR.md) — webhook registration + the
  Registrar that puts Maya's account in shape so Phase 4's webhooks can
  actually arrive.
- [PHASE2-TOUR.md](PHASE2-TOUR.md) — verification pipeline that runs
  before the EventDispatcher sees a payload.
- [PHASE1-TOUR.md](PHASE1-TOUR.md) — foundation + primer.
