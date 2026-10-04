# Phase 5 — Platform and Integrations

> Depends on Phases 0–1 (idempotency, business date, journal, gateway) for safe external exposure. Additive only; external contracts versioned so internal refactors never break integrators.

---

## 5.1 Versioned Public REST API + Signed Outbound Webhooks + Docs

### (a) Operational rationale

Partners (OTA, POS, corporate travel) integrating against unversioned Inertia routes break on every deploy and poll the DB. Without versioning, scopes and signed webhooks, one leaked session cookie exposes the PMS.

### (b) Data model

```php
Schema::create('api_consumers', function (Blueprint $t) {
    $t->id(); $t->string('name',128); $t->foreignId('branch_id')->nullable()->constrained();
    $t->jsonb('scopes'); $t->string('webhook_url',256)->nullable(); $t->string('webhook_secret',128)->nullable();
    $t->timestamps();
});
Schema::create('webhook_deliveries', function (Blueprint $t) {
    $t->id(); $t->foreignId('api_consumer_id')->constrained(); $t->string('event',64);
    $t->jsonb('payload'); $t->string('signature',128); $t->string('status',16);
    $t->integer('attempts')->default(0); $t->timestamps(); $t->index(['status','updated_at']);
});
```

Sanctum tokens scoped per consumer (existing Sanctum; add `expires_at` enforcement). Backfill: none.

### (c) Service-layer design

- `Route::prefix('api/v1')->middleware(['auth:sanctum','ability:...','branch.scope'])`; read endpoints over Phase 1/2 services (never raw models); writes require `X-Idempotency-Key` (Phase 0) and return stable error envelope.
- `WebhookDispatcher` (queue `webhooks`): HMAC-SHA256 sign, exponential backoff 8×, per-consumer ordering key, replay endpoint re-signs same payload id. Scramble: secret rotation with dual-secret grace.
- Docs: Scramble/OpenAPI auto-generated + Postman collection, CI-fails on undocumented route.

### (d) Surface area

- Controllers `Api\V1\*` (Availability, Reservation, Folio-read, Rates-read, Revenue-read). Inertia `developers/Consumers.vue` (scopes, secret rotation, delivery log + replay).
- Reverb unchanged (webhooks are the async path for third parties).
- Endpoints (v1): `GET /availability`, `POST /reservations`, `GET /reservations/{id}`, `POST /reservations/{id}/cancel`, `GET /folios/{id}`, `GET /reports/revenue`, `POST /webhooks/replay`.

### (e) Permissions

New group `api: ['view','manage_consumers']`. Manage → Global Admin only; per-consumer scopes map to existing groups (e.g. `reservations.create`).

### (f) Pest tests

- Out-of-scope token → 403; expired → 401. Write without idempotency key → 422; replay → single effect.
- Webhook fake receiver: verifies HMAC, redelivery same signature, rotation grace accepts both.
- Docs test: every `api/v1` route has OpenAPI annotation (CI snapshot).

### (g) Estimate & deferral risk

**5–7 eng-days.** Deferral risk: MEDIUM — internal ops fine without it; every handshake integration before it becomes a breaking-change liability.

---

## 5.2 Accounting Connectors (Xero / QuickBooks / Sage daily journal export)

### (a) Operational rationale

Finance re-keying daily revenue into Xero mis-keys, delays close, and can't prove completeness. Without a reconciled export, the trial balance lives only in the PMS.

### (b) Data model

```php
Schema::create('accounting_links', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->string('provider',16);
    $t->jsonb('account_map'); // chart_code -> external ledger id
    $t->binary('oauth_enc'); $t->timestamps();
});
Schema::create('accounting_exports', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->date('business_date');
    $t->string('provider',16); $t->string('status',16); $t->string('external_id',128)->nullable();
    $t->jsonb('totals'); $t->timestamps(); $t->unique(['branch_id','business_date','provider']);
});
```

Backfill: none (exports start at go-live; historic via manual CSV from trial balances).

### (c) Service-layer design

- `AccountingExporter` interface + 3 drivers; `ExportDailyJournalJob` (queue `accounting`, after trial-balance close): maps chart → external accounts, posts balanced batch, stores external id; totals asserted equal to trial balance before send; failure → retry 5× then `failed` + Auditor alert; re-run same date → idempotent (unique key, provider-side dedupe via external ref `PMS-{branch}-{date}`).
- OAuth tokens encrypted, refreshed server-side; sandbox mode per branch.

### (d) Surface area

- `AccountingLinkController`, `AccountingExportController` (map editor, retry). Inertia `finance/Accounting.vue` (mapping grid, export status per date, drill to trial balance).
- Reverb: `AccountingExportCompleted/Failed`.

### (e) Permissions

New group `accounting_export: ['view','manage','retry']` (or extend `accounting`). Manage → Auditor, Global Admin; retry → + Branch GM.

### (f) Pest tests

- Fake driver: export totals == trial balance; unbalanced day → refused, no send. Retry same date → single external batch (fake dedupe asserted).
- Mapping missing account → 422 with account code. Concurrent exports → one wins.

