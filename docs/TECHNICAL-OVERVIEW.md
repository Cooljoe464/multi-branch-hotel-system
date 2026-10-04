# Technical Overview

## Architecture

```
┌─────────────────────────────────────────────────────┐
│                   Frontend (Vue 3)                   │
│  Inertia.js ──┬── App Pages (resources/js/pages/)  │
│               ├── Tablet SPA (kiosk-mode)           │
│               └── Components (shadcn-vue + reka-ui) │
├─────────────────────────────────────────────────────┤
│                Laravel 13 Backend                     │
│  Controllers ─┬── HTTP Controllers                   │
│               └── API Controllers                    │
│  Services ────┬── FolioService                       │
│               ├── PaymentService (Paystack)           │
│               ├── PaymentGuardService                 │
│               ├── TabletOrderService                  │
│               ├── KotRoutingService                  │
│               ├── AuditFlagService                   │
│               ├── NightAuditService                  │
│               ├── DoorLockService                     │
│               ├── HotspotService (tiers + selection)  │
│               ├── RadiusService (cloud RADIUS sync)   │
│               ├── MikrotikService (single NAS/branch) │
│               ├── RouterOsConfigService (.rsc export) │
│               └── FolioLockService                    │
│  Events ──────┬── KotItemStatusUpdated               │
│               ├── MenuItemStockToggled               │
│               ├── OrderStatusUpdated                  │
│               ├── RoomStatusUpdated                   │
│               └── WifiIssued                          │
│  Jobs ────────┬── ProvisionWifiJob (queue: network)  │
│               └── DeprovisionWifiJob (queue: network) │
├─────────────────────────────────────────────────────┤
│              Database (PostgreSQL)                    │
│  ┌─────────────────────────────────────────────┐    │
│  │  Core Tables                                 │    │
│  │  ├── branches, rooms, reservations           │    │
│  │  ├── folios, transactions                    │    │
│  │  ├── guests, users, roles, permissions       │    │
│  │  ├── door_lock_audit_logs                    │    │
│  │  ├── daily_ledgers                           │    │
│  │  ├── audit_flags                             │    │
│  │  ├── hotspot_tiers, reservation_hotspots     │    │
│  │  ├── wifi_sessions (tier + NAS fields)       │    │
│  │  └── activity_log                            │    │
│  │                                               │    │
│  │  Feature Tables                               │    │
│  │  ├── menu_items, menu_item_stations         │    │
│  │  ├── kitchen_stations, outlets               │    │
│  │  ├── tablet_sessions, tablet_orders          │    │
│  │  ├── kot_items, pos_charges                  │    │
│  │  ├── inventory_items, recipes                │    │
│  │  ├── channel_providers, channel_rates,       │    │
│  │  │   channel_reservations                    │    │
│  │  ├── yield_rules, rate_overrides             │    │
│  │  ├── laundry_orders, transfer_requests       │    │
│  │  └── registration_cards                      │    │
│  └─────────────────────────────────────────────┘    │
├─────────────────────────────────────────────────────┤
│              External Services                        │
│  ┌─────────────────────────────────────────────┐    │
│  │  Laravel Reverb (WebSockets, default)         │    │
│  │  Pusher (WebSockets — fallback)               │    │
│  │  Duowin Bridge (Serial Port, Node.js)       │    │
│  │  FreeRADIUS (cloud, via WireGuard tunnel)     │    │
│  │  MikroTik RouterOS (1 NAS per branch)         │    │
│  │  Redis (Sessions, Cache, Queue)              │    │
│  │  Cloudflare R2 (Backups)                     │    │
│  └─────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────┘
```

## Tech Stack

| Layer           | Technology                | Version           |
| --------------- | ------------------------- | ----------------- |
| Backend         | Laravel                   | 13.17             |
| PHP             | PHP                       | 8.4               |
| Frontend        | Vue.js                    | 3.5               |
| SPA             | Inertia.js                | v3                |
| CSS             | Tailwind CSS              | 4                 |
| Components      | shadcn-vue + reka-ui      | source-vendored   |
| Auth            | Laravel Fortify           | —                 |
| Permissions     | Spatie Permission         | —                 |
| Activity Log    | Spatie Activity Log       | —                 |
| WebSockets      | Laravel Reverb            | (Pusher fallback) |
| Database        | PostgreSQL                | —                 |
| Cache/Queue     | Redis                     | —                 |
| Testing         | Pest                      | 5                 |
| Browser Tests   | Pest Browser + Playwright | —                 |
| Static Analysis | PHPStan                   | Level 10          |
| Code Style      | Laravel Pint              | —                 |
| Build           | Vite                      | —                 |

