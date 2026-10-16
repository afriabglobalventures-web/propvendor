<?php
/**
 * Test Suite: PropVendor Phase 7C — Release Candidate Hardening & Verification
 *
 * Verifies:
 * 1. SEC-01: Fulfillment defense check rolls back transaction on WP_Error, non-array, or success => false.
 * 2. SEC-01: Database order status never becomes 'successful' when entitlement grant fails.
 * 3. SEC-02: Cross-worker serialization via MySQL GET_LOCK('pv_stale_cleanup_lock', 5).
 * 4. Stale cleanup returns already_running when engine-level lock is held by another DB connection.
 * 5. Amount validation strict equality policy: underpayments (< order_kobo) rejected as amount_mismatch.
 * 6. Amount validation strict equality policy: overpayments (> order_kobo) rejected as amount_mismatch.
 * 7. End-to-end valid order fulfillment succeeds atomically and idempotently.
 * 8. VIP Agent entitlement rollback defense check.
 * 9. Clean fixture teardown and lock release in finally block.
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

add_filter('pre_wp_mail', '__return_false');

echo "====================================================================\n";
echo "  PROPVENDOR PHASE 7C — RELEASE CANDIDATE HARDENING & VERIFICATION  \n";
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
$created_order_refs = array();

$table_orders        = $wpdb->prefix . 'propvendor_orders';
$table_plans         = $wpdb->prefix . 'propvendor_plans';
$table_ledger        = $wpdb->prefix . 'propvendor_credit_ledger';
$table_notifications = $wpdb->prefix . 'propvendor_notifications';

try {
    // -------------------------------------------------------------------------
    // Setup Test Vendors and Plans
    // -------------------------------------------------------------------------
    $vendor_login = 'test_v_7c_' . time();
    $vendor_id = wp_create_user($vendor_login, 'Password123!', $vendor_login . '@example.com');
    if (!is_wp_error($vendor_id)) {
        $created_user_ids[] = $vendor_id;
        $u = new WP_User($vendor_id);
        $u->set_role('vendor');
    }

    $credit_plan = $wpdb->get_row("SELECT * FROM {$table_plans} WHERE product_type = 'credit_pack' AND is_active = 1 ORDER BY id ASC LIMIT 1");
    $vip_plan    = $wpdb->get_row("SELECT * FROM {$table_plans} WHERE product_type = 'vip_agent' AND is_active = 1 ORDER BY id ASC LIMIT 1");

    run_test(!empty($credit_plan), 'Prerequisite: Active credit pack plan exists in catalog');
    run_test(!empty($vip_plan), 'Prerequisite: Active VIP agent plan exists in catalog');
    run_test($vendor_id > 0, 'Prerequisite: Test vendor created with ID ' . $vendor_id);

    $orders_engine = $wpdb->get_var("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table_orders}'");
    $ledger_engine = $wpdb->get_var("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table_ledger}'");
    run_test('InnoDB' === $orders_engine, 'Prerequisite: Orders table uses InnoDB engine');
    run_test('InnoDB' === $ledger_engine, 'Prerequisite: Credit ledger table uses InnoDB engine');

    // -------------------------------------------------------------------------
    // Group 1: SEC-01 — Entitlement Grant Failures Trigger Transaction ROLLBACK
    // -------------------------------------------------------------------------
    echo "\n--- Group 1: SEC-01 — Entitlement Grant Failures & ROLLBACK Defense ---\n";

    // Test 1: Entitlement grant returning WP_Error
    $ref_err = 'PV_TEST_7C_ERR_' . time();
    $created_order_refs[] = $ref_err;
    $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $vendor_id,
            'plan_id'               => $credit_plan->id,
            'amount_kobo'           => (int) $credit_plan->price_kobo,
            'transaction_reference' => $ref_err,
            'gateway'               => 'paystack',
            'status'                => 'pending',
            'created_at'            => current_time('mysql'),
            'updated_at'            => current_time('mysql'),
        )
    );
    $order_err_id = $wpdb->insert_id;

    $mock_paystack_ok = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $ref_err,
            'amount'    => (int) $credit_plan->price_kobo,
            'currency'  => 'NGN',
        ),
    );

    // Override grant to simulate failure
    $fail_filter_wp_error = function($override, $order, $plan, $reference) use ($ref_err) {
        if ($reference === $ref_err) {
            return new WP_Error('mock_grant_fail', 'Simulated failure during entitlement grant');
        }
        return $override;
    };
    add_filter('propvendor_marketplace_override_grant', $fail_filter_wp_error, 10, 4);

    $res_err = propvendor_marketplace_verify_and_fulfill_order($ref_err, $mock_paystack_ok);
    remove_filter('propvendor_marketplace_override_grant', $fail_filter_wp_error, 10);

    run_test(is_wp_error($res_err), 'SEC-01: Fulfillment returns WP_Error when entitlement grant returns WP_Error');
    run_test(is_wp_error($res_err) && 'mock_grant_fail' === $res_err->get_error_code(), 'SEC-01: WP_Error code matches the entitlement error code');

    $db_order_err = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_orders} WHERE id = %d", $order_err_id));
    run_test($db_order_err && 'successful' !== $db_order_err->status, 'SEC-01: Order status was NOT updated to successful (remains pending)');

    $bal_err = propvendor_marketplace_get_vendor_credit_balance($vendor_id);
    run_test(0 === $bal_err, 'SEC-01: Vendor balance unchanged at 0 credits (transaction rolled back)');

    $ledger_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_ledger} WHERE reference = %s", $ref_err));
    run_test(0 === $ledger_count, 'SEC-01: No credit ledger row persisted for failed entitlement order');

    // Test 2: Entitlement grant returning array('success' => false)
    $ref_false_arr = 'PV_TEST_7C_FLS_ARR_' . time();
    $created_order_refs[] = $ref_false_arr;
    $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $vendor_id,
            'plan_id'               => $credit_plan->id,
            'amount_kobo'           => (int) $credit_plan->price_kobo,
            'transaction_reference' => $ref_false_arr,
            'gateway'               => 'paystack',
            'status'                => 'pending',
            'created_at'            => current_time('mysql'),
            'updated_at'            => current_time('mysql'),
        )
    );
    $order_false_arr_id = $wpdb->insert_id;

    $mock_paystack_fls = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $ref_false_arr,
            'amount'    => (int) $credit_plan->price_kobo,
            'currency'  => 'NGN',
        ),
    );

    $fail_filter_arr = function($override, $order, $plan, $reference) use ($ref_false_arr) {
        if ($reference === $ref_false_arr) {
            return array('success' => false, 'reason' => 'quota_exhausted');
        }
        return $override;
    };
    add_filter('propvendor_marketplace_override_grant', $fail_filter_arr, 10, 4);

    $res_false_arr = propvendor_marketplace_verify_and_fulfill_order($ref_false_arr, $mock_paystack_fls);
    remove_filter('propvendor_marketplace_override_grant', $fail_filter_arr, 10);

    run_test(is_wp_error($res_false_arr), 'SEC-01: Fulfillment returns WP_Error when entitlement returns success => false');
    run_test(is_wp_error($res_false_arr) && 'fulfillment_failed' === $res_false_arr->get_error_code(), 'SEC-01: WP_Error code is fulfillment_failed');

    $db_order_fls_arr = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_orders} WHERE id = %d", $order_false_arr_id));
    run_test($db_order_fls_arr && 'successful' !== $db_order_fls_arr->status, 'SEC-01: Order status never updated to successful when success => false');

    $bal_fls_arr = propvendor_marketplace_get_vendor_credit_balance($vendor_id);
    run_test(0 === $bal_fls_arr, 'SEC-01: Vendor balance unchanged at 0 credits');

    $ledger_count_arr = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_ledger} WHERE reference = %s", $ref_false_arr));
    run_test(0 === $ledger_count_arr, 'SEC-01: No credit ledger row persisted for success => false');

    // Test 3: Entitlement grant returning non-array null
    $ref_null = 'PV_TEST_7C_NULL_' . time();
    $created_order_refs[] = $ref_null;
    $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $vendor_id,
            'plan_id'               => $credit_plan->id,
            'amount_kobo'           => (int) $credit_plan->price_kobo,
            'transaction_reference' => $ref_null,
            'gateway'               => 'paystack',
            'status'                => 'pending',
            'created_at'            => current_time('mysql'),
            'updated_at'            => current_time('mysql'),
        )
    );
    $order_null_id = $wpdb->insert_id;

    $mock_paystack_null = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $ref_null,
            'amount'    => (int) $credit_plan->price_kobo,
            'currency'  => 'NGN',
        ),
    );

    $fail_filter_null = function($override, $order, $plan, $reference) use ($ref_null) {
        if ($reference === $ref_null) {
            return function() { return null; };
        }
        return $override;
    };
    add_filter('propvendor_marketplace_override_grant', $fail_filter_null, 10, 4);

    $res_null = propvendor_marketplace_verify_and_fulfill_order($ref_null, $mock_paystack_null);
    remove_filter('propvendor_marketplace_override_grant', $fail_filter_null, 10);

    run_test(is_wp_error($res_null), 'SEC-01: Fulfillment returns WP_Error when entitlement returns null (non-array)');
    run_test(is_wp_error($res_null) && 'fulfillment_failed' === $res_null->get_error_code(), 'SEC-01: WP_Error code is fulfillment_failed for null');

    $db_order_null = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_orders} WHERE id = %d", $order_null_id));
    run_test($db_order_null && 'successful' !== $db_order_null->status, 'SEC-01: Order status never updated to successful when result is null');

    $bal_null = propvendor_marketplace_get_vendor_credit_balance($vendor_id);
    run_test(0 === $bal_null, 'SEC-01: Vendor balance unchanged at 0 credits for null result');

    // Test 3b: Entitlement grant returning boolean false
    $ref_bool = 'PV_TEST_7C_BOOL_' . time();
    $created_order_refs[] = $ref_bool;
    $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $vendor_id,
            'plan_id'               => $credit_plan->id,
            'amount_kobo'           => (int) $credit_plan->price_kobo,
            'transaction_reference' => $ref_bool,
            'gateway'               => 'paystack',
            'status'                => 'pending',
            'created_at'            => current_time('mysql'),
            'updated_at'            => current_time('mysql'),
        )
    );
    $order_bool_id = $wpdb->insert_id;

    $mock_paystack_bool = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $ref_bool,
            'amount'    => (int) $credit_plan->price_kobo,
            'currency'  => 'NGN',
        ),
    );

    $fail_filter_bool = function($override, $order, $plan, $reference) use ($ref_bool) {
        if ($reference === $ref_bool) {
            return false;
        }
        return $override;
    };
    add_filter('propvendor_marketplace_override_grant', $fail_filter_bool, 10, 4);

    $res_bool = propvendor_marketplace_verify_and_fulfill_order($ref_bool, $mock_paystack_bool);
    remove_filter('propvendor_marketplace_override_grant', $fail_filter_bool, 10);

    run_test(is_wp_error($res_bool), 'SEC-01: Fulfillment returns WP_Error when entitlement returns false (non-array)');
    run_test(is_wp_error($res_bool) && 'fulfillment_failed' === $res_bool->get_error_code(), 'SEC-01: WP_Error code is fulfillment_failed for false');
    $db_order_bool = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_orders} WHERE id = %d", $order_bool_id));
    run_test($db_order_bool && 'successful' !== $db_order_bool->status, 'SEC-01: Order status never updated to successful when result is false');

    // -------------------------------------------------------------------------
    // Group 2: SEC-02 — Database-Level Mutex Serialization (pv_stale_cleanup_lock)
    // -------------------------------------------------------------------------
    echo "\n--- Group 2: SEC-02 — Cross-Worker Mutex Serialization (GET_LOCK) ---\n";

    // Open a second independent database connection using wpdb
    $wpdb2 = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $secondary_connected = !empty($wpdb2->dbh);
    run_test($secondary_connected, 'SEC-02: Secondary database connection established successfully');

    if ($secondary_connected) {
        // Acquire lock on secondary connection with 0s timeout
        $lock_acquired = $wpdb2->get_var($wpdb2->prepare("SELECT GET_LOCK(%s, 0)", 'pv_stale_cleanup_lock'));
        run_test('1' === (string) $lock_acquired, 'SEC-02: Secondary connection acquired pv_stale_cleanup_lock');

        // Ensure transient is deleted to prove engine-level lock prevents execution
        delete_transient('propvendor_stale_orders_cleanup_lock');

        // Now run cleanup on primary connection
        $res_cleanup_blocked = propvendor_cron_cleanup_stale_orders(24, 50);

        run_test(false === $res_cleanup_blocked['success'], 'SEC-02: Stale cleanup fails when pv_stale_cleanup_lock is held');
        run_test('already_running' === $res_cleanup_blocked['reason'], 'SEC-02: Rejection reason is already_running');
        run_test(0 === $res_cleanup_blocked['cleaned'], 'SEC-02: Zero orders cleaned during locked worker attempt');

        // Release lock on secondary connection
        $wpdb2->query($wpdb2->prepare("SELECT RELEASE_LOCK(%s)", 'pv_stale_cleanup_lock'));
        $wpdb2->close();

        // Now cleanup on primary connection must succeed
        $res_cleanup_freed = propvendor_cron_cleanup_stale_orders(24, 50);
        run_test(true === $res_cleanup_freed['success'], 'SEC-02: Stale cleanup succeeds after lock is released by secondary worker');
    }

    // -------------------------------------------------------------------------
    // Group 3: Amount Validation — Underpayment & Overpayment Rejection Policy
    // -------------------------------------------------------------------------
    echo "\n--- Group 3: Amount Validation — Strict Integer Equality Policy ---\n";

    // Test 5: Underpayment rejection policy
    $ref_under = 'PV_TEST_7C_UNDER_' . time();
    $created_order_refs[] = $ref_under;
    $required_kobo = (int) $credit_plan->price_kobo;

    $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $vendor_id,
            'plan_id'               => $credit_plan->id,
            'amount_kobo'           => $required_kobo,
            'transaction_reference' => $ref_under,
            'gateway'               => 'paystack',
            'status'                => 'pending',
            'created_at'            => current_time('mysql'),
            'updated_at'            => current_time('mysql'),
        )
    );
    $order_under_id = $wpdb->insert_id;

    $mock_underpaid = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $ref_under,
            'amount'    => $required_kobo - 1000, // Underpaid by 1,000 kobo (10 NGN)
            'currency'  => 'NGN',
        ),
    );

    $res_under = propvendor_marketplace_verify_and_fulfill_order($ref_under, $mock_underpaid);
    run_test(is_wp_error($res_under), 'Underpayment: Verification rejects underpaid amount');
    run_test(is_wp_error($res_under) && 'amount_mismatch' === $res_under->get_error_code(), 'Underpayment: Error code is amount_mismatch');

    $db_order_under = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_orders} WHERE id = %d", $order_under_id));
    run_test($db_order_under && 'failed' === $db_order_under->status, 'Underpayment: Order status in database is updated to failed');

    $bal_under = propvendor_marketplace_get_vendor_credit_balance($vendor_id);
    run_test(0 === $bal_under, 'Underpayment: Vendor credit balance unchanged at 0 credits');

    $ledger_under = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_ledger} WHERE reference = %s", $ref_under));
    run_test(0 === $ledger_under, 'Underpayment: No ledger record persisted for underpaid transaction');

    // Test 6: Overpayment rejection policy
    $ref_over = 'PV_TEST_7C_OVER_' . time();
    $created_order_refs[] = $ref_over;

    $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $vendor_id,
            'plan_id'               => $credit_plan->id,
            'amount_kobo'           => $required_kobo,
            'transaction_reference' => $ref_over,
            'gateway'               => 'paystack',
            'status'                => 'pending',
            'created_at'            => current_time('mysql'),
            'updated_at'            => current_time('mysql'),
        )
    );
    $order_over_id = $wpdb->insert_id;

    $mock_overpaid = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $ref_over,
            'amount'    => $required_kobo + 1000, // Overpaid by 1,000 kobo
            'currency'  => 'NGN',
        ),
    );

    $res_over = propvendor_marketplace_verify_and_fulfill_order($ref_over, $mock_overpaid);
    run_test(is_wp_error($res_over), 'Overpayment: Verification rejects overpaid amount');
    run_test(is_wp_error($res_over) && 'amount_mismatch' === $res_over->get_error_code(), 'Overpayment: Error code is amount_mismatch');

    $db_order_over = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_orders} WHERE id = %d", $order_over_id));
    run_test($db_order_over && 'failed' === $db_order_over->status, 'Overpayment: Order status in database is updated to failed');

    $bal_over = propvendor_marketplace_get_vendor_credit_balance($vendor_id);
    run_test(0 === $bal_over, 'Overpayment: Vendor credit balance unchanged at 0 credits');

    $ledger_over = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_ledger} WHERE reference = %s", $ref_over));
    run_test(0 === $ledger_over, 'Overpayment: No ledger record persisted for overpaid transaction');

    // -------------------------------------------------------------------------
    // Group 4: End-to-End Valid Fulfillment & Idempotency
    // -------------------------------------------------------------------------
    echo "\n--- Group 4: End-to-End Valid Fulfillment & Idempotency ---\n";

    $ref_valid = 'PV_TEST_7C_VAL_' . time();
    $created_order_refs[] = $ref_valid;

    $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $vendor_id,
            'plan_id'               => $credit_plan->id,
            'amount_kobo'           => $required_kobo,
            'transaction_reference' => $ref_valid,
            'gateway'               => 'paystack',
            'status'                => 'pending',
            'created_at'            => current_time('mysql'),
            'updated_at'            => current_time('mysql'),
        )
    );
    $order_val_id = $wpdb->insert_id;

    $mock_valid = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $ref_valid,
            'amount'    => $required_kobo,
            'currency'  => 'NGN',
        ),
    );

    $res_valid = propvendor_marketplace_verify_and_fulfill_order($ref_valid, $mock_valid);
    run_test(!is_wp_error($res_valid), 'Valid Order: Verification succeeds without error');
    run_test(!empty($res_valid['success']) && false === $res_valid['already_fulfilled'], 'Valid Order: Reports success with already_fulfilled = false');

    $db_order_val = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_orders} WHERE id = %d", $order_val_id));
    run_test($db_order_val && 'successful' === $db_order_val->status, 'Valid Order: Status transitioned to successful in database');

    $bal_valid = propvendor_marketplace_get_vendor_credit_balance($vendor_id);
    run_test($bal_valid === (int) $credit_plan->credits, 'Valid Order: Vendor credited exactly with plan credits (' . $credit_plan->credits . ')');

    // Second verification call (idempotency test)
    $res_valid_dup = propvendor_marketplace_verify_and_fulfill_order($ref_valid, $mock_valid);
    run_test(!empty($res_valid_dup['already_fulfilled']), 'Idempotency: Repeated fulfillment call returns already_fulfilled = true');

    $bal_valid_dup = propvendor_marketplace_get_vendor_credit_balance($vendor_id);
    run_test($bal_valid_dup === (int) $credit_plan->credits, 'Idempotency: Credit balance unchanged on duplicate fulfillment (no double-grant)');

    // -------------------------------------------------------------------------
    // Group 5: VIP Agent Entitlement Rollback Defense
    // -------------------------------------------------------------------------
    echo "\n--- Group 5: VIP Agent Entitlement Rollback Defense ---\n";

    $ref_vip = 'PV_TEST_7C_VIP_' . time();
    $created_order_refs[] = $ref_vip;
    $vip_kobo = (int) $vip_plan->price_kobo;

    $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $vendor_id,
            'plan_id'               => $vip_plan->id,
            'amount_kobo'           => $vip_kobo,
            'transaction_reference' => $ref_vip,
            'gateway'               => 'paystack',
            'status'                => 'pending',
            'created_at'            => current_time('mysql'),
            'updated_at'            => current_time('mysql'),
        )
    );
    $order_vip_id = $wpdb->insert_id;

    $mock_vip = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $ref_vip,
            'amount'    => $vip_kobo,
            'currency'  => 'NGN',
        ),
    );

    $fail_filter_vip = function($override, $order, $plan, $reference) use ($ref_vip) {
        if ($reference === $ref_vip) {
            return new WP_Error('vip_grant_exception', 'Simulated failure during VIP grant');
        }
        return $override;
    };
    add_filter('propvendor_marketplace_override_grant', $fail_filter_vip, 10, 4);

    $res_vip = propvendor_marketplace_verify_and_fulfill_order($ref_vip, $mock_vip);
    remove_filter('propvendor_marketplace_override_grant', $fail_filter_vip, 10);

    run_test(is_wp_error($res_vip), 'VIP Defense: Fulfillment returns WP_Error when VIP grant fails');
    run_test(is_wp_error($res_vip) && 'vip_grant_exception' === $res_vip->get_error_code(), 'VIP Defense: Matches error code vip_grant_exception');

    $db_order_vip = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_orders} WHERE id = %d", $order_vip_id));
    run_test($db_order_vip && 'successful' !== $db_order_vip->status, 'VIP Defense: Order status remains pending (not successful)');

    run_test(!propvendor_marketplace_is_vendor_vip_agent($vendor_id), 'VIP Defense: Vendor was NOT granted VIP Agent status');

} finally {
    // -------------------------------------------------------------------------
    // Teardown Test Fixtures & Locks
    // -------------------------------------------------------------------------
    wp_set_current_user(0);
    delete_transient('propvendor_stale_orders_cleanup_lock');
    $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", 'pv_stale_cleanup_lock'));

    if (!function_exists('wp_delete_user')) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
    }

    foreach ($created_user_ids as $uid) {
        wp_delete_user($uid);
    }
    foreach ($created_order_refs as $ref) {
        $wpdb->delete($table_orders, array('transaction_reference' => $ref));
        $wpdb->delete($table_ledger, array('reference' => $ref));
    }

    $lock_free = $wpdb->get_var($wpdb->prepare("SELECT IS_FREE_LOCK(%s)", 'pv_stale_cleanup_lock'));
    run_test('1' === (string) $lock_free, 'Teardown: pv_stale_cleanup_lock is completely free in database');
}

echo "\n====================================================================\n";
echo "PHASE 7C TEST SUMMARY: {$passed_tests}/{$total_tests} PASSED\n";
echo "SUMMARY: {$passed_tests} PASSED, {$failed_tests} FAILED\n";
echo "====================================================================\n";

if ($failed_tests > 0) {
    exit(1);
}
exit(0);

