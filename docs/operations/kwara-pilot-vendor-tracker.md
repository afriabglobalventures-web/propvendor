# Kwara Pilot Vendor Onboarding Tracker — Phase 19.3

**Status Date:** 30 October 2026  
**Current Milestone:** Phase 19.3 — Ilorin Pilot Outreach and Operational Validation  
**Target Region:** Ilorin & Kwara State  
**Pilot Target:** 5 Vendors (3 Real Estate Agencies, 2 Auto Dealerships)  
**Target Active Listings:** 12–15 approved listings (Max 3 active per Free Basic vendor; pipeline up to 20 genuine listings)  
**Payment Posture Invariant:** Paystack strictly in **TEST mode** (`pk_test_...`)

---

## 1. Pilot Cohort Registry

| Vendor ID | Agency / Dealership Name | Sector | Prime Location (Ilorin) | Point of Contact | Outreach Channel | Consent Status | Account Status |
|:---|:---|:---|:---|:---|:---|:---|:---|
| `VND-ILR-01` | **Apex Realty Services** | Real Estate | GRA, Ilorin | Principal Broker | Direct WhatsApp & Office Visit | Pending Outreach | Not Registered |
| `VND-ILR-02` | **Kwara Horizon Properties** | Real Estate | Fate Road / University Way | Managing Partner | WhatsApp + Email Proposal | Pending Outreach | Not Registered |
| `VND-ILR-03` | **Crown Landmark Estates** | Real Estate & Land | Tanke / University Rd | Lead Consultant | WhatsApp Pitch | Pending Outreach | Not Registered |
| `VND-ILR-04` | **Al-Barakah Motors** | Automotive | Fate Road / Unity Rd | Dealership Principal | Direct Dealership Pitch | Pending Outreach | Not Registered |
| `VND-ILR-05` | **Kwara Direct Tokunbo Hub** | Automotive | Ajase-Ipo Road | Sales Director | Direct Dealership Pitch | Pending Outreach | Not Registered |

---

## 2. Vendor Outreach & Onboarding Lifecycle

### Status Key
- `NOT_CONTACTED`: Initial target identified; intake packet prepared.
- `OUTREACH_SENT`: WhatsApp pitch or partnership proposal delivered.
- `CONSENT_AGREED`: Vendor approved participation in Free Basic pilot program.
- `REGISTERED`: Free Basic account created on PropVendor (`max_listings = 3`).
- `INVENTORY_SUBMITTED`: Listing details, physical photos, inspection sheets received.
- `UNDER_MODERATION`: Submissions placed in wp-admin Moderation Queue.
- `ACTIVE_PUBLISHED`: Listings reviewed, passed due-diligence criteria, and published.

| Vendor ID | Outreach Date | Response Date | Agreement / Consent | Registered Username | Account Email | Active Listings / Quota |
|:---|:---|:---|:---|:---|:---|:---|
| `VND-ILR-01` | TBD | TBD | Pending | TBD | TBD | 0 / 3 |
| `VND-ILR-02` | TBD | TBD | Pending | TBD | TBD | 0 / 3 |
| `VND-ILR-03` | TBD | TBD | Pending | TBD | TBD | 0 / 3 |
| `VND-ILR-04` | TBD | TBD | Pending | TBD | TBD | 0 / 3 |
| `VND-ILR-05` | TBD | TBD | Pending | TBD | TBD | 0 / 3 |

---

## 3. Inventory Intake, Due Diligence & Moderation Pipeline

Target: Minimum 12, maximum 15 active published listings across the cohort (3 listings per vendor). Genuine backup inventory (up to 20 total) held in queue.

