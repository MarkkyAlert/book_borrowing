<?php

/**
 * ตารางวันที่ห้องสมุดไม่เปิดทำการ — ใช้หักออกจากการคิดค่าปรับ
 *
 * 🎯 ปัญหา: ค่าปรับนับทุกวันตามปฏิทิน ไม่สนใจว่าห้องสมุดเปิดหรือไม่
 *    - ยืมก่อนหยุดยาว ครบกำหนดระหว่างที่ปิด กลับมาคืนวันแรกที่เปิด → โดนปรับ
 *      ทั้งที่ไม่มีวันไหนให้มาคืนได้เลย
 *    - ปิดปรับปรุง 60 วัน = 600 บาท/เล่ม ซึ่งแพงกว่าหนังสือส่วนใหญ่
 *
 * 🧠 เก็บเป็น "ช่วงวัน" ไม่ใช่วันเดี่ยว
 *    ปิดปรับปรุง 60 วันจะกลายเป็น 60 แถวถ้าเก็บทีละวัน — ทั้งกรอกยาก ทั้งอ่านยาก
 *    วันเดียว = ใส่ start_date เท่ากับ end_date
 *
 * 🧠 ทำไมเป็นตารางแยก ไม่ยัดใน `settings`
 *    `settings` เป็น key-value 1 แถว 1 ค่า แต่วันปิดมีได้ไม่จำกัดจำนวน
 *    ยัดเป็น JSON ในช่องเดียวจะค้นด้วย SQL ไม่ได้ และแก้ทีละรายการไม่ได้
 *
 * 📥 $pdo ถูกส่งเข้ามาโดย database/migrate.php
 */

return function (PDO $pdo): string {
    // 🛡️ ตรวจแยกทีละอย่าง — ตาราง / index
    //    เคยพลาดมาแล้วตอน add_reservation_queue: ผูกไว้ด้วยกันแล้วเจอสภาพครึ่ง ๆ
    //    ทำให้ index ไม่มีวันถูกสร้าง หรือพังด้วย Duplicate key name
    $done = [];

    $exists = $pdo->query("SHOW TABLES LIKE 'closed_days'")->rowCount() > 0;
    if ($exists) {
        $done[] = 'มีตาราง closed_days อยู่แล้ว — ข้าม';
    } else {
        $pdo->exec("
            CREATE TABLE `closed_days` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `start_date` DATE NOT NULL COMMENT 'วันแรกที่ปิด',
                `end_date` DATE NOT NULL COMMENT 'วันสุดท้ายที่ปิด (วันเดียว = ใส่ค่าเดียวกับ start_date)',
                `note` VARCHAR(255) NOT NULL COMMENT 'เหตุผล เช่น วันหยุดนักขัตฤกษ์ / ปิดปรับปรุง',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $done[] = 'สร้างตาราง closed_days แล้ว';
    }

    // 📇 index สำหรับการค้น "วันนี้อยู่ในช่วงปิดไหม" ซึ่งถูกเรียกทุกครั้งที่คิดค่าปรับ
    if (!$pdo->query("SHOW INDEX FROM `closed_days` WHERE Key_name = 'idx_closed_range'")->fetch()) {
        $pdo->exec("ALTER TABLE `closed_days` ADD INDEX `idx_closed_range` (`start_date`, `end_date`)");
        $done[] = 'เพิ่ม index idx_closed_range แล้ว';
    }

    return implode(' · ', $done);
};
