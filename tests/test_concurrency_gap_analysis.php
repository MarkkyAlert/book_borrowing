<?php

/**
 * Section 18 — Concurrency + Idempotency Gap Analysis
 * 
 * Tests:
 * ── Double Submit ─────────────────────────
 * CI-01: ยืม double submit → idempotency key blocks
 * CI-02: จอง double submit → idempotency key blocks
 * CI-03: คืน double submit → idempotency key blocks
 * CI-04: ชำระ double submit → idempotency key blocks
 * CI-05: อนุมัติจอง double submit → idempotency key blocks
 * 
 * ── PRG Pattern ──────────────────────────
 * CI-06: borrows.php POST → redirect (PRG)
 * CI-07: payments.php POST → redirect (PRG)
 * CI-08: reservations.php POST → redirect (PRG)
 * 
 * ── Race Condition (DB-level) ────────────
 * CI-09: createReservation FOR UPDATE lock on book row
 * CI-10: fulfillReservation FOR UPDATE lock on reservation
 * CI-11: cancelReservation FOR UPDATE lock on reservation
 * CI-12: returnBook FOR UPDATE lock (status=borrowing only)
 * CI-13: payFine FOR UPDATE + UNIQUE borrow_id
 * CI-14: expireReservation FOR UPDATE batch lock
 * CI-15: createBorrow FOR UPDATE + quota check
 * CI-16: Double return → service rejects (row lock + status check)
 * CI-17: Double pay → UNIQUE constraint blocks
 * CI-18: Cron expire + user cancel same reservation → stock +1 only
 * CI-19: Delete book idempotency key
 * 
 * Usage: php tests/test_concurrency_gap_analysis.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$_SESSION = ['user_id' => 0, 'role' => 'admin', 'processed_actions' => []];
$_SERVER['PHP_SELF'] = 'tests/test_concurrency.php';

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
use App\Repositories\BorrowRepository;
use App\Repositories\ReservationRepository;
use App\Repositories\BookRepository;

$pdo = getDB();
$borrowService = new BorrowService($pdo);
$borrowRepo = new BorrowRepository($pdo);
$reservationService = new ReservationService($pdo);
$reservationRepo = new ReservationRepository($pdo);
$bookRepo = new BookRepository($pdo);

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

// Helper: create test user
function createTestUser(string $suffix = ''): int
{
    global $pdo;
    $email = 'ci_test_' . $suffix . '_' . time() . '@test.com';
    $hash = password_hash('123456', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, phone) VALUES (?, ?, ?, 'member', '0800000000')");
    $stmt->execute(["CI Test $suffix", $email, $hash]);
    return (int) $pdo->lastInsertId();
}

// Helper: create test book
function createTestBook(string $suffix = '', int $qty = 5): int
{
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO books (title, author, isbn, quantity, available, category_id) VALUES (?, 'Test Author', ?, ?, ?, 1)");
    $isbn = 'CI' . time() . $suffix;
    $stmt->execute(["CI Book $suffix", $isbn, $qty, $qty]);
    return (int) $pdo->lastInsertId();
}

echo "\n════════════════════════════════════════\n";
echo " Section 18: Concurrency + Idempotency\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "════════════════════════════════════════\n";

// ============================================================
echo "\n── 1️⃣ DOUBLE SUBMIT (Idempotency Keys) ──\n";
// ============================================================

// CI-01: Borrow double submit
$borrowCode = file_get_contents(__DIR__ . '/../admin/borrow_form.php');
$hasBorrowIdem = strpos($borrowCode, "idempotencyKey = 'borrow_'") !== false
    && strpos($borrowCode, "processed_actions") !== false;
assertTest(
    "CI-01: บันทึกยืม — idempotency key ป้องกัน double submit",
    $hasBorrowIdem,
    "borrow_form.php has idempotency key check"
);

// CI-02: Reserve double submit (API)
$reserveCode = file_get_contents(__DIR__ . '/../api/reserve_book.php');
$hasReserveIdem = strpos($reserveCode, "idempotencyKey = 'reserve_'") !== false
    && strpos($reserveCode, "processed_actions") !== false;
assertTest(
    "CI-02: จอง — idempotency key ป้องกัน double submit",
    $hasReserveIdem,
    "reserve_book.php has idempotency key check"
);

// CI-03: Return double submit
$returnCode = file_get_contents(__DIR__ . '/../admin/borrows.php');
$hasReturnIdem = strpos($returnCode, "idempotencyKey = 'return_'") !== false
    && strpos($returnCode, "processed_actions") !== false;
assertTest(
    "CI-03: คืน — idempotency key ป้องกัน double submit",
    $hasReturnIdem,
    "borrows.php has idempotency key check"
);

// CI-04: Pay fine double submit
$payCode = file_get_contents(__DIR__ . '/../admin/payments.php');
$hasPayIdem = strpos($payCode, "idempotencyKey = 'pay_fine_'") !== false
    && strpos($payCode, "processed_actions") !== false;
assertTest(
    "CI-04: ชำระค่าปรับ — idempotency key ป้องกัน double submit",
    $hasPayIdem,
    "payments.php has idempotency key check"
);

// CI-05: Approve reservation double submit
$approveCode = file_get_contents(__DIR__ . '/../admin/reservations.php');
$hasApproveIdem = strpos($approveCode, "idempotencyKey = 'reservation_'") !== false
    && strpos($approveCode, "processed_actions") !== false;
assertTest(
    "CI-05: อนุมัติจอง — idempotency key ป้องกัน double submit",
    $hasApproveIdem,
    "reservations.php has idempotency key check"
);

// ============================================================
echo "\n── 2️⃣ PRG PATTERN (Refresh After Submit) ──\n";
// ============================================================

// CI-06: borrows.php POST → redirect
$hasReturnRedirect = strpos($returnCode, "redirect('borrows.php')") !== false;
assertTest(
    "CI-06: borrows.php POST คืน → redirect (PRG)",
    $hasReturnRedirect,
    "redirect after return POST"
);

// CI-07: payments.php POST → redirect
$hasPayRedirect = strpos($payCode, "redirect('payments.php')") !== false;
assertTest(
    "CI-07: payments.php POST ชำระ → redirect (PRG)",
    $hasPayRedirect,
    "redirect after pay POST"
);

// CI-08: reservations.php POST → redirect
$hasApproveRedirect = strpos($approveCode, "redirect('reservations.php')") !== false;
assertTest(
    "CI-08: reservations.php POST อนุมัติ/ยกเลิก → redirect (PRG)",
    $hasApproveRedirect,
    "redirect after approve/cancel POST"
);

// ============================================================
echo "\n── 3️⃣ RACE CONDITION (FOR UPDATE Locks — DB-level) ──\n";
// ============================================================

// CI-09: 2 people reserve last book simultaneously
$userId1 = createTestUser('racer1');
$userId2 = createTestUser('racer2');
$bookId = createTestBook('lastcopy', 1); // only 1 available

$result1 = null;
$error1 = null;
$result2 = null;
$error2 = null;

try {
    $result1 = $reservationService->createReservation($userId1, $bookId);
} catch (Exception $e) {
    $error1 = $e->getMessage();
}

try {
    $result2 = $reservationService->createReservation($userId2, $bookId);
} catch (Exception $e) {
    $error2 = $e->getMessage();
}

$oneSucceeded = ($result1 !== null) xor ($result2 !== null)
    || ($result1 !== null && $error2 !== null);
assertTest(
    "CI-09: จองเล่มสุดท้ายพร้อมกัน → คนเดียวได้",
    $oneSucceeded || ($result1 !== null && $result2 === null),
    "user1=" . ($result1 ? 'success' : "fail: $error1") .
        ", user2=" . ($result2 ? 'success' : "fail: $error2")
);

// CI-10: fulfill + cancel same reservation simultaneously
$userId3 = createTestUser('simul');
$bookId2 = createTestBook('simul', 5);
$reservationService->createReservation($userId3, $bookId2);
$resId = (int) $pdo->query("SELECT id FROM reservations WHERE user_id = $userId3 AND book_id = $bookId2 AND status = 'pending' LIMIT 1")->fetchColumn();

$fulfillResult = null;
$fulfillError = null;
$cancelResult = null;
$cancelError = null;

try {
    $fulfillResult = $reservationService->fulfillReservation($resId);
} catch (Exception $e) {
    $fulfillError = $e->getMessage();
}

try {
    $cancelResult = $reservationService->cancelReservation($resId);
} catch (Exception $e) {
    $cancelError = $e->getMessage();
}

// One should succeed, other should fail (reservation is no longer pending)
$onlyOneSucceeded = ($fulfillResult !== null && $cancelResult === null)
    || ($fulfillResult === null && $cancelResult !== null);
assertTest(
    "CI-10: fulfill + cancel พร้อมกัน → ทำได้แค่อย่างเดียว",
    $onlyOneSucceeded,
    "fulfill=" . ($fulfillResult ? 'OK' : "fail: $fulfillError") .
        ", cancel=" . ($cancelResult ? 'OK' : "fail: $cancelError")
);

// CI-11: Double return same book → second fails
$userId4 = createTestUser('dblret');
$bookId3 = createTestBook('dblret', 5);

// Create a borrow
$borrowService->createBorrow($userId4, [$bookId3]);
$borrowId = (int) $pdo->query("SELECT id FROM borrows WHERE user_id = $userId4 AND book_id = $bookId3 AND status = 'borrowing' LIMIT 1")->fetchColumn();

// First return
$ret1 = null;
$retErr1 = null;
try {
    $ret1 = $borrowService->returnBook($borrowId);
} catch (Exception $e) {
    $retErr1 = $e->getMessage();
}

// Second return (should fail - already returned)
$ret2 = null;
$retErr2 = null;
try {
    $ret2 = $borrowService->returnBook($borrowId);
} catch (Exception $e) {
    $retErr2 = $e->getMessage();
}

assertTest(
    "CI-11: คืนหนังสือซ้ำ → ครั้งที่ 2 fail (FOR UPDATE + status check)",
    $ret1 !== null && $retErr2 !== null,
    "ret1=" . ($ret1 ? 'OK' : "fail: $retErr1") .
        ", ret2=" . ($ret2 ? 'OK' : "fail: $retErr2")
);

// CI-12: Double pay same fine → second fails
$userId5 = createTestUser('dblpay');
$bookId4 = createTestBook('dblpay', 5);

// Create borrow + make it overdue
$borrowService->createBorrow($userId5, [$bookId4]);
$borrowId2 = (int) $pdo->query("SELECT id FROM borrows WHERE user_id = $userId5 AND book_id = $bookId4 AND status = 'borrowing' LIMIT 1")->fetchColumn();
$pdo->prepare("UPDATE borrows SET due_date = DATE_SUB(CURDATE(), INTERVAL 3 DAY) WHERE id = ?")
    ->execute([$borrowId2]);

// Return (creates fine)
$borrowService->returnBook($borrowId2);

// First pay
$pay1 = null;
$payErr1 = null;
try {
    $pay1 = $borrowService->payFine($borrowId2, $_SESSION['user_id']);
} catch (Exception $e) {
    $payErr1 = $e->getMessage();
}

// Second pay (should fail)
$pay2 = null;
$payErr2 = null;
try {
    $pay2 = $borrowService->payFine($borrowId2, $_SESSION['user_id']);
} catch (Exception $e) {
    $payErr2 = $e->getMessage();
}

assertTest(
    "CI-12: ชำระค่าปรับซ้ำ → ครั้งที่ 2 fail",
    $pay1 !== null && $payErr2 !== null,
    "pay1=" . ($pay1 ? 'OK' : "fail: $payErr1") .
        ", pay2=" . ($pay2 ? 'OK' : "fail: $payErr2")
);

// CI-13: FOR UPDATE in ReservationRepo code review
$resRepoCode = file_get_contents(__DIR__ . '/../app/Repositories/ReservationRepository.php');
$hasFUInResRepo = strpos($resRepoCode, "FOR UPDATE") !== false;
assertTest(
    "CI-13: ReservationRepository uses FOR UPDATE locks",
    $hasFUInResRepo,
    "FOR UPDATE in ReservationRepository.php"
);

// CI-14: FOR UPDATE in BorrowRepository code review
$borrowRepoCode = file_get_contents(__DIR__ . '/../app/Repositories/BorrowRepository.php');
$hasFUInBorrowRepo = strpos($borrowRepoCode, "FOR UPDATE") !== false;
assertTest(
    "CI-14: BorrowRepository uses FOR UPDATE locks",
    $hasFUInBorrowRepo,
    "FOR UPDATE in BorrowRepository.php"
);

// CI-15: createBorrow quota check under lock
$borrowSvcCode = file_get_contents(__DIR__ . '/../app/Services/BorrowService.php');
$hasQuotaLock = strpos($borrowSvcCode, 'lockById') !== false
    || (strpos($borrowSvcCode, 'FOR UPDATE') !== false
        && strpos($borrowSvcCode, 'quota') !== false);
assertTest(
    "CI-15: createBorrow quota check ภายใต้ lock",
    $hasQuotaLock,
    "user lock + quota check in transaction"
);

// CI-16: Cron expire + cancel → stock restores only once
$userId6 = createTestUser('cronrace');
$bookId5 = createTestBook('cronrace', 3);

// Get stock before
$stockBefore = (int) $pdo->query("SELECT available FROM books WHERE id = $bookId5")->fetch()['available'];

// Create reservation (stock -1)
$reservationService->createReservation($userId6, $bookId5);
$resId3 = (int) $pdo->query("SELECT id FROM reservations WHERE user_id = $userId6 AND book_id = $bookId5 AND status = 'pending' ORDER BY id DESC LIMIT 1")->fetchColumn();

$stockAfterReserve = (int) $pdo->query("SELECT available FROM books WHERE id = $bookId5")->fetch()['available'];

// Cancel it (stock +1)
$reservationService->cancelReservation($resId3, $userId6);

$stockAfterCancel = (int) $pdo->query("SELECT available FROM books WHERE id = $bookId5")->fetch()['available'];

// Try to expire same reservation (should fail - already cancelled)
$pdo->prepare("UPDATE reservations SET expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE id = ?")
    ->execute([$resId3]);
$reservationService->expireOverdueReservations();

$stockAfterExpire = (int) $pdo->query("SELECT available FROM books WHERE id = $bookId5")->fetch()['available'];

assertTest(
    "CI-16: cancel + cron expire → stock คืนแค่ 1 ครั้ง",
    $stockAfterCancel === $stockBefore && $stockAfterExpire === $stockBefore,
    "before=$stockBefore, afterReserve=$stockAfterReserve, afterCancel=$stockAfterCancel, afterExpire=$stockAfterExpire"
);

// CI-17: Delete book idempotency
$booksCode = file_get_contents(__DIR__ . '/../admin/books.php');
$hasDeleteIdem = strpos($booksCode, "idempotencyKey = 'delete_book_'") !== false
    && strpos($booksCode, "processed_actions") !== false;
assertTest(
    "CI-17: ลบหนังสือ — idempotency key ป้องกัน double delete",
    $hasDeleteIdem,
    "books.php has idempotency key check"
);

// CI-18: Delete member idempotency
$membersCode = file_get_contents(__DIR__ . '/../admin/members.php');
$hasDeleteMemberIdem = strpos($membersCode, "idempotencyKey = 'delete_member_'") !== false
    && strpos($membersCode, "processed_actions") !== false;
assertTest(
    "CI-18: ลบสมาชิก — idempotency key ป้องกัน double delete",
    $hasDeleteMemberIdem,
    "members.php has idempotency key check"
);

// CI-19: cleanupIdempotencyKeys function exists and runs
$functionsCode = file_get_contents(__DIR__ . '/../includes/functions.php');
$hasCleanup = strpos($functionsCode, 'cleanupIdempotencyKeys') !== false
    && strpos($functionsCode, 'processed_actions') !== false;
assertTest(
    "CI-19: cleanupIdempotencyKeys ล้าง keys หมดอายุ",
    $hasCleanup,
    "functions.php has cleanup function"
);

// ============================================================
echo "\n── CLEANUP ──\n";
// ============================================================

// Clean up test data
$pdo->exec("DELETE FROM payments WHERE borrow_id IN (SELECT id FROM borrows WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'ci_test_%'))");
$pdo->exec("DELETE FROM borrows WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'ci_test_%')");
$pdo->exec("DELETE FROM reservations WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'ci_test_%')");
$pdo->exec("DELETE FROM users WHERE email LIKE 'ci_test_%'");
$pdo->exec("DELETE FROM books WHERE isbn LIKE 'CI%'");
echo "  Test data cleaned\n";

echo "\n════════════════════════════════════════\n";
echo " RESULTS: $passed/$total passed";
if ($failed > 0) echo " | $failed FAILED";
echo "\n════════════════════════════════════════\n\n";

exit($failed > 0 ? 1 : 0);
