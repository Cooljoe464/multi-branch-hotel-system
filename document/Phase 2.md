# Phase 2 — Revenue and Distribution

> Depends on Phase 0 (business date, idempotency, journal) and Phase 1 (availability engine, tax snapshots, night-audit outcomes, gateway drivers). All money in integer minor units. Additive only.
> Thin-implementation verdict: current `yield_rules` + `rate_overrides` + `channel_*` tables are a pricing suggester and a fire-and-forget sync — they will fail under OTA contention, parity audits, and commission reconciliation.

---

## 2.1 Rate Restrictions (MinLOS, MaxLOS, CTA, CTD, stop-sell, min advance)

### (a) Operational rationale

Without close-to-arrival/departure, min/max length-of-stay and stop-sell, revenue managers cannot protect weekends or force 3-night minima — the hotel sells its best nights to one-night OTA stays and turns away high-value guests.

### (b) Data model

```php
Schema::create('rate_restrictions', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->foreignId('rate_plan_id')->constrained();
    $t->foreignId('room_type_id')->nullable()->constrained(); // null = all types
    $t->date('stay_date'); $t->integer('min_los')->nullable(); $t->integer('max_los')->nullable();
    $t->boolean('cta')->default(false); $t->boolean('ctd')->default(false);
    $t->boolean('stop_sell')->default(false); $t->integer('min_advance_hours')->nullable();
    $t->timestamps(); $t->unique(['rate_plan_id','room_type_id','stay_date']);
    $t->index(['branch_id','stay_date']);
});
```

Backfill: one open row-set per active rate plan (all flags off) so evaluation is uniform; no behavior change until restrictions are set.

### (c) Service-layer design

- `RestrictionService::evaluate(branch, plan, roomType, in, out, bookingAt)` — pure function over the stay-date range; `AvailabilityService::quote/reserve` calls it inside the same transaction (after row locks) and rejects with typed `RestrictionViolation {code, dates}` (e.g. `MIN_LOS_3`, `STOP_SELL`). Overrides require `rates.override_restriction` + reason → activity log + audit flag.
- Failure: partial-range violation returns full date list (UI highlights nights); never auto-trims dates.

### (d) Surface area

- `RateRestrictionController` (bulk date-range editor). Inertia `rates/Restrictions.vue` (calendar grid with CTA/CTD/stop-sell toggles, MinLOS cells).
- Reverb: `RestrictionsChanged(branch.{id})` → booking engine + channel ARI queue refresh.
- API: violations surfaced as 422 with machine codes.

### (e) Permissions

Extend `rate_plans` group: `manage_restrictions`. → Branch GM, Global Admin. Front Desk gets read-only view (to explain rejections).

### (f) Pest tests

- Booking violating MinLOS=3 with 2 nights → 422 `MIN_LOS_3`; stop-sell night → 422; CTA date as arrival → 422, as mid-stay → OK.
- Concurrent restriction change + booking → booking evaluated against committed restrictions (repeatable-read safe via re-check after lock).
- Override with permission + reason succeeds and logs; without → 403.

### (g) Estimate & deferral risk

**3–4 eng-days.** Deferral risk: MEDIUM-HIGH — revenue leakage is silent (wrong guest mix) and OTA parity disputes blame you.

---

## 2.2 Rate Plan Depth (derived rates, packages with folio breakdown, promo codes, corporate rates, seasons)

### (a) Operational rationale

Flat nightly `room_rate` cannot sell "BAR −10%", "bed+breakfast ₦15k", corporate Deloitte rates, or Detty-December seasons. Without component breakdown, packages post as opaque lumps that tax, commission and analytics all misreport.

### (b) Data model

```php
Schema::create('rate_plans', function (Blueprint $t) { /* extends existing: add */ });
// additive alters: base_plan_id nullable FK, kind enum-string (standalone|derived|package|corporate|promo),
// derivation_rule JSONB {offset_bps|offset_minor}, package_components JSONB [{code:breakfast, amount_minor, tax_profile}],
// seasons handled via rate_seasons table:
Schema::create('rate_seasons', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->string('name',64);
    $t->date('start_date'); $t->date('end_date'); $t->integer('multiplier_bps')->default(10000); $t->timestamps();
});
Schema::create('promo_codes', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->string('code',32);
    $t->foreignId('rate_plan_id')->constrained(); $t->integer('uses_max')->nullable(); $t->integer('uses_count')->default(0);
    $t->date('valid_from')->nullable(); $t->date('valid_to')->nullable(); $t->boolean('active')->default(true);
    $t->timestamps(); $t->unique(['branch_id','code']);
});
Schema::create('corporate_accounts', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->string('name',128);
    $t->foreignId('city_ledger_account_id')->nullable()->constrained();
    $t->foreignId('negotiated_plan_id')->nullable()->constrained('rate_plans'); $t->timestamps();
});
```

