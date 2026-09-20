# Phase 3 — Operational Depth

> Depends on Phase 0 (idempotency, versions, journal, business date) and Phase 1 (availability, folio windows, tax, shifts). All money in integer minor units. Additive only.

---

## 3.1 Group Blocks (pickup, cut-off, rooming-list import, BEO/function space)

### (a) Operational rationale
Weddings and conferences booked as 30 individual reservations lose the block, over-release on cut-off day, and banquet charges land on no folio. Without blocks + BEOs, groups are chaos and attrition is unenforceable.

### (b) Data model
```php
Schema::create('group_blocks', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->string('name',128);
    $t->string('code',32); $t->date('cutoff_date'); $t->integer('attrition_pct')->default(0);
    $t->string('status',16); // tentative|definite|cancelled|completed
    $t->foreignId('master_folio_id')->nullable()->constrained('folios'); $t->timestamps();
    $t->unique(['branch_id','code']);
});
Schema::create('group_block_nights', function (Blueprint $t) {
    $t->id(); $t->foreignId('group_block_id')->constrained()->cascadeOnDelete();
    $t->foreignId('room_type_id')->constrained(); $t->date('stay_date'); $t->integer('blocked'); $t->integer('picked_up')->default(0);
    $t->timestamps(); $t->unique(['group_block_id','room_type_id','stay_date']);
});
Schema::create('function_spaces', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->string('name',64); $t->integer('capacity')->nullable(); $t->timestamps();
});
Schema::create('banquet_event_orders', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->foreignId('group_block_id')->nullable()->constrained();
    $t->foreignId('function_space_id')->constrained(); $t->date('event_date');
    $t->jsonb('schedule'); // setup/start/end/teardown
    $t->bigInteger('agreed_total_minor'); $t->string('status',16); $t->timestamps();
});
```
Add `reservations.group_block_id` nullable FK. Backfill: existing `is_group_booking + group_id` strings mapped to one block per distinct `group_id` (code = legacy id); pickup = linked reservation count.

### (c) Service-layer design
- `GroupBlockService::create/hold/pickup/release`: block nights decrement `room_type_inventory.blocked` (Phase 1) under row locks; pickup converts a blocked night to a reservation (`picked_up++`, `blocked--`, `sold++` atomically); `CutoffJob` (nightly, queue `reservations`): past-cutoff unpicked nights auto-released (idempotent, logged). BEO postings go to master folio window `banquet` via `FolioService`.
- Rooming-list import reuses Excel import pipeline (queued, idempotent per row key `block.{code}.row.{n}`).

### (d) Surface area
- `GroupBlockController`, `BeoController`, `FunctionSpaceController`. Inertia `groups/Block.vue` (pickup grid, cut-off countdown, rooming-list dropzone), `groups/Beo.vue` (printable BEO).
- Reverb: `BlockPickupChanged`, `BlockCutoffReleased`, `BeoUpdated`.

### (e) Permissions
New group `groups: ['view','manage','manage_beo']`. Manage → Front Desk (pickup), Branch GM (block terms); BEO → Branch GM + Kitchen Staff (view).

### (f) Pest tests
- Block 10, pickup 6, cut-off releases 4 → inventory freed, master folio intact. Re-run cut-off → no-op.
- Concurrent pickups for last blocked night → one wins. Rooming-list re-import → idempotent (no dupes).
- BEO post dupla10064 → single master charge + journal pair.

### (g) Estimate & deferral risk
**5–6 eng-days.** Deferral risk: MEDIUM — groups workable manually below ~5 concurrent blocks; breaks at wedding season.

---

## 3.2 Housekeeping Depth (mobile view, credit allocation, turn-down, minibar, lost & found, inspections, OOO vs OOS)

### (a) Operational rationale
A single `rooms.status` flag cannot distinguish "dirty, 20 credits" from "out-of-order, plumbing" — so supervisors assign 3 suites to one attendant, turn-down is forgotten, and broken rooms get sold.

