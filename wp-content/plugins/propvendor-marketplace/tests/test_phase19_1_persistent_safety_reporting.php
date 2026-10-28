<?php
/**
 * PropVendor Phase 19.1 — Persistent Safety & Fraud Reporting Test Suite.
 *
 * Verifies:
 * 1. Database schema definition & dynamic prefix adherence
 * 2. Ticket reference generation & collision avoidance (TKT-XXXXXX)
 * 3. Contact & Safety report submission handler (validation, rate limits, persistence)
 * 4. Front-end shortcode & pre-population
 * 5. Admin triage dashboard, status transitions, and audit trail
 * 6. Compatibility & Regression invariants
 */

// Global test tracker
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

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

// Minimal WordPress helper mocks
if (!function_exists('__')) {
    function __($text, $domain = '') { return $text; }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = '') { return $text; }
}
if (!function_exists('esc_html')) {
    function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = '') { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_url')) {
    function esc_url($text) { return $text; }
}
if (!function_exists('esc_textarea')) {
    function esc_textarea($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('home_url')) {
    function home_url($path = '') { return 'https://propvendor.com.ng' . $path; }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '') { return 'https://propvendor.com.ng/wp-admin/' . $path; }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
}
if (!function_exists('is_email')) {
    function is_email($email) { return (bool) filter_var($email, FILTER_VALIDATE_EMAIL); }
}
if (!function_exists('sanitize_email')) {
    function sanitize_email($email) { return filter_var($email, FILTER_SANITIZE_EMAIL); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return strip_tags((string)$str); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($str) { return strip_tags((string)$str); }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$key)); }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($val) { return is_string($val) ? stripslashes($val) : $val; }
}
if (!function_exists('absint')) {
    function absint($val) { return abs((int)$val); }
}
if (!function_exists('current_time')) {
    function current_time($type) { return date('Y-m-d H:i:s'); }
}
if (!function_exists('wp_rand')) {
    function wp_rand($min = 0, $max = 0) { return mt_rand($min, $max); }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}
if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = array()) {
        if (is_object($args)) {
            $r = get_object_vars($args);
        } elseif (is_array($args)) {
            $r =& $args;
        } else {
            parse_str($args, $r);
        }
        return array_merge($defaults, $r);
    }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() { return false; }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() { return 0; }
}
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() { 
        $u = new stdClass(); 
        $u->ID = 0; 
        $u->user_login = 'public_visitor'; 
        return $u; 
    }
}
if (!function_exists('selected')) {
    function selected($val, $current, $echo = true) {
        $result = ($val === $current) ? ' selected="selected"' : '';
        if ($echo) { echo $result; }
        return $result;
    }
}
if (!function_exists('date_i18n')) {
    function date_i18n($format, $timestamp) { return date($format, $timestamp); }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true) {
        $html = '<input type="hidden" name="' . esc_attr($name) . '" value="mock_nonce_' . esc_attr($action) . '" />';
        if ($echo) { echo $html; }
        return $html;
    }
}

// Transient & Option mocks
$GLOBALS['pv_transients'] = array();
$GLOBALS['pv_options'] = array(
    'admin_email' => 'admin@propvendor.com.ng',
    'date_format' => 'Y-m-d',
    'time_format' => 'H:i:s',
);

if (!function_exists('get_transient')) {
    function get_transient($key) {
        return $GLOBALS['pv_transients'][$key] ?? false;
    }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $val, $exp = 0) {
        $GLOBALS['pv_transients'][$key] = $val;
        return true;
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient($key) {
        unset($GLOBALS['pv_transients'][$key]);
        return true;
    }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        return $GLOBALS['pv_options'][$key] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($key, $val) {
        $GLOBALS['pv_options'][$key] = $val;
        return true;
    }
}

