# SaaS Platform — Features & Modules (with Attached Roles)

> Detailed catalogue of the multi-branch hotel management platform as a SaaS product:
> feature/module inventory, the application roles attached to each module, the permission
> keys backing them, the entitlement keys gating them, and the current implementation status.

- Platform: Herd Multi-Branch Hotel System (Laravel 13 / PHP 8.4, Vue 3 + Inertia, PostgreSQL, Redis/Valkey, Reverb, Pest)
- SaaS control plane: plans, subscriptions, entitlements, billing, dunning, tenant overrides
- Concierge/AI: WhatsApp Cloud API (Meta direct) + multi-provider AI adapter stack
- Last updated: 2026-11-10 (WhatsApp concierge Phase 5/6 implementation session)

## 1. How to read this document

Every module entry below maps to:

1. **Module** — the bounded capability (e.g. reservations, concierge, billing).
2. **Attached roles** — the Spatie roles that operate it, in their operational scope
   (platform / property / branch / front desk / back office).
3. **Permission keys** — the `group.action` Spatie permissions used (see `database/seeders/RoleSeeder.php`).
4. **Entitlement keys** — SaaS feature flags consumed via `App\Services\EntitlementService`
   and the `entitled` middleware (`app/Http/Middleware/EnsureEntitled.php`).
5. **Status** — Implemented / In progress / Pending / Gated / Deferred.

## 2. Platform scope

- **Tenancy model:** branches (multi-branch) under a property/tenant hierarchy, with branch
  scoping enforced by `BranchAccess` / `EnsuresBranchAccess`, `SetBranchContext`,
  `ApiBranchScope`, Sanctum token branch scope and the `branch.scope` middleware.
- **SaaS control plane modules:** plans (`starter | growth | professional | enterprise`),
  subscriptions (`trialing | active | past_due | grace | restricted | cancelled`),
  entitlements/overrides, billing invoices, dunning, tenant management.
- **Monetised feature keys:** `pms.reservations`, `pms.front_desk`, `finance.folios`,
  `pms.group_booking`, `wifi.vouchers`, `wifi.tier_upsell`, `locks.issue`, `pos.sales`,
  `channels.sync`, `events.bookings`, `api.public`, `channels.concierge`, plus provider-gated
  keys such as `locks.provider.<provider>` and SaaS billing events such as
  `charge.success`, `charge.failed`, `refund.issued`.

## 3. Role catalogue

All roles use Spatie permission guard `web`. Scope boundaries: **Global Admin** (platform-wide),
**Property Owner** (property-level), **Branch GM / Front Desk / Housekeeper / Cashier / …**
(branch-level operational roles).

