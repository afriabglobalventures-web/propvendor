# PropVendor Phase 19.3 — Initial Real Inventory Supply & Vendor Onboarding Plan Report

**Project:** PropVendor Technologies  
**Milestone:** Phase 19.3 — Initial Real Inventory Supply  
**Target Pilot Region:** Kwara State (Primary focus: Ilorin Metropolitan Area)  
**Expansion Runway:** Offa, Omu-Aran, followed by Southwest expansion (Oyo/Ogun/Lagos)  
**Payment Gateway Posture:** Paystack Strictly in **TEST Mode** (Live payments disabled)  
**Execution Timestamp:** October 29, 2026 — 04:25 WAT / UTC+1  
**Operational Objective:** Seed the marketplace with 100% genuine, permission-based real estate, land, and automotive inventory under strict moderation and statutory consumer protection standards.

---

## 1. Executive Summary & Strategic Scope

With the deployment and verification of **Phase 19.2 (Moderation & Onboarding Operations)**, PropVendor possesses an auditable, resilient moderation queue, structured rejection remediation workflows, protected postmeta audit logging, and transactional email notifications.

**Phase 19.3: Initial Real Inventory Supply** transitions the platform from technical infrastructure readiness to live commercial operation. Rather than populating the platform with synthetic, scraped, or unverified listings, Phase 19.3 executes a disciplined, permission-based pilot starting in **Kwara State (Ilorin)**.

### Cardinal Operating Principles
1. **Zero Synthetic / Scraped Data:** Every listing published must be authorized in writing by the legal owner or accredited representing broker/agent.
2. **Truth in Advertising & Title Claims:** PropVendor never claims that a land title or vehicle status has been "Government Certified" or "Legally Verified" unless formal, certified search documentation has been examined and documented by accredited legal/regulatory counsel.
3. **Advance-Fee Prohibition:** Strict enforcement of the cardinal marketplace safety rule: zero inspection fees, advance reservation deposits, or unverified wire transfers.
4. **Safety & NDPA Compliance:** Contact masking remains fully active for locked leads; vendor business details and regulatory certifications (LASRERA, REDAN, CAC) are validated during onboarding.
5. **Payment Posture Invariant:** Paystack remains locked in **TEST mode** throughout Phase 19.3. Free Basic tier quotas (3 listings) and test credits will be used for pilot onboarding.

---

## 2. Vendor Recruitment & Onboarding Plan

### 2.1 Target Vendor Cohorts (Kwara State Pilot)
* **Tier 1: Institutional Estate Agents & Realtors:** Established agencies registered with CAC and affiliated with professional bodies (e.g., REDAN, NIESV) in Ilorin (GRA, Fate, Tanke, University Road).
* **Tier 2: Private Property Managers & Developers:** Verified direct property owners and building developers managing residential flats, commercial shops, and student housing.
* **Tier 3: Registered Land Surveyors & Family Representatives:** Accredited SURCON surveyors and documented family representatives offering surveyed plots in designated growth corridors (Ganmo, Eiyenkorin, Airport Road, Oke-Oyi).
* **Tier 4: Licensed Automobile Dealerships:** Physical auto dealerships with permanent showrooms/lots in Ilorin, offering inspected foreign-used (Tokunbo) or registered Nigerian-used vehicles.

### 2.2 Onboarding Funnel & Verification Steps
```
  [Stage 1: Outbound Outreach / Inbound Registration]
                     │
                     ▼
  [Stage 2: Identity & Regulatory Documentation Review]
    • Corporate: CAC Certificate of Incorporation (RC / BN)
    • Professional: REDAN / NIESV membership or LASRERA/state equivalent
    • Automobile: Physical showroom address & business license
    • Individual: Government-issued ID (NIN / Driver's License)
                     │
                     ▼
  [Stage 3: Signed Listing Authorization & Terms Agreement]
    • Vendor Listing Permission Undertaking (confirming authority to market)
    • Agreement to PropVendor Anti-Advance-Fee & Safety Policies
                     │
                     ▼
  [Stage 4: Account Provisioning & Orientation]
    • Vendor Dashboard orientation (/vendor-dashboard/)
    • Submission walkthrough & image quality standards briefing
    • Free pilot listing allocation (3 active listings under Free Basic Plan)
```

