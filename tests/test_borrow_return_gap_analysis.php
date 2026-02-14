<?php

/**
 * Borrow/Return Gap Analysis Test
 * 
 * ทดสอบกรณีที่ logical_consistency_test.php ไม่ได้ cover:
 * 1. Atomic Failure (ยืมหลายเล่ม ถ้าเล่มใดมีปัญหา rollback ทั้งหมด)
 * 2. Overdue Fine Calculation (วันต่างๆ)
 * 3. Invalid Inputs (user/book ไม่มีอยู่)
 * 4. Return on Due Date (ค่าปรับ = 0)
 * 5. Pay Later Flow
 */
require_once __DIR__ . '/../bootstrap.php';

use App\Services\BorrowService;

$pdo = getDB();
$borrowService = new BorrowService($pdo);

$passed = 0;
$failed = 0;

function assertTest($name, $condition, $msg = "")
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ✅ PASS: $name\n";
    } else {
        $failed++;
        echo "  ❌ FAIL: $name ($msg)\n";
    }
}

echo "🧪 Borrow/Return Gap Analysis Test\n";
echo str_repeat("=", 50) . "\n";

// ===== Pre-cleanup =====
$pdo->exec("DELETE FROM payments WHERE borrow_id IN (SELECT id FROM borrows WHERE user_id IN (900, 901))");
$pdo->exec("DELETE FROM reservations WHERE user_id IN (900, 901)");
$pdo->exec("DELETE FROM borrows WHERE user_id IN (900, 901)");
$pdo->exec("DELETE FROM books WHERE id IN (900, 901, 902)");
$pdo->exec("DELETE FROM users WHERE id IN (900, 901)");

