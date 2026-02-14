<?php

/**
 * Section 13 — Admin Reservation Management Gap Analysis Tests
 * 
 * Tests:
 * - Fulfill: creates borrow, status→fulfilled, stock unchanged
 * - Cancel: status→cancelled, stock +1
 * - Failure: cancelled/expired/quota full/already borrowing/terminal state
 * - Idempotency: double fulfill, double cancel
 * 
 * Usage: php tests/test_reservation_admin_gap_analysis.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$_SESSION = ['user_id' => 0, 'role' => 'admin', 'processed_actions' => []];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = 'tests/test_reservation_admin_gap_analysis.php';

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

use App\Services\ReservationService;
use App\Services\BorrowService;
use App\Repositories\ReservationRepository;
use App\Repositories\BorrowRepository;
use App\Repositories\BookRepository;

$pdo = getDB();
$reservationService = new ReservationService($pdo);
$borrowService = new BorrowService($pdo);
$reservationRepo = new ReservationRepository($pdo);
$borrowRepo = new BorrowRepository($pdo);
$bookRepo = new BookRepository($pdo);

$passed = 0;
$failed = 0;
$total = 0;
$cleanupReservationIds = [];
$cleanupBorrowIds = [];
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
echo " Section 13: Admin Reservation Gap Analysis\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "════════════════════════════════════════\n\n";

// ── SETUP ──
echo "── SETUP ──\n";

// Create test category
$pdo->prepare("INSERT INTO categories (name) VALUES (?)")->execute(["_res_admin_cat_$ts"]);
$catId = (int) $pdo->lastInsertId();
$cleanupCatIds[] = $catId;

// Create test books (multiple for multi-quota test)
$bookIds = [];
for ($i = 1; $i <= 5; $i++) {
    $pdo->prepare("INSERT INTO books (title, author, category_id, quantity, available) VALUES (?,?,?,?,?)")
        ->execute(["_res_admin_book_{$i}_$ts", "_author", $catId, 5, 5]);
    $bookIds[] = (int) $pdo->lastInsertId();
    $cleanupBookIds[] = end($bookIds);
}

// Create test members
$hash = hashPassword('Test123456');
$pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?,?,?,?)")
    ->execute(["Res Admin Tester $ts", "_res_admin_$ts@test.com", $hash, 'member']);
$memberId = (int) $pdo->lastInsertId();
$cleanupUserIds[] = $memberId;

$pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?,?,?,?)")
    ->execute(["Res Admin Tester2 $ts", "_res_admin2_$ts@test.com", $hash, 'member']);
$member2Id = (int) $pdo->lastInsertId();
$cleanupUserIds[] = $member2Id;

echo "  Category: $catId, Books: " . implode(',', $bookIds) . "\n";
echo "  Members: $memberId, $member2Id\n\n";

// Helper to create a pending reservation directly
function createPendingReservation(PDO $pdo, int $userId, int $bookId, int $expireDays = 2): int
{
    global $cleanupReservationIds;
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expireDays} days"));
    $pdo->prepare("INSERT INTO reservations (user_id, book_id, status, expires_at) VALUES (?,?,?,?)")
        ->execute([$userId, $bookId, 'pending', $expiresAt]);
    $id = (int) $pdo->lastInsertId();
    $cleanupReservationIds[] = $id;
    // Decrement stock (simulating what createReservation does)
    $pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ? AND available > 0")->execute([$bookId]);
    return $id;
}

// ============================================================
echo "── 1️⃣ HAPPY PATH: FULFILL ──\n";
// ============================================================

// Record stock before
$bookBefore = $bookRepo->findById($bookIds[0]);
$stockBefore = (int) $bookBefore['available'];

// Create pending reservation
$resId1 = createPendingReservation($pdo, $memberId, $bookIds[0]);

// Stock should have been decremented
$bookAfterReserve = $bookRepo->findById($bookIds[0]);
$stockAfterReserve = (int) $bookAfterReserve['available'];
assertTest(
    "RA-01: จองแล้ว stock ลด -1",
    $stockAfterReserve === $stockBefore - 1,
    "before=$stockBefore, afterReserve=$stockAfterReserve"
);

// Fulfill the reservation
$result = $reservationService->fulfillReservation($resId1);

assertTest(
    "RA-02: fulfillReservation สำเร็จ",
    $result['success'] === true && !empty($result['borrow_id']),
    "borrow_id={$result['borrow_id']}"
);
$borrowId1 = $result['borrow_id'];
$cleanupBorrowIds[] = $borrowId1;

// Check reservation status changed to fulfilled
$stmt = $pdo->prepare("SELECT status, borrow_id FROM reservations WHERE id = ?");
$stmt->execute([$resId1]);
$resFulfilled = $stmt->fetch();
assertTest(
    "RA-03: สถานะเปลี่ยนเป็น fulfilled + link borrow_id",
    $resFulfilled['status'] === 'fulfilled' && (int)$resFulfilled['borrow_id'] === $borrowId1,
    "status={$resFulfilled['status']}, borrow_id={$resFulfilled['borrow_id']}"
);

// Check borrow record created
$stmt = $pdo->prepare("SELECT * FROM borrows WHERE id = ?");
$stmt->execute([$borrowId1]);
$borrow = $stmt->fetch();
assertTest(
    "RA-04: borrow record สร้างถูกต้อง",
    $borrow && (int)$borrow['user_id'] === $memberId && (int)$borrow['book_id'] === $bookIds[0] && $borrow['status'] === 'borrowing',
    "user={$borrow['user_id']}, book={$borrow['book_id']}, status={$borrow['status']}"
);

// Stock should NOT change after fulfill (already decremented at reservation time)
$bookAfterFulfill = $bookRepo->findById($bookIds[0]);
$stockAfterFulfill = (int) $bookAfterFulfill['available'];
assertTest(
    "RA-05: Stock ไม่เปลี่ยนหลัง fulfill (หักไปตอนจองแล้ว)",
    $stockAfterFulfill === $stockAfterReserve,
    "afterReserve=$stockAfterReserve, afterFulfill=$stockAfterFulfill"
);

// ============================================================
echo "\n── 2️⃣ HAPPY PATH: CANCEL ──\n";
// ============================================================

$resId2 = createPendingReservation($pdo, $memberId, $bookIds[1]);
$stockBeforeCancel = (int) $bookRepo->findById($bookIds[1])['available'];

$cancelResult = $reservationService->cancelReservation($resId2);
assertTest(
    "RA-06: cancelReservation สำเร็จ",
    $cancelResult['success'] === true,
    "message={$cancelResult['message']}"
);

// Check status changed
$stmt = $pdo->prepare("SELECT status FROM reservations WHERE id = ?");
$stmt->execute([$resId2]);
$resCancelled = $stmt->fetch();
assertTest(
    "RA-07: สถานะเปลี่ยนเป็น cancelled",
    $resCancelled['status'] === 'cancelled',
    "status={$resCancelled['status']}"
);

// Stock should be restored +1
$stockAfterCancel = (int) $bookRepo->findById($bookIds[1])['available'];
assertTest(
    "RA-08: Stock คืนกลับ +1 หลัง cancel",
    $stockAfterCancel === $stockBeforeCancel + 1,
    "beforeCancel=$stockBeforeCancel, afterCancel=$stockAfterCancel"
);

// ============================================================
echo "\n── 3️⃣ FAILURE CASES ──\n";
// ============================================================

// RA-09: Fulfill already cancelled reservation
$ra09_error = false;
try {
    $reservationService->fulfillReservation($resId2);  // $resId2 is cancelled
} catch (Exception $e) {
    $ra09_error = true;
    $ra09_msg = $e->getMessage();
}
assertTest(
    "RA-09: อนุมัติจองที่ cancelled แล้ว → exception",
    $ra09_error,
    "error: " . ($ra09_msg ?? 'none')
);

// RA-10: Fulfill expired reservation
// Create a reservation with expired date
$pdo->prepare("INSERT INTO reservations (user_id, book_id, status, expires_at) VALUES (?,?,?,?)")
    ->execute([$member2Id, $bookIds[2], 'expired', date('Y-m-d H:i:s', strtotime('-1 day'))]);
$resIdExpired = (int) $pdo->lastInsertId();
$cleanupReservationIds[] = $resIdExpired;

$ra10_error = false;
try {
    $reservationService->fulfillReservation($resIdExpired);
} catch (Exception $e) {
    $ra10_error = true;
    $ra10_msg = $e->getMessage();
}
assertTest(
    "RA-10: อนุมัติจองที่ expired แล้ว → exception",
    $ra10_error,
    "error: " . ($ra10_msg ?? 'none')
);

// RA-11: Fulfill when member has full quota
// Fill member2's quota with borrows (MAX_BORROW_BOOKS)
$quotaBorrowIds = [];
for ($i = 0; $i < MAX_BORROW_BOOKS; $i++) {
    $pdo->prepare("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status) 
        VALUES (?,?,CURDATE(),DATE_ADD(CURDATE(), INTERVAL 7 DAY),'borrowing')")
        ->execute([$member2Id, $bookIds[$i]]);
    $quotaBorrowIds[] = (int) $pdo->lastInsertId();
    $cleanupBorrowIds[] = end($quotaBorrowIds);
}

// Create a pending reservation for member2 on a different book
$resIdQuotaFull = createPendingReservation($pdo, $member2Id, $bookIds[4]);

$ra11_error = false;
try {
    $reservationService->fulfillReservation($resIdQuotaFull);
} catch (Exception $e) {
    $ra11_error = true;
    $ra11_msg = $e->getMessage();
}
assertTest(
    "RA-11: อนุมัติเมื่อสมาชิกเต็มโควตา → exception",
    $ra11_error,
    "error: " . ($ra11_msg ?? 'none')
);

// Clean up quota borrows
foreach ($quotaBorrowIds as $bid) {
    $pdo->prepare("DELETE FROM borrows WHERE id = ?")->execute([$bid]);
}
$cleanupBorrowIds = array_diff($cleanupBorrowIds, $quotaBorrowIds);

// RA-12: Fulfill when member already borrows this book
// Member1 already has borrowId1 for bookIds[0] → create another reservation for same book
$pdo->prepare("INSERT INTO reservations (user_id, book_id, status, expires_at) VALUES (?,?,?,?)")
    ->execute([$memberId, $bookIds[0], 'pending', date('Y-m-d H:i:s', strtotime('+2 days'))]);
$resIdDupBorrow = (int) $pdo->lastInsertId();
$cleanupReservationIds[] = $resIdDupBorrow;

$ra12_error = false;
try {
    $reservationService->fulfillReservation($resIdDupBorrow);
} catch (Exception $e) {
    $ra12_error = true;
    $ra12_msg = $e->getMessage();
}
assertTest(
    "RA-12: อนุมัติเมื่อสมาชิกยืมเล่มนี้อยู่แล้ว → exception",
    $ra12_error,
    "error: " . ($ra12_msg ?? 'none')
);

// RA-13: Cancel a fulfilled reservation → error (terminal state)
$ra13_error = false;
try {
    $reservationService->cancelReservation($resId1);  // $resId1 is fulfilled
} catch (Exception $e) {
    $ra13_error = true;
    $ra13_msg = $e->getMessage();
}
assertTest(
    "RA-13: ยกเลิกจองที่ fulfilled แล้ว → exception (terminal state)",
    $ra13_error,
    "error: " . ($ra13_msg ?? 'none')
);

// ============================================================
echo "\n── 4️⃣ IDEMPOTENCY ──\n";
// ============================================================

// RA-14: Double fulfill → second attempt fails
$resIdIdempotent = createPendingReservation($pdo, $member2Id, $bookIds[3]);
$firstFulfill = $reservationService->fulfillReservation($resIdIdempotent);
$cleanupBorrowIds[] = $firstFulfill['borrow_id'];

$ra14_error = false;
try {
    $reservationService->fulfillReservation($resIdIdempotent);
} catch (Exception $e) {
    $ra14_error = true;
    $ra14_msg = $e->getMessage();
}
assertTest(
    "RA-14: กดอนุมัติ 2 ครั้ง → ครั้งที่ 2 exception (ไม่สร้าง borrow ซ้ำ)",
    $ra14_error,
    "error: " . ($ra14_msg ?? 'none')
);

// Verify only 1 borrow created
$stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM borrows WHERE user_id = ? AND book_id = ?");
$stmt->execute([$member2Id, $bookIds[3]]);
$borrowCount = (int) $stmt->fetch()['cnt'];
assertTest(
    "RA-15: มี borrow record แค่ 1 รายการ (ไม่ซ้ำ)",
    $borrowCount === 1,
    "borrowCount=$borrowCount"
);

// RA-16: Double cancel → second attempt fails
$resIdDoubleCancel = createPendingReservation($pdo, $member2Id, $bookIds[2]);
$stockBeforeDoubleCancel = (int) $bookRepo->findById($bookIds[2])['available'];

$reservationService->cancelReservation($resIdDoubleCancel);
$stockAfterFirstCancel = (int) $bookRepo->findById($bookIds[2])['available'];

$ra16_error = false;
try {
    $reservationService->cancelReservation($resIdDoubleCancel);
} catch (Exception $e) {
    $ra16_error = true;
    $ra16_msg = $e->getMessage();
}
assertTest(
    "RA-16: กดยกเลิก 2 ครั้ง → ครั้งที่ 2 exception",
    $ra16_error,
    "error: " . ($ra16_msg ?? 'none')
);

// Stock should only increase by 1, not 2
$stockAfterDoubleCancel = (int) $bookRepo->findById($bookIds[2])['available'];
assertTest(
    "RA-17: Stock +1 ไม่ใช่ +2 (ป้องกัน stock leak)",
    $stockAfterDoubleCancel === $stockAfterFirstCancel,
    "afterFirstCancel=$stockAfterFirstCancel, afterDoubleCancel=$stockAfterDoubleCancel"
);

// ============================================================
echo "\n── CLEANUP ──\n";
// ============================================================

foreach ($cleanupBorrowIds as $bid) {
    try {
        $pdo->prepare("DELETE FROM borrows WHERE id = ?")->execute([$bid]);
    } catch (Exception $e) {
    }
}
foreach ($cleanupReservationIds as $rid) {
    try {
        $pdo->prepare("DELETE FROM reservations WHERE id = ?")->execute([$rid]);
    } catch (Exception $e) {
    }
}
foreach ($cleanupBookIds as $bid) {
    try {
        $pdo->prepare("DELETE FROM books WHERE id = ?")->execute([$bid]);
    } catch (Exception $e) {
    }
}
foreach ($cleanupUserIds as $uid) {
    try {
        $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'")->execute([$uid]);
    } catch (Exception $e) {
    }
}
foreach ($cleanupCatIds as $cid) {
    try {
        $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$cid]);
    } catch (Exception $e) {
    }
}

echo "  Cleanup done\n";

echo "\n════════════════════════════════════════\n";
echo " RESULTS: $passed/$total passed";
if ($failed > 0) echo " | $failed FAILED";
echo "\n════════════════════════════════════════\n\n";

exit($failed > 0 ? 1 : 0);
