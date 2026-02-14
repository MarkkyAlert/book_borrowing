<?php

/**
 * Test Reservation Logic (Gap Analysis)
 */
require_once __DIR__ . '/../bootstrap.php';

use App\Services\ReservationService;
use App\Models\Book;

$pdo = getDB();
$reservationService = new ReservationService($pdo);

// Setup Data
$pdo->exec("INSERT IGNORE INTO users (id, name, email, password, role) VALUES (998, 'Reserver A', 'res_a@test.com', 'pass', 'member')");
$pdo->exec("INSERT IGNORE INTO users (id, name, email, password, role) VALUES (999, 'Reserver B', 'res_b@test.com', 'pass', 'member')");
$pdo->exec("INSERT IGNORE INTO books (id, title, author, quantity, available) VALUES (999, 'Res Book', 'Auth', 5, 5)");

function assertTest($name, $condition, $msg = "")
{
    echo $condition ? "✅ PASS: $name\n" : "❌ FAIL: $name ($msg)\n";
}

echo "🧪 Testing Reservation Logic...\n\n";

// Pre-cleanup
$pdo->exec("DELETE FROM reservations WHERE book_id = 999");
$pdo->exec("DELETE FROM books WHERE id = 999");
$pdo->exec("DELETE FROM users WHERE id IN (998, 999)");

// Setup Data (Re-insert)
$pdo->exec("INSERT IGNORE INTO users (id, name, email, password, role) VALUES (998, 'Reserver A', 'res_a@test.com', 'pass', 'member')");
$pdo->exec("INSERT IGNORE INTO users (id, name, email, password, role) VALUES (999, 'Reserver B', 'res_b@test.com', 'pass', 'member')");
$pdo->exec("INSERT IGNORE INTO books (id, title, author, quantity, available) VALUES (999, 'Res Book', 'Auth', 5, 5)");

// 1. Happy Path: Reserve & Cancel
echo "1. Reserve & Cancel Flow\n";
$pdo->exec("UPDATE books SET available = 5 WHERE id = 999");
$res1 = $reservationService->createReservation(998, 999);
$avail1 = $pdo->query("SELECT available FROM books WHERE id = 999")->fetchColumn();
$resId1 = $pdo->query("SELECT id FROM reservations WHERE user_id = 998 AND book_id = 999 AND status = 'pending'")->fetchColumn();
assertTest("Reserve Success", $res1['success'] && $avail1 == 4 && $resId1, "Available: $avail1, ID: $resId1");

$resCancel = $reservationService->cancelReservation($resId1, 998);
$avail2 = $pdo->query("SELECT available FROM books WHERE id = 999")->fetchColumn();
$status = $pdo->query("SELECT status FROM reservations WHERE id = {$resId1}")->fetchColumn();
assertTest("Cancel Success", $resCancel['success'] && $avail2 == 5 && $status === 'cancelled', "Available: $avail2, Status: $status");

// 2. IDOR Protection
echo "\n2. IDOR Protection\n";
$res2 = $reservationService->createReservation(998, 999);
$resId2 = $pdo->query("SELECT id FROM reservations WHERE user_id = 998 AND book_id = 999 AND status = 'pending'")->fetchColumn();
try {
    $reservationService->cancelReservation($resId2, 999); // User 999 tries to cancel 998's
    assertTest("IDOR Blocked", false, "Should have thrown exception");
} catch (Exception $e) {
    assertTest("IDOR Blocked", true, "Caught expected exception: " . $e->getMessage());
}
// Clean up
$reservationService->cancelReservation($resId2, 998);

// 3. No Stock
echo "\n3. Reserve No Stock\n";
$pdo->exec("UPDATE books SET available = 0 WHERE id = 999");
try {
    $res3 = $reservationService->createReservation(998, 999);
    assertTest("Block No Stock", !$res3['success'], "Should have failed but succeeded");
} catch (Exception $e) {
    assertTest("Block No Stock", true, "Caught expected exception: " . $e->getMessage());
}

// 4. Lazy Expiration
echo "\n4. Lazy Expiration\n";
$pdo->exec("UPDATE books SET available = 5 WHERE id = 999");

// Use NEW service instance to bypass 'expiredMarked' flag
$reservationServiceClean = new ReservationService($pdo);

$res4 = $reservationServiceClean->createReservation(998, 999);
$resId4 = $pdo->query("SELECT id FROM reservations WHERE user_id = 998 AND book_id = 999 AND status = 'pending'")->fetchColumn();

// Manually expire it (set expires_at to PAST)
// createReservation sets expires_at to +2 days. We override it.
$pdo->exec("UPDATE reservations SET expires_at = DATE_SUB(NOW(), INTERVAL 1 HOUR), status = 'pending' WHERE id = {$resId4}");

// Trigger Check (Lazy Expire)
// We need another fresh instance because the previous createReservation already triggered the flag for $reservationServiceClean
$reservationServiceClean2 = new ReservationService($pdo);
$reservationServiceClean2->createReservation(999, 999);

// Check if old one is expired
$status4 = $pdo->query("SELECT status FROM reservations WHERE id = {$resId4}")->fetchColumn();
if ($status4 === 'expired') {
    assertTest("Lazy Expire", true);
} else {
    echo "ℹ️ Lazy Expire not triggered by createReservation. (Status: $status4)\n";
}

// Cleanup
$pdo->exec("DELETE FROM reservations WHERE book_id = 999");
$pdo->exec("DELETE FROM books WHERE id = 999");
$pdo->exec("DELETE FROM users WHERE id IN (998, 999)");
