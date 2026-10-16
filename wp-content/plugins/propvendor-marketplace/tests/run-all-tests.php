<?php
/**
 * PropVendor Master 1-Click Test Runner
 *
 * Discovers and executes all 13 regression test suites in isolated PHP subprocesses,
 * collecting assertion counts, execution times, and exit statuses into a unified report.
 *
 * Usage:
 *   php run-all-tests.php
 *   php run-all-tests.php --verbose
 *   php run-all-tests.php --filter=quotas
 */

// 1. Guard against direct web execution
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Access Denied: The test runner can only be executed via the PHP command-line interface.\n");
}

// 2. Determine PHP executable
$php_bin = PHP_BINARY;
if (empty($php_bin) || !file_exists($php_bin) || stripos(basename($php_bin), 'php-cgi') !== false) {
    if (file_exists('C:\\xampp\\php\\php.exe')) {
        $php_bin = 'C:\\xampp\\php\\php.exe';
    } else {
        $php_bin = 'php';
    }
}

// Parse command-line options
$options = getopt('v::', ['verbose::', 'filter::', 'help']);
if (isset($options['help'])) {
    echo "PropVendor Test Runner\n";
    echo "Usage:\n";
    echo "  php run-all-tests.php            Run all 9 regression suites\n";
    echo "  php run-all-tests.php --verbose  Display full assertion outputs from all suites\n";
    echo "  php run-all-tests.php --filter=X Run only suites matching pattern X\n";
    exit(0);
}

$verbose = isset($options['v']) || isset($options['verbose']);
$filter  = isset($options['filter']) ? trim($options['filter']) : '';

$test_dir = __DIR__;

// Define test suites in canonical dependency and verification order
$suites = [
    [
        'file'                => 'test_stage3_listing_quotas.php',
        'name'                => 'Stage 3: Listing Quotas & VIP Entitlement',
        'expected_assertions' => 61,
    ],
    [
        'file'                => 'test_ui_fixes_and_category_logic.php',
        'name'                => 'UI Fixes, Standardized Units & Category Logic',
        'expected_assertions' => 90,
    ],
    [
        'file'                => 'test_location_schema_enhancement.php',
        'name'                => 'Location Schema (37-State Dropdown & Address)',
        'expected_assertions' => 63,
    ],
    [
        'file'                => 'test_edit_form_enhancements_and_safeguards.php',
        'name'                => 'Edit Form Enhancements & Input Safeguards',
        'expected_assertions' => 33,
    ],
    [
        'file'                => 'test_dynamic_forms_verification.php',
        'name'                => 'Dynamic Forms & Category Schema Verification',
        'expected_assertions' => 53,
    ],
    [
        'file'                => 'test_dynamic_forms_edge_cases.php',
        'name'                => 'Dynamic Forms Edge Cases & Validation Rules',
        'expected_assertions' => 15,
    ],
    [
        'file'                => 'test_stage2_verification.php',
        'name'                => 'Stage 2: Trust Badges, Regulatory & Disclaimers',
        'expected_assertions' => 40,
    ],
    [
        'file'                => 'test_stage4_search_filters.php',
        'name'                => 'Stage 4: Search & Discovery Filters',
        'expected_assertions' => 61,
    ],
    [
        'file'                => 'test_stage5_entitlements_and_ledger.php',
        'name'                => 'Stage 5: Credit Ledger & Lead Entitlements',
        'expected_assertions' => 98,
    ],
    [
        'file'                => 'test_stage5b_payments.php',
        'name'                => 'Phase 4B: Payment Gateway Integration & Checkout',
        'expected_assertions' => 99,
    ],
    [
        'file'                => 'test_phase6a_notifications.php',
        'name'                => 'Phase 6A: Secure Automated Email Notifications',
        'expected_assertions' => 71,
    ],
    [
        'file'                => 'test_phase7a_security_audit.php',
        'name'                => 'Phase 7A: Production Security & Reliability Audit',
        'expected_assertions' => 44,
    ],
    [
        'file'                => 'test_phase7b_cleanup_and_readiness.php',
        'name'                => 'Phase 7B: Stale Order Cleanup & Deployment Readiness',
        'expected_assertions' => 42,
    ],
];

if (!empty($filter)) {
    $suites = array_values(array_filter($suites, function($s) use ($filter) {
        return stripos($s['file'], $filter) !== false || stripos($s['name'], $filter) !== false;
    }));
    if (empty($suites)) {
        echo "No test suites matched filter: '{$filter}'\n";
        exit(1);
    }
}

echo "====================================================================\n";
echo "  PROPVENDOR MASTER 1-CLICK TEST RUNNER                             \n";
echo "====================================================================\n";
echo "PHP Binary : " . $php_bin . "\n";
echo "Test Dir   : " . $test_dir . "\n";
echo "Suites     : " . count($suites) . " active suite(s)\n";
echo "Mode       : " . ($verbose ? "Verbose (Full Output)" : "Standard (Clean Progress)") . "\n";
echo "--------------------------------------------------------------------\n\n";

$start_time       = microtime(true);
$results          = [];
$grand_passed     = 0;
$grand_failed     = 0;
$grand_expected   = 0;
$suites_passed    = 0;
$suites_failed    = 0;

