# PropVendor — Phase 10A Pre-Launch Audit & Staging Readiness Report

**Project:** PropVendor Technologies  
**Local Environment:** `http://localhost/Propvendor/`  
**Root Directory:** `C:\xampp\htdocs\Propvendor`  
**Primary Plugin:** `wp-content/plugins/propvendor-marketplace/`  
**Database:** `propvendor` (MySQL / InnoDB, prefix `wp_`)  
**Audit Date:** October 19, 2026  
**Audit Mode:** Read-Only Non-Destructive Inspection  

---

## 1. Executive Summary

A comprehensive pre-launch audit of the PropVendor marketplace platform was conducted to assess staging and production deployment readiness. PropVendor is a multi-vendor marketplace connecting verified real estate developers, property agents, automotive dealerships, and prospective buyers across all 36 Nigerian States and the Federal Capital Territory (FCT).

### Key Takeaways
1. **Core Platform Integrity:** The platform's foundational marketplace logic, listing quotas (3 active listings for Free Basic vs. unlimited for VIP), lead credit ledger, Paystack payment engine, and contact masking are robust, passing **14 out of 14 test suites and 819 out of 819 assertions (100% success rate)**.
2. **Readiness Status:** The application is **conditionally ready for staging**, pending resolution of specific configuration, legal, and security items identified in this audit.
3. **No Unsafe Actions Taken:** In accordance with audit directives, no files, database records, payment credentials, or Git states were altered.

---

## 2. Git Status and Project State

| Metric | Status | Details |
| :--- | :--- | :--- |
| **Current Branch** | `main` | Tracking `origin/main` |
| **Branch Divergence** | Ahead by 14 commits | Local commits ready for staging push |
| **Latest Commit** | `f59a287` | *feat: complete Phase 9E follow-up - vendor dashboard contrast, quota upgrade CTA to wallet, mobile 44px touch targets, and 819-assertion runner* |
| **Unstaged Changes** | 1 file | `wp-content/plugins/propvendor-marketplace/assets/css/front-end.css` (Fluid heading `clamp()` micro-fix) |
| **Untracked Files** | 0 files | Clean working tree |
| **Whitespace Check** | PASSED | `git diff --check` returned 0 whitespace errors |

> [!WARNING]
> **Git Tracking Finding:** `wp-config.php` is currently tracked in the Git repository. On staging/production environments, committing `wp-config.php` introduces credential leak risks.

---

## 3. Verified WordPress Pages and Routes

A direct read-only query of `wp_posts` (`post_type = 'page'`) audited all 19 pages currently residing in the database:

| Page ID | Title | Slug | Status | Shortcode / Content Preview | Route Health |
| :---: | :--- | :--- | :---: | :--- | :---: |
| **2** | Sample Page | `sample-page` | `publish` | WordPress default sample text | **Unnecessary** (Trash before staging) |
| **3** | Privacy Policy | `privacy-policy` | `draft` | WordPress boilerplate tutorial text | **Action Required** (Review & Publish) |
| **7** | Home | `home` | `publish` | `[propvendor_home]` | **Healthy** (HTTP 200) |
| **8** | Browse Listings | `browse-listings` | `publish` | `[propvendor_browse_listings]` | **Healthy** (HTTP 200) |
| **9** | Property Details | `property-details` | `publish` | `[propvendor_property_details_guide]` | **Healthy** (HTTP 200) |
| **10** | Vehicle Details | `vehicle-details` | `publish` | `[propvendor_vehicle_details_guide]` | **Healthy** (HTTP 200) |
| **11** | Submit a Listing | `submit-a-listing` | `publish` | `[propvendor_submit_listing_form]` | **Healthy** (HTTP 200) |
| **12** | Vendor Registration | `vendor-registration` | `publish` | `[propvendor_vendor_registration_form]` | **Healthy** (HTTP 200) |
| **13** | Vendor Dashboard | `vendor-dashboard` | `publish` | `[propvendor_vendor_dashboard]` | **Healthy** (HTTP 200) |
| **14** | Vendor Profile | `vendor-profile` | `publish` | `[propvendor_vendor_profile]` | **Healthy** (HTTP 200) |
| **15** | About PropVendor | `about-propvendor` | `publish` | `[propvendor_about]` | **Healthy** (HTTP 200) |
| **16** | Contact Us | `contact-us` | `publish` | `[propvendor_contact_form]` | **Healthy** (HTTP 200) |
| **17** | Help and FAQs | `help-and-faqs` | `publish` | `[propvendor_help_faqs]` | **Healthy** (HTTP 200) |
| **18** | Terms and Conditions | `terms-and-conditions` | `publish` | 1-sentence placeholder text | **Action Required** (Publish Full Terms) |
| **19** | Safety Guidelines | `listing-and-transaction-safety-guidelines` | `publish` | `[propvendor_safety_guidelines]` | **Healthy** (HTTP 200) |
| **20** | Login and My Account | `login-and-my-account` | `publish` | `[propvendor_login_account]` | **Healthy** (HTTP 200) |
| **120** | Customer Inbox | `customer-inbox` | `publish` | `[propvendor_customer_inbox]` | **Healthy** (HTTP 200) |
| **2771** | Vendor Wallet | `vendor-wallet` | `publish` | `[propvendor_vendor_wallet]` | **Canonical Active** (Assigned in Menu) |
| **2772** | Vendor Wallet | `vendor-wallet` | `publish` | `[propvendor_vendor_wallet]` | **Duplicate** (Orphaned duplicate page) |