## Key Design Patterns

### Branch Scoping

Every branchable model uses `branch_id` + `BranchAccess` trait. Controllers use `EnsuresBranchAccess` trait to validate access.

### Money Handling

All monetary values stored as integer cents. Displayed via `formatCents()` helper in Vue.

### Audit Trail

Models use `LogsActivity` trait. Key state changes logged with `activity()` helper. Sensitive operations guard with `AuditGuardService`.

### Provider Pattern

External integrations (door locks) use a provider pattern — `DoorLockService` dispatches to the correct provider based on gateway configuration.

### Event-Driven

KDS, tablet, menu, and room updates broadcast via Laravel Reverb (with Pusher fallback) for real-time cross-device synchronization.

## File Structure

```
app/
├── Console/Commands/          # Artisan commands (RunNightAudit, HotspotExportRsc, HotspotExpireSessions)
├── Contracts/                 # Interfaces (LockProvider)
├── Events/                    # Broadcast events
├── Http/Controllers/
│   ├── Settings/              # Profile, Security, PaymentGuard, Branding
│   ├── Concerns/              # Traits (EnsuresBranchAccess)
│   └── [Domain]Controller.php
├── Models/                    # Eloquent models with Fillable attributes
├── Policies/                  # Authorization policies
├── Services/
│   ├── DoorLock/              # AssaAbloyProvider, SaltoProvider, DuowinProvider
│   ├── HotspotService         # Tier selection + folio posting
│   ├── RadiusService          # Cloud FreeRADIUS sync (fake by default)
│   ├── MikrotikService        # Single-NAS provision/kick (fake by default)
│   ├── RouterOsConfigService  # Per-branch .rsc isolation export
│   ├── AuditFlagService
│   ├── KotRoutingService
│   ├── PaymentService         # Paystack integration
│   ├── PaymentGuardService
│   └── TabletOrderService
└── View/Components/           # Blade components

database/
├── factories/                 # Model factories with states
├── migrations/                # Anonymous migration classes
└── seeders/                   # RoleSeeder, InventorySeeder, etc.

resources/
├── js/
│   ├── components/            # Vue components (AppSidebar, etc.)
│   ├── layouts/               # AppLayout, AuthLayout, SettingsLayout
│   ├── pages/                 # Inertia pages by domain
│   │   ├── analytics/         # ProfitAndLoss, ChannelYield, TaxLiability
│   │   ├── hotspot/             # Hotspot Index (tiers + .rsc export)
│   │   ├── housekeeping/      # Mobile
│   │   ├── kds/               # KDS Index
│   │   ├── pos/               # Terminal
│   │   ├── settings/          # PaymentGuard
│   │   └── tablet/            # Menu, TrackOrder
│   ├── app.ts                 # Entry point
│   └── bootstrap.ts           # Echo/Reverb init
└── views/                     # Blade views (layouts, auth, emails)

routes/
├── web.php                    # Main routes (auth + branch-scoped)
├── settings.php               # Settings routes (profile, security, payment guard)
└── api.php                    # API routes (POS charge, Paystack webhook)

tests/
├── Feature/                   # Feature tests (Pest)
├── Unit/                      # Unit tests (models)
└── Feature/Browser/           # Playwright browser journey tests (Pest browser plugin)

duowin-bridge/                 # Node.js serial port bridge
├── server.js
├── package.json
└── .env.example
```

## Running the Application

```bash
# Install dependencies
composer install
npm install

# Setup
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed

# Development
composer run dev          # Vite dev server
php artisan serve         # Laravel server
php artisan reverb:start  # Reverb WebSocket server

# Testing
php artisan test --compact

# Static Analysis
vendor/bin/phpstan analyse

# Code Style
vendor/bin/pint --dirty --format agent

# Duowin Bridge (optional)
cd duowin-bridge && npm install && npm start
```