// ===== Setup =====
$pdo->exec("INSERT INTO users (id, name, email, password, role) VALUES 
    (900, 'Gap Member A', 'gap_a@test.com', 'pass', 'member'),
    (901, 'Gap Member B', 'gap_b@test.com', 'pass', 'member')");
$pdo->exec("INSERT INTO books (id, title, author, quantity, available) VALUES 
    (900, 'Gap Book OK', 'Author', 5, 5),
    (901, 'Gap Book Empty', 'Author', 3, 0),
    (902, 'Gap Book One', 'Author', 1, 1)");

// ===== 1. ATOMIC FAILURE =====
echo "\n1. Atomic Failure (ยืมหลายเล่ม เล่มหนึ่ง available=0)\n";
$beforeOK = (int)$pdo->query("SELECT available FROM books WHERE id = 900")->fetchColumn();
$beforeEmpty = (int)$pdo->query("SELECT available FROM books WHERE id = 901")->fetchColumn();

$atomicFailed = false;
try {
    $borrowService->createBorrow(900, [900, 901], 7); // Book 901 has available=0
} catch (Exception $e) {
    $atomicFailed = true;
}

$afterOK = (int)$pdo->query("SELECT available FROM books WHERE id = 900")->fetchColumn();
$afterEmpty = (int)$pdo->query("SELECT available FROM books WHERE id = 901")->fetchColumn();
$borrowCount = (int)$pdo->query("SELECT COUNT(*) FROM borrows WHERE user_id = 900 AND status = 'borrowing'")->fetchColumn();

assertTest(
    "Atomic Rollback",
    $atomicFailed && $afterOK == $beforeOK && $afterEmpty == $beforeEmpty && $borrowCount == 0,
    "failed=$atomicFailed, bookOK: $beforeOK→$afterOK, bookEmpty: $beforeEmpty→$afterEmpty, borrows=$borrowCount"
);

// ===== 2. INVALID USER =====
echo "\n2. Invalid User (user_id ไม่มีในระบบ)\n";
$invalidUserFailed = false;
try {
    $borrowService->createBorrow(99999, [900], 7);
} catch (Exception $e) {
    $invalidUserFailed = true;
}
assertTest("Invalid User Blocked", $invalidUserFailed, "Should throw exception");

// ===== 3. INVALID BOOK =====
echo "\n3. Invalid Book (book_id ไม่มีในระบบ)\n";
$invalidBookFailed = false;
try {
    $borrowService->createBorrow(900, [99999], 7);
} catch (Exception $e) {
    $invalidBookFailed = true;
}
assertTest("Invalid Book Blocked", $invalidBookFailed, "Should throw exception");

// ===== 4. OVERDUE FINE CALCULATION =====
echo "\n4. Overdue Fine Calculation (3 วัน overdue)\n";
// Insert past-due borrow directly
$pdo->exec("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status) VALUES 
    (900, 900, DATE_SUB(CURDATE(), INTERVAL 10 DAY), DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'borrowing')");
$borrowId = (int)$pdo->lastInsertId();
$pdo->exec("UPDATE books SET available = available - 1 WHERE id = 900");

$result = $borrowService->returnBook($borrowId, false);
$expectedFine = 3 * FINE_PER_DAY;

assertTest(
    "Fine = $expectedFine บาท",
    $result['fine']['amount'] == $expectedFine && $result['fine']['days'] == 3,
    "got fine={$result['fine']['amount']}, days={$result['fine']['days']}"
);
assertTest("Pay Later (paid=false)", !$result['paid'], "paid should be false");

// Check no payment record
$paymentExists = (bool)$pdo->query("SELECT id FROM payments WHERE borrow_id = $borrowId")->fetchColumn();
assertTest("No Payment Record", !$paymentExists, "Should not have payment yet");

// ===== 5. PAY LATER FLOW =====
echo "\n5. Pay Later → Pay Fine\n";
$payResult = $borrowService->payFine($borrowId, 1);
assertTest(
    "Pay Fine Success",
    $payResult['success'] && $payResult['amount'] == $expectedFine,
    "success={$payResult['success']}, amount={$payResult['amount']}"
);

// ===== 6. RETURN ON DUE DATE =====
echo "\n6. Return on Due Date (ค่าปรับ = 0)\n";
$pdo->exec("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status) VALUES 
    (901, 900, DATE_SUB(CURDATE(), INTERVAL 7 DAY), CURDATE(), 'borrowing')");
$borrowId2 = (int)$pdo->lastInsertId();
$pdo->exec("UPDATE books SET available = available - 1 WHERE id = 900");

$result2 = $borrowService->returnBook($borrowId2);
assertTest("Fine = 0 (on due date)", $result2['fine']['amount'] == 0, "got fine={$result2['fine']['amount']}");

// ===== 7. RETURN BEFORE DUE DATE =====
echo "\n7. Return Before Due Date (ค่าปรับ = 0)\n";
$pdo->exec("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status) VALUES 
    (901, 902, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'borrowing')");
$borrowId3 = (int)$pdo->lastInsertId();
$pdo->exec("UPDATE books SET available = available - 1 WHERE id = 902");

$result3 = $borrowService->returnBook($borrowId3);
assertTest("Fine = 0 (before due)", $result3['fine']['amount'] == 0, "got fine={$result3['fine']['amount']}");

// ===== 8. RETURN NON-BORROWING STATUS =====
echo "\n8. Return Non-Borrowing Status\n";
$returnedFailed = false;
try {
    $borrowService->returnBook($borrowId3); // Already returned above
} catch (Exception $e) {
    $returnedFailed = true;
}
assertTest("Block Double Return", $returnedFailed, "Should throw exception");

// ===== Cleanup =====
$pdo->exec("DELETE FROM payments WHERE borrow_id IN (SELECT id FROM borrows WHERE user_id IN (900, 901))");
$pdo->exec("DELETE FROM borrows WHERE user_id IN (900, 901)");
$pdo->exec("DELETE FROM books WHERE id IN (900, 901, 902)");
$pdo->exec("DELETE FROM users WHERE id IN (900, 901)");

// ===== Summary =====
echo "\n" . str_repeat("=", 50) . "\n";
echo "📊 Results: $passed passed, $failed failed\n";
echo ($failed === 0) ? "🎉 ALL TESTS PASSED!\n" : "⚠️ SOME TESTS FAILED\n";
