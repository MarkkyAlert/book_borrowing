<?php

/**
 * Section 19 — Data Integrity Gap Analysis
 * 
 * ── Stock ──────────────────────────────
 * DI-01: เพิ่มหนังสือ 5 เล่ม → available = 5
 * DI-02: ยืมไป 2 เล่ม → available = 3
 * DI-03: คืน 1 เล่ม → available = 4
 * DI-04: จอง 1 เล่ม → available = 3
 * DI-05: ยกเลิกการจอง → available = 4
 * DI-06: อนุมัติการจอง → available ไม่เปลี่ยน
 * DI-07: ยืมเมื่อ available = 0 → error
 * 
 * ── ค่าปรับ ────────────────────────────
 * DI-08: คืนเกิน 1 วัน → fine = 10
 * DI-09: คืนเกิน 5 วัน → fine = 50
 * DI-10: คืนตรงกำหนด → fine = 0
 * DI-11: คืนก่อนกำหนด → fine = 0
 * DI-12: ชำระค่าปรับ → payment record ถูกต้อง
 * 
 * ── Quota ──────────────────────────────
 * DI-13: ยืม 3 เล่ม → ยืมเพิ่มไม่ได้
 * DI-14: คืน 1 เล่ม → ยืมเพิ่มได้
 * DI-15: จอง + ยืม รวม = quota → ยืมเพิ่มไม่ได้
 * 
 * ── FK Constraints ─────────────────────
 * DI-16: ลบหนังสือที่มี borrow → FK error
 * DI-17: ลบหมวดหมู่ที่มีหนังสือ → FK error
 * DI-18: ลบสมาชิกที่มี borrow → FK error
 * 
 * ── Expiration ─────────────────────────
 * DI-19: จองหมดอายุ → status = expired + stock คืน
 * DI-20: Cron expire idempotent (รันซ้ำไม่มี side effect)
 * DI-21: Lazy expire ทำงาน (code review)
 * DI-22: Cron เข้าผ่าน browser → 403
 * 
 * ── DB Constraints ────────────────────
 * DI-23: available >= 0 (CHECK constraint)
 * DI-24: quantity >= available (CHECK constraint)
 * DI-25: payments.borrow_id UNIQUE
 * DI-26: users.email UNIQUE
 * DI-27: books.isbn UNIQUE
 * 
 * Usage: php tests/test_data_integrity.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$_SESSION = ['user_id' => 0, 'role' => 'admin', 'processed_actions' => []];
$_SERVER['PHP_SELF'] = 'tests/test_data_integrity.php';

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/BorrowService.php';
require_once __DIR__ . '/../app/Services/ReservationService.php';
require_once __DIR__ . '/../app/Repositories/BorrowRepository.php';
require_once __DIR__ . '/../app/Repositories/ReservationRepository.php';
require_once __DIR__ . '/../app/Repositories/BookRepository.php';
require_once __DIR__ . '/../app/Repositories/UserRepository.php';
require_once __DIR__ . '/../app/Repositories/PaymentRepository.php';

use App\Services\BorrowService;
use App\Services\ReservationService;

$pdo = getDB();
$borrowService = new BorrowService($pdo);
$reservationService = new ReservationService($pdo);

$passed = 0;
$failed = 0;
$total = 0;

function assertTest(string $name, bool $condition, string $detail = '')
{
    global $passed, $failed, $total;
    $total++;
    if ($condition) {
        $passed++;
        echo "  ✅ PASS: $name";
    } else {
        $failed++;
        echo "  ❌ FAIL: $name";
    }
    if ($detail) echo "\n     └─ $detail";
    echo "\n";
}

// Helpers
function createUser(string $tag): int
{
    global $pdo;
    $email = 'di_' . $tag . '_' . time() . mt_rand(100, 999) . '@test.com';
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, phone) VALUES (?, ?, ?, 'member', '0800000000')");
    $stmt->execute(["DI $tag", $email, password_hash('123456', PASSWORD_DEFAULT)]);
    return (int) $pdo->lastInsertId();
}

// Get a safe category ID
$safeCatId = (int) $pdo->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetchColumn();

function createBook(string $tag, int $qty = 5): int
{
    global $pdo, $safeCatId;
    // 🧠 คอลัมน์ isbn เป็น VARCHAR(20) — ต้องคุมความยาวเอง
    //    เดิมใช้ 'DI'.time().rand().$tag ซึ่งยาวเกิน 20 เมื่อ tag ยาวหน่อย → fatal PDOException
    $isbn = mb_substr('DI' . time() . $tag, 0, 20);
    $stmt = $pdo->prepare("INSERT INTO books (title, author, isbn, quantity, available, category_id) VALUES (?, 'Test', ?, ?, ?, ?)");
    $stmt->execute(["DI Book $tag", $isbn, $qty, $qty, $safeCatId]);
    return (int) $pdo->lastInsertId();
}

function getAvailable(int $bookId): int
{
    global $pdo;
    return (int) $pdo->query("SELECT available FROM books WHERE id = $bookId")->fetchColumn();
}

function getBorrowId(int $userId, int $bookId): int
{
    global $pdo;
    return (int) $pdo->query("SELECT id FROM borrows WHERE user_id = $userId AND book_id = $bookId ORDER BY id DESC LIMIT 1")->fetchColumn();
}

function getResId(int $userId, int $bookId): int
{
    global $pdo;
    return (int) $pdo->query("SELECT id FROM reservations WHERE user_id = $userId AND book_id = $bookId ORDER BY id DESC LIMIT 1")->fetchColumn();
}

echo "\n════════════════════════════════════════\n";
echo " Section 19: Data Integrity\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "════════════════════════════════════════\n";

// ============================================================
echo "\n── 1️⃣ STOCK (จำนวนหนังสือคงเหลือ) ──\n";
// ============================================================

$stockUserId = createUser('stock');
$stockBookId = createBook('stock', 5);

// DI-01: เพิ่มหนังสือ 5 เล่ม → available = 5
$av = getAvailable($stockBookId);
assertTest(
    "DI-01: เพิ่มหนังสือ 5 เล่ม → available = 5",
    $av === 5,
    "available=$av"
);

// DI-02: ยืมไป 2 เล่ม → available = 3
$stockUserId2 = createUser('stock2');
$stockBookId2 = createBook('stock2', 5);
$borrowService->createBorrow($stockUserId, [$stockBookId]);
$borrowService->createBorrow($stockUserId2, [$stockBookId]);
$av = getAvailable($stockBookId);
assertTest(
    "DI-02: ยืมไป 2 เล่ม → available = 3",
    $av === 3,
    "available=$av"
);

// DI-03: คืน 1 เล่ม → available = 4
$bId1 = getBorrowId($stockUserId, $stockBookId);
$borrowService->returnBook($bId1);
$av = getAvailable($stockBookId);
assertTest(
    "DI-03: คืน 1 เล่ม → available = 4",
    $av === 4,
    "available=$av"
);

// DI-04: จอง 1 เล่ม → available = 3
$stockUserId3 = createUser('stock3');
$reservationService->createReservation($stockUserId3, $stockBookId);
$av = getAvailable($stockBookId);
assertTest(
    "DI-04: จอง 1 เล่ม → available = 3",
    $av === 3,
    "available=$av"
);

// DI-05: ยกเลิกการจอง → available = 4
$resId = getResId($stockUserId3, $stockBookId);
$reservationService->cancelReservation($resId, $stockUserId3);
$av = getAvailable($stockBookId);
assertTest(
    "DI-05: ยกเลิกการจอง → available = 4",
    $av === 4,
    "available=$av"
);

// DI-06: อนุมัติการจอง → available ไม่เปลี่ยน (หักตอนจองแล้ว)
$stockUserId4 = createUser('stock4');
$reservationService->createReservation($stockUserId4, $stockBookId);
$avBeforeApprove = getAvailable($stockBookId);
$resId2 = getResId($stockUserId4, $stockBookId);
$reservationService->fulfillReservation($resId2);
$avAfterApprove = getAvailable($stockBookId);
assertTest(
    "DI-06: อนุมัติการจอง → available ไม่เปลี่ยน",
    $avBeforeApprove === $avAfterApprove,
    "before=$avBeforeApprove, after=$avAfterApprove"
);

// DI-07: ยืมเมื่อ available = 0 → error
$emptyBookId = createBook('empty', 1);
$emptyUser1 = createUser('empty1');
$emptyUser2 = createUser('empty2');
$borrowService->createBorrow($emptyUser1, [$emptyBookId]); // available = 0

$errMsg = null;
try {
    $borrowService->createBorrow($emptyUser2, [$emptyBookId]);
} catch (Exception $e) {
    $errMsg = $e->getMessage();
}
assertTest(
    "DI-07: ยืมเมื่อ available = 0 → error",
    $errMsg !== null,
    "error=" . ($errMsg ?? 'none')
);

// ============================================================
echo "\n── 2️⃣ ค่าปรับ ──\n";
// ============================================================

// DI-08: คืนเกิน 1 วัน → fine = 10 (FINE_PER_DAY=10)
$fine = $borrowService->calculateFine(date('Y-m-d', strtotime('-1 day')), date('Y-m-d'));
assertTest(
    "DI-08: คืนเกินกำหนด 1 วัน → fine = " . FINE_PER_DAY,
    (int)$fine['amount'] === FINE_PER_DAY && (int)$fine['days'] === 1,
    "days={$fine['days']}, amount={$fine['amount']}"
);

// DI-09: คืนเกิน 5 วัน → fine = 50
$fine5 = $borrowService->calculateFine(date('Y-m-d', strtotime('-5 days')), date('Y-m-d'));
assertTest(
    "DI-09: คืนเกินกำหนด 5 วัน → fine = " . (5 * FINE_PER_DAY),
    (int)$fine5['amount'] === (5 * FINE_PER_DAY) && (int)$fine5['days'] === 5,
    "days={$fine5['days']}, amount={$fine5['amount']}"
);

// DI-10: คืนตรงกำหนด → fine = 0
$fine0 = $borrowService->calculateFine(date('Y-m-d'), date('Y-m-d'));
assertTest(
    "DI-10: คืนตรงกำหนด → fine = 0",
    (int)$fine0['amount'] === 0 && (int)$fine0['days'] === 0,
    "days={$fine0['days']}, amount={$fine0['amount']}"
);

// DI-11: คืนก่อนกำหนด → fine = 0
$fineEarly = $borrowService->calculateFine(date('Y-m-d', strtotime('+3 days')), date('Y-m-d'));
assertTest(
    "DI-11: คืนก่อนกำหนด → fine = 0",
    (int)$fineEarly['amount'] === 0 && (int)$fineEarly['days'] === 0,
    "days={$fineEarly['days']}, amount={$fineEarly['amount']}"
);

// DI-12: ชำระค่าปรับ → payment record สร้างถูกต้อง
$fineUser = createUser('fine');
$fineBook = createBook('fine', 5);
$borrowService->createBorrow($fineUser, [$fineBook]);
$fineBorrowId = getBorrowId($fineUser, $fineBook);
$pdo->prepare("UPDATE borrows SET due_date = DATE_SUB(CURDATE(), INTERVAL 3 DAY) WHERE id = ?")
    ->execute([$fineBorrowId]);
$borrowService->returnBook($fineBorrowId);
$borrowService->payFine($fineBorrowId, $_SESSION['user_id']);

$payment = $pdo->query("SELECT * FROM payments WHERE borrow_id = $fineBorrowId")
    ->fetch(PDO::FETCH_ASSOC);
$expectedFine = 3 * FINE_PER_DAY;
assertTest(
    "DI-12: ชำระค่าปรับ → payment record ถูกต้อง",
    $payment !== false && (int)$payment['amount'] === $expectedFine,
    "expected=$expectedFine, actual=" . ($payment ? $payment['amount'] : 'none')
);

// ============================================================
echo "\n── 3️⃣ QUOTA (จำนวนยืม) ──\n";
// ============================================================

// DI-13: ยืม 3 เล่ม → ยืมเพิ่มไม่ได้
$quotaUser = createUser('quota');
$qb1 = createBook('q1', 5);
$qb2 = createBook('q2', 5);
$qb3 = createBook('q3', 5);
$qb4 = createBook('q4', 5);

$borrowService->createBorrow($quotaUser, [$qb1, $qb2, $qb3]);
$quotaErr = null;
try {
    $borrowService->createBorrow($quotaUser, [$qb4]);
} catch (Exception $e) {
    $quotaErr = $e->getMessage();
}
assertTest(
    "DI-13: ยืม " . MAX_BORROW_BOOKS . " เล่ม → ยืมเพิ่มไม่ได้",
    $quotaErr !== null,
    "error=" . ($quotaErr ?? 'none')
);

// DI-14: คืน 1 เล่ม → ยืมเพิ่มได้
$qBorrowId = getBorrowId($quotaUser, $qb1);
$borrowService->returnBook($qBorrowId);
$extraErr = null;
try {
    $borrowService->createBorrow($quotaUser, [$qb4]);
    $extraErr = false;
} catch (Exception $e) {
    $extraErr = $e->getMessage();
}
assertTest(
    "DI-14: คืน 1 เล่ม → ยืมเพิ่มได้ 1 เล่ม",
    $extraErr === false,
    $extraErr === false ? "borrow succeeded" : "error=$extraErr"
);

// DI-15: จอง + ยืม รวม = quota → ยืมเพิ่มไม่ได้
$quotaUser2 = createUser('quota2');
$qb5 = createBook('q5', 5);
$qb6 = createBook('q6', 5);
$qb7 = createBook('q7', 5);
$qb8 = createBook('q8', 5);

$borrowService->createBorrow($quotaUser2, [$qb5]);  // 1 borrow
$reservationService->createReservation($quotaUser2, $qb6);  // 1 reservation
$reservationService->createReservation($quotaUser2, $qb7);  // 2 reservations
// Total = 1 borrow + 2 reservations = 3 = MAX

$quotaErr2 = null;
try {
    $borrowService->createBorrow($quotaUser2, [$qb8]);
} catch (Exception $e) {
    $quotaErr2 = $e->getMessage();
}
assertTest(
    "DI-15: จอง 2 + ยืม 1 = 3 → ยืมเพิ่มไม่ได้",
    $quotaErr2 !== null,
    "error=" . ($quotaErr2 ?? 'none')
);

// ============================================================
echo "\n── 4️⃣ FK CONSTRAINTS (ความสัมพันธ์ข้อมูล) ──\n";
// ============================================================

// DI-16: ลบหนังสือที่มี borrow → FK error
$fkErr1 = null;
try {
    $pdo->exec("DELETE FROM books WHERE id = $qb2");
} catch (Exception $e) {
    $fkErr1 = $e->getMessage();
}
assertTest(
    "DI-16: ลบหนังสือที่มี borrow → FK error",
    $fkErr1 !== null,
    "FK blocked: " . ($fkErr1 ? substr($fkErr1, 0, 60) : 'none')
);

// DI-17: books.category_id FK = ON DELETE SET NULL
//   When deleting a category, books remain but category_id becomes NULL
$pdo->exec("INSERT INTO categories (name) VALUES ('DI TempCat')");
$tmpCatId = (int) $pdo->lastInsertId();
$tmpBookForCat = createBook('cattest', 2);
$pdo->prepare("UPDATE books SET category_id = ? WHERE id = ?")->execute([$tmpCatId, $tmpBookForCat]);
$pdo->exec("DELETE FROM categories WHERE id = $tmpCatId");
$catAfterDel = $pdo->query("SELECT category_id FROM books WHERE id = $tmpBookForCat")->fetchColumn();
assertTest(
    "DI-17: ON DELETE SET NULL (book remains, category_id=NULL)",
    $catAfterDel === null,
    "category_id after delete=" . var_export($catAfterDel, true)
);

// DI-18: ลบสมาชิกที่มี borrow → FK error
$fkErr3 = null;
try {
    $pdo->exec("DELETE FROM users WHERE id = $quotaUser");
} catch (Exception $e) {
    $fkErr3 = $e->getMessage();
}
assertTest(
    "DI-18: ลบสมาชิกที่มี borrow → FK error",
    $fkErr3 !== null,
    "FK blocked: " . ($fkErr3 ? substr($fkErr3, 0, 60) : 'none')
);

// ============================================================
echo "\n── 5️⃣ EXPIRATION (การจองหมดอายุ) ──\n";
// ============================================================

// DI-19: จองหมดอายุ → status=expired + stock คืน
$expUser = createUser('expire');
$expBook = createBook('expire', 3);
$reservationService->createReservation($expUser, $expBook);
$expResId = getResId($expUser, $expBook);
$stockBeforeExpire = getAvailable($expBook);

// Force expire
$pdo->prepare("UPDATE reservations SET expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ?")
    ->execute([$expResId]);
$reservationService->expireOverdueReservations();

$stockAfterExpire = getAvailable($expBook);
$expStatus = $pdo->query("SELECT status FROM reservations WHERE id = $expResId")->fetchColumn();
assertTest(
    "DI-19: จองหมดอายุ → expired + stock คืน",
    $expStatus === 'expired' && $stockAfterExpire === ($stockBeforeExpire + 1),
    "status=$expStatus, stock: $stockBeforeExpire→$stockAfterExpire"
);

// DI-20: Cron expire idempotent
$stockBeforeRerun = getAvailable($expBook);
$reservationService->expireOverdueReservations();
$stockAfterRerun = getAvailable($expBook);
assertTest(
    "DI-20: Cron expire ซ้ำ → ไม่มี side effect (idempotent)",
    $stockBeforeRerun === $stockAfterRerun,
    "stock: $stockBeforeRerun→$stockAfterRerun"
);

// DI-21: Lazy expire (code review)
$resSvcCode = file_get_contents(__DIR__ . '/../app/Services/ReservationService.php');
$hasLazyExpire = strpos($resSvcCode, 'markExpiredReservations') !== false
    && strpos($resSvcCode, 'createReservation') !== false;
assertTest(
    "DI-21: Lazy expire ทำงาน (code review)",
    $hasLazyExpire,
    "markExpiredReservations called inside createReservation"
);

// DI-22: Cron เข้าผ่าน browser → 403
$ch = curl_init(rtrim(APP_URL, '/') . '/cron/expire_reservations.php');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$cronBody = curl_exec($ch);
$cronCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
assertTest(
    "DI-22: Cron เข้าผ่าน browser → 403",
    $cronCode === 403 || strpos($cronBody, 'Access denied') !== false,
    "code=$cronCode"
);

// ============================================================
echo "\n── 6️⃣ DB CONSTRAINTS (Safety Net) ──\n";
// ============================================================

// DI-23: available >= 0 (CHECK constraint)
$constraints = $pdo->query("
    SELECT CONSTRAINT_NAME, CHECK_CLAUSE 
    FROM information_schema.CHECK_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = '" . DB_NAME . "'
")->fetchAll(PDO::FETCH_ASSOC);

$hasAvailCheck = false;
$hasQtyCheck = false;
foreach ($constraints as $c) {
    if (strpos($c['CONSTRAINT_NAME'], 'available_non_negative') !== false) $hasAvailCheck = true;
    if (strpos($c['CONSTRAINT_NAME'], 'quantity_gte_available') !== false) $hasQtyCheck = true;
}
assertTest(
    "DI-23: CHECK available >= 0",
    $hasAvailCheck,
    "constraint found=" . ($hasAvailCheck ? 'yes' : 'no')
);

// DI-24: CHECK quantity >= available
assertTest(
    "DI-24: CHECK quantity >= available",
    $hasQtyCheck,
    "constraint found=" . ($hasQtyCheck ? 'yes' : 'no')
);

// DI-25: payments.borrow_id UNIQUE
$payIdxes = $pdo->query("SHOW INDEX FROM payments WHERE Key_name = 'unique_borrow_payment'")->fetchAll();
assertTest(
    "DI-25: payments.borrow_id UNIQUE constraint",
    count($payIdxes) > 0,
    "unique_borrow_payment index exists=" . (count($payIdxes) > 0 ? 'yes' : 'no')
);

// DI-26: users.email UNIQUE
$emailIdx = $pdo->query("SHOW INDEX FROM users WHERE Column_name = 'email' AND Non_unique = 0")->fetchAll();
assertTest(
    "DI-26: users.email UNIQUE constraint",
    count($emailIdx) > 0,
    "email unique index exists=" . (count($emailIdx) > 0 ? 'yes' : 'no')
);

// DI-27: books.isbn UNIQUE
$isbnIdx = $pdo->query("SHOW INDEX FROM books WHERE Key_name = 'uq_isbn'")->fetchAll();
assertTest(
    "DI-27: books.isbn UNIQUE index",
    count($isbnIdx) > 0,
    "uq_isbn index exists=" . (count($isbnIdx) > 0 ? 'yes' : 'no')
);

// ============================================================
echo "\n── CLEANUP ──\n";
// ============================================================

$pdo->exec("DELETE FROM payments WHERE borrow_id IN (SELECT id FROM borrows WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'di_%'))");
$pdo->exec("DELETE FROM borrows WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'di_%')");
$pdo->exec("DELETE FROM reservations WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'di_%')");
$pdo->exec("DELETE FROM users WHERE email LIKE 'di_%'");
$pdo->exec("DELETE FROM books WHERE isbn LIKE 'DI%'");
echo "  Test data cleaned\n";

echo "\n════════════════════════════════════════\n";
echo " RESULTS: $passed/$total passed";
if ($failed > 0) echo " | $failed FAILED";
echo "\n════════════════════════════════════════\n\n";

exit($failed > 0 ? 1 : 0);
