# Architecture — woocommerce-maya-gateway

One-screen map of the plugin so you know which file to open before touching
anything.

## File tree (src/)

```
src/
├── Plugin.php                       # bootstrap: class_exists check + hook registrations
├── Admin/
│   ├── AdminAssets.php              # enqueue maya-admin.css/js + wp_localize_script
│   ├── FormFields.php               # WC_Settings_API form_fields array
│   ├── FieldRenderers.php           # generate_<type>_html implementations + validators
│   ├── Ajax/
│   │   ├── TestConnection.php       # AJAX handler; orchestrates the two probes
│   │   ├── SimulateWebhook.php      # AJAX handler; POSTs a forged payload at our own webhook endpoint
│   │   ├── RefreshWebhooks.php      # AJAX handler; re-fetches Maya's registered webhooks for the status table
│   │   └── CapturePayment.php       # AJAX handler; thin wrapper over CaptureProcessor
│   ├── EventLog/
│   │   ├── EventLogParser.php      # pure-static WC log-line parser (Phase 8)
│   │   └── EventLogPage.php        # custom "Maya events" tab under WC → Status (Phase 8)
│   └── OrderActions/
│       ├── CaptureButton.php        # Capture button beside Refund on the order-edit screen
│       └── CapturePanel.php         # capture form panel rendered below the order totals
├── Api/
│   ├── MayaApiClient.php            # HTTP transport: Basic auth, JSON I/O, logging
│   └── Endpoints/                   # typed wrappers, one class per logical endpoint group
│       ├── Checkouts.php            # POST /checkout/v1/checkouts → CheckoutSession
│       ├── Webhooks.php             # GET/POST/DELETE /checkout/v1/webhooks → WebhookRecord(s)
│       └── Payments.php             # /payments/v1/payment-rrns + capture/void/refund/get_refunds
├── Blocks/
│   └── MayaBlocksPaymentMethod.php  # AbstractPaymentMethodType — exposes the gateway to the block-based Cart/Checkout
├── Gateway/
│   ├── MayaGateway.php              # WC_Payment_Gateway subclass; delegates to Admin/* and Settings/*
│   ├── PaymentProcessor.php         # builds checkout payload + Checkouts::create + persists meta
│   ├── CaptureProcessor.php         # validates + executes capture via Payments::capture (Phase 5)
│   ├── RefundProcessor.php          # void-vs-refund + multi-capture split decision tree (Phase 6)
│   └── ReturnHandler.php            # wc-api=maya_return — customer redirect handler
├── Settings/
│   └── SettingsHelper.php           # typed getters; used by admin AND runtime callers
├── Util/
│   ├── IdempotencyKey.php           # requestReferenceNumber builders (uuid, for_order, for_test_connection)
│   └── Logger.php                   # WC_Logger wrapper, debug-toggle aware, redacts secrets
├── Value/                           # immutable DTOs + enums
│   ├── AuthorizationType.php        # enum: None / Normal / FinalAuth / Preauthorization
│   ├── CheckoutSession.php          # POST /checkout/v1/checkouts response wrapper
│   ├── Money.php                    # amount + currency pair
│   ├── PaymentRecord.php            # /payments/v1/payment-rrns/{rrn} item wrapper (incl. is_capture + is_authorization)
│   ├── RefundRecord.php             # /payments/v1/payments/{id}/refunds item wrapper (Phase 6)
│   ├── WebhookEvent.php             # enum of Maya event names + classification helpers
│   └── WebhookRecord.php            # /checkout/v1/webhooks item wrapper (id, name, callbackUrl, timestamps)
└── Webhook/
    ├── WebhookHandler.php           # REST /wp-json/wc-maya/v1/webhook + wc-api shim; shared process()
    ├── PublicKeyBundle.php          # Maya's sandbox + production RSA public keys
    ├── PayloadFlattener.php         # canonical key.subkey=value flatten + sort + nonce suffix
    ├── SignatureVerifier.php        # RSA-SHA256 against PublicKeyBundle
    ├── TimestampVerifier.php        # ±300s freshness check (epoch-ms)
    ├── IpAllowlist.php              # 4 Maya outbound IPs + source-IP discovery
    ├── Registrar.php                # idempotent reconcile: delete managed set → create five fresh
    ├── EventDispatcher.php          # verified event → WC order state change (Phase 4)
    ├── RetryQueue.php               # Action Scheduler-backed safety net for transient dispatch failures (Phase 8)
    └── Simulator.php                # local-dev forged-payload poster with bypass header
```

