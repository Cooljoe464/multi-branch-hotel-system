# Multi-Branch Hotel PMS — QA Test Workflow

**Version:** 1.0
**Date:** September 18, 2026
**Purpose:** Step-by-step test procedures for human testers to validate each module across all roles.

---

## How to Use This Document

1. Each tester is assigned one or more **roles** (e.g., Front Desk, Cashier).
2. Log in with the assigned role's test account.
3. Follow every step in order. Mark each step **PASS** or **FAIL**.
4. If a step fails, write the failure description in the **Notes** column and file a bug report.
5. A module passes only if **all** its steps pass.

---

## Test Accounts

| Role | Email | Default Branch |
|------|-------|----------------|
| Global Admin | admin@hotel.com | All branches |
| Property Owner | owner@hotel.com | All branches |
| Branch GM | gm@hotel.com | Main branch |
| Front Desk | frontdesk@hotel.com | Main branch |
| Housekeeper | housekeeper@hotel.com | Main branch |
| Kitchen Staff | kitchen@hotel.com | Main branch |
| Laundry Attendant | laundry@hotel.com | Main branch |
| Cashier | cashier@hotel.com | Main branch |
| Auditor | auditor@hotel.com | Main branch |

> **Note:** Confirm these accounts exist in the database seeder before testing. If not, create them via `php artisan db:seed`.

---

## Pre-Test Checklist

- [ ] Application is running (`php artisan serve` or equivalent)
- [ ] Database is seeded (`php artisan db:seed`)
- [ ] All migrations have run (`php artisan migrate`)
- [ ] Frontend assets are compiled (`npm run build`)
- [ ] A valid room exists in the test branch (e.g., Room 101)
- [ ] A valid guest exists (e.g., John Doe, john@example.com)
- [ ] A confirmed reservation exists for the test guest
- [ ] At least one outlet exists (e.g., Restaurant, Bar)
- [ ] At least one menu item exists with a price
- [ ] Browser console has no JavaScript errors on page load

---

## Module 1: Authentication & Profile

**Applies to:** All roles

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 1.1 | Navigate to `/login` | Login form renders with email and password fields | | |
| 1.2 | Enter valid credentials and submit | Redirected to `/dashboard` | | |
| 1.3 | Verify sidebar shows correct modules for your role | Only permitted modules appear | | |
| 1.4 | Click your username/avatar in the top-right | Profile dropdown appears | | |
| 1.5 | Click "Profile" | Profile page loads with your name and email | | |
| 1.6 | Update your name and click Save | Success toast appears, name is updated | | |
| 1.7 | Click "Security" in settings | Password change form appears | | |
| 1.8 | Enter current password and new password, save | Success toast appears | | |
| 1.9 | Log out and log in with the new password | Login succeeds with new password | | |
| 1.10 | Try accessing a URL you don't have permission for (e.g., `/admin/users` as Front Desk) | 403 error or redirect | | |

---

## Module 2: Dashboard

**Applies to:** All roles

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 2.1 | Navigate to `/dashboard` | Dashboard loads with stats cards | | |
| 2.2 | Verify stats display: Occupied rooms, Revenue, Occupancy % | Numbers are non-negative and reasonable | | |
| 2.3 | Check the date range filter (if present) | Changing dates updates the stats | | |
| 2.4 | Verify the quick-actions section (if present) | Links navigate to correct modules | | |

---

## Module 3: Reservations

**Applies to:** Global Admin, Property Owner, Branch GM, Front Desk

### 3A: Create Reservation

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 3.1 | Navigate to `/reservations` | Reservation list loads with table | | |
| 3.2 | Click "New Reservation" button | Create form loads | | |
| 3.3 | Fill in guest name, email, phone | Fields accept input | | |
| 3.4 | Select a room type | Room type is selected | | |
| 3.5 | Select check-in and check-out dates | Dates are valid (check-out after check-in) | | |
| 3.6 | Select a rate plan | Rate plan is selected, total amount calculates | | |
| 3.7 | Submit the form | Success toast, redirect to reservation show page | | |
| 3.8 | Verify confirmation number is displayed (e.g., `RES-XXXXXX`) | Confirmation number is shown | | |