| Role | Scope | Primary module focus | Key permission groups |
|---|---|---|---|
| **Global Admin** | Platform-wide | SaaS control plane, branches, users/roles, settings, system health, all operational modules | All permissions via `syncPermissions(Permission::all())`; SaaS abilities `saas.view`, `admin.system`, `developers.index` |
| **Property Owner** | Property / group | High-level oversight: reservations, rooms, guests, housekeeping, maintenance, folios, ledgers, analytics, yield, rates, locks, menus, inventory, transfers, channels, concierge, CRS, finance exports | See seeded permission set (e.g. `reservations.*`, `rooms.*`, `guests.*`, `analytics.*`, `yield_rules.*`, `rate_plans.*`, `door_lock.*`, `menu_items.*`, `inventory.*`, `transfers.*`, `channels.*`, `concierge.view`, `crs.*`, `city_ledger.*`, `laundry.*`, `pos.*`, `kds.*`, `business_date.*`, `journal.*`, `availability.*`, `accounting.*`, `commissions.*`, `privacy.*`, `statutory.*`, `crm.*`, `upsell.*`, rate restriction/season modules, promos, corporate accounts, `api.*`, `accounting_export.*`) |
| **Branch GM** | Branch | Branch operations: availability/overbooking, cashier shifts, reservations ops, guests, housekeeping, maintenance, privacy, statutory, CRM, upsell, concierge, folios, locks, telecom, hotspot, menus, rates, yield, outlets, inventory, transfers, laundry, POS, KDS, idempotency, journal, system health | See seeded permission set (e.g. `availability.override_overbook`, `availability.manage_overbook`, `cashier.*`, `groups.*`, `tax.*`, `fiscal.*`, `accounting.*`, `reservations.*`, `rooms.*`, `guests.*`, `housekeeping.*`, `maintenance.*`, `privacy.*`, `statutory.*`, `crm.*`, `upsell.*`, `concierge.*`, `folios.*`, `door_lock.*`, `telecom.*`, `hotspot.*`, `menu_items.*`, `rate_plans.*`, `inventory.*`, `transfers.*`, `laundry.*`, `pos.*`, `kds.*`, `idempotency.*`, `journal.*`, `system_health.*`, `availability.*`) |
| **Front Desk** | Branch | Front-office operations: reservations, check-in/out, guests, housekeeping assignment, maintenance view, folios, POS, concierge, channels replay, groups/CRM/upsell, cashier shifts, rate controls, promos, corporate accounts, hotspot | See seeded permission set (e.g. `reservations.{view,create,update,checkin,checkout,move_room}`, `rooms.{view,update_status}`, `guests.*`, `housekeeping.assign`, `maintenance.view`, `folios.{view,manage,split,transfer}`, `payments.charge`, `menu_items.view`, `inventory.view`, `pos.{view,manage}`, `laundry.{view,manage}`, `business_date.view`, `availability.view`, `forecast.view`, `channels.replay`, `concierge.{view,reply,manage_kb}`, `groups.*`, `statutory.view`, `crm.*`, `upsell.*`, `cashier.{open_shift,close_shift}`, `rate_restrictions.*`, `rate_seasons.*`, `promo_codes.*`, `corporate_accounts.*`, `hotspot.{view,issue,revoke}`) |
| **Housekeeper** | Branch | Housekeeping & maintenance execution | `rooms.view`, `rooms.update_status`, `housekeeping.{view,manage,assign,inspect,manage_lost_found}`, `maintenance.{view,manage}` |
| **Kitchen Staff** | Branch | KDS / menu / inventory execution | `kds.{view,manage}`, `menu_items.{view,manage}`, `inventory.view`, `groups.view` |
| **Laundry Attendant** | Branch | Laundry & room-state support | `laundry.{view,manage}`, `rooms.view` |
| **Cashier** | Branch | POS/folio/payment/cashier-shift operations & finance reporting | `pos.*`, `folios.*`, `payments.{charge,refund}`, `crm.redeem`, `cashier.{open_shift,close_shift}`, `reports.{view,export}`, `business_date.view`, `hotspot.view` |
| **Auditor** | Branch / platform | Night audit, anomaly review, financial & compliance reporting | `audit.*`, `reports.*`, `analytics.*`, `accounting_export.*`, `forecast.view`, `api.view`, `idempotency.view`, `journal.*`, `system_health.view`, `tax.*`, `fiscal.*`, `accounting.*`, `commissions.view`, `inventory.view_costing`, `privacy.*`, `statutory.*`, `cashier.approve_void` |

## 4. Module catalogue with attached roles

### 4.1 Platform, tenancy & access administration

| Module | Purpose | Attached roles | Permission keys | Status |
|---|---|---|---|---|
| Branches | Multi-branch management & scoping | Global Admin, Property Owner, Branch GM | `branches.view`, `branches.manage` | Implemented |
| Users & roles | Staff accounts, Spatie roles, branch assignment | Global Admin, Property Owner | `users.{view,create,update,delete}`, `roles.{view,assign}` | Implemented |
| Settings | Per-module settings incl. concierge AI overrides | Global Admin, Property Owner | `settings.{view,update,manage}` | Implemented |
| Business date | Single business date governance | Branch GM, Front Desk, Cashier, Auditor, Branch GM | `business_date.{view,close}` | Implemented |
| System health | Platform/branch health checks & DR drills | Global Admin, Branch GM, Auditor | `system_health.{view,run_dr_drill}` | Implemented |
| Privacy / retention | Guest privacy, DNR, retention | Global Admin, Branch GM, Auditor, Property Owner | `privacy.{view,fulfill,set_retention}` | Implemented (DPIA sign-off pending) |

