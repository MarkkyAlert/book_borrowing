<?php

/**
 * ทดสอบ "หนังสือหาย / ชำรุด" (ROADMAP ข้อ 4)
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. กติกาการแจ้ง — ประเภท · เหตุผลบังคับ · **ห้ามคิดค่าชดใช้ 0 เงียบ ๆ**
 * B. สต็อก — quantity ลด 1 · available คงเดิม · invariant ไม่พัง · กดซ้ำไม่ลดซ้ำ
 * C. เงิน — ไม่คิดค่าปรับเกินกำหนดซ้ำ · snapshot ราคาไว้ · โผล่ในยอดค้างชำระ
 * D. ตัวเลขข้ามหน้า — ยอด "ค้างชำระ" ทั้ง 6 แหล่งต้องตรงกัน
 *                    และ "คืนวันนี้/เดือนนี้" ต้อง **ไม่ขยับ**
 * E. ย้อนการแจ้ง — สต็อกกลับมาครบ · หนี้ที่ยังไม่จ่ายถูกยกเลิก · ย้อนซ้ำไม่ได้
 * F. หน้าเว็บจริงผ่าน HTTP
 *
 * 🧹 ลบทุกอย่างที่สร้างขึ้นเมื่อจบ — อยู่ใน register_shutdown_function
 *    เพื่อให้ล้างแม้เทสต์ตายกลางคัน (บทเรียนจาก F-52)
 *
 * 📌 การใช้งาน: php tests/test_lost_damaged.php [รหัสผ่าน admin]
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
require_once __DIR__ . '/../app/Repositories/ReportRepository.php';
require_once __DIR__ . '/../app/Repositories/UserRepository.php';
require_once __DIR__ . '/../app/Services/BorrowService.php';

$BASE_URL       = rtrim(APP_URL, '/');
$ADMIN_EMAIL    = 'admin@library.com';
$ADMIN_PASSWORD = $argv[1] ?? '123456';

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

$pdo     = getDB();
$service = new App\Services\BorrowService($pdo);
$bookRepo   = new App\Repositories\BookRepository($pdo);
$borrowRepo = new App\Repositories\BorrowRepository($pdo);
$reportRepo = new App\Repositories\ReportRepository($pdo);
$paymentRepo = new App\Repositories\PaymentRepository($pdo);

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  หนังสือหาย / ชำรุด (ROADMAP ข้อ 4)                       ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

// ============================================================
// FIXTURE
// ============================================================
$created = ['books' => [], 'users' => [], 'borrows' => []];
$COOKIE  = tempnam(sys_get_temp_dir(), 'bblost');

// 🧹 ล้างเสมอ แม้เทสต์จะตายกลางคัน
$cleanupDone = false;
$cleanup = function () use (&$created, &$cleanupDone, $pdo, $COOKIE) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    echo "\n── CLEANUP ──\n";
    try {
        if ($created['borrows']) {
            $in = implode(',', array_map('intval', $created['borrows']));
            $pdo->exec("DELETE FROM payments WHERE borrow_id IN ($in)");
            $pdo->exec("DELETE FROM borrows WHERE id IN ($in)");
        }
        if ($created['books']) {
            $in = implode(',', array_map('intval', $created['books']));
            $pdo->exec("DELETE FROM reservations WHERE book_id IN ($in)");
            $pdo->exec("DELETE FROM borrows WHERE book_id IN ($in)");
            $pdo->exec("DELETE FROM books WHERE id IN ($in)");
        }
        if ($created['users']) {
            $in = implode(',', array_map('intval', $created['users']));
            $pdo->exec("DELETE FROM borrows WHERE user_id IN ($in)");
            $pdo->exec("DELETE FROM users WHERE id IN ($in)");
        }
        echo "  ลบหนังสือ/สมาชิก/รายการยืมที่สร้างขึ้นทั้งหมด\n";
    } catch (Throwable $e) {
        echo "  ⚠️ ล้างข้อมูลไม่ครบ: " . $e->getMessage() . "\n";
    }
    @unlink($COOKIE);
};
register_shutdown_function($cleanup);

// 📚 หนังสือทดสอบ — ต้องมีทั้งเล่มที่มีราคาและไม่มีราคา
$catId = (int) $pdo->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetchColumn();

$mkBook = function (string $title, ?float $price, int $qty) use ($bookRepo, $catId, &$created): int {
    $id = $bookRepo->create([
        'title' => $title, 'author' => 'ผู้แต่งทดสอบ', 'category_id' => $catId,
        'quantity' => $qty, 'price' => $price,
    ]);
    $created['books'][] = $id;
    return $id;
};

$bookPriced   = $mkBook('[LOSTTEST] เล่มที่มีราคาปก', 250.00, 3);
$bookNoPrice  = $mkBook('[LOSTTEST] เล่มที่ไม่มีราคาปก', null, 2);
$bookLastCopy = $mkBook('[LOSTTEST] เล่มสุดท้ายเล่มเดียว', 120.00, 1);
$bookDamaged  = $mkBook('[LOSTTEST] เล่มสำหรับทดสอบชำรุด', 80.00, 2);
$bookUndo     = $mkBook('[LOSTTEST] เล่มสำหรับทดสอบย้อน', 300.00, 2);
$bookOverdue  = $mkBook('[LOSTTEST] เล่มที่เลยกำหนดมานาน', 150.00, 2);

// 👤 สมาชิกทดสอบ
$mkUser = function (string $suffix) use ($pdo, &$created): int {
    $email = "losttest_{$suffix}_" . time() . rand(100, 999) . "@test.com";
    $st = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'member')");
    $st->execute(["ผู้ยืมทดสอบ {$suffix}", $email, password_hash('x', PASSWORD_DEFAULT)]);
    $id = (int) $pdo->lastInsertId();
    $created['users'][] = $id;
    return $id;
};
$userA = $mkUser('a');
$userB = $mkUser('b');

/** สร้างรายการยืม + หัก available ให้ตรง invariant */
$mkBorrow = function (int $userId, int $bookId, string $due) use ($pdo, &$created): int {
    $st = $pdo->prepare("
        INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status)
        VALUES (?, ?, CURDATE(), ?, 'borrowing')
    ");
    $st->execute([$userId, $bookId, $due]);
    // 🔴 อ่าน lastInsertId ทันทีหลัง INSERT — ถ้าไปทำ query อื่นก่อนจะได้ 0
    $id = (int) $pdo->lastInsertId();
    $created['borrows'][] = $id;
    $pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ?")->execute([$bookId]);
    return $id;
};

