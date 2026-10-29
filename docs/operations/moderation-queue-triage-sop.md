# PropVendor Standard Operating Procedure (SOP)
## Moderation Queue Triage & Listing Review Guide

**Document Reference:** `PV-SOP-MOD-19-3-02`  
**Version:** 1.1  
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

## 3. Concrete Criteria: When to Approve vs. When to Apply Rejection Codes

Moderators must evaluate every listing against the explicit approval criteria and failure triggers detailed below.

### 3.1 When to Approve (Mandatory Pass Criteria by Post Type)

#### A. Residential & Commercial Property (`property_listing`)
A property listing **MUST BE APPROVED** if and only if all of the following conditions are met:
* **Authorization:** Vendor is an onboarded Tier 1 or Tier 2 partner with an active marketing mandate.
* **Price Realism:** Asking price is realistic for the local market, formatted as a valid integer in ₦ (NGN), not 0 or placeholder.
* **Location Specificity:** Specifies exact area/neighborhood and LGA (e.g., Tanke Buka, Ilorin South LGA; GRA, Ilorin West LGA).
* **Photographic Integrity:** Contains at least 4 clear, daylight, unwatermarked photographs (exterior facade, living area, kitchen, bathroom).
* **Zero Smuggled Contacts:** Zero phone numbers, email addresses, WhatsApp links, or external domains in title, description, or photos.
* **Description Completeness:** Minimum 150 characters describing property specs, amenities, water/power supply, and road access.

#### B. Land Parcels (`land_listing`)
A land listing **MUST BE APPROVED** if and only if all of the following conditions are met:
* **Cadastral Dimensions:** Exact area specified in Plots, Acres, Hectares, or Square Metres.
* **Survey Plan Reference:** Registered Survey Plan number and Surveyor's name/SURCON number provided.
* **Topography & Zoning:** Dry land vs waterlogged status disclosed; zoning explicitly designated (Residential, Commercial, Mixed, Agricultural).
* **Photographic Integrity:** At least 3 photographs showing boundary frontage, surrounding landmarks, and access road.
* **Dispute Free:** Confirmation that no community/family dispute or government acquisition warning notice exists.

#### C. Automotive & Vehicles (`vehicle_listing`)
A vehicle listing **MUST BE APPROVED** if and only if all of the following conditions are met:
* **VIN Integrity:** Exactly 17-character alphanumeric VIN provided, passing ISO 3779 checksum and matching the dashboard plaque photo.
* **Photographic Evidence:** At least 4 photos showing front 45°, rear 45°, interior dashboard with odometer reading, and engine bay.
* **Specifications:** Accurate Year, Make, Model, Transmission (Automatic/Manual), Mileage, and Fuel Type.
* **Customs / Registration:** Valid Nigerian Customs SGD C-number on file for Tokunbo, or verified state license plate and Proof of Ownership for Nigerian-used.

---

### 3.2 Canonical Rejection Codes Playbook

---

#### Code 1: `missing_documents`
* **Label:** Missing or Unclear Property Documentation
* **Primary Scope:** Property, Land, and Automotive
* **When to Apply (Triggers):**
  - Land listing claims "C of O" or "Governor's Consent" but provides no survey plan number or layout approval reference.
  - Land survey plan provided is illegible, cropped, or missing the surveyor's seal and SURCON number.
  - Imported Tokunbo vehicle has no Nigerian Customs Service Single Goods Declaration (SGD) C-number or entry port details.
  - Nigerian-used vehicle has expired or missing Proof of Ownership Certificate.
* **Mandatory Moderator Feedback Template:**
  > *"This listing cannot be approved because essential verification documentation is missing. Please provide: (1) Legible registered survey plan showing beacon numbers for land, or (2) Verified Nigerian Customs SGD C-Number for imported vehicles. Please update your listing with these references before resubmitting."*

---

#### Code 2: `missing_vin`
* **Label:** Missing or Invalid 17-Character VIN / Chassis Number
* **Primary Scope:** `vehicle_listing` (Automotive only)
* **When to Apply (Triggers):**
  - VIN input field is empty, contains whitespace, or has fewer or more than 17 characters.
  - VIN contains invalid characters (letters I, O, Q, or symbols).
  - VIN entered does not match the physical windshield plaque or door B-pillar sticker photograph.
  - Photo of VIN plaque is missing, blurred, or obscured.
* **Mandatory Moderator Feedback Template:**
  > *"Your vehicle listing was returned because the 17-character Vehicle Identification Number (VIN) is missing or invalid. Please check the dashboard plaque or door jamb sticker, verify that the VIN contains exactly 17 alphanumeric characters, and provide a clear, legible photograph of the VIN plate."*

