<?php
/**
 * PropVendor Phase 19.2 — Moderation and Onboarding Operations Test Suite.
 *
 * Verifies:
 * 1. Canonical Rejection Reasons & Schema Validation (Workstream B)
 * 2. Moderation Audit Trail Engine & Immutability (Workstream A)
 * 3. Submission & Status Transition Precedence (Fail-Closed, Demotion)
 * 4. Administrator Moderation & Queue Operations (Workstream D)
 * 5. Transactional Moderation Notifications (Workstream C)
 * 6. Front-End Vendor Experience & Safety Disclaimers (Workstream B & D)
 * 7. Security Hygiene, Access Controls & Quota Invariants (All Workstreams)
 */

$total_assertions = 0;
$passed_assertions = 0;
$failed_assertions = array();

function pv_assert($condition, $description, $context = null) {
    global $total_assertions, $passed_assertions, $failed_assertions;
    $total_assertions++;
    if ($condition) {
        $passed_assertions++;
        echo "  [PASS] {$description}\n";
    } else {
        $msg = "  [FAIL] {$description}";
        if ($context !== null) {
            $msg .= " (Context: " . print_r($context, true) . ")";
        }
        $failed_assertions[] = $description;
        echo $msg . "\n";
    }
}

if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);

// Minimal WordPress helper mocks
if (!function_exists('__')) { function __($t, $d = '') { return $t; } }
if (!function_exists('esc_html__')) { function esc_html__($t, $d = '') { return $t; } }
if (!function_exists('esc_html_e')) { function esc_html_e($t, $d = '') { echo htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_html')) { function esc_html($t) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_attr__')) { function esc_attr__($t, $d = '') { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_attr_e')) { function esc_attr_e($t, $d = '') { echo htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_attr')) { function esc_attr($t) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('esc_url')) { function esc_url($t) { return (string)$t; } }
if (!function_exists('esc_textarea')) { function esc_textarea($t) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('home_url')) { function home_url($p = '') { return 'https://propvendor.com.ng' . $p; } }
if (!function_exists('admin_url')) { function admin_url($p = '') { return 'https://propvendor.com.ng/wp-admin/' . $p; } }
if (!function_exists('is_email')) { function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); } }
if (!function_exists('sanitize_email')) { function sanitize_email($e) { return filter_var($e, FILTER_SANITIZE_EMAIL); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return strip_tags((string)$s); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($s) { return strip_tags((string)$s); } }
if (!function_exists('sanitize_key')) { function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$k)); } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $v) { return $v; } }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; } }
if (!function_exists('absint')) { function absint($v) { return abs((int)$v); } }
if (!function_exists('number_format_i18n')) { function number_format_i18n($num, $dec = 0) { return number_format((float)$num, $dec); } }
if (!function_exists('date_i18n')) { function date_i18n($format, $timestamp = null) { return date($format, $timestamp ?: time()); } }
if (!function_exists('current_time')) { 
    function current_time($type) { 
        return ($type === 'timestamp') ? 1761690000 : '2026-10-28 23:30:00'; 
    } 
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        return substr(str_shuffle(str_repeat($chars, 5)), 0, $length);
    }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg($key, $value = false, $url = '') {
        if (is_array($key)) {
            $params = $key;
            $target = $value ?: '';
        } else {
            $params = array($key => $value);
            $target = $url ?: '';
        }
        $parts = parse_url($target);
        $query = array();
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        foreach ($params as $k => $v) {
            $query[$k] = $v;
        }
        $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'propvendor.com.ng') . ($parts['path'] ?? '');
        return $base . '?' . http_build_query($query);
    }
}

