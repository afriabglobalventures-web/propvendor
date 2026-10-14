# PROPVENDOR — REUSABLE AI IMPLEMENTATION PROMPT TEMPLATE

**How to use this template:**
Fill in the four blank sections at the top (Feature Name, Goal, Scope, Specific Requirements), then paste the entire filled-out document to your AI developer tool. Everything else is pre-configured to protect your project and align with the PropVendor Vision.

---

## 🔧 FILL IN THESE FOUR SECTIONS BEFORE SENDING

### 1. Feature Name
> *(Example: "Add Services / Hire Me Category" OR "Add Vendor Review & Rating System" OR "Add Paystack Payment Integration")*

**Feature Name:** `[WRITE HERE]`

---

### 2. Business Goal (Why are we building this?)
> *(Example: "Allow verified skilled workers like plumbers, surveyors, and mechanics to list their services so buyers who already use PropVendor can hire trusted professionals, creating cross-category value.")*

**Business Goal:** `[WRITE HERE]`

---

### 3. Scope (What should it do?)
> *(Example: "Add a 4th listing type called Services. Allow service providers to upload portfolio images, specify hourly/daily rates, service area, years of experience, certifications, and availability status.")*

**Scope:** `[WRITE HERE]`

---

### 4. Specific Requirements / Fields / Workflow
> *(Example: "New category: Services (Hire Me) with subcategories: Real Estate Pros, Construction, Automotive, Lifestyle, Digital/Freelance. Custom fields: Service Type, Rate, Years of Experience, Coverage Area, Certifications Upload, Portfolio Gallery, Availability (Open/Busy).")*

**Specific Requirements:** `[WRITE HERE]`

---

# BEGIN STANDARD INSTRUCTIONS BELOW — DO NOT MODIFY

## PROPVENDOR CONTEXT (Required Reading)

**Project:** `C:\xampp\htdocs\Propvendor`
**Target plugin:** `wp-content/plugins/propvendor-marketplace/`
**Platform:** WordPress + PropVendor Marketplace plugin
**Target Market:** Nigeria

**Vision:** PropVendor is Nigeria's premium trust-based marketplace for Property, Land, Vehicles, and Verified Services. Every feature must strengthen trust, reduce scam risk, respect Nigerian local context (states, title documents, plot measurements), and support modular growth. Do NOT build generic "Jiji-style" features. Build professional, verification-first features that justify VIP subscription pricing.

---

## 1. Mandatory Pre-Change Safeguards

Before editing ANY code, you MUST:

1. **Inspect current implementation of related files**, including:
   - `propvendor-marketplace.php`
   - `assets/js/front-end.js`
   - `assets/css/front-end.css`
   - Related submission handlers, edit-mode logic, metadata keys, and single-listing renderers.
2. **Check current Git status** and preserve ALL existing uncommitted work.
3. **Create a timestamped backup** at:
   `C:\Users\USER\Documents\PropVendor Backups\[FeatureName]-PreImplementation-[Timestamp]`
   - Include a full database dump (`propvendor.sql`).
   - Include a full copy of the plugin folder.
4. **Verify backup completeness** before proceeding.
5. **Do NOT overwrite the entire plugin** or replace working code with simplified versions.
6. **Do NOT stage, commit, push, reset, or discard any Git changes.** Keep everything unstaged.

---

## 2. Protect Existing Functionality (No Regressions)

The following must NEVER be broken, weakened, or removed:

- Phase 3.1 lead-contact entitlement and masking.
- Phase 3.2 contact leakage prevention.
- Stage 2 Owner-Direct moderation and safety banner.
- Dynamic Property, Land, and Vehicle forms.
- Multi-image upload, gallery, primary-image selection, and image deletion.
- Vendor ownership and listing approval checks.
- Enquiries, inbox, messaging, read receipts, and rate limits.
- Private listing image protections.
- Email notification safeguards.
- 37 Nigerian States dropdown + Full Address field.
- Standardized Plot Size & Floor Area units.
- VIP plan entitlements and listing quotas.
- Admin moderation workflow.

Do NOT introduce duplicate subscription systems, duplicate metadata keys, or duplicate category/role structures. REUSE existing conventions.

---

## 3. Nigerian Context Requirements (Non-Negotiable)

All new features must respect Nigerian local context:

- **Locations:** Use the existing 37 Nigerian States allowlist. Never hardcode arbitrary cities.
- **Property Titles:** Respect existing Nigerian Title Document types (C of O, Governor's Consent, Deed of Assignment, Gazette, Excision, Survey Plan).
- **Measurements:** Use existing standardized units (`Sqm (Square Meters)`, `Plots`, `Acres`, `Hectares`, `Sqft (Square Feet)`).
- **Currency:** Nigerian Naira (NGN) with proper formatting.
- **Terminology:** Use Nigerian-friendly terms (e.g., "Tokunbo", "Brand New", "Nigerian Used" for Vehicles; "Dry Land", "Swampy", etc. for Land).

---

## 4. UX & Microcopy Requirements

- Every form field must have:
  - A clear, visible label.
  - A short helpful sub-label or hint explaining what to enter.
  - A realistic placeholder with a Nigerian-context example (e.g., *"e.g., 4 Bedroom Duplex in Lekki Phase 1"*).
- All dropdowns must have proper vertical text alignment (no clipping).
- All error messages must be clear and non-technical.
- Mobile-friendly, accessible markup.
- Form field visibility must be dynamic per selected category (hide unrelated fields; disable them server-side so they don't overwrite unrelated metadata).

---

## 5. Server-Side Security Requirements

For any new submission handler or endpoint:

- Enforce nonce checks.
- Enforce capability checks (vendor ownership, admin role).
- Validate all input against defined allowlists (never trust client-side dropdowns).
- Sanitize all text using `sanitize_text_field()`, `wp_strip_all_tags()`, or `esc_url_raw()` as appropriate.
- Enforce reasonable length limits.
- Reject invalid/tampered POST data cleanly without partial overwrites.
- Escape all output using `esc_html()`, `esc_attr()`, `esc_url()`.
- Keep create and edit flows consistent.
- Never expose internal metadata keys, private emails, or unapproved verification claims.
- Protect private media and uploaded files.

---

## 6. Modular Architecture Requirements

- New categories or features must EXTEND the existing dynamic category system (`Property`, `Land`, `Vehicle`) — never replace it.
- Use consistent metadata key naming (e.g., `_listing_[field_name]` or match existing conventions).
- Reuse existing helper functions (e.g., `propvendor_marketplace_get_plot_size_units()`) where possible.
- Add new helper functions in the same style as existing ones.
- Keep JS code modular (new `init[FeatureName]()` functions in `front-end.js`).
- Keep CSS in `front-end.css` using existing class naming patterns (e.g., `.propvendor-[component-name]`).

---

## 7. Required Tests Before Reporting Complete

Run and report with actual pass/fail counts:

1. PHP syntax lint on all modified `.php` files.
2. JavaScript syntax check on all modified `.js` files.
3. All existing automated test suites (verify zero regressions):
   - `test_dynamic_forms_verification.php`
   - `test_dynamic_forms_edge_cases.php`
   - `test_stage2_verification.php`
   - `test_location_schema_enhancement.php`
   - `test_edit_form_enhancements_and_safeguards.php`
4. New tests specific to this feature covering:
   - Happy path (valid input).
   - Rejection of invalid/tampered input.
   - Edge cases (empty, maximum length, special characters).
   - Permission checks (unauthorized access rejected).
   - Edit mode persistence.
   - Legacy data compatibility (if applicable).
5. Where feasible, perform a REAL browser test on `http://localhost/Propvendor/`. Do NOT report CLI/PHPUnit tests as browser tests.
6. Do NOT delete or modify real user listings as test fixtures. Use isolated temporary records and clean up after testing.

---

## 8. Final Report Requirements

Your final report MUST include:

- All files changed (with file paths).
- Backup directory location.
- New metadata keys introduced (if any) and justification.
- Dependency on any existing helper functions or test suites.
- Test results with ACTUAL pass/fail counts.
- Any outstanding issues or manual browser checks the user must perform.
- Confirmation that Git changes remain unstaged and uncommitted.
- A short "Next Suggested Step" aligned with the PropVendor Vision Roadmap.

---

## 9. Stop Conditions

- **STOP** after implementing and testing the requested feature.
- **DO NOT** begin unrelated phases or features.
- **DO NOT** auto-commit, push, or merge changes.
- **DO NOT** modify files outside the stated feature scope.

If any requirement conflicts with existing functionality, STOP and ASK for clarification instead of guessing.

---

## 10. Response Format Expectations

When responding, the AI must:

1. Start by confirming the pre-change inspection and backup are complete.
2. Explain changes being made step by step with file paths.
3. Show diffs or key code snippets for transparency.
4. Report test results clearly in a table or structured list.
5. End with a summary & Next Suggested Step.

**End of template.**
Example Use Case:
Feature Name: "Add Vendor Review & Rating System"
Business Goal: "Allow verified buyers to rate vendors so trustworthy sellers get boosted visibility, strengthening PropVendor's trust layer against scams."
Scope: "Allow 1-5 star reviews with written comments only from verified transactions."
Specific Requirements: "Reviews stored per vendor profile. Only buyers who have messaged the vendor can leave reviews. Display average rating on listing cards and vendor profile pages. Admin can moderate/remove abusive reviews."