### Page Route Audit Analysis
- **Duplicate Vendor Wallet Page:** Both ID `2771` and ID `2772` share the title "Vendor Wallet", slug `vendor-wallet`, and creation timestamp `2026-10-17 16:21:47`. WordPress navigation menu ID 9 (`PropVendor Main Menu`) links to ID `2771`. ID `2772` is an orphaned duplicate and should be removed.
- **Legal Content Deficiencies:** 
  - **Privacy Policy (Page ID 3):** Exists only as a `draft` containing generic WordPress boilerplate.
  - **Terms and Conditions (Page ID 18):** Published, but contains only a single placeholder sentence: `<p>Marketplace users agree to accurate listing details, safe transactions and respectful communication.</p>`.
- **Informational Guides vs. Dynamic Single Routes:** Dynamic CPT single pages (`/property/{slug}`, `/land/{slug}`, `/vehicle/{slug}`) operate correctly. Pages 9 and 10 correctly serve as educational guides with direct search CTAs.

---

## 4. Functional Marketplace Audit

| Feature Area | Verification Status | Operational Findings |
| :--- | :---: | :--- |
| **Vendor Onboarding** | **VERIFIED** | Registration form verifies nonces (`propvendor_register_vendor`), sanitizes business data, assigns the custom `vendor` role, and redirects to dashboard. |
| **Ownership Enforcement** | **VERIFIED** | Strict authorization prevents Insecure Direct Object References (IDOR). Listing edits enforce `(int) $listing->post_author === get_current_user_id() \|\| current_user_can('manage_options')`. |
| **Free Tier Quotas** | **VERIFIED** | Free Basic vendors are strictly limited to 3 concurrently active listings across Property, Land, and Vehicle post types. Inactive moderation statuses (`Rejected`, `Unavailable`, `Expired`, `Sold`, `Rented`) are excluded via SQL `NOT EXISTS`. |
| **VIP Agent Entitlements** | **VERIFIED** | Active VIP Agent subscription (`propvendor_listing_plan = 'vip'`) grants unlimited listing slots, top-of-search placement, and VIP badges. Expirations are evaluated in the site timezone (`Africa/Lagos` / WAT). |
| **Entitlement Separation** | **VERIFIED** | **Strictly separated:** Listing VIP status does not grant lead contact unlocks, and purchasing lead credits does not grant listing VIP privileges. |
| **Contact Masking** | **VERIFIED** | Buyer phone numbers and email addresses are masked by default (`propvendor_marketplace_mask_phone` / `propvendor_marketplace_mask_email`). |
| **Lead Unlocking** | **VERIFIED** | AJAX handler `propvendor_marketplace_ajax_unlock_thread_lead` verifies nonces, confirms ownership of the thread, atomically deducts 1 lead credit from `wp_propvendor_credit_ledger`, and returns the unmasked contact details. |
| **Wallet Packages** | **VERIFIED** | Frontend wallet displays four tiers: Free Basic (₦0), Starter Pack (5 credits / ₦5,000), Pro Pack (28 credits / ₦20,000), and VIP Agent Monthly (₦30,000 / 30 days). |