Add `reservations.rate_plan_id`, `reservations.rate_snapshot JSONB` (frozen nightly math + components). Backfill: existing reservations get `rate_snapshot={legacy room_rate, version:legacy}`; BAR plan marked base.

### (c) Service-layer design

- `RateEngine::price(branch, plan, roomType, nights[], promo?, corporate?)` — pure integer pipeline: base → season multiplier → derivation → package split → promo/corporate → tax (via TaxService, Phase 1) → nightly lines. `AvailabilityService::reserve` freezes `rate_snapshot`; folio posts one transaction per component (room vs breakfast) so tax/commission split correctly; promo `uses_count` incremented with `lockForUpdate`, cap enforced.
- Failure: expired/inactive promo → 422; corporate account on-stop → falls back to BAR + flag.

### (d) Surface area

- `RatePlanController` extended (derivation + package builder), `PromoCodeController`, `CorporateAccountController`. Inertia `rates/Plans.vue` (derived-rate graph, package component editor), booking engine shows package line items.
- Reverb: `RatePlansChanged` (refreshes cached quotes + channel ARI).
- API: quote endpoint returns nightly + component breakdown.

### (e) Permissions

Extend `rate_plans`: `manage_packages`, `manage_promos`, `manage_corporate`. All → Branch GM, Global Admin; promos view → Front Desk.

### (f) Pest tests

- Derived −10% on 10,000 → 9,000 (integer, no float); package 12,000 splits room 9,000 + breakfast 3,000 with distinct tax lines frozen on folio.
- Promo cap 5: 6th concurrent redemption fails exactly once (lock test). Corporate rate applies only with linked account.
- Re-price after plan change leaves old reservation snapshot untouched.

### (g) Estimate & deferral risk

**5–7 eng-days.** Deferral risk: MEDIUM — sellable now, but every package sold before this posts wrong tax/commission and must be restated.

---

## 2.3 Deposits, Guarantees, Cancellation/No-Show Penalties, Auto-Release of Holds

### (a) Operational rationale

Non-guaranteed holds that never release block inventory forever; no-shows without penalties teach agents to overbook you. Without deposit schedules and auto-release, occupancy is fiction by 6pm.

### (b) Data model

```php
Schema::create('guarantee_policies', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->foreignId('rate_plan_id')->nullable()->constrained();
    $t->string('kind',32); // deposit_schedule | card_guarantee | company_guarantee
    $t->jsonb('rules'); // {deposit_bps, due_hours_before_arrival, cancel_free_until_hours, no_show_fee: first_night|percent}
    $t->timestamps();
});
```

Add `reservations.guarantee_status` (none|hold|guaranteed|forfeited), `deposit_due_minor`, `deposit_paid_minor`, `cancel_deadline_at`, `hold_expires_at` + index on `hold_expires_at`. Backfill: existing confirmed reservations → `guaranteed` if `amount_paid>0` else `hold` with 24h expiry (one-time grace, logged).

### (c) Service-layer design

- `GuaranteeService`: on reserve → compute deposit + deadlines, create pre-auth via PaymentService (Phase 1) or hold timer; `ReleaseHoldsJob` (every 10 min, queue `reservations`): `lockForUpdate` expired holds → cancel + release inventory (1.1) + broadcast; `applyNoShow` (called by night audit 1.4) posts penalty per policy (idempotent sub-key) and journals.
- Retry: penalty posting reuses night-audit idempotency; gateway timeout → hold stays, retried with backoff, never double-charged.

### (d) Surface area

- `GuaranteePolicyController`; reservation detail shows guarantee timeline + deposit progress. Inertia `reservations/GuaranteePanel.vue`.
- Reverb: `HoldReleased`, `DepositOverdue`, `NoShowPenaltyPosted`.
- API: guarantee status in reservation payload.

### (e) Permissions

Extend `reservations`: `waive_penalty`, `override_guarantee`. → Branch GM only (+ Global Admin). Front Desk can collect deposit, not waive.

### (f) Pest tests

- Hold expires → auto-cancelled, inventory freed, folio untouched. Re-run release → no-op.
- No-show with card guarantee → penalty posted once even under double night-audit run.
- Cancel before deadline → no fee; after → fee; waiver without permission → 403.

### (g) Estimate & deferral risk

**4–5 eng-days.** Deferral risk: HIGH — phantom occupancy directly causes walk-outs and OTA overbooking fines.

