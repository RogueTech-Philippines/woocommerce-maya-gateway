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
│   └── Ajax/
│       ├── TestConnection.php       # AJAX handler; orchestrates the two probes
│       ├── SimulateWebhook.php      # AJAX handler; POSTs a forged payload at our own webhook endpoint
│       └── RefreshWebhooks.php      # AJAX handler; re-fetches Maya's registered webhooks for the status table
├── Api/
│   ├── MayaApiClient.php            # HTTP transport: Basic auth, JSON I/O, logging
│   └── Endpoints/                   # typed wrappers, one class per logical endpoint group
│       ├── Checkouts.php            # POST /checkout/v1/checkouts → CheckoutSession
│       └── Webhooks.php             # GET/POST/DELETE /checkout/v1/webhooks → WebhookRecord(s)
├── Gateway/
│   └── MayaGateway.php              # WC_Payment_Gateway subclass; delegates to Admin/* and Settings/*
├── Settings/
│   └── SettingsHelper.php           # typed getters; used by admin AND runtime callers
├── Util/
│   ├── IdempotencyKey.php           # requestReferenceNumber builders (uuid, for_order, for_test_connection)
│   └── Logger.php                   # WC_Logger wrapper, debug-toggle aware, redacts secrets
├── Value/                           # immutable DTOs + enums
│   ├── AuthorizationType.php        # enum: None / Normal / FinalAuth / Preauthorization
│   ├── CheckoutSession.php          # POST /checkout/v1/checkouts response wrapper
│   ├── Money.php                    # amount + currency pair
│   ├── PaymentRecord.php            # /payments/v1/payment-rrns/{rrn} item wrapper
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
    └── Simulator.php                # local-dev forged-payload poster with bypass header
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

## What we do NOT split (yet)

- `MayaGateway::process_payment()` lives in the gateway. Move to a
  `PaymentProcessor` only when it grows beyond a screenful. (Phase 4.)
- `EventDispatcher` is not yet broken out — `WebhookHandler::process()`
  only logs the would-be dispatch. (Phase 4.)

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
│   └── WebhookRecordTest.php
└── Webhook/
    ├── IpAllowlistTest.php
    ├── PayloadFlattenerTest.php
    ├── PublicKeyBundleTest.php
    ├── RegistrarTest.php
    ├── SignatureVerifierTest.php
    ├── SimulatorTest.php
    ├── TimestampVerifierTest.php
    └── WebhookHandlerTest.php
```

Pest auto-discovers via `pest()->in('Unit')` in `tests/Pest.php`, so adding
a subdirectory needs no config change.