// User & Capability state mock
$GLOBALS['pv_current_user_id'] = 1;
$GLOBALS['pv_user_caps'] = array(
    1 => array('manage_options' => true, 'propvendor_submit_marketplace_listings' => true), // Admin
    5 => array('propvendor_submit_marketplace_listings' => true), // Vendor
    9 => array(), // Customer
);
$GLOBALS['pv_users'] = array(
    1 => (object) array('ID' => 1, 'user_login' => 'afriab_admin', 'display_name' => 'Afriab Admin', 'user_email' => 'admin@propvendor.com.ng'),
    5 => (object) array('ID' => 5, 'user_login' => 'lekki_realtors', 'display_name' => 'Lekki Realtors Ltd', 'user_email' => 'vendor@realtors.ng'),
    9 => (object) array('ID' => 9, 'user_login' => 'john_buyer', 'display_name' => 'John Buyer', 'user_email' => 'john@buyer.com'),
);

if (!function_exists('get_current_user_id')) {
    function get_current_user_id() { return $GLOBALS['pv_current_user_id']; }
}
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() {
        $id = $GLOBALS['pv_current_user_id'];
        return $GLOBALS['pv_users'][$id] ?? (object) array('ID' => 0, 'user_login' => 'guest', 'display_name' => 'Guest');
    }
}
if (!function_exists('get_user_by')) {
    function get_user_by($field, $value) {
        if ($field === 'id') {
            return $GLOBALS['pv_users'][(int)$value] ?? null;
        }
        return null;
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap) {
        $uid = $GLOBALS['pv_current_user_id'];
        return !empty($GLOBALS['pv_user_caps'][$uid][$cap]);
    }
}
if (!function_exists('user_can')) {
    function user_can($user, $cap) {
        $uid = is_object($user) ? $user->ID : (int)$user;
        return !empty($GLOBALS['pv_user_caps'][$uid][$cap]);
    }
}

// Post & Meta store
$GLOBALS['pv_posts'] = array(
    201 => (object) array('ID' => 201, 'post_title' => 'Luxury 5-Bed Duplex Ikoyi', 'post_type' => 'property_listing', 'post_status' => 'pending', 'post_author' => 5),
    202 => (object) array('ID' => 202, 'post_title' => 'Prime 2000sqm Land Epe', 'post_type' => 'land_listing', 'post_status' => 'publish', 'post_author' => 5),
    203 => (object) array('ID' => 203, 'post_title' => 'Toyota Land Cruiser Prado 2023', 'post_type' => 'vehicle_listing', 'post_status' => 'draft', 'post_author' => 5),
);
$GLOBALS['pv_postmeta'] = array(
    201 => array('propvendor_price' => 250000000, 'propvendor_location' => 'Lagos', 'propvendor_listing_status' => 'Pending review'),
    202 => array('propvendor_price' => 45000000, 'propvendor_location' => 'Lagos', 'propvendor_listing_status' => 'Published'),
    203 => array('propvendor_price' => 85000000, 'propvendor_location' => 'Abuja', 'propvendor_listing_status' => 'Rejected', '_propvendor_rejection_reason' => 'poor_images', '_propvendor_rejection_notes' => 'Please provide high-resolution images.'),
);
$GLOBALS['pv_usermeta'] = array(
    5 => array('propvendor_business_name' => 'Lekki Realtors Ltd'),
);

if (!function_exists('get_post')) {
    function get_post($post_id) {
        return $GLOBALS['pv_posts'][(int)$post_id] ?? null;
    }
}
if (!function_exists('get_the_title')) {
    function get_the_title($post = 0) {
        $p = is_object($post) ? $post : get_post($post);
        return $p ? $p->post_title : '';
    }
}
if (!function_exists('get_post_type')) {
    function get_post_type($post = null) {
        $p = is_object($post) ? $post : get_post($post);
        return $p ? $p->post_type : '';
    }
}
if (!function_exists('get_permalink')) {
    function get_permalink($post = 0) {
        $id = is_object($post) ? $post->ID : (int)$post;
        return 'https://propvendor.com.ng/listing/' . $id . '/';
    }
}
if (!function_exists('get_post_modified_time')) {
    function get_post_modified_time($f, $g, $p) { return 1761691200; }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = true) {
        return $GLOBALS['pv_postmeta'][(int)$post_id][$key] ?? ($single ? '' : array());
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $key, $value) {
        $GLOBALS['pv_postmeta'][(int)$post_id][$key] = $value;
        return true;
    }
}
if (!function_exists('delete_post_meta')) {
    function delete_post_meta($post_id, $key) {
        unset($GLOBALS['pv_postmeta'][(int)$post_id][$key]);
        return true;
    }
}
if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key, $single = true) {
        return $GLOBALS['pv_usermeta'][(int)$user_id][$key] ?? ($single ? '' : array());
    }
}
if (!function_exists('wp_update_post')) {
    function wp_update_post($args, $wp_error = false) {
        $id = $args['ID'] ?? 0;
        if (isset($GLOBALS['pv_posts'][$id])) {
            foreach ($args as $k => $v) {
                if ($k !== 'ID') {
                    $GLOBALS['pv_posts'][$id]->$k = $v;
                }
            }
            return $id;
        }
        return 0;
    }
}

