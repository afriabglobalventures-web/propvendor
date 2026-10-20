# PropVendor Technologies — Privacy Policy (Draft)
**Effective Date:** `[REVIEW REQUIRED: Insert Launch Date, e.g., November 1, 2026]`  
**Last Updated:** October 20, 2026  
**Governing Standard:** Nigeria Data Protection Act (NDPA) 2023  
**Target Page:** `http://localhost/Propvendor/privacy-policy/` (Page ID 3)  
**Status:** Internal Staging Draft — Not Published

> **IMPORTANT NOTICE:**  
> This Privacy Policy has been prepared to reflect the technical data architecture and business logic of the PropVendor marketplace. Before final publication on production, this document should be formally reviewed and ratified by a qualified Nigerian legal practitioner or certified Data Protection Officer (DPO).

---

## 1. Introduction and Data Controller Information

PropVendor Technologies (`"PropVendor"`, `"we"`, `"our"`, or `"us"`) operates the online property, land, and vehicle marketplace accessible at `https://propvendor.ng` (and local development environments). 

For the purposes of the **Nigeria Data Protection Act 2023 (NDPA)**, PropVendor Technologies is the **Data Controller** responsible for your personal data.

- **Data Controller Name:** PropVendor Technologies `[REVIEW REQUIRED: Insert registered CAC business or corporate entity name]`
- **Registration Number:** `[REVIEW REQUIRED: Insert CAC RC / BN Number]`
- **Principal Address:** `[REVIEW REQUIRED: Insert registered commercial office address in Nigeria]`
- **Privacy Contact Email:** `privacy@propvendor.ng` / `support@propvendor.ng` `[REVIEW REQUIRED: Confirm operational mailbox]`
- **Data Protection Officer (DPO):** `[REVIEW REQUIRED: Designate internal DPO or external DPCO contact details]`

---

## 2. Categories of Personal Data We Collect

Depending on how you interact with PropVendor (as a visitor, buyer/tenant inquirer, or vendor/agent), we collect and process the following categories of data:

### A. Information You Provide to Us Directly
1. **Prospective Buyers and Renters (Inquirers):**
   - **Full Name:** To identify you in communications with listing vendors.
   - **Email Address & Phone Number:** To allow vendors to contact you regarding properties or vehicles you have enquired about.
   - **Enquiry Message:** Specific questions, property preferences, inspection scheduling requests, or offer terms.
