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

$adminPassword = $argv[1] ?? '123456';
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

// ============================================================
// 🛡️ ด่านตรวจก่อนเริ่ม: ล็อกอิน admin ได้จริงไหม
// ============================================================
// 🧠 ทำไมต้องมี: ถ้ารหัสผ่าน admin ไม่ตรง ชุดที่ต้องล็อกอินจะแดง "พร้อมกันทั้งหมด"
//    เกิดขึ้นจริงมาแล้ว — 60 เคสแดงรวด ยอดรวมหดจาก 1025 เหลือ 857
//    อ่านแล้วเหมือน regression ร้ายแรง ทั้งที่โค้ดไม่ได้พังสักบรรทัด
//    บรรทัด "❌ login ไม่สำเร็จ" ที่บอกความจริงถูกกลบอยู่กลางผลลัพธ์หลายพันบรรทัด
//    → ล้มตั้งแต่ต้นแล้วบอกวิธีแก้ ดีกว่าปล่อยให้แดงเป็นพรวนแล้วให้คนไปเดาเอง
//
// ⚠️ ไม่บล็อกกรณี Apache ไม่ได้เปิด — กรณีนั้นมีทางเดิมรองรับอยู่แล้ว (ขึ้น SKIPPED)
//    ด่านนี้จับเฉพาะ "Apache ตอบ แต่ล็อกอินไม่ผ่าน" ซึ่งเป็นคนละเรื่องกัน
$preJar = tempnam(sys_get_temp_dir(), 'bbpre');
$preFetch = function (string $url, array $post = []) use ($preJar): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR      => $preJar,
        CURLOPT_COOKIEFILE     => $preJar,
    ]);
    if ($post) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'code' => $code];
};

