<?php
require_once __DIR__ . '/../bootstrap.php';

use App\Services\MemberService;
use App\Services\BookService;
use App\Services\BorrowService;

echo "════════════════════════════════════════\n";
echo " Section 12: Member Management\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "════════════════════════════════════════\n\n";

$pdo = getDB();
$memberService = new MemberService($pdo);
// Need Book/Borrow services for constraints
$bookService = new App\Services\BookService($pdo);
$borrowService = new App\Services\BorrowService($pdo);

$prefix = "TM_" . time(); // Test Member

// 1. Setup
echo "── SETUP ──\n";
$emailA = $prefix . "_A@test.com";
$emailB = $prefix . "_B@test.com";

// 2. Happy Path
echo "── HAPPY PATH ──\n";

// MM-01: Create Member
echo "MM-01: Create Member\n";
try {
    $rA = $memberService->createMember([
        'name' => 'Test Member A',
        'email' => $emailA,
        'phone' => '0800000000',
        'password' => 'password123'
    ]);
    $idA = $rA['id'];
    echo "  ✅ PASS: Created Member A (ID: $idA)\n";
} catch (Exception $e) {
    echo "  ❌ FAIL: Create A: " . $e->getMessage() . "\n";
    exit(1);
}

// MM-02: Auto-gen Password
echo "MM-02: Auto-gen Password\n";
try {
    $rB = $memberService->createMember([
        'name' => 'Test Member B',
        'email' => $emailB,
        'phone' => '0800000000'
        // No password
    ]);
    $idB = $rB['id'];
    if (!empty($rB['password'])) {
        echo "  ✅ PASS: Auto-generated password: " . $rB['password'] . "\n";
    } else {
        echo "  ❌ FAIL: Password not generated\n";
    }
} catch (Exception $e) {
    echo "  ❌ FAIL: Create B: " . $e->getMessage() . "\n";
}

// MM-03: Update Member & Role
echo "MM-03: Update Member & Role\n";
try {
    $memberService->updateMember($idA, [
        'name' => 'Test Member A Updated',
        'email' => $emailA,
        'role' => 'staff' // Change to staff
    ]);
    $uA = $memberService->getMemberById($idA);

    if ($uA['name'] === 'Test Member A Updated') echo "  ✅ PASS: Name updated\n";
    else echo "  ❌ FAIL: Name mismatch\n";

    if ($uA['role'] === 'staff') echo "  ✅ PASS: Role updated to Staff\n";
    else echo "  ❌ FAIL: Role mismatch ({$uA['role']})\n";
} catch (Exception $e) {
    echo "  ❌ FAIL: Update A: " . $e->getMessage() . "\n";
}

// 3. Logic & Constraints
echo "\n── LOGIC & CONSTRAINTS ──\n";

// MM-04: Duplicate Email (Create)
echo "MM-04: Duplicate Email (Create)\n";
try {
    $memberService->createMember([
        'name' => 'Duplicate',
        'email' => $emailA // Exists
    ]);
    echo "  ❌ FAIL: Allowed duplicate email create\n";
} catch (Exception $e) {
    echo "  ✅ PASS: Blocked duplicate email create (" . $e->getMessage() . ")\n";
}

// MM-05: Duplicate Email (Update)
echo "MM-05: Duplicate Email (Update)\n";
try {
    $memberService->updateMember($idB, [
        'name' => 'B trying to be A',
        'email' => $emailA // A's email
    ]);
    echo "  ❌ FAIL: Allowed duplicate email update\n";
} catch (Exception $e) {
    echo "  ✅ PASS: Blocked duplicate email update\n";
}

// MM-06: Delete Constraints
echo "MM-06: Delete Constraints\n";

// Create temp book and borrow it by Member A
$pdo->exec("INSERT INTO books (title, author, quantity, available, isbn, category_id) VALUES ('MemTestBook', 'Auth', 1, 1, '978-MEM-TEST', NULL)");
$bookId = $pdo->lastInsertId();

// Borrow
// Need to find an admin ID for `approveBorrow`. I'll just insert into borrows table directly to verify `MemberService::delete` constraint logic (borrowRepo->countByUser).
// BorrowRepo counts ANY row in `borrows` table for user_id.
// 🧠 ENUM ของคอลัมน์นี้คือ ('borrowing','returned') — ไม่มีคำว่า 'borrowed'
//    เดิมเขียน 'borrowed' ทำให้ MySQL โยน "Data truncated for column 'status'"
//    แล้วสคริปต์ตายเงียบ ๆ ตรงนี้ ไม่ได้ไปถึงส่วน CLEANUP → ทิ้งขยะไว้ทุกครั้งที่รัน
$pdo->prepare("INSERT INTO borrows (book_id, user_id, borrow_date, due_date, status) VALUES (?, ?, NOW(), NOW(), 'borrowing')")
    ->execute([$bookId, $idA]);

// Try Delete Member A (Has active borrow)
try {
    $memberService->deleteMember($idA);
    echo "  ❌ FAIL: Allowed delete member with active borrow\n";
} catch (Exception $e) {
    echo "  ✅ PASS: Blocked delete (Active Borrow): " . $e->getMessage() . "\n";
}

// Return the book (Make it 'returned' status) -> History
$pdo->prepare("UPDATE borrows SET status = 'returned', return_date = NOW() WHERE book_id = ? AND user_id = ?")
    ->execute([$bookId, $idA]);

// Try Delete Member A (Has history)
try {
    $memberService->deleteMember($idA);
    echo "  ❌ FAIL: Allowed delete member with history\n";
} catch (Exception $e) {
    echo "  ✅ PASS: Blocked delete (History): " . $e->getMessage() . "\n";
}

// Delete Member B (No history)
// MM-07: Happy Delete
echo "MM-07: Happy Delete\n";
try {
    if ($memberService->deleteMember($idB)) {
        echo "  ✅ PASS: Deleted Member B (No history)\n";
    } else {
        echo "  ❌ FAIL: Delete returned false\n";
    }
} catch (Exception $e) {
    echo "  ❌ FAIL: Delete exception: " . $e->getMessage() . "\n";
}


// 4. Cleanup
// 🧹 ต้องทำงานเสมอ แม้เทสต์ด้านบนจะพังกลางคัน — ไม่งั้นจะทิ้งหนังสือที่ไม่มี
//    search_tokens ไว้ แล้วชุด Search Index (SI-08) จะ fail ในรอบถัดไป
echo "\n── CLEANUP ──\n";
try {
    // 📌 ลบตามลำดับ FK: payments → reservations → borrows → books → users
    $pdo->exec("DELETE FROM payments WHERE borrow_id IN (SELECT id FROM borrows WHERE user_id = $idA OR book_id = $bookId)");
    $pdo->exec("DELETE FROM reservations WHERE user_id = $idA OR book_id = $bookId");
    $pdo->exec("DELETE FROM borrows WHERE user_id = $idA OR book_id = $bookId");
    $pdo->exec("DELETE FROM books WHERE id = $bookId OR isbn = '978-MEM-TEST'");
    $pdo->exec("DELETE FROM users WHERE email LIKE '$prefix%'");
    echo "  Cleanup done\n";
} catch (Throwable $e) {
    echo "  ⚠️ Cleanup ไม่ครบ: " . $e->getMessage() . "\n";
}
echo "\n════════════════════════════════════════\n";
