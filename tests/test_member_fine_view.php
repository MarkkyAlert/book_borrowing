<?php

/**
 * ค่าปรับที่นักเรียนเห็น ต้องตรงกับที่เจ้าหน้าที่เห็น
 *
 * ==========================================================================
 * 🔴 ที่มา: UAT รอบ 5
 * ==========================================================================
 * นักเรียนเลยกำหนดคืนมา 10 วัน (ค่าปรับ 100 บาท) เปิดหน้าของตัวเองเห็นแค่
 * "(เลยกำหนด 10 วัน)" — คำว่า "ค่าปรับ" กับ "บาท" ไม่ปรากฏเลยสักครั้ง
 * ขณะที่เจ้าหน้าที่เปิดหน้าเดียวกันเห็นป้ายแดง "100 บาท" ชัด ๆ
 *
 * สาเหตุ: ค่าปรับมี 2 สถานะ และหน้าสมาชิกรู้จักแค่สถานะเดียว
 *   - บันทึกแล้ว = borrows.fine_amount เขียนตอน "รับคืน" เท่านั้น
 *   - กำลังเดิน  = คำนวณสด ยังไม่มีในฐานข้อมูล
 * หน้าเจ้าหน้าที่เรียก calculateFine() สดทุกแถว หน้าสมาชิกอ่านจากฐานข้อมูลตรง ๆ
 *
 * 🧠 หัวใจของไฟล์นี้ 2 อย่าง:
 *    1. MB-3 — พิสูจน์ว่า **ใช้สูตรเดียวกันจริง** ไม่ใช่คำนวณเองให้บังเอิญตรง
 *       (ตั้งวันปิดห้องสมุดแล้วยอดต้องลดลงเท่ากันทั้งสองฝั่ง)
 *    2. MB-2 — ยามกันไม่ให้เอายอดที่กำลังเดินไปรวมกับ "ค่าปรับค้างชำระ"
 *       ซึ่งจะทำให้ตัวเลขของนักเรียนกับของเจ้าหน้าที่เถียงกัน (ดู test_fine_waiver หมวด C)
 */

require_once __DIR__ . '/../bootstrap.php';

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  ค่าปรับที่นักเรียนเห็น (UAT รอบ 5)                        ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";

$results = ['passed' => 0, 'failed' => 0, 'total' => 0];
function pass(string $id, string $m): void { global $results; $results['passed']++; $results['total']++; echo "  \033[32m✅ {$id}\033[0m: {$m}\n"; }
function fail(string $id, string $m): void { global $results; $results['failed']++; $results['total']++; echo "  \033[31m❌ {$id}\033[0m: {$m}\n"; }
function check(string $id, bool $ok, string $a, string $b): void { $ok ? pass($id, $a) : fail($id, $b); }

$TAG  = 'MBFINE' . getmypid();
$pdo  = getDB();
$BASE = rtrim(APP_URL, '/');
$OVERDUE_DAYS = 10;
$CLOSED_DAYS  = 3;

