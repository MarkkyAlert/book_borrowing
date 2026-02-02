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

use App\Repositories\BookRepository;

// [SECURITY] Rate limiting ป้องกัน API abuse
if (!checkRateLimit('search_books', 60, 5)) { // 60 requests per 5 minutes
    http_response_code(429);
    echo '<div class="text-center text-red-500 py-4">Too many requests. Please wait.</div>';
    exit;
}

// ========== 1. รับ & Validate Input ==========
$search = trim($_GET['search'] ?? '');
$categoryId = (int) ($_GET['category'] ?? 0);
$status = $_GET['status'] ?? '';

// ========== 2. สร้าง filters array ==========
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

// ========== 3. เรียก Repository ==========
$pdo = getDB();
$bookRepository = new BookRepository($pdo);
$books = $bookRepository->findAll($filters);

// ========== 4. ส่ง Response (HTML partial) ==========
header('Content-Type: text/html; charset=utf-8');
require __DIR__ . '/../includes/book_grid.php';
