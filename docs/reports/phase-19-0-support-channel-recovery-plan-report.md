# PropVendor Phase 19.0 — Support Channel Recovery Plan (READ-ONLY)

**Project:** PropVendor Technologies  
**Production URL:** `https://propvendor.com.ng`  
**Staging URL:** `https://staging.propvendor.com.ng`  
**Repository:** `afriabglobalventures-web/propvendor`  
**Baseline Git Commit:** `005b99c`  
**Execution Timestamp:** October 26, 2026 — 23:10 UTC+1  
**Audit Posture:** **STRICTLY READ-ONLY** (Zero code edits, zero DNS mutations, zero database updates)  
**Status:** **AUDIT COMPLETE & RECOVERY PLAN FORMULATED**  

---

## 1. Executive Summary

During the Phase 18.9 Domain 5 Business Readiness Review, a critical defect was identified: all publicly displayed customer support, trust & safety, and privacy email addresses (`support@propvendor.ng`, `safety@propvendor.ng`, `privacy@propvendor.ng`) belong to an **unresolved, non-existent domain (`propvendor.ng`)**, guaranteeing that all inbound customer inquiries and fraud reports bounce immediately.

This **Phase 19.0 Support Channel Recovery Plan** provides a comprehensive, read-only audit of:
1. Current WordPress options, site URLs, and plugin email configuration.
2. Production DNS health (MX, SPF, DKIM, DMARC) for `propvendor.com.ng` and delegation status for `propvendor.ng`.
3. Server-level outbound mail transport mechanisms and deliverability risks.
4. Exact, line-by-line remediation steps to consolidate all email routing to `@propvendor.com.ng` and establish authenticated SMTP via Hostinger.

---

## 2. Mailbox & Domain Routing Audit

### 2.1 WordPress Options & Plugin Configuration

| Configuration Element | Current Configured Value | Status / Operational Implication |
| :--- | :--- | :--- |
| **WordPress `siteurl`** | `https://propvendor.com.ng` | Active production canonical URL |
| **WordPress `home`** | `https://propvendor.com.ng` | Active production home URL |
| **WordPress `admin_email`** | Site Administrator email | Primary fallback in `propvendor_marketplace_handle_contact_submission()` |
| **Support Email Helper** | `propvendor_marketplace_get_support_email()` returns `support@propvendor.ng` | **DEFECT:** Returns dead domain address |
| **Sender Email Fallback** | `propvendor_marketplace_get_notification_sender_email()` falls back to `no-reply@propvendor.ng` | **DEFECT:** Fallback returns dead domain address |
| **Privacy Policy Copy** | References `https://propvendor.ng`, `privacy@propvendor.ng`, and `support@propvendor.ng` | **DEFECT:** Displays dead domain addresses |
| **Help & FAQs Copy** | References `safety@propvendor.ng` and `support@propvendor.ng` | **DEFECT:** Displays dead domain addresses |

### 2.2 DNS Records Verification for `propvendor.com.ng`

Live DNS queries were executed against global nameservers:

* **MX Records:**
  - `mx1.hostinger.com` (Preference `5`, TTL `14400`) — **RESOLVED & ACTIVE**
  - `mx2.hostinger.com` (Preference `10`, TTL `14400`) — **RESOLVED & ACTIVE**
  - *Assessment:* Hostinger mail routing infrastructure is fully provisioned and ready to accept inbound email for any mailbox created under `@propvendor.com.ng`.
* **TXT / SPF Record:**
  - Host: `propvendor.com.ng`
  - Value: `v=spf1 include:_spf.mail.hostinger.com ~all` (TTL `3600`) — **RESOLVED & ACTIVE**
  - *Assessment:* Authorizes Hostinger outgoing mail relays. Does *not* authorize direct transmission from the shared web server IP via unauthenticated PHP `mail()`.
