# Phase 3 tour — Webhook registration (write side)

Status: **DONE** (2026-05-26).

> Save the settings page → Maya Manager now shows the five managed event
> registrations pointing at this site, without anyone clicking around in
> Maya's UI. Re-saving is idempotent: only our managed set is touched;
> other webhooks the merchant created for unrelated systems are left alone.

Delta tour. Assumes you've read [PHASE1-TOUR.md](PHASE1-TOUR.md) and
[PHASE2-TOUR.md](PHASE2-TOUR.md). I don't re-explain the `register()`
convention, the `Webhooks` endpoint, value objects, or the Pest/Mockery
setup.

---

## 1. Definition-of-done — confirmed

The rebuild plan asked for:

> Save settings → Maya Manager shows the five entries pointing at the
> local-dev override URL (or `home_url()` if blank).

What was shipped:

- **95 tests passing, 251 assertions** — net delta of **+13** over Phase 2's
  82-test baseline. New/extended suites:
  `Value/WebhookRecordTest` (3), `Webhook/RegistrarTest` (6),
  `Api/Endpoints/WebhooksTest` (4 net new on top of the 2 from Phase 1).
- PHP lint clean (`php-cs-fixer` + `php -l`).
- New `Value/WebhookRecord` DTO replaces raw arrays end-to-end.
- New `Webhook/Registrar` owns the idempotent reconcile loop.
- New `Admin/Ajax/RefreshWebhooks` powers the status table refresh.
- New `webhook_status_table` field type with live AJAX-fetched rows.
- `MayaGateway::process_admin_options()` overridden so settings save now
  reconciles webhooks and surfaces success / partial / failure via WC
  admin notices.

---

## 2. Before / after file tree

```text
src/
├── Admin/
│   ├── FormFields.php               # NEW: webhook_status_table field
│   ├── FieldRenderers.php           # NEW: webhook_status_table() renderer
│   ├── AdminAssets.php              # NEW: refreshWebhooks action + 4 i18n strings
│   └── Ajax/
│       ├── TestConnection.php
│       ├── SimulateWebhook.php
│       └── RefreshWebhooks.php      # NEW (Phase 3)
├── Api/
│   └── Endpoints/
│       └── Webhooks.php             # EXTENDED: create() + delete(); all() returns WebhookRecord[]
├── Gateway/
│   └── MayaGateway.php              # EXTENDED: process_admin_options() override + generate_webhook_status_table_html
├── Plugin.php                       # NEW: RefreshWebhooks::register()
├── Value/
│   └── WebhookRecord.php            # NEW (Phase 3)
└── Webhook/
    └── Registrar.php                # NEW (Phase 3)
```

```text
tests/Unit/
├── Api/Endpoints/WebhooksTest.php   # REWRITTEN: covers all/create/delete + DTOs
├── Value/WebhookRecordTest.php      # NEW (Phase 3)
└── Webhook/RegistrarTest.php        # NEW (Phase 3)
```

---

## 3. The reconciliation contract

`Webhook/Registrar::reconcile(string $callback_url): array|WP_Error` is
where everything important happens. The shape is intentionally
"delete-then-create" — Maya doesn't expose a PUT that lets us mutate a
callback URL in place, so the cleanest path is "burn the managed set,
rebuild it." The trade-off is that every save round-trips ~7 API calls
(1 list + 2 deletes + 5 creates on the steady-state run); that's
acceptable for a settings-save action.

```text
caller (process_admin_options or future CLI)
        │
        ▼
Registrar::reconcile($callback_url)
        │
        ├─ 0. Guard: empty URL                       → WP_Error 'wc_maya_registrar_empty_url'
        ├─ 1. endpoint->all()                        → WP_Error bubbles
        ├─ 2. for each existing record:
        │       ├─ name in MANAGED_EVENTS?  YES → endpoint->delete(id); record success/error
        │       └─                          NO  → skipped[] += name
        ├─ 3. for each MANAGED_EVENTS event:
        │       └─ endpoint->create(event, $callback_url); record success/error
        └─ 4. logger->info(summary); return [deleted, created, skipped, errors]
```

`MANAGED_EVENTS` is the five-event set the rebuild plan calls out:
`CHECKOUT_SUCCESS`, `CHECKOUT_FAILURE`, `PAYMENT_SUCCESS`,
`PAYMENT_FAILED`, `PAYMENT_EXPIRED`. The static helpers
`Registrar::managed_names(): list<string>` and
`Registrar::is_managed(string $name): bool` exist so the AJAX status-table
endpoint can tag rows without duplicating the set.

### Idempotency

Re-running `reconcile()` with the same URL yields the same final state:
the five managed events exist, point at the URL, and nothing else changed.
Unmanaged entries are not touched on either delete or create.

### Partial-failure handling