> [!NOTE]
> **Minor Logic Inconsistency:** In `propvendor_marketplace_login_account_shortcode` (Line 4373), VIP status is checked via `propvendor_marketplace_is_user_vip()`. However, the plugin's actual function name is `propvendor_marketplace_is_vendor_vip()`. As a result, the My Account portal safely defaults to "Registered Account" rather than "VIP Agent Member".

---

## 5. Payments and Financial Integrity Audit

The Paystack payment integration was audited for security, idempotency, and concurrency safety:

```
+-----------------------------------------------------------------------------------------+
|                                PAYSTACK PAYMENT PIPELINE                                |
+-----------------------------------------------------------------------------------------+
| [Order Initialization] -> Client checkout via Paystack Popup (NGN only)                |
|           |                                                                             |
|           v                                                                             |
| [Server Verification]  -> Strict integer Kobo match ($paid_kobo === $order_kobo)        |
|           |            -> Reference check against existing pending order                |
|           v                                                                             |
| [Mutex Locking]        -> MySQL GET_LOCK('pv_order_' . md5($reference), 15)             |
|           |                                                                             |
|           v                                                                             |
| [Atomic Fulfillment]   -> DB Transaction: START TRANSACTION                             |
|           |            -> Grants credits or VIP subscription                            |
|           |            -> Updates order to 'successful'                                 |
|           |            -> COMMIT (or ROLLBACK on failure)                               |
|           v                                                                             |
| [Reconciliation Cron]  -> Stale orders (>24h) expired; late payments automatically       |
|                           reconciled upon valid charge.success webhook.                 |
+-----------------------------------------------------------------------------------------+
```

### Verified Financial Safeguards
1. **Amount Verification:** Evaluated in integer kobo (`paid_kobo === order_kobo`). A ₦5,000 order strictly requires `500000` kobo.
2. **Currency Enforcement:** Rejects any transaction not explicitly denominated in `NGN`.
3. **Replay & Concurrency Protection:** Employs MySQL named mutex locks (`SELECT GET_LOCK(...)`) combined with status check (`if ($order->status === 'successful') return already_fulfilled;`).
4. **Database Rollbacks:** Entitlement grants and order status updates are wrapped in `START TRANSACTION` / `COMMIT` blocks with automatic `ROLLBACK` on error.
5. **Webhook Signature Security:** Incoming webhooks to `/wp-json/propvendor/v1/paystack-webhook` verify `x-paystack-signature` using `hash_hmac('sha512', $payload, $secret_key)` evaluated with timing-safe `hash_equals()`.
6. **Active Plans in Database:** `wp_propvendor_plans` contains 4 records:
   - ID 1: `vip_agent_monthly` (₦30,000)
   - ID 2: `credit_pack_starter` (₦5,000)
   - ID 3: `credit_pack_growth` (₦10,000) — *Retired from UI, but still active in DB table.*
   - ID 4: `credit_pack_pro` (₦20,000)
7. **Credentials State:**
   - Active Mode: `test`
   - Test Public Key: Configured (`pk_test_...`, 34 chars)
   - Test Secret Key: Configured (`sk_test_...`, 28 chars)
   - Live Public Key: Placeholder string (17 chars)
   - Live Secret Key: Placeholder string (17 chars)

---

## 6. Security and Privacy Review

| Security Domain | Status | Finding & Analysis |
| :--- | :---: | :--- |
| **CSRF / Nonce Defense** | **PASS** | Every form submission, profile update, moderation action, enquiry post, and AJAX endpoint validates nonces (`check_ajax_referer` or `wp_verify_nonce`). |
| **SQL Injection Risks** | **PASS** | All dynamic queries utilize `$wpdb->prepare()`. Static table-name interpolations use internal `$wpdb->prefix` constants without user input. |
| **Media Upload Security** | **PASS** | File uploads are validated via `propvendor_marketplace_validate_secure_file_upload`. Enforces MIME types (`image/jpeg`, `image/png`, `image/webp`), 4MB limit, disallows executable extensions, and routes uploads to protected subdirectories. |
| **Information Disclosure** | **RISK** | `WP_DEBUG_DISPLAY` is currently `true` (PHP default). Must be explicitly set to `false` in `wp-config.php` on staging/production to prevent path leaks. |
| **Repository Secrets** | **CRITICAL** | `wp-config.php` is tracked in Git. Must be removed from index and added to `.gitignore`. |
| **Database User Hygiene** | **WARNING** | `wp_users` contains 4 test administrator accounts (`admin_moderation_U39`, `test_admin_quota`, `test_admin_st3`, etc.) created during automated test execution. |

