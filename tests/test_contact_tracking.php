<?php

/**
 * บันทึกการโทรตาม — "โทรใครไปแล้วบ้าง"
 *
 * ==========================================================================
 * 🔴 ที่มา: UAT รอบ 2 ข้อ ฎ.7
 * ==========================================================================
 * ระบบทำใบโทรตามให้ กดเบอร์โทรออกได้ แต่พอวางสายแล้ว **ไม่มีที่จด**
 * บรรณารักษ์คนเดียวโทร 30 สาย พรุ่งนี้เปิดมาก็ไม่รู้ว่าโทรใครไปแล้ว
 * ต้องไล่ใหม่ทั้งใบทุกวัน หรือไม่ก็โทรซ้ำคนเดิมจนโดนบ่น
 *
 * 🧠 หัวใจของไฟล์นี้ 2 อย่าง:
 *    1. CALL-2 — ยามกันซ้ำรอยบั๊ก ก.6 (ฟิลด์ที่ไม่ได้ส่งมาต้องไม่ถูกล้าง)
 *       ทำงานอื่นกับรายการยืม (ต่ออายุ/คืน) ต้องไม่ลบประวัติการโทรทิ้ง
 *    2. CALL-4 — รายการเดิมทั้ง 2,000 กว่าแถวต้องเป็น "ยังไม่เคยโทร"
 *       ไม่ใช่ "โทรแล้วเมื่อวันที่อัปเกรด" ซึ่งจะทำให้ทั้งใบดูเหมือนโทรครบแล้ว
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/report_helper.php';

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  บันทึกการโทรตาม (UAT รอบ 2 ข้อ ฎ.7)                       ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";

$results = ['passed' => 0, 'failed' => 0, 'total' => 0];
function pass(string $id, string $msg): void {
    global $results; $results['passed']++; $results['total']++;
    echo "  \033[32m✅ {$id}\033[0m: {$msg}\n";
}
function fail(string $id, string $msg): void {
    global $results; $results['failed']++; $results['total']++;
    echo "  \033[31m❌ {$id}\033[0m: {$msg}\n";
}
function check(string $id, bool $ok, string $okMsg, string $failMsg): void {
    $ok ? pass($id, $okMsg) : fail($id, $failMsg);
}

$TAG      = 'CALL' . getmypid();
$pdo      = getDB();
$bookRepo = new \App\Repositories\BookRepository($pdo);
$service  = new \App\Services\BorrowService($pdo);
$repRepo  = new \App\Repositories\ReportRepository($pdo);

// ── สร้างของทดสอบ ──
$bookId = $bookRepo->create(['title' => "[$TAG] หนังสือทดสอบ", 'author' => 'ผู้แต่งทดสอบ', 'quantity' => 10]);
$stmt = $pdo->prepare("INSERT INTO users (name, email, password, phone, role) VALUES (?, ?, ?, '0891234567', 'member')");
$stmt->execute(["[$TAG] สมาชิกทดสอบ", strtolower($TAG) . '@test.local', hashPassword('123456')]);
$memberId = (int) $pdo->lastInsertId();
$staffId  = (int) $pdo->query("SELECT id FROM users WHERE role IN ('admin','staff') ORDER BY id LIMIT 1")->fetchColumn();

// 🧹 เก็บกวาดแบบรับประกัน — ทำงานทุกทางออกแม้เกิด fatal error
register_shutdown_function(function () use ($pdo, $memberId, $bookId): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec("DELETE FROM payments WHERE borrow_id IN (SELECT id FROM borrows WHERE user_id = $memberId)");
    $pdo->exec("DELETE FROM borrows WHERE user_id = $memberId OR book_id = $bookId");
    $pdo->exec("DELETE FROM reservations WHERE user_id = $memberId OR book_id = $bookId");
    $pdo->exec("DELETE FROM books WHERE id = $bookId");
    $pdo->exec("DELETE FROM users WHERE id = $memberId");
});

$makeBorrow = function (int $dueInDays) use ($pdo, $memberId, $bookId): int {
    $stmt = $pdo->prepare("
        INSERT INTO borrows (user_id, book_id, borrow_date, due_date, renew_count, status, fine_amount, created_at)
        VALUES (?, ?, DATE_SUB(CURDATE(), INTERVAL 7 DAY), DATE_ADD(CURDATE(), INTERVAL ? DAY), 0, 'borrowing', 0, NOW())
    ");
    $stmt->execute([$memberId, $bookId, $dueInDays]);
    $id = (int) $pdo->lastInsertId();
    $pdo->exec("UPDATE books SET available = available - 1 WHERE id = $bookId AND available > 0");
    return $id;
};
$contactOf = fn(int $id) => $pdo->query(
    "SELECT contacted_at, contacted_by, contact_note FROM borrows WHERE id = $id"
)->fetch(PDO::FETCH_ASSOC);

// ════════════════════════════════════════════════════════════
echo "\n── A. จดว่าโทรแล้ว ──\n";

// CALL-1: จดครั้งแรก → ต้องได้ครบทั้ง 3 ช่อง
$b1 = $makeBorrow(-5);
$service->recordContact($b1, 'รับสาย จะมาคืนพรุ่งนี้', $staffId);
$c = $contactOf($b1);
check('CALL-1',
    $c['contacted_at'] !== null
        && (int) $c['contacted_by'] === $staffId
        && $c['contact_note'] === 'รับสาย จะมาคืนพรุ่งนี้'
        && abs(time() - strtotime($c['contacted_at'])) < 120,
    "จดได้ครบ: เมื่อ {$c['contacted_at']} · โดยเจ้าหน้าที่ #{$c['contacted_by']} · ผล \"{$c['contact_note']}\"",
    '🔴 จดไม่ครบ: ' . json_encode($c, JSON_UNESCAPED_UNICODE));

// CALL-1b: โทรซ้ำรอบ 2 → ทับของเดิม (โทรหลายรอบเป็นเรื่องปกติ ห้ามบล็อก)
$service->recordContact($b1, 'โทรรอบสอง ไม่รับสาย', $staffId);
$c = $contactOf($b1);
check('CALL-1b', $c['contact_note'] === 'โทรรอบสอง ไม่รับสาย',
    'โทรซ้ำได้ ระบบเก็บผลครั้งล่าสุด',
    "🔴 จดรอบสองไม่ติด ยังเป็น \"{$c['contact_note']}\"");

// CALL-1c: ไม่กรอกผลการโทรก็จดได้ (แค่รู้ว่าโทรไปแล้วก็มีประโยชน์)
$b2 = $makeBorrow(-3);
$service->recordContact($b2, '', $staffId);
$c = $contactOf($b2);
check('CALL-1c', $c['contacted_at'] !== null && $c['contact_note'] === null,
    'ไม่กรอกผลการโทรก็จดวันที่ให้ ช่องหมายเหตุเป็นค่าว่าง',
    '🔴 ' . ($c['contacted_at'] === null ? 'ไม่ได้จดวันที่' : "หมายเหตุกลายเป็น \"{$c['contact_note']}\" แทนที่จะว่าง"));

// CALL-1d: หมายเหตุยาวเกินคอลัมน์ → ต้องเตือน ไม่ใช่ให้ MySQL ตัดเงียบ
//    🧠 ถ้าปล่อยผ่าน ข้อความจะถูกตัดกลางคันแล้วบรรณารักษ์อ่านผลการโทรไม่รู้เรื่อง
$tooLong = str_repeat('ก', 256);
$rejected = false;
try { $service->recordContact($b2, $tooLong, $staffId); }
catch (Exception $e) { $rejected = str_contains($e->getMessage(), '255'); }
$stillShort = mb_strlen((string) $contactOf($b2)['contact_note']);
check('CALL-1d', $rejected && $stillShort === 0,
    'หมายเหตุเกิน 255 ตัวถูกปฏิเสธพร้อมบอกเหตุผล ไม่ถูกตัดเงียบ ๆ',
    '🔴 ' . (!$rejected ? 'ยอมรับข้อความยาวเกิน ' : '') . "ค่าที่เก็บจริงยาว {$stillShort} ตัว");

// CALL-1e: id มั่ว → ต้อง error ไม่ใช่เขียนลงฐานเงียบ ๆ
$badBlocked = false;
try { $service->recordContact(999999999, 'ทดสอบ', $staffId); }
catch (Exception $e) { $badBlocked = true; }
check('CALL-1e', $badBlocked,
    'จดโทรใส่รายการที่ไม่มีอยู่จริงไม่ได้',
    '🔴 ยิง borrow_id มั่วเข้าไปแล้วผ่าน');

// ════════════════════════════════════════════════════════════
echo "\n── B. ยามกันซ้ำรอยบั๊ก ก.6 ──\n";
echo "  (ฟิลด์ที่ไม่ได้ส่งมาในการทำงานอื่น ต้องไม่ถูกล้างทิ้ง)\n";

// CALL-2: ต่ออายุ แล้วประวัติการโทรต้องยังอยู่
$b3 = $makeBorrow(3);
$service->recordContact($b3, 'เตือนก่อนครบกำหนดแล้ว', $staffId);
$before = $contactOf($b3);
$service->renewBorrow($b3);
$after = $contactOf($b3);
check('CALL-2a', $after['contacted_at'] === $before['contacted_at']
        && $after['contact_note'] === $before['contact_note']
        && (int) $after['contacted_by'] === (int) $before['contacted_by'],
    'ต่ออายุการยืมแล้ว ประวัติการโทรยังอยู่ครบ',
    '🔴 ต่ออายุแล้วประวัติการโทรหาย: ' . json_encode($after, JSON_UNESCAPED_UNICODE));

// CALL-2b: คืนหนังสือ แล้วประวัติการโทรต้องยังอยู่ (ใช้ตรวจย้อนหลังว่าโทรตามได้ผลไหม)
$service->returnBook($b3, false, $staffId);
$after = $contactOf($b3);
check('CALL-2b', $after['contacted_at'] === $before['contacted_at']
        && $after['contact_note'] === $before['contact_note'],
    'คืนหนังสือแล้วประวัติการโทรยังอยู่ ตรวจย้อนหลังได้ว่าโทรแล้วเขาเอามาคืนจริงไหม',
    '🔴 คืนหนังสือแล้วประวัติการโทรหาย: ' . json_encode($after, JSON_UNESCAPED_UNICODE));

// CALL-2c: ระดับซอร์ส — BorrowRepository ต้องไม่มี update() รวมที่เขียนทุกคอลัมน์
//    🧠 บั๊ก ก.6 เกิดจากการประกอบ payload ทีละคีย์ แล้วคีย์ที่ขาดไปลบข้อมูลเดิม
//       ตราบใดที่ repository นี้ใช้เมธอดเฉพาะงาน (ระบุ SET เอง) บั๊กแบบนั้นเกิดไม่ได้
$repoSrc = file_get_contents(__DIR__ . '/../app/Repositories/BorrowRepository.php');
check('CALL-2c', !preg_match('/public function update\s*\(/', $repoSrc),
    'BorrowRepository ไม่มีเมธอด update() รวม — ทุกการเขียนระบุคอลัมน์เอง',
    '🔴 มี update() รวมโผล่มา — เสี่ยงล้างคอลัมน์ที่ไม่ได้ส่งมาแบบบั๊ก ก.6');

// CALL-2d: ระดับซอร์ส — ปุ่มในหน้าเว็บต้องผ่านด่าน CSRF ก่อนเสมอ
$pageSrc  = file_get_contents(__DIR__ . '/../admin/borrows.php');
$csrfPos  = strpos($pageSrc, 'validateCSRFToken');
$actionPos = strpos($pageSrc, "'record_contact'");
check('CALL-2d', $csrfPos !== false && $actionPos !== false && $csrfPos < $actionPos,
    'การจดโทรอยู่หลังด่าน CSRF — ยิงจากเว็บอื่นมาจดแทนไม่ได้',
    '🔴 หา record_contact หรือ CSRF ไม่เจอ หรือ record_contact อยู่ก่อนด่าน CSRF');

// ════════════════════════════════════════════════════════════
echo "\n── C. ใบโทรตามที่พิมพ์ออกมา ──\n";

// CALL-3: ทั้งสองใบต้องมีคอลัมน์ "โทรแล้วเมื่อ" และหัวตารางต้องเท่าจำนวนคอลัมน์ข้อมูล
foreach (['due_soon' => 'ใบโทรตามก่อนครบกำหนด', 'overdue' => 'ใบตามหนังสือค้างส่ง'] as $type => $label) {
    $cfg  = getReportConfig($type, date('Y-m-01'), date('Y-m-d'), $repRepo);
    $keys = $cfg['data'] ? array_keys($cfg['data'][0]) : [];
    check("CALL-3:{$type}",
        in_array('โทรแล้วเมื่อ', $cfg['headers'], true)
            && in_array('contacted', $keys, true)
            && count($cfg['headers']) === count($keys),
        "{$label} มีคอลัมน์ \"โทรแล้วเมื่อ\" · หัวตาราง " . count($cfg['headers']) . " ช่องตรงกับข้อมูล",
        "🔴 {$label}: หัวตาราง " . count($cfg['headers']) . " ช่อง · ข้อมูล " . count($keys) . " ช่อง · คีย์: " . implode(',', $keys));
}

// CALL-3b: ค่าที่โชว์ต้องอ่านรู้เรื่อง — ยังไม่โทรเป็นช่องว่าง (ไว้เขียนมือ) โทรแล้วเป็นวันที่+ผล
$b4 = $makeBorrow(1);   // ครบกำหนดพรุ่งนี้ → ต้องโผล่ในใบโทรตาม
$blankRow = null; $filledRow = null;
foreach ($repRepo->getDueSoonReport(max(DUE_SOON_DAYS, 3)) as $row) {
    if (str_contains((string) $row['title'], $TAG)) $blankRow = $row;
}
$service->recordContact($b4, 'ไม่รับสาย', $staffId);
foreach ($repRepo->getDueSoonReport(max(DUE_SOON_DAYS, 3)) as $row) {
    if (str_contains((string) $row['title'], $TAG)) $filledRow = $row;
}
if ($blankRow === null || $filledRow === null) {
    fail('CALL-3b', '🔴 หนังสือทดสอบไม่โผล่ในใบโทรตาม — ตรวจค่าที่แสดงไม่ได้');
} else {
    check('CALL-3b',
        $blankRow['contacted'] === ''
            && str_contains($filledRow['contacted'], date('d/m/Y'))
            && str_contains($filledRow['contacted'], 'ไม่รับสาย'),
        "ยังไม่โทร = ช่องว่างไว้เขียนมือ · โทรแล้ว = \"{$filledRow['contacted']}\"",
        "🔴 ยังไม่โทรได้ \"{$blankRow['contacted']}\" · โทรแล้วได้ \"{$filledRow['contacted']}\"");
}

// CALL-3c: คอลัมน์ใหม่ต้องไม่ทำ CSV พัง — เบอร์โทรยังต้องมี ' นำหน้ากัน Excel ตัด 0
//    🧠 บทเรียนจาก F-44: 0891234567 กลายเป็น 891234567 แล้วเอาไปโทรตามไม่ได้
$cfg = getReportConfig('overdue', date('Y-m-01'), date('Y-m-d'), $repRepo);
$phoneOk = true; $contactOk = true;
foreach (array_slice($cfg['data'], 0, 20) as $row) {
    if (($row['phone'] ?? '') !== '' && !str_starts_with(csvReportValue('phone', $row['phone']), "'")) $phoneOk = false;
    // คอลัมน์ใหม่ต้องผ่าน csvSafeValue โดยไม่ถูกเติม ' มั่ว (ขึ้นต้นด้วยตัวเลขวันที่)
    if (csvSafeValue(csvReportValue('contacted', $row['contacted'])) !== (string) $row['contacted']) $contactOk = false;
}
check('CALL-3c', $phoneOk && $contactOk,
    'CSV: เบอร์โทรยังมี \' นำหน้ากัน Excel ตัดเลข 0 · คอลัมน์โทรแล้วเมื่อออกไปตรง ๆ',
    '🔴 ' . (!$phoneOk ? 'เบอร์โทรไม่มี \' นำหน้าแล้ว ' : '') . (!$contactOk ? 'คอลัมน์โทรแล้วเมื่อถูกแก้ค่าตอนเขียน CSV' : ''));

// ════════════════════════════════════════════════════════════
echo "\n── D. รายการเดิมหลังอัปเกรด ──\n";

// CALL-4: ทุกแถวที่มีอยู่ก่อนต้องเป็น "ยังไม่เคยโทร"
//    🔴 ถ้า migration ตั้ง DEFAULT เป็น NOW() ทั้งใบจะดูเหมือนโทรครบแล้วตั้งแต่วันอัปเกรด
$total    = (int) $pdo->query("SELECT COUNT(*) FROM borrows WHERE user_id <> $memberId")->fetchColumn();
$contacted = (int) $pdo->query("SELECT COUNT(*) FROM borrows WHERE user_id <> $memberId AND contacted_at IS NOT NULL")->fetchColumn();
check('CALL-4', $contacted === 0,
    "รายการยืมเดิมทั้ง {$total} แถวเป็น \"ยังไม่เคยโทร\" — ไม่มีแถวไหนถูกตั้งค่าให้เองตอนอัปเกรด",
    "🔴 มี {$contacted} แถวถูกทำเครื่องหมายว่าโทรแล้วทั้งที่ไม่มีใครกด");

// CALL-4b: เจ้าหน้าที่ที่โทรถูกลบ → ประวัติต้องไม่หายทั้งแถว (FK เป็น SET NULL)
$fk = $pdo->query("
    SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_borrows_contacted_by'
")->fetchColumn();
check('CALL-4b', $fk === 'SET NULL',
    'ลบบัญชีเจ้าหน้าที่แล้ว บันทึกว่า "เคยโทรแล้ว" ยังอยู่ (FK เป็น SET NULL)',
    "🔴 กติกาการลบเป็น \"{$fk}\" — ลบเจ้าหน้าที่แล้วประวัติการโทรอาจหายไปทั้งแถว");

// ════════════════════════════════════════════════════════════
echo "\n── E. เส้นทางจริงบนหน้าเว็บ ──\n";

// 🌐 ทดสอบผ่าน HTTP จริง — service ผ่านไม่ได้แปลว่าปุ่มบนหน้าจอใช้ได้
//    (เคยเจอมาแล้วว่าฟอร์มส่ง action ไม่ถึง handler เพราะชื่อ input ไปทับ form.action)
$BASE   = rtrim(APP_URL, '/');
$COOKIE = tempnam(sys_get_temp_dir(), 'bbcall');
register_shutdown_function(fn() => @unlink($COOKIE));

$http = function (string $method, string $url, array $fields = []) use ($COOKIE): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $COOKIE,
        CURLOPT_COOKIEFILE => $COOKIE, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = (string) curl_exec($ch);
    curl_close($ch);
    return $body;
};
$csrfOf = fn(string $html) => preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';

$login = $http('GET', "{$BASE}/login.php");
$home  = $http('POST', "{$BASE}/login.php", [
    'csrf_token' => $csrfOf($login), 'email' => 'admin@library.com',
    'password'   => $argv[1] ?? '123456',
]);

if (!str_contains($home, 'ออกจากระบบ') && !str_contains($home, 'แดชบอร์ด')) {
    fail('CALL-5', '🔴 ล็อกอินไม่สำเร็จ — ข้ามการทดสอบผ่านหน้าเว็บ (ตรวจ Apache/รหัสผ่านแอดมิน)');
} else {
    $b5   = $makeBorrow(-2);
    $page = $http('GET', "{$BASE}/admin/borrows.php");
    $csrf = $csrfOf($page);

    // CALL-5a: กดปุ่มบนหน้าเว็บแล้วจดติดจริง + ขึ้นข้อความยืนยัน
    $after5 = $http('POST', "{$BASE}/admin/borrows.php", [
        'csrf_token' => $csrf, 'action' => 'record_contact',
        'borrow_id'  => $b5,   'contact_note' => 'ทดสอบผ่านหน้าเว็บ',
    ]);
    $c5 = $contactOf($b5);
    check('CALL-5a',
        $c5['contacted_at'] !== null && $c5['contact_note'] === 'ทดสอบผ่านหน้าเว็บ'
            && str_contains($after5, 'บันทึกว่าโทรตามแล้ว'),
        'กดจดโทรจากหน้าเว็บแล้วบันทึกติด และขึ้นข้อความยืนยันให้เห็น',
        '🔴 ' . ($c5['contacted_at'] === null ? 'ไม่ได้บันทึกลงฐาน ' : '')
              . (!str_contains($after5, 'บันทึกว่าโทรตามแล้ว') ? 'ไม่มีข้อความยืนยันบนหน้าจอ' : ''));

    // CALL-5b: ไม่มี CSRF → ต้องไม่จด (กันเว็บอื่นยิงมาจดแทน)
    $b6 = $makeBorrow(-2);
    $http('POST', "{$BASE}/admin/borrows.php", [
        'action' => 'record_contact', 'borrow_id' => $b6, 'contact_note' => 'ยิงมั่วไม่มี token',
    ]);
    check('CALL-5b', $contactOf($b6)['contacted_at'] === null,
        'ยิงมาจดโดยไม่มี CSRF token ไม่ผ่าน',
        '🔴 จดสำเร็จทั้งที่ไม่มี CSRF token');

    // CALL-5c: ทุก id ที่สคริปต์เรียกหา ต้องมีอยู่จริงในหน้า
    //    🔴 พิมพ์ id ผิดตัวเดียว = กดปุ่มแล้วเงียบ ไม่มี error ให้เห็น ผู้ใช้คิดว่าระบบค้าง
    $js = substr($page, strpos($page, 'function openContactModal') ?: 0);
    $js = substr($js, 0, strpos($js, 'function setContactNote') ?: strlen($js));
    preg_match_all("/getElementById\('([^']+)'\)/", $js, $m);
    $ids     = array_values(array_unique($m[1]));
    $missing = array_values(array_filter($ids, fn($id) => !str_contains($page, 'id="' . $id . '"')));
    check('CALL-5c', $ids !== [] && $missing === [],
        'สคริปต์กล่องจดโทรอ้างถึง ' . count($ids) . ' id และมีอยู่จริงในหน้าครบทุกตัว',
        $ids === [] ? '🔴 หาสคริปต์กล่องจดโทรในหน้าไม่เจอ'
                    : '🔴 สคริปต์เรียกหา id ที่ไม่มีในหน้า: ' . implode(', ', $missing));

    // CALL-5d: ปุ่มขึ้นเฉพาะแถวที่ควรโทร — เล่มที่เพิ่งยืมไปไม่ต้องมีปุ่มมารก
    $farId  = $makeBorrow(DUE_SOON_DAYS + 30);
    $filter = $http('GET', "{$BASE}/admin/borrows.php?search=" . urlencode($TAG));
    $near   = str_contains($filter, 'data-borrow-id="' . $b5 . '"');
    $far    = preg_match('/btn-contact[^>]*data-borrow-id="' . $farId . '"/', $filter) === 1;
    check('CALL-5d', $near && !$far,
        'ปุ่ม "จดว่าโทรแล้ว" ขึ้นเฉพาะแถวที่เกินกำหนด/ใกล้ครบกำหนด ไม่ขึ้นกับเล่มที่เพิ่งยืมไป',
        '🔴 ' . (!$near ? 'แถวที่ควรมีปุ่มกลับไม่มี ' : '') . ($far ? 'แถวที่ยังไม่ถึงเวลาโทรกลับมีปุ่ม' : ''));
}

// ============================================================
echo "\n── CLEANUP ──\n";
echo "  ลบหนังสือ/สมาชิก/รายการยืมทดสอบทั้งหมด\n";

$pct = $results['total'] ? round($results['passed'] / $results['total'] * 100, 1) : 0;
echo "\n══════════════════════════════════════\n";
echo " RESULTS: {$results['passed']}/{$results['total']} passed ({$pct}%)";
if ($results['failed'] > 0) echo " | {$results['failed']} FAILED";
echo "\n══════════════════════════════════════\n\n";

exit($results['failed'] > 0 ? 1 : 0);
