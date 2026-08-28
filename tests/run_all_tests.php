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

// 🧠 ต้องโหลด config ก่อนเพราะเช็ค Apache ด้วย APP_URL (ลูกค้าติดตั้งโฟลเดอร์ชื่ออะไรก็ได้)
require_once __DIR__ . '/../includes/config.php';

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
echo "║  Suite 3:  Gap Analysis 8 ชุด (SQLi/XSS/integrity/…)     ║\n";
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
    // 🧠 บางชุดพิมพ์ "RESULTS: 35/35 passed" เฉย ๆ ไม่มีเปอร์เซ็นต์ต่อท้าย
    //    ทำให้เปอร์เซ็นต์เป็นส่วนที่ "มีก็ได้ ไม่มีก็ได้" — ไม่งั้นชุดพวกนั้นจะโชว์ 0/0
    //    ทั้งที่ผ่านหมด ซึ่งอ่านแล้วเข้าใจผิดว่าไม่ได้รันอะไรเลย
    if (preg_match('/(\d+)\/(\d+)\s+passed(?:\s+\((\d+\.?\d*)%\))?/i', $fullOutput, $m)) {
        $passed = (int) $m[1];
        $total = (int) $m[2];
    }
    if (preg_match('/(\d+)\s+FAILED/', $fullOutput, $m)) {
        $failed = (int) $m[1];
    }

    // 🧠 ชุดรุ่นเก่าไม่ได้พิมพ์บรรทัดสรุปแบบ "N/N passed" — ถ้าไม่ทำอะไรจะขึ้น "0/0 passed"
    //    ซึ่งอ่านแล้วเข้าใจผิดว่าไม่ได้รันอะไรเลย จึงหาตัวเลขจากรูปแบบอื่นแทน
    $clean = preg_replace('/\x1b\[[0-9;]*m/', '', $fullOutput);

    // แบบที่ 2: "Passed: 17" + "Failed: 0" (แม่นกว่าการนับเครื่องหมาย)
    if ($total === 0
        && preg_match('/Passed:\s*(\d+)/i', $clean, $mp)
        && preg_match('/Failed:\s*(\d+)/i', $clean, $mf)
    ) {
        $passed = (int) $mp[1];
        $failed = (int) $mf[1];
        $total  = $passed + $failed;
    }

    // แบบที่ 3 (ท้ายสุด): นับเครื่องหมาย ✅/❌ ในผลลัพธ์
    // ⚠️ วิธีนี้ไม่แม่น — บางไฟล์พิมพ์ "Failed: 0 ❌" เป็นป้ายกำกับ แล้วจะถูกนับเป็นความล้มเหลว
    //    จึงต้องลองแบบที่ 2 ก่อนเสมอ
    if ($total === 0) {
        $passed = preg_match_all('/✅|\[PASS\]/u', $clean);
        $failed = preg_match_all('/❌|\[FAIL\]/u', $clean);
        $total  = $passed + $failed;
    }
    
    // 🔴 [สำคัญ] ชุดที่จบด้วย exit code ไม่ใช่ 0 แต่ parser หา "failed" ไม่เจอ
    //    = ตายกลางคัน (fatal error / uncaught exception) ก่อนพิมพ์บรรทัดสรุป
    //    ถ้าไม่ดักตรงนี้ เคสที่ผ่านไปแล้วก่อนตายจะถูกนับเข้ายอดรวม
    //    แล้วหัวตารางจะขึ้น "100%" ทั้งที่มีชุดหนึ่งพังยับ — เคยเกิดจริง:
    //    Concurrency Gap Analysis ตายที่ FK constraint แต่รายงานว่า 387/387 (100%)
    //    📌 ไอคอนข้างชื่อชุดขึ้น ❌ ถูกอยู่แล้ว แต่ "ตัวเลขรวม" ต่างหากที่โกหก
    if ($exitCode !== 0 && $failed === 0) {
        $failed = max(1, $total - $passed);
        $total  = $passed + $failed;
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

// ============================================================
// Suite 3: กลุ่ม Gap Analysis — เดิมมีอยู่แต่ไม่ได้ต่อเข้าชุดหลัก
// ============================================================
// 🧠 ทำไมต้องต่อเข้ามา: ไฟล์พวกนี้มี 139 เคสที่ชุดหลักไม่ได้ครอบคลุม
//    (SQL injection 3 จุด, XSS สะท้อนกลับ, CSRF token เปลี่ยนต่อ session,
//     แยกสิทธิ์ staff/admin, email แก้ไม่ได้, data integrity, concurrency)
//    แต่ไม่มีใครรัน → **เน่าไปแล้ว 2 ไฟล์** (fatal error ทั้งคู่ เพิ่งซ่อมไป 2026-08-28)
//    ถ้าไม่ต่อเข้ามา ที่เหลือก็จะทยอยตายแบบเดียวกันโดยไม่มีใครรู้
// ⏱️ ทั้งกลุ่มใช้เวลารวมประมาณ 3.5 วินาที และเก็บกวาดข้อมูลตัวเองครบทุกไฟล์
//    (วัดแล้วด้วยการนับแถวใน 6 ตารางก่อน/หลังรัน)
$gapSuites = [
    'Security Gap Analysis'      => 'test_security_gap_analysis.php',
    'Data Integrity'             => 'test_data_integrity.php',
    'Concurrency Gap Analysis'   => 'test_concurrency_gap_analysis.php',
    'Reservation Admin Gap'      => 'test_reservation_admin_gap_analysis.php',
    'Payment Gap Analysis'       => 'test_payment_gap_analysis.php',
    'ยกเว้นค่าปรับ'              => 'test_fine_waiver.php',           // ทุกที่ที่นิยาม "ค้างชำระ" ต้องตรงกัน
    'Authentication Gap'         => 'test_authentication_gap_analysis.php',
    'Book Management'            => 'test_book_management.php',
    'หนังสืออ้างอิง'             => 'test_reference_books.php',       // ยืม/จองไม่ได้ แต่ยังค้นเจอ
    'Profile Security'           => 'test_profile_security.php',
    // 📌 3 ตัวนี้เพิ่งซ่อม (2026-08-28) — เดิมทิ้งขยะไว้/query คอลัมน์ที่ไม่มีอยู่จริง
    'Member Management'          => 'test_member_management.php',
    'Reservations'               => 'test_reservations.php',
    'Reports Queries'            => 'reports_test.php',
    // 📌 กลุ่มที่ตรวจแล้วว่ามีของที่ชุดอื่นไม่ได้ทดสอบ (2026-08-28)
    'Category Management'        => 'test_category_management.php',   // CRUD หมวดหมู่ + ON DELETE SET NULL
    'Settings (Service)'         => 'test_settings.php',              // อ่าน/เขียนค่าตั้งค่า + อักขระพิเศษ
    'Settings (HTTP)'            => 'test_settings_http.php',         // validation สี/ชื่อ + สิทธิ์ staff
    'Settings (กฎการยืม)'        => 'test_settings_rules.php',        // กฎที่ลูกค้าแก้เองได้: settings → .env → default
    'Reservation Logic'          => 'test_reservation_logic.php',     // รวม IDOR — ไม่มีที่อื่นทดสอบ
    'Borrow/Return Gap'          => 'test_borrow_return_gap_analysis.php',
    'ต่ออายุการยืม'              => 'test_renew_borrow.php',          // เลื่อนกำหนดคืน + กันช่องลบค่าปรับ
    'หนังสือหาย/ชำรุด'           => 'test_lost_damaged.php',          // ลดสต็อก + ค่าชดใช้ + ย้อนได้
    'จองรอคิว'                   => 'test_reservation_queue.php',    // 🔴 แตะ invariant สต็อกโดยตรง
    'โครงสร้าง DB 3 แหล่ง'       => 'test_schema_sources_match.php',  // install.php / schema.sql / migration ต้องตรงกัน // ค่าปรับที่ขอบเขต + atomic rollback
    'Logical Consistency'        => 'logical_consistency_test.php',   // กันทำซ้ำ (ยืม/คืน/จ่าย/จองซ้ำ)
    'Search API (HTTP)'          => 'test_search_api.php',            // 405, คำค้น 1000 ตัว
    'Barcode Scan'               => 'barcode_test.php',               // สแกนหา user/book
    'Dashboard & Reports'        => 'test_dashboard_reports.php',     // ความถูกต้องของสถิติ
    'Import Flow'                => 'test_import_flow.php',           // BOM, ข้ามแถวเสีย, upsert สมาชิก
];
foreach ($gapSuites as $label => $file) {
    $suiteResults[] = runSuite($label, __DIR__ . '/' . $file, escapeshellarg($adminPassword));
}

// Suite 3: HTTP Integration Tests
// Check if Apache is running first
$ch = curl_init(rtrim(APP_URL, '/') . '/login.php');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_NOBODY => true]);
curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

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
    // 🧠 ต้องดู failed ด้วย ไม่ใช่ exit code อย่างเดียว
    //    ชุดรุ่นเก่าบางตัวพิมพ์ ❌ แต่ไม่ได้คืน exit code (จบด้วย 0 เสมอ)
    //    ถ้าดูแค่ exit code จะขึ้น "✅ 2/3 passed (1 failed)" ซึ่งขัดกันเองและหลอกตา
    $isFail = $s['exit_code'] !== 0 || $s['failed'] > 0;
    $icon = $s['exit_code'] === -1 ? '⏭' : ($isFail ? '❌' : '✅');
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
