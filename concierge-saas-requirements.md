# WhatsApp Concierge — SaaS Requirements (Refined)

## Context

The Herd multi-branch hotel management system (HMS) is a SaaS platform with a
hierarchical tenant (hotel group) → branch (property) architecture. The WhatsApp
AI Concierge (roadmap Phase 6, currently executing Phase 5 go-live readiness) must
serve each tenant/branch as an autonomous digital staff member while respecting
multi-tenant isolation.

## Current SaaS Feature Set (Phases 1–4 — completed)

- Multi-branch/tenant management with isolated configurations.
- Reservation core: room type inventory, booking lifecycle, status tracking.
- Financial & accounting: daily ledger closing, cashier shifts, chart of accounts, posting rules.
- Operations: housekeeping, maintenance, room locks, tasks.
- POS/F&B: kitchen stations, menus, dining tables.
- Support services: billing cycles, hotspot management, audit logs.
- Identity & security: RBAC (Spatie), GDPR consent tracking, passkeys.

## Remaining Roadmap

| Phase | Focus | Status |
| :--- | :--- | :--- |
| Phase 1–4 | Infrastructure, driver seam, intent engine | Completed ✅ |
| Phase 5 | Go-live readiness (Meta onboarding) | Active (pending keys) |
| Phase 6.1 | Multi-provider AI adapter & consent/opt-out | Pending |
| Phase 6.2 | Delivery hardening & agent-suggested replies | Pending |
| Phase 6.2.1 | SaaS-specific concierge requirements (refined) | Pending |
| Phase 6.3 | Go-live / production launch | Pending |

## Refined SaaS Requirements for the WhatsApp Concierge

### 1. Tenant / Branch Autonomy (Isolation)

- Logical partitioning: concierge engine must enforce `Branch` scope; guest inquiries
  for one branch must never trigger AI responses based on another branch's rate plans.
- Independent webhook routing per branch, or a single master endpoint resolving the
  branch via Meta `phone_number_id` / `waba_id`.
- Autonomous onboarding: property managers complete their own Meta-to-HMS WhatsApp
  linking without database-level provider intervention.

### 2. Concierge "Tiering" (Upselling / Config)

- Feature gating: subscription-based toggle (plan entitlements) deciding whether a
  branch gets AI-driven concierge, basic heuristic, or none.
- Model flexibility: tenant/branch-level AI model overrides (e.g., `gpt-4o`, `gemini-pro`,
  `claude-3-5`) for premium vs. cost-efficient branches.

### 3. Staff & Workflow Management

- Branch-level hand-off: human handoffs route only to staff assigned to that branch;
  global admins oversee all.
- Draft approval workflows: staff-set auto-reply thresholds (e.g., auto-reply for simple
  FAQs; human approval required for quotes/upsells).

### 4. SaaS Analytics (Tenant Reporting)

- Concierge ROI reporting per tenant:
  - Deflection rate (AI answers without staff intervention).
  - Revenue impact (bookings/upsells originating from WhatsApp threads).
  - Sentiment score trends.

### 5. Security & Compliance

- Data residency / privacy: thread data (PII) indexed by `tenant_id`, cascade delete on
  branch/tenant termination.
- Content filtering: branch-level negative keywords / sensitive topics triggering
  human handoff.

## Open Questions / Next Steps

1. Meta onboarding (Phase 5) — pending credentials.
2. AI provider key provisioning (Phase 6.1) — pending.
3. SaaS-specific implementation order: isolation safeguards first, then tiering,
   then staff workflows, then analytics, then launch.
