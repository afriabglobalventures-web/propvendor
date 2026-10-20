# PropVendor Technologies — Data Inventory & Processing Mapping
**Framework:** Nigeria Data Protection Act (NDPA) 2023  
**Project:** PropVendor Nigerian Property & Vehicle Marketplace  
**Date:** October 20, 2026  
**Status:** Internal Review Draft (Stage C Deliverable)

---

## 1. Data Inventory Matrix

| Data Subject Category | Data Elements Collected | Purpose of Processing | Lawful Basis (NDPA 2023) | Storage Location & Table | Access / Recipients | Retention Rule |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **Buyer / Tenant (Inquirer)** | Full Name, Email Address, Phone Number | Facilitate property/vehicle inquiries to listing vendors | Performance of contract (Marketplace Facilitation) / Legitimate Interest | `wp_propvendor_enquiries`<br>`wp_propvendor_threads` | System Admin, Listing Vendor (unlocked via credit/VIP only) | Active thread lifecycle + audit log (Recommended: 24 months post-inquiry) |
| **Buyer / Tenant (Inquirer)** | Inquiry Message Text | Convey inquiry specifics, inspection requests, or counteroffers | Performance of contract | `wp_propvendor_enquiries`<br>`wp_propvendor_messages` | Inquirer, Vendor, System Moderation Admin | Thread lifecycle |
| **Visitor / Inquirer** | IP Address | Rate limiting (max 5 inquiries/interval), spam mitigation, security audit | Legitimate Interest / Security | `wp_propvendor_enquiries.ip_address`<br>WordPress Transient Cache (`pv_rate_*`) | Server logs, Moderation Admin | Transients expire in minutes; audit IP retained with enquiry |
| **Vendor / Agent** | First Name, Last Name, Business Name, Phone Number, Email Address | Account registration, vendor portal access, contact directory | Performance of contract | `wp_users`<br>`wp_usermeta` | System Admin, Public Marketplace (Business name & verified badge) | Duration of active vendor account + 6 years legal limitation |
| **Vendor / Agent** | Business Type, Verification Details / Notes | Vendor vetting, KYC due diligence, anti-fraud evaluation | Legal Obligation / Legitimate Interest | `wp_usermeta` (`propvendor_verification_details`) | Platform Compliance & Verification Team | Duration of vendor registration + audit period |
| **Vendor / Agent** | Identity / Property Documents (Proof of ownership, CAC, etc.) | Listing validation, ownership verification, regulatory compliance | Legitimate Interest / Fraud Prevention | `wp-content/uploads/propvendor-private/` (Protected via .htaccess / web.config) | Restricted Admin / Verification Staff only | Duration of listing active period + 3 years |
| **Vendor / Agent** | Listing Attributes (Address, State, Title Document Type, Price, Specifications) | Public advertising of property, land, or vehicle | Performance of contract | `wp_posts` (`property_listing`, `land_listing`)<br>`wp_postmeta` | Public Web Visitors | Duration of listing publication |
| **Vendor / Agent** | Payment Records (Transaction Reference, Plan ID, Amount in Kobo, Order Status) | Subscription billing, credit package purchases, ledger reconciliation | Performance of contract / Financial Recordkeeping | `wp_propvendor_orders`<br>`wp_propvendor_credit_ledger` | System Admin, Vendor (own ledger), Paystack | 7 years (Statutory Nigerian financial recordkeeping) |
| **All Users** | Session Cookies, Nonces | CSRF protection, session authentication, login state | Strictly Necessary | Browser Local Storage / Cookies | User browser only | Session / 14 days (WP remember-me) |

---

## 2. Third-Party Data Processors

1. **Paystack Payments Limited (Nigeria):**
   - **Role:** Payment Gateway & Merchant of Record for online transactions.
   - **Data Shared:** Transaction reference, order amount (kobo), currency (`NGN`), customer email (transmitted during checkout redirect).
   - **Security:** PCI-DSS Level 1 compliant. Card and bank details are handled exclusively on Paystack's secure servers; PropVendor never collects, handles, or stores PAN/CVV data.
2. **Transactional Email Provider (WP Mail SMTP / Google Workspace TLS):**
   - **Role:** Delivery of account activation notices, password resets, and vendor lead notifications.
   - **Data Shared:** Recipient email, recipient name, notification subject, and lead message snippet.
   - **Security:** TLS 1.3 / Port 587 encrypted transit.
3. **Web Hosting Infrastructure (XAMPP / Target Staging Linux Host):**
   - **Role:** Infrastructure, database hosting, and server log retention.
   - **Data Shared:** HTTP request logs, remote IP addresses, user agents.

