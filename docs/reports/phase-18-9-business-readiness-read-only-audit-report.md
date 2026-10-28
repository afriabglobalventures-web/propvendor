# PropVendor Phase 18.9 — Domain 5 Business Readiness Review (READ-ONLY)

**Project:** PropVendor Technologies  
**Production URL:** `https://propvendor.com.ng`  
**Staging URL:** `https://staging.propvendor.com.ng`  
**Repository:** `afriabglobalventures-web/propvendor`  
**Baseline Git Commit:** `005b99c`  
**Audit Execution Date:** October 26, 2026 — 22:45 UTC+1  
**Audit Posture:** **STRICTLY READ-ONLY** (Zero code edits, zero database mutations, zero emails dispatched, zero payment transactions, zero account modifications)  
**Status:** **AUDIT COMPLETE — OPERATIONAL READINESS BASELINE ESTABLISHED**  

---

## 1. Executive Summary

A comprehensive, evidence-based, **read-only business readiness audit** was conducted across PropVendor's production and staging environments, codebase architecture, and operational workflows. 

The audit evaluated five core pillars:
1. **Support Channel Readiness**
2. **Genuine Initial Listing Supply**
3. **Vendor Onboarding & Manual Verification**
4. **User Feedback & Fraud-Report Triage**
5. **Business Metrics & Claims Integrity**

### Summary Scorecard

| Area | Audit Pillar | Operational Readiness Status | Key Finding |
| :---: | :--- | :---: | :--- |
| **A** | **Support Channel Readiness** | **NOT READY** | Publicly advertised email addresses (`support@propvendor.ng`, `safety@propvendor.ng`, `privacy@propvendor.ng`) belong to an **unresolved / non-existent domain (`propvendor.ng`)**, causing all inbound customer and safety emails to bounce immediately. No staff inbox access exists. |
| **B** | **Genuine Initial Listing Supply** | **NOT READY (0 Published)** | Production has **zero (0) published listings** across property, land, and vehicle categories. Public browse displays an empty state. Staging contains 2 internal Lekki property seed listings. |
| **C** | **Vendor Onboarding & Verification** | **PARTIALLY READY** | Vendor registration, free tier ceiling (3 listings), and quarantine into canonical `Pending review` are verified. However, email verification, phone OTP verification, CAC/NIN identity uploads, vehicle VIN collection, and land document uploads are **not implemented**. |
| **D** | **Feedback & Fraud-Report Triage** | **NOT READY** | Fraud reporting routes through the generic Contact Us form via PHP `@wp_mail()`. Submissions are **never persisted in the database**, have no listing linkage, no ticket tracking, no resolution notes, and no automated user acknowledgement. |
| **E** | **Business Metrics Integrity** | **BASELINE DOCUMENTED** | Real customer registrations, genuine vendor supply, completed deals, and revenue are currently **zero / unrecorded**. Paystack remains inert in TEST mode with zero live charges. Platform operates as an inquiry classifieds board, not an escrow or payment settlement platform. |

---

## 2. Preflight & Environment Confirmation

