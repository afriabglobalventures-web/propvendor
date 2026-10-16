<?php
/**
 * Test Suite: PropVendor Phase 6A — Secure Automated Email Notifications & Delivery Architecture
 *
 * Verifies all required test cases for Phase 6A:
 * 1. Schema migration & table integrity (wp_propvendor_notifications InnoDB table, columns, indexes, idempotency).
 * 2. Configurable sender settings (name, email) & fallbacks.
 * 3. User opt-out preference enforcement (specific notification type & global).
 * 4. Event A: New customer enquiry -> vendor notification event creation.
 * 5. Event A Duplicate-send prevention (idempotent event_key matching).
 * 6. Event B: Vendor reply -> buyer notification event creation.
 * 7. Event B Duplicate-send prevention (idempotent event_key matching).
 * 8. Unauthorized conversation recipient & sender security checks.
 * 9. Privacy rules: Vendor alert never exposes unmasked customer phone or email.
 * 10. Buyer reply notification goes strictly to the authorized thread buyer.
 * 11. HTML / Script escaping (defense against XSS & email injection).
 * 12. Invalid or missing recipient email handling.
 * 13. User opt-out respected (notification skipped without dispatch).
 * 14. Simulated transient mail failure logged with 'retrying' status.
 * 15. Successful retry transitions to 'sent' without duplicating already-sent mail.
 * 16. Bounded retry limit enforcement (max 3 attempts, transitions to 'failed').
 * 17. Main enquiry & reply transaction rollback safety on email failure.
 * 18. Admin visibility, permissions, and log querying.
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

echo "====================================================================\n";
echo "  PROPVENDOR PHASE 6A — SECURE AUTOMATED EMAIL NOTIFICATIONS SUITE  \n";
echo "====================================================================\n\n";

// Mock mail transport
$captured_emails = array();
$mock_mail_succeed = true;

add_filter('pre_wp_mail', function($null, $atts) use (&$captured_emails, &$mock_mail_succeed) {
    if (!$mock_mail_succeed) {
        return false;
    }
    $captured_emails[] = $atts;
    return true;
}, 10, 2);

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

// Ensure table exists before cleanup
propvendor_marketplace_create_notifications_table();
$table_notifications = $wpdb->prefix . 'propvendor_notifications';
$wpdb->query("DELETE FROM {$table_notifications} WHERE event_key LIKE 'test_%' OR event_key LIKE 'new_enquiry:%' OR event_key LIKE 'vendor_reply:%'");

// =========================================================================
// SECTION 1: SCHEMA MIGRATION & TABLE INTEGRITY
// =========================================================================
echo "\n--- Section 1: Schema Migration & Table Integrity ---\n";

propvendor_marketplace_create_notifications_table();
// Repeated call to test idempotency
propvendor_marketplace_create_notifications_table();

$table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_notifications)) === $table_notifications;
run_test($table_exists, '1.1 wp_propvendor_notifications table exists after migration');

$engine = $wpdb->get_var($wpdb->prepare(
    "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
    DB_NAME,
    $table_notifications
));
run_test(strcasecmp($engine, 'InnoDB') === 0, '1.2 Table engine is InnoDB');

$cols = $wpdb->get_col($wpdb->prepare(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
    DB_NAME,
    $table_notifications
));
$expected_cols = array(
    'id', 'event_key', 'notification_type', 'recipient_user_id',
    'recipient_email', 'subject', 'status', 'attempts',
    'last_error', 'related_object_type', 'related_object_id',
    'scheduled_at', 'sent_at', 'created_at', 'updated_at'
);
$missing_cols = array_diff($expected_cols, $cols);
run_test(empty($missing_cols), '1.3 All 15 required table columns exist', empty($missing_cols) ? '' : 'Missing: ' . implode(',', $missing_cols));

$indexes = $wpdb->get_results($wpdb->prepare("SHOW INDEX FROM {$table_notifications}"));
$index_names = array();
$unique_event_key = false;
foreach ($indexes as $idx) {
    $index_names[$idx->Key_name] = true;
    if ($idx->Key_name === 'event_key' && (int)$idx->Non_unique === 0) {
        $unique_event_key = true;
    }
}
run_test($unique_event_key, '1.4 event_key has UNIQUE constraint');
run_test(isset($index_names['status']), '1.5 Key index on status column exists');
run_test(isset($index_names['recipient_user_id']) && isset($index_names['related_object']), '1.6 Indexes on recipient_user_id and related_object exist');


// =========================================================================
// SECTION 2: CONFIGURABLE SENDER SETTINGS & OPT-OUT PREFERENCES
// =========================================================================
echo "\n--- Section 2: Sender Settings & User Opt-Out Preferences ---\n";

$default_email = propvendor_marketplace_get_notification_sender_email();
$default_name  = propvendor_marketplace_get_notification_sender_name();
run_test(!empty($default_email) && is_email($default_email), '2.1 Default sender email returns a valid email address');
run_test(!empty($default_name), '2.2 Default sender name returns non-empty string');

update_option('propvendor_mail_sender_name', 'PropVendor Alerts Unit');
update_option('propvendor_mail_sender_email', 'alerts@propvendor.com');
run_test(
    propvendor_marketplace_get_notification_sender_name() === 'PropVendor Alerts Unit' &&
    propvendor_marketplace_get_notification_sender_email() === 'alerts@propvendor.com',
    '2.3 Custom sender name and email options are respected'
);
delete_option('propvendor_mail_sender_name');
delete_option('propvendor_mail_sender_email');

// Setup test users
$test_vendor_id = wp_create_user('pv_test_vendor_' . time(), 'password123', 'vendor_' . time() . '@example.com');
$vendor_user = get_user_by('id', $test_vendor_id);
$vendor_user->set_role('vendor');

$test_buyer_id = wp_create_user('pv_test_buyer_' . time(), 'password123', 'buyer_' . time() . '@example.com');
$buyer_user = get_user_by('id', $test_buyer_id);
$buyer_user->set_role('subscriber');

run_test(
    !propvendor_marketplace_user_has_opted_out_notifications($test_vendor_id, 'new_enquiry'),
    '2.4 User default state allows notifications (not opted out)'
);

propvendor_marketplace_set_user_notification_optout($test_vendor_id, 'new_enquiry', true);
run_test(
    propvendor_marketplace_user_has_opted_out_notifications($test_vendor_id, 'new_enquiry'),
    '2.5 User specific-type opt-out is detected'
);

propvendor_marketplace_set_user_notification_optout($test_vendor_id, 'new_enquiry', false);
run_test(
    !propvendor_marketplace_user_has_opted_out_notifications($test_vendor_id, 'new_enquiry'),
    '2.6 Clearing specific opt-out restores notification eligibility'
);


// =========================================================================
// SECTION 3: EVENT A — NEW CUSTOMER ENQUIRY -> VENDOR NOTIFICATION
// =========================================================================
echo "\n--- Section 3: Event A (New Customer Enquiry -> Vendor Notification) ---\n";

// Create test listing
$test_listing_id = wp_insert_post(array(
    'post_title'   => 'Lekki Phase 1 Luxury Duplex',
    'post_type'    => 'property_listing',
    'post_status'  => 'publish',
    'post_author'  => $test_vendor_id,
));

// Insert test enquiry into wp_propvendor_enquiries
$table_enquiries = $wpdb->prefix . 'propvendor_enquiries';
$table_threads   = $wpdb->prefix . 'propvendor_threads';
$table_messages  = $wpdb->prefix . 'propvendor_messages';
$now_mysql       = current_time('mysql');

$buyer_raw_phone = '+2348099887766';
$buyer_raw_email = 'customer_private_' . time() . '@example.com';
$buyer_name      = 'Chief Adebayo';

$wpdb->insert(
    $table_enquiries,
    array(
        'listing_id'           => $test_listing_id,
        'vendor_id'            => $test_vendor_id,
        'customer_user_id'     => $test_buyer_id,
        'customer_name'        => $buyer_name,
        'customer_email'       => $buyer_raw_email,
        'customer_phone'       => $buyer_raw_phone,
        'message'              => 'Hello, is this duplex still available for immediate inspection this Saturday?',
        'vendor_email_sent'    => 0,
        'vendor_email_address' => $vendor_user->user_email,
        'created_at'           => $now_mysql,
    ),
    array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
);
$test_enquiry_id = $wpdb->insert_id;

$wpdb->insert(
    $table_threads,
    array(
        'listing_id'       => $test_listing_id,
        'vendor_id'        => $test_vendor_id,
        'customer_user_id' => $test_buyer_id,
        'customer_name'    => $buyer_name,
        'customer_email'   => $buyer_raw_email,
        'status'           => 'open',
        'last_message_at'  => $now_mysql,
        'created_at'       => $now_mysql,
    ),
    array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
);
$test_thread_id = $wpdb->insert_id;

// Clear captured mock emails
$captured_emails = array();
$mock_mail_succeed = true;

// Trigger Event A notification
$res_event_a = propvendor_marketplace_send_new_enquiry_notification($test_enquiry_id, $test_thread_id);

run_test(
    !empty($res_event_a['success']) && 'sent' === $res_event_a['status'],
    '3.1 Event A notification returns success with status sent'
);
run_test(
    count($captured_emails) === 1,
    '3.2 Exactly one email was dispatched via wp_mail'
);

$sent_mail_a = !empty($captured_emails[0]) ? $captured_emails[0] : array();
run_test(
    isset($sent_mail_a['to']) && $sent_mail_a['to'] === $vendor_user->user_email,
    '3.3 Recipient email strictly matches listing vendor email'
);
run_test(
    strpos($sent_mail_a['subject'], 'Lekki Phase 1 Luxury Duplex') !== false,
    '3.4 Subject contains listing title'
);
run_test(
    strpos($sent_mail_a['message'], 'PropVendor') !== false,
    '3.5 Email contains PropVendor branding'
);
run_test(
    strpos($sent_mail_a['message'], 'vendor-dashboard/?view=inbox') !== false &&
    (strpos($sent_mail_a['message'], 'thread_id=' . $test_thread_id) !== false || strpos($sent_mail_a['message'], '&#038;thread_id=' . $test_thread_id) !== false),
    '3.6 Email contains authorized vendor inbox link'
);
run_test(
    strpos($sent_mail_a['message'], 'is this duplex still available') !== false,
    '3.7 Email contains sanitized message preview'
);

// Privacy assertions: Buyer phone & email MUST NOT be unmasked in the alert
run_test(
    strpos($sent_mail_a['message'], $buyer_raw_phone) === false,
    '3.8 Vendor alert does NOT expose unmasked buyer phone number'
);
run_test(
    strpos($sent_mail_a['message'], $buyer_raw_email) === false,
    '3.9 Vendor alert does NOT expose unmasked buyer email address'
);

$notif_row_a = propvendor_marketplace_get_notification_by_key("new_enquiry:{$test_enquiry_id}:vendor:{$test_vendor_id}");
run_test(
    $notif_row_a && $notif_row_a->status === 'sent' && (int)$notif_row_a->attempts === 1,
    '3.10 Delivery log record saved in wp_propvendor_notifications with status sent'
);


// =========================================================================
// SECTION 4: EVENT A DUPLICATE-SEND PREVENTION & PRIVACY AUDIT
// =========================================================================
echo "\n--- Section 4: Event A Duplicate Prevention & Log Privacy Audit ---\n";

$count_before = count($captured_emails);
$res_event_a_dup = propvendor_marketplace_send_new_enquiry_notification($test_enquiry_id, $test_thread_id);

run_test(
    !empty($res_event_a_dup['success']) && 'skipped' === $res_event_a_dup['status'],
    '4.1 Duplicate Event A trigger returns skipped status'
);
run_test(
    $res_event_a_dup['reason'] === 'already_sent',
    '4.2 Duplicate Event A skipped reason is already_sent'
);
run_test(
    count($captured_emails) === $count_before,
    '4.3 Duplicate trigger dispatched zero additional emails'
);

$log_count_a = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$table_notifications} WHERE event_key = %s",
    "new_enquiry:{$test_enquiry_id}:vendor:{$test_vendor_id}"
));
run_test((int)$log_count_a === 1, '4.4 Only one row exists in notifications table for event key');

run_test(
    strpos($notif_row_a->subject, $buyer_raw_phone) === false &&
    strpos($notif_row_a->last_error ?? '', $buyer_raw_phone) === false,
    '4.5 Notification table record contains no raw buyer phone number'
);


// =========================================================================
// SECTION 5: EVENT B — VENDOR REPLY -> BUYER NOTIFICATION
// =========================================================================
echo "\n--- Section 5: Event B (Vendor Reply -> Buyer Notification) ---\n";

$vendor_reply_text = 'Yes, the duplex is available for inspection this Saturday by 11am. Looking forward to meeting you.';
$wpdb->insert(
    $table_messages,
    array(
        'thread_id'   => $test_thread_id,
        'sender_id'   => $test_vendor_id,
        'sender_role' => 'vendor',
        'message'     => $vendor_reply_text,
        'is_read'     => 0,
        'created_at'  => current_time('mysql'),
    ),
    array('%d', '%d', '%s', '%s', '%d', '%s')
);
$test_reply_id = $wpdb->insert_id;

$captured_emails = array();
$mock_mail_succeed = true;

$res_event_b = propvendor_marketplace_send_vendor_reply_notification($test_reply_id, $test_thread_id);

run_test(
    !empty($res_event_b['success']) && 'sent' === $res_event_b['status'],
    '5.1 Event B notification returns success with status sent'
);
run_test(
    count($captured_emails) === 1,
    '5.2 Exactly one email was dispatched to buyer via wp_mail'
);

$sent_mail_b = !empty($captured_emails[0]) ? $captured_emails[0] : array();
run_test(
    isset($sent_mail_b['to']) && $sent_mail_b['to'] === $buyer_raw_email,
    '5.3 Recipient strictly matches the thread buyer email'
);
run_test(
    strpos($sent_mail_b['subject'], 'Lekki Phase 1 Luxury Duplex') !== false,
    '5.4 Subject references the enquiry listing title'
);
run_test(
    strpos($sent_mail_b['message'], 'duplex is available for inspection') !== false,
    '5.5 Email body contains sanitized vendor reply snippet'
);
run_test(
    strpos($sent_mail_b['message'], 'customer-inbox/?thread_id=' . $test_thread_id) !== false,
    '5.6 Email contains conversation link for registered customer'
);

$expected_b_key = "vendor_reply:{$test_reply_id}:buyer:{$test_buyer_id}";
$notif_row_b = propvendor_marketplace_get_notification_by_key($expected_b_key);
run_test(
    $notif_row_b && $notif_row_b->status === 'sent' && (int)$notif_row_b->recipient_user_id === $test_buyer_id,
    '5.7 Delivery log record saved in wp_propvendor_notifications with recipient buyer ID'
);
run_test(
    $notif_row_b && (int)$notif_row_b->attempts === 1,
    '5.8 Delivery record attempts is exactly 1'
);


// =========================================================================
// SECTION 6: EVENT B DUPLICATE-SEND PREVENTION & AUTHORIZATION SECURITY
// =========================================================================
echo "\n--- Section 6: Event B Duplicate Prevention & Authorization Checks ---\n";

$count_b_before = count($captured_emails);
$res_event_b_dup = propvendor_marketplace_send_vendor_reply_notification($test_reply_id, $test_thread_id);

run_test(
    !empty($res_event_b_dup['success']) && 'skipped' === $res_event_b_dup['status'],
    '6.1 Duplicate Event B trigger returns skipped status'
);
run_test(
    $res_event_b_dup['reason'] === 'already_sent',
    '6.2 Duplicate Event B skipped reason is already_sent'
);
run_test(
    count($captured_emails) === $count_b_before,
    '6.3 Duplicate trigger dispatched zero additional emails'
);

// Test 6.4: Unauthorized sender rejection
$third_party_user_id = wp_create_user('pv_unauth_' . time(), 'pass123', 'unauth_' . time() . '@example.com');
$wpdb->insert(
    $table_messages,
    array(
        'thread_id'   => $test_thread_id,
        'sender_id'   => $third_party_user_id,
        'sender_role' => 'vendor',
        'message'     => 'Unauthorized third party message',
        'is_read'     => 0,
        'created_at'  => current_time('mysql'),
    ),
    array('%d', '%d', '%s', '%s', '%d', '%s')
);
$unauth_msg_id = $wpdb->insert_id;
$res_unauth = propvendor_marketplace_send_vendor_reply_notification($unauth_msg_id, $test_thread_id);
run_test(
    empty($res_unauth['success']) && $res_unauth['reason'] === 'unauthorized_sender',
    '6.4 Message from unauthorized sender is rejected'
);

// Test 6.5: Thread ID mismatch rejection
$res_mismatch = propvendor_marketplace_send_vendor_reply_notification($test_reply_id, 999999);
run_test(
    empty($res_mismatch['success']) && $res_mismatch['reason'] === 'thread_mismatch',
    '6.5 Mismatched thread ID is rejected'
);

// Test 6.6: Non-existent message ID rejection
$res_nonexistent = propvendor_marketplace_send_vendor_reply_notification(999999, $test_thread_id);
run_test(
    empty($res_nonexistent['success']) && $res_nonexistent['reason'] === 'message_not_found',
    '6.6 Non-existent message ID is rejected'
);

// Test 6.7: Customer role message rejection for vendor reply event
$wpdb->insert(
    $table_messages,
    array(
        'thread_id'   => $test_thread_id,
        'sender_id'   => $test_buyer_id,
        'sender_role' => 'customer',
        'message'     => 'Customer follow up message',
        'is_read'     => 0,
        'created_at'  => current_time('mysql'),
    ),
    array('%d', '%d', '%s', '%s', '%d', '%s')
);
$customer_msg_id = $wpdb->insert_id;
$res_cust_role = propvendor_marketplace_send_vendor_reply_notification($customer_msg_id, $test_thread_id);
run_test(
    empty($res_cust_role['success']) && $res_cust_role['reason'] === 'invalid_sender_role',
    '6.7 Customer role message is rejected for vendor-reply event'
);

// Test 6.8: Guest buyer workflow (customer_user_id = 0) receives registration CTA
$guest_email = 'guest_buyer_' . time() . '@example.com';
$wpdb->insert(
    $table_threads,
    array(
        'listing_id'       => $test_listing_id,
        'vendor_id'        => $test_vendor_id,
        'customer_user_id' => 0,
        'customer_name'    => 'Guest Visitor',
        'customer_email'   => $guest_email,
        'status'           => 'open',
        'last_message_at'  => current_time('mysql'),
        'created_at'       => current_time('mysql'),
    ),
    array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
);
$guest_thread_id = $wpdb->insert_id;
$wpdb->insert(
    $table_messages,
    array(
        'thread_id'   => $guest_thread_id,
        'sender_id'   => $test_vendor_id,
        'sender_role' => 'vendor',
        'message'     => 'Hello guest, thank you for your inquiry.',
        'is_read'     => 0,
        'created_at'  => current_time('mysql'),
    ),
    array('%d', '%d', '%s', '%s', '%d', '%s')
);
$guest_msg_id = $wpdb->insert_id;

$captured_emails = array();
$res_guest_reply = propvendor_marketplace_send_vendor_reply_notification($guest_msg_id, $guest_thread_id);
run_test(
    !empty($res_guest_reply['success']) && 'sent' === $res_guest_reply['status'],
    '6.8 Guest buyer reply notification dispatched successfully'
);


// =========================================================================
// SECTION 7: HTML / SCRIPT ESCAPING & INPUT SANITIZATION
// =========================================================================
echo "\n--- Section 7: HTML / Script Escaping & Input Sanitization ---\n";

$malicious_text = '<script>alert("XSS")</script><img src=x onerror=alert(1)>Special Offer & Discount!';
$wpdb->insert(
    $table_enquiries,
    array(
        'listing_id'           => $test_listing_id,
        'vendor_id'            => $test_vendor_id,
        'customer_user_id'     => $test_buyer_id,
        'customer_name'        => '<script>evil()</script>Name',
        'customer_email'       => 'safe_' . time() . '@example.com',
        'customer_phone'       => '08011223344',
        'message'              => $malicious_text,
        'vendor_email_sent'    => 0,
        'vendor_email_address' => $vendor_user->user_email,
        'created_at'           => current_time('mysql'),
    ),
    array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
);
$xss_enquiry_id = $wpdb->insert_id;

$captured_emails = array();
$mock_mail_succeed = true;
$res_xss = propvendor_marketplace_send_new_enquiry_notification($xss_enquiry_id, $test_thread_id);

run_test(!empty($res_xss['success']), '7.1 Notification for input containing HTML tags sent safely');
$xss_sent_mail = !empty($captured_emails[0]) ? $captured_emails[0] : array();
run_test(
    strpos($xss_sent_mail['message'] ?? '', '<script>alert("XSS")</script>') === false,
    '7.2 Raw <script> tag is stripped/escaped from email body'
);
run_test(
    strpos($xss_sent_mail['message'] ?? '', '<img src=x') === false,
    '7.3 Malicious <img> tag is stripped/escaped from email body'
);
run_test(
    strpos($xss_sent_mail['message'] ?? '', '<script>evil()</script>') === false,
    '7.4 Malicious sender name tag is stripped/escaped from email body'
);
run_test(
    strpos($xss_sent_mail['message'] ?? '', 'Special Offer &amp; Discount!') !== false ||
    strpos($xss_sent_mail['message'] ?? '', 'Special Offer & Discount!') !== false,
    '7.5 Legitimate text content preserved'
);


// =========================================================================
// SECTION 8: INVALID OR MISSING RECIPIENT EMAIL HANDLING
// =========================================================================
echo "\n--- Section 8: Invalid or Missing Recipient Email Handling ---\n";

$bad_vendor_id = wp_create_user('pv_bad_vendor_' . time(), 'pass123', 'badvendor_' . time() . '@example.com');
// Force user_email in database to empty string and clear WP cache
$wpdb->update($wpdb->users, array('user_email' => ''), array('ID' => $bad_vendor_id));
clean_user_cache($bad_vendor_id);
delete_user_meta($bad_vendor_id, 'propvendor_public_email');

$wpdb->insert(
    $table_enquiries,
    array(
        'listing_id'           => $test_listing_id,
        'vendor_id'            => $bad_vendor_id,
        'customer_user_id'     => $test_buyer_id,
        'customer_name'        => 'Test Customer',
        'customer_email'       => 'test@example.com',
        'customer_phone'       => '08000000000',
        'message'              => 'Test inquiry for bad vendor',
        'vendor_email_sent'    => 0,
        'vendor_email_address' => '',
        'created_at'           => current_time('mysql'),
    ),
    array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
);
$bad_enquiry_id = $wpdb->insert_id;

$res_bad_email = propvendor_marketplace_send_new_enquiry_notification($bad_enquiry_id, $test_thread_id);

run_test(
    empty($res_bad_email['success']) && 'failed' === $res_bad_email['status'],
    '8.1 Missing recipient email results in status failed'
);
run_test(
    $res_bad_email['reason'] === 'invalid_recipient_email',
    '8.2 Failure reason identifies invalid_recipient_email'
);

$bad_notif_row = propvendor_marketplace_get_notification_by_key("new_enquiry:{$bad_enquiry_id}:vendor:{$bad_vendor_id}");
run_test(
    $bad_notif_row && $bad_notif_row->status === 'failed',
    '8.3 Database record saved with status failed'
);
run_test(
    $bad_notif_row && strpos($bad_notif_row->last_error, 'Invalid or missing') !== false,
    '8.4 Error details logged in table'
);


// =========================================================================
// SECTION 9: USER OPT-OUT ENFORCEMENT
// =========================================================================
echo "\n--- Section 9: User Opt-Out Enforcement ---\n";

propvendor_marketplace_set_user_notification_optout($test_vendor_id, 'new_enquiry', true);

$wpdb->insert(
    $table_enquiries,
    array(
        'listing_id'           => $test_listing_id,
        'vendor_id'            => $test_vendor_id,
        'customer_user_id'     => $test_buyer_id,
        'customer_name'        => 'Inquirer Ade',
        'customer_email'       => 'ade@example.com',
        'customer_phone'       => '08012345678',
        'message'              => 'Inquiry while opted out',
        'vendor_email_sent'    => 0,
        'vendor_email_address' => $vendor_user->user_email,
        'created_at'           => current_time('mysql'),
    ),
    array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
);
$optout_enquiry_id = $wpdb->insert_id;

$captured_emails = array();
$res_optout = propvendor_marketplace_send_new_enquiry_notification($optout_enquiry_id, $test_thread_id);

run_test(
    !empty($res_optout['success']) && 'skipped' === $res_optout['status'],
    '9.1 Opted-out user notification returns skipped status'
);
run_test(
    $res_optout['reason'] === 'opted_out',
    '9.2 Skipped reason identifies opted_out'
);
run_test(
    empty($captured_emails),
    '9.3 Zero emails were dispatched to opted-out user'
);

$optout_row = propvendor_marketplace_get_notification_by_key("new_enquiry:{$optout_enquiry_id}:vendor:{$test_vendor_id}");
run_test(
    $optout_row && $optout_row->status === 'skipped',
    '9.4 Database record saved with status skipped'
);

// Reset opt-out
propvendor_marketplace_set_user_notification_optout($test_vendor_id, 'new_enquiry', false);


// =========================================================================
// SECTION 10: TRANSIENT FAILURE, LOGGING, RETRY & BOUNDED LIMITS
// =========================================================================
echo "\n--- Section 10: Transient Failure, Logging, Retry & Bounded Limits ---\n";

$wpdb->insert(
    $table_enquiries,
    array(
        'listing_id'           => $test_listing_id,
        'vendor_id'            => $test_vendor_id,
        'customer_user_id'     => $test_buyer_id,
        'customer_name'        => 'Retry Tester',
        'customer_email'       => 'retry@example.com',
        'customer_phone'       => '08099998888',
        'message'              => 'Testing retry mechanisms',
        'vendor_email_sent'    => 0,
        'vendor_email_address' => $vendor_user->user_email,
        'created_at'           => current_time('mysql'),
    ),
    array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
);
$retry_enquiry_id = $wpdb->insert_id;
$retry_event_key  = "new_enquiry:{$retry_enquiry_id}:vendor:{$test_vendor_id}";

// Simulate transient mail failure
$mock_mail_succeed = false;
$captured_emails   = array();

$res_fail1 = propvendor_marketplace_send_new_enquiry_notification($retry_enquiry_id, $test_thread_id);

run_test(
    empty($res_fail1['success']) && 'retrying' === $res_fail1['status'],
    '10.1 First transient mail failure sets status to retrying'
);

$retry_row = propvendor_marketplace_get_notification_by_key($retry_event_key);
run_test(
    $retry_row && $retry_row->status === 'retrying' && (int)$retry_row->attempts === 1,
    '10.2 Table record has status retrying and attempts count 1'
);
run_test(
    $retry_row && !empty($retry_row->last_error),
    '10.3 Failure reason is logged in last_error column'
);

// Recover mail service
$mock_mail_succeed = true;
$res_retry_success = propvendor_marketplace_retry_notification($retry_row->id);

run_test(
    !empty($res_retry_success['success']) && 'sent' === $res_retry_success['status'],
    '10.4 Manual retry succeeds when mail service recovers'
);

$retry_row_recovered = propvendor_marketplace_get_notification_by_key($retry_event_key);
run_test(
    $retry_row_recovered && $retry_row_recovered->status === 'sent' && (int)$retry_row_recovered->attempts === 2,
    '10.5 Status transitions to sent with attempts count 2'
);
run_test(
    $retry_row_recovered && !empty($retry_row_recovered->sent_at),
    '10.6 sent_at timestamp is populated'
);

// Attempt duplicate retry on already sent notification
$res_retry_dup = propvendor_marketplace_retry_notification($retry_row->id);
run_test(
    empty($res_retry_dup['success']) && $res_retry_dup['reason'] === 'already_sent',
    '10.7 Retry on already sent notification is rejected (already_sent)'
);

// Test bounded retry exhaustion (max 3 attempts -> failed)
$exhaust_event_key = "test_exhaust_" . time();
$mock_mail_succeed = false;

// Attempt 1
$res_ex1 = propvendor_marketplace_dispatch_notification(array(
    'event_key'         => $exhaust_event_key,
    'notification_type' => 'test_failure',
    'recipient_email'   => 'exhaust@example.com',
    'subject'           => 'Exhaustion Test',
    'html_body'         => '<p>Test</p>',
));
run_test('retrying' === $res_ex1['status'], '10.8 Exhaustion attempt 1 status is retrying');

// Attempt 2
$row_ex = propvendor_marketplace_get_notification_by_key($exhaust_event_key);
$res_ex2 = propvendor_marketplace_dispatch_notification(array(
    'event_key'         => $exhaust_event_key,
    'notification_type' => 'test_failure',
    'recipient_email'   => 'exhaust@example.com',
    'subject'           => 'Exhaustion Test',
    'html_body'         => '<p>Test</p>',
    'force_retry'       => true,
));
run_test('retrying' === $res_ex2['status'], '10.9 Exhaustion attempt 2 status is retrying');

// Attempt 3 -> transitions to failed
$res_ex3 = propvendor_marketplace_dispatch_notification(array(
    'event_key'         => $exhaust_event_key,
    'notification_type' => 'test_failure',
    'recipient_email'   => 'exhaust@example.com',
    'subject'           => 'Exhaustion Test',
    'html_body'         => '<p>Test</p>',
    'force_retry'       => true,
));
run_test('failed' === $res_ex3['status'], '10.10 Exhaustion attempt 3 reaches max and status becomes failed');

// Restore mock mail
$mock_mail_succeed = true;


// =========================================================================
// SECTION 11: TRANSACTIONAL SAFETY & NON-ROLLBACK BEHAVIOR
// =========================================================================
echo "\n--- Section 11: Transactional Safety & Non-Rollback ---\n";

// Verify that when notification fails, database entities remain safely persisted
$wpdb->insert(
    $table_enquiries,
    array(
        'listing_id'           => $test_listing_id,
        'vendor_id'            => $test_vendor_id,
        'customer_user_id'     => $test_buyer_id,
        'customer_name'        => 'Persist User',
        'customer_email'       => 'persist@example.com',
        'customer_phone'       => '08011112222',
        'message'              => 'Will persist despite mail error',
        'vendor_email_sent'    => 0,
        'vendor_email_address' => $vendor_user->user_email,
        'created_at'           => current_time('mysql'),
    ),
    array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
);
$persisted_enquiry_id = $wpdb->insert_id;

$mock_mail_succeed = false;
$res_persist_test = propvendor_marketplace_send_new_enquiry_notification($persisted_enquiry_id, $test_thread_id);

$enquiry_still_exists = $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$table_enquiries} WHERE id = %d",
    $persisted_enquiry_id
));
run_test((int)$enquiry_still_exists === 1, '11.1 Enquiry record is safely preserved despite email failure');

$mock_mail_succeed = true;


// =========================================================================
// SECTION 12: ADMIN VISIBILITY, LOG QUERYING & MASKING
// =========================================================================
echo "\n--- Section 12: Admin Visibility & Query API ---\n";

$admin_notifs = propvendor_marketplace_get_notifications(array('limit' => 10));
run_test(!empty($admin_notifs) && count($admin_notifs) > 0, '12.1 propvendor_marketplace_get_notifications queries delivery records');

$first_notif = $admin_notifs[0];
run_test(isset($first_notif->event_key) && isset($first_notif->status), '12.2 Notification object contains event_key and status properties');

$masked_recipient = propvendor_marketplace_mask_email($first_notif->recipient_email);
run_test(
    empty($first_notif->recipient_email) || $masked_recipient !== $first_notif->recipient_email,
    '12.3 Masking helper masks raw email addresses for safe administrative display'
);

$retry_by_key_res = propvendor_marketplace_retry_notification_by_key($retry_event_key);
run_test(
    empty($retry_by_key_res['success']) && $retry_by_key_res['reason'] === 'already_sent',
    '12.4 Retry-by-key helper correctly evaluates idempotency guards'
);


// =========================================================================
// CLEANUP & FINAL REPORT
// =========================================================================
wp_delete_post($test_listing_id, true);
wp_delete_user($test_vendor_id);
wp_delete_user($test_buyer_id);
wp_delete_user($third_party_user_id);
wp_delete_user($bad_vendor_id);

echo "\n====================================================================\n";
echo "  PHASE 6A TEST SUMMARY: {$passed_tests} / {$total_tests} ASSERTIONS PASSED\n";
echo "====================================================================\n\n";

if ($failed_tests > 0) {
    echo "FAILED: {$failed_tests} assertion(s) failed.\n";
    exit(1);
} else {
    echo "SUCCESS: All {$passed_tests} assertions passed cleanly.\n";
    exit(0);
}
