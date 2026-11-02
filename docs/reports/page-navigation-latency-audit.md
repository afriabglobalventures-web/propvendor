# PropVendor — Page-to-Page Navigation Latency & Query Transient Audit

**Project:** PropVendor Technologies  
**Milestone:** Phase 19.3 Operational Performance & Asset Optimization  
**Environment:** Local (`c:\xampp\htdocs\Propvendor`), Staging (`https://staging.propvendor.com.ng`), Production (`https://propvendor.com.ng`)  
**Date:** November 2026  
**Status:** Ready for Staging / Production Deployment  

---

## 1. Executive Summary

This audit and performance optimization addresses page-to-page navigation latency across the PropVendor marketplace platform. The investigation targeted three interconnected performance bottlenecks:

1. **Hover-to-Navigation Idle Time:** Chromium browsers had the native WordPress Speculation Rules configured to `"eagerness": "conservative"` (triggering only on mouse-down, yielding near-zero preloading advantage), while Safari, Firefox, and legacy browsers had zero hover prefetching enabled.
2. **Repetitive Database Queries:** Public listing queries (such as featured listings on the homepage) and administrative state lists were reconstructed and queried on every single request without transient persistence.
3. **External Gateway Blocking:** Third-party scripts (`js.paystack.co`) lacked preconnect/dns-prefetch resource hints and explicit script execution deferrals.

All three layers have been comprehensively optimized. Local validation confirms 22/22 automated performance and safety tests passing with zero regressions to Phase 12/14 test invariants.

---

## 2. Diagnostics & Root Cause Analysis

### 2.1 Preload & Speculation Rules Analysis
* **Observed Configuration:** WordPress 6.8+ ships with native speculative loading via `wp-includes/speculative-loading.php`. The core default configuration defaults to `'mode' => 'prefetch'` with `'eagerness' => 'conservative'`.
* **Latency Impact:** `"conservative"` eagerness only prefetches when the user presses mouse down (`pointerdown`). Because average human click duration is only 50–100ms, the prefetch request cannot complete over typical cellular/mobile network latencies before navigation commences.
* **Non-Chromium Gap:** Safari and Firefox do not currently support the Speculation Rules API. Without `instant.page` or link prefetching polyfills, visitors on these platforms experienced full end-to-end round-trip latency on every link click.

### 2.2 Database Query & Transient Inspection
* **Public Listing Sub-Queries:** The homepage calls `propvendor_marketplace_listings_query(8)` to display featured marketplace listings. Even though `'no_found_rows' => true` was present, the query performed a multi-table SQL JOIN against `wp_postmeta` (checking `propvendor_listing_status` and `propvendor_status`) on every uncached page view.
* **Geographical State Retrieval:** `propvendor_marketplace_get_nigerian_states()` was invoked across the hero search bar, search filter shortcode, submit form, and mobile drawer. Caching this list avoids repetitive in-memory parsing.
* **Category Count Aggregation:** Browse templates and category showcases benefit from transient caching of published listing counts to avoid counting queries during high-concurrency browsing.

### 2.3 Asset Delivery & Parser-Blocking Inspection
* **CSS:** Verified that `assets/css/front-end.css` contains zero `@import` rules and uses `font-display: swap;` for Dashicons and Astra icon fonts, preventing Flash of Invisible Text (FOIT).
* **JavaScript:** `paystack-inline` was loaded without `'strategy' => 'defer'`. External DNS resolution to `js.paystack.co` was deferred until inline execution rather than pre-resolved in the document head.

---

## 3. Implemented Optimizations

### 3.1 Instant Click & Speculation Rules Acceleration
1. **Speculation Rules Hook (`wp_speculation_rules_configuration`):**
   - Configured WordPress Core to utilize `'mode' => 'prefetch'` with `'eagerness' => 'moderate'`.
   - Moderate eagerness begins prefetching upon hover (hover intent with a 200ms debounce) or pointer enter, granting 200–400ms head start before click.
2. **Speculation Safety Exclusions (`wp_speculation_rules_href_exclude_paths`):**
   - Strictly excluded private endpoints: `/vendor-wallet*`, `/vendor-dashboard*`, `/checkout*`, `/wp-admin*`, `/wp-login.php*`, and `/*?*action=logout*`.
3. **Speculation Rules Fallback:**
   - Added `propvendor_marketplace_render_speculation_rules_fallback()` for environments where WordPress core speculative loading is inactive or logged out.
4. **Universal Hover Polyfill (`instantpage.min.js` v5.2.0):**
   - Integrated lightweight (1.2 KB) `instantpage.min.js` with 65ms hover debounce and mobile `touchstart` preloading.
   - Enqueued via `wp_enqueue_script('propvendor-instant-page')` with `'strategy' => 'defer'` and `'in_footer' => true`.
   - Strictly enforces exclusions for administrative pages, CSRF query nonces, logout actions, and `no-instant` selectors.

