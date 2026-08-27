<?php
/**
 * API: Search Books - Returns HTML partial
 * 
 * ⚠️ กติกา: ไฟล์นี้ทำหน้าที่ Controller เท่านั้น
 * - ตรวจ method / validate input
 * - เรียก Service
 * - ส่ง Response (HTML partial)
 * - ห้ามใส่ business logic
 * - ห้ามเขียน SQL โดยตรง
 */

require_once __DIR__ . '/../bootstrap.php';

// � [BY DESIGN] ไม่มี auth guard — API นี้เป็น public เพราะหน้า index.php (ค้นหาหนังสือ) เป็น public
//    มี rate limit (60 req/5min) ป้องกัน abuse อยู่แล้ว

// �🛡️ [SECURITY] บังคับ GET เท่านั้น (read-only API)
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit;
}

use App\Services\HomeService;

// 🛡️ [SECURITY] Rate limiting ป้องกัน API abuse
//    60 requests ต่อ 5 นาที — ป้องกัน bot/script ยิง request ถี่
//    คืน HTML error (ไม่ใช่ JSON) เพราะ response ถูกแทรกลง DOM โดยตรง
if (!checkRateLimit('search_books', 60, 5)) {
    http_response_code(429);
    echo '<div class="text-center text-red-500 py-4">Too many requests. Please wait.</div>';
    exit;
}

// 📝 รับ & Validate Input — กรองเฉพาะค่าที่ไม่ว่างออก
$search = trim($_GET['search'] ?? '');
$categoryId = (int) ($_GET['category'] ?? 0);
$status = $_GET['status'] ?? '';
$page = (int) ($_GET['page'] ?? 1);   // 📄 หน้าที่ขอ — Service clamp ให้อยู่ในช่วงจริงเอง

// 📝 สร้าง filters array สำหรับส่งให้ Service
$filters = [];

if (!empty($search)) {
    $filters['search'] = $search;
}

if ($categoryId > 0) {
    $filters['category_id'] = $categoryId;
}

if ($status === 'available') {
    $filters['status'] = 'available';
}

// 📄 ส่งหน้าที่ขอไปด้วย — ถ้าไม่ส่ง Service จะคืนหน้า 1 เสมอ
//    ทำให้กดเลขหน้าใน grid ที่โหลดผ่าน AJAX แล้วไม่ขยับ
$filters['page'] = $page;

// 📝 เรียกผ่าน HomeService — Single Source of Truth ของ "หนังสือที่ผู้ใช้ทั่วไปเห็นได้"
//    🛡️ [SECURITY] ห้ามเรียก BookRepository::findAll() ตรงๆ จากที่นี่!
//       HomeService::getBooks() เป็นที่เดียวที่ใส่ visible_only = true
//       ถ้าข้ามไปเรียก repo เอง หนังสือที่ถูกซ่อน (is_visible = 0) จะโผล่ในผลค้นหาสาธารณะ
//       (index.php ใช้ service ตัวเดียวกันนี้ตอน render ครั้งแรก — ผลลัพธ์จึงตรงกันเสมอ)
$homeService = new HomeService(getDB());
$data = $homeService->getBooks($filters);
$books = $data['books'];
$pagination = $data['pagination'];   // 📄 book_grid.php ใช้วาดแถบเลือกหน้าต่อท้าย

// 📄 filter ที่ต้องติดไปกับลิงก์เปลี่ยนหน้า (ใช้ชื่อ key เดียวกับที่หน้าเว็บส่งมา)
$paginationParams = ['search' => $search, 'category' => $categoryId ?: '', 'status' => $status];
$paginationAjax = true;   // 📝 ลิงก์จะมี data-page ให้ JS ดักคลิกแทนการโหลดหน้าใหม่
$paginationUnit = 'เล่ม';

// 📤 ส่ง Response เป็น HTML partial (ไม่ใช่ JSON)
//    AJAX รับ HTML ไปแทรกใน DOM โดยตรง (innerHTML)
header('Content-Type: text/html; charset=utf-8');
require __DIR__ . '/../includes/book_grid.php';
