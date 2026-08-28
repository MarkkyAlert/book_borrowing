<?php

/**
 * ทดสอบ "ต่ออายุการยืม" (ROADMAP ข้อ 3)
 *
 * ==========================================================================
 * 🎯 กฎที่ต้องเป็นจริง
 * ==========================================================================
 * ✅ ต่อได้เมื่อ: ยังไม่เกินกำหนด · ยังไม่เต็มโควตา · ไม่มีคนจองเล่มนั้นรออยู่
 * ❌ ต่อไม่ได้เมื่อ: เลยกำหนด · คืนไปแล้ว · ต่อครบแล้ว · มีคนจองรอ
 * 📅 กำหนดคืนใหม่นับจาก **กำหนดเดิม** ไม่ใช่จากวันนี้
 * 📦 ต่ออายุต้องไม่แตะสต็อก — หนังสือยังอยู่กับคนเดิม
 *
 * 🔴 เคสที่สำคัญที่สุดคือ RENEW-A5:
 *    ระบบไม่เก็บค่าปรับของรายการที่ยังไม่คืน (คำนวณสดจาก due_date ตอนคืน)
 *    ถ้าเผลอเปิดให้ต่ออายุตอนเลยกำหนด → เลื่อน due_date = **ลบค่าปรับที่เกิดแล้วทิ้ง**
 *    กลายเป็นช่องหนีค่าปรับที่เจ้าหน้าที่กดให้เองได้
 *    เคสนี้จึงพิสูจน์ทั้ง "ถูกปฏิเสธ" และ "ค่าปรับยังอยู่เท่าเดิม"
 *
 * 🧹 เก็บกวาดผ่าน register_shutdown_function — ทำงานแม้เทสต์ตายกลางคัน (บทเรียนจาก F-52)
 *
 * 📌 การใช้งาน: php tests/test_renew_borrow.php [รหัสผ่าน admin]
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
require_once __DIR__ . '/../app/Repositories/ReservationRepository.php';
require_once __DIR__ . '/../app/Repositories/UserRepository.php';
require_once __DIR__ . '/../app/Services/BorrowService.php';

$BASE_URL       = rtrim(APP_URL, '/');
$ADMIN_EMAIL    = 'admin@library.com';
$ADMIN_PASSWORD = $argv[1] ?? '123456';
$TAG            = 'RENEWTEST' . getmypid();

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
function expectFail(string $id, callable $fn, string $word, string $what): void
{
    try {
        $fn();
        fail($id, "$what ผ่านไปได้ ทั้งที่ไม่ควร");
    } catch (Exception $e) {
        check($id, str_contains($e->getMessage(), $word),
            "$what ถูกปฏิเสธ: " . mb_substr($e->getMessage(), 0, 62),
            "$what ถูกปฏิเสธแต่ข้อความไม่ตรงที่คาด: " . $e->getMessage());
    }
}

$COOKIE = tempnam(sys_get_temp_dir(), 'bbrenew');
function http(string $method, string $url, array $fields = []): array
{
    global $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $COOKIE, CURLOPT_COOKIEFILE => $COOKIE,
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
echo "║  ต่ออายุการยืม (ROADMAP ข้อ 3)                             ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "  ตั้งไว้: ต่อได้ " . MAX_RENEW_COUNT . " ครั้ง · ครั้งละ " . DEFAULT_BORROW_DAYS . " วัน\n";

$pdo = getDB();
$bookRepo = new \App\Repositories\BookRepository($pdo);
$service  = new \App\Services\BorrowService($pdo);

// ── สร้างของทดสอบ ──
$bookId = $bookRepo->create(['title' => "[$TAG] หนังสือทดสอบ", 'author' => 'ผู้แต่งทดสอบ', 'quantity' => 10]);
$hash = hashPassword('123456');
$stmt = $pdo->prepare("INSERT INTO users (name, email, password, phone, role) VALUES (?, ?, ?, '0800000000', 'member')");
$stmt->execute(["[$TAG] สมาชิกทดสอบ", strtolower($TAG) . '@test.local', $hash]);
$memberId = (int) $pdo->lastInsertId();

// 🧹 เก็บกวาดแบบรับประกัน — ทำงานทุกทางออกแม้เกิด fatal error
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

/**
 * สร้างรายการยืมที่ครบกำหนดในอีกกี่วัน (ติดลบ = เลยกำหนดมาแล้ว)
 *
 * ⚠️ ต้องหัก available ด้วยเหมือนการยืมจริง ไม่งั้น fixture เองจะทำ invariant พัง
 *    แล้วเคส RENEW-B4 จะแดงโดยที่ฟีเจอร์ไม่ได้ผิดอะไร (เจอมาแล้วตอนเขียนไฟล์นี้)
 */