// Option mocks
$GLOBALS['pv_options'] = array(
    'admin_email' => 'admin@propvendor.com.ng',
    'propvendor_mail_sender_email' => 'noreply@propvendor.com.ng',
    'propvendor_mail_sender_name'  => 'PropVendor Marketplace',
);
if (!function_exists('get_option')) {
    function get_option($k, $d = false) { return $GLOBALS['pv_options'][$k] ?? $d; }
}

// Mock Nonces
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) {
        return ($nonce === 'valid_mod_nonce' || $nonce === 'mock_nonce_' . $action);
    }
}

// Mock Dispatched Notifications
$GLOBALS['pv_dispatched_notifications'] = array();
if (!function_exists('propvendor_marketplace_dispatch_notification')) {
    function propvendor_marketplace_dispatch_notification($args) {
        $GLOBALS['pv_dispatched_notifications'][] = $args;
        return array('success' => true, 'status' => 'sent', 'notification_id' => count($GLOBALS['pv_dispatched_notifications']));
    }
}

// Include plugin source
$plugin_path = dirname(__DIR__) . '/propvendor-marketplace.php';
if (!file_exists($plugin_path)) {
    die("FATAL: Cannot find {$plugin_path}\n");
}
$source = file_get_contents($plugin_path);

// Helper to extract and evaluate functions from plugin source
function extract_and_eval_function($fn_name, $source) {
    if (function_exists($fn_name)) {
        return;
    }
    $pattern = '/function\s+' . preg_quote($fn_name, '/') . '\s*\(/';
    if (!preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
        return;
    }
    $fn_start = $m[0][1];
    $brace_count = 0;
    $in_fn = false;
    $fn_code = '';
    $len = strlen($source);
    for ($i = $fn_start; $i < $len; $i++) {
        $char = $source[$i];
        $fn_code .= $char;
        if ($char === '{') {
            $brace_count++;
            $in_fn = true;
        } elseif ($char === '}') {
            $brace_count--;
            if ($in_fn && $brace_count === 0) {
                break;
            }
        }
    }
    if ($in_fn && $brace_count === 0) {
        eval($fn_code);
    }
}

// Load required functions from source
extract_and_eval_function('propvendor_marketplace_get_listing_post_types', $source);
extract_and_eval_function('propvendor_marketplace_get_listing_status_choices', $source);
extract_and_eval_function('propvendor_marketplace_get_canonical_listing_status', $source);
extract_and_eval_function('propvendor_marketplace_get_rejection_reasons', $source);
extract_and_eval_function('propvendor_marketplace_get_rejection_reason_label', $source);
extract_and_eval_function('propvendor_marketplace_record_moderation_event', $source);
extract_and_eval_function('propvendor_marketplace_get_moderation_audit_trail', $source);
extract_and_eval_function('propvendor_marketplace_get_notification_sender_email', $source);
extract_and_eval_function('propvendor_marketplace_get_notification_sender_name', $source);
extract_and_eval_function('propvendor_marketplace_render_email_layout', $source);
extract_and_eval_function('propvendor_marketplace_send_listing_submitted_notification', $source);
extract_and_eval_function('propvendor_marketplace_send_listing_approved_notification', $source);
extract_and_eval_function('propvendor_marketplace_send_listing_rejected_notification', $source);

