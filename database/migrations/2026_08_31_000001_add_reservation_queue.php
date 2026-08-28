<?php

/**
 * ==========================================================================
 * เพิ่ม "คิวรอ" ให้ระบบจอง (ROADMAP ข้อ 5)
 * ==========================================================================
 * - reservations.status     เพิ่มค่า 'waiting' = เข้าคิวรอเล่มที่คนอื่นยืมอยู่
 * - reservations.queued_at  เวลาเข้าคิว (ใช้เรียงลำดับ)
 * - reservations.expires_at ผ่อนให้เป็น NULL ได้ — คิวรอไม่มีวันหมดอายุ
 * - UNIQUE กันจองซ้ำเล่มเดิม (ค้างมาจาก KNOWN_LIMITATIONS §4)
 *
 * 🔴 ความต่างที่ต้องแยกให้ขาด
 *    waiting = เข้าคิวรอ   → **ไม่กินสต็อก** ไม่กินโควตา ไม่มีวันหมดอายุ
 *    pending = ของพร้อมแล้ว → กินสต็อก (กันเล่มไว้ให้) หมดอายุตาม RESERVATION_EXPIRE_DAYS
 *
 *    สูตรสต็อกคือ available = quantity − ยืมค้าง − จอง pending
 *    ทุก query ที่ใช้สูตรนี้กรอง status='pending' อยู่แล้ว จึงไม่นับ waiting ให้เองโดยอัตโนมัติ
 *    ที่ต้องระวังคือทางกลับกัน — จุดที่ต้อง**เพิ่ม** waiting เข้าไปนับ (ดู ReservationRepository)
 *
 * ✅ รันซ้ำได้ — เช็คก่อนทุกคำสั่ง
 */

