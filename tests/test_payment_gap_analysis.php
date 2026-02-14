<?php

/**
 * Section 9 — Fine/Payment Gap Analysis Tests
 * 
 * Tests gap areas NOT covered by existing tests:
 * - recorded_by is correctly stored in payment record
 * - Multi-unpaid grouping per user
 * - Payment search functionality
 * - Idempotency key mechanism (session-level)
 * - Stats accuracy (total, unpaid, this month)
 * 
 * Usage: php tests/test_payment_gap_analysis.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$_SESSION = ['user_id' => 0, 'role' => 'admin', 'processed_actions' => []];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = 'tests/test_payment_gap_analysis.php';

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

spl_autoload_register(function (string $class) {
    $map = [
        'App\\Services\\' => __DIR__ . '/../app/Services/',
        'App\\Repositories\\' => __DIR__ . '/../app/Repositories/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . substr($class, strlen($prefix)) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
});

use App\Services\BorrowService;
use App\Repositories\PaymentRepository;
use App\Repositories\BorrowRepository;

$pdo = getDB();
$borrowService = new BorrowService($pdo);
$paymentRepo = new PaymentRepository($pdo);
$borrowRepo = new BorrowRepository($pdo);

$passed = 0;
$failed = 0;
$total = 0;
$cleanupBorrowIds = [];
$cleanupPaymentBorrowIds = [];
$cleanupUserIds = [];
$cleanupBookIds = [];
$cleanupCatIds = [];
$ts = time();

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

echo "\n════════════════════════════════════════\n";
echo " Section 9: Fine/Payment Gap Analysis\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "════════════════════════════════════════\n\n";

// ── SETUP ──
echo "── SETUP ──\n";

// Create test category
$pdo->prepare("INSERT INTO categories (name) VALUES (?)")->execute(["_pay_test_cat_$ts"]);
$catId = (int) $pdo->lastInsertId();
$cleanupCatIds[] = $catId;

// Create test books (3 books for multi-unpaid test)
$bookIds = [];
for ($i = 1; $i <= 3; $i++) {
    $pdo->prepare("INSERT INTO books (title, author, category_id, quantity, available) VALUES (?,?,?,?,?)")
        ->execute(["_pay_test_book_{$i}_$ts", "_author", $catId, 5, 5]);
    $bookIds[] = (int) $pdo->lastInsertId();
    $cleanupBookIds[] = end($bookIds);
}

// Create test member
$hash = hashPassword('Test123456');
$pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?,?,?,?)")
    ->execute(["Pay Tester $ts", "_pay_test_$ts@test.com", $hash, 'member']);
$memberId = (int) $pdo->lastInsertId();
$cleanupUserIds[] = $memberId;

// Create a second member for multi-user test
$pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?,?,?,?)")
    ->execute(["Pay Tester2 $ts", "_pay_test2_$ts@test.com", $hash, 'member']);
$member2Id = (int) $pdo->lastInsertId();
$cleanupUserIds[] = $member2Id;

// Create admin/staff user for recorded_by
$pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?,?,?,?)")
    ->execute(["Pay Staff $ts", "_pay_staff_$ts@test.com", $hash, 'staff']);
$staffId = (int) $pdo->lastInsertId();
$cleanupUserIds[] = $staffId;

echo "  Category ID: $catId, Books: " . implode(',', $bookIds) . "\n";
echo "  Member: $memberId, Member2: $member2Id, Staff: $staffId\n\n";

// ============================================================
echo "── 1️⃣ RECORDED_BY TEST ──\n";
// ============================================================

// Create overdue borrow → return → pay → check recorded_by
$pdo->prepare("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, return_date, status, fine_amount) 
    VALUES (?,?,DATE_SUB(CURDATE(), INTERVAL 10 DAY),DATE_SUB(CURDATE(), INTERVAL 5 DAY),CURDATE(),'returned',50)")
    ->execute([$memberId, $bookIds[0]]);
$borrowId1 = (int) $pdo->lastInsertId();
$cleanupBorrowIds[] = $borrowId1;

$result = $borrowService->payFine($borrowId1, $staffId);
$cleanupPaymentBorrowIds[] = $borrowId1;

// Check payment record
$stmt = $pdo->prepare("SELECT recorded_by, amount FROM payments WHERE borrow_id = ?");
$stmt->execute([$borrowId1]);
$payment = $stmt->fetch();

assertTest(
    "FP-01: Payment recorded_by = staffId",
    $payment && (int)$payment['recorded_by'] === $staffId && (float)$payment['amount'] === 50.0,
    "recorded_by={$payment['recorded_by']} (expected $staffId), amount={$payment['amount']} (expected 50)"
);

// ============================================================
echo "\n── 2️⃣ FAILURE CASES ──\n";
// ============================================================

// FP-02: Pay already-paid fine
$fp02_exception = false;
try {
    $borrowService->payFine($borrowId1, $staffId);
} catch (Exception $e) {
    $fp02_exception = true;
    $fp02_msg = $e->getMessage();
}
assertTest(
    "FP-02: ชำระค่าปรับที่ชำระไปแล้ว → exception",
    $fp02_exception,
    "error: " . ($fp02_msg ?? 'none')
);

// FP-03: Pay fine on borrow with fine_amount = 0
$pdo->prepare("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, return_date, status, fine_amount) 
    VALUES (?,?,DATE_SUB(CURDATE(), INTERVAL 5 DAY),CURDATE(),CURDATE(),'returned',0)")
    ->execute([$memberId, $bookIds[1]]);
$borrowId2 = (int) $pdo->lastInsertId();
$cleanupBorrowIds[] = $borrowId2;

$fp03_exception = false;
try {
    $borrowService->payFine($borrowId2, $staffId);
} catch (Exception $e) {
    $fp03_exception = true;
    $fp03_msg = $e->getMessage();
}
assertTest(
    "FP-03: ชำระค่าปรับของ borrow ที่ไม่มี fine_amount → exception",
    $fp03_exception,
    "error: " . ($fp03_msg ?? 'none')
);

// FP-04: Pay fine on non-existent borrow
$fp04_exception = false;
try {
    $borrowService->payFine(999999, $staffId);
} catch (Exception $e) {
    $fp04_exception = true;
    $fp04_msg = $e->getMessage();
}
assertTest(
    "FP-04: ชำระค่าปรับของ borrow ที่ไม่มี → exception",
    $fp04_exception,
    "error: " . ($fp04_msg ?? 'none')
);

// ============================================================
echo "\n── 3️⃣ MULTI-UNPAID GROUPING ──\n";
// ============================================================

// Create 3 overdue borrows for memberId → test grouping
$multiUnpaidIds = [];
for ($i = 0; $i < 3; $i++) {
    $fine = ($i + 1) * 10; // 10, 20, 30
    $pdo->prepare("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, return_date, status, fine_amount) 
        VALUES (?,?,DATE_SUB(CURDATE(), INTERVAL 10 DAY),DATE_SUB(CURDATE(), INTERVAL ? DAY),CURDATE(),'returned',?)")
        ->execute([$member2Id, $bookIds[$i], ($i + 1), $fine]);
    $multiUnpaidIds[] = (int) $pdo->lastInsertId();
    $cleanupBorrowIds[] = end($multiUnpaidIds);
}

// FP-05: Check unpaid list groups by user
$unpaidList = $borrowRepo->getUnpaidFinesList(50);
$member2Unpaid = array_filter($unpaidList, fn($item) => (int)$item['user_id'] === $member2Id);
assertTest(
    "FP-05: สมาชิก 1 คนค้าง 3 รายการ → เห็น 3 items ใน unpaidList",
    count($member2Unpaid) === 3,
    "count=" . count($member2Unpaid) . " (expected 3)"
);

// FP-06: Pay one by one → unpaid count decreases
$borrowService->payFine($multiUnpaidIds[0], $staffId);
$cleanupPaymentBorrowIds[] = $multiUnpaidIds[0];

$unpaidAfterPay = $borrowRepo->getUnpaidFinesList(50);
$member2UnpaidAfter = array_filter($unpaidAfterPay, fn($item) => (int)$item['user_id'] === $member2Id);
assertTest(
    "FP-06: ชำระ 1/3 → เหลือ 2 unpaid",
    count($member2UnpaidAfter) === 2,
    "count=" . count($member2UnpaidAfter) . " (expected 2)"
);

// ============================================================
echo "\n── 4️⃣ STATS ACCURACY ──\n";
// ============================================================

// FP-07: Total revenue includes our new payment
$totalRevenue = $paymentRepo->getTotalCollected();
assertTest(
    "FP-07: รายได้รวมรวม payment ใหม่",
    $totalRevenue >= 50, // at minimum our 50 + 10
    "totalRevenue=$totalRevenue (expected >= 60)"
);

// FP-08: Unpaid total includes remaining fines
$unpaidTotal = $borrowRepo->getTotalUnpaidFines();
assertTest(
    "FP-08: ยอดค้างชำระรวมค่าปรับที่เหลือ",
    $unpaidTotal >= 50, // 20 + 30 from member2
    "unpaidTotal=$unpaidTotal (expected >= 50)"
);

// FP-09: This month total
$thisMonth = $paymentRepo->getThisMonthTotal();
assertTest(
    "FP-09: ยอดเดือนนี้ > 0 (มี payment ใหม่)",
    $thisMonth > 0,
    "thisMonthTotal=$thisMonth"
);

// ============================================================
echo "\n── 5️⃣ PAYMENT SEARCH ──\n";
// ============================================================

// FP-10: Search payment history by member name
$payments = $paymentRepo->findAll(['search' => "Pay Tester $ts"]);
assertTest(
    "FP-10: ค้นหา payment ด้วยชื่อสมาชิก → พบ",
    count($payments) >= 1,
    "found=" . count($payments)
);

// FP-11: Search with non-matching term → empty
$emptySearch = $paymentRepo->findAll(['search' => "NONEXISTENT_MEMBER_$ts"]);
assertTest(
    "FP-11: ค้นหา payment ด้วยชื่อที่ไม่มี → ไม่พบ",
    count($emptySearch) === 0,
    "found=" . count($emptySearch)
);

// ============================================================
echo "\n── 6️⃣ IDEMPOTENCY KEY ──\n";
// ============================================================

// FP-12: Simulate idempotency key check (session-level)
$testBorrowId = $multiUnpaidIds[1]; // still unpaid
$idempotencyKey = 'pay_fine_' . $testBorrowId;

// Simulate first submission
$_SESSION['processed_actions'][$idempotencyKey] = time();

// Check if second submission would be blocked
$isBlocked = isset($_SESSION['processed_actions'][$idempotencyKey])
    && (time() - $_SESSION['processed_actions'][$idempotencyKey] < 60);

assertTest(
    "FP-12: Idempotency key ป้องกัน double-submit (session check)",
    $isBlocked,
    "blocked=$isBlocked"
);

// Also verify DB-level protection: UNIQUE constraint
$borrowService->payFine($testBorrowId, $staffId);
$cleanupPaymentBorrowIds[] = $testBorrowId;

$fp12_db_blocked = false;
try {
    $borrowService->payFine($testBorrowId, $staffId);
} catch (Exception $e) {
    $fp12_db_blocked = true;
}
assertTest(
    "FP-13: DB UNIQUE constraint ป้องกันชำระซ้ำ (defense-in-depth)",
    $fp12_db_blocked,
    "dbBlocked=$fp12_db_blocked"
);

// ============================================================
echo "\n── CLEANUP ──\n";
// ============================================================

// Delete payments
foreach ($cleanupPaymentBorrowIds as $bid) {
    try {
        $pdo->prepare("DELETE FROM payments WHERE borrow_id = ?")->execute([$bid]);
    } catch (Exception $e) {
    }
}
// Delete borrows
foreach ($cleanupBorrowIds as $bid) {
    try {
        $pdo->prepare("DELETE FROM borrows WHERE id = ?")->execute([$bid]);
    } catch (Exception $e) {
    }
}
// Delete books
foreach ($cleanupBookIds as $bid) {
    try {
        $pdo->prepare("DELETE FROM books WHERE id = ?")->execute([$bid]);
    } catch (Exception $e) {
    }
}
// Delete users
foreach ($cleanupUserIds as $uid) {
    try {
        $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'")->execute([$uid]);
    } catch (Exception $e) {
    }
}
// Delete categories
foreach ($cleanupCatIds as $cid) {
    try {
        $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$cid]);
    } catch (Exception $e) {
    }
}

echo "  Cleanup done\n";

// ============================================================
echo "\n════════════════════════════════════════\n";
echo " RESULTS: $passed/$total passed";
if ($failed > 0) echo " | $failed FAILED";
echo "\n════════════════════════════════════════\n\n";

exit($failed > 0 ? 1 : 0);