Tooling and translation files:

```
bin/
├── make-pot.php                     # self-contained .pot extractor (Phase 8)
└── build-release.sh                 # composer-less zip builder (Phase 8)
languages/
└── wc-maya-gateway.pot              # bundled translation template (Phase 8)
```

## "Which file do I open?"

| I want to… | Open |
| --- | --- |
| Add a new gateway setting | [src/Admin/FormFields.php](../src/Admin/FormFields.php) |
| Add a custom field type (button, table, badge) | [src/Admin/FieldRenderers.php](../src/Admin/FieldRenderers.php) + [src/Admin/FormFields.php](../src/Admin/FormFields.php) |
| Read a setting at runtime | [src/Settings/SettingsHelper.php](../src/Settings/SettingsHelper.php) |
| Add an admin AJAX endpoint | new file under [src/Admin/Ajax/](../src/Admin/Ajax/) |
| Localize a new JS string | [src/Admin/AdminAssets.php](../src/Admin/AdminAssets.php) |
| Add a Maya API endpoint call | new class under [src/Api/Endpoints/](../src/Api/Endpoints/) that composes `MayaApiClient` |
| Add a response/payload type | new immutable class under [src/Value/](../src/Value/) with `from_array()` |
| Build a Maya `requestReferenceNumber` | [src/Util/IdempotencyKey.php](../src/Util/IdempotencyKey.php) |
| Change payment-processing logic | [src/Gateway/MayaGateway.php](../src/Gateway/MayaGateway.php) (`process_payment`) |
| Change how a Maya event maps to a WC order | [src/Webhook/WebhookHandler.php](../src/Webhook/WebhookHandler.php) |
| Adjust what gets logged | [src/Util/Logger.php](../src/Util/Logger.php) (levels) or [src/Api/MayaApiClient.php](../src/Api/MayaApiClient.php) (call sites) |
| Register a new hook | [src/Plugin.php](../src/Plugin.php) (only if cross-cutting) or the owning class's `register()` method |
| Tweak signature/timestamp/IP checks | [src/Webhook/SignatureVerifier.php](../src/Webhook/SignatureVerifier.php) / [src/Webhook/TimestampVerifier.php](../src/Webhook/TimestampVerifier.php) / [src/Webhook/IpAllowlist.php](../src/Webhook/IpAllowlist.php) |
| Add or change webhook routing | [src/Webhook/WebhookHandler.php](../src/Webhook/WebhookHandler.php) (`process()` is the shared core) |
| Rotate Maya's webhook public keys | [src/Webhook/PublicKeyBundle.php](../src/Webhook/PublicKeyBundle.php) |
| Simulate a webhook locally | "Simulate webhook" button on the gateway settings → [src/Admin/Ajax/SimulateWebhook.php](../src/Admin/Ajax/SimulateWebhook.php) → [src/Webhook/Simulator.php](../src/Webhook/Simulator.php) |
| Change which events the plugin manages | `MANAGED_EVENTS` constant in [src/Webhook/Registrar.php](../src/Webhook/Registrar.php) |
| Tweak what happens on settings save | `process_admin_options()` override in [src/Gateway/MayaGateway.php](../src/Gateway/MayaGateway.php) |
| Change the checkout payload sent to Maya | [src/Gateway/PaymentProcessor.php](../src/Gateway/PaymentProcessor.php) (`build_payload` is pure-static, unit-testable) |
| Change customer return-from-Maya behavior | [src/Gateway/ReturnHandler.php](../src/Gateway/ReturnHandler.php) |
| Change webhook event → order state mapping | [src/Webhook/EventDispatcher.php](../src/Webhook/EventDispatcher.php) (`dispatch()` switch on `WebhookEvent`) |
| Add a manual-capture authorization mode | `AuthorizationType` enum in [src/Value/AuthorizationType.php](../src/Value/AuthorizationType.php), then surface in [src/Admin/FormFields.php](../src/Admin/FormFields.php) select options |
| Change capture validation rules | [src/Gateway/CaptureProcessor.php](../src/Gateway/CaptureProcessor.php) (`capture()` validation + dispatch) |
| Tweak the capture-panel HTML | [templates/admin/capture-panel.php](../templates/admin/capture-panel.php) (`include`-rendered template) |
| Change void-vs-refund decision or multi-capture split | [src/Gateway/RefundProcessor.php](../src/Gateway/RefundProcessor.php) — `plan_capture_actions()` is pure-static and exhaustively unit-tested |
| Change how the gateway shows up in the block-based Cart/Checkout | [src/Blocks/MayaBlocksPaymentMethod.php](../src/Blocks/MayaBlocksPaymentMethod.php) (PHP side — title/description/icon/supports) and [assets/js/maya-blocks.js](../assets/js/maya-blocks.js) (frontend `registerPaymentMethod` call) |
| Add an icon to the block payment method | Hook the `wc_maya_blocks_icon_url` filter (string URL) — read in `MayaBlocksPaymentMethod::resolve_icon_url()` |
| Tweak the admin event-log viewer | [src/Admin/EventLog/EventLogPage.php](../src/Admin/EventLog/EventLogPage.php) (UI + file picking) — line parsing in [src/Admin/EventLog/EventLogParser.php](../src/Admin/EventLog/EventLogParser.php) is pure-static |
| Change which dispatch failures get retried | `RETRYABLE_ACTIONS` in [src/Webhook/RetryQueue.php](../src/Webhook/RetryQueue.php) — backoff schedule is `RetryQueue::plan_delay()` |
| Regenerate the .pot translation template | `php bin/make-pot.php` ([bin/make-pot.php](../bin/make-pot.php)) writes `languages/wc-maya-gateway.pot` |
| Build a production-installable zip | `bin/build-release.sh` ([bin/build-release.sh](../bin/build-release.sh)) — composer-less, dev/tests/docs excluded |