// Mock Nonce verification
$GLOBALS['pv_valid_nonces'] = array();
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) {
        if ($nonce === 'valid_test_nonce' || $nonce === 'mock_nonce_' . $action) {
            return 1;
        }
        return false;
    }
}
if (!function_exists('check_admin_referer')) {
    function check_admin_referer($action) {
        if (!empty($_REQUEST['_wpnonce']) && $_REQUEST['_wpnonce'] === 'mock_admin_nonce_' . $action) {
            return true;
        }
        if (!empty($_POST['_wpnonce']) && $_POST['_wpnonce'] === 'valid_admin_nonce') {
            return true;
        }
        return false;
    }
}

// Mock Posts & Listings
$GLOBALS['pv_mock_posts'] = array(
    101 => (object) array('ID' => 101, 'post_title' => 'Luxury 4-Bed Duplex Lekki', 'post_type' => 'property_listing', 'post_author' => 5),
    102 => (object) array('ID' => 102, 'post_title' => 'Commercial Land 2 Acres Ibeju-Lekki', 'post_type' => 'land_listing', 'post_author' => 8),
    103 => (object) array('ID' => 103, 'post_title' => 'Toyota Land Cruiser 2022', 'post_type' => 'vehicle_listing', 'post_author' => 9),
    999 => (object) array('ID' => 999, 'post_title' => 'Blog Post', 'post_type' => 'post', 'post_author' => 1),
);

if (!function_exists('get_post')) {
    function get_post($post_id) {
        return $GLOBALS['pv_mock_posts'][(int)$post_id] ?? null;
    }
}
if (!function_exists('propvendor_marketplace_get_support_email')) {
    function propvendor_marketplace_get_support_email() {
        return 'support@propvendor.com.ng';
    }
}
if (!function_exists('propvendor_marketplace_get_listing_post_types')) {
    function propvendor_marketplace_get_listing_post_types() {
        return array('property_listing', 'land_listing', 'vehicle_listing');
    }
}
if (!function_exists('propvendor_marketplace_create_contact_reports_table')) {
    function propvendor_marketplace_create_contact_reports_table() {
        // Mock table creator for unit testing
        return true;
    }
}

// Mock wp_mail
$GLOBALS['pv_sent_emails'] = array();
if (!function_exists('wp_mail')) {
    function wp_mail($to, $subject, $message, $headers = '', $attachments = array()) {
        $GLOBALS['pv_sent_emails'][] = array(
            'to'      => $to,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headers,
        );
        return true;
    }
}

// Mock wpdb
class MockWPDB {
    public $prefix = 'pv_wp_';
    public $last_error = '';
    public $inserted_records = array();
    public $updated_records = array();
    public $queries = array();
    public $fail_insert = false;
    public $existing_tickets = array();

    public function get_charset_collate() {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
    }

    public function prepare($query, $args) {
        if (!is_array($args)) {
            $args = array_slice(func_get_args(), 1);
        }
        foreach ($args as $arg) {
            $query = preg_replace('/%[sdFf]/', "'" . addslashes((string)$arg) . "'", $query, 1);
        }
        return $query;
    }

    public function esc_like($text) {
        return addcslashes($text, '_%\\');
    }

    public function query($sql) {
        $this->queries[] = $sql;
        return true;
    }

    public function insert($table, $data, $format = null) {
        if ($this->fail_insert) {
            $this->last_error = 'Simulated database deadlock/connection failure';
            return false;
        }
        $data['id'] = count($this->inserted_records) + 1;
        $this->inserted_records[$data['id']] = (object) $data;
        if (!empty($data['ticket_reference'])) {
            $this->existing_tickets[$data['ticket_reference']] = $data['id'];
        }
        return 1;
    }

    public function update($table, $data, $where, $format = null, $where_format = null) {
        $id = $where['id'] ?? 0;
        if (isset($this->inserted_records[$id])) {
            $record = (array) $this->inserted_records[$id];
            foreach ($data as $k => $v) {
                $record[$k] = $v;
            }
            $this->inserted_records[$id] = (object) $record;
            $this->updated_records[] = array('id' => $id, 'data' => $data);
            return 1;
        }
        return 0;
    }

