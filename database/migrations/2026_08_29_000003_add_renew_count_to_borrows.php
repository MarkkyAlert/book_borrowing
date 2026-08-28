<?php

/**
 * เพิ่มคอลัมน์ renew_count ให้ตาราง borrows
 *
 * 🎯 "ต่ออายุการยืม" — สมาชิกอ่านไม่จบ ขอต่ออีก 7 วัน
 *    เป็นงานที่ห้องสมุดทำทุกวัน แต่เดิมตารางยืม-คืนมีปุ่มเดียวคือ "คืน"
 *    ทางอ้อมคือคืนแล้วยืมใหม่ ซึ่งถ้าเลยกำหนดแล้วจะโดนค่าปรับทันที
 *    และประวัติกลายเป็น 2 รายการแทนที่จะเป็นรายการเดียวที่ต่ออายุ
 *
 * ⚠️ default = 0 → รายการเดิมทุกรายการยังต่ออายุได้ตามโควตาปกติ
 *
 * 📥 $pdo ถูกส่งเข้ามาโดย database/migrate.php
 */

return function (PDO $pdo): string {
    // 🛡️ ตรวจก่อนว่ามีคอลัมน์อยู่แล้วหรือยัง — migration ต้องรันซ้ำได้โดยไม่พัง
    $exists = $pdo->query("SHOW COLUMNS FROM `borrows` LIKE 'renew_count'")->rowCount() > 0;
    if ($exists) {
        return 'มีคอลัมน์ renew_count อยู่แล้ว — ข้าม';
    }

    $pdo->exec("ALTER TABLE `borrows`
                ADD COLUMN `renew_count` TINYINT UNSIGNED NOT NULL DEFAULT 0
                COMMENT 'ต่ออายุไปแล้วกี่ครั้ง' AFTER `due_date`");

    return 'เพิ่มคอลัมน์ renew_count ให้ borrows แล้ว';
};