$today   = date('Y-m-d');
$future  = date('Y-m-d', strtotime('+5 days'));
$longAgo = date('Y-m-d', strtotime('-40 days'));

$b1 = $mkBorrow($userA, $bookPriced,   $future);
$b2 = $mkBorrow($userA, $bookNoPrice,  $future);
$b3 = $mkBorrow($userB, $bookLastCopy, $future);
$b4 = $mkBorrow($userB, $bookDamaged,  $future);
$b5 = $mkBorrow($userA, $bookUndo,     $future);
$b6 = $mkBorrow($userB, $bookOverdue,  $longAgo);

echo "  📦 fixture: หนังสือ 6 เล่ม · สมาชิก 2 คน · รายการยืม 6 รายการ\n\n";

/** invariant ของทั้งระบบ — คืนจำนวนเล่มที่เพี้ยน */
$brokenBooks = function () use ($pdo): int {
    return (int) $pdo->query("
        SELECT COUNT(*) FROM books b
        WHERE b.available <> b.quantity
            - (SELECT COUNT(*) FROM borrows x WHERE x.book_id = b.id AND x.status = 'borrowing')
            - (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.id AND r.status = 'pending')
    ")->fetchColumn();
};

// ============================================================
// A. กติกาการแจ้ง
// ============================================================
echo "── A. กติกาการแจ้ง ──\n";

// A1 — ประเภทต้องเป็น lost หรือ damaged เท่านั้น
try {
    $service->markAsLost($b1, 'stolen', 100, 'ทดสอบ', 1);
    fail('LOST-A1', 'ประเภทมั่ว ๆ ผ่านไปได้ ทั้งที่ไม่ควร');
} catch (Exception $e) {
    check('LOST-A1', str_contains($e->getMessage(), 'ประเภท'),
        'ประเภทที่ไม่รู้จักถูกปฏิเสธ: ' . $e->getMessage(),
        'ถูกปฏิเสธแต่ข้อความไม่ตรง: ' . $e->getMessage());
}

// A2 — ต้องกรอกเหตุผล
try {
    $service->markAsLost($b1, 'lost', 100, '   ', 1);
    fail('LOST-A2', 'แจ้งโดยไม่มีเหตุผลผ่านไปได้ — เป็นเรื่องเงิน ต้องมีร่องรอย');
} catch (Exception $e) {
    check('LOST-A2', str_contains($e->getMessage(), 'รายละเอียด'),
        'บังคับกรอกเหตุผลได้ผล: ' . $e->getMessage(),
        'ถูกปฏิเสธแต่ข้อความไม่ตรง: ' . $e->getMessage());
}

// A3 — 🔴 หนังสือไม่มีราคา + ไม่กรอกราคา = ต้องหยุด ห้ามคิด 0
$qtyBefore = (int) $pdo->query("SELECT quantity FROM books WHERE id = {$bookNoPrice}")->fetchColumn();
try {
    $service->markAsLost($b2, 'lost', null, 'ผู้ยืมแจ้งว่าหาย', 1);
    fail('LOST-A3', '🔴 แจ้งหายเล่มที่ไม่รู้ราคาผ่านไปได้ — กลายเป็นทำหายแล้วไม่ต้องจ่าย');
} catch (Exception $e) {
    check('LOST-A3', str_contains($e->getMessage(), 'ราคาปก'),
        'เล่มที่ไม่รู้ราคา ถูกบังคับให้กรอกราคา: ' . mb_substr($e->getMessage(), 0, 60) . '…',
        'ถูกปฏิเสธแต่ข้อความไม่ตรง: ' . $e->getMessage());
}
$qtyAfter = (int) $pdo->query("SELECT quantity FROM books WHERE id = {$bookNoPrice}")->fetchColumn();
check('LOST-A4', $qtyBefore === $qtyAfter,
    "แจ้งไม่สำเร็จแล้วสต็อกไม่ถูกแตะ (quantity = {$qtyAfter} เท่าเดิม)",
    "🔴 แจ้งไม่สำเร็จแต่ quantity เปลี่ยน {$qtyBefore} → {$qtyAfter} — transaction ไม่ rollback");

// A5 — กรอกราคาเองได้ ถึงหนังสือจะไม่มีราคาปก
$res = $service->markAsLost($b2, 'lost', 175.50, 'ผู้ยืมแจ้งว่าหาย ตกลงราคาที่ 175.50', 1);
check('LOST-A5', abs($res['charge'] - 175.50) < 0.01,
    'กรอกราคาเองแทนราคาปกที่ไม่มีได้ — ค่าชดใช้ ' . number_format($res['charge'], 2) . ' บาท',
    'ค่าชดใช้ผิด: ' . $res['charge']);

// A6 — ราคาติดลบไม่ได้
try {
    $service->markAsLost($b3, 'lost', -50, 'ทดสอบ', 1);
    fail('LOST-A6', 'ราคาติดลบผ่านไปได้');
} catch (Exception $e) {
    pass('LOST-A6', 'ราคาติดลบถูกปฏิเสธ: ' . $e->getMessage());
}

// ============================================================
// B. สต็อก
// ============================================================
echo "\n── B. สต็อก ──\n";

$before = $pdo->query("SELECT quantity, available FROM books WHERE id = {$bookPriced}")->fetch();
$service->markAsLost($b1, 'lost', null, 'ผู้ยืมแจ้งว่าทำหายระหว่างเดินทาง', 1);
$after = $pdo->query("SELECT quantity, available FROM books WHERE id = {$bookPriced}")->fetch();

check('LOST-B1',
    (int) $after['quantity'] === (int) $before['quantity'] - 1,
    "quantity ลดลง 1 ({$before['quantity']} → {$after['quantity']})",
    "quantity ผิด: {$before['quantity']} → {$after['quantity']} (ควรลด 1)");

check('LOST-B2',
    (int) $after['available'] === (int) $before['available'],
    "available คงเดิมที่ {$after['available']} — ถูกต้อง เพราะหนังสือไม่ได้กลับเข้าชั้น",
    "🔴 available เปลี่ยน {$before['available']} → {$after['available']} — เท่ากับได้หนังสือคืนมาฟรี ๆ");

check('LOST-B3', $brokenBooks() === 0,
    'invariant สต็อกถูกต้องทุกเล่มในระบบ',
    '🔴 invariant พัง ' . $brokenBooks() . ' เล่ม');

// B4 — กดซ้ำต้องไม่ลดซ้ำ
$qtyBeforeDup = (int) $pdo->query("SELECT quantity FROM books WHERE id = {$bookPriced}")->fetchColumn();
$dupBlocked = 0;
for ($i = 0; $i < 3; $i++) {
    try { $service->markAsLost($b1, 'lost', 250, 'ยิงซ้ำ', 1); } catch (Exception $e) { $dupBlocked++; }
}
$qtyAfterDup = (int) $pdo->query("SELECT quantity FROM books WHERE id = {$bookPriced}")->fetchColumn();
check('LOST-B4', $dupBlocked === 3 && $qtyBeforeDup === $qtyAfterDup,
    "ยิงแจ้งซ้ำ 3 ครั้ง ถูกปฏิเสธทั้ง 3 · quantity ไม่ขยับ ({$qtyAfterDup})",
    "🔴 กดซ้ำแล้วลด quantity เพิ่ม: {$qtyBeforeDup} → {$qtyAfterDup} (บล็อกได้ {$dupBlocked}/3)");

// B5 — เล่มสุดท้าย quantity=1 → เหลือ 0 ไม่ติดลบ ไม่ชน CHECK
$service->markAsLost($b3, 'lost', null, 'เล่มสุดท้ายหาย', 1);
$lastCopy = $pdo->query("SELECT quantity, available FROM books WHERE id = {$bookLastCopy}")->fetch();
check('LOST-B5',
    (int) $lastCopy['quantity'] === 0 && (int) $lastCopy['available'] === 0,
    "เล่มสุดท้ายหาย → quantity 1→0, available 0 ไม่ติดลบ ไม่ชน CHECK constraint",
    "ผิด: quantity={$lastCopy['quantity']} available={$lastCopy['available']}");

// B6 — ชำรุดทำงานเหมือนกัน
$dmgBefore = (int) $pdo->query("SELECT quantity FROM books WHERE id = {$bookDamaged}")->fetchColumn();
$resD = $service->markAsLost($b4, 'damaged', null, 'ปกฉีก หน้าหลุด อ่านไม่ได้แล้ว', 1);
$dmgAfter = (int) $pdo->query("SELECT quantity FROM books WHERE id = {$bookDamaged}")->fetchColumn();
$dmgStatus = $pdo->query("SELECT status FROM borrows WHERE id = {$b4}")->fetchColumn();
check('LOST-B6',
    $dmgStatus === 'damaged' && $dmgAfter === $dmgBefore - 1,
    "แจ้งชำรุด → status = damaged · quantity {$dmgBefore} → {$dmgAfter}",
    "ผิด: status={$dmgStatus} quantity {$dmgBefore} → {$dmgAfter}");

check('LOST-B7', $brokenBooks() === 0,
    'invariant ยังถูกต้องหลังแจ้งไป 4 รายการ',
    '🔴 invariant พัง ' . $brokenBooks() . ' เล่ม');

// ============================================================
// C. เงิน
// ============================================================
echo "\n── C. เงิน ──\n";

// C1 — ค่าชดใช้ = ราคาปก (ค่าดำเนินการเป็น 0 โดยค่าเริ่มต้น)
$row1 = $pdo->query("SELECT fine_amount, status, return_date, lost_reported_at, lost_reported_by, lost_note FROM borrows WHERE id = {$b1}")->fetch();
check('LOST-C1',
    abs((float) $row1['fine_amount'] - (250.00 + (float) LOST_BOOK_FEE)) < 0.01,
    'ค่าชดใช้ = ราคาปก 250.00 + ค่าดำเนินการ ' . number_format((float) LOST_BOOK_FEE, 2) . ' = ' . number_format((float) $row1['fine_amount'], 2),
    'ค่าชดใช้ผิด: ' . $row1['fine_amount']);

// C2 — 🔴 ไม่คิดค่าปรับเกินกำหนดซ้ำ
//    b6 เลยกำหนดมา 40 วัน = ค่าปรับ 400 บาท ถ้าคิดซ้ำจะเป็น 550
$service->markAsLost($b6, 'lost', null, 'เลยกำหนดมานานแล้วผู้ยืมแจ้งว่าหาย', 1);
$row6 = $pdo->query("SELECT fine_amount FROM borrows WHERE id = {$b6}")->fetch();
$expected6 = 150.00 + (float) LOST_BOOK_FEE;
$overdueFine = 40 * FINE_PER_DAY;
check('LOST-C2',
    abs((float) $row6['fine_amount'] - $expected6) < 0.01,
    "เลยกำหนด 40 วัน แต่คิดแค่ค่าชดใช้ " . number_format((float) $row6['fine_amount'], 2)
        . " บาท ไม่บวกค่าปรับ {$overdueFine} บาทซ้ำ",
    "🔴 คิดค่าปรับเกินกำหนดซ้ำ — ได้ {$row6['fine_amount']} ควรเป็น {$expected6}");

// C3 — 🔴 ห้ามตั้ง return_date ให้เล่มที่หาย
check('LOST-C3',
    $row1['return_date'] === null && $row1['lost_reported_at'] !== null,
    'return_date เป็น NULL · lost_reported_at ถูกบันทึก — รายงาน "คืนแล้ว" จะไม่นับเล่มที่หาย',
    '🔴 return_date = ' . var_export($row1['return_date'], true)
        . ' — จะไปพองอยู่ในตัวเลข "คืนวันนี้/คืนเดือนนี้" ที่นับจาก return_date โดยไม่กรอง status');

// C4 — ร่องรอยครบ
check('LOST-C4',
    (int) $row1['lost_reported_by'] === 1 && str_contains((string) $row1['lost_note'], 'ทำหาย'),
    'บันทึกครบ: ใครแจ้ง (#' . $row1['lost_reported_by'] . ') · เหตุผล "' . mb_substr((string) $row1['lost_note'], 0, 30) . '…"',
    'ร่องรอยไม่ครบ: by=' . var_export($row1['lost_reported_by'], true) . ' note=' . var_export($row1['lost_note'], true));

// C5 — 🔴 snapshot ราคา: แก้ราคาหนังสือทีหลังแล้วหนี้ต้องไม่เปลี่ยน
$fineBeforeEdit = (float) $pdo->query("SELECT fine_amount FROM borrows WHERE id = {$b1}")->fetchColumn();
$pdo->prepare("UPDATE books SET price = 9999 WHERE id = ?")->execute([$bookPriced]);
$fineAfterEdit = (float) $pdo->query("SELECT fine_amount FROM borrows WHERE id = {$b1}")->fetchColumn();
check('LOST-C5',
    abs($fineBeforeEdit - $fineAfterEdit) < 0.01,
    'แก้ราคาหนังสือเป็น 9999 แล้ว หนี้ที่ค้างยังเป็น ' . number_format($fineAfterEdit, 2) . ' เท่าเดิม — snapshot ทำงาน',
    "🔴 แก้ราคาหนังสือแล้วหนี้เปลี่ยนตาม {$fineBeforeEdit} → {$fineAfterEdit} — อ่านราคาสดแทน snapshot");
$pdo->prepare("UPDATE books SET price = 250 WHERE id = ?")->execute([$bookPriced]);

// C6 — โควตาต้องคืนช่องให้สมาชิก
$activeA = $borrowRepo->countActiveBorrows($userA);
$stillBorrowing = (int) $pdo->query("SELECT COUNT(*) FROM borrows WHERE user_id = {$userA} AND status = 'borrowing'")->fetchColumn();
check('LOST-C6', $activeA === $stillBorrowing,
    "โควตาของผู้ยืมนับ {$activeA} รายการ — เล่มที่แจ้งหายไม่กินโควตาแล้ว",
    "โควตาไม่ตรง: countActiveBorrows={$activeA} แต่ในตารางมี {$stillBorrowing}");

// ============================================================
// D. ตัวเลขข้ามหน้า
// ============================================================
echo "\n── D. ตัวเลขต้องตรงกันทุกหน้า ──\n";

// D1 — 🔴 ยอดค้างชำระต้องตรงกันทั้ง 6 แหล่ง
// 🧠 เทียบ "ยอดเงินรวม" ไม่ใช่จำนวนแถว เพราะแต่ละแหล่งมี limit ต่างกัน
//    ทั้ง 4 แหล่งนี้คือที่มาของตัวเลขค้างชำระบน Dashboard / payments / reports / CSV
$sumLostTest = (float) $pdo->query("
    SELECT COALESCE(SUM(b.fine_amount), 0) FROM borrows b
    JOIN books bk ON bk.id = b.book_id
    LEFT JOIN payments p ON p.borrow_id = b.id
    WHERE bk.title LIKE '[LOSTTEST]%' AND b.fine_amount > 0
      AND p.id IS NULL AND b.fine_waived_at IS NULL
")->fetchColumn();

$sumFromReport = 0.0;
foreach ($reportRepo->getUnpaidFinesReport() as $r) {
    if (str_contains((string) $r['book_title'], '[LOSTTEST]')) $sumFromReport += (float) $r['fine_amount'];
}
$sumFromList = 0.0;
foreach ($borrowRepo->getUnpaidFinesList(500) as $r) {
    if (str_contains((string) ($r['book_title'] ?? ''), '[LOSTTEST]')) $sumFromList += (float) $r['fine_amount'];
}
$sumByUserA = 0.0;
foreach (array_merge($borrowRepo->getUnpaidFinesByUser($userA), $borrowRepo->getUnpaidFinesByUser($userB)) as $r) {
    if (str_contains((string) ($r['book_title'] ?? ''), '[LOSTTEST]')) $sumByUserA += (float) $r['fine_amount'];
}

$sources = [
    'SQL ตรง (นิยามกลาง)'                  => $sumLostTest,
    'ReportRepository::getUnpaidFinesReport' => $sumFromReport,
    'BorrowRepository::getUnpaidFinesList'   => $sumFromList,
    'BorrowRepository::getUnpaidFinesByUser' => $sumByUserA,
];
$vals = array_values($sources);
$allSame = true;
foreach ($vals as $v) if (abs($v - $vals[0]) > 0.01) $allSame = false;
if ($allSame) {
    pass('LOST-D1', 'ยอดค้างชำระตรงกันทุกแหล่ง = ' . number_format($vals[0], 2) . ' บาท (4 แหล่ง)');
} else {
    $detail = [];
    foreach ($sources as $k => $v) $detail[] = "{$k}=" . number_format($v, 2);
    fail('LOST-D1', "🔴 ยอดค้างชำระไม่ตรงกัน — " . implode(' · ', $detail)
        . "\n       แหล่งที่ต่ำกว่าเพื่อนคือแหล่งที่ลืมนับ status lost/damaged");
}

// D2 — ค่าชดใช้ต้องโผล่ในรายงานค้างชำระ
$unpaidRows = $reportRepo->getUnpaidFinesReport();
$foundLost = false;
foreach ($unpaidRows as $r) {
    if (str_contains((string) $r['book_title'], '[LOSTTEST]')) { $foundLost = true; break; }
}
check('LOST-D2', $foundLost,
    'ค่าชดใช้หนังสือหายปรากฏในรายงาน "ค้างชำระ" แล้ว',
    '🔴 ค่าชดใช้ไม่โผล่ในรายงานค้างชำระ — query นี้เป็นตัวเดียวใน 6 ตัวที่กรอง status');

// D3 — 🔴 ตัวเลข "คืนวันนี้" ต้องไม่ขยับเพราะการแจ้งหาย
$returnedToday = (int) $pdo->query("SELECT COUNT(*) FROM borrows WHERE DATE(return_date) = CURDATE()")->fetchColumn();
$lostToday     = (int) $pdo->query("SELECT COUNT(*) FROM borrows WHERE DATE(lost_reported_at) = CURDATE() AND status IN ('lost','damaged')")->fetchColumn();
$overlap = (int) $pdo->query("
    SELECT COUNT(*) FROM borrows
    WHERE DATE(return_date) = CURDATE() AND status IN ('lost','damaged')
")->fetchColumn();
check('LOST-D3', $overlap === 0,
    "แจ้งหายวันนี้ {$lostToday} รายการ แต่ไม่มีรายการไหนไปโผล่ในยอด \"คืนวันนี้\" ({$returnedToday})",
    "🔴 มี {$overlap} รายการที่แจ้งหายแต่มี return_date — ยอด \"คืนวันนี้\" พองเกินจริง");

// D4 — รายงานค้างชำระต้องแสดงวันที่ ไม่ใช่ช่องว่าง
$blankDate = 0;
foreach ($unpaidRows as $r) {
    if (str_contains((string) $r['book_title'], '[LOSTTEST]') && empty($r['return_date'])) $blankDate++;
}
check('LOST-D4', $blankDate === 0,
    'รายการหนังสือหายในรายงานแสดงวันที่แจ้งแทนวันคืน ไม่มีช่องว่าง',
    "มี {$blankDate} รายการที่ช่องวันที่ว่าง — ต้อง COALESCE(return_date, lost_reported_at)");

// D5 — 🔴 รายงานต้องอ่านยอดจาก snapshot ไม่ใช่ JOIN ไปหยิบ books.price สด
//    C5 ตรวจที่คอลัมน์ใน DB แล้ว แต่ถ้ารายงานเขียน JOIN ไปอ่าน bk.price
//    ตัวเลขบนหน้าจอจะเปลี่ยนตามราคาที่แก้ ทั้งที่ข้อมูลใน DB ถูก
$beforeEdit = [];
foreach ($reportRepo->getUnpaidFinesReport() as $r) {
    if (str_contains((string) $r['book_title'], '[LOSTTEST]')) {
        $beforeEdit[$r['book_title']] = (float) $r['fine_amount'];
    }
}
// 💥 แก้ราคาหนังสือทุกเล่มในชุดทดสอบให้ต่างจากยอดที่ snapshot ไว้มาก ๆ
$inBooks = implode(',', array_map('intval', $created['books']));
$pdo->exec("UPDATE books SET price = 8888 WHERE id IN ($inBooks)");

$afterEdit = [];
foreach ($reportRepo->getUnpaidFinesReport() as $r) {
    if (str_contains((string) $r['book_title'], '[LOSTTEST]')) {
        $afterEdit[$r['book_title']] = (float) $r['fine_amount'];
    }
}
$drifted = [];
foreach ($beforeEdit as $title => $amt) {
    if (!isset($afterEdit[$title]) || abs($afterEdit[$title] - $amt) > 0.01) {
        $drifted[] = mb_substr($title, 0, 30) . ': ' . number_format($amt, 2)
            . ' → ' . number_format($afterEdit[$title] ?? 0, 2);
    }
}
check('LOST-D5', $beforeEdit !== [] && $drifted === [],
    'แก้ราคาหนังสือเป็น 8888 แล้ว ยอดในรายงานค้างชำระ ' . count($beforeEdit)
        . ' รายการยังเท่าเดิม — รายงานอ่านจาก snapshot',
    '🔴 รายงานอ่านราคาสดแทน snapshot: ' . implode(' · ', $drifted));

// 🧹 คืนราคาเดิมให้เล่มที่ยังต้องใช้ต่อ
$pdo->prepare("UPDATE books SET price = 300 WHERE id = ?")->execute([$bookUndo]);
$pdo->prepare("UPDATE books SET price = 80 WHERE id = ?")->execute([$bookDamaged]);

// ============================================================
// E. ย้อนการแจ้ง
// ============================================================
echo "\n── E. ย้อนการแจ้ง (หาหนังสือเจอทีหลัง) ──\n";

$service->markAsLost($b5, 'lost', null, 'ผู้ยืมแจ้งว่าหาย', 1);
$undoBefore = $pdo->query("SELECT quantity, available FROM books WHERE id = {$bookUndo}")->fetch();

// E1 — ต้องกรอกเหตุผล
try {
    $service->undoLost($b5, '', 1);
    fail('LOST-E1', 'ย้อนโดยไม่มีเหตุผลผ่านไปได้');
} catch (Exception $e) {
    pass('LOST-E1', 'บังคับกรอกเหตุผลตอนย้อน: ' . $e->getMessage());
}

// E2 — ย้อนสำเร็จ สต็อกกลับมาครบ
$resU = $service->undoLost($b5, 'ผู้ยืมเจอหนังสือแล้วนำมาคืน', 1);
$undoAfter = $pdo->query("SELECT quantity, available FROM books WHERE id = {$bookUndo}")->fetch();
check('LOST-E2',
    (int) $undoAfter['quantity'] === (int) $undoBefore['quantity'] + 1
        && (int) $undoAfter['available'] === (int) $undoBefore['available'] + 1,
    "ย้อนแล้ว quantity {$undoBefore['quantity']} → {$undoAfter['quantity']} · available {$undoBefore['available']} → {$undoAfter['available']}",
    "สต็อกไม่กลับมาครบ: quantity {$undoBefore['quantity']} → {$undoAfter['quantity']}, available {$undoBefore['available']} → {$undoAfter['available']}");

check('LOST-E3', $brokenBooks() === 0,
    'invariant ยังถูกต้องหลังย้อน',
    '🔴 invariant พังหลังย้อน ' . $brokenBooks() . ' เล่ม');

// E4 — หนี้ที่ยังไม่จ่ายถูกยกเลิก + เหลือร่องรอย
$rowU = $pdo->query("SELECT status, fine_amount, return_date, lost_note FROM borrows WHERE id = {$b5}")->fetch();
check('LOST-E4',
    $rowU['status'] === 'returned' && (float) $rowU['fine_amount'] == 0.0
        && str_contains((string) $rowU['lost_note'], 'ย้อนการแจ้ง')
        && str_contains((string) $rowU['lost_note'], 'เจอหนังสือ'),
    'status → returned · ค่าชดใช้ถูกยกเลิก · บันทึกเดิมยังอยู่ + ต่อท้ายว่าใครย้อนเมื่อไหร่',
    'ผิด: status=' . $rowU['status'] . ' fine=' . $rowU['fine_amount'] . ' note=' . var_export($rowU['lost_note'], true));

// E5 — ย้อนซ้ำไม่ได้ (ไม่งั้น quantity จะเพิ่มเกิน)
$qtyB = (int) $pdo->query("SELECT quantity FROM books WHERE id = {$bookUndo}")->fetchColumn();
$undoBlocked = 0;
for ($i = 0; $i < 3; $i++) {
    try { $service->undoLost($b5, 'ย้อนซ้ำ', 1); } catch (Exception $e) { $undoBlocked++; }
}
$qtyA2 = (int) $pdo->query("SELECT quantity FROM books WHERE id = {$bookUndo}")->fetchColumn();
check('LOST-E5', $undoBlocked === 3 && $qtyB === $qtyA2,
    "ย้อนซ้ำ 3 ครั้งถูกปฏิเสธทั้งหมด · quantity ไม่เพิ่มเกิน ({$qtyA2})",
    "🔴 ย้อนซ้ำได้ quantity {$qtyB} → {$qtyA2} (บล็อกได้ {$undoBlocked}/3)");

// E6 — แจ้งหายรายการที่ปิดไปแล้วไม่ได้
$e6Blocked = false;
try {
    $service->markAsLost($b5, 'lost', 300, 'หายอีกรอบ', 1);
} catch (Exception $e) {
    $e6Blocked = true;
}
$b5Status = $pdo->query("SELECT status FROM borrows WHERE id = {$b5}")->fetchColumn();
check('LOST-E6', $e6Blocked && $b5Status === 'returned',
    'แจ้งหายรายการที่ปิดไปแล้วถูกปฏิเสธ — แจ้งได้เฉพาะที่ยังยืมอยู่',
    'รายการที่ปิดแล้วถูกแจ้งหายได้ status=' . $b5Status);

// E7 — 🔴 จ่ายค่าชดใช้ไปแล้ว ย้อนต้องไม่ลบ payment ทิ้ง (รายงานรายได้จะเพี้ยน)
$b8 = $mkBorrow($userA, $bookDamaged, $future);
$service->markAsLost($b8, 'lost', 200, 'หายแล้วจ่ายค่าชดใช้ทันที', 1);
$paymentRepo->create($b8, 200.00, 1);
$payCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE borrow_id = {$b8}")->fetchColumn();

$resPaid = $service->undoLost($b8, 'เจอหนังสือหลังจากจ่ายไปแล้ว', 1);
$payCountAfter = (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE borrow_id = {$b8}")->fetchColumn();
$fineAfterUndo = (float) $pdo->query("SELECT fine_amount FROM borrows WHERE id = {$b8}")->fetchColumn();

check('LOST-E7',
    $resPaid['refundNeeded'] === true
        && $payCountBefore === 1 && $payCountAfter === 1
        && abs($fineAfterUndo - 200.00) < 0.01
        && str_contains($resPaid['message'], 'ไม่คืนเงินให้อัตโนมัติ'),
    'จ่ายไปแล้วแล้วย้อน — payment ไม่ถูกลบ · ยอดเดิมคงไว้ · เตือนให้คืนเงินเอง',
    '🔴 ผิด: refundNeeded=' . var_export($resPaid['refundNeeded'], true)
        . " payments {$payCountBefore}→{$payCountAfter} fine={$fineAfterUndo}");

check('LOST-E8', $brokenBooks() === 0,
    'invariant ยังถูกต้องหลังย้อนรายการที่จ่ายเงินแล้ว',
    '🔴 invariant พัง ' . $brokenBooks() . ' เล่ม');

// ============================================================
// G. Race — แจ้งหายพร้อมกัน 2 โปรเซส
// ============================================================
echo "\n── G. แจ้งหายพร้อมกัน 2 โปรเซส ──\n";

// 🧠 ด่าน WHERE status='borrowing' ใน BorrowRepository::markAsLost() มีไว้กันเคสนี้
//    เทสต์แบบเรียกทีละครั้งเข้าไม่ถึง เพราะ Service กรองด้วย findByIdForUpdate() ไปก่อนแล้ว
//    ต้องยิงจริง 2 โปรเซสพร้อมกันถึงจะพิสูจน์ได้ว่า quantity ไม่ถูกลด 2 ครั้ง
$bookRace = $mkBook('[LOSTTEST] เล่มสำหรับทดสอบ race', 500.00, 4);
$bRace    = $mkBorrow($userA, $bookRace, $future);
$qtyRaceBefore = (int) $pdo->query("SELECT quantity FROM books WHERE id = {$bookRace}")->fetchColumn();

// 🛡️ [SECURITY] เขียนไฟล์ probe ลง temp dir ของระบบ ห้ามลงในโฟลเดอร์โปรเจกต์
//    โฟลเดอร์โปรเจกต์คือ document root — ไฟล์ .php ที่วางไว้เปิดผ่านเว็บได้ทันที
$rootDir = str_replace('\\', '/', dirname(__DIR__));
$probe = <<<SUB
<?php
\$_SERVER["REQUEST_METHOD"]="GET"; \$_SERVER["PHP_SELF"]="sub.php"; \$_SERVER["REMOTE_ADDR"]="127.0.0.1";
define('PROBE_ROOT', '{$rootDir}');
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
// ⏱️ รอให้ถึงเวลานัดพร้อมกัน — ทั้ง 2 โปรเซสจะยิงในวินาทีเดียวกัน
\$startAt = (float) \$argv[2];
while (microtime(true) < \$startAt) usleep(1000);
try { \$r = \$svc->markAsLost((int) \$argv[1], 'lost', 500.0, 'race test', 1); echo "OK"; }
catch (Exception \$e) { echo "BLOCKED"; }
SUB;
$probeFile = tempnam(sys_get_temp_dir(), 'bbrace') . '.php';
file_put_contents($probeFile, $probe);

$startAt = microtime(true) + 1.5;   // 🕐 นัดเวลาให้ทั้งคู่ยิงพร้อมกัน
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeFile) . ' ' . (int) $bRace . ' ' . $startAt;
$procs = [];
for ($i = 0; $i < 2; $i++) {
    $procs[$i] = popen($cmd . ' 2>&1', 'r');
}
$outs = [];
foreach ($procs as $i => $h) { $outs[$i] = trim((string) stream_get_contents($h)); pclose($h); }
@unlink($probeFile);
@unlink(substr($probeFile, 0, -4));   // tempnam สร้างไฟล์ไม่มีนามสกุลไว้ด้วย

$okCount      = count(array_filter($outs, fn($o) => str_contains($o, 'OK')));
$blockedCount = count(array_filter($outs, fn($o) => str_contains($o, 'BLOCKED')));
$qtyRaceAfter = (int) $pdo->query("SELECT quantity FROM books WHERE id = {$bookRace}")->fetchColumn();

check('LOST-G1',
    $okCount === 1 && $blockedCount === 1,
    "ยิงพร้อมกัน 2 โปรเซส → สำเร็จ 1 ถูกปฏิเสธ 1 (ตามที่ควร)",
    "🔴 ผลผิด: สำเร็จ {$okCount} ถูกปฏิเสธ {$blockedCount} — [" . implode(' | ', $outs) . ']');

check('LOST-G2',
    $qtyRaceAfter === $qtyRaceBefore - 1,
    "quantity ลดลงครั้งเดียว ({$qtyRaceBefore} → {$qtyRaceAfter}) แม้ยิงพร้อมกัน",
    "🔴 quantity ลดซ้ำ: {$qtyRaceBefore} → {$qtyRaceAfter} (ควรลด 1)");

check('LOST-G3', $brokenBooks() === 0,
    'invariant ยังถูกต้องหลังยิงพร้อมกัน',
    '🔴 invariant พังหลัง race ' . $brokenBooks() . ' เล่ม');

// ============================================================
// F. หน้าเว็บจริง
// ============================================================
echo "\n── F. หน้าเว็บจริง (HTTP) ──\n";

function http(string $method, string $url, array $fields = []): array
{
    global $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $COOKIE,
        CURLOPT_COOKIEFILE     => $COOKIE,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'code' => $code];
}

function csrfFrom(string $html): string
{
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

$login = http('GET', "$BASE_URL/login.php");
$r = http('POST', "$BASE_URL/login.php", [
    'csrf_token' => csrfFrom($login['body']),
    'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD,
]);

if (!str_contains($r['body'], 'ออกจากระบบ') && !str_contains($r['body'], 'logout')) {
    fail('LOST-F1', 'ล็อกอินไม่สำเร็จ — ข้ามการทดสอบผ่านหน้าเว็บ (ส่งรหัสผ่าน admin เป็น argument)');
} else {
    $page = http('GET', "$BASE_URL/admin/borrows.php?search=LOSTTEST");

    check('LOST-F1',
        str_contains($page['body'], 'หาย/ชำรุด'),
        'หน้ายืม-คืนมีปุ่ม "หาย/ชำรุด"',
        'ไม่พบปุ่มแจ้งหายในหน้ายืม-คืน');

    check('LOST-F2',
        str_contains($page['body'], 'ย้อนการแจ้ง'),
        'รายการที่แจ้งหายแล้วมีปุ่ม "ย้อนการแจ้ง"',
        'ไม่พบปุ่มย้อนการแจ้ง');

    // F3 — แจ้งผ่านหน้าเว็บจริง
    $qtyWebBefore = (int) $pdo->query("SELECT quantity FROM books WHERE id = {$bookNoPrice}")->fetchColumn();
    $b7 = $mkBorrow($userB, $bookNoPrice, $future);
    $form = http('GET', "$BASE_URL/admin/borrows.php?search=LOSTTEST");
    $post = http('POST', "$BASE_URL/admin/borrows.php", [
        'csrf_token' => csrfFrom($form['body']),
        'action' => 'mark_lost', 'borrow_id' => $b7,
        'loss_type' => 'lost', 'loss_price' => '99.00',
        'loss_note' => 'แจ้งผ่านหน้าเว็บ',
    ]);
    $rowWeb = $pdo->query("SELECT status, fine_amount FROM borrows WHERE id = {$b7}")->fetch();
    $qtyWebAfter = (int) $pdo->query("SELECT quantity FROM books WHERE id = {$bookNoPrice}")->fetchColumn();
    check('LOST-F3',
        $rowWeb['status'] === 'lost' && abs((float) $rowWeb['fine_amount'] - (99.00 + (float) LOST_BOOK_FEE)) < 0.01
            && $qtyWebAfter === $qtyWebBefore - 1,
        "แจ้งหายผ่านหน้าเว็บได้ — ค่าชดใช้ " . number_format((float) $rowWeb['fine_amount'], 2)
            . " · quantity {$qtyWebBefore} → {$qtyWebAfter}",
        "ผ่านหน้าเว็บไม่สำเร็จ: status={$rowWeb['status']} fine={$rowWeb['fine_amount']} qty {$qtyWebBefore}→{$qtyWebAfter}");

    // F4 — ตัวกรองสถานะใหม่ต้องใช้ได้
    $filtered = http('GET', "$BASE_URL/admin/borrows.php?status=lost&search=LOSTTEST");
    check('LOST-F4',
        $filtered['code'] === 200 && str_contains($filtered['body'], 'แจ้งหาย'),
        'กรองดูเฉพาะรายการที่แจ้งหายได้จากหน้าเว็บ',
        'ตัวกรอง status=lost ใช้ไม่ได้ (HTTP ' . $filtered['code'] . ')');

    // F5 — ฟอร์มหนังสือมีช่องราคาปก
    $bookForm = http('GET', "$BASE_URL/admin/book_form.php?id={$bookPriced}");
    check('LOST-F5',
        str_contains($bookForm['body'], 'ราคาปก') && str_contains($bookForm['body'], 'name="price"'),
        'ฟอร์มแก้ไขหนังสือมีช่อง "ราคาปก" และเติมค่าเดิมมาให้',
        'ไม่พบช่องราคาปกในฟอร์มหนังสือ');

    // F6 — หน้าตั้งค่าระบบมีค่าดำเนินการ
    $settings = http('GET', "$BASE_URL/admin/settings.php");
    check('LOST-F6',
        str_contains($settings['body'], 'ค่าดำเนินการหนังสือหาย'),
        'หน้าตั้งค่าระบบมี "ค่าดำเนินการหนังสือหาย" ให้ลูกค้าแก้เองได้',
        'ไม่พบค่าดำเนินการในหน้าตั้งค่าระบบ');

    check('LOST-F7', $brokenBooks() === 0,
        'invariant ยังถูกต้องหลังทดสอบผ่านหน้าเว็บ',
        '🔴 invariant พัง ' . $brokenBooks() . ' เล่ม');
}

$cleanup();

// ============================================================
// SUMMARY
// ============================================================
$pct = $results['total'] > 0 ? round($results['passed'] / $results['total'] * 100, 1) : 0;
echo "\n══════════════════════════════════════\n";
echo " RESULTS: {$results['passed']}/{$results['total']} passed ($pct%)";
if ($results['failed'] > 0) echo " | {$results['failed']} FAILED";
echo "\n══════════════════════════════════════\n\n";

exit($results['failed'] > 0 ? 1 : 0);
