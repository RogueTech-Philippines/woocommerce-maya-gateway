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
│       └── TestConnection.php       # AJAX handler; orchestrates the two probes
├── Api/
│   ├── MayaApiClient.php            # HTTP transport: Basic auth, JSON I/O, logging
│   └── Endpoints/                   # typed wrappers, one class per logical endpoint group
│       ├── Checkouts.php            # POST /checkout/v1/checkouts → CheckoutSession
│       └── Webhooks.php             # GET /checkout/v1/webhooks
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
│   └── WebhookEvent.php             # enum of Maya event names + classification helpers
└── Webhook/
    └── WebhookHandler.php           # routes woocommerce_api_maya_webhook hits
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

## What we do NOT split (yet)

- `WebhookHandler` keeps its IP allowlists inline as class constants. Don't
  extract a `IpAllowlist` class unless the verification grows non-trivial.
  (This changes in Phase 2 of the rebuild plan.)
- `MayaGateway::process_payment()` lives in the gateway. Move to a
  `PaymentProcessor` only when it grows beyond a screenful. (Phase 4.)

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
│   └── WebhookEventTest.php
└── Webhook/WebhookHandlerTest.php
```

Pest auto-discovers via `pest()->in('Unit')` in `tests/Pest.php`, so adding
a subdirectory needs no config change.
