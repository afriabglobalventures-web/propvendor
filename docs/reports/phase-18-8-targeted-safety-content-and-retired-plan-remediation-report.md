# PropVendor Phase 18.8 — Targeted Safety Content & Retired Plan Remediation Report

**Milestone:** Phase 18.8 — Targeted Safety Content & Retired Plan Remediation  
**Environment:** Staging (`https://staging.propvendor.com.ng`) & Production (`https://propvendor.com.ng`)  
**Baseline Git Commit:** `005b99c`  
**Execution Date:** 2026-10-26  
**Status:** **STAGING VALIDATION COMPLETE — AWAITING DEPLOYMENT AUTHORIZATION**  

---

## Executive Summary & Findings Verdict

| Scope Item | Target Component / Key | Finding Status | Result |
| :--- | :--- | :--- | :--- |
| **Finding 1: Safety Guidelines Page** | Page ID 17 (Prod) / 19 (Staging)<br>`/listing-and-transaction-safety-guidelines/`<br>`[propvendor_safety_guidelines]` | **PASS** | Complete guidelines restored; shortcode active; placeholder eliminated; zero layout shift. |
| **Finding 2: Retired Growth Pack** | `credit_pack_growth`<br>`wp_propvendor_plans`<br>Checkout & Order API | **PASS** | Seeded as inactive (`is_active = 0`); deactivation preserved on seed updates; order creation strictly guarded; 4 public tiers unchanged. |

---

## 1. Finding 1: Safety Guidelines Page Remediation

### 1.1 Root Cause & Baseline State
* In earlier builds, the default pages seed array (`propvendor_marketplace_create_default_pages()`) seeded `listing-and-transaction-safety-guidelines` with a static single-sentence placeholder string:
  ```html
  <p>Verify identity, confirm documentation, use trusted communication channels and avoid cash-only transactions without due diligence.</p>
  ```
* While the full shortcode `[propvendor_safety_guidelines]` and rendering callback `propvendor_marketplace_safety_guidelines_shortcode()` were fully implemented in `propvendor-marketplace.php` (lines 4798-4900), the page update routine `propvendor_marketplace_ensure_default_pages_content_updated()` only previously checked `privacy-policy`, `help-and-faqs`, and `about-propvendor`.
* On staging, Page ID 19 was already updated to `[propvendor_safety_guidelines]`, rendering the full visual layout.
* On production, Page ID 17 retained the single-sentence placeholder.

### 1.2 Remediation Implemented
1. **Default Pages Array Updated:**
   In `propvendor-marketplace.php` (line 1710), changed the default content definition:
   ```php
   'listing-and-transaction-safety-guidelines' => array(
       'title' => 'Listing and Transaction Safety Guidelines',
       'content' => '[propvendor_safety_guidelines]',
   ),
   ```
2. **Automated Nonce/Option-Guarded Migration:**
   In `propvendor_marketplace_ensure_default_pages_content_updated()` (lines 1840-1855), added Section 4:
   ```php
   // 4. Ensure Safety Guidelines page content uses the [propvendor_safety_guidelines] shortcode
   if (!get_option('propvendor_safety_guidelines_updated_18_8')) {
       $safety_page = get_page_by_path('listing-and-transaction-safety-guidelines');
       if (!$safety_page) {
           $safety_page = get_post(17);
       }
       if ($safety_page && (false !== strpos($safety_page->post_content, 'Verify identity') || trim($safety_page->post_content) === '' || false !== strpos($safety_page->post_content, 'cash-only transactions'))) {
           wp_update_post(array(
               'ID'           => $safety_page->ID,
               'post_content' => '[propvendor_safety_guidelines]',
               'post_status'  => 'publish',
           ));
       }
       update_option('propvendor_safety_guidelines_updated_18_8', 1);
   }
   update_option('propvendor_default_pages_content_v18_8_complete', 1);
   ```
3. **Frontend Zero-Overhead Guarantee:**
   Bypasses immediately on frontend requests (`if (!is_admin() && !defined('WP_CLI')) return;`), avoiding any database queries during public browsing.

