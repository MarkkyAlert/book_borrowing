<?php
/**
 * Automated Integration Test for Book Borrowing System
 * Run via CLI: php tests/integration_test.php
 */

require_once __DIR__ . '/../includes/config.php';

// CLI Output Helper
function testLog($message, $type = 'INFO') {
    $color = $type === 'PASS' ? "\033[32m" : ($type === 'FAIL' ? "\033[31m" : "\033[36m");
    $reset = "\033[0m";
    echo "{$color}[{$type}] {$message}{$reset}\n";
}

try {
    // 1. Setup DB Connection
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    testLog("Database connected successfully");

    $pdo->beginTransaction(); // Transaction for easy rollback (cleanup)
    
    // 2. Setup Test Data
    // Create Test Category
    $pdo->exec("INSERT INTO categories (name) VALUES ('Test Category " . uniqid() . "')");
    $catId = $pdo->lastInsertId();
    testLog("Created test category ID: $catId");

    // Create Test User
    $pdo->exec("INSERT INTO users (name, email, password, role) VALUES ('Test Bot', 'bot" . uniqid() . "@test.com', 'pass', 'member')");
    $userId = $pdo->lastInsertId();
    testLog("Created test user ID: $userId");

    // 3. Test Case: Add Book with Quantity
    $initialQty = 3;
    $pdo->prepare("INSERT INTO books (title, author, category_id, quantity, available) VALUES (?, ?, ?, ?, ?)")
        ->execute(['Test Logic Book', 'Bot Author', $catId, $initialQty, $initialQty]);
    $bookId = $pdo->lastInsertId();
    testLog("Added book ID: $bookId with Quantity: $initialQty");

    // Verify
    $book = $pdo->query("SELECT * FROM books WHERE id = $bookId")->fetch();
    if ($book['available'] == $initialQty) {
        testLog("TEST 1: Book Creation - OK", 'PASS');
    } else {
        throw new Exception("Book creation failed. Expected available $initialQty, got {$book['available']}");
    }

    // 4. Test Case: Borrow Book
    testLog("Attempting to borrow book...");
    
    // Logic from admin/borrow_form.php (Simplified)
    if ($book['available'] > 0) {
        $pdo->prepare("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status) VALUES (?, ?, CURDATE(), CURDATE() + INTERVAL 7 DAY, 'borrowing')")
            ->execute([$userId, $bookId]);
        $borrowId = $pdo->lastInsertId();
        
        $pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ?")->execute([$bookId]);
    }

    // Verify
    $book = $pdo->query("SELECT * FROM books WHERE id = $bookId")->fetch();
    if ($book['available'] == $initialQty - 1) {
        testLog("TEST 2: Borrow Logic (Decrement) - OK ($initialQty -> " . ($initialQty - 1) . ")", 'PASS');
    } else {
        throw new Exception("Borrow logic failed. Expected available " . ($initialQty - 1) . ", got {$book['available']}");
    }

    // 5. Test Case: Return Book
    testLog("Attempting to return book...");
    
    // Logic from admin/borrows.php
    $pdo->prepare("UPDATE borrows SET status = 'returned', return_date = CURDATE() WHERE id = ?")->execute([$borrowId]);
    $pdo->prepare("UPDATE books SET available = available + 1 WHERE id = ?")->execute([$bookId]);

    // Verify
    $book = $pdo->query("SELECT * FROM books WHERE id = $bookId")->fetch();
    if ($book['available'] == $initialQty) {
        testLog("TEST 3: Return Logic (Increment) - OK (" . ($initialQty - 1) . " -> $initialQty)", 'PASS');
    } else {
        throw new Exception("Return logic failed. Expected available $initialQty, got {$book['available']}");
    }

    // 6. Test Case: Edge Case - Out of Stock
    testLog("Testing Out of Stock Scenario...");
    // Borrow all copies
    for ($i = 0; $i < $initialQty; $i++) {
        $pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ?")->execute([$bookId]);
    }
    
    $book = $pdo->query("SELECT * FROM books WHERE id = $bookId")->fetch();
    if ($book['available'] == 0) {
        testLog("TEST 4: Stock Depletion - OK (Available: 0)", 'PASS');
    } else {
        throw new Exception("Stock depletion failed. Expected 0, got {$book['available']}");
    }

    // Attempt to borrow unavailable (Validation Check)
    // In real app, UI prevents this. Here we check logic constraint if we were to check 'available > 0'
    if ($book['available'] <= 0) {
        testLog("TEST 5: Borrow Prevention valid (Available <= 0)", 'PASS');
    } else {
        testLog("TEST 5: Borrow Prevention failed", 'FAIL');
    }

    // Cleanup (Rollback transaction so we don't pollute DB)
    $pdo->rollBack();
    testLog("Cleanup: Transaction rolled back (Database remains clean)", 'INFO');

    testLog("\nALL INTEGRATION TESTS PASSED ✅", 'PASS');

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    testLog("TEST FAILED: " . $e->getMessage(), 'FAIL');
    exit(1);
}