### 4.2 Reservations, guests & front office

| Module | Purpose | Attached roles | Permission keys | Status |
|---|---|---|---|---|
| Reservations & holds | Booking CRUD, check-in/out, waivers, moves, cancellations, holds | Front Desk, Branch GM, Cashier (payment-side), Auditor (reports) | `reservations.{view,create,update,checkin,checkout,cancel,delete,waive_penalty,override_guarantee,move_room}`, `idempotency.view` | Implemented |
| Guests & CRM | Guest profiles, PII/DNR, merge, CRM, upsell, loyalty/redeem | Front Desk, Branch GM, Cashier, Auditor, Property Owner | `guests.{view,create,update,manage,merge,manage_dnr,view_pii}`, `crm.{view,manage,redeem}`, `upsell.{view,manage,grant_free}` | Implemented |
| Groups & CRS | Group bookings, BEO, city ledgers, CRS | Front Desk, Branch GM, Property Owner, Kitchen Staff | `groups.{view,manage,manage_beo}`, `crs.{view,manage}`, `city_ledger.*`, `tape_chart.view` | Implemented |

### 4.3 Rooms, housekeeping & availability

| Module | Purpose | Attached roles | Permission keys | Status |
|---|---|---|---|---|
| Rooms / room types / inventory | Room CRUD, inventory, housekeeping, maintenance | Housekeeper, Front Desk, Branch GM, Property Owner | `rooms.{view,update_status,manage}`, `inventory.*` | Implemented |
| Availability & overbooking | Rate/overbooking/overrides/yield/rate plans | Branch GM, Property Owner, Front Desk, Auditor | `availability.{view,override_overbook,manage_overbook}`, `yield_rules.*`, `rate_overrides.*`, `rate_plans.*`, `rate_seasons.*`, `rate_restrictions.*` | Implemented |
| Dynamic pricing | AI-assisted price approval | Property Owner, Branch GM | `yield_rules.approve_ai_price`, `rate_plans.approve_ai_price` | Implemented (AI price approval wired) |

### 4.4 Folios, billing, night audit & finance

| Module | Purpose | Attached roles | Permission keys | Status |
|---|---|---|---|---|
| Folios & transactions | Folio splitting/transfers/routing, transactions, POS charges | Front Desk, Branch GM, Cashier, Auditor | `folios.{view,manage,split,transfer,manage_routing}`, `journal.{view,export}`, `transactions` domain | Implemented |
| Night audit & anomalies | Night audit runs, retries, anomaly rules | Branch GM, Auditor, Front Desk, Cashier | `audit.{view,manage,run_night_audit,retry_night_audit,manage_anomaly_rules,confirm_anomaly}` | Implemented |
| Tax & statutory / e-invoicing | Tax engine, fiscal, statutory export/e-mission | Branch GM, Auditor, Property Owner | `tax.*`, `fiscal.*`, `statutory.*` | Implemented (live e-invoice provider pending) |
| Payments & cashiering | Paystack/Flutterwave gateways, charges/refunds, cashier shifts/voids, commissions | Cashier, Branch GM, Auditor, Front Desk | `payments.{charge,refund,manage_drivers}`, `cashier.{open_shift,close_shift,approve_void}`, `commissions.*` | Implemented (gateway onboarding pending) |
| SaaS billing (separate module) | Plans/subscriptions/invoices/dunning | Global Admin, Auditor, Property Owner | `saas.view`, billing domain | Implemented (real settlement sandbox-gated) |

### 4.5 Revenue analytics, forecasting, reporting & sentiment

