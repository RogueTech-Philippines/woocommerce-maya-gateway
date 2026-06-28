# Phase 6 tour — Refund + void

Status: **DONE** (2026-05-26).

> WC's Refund button now actually works. Smart-picks void (cheaper for the
> customer — never shows on their statement) when Maya still permits it,
> otherwise refunds. For manual-capture orders with multiple captures,
> walks them chronologically and splits the requested amount across them.

Delta tour. Assumes you've read [PHASE1-TOUR.md](PHASE1-TOUR.md) through
[PHASE5-TOUR.md](PHASE5-TOUR.md). The capture/EventDispatcher/manual-capture
context from Phase 5 is load-bearing here.

---

## 1. Definition-of-done — confirmed

The rebuild plan asked for:

> Refund flow works for: full void, full refund, partial refund single
> payment, partial refund split across two captures.

What was shipped:

- **163 tests passing, 549 assertions** — net delta of **+28** over Phase 5's
  135-test baseline.
- PHP lint clean (`php-cs-fixer` + `php -l`).
- New `Value/RefundRecord` DTO.
- `Value/PaymentRecord` gained `is_capture` (bool) + `is_authorization()`
  method + `created_at` (for chronological capture ordering).
- `Api/Endpoints/Payments` extended with `void()`, `refund()`, `get_refunds()`.
- `Gateway/RefundProcessor` with the full decision tree.
- `MayaGateway::process_refund` is a thin delegate; `'refunds'` is back in
  `$supports`.
- All four DoD scenarios green, plus exhaustive coverage of the pure
  planner (`plan_capture_actions`) and the reducer (`remaining_refundable`).

---

## 2. Before / after file tree

```text
src/
├── Api/Endpoints/
│   └── Payments.php                 # EXTENDED: void + refund + get_refunds
├── Gateway/
│   ├── MayaGateway.php              # EXTENDED: process_refund delegate; 'refunds' back in $supports
│   └── RefundProcessor.php          # NEW (Phase 6) — decision tree + pure planner
└── Value/
    ├── PaymentRecord.php            # EXTENDED: is_capture, is_authorization(), created_at
    └── RefundRecord.php             # NEW (Phase 6) — /payments/v1/payments/{id}/refunds item
```

```text
tests/Unit/
├── Api/Endpoints/PaymentsTest.php   # EXTENDED: void / refund / get_refunds happy + error paths
├── Gateway/RefundProcessorTest.php  # NEW (Phase 6) — 16 cases incl. 4 DoD scenarios + planner
└── Value/
    ├── PaymentRecordTest.php        # EXTENDED: is_capture, is_authorization, created_at
    └── RefundRecordTest.php         # NEW (Phase 6) — from_array, is_successful
```

---

## 3. The decision tree

`RefundProcessor::process(WC_Order $order, float $amount, string $reason)`
branches on the order's `_maya_authorization_type` meta:

```text
amount ≤ 0? → WP_Error invalid_amount
get_by_rrn fails? → bubble WP_Error

auth_type == 'none' (immediate-capture order)
    └── find PAYMENT_SUCCESS or REFUNDED payment for this RRN
        ├── none found → WP_Error no_payment
        ├── canVoid + amount == full → Payments::void()
        ├── canVoid + amount != full + !canRefund → WP_Error partial_void
        ├── !canRefund → WP_Error not_refundable
        └── canRefund → Payments::refund(amount)

auth_type ∈ {normal, final, preauthorization} (manual-capture order)
    └── find authorization record
        ├── none found → WP_Error no_authorization
        ├── only the auth exists (no captures yet)
        │   ├── !canVoid → WP_Error authorization_locked
        │   ├── amount != full → WP_Error partial_void
        │   └── canVoid + amount == full → Payments::void(authorization)
        └── captures exist
            ├── sort captures by createdAt ascending
            ├── for each capture: build available action
            │   ├── canVoid → action='void' for full capture amount
            │   └── canRefund → fetch get_refunds, remaining = amount − Σ SUCCESS refunds
            │                   if remaining > 0: action='refund' for remaining
            ├── plan_capture_actions(available, amount) — pure planner
            │   ├── walks available in order, consumes amount
            │   ├── 'void' actions must consume whole (Maya rejects partials)
            │   ├── 'refund' actions may take a partial slice
            │   └── unconsumed remainder > tolerance → WP_Error insufficient_balance
            └── execute_capture_actions(plan)
                └── each action runs Payments::void() or Payments::refund() + adds order note
```

