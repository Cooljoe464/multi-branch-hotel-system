# Phase 1 — Financial and Inventory Integrity

> Depends on Phase 0 (business dates, idempotency, versions, journal, Horizon). All money in integer minor units. Additive migrations only.
> Thin-implementation verdict up front: current `NightAuditService`, `FolioService`, availability check, single `branch.tax_rate` float, and float FX will fail under real load and real accounting scrutiny — this phase replaces each behind the faces below.

---

## 1.1 Per-Date Availability Engine (single source of truth, row locking, overbooking policy)

### (a) Operational rationale

Checking `rooms.status` cannot answer "is room-type X free 12–15 Dec". Without a per-date inventory ledger, two concurrent bookings oversell the same night, and OTA + front desk diverge. Oversells at 11pm are the most expensive bug a hotel can ship.

### (b) Data model

```php
// room_type_inventory: one row per (branch, room_type, date)
$t->foreignId('branch_id')->constrained(); $t->foreignId('room_type_id')->constrained();
$t->date('stay_date'); $t->integer('total_rooms'); $t->integer('sold')->default(0);
$t->integer('blocked')->default(0); // maintenance/OOO holds, group blocks
$t->integer('overbooking_limit')->default(0); // policy snapshot
$t->unique(['room_type_id','stay_date']); $t->index(['branch_id','stay_date']);
// room_inventory holds: physical room assignment per reservation-night (for tape chart/moves)
$t->foreignId('reservation_id')->constrained(); $t->foreignId('room_id')->nullable();
$t->date('stay_date'); $t->unique(['reservation_id','stay_date']);
```

Add `reservations.overbooked BOOLEAN DEFAULT false`, `branches.overbooking_policy JSONB` (`{mode: none|percent|count, value}`). Backfill: build `room_type_inventory` from existing `rooms` (total per type) + active reservations exploded per night (`sold++`); `UPDATE ... WHERE sold>total` flagged `overbooked=true` for manual review — never silently deleted.

### (c) Service-layer design

- `AvailabilityService::quote(branch, roomType, in, out, qty)` — reads inventory, no locks. `::reserve(branch, ..., idempotencyKey)` — `DB::transaction`: `SELECT ... FOR UPDATE` on each night's inventory row (ordered by date to avoid deadlock), assert `sold+qty <= total+overbooking_limit-blocked`, increment, create reservation + holds. `::release` decrements on cancel/no-show. Dedicated queue `reservations` for OTA imports.
- Concurrency: row locks serialize same-night writes; different nights don't block. Deadlock retry 3× with jitter. Overbooking allowed only with `reservations.overbook.allow` permission + reason, logged + `AuditFlag`.
- Failure: partial nights unavailable → whole transaction rolls back, returns per-night availability diff (no partial holds).

### (d) Surface area

- Controllers: `AvailabilityController` (`GET /branches/{b}/availability`, `POST /branches/{b}/availability/hold`). Inertia: tape chart rewired to read engine; new `AvailabilityCalendar.vue`.
- Reverb: `InventoryChanged(branch.{id})` (debounced per minute), `OverbookingUsed` alert.
- API: `GET /api/v1/availability` (Phase 5).

### (e) Permissions

New group `availability: ['view','override_overbook']`. `override_overbook` → Global Admin, Branch GM only. Front Desk gets view.

### (f) Pest tests

- Concurrent reserve for last room (20 parallel) → exactly 1 wins, 19 get 409 + diff.
- Idempotent reserve replay → single reservation.
- Cancel releases nights; re-run release is no-op. Overbook without permission → 403; with permission + reason → flagged.

### (g) Estimate & deferral risk

**5–6 eng-days.** Deferral risk: CRITICAL — every booking until this ships can oversell; cannot be patched later without rewriting reservations.

---

## 1.2 Folio Redesign (windows, routing rules, splits, group master/child, transfers)

### (a) Operational rationale

One folio per reservation cannot express "company pays room, guest pays minibar" or "split dinner 40/30/30". Without windows + routing, night staff post to the wrong payer and checkout becomes a 20-minute argument.

### (b) Data model

