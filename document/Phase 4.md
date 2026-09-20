# Phase 4 — Guest, CRM and Compliance

> Depends on Phase 0 (idempotency, journal, observability) and Phase 1 (folio/tax). Handles NDPR (Nigeria Data Protection Regulation) + GDPR for OTA guests. Field-level encryption for IDs; additive only; purge is the only sanctioned delete path and is itself journaled.

---

## 4.1 Profiles: Dedup, Merge, Do-Not-Rent, ID/Passport Capture (OCR + field encryption)

### (a) Operational rationale
Duplicate guest rows ("John Doe" × 4) splinter history, break loyalty, and hide a banned guest rebooking under a typo. Unencrypted passport scans in R2 are a breach waiting for a headline.

### (b) Data model
```php
Schema::create('guest_merge_links', function (Blueprint $t) {
    $t->id(); $t->foreignId(' surviving_guest_id')->constrained('guests'); $t->foreignId('retired_guest_id')->constrained('guests');
    $t->foreignId('merged_by')->constrained('users'); $t->jsonb('field_choices'); $t->timestamps();
});
Schema::create('do_not_rent', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->nullable()->constrained(); // null = all branches
    $t->foreignId('guest_id')->nullable()->constrained(); $t->string('reason',256);
    $t->foreignId('listed_by')->constrained('users'); $t->timestamps();
});
Schema::create('guest_identity_documents', function (Blueprint $t) {
    $t->id(); $t->foreignId('guest_id')->constrained();
    $t->string('doc_type',16); // nin|passport|drivers
    $t->binary('doc_number_enc')->nullable(); // Laravel encrypted:externally stored ciphertext
    $t->string('scan_path',256)->nullable(); // R2, SSE-S3, private
    $t->jsonb('ocr_result')->nullable(); $t->string('ocr_status',16); $t->timestamps();
});
```
Add `guests.master_guest_id` (self-ref, null = master), `guests.dedup_hash` (soundex+phone+dob, indexed). Backfill: compute hashes, flag candidate dupes for review (never auto-merge historic); encrypt existing ID numbers in place via `EncryptIdsJob` (chunked, verifies decrypt round-trip per row).

### (c) Service-layer design
- `GuestDedupService::candidates/suggest/merge`: merge runs in one transaction — repoint reservations/folios/loyalty/consents to survivor, set `master_guest_id`, write merge link + activity log; DNR checked inside `AvailabilityService::reserve` (blocked with `DO_NOT_RENT` + override requires GM + reason).
- `IdentityService::capture/ocr`: upload → R2 private → queued `OcrDocumentJob` (queue `crm`, vendor API, PII-redacted logs) → encrypted field write via `Encrypted` cast (APP_KEY + per-branch key wrap in `config/gdpr.php`); scans served via signed URLs only.
- Failure: OCR down → manual entry allowed, flagged `ocr_pending`; merge conflict on active folio → blocked until checkout.

### (d) Surface area
- `GuestMergeController`, `DoNotRentController`, `IdentityDocumentController`. Inertia `guests/Profile.vue` (merge wizard with field picker, DNR banner, ID capture dropzone + OCR review).
- Reverb: `GuestMerged`, `DnrListed`, `OcrCompleted`.
- API: DNR check in booking engine (silent reject with generic message, no reason leak).

### (e) Permissions
Extend `guests`: `merge`, `manage_dnr`, `view_pii`. Merge/DNR → Branch GM (+ Global Admin); `view_pii` (unmask ID) → Branch GM, Front Desk (masked by default, full on click + logged).

### (f) Pest tests
- Merge two guests: reservations/folios/points all on survivor; re-run merge → no-op; retired profile redirects.
- DNR guest booking → 422; override with GM reason → allowed + flagged. Concurrent merges of same pair → one wins.
- Encryption: DB ciphertext ≠ plaintext; signed URL expires; OCR fake fills fields; PII never in logs (assert scrubbed).

### (g) Estimate & deferral risk
**5–6 eng-days** (+ OCR vendor). Deferral risk: HIGH — DNR miss is a safety incident; unencrypted IDs are a breach fine.

---

## 4.2 Statutory Guest Registration Reporting

### (a) Operational rationale
Nigerian hotels must render guest registers to authorities (immigration/police) on demand. A manual Excel at 2am during an inspection fails and invites sanctions.

### (b) Data model
```php
Schema::create('statutory_reports', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->string('kind',32); // immigration|police|tourism_board
    $t->date('period_from'); $t->date('period_to'); $t->string('status',16);
    $t->string('file_path',256)->nullable(); $t->foreignId('generated_by')->constrained('users'); $t->timestamps();
});
```
No guest schema change (reads registration cards + identity docs). Backfill: none.