// ── ของทดสอบ ──
$bookRepo = new \App\Repositories\BookRepository($pdo);
$bookId   = $bookRepo->create(['title' => "[$TAG] หนังสือทดสอบ", 'author' => 'ผู้แต่ง', 'quantity' => 5]);
$st = $pdo->prepare("INSERT INTO users (name, email, password, phone, role, must_change_password)
                     VALUES (?, ?, ?, '0891110000', 'member', 0)");
$st->execute(["[$TAG] นักเรียน", strtolower($TAG) . '@test.local', hashPassword('123456')]);
$memberId = (int) $pdo->lastInsertId();

// 🧹 เก็บกวาดแบบรับประกัน
//    🔴 ต้องคืนสต็อกก่อนลบรายการยืม ไม่งั้นหนังสือหายจากชั้นถาวร
//       (พลาดมาแล้วจริงตอน UAT รอบ 4 — หนังสือของลูกค้าหายไป 1 เล่ม)
register_shutdown_function(function () use ($pdo, $memberId, $bookId, $TAG): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec("UPDATE books b JOIN borrows br ON br.book_id = b.id SET b.available = b.available + 1
                WHERE br.user_id = $memberId AND br.status = 'borrowing'");
    $pdo->exec("DELETE FROM payments WHERE borrow_id IN (SELECT id FROM borrows WHERE user_id = $memberId)");
    $pdo->exec("DELETE FROM borrows WHERE user_id = $memberId OR book_id = $bookId");
    $pdo->exec("DELETE FROM reservations WHERE user_id = $memberId OR book_id = $bookId");
    $pdo->exec("DELETE FROM books WHERE id = $bookId");
    $pdo->exec("DELETE FROM users WHERE id = $memberId");
    $pdo->exec("DELETE FROM closed_days WHERE note = '[$TAG] วันปิดทดสอบ'");
});

// ── HTTP ──
$jarFor = function (string $email, string $pw) use ($BASE): ?string {
    $jar = tempnam(sys_get_temp_dir(), 'mbf');
    $req = function (string $m, string $u, array $f = []) use ($jar): string {
        $ch = curl_init($u);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
            CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
        if ($m === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($f)); }
        $b = (string) curl_exec($ch); curl_close($ch); return $b;
    };
    $login = $req('GET', "{$BASE}/login.php");
    $tok = preg_match('/name="csrf_token"\s+value="([^"]+)"/', $login, $m) ? $m[1] : '';
    $out = $req('POST', "{$BASE}/login.php", ['csrf_token' => $tok, 'email' => $email, 'password' => $pw]);
    return str_contains($out, 'ออกจากระบบ') ? $jar : null;
};
$get = function (string $url, string $jar): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
    $b = (string) curl_exec($ch); curl_close($ch); return $b;
};

$adminJar   = $jarFor('admin@library.com', $argv[1] ?? '123456');
$studentJar = $jarFor(strtolower($TAG) . '@test.local', '123456');
register_shutdown_function(function () use (&$adminJar, &$studentJar) {
    if ($adminJar) @unlink($adminJar);
    if ($studentJar) @unlink($studentJar);
});

if (!$adminJar || !$studentJar) {
    fail('MB-0', '🔴 ล็อกอินไม่สำเร็จ — ข้ามทั้งไฟล์ (ตรวจ Apache / รหัสผ่านแอดมิน)');
    echo "\n══════════════════════════════════════\n RESULTS: 0/1 passed | 1 FAILED\n══════════════════════════════════════\n\n";
    exit(1);
}

// 📖 ยืมแล้วเลื่อนกำหนดคืนย้อนหลัง
$service = new \App\Services\BorrowService($pdo);
$service->createBorrow($memberId, [$bookId]);
$borrowId = (int) $pdo->query("SELECT id FROM borrows WHERE user_id = $memberId ORDER BY id DESC LIMIT 1")->fetchColumn();
$pdo->exec("UPDATE borrows SET due_date = DATE_SUB(CURDATE(), INTERVAL {$OVERDUE_DAYS} DAY) WHERE id = $borrowId");

// 🔍 ตัวอ่านยอดจากแต่ละหน้า
$staffAmount = function () use ($get, $adminJar, $BASE, $TAG): ?float {
    $h = $get("{$BASE}/admin/borrows.php?search=" . urlencode($TAG), $adminJar);
    return preg_match('/bg-red-100 text-red-700">\s*([\d,]+(?:\.\d+)?)\s*บาท/u', $h, $m)
        ? (float) str_replace(',', '', $m[1]) : null;
};
$studentAmount = function () use ($get, $studentJar, $BASE): ?float {
    $h = $get("{$BASE}/my_borrows.php", $studentJar);
    return preg_match('/ค่าปรับถึงวันนี้\s*([\d,]+\.\d\d)\s*บาท/u', $h, $m)
        ? (float) str_replace(',', '', $m[1]) : null;
};
$profileRunning = function () use ($get, $studentJar, $BASE): ?float {
    $h = $get("{$BASE}/profile.php", $studentJar);
    return preg_match('/ค่าปรับถึงวันนี้อีก\s*<span[^>]*>([\d,]+\.\d\d)<\/span>/u', $h, $m)
        ? (float) str_replace(',', '', $m[1]) : null;
};
$profileUnpaid = function () use ($get, $studentJar, $BASE): ?float {
    $h = $get("{$BASE}/profile.php", $studentJar);
    return preg_match('/ค่าปรับค้างชำระ<\/p>\s*<p[^>]*>\s*([\d,]+\.\d\d)/u', $h, $m)
        ? (float) str_replace(',', '', $m[1]) : null;
};

