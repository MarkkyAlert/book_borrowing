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
//    คืน HTML error (ไม่ใช่ JSON) เพราะ response ถูกแทรกลง DOM โดยตรง
//
// 🔴 เดิมเรียกแค่ checkRateLimit() โดยไม่เรียก incrementRateLimit() ตามหลัง
//    ตัวนับจึงเป็น 0 ตลอดกาล → **rate limit ไม่เคยทำงานเลย**
//    (ยิงทดสอบ 200 ครั้งติด ถูกบล็อก 0 ครั้ง · แถวใน rate_limits = 0)
//    endpoint อื่นทุกตัวเรียกครบคู่ มีแค่ที่นี่ที่เรียกครึ่งเดียว
//
// 🧠 ทำไมโควตา 300 ไม่ใช่ 60:
//    endpoint นี้ถูกเรียกทุกครั้งที่ผู้ใช้กดค้นหาบนหน้าแรก และห้องสมุดส่วนใหญ่
//    ออกอินเทอร์เน็ตผ่าน IP เดียว (NAT) → **ทั้งห้องสมุดแชร์โควตาก้อนเดียวกัน**
//    ถ้าตั้ง 60 ผู้ใช้ 30 คนค้นคนละ 2 ครั้งก็ชนเพดานแล้ว = ลูกค้าใช้งานไม่ได้
//    300 ครั้ง/5 นาที ≈ 30 คนค้นคนละ 10 ครั้ง ซึ่งเกินพฤติกรรมปกติมาก
//    แต่ยังกัน bot ที่ยิงเป็นพัน ๆ ครั้งได้อยู่
//    📌 ปรับได้ที่ SEARCH_RATE_LIMIT / SEARCH_RATE_WINDOW ใน .env
$searchLimit  = defined('SEARCH_RATE_LIMIT') ? SEARCH_RATE_LIMIT : 300;
$searchWindow = defined('SEARCH_RATE_WINDOW') ? SEARCH_RATE_WINDOW : 5;

if (!checkRateLimit('search_books', $searchLimit, $searchWindow)) {
    http_response_code(429);
    echo '<div class="text-center text-red-500 py-4">ค้นหาถี่เกินไป กรุณารอสักครู่แล้วลองใหม่</div>';
    exit;
}

// 📝 ต้องบันทึกหลังตรวจผ่านเท่านั้น — ถ้าบันทึกก่อนตรวจ คนที่ถูกบล็อกอยู่แล้ว
//    จะยิ่งถูกบล็อกยาวออกไปเรื่อย ๆ ทุกครั้งที่กด
incrementRateLimit('search_books');

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