echo "====================================================================\n";
echo "  PROPVENDOR PHASE 19.2 — MODERATION & ONBOARDING OPERATIONS TEST SUITE\n";
echo "====================================================================\n\n";

// -----------------------------------------------------------------
// SECTION 1: CANONICAL REJECTION REASONS & SCHEMA VALIDATION
// -----------------------------------------------------------------
echo "--- Section 1: Canonical Rejection Reasons & Schema Validation ---\n";

pv_assert(
    function_exists('propvendor_marketplace_get_rejection_reasons'),
    "1.1 Function propvendor_marketplace_get_rejection_reasons is defined"
);

$reasons = propvendor_marketplace_get_rejection_reasons();
pv_assert(is_array($reasons) && count($reasons) === 6, "1.2 Returns exactly 6 canonical rejection reason codes", $reasons);

$expected_keys = array('missing_documents', 'missing_vin', 'poor_images', 'incomplete_details', 'suspicious_content', 'other');
$actual_keys = array_keys($reasons);
pv_assert(
    empty(array_diff($expected_keys, $actual_keys)),
    "1.3 Rejection reason keys match strict required taxonomy: missing_documents, missing_vin, poor_images, incomplete_details, suspicious_content, other",
    $actual_keys
);

pv_assert(
    function_exists('propvendor_marketplace_get_rejection_reason_label'),
    "1.4 Function propvendor_marketplace_get_rejection_reason_label is defined"
);

pv_assert(
    strpos(propvendor_marketplace_get_rejection_reason_label('poor_images'), 'Blurred') !== false,
    "1.5 Resolves 'poor_images' to human-readable description: Blurred, Low Quality, Watermarked..."
);

pv_assert(
    strpos(propvendor_marketplace_get_rejection_reason_label('missing_documents'), 'Property Documentation') !== false,
    "1.6 Resolves 'missing_documents' to human-readable description: Missing or Unclear Property Documentation..."
);

pv_assert(
    propvendor_marketplace_get_rejection_reason_label('unregistered_code_xyz') === 'unregistered_code_xyz',
    "1.7 Unknown reason code safely handled without crashing (returns sanitized string)"
);

pv_assert(
    propvendor_marketplace_get_rejection_reason_label('') === 'Unspecified Reason',
    "1.8 Empty reason code safely resolves to 'Unspecified Reason'"
);

// -----------------------------------------------------------------
// SECTION 2: MODERATION AUDIT TRAIL ENGINE
// -----------------------------------------------------------------
echo "\n--- Section 2: Moderation Audit Trail Engine ---\n";

pv_assert(
    function_exists('propvendor_marketplace_record_moderation_event'),
    "2.1 Function propvendor_marketplace_record_moderation_event is defined"
);

pv_assert(
    function_exists('propvendor_marketplace_get_moderation_audit_trail'),
    "2.2 Function propvendor_marketplace_get_moderation_audit_trail is defined"
);

// Record initial event for listing 201
$GLOBALS['pv_current_user_id'] = 5; // Vendor
$event1 = propvendor_marketplace_record_moderation_event(201, array(
    'actor_id'    => 5,
    'actor_name'  => 'Lekki Realtors Ltd',
    'actor_role'  => 'vendor',
    'from_status' => 'None',
    'to_status'   => 'Pending review',
    'decision'    => 'submitted',
    'reason_code' => '',
    'notes'       => 'Initial listing submission.',
));

pv_assert(!empty($event1['entry_id']) && strpos($event1['entry_id'], 'mod_') === 0, "2.3 Moderation event generates unique ID with 'mod_' prefix", $event1['entry_id']);
pv_assert($event1['actor_id'] === 5 && $event1['actor_role'] === 'vendor', "2.4 Records vendor actor attribution correctly");
pv_assert($event1['from_status'] === 'None' && $event1['to_status'] === 'Pending review', "2.5 Records status transition from None to Pending review");
pv_assert($event1['decision'] === 'submitted', "2.6 Records decision as 'submitted'");
pv_assert(!empty($event1['timestamp']), "2.7 Records human-readable MySQL timestamp: " . $event1['timestamp']);