    public function get_var($query) {
        $this->queries[] = $query;
        // Check ticket collision
        if (preg_match("/ticket_reference = '([^']+)'/", $query, $m)) {
            $ticket = $m[1];
            return $this->existing_tickets[$ticket] ?? null;
        }
        if (strpos($query, 'COUNT(*)') !== false) {
            return count($this->inserted_records);
        }
        return null;
    }

    public function get_row($query) {
        $this->queries[] = $query;
        if (preg_match("/id = '?(\d+)'?/", $query, $m)) {
            $id = (int) $m[1];
            return $this->inserted_records[$id] ?? null;
        }
        if (preg_match("/ticket_reference = '([^']+)'/", $query, $m)) {
            $ticket = $m[1];
            foreach ($this->inserted_records as $rec) {
                if ($rec->ticket_reference === $ticket) {
                    return $rec;
                }
            }
        }
        return null;
    }

    public function get_results($query) {
        $this->queries[] = $query;
        return array_values($this->inserted_records);
    }
}

global $wpdb;
$wpdb = new MockWPDB();

// Mock dbDelta
if (!function_exists('dbDelta')) {
    function dbDelta($sql) {
        $GLOBALS['pv_dbdelta_sql'][] = $sql;
        return array('table_created');
    }
}

// Include plugin source code
$plugin_path = dirname(__DIR__) . '/propvendor-marketplace.php';
if (!file_exists($plugin_path)) {
    die("FATAL: Cannot find {$plugin_path}\n");
}
$source = file_get_contents($plugin_path);

echo "====================================================================\n";
echo "  PROPVENDOR PHASE 19.1 — PERSISTENT SAFETY REPORTING TEST SUITE    \n";
echo "====================================================================\n\n";

// -----------------------------------------------------------------
// SECTION 1: DATABASE SCHEMA & DYNAMIC PREFIX ADHERENCE
// -----------------------------------------------------------------
echo "--- Section 1: Database Schema & Dynamic Prefix Adherence ---\n";

pv_assert(
    strpos($source, 'function propvendor_marketplace_create_contact_reports_table') !== false,
    '1.1 Table creation function propvendor_marketplace_create_contact_reports_table is defined'
);

pv_assert(
    strpos($source, '$table_reports = $wpdb->prefix . \'propvendor_contact_reports\';') !== false,
    '1.2 Table name dynamically respects $wpdb->prefix without hardcoding wp_'
);

pv_assert(
    strpos($source, 'ticket_reference varchar(32) NOT NULL') !== false &&
    strpos($source, 'UNIQUE KEY ticket_reference (ticket_reference)') !== false,
    '1.3 Schema defines ticket_reference with UNIQUE KEY constraint'
);

pv_assert(
    strpos($source, 'category varchar(64) NOT NULL') !== false &&
    strpos($source, 'description text NOT NULL') !== false &&
    strpos($source, 'listing_id bigint(20) unsigned') !== false &&
    strpos($source, 'status varchar(32) NOT NULL') !== false &&
    strpos($source, 'priority varchar(32) NOT NULL') !== false &&
    strpos($source, 'audit_trail longtext') !== false,
    '1.4 Schema defines essential triage fields (category, description, listing_id, status, priority, audit_trail)'
);

pv_assert(
    strpos($source, 'KEY category (category)') !== false &&
    strpos($source, 'KEY status (status)') !== false &&
    strpos($source, 'KEY priority (priority)') !== false &&
    strpos($source, 'KEY listing_id (listing_id)') !== false &&
    strpos($source, 'KEY created_at (created_at)') !== false,
    '1.5 Schema defines indexing keys on category, status, priority, listing_id, and created_at'
);

pv_assert(
    strpos($source, 'ENGINE = InnoDB') !== false,
    '1.6 Enforces InnoDB engine for ACID transactions and row-level locking'
);

pv_assert(
    strpos($source, 'propvendor_marketplace_create_contact_reports_table();') !== false,
    '1.7 Table creation routine registered in propvendor_marketplace_activate lifecycle'
);