## Service-registration convention

Each subsystem owns its own hooks via a static `register()` method called
once from `Plugin::init()`. Keeps `Plugin.php` a one-screen map of the
plugin's surface area.

```php
// src/Plugin.php
public static function init(): void {
    // ...
    AdminAssets::register();
    TestConnection::register();
}

// src/Admin/AdminAssets.php
public static function register(): void {
    add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
}
```

## WC_Settings_API bridge

`WC_Settings_API` looks up `generate_<type>_html($key, $data)` and
`validate_<key>_field($key, $value)` on the gateway instance — those method
names are fixed by WooCommerce. The pattern is:

1. Define the field in `FormFields::definitions()` with a custom `type`.
2. Implement the rendering / validation as a static method on
   `FieldRenderers`.
3. Add a one-line `generate_<type>_html` (or `validate_<key>_field`) on
   `MayaGateway` that delegates to `FieldRenderers`.

The gateway stays a thin WC bridge; logic lives in `Admin/`.

## SettingsHelper lives outside Admin/

It's read from non-admin contexts (the webhook handler dispatches on
`is_sandbox()`, the gateway's `build_api_client()` needs all four). Keeping
it under `Settings/` makes its broader role visible.

## Logger debug toggle

`new Logger($debug_enabled)`:

- `false` (production default): warnings and errors only.
- `true`: also writes `debug` (outgoing requests) and `info` (successful
  responses).

The gateway reads `SettingsHelper::debug_log_enabled()` for the saved
preference; the AJAX `TestConnection` handler honors the live checkbox
state from POST so the toggle works without saving.

## Webhook routing — two entrypoints, one core

`WebhookHandler::register()` wires up two URLs that Maya can call:

