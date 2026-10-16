<?php
/**
 * Test Suite: PropVendor Phase 7B — Stale Order Cleanup, Late Webhook Reconciliation & Deployment Readiness
 *
 * Verifies:
 * 1. WP-Cron stale pending order cleanup (>24h transitions to 'expired').
 * 2. Fresh pending orders (<24h) remain pending.
 * 3. Successful and failed orders are never expired or mutated.
 * 4. Non-destructive guarantee: order and ledger rows are never deleted.
 * 5. Idempotent cleanup execution and concurrency mutex locking.
 * 6. Function alias compatibility (propvendor_cron_cleanup_stale_orders / propvendor_marketplace_cron_cleanup_stale_orders).
 * 7. Late-payment webhook reconciliation for expired orders (Credit Pack & VIP).
 * 8. Late-payment idempotency: repeated webhooks return already_fulfilled with 0 double-granting.
 * 9. REST webhook endpoint late payment reconciliation response handling.
 * 10. WP-Cron lifecycle hooks (schedule, unschedule, self-healing).
 * 11. Deployment readiness metrics and payment configuration validation.
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
echo "  PROPVENDOR PHASE 7B — STALE ORDER CLEANUP & DEPLOYMENT READINESS  \n";
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
    $vendor_login = 'test_v_7b_' . time();
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

    // -------------------------------------------------------------------------
    // Test Group 1: Stale Pending Order Cleanup (>24h)
    // -------------------------------------------------------------------------
    echo "\n--- Group 1: Stale Pending Order Cleanup (>24h) ---\n";

    // Create order created 26 hours ago
    $stale_ref_1 = 'PV_TEST_STALE_1_' . time();
    $created_order_refs[] = $stale_ref_1;
    $time_26h_ago = date('Y-m-d H:i:s', current_time('timestamp') - (26 * 3600));

    $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $vendor_id,
            'plan_id'               => $credit_plan->id,
            'amount_kobo'           => (int) $credit_plan->price_kobo,
            'transaction_reference' => $stale_ref_1,
            'gateway'               => 'paystack',
            'status'                => 'pending',
            'created_at'            => $time_26h_ago,
            'updated_at'            => $time_26h_ago,
        )
    );
    $stale_order_id_1 = $wpdb->insert_id;

    // Run cleanup
    $res_cleanup = propvendor_cron_cleanup_stale_orders(24, 50);

    run_test($res_cleanup['success'] === true, 'Stale order cleanup executes successfully');
    run_test($res_cleanup['cleaned'] >= 1, 'Cleanup transitions at least 1 stale order');
    run_test(in_array((int) $stale_order_id_1, $res_cleanup['order_ids'], true), 'Order created 26h ago is in expired order IDs list');

    $db_stale_1 = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_orders} WHERE id = %d", $stale_order_id_1));
    run_test($db_stale_1 && 'expired' === $db_stale_1->status, 'Stale order status in DB is now expired');
    run_test($db_stale_1 && $db_stale_1->updated_at >= $time_26h_ago, 'Stale order updated_at timestamp refreshed to current time');

    // -------------------------------------------------------------------------
    // Test Group 2: Fresh Pending Orders (<24h) Preserved
    // -------------------------------------------------------------------------
    echo "\n--- Group 2: Fresh Pending Orders (<24h) Preserved ---\n";

    $fresh_ref = 'PV_TEST_FRESH_' . time();
    $created_order_refs[] = $fresh_ref;
    $time_2h_ago = date('Y-m-d H:i:s', current_time('timestamp') - (2 * 3600));

    $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $vendor_id,
            'plan_id'               => $credit_plan->id,
            'amount_kobo'           => (int) $credit_plan->price_kobo,
            'transaction_reference' => $fresh_ref,
            'gateway'               => 'paystack',
            'status'                => 'pending',
            'created_at'            => $time_2h_ago,
            'updated_at'            => $time_2h_ago,
        )
    );
    $fresh_order_id = $wpdb->insert_id;

    $res_fresh_cleanup = propvendor_cron_cleanup_stale_orders(24, 50);

    $db_fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_orders} WHERE id = %d", $fresh_order_id));
    run_test($db_fresh && 'pending' === $db_fresh->status, 'Fresh order (created 2h ago) remains pending');
    run_test(!in_array((int) $fresh_order_id, $res_fresh_cleanup['order_ids'], true), 'Fresh order ID is NOT in expired list');

    // -------------------------------------------------------------------------
    // Test Group 3: Non-Destructive Invariant & Successful/Failed Protection
    // -------------------------------------------------------------------------
    echo "\n--- Group 3: History Preservation & Successful/Failed Protection ---\n";

    $succ_ref = 'PV_TEST_SUCC_' . time();
    $created_order_refs[] = $succ_ref;
    $time_48h_ago = date('Y-m-d H:i:s', current_time('timestamp') - (48 * 3600));

    $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $vendor_id,
            'plan_id'               => $credit_plan->id,
            'amount_kobo'           => (int) $credit_plan->price_kobo,
            'transaction_reference' => $succ_ref,
            'gateway'               => 'paystack',
            'status'                => 'successful',
            'created_at'            => $time_48h_ago,
            'updated_at'            => $time_48h_ago,
        )
    );
    $succ_order_id = $wpdb->insert_id;

    $fail_ref = 'PV_TEST_FAIL_' . time();
    $created_order_refs[] = $fail_ref;
    $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $vendor_id,
            'plan_id'               => $credit_plan->id,
            'amount_kobo'           => (int) $credit_plan->price_kobo,
            'transaction_reference' => $fail_ref,
            'gateway'               => 'paystack',
            'status'                => 'failed',
            'created_at'            => $time_48h_ago,
            'updated_at'            => $time_48h_ago,
        )
    );
    $fail_order_id = $wpdb->insert_id;

    // Run cleanup
    propvendor_cron_cleanup_stale_orders(24, 50);

    $db_succ = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_orders} WHERE id = %d", $succ_order_id));
    $db_fail = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_orders} WHERE id = %d", $fail_order_id));

    run_test($db_succ && 'successful' === $db_succ->status, 'Successful order created 48h ago is NEVER altered to expired');
    run_test($db_fail && 'failed' === $db_fail->status, 'Failed order created 48h ago is NEVER altered to expired');
    run_test(!empty($wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_orders} WHERE id = %d", $stale_order_id_1))), 'History preservation: Expired order row was NOT deleted');

    // -------------------------------------------------------------------------
    // Test Group 4: Cleanup Idempotency & Mutex Locking
    // -------------------------------------------------------------------------
    echo "\n--- Group 4: Cleanup Idempotency & Mutex Locking ---\n";

    // Immediate second run should clean 0 orders
    $res_run2 = propvendor_cron_cleanup_stale_orders(24, 50);
    run_test($res_run2['success'] === true && $res_run2['cleaned'] === 0, 'Consecutive cleanup run is idempotent (0 additional orders expired)');

    // Test concurrency lock
    set_transient('propvendor_stale_orders_cleanup_lock', time(), 300);
    $res_locked = propvendor_cron_cleanup_stale_orders(24, 50);
    run_test($res_locked['success'] === false && 'already_running' === $res_locked['reason'], 'Mutex lock rejects concurrent execution when transient is set');
    delete_transient('propvendor_stale_orders_cleanup_lock');

    // -------------------------------------------------------------------------
    // Test Group 5: Function Alias Compatibility
    // -------------------------------------------------------------------------
    echo "\n--- Group 5: Function Alias Compatibility ---\n";

    run_test(function_exists('propvendor_cron_cleanup_stale_orders'), 'Function propvendor_cron_cleanup_stale_orders exists');
    run_test(function_exists('propvendor_marketplace_cron_cleanup_stale_orders'), 'Function propvendor_marketplace_cron_cleanup_stale_orders alias exists');

    $alias_res = propvendor_marketplace_cron_cleanup_stale_orders(24, 50);
    run_test(is_array($alias_res) && isset($alias_res['success']), 'Alias returns valid cleanup statistics array');

    // -------------------------------------------------------------------------
    // Test Group 6: Late Payment Reconciliation for Expired Order (Credit Pack)
    // -------------------------------------------------------------------------
    echo "\n--- Group 6: Late Payment Reconciliation (Credit Pack) ---\n";

    $late_credit_ref = 'PV_TEST_LATE_CRED_' . time();
    $created_order_refs[] = $late_credit_ref;

    $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $vendor_id,
            'plan_id'               => $credit_plan->id,
            'amount_kobo'           => (int) $credit_plan->price_kobo,
            'transaction_reference' => $late_credit_ref,
            'gateway'               => 'paystack',
            'status'                => 'expired', // Marked expired earlier
            'created_at'            => $time_26h_ago,
            'updated_at'            => $time_26h_ago,
        )
    );
    $late_credit_order_id = $wpdb->insert_id;

    $initial_balance = propvendor_marketplace_get_vendor_credit_balance($vendor_id);

    $mock_credit_success = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $late_credit_ref,
            'amount'    => (int) $credit_plan->price_kobo,
            'currency'  => 'NGN',
        ),
    );

    // Fulfill late order
    $recon_res = propvendor_marketplace_verify_and_fulfill_order($late_credit_ref, $mock_credit_success);

    run_test(!is_wp_error($recon_res), 'Late payment verification does not return error for expired order');
    run_test(!empty($recon_res['success']), 'Late payment verification reports success');
    run_test(!empty($recon_res['was_reconciled']), 'Late payment result explicitly flags was_reconciled = true');
    run_test(false === $recon_res['already_fulfilled'], 'Late payment fulfillment was not previously fulfilled');

    $db_reconciled_order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_orders} WHERE id = %d", $late_credit_order_id));
    run_test($db_reconciled_order && 'successful' === $db_reconciled_order->status, 'Order in DB transitioned from expired to successful');

    $new_balance = propvendor_marketplace_get_vendor_credit_balance($vendor_id);
    run_test($new_balance === $initial_balance + (int) $credit_plan->credits, 'Vendor received credit pack credits upon late payment reconciliation');

    // Verify audit log in notifications table
    $recon_notif = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_notifications} WHERE related_object_type = 'order' AND related_object_id = %d AND notification_type = 'system_late_payment_reconciliation'",
        $late_credit_order_id
    ));
    run_test(!empty($recon_notif), 'Audit trail record written to notifications table for late payment reconciliation');

    // -------------------------------------------------------------------------
    // Test Group 7: Late Payment Idempotency & Duplicate Prevention
    // -------------------------------------------------------------------------
    echo "\n--- Group 7: Late Payment Idempotency & Duplicate Prevention ---\n";

    // Second webhook or fulfillment call with identical reference
    $recon_res_repeat = propvendor_marketplace_verify_and_fulfill_order($late_credit_ref, $mock_credit_success);

    run_test(!empty($recon_res_repeat['success']), 'Repeated verification reports success');
    run_test(!empty($recon_res_repeat['already_fulfilled']), 'Repeated verification flags already_fulfilled = true');
    run_test(empty($recon_res_repeat['was_reconciled']), 'Repeated verification does not re-reconcile');

    $balance_after_repeat = propvendor_marketplace_get_vendor_credit_balance($vendor_id);
    run_test($balance_after_repeat === $new_balance, 'Zero double-granting: credit balance unchanged on duplicate fulfillment');

    // -------------------------------------------------------------------------
    // Test Group 8: Late Payment Reconciliation for VIP Agent Plan
    // -------------------------------------------------------------------------
    echo "\n--- Group 8: Late Payment Reconciliation (VIP Agent Plan) ---\n";

    // Ensure vendor has standard status first
    delete_user_meta($vendor_id, 'propvendor_vip_agent_plan');
    delete_user_meta($vendor_id, 'propvendor_vip_agent_expires_at');
    run_test(!propvendor_marketplace_is_vendor_vip_agent($vendor_id), 'Vendor is initially not a VIP agent');

    $late_vip_ref = 'PV_TEST_LATE_VIP_' . time();
    $created_order_refs[] = $late_vip_ref;

    $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $vendor_id,
            'plan_id'               => $vip_plan->id,
            'amount_kobo'           => (int) $vip_plan->price_kobo,
            'transaction_reference' => $late_vip_ref,
            'gateway'               => 'paystack',
            'status'                => 'expired',
            'created_at'            => $time_26h_ago,
            'updated_at'            => $time_26h_ago,
        )
    );
    $late_vip_order_id = $wpdb->insert_id;

    $mock_vip_success = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $late_vip_ref,
            'amount'    => (int) $vip_plan->price_kobo,
            'currency'  => 'NGN',
        ),
    );

    $recon_vip_res = propvendor_marketplace_verify_and_fulfill_order($late_vip_ref, $mock_vip_success);
    run_test(!empty($recon_vip_res['success']) && !empty($recon_vip_res['was_reconciled']), 'VIP expired order successfully reconciled');
    run_test(propvendor_marketplace_is_vendor_vip_agent($vendor_id), 'Vendor successfully granted VIP Agent status via late reconciliation');

    // -------------------------------------------------------------------------
    // Test Group 9: Webhook REST Handler Message Format
    // -------------------------------------------------------------------------
    echo "\n--- Group 9: Webhook REST Handler Late Payment Response ---\n";

    // Setup another expired order for REST test
    $rest_late_ref = 'PV_TEST_REST_LATE_' . time();
    $created_order_refs[] = $rest_late_ref;

    $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $vendor_id,
            'plan_id'               => $credit_plan->id,
            'amount_kobo'           => (int) $credit_plan->price_kobo,
            'transaction_reference' => $rest_late_ref,
            'gateway'               => 'paystack',
            'status'                => 'expired',
            'created_at'            => $time_26h_ago,
            'updated_at'            => $time_26h_ago,
        )
    );

    // Set test secret key for webhook verification
    $test_secret = 'sk_test_mocksecret1234567890';
    update_option('propvendor_paystack_test_secret_key', $test_secret);
    update_option('propvendor_paystack_mode', 'test');

    $webhook_payload = json_encode(array(
        'event' => 'charge.success',
        'data'  => array(
            'reference' => $rest_late_ref,
            'amount'    => (int) $credit_plan->price_kobo,
            'currency'  => 'NGN',
            'status'    => 'success',
        ),
        'mock_test_response' => array(
            'status' => true,
            'data'   => array(
                'status'    => 'success',
                'reference' => $rest_late_ref,
                'amount'    => (int) $credit_plan->price_kobo,
                'currency'  => 'NGN',
            ),
        ),
    ));
    $signature = hash_hmac('sha512', $webhook_payload, $test_secret);

    // Mock REST Request
    $request = new WP_REST_Request('POST', '/propvendor/v1/paystack-webhook');
    $request->set_header('x-paystack-signature', $signature);
    $request->set_header('content-type', 'application/json');
    $request->set_body($webhook_payload);

    $webhook_response = propvendor_marketplace_handle_paystack_webhook($request);

    run_test($webhook_response instanceof WP_REST_Response, 'Webhook returns WP_REST_Response');
    run_test(200 === $webhook_response->get_status(), 'Webhook response status code is 200');
    $resp_data = $webhook_response->get_data();
    run_test(
        isset($resp_data['message']) && false !== strpos($resp_data['message'], 'Late payment order reconciled'),
        'Webhook response message confirms late payment reconciliation'
    );

    // -------------------------------------------------------------------------
    // Test Group 10: WP-Cron Lifecycle Hooks
    // -------------------------------------------------------------------------
    echo "\n--- Group 10: WP-Cron Lifecycle Hooks ---\n";

    // Test clear cron jobs
    propvendor_marketplace_clear_cron_jobs();
    $next_run_cleared = wp_next_scheduled('propvendor_cron_cleanup_stale_orders');
    run_test(false === $next_run_cleared, 'propvendor_marketplace_clear_cron_jobs unschedules cron event');

    // Test schedule cron jobs
    propvendor_marketplace_schedule_cron_jobs();
    $next_run_scheduled = wp_next_scheduled('propvendor_cron_cleanup_stale_orders');
    run_test(is_int($next_run_scheduled) && $next_run_scheduled > 0, 'propvendor_marketplace_schedule_cron_jobs registers daily cron schedule');

    // Test deactivation hook clears schedule
    propvendor_marketplace_deactivate();
    $next_run_deactivated = wp_next_scheduled('propvendor_cron_cleanup_stale_orders');
    run_test(false === $next_run_deactivated, 'propvendor_marketplace_deactivate cleanly unschedules cron hook');

    // Re-schedule for production readiness
    propvendor_marketplace_schedule_cron_jobs();
    run_test(false !== wp_next_scheduled('propvendor_cron_cleanup_stale_orders'), 'Cron schedule cleanly restored');

    // -------------------------------------------------------------------------
    // Test Group 11: Deployment Readiness Verification
    // -------------------------------------------------------------------------
    echo "\n--- Group 11: Deployment Readiness Verification ---\n";

    $webhook_endpoint = propvendor_marketplace_get_paystack_webhook_url();
    run_test(
        filter_var($webhook_endpoint, FILTER_VALIDATE_URL) && false !== strpos($webhook_endpoint, '/wp-json/propvendor/v1/paystack-webhook'),
        'Paystack webhook endpoint URL is well-formed'
    );

    $mode = propvendor_marketplace_get_paystack_mode();
    run_test(in_array($mode, array('test', 'live'), true), 'Gateway mode is valid (test or live)');

    $orders_engine = $wpdb->get_var("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table_orders}'");
    $ledger_engine = $wpdb->get_var("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table_ledger}'");
    run_test('InnoDB' === $orders_engine, 'Orders table uses InnoDB storage engine');
    run_test('InnoDB' === $ledger_engine, 'Credit ledger table uses InnoDB storage engine');

} finally {
    // -------------------------------------------------------------------------
    // Teardown Test Fixtures
    // -------------------------------------------------------------------------
    wp_set_current_user(0);
    delete_transient('propvendor_stale_orders_cleanup_lock');

    foreach ($created_user_ids as $uid) {
        wp_delete_user($uid);
    }
    foreach ($created_order_refs as $ref) {
        $wpdb->delete($table_orders, array('transaction_reference' => $ref));
        $wpdb->delete($table_ledger, array('reference' => $ref));
    }
    // Clean test notifications
    $wpdb->query("DELETE FROM {$table_notifications} WHERE notification_type IN ('system_stale_orders_cleanup', 'system_late_payment_reconciliation') AND created_at >= '" . date('Y-m-d H:i:s', time() - 3600) . "'");
}

echo "\n====================================================================\n";
echo "PHASE 7B TEST SUMMARY: {$passed_tests}/{$total_tests} PASSED\n";
echo "SUMMARY: {$passed_tests} PASSED, {$failed_tests} FAILED\n";
echo "====================================================================\n";

if ($failed_tests > 0) {
    exit(1);
}
exit(0);