### (g) Estimate & deferral risk

**6–9 eng-days** (3 OAuth certs). Deferral risk: LOW-MEDIUM — CSV export bridges months; pain grows at multi-branch close.

---

## 5.3 BI / Warehouse Export

### (a) Operational rationale

Analytics queries on the live OLTP slow check-in and can't answer year-over-year. Without a warehouse feed, every board report is a production query.

### (b) Data model

No new OLTP tables. Artefacts: nightly Parquet/CSV snapshots (`reservations`, `journal_entries`, `revenue_snapshots`, `inventory`) to R2 (`warehouse/dt=YYYY-MM-DD/`), plus `warehouse_manifests` table (date, files, row counts, checksums). Backfill: one initial full dump + 90-day manifests.

### (c) Service-layer design

- `WarehouseExportJob` (nightly, queue `reports`, after audit): chunked `cursor()` reads, writes Parquet (or CSV.gz v1), uploads to R2, writes manifest with counts; downstream (BigQuery/Snowflake loader) reads manifest; checksum mismatch → alert, no partial manifest. PII columns hashed or excluded per retention policy (Phase 4).
- Read path never touches primary for analytics (BI points at R2/manifest).

### (d) Surface area

- `WarehouseController` (manifest list, re-export). Inertia `analytics/Warehouse.vue` (freshness, row counts, schema version).
- Reverb: `WarehouseExportReady/Failed`. API: manifests listed under `/api/v1/warehouse/manifests`.

### (e) Permissions

Extend `analytics`: `export_warehouse`. → Auditor, Property Owner, Global Admin.

### (f) Pest tests

- Export row counts == DB counts; re-run same date → same checksums, no dupe files. PII columns absent/hashed in artefact.
- Manifest with tampered file → verification fails + alert.

### (g) Estimate & deferral risk

**3–4 eng-days.** Deferral risk: LOW — defer until reporting load hurts; keep analytics off primary meanwhile (read replica below first).

---

## 5.4 PBX Call Billing, Wi-Fi Captive Portal, IPTV, BLE Mobile Key

### (a) Operational rationale

Unbilled phone calls, open Wi-Fi with no PMS linkage, and plastic keys are revenue leakage + front-desk queues. Each is also a fraud/abuse vector (calls never posted, keys never revoked).

### (b) Data model

```php
Schema::create('call_records', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->foreignId('reservation_id')->nullable()->constrained();
    $t->string('extension',16); $t->bigInteger('duration_secs'); $t->bigInteger('charge_minor');
    $t->string('cdr_id',64)->unique(); $t->timestamps();
});
Schema::create('wifi_sessions', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->foreignId('reservation_id')->nullable()->constrained();
    $t->string('voucher',32)->unique(); $t->timestampTz('expires_at')->nullable(); $t->timestamps();
});
Schema::create('mobile_keys', function (Blueprint $t) {
    $t->id(); $t->foreignId('reservation_id')->constrained(); $t->string('device_id',64);
    $t->binary('key_enc'); $t->timestampTz('valid_from'); $t->timestampTz('valid_to'); $t->string('status',16); $t->timestamps();
});
```

Backfill: none (integrations start at cutover; historic CDRs imported once if vendor provides, keyed by `cdr_id`).

### (c) Service-layer design

- `PbxService`: CDR webhook (HMAC) → rate table → idempotent `cdr.{id}` → folio post (window `telecom`) + journal. Wi-Fi: portal authenticates by confirmation+name → issues time-boxed voucher tied to reservation dates; IPTV: checkout hook clears billing/pairing. `MobileKeyService`: BLE credential issuance via lock-vendor API (Salto/Assa/Duowin abstraction already present — extend with `issue/revoke/extend`), keys auto-expire at checkout + move (Phase 3.4 extends validity).
- All vendor calls behind interfaces with fakes; failures never block check-in (degraded: plastic key + flag).

### (d) Surface area

- `CallRecordController`, `WifiController`, `MobileKeyController`. Inertia front-desk key panel (`KeyCard.vue` + `MobileKey.vue`), Wi-Fi voucher print, IPTV pairing status.
- Reverb: `CallPosted`, `WifiIssued`, `MobileKeyIssued/Revoked`.
- API: voucher validation endpoint for portal; CDR webhook per vendor.

### (e) Permissions

Extend `door_lock`: `issue_mobile_key`; new `telecom: ['view','manage_rates']`. Mobile key → Front Desk; telecom rates → Branch GM.

### (f) Pest tests

- Duplicate CDR → single folio charge. Voucher for checked-out reservation → rejected. Key issue → revoke on checkout (fake vendor asserted); double issue idempotent.
- Vendor down → check-in still 200 with plastic-key fallback flag.

### (g) Estimate & deferral risk

**8–12 eng-days** (hardware/vendor sandboxes dominate). Deferral risk: LOW — amenity/capex-gated; sequence after core money is safe.

---

