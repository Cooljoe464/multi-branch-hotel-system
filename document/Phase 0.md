# Phase 0 — Foundation: Business Date, Idempotency, Locking, Observability, Journal, CI

> Goal: make every later phase safe to build. No revenue logic in this phase; only the rails it runs on.
> Conventions: `EnsuresBranchAccess` on all controllers, Spatie permissions, `App\Services\*` with `DB::transaction` + `lockForUpdate`, dedicated queue names (`night-audit`, `imports`, `channel`, `payments`, `reports`), Spatie activity log on state changes, Pest `tests/Feature/*Test.php` + `tests/Unit/*Test.php`. Additive migrations only. All money in integer minor units.

---

## 0.1 Business-Date Model per Branch (open/closed state)

### (a) Operational rationale

Without a per-branch business date, night audit posts to "today" while the front desk still sells "yesterday". Without it, occupancy, ADR, Z-reports and audits never reconcile across shifts, and day-use/early-departure logic has no anchor.

### (b) Data model

New table `business_dates`:

```php
Schema::create('business_dates', function (Blueprint $t) {
    $t->id();
    $t->foreignId('branch_id')->constrained()->cascadeOnDelete();
    $t->date('business_date');
    $t->string('status', 16); // open | closing | closed  (string + CHECK, not native enum for pg/compat)
    $t->timestampTz('opened_at')->nullable();
    $t->timestampTz('closed_at')->nullable();
    $t->foreignId('opened_by')->nullable()->constrained('users');
    $t->foreignId('closed_by')->nullable()->constrained('users');
    $t->jsonb('close_summary')->nullable(); // rooms_posted, revenue totals, ledger id
    $t->timestamps();
    $t->unique(['branch_id','business_date']);
    $t->index(['branch_id','status']);
});
```

Columns on existing tables (additive, nullable first): `reservations.business_date` (date, nullable), `transactions.business_date` (date), `pos_charges.business_date` (date), `payment_transactions.business_date` (date), `daily_ledgers.business_date` stays canonical. Add `branches.current_business_date` (date, nullable) as cached pointer — source of truth remains `business_dates where status=open` (exactly one open row per branch enforced by partial unique index `WHERE status='open'`).

Migration + backfill plan:

1. Migration A: create `business_dates`, add nullable columns above, add partial unique index.
2. Backfill job `BackfillBusinessDatesJob` (queue `maintenance`): for each branch, `open_date = min(daily_ledgers.business_date) ?? today`; insert one `open` row at `today` (branch timezone, default Africa/Lagos) and `closed` rows for each historic `daily_ledger.business_date`. Backfill `transactions.business_date := date(created_at)`, same for others. Idempotent (`updateOrCreate` on `(branch_id,business_date)`), chunked by 1000, logs counts to activity log.

### (c) Service-layer design

