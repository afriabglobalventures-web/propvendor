<?php
/**
 * Test Suite: PropVendor Phase 7A — Production Security & Reliability Audit
 *
 * Verifies all required security hardening and resilience domains:
 * 1. Domain 1: Authentication & Authorization (IDOR, CSRF, Unauthenticated Access)
 * 2. Domain 2: Data Privacy & Information Leakage Prevention (Lead Contact Masking)
 * 3. Domain 3: Payment Fulfillment & Idempotency Edge Cases (Paystack Webhook & Orders)
 * 4. Domain 4: Input Validation, Output Sanitization & File Upload Hardening
 * 5. Domain 5: Concurrency, Row Locking & Credit Ledger Integrity
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
echo "  PROPVENDOR PHASE 7A — PRODUCTION SECURITY & RELIABILITY AUDIT     \n";
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
$created_user_ids     = array();
$created_post_ids     = array();
$created_thread_ids   = array();
$created_order_refs   = array();
$temp_test_files      = array();

$table_orders   = $wpdb->prefix . 'propvendor_orders';
$table_plans    = $wpdb->prefix . 'propvendor_plans';
$table_threads  = $wpdb->prefix . 'propvendor_threads';
$table_messages = $wpdb->prefix . 'propvendor_messages';
$table_ledger   = $wpdb->prefix . 'propvendor_credit_ledger';

// Helper to create valid 1x1 test PNG
function pv_create_test_png($filename) {
    global $temp_test_files;
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;
    // 1x1 transparent PNG binary
    $png_bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    file_put_contents($path, $png_bytes);
    $temp_test_files[] = $path;
    return $path;
}

// Helper to create test dummy text file
function pv_create_test_file($filename, $content = 'test') {
    global $temp_test_files;
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;
    file_put_contents($path, $content);
    $temp_test_files[] = $path;
    return $path;
}

try {
    // -------------------------------------------------------------
    // SETUP: Test Fixtures
    // -------------------------------------------------------------
    propvendor_marketplace_create_payment_tables();
    propvendor_marketplace_create_credit_ledger_table();
    propvendor_marketplace_create_enquiries_table();

    // Create Vendor A
    $u1 = 'sec_vendor_a_' . time() . '_' . wp_rand(100, 999);
    $vendor_a_id = wp_create_user($u1, 'Password123!', $u1 . '@example.com');
    $created_user_ids[] = $vendor_a_id;
    $user_a = new WP_User($vendor_a_id);
    $user_a->set_role('vendor');
    $user_a->add_cap('propvendor_submit_marketplace_listings');
    update_user_meta($vendor_a_id, 'propvendor_vendor_plan', 'free');

    // Create Vendor B
    $u2 = 'sec_vendor_b_' . time() . '_' . wp_rand(100, 999);
    $vendor_b_id = wp_create_user($u2, 'Password123!', $u2 . '@example.com');
    $created_user_ids[] = $vendor_b_id;
    $user_b = new WP_User($vendor_b_id);
    $user_b->set_role('vendor');
    $user_b->add_cap('propvendor_submit_marketplace_listings');
    update_user_meta($vendor_b_id, 'propvendor_vendor_plan', 'free');

    // Create a listing for Vendor A
    $listing_a_id = wp_insert_post(array(
        'post_title'   => 'Vendor A Secure Property',
        'post_content' => 'Description of property owned by Vendor A.',
        'post_status'  => 'publish',
        'post_author'  => $vendor_a_id,
        'post_type'    => 'property_listing',
    ));
    $created_post_ids[] = $listing_a_id;
    update_post_meta($listing_a_id, 'propvendor_price', 45000000);
    update_post_meta($listing_a_id, 'propvendor_location', 'Lagos');

    // Create an enquiry thread on Vendor A's listing
    $wpdb->insert(
        $table_threads,
        array(
            'listing_id'        => $listing_a_id,
            'vendor_id'         => $vendor_a_id,
            'customer_name'     => 'Chinedu Okeke',
            'customer_email'    => 'chinedu.okeke@example.com',
            'customer_user_id'  => 0,
            'status'            => 'open',
            'last_message_at'   => current_time('mysql'),
            'created_at'        => current_time('mysql'),
        ),
        array('%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s')
    );
    $thread_a_id = (int) $wpdb->insert_id;
    $created_thread_ids[] = $thread_a_id;

    // Create matching enquiry record so phone lookup works
    $table_enquiries = $wpdb->prefix . 'propvendor_enquiries';
    $wpdb->insert(
        $table_enquiries,
        array(
            'listing_id'     => $listing_a_id,
            'customer_name'  => 'Chinedu Okeke',
            'customer_email' => 'chinedu.okeke@example.com',
            'customer_phone' => '08023456789',
            'message'        => 'Initial enquiry message.',
            'created_at'     => current_time('mysql'),
        ),
        array('%d', '%s', '%s', '%s', '%s', '%s')
    );

    // Insert an initial message from customer
    $wpdb->insert(
        $table_messages,
        array(
            'thread_id'   => $thread_a_id,
            'sender_id'   => 0,
            'sender_role' => 'customer',
            'message'     => 'Hello, I want to inspect this property. Call me on 08023456789 or chinedu.okeke@example.com.',
            'is_read'     => 0,
            'created_at'  => current_time('mysql'),
        ),
        array('%d', '%d', '%s', '%s', '%d', '%s')
    );


    // =============================================================
    // DOMAIN 1: AUTHENTICATION & AUTHORIZATION (IDOR & CSRF)
    // =============================================================
    echo "\n--- DOMAIN 1: Authentication & Authorization (IDOR & CSRF) ---\n";

    // 1.1 Unauthenticated REST /verify-payment rejected
    wp_set_current_user(0);
    $rest_req = new WP_REST_Request('POST', '/propvendor/v1/verify-payment');
    $rest_req->set_header('content-type', 'application/json');
    $rest_req->set_body(json_encode(array('reference' => 'UNAUTH_TEST_REF')));
    $rest_res = rest_do_request($rest_req);
    run_test(
        $rest_res->get_status() === 401,
        '1.1 Unauthenticated call to REST /verify-payment returns HTTP 401',
        'Status: ' . $rest_res->get_status()
    );

    // 1.2 Unauthenticated listing update rejected
    $update_die_caught = false;
    $update_die_code = 0;
    add_filter('wp_die_handler', function() {
        return function($message, $title, $args) {
            throw new Exception((string) $message, isset($args['response']) ? (int) $args['response'] : 500);
        };
    });
    try {
        propvendor_marketplace_handle_listing_update();
    } catch (Exception $e) {
        $update_die_caught = true;
        $update_die_code = $e->getCode();
    }
    run_test(
        $update_die_caught && 401 === $update_die_code,
        '1.2 Unauthenticated admin_post_propvendor_update_listing dies with HTTP 401',
        "Caught: " . ($update_die_caught ? 'yes' : 'no') . ", Code: {$update_die_code}"
    );

    // 1.3 nopriv hook for update_listing is NOT registered
    run_test(
        !has_action('admin_post_nopriv_propvendor_update_listing', 'propvendor_marketplace_handle_listing_update'),
        '1.3 admin_post_nopriv_propvendor_update_listing hook is completely unregistered'
    );

    // 1.4 IDOR: Order Verification Cross-Tenant Protection
    // Create an order owned by Vendor A
    $ref_a = 'PV_ORD_IDOR_' . time() . '_' . wp_rand(100, 999);
    $created_order_refs[] = $ref_a;
    $plan_starter = $wpdb->get_row("SELECT * FROM {$table_plans} WHERE plan_key = 'credit_pack_starter' LIMIT 1");
    $plan_id = $plan_starter ? (int) $plan_starter->id : 2;
    $plan_amount = $plan_starter ? (int) $plan_starter->price_kobo : 500000;
    $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $vendor_a_id,
            'plan_id'               => $plan_id,
            'amount_kobo'           => $plan_amount,
            'transaction_reference' => $ref_a,
            'gateway'               => 'paystack',
            'status'                => 'pending',
            'created_at'            => current_time('mysql'),
            'updated_at'            => current_time('mysql'),
        ),
        array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
    );

    // Vendor B attempts to verify Vendor A's order via REST
    wp_set_current_user($vendor_b_id);
    $rest_idor_req = new WP_REST_Request('POST', '/propvendor/v1/verify-payment');
    $rest_idor_req->set_header('content-type', 'application/json');
    $rest_idor_req->set_body(json_encode(array('reference' => $ref_a)));
    $rest_idor_res = rest_do_request($rest_idor_req);
    run_test(
        $rest_idor_res->get_status() === 403,
        '1.4 IDOR: Vendor B verifying Vendor A order via REST returns HTTP 403 Forbidden',
        'Status: ' . $rest_idor_res->get_status()
    );

    // 1.5 IDOR: Thread Lead Unlock Cross-Tenant Protection
    // Vendor B attempts to unlock Vendor A's thread
    $unlock_idor = propvendor_marketplace_unlock_thread_with_credits($vendor_b_id, $thread_a_id);
    run_test(
        is_wp_error($unlock_idor) && $unlock_idor->get_error_code() === 'unauthorized_thread',
        '1.5 IDOR: Vendor B unlocking Vendor A thread rejected with unauthorized_thread error'
    );

    // 1.6 IDOR: Thread Access Function Protection
    $can_access = propvendor_marketplace_user_can_access_thread($thread_a_id, $vendor_b_id);
    run_test(
        !$can_access,
        '1.6 IDOR: propvendor_marketplace_user_can_access_thread returns false for unauthorized vendor'
    );

    // 1.7 IDOR: Listing Modification Protection
    // Vendor B attempts to update Vendor A's listing
    $edit_data = array(
        'propvendor_listing_type' => 'property_listing',
        'propvendor_title'        => 'Hacked Title By Vendor B',
        'propvendor_price'        => 100,
        'propvendor_location'     => 'Lagos',
        'propvendor_description'  => 'Malicious overwrite attempt',
    );
    $edit_res = propvendor_marketplace_process_listing_submission($edit_data, array(), $vendor_b_id, $listing_a_id);
    run_test(
        is_wp_error($edit_res) && $edit_res->get_error_code() === 'not_owner',
        '1.7 IDOR: Vendor B updating Vendor A listing rejected with not_owner error'
    );


    // =============================================================
    // DOMAIN 2: DATA PRIVACY & INFORMATION LEAKAGE PREVENTION
    // =============================================================
    echo "\n--- DOMAIN 2: Data Privacy & Information Leakage Prevention ---\n";

    // 2.1 Locked Lead Thread: Vendor cannot view unmasked contact
    wp_set_current_user($vendor_a_id);
    $can_view = propvendor_marketplace_vendor_can_view_contact_info($vendor_a_id, $thread_a_id);
    run_test(
        !$can_view,
        '2.1 Free vendor with 0 unlocks cannot view unmasked lead contact info'
    );

    // 2.2 Render Vendor Inbox for locked lead thread
    $_GET['view'] = 'inbox';
    $_GET['thread_id'] = $thread_a_id;
    $inbox_html = propvendor_marketplace_render_vendor_inbox($vendor_a_id);

    // Raw email must NOT be present
    run_test(
        strpos($inbox_html, 'chinedu.okeke@example.com') === false,
        '2.2 Unmasked customer email (chinedu.okeke@example.com) is NOT rendered in DOM'
    );

    // Raw phone must NOT be present
    run_test(
        strpos($inbox_html, '08023456789') === false,
        '2.3 Unmasked customer phone (08023456789) is NOT rendered in DOM'
    );

    // Masked email must be present
    $masked_email = propvendor_marketplace_mask_email('chinedu.okeke@example.com');
    run_test(
        strpos($inbox_html, $masked_email) !== false,
        "2.4 Masked customer email ({$masked_email}) is safely displayed in DOM"
    );

    // Masked phone must be present
    $masked_phone = propvendor_marketplace_mask_phone('08023456789');
    run_test(
        strpos($inbox_html, $masked_phone) !== false,
        "2.5 Masked customer phone ({$masked_phone}) is safely displayed in DOM"
    );

    // 2.6 Freeform message text anti-leakage
    run_test(
        strpos($inbox_html, '[Protected Phone]') !== false,
        '2.6 Phone number in message stream is replaced with [Protected Phone]'
    );
    run_test(
        strpos($inbox_html, '[Protected Email]') !== false,
        '2.7 Email address in message stream is replaced with [Protected Email]'
    );

    // 2.8 No raw data attribute leaks
    run_test(
        strpos($inbox_html, 'data-raw-phone') === false && strpos($inbox_html, 'data-raw-email') === false,
        '2.8 No hidden raw phone or email attributes leaked in DOM data-* attributes'
    );


    // =============================================================
    // DOMAIN 3: PAYMENT FULFILLMENT & IDEMPOTENCY
    // =============================================================
    echo "\n--- DOMAIN 3: Payment Fulfillment & Idempotency Edge Cases ---\n";

    // Set test secret key for webhook verification
    $mock_secret = 'sk_test_audit_sec_0123456789abcdef';
    update_option('propvendor_paystack_mode', 'test');
    update_option('propvendor_paystack_test_secret_key', $mock_secret);
    update_option('propvendor_paystack_test_public_key', 'pk_test_audit_pub_0123456789abcdef');

    // 3.1 Webhook signature verification: valid HMAC SHA512 passes
    $webhook_payload = json_encode(array(
        'event' => 'ping',
        'data'  => array('message' => 'Webhook test ping'),
    ));
    $valid_sig = hash_hmac('sha512', $webhook_payload, $mock_secret);
    $req_wh_valid = new WP_REST_Request('POST', '/propvendor/v1/paystack-webhook');
    $req_wh_valid->set_header('x-paystack-signature', $valid_sig);
    $req_wh_valid->set_header('content-type', 'application/json');
    $req_wh_valid->set_body($webhook_payload);
    $res_wh_valid = rest_do_request($req_wh_valid);
    run_test(
        $res_wh_valid->get_status() === 200 && isset($res_wh_valid->get_data()['message']) && $res_wh_valid->get_data()['message'] === 'Event acknowledged.',
        '3.1 Webhook with valid HMAC SHA512 signature passes authentication'
    );

    // 3.2 Webhook signature verification: invalid signature returns HTTP 401
    $req_wh_invalid = new WP_REST_Request('POST', '/propvendor/v1/paystack-webhook');
    $req_wh_invalid->set_header('x-paystack-signature', 'forged_fake_signature_abc123');
    $req_wh_invalid->set_header('content-type', 'application/json');
    $req_wh_invalid->set_body($webhook_payload);
    $res_wh_invalid = rest_do_request($req_wh_invalid);
    run_test(
        $res_wh_invalid->get_status() === 401,
        '3.2 Webhook with invalid signature fails with HTTP 401 Unauthorized',
        'Status: ' . $res_wh_invalid->get_status()
    );

    // 3.3 Underpayment defense: lower amount received than order amount
    $ref_underpay = 'PV_ORD_UNDERPAY_' . time() . '_' . wp_rand(100, 999);
    $created_order_refs[] = $ref_underpay;
    $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $vendor_a_id,
            'plan_id'               => $plan_id,
            'amount_kobo'           => $plan_amount,
            'transaction_reference' => $ref_underpay,
            'gateway'               => 'paystack',
            'status'                => 'pending',
            'created_at'            => current_time('mysql'),
            'updated_at'            => current_time('mysql'),
        ),
        array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
    );

    // Attacker only paid 1,000 kobo (10 NGN)
    $mock_underpay_api = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $ref_underpay,
            'amount'    => 1000,
            'currency'  => 'NGN',
        ),
    );
    $underpay_result = propvendor_marketplace_verify_and_fulfill_order($ref_underpay, $mock_underpay_api);
    run_test(
        is_wp_error($underpay_result) && $underpay_result->get_error_code() === 'amount_mismatch',
        '3.3 Underpayment attack rejected with amount_mismatch error'
    );

    // Order status must be 'failed' in database
    $underpay_status = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$table_orders} WHERE transaction_reference = %s", $ref_underpay));
    run_test(
        $underpay_status === 'failed',
        '3.4 Order status transitioned to "failed" on underpayment attempt'
    );

    // 3.5 Currency mismatch defense
    $ref_cur = 'PV_ORD_CUR_' . time() . '_' . wp_rand(100, 999);
    $created_order_refs[] = $ref_cur;
    $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $vendor_a_id,
            'plan_id'               => $plan_id,
            'amount_kobo'           => $plan_amount,
            'transaction_reference' => $ref_cur,
            'gateway'               => 'paystack',
            'status'                => 'pending',
            'created_at'            => current_time('mysql'),
            'updated_at'            => current_time('mysql'),
        ),
        array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
    );
    $mock_usd_api = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $ref_cur,
            'amount'    => $plan_amount,
            'currency'  => 'USD', // Not NGN
        ),
    );
    $cur_result = propvendor_marketplace_verify_and_fulfill_order($ref_cur, $mock_usd_api);
    run_test(
        is_wp_error($cur_result) && $cur_result->get_error_code() === 'currency_mismatch',
        '3.5 Non-NGN currency payment rejected with currency_mismatch error'
    );

    // 3.6 Fulfillment Idempotency
    $ref_idemp = 'PV_ORD_IDEMP_' . time() . '_' . wp_rand(100, 999);
    $created_order_refs[] = $ref_idemp;
    $wpdb->insert(
        $table_orders,
        array(
            'vendor_id'             => $vendor_a_id,
            'plan_id'               => $plan_id,
            'amount_kobo'           => $plan_amount,
            'transaction_reference' => $ref_idemp,
            'gateway'               => 'paystack',
            'status'                => 'pending',
            'created_at'            => current_time('mysql'),
            'updated_at'            => current_time('mysql'),
        ),
        array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
    );
    $mock_valid_api = array(
        'status' => true,
        'data'   => array(
            'status'    => 'success',
            'reference' => $ref_idemp,
            'amount'    => $plan_amount,
            'currency'  => 'NGN',
        ),
    );

    $bal_before_fulfill = propvendor_marketplace_get_vendor_credit_balance($vendor_a_id);
    $fulfill_1 = propvendor_marketplace_verify_and_fulfill_order($ref_idemp, $mock_valid_api);
    run_test(
        !is_wp_error($fulfill_1) && $fulfill_1['success'] === true && $fulfill_1['already_fulfilled'] === false,
        '3.6 First fulfillment call succeeds and grants credits'
    );
    $bal_after_fulfill = propvendor_marketplace_get_vendor_credit_balance($vendor_a_id);
    run_test(
        $bal_after_fulfill === ($bal_before_fulfill + 5),
        "3.7 Vendor balance accurately credited (+5 credits: {$bal_before_fulfill} -> {$bal_after_fulfill})"
    );

    // Second fulfillment call with identical reference
    $fulfill_2 = propvendor_marketplace_verify_and_fulfill_order($ref_idemp, $mock_valid_api);
    run_test(
        !is_wp_error($fulfill_2) && $fulfill_2['already_fulfilled'] === true,
        '3.8 Duplicate fulfillment returns already_fulfilled = true'
    );
    $bal_after_dupe = propvendor_marketplace_get_vendor_credit_balance($vendor_a_id);
    run_test(
        $bal_after_dupe === $bal_after_fulfill,
        '3.9 Credits are NOT double-counted on duplicate fulfillment'
    );


    // =============================================================
    // DOMAIN 4: INPUT VALIDATION & FILE UPLOAD HARDENING
    // =============================================================
    echo "\n--- DOMAIN 4: Input Validation & File Upload Hardening ---\n";

    // 4.1 Dangerous extension: .php script rejected
    $php_file = pv_create_test_file('exploit.php', '<?php phpinfo(); ?>');
    $bad_file_php = array(
        'name'     => 'exploit.php',
        'type'     => 'application/x-php',
        'tmp_name' => $php_file,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($php_file),
    );
    $val_php = propvendor_marketplace_validate_secure_file_upload($bad_file_php);
    run_test(
        is_wp_error($val_php) && ($val_php->get_error_code() === 'dangerous_file_type' || $val_php->get_error_code() === 'invalid_file_type'),
        '4.1 PHP script upload (exploit.php) rejected by secure upload validator'
    );

    // 4.2 Dangerous extension: .phtml script rejected
    $phtml_file = pv_create_test_file('shell.phtml', '<?php echo 1; ?>');
    $bad_file_phtml = array(
        'name'     => 'shell.phtml',
        'type'     => 'text/html',
        'tmp_name' => $phtml_file,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($phtml_file),
    );
    $val_phtml = propvendor_marketplace_validate_secure_file_upload($bad_file_phtml);
    run_test(
        is_wp_error($val_phtml) && ($val_phtml->get_error_code() === 'dangerous_file_type' || $val_phtml->get_error_code() === 'invalid_file_type'),
        '4.2 PHTML executable upload (shell.phtml) rejected'
    );

    // 4.3 Dangerous extension: .exe executable rejected
    $exe_file = pv_create_test_file('malware.exe', 'MZ binary');
    $bad_file_exe = array(
        'name'     => 'malware.exe',
        'type'     => 'application/octet-stream',
        'tmp_name' => $exe_file,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($exe_file),
    );
    $val_exe = propvendor_marketplace_validate_secure_file_upload($bad_file_exe);
    run_test(
        is_wp_error($val_exe) && ($val_exe->get_error_code() === 'dangerous_file_type' || $val_exe->get_error_code() === 'invalid_file_type'),
        '4.3 Binary executable upload (malware.exe) rejected'
    );

    // 4.4 Dangerous extension: .svg active vector rejected
    $svg_file = pv_create_test_file('xss.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $bad_file_svg = array(
        'name'     => 'xss.svg',
        'type'     => 'image/svg+xml',
        'tmp_name' => $svg_file,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($svg_file),
    );
    $val_svg = propvendor_marketplace_validate_secure_file_upload($bad_file_svg);
    run_test(
        is_wp_error($val_svg) && ($val_svg->get_error_code() === 'dangerous_file_type' || $val_svg->get_error_code() === 'invalid_file_type'),
        '4.4 SVG active content upload (xss.svg) rejected'
    );

    // 4.5 Double extension detection: payload.php.png
    $double_ext_file = pv_create_test_file('payload.php.png', 'fake image bytes');
    $bad_file_double = array(
        'name'     => 'payload.php.png',
        'type'     => 'image/png',
        'tmp_name' => $double_ext_file,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($double_ext_file),
    );
    $val_double = propvendor_marketplace_validate_secure_file_upload($bad_file_double);
    run_test(
        is_wp_error($val_double) && ($val_double->get_error_code() === 'dangerous_file_type' || $val_double->get_error_code() === 'invalid_file_type'),
        '4.5 Double extension (payload.php.png) rejected'
    );

    // 4.6 Oversized image rejection (>4MB)
    $oversized_file = pv_create_test_png('huge.png');
    $bad_file_oversized = array(
        'name'     => 'huge.png',
        'type'     => 'image/png',
        'tmp_name' => $oversized_file,
        'error'    => UPLOAD_ERR_OK,
        'size'     => 5 * 1024 * 1024, // 5MB simulated
    );
    $val_oversized = propvendor_marketplace_validate_secure_file_upload($bad_file_oversized, array('image/jpeg', 'image/png', 'image/webp'), 4 * 1024 * 1024);
    run_test(
        is_wp_error($val_oversized) && $val_oversized->get_error_code() === 'image_too_large',
        '4.6 File exceeding 4MB limit rejected with image_too_large'
    );

    // 4.7 Fake image (corrupt / invalid content) rejection
    $fake_img_file = pv_create_test_file('fake.png', 'Plain text disguised as PNG');
    $bad_file_fake = array(
        'name'     => 'fake.png',
        'type'     => 'image/png',
        'tmp_name' => $fake_img_file,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($fake_img_file),
    );
    $val_fake = propvendor_marketplace_validate_secure_file_upload($bad_file_fake);
    run_test(
        is_wp_error($val_fake) && $val_fake->get_error_code() === 'invalid_file_type',
        '4.7 Non-image file with PNG extension rejected by image inspection'
    );

    // 4.8 Valid 1x1 PNG file accepted
    $valid_png = pv_create_test_png('valid_test.png');
    $good_file = array(
        'name'     => 'valid_test.png',
        'type'     => 'image/png',
        'tmp_name' => $valid_png,
        'error'    => UPLOAD_ERR_OK,
        'size'     => filesize($valid_png),
    );
    $val_good = propvendor_marketplace_validate_secure_file_upload($good_file);
    run_test(
        $val_good === true,
        '4.8 Valid PNG image passes all validation checks'
    );

    // 4.9 SQL Injection resilience in listing filters
    $sqli_params = array(
        'pv_keyword' => "'; DROP TABLE wp_posts; --",
        'pv_state'   => "' OR 1=1 --",
    );
    $q_res = propvendor_marketplace_build_search_query($sqli_params);
    run_test(
        $q_res instanceof WP_Query,
        '4.9 SQL injection payload in search query parameters executes safely without SQL syntax error'
    );


    // =============================================================
    // DOMAIN 5: CONCURRENCY, ROW LOCKING & LEDGER INTEGRITY
    // =============================================================
    echo "\n--- DOMAIN 5: Concurrency, Row Locking & Credit Ledger Integrity ---\n";

    // 5.1 Overdraft Prevention: Vendor with 0 credits cannot deduct
    $vid_zero = $vendor_b_id;
    $b_bal = propvendor_marketplace_get_vendor_credit_balance($vid_zero);
    run_test($b_bal === 0, '5.1 Vendor B begins with 0 credit balance');

    $overdraft_res = propvendor_marketplace_deduct_vendor_credits($vid_zero, 1, 'lead_unlock', 'test_overdraft');
    run_test(
        is_wp_error($overdraft_res) && $overdraft_res->get_error_code() === 'insufficient_credits',
        '5.2 Overdraft attempt fails closed with insufficient_credits error'
    );
    $b_bal_after = propvendor_marketplace_get_vendor_credit_balance($vid_zero);
    run_test(
        $b_bal_after === 0,
        '5.3 Credit balance remains 0 without negative balance or overdraft'
    );

    // 5.4 Lead Unlock with 1 Credit: Atomic Deduction
    wp_set_current_user($vendor_a_id);
    $a_bal_before_unlock = propvendor_marketplace_get_vendor_credit_balance($vendor_a_id);
    $unlock_res = propvendor_marketplace_unlock_thread_with_credits($vendor_a_id, $thread_a_id);
    run_test(
        !is_wp_error($unlock_res) && $unlock_res['success'] === true && $unlock_res['deducted'] === 1,
        '5.4 Lead unlock succeeds with exactly 1 credit deducted'
    );
    $a_bal_after_unlock = propvendor_marketplace_get_vendor_credit_balance($vendor_a_id);
    run_test(
        $a_bal_after_unlock === ($a_bal_before_unlock - 1),
        "5.5 Vendor balance reduced by exactly 1 credit ({$a_bal_before_unlock} -> {$a_bal_after_unlock})"
    );

    // 5.6 Idempotent Unlock: Unlocking the same thread again costs 0 credits
    $unlock_again = propvendor_marketplace_unlock_thread_with_credits($vendor_a_id, $thread_a_id);
    run_test(
        !is_wp_error($unlock_again) && $unlock_again['already_unlocked'] === true && $unlock_again['deducted'] === 0,
        '5.6 Idempotent unlock: Re-unlocking thread costs 0 credits'
    );
    $a_bal_after_dupe_unlock = propvendor_marketplace_get_vendor_credit_balance($vendor_a_id);
    run_test(
        $a_bal_after_dupe_unlock === $a_bal_after_unlock,
        '5.7 Balance unchanged after idempotent re-unlock'
    );

    // 5.8 Contact Access Granted after Unlock
    $can_view_unlocked = propvendor_marketplace_vendor_can_view_contact_info($vendor_a_id, $thread_a_id);
    run_test(
        $can_view_unlocked === true,
        '5.8 Unlocked thread grants access in propvendor_marketplace_vendor_can_view_contact_info'
    );

    // 5.9 VIP Agent Auto-Unlock Bypass
    propvendor_marketplace_grant_vip_agent_entitlement($vendor_b_id, 30, 'VIP_AUDIT_TEST');
    run_test(
        propvendor_marketplace_is_vendor_vip_agent($vendor_b_id),
        '5.9 Vendor B is now an active VIP Agent'
    );

    // Create a thread for Vendor B
    $wpdb->insert(
        $table_threads,
        array(
            'listing_id'        => $listing_a_id,
            'vendor_id'         => $vendor_b_id,
            'customer_name'     => 'Amina Bello',
            'customer_email'    => 'amina.bello@example.com',
            'customer_user_id'  => 0,
            'status'            => 'open',
            'last_message_at'   => current_time('mysql'),
            'created_at'        => current_time('mysql'),
        ),
        array('%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s')
    );
    $thread_b_id = (int) $wpdb->insert_id;
    $created_thread_ids[] = $thread_b_id;

    $vip_unlock = propvendor_marketplace_unlock_thread_with_credits($vendor_b_id, $thread_b_id);
    run_test(
        !is_wp_error($vip_unlock) && !empty($vip_unlock['vip_bypass']) && $vip_unlock['deducted'] === 0,
        '5.10 VIP vendor lead unlock executes automatic bypass with 0 credits deducted'
    );
    $vip_can_view = propvendor_marketplace_vendor_can_view_contact_info($vendor_b_id, $thread_b_id);
    run_test(
        $vip_can_view === true,
        '5.11 VIP vendor has direct contact view access without purchasing credit packs'
    );

} catch (Exception $e) {
    echo "AUDIT EXCEPTION: " . $e->getMessage() . "\n";
    $failed_tests++;
} finally {
    // Teardown test fixtures
    wp_set_current_user(0);
    unset($_GET['view'], $_GET['thread_id']);

    foreach ($created_user_ids as $uid) {
        wp_delete_user($uid);
    }
    foreach ($created_post_ids as $pid) {
        wp_delete_post($pid, true);
    }
    foreach ($created_thread_ids as $tid) {
        $wpdb->delete($table_messages, array('thread_id' => $tid));
        $wpdb->delete($table_threads, array('id' => $tid));
    }
    foreach ($created_order_refs as $ref) {
        $wpdb->delete($table_orders, array('transaction_reference' => $ref));
        $wpdb->delete($table_ledger, array('reference' => $ref));
    }
    foreach ($temp_test_files as $f) {
        if (file_exists($f)) {
            @unlink($f);
        }
    }
}

echo "\n====================================================================\n";
echo "PHASE 7A SECURITY AUDIT SUMMARY: {$passed_tests}/{$total_tests} PASSED\n";
echo "SUMMARY: {$passed_tests} PASSED, {$failed_tests} FAILED\n";
echo "====================================================================\n";

if ($failed_tests > 0) {
    exit(1);
}
exit(0);

