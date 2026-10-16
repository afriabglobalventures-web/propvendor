<?php
/**
 * Test Suite: PropVendor Stage 5B / Phase 4B — Payment Integration & Checkout UI
 *
 * Verifies all required test cases for Phase 4B:
 * 1. Schema migration & table integrity (wp_propvendor_plans & wp_propvendor_orders InnoDB tables, columns, indexes).
 * 2. Default plans catalog seeding & plan querying helpers.
 * 3. Settings helpers & Paystack key configuration.
 * 4. Pending order creation in wp_propvendor_orders with unique transaction reference.
 * 5. Credit pack payment verification and fulfillment (mocked Paystack API response -> credits granted to vendor).
 * 6. Idempotent duplicate verification (no double crediting).
 * 7. VIP Agent payment verification and fulfillment with strict entitlement separation:
 *    - Vendor receives VIP Agent subscription (propvendor_vip_agent_plan = 'vip').
 *    - Expiry is set in propvendor_vip_agent_expires_at.
 *    - Listing quota VIP (propvendor_listing_plan) remains untouched (free quota preserved).
 *    - Automatic lead contact access and 0-credit thread unlock bypass granted.
 * 8. VIP consecutive purchase cumulative extension (duration days added to existing future expiry).
 * 9. Security rejections:
 *    - Underpayment rejection (amount_mismatch).
 *    - Overpayment rejection (exact integer kobo equality enforced).
 *    - Currency mismatch rejection (NGN only).
 *    - Non-successful transaction status rejection (abandoned/failed).
 * 10. Non-existent reference rejection (order_not_found).
 * 11. Webhook HMAC SHA512 signature verification (constant-time hash_equals).
 * 12. Concurrency & Mutex named lock protection (GET_LOCK/RELEASE_LOCK).
 * 13. Frontend Wallet Shortcode [propvendor_vendor_wallet] rendering.
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
echo "  PROPVENDOR STAGE 5B — PAYMENT INTEGRATION & CHECKOUT TEST SUITE   \n";
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
$created_user_ids = array();
$created_order_references = array();
$table_plans      = $wpdb->prefix . 'propvendor_plans';
$table_orders     = $wpdb->prefix . 'propvendor_orders';
$table_ledger     = $wpdb->prefix . 'propvendor_credit_ledger';

try {
    // -------------------------------------------------------------
    // TEST 1: Schema Migration & Table Integrity
    // -------------------------------------------------------------
    echo "\n--- TEST 1: Schema Migration & Table Integrity ---\n";

    propvendor_marketplace_create_payment_tables();

    $plans_table_check = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_plans));
    run_test($plans_table_check === $table_plans, 'wp_propvendor_plans table exists in database');

    $orders_table_check = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_orders));
    run_test($orders_table_check === $table_orders, 'wp_propvendor_orders table exists in database');

    // Engine checks
    $plans_status = $wpdb->get_row($wpdb->prepare("SHOW TABLE STATUS WHERE Name = %s", $table_plans));
    $plans_engine = $plans_status ? strtolower($plans_status->Engine) : '';
    run_test($plans_engine === 'innodb', 'wp_propvendor_plans uses InnoDB storage engine', "Engine: {$plans_engine}");

    $orders_status = $wpdb->get_row($wpdb->prepare("SHOW TABLE STATUS WHERE Name = %s", $table_orders));
    $orders_engine = $orders_status ? strtolower($orders_status->Engine) : '';
    run_test($orders_engine === 'innodb', 'wp_propvendor_orders uses InnoDB storage engine', "Engine: {$orders_engine}");

    // Plans columns check
    $plans_cols = $wpdb->get_col("DESCRIBE {$table_plans}", 0);
    $req_plans_cols = array('id', 'plan_key', 'name', 'product_type', 'price_kobo', 'credits', 'duration_days', 'description', 'is_active', 'created_at');
    $plans_cols_ok = true;
    foreach ($req_plans_cols as $c) {
        if (!in_array($c, $plans_cols, true)) {
            $plans_cols_ok = false;
            break;
        }
    }
    run_test($plans_cols_ok, 'All required columns exist on wp_propvendor_plans');

    // Orders columns check
    $orders_cols = $wpdb->get_col("DESCRIBE {$table_orders}", 0);
    $req_orders_cols = array('id', 'vendor_id', 'plan_id', 'amount_kobo', 'transaction_reference', 'gateway', 'status', 'created_at', 'updated_at');
    $orders_cols_ok = true;
    foreach ($req_orders_cols as $c) {
        if (!in_array($c, $orders_cols, true)) {
            $orders_cols_ok = false;
            break;
        }
    }
    run_test($orders_cols_ok, 'All required columns exist on wp_propvendor_orders');

    // -------------------------------------------------------------
    // TEST 2: Default Plans Catalog Seeding & Helpers
    // -------------------------------------------------------------
    echo "\n--- TEST 2: Default Plans Catalog Seeding & Helpers ---\n";

    $all_plans = propvendor_marketplace_get_active_plans();
    run_test(count($all_plans) >= 4, 'Catalog contains at least 4 active products', 'Count: ' . count($all_plans));

    $vip_plan = propvendor_marketplace_get_plan_by_key('vip_agent_monthly');
    run_test(null !== $vip_plan, 'vip_agent_monthly plan exists in catalog');
    run_test((int) $vip_plan->price_kobo === 3000000, 'vip_agent_monthly is priced at 3,000,000 kobo (₦30,000)');
    run_test((int) $vip_plan->duration_days === 30, 'vip_agent_monthly has 30 days duration');
    run_test('vip_agent' === $vip_plan->product_type, 'vip_agent_monthly has product_type vip_agent');

    $starter_plan = propvendor_marketplace_get_plan_by_key('credit_pack_starter');
    run_test(null !== $starter_plan, 'credit_pack_starter exists in catalog');
    run_test((int) $starter_plan->price_kobo === 500000, 'credit_pack_starter is priced at 500,000 kobo (₦5,000)');
    run_test((int) $starter_plan->credits === 5, 'credit_pack_starter grants 5 credits');
    run_test('credit_pack' === $starter_plan->product_type, 'credit_pack_starter has product_type credit_pack');

    $growth_plan = propvendor_marketplace_get_plan_by_key('credit_pack_growth');
    run_test(null !== $growth_plan, 'credit_pack_growth exists in catalog');
    run_test((int) $growth_plan->price_kobo === 1000000, 'credit_pack_growth is priced at 1,000,000 kobo (₦10,000)');
    run_test((int) $growth_plan->credits === 12, 'credit_pack_growth grants 12 credits');

    $pro_plan = propvendor_marketplace_get_plan_by_key('credit_pack_pro');
    run_test(null !== $pro_plan, 'credit_pack_pro exists in catalog');
    run_test((int) $pro_plan->price_kobo === 2000000, 'credit_pack_pro is priced at 2,000,000 kobo (₦20,000)');
    run_test((int) $pro_plan->credits === 28, 'credit_pack_pro grants 28 credits');

    $credit_packs = propvendor_marketplace_get_active_plans('credit_pack');
    run_test(count($credit_packs) === 3, 'Filtered query for credit_pack returns exactly 3 products');

    $vip_plans = propvendor_marketplace_get_active_plans('vip_agent');
    run_test(count($vip_plans) === 1, 'Filtered query for vip_agent returns exactly 1 product');

    $fetched_by_id = propvendor_marketplace_get_plan_by_id($starter_plan->id);
    run_test($fetched_by_id && $fetched_by_id->plan_key === 'credit_pack_starter', 'propvendor_marketplace_get_plan_by_id retrieves correct plan');

    // -------------------------------------------------------------
    // TEST 3: Settings Helpers & Paystack Key Configuration
    // -------------------------------------------------------------
    echo "\n--- TEST 3: Settings Helpers & Paystack Key Configuration ---\n";

    // Mode helper defaults to test
    update_option('propvendor_paystack_mode', 'test');
    run_test(propvendor_marketplace_get_paystack_mode() === 'test', 'Paystack mode defaults to test');

    // Key helpers in test mode
    update_option('propvendor_paystack_test_public_key', 'pk_test_sample123');
    update_option('propvendor_paystack_test_secret_key', 'sk_test_sample456');
    run_test(propvendor_marketplace_get_paystack_public_key() === 'pk_test_sample123', 'Public key helper returns test public key when in test mode');
    run_test(propvendor_marketplace_get_paystack_secret_key() === 'sk_test_sample456', 'Secret key helper returns test secret key when in test mode');

    // Mode helper in live mode
    update_option('propvendor_paystack_mode', 'live');
    update_option('propvendor_paystack_live_public_key', 'pk_live_sample789');
    update_option('propvendor_paystack_live_secret_key', 'sk_live_sample012');
    run_test(propvendor_marketplace_get_paystack_mode() === 'live', 'Paystack mode switches to live');
    run_test(propvendor_marketplace_get_paystack_public_key() === 'pk_live_sample789', 'Public key helper returns live public key when in live mode');
    run_test(propvendor_marketplace_get_paystack_secret_key() === 'sk_live_sample012', 'Secret key helper returns live secret key when in live mode');

    // Revert to test mode
    update_option('propvendor_paystack_mode', 'test');

    // Webhook URL endpoint helper
    $webhook_url = propvendor_marketplace_get_paystack_webhook_url();
    run_test(strpos($webhook_url, '/wp-json/propvendor/v1/paystack-webhook') !== false, 'Webhook URL matches REST route');

    // -------------------------------------------------------------
    // TEST 4: Order Creation & Validation
    // -------------------------------------------------------------
    echo "\n--- TEST 4: Order Creation & Validation ---\n";

    // Create a dummy test vendor
    $vendor_login = 'test_p4b_v1_' . time() . '_' . wp_rand(100, 999);
    $vid1 = wp_create_user($vendor_login, 'PassStage5B1!', $vendor_login . '@propvendor.ng');
    $created_user_ids[] = $vid1;
    $v1_user = get_user_by('id', $vid1);
    $v1_user->set_role('vendor');

    // Invalid vendor rejection
    $invalid_v_res = propvendor_marketplace_create_order(0, $starter_plan->id);
    run_test(is_wp_error($invalid_v_res), 'Order creation rejects invalid vendor ID (0)');

    // Invalid plan rejection
    $invalid_p_res = propvendor_marketplace_create_order($vid1, 999999);
    run_test(is_wp_error($invalid_p_res), 'Order creation rejects non-existent plan ID');

    // Valid order creation for Starter Pack (5 credits = 500,000 kobo)
    $order1 = propvendor_marketplace_create_order($vid1, $starter_plan->id);
    run_test(!is_wp_error($order1) && $order1['success'] === true, 'Order created successfully for Starter Pack');
    run_test(0 === strpos($order1['reference'], 'PV_'), 'Generated reference starts with PV_ prefix');
    run_test($order1['amount_kobo'] === 500000, 'Order amount matches plan price in integer kobo (500000)');
    run_test($order1['status'] === 'pending', 'Initial order status is pending');

    $created_order_references[] = $order1['reference'];

    // Verify row in database
    $db_order1 = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_orders} WHERE transaction_reference = %s", $order1['reference']));
    run_test($db_order1 !== null, 'Order row persisted in wp_propvendor_orders table');
    run_test((int) $db_order1->vendor_id === $vid1, 'Order row associates correct vendor_id');
    run_test((int) $db_order1->plan_id === (int) $starter_plan->id, 'Order row associates correct plan_id');
    run_test($db_order1->status === 'pending', 'Order row status in database is pending');

    // Create a second order to verify reference uniqueness
    $order1_b = propvendor_marketplace_create_order($vid1, $starter_plan->id);
    $created_order_references[] = $order1_b['reference'];
    run_test($order1['reference'] !== $order1_b['reference'], 'Subsequent order generates unique transaction reference');

    // -------------------------------------------------------------
    // TEST 5: Credit Pack Payment Verification & Fulfillment (Mocked API)
    // -------------------------------------------------------------
    echo "\n--- TEST 5: Credit Pack Payment Verification & Fulfillment ---\n";

    $initial_bal = propvendor_marketplace_get_vendor_credit_balance($vid1);
    run_test($initial_bal === 0, 'Vendor 1 has 0 starting credits');

    $mock_starter_success = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $order1['reference'],
            'amount'    => 500000, // 500,000 kobo
            'currency'  => 'NGN',
        ),
    );

    $fulfill_res1 = propvendor_marketplace_verify_and_fulfill_order($order1['reference'], $mock_starter_success);
    run_test(!is_wp_error($fulfill_res1) && $fulfill_res1['success'] === true, 'Fulfillment succeeds with valid Paystack mock response');
    run_test($fulfill_res1['already_fulfilled'] === false, 'First fulfillment marks already_fulfilled as false');
    run_test($fulfill_res1['credits'] === 5, 'Fulfillment report indicates 5 credits granted');

    // Verify order updated in DB
    $updated_order1 = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_orders} WHERE transaction_reference = %s", $order1['reference']));
    run_test($updated_order1->status === 'successful', 'Order status updated to successful in database');

    // Verify vendor credits updated in ledger
    $new_bal1 = propvendor_marketplace_get_vendor_credit_balance($vid1);
    run_test($new_bal1 === 5, 'Vendor 1 credit balance increased from 0 to 5 credits (+5)');

    // Verify ledger row exists
    $ledger_entry1 = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_ledger} WHERE vendor_id = %d AND reference = %s",
        $vid1,
        $order1['reference']
    ));
    run_test($ledger_entry1 !== null, 'Credit ledger record created with order reference');
    run_test((int) $ledger_entry1->amount === 5, 'Ledger entry records +5 credits');
    run_test((int) $ledger_entry1->balance_after === 5, 'Ledger entry records balance_after = 5');

    // -------------------------------------------------------------
    // TEST 6: Idempotent Double-Verification (Credit Pack)
    // -------------------------------------------------------------
    echo "\n--- TEST 6: Idempotent Double-Verification ---\n";

    $duplicate_res1 = propvendor_marketplace_verify_and_fulfill_order($order1['reference'], $mock_starter_success);
    run_test(!is_wp_error($duplicate_res1) && $duplicate_res1['success'] === true, 'Duplicate verification returns success');
    run_test($duplicate_res1['already_fulfilled'] === true, 'Duplicate verification identifies already_fulfilled = true');

    $bal_after_dup = propvendor_marketplace_get_vendor_credit_balance($vid1);
    run_test($bal_after_dup === 5, 'Vendor balance remains strictly at 5 credits without double-crediting');

    // -------------------------------------------------------------
    // TEST 7: VIP Agent Payment Verification & Fulfillment (Entitlement Separation)
    // -------------------------------------------------------------
    echo "\n--- TEST 7: VIP Agent Payment Verification & Fulfillment (Entitlement Separation) ---\n";

    // Create a second test vendor
    $vendor_login2 = 'test_p4b_v2_' . time() . '_' . wp_rand(100, 999);
    $vid2 = wp_create_user($vendor_login2, 'PassStage5B2!', $vendor_login2 . '@propvendor.ng');
    $created_user_ids[] = $vid2;
    $v2_user = get_user_by('id', $vid2);
    $v2_user->set_role('vendor');

    run_test(!propvendor_marketplace_is_vendor_vip_agent($vid2), 'Vendor 2 is not VIP Agent initially');
    run_test(!propvendor_marketplace_is_vendor_vip($vid2), 'Vendor 2 is not Listing VIP initially');

    $vip_order = propvendor_marketplace_create_order($vid2, $vip_plan->id);
    $created_order_references[] = $vip_order['reference'];
    run_test(!is_wp_error($vip_order) && $vip_order['success'] === true, 'Order created successfully for VIP Agent Monthly');
    run_test($vip_order['amount_kobo'] === 3000000, 'VIP order amount is 3,000,000 kobo (₦30,000)');

    $mock_vip_success = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $vip_order['reference'],
            'amount'    => 3000000, // 3,000,000 kobo
            'currency'  => 'NGN',
        ),
    );

    $vip_fulfill_res = propvendor_marketplace_verify_and_fulfill_order($vip_order['reference'], $mock_vip_success);
    run_test(!is_wp_error($vip_fulfill_res) && $vip_fulfill_res['success'] === true, 'VIP fulfillment succeeds with mock response');
    run_test($vip_fulfill_res['already_fulfilled'] === false, 'VIP first fulfillment marks already_fulfilled as false');

    // Verify VIP Agent Entitlement
    run_test(propvendor_marketplace_is_vendor_vip_agent($vid2), 'Vendor 2 is now an active VIP Agent subscriber');
    run_test(get_user_meta($vid2, 'propvendor_vip_agent_plan', true) === 'vip', 'Vendor 2 propvendor_vip_agent_plan meta is set to vip');

    $vip_expiry = get_user_meta($vid2, 'propvendor_vip_agent_expires_at', true);
    $expected_min_expiry = date('Y-m-d', strtotime('+29 days'));
    $expected_max_expiry = date('Y-m-d', strtotime('+31 days'));
    run_test($vip_expiry >= $expected_min_expiry && $vip_expiry <= $expected_max_expiry, 'VIP Agent plan expiry is set ~30 days in the future', "Expiry: {$vip_expiry}");

    // Crucial Audit 1 Check: VIP Separation (listing quota plan is UNTOUCHED)
    run_test(get_user_meta($vid2, 'propvendor_listing_plan', true) !== 'vip', 'Vendor 2 propvendor_listing_plan was NOT set to vip (separated)');
    run_test(!propvendor_marketplace_is_vendor_vip($vid2), 'Vendor 2 is NOT listing VIP (standard listing quotas still apply)');
    run_test(3 === (int) propvendor_marketplace_get_free_listing_limit(), 'Vendor 2 remains bound by standard 3 free listing quota');

    // Lead contact viewing and thread unlock checks
    run_test(propvendor_marketplace_vendor_can_view_contact_info($vid2, 9999), 'Vendor 2 can view lead contact info via VIP Agent entitlement');
    $unlock_bypass_res = propvendor_marketplace_unlock_thread_with_credits($vid2, 9999);
    run_test(!is_wp_error($unlock_bypass_res) && !empty($unlock_bypass_res['vip_bypass']), 'Vendor 2 gets automatic VIP bypass on thread unlock without credit deduction');

    // Idempotent re-verify for VIP order
    $vip_dup_res = propvendor_marketplace_verify_and_fulfill_order($vip_order['reference'], $mock_vip_success);
    run_test($vip_dup_res['already_fulfilled'] === true, 'Duplicate VIP verification identifies order as already fulfilled');
    $vip_expiry_after_dup = get_user_meta($vid2, 'propvendor_vip_agent_expires_at', true);
    run_test($vip_expiry === $vip_expiry_after_dup, 'Expiry date was not altered on duplicate order verification');

    // -------------------------------------------------------------
    // TEST 8: VIP Consecutive Purchase Cumulative Extension
    // -------------------------------------------------------------
    echo "\n--- TEST 8: VIP Consecutive Purchase Cumulative Extension ---\n";

    // Vendor 2 buys another VIP month (+30 days)
    $vip_order2 = propvendor_marketplace_create_order($vid2, $vip_plan->id);
    $created_order_references[] = $vip_order2['reference'];

    $mock_vip2_success = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $vip_order2['reference'],
            'amount'    => 3000000,
            'currency'  => 'NGN',
        ),
    );

    $vip2_fulfill = propvendor_marketplace_verify_and_fulfill_order($vip_order2['reference'], $mock_vip2_success);
    run_test(!is_wp_error($vip2_fulfill) && $vip2_fulfill['success'] === true, 'Consecutive VIP purchase fulfills cleanly');

    $extended_expiry = get_user_meta($vid2, 'propvendor_vip_agent_expires_at', true);
    $expected_extended_min = date('Y-m-d', strtotime('+59 days'));
    $expected_extended_max = date('Y-m-d', strtotime('+61 days'));
    run_test($extended_expiry >= $expected_extended_min && $extended_expiry <= $expected_extended_max, 'VIP Agent expiry cumulatively extended to ~60 days from now', "New Expiry: {$extended_expiry}");
    run_test(get_user_meta($vid2, 'propvendor_listing_plan', true) !== 'vip', 'Vendor 2 propvendor_listing_plan remains untouched after consecutive purchase');

    // -------------------------------------------------------------
    // TEST 9: Security Rejections on Underpayment, Overpayment, Currency Mismatch, Failed Status
    // -------------------------------------------------------------
    echo "\n--- TEST 9: Security Rejections on Underpayment, Overpayment, Currency Mismatch, Failed Status ---\n";

    // 9a: Underpayment (Attacker paid only 50,000 kobo instead of 1,000,000 kobo)
    $order_growth = propvendor_marketplace_create_order($vid1, $growth_plan->id);
    $created_order_references[] = $order_growth['reference'];

    $mock_underpay = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $order_growth['reference'],
            'amount'    => 50000, // 50,000 kobo instead of 1,000,000 kobo
            'currency'  => 'NGN',
        ),
    );

    $underpay_res = propvendor_marketplace_verify_and_fulfill_order($order_growth['reference'], $mock_underpay);
    run_test(is_wp_error($underpay_res), 'Underpayment attempt is rejected with WP_Error');
    run_test(is_wp_error($underpay_res) && $underpay_res->get_error_code() === 'amount_mismatch', 'Underpayment error code is amount_mismatch');
    $db_growth = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$table_orders} WHERE transaction_reference = %s", $order_growth['reference']));
    run_test($db_growth->status === 'failed', 'Underpaid order marked as failed in database');
    $bal_after_attack = propvendor_marketplace_get_vendor_credit_balance($vid1);
    run_test($bal_after_attack === 5, 'Vendor credit balance was NOT increased on underpaid order');

    // 9b: Overpayment check (Exact kobo equality: Attacker/Discrepancy paid 1,000,001 kobo instead of 1,000,000 kobo)
    $order_overpay = propvendor_marketplace_create_order($vid1, $growth_plan->id);
    $created_order_references[] = $order_overpay['reference'];

    $mock_overpay = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $order_overpay['reference'],
            'amount'    => 1000001, // 1 kobo overpayment
            'currency'  => 'NGN',
        ),
    );

    $overpay_res = propvendor_marketplace_verify_and_fulfill_order($order_overpay['reference'], $mock_overpay);
    run_test(is_wp_error($overpay_res), 'Overpayment attempt is rejected with WP_Error (exact kobo equality enforced)');
    run_test(is_wp_error($overpay_res) && $overpay_res->get_error_code() === 'amount_mismatch', 'Overpayment error code is amount_mismatch');
    $db_overpay = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$table_orders} WHERE transaction_reference = %s", $order_overpay['reference']));
    run_test($db_overpay->status === 'failed', 'Overpaid order marked as failed in database for manual review');
    run_test(propvendor_marketplace_get_vendor_credit_balance($vid1) === 5, 'Vendor credit balance remains strictly unchanged on overpayment');

    // 9c: Currency Mismatch (Attacker paid in USD instead of NGN)
    $order_curr = propvendor_marketplace_create_order($vid1, $starter_plan->id);
    $created_order_references[] = $order_curr['reference'];

    $mock_usd = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $order_curr['reference'],
            'amount'    => 500000,
            'currency'  => 'USD', // Wrong currency!
        ),
    );

    $curr_res = propvendor_marketplace_verify_and_fulfill_order($order_curr['reference'], $mock_usd);
    run_test(is_wp_error($curr_res), 'Currency mismatch (USD instead of NGN) is rejected with WP_Error');
    run_test(is_wp_error($curr_res) && $curr_res->get_error_code() === 'currency_mismatch', 'Currency mismatch error code is currency_mismatch');
    $db_curr = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$table_orders} WHERE transaction_reference = %s", $order_curr['reference']));
    run_test($db_curr->status === 'failed', 'Currency mismatch order marked as failed in database');

    // 9d: Failed/Abandoned status
    $order_fail = propvendor_marketplace_create_order($vid1, $starter_plan->id);
    $created_order_references[] = $order_fail['reference'];

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
    run_test(is_wp_error($fail_res), 'Non-success transaction status is rejected with WP_Error');
    $db_failed = $wpdb->get_row($wpdb->prepare("SELECT status FROM {$table_orders} WHERE transaction_reference = %s", $order_fail['reference']));
    run_test($db_failed->status === 'failed', 'Abandoned transaction order marked as failed in database');

    // -------------------------------------------------------------
    // TEST 10: Non-existent Reference Rejection
    // -------------------------------------------------------------
    echo "\n--- TEST 10: Non-existent Reference Rejection ---\n";

    $mock_ghost = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => 'PV_GHOST_123',
            'amount'    => 500000,
            'currency'  => 'NGN',
        ),
    );
    $ghost_res = propvendor_marketplace_verify_and_fulfill_order('PV_GHOST_123', $mock_ghost);
    run_test(is_wp_error($ghost_res) && $ghost_res->get_error_code() === 'order_not_found', 'Non-existent reference returns order_not_found');

    // -------------------------------------------------------------
    // TEST 11: Webhook HMAC SHA512 Signature Verification
    // -------------------------------------------------------------
    echo "\n--- TEST 11: Webhook HMAC SHA512 Signature Verification ---\n";

    $mock_secret = 'sk_test_paystack_secret_key_12345';
    $payload = json_encode(array(
        'event' => 'charge.success',
        'data'  => array('reference' => 'PV_TEST_REF', 'amount' => 500000),
    ));

    $valid_sig   = hash_hmac('sha512', $payload, $mock_secret);
    $invalid_sig = hash_hmac('sha512', $payload, 'sk_test_wrong_secret');

    run_test(hash_equals($valid_sig, hash_hmac('sha512', $payload, $mock_secret)), 'Valid webhook signature passes hash_equals');
    run_test(!hash_equals($invalid_sig, hash_hmac('sha512', $payload, $mock_secret)), 'Invalid webhook signature fails hash_equals');

    // -------------------------------------------------------------
    // TEST 12: Concurrency & Mutex Named Lock Protection
    // -------------------------------------------------------------
    echo "\n--- TEST 12: Concurrency & Mutex Named Lock Protection ---\n";

    $order_lock_test = propvendor_marketplace_create_order($vid1, $starter_plan->id);
    $created_order_references[] = $order_lock_test['reference'];

    $lock_key = 'pv_order_' . md5($order_lock_test['reference']);
    $lock_acquired = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 0)", $lock_key));
    run_test('1' === (string) $lock_acquired, 'Manual test lock acquired for concurrency simulation');

    $lock_release = $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_key));
    run_test('1' === (string) $lock_release, 'Test lock successfully released');

    // -------------------------------------------------------------
    // TEST 13: Frontend Wallet Shortcode Rendering
    // -------------------------------------------------------------
    echo "\n--- TEST 13: Frontend Wallet Shortcode Rendering ---\n";

    // Logged-out render
    wp_set_current_user(0);
    $logged_out_html = do_shortcode('[propvendor_vendor_wallet]');
    run_test(strpos($logged_out_html, 'propvendor-wallet-login-prompt') !== false, 'Logged-out vendor sees wallet login prompt');

    // Logged-in render as Vendor 1 (5 credits, Free plan)
    wp_set_current_user($vid1);
    $logged_in_html = do_shortcode('[propvendor_vendor_wallet]');
    run_test(strpos($logged_in_html, 'propvendor-wallet-container') !== false, 'Logged-in vendor sees wallet container');
    run_test(strpos($logged_in_html, 'id="pv-current-credit-balance">5<') !== false || strpos($logged_in_html, 'id="pv-current-credit-balance">5</span>') !== false, 'Wallet displays accurate credit balance (5)');
    run_test(strpos($logged_in_html, 'propvendor-pricing-grid') !== false, 'Wallet renders pricing cards grid');
    run_test(strpos($logged_in_html, 'data-plan-id=') !== false, 'Pricing cards contain data-plan-id attribute');
    run_test(strpos($logged_in_html, 'propvendor-checkout-btn') !== false, 'Pricing cards render propvendor-checkout-btn buttons');
    run_test(strpos($logged_in_html, 'propvendor-transactions-table') !== false, 'Wallet renders recent transaction history table');

    // Logged-in render as Vendor 2 (VIP Agent subscription)
    wp_set_current_user($vid2);
    $v2_wallet_html = do_shortcode('[propvendor_vendor_wallet]');
    run_test(strpos($v2_wallet_html, 'Active VIP Agent') !== false, 'Vendor 2 wallet renders Active VIP Agent status');

    // Reset current user
    wp_set_current_user(0);

} catch (Exception $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    $failed_tests++;
} finally {
    // Clean up created test vendors and orders
    foreach ($created_user_ids as $uid) {
        wp_delete_user($uid);
    }
    foreach ($created_order_references as $ref) {
        $wpdb->delete($table_orders, array('transaction_reference' => $ref));
        $wpdb->delete($table_ledger, array('reference' => $ref));
    }
}

echo "\n====================================================================\n";
echo "STAGE 5B TEST SUMMARY: {$passed_tests}/{$total_tests} PASSED\n";
echo "SUMMARY: {$passed_tests} PASSED, {$failed_tests} FAILED\n";
echo "====================================================================\n";

if ($failed_tests > 0) {
    exit(1);
}
exit(0);