| Listing ID | Vendor ID | Category | Item Description | Location / Area | Due-Diligence Checklist | Moderation Status | Rejection Code / Action |
|:---|:---|:---|:---|:---|:---|:---|:---|
| `LST-PILOT-01` | `VND-ILR-01` | Property (Sale/Rent) | TBD | GRA, Ilorin | Inspection Sheet, Photo Set | In Pipeline | None |
| `LST-PILOT-02` | `VND-ILR-01` | Property (Sale/Rent) | TBD | GRA, Ilorin | Inspection Sheet, Photo Set | In Pipeline | None |
| `LST-PILOT-03` | `VND-ILR-01` | Land | TBD | Ilorin South | KW-GIS Search, Survey Beacon Check | In Pipeline | None |
| `LST-PILOT-04` | `VND-ILR-02` | Property | TBD | Fate Road, Ilorin | Inspection Sheet, Photo Set | In Pipeline | None |
| `LST-PILOT-05` | `VND-ILR-02` | Property | TBD | Fate Road, Ilorin | Inspection Sheet, Photo Set | In Pipeline | None |
| `LST-PILOT-06` | `VND-ILR-02` | Land | TBD | Asa Dam / Ganmo | KW-GIS Cadastral Check | In Pipeline | None |
| `LST-PILOT-07` | `VND-ILR-03` | Property | TBD | Tanke, Ilorin | Inspection Sheet, Photo Set | In Pipeline | None |
| `LST-PILOT-08` | `VND-ILR-03` | Property | TBD | University Rd, Ilorin | Inspection Sheet, Photo Set | In Pipeline | None |
| `LST-PILOT-09` | `VND-ILR-03` | Land | TBD | Pipeline / Tanke | Survey Beacon / KW-GIS | In Pipeline | None |
| `LST-PILOT-10` | `VND-ILR-04` | Vehicle | TBD | Fate Rd Showroom | 17-char VIN, Customs Check, Engine Bay | In Pipeline | None |
| `LST-PILOT-11` | `VND-ILR-04` | Vehicle | TBD | Fate Rd Showroom | 17-char VIN, Customs Check, Engine Bay | In Pipeline | None |
| `LST-PILOT-12` | `VND-ILR-04` | Vehicle | TBD | Fate Rd Showroom | 17-char VIN, Customs Check, Engine Bay | In Pipeline | None |
| `LST-PILOT-13` | `VND-ILR-05` | Vehicle | TBD | Ajase-Ipo Rd Hub | 17-char VIN, Dashboard / Mileage | In Pipeline | None |
| `LST-PILOT-14` | `VND-ILR-05` | Vehicle | TBD | Ajase-Ipo Rd Hub | 17-char VIN, Dashboard / Mileage | In Pipeline | None |
| `LST-PILOT-15` | `VND-ILR-05` | Vehicle | TBD | Ajase-Ipo Rd Hub | 17-char VIN, Dashboard / Mileage | In Pipeline | None |

---

## 4. Operational Due-Diligence & Disclaimer Policy

1. **KW-GIS & Survey Due Diligence:**
   - Coordinate with the Bureau of Lands / KW-GIS for survey plan coordinate search.
   - Cross-check beacon numbers against registered layout plans.
2. **VIN & Vehicle Verification:**
   - Verify complete 17-character VIN (10th digit model year check, windshield tag + door jamb B-pillar photo).
   - Require proof of Nigeria Customs Service clearance documentation.
3. **Mandatory Due Diligence Disclaimer:**
   - Physical inspections, cadastral searches, document screening, and VIN cross-referencing are due-diligence measures.
   - They **must not** be represented or advertised to buyers as absolute legal warranties or guarantees of title, ownership, condition, or history.

---

## 5. Pilot Metrics & Audit Log

| Metric | Target | Actual Current | Notes |
|:---|:---|:---|:---|
| Pilot Vendors Contacted | 5 | 0 | Ready for field outreach |
| Consenting Pilot Vendors | 5 | 0 | Awaiting initial responses |
| Free Basic Accounts Created | 5 | 0 | 3 active quota each |
| Total Listings Submitted | 15–20 | 0 | In intake preparation |
| Listings Approved & Active | 12–15 | 0 | Target range |
| Moderation SLA (First Review) | < 24 hrs | — | Tracked in wp-admin Moderation Queue |
| Lead Enquiries Logged | Tracked | 0 | Validates customer enquiry flow |