2. **Vendors and Real Estate / Vehicle Agents:**
   - **Account Details:** First name, last name, business or agency name, mobile phone number, and primary email address.
   - **Business Profile:** Business category, CAC registration details (where applicable), and professional biography.
   - **Verification Documents:** Proof of ownership, title documents (e.g., Certificate of Occupancy, Governor's Consent, Deed of Assignment), and government-issued identification submitted for vendor vetting and badge verification.
   - **Listing Data:** Property addresses, location states, vehicle details, asking prices, photos, and listing descriptions.
3. **Transaction & Financial Records:**
   - Transaction references, credit package selections (Free Basic, Starter Pack, Pro Pack, VIP Agent Monthly), payment timestamps, and credit balance ledger entries.
   - *Note: Payment card details (PAN, PIN, CVV) are processed directly by our payment partner, Paystack. PropVendor does not store, process, or transmit payment card numbers.*

### B. Information Collected Automatically
- **Technical & Log Data:** Internet Protocol (IP) address, browser type, device information, timestamp of access, and referring URL.
- **Security & Anti-Spam Data:** Honeypot field validation tokens and enquiry submission frequency counters used for rate-limiting (e.g., limiting consecutive inquiries to protect against automated scraping).
- **Cookies & Session Identifiers:** Functional cookies strictly necessary to maintain authenticated sessions, CSRF security tokens, and user preferences.

---

## 3. Lawful Bases for Processing (Under NDPA 2023)

In accordance with Section 25 of the NDPA 2023, we only process your personal data where a valid lawful basis exists:

1. **Performance of a Contract:**
   - Processing is necessary to facilitate marketplace introductions between inquirers and vendors.
   - Managing vendor accounts, processing credit purchases, and delivering subscription entitlements.
2. **Legitimate Interests:**
   - Protecting the marketplace against fraudulent listings, identity theft, and malicious bots.
   - Enforcing enquiry rate limits and contact security safeguards.
   - Improving user interface responsiveness and marketplace reliability.
3. **Compliance with Legal Obligations:**
   - Maintaining financial ledgers and transaction history pursuant to Nigerian tax and commercial laws.
   - Complying with lawful directives from Nigerian law enforcement, regulatory authorities, or courts of competent jurisdiction.
4. **Consent:**
   - Where you explicitly opt in to receive marketing communications or promotional newsletters. You may withdraw consent at any time.

---

## 4. Privacy Safeguards & Technical Controls

PropVendor implements technical and organizational measures to safeguard personal data against unauthorized access, loss, alteration, or disclosure:

- **Contact Masking:** Customer phone numbers and email addresses submitted through property enquiry forms are **masked and shielded** from public display. Vendors can only access complete buyer contact details through an authenticated, credit-unlocked inbox session or active VIP Agent entitlement.
- **Private Document Isolation:** Identity and title verification documents submitted by vendors are stored in a dedicated, isolated server directory (`/propvendor-private/`) protected by server configuration rules (`.htaccess` / `web.config`) preventing direct URL access or public indexing.
- **Cryptographic Protections:** Enforced HTTPS transmission (TLS 1.3), hashed authentication cookies, and per-form CSRF nonces.
- **Automated Stale Order Purging:** Unfulfilled or abandoned payment transactions are scheduled for automated cleanup via cron routines to prevent lingering incomplete financial artifacts.

---

## 5. Sharing and Disclosure of Personal Data

We do not sell, rent, or trade your personal data. Personal data is shared only with the following categories of recipients:

1. **Listing Vendors / Agents:** When an inquirer submits an inquiry form on a listing, their name, message, and (upon credit unlock) phone number and email are shared with the specific vendor who published that listing.
2. **Third-Party Service Providers (Data Processors):**
   - **Paystack Payments Limited:** Nigerian licensed payment processor handling credit and subscription checkouts under PCI-DSS Level 1 compliance.
   - **Transactional Email Service:** SMTP mail servers delivering secure account emails and lead notifications via encrypted TLS connections.
   - **Cloud Hosting & Infrastructure Providers:** Data centers hosting our database, web assets, and backups.
3. **Law Enforcement & Regulatory Bodies:** Where required by Nigerian law, court order, or formal request from the Nigeria Data Protection Commission (NDPC).

---

## 6. Data Retention and Deletion

We retain personal data only for as long as necessary to fulfill the purposes for which it was collected:

- **Active Accounts:** Retained for the duration of your active account registration.
- **Inquiry & Thread Records:** Retained in the vendor/buyer message thread for the duration of the communication and up to `[REVIEW REQUIRED: Recommended 24 months]` thereafter for dispute resolution and audit integrity.
- **Financial & Order Records:** Retained for a minimum of 7 years in compliance with Nigerian financial recordkeeping and tax statutes.
- **Inactive / Deleted Accounts:** Upon verified account deletion request, personal identifying data will be removed or irreversibly anonymized, except where statutory retention is legally mandated.

---

## 7. Your Data Subject Rights (NDPA 2023)

Under Section 34 of the Nigeria Data Protection Act 2023, you have the following rights regarding your personal data:

1. **Right of Access:** You may request confirmation of whether we process your data and receive a copy of your personal data.
2. **Right to Rectification:** You may request correction of inaccurate, outdated, or incomplete data.
3. **Right to Erasure ("Right to be Forgotten"):** You may request deletion of your personal data where it is no longer necessary for the purposes collected, subject to statutory retention exceptions.
4. **Right to Restrict Processing:** You may request suspension of processing under specific legal conditions.
5. **Right to Object:** You may object at any time to the processing of your personal data for direct marketing or legitimate-interest purposes.
6. **Right to Data Portability:** You may request transmission of your personal data to another controller in a structured, commonly used format.
7. **Right to Lodge a Complaint:** You have the right to lodge a complaint with the **Nigeria Data Protection Commission (NDPC)** (`https://ndpc.gov.ng`) if you believe our processing violates the NDPA.

To exercise any of these rights, please contact our privacy desk at `privacy@propvendor.ng` `[REVIEW REQUIRED: Confirm contact address]`.

---

## 8. Third-Party Links and External Services

Our marketplace contains links to external websites, maps, and social networks. We are not responsible for the privacy practices or content of third-party platforms. We encourage users to read the privacy notices of external websites they visit.

---

## 9. Updates to this Policy

We may update this Privacy Policy from time to time to reflect operational, technical, or legal changes. When changes are made, the "Last Updated" date at the top of this policy will be revised. Material changes will be communicated via on-site notification or email to registered users.

---

## 10. Contact Us

If you have questions, concerns, or requests regarding this Privacy Policy or our data protection practices, please contact us:

- **Entity:** PropVendor Technologies  
- **Email:** `privacy@propvendor.ng`  
- **Support:** `support@propvendor.ng`  
- **Phone:** `[REVIEW REQUIRED: Insert customer support phone number]`  
- **Office:** `[REVIEW REQUIRED: Insert Nigerian office address]`