* **DMARC Record:**
  - Host: `_dmarc.propvendor.com.ng`
  - Value: `v=DMARC1; p=none` (TTL `3600`) — **RESOLVED & ACTIVE**
  - *Assessment:* Configured in monitoring mode (`p=none`). Requires valid SPF or DKIM alignment to pass modern inbox provider checks.
* **DKIM (DomainKeys Identified Mail):**
  - Host: `hostingermail-a._domainkey.propvendor.com.ng`
  - CNAME Target: `hostingermail-a.dkim.mail.hostinger.com`
  - Public Key: Active 2048-bit RSA key (`v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAs+Rij...IDAQAB`) — **RESOLVED & ACTIVE**
  - *Assessment:* Hostinger's DKIM signature is fully deployed in DNS. Any email routed through Hostinger's authenticated SMTP (`smtp.hostinger.com`) is cryptographically signed and passes DKIM verification 100%.

### 2.3 Delegation & Registry Status for `propvendor.ng`

* Authoritative DNS queries were executed against Google Public DNS (`8.8.8.8`) and Cloudflare DNS (`1.1.1.1`):
  - Result: **`DNS_ERROR_RCODE_NAME_ERROR` (`NXDOMAIN` — RCODE 3)**
  - NS Records: `0` (None)
  - SOA Records: `0` (None)
  - MX Records: `0` (None)
  - A Records: `0` (None)
* *Conclusion:* The apex domain `propvendor.ng` has **no registrar delegation in the NiRA `.ng` registry**. It is strictly unregistered and non-existent.
* *Business Impact:* Every message sent to `support@propvendor.ng`, `safety@propvendor.ng`, or `privacy@propvendor.ng` will bounce immediately with host unreachable / non-existent domain error.

---

## 3. Outbound Mail Transport Verification

### 3.1 WordPress Mail Transport Audit
* Probed active plugins on production (`https://propvendor.com.ng`):
  - `wp-mail-smtp`: **HTTP 404 (Not Installed)**
  - `easy-wp-smtp`: **HTTP 404 (Not Installed)**
  - `fluent-smtp`: **HTTP 404 (Not Installed)**
  - `post-smtp`: **HTTP 404 (Not Installed)**
* Plugin code inspection:
  - `propvendor_marketplace_handle_contact_submission()` calls native `@wp_mail($admin_email, ...)`.
  - `propvendor_marketplace_send_notification()` calls native `wp_mail($recipient_email, ...)`.
* *Finding:* The production site relies entirely on the default, unauthenticated PHP `mail()` function (`PHPMailer` with `isMail()`).

### 3.2 Spam & Deliverability Risk Assessment

| Risk Vector | Technical Cause | Severity | Real-World Impact |
| :--- | :--- | :---: | :--- |
| **SPF SoftFail / Rejection** | PHP `mail()` dispatches from the shared web server IP, which is not listed in `include:_spf.mail.hostinger.com`. | **HIGH** | Inbound servers mark emails as suspicious or softfailed (`~all`). |
| **DKIM Signature Absence** | Hostinger's DKIM key is injected only when passing through `smtp.hostinger.com`. Native PHP `mail()` cannot sign DKIM. | **HIGH** | Modern providers (Google, Yahoo, Microsoft) require DKIM for business senders. Emails land in Spam. |
| **DMARC Alignment Failure** | With SPF softfailing and DKIM absent, DMARC alignment fails completely. | **CRITICAL** | Since February 2024, Google/Yahoo sender policies penalize or reject unaligned transactional mail. |
| **Hostinger Shared Throttling** | Hostinger shared hosting limits PHP `mail()` (typically 50–100 messages/hour). | **MEDIUM** | Surge in inquiries or password resets can trigger silent mail drops or host throttling. |

---

## 4. Proposed Remediation Plan

### 4.1 Hostinger hPanel Email Setup (Infrastructure Actions)