### 3B: Check-In

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 3.9 | Open a confirmed reservation | Show page loads with status "confirmed" | | |
| 3.10 | Click "Check In" button | Confirmation dialog or inline action | | |
| 3.11 | Confirm check-in | Status changes to "checked_in", room status changes to "occupied" | | |
| 3.12 | Verify room status on `/rooms` page | Room shows as "occupied" | | |

### 3C: Check-Out

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 3.13 | Open a checked-in reservation | Show page loads with status "checked_in" | | |
| 3.14 | Click "Check Out" button | Confirmation dialog | | |
| 3.15 | Confirm check-out | Status changes to "checked_out", room status changes to "cleaning" | | |
| 3.16 | Verify folio is created | Folio page shows charges and balance | | |

### 3D: Cancel Reservation

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 3.17 | Open a confirmed reservation (Branch GM or Admin only) | Show page loads | | |
| 3.18 | Click "Cancel" button | Confirmation dialog | | |
| 3.19 | Confirm cancellation | Status changes to "cancelled" | | |

### 3E: Edit Reservation

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 3.20 | Open a reservation and click "Edit" | Edit form loads with current data | | |
| 3.21 | Change the check-out date | Date field updates | | |
| 3.22 | Save changes | Success toast, dates are updated | | |

### 3F: Tape Chart

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 3.23 | Navigate to `/tape-chart` | Tape chart grid loads | | |
| 3.24 | Verify rooms are displayed with status colors | Occupied rooms show different color than available | | |
| 3.25 | Click on an available room slot | Quick reservation form or detail appears | | |

### 3G: Cross-Branch Search (Global Admin / Property Owner only)

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 3.26 | Navigate to `/reservations/cross-branch-search` | Search form loads | | |
| 3.27 | Search by guest name or confirmation number across branches | Results from multiple branches appear | | |

---

## Module 4: Rooms

**Applies to:** Global Admin, Property Owner, Branch GM, Front Desk, Housekeeper, Laundry Attendant

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 4.1 | Navigate to `/rooms` | Room list loads with table | | |
| 4.2 | Verify room numbers, types, and statuses display | All fields populated correctly | | |
| 4.3 | Filter rooms by status (e.g., "available") | Table filters to show only matching rooms | | |
| 4.4 | Click a room to view details | Room detail page loads | | |
| 4.5 | Update room status (e.g., mark as "cleaning" then "available") | Status updates with success toast | | |
| 4.6 | (Admin only) Create a new room | Room form loads, fill in number/type/floor, save | Room appears in list | |
| 4.7 | (Admin only) Edit room details | Edit form loads, change room type, save | Changes reflected | |
| 4.8 | (Admin only) Delete a room | Confirmation dialog, confirm delete | Room removed from list | |

---

## Module 5: Guests

**Applies to:** Global Admin, Property Owner, Branch GM, Front Desk

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 5.1 | Navigate to guest search or list | Guest list/search loads | | |
| 5.2 | Search for a guest by name | Matching guests appear | | |
| 5.3 | Click a guest to view profile | Guest profile page loads with contact info and reservation history | | |
| 5.4 | Verify VIP status badge displays correctly | Badge shows VIP level or "none" | | |
| 5.5 | Edit guest information (name, email, phone) | Fields are editable, save succeeds | | |
| 5.6 | Create a new guest | Guest form loads, fill required fields, save | Guest appears in list | |

---

## Module 6: Housekeeping

**Applies to:** Global Admin, Property Owner, Branch GM, Front Desk, Housekeeper

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 6.1 | Navigate to `/housekeeping` | Task list loads | | |
| 6.2 | Verify tasks show room number, status, and assigned staff | All fields populated | | |
| 6.3 | (Front Desk/GM) Create a new housekeeping task for a room | Task form loads, select room, assign staff, save | Task appears in list | |
| 6.4 | (Housekeeper) Start a task | Task status changes to "in_progress" | | |
| 6.5 | (Housekeeper) Complete a task | Task status changes to "completed" | | |
| 6.6 | Verify mobile view (`/housekeeping/mobile`) | Mobile-optimized task list loads | | |
| 6.7 | (Housekeeper) Pull to refresh on mobile | Task list updates | | |

---

## Module 7: Maintenance

