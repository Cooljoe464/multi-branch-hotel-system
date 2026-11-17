# HospitalityOS — Specification v1.1

*Closure amendments and build baseline · Amends specification v1.0 · 26 October 2026 · Status: complete for build start*

## 1. Closure statement

This document closes specification v1.0. Together they form baseline v1.1. Where the two conflict, this document prevails; v1.0 sections not listed in §13 remain in force exactly as written.

**Scope is unchanged and nothing is deferred.** Every module in v1.0 Phases 1–5, plus the public developer API (§4) and NRS e-invoicing (§3), is in scope for a six-month delivery window with 15 developers (§12).

**What “complete” means here.** The spec is complete when (a) every requirement needed to start building has a decision, (b) every decision has acceptance criteria, and (c) anything that depends on a third party or a professional sign-off is a named gate with an owner and a date (§12.3), not an open question. “Complete” does not mean that external facts are verified: tax treatment, NRS procedures, vendor SDKs and retention periods carry gates and are marked *to confirm*.

**Provenance legend.** *Owner* = decided by the product owner during review. *Default* = proposed by the spec author where no answer was given; changeable only by ADR.

## 2. Decision register — all 20 open decisions closed

| ID | Resolution | Source |
| --- | --- | --- |
| OD-01 | A tenant is the contracting and billing legal entity. A group with several properties is one tenant with many properties. Separate legal entities under one brand are separate tenants, optionally linked by a portfolio for roll-up reports with explicit consent. | Default |
| OD-02 | Each hotel is its own merchant of record. Paystack and Flutterwave are both supported and each hotel chooses its default. SaaS billing uses a separate platform-owned connection. No marketplace settlement and no platform custody of guest funds (§7). | Owner |
| OD-03 | Replaced by §3 (Nigeria Tax Act 2025, Nigeria Tax Administration Act 2025, NRS e-invoicing). Tax adviser sign-off is gate G-1. | Owner + spec |
| OD-04 | WatermelonDB is retained as a fixed constraint. Supported browsers: latest two major versions of Chrome and Edge, and Safari on the current and previous iOS major. Offline budget 50 MB per device plus a 20 MB encrypted ID-image queue. Initial values, tuned after the Month 1 proof of concept (§6.5). | Owner + default |
| OD-05 | Any MikroTik is accepted if it passes the NAS Compatibility Test (§8.1). Supported RouterOS: 7 stable and 6.49 long-term. Mode: HotSpot with RADIUS (PAP/CHAP); MAC authentication optional. | Owner + default |
| OD-06 | The hotel edge node is in scope as a managed add-on. Reference hardware and support split are in §8.2. | Default |
| OD-07 | Device limit means simultaneous active sessions per voucher. A registered-device (MAC) cap is optional and off by default because of MAC randomization. | Default |
| OD-08 | Quota types: per stay (default), per purchase, and daily (resets at property-local midnight). No rolling windows. Counters come from cumulative accounting including gigaword counters; displayed values round down to the MB. | Default |
| OD-09 | OTA connectivity is partner-first through an accredited channel-manager partner chosen under gate G-4. Direct Booking.com connection is not available while new-provider onboarding is paused. Manual import is the fallback. | Default |
| OD-10 | Five lock families are in scope (§9) with a qualification gate per family and a manual-record mode for anything not yet certified. | Owner |
| OD-11 | Walk-in check-in with a provisional record is allowed offline, with the safeguards in §6. | Owner |
| OD-12 | Trial 30 days. Past due 7 days. Grace 14 days. Restricted state blocks new reservations and premium modules but always allows checkout, folio access, exports, and never revokes keys or Wi-Fi for in-house guests. Cancelled tenants get a 90-day export window. All values configurable by agreement. | Default |
| OD-13 | The v1.0 §4.2 catalog is the launch catalog, subject to pilot feedback. Hotels set their own room and rate prices; the platform bills only its own subscription, module and metered fees. Quarterly price review for USD-linked costs (hosting, SMS, WhatsApp). | Owner + default |
| OD-14 | Retention periods are set by counsel (gate G-5); engineering delivers per-tenant, per-data-class retention settings with purge jobs. No data-residency mandate: hosting region is chosen for latency to Nigeria and cost. NDPA cross-border transfer basis and a subprocessor register are maintained regardless. | Owner + default |
| OD-15 | Default chart-of-accounts template for Nigerian hospitality, hotel-editable. Room revenue is recognized per occupied night at night audit. Deposits are liabilities until the stay. Rounding rule in §3.2. CSV export first; accounting connectors after per-vendor verification (v1.0 §11.6). | Default |
| OD-16 | One Vue monorepo with three entry points (staff PWA, guest and booking, super-admin), built and deployed independently, sharing one generated typed API client. | Default |
| OD-17 | PostgreSQL read models first. Move to a warehouse when any trigger is hit: p95 report latency above 5 s on an 8-week window after indexing and materialization; a reporting table above 50 million rows; or a cross-tenant analytics requirement. | Default |
| OD-18 | Support tiers: business hours (Mon–Sat, 08:00–18:00 WAT) for base plans; extended hours for mid plans; 24/7 Sev-1 on-call for the top plan and managed edge. First-response targets: Sev-1 1 h, Sev-2 4 h, Sev-3 one business day. Commercial team to ratify. | Default |
| OD-19 | CSV import kit for rooms, rates, guests, future reservations and open folio balances. No historical ledger import by default. Legacy Wi-Fi moves through RADIUS shadow migration (v1.0 §10.9). Cutover at a night-audit boundary after a 7-day parallel run. | Default |
| OD-20 | Edge protocol is versioned. The cloud supports the current and two previous edge-agent minor versions and refuses unknown future versions with a clear error. Rollout rings: canary properties, then 10%, then all, inside tenant maintenance windows. Version matrix runs in CI contract tests. | Default |