$makeBorrow = function (int $dueInDays, int $renewCount = 0, string $status = 'borrowing') use ($pdo, $memberId, $bookId): int {
    $stmt = $pdo->prepare("
        INSERT INTO borrows (user_id, book_id, borrow_date, due_date, renew_count, status, fine_amount, created_at)
        VALUES (?, ?, DATE_SUB(CURDATE(), INTERVAL 7 DAY), DATE_ADD(CURDATE(), INTERVAL ? DAY), ?, ?, 0, NOW())
    ");
    $stmt->execute([$memberId, $bookId, $dueInDays, $renewCount, $status]);

    // ⚠️ ต้องอ่าน lastInsertId() ก่อนสั่ง query อื่น ไม่งั้นจะได้ 0 จาก UPDATE ที่ตามมา
    $id = (int) $pdo->lastInsertId();

    // 📦 เฉพาะที่ยังไม่คืนเท่านั้นที่กินสต็อก (รายการ returned ไม่หัก)
    if ($status === 'borrowing') {
        $pdo->exec("UPDATE books SET available = available - 1 WHERE id = $bookId AND available > 0");
    }
    return $id;
};
$dueOf = fn(int $id) => $pdo->query("SELECT due_date FROM borrows WHERE id = $id")->fetchColumn();

echo "\n── A. กฎการต่ออายุ ──\n";

// A1: ต่อได้ + เลื่อนจากกำหนดเดิม
$b1 = $makeBorrow(3);
$oldDue = $dueOf($b1);
$r = $service->renewBorrow($b1);
$expected = date('Y-m-d', strtotime($oldDue . ' +' . DEFAULT_BORROW_DAYS . ' days'));
check('RENEW-A1', !empty($r['success']) && $dueOf($b1) === $expected,
    "ต่ออายุได้ — กำหนดคืน $oldDue → " . $dueOf($b1), 'ต่ออายุไม่สำเร็จ หรือวันที่ไม่ตรง');

check('RENEW-A2', (int) $pdo->query("SELECT renew_count FROM borrows WHERE id = $b1")->fetchColumn() === 1,
    'renew_count นับเป็น 1', 'renew_count ไม่ถูกนับ');

// A3: เลื่อนจากกำหนดเดิม ไม่ใช่จากวันนี้ — ต่อเร็วหรือช้าได้ผลเท่ากัน
$b2 = $makeBorrow(6);
$old2 = $dueOf($b2);
$service->renewBorrow($b2);
check('RENEW-A3', $dueOf($b2) === date('Y-m-d', strtotime($old2 . ' +' . DEFAULT_BORROW_DAYS . ' days')),
    'นับจากกำหนดเดิม ไม่ใช่จากวันนี้ (ต่อเร็วไม่เสียเปรียบ)', 'นับจากวันนี้ ทำให้ต่อเร็วได้วันน้อยกว่า');

// A4: ต่อครบโควตาแล้ว
expectFail('RENEW-A4', fn() => $service->renewBorrow($b1),
    'ต่ออายุครบ', 'ต่ออายุเกินโควตา');

echo "\n── 🔴 A5. เลยกำหนดแล้วต้องต่อไม่ได้ (กันช่องลบค่าปรับ) ──\n";

$b3 = $makeBorrow(-5);   // เลยกำหนดมา 5 วัน
$dueBefore = $dueOf($b3);
$fineBefore = $service->calculateFine($dueBefore, null);

expectFail('RENEW-A5', fn() => $service->renewBorrow($b3),
    'เลยกำหนดคืนมาแล้ว', 'ต่ออายุรายการที่เลยกำหนด');

$fineAfter = $service->calculateFine($dueOf($b3), null);
check('RENEW-A6',
    $dueOf($b3) === $dueBefore && (float) $fineAfter['amount'] === (float) $fineBefore['amount'],
    sprintf('กำหนดคืนไม่ถูกเลื่อน และค่าปรับยังเป็น %s บาทเท่าเดิม — ไม่มีช่องลบค่าปรับ',
        number_format($fineBefore['amount'])),
    'กำหนดคืนถูกเลื่อนหรือค่าปรับหายไป — เกิดช่องหนีค่าปรับ');

echo "\n── B. เงื่อนไขอื่น ──\n";

// B1: คืนไปแล้วต่อไม่ได้
$b4 = $makeBorrow(3, 0, 'returned');
expectFail('RENEW-B1', fn() => $service->renewBorrow($b4),
    'คืนไปแล้ว', 'ต่ออายุรายการที่คืนแล้ว');

// B2: มีคนจองเล่มนี้รออยู่ → ต่อไม่ได้ (ไม่งั้นคนที่รอไม่มีวันได้หนังสือ)
$b5 = $makeBorrow(3);
$stmt = $pdo->prepare("INSERT INTO reservations (user_id, book_id, status, expires_at, created_at) VALUES (?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 2 DAY), NOW())");
$stmt->execute([$memberId, $bookId]);
$resId = (int) $pdo->lastInsertId();   // ⚠️ อ่านก่อนสั่ง UPDATE ด้านล่าง
$pdo->exec("UPDATE books SET available = available - 1 WHERE id = $bookId AND available > 0");  // 📦 จองก็กินสต็อก

expectFail('RENEW-B2', fn() => $service->renewBorrow($b5),
    'จองหนังสือเล่มนี้รออยู่', 'ต่ออายุทั้งที่มีคนจองรอ');

$pdo->exec("DELETE FROM reservations WHERE id = $resId");
$pdo->exec("UPDATE books SET available = available + 1 WHERE id = $bookId");  // 📦 ยกเลิกจอง = คืนสต็อก
$r = $service->renewBorrow($b5);
check('RENEW-B3', !empty($r['success']), 'พอไม่มีคนจองแล้ว ต่ออายุได้ตามปกติ', 'ยกเลิกการจองแล้วยังต่อไม่ได้');

// B4: สต็อกต้องไม่เปลี่ยนเลยจากการต่ออายุ
$bad = (int) $pdo->query("
    SELECT COUNT(*) FROM books b WHERE b.available <> GREATEST(0, b.quantity
      - (SELECT COUNT(*) FROM borrows x WHERE x.book_id = b.id AND x.status = 'borrowing')
      - (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.id AND r.status = 'pending'))
")->fetchColumn();
check('RENEW-B4', $bad === 0, 'ต่ออายุไม่แตะสต็อก — invariant ถูกต้องทุกเล่ม', "สต็อกเพี้ยน $bad เล่ม");

echo "\n── C. ผ่านหน้าเว็บ ──\n";

$login = http('GET', "$BASE_URL/login.php");
http('POST', "$BASE_URL/login.php", ['email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD, 'csrf_token' => csrf($login['body'])]);
$page = http('GET', "$BASE_URL/admin/borrows.php");

if ($page['code'] !== 200) {
    fail('RENEW-C0', 'เปิดหน้ายืม-คืนไม่ได้ (HTTP ' . $page['code'] . ') — ข้ามหมวด C');
} else {
    $b6 = $makeBorrow(4);
    $old6 = $dueOf($b6);
    $form = http('GET', "$BASE_URL/admin/borrows.php");
    http('POST', "$BASE_URL/admin/borrows.php", [
        'csrf_token' => csrf($form['body']), 'action' => 'renew', 'borrow_id' => $b6,
    ]);
    check('RENEW-C1', $dueOf($b6) === date('Y-m-d', strtotime($old6 . ' +' . DEFAULT_BORROW_DAYS . ' days')),
        'ต่ออายุผ่านหน้าเว็บได้', 'ต่ออายุผ่านหน้าเว็บไม่สำเร็จ');

    // ปุ่มของรายการที่ต่อไม่ได้ ต้องเป็นปุ่มเทาพร้อมบอกเหตุผล ไม่ใช่หายไปเฉย ๆ
    $listPage = http('GET', "$BASE_URL/admin/borrows.php?filter=overdue");
    check('RENEW-C2', str_contains($listPage['body'], 'เลยกำหนดคืนแล้ว ต่ออายุไม่ได้'),
        'รายการเกินกำหนดแสดงปุ่มเทาพร้อมบอกเหตุผล', 'ไม่บอกเหตุผลว่าทำไมต่ออายุไม่ได้');
}

echo "\n── D. ปิดการต่ออายุทั้งระบบได้ ──\n";

// 🧠 MAX_RENEW_COUNT เป็น constant ที่ตั้งตอนโหลด — เปลี่ยนกลางคันไม่ได้
//    จึงตั้งค่าในตาราง settings แล้วรัน process ใหม่มาตรวจ
$b7 = $makeBorrow(3);
$pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('rule_max_renew', '0')
            ON DUPLICATE KEY UPDATE setting_value = '0'");

// 🛡️ [SECURITY] เขียนไฟล์ probe ลง temp dir ของระบบ **ห้ามลงในโฟลเดอร์โปรเจกต์**
//    โฟลเดอร์โปรเจกต์คือ document root — ไฟล์ .php ที่วางไว้ตรงนั้นเปิดผ่านเว็บได้ทันที
//    ถ้าเทสต์ตายกลางคันก่อน unlink จะเหลือไฟล์รันได้ค้างอยู่ในเว็บ
//    → ใช้ ROOT ที่ส่งเข้าไปแทน __DIR__ เพราะไฟล์อยู่คนละที่กับโปรเจกต์แล้ว
$root = str_replace('\\', '/', dirname(__DIR__));
$script = <<<SUB
<?php
\$_SERVER["REQUEST_METHOD"]="GET"; \$_SERVER["PHP_SELF"]="sub.php"; \$_SERVER["REMOTE_ADDR"]="127.0.0.1";
define('PROBE_ROOT', '{$root}');
require PROBE_ROOT . "/includes/config.php";
require PROBE_ROOT . "/includes/db.php";
require PROBE_ROOT . "/includes/functions.php";
require PROBE_ROOT . "/app/Repositories/BookRepository.php";
require PROBE_ROOT . "/app/Repositories/BorrowRepository.php";
require PROBE_ROOT . "/app/Repositories/PaymentRepository.php";
require PROBE_ROOT . "/app/Repositories/ReservationRepository.php";
require PROBE_ROOT . "/app/Repositories/UserRepository.php";
require PROBE_ROOT . "/app/Services/BorrowService.php";
\$svc = new App\Services\BorrowService(getDB());
echo "MAX=" . MAX_RENEW_COUNT . "|";
try { \$svc->renewBorrow((int) \$argv[1]); echo "RENEWED"; }
catch (Exception \$e) { echo "BLOCKED:" . \$e->getMessage(); }
SUB;
$tmpScript = tempnam(sys_get_temp_dir(), 'bbprobe') . '.php';
file_put_contents($tmpScript, $script);
$out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmpScript) . ' ' . (int) $b7 . ' 2>&1');
@unlink($tmpScript);
@unlink(substr($tmpScript, 0, -4));   // tempnam สร้างไฟล์ไม่มีนามสกุลไว้ด้วย
$pdo->exec("DELETE FROM settings WHERE setting_key = 'rule_max_renew'");

check('RENEW-D1', str_contains($out, 'MAX=0') && str_contains($out, 'ปิดการต่ออายุ'),
    'ตั้งค่าเป็น 0 จากหน้าตั้งค่าระบบ = ปิดการต่ออายุทั้งระบบ',
    'ตั้งเป็น 0 แล้วยังต่ออายุได้: ' . mb_substr(trim($out), 0, 90));

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