| URL                                      | Hook                                              | Why we keep both |
| ---                                      | ---                                               | --- |
| `POST /wp-json/wc-maya/v1/webhook`       | `rest_api_init` → `register_rest_route()`         | Primary. Modern WP routing; predictable JSON in/out; what new installs should register. |
| `POST /?wc-api=maya_webhook`             | `woocommerce_api_maya_webhook` → `handle_wc_api()` | Compatibility shim. Matches the URL shape WC has historically used so migrating merchants don't have to re-register webhooks in the Maya Manager. |

Both entrypoints converge on `WebhookHandler::process()` — a pure(-ish)
function that takes `(body, headers, source_ip, is_sandbox, logger)` and
returns `[status, body]`. Unit tests exercise that core directly without
booting WP_REST_Server, php://input, or the gateway settings option.

## Verification pipeline

Inside `process()`, every (non-simulated) webhook is checked in this order:

1. **Body parses as JSON.** (400 `invalid_body`.)
2. **Timestamp within ±300s** — `TimestampVerifier` reads epoch-ms from
   `X-Maya-Webhook-Timestamp`. (401 `stale_timestamp`.)
3. **Signature verifies** — `SignatureVerifier` flattens via
   `PayloadFlattener` and runs `openssl_verify(...,
   'sha256WithRSAEncryption')` against each PEM in `PublicKeyBundle`. (401
   `invalid_signature`.)
4. **Source IP in `IpAllowlist`** for the active environment. (403
   `source_ip_blocked`.)
5. On success, look up the event via `WebhookEvent::try_from_string` and
   log the "would dispatch" line. Phase 4 swaps the log line for the real
   `EventDispatcher` call.

`X-Simulated-Webhook: true` is honored **only in sandbox mode** and short-
circuits steps 2–4 so a developer can exercise the pipeline without a
tunnel.

## Webhook registration — settings-save flow

`MayaGateway::process_admin_options()` is the seam:

1. Parent's `process_admin_options()` writes the form values to `wp_options`.
2. We re-`init_settings()` so the in-memory gateway sees the fresh values.
3. If `enabled !== 'yes'` or either API key is empty → just save, show a
   "saved, add your keys" notice, no Maya call.
4. Otherwise build a `Webhooks` endpoint client and hand it to
   `Webhook\Registrar::reconcile($webhook_url)`:
   - `endpoint->all()` → list every Maya webhook on this account.
   - For each whose `name` is in `Registrar::MANAGED_EVENTS`, call
     `endpoint->delete(id)`. Unmanaged entries are skipped.
   - For each event in `MANAGED_EVENTS`, call
     `endpoint->create(event, callback_url)`.
   - Returns `[deleted, created, skipped, errors]` so the gateway can
     surface success / partial / failure via `WC_Admin_Settings::add_*`.

The status table under the form fetches the current state via the
`Admin/Ajax/RefreshWebhooks` endpoint on page load and on user click — the
form render itself never blocks on Maya.

## Payment processing — checkout → return → webhook

```text
1. customer clicks "Place order"
   └── MayaGateway::process_payment(order_id)
       └── PaymentProcessor::process($order):
           ├── build_payload(order, RRN, return_url)
           ├── Checkouts::create(payload)
           ├── persist _maya_checkout_id + _maya_idempotency_key
           └── return [result: success, redirect: <Maya hosted page>]
2. customer enters card on Maya's hosted page, redirected back to:
   └── ?wc-api=maya_return&order=<id>&status=success
       └── ReturnHandler::handle()
           ├── if status=failed: notice + back to checkout payment URL
           └── else: flip pending → processing, empty cart, redirect to
                     get_checkout_order_received_url()
3. Maya's webhook server independently POSTs the signed result:
   └── WebhookHandler::process() (Phase 2 verify pipeline)
       └── on success: EventDispatcher::dispatch(WebhookEvent, payload):
           ├── PAYMENT_SUCCESS + amount match → $order->payment_complete($paymentId)
           ├── PAYMENT_SUCCESS + amount mismatch → log + order note (no state change)
           ├── PAYMENT_FAILED / EXPIRED / AUTH_FAILED → update_status('failed', note)
           ├── already-paid orders → log + skip (idempotency for retries)
           └── other events (CHECKOUT_*, AUTHORIZED) → log + skip (Phase 5 layer)
```

