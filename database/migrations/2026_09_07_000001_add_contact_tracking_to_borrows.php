<?php

/**
 * บันทึกการโทรตาม — จดว่าโทรหาใครไปแล้วเมื่อไหร่ ผลเป็นยังไง
 *
 * 🎯 ปัญหา (UAT รอบ 2 ข้อ ฎ.7): ระบบทำใบรายชื่อโทรตามให้ครบ มีเบอร์ กดโทรออกได้
 *    แต่**วางสายแล้วไม่มีที่จด** พรุ่งนี้เปิดมาก็ไม่รู้ว่าโทรใครไปแล้ว
 *    ต้องเริ่มไล่ใหม่ตั้งแต่ต้นทุกวัน หรือไปจดใส่กระดาษแยก
 *    → งานโทรตามเป็นงานเดียวที่ระบบพาไปได้ครึ่งทางแล้วปล่อยมือ
 *
 * 🧠 ทำไมจดที่ "รายการยืม" ไม่ใช่ที่ "คน":
 *    ใบโทรตาม 1 แถว = 1 รายการยืม และการโทรคือการทวงเล่มนั้น
 *    คนคนเดียวอาจค้าง 3 เล่มคนละกำหนด ต้องแยกกันได้ว่าโทรเรื่องเล่มไหนไปแล้ว
 *
 * 🧠 ทำไม 3 คอลัมน์: ทำตามแบบแผนที่ตารางนี้ใช้อยู่แล้ว 2 ชุด
 *    (lost_reported_at/by/note และ fine_waived_at/by/note)
 *    หมายเหตุสำคัญไม่แพ้วันที่ — "ไม่รับสาย" กับ "รับปากว่าพรุ่งนี้เอามาคืน"
 *    ทำให้คนที่มาสานงานต่อรู้ว่าควรทำอะไรถัดไป
 *
 * ⚠️ NULL ทั้งหมด = "ยังไม่เคยโทร" — รายการเดิมทุกแถวจึงไม่กระทบ
 *
 * 📥 $pdo ถูกส่งเข้ามาโดย database/migrate.php
 */

return function (PDO $pdo): string {
    if ($pdo->query("SHOW COLUMNS FROM `borrows` LIKE 'contacted_at'")->rowCount() > 0) {
        return 'มีคอลัมน์ contacted_at อยู่แล้ว — ข้าม';
    }

    $pdo->exec("ALTER TABLE `borrows`
                ADD COLUMN `contacted_at` DATETIME NULL
                    COMMENT 'โทรตามครั้งล่าสุดเมื่อไหร่ (NULL = ยังไม่เคยโทร)'
                    AFTER `notes`,
                ADD COLUMN `contacted_by` INT NULL
                    COMMENT 'เจ้าหน้าที่ที่โทร'
                    AFTER `contacted_at`,
                ADD COLUMN `contact_note` VARCHAR(255) NULL
                    COMMENT 'ผลการโทร เช่น ไม่รับสาย / รับปากว่าจะเอามาคืนพรุ่งนี้'
                    AFTER `contacted_by`");

    // 🔗 ON DELETE SET NULL — ลบบัญชีเจ้าหน้าที่แล้วประวัติการโทรต้องไม่หายไปด้วย
    //    (แบบเดียวกับ fk_borrows_waived_by / fk_borrows_lost_reported_by)
    $pdo->exec("ALTER TABLE `borrows`
                ADD CONSTRAINT `fk_borrows_contacted_by`
                FOREIGN KEY (`contacted_by`) REFERENCES `users`(`id`)
                ON DELETE SET NULL ON UPDATE CASCADE");

    return 'เพิ่มคอลัมน์บันทึกการโทรตามแล้ว (รายการเดิมทั้งหมด = ยังไม่เคยโทร)';
};
