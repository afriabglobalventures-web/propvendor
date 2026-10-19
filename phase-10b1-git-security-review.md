# PropVendor Phase 10B.1 — Git & Configuration Security Review

**Project:** PropVendor Technologies  
**Local Environment:** `http://localhost/Propvendor/`  
**Repository Directory:** `C:\xampp\htdocs\Propvendor`  
**Audit Date:** October 19, 2026  
**Status:** Inspection & Proposal Phase (No unapproved changes applied)  

---

## 1. Initial Git Status & Working Tree State

| Parameter | Current State | Notes |
| :--- | :--- | :--- |
| **Current Branch** | `main` | Tracking `origin/main` |
| **Branch Divergence** | Ahead by 15 commits | Local commits ready for eventual remote sync |
| **Latest Commit** | `a41eeab` | *docs: complete Phase 10A pre-launch audit report with 819-assertion runner* |
| **Unstaged Changes** | 1 file | `wp-content/plugins/propvendor-marketplace/assets/css/front-end.css` (Fluid heading `clamp()` micro-fix) |
| **Untracked Files** | None | Working tree is clean |
| **Whitespace Quality** | 0 errors | Verified via `git diff --check` |
| **Automated Tests** | 14/14 suites passing | 819/819 assertions passing (100% pass rate in 62.25s) |

---

## 2. Configuration Tracking & Ignore Findings

### A. Root `wp-config.php` Tracking Finding
- **Current Status:** `wp-config.php` is **currently tracked in Git's index** (Blob SHA: `7f076679c0b7ab4c7e0932edb79439c610d39714`).
- **Location:** `C:\xampp\htdocs\Propvendor\wp-config.php`.
- **Root Cause:** When the WordPress site was initially initialized in commit `6a8d208`, `wp-config.php` was staged and committed before a `.gitignore` rule was added to the root directory.

### B. Root vs. Subdirectory `.gitignore` Misalignment
- The repository contains two `.gitignore` files:
  1. `C:\xampp\htdocs\Propvendor\.gitignore` (Root)
  2. `C:\xampp\htdocs\Propvendor\wp-content\.gitignore` (Subdirectory)
- **The Misconfiguration:** The root `.gitignore` contains only generic Node.js and editor ignores (`node_modules`, `dist`, `.vscode`, `.idea`). It contains **no WordPress-specific ignore rules**.
- Conversely, `wp-content/.gitignore` contains `wp-config.php` on line 1. Because Git evaluates paths relative to the directory where the `.gitignore` file resides, `wp-content/.gitignore` only instructs Git to ignore `wp-content/wp-config.php`. It has **no effect** on the actual configuration file at the repository root (`/wp-config.php`).

---

## 3. Git History & Secret Scanning Analysis

A historical scan of all commits touching configuration files was conducted:

```
6a8d208 Initial WordPress setup (wp-config.php added)
783b49b Save local settings (added XAMPP OPENSSL_CONF path and WP_ENVIRONMENT_TYPE = 'local')
```

### Analysis of Historical Content
1. **Database Credentials:** The committed `wp-config.php` only ever contained local development settings (`DB_NAME = 'Propvendor'`, `DB_USER = 'root'`, `DB_PASSWORD = ''`). No live production passwords, remote database hosts, or cloud credentials exist in Git history.
2. **Third-Party API Keys:** Paystack API keys are stored in the WordPress options database table (`wp_options`), not in `wp-config.php`. No Paystack secret keys exist in `wp-config.php` or Git history.
3. **WordPress Salts:** WordPress security keys and salts generated at initial installation exist in history. These salts must be regenerated when creating the staging/production configuration.
4. **History Rewriting Verdict:** Because no production secrets exist in Git history, **destructive Git history rewriting (such as `git filter-repo` or BFG) is NOT recommended**. Rewriting history would break commit hashes, desynchronize the 15 unpushed commits, and risk repository corruption without providing tangible security benefit.
5. **Recommended Remediation:** Untrack the file from Git's index (`git rm --cached wp-config.php`), add it to the root `.gitignore`, and use fresh, unique database credentials and salt keys on the staging host.

---

## 4. Proposed Changes

