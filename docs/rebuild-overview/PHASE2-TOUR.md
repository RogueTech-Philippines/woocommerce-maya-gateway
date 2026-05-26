# Phase 2 tour — Webhook reception (read-only side)

Status: **DONE** (2026-05-26).

> Receive Maya's server-to-server callbacks, verify them, and log what we
> *would* have dispatched — without yet touching any WooCommerce orders.

This is a delta tour. It assumes you've read
[PHASE1-TOUR.md](PHASE1-TOUR.md) (vocabulary, plugin loading, value objects,
the `register()` convention, testing primer) and doesn't re-explain any of
that. Open Phase 1 first if you haven't.

---

## 1. Definition-of-done — confirmed

The rebuild plan asked for:

> Fire a sandbox payment → see "would dispatch `PAYMENT_SUCCESS` for order
> N" in the log. Simulate button works locally without tunnel.

What was shipped:

- **82 tests passing, 191 assertions** — 40 cases across 7 rewritten/new
  test suites under `tests/Unit/Webhook/`, for a **net delta of +38** over
  the Phase 1 baseline of 44 (the 2-test `WebhookHandlerTest` from Phase 1
  was rewritten, hence 40 - 2 = 38 net).
- PHP lint clean across the tree (`php-cs-fixer` formatted; `php -l` syntax-OK).
- All seven verification primitives shipped (`PublicKeyBundle`,
  `PayloadFlattener`, `SignatureVerifier`, `TimestampVerifier`,
  `IpAllowlist`, `WebhookHandler` REST + shim, `Simulator`).
- Admin "Simulate webhook" button rendered in sandbox mode only and gated
  by `manage_woocommerce` + nonce.
- `Plugin::init()` rewired so each subsystem owns its own
  `register()` — `Plugin.php` stays a one-screen map.

---

## 2. Before / after file tree

```text
src/
├── Plugin.php                       # NEW: SimulateWebhook::register() + WebhookHandler::register()
├── Admin/
│   ├── AdminAssets.php              # NEW: simulateWebhook action + simulator i18n strings
│   ├── FormFields.php               # NEW: webhook_simulator field type
│   ├── FieldRenderers.php           # NEW: webhook_simulator() renderer
│   └── Ajax/
│       ├── TestConnection.php
│       └── SimulateWebhook.php      # NEW (Phase 2)
└── Webhook/
    ├── WebhookHandler.php           # REWRITTEN: REST + wc-api shim, process() core
    ├── PublicKeyBundle.php          # NEW (Phase 2)
    ├── PayloadFlattener.php         # NEW (Phase 2)
    ├── SignatureVerifier.php        # NEW (Phase 2)
    ├── TimestampVerifier.php        # NEW (Phase 2)
    ├── IpAllowlist.php              # NEW (Phase 2)
    └── Simulator.php                # NEW (Phase 2)
```

```text
tests/Unit/Webhook/
├── PayloadFlattenerTest.php         # NEW (Phase 2)
├── TimestampVerifierTest.php        # NEW (Phase 2)
├── SignatureVerifierTest.php        # NEW (Phase 2) — real RSA round-trip
├── IpAllowlistTest.php              # NEW (Phase 2)
├── PublicKeyBundleTest.php          # NEW (Phase 2)
├── WebhookHandlerTest.php           # REWRITTEN: exercises process() pipeline
└── SimulatorTest.php                # NEW (Phase 2)
```

---

## 3. The shape of Maya's webhook

Quick refresher. When a payment changes state, Maya's servers POST JSON to
the URL we registered with them. The request includes three load-bearing
HTTP headers:

| Header                          | What it carries |
| ---                             | --- |
| `X-Maya-Webhook-Timestamp`      | Epoch milliseconds when Maya signed the payload. We reject anything older or newer than ±300s. |
| `X-Maya-Webhook-Signature`      | Two comma-separated key=value pairs: `nonce=<random>,v1=<hex-rsa-sha256>`. The `v1` value is the hex-encoded RSA-SHA256 signature over the canonicalized payload + nonce. |
| `X-Simulated-Webhook` (ours)    | Local-dev escape hatch — see [§9](#9-the-simulator--end-to-end-walkthrough). Honored only in sandbox mode. |

The body is JSON. The fields we care about right now:

```json
{
    "id":                     "pay_abc123",
    "status":                 "PAYMENT_SUCCESS",
    "requestReferenceNumber": "42",
    "amount":                 199.50,
    "currency":               "PHP",
    "isPaid":                 true,
    "canVoid":                false,
    "canRefund":              true,
    "canCapture":             false
}
```

`status` is what Phase 4's dispatcher will fan out on. `requestReferenceNumber`
is the WC order id we want to look up. The other fields drive
amount-match checks and the manual-capture branch (Phase 5).

---

## 4. The verification pipeline at a glance

```text
POST  /wp-json/wc-maya/v1/webhook                 POST  /?wc-api=maya_webhook
        │   (primary)                                     │   (compat shim)
        ▼                                                 ▼
  WebhookHandler::handle_rest()                  WebhookHandler::handle_wc_api()
        │                                                 │
        └────────────────┐               ┌────────────────┘
                         ▼               ▼
                 WebhookHandler::process(body, headers, source_ip, is_sandbox, logger)
                         │
                         ├─ 1. json_decode body                    → 400 invalid_body
                         ├─ 2. TimestampVerifier (±300s)           → 401 stale_timestamp
                         ├─ 3. SignatureVerifier (PublicKeyBundle) → 401 invalid_signature
                         ├─ 4. IpAllowlist::allows()               → 403 source_ip_blocked
                         └─ 5. WebhookEvent::try_from_string()     → 200 + log "would dispatch X for order Y"
```

The whole pipeline is shaped so that **steps 2–4 short-circuit** if the
caller sets `X-Simulated-Webhook: true` *and* the gateway is in sandbox
mode. That's the only way to bypass verification; production never reads
the header.

---

## 5. The seven new files

### 5.1 `PublicKeyBundle`

Two RSA public keys per environment, copied verbatim from Maya's developer
docs (and from the legacy plugin's lines 39-87). Verification accepts a
match against *any* key in the bundle so Maya can rotate one without
breaking us.

```php
public const SANDBOX_PEMS    = [ /* two PEMs */ ];
public const PRODUCTION_PEMS = [ /* two PEMs */ ];

public static function for_environment(bool $is_sandbox): array
{
    return $is_sandbox ? self::SANDBOX_PEMS : self::PRODUCTION_PEMS;
}
```

Test (`PublicKeyBundleTest`) asserts the bundle has two PEMs per environment
*and* that each one is parseable by `openssl_pkey_get_public()` so a
stray whitespace/newline bug doesn't ship.

### 5.2 `PayloadFlattener`

Pure function. Given a decoded JSON payload + a nonce, returns the exact
byte sequence Maya signed. Algorithm (ported from the legacy plugin's
`flatten_object_to_string`):

1. Walk the payload depth-first.
2. Drop `null`, empty string, empty array values — Maya's signer drops
   them too, so including them would produce a string Maya never signed.
3. Emit `"{dotted.key}=value"` — booleans become the lowercase strings
   `true`/`false`, everything else casts to string.
4. Sort ascending (ASCII byte order).
5. `implode('&', ...)` + `&nonce={nonce}` suffix.

```php
$flat = PayloadFlattener::flatten(
    [ 'id' => 'pay_abc', 'amount' => [ 'value' => 100, 'currency' => 'PHP' ] ],
    'NONCE',
);
// "amount.currency=PHP&amount.value=100&id=pay_abc&nonce=NONCE"
```

Why centralize this so aggressively? Because the flattener is the
single load-bearing piece that determines whether *every* signature check
passes or fails. A one-character difference in encoding — sorting wrong,
including empties, forgetting the nonce suffix, lowercase vs. uppercase
booleans — silently breaks everything. The pure function makes it trivial
to pin the contract with golden fixtures, and `PayloadFlattenerTest`
ships five.

### 5.3 `SignatureVerifier`

Parses the comma-separated header, hex-decodes `v1`, asks the flattener
for the canonical bytes, walks every PEM, returns true on the first
`openssl_verify(..., 'sha256WithRSAEncryption')` that returns 1.

```php
$verifier = new SignatureVerifier(PublicKeyBundle::for_environment(true));
$verifier->verify($payload, 'nonce=abc,v1=deadbeef…');
```

`parse_header()` is exposed publicly so its quirks (any order; missing
fields → nulls; trims whitespace; rejects empty values) are directly
testable.

Test approach worth calling out: `SignatureVerifierTest` generates a
fresh 2048-bit RSA keypair *inside the test* via `openssl_pkey_new`, signs
the canonical payload with the private half, and verifies with the public
half. It's a real cryptographic round-trip, not a mock — so any regression
in the flatten ↔ verify contract fails the test immediately.

Two defensive checks live in the verifier: hex strings of odd length or
non-hex characters return false *before* `hex2bin` is called, because
PHP 8.3 emits a warning that Pest's strict mode treats as a failure.

### 5.4 `TimestampVerifier`

```php
public const TOLERANCE_MS = 300_000;

public static function within_tolerance(string $timestamp_ms, ?int $now_ms = null): bool
```

Takes the string from the header (because that's what we read from
`$_SERVER`) and an optional injected "now" so tests don't need to freeze
the clock. Returns false for empty or non-numeric input — defensive
parsing matches the rest of the verification surface.

### 5.5 `IpAllowlist`

```php
public const SANDBOX_IPS    = ['13.229.160.234', '3.1.199.75'];
public const PRODUCTION_IPS = ['18.138.50.235', '3.1.207.200'];

public static function allows(string $ip, bool $is_sandbox): bool;
public static function get_source_ip(array $server): string;
```

`get_source_ip()` walks the standard proxy header chain in priority order
(`X-Forwarded-For` first IP → `CF-Connecting-IP` → `X-Client-IP` →
`Client-IP` → `REMOTE_ADDR`). Sites behind Cloudflare or a load balancer
get the right IP without code changes.

### 5.6 `WebhookHandler`

Rewritten. Three concerns split cleanly:

```php
public static function register(): void
{
    add_action('rest_api_init', [ self::class, 'register_rest_route' ]);
    add_action('woocommerce_api_' . SettingsHelper::WEBHOOK_ROUTE,
        [ self::class, 'handle_wc_api' ]);
}
```

- `handle_rest(WP_REST_Request)` / `handle_wc_api()` are thin entrypoints
  that normalize headers + body and delegate.
- `process(string $body, array $headers, string $source_ip, bool $is_sandbox,
  Logger $logger, ?SignatureVerifier $verifier = null): array` is the
  pure-ish core that runs every check and returns `[status, body]`.

That last optional `?SignatureVerifier $verifier` is an injection seam used
exclusively by tests — production code lets `process()` build a real
verifier from `PublicKeyBundle::for_environment()`.

Settings are loaded at the entrypoint via a private
`load_runtime_settings()` that reads `wp_options` directly:

```php
$option = get_option('woocommerce_' . MayaGateway::ID . '_settings', []);
```

That's intentional. The webhook handler runs during request bootstrap,
before `WC()->payment_gateways()` has necessarily instantiated the gateway.
Reading the option directly avoids ordering bugs and keeps the handler
self-sufficient.

### 5.7 `Simulator`

```php
public function simulate(WC_Order $order, string $status): array|WP_Error
{
    $payload  = self::build_payload($order, $status);
    $response = wp_remote_post(
        $this->settings->webhook_url(),
        [
            'headers' => [
                'Content-Type'                   => 'application/json',
                WebhookHandler::HEADER_SIMULATED => 'true',
            ],
            'body' => wp_json_encode($payload),
        ],
    );
    /* … wrap in {status, body} tuple … */
}
```

Posts a forged Maya payload at *our own* webhook URL with the bypass
header set. The handler still parses + logs the event — the only steps
that get skipped are timestamp/signature/IP. The simulator's return
value carries the actual HTTP status the handler emitted, so the
admin sees exactly what the handler decided.

`Simulator::ALLOWED_STATUSES` whitelists `PAYMENT_SUCCESS`,
`PAYMENT_FAILED`, `PAYMENT_EXPIRED` — the three the rebuild plan asks
for. Anything else returns a `WP_Error` before any HTTP call is made.

---

## 6. The admin "Simulate webhook" UI

A new `webhook_simulator` field type in `FormFields::definitions()` is
rendered by `FieldRenderers::webhook_simulator()`:

- Hidden behind "Sandbox mode" — production stores never see the UI.
- Order ID input + status select + Simulate button + result panel.
- Bound by `assets/js/maya-admin.js → attachSimulator()`, which POSTs to
  `Admin/Ajax/SimulateWebhook` and renders the handler's response (status
  code + JSON body) verbatim into the result panel.

The AJAX handler does the usual admin handshake (`manage_woocommerce` +
nonce) before calling `Simulator::simulate()`.

---

## 7. Anti-patterns deliberately avoided

| Tempted to | Why we didn't |
| --- | --- |
| Build an in-memory event dispatcher now | Phase 4's job. The "would dispatch X for order Y" log line is the DoD-required surface for this phase; building the dispatcher early would force scope decisions before we have the matching tests. |
| Make `WebhookHandler::process()` look up the order via `wc_get_order()` | Same reasoning — Phase 4 owns the WC-side mutations. Phase 2 stops at parsing + logging. |
| Inline the verification logic in `WebhookHandler` instead of splitting into primitives | Each primitive (flattener, signature, timestamp, IP, key bundle) has a different reason to change. Splitting them lets the unit tests pin each contract independently — when Maya rotates a key, only `PublicKeyBundle` moves. |
| Trust `$_SERVER['REMOTE_ADDR']` unconditionally | Production sites are behind Cloudflare / load balancers; the real client IP is in `X-Forwarded-For` or `CF-Connecting-IP`. `IpAllowlist::get_source_ip()` walks the chain. |
| Honor `X-Simulated-Webhook` in production | The simulator is local-dev only. The handler short-circuits the check the moment `is_sandbox` is false — even with the header set, production webhooks fall through to the real signature/timestamp/IP gates. |
| Lift the existing `WebhookHandler::SANDBOX_IPS` / `PRODUCTION_IPS` constants and keep the old constant locations | They're already moved into `IpAllowlist`. The handler tests that previously checked `WebhookHandler::SANDBOX_IPS` now live in `IpAllowlistTest`. Re-exporting old constants would defeat the split. |
| Add a config field for "tolerance window seconds" | The 300s window is documented by Maya and matches the legacy plugin. Adding configurability invites pin-and-drift bugs. Hardcoded constant; trivial to find if Maya ever changes it. |

---

## 8. Real-world bug case study: PHP 8.3's stricter `hex2bin()` warning

While writing the negative test "verify rejects malformed hex in v1", Pest
flagged a runtime warning:

```text
hex2bin(): Hexadecimal input string must have an even length
```

The original code wrapped the call in `@hex2bin($hex_v1)` to silence it,
and assumed `false === $signature_bytes` was enough downstream. On PHP
8.3 + Pest 4, the `@` doesn't actually suppress the warning at the
test-framework level: it still propagates, fails strict-mode assertions,
and surfaces as a `WARN` line in the runner.

The fix was defensive parsing — reject the bad input *before* calling
`hex2bin`:

```php
if ('' === $hex_v1
    || 0 !== strlen($hex_v1) % 2
    || ! ctype_xdigit($hex_v1)) {
    return false;
}
$signature_bytes = hex2bin($hex_v1);
```

Now the malformed-hex test passes cleanly and the call site is also
self-documenting about which shapes are rejected. The takeaway:
**`@`-suppression is a smell in 8.3+. Validate first, then call.**

---

## 9. The simulator — end-to-end walkthrough

```text
1. Admin opens WC → Settings → Payments → Maya Checkout
   (sandbox mode is on, otherwise the simulator UI is hidden)
   ↓
2. Types an order ID, picks "PAYMENT_SUCCESS", clicks "Simulate webhook"
   ↓
3. maya-admin.js → attachSimulator() POSTs to admin-ajax.php with:
   action=wc_maya_simulate_webhook, nonce, order_id, status
   ↓
4. Admin/Ajax/SimulateWebhook::handle()
   ├── current_user_can('manage_woocommerce')
   ├── check_ajax_referer(nonce)
   ├── absint(order_id), validate status ∈ ALLOWED_STATUSES
   ├── wc_get_order($order_id)
   ├── find_gateway() → MayaGateway instance
   └── new Simulator(new SettingsHelper($gateway))->simulate($order, $status)
       │
       ↓
5. Webhook/Simulator::simulate()
   ├── Simulator::build_payload($order, $status)   # Maya-shaped record
   └── wp_remote_post(
         $settings->webhook_url(),                  # = local-dev override OR home_url
         headers: [
           Content-Type:        application/json,
           X-Simulated-Webhook: true,
         ],
         body: json_encode($payload),
       )
       │
       ↓ (loops back to the same WP instance)
6. Webhook/WebhookHandler::handle_wc_api()  (or handle_rest if the URL is REST)
   └── WebhookHandler::process(body, headers, source_ip, is_sandbox=true, logger)
       ├── json_decode ✓
       ├── is_simulated() → true (sandbox + bypass header)
       │   ├── SKIP timestamp check
       │   ├── SKIP signature check
       │   └── SKIP IP check
       ├── WebhookEvent::try_from_string('PAYMENT_SUCCESS')
       └── logger->info('Webhook verified (simulated) — would dispatch
                        PAYMENT_SUCCESS for order 42.')
   └── returns [status: 200, body: {received: true, simulated: true,
                                     event: 'PAYMENT_SUCCESS', reference: '42'}]
       │
       ↓
7. Simulator unpacks [status, body] and hands it back to the AJAX handler
   ↓
8. SimulateWebhook::handle() → wp_send_json_success({status, body})
   ↓
9. JS renders: "HTTP 200 · handler accepted (would dispatch event)"
   + JSON-pretty-printed body in a <pre> block
   ↓
10. Admin opens WooCommerce → Status → Logs → wc-maya-gateway
    → sees the "would dispatch" line
```

Every box past step 5 is real plugin code that a Maya callback would also
hit. The simulator earns its keep by exercising the *full* handler
pipeline (parser + event extraction + logging) without needing a tunnel.

---

## 10. Test coverage delta

| File | Cases | Covers |
| --- | --- | --- |
| `Webhook/PayloadFlattenerTest.php` | 5 | Dotted keys, booleans, empty-skip rule, sort order, realistic golden fixture |
| `Webhook/TimestampVerifierTest.php` | 5 | Inside window, too-old, too-future, non-numeric, default-clock |
| `Webhook/SignatureVerifierTest.php` | 7 | Header parse (order, missing, empty), real RSA round-trip, tampered payload, malformed hex, multi-key walk |
| `Webhook/IpAllowlistTest.php` | 6 | Constants, environment switch, CF-over-XFF priority, XFF first-entry fallback, X-Client / Client / REMOTE_ADDR fallback chain, whitespace trimming |
| `Webhook/PublicKeyBundleTest.php` | 3 | Per-env count, environment switch, every PEM parses with OpenSSL |
| `Webhook/WebhookHandlerTest.php` (rewritten) | 8 | Non-JSON, stale timestamp, bad signature, bad IP, success path, simulator bypass (sandbox), simulator refused in production, route constants |
| `Webhook/SimulatorTest.php` | 6 | Payload shape (success), payload shape (failure), invalid status rejection, sandbox-mode gate, real wp_remote_post wiring, WP_Error passthrough |

**40 cases across the 7 new/rewritten suites** — every primitive owns its
own contract. Net delta vs. Phase 1's 44-test baseline: **+38** (the 2 cases
from Phase 1's `WebhookHandlerTest` were replaced, hence 40 − 2 = 38).

---

## 11. Try it yourself

### Run the tests

```bash
cd web/app/plugins/woocommerce-maya-gateway
./vendor/bin/pest
```

Expected: `Tests: 82 passed (191 assertions)`.

### PHP lint

```bash
find src tests -name '*.php' -print | xargs -n1 php -l
```

Every line should be `No syntax errors detected in …`.

### Local-dev smoke test

1. WooCommerce → Settings → Payments → Maya Checkout (sandbox mode on).
2. Tick **Debug log** so you'll see the "would dispatch" line.
3. Scroll to "Simulate webhook (sandbox only)".
4. Type a real (sandbox) order ID, pick `PAYMENT_SUCCESS`, click
   **Simulate webhook**.
5. Result panel should show `HTTP 200 · handler accepted (would dispatch
   event)` plus the JSON body containing the parsed event.
6. WooCommerce → Status → Logs → `wc-maya-gateway-YYYY-MM-DD-*.log`
   should contain a line like
   `Webhook verified (simulated) — would dispatch PAYMENT_SUCCESS for order 42.`

### Live sandbox smoke test

If you have a tunnel set up (see [webhook-tunneling.md](../webhook-tunneling.md)),
fire a real sandbox payment. The same "would dispatch" log line should
appear, but with `(simulated)` absent and the source IP set to one of
Maya's documented sandbox IPs (`13.229.160.234` or `3.1.199.75`).

---

## 12. Where to read next

- [../REBUILD_PLAN.md](../REBUILD_PLAN.md) — the master plan; Phase 3 is
  next (webhook *registration* — manage the merchant's webhooks from our
  side instead of pointing them at Maya Manager).
- [../architecture.md](../architecture.md) — updated map and the
  "which file do I open?" table.
- [PHASE1-TOUR.md](PHASE1-TOUR.md) — foundation refactor + codebase primer.
- [../webhook-tunneling.md](../webhook-tunneling.md) — exposing your dev
  site so Maya can reach you.