The full tree is ported from the legacy plugin's `process_refund` (lines
809-1063), but reorganized so the load-bearing decision logic
(`plan_capture_actions`) is a pure static function. That lets the test suite
pin every branch of the void-vs-refund decision and the partial-amount
splitting without a single mock HTTP call.

---

## 4. New files

### 4.1 `Value/RefundRecord`

```php
final readonly class RefundRecord
{
    public function __construct(
        public string $id,
        public string $status,
        public Money $amount,
        public string $reason,
        public string $request_reference_number,
        public string $created_at,
    ) {}

    public static function from_array(array $data): self;
    public function is_successful(): bool;  // status === 'SUCCESS'
}
```

`is_successful()` is what the refund-balance reducer cares about: Maya
marks PENDING and FAILED refunds, which don't lock funds, and SUCCESS
refunds, which do.

### 4.2 `Value/PaymentRecord` (extended)

Two new fields and one helper:

```php
public bool $is_capture = false;     // true when 'authorizationPayment' key present in source
public string $created_at = '';      // ISO-8601, used for chronological sorting
public function is_authorization(): bool;  // true when authorization_type is non-null
```

`is_capture` mirrors Maya's marker: every capture record has an
`authorizationPayment` reference back to its parent authorization.
`is_authorization()` complements it for the symmetric question. Together
they let the refund processor partition `get_by_rrn` results into
"authorizations" and "captures" cleanly.

`created_at` is captured verbatim — the refund processor needs it for
`usort()` so captures are walked in the order they happened (FIFO across
the authorized balance).

### 4.3 `Api/Endpoints/Payments` (extended)

```php
public function void(string $payment_id, string $reason): PaymentRecord|WP_Error;
public function refund(string $payment_id, array $payload): RefundRecord|WP_Error;
public function get_refunds(string $payment_id): array|WP_Error;
```

All three use the Checkout secret key (same as `capture` and `get_by_rrn`).
`void` posts `{reason}` to `/voids` and returns the updated `PaymentRecord`
with `canVoid` flipped to false. `refund` posts `{totalAmount, reason}` to
`/refunds` and returns a fresh `RefundRecord`. `get_refunds` lists every
refund on file for a payment and wraps each item in a `RefundRecord` so
the reducer doesn't juggle raw arrays.

### 4.4 `Gateway/RefundProcessor`

The new orchestrator. Key API surface:

```php
public function process(WC_Order $order, float $amount, string $reason): true|WP_Error;
public static function plan_capture_actions(array $available, float $amount): array|WP_Error;
public static function remaining_refundable(PaymentRecord $capture, array $refunds): float;
```

`plan_capture_actions` is the pure planner — given a list of available
"action specs" (each one is a void or refund the merchant *could* take on
a specific capture) and the requested refund amount, it returns the
concrete actions to execute. The pure shape means tests can exhaustively
cover every shape of partial-split without touching Maya:

```php
$available = [
    [ 'action' => 'void',   'payment_id' => 'cap_1', 'amount' => 50.0,  'currency' => 'PHP' ],
    [ 'action' => 'refund', 'payment_id' => 'cap_2', 'amount' => 100.0, 'currency' => 'PHP' ],
];
$plan = RefundProcessor::plan_capture_actions($available, 75.0);
// → [
//     [ 'action' => 'void',   'payment_id' => 'cap_1', 'amount' => 50.0,  'currency' => 'PHP' ],
//     [ 'action' => 'refund', 'payment_id' => 'cap_2', 'amount' => 25.0, 'currency' => 'PHP' ],
//   ]
```

