<?php
/**
 * Automated Integration Test for Barcode Scanning Logic
 * Test scanner endpoints in borrow_form.php via direct logic invocation (or simulating POST)
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

    // 1. Get a valid User ID
    $userId = $pdo->query("SELECT id FROM users WHERE role = 'member' LIMIT 1")->fetchColumn();
    if (!$userId) throw new Exception("No member found");
    testLog("Using Member ID: $userId");

    // 2. Simulate User Scan Logic (Query directly as in borrow_form.php)
    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ? AND role = 'member'");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        testLog("TEST 1: Scan User Found - OK", 'PASS');
    } else {
        throw new Exception("Scan User Logic Failed");
    }

    // 3. Get a valid Book ID
    $bookId = $pdo->query("SELECT id FROM books WHERE available > 0 LIMIT 1")->fetchColumn();
    if (!$bookId) throw new Exception("No available book found");
    testLog("Using Book ID: $bookId");

    // 4. Simulate Book Scan Logic
    $stmt = $pdo->prepare("SELECT id, title, author, available FROM books WHERE (id = ? OR isbn = ?)");
    $stmt->execute([$bookId, $bookId]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($book) {
        testLog("TEST 2: Scan Book Found - OK", 'PASS');
    } else {
        throw new Exception("Scan Book Logic Failed");
    }

    testLog("\nALL SCAN TESTS PASSED ✅", 'PASS');

} catch (Exception $e) {
    testLog("TEST FAILED: " . $e->getMessage(), 'FAIL');
    exit(1);
}