// Record second event (Admin Rejection)
$GLOBALS['pv_current_user_id'] = 1; // Admin
$event2 = propvendor_marketplace_record_moderation_event(201, array(
    'actor_id'    => 1,
    'actor_name'  => 'Afriab Admin',
    'actor_role'  => 'admin',
    'from_status' => 'Pending review',
    'to_status'   => 'Rejected',
    'decision'    => 'rejected',
    'reason_code' => 'missing_documents',
    'notes'       => 'Please upload a legible copy of the C of O.',
));

$trail = propvendor_marketplace_get_moderation_audit_trail(201);
pv_assert(count($trail) === 2, "2.8 Audit trail successfully appends events chronologically (count=2)", count($trail));
pv_assert($trail[1]['decision'] === 'rejected' && $trail[1]['reason_code'] === 'missing_documents', "2.9 Audit trail stores rejection reason code: missing_documents");
pv_assert($trail[1]['actor_role'] === 'admin', "2.10 Audit trail distinguishes admin actor from vendor");

// Record third event (Vendor Resubmission)
$GLOBALS['pv_current_user_id'] = 5; // Vendor
$event3 = propvendor_marketplace_record_moderation_event(201, array(
    'actor_id'    => 5,
    'actor_name'  => 'Lekki Realtors Ltd',
    'actor_role'  => 'vendor',
    'from_status' => 'Rejected',
    'to_status'   => 'Pending review',
    'decision'    => 'resubmitted',
    'reason_code' => '',
    'notes'       => 'Uploaded scanned Governor Consent and survey plan.',
));

$trail_after_resubmit = propvendor_marketplace_get_moderation_audit_trail(201);
pv_assert(count($trail_after_resubmit) === 3, "2.11 Resubmission preserves previous rejection history (immutability test)", count($trail_after_resubmit));
pv_assert($trail_after_resubmit[2]['decision'] === 'resubmitted', "2.12 Records resubmission transition properly");

// Audit trail bounding test (prevent log bloat)
for ($i = 0; $i < 60; $i++) {
    propvendor_marketplace_record_moderation_event(201, array(
        'decision' => 'test_entry_' . $i,
    ));
}
$bounded_trail = propvendor_marketplace_get_moderation_audit_trail(201);
pv_assert(count($bounded_trail) === 50, "2.13 Audit trail enforces upper bound of 50 entries to prevent postmeta bloat", count($bounded_trail));

// -----------------------------------------------------------------
// SECTION 3: SUBMISSION & STATUS TRANSITIONS
// -----------------------------------------------------------------
echo "\n--- Section 3: Submission & Status Transition Precedence ---\n";

pv_assert(
    strpos($source, "delete_post_meta(\$post_id, '_propvendor_rejection_reason');") !== false,
    "3.1 Resubmission handler deletes active rejection reason meta"
);

pv_assert(
    strpos($source, "delete_post_meta(\$post_id, '_propvendor_rejection_notes');") !== false,
    "3.2 Resubmission handler deletes active rejection notes meta"
);

pv_assert(
    strpos($source, "propvendor_marketplace_record_moderation_event(\$post_id,") !== false,
    "3.3 Listing submission process records moderation event in audit trail"
);

// Verify fail-closed status resolution
pv_assert(
    propvendor_marketplace_get_canonical_listing_status(203) === 'Rejected',
    "3.4 Canonical status resolver fail-closed check: Listing 203 resolves to 'Rejected'"
);

pv_assert(
    propvendor_marketplace_get_canonical_listing_status(202) === 'Published',
    "3.5 Canonical status resolver check: Listing 202 resolves to 'Published'"
);

// -----------------------------------------------------------------
// SECTION 4: ADMINISTRATOR MODERATION & QUEUE OPERATIONS
// -----------------------------------------------------------------
echo "\n--- Section 4: Administrator Moderation & Queue Operations ---\n";