Per-step failures are captured in the returned `errors` array (one string
per failed delete/create) and the function does **not** abort. This is
deliberate: a single dead create shouldn't prevent the other four from
landing. The gateway's `process_admin_options()` inspects `errors` and
surfaces a "partial success" notice if any entries are populated.

---

## 4. The new files

### 4.1 `Value/WebhookRecord`

Maya's webhook record has five fields we care about. Modeled the same way
as every other `Value/*` DTO: final readonly class, `from_array()` factory
with field-by-field type coercion, `to_array()` for round-trip.

```php
final readonly class WebhookRecord
{
    public function __construct(
        public string $id,
        public string $name,
        public string $callback_url,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function from_array(array $data): self { /* … */ }
    public function to_array(): array { /* … */ }
}
```

Timestamps stay as raw ISO-8601 strings rather than `DateTimeImmutable`
because the only consumer (the admin status table) renders them via JS,
which already understands ISO-8601 via `new Date()`.

### 4.2 `Api/Endpoints/Webhooks` (extended)

```php
class Webhooks
{
    public function all(): array|WP_Error;                                  // now returns list<WebhookRecord>
    public function create(string $event_name, string $callback_url): WebhookRecord|WP_Error;
    public function delete(string $id): WebhookRecord|WP_Error;
}
```

Two behavior calls worth noting:

- **`all()` decodes into DTOs.** The previous shape (raw decoded array)
  bubbled up through callers. With DTOs the Registrar can iterate
  `$record->name` without null-coalescing at every read.
- **`delete()` URL-encodes the id.** Maya's ids are short alphanumerics
  in practice, but `rawurlencode()` is the right defense if Maya ever
  ships ids with slashes or pluses.

### 4.3 `Webhook/Registrar`

The orchestration class — covered in §3 above. Key surface:

```php
public function __construct(
    private readonly Webhooks $endpoint,
    private readonly Logger $logger,
) {}

public const MANAGED_EVENTS = [ /* five WebhookEvent enum cases */ ];

public function reconcile(string $callback_url): array|WP_Error;
public static function managed_names(): array;
public static function is_managed(string $event_name): bool;
```

Built as a constructor-injected service so unit tests can pass a
Mockery-mocked `Webhooks` endpoint without booting WP.

### 4.4 `Admin/Ajax/RefreshWebhooks`

A one-purpose AJAX endpoint. Permission check + nonce + gateway lookup +
`Webhooks::all()`, then maps the DTOs to a JSON array with an extra
`managed: bool` flag so the JS can tag rows.

Same shape as `SimulateWebhook` from Phase 2 — keeps the AJAX surface
predictable.

### 4.5 `webhook_status_table` field type

Renders an empty `<table class="widefat striped">` with a placeholder
"Loading…" row and a **Refresh from Maya** button. The JS fetches the
live rows via `RefreshWebhooks` on page load *and* on click.

Why empty-on-render instead of pre-populating server-side? Two reasons:

- A synchronous Maya call on every settings-page render would add 100–500ms
  to load time and time out the page if Maya is sluggish.
- The same JS code path runs for both initial load and refresh — fewer
  branches, easier to reason about.

### 4.6 `MayaGateway::process_admin_options()` override

The seam that triggers reconciliation:

```php
public function process_admin_options(): bool
{
    $saved = parent::process_admin_options();
    if (! $saved) return $saved;

    $this->init_settings();                       // re-read what the parent just wrote
    $helper = new SettingsHelper($this);

    if ('yes' !== $this->get_option('enabled')) return $saved;
    if ('' === $helper->public_key() || '' === $helper->secret_key()) {
        WC_Admin_Settings::add_message('Saved. Add both Maya API keys to register webhooks automatically.');
        return $saved;
    }

    $registrar = new Registrar(
        new Webhooks($this->build_api_client()),
        new Logger($helper->debug_log_enabled()),
    );

    $result = $registrar->reconcile($helper->webhook_url());
    // … translate $result into WC_Admin_Settings::add_message / add_error
    return $saved;
}
```

The notice taxonomy:

| State | Notice |
| --- | --- |
| Disabled, or keys missing | "Saved. Add both Maya API keys to register webhooks automatically." (info) |
| Reconcile returned WP_Error (list failed at the top) | "Webhook registration failed: …" (error) |
| Reconcile returned `errors[]` populated | "Webhook registration partially succeeded — N created; errors: …" (error) |
| Clean success | "%d webhooks registered with Maya." (info, pluralized via `_n()`) |

---

## 5. End-to-end walkthrough — save settings

