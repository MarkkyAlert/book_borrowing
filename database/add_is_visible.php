<?php

/**
 * Migration: Add is_visible column to books table
 *
 * วิธีใช้งาน (CLI เท่านั้น):
 *   php database/add_is_visible.php
 *
 * ⚠️ ใช้กับระบบที่ติดตั้งไว้ก่อน commit ที่เพิ่มฟีเจอร์ซ่อนหนังสือเท่านั้น
 *    ติดตั้งใหม่ด้วย install.php จะมีคอลัมน์นี้อยู่แล้ว
 */

// 🛡️ [SECURITY] CLI เท่านั้น — migration แก้ schema ห้ามเรียกผ่าน browser
//    ใช้ guard แบบเดียวกับ cron/*.php (ไฟล์ migration ใหม่ทุกไฟล์ต้องมีบรรทัดนี้)
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied — run this migration from the command line');
}

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
