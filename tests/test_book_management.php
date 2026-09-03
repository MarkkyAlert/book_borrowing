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

/**
 * 🧹 เก็บกวาดแบบรับประกัน — ทำงานแม้เทสต์ตายกลางคัน
 *
 * 🧠 ทำไมต้อง register_shutdown_function ไม่ใช่เขียนไว้ท้ายไฟล์เฉย ๆ:
 *    ถ้าเคสไหนโยน exception ที่ไม่ถูกจับ หรือเกิด fatal error โค้ดท้ายไฟล์
 *    จะไม่ถูกรันเลย → เหลือหนังสือ/สมาชิกทดสอบค้างในระบบ
 *    เจอมาแล้วจริงตอนทดสอบยาม BK-RT3 ว่ามันแดงได้ไหม — เทสต์ตายกลางคัน
 *    3 รอบ ทิ้งหนังสือค้างไว้ 9 เล่ม (แบบเดียวกับ F-52 และ test_reference_books.php)
 *
 * ⚠️ ลบตามชื่อ ไม่ใช่ตาม id ที่จำไว้ จะได้เก็บของค้างจากรอบก่อน ๆ ไปด้วย
 */
$cleanupBk = function () use ($pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    // ลำดับ: ลูกก่อน (borrows, reservations) แล้วค่อยพ่อแม่ (books, users, categories)
    $pdo->exec("DELETE FROM borrows WHERE book_id IN (SELECT id FROM books WHERE title LIKE 'Test Book%')");
    $pdo->exec("DELETE FROM reservations WHERE book_id IN (SELECT id FROM books WHERE title LIKE 'Test Book%')");
    $pdo->exec("DELETE FROM books WHERE title LIKE 'Test Book%'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'tester_bk_%'");
    $pdo->exec("DELETE FROM categories WHERE name LIKE 'Test Cat%'");
};
register_shutdown_function($cleanupBk);
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
// 🛡️ ยามกันบั๊ก ก.6 ซ้ำรอย — "ฟิลด์หายเงียบตอนแก้ไข"
// ============================================================
// 🧠 ที่มา: BookService::updateBook() เคยประกอบ array ส่ง Repository ด้วยการ
//    ไล่พิมพ์คีย์เองทีละตัว คีย์ที่ลืมพิมพ์จะตกหล่นเงียบ ๆ แล้วไปโดน `?? null` /
//    `?? 0` ใน Repository ทับค่าเดิมในฐานข้อมูล = ข้อมูลผู้ใช้หายโดยไม่มี error
//    เกิดมาแล้ว 2 รอบ (call_number+copy_notes / price+is_reference)
//    2 เคสข้างล่างนี้จับ "ประเภท" ของบั๊ก ไม่ใช่จับแค่ฟิลด์ที่เคยพัง

echo "\n── ยามกันฟิลด์หายตอนแก้ไข (BK-RT) ──\n";

// ── BK-RT1: รายชื่อคอลัมน์ที่ประกาศไว้ ต้องตรงกับตาราง books จริง ──
//    ถ้ามีใครเพิ่มคอลัมน์ใหม่ในตารางแล้วลืมใส่ EDITABLE_COLUMNS → เคสนี้แดงทันที
//    (ไม่ต้องรอให้ผู้ใช้มาเจอว่าข้อมูลที่กรอกหายไปเอง)
$systemCols = ['id', 'created_at', 'updated_at', 'search_tokens'];
$dbCols = $pdo->query("SHOW COLUMNS FROM books")->fetchAll(PDO::FETCH_COLUMN);
$expectedCols = array_values(array_diff($dbCols, $systemCols));
$declaredCols = \App\Repositories\BookRepository::EDITABLE_COLUMNS;
$missingCols = array_values(array_diff($expectedCols, $declaredCols));
$extraCols   = array_values(array_diff($declaredCols, $expectedCols));
assertTest(
    "BK-RT1: EDITABLE_COLUMNS ตรงกับคอลัมน์จริงในตาราง books",
    !$missingCols && !$extraCols,
    $missingCols || $extraCols
        ? 'ขาด: [' . implode(', ', $missingCols) . '] · เกิน: [' . implode(', ', $extraCols) . ']'
          . ' → แก้ที่ BookRepository::EDITABLE_COLUMNS และ SQL ใน update()'
        : count($declaredCols) . ' คอลัมน์ตรงกันครบ'
);

// ── BK-RT2: ทุกคอลัมน์ที่แก้ได้ ต้องรอดจากการ updateBook() จริง ──
//    ส่งค่าเข้าไปทีเดียวทุกฟิลด์ แล้วอ่านกลับมาเทียบทีละตัว
//    ฟิลด์ไหนหายระหว่างทาง เคสนี้จะบอกชื่อฟิลด์นั้นออกมาตรง ๆ
$rtTitle = 'Test Book RT ' . time();
$rtBookId = $bookService->createBook([
    'title' => $rtTitle, 'author' => 'RT Author', 'quantity' => 10, 'category_id' => $catId,
]);

// ค่าทดสอบของแต่ละคอลัมน์ — ต้องต่างจากค่าตอนสร้าง จะได้รู้ว่า "เขียนทับสำเร็จ" จริง
$rtProbe = [
    'title'        => $rtTitle,
    'author'       => 'RT Author Edited',
    'isbn'         => '978-RT-' . time(),
    'call_number'  => 'อ 123 ท',
    'category_id'  => $catId,
    'description'  => 'RT description edited',
    'copy_notes'   => 'RT copy notes',
    'cover_image'  => 'rt_cover.jpg',
    'price'        => 199.50,
    'quantity'     => 7,
    'is_visible'   => 0,
    'is_reference' => 1,
];
// 🧠 available ไม่อยู่ในรายการทดสอบ เพราะ Service ตั้งใจคำนวณเองจาก quantity diff
//    ห้ามรับค่าดิบจากฟอร์ม (ไม่งั้นแก้ไขหนังสือจะปลอม stock ได้)
$rtComputed = ['available'];