`remaining_refundable` does the obvious sum-and-subtract over a
`PaymentRecord` + its list of `RefundRecord`s, clamping at zero. Pure and
unit-tested separately because it's the source of "how much can I still
take from this capture?" — easy to get wrong if you forget to filter on
SUCCESS.

The orchestrator (`process`) wires the API calls around the pure planner,
adds order notes for each executed action, and returns `true` (WC's
"refund accepted" signal) or a `WP_Error` with one of these codes:

| Code | When |
| --- | --- |
| `wc_maya_refund_invalid_amount` | `amount <= 0` |
| `wc_maya_refund_no_payment` | Immediate-capture order with no PAYMENT_SUCCESS or REFUNDED record |
| `wc_maya_refund_not_refundable` | Maya says `canRefund: false` on the only candidate |
| `wc_maya_refund_partial_void` | Asked for a non-full amount on a void-only payment |
| `wc_maya_refund_no_authorization` | Manual-capture order with no authorization record |
| `wc_maya_refund_authorization_locked` | Authorization no longer voidable (expired / consumed) |
| `wc_maya_refund_insufficient_balance` | Multi-capture split couldn't cover the requested amount |
| `wc_maya_refund_empty_plan` | Planner produced no actions — defensive guard |
| `wc_maya_refund_partial_failure` | Mid-execution Maya error — some actions may have succeeded |

---

## 5. The four DoD scenarios

Each one is a single named test in `RefundProcessorTest`:

### Scenario 1: full void (immediate-capture)

```text
Order: paid immediately, payment.canVoid=true, payment.amount=100, refund request: 100
→ Payments::void('pay_immediate', reason)
→ Order note: "Maya void succeeded for payment pay_immediate (reason: …)"
→ return true
```

### Scenario 2: full refund (immediate-capture)

```text
Order: paid immediately, payment.canVoid=false, payment.canRefund=true, refund request: 100 of 100
→ Payments::refund('pay_immediate', {totalAmount: {amount: 100, currency: PHP}, reason: …})
→ Order note: "Maya refund 100 on payment pay_immediate (refund id rfd_1, reason: …)"
→ return true
```

### Scenario 3: partial refund, single capture (manual-capture)

```text
Order: manual-capture (preauthorization), one capture cap_1 of 100, refund request: 30
→ Payments::get_refunds('cap_1')  → []  (no prior refunds)
→ available = [{refund, cap_1, 100}]
→ plan = [{refund, cap_1, 30}]    (partial slice)
→ Payments::refund('cap_1', 30)
→ Order note
→ return true
```

### Scenario 4: partial refund split across two captures

```text
Order: manual-capture (preauthorization), captures cap_1 (80) and cap_2 (120) — refund request: 100
→ Captures sorted by createdAt: cap_1 (older), cap_2 (newer)
→ available = [{refund, cap_1, 80}, {refund, cap_2, 120}]
→ plan = [{refund, cap_1, 80}, {refund, cap_2, 20}]
→ Payments::refund('cap_1', 80)
→ Payments::refund('cap_2', 20)
→ Two order notes
→ return true
```

---

## 6. Anti-patterns deliberately avoided

| Tempted to | Why we didn't |
| --- | --- |
| Treat the capture API's `capturedAmount` echo as authoritative | Same lesson from the Phase 5 review — Maya's signed webhook stays the source of truth for order completion. The refund processor only mutates Maya state, never the order's WC status; that flows through the EventDispatcher when the matching webhook arrives. |
| Pre-flight every capture's `get_refunds` even when none of them are needed | The pre-flight runs only for captures the planner *might* consume — but the planner walks linearly until the amount is covered, so in the common case (refund a small slice of a single capture) only one `get_refunds` call fires. Not perfect, but the legacy plugin's behavior. |
| Sort by id alphabetically as a stand-in for `createdAt` | Sounded simpler, but Maya's payment ids aren't lexically time-ordered. `usort` by `createdAt` (ISO-8601 strings sort correctly lexically) is reliable and matches the legacy plugin. |
| Cache `get_refunds` across the refund | Each capture's `get_refunds` is a different URL — there's nothing to share. Per-call lookup is correct. |
| Stop the whole batch on the first partial failure and roll back successful steps | Maya doesn't support multi-statement transactions, and rolling back a successful void by re-authorizing is impossible. Best we can do is surface the failure (and the partial-success trail through order notes) so the merchant reconciles in the Maya dashboard. |
| Skip the `wc_maya_refund_empty_plan` guard ("can't happen") | Defensive: a future regression in `plan_capture_actions` returning `[]` instead of an error would otherwise silently no-op refunds. Cheap to keep. |
| Try to do partial voids by chopping the amount Maya-side | Maya rejects them with a 400. The planner enforces the rule client-side so the merchant gets a meaningful error message instead of an opaque Maya response. |

