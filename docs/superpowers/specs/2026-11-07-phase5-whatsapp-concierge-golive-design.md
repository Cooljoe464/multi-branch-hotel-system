# Phase 5 — Go-Live Readiness Design (WhatsApp Concierge)

- **Date:** 2026-11-07
- **Source:** `whatsapp-concierge-implementation-plan.md` §Phase 5, derived from the Phase 6.3 build-out design (`whatsapp-concierge-build-out.md`).
- **Status:** Design approved · pending written-spec review

## Objective

Transition the WhatsApp concierge (BSP-free Meta Cloud API) from a code-complete, dockerized, test-green state into a **single-branch live pilot**, then production go-live.

## Design

### Workstream 1 — Meta-direct onboarding (external, user-driven)
Sequence: Meta dev-mode (test WABA + test phone number) → single-branch pilot → production (WABA + phone + App Review).

1. Create Meta Business app + WhatsApp product + WABA (~10.x).
2. Register phone number; complete display-name review.
3. Issue permanent System User token with `whatsapp_business_messaging` + `whatsapp_business_management`.
4. Set webhook callback (`https://<host>/webhooks/whatsapp`) subscribed to `messages` (inbound + status updates).
5. Add app secret; verify `X-Hub-Signature-256` against the branch secret.
6. Complete App Review / Advanced Access; publish privacy policy + data-deletion URLs before messaging non-test numbers.

### Workstream 2 — Branch configuration & runtime enablement (app)
- Persist per-branch WhatsApp settings in `branches.settings.whatsapp`: `enabled`, `phone_number_id`, `waba_id`, `verify_token`.
- Store token/secret via `WhatsAppCloudApiService::storeAccessToken` / `storeAppSecret` (encrypted at rest).
- Populate branch knowledge base before enabling; keep kill switch OFF until pilot checks pass.

### Workstream 3 — AI provider & compliance (app)
- Set `CONCIERGE_AI_PROVIDER` plus matching `CONCIERGE_AI_*_API_KEY` / `CONCIERGE_AI_*_MODEL`.
- Run `php artisan config:cache` after configuration.
- With no key configured, concierge stays on the deterministic heuristic driver and makes no external calls.
- Publish provider privacy/DPA note: guest message bodies leave the platform for the chosen provider.

### Workstream 4 — Pilot checks (9 operational checks)
1. Webhook verification challenge passes; invalid signatures rejected.
2. FAQ answer includes AI-assistant disclosure; low-confidence/complaint/money turns hand off.
3. Rate quote equals RateEngine exactly; never invents a price.
4. Late-checkout draft stays pending until staff approves; repeats don't duplicate drafts.
5. STOP revokes WhatsApp marketing consent and acknowledges; service replies still flow.
6. Kill switch flips branch to human-only; handback resumes AI.
7. Agent takeover → AI draft → agent edits → sends; delivery status updates arrive.
8. Failed delivery → handoff; no duplicate handoff events.
9. Note 24h customer-service window: free-form replies outside it require approved templates; proactive marketing remains deferred.

### Recommended staged sequence
1. **Stage 1** — Meta dev-mode setup (test WABA/phone) + branch config with test number.
2. **Stage 2** — AI provider/key + `config:cache` + KB + kill switch off.
3. **Stage 3** — Pilot: run all 9 checks on the single pilot branch via test number; iterate.
4. **Stage 4** — Go-live: production phone + App Review + privacy/DPA + enable.

## Open decisions
- Pilot branch identity (live DB has no seeded branches; to be identified).
- AI provider/key selection.

## Risks
- Meta App Review latency; 24h messaging window; kill-switch sequencing.

## Spec self-review
- **Placeholder scan:** no TBDs/TODOs; open items are explicitly listed decisions.
- **Consistency:** workstreams map 1:1 to the approved Phase 5 checklist.
- **Scope:** focused on Phase 5 only; pilot implementation deferred per staged sequence.
- **Ambiguity:** resolved as staged (dev → pilot → prod).

---

**Spec written to `docs/superpowers/specs/2026-11-07-phase5-whatsapp-concierge-golive-design.md`.**

Per the brainstorming workflow: please review the spec and let me know if you want any changes before I proceed to write the implementation plan. (Next step after your approval: invoke the writing-plans skill to produce the detailed execution plan, then start executing approved items.)