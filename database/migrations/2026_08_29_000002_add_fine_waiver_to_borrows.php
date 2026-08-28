<?php

/**
 * เพิ่มคอลัมน์ "ยกเว้นค่าปรับ" ให้ตาราง borrows
 *
 * 🎯 ห้องสมุดทุกแห่งต้องยกเว้นค่าปรับเป็นกรณี ๆ (ระบบผิดพลาด เจ็บป่วย ปิดกะทันหัน)
 *    เดิมทำไม่ได้เลย — ทางเดียวคือปล่อยค้างไว้ตลอดกาลจนไปพองในยอดค้างชำระรวม
 *
 * 🧠 ทำไมไม่บันทึกเป็นแถวใน payments ที่ amount = 0:
 *    - `payments` แปลว่า "เก็บเงินได้" การยกเว้นคือ "ไม่เก็บ" — ปนกันแล้วรายงานรายได้เพี้ยน
 *    - `unique_borrow_payment` จะทำให้ยกเว้นแล้วรับชำระทีหลังไม่ได้ ด้วยเหตุผลที่ผิด
 *
 * 🛡️ เป็นเรื่องเงิน → ต้องรู้เสมอว่า **ใคร ยกเว้นเมื่อไหร่ เพราะอะไร**
 *    ระบบนี้ยังไม่มี audit trail กลาง (ดู KNOWN_LIMITATIONS §4) จึงเก็บไว้กับแถวโดยตรง
 *
 * 📥 $pdo ถูกส่งเข้ามาโดย database/migrate.php
 */

return function (PDO $pdo): string {
    // 🛡️ ตรวจก่อนว่ามีคอลัมน์อยู่แล้วหรือยัง — migration ต้องรันซ้ำได้โดยไม่พัง
    $exists = $pdo->query("SHOW COLUMNS FROM `borrows` LIKE 'fine_waived_at'")->rowCount() > 0;
    if ($exists) {
        return 'มีคอลัมน์ fine_waived_* อยู่แล้ว — ข้าม';
    }

    $pdo->exec("ALTER TABLE `borrows`
                ADD COLUMN `fine_waived_at`   DATETIME     NULL COMMENT 'เวลาที่ยกเว้นค่าปรับ (NULL = ยังไม่ยกเว้น)' AFTER `fine_amount`,
                ADD COLUMN `fine_waived_by`   INT          NULL COMMENT 'ผู้ยกเว้น' AFTER `fine_waived_at`,
                ADD COLUMN `fine_waived_note` VARCHAR(255) NULL COMMENT 'เหตุผลที่ยกเว้น (บังคับกรอกที่ชั้น Service)' AFTER `fine_waived_by`");

    // 🔗 FK ไปที่ users — ON DELETE SET NULL เหมือน payments.recorded_by
    //    ลบบัญชีเจ้าหน้าที่แล้วประวัติการยกเว้นต้องไม่หายไปด้วย
    $pdo->exec("ALTER TABLE `borrows`
                ADD CONSTRAINT `fk_borrows_waived_by`
                FOREIGN KEY (`fine_waived_by`) REFERENCES `users`(`id`)
                ON DELETE SET NULL ON UPDATE CASCADE");

    // 📇 index สำหรับหน้ารายการค้างชำระที่ต้องกรอง fine_waived_at IS NULL ทุกครั้ง
    $pdo->exec("ALTER TABLE `borrows` ADD INDEX `idx_fine_waived` (`fine_waived_at`)");

    return 'เพิ่มคอลัมน์ fine_waived_at / fine_waived_by / fine_waived_note ให้ borrows แล้ว';
};
