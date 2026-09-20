# Usability Test Plan — Multi-Branch Hotel Management System

This document defines usability test scenarios for real human users to observe interactions, measure task completion, and validate workflows across all modules of the hotel system.

---

## How to Run These Tests

- **Participants:** 1–2 users per role (Front Desk, Housekeeper, Kitchen Staff, etc.)
- **Setup:** Fresh browser, production-like data (reservations, rooms, folios pre-seeded)
- **Method:** Think-aloud protocol — user narrates what they're doing and why
- **Measure:** Task completion (yes/no), time to complete, error count, help needed
- **Record:** Screen recording + observer notes

---

## 1. Authentication & Branch Access

### 1.1 Login Flow
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Navigate to `/login` | Login form with email + password fields | Is the form clear? Any confusion? |
| 2 | Enter valid credentials, click "Log in" | Redirect to `/dashboard` | How fast is the redirect? |
| 3 | Enter wrong password, click "Log in" | Error message displayed on same page | Is the error message clear? |
| 4 | Click logout menu → "Log out" | Redirect to login page | Is logout easy to find? |

### 1.2 Branch Switching
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Log in as Global Admin (multi-branch access) | Dashboard loads | |
| 2 | Click branch switcher in sidebar | List of assigned branches appears | Is the switcher discoverable? |
| 3 | Select a different branch | Dashboard reloads with new branch context | Does the user understand the branch changed? |
| 4 | Verify data shows new branch's data | Reservations, rooms, etc. reflect new branch | |

---

## 2. Dashboard

### 2.1 Dashboard Overview
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Log in as Branch GM | Dashboard with revenue stats, occupancy, arrivals/departures | |
| 2 | Identify today's occupancy rate | Number displayed clearly | Can user find it in <5 seconds? |
| 3 | Identify arrivals today | Count shown | |
| 4 | Identify departures today | Count shown | |
| 5 | Click "Reservations" from dashboard | Navigate to reservations list | Is the path intuitive? |

---

## 3. Reservation Management

### 3.1 Create a Reservation (Front Desk)
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Navigate to Reservations → "New Reservation" | Create form loads | |
| 2 | Select a guest (or enter new guest details) | Guest info populated | Is guest search fast? |
| 3 | Select room type from dropdown | Room type selected | |
| 4 | Pick check-in and check-out dates | Dates selected on calendar | Is date picker intuitive? |
| 5 | Select an available room | Room assigned | |
| 6 | Click "Create Reservation" | Reservation created, redirected to detail page | |
| 7 | Verify confirmation number displayed | Unique confirmation number shown | |

### 3.2 Check-In a Guest
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Open a "Confirmed" reservation | Reservation detail page loads | |
| 2 | Click "Check In" | Prompt for room selection (if not pre-assigned) | |
| 3 | Select room from available list | Room selected | |
| 4 | Confirm check-in | Status changes to "Checked In", room status → "Occupied" | |
| 5 | Verify registration card available | Print/view registration card | |

### 3.3 Check-Out a Guest
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Open a "Checked In" reservation | Reservation detail shows folio balance | |
| 2 | Click "Check Out" | Confirmation dialog appears | |
| 3 | Confirm check-out | Status → "Checked Out", room → "Dirty" | |
| 4 | Verify folio is settled | Folio shows $0 balance or payment recorded | |

### 3.4 Cancel a Reservation
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Open a "Confirmed" reservation | Detail page loads | |
| 2 | Click "Cancel" | Confirmation dialog appears | |
| 3 | Confirm cancellation | Status → "Cancelled", room released | |
| 4 | Verify room available again | Room appears in available list | |

### 3.5 Tape Chart Navigation
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Navigate to Tape Chart | Visual grid of rooms × dates loads | |
| 2 | Scroll horizontally across dates | Smooth scroll, dates visible | |
| 3 | Click on a reservation bar | Opens reservation detail | |
| 4 | Identify room status colors (occupied, dirty, available) | Color coding is clear | Can user distinguish statuses at a glance? |

---

## 4. Room Management

### 4.1 Room List & Status
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Navigate to Rooms | List of rooms with status badges | |
| 2 | Filter by status (Available, Occupied, Dirty, Maintenance) | List filters correctly | Is filter easy to find? |
| 3 | Click on a room | Room detail/edit modal opens | |
| 4 | Change room status to "Maintenance" | Status updated | |

