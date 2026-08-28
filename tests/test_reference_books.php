<?php

/**
 * ทดสอบ "หนังสืออ้างอิง — อ่านในห้องสมุดเท่านั้น" (ROADMAP ข้อ 1)
 *
 * ==========================================================================
 * 🎯 กฎที่ต้องเป็นจริง
 * ==========================================================================
 * หนังสือที่ is_reference = 1:
 *   ❌ ยืมออกไม่ได้ · จองไม่ได้ · ไม่โผล่ในดรอปดาวน์เลือกหนังสือ · สแกนแล้วเตือน
 *   ✅ ยัง **ค้นเจอและแสดงบนหน้าเว็บตามปกติ** — ต่างจากการซ่อน (is_visible = 0)
 *      เพราะสมาชิกต้องรู้ว่ามีเล่มนี้ แล้วเดินมาอ่านที่ห้องสมุด
 *   ✅ เล่มที่ยืมไปก่อนถูกตั้งเป็นอ้างอิง ต้องคืนได้ตามปกติ (ไม่ขังไว้)
 *
 * 🛡️ ด่านต้องอยู่ที่ Service ไม่ใช่แค่ซ่อนปุ่ม — มีเคสยิง POST ตรงข้ามหน้าจอด้วย
 *
 * 🧹 สร้างหนังสือ/สมาชิกของตัวเอง แล้วลบทิ้งท้ายไฟล์ ไม่แตะข้อมูลจริง
 *
 * 📌 การใช้งาน: php tests/test_reference_books.php [รหัสผ่าน admin]
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
require_once __DIR__ . '/../app/Repositories/ReservationRepository.php';
require_once __DIR__ . '/../app/Repositories/UserRepository.php';
require_once __DIR__ . '/../app/Services/BorrowService.php';
require_once __DIR__ . '/../app/Services/ReservationService.php';

$BASE_URL       = rtrim(APP_URL, '/');
$ADMIN_EMAIL    = 'admin@library.com';
$ADMIN_PASSWORD = $argv[1] ?? '123456';
$TAG            = 'REFTEST' . getmypid();

$results = ['passed' => 0, 'failed' => 0, 'total' => 0];

function pass(string $id, string $msg = 'OK'): void
{
    global $results;
    $results['total']++; $results['passed']++;
    echo "  \033[32m✅ $id\033[0m: $msg\n";
}

function fail(string $id, string $msg): void
{
    global $results;
    $results['total']++; $results['failed']++;
    echo "  \033[31m❌ $id\033[0m: $msg\n";
}

function check(string $id, bool $ok, string $okMsg, string $failMsg): void
{
    $ok ? pass($id, $okMsg) : fail($id, $failMsg);
}

$COOKIE = tempnam(sys_get_temp_dir(), 'bbref');

function http(string $method, string $url, array $fields = [], ?string $jar = null): array
{
    global $COOKIE;
    $jar = $jar ?? $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
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
echo "║  หนังสืออ้างอิง — อ่านในห้องสมุดเท่านั้น (ROADMAP ข้อ 1)   ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";

$pdo = getDB();
$bookRepo = new \App\Repositories\BookRepository($pdo);
$borrowService = new \App\Services\BorrowService($pdo);
$reservationService = new \App\Services\ReservationService($pdo);

// ── สร้างของทดสอบของตัวเอง ──
$refBookId = $bookRepo->create([
    'title' => "[$TAG] พจนานุกรมทดสอบ",
    'author' => 'ผู้แต่งทดสอบ',
    'quantity' => 3,
    'is_reference' => 1,
]);
$normalBookId = $bookRepo->create([
    'title' => "[$TAG] นวนิยายทดสอบ",
    'author' => 'ผู้แต่งทดสอบ',
    'quantity' => 3,
]);
$hash = hashPassword('123456');
$stmt = $pdo->prepare("INSERT INTO users (name, email, password, phone, role) VALUES (?, ?, ?, '0800000000', 'member')");
$stmt->execute(["[$TAG] สมาชิกทดสอบ", strtolower($TAG) . '@test.local', $hash]);
$memberId = (int) $pdo->lastInsertId();
$memberEmail = strtolower($TAG) . '@test.local';

echo "\n── A. ด่านที่ชั้น Service ──\n";

// A1: ยืมเล่มอ้างอิงไม่ได้
try {
    $borrowService->createBorrow($memberId, [$refBookId]);
    fail('REF-A1', 'ยืมหนังสืออ้างอิงสำเร็จ ทั้งที่ไม่ควรได้');
} catch (Exception $e) {
    check('REF-A1', str_contains($e->getMessage(), 'อ้างอิง'),
        'ยืมไม่ได้: ' . mb_substr($e->getMessage(), 0, 70),
        'ถูกปฏิเสธแต่ข้อความไม่บอกว่าเพราะเป็นหนังสืออ้างอิง: ' . $e->getMessage());
}
check('REF-A2', (int) $pdo->query("SELECT COUNT(*) FROM borrows WHERE book_id = $refBookId")->fetchColumn() === 0,
    'ไม่มีรายการยืมเกิดขึ้นเลย', 'มีรายการยืมหลุดเข้าไปในฐานข้อมูล');

// A3: stock ต้องไม่ถูกหัก
check('REF-A3', (int) $pdo->query("SELECT available FROM books WHERE id = $refBookId")->fetchColumn() === 3,
    'stock ไม่ถูกหัก (ยังเหลือ 3)', 'stock ถูกหักทั้งที่ยืมไม่สำเร็จ');

// A4: จองไม่ได้
try {
    $reservationService->createReservation($memberId, $refBookId);
    fail('REF-A4', 'จองหนังสืออ้างอิงสำเร็จ ทั้งที่ไม่ควรได้');
} catch (Exception $e) {
    check('REF-A4', str_contains($e->getMessage(), 'อ้างอิง'),
        'จองไม่ได้: ' . mb_substr($e->getMessage(), 0, 70),
        'ถูกปฏิเสธแต่ข้อความไม่ชัด: ' . $e->getMessage());
}
check('REF-A5', (int) $pdo->query("SELECT COUNT(*) FROM reservations WHERE book_id = $refBookId")->fetchColumn() === 0,
    'ไม่มีการจองเกิดขึ้นเลย', 'มีการจองหลุดเข้าไป');

// A6: เล่มปกติต้องยังยืมได้ (กันด่านกว้างเกินไปจนบล็อกทุกเล่ม)
try {
    $r = $borrowService->createBorrow($memberId, [$normalBookId]);
    check('REF-A6', !empty($r['success']), 'เล่มปกติยังยืมได้ตามเดิม', 'เล่มปกติกลับยืมไม่ได้');
} catch (Exception $e) {
    fail('REF-A6', 'เล่มปกติยืมไม่ได้: ' . $e->getMessage());
}

// A7: เล่มที่ยืมไปแล้วค่อยถูกตั้งเป็นอ้างอิง → ต้องคืนได้ ไม่ถูกขังไว้
$pdo->exec("UPDATE books SET is_reference = 1 WHERE id = $normalBookId");
$borrowId = (int) $pdo->query("SELECT id FROM borrows WHERE book_id = $normalBookId AND status = 'borrowing' LIMIT 1")->fetchColumn();
try {
    $ret = $borrowService->returnBook($borrowId);
    check('REF-A7', !empty($ret['success']),
        'เล่มที่ยืมไปก่อนถูกตั้งเป็นอ้างอิง ยังคืนได้ตามปกติ',
        'คืนไม่ได้ — สมาชิกจะถูกขังหนังสือไว้');
} catch (Exception $e) {
    fail('REF-A7', 'คืนไม่ได้: ' . $e->getMessage());
}
$pdo->exec("UPDATE books SET is_reference = 0 WHERE id = $normalBookId");

// A8: ปิด flag แล้วต้องกลับมายืมได้
$pdo->exec("UPDATE books SET is_reference = 0 WHERE id = $refBookId");
try {
    $r = $borrowService->createBorrow($memberId, [$refBookId]);
    check('REF-A8', !empty($r['success']), 'ปิดสถานะอ้างอิงแล้วกลับมายืมได้', 'ปิดแล้วยังยืมไม่ได้');
    $bid = (int) $pdo->query("SELECT id FROM borrows WHERE book_id = $refBookId LIMIT 1")->fetchColumn();
    $borrowService->returnBook($bid);
} catch (Exception $e) {
    fail('REF-A8', 'ปิดสถานะแล้วยังยืมไม่ได้: ' . $e->getMessage());
}
$pdo->exec("UPDATE books SET is_reference = 1 WHERE id = $refBookId");

echo "\n── B. ผ่าน HTTP จริง (ยิงข้ามหน้าจอ) ──\n";

$login = http('GET', "$BASE_URL/login.php");
http('POST', "$BASE_URL/login.php", [
    'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD, 'csrf_token' => csrf($login['body']),
]);
$form = http('GET', "$BASE_URL/admin/borrow_form.php");

if ($form['code'] !== 200) {
    fail('REF-B0', 'เปิดหน้าบันทึกการยืมไม่ได้ (HTTP ' . $form['code'] . ') — ข้ามหมวด B');
} else {
    // B1: ยิง POST ตรง ๆ ต้องถูกปฏิเสธ (defence in depth — ไม่ใช่แค่ซ่อนปุ่ม)
    $res = http('POST', "$BASE_URL/admin/borrow_form.php", [
        'csrf_token' => csrf($form['body']),
        'user_id' => $memberId,
        'book_ids' => [$refBookId],
        'borrow_days' => 7,
    ]);
    check('REF-B1', str_contains($res['body'], 'อ้างอิง')
        && (int) $pdo->query("SELECT COUNT(*) FROM borrows WHERE book_id = $refBookId AND status='borrowing'")->fetchColumn() === 0,
        'ยิง POST ตรงข้ามหน้าจอก็ยังถูกปฏิเสธ', 'ยิง POST ตรงแล้วยืมได้ — ด่านอยู่แค่หน้าจอ');

    // B2: สแกนบาร์โค้ดต้องเตือน
    $form2 = http('GET', "$BASE_URL/admin/borrow_form.php");
    $scan = http('POST', "$BASE_URL/admin/borrow_form.php", [
        'action' => 'scan', 'type' => 'book', 'id' => $refBookId, 'csrf_token' => csrf($form2['body']),
    ]);
    $json = json_decode($scan['body'], true);
    check('REF-B2', is_array($json) && ($json['success'] ?? true) === false && str_contains($json['message'] ?? '', 'อ้างอิง'),
        'สแกนแล้วเตือนทันที: ' . mb_substr($json['message'] ?? '-', 0, 50),
        'สแกนแล้วผ่าน — เจ้าหน้าที่จะรู้ตัวตอนกดบันทึกแล้ว');

    // B3: ไม่โผล่ในดรอปดาวน์เลือกหนังสือ
    check('REF-B3', !str_contains($form['body'], 'value="' . $refBookId . '"'),
        'ไม่โผล่ในดรอปดาวน์เลือกหนังสือ', 'ยังโผล่ให้เลือกได้ในดรอปดาวน์');

    // B4/B5: ตัวกรองในหน้าจัดการหนังสือ
    $onlyRef = http('GET', "$BASE_URL/admin/books.php?is_reference=1&search=" . urlencode($TAG));
    check('REF-B4', str_contains($onlyRef['body'], "[$TAG] พจนานุกรมทดสอบ")
        && !str_contains($onlyRef['body'], "[$TAG] นวนิยายทดสอบ"),
        'ตัวกรอง "อ้างอิง" แสดงเฉพาะเล่มอ้างอิง', 'ตัวกรองอ้างอิงกรองไม่ถูก');

    $onlyNormal = http('GET', "$BASE_URL/admin/books.php?is_reference=0&search=" . urlencode($TAG));
    check('REF-B5', str_contains($onlyNormal['body'], "[$TAG] นวนิยายทดสอบ")
        && !str_contains($onlyNormal['body'], "[$TAG] พจนานุกรมทดสอบ"),
        'ตัวกรอง "ยืมออกได้" แสดงเฉพาะเล่มปกติ', 'ตัวกรองยืมออกได้กรองไม่ถูก');

    // B6: ลิงก์เลขหน้าต้องพาตัวกรองไปด้วย (บทเรียนจาก F-37)
    $listAll = http('GET', "$BASE_URL/admin/books.php?is_reference=0");
    check('REF-B6', preg_match('/href="\?[^"]*is_reference=0[^"]*page=\d+"/', $listAll['body']) === 1,
        'ลิงก์เลขหน้าพาตัวกรองไปด้วย', 'กดหน้า 2 แล้วตัวกรองหลุด');
}

echo "\n── C. ฝั่งสมาชิก: เห็นได้ ค้นเจอ แต่จองไม่ได้ ──\n";

$stuJar = tempnam(sys_get_temp_dir(), 'bbrefstu');
$loginS = http('GET', "$BASE_URL/login.php", [], $stuJar);
http('POST', "$BASE_URL/login.php", [
    'email' => $memberEmail, 'password' => '123456', 'csrf_token' => csrf($loginS['body']),
], $stuJar);

// C1: ยังค้นเจอ (ต่างจาก is_visible = 0 ที่หายไปเลย)
$search = http('GET', "$BASE_URL/index.php?search=" . urlencode($TAG), [], $stuJar);
check('REF-C1', str_contains($search['body'], "[$TAG] พจนานุกรมทดสอบ"),
    'สมาชิกยังค้นเจอหนังสืออ้างอิง (ต่างจากการซ่อน)', 'หนังสืออ้างอิงหายจากผลค้นหา — ผิดวัตถุประสงค์');

// C2/C3: หน้ารายละเอียดบอกให้อ่านที่ห้องสมุด และไม่มีปุ่มจอง
$detail = http('GET', "$BASE_URL/book.php?id=$refBookId", [], $stuJar);
check('REF-C2', str_contains($detail['body'], 'อ่านที่ห้องสมุดเท่านั้น'),
    'หน้ารายละเอียดขึ้น "อ่านที่ห้องสมุดเท่านั้น"', 'ไม่มีข้อความอธิบายให้สมาชิกเข้าใจ');
check('REF-C3', !preg_match('/onclick="reserveBook\(' . $refBookId . '\)"/', $detail['body']),
    'ไม่มีปุ่มจองบนหน้ารายละเอียด', 'ยังมีปุ่มจองให้กดอยู่');

// C4: ยิง API จองตรง ๆ ต้องถูกปฏิเสธ
$reserve = http('POST', "$BASE_URL/api/reserve_book.php", [
    'book_id' => $refBookId,
    'csrf_token' => preg_match('/csrf_token=([A-Za-z0-9]+)/', $detail['body'], $m) ? $m[1] : '',
], $stuJar);
$rj = json_decode($reserve['body'], true);
check('REF-C4', is_array($rj) && ($rj['success'] ?? true) === false,
    'ยิง API จองตรง ๆ ถูกปฏิเสธ: ' . mb_substr($rj['message'] ?? '-', 0, 55),
    'ยิง API จองตรงแล้วจองสำเร็จ');

echo "\n── D. ค่าเริ่มต้นไม่กระทบของเดิม ──\n";
$refCount = (int) $pdo->query("SELECT COUNT(*) FROM books WHERE is_reference = 1")->fetchColumn();
$total    = (int) $pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();
// 🧠 เจตนาของเคสนี้: จับกรณีมีใครไปใส่ logic เดา is_reference จากหมวดหมู่หรือชื่อเรื่อง
//    ซึ่ง migration เขียนกำกับไว้ชัดว่าห้ามทำ (บางห้องสมุดใช้ชื่อหมวด "หนังสืออ้างอิง"
//    กับเล่มที่ยืมออกได้) · ไม่ได้จำกัดว่าห้องสมุดตั้งอ้างอิงได้กี่เล่ม
//    → วัดเป็นสัดส่วน ไม่ใช่จำนวนตายตัว จะได้ไม่พังเวลาข้อมูลสาธิตโตขึ้น
$refRatio = $total > 0 ? $refCount / $total : 0;
check('REF-D1', $refRatio < 0.20,
    sprintf('หนังสือส่วนใหญ่ยังยืมออกได้ตามเดิม (อ้างอิง %d จาก %d เล่ม = %.1f%%)', $refCount, $total, $refRatio * 100),
    sprintf('มีหนังสือถูกตั้งเป็นอ้างอิงมากผิดปกติ (%d จาก %d = %.1f%%) — อาจมีใครใส่ logic เดาจากหมวดหมู่', $refCount, $total, $refRatio * 100));

// ============================================================
// CLEANUP
// ============================================================
echo "\n── CLEANUP ──\n";
$pdo->exec("DELETE FROM payments WHERE borrow_id IN (SELECT id FROM borrows WHERE user_id = $memberId)");
$pdo->exec("DELETE FROM borrows WHERE user_id = $memberId OR book_id IN ($refBookId, $normalBookId)");
$pdo->exec("DELETE FROM reservations WHERE user_id = $memberId OR book_id IN ($refBookId, $normalBookId)");
$pdo->exec("DELETE FROM books WHERE id IN ($refBookId, $normalBookId)");
$pdo->exec("DELETE FROM users WHERE id = $memberId");
@unlink($COOKIE);
@unlink($stuJar);
echo "  ลบหนังสือทดสอบ 2 เล่ม สมาชิกทดสอบ 1 คน และรายการที่เกี่ยวข้องทั้งหมด\n";

$pct = $results['total'] > 0 ? round($results['passed'] / $results['total'] * 100, 1) : 0;
echo "\n══════════════════════════════════════\n";
echo " RESULTS: {$results['passed']}/{$results['total']} passed ($pct%)";
if ($results['failed'] > 0) echo " | {$results['failed']} FAILED";
echo "\n══════════════════════════════════════\n\n";

exit($results['failed'] > 0 ? 1 : 0);