## 5.5 Full i18n, Per-Branch Timezone, Multi-Currency with Rate Snapshotting

### (a) Operational rationale

One timezone + one currency + English-only breaks the Abuja/Lagos + foreign-guest reality: reports shift days, francophone guests can't self-serve, and FX revaluation rewrites history.

### (b) Data model

Add `branches.timezone` (default `Africa/Lagos`), `branches.base_currency` (char 3). New `fx_rates` table: `{branch_id, from_ccy, to_ccy, rate_minor (scaled 1e6), effective_date, source}` unique on `(branch,from,to,date)`. Add `*.currency_code` already present — add `fx_snapshot JSONB` (rate + source + date) to `transactions`, `payment_transactions`, `trial_balances`. Backfill: set timezone/currency from branding; fx snapshot `{1.0, legacy}` on historic rows; never restate amounts.

### (c) Service-layer design

- `TimezoneService`: all business-date math in branch tz (replaces every `now()`/`whereDate` — grep and fix, blast radius ~20 sites; central `BranchTime::now(branch)` helper + Pint/CI lint rule banning bare `now()` in Services).
- `FxService::convert(amount, from, to, date)` — integer math on scaled rates, snapshot frozen at post; revaluation is a separate journal pair (never edits originals).
- i18n: `laravel-localization` + Vue i18n, locale per user + per branch default; money/date formatting via `Intl` with branch locale; translation keys CI-checked for missing FR entries (EN + FR required at launch).

### (d) Surface area

- Settings pages for timezone/currency/FX source; language switcher in layout + guest portal + booking engine. All dates rendered in branch tz with UTC tooltip.
- Reverb payloads include `business_date` + `tz`.
- API: `currency_code` + `fx_snapshot` on money payloads; `Accept-Language` honored.

### (e) Permissions

Extend `settings`: `manage_locale_fx`. → Global Admin (FX source), Branch GM (timezone/locale display).

### (f) Pest tests

- DST/boundary: 00:30 UTC posting lands on correct Lagos business date. FX: 10,000 NGN @ 1,500.00/ USD-scaled → exact minor-unit USD + frozen snapshot; rate change doesn't move old rows.
- Locale: FR rendering of booking engine spot-checked; missing-key CI test fails build.
- Concurrent postings across two branches in different tz → each anchored correctly.

### (g) Estimate & deferral risk

**5–7 eng-days.** Deferral risk: MEDIUM-HIGH — day-boundary bugs corrupt every report; FX restatement risk grows with foreign volume. Do timezone early in this phase.

---

## 5.6 Read Replicas, Partitioning (transactions, activity log), Backup/DR Runbook

### (a) Operational rationale

A single Postgres carrying OLTP + reports + ever-growing `transactions`/`activity_log` will slow check-in to a crawl and make restores a hope, not a plan. Untested backups are not backups.

### (b) Data model

- Partitions: `transactions` and `activity_log` range-partitioned by `(branch_id, created_month)` (pg declarative; Laravel migration creates parent + future partitions via `PartitionManagerJob` monthly; existing rows migrated with `pg_partman`-style backfill in batches, PK preserved, additive).
- `journal_entries` partitioned similarly (read-mostly, retention longest). No app-code key changes (Eloquent unaware; scopes unchanged).

### (c) Service-layer design

- DB: primary + async replica; `DB::connection('replica')` for all report/analytics/warehouse reads (`ReadFromReplica` middleware on GET report routes + explicit in services); writes always primary with `sticky` for read-your-write (30s).
- Backups: Spatie backup (already present) → nightly base + WAL to R2 cross-region; quarterly DR drill: restore to staging, run smoke suite (`php artisan dr:smoke`), record RTO/RPO in runbook; Horizon/paused-queue procedure documented.
- Failure: replica lag > 60s → reports show staleness badge (lag header), never block OLTP.

### (d) Surface area

- No guest-facing change. Admin `System/Database.vue`: replica lag, partition sizes, last restore drill date + RTO/RPO. Runbook `docs/DR-RUNBOOK.md` (tested steps, owners, escalation).
- Reverb: `ReplicaLagHigh`, `BackupFailed`.

### (e) Permissions

Extend `system_health`: `run_dr_drill`. → Global Admin only.

### (f) Pest tests

- Partition routing test (insert old + new month → correct child, query planner prunes). Replica-read test (report service uses replica connection — fake/assert).
- DR smoke: restore-from-manifest script dry-run in CI (small fixture) + runbook freshness test (drill date < 120 days or CI warns).

### (g) Estimate & deferral risk

**4–6 eng-days** (+ DBA/infra). Deferral risk: MEDIUM — fine under ~10M rows; cliff is sudden (vacuum/lock pain). Schedule before second property goes live.

---

## Phase 5 build order

1. Timezone/FX/i18n (5.5). 2. Versioned API + webhooks (5.1). 3. Accounting export (5.2) + warehouse (5.3, parallel). 4. Replicas/partitioning/DR (5.6). 5. Telco/keys (5.4, hardware-gated, last).