foreach ($suites as $index => $suite) {
    $suite_num  = $index + 1;
    $file_path  = $test_dir . DIRECTORY_SEPARATOR . $suite['file'];
    $suite_name = $suite['name'];
    $expected   = $suite['expected_assertions'];
    $grand_expected += $expected;

    if (!file_exists($file_path)) {
        echo sprintf("[%d/%d] [MISSING] %s (%s)\n", $suite_num, count($suites), $suite_name, $suite['file']);
        $results[] = [
            'name'       => $suite_name,
            'file'       => $suite['file'],
            'status'     => 'MISSING',
            'passed'     => 0,
            'failed'     => $expected,
            'expected'   => $expected,
            'duration'   => 0.0,
            'error_msg'  => 'File not found on disk',
        ];
        $suites_failed++;
        $grand_failed += $expected;
        continue;
    }

    echo sprintf("[%d/%d] Running: %s ... ", $suite_num, count($suites), $suite_name);
    flush();

    $cmd = escapeshellarg($php_bin) . ' ' . escapeshellarg($file_path);
    $suite_start = microtime(true);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($cmd, $descriptors, $pipes, $test_dir);
    $stdout = '';
    $stderr = '';

    if (is_resource($process)) {
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);
    } else {
        $exit_code = -1;
        $stderr = "Failed to launch subprocess.";
    }

    $suite_duration = microtime(true) - $suite_start;

    // Parse assertion results from stdout
    $passed = 0;
    $failed = 0;

    // Check for "SUMMARY: X PASSED, Y FAILED"
    if (preg_match('/SUMMARY:\s*(\d+)\s*PASSED,\s*(\d+)\s*FAILED/i', $stdout, $matches)) {
        $passed = (int) $matches[1];
        $failed = (int) $matches[2];
    }
    // Check for "STAGE 2 TEST SUMMARY: X/Y PASSED"
    elseif (preg_match('/STAGE 2 TEST SUMMARY:\s*(\d+)\/(\d+)\s*PASSED/i', $stdout, $matches)) {
        $passed = (int) $matches[1];
        $total  = (int) $matches[2];
        $failed = max(0, $total - $passed);
    } else {
        // Fallback: count [PASS] / [FAIL]
        $passed = preg_match_all('/\[PASS\]/i', $stdout);
        $failed = preg_match_all('/\[FAIL\]/i', $stdout);
    }

    $is_pass = ($exit_code === 0 && $failed === 0 && $passed > 0);

    if ($is_pass) {
        $suites_passed++;
        echo sprintf("PASSED (%d/%d assertions) [%.2fs]\n", $passed, $expected, $suite_duration);
    } else {
        $suites_failed++;
        echo sprintf("FAILED (%d passed, %d failed, exit code %d) [%.2fs]\n", $passed, $failed, $exit_code, $suite_duration);
    }

    if ($verbose || !$is_pass) {
        echo "--------------------------------------------------------------------\n";
        echo trim($stdout) . "\n";
        if (!empty($stderr)) {
            // Filter out known harmless openssl startup warning
            $clean_stderr = preg_replace('/PHP Warning:\s*Module "openssl" is already loaded[^\r\n]*/i', '', $stderr);
            $clean_stderr = trim($clean_stderr);
            if (!empty($clean_stderr)) {
                echo "[STDERR]\n" . $clean_stderr . "\n";
            }
        }
        echo "--------------------------------------------------------------------\n";
    }

    $grand_passed += $passed;
    $grand_failed += $failed;

    $results[] = [
        'name'       => $suite_name,
        'file'       => $suite['file'],
        'status'     => $is_pass ? 'PASS' : 'FAIL',
        'passed'     => $passed,
        'failed'     => $failed,
        'expected'   => $expected,
        'duration'   => $suite_duration,
        'exit_code'  => $exit_code,
    ];
}

$total_duration = microtime(true) - $start_time;

// Print Master Summary Table
echo "\n====================================================================\n";
echo "  MASTER REGRESSION TEST RESULTS                                    \n";
echo "====================================================================\n";
printf("%-4s %-46s %-8s %-9s %-8s\n", '#', 'Test Suite Name', 'Status', 'Passed', 'Time');
echo "--------------------------------------------------------------------\n";

foreach ($results as $i => $r) {
    $status_str = ($r['status'] === 'PASS') ? '[PASS]' : '[FAIL]';
    $tally_str  = sprintf("%d/%d", $r['passed'], $r['expected']);
    printf("%-4d %-46s %-8s %-9s %-7.2fs\n", $i + 1, $r['name'], $status_str, $tally_str, $r['duration']);
}

echo "--------------------------------------------------------------------\n";
printf("TOTAL SUITES     : %d passed, %d failed (%d total)\n", $suites_passed, $suites_failed, count($suites));
printf("TOTAL ASSERTIONS : %d / %d PASSED (%d failed)\n", $grand_passed, $grand_expected, $grand_failed);
printf("TOTAL TIME       : %.2f seconds\n", $total_duration);

if ($suites_failed === 0 && $grand_failed === 0 && $grand_passed === $grand_expected) {
    echo "VERDICT          : 100% SUCCESS — ALL TEST SUITES PASSED CLEANLY!\n";
    echo "====================================================================\n";
    exit(0);
} else {
    echo "VERDICT          : TEST SUITE FAILURES DETECTED — REVIEW LOGS ABOVE!\n";
    echo "====================================================================\n";
    exit(1);
}
