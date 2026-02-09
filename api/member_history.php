<?php
/**
 * AJAX: Member Borrow History
 * 
 * @method GET
 * @param int id - Member ID
 * @return JSON array of borrow history
 */

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// [SECURITY] Staff only
if (!isAdmin() && !isStaff()) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([]);
    exit;
}

$userId = (int) ($_GET['id'] ?? 0);
if ($userId <= 0) {
    echo json_encode([]);
    exit;
}

$pdo = getDB();
$borrowRepo = new \App\Repositories\BorrowRepository($pdo);
$history = $borrowRepo->findByUserId($userId, 10);

echo json_encode($history);
