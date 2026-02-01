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

header('Content-Type: application/json');

// [SECURITY] Method check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// [AUTHORIZATION] เฉพาะ admin เท่านั้น
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// [SECURITY] CSRF check
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit;
}

try {
    $pdo = getDB();
    $memberService = new MemberService($pdo);
    
    // MemberService handles: validation, duplicate check, password generation
    $result = $memberService->createMember([
        'name' => trim($_POST['name'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? '')
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'เพิ่มสมาชิกสำเร็จ',
        'member' => [
            'id' => $result['id'],
            'name' => $result['name'],
            'email' => $result['email'],
            'phone' => $_POST['phone'] ?? ''
        ]
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
