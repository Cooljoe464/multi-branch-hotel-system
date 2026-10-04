# Platform Guide

## Role-Based Navigation

This guide explains what each role can see and do in the multi-branch hotel management system.

### Global Admin

- **Full access** to every module in the system
- Can manage branches, users, roles, permissions
- Can view all reports and analytics
- Can configure branding, payment guard, and system settings

### Property Owner

- **Dashboard** — business overview for their branch
- **Reservations** — full CRUD
- **Rooms** — view, update status
- **Front Desk** — check-in, check-out, registration cards
- **Folio** — view, post charges, transfers, adjustments
- **POS** — terminal access, charges
- **Tablet** — guest-facing order management
- **KDS** — view kitchen display
- **Menu Items** — view and manage
- **Outlets** — view and manage
- **Inventory** — view and manage
- **Transfers** — view and manage
- **Laundry** — view and manage
- **Channels** — view and manage
- **CRS** — view and manage
- **City Ledger** — view and manage
- **Reports** — all analytics including P&L, Channel Yield, Tax Liability
- **Yield Rules** — view and manage
- **Rate Overrides** — view and manage
- **Settings** — profile, security, payment guard
- **Audit Flags** — view all generated flags
- **Door Lock** — manage

### Branch GM

- **Dashboard** — business overview for their branch
- **Reservations** — full CRUD (check-in, check-out, cancel)
- **Rooms** — view, update status
- **Front Desk** — check-in, check-out, registration cards
- **Folio** — view, post charges, transfers, adjustments
- **POS** — terminal access, charges
- **Tablet** — guest-facing order management
- **KDS** — view kitchen display
- **Menu Items** — view and manage
- **Outlets** — view and manage
- **Inventory** — view and manage
- **Transfers** — view and manage
- **Laundry** — view and manage
- **Reports** — view and export
- **Yield Rules** — view and manage
- **Rate Overrides** — view and manage
- **Door Lock** — manage
- **Housekeeping** — view and manage
- **Maintenance** — view and manage
- **Settings** — view
- **Audit** — view

### Front Desk

- **Dashboard** — branch overview
- **Reservations** — create, edit, check-in, check-out
- **Rooms** — update status
- **Check-in / Check-out**
- **Registration Cards** — generate
- **POS** — charges
- **Tablet** — pair/unpair
- **Guest folio** — post charges, view
- **Housekeeping** — view
- **Maintenance** — view
- **Folios** — view and manage
- **Menu Items** — view
- **Inventory** — view
- **Laundry** — view and manage
- **Hotspot** — select Wi-Fi tier at booking, auto-provision on check-in, upgrade mid-stay

### Housekeeper

- **Dashboard** — task summary
- **Rooms** — update status
- **Mobile Tasks** — view assigned tasks, start/complete
- **Laundry** — view tasks

### Kitchen Staff

- **KDS** — view and update order status (pending → preparing → ready → served)
- **Menu Items** — view, toggle availability
- **Inventory** — view stock levels

### Laundry Attendant

- **Laundry** — view and manage laundry tasks
- **Rooms** — view

### Cashier

- **Dashboard** — revenue summary
- **POS** — process payments
- **Folio** — post charges, view, manage (incl. paid Wi-Fi tiers)
- **Reports** — payment-related reports

### Auditor

- **Reports** — full analytics access
- **Audit Flags** — view all generated flags
- **Night Audit** — run audit
- **Financials** — P&L, tax liability
- **Settings** — view, update, manage

---

## Key Features

### Payment Guard

Located in **Settings > Payment Guard** (Global Admin / Property Owner only).

Two modes:

- **Pre-Pay** — QR code payment required before orders reach KDS
- **Post-Pay** — orders dispatch immediately, charges post to room folio

### Paystack Integration

The platform uses **Paystack** as its payment gateway for online transactions. The integration is handled via raw HTTP calls to `https://api.paystack.co` (no SDK dependency).

**Environment Variables** (in `.env`):

```
PAYSTACK_SECRET_KEY=sk_test_xxxxx
PAYSTACK_PUBLIC_KEY=pk_test_xxxxx
```

**Key Components:**

- **`PaymentService`** (`app/Services/PaymentService.php`) — handles payment initialization, transaction verification, pre-auth capture, and refunds
- **`PaystackWebhookController`** (`app/Http/Controllers/PaystackWebhookController.php`) — receives webhook callbacks at `POST /api/webhooks/paystack`, verifies HMAC-SHA512 signature
- **`PaymentTransaction` model** (`app/Models/PaymentTransaction.php`) — stores `paystack_reference`, `paystack_access_code`, status, and webhook payloads

**Payment Flow:**

1. `PaymentService::initializePayment()` calls `POST /transaction/initialize` on Paystack API
2. Returns `access_code` and `reference` for frontend payment widget
3. On successful payment, Paystack sends webhook to `/api/webhooks/paystack`
4. `PaymentService::handleWebhook()` verifies and processes `charge.success`, `charge.failed`, `refund.created` events
5. Successful charges post a credit to the guest folio automatically

**Webhook Events Handled:**