- `App\Services\BusinessDateService` — `current(Branch): BusinessDate` (creates today's open row if none, under `lockForUpdate` on branch row + advisory lock `pg_advisory_xact_lock(hashtext('bizdate:'.$branch->id))`); `advance(Branch, user)` — only callable from NightAudit close path; `isOpen`, `requireOpen` guard used by posting services.
- Transaction boundary: `advance()` runs inside the night-audit DB transaction (see Phase 1): insert next-day `open` row + mark current `closing` → post → mark `closed`. Locking: `SELECT ... FOR UPDATE` on the open `business_dates` row; concurrent `advance()` calls serialize; second caller gets `BusinessDateAlreadyClosingException`.
- Failure modes: clock skew (use `now($branch->timezone)`); double-close (idempotent on `(branch,status)`); crash mid-close → row stays `closing`, resume path re-enters (Phase 1).
- Current architecture block: `NightAuditService::closeDailyLedger()` uses `now()->subDay()` and `whereDate(created_at)` — timezone-unsafe and branch-timezone-blind. Must refactor to inject `BusinessDateService::current()` first. Blast radius: `NightAuditService`, `PostRoomChargesJob`, `CloseDailyLedgerJob`, all `whereDate` queries (grep ~12 sites).

### (d) Surface area

- Controller: `BusinessDateController` (`show`, `advance`) under `/branches/{branch}/business-date`. Inertia pages: `resources/js/pages/business-date/Show.vue` (current date, status pill, close checklist, advance button), component `BusinessDateBadge.vue` in app layout header.
- Reverb: new `BusinessDateAdvanced(branch.{id})` on private `branch.{id}` channel; `DailyLedgerClosed` payload gains `business_date`.
- API: `GET /api/v1/branches/{branch}/business-date` (see Phase 5 versioning; Phase 0 ships internal only).

### (e) Permissions

New group `business_date: ['view','close']`. Roles: Global Admin (both), Branch GM (both), Auditor (view), Front Desk (view). Cashier (view — needs Z-report date anchor).

### (f) Pest tests

- `BusinessDateTest`: advance creates next-day open row; double `advance()` throws / second is no-op with same idempotency key; exactly-one-open enforced (parallel `advance()` via `spatie/fork` or two DB connections — assert one wins).
- Re-run: crashing after `closing` then re-running `advance()` resumes and completes once.
- Timezone: branch `Africa/Lagos` vs server UTC at 00:30 UTC still yields correct business date.

### (g) Estimate & deferral risk

**2–3 eng-days.** Deferral risk: HIGH — every financial number built without this is wrong by one day at month-end and unfixable retroactively.

---

## 0.2 Idempotency Keys for all POSTs, Webhooks and Jobs

### (a) Operational rationale

Front-desk double-clicks, Paystack webhook retries, and queue redeliveries currently create duplicate folios, charges and payments. Without idempotency, "retry safely" is impossible and every later retry/backoff design is unsafe.

### (b) Data model

```php
Schema::create('idempotency_keys', function (Blueprint $t) {
    $t->id();
    $t->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
    $t->string('key', 64); // client-supplied UUID / Paystack reference / job fingerprint
    $t->string('scope', 64); // e.g. folio.post, reservation.create, paystack.webhook, nightaudit.2026-09-20
    $t->string('status', 16); // in_progress | completed | failed
    $t->jsonb('request_hash')->nullable();
    $t->jsonb('response')->nullable();
    $t->timestampTz('locked_until')->nullable();
    $t->timestamps();
    $t->unique(['scope','key']);
    $t->index(['status','updated_at']);
});
```

Add nullable `idempotency_key` (string 64) + index to `transactions`, `reservations`, `payment_transactions`, `folios` for traceability. Backfill: none (nullable, new writes only).

### (c) Service-layer design

- `App\Services\IdempotencyService::run(scope, key, ttl, Closure)` — `INSERT ... ON CONFLICT DO NOTHING` to claim; if existing `completed`, return stored response (HTTP 200 + `Idempotent-Replayed: true` header); if `in_progress` and `locked_until` future, return 409; else execute closure inside DB transaction, store response hash, mark completed. Jobs: `ShouldBeUnique` + fingerprint from `(scope,key)`; middleware `WithoutOverlapping`.
- Middleware `RequireIdempotencyKey` on mutating web routes + `VerifyWebhookSignature` (Phase 1) feeds the same table with scope `paystack.webhook`.
- Failure: SSE/dupe key with different payload → 422 `IdempotencyKeyConflict` (never silently reuse).

### (d) Surface area

- HTTP: `X-Idempotency-Key` header accepted on all POST/PUT/PATCH; Inertia `useForm` wrapper `useIdempotentForm()` auto-attaches UUID. New `IdempotencyController` read-only replay inspector (admin).
- Reverb: no new events; replayed responses include `replayed: true` in broadcast meta.
- API: documented header in Phase 5 OpenAPI.

### (e) Permissions

New group `idempotency: ['view']` → Global Admin, Auditor, Branch GM.

### (f) Pest tests

- Double POST with same key creates one row (HTTP test, `assertDatabaseCount`).
- Concurrent double POST (two processes) → one 200, one 200-replayed or 409, single side effect.
- Webhook retried 3× with same reference → single payment; different payload same key → 422.
- Job redelivered after crash → `ShouldBeUnique` prevents second run; resume path (night audit) covered in 0.1/1.4.

### (g) Estimate & deferral risk

**3–4 eng-days** (middleware + service + frontend wrapper + webhook wiring). Deferral risk: CRITICAL — duplicates compound daily; backfill cannot dedupe money safely.

---

## 0.3 Optimistic Locking on reservations, rooms, folios, KOTs

### (a) Operational rationale

Two agents editing the same reservation/folio/KOT overwrite each other silently (lost update: wrong rate, wrong bill, duplicated kitchen fire). Pessimistic locks alone deadlock at check-in rush; version checks give safe, explainable conflicts.

### (b) Data model

Add `version INTEGER NOT NULL DEFAULT 1` to `reservations`, `rooms`, `folios`, `kot_items` (KOT header table if exists else `kot_items` group key — create `kots` header in Phase 3; Phase 0 adds `version` to `kot_items` + `pos_charges`). Index not needed. Backfill: default 1, no data change. All updates go through `where('id',...)->where('version',$expected)->increment('version')` pattern or Eloquent `OptimisticLocking` trait throwing `StaleModelException` (409).

### (c) Service-layer design

- `App\Concerns\HasOptimisticLock` trait: `saveWithVersion($expected)`, auto-increment on save, `resolveConflict` hook (e.g. folio re-read + re-sum). Services (`ReservationService` — new, extracted from controller; `FolioService`; `KotRoutingService`) accept `expected_version` param; Inertia forms submit `version`; mismatch → 409 + fresh model + diff.
- Locking strategy: optimistic for user edits; `lockForUpdate` retained inside short posting transactions. No retry-on-stale for money (surface to user); auto-retry (1×) only for system posts (night audit room charge re-reads folio).
- Current block: controllers update models directly without versions. Blast radius: 4 models + ~8 controllers; mechanical change.

### (d) Surface area

- Inertia: `ConflictDialog.vue` (shows your vs current values, reload button). Controllers return 409 JSON with `current` resource.
- Reverb: existing `TransactionPosted`, KDS events gain `version` field; new `ModelConflicted` not broadcast (local 409 only).

### (e) Permissions

No new permissions (uses existing `reservations.update`, `folios.manage`, `kds.manage`).

### (f) Pest tests

- Stale version update → 409, no write; fresh retry succeeds.
- Concurrent folio posts: two processes, one stale — assert one 409 and balance correct (no lost update).
- KOT fire twice with same version → single fire.

### (g) Estimate & deferral risk

**2–3 eng-days.** Deferral risk: MEDIUM-HIGH — data corruption at peak load; cheap now, painful after.

---

## 0.4 Observability (Horizon, Sentry, Health Endpoints, Queue Alerts)

### (a) Operational rationale

With `QUEUE_CONNECTION=database` and no error tracking, a stuck night-audit or failed Paystack job is discovered at checkout queue. You cannot run a hotel on silent job failures.

### (b) Data model

No app tables. Infra: Redis (queues + cache), Horizon `metrics` tables (vendor). Add `health_checks` log table optional — prefer stateless checks. Backfill: none.

### (c) Service-layer design

- Replace `database` queue with `redis` + Horizon (`config/horizon.php`: supervisors per queue — `default`, `night-audit`, `payments`, `channel`, `imports`, `reports`, `maintenance`; `balance=auto`, maxProcesses per env; `failed_job` alert webhook to Slack/email via `Horizon::routeMailNotificationsTo`).
- Sentry (`sentry/sentry-laravel`): traces 10%, `before_send` strips PII (guest email/phone), release = git SHA; queue job context includes `branch_id`, `business_date`, `idempotency_key`.
- Health: `GET /healthz` (liveness), `GET /readyz` (DB + Redis + R2 + Reverb echo check), `GET /health/queues` (Horizon paused/failed counts) — used by Docker HEALTHCHECK + uptime monitor. Queue alert job `MonitorStuckQueuesJob` (every 5 min, queue `maintenance`): failed_jobs growth or `night-audit` latency > 15 min → notify + activity log.
- Failure modes: Redis down → `failover` connection (database fallback) keeps audit alive; document degraded mode.

### (d) Surface area

- Routes: `/healthz`, `/readyz`, `/health/queues` (no auth, rate-limited). Inertia: admin `System/Health.vue` embedding Horizon link + queue latency cards.
- Reverb: new `QueueAlertRaised` (private `admin.system`) for stuck night-audit/payments.
- API: health endpoints versioned out (`/healthz` unversioned by convention).

### (e) Permissions

New group `system_health: ['view']` → Global Admin, Auditor, Branch GM.

### (f) Pest tests

- `readyz` returns 200 with all green, 503 when DB down (mock).
- Horizon supervisor config asserts 7 queues exist; stuck-queue monitor test with fake failed job creates alert (notification fake).
- Sentry: assert PII scrubber unit test (email redacted).

### (g) Estimate & deferral risk

**2–3 eng-days** (+ DevOps for Redis). Deferral risk: HIGH — blind ops; first real night-audit failure will be unrecoverable without it.

---

## 0.5 Append-Only Financial Journal (separate from activity log)

### (a) Operational rationale

Spatie activity log is mutable, free-text and mixes "user changed room" with "posted ₦50,000". Auditors and tax authorities require an immutable, sequential, double-entry-capable journal; without it every Phase 1 reconciliation is hearsay.

### (b) Data model

```php
Schema::create('journal_entries', function (Blueprint $t) {
    $t->id(); // strict sequence; never reuse
    $t->foreignId('branch_id')->constrained();
    $t->date('business_date');
    $t->string('event', 64); // room_charge.posted, payment.received, tax.accrued, ...
    $t->string('debit_account', 32); $t->string('credit_account', 32); // chart codes, Phase 1 seeds
    $t->bigInteger('amount_minor'); // CHECK > 0
    $t->char('currency_code', 3);
    $t->bigInteger('fx_rate_to_branch_minor')->default(1000000); // 1.0 scaled 1e6, never float
    $t->morphs('source'); // transaction / payment_transaction / ...
    $t->string('idempotency_scope',64)->nullable(); $t->string('idempotency_key',64)->nullable();
    $t->foreignId('created_by')->nullable()->constrained('users');
    $t->timestampTz('posted_at');
    $t->unique(['idempotency_scope','idempotency_key']); // exactly-once journal
    $t->index(['branch_id','business_date']); $t->index(['source_type','source_id']);
});
// No update/delete grants in app code; DB trigger or RLS prevents UPDATE/DELETE (postgres rule).
```

Backfill: one-shot `BackfillJournalJob` replays `transactions` + `payment_transactions` (ordered by id) into journal with `posted_at=created_at`, `business_date` from 0.1 backfill. Reversible-check: `sum(journal)==sum(transactions)` per branch asserted in test; originals untouched.

### (c) Service-layer design

- `App\Services\JournalService::post(branch, businessDate, event, debit, credit, amountMinor, source, idempotency)` — single INSERT inside the caller's DB transaction (never its own commit); throws on zero/negative; void = compensating reversal entry (never UPDATE).
- All Phase 1 postings call it; Phase 0 wires `FolioService::postDebit/postCredit` to also journal (behind feature flag `journal.enabled`, default on in tests).
- Concurrency: unique on idempotency key gives exactly-once; sequence from PK order (no gap-less requirement — gaps from rollbacks are fine, documented).

### (d) Surface area

- Controller `JournalController@index/show` (read-only, filterable by date/event/account). Inertia `finance/Journal.vue` (append-only banner, no edit buttons).
- Reverb: `JournalPosted` (private `branch.{id}.finance`) — debounced/batched during night audit.
- API: `GET /api/v1/.../journal` in Phase 5.

### (e) Permissions

New group `journal: ['view','export']` → Global Admin, Auditor, Branch GM (view), Property Owner (view). No manage — immutable.

### (f) Pest tests

- Posting twice with same idempotency key → one journal row.
- Attempted `update/delete` on journal → denied (model has no update path; DB test expects exception via trigger).
- Backfill replay: sums match; re-running backfill adds zero rows.

### (g) Estimate & deferral risk

**3 eng-days.** Deferral risk: CRITICAL — without it, Phase 1 double-entry has no foundation and auditors will reject the books.

---

## 0.6 CI with Pest, Seeded Demo Tenant, Load Test on Availability

### (a) Operational rationale

Without CI + seeded demo + availability load test, regressions (double-booking under concurrency) ship to production. The existing `DoubleBookingTest`/`ReservationConcurrencyTest` must run on every PR, not just once.

### (b) Data model

Seeders only: `DemoTenantSeeder` (1 group → 2 branches Lagos/Abuja, 5 room types, 60 rooms, 9 roles, 12 users, 30-day rate plans, historic business_dates + journal via factories). No schema change. Deterministic (`--seed` with fixed faker seed `1234`).

### (c) Service-layer design

- GitHub Actions `.github/workflows/ci.yml`: `pest --compact --parallel` (postgres service, redis service), `pint --test`, `phpstan`, `npm run types:check`; artifacts: failed-test screenshots, coverage (min 70% on `Services/`).
- Load test: `tests/Load/AvailabilityLoadTest.php` (marked `--group=load`, nightly + manual): 200 concurrent availability checks for same dates via `Http::pool` against local server or direct `AvailabilityService` with 20 parallel processes; asserts p95 < 400ms, zero overbooks. K6 script `tests/load/availability.js` for staging (optional, same SLOs).
- Failure semantics: load group excluded from default PR run (`--exclude-group=load`), required on `main` nightly.

### (d) Surface area

- None user-facing. Internal: `php artisan demo:seed --fresh` command; CI badge in README; Horizon + Sentry DSN documented in `DEPLOYMENT.md`.

### (e) Permissions

None.

### (f) Pest tests

- Meta: `DemoTenantSeeder` runs in < 90s, spot-check counts.
- Load test itself is the test (see SLOs above); plus assertion that PR suite includes concurrency tests (CI config snapshot test).

### (g) Estimate & deferral risk

**2 eng-days.** Deferral risk: MEDIUM — velocity tax grows; the availability race will bite during the first OTA flash sale.

---

## Thin-implementation flags (found in current codebase)

1. `NightAuditService` is thin: no business-date anchoring, no locks, swallows errors, uses `whereDate(created_at/paid_at)`, `exchange_rate_to_group` is float, single `branch->tax_rate` float. Will fail accounting scrutiny. Must be rewritten in Phase 1 on top of 0.1/0.2/0.5.
2. `FolioService` balance via `outstanding_balance` recompute + `lockForUpdate` but no version/idempotency/journal — double-post under retry likely. Hardened in 0.2/0.3/0.5.
3. No `AvailabilityService` / inventory table — reservation creation almost certainly checks `rooms.status` only, which cannot prevent date-range double-booking under concurrency. Phase 1 replaces with per-date engine.
4. `PaymentTransaction`/`PaystackWebhookController` — verify signature + replay protection before adding drivers (Phase 1).
5. `QUEUE_CONNECTION=database` + `after_commit=false` — outbox unsafe; jobs can fire before commit. Switch to redis + `after_commit=true` in this phase.
6. Money: `amount` ints are correct, but `tax_rate` and `exchange_rate_to_group` floats violate minor-units — migrate to bps / scaled ints in Phase 1.

## Phase 0 build order (reviewable units)

1. Business dates + service (0.1). 2. Idempotency (0.2). 3. Versions (0.3). 4. Journal (0.5). 5. Horizon/Sentry/health (0.4). 6. CI/demo/load (0.6).