---

## 3. Legitimate Listing Sourcing & Consent Framework

### 3.1 Listing Authorization Protocol
To prevent dispute, double-marketing, or fraudulent listings, every vendor must provide evidence of marketing authorization:
* **Direct Owner Listings:** Proof of ownership (purchase deed, allocation letter, or state land registry document) and signed Vendor Undertaking.
* **Agent / Broker Listings:** Signed Agency Representation Agreement or Broker Instruction Letter from the beneficial owner specifying marketing terms, asking price, and validity duration.
* **Automobile Listings:** Physical custody of the vehicle at a verifiable business location with original vehicle licensing and customs documentation available for physical inspection.

### 3.2 Statutory Representation Boundaries
PropVendor does not claim ownership or custody of any asset. All listing detail pages must display:
* Standard Anti-Advance-Fee Disclaimer.
* Mandatory Physical Inspection Notice.
* Clear distinction between **Vendor Self-Representation** and **Administrative Documentation Checks**.

---

## 4. Moderation Checklist by Asset Category

### 4.1 Residential & Commercial Property (`property_listing`)
* [ ] **Physical Address:** Precise location specified (Street/Area, LGA, State) without ambiguous placeholders.
* [ ] **Price Sanity:** Asking price is realistic for the local market; currency denominated in Nigerian Naira (₦).
* [ ] **Photographic Evidence:** Minimum 4 high-resolution, unwatermarked photographs (exterior facade, living area, kitchen, bathroom). Zero stock or stolen 3D renders.
* [ ] **Property Type & Specs:** Accurate bedroom/bathroom count, property type (Duplex, Bungalow, Self-Contain, Office Complex).
* [ ] **Contact Leakage Audit:** Description text and images scanned for smuggled phone numbers, WhatsApp links, or external URLs.
* [ ] **Title Designation:** Title claim matches uploaded documentation (e.g., Governor's Consent, C of O, Deed of Assignment, Gazette).

### 4.2 Land & Agricultural Parcels (`land_listing`)
* [ ] **Plot Dimensions & Area:** Explicit measurement specified (e.g., Plots, Hectares, Acres, or Square Metres).
* [ ] **Survey Coordinates / Plan:** Registered Survey Plan reference number present or beacon numbers indicated where available.
* [ ] **Zoning & Topography:** Dry land vs swamp/waterlogged status disclosed; zoning stated (Residential, Commercial, Mixed, Agricultural).
* [ ] **Community / Family Encumbrance:** Confirmation that land is free from active court injunctions or family ownership disputes.
* [ ] **Title Disclaimer:** Mandatory notice: *"Buyers must independently verify beacon coordinates with the Kwara State Geographic Information Service (KW-GIS) or relevant State Lands Bureau prior to executing consideration payments."*

### 4.3 Automotive & Commercial Vehicles (`vehicle_listing`)
* [ ] **VIN / Chassis Integrity:** 17-character alphanumeric VIN provided; passes ISO 3779 checksum; excludes ambiguous glyphs.
* [ ] **Vehicle Details:** Make, Model, Year of Manufacture, Transmission, Mileage, Engine Size, and Fuel Type accurately specified.
* [ ] **Condition Classification:** Accurately classified as Brand New, Foreign Used (Tokunbo), or Nigerian Used.
* [ ] **Customs & Registration:** Nigerian Customs SGD (Single Goods Declaration) paperwork on file for Tokunbo imports; plate registration and FRSC/VIO status for Nigerian-used.
* [ ] **Mandatory Mechanical Notice:** Prominent notice advising buyer to perform an independent OBD-II diagnostic and daylight test drive before payment.

---

## 5. Listing Quality & Completeness Standard

### 5.1 Image Quality Standards
* Minimum resolution: `1024 x 768` pixels.
* Formats: WebP, JPEG, or PNG.
* Requirements: Natural daylight illumination; interior and exterior perspectives; sharp focus.
* Strict Prohibitions: Watermarks from competitor portals (PropertyPro, NigeriaPropertyCentre, Jiji); embedded phone numbers or text banners; screenshots from mobile camera rolls.

### 5.2 Content & Description Standards
* Minimum description length: 150 characters.
* Must describe: neighborhood amenities, road accessibility, electricity/water supply, security infrastructure, and payment terms.
* Zero external links or off-platform payment solicitations.

---

## 6. Vendor Communication & Support Workflow

```
   Listing Submitted
         │
         ▼
   [Admin Queue Triage] ────► SLA: < 12 Hours
         │
         ├───► [APPROVED] ───► Transactional Email to Vendor
         │                     • Published to Live Marketplace
         │                     • Statutory Verification Disclaimer Attached
         │
         └───► [REJECTED] ───► Transactional Email to Vendor
                               • Exact Canonical Reason Tagged
                               • Granular Remediation Notes Attached
                               • Vendor Dashboard Feedback (.pv-dashboard-rejection-info)
                               • Submit Form Remediation Banner (.pv-moderation-remediation-banner)
                               • 1-Click "Edit & Resubmit" Action
```

* **Support Channel:** Dedicated vendor assistance via `support@propvendor.com.ng`.
* **Escalations & Disputes:** Safety and compliance issues directed to `safety@propvendor.com.ng` with registered ticket reference tracking (`TKT-XXXXXX`).

---

## 7. Staged Pilot Targets (Kwara State / Ilorin)

### 7.1 Quantitative Inventory Milestones

| Stage | Target Timeline | Genuine Listings Target | Approved Vendors | Target Asset Mix | Key Milestone Owner |
| :--- | :--- | :---: | :---: | :--- | :--- |
| **Stage 1: Seed Onboarding** | Weeks 1 – 2 | 20 Genuine Listings | 5 Onboarded Vendors | 12 Properties, 5 Land, 3 Vehicles | Operations Lead |
| **Stage 2: Metro Expansion** | Weeks 3 – 4 | 50 Genuine Listings | 15 Onboarded Vendors | 30 Properties, 12 Land, 8 Vehicles | Regional Field Agent |
| **Stage 3: Full Pilot Maturity**| Weeks 5 – 6 | 100 Genuine Listings | 30 Onboarded Vendors | 60 Properties, 25 Land, 15 Vehicles | Head of Marketplace |

### 7.2 Aggregate Operational KPI Metrics

```
+------------------------------------+-----------------------------+-----------------------+
| Operational Metric                 | Target SLA / Benchmark      | Monitoring Dashboard  |
+------------------------------------+-----------------------------+-----------------------+
| Initial Review Turnaround Time     | < 12 Hours (Max 24 Hours)   | Admin Moderation Queue|
| First-Time Approval Rate           | > 65%                       | Admin KPI Counters    |
| Resubmission Turnaround Time       | < 6 Hours                   | Moderation Audit Trail|
| Consumer Inquiries per Listing     | >= 3 Inquiries / Month      | Inquiries Ledger      |
| Safety Reports on Genuine Listings | 0 Fraud / False Claims      | Safety Reports Triage |
| Lead Contact Unlock Response Time  | < 4 Hours                   | Vendor Inbox          |
+------------------------------------+-----------------------------+-----------------------+
```

---

## 8. Dependencies & Acceptance Criteria

### 8.1 Technical Dependencies
1. Phase 19.2 Moderation Queue & Rejection Engine active on production.
2. Authenticated Hostinger SMTP (`noreply@propvendor.com.ng`) active for transactional dispatch.
3. Persistent Safety & Fraud Reporting (`propvendor-safety-reports`) active for buyer alerts.
4. Contact masking active on all public and locked thread views.

### 8.2 Operational Acceptance Criteria for Pilot Launch
* [ ] Formal Vendor Onboarding Pack and Authorization Undertaking drafted and signed.
* [ ] Pilot vendors provisioned with Free Basic Plan (3 active listings quota).
* [ ] First 20 genuine listings submitted, moderated via Unified Moderation Queue, and audited in `_propvendor_moderation_audit_trail`.
* [ ] Zero public listings published without prior administrator approval.
* [ ] Paystack strictly maintained in **TEST mode** throughout pilot.