### 4.2 Housekeeping Tasks
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Log in as Housekeeper | Limited sidebar (Rooms, Housekeeping only) | |
| 2 | Navigate to Housekeeping | Task list with dirty rooms | |
| 3 | Click "Start Cleaning" on a room | Task status → "In Progress" | |
| 4 | Complete cleaning, mark as "Clean" | Room status → "Clean" | |
| 5 | Verify room appears as available for check-in | | |

---

## 5. Folio & Financial Operations

### 5.1 Post a Charge
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Open a folio for a checked-in guest | Folio detail page loads | |
| 2 | Click "Post Charge" | Modal with category, description, amount fields | |
| 3 | Select category (e.g., Minibar) | Category selected | |
| 4 | Enter description and amount | Fields accept input | |
| 5 | Click "Post Charge" button | Transaction recorded, balance updated | |
| 6 | Verify charge appears in transaction list | Debit entry visible | |

### 5.2 Record a Payment
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Open folio with outstanding balance | Balance displayed in red | |
| 2 | Click "Record Payment" | Modal with payment method, amount, reference | |
| 3 | Select payment method (Cash/Card/Online) | Method selected | |
| 4 | Enter amount and reference | Fields accept input | |
| 5 | Click "Record Payment" | Transaction recorded, balance reduced | |
| 6 | Verify balance reflects payment | |

### 5.3 Create a Child Folio
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Open a master folio | Detail page loads | |
| 2 | Click "Create Child Folio" | Modal with description field | |
| 3 | Enter description (e.g., "Corporate bill") | | |
| 4 | Click "Create" | Child folio created, listed under master | |
| 5 | Verify parent-child relationship displayed | | |

### 5.4 Checkout a Settled Folio
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Open a folio with $0 balance | "Checkout" button visible (green) | |
| 2 | Click "Checkout" | Confirmation dialog | |
| 3 | Confirm | Folio status → "Closed" | |
| 4 | Verify folio no longer editable | | |

---

## 6. Guest-Facing Booking Engine

### 6.1 Public Booking Flow
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Navigate to `/book` | Booking page with property selector | Is the page inviting? |
| 2 | Select a property from dropdown | Property selected | |
| 3 | Pick check-in and check-out dates | Dates selected | |
| 4 | Click "Search Availability" | Available room types displayed | |
| 5 | Select a room type | Room type highlighted | |
| 6 | Fill in guest details (name, email, phone) | Fields accept input | |
| 7 | Click "Review Booking" | Summary page with pricing | |
| 8 | Accept terms and conditions | Checkbox checked | |
| 9 | Click "Complete Booking" | Confirmation page with booking reference | |
| 10 | Verify confirmation email concept | Reference number shown | |

### 6.2 Guest Folio Lookup
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Navigate to `/guest/folio/{confirmation_number}` | Guest folio page loads | |
| 2 | Verify guest name and reservation details | Correct data displayed | |
| 3 | Verify charges and payments listed | Transaction history visible | |

---

## 7. Kitchen Display System (KDS)

### 7.1 Kitchen Order Processing
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Log in as Kitchen Staff | KDS view loads (limited sidebar) | |
| 2 | View pending orders | Orders appear in queue | |
| 3 | Click "Start Preparing" on an order | Status → "Preparing" | |
| 4 | Mark order as "Ready" | Status → "Ready" | |
| 5 | Verify order moves to "Ready" section | Real-time update via WebSocket | |

