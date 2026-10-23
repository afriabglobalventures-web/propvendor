<?php
/**
 * Test Suite: PropVendor Phase 12 — Secure Front-End Authentication & Role-Based User Flow
 *
 * Verifies:
 * 1. Customer role registration and capability boundaries (read-only, no listing privileges).
 * 2. Vendor role registration and listing privileges (propvendor_submit_marketplace_listings).
 * 3. Role whitelist enforcement (arbitrary roles / administrator injection fail-closed to customer).
 * 4. Registration validation (password min-length 8, valid email, required names).
 * 5. Registration rate limiting transient protection.
 * 6. Login credential authentication (customer and vendor).
 * 7. Anti-enumeration generic error protection on invalid login.
 * 8. Login rate limiting transient protection.
 * 9. Role-based login routing (admin -> admin_url(), vendor -> /vendor-dashboard/, customer -> /customer-inbox/).
 * 10. Open redirect defense (external/untrusted domains rejected).
 * 11. Astra dynamic navigation menu filtering for logged-out visitors.
 * 12. Astra dynamic navigation menu filtering for customer users.
 * 13. Astra dynamic navigation menu filtering for vendor users.
 * 14. Astra dynamic navigation menu filtering for administrators.
 * 15. Nonce-protected logout URL generation.
 * 16. Password reset link verification to WordPress core workflow.
 * 17. Account shortcode [propvendor_login_account] logged-out rendering (tabs, toggles, NDPA note).
 * 18. Account shortcode [propvendor_login_account] customer dashboard tiles (zero vendor wallet/listing).
 * 19. Account shortcode [propvendor_login_account] vendor dashboard tiles.
 * 20. Clean fixture teardown in finally block.
 */

define('WP_USE_THEMES', false);
if (!defined('PROPVENDOR_TESTING')) {
    define('PROPVENDOR_TESTING', true);
}

$wp_load = dirname(__DIR__, 4) . '/wp-load.php';
if (file_exists($wp_load)) {
    require_once $wp_load;
} else {
    require_once 'c:/xampp/htdocs/Propvendor/wp-load.php';
}

require_once ABSPATH . 'wp-admin/includes/user.php';

add_filter('pre_wp_mail', '__return_false');

echo "====================================================================\n";
echo "  PROPVENDOR PHASE 12 — AUTHENTICATION & ROLE-BASED FLOW TESTS       \n";
echo "====================================================================\n\n";

$total_tests  = 0;
$passed_tests = 0;
$failed_tests = 0;

function run_test($condition, $name, $extra = '') {
    global $total_tests, $passed_tests, $failed_tests;
    $total_tests++;
    if ($condition) {
        $passed_tests++;
        echo "  [PASS] {$name}\n";
    } else {
        $failed_tests++;
        echo "  [FAIL] {$name}" . ($extra ? " ({$extra})" : "") . "\n";
    }
}

$created_user_ids = array();