## 3. Tax and e-invoicing (replaces v1.0 §14.3)

### 3.1 Legal baseline

Nigeria’s VAT rules changed on 1 January 2026. The VAT Act Cap V1 (2004) regime and its section references (13A, 17, 25, 15) are withdrawn from this specification. The baseline is the Nigeria Tax Act 2025 (charging provisions) and the Nigeria Tax Administration Act 2025 (administration), with the Nigeria Revenue Service (NRS, successor to FIRS) as the authority. Every rule below is configuration: effective-dated and versioned in `tax_rule_versions`, never a constant in code. Every rule is subject to gate G-1 (tax adviser sign-off).

### 3.2 Engine rules

| ID | Rule |
| --- | --- |
| TR-1 | Output VAT is charged at the standard rate (currently 7.5%) on lodging, conferencing, food and beverage and other taxable supplies. The rate lives in the rule table. |
| TR-2 | Each tenant has a VAT status: registered, exempt small business, or opted-in. Small-business status needs a turnover test and a fixed-assets test, and excludes professional-service providers. Thresholds are configuration (commonly reported as ₦100m turnover and ₦250m fixed assets, with some sources citing ₦50m). Status changes are effective-dated and store an evidence snapshot. Exempt tenants issue invoices without VAT and produce no VAT workpapers. |
| TR-3 | Input VAT is recorded on supplier invoices for goods, services and fixed assets used for taxable supplies. Mixed-use costs carry an apportionment ratio. Credits apply only to supplies from 1 January 2026, are claimable within five years, and where e-invoicing applies require a validated supplier invoice (to confirm under G-1). |
| TR-4 | State or local hospitality levies (for example Lagos’s 5% consumption tax) are optional property-level rules, off by default. The base is the bill excluding VAT, shown as a separate invoice line with its own ledger account and remittance report. Enabling one requires recorded adviser approval and a legal-basis note, because courts have held the federal VAT regime displaces such levies while some states continue to collect them. |
| TR-5 | Service charge is a per-property setting, default taxable (inside the VAT base), shown as its own line, until the adviser rules otherwise. |
| TR-6 | Rounding: tax is computed per invoice line, rounded half-up to the kobo; invoice totals are the sum of rounded lines. Fixtures are mandatory. |
| TR-7 | Credit and debit notes reference the original invoice. Posted invoices are never edited. |
| TR-8 | The engine produces a monthly VAT workpaper per property (output, input, net) and a data export for return preparation. It does not file returns. The due date is configuration (default 21st of the following month, to confirm). |
| TR-9 | Penalty amounts and interest are not encoded. |

