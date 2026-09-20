# User Acceptance Testing (UAT) Plan — Multi-Branch Hotel Management System

This document defines the UAT test cases for stakeholders and QA to verify that the system meets business requirements and is production-ready.

---

## Prerequisites

- Test database seeded with: 2 branches, 10+ rooms per branch, room types, rate plans, sample reservations in various statuses, folios with transactions, menu items, outlets, inventory items
- Test users created for each role with valid credentials
- Browser: Chrome or Edge (latest)
- Screen recording enabled for audit trail

## Test Users

| Role | Email | Password |
|------|-------|----------|
| Global Admin | admin@hotel.com | password |
| Property Owner | owner@hotel.com | password |
| Branch GM | gm@hotel.com | password |
| Front Desk | frontdesk@hotel.com | password |
| Housekeeper | housekeeper@hotel.com | password |
| Kitchen Staff | kitchenstaff@hotel.com | password |
| Laundry Attendant | laundry@hotel.com | password |
| Cashier | cashier@hotel.com | password |
| Auditor | auditor@hotel.com | password |

---

## Module 1: Authentication

| ID | Test Case | Steps | Expected Result | Pass/Fail |
|----|-----------|-------|-----------------|-----------|
| AUTH-01 | Valid login | Enter valid email + password, click Log in | Redirect to dashboard | |
| AUTH-02 | Invalid password | Enter valid email + wrong password, click Log in | Error message, stay on login page | |
| AUTH-03 | Empty fields | Click Log in with empty email/password | Validation error displayed | |
| AUTH-04 | Logout | Click user menu → Log out | Redirect to login page, session destroyed | |
| AUTH-05 | Unauthorized page access | Log in as Front Desk, navigate to `/analytics` via URL | 403 Forbidden | |

---

## Module 2: Multi-Branch Architecture

| ID | Test Case | Steps | Expected Result | Pass/Fail |
|----|-----------|-------|-----------------|-----------|
| BR-01 | Branch context loads | Log in as Branch GM | Dashboard shows branch-specific data | |
| BR-02 | Branch switching | Log in as Global Admin, switch branch | Dashboard reloads with new branch data | |
| BR-03 | Data isolation | Switch to Branch A, view reservations | Only Branch A reservations shown | |
| BR-04 | Cross-branch restriction | Log in as Front Desk (single branch), try to access other branch | Cannot see or access other branch data | |
| BR-05 | Branch creation wizard | Log in as Global Admin, click New Branch | Wizard loads with Step 1 form | |
| BR-06 | Wizard step navigation | Complete Step 1, click Next | Advances to Step 2 (Room Types) | |

---

## Module 3: Reservation Lifecycle

| ID | Test Case | Steps | Expected Result | Pass/Fail |
|----|-----------|-------|-----------------|-----------|
| RES-01 | Create reservation | Reservations → New Reservation → Fill form → Create | Reservation created with confirmation number, status = Pending | |
| RES-02 | Create with invalid dates | Set check-out before check-in | Validation error | |
| RES-03 | Create with past date | Set check-in to yesterday | Validation error or warning | |
| RES-04 | Double booking prevention | Create reservation for Room 101 Jan 10–15, then another for Room 101 Jan 12–16 | Error: room not available for selected dates | |
| RES-05 | View reservation list | Navigate to Reservations | List shows all reservations with guest name, room, dates, status | |
| RES-06 | Search reservation | Type guest name or confirmation number in search | Filtered results displayed | |
| RES-07 | View reservation detail | Click a reservation | Detail page shows all fields, folio, transactions | |
| RES-08 | Edit reservation | Change dates or room type | Changes saved, conflict re-checked | |
| RES-09 | Cancel reservation | Open confirmed reservation → Cancel → Confirm | Status = Cancelled, room released | |
| RES-10 | Registration card | Open checked-in reservation → View Registration Card | Card displays with guest details, printable | |

---

## Module 4: Check-In / Check-Out