**Applies to:** Global Admin, Property Owner, Branch GM, Front Desk

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 7.1 | Navigate to `/maintenance` | Ticket list loads | | |
| 7.2 | Click "New Ticket" | Ticket creation form loads | | |
| 7.3 | Fill in title, description, priority, room | Fields accept input | | |
| 7.4 | Submit the ticket | Success toast, ticket appears in list with status "open" | | |
| 7.5 | Open the ticket and click "Start Work" | Status changes to "in_progress" | | |
| 7.6 | Click "Complete" | Status changes to "completed" | | |
| 7.7 | If room is linked, click "Lock Room" | Room becomes locked for maintenance | | |
| 7.8 | Click "Unlock Room" | Room becomes available again | | |
| 7.9 | Delete a ticket (GM/Admin only) | Ticket removed from list | | |

---

## Module 8: Folios & Billing

**Applies to:** Global Admin, Property Owner, Branch GM, Front Desk, Cashier

### 8A: Folio Management

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 8.1 | Navigate to `/folios` | Folio list loads | | |
| 8.2 | Open a folio | Folio detail loads with transactions | | |
| 8.3 | Verify transaction table shows date, type, description, debit, credit | All columns populated | | |
| 8.4 | Verify balance calculation | Balance = sum of debits - sum of credits | | |
| 8.5 | Post a charge to the folio | Charge form loads, enter description and amount, save | Transaction appears | |
| 8.6 | Record a payment | Payment form loads, enter amount and method, save | Balance decreases | |
| 8.7 | Print the folio (Bill page) | `/folios/{id}/bill` loads with print-friendly layout | | |

### 8B: Cashier Operations

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 8.8 | Log in as Cashier, navigate to `/pos` | POS outlet list shows only assigned outlets | | |
| 8.9 | Click an outlet to open the terminal | POS terminal loads with menu items | | |
| 8.10 | Add items to the order | Items appear in the order list with correct prices | | |
| 8.11 | Adjust quantity or remove an item | Order total updates correctly | | |
| 8.12 | Post charge to a room (reservation mode) | Select reservation, confirm, charge posted to folio | | |
| 8.13 | View folio from Cashier role | Navigate to `/folios`, open the linked folio, verify charge appears | | |

---

## Module 9: Food & Beverage — KDS

**Applies to:** Global Admin, Property Owner, Branch GM, Kitchen Staff

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 9.1 | Navigate to `/kds` | Kitchen display loads with pending orders | | |
| 9.2 | Verify order items, quantities, and special instructions | All details displayed | | |
| 9.3 | Mark an item as "preparing" | Status updates on the display | | |
| 9.4 | Mark an item as "ready" | Status updates, color changes to green | | |
| 9.5 | Verify stock toggle on menu items | Toggle availability on `/menu-items`, item disappears/reappears on POS | | |

---

## Module 10: Food & Beverage — Menu Items & Outlets

**Applies to:** Global Admin, Property Owner, Branch GM (manage); Front Desk, Kitchen Staff (view)

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 10.1 | Navigate to `/menu-items` | Menu item list loads | | |
| 10.2 | Click "New Menu Item" (manage roles only) | Creation form loads | | |
| 10.3 | Fill in name, price (in cents), category, outlet | Fields accept input | | |
| 10.4 | Save the menu item | Success toast, item appears in list | | |
| 10.5 | Edit a menu item | Edit form loads, change price, save | Price updated | |
| 10.6 | Navigate to `/outlets` | Outlet list loads | | |
| 10.7 | Create a new outlet (manage roles only) | Outlet form loads, fill name/type/code, save | Outlet appears | | |
| 10.8 | Assign a cashier to an outlet (`/settings/cashier-outlets`) | Cashier outlet assignment page loads | | |
| 10.9 | Check a cashier's outlet checkbox and save | Cashier can now see that outlet in POS | | |

---

## Module 11: Food & Beverage — Inventory

**Applies to:** Global Admin, Property Owner, Branch GM, Front Desk, Kitchen Staff (view); manage for GM/Admin/Owner

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 11.1 | Navigate to `/inventory` | Inventory list loads | | |
| 11.2 | Verify item names, quantities, and unit | All fields populated | | |
| 11.3 | Create a new inventory item (manage only) | Form loads, fill name/quantity/unit, save | Item appears | |
| 11.4 | Restock an item | Restock form, enter quantity, save | Quantity increases | |
| 11.5 | Transfer stock between outlets (GM/Admin only) | Transfer form loads, select source/destination/quantity, save | Stock adjusts | | |

---

