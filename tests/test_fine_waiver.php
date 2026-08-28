<?php

/**
 * ทดสอบ "ยกเว้น/ลดหย่อนค่าปรับ" (ROADMAP ข้อ 2)
 *
 * ==========================================================================
 * 🎯 กฎที่ต้องเป็นจริง
 * ==========================================================================
 * A. ด่านที่ชั้น Service — บังคับเหตุผล · กันยกเว้นซ้ำ · กันยกเว้นรายการที่จ่ายแล้ว
 *    · กันรับชำระรายการที่ยกเว้นแล้ว · เพดานของเจ้าหน้าที่
 * B. 🔴 **ทุกที่ที่นิยาม "ค้างชำระ" ต้องตรงกันหมด** — นี่คือเคสที่สำคัญที่สุดของไฟล์นี้
 *    ระบบมี 6 query ที่นิยามคำนี้แยกกัน ถ้าลืมแก้ที่ใดที่หนึ่ง ตัวเลขจะไม่ตรงกันข้ามหน้า
 *    ซึ่งเป็นอาการเดียวกับ F-35 ที่ยังค้างอยู่
 * C. ผ่าน HTTP จริง + ฝั่งสมาชิกต้องเห็นว่า "ยกเว้นแล้ว" ไม่ใช่ยอดค้างสีแดง
 *
 * 🧹 สร้างหนังสือ/สมาชิก/รายการยืมของตัวเอง แล้วลบทิ้งท้ายไฟล์
 *
 * 📌 การใช้งาน: php tests/test_fine_waiver.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Repositories/BookRepository.php';
require_once __DIR__ . '/../app/Repositories/BorrowRepository.php';
require_once __DIR__ . '/../app/Repositories/PaymentRepository.php';
require_once __DIR__ . '/../app/Repositories/ReportRepository.php';
require_once __DIR__ . '/../app/Repositories/ReservationRepository.php';
require_once __DIR__ . '/../app/Repositories/UserRepository.php';
require_once __DIR__ . '/../app/Services/BorrowService.php';

$BASE_URL       = rtrim(APP_URL, '/');
$ADMIN_EMAIL    = 'admin@library.com';
$ADMIN_PASSWORD = $argv[1] ?? '123456';
$TAG            = 'WAIVETEST' . getmypid();

$results = ['passed' => 0, 'failed' => 0, 'total' => 0];

function pass(string $id, string $msg = 'OK'): void
{
    global $results; $results['total']++; $results['passed']++;
    echo "  \033[32m✅ $id\033[0m: $msg\n";
}
function fail(string $id, string $msg): void
{
    global $results; $results['total']++; $results['failed']++;
    echo "  \033[31m❌ $id\033[0m: $msg\n";
}
function check(string $id, bool $ok, string $okMsg, string $failMsg): void
{
    $ok ? pass($id, $okMsg) : fail($id, $failMsg);
}
/** เรียกแล้วต้อง throw — ใช้ตรวจด่านต่าง ๆ */
function expectFail(string $id, callable $fn, string $expectWord, string $what): void
{
    try {
        $fn();
        fail($id, "$what ผ่านไปได้ ทั้งที่ไม่ควร");
    } catch (Exception $e) {
        check($id, str_contains($e->getMessage(), $expectWord),
            "$what ถูกปฏิเสธ: " . mb_substr($e->getMessage(), 0, 60),
            "$what ถูกปฏิเสธแต่ข้อความไม่ตรงที่คาด: " . $e->getMessage());
    }
}

$COOKIE = tempnam(sys_get_temp_dir(), 'bbwaive');
function http(string $method, string $url, array $fields = [], ?string $jar = null): array
{
    global $COOKIE;
    $jar = $jar ?? $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'code' => $code];
}
function csrf(string $html): string
{
    preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
}

echo "\n╔════════════════════════════════════════════════════════════╗\n";
echo "║  ยกเว้น/ลดหย่อนค่าปรับ (ROADMAP ข้อ 2)                     ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";

$pdo = getDB();
$bookRepo    = new \App\Repositories\BookRepository($pdo);
$borrowRepo  = new \App\Repositories\BorrowRepository($pdo);
$paymentRepo = new \App\Repositories\PaymentRepository($pdo);
$reportRepo  = new \App\Repositories\ReportRepository($pdo);
$service     = new \App\Services\BorrowService($pdo);

