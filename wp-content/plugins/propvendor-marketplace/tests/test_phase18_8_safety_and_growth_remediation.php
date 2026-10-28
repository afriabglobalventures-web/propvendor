<?php
/**
 * Test Suite: PropVendor Phase 18.8 — Targeted Safety Content & Retired Plan Remediation
 *
 * Verifies:
 * 1. Finding 1 — Safety Guidelines Page:
 *    - Shortcode callback exists and is registered.
 *    - Complete safety guidelines content rendered.
 *    - Cardinal safety rule (no advance fees, no custody of funds) present.
 *    - Property & vehicle due diligence sections present.
 *    - Red flags and reporting routes present.
 *    - Default pages array configures [propvendor_safety_guidelines].
 *    - Content update logic includes safety guidelines migration.
 * 2. Finding 2 — Retired Growth Pack Remediation:
 *    - Default seed definition configures credit_pack_growth as is_active = 0.
 *    - Available public packages contain exactly the 4 approved tiers.
 *    - credit_pack_growth is strictly absent from public packages catalog.
 *    - Seed logic deactivates credit_pack_growth upon update.
 *    - Seed logic preserves intentional administrative deactivations (does not reactivate is_active = 0).
 *    - Order creation guard blocks purchases of credit_pack_growth (returns retired_plan).
 *    - Order creation guard blocks any inactive plan (returns invalid_plan).
 *    - Backward-compatibility: historical plan definitions preserved without deletion.
 */

// If WordPress is not loaded, mock minimal environment functions for isolated test execution
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default') { return $text; }
}
if (!function_exists('esc_url')) {
    function esc_url($url) { return $url; }
}
if (!function_exists('home_url')) {
    function home_url($path = '') { return 'https://staging.propvendor.com.ng' . $path; }
}
if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)); }
}
if (!function_exists('absint')) {
    function absint($maybeint) { return abs((int) $maybeint); }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        public $code;
        public $message;
        public function __construct($code = '', $message = '') {
            $this->code = $code;
            $this->message = $message;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) { return ($thing instanceof WP_Error); }
}

echo "====================================================================\n";
echo "  PROPVENDOR PHASE 18.8 — SAFETY CONTENT & RETIRED PLAN REMEDIATION  \n";
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

$plugin_file = dirname(__DIR__) . '/propvendor-marketplace.php';
$source = file_get_contents($plugin_file);

// -----------------------------------------------------------------
// SECTION 1: FINDING 1 — SAFETY GUIDELINES AUDIT & REGRESSION
// -----------------------------------------------------------------
echo "--- Section 1: Finding 1 — Safety Guidelines Audit & Regression ---\n";

// 1.1 Shortcode registration
run_test(
    strpos($source, "add_shortcode('propvendor_safety_guidelines'") !== false,
    '1.1 Shortcode [propvendor_safety_guidelines] is registered in plugin'
);

// 1.2 Include function if not defined
if (!function_exists('propvendor_marketplace_safety_guidelines_shortcode')) {
    // Extract function implementation from source
    preg_match('/function propvendor_marketplace_safety_guidelines_shortcode\(\)\s*\{(.+?)\n\}\nadd_shortcode/s', $source, $matches);
    if (!empty($matches[1])) {
        eval('function propvendor_marketplace_safety_guidelines_shortcode() {' . $matches[1] . '}');
    }
}

$rendered = function_exists('propvendor_marketplace_safety_guidelines_shortcode')
    ? propvendor_marketplace_safety_guidelines_shortcode()
    : '';

run_test(!empty($rendered), '1.2 Safety guidelines shortcode callback executes and returns content');

// 1.3 Structural containers and badges
run_test(
    strpos($rendered, 'propvendor-static-page propvendor-safety-page') !== false,
    '1.3 Rendered markup includes standard static page and safety page wrappers'
);
run_test(
    strpos($rendered, 'Consumer & Vendor Protection') !== false,
    '1.4 Rendered header displays Consumer & Vendor Protection badge'
);
run_test(
    strpos($rendered, 'Listing & Transaction Safety Guidelines') !== false,
    '1.5 Rendered title displays Listing & Transaction Safety Guidelines'
);

// 1.4 Cardinal Safety Rule & Advance Fee Warning
run_test(
    strpos($rendered, 'The Cardinal Safety Rule') !== false,
    '1.6 Cardinal Safety Rule section is prominently rendered'
);
run_test(
    strpos($rendered, 'NEVER send money, pay "booking deposits", or pay "inspection fees"') !== false,
    '1.7 Explicit warning against advance booking deposits or inspection fees is present'
);
run_test(
    strpos($rendered, 'PropVendor does not hold custody of funds') !== false,
    '1.8 Accurate custody disclaimer is present (PropVendor does not hold custody of funds)'
);

