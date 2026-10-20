# PropVendor Technologies — NDPA 2023 Compliance Review Checklist
**Regulation:** Nigeria Data Protection Act (NDPA) 2023  
**Auditor Role:** Senior WordPress Security Engineer & Data Protection Specialist  
**Date:** October 20, 2026  
**Status:** Pre-Staging Assessment

---

## 1. Statutory Compliance Matrix (NDPA 2023)

| NDPA 2023 Section | Statutory Requirement | Implementation Status in PropVendor | Technical / Operational Evidence |
| :--- | :--- | :---: | :--- |
| **Section 24(1)(a)** | Lawful, fair, and transparent processing | **IMPLEMENTED (Subject to Policy Approval)** | Clear notices drafted for registration, enquiry forms, and checkout. |
| **Section 24(1)(b)** | Purpose specification & limitation | **IMPLEMENTED** | Contact data collected strictly for enquiry routing and account management. |
| **Section 24(1)(c)** | Data minimisation | **IMPLEMENTED** | Enquiry form requires only name, email, and message; phone is optional. |
| **Section 24(1)(d)** | Accuracy & rectification | **IMPLEMENTED** | Vendors can update profile metadata via dashboard (`/vendor-profile/`). |
| **Section 24(1)(e)** | Storage limitation | **PARTIALLY IMPLEMENTED / REVIEW REQUIRED** | Stale orders cleaned via cron (`propvendor_cron_cleanup_stale_orders`). Explicit deletion policy for old enquiries/messages needed. |
| **Section 24(1)(f)** | Integrity, confidentiality & security | **IMPLEMENTED** | Contact masking, rate limiting, `.htaccess` protected private document upload directory, nonces on all POST submissions. |
| **Section 25** | Lawful bases for processing | **IMPLEMENTED** | Contract performance (inquiry delivery, payments), Legitimate interest (anti-fraud), Legal obligation (tax records). |
| **Section 29** | Protection of children's data | **CONFIRMED BY DESIGN** | Marketplace operates as property/vehicle commercial platform with strict 18+ eligibility requirement. |
| **Section 34** | Data subject rights fulfillment | **OPERATIONAL PROCEDURE REQUIRED** | Privacy policy explains rights. Email channel `privacy@propvendor.ng` designated for manual DSR handling. |
| **Section 39-40** | Data Security & Breach Notification | **TECHNICAL SAFEGUARDS IMPLEMENTED** | WP_DEBUG_DISPLAY suppressed, private documents isolated, database credentials protected. Administrative 72-hour breach response SOP needed. |
| **Section 41-43** | Cross-border data transfers | **COMPLIANT** | Local hosting in Nigeria or standard international service providers with adequate contractual clauses (Google Workspace, Paystack). |
| **Section 48** | Registration of Data Controllers of Major Importance | **LEGAL REVIEW REQUIRED** | Business owner must determine if PropVendor meets the NDPC threshold for designation as a "Data Controller of Major Importance" (DCMI). |

---

## 2. Technical Safeguards Confirmed in Code

1. **Consumer Privacy Masking:**
   - In `propvendor-marketplace.php` (lines 8730-8770), customer phone numbers and emails are masked with `***` until explicitly unlocked using credits or VIP Agent entitlement.
2. **Private Document Protection:**
   - Vendor verification documents uploaded to `wp-content/uploads/propvendor-private/` are protected with `.htaccess` and `web.config` preventing direct URL access or Google indexation.
3. **Spam & Anti-Scraping Safeguards:**
   - Honeypot form fields (`propvendor_enquiry_company`) reject automated bots silently.
   - IP-based rate limiting (5 inquiries per short interval) prevents data harvesting.
4. **Offsite Financial Processing:**
   - Payment card numbers never touch the PropVendor database; Paystack handles PCI-DSS Level 1 compliance.

