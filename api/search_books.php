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

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../app/Repositories/BookRepository.php';

use App\Repositories\BookRepository;

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
