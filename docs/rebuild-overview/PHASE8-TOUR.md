# Phase 8 tour — Polish, observability, release

Status: **DONE** (2026-05-26).

> Wraps up the rebuild. Adds the observability pieces a production deploy
> needs (audit-log viewer, Action Scheduler retry safety net), ships the
> translation template, writes the release-zip builder, and updates the
> README + CHANGELOG. Two items from the original phase plan are
> deliberately deferred — Playwright browser tests and the old-plugin
> retirement — both documented below with the rationale.

Delta tour. Assumes you've read [PHASE1-TOUR.md](PHASE1-TOUR.md) through
[PHASE7-TOUR.md](PHASE7-TOUR.md). Phase 8 is the last per-phase doc;
after this the master plan is fully delivered.

---

## 1. Definition-of-done — confirmed

The rebuild plan asked for:

> Production-installable build (composer-less zip), translatable, with
> audit log.

What was shipped:

- **212 tests passing, 661 assertions** — net delta of **+30** over
  Phase 7's 182-test baseline.
- PHP lint clean (`php -l` + `php-cs-fixer`).
- **Audit log viewer.** `Admin/EventLog/EventLogParser` (pure-static
  WC log-line parser) and `Admin/EventLog/EventLogPage` (custom
  "Maya events" tab under WooCommerce → Status with file picker,
  level filters, free-text search, decoded JSON context column).
- **Action Scheduler retry safety net.** `Webhook/RetryQueue` —
  transient-failure dispatch actions trigger a replay scheduled
  via `as_schedule_single_action`, capped at 4 attempts, exponential
  backoff (1m / 4m / 16m / 64m).
- **Translation template.** `bin/make-pot.php` self-contained extractor
  → `languages/wc-maya-gateway.pot` (139 unique strings).
  `Plugin::init()` now calls `load_plugin_textdomain` for the bundled
  catalog.
- **Release builder.** `bin/build-release.sh` produces
  `dist/wc-maya-gateway-<version>.zip` with a `composer install --no-dev`
  vendor and dev/tests/docs/bin excluded. Plugin working tree never
  touched.
- **CHANGELOG.md** (this release as 1.0.0) + expanded **README.md**
  with install, configuration table, dev workflow.

Deferred from the original phase plan:

- **Playwright browser tests.** Would require a Node toolchain + a
  running WP install — neither belongs in the Pest-only unit-test
  workflow. Documented as a future trigger in
  `.claude/skills/maya-gateway-structure/SKILL.md` under "Splits we
  deliberately deferred."
- **Old plugin retirement.** A separate-repo concern. When this plugin
  ships its 1.0.0, the legacy `wc-maya-payment-gateway` repo gets a
  README update pointing here — outside this codebase.

---

## 2. Before / after file tree

```text
src/
├── Admin/
│   └── EventLog/                    # NEW (Phase 8)
│       ├── EventLogParser.php       # pure-static WC log-line parser
│       └── EventLogPage.php         # custom WC → Status tab
├── Plugin.php                       # EXTENDED: registers EventLogPage + RetryQueue + load_plugin_textdomain
└── Webhook/
    ├── RetryQueue.php               # NEW (Phase 8)
    └── WebhookHandler.php           # EXTENDED: calls RetryQueue::maybe_schedule after dispatch
bin/                                 # NEW (Phase 8)
├── make-pot.php
└── build-release.sh
languages/                           # NEW (Phase 8)
└── wc-maya-gateway.pot
tests/Unit/
├── Admin/EventLog/                  # NEW
│   └── EventLogParserTest.php
└── Webhook/
    └── RetryQueueTest.php           # NEW
CHANGELOG.md                         # NEW
README.md                            # EXTENDED
```

---

## 3. Maya events log viewer

WooCommerce already has a Status → Logs page that shows every
extension's entries. It works, but the signal-to-noise ratio is bad
when you're triaging a Maya issue — you scroll past Stripe logs, plugin
update logs, queue traces. The dedicated **"Maya events"** tab is a
narrowed view onto just the `wc-maya-gateway` log channel.

### 3.1 EventLogParser

WC's log file format (emitted by `WC_Log_Handler_File`):

```text
2026-05-26T03:51:06+00:00 LEVEL message {optional-json-context}
```

The parser is **pure static**, so the test suite pins every shape
without touching the filesystem:

```php
public static function parse_line(string $line): ?array;   // {timestamp, level, message, context} | null
public static function parse_lines(string $contents): array;
public static function filter_by_level(array $entries, array $levels): array;
public static function filter_by_search(array $entries, string $needle): array;
```

The non-obvious correctness bit: the context-detection logic walks `{`
positions **left-to-right**, attempting `json_decode` from each until
one succeeds. This is robust against `{` characters inside message text
— e.g. the URL `/payments/v1/payments/{id}/capture` — that the naive
"find the last `{`" approach gets wrong.