| Module | Purpose | Attached roles | Permission keys | Status |
|---|---|---|---|---|
| Analytics & revenue reports | Occupancy, ADR, RevPAR, revenue summaries, trends, room-type performance, KPI summaries | Branch GM, Property Owner, Auditor, Cashier | `analytics.{view,manage,export_warehouse}`, `reports.{view,export}` | Implemented |
| Forecasting & NL reporting | Forecasts, natural-language reporting | Branch GM, Auditor, Front Desk, Cashier | `forecast.view`, NL reporting service | Implemented |
| Sentiment & surveys | Sentiment scoring, post-stay surveys | Auditor, Front Desk | `surveys` / sentiment domain | Implemented (legal retention sign-off pending) |

### 4.6 Channels, concierge AI & customer messaging (SaaS-facing)

| Module | Purpose | Attached roles | Permission keys | Entitlement keys | Status |
|---|---|---|---|---|---|
| Channel integrations | OTA/Hotel channel sync, replay, mapping; drivers `LogChannelDriver`, `FailingChannelDriver`; OTA deferred | Branch GM, Front Desk, Property Owner, Global Admin | `channels.{view,manage,manage_mapping,replay}` | `channels.sync` | Implemented (OTA deferred) |
| WhatsApp Cloud API | Meta BSP-free webhook (verify, signature, branch resolution, inbound + delivery jobs) | Front Desk, Branch GM, Property Owner, Global Admin | `channels.*` | SaaS secret keys `whatsapp.access_token`, `whatsapp.app_secret` | Implemented (Meta credentials pending go-live) |
| Concierge / AI | AI manager, providers, drivers, intents, reply suggester, KB, consent marketing, handoff | Front Desk, Branch GM, Property Owner (view), Global Admin | `concierge.{view,reply,manage_kb}`, `channels.*` | `channels.concierge` (Professional + Enterprise) | Core implemented; delivery hardening/suggested replies in progress; go-live pending |

### 4.7 F&B, POS, KDS, inventory, transfers

| Module | Purpose | Attached roles | Permission keys | Status |
|---|---|---|---|---|
| F&B / POS / KDS / inventory / transfers | POS floor/pricing, KDS, menus, outlets, inventory, transfers, F&B | Kitchen Staff, Cashier, Front Desk, Branch GM, Property Owner | `pos.*`, `kds.*`, `menu_items.*`, `outlets.*`, `inventory.*`, `transfers.*`, `kot` | Implemented |

### 4.8 Physical access, telecom, hotspot, edge, RADIUS

| Module | Purpose | Attached roles | Permission keys | Status |
|---|---|---|---|---|
| Locks / telecom / hotspot / edge / RADIUS | Door locks (AssaAbloy/Dormakaba/Duowin/Salto/TTLock + edge agent), telecom, Wi-Fi/hotspot, FreeRADIUS/MikroTik | Branch GM, Front Desk, Property Owner, Auditor | `door_lock.*`, `telecom.*`, `hotspot.*`, `edge.*` | Implemented / lab-gated |

### 4.9 Security, privacy, integrations & system

| Module | Purpose | Attached roles | Permission keys | Status |
|---|---|---|---|---|
| Security, audit, integrations, public & staff API, webhooks | MFA, RBAC, API keys/usage, webhooks, idempotency, security | Global Admin, Auditor, Developer (`developers.index`) | `api.*`, `developers.index`, `idempotency.*`, `audit.*`, `privacy.*`, `system_health.*` | Implemented (several sub-items pending) |

## 5. SaaS control plane module (with roles)

| Module | Function | Roles | Keys | Status |
|---|---|---|---|---|
| Plans & entitlement catalog | `Plan`, `PlanEntitlement`, `TenantEntitlementOverride`, feature keys + `limits.<area>.max` caps | Global Admin, Property Owner, Branch GM (via entitlements) | entitlement keys (see §2) | Implemented |
| Subscriptions & billing | `Subscription`, `BillingInvoice`, `BillingService`, `DunningService`, `Tenant` | Global Admin, Auditor, Property Owner | `saas.view`, billing events | Implemented (real settlement sandbox-gated) |
| Entitlement enforcement | `EntitlementService`, `EnsureEntitled` middleware, 403 `"Your subscription does not include [{$key}]."` | Global Admin (manage), ops roles (consume) | entitlement keys | Implemented |