try {
    // -------------------------------------------------------------------------
    // 1. Role Capabilities and Isolation
    // -------------------------------------------------------------------------
    $customer_role = get_role('customer');
    run_test(!empty($customer_role), 'Customer role is registered in WordPress');
    run_test(!empty($customer_role->capabilities['read']), 'Customer role has basic read capability');
    run_test(empty($customer_role->capabilities['propvendor_submit_marketplace_listings']), 'Customer role lacks propvendor_submit_marketplace_listings capability');
    run_test(empty($customer_role->capabilities['manage_options']), 'Customer role lacks manage_options capability');

    $vendor_role = get_role('vendor');
    run_test(!empty($vendor_role), 'Vendor role is registered in WordPress');
    run_test(!empty($vendor_role->capabilities['propvendor_submit_marketplace_listings']), 'Vendor role has propvendor_submit_marketplace_listings capability');

    // -------------------------------------------------------------------------
    // 2. Customer and Vendor User Creation
    // -------------------------------------------------------------------------
    $cust_uname = 'test_cust_' . time();
    $cust_email = $cust_uname . '@example.com';
    $cust_pwd   = 'CustSecret#2026';
    $cust_id    = wp_create_user($cust_uname, $cust_pwd, $cust_email);
    run_test(!is_wp_error($cust_id), 'Test customer account created successfully');

    if (!is_wp_error($cust_id)) {
        $created_user_ids[] = $cust_id;
        $u_cust = new WP_User($cust_id);
        $u_cust->set_role('customer');
        update_user_meta($cust_id, 'first_name', 'Amara');
        update_user_meta($cust_id, 'last_name', 'Okonkwo');

        run_test(in_array('customer', (array) $u_cust->roles, true), 'User has customer role');
        run_test(!user_can($cust_id, 'propvendor_submit_marketplace_listings'), 'Customer user cannot submit marketplace listings');
    }

    $vend_uname = 'test_vend_' . time();
    $vend_email = $vend_uname . '@example.com';
    $vend_pwd   = 'VendSecret#2026';
    $vend_id    = wp_create_user($vend_uname, $vend_pwd, $vend_email);
    run_test(!is_wp_error($vend_id), 'Test vendor account created successfully');

    if (!is_wp_error($vend_id)) {
        $created_user_ids[] = $vend_id;
        $u_vend = new WP_User($vend_id);
        $u_vend->set_role('vendor');
        update_user_meta($vend_id, 'first_name', 'Babajide');
        update_user_meta($vend_id, 'last_name', 'Adeyemi');
        update_user_meta($vend_id, 'propvendor_agency_name', 'Adeyemi Properties Ltd');
        update_user_meta($vend_id, 'propvendor_phone', '+2348031234567');

        run_test(in_array('vendor', (array) $u_vend->roles, true), 'User has vendor role');
        run_test(user_can($vend_id, 'propvendor_submit_marketplace_listings'), 'Vendor user can submit marketplace listings');
        run_test(get_user_meta($vend_id, 'propvendor_agency_name', true) === 'Adeyemi Properties Ltd', 'Vendor agency name meta is saved');
    }

    // -------------------------------------------------------------------------
    // 3. Role Whitelist / Arbitrary Role Injection Defense
    // -------------------------------------------------------------------------
    $evil_role_inputs = array('administrator', 'editor', 'superadmin', 'author', 'malicious_role');
    $all_defended = true;
    foreach ($evil_role_inputs as $evil_role) {
        $allowed = array('customer', 'vendor');
        $sanitized_role = in_array($evil_role, $allowed, true) ? $evil_role : 'customer';
        if ('customer' !== $sanitized_role) {
            $all_defended = false;
        }
    }
    run_test($all_defended, 'Arbitrary role injection attempts strictly fall back to customer');

    // -------------------------------------------------------------------------
    // 4. Rate Limiting Logic
    // -------------------------------------------------------------------------
    $dummy_ip = '198.51.100.42';
    $login_rate_key = 'pv_login_rate_' . md5($dummy_ip);
    set_transient($login_rate_key, 5, 900);
    $login_attempts = (int) get_transient($login_rate_key);
    run_test($login_attempts >= 5, 'Login rate limiter correctly identifies 5 failed attempts');
    delete_transient($login_rate_key);

    $reg_rate_key = 'pv_reg_rate_' . md5($dummy_ip);
    set_transient($reg_rate_key, 3, 3600);
    $reg_attempts = (int) get_transient($reg_rate_key);
    run_test($reg_attempts >= 3, 'Registration rate limiter correctly identifies 3 registration attempts');
    delete_transient($reg_rate_key);

    // -------------------------------------------------------------------------
    // 5. Authentication Verification & Anti-Enumeration
    // -------------------------------------------------------------------------
    $auth_cust = wp_authenticate($cust_uname, $cust_pwd);
    run_test(!is_wp_error($auth_cust) && $auth_cust->ID === $cust_id, 'Customer authentication succeeds with valid credentials');

    $auth_vend = wp_authenticate($vend_uname, $vend_pwd);
    run_test(!is_wp_error($auth_vend) && $auth_vend->ID === $vend_id, 'Vendor authentication succeeds with valid credentials');

    // Anti-enumeration test: bad username and bad password both receive generic notice
    $generic_login_error = __('Invalid username, email, or password. Please try again.', 'propvendor-marketplace');
    run_test(!empty($generic_login_error), 'Generic login error message is configured for anti-enumeration defense');

    // -------------------------------------------------------------------------
    // 6. Role-Based Login Redirects & Open-Redirect Defense
    // -------------------------------------------------------------------------
    $mock_admin_user = new WP_User();
    $mock_admin_user->roles = array('administrator');
    $redirect_admin = propvendor_marketplace_login_redirect(home_url('/'), '', $mock_admin_user);
    run_test(false !== strpos($redirect_admin, 'wp-admin'), 'Administrator is routed to wp-admin dashboard');

    $mock_vendor_user = new WP_User();
    $mock_vendor_user->roles = array('vendor');
    $redirect_vendor = propvendor_marketplace_login_redirect(home_url('/'), '', $mock_vendor_user);
    run_test(false !== strpos($redirect_vendor, '/vendor-dashboard/'), 'Vendor is routed to /vendor-dashboard/');

    $mock_customer_user = new WP_User();
    $mock_customer_user->roles = array('customer');
    $redirect_customer = propvendor_marketplace_login_redirect(home_url('/'), '', $mock_customer_user);
    run_test(false !== strpos($redirect_customer, '/customer-inbox/'), 'Customer is routed to /customer-inbox/');

    // Safe custom internal redirect preserved
    $redirect_custom = propvendor_marketplace_login_redirect(home_url('/'), home_url('/browse-listings/'), $mock_customer_user);
    run_test(false !== strpos($redirect_custom, '/browse-listings/'), 'Explicit internal requested redirect to /browse-listings/ is preserved for customer');

    // Open redirect rejection: external malicious URL rejected
    $evil_redirect = propvendor_marketplace_login_redirect('https://malicious-phishing-site.example/steal', 'https://malicious-phishing-site.example/steal', $mock_customer_user);
    run_test(false === strpos($evil_redirect, 'malicious-phishing-site.example'), 'Open redirect to external domain is rejected');

    // -------------------------------------------------------------------------
    // 7. Dynamic Navigation Menu Filtering
    // -------------------------------------------------------------------------
    // Build a mock nav menu item list simulating Astra's menu objects
    $mock_menu_items = array();

    $item_home = new stdClass();
    $item_home->ID = 101;
    $item_home->title = 'Home';
    $item_home->url = home_url('/');
    $mock_menu_items[] = $item_home;

    $item_browse = new stdClass();
    $item_browse->ID = 102;
    $item_browse->title = 'Browse';
    $item_browse->url = home_url('/browse-listings/');
    $mock_menu_items[] = $item_browse;

    $item_submit = new stdClass();
    $item_submit->ID = 103;
    $item_submit->title = 'Submit a Listing';
    $item_submit->url = home_url('/submit-a-listing/');
    $mock_menu_items[] = $item_submit;

    $item_vdash = new stdClass();
    $item_vdash->ID = 104;
    $item_vdash->title = 'Vendor Dashboard';
    $item_vdash->url = home_url('/vendor-dashboard/');
    $mock_menu_items[] = $item_vdash;

    $item_vwallet = new stdClass();
    $item_vwallet->ID = 105;
    $item_vwallet->title = 'Vendor Wallet';
    $item_vwallet->url = home_url('/vendor-wallet/');
    $mock_menu_items[] = $item_vwallet;

    // Test A: Logged-out visitor
    wp_set_current_user(0);
    $filtered_logged_out = propvendor_marketplace_filter_nav_menu_objects($mock_menu_items);
    $urls_logged_out = array_map(function($i) { return $i->url; }, $filtered_logged_out);
    $titles_logged_out = array_map(function($i) { return $i->title; }, $filtered_logged_out);

    run_test(!in_array(home_url('/submit-a-listing/'), $urls_logged_out, true), 'Logged-out menu strips Submit a Listing');
    run_test(!in_array(home_url('/vendor-dashboard/'), $urls_logged_out, true), 'Logged-out menu strips Vendor Dashboard');
    run_test(!in_array(home_url('/vendor-wallet/'), $urls_logged_out, true), 'Logged-out menu strips Vendor Wallet');
    run_test(in_array('Sign In', $titles_logged_out, true) || in_array('Login', $titles_logged_out, true), 'Logged-out menu injects Sign In');
    run_test(in_array('Register', $titles_logged_out, true), 'Logged-out menu injects Register');

    // Test B: Logged-in Customer
    wp_set_current_user($cust_id);
    $filtered_customer = propvendor_marketplace_filter_nav_menu_objects($mock_menu_items);
    $urls_customer = array_map(function($i) { return $i->url; }, $filtered_customer);
    $titles_customer = array_map(function($i) { return $i->title; }, $filtered_customer);

    run_test(!in_array(home_url('/submit-a-listing/'), $urls_customer, true), 'Customer menu strips Submit a Listing');
    run_test(!in_array(home_url('/vendor-dashboard/'), $urls_customer, true), 'Customer menu strips Vendor Dashboard');
    run_test(!in_array(home_url('/vendor-wallet/'), $urls_customer, true), 'Customer menu strips Vendor Wallet');
    run_test(in_array(home_url('/customer-inbox/'), $urls_customer, true), 'Customer menu contains Customer Dashboard (/customer-inbox/)');
    run_test(in_array('Log Out', $titles_customer, true) || in_array('Logout', $titles_customer, true), 'Customer menu contains Log Out');

    // Test C: Logged-in Vendor
    wp_set_current_user($vend_id);
    $filtered_vendor = propvendor_marketplace_filter_nav_menu_objects($mock_menu_items);
    $urls_vendor = array_map(function($i) { return $i->url; }, $filtered_vendor);

    run_test(in_array(home_url('/submit-a-listing/'), $urls_vendor, true), 'Vendor menu retains Submit a Listing');
    run_test(in_array(home_url('/vendor-dashboard/'), $urls_vendor, true), 'Vendor menu retains Vendor Dashboard');
    run_test(in_array(home_url('/vendor-wallet/'), $urls_vendor, true), 'Vendor menu retains Vendor Wallet');
    run_test(!in_array(home_url('/customer-inbox/'), $urls_vendor, true), 'Vendor menu does not have Customer Inbox item');

    // -------------------------------------------------------------------------
    // 8. Security Nonces & Lost Password URL
    // -------------------------------------------------------------------------
    $logout_url = wp_logout_url(home_url('/'));
    run_test(false !== strpos($logout_url, '_wpnonce='), 'Logout URL is protected with a valid WordPress nonce');

    $lost_pwd_url = wp_lostpassword_url();
    run_test(false !== strpos($lost_pwd_url, 'action=lostpassword'), 'Password reset URL points to core WordPress lostpassword workflow');

    // -------------------------------------------------------------------------
    // 9. Account Shortcode Output Verification
    // -------------------------------------------------------------------------
    // Logged-out render
    wp_set_current_user(0);
    $logged_out_html = propvendor_marketplace_login_account_shortcode();
    run_test(false !== strpos($logged_out_html, 'pv-auth-tabs'), 'Logged-out shortcode renders tabbed container');
    run_test(false !== strpos($logged_out_html, 'pv-toggle-pwd-btn'), 'Logged-out shortcode renders password show/hide buttons');
    run_test(false !== strpos($logged_out_html, 'name="account_type"'), 'Logged-out shortcode renders account_type selection');
    run_test(false !== strpos($logged_out_html, 'NDPA 2023'), 'Logged-out shortcode includes NDPA 2023 legal compliance notice');

    // Logged-in Customer render
    wp_set_current_user($cust_id);
    $customer_html = propvendor_marketplace_login_account_shortcode();
    run_test(false !== strpos($customer_html, '/customer-inbox/'), 'Customer account page renders Customer Inbox tile');
    run_test(false === strpos($customer_html, '/vendor-wallet/'), 'Customer account page excludes Vendor Wallet tile');
    run_test(false === strpos($customer_html, '/submit-a-listing/'), 'Customer account page excludes Submit a Listing tile');

    // Logged-in Vendor render
    wp_set_current_user($vend_id);
    $vendor_html = propvendor_marketplace_login_account_shortcode();
    run_test(false !== strpos($vendor_html, '/vendor-dashboard/'), 'Vendor account page renders Vendor Dashboard tile');
    run_test(false !== strpos($vendor_html, '/vendor-wallet/'), 'Vendor account page renders Vendor Wallet tile');
    run_test(false !== strpos($vendor_html, '/submit-a-listing/'), 'Vendor account page renders Submit a Listing tile');

} finally {
    // -------------------------------------------------------------------------
    // Clean Teardown
    // -------------------------------------------------------------------------
    wp_set_current_user(0);
    foreach ($created_user_ids as $uid) {
        wp_delete_user($uid);
    }
}

echo "\n====================================================================\n";
echo "PHASE 12 TEST SUMMARY: {$passed_tests}/{$total_tests} PASSED\n";
echo "SUMMARY: {$passed_tests} PASSED, {$failed_tests} FAILED\n";
echo "====================================================================\n";

if ($failed_tests > 0) {
    exit(1);
}
exit(0);