return function (PDO $pdo): string {
    $done = [];

    // ── status ENUM ────────────────────────────────────────────────────
    $col = $pdo->query("SHOW COLUMNS FROM `reservations` LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($col && str_contains((string) $col['Type'], "'waiting'")) {
        $done[] = 'ENUM ของ reservations.status มี waiting อยู่แล้ว — ข้าม';
    } else {
        // 🧠 วาง 'waiting' ไว้หน้า 'pending' เพราะเป็นสถานะที่มาก่อนตามเวลาจริง
        $pdo->exec("
            ALTER TABLE `reservations`
            MODIFY COLUMN `status` ENUM('waiting','pending','fulfilled','expired','cancelled')
                NOT NULL DEFAULT 'pending'
                COMMENT 'waiting=เข้าคิวรอ ไม่กินสต็อก / pending=ของพร้อม รอมารับ กินสต็อก'
        ");
        $done[] = "เพิ่มค่า 'waiting' ใน ENUM ของ reservations.status แล้ว";
    }

    // ── queued_at ──────────────────────────────────────────────────────
    // 🧠 เช็คคอลัมน์กับ index **แยกกัน** ไม่รวมไว้ในเงื่อนไขเดียว
    //    ถ้ารวม แล้วเจอสภาพที่มีอย่างหนึ่งไม่มีอีกอย่าง (เช่น รันครึ่งทางแล้วพัง
    //    หรือมีคนเติมคอลัมน์เองด้วยมือ) migration จะพังด้วย Duplicate key name
    //    แล้วรันต่อไม่ได้เลย — ผิดกติกา "ต้องเขียนให้รันซ้ำได้"
    if ($pdo->query("SHOW COLUMNS FROM `reservations` LIKE 'queued_at'")->fetch()) {
        $done[] = 'มีคอลัมน์ queued_at อยู่แล้ว — ข้าม';
    } else {
        $pdo->exec("
            ALTER TABLE `reservations`
            ADD COLUMN `queued_at` DATETIME NULL DEFAULT NULL
                COMMENT 'เวลาเข้าคิว — ใช้เรียงลำดับ (แถวเก่าเป็น NULL ให้ COALESCE กับ created_at)'
                AFTER `status`
        ");
        $done[] = 'เพิ่มคอลัมน์ queued_at แล้ว';
    }

    if ($pdo->query("SHOW INDEX FROM `reservations` WHERE Key_name = 'idx_queue'")->fetch()) {
        $done[] = 'มี index idx_queue อยู่แล้ว — ข้าม';
    } else {
        $pdo->exec("ALTER TABLE `reservations` ADD INDEX `idx_queue` (`book_id`, `status`, `queued_at`)");
        $done[] = 'เพิ่ม index idx_queue แล้ว';
    }

    // ── expires_at ต้องเป็น NULL ได้ ────────────────────────────────────
    $exp = $pdo->query("SHOW COLUMNS FROM `reservations` LIKE 'expires_at'")->fetch(PDO::FETCH_ASSOC);
    if ($exp && strtoupper((string) $exp['Null']) === 'YES') {
        $done[] = 'expires_at เป็น NULL ได้อยู่แล้ว — ข้าม';
    } else {
        // 🧠 แถว waiting ไม่มีวันหมดอายุ จึงไม่มีค่าที่ควรใส่
        //    การใส่วันไกล ๆ แทน NULL จะทำให้ markExpiredReservations() ต้องจำเงื่อนไขพิเศษ
        $pdo->exec("
            ALTER TABLE `reservations`
            MODIFY COLUMN `expires_at` DATETIME NULL DEFAULT NULL
                COMMENT 'วันหมดอายุ — NULL สำหรับคิวรอ (waiting) ที่ไม่มีวันหมดอายุ'
        ");
        $done[] = 'ผ่อน expires_at ให้เป็น NULL ได้แล้ว (สำหรับคิวรอ)';
    }

    // ── UNIQUE กันจองซ้ำ (KNOWN_LIMITATIONS §4) ─────────────────────────
    // 🧠 MySQL/MariaDB มองว่าแถวที่มี NULL ใน unique key ไม่ชนกัน
    //    จึงใช้คอลัมน์ generated ที่เป็น NULL เมื่อการจองปิดไปแล้ว (fulfilled/expired/cancelled)
    //    → กันซ้ำเฉพาะการจองที่ยัง "มีชีวิต" (waiting/pending) ตามที่ต้องการพอดี
    if ($pdo->query("SHOW COLUMNS FROM `reservations` LIKE 'active_slot'")->fetch()) {
        $done[] = 'มีคอลัมน์ active_slot อยู่แล้ว — ข้าม';
    } else {
        // 🧹 เคลียร์ของซ้ำที่ค้างอยู่ก่อน ไม่งั้นสร้าง index ไม่ผ่าน
        //    เก็บแถวที่ใหม่ที่สุดไว้ ที่เหลือตั้งเป็น cancelled
        $dupes = $pdo->query("
            SELECT user_id, book_id, MAX(id) AS keep_id, COUNT(*) AS n
            FROM reservations
            WHERE status IN ('waiting','pending')
            GROUP BY user_id, book_id
            HAVING n > 1
        ")->fetchAll(PDO::FETCH_ASSOC);
        $cleaned = 0;
        foreach ($dupes as $d) {
            $st = $pdo->prepare("
                UPDATE reservations SET status = 'cancelled'
                WHERE user_id = ? AND book_id = ? AND status IN ('waiting','pending') AND id <> ?
            ");
            $st->execute([$d['user_id'], $d['book_id'], $d['keep_id']]);
            $cleaned += $st->rowCount();
        }
        if ($cleaned > 0) {
            $done[] = "ยกเลิกการจองซ้ำที่ค้างอยู่ {$cleaned} รายการก่อนสร้าง unique";
        }

        $pdo->exec("
            ALTER TABLE `reservations`
            ADD COLUMN `active_slot` TINYINT(1)
                GENERATED ALWAYS AS (CASE WHEN `status` IN ('waiting','pending') THEN 1 ELSE NULL END) VIRTUAL
                COMMENT 'ตัวช่วยกันจองซ้ำ — NULL เมื่อการจองปิดแล้ว ทำให้ unique ไม่ชน'
        ");
        $done[] = 'เพิ่มคอลัมน์ active_slot แล้ว';
    }

    // 🧠 เช็ค unique แยกจากคอลัมน์ ด้วยเหตุผลเดียวกับ idx_queue ด้านบน
    if ($pdo->query("SHOW INDEX FROM `reservations` WHERE Key_name = 'uq_reservation_active'")->fetch()) {
        $done[] = 'มี UNIQUE กันจองซ้ำอยู่แล้ว — ข้าม';
    } else {
        $pdo->exec("
            ALTER TABLE `reservations`
            ADD UNIQUE KEY `uq_reservation_active` (`user_id`, `book_id`, `active_slot`)
        ");
        $done[] = 'เพิ่ม UNIQUE กันจองซ้ำเล่มเดิม (user_id, book_id) เฉพาะที่ยัง waiting/pending';
    }

    return implode(' · ', $done);
};