## Module 12: Laundry

**Applies to:** Global Admin, Property Owner, Branch GM, Front Desk, Laundry Attendant

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 12.1 | Navigate to `/laundry` | Laundry order list loads | | |
| 12.2 | Create a new laundry order (Front Desk/GM) | Order form loads, select room/reservation, add items, save | Order appears with status "pending" | |
| 12.3 | Mark order as "picked up" (Laundry Attendant) | Status changes to "picked_up" | | |
| 12.4 | Mark order as "delivered" | Status changes to "delivered" | | |
| 12.5 | Open delivered order and verify items | Items and charges displayed correctly | | |

---

## Module 13: Channels & CRS

**Applies to:** Global Admin, Property Owner, Branch GM

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 13.1 | Navigate to `/channels` | Channel provider list loads | | |
| 13.2 | Click "Sync Rates" on a channel provider | Sync process initiates, success toast with count | | |
| 13.3 | Click "Pull Reservations" | Pull process initiates, reservations imported | | |
| 13.4 | Navigate to `/crs` | Central Reservations list loads | | |
| 13.5 | Create a CRS reservation | Form loads, fill details, save | Reservation created with confirmation number | |

---

## Module 14: City Ledger

**Applies to:** Global Admin, Property Owner, Branch GM

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 14.1 | Navigate to `/city-ledger` | City ledger account list loads | | |
| 14.2 | Click "New Account" | Account creation form loads | | |
| 14.3 | Fill in company name, contact, email, credit limit, payment terms | Fields accept input | | |
| 14.4 | Save the account | Success toast, account appears in list | | |
| 14.5 | Open the account | Account detail page loads with balance and transactions | | |
| 14.6 | Post a charge | Charge form loads, enter amount, save | Balance increases | |
| 14.7 | Record a payment | Payment form loads, enter amount, save | Balance decreases | |
| 14.8 | View statement (`/city-ledger/{id}/statement`) | Statement page loads with all transactions | | |

---

## Module 15: Revenue Management

**Applies to:** Global Admin, Property Owner, Branch GM

### 15A: Yield Rules

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 15.1 | Navigate to `/yield-rules` | Yield rule list loads | | |
| 15.2 | Create a new yield rule | Form loads, fill occupancy range/adjustment %, save | Rule appears | |
| 15.3 | Toggle a rule active/inactive | Badge changes between "Active" and "Inactive" | | |
| 15.4 | Edit a rule | Edit form loads, change values, save | Changes reflected | |
| 15.5 | Delete a rule | Confirmation dialog, confirm | Rule removed | |

### 15B: Rate Plans & Overrides

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 15.6 | Navigate to `/settings/rate-plans` | Rate plan list loads | | |
| 15.7 | Create a rate plan | Form loads, fill name/code/type/multiplier/dates, save | Plan appears | |
| 15.8 | Navigate to `/rate-overrides` | Rate override list loads | | |
| 15.9 | Create a rate override for a specific date | Form loads, select room type/date/price, save | Override appears | |

---

## Module 16: Analytics & Reports

**Applies to:** Global Admin, Property Owner, Branch GM, Auditor

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 16.1 | Navigate to `/analytics` | Analytics dashboard loads | | |
| 16.2 | Verify occupancy chart, revenue chart, ADR | Charts render with data | | |
| 16.3 | Navigate to `/reports` | Reports page loads | | |
| 16.4 | View Group Ledger report | Group ledger list loads | | |
| 16.5 | Open a group ledger entry | Detail page loads with financial data | | |
| 16.6 | Export a report (Night Audit / Financial) | CSV/PDF download initiates | | |
| 16.7 | Navigate to `/reports/pnl` | P&L report loads with revenue and expense categories | | |
| 16.8 | Navigate to `/reports/channel-yield` | Channel yield report loads | | |
| 16.9 | Navigate to `/reports/tax-liability` | Tax liability report loads | | |

---

## Module 17: Audit Flags

**Applies to:** Global Admin, Branch GM (view only), Auditor (manage)

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 17.1 | Navigate to `/audit/flags` | Audit flag list loads | | |
| 17.2 | Verify flags show transaction details and status | All fields populated | | |
| 17.3 | (Auditor) Review a flag | Flag status changes to "reviewed" | | |
| 17.4 | (Auditor) Suppress a flag | Flag status changes to "suppressed" | | |
| 17.5 | Verify GM can view but not review/suppress | Review/Suppress buttons are hidden or disabled | | |

