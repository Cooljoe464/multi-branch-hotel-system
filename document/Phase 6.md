# Phase 6 — Intelligence (ML-assisted, human-approved)

> Depends on Phases 0–2 (snapshots, journal, restrictions, rate engine) and Phase 5 (warehouse). Principle: models propose, humans dispose — every automated action is a draft/flag requiring existing permissions, never silent money movement. All money in integer minor units. Additive only.

---

## 6.1 Demand Forecasting + Dynamic Pricing (feeds existing yield rules)

### (a) Operational rationale

Static yield rules can't see that next month's conference + pace surge justifies +20%. Without forecasts, pricing reacts a week late and leaves RevPAR on the table every compression night.

### (b) Data model

```php
Schema::create('demand_forecasts', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->date('stay_date');
    $t->date('generated_on'); $t->float('p_demand'); // probability model output OK as float (not money)
    $t->bigInteger('expected_rooms'); $t->jsonb('features'); $t->string('model_version',32);
    $t->timestamps(); $t->unique(['branch_id','stay_date','generated_on']);
});
Schema::create('price_recommendations', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->foreignId('room_type_id')->constrained();
    $t->date('stay_date'); $t->bigInteger('recommended_minor'); $t->bigInteger('current_minor');
    $t->string('status',16); // proposed|approved|rejected|applied|expired
    $t->foreignId('decided_by')->nullable()->constrained('users'); $t->timestamps();
});
```

Reads `revenue_snapshots` + warehouse (Phase 2.4/5.3); never rewrites them. Backfill: generate 90-day history on first run (marked `backfill` version).

### (c) Service-layer design

- `ForecastingService::generate` (nightly, queue `ml`): pickup-curve + seasonal baseline (heuristics v1; gradient-boosted model v2 via Python sidecar or SageMaker — PMS sends features, receives predictions, stores with `model_version`); `PricingRecommender::propose` clips to guardrails (±25%, floor per room type) and creates `proposed` rows; apply path reuses `RatePlan` update + `RestrictionsChanged` flow — requires human approve (or auto-apply only within ±5% if branch opts in, logged).
- Failure: model down → heuristics fallback; stale forecast (>48h) auto-expires, UI badges it. No direct writes to live rates by the model.

### (d) Surface area

- `ForecastController`, `PriceRecommendationController` (approve/reject/apply). Inertia `revenue/Forecast.vue` (demand calendar overlay, confidence bands, approve-all-in-guards button).
- Reverb: `ForecastReady`, `PriceProposed/Applied`.
- API: read-only forecast feed (v1).

### (e) Permissions

Extend `yield_rules`/`rate_plans`: `approve_ai_price`. Approve/apply → Branch GM, Property Owner; view → + Auditor, Front Desk (read).

### (f) Pest tests

- Known surge fixture → recommends increase within guardrails; crash fixture → decrease floored.
- Auto-apply off: proposed never touches live rate (assert). Double-apply same recommendation → single rate change (idempotency).
- Model-timeout → heuristic fallback used + flagged; expired forecast cannot be applied.

### (g) Estimate & deferral risk

**8–12 eng-days** (+ data-science tuning). Deferral risk: LOW — upside only; static rules + pace reports (2.4) carry you for a year.

---

## 6.2 Anomaly Detection (feeds audit flags)

### (a) Operational rationale

A cashier voiding 12 bills nightly or a 3am rate drop to ₦1,000 is found weeks later in Excel — after the cash is gone. Without automated flags, audit is sample-based luck.

### (b) Data model

```php
Schema::create('anomaly_rules', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->nullable()->constrained(); // null = global
    $t->string('code',64); $t->jsonb('params'); $t->boolean('active')->default(true); $t->timestamps();
});
Schema::create('anomaly_findings', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->string('rule_code',64);
    $t->morphs('subject'); $t->float('score'); $t->string('status',16); // open|cleared|confirmed
    $t->jsonb('evidence'); $t->timestamps(); $t->unique(['rule_code','subject_type','subject_id']);
});
```

Writes into existing `audit_flags` (finding → flag link, no schema break). Backfill: run rules over last 30 days once (marked `backfill`, `open` only above high threshold to avoid inbox flood).

### (c) Service-layer design