## 6. Concierge module deep map (module-by-module, with roles)

1. **Concierge service** — `ConciergeService::handleInbound(Branch $branch, array $message, array $contact = [])` plus agent workflow (`replyAsAgent`, `takeOver`, `handBack`, `close`, `markRead`, `applyAction`, `rejectAction`, `sendMarketing`, `handOff(thread, reason)`). Entitlement: `channels.concierge`.
2. **AI layer** — `ConciergeAiManager::provider(?Branch): ConciergeAiProvider`, `isConfigured(?Branch): bool`; branch overrides via `settings.concierge.ai` (`provider`, `model`, `negative_keywords`) with global fallback; providers `OpenAiCompatibleConciergeProvider`, `AnthropicConciergeProvider`; contract `ConciergeAiProvider::complete(...): ConciergeAiResponse`.
3. **Intent engine** — `ConciergeDriver::interpret(...): ConciergeIntent`; drivers `HeuristicConciergeDriver`, `ModelConciergeDriver`; intents `faq|rate|booking|late_checkout|maintenance|confirm|decline|human|greeting|unknown` with `needsHuman()`.
4. **Agent assist** — `ConciergeReplySuggester::suggest(ConciergeThread $thread): ?string`.
5. **Knowledge & consent** — `ConciergeKbEntry`; marketing consent/opt-out regex; human-handoff reasons (`delivery_failed`, `complaint_sentiment`, `content_flagged`, `not_entitled`, …).
6. **WhatsApp delivery** — `WhatsAppWebhookController` (verify + signed inbound), `WhatsAppCloudApiService::findBranchByPhoneNumberId`, `ProcessWhatsAppWebhookJob`, delivery-status job → failed delivery handoff.

Module → role mapping: Front Desk / Branch GM / Property Owner operate concierge UI; Front Desk + Branch GM hold `concierge.reply`; Property Owner/Global Admin hold `concierge.manage_kb`; entitlement `channels.concierge` gates the module SaaS-wide.

## 7. Consolidated module → role matrix

| Module area | Global Admin | Property Owner | Branch GM | Front Desk | Housekeeper | Kitchen Staff | Laundry | Cashier | Auditor |
|---|---|---|---|---|---|---|---|---|---|
| SaaS/billing/entitlements | ✅ | ✅ | via plan | via plan | — | — | — | — | — |
| Branches/users/roles/settings | ✅ | ✅ | ✅ | — | — | — | — | — | — |
| Reservations/guests/groups | ✅ | ✅ | ✅ | ✅ | — | — | — | — | ✅ |
| Rooms/housekeeping/availability | ✅ | ✅ | ✅ | ✅ | ✅ | — | ✅ | — | — |
| Folios/finance/night audit/tax/payments | ✅ | ✅ | ✅ | ✅ | — | — | — | ✅ | ✅ |
| Analytics/forecast/reporting | ✅ | ✅ | ✅ | ✅ | — | — | — | ✅ | ✅ |
| Channels/concierge/AI | ✅ | ✅ | ✅ | ✅ | — | — | — | — | — |
| F&B/POS/KDS/inventory | ✅ | ✅ | ✅ | ✅ | — | ✅ | — | ✅ | — |
| Locks/telecom/hotspot/edge/RADIUS | ✅ | ✅ | ✅ | — | — | — | — | — | — |
| Security/privacy/audit/integrations | ✅ | ✅ | ✅ | — | — | — | — | — | ✅ |

## 8. Status summary & open gates

- **SaaS layer:** implemented (plans, entitlements, billing, dunning); real settlement/onboarding gates remain (G-6, G-7).
- **Concierge core:** implemented (manager, providers, drivers, intents, suggester, consent, handoff, delivery jobs); Phase 5 (Meta credentials) and Phase 6.3 (go-live) pending external inputs.
- **SaaS concierge analytics:** requirements captured (deflection rate, revenue impact, sentiment reporting) — implementation pending (Phase 6.2.1).
- **Deferred:** OTA channels, physical POS, passkeys hardware, live e-invoicing provider, physical lock hardware.