### (b) Data model
```php
Schema::create('housekeeping_tasks', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->foreignId('room_id')->constrained();
    $t->string('kind',16); // checkout_clean|stayover|turndown|inspection|minibar_check
    $t->integer('credits')->default(10); $t->foreignId('assignee_id')->nullable()->constrained('users');
    $t->string('status',16); // open|in_progress|done|failed_inspection
    $t->integer('inspection_score')->nullable(); $t->timestamps();
    $t->index(['branch_id','status']); $t->index(['assignee_id','status']);
});
Schema::create('minibar_postings', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->foreignId('room_id')->constrained();
    $t->foreignId('folio_id')->nullable()->constrained(); $t->jsonb('items'); $t->bigInteger('total_minor');
    $t->string('idempotency_key',64)->unique(); $t->timestamps();
});
Schema::create('lost_found_items', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->foreignId('room_id')->nullable()->constrained();
    $t->string('description',256); $t->string('status',16); $t->jsonb('claim')->nullable(); $t->timestamps();
});
```
Add `rooms.condition` (`clean|dirty|inspected|ooo|oos`, default dirty), `rooms.condition_reason`, `rooms.version` (Phase 0). Distinguish: OOS = sellable after touch-up (counts in inventory total), OOO = removed from `room_type_inventory.total_rooms` for the date range (via dated `room_outs` table: room/date/reason). Backfill: map legacy status → condition; create checkout_clean tasks for all occupied rooms.

### (c) Service-layer design
- `HousekeepingService::assign/autoAllocate`: credit-based (sum credits per attendant ≤ shift cap, `lockForUpdate` on attendant day-row); `complete` requires inspection for checkout cleans (score < threshold → `failed_inspection` + re-task); minibar post → `FolioService::postToWindow(incidentals)` + journal, idempotent.
- OOO dates adjust inventory totals (calls AvailabilityService admin path); OOS does not. All transitions activity-logged.

### (d) Surface area
- `HousekeepingTaskController`, `MinibarController`, `LostFoundController`. Inertia `housekeeping/Mobile.vue` (big-touch, offline-tolerant list, photo upload to R2), `housekeeping/Board.vue` (credit load per attendant), inspection score widget.
- Reverb: `RoomConditionChanged`, `HkTaskAssigned/Completed`, `InspectionFailed`.

### (e) Permissions
Extend `housekeeping`: `assign`, `inspect`, `manage_lost_found`. Assign/inspect → Branch GM, Front Desk (assign); attendants get `housekeeping.manage` (complete own). Minibar post → Front Desk, Housekeeper (own tasks).

### (f) Pest tests
- Allocation caps credits: over-assign → 422. Concurrent completes → single done. Minibar double-submit (same key) → one folio charge.
- OOO range removes inventory (availability drops), OOS does not. Failed inspection reopens task; re-run complete idempotent.

### (g) Estimate & deferral risk
**5–7 eng-days** (+ mobile QA). Deferral risk: MEDIUM — works on paper until 60%+ occupancy, then clean-room promises break.

---

## 3.3 Preventive Maintenance (asset register, SLAs, work orders)

### (a) Operational rationale
Reactive tickets mean ACs die during sold-out weekends. Without assets, schedules and SLAs, maintenance is unplannable and repeat failures are invisible.

### (b) Data model
```php
Schema::create('assets', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->string('name',128);
    $t->string('category',32); $t->foreignId('room_id')->nullable()->constrained();
    $t->date('installed_on')->nullable(); $t->jsonb('pm_schedule')->nullable(); // {every_days, checklist}
    $t->timestamps();
});
Schema::create('work_orders', function (Blueprint $t) { // extends maintenance_tickets, additive
    $t->id(); $t->foreignId('asset_id')->nullable()->constrained(); $t->string('priority',16);
    $t->timestampTz('sla_due_at')->nullable(); $t->timestampTz('responded_at')->nullable(); $t->timestampTz('resolved_at')->nullable();
    $t->timestamps();
});
```
Backfill: create assets from distinct ticket subjects (best-effort, `unclassified`), link by room; SLA clocks start at migration (no retroactive breach).

### (c) Service-layer design
- `MaintenanceService::raise/assign/slaClock`: SLA per (category, priority) from branch config; `PmSchedulerJob` (daily, queue `maintenance`): generates PM work orders due (idempotent key `pm.{asset}.{date}`); escalation → Reverb + AuditFlag on breach; resolution posts cost (parts from inventory, Phase 3.5) to expense journal.
- Failure: SSE-safe — scheduler re-run creates zero dupes.

### (d) Surface area
- `AssetController`, `WorkOrderController` (extends MaintenanceController). Inertia `maintenance/Assets.vue`, SLA breach inbox, PM calendar.
- Reverb: `WorkOrderCreated/Assigned/SlaBreached/Resolved`.

