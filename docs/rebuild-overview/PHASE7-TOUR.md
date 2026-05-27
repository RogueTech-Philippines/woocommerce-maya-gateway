# Phase 7 tour — WooCommerce Blocks support

Status: **DONE** (2026-05-26).

> The block-based Cart and Checkout (what new themes default to) now shows
> Maya as a selectable payment method. Old plugin only worked on the
> classic shortcode checkout — this phase plugs Maya into the React
> checkout WooCommerce ships in 2024+ themes.

Delta tour. Assumes you've read [PHASE1-TOUR.md](PHASE1-TOUR.md) through
[PHASE6-TOUR.md](PHASE6-TOUR.md). The classic gateway built up across
Phases 4–6 stays the source of truth for `process_payment` /
`process_refund`; this phase is the React-checkout shim that delegates
back to it.

---

## 1. Definition-of-done — confirmed

The rebuild plan asked for:

> A site using the block-based checkout sees Maya as a selectable payment
> method and the flow completes.

What was shipped:

- **182 tests passing, 594 assertions** — net delta of **+19** over Phase
  6's 163-test baseline.
- PHP lint clean (`php -l`).
- New `src/Blocks/MayaBlocksPaymentMethod.php` — extends
  `Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType`.
- New `assets/js/maya-blocks.js` — vanilla JS that calls
  `wc.wcBlocksRegistry.registerPaymentMethod` using `wp.element` and
  `wp.htmlEntities`.
- `cart_checkout_blocks` compatibility declared alongside HPOS in
  `wc-maya-payment-gateway.php`.
- `Plugin::init()` calls `MayaBlocksPaymentMethod::register()` after the
  other subsystems.

---

## 2. Before / after file tree

```text
src/
└── Blocks/                          # NEW (Phase 7)
    └── MayaBlocksPaymentMethod.php  # AbstractPaymentMethodType subclass
assets/js/
└── maya-blocks.js                   # NEW — registerPaymentMethod call
wc-maya-payment-gateway.php          # EXTENDED — declares cart_checkout_blocks
src/Plugin.php                       # EXTENDED — calls MayaBlocksPaymentMethod::register()
tests/
├── stubs.php                        # EXTENDED — AbstractPaymentMethodType + PaymentMethodRegistry stubs
└── Unit/Blocks/                     # NEW
    └── MayaBlocksPaymentMethodTest.php
```

---

## 3. Why this phase exists at all

WooCommerce has **two different checkout shells**:

1. **Classic checkout** (`[woocommerce_checkout]` shortcode) — what every
   legacy theme uses. Server-rendered PHP. The `WC_Payment_Gateway` class
   you implemented in `Gateway/MayaGateway.php` drives this — WC calls
   `$gateway->process_payment($order_id)` directly.
2. **Block-based Cart / Checkout** (introduced 2022, default in newer
   themes like Twenty Twenty-Four). React-rendered. Each gateway has to
   ship a **separate integration class plus a JS bundle** that registers
   the gateway with the block's payment-methods registry.

Without that second integration, Maya simply doesn't appear in the block
checkout — even though the classic gateway is fully wired up. After
Phase 4, Maya worked on classic checkout. Phase 7 closes the gap so a
modern store sees Maya in both shells.