| ID | Test Case | Steps | Expected Result | Pass/Fail |
|----|-----------|-------|-----------------|-----------|
| CI-01 | Check-in with assigned room | Open confirmed reservation → Check In → Select room → Confirm | Status = Checked In, room = Occupied | |
| CI-02 | Check-in without room | Open confirmed reservation → Check In (no room assigned) | Prompt to select room | |
| CI-03 | Check-in already checked-in | Try to check-in a guest already checked in | Error or button disabled | |
| CO-01 | Check-out with zero balance | Open checked-in reservation, folio at $0 → Check Out | Status = Checked Out, room = Dirty | |
| CO-02 | Check-out with balance | Open checked-in reservation, folio has balance → Check Out | Error: balance must be settled first | |
| CO-03 | Room status after checkout | Complete check-out | Room appears as "Dirty" in housekeeping queue | |

---

## Module 5: Room Management

| ID | Test Case | Steps | Expected Result | Pass/Fail |
|----|-----------|-------|-----------------|-----------|
| RM-01 | View room list | Navigate to Rooms | List shows all rooms with number, type, status | |
| RM-02 | Filter by status | Filter by "Available" | Only available rooms shown | |
| RM-03 | Filter by room type | Filter by room type | Only matching rooms shown | |
| RM-04 | Edit room details | Click room → Change floor or description → Save | Changes persisted | |
| RM-05 | Change room status | Set room to "Maintenance" | Status badge updates | |
| RM-06 | Room availability for booking | Room set to "Maintenance" | Room not available for new reservations | |

---

## Module 6: Housekeeping

| ID | Test Case | Steps | Expected Result | Pass/Fail |
|----|-----------|-------|-----------------|-----------|
| HK-01 | View housekeeping queue | Log in as Housekeeper → Housekeeping | List of rooms needing attention | |
| HK-02 | Start cleaning task | Click "Start Cleaning" on a dirty room | Task status = In Progress | |
| HK-03 | Complete cleaning | Mark room as "Clean" | Room status = Clean, available for check-in | |
| HK-04 | Mark as inspected | Mark room as "Inspected" | Room status = Inspected | |
| HK-05 | Filter tasks | Filter by status (Dirty, In Progress, Clean) | Filtered list displayed | |

---

## Module 7: Folio Operations

| ID | Test Case | Steps | Expected Result | Pass/Fail |
|----|-----------|-------|-----------------|-----------|
| FL-01 | View folio list | Navigate to Folios | List shows folio number, guest, balance, status | |
| FL-02 | View folio detail | Click a folio | Detail shows transactions, balance, reservation info | |
| FL-03 | Post charge | Open folio → Post Charge → Select category → Enter amount → Submit | Debit transaction recorded, balance increased | |
| FL-04 | Post charge with tax | Enter tax rate (750 bps = 7.5%) | Tax calculated correctly | |
| FL-05 | Record payment | Open folio → Record Payment → Select method → Enter amount → Submit | Credit transaction recorded, balance reduced | |
| FL-06 | Create child folio | Open master folio → Create Child Folio → Enter description → Create | Child folio created, linked to parent | |
| FL-07 | Transfer charge | Open folio with child → Click Transfer on a charge → Select target → Transfer | Charge moved to child folio | |
| FL-08 | Checkout settled folio | Folio balance = $0 → Checkout → Confirm | Folio status = Closed | |
| FL-09 | Checkout unsettled folio | Folio balance > $0 → Checkout | Error or button disabled | |
| FL-10 | Void transaction | Void a debit transaction | Transaction marked voided, balance adjusted | |
| FL-11 | Dispute transaction | Click Dispute on a charge → Enter reason → Submit | Dispute record created | |

---

## Module 8: Guest-Facing Booking Engine

| ID | Test Case | Steps | Expected Result | Pass/Fail |
|----|-----------|-------|-----------------|-----------|
| BK-01 | Load booking page | Navigate to `/book` | Page loads with property selector and date pickers | |
| BK-02 | Search availability | Select property, dates → Search | Available room types with prices shown | |
| BK-03 | No availability | Search dates with no rooms available | "No rooms available" message | |
| BK-04 | Complete booking | Select room → Fill guest info → Review → Accept terms → Complete | Confirmation page with reference number | |
| BK-05 | Missing required fields | Leave guest name empty → Complete | Validation error | |
| BK-06 | Guest folio lookup | Navigate to `/guest/folio/{confirmation}` | Guest sees their charges and payments | |

