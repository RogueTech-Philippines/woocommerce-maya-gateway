# Phase 1 tour — Foundation refactor + codebase primer

Status: **DONE** (2026-05-26).

This doc is the in-depth companion to Phase 1 of
[REBUILD_PLAN.md](../REBUILD_PLAN.md). Because Phase 1 was
**the foundation phase** — it laid down the seams everything else plugs
into — this is also your **primary tour of the codebase as it exists
today**.

Read it top-to-bottom on your first day. Future phase tours
(`PHASE2-TOUR.md`, `PHASE3-TOUR.md`, …) will be smaller delta docs
that assume you've read this one.

It assumes basic PHP (classes, namespaces, types) and HTTP fundamentals,
but no WordPress / WooCommerce experience.

---

## 1. What is this plugin?

WordPress is a CMS. WooCommerce is a plugin that turns WordPress into
an e-commerce store. A **WooCommerce payment gateway plugin** adds a
new payment method to that store — e.g. Stripe, PayPal, GCash, or in
our case **Maya** (a major Philippine payment processor).

Maya works like Stripe:

1. The customer clicks "Place order" on the WooCommerce checkout.
2. Our plugin asks Maya's API to create a "checkout session" and gets
   back a special URL.
3. The customer is redirected to that URL — a Maya-hosted payment page
   where they enter card / wallet details.
4. Maya processes the payment and redirects the customer back to our
   site.
5. Separately, **Maya's server posts a webhook** to a URL we
   registered, telling us whether the payment succeeded or failed.
   That's the authoritative signal — we never trust the customer's
   browser to tell us the truth.

So the plugin has four jobs:

- **Settings** — let the merchant enter API keys and configure behavior.
- **Checkout creation** — talk to Maya's API when a customer pays.
- **Webhook handling** — receive Maya's server-to-server callbacks.
- **Admin tooling** — let the merchant test the connection, capture
  authorized payments, refund, etc.

The plugin is **in active rebuild**. The "Test connection" feature
works end-to-end; real payment / webhook / capture flows are being
added phase-by-phase. See [REBUILD_PLAN.md](../REBUILD_PLAN.md) for
the roadmap.

---

## 2. Phase 1 in one sentence

> Mechanical splits with **no behavior change**, to set up the seams
> everything else slots into.

The user-visible behavior didn't change in Phase 1. Internally there
are now named seams for:

- Per-endpoint API wrappers (`Api/Endpoints/*`).
- Immutable response types (`Value/*`).
- Typed enums for known sets.
- A centralized builder for Maya's `requestReferenceNumber`.

Throughout this doc, sections that cover Phase 1's additions are
flagged with **"New in Phase 1"** and include a "Before Phase 1 vs.
After Phase 1" subsection so you see both the *what* and the *why*.

### Phase 1 — definition of done, confirmed

The rebuild plan asked for:

> 8 existing tests still pass + ~10 new DTO tests.

What was shipped:

- **44 tests passing** (was 8 → +36), 96 assertions.
- PHP lint clean across the tree.
- Two bonus fixes caught during real sandbox testing
  (see [§16](#16-real-world-bug-case-study-the-36-character-cap)).

---

## 3. Prerequisites — vocabulary you'll need

Skim these. The rest of the doc assumes they're familiar.

| Concept | What you need to know |
| --- | --- |
| **WordPress plugin** | A folder of PHP files inside `wp-content/plugins/`. WordPress finds the "main" file via a docblock header (`Plugin Name: …`) and loads it on every request. |
| **Hooks** | WordPress's event system. Plugins call `add_action('some_event', $callback)` to react when WP fires that event. `add_filter()` is the same idea but the callback transforms a value. |
| **Hook timing** | Common ones: `plugins_loaded` (all plugins are loaded, do early setup), `init` (later setup), `admin_init` (admin pages only), `wp_ajax_<action>` (AJAX handler routing). |
| **PSR-4 autoloading** | A Composer convention: a class named `Foo\Bar\Baz` is found at `src/Bar/Baz.php` (given the namespace prefix `Foo\\` maps to `src/`). No manual `require_once` needed. |
| **WooCommerce gateway** | A PHP class that extends `WC_Payment_Gateway`. WooCommerce uses duck-typing on certain method names (`process_payment`, `generate_<type>_html`, `validate_<key>_field`) to do its work. |
| **Nonce** | WordPress's CSRF token. You generate one with `wp_create_nonce('action')` and verify with `check_ajax_referer('action', 'nonce')`. |
| **HPOS** | High-Performance Order Storage — WC's newer order storage mode (custom tables instead of `wp_posts`). Plugins must declare compatibility. We do, in the main plugin file. |

---

## 4. The 30-second tour

```
woocommerce-maya-gateway/
├── wc-maya-payment-gateway.php       ← WordPress loads this first
├── composer.json                     ← PSR-4 mapping + dev deps (Pest, etc.)
├── assets/
│   ├── css/maya-admin.css            ← styles for the settings page
│   └── js/maya-admin.js              ← JS for show/hide keys, Test connection
├── docs/                             ← you are here
├── src/                              ← all PHP under namespace TaniKyuun\MayaGateway
└── tests/                            ← Pest unit tests; mirrors src/ layout
```

Inside `src/` — **NEW in Phase 1** directories are flagged:

```
src/
├── Plugin.php                ← entry point: registers everything else
├── Admin/                    ← code that runs on the WP admin screen
│   ├── AdminAssets.php       ← enqueues JS + CSS for the settings page
│   ├── FormFields.php        ← the form_fields[] array WC reads to render settings
│   ├── FieldRenderers.php    ← HTML renderers for custom field types
│   └── Ajax/
│       └── TestConnection.php  ← AJAX handler for the Test connection button
├── Api/
│   ├── MayaApiClient.php     ← HTTP transport (Basic auth, JSON, logging)
│   └── Endpoints/                                              ← NEW in Phase 1
│       ├── Checkouts.php     ← POST /checkout/v1/checkouts → CheckoutSession
│       └── Webhooks.php      ← GET  /checkout/v1/webhooks
├── Gateway/
│   └── MayaGateway.php       ← extends WC_Payment_Gateway — the WC bridge
├── Settings/
│   └── SettingsHelper.php    ← typed getters over WC's options storage
├── Util/
│   ├── IdempotencyKey.php    ← NEW in Phase 1 (requestReferenceNumber builder)
│   └── Logger.php            ← wraps WC_Logger; redacts secrets
├── Value/                                                       ← NEW in Phase 1
│   ├── AuthorizationType.php ← enum: None/Normal/FinalAuth/Preauthorization
│   ├── CheckoutSession.php   ← parsed createCheckout response
│   ├── Money.php             ← amount + currency
│   ├── PaymentRecord.php     ← parsed payment record
│   └── WebhookEvent.php      ← enum of Maya event names
└── Webhook/
    └── WebhookHandler.php    ← inbound webhook receiver (stub for now; Phase 2)
```

---

## 5. How a WordPress plugin actually loads

The very first file is [wc-maya-payment-gateway.php](../../wc-maya-payment-gateway.php).
It looks like:

```php
<?php
/**
 * Plugin Name:       WooCommerce Maya Gateway
 * Description:       Maya payment gateway for WooCommerce (Philippines).
 * Version:           1.0.0
 * Requires PHP:      8.3
 * WC requires at least: 10.6
 */

defined('ABSPATH') || exit;             // can't be hit directly via URL

define('WC_MAYA_PLUGIN_FILE', __FILE__);

require_once __DIR__ . '/vendor/autoload.php';   // Composer autoloader

use TaniKyuun\MayaGateway\Plugin;

add_action('before_woocommerce_init', /* HPOS declaration */ );
add_action('plugins_loaded', [Plugin::class, 'init']);
```

What happens at runtime:

1. WordPress scans `wp-content/plugins/` and reads the docblock header
   of every PHP file to find plugins.
2. If the merchant has activated us, WP requires this file on every
   page load.
3. `vendor/autoload.php` registers Composer's class autoloader. Now
   any reference to `TaniKyuun\MayaGateway\X` will find `src/X.php`
   automatically — that's PSR-4 at work.
4. We declare HPOS compatibility (a current WC requirement).
5. We hook into `plugins_loaded` — a moment WordPress fires once all
   plugins are loaded but before pages render. At that moment we run
   `Plugin::init()`, which is our real entry point.

Why use the `plugins_loaded` hook instead of just running our code
immediately? Because at the time this main file is included, **other
plugins haven't loaded yet**. We need WooCommerce's classes to exist
before we can extend `WC_Payment_Gateway`. The hook gives WP a chance
to load WooCommerce first.

---

## 6. The entry point: `Plugin.php`

[Plugin.php](../../src/Plugin.php) is intentionally tiny. Its only job
is to **wire** the rest of the plugin's subsystems:

```php
class Plugin
{
    public static function init(): void
    {
        if (! class_exists('WooCommerce')) {
            add_action('admin_notices', [ self::class, 'missing_woocommerce_notice' ]);
            return;
        }

        add_filter('woocommerce_payment_gateways', [ self::class, 'register_gateway' ]);
        add_action('woocommerce_api_maya_webhook', [ WebhookHandler::class, 'handle' ]);

        AdminAssets::register();
        TestConnection::register();
    }
}
```

Five things happen:

1. **Defensive check.** If WooCommerce isn't installed/active, we
   bail and show the merchant a notice instead of crashing.
2. **Register the gateway with WC.** When WC asks "what payment
   gateways exist?", it calls every callback hooked to
   `woocommerce_payment_gateways`. We add `MayaGateway::class` to the
   list it returns.
3. **Register the webhook route.** `woocommerce_api_<name>` is WC's
   built-in mechanism for exposing a URL endpoint at
   `?wc-api=<name>` — see [§13](#13-the-webhook-receiver-webhookhandler).
4. **`AdminAssets::register()`** wires the admin-only script + style
   enqueueing.
5. **`TestConnection::register()`** wires the AJAX action that
   powers the "Test connection" button.

### The `register()` convention

Each subsystem class has a `public static function register(): void`
that adds its own WordPress hooks. `Plugin` calls them all from one
place. Why this convention:

- **Discoverability.** Open `Plugin.php` and you immediately see
  every feature surface in 5 lines.
- **Ownership.** The class that uses the hook also registers it. No
  spooky-action-at-a-distance where `Foo` adds hooks for `Bar`.
- **Testability.** A subsystem's hooks can be re-registered in tests
  without booting the whole plugin.

---

## 7. The settings page — how WooCommerce renders it

When a merchant visits **WooCommerce → Settings → Payments → Maya
Checkout**, WC asks our gateway class for a form definition and
renders it for them. Relevant files:

- [src/Gateway/MayaGateway.php](../../src/Gateway/MayaGateway.php) —
  the WC contract surface.
- [src/Admin/FormFields.php](../../src/Admin/FormFields.php) — what
  fields exist.
- [src/Admin/FieldRenderers.php](../../src/Admin/FieldRenderers.php)
  — HTML for custom field types.
- [src/Admin/AdminAssets.php](../../src/Admin/AdminAssets.php) — the
  JS and CSS that decorate the page.

### 7.1 The WC contract: `MayaGateway`

`MayaGateway extends WC_Payment_Gateway`:

```php
class MayaGateway extends WC_Payment_Gateway
{
    public const ID = 'maya_checkout';

    public function __construct() {
        $this->id           = self::ID;
        $this->method_title = __('Maya Checkout', 'wc-maya-gateway');
        $this->init_form_fields();   // populates $this->form_fields
        $this->init_settings();      // loads saved values from wp_options

        add_action('woocommerce_update_options_payment_gateways_' . $this->id,
            [ $this, 'process_admin_options' ]);
    }

    public function init_form_fields(): void {
        $this->form_fields = FormFields::definitions();
    }
}
```

WooCommerce calls `init_form_fields()` to discover the form layout,
then renders each field based on its `type:` (text, password,
checkbox, textarea, select, …). For each field, WC looks up a
method named `generate_<type>_html($key, $data)` on the gateway and
calls it. Built-in types (`text`, `password`, …) are handled by
WC's parent class. Custom types like `test_connection` route to
our `generate_test_connection_html`.

**This is duck-typing by method name.** The bottom of
`MayaGateway.php` has one-line `generate_*_html` methods because
WC's contract demands those names. We delegate the real work to
`FieldRenderers`:

```php
public function generate_test_connection_html(string $key, array $data): string {
    return FieldRenderers::test_connection($this, $key, $data);
}
```

Why the delegation? To keep the gateway class as a **thin bridge**
to WC, and the real rendering logic (HTML, conditionals, hint text)
in a focused renderer file.

### 7.2 The form definition: `FormFields`

[FormFields::definitions()](../../src/Admin/FormFields.php) returns
an array. Each key is the field's option name in `wp_options`;
each value is a WC form-field config:

```php
'public_key' => [
    'title'       => __('Public key', 'wc-maya-gateway'),
    'type'        => 'password',
    'placeholder' => 'pk-...',
    'description' => __('Used to create checkout sessions…'),
    'class'       => 'wc-maya-key-input',
],
```

`type: password` is built into WC and renders an `<input
type="password">`. `type: test_connection` is **ours**; WC will
dispatch its rendering to `generate_test_connection_html`.

### 7.3 The custom renderers: `FieldRenderers`

[FieldRenderers](../../src/Admin/FieldRenderers.php) has two static
methods that produce HTML:

- `test_connection()` — emits the Test connection button, spinner,
  and result placeholder.
- `webhook_url_display()` — emits the read-only Webhook URL field
  with a Copy button.

Static because they don't need state — they take the gateway + field
data in as arguments and return HTML out.

### 7.4 The browser-side glue: `AdminAssets`

[AdminAssets](../../src/Admin/AdminAssets.php) enqueues our JS and
CSS **only on our settings screen** (no point loading on every
admin page):

```php
public static function enqueue(string $hook): void
{
    if (! self::is_maya_settings_screen($hook)) {
        return;
    }
    wp_enqueue_style('wc-maya-admin', $base . '/assets/css/maya-admin.css', …);
    wp_enqueue_script('wc-maya-admin', $base . '/assets/js/maya-admin.js', ['jquery'], …);

    wp_localize_script('wc-maya-admin', 'wcMayaAdmin', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce(self::NONCE_ACTION),
        'actions' => [ 'testConnection' => TestConnection::ACTION ],
        'i18n'    => [ /* translated UI strings */ ],
    ]);
}
```

`wp_localize_script` is the standard way to pass data from PHP to
JS. It declares `window.wcMayaAdmin = { ajaxUrl, nonce, … }` *before*
the JS file runs. The JS then reads it:

```js
$.post(wcMayaAdmin.ajaxUrl, {
    action: wcMayaAdmin.actions.testConnection,
    nonce:  wcMayaAdmin.nonce,
});
```

Why localize instead of inlining the values into the JS file?
Because the JS file is **cached** by the browser. Dynamic values
(nonce, AJAX URL, translated strings) have to be injected at render
time, not bundled into the cacheable file.

---

## 8. Value Objects — the typed core *(New in Phase 1)*

The single biggest payoff of Phase 1 is that the rest of the
codebase can stop carrying around raw associative arrays for API
responses.

### 8.1 What's a Value Object?

A **Value Object** (VO) is an immutable class that holds typed data
and no behavior beyond convenience methods. Two `Money(100.0, 'PHP')`
instances are interchangeable — they have no identity beyond their
values.

Why immutable?

- You can pass them anywhere without worrying that some downstream
  function will mutate them mid-flight.
- Tests don't need to "reset" them between cases.
- Static analysis (PHPStan, Psalm) can reason about them precisely.

PHP 8.2+ has a language feature for this: **readonly classes**.

```php
final readonly class Money
{
    public function __construct(
        public float $value,
        public string $currency = 'PHP',
    ) {}
}
```

- `final` — nobody can subclass this. (Subclasses could add mutable
  state, defeating the point.)
- `readonly` — every property is implicitly assigned-once. The
  language rejects `$money->value = 200;` at runtime.
- Constructor promotion (`public float $value`) makes the
  constructor parameter list double as the property list. No
  boilerplate.

### 8.2 The five new types

| File | Wraps | Used for |
| --- | --- | --- |
| [Money.php](../../src/Value/Money.php) | `{value, currency}` | The `totalAmount` / `amount` / `capturedAmount` fields Maya uses everywhere |
| [CheckoutSession.php](../../src/Value/CheckoutSession.php) | `{checkoutId, redirectUrl}` | Response from `POST /checkout/v1/checkouts` |
| [PaymentRecord.php](../../src/Value/PaymentRecord.php) | Maya's payment record (id, status, amount, captured_amount, can_void, can_refund, can_capture, …) | Will be used in Phase 4+ when we read payments by RRN |
| [AuthorizationType.php](../../src/Value/AuthorizationType.php) | Enum `{None, Normal, FinalAuth, Preauthorization}` | The four manual-capture modes |
| [WebhookEvent.php](../../src/Value/WebhookEvent.php) | Enum of Maya's documented event names | The webhook event dispatcher (Phase 2) |

### 8.3 The `from_array()` convention

Every VO has a static `from_array(array): self` factory:

```php
public static function from_array(array $data): self
{
    return new self(
        (float) ($data['value'] ?? 0),
        (string) ($data['currency'] ?? 'PHP'),
    );
}
```

Three things to call out:

1. **The keys mirror Maya's wire format.** Maya sends `value` /
   `currency`, so the factory reads those keys. PHP-side property
   names can diverge if useful (we use `checkout_id` even though
   Maya sends `checkoutId`).
2. **Null-coalescing defaults.** `$data['value'] ?? 0` means if
   Maya omits the field, we don't crash — we get a deterministic
   default.
3. **Explicit casts.** Numeric strings like `"100.50"` get
   normalized to `100.5`.

The point: **once a Maya response has been through `from_array()`,
no downstream code has to do null-coalescing or casting again**.

### 8.4 Enums

PHP 8.1+ enums look like this:

```php
enum AuthorizationType: string
{
    case None             = 'none';
    case Normal           = 'normal';
    case FinalAuth        = 'final';
    case Preauthorization = 'preauthorization';

    public function for_maya_api(): string {
        return strtoupper($this->value);
    }

    public function is_manual_capture(): bool {
        return self::None !== $this;
    }
}
```

Upsides vs. plain constants:

**Compile-time safety.** A function signature
`f(AuthorizationType $t)` only accepts the four cases. You can't
typo `AuthorizationType::Normall`.

**Methods.** Enums can carry behavior. `for_maya_api()` is a
perfect example: Maya wants `'NORMAL'` (uppercase) in API calls but
we store `'normal'` (lowercase) so it survives serialization
cleanly. The conversion lives on the type itself, not scattered
across callers.

Same idea for `WebhookEvent`:

```php
public function is_terminal_failure(): bool {
    return match ($this) {
        self::PaymentFailed, self::PaymentExpired, self::PaymentCancelled,
        self::CheckoutFailure, self::CheckoutDropout, self::AuthFailed => true,
        default => false,
    };
}
```

When the event dispatcher (Phase 2) needs to decide "should this
mark the order as failed?", it just asks the enum.

### 8.5 The `from_setting()` safety net

The enums have a forgiving parser for stored option values:

```php
public static function from_setting(mixed $value): self
{
    if (! is_string($value)) {
        return self::None;
    }
    return self::tryFrom(strtolower(trim($value))) ?? self::None;
}
```

Why? Because `wp_options` could contain a typo or a value left over
from an old plugin version. We don't want a fatal error on every
page load — we want to fall back to a safe default and keep
working. **Crash-safety at the boundary, type-safety inside.**

### 8.6 Before / after — what these replaced

Before Phase 1, code that touched API responses did this:

```php
$response = $client->createCheckout($payload);    // returns associative array
$checkoutId = $response['checkoutId'] ?? '';      // null-coalesce at every read
```

After Phase 1:

```php
$session = (new Checkouts($client))->create($payload);    // returns CheckoutSession|WP_Error
if (! $session instanceof WP_Error) {
    $checkoutId = $session->checkout_id;                  // typed property; IDE autocomplete
}
```

The IDE knows the shape. Static analysis catches typos. Tests can
assert against typed objects instead of array-equality.

---

## 9. Talking to Maya's API *(reshaped in Phase 1)*

Three layers, each with one job:

```
Caller (TestConnection, future PaymentProcessor)
    │
    ▼
Api/Endpoints/* (typed wrappers)
    │   - knows the path
    │   - knows which key family
    │   - converts response → DTO
    │
    ▼
Api/MayaApiClient (transport)
    │   - knows Basic auth, JSON, logging
    │   - does NOT know endpoints
    │
    ▼
wp_remote_request → Maya
```

### 9.1 What was wrong before Phase 1

`MayaApiClient` mixed three responsibilities:

1. HTTP transport (Basic auth, JSON, logging).
2. Endpoint knowledge (`'POST /checkout/v1/checkouts'`,
   `'GET /payments/v1/webhooks'`).
3. Orchestration (build a test payload, run two probes, package
   results).

The `test_connection()` method baked all three into one place.
That made it hard to test the orchestration without making real
HTTP calls, hard to add new endpoints, and hard to reuse the
transport.

### 9.2 The transport: `MayaApiClient`

[MayaApiClient](../../src/Api/MayaApiClient.php) is endpoint-agnostic:

```php
public function request(string $method, string $path, ?array $body, string $key): array|WP_Error
{
    $api_key = self::KEY_SECRET === $key ? $this->secret_key : $this->public_key;
    // … build Authorization header
    // … wp_remote_request
    // … parse JSON or return WP_Error
}
```

The signature tells you the contract: "you tell me the method, the
path, the body, and which key family to use; I'll give you back the
decoded body or a typed error." It doesn't know what an endpoint
is for.

Three things in `request()`:

**Basic Auth with empty password.** Maya's auth model is
`Basic base64({api_key}:)` — the key is the "username" and the
password is intentionally empty. The trailing colon matters.

**Caller picks which key.** We don't bake "public" or "secret"
into the client. Each endpoint that calls `request()` passes
`MayaApiClient::KEY_PUBLIC` or `KEY_SECRET` because each Maya
endpoint documents which one it requires.

**`array|WP_Error` return.** This is WordPress's idiomatic
"either value or error" pattern. Every caller checks `$result
instanceof WP_Error` before assuming success. Similar to Rust's
`Result<T, E>` but at the type-system level it's a union.

### 9.3 The endpoint wrappers: `Api/Endpoints/*`

Each file represents one logical group of Maya endpoints. For now
we have two:

[Checkouts](../../src/Api/Endpoints/Checkouts.php):

```php
class Checkouts
{
    public function __construct(private readonly MayaApiClient $client) {}

    public function create(array $payload): CheckoutSession|WP_Error
    {
        $response = $this->client->request(
            'POST', '/checkout/v1/checkouts',
            $payload, MayaApiClient::KEY_PUBLIC,
        );
        return $response instanceof WP_Error ? $response : CheckoutSession::from_array($response);
    }
}
```

Three pieces of knowledge that live here and nowhere else:

- **Path** — `/checkout/v1/checkouts`
- **Key family** — `KEY_PUBLIC`
- **DTO mapping** — `CheckoutSession::from_array(...)`

If Maya rev's the API path tomorrow, you change one line in one
file.

[Webhooks](../../src/Api/Endpoints/Webhooks.php) follows the same
pattern with `GET /checkout/v1/webhooks` and `KEY_SECRET`.

### 9.4 Why this scales

- **Phase 4** will add `process_payment()` (real checkouts). It
  will use the same `Checkouts::create()` method — just with a
  real-order payload instead of a test payload. Zero new endpoint
  code needed.
- **Phase 5** will add `Api/Endpoints/Payments.php` for capture /
  void / refund. Same pattern.
- **Phase 3** will extend `Api/Endpoints/Webhooks.php` with
  `create()` / `delete()` methods. Same pattern.

The transport never grows.

### 9.5 Maya API authentication, quickly

Maya issues **two key types**: public (`pk-…`) and secret
(`sk-…`).

| Endpoint | Key family | Why |
| --- | --- | --- |
| `POST /checkout/v1/checkouts` | Public | Called from server when initiating a checkout |
| `GET /checkout/v1/webhooks` | Secret | Reading + managing webhooks |
| `POST /checkout/v1/webhooks` | Secret | Creating webhooks |

Note: there's a separate `/payments/v1/*` endpoint family that
belongs to Maya's **Payment Vault** product, which has its **own**
key pair. Our plugin uses **Maya Checkout** only — don't mix them
or you'll get 401s. (Yes, this caught us in the early test
connection rounds.)

---

## 10. Settings storage — `SettingsHelper`

WooCommerce stores each gateway's settings as a serialized array
under `wp_options` keyed `woocommerce_<gateway_id>_settings`. You can
read it with `$gateway->get_option('public_key')`.

That works but it's untyped — you get back whatever string the
merchant saved, including empty strings, leading whitespace, etc.
[SettingsHelper](../../src/Settings/SettingsHelper.php) gives every
option a typed getter:

```php
public function public_key(): string {
    return trim((string) $this->gateway->get_option('public_key'));
}

public function is_sandbox(): bool {
    return 'yes' === $this->gateway->get_option('is_sandbox', 'yes');
}

public function webhook_url(): string {
    $override = $this->local_dev_webhook_base_url();
    if ('' === $override) {
        return home_url('/?wc-api=' . self::WEBHOOK_ROUTE);
    }
    // … compose from override …
}
```

### Why is this under `Settings/` and not `Admin/`?

Because **it's read by code that runs outside the admin screen too**.
The webhook handler (Phase 2) will check `is_sandbox()` to pick
which RSA public keys to verify against. The payment processor
(Phase 4) will read `public_key()` and `secret_key()` for every
checkout request. Settings access is not admin-only.

This is also why `webhook_url()` exists as a helper: composing the
final URL (with the local-dev override) is logic, not just a
getter. Both the admin display and the runtime check use the same
function.

---

## 11. Building reference numbers — `IdempotencyKey` *(New in Phase 1)*

[Util/IdempotencyKey.php](../../src/Util/IdempotencyKey.php) is a
small but load-bearing utility.

Maya's API has a field called `requestReferenceNumber` (RRN). It's
the merchant-side correlation id — payments under the same RRN are
grouped (auth → capture → refund). For real orders, it's the WC
order id; for diagnostic calls we want something identifiable.

A naive approach would be `'wc-maya-test-' . wp_generate_uuid4()`
at each call site. **That broke in production** — see
[§16](#16-real-world-bug-case-study-the-36-character-cap). Maya
caps RRNs at 36 characters; a 13-char prefix + 36-char UUID = 49
chars = rejected.

Centralizing in `IdempotencyKey` means:

- One place that knows Maya's 36-char cap.
- One place to update if Maya's contract changes.
- The intent is in the function name (`for_test_connection()` vs
  `for_order()`), not buried at call sites.

```php
public const TEST_PREFIX          = 'wc-maya-test-';
public const MAX_REFERENCE_LENGTH = 36;

public static function for_test_connection(): string
{
    $hex           = str_replace('-', '', self::uuid());  // 32 hex chars
    $suffix_length = max(1, self::MAX_REFERENCE_LENGTH - strlen(self::TEST_PREFIX));
    return self::TEST_PREFIX . substr($hex, 0, $suffix_length);
}
```

`for_test_connection()` always returns exactly 36 characters. If
someone later lengthens the prefix, `max(1, …)` keeps at least one
character of randomness so we don't silently collide.

---

## 12. Logging — `Logger`

[Util/Logger.php](../../src/Util/Logger.php) is a thin wrapper around
WooCommerce's built-in `WC_Logger`. WC ships a logger that writes
to `wp-content/uploads/wc-logs/` and surfaces in **WooCommerce →
Status → Logs**. Our wrapper adds two features:

### 12.1 A debug toggle

When a `Logger` is constructed with `new Logger($debug_enabled =
false)`, the `debug()` and `info()` methods are silently dropped.
Only `warning()` and `error()` always write. Production sites don't
drown in noise unless the merchant turns on "Debug log" in
settings.

```php
public function debug(string $message, array $context = []): void {
    if (! $this->debug_enabled) {
        return;
    }
    $this->log('debug', $message, $context);
}
```

### 12.2 Secret redaction

The `context` array passed to log calls might contain headers or
settings that include API keys. Before writing, the logger walks
the context array recursively and replaces any value under a known
sensitive key with `'[redacted]'`:

```php
private const REDACT_KEYS = [
    'authorization', 'secret_key', 'public_key', 'api_key', 'secret',
];
```

This is **defense in depth** — we already don't pass headers into
the log context, but if someone does in the future, the redactor
catches it.

---

## 13. The webhook receiver — `WebhookHandler`

[WebhookHandler](../../src/Webhook/WebhookHandler.php) is currently
a stub. Phase 2 of [REBUILD_PLAN.md](../REBUILD_PLAN.md) builds it
out fully (RSA signature verification, IP allowlist, event
dispatch). For now it has only the two IP-allowlist constants from
Maya's docs:

```php
public const SANDBOX_IPS    = ['13.229.160.234', '3.1.199.75'];
public const PRODUCTION_IPS = ['18.138.50.235', '3.1.207.200'];
```

When a webhook arrives, Maya signs the payload with their private
key and includes timestamp + signature headers. The full
verification flow is documented in the rebuild plan; for now the
handler responds with a 503 telling Maya "not implemented yet."

### How does Maya's request reach this code at all?

WooCommerce has a feature called **`wc-api` routes**. When you
hook into `woocommerce_api_<route_name>`, WC creates a URL
endpoint at `https://your-site.com/?wc-api=<route_name>`. We
register ours in `Plugin::init()`:

```php
add_action('woocommerce_api_maya_webhook', [WebhookHandler::class, 'handle']);
```

So when Maya POSTs to `https://your-site.com/?wc-api=maya_webhook`,
`WebhookHandler::handle()` runs. For local development, your dev
site is on `localhost` (or a `.test` domain), which Maya's servers
can't reach. The [webhook-tunneling.md](../webhook-tunneling.md)
doc covers exposing your dev site via a Cloudflare tunnel.

---

## 14. Worked example: Test connection, end-to-end

This ties everything above together. Trace what happens when the
merchant clicks **Test connection**.

### Step 1 — Browser click

```js
// assets/js/maya-admin.js
$btn.on('click', function () {
    const payload = {
        action:     wcMayaAdmin.actions.testConnection,   // 'wc_maya_test_connection'
        nonce:      wcMayaAdmin.nonce,
        public_key: $('#woocommerce_maya_checkout_public_key').val(),
        secret_key: $('#woocommerce_maya_checkout_secret_key').val(),
        is_sandbox: $('#woocommerce_maya_checkout_is_sandbox').is(':checked') ? 'yes' : 'no',
        debug_log:  $('#woocommerce_maya_checkout_debug_log').is(':checked') ? 'yes' : 'no'
    };
    $.post(wcMayaAdmin.ajaxUrl, payload).done(/* renderResult */);
});
```

The JS reads the **currently-entered** values (not the saved
values) so the merchant can test their keys before saving.

### Step 2 — WordPress AJAX routing

The browser POSTs to `/wp-admin/admin-ajax.php` with
`action=wc_maya_test_connection`. WP looks up handlers under
`wp_ajax_wc_maya_test_connection` and finds
[TestConnection::handle()](../../src/Admin/Ajax/TestConnection.php),
registered via:

```php
public static function register(): void {
    add_action('wp_ajax_' . self::ACTION, [self::class, 'handle']);
}
```

(`wp_ajax_*` is only for logged-in users; `wp_ajax_nopriv_*` is
for guests. We're admin-only.)

### Step 3 — Permission + nonce checks

```php
public static function handle(): void {
    if (! current_user_can('manage_woocommerce')) {
        wp_send_json_error([ 'message' => __('Insufficient permissions.', …) ], 403);
    }
    check_ajax_referer(AdminAssets::NONCE_ACTION, 'nonce');
    // …
}
```

`current_user_can('manage_woocommerce')` rejects anyone who isn't
an admin / shop manager. `check_ajax_referer` verifies the nonce
generated by `wp_create_nonce()` and shipped to the JS via
`wp_localize_script`. Together these prevent CSRF and restrict the
AJAX action to legitimate admin users.

### Step 4 — Build the API client

If the form has unsaved keys in the POST, we use those. Otherwise
we read the saved gateway settings:

```php
if ('' !== $public_key && '' !== $secret_key) {
    $client = new MayaApiClient($public_key, $secret_key, $is_sandbox, new Logger($debug_log));
} else {
    $client = $gateway->build_api_client();
}
```

### Step 5 — Probe the public key

The probe creates a real checkout session — the **same call**
WooCommerce makes for a real purchase, so a 200 response proves
the key works:

```php
$reference = IdempotencyKey::for_test_connection();
$payload   = self::build_test_checkout_payload($reference, home_url('/'));
$response  = (new Checkouts($client))->create($payload);
```

Trace `Checkouts::create($payload)`:

1. Calls `MayaApiClient::request('POST', '/checkout/v1/checkouts',
   $payload, KEY_PUBLIC)`.
2. The client builds the Authorization header
   (`Basic base64(pk-…:)`), serializes the payload to JSON, calls
   `wp_remote_request()`.
3. Maya returns 200 with `{"checkoutId": "…", "redirectUrl": "…"}`.
4. The client logs the request + response, returns the decoded
   array.
5. `Checkouts::create()` converts that array to a `CheckoutSession`
   value object.

The `CheckoutSession` percolates back to `probe_public_key`, which
formats the success result:

```php
return [
    'ok'         => true,
    'checkoutId' => $response->checkout_id,
    'reference'  => $reference,
];
```

**No money moves.** Maya only charges a card when a real customer
completes the hosted-checkout page. We never send anyone to the
returned URL, so the session just expires.

### Step 6 — Probe the secret key

```php
$response = (new Webhooks($client))->all();
// → GET /checkout/v1/webhooks with the secret key
```

A 200 response means the secret key is valid for the same merchant
account that owns the public key.

### Step 7 — Send the JSON response

```php
wp_send_json_success([
    'public_key'  => self::probe_public_key($client),
    'secret_key'  => self::probe_secret_key($client),
    'environment' => $client->is_sandbox() ? 'sandbox' : 'production',
]);
```

### Step 8 — JS renders the result

```js
$.post(wcMayaAdmin.ajaxUrl, payload)
    .done(function (response) {
        if (response.success) {
            renderResult($result, response.data);
        }
    });
```

`renderResult` walks `response.data`, builds an `<ul>` with one
`<li>` per probe (public + secret), and inserts it under the
button.

### The arrows-and-boxes view

```
1. User clicks "Test connection" in WC settings
   ↓
2. assets/js/maya-admin.js reads form values, POSTs to admin-ajax.php
   ↓
3. WP routes to TestConnection::handle()  (registered in Plugin::init)
   ↓
4. permission check (current_user_can) + nonce (check_ajax_referer)
   ↓
5. Build MayaApiClient (transport)
   ↓
6. probe_public_key:
   ├── IdempotencyKey::for_test_connection() → 36-char RRN
   ├── TestConnection::build_test_checkout_payload($rrn, home_url('/'))
   │   └── uses Money(100.0, 'PHP')->to_array() for totalAmount
   ├── (new Checkouts($client))->create($payload)
   │   └── MayaApiClient::request('POST', '/checkout/v1/checkouts', $payload, KEY_PUBLIC)
   │       ├── Logger::debug() — outbound
   │       ├── wp_remote_request()
   │       └── Logger::info() — inbound 200
   │   └── CheckoutSession::from_array($response)
   └── return [ok, checkoutId, reference]
   ↓
7. probe_secret_key:
   ├── (new Webhooks($client))->all()
   │   └── MayaApiClient::request('GET', '/checkout/v1/webhooks', null, KEY_SECRET)
   └── return [ok, webhookCount]
   ↓
8. wp_send_json_success({public_key, secret_key, environment})
   ↓
9. JS renders <ul> with per-probe rows
```

Every box on the right of the arrows touches a Phase 1 file. The
seams will be reused in Phases 2+.

### 14.1 The pure payload builder

The single most interesting bit of `TestConnection` is the
createCheckout payload — that's the contract with Maya. It's
extracted into a public pure function so it can be unit-tested
without any Maya call:

```php
public static function build_test_checkout_payload(string $reference, string $return_url): array
{
    $amount = new Money(self::TEST_AMOUNT, self::TEST_CURRENCY);

    return [
        'totalAmount'            => $amount->to_array(),
        'requestReferenceNumber' => $reference,
        'redirectUrl'            => [
            'success' => $return_url,
            'failure' => $return_url,
            'cancel'  => $return_url,
        ],
        'metadata' => [
            'source' => self::TEST_METADATA_SOURCE,
        ],
    ];
}
```

Two things to call out:

**`$amount->to_array()`** — we use the `Money` DTO not because
it's strictly required (the resulting array is identical to a
literal), but to prove the foundation actually flows through every
layer. If `Money` ever gains a field, every test-checkout payload
picks it up automatically.

**Class constants** — `TEST_AMOUNT`, `TEST_CURRENCY`,
`TEST_METADATA_SOURCE`. Pulling these out of the method body
means: the metadata source string is greppable; future tuning is a
one-line change.

---

## 15. The `TestConnection` refactor — before / after *(Phase 1)*

### Before Phase 1

```php
$client = new MayaApiClient(...);
wp_send_json_success($client->test_connection());   // probe logic inside the client
```

All the probe orchestration lived inside
`MayaApiClient::test_connection()`. That method built the test
checkout payload, called both endpoints, formatted both results,
and returned a single nested array — *and* the client knew which
endpoints to call.

### After Phase 1

```php
$client = new MayaApiClient(...);
wp_send_json_success([
    'public_key'  => self::probe_public_key($client),   // uses Checkouts endpoint wrapper
    'secret_key'  => self::probe_secret_key($client),   // uses Webhooks endpoint wrapper
    'environment' => $client->is_sandbox() ? 'sandbox' : 'production',
]);
```

Each probe is a small method on `TestConnection` itself. The
client returned to pure transport, the orchestration moved to its
caller, and the payload composition got a testable pure function.

---

## 16. Real-world bug case study: the 36-character cap

During a live sandbox test the public-key probe failed with:

```
Warning <- POST /checkout/v1/checkouts 400 Missing/invalid parameters.
{"body":{"code":"2553","message":"Missing/invalid parameters.",
 "parameters":[{"description":"length must be at most 36","field":"requestReferenceNumber"}]}}
```

Two problems exposed at once.

### 16.1 The 36-char RRN cap

The original `for_test_connection()` was:

```php
return self::TEST_PREFIX . self::uuid();   // 13 + 36 = 49 chars → REJECTED
```

The fix (current code, [§11](#11-building-reference-numbers--idempotencykey-new-in-phase-1))
strips the UUID hyphens (down to 32 chars) and slices to fit the
remaining budget. We also added `MAX_REFERENCE_LENGTH = 36` as a
constant so the cap is documented + enforced. A new test asserts
the cap is honored:

```php
test('for_test_connection fits inside Maya\'s requestReferenceNumber limit', function (): void {
    expect(strlen(IdempotencyKey::for_test_connection()))
        ->toBeLessThanOrEqual(IdempotencyKey::MAX_REFERENCE_LENGTH);
});
```

### 16.2 The unhelpful error display

The JS showed only `"Missing/invalid parameters."` — useless
without the field-level detail (`"length must be at most 36"`).
The error body had it; we just weren't reading it.

We added `MayaApiClient::format_parameter_details()`:

```php
public static function format_parameter_details(array $decoded): string
{
    // Walk $decoded['parameters'][], build " (field1: desc1; field2: desc2)"
}
```

…called by `request()` to enrich the WP_Error message. Now the
same failure renders as:

> Missing/invalid parameters. (requestReferenceNumber: length must be at most 36)

`format_parameter_details` is public + static so it's directly
unit-testable as a pure function — no need to mock HTTP just to
verify the formatter.

### 16.3 The takeaway

Phase 1's "no behavior change" rule deliberately excludes bugfixes
caught during testing. Both fixes are small, isolated, and each
ships with new test coverage. **Document the surprise; don't just
fix it silently.**

---

## 17. How tests work

We use [Pest](https://pestphp.com/) — a modern testing framework
on top of PHPUnit. Tests live under `tests/Unit/` and mirror the
`src/` structure.

A simple test:

```php
test('Money::from_array casts numeric strings to float', function (): void {
    $money = Money::from_array(['value' => '100.50', 'currency' => 'PHP']);
    expect($money->value)->toBe(100.5)
        ->and($money->currency)->toBe('PHP');
});
```

### Stubbing WordPress functions with Brain Monkey

For tests that touch WP functions (e.g., `home_url()`), we use
**Brain Monkey** to stub them:

```php
beforeEach(function (): void {
    Functions\when('home_url')->alias(
        static fn (string $path = ''): string => 'https://example.test' . $path,
    );
});
```

`when('home_url')->alias(...)` patches the `home_url` function for
the duration of each test. After the test, `Monkey\tearDown()`
(registered in `tests/Pest.php`) puts the original back.

### Mocking classes with Mockery

For tests that need to mock an entire class (so we don't actually
hit Maya's API), we use **Mockery**:

```php
$client = Mockery::mock(MayaApiClient::class);
$client->expects('request')
    ->with('POST', '/checkout/v1/checkouts', $payload, MayaApiClient::KEY_PUBLIC)
    ->andReturn(['checkoutId' => 'abc', 'redirectUrl' => 'https://…']);

$session = (new Checkouts($client))->create($payload);
expect($session->checkout_id)->toBe('abc');
```

Mockery generates a fake `MayaApiClient` whose `request` method
returns our scripted response — no HTTP call ever happens.

Running tests: `./vendor/bin/pest` from the plugin root.

---

## 18. Test coverage shipped in Phase 1

8 → **44 tests**, 80+ assertions:

| File | Tests | Covers |
| --- | --- | --- |
| `Value/MoneyTest.php` | 3 | Numeric-string coercion, default currency, round-trip |
| `Value/CheckoutSessionTest.php` | 3 | Field parsing, missing-field defaults, to-array shape |
| `Value/PaymentRecordTest.php` | 3 | Full record parsing, optional `capturedAmount`, currency inheritance |
| `Value/AuthorizationTypeTest.php` | 4 | `for_maya_api()`, `is_manual_capture()`, `from_setting()` forgiveness |
| `Value/WebhookEventTest.php` | 5 | `try_from_string()` trim + reject, `is_terminal_*()` classifiers |
| `Util/IdempotencyKeyTest.php` | 5 | UUID delegation, order id stringify, test prefix, length cap |
| `Api/Endpoints/CheckoutsTest.php` | 2 | Correct path + key (via Mockery), WP_Error passthrough |
| `Api/Endpoints/WebhooksTest.php` | 2 | Correct path + key, WP_Error passthrough |
| `Api/MayaApiClientTest.php` | +4 | `format_parameter_details` for single/multi/empty/partial cases |
| `Admin/Ajax/TestConnectionTest.php` | 5 | Payload shape (Money usage, mirrored URLs, metadata, constants) |

What this buys us going forward:

- Future refactors against the same contracts are safe.
- Any regression in DTO parsing trips a test immediately.
- The endpoint wrappers can be replaced with a different transport
  (e.g., a fake for integration tests) — Mockery patterns are
  already established.

---

## 19. Anti-patterns we deliberately avoided

Phase 1 had several "ooh, while we're in here…" temptations. We
skipped them; they're documented here for future-you:

| Tempted to | Why we didn't |
| --- | --- |
| Pre-create `Api/Endpoints/Payments.php` | No caller needs it yet. Adding empty files makes the codebase look more complete than it is and clutters tab-completion. Phase 5 creates it with real methods. |
| Extract `ProbeResult` to `Value/` | Probe results are UI-flow shaped (`ok / message / checkoutId / webhookCount`), not domain shaped. `Value/` is for domain types. Adding a class for one-use admin output is over-engineering. |
| Split `IpAllowlist` out of `WebhookHandler` | The handler is still a stub and the IPs are two-element constants. The split is in Phase 2's scope. |
| Make `Money` operations rich (`add`, `subtract`, `equals`) | We have no caller that needs them. Add when the first one shows up. |
| Auto-detect debug log in `Logger` from `WP_DEBUG` | The plan is explicit: merchant-controlled checkbox. Don't smuggle environment-driven behavior into the logger. |

The general rule: **the trigger condition is in the rebuild plan,
not "I'm here anyway"**.

---

## 20. How to do common tasks

| Task | What to do | Why |
| --- | --- | --- |
| Add a setting | Add an entry to [FormFields::definitions()](../../src/Admin/FormFields.php). Add a typed getter to [SettingsHelper](../../src/Settings/SettingsHelper.php) if the value is read in more than one place. | Form definitions live in one file so the merchant-facing schema is easy to audit. |
| Add a custom field type (e.g., a badge) | Add `type: my_badge` in `FormFields`. Add a `public static function my_badge(WC_Payment_Gateway $gateway, string $key, array $data): string` to [FieldRenderers](../../src/Admin/FieldRenderers.php). Add a one-line `generate_my_badge_html` delegate to [MayaGateway](../../src/Gateway/MayaGateway.php). | WC's API requires the `generate_<type>_html` name on the gateway — we satisfy that with a thin pass-through. |
| Add a Maya API call | Add a method to the matching `Api/Endpoints/*` class (or create a new endpoint class). Inside, call `$this->client->request(method, path, body, key)` and convert the response via `from_array()` on a `Value/*` DTO. | The transport stays endpoint-agnostic; per-endpoint shaping lives next to the endpoint. |
| Add a response DTO | New file under [src/Value/](../../src/Value/) with a `final readonly` class, public typed properties, and `from_array(array): self`. | Keeps the API surface typed everywhere downstream. |
| Add an admin AJAX endpoint | New file in [src/Admin/Ajax/](../../src/Admin/Ajax/) with a `public const ACTION`, `public static register()`, and `public static handle()`. Hook it in [Plugin::init()](../../src/Plugin.php). Add a localized i18n entry in [AdminAssets](../../src/Admin/AdminAssets.php). | One file per AJAX action keeps each handler focused. |
| Build a Maya RRN | Use [IdempotencyKey](../../src/Util/IdempotencyKey.php) (`for_order`, `for_test_connection`). Don't call `wp_generate_*` at the call site. | Centralizes Maya's 36-char limit handling. |
| Log something during a request | Use the `Logger` already injected into `MayaApiClient`. For new subsystems, accept `?Logger $logger = null` via the constructor and fall back to `new Logger()`. | Debug toggle and redaction stay applied. |

---

## 21. Verify Phase 1 yourself

### Run the tests

```bash
cd web/app/plugins/woocommerce-maya-gateway
./vendor/bin/pest
```

Expected: `Tests: 44 passed`.

### PHP lint

```bash
find src tests -name '*.php' -print | xargs -n1 php -l
```

Expected: every line says `No syntax errors detected in …`.

### Manual smoke test

1. Visit WooCommerce → Settings → Payments → Maya Checkout.
2. Paste your sandbox keys (or save then leave them blank to fall
   back to saved values).
3. Click **Test connection**.
4. Watch the result panel:
   - Environment: `Testing against sandbox (pg-sandbox.paymaya.com).`
   - Public key: `Checkout session created (id <uuid>) — no payment was taken.`
   - Secret key: `N webhook(s) registered with Maya for this account.`

If the public-key row shows a 400 error with field-level details
in parentheses (e.g. `(requestReferenceNumber: length must be at
most 36)`), that's the new `format_parameter_details` working.
Successful runs won't trigger it.

### Verify logging

If you ticked **Debug log** in settings, **WooCommerce → Status →
Logs** will show a `wc-maya-gateway-YYYY-MM-DD-*.log` file with the
request/response trace. The Authorization header is never logged;
only metadata, payload body, and decoded responses.

---

## 22. Glossary

| Term | Meaning |
| --- | --- |
| **Action** | A WordPress hook that "fires" at a specific moment. Callbacks receive arguments and return nothing. Registered via `add_action('event_name', $callback)`. |
| **Filter** | Like an action, but the callback transforms a value and returns it. Registered via `add_filter`. |
| **Hook** | Umbrella term for actions + filters. |
| **Nonce** | A short-lived CSRF token. `wp_create_nonce('action')` produces one; `check_ajax_referer('action', 'nonce')` verifies it. |
| **WC_API route** | A URL endpoint exposed at `?wc-api=<name>`. Hook into `woocommerce_api_<name>` to handle requests. |
| **HPOS** | High-Performance Order Storage — WC's custom-tables order storage. Plugins must opt-in by declaring compatibility. |
| **Sandbox** | Maya's test environment at `pg-sandbox.paymaya.com`. Uses test cards, never charges real money. |
| **Production** | Maya's real environment at `pg.maya.ph`. Real cards, real money. |
| **Webhook** | An HTTP POST from Maya's servers to our URL when something happens (payment success/failure/expiry). The authoritative payment signal. |
| **Idempotency / RRN** | `requestReferenceNumber` — Maya's correlation id. Reusing the same RRN groups payments (auth → capture → refund). |
| **DTO** | Data Transfer Object — an immutable class that holds typed data, no behavior. `Money`, `CheckoutSession`. |
| **PSR-4** | A Composer autoloading standard. Namespace `Foo\Bar\Baz` lives at `src/Bar/Baz.php`. |
| **Enum** | A PHP 8.1+ type with a fixed set of named values. `WebhookEvent::PaymentSuccess`. |
| **Readonly class** | A PHP 8.2+ class where every property is implicitly readonly — assigned once, then immutable. |
| **WP_Error** | WordPress's "error object" — used as a return value to signal failure. Check with `$result instanceof WP_Error`. |
| **AJAX** | "Asynchronous JavaScript and XML" — a JS-initiated HTTP request that doesn't reload the page. We use it for the Test connection button. |
| **wp_localize_script** | The standard way to pass PHP data to a JS file via a global JS variable, set up at render time. |
| **`wp_options` / option** | WordPress's key-value store. Settings live here. WC saves gateway settings under `woocommerce_<gateway_id>_settings`. |
| **Composer** | PHP's dependency manager. `composer install` reads `composer.json` and populates `vendor/`. |

---

## 23. Where to read next

- [../REBUILD_PLAN.md](../REBUILD_PLAN.md) — the master plan;
  Phase 2 is next (webhook reception + signature verification).
- [../architecture.md](../architecture.md) — the "which file do I
  open?" reference, concise.
- [../webhook-tunneling.md](../webhook-tunneling.md) — how to
  expose your dev site so Maya can reach you.
- [Maya developer docs](https://developers.maya.ph/) — the source
  of truth for API contracts, webhook payloads, test cards.
- [WooCommerce payment gateway docs](https://woocommerce.com/document/payment-gateway-api/)
  — the `WC_Payment_Gateway` API.
- [Pest docs](https://pestphp.com/docs) — testing framework we use.

Welcome aboard.