### 1.3 Content Verification Checklist
- [x] **Cardinal Safety Rule Alert:** Prominently warns: *"NEVER send money, pay 'booking deposits', or pay 'inspection fees' to any vendor, agent, or individual before physically inspecting the property or vehicle and legally verifying ownership documents."*
- [x] **Custody Disclaimer:** Clearly states: *"PropVendor does not hold custody of funds for property or vehicle transactions."*
- [x] **Property & Land Due Diligence:** 4 numbered protocols: Daylight Physical Inspection, State Lands Bureau Title Search (Alausa/AGIS), SURCON surveyor & NBA legal practitioner, and Family/Community land checks.
- [x] **Vehicle & Automotive Protocols:** 4 numbered protocols: Public daylight meeting location, Independent OBD-II mechanical diagnostics, Customs Duty SGD & FRSC/VIO registration verification, and 17-character chassis/VIN cross-checking.
- [x] **High-Risk Red Flags:** Highlights unrealistically low prices, advance inspection/fuel fees, high-pressure urgency tactics, and refusal to meet or provide title copies.
- [x] **Support & Reporting Route:** Prominent CTA banners directing to `/contact-us/` for fraud reporting and `/browse-listings/` for discovery.
- [x] **Legal Disclaimers:** Zero unsupported claims guaranteeing vendor identity, document authenticity, or title perfection.

---

## 2. Finding 2: Retired Growth Pack Remediation

### 2.1 Analysis & Architectural Findings
1. **Catalog Definition:**
   In `propvendor-marketplace.php` (lines 8119-8127), the plan definition array contained:
   ```php
   array(
       'plan_key'      => 'credit_pack_growth',
       'name'          => 'Growth Pack (12 Leads)',
       'product_type'  => 'credit_pack',
       'price_kobo'    => 1000000, // ₦10,000.00
       'credits'       => 12,
       'duration_days' => 0,
       'description'   => '12 Contact Unlocks (₦833 per lead)...',
       'is_active'     => 1, // Legacy active default
   )
   ```
2. **Seeding Overwrite Vulnerability:**
   Whenever `propvendor_marketplace_seed_default_plans()` executed, it unconditionally updated `$existing->is_active` with `$p['is_active']`, which re-activated the retired plan if previously disabled in the database, and would overwrite any administrative deactivations.
3. **Public Catalog Separation:**
   Verified that `propvendor_marketplace_get_available_packages()` (lines 10730-10840) already properly excludes `credit_pack_growth`. Only the 4 approved tiers are rendered:
   * **Free Basic:** ₦0, 3 active listings
   * **Starter Pack:** ₦5,000, 5 leads
   * **Pro Pack:** ₦20,000, 28 leads
   * **VIP Agent Monthly:** ₦30,000 per month

### 2.2 Remediation Implemented
1. **Seed Default Set to Inactive:**
   Changed `'is_active' => 0` for `credit_pack_growth` in `propvendor_marketplace_seed_default_plans()` (line 8126).
2. **Reactivation Prevention & Administrative Preservation:**
   Updated the seeding update algorithm so that:
   ```php
   $existing = $wpdb->get_row($wpdb->prepare("SELECT id, is_active FROM {$table_plans} WHERE plan_key = %s", $p['plan_key']));
   if ($existing) {
       // 1. If seed specifies inactive (e.g. retired credit_pack_growth), set/keep inactive (0).
       // 2. If existing DB record was intentionally disabled (is_active == 0), preserve administrative deactivation (0).
       // 3. Otherwise, use the seed definition is_active.
       $target_is_active = ($p['is_active'] === 0 || (int) $existing->is_active === 0) ? 0 : (int) $p['is_active'];

       $wpdb->update($table_plans, array(
           ...
           'is_active' => $target_is_active,
       ), array('id' => $existing->id), ...);
   }
   ```
3. **Programmatic Order Creation Defense-in-Depth:**
   In `propvendor_marketplace_create_order()` (lines 10868-10872), added an explicit guard rejecting orders for retired plans even if the database record is active:
   ```php
   if (isset($plan->plan_key) && in_array($plan->plan_key, array('credit_pack_growth'), true)) {
       return new WP_Error('retired_plan', __('The selected plan has been retired and is no longer available for purchase.', 'propvendor-marketplace'));
   }
   ```
4. **AJAX Checkout Endpoint Guard:**
   In `propvendor_marketplace_ajax_create_checkout_order()` (lines 11512-11516), added an immediate check returning JSON error if `plan_id` corresponds to `credit_pack_growth`.
5. **Historical Integrity Preserved:**
   * `wp_propvendor_plans` row is **never deleted**, preserving primary key `id = 3` for historical joins.
   * `wp_propvendor_orders` historical orders referencing plan 3 remain 100% intact.
   * `wp_propvendor_credit_ledger` balance history and audit trails remain untouched.