---

## 7. Master Test Suite & Code Quality Results

The automated regression test suite was executed in clean mode via CLI:
`c:\xampp\php\php.exe wp-content/plugins/propvendor-marketplace/tests/run-all-tests.php`

```
====================================================================
  MASTER REGRESSION TEST RESULTS                                    
====================================================================
#    Test Suite Name                                Status   Passed    Time    
--------------------------------------------------------------------
1    Stage 3: Listing Quotas & VIP Entitlement      [PASS]   61/61      8.71 s
2    UI Fixes, Standardized Units & Category Logic  [PASS]   90/90      3.55 s
3    Location Schema (37-State Dropdown & Address)  [PASS]   63/63      2.75 s
4    Edit Form Enhancements & Input Safeguards      [PASS]   33/33      2.09 s
5    Dynamic Forms & Category Schema Verification   [PASS]   53/53      2.56 s
6    Dynamic Forms Edge Cases & Validation Rules    [PASS]   15/15      5.05 s
7    Stage 2: Trust Badges, Regulatory & Disclaimers [PASS]   40/40      6.14 s
8    Stage 4: Search & Discovery Filters            [PASS]   61/61      2.81 s
9    Stage 5: Credit Ledger & Lead Entitlements     [PASS]   98/98      4.48 s
10   Phase 4B: Payment Gateway Integration & Checkout [PASS]   99/99      3.25 s
11   Phase 6A: Secure Automated Email Notifications [PASS]   71/71      5.11 s
12   Phase 7A: Production Security & Reliability Audit [PASS]   44/44      4.16 s
13   Phase 7B: Stale Order Cleanup & Deployment Readiness [PASS]   42/42      3.83 s
14   Phase 7C: Release Candidate Hardening & Verification [PASS]   49/49      7.73 s
--------------------------------------------------------------------
TOTAL SUITES     : 14 passed, 0 failed (14 total)
TOTAL ASSERTIONS : 819 / 819 PASSED (0 failed)
TOTAL TIME       : 62.25 seconds
VERDICT          : 100% SUCCESS — ALL TEST SUITES PASSED CLEANLY!
====================================================================
```

- **PHP Syntax (`php -l`):** 0 syntax errors detected in `propvendor-marketplace.php`.
- **Git Diff Whitespace (`git diff --check`):** 0 errors.

---

## 8. Staging Readiness Checklist

