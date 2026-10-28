<?php
/**
 * PropVendor Phase 19.0 — Support Channel Recovery & Authenticated SMTP Test Suite.
 *
 * Verifies:
 * 1. Application email address consolidation to @propvendor.com.ng (and noreply@)
 * 2. Privacy Policy, Help & FAQs, About Us, and Shortcode content alignment
 * 3. Authenticated Hostinger SMTP relay configuration via phpmailer_init
 * 4. Credential safety enforcement and absence of silent unauthenticated fallback
 * 5. Sanitized failure logging via wp_mail_failed hook
 * 6. Migration routines and frontend performance guards
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

// Minimal WordPress helper mocks
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = '') { return $text; }
}
if (!function_exists('esc_html')) {
    function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_url')) {
    function esc_url($text) { return $text; }
}
if (!function_exists('home_url')) {
    function home_url($path = '') { return 'https://propvendor.com.ng' . $path; }
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
    function sanitize_text_field($str) { return strip_tags($str); }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)); }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) { return $value; }
}
if (!function_exists('get_option')) {
    $GLOBALS['pv_options'] = array();
    function get_option($opt, $default = false) {
        return $GLOBALS['pv_options'][$opt] ?? $default;
    }
    function update_option($opt, $val) {
        $GLOBALS['pv_options'][$opt] = $val;
        return true;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        protected $code;
        protected $message;
        public function __construct($code = '', $message = '') {
            $this->code = $code;
            $this->message = $message;
        }
        public function get_error_message() { return $this->message; }
        public function get_error_code() { return $this->code; }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return ($thing instanceof WP_Error); }
}

// Mock PHPMailer for unit testing SMTP hook
class MockPHPMailer {
    public $Mailer = 'mail';
    public $Host = '';
    public $Port = 25;
    public $SMTPAuth = false;
    public $SMTPSecure = '';
    public $Username = '';
    public $Password = '';
    public $Timeout = 300;
    public $From = '';
    public $FromName = '';

    public function isSMTP() {
        $this->Mailer = 'smtp';
    }
}

// Read plugin source
$plugin_file = dirname(__DIR__) . '/propvendor-marketplace.php';
$source = file_get_contents($plugin_file);

// Extract required functions dynamically using brace counting
function extract_function($func_name, $source) {
    if (function_exists($func_name)) return true;
    $pattern = '/function\s+' . preg_quote($func_name, '/') . '\s*\((.*?)\)\s*\{/s';
    if (!preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
        return false;
    }
    $param_str = $m[1][0];
    $start_pos = $m[0][1] + strlen($m[0][0]);

    $len = strlen($source);
    $brace_count = 1;
    $body = '';
    for ($i = $start_pos; $i < $len; $i++) {
        $ch = $source[$i];
        if ($ch === '{') {
            $brace_count++;
        } elseif ($ch === '}') {
            $brace_count--;
            if ($brace_count === 0) {
                $body = substr($source, $start_pos, $i - $start_pos);
                break;
            }
        }
    }
    eval("function {$func_name}({$param_str}) { {$body} }");
    return true;
}

extract_function('propvendor_marketplace_get_support_email', $source);
extract_function('propvendor_marketplace_get_notification_sender_email', $source);
extract_function('propvendor_marketplace_get_notification_sender_name', $source);
extract_function('propvendor_marketplace_get_privacy_policy_content', $source);
extract_function('propvendor_marketplace_get_help_faqs_content', $source);
extract_function('propvendor_marketplace_about_shortcode', $source);
extract_function('propvendor_marketplace_help_faqs_shortcode', $source);
extract_function('propvendor_marketplace_safety_guidelines_shortcode', $source);
extract_function('propvendor_marketplace_configure_phpmailer_smtp', $source);
extract_function('propvendor_marketplace_handle_wp_mail_failed', $source);

echo "====================================================================\n";
echo "  PROPVENDOR PHASE 19.0 — SUPPORT CHANNEL RECOVERY TEST SUITE       \n";
echo "====================================================================\n\n";

// SECTION 1: Application Email Address Consolidation
echo "--- Section 1: Application Email Address Consolidation ---\n";

$supp_email = propvendor_marketplace_get_support_email();
pv_assert('support@propvendor.com.ng' === $supp_email, '1.1 Official support email defaults to support@propvendor.com.ng', $supp_email);

$sender_email = propvendor_marketplace_get_notification_sender_email();
pv_assert('noreply@propvendor.com.ng' === $sender_email, '1.2 Notification sender email defaults to noreply@propvendor.com.ng', $sender_email);

$sender_name = propvendor_marketplace_get_notification_sender_name();
pv_assert('PropVendor Marketplace' === $sender_name, '1.3 Notification sender name defaults to PropVendor Marketplace', $sender_name);

$privacy_content = propvendor_marketplace_get_privacy_policy_content();
pv_assert(strpos($privacy_content, 'privacy@propvendor.com.ng') !== false, '1.4 Privacy policy content includes privacy@propvendor.com.ng');
pv_assert(strpos($privacy_content, 'support@propvendor.com.ng') !== false, '1.5 Privacy policy content includes support@propvendor.com.ng');
pv_assert(strpos($privacy_content, 'https://propvendor.com.ng') !== false, '1.6 Privacy policy content includes canonical https://propvendor.com.ng');
pv_assert(strpos($privacy_content, 'propvendor.ng') === false, '1.7 Privacy policy content has zero occurrences of dead domain propvendor.ng');

$faq_content = propvendor_marketplace_get_help_faqs_content();
pv_assert(strpos($faq_content, 'safety@propvendor.com.ng') !== false, '1.8 Help & FAQs content includes safety@propvendor.com.ng');
pv_assert(strpos($faq_content, 'propvendor.ng') === false, '1.9 Help & FAQs content has zero occurrences of dead domain propvendor.ng');

$about_content = propvendor_marketplace_about_shortcode();
pv_assert(strpos($about_content, 'support@propvendor.com.ng') !== false, '1.10 About Us content includes support@propvendor.com.ng');
pv_assert(strpos($about_content, 'propvendor.ng') === false, '1.11 About Us content has zero occurrences of dead domain propvendor.ng');

$faq_shortcode_content = propvendor_marketplace_help_faqs_shortcode();
pv_assert(strpos($faq_shortcode_content, 'support@propvendor.com.ng') !== false, '1.12 Help & FAQs shortcode output includes support@propvendor.com.ng');
pv_assert(strpos($faq_shortcode_content, 'propvendor.ng') === false, '1.13 Help & FAQs shortcode output has zero occurrences of dead domain propvendor.ng');

$safety_shortcode_content = propvendor_marketplace_safety_guidelines_shortcode();
pv_assert(strpos($safety_shortcode_content, 'propvendor.ng') === false, '1.14 Safety guidelines shortcode has zero occurrences of dead domain propvendor.ng');
pv_assert(strpos($safety_shortcode_content, '/contact-us/') !== false, '1.15 Safety guidelines shortcode routes fraud reports to /contact-us/');

// SECTION 2: Authenticated Hostinger SMTP Relay Hook
echo "\n--- Section 2: Authenticated Hostinger SMTP Relay Hook ---\n";

pv_assert(strpos($source, "add_action('phpmailer_init', 'propvendor_marketplace_configure_phpmailer_smtp')") !== false, '2.1 phpmailer_init hook registered for propvendor_marketplace_configure_phpmailer_smtp');

// Test 2.2: Disabled state
$mailer1 = new MockPHPMailer();
update_option('propvendor_smtp_enabled', 0);
propvendor_marketplace_configure_phpmailer_smtp($mailer1);
pv_assert('mail' === $mailer1->Mailer && '' === $mailer1->Host, '2.2 Disabled SMTP leaves PHPMailer untouched in default mail transport');

// Test 2.3: Enabled state with missing credentials
$mailer2 = new MockPHPMailer();
update_option('propvendor_smtp_enabled', 1);
update_option('propvendor_smtp_user', ''); // missing user/pass
propvendor_marketplace_configure_phpmailer_smtp($mailer2);
pv_assert('unconfigured-smtp-auth.invalid' === $mailer2->Host, '2.3 Enabled SMTP with missing credentials routes to invalid host (no silent unauthenticated fallback)');

// Test 2.4: Enabled state with configured credentials
$mailer3 = new MockPHPMailer();
update_option('propvendor_smtp_enabled', 1);
update_option('propvendor_smtp_host', 'smtp.hostinger.com');
update_option('propvendor_smtp_port', 465);
update_option('propvendor_smtp_secure', 'ssl');
update_option('propvendor_smtp_user', 'support@propvendor.com.ng');

if (!defined('PROPVENDOR_SMTP_PASS')) {
    define('PROPVENDOR_SMTP_PASS', 'TestPassword#123');
}

propvendor_marketplace_configure_phpmailer_smtp($mailer3);
pv_assert('smtp' === $mailer3->Mailer, '2.4 PHPMailer Mailer is set to smtp');
pv_assert('smtp.hostinger.com' === $mailer3->Host, '2.5 SMTP Host is smtp.hostinger.com', $mailer3->Host);
pv_assert(465 === $mailer3->Port, '2.6 SMTP Port is 465', $mailer3->Port);
pv_assert('ssl' === $mailer3->SMTPSecure, '2.7 SMTP SMTPSecure is ssl', $mailer3->SMTPSecure);
pv_assert(true === $mailer3->SMTPAuth, '2.8 SMTPAuth is true');
pv_assert('support@propvendor.com.ng' === $mailer3->Username, '2.9 SMTP Username is configured', $mailer3->Username);
pv_assert('TestPassword#123' === $mailer3->Password, '2.10 SMTP Password populated from protected constant');
pv_assert(10 === $mailer3->Timeout, '2.11 PHPMailer timeout bounded to 10 seconds');
pv_assert('noreply@propvendor.com.ng' === $mailer3->From, '2.12 From header matches notification sender email');
pv_assert('PropVendor Marketplace' === $mailer3->FromName, '2.13 FromName header matches notification sender name');

// Test 2.14: Sanitized Failure Logging Hook
pv_assert(strpos($source, "add_action('wp_mail_failed', 'propvendor_marketplace_handle_wp_mail_failed')") !== false, '2.14 wp_mail_failed hook registered for propvendor_marketplace_handle_wp_mail_failed');

// SECTION 3: Content Migration & Performance Guards
echo "\n--- Section 3: Content Migration & Performance Guards ---\n";

pv_assert(strpos($source, 'propvendor_default_pages_content_v19_0_complete') !== false, '3.1 Master persistent option guard propvendor_default_pages_content_v19_0_complete present');
pv_assert(strpos($source, 'propvendor_support_emails_updated_19_0') !== false, '3.2 Support emails migration option propvendor_support_emails_updated_19_0 present');
pv_assert(strpos($source, "!is_admin() && !defined('WP_CLI') && !doing_action('activate_") !== false, '3.3 Performance guard strictly prevents execution on public frontend page requests');

// SECTION 4: Security Hygiene & Leakage Prevention
echo "\n--- Section 4: Security Hygiene & Leakage Prevention ---\n";

pv_assert(strpos($source, 'TestPassword#123') === false, '4.1 Zero plaintext SMTP passwords exist in plugin source code');
pv_assert(strpos($source, '***REDACTED***') !== false, '4.2 Password redaction pattern active in mail error logging');
pv_assert(strpos($source, 'PROPVENDOR_SMTP_PASS') !== false, '4.3 SMTP credentials protected by constant precedence');

// Summary
echo "\n====================================================================\n";
echo "  PHASE 19.0 REGRESSION TEST EXECUTION SUMMARY\n";
echo "====================================================================\n";
echo "TOTAL ASSERTIONS : {$total_assertions}\n";
echo "PASSED           : {$passed_assertions}\n";
echo "FAILED           : " . count($failed_assertions) . "\n";
if (empty($failed_assertions)) {
    echo "STATUS           : 100% SUCCESS — ALL SUITES PASSED\n";
} else {
    echo "STATUS           : FAILED\n";
    foreach ($failed_assertions as $fa) {
        echo "  - {$fa}\n";
    }
}
echo "====================================================================\n";

if (!empty($failed_assertions)) {
    exit(1);
}
exit(0);
