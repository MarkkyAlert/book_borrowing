<?php
/**
 * API: Search Books - Returns HTML partial
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

$pdo = getDB();

// Get search/filter parameters
$search = trim($_GET['search'] ?? '');
$categoryId = (int) ($_GET['category'] ?? 0);
$status = $_GET['status'] ?? '';

// Build query
$where = [];
$params = [];

if (!empty($search)) {
    $where[] = "(b.title LIKE ? OR b.author LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($categoryId > 0) {
    $where[] = "b.category_id = ?";
    $params[] = $categoryId;
}

if ($status === 'available') {
    $where[] = "b.available > 0";
}

$whereSQL = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get books
$sql = "
    SELECT b.*, c.name as category_name 
    FROM books b
    LEFT JOIN categories c ON b.category_id = c.id
    $whereSQL
    ORDER BY b.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$books = $stmt->fetchAll();

// Return the grid View
header('Content-Type: text/html; charset=utf-8');
require __DIR__ . '/../includes/book_grid.php';
