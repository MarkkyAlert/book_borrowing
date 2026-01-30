<?php
/**
 * API: Reserve Book
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

// Check login
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อนจองหนังสือ']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$bookId = (int) ($_POST['book_id'] ?? 0);
$userId = $_SESSION['user_id'];

if ($bookId <= 0) {
    echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ถูกต้อง']);
    exit;
}

try {
    $pdo = getDB();
    $pdo->beginTransaction();

    // 1. Check if user already reserved this book (pending)
    $stmt = $pdo->prepare("SELECT id FROM reservations WHERE user_id = ? AND book_id = ? AND status = 'pending'");
    $stmt->execute([$userId, $bookId]);
    if ($stmt->fetch()) {
        throw new Exception("คุณได้จองหนังสือเล่มนี้ไว้แล้ว กรุณารอรับหนังสือ");
    }

    // 2. Check book availability
    $stmt = $pdo->prepare("SELECT available, quantity, title FROM books WHERE id = ? FOR UPDATE");
    $stmt->execute([$bookId]);
    $book = $stmt->fetch();

    if (!$book) {
        throw new Exception("ไม่พบหนังสือ");
    }

    if ($book['available'] <= 0) {
        throw new Exception("หนังสือหมด ไม่สามารถจองได้");
    }

    // 3. Create Reservation
    // Expire in 2 days
    $expiresAt = date('Y-m-d H:i:s', strtotime('+2 days'));
    
    $stmt = $pdo->prepare("
        INSERT INTO reservations (user_id, book_id, expires_at, status)
        VALUES (?, ?, ?, 'pending')
    ");
    $stmt->execute([$userId, $bookId, $expiresAt]);

    // 4. Decrement Stock
    $stmt = $pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ?");
    $stmt->execute([$bookId]);

    $pdo->commit();

    echo json_encode([
        'success' => true, 
        'message' => "จองสำเร็จ! กรุณามารับหนังสือ \"{$book['title']}\" ภายในวันที่ " . formatDate($expiresAt)
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