```php
Schema::create('folio_windows', function (Blueprint $t) {
    $t->id(); $t->foreignId('folio_id')->constrained()->cascadeOnDelete();
    $t->string('code', 16); // room | incidentals | package | ...
    $t->string('payer_type', 16); // guest | company | group_master
    $t->foreignId('city_ledger_account_id')->nullable()->constrained();
    $t->timestamps(); $t->unique(['folio_id','code']);
});
Schema::create('folio_routing_rules', function (Blueprint $t) {
    $t->id(); $t->foreignId('folio_id')->constrained()->cascadeOnDelete();
    $t->string('charge_category', 32); // room_rate|pos|minibar|laundry|...
    $t->foreignId('target_window_id')->constrained('folio_windows');
    $t->integer('priority')->default(0); $t->boolean('active')->default(true); $t->timestamps();
});
Schema::create('transaction_splits', function (Blueprint $t) {
    $t->id(); $t->foreignId('transaction_id')->constrained()->cascadeOnDelete();
    $t->foreignId('target_window_id')->constrained('folio_windows');
    $t->bigInteger('amount_minor'); $t->integer('percent_bps')->nullable(); // 4000 = 40%
    $t->timestamps();
});
```

Add to `transactions`: `folio_window_id`, `group_master_folio_id` (nullable), `transfer_of_transaction_id` (nullable, self-ref), `version`. `folios`: add `version`, `is_master`. Backfill: create default `room` window per folio; set existing transactions to it; no amount changes.

### (c) Service-layer design

- `FolioService` extended: `postToWindow(folio, windowCode|routing, ...)` resolves routing rules inside the posting transaction (`lockForUpdate` on folio + window); `splitTransaction(tx, [{window, percent_bps|amount_minor}])` validates splits sum to tx amount (integer-safe, remainder to first window, documented); `transferToMaster(childTx, masterFolio)` creates compensating pair (void original + repost, linked by `transfer_of_transaction_id`, journal reversal + new entry).
- All inside one DB transaction + idempotency key (`folio.{id}.post.{key}`); stale `version` → 409.

### (d) Surface area

- `FolioWindowController`, `FolioRoutingController` (CRUD). Inertia `folios/Show.vue` gains window tabs, routing-rule editor, split dialog (`SplitDialog.vue`), transfer-to-master button.
- Reverb: `TransactionPosted` payload gains `folio_window_id`, `splits`; new `FolioTransferred`.
- API: windows/splits exposed under `/api/v1/folios/{id}` (Phase 5).

### (e) Permissions

Extend `folios` group: `split`, `transfer`, `manage_routing`. `split/transfer` → Front Desk, Cashier, Branch GM; `manage_routing` → Branch GM, Global Admin.

### (f) Pest tests

- Percentage split 33/33/34 on 1000 minor → sums exactly, remainder rule asserted.
- Concurrent posts to two windows → both succeed, balances per window correct.
- Transfer re-run with same idempotency key → single transfer pair. Stale version transfer → 409, no partial void.

### (g) Estimate & deferral risk

**6–8 eng-days.** Deferral risk: HIGH — corporate/group billing is unusable without it; retrofitting splits onto posted data is error-prone.

---

## 1.3 Tax Engine (inclusive/exclusive, VAT, service charge, levy, jurisdiction, exemptions, snapshots, FIRS emitter)

### (a) Operational rationale

A single `branch.tax_rate` float cannot produce a legal Nigerian invoice (VAT 7.5% + service charge + occupancy levy, some exempt, some inclusive). Wrong tax math means fines and failed e-invoicing; floats make it worse with rounding drift.

### (b) Data model

```php
Schema::create('tax_profiles', function (Blueprint $t) { // per branch + jurisdiction
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->string('jurisdiction', 32); // NG-LA, NG-FCT
    $t->string('name', 64); $t->boolean('active')->default(true); $t->timestamps();
});
Schema::create('tax_components', function (Blueprint $t) {
    $t->id(); $t->foreignId('tax_profile_id')->constrained()->cascadeOnDelete();
    $t->string('code', 32); // VAT | SERVICE_CHARGE | OCCUPANCY_LEVY
    $t->string('mode', 16); // exclusive | inclusive
    $t->integer('rate_bps'); // 750 = 7.5%
    $t->string('applies_to', 32); // room | fnb | all ...
    $t->integer('sequence')->default(0); // compounding order
    $t->timestamps();
});
Schema::create('tax_exemptions', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained();
    $t->morphs('exemptable'); // reservation/guest/company
    $t->string('component_code',32); $t->string('reason',128); $t->timestamps();
});
```

Add to `transactions`: `tax_snapshot JSONB` (frozen profile+components+math per line), `tax_total_minor`. Keep legacy `tax_amount` for compat (populated from snapshot sum). Backfill: create `NG-DEFAULT` profile per branch from `branch.tax_rate` (as exclusive VAT); freeze snapshot on existing transactions (`rate_bps` from branch, `snapshot_version: legacy`).

### (c) Service-layer design