### 3.2 EventLogPage

Hooks two WC filters:

```php
add_filter('woocommerce_admin_status_tabs', [self::class, 'register_tab']);
add_action('woocommerce_admin_status_content_' . self::TAB_SLUG, [self::class, 'render']);
```

`render()`:

1. `manage_woocommerce` permission check.
2. List all `wc-maya-gateway-*.log` files in `WC_LOG_DIR` (sort newest
   first).
3. Resolve the selected file (from `?maya_log_file=`, falling back to
   newest).
4. Read + parse + filter (level checkboxes + free-text search box).
5. Tail to `MAX_ENTRIES = 500` so a multi-MB log doesn't blow out the
   admin page.
6. Render a `widefat striped` table: timestamp, level, message,
   pretty-printed JSON context.

The filter form GETs back into the same `wc-status` page, so every
state lives in the URL — bookmarkable, shareable, no JS required.

---

## 4. Action Scheduler retry queue

### 4.1 What problem this solves

Maya retries our webhook endpoint 4 times on their side when we
respond non-2xx. That covers most transient errors — a dead pod, a
broken DB connection — because we 5xx out before reaching dispatch
logic.

The case Maya's retries *don't* cover: we return **200 OK** (signature
verified, payload accepted) but the local dispatch hit a transient
problem. The two real-world cases:

1. **Order-not-found at webhook time.** Customer returns from Maya,
   `ReturnHandler` redirects to the thank-you page, the order row is
   in MySQL — but a few milliseconds later when the signed webhook
   POSTs, the order isn't yet visible to `wc_get_order()` on a
   read-replica. Race window is tiny but non-zero.
2. **`Payments::get_by_rrn` errored mid-dispatch.** The manual-capture
   branch of `EventDispatcher::dispatch` looks up the authoritative
   AUTHORIZED record before deciding whether `capturedAmount` covers
   the authorized total. If that call hits a 5xx or timeout, the
   webhook can't be completed *now* — but will likely succeed in 60
   seconds.

For both, retrying once is safe: every state mutation downstream
(`payment_complete()`, `update_status('failed')`, `add_order_note`) is
idempotent in WC. The retry queue is the safety net.

### 4.2 Lifecycle

```text
WebhookHandler::process()
    ├── verify signature/timestamp/IP
    ├── EventDispatcher::dispatch() → {action: 'order_not_found' | 'payment_complete' | ...}
    └── RetryQueue::maybe_schedule(dispatch, payload, attempt=1, logger)
        └── action in RETRYABLE_ACTIONS && attempt < MAX_ATTEMPTS (4)?
            └── as_schedule_single_action(
                    time() + plan_delay(attempt+1),
                    'wc_maya_replay_webhook',
                    [{ payload, attempt: attempt+1 }],
                    group='wc-maya-gateway',
                )

(time passes)

Action Scheduler fires:
    └── RetryQueue::handle({ payload, attempt: N })
        ├── DO NOT re-verify — original verification still trustworthy
        ├── EventDispatcher::dispatch(...) on same payload
        └── RetryQueue::maybe_schedule(...)   # chains until terminal or cap
```

### 4.3 Pure-static planner

The retry *policy* is two pure-static methods, both exhaustively
unit-tested:

```php
RetryQueue::should_schedule(['action' => 'order_not_found'], 1)     // true
RetryQueue::should_schedule(['action' => 'payment_complete'], 1)    // false (terminal)
RetryQueue::should_schedule(['action' => 'order_not_found'], 4)     // false (cap)

RetryQueue::plan_delay(1)   // 60   (1 minute)
RetryQueue::plan_delay(2)   // 240  (4 minutes)
RetryQueue::plan_delay(3)   // 960  (16 minutes)
RetryQueue::plan_delay(4)   // 3840 (64 minutes)
```

`RETRYABLE_ACTIONS` is a public constant on the class — anyone wanting
to change which dispatch outcomes trigger replays edits one list, and
the tests double-check that constant against an `equalsCanonicalizing`
assertion so the policy doesn't drift silently.

### 4.4 Why no signature re-verification

By the time `handle()` runs, the original `WebhookHandler::process()`
call already returned 200 — meaning signature, timestamp, and IP all
verified. The replay's purpose is **only** to retry the local
processing, not to rebroadcast the trust decision. Re-verifying would
also be impossible: AS only stores the args we hand it (the parsed
payload), not the original signature header.

---

## 5. Translation (i18n)

### 5.1 `bin/make-pot.php`