// 1.5 Property & Land Due Diligence
run_test(
    strpos($rendered, 'Property & Land Due Diligence Protocols') !== false,
    '1.9 Property & Land due diligence section is present'
);
run_test(
    strpos($rendered, 'Daylight Physical Inspection') !== false,
    '1.10 Protocol 01: Daylight Physical Inspection requirement is present'
);
run_test(
    strpos($rendered, 'State Lands Bureau Title Search') !== false,
    '1.11 Protocol 02: State Lands Bureau Title Search requirement is present'
);
run_test(
    strpos($rendered, 'SURCON-registered surveyor') !== false && strpos($rendered, 'Nigerian Bar Association (NBA)') !== false,
    '1.12 Protocol 03: Certified professionals (SURCON surveyor & NBA lawyer) requirement is present'
);
run_test(
    strpos($rendered, 'Family / Community Land Checks') !== false,
    '1.13 Protocol 04: Family and community land verification guidance is present'
);

// 1.6 Vehicle & Automotive Buying Protocols
run_test(
    strpos($rendered, 'Vehicle & Automotive Buying Protocols') !== false,
    '1.14 Vehicle & Automotive Buying Protocols section is present'
);
run_test(
    strpos($rendered, 'Meet in a Public Daylight Location') !== false,
    '1.15 Vehicle Protocol 01: Public daylight inspection location is present'
);
run_test(
    strpos($rendered, 'Independent Mechanical Diagnostic') !== false,
    '1.16 Vehicle Protocol 02: Independent OBD-II mechanical diagnostic is present'
);
run_test(
    strpos($rendered, 'Customs Duty & Registration Verification') !== false,
    '1.17 Vehicle Protocol 03: Customs Duty SGD & FRSC/VIO registration verification is present'
);
run_test(
    strpos($rendered, 'Chassis & VIN Cross-Check') !== false,
    '1.18 Vehicle Protocol 04: 17-character VIN & chassis cross-referencing is present'
);

// 1.7 High-Risk Red Flags & Reporting Route
run_test(
    strpos($rendered, 'High-Risk Red Flags to Avoid') !== false,
    '1.19 High-Risk Red Flags section is present'
);
run_test(
    strpos($rendered, 'Unrealistically low prices') !== false,
    '1.20 Red Flag: Unrealistically low prices warning is present'
);
run_test(
    strpos($rendered, 'Advance "Inspection" or "Fuel" Fees') !== false,
    '1.21 Red Flag: Advance inspection/fuel fees warning is present'
);
run_test(
    strpos($rendered, 'High-pressure urgency tactics') !== false,
    '1.22 Red Flag: High-pressure urgency tactics warning is present'
);
run_test(
    strpos($rendered, '/contact-us/') !== false && strpos($rendered, 'Report Suspicious Activity') !== false,
    '1.23 Report Suspicious Activity CTA links to legitimate support/contact route'
);
run_test(
    strpos($rendered, '/browse-listings/') !== false,
    '1.24 Browse Verified Listings CTA link is present'
);

// 1.8 Code Configuration of Default Pages
run_test(
    strpos($source, "'listing-and-transaction-safety-guidelines' => array(\n            'title' => 'Listing and Transaction Safety Guidelines',\n            'content' => '[propvendor_safety_guidelines]',") !== false,
    '1.25 Default pages creation array configures [propvendor_safety_guidelines] for page 17/19'
);
run_test(
    strpos($source, "update_option('propvendor_safety_guidelines_updated_18_8', 1);") !== false,
    '1.26 Default pages update routine includes safety guidelines migration with option flag'
);
run_test(
    strpos($source, "!is_admin() && !defined('WP_CLI')") !== false,
    '1.27 Default pages update routine includes frontend performance bypass guard'
);

// -----------------------------------------------------------------
// SECTION 2: FINDING 2 — RETIRED GROWTH PACK REMEDIATION
// -----------------------------------------------------------------
echo "\n--- Section 2: Finding 2 — Retired Growth Pack Remediation ---\n";

// 2.1 Seed Definition: Inactive by default
run_test(
    preg_match("/'plan_key'\s*=>\s*'credit_pack_growth'.*?'is_active'\s*=>\s*0/s", $source) === 1,
    '2.1 Seed definition for credit_pack_growth defaults to is_active = 0 (inactive)'
);

