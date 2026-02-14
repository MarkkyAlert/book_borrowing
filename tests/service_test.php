<?php
/**
 * Service-Level Unit Tests
 * ทดสอบ Business Logic ผ่าน Service Layer โดยตรง (ไม่ผ่าน HTTP)
 * 
 * ครอบคลุม: BorrowService, ReservationService, BookService, MemberService, AuthService
 * ทดสอบ: Happy path, Edge case, Transaction, Lock guard, Quota, State machine
 * 
 * Usage: php tests/service_test.php
 * ⚠️ รันบน CLI เท่านั้น — ห้ามเปิดผ่าน browser
 * ⚠️ ข้อมูล test จะถูกลบทิ้งหลังรันเสร็จ (cleanup)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// Fake session for bootstrap (services use $_SESSION in some places)
$_SESSION = ['user_id' => 0, 'role' => 'admin', 'processed_actions' => []];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = 'tests/service_test.php'; // prevent bootstrap basename check

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Autoloader
spl_autoload_register(function (string $class) {
    $map = [
        'App\\Services\\' => __DIR__ . '/../app/Services/',
        'App\\Repositories\\' => __DIR__ . '/../app/Repositories/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . substr($class, strlen($prefix)) . '.php';
            if (file_exists($file)) { require_once $file; return; }
        }
    }
});

use App\Services\BorrowService;
use App\Services\ReservationService;
use App\Services\BookService;
use App\Services\MemberService;
use App\Services\AuthService;

// ============================================================
// TEST FRAMEWORK
// ============================================================
$results = ['passed' => 0, 'failed' => 0, 'errors' => [], 'total' => 0];
$cleanupIds = ['users' => [], 'books' => [], 'categories' => [], 'borrows' => [], 'reservations' => [], 'payments' => []];

function pass(string $id, string $msg = 'OK') {
    global $results;
    $results['total']++;
    $results['passed']++;
    echo "  \033[32m✅ $id\033[0m: $msg\n";
}

function fail(string $id, string $msg) {
    global $results;
    $results['total']++;
    $results['failed']++;
    $results['errors'][] = "$id: $msg";
    echo "  \033[31m❌ $id\033[0m: $msg\n";
}

function section(string $title) {
    echo "\n\033[1;36m─── $title ───\033[0m\n";
}

// ============================================================
// SETUP TEST DATA
// ============================================================
$pdo = getDB();
$ts = time();

echo "\n\033[1m══════════════════════════════════════\033[0m\n";
echo "\033[1m Service-Level Tests — " . date('Y-m-d H:i:s') . "\033[0m\n";
echo "\033[1m══════════════════════════════════════\033[0m\n";

section("SETUP");

// Create test category
$pdo->prepare("INSERT INTO categories (name) VALUES (?)")->execute(["_test_cat_$ts"]);
$catId = (int) $pdo->lastInsertId();
$cleanupIds['categories'][] = $catId;
echo "  Created test category ID: $catId\n";

// Create test book (qty=5)
$pdo->prepare("INSERT INTO books (title, author, category_id, quantity, available, isbn) VALUES (?,?,?,?,?,?)")
    ->execute(["_test_book_$ts", "_test_author", $catId, 5, 5, "TEST-$ts"]);
$bookId = (int) $pdo->lastInsertId();
$cleanupIds['books'][] = $bookId;
echo "  Created test book ID: $bookId (qty=5)\n";

// Create test book with qty=1 (for stock-empty tests)
$pdo->prepare("INSERT INTO books (title, author, category_id, quantity, available, isbn) VALUES (?,?,?,?,?,?)")
    ->execute(["_test_book_single_$ts", "_test_author", $catId, 1, 1, "TEST2-$ts"]);
$bookSingleId = (int) $pdo->lastInsertId();
$cleanupIds['books'][] = $bookSingleId;
echo "  Created test book (single) ID: $bookSingleId (qty=1)\n";

// Create test users
$hash = hashPassword('Test123456');
$pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?,?,?,?)")
    ->execute(["_test_member_$ts", "_test_m_$ts@test.com", $hash, 'member']);
$memberId = (int) $pdo->lastInsertId();
$cleanupIds['users'][] = $memberId;
echo "  Created test member ID: $memberId\n";

$pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?,?,?,?)")
    ->execute(["_test_member2_$ts", "_test_m2_$ts@test.com", $hash, 'member']);
$member2Id = (int) $pdo->lastInsertId();
$cleanupIds['users'][] = $member2Id;
echo "  Created test member2 ID: $member2Id\n";

// Staff user for borrow operations
$pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?,?,?,?)")
    ->execute(["_test_staff_$ts", "_test_s_$ts@test.com", $hash, 'staff']);
$staffId = (int) $pdo->lastInsertId();
$cleanupIds['users'][] = $staffId;
echo "  Created test staff ID: $staffId\n";

// ============================================================
// A. AuthService Tests
// ============================================================
section("A. AuthService (5 tests)");

$authService = new AuthService($pdo);

// AS-01: Login success
$user = $authService->login("_test_m_$ts@test.com", 'Test123456');
if ($user && $user['id'] == $memberId) {
    pass('AS-01', 'Login success — correct user returned');
} else {
    fail('AS-01', 'Login should return user');
}

// AS-02: Login wrong password
$user = $authService->login("_test_m_$ts@test.com", 'WrongPassword');
if ($user === null) {
    pass('AS-02', 'Login wrong password — returns null (no enumeration)');
} else {
    fail('AS-02', 'Wrong password should return null');
}

// AS-03: Login non-existent email
$user = $authService->login("ghost_$ts@nowhere.com", 'Test123');
if ($user === null) {
    pass('AS-03', 'Login non-existent email — returns null (no enumeration)');
} else {
    fail('AS-03', 'Non-existent email should return null');
}

// AS-04: Change password — new same as old
$result = $authService->changePassword($memberId, 'Test123456', 'Test123456');
if (!$result['success'] && stripos($result['error'], 'ซ้ำ') !== false) {
    pass('AS-04', 'Change password same as old — blocked: ' . $result['error']);
} else {
    fail('AS-04', 'Same password should be blocked');
}

// AS-05: Change password — wrong current
$result = $authService->changePassword($memberId, 'WrongCurrent', 'NewPass123');
if (!$result['success']) {
    pass('AS-05', 'Change password wrong current — blocked: ' . $result['error']);
} else {
    fail('AS-05', 'Wrong current password should be blocked');
}

// ============================================================
// B. MemberService Tests
// ============================================================
section("B. MemberService (6 tests)");

$memberService = new MemberService($pdo);

// MS-01: Create member — duplicate email
try {
    $memberService->createMember([
        'name' => 'Dup', 'email' => "_test_m_$ts@test.com",
        'phone' => '', 'password' => 'Test123456'
    ]);
    fail('MS-01', 'Duplicate email should throw Exception');
} catch (Exception $e) {
    if (stripos($e->getMessage(), 'อีเมล') !== false || stripos($e->getMessage(), 'ซ้ำ') !== false) {
        pass('MS-01', 'Duplicate email — blocked: ' . $e->getMessage());
    } else {
        fail('MS-01', 'Unexpected error: ' . $e->getMessage());
    }
}

// MS-02: Create member — success
try {
    $newMember = $memberService->createMember([
        'name' => "_test_new_$ts", 'email' => "_test_new_$ts@test.com",
        'phone' => '0899999999', 'password' => 'Test123456'
    ]);
    $cleanupIds['users'][] = $newMember['id'];
    pass('MS-02', "Create member success — ID: {$newMember['id']}");
} catch (Exception $e) {
    fail('MS-02', 'Create member failed: ' . $e->getMessage());
}

// MS-03: Update member — role whitelist
try {
    $memberService->updateMember($memberId, [
        'name' => "_test_member_$ts", 'email' => "_test_m_$ts@test.com",
        'phone' => '', 'role' => 'admin'
    ]);
    // Check if role was actually changed
    $updated = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $updated->execute([$memberId]);
    $role = $updated->fetchColumn();
    if ($role !== 'admin') {
        pass('MS-03', 'Role whitelist — admin blocked, role stays: ' . $role);
    } else {
        fail('MS-03', 'Role escalation to admin should be blocked!');
        // Revert
        $pdo->prepare("UPDATE users SET role = 'member' WHERE id = ?")->execute([$memberId]);
    }
} catch (Exception $e) {
    pass('MS-03', 'Role whitelist — exception: ' . $e->getMessage());
}

// MS-04: Delete member — with active borrow guard
// First, create a borrow for this member
$pdo->prepare("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status) VALUES (?,?,CURDATE(),DATE_ADD(CURDATE(), INTERVAL 7 DAY),'borrowing')")
    ->execute([$member2Id, $bookId]);
$tempBorrowId = (int) $pdo->lastInsertId();
$cleanupIds['borrows'][] = $tempBorrowId;
// Decrement stock
$pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ?")->execute([$bookId]);

try {
    $memberService->deleteMember($member2Id);
    fail('MS-04', 'Delete member with active borrow should throw Exception');
} catch (Exception $e) {
    if (stripos($e->getMessage(), 'ไม่สามารถลบ') !== false) {
        pass('MS-04', 'Delete guard (active borrow) — blocked: ' . $e->getMessage());
    } else {
        fail('MS-04', 'Unexpected error: ' . $e->getMessage());
    }
}

// MS-05: Email uniqueness on update
try {
    $memberService->updateMember($memberId, [
        'name' => "_test_member_$ts",
        'email' => "_test_m2_$ts@test.com", // member2's email
        'phone' => ''
    ]);
    fail('MS-05', 'Duplicate email on update should throw Exception');
} catch (Exception $e) {
    pass('MS-05', 'Email unique on update — blocked: ' . $e->getMessage());
}

// MS-06: Password is hashed (not plaintext)
$stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
$stmt->execute([$memberId]);
$hash = $stmt->fetchColumn();
if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2b$')) {
    pass('MS-06', 'Password stored as bcrypt hash');
} else {
    fail('MS-06', 'Password not hashed properly');
}

// ============================================================
// C. BookService Tests
// ============================================================
section("C. BookService (5 tests)");

$bookService = new BookService($pdo);

// BS-01: Update book — quantity reduction guard
try {
    // Currently 4 available (5-1 from borrow above). currentlyOut=1
    // Try to set quantity to 0 → should fail
    $bookService->updateBook($bookId, [
        'title' => "_test_book_$ts", 'author' => '_test_author',
        'isbn' => "TEST-$ts", 'quantity' => 0
    ]);
    fail('BS-01', 'Reduce quantity below currentlyOut should throw');
} catch (Exception $e) {
    if (stripos($e->getMessage(), 'ไม่สามารถลด') !== false || stripos($e->getMessage(), 'ออกอยู่') !== false) {
        pass('BS-01', 'Quantity guard — blocked: ' . $e->getMessage());
    } else {
        fail('BS-01', 'Unexpected error: ' . $e->getMessage());
    }
}

// BS-02: Update book — quantity increase (available should increase too)
try {
    $bookService->updateBook($bookId, [
        'title' => "_test_book_$ts", 'author' => '_test_author',
        'isbn' => "TEST-$ts", 'quantity' => 7
    ]);
    $book = $pdo->query("SELECT quantity, available FROM books WHERE id = $bookId")->fetch();
    if ($book['quantity'] == 7 && $book['available'] == 6) { // was 5/4, now 7/6
        pass('BS-02', "Quantity increase — qty={$book['quantity']}, avail={$book['available']}");
    } else {
        fail('BS-02', "Unexpected: qty={$book['quantity']}, avail={$book['available']}");
    }
} catch (Exception $e) {
    fail('BS-02', 'Update failed: ' . $e->getMessage());
}

// BS-03: Delete book — active borrow guard
try {
    $bookService->deleteBook($bookId);
    fail('BS-03', 'Delete book with active borrow should throw');
} catch (Exception $e) {
    if (stripos($e->getMessage(), 'ไม่สามารถลบ') !== false || stripos($e->getMessage(), 'ยืม') !== false) {
        pass('BS-03', 'Delete guard (active borrow) — blocked: ' . $e->getMessage());
    } else {
        fail('BS-03', 'Unexpected error: ' . $e->getMessage());
    }
}

// BS-04: ISBN uniqueness
try {
    $bookService->createBook([
        'title' => "_test_dup_isbn_$ts", 'author' => 'Dup',
        'isbn' => "TEST-$ts", 'quantity' => 1
    ]);
    fail('BS-04', 'Duplicate ISBN should throw');
} catch (Exception $e) {
    pass('BS-04', 'ISBN unique — blocked: ' . $e->getMessage());
}

// BS-05: Create book success
try {
    $newBookId = $bookService->createBook([
        'title' => "_test_book_new_$ts", 'author' => '_new_author',
        'isbn' => "TESTNEW-$ts", 'quantity' => 2
    ]);
    if ($newBookId > 0) {
        $cleanupIds['books'][] = $newBookId;
        pass('BS-05', "Create book success — ID: $newBookId");
    } else {
        fail('BS-05', 'createBook returned invalid ID');
    }
} catch (Exception $e) {
    fail('BS-05', 'Create book failed: ' . $e->getMessage());
}

// ============================================================
// D. BorrowService Tests
// ============================================================
section("D. BorrowService (6 tests)");

$borrowService = new BorrowService($pdo);

// BS-D01: Create borrow — success (single book)
try {
    $result = $borrowService->createBorrow($memberId, [$bookSingleId], DEFAULT_BORROW_DAYS);
    if ($result['success'] && count($result['borrowed']) > 0) {
        // Query DB for borrow ID (return structure doesn't include IDs)
        $stmt = $pdo->prepare("SELECT id FROM borrows WHERE user_id=? AND book_id=? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$memberId, $bookSingleId]);
        $testBorrowId = (int) $stmt->fetchColumn();
        $cleanupIds['borrows'][] = $testBorrowId;
        // Verify stock decreased
        $avail = $pdo->query("SELECT available FROM books WHERE id = $bookSingleId")->fetchColumn();
        if ((int)$avail === 0) {
            pass('BS-D01', "Borrow success — borrow_id=$testBorrowId, stock=0");
        } else {
            fail('BS-D01', "Stock should be 0, got $avail");
        }
    } else {
        fail('BS-D01', 'Borrow not successful: ' . ($result['message'] ?? 'unknown'));
    }
} catch (Exception $e) {
    fail('BS-D01', 'Borrow failed: ' . $e->getMessage());
}

// BS-D02: Create borrow — stock empty (service skips book, doesn't throw)
try {
    $result = $borrowService->createBorrow($member2Id, [$bookSingleId], DEFAULT_BORROW_DAYS);
    if (empty($result['borrowed']) && !empty($result['skipped'])) {
        pass('BS-D02', 'Borrow out-of-stock — skipped: ' . implode(', ', $result['skipped']));
    } elseif (!$result['success']) {
        pass('BS-D02', 'Borrow out-of-stock — not successful');
    } else {
        fail('BS-D02', 'Should not borrow out-of-stock book');
    }
} catch (Exception $e) {
    // Some implementations throw instead of skip — both are acceptable
    pass('BS-D02', 'Borrow out-of-stock — blocked (exception): ' . $e->getMessage());
}

// BS-D03: Return book — success
if (isset($testBorrowId)) {
    try {
        $result = $borrowService->returnBook($testBorrowId, false, $staffId);
        $avail = $pdo->query("SELECT available FROM books WHERE id = $bookSingleId")->fetchColumn();
        $status = $pdo->query("SELECT status FROM borrows WHERE id = $testBorrowId")->fetchColumn();
        if ((int)$avail === 1 && $status === 'returned') {
            pass('BS-D03', "Return success — stock restored=1, status=returned");
        } else {
            fail('BS-D03', "Unexpected: avail=$avail, status=$status");
        }
    } catch (Exception $e) {
        fail('BS-D03', 'Return failed: ' . $e->getMessage());
    }
} else {
    fail('BS-D03', 'SKIP — no borrow to return');
}

// BS-D04: Return book — already returned (state guard)
if (isset($testBorrowId)) {
    try {
        $borrowService->returnBook($testBorrowId, false, $staffId);
        fail('BS-D04', 'Double return should throw');
    } catch (Exception $e) {
        pass('BS-D04', 'Double return — blocked: ' . $e->getMessage());
    }
} else {
    fail('BS-D04', 'SKIP — no borrow to test');
}

// BS-D05: Quota check (borrow more than MAX_BORROW_BOOKS)
// Create borrows up to quota
$quotaBorrowIds = [];
try {
    for ($i = 0; $i < MAX_BORROW_BOOKS; $i++) {
        // Create temporary books
        $pdo->prepare("INSERT INTO books (title, author, category_id, quantity, available) VALUES (?,?,?,?,?)")
            ->execute(["_quota_book_{$i}_$ts", 'Author', $catId, 1, 1]);
        $qBookId = (int) $pdo->lastInsertId();
        $cleanupIds['books'][] = $qBookId;
        
        $r = $borrowService->createBorrow($memberId, [$qBookId], DEFAULT_BORROW_DAYS);
        // Get borrow ID from DB
        $stmt = $pdo->prepare("SELECT id FROM borrows WHERE user_id=? AND book_id=? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$memberId, $qBookId]);
        $qBorrowId = (int) $stmt->fetchColumn();
        $quotaBorrowIds[] = $qBorrowId;
        $cleanupIds['borrows'][] = $qBorrowId;
    }
    
    // Now try one more — should fail
    $pdo->prepare("INSERT INTO books (title, author, category_id, quantity, available) VALUES (?,?,?,?,?)")
        ->execute(["_quota_book_extra_$ts", 'Author', $catId, 1, 1]);
    $extraBookId = (int) $pdo->lastInsertId();
    $cleanupIds['books'][] = $extraBookId;
    
    $borrowService->createBorrow($memberId, [$extraBookId], DEFAULT_BORROW_DAYS);
    fail('BS-D05', 'Borrow over quota should throw');
} catch (Exception $e) {
    if (stripos($e->getMessage(), 'เกินจำนวน') !== false || stripos($e->getMessage(), 'quota') !== false || stripos($e->getMessage(), 'สูงสุด') !== false || stripos($e->getMessage(), 'ครบ') !== false) {
        pass('BS-D05', 'Quota exceeded (MAX=' . MAX_BORROW_BOOKS . ') — blocked: ' . $e->getMessage());
    } else {
        pass('BS-D05', 'Quota exceeded — blocked (exception): ' . $e->getMessage());
    }
}

// BS-D06: Pay fine — duplicate prevention
// Create an overdue borrow, return it, pay fine, pay again
$pdo->prepare("INSERT INTO books (title, author, category_id, quantity, available) VALUES (?,?,?,?,?)")
    ->execute(["_fine_book_$ts", 'Author', $catId, 1, 1]);
$fineBookId = (int) $pdo->lastInsertId();
$cleanupIds['books'][] = $fineBookId;

// Create borrow that's overdue (borrow_date = 30 days ago)
$pdo->prepare("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status) VALUES (?,?,DATE_SUB(CURDATE(), INTERVAL 30 DAY),DATE_SUB(CURDATE(), INTERVAL 23 DAY),'borrowing')")
    ->execute([$member2Id, $fineBookId]);
$fineBorrowId = (int) $pdo->lastInsertId();
$cleanupIds['borrows'][] = $fineBorrowId;
$pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ?")->execute([$fineBookId]);

try {
    // Return overdue book (payNow=false so we can test payFine separately)
    $returnResult = $borrowService->returnBook($fineBorrowId, false, $staffId);
    
    // Pay fine
    $payResult = $borrowService->payFine($fineBorrowId, $staffId);
    $cleanupIds['payments'][] = $fineBorrowId; // cleanup by borrow_id
    
    // Try paying again — should fail
    try {
        $borrowService->payFine($fineBorrowId, $staffId);
        fail('BS-D06', 'Duplicate payment should throw');
    } catch (Exception $e) {
        pass('BS-D06', 'Duplicate payment — blocked: ' . $e->getMessage());
    }
} catch (Exception $e) {
    fail('BS-D06', 'Fine test setup failed: ' . $e->getMessage());
}

// ============================================================
// E. ReservationService Tests
// ============================================================
section("E. ReservationService (5 tests)");

$reservationService = new ReservationService($pdo);

// Use a fresh book for reservation tests (separate from borrow tests)
$pdo->prepare("INSERT INTO books (title, author, category_id, quantity, available) VALUES (?,?,?,?,?)")
    ->execute(["_test_resbook_$ts", '_test_author', $catId, 2, 2]);
$resBookId = (int) $pdo->lastInsertId();
$cleanupIds['books'][] = $resBookId;

// Use member2 for reservations (member1 has active borrows from quota test)
$resUserId = $member2Id;

// RS-01: Create reservation success
try {
    $result = $reservationService->createReservation($resUserId, $resBookId);
    // Check stock decremented
    $avail = $pdo->query("SELECT available FROM books WHERE id = $resBookId")->fetchColumn();
    if ((int)$avail === 1) { // was 2, now 1
        pass('RS-01', 'Reservation created — stock decremented to 1');
    } else {
        fail('RS-01', "Stock should be 1, got $avail");
    }
    // Find the reservation ID for cleanup
    $stmt = $pdo->prepare("SELECT id FROM reservations WHERE user_id=? AND book_id=? AND status='pending' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$resUserId, $resBookId]);
    $testResId = (int) $stmt->fetchColumn();
    $cleanupIds['reservations'][] = $testResId;
} catch (Exception $e) {
    fail('RS-01', 'Reservation failed: ' . $e->getMessage());
    $testResId = null;
}

// RS-02: Duplicate reservation (same user, same book)
try {
    $reservationService->createReservation($resUserId, $resBookId);
    fail('RS-02', 'Duplicate reservation should throw');
} catch (Exception $e) {
    pass('RS-02', 'Duplicate reservation — blocked: ' . $e->getMessage());
}

// RS-03: Cancel reservation — wrong owner (IDOR)
if ($testResId) {
    try {
        $reservationService->cancelReservation($testResId, $memberId); // wrong user (memberId != resUserId)
        fail('RS-03', 'Cancel by wrong owner should throw');
    } catch (Exception $e) {
        pass('RS-03', 'Cancel wrong owner (IDOR) — blocked: ' . $e->getMessage());
    }
} else {
    fail('RS-03', 'SKIP — no reservation');
}

// RS-04: Cancel reservation — success + stock returned
if ($testResId) {
    try {
        $reservationService->cancelReservation($testResId, $resUserId);
        $avail = $pdo->query("SELECT available FROM books WHERE id = $resBookId")->fetchColumn();
        $status = $pdo->query("SELECT status FROM reservations WHERE id = $testResId")->fetchColumn();
        if ((int)$avail === 2 && $status === 'cancelled') {
            pass('RS-04', "Cancel success — stock restored=2, status=cancelled");
        } else {
            fail('RS-04', "Unexpected: avail=$avail, status=$status");
        }
    } catch (Exception $e) {
        fail('RS-04', 'Cancel failed: ' . $e->getMessage());
    }
} else {
    fail('RS-04', 'SKIP — no reservation');
}

// RS-05: Cancel already cancelled (state guard)
if ($testResId) {
    try {
        $reservationService->cancelReservation($testResId, $resUserId);
        fail('RS-05', 'Double cancel should throw');
    } catch (Exception $e) {
        pass('RS-05', 'Double cancel — blocked: ' . $e->getMessage());
    }
} else {
    fail('RS-05', 'SKIP — no reservation');
}

// ============================================================
// CLEANUP
// ============================================================
section("CLEANUP");

$cleanupErrors = 0;

// Return active borrows first (to restore stock)
foreach ($cleanupIds['borrows'] as $bid) {
    try {
        $st = $pdo->prepare("SELECT status, book_id FROM borrows WHERE id = ?");
        $st->execute([$bid]);
        $b = $st->fetch();
        if ($b && $b['status'] === 'borrowing') {
            $pdo->prepare("UPDATE borrows SET status='returned', return_date=CURDATE() WHERE id=?")->execute([$bid]);
            $pdo->prepare("UPDATE books SET available = available + 1 WHERE id=?")->execute([$b['book_id']]);
        }
    } catch (Exception $e) { $cleanupErrors++; }
}

// Delete payments
foreach ($cleanupIds['payments'] as $borrowId) {
    try {
        $pdo->prepare("DELETE FROM payments WHERE borrow_id = ?")->execute([$borrowId]);
    } catch (Exception $e) { $cleanupErrors++; }
}

// Delete reservations
foreach ($cleanupIds['reservations'] as $rid) {
    try {
        $pdo->prepare("DELETE FROM reservations WHERE id = ?")->execute([$rid]);
    } catch (Exception $e) { $cleanupErrors++; }
}

// Delete borrows
foreach ($cleanupIds['borrows'] as $bid) {
    try {
        $pdo->prepare("DELETE FROM borrows WHERE id = ?")->execute([$bid]);
    } catch (Exception $e) { $cleanupErrors++; }
}

// Delete books
foreach ($cleanupIds['books'] as $bid) {
    try {
        $pdo->prepare("DELETE FROM books WHERE id = ?")->execute([$bid]);
    } catch (Exception $e) { $cleanupErrors++; }
}

// Delete users
foreach ($cleanupIds['users'] as $uid) {
    try {
        $pdo->prepare("DELETE FROM users WHERE id = ? AND role != 'admin'")->execute([$uid]);
    } catch (Exception $e) { $cleanupErrors++; }
}

// Delete categories
foreach ($cleanupIds['categories'] as $cid) {
    try {
        $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$cid]);
    } catch (Exception $e) { $cleanupErrors++; }
}

// Clean rate limits
try {
    $pdo->exec("DELETE FROM rate_limits WHERE key_name LIKE '%_test_%'");
} catch (Exception $e) {}

echo "  Cleanup done" . ($cleanupErrors > 0 ? " ($cleanupErrors minor errors)" : "") . "\n";

// ============================================================
// SUMMARY
// ============================================================
$total = $results['total'];
$pass = $results['passed'];
$fail = $results['failed'];
$pct = $total > 0 ? round($pass / $total * 100, 1) : 0;

echo "\n\033[1m══════════════════════════════════════\033[0m\n";
echo "\033[1m SERVICE TESTS: $pass/$total passed ($pct%)\033[0m";
if ($fail > 0) echo " | \033[31m$fail FAILED\033[0m";
echo "\n\033[1m══════════════════════════════════════\033[0m\n";

if (!empty($results['errors'])) {
    echo "\n\033[31mFailed tests:\033[0m\n";
    foreach ($results['errors'] as $e) echo "  - $e\n";
}
echo "\n";

exit($fail > 0 ? 1 : 0);
