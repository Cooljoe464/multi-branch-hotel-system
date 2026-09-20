# Multi-Branch Hotel Management System

## Platform Overview

A comprehensive, enterprise-grade Property Management System (PMS) built for multi-branch hotel operations. The platform covers the full hotel operations lifecycle — from booking and check-in through checkout — including revenue management, food & beverage operations, housekeeping, maintenance, financial accounting, and guest self-service.

**Tech Stack:** Laravel 13, PHP 8.4, Vue 3 + Inertia.js, Laravel Reverb (WebSockets), Pest v5

---

## 1. Core Architecture

| Component | Count |
|-----------|-------|
| Eloquent Models | 43 |
| Controllers | 52 |
| Vue Pages | 85+ |
| Vue Components | 30+ |
| Services | 15 |
| Events | 9 |
| Jobs | 3 |
| Database Migrations | 48 |
| Database Seeders | 31 |

### Multi-Branch Support
- Users belong to multiple branches via `user_branch` pivot table
- Each user has a `currentBranch` and a default branch
- Branch-scoped access control via `EnsuresBranchAccess` trait on controllers
- Global Admin bypasses all branch restrictions
- Group Ledger with exchange rate conversion for multi-branch financial consolidation

---

## 2. Room & Inventory Management

### Room Management
- **Room Types** with full CRUD, pricing, and amenity configuration
- **Room CRUD** with status tracking (Available, Occupied, Out of Order, etc.)
- **Room Status Dashboard** with real-time updates via WebSocket broadcasting
- **Tape Chart** — visual room allocation and availability grid

### Housekeeping
- **Housekeeping Dashboard** with task assignment and tracking
- Room status updates (Clean, Dirty, Inspected)
- Real-time room status broadcasting to all connected clients

### Maintenance
- **Maintenance Ticket System** with priority levels and assignment
- **Kitchen Waste Logging** for F&B operations

### Inventory Management
- **Inventory Items** with stock tracking
- **Inventory Transactions** for stock movements
- **Transfer Requests** between branches

---

## 3. Reservations & Bookings

### Reservation System
- **Full Reservation CRUD** with guest assignment, room type selection, and date management
- **Reservation Status Workflow** (Confirmed, Checked-in, Checked-out, Cancelled)
- **Registration Card** generation
- **Guest Preferences** tracking

### Booking Engine
- **Public Booking Engine** (`BookingEngineController`) for online reservations
- Guest-facing self-service portal

### Channel Management
- **Channel Provider Models** — integration with OTAs (Booking.com, Expedia, etc.)
- **Channel Rate Management** — sync rates across channels
- **Channel Reservations** — import bookings from channels
- **Channel Sync Service** — automated synchronization

### Central Reservation System (CRS)
- **CRS Controller** for centralized reservation management across all branches

### Yield Management
- **Yield Rules** — dynamic pricing rules
- **Rate Overrides** — override standard rates per date/room type

---

## 4. Front Desk Operations

### Front Desk Dashboard
- **Real-time Room Status Overview** — available, occupied, out-of-order counts
- **Today's Arrivals & Departures** — quick access lists
- **Walk-in Reservation** support
- **Room Assignment** with drag-and-drop on Tape Chart

### Check-in / Check-out
- **Check-in Workflow** — room assignment, key issuance, payment collection
- **Check-out Workflow** — folio settlement, room status update
- **Door Lock Integration** — automatic key issuance on check-in

### Guest Management
- **Guest Profiles** with contact info, preferences, and reservation history
- **Guest Search** across branches (Global Admin)

---

## 5. Financial Management

### Folio System
- **Folio Management** — per-reservation financial tracking
- **Transaction Posting** — room charges, POS charges, payments
- **Folio Disputes** — dispute initiation and resolution workflow
- **PDF Bill Generation** — folio export via wkhtmltopdf

### Night Audit
- **Night Audit Service** — automated nightly room charge posting
- **Close Daily Ledger** — end-of-day financial reconciliation
- **Night Audit Report** — Excel export with financial summary

### City Ledger
- **City Ledger Accounts** — corporate/agent accounts
- **City Ledger Transactions** — posting and settlement tracking

### Group Ledger
- **Group Ledger** — multi-reservation billing for groups/events
- **Exchange Rate Conversion** — multi-currency support

### Banking
- **Bank Profiles** — bank account management for reporting

---

## 6. Point of Sale (POS)

### POS Operations
- **POS Terminal** interface for restaurant/bar/SPA
- **POS Charges** — itemized charges posted to folio
- **Outlet Management** — configure POS outlets (restaurants, bars, spa, etc.)

### Menu Management
- **Menu Items** with categories, pricing, and availability toggling
- **Menu Item Stations** — kitchen station routing
- **Recipes** — ingredient tracking for menu items

### Kitchen Display System (KDS)
- **KDS Dashboard** — real-time kitchen order queue
- **Kitchen Stations** — station-based order routing
- **KOT (Kitchen Order Tickets)** — ticket creation and status tracking
- **Real-time KOT Updates** via WebSocket broadcasting

### Tablet Ordering
- **Tablet Sessions** — device/session management
- **Tablet Orders** — order placement from tablets
- **Tablet Menu** — menu browsing on tablet devices

---

## 7. Food & Beverage

### Menu & Pricing
- **Menu Items** with categories, pricing, and stock status
- **Recipe Tracking** for ingredient management
- **Menu Item Stock Toggle** — real-time availability broadcasting

### Order Management
- **Guest Orders** — room charge or direct payment
- **Order Status Tracking** — pending, preparing, ready, served
- **KOT Routing** — automatic routing to correct kitchen station

---

## 8. Laundry Management