A standalone POT extractor. The standard tooling
(`wp i18n make-pot`) wants wp-cli installed; we can't depend on that
in every dev environment. The extractor walks `src/` and `templates/`
+ the main plugin file with focused regexes for the six call shapes we
use:

| Shape | Captured |
| --- | --- |
| `__('text', 'wc-maya-gateway')` | msgid |
| `_e('text', 'wc-maya-gateway')` | msgid |
| `esc_html__` / `esc_attr__` / `esc_html_e` / `esc_attr_e` | msgid |
| `_n('singular', 'plural', $count, 'wc-maya-gateway')` | msgid + msgid_plural |
| `_x('text', 'context', 'wc-maya-gateway')` | msgid + msgctxt |

Output: a sorted `languages/wc-maya-gateway.pot` with file:line refs
for every occurrence and an `X-Generator` header noting our extractor.

```bash
$ php bin/make-pot.php
Wrote 140 unique strings to languages/wc-maya-gateway.pot
```

The `.pot` is committed; CI re-runs the extractor and fails on drift
(once the GitHub Action is added — separate ticket).

### 5.2 `Plugin::load_textdomain`

```php
load_plugin_textdomain(
    'wc-maya-gateway',
    false,
    dirname(plugin_basename(WC_MAYA_PLUGIN_FILE)) . '/languages',
);
```

WP looks first in `wp-content/languages/plugins/wc-maya-gateway-<locale>.mo`
(the official translation slot, populated by translate.wordpress.org),
then in our bundled `languages/` folder. So we only need to ship `.mo`
files when we want a translation that *isn't* yet on translate.w.org.

---

## 6. Release build

```bash
$ bin/build-release.sh
Building wc-maya-gateway v1.0.0…
Built /…/dist/wc-maya-gateway-1.0.0.zip
Size:  1.4M
```

Two non-obvious details:

1. **Staging directory.** The script `rsync`s into
   `dist/woocommerce-maya-gateway/` and `composer install`s *there* —
   the plugin's working tree (and the dev `vendor/`) never get touched.
   You can run the build script with uncommitted changes, dirty
   composer.lock, dev-only deps installed — none of it leaks into the
   zip.
2. **vendor pruning.** `composer install --no-dev` already drops dev
   packages, but the runtime packages still ship their own
   `tests/`, `docs/`, `CHANGELOG.md`, `phpunit.xml`. The script
   post-prunes those because the release-zip target audience is end
   merchants who'll never run those packages' tests.

The resulting zip is upload-installable in WP admin → Plugins → Add
New → Upload Plugin. No composer required on the production server.

---

## 7. Anti-patterns deliberately avoided

| Tempted to | Why we didn't |
| --- | --- |
| Build the event-log viewer on top of a custom database table | The data is already in WC's log files; a parallel table would be data duplication + a migration concern. The parser handles the file format well enough. |
| Re-verify signatures on AS replay | Impossible (signature isn't in the persisted args) and pointless (verification already passed on the original delivery). |
| Allow `payment_complete` / `failed` actions to be replayed | They're terminal — replaying would either be a no-op (the order's already paid) or destructive (resetting a completed order). The allow-list keeps replays to genuine transient failures. |
| Use cron (`wp_schedule_event`) instead of Action Scheduler | WP cron is best-effort and tied to traffic; AS has a proper retry table, an admin viewer, and guarantees. WC already loads AS, so the dep is free. |
| Add an automatic-translation feature ("we'll pre-translate to Tagalog") | Out of scope; merchants who need translations install them from translate.w.org or supply their own `.mo`. Shipping low-quality machine translations would be worse than shipping none. |
| Use `wp_i18n make-pot` as a composer script | Requires wp-cli installed on every dev machine + CI runner. The standalone extractor is 200 lines of PHP and covers exactly the calls we make. Trade-off worth it. |
| Make the release-build script overwrite the dev `vendor/` | Would silently break the next test run. Staging dir is the right boundary. |
| Add browser tests to make the test suite "complete" | A Playwright setup needs Node, a running WP install, a fixture DB, and a CI runner with display support. None of that fits the current Pest-only single-command workflow. Deferred with a documented trigger: a flaky-rendering regression. |
| Force a database upgrade routine for option-key migration from the legacy plugin | Phase-1 decision: no backwards-compat with the old plugin. Merchants reconfigure. The new keys are documented in the README and CHANGELOG. |

---

## 8. Test coverage delta