$noProbe = array_values(array_diff($declaredCols, array_keys($rtProbe), $rtComputed));
assertTest(
    "BK-RT2a: ทุกคอลัมน์ที่แก้ได้มีค่าทดสอบครบ",
    !$noProbe,
    $noProbe
        ? 'ยังไม่มีค่าทดสอบให้: [' . implode(', ', $noProbe) . '] → เพิ่มใน $rtProbe ของไฟล์นี้'
        : count($rtProbe) . ' คอลัมน์มีค่าทดสอบครบ'
);

$bookService->updateBook($rtBookId, $rtProbe);
$rtAfter = $pdo->query("SELECT * FROM books WHERE id = $rtBookId")->fetch(PDO::FETCH_ASSOC);

$rtLost = [];
foreach ($rtProbe as $col => $want) {
    $got = $rtAfter[$col];
    $same = (is_numeric($want) && is_numeric($got))
        ? abs((float) $want - (float) $got) < 0.001
        : (string) $want === (string) $got;
    if (!$same) $rtLost[] = "$col (ส่ง " . var_export($want, true) . " ได้ " . var_export($got, true) . ")";
}
assertTest(
    "BK-RT2b: ทุกฟิลด์ที่ส่งเข้า updateBook() รอดออกมาครบ",
    !$rtLost,
    $rtLost
        ? count($rtLost) . ' ฟิลด์หายระหว่างทาง: ' . implode(' · ', $rtLost)
          . ' → เช็คว่า BookService::updateBook() ส่งต่อครบตาม EDITABLE_COLUMNS ไหม'
        : count($rtProbe) . ' ฟิลด์รอดครบ'
);

// ── BK-RT3: available ต้องมาจากการคำนวณ ไม่ใช่ค่าที่ส่งมา ──
//    กันการ "แก้" บั๊กข้างบนด้วยการส่ง $data ทั้งก้อนเข้า Repository
//    ซึ่งจะเปิดช่องให้ปลอม stock ผ่านหน้าแก้ไขหนังสือได้
//
// 🧠 ทำไมส่ง available = 1 ไม่ใช่ 999:
//    999 จะไปชน CHECK constraint chk_books_quantity_gte_available แล้วโยน exception
//    = เทสต์ผ่านเพราะ "ฐานข้อมูลช่วยไว้" ไม่ใช่เพราะ Service ทำถูก (ลองแล้วเจอจริง)
//    ส่ง 1 แทน — ต่ำกว่า quantity จึงไม่มี constraint ไหนกัน ถ้า Service รับค่าดิบ
//    stock จะเพี้ยนเงียบ ๆ (หนังสือ 7 เล่มกลายเป็นว่างเล่มเดียว) ซึ่งคือของจริงที่ต้องจับ
try {
    $bookService->updateBook($rtBookId, $rtProbe + ['available' => 1]);
    $rtStock = $pdo->query("SELECT quantity, available FROM books WHERE id = $rtBookId")->fetch(PDO::FETCH_ASSOC);
    $rtStockOk = (int) $rtStock['available'] === 7 && (int) $rtStock['quantity'] === 7;
    $rtStockMsg = "quantity={$rtStock['quantity']} available={$rtStock['available']} (ต้องเป็น 7/7)";
} catch (\Throwable $e) {
    // จับไว้เอง ไม่ปล่อยให้ fatal — ไม่งั้นเคสที่เหลือกับ CLEANUP จะไม่ได้รัน
    $rtStockOk = false;
    $rtStockMsg = 'updateBook() โยน exception: ' . $e->getMessage();
}
assertTest("BK-RT3: available ยังคำนวณเอง ไม่รับค่าดิบจากฟอร์ม", $rtStockOk, $rtStockMsg);

// ── BK-P1: ราคาปกว่าง ต้องเก็บเป็น NULL ไม่ใช่ 0 ──
//    🧠 NULL = "ยังไม่ได้ระบุราคา" · 0 = "หนังสือฟรี" คนละความหมายกัน
//    ถ้าเก็บเป็น 0 ตอนแจ้งหนังสือหายจะคิดค่าปรับเป็น 0 บาทโดยไม่มีใครทัก
$bookService->updateBook($rtBookId, ['title' => $rtTitle, 'author' => 'RT Author Edited',
    'quantity' => 7, 'is_visible' => 0, 'price' => null]);
$rtPrice = $pdo->query("SELECT price FROM books WHERE id = $rtBookId")->fetchColumn();
assertTest(
    "BK-P1: ราคาปกว่าง → NULL (ไม่ใช่ 0)",
    $rtPrice === null,
    'price = ' . var_export($rtPrice, true)
);

// ============================================================
echo "\n── CLEANUP ──\n";
// ============================================================
$cleanupBk();
echo "  Cleanup done\n";
echo "\n════════════════════════════════════════\n";
echo " RESULTS: $passed/$total passed";
if ($failed > 0) echo " | $failed FAILED";
echo "\n════════════════════════════════════════\n\n";

exit($failed > 0 ? 1 : 0);