### (e) Permissions
Extend `maintenance`: `manage_assets`, `manage_sla`. → Branch GM; technicians (Housekeeper role or new) get `maintenance.manage`.

### (f) Pest tests
- PM scheduler run twice → one WO per asset. SLA breach detected after due; re-check idempotent.
- Concurrent assign → single assignee. Resolution with parts decrements inventory + journals cost.

### (g) Estimate & deferral risk
**3–4 eng-days.** Deferral risk: LOW-MEDIUM — deferrable until asset count hurts; schedule now, detail later.

---

## 3.4 Mid-Stay Room Moves (preserve charges, key credentials)

### (a) Operational rationale
Moving a guest for a leaking AC currently orphans charges on the old room, breaks the door key, and confuses housekeeping — the folio splits across rooms and the guest gets locked out.

### (b) Data model
Add `reservation_moves` table: `{reservation_id, from_room_id, to_room_id, moved_at, moved_by, reason, key_reissued BOOLEAN}`. Add `room_inventory.to_room_id` support (Phase 1 holds table gains move chain). No amount changes — charges stay on folio windows (Phase 1).

### (c) Service-layer design
- `RoomMoveService::move(res, toRoom, reason)` — one transaction: `lockForUpdate` reservation + both rooms + inventory nights; validate target free for remaining nights (AvailabilityService); rewrite future `room_inventory` rows; reissue door key via lock gateway ( compensating: key failure rolls back move); broadcast; housekeeping auto-tasks (dirty old, inspect new).
- Door-lock failure → 502 with move rolled back + `KeyReissueFailed` flag (never half-moved).

### (d) Surface area
- `ReservationMoveController` (`POST /reservations/{id}/move`). Inertia `MoveDialog.vue` (availability-checked room picker, reason codes).
- Reverb: `GuestMoved` (folio + housekeeping + KDS channels), `KeyReissued`.

### (e) Permissions
Extend `reservations`: `move_room`. → Front Desk, Branch GM. Key reissue uses existing `door_lock.manage`.

### (f) Pest tests
- Move preserves folio totals exactly (before/after sums equal); future nights point to new room; past nights unchanged.
- Move to occupied room → 422. Key-gateway failure (fake) → 502, no DB change. Concurrent moves → one wins (version check).
- Re-run with same idempotency key → single move row.

### (g) Estimate & deferral risk
**2–3 eng-days.** Deferral risk: MEDIUM — infrequent but high-anger when it breaks (lockout at midnight).

---

## 3.5 F&B Costing (recipes, depletion, POs, GRN, suppliers, valuation, theoretical vs actual)

### (a) Operational rationale
Posting POS revenue without depleting ingredients and valuing stock means food cost % is unknown and theft/waste hides. Without POs/GRNs, suppliers get paid from memory.

### (b) Data model
```php
Schema::create('suppliers', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->string('name',128); $t->timestamps();
});
Schema::create('purchase_orders', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->foreignId('supplier_id')->constrained();
    $t->jsonb('lines'); // {inventory_item_id, qty, unit_cost_minor}
    $t->string('status',16); // draft|sent|partial|received|cancelled
    $t->timestamps();
});
Schema::create('goods_receipts', function (Blueprint $t) {
    $t->id(); $t->foreignId('purchase_order_id')->constrained(); $t->jsonb('lines_received');
    $t->timestamps();
});
```
Extend `inventory_items`: `valuation_method` (weighted_avg), `unit_cost_minor`. Extend `recipes`: `yield_qty`, component lines already exist — add per-component `wastage_bps`. Backfill: seed unit costs 0 + `unvalued` flag; valuation starts accruing from first GRN (no restatement).

### (c) Service-layer design
- `CostingService`: POS/KOT fire → `depleteRecipe` (decrement ingredients by qty×yield, `lockForUpdate`, journal `Dr COGS / Cr Inventory` at weighted cost); GRN → weighted-average cost update + journal `Dr Inventory / Cr GRNI`; `VarianceReport` compares theoretical (recipes×sales) vs actual (counts) → waste/theft flag.
- Stock-out blocks firing with 409 + suggested substitute (never negative stock; no silent oversell of ingredients).