### (c) Service-layer design
- `StatutoryReportService::generate(branch, kind, range)` — queued `GenerateStatutoryReportJob` (queue `reports`): pulls registration cards in business-date range (Phase 0), renders authority template (PDF + Excel via existing export stack), stores to R2, records hash for tamper evidence; re-run same range → same file (idempotent key `statutory.{kind}.{from}.{to}`).
- Failure: missing ID fields → row flagged `incomplete`, report still issues with exception annex (never silently dropped).

### (d) Surface area
- `StatutoryReportController` (generate/download/resubmit). Inertia `compliance/Statutory.vue` (range picker, completeness meter, download log).
- Reverb: `StatutoryReportReady/Failed`.

### (e) Permissions
New group `statutory: ['view','generate']`. Generate → Branch GM, Auditor; view → + Front Desk.

### (f) Pest tests
- Generate over known stays → row count + required columns exact; incomplete ID → annex row, still 200.
- Re-run same range → same hash, no dupe file. Concurrent generates → single file.

### (g) Estimate & deferral risk
**2–3 eng-days.** Deferral risk: MEDIUM — fine risk concentrated at inspection time; cheap insurance.

---

## 4.3 CRM & Loyalty (tiers, points, consent, segmentation, survey, review sentiment)

### (a) Operational rationale
Without tiers/points tied to consent, repeat guests are invisible and marketing spams people who opted out — burning direct bookings and breaking NDPR consent rules.

### (b) Data model
```php
Schema::create('loyalty_tiers', function (Blueprint $t) {
    $t->id(); $t->string('name',32); $t->integer('threshold_nights'); $t->integer('earn_bps'); $t->timestamps();
});
Schema::create('loyalty_accounts', function (Blueprint $t) {
    $t->id(); $t->foreignId('guest_id')->unique()->constrained(); $t->bigInteger('points')->default(0);
    $t->string('tier',32)->default('member'); $t->timestamps();
});
Schema::create('loyalty_ledger', function (Blueprint $t) { // append-only
    $t->id(); $t->foreignId('loyalty_account_id')->constrained(); $t->bigInteger('delta');
    $t->string('reason',64); $t->morphs('source'); $t->string('idempotency_key',64)->unique(); $t->timestamps();
});
Schema::create('consents', function (Blueprint $t) {
    $t->id(); $t->foreignId('guest_id')->constrained(); $t->string('channel',32); // email|sms|whatsapp
    $t->string('purpose',64); $t->boolean('granted'); $t->timestampTz('at'); $t->timestamps();
    $t->index(['guest_id','channel']);
});
Schema::create('post_stay_surveys', function (Blueprint $t) {
    $t->id(); $t->foreignId('reservation_id')->unique()->constrained(); $t->integer('nps')->nullable();
    $t->jsonb('answers')->nullable(); $t->float('sentiment')->nullable(); $t->timestamps();
});
```
Backfill: create accounts (0 points) for existing guests; award historic nights via one-shot job (idempotent keys `loyalty.backfill.{res}`), disclosed as backfill batch.

### (c) Service-layer design
- `LoyaltyService::earn/redeem/tierRecalc`: earn on checkout (night-audit hook, idempotent), redeem as folio credit via `FolioService` (points→minor at fixed rate, journaled); tier recalc nightly; consent gating in every outbound sender (no consent → no send, logged skip).
- `SentimentService`: survey + OTA review text → queued scoring (Phase 6 model hook, heuristic now), feeds audit flags on 1-star with charge dispute.
- Race: double checkout → single earn (unique key); concurrent redeem → balance lock.

### (d) Surface area
- `LoyaltyController`, `ConsentController`, `SurveyController`. Inertia `crm/Dashboard.vue` (segments, tier mix, consent coverage), guest portal points wallet, post-stay survey page.
- Reverb: `PointsEarned/Redeemed`, `TierChanged`, `SurveyReceived`.

### (e) Permissions
New group `crm: ['view','manage','redeem']`. Manage → Branch GM, Front Desk (enroll); redeem → Front Desk + Cashier; sentiment view → + Auditor.

### (f) Pest tests
- Checkout posts earn once; double checkout → no dupe. Redeem more than balance → 422. Tier upgrade at threshold exactly.
- No-consent guest excluded from campaign send list. Survey re-submit updates once; concurrent redeems → one wins.

### (g) Estimate & deferral risk
**5–7 eng-days.** Deferral risk: LOW-MEDIUM — revenue-safe to defer; consent enforcement is the non-deferrable slice (do it with 4.5).

---

## 4.4 Upsell Engine (early check-in, late checkout, upgrades)

### (a) Operational rationale
Late checkouts given free at the desk while the next guest waits are pure lost revenue. Without priced, inventory-aware offers, upsell depends on who's on shift.