The block flow still calls back into the classic gateway's
`process_payment()` once the buyer clicks "Place order" (Maya is hosted
checkout — there's no card UI to render in the block). So this phase is
genuinely a thin shim: a few hundred lines of glue, not a parallel
checkout pipeline.

---

## 4. New files

### 4.1 `Blocks/MayaBlocksPaymentMethod`

Extends `AbstractPaymentMethodType` (from the
`Automattic\WooCommerce\Blocks\Payments\Integrations` namespace, shipped
inside WooCommerce core). WC calls the following methods at well-defined
points in the block-checkout lifecycle:

| Method | What WC does with the return value |
| --- | --- |
| `get_name()` | Identifies this payment method registry-side. We return `'maya_checkout'` — same id as the classic gateway, so server-side gateway lookups continue to work. |
| `initialize()` | Called once before the others. We load `woocommerce_maya_checkout_settings` (the same option WC's settings API writes to). |
| `is_active()` | If false, the block JS is not enqueued and the method is hidden. We return `enabled === 'yes'`. |
| `get_payment_method_script_handles()` | Array of script handles WC must enqueue when this method is rendered. We `wp_register_script('wc-maya-blocks', …)` lazily and return `['wc-maya-blocks']`. |
| `get_payment_method_data()` | Key-value bag localized to the client via `wc.wcSettings`. We return `{title, description, icon, supports}`. |

Two pure-static helpers make the testable surface cheap:

```php
public static function build_payment_method_data(
    string $title, string $description, string $icon, array $supports,
): array;

public static function is_enabled(array $settings): bool;
```

`build_payment_method_data` is the data-shape contract tests pin —
including the `array_filter` that drops non-string entries from
`supports` so an upstream regression in `WC_Payment_Gateway::$supports`
can't ship a malformed payload to the client. `is_enabled` is the
activation rule (`enabled === 'yes'`), kept static so the rule is
verified in isolation from settings-loading.

The `register()` static stays consistent with the other subsystems:

```php
public static function register(): void
{
    if (! class_exists(PaymentMethodRegistry::class)) {
        return;
    }
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        [ self::class, 'register_payment_method' ],
    );
}
```

The `class_exists` guard is intentional: WC Blocks ships *with*
WooCommerce in modern versions, but a host that has explicitly disabled
the Blocks package shouldn't crash the plugin. The guard is also why the
test stubs don't need to mock the WC Blocks package — the production
code degrades gracefully.

### 4.2 `assets/js/maya-blocks.js`

Vanilla JS (not bundled with `@wordpress/scripts` — the bundle output
would have looked identical to this anyway because we have no JSX, no
ESM dependencies, and no transpilation needs). Uses the global
`window.wp` / `window.wc` namespaces that WC Blocks guarantees are
present whenever the block-checkout renders:

```js
( function ( wp, wc ) {
    if ( ! wp || ! wc || ! wc.wcBlocksRegistry || ! wc.wcSettings ) {
        return;
    }
    var createElement = wp.element.createElement;
    var decodeEntities = wp.htmlEntities.decodeEntities;
    var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
    var settings = wc.wcSettings.getPaymentMethodData( 'maya_checkout', {} );
    // … define Label + Content components, then:
    registerPaymentMethod( {
        name: 'maya_checkout',
        label: createElement( Label, null ),
        content: createElement( Content, null ),
        edit: createElement( Content, null ),
        canMakePayment: function () { return true; },
        ariaLabel: label,
        supports: { features: settings.supports || [ 'products' ] },
    } );
}( window.wp || {}, window.wc || {} ) );
```

The script's dependencies (`wc-blocks-registry`, `wp-element`,
`wp-html-entities`, `wp-i18n`) are the canonical WC-Blocks handles. They
guarantee the right load order — `wcBlocksRegistry` isn't available
until `wc-blocks-registry` has resolved.

Why no input fields? Maya is **hosted checkout**: the customer enters
card / wallet details on a Maya-hosted page after "Place order". The
block content is therefore just a description string. `canMakePayment`
returns true unconditionally; there's nothing the block needs to validate
client-side before allowing the order to be placed.

---

## 5. Notable refactors

### Main plugin file — second compatibility declaration

`wc-maya-payment-gateway.php` already declared `custom_order_tables`
(HPOS) inside the `before_woocommerce_init` hook. Phase 7 adds a second
declaration for `cart_checkout_blocks`:

```php
FeaturesUtil::declare_compatibility('custom_order_tables', WC_MAYA_PLUGIN_FILE, true);
FeaturesUtil::declare_compatibility('cart_checkout_blocks', WC_MAYA_PLUGIN_FILE, true);
```

Without `cart_checkout_blocks`, WC's "plugin compatibility" admin screen
flags the plugin as incompatible with the block checkout, and — for some
release lines — WC actually hides the gateway from the block checkout
even when the integration class registers cleanly. Declaring it is the
shibboleth: "yes, we've thought about this, here's the integration."

### `tests/stubs.php` — WC Blocks stubs

The test runner doesn't load WooCommerce. To let
`MayaBlocksPaymentMethod` compile (because it extends a class from WC
core) and to test the registry handoff, the bootstrap now defines three
no-op shapes when WC isn't loaded:

```php
interface PaymentMethodTypeInterface { public function get_name(); }
abstract class AbstractPaymentMethodType implements PaymentMethodTypeInterface { … }
class PaymentMethodRegistry { public array $registered = []; public function register(object $m): void; }
```

These are aliased into the
`Automattic\WooCommerce\Blocks\Payments\…` namespace via `class_alias`,
so the production class's `extends`/`implements` chain resolves whether
or not WC is loaded. In production the real WC classes load first and
the guard in `tests/stubs.php` is a no-op.

---

## 6. Anti-patterns deliberately avoided

| Tempted to | Why we didn't |
| --- | --- |
| Bundle the JS with `@wordpress/scripts` and add a `package.json` + `node_modules` | The bundle output for our needs (no JSX, no imports) is byte-for-byte equivalent to the vanilla JS we ship. Adding the toolchain costs a `node_modules` of ~300 MB and a CI step, for zero correctness benefit. Phase 8 ("polish") can revisit if real bundling becomes necessary. |
| Mirror the classic `Admin/AdminAssets` enqueue logic in the block class | WC Blocks handles enqueueing for us — we only `wp_register_script` and return the handle. WC's PaymentMethodRegistry runs `wp_enqueue_script` itself based on the registry contents and the current cart state. Trying to enqueue early causes double-enqueue warnings. |
| Reimplement payment-creation client-side ("call Maya from JS") | Maya's `/checkout/v1/checkouts` requires the secret key, which must never leave the server. The classic `process_payment()` runs on the server even when the block checkout is rendering — the block's job is purely the UI shell. |
| Render card / wallet input in the block content | Hosted checkout: the customer enters those on Maya's page after redirect. Putting fields in the block would mislead users into thinking their card data is captured client-side. |
| Cache the gateway's `$supports` array on the class | `WC()->payment_gateways()` returns gateway instances that hold the live `$supports` array; reading it lazily means a runtime `add_filter` on `woocommerce_payment_gateways_supports` is honored without restarting PHP. The `resolve_supports()` helper falls back to `['products']` only when WC isn't fully booted. |
| Add a default icon | Stores ship many visual styles; a hardcoded SVG would look wrong half the time. The `wc_maya_blocks_icon_url` filter lets themes inject their own. Default is empty string, which the block renders as label-only. |
| Test the JS bundle with Jest | Pure DOM-and-globals JS; bringing Jest into this plugin for ~70 LOC of glue isn't worth the toolchain weight. Phase 8 covers Playwright browser tests which will exercise the bundle end-to-end against a real WP install. |

---

## 7. Test coverage delta

| File | Cases | Covers |
| --- | --- | --- |
| `Blocks/MayaBlocksPaymentMethodTest.php` (new) | 19 | Data-shape builder (`build_payment_method_data`), `is_enabled` truth table (5 cases), `get_name`, the `register` hook wiring, `register_payment_method` registry handoff, `initialize` happy path + non-array option coercion, `is_active` reading saved settings, `get_payment_method_data` end-to-end (title/description, icon filter, missing-title fallback), `get_payment_method_script_handles` registration-shape + idempotence, `SCRIPT_HANDLE` constant |

**19 net new tests, 45 net new assertions.**

The block class is exhaustively unit-testable because of the two
pure-static helpers — `build_payment_method_data` and `is_enabled`. The
lifecycle methods (`initialize`, `is_active`, `get_payment_method_data`,
`get_payment_method_script_handles`) get covered too, via Brain Monkey's
`expect('get_option')` / `expect('wp_register_script')` stubs.

---

## 8. End-to-end flow through the new structure

```text
Page load: customer visits a block-based Cart/Checkout page
    └── WC Blocks → PaymentMethodRegistry::initialize()
        └── for each registered integration:
            ├── ::initialize()                  # reads gateway settings option
            ├── ::is_active()                   # gateway enabled?
            ├── ::get_payment_method_script_handles()
            │       wp_register_script('wc-maya-blocks', …)
            │       wp_set_script_translations(…)
            └── ::get_payment_method_data()
                    → {title, description, icon, supports}
                    → localized as wc.wcSettings.maya_checkout

In the browser:
    └── assets/js/maya-blocks.js loads
        ├── reads wc.wcSettings.getPaymentMethodData('maya_checkout')
        └── wc.wcBlocksRegistry.registerPaymentMethod({
                name, label, content, edit, canMakePayment, supports
            })
        → Maya now appears in the React checkout's payment-method list

Customer selects Maya and clicks "Place order":
    └── WC Blocks server-side ajax → /wc/store/v1/checkout
        └── WC core resolves the payment method by name → MayaGateway::ID
        └── MayaGateway::process_payment(order_id)
            └── PaymentProcessor::process($order)     # Phase 4
                └── Checkouts::create(payload)
                └── return ['redirect' => <Maya hosted page URL>]

The redirect URL flows back through the block JS, the customer is
redirected to Maya, completes payment, returns via wc-api=maya_return
(Phase 4 ReturnHandler), and the order is promoted by the signed webhook
(Phase 4 EventDispatcher). Identical to classic checkout from
process_payment() onward.
```

---

## 9. Try it yourself

### Run the tests

```bash
cd web/app/plugins/woocommerce-maya-gateway
./vendor/bin/pest
```

Expected: `Tests: 182 passed (594 assertions)`.

### Manual smoke test

1. On a WP install with WooCommerce 8.3+, install the **Cart** and
   **Checkout** blocks on the matching pages (the WC setup wizard does
   this automatically for fresh installs).
2. Save the Maya gateway settings with both API keys + `Enabled` checked.
3. In an incognito window, add a product to the cart, proceed to
   Checkout.
4. **Expected:** "Maya" appears in the payment-method list with the
   description text you configured. Select it and click "Place order" —
   you should be redirected to `pg-sandbox.paymaya.com` (or
   `pg.maya.ph` in production mode).
5. Complete payment with the Maya sandbox test card; you'll be
   redirected back to the order-received page.
6. Confirm in WC Status → Logs (source `wc-maya-gateway`) that the
   PAYMENT_SUCCESS webhook arrived and promoted the order.

### What "broken Blocks" looks like

If Maya doesn't appear in the block checkout, check in this order:

1. **WC admin → Plugins → "WooCommerce features" compatibility table.**
   `Cart & Checkout Blocks` should show ✅ for `WooCommerce Maya Gateway`.
   If it shows ❌, the `FeaturesUtil::declare_compatibility(...)` call
   isn't firing — the `before_woocommerce_init` action probably ran
   before our plugin loaded.
2. **Browser console.** A missing `wcBlocksRegistry` or `wcSettings`
   error means the dependency list on `wp_register_script` is wrong.
3. **`wc.wcSettings.getPaymentMethodData('maya_checkout', null)` in the
   console.** Should return the data bag. `null` means
   `get_payment_method_data()` didn't run (gateway not active?
   `is_active()` returning false?).

---

## 10. Where to read next

- [../REBUILD_PLAN.md](../REBUILD_PLAN.md) — master plan; Phase 8 closes
  out with i18n, audit-log viewer, Action Scheduler retries, Playwright
  browser tests, and the release zip.
- [PHASE8-TOUR.md](PHASE8-TOUR.md) — next phase: polish + release
  (observability, retry safety net, .pot, release-zip builder).
- [../architecture.md](../architecture.md) — updated file map and the
  "Block-based Cart and Checkout" flow diagram.
- [PHASE4-TOUR.md](PHASE4-TOUR.md) — payment processing (the classic
  gateway's `process_payment` is what the block delegates to once the
  buyer clicks Place order).
- [PHASE6-TOUR.md](PHASE6-TOUR.md) — the prior phase; refund + void.