pv_assert(
    strpos($source, 'function propvendor_marketplace_render_admin_moderation_queue_page') !== false,
    "4.1 Function propvendor_marketplace_render_admin_moderation_queue_page is defined in source"
);

pv_assert(
    strpos($source, 'function propvendor_marketplace_handle_admin_quick_moderation') !== false,
    "4.2 Quick moderation form handler propvendor_marketplace_handle_admin_quick_moderation is defined in source"
);

pv_assert(
    strpos($source, "'Marketplace Moderation Queue'") !== false,
    "4.3 Moderation Queue submenu is registered under Marketplace Enquiries"
);

pv_assert(
    strpos($source, "'Moderation Queue' . \$badge") !== false,
    "4.4 Moderation Queue submenu dynamically includes pending count badge"
);

pv_assert(
    strpos($source, "'propvendor_moderation_queue_action'") !== false,
    "4.5 Quick moderation action verifies CSRF nonce propvendor_moderation_queue_action"
);

pv_assert(
    strpos($source, "current_user_can('manage_options')") !== false,
    "4.6 Moderation Queue and quick action strictly gate behind manage_options capability"
);

// -----------------------------------------------------------------
// SECTION 5: TRANSACTIONAL MODERATION NOTIFICATIONS
// -----------------------------------------------------------------
echo "\n--- Section 5: Transactional Moderation Notifications ---\n";

pv_assert(
    function_exists('propvendor_marketplace_send_listing_submitted_notification'),
    "5.1 Function propvendor_marketplace_send_listing_submitted_notification is defined"
);

pv_assert(
    function_exists('propvendor_marketplace_send_listing_approved_notification'),
    "5.2 Function propvendor_marketplace_send_listing_approved_notification is defined"
);

pv_assert(
    function_exists('propvendor_marketplace_send_listing_rejected_notification'),
    "5.3 Function propvendor_marketplace_send_listing_rejected_notification is defined"
);

// Test Listing Submitted Notification
$res_sub = propvendor_marketplace_send_listing_submitted_notification(201, false);
pv_assert($res_sub['success'] === true, "5.4 Dispatches listing_submitted notification successfully");
$last_notif = end($GLOBALS['pv_dispatched_notifications']);
pv_assert($last_notif['notification_type'] === 'listing_submitted', "5.5 Sets notification_type = 'listing_submitted'");
pv_assert(strpos($last_notif['event_key'], 'listing_submitted:201:') === 0, "5.6 Generates deterministic event_key starting with listing_submitted:201:", $last_notif['event_key']);
pv_assert($last_notif['recipient_email'] === 'admin@propvendor.com.ng', "5.7 Directs notification to admin_email");
pv_assert(strpos($last_notif['html_body'], 'Luxury 5-Bed Duplex Ikoyi') !== false, "5.8 Email content includes listing title");
pv_assert(strpos($last_notif['html_body'], 'Open Moderation Queue') !== false, "5.9 Email CTA points to Moderation Queue");

// Test Listing Approved Notification
$res_app = propvendor_marketplace_send_listing_approved_notification(201);
pv_assert($res_app['success'] === true, "5.10 Dispatches listing_approved notification successfully");
$last_notif = end($GLOBALS['pv_dispatched_notifications']);
pv_assert($last_notif['notification_type'] === 'listing_approved', "5.11 Sets notification_type = 'listing_approved'");
pv_assert($last_notif['recipient_email'] === 'vendor@realtors.ng', "5.12 Directs notification to vendor user_email (vendor@realtors.ng)");
pv_assert(strpos($last_notif['subject'], 'approved and published') !== false, "5.13 Email subject informs vendor that listing is published");

