# PropVendor Standard Operating Procedure (SOP)
## Moderation Queue Triage & Listing Review Guide

**Document Reference:** `PV-SOP-MOD-19-3-02`  
**Version:** 1.0  
**Effective Date:** October 29, 2026  
**Target Screen:** `/wp-admin/admin.php?page=propvendor-moderation-queue`  
**Required Capability:** `manage_options` (Platform Administrator)  
**Classification:** Operational / Internal Compliance  

---

## 1. Moderation Philosophy & Cardinal Principles

The PropVendor Moderation Queue is the authoritative barrier protecting consumers, vendors, and marketplace reputation. Every listing submitted by a vendor enters the queue in `pending` review status and must be audited before publication.

### Three Invariant Operating Rules
1. **Never Approve Smuggled Contact Information:** Any listing containing a raw phone number, WhatsApp link, email address, or external URL in the title, description, or images must be rejected immediately under `suspicious_content`.
2. **Never Publish Unverified Vehicle Inventory:** Any vehicle listing without a verified 17-character VIN or missing clean daylight photos must be rejected under `missing_vin` or `poor_images`.
3. **Always Provide Actionable Rejection Notes:** Moderators must never reject a listing with blank or vague feedback. Always select the appropriate canonical reason and write precise, constructive remediation instructions that guide the vendor to successful resubmission.

---

## 2. Moderation Decision Matrix: Approval vs. Rejection

```
   Incoming Listing (Pending Review)
                  │
                  ▼
   [Step 1: Contact Smuggling Scan] ──── (Phone / WhatsApp / URL detected?) ───► REJECT: suspicious_content
                  │ (Clean)
                  ▼
   [Step 2: Category Technical Check]
      • Property ──► Minimum 4 daylight photos? Realistic price? Physical LGA?
      • Land     ──► Survey plan reference? Boundary area specified?
      • Vehicle  ──► Valid 17-char VIN? Clean dash/odometer photo? Tokunbo SGD?
                  │ (Passes checks)
                  ▼
   [Step 3: Verification & Approval]
      • Record Approval in Audit Trail
      • Listing post_status transitions to 'publish'
      • Automated notification dispatched from noreply@propvendor.com.ng
```

---

## 3. Canonical Rejection Taxonomy: Operational Playbook

PropVendor enforces a closed taxonomy of 6 canonical rejection reason codes. Operators must select the exact matching reason and paste the standardized feedback template into the moderator notes box.

---

### Code 1: `missing_documents`
* **Label:** Missing or Unclear Property Documentation
* **Primary Post Types:** `property_listing`, `land_listing`, `vehicle_listing`
* **Trigger Conditions:**
  - Property claiming "Governor's Consent" or "C of O" but no survey plan number, layout approval, or title reference is provided in the intake file.
  - Land parcel missing surveyor name, SURCON number, or beacon identifier on the registered survey plan.
  - Imported Tokunbo vehicle missing Nigerian Customs Service Single Goods Declaration (SGD) entry details.
* **Standard Moderator Feedback Template:**
  > *"This listing cannot be approved because essential documentation is missing. Please provide: (1) Registered survey plan number and beacon reference for land, or (2) Verified Nigerian Customs SGD C-Number for Tokunbo vehicles. Upload clear documentation or provide reference details before resubmitting."*

---

### Code 2: `missing_vin`
* **Label:** Missing or Invalid 17-Character VIN / Chassis Number
* **Primary Post Types:** `vehicle_listing` (Automotive only)
* **Trigger Conditions:**
  - VIN input field is empty, contains fewer than 17 characters, or contains invalid characters (e.g., spaces, dashes, or ambiguous letters I, O, Q in serial sequence).
  - VIN entered does not match the photo of the windshield lower dash plaque or door B-pillar sticker.
  - VIN fails ISO 3779 checksum calculation.
* **Standard Moderator Feedback Template:**
  > *"Your vehicle listing was returned because the 17-character Vehicle Identification Number (VIN) is missing or invalid. Please verify the 17-character VIN from the vehicle dashboard plaque or door jamb sticker and update the listing with a clear photograph of the VIN plate."*

---

### Code 3: `poor_images`
* **Label:** Blurred, Low Quality, Watermarked, or Insufficient Photographs
* **Primary Post Types:** `property_listing`, `land_listing`, `vehicle_listing`
* **Trigger Conditions:**
  - Fewer than 3 photographs provided (minimum required is 3; 4+ recommended).
  - Photographs are blurred, extremely dark, low resolution (< 800x600), or screenshot captures from a mobile phone displaying battery/status bars.
  - Photos carry visible watermarks from competitor platforms (e.g., Jiji, NigeriaPropertyCentre, PropertyPro, Carmart).
  - Photos are stock 3D architectural renders rather than actual photographs of the physical building/land.