```text
1. Admin clicks "Save changes" on WC → Settings → Payments → Maya Checkout
   ↓
2. WC dispatches woocommerce_update_options_payment_gateways_maya_checkout
   → fires our overridden MayaGateway::process_admin_options()
   ↓
3. parent::process_admin_options() writes wp_options
   ↓
4. $this->init_settings() — re-read the freshly written values
   ↓
5. Sanity gates:
   ├── enabled !== 'yes'? → notice, return
   └── public_key or secret_key empty? → notice, return
   ↓
6. Build Webhook/Registrar with:
   ├── new Webhooks(MayaGateway::build_api_client())
   └── new Logger(SettingsHelper::debug_log_enabled())
   ↓
7. Registrar::reconcile($webhook_url):
   ├── endpoint->all() → list every Maya webhook on this account
   ├── for each existing where is_managed(name): endpoint->delete(id)
   └── for each MANAGED_EVENTS: endpoint->create(name, $webhook_url)
   ↓
8. Returned summary → translated to a WC admin notice on the next page load
   ↓
9. JS on the status table picks up the change on next visit, or the user
   clicks "Refresh from Maya" to see the updated rows immediately
```

---

## 6. Anti-patterns we deliberately avoided

| Tempted to | Why we didn't |
| --- | --- |
| Diff-and-update (compute the delta and only call delete/create on what changed) | Maya doesn't expose PUT — every update requires delete+create anyway. The diff layer would add complexity without saving API calls. Burn-and-rebuild stays mechanically simple. |
| Make the registrar swallow errors and pretend success | Maya can 4xx on legitimate failures (duplicate name, quota). The merchant deserves to see "Webhook registration partially succeeded — N created; errors: …" instead of a silent half-broken state. |
| Run reconcile from a separate background action | Doable via Action Scheduler, but the merchant clicked Save and expects to see the result. Synchronous keeps the feedback loop tight. We may revisit this in Phase 8 if reconcile becomes flaky. |
| Persist the managed webhook ids in `wp_options` | Tempting because then `delete()` could target by id without listing. But the list call costs one round-trip and gives us truthful state; persisted ids drift if anyone edits via Maya Manager. The plan's explicit "Idempotent" requirement means listing is the only safe approach. |
| Render the status table synchronously on page load | A blocking Maya call on every settings-page render — bad UX and an outage vector if Maya is slow. The JS fetch keeps render time bounded. |
| Add a "preview before save" UI | YAGNI. The status table after save is the preview. |
| Expand `WebhookEvent` enum to include only managed events | The enum models the full Maya event vocabulary (used by the handler dispatcher in Phase 4). `MANAGED_EVENTS` is a Registrar concern — keep concerns split. |

---

## 7. Test coverage delta

| File | Cases | Covers |
| --- | --- | --- |
| `Value/WebhookRecordTest.php` (new) | 3 | Field mapping, missing-field defaults, to_array round-trip |
| `Api/Endpoints/WebhooksTest.php` (rewritten) | 6 | `all()` returning DTOs, all error bubble, `create()` POST shape, create error bubble, `delete()` URL-encoding ids, delete error bubble |
| `Webhook/RegistrarTest.php` (new) | 6 | Managed-names exact set, `is_managed`, empty-URL guard, list-error bubble, full happy-path reconcile, partial-failure shape |

**15 cases across the 3 new/rewritten suites** — net **+13** over Phase 2
(the 2 cases from the pre-Phase-3 `WebhooksTest` were replaced, so
15 − 2 = 13 net).

---

## 8. Try it yourself

### Run the tests

```bash
cd web/app/plugins/woocommerce-maya-gateway
./vendor/bin/pest
```

Expected: `Tests: 95 passed (251 assertions)`.

### Manual smoke test

1. WooCommerce → Settings → Payments → Maya Checkout.
2. Make sure **Enable** is ticked and both API keys are filled in.
3. Click **Save changes**.
4. The status table below should show 5 rows after a moment, each marked
   "Managed by this plugin" and pointing at the URL in "Webhook URL to
   register" above. Any prior unmanaged entries (e.g. user-added
   `CHECKOUT_DROPOUT`) are left untouched.
5. Look at WooCommerce → Status → Logs → `wc-maya-gateway-*` (if Debug
   log is on). You'll see one `Webhook reconcile done.` info line with
   `created`, `deleted`, `skipped`, and `errors`.
6. Cross-check by visiting Maya Manager → Webhooks. The five managed
   entries should now match the screenshot of the status table.

### Idempotency check

Hit **Save changes** again without modifying anything. The status table
should still show exactly five managed rows. The log entry's `deleted`
should list the 5 from before, `created` should list the 5 fresh ones,
and `skipped` should match whatever the merchant had for unrelated events.

---

## 9. Where to read next

- [../REBUILD_PLAN.md](../REBUILD_PLAN.md) — master plan; Phase 4 is next
  (real `process_payment` + the `EventDispatcher` that converts verified
  webhook events into WC order state changes).
- [../architecture.md](../architecture.md) — updated file map, "which file
  do I open?" table, and the new "Webhook registration — settings-save
  flow" section.
- [PHASE2-TOUR.md](PHASE2-TOUR.md) — webhook reception primitives the
  Registrar composes on top of.
- [PHASE1-TOUR.md](PHASE1-TOUR.md) — primer + foundation.
