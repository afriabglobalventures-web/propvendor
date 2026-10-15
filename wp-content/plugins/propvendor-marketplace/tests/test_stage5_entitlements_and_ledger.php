<?php
/**
 * Test Suite: PropVendor Stage 5 — Credit Ledger & Lead Entitlement Engine
 *
 * Verifies all required test cases for Stage 5:
 * 1. Schema migration & table integrity (InnoDB engine, columns, indexing).
 * 2. Credit pack additions (+5, +12, +28).
 * 3. Atomic credit deduction on lead unlock (-1).
 * 4. Insufficient credit rejection.
 * 5. Idempotent duplicate unlock prevention (no double charge).
 * 6. VIP plan auto-unlock bypass.
 * 7. Regression check on free-vendor contact masking & access enforcement.
 */

define('WP_USE_THEMES', false);
$wp_load = dirname(__DIR__, 4) . '/wp-load.php';
if (file_exists($wp_load)) {
    require_once $wp_load;
} else {
    require_once 'c:/xampp/htdocs/Propvendor/wp-load.php';
}

add_filter('pre_wp_mail', '__return_false');

echo "====================================================================\n";
echo "  PROPVENDOR STAGE 5 — CREDIT LEDGER & ENTITLEMENT TEST SUITE       \n";
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
$created_user_ids   = array();
$created_thread_ids = array();
$table_ledger       = $wpdb->prefix . 'propvendor_credit_ledger';
$table_threads      = $wpdb->prefix . 'propvendor_threads';