// ════════════════════════════════════════════════════════════
echo "\n── A. ตัวเลขสองฝั่งต้องตรงกัน ──\n";

$expect = $OVERDUE_DAYS * FINE_PER_DAY;
$staff = $staffAmount();
$stud  = $studentAmount();
check('MB-1', $staff !== null && $stud !== null && abs($staff - $stud) < 0.01 && abs($stud - $expect) < 0.01,
    "เกินกำหนด {$OVERDUE_DAYS} วัน → เจ้าหน้าที่เห็น {$staff} บาท · นักเรียนเห็น {$stud} บาท (ตรงกัน)",
    '🔴 ' . ($stud === null ? 'นักเรียนไม่เห็นตัวเลขค่าปรับเลย ' : '')
          . ($staff === null ? 'อ่านยอดฝั่งเจ้าหน้าที่ไม่ได้ ' : '')
          . (($staff !== null && $stud !== null) ? "เจ้าหน้าที่ {$staff} · นักเรียน {$stud} · ควรเป็น {$expect}" : ''));

$pRun = $profileRunning();
check('MB-1b', $pRun !== null && abs($pRun - $expect) < 0.01,
    "หน้าโปรไฟล์ก็บอกยอดที่กำลังเดิน {$pRun} บาท ตรงกับหน้ารายการยืม",
    '🔴 ' . ($pRun === null ? 'หน้าโปรไฟล์ไม่บอกยอดที่กำลังเดิน' : "โปรไฟล์บอก {$pRun} ควรเป็น {$expect}"));

// ════════════════════════════════════════════════════════════
echo "\n── B. ห้ามเอายอดที่กำลังเดินไปรวมกับ \"ค้างชำระ\" ──\n";

