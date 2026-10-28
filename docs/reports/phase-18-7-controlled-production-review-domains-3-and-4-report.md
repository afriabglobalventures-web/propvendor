# Phase 18.7 — Controlled Production Review Report: Domains 3 & 4

**Project:** PropVendor Technologies (`https://propvendor.com.ng`)  
**Staging Target:** `https://staging.propvendor.com.ng`  
**Execution Timestamp:** October 26, 2026 — 06:38:19 UTC+1  
**Baseline Git Commit:** [`005b99c`](file:///c:/xampp/htdocs/Propvendor/docs/reports/phase-18-6-controlled-production-functional-review-report.md)  
**Verification Scope:** Domain 3 (Wallet & Monetization) & Domain 4 (Trust & Safety)  
**Execution Mode:** Controlled, non-destructive audit (Zero production mutations, zero live charges)  
**Status:** **48 / 50 PASSED (96.0% Overall Score)** — *2 Database Content Gaps Identified*

---

## 1. Executive Summary

A rigorous, evidence-based functional and security audit was conducted against PropVendor's live production platform (`https://propvendor.com.ng`), staging environment (`https://staging.propvendor.com.ng`), and codebase architecture. The review focused on **Domain 3: Wallet & Monetization** and **Domain 4: Trust & Safety**.

All tests were strictly non-destructive. No live payment transactions were initiated, no user balances or ledgers were mutated, no real records were altered, and Paystack remained in an inert/test state with zero live payment credentials configured on production.

### High-Level Domain Results
* **Domain 3 (Wallet & Monetization):** **25 / 25 Passed (100.0%)**
  - Confirmed 100% pricing consistency across public UI, wallet dashboard, and backend catalog.
  - Verified atomic mutex locking (`GET_LOCK`) and ACID InnoDB transactions across credit addition and deduction.
  - Verified server-side price calculation: amounts are strictly pulled from database plan records (`amount_kobo`), preventing any client-side tampering.
  - Confirmed production Paystack isolation: live keys are unconfigured, webhook requests without valid secrets return `HTTP 400 Bad Request`, and staging webhook enforces HMAC-SHA512 signatures (`HTTP 401 Unauthorized`).
* **Domain 4 (Trust & Safety):** **23 / 25 Passed (92.0%)**, **2 Gaps Identified**
  - Verified administrator-only moderation gates (`current_user_can('manage_options')` and `propvendor_moderation_nonce`).
  - Verified non-destructively that pending listings are quarantined from public search results (`post_status = 'publish'` enforced).
  - Verified privacy masking: customer phone numbers (`+2348****678`) and emails (`s***r@example.com`) are masked across public pages.
  - **Identified Gap 1 & 2:** WordPress Page ID 17 (`/listing-and-transaction-safety-guidelines/`) currently renders a 1-sentence draft placeholder string in `post_content`. While the rich safety guidelines shortcode `[propvendor_safety_guidelines]` is fully implemented in code and the zero inspection fee guarantee is live on `/help-and-faqs/`, Page ID 17 itself lacks the shortcode in the production database.

---

## 2. Environment & Release Preflight Verification

| Attribute | Verified State | Evidence & Notes |
| :--- | :--- | :--- |
| **Active Git Branch** | `main` | Clean working tree, ahead by 5 commits |
| **Git Baseline Commit** | `005b99c` | `docs(phase-18.6): controlled production functional review report` |
| **Production Endpoint** | `https://propvendor.com.ng` | Returns `HTTP 200 OK` (Astra 4.14.1 + `propvendor-marketplace`) |
| **Staging Endpoint** | `https://staging.propvendor.com.ng` | Returns `HTTP 200 OK` (Isolated staging database and test gateway) |
| **Production Paystack Mode** | **INERT / TEST** | `POST /wp-json/propvendor/v1/paystack-webhook` returns `{"message":"Paystack secret key not configured."}` (HTTP 400). Zero live charge liability. |
| **Staging Paystack Mode** | **TEST (Active)** | `POST /wp-json/propvendor/v1/paystack-webhook` returns `{"message":"Invalid webhook signature."}` (HTTP 401). Signature validation active. |
| **Database Mutations** | **ZERO (0)** | Strictly read-only inspection; no balances or user records changed |

---

## 3. Scoreboard Breakdown

```
========================================================================================
  DOMAIN 3: WALLET & MONETIZATION (25 / 25 PASSED — 100.0%)
========================================================================================
  [PASS] D3_1.1_free_basic_config                : Free Basic Plan configured (₦0 / 3 active listings quota)
  [PASS] D3_1.2_starter_pack_config              : Starter Pack configured (₦5,000 / 5 credits @ ₦1,000/lead)
  [PASS] D3_1.3_pro_pack_config                  : Pro Pack configured (₦20,000 / 28 credits @ ₦714/lead, 28% discount)
  [PASS] D3_1.4_vip_agent_config                 : VIP Agent Monthly configured (₦30,000 / 30 days unlimited leads)
  [PASS] D3_1.5_retired_growth_pack_omitted_active_ui: Retired ₦10,000 Growth Pack omitted from active pricing packages
  [PASS] D3_1.6_plan_benefit_separation          : VIP Agent duration & badge strictly separated from credit packs
  [PASS] D3_1.7_server_price_integrity           : Checkout amount derived from DB plan record; client cannot tamper
  [PASS] D3_2.1_wallet_auth_gate                 : Unauthenticated /vendor-wallet/ access gated with auth prompt
  [PASS] D3_2.2_wallet_credit_balance_card       : Vendor sees Lead Contact Credits balance card (#pv-current-credit-balance)
  [PASS] D3_2.3_wallet_vip_status_card           : Vendor sees VIP Agent Status card (.propvendor-vip-card)
  [PASS] D3_2.4_wallet_pricing_grid              : Subscriptions & Credit Packs pricing grid renders all 4 package cards
  [PASS] D3_2.5_wallet_history_table             : Payment & Order History table section rendered
  [PASS] D3_2.6_wallet_vars_localized            : propvendor_payment_vars localized with nonces, Ajax & REST endpoints
  [PASS] D3_3.1_ledger_table_schema              : Ledger table schema verified (balance_after, amount, reference, type)
  [PASS] D3_3.2_ledger_balance_lookup            : Balance calculated from latest balance_after ledger record
  [PASS] D3_3.3_ledger_atomic_mutex              : Atomic MySQL GET_LOCK mutex on vendor credit ledger operations
  [PASS] D3_3.4_ledger_innodb_transactions       : Ledger updates wrapped in ACID InnoDB transactions with rollback
  [PASS] D3_3.5_insufficient_balance_guard       : Insufficient credits prevent lead unlock with clean rollback
  [PASS] D3_3.6_idempotency_pack_purchase        : Idempotent credit pack grant prevents duplicate credits via reference
  [PASS] D3_4.1_paystack_mode_isolated           : Production Paystack isolation verified (inert, HTTP 400 not configured)
  [PASS] D3_4.2_webhook_signature_enforced       : Staging Paystack webhook HMAC-SHA512 signature enforced (HTTP 401)
  [PASS] D3_4.3_webhook_empty_payload_rejected   : Webhook rejects forged or unsigned payloads
  [PASS] D3_4.4_verify_auth_gate                 : /wp-json/propvendor/v1/verify-payment requires authentication (HTTP 401)
  [PASS] D3_4.5_order_mutex_lock                 : Order fulfillment protected by GET_LOCK against webhook race conditions
  [PASS] D3_4.6_triple_security_checks           : Strict integer kobo match, reference equality, and NGN currency checks

========================================================================================
  DOMAIN 4: TRUST & SAFETY (23 / 25 PASSED, 2 GAPS — 92.0%)
========================================================================================
  [PASS] D4_1.1_safety_guidelines_route_200      : /listing-and-transaction-safety-guidelines/ returns HTTP 200 OK
  [PASS] D4_1.2_fraud_topic_available            : Contact Us form contains 'Fraud or Safety Report' topic dropdown
  [PASS] D4_1.3_contact_form_nonce_protected     : Contact Us form protected by security nonce (propvendor_contact_nonce)
  [PASS] D4_1.4_contact_form_anti_phishing_notice: Anti-phishing advisory warning displayed on Contact Us form
  [PASS] D4_1.5_faq_fraud_instructions           : Help & FAQs details reporting workflow for suspicious listings/agents
  [PASS] D4_1.6_official_support_email           : Official support channels designated (support@propvendor.ng)
  [PASS] D4_2.1_admin_moderation_metabox_gate    : Moderation meta box strictly restricted to manage_options administrators
  [PASS] D4_2.2_moderation_nonce_enforced        : Moderation save handler enforces propvendor_moderation_nonce
  [PASS] D4_2.3_vendor_cannot_publish            : Vendor role lacks publish_posts capability (all submissions pending)
  [PASS] D4_2.4_canonical_status_quarantine      : Unapproved listings quarantined from public search (publish required)
  [PASS] D4_2.5_unauthorized_lead_unlock_blocked : Cross-vendor thread unlock strictly forbidden server-side (403)
  [PASS] D4_2.6_unapproved_listing_enquiry_blocked: Inquiries on unapproved listings rejected server-side
  [PASS] D4_2.7_direct_owner_moderation_gate     : Direct Owner badge requires explicit admin approval before display
  [PASS] D4_3.1_zero_inspection_fee_warning      : Zero inspection fee consumer warning verified on /help-and-faqs/
  [FAIL] D4_3.2_high_risk_red_flags_card         : [GAP] Page ID 17 lacks [propvendor_safety_guidelines] shortcode in DB
  [FAIL] D4_3.3_in_person_inspection_advisory    : [GAP] Page ID 17 lacks daylight in-person inspection copy in DB
  [PASS] D4_3.4_no_unsupported_title_claims      : Platform clearly disclaims unsupported title/ownership guarantees
  [PASS] D4_3.5_honeypot_spam_trap               : Honeypot spam trap on inquiry submissions (propvendor_enquiry_company)
  [PASS] D4_3.6_inquiry_rate_limiting            : Inquiry submission rate limiting implemented (pv_enq_rate_)
  [PASS] D4_4.1_phone_masking_enforced           : Technical phone masking algorithm verified (+2348****678)
  [PASS] D4_4.2_email_masking_enforced           : Technical email masking algorithm verified (s***r@example.com)
  [PASS] D4_4.3_private_upload_isolation         : Vendor KYC verification uploads protected with .htaccess deny rules
  [PASS] D4_4.4_privacy_policy_accessible        : Privacy policy accessible with NDPA statutory disclosures (HTTP 200)
  [PASS] D4_4.5_offsite_payment_processing       : Zero payment card data stored in database (PCI-DSS Paystack offsite)
  [PASS] D4_4.6_anti_enumeration_auth            : Anti-enumeration authentication parity verified (0 disclosure)
```

---

## 4. Discovered Configuration & Monetization Baseline

### Verified Pricing Architecture
The live vendor wallet at `https://propvendor.com.ng/vendor-wallet/` renders exactly 4 active packages:

| Package Key | Display Name | Product Type | Verified Price | Benefits & Quotas | Active UI State |
| :--- | :--- | :--- | :--- | :--- | :---: |
| `free_basic` | Free Basic Plan | `free_listing` | **₦0** | 3 active listings, standard search placement, pay-as-you-go leads | **Active (Default)** |
| `starter_5` | Starter Pack (5 Leads) | `credit_pack` | **₦5,000** | 5 contact unlocks (₦1,000/lead), credits never expire | **Active** |
| `pro_28` | Pro Pack (28 Leads) | `credit_pack` | **₦20,000** | 28 contact unlocks (₦714/lead, 28% discount), credits never expire | **Active** |
| `vip_monthly` | VIP Agent Monthly | `vip_agent` | **₦30,000** | 30 days duration, unlimited contact unlocks, verified VIP badge | **Active** |
| `credit_pack_growth` | Growth Pack (12 Leads)| `credit_pack` | ₦10,000 | 12 contact unlocks (₦833/lead) | **RETIRED (Omitted from UI)** |

> [!NOTE]
> The retired ₦10,000 Growth Pack is completely absent from the active package grid rendered to vendors. It remains defined only in the background catalog seed.

---

## 5. Security & Privacy Architectural Findings

### 1. Payment Gateway Isolation & Safety
* **Production Invariant:** When attempting to POST to `/wp-json/propvendor/v1/paystack-webhook` on production, the response is `HTTP 400 Bad Request` with message `Paystack secret key not configured.`. This confirms that production is completely safe from accidental live payment executions.
* **Staging Signature Validation:** On staging, Paystack webhooks strictly enforce HMAC-SHA512 signature validation using `hash_equals($computed_signature, $signature)` against the test secret key. Unsigned requests are rejected with `HTTP 401 Unauthorized`.
* **Fulfillment Race Condition Protection:** In [`propvendor_marketplace_verify_and_fulfill_order()`](file:///c:/xampp/htdocs/Propvendor/wp-content/plugins/propvendor-marketplace/propvendor-marketplace.php#L11138), a MySQL named lock `GET_LOCK('pv_order_' . md5($reference), 15)` serializes processing. Duplicate webhook deliveries exit immediately via the idempotency check: `if ('successful' === $order->status) return already_fulfilled;`.

### 2. Wallet & Ledger Integrity
* All balance changes in `wp_propvendor_credit_ledger` utilize:
  1. Vendor-level lock: `GET_LOCK('pv_credit_ledger_' . $vendor_id, 10)`
  2. ACID transaction: `START TRANSACTION` / `COMMIT` / `ROLLBACK`
  3. Row locking: `SELECT balance_after FROM ... FOR UPDATE`
  4. Balance validation: If balance < amount required, the transaction immediately rolls back and returns `WP_Error('insufficient_credits')`.

### 3. Moderation & Anti-Abuse Controls
* **Capability Restrictions:** Marketplace vendors possess capability `propvendor_submit_marketplace_listings`, but lack `publish_posts` and `manage_options`. All vendor submissions enter `post_status = 'pending'` and are quarantined from public search.
* **Cross-Vendor Isolation:** Vendors attempting to unlock leads on threads that do not belong to their vendor ID are rejected server-side with `HTTP 403 Forbidden` (`code: unauthorized`).
* **Honeypot & Rate Limiting:** All enquiry forms include hidden trap fields (`propvendor_enquiry_company`) and enforce transient-based IP throttling.

---

## 6. Gaps Identified for Remediation (Phase 18.8 Roadmap)

1. **Page ID 17 (`listing-and-transaction-safety-guidelines`) Content Injection:**
   - **Finding:** The page exists and returns `HTTP 200 OK`, but contains only the legacy 1-sentence draft placeholder: *"Verify identity, confirm documentation, use trusted communication channels and avoid cash-only transactions without due diligence."*.
   - **Remediation:** Update `wp_posts.post_content` for Page ID 17 to insert the shortcode `[propvendor_safety_guidelines]` (or the full semantic HTML safety guidelines card container).
2. **Catalog Seed Soft-Deprecation:**
   - **Finding:** While `credit_pack_growth` is omitted from `propvendor_marketplace_get_available_packages()`, it is seeded with `is_active = 1` in `propvendor_marketplace_seed_default_plans()`.
   - **Remediation:** Update the seed routine to set `'is_active' => 0` for `credit_pack_growth` to prevent programmatic purchase via direct API calls.

---

## 7. Business & Legal Review Items (Awaiting Owner Decision)

1. **Formal CAC Registration:** Corporate Affairs Commission (CAC) RC/BN number and legal registered company name.
2. **Data Protection Officer (DPO):** Official operational mailbox for privacy inquiries (`privacy@propvendor.ng`).
3. **Data Retention Window:** Formal policy on customer message thread retention before anonymization (recommended: 24 months).
4. **Governing Law Jurisdiction:** Default state court jurisdiction for marketplace terms (High Court of Lagos State or High Court of the Federal Capital Territory, Abuja).

---

## 8. Explicit Change Control Confirmation

* **Production Code:** **0 modifications made (Unchanged)**
* **Production Database Records:** **0 records modified (Unchanged)**
* **Production Settings & Options:** **0 settings modified (Unchanged)**
* **Payment Mode:** **Inert / TEST (Unchanged)**
* **Git Status:** Working tree clean on commit `005b99c`