- `TaxService::compute(baseMinor, profile, category, exemptions)` — pure integer math, per-component rounding (round-half-up, remainder to largest component), returns lines + total. `FolioService` calls it at post time and freezes snapshot; rate changes never rewrite history.
- `EInvoiceEmitter` interface + `FirsEmitter` (Nigeria FIRS): queued `EmitEinvoiceJob` (queue `fiscal`), signs payload, stores IRN/status on `fiscal_documents` table; failure → retry with backoff, never blocks checkout.
- Block: `branch.tax_rate` float must be retired (keep column, ignore). Blast radius: `FolioService`, `NightAuditService`, POS posting.

### (d) Surface area

- `TaxProfileController` CRUD; Inertia `settings/TaxProfiles.vue` + folio line tax breakdown tooltip.
- Reverb: `FiscalDocumentIssued` / `FiscalDocumentFailed`.
- API: tax lines included in folio/bill payloads.

### (e) Permissions

New group `tax: ['view','manage']` → Global Admin, Branch GM, Auditor (view). `fiscal: ['view','retry']` → Auditor, Branch GM.

### (f) Pest tests

- Inclusive vs exclusive math table (1000/750bps cases, rounding remainder).
- Exemption removes one component only; snapshot frozen — changing profile rate doesn't alter old tx.
- FIRS emitter fake: success stores IRN; failure retries 3× then `failed` + alert; replay same idempotency key → single document.

### (g) Estimate & deferral risk

**5–7 eng-days** (+ FIRS sandbox access). Deferral risk: HIGH — tax errors are strict-liability; rebuild touches every posted row.

---

## 1.4 Idempotent Resumable Night Audit (no-show, day-use, early departure)

### (a) Operational rationale

Current `closeDailyLedger()` is neither idempotent nor resumable: a crash mid-post double-charges some rooms and skips others, and no-show/day-use/early-departure are unhandled so revenue leaks or overstates every night.

### (b) Data model

```php
Schema::create('night_audit_runs', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->date('business_date');
    $t->string('status', 16); // running | posted | reconciled | failed
    $t->string('idempotency_key', 64);
    $t->jsonb('steps'); // [{step: post_room, status, count}, ...]
    $t->jsonb('result')->nullable(); $t->timestamps();
    $t->unique(['branch_id','business_date']); $t->unique('idempotency_key');
});
```

Add `reservations.audit_outcome` (string: `stayed|no_show|day_use|early_departure`, nullable), `reservations.no_show_fee_minor`. Backfill: none (new runs only); historic ledgers linked by `(branch,business_date)`.

### (c) Service-layer design

- Rewrite `NightAuditService` around `BusinessDateService` + `JournalService` + `TaxService`: `run(branch, date, idemKey)` — claim `night_audit_runs` row; steps: `lock_date → post_room (per-reservation idempotent sub-keys nightaudit.{date}.res.{id}) → apply_no_show_fees → apply_day_use → close_walked/early → post_taxes → trial_balance_check → advance_business_date`. Each step commits independently + records progress; crash → re-`run()` resumes from first incomplete step (step-level idempotency).
- Runs on queue `night-audit` (`ShouldBeUnique` on `(branch,date)`), 3 retries with backoff; supervisor approval not needed but `AuditFlag` on variance.
- No-show: fee per rate plan (Phase 2) or default first-night; day-use: half/single rate code; early departure: post through actual last night, release remaining inventory (calls 1.1).

### (d) Surface area

- `NightAuditController` (`show/run/retry/step-status`). Inertia `night-audit/Show.vue` (stepper, per-step counts, resume button, variance panel).
- Reverb: `NightAuditStepCompleted`, `NightAuditCompleted`, `NightAuditFailed` (private `branch.{id}.audit`).
- API: read-only run status (Phase 5).

### (e) Permissions

Extend `audit` group: `run_night_audit`, `retry_night_audit`. Run/retry → Branch GM, Global Admin, Auditor (retry). Front Desk: view only.

### (f) Pest tests

- Crash injection after 3/10 rooms → re-run posts remaining 7, first 3 not duplicated (journal count == 10).
- No-show reservation → fee posted, inventory released, folio flagged. Day-use → single charge, room released same date. Early departure → charges stop at departure, future nights released.
- Concurrent `run()` twice → one active, one 409; ledger still balanced.

### (g) Estimate & deferral risk

**6–8 eng-days.** Deferral risk: CRITICAL — current audit will double-post on first real retry; revenue figures untrusted until this ships.

---

## 1.5 Cashier Shift & Drawer (Z-report, variance, void/refund codes, supervisor approval)

