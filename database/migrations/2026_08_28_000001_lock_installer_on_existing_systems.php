<?php

/**
 * ปิดตัวติดตั้งสำหรับระบบที่ติดตั้งไปแล้ว (ต่อจาก F-23)
 *
 * ==========================================================================
 * 🔴 ปัญหาที่ migration นี้แก้
 * ==========================================================================
 * F-23 ทำล็อค 2 ชั้นไว้ (ไฟล์ `.installed` + แถว `installed_at` ในตาราง settings)
 * แต่แถวใน DB **เขียนโดย `install.php` เท่านั้น** → ป้องกันเฉพาะการติดตั้งใหม่
 *
 * ลูกค้าที่อัปเกรดจากเวอร์ชันเก่าจึงเหลือแค่ล็อคชั้นไฟล์ ซึ่งเป็นชั้นที่พังง่าย —
 * ถ้า web server รันคนละ user กับเจ้าของโฟลเดอร์ ไฟล์ `.installed` จะเขียนไม่สำเร็จ
 * ตั้งแต่แรกอยู่แล้ว (ซึ่งคือต้นเหตุของ F-23 พอดี)
 *
 * ทดสอบยืนยันแล้ว: กู้ฐานข้อมูลลูกค้าเก่า → รัน migrate.php → ลบ `.installed`
 * → POST เข้า install.php → **ได้บัญชี admin ของผู้โจมตีจริง**
 *
 * ⚠️ [สำคัญ] ต้องตั้งค่าเฉพาะฐานข้อมูลที่ "ติดตั้งไปแล้วจริง" เท่านั้น
 *    ถ้าเผลอไปตั้งบนฐานข้อมูลเปล่า ลูกค้าจะติดตั้งไม่ได้เลยและงงหนักว่าเกิดอะไรขึ้น
 *    → ใช้ "มีบัญชี role=admin อยู่แล้ว" เป็นเงื่อนไข เพราะฐานข้อมูลเปล่าจะไม่มีแน่นอน
 *      (install.php สร้างบัญชี admin เป็นขั้นตอนหนึ่งของการติดตั้ง)
 */

return function (PDO $pdo): string {
    // 📝 ตาราง settings มีมาตั้งแต่ schema แรก แต่กันไว้เผื่อฐานข้อมูลแปลก ๆ
    $hasSettings = $pdo->query("SHOW TABLES LIKE 'settings'")->rowCount() > 0;
    if (!$hasSettings) {
        return 'ยังไม่มีตาราง settings — ข้าม (ฐานข้อมูลนี้ยังไม่ได้ติดตั้ง)';
    }

    // 📝 มีอยู่แล้ว → ไม่ต้องทำอะไร (รันซ้ำได้)
    $already = $pdo->query("SELECT 1 FROM settings WHERE setting_key = 'installed_at' LIMIT 1")->fetchColumn();
    if ($already !== false) {
        return 'ระบบมีล็อคติดตั้งใน database อยู่แล้ว — ข้าม';
    }

    // 🛡️ เงื่อนไขชี้ขาด: มีบัญชี admin = ผ่านการติดตั้งมาแล้วแน่นอน
    $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    if ($adminCount === 0) {
        return 'ยังไม่มีบัญชี admin — ถือว่ายังไม่ได้ติดตั้ง จึงไม่ล็อค';
    }

    // 📝 ใช้เวลาสร้างบัญชี admin แรกเป็น "วันที่ติดตั้ง" — ใกล้เคียงความจริงกว่าเวลาปัจจุบัน
    $installedAt = $pdo->query("SELECT MIN(created_at) FROM users WHERE role = 'admin'")->fetchColumn()
        ?: date('Y-m-d H:i:s');

    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('installed_at', ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([$installedAt]);

    return "ปิดตัวติดตั้งสำหรับระบบที่ใช้งานอยู่แล้ว (installed_at = $installedAt)";
};