### 3.2 Database Query & Transient Caching
1. **State Dropdown Caching:**
   - Updated `propvendor_marketplace_get_nigerian_states()` with `get_transient('pv_nigerian_states')` and `set_transient('pv_nigerian_states', $states, WEEK_IN_SECONDS)`.
2. **Public Listings Query Transient:**
   - Updated `propvendor_marketplace_listings_query($count)` to store published post IDs in `pv_public_listings_{count}` with a 1-hour TTL.
   - Hydrates post objects from internal object cache, eliminating database meta joins on repeated hits.
3. **Category Counts Transient:**
   - Implemented `propvendor_marketplace_get_category_counts()` with `pv_category_counts` transient (1-hour TTL).
4. **Automated Cache Busting:**
   - Enhanced `propvendor_marketplace_invalidate_listing_count_cache()` to flush `pv_category_counts` and all `pv_public_listings_*` transients whenever a listing is created, updated, trashed, or changes status (`save_post`, `deleted_post`, `transition_post_status`).
5. **No Found Rows Passthrough:**
   - Added `'no_found_rows'` parameter passthrough in `propvendor_marketplace_get_search_query_args()`, allowing sub-queries to bypass `SQL_CALC_FOUND_ROWS`.

### 3.3 Asset Pipeline & Resource Hints
1. **Resource Hints (`wp_resource_hints`):**
   - Added `dns-prefetch` and `preconnect` hints for `https://js.paystack.co` to eliminate DNS lookup latency when checkout or wallet modals open.
2. **Script Tag Deferral Filter (`script_loader_tag`):**
   - Added `propvendor_marketplace_defer_scripts()` filter to guarantee physical `defer` attributes on `paystack-inline`, `propvendor-instant-page`, and `propvendor-marketplace-frontend`.
3. **Phase 14 Test Invariant Preservation:**
   - Preserved exact formatting of `wp_enqueue_style('propvendor-marketplace', plugins_url('assets/css/front-end.css', __FILE__), array(), time())` to ensure compatibility with `test_phase14_comprehensive_e2e_staging_qa.php` line 344.

---

## 4. Verification & QA Results

| Verification Item | Test / Command | Result |
| :--- | :--- | :--- |
| **PHP Syntax Check** | `php -l propvendor-marketplace.php` | PASS (0 syntax errors) |
| **Phase 14 Style Invariant** | Regex assertion matching line 344 of Phase 14 test | PASS |
| **Instant.page Asset** | Version check (v5.2.0, 65ms debounce, safety paths) | PASS |
| **Speculation Rules Config** | Filter verification (`eagerness: moderate`) | PASS |
| **Speculation Path Exclude** | Exclusions for wallet, dashboard, admin, logout | PASS |
| **Transient Caching** | State list (`pv_nigerian_states`, 1 week) | PASS |
| **Listing Caching** | Featured listings (`pv_public_listings_*`, 1 hour) | PASS |
| **Category Caching** | Category count transient (`pv_category_counts`, 1 hour) | PASS |
| **Transient Flushing** | Action hooks on `save_post`, `deleted_post`, `transition_post_status` | PASS |
| **Resource Hints** | `preconnect` / `dns-prefetch` on `js.paystack.co` | PASS |
| **Script Tag Deferral** | Physical `defer` attribute injection via `script_loader_tag` | PASS |
| **Header Nav Regression** | `scratch/test_filter_nav.php` (Guest, Customer, Vendor, Admin) | PASS (20/20 checks) |
| **Comprehensive Latency Suite**| `scratch/test_page_navigation_latency.php` | PASS (22/22 checks) |

---

## 5. Deployment Package Details

* **Archive Path:** `wp-content/plugins/propvendor-marketplace.zip`
* **Archive Size:** 2,684,707 bytes
* **Archive SHA-256:** `7496296E5BEE5EDA39FB0F3CAD95BF58710858BB09631FB59FDF8B13658F30ED`
* **Format:** POSIX forward slashes, UNIX file attributes (0644).

---

## 6. Staging / Production Promotion Checklist

1. **Deploy Plugin Package:**
   - Upload and extract `propvendor-marketplace.zip` (SHA-256: `7496296E5BEE5EDA39FB0F3CAD95BF58710858BB09631FB59FDF8B13658F30ED`) into `wp-content/plugins/` on staging/production.
2. **Purge LiteSpeed Cache:**
   - Execute LiteSpeed Cache purge: "Purge All" to clear stale cached HTML.
3. **Verify DevTools Network Waterfall:**
   - Hover over navigation links (e.g. *Browse Listings*, *Safety Guidelines*).
   - In Chrome/Edge: Inspect `chrome://net-export` or DevTools Network tab to confirm speculative prefetch starts immediately on hover.
   - In Safari/Firefox: Confirm `assets/js/instantpage.min.js` issues a background prefetch `<link rel="prefetch">` or fetch with `low` priority after 65ms hover.
4. **Verify Database Performance:**
   - Confirm query transients (`pv_nigerian_states`, `pv_public_listings_8`, `pv_category_counts`) appear in `wp_options`.
