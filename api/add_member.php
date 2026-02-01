<?php
/**
 * AJAX: Quick Add Member
 * 
 * ⚠️ กติกา: ไฟล์นี้ทำหน้าที่ Controller เท่านั้น
 * - ตรวจ method / auth / validate input
 * - เรียก Repository
 * - ส่ง JSON response
 * - ห้ามใส่ business logic
 * - ห้ามเขียน SQL โดยตรง
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Repositories\UserRepository;

header('Content-Type: application/json');

// [SECURITY] Method check - ป้องกัน GET request ที่อาจถูก cache/log
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// [AUTHORIZATION] เฉพาะ admin เท่านั้น - staff ไม่มีสิทธิ์
// เหตุผล: การเพิ่ม member ควรผ่าน flow ปกติ (register) ยกเว้น admin ช่วยเพิ่มให้
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// [SECURITY] CSRF check - ป้องกัน request จาก site อื่น
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

$pdo = getDB();
$userRepo = new UserRepository($pdo);

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
$phone = trim($_POST['phone'] ?? '');

// Validation
$errors = [];

if (empty($name)) {
    $errors[] = 'กรุณากรอกชื่อ-นามสกุล';
} elseif (strlen($name) < 2 || strlen($name) > 100) {
    $errors[] = 'ชื่อต้องมีความยาว 2-100 ตัวอักษร';
}

if (empty($email)) {
    $errors[] = 'กรุณากรอกอีเมล';
} elseif (!isValidEmail($email)) {
    $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
} else {
    // Check duplicate email using Repository
    if ($userRepo->emailExists($email)) {
        $errors[] = 'อีเมลนี้ถูกใช้งานแล้ว';
    }
}

if (!empty($phone) && !isValidPhone($phone)) {
    $errors[] = 'เบอร์โทรต้องเป็นตัวเลข 9-10 หลัก';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
    exit;
}

try {
    // [SECURITY] สร้าง random password - user ต้องใช้ forgot password เพื่อตั้งค่าเอง
    // ไม่ส่ง password กลับไปแสดง - ป้องกันการ leak
    $randomPassword = bin2hex(random_bytes(4)); // 8 characters
    $hashedPassword = password_hash($randomPassword, PASSWORD_DEFAULT);
    
    // [NOTE] role hardcode เป็น 'member' - ห้าม admin สร้าง admin ผ่าน quick add
    // Create member using Repository
    $newId = $userRepo->create([
        'name' => $name,
        'email' => $email,
        'password' => $hashedPassword,
        'phone' => $phone ?: null,
        'role' => 'member'
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'เพิ่มสมาชิกสำเร็จ',
        'member' => [
            'id' => $newId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาด กรุณาลองใหม่']);
}