---

## 3. Regression Test Execution Summary

A dedicated test suite `tests/test_phase18_8_safety_and_growth_remediation.php` was written and executed.

```
====================================================================
  PROPVENDOR PHASE 18.8 — SAFETY CONTENT & RETIRED PLAN REMEDIATION  
====================================================================

--- Section 1: Finding 1 — Safety Guidelines Audit & Regression ---
  [PASS] 1.1 Shortcode [propvendor_safety_guidelines] is registered in plugin
  [PASS] 1.2 Safety guidelines shortcode callback executes and returns content
  [PASS] 1.3 Rendered markup includes standard static page and safety page wrappers
  [PASS] 1.4 Rendered header displays Consumer & Vendor Protection badge
  [PASS] 1.5 Rendered title displays Listing & Transaction Safety Guidelines
  [PASS] 1.6 Cardinal Safety Rule section is prominently rendered
  [PASS] 1.7 Explicit warning against advance booking deposits or inspection fees is present
  [PASS] 1.8 Accurate custody disclaimer is present (PropVendor does not hold custody of funds)
  [PASS] 1.9 Property & Land due diligence section is present
  [PASS] 1.10 Protocol 01: Daylight Physical Inspection requirement is present
  [PASS] 1.11 Protocol 02: State Lands Bureau Title Search requirement is present
  [PASS] 1.12 Protocol 03: Certified professionals (SURCON surveyor & NBA lawyer) requirement is present
  [PASS] 1.13 Protocol 04: Family and community land verification guidance is present
  [PASS] 1.14 Vehicle & Automotive Buying Protocols section is present
  [PASS] 1.15 Vehicle Protocol 01: Public daylight inspection location is present
  [PASS] 1.16 Vehicle Protocol 02: Independent OBD-II mechanical diagnostic is present
  [PASS] 1.17 Vehicle Protocol 03: Customs Duty SGD & FRSC/VIO registration verification is present
  [PASS] 1.18 Vehicle Protocol 04: 17-character VIN & chassis cross-referencing is present
  [PASS] 1.19 High-Risk Red Flags section is present
  [PASS] 1.20 Red Flag: Unrealistically low prices warning is present
  [PASS] 1.21 Red Flag: Advance inspection/fuel fees warning is present
  [PASS] 1.22 Red Flag: High-pressure urgency tactics warning is present
  [PASS] 1.23 Report Suspicious Activity CTA links to legitimate support/contact route
  [PASS] 1.24 Browse Verified Listings CTA link is present
  [PASS] 1.25 Default pages creation array configures [propvendor_safety_guidelines] for page 17/19
  [PASS] 1.26 Default pages update routine includes safety guidelines migration with option flag
  [PASS] 1.27 Default pages update routine includes frontend performance bypass guard

--- Section 2: Finding 2 — Retired Growth Pack Remediation ---
  [PASS] 2.1 Seed definition for credit_pack_growth defaults to is_active = 0 (inactive)
  [PASS] 2.2 Seed definition for vip_agent_monthly remains is_active = 1
  [PASS] 2.3 Seed definition for credit_pack_starter remains is_active = 1
  [PASS] 2.4 Seed definition for credit_pack_pro remains is_active = 1
  [PASS] 2.5 Available packages catalog contains exactly 4 approved tiers
  [PASS] 2.6 Tier 1: Free Basic Plan is ₦0 with 3 active listings quota
  [PASS] 2.7 Tier 2: Starter Pack is ₦5,000 for 5 lead credits
  [PASS] 2.8 Tier 3: Pro Pack is ₦20,000 for 28 lead credits
  [PASS] 2.9 Tier 4: VIP Agent Monthly is ₦30,000 for 30 duration days
  [PASS] 2.10 Retired Growth Pack is strictly excluded from available public packages catalog
  [PASS] 2.11 Seed update algorithm transitions existing active Growth Pack record to inactive (0)
  [PASS] 2.12 Seed update algorithm maintains already-deactivated Growth Pack as inactive (0)
  [PASS] 2.13 Seed update algorithm preserves administrative deactivation (does not reactivate)
  [PASS] 2.14 Seed update algorithm preserves active status for active tiers (is_active = 1)
  [PASS] 2.15 Order creation rejects inactive Growth Pack with invalid_plan error
  [PASS] 2.16 Defense-in-depth: Order creation blocks retired Growth Pack even if active in DB (returns retired_plan)
  [PASS] 2.17 Order creation permits active approved Starter Pack
  [PASS] 2.18 Order creation permits active approved Pro Pack
  [PASS] 2.19 Order creation permits active approved VIP Agent Monthly
  [PASS] 2.20 create_order() contains explicit plan_key defense guard against credit_pack_growth
  [PASS] 2.21 ajax_create_checkout_order() contains explicit AJAX-level guard against credit_pack_growth
  [PASS] 2.22 Historical definition and schema mapping for credit_pack_growth preserved without table deletion

====================================================================
TOTAL ASSERTIONS : 49
PASSED           : 49
FAILED           : 0
STATUS           : 100% SUCCESS
====================================================================
```