* **Standard Moderator Feedback Template:**
  > *"Your listing was returned due to photo quality issues. PropVendor requires at least 3 original, clear, unwatermarked photographs taken in natural daylight. Please replace blurred, dark, or competitor-watermarked images with clear photos of the exterior, interior, and surrounding environment."*

---

### Code 4: `incomplete_details`
* **Label:** Incomplete Listing Details (Price, Physical Location/LGA, or Clear Description)
* **Primary Post Types:** `property_listing`, `land_listing`, `vehicle_listing`
* **Trigger Conditions:**
  - Asking price is unrealistic, set to zero, placeholder (e.g., ₦1, ₦123, ₦99,999,999,999), or denominated in foreign currency ($/£).
  - Physical location is too generic (e.g., just "Ilorin" without specifying neighborhood, area, or Local Government Area).
  - Description is shorter than 150 characters or consists of random characters/placeholder text.
  - For vehicles: Missing transmission type (Auto/Manual), fuel type, or mileage.
* **Standard Moderator Feedback Template:**
  > *"Your listing lacks critical information required by prospective buyers. Please update the following fields: (1) Specific physical area and LGA (e.g., Tanke, Fate, GRA), (2) Realistic Nigerian Naira asking price, and (3) Comprehensive description detailing property specifications or vehicle condition."*

---

### Code 5: `suspicious_content`
* **Label:** Smuggled Direct Contact Information, Advance Inspection Fees, or Policy Violations
* **Primary Post Types:** `property_listing`, `land_listing`, `vehicle_listing`
* **Trigger Conditions:**
  - Direct telephone numbers, WhatsApp contact links, or email addresses embedded in the title, description, or superimposed onto images.
  - Demand for "inspection fees", "fuel deposit", "advance commitment payment", or wire transfer before physical inspection.
  - Urgency tactics (e.g., "Owner traveling abroad today, pay reservation fee immediately").
  - Content suspected of copyright infringement or duplicate submission of another vendor's existing listing.
* **Standard Moderator Feedback Template:**
  > *"This listing was rejected for policy violations regarding off-platform contact or advance fees. PropVendor strictly prohibits embedding direct telephone numbers, WhatsApp links, or external URLs in descriptions, and zero advance inspection fees may be requested. Buyers must communicate through the secure marketplace portal."*

---

### Code 6: `other`
* **Label:** Other Compliance Issue (See Administrator Feedback Notes)
* **Primary Post Types:** Any
* **Trigger Conditions:**
  - Unique regulatory conflict (e.g., property located in an active road expansion setback or zoning dispute).
  - Duplicate listing submitted multiple times by the same vendor.
  - Vendor account under administrative audit or undergoing regulatory license renewal.
* **Standard Moderator Feedback Template:**
  > *"This listing was returned for an administrative compliance review: [INSERT SPECIFIC DETAILS HERE, e.g., 'This listing is an exact duplicate of Listing #1234 already active in your account. Please manage your existing listing.']"*

---

## 4. Quick Actions vs. Full Editor Moderation

The Unified Moderation Queue (`admin.php?page=propvendor-moderation-queue`) provides two operational workflows:

### When to Use "Quick Approve" (from Queue Table)
* The vendor is a verified Tier 1 partner (CAC verified, REDAN member, physical office inspected).
* All photographs are sharp, unwatermarked daylight shots.
* Title, price, and description are complete, professional, and contain zero smuggled phone numbers or external URLs.
* Documentation (survey plan / VIN) has already been cross-referenced by field officers.
* *Action:* Click **Approve** button in queue table row &rarr; Confirms modal &rarr; Automatically updates post to `publish`, appends audit trail entry, and sends transactional email to vendor.

### When to Use "Review / Moderate" (Full Post Editor)
* First-time submission from a newly onboarded vendor.
* Complex land parcel requiring coordinate inspection and boundary verification.
* Any suspected phone number masking or encoded contact smuggling that requires deep inspection.
* Rejection requiring specific paragraph-by-paragraph notes.
* *Action:* Click **Review / Moderate** &rarr; Opens edit screen with dedicated **PropVendor Listing Moderation** meta box &rarr; Select status (`Rejected`), pick canonical reason code, enter granular feedback notes &rarr; Click **Save Moderation Status**.

---

## 5. Audit Trail & Legal Accountability

Every action performed in the Moderation Queue is permanently recorded in the protected postmeta key `_propvendor_moderation_audit_trail`:
* **Operator Identification:** The active administrator's User ID and display name are logged.
* **Immutable History:** When a vendor edits and resubmits a rejected listing, previous rejection entries are retained; the resubmission is appended as `resubmitted`.
* **Bounded Safety:** The audit trail maintains the most recent 50 lifecycle events, ensuring legal auditability while preventing postmeta database bloat.
* **Confidentiality:** Moderation audit trails are restricted to administrators (`manage_options`) and are never exposed to public visitors or standard vendor accounts.