- **Laundry Orders** — create and track laundry requests
- **Laundry Status Tracking** — pending, washing, ready, delivered
- **Laundry Attendant Dashboard** — task queue and management

---

## 9. Analytics & Reporting

### Analytics Dashboard
- **Revenue Analytics** — revenue by branch, room type, period
- **Occupancy Analytics** — occupancy rates and trends
- **KPI Dashboard** — key performance indicators

### Reports
- **Financial Summary Report** — Excel export with totals and averages
- **Night Audit Report** — daily financial reconciliation
- **Audit Flag Reports** — operational anomaly tracking
- **Analytics Export** — data visualization and export

### Audit System
- **Audit Flags** — operational anomaly detection with severity levels
- **Review Workflow** — flag review and resolution
- **Activity Logging** — Spatie Activity Log on all major models

---

## 10. Authentication & Security

### Authentication Stack
- **Laravel Fortify** — login, registration, password reset, email verification
- **Two-Factor Authentication (2FA)** — TOTP-based with OTP challenge
- **Passkey/WebAuthn** — passwordless authentication
- **Laravel Sanctum** — API token authentication for POS API

### Role-Based Access Control (Spatie)
| Role | Description |
|------|-------------|
| **Global Admin** | Full system access, branch management, cross-branch search |
| **Property Owner** | Revenue management, analytics, reports, channel management |
| **Branch GM** | Full operational control within assigned branch |
| **Front Desk** | Reservations, check-in/out, room status, guests, folios |
| **Housekeeper** | Room status updates, housekeeping tasks |
| **Kitchen Staff** | KDS operations, menu items, inventory view |
| **Laundry Attendant** | Laundry management operations |
| **Cashier** | POS operations, folio management, reports |
| **Auditor** | Audit flags, reports, analytics, settings management |

**28 Permission Groups** with fine-grained actions (view, manage, create, update, delete).

---

## 11. Integrations

### Payment Gateway (Paystack)
- Online payment initialization and verification
- Webhook handling for payment confirmation
- Guest self-service payment portal
- Configurable payment mode per branch (pay-first vs pay-later)

### Door Lock Integration
- **3 Providers:** Salto, Assa Abloy, Duowin
- Key issuance, revocation, and status tracking
- Auto-generated 6-digit PIN codes
- Full audit logging with credential tracking

### Channel Manager
- OTA integration (Booking.com, Expedia, etc.)
- Rate and availability sync
- Reservation import from channels

---

## 12. Real-Time Features (Laravel Reverb + Echo)

| Event | Purpose |
|-------|---------|
| `RoomStatusUpdated` | Room status changes broadcast to front desk |
| `KotItemStatusUpdated` | Kitchen order ticket status updates |
| `MenuItemStockToggled` | Menu item availability changes |
| `TransactionPosted` | Financial transaction notifications |
| `PaymentReceived` | Payment confirmation broadcasts |
| `OrderStatusUpdated` | F&B order status changes |
| `FolioClosed` | Folio settlement notifications |
| `DisputeInitiated` / `DisputeResolved` | Dispute workflow notifications |

---

## 13. Guest Experience

### Guest Portal
- **Self-service check-in** via digital link
- **Payment portal** for outstanding balances
- **QR Code** generation for portal access

### Guest Notifications
- **Pre-Arrival Notification** — digital check-in link via email
- **Check-In Notification** — welcome email post check-in
- **Checkout Notification** — departure confirmation email

### GDPR Compliance
- Guest data anonymization (PII replacement, soft-delete)
- Guest data export (JSON format with preferences and history)
- Activity logging of all GDPR operations

---

## 14. Data Import & Export

| Type | Format | Content |
|------|--------|---------|
| Import | Excel | Rooms, Guests, Reservations (with templates) |
| Export | Excel | Night Audit, Financial Summary |
| Export | JSON | Guest data (GDPR) |

All imports processed via queued jobs (`ProcessExcelImportJob`).

---

## 15. Branding & White-Label

- **Branding Model** — singleton pattern for logo and theme
- Logo storage on Cloudflare R2 with temporary URL generation
- Configurable branding per deployment

---

## 16. Infrastructure & DevOps

### Deployment
- Docker support (`docker-compose.yml`, `Dockerfile`)
- Laravel Cloud deployment ready

### Background Jobs
- `PostRoomChargesJob` — nightly room charge posting (dedicated `night-audit` queue)
- `CloseDailyLedgerJob` — daily ledger closing
- `ProcessExcelImportJob` — async Excel import processing

### File Storage
- Cloudflare R2 for logo/branding assets
- Temporary URL generation with 7-day expiry

### Testing
- Pest v5 with browser testing plugin
- Feature and unit test structure
- 31 database seeders for comprehensive test data

---

## 17. Settings & Configuration

- **Branch Settings** — per-branch configuration
- **User Management** — create, edit, assign roles and branches
- **Role Management** — permission assignment
- **Rate Plans** — configurable rate structures
- **Outlets** — POS outlet configuration
- **Kitchen Stations** — KDS station setup
- **Channel Providers** — OTA integration settings
- **Door Lock Gateways** — lock provider configuration per branch

---

## Summary

This is a **production-grade, enterprise-level Hotel Property Management System** covering:

- Multi-branch hotel operations
- Full reservation lifecycle management
- Real-time front desk and kitchen operations
- Comprehensive financial accounting (folios, ledgers, night audit)
- POS and F&B management with KDS
- Yield management and dynamic pricing
- Channel management and OTA integration
- Door lock system integration
- Guest self-service portal
- GDPR compliance
- Role-based access control with 10 roles and 28 permission groups
- Real-time WebSocket broadcasting
- Analytics and reporting
- Mobile/tablet ordering support
