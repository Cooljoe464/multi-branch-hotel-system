# Hotspot (In-Hotel Guest Wi-Fi)

Per-guest auto-provisioned Wi-Fi with free + paid tiers, enforced by the
property MikroTik (one router per branch) against cloud-hosted FreeRADIUS
over a per-branch WireGuard tunnel. Company systems stay on an isolated
staff VLAN that guests cannot route to.

## Concepts

| Term | Meaning |
|---|---|
| **Tier** (`hotspot_tiers`) | Per-branch Wi-Fi product: name, code (`free` + paid codes), `price_minor`, up/down kbps, optional `quota_mb` / `duration_mins`, `device_limit`. Free tier is auto-included; paid tiers post to the folio. |
| **Selection** (`reservation_hotspots`) | Tier chosen for a reservation *before it completes* (front-desk create, booking wizard step 4, CRS, API). One row per reservation. |
| **Session** (`wifi_sessions`) | Per-stay credential (`voucher` = username + generated password), linked to tier + folio transaction, `provisioned_at` on check-in, revoked + `deprovisioned_at` on checkout/expiry. |
| **NAS** | The branch MikroTik. Exactly one per branch in V1 (no CAPsMAN). |

## Network isolation (the important part)

Each router carries two worlds that cannot reach each other:

- **VLAN 10 / `bridge-staff`** — `STAFF` PSK SSID, `10.10.0.0/24`. PMS terminals, POS, printers, door-lock gateways, PBX.
- **VLAN 30 / `bridge-guest`** — open `GUEST-WIFI` SSID + Hotspot, `10.30.0.0/21`. Guests only.
- **`wg-hms`** — WireGuard tunnel to cloud. Carries RADIUS (1812/1813) and RouterOS API. The router dials out, so CGNAT properties need no inbound ports.

The Hotspot instance binds **only** to `bridge-guest`. Firewall drops guest→RFC1918, guest↔staff, and guest→router-admin (winbox/API/SSH). Guests can reach DNS, the portal walled-garden, and RADIUS over the tunnel — nothing else.

Generate the per-branch script from live settings (no hand-editing routers):

```bash
php artisan hotspot:expire-sessions          # expiry sweeper (also scheduled every 15 min)
php artisan hotspot:export-rsc 3            # by branch ID
php artisan hotspot:export-rsc HTL-LOS      # by branch code
php artisan hotspot:export-rsc 3 --output=hotspot-b3.rsc
```

Or download it from **Hotspot → Download .rsc isolation export** (permission `hotspot.manage`). Import once per router with `/import hotspot-b<ID>.rsc`, then set the secrets below.

## Branch configuration (`branches.settings`)

| Key | Purpose |
|---|---|
| `staff_ssid` / `guest_ssid` | SSID names (defaults `STAFF` / `GUEST-WIFI`) |
| `staff_psk` | **Secret.** Staff WPA2 passphrase. Never commit. |
| `nas_secret` | **Secret.** RADIUS shared secret for this NAS (falls back to `cdr_secret`). |
| `radius_host` | Tunnel IP of cloud RADIUS (default `config/radius.host`) |
| `tunnel_ip` | This router's WireGuard address (marks NAS "configured" in UI) |
| `wireguard_private_key` / `wireguard_peer_public_key` / `wireguard_endpoint` | Tunnel identity |
| `portal_url` | Guest portal URL printed in the export |

## Guest lifecycle

1. **Select tier pre-complete** — tier picker on front-desk create, booking wizard step 4, CRS modal, or `hotspot_tier_id` on `POST /api/v1/reservations`. Paid tiers post a `wifi` debit to the open folio (room window, idempotent per reservation).
2. **Check-in** — `ProvisionWifiJob` (queue `network`) creates the session credential, mirrors the user to RADIUS (`Cleartext-Password`, `Session-Timeout` = checkout, rate-limit from tier), ensures the Hotspot user, fires `WifiIssued`. Fake/log-backed in V1, so check-in never blocks on tunnel outages.
3. **Stay** — guest joins `GUEST-WIFI`, Hotspot redirects to the portal, `POST /api/wifi/validate` returns tier + expiry (branch-scoped, optional `mac`/`nas_ip` logging).
4. **Checkout / expiry** — `WifiService::revokeForReservation` + `DeprovisionWifiJob` disable the RADIUS user, send CoA-disconnect, kick the active session. `hotspot:expire-sessions` (scheduled) sweeps past-expiry sessions.

Upgrades mid-stay: **Reservations → Show → Wi-Fi Tier** (permission `hotspot.issue`).

## Permissions (`hotspot` group)

`view, manage, issue, revoke, grant_free`. Global Admin gets all. Branch GM: all. Front Desk: `view, issue, revoke`. Cashier: `view` (paid upgrades post through folio permissions).

## Queues & scheduling

- Horizon supervisor `supervisor-network` serves queue `network` (tries 5, 120s timeout).
- `hotspot:expire-sessions` runs every 15 minutes (`routes/console.php`).

## Testing

`tests/Feature/HotspotTest.php` covers: free default with no charge, paid folio posting + idempotent re-select, check-in provision → checkout deprovision jobs, `.rsc` isolation assertions (hotspot only on guest bridge, RFC1918/staff drops, RADIUS + WireGuard present), portal branch-scoping + revoked rejection. RADIUS/MikroTik run fake in tests (`RADIUS_FAKE=true` default).

## Schema note (DBA)

`wifi_sessions.folio_transaction_id` and `reservation_hotspots.folio_transaction_id` are plain indexed columns with **no database FK** (see `drop_hotspot_folio_transaction_fks` migration). `transactions` converts to monthly RANGE partitions during a maintenance window (`db:partition-transactions`), which drops every inbound single-column FK — same precedent as the transfer self-reference. Folio integrity lives in `transactions` itself; these links are audit-only.

## What is NOT in V1 (deliberate)

- No multi-AP roaming/CAPsMAN, no staff EAP (PSK only), no agent/external voucher sales (in-hotel only).
- No live RADIUS DB writes yet — `RadiusService` logs and returns success until the DBA provisions `radius.connection` tables (`radcheck`/`radreply`/`radacct`).
- No bandwidth accounting ingestion — `bytes_used`/`last_acct_at` columns exist; the NAS→app accounting feed is the next milestone.