### 7.2 Menu Item Stock Toggle
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | In KDS, find a menu item | Item listed with stock status | |
| 2 | Toggle stock off (86'd) | Item marked unavailable | |
| 3 | Verify tablet/POS reflects unavailability | Real-time update | |

---

## 8. POS Terminal

### 8.1 Create an Outlet Order
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Log in as Cashier | POS terminal view loads | |
| 2 | Select outlet (e.g., Restaurant) | Outlet context set | |
| 3 | Add items to order | Items appear in order list | |
| 4 | Apply payment (Cash/Card) | Payment recorded | |
| 5 | Complete transaction | Order closed, receipt concept | |

---

## 9. Role-Based Access Control

### 9.1 Front Desk — Restricted Pages
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Log in as Front Desk | Dashboard loads | |
| 2 | Try to navigate to `/analytics` | Forbidden (403) or redirect | Is the restriction clear? |
| 3 | Try to navigate to `/reports` | Forbidden | |
| 4 | Try to navigate to `/yield-rules` | Forbidden | |
| 5 | Try to navigate to `/admin/branches/create` | Forbidden | |

### 9.2 Housekeeper — Limited Access
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Log in as Housekeeper | Only Rooms + Housekeeping in sidebar | |
| 2 | Verify no Folios, Analytics, Reports links | Sidebar is minimal | Is it confusing or clear? |
| 3 | Manually type `/folios` in URL | Forbidden | |

### 9.3 Kitchen Staff — KDS Only
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Log in as Kitchen Staff | KDS + Menu Items + Inventory in sidebar | |
| 2 | Verify no Reservations, Rooms, Folios links | | |
| 3 | Navigate to KDS | Kitchen order queue loads | |

### 9.4 Auditor — Read-Only Access
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Log in as Auditor | Audit + Reports + Analytics + Settings in sidebar | |
| 2 | Navigate to Audit Flags | Flag list loads | |
| 3 | Try to modify a setting | Should be read-only or restricted | |

---

## 10. Mobile / Tablet Responsiveness

### 10.1 Housekeeping Mobile View
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Open housekeeping page on mobile device | Layout adapts to narrow screen | |
| 2 | Scroll through task list | Smooth scroll, touch-friendly buttons | |
| 3 | Tap "Start Cleaning" | Button responsive to touch | |
| 4 | Complete a task | Status updates correctly | |

### 10.2 Tablet Ordering (Guest Kiosk)
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Open tablet ordering SPA on tablet device | Full-screen kiosk interface | |
| 2 | Browse menu categories | Menu items load with images | |
| 3 | Add items to cart | Cart updates | |
| 4 | Submit order | Order sent to KDS | |
| 5 | Verify order appears in kitchen | Real-time WebSocket update | |

---

## 11. Settings & Configuration

### 11.1 Profile Settings
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Navigate to Settings → Profile | Profile form loads | |
| 2 | Update name | Field accepts change | |
| 3 | Save changes | Success notification | |

### 11.2 Branch Settings
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Log in as Global Admin | | |
| 2 | Navigate to Settings | Settings page loads | |
| 3 | View payment guard settings | Pre-pay / Post-pay toggle visible | |
| 4 | Change payment guard mode | Setting saved | |

---

## 12. Error States & Edge Cases

### 12.1 Double Booking Prevention
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Create a reservation for Room 101, Jan 10–15 | Created successfully | |
| 2 | Try to create another reservation for Room 101, Jan 12–16 | Error: room not available | Is the error message clear? |
| 3 | Verify the conflicting dates are shown | | |

### 12.2 Checkout with Outstanding Balance
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Open a folio with $5,000 balance | Balance displayed | |
| 2 | Try to checkout | Error: balance not settled | |
| 3 | Verify checkout button is disabled or error shown | | |

### 12.3 Empty States
| Step | Action | Expected | Observe |
|------|--------|----------|---------|
| 1 | Navigate to Reservations with no data | "No reservations found" message | Is the empty state helpful? |
| 2 | Navigate to Reports with no data | Appropriate empty state | |
| 3 | Search for non-existent guest | "No results" message | |

---

## 13. Real-World Workflow Scenarios

### Scenario A: Guest Arrival to Departure
1. Guest calls to book → Front Desk creates reservation
2. Guest arrives → Front Desk checks in guest, assigns room
3. Guest uses minibar → Charge posted to folio
4. Guest dines at restaurant → POS charge posted to folio
5. Guest requests checkout → Front Desk settles folio, checks out
6. Room marked dirty → Housekeeper cleans → Room available

**Observe:** End-to-end time, number of clicks, any friction points

### Scenario B: Group Booking
1. Create master folio for group
2. Create child folios for each guest
3. Post charges to individual child folios
4. Transfer charges between folios
5. Settle all folios at checkout

**Observe:** Can user manage multiple folios without confusion?

### Scenario C: Night Audit
1. Auditor logs in at end of day
2. Reviews audit flags (rate overrides, voided transactions)
3. Resolves or suppresses flags
4. Runs night audit report
5. Verifies daily ledger closure

**Observe:** Is the audit flow logical? Any steps missing?

---

## Metrics to Record

| Metric | How to Measure |
|--------|----------------|
| Task completion rate | % of users who complete each scenario without help |
| Time on task | Stopwatch from start to successful completion |
| Error rate | Number of wrong clicks, failed attempts, incorrect data |
| Help requests | Number of times user asks observer for help |
| Satisfaction (optional) | Post-test Likert scale (1–5) per module |
| Discoverability | Can user find the feature without guidance? |

---

## Post-Test Questions

After each session, ask the participant:

1. What was the easiest part of the system to use?
2. What was the most confusing or frustrating part?
3. Were there any features you expected to find but couldn't locate?
4. How would you rate the overall usability? (1–5)
5. What one improvement would make the biggest difference?