| Requirement | Audit Status | Technical Notes |
| :--- | :---: | :--- |
| **Isolated Staging Host** | **Missing** | Staging server environment (subdomain, separate MySQL DB, staging directory) must be provisioned. |
| **HTTPS / SSL Certificate** | **Not Verified** | Local environment uses HTTP (`http://localhost/Propvendor/`). Staging host must have valid SSL (e.g. Let's Encrypt). |
| **Production Debug Settings** | **Missing** | `wp-config.php` needs `WP_DEBUG_LOG: true`, `WP_DEBUG_DISPLAY: false`, `SCRIPT_DEBUG: false`. |
| **File Editing Lockdown** | **Missing** | `DISALLOW_FILE_EDIT: true` and `FORCE_SSL_ADMIN: true` should be declared in `wp-config.php`. |
| **Paystack Test Mode** | **Verified** | Verified operating in test mode with valid test keys. |
| **Paystack Live Keys** | **Not Verified** | Live keys are placeholders. Requires real credentials upon production cutover. |
| **Webhook Endpoint** | **Verified** | `/wp-json/propvendor/v1/paystack-webhook` registered and signature-verified. Needs public HTTPS URL configured in Paystack Dashboard. |
| **Stale Order Cleanup** | **Verified** | Scheduled daily via `propvendor_cron_cleanup_stale_orders`. On staging/prod, recommend server crontab over traffic-dependent WP-Cron. |
| **Transactional Email (SMTP)** | **Verified** | Active via WP Mail SMTP connecting to `smtp.gmail.com:587` with TLS. |
| **Legal Documentation** | **Requires Human Approval** | Privacy Policy (Page ID 3) and Terms & Conditions (Page ID 18) drafts require owner approval before publishing. |
| **Database Cleanliness** | **Requires Human Approval** | Duplicate Page ID 2772, Sample Page ID 2, and test administrator users should be purged. |
| **Backup & Rollback Plan** | **Verified** | Baseline backup archived in `C:\xampp\htdocs\Propvendor_backups\Phase9G_20261019\`. |

---

## 9. Risk Classification & Prioritization

```
+----------------------------------------------------------------------------------------------------+
|                                    RISK SEVERITY CLASSIFICATION                                    |
+----------------------------------------------------------------------------------------------------+
| [CRITICAL] SEC-01: wp-config.php is tracked in Git.                                               |
|                                                                                                    |
| [HIGH]     CFG-01: WP_DEBUG_DISPLAY is true; errors could leak system paths on staging.           |
|            LEG-01: Privacy Policy (ID 3) is a draft; Terms (ID 18) is a 1-sentence placeholder.   |
|            USR-01: Automated test administrator accounts linger in the live database.             |
|                                                                                                    |
| [MEDIUM]   PAG-01: Duplicate Vendor Wallet page (ID 2772) exists in the database.                 |
|            MON-01: Retired ₦10,000 Growth Pack still flagged is_active=1 in database table.        |
|            CRN-01: Scheduled cleanup relies on WP-Cron rather than server-level crontab.           |
|            UI-01:  My Account portal checks propvendor_marketplace_is_user_vip (nonexistent).     |
|                                                                                                    |
| [LOW]      PAG-02: Default WordPress Sample Page (ID 2) is published.                             |
|            URL-01: Site URLs are hardcoded to http://localhost/Propvendor (HTTP).                  |
+----------------------------------------------------------------------------------------------------+
```

---

## 10. Recommended Remediation Order (Phase 10B)

Upon owner authorization, the remediation steps should be executed in this order:

1. **Security & Repository Sanitization (Immediate):**
   - Untrack `wp-config.php` from Git index (`git rm --cached wp-config.php`).
   - Add `wp-config.php` to root `.gitignore`.
   - Provide a safe `wp-config-sample.php` template.
2. **Environment & Debug Configuration:**
   - Configure staging `wp-config.php` with:
     ```php
     define('WP_ENVIRONMENT_TYPE', 'staging');
     define('WP_DEBUG', true);
     define('WP_DEBUG_LOG', true);
     define('WP_DEBUG_DISPLAY', false);
     define('FORCE_SSL_ADMIN', true);
     define('DISALLOW_FILE_EDIT', true);
     ```
3. **Database & Content Hygiene:**
   - Trash duplicate Vendor Wallet page (ID 2772).
   - Trash Sample Page (ID 2).
   - Clean up test administrator accounts (`admin_moderation_U39`, `test_admin_quota`, `test_admin_st3`).
   - Deactivate retired Growth Pack in `wp_propvendor_plans` (`UPDATE wp_propvendor_plans SET is_active = 0 WHERE plan_key = 'credit_pack_growth'`).
4. **Legal Content Publishing:**
   - Review and publish approved Terms & Conditions to Page ID 18.
   - Review and publish approved Privacy Policy to Page ID 3 (change status from `draft` to `publish`).
5. **UI Helper Correction:**
   - Update `propvendor_marketplace_login_account_shortcode` to check `propvendor_marketplace_is_vendor_vip()`.
6. **Staging Webhook & Paystack Preparation:**
   - Note public staging domain URL for Paystack Webhook configuration (`https://staging.propvendor.ng/wp-json/propvendor/v1/paystack-webhook`).
   - Keep test keys active for initial staging acceptance testing.

---

## 11. Decisions Required from Platform Owner

1. **Approval of Proposed Legal Texts:** Confirm authorization to publish the operational Terms & Conditions and Privacy Policy drafts drafted during Phase 9G.
2. **Removal of Test Admin Accounts:** Confirm deletion of the 4 test administrator accounts from `wp_users`.
3. **Staging Host Domain & SSL:** Provide target staging domain (e.g. `https://staging.propvendor.ng`) for URL and webhook alignment.
4. **Authorisation to proceed to Phase 10B Remediation.**

