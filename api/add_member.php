<?php
/**
 * AJAX: Quick Add Member
 * 
 * ⚠️ กติกา: ไฟล์นี้ทำหน้าที่ Controller เท่านั้น
 * - ตรวจ method / auth / CSRF
 * - เรียก MemberService (single source of truth)
 * - ส่ง JSON response
 * - ห้ามใส่ business logic
 * - ห้ามเขียน SQL โดยตรง
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Services\MemberService;

// 📝 บังคับตอบเป็น JSON เสมอ (ไม่ใช่ HTML)
header('Content-Type: application/json');

// 🛡️ [SECURITY] บังคับ POST เท่านั้น — ป้องกัน GET request ถูก cache / bookmark
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// 🛡️ [AUTHORIZATION] Staff ขึ้นไปเท่านั้นจึงเพิ่มสมาชิกได้
//    requireStaffApi() อยู่ใน bootstrap.php — คืน JSON error ถ้าไม่ใช่ staff/admin
requireStaffApi();

// 🛡️ [SECURITY] CSRF check — ป้องกัน attacker หลอกให้ staff เพิ่มสมาชิกโดยไม่รู้ตัว
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

try {
    // 📝 สร้าง PDO + Service
    $pdo = getDB();
    $memberService = new MemberService($pdo);
    
    // 📝 เรียก Service (Single Source of Truth)
    //    MemberService จัดการ: validate, duplicate check, password generation, hash, INSERT
    //    Controller ไม่ต้องทำอะไรเพิ่มเติม
    //    🔑 [F-53] true = บังคับเปลี่ยนรหัสตอนล็อกอินครั้งแรก
    //       endpoint นี้สุ่มรหัสแล้วคืนให้เจ้าหน้าที่อ่านออกเสียงให้สมาชิกฟัง
    //       = มีคนอื่นรู้รหัสนั้นเสมอ จึงต้องบังคับเปลี่ยน
    $result = $memberService->createMember([
        'name' => trim($_POST['name'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? '')
    ], true);
    
    // 📤 คืน JSON สำเร็จ
    // 🔑 คืนรหัสผ่านที่ระบบสุ่มให้ กลับไปแสดงบนหน้าจอ "ครั้งเดียว"
    //    ⚠️ endpoint นี้ไม่ได้ส่ง password เข้าไป → MemberService จะสุ่มให้เสมอ
    //       ถ้าไม่คืนค่ากลับ จะไม่มีใครรู้รหัสของสมาชิกที่เพิ่งเพิ่ม
    //       (hash แล้วดึงกลับไม่ได้ + ระบบยังไม่ส่งอีเมล → สมาชิกจะ login ไม่ได้เลย)
    //    🛡️ ปลอดภัยเพราะ endpoint นี้ผ่าน requireStaffApi() แล้ว — เห็นเฉพาะเจ้าหน้าที่
    echo json_encode([
        'success' => true,
        'message' => 'เพิ่มสมาชิกสำเร็จ',
        'member' => [
            'id' => $result['id'],
            'name' => $result['name'],
            'email' => $result['email'],
            'phone' => $_POST['phone'] ?? '',
            'password' => $result['password']
        ]
    ]);
} catch (Exception $e) {
    // ❌ Service throw Exception → คืน 400 + error message
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
