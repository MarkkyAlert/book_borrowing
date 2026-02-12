<?php
/**
 * API: Reserve Book
 * 
 * ⚠️ กติกา: ไฟล์นี้ทำหน้าที่ Controller เท่านั้น
 * - ตรวจ method / auth / validate input
 * - เรียก Service
 * - ส่ง JSON response
 * - ห้ามใส่ business logic
 * - ห้ามเข้าถึง DB โดยตรง
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Services\ReservationService;

header('Content-Type: application/json');

// 🛡️ [AUTH] ต้อง login ก่อน — ป้องกัน anonymous reservation
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'กรุณาเข้าสู่ระบบก่อนจองหนังสือ']);
    exit;
}

// 🛡️ [SECURITY] บังคับ POST — ป้องกัน CSRF via GET + ป้องกัน request ถูก cache
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// 📝 รับ & Validate Input
$bookId = (int) ($_POST['book_id'] ?? 0);
// 🛡️ [AUTH] ใช้ user_id จาก session เท่านั้น!
//    ห้ามรับจาก POST — ป้องกัน impersonation (แอบส่ง user_id ของคนอื่น)
$userId = $_SESSION['user_id'];

// 🛡️ [SECURITY] CSRF ป้องกัน attacker หลอกให้ user จองโดยไม่รู้ตัว
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

// 📝 ตรวจ book_id
if ($bookId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ข้อมูลไม่ถูกต้อง']);
    exit;
}

// 📝 เรียก Service (Single Source of Truth)
try {
    $pdo = getDB();
    $reservationService = new ReservationService($pdo);
    
    // 📝 Service จัดการทั้งหมด:
    //    expire เก่า, lock book, check stock, check duplicate, insert, decrement stock
    $result = $reservationService->createReservation($userId, $bookId);
    
    // 📤 คืน JSON สำเร็จ
    echo json_encode([
        'success' => true,
        'message' => $result['message']
    ]);

} catch (Exception $e) {
    // ❌ Service throw Exception → คืน 400 + error message
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
