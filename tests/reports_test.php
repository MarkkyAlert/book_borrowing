<?php
/**
 * Automated Integration Test for Reports Module
 * Run via CLI: C:\xampp\php\php.exe tests/reports_test.php
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

function testLog($message, $type = 'INFO') {
    $color = $type === 'PASS' ? "\033[32m" : ($type === 'FAIL' ? "\033[31m" : "\033[36m");
    $reset = "\033[0m";
    echo "{$color}[{$type}] {$message}{$reset}\n";
}

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    testLog("Database connected successfully");

    // 1. Test Top Books Query
    $sql = "
        SELECT b.title, c.name as category, COUNT(br.id) as borrow_count,
               (b.quantity - b.available) as currently_borrowed
        FROM books b
        LEFT JOIN categories c ON b.category_id = c.id
        LEFT JOIN borrows br ON b.id = br.book_id
        GROUP BY b.id
        ORDER BY borrow_count DESC
        LIMIT 5
    ";
    $books = $pdo->query($sql)->fetchAll();
    testLog("TEST 1: Top Books Query - OK (" . count($books) . " rows)", 'PASS');

    // 2. Test Top Members Query
    $sql = "
        SELECT u.name, u.email, u.role, COUNT(br.id) as borrow_count,
               SUM(CASE WHEN br.status = 'borrowing' THEN 1 ELSE 0 END) as active_loans
        FROM users u
        JOIN borrows br ON u.id = br.user_id
        WHERE u.role != 'admin'
        GROUP BY u.id
        ORDER BY borrow_count DESC
        LIMIT 5
    ";
    $members = $pdo->query($sql)->fetchAll();
    testLog("TEST 2: Top Members Query - OK (" . count($members) . " rows)", 'PASS');

    // 3. Test Revenue Query
    $sql = "
        SELECT DATE(payment_date) as payment_day, COUNT(id) as transaction_count, SUM(amount) as total_amount
        FROM payments
        GROUP BY DATE(payment_date)
        ORDER BY payment_day DESC
        LIMIT 5
    ";
    $revenue = $pdo->query($sql)->fetchAll();
    testLog("TEST 3: Revenue Query - OK (" . count($revenue) . " rows)", 'PASS');

    testLog("\nALL REPORT TESTS PASSED ✅", 'PASS');

} catch (Exception $e) {
    testLog("TEST FAILED: " . $e->getMessage(), 'FAIL');
    exit(1);
}