`ReturnHandler` *never* marks orders completed — the browser is untrusted.
`payment_complete()` only fires from the signed webhook, so a forged
return URL can't promote an order past `processing`.

## Manual capture — authorize-now, capture-later (Phase 5)

When the gateway's `manual_capture` setting is anything other than `none`,
the flow grows two extra moments:

```text
On checkout (PaymentProcessor)
    └── adds authorizationType: UPPERCASE to the createCheckout payload
    └── persists _maya_authorization_type = 'normal' | 'final' | 'preauthorization'

After return + AUTHORIZED webhook
    └── EventDispatcher::note_authorized() adds a "Use the Capture panel" note
    └── order stays in 'processing' (set by ReturnHandler)

Merchant clicks Capture on the order edit page
    └── CaptureButton::should_render() did the live Payments::get_by_rrn lookup
        and confirmed an AUTHORIZED + canCapture payment exists
    └── CapturePanel rendered the form with live authorized/captured/remaining
    └── JS POSTs to wc_maya_capture_payment AJAX
    └── CapturePayment::handle() → CaptureProcessor::capture()
            ├── validate amount > 0 and ≤ (authorized − captured)
            ├── Payments::capture(payment_id, payload)
            ├── add_order_note(updated balances)
            └── return [amount_authorized, amount_captured, amount_remaining]

PAYMENT_SUCCESS webhook arrives (asynchronous, from the capture)
    └── EventDispatcher detects _maya_authorization_type != none
        ├── if capturedAmount === amount → payment_complete()
        └── else → add partial-capture note, leave order in 'processing'
```

The order's authoritative completion still comes from the signed webhook —
the capture API response is *informational only*. Two partial captures
that together cover the authorized amount produce two PAYMENT_SUCCESS
webhooks; only the second one (with the matching cumulative
`capturedAmount`) promotes the order.

## Refund — void-vs-refund decision tree (Phase 6)

`MayaGateway::process_refund(order_id, amount, reason)` is a thin delegate
to {@see RefundProcessor::process()}. The processor branches on the order's
`_maya_authorization_type` meta:

```text
Immediate-capture order (auth type 'none')
    └── find PAYMENT_SUCCESS or REFUNDED payment for the RRN
        ├── canVoid + amount == full → Payments::void()
        ├── canVoid + amount != full + !canRefund → WP_Error partial_void
        └── canRefund → Payments::refund(amount)

Manual-capture order (auth type normal/final/preauthorization)
    └── find AUTHORIZED payment
        ├── only the auth exists (no captures yet)
        │   ├── canVoid + amount == full → Payments::void(auth)
        │   └── partial → WP_Error partial_void
        └── captures exist
            ├── sort by createdAt asc
            ├── for each capture: build available action
            │   ├── canVoid → action='void' for full capture amount
            │   └── canRefund → fetch get_refunds, action='refund' for remaining balance
            ├── plan_capture_actions(available, amount) — pure planner:
            │   walks the list, consumes amount, returns [action,…]
            │   └── voids must consume whole; refunds may take partial
            └── execute_capture_actions(plan, reason)
                └── each action calls Payments::void or Payments::refund + adds order note
```

`plan_capture_actions()` and `remaining_refundable()` are public static so
unit tests can pin every branch of the algorithm without mocking Maya.

## Block-based Cart and Checkout (Phase 7)

WooCommerce ships two checkout experiences:

- **Classic checkout** (shortcode `[woocommerce_checkout]`) — server-rendered,
  what every legacy theme uses. The `WC_Payment_Gateway` class drives it.
  Maya already worked here after Phase 4.
- **Block-based Cart / Checkout** (introduced with WooCommerce Blocks) —
  React-rendered, what new themes default to. Each payment gateway has to
  ship a separate "integration" class + a JS bundle that calls
  `wc.wcBlocksRegistry.registerPaymentMethod`.

`Blocks/MayaBlocksPaymentMethod` is that integration:

