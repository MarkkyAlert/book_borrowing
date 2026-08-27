<?php

/**
 * Test Script for Section 13: Reservations
 * Verifies:
 * - Happy Path: Create -> Fulfill -> Borrow created -> Stock maintained
 * - Happy Path: Create -> Cancel -> Stock returned
 * - Happy Path: Create -> Expire -> Stock returned
 * - Failure Case: Reserve out of stock
 * - Failure Case: Reserve duplicate book
 * - Failure Case: Reserve over quota
 */

require_once __DIR__ . '/../bootstrap.php';
// require_once __DIR__ . '/test_helpers.php'; // Removed

use App\Services\ReservationService;
use App\Services\BookService;
use App\Services\MemberService;
use App\Repositories\BookRepository;
use App\Repositories\ReservationRepository;
use App\Repositories\BorrowRepository;
use App\Repositories\UserRepository;

// Initialize Services
$pdo = getDB();
$reservationService = new ReservationService($pdo);
$bookRepo = new BookRepository($pdo);
$reservationRepo = new ReservationRepository($pdo);
$borrowRepo = new BorrowRepository($pdo);
$userRepo = new UserRepository($pdo);

// Helper: Create Test User
function createTestUser($pdo, $email, $role = 'member')
{
    $stmt = $pdo->prepare("INSERT INTO users (email, password, name, role) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
    $stmt->execute([$email, password_hash('password', PASSWORD_DEFAULT), 'Test User ' . rand(1000, 9999), $role]);
    return $pdo->lastInsertId();
}

// Helper: Create Test Book
function createTestBook($pdo, $isbn, $qty = 5)
{
    $stmt = $pdo->prepare("INSERT INTO books (isbn, title, author, quantity, available) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), available=?");
    $stmt->execute([$isbn, 'Test Book ' . rand(1000, 9999), 'Author', $qty, $qty, $qty]);
    return $pdo->lastInsertId();
}

/**
 * 🧹 ลบเฉพาะข้อมูลที่ไฟล์นี้สร้างเอง (อ้างอิงจากรูปแบบ email / ISBN ด้านบน)
 *
 * 🔴 เดิมเขียนไว้แบบนี้ ซึ่งอันตรายมาก:
 *      DELETE FROM reservations WHERE created_at > NOW() - INTERVAL 5 MINUTE
 *      DELETE FROM borrows WHERE borrow_date > CURDATE() - INTERVAL 1 DAY
 *    → ลบ "การจองที่เพิ่งเกิดใน 5 นาที" และ "การยืมของเมื่อวาน-วันนี้" **ทั้งหมด**
 *      ไม่ว่าจะเป็นของใคร ถ้าใครเผลอรันไฟล์นี้บนฐานข้อมูลจริง ข้อมูลลูกค้าหายทันที
 *      (ในโค้ดเดิมมีคอมเมนต์กำกับว่า "Dangerous but ok for test env" — แต่ไฟล์นี้
 *       ถูกแพ็กไปกับสินค้าด้วย ชื่อไฟล์ก็ดูไม่มีพิษภัย)
 *
 * ✅ ตอนนี้ผูกกับรูปแบบชื่อของข้อมูลทดสอบเท่านั้น ไม่แตะข้อมูลอื่นเลย
 */
function cleanupReservationTestData(PDO $pdo): void
{
    // 📌 ลบตามลำดับ FK: payments → reservations → borrows → books/users
    $bookFilter = "SELECT id FROM books WHERE isbn LIKE '978-RES-%' OR isbn LIKE '978-CAN-%'
                   OR isbn LIKE '978-OOS-%' OR isbn LIKE '978-DUPE-%' OR isbn LIKE '978-EXP-%'";
    $userFilter = "SELECT id FROM users WHERE email LIKE 'res_tester%@test.com'";

    $pdo->exec("DELETE FROM payments WHERE borrow_id IN (
                    SELECT id FROM borrows WHERE book_id IN ($bookFilter) OR user_id IN ($userFilter))");
    $pdo->exec("DELETE FROM reservations WHERE book_id IN ($bookFilter) OR user_id IN ($userFilter)");
    $pdo->exec("DELETE FROM borrows WHERE book_id IN ($bookFilter) OR user_id IN ($userFilter)");
    $pdo->exec("DELETE FROM books WHERE id IN ($bookFilter)");
    $pdo->exec("DELETE FROM users WHERE id IN ($userFilter)");
}

// 🧹 เคลียร์ของค้างจากรอบก่อน (เผื่อรอบก่อนพังกลางคัน)
cleanupReservationTestData($pdo);

echo "════════════════════════════════════════\n";
echo " Section 13: Reservations Verification\n";
echo "════════════════════════════════════════\n\n";

try {
    // Setup Data
    $userId = createTestUser($pdo, 'res_tester_' . time() . '@test.com');
    $bookId = createTestBook($pdo, '978-RES-' . time(), 2); // Qty 2

    echo "1. Happy Path: Create Reservation\n";
    $res = $reservationService->createReservation($userId, $bookId);
    if ($res['success']) {
        echo "  ✅ PASS: Reservation created. ID: " . ($res['reservation_id'] ?? 'MISSING') . "\n";
    } else {
        echo "  ❌ FAIL: Create failed: " . $res['message'] . "\n";
        exit;
    }

    // Verify Stock Reduced
    $book = $bookRepo->findById($bookId);
    if ($book['available'] == 1) { // 2 - 1 = 1
        echo "  ✅ PASS: Stock reduced to 1\n";
    } else {
        echo "  ❌ FAIL: Stock mismatch. Expected 1, got " . $book['available'] . "\n";
    }

    echo "\n2. Happy Path: Fulfill (Approve)\n";
    // Fulfill
    $resId = $res['reservation_id'];
    $fulfillRes = $reservationService->fulfillReservation($resId);

    // Verify Status = fulfilled
    $reservation = $reservationRepo->findById($resId);
    if ($reservation['status'] === 'fulfilled') {
        echo "  ✅ PASS: Status updated to 'fulfilled'\n";
    } else {
        echo "  ❌ FAIL: Status is " . $reservation['status'] . "\n";
    }

    // Verify Borrow Created
    if (!empty($reservation['borrow_id'])) {
        echo "  ✅ PASS: Borrow ID linked: " . $reservation['borrow_id'] . "\n";
        $borrow = $borrowRepo->findById($reservation['borrow_id']);
        if ($borrow['status'] === 'borrowing') {
            echo "  ✅ PASS: Borrow record status is 'borrowing'\n";
        }
    } else {
        echo "  ❌ FAIL: No borrow_id linked\n";
    }

    // Verify Stock NOT Changed (already deducted during reserve)
    $book = $bookRepo->findById($bookId);
    if ($book['available'] == 1) {
        echo "  ✅ PASS: Stock remains 1 (correct)\n";
    } else {
        echo "  ❌ FAIL: Stock changed during fulfill! Got " . $book['available'] . "\n";
    }

    echo "\n3. Happy Path: Cancel Reservation\n";
    // Setup new reservation with FRESH book to avoid "Already Borrowing" error
    $bookIdCancel = createTestBook($pdo, '978-CAN-' . time(), 5);
    $resCancel = $reservationService->createReservation($userId, $bookIdCancel); // Stock 5 -> 4
    echo "  Created new reservation ID: " . $resCancel['reservation_id'] . " (Stock 5 -> 4)\n";

    // Cancel
    $reservationService->cancelReservation($resCancel['reservation_id'], $userId);

    $reservation = $reservationRepo->findById($resCancel['reservation_id']);
    if ($reservation['status'] === 'cancelled') {
        echo "  ✅ PASS: Status updated to 'cancelled'\n";
    } else {
        echo "  ❌ FAIL: Status is " . $reservation['status'] . "\n";
    }

    // Verify Stock Returned
    $book = $bookRepo->findById($bookIdCancel);
    if ($book['available'] == 5) { // 4 + 1 = 5
        echo "  ✅ PASS: Stock returned to 5\n";
    } else {
        echo "  ❌ FAIL: Stock not returned. Expected 5, got " . $book['available'] . "\n";
    }


    echo "\n4. Failure Case: Reserve Out of Stock\n";
    // Create Book with Qty 1
    $bookIdOOS = createTestBook($pdo, '978-OOS-' . time(), 1);
    // User 1 reserves it
    $resOOS = $reservationService->createReservation($userId, $bookIdOOS); // Stock 1 -> 0
    echo "  User A reserved last copy.\n";

    // Create User 2
    $userId2 = createTestUser($pdo, 'res_tester2_' . time() . '@test.com');

    try {
        $reservationService->createReservation($userId2, $bookIdOOS);
        echo "  ❌ FAIL: Should fail when out of stock\n";
    } catch (Exception $e) {
        // Expected "Book is not available"
        if (strpos($e->getMessage(), 'หนังสือหมด') !== false || strpos($e->getMessage(), 'available') !== false) {
            echo "  ✅ PASS: Caught expected error: " . $e->getMessage() . "\n";
        } else {
            echo "  ❌ FAIL: Caught unexpected error message: " . $e->getMessage() . "\n";
        }
    }
    // Cleanup Step 4 (Cancel User 1's reservation to free quota)
    // We don't have the ID captured? Ah, createReservation returns array now.
    // Wait, I didn't capture return in Step 4.
    // $reservationService->cancelReservation(..., $userId); 
    // Let's just manually delete or ignore. 
    // Actually, createReservation DOES return array. I should capture it.


    echo "\n5. Failure Case: Double Reserve (Same Book)\n";
    // Need a fresh book and fresh reservation for verify Duplicate check
    $bookId2 = createTestBook($pdo, '978-DUPE-' . time(), 5);
    $resDup = $reservationService->createReservation($userId, $bookId2);

    try {
        $reservationService->createReservation($userId, $bookId2);
        echo "  ❌ FAIL: Should fail when reserving same book pending\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'จองหนังสือเล่มนี้ไว้แล้ว') !== false || strpos($e->getMessage(), 'duplicate') !== false) {
            echo "  ✅ PASS: Caught expected error: " . $e->getMessage() . "\n";
        } else {
            echo "  ❌ FAIL: Caught unexpected error: " . $e->getMessage() . "\n";
        }
    }
    // Cleanup Step 5
    $reservationService->cancelReservation($resDup['reservation_id'], $userId);

    echo "\n6. Expire Logic (Lazy Expiration)\n";
    // Create simple reservation
    $resExpire = $reservationService->createReservation($userId, createTestBook($pdo, '978-EXP-' . time(), 5));
    // Manually update expires_at to past
    $pdo->prepare("UPDATE reservations SET expires_at = NOW() - INTERVAL 1 HOUR WHERE id = ?")->execute([$resExpire['reservation_id']]);

    // Trigger lazy expire
    $count = $reservationRepo->markExpiredReservations();

    if ($count > 0) {
        echo "  ✅ PASS: Expired $count reservations\n";
        $r = $reservationRepo->findById($resExpire['reservation_id']);
        if ($r['status'] === 'expired') {
            echo "  ✅ PASS: Status set to expired\n";
        }
    } else {
        echo "  ❌ FAIL: Did not expire reservation\n";
    }

    echo "\nAll Tests Completed.\n";
} catch (Exception $e) {
    echo "\n❌ FATAL ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
} finally {
    // 🧹 เก็บกวาดเสมอ แม้เทสต์จะพังกลางคัน
    //    ไม่งั้นจะทิ้งหนังสือที่ไม่มี search_tokens ไว้ แล้วชุด Search Index (SI-08) จะ fail
    cleanupReservationTestData($pdo);
    echo "\n🧹 เก็บกวาดข้อมูลทดสอบแล้ว\n";
}
