<?php
/**
 * Master Test Runner — รันทุก test suite แล้วสรุปผล
 * 
 * Usage: php tests/run_all_tests.php [admin_password]
 * 
 * รัน 3 suites:
 * 1. Service Tests    — ทดสอบ business logic ผ่าน Service Layer (27 tests)
 * 2. DB Constraint    — ทดสอบ CHECK/UNIQUE/FK RESTRICT (11 tests)
 * 3. HTTP Integration — ทดสอบ endpoints ผ่าน curl (55 tests)
 * 
 * ⚠️ CLI only — ต้องเปิด Apache ก่อนรัน HTTP tests
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$adminPassword = $argv[1] ?? 'password';
$startTime = microtime(true);

echo "\n";
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║         QA FULL TEST SUITE — " . date('Y-m-d H:i:s') . "          ║\n";
echo "╠══════════════════════════════════════════════════════════╣\n";
echo "║  Suite 1: Service-Level Tests (PHP direct)              ║\n";
echo "║  Suite 2: DB Constraint Tests (SQL direct)              ║\n";
echo "║  Suite 2b: Deadlock Retry Tests (helper logic)          ║\n";
echo "║  Suite 2c: Pagination Tests (LIMIT/OFFSET correctness)  ║\n";
echo "║  Suite 2d: Search Index Tests (FULLTEXT ภาษาไทย)        ║\n";
echo "║  Suite 2e: Offline Assets Tests (ไม่พึ่ง CDN)           ║\n";
echo "║  Suite 3: HTTP Integration Tests (curl via Apache)      ║\n";
echo "║  Suite 4: Upload Security Tests (real files)            ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n";

$suiteResults = [];

// ============================================================
// Helper: Run a test file and capture exit code
// ============================================================
function runSuite(string $name, string $file, string $extraArgs = ''): array {
    $phpPath = PHP_BINARY;
    $cmd = "\"$phpPath\" \"$file\" $extraArgs 2>&1";
    
    echo "\n\033[1;33m▶ Running: $name\033[0m\n";
    echo str_repeat('─', 56) . "\n";
    
    $output = [];
    $exitCode = 0;
    exec($cmd, $output, $exitCode);
    
    $fullOutput = implode("\n", $output);
    echo $fullOutput . "\n";
    
    // Parse results from output
    $passed = 0; $failed = 0; $total = 0;
    if (preg_match('/(\d+)\/(\d+)\s+passed\s+\((\d+\.?\d*)%\)/', $fullOutput, $m)) {
        $passed = (int) $m[1];
        $total = (int) $m[2];
    }
    if (preg_match('/(\d+)\s+FAILED/', $fullOutput, $m)) {
        $failed = (int) $m[1];
    }
    
    return [
        'name' => $name,
        'passed' => $passed,
        'failed' => $failed,
        'total' => $total,
        'exit_code' => $exitCode,
        'success' => $exitCode === 0
    ];
}

// ============================================================
// RUN SUITES
// ============================================================

// Suite 1: Service Tests
$suiteResults[] = runSuite(
    'Service-Level Tests',
    __DIR__ . '/service_test.php'
);

// Suite 2: DB Constraint Tests
$suiteResults[] = runSuite(
    'DB Constraint Tests',
    __DIR__ . '/db_constraint_test.php'
);

// Suite 2b: Deadlock Retry Tests (ไม่ต้องใช้ Apache — ทดสอบตรรกะ helper ล้วน)
$suiteResults[] = runSuite(
    'Deadlock Retry Tests',
    __DIR__ . '/test_deadlock_retry.php'
);

// Suite 2c: Pagination Tests (ไม่ต้องใช้ Apache — อ่านข้อมูลอย่างเดียว)
$suiteResults[] = runSuite(
    'Pagination Tests',
    __DIR__ . '/test_pagination.php'
);

// Suite 2d: Search Index Tests (FULLTEXT trigram — ไม่ต้องใช้ Apache)
$suiteResults[] = runSuite(
    'Search Index Tests',
    __DIR__ . '/test_search_index.php'
);

// Suite 2e: Offline Assets Tests (กัน CDN หลุดกลับเข้ามา — ไม่ต้องใช้ Apache/DB)
$suiteResults[] = runSuite(
    'Offline Assets Tests',
    __DIR__ . '/test_offline_assets.php'
);

// Suite 3: HTTP Integration Tests
// Check if Apache is running first
$ch = curl_init('http://localhost/book_borrowing/login.php');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_NOBODY => true]);
curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode > 0) {
    $suiteResults[] = runSuite(
        'HTTP Integration Tests',
        __DIR__ . '/qa_test_runner.php',
        escapeshellarg($adminPassword)
    );
} else {
    echo "\n\033[1;33m▶ Running: HTTP Integration Tests\033[0m\n";
    echo str_repeat('─', 56) . "\n";
    echo "  \033[33m⏭ SKIPPED — Apache not running at localhost\033[0m\n";
    echo "  Start Apache first, then re-run.\n";
    $suiteResults[] = [
        'name' => 'HTTP Integration Tests',
        'passed' => 0, 'failed' => 0, 'total' => 0,
        'exit_code' => -1, 'success' => false
    ];
}

// Suite 4: Upload Security Tests — ต้องใช้ Apache เหมือน Suite 3
//   ทดสอบด่านกรองไฟล์ด้วยไฟล์จริง + ยืนยันว่า uploads/.htaccess ยังกัน PHP อยู่
if ($httpCode > 0) {
    $suiteResults[] = runSuite(
        'Upload Security Tests',
        __DIR__ . '/test_upload_security.php',
        escapeshellarg($adminPassword)
    );
} else {
    echo "\n\033[1;33m▶ Running: Upload Security Tests\033[0m\n";
    echo str_repeat('─', 56) . "\n";
    echo "  \033[33m⏭ SKIPPED — Apache not running at localhost\033[0m\n";
    $suiteResults[] = [
        'name' => 'Upload Security Tests',
        'passed' => 0, 'failed' => 0, 'total' => 0,
        'exit_code' => -1, 'success' => false
    ];
}

// ============================================================
// GRAND SUMMARY
// ============================================================
$elapsed = round(microtime(true) - $startTime, 1);
$grandTotal = array_sum(array_column($suiteResults, 'total'));
$grandPass = array_sum(array_column($suiteResults, 'passed'));
$grandFail = array_sum(array_column($suiteResults, 'failed'));
$grandPct = $grandTotal > 0 ? round($grandPass / $grandTotal * 100, 1) : 0;

echo "\n";
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║                   GRAND SUMMARY                         ║\n";
echo "╠══════════════════════════════════════════════════════════╣\n";

foreach ($suiteResults as $s) {
    $icon = $s['exit_code'] === 0 ? '✅' : ($s['exit_code'] === -1 ? '⏭' : '❌');
    $line = sprintf("  %s %-30s %d/%d passed", $icon, $s['name'], $s['passed'], $s['total']);
    if ($s['failed'] > 0) $line .= " ({$s['failed']} failed)";
    echo "║" . str_pad($line, 57) . "║\n";
}

echo "╠══════════════════════════════════════════════════════════╣\n";
$summaryLine = "  TOTAL: $grandPass/$grandTotal passed ($grandPct%)";
if ($grandFail > 0) $summaryLine .= " | $grandFail FAILED";
$summaryLine .= " | {$elapsed}s";
echo "║" . str_pad($summaryLine, 57) . "║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

// Save summary JSON
$summaryFile = __DIR__ . '/logs/full_suite_' . date('Y-m-d_His') . '.json';
if (!is_dir(__DIR__ . '/logs')) mkdir(__DIR__ . '/logs', 0755, true);
file_put_contents($summaryFile, json_encode([
    'run_at' => date('c'),
    'elapsed_seconds' => $elapsed,
    'grand_total' => $grandTotal,
    'grand_passed' => $grandPass,
    'grand_failed' => $grandFail,
    'pass_rate' => "$grandPct%",
    'suites' => $suiteResults
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "📄 Summary saved: $summaryFile\n\n";

exit($grandFail > 0 ? 1 : 0);