---

## Module 9: Kitchen Display System (KDS)

| ID | Test Case | Steps | Expected Result | Pass/Fail |
|----|-----------|-------|-----------------|-----------|
| KDS-01 | View pending orders | Log in as Kitchen Staff → KDS | Pending orders displayed | |
| KDS-02 | Start preparing | Click "Start Preparing" | Status → Preparing | |
| KDS-03 | Mark as ready | Click "Ready" | Status → Ready | |
| KDS-04 | Mark as served | Click "Served" | Order completed, removed from queue | |
| KDS-05 | 86 an item | Toggle stock off on a menu item | Item marked unavailable | |
| KDS-06 | Real-time update | Post order from tablet → KDS | Order appears in real-time (WebSocket) | |

---

## Module 10: POS Terminal

| ID | Test Case | Steps | Expected Result | Pass/Fail |
|----|-----------|-------|-----------------|-----------|
| POS-01 | Load POS | Log in as Cashier → POS | Terminal interface loads | |
| POS-02 | Add items to order | Select items from menu | Items added to order list | |
| POS-03 | Apply payment (Cash) | Complete order → Pay with Cash | Payment recorded | |
| POS-04 | Apply payment (Card) | Complete order → Pay with Card | Payment recorded | |
| POS-05 | Post to room folio | Complete order → Post to Room → Select room | Charge posted to guest folio | |
| POS-06 | Void order | Void a completed order | Order voided, stock restored | |

---

## Module 11: Inventory Management

| ID | Test Case | Steps | Expected Result | Pass/Fail |
|----|-----------|-------|-----------------|-----------|
| INV-01 | View inventory | Navigate to Inventory | List of items with stock levels | |
| INV-02 | Add inventory item | Create new item with name, unit, reorder level | Item created | |
| INV-03 | Adjust stock | Update stock count | Stock level updated | |
| INV-04 | Create transfer request | Request stock transfer between branches | Transfer request created | |
| INV-05 | Approve transfer | Approve a pending transfer | Status → Approved | |
| INV-06 | Receive transfer | Mark transfer as received | Stock levels updated at both branches | |

---

## Module 12: Yield Management & Rate Plans

| ID | Test Case | Steps | Expected Result | Pass/Fail |
|----|-----------|-------|-----------------|-----------|
| YR-01 | View yield rules | Navigate to Yield Rules | List of rules with occupancy ranges and multipliers | |
| YR-02 | Create yield rule | Set min/max occupancy % and rate multiplier | Rule created | |
| YR-03 | Edit yield rule | Change multiplier | Rule updated | |
| YR-04 | Delete yield rule | Delete a rule | Rule removed | |
| YR-05 | View rate overrides | Navigate to Rate Overrides | List of overrides with audit trail | |
| YR-06 | Create rate plan | Navigate to Rate Plans → Create | Rate plan form loads | |

---

## Module 13: City Ledger & Corporate Accounts

| ID | Test Case | Steps | Expected Result | Pass/Fail |
|----|-----------|-------|-----------------|-----------|
| CL-01 | View city ledger | Navigate to City Ledger | List of corporate accounts | |
| CL-02 | Create account | Create new account with company details | Account created | |
| CL-03 | View account detail | Click an account | Detail shows balance, transactions, statements | |
| CL-04 | Post charge to account | Post a charge to a city ledger account | Debit recorded | |
| CL-05 | Record payment on account | Record a payment | Credit recorded, balance reduced | |

---

## Module 14: Settings & Configuration