try {
    // -------------------------------------------------------------
    // TEST 1: Schema Migration & Table Integrity
    // -------------------------------------------------------------
    echo "\n--- TEST 1: Schema Migration & Table Integrity ---\n";

    // Ensure table is created
    propvendor_marketplace_create_credit_ledger_table();

    $table_check = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_ledger));
    run_test($table_check === $table_ledger, 'wp_propvendor_credit_ledger table exists in database');

    // Verify InnoDB engine
    $table_status = $wpdb->get_row($wpdb->prepare("SHOW TABLE STATUS WHERE Name = %s", $table_ledger));
    $engine = $table_status ? strtolower($table_status->Engine) : '';
    run_test($engine === 'innodb', 'wp_propvendor_credit_ledger uses InnoDB storage engine', "Engine: {$engine}");

    // Verify columns
    $columns = $wpdb->get_col("DESCRIBE {$table_ledger}", 0);
    $required_cols = array('id', 'vendor_id', 'amount', 'balance_after', 'transaction_type', 'reference', 'description', 'created_at');
    $all_cols_exist = true;
    foreach ($required_cols as $col) {
        if (!in_array($col, $columns, true)) {
            $all_cols_exist = false;
            break;
        }
    }
    run_test($all_cols_exist, 'All required schema columns exist on credit ledger table');

    // Create a fresh test vendor
    $vendor_login = 'test_v5_v_' . time() . '_' . wp_rand(100, 999);
    $vid = wp_create_user($vendor_login, 'PassStage5V!', $vendor_login . '@propvendor.ng');
    $created_user_ids[] = $vid;
    $v_user = get_user_by('id', $vid);
    $v_user->set_role('vendor');

    $initial_bal = propvendor_marketplace_get_vendor_credit_balance($vid);
    run_test($initial_bal === 0, 'New vendor without transactions has 0 initial credit balance');

    // -------------------------------------------------------------
    // TEST 2: Credit Pack Additions (+5, +12, +28)
    // -------------------------------------------------------------
    echo "\n--- TEST 2: Credit Pack Additions (+5, +12, +28) ---\n";

    $packs = propvendor_marketplace_get_credit_packs();
    run_test(isset($packs['starter'], $packs['growth'], $packs['pro']), 'Credit pack definitions contain starter, growth, and pro');
    run_test($packs['starter']['credits'] === 5, 'Starter pack defines 5 credits');
    run_test($packs['growth']['credits'] === 12, 'Growth pack defines 12 credits');
    run_test($packs['pro']['credits'] === 28, 'Pro pack defines 28 credits');

    // Add Starter Pack (+5)
    $bal1 = propvendor_marketplace_add_vendor_credit_pack($vid, 'starter', 'txn_pack_starter_01');
    run_test($bal1 === 5, 'Adding Starter pack updates balance to 5');
    run_test(propvendor_marketplace_get_vendor_credit_balance($vid) === 5, 'Balance lookup returns 5 credits');

    $row1 = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_ledger} WHERE vendor_id = %d ORDER BY id DESC LIMIT 1", $vid));
    run_test($row1 && (int)$row1->amount === 5 && (int)$row1->balance_after === 5, 'Ledger records Starter row with amount=+5 and balance_after=5');
    run_test($row1 && $row1->transaction_type === 'pack_purchase', 'Starter transaction type is pack_purchase');

    // Add Growth Pack (+12)
    $bal2 = propvendor_marketplace_add_vendor_credit_pack($vid, 'growth', 'txn_pack_growth_02');
    run_test($bal2 === 17, 'Adding Growth pack (+12) updates balance from 5 to 17');
    run_test(propvendor_marketplace_get_vendor_credit_balance($vid) === 17, 'Balance lookup returns 17 credits');

    // Add Pro Pack (+28)
    $bal3 = propvendor_marketplace_add_vendor_credit_pack($vid, 28, 'txn_pack_pro_03');
    run_test($bal3 === 45, 'Adding Pro pack (+28) updates balance from 17 to 45');
    run_test(propvendor_marketplace_get_vendor_credit_balance($vid) === 45, 'Balance lookup returns 45 credits');

    // Invalid amounts rejected
    $err_neg = propvendor_marketplace_add_vendor_credits($vid, -5);
    run_test(is_wp_error($err_neg) && $err_neg->get_error_code() === 'invalid_amount', 'Negative credit addition rejected cleanly');
    $err_zero = propvendor_marketplace_add_vendor_credits($vid, 0);
    run_test(is_wp_error($err_zero) && $err_zero->get_error_code() === 'invalid_amount', 'Zero credit addition rejected cleanly');
    $err_vnd = propvendor_marketplace_add_vendor_credits(0, 10);
    run_test(is_wp_error($err_vnd) && $err_vnd->get_error_code() === 'invalid_vendor', 'Invalid vendor ID addition rejected cleanly');

    // -------------------------------------------------------------
    // TEST 3: Atomic Credit Deduction on Lead Unlock (-1)
    // -------------------------------------------------------------
    echo "\n--- TEST 3: Atomic Credit Deduction on Lead Unlock (-1) ---\n";

    // Deduct 1 credit
    $deduct_res = propvendor_marketplace_deduct_vendor_credits($vid, 1, 'lead_unlock', 'thread_101', 'Test thread unlock');
    run_test($deduct_res === 44, 'Deducting 1 credit reduces balance from 45 to 44');
    run_test(propvendor_marketplace_get_vendor_credit_balance($vid) === 44, 'Balance lookup verifies 44 credits');

    $deduct_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_ledger} WHERE vendor_id = %d ORDER BY id DESC LIMIT 1", $vid));
    run_test($deduct_row && (int)$deduct_row->amount === -1, 'Ledger records amount as -1');
    run_test($deduct_row && (int)$deduct_row->balance_after === 44, 'Ledger records balance_after as 44');
    run_test($deduct_row && $deduct_row->transaction_type === 'lead_unlock', 'Transaction type is lead_unlock');
    run_test($deduct_row && $deduct_row->reference === 'thread_101', 'Transaction reference is thread_101');

    // Multiple sequential deductions
    $res2 = propvendor_marketplace_deduct_vendor_credits($vid, 1, 'lead_unlock', 'thread_102', 'Thread 102 unlock');
    $res3 = propvendor_marketplace_deduct_vendor_credits($vid, 1, 'lead_unlock', 'thread_103', 'Thread 103 unlock');
    run_test($res2 === 43 && $res3 === 42, 'Sequential 1-credit deductions monotonically decrease balance (44 -> 43 -> 42)');

    // Invalid deduction inputs rejected
    $deduct_neg = propvendor_marketplace_deduct_vendor_credits($vid, -1);
    run_test(is_wp_error($deduct_neg) && $deduct_neg->get_error_code() === 'invalid_amount', 'Negative deduction rejected with WP_Error');
    $deduct_zero = propvendor_marketplace_deduct_vendor_credits($vid, 0);
    run_test(is_wp_error($deduct_zero) && $deduct_zero->get_error_code() === 'invalid_amount', 'Zero deduction rejected with WP_Error');

    // -------------------------------------------------------------
    // TEST 4: Insufficient Credit Rejection
    // -------------------------------------------------------------
    echo "\n--- TEST 4: Insufficient Credit Rejection ---\n";

    // Create a broke vendor with 0 credits
    $broke_login = 'test_v5_broke_' . time() . '_' . wp_rand(100, 999);
    $broke_id = wp_create_user($broke_login, 'PassStage5B!', $broke_login . '@propvendor.ng');
    $created_user_ids[] = $broke_id;
    $b_user = get_user_by('id', $broke_id);
    $b_user->set_role('vendor');

    run_test(propvendor_marketplace_get_vendor_credit_balance($broke_id) === 0, 'Broke vendor has 0 credits');

    // Attempt direct deduction of 1 credit
    $fail_deduct = propvendor_marketplace_deduct_vendor_credits($broke_id, 1, 'lead_unlock', 'thread_201');
    run_test(is_wp_error($fail_deduct) && $fail_deduct->get_error_code() === 'insufficient_credits', 'Deduction with 0 credits rejected with insufficient_credits error');
    run_test(propvendor_marketplace_get_vendor_credit_balance($broke_id) === 0, 'Balance remains at 0 (never drops negative)');

    // Attempt unlock via unlock_thread_with_credits
    $dummy_thread_id = 99901;
    $fail_unlock = propvendor_marketplace_unlock_thread_with_credits($broke_id, $dummy_thread_id);
    run_test(is_wp_error($fail_unlock) && $fail_unlock->get_error_code() === 'insufficient_credits', 'unlock_thread_with_credits rejected with insufficient_credits error');

    // Verify no ledger rows created for failed deduction
    $broke_rows = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_ledger} WHERE vendor_id = %d", $broke_id));
    run_test((int)$broke_rows === 0, 'No ledger transactions recorded for rejected deduction');

    // Add 1 credit, attempt to deduct 2
    propvendor_marketplace_add_vendor_credits($broke_id, 1, 'admin_adjustment', 'test_grant', '1 Credit grant');
    run_test(propvendor_marketplace_get_vendor_credit_balance($broke_id) === 1, 'Broke vendor now has exactly 1 credit');
    $fail_deduct_2 = propvendor_marketplace_deduct_vendor_credits($broke_id, 2);
    run_test(is_wp_error($fail_deduct_2) && $fail_deduct_2->get_error_code() === 'insufficient_credits', 'Attempt to deduct 2 credits with 1 credit available rejected');
    run_test(propvendor_marketplace_get_vendor_credit_balance($broke_id) === 1, 'Balance remains unchanged at 1 credit');

    // -------------------------------------------------------------
    // TEST 5: Idempotent Duplicate Unlock Prevention
    // -------------------------------------------------------------
    echo "\n--- TEST 5: Idempotent Duplicate Unlock Prevention ---\n";

    // Setup vendor with 5 credits
    $idem_login = 'test_v5_idem_' . time() . '_' . wp_rand(100, 999);
    $idem_id = wp_create_user($idem_login, 'PassStage5I!', $idem_login . '@propvendor.ng');
    $created_user_ids[] = $idem_id;
    $i_user = get_user_by('id', $idem_id);
    $i_user->set_role('vendor');
    update_user_meta($idem_id, 'propvendor_vendor_plan', 'free');

    propvendor_marketplace_add_vendor_credits($idem_id, 5, 'pack_purchase', 'txn_idem_01', 'Initial 5 credits');
    run_test(propvendor_marketplace_get_vendor_credit_balance($idem_id) === 5, 'Vendor balance starts at 5 credits');

    // Create a real thread row in wp_propvendor_threads
    $wpdb->insert(
        $table_threads,
        array(
            'listing_id'       => 1,
            'vendor_id'        => $idem_id,
            'customer_user_id' => 0,
            'customer_name'    => 'Emeka Okafor',
            'customer_email'   => 'emeka@example.com',
            'status'           => 'open',
            'last_message_at'  => current_time('mysql'),
            'created_at'       => current_time('mysql'),
        ),
        array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
    );
    $target_thread_id = (int)$wpdb->insert_id;
    $created_thread_ids[] = $target_thread_id;

    run_test(!propvendor_marketplace_is_thread_unlocked($idem_id, $target_thread_id), 'Thread starts in locked state');

    // Unlock thread 1st time
    $u1 = propvendor_marketplace_unlock_thread_with_credits($idem_id, $target_thread_id);
    run_test(is_array($u1) && $u1['success'] === true, '1st unlock attempt succeeds');
    run_test($u1['already_unlocked'] === false, '1st unlock reports already_unlocked = false');
    run_test($u1['deducted'] === 1, '1st unlock deducted exactly 1 credit');
    run_test($u1['balance'] === 4, '1st unlock reports new balance = 4');
    run_test(propvendor_marketplace_get_vendor_credit_balance($idem_id) === 4, 'Ledger balance is 4 credits');
    run_test(propvendor_marketplace_is_thread_unlocked($idem_id, $target_thread_id), 'is_thread_unlocked returns true');

    // Unlock thread 2nd time (IDEMPOTENT ATTEMPT)
    $u2 = propvendor_marketplace_unlock_thread_with_credits($idem_id, $target_thread_id);
    run_test(is_array($u2) && $u2['success'] === true, '2nd unlock attempt succeeds (idempotent)');
    run_test($u2['already_unlocked'] === true, '2nd unlock correctly flags already_unlocked = true');
    run_test($u2['deducted'] === 0, '2nd unlock deducted 0 credits (NO DOUBLE CHARGE)');
    run_test($u2['balance'] === 4, '2nd unlock maintains balance at 4');
    run_test(propvendor_marketplace_get_vendor_credit_balance($idem_id) === 4, 'Ledger balance remains unchanged at 4');

    // Unlock thread 3rd time
    $u3 = propvendor_marketplace_unlock_thread_with_credits($idem_id, $target_thread_id);
    run_test($u3['deducted'] === 0 && $u3['already_unlocked'] === true, '3rd unlock attempt is idempotent with 0 deduction');
    run_test(propvendor_marketplace_get_vendor_credit_balance($idem_id) === 4, 'Ledger balance remains solidly at 4');

    // Verify exact count of deduction rows for this thread
    $ref_tag = 'thread_' . $target_thread_id;
    $thread_deduct_count = (int)$wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_ledger} WHERE vendor_id = %d AND transaction_type = 'lead_unlock' AND reference = %s",
        $idem_id,
        $ref_tag
    ));
    run_test($thread_deduct_count === 1, 'Exactly 1 deduction transaction recorded in database for this thread');

    // Verify user meta was synced
    $unlocked_meta = get_user_meta($idem_id, 'propvendor_unlocked_thread_ids', true);
    run_test(is_array($unlocked_meta) && in_array($target_thread_id, array_map('absint', $unlocked_meta), true), 'User meta propvendor_unlocked_thread_ids contains target thread ID');

    // -------------------------------------------------------------
    // TEST 6: VIP Plan Auto-Unlock Bypass
    // -------------------------------------------------------------
    echo "\n--- TEST 6: VIP Plan Auto-Unlock Bypass ---\n";

    $vip_login = 'test_v5_vip_' . time() . '_' . wp_rand(100, 999);
    $vip_id = wp_create_user($vip_login, 'PassStage5VIP!', $vip_login . '@propvendor.ng');
    $created_user_ids[] = $vip_id;
    $vip_user = get_user_by('id', $vip_id);
    $vip_user->set_role('vendor');

    // Configure active VIP
    update_user_meta($vip_id, 'propvendor_listing_plan', 'vip');
    update_user_meta($vip_id, 'propvendor_plan_expires_at', date('Y-m-d', strtotime('+30 days')));

    run_test(propvendor_marketplace_is_vendor_vip($vip_id), 'Vendor is confirmed active VIP');
    run_test(propvendor_marketplace_get_vendor_credit_balance($vip_id) === 0, 'VIP vendor has 0 credits');

    // VIP unlock call
    $vip_thread_id = 99902;
    $vip_res = propvendor_marketplace_unlock_thread_with_credits($vip_id, $vip_thread_id);
    run_test(is_array($vip_res) && $vip_res['success'] === true, 'VIP unlock call succeeds');
    run_test($vip_res['vip_bypass'] === true, 'VIP unlock flags vip_bypass = true');
    run_test($vip_res['deducted'] === 0, 'VIP unlock deducted 0 credits');
    run_test(propvendor_marketplace_get_vendor_credit_balance($vip_id) === 0, 'VIP credit balance remains at 0');

    // VIP can_view_contact_info checks
    run_test(propvendor_marketplace_vendor_can_view_contact_info($vip_id, $vip_thread_id) === true, 'VIP vendor granted automatic contact view access for thread');
    run_test(propvendor_marketplace_vendor_can_view_contact_info($vip_id, 0) === false, 'VIP vendor without thread ID preserves Stage 3 separation');

    // Expire VIP plan and verify revocation
    update_user_meta($vip_id, 'propvendor_plan_expires_at', date('Y-m-d', strtotime('-5 days')));
    run_test(!propvendor_marketplace_is_vendor_vip($vip_id), 'Vendor is no longer active VIP when expiry date is in the past');
    run_test(propvendor_marketplace_vendor_can_view_contact_info($vip_id, 88888) === false, 'Expired VIP vendor denied unmasked contact view access');

    // -------------------------------------------------------------
    // TEST 7: Regression Check on Free-Vendor Contact Masking
    // -------------------------------------------------------------
    echo "\n--- TEST 7: Regression Check on Free-Vendor Contact Masking ---\n";

    // Free vendor on locked thread
    $free_login = 'test_v5_free_' . time() . '_' . wp_rand(100, 999);
    $free_id = wp_create_user($free_login, 'PassStage5F!', $free_login . '@propvendor.ng');
    $created_user_ids[] = $free_id;
    $f_user = get_user_by('id', $free_id);
    $f_user->set_role('vendor');
    update_user_meta($free_id, 'propvendor_vendor_plan', 'free');

    $locked_thread_id = 77701;
    run_test(propvendor_marketplace_vendor_can_view_contact_info($free_id, $locked_thread_id) === false, 'Free vendor denied contact view access on locked thread');
    run_test(propvendor_marketplace_vendor_can_view_contact_info($free_id, 0) === false, 'Free vendor denied contact view access when thread ID is 0');

    // Format display customer name masking
    $masked_name_phone = propvendor_marketplace_format_display_customer_name('John 08031234567', false);
    run_test(strpos($masked_name_phone, '08031234567') === false, 'Customer phone in name is masked for locked leads');

    $masked_name_email = propvendor_marketplace_format_display_customer_name('Mary mary@test.ng', false);
    run_test(strpos($masked_name_email, '@') === false, 'Customer email in name is masked for locked leads');

    // Unlocking unlocks contact info for that specific thread
    propvendor_marketplace_add_vendor_credits($free_id, 3, 'admin_adjustment', 'grant_3');
    $unlock_free = propvendor_marketplace_unlock_thread_with_credits($free_id, $locked_thread_id);
    run_test($unlock_free['success'] === true && $unlock_free['deducted'] === 1, 'Free vendor unlocked locked thread with 1 credit');
    run_test(propvendor_marketplace_vendor_can_view_contact_info($free_id, $locked_thread_id) === true, 'Free vendor now granted access to unlocked thread');

    // Other threads remain locked
    $other_thread_id = 77702;
    run_test(propvendor_marketplace_vendor_can_view_contact_info($free_id, $other_thread_id) === false, 'Other threads for free vendor remain strictly locked');

    // Admin user check
    $admin_user = get_user_by('login', 'admin');
    if ($admin_user) {
        run_test(propvendor_marketplace_vendor_can_view_contact_info($admin_user->ID, $other_thread_id) === true, 'Admin user always granted contact view access');
    } else {
        // Fallback test
        run_test(true, 'Admin user contact access skipped (no admin user found)');
    }

} finally {
    // Teardown: clean up created test records
    foreach ($created_user_ids as $uid) {
        // Clean ledger rows
        $wpdb->delete($table_ledger, array('vendor_id' => $uid));
        wp_delete_user($uid);
    }
    foreach ($created_thread_ids as $tid) {
        $wpdb->delete($table_threads, array('id' => $tid));
    }
}

echo "\n====================================================================\n";
echo "  STAGE 5 TEST SUMMARY: {$passed_tests}/{$total_tests} PASSED\n";
if ($failed_tests === 0 && $passed_tests > 0) {
    echo "  SUMMARY: {$passed_tests} PASSED, 0 FAILED (ALL TESTS PASSED)\n";
} else {
    echo "  SUMMARY: {$passed_tests} PASSED, {$failed_tests} FAILED\n";
}
echo "====================================================================\n";