---

## 2.4 Revenue Analytics (ADR, RevPAR, TRevPAR, GOPPAR, pickup/pace, OTB vs budget, demand calendar, segment/source)

### (a) Operational rationale

Occupancy without ADR/RevPAR/pace is vanity. Without on-the-books vs budget and pickup, managers discover a soft weekend on Saturday — when discounting can no longer save it.

### (b) Data model

```php
Schema::create('revenue_snapshots', function (Blueprint $t) { // daily grain, rebuilt nightly
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->date('stay_date'); $t->date('snapshot_date');
    $t->bigInteger('rooms_available'); $t->bigInteger('rooms_sold');
    $t->bigInteger('room_revenue_minor'); $t->bigInteger('total_revenue_minor'); $t->bigInteger(' GOP_expense_minor')->default(0);
    $t->jsonb('by_segment')->nullable(); $t->jsonb('by_source')->nullable();
    $t->timestamps(); $t->unique(['branch_id','stay_date','snapshot_date']);
});
Schema::create('budgets', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->date('month');
    $t->bigInteger('room_nights_target'); $t->bigInteger('revenue_target_minor'); $t->timestamps();
    $t->unique(['branch_id','month']);
});
```

Backfill: rebuild snapshots from journal + inventory for last 90 days (`RebuildRevenueSnapshotsJob`, chunked, idempotent by unique key).

### (c) Service-layer design

- `RevenueAnalyticsService` — pure reads over snapshots + journal + trial balance (GOP from expense accounts, Phase 1 chart); `SnapshotRevenueJob` (nightly, queue `reports`, after audit): upserts today's snapshot; pace = `OTB(arrival_date) − OTB(arrival_date, snapshot−7d)`.
- All derived metrics computed in minor units, formatted client-side; cached 15 min per branch/date (Redis tags invalidated by audit close).

### (d) Surface area

- `RevenueReportController` (ADR/RevPAR/TRevPAR/GOPPAR, pickup & pace, OTB vs budget, demand calendar heatmap, segment/source tables). Inertia `analytics/Revenue.vue` + `DemandCalendar.vue` (reuse existing AnalyticsService views, add pace sparklines).
- Reverb: `RevenueSnapshotReady`.
- API: `GET /api/v1/reports/revenue?from&to&segment`.

### (e) Permissions

Uses existing `analytics.view/manage` + `reports.export`. Budget manage → Branch GM, Property Owner.

### (f) Pest tests

- Known dataset: ADR = revenue/sold, RevPAR = revenue/available, TRevPAR includes F&B, GOPPAR nets expenses — exact minor-unit assertions.
- Pace: snapshot today vs 7 days ago diff correct. Re-run snapshot job → same row, no dupes. Budget variance sign correct.

### (g) Estimate & deferral risk

**4–6 eng-days.** Deferral risk: MEDIUM — operable blind for a while, but pricing decisions without pace lose 5–15% RevPAR in season.

---

## 2.5 Channel Manager Reliability (idempotent ARI, retry/backoff, mapping, failure dashboard + replay, reconciliation, parity, virtual cards)

### (a) Operational rationale

Current channel sync is the likeliest production outage: a failed rate push silently leaves Booking.com selling ₦40k while the PMS sells ₦55k (parity breach + double-booking), and virtual-card charges with no handler become chargebacks.

### (b) Data model

```php
Schema::create('channel_mappings', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->string('channel',32); // bookingcom|expedia|...
    $t->foreignId('room_type_id')->constrained(); $t->foreignId('rate_plan_id')->constrained();
    $t->string('channel_room_code',64); $t->string('channel_rate_code',64);
    $t->timestamps(); $t->unique(['channel','channel_room_code','channel_rate_code']);
});
Schema::create('channel_messages', function (Blueprint $t) { // outbox for ARI push
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->string('channel',32);
    $t->string('kind',16); // ari|reservation|...
    $t->jsonb('payload'); $t->string('idempotency_key',64)->unique();
    $t->string('status',16); // queued|sending|acked|failed
    $t->integer('attempts')->default(0); $t->text('last_error')->nullable(); $t->timestamps();
    $t->index(['status','updated_at']);
});
Schema::create('channel_reconciliation_runs', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->date('stay_date');
    $t->jsonb('diff')->nullable(); $t->string('status',16); $t->timestamps();
});
```

Add `channel_reservations.virtual_card_token_id` (FK to payment_methods), `channel_reservations.replay_nonce` unique. Backfill: generate mappings from existing `channel_rates` where possible; unmatched flagged for manual mapping (dashboard).

### (c) Service-layer design