### (a) Operational rationale

Without shifts and counted drawers, cash shortages vanish into "system error" and voids/refunds are untraceable. A hotel handling cash without Z-reports and approval trails will fail both internal audit and tax inspection.

### (b) Data model

```php
Schema::create('cashier_shifts', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->foreignId('user_id')->constrained();
    $t->date('business_date'); $t->bigInteger('opening_float_minor');
    $t->bigInteger('expected_cash_minor')->default(0); $t->bigInteger('counted_cash_minor')->nullable();
    $t->bigInteger('variance_minor')->nullable();
    $t->string('status',16); // open|closed|reconciled
    $t->timestampTz('opened_at'); $t->timestampTz('closed_at')->nullable(); $t->timestamps();
});
Schema::create('void_refund_codes', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->string('code',32); $t->string('kind',16); // void|refund
    $t->boolean('requires_supervisor')->default(false); $t->boolean('active')->default(true); $t->timestamps();
});
Schema::create('void_refund_approvals', function (Blueprint $t) {
    $t->id(); $t->morphs('subject'); $t->foreignId('reason_code_id')->constrained('void_refund_codes');
    $t->foreignId('requested_by')->constrained('users'); $t->foreignId('approved_by')->nullable()->constrained('users');
    $t->string('status',16); // pending|approved|rejected
    $t->text('note')->nullable(); $t->timestamps();
});
```

Add `transactions.void_reason_code_id`, `payment_transactions.refund_reason_code_id`. Backfill: seed standard codes per branch; no historic rewrite.

### (c) Service-layer design

- `CashierShiftService::open/close/reconcile` — close computes expected from journal (`cash` payments + floats − payouts) inside shift transaction; variance → `AuditFlag` + requires GM note if > threshold. Z-report = shift-scoped trial slice (journal filtered by shift window), printable + R2-archived PDF.
- `VoidRefundService`: void/refund above threshold or flagged codes require supervisor approval (second user, different id) before the compensating journal entry posts; all idempotent.

### (d) Surface area

- `CashierShiftController`, `VoidRefundController`. Inertia `cashier/Shift.vue` (open/count/close wizard, variance banner), `finance/Voids.vue`, `ZReport.vue` (print).
- Reverb: `ShiftClosed`, `VarianceFlagged`, `VoidApprovalRequested/Decided`.

### (e) Permissions

New group `cashier: ['open_shift','close_shift','approve_void']`. Open/close → Cashier, Front Desk, Branch GM; approve → Branch GM, Auditor. `reports.export` covers Z-report PDF.

### (f) Pest tests

- Close with counted ≠ expected → variance row + flag; over-threshold close without note → 422.
- Void without supervisor for protected code → 403/pending; second-user approval posts reversal once; replay → no-op.
- Two concurrent closes → one wins; Z-report totals == journal slice.

### (g) Estimate & deferral risk

**4–5 eng-days.** Deferral risk: HIGH — cash leakage starts day one and is unrecoverable without logs.

---

## 1.6 Double-Entry Posting (chart of accounts, daily trial balance that must reconcile)

### (a) Operational rationale

Single-sided `transactions` rows can never prove "books balance". Without a chart of accounts and an enforced trial balance, month-end close is manual Excel and fraud hides in unbalanced postings.

### (b) Data model

```php
Schema::create('chart_accounts', function (Blueprint $t) {
    $t->id(); $t->string('code', 16)->unique(); // 1100 Cash, 4100 Room Revenue ...
    $t->string('name', 96); $t->string('type', 16); // asset|liability|revenue|expense|equity
    $t->boolean('system')->default(false); $t->timestamps();
});
Schema::create('trial_balances', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->date('business_date');
    $t->jsonb('totals'); // {debits, credits, per_account: {...}}
    $t->boolean('balanced'); $t->timestamps(); $t->unique(['branch_id','business_date']);
});
```

Journal (Phase 0) already stores debit/credit legs — this phase seeds the chart and enforces balance. Backfill: seed standard hotel chart (30 accounts); compute historic `trial_balances` from journal backfill (informational, `balanced` may be false for legacy — flagged, never edited).

### (c) Service-layer design

- `PostingService` (replaces ad-hoc `FolioService` math for ledger purposes): every `JournalService::post` requires valid `(debit, credit)` from chart; `TrialBalanceService::close(date)` sums journal per branch/date, asserts `debits == credits`, writes row; night audit calls it as final gate — unbalanced → audit stays `failed`, blocks `advance_business_date`, pages Auditor.
- Mapping table `posting_rules` (event → debit/credit accounts) seeded, editable only by Auditor/GA.