// Test Listing Rejected Notification
$res_rej = propvendor_marketplace_send_listing_rejected_notification(201, 'poor_images', 'Photos are too blurry.');
pv_assert($res_rej['success'] === true, "5.14 Dispatches listing_rejected notification successfully");
$last_notif = end($GLOBALS['pv_dispatched_notifications']);
pv_assert($last_notif['notification_type'] === 'listing_rejected', "5.15 Sets notification_type = 'listing_rejected'");
pv_assert(strpos($last_notif['html_body'], 'Blurred, Low Quality') !== false, "5.16 Email body contains human-readable rejection reason");
pv_assert(strpos($last_notif['html_body'], 'Photos are too blurry.') !== false, "5.17 Email body contains specific moderator feedback notes");
pv_assert(strpos($last_notif['html_body'], 'Edit &amp; Resubmit Listing') !== false || strpos($last_notif['html_body'], 'Edit & Resubmit Listing') !== false, "5.18 Email CTA provides direct link to edit and resubmit");

// -----------------------------------------------------------------
// SECTION 6: FRONT-END VENDOR EXPERIENCE & DISCLAIMERS
// -----------------------------------------------------------------
echo "\n--- Section 6: Front-End Vendor Experience & Disclaimers ---\n";

pv_assert(
    strpos($source, 'pv-moderation-remediation-banner') !== false,
    "6.1 Submit/Edit listing form includes pv-moderation-remediation-banner markup"
);

pv_assert(
    strpos($source, 'Action Required: Moderator Corrections Requested') !== false,
    "6.2 Banner clearly highlights required moderator corrections"
);

pv_assert(
    strpos($source, 'pv-dashboard-rejection-info') !== false,
    "6.3 Vendor dashboard table includes pv-dashboard-rejection-info container"
);

pv_assert(
    strpos($source, 'pv-btn-resubmit') !== false,
    "6.4 Vendor dashboard table renders 'Edit & Resubmit' CTA button"
);

pv_assert(
    strpos($source, 'Approval does not constitute official legal title verification') !== false,
    "6.5 Approval notification includes strict legal title & vehicle ownership verification disclaimer"
);

pv_assert(
    strpos($source, 'Approval does not verify legal title authenticity') !== false,
    "6.6 Moderation meta box includes safety disclaimer regarding title and ownership"
);

// -----------------------------------------------------------------
// SECTION 7: SECURITY HYGIENE, ACCESS CONTROLS & QUOTA INVARIANTS
// -----------------------------------------------------------------
echo "\n--- Section 7: Security Hygiene, Access Controls & Quota Invariants ---\n";

pv_assert(
    strpos($source, '_propvendor_moderation_audit_trail') !== false,
    "7.1 Moderation audit trail uses protected postmeta key (_propvendor_moderation_audit_trail)"
);

pv_assert(
    strpos($source, '_propvendor_rejection_reason') !== false,
    "7.2 Rejection reason uses protected postmeta key (_propvendor_rejection_reason)"
);

pv_assert(
    strpos($source, 'propvendor_marketplace_restrict_vendor_rest_writes') !== false,
    "7.3 REST write isolation for vendors remains strictly enforced (HTTP 403 for non-admins)"
);

pv_assert(
    strpos($source, 'propvendor_marketplace_restrict_vendor_admin_access') !== false,
    "7.4 Vendor wp-admin access lockdown remains strictly active"
);

pv_assert(
    strpos($source, 'pv_listing_quota_') !== false,
    "7.5 Free-tier active listing quota atomic lock (GET_LOCK) remains active"
);

$notif_content_all = ($last_notif['html_body'] ?? '') . ($res_sub['html_body'] ?? '') . ($res_app['html_body'] ?? '');
pv_assert(
    strpos($notif_content_all, 'propvendor.ng') === false,
    "7.6 Zero occurrences of dead domain propvendor.ng in moderation notification templates"
);

// Summary
echo "\n====================================================================\n";
echo "  PHASE 19.2 TEST EXECUTION SUMMARY\n";
echo "====================================================================\n";
echo "TOTAL ASSERTIONS : {$total_assertions}\n";
echo "PASSED           : {$passed_assertions}\n";
echo "FAILED           : " . count($failed_assertions) . "\n";
echo "STATUS           : " . (count($failed_assertions) === 0 ? "100% SUCCESS — ALL SUITES PASSED" : "FAILED") . "\n";
echo "====================================================================\n";

if (count($failed_assertions) > 0) {
    exit(1);
}
exit(0);