- `AnomalyService::scan` (hourly + post-audit, queue `ml`): rules (void rate, discount rate, refund burst, after-hours rate change, inventory jump, no-show fee skip) + z-score vs 28-day baseline from journal/snapshots; findings deduped by unique key; `confirmed` requires Auditor action; false-positive feedback tunes thresholds per branch.
- Never auto-voids or auto-charges — flag only. PII excluded from evidence payloads.

### (d) Surface area

- `AnomalyController` (findings inbox, rule tuner). Inertia `audit/Anomalies.vue` (score-sorted queue, evidence drawer, confirm/clear).
- Reverb: `AnomalyRaised` (private `branch.{id}.audit`).

### (e) Permissions

Extend `audit`: `manage_anomaly_rules`, `confirm_anomaly`. Rules → Auditor, Global Admin; confirm → Auditor, Branch GM.

### (f) Pest tests

- Injected void-burst fixture → finding raised once; re-scan → no dupe. Threshold feedback changes future scores.
- Benign high-volume day (conference) → no flag (baseline-aware). Concurrent scans → single findings.

### (g) Estimate & deferral risk

**5–7 eng-days.** Deferral risk: LOW-MEDIUM — loss prevention ROI from month one, but manual audit covers small scale.

---

## 6.3 WhatsApp AI Concierge

### (a) Operational rationale

Front desk answers "what's the Wi-Fi code / checkout time / breakfast hours" 200× daily while queues build. Without a grounded concierge, staff drown in routine chat and OTA messages go unanswered.

### (b) Data model

```php
Schema::create('concierge_threads', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->foreignId('guest_id')->nullable()->constrained();
    $t->foreignId('reservation_id')->nullable()->constrained(); $t->string('channel',16); // whatsapp
    $t->string('status',16); $t->timestamps();
});
Schema::create('concierge_messages', function (Blueprint $t) {
    $t->id(); $t->foreignId('concierge_thread_id')->constrained()->cascadeOnDelete();
    $t->string('role',16); $t->text('body'); $t->jsonb('tool_calls')->nullable(); $t->timestamps();
});
```

No money tables; booking/upselI actions call existing services with guest confirmation + idempotency (never direct DB writes from the model).

### (c) Service-layer design

- `ConciergeService`: WhatsApp BSP webhook (verified, queue `concierge`) → identity-link (confirmation+name, consent check Phase 4) → RAG over branch KB (hours, menus, policies) + tool calls (`getBooking`, `requestLateCheckout` → creates upsell acceptance draft, `logMaintenance`) → human handoff on low confidence / money action / complaint sentiment; all replies carry "AI assistant" disclosure; transcripts on reservation timeline.
- Guardrails: price quotes only from RateEngine; no key/PII in chat beyond masking; rate-limited per guest; kill-switch per branch.

### (d) Surface area

- `ConciergeController` (threads, handoff, KB editor). Inertia `concierge/Inbox.vue` (agent + AI side-by-side, take-over button).
- Reverb: `ConciergeReplied`, `HandoffRequested`.
- API/webhook: `POST /webhooks/whatsapp` (BSP signature).

### (e) Permissions

New group `concierge: ['view','reply','manage_kb']`. Reply/takeover → Front Desk; KB → Branch GM.

### (f) Pest tests

- FAQ fixture → grounded answer cites KB; rate question matches engine quote exactly (no hallucinated price — assert).
- Money action → draft created, not applied, pending guest confirm; double webhook → single draft.
- No-consent guest → marketing blocked, service replies allowed (scope test). Kill-switch → instant human-only mode.

### (g) Estimate & deferral risk

**8–12 eng-days** (+ BSP costs/eval). Deferral risk: LOW — labor saver, not correctness; pilot on one branch first.

---

## 6.4 Natural-Language Reporting ("revenue last Detty December vs budget, by segment")

### (a) Operational rationale

Managers wait days for "quick numbers" because only analysts speak SQL. Without governed NL queries, they either fly blind or leak data through ad-hoc dumps.

### (b) Data model

None new (reads snapshots/journal/warehouse + saved `report_queries {name, nl, sql, owner}`). Query log `nl_query_logs` (prompt hash, sql hash, rows, ms — no result PII).

### (c) Service-layer design