$loginUrl = rtrim(APP_URL, '/') . '/login.php';
$pre = $preFetch($loginUrl);
if ($pre['code'] === 0) {
    echo "\n  \033[33m⏭  Apache ไม่ตอบที่ " . rtrim(APP_URL, '/') . " — ชุดที่ต้องใช้ HTTP จะถูกข้าม\033[0m\n";
} else {
    preg_match('/name="csrf_token" value="([^"]+)"/', $pre['body'], $preTok);
    $pre = $preFetch($loginUrl, [
        'csrf_token' => $preTok[1] ?? '',
        'email'      => ADMIN_EMAIL,
        'password'   => $adminPassword,
    ]);
    // ตรวจแบบเดียวกับ tests/test_upload_security.php — หน้าแอดมินต้องโผล่ขึ้นมา
    if (!str_contains($pre['body'], 'Dashboard') && !str_contains($pre['body'], 'ผู้ดูแลระบบ')) {
        @unlink($preJar);
        echo "\n\033[1;31m";
        echo "╔══════════════════════════════════════════════════════════╗\n";
        echo "║  หยุด: ล็อกอิน admin ไม่ผ่าน — ยังไม่ได้รันเทสต์สักชุด  ║\n";
        echo "╚══════════════════════════════════════════════════════════╝\033[0m\n";
        echo "  บัญชีที่ลอง : " . ADMIN_EMAIL . "\n";
        echo "  รหัสที่ใช้   : " . (isset($argv[1]) ? 'ที่ส่งมาเป็น argument' : "ค่าปริยาย '123456' (ไม่ได้ส่ง argument มา)") . "\n\n";
        echo "  ถ้ารันต่อ ชุดที่ต้องล็อกอินจะแดงทั้งหมดประมาณ 60 เคส\n";
        echo "  ซึ่งอ่านแล้วเหมือนโค้ดพัง ทั้งที่แค่รหัสผ่านไม่ตรง\n\n";
        echo "  \033[1mวิธีแก้ — ส่งรหัสผ่าน admin เป็น argument ตัวแรก:\033[0m\n";
        echo "    php tests/run_all_tests.php <รหัสผ่าน>\n\n";
        exit(2);
    }
    echo "\n  ✅ ล็อกอิน admin ผ่าน (" . ADMIN_EMAIL . ")\n";
}
@unlink($preJar);

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
    'อีเมลไม่บังคับ + เลิกใช้งาน' => 'test_member_status.php',   // ฒ.2 ฒ.5-6 จาก UAT รอบ 2
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
    'รายการค้างชำระ'             => 'test_unpaid_list.php',          // F-35 ตัวเลขจริง + F-39 ลำดับคงที่
    'บันทึกแล้วกลับที่เดิม'      => 'test_list_state.php',           // F-37 + กัน open redirect
    'กันหนังสือซ้ำ'              => 'test_duplicate_book.php',       // F-36 idempotency + เตือนชื่อซ้ำ
    'หน้าลืมรหัสผ่าน'            => 'test_forgot_password.php',      // F-40 กัน account enumeration
    'ภาพโควตา + ไม่มารับ'        => 'test_quota_visibility.php',     // F-41 โควตารวมจอง + F-42 แท็บไม่มารับ
    'ไฟล์ CSV ที่ส่งออก'         => 'test_csv_export.php',           // F-44 เบอร์โทร/คอลัมน์/Excel SUM
    'บัตรสมาชิก'                 => 'test_member_card.php',         // F-45 ชื่อยาว/ป้ายไทย/บาร์โค้ด
    'คำศัพท์ + ระบุตัวสมาชิก'    => 'test_wording.php',            // F-46 คำเดียวกันทุกหน้า + F-51 รหัสในดรอปดาวน์
    'บังคับเปลี่ยนรหัสครั้งแรก'  => 'test_must_change_password.php', // 🔴 F-53 ปิดช่องรหัสเริ่มต้นร่วม
    'กล่องยืนยัน'                => 'test_confirm_dialogs.php',     // F-47 บอกว่าทำอะไรกับใคร + escape
    'ตัวกรองของบรรณารักษ์'       => 'test_filters.php',            // F-48 ไม่มี ISBN / เต็มโควตา / ค้างค่าปรับ
    'มือถือ: ปุ่มอยู่ในจอ'       => 'test_mobile_layout.php',      // F-49 ตรึงคอลัมน์ปุ่มบนจอแคบ
    'ค่าปรับที่นักเรียนเห็น'      => 'test_member_fine_view.php',  // UAT รอบ 5 — ตัวเลขสองฝั่งต้องตรงกัน
    'บันทึกการโทรตาม'            => 'test_contact_tracking.php',  // ฎ.7 วางสายแล้วต้องมีที่จด + ยามกัน ก.6
    'รายงานของบรรณารักษ์'        => 'test_reports.php',            // F-50 หนังสือไม่มีการยืม/อายุหนี้/ยอดรวม
    'สิทธิ์เขียนโฟลเดอร์รูป'     => 'test_upload_writable.php',    // F-54 ตัวติดตั้งเตือนก่อน + บอกสาเหตุจริง
    'วันปิดทำการ'                => 'test_closed_days.php',        // ไม่คิดค่าปรับวันที่ห้องสมุดปิด
    'โควตาตาม role'              => 'test_role_quota.php',         // เจ้าหน้าที่ยืมได้มากกว่าสมาชิกทั่วไป
    'เลขเรียกหนังสือ'            => 'test_call_number.php',        // ที่อยู่ของหนังสือบนชั้น
    'หมายเหตุรายเล่ม'            => 'test_copy_notes.php',        // สมุดจดของเจ้าหน้าที่ ไม่แตะสต็อก
    'กระดิ่งแจ้งเตือน'           => 'test_alert_bell.php',        // เดิมเป็นปุ่มหลอก จุดแดงตายตัว
    'กระดิ่งฝั่งสมาชิก'          => 'test_member_bell.php',       // ต้องไม่เห็นตัวเลขของคนอื่น
    'ส่งเมลรีเซ็ตรหัสผ่าน'       => 'test_mail_reset.php',        // ปิดเป็นค่าเริ่มต้น + SMTP ปลอม
    'กระดิ่งสุขภาพระบบ'          => 'test_system_health.php',     // 🔴 ของที่พังเงียบ: สต็อก/เมล/ไฟล์ติดตั้ง/debug/โฟลเดอร์ปก
    'ความเร่งด่วนในกระดิ่ง'      => 'test_due_urgency.php',       // 🔴 วันนี้/ใกล้หมดอายุ + ป้ายแดงต้องไม่นับซ้ำ
    'เตือนก่อนสาย + รหัสที่ตั้งให้' => 'test_due_soon_and_reset.php', // แทนอีเมลที่ตั้งใจไม่ทำ
    'เอกสารตรงกับโค้ด'           => 'test_docs_match_code.php',    // กันเอกสารสอนลูกค้าผิด
    'สุขภาพของทุกหน้า'           => 'test_page_health.php',       // กวาดทุกหน้าหา error/คำผิด
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