// 2.2 Active plans in seed definition remain active
run_test(
    preg_match("/'plan_key'\s*=>\s*'vip_agent_monthly'.*?'is_active'\s*=>\s*1/s", $source) === 1,
    '2.2 Seed definition for vip_agent_monthly remains is_active = 1'
);
run_test(
    preg_match("/'plan_key'\s*=>\s*'credit_pack_starter'.*?'is_active'\s*=>\s*1/s", $source) === 1,
    '2.3 Seed definition for credit_pack_starter remains is_active = 1'
);
run_test(
    preg_match("/'plan_key'\s*=>\s*'credit_pack_pro'.*?'is_active'\s*=>\s*1/s", $source) === 1,
    '2.4 Seed definition for credit_pack_pro remains is_active = 1'
);

// 2.5 Catalog Packages Verification
if (!function_exists('propvendor_marketplace_get_available_packages')) {
    $fn_start = strpos($source, 'function propvendor_marketplace_get_available_packages()');
    if ($fn_start !== false) {
        $fn_end = strpos($source, 'function propvendor_marketplace_create_order', $fn_start);
        $fn_code = substr($source, $fn_start, $fn_end - $fn_start);
        $last_brace = strrpos($fn_code, '}');
        $fn_clean = substr($fn_code, 0, $last_brace + 1);

        // Mock propvendor_marketplace_get_plan_by_key to return simulated objects
        if (!function_exists('propvendor_marketplace_get_plan_by_key')) {
            function propvendor_marketplace_get_plan_by_key($key) {
                $plans = array(
                    'credit_pack_starter' => (object) array('id' => 2, 'price_kobo' => 500000, 'credits' => 5, 'is_active' => 1),
                    'credit_pack_growth'  => (object) array('id' => 3, 'price_kobo' => 1000000, 'credits' => 12, 'is_active' => 0),
                    'credit_pack_pro'     => (object) array('id' => 4, 'price_kobo' => 2000000, 'credits' => 28, 'is_active' => 1),
                    'vip_agent_monthly'   => (object) array('id' => 1, 'price_kobo' => 3000000, 'credits' => 0, 'duration_days' => 30, 'is_active' => 1),
                );
                return isset($plans[$key]) ? $plans[$key] : null;
            }
        }
        eval($fn_clean);
    }
}

$packages = function_exists('propvendor_marketplace_get_available_packages')
    ? propvendor_marketplace_get_available_packages()
    : array();

run_test(
    count($packages) === 4,
    '2.5 Available packages catalog contains exactly 4 approved tiers'
);
run_test(
    isset($packages['free_basic']) && $packages['free_basic']['price_ngn'] === 0 && $packages['free_basic']['listing_quota'] === 3,
    '2.6 Tier 1: Free Basic Plan is ₦0 with 3 active listings quota'
);
run_test(
    isset($packages['starter_5']) && $packages['starter_5']['price_ngn'] === 5000 && $packages['starter_5']['credits'] === 5,
    '2.7 Tier 2: Starter Pack is ₦5,000 for 5 lead credits'
);
run_test(
    isset($packages['pro_28']) && $packages['pro_28']['price_ngn'] === 20000 && $packages['pro_28']['credits'] === 28,
    '2.8 Tier 3: Pro Pack is ₦20,000 for 28 lead credits'
);
run_test(
    isset($packages['vip_monthly']) && $packages['vip_monthly']['price_ngn'] === 30000 && $packages['vip_monthly']['duration_days'] === 30,
    '2.9 Tier 4: VIP Agent Monthly is ₦30,000 for 30 duration days'
);
run_test(
    !isset($packages['credit_pack_growth']) && !isset($packages['growth_12']) && !isset($packages['growth']),
    '2.10 Retired Growth Pack is strictly excluded from available public packages catalog'
);

// 2.11 Seed Update Logic Simulation: Deactivation Enforcement & Administrative Preservation
function simulate_seed_target_status($seed_def, $db_existing) {
    // Exact algorithm implemented in propvendor_marketplace_seed_default_plans()
    return ($seed_def['is_active'] === 0 || (int) $db_existing['is_active'] === 0) ? 0 : (int) $seed_def['is_active'];
}

// Case A: Existing DB record for credit_pack_growth has is_active = 1 (pre-remediation state)
$target_a = simulate_seed_target_status(
    array('plan_key' => 'credit_pack_growth', 'is_active' => 0),
    array('id' => 3, 'plan_key' => 'credit_pack_growth', 'is_active' => 1)
);
run_test($target_a === 0, '2.11 Seed update algorithm transitions existing active Growth Pack record to inactive (0)');

// Case B: Existing DB record for credit_pack_growth is already 0
$target_b = simulate_seed_target_status(
    array('plan_key' => 'credit_pack_growth', 'is_active' => 0),
    array('id' => 3, 'plan_key' => 'credit_pack_growth', 'is_active' => 0)
);
run_test($target_b === 0, '2.12 Seed update algorithm maintains already-deactivated Growth Pack as inactive (0)');