### A. Proposed Root `.gitignore` Update
Add dedicated WordPress environment and media rules to `C:\xampp\htdocs\Propvendor\.gitignore`:

```gitignore
# Logs
logs
*.log
npm-debug.log*
yarn-debug.log*
yarn-error.log*
pnpm-debug.log*
lerna-debug.log*

node_modules
dist
dist-ssr
*.local

# Editor directories and files
.vscode/*
!.vscode/extensions.json
.idea
.DS_Store
*.suo
*.ntvs*
*.njsproj
*.sln
*.sw?

# =========================================================================
# WordPress Environment & Security Lockdown (Phase 10B.1)
# =========================================================================
wp-config.php
wp-config-local.php
.env
.env.*
*.sql
*.sqlite
*.bak
*.backup

# WordPress Uploads & Cache
wp-content/uploads/
wp-content/upgrade/
wp-content/debug.log
wp-content/backups/
```

### B. Proposed `wp-config-sample.php` Template Update
The repository already contains a default `wp-config-sample.php`. We propose modernizing it to include clear placeholders for database credentials, salt keys, and staging/production environment hardening constants without exposing any real values:

```php
<?php
/**
 * The base configuration for WordPress (PropVendor Deployment Template)
 *
 * Copy this file to "wp-config.php" on your target environment (local, staging, or production)
 * and provide the appropriate credentials and environment constants.
 *
 * NEVER commit the active "wp-config.php" containing real credentials to version control.
 */

// ** Database settings - Obtain from your web hosting provider or server admin ** //
define( 'DB_NAME', 'database_name_here' );
define( 'DB_USER', 'username_here' );
define( 'DB_PASSWORD', 'password_here' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 * Generate fresh salts using: https://api.wordpress.org/secret-key/1.1/salt/
 */
define( 'AUTH_KEY',         'put your unique phrase here' );
define( 'SECURE_AUTH_KEY',  'put your unique phrase here' );
define( 'LOGGED_IN_KEY',    'put your unique phrase here' );
define( 'NONCE_KEY',        'put your unique phrase here' );
define( 'AUTH_SALT',        'put your unique phrase here' );
define( 'SECURE_AUTH_SALT', 'put your unique phrase here' );
define( 'LOGGED_IN_SALT',   'put your unique phrase here' );
define( 'NONCE_SALT',       'put your unique phrase here' );
/**#@-*/

$table_prefix = 'wp_';

/**
 * =========================================================================
 * Environment & Security Settings
 * =========================================================================
 */

/* Environment Type: 'local', 'staging', or 'production' */
define( 'WP_ENVIRONMENT_TYPE', 'staging' );

/* Debugging & Logging Controls */
define( 'WP_DEBUG', false );
define( 'WP_DEBUG_LOG', true );             // Errors logged to wp-content/debug.log
define( 'WP_DEBUG_DISPLAY', false );        // Suppress on-screen error displays
@ini_set( 'display_errors', '0' );
define( 'SCRIPT_DEBUG', false );

/* Security Hardening */
define( 'DISALLOW_FILE_EDIT', true );       // Disable in-dashboard theme/plugin code editor
define( 'FORCE_SSL_ADMIN', true );          // Require HTTPS for all admin and login sessions

/* SSL Reverse Proxy Support (Uncomment if using Cloudflare or Nginx reverse proxy) */
// if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
//     $_SERVER['HTTPS'] = 'on';
// }

/* That's all, stop editing! Happy publishing. */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
```

---

## 5. Debug Configuration Review

### Current Local `wp-config.php` State:
```php
define( 'WP_DEBUG', false );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
```

### Analysis of Current Constants:
1. `WP_DEBUG`: Currently `false`.
2. `WP_DEBUG_LOG`: **Undefined**. Errors are not being captured in `wp-content/debug.log`.
3. `WP_DEBUG_DISPLAY`: **Undefined**. Defaults to PHP's `display_errors` configuration (on local XAMPP, warnings and notices can display in HTML, which could corrupt JSON responses from REST API or AJAX).
4. `FORCE_SSL_ADMIN`: **Undefined**. Defaults to `false`.
5. `DISALLOW_FILE_EDIT`: **Undefined**. The WordPress theme/plugin file editor remains accessible in WP Admin.

