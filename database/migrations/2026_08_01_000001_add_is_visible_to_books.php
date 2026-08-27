<?php

/**
 * เพิ่มคอลัมน์ is_visible ให้ตาราง books
 *
 * มาจากไฟล์เดิม database/add_is_visible.php (ก่อนมีระบบ migration)
 * ใช้กับระบบที่ติดตั้งก่อน commit ที่เพิ่มฟีเจอร์ "ซ่อนหนังสือจากหน้าสาธารณะ"
 *
 * 📥 $pdo ถูกส่งเข้ามาโดย database/migrate.php
 */

return function (PDO $pdo): string {
    // 🛡️ ตรวจก่อนว่ามีคอลัมน์อยู่แล้วหรือยัง — migration ต้องรันซ้ำได้โดยไม่พัง
    $exists = $pdo->query("SHOW COLUMNS FROM `books` LIKE 'is_visible'")->rowCount() > 0;
    if ($exists) {
        return 'มีคอลัมน์ is_visible อยู่แล้ว — ข้าม';
    }

    $pdo->exec("ALTER TABLE `books`
                ADD COLUMN `is_visible` TINYINT(1) NOT NULL DEFAULT 1
                COMMENT 'แสดงให้สาธารณะเห็น' AFTER `available`");

    return 'เพิ่มคอลัมน์ is_visible ให้ books แล้ว';
};