### (d) Surface area
- `SupplierController`, `PurchaseOrderController`, `GoodsReceiptController`, costing dashboard. Inertia `inventory/Costing.vue` (variance table, valuation cards), PO/GRN wizards.
- Reverb: `StockLow`, `PurchaseOrderReceived`, `CostVarianceFlagged`.

### (e) Permissions
Extend `inventory`: `manage_suppliers`, `manage_pos_grn`, `view_costing`. Suppliers/PO → Branch GM; costing view → + Auditor, Property Owner.

### (f) Pest tests
- Sell 2 burgers → flour/patty decremented exactly; concurrent sales never drive negative.
- GRN updates weighted cost: (10@100 + 10@200)/20 = 150 asserted in minor units. Theoretical vs actual variance math exact.
- PO receive twice (same key) → single GRN + single journal pair.

### (g) Estimate & deferral risk
**5–7 eng-days.** Deferral risk: MEDIUM — margin invisible until then; fraud window open but bounded by shift counts.

---

## 3.6 POS Completeness (floor plan, split bills, course firing, modifiers, happy hour, offline queue)

### (a) Operational rationale
Without table plans, split bills and course control, a 10-top's bill is unwieldy, courses fire together, and a network blip loses the lunch rush. Happy-hour mispricing causes instant social-media disputes.

### (b) Data model
```php
Schema::create('dining_tables', function (Blueprint $t) {
    $t->id(); $t->foreignId('outlet_id')->constrained(); $t->string('code',16); $t->string('shape',16);
    $t->integer('seats'); $t->jsonb('position'); // x,y for plan
    $t->timestamps(); $t->unique(['outlet_id','code']);
});
Schema::create('pos_modifiers', function (Blueprint $t) {
    $t->id(); $t->foreignId('branch_id')->constrained(); $t->string('name',64);
    $t->bigInteger('price_delta_minor'); $t->boolean('active')->default(true); $t->timestamps();
});
Schema::create('happy_hours', function (Blueprint $t) {
    $t->id(); $t->foreignId('outlet_id')->constrained(); $t->string('cron_window',64); // day + time range
    $t->integer('discount_bps'); $t->jsonb('applies_to')->nullable(); $t->boolean('active')->default(true); $t->timestamps();
});
```
Add `pos_charges`: `dining_table_id`, `course` (starter|main|dessert), `fired_at`, `offline_nonce` unique, `parent_split_id` (self-ref for splits). Backfill: existing charges get `course=main`, no price change.

### (c) Service-layer design
- `PosService::openTab/fireCourse/splitBill/applyModifiers`: course firing creates per-course KOTs (Phase KDS) with `fired_at`; splits divide by seat/item/percent (integer remainder rule, cf. 1.2); happy-hour evaluated server-side at fire time and frozen on the charge (snapshot); offline queue: tablet/POS stores `offline_nonce`, replays via idempotent `pos.offline.{nonce}` on reconnect (queue `pos`, ordered per outlet).
- Failure: replay storm → dedupe by nonce; clock skew on happy-hour boundary → server time wins, logged.

### (d) Surface area
- `DiningTableController`, `PosModifierController`, `HappyHourController`; POS terminal page gains floor-plan canvas (`FloorPlan.vue`), split dialog, course-fire buttons, offline banner with queued count.
- Reverb: `TableSeated`, `CourseFired`, `BillSplit`, `OfflineQueueSynced`.

### (e) Permissions
Extend `pos`: `manage_floor`, `manage_pricing`, `comp`. Floor → Branch GM; comps/voids follow 1.5 supervisor rules; happy-hour manage → Branch GM.

### (f) Pest tests
- Split 10,000 three ways → 3334/3333/3333 + sums exact. Course fire creates 2 KOTs in order; happy-hour boundary (one minute before/after) priced correctly + frozen.
- Offline: 5 queued charges replay → 5 posted once; duplicate replay → no dupes. Concurrent splits → single split set.

### (g) Estimate & deferral risk
**6–8 eng-days.** Deferral risk: MEDIUM — dine-in works without it; fails at banquet scale and during network outages.

---

## Phase 3 build order
1. Group blocks + BEO (3.1). 2. Room moves (3.4, small, unlocks HK). 3. Housekeeping depth (3.2). 4. POS completeness (3.6). 5. Costing (3.5, needs POS fires). 6. Maintenance PM (3.3, parallelizable).
