# Phase 5 — Go-Live Implementation Plan (WhatsApp Concierge)

- **Date:** 2026-11-07
- **Design:** `docs/superpowers/specs/2026-11-07-phase5-whatsapp-concierge-golive-design.md`
- **Status:** Draft — pending execution approval

## Objective

Move the WhatsApp concierge from the approved Phase 5 design into executed, sequenced steps toward a single-branch live pilot and production go-live.

## Pre-implementation state (verified this session)

- Live docker stack green: php (healthy), nginx (80/443/8088), postgres (healthy, now `5433:5432` published), valkey cache/queue (healthy), queue + scheduler Up, reverb standalone Up; `/healthz` ok, `/readyz` ok.
- Vite assets serving clean; browser console clean; Reverb WS upgrade → 101.
- Horizon supervisor covers concierge queue: `['channel', 'reservations', 'pos', 'concierge']` (config/horizon.php).
- Code touchpoints present: `Branch::$settings` array; `WhatsAppCloudApiService::storeAccessToken/storeAppSecret`; `services.concierge_ai` (openai/anthropic/openrouter/google) with env-driven keys/models.

## Implementation tasks

### Task 1 — Meta-direct onboarding (external, gated)
- [ ] User creates Meta Business app + WhatsApp product + WABA in Meta developer account.
- [ ] Register phone number (test number for pilot; production later).
- [ ] Issue permanent System User token (`whatsapp_business_messaging`, `whatsapp_business_management`).
- [ ] Configure webhook callback `https://<host>/webhooks/whatsapp` with `messages` field.
- [ ] Add app secret; wire branch secret + `X-Hub-Signature-256` verification (code-side done in Phase 3/4; runtime config pending).

### Task 2 — Branch configuration & runtime enablement (app-side)
- [ ] Write per-branch settings: `enabled`, `phone_number_id`, `waba_id`, `verify_token` in `branches.settings.whatsapp`.
- [ ] Store token/secret via `WhatsAppCloudApiService` methods (encrypted at rest).
- [ ] Populate branch KB before enabling.
- [ ] Keep kill switch OFF until pilot checks pass.

### Task 3 — AI provider & compliance (app-side)
- [ ] Set `CONCIERGE_AI_PROVIDER` + `CONCIERGE_AI_*_API_KEY` / `_MODEL`.
- [ ] Run `php artisan config:cache`.
- [ ] Confirm no-key → heuristic fallback (no external calls).

### Task 4 — Pilot checks execution (operational, ordered)
Run against single pilot branch via test number:
1. Webhook verification challenge + signature rejection.
2. FAQ answer w/ AI disclosure; low-confidence/complaint/money → handoff.
3. Rate quote parity with RateEngine; no invented price.
4. Late-checkout draft pending until staff approval; no duplicate drafts.
5. STOP revokes marketing consent + acks; service replies flow.
6. Kill switch → human-only; handback resumes AI.
7. Agent takeover → AI draft → edit → send; delivery updates arrive.
8. Failed delivery → handoff; no duplicate handoffs.
9. Document 24h customer-service window (free-form replies outside require approved templates; proactive marketing deferred).

### Task 5 — Go-live cutover
- Enable branch → production phone + App Review + Advanced Access → privacy/DPA → production pilot.

## Sequencing

1. Task 1 (external) → unblock with test credentials.
2. Task 2 + Task 3 (app-side) → prepare runtime config.
3. Task 4 → validate on pilot branch.
4. Task 5 → go-live.

## Acceptance criteria for Phase 5 completion

- All 9 pilot checks pass on single branch (test number).
- `php artisan config:cache` clean; concierge AI wired or intentionally on heuristic.
- Live stack: webhook reachable, delivery callbacks, queue worker (`concierge` queue) processing.

## Risks & mitigations

- Meta App Review latency → keep pilot on test numbers until approved.
- 24h messaging window → free-form replies only within window; templates for proactive marketing (deferred).
- Kill-switch sequencing → default OFF during pilot.

## Out of scope

- Proactive AI outbound broadcasts (explicitly non-goal for Phase 6.3).
- BSP reintroduction.

## Next actions

- Await user decisions: pilot branch, AI provider/key, Meta credential readiness.
- Execute Tasks 2/3 (app-side runtime prep) where possible without external credentials; then coordinate Task 1 → Task 4 → Task 5.