// MB-2 — 🔴 ยามที่สำคัญที่สุดของไฟล์นี้
//    "ค้างชำระ" = คืนแล้ว ยอดถูกบันทึก ถึงกำหนดจ่าย · "กำลังเดิน" = ยังไม่คืน ยอดยังโต
//    ระบบมี 6 query ที่นิยาม "ค้างชำระ" (ดู test_fine_waiver หมวด C)
//    ถ้าเอายอดที่กำลังเดินยัดเข้าไป นักเรียนจะเห็นเลขหนึ่ง เจ้าหน้าที่เห็นอีกเลข
$unpaid = $profileUnpaid();
$staffUnpaidForMember = (float) $pdo->query("
    SELECT COALESCE(SUM(b.fine_amount), 0) FROM borrows b
    LEFT JOIN payments p ON p.borrow_id = b.id
    WHERE b.user_id = $memberId AND b.fine_amount > 0 AND p.id IS NULL AND b.fine_waived_at IS NULL
")->fetchColumn();
check('MB-2', $unpaid !== null && abs($unpaid - $staffUnpaidForMember) < 0.01,
    "\"ค่าปรับค้างชำระ\" ยังเป็น {$unpaid} บาท ตรงกับที่ระบบนับให้เจ้าหน้าที่ — ไม่ถูกเอายอดที่กำลังเดินมารวม",
    "🔴 นักเรียนเห็นค้างชำระ {$unpaid} แต่ฝั่งเจ้าหน้าที่นับได้ {$staffUnpaidForMember} — ตัวเลขเถียงกันข้ามหน้า");

// ════════════════════════════════════════════════════════════
echo "\n── C. ใช้สูตรเดียวกันจริง ไม่ใช่คำนวณเองให้บังเอิญตรง ──\n";

// MB-3 — ตั้งวันปิดห้องสมุดคร่อมช่วงที่เกินกำหนด
//    ค่าปรับต้องหักวันปิดออก (ดู BorrowService::calculateFine)
//    ถ้าหน้าสมาชิกคำนวณเองแบบง่าย ๆ (วันเกิน × ค่าปรับ) ยอดจะไม่ลด → ข้อนี้แดง
$cs = date('Y-m-d', strtotime('-' . ($OVERDUE_DAYS - 5) . ' day'));
$ce = date('Y-m-d', strtotime('-' . ($OVERDUE_DAYS - 5 - ($CLOSED_DAYS - 1)) . ' day'));
$pdo->prepare("INSERT INTO closed_days (start_date, end_date, note) VALUES (?, ?, ?)")
    ->execute([$cs, $ce, "[$TAG] วันปิดทดสอบ"]);

$staff2 = $staffAmount();
$stud2  = $studentAmount();
$expect2 = ($OVERDUE_DAYS - $CLOSED_DAYS) * FINE_PER_DAY;
check('MB-3', $staff2 !== null && $stud2 !== null
        && abs($staff2 - $stud2) < 0.01 && abs($stud2 - $expect2) < 0.01,
    "ตั้งวันปิด {$CLOSED_DAYS} วัน → ทั้งสองฝั่งลดเหลือ {$stud2} บาทเท่ากัน (หักวันปิดด้วยสูตรเดียวกัน)",
    "🔴 หลังตั้งวันปิด: เจ้าหน้าที่ {$staff2} · นักเรียน {$stud2} · ควรเป็น {$expect2}"
        . ' — ถ้านักเรียนยังเป็น ' . ($OVERDUE_DAYS * FINE_PER_DAY) . ' แปลว่าหน้าสมาชิกคำนวณเอง ไม่ได้ใช้ calculateFine()');

$pdo->exec("DELETE FROM closed_days WHERE note = '[$TAG] วันปิดทดสอบ'");

// ════════════════════════════════════════════════════════════
// ============================================================
// 🚫 ที่จงใจ "ไม่" ใส่ไว้ในไฟล์นี้ — และเหตุผล
// ============================================================
// เคยเขียนยาม 2 แบบตรงนี้ แล้วถอดออกทั้งคู่เพราะ **ทำลายโค้ดยังไงก็ไม่แดง**
// เก็บบันทึกไว้กันคนมาเติมใหม่แล้วเสียเวลาซ้ำ:
//
//   1. "เล่มที่ยังไม่ถึงกำหนดต้องไม่ขึ้นค่าปรับ"
//      → calculateFine() คืน 0 ให้วันที่ยังมาไม่ถึงอยู่แล้ว และหน้าจอยังมี
//        เงื่อนไข $isOverdue กับ amount > 0 กันอีก 2 ชั้น
//        ทำลายชั้นไหนก็ไม่แดง เพราะอีก 2 ชั้นรับไว้
//
//   2. "ครบกำหนดวันนี้พอดีต้องไม่เสียค่าปรับ"
//      → calculateFine() มี `if ($return > $due)` ครอบทั้งก้อนไว้
//        ทดลองแล้วทั้งเปลี่ยน > เป็น >= และใส่ off-by-one 2 แบบ ยามก็ยังเขียว
//        เพราะต้องพังพร้อมกัน 2 จุดถึงจะเกิดผล ซึ่งไม่ใช่ความผิดพลาดที่เกิดจริง
//      → ขอบเขตนี้มียามอยู่แล้วที่ tests/test_closed_days.php (calculateFine ตรง ๆ)
//        ซึ่งเป็นชั้นที่ถูกต้องกว่าสำหรับตรวจสูตร
//
// 🧠 บทเรียน: ยามที่ล้มไม่ได้ = บรรทัดเขียวที่ไม่ได้คุ้มครองอะไร
//    แย่กว่าไม่มี เพราะทำให้คิดว่าตรงนั้นมีคนดูแลอยู่

// ============================================================
echo "\n── CLEANUP ──\n";
echo "  ลบหนังสือ/สมาชิก/รายการยืม/วันปิดทดสอบทั้งหมด\n";

$pct = $results['total'] ? round($results['passed'] / $results['total'] * 100, 1) : 0;
echo "\n══════════════════════════════════════\n";
echo " RESULTS: {$results['passed']}/{$results['total']} passed ({$pct}%)";
if ($results['failed'] > 0) echo " | {$results['failed']} FAILED";
echo "\n══════════════════════════════════════\n\n";

exit($results['failed'] > 0 ? 1 : 0);