### (b) Data model
```php
Schema::create('upsell_offers', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->string('kind',32); // early_checkin|late_checkout|upgrade
    $t->jsonb('rules'); // {fee_minor, cutoff_hour, inventory_guard}
    $t->boolean('active')->default(true); $t->timestamps();
});
Schema::create('upsell_acceptances', function (Blueprint $t) {
    $t->id(); $t->foreignId('reservation_id')->constrained(); $t->foreignId('upsell_offer_id')->constrained();
    $t->bigInteger('fee_minor'); $t->string('idempotency_key',64)->unique(); $t->timestamps();
});
```
Backfill: seed standard offers per branch (inactive until priced); no historic rows.

### (c) Service-layer design
- `UpsellService::quote/accept`: quote checks inventory guard (late checkout only if room unsold tonight; upgrade only if target type free — live `AvailabilityService` read); accept posts fee to folio window + journals + reissues key (late checkout extends key validity) — all one transaction + idempotency.
- Offer sent via guest portal/WhatsApp (Phase 6) with expiry; acceptance after expiry → 410.

### (d) Surface area
- `UpsellOfferController`, `UpsellAcceptanceController`. Inertia front-desk `UpsellPanel.vue` + guest portal offer cards.
- Reverb: `UpsellOffered/Accepted/Expired`.

### (e) Permissions
New group `upsell: ['view','manage','grant_free']`. Manage pricing → Branch GM; grant_free (waive fee) → Branch GM only, logged.

### (f) Pest tests
- Late checkout quoted when room free → fee posted once; when room sold → 422 `ROOM_SOLD_TONIGHT`. Double accept → single charge.
- Upgrade moves inventory + posts difference; re-run idempotent. Free grant without permission → 403.

### (g) Estimate & deferral risk
**3–4 eng-days.** Deferral risk: LOW — pure upside; deferring only costs ancillary revenue.

---

## 4.5 DSAR Workflow, Retention Schedules, Automated Purge, Consent Ledger

### (a) Operational rationale
"Delete my data" answered by editing rows breaks the books and still leaves backups. Without a DSAR workflow + retention + purge, NDPR/GDPR requests become ad-hoc destruction of financial evidence.

### (b) Data model
```php
Schema::create('dsar_requests', function (Blueprint $t) {
    $t->id(); $t->foreignId('guest_id')->constrained(); $t->string('kind',16); // access|erasure|portability
    $t->string('status',16); // open|fulfilled|rejected
    $t->jsonb('result')->nullable(); $t->timestamps();
});
Schema::create('retention_policies', function (Blueprint $t) {
    $t->id(); $t->string('data_class',64); // folio_lines|id_scans|marketing|...
    $t->integer('retain_days'); $t->string('action',16); // anonymize|purge
    $t->timestamps();
});
```
Reuse existing GDPR export/anonymize for fulfillment; extend with verifiable purge receipts. Financial journal + trial balances are NEVER purged (legal hold) — purge anonymizes guest PII linkage only, preserving amounts.

### (c) Service-layer design
- `DsarService::fulfill`: access/portability → existing export pipeline (queued, signed URL, expiry); erasure → checks open folios/legal hold → schedules `PurgeGuestJob` (queue `crm`): anonymizes guest row (name→`REDACTED-{id}`, contacts nulled, ID docs destroyed, scan deleted from R2), writes purge receipt + journal note (counts only, no PII); retention scheduler runs nightly, idempotent per `(guest, policy, date)`.
- Failure: backup copies noted in receipt with 30-day rotation disclosure (restores re-run purge — documented runbook).

### (d) Surface area
- `DsarController` (intake/track/fulfill), retention policy admin. Inertia `compliance/Dsar.vue` (SLA countdown, fulfillment bundle download).
- Reverb: `DsarReceived/Fulfilled`, `PurgeCompleted`.

### (e) Permissions
New group `privacy: ['view','fulfill','set_retention']`. Fulfill → Branch GM + Auditor (dual view); retention → Global Admin only. Every fulfillment activity-logged with actor.

### (f) Pest tests
- Erasure with open folio → 422 `OPEN_FOLIO`; after settlement → PII nulled, amounts intact, journal balanced.
- Retention run twice → same receipt, no double-purge. DSAR export contains consents + stays, excludes other guests (scope test).
- Backup-restore simulation: re-running purge after restore converges (idempotent).

### (g) Estimate & deferral risk
**4–5 eng-days.** Deferral risk: HIGH — regulatory clock starts at first request; fines + forced process change mid-operation.

---

## Phase 4 build order
1. Identity/DNR + privacy (4.1 core + 4.5) — legal safety first. 2. Statutory (4.2). 3. Loyalty/consent (4.3). 4. Upsell (4.4, needs folio + inventory — can parallelize after 4.1).