---

## 7. Test coverage delta

| File | Cases | Covers |
| --- | --- | --- |
| `Value/RefundRecordTest.php` (new) | 3 | from_array mapping, defaults, is_successful by status |
| `Value/PaymentRecordTest.php` (extended) | +3 | is_authorization, is_capture, created_at |
| `Api/Endpoints/PaymentsTest.php` (extended) | +6 | void/refund/get_refunds happy + error paths |
| `Gateway/RefundProcessorTest.php` (new) | 16 | All 4 DoD scenarios, the negative-amount guard, transport-error bubble, both partial-void error paths, planner exhaustive coverage (4 branches), reducer correctness (success + clamp) |

**28 net new tests.**

---

## 8. Try it yourself

### Run the tests

```bash
cd web/app/plugins/woocommerce-maya-gateway
./vendor/bin/pest
```

Expected: `Tests: 163 passed (549 assertions)`.

### Manual sandbox smoke test — immediate capture, full void

1. Place a sandbox order, pay with the test card, let it complete (Phase 4
   webhook promotes to `completed`).
2. Quickly (Maya's `canVoid` window is short for immediate captures —
   typically only a few minutes before the capture settles) hit the order's
   Refund button, enter the full amount.
3. Order note: "Maya void succeeded for payment pay_xxx (reason: …)". The
   refund completes WC-side, Maya's dashboard shows the payment voided.

### Manual sandbox smoke test — immediate capture, full refund

1. Same setup as above, but wait until `canVoid` flips false (Maya's
   settlement window). Then refund the full amount.
2. Order note: "Maya refund 100 on payment pay_xxx (refund id rfd_xxx,
   reason: …)". WC marks the order refunded.

### Manual sandbox smoke test — manual capture, partial refund single capture

1. Set Manual capture to PREAUTHORIZATION.
2. Place an order, pay with the test card, let it authorize.
3. Open the order edit screen, capture the full amount (200) via the
   Phase 5 capture panel.
4. After the PAYMENT_SUCCESS webhook promotes the order to `completed`,
   hit Refund with `30`. One capture exists, so the planner refunds 30 of
   200. Order note appears.

### Manual sandbox smoke test — partial refund split across two captures

1. Same setup as above, but capture in two halves: 80 first, then 120.
   Wait for both webhooks to settle the order.
2. Refund `100`. The planner walks cap_1 (80, whole) then cap_2 (20,
   partial). Two order notes appear; Maya's dashboard shows two refund
   records.

---

## 9. Where to read next

- [../REBUILD_PLAN.md](../REBUILD_PLAN.md) — master plan; Phase 7 lands
  WooCommerce Blocks support (the block-based checkout's payment method
  registration).
- [PHASE7-TOUR.md](PHASE7-TOUR.md) — next phase: WC Blocks integration
  for the block-based Cart and Checkout.
- [../architecture.md](../architecture.md) — updated file map, new
  "Refund" decision-tree diagram, expanded "which file do I open?" rows.
- [PHASE5-TOUR.md](PHASE5-TOUR.md) — manual capture, the prerequisite for
  every multi-capture refund flow this phase services.
- [PHASE4-TOUR.md](PHASE4-TOUR.md) — payment processing + EventDispatcher
  (the webhook side of "what counts as `completed`?").