### (d) Surface area

- `ChartAccountController`, `TrialBalanceController` (read + re-run). Inertia `finance/Chart.vue`, `finance/TrialBalance.vue` (balanced ✅/❌ banner, drill-down to journal).
- Reverb: `TrialBalanceCompleted {balanced}`, `TrialBalanceFailed`.

### (e) Permissions

New group `accounting: ['view','manage_chart','close_period']`. Manage/close → Auditor, Global Admin; view → Branch GM, Property Owner.

### (f) Pest tests

- Every posting test asserts journal legs balance; trial close on balanced day → `balanced=true`; injected unbalanced entry (test-only bypass) → close fails, audit blocked.
- Re-run trial close → same row (idempotent). Legacy backfill day with imbalance → flagged, does not block new days.

### (g) Estimate & deferral risk

**4–5 eng-days.** Deferral risk: CRITICAL — without it you have no books, only receipts; auditors will qualify the accounts.

---

## 1.7 Payment Gateway Driver Abstraction (tokenization, pre-auth, partial refunds, webhook verify + replay)

### (a) Operational rationale

Paystack-only code with unverified webhooks means a forged callback can mark bills "paid", cards can't be pre-authed for incidentals, and switching/adding gateways (Flutterwave, Stripe) requires rewriting checkout. Chargebacks on unprotected webhooks are a direct cash loss.

### (b) Data model

```php
Schema::create('payment_methods', function (Blueprint $t) { // tokens, never PANs
    $t->id(); $t->morphs('owner'); // guest/reservation
    $t->string('driver', 32); // paystack|flutterwave|...
    $t->string('token', 128); $t->string('brand', 16)->nullable(); $t->string('last4', 4)->nullable();
    $t->date('exp_date')->nullable(); $t->timestamps();
});
```

Extend `payment_transactions`: `driver`, `gateway_ref` (unique per driver), `kind` (`sale|preauth|capture|refund|void`), `parent_id` (self-ref for capture/refund chains), `webhook_event_id` (unique), `amount_minor` (already int — enforce). Backfill: set `driver=paystack` on existing rows; populate `gateway_ref` from stored reference where present.

### (c) Service-layer design

- `PaymentDriver` interface (`tokenize, preauth, capture, charge, refund(partial), void`) + `PaystackDriver` (first), `PaymentService` facade selecting driver by branch config; all webhook entry via `WebhookController` → `VerifySignature` → `IdempotencyService(scope=driver.webhook, key=event_id)` → queued `ProcessPaymentWebhookJob` (queue `payments`, `ShouldBeUnique`, backoff 5).
- Pre-auth at check-in (configurable amount), capture at checkout, partial refunds via parent chain; every state change journals + broadcasts. Secrets in `config/payments.php` + env, never DB plaintext.
- Block: current `PaystackWebhookController` must gain verification first. Blast radius: `PaymentService`, guest portal, POS payments.

### (d) Surface area

- `PaymentMethodController` (token list, no PAN display), `PaymentTransactionController` (refund/capture buttons). Inertia `folios/Payments.vue` (pre-auth banner, partial-refund dialog), guest portal pay page unchanged (driver-switched server-side).
- Reverb: `PaymentAuthorized/Captured/Refunded`, `WebhookRejected`.
- API: `POST /api/v1/payments/intent`, webhook `POST /webhooks/{driver}` (raw body preserved for HMAC).

### (e) Permissions

Extend `folios` or new `payments: ['charge','refund','manage_drivers']`. Charge → Front Desk, Cashier; refund → Cashier + supervisor rule (1.5); manage_drivers → Global Admin only.

### (f) Pest tests

- Forged signature → 401, no side effect; replay same event_id → single payment.
- Pre-auth → capture (partial) → refund (partial): journal chains sum correctly; over-capture/over-refund → 422.
- Driver swap fake (`FakeDriver`) proves abstraction (no Paystack code in `PaymentService`).
- Concurrent webhook deliveries → one processed.

### (g) Estimate & deferral risk

**5–7 eng-days** (+ gateway sandbox). Deferral risk: CRITICAL — live-money forgery and no-hold checkouts lose cash from day one.

---

## Phase 1 build order

1. Availability (1.1). 2. Tax + chart/journal wiring (1.3 + 1.6 skeleton). 3. Folio windows/splits (1.2). 4. Gateway abstraction (1.7). 5. Cashier shifts (1.5). 6. Night-audit rewrite (1.4) + trial-balance gate (1.6 close).