### Recommended Environment-Specific Configurations:

#### For Local Development (`http://localhost/Propvendor/`):
- Maintain `FORCE_SSL_ADMIN = false` because local XAMPP runs over HTTP. Setting `FORCE_SSL_ADMIN = true` locally would create an HTTPS redirect loop and lock out the administrator.
- Keep `WP_DEBUG_DISPLAY = false` and `WP_DEBUG_LOG = true` so errors are logged cleanly without disrupting local AJAX responses.

#### For Staging Environment (`https://staging.propvendor.ng/`):
```php
define( 'WP_ENVIRONMENT_TYPE', 'staging' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
@ini_set( 'display_errors', '0' );
define( 'SCRIPT_DEBUG', false );
define( 'FORCE_SSL_ADMIN', true );
define( 'DISALLOW_FILE_EDIT', true );
```

---

## 6. Safety Backup Verification

A timestamped backup of all configuration files has been archived outside the Git repository:
- **Backup Directory:** `C:\xampp\htdocs\Propvendor_backups\Phase10B1_20261019\`
- **Archived Files:**
  - `wp-config.php` (3,893 bytes)
  - `root.gitignore.bak` (277 bytes)
  - `wp-content.gitignore.bak` (58 bytes)
  - `wp-config-sample.php` (3,339 bytes)

---

## 7. Exact Proposed Commands & Operational Effects

When approved by the project owner, the following exact commands will be executed:

```powershell
# 1. Update root .gitignore to exclude wp-config.php, backups, and uploads
# (Modifies .gitignore to ensure Git ignores future changes to wp-config.php)

# 2. Update wp-config-sample.php with modernized template
# (Ensures future deployments have a clean, documented template without secrets)

# 3. Untrack wp-config.php from Git index without deleting the physical file:
git rm --cached wp-config.php

# 4. Verify working tree status:
git status --short
# Expected output:
# D  wp-config.php (staged for deletion from index)
# M  .gitignore (staged or unstaged)
# M  wp-config-sample.php (staged or unstaged)
# Note: Physical C:\xampp\htdocs\Propvendor\wp-config.php remains intact on disk.

# 5. Verify local site health:
# Confirm HTTP 200 on http://localhost/Propvendor/
# Run tests/run-all-tests.php to ensure all 819 assertions remain 100% passing.
```

### Detailed Impact of `git rm --cached wp-config.php`:
- **Physical File on Disk:** **NOT deleted**. The file `C:\xampp\htdocs\Propvendor\wp-config.php` remains present and functional.
- **Local WordPress Functionality:** Completely unaffected. Apache and PHP continue loading `wp-config.php`.
- **Git Repository State:** `wp-config.php` transitions to a staged deletion in the index. Because `.gitignore` will now contain `wp-config.php`, Git will no longer track or commit changes to this file in the future.
- **Future Staging / Production Clones:** When the repository is cloned to a staging server, Git will not create a `wp-config.php`. The server administrator will copy `wp-config-sample.php` to `wp-config.php` and supply server-specific credentials.

---

## 8. Risks and Owner Decisions Required

### Decision 1: Authorization to Untrack `wp-config.php`
- **Action:** Execute `git rm --cached wp-config.php` and update root `.gitignore`.
- **Benefit:** Prevents accidental commits of database passwords or sensitive configuration changes.
- **Risk:** None for local development (physical file is preserved and backed up).

### Decision 2: Local `wp-config.php` Debug Hardening
- **Action:** Update local `wp-config.php` to explicitly define `WP_DEBUG_LOG = true` and `WP_DEBUG_DISPLAY = false`.
- **Benefit:** Prevents PHP notices from breaking frontend AJAX/JSON responses while logging issues to `wp-content/debug.log`.

### Decision 3: Staging Server Preparation
- **Action:** Prepare staging host with fresh database credentials and unique security salts generated via WordPress.org API.
- **Decision:** Confirm staging server domain name and whether staging will use an SSL certificate.

---

*This review is complete. No changes have been made to repository tracking, `.gitignore`, or `wp-config.php`. Awaiting your review and approval before executing the proposed commands.*

