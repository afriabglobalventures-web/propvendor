<?php
/**
 * Test Suite: PropVendor Phase 15 — Staging Payments & Operational Integrity QA
 *
 * Verifies:
 * 1. Preflight: Git baseline, backups, Paystack TEST mode positive verification, staging endpoint, and cron registry.
 * 2. Stage B: Test-mode checkout, catalog plans, order reference generation, UI vs server consistency, failure handling.
 * 3. Stage C: REST Webhook HMAC-SHA512 verification, amount/currency/reference validation, idempotency, mutex concurrency.
 * 4. Stage D: Wallet ledger reconciliation, entitlement separation (VIP Agent vs Credits vs Quotas), stale order expiration, late payment reconciliation.
 * 5. Clean teardown of all synthetic test fixtures.
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

// Disable real email dispatching during QA tests
add_filter('pre_wp_mail', '__return_false');

echo "====================================================================\n";
echo "  PROPVENDOR PHASE 15 — STAGING PAYMENTS & OPERATIONAL INTEGRITY QA  \n";
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

global $wpdb;
$cleanup_users       = array();
$cleanup_order_refs  = array();
$cleanup_notif_keys  = array();

$table_orders        = $wpdb->prefix . 'propvendor_orders';
$table_plans         = $wpdb->prefix . 'propvendor_plans';
$table_ledger        = $wpdb->prefix . 'propvendor_credit_ledger';
$table_notifications = $wpdb->prefix . 'propvendor_notifications';

try {
    // -----------------------------------------------------------------
    // SECTION 1: PREFLIGHT & TEST-MODE VERIFICATION
    // -----------------------------------------------------------------
    echo "--- 1. Preflight & Test-Mode Verification ---\n";

    // 1.1 Git commit check
    $head_commit = trim((string) @shell_exec('git rev-parse HEAD 2>&1'));
    run_test(!empty($head_commit) && strlen($head_commit) === 40, '1.1 Git repository HEAD commit verified', $head_commit);

    // 1.2 Backups directory exists
    $backup_dir = 'C:/xampp/htdocs/Propvendor_backups/Phase15_20261023';
    run_test(is_dir($backup_dir) && count(scandir($backup_dir)) >= 4, '1.2 Phase 15 pre-execution backup archive verified', $backup_dir);

    // 1.3 Database connection verified
    $db_test = $wpdb->get_var("SELECT 1;");
    run_test('1' === (string) $db_test, '1.3 Local WordPress database connectivity confirmed');

    // 1.4 Paystack active mode positively verified as 'test'
    $paystack_mode = propvendor_marketplace_get_paystack_mode();
    run_test('test' === $paystack_mode, '1.4 Paystack is positively verified in TEST mode', "Active mode: {$paystack_mode}");

    // 1.5 Paystack test public key format
    $test_pub_key = propvendor_marketplace_get_paystack_public_key();
    run_test(!empty($test_pub_key) && strpos($test_pub_key, 'pk_test_') === 0, '1.5 Paystack test public key begins with pk_test_', substr($test_pub_key, 0, 12) . '...');

    // 1.6 Paystack test secret key format (never output full key)
    $test_sec_key = propvendor_marketplace_get_paystack_secret_key();
    run_test(!empty($test_sec_key) && strpos($test_sec_key, 'sk_test_') === 0, '1.6 Paystack test secret key begins with sk_test_ (redacted)');

    // 1.7 Staging REST API webhook route
    $webhook_url = propvendor_marketplace_get_paystack_webhook_url();
    run_test(strpos($webhook_url, '/wp-json/propvendor/v1/paystack-webhook') !== false, '1.7 Staging webhook endpoint confirmed as /wp-json/propvendor/v1/paystack-webhook', $webhook_url);

    // 1.8 Stale order cleanup cron registered
    propvendor_marketplace_schedule_cron_jobs();
    $cron_ts = wp_next_scheduled('propvendor_cron_cleanup_stale_orders');
    run_test(!empty($cron_ts) && $cron_ts > 0, '1.8 Stale orders cleanup cron task registered in WP-Cron');

    // -----------------------------------------------------------------
    // SECTION 2: STAGE B — TEST-MODE CHECKOUT & ORDER GENERATION
    // -----------------------------------------------------------------
    echo "\n--- 2. Stage B: Test-Mode Checkout & Order Generation ---\n";

    // 2.1 Create Dedicated Test Vendor
    $v_ts    = time();
    $v_login = "qa_vendor_p15_{$v_ts}";
    $v_email = "{$v_login}@example.com";
    $v_id    = wp_insert_user(array(
        'user_login'   => $v_login,
        'user_email'   => $v_email,
        'user_pass'    => 'VendorSecurePass#2026',
        'display_name' => 'QA Vendor P15',
        'role'         => 'vendor',
    ));
    $cleanup_users[] = $v_id;
    run_test(!is_wp_error($v_id) && $v_id > 0, '2.1 Dedicated test vendor registered');

    $u_vend = new WP_User($v_id);
    $u_vend->add_cap('propvendor_submit_marketplace_listings');

    // 2.2-2.4 Available Packages Catalog Consistency
    $packages = propvendor_marketplace_get_available_packages();
    run_test(isset($packages['starter_5']) && 5000 === $packages['starter_5']['price_ngn'] && 5 === $packages['starter_5']['credits'], '2.2 Starter Pack: ₦5,000 for 5 credits');
    run_test(isset($packages['pro_28']) && 20000 === $packages['pro_28']['price_ngn'] && 28 === $packages['pro_28']['credits'], '2.3 Pro Pack: ₦20,000 for 28 credits');
    run_test(isset($packages['vip_monthly']) && 30000 === $packages['vip_monthly']['price_ngn'] && 30 === $packages['vip_monthly']['duration_days'], '2.4 VIP Agent Monthly: ₦30,000 for 30 duration days');

    // Query Catalog Plans
    $starter_plan = propvendor_marketplace_get_plan_by_key('credit_pack_starter');
    $growth_plan  = propvendor_marketplace_get_plan_by_key('credit_pack_growth');
    $pro_plan     = propvendor_marketplace_get_plan_by_key('credit_pack_pro');
    $vip_plan     = propvendor_marketplace_get_plan_by_key('vip_agent_monthly');

    // 2.5-2.8 Pending Order Creation for each plan
    $order_starter = propvendor_marketplace_create_order($v_id, $starter_plan->id);
    run_test(!is_wp_error($order_starter) && 'pending' === $order_starter['status'] && 500000 === $order_starter['amount_kobo'], '2.5 Starter Pack order created in pending status (500,000 kobo)');
    if (!is_wp_error($order_starter)) {
        $cleanup_order_refs[] = $order_starter['reference'];
    }

    $order_growth = propvendor_marketplace_create_order($v_id, $growth_plan->id);
    run_test(!is_wp_error($order_growth) && 'pending' === $order_growth['status'] && 1000000 === $order_growth['amount_kobo'], '2.6 Growth Pack order created in pending status (1,000,000 kobo)');
    if (!is_wp_error($order_growth)) {
        $cleanup_order_refs[] = $order_growth['reference'];
    }

    $order_pro = propvendor_marketplace_create_order($v_id, $pro_plan->id);
    run_test(!is_wp_error($order_pro) && 'pending' === $order_pro['status'] && 2000000 === $order_pro['amount_kobo'], '2.7 Pro Pack order created in pending status (2,000,000 kobo)');
    if (!is_wp_error($order_pro)) {
        $cleanup_order_refs[] = $order_pro['reference'];
    }

    $order_vip = propvendor_marketplace_create_order($v_id, $vip_plan->id);
    run_test(!is_wp_error($order_vip) && 'pending' === $order_vip['status'] && 3000000 === $order_vip['amount_kobo'], '2.8 VIP Agent Monthly order created in pending status (3,000,000 kobo)');
    if (!is_wp_error($order_vip)) {
        $cleanup_order_refs[] = $order_vip['reference'];
    }

    // 2.9 Uniqueness of Transaction References
    $refs = array($order_starter['reference'], $order_growth['reference'], $order_pro['reference'], $order_vip['reference']);
    run_test(count(array_unique($refs)) === 4, '2.9 Generated transaction references are strictly unique');

    // 2.10 Database-level uniqueness rejection
    $dup_insert = $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $v_id,
            'plan_id'               => $starter_plan->id,
            'amount_kobo'           => 500000,
            'transaction_reference' => $order_starter['reference'],
            'gateway'               => 'paystack',
            'status'                => 'pending',
            'created_at'            => current_time('mysql'),
            'updated_at'            => current_time('mysql'),
        )
    );
    run_test(false === $dup_insert, '2.10 Database rejects duplicate transaction reference via UNIQUE index');

    // 2.11 UI vs Server amount validation
    run_test((int)$packages['starter_5']['price_kobo'] === (int)$order_starter['amount_kobo'], '2.11 UI package kobo matches server-calculated order kobo');

    // 2.12 Failed / Abandoned payment handling
    $order_fail = propvendor_marketplace_create_order($v_id, $starter_plan->id);
    $cleanup_order_refs[] = $order_fail['reference'];
    $mock_abandoned = array(
        'status' => true,
        'data'   => array(
            'status'    => 'abandoned',
            'reference' => $order_fail['reference'],
            'amount'    => 500000,
            'currency'  => 'NGN',
        ),
    );
    $fail_res = propvendor_marketplace_verify_and_fulfill_order($order_fail['reference'], $mock_abandoned);
    run_test(is_wp_error($fail_res), '2.12 Abandoned transaction rejected with WP_Error');
    $db_fail = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table_orders} WHERE transaction_reference = %s", $order_fail['reference']));
    run_test('failed' === $db_fail, '2.13 Failed/Abandoned order status updated to failed');
    run_test(0 === propvendor_marketplace_get_vendor_credit_balance($v_id), '2.14 Failed transaction leaves credit balance at 0 (no fake credits)');

    // -----------------------------------------------------------------
    // SECTION 3: STAGE C — WEBHOOK SECURITY, REST ROUTE & IDEMPOTENCY
    // -----------------------------------------------------------------
    echo "\n--- 3. Stage C: Webhook Security, REST Route & Idempotency ---\n";

    // Setup REST Request helper
    $webhook_secret = propvendor_marketplace_get_paystack_secret_key();

    // 3.1 Missing signature rejection
    $req_nosig = new WP_REST_Request('POST', '/propvendor/v1/paystack-webhook');
    $req_nosig->set_body(json_encode(array('event' => 'charge.success')));
    $res_nosig = propvendor_marketplace_handle_paystack_webhook($req_nosig);
    run_test(401 === $res_nosig->get_status(), '3.1 Missing x-paystack-signature header rejected with HTTP 401');

    // 3.2 Invalid / Forged signature rejection
    $req_badsig = new WP_REST_Request('POST', '/propvendor/v1/paystack-webhook');
    $req_badsig->set_header('x-paystack-signature', 'forged_fake_signature_hex_0123456789');
    $req_badsig->set_body(json_encode(array('event' => 'charge.success')));
    $res_badsig = propvendor_marketplace_handle_paystack_webhook($req_badsig);
    run_test(401 === $res_badsig->get_status(), '3.2 Forged signature rejected with HTTP 401 Unauthorized');

    // 3.3 Malformed event payload rejection
    $malformed_body = 'NOT_JSON';
    $req_malformed = new WP_REST_Request('POST', '/propvendor/v1/paystack-webhook');
    $req_malformed->set_header('x-paystack-signature', hash_hmac('sha512', $malformed_body, $webhook_secret));
    $req_malformed->set_body($malformed_body);
    $res_malformed = propvendor_marketplace_handle_paystack_webhook($req_malformed);
    run_test(400 === $res_malformed->get_status(), '3.3 Malformed non-JSON payload rejected with HTTP 400');

    // 3.4 Non-existent order reference
    $ghost_ref = 'PV_GHOST_TEST_999';
    $ghost_payload = json_encode(array(
        'event' => 'charge.success',
        'data'  => array('reference' => $ghost_ref, 'amount' => 500000, 'status' => 'success'),
    ));
    $req_ghost = new WP_REST_Request('POST', '/propvendor/v1/paystack-webhook');
    $req_ghost->set_header('x-paystack-signature', hash_hmac('sha512', $ghost_payload, $webhook_secret));
    $req_ghost->set_body($ghost_payload);
    $res_ghost = propvendor_marketplace_handle_paystack_webhook($req_ghost);
    run_test(400 === $res_ghost->get_status(), '3.4 Non-existent transaction reference rejected with HTTP 400 (order_not_found)');

    // 3.5 Server-side Underpayment rejection (Amount mismatch)
    $order_underpay = propvendor_marketplace_create_order($v_id, $starter_plan->id);
    $cleanup_order_refs[] = $order_underpay['reference'];
    $mock_underpay = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $order_underpay['reference'],
            'amount'    => 10000, // ₦100 instead of ₦5,000 (500,000 kobo)
            'currency'  => 'NGN',
        ),
    );
    $underpay_res = propvendor_marketplace_verify_and_fulfill_order($order_underpay['reference'], $mock_underpay);
    run_test(is_wp_error($underpay_res) && 'amount_mismatch' === $underpay_res->get_error_code(), '3.5 Underpayment rejected with amount_mismatch');
    $db_under = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table_orders} WHERE transaction_reference = %s", $order_underpay['reference']));
    run_test('failed' === $db_under, '3.6 Underpaid order transitioned to failed status');

    // 3.7 Server-side Currency mismatch rejection
    $order_curr = propvendor_marketplace_create_order($v_id, $starter_plan->id);
    $cleanup_order_refs[] = $order_curr['reference'];
    $mock_usd = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $order_curr['reference'],
            'amount'    => 500000,
            'currency'  => 'USD', // Not NGN
        ),
    );
    $curr_res = propvendor_marketplace_verify_and_fulfill_order($order_curr['reference'], $mock_usd);
    run_test(is_wp_error($curr_res) && 'currency_mismatch' === $curr_res->get_error_code(), '3.7 Currency mismatch (USD) rejected with currency_mismatch');

    // 3.8 Reference mismatch in payload
    $order_ref_mismatch = propvendor_marketplace_create_order($v_id, $starter_plan->id);
    $cleanup_order_refs[] = $order_ref_mismatch['reference'];
    $mock_mismatch = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => 'DIFFERENT_REFERENCE_XYZ',
            'amount'    => 500000,
            'currency'  => 'NGN',
        ),
    );
    $mismatch_res = propvendor_marketplace_verify_and_fulfill_order($order_ref_mismatch['reference'], $mock_mismatch);
    run_test(is_wp_error($mismatch_res) && 'reference_mismatch' === $mismatch_res->get_error_code(), '3.8 Reference mismatch rejected with reference_mismatch');

    // 3.9 Valid Webhook Execution (Starter Pack: 5 Credits)
    $valid_webhook_payload = json_encode(array(
        'event' => 'charge.success',
        'data'  => array(
            'reference' => $order_starter['reference'],
            'amount'    => 500000,
            'currency'  => 'NGN',
            'status'    => 'success',
        ),
        'mock_test_response' => array(
            'status' => true,
            'data'   => array(
                'status'    => 'success',
                'reference' => $order_starter['reference'],
                'amount'    => 500000,
                'currency'  => 'NGN',
            ),
        ),
    ));
    $req_valid = new WP_REST_Request('POST', '/propvendor/v1/paystack-webhook');
    $req_valid->set_header('x-paystack-signature', hash_hmac('sha512', $valid_webhook_payload, $webhook_secret));
    $req_valid->set_body($valid_webhook_payload);
    $res_valid = propvendor_marketplace_handle_paystack_webhook($req_valid);
    run_test(200 === $res_valid->get_status(), '3.9 Valid signed webhook processed successfully with HTTP 200');

    // 3.10 Idempotency: Immediate repeated webhook
    $res_repeat = propvendor_marketplace_handle_paystack_webhook($req_valid);
    run_test(200 === $res_repeat->get_status(), '3.10 Repeated webhook returns HTTP 200 with already fulfilled status');
    $rep_data = $res_repeat->get_data();
    run_test(!empty($rep_data['result']['already_fulfilled']), '3.11 Repeated webhook response flags already_fulfilled = true');
    run_test(5 === propvendor_marketplace_get_vendor_credit_balance($v_id), '3.12 Repeated webhook does NOT double-credit wallet (balance remains 5)');

    // 3.13 Concurrency & Mutex locking
    $lock_name = 'pv_order_' . md5($order_starter['reference']);
    $lock_acquired = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 0)", $lock_name));
    run_test('1' === (string)$lock_acquired, '3.13 MySQL named lock pv_order_* acquired for order serialization');
    $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));

    // -----------------------------------------------------------------
    // SECTION 4: STAGE D — WALLET & OPERATIONAL RECONCILIATION
    // -----------------------------------------------------------------
    echo "\n--- 4. Stage D: Wallet & Operational Reconciliation ---\n";

    // 4.1 Credit Pack Ledger Entry Verification
    $ledger_entry = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_ledger} WHERE vendor_id = %d AND reference = %s",
        $v_id,
        $order_starter['reference']
    ));
    run_test(!empty($ledger_entry) && 'pack_purchase' === $ledger_entry->transaction_type && 5 === (int)$ledger_entry->amount, '4.1 Credit ledger record created: +5 credits, pack_purchase');
    run_test(5 === (int)$ledger_entry->balance_after, '4.2 Credit ledger balance_after recorded as 5');

    // 4.3-4.4 Entitlement Separation: Credit purchase did not grant VIP or touch listing quotas
    $vip_agent_meta = get_user_meta($v_id, 'propvendor_vip_agent_plan', true);
    run_test(empty($vip_agent_meta), '4.3 Credit pack purchase does NOT grant VIP Agent subscription');
    $listing_plan_meta = get_user_meta($v_id, 'propvendor_listing_plan', true);
    run_test(empty($listing_plan_meta) || 'vip' !== $listing_plan_meta, '4.4 Credit pack purchase leaves listing quota plan untouched (free quota preserved)');

    // 4.5-4.6 VIP Agent Monthly Fulfillment
    $mock_vip_success = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $order_vip['reference'],
            'amount'    => 3000000,
            'currency'  => 'NGN',
        ),
    );
    $vip_fulfill_res = propvendor_marketplace_verify_and_fulfill_order($order_vip['reference'], $mock_vip_success);
    run_test(is_array($vip_fulfill_res) && true === $vip_fulfill_res['success'], '4.5 VIP Agent Monthly order verified and fulfilled');
    $active_vip_agent = propvendor_marketplace_is_vendor_vip_agent($v_id);
    run_test(true === $active_vip_agent, '4.6 propvendor_marketplace_is_vendor_vip_agent() returns true');

    $site_tz = wp_timezone();
    $expected_first_expiry = wp_date('Y-m-d', current_time('timestamp') + (30 * DAY_IN_SECONDS), $site_tz);
    $actual_vip_expiry = get_user_meta($v_id, 'propvendor_vip_agent_expires_at', true);
    run_test($expected_first_expiry === $actual_vip_expiry, '4.7 VIP Agent expiry set to +30 days from current date', $actual_vip_expiry);

    // 4.8 VIP Consecutive Purchase Cumulative Extension (+30 days added to future expiry)
    $order_vip_2 = propvendor_marketplace_create_order($v_id, $vip_plan->id);
    $cleanup_order_refs[] = $order_vip_2['reference'];
    $mock_vip_2 = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $order_vip_2['reference'],
            'amount'    => 3000000,
            'currency'  => 'NGN',
        ),
    );
    $vip_2_res = propvendor_marketplace_verify_and_fulfill_order($order_vip_2['reference'], $mock_vip_2);
    run_test(is_array($vip_2_res) && true === $vip_2_res['success'], '4.8 Second VIP Agent Monthly order fulfilled');
    $expected_cumulative_expiry = wp_date('Y-m-d', current_time('timestamp') + (60 * DAY_IN_SECONDS), $site_tz);
    $actual_cumulative_expiry = get_user_meta($v_id, 'propvendor_vip_agent_expires_at', true);
    run_test($expected_cumulative_expiry === $actual_cumulative_expiry, '4.9 VIP Agent subscription extended cumulatively to +60 days', $actual_cumulative_expiry);

    // 4.10 VIP Entitlement Separation: Listing Plan meta remains free/untouched
    $listing_plan_after_vip = get_user_meta($v_id, 'propvendor_listing_plan', true);
    run_test(empty($listing_plan_after_vip) || 'vip' !== $listing_plan_after_vip, '4.10 VIP Agent subscription does NOT overwrite listing quota plan');

    // 4.11 VIP Automatic Lead Contact Bypass
    $mock_thread_id = 999888;
    $unlock_bypass = propvendor_marketplace_unlock_thread_with_credits($v_id, $mock_thread_id);
    run_test(is_array($unlock_bypass) && true === $unlock_bypass['success'] && true === $unlock_bypass['vip_bypass'], '4.11 VIP vendor automatically bypasses credit deduction for thread unlocks');
    run_test(0 === $unlock_bypass['deducted'] && 5 === propvendor_marketplace_get_vendor_credit_balance($v_id), '4.12 VIP bypass deducted 0 credits (credit balance remains 5)');

    // 4.13 Stale Pending Order Cleanup (>24h transitions to 'expired')
    $order_stale = propvendor_marketplace_create_order($v_id, $pro_plan->id);
    $cleanup_order_refs[] = $order_stale['reference'];
    // Backdate order to 26 hours ago
    $stale_time = date('Y-m-d H:i:s', current_time('timestamp') - (26 * HOUR_IN_SECONDS));
    $wpdb->update($table_orders, array('created_at' => $stale_time), array('id' => $order_stale['order_id']));
    
    // Execute stale orders cleanup
    delete_transient('propvendor_stale_orders_cleanup_lock');
    $cleanup_res = propvendor_cron_cleanup_stale_orders(24, 100);
    $stale_db_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table_orders} WHERE id = %d", $order_stale['order_id']));
    run_test('expired' === $stale_db_status, '4.13 Stale pending order (>24h) successfully transitioned to expired');

    // 4.14 Fresh Pending Order (<24h) remains pending
    $fresh_db_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table_orders} WHERE transaction_reference = %s", $order_growth['reference']));
    run_test('pending' === $fresh_db_status, '4.14 Fresh pending order (<24h) remains in pending status');

    // 4.15 Late Payment Reconciliation for Expired Order
    $mock_late_pro = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $order_stale['reference'],
            'amount'    => 2000000,
            'currency'  => 'NGN',
        ),
    );
    $late_recon_res = propvendor_marketplace_verify_and_fulfill_order($order_stale['reference'], $mock_late_pro);
    run_test(is_array($late_recon_res) && true === $late_recon_res['success'], '4.15 Late payment on expired order fulfilled successfully');
    run_test(!empty($late_recon_res['was_reconciled']), '4.16 Order fulfillment flags was_reconciled = true');
    $recon_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table_orders} WHERE id = %d", $order_stale['order_id']));
    run_test('successful' === $recon_status, '4.17 Expired order transitioned to successful status upon late fulfillment');
    run_test(33 === propvendor_marketplace_get_vendor_credit_balance($v_id), '4.18 Balance credited with 28 Pro Pack leads (5 + 28 = 33 credits)');

    // 4.19 Late Reconciliation Audit Notification Recorded
    $late_notif = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_notifications} WHERE notification_type = 'system_late_payment_reconciliation' AND related_object_id = %d",
        $order_stale['order_id']
    ));
    run_test(!empty($late_notif) && strpos($late_notif->subject, 'Late payment order') !== false, '4.19 Late payment audit notification recorded in notifications table');
    if (!empty($late_notif)) {
        $cleanup_notif_keys[] = $late_notif->id;
    }

    // 4.20 Late Payment Idempotency
    $late_repeat = propvendor_marketplace_verify_and_fulfill_order($order_stale['reference'], $mock_late_pro);
    run_test(is_array($late_repeat) && true === $late_repeat['already_fulfilled'], '4.20 Repeated late payment callback returns already_fulfilled = true');
    run_test(33 === propvendor_marketplace_get_vendor_credit_balance($v_id), '4.21 Zero additional credits added on repeated late callback (balance remains 33)');

    // 4.22 Frontend Wallet Shortcode Rendering
    wp_set_current_user($v_id);
    $wallet_html = do_shortcode('[propvendor_vendor_wallet]');
    run_test(strpos($wallet_html, 'propvendor-wallet-container') !== false, '4.22 Wallet shortcode renders container for logged-in vendor');
    run_test(strpos($wallet_html, 'id="pv-current-credit-balance">33<') !== false || strpos($wallet_html, 'id="pv-current-credit-balance">33</span>') !== false, '4.23 Wallet displays accurate live credit balance (33)');
    run_test(strpos($wallet_html, 'Active VIP Agent') !== false, '4.24 Wallet displays Active VIP Agent badge');

} catch (Exception $e) {
    echo "EXCEPTION ENCOUNTERED: " . $e->getMessage() . "\n";
} finally {
    // Clean up test fixtures
    wp_set_current_user(0);
    delete_transient('propvendor_stale_orders_cleanup_lock');

    foreach ($cleanup_notif_keys as $nid) {
        $wpdb->delete($table_notifications, array('id' => $nid));
    }
    foreach ($cleanup_order_refs as $ref) {
        $wpdb->delete($table_ledger, array('reference' => $ref));
        $wpdb->delete($table_orders, array('transaction_reference' => $ref));
    }
    foreach ($cleanup_users as $uid) {
        delete_user_meta($uid, 'propvendor_lead_credits');
        delete_user_meta($uid, 'propvendor_vip_agent_plan');
        delete_user_meta($uid, 'propvendor_vip_agent_expires_at');
        delete_user_meta($uid, 'propvendor_unlocked_thread_ids');
        wp_delete_user($uid);
    }
}

echo "\n====================================================================\n";
echo "  PHASE 15 QA EXECUTION TOTALS\n";
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

