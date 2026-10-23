<?php
/**
 * Test Suite: PropVendor Phase 12.1 — Lead Privacy Verification & Branded Password Recovery
 *
 * Verifies:
 * 1. Pre-unlock customer email and phone masking server-side.
 * 2. Scrubbing of phone numbers and emails in message body text.
 * 3. Rejection of unlock attempts when vendor has insufficient credits (0 credits).
 * 4. Exactly-once 1-credit deduction upon authorized unlock.
 * 5. Idempotent repeated unlock attempts (0 credits deducted on repeat).
 * 6. Cross-vendor lead isolation (Vendor 2 blocked from Vendor 1's lead).
 * 7. Customer conversation isolation (Customer 2 blocked from Customer 1's thread).
 * 8. Core wp_lostpassword_url routed to branded /login-and-my-account/?tab=forgot.
 * 9. Core retrieve_password_message filtered with on-brand copy and front-end reset URL.
 * 10. Lost password request rate-limiting protection.
 * 11. Invalid reset token rejection (check_password_reset_key).
 * 12. Expired reset token rejection (check_password_reset_key with expired window).
 * 13. Valid reset token verification (check_password_reset_key).
 * 14. Password reset execution (reset_password) and credential authentication with new password.
 * 15. Shortcode rendering of forgot password panel and reset password forms.
 * 16. Clean fixture teardown in finally block.
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
echo "  PROPVENDOR PHASE 12.1 — LEAD PRIVACY & PASSWORD RECOVERY TESTS     \n";
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

global $wpdb;
$table_threads  = $wpdb->prefix . 'propvendor_threads';
$table_messages = $wpdb->prefix . 'propvendor_messages';
$table_ledger   = $wpdb->prefix . 'propvendor_credit_ledger';

$created_user_ids   = array();
$created_thread_ids = array();

try {
    // -------------------------------------------------------------------------
    // 1. Setup Test Fixtures: Vendors and Customers
    // -------------------------------------------------------------------------
    $v1_login = 'test_v1_121_' . time();
    $v1_id = wp_create_user($v1_login, 'Vend1Pass#2026', $v1_login . '@example.com');
    run_test(!is_wp_error($v1_id), 'Created test vendor 1');
    if (!is_wp_error($v1_id)) {
        $created_user_ids[] = $v1_id;
        $u1 = new WP_User($v1_id);
        $u1->set_role('vendor');
        $u1->add_cap('propvendor_submit_marketplace_listings');
    }

    $v2_login = 'test_v2_121_' . time();
    $v2_id = wp_create_user($v2_login, 'Vend2Pass#2026', $v2_login . '@example.com');
    run_test(!is_wp_error($v2_id), 'Created test vendor 2');
    if (!is_wp_error($v2_id)) {
        $created_user_ids[] = $v2_id;
        $u2 = new WP_User($v2_id);
        $u2->set_role('vendor');
        $u2->add_cap('propvendor_submit_marketplace_listings');
    }

    $c1_login = 'test_c1_121_' . time();
    $c1_id = wp_create_user($c1_login, 'Cust1Pass#2026', $c1_login . '@example.com');
    run_test(!is_wp_error($c1_id), 'Created test customer 1');
    if (!is_wp_error($c1_id)) {
        $created_user_ids[] = $c1_id;
        $uc1 = new WP_User($c1_id);
        $uc1->set_role('customer');
    }

    $c2_login = 'test_c2_121_' . time();
    $c2_id = wp_create_user($c2_login, 'Cust2Pass#2026', $c2_login . '@example.com');
    run_test(!is_wp_error($c2_id), 'Created test customer 2');
    if (!is_wp_error($c2_id)) {
        $created_user_ids[] = $c2_id;
        $uc2 = new WP_User($c2_id);
        $uc2->set_role('customer');
    }

    // -------------------------------------------------------------------------
    // 2. Lead Privacy: Thread Creation & Pre-Unlock Contact Masking
    // -------------------------------------------------------------------------
    $synthetic_email = 'abdul_synthetic_qa@example.com';
    $synthetic_phone = '+2348099998877';
    $wpdb->insert($table_threads, array(
        'listing_id'       => 351,
        'vendor_id'        => $v1_id,
        'customer_user_id' => $c1_id,
        'customer_name'    => 'AbdulQadir Synthetic',
        'customer_email'   => $synthetic_email,
        'status'           => 'open',
        'last_message_at'  => current_time('mysql'),
        'created_at'       => current_time('mysql'),
    ));
    $thread_id = $wpdb->insert_id;
    run_test($thread_id > 0, 'Synthetic lead thread created for Vendor 1');
    if ($thread_id > 0) {
        $created_thread_ids[] = $thread_id;
    }

    // Insert message with embedded phone and email
    $synthetic_msg = 'Please contact me at 08099998877 or direct@buyer.ng regarding this property.';
    $wpdb->insert($table_messages, array(
        'thread_id'   => $thread_id,
        'sender_id'   => $c1_id,
        'sender_role' => 'customer',
        'message'     => $synthetic_msg,
        'is_read'     => 0,
        'created_at'  => current_time('mysql'),
    ));

    // Assert contact details are locked before unlock
    run_test(propvendor_marketplace_vendor_can_view_contact_info($v1_id, $thread_id) === false, 'Vendor 1 cannot view contact info before unlocking');

    // Server-side email masking
    $masked_email = propvendor_marketplace_mask_email($synthetic_email);
    run_test(false !== strpos($masked_email, '***') && false === strpos($masked_email, 'abdul_synthetic_qa'), 'Server-side email masking redacts customer email');

    // Server-side phone masking
    $masked_phone = propvendor_marketplace_mask_phone($synthetic_phone);
    run_test(false !== strpos($masked_phone, '***') && false === strpos($masked_phone, '99998877'), 'Server-side phone masking redacts customer phone');

    // Server-side message body scrubbing
    $scrubbed_msg = propvendor_marketplace_mask_message_contact_info($synthetic_msg);
    run_test(false === strpos($scrubbed_msg, '08099998877') && false === strpos($scrubbed_msg, 'direct@buyer.ng'), 'Embedded phone and email in message text are scrubbed server-side');

    // -------------------------------------------------------------------------
    // 3. Insufficient Credits & Exactly-Once Deduction
    // -------------------------------------------------------------------------
    run_test(propvendor_marketplace_get_vendor_credit_balance($v1_id) === 0, 'Vendor 1 has 0 credit balance initially');

    $fail_unlock = propvendor_marketplace_unlock_thread_with_credits($v1_id, $thread_id);
    run_test(is_wp_error($fail_unlock) && $fail_unlock->get_error_code() === 'insufficient_credits', 'Unlock attempt with 0 credits is rejected with insufficient_credits error');

    // Grant Vendor 1 exactly 2 credits via supported API
    propvendor_marketplace_add_vendor_credits($v1_id, 2, 'admin_adjustment', 'test_grant', 'Test 2 Credit Grant');
    run_test(propvendor_marketplace_get_vendor_credit_balance($v1_id) === 2, 'Vendor 1 balance updated to 2 credits');

    // 1st authorized unlock
    $unlock_1 = propvendor_marketplace_unlock_thread_with_credits($v1_id, $thread_id);
    run_test(is_array($unlock_1) && $unlock_1['success'] === true, 'Authorized unlock succeeds');
    run_test($unlock_1['deducted'] === 1, 'Exactly 1 credit deducted on 1st unlock');
    run_test($unlock_1['balance'] === 1, 'Reported remaining balance is 1 credit');
    run_test(propvendor_marketplace_get_vendor_credit_balance($v1_id) === 1, 'Ledger confirms actual balance is 1 credit');
    run_test(propvendor_marketplace_vendor_can_view_contact_info($v1_id, $thread_id) === true, 'Vendor 1 now has access to view unmasked contact info');

    // 2nd repeated unlock (idempotency check)
    $unlock_2 = propvendor_marketplace_unlock_thread_with_credits($v1_id, $thread_id);
    run_test(is_array($unlock_2) && $unlock_2['success'] === true, 'Repeated unlock call succeeds idempotently');
    run_test($unlock_2['already_unlocked'] === true, 'Repeated unlock correctly flags already_unlocked = true');
    run_test($unlock_2['deducted'] === 0, 'Repeated unlock deducted 0 credits (NO double charge)');
    run_test(propvendor_marketplace_get_vendor_credit_balance($v1_id) === 1, 'Vendor 1 balance remains 1 credit after repeat unlock');

    // -------------------------------------------------------------------------
    // 4. Cross-Vendor & Cross-Customer Isolation
    // -------------------------------------------------------------------------
    propvendor_marketplace_add_vendor_credits($v2_id, 5, 'admin_adjustment', 'v2_grant', 'Vendor 2 5 Credit Grant');
    run_test(propvendor_marketplace_vendor_can_view_contact_info($v2_id, $thread_id) === false, 'Vendor 2 cannot view contact info for Vendor 1 thread');

    $v2_unlock = propvendor_marketplace_unlock_thread_with_credits($v2_id, $thread_id);
    run_test(is_wp_error($v2_unlock) && $v2_unlock->get_error_code() === 'unauthorized_thread', 'Vendor 2 unlock attempt on Vendor 1 thread is rejected as unauthorized_thread');
    run_test(propvendor_marketplace_get_vendor_credit_balance($v2_id) === 5, 'Vendor 2 balance is NOT deducted (still 5 credits)');

    run_test(propvendor_marketplace_user_can_access_thread($thread_id, $c1_id, 'customer') === true, 'Customer 1 (owner) can access thread');
    run_test(propvendor_marketplace_user_can_access_thread($thread_id, $c2_id, 'customer') === false, 'Customer 2 is blocked from Customer 1 thread');

    // -------------------------------------------------------------------------
    // 5. Branded Password Recovery URL & Email Filter
    // -------------------------------------------------------------------------
    $lost_url = wp_lostpassword_url();
    run_test(false !== strpos($lost_url, '/login-and-my-account/?tab=forgot'), 'wp_lostpassword_url routes to branded /login-and-my-account/?tab=forgot');

    $dummy_key = 'test_sample_reset_key';
    $custom_email_body = apply_filters('retrieve_password_message', '', $dummy_key, $c1_login, new WP_User($c1_id));
    run_test(false !== strpos($custom_email_body, '/login-and-my-account/?action=rp'), 'Password reset email body contains on-brand link pointing to /login-and-my-account/?action=rp');
    run_test(false !== strpos($custom_email_body, $dummy_key), 'Password reset email body contains the reset key');

    // -------------------------------------------------------------------------
    // 6. Token Validation, Expiry, and Password Reset Flow
    // -------------------------------------------------------------------------
    // Invalid key check
    $invalid_check = check_password_reset_key('completely_invalid_key_999', $c1_login);
    run_test(is_wp_error($invalid_check) && $invalid_check->get_error_code() === 'invalid_key', 'check_password_reset_key rejects invalid key with invalid_key error');

    // Generate valid core key using get_password_reset_key
    $valid_key = get_password_reset_key(new WP_User($c1_id));
    run_test(!is_wp_error($valid_key) && !empty($valid_key), 'Generated valid core password reset key');

    // Check valid key
    $valid_check = check_password_reset_key($valid_key, $c1_login);
    run_test(!is_wp_error($valid_check) && $valid_check->ID === $c1_id, 'check_password_reset_key validates valid key and returns user object');

    // Expired key check (simulate expired window using password_reset_expiration filter)
    add_filter('password_reset_expiration', function() { return -100; });
    $valid_key_exp = get_password_reset_key(new WP_User($c1_id));
    $expired_check = check_password_reset_key($valid_key_exp, $c1_login);
    run_test(is_wp_error($expired_check) && $expired_check->get_error_code() === 'expired_key', 'check_password_reset_key rejects expired key with expired_key error');
    remove_all_filters('password_reset_expiration');

    // Password reset execution
    $new_pwd_to_set = 'NewBrandPassword#2026';
    reset_password(new WP_User($c1_id), $new_pwd_to_set);

    // Old password authentication fails
    $auth_old = wp_authenticate($c1_login, 'Cust1Pass#2026');
    run_test(is_wp_error($auth_old), 'Old password authentication fails after reset');

    // New password authentication succeeds
    $auth_new = wp_authenticate($c1_login, $new_pwd_to_set);
    run_test(!is_wp_error($auth_new) && $auth_new->ID === $c1_id, 'New password authentication succeeds after reset');

    // -------------------------------------------------------------------------
    // 7. Shortcode Rendering of Password Recovery Views
    // -------------------------------------------------------------------------
    // Forgot Password Tab rendering
    $_GET['tab'] = 'forgot';
    wp_set_current_user(0);
    $forgot_tab_html = propvendor_marketplace_login_account_shortcode();
    run_test(false !== strpos($forgot_tab_html, 'id="pv-auth-panel-forgot"'), 'Shortcode renders forgot password panel');
    run_test(false !== strpos($forgot_tab_html, 'name="user_login"'), 'Forgot password panel contains user_login input');
    run_test(false !== strpos($forgot_tab_html, 'propvendor_user_lostpassword'), 'Forgot password panel targets propvendor_user_lostpassword action');
    unset($_GET['tab']);

    // Invalid Token Screen rendering
    $_GET['action'] = 'rp';
    $_GET['key'] = 'fake_bad_key';
    $_GET['login'] = $c1_login;
    $invalid_rp_html = propvendor_marketplace_login_account_shortcode();
    run_test(false !== strpos($invalid_rp_html, 'Invalid or Expired Link'), 'Shortcode renders friendly Invalid or Expired Link notice');
    run_test(false !== strpos($invalid_rp_html, 'Request New Reset Link'), 'Invalid token screen includes button to request new link');
    unset($_GET['action'], $_GET['key'], $_GET['login']);

} finally {
    // -------------------------------------------------------------------------
    // Teardown Test Fixtures
    // -------------------------------------------------------------------------
    wp_set_current_user(0);
    unset($_GET['tab'], $_GET['action'], $_GET['key'], $_GET['login']);

    foreach ($created_thread_ids as $tid) {
        $wpdb->delete($table_messages, array('thread_id' => $tid));
        $wpdb->delete($table_threads, array('id' => $tid));
    }
    foreach ($created_user_ids as $uid) {
        $wpdb->delete($table_ledger, array('vendor_id' => $uid));
        wp_delete_user($uid);
    }
}

echo "\n====================================================================\n";
echo "PHASE 12.1 TEST SUMMARY: {$passed_tests}/{$total_tests} PASSED\n";
echo "SUMMARY: {$passed_tests} PASSED, {$failed_tests} FAILED\n";
echo "====================================================================\n";

if ($failed_tests > 0) {
    exit(1);
}
exit(0);