---

#### Code 3: `poor_images`
* **Label:** Blurred, Low Quality, Watermarked, or Insufficient Photographs
* **Primary Scope:** All post types
* **When to Apply (Triggers):**
  - Fewer than 3 photographs provided (minimum required is 3; 4+ recommended).
  - Images are severely blurred, dark, pixelated, or lower resolution than `800 x 600` pixels.
  - Visible watermarks from third-party competitor platforms (Jiji, PropertyPro, NigeriaPropertyCentre, Carmart).
  - Mobile phone screenshots showing battery, Wi-Fi, or status bars.
  - Stolen 3D architectural renders used in place of actual physical photos of completed buildings.
* **Mandatory Moderator Feedback Template:**
  > *"Your listing was returned due to photographic quality issues. PropVendor requires at least 3 original, unwatermarked photographs taken in clear daylight. Please replace blurred, dark, or competitor-watermarked images with clean photographs of the exterior, interior, and surroundings."*

---

#### Code 4: `incomplete_details`
* **Label:** Incomplete Listing Details (Price, Physical Location/LGA, or Clear Description)
* **Primary Scope:** All post types
* **When to Apply (Triggers):**
  - Asking price is unrealistic (e.g., ₦0, ₦1, ₦999,999,999,999), set to a placeholder, or denominated in USD/GBP.
  - Physical location is too vague (e.g., just "Kwara" or "Nigeria" without naming the specific neighborhood, street, or LGA).
  - Description is shorter than 150 characters or contains placeholder gibberish (e.g., "call me for details", "nice house for sale").
  - Vehicle listing omits transmission type, fuel type, or mileage.
* **Mandatory Moderator Feedback Template:**
  > *"Your listing lacks critical information required by prospective buyers. Please update: (1) Specific physical area and LGA (e.g., Tanke, Fate, GRA), (2) Realistic Nigerian Naira asking price, and (3) Comprehensive description detailing property specifications or vehicle condition (minimum 150 characters)."*

---

#### Code 5: `suspicious_content`
* **Label:** Smuggled Direct Contact Information, Advance Inspection Fees, or Policy Violations
* **Primary Scope:** All post types
* **When to Apply (Triggers):**
  - Phone numbers, WhatsApp contact links, or email addresses written in the title, description, or superimposed onto images.
  - Phone numbers disguised using words or symbols (e.g., "zero-eight-zero-three...", "0 8 0 3 * * *").
  - Description solicits advance inspection fees, fuel deposits, or reservation payments before viewing.
  - Urgency tactics claiming the owner is traveling today or requiring immediate bank transfer.
* **Mandatory Moderator Feedback Template:**
  > *"This listing was rejected for violating PropVendor safety policies regarding off-platform contact or advance fees. PropVendor strictly prohibits embedding direct telephone numbers, WhatsApp links, or external URLs in descriptions, and zero advance inspection fees may be requested. Buyers must communicate through the secure marketplace portal."*

---

#### Code 6: `other`
* **Label:** Other Compliance Issue (See Administrator Feedback Notes)
* **Primary Scope:** All post types
* **When to Apply (Triggers):**
  - Duplicate listing submitted multiple times by the same vendor.
  - Confirmed active legal dispute or court injunction on the property.
  - Property located in a designated government acquisition, canal right-of-way, or road setback.
  - Vendor profile undergoing administrative audit or regulatory license renewal.
* **Mandatory Moderator Feedback Template:**
  > *"This listing was returned for an administrative compliance review: [INSERT SPECIFIC DETAILS HERE, e.g., 'This listing is an exact duplicate of Listing #1234 already published in your account. Please manage your active listing.']"*

---

## 4. Operational Walkthrough: Quick Actions vs. Full Editor

The Unified Moderation Queue (`admin.php?page=propvendor-moderation-queue`) provides two operational workflows:

```
+───────────────────────────────────────────────────────────────────────────────────+
| Moderation Queue Interface Overview                                               |
| • KPI Stat Cards: Pending Review | Published Active | Rejected/Returned | Total   |
| • Post Type Filters: All | Property | Land | Vehicle                              |
| • Status Tabs: Pending Review | Published Active | Rejected / Returned | All       |
| • Search Box: Filter by listing title, author, or keyword                         |
+───────────────────────────────────────────────────────────────────────────────────+
```

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
* **Service Level Agreement (SLA):**
  - Initial Submission Review: Under 12 Hours (Maximum 24 Hours).
  - Resubmission Review: Under 6 Hours.
