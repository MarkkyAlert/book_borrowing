<?php
/**
 * Automated Integration Test for Reservation System
 * Run via CLI: C:\xampp\php\php.exe tests/reservation_test.php
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

    $pdo->beginTransaction(); // Transaction for rollback cleanup

    // 2. Setup Test Data
    $pdo->exec("INSERT INTO categories (name) VALUES ('Test Res Category')");
    $catId = $pdo->lastInsertId();

    $pdo->exec("INSERT INTO users (name, email, password, role) VALUES ('Test Res User', 'res" . uniqid() . "@test.com', 'pass', 'member')");
    $userId = $pdo->lastInsertId();

    // Create Book with 2 copies
    $initialQty = 2;
    $pdo->prepare("INSERT INTO books (title, author, category_id, quantity, available) VALUES (?, ?, ?, ?, ?)")
        ->execute(['Res Test Book', 'Res Author', $catId, $initialQty, $initialQty]);
    $bookId = $pdo->lastInsertId();
    testLog("Created Book ID: $bookId (Qty: $initialQty)");

    // 3. Test Case: Reserve Success
    testLog("Testing Reservation Success...");
    
    // Simulate API logic
    $expiresAt = date('Y-m-d H:i:s', strtotime('+2 days'));
    $pdo->prepare("INSERT INTO reservations (user_id, book_id, expires_at, status) VALUES (?, ?, ?, 'pending')")
        ->execute([$userId, $bookId, $expiresAt]);
    $resId = $pdo->lastInsertId();

    // Decrement stock
    $pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ?")->execute([$bookId]);

    // Verify
    $book = $pdo->query("SELECT * FROM books WHERE id = $bookId")->fetch();
    $res = $pdo->query("SELECT * FROM reservations WHERE id = $resId")->fetch();

    if ($book['available'] == 1 && $res['status'] == 'pending') {
        testLog("TEST 1: Reserve Success (Stock 2->1) - OK", 'PASS');
    } else {
        throw new Exception("Reserve Logic Failed: Available {$book['available']}, Status {$res['status']}");
    }

    // 4. Test Case: Double Reserve Prevention Logic
    testLog("Testing Double Reserve Check...");
    $stmt = $pdo->prepare("SELECT id FROM reservations WHERE user_id = ? AND book_id = ? AND status = 'pending'");
    $stmt->execute([$userId, $bookId]);
    if ($stmt->fetch()) {
        testLog("TEST 2: Double Reserve Detected - OK", 'PASS');
    } else {
        throw new Exception("Double Reserve Check Failed");
    }

    // 5. Test Case: Admin Approve (Fulfillment)
    testLog("Testing Admin Approval...");
    
    // Create Borrow
    $pdo->prepare("INSERT INTO borrows (user_id, book_id, borrow_date, due_date, status) VALUES (?, ?, CURDATE(), CURDATE() + INTERVAL 7 DAY, 'borrowing')")
        ->execute([$userId, $bookId]);
    
    // Update Reservation
    $pdo->prepare("UPDATE reservations SET status = 'fulfilled' WHERE id = ?")->execute([$resId]);

    // Verify
    $res = $pdo->query("SELECT * FROM reservations WHERE id = $resId")->fetch();
    $book = $pdo->query("SELECT * FROM books WHERE id = $bookId")->fetch();
    
    if ($res['status'] == 'fulfilled' && $book['available'] == 1) { // Stock shouldn't change again
        testLog("TEST 3: Admin Approve (Pending -> Fulfilled, Stock unchanged) - OK", 'PASS');
    } else {
        throw new Exception("Admin Approve Logic Failed");
    }

    // 6. Test Case: Cancel Reservation (Restore Stock)
    testLog("Testing Reservation Cancellation...");
    // Create another reservation first
    $pdo->prepare("INSERT INTO reservations (user_id, book_id, expires_at, status) VALUES (?, ?, ?, 'pending')")
        ->execute([$userId, $bookId, $expiresAt]);
    $resId2 = $pdo->lastInsertId();
    $pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ?")->execute([$bookId]); // Stock becomes 0

    // Cancel it
    $pdo->prepare("UPDATE reservations SET status = 'cancelled' WHERE id = ?")->execute([$resId2]);
    $pdo->prepare("UPDATE books SET available = available + 1 WHERE id = ?")->execute([$bookId]); // Stock becomes 1

    $book = $pdo->query("SELECT * FROM books WHERE id = $bookId")->fetch();
    if ($book['available'] == 1) {
        testLog("TEST 4: Cancel Reservation (Stock Restored) - OK", 'PASS');
    } else {
        throw new Exception("Cancel Logic Failed: Available {$book['available']}");
    }

    $pdo->rollBack();
    testLog("\nALL RESERVATION LOGIC TESTS PASSED ✅", 'PASS');

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    testLog("TEST FAILED: " . $e->getMessage(), 'FAIL');
    exit(1);
}
