# Untested Features & Known Gaps

This document lists features, modules, and scenarios that are **not covered** by the existing test suite or have only partial test coverage. Use this as a checklist for future test expansion.

---

## 1. Deferred / Not Implemented

| Area                                | Status   | Notes                                                                                    |
| ----------------------------------- | -------- | ---------------------------------------------------------------------------------------- |
| **Booking.com / OTA Channel Integration** | Deferred | Only `LogChannelDriver` and `FailingChannelDriver` exist; sync-service behaviour is tested against them. No real OTA driver (spec G-4: partner not yet selected). |
| **Live NRS / FIRS e-invoicing** | Deferred | The tax engine (`TaxService`) and the e-invoice lifecycle (`FiscalService`, `FirsEmitter`: hold, retry, credit notes) are implemented and covered by `TaxEngineTest` and `FiscalEmissionTest` against faked HTTP. No tests run against a real provider sandbox (spec G-2). |
| **Entitlements & SaaS billing** | Not built | No plans, subscriptions, entitlements or dunning yet (spec v1.1 §12, AC-042, AC-056). |
| **Offline provisional check-in** | Not built | No service worker, sync endpoints or override tokens yet (spec v1.1 §6, AC-038 to AC-041). |
| **Second payment gateway** | Not built | Only `PaystackDriver` exists (spec v1.1 ADR-010, AC-043). |
| **TTLock and edge agent** | Not built | No TTLock provider and no edge agent (spec v1.1 §8 and §9, AC-052, AC-053, AC-055). |
| **Passkey/WebAuthn Hardware**       | Deferred | Passkey model and routes exist, but no hardware-specific tests for FIDO2 authenticators. |
| **Physical POS Hardware**           | Deferred | No tests for physical card readers, receipt printers, or cash drawers.                   |

---

## 2. Partially Tested / Not Covered

| Area                                   | Status           | Notes                                                                                                                                                                                                |
| -------------------------------------- | ---------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Duowin Door Lock Hardware**          | Partial          | `DuowinProviderTest.php` tests the REST API client against mock HTTP responses. No tests exist for the Node.js serial port bridge (`duowin-bridge/server.js`) or physical card encoding.             |
| **Live WebSocket Delivery (Reverb)**   | Partial          | `ReverbBroadcastingTest.php` verifies event dispatch, channel scoping, and payload serialization. No tests verify actual live socket delivery to connected Echo clients.                             |
| **Physical Card Encoding**             | Not Tested       | Requires actual COM port hardware connected to `duowin-bridge/server.js`.                                                                                                                            |
| **Email/SMS Notification Delivery**    | Not Tested       | `NotificationTest.php` verifies notification objects are created. No tests verify actual email or SMS delivery via configured mailers.                                                               |
| **File Upload & Export Features**      | Not Tested       | Export controllers exist (night audit, financial, folio PDF). No tests verify actual file generation, PDF rendering, or Excel export.                                                                |
| **GDPR Data Export/Anonymization**     | Partial          | `GdprTest.php` verifies the controller logic. No tests verify actual data export file generation or anonymization of related tables.                                                                 |
| **Cross-Branch Synchronization**       | Partial          | Cross-branch search exists but no tests verify multi-branch data consistency or synchronization.                                                                                                     |
| **Tablet SPA Kiosk Mode**              | Not Tested       | Tablet routes (`/tablet/{confirmation}/menu`) exist. No browser tests cover the kiosk-mode SPA.                                                                                                      |
| **Scheduled Jobs**                     | Partial          | `PostRoomChargesJobTest.php` and `CloseDailyLedgerJobTest.php` exist. No tests for job failures, retries, or timeouts.                                                                               |
| **Backup & Restore**                   | Not Tested       | Backup configuration exists (`config/backup.php`). No tests verify backup creation, storage, or restore workflows.                                                                                   |
| **Multi-language / Localization** | Partial | `LocaleTest.php` covers locale switching. Translation file completeness is not verified. |
| **API Token Authentication (Sanctum)** | Partial | `ApiV1Test` and `CrossTenantSecurityTest` authenticate with Sanctum. Token scopes and revocation are not tested. |
| **Queue Worker Failures**              | Not Tested       | Queue configuration exists. No tests verify job failure handling, dead letter queues, or retry logic.                                                                                                |
| **Rate Limiting** | Partial | Login throttling is covered in `Auth/AuthenticationTest`. The throttles on POS, check-in and public API routes are not. |
| **CORS Configuration**                 | Not Tested       | CORS middleware exists. No tests verify cross-origin requests.                                                                                                                                       |
| **Maintenance Mode**                   | Not Tested       | Maintenance driver is configured. No tests verify maintenance mode responses.                                                                                                                        |
| **Performance / Load Testing**         | Partially Tested | Pest load group (`AvailabilityLoadTest` + race workers, nightly in CI) and a k6 staging probe (`tests/load/availability.js`, nightly + manual) cover the booking engine; no full-traffic benchmarks. |
| **WebSocket Channel Authorization**    | Not Tested       | `broadcasting/auth` endpoint exists. No tests verify private channel authorization logic.                                                                                                            |
| **Physical RouterOS Import**           | Not Tested       | `RouterOsConfigService` output is asserted as text in `HotspotTest.php`. No tests import the `.rsc` on real RouterOS or verify firewall behavior on hardware.                                        |
| **Live RADIUS / CoA**                  | Not Tested       | `RadiusService`/`MikrotikService` run fake (log-only) in all environments. No tests against a live FreeRADIUS DB (`radcheck`/`radreply`/`radacct`) or real CoA-disconnect.                           |
| **RADIUS Accounting Ingestion**        | Deferred         | `bytes_used`/`last_acct_at` columns exist; the NAS→app accounting feed is not implemented.                                                                                                           |

---

## 3. Browser Test Limitations

| Limitation                    | Notes                                                                                                                                                                   |
| ----------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Shadcn Select Components**  | Browser tests avoid shadcn `<Select>` interactions due to complex Playwright escaping. Form submission tests use direct HTTP assertions (`actingAs()->post()`) instead. |
| **Date Picker Interactions**  | Reservation creation forms use `@vuepic/vue-datepicker`. Browser tests verify the page loads but not date selection.                                                    |
| **Modal Dialog Interactions** | Browser tests verify modal opens but not full form submission within modals.                                                                                            |
| **WebSocket Live Events**     | Browser tests verify page loads but not real-time WebSocket event delivery.                                                                                             |
| **File Uploads**              | No browser tests cover file upload forms (import rooms/guests/reservations).                                                                                            |
| **Print/Export Actions**      | No browser tests cover print or export buttons.                                                                                                                         |

---

## 4. Recommended Future Test Expansion

1. **Physical Hardware Tests** — Add integration tests for `duowin-bridge/server.js` using a mock serial port.
2. **Live WebSocket Tests** — Add a test that connects a real Echo client via Reverb and verifies event delivery.
3. **Export/PDF Tests** — Verify PDF generation for folios, registration cards, and financial reports.
4. **File Upload Tests** — Test Excel import for rooms, guests, and reservations.
5. **Rate Limiting Tests** — Verify throttle middleware behavior on check-in/check-out routes.
6. **CORS Tests** — Verify cross-origin request handling.
7. **Maintenance Mode Tests** — Verify maintenance mode responses.
8. **Performance Tests** — Add load tests for high-traffic routes (booking engine, POS checkout).
9. **API Token Tests** — Add Sanctum API token authentication tests.
10. **Queue Failure Tests** — Add job failure, retry, and dead letter queue tests.