---

## Module 18: Administration

**Applies to:** Global Admin (full); Property Owner, Branch GM (limited)

### 18A: Branch Management

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 18.1 | (Admin) Navigate to `/admin/branches/create` | Branch creation form loads | | |
| 18.2 | Fill in branch name, code, address, phone | Fields accept input | | |
| 18.3 | Save the branch | Success toast, branch created | | |
| 18.4 | Switch branches from the sidebar dropdown | Branch switches, data refreshes for new branch | | |

### 18B: User Management

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 18.5 | Navigate to `/admin/users` | User list loads | | |
| 18.6 | Click "Add User" | User creation form loads | | |
| 18.7 | Fill in name, email, password, select role and branch | Fields accept input | | |
| 18.8 | Save the user | Success toast, user appears in list | | |
| 18.9 | Edit a user's role or branch | Edit form loads, change role, save | Role updated | |
| 18.10 | Deactivate/delete a user (Admin only) | Confirmation dialog, confirm | User removed | |

### 18C: Roles & Permissions (Admin only)

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 18.11 | Navigate to `/admin/roles` | Role list loads with permission counts | | |
| 18.12 | Open a role | Role detail shows all assigned permissions | | |
| 18.13 | Assign a permission to a role | Checkbox toggles, save succeeds | | |

### 18D: Data Import (Admin only)

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 18.14 | Navigate to `/admin/import/rooms` | Room import page loads with CSV upload | | |
| 18.15 | Upload a valid CSV of rooms | Rooms are imported, success message | | |
| 18.16 | Navigate to `/admin/import/guests` | Guest import page loads | | |
| 18.17 | Upload a valid CSV of guests | Guests are imported | | |
| 18.18 | Navigate to `/admin/import/reservations` | Reservation import page loads | | |
| 18.19 | Upload a valid CSV of reservations | Reservations are imported | | |

---

## Module 19: Settings

**Applies to:** Global Admin, Auditor (manage); others view-only where permitted

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 19.1 | Navigate to `/settings/payment-guard` | Payment guard policy page loads (Admin/Auditor only) | | |
| 19.2 | Update the payment guard policy | Policy updated, success toast | | |
| 19.3 | Navigate to `/settings/branding` | Branding settings page loads (Admin only) | | |
| 19.4 | Upload a logo | Logo uploads and displays | | |
| 19.5 | Remove the logo | Logo removed, default displays | | |
| 19.6 | Navigate to `/settings/appearance` | Appearance settings load (all authenticated users) | | |
| 19.7 | Toggle dark mode | Theme switches between light and dark | | |

---

## Module 20: Guest Portal (Public)

**Applies to:** No login required — test in incognito/private browser

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 20.1 | Navigate to `/book` | Booking engine loads with search form | | |
| 20.2 | Select dates and search for availability | Available room types display with prices | | |
| 20.3 | Select a room type and proceed to booking form | Guest details form loads | | |
| 20.4 | Fill in guest details and submit | Reservation created, confirmation page loads | | |
| 20.5 | Navigate to `/guest/folio/{confirmation}` | Guest folio loads with reservation details | | |
| 20.6 | Navigate to `/guest/order/{confirmation}` | Guest ordering page loads with outlet list | | |
| 20.7 | Select an outlet and add items to cart | Items appear in cart with correct prices | | |
| 20.8 | Place the order | Success message, order appears in KDS | | |
| 20.9 | Navigate to `/guest/order/{confirmation}/track/{orderId}` | Order tracking page loads with status steps | | |
| 20.10 | Navigate to `/guest/payment/{confirmation}` | Guest payment page loads | | |
| 20.11 | Submit a payment | Payment processes, folio balance updates | | |

---

## Module 21: Tablet Ordering (Public)

**Applies to:** No login required — test on tablet device or emulated viewport

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 21.1 | Navigate to `/tablet/{confirmation}/menu` | Tablet menu loads with categories and items | | |
| 21.2 | Browse categories by clicking category tabs | Menu items filter by selected category | | |
| 21.3 | Add items to cart | Cart badge updates, items appear in cart panel | | |
| 21.4 | Adjust quantities in the cart | Total updates correctly | | |
| 21.5 | Place the order | Success message, order sent to KDS | | |
| 21.6 | Track the order on `/tablet/{confirmation}/track/{orderId}` | Status steps display, updates in real-time via WebSocket | | |
| 21.7 | (Admin) Pair a tablet via `/tablet/pair` | Tablet paired to a room | | |
| 21.8 | (Admin) Wipe a tablet session via `/tablet/wipe` | Session cleared, tablet returns to pairing state | | |