* **Active Git Branch:** `main` (clean working tree ahead of origin by 5 commits).
* **Confirmed Commit Baseline:** `005b99c` (`docs(phase-18.6): controlled production functional review report for domains 1 and 2`).
* **Release Artifact Integrity Verification:**
  - `Compress-Archive` (Windows PowerShell) build: Reported hash `F3087C4A591F603CC5AE186D088F6857938E904FFEF100C25496CAFF646E7A49` contained Windows backslashes (`\`), causing literal flat file extraction on Linux.
  - Remediated POSIX build: Verified hash `C1387B0EE7B080A64C8578AE9A685130E256551C4CD070B938CCD6BF32A2C2DD` with forward slashes (`/`), verified clean extraction, and successfully activated on production.
* **Production Environment:** `https://propvendor.com.ng` (Hostinger Edge CDN `hcdn`, Astra theme 4.14.1, WordPress 6.x, PHP 8.3).
* **Staging Environment:** `https://staging.propvendor.com.ng` (Isolated staging database and environment).
* **Payment Posture:** Paystack is confirmed in **INERT / TEST mode**. Live API keys are unconfigured (`propvendor_paystack_secret_key` empty). Webhook probes without keys return `HTTP 400 Bad Request`. Zero live payment liability.
* **Available Backups:** `scratch/backups/phase2_pre_mod` contains pre-modification assets (`front-end.css`, `front-end.js`, `propvendor-marketplace.php`).
* **Read-Only Inspection Harness:** All inspections were conducted non-destructively using public REST API queries (`/wp-json/wp/v2/`), static page curl probes, DNS resolution tools, and read-only static code analysis. Zero database writes, user mutations, or external webhook calls were triggered.

---

## 3. Area A — Support Channel Readiness

### 3.1 Reported Support Channels
The application advertises three official email channels:
1. `support@propvendor.ng` (General customer support, footer, Help & FAQs, `propvendor_marketplace_get_support_email()`).
2. `safety@propvendor.ng` (Fraud reporting, Safety Guidelines page, Help & FAQs).
3. `privacy@propvendor.ng` (Privacy desk, NDPA compliance documentation).

### 3.2 Detailed Findings

| Audit Check | Finding | Status |
| :--- | :--- | :---: |
| **Address Provisioning & DNS** | DNS lookup on `propvendor.ng` returns `NXDOMAIN` (Non-Existent Domain). There are zero MX, A, or NS records registered for `propvendor.ng`. | **VERIFIED (DEFECT)** |
| **Staff Inbox Access** | Because `propvendor.ng` does not resolve, no mailbox exists. Authorized staff cannot access any inbox under `@propvendor.ng`. | **VERIFIED (BLOCKED)** |
| **Mail Routing & MX Records** | Active Hostinger MX records (`mx1.hostinger.com`, `mx2.hostinger.com`) exist exclusively for `propvendor.com.ng`, not `propvendor.ng`. | **VERIFIED** |
| **Delivery & Bounce Behavior** | Any email sent by users or vendors to `support@propvendor.ng` or `safety@propvendor.ng` will bounce immediately with host lookup failure. | **VERIFIED** |
| **SMTP Configuration** | The WordPress backend uses native PHP `@wp_mail()` with default sender `no-reply@propvendor.com.ng`. No authenticated transactional SMTP plugin (e.g., Postmark, SendGrid, Hostinger SMTP) is configured. | **PARTIALLY VERIFIED** |
| **Request Ownership & SLA** | No specific person, role, ticketing system, or SLA is documented in the codebase or operational manuals. | **UNVERIFIED** |
| **Safety Escalation Path** | While page copy encourages reporting to `safety@propvendor.ng`, no operational escalation procedure or routing rule exists. | **UNVERIFIED** |
| **Privacy & Access Controls** | Contact form submissions are dispatched in unencrypted plain text via PHP mail with no access-controlled ticketing queue. | **PARTIALLY VERIFIED** |

---

## 4. Area B — Genuine Initial Listing Supply

### 4.1 Auditable Inventory Census (As of October 26, 2026 — 22:45 UTC+1)

| Category / Post Type | Production Live Published | Staging Live Published | Counting Method & Source |
| :--- | :---: | :---: | :--- |
| **Property Listings (`property_listing`)** | **0** | **2** | `/wp-json/wp/v2/property_listing`, `X-WP-Total` |
| **Land Listings (`land_listing`)** | **0** | **0** | `/wp-json/wp/v2/land_listing`, `X-WP-Total` |
| **Vehicle Listings (`vehicle_listing`)** | **0** | **0** | `/wp-json/wp/v2/vehicle_listing`, `X-WP-Total` |
| **Vendor Profiles (`vendor_profile`)** | **0** | **0** | `/wp-json/wp/v2/vendor_profile`, `X-WP-Total` |
| **Public Search Results** | **0** | **2** | `https://propvendor.com.ng/browse-listings/` |
| **Registered User Accounts** | **1** (`admin`) | **2** | `/wp-json/wp/v2/users`, `X-WP-Total` |
| **Vendors with Published Inventory** | **0** | **1** | REST API author attribution check |

### 4.2 Seed vs. Vendor Inventory Classification
* **Production Inventory:** **100% Empty (0 Listings)**.
* **Staging Inventory:** 
  - Post ID `351`: `"Stunning 4 Bedroom Duplex Lekki"` (Created 2026-10-13 by internal admin account). Classified as **Confirmed Seed/Demo Inventory**.
  - Post ID `353`: `"Stunning 4 Bedroom Duplex Lekki"` (Duplicate entry created 2026-10-13 by internal admin account). Classified as **Confirmed Seed/Demo Inventory (Duplicate)**.
* **Genuine Vendor Supply:** Currently **0 verified vendor listings** across both production and staging.
* **Architectural Cause:** The zero inventory on production is an intended result of the Phase 18.6 Domain 2 moderation quarantine: all newly submitted listings enter `Pending review` and are excluded from public search (`post_status = 'publish'` enforced). No administrator has yet approved any listings for production.
* **Operational Limitation:** Visitors navigating to `/browse-listings/` see the empty state message:  
  *`"0 matching listings. No listings match your search criteria. Try adjusting your filters or resetting to view all available listings."`*

---

## 5. Area C — Vendor Onboarding and Manual Verification

### 5.1 Verification Workflow Evaluation

| Workflow Step | Technical & Operational Mechanism | Classification |
| :--- | :--- | :---: |
| **Vendor Registration** | Open registration at `/login-and-my-account/?tab=register` with `account_type=vendor`. Collects name, email, password (min 8 chars), agency name, phone number, and free-text verification details. Rate limited to 3 registrations/hour/IP. | **IMPLEMENTED AND EVIDENCED** |
| **Role Assignment** | Assigns WordPress role `'vendor'` and capability `'propvendor_submit_marketplace_listings'`. Auto-logs in vendor and redirects to `/vendor-dashboard/`. | **IMPLEMENTED AND EVIDENCED** |
| **Email Verification** | No verification email, confirmation link, or activation token is required. Account is active immediately. | **NOT FOUND** |
| **Phone Number Verification** | Phone number is stored as unverified string metadata in `wp_usermeta`. No SMS OTP or external validation is implemented. | **NOT FOUND** |
| **Business / Identity Proof** | The registration form provides a plain textarea for `verification_details`. There is no file upload mechanism for CAC registration certificates, NIN, or government-issued ID. | **PARTIALLY IMPLEMENTED** |
| **Listing Quota Boundary** | Free vendor tier ceiling is strictly enforced at 3 listings via atomic mutex locking (`GET_LOCK`) and `propvendor_marketplace_vendor_can_create_listing()`. | **IMPLEMENTED AND EVIDENCED** |
| **Listing Submission Validation** | Robust server-side validation for price, state, required fields, and images (4MB ceiling, strict MIME whitelist, deep binary `getimagesize()` scan). | **IMPLEMENTED AND EVIDENCED** |
| **Listing Moderation Queue** | New submissions automatically enter canonical `Pending review` (`post_status = 'pending'`). Quarantined from search results. | **IMPLEMENTED AND EVIDENCED** |
| **Reviewer Authorization** | Approval / rejection meta box in WP Admin is strictly gated by `current_user_can('manage_options')` and `propvendor_moderation_nonce`. | **IMPLEMENTED AND EVIDENCED** |
| **Property / Land Verification** | Submission form collects title document type via dropdown (C of O, Governor's Consent, Gazette, Excision, etc.), but **does not collect or upload document scans/PDFs**. No automated or manual lands bureau check is integrated. | **DOCUMENTED ONLY** |
| **Vehicle Verification** | Form collects make, model, year, condition, transmission, mileage, and fuel type. **Does not collect VIN (Chassis number)** or Single Goods Declaration (SGD) customs papers. | **DOCUMENTED ONLY** |
| **Decision Audit Trail** | Moderation status updates overwrite `propvendor_listing_status` without recording reviewer ID, decision timestamps, or rejection reasons. | **NOT FOUND** |
| **Vendor Notification on Review** | No automated email is dispatched to the vendor when an administrator approves, rejects, or trashes a listing. | **NOT FOUND** |
| **Rejection Appeal Process** | No structured appeal, correction request, or re-submission workflow exists. | **NOT FOUND** |

---

## 6. Area D — User Feedback and Fraud-Report Triage

### 6.1 Feedback & Fraud Channels Evaluation

| Dimension | Implementation Details | Status |
| :--- | :--- | :---: |
| **Reporting Interfaces** | The CTA on `/listing-and-transaction-safety-guidelines/` links to `/contact-us/`. The contact form includes dropdown option `"Fraud or Safety Report"`. | **IMPLEMENTED AND EVIDENCED** |
| **Listing-Specific Reporting** | No "Report this listing" button exists on listing detail cards or `/property-details/`. Users must navigate away and manually write listing details in the contact form. | **NOT FOUND** |
| **Database Persistence** | Submissions on `/contact-us/` are **never saved to the database**. There is no `wp_propvendor_reports` or ticketing table. | **NOT FOUND (DEFECT)** |
| **Listing / Vendor Linkage** | Submissions do not capture structured `listing_id`, `vendor_id`, or listing URL. The message body contains only raw text. | **NOT FOUND** |
| **Triage & Status Tracking** | No case ID, status (`new`, `under investigation`, `resolved`), priority, or assigned investigator can be tracked. | **NOT FOUND** |
| **Urgent Fraud Escalation** | No escalation path exists for high-risk reports (e.g. advance inspection fee scams or impersonation). | **DOCUMENTED ONLY** |
| **Automated Acknowledgement** | No auto-response email is sent to the reporting user. Only a temporary on-screen flash notice is displayed. | **NOT FOUND** |
| **Audit Trail & Retention** | Because reports exist solely as transient PHP `wp_mail()` calls, there is zero historical record, zero audit trail, and zero NDPA-compliant retention control. | **NOT FOUND** |

---

## 7. Area E — Business Metrics and Claims Integrity

### 7.1 Available Evidence vs. Commercial Claims

| Metric | Codebase / Database Evidence | Operational Reality | Classification |
| :--- | :--- | :--- | :---: |
| **Total Registrations** | WordPress `wp_users` table | 1 Administrator on Production. Ephemeral sandbox test accounts from QA are purged. | **VERIFIED** |
| **Active Users** | None | No login activity timestamp or session telemetry exists in `wp_usermeta`. | **NOT RECORDED / UNKNOWN** |
| **Published Inventory** | CPTs (`property_listing`, `land_listing`, `vehicle_listing`) | 0 on Production; 2 seed listings on Staging. | **VERIFIED (0 Live)** |
| **Unique Vendors with Inventory** | REST API author attribution | 0 active vendors on Production. | **VERIFIED (0 Live)** |
| **Buyer Enquiries** | `wp_propvendor_enquiries`, `wp_propvendor_threads` | Inquiries are functional in code and tested in sandbox QA, but 0 genuine customer inquiries exist on production. | **VERIFIED (0 Live)** |
| **Completed Transactions** | None | PropVendor is an inquiry marketplace. The platform does not hold custody of funds or settle offline property/vehicle transactions. | **NOT APPLICABLE** |
| **Platform Revenue** | `wp_propvendor_orders`, Paystack API | Paystack is in TEST mode; live keys unconfigured. Real revenue generated to date is ₦0.00. | **VERIFIED (₦0.00)** |
| **Moderation Turnaround Time** | None | Admin moderation does not log timestamps; average response time cannot be calculated. | **UNKNOWN** |

---

## 8. Operational Risks and Gaps Ranked

### Critical Risks (Launch Blockers)
1. **Unresolvable Support & Fraud Mail Routing:**
   - *Risk:* Public contact points (`support@propvendor.ng`, `safety@propvendor.ng`, `privacy@propvendor.ng`) are configured under an unresolvable domain (`propvendor.ng`). Inbound customer support inquiries, payment dispute notifications, and urgent fraud reports will bounce immediately.
   - *Severity:* **CRITICAL**
2. **Zero Persistence of Safety and Fraud Reports:**
   - *Risk:* The Contact Us form relies entirely on PHP `@wp_mail()` with no database logging. Any transient mail server drop or unrouted mailbox results in permanent, irrecoverable loss of safety reports with zero administrative awareness.
   - *Severity:* **CRITICAL**

### High Risks (Business & Launch Gaps)
3. **Empty Public Inventory Baseline:**
   - *Risk:* Production has 0 published listings. Driving public traffic or paid marketing to `/browse-listings/` will produce a 100% empty state bounce.
   - *Severity:* **HIGH**
4. **Unverified Vendor Identity & Listing Documents:**
   - *Risk:* Vendors can register with unverified emails and phone numbers. Listings collect title document types as a dropdown without requiring proof-of-ownership document uploads or vehicle VINs.
   - *Severity:* **HIGH**
5. **Absence of Moderation Decision Audit Trail:**
   - *Risk:* Administrators can publish or reject listings without logging reviewer identity, timestamps, or reasons, and without notifying the vendor.
   - *Severity:* **HIGH**

### Medium Risks (Operational Friction)
6. **Lack of Authenticated Transactional SMTP:**
   - *Risk:* Reliance on server-native PHP mail without authenticated SMTP (DKIM/SPF/DMARC) risks system emails (inquiry notifications, password resets) landing in spam.
   - *Severity:* **MEDIUM**
7. **No Listing-Direct Reporting Mechanism:**
   - *Risk:* Users cannot flag a fraudulent listing directly on `/property-details/` or `/vehicle-details/`. They must manually copy details into the generic contact form.
   - *Severity:* **MEDIUM**

### Low Risks (Technical Hygiene)
8. **REST User Enumeration Slug:**
   - *Risk:* `/wp-json/wp/v2/users` exposes the public author slug `"admin"`. While passwords and emails are secure, unauthenticated REST user listing should be disabled in production hardening.
   - *Severity:* **LOW**
9. **Taxonomies Omitted from REST API:**
   - *Risk:* `listing_category`, `listing_location`, and `listing_state` return HTTP 404 in REST queries because `show_in_rest` was omitted during registration.
   - *Severity:* **LOW**

---

## 9. Recommended Next Actions & Ownership

| Recommendation | Proposed Owner Role | Priority | Target Milestone |
| :--- | :---: | :---: | :---: |
| **1. Align Mail Domain & Provision Inboxes:** Register/forward MX for `propvendor.ng` or update all public strings and WordPress options to `@propvendor.com.ng`. Provision live inboxes for `support@` and `safety@`. | Systems / Domain Administrator | **P0 (Immediate)** | Pre-Launch |
| **2. Seed & Approve Initial Inventory:** Curate and approve 10–15 verified reference listings across Lagos, Abuja FCT, and Kwara State so `/browse-listings/` is populated. | Marketplace Operations Lead | **P0 (Immediate)** | Pre-Launch |
| **3. Persist Fraud Reports to Database:** Create a `wp_propvendor_reports` table and WP Admin review screen so fraud and safety reports are saved, tracked, and assigned regardless of email delivery. | Plugin Engineering | **P1 (High)** | Phase 19.0 |
| **4. Implement Contextual "Report Listing" Action:** Add a direct "Report Listing" modal on listing detail pages that automatically captures `listing_id` and vendor metadata. | Plugin Engineering | **P1 (High)** | Phase 19.0 |
| **5. Configure Authenticated Transactional SMTP:** Install and configure an authenticated SMTP service (Hostinger Business Mail, Postmark, or SendGrid) with valid SPF, DKIM, and DMARC records. | Infrastructure Engineer | **P1 (High)** | Pre-Launch |
| **6. Implement Vendor Document Uploads:** Add optional/mandatory document upload fields (CAC certificate, NIN, title document scan, vehicle VIN) to the vendor submission workflow. | Product / Engineering | **P2 (Medium)** | Phase 19.1 |
| **7. Add Moderation Decision Logging & Alerts:** Record reviewer user ID, timestamp, and rejection reason on moderation; send automated email notices to vendors upon approval/rejection. | Plugin Engineering | **P2 (Medium)** | Phase 19.1 |

---

## 10. Matters Requiring Business-Owner Decisions

1. **Email Domain Strategy:**
   - Should PropVendor acquire and configure DNS MX routing for `propvendor.ng`, or should the brand officially consolidate all email addresses under `propvendor.com.ng`?
2. **Launch Inventory Strategy:**
   - Will initial listings be onboarded via direct agent partnerships, internal agency seeding, or a pre-launch vendor acquisition drive?
3. **Vendor Identity Verification Tiering:**
   - Should CAC registration and government-issued ID (NIN/Voter's Card) be mandatory for *all* vendors before submitting listings, or should verification be an optional "Verified Vendor" badge awarded post-review?
4. **Customer Support & Moderation Staffing:**
   - Who is the designated operational owner responsible for reviewing incoming vendor listings and monitoring the support/fraud inbox? What is the agreed SLA for reviewing submissions (e.g. within 12 hours)?
5. **Paystack Live Credential Activation:**
   - When should live Paystack API keys and live webhook secrets be configured, and who is authorized to initiate real financial activation?

---

## 11. Read-Only Compliance Certification

This audit was conducted strictly in a **read-only posture**:
* Zero lines of production or staging code were modified.
* Zero database records, options, listings, or user accounts were altered, created, or deleted.
* Zero emails, support inquiries, or fraud reports were dispatched to live inboxes.
* Zero live or test payment charges were initiated.
* Zero listings were published, unpublished, approved, or rejected.
* Zero Git commits, pushes, or deployments were executed.