| File | Cases | Covers |
| --- | --- | --- |
| `Admin/EventLog/EventLogParserTest.php` (new) | 15 | `parse_line` with: real WC line + JSON, no context, blank line, non-WC line, malformed trailing brace, nested JSON, brace-in-message-text; `parse_lines` with CRLF + interleaved garbage; `filter_by_level` empty + case-insensitive; `filter_by_search` against message + context-JSON + empty-needle pass-through |
| `Webhook/RetryQueueTest.php` (new) | 15 | `should_schedule` truth table for all three retryable actions + all non-retryable actions + attempt cap + missing-action key; `plan_delay` exponential schedule + 0-attempt floor; constants (`MAX_ATTEMPTS`, `ACTION_HOOK`, `GROUP`, `RETRYABLE_ACTIONS`); `maybe_schedule` skips non-retryable + at-cap + schedules-with-correct-args; `schedule` clamps negative delay |

**30 net new tests, 67 net new assertions.**

The two algorithms that actually drive Phase 8 behavior — log-line
parsing and retry policy — are pure-static and exhaustively covered.
The lifecycle methods (`EventLogPage::render`, `RetryQueue::handle`)
delegate to those pure helpers, so a regression in the policy would
fail loudly in unit tests before reaching admin or AS.

---

## 9. End-to-end audit

A real-world example chained together:

```text
1. Customer pays, returns to thank-you page, webhook arrives
   └── WebhookHandler::process(verified body, headers, IP)
       ├── EventDispatcher::dispatch(PAYMENT_SUCCESS, payload)
       │   └── wc_get_order(42) returns null  (DB lag race)
       │   └── return { action: 'order_not_found' }
       └── RetryQueue::maybe_schedule({order_not_found}, payload, 1)
           └── as_schedule_single_action(time()+60, 'wc_maya_replay_webhook',
                                          [{payload, attempt:2}], 'wc-maya-gateway')
           → action id 7041 scheduled

2. 60 seconds later, AS fires the action
   └── RetryQueue::handle({payload, attempt:2})
       ├── EventDispatcher::dispatch(PAYMENT_SUCCESS, payload)
       │   └── wc_get_order(42) returns the order  ← visible now
       │   └── $order->payment_complete('pay_xxx')
       │   └── return { action: 'payment_complete', order_id: 42 }
       └── RetryQueue::maybe_schedule({payment_complete}, payload, 2)
           └── action not in RETRYABLE_ACTIONS → return 0
       └── logger->info('RetryQueue: replay terminal.')

3. Merchant later opens WC → Status → Maya events
   └── EventLogPage::render()
       ├── parses today's wc-maya-gateway-*.log
       ├── filters: level=info+warning, search="42"
       └── table shows the four entries above with timestamps, level,
           message, decoded JSON context
```

---

## 10. Try it yourself

### Run the tests

```bash
cd web/app/plugins/woocommerce-maya-gateway
./vendor/bin/pest
```

Expected: `Tests: 212 passed (661 assertions)`.

### Regenerate the .pot

```bash
php bin/make-pot.php
```

Should print `Wrote 140 unique strings to languages/wc-maya-gateway.pot`
(value will increment as new translatable strings are added).

### Build a release zip

```bash
bin/build-release.sh
```

Verify the artifact:

```bash
unzip -l dist/wc-maya-gateway-1.0.0.zip | head -20
unzip -l dist/wc-maya-gateway-1.0.0.zip | wc -l   # ~few hundred files, no tests/ docs/ bin/
```

### Manual smoke tests

**Event log viewer.** Trigger a Test connection on the gateway settings
screen (creates a sandbox checkout + lists webhooks), then visit
WooCommerce → Status → **Maya events**. Confirm the entries appear
with timestamps, level chips, and pretty-printed JSON context.

**Action Scheduler retry.** Hardest to repro intentionally; the easiest
test is to disable the gateway between checkout and webhook arrival —
the `order_not_found` path fires, you should see a `wc_maya_replay_webhook`
action queued under WooCommerce → Status → Scheduled Actions.

**Translation.** Drop a translated `.mo` file into
`wp-content/languages/plugins/wc-maya-gateway-tl_PH.mo` (Tagalog) and
switch the WP locale; the settings screen should render the translated
labels.

---

## 11. Where to read next

- [../REBUILD_PLAN.md](../REBUILD_PLAN.md) — master plan; all eight
  phases now ✅. The rebuild is complete; new work goes through normal
  feature branches and the `.claude/skills/maya-gateway-structure/`
  decision tables.
- [../architecture.md](../architecture.md) — final architecture map
  including the Phase 8 observability + retry sections.
- [PHASE1-TOUR.md](PHASE1-TOUR.md) — codebase primer if you're new to
  the project.
- [PHASE6-TOUR.md](PHASE6-TOUR.md), [PHASE7-TOUR.md](PHASE7-TOUR.md) —
  the two phases whose dispatch actions feed into this phase's retry
  queue (refund flows reach `order_not_found` if a webhook races a
  refund-time order lookup; manual-capture flows reach
  `manual_capture_lookup_failed` when Maya hiccups during the
  authoritative `get_by_rrn` check).