In Hostinger hPanel under `propvendor.com.ng` -> **Emails**:
1. **Create Designated Mailboxes:**
   - `support@propvendor.com.ng`: Primary customer, vendor, and general operational support.
   - `safety@propvendor.com.ng`: Trust & safety, anti-fraud reports, unverified agent alerts.
   - `privacy@propvendor.com.ng`: Data subject access requests (NDPA compliance desk).
   - `no-reply@propvendor.com.ng`: System-generated notifications, lead unlock alerts, password resets.
   *(Note: If mailbox licenses are limited, create `support@propvendor.com.ng` as the primary mailbox, and configure email aliases/forwarders for `safety@`, `privacy@`, and `no-reply@` pointing to `support@propvendor.com.ng`).*
2. **Retrieve Authenticated SMTP Connection Parameters:**
   - **SMTP Server:** `smtp.hostinger.com`
   - **SMTP Port:** `465` (SSL) or `587` (TLS)
   - **Authentication:** Yes (Requires username & password)
   - **Recommended App Password:** Generate a dedicated password in hPanel for WordPress transactional email.

---

### 4.2 Exact Code Modifications in `propvendor-marketplace.php`

Consolidate all references from `@propvendor.ng` to `@propvendor.com.ng`:

#### 1. Official Support Helper
* **Location:** [`wp-content/plugins/propvendor-marketplace/propvendor-marketplace.php`](file:///c:/xampp/htdocs/Propvendor/wp-content/plugins/propvendor-marketplace/propvendor-marketplace.php#L4540)
* **Line 4540:**
  ```php
  // Current:
  return apply_filters('propvendor_marketplace_support_email', 'support@propvendor.ng');

  // Proposed:
  return apply_filters('propvendor_marketplace_support_email', 'support@propvendor.com.ng');
  ```

#### 2. Notification Sender Fallback
* **Location:** [`wp-content/plugins/propvendor-marketplace/propvendor-marketplace.php`](file:///c:/xampp/htdocs/Propvendor/wp-content/plugins/propvendor-marketplace/propvendor-marketplace.php#L11893-L11902)
* **Lines 11893–11902:**
  ```php
  // Current:
  $host = wp_parse_url(home_url(), PHP_URL_HOST);
  if (empty($host) || strpos($host, '.') === false) {
      $host = 'propvendor.ng';
  }
  $fallback = 'no-reply@' . $host;
  if (!is_email($fallback)) {
      $fallback = 'no-reply@propvendor.ng';
  }

  // Proposed:
  $host = wp_parse_url(home_url(), PHP_URL_HOST);
  if (empty($host) || strpos($host, '.') === false) {
      $host = 'propvendor.com.ng';
  }
  $fallback = 'no-reply@' . $host;
  if (!is_email($fallback)) {
      $fallback = 'no-reply@propvendor.com.ng';
  }
  ```

#### 3. Privacy Policy Content Strings
* **Location:** [`wp-content/plugins/propvendor-marketplace/propvendor-marketplace.php`](file:///c:/xampp/htdocs/Propvendor/wp-content/plugins/propvendor-marketplace/propvendor-marketplace.php#L1442-L1553)
* **Line 1442:** Replace `https://propvendor.ng` with `https://propvendor.com.ng`.
* **Lines 1447–1448:**
  ```html
  <!-- Current: -->
  <li><strong>Privacy Office Email:</strong> <code>privacy@propvendor.ng</code></li>
  <li><strong>Customer Support Email:</strong> <code>support@propvendor.ng</code></li>

  <!-- Proposed: -->
  <li><strong>Privacy Office Email:</strong> <code>privacy@propvendor.com.ng</code></li>
  <li><strong>Customer Support Email:</strong> <code>support@propvendor.com.ng</code></li>
  ```
* **Line 1540 & Line 1552:** Replace `privacy@propvendor.ng` with `privacy@propvendor.com.ng`.
* **Line 1553:** Replace `support@propvendor.ng` with `support@propvendor.com.ng`.

#### 4. Help & FAQs Content Strings
* **Location:** [`wp-content/plugins/propvendor-marketplace/propvendor-marketplace.php`](file:///c:/xampp/htdocs/Propvendor/wp-content/plugins/propvendor-marketplace/propvendor-marketplace.php#L1597-L4791)
* **Line 1597:** Replace `safety@propvendor.ng` with `safety@propvendor.com.ng`.
* **Line 4704 & Line 4791:** Replace `support@propvendor.ng` with `support@propvendor.com.ng`.

#### 5. Authenticated SMTP Transport Hook
Add a native, lightweight PHPMailer SMTP hook to `propvendor-marketplace.php` so the site relays via Hostinger without requiring bloated third-party plugins:
```php
/**
 * Phase 19.0: Authenticated Hostinger SMTP Relay Hook.
 */
function propvendor_marketplace_configure_phpmailer_smtp($phpmailer) {
    $smtp_enabled = get_option('propvendor_smtp_enabled', 1);
    if (!$smtp_enabled) {
        return;
    }

    $phpmailer->isSMTP();
    $phpmailer->Host       = get_option('propvendor_smtp_host', 'smtp.hostinger.com');
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Port       = (int) get_option('propvendor_smtp_port', 465);
    $phpmailer->Username   = get_option('propvendor_smtp_username', 'support@propvendor.com.ng');
    $phpmailer->Password   = get_option('propvendor_smtp_password', '');
    $phpmailer->SMTPSecure = get_option('propvendor_smtp_secure', 'ssl');
    $phpmailer->From       = get_option('propvendor_mail_sender_email', 'no-reply@propvendor.com.ng');
    $phpmailer->FromName   = get_option('propvendor_mail_sender_name', 'PropVendor Marketplace');
}
add_action('phpmailer_init', 'propvendor_marketplace_configure_phpmailer_smtp');
```

---

### 4.3 Test Suite & Legal Documentation Alignment

1. **Unit Test Suite:**
   - File: `wp-content/plugins/propvendor-marketplace/tests/test_phase14_comprehensive_e2e_staging_qa.php`
   - Line 336: Update test assertion expectation from `'support@propvendor.ng'` to `'support@propvendor.com.ng'`.
2. **Legal Documentation Drafts:**
   - Update `docs/legal/privacy-policy-draft-ndpa.md`, `docs/legal/terms-of-service-draft.md`, and `docs/legal/ndpa-compliance-checklist.md` to reflect `@propvendor.com.ng`.
   - Update `docs/legal/unresolved-business-decisions.md`: Mark Item 2 (Privacy and Support Mailbox) as **RESOLVED** with canonical domain `propvendor.com.ng`.

---

## 5. Implementation Roadmap & Execution Checklist

| Step | Action | Environment | Responsibility | Status |
| :---: | :--- | :---: | :---: | :---: |
| **1** | Create `support@`, `safety@`, `privacy@`, `no-reply@` in Hostinger hPanel. | Production | System Admin / Owner | **PENDING** |
| **2** | Update `propvendor-marketplace.php` email strings & default helpers to `@propvendor.com.ng`. | Staging / Local | Engineering | **READY** |
| **3** | Update unit test assertions in `test_phase14_comprehensive_e2e_staging_qa.php`. | Local | QA | **READY** |
| **4** | Deploy updated plugin to Staging & verify with test contact submission. | Staging | Engineering | **PENDING** |
| **5** | Configure Hostinger SMTP credentials in Staging WP Admin and verify DKIM/SPF on outbound mail. | Staging | Engineering | **PENDING** |
| **6** | Package POSIX release ZIP and deploy to Production following owner sign-off. | Production | Release Lead | **PENDING** |

---

## 6. Read-Only Compliance Certification

This audit and remediation plan was executed strictly in a **read-only posture**:
* Zero lines of code were modified.
* Zero DNS records were changed.
* Zero database entries or options were altered.
* Zero emails were dispatched.
* Zero Git commits or production deployments were triggered.