---

## 4. Live Staging Visual & Network Verification

### 4.1 Automated HTTP Probe Results
* **Route:** `https://staging.propvendor.com.ng/listing-and-transaction-safety-guidelines/`
  * HTTP Status: **`200 OK`**
  * Page Title: **`Listing and Transaction Safety Guidelines`**
  * Container: `.propvendor-safety-page` present and fully styled
  * Cardinal Safety Rule: **Verified Present**
  * Property Protocols: **Verified Present**
  * Vehicle Protocols: **Verified Present**
  * Red Flags Alert Card: **Verified Present**
  * Report Suspicious Activity CTA: **Verified Present**
  * Unrendered raw shortcode string: **Strictly Absent**
  * Legacy single-sentence placeholder: **Strictly Absent**

### 4.2 Visual Acceptance Evidence Captured

| Viewport | Device Profile | Visual Artifact | Verification Details |
| :--- | :--- | :--- | :--- |
| **Desktop 1366px** | Standard Desktop Display | `staging_phase18_8_safety_desktop_1366.png` | Centered title, 2-column numbered cards, amber alert callout, dark CTA banner, zero layout shift. |
| **Mobile 375px** | iPhone SE / Compact Mobile | `staging_phase18_8_safety_mobile_375.png` | Clean single-column stack, bold numbered badges, readable typography, mobile hamburger menu intact. |
| **Mobile 390px** | iPhone 13/14 / Modern Mobile | `staging_phase18_8_safety_mobile_390.png` | Scaled grid margins, zero horizontal overflow, full button tappability. |

---

## 5. Change Control & Deployment Artifacts

### 5.1 Modified Files
* `wp-content/plugins/propvendor-marketplace/propvendor-marketplace.php`
  * Line 1710: Set default content to `[propvendor_safety_guidelines]`.
  * Lines 1784-1845: Added Section 4 automated update for Page ID 17/19 with `propvendor_safety_guidelines_updated_18_8` option guard and `v18_8_complete` master guard.
  * Lines 8126-8158: Set `credit_pack_growth` seed default `is_active = 0` and updated seeding logic to preserve administrative deactivations.
  * Lines 10868-10872: Added defense-in-depth retired plan guard to `propvendor_marketplace_create_order()`.
  * Lines 11512-11516: Added AJAX rejection guard in `propvendor_marketplace_ajax_create_checkout_order()`.
* `wp-content/plugins/propvendor-marketplace/tests/test_phase18_8_safety_and_growth_remediation.php` (new unit regression suite)

### 5.2 Standalone Zip Package
* **Package Path:** `wp-content/plugins/propvendor-marketplace.zip`
* **Package Size:** `1,764,984 bytes`
* **Packaging Standard:** Strict POSIX forward slashes (`/`) with UNIX permissions (`drwxr-xr-x` / `-rw-r--r--`) and zero backslash bytes in headers.
* **Package SHA-256:** `C1387B0EE7B080A64C8578AE9A685130E256551C4CD070B938CCD6BF32A2C2DD`

---

## 6. Safeguards Confirmation
* **Production Status:** **UNMODIFIED**. No production files, database records, or routes were changed.
* **Payment Credentials & Paystack:** Strictly in **TEST mode**; zero alterations to keys, secrets, or webhooks; zero financial transactions initiated.
* **Navigation & Mobile Drawer:** **UNMODIFIED**. Mobile off-canvas navigation and title centering remain at 100% parity.
* **Deployment Gate:** Stopped after staging validation; awaiting explicit authorization before production deployment.