---

## Module 22: Branding & Appearance

**Applies to:** Global Admin

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 22.1 | Navigate to `/settings/branding` | Branding settings page loads | | |
| 22.2 | Upload a hotel logo | Logo preview updates | | |
| 22.3 | Save branding settings | Success toast, logo visible in sidebar/header | | |
| 22.4 | Remove the logo | Default branding restored | | |

---

## Module 23: Notifications & Real-Time

**Applies to:** All roles (where applicable)

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 23.1 | Open KDS in one browser tab | KDS page loads | | |
| 23.2 | Place an order from POS in another tab | Order appears on KDS in real-time without page refresh | | |
| 23.3 | Update order status on KDS | Status updates reflect on tracking page in real-time | | |
| 23.4 | Verify toast notifications appear on successful actions | Green success toasts display and auto-dismiss | | |
| 23.5 | Verify error toasts appear on failed actions | Red error toasts display with error message | | |

---

## Module 24: RBAC — Permission Denial Verification

**Applies to:** Each role tests their own restrictions

For each role below, log in and attempt the restricted actions. Verify they are blocked.

### Front Desk Restrictions

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 24.1 | Try to delete a reservation | Button hidden or 403 error | | |
| 24.2 | Try to access `/admin/users` | 403 error or redirect | | |
| 24.3 | Try to manage yield rules (`/yield-rules`) | Page not visible in sidebar, direct URL returns 403 | | |
| 24.4 | Try to access `/settings/payment-guard` | 403 error | | |

### Housekeeper Restrictions

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 24.5 | Try to access `/reservations` | 403 error or page not in sidebar | | |
| 24.6 | Try to create a reservation | Button hidden or 403 error | | |
| 24.7 | Try to access `/folios` | 403 error or page not in sidebar | | |

### Kitchen Staff Restrictions

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 24.8 | Try to access `/reservations` | 403 error or page not in sidebar | | |
| 24.9 | Try to access `/folios` | 403 error or page not in sidebar | | |
| 24.10 | Try to manage menu items (create/edit) | Button hidden or 403 error | | |

### Laundry Attendant Restrictions

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 24.11 | Try to access `/reservations` | 403 error or page not in sidebar | | |
| 24.12 | Try to access `/folios` | 403 error or page not in sidebar | | |
| 24.13 | Try to access `/kds` | 403 error or page not in sidebar | | |

### Cashier Restrictions

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 24.14 | Try to access `/reservations` | 403 error or page not in sidebar | | |
| 24.15 | Try to manage rooms (`/rooms` create/edit) | Button hidden or 403 error | | |
| 24.16 | Try to access `/admin/users` | 403 error | | |

### Auditor Restrictions

| Step | Action | Expected Result | Pass/Fail | Notes |
|------|--------|----------------|-----------|-------|
| 24.17 | Try to access `/reservations` | 403 error or page not in sidebar | | |
| 24.18 | Try to create a reservation | Button hidden or 403 error | | |
| 24.19 | Try to access `/rooms` | 403 error or page not in sidebar | | |
| 24.20 | Try to access `/kds` | 403 error or page not in sidebar | | |

---

## Sign-Off

| Tester Name | Role Tested | Date | Modules Passed | Modules Failed | Signature |
|-------------|-------------|------|----------------|----------------|-----------|
| | | | /24 | /24 | |
| | | | /24 | /24 | |
| | | | /24 | /24 | |
| | | | /24 | /24 | |

---

## Bug Report Template

When a test step fails, file a bug report with:

```
**Title:** [Module Name] — Brief description of failure
**Role:** [Role used during test]
**Step:** [Step number from this document]
**Environment:** Browser, OS, screen size
**Steps to Reproduce:**
1. ...
2. ...
3. ...
**Expected Result:** [What should happen]
**Actual Result:** [What actually happened]
**Screenshots/Video:** [Attach if possible]
**Severity:** Critical / High / Medium / Low
```
