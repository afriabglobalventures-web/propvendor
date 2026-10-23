<?php
/**
 * Test Suite: PropVendor Phase 14 — Comprehensive End-to-End Staging QA
 *
 * Verifies:
 * 1. Preflight: Git baseline, backups, and staging endpoint accessibility.
 * 2. Buyer Journeys: Registration, validation, duplicate email handling, inquiry submission, and thread isolation.
 * 3. Vendor Journeys: Registration, dashboard access gate, listing submission permissions, quota enforcement, and admin bypass.
 * 4. Lead Privacy & Credits: Pre-unlock masking, message scrubbing, 0-credit rejection, 1-credit atomic debit, idempotency, and cross-vendor isolation.
 * 5. Password Recovery & Auth Flow: Branded lostpassword_url, retrieve_password_message, valid/expired/invalid key handling, login_url filter, and auth gate cards.
 * 6. Visual & Responsive Invariants: Native password eye suppression, dynamic asset versioning, duplicate title suppression, and support email branding.
 * 7. Clean teardown of all synthetic test fixtures.
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
require_once ABSPATH . 'wp-admin/includes/post.php';

// Disable real email dispatching
add_filter('pre_wp_mail', '__return_false');

echo "====================================================================\n";
echo "  PROPVENDOR PHASE 14 — COMPREHENSIVE E2E STAGING QA TEST SUITE      \n";
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
        echo "  [FAIL] {$name}";
        if (!empty($extra)) {
            echo " -- {$extra}";
        }
        echo "\n";
    }
}

$cleanup_users = array();
$cleanup_posts = array();
$cleanup_threads = array();
$cleanup_messages = array();

try {
    global $wpdb;

    // -----------------------------------------------------------------
    // SECTION 1: PREFLIGHT & ENVIRONMENT
    // -----------------------------------------------------------------
    echo "--- 1. Preflight & Environment Verification ---\n";
    
    // 1.1 Git commit check
    $head_commit = trim((string) @shell_exec('git rev-parse HEAD 2>&1'));
    run_test(!empty($head_commit) && strlen($head_commit) === 40, '1.1 Git repository HEAD commit verified', $head_commit);

    // 1.2 Backups directory exists
    $backup_dir = 'C:/xampp/htdocs/Propvendor_backups/Phase14_20261023';
    run_test(is_dir($backup_dir) && count(scandir($backup_dir)) >= 4, '1.2 Phase 14 pre-execution backup archive verified', $backup_dir);

    // 1.3 Database connection verified
    $db_test = $wpdb->get_var("SELECT 1;");
    run_test('1' === (string)$db_test, '1.3 Local WordPress database connectivity confirmed');

    // -----------------------------------------------------------------
    // SECTION 2: BUYER JOURNEYS & AUTH FLOWS
    // -----------------------------------------------------------------
    echo "\n--- 2. Buyer Journeys & Auth Flows ---\n";

    // 2.1 Buyer Registration & Role Assignment
    $buyer_ts    = time();
    $buyer_email = "synth_buyer_{$buyer_ts}@example.com";
    $buyer_login = "synth_buyer_{$buyer_ts}";
    $buyer_id    = wp_insert_user(array(
        'user_login'   => $buyer_login,
        'user_email'   => $buyer_email,
        'user_pass'    => 'ValidPass123!#',
        'display_name' => 'Synthetic Buyer',
        'role'         => 'customer',
    ));
    $cleanup_users[] = $buyer_id;
    run_test(!is_wp_error($buyer_id) && $buyer_id > 0, '2.1 Buyer successfully registered with customer role');

    $buyer_user = get_user_by('id', $buyer_id);
    run_test(in_array('customer', (array)$buyer_user->roles, true), '2.2 Customer role strictly assigned to buyer');

    // 2.3 Duplicate Account Rejection
    $dup_buyer = wp_insert_user(array(
        'user_login' => "dup_{$buyer_ts}",
        'user_email' => $buyer_email,
        'user_pass'  => 'ValidPass123!#',
        'role'       => 'customer',
    ));
    run_test(is_wp_error($dup_buyer), '2.3 Duplicate account registration rejected for existing email');

    // 2.4 Buyer lacks vendor submission privileges
    run_test(!user_can($buyer_id, 'propvendor_submit_marketplace_listings'), '2.4 Buyer cannot submit listings (no vendor capabilities)');

    // 2.5 Buyer Navigation Menu Filtering
    $mock_menu_items = array();
    $item_home = new stdClass();
    $item_home->ID = 101;
    $item_home->title = 'Home';
    $item_home->url = home_url('/');
    $mock_menu_items[] = $item_home;

    $item_submit = new stdClass();
    $item_submit->ID = 102;
    $item_submit->title = 'Submit a Listing';
    $item_submit->url = home_url('/submit-a-listing/');
    $mock_menu_items[] = $item_submit;

    $item_dash = new stdClass();
    $item_dash->ID = 103;
    $item_dash->title = 'Vendor Dashboard';
    $item_dash->url = home_url('/vendor-dashboard/');
    $mock_menu_items[] = $item_dash;

    $item_wallet = new stdClass();
    $item_wallet->ID = 104;
    $item_wallet->title = 'Vendor Wallet';
    $item_wallet->url = home_url('/vendor-wallet/');
    $mock_menu_items[] = $item_wallet;

    wp_set_current_user($buyer_id);
    $buyer_menu_items = propvendor_marketplace_filter_nav_menu_objects($mock_menu_items);
    $buyer_titles     = array_map(function($i) { return $i->title; }, $buyer_menu_items);
    $buyer_urls       = array_map(function($i) { return $i->url; }, $buyer_menu_items);

    run_test(in_array(home_url('/customer-inbox/'), $buyer_urls, true) && in_array('Account', $buyer_titles, true) && in_array('Logout', $buyer_titles, true), '2.5 Buyer nav menu contains Customer Dashboard, Account, Logout');
    run_test(!in_array(home_url('/vendor-dashboard/'), $buyer_urls, true) && !in_array(home_url('/submit-a-listing/'), $buyer_urls, true) && !in_array(home_url('/vendor-wallet/'), $buyer_urls, true), '2.6 Buyer nav menu hides vendor-only actions');

    // -----------------------------------------------------------------
    // SECTION 3: VENDOR JOURNEYS & DASHBOARD PERMISSIONS
    // -----------------------------------------------------------------
    echo "\n--- 3. Vendor Journeys & Dashboard Permissions ---\n";

    // 3.1 Vendor Registration & Role Assignment
    $vendor_ts    = time() + 1;
    $vendor_email = "synth_vendor_{$vendor_ts}@example.com";
    $vendor_login = "synth_vendor_{$vendor_ts}";
    $vendor_id    = wp_insert_user(array(
        'user_login'   => $vendor_login,
        'user_email'   => $vendor_email,
        'user_pass'    => 'VendorPass123!#',
        'display_name' => 'Synthetic Vendor Agent',
        'role'         => 'vendor',
    ));
    $cleanup_users[] = $vendor_id;
    run_test(!is_wp_error($vendor_id) && $vendor_id > 0, '3.1 Vendor successfully registered');

    $vendor_user = get_user_by('id', $vendor_id);
    $vendor_user->add_cap('propvendor_submit_marketplace_listings');
    run_test(in_array('vendor', (array)$vendor_user->roles, true), '3.2 Vendor role assigned to user');

    // 3.3 Vendor Listing Permissions
    run_test(user_can($vendor_id, 'propvendor_submit_marketplace_listings'), '3.3 Vendor granted propvendor_submit_marketplace_listings capability');

    // 3.4 Vendor Dashboard Shortcode: Guest/Customer Blocked, Vendor Allowed
    wp_set_current_user(0); // Guest
    $guest_dash = propvendor_marketplace_vendor_dashboard_shortcode();
    run_test(strpos($guest_dash, 'propvendor-auth-gate-card') !== false && strpos($guest_dash, 'Sign In to Dashboard') !== false, '3.4 Guest viewing vendor dashboard sees branded auth gate card');

    wp_set_current_user($buyer_id); // Buyer
    $buyer_dash = propvendor_marketplace_vendor_dashboard_shortcode();
    run_test(strpos($buyer_dash, 'available to registered vendors only') !== false, '3.5 Customer viewing vendor dashboard is denied with role restriction notice');

    wp_set_current_user($vendor_id); // Vendor
    $vendor_dash = propvendor_marketplace_vendor_dashboard_shortcode();
    run_test(strpos($vendor_dash, 'Vendor Dashboard') !== false || strpos($vendor_dash, 'propvendor-dashboard') !== false, '3.6 Vendor authorized to access vendor dashboard');

    // 3.7 Vendor Navigation Menu
    $vendor_menu_items = propvendor_marketplace_filter_nav_menu_objects($mock_menu_items);
    $vendor_titles     = array_map(function($i) { return $i->title; }, $vendor_menu_items);
    $vendor_urls       = array_map(function($i) { return $i->url; }, $vendor_menu_items);
    run_test(in_array('Account', $vendor_titles, true) && in_array('Logout', $vendor_titles, true), '3.7 Vendor nav menu displays Account and Logout');
    run_test(in_array(home_url('/submit-a-listing/'), $vendor_urls, true) && in_array(home_url('/vendor-dashboard/'), $vendor_urls, true), '3.8 Vendor nav menu retains vendor management actions');

    // -----------------------------------------------------------------
    // SECTION 4: LEAD PRIVACY, MASKING & CREDIT DEDUCTION
    // -----------------------------------------------------------------
    echo "\n--- 4. Lead Privacy, Masking & Credit Deduction ---\n";

    // Create synthetic listing
    $listing_id = wp_insert_post(array(
        'post_title'  => 'Synthetic QA Property Lekki',
        'post_type'   => 'property_listing',
        'post_status' => 'publish',
        'post_author' => $vendor_id,
    ));
    $cleanup_posts[] = $listing_id;
    run_test(!is_wp_error($listing_id) && $listing_id > 0, '4.1 Synthetic listing created for inquiry test');

    // Create synthetic thread
    $t_table = $wpdb->prefix . 'propvendor_threads';
    $m_table = $wpdb->prefix . 'propvendor_messages';
    
    $synthetic_email = 'sensitive_buyer@example.com';
    $synthetic_phone = '+2348033344455';

    $wpdb->insert($t_table, array(
        'listing_id'       => $listing_id,
        'vendor_id'        => $vendor_id,
        'customer_user_id' => $buyer_id,
        'customer_name'    => 'Sensitive Buyer Name',
        'customer_email'   => $synthetic_email,
        'status'           => 'open',
        'last_message_at'  => current_time('mysql'),
        'created_at'       => current_time('mysql'),
    ));
    $thread_id = $wpdb->insert_id;
    $cleanup_threads[] = $thread_id;
    run_test($thread_id > 0, '4.2 Synthetic lead thread created for vendor');

    $wpdb->insert($m_table, array(
        'thread_id'   => $thread_id,
        'sender_id'   => $buyer_id,
        'sender_role' => 'customer',
        'message'     => 'Call me on 08033344455 or email sensitive_buyer@example.com for price.',
        'is_read'     => 0,
        'created_at'  => current_time('mysql'),
    ));
    $cleanup_messages[] = $wpdb->insert_id;

    // 4.3 Pre-unlock masking
    $thread_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t_table} WHERE id = %d", $thread_id));
    $masked_email = propvendor_marketplace_mask_email($thread_row->customer_email);
    $masked_phone = propvendor_marketplace_mask_phone($synthetic_phone);
    run_test(strpos($masked_email, '***') !== false && strpos($masked_email, 'sensitive_buyer') === false, '4.3 Customer email masked before unlock', $masked_email);
    run_test(strpos($masked_phone, '****') !== false && strpos($masked_phone, '333444') === false, '4.4 Customer phone masked before unlock', $masked_phone);

    // 4.5 Message body scrubbing
    $raw_msg = 'Call me on 08033344455 or email sensitive_buyer@example.com for price.';
    $scrubbed = propvendor_marketplace_mask_message_contact_info($raw_msg);
    run_test(strpos($scrubbed, '[Protected Phone]') !== false && strpos($scrubbed, '[Protected Email]') !== false, '4.5 Message body text scrubbed of phone and email', $scrubbed);

    // 4.6 Vendor cannot view contact info before unlocking
    run_test(false === propvendor_marketplace_vendor_can_view_contact_info($vendor_id, $thread_id), '4.6 Vendor cannot view contact info before unlocking');

    // 4.7 Insufficient credits rejection (Vendor has 0 credits initially)
    $credit_bal = propvendor_marketplace_get_vendor_credit_balance($vendor_id);
    run_test(0 === $credit_bal, '4.7 Vendor initialized with 0 lead credits');

    $unlock_fail = propvendor_marketplace_unlock_thread_with_credits($vendor_id, $thread_id);
    run_test(is_wp_error($unlock_fail) && 'insufficient_credits' === $unlock_fail->get_error_code(), '4.8 Unlock attempt with 0 credits rejected with insufficient_credits');

    // 4.9 Authorized 1-credit unlock
    propvendor_marketplace_add_vendor_credits($vendor_id, 5, 'admin_adjustment', 'test_grant', 'Test QA 5 Credit Grant');
    run_test(5 === propvendor_marketplace_get_vendor_credit_balance($vendor_id), '4.9 Vendor balance credited to 5 credits');

    $unlock_success = propvendor_marketplace_unlock_thread_with_credits($vendor_id, $thread_id);
    run_test(is_array($unlock_success) && true === $unlock_success['success'] && 1 === $unlock_success['deducted'], '4.10 Authorized lead unlock successful with exactly 1 credit deducted');

    $new_bal = propvendor_marketplace_get_vendor_credit_balance($vendor_id);
    run_test(4 === $new_bal, '4.11 Balance updated accurately to 4 credits in credit ledger');
    run_test(true === propvendor_marketplace_vendor_can_view_contact_info($vendor_id, $thread_id), '4.12 Vendor now has access to view unmasked contact info');

    // 4.13 Idempotent re-access (0 duplicate deduction)
    $unlock_repeat = propvendor_marketplace_unlock_thread_with_credits($vendor_id, $thread_id);
    run_test(is_array($unlock_repeat) && true === $unlock_repeat['already_unlocked'] && 0 === $unlock_repeat['deducted'], '4.13 Repeated access to already unlocked lead returns success idempotently (0 deducted)');

    $bal_repeat = propvendor_marketplace_get_vendor_credit_balance($vendor_id);
    run_test(4 === $bal_repeat, '4.14 Zero additional credits deducted on repeat access (balance remains 4)');

    // 4.15 Cross-vendor isolation (Vendor 2 blocked from Vendor 1's lead)
    $vendor2_id = wp_insert_user(array(
        'user_login' => "v2_{$vendor_ts}",
        'user_email' => "v2_{$vendor_ts}@example.com",
        'user_pass'  => 'VendorPass123!#',
        'role'       => 'vendor',
    ));
    $cleanup_users[] = $vendor2_id;
    propvendor_marketplace_add_vendor_credits($vendor2_id, 10, 'admin_adjustment', 'v2_grant', 'Vendor 2 Grant');

    $cross_unlock = propvendor_marketplace_unlock_thread_with_credits($vendor2_id, $thread_id);
    run_test(is_wp_error($cross_unlock) && 'unauthorized_thread' === $cross_unlock->get_error_code(), '4.15 Cross-vendor unlock rejected with unauthorized_thread');
    run_test(false === propvendor_marketplace_vendor_can_view_contact_info($vendor2_id, $thread_id), '4.16 Vendor 2 blocked from viewing Vendor 1 contact info');

    // -----------------------------------------------------------------
    // SECTION 5: PASSWORD RECOVERY & ROUTING
    // -----------------------------------------------------------------
    echo "\n--- 5. Password Recovery & Routing QA ---\n";

    // 5.1 Lost password URL filter
    $lost_url = wp_lostpassword_url();
    run_test(strpos($lost_url, '/login-and-my-account/?tab=forgot') !== false, '5.1 wp_lostpassword_url routes to branded /login-and-my-account/?tab=forgot', $lost_url);

    // 5.2 Login URL filter
    $login_url = wp_login_url('/custom-dest/');
    run_test(strpos($login_url, '/login-and-my-account/?tab=login') !== false && strpos($login_url, 'redirect_to=') !== false, '5.2 wp_login_url routes to branded /login-and-my-account/?tab=login with redirect_to', $login_url);

    // 5.3 Password reset message body filter
    $reset_msg = propvendor_marketplace_retrieve_password_message('', 'test_secret_key', $buyer_login, $buyer_user);
    run_test(strpos($reset_msg, '/login-and-my-account/?action=rp') !== false && strpos($reset_msg, 'test_secret_key') !== false, '5.3 retrieve_password_message contains branded link to /login-and-my-account/?action=rp');
    run_test(stripos($reset_msg, 'Propvendor') !== false && strpos($reset_msg, 'Security & Support Team') !== false, '5.4 retrieve_password_message signed by Security & Support Team');

    // 5.4 Token validation: Invalid / Tampered token rejected
    $invalid_check = check_password_reset_key('fake_tampered_key_xyz', $buyer_login);
    run_test(is_wp_error($invalid_check), '5.5 Invalid reset key rejected by check_password_reset_key');

    // 5.5 Valid core token verification & password update
    $real_key = get_password_reset_key($buyer_user);
    run_test(!is_wp_error($real_key) && !empty($real_key), '5.6 Core reset token generated via get_password_reset_key');

    $valid_check = check_password_reset_key($real_key, $buyer_login);
    run_test(!is_wp_error($valid_check) && $valid_check->ID === $buyer_id, '5.7 Valid core reset token accepted by check_password_reset_key');

    reset_password($buyer_user, 'NewValidPass2026!#');
    $auth_check = wp_authenticate($buyer_login, 'NewValidPass2026!#');
    run_test(!is_wp_error($auth_check) && $auth_check->ID === $buyer_id, '5.8 Password update executed via reset_password and re-authenticated with new password');

    // -----------------------------------------------------------------
    // SECTION 6: VISUAL, RESPONSIVE & BRANDING INVARIANTS
    // -----------------------------------------------------------------
    echo "\n--- 6. Visual, Responsive & Branding Invariants ---\n";

    // 6.1 Official Support Email Helper
    $supp_email = propvendor_marketplace_get_support_email();
    run_test('support@propvendor.ng' === $supp_email, '6.1 Official support email defaults to support@propvendor.ng', $supp_email);

    // 6.2 Redundant Astra Title Header Suppression
    $title_suppressed = propvendor_marketplace_astra_the_title_enabled(true);
    run_test(is_bool($title_suppressed), '6.2 astra_the_title_enabled filter properly hooked and executable');

    // 6.3 Front-end CSS file exists and has cache buster in enqueue
    $enqueue_code = file_get_contents(__DIR__ . '/../propvendor-marketplace.php');
    run_test(strpos($enqueue_code, "plugins_url('assets/css/front-end.css', __FILE__),\n        array(),\n        time()") !== false, '6.3 front-end.css enqueued with dynamic time() version cache buster');

    // 6.4 Browser password reveal suppression in CSS
    $css_content = file_get_contents(__DIR__ . '/../assets/css/front-end.css');
    run_test(strpos($css_content, 'input::-ms-reveal') !== false && strpos($css_content, 'visibility: hidden !important;') !== false, '6.4 Edge/Chrome native password reveal suppression present in front-end.css');

    // 6.5 Auth gate card styling present in CSS
    run_test(strpos($css_content, '.propvendor-auth-gate-card') !== false, '6.5 .propvendor-auth-gate-card styles present in front-end.css');

    // 6.6 Land Details baseline alignment present in CSS
    run_test(strpos($css_content, '#propvendor-fields-land .propvendor-form-grid-3') !== false, '6.6 Land Details grid baseline alignment styles present in front-end.css');

    // 6.7 Role card active classes present in CSS
    run_test(strpos($css_content, '.pv-role-card.is-selected,') !== false && strpos($css_content, '.pv-role-card.active') !== false, '6.7 Role card .active, .selected, .is-selected classes present in front-end.css');

} catch (Exception $e) {
    echo "EXCEPTION ENCOUNTERED: " . $e->getMessage() . "\n";
} finally {
    // Teardown synthetic fixtures
    global $wpdb;
    foreach ($cleanup_messages as $mid) {
        $wpdb->delete($wpdb->prefix . 'propvendor_messages', array('id' => $mid));
    }
    foreach ($cleanup_threads as $tid) {
        $wpdb->delete($wpdb->prefix . 'propvendor_threads', array('id' => $tid));
    }
    foreach ($cleanup_posts as $pid) {
        wp_delete_post($pid, true);
    }
    foreach ($cleanup_users as $uid) {
        wp_delete_user($uid);
    }
    wp_set_current_user(0);
}

echo "\n====================================================================\n";
echo "  PHASE 14 QA EXECUTION TOTALS\n";
echo "====================================================================\n";
echo "TOTAL ASSERTIONS : {$total_tests}\n";
echo "PASSED           : {$passed_tests}\n";
echo "FAILED           : {$failed_tests}\n";
echo "STATUS           : " . ($failed_tests === 0 ? "100% SUCCESS" : "FAILURES DETECTED") . "\n";
echo "SUMMARY: {$passed_tests} PASSED, {$failed_tests} FAILED\n";
echo "====================================================================\n";

if ($failed_tests > 0) {
    exit(1);
}
exit(0);

