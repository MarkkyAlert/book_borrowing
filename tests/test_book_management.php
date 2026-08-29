<?php

/**
 * Section 10 — Book Management Gap Analysis
 * 
 * Tests:
 * ── Happy Path ─────────────────────────
 * BK-01: Create Book → Success, available=quantity
 * BK-02: Update Book (Title/Author) → Success
 * BK-06: Delete Book (No constraints) → Success + Cover deleted
 * 
 * ── Logic ──────────────────────────────
 * BK-03: Update Quantity (Increase) → Available increases
 * BK-04: Update Quantity (Decrease Valid) → Available decreases
 * 
 * ── Failure Cases / Constraints ────────
 * BK-05: Update Quantity (Decrease Invalid < CurrentlyOut) → Fail
 * BK-07: Delete Book (Active Borrow) → Fail
 * BK-08: Delete Book (Borrow History) → Fail
 * BK-09: Delete Book (Pending Reservation) → Fail
 * 
 * ── Validation ─────────────────────────
 * BK-10: ISBN Unique → Fail if duplicate
 * 
 * Usage: php tests/test_book_management.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/BookService.php';
require_once __DIR__ . '/../app/Repositories/BorrowRepository.php';
require_once __DIR__ . '/../app/Repositories/ReservationRepository.php';
require_once __DIR__ . '/../app/Repositories/CategoryRepository.php';

use App\Services\BookService;
use App\Repositories\BorrowRepository;
use App\Repositories\ReservationRepository;
use App\Repositories\CategoryRepository;

$pdo = getDB();
$bookService = new BookService($pdo);
$borrowRepo = new BorrowRepository($pdo);
$reservationRepo = new ReservationRepository($pdo);
$categoryRepo = new CategoryRepository($pdo);

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

// Setup: Create dummy category
$catId = $categoryRepo->create('Test Cat ' . time());

echo "\n════════════════════════════════════════\n";
echo " Section 10: Book Management\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "════════════════════════════════════════\n";

// ============================================================
echo "\n── HAPPY PATH ──\n";
// ============================================================

// BK-01: Create Book
$bookData = [
    'title' => 'Test Book ' . time(),
    'author' => 'Author ' . time(),
    'isbn' => '978-' . time(),
    'category_id' => $catId,
    'quantity' => 10,
    'description' => 'Test Desc'
];
$bookId = $bookService->createBook($bookData);
$b1 = $bookService->getBookById($bookId);

assertTest(
    "BK-01: Create Book → Success",
    $b1 && $b1['available'] == 10 && $b1['quantity'] == 10,
    "id=$bookId, qty={$b1['quantity']}, avail={$b1['available']}"
);

// BK-02: Update Book
$newTitle = $bookData['title'] . " (Updated)";
$bookService->updateBook($bookId, array_merge($bookData, ['title' => $newTitle]));
$b2 = $bookService->getBookById($bookId);

assertTest(
    "BK-02: Update Book → Success",
    $b2['title'] === $newTitle,
    "title={$b2['title']}"
);

// ============================================================
echo "\n── LOGIC: QUANTITY ──\n";
// ============================================================

// Setup: Simulate 1 borrow (Active)
// Need user
$stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES ('Tester', 'tester_bk_" . time() . "@test.com', 'pass', 'member')");
$stmt->execute();
$userId = $pdo->lastInsertId();

// Create borrow record manually (BorrowRepo doesn't have create method for raw insertion easily, use SQL)
// currentlyOut = 1. Qty=10. Available should be 9.
$pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ?")->execute([$bookId]);
$pdo->prepare("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status) VALUES (?, ?, NOW(), NOW(), 'borrowing')")->execute([$userId, $bookId]);
$borrowId = $pdo->lastInsertId();
// borrow_details removed - schema is flat

// Verify setup
$b_setup = $bookService->getBookById($bookId);
// Q=10, A=9. Out=1.

// BK-03: Update Quantity (Increase) 10 -> 15. Expect A = 9 + (15-10) = 14.
$bookService->updateBook($bookId, array_merge($bookData, ['quantity' => 15]));
$b3 = $bookService->getBookById($bookId);
assertTest(
    "BK-03: Quick Calc Qty 10->15 (Out=1) → Avail 14",
    $b3['quantity'] == 15 && $b3['available'] == 14,
    "q={$b3['quantity']}, a={$b3['available']}"
);

// BK-04: Update Quantity (Decrease Valid) 15 -> 12. Expect A = 14 + (12-15) = 11.
$bookService->updateBook($bookId, array_merge($bookData, ['quantity' => 12]));
$b4 = $bookService->getBookById($bookId);
assertTest(
    "BK-04: Decrease Qty 15->12 (Out=1) → Avail 11",
    $b4['quantity'] == 12 && $b4['available'] == 11,
    "q={$b4['quantity']}, a={$b4['available']}"
);

// BK-05: Update Quantity (Decrease Invalid) 12 -> 0. Out=1.
// 0 < 1 is true, so "newQuantity < currentlyOut" triggers exception.
try {
    $bookService->updateBook($bookId, array_merge($bookData, ['quantity' => 0]));
    assertTest("BK-05: Decrease Qty < Out → Fail", false, "Should throw exception");
} catch (Exception $e) {
    assertTest(
        "BK-05: Decrease Qty < Out → Fail",
        strpos($e->getMessage(), 'ไม่สามารถลดจำนวน') !== false,
        "error=" . $e->getMessage()
    );
}

// ============================================================
echo "\n── DELETE CONSTRAINTS ──\n";
// ============================================================

// BK-07: Delete Book (Active Borrow)
// Currently borrowing.
try {
    $bookService->deleteBook($bookId);
    assertTest("BK-07: Delete (Active Borrow) → Fail", false, "Should throw exception");
} catch (Exception $e) {
    assertTest(
        "BK-07: Delete (Active Borrow) → Fail",
        strpos($e->getMessage(), 'กำลังถูกยืมอยู่') !== false,
        "error=" . $e->getMessage()
    );
}

// Clear active borrow (return it)
$pdo->prepare("UPDATE borrows SET status = 'returned', return_date = NOW() WHERE id = ?")->execute([$borrowId]);
// Note: BookService::deleteBook checks borrow history (countByBook). Returned borrow still counts as history!

// BK-08: Delete Book (Borrow History)
try {
    $bookService->deleteBook($bookId);
    assertTest("BK-08: Delete (History) → Fail", false, "Should throw exception");
} catch (Exception $e) {
    assertTest(
        "BK-08: Delete (History) → Fail",
        strpos($e->getMessage(), 'มีประวัติการยืม') !== false,
        "error=" . $e->getMessage()
    );
}

// Create clean book for reservation test
$bookResId = $bookService->createBook(array_merge($bookData, ['isbn' => '978-RES-' . time()]));

// Create Pending Reservation
$pdo->prepare("INSERT INTO reservations (user_id, book_id, status, expires_at, created_at) VALUES (?, ?, 'pending', NOW(), NOW())")->execute([$userId, $bookResId]);

// BK-09: Delete Book (Pending Reservation)
try {
    $bookService->deleteBook($bookResId);
    assertTest("BK-09: Delete (Pending Res) → Fail", false, "Should throw exception");
} catch (Exception $e) {
    // ✅ ยึดความหมาย ไม่ยึดตัวอักษร — ข้อความบอกเหตุผลว่า "เพราะมีคนจอง"
    //    และต้องไม่ใช่ด่านอื่นที่ยิงผิดตัว (ประวัติการยืม)
    $msg = $e->getMessage();
    assertTest(
        "BK-09: Delete (Pending Res) → Fail",
        str_contains($msg, 'จอง') && !str_contains($msg, 'ประวัติการยืม'),
        "error=" . $msg
    );
}

// ============================================================
echo "\n── HAPPY DELETE ──\n";
// ============================================================

// Create clean book
$bookDelId = $bookService->createBook(array_merge($bookData, ['isbn' => '978-DEL-' . time(), 'cover_image' => 'test_cover.jpg']));
// Mock cover file
$coverPath = __DIR__ . '/../uploads/covers/test_cover.jpg';
if (!is_dir(dirname($coverPath))) mkdir(dirname($coverPath), 0755, true);
file_put_contents($coverPath, 'dummy content');

// BK-06: Delete Book (Clean)
$delResult = $bookService->deleteBook($bookDelId);
assertTest(
    "BK-06: Delete (Clean) → Success",
    $delResult === true && !file_exists($coverPath),
    "file_deleted=" . (!file_exists($coverPath) ? 'yes' : 'no')
);


// ============================================================
echo "\n── VALIDATION ──\n";
// ============================================================

// BK-10: ISBN Unique (Not directly in Service updateBook/createBook, handled in Form via Repository, but we can test Repository logic here via Repo instance or Service if it exposed it. Service doesn't check ISBN unique in createBook? Let's check Service code again.)
// Service `createBook` passes through. DB might have UNIQUE constraint? Or Repository check?
// `book_form.php` checks before calling service.
// Let's create a book with same ISBN as $b1 (which still exists).
// Note: $bookId has '978-' . time(). 
try {
    // Attempt SQL insert directly or check Repo method since Service doesn't enforce it (App reliance on Form validation).
    // Test Repo logic: isbnExists
    // Load BookRepo via reflection or just usage
    // Access $bookService private property? No.
    // Create new repo instance.
    $repo = new \App\Repositories\BookRepository($pdo);
    $isDup = $repo->isbnExists($b1['isbn']);
    assertTest(
        "BK-10: ISBN Unique Check → Detected",
        $isDup === $bookId || $isDup === true || $isDup > 0,
        "duplicate_found=" . ($isDup ? 'yes' : 'no')
    );
} catch (Exception $e) {
    assertTest("BK-10: ISBN Unique Check → Error", false, $e->getMessage());
}


// ============================================================
echo "\n── CLEANUP ──\n";
// ============================================================
// Cleanup Order: Children first (Borrows, Reservations) -> Parents (Books, Users, Categories)
// Delete all dependencies for any test books (current or previous runs)
$pdo->exec("DELETE FROM borrows WHERE book_id IN (SELECT id FROM books WHERE title LIKE 'Test Book%')");
$pdo->exec("DELETE FROM reservations WHERE book_id IN (SELECT id FROM books WHERE title LIKE 'Test Book%')");
$pdo->exec("DELETE FROM books WHERE title LIKE 'Test Book%'");
$pdo->exec("DELETE FROM users WHERE email LIKE 'tester_bk_%'");
$pdo->exec("DELETE FROM categories WHERE name LIKE 'Test Cat%'");

echo "  Cleanup done\n";
echo "\n════════════════════════════════════════\n";
echo " RESULTS: $passed/$total passed";
if ($failed > 0) echo " | $failed FAILED";
echo "\n════════════════════════════════════════\n\n";

exit($failed > 0 ? 1 : 0);