| Event                   | Action                                              |
| ----------------------- | --------------------------------------------------- |
| `charge.success`        | Marks transaction successful, posts credit to folio |
| `charge.failed`         | Marks transaction failed                            |
| `authorization.success` | Stores pre-authorization code for later capture     |
| `refund.created`        | Creates refund transaction, posts debit to folio    |

**Testing:**
Webhook signature verification can be tested with `tests/Feature/PaystackWebhookControllerTest.php` (14 test cases covering signature validation, charge success/failure, refunds, and idempotency).

### Tablet System

Guest-facing in-room ordering:

1. Front Desk pairs tablet on check-in
2. Guest browses menu, places order
3. Payment guard determines flow (QR or room charge)
4. Order dispatches to KDS with outlet routing

### KOT Station Routing

Items are automatically routed to the correct kitchen station based on `MenuItemStation` mappings. Falls back to `general` outlet if no mapping exists.

### Audit Flags

Generated automatically during night audit:

- Rate overrides exceeding threshold
- Voided transactions above dollar threshold
- Large refunds

View at **Audit Flags** in the sidebar.

### Door Lock Integration

Supports Assa Abloy, Salto, Dormakaba, and Duowin providers. Duowin requires a local Node.js bridge (`duowin-bridge/server.js`) for serial port card encoding.

### Hotspot (Guest Wi-Fi)

In-hotel guest Wi-Fi with free + paid tiers, one MikroTik per branch, cloud RADIUS over WireGuard. Full runbook: `docs/HOTSPOT.md`.

1. Guest (or desk) picks a tier before the reservation completes — free included, paid posts a `wifi` folio charge
2. Check-in auto-provisions a per-guest credential (12-char voucher)
3. Guest joins `GUEST-WIFI`, logs in at the portal
4. Check-out/expiry revokes + disconnects automatically

- Company systems live on the isolated staff VLAN (PSK) — guests cannot route to them
- Manage tiers + download the router `.rsc` at **Hotspot** in the sidebar (`hotspot.manage`)

### WebSocket Notifications (Laravel Reverb)

Real-time updates via Laravel Reverb with Pusher fallback:

- KDS items update live via `branch.{id}.kds` private channel
- Tablet order status tracks in real-time via `tablet.order.{id}` channel
- Menu availability toggles reflect on guest tablets via `branch.{id}.menu` channel
- Room status changes broadcast via `private-branch.{id}` private channel

---

## Permissions Reference

| Module         | View                  | Manage                  | Notes                                                         |
| -------------- | --------------------- | ----------------------- | ------------------------------------------------------------- |
| Reservations   | `reservations.view`   | —                       | Actions: create, update, checkin, checkout, cancel, delete    |
| Rooms          | `rooms.view`          | `rooms.manage`          | Also: `rooms.update_status`                                   |
| POS            | `pos.view`            | `pos.manage`            |                                                               |
| KDS            | `kds.view`            | `kds.manage`            |                                                               |
| Menu Items     | `menu_items.view`     | `menu_items.manage`     |                                                               |
| Inventory      | `inventory.view`      | `inventory.manage`      |                                                               |
| Transfers      | `transfers.view`      | `transfers.manage`      |                                                               |
| Laundry        | `laundry.view`        | `laundry.manage`        |                                                               |
| Channels       | `channels.view`       | `channels.manage`       |                                                               |
| CRS            | `crs.view`            | `crs.manage`            |                                                               |
| City Ledger    | `city_ledger.view`    | `city_ledger.manage`    |                                                               |
| Reports        | `reports.view`        | —                       | Also: `reports.export`                                        |
| Settings       | `settings.view`       | `settings.manage`       |                                                               |
| Branches       | `branches.view`       | `branches.manage`       |                                                               |
| Users          | `users.view`          | —                       | Actions: create, update, delete                               |
| Roles          | `roles.view`          | `roles.assign`          |                                                               |
| Yield Rules    | `yield_rules.view`    | `yield_rules.manage`    |                                                               |
| Rate Overrides | `rate_overrides.view` | `rate_overrides.manage` |                                                               |
| Audit          | `audit.view`          | `audit.manage`          |                                                               |
| Door Lock      | `door_lock.view`      | `door_lock.manage`      |                                                               |
| Hotspot        | `hotspot.view`        | `hotspot.manage`        | Also: `hotspot.issue`, `hotspot.revoke`, `hotspot.grant_free` |
| Tape Chart     | `tape_chart.view`     | —                       |                                                               |
| Housekeeping   | `housekeeping.view`   | `housekeeping.manage`   |                                                               |
| Maintenance    | `maintenance.view`    | `maintenance.manage`    | Also: `door_lock.manage` for lock/unlock                      |
| Outlets        | `outlets.view`        | `outlets.manage`        |                                                               |
| Rate Plans     | `rate_plans.view`     | `rate_plans.manage`     |                                                               |
| Guests         | `guests.view`         | `guests.manage`         |                                                               |
| Folios         | `folios.view`         | `folios.manage`         |                                                               |
| Analytics      | `analytics.view`      | `analytics.manage`      |                                                               |