```text
Plugin::init()
    └── MayaBlocksPaymentMethod::register()
        └── add_action('woocommerce_blocks_payment_method_type_registration', …)
            └── WC Blocks calls register_payment_method(PaymentMethodRegistry $r)
                └── $r->register(new MayaBlocksPaymentMethod())

When the block renders:
    ├── ::initialize()                 reads woocommerce_maya_checkout_settings
    ├── ::is_active()                  → enabled === 'yes'
    ├── ::get_payment_method_script_handles()
    │       wp_register_script('wc-maya-blocks', assets/js/maya-blocks.js,
    │                          [wc-blocks-registry, wp-element, wp-html-entities, wp-i18n])
    │       wp_set_script_translations(...)
    └── ::get_payment_method_data()    → {title, description, icon, supports}
            (localized as wc.wcSettings)

In the browser:
    └── assets/js/maya-blocks.js
        ├── reads wc.wcSettings.getPaymentMethodData('maya_checkout')
        └── wc.wcBlocksRegistry.registerPaymentMethod({
                name: 'maya_checkout',
                label: <PaymentMethodLabel text=title icon=icon />,
                content / edit: <div>{description}</div>,
                canMakePayment: () => true,
                supports: { features: settings.supports },
            })
```

Maya is **hosted checkout** — the customer enters card / wallet details
on a Maya-hosted page after "Place order", so the block content is just a
description, no input fields. `process_payment()` on the classic
`MayaGateway` still owns the actual session-creation logic; the block's
`canMakePayment` always returns true and the rest of the flow is
identical to the classic checkout.

`build_payment_method_data()` and `is_enabled()` are public static so the
data-shape contract and activation rule can be pinned by unit tests
without booting WooCommerce.

Compatibility is declared in the main plugin file alongside HPOS:

```php
FeaturesUtil::declare_compatibility('cart_checkout_blocks', WC_MAYA_PLUGIN_FILE, true);
```

Without that declaration, WooCommerce hides the gateway from the block
checkout even if the integration class is registered.

## Observability and retry (Phase 8)

### Maya events log viewer

The `wc-maya-gateway` log channel collects every outgoing API request,
every verification decision, and every state change. The global WC log
page (WooCommerce → Status → Logs) shows them with every other
extension's entries — usable but noisy. Phase 8 adds a dedicated
"Maya events" tab under WC → Status that:

- Lists every `wc-maya-gateway-*.log` file (newest first) in a file
  picker.
- Parses the selected file via `EventLogParser::parse_lines()` — pure-
  static, robust against malformed lines and message text that contains
  `{` (e.g. `/payments/v1/payments/{id}/capture` URLs).
- Filters by level (DEBUG / INFO / WARNING / ERROR) and free-text
  search (matches against both the message and the JSON-encoded
  context).
- Renders the result as a `widefat striped` table with the context
  pretty-printed as JSON.

```text
EventLogPage::render()
    ├── EventLogPage::list_log_files()      # globs WC_LOG_DIR for wc-maya-gateway-*.log
    ├── EventLogPage::resolve_selected_file(...)
    ├── EventLogParser::parse_lines(file_contents)
    ├── EventLogParser::filter_by_level(...)
    ├── EventLogParser::filter_by_search(...)
    └── EventLogPage::tail(entries, MAX_ENTRIES)   # cap at 500 per render
```

### Action Scheduler retry safety net

`WebhookHandler::process()` calls `RetryQueue::maybe_schedule(...)`
right after `EventDispatcher::dispatch(...)`. The retry queue inspects
the dispatch's `action` field and, for a small allow-list of transient
failures, schedules a follow-up via `as_schedule_single_action`:

```text
dispatch.action in RETRYABLE_ACTIONS?  (default: order_not_found,
    │                                    manual_capture_lookup_failed,
    │                                    manual_capture_lookup_unavailable)
    ├── no  → return; let the failure stand
    └── yes → attempt < MAX_ATTEMPTS (4)?
        ├── no  → log + give up
        └── yes → as_schedule_single_action(time() + plan_delay(attempt+1),
                       'wc_maya_replay_webhook',
                       [{ payload, attempt+1 }],
                       group='wc-maya-gateway')
```

