<?php
/**
 * AJAX: Member Borrow History
 * 
 * @method GET
 * @param int id - Member ID
 * @return JSON array of borrow history
 */

require_once __DIR__ . '/../bootstrap.php';

// 📝 บังคับตอบเป็น JSON + UTF-8
header('Content-Type: application/json; charset=utf-8');

// 🛡️ [AUTHORIZATION] Staff ขึ้นไปเท่านั้น
//    requireStaffApi() คืน JSON error + exit ถ้าไม่ใช่ staff/admin
requireStaffApi();

// 🛡️ [SECURITY] บังคับ GET (read-only)
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([]);
    exit;
}

// 📝 Validate Input — แปลงเป็น int
$userId = (int) ($_GET['id'] ?? 0);
if ($userId <= 0) {
    // 📤 คืน array ว่าง (ไม่ใช่ error) เพราะ UI แค่แสดงว่า "ไม่มีข้อมูล"
    echo json_encode([]);
    exit;
}

// 📝 เรียก Repository โดยตรง (ไม่ผ่าน Service เพราะเป็น read-only ธรรมดา)
$pdo = getDB();
$borrowRepo = new \App\Repositories\BorrowRepository($pdo);
$history = $borrowRepo->findByUserId($userId, 10);

// 📤 คืน JSON array ของ borrow history (limit 10)
echo json_encode($history);
