<?php

/**
 * Migration: Add is_visible column to books table
 */
require_once __DIR__ . '/../bootstrap.php';

$pdo = getDB();

try {
    // Check if column already exists
    $stmt = $pdo->query("SHOW COLUMNS FROM books LIKE 'is_visible'");
    if ($stmt->rowCount() > 0) {
        echo "Column 'is_visible' already exists. Skipping.\n";
    } else {
        $pdo->exec("ALTER TABLE books ADD COLUMN `is_visible` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'แสดงให้สาธารณะเห็น' AFTER `available`");
        echo "OK: Added 'is_visible' column to books table.\n";
    }
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