When AS fires the scheduled action, `RetryQueue::handle()` rebuilds
the dispatcher (with a fresh `Payments` endpoint from the saved
settings) and re-runs `dispatch()` on the same payload. It does
**not** re-verify the signature — the payload was verified on the
original delivery; the replay's purpose is purely to retry the local
processing.

`plan_delay()` is exponential with a 60-second floor: 1m → 4m → 16m
→ 64m. Pure-static so the policy is unit-testable without touching AS.

Idempotency rules from upstream phases hold: `payment_complete()` is
idempotent inside WC; an order already in `paid` short-circuits in
`EventDispatcher::dispatch` regardless of how many times we replay.

### Translation pipeline

`bin/make-pot.php` walks `src/` + `templates/` + the main plugin file
with a focused regex extractor for the six call shapes we actually use
(`__`, `_e`, `esc_html__`, `esc_attr__`, `_n`, `_x`). Output is
`languages/wc-maya-gateway.pot`, sorted alphabetically with file:line
references for every occurrence. `Plugin::init()` calls
`load_plugin_textdomain('wc-maya-gateway', false, 'languages')` so any
shipped or user-installed `.mo` is picked up automatically.

### Release build

`bin/build-release.sh` rsyncs the runtime files into
`dist/woocommerce-maya-gateway/`, runs `composer install --no-dev
--optimize-autoloader --classmap-authoritative` inside the staging
copy, strips vendor-side test / doc directories, and zips up the
result as `dist/wc-maya-gateway-<version>.zip`. The plugin's working
tree is never touched — composer.lock stays at the dev resolve.

## Adding an endpoint

The pattern is set by [src/Api/Endpoints/Checkouts.php](../src/Api/Endpoints/Checkouts.php) and
[src/Api/Endpoints/Webhooks.php](../src/Api/Endpoints/Webhooks.php):

1. New class under `src/Api/Endpoints/` taking `MayaApiClient` via constructor.
2. Each public method calls `$this->client->request(method, path, body, key)`
   and converts the decoded array into a `src/Value/*` DTO via
   `Whatever::from_array()`.
3. WP_Error responses pass through verbatim — callers handle them.

The `MayaApiClient` itself is transport-only: it never knows what endpoint
it's being asked to call.

## Tests mirror src/

```
tests/Unit/
├── Admin/Ajax/TestConnectionTest.php
├── Admin/EventLog/EventLogParserTest.php
├── Api/
│   ├── MayaApiClientTest.php
│   └── Endpoints/
│       ├── CheckoutsTest.php
│       └── WebhooksTest.php
├── Settings/SettingsHelperTest.php
├── Util/IdempotencyKeyTest.php
├── Value/
│   ├── AuthorizationTypeTest.php
│   ├── CheckoutSessionTest.php
│   ├── MoneyTest.php
│   ├── PaymentRecordTest.php
│   ├── WebhookEventTest.php
│   ├── WebhookRecordTest.php
│   └── RefundRecordTest.php
├── Api/Endpoints/
│   ├── CheckoutsTest.php
│   ├── WebhooksTest.php
│   └── PaymentsTest.php
├── Blocks/
│   └── MayaBlocksPaymentMethodTest.php
├── Gateway/
│   ├── PaymentProcessorTest.php
│   ├── CaptureProcessorTest.php
│   ├── RefundProcessorTest.php
│   └── (ReturnHandler is exit-based — covered by manual smoke test, not unit-tested)
└── Webhook/
    ├── EventDispatcherTest.php
    ├── IpAllowlistTest.php
    ├── PayloadFlattenerTest.php
    ├── PublicKeyBundleTest.php
    ├── RegistrarTest.php
    ├── RetryQueueTest.php
    ├── SignatureVerifierTest.php
    ├── SimulatorTest.php
    ├── TimestampVerifierTest.php
    └── WebhookHandlerTest.php
```

Pest auto-discovers via `pest()->in('Unit')` in `tests/Pest.php`, so adding
a subdirectory needs no config change.
