# PropVendor Domain 5 — Business Readiness & Production Review Report

**Milestone:** Domain 5 — Business Readiness & Production Review  
**Target Environment:** Production (`https://propvendor.com.ng`)  
**Verified Baseline Commit:** `005b99c`  
**Execution Timestamp:** October 26, 2026, 15:25 UTC+1  
**Status:** **AUDIT COMPLETE & PRODUCTION VERIFIED**  

---

## Executive Summary

Following stakeholder deployment of the corrected POSIX plugin package ([`propvendor-marketplace.zip`](file:///c:/xampp/htdocs/Propvendor/wp-content/plugins/propvendor-marketplace.zip), SHA-256: `C1387B0EE7B080A64C8578AE9A685130E256551C4CD070B938CCD6BF32A2C2DD`), live production was probed and a comprehensive read-only Domain 5 Business Readiness Review was conducted.

| Audit Pillar | Focus Area | Status | Key Finding |
| :--- | :--- | :---: | :--- |
| **1. Safety Guidelines Probe** | Page ID 17 (`/listing-and-transaction-safety-guidelines/`) | **PASS** | `HTTP 200 OK`, full `[propvendor_safety_guidelines]` component rendered, zero placeholder text, snappy TTFB (0.29s). |
| **2. Support Routing** | `support@propvendor.ng`, `safety@propvendor.ng` | **ATTENTION REQUIRED** | Email channels defined in templates; `propvendor.com.ng` has active Hostinger MX, but `propvendor.ng` lacks MX records. |
| **3. Inventory Baseline** | Published Listings (`property`, `land`, `vehicle`) | **BASELINE NOTED** | **0 published listings** on public search; vendor submissions strictly quarantined in `Pending Review`. |
| **4. Security Hygiene** | `display_errors`, Debug Headers, PII Leaks | **PASS** | `display_errors = 0` confirmed; zero stack traces; HSTS & security headers active; sensitive files blocked (`403 Forbidden`). |

---

## 1. Production Safety Guidelines Probe Verification

A live automated probe was executed against `https://propvendor.com.ng/listing-and-transaction-safety-guidelines/`:

### 1.1 HTTP Response & Performance
* **HTTP Status:** **`200 OK`**
* **Upstream Response Time:** **`0.295s`**
* **Edge CDN Status:** Hostinger Edge (`hcdn`), LiteSpeed Cache active.
* **Canonical Page ID:** Post ID `17` verified via WP REST API (`/wp-json/wp/v2/pages/17`).

### 1.2 Content & Component Assertions
* **Shortcode Container:** `<div class="propvendor-static-page propvendor-safety-page">` present and fully styled.
* **Header & Badge:** Displays `"Consumer & Vendor Protection"` badge with centered title `"Listing & Transaction Safety Guidelines"`.
* **Cardinal Safety Rule Alert:** Fully rendered with prominent amber warning:
  > *"NEVER send money, pay 'booking deposits', or pay 'inspection fees' to any vendor, agent, or individual before physically inspecting the property or vehicle and legally verifying ownership documents. PropVendor does not hold custody of funds for property or vehicle transactions."*
* **Property & Land Due Diligence Protocols:** All 4 numbered protocols rendered (Daylight Inspection, State Lands Bureau Search, SURCON/NBA Professionals, Family/Community Land Checks).
* **Vehicle & Automotive Protocols:** All 4 numbered protocols rendered (Public Daylight Location, Independent Mechanical Diagnostic, Customs Duty SGD & Registration Verification, Chassis & VIN Cross-Check).
* **High-Risk Red Flags:** Red alert card rendered (Unrealistically low prices, Advance inspection fees, Urgency tactics, Refusal to meet in person).
* **Actionable CTAs:** `"Report Suspicious Activity"` linking to `/contact-us/` and `"Browse Verified Listings"` linking to `/browse-listings/`.
* **Placeholder Absence:** The legacy single-sentence string (`Verify identity, confirm documentation...`) is **strictly absent (0 matches)**.
* **Unrendered Shortcode Absence:** Raw shortcode string `[propvendor_safety_guidelines]` is **strictly absent (0 matches)**.

---

## 2. Support Channel Routing Audit

### 2.1 Configured Support Channels in Codebase
* **Official Support Email Helper:** `propvendor_marketplace_get_support_email()` returns `support@propvendor.ng`.
* **Contact Form Handler:** Submissions on `/contact-us/` (including topic *"Fraud or Safety Report"*) route to `get_option('admin_email')` with fallback to `support@propvendor.ng`.
* **Help & FAQs Routing:** FAQ item explicitly directs users to `safety@propvendor.ng` or the Contact Us form for reporting unverified vendors or suspicious fees.
* **Privacy Office:** Privacy policy documents designate `privacy@propvendor.ng`.
* **Global Footer:** Displays `Official Support: support@propvendor.ng` (WAT Business Hours: Mon–Fri 9:00 AM – 5:00 PM).

### 2.2 Operational Infrastructure Finding & Recommendation
* **DNS MX Lookup Analysis:**
  * `propvendor.com.ng`: **RESOLVED** (`mx1.hostinger.com`, `mx2.hostinger.com` — active mail routing).
  * `propvendor.ng`: **UNRESOLVED** (No DNS MX or A records configured).
* **Actionable Recommendation:**
  Because the public site advertises `@propvendor.ng` email addresses while the active mail server is configured under `propvendor.com.ng`, inbound emails to `support@propvendor.ng` or `safety@propvendor.ng` may bounce.  
  *Recommendation:* Either register/configure MX forwarding for the `propvendor.ng` vanity domain to point to Hostinger mail servers, or update the support email setting in WordPress to `support@propvendor.com.ng` and `safety@propvendor.com.ng`.

---

## 3. Published Listing Inventory Baseline

### 3.1 Live Inventory Census
An audit across the WordPress REST API and public search frontend reveals:

| Custom Post Type | Endpoint | Published Live Count |
| :--- | :--- | :---: |
| **Property Listings** | `/wp-json/wp/v2/property_listing` | **0** |
| **Land Listings** | `/wp-json/wp/v2/land_listing` | **0** |
| **Vehicle Listings** | `/wp-json/wp/v2/vehicle_listing` | **0** |
| **Public Browse Grid** | `/browse-listings/` | **0 (Empty State)** |

### 3.2 Architectural Cause & Launch Readiness Assessment
* **Moderation Quarantine Enforced:** In Domain 2, the marketplace architecture enforces strict quarantine: all vendor submissions enter `Pending Review` (`propvendor_listing_status = 'Pending review'`). No vendor can publish directly to public search without explicit administrator approval.
* **Staging vs. Production Baseline:** Staging has 2 published demo listings (`Stunning 4 Bedroom Duplex Lekki`), whereas Production has **zero approved listings**.
* **Actionable Recommendation:**
  When prospective buyers arrive at `/browse-listings/`, the platform displays:  
  *`"0 matching listings. No listings match your search criteria. Try adjusting your filters or resetting to view all available listings."`*  
  To prevent immediate bounce on public launch, the marketplace operations team should approve initial verified vendor submissions or seed 6–12 curated reference listings across Lagos, Abuja FCT, and Kwara State prior to paid marketing campaigns.

---

## 4. Security Hygiene & Information Disclosure Audit

### 4.1 Server Information Masking
* **`X-Powered-By` Header:** **NOT PRESENT** (Clean — PHP version 8.3 is suppressed).
* **`Server` Header:** Masked to `hcdn` (Hostinger Edge CDN).
* **Debug Headers:** `X-Debug`, `X-Runtime`, and `WP-Debug` are **strictly absent**.

### 4.2 Error Handling & `display_errors`
Tested against malformed queries, negative pagination (`?per_page=-5`), invalid post queries (`/?p=-1`), and arbitrary AJAX actions:
* **PHP Error Output:** **`display_errors = 0` CONFIRMED**. Zero fatal errors, warnings, notices, or deprecation messages emitted in HTML responses.
* **Path Disclosure:** Zero internal filesystem paths (e.g. `/home/u259511143/...` or `C:\xampp\...`) disclosed.
* **Database Query Disclosure:** Zero raw SQL errors, table schemas, or `wpdb::prepare` notices exposed.
* **WAF Protection:** Hostinger Edge WAF intercepts malformed XSS parameter probes with clean HTTP 403.

### 4.3 Security Hardening Headers Active
* `Strict-Transport-Security: max-age=31536000; includeSubDomains` (Enforces HTTPS across all subdomains).
* `Content-Security-Policy: upgrade-insecure-requests`.
* `X-Frame-Options: SAMEORIGIN` (Mitigates clickjacking attacks).
* `X-Content-Type-Options: nosniff` (Mitigates MIME confusion attacks).
* `Referrer-Policy: strict-origin-when-cross-origin`.

### 4.4 Sensitive Files & Debug Log Access
* `/wp-content/debug.log`: **`HTTP 403 Forbidden`** (Direct browser access strictly blocked).
* `/.env`: **`HTTP 403 Forbidden`** (Direct browser access strictly blocked).
* `/wp-config.php.bak` / `/wp-config.php~`: **`HTTP 403 Forbidden`** (Direct browser access strictly blocked).
* `/debug.log`: **`HTTP 404 Not Found`**.

### 4.5 User Enumeration & PII Protection
* **Author Query:** `/?author=1` does not redirect to author archive or reveal real administrative identity (returns generic HTTP 200).
* **REST API:** `/wp-json/wp/v2/users` exposes public author slug `"admin"`. While email addresses and passwords are protected by core, disabling public REST user queries for unauthenticated visitors is recommended as defense-in-depth against username harvesting.
