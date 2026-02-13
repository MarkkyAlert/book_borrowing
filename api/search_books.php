<?php
/**
 * API: Search Books - Returns HTML partial
 * 
 * ⚠️ กติกา: ไฟล์นี้ทำหน้าที่ Controller เท่านั้น
 * - ตรวจ method / validate input
 * - เรียก Repository
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

use App\Repositories\BookRepository;

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

// 📝 สร้าง filters array สำหรับส่งให้ Repository
$filters = [];

if (!empty($search)) {
    $filters['search'] = $search;
}

if ($categoryId > 0) {
    $filters['category_id'] = $categoryId;
}

if ($status === 'available') {
    $filters['available_only'] = true;
}

// 📝 เรียก Repository โดยตรง (ไม่ผ่าน Service เพราะเป็น read-only)
$pdo = getDB();
$bookRepository = new BookRepository($pdo);
$books = $bookRepository->findAll($filters);

// 📤 ส่ง Response เป็น HTML partial (ไม่ใช่ JSON)
//    AJAX รับ HTML ไปแทรกใน DOM โดยตรง (innerHTML)
header('Content-Type: text/html; charset=utf-8');
require __DIR__ . '/../includes/book_grid.php';