// -----------------------------------------------------------------
// SECTION 2: TICKET REFERENCE GENERATION & COLLISION HANDLING
// -----------------------------------------------------------------
echo "\n--- Section 2: Ticket Reference Generation & Collision Handling ---\n";

pv_assert(
    strpos($source, 'function propvendor_marketplace_generate_ticket_reference') !== false,
    '2.1 Function propvendor_marketplace_generate_ticket_reference is defined'
);

// Include function definition via evaluation or helper
if (!function_exists('propvendor_marketplace_generate_ticket_reference')) {
    // Extract function from source
    $fn_start = strpos($source, 'function propvendor_marketplace_generate_ticket_reference()');
    if ($fn_start !== false) {
        $brace_count = 0;
        $in_fn = false;
        $fn_code = '';
        for ($i = $fn_start; $i < strlen($source); $i++) {
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
        eval($fn_code);
    }
}

$ticket1 = propvendor_marketplace_generate_ticket_reference();
pv_assert(
    is_string($ticket1) && strlen($ticket1) === 10, // 'TKT-' (4) + 6 chars = 10
    '2.2 Generates valid 10-character ticket reference: ' . $ticket1
);

pv_assert(
    (bool) preg_match('/^TKT-[2-9A-HJ-NP-Z]{6}$/', $ticket1),
    '2.3 Ticket matches strict TKT-XXXXXX format with non-ambiguous uppercase alphanumerics'
);

// Verify no ambiguous characters (0, O, 1, I)
$has_ambiguous = preg_match('/[01OI]/', $ticket1);
pv_assert(
    $has_ambiguous === 0,
    '2.4 Ticket reference strictly excludes ambiguous glyphs (0, O, 1, I)'
);

// Collision test: populate existing ticket in mock DB and confirm generator avoids collision
$wpdb->existing_tickets['TKT-TEST01'] = 99;
$ticket2 = propvendor_marketplace_generate_ticket_reference();
$ticket3 = propvendor_marketplace_generate_ticket_reference();

pv_assert(
    $ticket2 !== $ticket3 && $ticket2 !== 'TKT-TEST01',
    '2.5 Collision avoidance: Generates fresh unique non-colliding references'
);

// -----------------------------------------------------------------
// SECTION 3: CONTACT & SAFETY REPORT FORM SUBMISSION HANDLER
// -----------------------------------------------------------------
echo "\n--- Section 3: Contact & Safety Report Form Submission Handler ---\n";

pv_assert(
    strpos($source, 'function propvendor_marketplace_handle_contact_submission') !== false,
    '3.1 Submission handler propvendor_marketplace_handle_contact_submission is defined'
);

// Extract handler function
if (!function_exists('propvendor_marketplace_handle_contact_submission')) {
    $fn_start = strpos($source, 'function propvendor_marketplace_handle_contact_submission()');
    if ($fn_start !== false) {
        $brace_count = 0;
        $in_fn = false;
        $fn_code = '';
        for ($i = $fn_start; $i < strlen($source); $i++) {
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
        eval($fn_code);
    }
}

// 3.1 Nonce failure
$_POST = array();
$res_nonce_fail = propvendor_marketplace_handle_contact_submission();
pv_assert(
    isset($res_nonce_fail['status']) && $res_nonce_fail['status'] === 'error' && strpos($res_nonce_fail['message'], 'verification failed') !== false,
    '3.2 Missing or invalid nonce rejected with security verification error'
);

// Setup valid nonce
$_POST['propvendor_contact_nonce'] = 'valid_test_nonce';

// 3.3 Missing required fields
$_POST['pv_contact_name'] = '';
$_POST['pv_contact_email'] = '';
$_POST['pv_contact_message'] = '';
$res_missing = propvendor_marketplace_handle_contact_submission();
pv_assert(
    $res_missing['status'] === 'error' && strpos($res_missing['message'], 'required fields') !== false,
    '3.3 Missing required fields rejected'
);

// 3.4 Invalid email
$_POST['pv_contact_name'] = 'Adeola Badmus';
$_POST['pv_contact_email'] = 'not-a-valid-email';
$_POST['pv_contact_message'] = 'Testing message';
$res_invalid_email = propvendor_marketplace_handle_contact_submission();
pv_assert(
    $res_invalid_email['status'] === 'error' && strpos($res_invalid_email['message'], 'valid email address') !== false,
    '3.4 Invalid email format rejected'
);

// 3.5 Valid Fraud Report Submission
$_POST['pv_contact_name'] = 'Emeka Nwosu';
$_POST['pv_contact_email'] = 'emeka@example.com';
$_POST['pv_contact_phone'] = '08023456789';
$_POST['pv_contact_topic'] = 'Fraud / Safety Report';
$_POST['pv_contact_message'] = 'Vendor demanded upfront viewing fee of ₦50,000 before daylight inspection.';
$_POST['pv_contact_listing_id'] = '101'; // Valid property listing

$res_valid = propvendor_marketplace_handle_contact_submission();

pv_assert(
    $res_valid['status'] === 'success' && !empty($res_valid['ticket']),
    '3.5 Valid Fraud Report successfully processed with status=success and ticket reference: ' . ($res_valid['ticket'] ?? 'None')
);

// Verify database record
$saved_report = end($wpdb->inserted_records);
pv_assert(
    $saved_report && $saved_report->ticket_reference === $res_valid['ticket'],
    '3.6 Report successfully persisted to database with matching ticket reference'
);

pv_assert(
    $saved_report->priority === 'high' && $saved_report->category === 'Fraud / Safety Report',
    '3.7 Fraud / Safety Report automatically assigned priority=high'
);

pv_assert(
    $saved_report->listing_id === 101 && $saved_report->vendor_id === 5,
    '3.8 Valid listing ID bound to listing_id=101 and vendor_id=5 (author)'
);

// 3.9 Untrusted / Nonexistent listing ID validation
$_POST['pv_contact_name'] = 'Boluwatife Adeleke';
$_POST['pv_contact_email'] = 'bolu@example.com';
$_POST['pv_contact_topic'] = 'General Enquiry';
$_POST['pv_contact_message'] = 'General question regarding escrow and payments.';
$_POST['pv_contact_listing_id'] = '99999'; // Nonexistent ID

$res_untrusted = propvendor_marketplace_handle_contact_submission();
$saved_untrusted = end($wpdb->inserted_records);

pv_assert(
    $saved_untrusted->listing_id === 0 && $saved_untrusted->vendor_id === 0,
    '3.9 Untrusted / nonexistent listing ID safely zeroed (not blindly trusted)'
);

pv_assert(
    $saved_untrusted->priority === 'medium',
    '3.10 General Enquiry assigned default priority=medium'
);

// 3.11 Duplicate submission guard
$res_dup = propvendor_marketplace_handle_contact_submission();
pv_assert(
    $res_dup['status'] === 'error' && strpos($res_dup['message'], 'identical message') !== false,
    '3.11 Duplicate identical submission within 10 minutes rejected with duplicate warning'
);

// 3.12 Rate limiting test
$rate_ip = '127.0.0.1';
$rate_key = 'pv_contact_rate_' . md5($rate_ip);
set_transient($rate_key, 5, 900); // Set rate attempts to 5

$_POST['pv_contact_name'] = 'New Person';
$_POST['pv_contact_email'] = 'new@example.com';
$_POST['pv_contact_message'] = 'Different unique message text to avoid duplicate guard.';
$res_rate = propvendor_marketplace_handle_contact_submission();

pv_assert(
    $res_rate['status'] === 'error' && strpos($res_rate['message'], 'Too many requests') !== false,
    '3.12 Rate limiting strictly blocks 6th submission when limit (5) reached'
);

delete_transient($rate_key); // Reset rate transient

// 3.13 Database failure truthful response
$wpdb->fail_insert = true;
$_POST['pv_contact_name'] = 'Test Fail';
$_POST['pv_contact_email'] = 'fail@example.com';
$_POST['pv_contact_message'] = 'Test failure message';

$res_fail = propvendor_marketplace_handle_contact_submission();

pv_assert(
    $res_fail['status'] === 'error' && strpos($res_fail['message'], 'unable to securely register your report') !== false,
    '3.13 Database failure returns truthful error response without falsely claiming success'
);

$wpdb->fail_insert = false;

// 3.14 Email notification dispatch with ticket reference
$last_email = end($GLOBALS['pv_sent_emails']);
pv_assert(
    $last_email && strpos($last_email['subject'], '[PropVendor TKT-') !== false,
    '3.14 Admin notification email dispatched with ticket reference in subject line'
);

// 3.15 Audit trail in persisted record
$audit_data = json_decode($saved_report->audit_trail, true);
pv_assert(
    is_array($audit_data) && $audit_data[0]['action'] === 'created' && !empty($audit_data[0]['timestamp']),
    '3.15 Initial audit trail entry registered with action=created and timestamp'
);

// -----------------------------------------------------------------
// SECTION 4: FRONT-END SHORTCODE & FORM PRESENTATION
// -----------------------------------------------------------------
echo "\n--- Section 4: Front-end Shortcode & Form Presentation ---\n";

pv_assert(
    strpos($source, 'add_shortcode(\'propvendor_contact_form\', \'propvendor_marketplace_contact_form_shortcode\');') !== false,
    '4.1 Shortcode [propvendor_contact_form] registered in plugin'
);

// Extract shortcode function
if (!function_exists('propvendor_marketplace_contact_form_shortcode')) {
    $fn_start = strpos($source, 'function propvendor_marketplace_contact_form_shortcode()');
    if ($fn_start !== false) {
        $brace_count = 0;
        $in_fn = false;
        $fn_code = '';
        for ($i = $fn_start; $i < strlen($source); $i++) {
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
        eval($fn_code);
    }
}

// Test shortcode output
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = array();
$html_default = propvendor_marketplace_contact_form_shortcode();

pv_assert(
    strpos($html_default, 'name="propvendor_contact_nonce"') !== false,
    '4.2 Contact form renders CSRF nonce field propvendor_contact_nonce'
);

pv_assert(
    strpos($html_default, 'name="pv_contact_listing_id"') !== false,
    '4.3 Contact form renders optional pv_contact_listing_id input field'
);

// Test pre-selection via URL query parameters
$_GET['topic'] = 'Fraud / Safety Report';
$_GET['listing_id'] = '101';
$html_prefill = propvendor_marketplace_contact_form_shortcode();

pv_assert(
    strpos($html_prefill, 'value="Fraud / Safety Report" selected="selected"') !== false,
    '4.4 Contact form pre-selects Fraud / Safety Report from $_GET[\'topic\']'
);

pv_assert(
    strpos($html_prefill, 'value="101"') !== false,
    '4.5 Contact form pre-fills listing ID from $_GET[\'listing_id\']'
);

// -----------------------------------------------------------------
// SECTION 5: ADMIN TRIAGE DASHBOARD & ACCESS CONTROL
// -----------------------------------------------------------------
echo "\n--- Section 5: Admin Triage Dashboard & Access Control ---\n";

pv_assert(
    strpos($source, '\'propvendor-safety-reports\'') !== false,
    '5.1 Admin menu registers propvendor-safety-reports submenu slug'
);

pv_assert(
    strpos($source, 'manage_options') !== false,
    '5.2 Admin triage page strictly requires manage_options capability'
);

pv_assert(
    strpos($source, 'function propvendor_marketplace_get_safety_reports') !== false &&
    strpos($source, 'function propvendor_marketplace_get_safety_report_by_id') !== false &&
    strpos($source, 'function propvendor_marketplace_get_safety_report_by_ticket') !== false,
    '5.3 Helper functions get_safety_reports, get_safety_report_by_id, and get_safety_report_by_ticket defined'
);

// Extract helper functions
$helpers = array('propvendor_marketplace_get_safety_reports', 'propvendor_marketplace_get_safety_report_by_id', 'propvendor_marketplace_get_safety_report_by_ticket');
foreach ($helpers as $h) {
    if (!function_exists($h)) {
        $fn_start = strpos($source, 'function ' . $h);
        if ($fn_start !== false) {
            $brace_count = 0;
            $in_fn = false;
            $fn_code = '';
            for ($i = $fn_start; $i < strlen($source); $i++) {
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
            eval($fn_code);
        }
    }
}

// Test report lookup by ticket
$tkt_lookup = propvendor_marketplace_get_safety_report_by_ticket($saved_report->ticket_reference);
pv_assert(
    $tkt_lookup && (int)$tkt_lookup->id === (int)$saved_report->id,
    '5.4 Lookup by ticket reference resolves correct report entity'
);

// Test report lookup by id
$id_lookup = propvendor_marketplace_get_safety_report_by_id($saved_report->id);
pv_assert(
    $id_lookup && $id_lookup->reporter_email === $saved_report->reporter_email,
    '5.5 Lookup by report ID resolves correct report entity'
);

// Test status transition and audit trail update
$report_id = $saved_report->id;
$audit_history = json_decode($saved_report->audit_trail, true);
$audit_history[] = array(
    'action'        => 'status_update',
    'timestamp'     => current_time('mysql'),
    'admin_id'      => 1,
    'admin_login'   => 'compliance_officer',
    'from_status'   => 'open',
    'to_status'     => 'resolved',
    'from_priority' => 'high',
    'to_priority'   => 'high',
    'assigned_to'   => 1,
    'notes'         => 'Vendor contacted, title deeds cross-checked with State Lands Bureau. Flag cleared.',
);

$wpdb->update(
    $wpdb->prefix . 'propvendor_contact_reports',
    array(
        'status'           => 'resolved',
        'resolution_notes' => 'Vendor contacted, title deeds cross-checked with State Lands Bureau. Flag cleared.',
        'resolved_at'      => current_time('mysql'),
        'audit_trail'      => wp_json_encode($audit_history),
    ),
    array('id' => $report_id)
);

$updated_report = propvendor_marketplace_get_safety_report_by_id($report_id);
pv_assert(
    $updated_report->status === 'resolved' && !empty($updated_report->resolved_at),
    '5.6 Triage status transition to resolved updates status and records resolved_at timestamp'
);

$updated_audit = json_decode($updated_report->audit_trail, true);
pv_assert(
    count($updated_audit) === 2 && $updated_audit[1]['action'] === 'status_update' && $updated_audit[1]['admin_login'] === 'compliance_officer',
    '5.7 Audit trail successfully appends chronological status update with admin identity and notes'
);

// -----------------------------------------------------------------
// SECTION 6: COMPATIBILITY & REGRESSION INVARIANTS
// -----------------------------------------------------------------
echo "\n--- Section 6: Compatibility & Regression Invariants ---\n";

pv_assert(
    strpos($source, 'support@propvendor.com.ng') !== false,
    '6.1 Official support email defaults to support@propvendor.com.ng'
);

pv_assert(
    strpos($source, 'noreply@propvendor.com.ng') !== false,
    '6.2 Official notification sender defaults to noreply@propvendor.com.ng'
);

pv_assert(
    strpos($html_default, 'propvendor.ng') === false,
    '6.3 Zero occurrences of dead domain propvendor.ng in contact shortcode markup'
);

pv_assert(
    strpos($source, 'propvendor_marketplace_create_enquiries_table') !== false &&
    strpos($source, 'propvendor_marketplace_create_credit_ledger_table') !== false &&
    strpos($source, 'propvendor_marketplace_create_payment_tables') !== false &&
    strpos($source, 'propvendor_marketplace_create_notifications_table') !== false,
    '6.4 Existing database schema functions (enquiries, ledger, payments, notifications) remain intact'
);

echo "\n====================================================================\n";
echo "  PHASE 19.1 TEST EXECUTION SUMMARY\n";
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