- `NlReportingService`: NL → constrained Text-to-SQL (allow-listed tables/columns, branch-scope injected, read-replica only, row cap 5k, timeout 15s) → shows SQL + results + export; every query permission-checked against caller's groups (segment PII masked without `view_pii`); hallucination guard: generated SQL must parse against allow-list or it's refused.
- Runs on queue `reports` (async for slow queries) with idempotent query keys for re-runs.

### (d) Surface area

- `NlReportController` (`POST /reports/ask`, saved queries). Inertia `analytics/Ask.vue` (chat + SQL inspector + chart auto-render).
- Reverb: `NlQueryCompleted/Failed`.

### (e) Permissions

Uses existing `reports.view/export` + `analytics.view`; saving shared queries → `analytics.manage`.

### (f) Pest tests

- Fixture questions → exact expected SQL shape + row values on seeded data. Cross-branch ask → only own branch rows (scope test).
- Disallowed table (`users.password`) → refused. Slow query → async job + same results on poll; replay key → cached.

### (g) Estimate & deferral risk

**5–8 eng-days.** Deferral risk: LOW — convenience; dashboards (2.4) cover the 80%.

---

## 6.5 Predictive Maintenance + Automated Housekeeping Scheduling

### (a) Operational rationale

ACs fail the night the hotel is full and room assignments ignore attendant load + guest ETA. Without prediction + auto-scheduling, supervisors plan from memory at 7am under pressure.

### (b) Data model

```php
Schema::create('asset_health_scores', function (Blueprint $t) {
    $t->id(); $t->foreignId('asset_id')->constrained(); $t->date('scored_on');
    $t->float('failure_prob'); $t->jsonb('signals'); $t->timestamps();
    $t->unique(['asset_id','scored_on']);
});
Schema::create('hk_schedules', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->date('work_date');
    $t->jsonb('assignments'); $t->string('status',16); // draft|published
    $t->timestamps(); $t->unique(['branch_id','work_date']);
});
```

Reads work orders/assets (3.3) + reservations/ETA + HK tasks/credits (3.2). Backfill: score history from ticket frequency heuristic (marked `heuristic`).

### (c) Service-layer design

- `PredictiveMaintenanceService` (nightly, queue `ml`): failure probability from ticket recurrence + age + category baseline → auto-creates PM work order drafts above threshold (human publishes; never auto-takes rooms OOO — recommends, GM confirms, then 3.2 flow).
- `HkSchedulerService` (nightly + on-ETA-change): optimizes assignments (credits ≤ cap, priority: VIP arrival > checkout > stayover, floor clustering) → `draft` schedule → supervisor publishes → tasks created idempotently (`hk.{date}.{room}.{kind}`).
- Overrides preserved; re-run keeps manual edits (diff-merge, manual pins win).

### (d) Surface area

- `AssetHealthController`, `HkScheduleController` (publish/edit). Inertia `maintenance/Health.vue` (risk-ranked assets), `housekeeping/Schedule.vue` (drag-drop board, publish).
- Reverb: `AssetRiskRaised`, `HkSchedulePublished`.

### (e) Permissions

Uses `maintenance.manage_assets` + `housekeeping.assign`; auto-publish → Branch GM only (drafts viewable by supervisors).

### (f) Pest tests

- Repeat-failure asset → risk score rises, PM draft created once; re-run → no dupe. High-risk auto-OOO never fires without approval (assert room still sellable).
- Scheduler: 100 rooms / 6 attendants → all caps respected, VIPs first; manual pin survives re-run; publish twice → single task set.

### (g) Estimate & deferral risk

**6–9 eng-days.** Deferral risk: LOW — real payoff at 100+ rooms; heuristics (SLA + credits) suffice until then.

---

## Phase 6 build order & guardrails

1. Anomaly detection (6.2, smallest, feeds audit). 2. Forecasting/pricing (6.1). 3. HK/predictive scheduling (6.5). 4. NL reporting (6.4). 5. Concierge (6.3, external dependency, last).
   Global guardrails: `ml` queue isolated with CPU/memory limits; model outputs versioned + explainable (features stored); kill-switches per feature per branch; eval harness (`tests/ML/*` fixtures, nightly) gates promotion; no model writes money/availability directly — all through Phase 0–2 services with human approval.