$adminId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();

// ── สร้างของทดสอบ ──
$bookId = $bookRepo->create(['title' => "[$TAG] หนังสือทดสอบ", 'author' => 'ผู้แต่งทดสอบ', 'quantity' => 5]);
$hash = hashPassword('123456');
$stmt = $pdo->prepare("INSERT INTO users (name, email, password, phone, role) VALUES (?, ?, ?, '0800000000', 'member')");
$stmt->execute(["[$TAG] สมาชิกทดสอบ", strtolower($TAG) . '@test.local', $hash]);
$memberId = (int) $pdo->lastInsertId();
$memberEmail = strtolower($TAG) . '@test.local';

/** สร้างรายการยืมที่คืนแล้วพร้อมค่าปรับตามจำนวนที่ต้องการ */
$makeFined = function (float $fine) use ($pdo, $memberId, $bookId): int {
    $stmt = $pdo->prepare("
        INSERT INTO borrows (user_id, book_id, borrow_date, due_date, return_date, status, fine_amount, created_at)
        VALUES (?, ?, DATE_SUB(CURDATE(), INTERVAL 20 DAY), DATE_SUB(CURDATE(), INTERVAL 13 DAY), CURDATE(), 'returned', ?, NOW())
    ");
    $stmt->execute([$memberId, $bookId, $fine]);
    return (int) $pdo->lastInsertId();
};

/**
 * 🔴 หัวใจของไฟล์นี้ — เก็บ "ยอดค้างชำระ" จากทุกที่ที่นิยามคำนี้ในระบบ
 *    ถ้าเลขใดเลขหนึ่งไม่ตรง แปลว่ามี query ที่ลืมกรอง fine_waived_at
 */
$collectUnpaid = function () use ($pdo, $paymentRepo, $borrowRepo, $reportRepo): array {
    $sql = (float) $pdo->query("
        SELECT COALESCE(SUM(b.fine_amount), 0) FROM borrows b
        LEFT JOIN payments p ON p.borrow_id = b.id
        WHERE b.fine_amount > 0 AND p.id IS NULL AND b.fine_waived_at IS NULL
    ")->fetchColumn();

    $unpaidRows = $reportRepo->getUnpaidFinesReport('2000-01-01', '2099-12-31');
    $reportSum = 0.0;
    foreach ($unpaidRows as $r) {
        $reportSum += (float) ($r['fine_amount'] ?? $r['fine'] ?? 0);
    }

    return [
        'SQL ตรง ๆ'                             => $sql,
        'PaymentRepository::getUnpaidTotal'     => (float) $paymentRepo->getUnpaidTotal(),
        'BorrowRepository::getTotalUnpaidFines' => (float) $borrowRepo->getTotalUnpaidFines(),
        'ReportRepository (สมาชิกค้างชำระ)'     => $reportSum,
    ];
};


/**
 * 🧹 เก็บกวาดแบบรับประกัน — ทำงานแม้เทสต์ตายกลางคัน
 *
 * 🧠 ทำไมต้อง register_shutdown_function ไม่ใช่เขียนไว้ท้ายไฟล์เฉย ๆ:
 *    ถ้าเคสใดเคสหนึ่งโยน exception ที่ไม่ถูกจับ หรือเกิด fatal error
 *    โค้ดท้ายไฟล์จะไม่ถูกรันเลย → เหลือหนังสือ/สมาชิกทดสอบค้างในระบบทุกครั้ง
 *    (อาการเดียวกับ F-52 และเคสที่ tests/test_concurrency_gap_analysis.php เคยเป็น)
 *    shutdown function ทำงานทุกทางออก จึงเก็บกวาดได้เสมอ
 */
$cleanup = function () use ($pdo, $memberId, $bookId): void {
    static $done = false;
    if ($done) return;
    $done = true;
    // 🔴 rollback ทรานแซกชันที่ค้างก่อน ไม่งั้น DELETE ด้านล่างจะถูก rollback ไปด้วย
    //    แล้วข้อมูลทดสอบค้างในระบบทั้งชุด (เจอมาแล้วตอนเทสต์ตายกลาง transaction)
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $pdo->exec("DELETE FROM payments WHERE borrow_id IN (SELECT id FROM borrows WHERE user_id = $memberId)");
    $pdo->exec("DELETE FROM borrows WHERE user_id = $memberId OR book_id = $bookId");
    $pdo->exec("DELETE FROM reservations WHERE user_id = $memberId OR book_id = $bookId");
    $pdo->exec("DELETE FROM books WHERE id = $bookId");
    $pdo->exec("DELETE FROM users WHERE id = $memberId");
};
register_shutdown_function($cleanup);

echo "\n── A. ด่านที่ชั้น Service ──\n";

// A1: ยกเว้นสำเร็จ + บันทึกครบ
$b1 = $makeFined(90);
$res = $service->waiveFine($b1, 'ห้องสมุดปิดกะทันหัน', $adminId, 'admin');
check('WAIVE-A1', !empty($res['success']) && (float) $res['amount'] === 90.0,
    'ยกเว้นสำเร็จ: ' . $res['message'], 'ยกเว้นไม่สำเร็จ');

$row = $pdo->query("SELECT * FROM borrows WHERE id = $b1")->fetch();
check('WAIVE-A2', !empty($row['fine_waived_at']) && (int) $row['fine_waived_by'] === $adminId
    && $row['fine_waived_note'] === 'ห้องสมุดปิดกะทันหัน',
    'บันทึกครบ: ใคร เมื่อไหร่ เพราะอะไร', 'บันทึกไม่ครบ — ตรวจย้อนหลังไม่ได้');

// A3: fine_amount เดิมต้องไม่ถูกล้างเป็น 0
check('WAIVE-A3', (float) $row['fine_amount'] === 90.0,
    'ยอดค่าปรับเดิมยังอยู่ (90) — ตรวจย้อนหลังได้ว่ายกเว้นไปเท่าไร',
    'fine_amount ถูกล้างเป็น 0 — ไม่รู้ว่ายกเว้นไปเท่าไร');

// A4–A6: ด่านต่าง ๆ
expectFail('WAIVE-A4', fn() => $service->waiveFine($b1, 'ลองซ้ำ', $adminId, 'admin'),
    'ยกเว้นค่าปรับไปแล้ว', 'ยกเว้นซ้ำ');
expectFail('WAIVE-A5', fn() => $service->payFine($b1, $adminId),
    'ยกเว้นค่าปรับไปแล้ว', 'รับชำระรายการที่ยกเว้นแล้ว');

$b2 = $makeFined(50);
expectFail('WAIVE-A6', fn() => $service->waiveFine($b2, '', $adminId, 'admin'),
    'กรุณากรอกเหตุผล', 'ยกเว้นโดยไม่กรอกเหตุผล');
expectFail('WAIVE-A7', fn() => $service->waiveFine($b2, "   \t  ", $adminId, 'admin'),
    'กรุณากรอกเหตุผล', 'ยกเว้นโดยกรอกแต่ช่องว่าง');
expectFail('WAIVE-A8', fn() => $service->waiveFine($b2, str_repeat('ก', 256), $adminId, 'admin'),
    'ไม่เกิน 255', 'เหตุผลยาวเกิน 255 ตัว');

// A9: จ่ายแล้วยกเว้นไม่ได้
$service->payFine($b2, $adminId);
expectFail('WAIVE-A9', fn() => $service->waiveFine($b2, 'ลองยกเว้นทีหลัง', $adminId, 'admin'),
    'ชำระค่าปรับแล้ว', 'ยกเว้นรายการที่ชำระแล้ว');

// A10: ไม่มีค่าปรับก็ยกเว้นไม่ได้
$b3 = $makeFined(0);
expectFail('WAIVE-A10', fn() => $service->waiveFine($b3, 'ไม่มีค่าปรับ', $adminId, 'admin'),
    'ไม่มีค่าปรับ', 'ยกเว้นรายการที่ไม่มีค่าปรับ');

echo "\n── B. เพดานของเจ้าหน้าที่ ──\n";
echo "     (ตั้งไว้ที่ " . number_format(FINE_WAIVE_STAFF_LIMIT) . " บาท — ปรับได้ที่หน้าตั้งค่าระบบ)\n";

$over  = $makeFined(FINE_WAIVE_STAFF_LIMIT + 10);
$under = $makeFined(max(1, FINE_WAIVE_STAFF_LIMIT - 10));

expectFail('WAIVE-B1', fn() => $service->waiveFine($over, 'เกินวงเงิน', $adminId, 'staff'),
    'เกินวงเงิน', 'เจ้าหน้าที่ยกเว้นยอดเกินเพดาน');

$r = $service->waiveFine($under, 'สมาชิกเจ็บป่วย', $adminId, 'staff');
check('WAIVE-B2', !empty($r['success']), 'เจ้าหน้าที่ยกเว้นยอดไม่เกินเพดานได้', 'เจ้าหน้าที่ยกเว้นยอดในวงเงินไม่ได้');

$r = $service->waiveFine($over, 'ผู้ดูแลอนุมัติ', $adminId, 'admin');
check('WAIVE-B3', !empty($r['success']), 'ผู้ดูแลยกเว้นยอดเกินเพดานได้', 'ผู้ดูแลยกเว้นยอดเกินเพดานไม่ได้');

echo "\n── C. 🔴 ทุกที่ที่นิยาม \"ค้างชำระ\" ต้องตรงกัน ──\n";

$before = $collectUnpaid();
$mismatch = array_filter($before, fn($v) => abs($v - $before['SQL ตรง ๆ']) > 0.01);
check('WAIVE-C1', count($mismatch) <= 1,
    'ก่อนยกเว้น: ทุกแหล่งตรงกันที่ ' . number_format($before['SQL ตรง ๆ'], 2),
    'ก่อนยกเว้นก็ไม่ตรงกันแล้ว: ' . json_encode($before, JSON_UNESCAPED_UNICODE));

$b4 = $makeFined(140);
$mid = $collectUnpaid();
check('WAIVE-C2', abs(($mid['SQL ตรง ๆ'] - $before['SQL ตรง ๆ']) - 140) < 0.01,
    'เพิ่มค่าปรับ 140 → ยอดค้างเพิ่ม 140 ตามจริง', 'เพิ่มค่าปรับแล้วยอดค้างไม่ขยับตามที่ควร');

$service->waiveFine($b4, 'ทดสอบความสอดคล้อง', $adminId, 'admin');
$after = $collectUnpaid();

$allAgree = true; $detail = [];
foreach ($after as $name => $value) {
    $expected = $before['SQL ตรง ๆ'];
    $detail[] = sprintf('%s=%.2f', $name, $value);
    if (abs($value - $expected) > 0.01) $allAgree = false;
}
check('WAIVE-C3', $allAgree,
    'ยกเว้น 140 แล้ว **ทุกแหล่งกลับมาตรงกันหมด** (' . number_format($before['SQL ตรง ๆ'], 2) . ')',
    'ยกเว้นแล้วตัวเลขไม่ตรงกัน — มี query ที่ลืมกรอง fine_waived_at: ' . implode(' · ', $detail));

// รายการที่ยกเว้นต้องหายจากลิสต์ค้างชำระ แต่โผล่ในประวัติการยกเว้น
$unpaidIds = array_column($borrowRepo->getUnpaidFinesList(500), 'id');
check('WAIVE-C4', !in_array($b4, $unpaidIds) && !in_array($b1, $unpaidIds),
    'รายการที่ยกเว้นหายจากลิสต์ค้างชำระแล้ว', 'ยังโผล่ในลิสต์ค้างชำระอยู่');

$waivedIds = array_column($borrowRepo->findWaivedFines(500), 'id');
check('WAIVE-C5', in_array($b4, $waivedIds),
    'โผล่ในประวัติการยกเว้น (ตรวจย้อนหลังได้)', 'ไม่โผล่ในประวัติการยกเว้น');

echo "\n── D. ผ่าน HTTP + ฝั่งสมาชิก ──\n";

$login = http('GET', "$BASE_URL/login.php");
http('POST', "$BASE_URL/login.php", ['email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD, 'csrf_token' => csrf($login['body'])]);
$page = http('GET', "$BASE_URL/admin/payments.php");

if ($page['code'] !== 200) {
    fail('WAIVE-D0', 'เปิดหน้าค่าปรับไม่ได้ (HTTP ' . $page['code'] . ') — ข้ามหมวด D');
} else {
    $b5 = $makeFined(70);
    $form = http('GET', "$BASE_URL/admin/payments.php");
    http('POST', "$BASE_URL/admin/payments.php", [
        'csrf_token' => csrf($form['body']), 'action' => 'waive_fine',
        'borrow_id' => $b5, 'waive_note' => "[$TAG] ทดสอบผ่านหน้าเว็บ",
    ]);
    $row5 = $pdo->query("SELECT fine_waived_at, fine_waived_note FROM borrows WHERE id = $b5")->fetch();
    check('WAIVE-D1', !empty($row5['fine_waived_at']),
        'ยกเว้นผ่านหน้าเว็บได้', 'ยกเว้นผ่านหน้าเว็บไม่สำเร็จ');

    $after2 = http('GET', "$BASE_URL/admin/payments.php");
    check('WAIVE-D2', str_contains($after2['body'], "[$TAG] ทดสอบผ่านหน้าเว็บ"),
        'เหตุผลโผล่ในตาราง "ประวัติการยกเว้นค่าปรับ"', 'ไม่เห็นเหตุผลในหน้าเว็บ — ตรวจย้อนหลังไม่ได้');

    // 🛡️ ห้ามให้หน้าเว็บส่ง role มาเอง — ไม่งั้นเจ้าหน้าที่ปลอมเป็นผู้ดูแลได้
    $bBig = $makeFined(FINE_WAIVE_STAFF_LIMIT + 500);
    $staffJar = tempnam(sys_get_temp_dir(), 'bbwvstaff');
    $ls = http('GET', "$BASE_URL/login.php", [], $staffJar);
    http('POST', "$BASE_URL/login.php", ['email' => 'staff@library.com', 'password' => $ADMIN_PASSWORD, 'csrf_token' => csrf($ls['body'])], $staffJar);
    $sf = http('GET', "$BASE_URL/admin/payments.php", [], $staffJar);
    http('POST', "$BASE_URL/admin/payments.php", [
        'csrf_token' => csrf($sf['body']), 'action' => 'waive_fine', 'borrow_id' => $bBig,
        'waive_note' => 'ลองปลอม role', 'role' => 'admin', 'waiver_role' => 'admin',
    ], $staffJar);
    $rowBig = $pdo->query("SELECT fine_waived_at FROM borrows WHERE id = $bBig")->fetch();
    check('WAIVE-D3', empty($rowBig['fine_waived_at']),
        'ส่ง role=admin มากับ POST ก็ไม่ช่วย — ระบบใช้ role จาก session เท่านั้น',
        'ปลอม role ผ่าน POST แล้วยกเว้นเกินวงเงินได้');
    @unlink($staffJar);

    // ฝั่งสมาชิก
    $stuJar = tempnam(sys_get_temp_dir(), 'bbwvstu');
    $lst = http('GET', "$BASE_URL/login.php", [], $stuJar);
    http('POST', "$BASE_URL/login.php", ['email' => $memberEmail, 'password' => '123456', 'csrf_token' => csrf($lst['body'])], $stuJar);
    $mine = http('GET', "$BASE_URL/my_borrows.php", [], $stuJar);
    check('WAIVE-D4', str_contains($mine['body'], 'ยกเว้นแล้ว'),
        'สมาชิกเห็นว่า "ยกเว้นแล้ว" ไม่ใช่ยอดค้างสีแดง',
        'สมาชิกยังเห็นเป็นค่าปรับค้างชำระ ทั้งที่ยกเว้นไปแล้ว');
    @unlink($stuJar);
}

// ============================================================
// CLEANUP
// ============================================================
echo "\n── CLEANUP ──\n";
$cleanup();
@unlink($COOKIE);
echo "  ลบหนังสือ/สมาชิก/รายการยืมที่สร้างขึ้นทั้งหมด\n";

$pct = $results['total'] > 0 ? round($results['passed'] / $results['total'] * 100, 1) : 0;
echo "\n══════════════════════════════════════\n";
echo " RESULTS: {$results['passed']}/{$results['total']} passed ($pct%)";
if ($results['failed'] > 0) echo " | {$results['failed']} FAILED";
echo "\n══════════════════════════════════════\n\n";

exit($results['failed'] > 0 ? 1 : 0);