- `ChannelDriver` interface + per-OTA adapters; `PushAriJob` (queue `channel`, `ShouldBeUnique` on idempotency key, exponential backoff 1m→1h, 8 attempts): sends via mapping layer, records ack; `NightlyReconciliationJob`: pulls OTA inventory/reservations, diffs vs PMS, raises `ParityAlert` + auto-repush on drift.
- Inbound reservations: verify signature → idempotency on `(channel, channel_confirmation)` → `AvailabilityService::reserve` (real inventory!) → virtual card tokenized via PaymentService, never stored raw.
- Failure dashboard shows failed messages with replay button (same idempotency key → safe).

### (d) Surface area

- `ChannelMessageController` (failed queue + replay), `ChannelMappingController` (code mapper UI). Inertia `channels/Reliability.vue` (failure inbox, parity alerts, reconciliation diff viewer).
- Reverb: `ChannelPushFailed`, `ParityAlertRaised`, `ChannelReconciled`.
- API: inbound `POST /webhooks/channels/{channel}` with HMAC.

### (e) Permissions

Extend `channels`: `manage_mapping`, `replay`. Mapping → Branch GM; replay → Branch GM, Front Desk (daytime) — all logged.

### (f) Pest tests

- ARI push retried 3× (fake failing driver) → single OTA update (idempotency key asserted on fake), then acked.
- Replay of failed message → no duplicate rate change. Inbound dupe webhook → single reservation + single inventory decrement.
- Nightly recon with drifted OTA price → parity alert + auto-repush; virtual-card reservation tokenizes without storing PAN.

### (g) Estimate & deferral risk

**7–9 eng-days** (+ OTA sandbox certs). Deferral risk: CRITICAL — parity fines and oversells from silent sync failure are existential with OTAs.

---

## 2.6 OTA & Agent Commission Accrual and Payout Tracking

### (a) Operational rationale

Paying 15–20% OTA commission from memory (or on gross instead of net, or twice) bleeds margin monthly. Without accrual at checkout + payout tracking, finance cannot close the month or dispute wrong invoices.

### (b) Data model

```php
Schema::create('commission_rules', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->string('source',64); // bookingcom|agent:XYZ
    $t->integer('rate_bps'); // 1500 = 15%
    $t->string('base',16); // net_room|net_room_tax_excl|gross
    $t->boolean('active')->default(true); $t->timestamps();
});
Schema::create('commission_accruals', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->foreignId('reservation_id')->constrained();
    $t->string('source',64); $t->bigInteger('base_minor'); $t->bigInteger('amount_minor');
    $t->string('status',16); // accrued|invoiced|paid|disputed
    $t->string('idempotency_key',64)->unique(); $t->timestamps();
});
Schema::create('commission_payouts', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->string('source',64);
    $t->bigInteger('amount_minor'); $t->string('status',16); $t->string('reference',128)->nullable(); $t->timestamps();
});
```

Backfill: accrue for checked-out reservations in last 90 days with source≠direct (idempotent keys `commission.{reservation_id}`); older left unaccrued + disclosed.

### (c) Service-layer design

- `CommissionService::accrue(reservation)` — called by night-audit checkout step (idempotent); base computed from frozen rate snapshot per rule (never from live rates); payout links accruals→payout (many-to-many `commission_accrual_payout`); disputes freeze payout.
- Journal: accrual posts `Dr Commission Expense / Cr Commission Payable` (Phase 1 chart); payment clears payable.

### (d) Surface area

- `CommissionController` (accrual inbox, payout builder, dispute). Inertia `finance/Commissions.vue` (aging table, payout wizard, invoice export).
- Reverb: `CommissionAccrued`, `PayoutCompleted`.

### (e) Permissions

New group `commissions: ['view','manage','pay']`. View → Auditor, Branch GM; manage → Branch GM; pay → Property Owner + Branch GM (dual control optional).

### (f) Pest tests

- 100,000 minor net × 1500bps → 15,000 accrual once; re-run checkout → no dupe.
- Partial payout links correctly; over-payout → 422. Disputed accrual excluded from payout.
- Base uses snapshot: changing rule rate doesn't alter accrued rows.

### (g) Estimate & deferral risk

**3–4 eng-days.** Deferral risk: MEDIUM-HIGH — margin leakage monthly; disputes unwinnable without accrual trail after 60 days.

---

## Phase 2 build order

1. Restrictions (2.1) + rate depth (2.2) together (engine boundary). 2. Guarantees/holds (2.3). 3. Channel reliability (2.5). 4. Commissions (2.6). 5. Analytics (2.4, reads only — can parallelize).