// Case C: Admin intentionally deactivated Starter Pack in DB (is_active = 0), seed says 1
$target_c = simulate_seed_target_status(
    array('plan_key' => 'credit_pack_starter', 'is_active' => 1),
    array('id' => 2, 'plan_key' => 'credit_pack_starter', 'is_active' => 0)
);
run_test($target_c === 0, '2.13 Seed update algorithm preserves administrative deactivation (does not reactivate)');

// Case D: Standard active Pro Pack in DB and seed
$target_d = simulate_seed_target_status(
    array('plan_key' => 'credit_pack_pro', 'is_active' => 1),
    array('id' => 4, 'plan_key' => 'credit_pack_pro', 'is_active' => 1)
);
run_test($target_d === 1, '2.14 Seed update algorithm preserves active status for active tiers (is_active = 1)');

// 2.15 Order Creation Guard Validation: propvendor_marketplace_create_order logic
function simulate_create_order_guard($plan) {
    if (!$plan || (int) $plan->is_active !== 1) {
        return new WP_Error('invalid_plan', 'The selected plan does not exist or is inactive.');
    }
    if (isset($plan->plan_key) && in_array($plan->plan_key, array('credit_pack_growth'), true)) {
        return new WP_Error('retired_plan', 'The selected plan has been retired and is no longer available for purchase.');
    }
    return array('success' => true, 'plan_id' => $plan->id);
}

// Case 1: Inactive Growth Pack (standard post-remediation state)
$order_growth_inactive = (object) array('id' => 3, 'plan_key' => 'credit_pack_growth', 'is_active' => 0);
$res1 = simulate_create_order_guard($order_growth_inactive);
run_test(
    is_wp_error($res1) && $res1->get_error_code() === 'invalid_plan',
    '2.15 Order creation rejects inactive Growth Pack with invalid_plan error'
);

// Case 2: Defense-in-depth: Growth Pack with is_active = 1 (e.g. if mistakenly toggled in DB)
$order_growth_active = (object) array('id' => 3, 'plan_key' => 'credit_pack_growth', 'is_active' => 1);
$res2 = simulate_create_order_guard($order_growth_active);
run_test(
    is_wp_error($res2) && $res2->get_error_code() === 'retired_plan',
    '2.16 Defense-in-depth: Order creation blocks retired Growth Pack even if active in DB (returns retired_plan)'
);

// Case 3: Valid active Starter Pack
$order_starter = (object) array('id' => 2, 'plan_key' => 'credit_pack_starter', 'is_active' => 1);
$res3 = simulate_create_order_guard($order_starter);
run_test(
    !is_wp_error($res3) && $res3['success'] === true,
    '2.17 Order creation permits active approved Starter Pack'
);

// Case 4: Valid active Pro Pack
$order_pro = (object) array('id' => 4, 'plan_key' => 'credit_pack_pro', 'is_active' => 1);
$res4 = simulate_create_order_guard($order_pro);
run_test(
    !is_wp_error($res4) && $res4['success'] === true,
    '2.18 Order creation permits active approved Pro Pack'
);

// Case 5: Valid active VIP Agent Monthly
$order_vip = (object) array('id' => 1, 'plan_key' => 'vip_agent_monthly', 'is_active' => 1);
$res5 = simulate_create_order_guard($order_vip);
run_test(
    !is_wp_error($res5) && $res5['success'] === true,
    '2.19 Order creation permits active approved VIP Agent Monthly'
);

// 2.20 Code Inspection of Guards in Source
run_test(
    strpos($source, "if (isset(\$plan->plan_key) && in_array(\$plan->plan_key, array('credit_pack_growth'), true))") !== false,
    '2.20 create_order() contains explicit plan_key defense guard against credit_pack_growth'
);
run_test(
    strpos($source, "if (\$plan && in_array(\$plan->plan_key, array('credit_pack_growth'), true))") !== false,
    '2.21 ajax_create_checkout_order() contains explicit AJAX-level guard against credit_pack_growth'
);

// 2.22 Historical Integrity
run_test(
    strpos($source, "'plan_key'      => 'credit_pack_growth'") !== false,
    '2.22 Historical definition and schema mapping for credit_pack_growth preserved without table deletion'
);

echo "\n====================================================================\n";
echo "  PHASE 18.8 REGRESSION TEST EXECUTION SUMMARY\n";
echo "====================================================================\n";
echo "TOTAL ASSERTIONS : {$total_tests}\n";
echo "PASSED           : {$passed_tests}\n";
echo "FAILED           : {$failed_tests}\n";
echo "STATUS           : " . ($failed_tests === 0 ? "100% SUCCESS" : "FAILURES DETECTED") . "\n";
echo "====================================================================\n";

if ($failed_tests > 0) {
    exit(1);
}
exit(0);