### 3.3 E-invoicing through the NRS Merchant Buyer Solution (MBS)

| ID | Requirement |
| --- | --- |
| EI-1 | Define an `EInvoiceProvider` adapter: `capabilities()`, `submit(invoice, idempotencyKey)`, `status(reference)`, `issueCreditNote()`, `reconcile(since)`. NRS requires transmission through accredited System Integrators or Access Point Providers, so launch with one accredited partner. The platform does not seek its own accreditation in this window; the adapter keeps the provider replaceable. |
| EI-2 | States: \`draft → ready → submitted → cleared |
| EI-3 | Store the invoice reference number, digital stamp or QR data, provider response hash and clear timestamps. These records are immutable. |
| EI-4 | Corporate bookings capture buyer name, address and TIN with format validation. An invalid TIN blocks B2B invoice issuance. |
| EI-5 | Fiscal invoices are server-only. Offline checkout prints a provisional folio statement marked “Not a tax invoice”; the fiscal invoice is generated on sync. |
| EI-6 | On provider outage: queue with exponential retry, alert at a defined age, hotels keep operating, and held B2B invoices escalate to the front-office manager. |
| EI-7 | Provider diligence (gate G-2): proof of accreditation in the NRS directory, an NDPA data processing agreement, per-invoice pricing, SLA, sandbox, outage behavior and exit or export terms. |
| EI-8 | Timeline: provider sandbox integrated by Month 4 and pilot-ready by Month 6. This matches NRS’s published schedule for taxpayers under ₦1bn (engagement January 2027, pilot April 2027, go-live July 2027, enforcement January 2028), to be rechecked against NRS notices. Hotels above ₦1bn are flagged for earlier activation. |

### 3.4 The platform’s own invoices

SaaS invoices use the same engine and the separate platform ledger. The platform company’s own VAT status, including whether software services count as professional services, is a G-1 question. When the platform company falls in scope, its invoices also go through EI-1.

## 4. Public developer API (new v1.0 §12.7)

Hotels build their own booking frontends against this platform’s backend, so a public developer surface is a first-class product.

### 4.1 Surface and credentials

- A separate surface, `/public/v1`, serves hotel-built frontends. The staff API `/api/v1` is never exposed to it.
- Two key types per property and environment (test and live). Publishable keys (`pk_…`) are safe in browsers and limited to availability, quotes and holds. Secret keys (`sk_…`) are server-to-server for confirm, read and cancel.
- Secrets are stored hashed, shown once, rotatable with an overlap window, revocable, optionally IP-allowlisted, and scoped per key.
- CORS uses an exact-origin allowlist per publishable key. No wildcards.

### 4.2 Booking flow

Flow: quote → hold → payment → confirm.

| Endpoint | Purpose |
| --- | --- |
| `GET /properties/{id}`, `GET /room-types` | Property and room content |
| `GET /availability`, `POST /quotes` | Availability and priced quotes with full price and tax breakdown |
| `POST /holds` | Temporary inventory hold |
| `POST /payments/intents` | Hosted gateway checkout using the hotel’s connection (§7) |
| `POST /reservations` | Confirm after verified payment or per hotel policy |
| `GET /reservations/{ref}`, `POST /reservations/{ref}/cancel` | Token-authorised lookup and policy-checked cancellation |

Rules:

- Hotels own all prices through their rate plans. A quote is bound to the rate and tax version and expires after 15 minutes. A hold expires after 10 minutes (configurable 5–30).
- Holds consume the same `availability_day` rows as every other source, tagged `public_api`. Caps apply per session, per IP and per key, with an optional per-property channel share cap.
- The API never receives card data; payment is by hosted gateway checkout.
- Reservation lookup needs a signed access token or an email one-time code. There is no guest enumeration, and personal data appears only in token-authorised responses.
- Marketing consent is captured separately from booking consent, with a link to the NDPA privacy notice.

### 4.3 Webhooks

- Events: `reservation.confirmed`, `reservation.modified`, `reservation.cancelled`, `payment.verified`, `hold.expired`.
- Signature: HMAC-SHA256 over timestamp plus body, 5-minute tolerance, per-endpoint secret with rotation overlap.
- Delivery: retries with exponential backoff for up to 72 hours, a delivery log, and manual replay. Ordering uses `aggregate_version`.
- Destination safety: HTTPS only. Private, loopback and link-local ranges are blocked and re-checked after DNS resolution to prevent SSRF.

### 4.4 Contract and operations

- `Idempotency-Key` is required on every POST. Errors use RFC 9457 problem details.
- OpenAPI 3.1 with a generated TypeScript SDK. A per-tenant sandbox provides synthetic inventory and gateway test mode.
- Additive changes stay within v1. Breaking changes ship as v2 with at least 12 months of overlap and `Deprecation` and `Sunset` headers.
- Rate limits per key return 429 with `Retry-After`. Usage is metered against `limits.api.daily_calls`.
- Entitlement keys: `api.public`, `limits.api.keys.max`, `limits.webhooks.endpoints.max`.
- Key creation, rotation and revocation are audited. A developer portal provides documentation, changelog, key management and delivery logs.

## 5. Inventory, night audit and tenancy amendments

### 5.1 Configurable overbooking allowance (ADR-009)

- Add `overbook_allowance` to `availability_day`, default 0, with the invariant `allocated + blocked <= capacity + overbook_allowance`.
- Allowance is set per property, room type and date range by users holding `inventory.overbook.manage` (MFA required) and is fully audited. It cannot be lowered below current allocation.
- Allowance is consumable only by the sources listed in `overbook_sources`, default `front_desk`. OTA and public API sources see physical capacity unless the hotel explicitly adds them.
- When arrivals exceed physical rooms at check-in, the stay is flagged `relocation_required`. The relocation workflow records the partner property, transport, compensation folio items and approver, and appears in reports.

### 5.2 One maintenance rule

`capacity` is the count of physical rooms of that type and changes only when rooms are added or retired. Maintenance, out-of-order rooms and room blocks increase `blocked`. This is the only accounting rule; it prevents double subtraction.

### 5.3 Night-audit idempotency key

The unique key becomes `(property_id, business_date, stay_id, folio_id, charge_code, line_seq)`. A room move is charged to the room assigned at business-date rollover. Split folios and multiple same-type lines no longer collide.

### 5.4 Tenant isolation (ADR-014)

Row-level security is mandatory from day one on every tenant-owned table. The application role is not the table owner and has no `BYPASSRLS`. Tables use `FORCE ROW LEVEL SECURITY`. Tenant context is set with `SET LOCAL` inside each transaction and is tested with connection pooling and queue workers. Migrations run under a separate owner role.

## 6. Offline provisional check-in (extends v1.0 §9)

### 6.1 Allowed offline

| Action | Condition |
| --- | --- |
| Provisional walk-in check-in | Arrival today, into a room the device cache shows vacant and clean |
| Provisional check-out | Prints a non-fiscal folio statement |
| Pending charges | Recorded as pending, never posted |
| Cash receipts | Recorded as provisional with an offline receipt number |
| Room status, housekeeping, maintenance, guest notes | Normal offline task rules |

### 6.2 Never offline

New future reservations, confirming card or bank payments, refunds, fiscal invoices, rate or tax changes, entitlement or plan changes, master-key creation, user management, and viewing other guests’ ID documents.

### 6.3 Safeguards

- A per-device cap on provisional check-ins per outage (default 20). Manager overrides use signed, single-use override tokens issued while online and valid for 12 hours by default.
- Offline receipt numbers come from reserved per-device ranges.
- ID metadata is stored locally. An ID image may be held in an encrypted queue (WebCrypto, 20 MB cap) that expires 72 hours after the last successful sync attempt; after that a supervisor must recapture.
- Local data is purged on logout, tenant switch and remote revoke (effective at next contact).
- Door keys for provisional stays, where a local encoder bridge is reachable, are limited to one night and badged provisional; they are revoked or extended on sync. Wi-Fi vouchers come from the edge’s pre-provisioned pool.
- Provisional stays are visibly badged and use the v1.0 §16.4 status language. Provisional never shows as paid, verified or fiscal.

### 6.4 Sync and conflict rules

The server is the authority for room assignment. If two devices assign the same room, the first command processed wins and the other becomes `conflict` in a supervisor queue; that stay is not confirmed checked-in until resolved, and a relocation record is created if needed. Entitlements are re-evaluated on sync, and commands for features no longer entitled are rejected with a visible reason.

### 6.5 WatermelonDB proof of concept (Month 1, fixed technology)

Because the technology is fixed, the proof of concept is about hardening, not selection. Pass criteria: survives reload, browser restart and two open tabs; migrates schema with pending commands intact (AC-017); detects storage eviction; syncs 1,000 queued commands; works on the supported browsers. Mitigations: pin the version, wrap it behind a thin repository and sync interface, and keep offline collections few. If a defect is found, the response is a patch or fork under an ADR.

## 7. Payments: dual-gateway model (ADR-010)

- A `payment_connections` record exists per tenant and optionally per property: provider (Paystack or Flutterwave), mode, secret and webhook-secret references, `is_default`, status. One default per property; a hotel may enable the other as fallback.
- Webhook URL is `/webhooks/{provider}/{connection_id}`. The signature is verified with that connection’s secret, the event is verified against the provider API, and the merchant reference must belong to the connection. Otherwise the event is rejected and alerted.
- Funds are credited only after provider verification and idempotently by provider reference.
- Secrets live in a secret manager with envelope encryption, are never logged, and are rotatable.
- The hotel is merchant of record and funds settle to its account. The platform never holds guest funds. SaaS billing uses a separate platform-owned connection and ledger.
- Bank-transfer and DVA reconciliation is enabled per merchant where the provider supports it (confirm per merchant). Public API payments always use hosted checkout.

## 8. Wi-Fi, MikroTik and edge

### 8.1 NAS Compatibility Test

An automated suite runs against any MikroTik before activation: Access-Request accept and reject, rate-limit attribute applied, session timeout, accounting start, interim and stop, CoA, Disconnect, and missing-Stop behavior after reboot. Results: **Certified** (all pass), **Compatible** (CoA fails; fallback is re-authentication), **Unsupported**. The suite records model, RouterOS version and result, reruns after upgrades, and the CoA port is configured explicitly.

### 8.2 Edge node reference and support split

- Reference hardware: x86-64 mini-PC, at least 4 GB RAM and 128 GB SSD with full-disk encryption, Linux LTS, FreeRADIUS and the edge agent, on a UPS that also covers the router.
- The platform owns remote monitoring, updates and edge software. The hotel owns power, UPS, LAN and physical access unless it buys an on-site plan.
- Stolen-device runbook: revoke the device certificate, rotate secrets, and wipe on next contact. Edge secrets are scoped per property.

## 9. Door locks (ADR-015)

| Family | Integration path |
| --- | --- |
| TTLock / TTHotel Systems | Cloud API adapter |
| Douwin | Local encoder bridge |
| Datacomm Express / ProUSB / Lord | Local encoder bridge |
| ZKTeco | SDK or network adapter |
| Hyphen Solutions (Alpha, Golden Edge, HS series) | Local encoder bridge, qualified model by model |

All five families are in scope. A family ships when its qualification passes: written SDK or protocol documentation and licensing, a demo unit tested end to end, documented revocation and offline behavior, and the card technology identified. Delivery order is TTLock, Douwin, Datacomm, ZKTeco, Hyphen, each behind its own entitlement (`locks.provider.<name>`). Any model not yet certified at a hotel runs in **manual-record mode**: staff encode cards in the vendor’s software and the platform records stay, room, validity window and operator, labelled “recorded by staff, not hardware-verified”. Master and emergency keys are never stored in the platform.

## 10. Stack and infrastructure baseline (ADR-016)

- PostgreSQL 18, exact minor version pinned, in development and production. Check the image notes for the data-directory layout when reusing the v1.0 compose volume line.
- Queue, cache and sessions on Valkey 8.x (BSD-licensed, Redis-compatible) unless a Redis 8 licence review passes. Laravel Horizon compatibility is verified in the Month 1 spike. Queue and cache use separate instances; the queue instance uses `noeviction` and AOF.
- All container images pinned to a minor version, with a monthly refresh. Lockfiles required; new dependency versions younger than 14 days are not adopted.
- Laravel 13 on PHP 8.3 or later. Schedule the next major upgrade after launch, inside the security-support window.
- CI gains: threat-model sign-off before Month 3, an external penetration test before pilot go-live, and automated accessibility checks (WCAG 2.2 AA target).
- OTA messaging must not assume a guest phone number: Booking.com stopped passing guest phone numbers to connectivity providers from 28 September 2026. Capture phone at check-in and use platform messaging for OTA guests.

## 11. Added acceptance criteria (extends v1.0 §17.2)

| ID | Scenario | Acceptance criterion |
| --- | --- | --- |
| AC-033 | Pooled connection and queue worker serve tenants A then B | No tenant A rows visible to tenant B |
| AC-034 | Overbook allowance with front-desk-only source | Front desk can exceed physical capacity within allowance; public API and OTA get a conflict at physical capacity |
| AC-035 | Arrivals exceed physical rooms | Stay flagged for relocation; relocation record and compensation item created |
| AC-036 | Room move at 01:30 across business-date rollover | One charge per stay-night, on the room at rollover |
| AC-037 | Split folio, two same-type lines | No uniqueness collision; no duplicate posting on retry |
| AC-038 | Two offline devices assign the same room | Second becomes `conflict`; no double occupancy confirmed |
| AC-039 | Provisional check-in then checkout offline | Never shown as paid, verified or fiscal; statement says “Not a tax invoice” |
| AC-040 | Offline manager override token reused or expired | Rejected |
| AC-041 | Logout, tenant switch or remote revoke | Local data purged; unsynced work reported |
| AC-042 | Feature de-entitled while command queued offline | Rejected on sync with visible reason |
| AC-043 | Valid webhook signature for connection A with merchant reference of connection B | Rejected and alerted |
| AC-044 | E-invoice provider outage | B2B invoices held, B2C reporting retried, no duplicate reference |
| AC-045 | Credit note issued | References original; original unchanged |
| AC-046 | Mixed taxable and exempt cost | Input VAT apportioned by ratio |
| AC-047 | Tenant VAT status changed with effective date | Earlier invoices unchanged |
| AC-048 | Hold-exhaustion attack on public API | Per-key, IP and session caps hold; legitimate bookings still succeed |
| AC-049 | Publishable key used from non-allowlisted origin | CORS denied |
| AC-050 | Webhook replay with stale timestamp; secret rotation | Stale rejected; both secrets valid during overlap |
| AC-051 | Webhook URL resolving to private address | Rejected, including after DNS re-resolution |
| AC-052 | Lock in manual-record mode | Never labelled hardware-verified |
| AC-053 | MikroTik without working CoA | Classified Compatible; re-authentication fallback works |
| AC-054 | OTA reservation without phone number | No SMS attempted; platform messaging used |
| AC-055 | Edge agent two versions behind, then three | Accepted, then refused with clear error |
| AC-056 | Subscription restricted with guests in house | Checkout, folio access, keys and Wi-Fi continue |

## 12. Delivery plan: all scope in six months

Assumption: start 2 November 2026; Month 1 is November 2026 and Month 6 is April 2027.

### 12.1 Streams

| Stream | Devs M1–M3 | Devs M4–M6 | Scope |
| --- | --- | --- | --- |
| S1 Core PMS and finance | 4 | 3 | Inventory, reservations, stays, folios, ledger, night audit, tax engine, reporting |
| S2 Platform and API | 3 | 2 | Tenancy and RLS, IAM and MFA, entitlements, SaaS billing, public developer API |
| S3 Front office and PWA | 3 | 3 | Staff app, offline sync, housekeeping, maintenance, operations, guest portal |
| S4 Network and edge | 2 | 2 | RADIUS, edge agent, CoA, compatibility suite, shadow migration |
| S5 Integrations | 2 | 2 | Gateways, e-invoicing, messaging, OTA partner, locks, accounting export |
| S6 Revenue modules | 1 | 3 | POS, stock, events, CRM and loyalty, corporate credit, SSO, analytics |

The 15 developers exclude QA automation, DevOps and SRE, UX, a product owner, the tax adviser and the security tester. Those roles are required in addition.

### 12.2 Timeline

| Month | Milestones |
| --- | --- |
| M1 Nov | Spikes and ADRs; tenancy and RLS; entitlement skeleton; inventory model; CI; MikroTik lab; vendor SDK requests; e-invoice partner and OTA partner selection; tax adviser engaged |
| M2 Dec | Reservations, stays, folio ledger, tax engine v1, both gateways in sandbox, staff portal shell, public API design freeze, cloud RADIUS authentication |
| M3 Jan | Night audit, payment reconciliation, check-in and checkout, housekeeping, offline sync platform, public API alpha, Wi-Fi vouchers and accounting. **Gate: core flow end to end in staging** |
| M4 Feb | Offline provisional check-in, SaaS billing and dunning, public API beta with sandbox and docs, CoA and edge alpha, TTLock and Douwin adapters, messaging, e-invoice sandbox integrated, POS and stock under way |
| M5 Mar | Edge release candidate, remaining lock adapters, OTA partner sync, events, CRM, accounting export, multi-property reporting, SSO, security review, load tests |
| M6 Apr | Hardening, penetration test, restore drill, pilot journeys A–D, pilot go-live, NRS pilot readiness, scope freeze |

Release rule: every module ships behind its entitlement and is switched on for a hotel only when its gate passes. The v1.0 §17.4 release-blocking defects apply to every module.

### 12.3 External gates

| ID | Gate | Owner | Needed by |
| --- | --- | --- | --- |
| G-1 | Tax adviser sign-off on §3 rules and the platform company’s own VAT status | Finance lead | End of M2 |
| G-2 | E-invoice partner contract, accreditation proof and sandbox | Product owner | End of M2 |
| G-3 | Written SDK or protocol documentation and demo unit for each lock family; per-family go or no-go | Hardware lead | Requested M1; decided M3 |
| G-4 | OTA channel partner selected and contracted | Partnerships | End of M2 |
| G-5 | NDPA retention schedule, data processing agreements, subprocessor register, and any Wi-Fi traffic-data retention duties | Legal | End of M2 |
| G-6 | Gateway merchant onboarding validated with pilot hotels | Payments lead | End of M2 |
| G-7 | Pilot hotels signed, with turnover band and installed network and lock hardware recorded | Product owner | End of M1 |
| G-8 | External penetration tester booked | Security lead | Booked M4; tested M5–M6 |
| G-9 | Edge hardware and UPS procured | Network lead | End of M3 |

### 12.4 Early-warning triggers and slip order

This schedule has no float. Triggers: the core gate not passed by end of Month 3; the e-invoice sandbox not integrated by end of Month 4; the first two lock adapters not passing the hardware lab by end of Month 5. If a trigger fires, the product owner applies this pre-agreed slip order, which delays enablement but never removes scope: (1) accounting connectors beyond CSV and SSO; (2) CRM, loyalty and corporate credit; (3) lock families after the first two; (4) OTA beyond the partner’s first connection; (5) depth of POS and events. Never slipped: tenancy and RLS, the ledger, tax and e-invoicing, offline safety, and the security gates.

## 13. Changes to v1.0

| v1.0 section | Change |
| --- | --- |
| §4.2 pricing | Unchanged; add the quarterly USD-cost review (OD-13) |
| §9 offline | Extended by §6 |
| §10.8 edge | Confirmed in scope; reference hardware in §8.2 |
| §11.2 payments | Replaced by §7 |
| §11.3 OTA, §11.5 messaging | Partner-first; no phone-number assumption |
| §11.4 locks | Replaced by §9 |
| §12 APIs | New §12.7 = this document’s §4 |
| §13.4 availability | Allowance and one maintenance rule (§5) |
| §14.3 tax | Replaced by §3 |
| §15.2 compose | PostgreSQL 18, Valkey, pinned tags (§10) |
| §17.2 acceptance | Add AC-033 to AC-056 |
| §18 phases | Replaced by §12 |
| §20 decisions | All OD-01 to OD-20 closed (§2); new ADR-009 to ADR-016 |
| Final architecture recommendation | Unchanged |

New ADRs: ADR-009 availability model; ADR-010 payment connections; ADR-011 e-invoice provider adapter; ADR-012 offline provisional check-in; ADR-013 public API surface; ADR-014 tenant isolation; ADR-015 lock vendor qualification; ADR-016 stack versions.

## 14. Verification notes and sign-off

**Checked on 26 October 2026 against public sources:** Laravel 13 requirements and support dates (laravel.com docs); PostgreSQL 18 as current stable and 19 in release-candidate stage (postgresql.org news, vendor lifecycle posts); Redis licensing and Valkey (The Register, Percona); Nigeria Tax Act 2025 VAT changes (Forvis Mazars, Legal500, TaxStreem); NRS e-invoicing timeline (VAT Update, Thomson Reuters, BusinessDay); SI and APP accreditation (BusinessDay, Daily Trust, Vanguard); Lagos 5% consumption tax and court positions (Mondaq, BusinessDay); Booking.com onboarding pause (connect.booking.com) and the 28 September 2026 guest-phone change (Smoobu); WatermelonDB web adapter (watermelondb.dev, RxDB comparison, which is a competitor).

**Not verified and therefore gated:** lock vendor SDK and API terms for all five families; the NRS technical schema and invoice reference format; penalties and filing deadlines under the new regime; statutory retention periods; whether Wi-Fi traffic-data duties apply to hotels; RouterOS behaviour per version (lab); Paystack and Flutterwave eligibility per hotel; and the commercial and support figures marked Default.

**Completion statement.** Baseline v1.1 is complete for build start: all 20 open decisions are closed, every amendment has acceptance criteria, all scope is scheduled, and every external dependency is a named gate (G-1 to G-9). Gates control when a feature may be declared done, not when building starts.

| Role | Name | Date | Signature |
| --- | --- | --- | --- |
| Product owner |  |  |  |
| Tech lead and architect |  |  |  |
| Finance and tax adviser |  |  |  |
| Security lead |  |  |  |
| Legal and privacy |  |  |  |