| ID | Test Case | Steps | Expected Result | Pass/Fail |
|----|-----------|-------|-----------------|-----------|
| SET-01 | View profile settings | Navigate to Settings → Profile | Profile form with name, email | |
| SET-02 | Update profile | Change name → Save | Success message, name updated | |
| SET-03 | View appearance settings | Navigate to Settings → Appearance | Theme and layout options | |
| SET-04 | Payment guard settings | Navigate to Settings → Payment Guard | Pre-pay / Post-pay toggle visible | |
| SET-05 | Toggle payment guard | Switch from Post-pay to Pre-pay | Setting saved | |

---

## Module 15: Reports & Analytics

| ID | Test Case | Steps | Expected Result | Pass/Fail |
|----|-----------|-------|-----------------|-----------|
| RPT-01 | View analytics | Log in as Branch GM → Analytics | Dashboard with charts and KPIs | |
| RPT-02 | View reports | Log in as Branch GM → Reports | Report list or generation interface | |
| RPT-03 | Export report | Generate a report → Export | File downloads (CSV/PDF) | |
| RPT-04 | Audit flags | Log in as Auditor → Audit Flags | List of flagged anomalies | |
| RPT-05 | Resolve audit flag | Click a flag → Add resolution notes → Resolve | Flag status → Resolved | |

---

## Module 16: Door Lock Integration

| ID | Test Case | Steps | Expected Result | Pass/Fail |
|----|-----------|-------|-----------------|-----------|
| DL-01 | View door lock status | Navigate to Door Lock page | Lock status for rooms displayed | |
| DL-02 | Issue key card | Issue a key card for a checked-in room | Card programmed (if hardware connected) | |
| DL-03 | Revoke key card | Revoke a key card | Card invalidated | |
| DL-04 | View audit log | View door lock audit log | Entry and exit events logged | |

---

## Module 17: Maintenance Ticketing

| ID | Test Case | Steps | Expected Result | Pass/Fail |
|----|-----------|-------|-----------------|-----------|
| MT-01 | Create maintenance ticket | Create ticket linked to a room | Ticket created with status = Open | |
| MT-02 | Assign ticket | Assign to a maintenance staff | Ticket assigned | |
| MT-03 | Complete ticket | Mark as completed with cost and notes | Ticket status = Completed, room unlocked | |
| MT-04 | Lock room during maintenance | Create ticket → Room locked | Room unavailable for booking | |

---

## Module 18: Laundry Operations

| ID | Test Case | Steps | Expected Result | Pass/Fail |
|----|-----------|-------|-----------------|-----------|
| LD-01 | View laundry queue | Log in as Laundry Attendant → Laundry | List of laundry items | |
| LD-02 | Process laundry item | Mark item as processed | Status updated | |
| LD-03 | Return to room | Mark as returned | Room status updated | |

---

## Module 19: Guest Data & GDPR

| ID | Test Case | Steps | Expected Result | Pass/Fail |
|----|-----------|-------|-----------------|-----------|
| GDPR-01 | View guest record | Open a guest profile | All guest data displayed | |
| GDPR-02 | Export guest data | Request data export for a guest | Export file generated | |
| GDPR-03 | Anonymize guest data | Request anonymization | Guest PII removed, record anonymized | |

---

## Module 20: Import Operations

| ID | Test Case | Steps | Expected Result | Pass/Fail |
|----|-----------|-------|-----------------|-----------|
| IMP-01 | Import rooms | Navigate to Import → Rooms → Upload CSV | Rooms imported | |
| IMP-02 | Import guests | Navigate to Import → Guests → Upload CSV | Guests imported | |
| IMP-03 | Import reservations | Navigate to Import → Reservations → Upload CSV | Reservations imported | |
| IMP-04 | Download template | Click "Download Template" | CSV template downloads | |
| IMP-05 | Invalid file format | Upload a .txt file instead of .csv | Validation error | |

---

## Sign-Off

| Role | Name | Date | Signature |
|------|------|------|-----------|
| Project Manager | | | |
| QA Lead | | | |
| Hotel Operations Manager | | | |
| IT Lead | | | |

**UAT Result:** ☐ PASS — System approved for production deployment
&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;☐ FAIL — Issues require resolution before deployment

**Notes:**
