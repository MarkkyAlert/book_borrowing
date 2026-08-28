<?php

/**
 * ==========================================================================
 * เพิ่มการบันทึก "หนังสือหาย / ชำรุด" (ROADMAP ข้อ 4)
 * ==========================================================================
 * - books.price              ราคาปก ใช้ตั้งต้นค่าชดใช้
 * - borrows.status           เพิ่มค่า 'lost' และ 'damaged' ใน ENUM
 * - borrows.lost_reported_at เวลาที่แจ้ง
 * - borrows.lost_reported_by ใครแจ้ง (FK users)
 * - borrows.lost_note        รายละเอียด/เหตุผล
 *
 * 🧠 ทำไมต้องมี lost_reported_at แยก ไม่ใช้ return_date ซ้ำ
 *    ReportRepository นับ "คืนแล้ว" จาก return_date โดยไม่กรอง status
 *    (getDailySummary / getMonthlyReturns) ถ้าใส่ return_date ให้เล่มที่หาย
 *    ตัวเลข "คืนวันนี้/คืนเดือนนี้" จะพองขึ้นด้วยเล่มที่ไม่เคยถูกคืนจริง
 *
 * ✅ รันซ้ำได้ — เช็คก่อนทุกคำสั่ง
 */

return function (PDO $pdo): string {
    $done = [];

    // ── books.price ────────────────────────────────────────────────────
    $has = $pdo->query("SHOW COLUMNS FROM `books` LIKE 'price'")->fetch();
    if ($has) {
        $done[] = 'มีคอลัมน์ price อยู่แล้ว — ข้าม';
    } else {
        $pdo->exec("
            ALTER TABLE `books`
            ADD COLUMN `price` DECIMAL(10,2) NULL DEFAULT NULL
                COMMENT 'ราคาปก — ใช้ตั้งต้นค่าชดใช้ตอนแจ้งหาย (NULL = ยังไม่ระบุ)'
                AFTER `quantity`
        ");
        $done[] = 'เพิ่มคอลัมน์ price ให้ books แล้ว';
    }

    // ── borrows.status ENUM ────────────────────────────────────────────
    // 🧠 อ่านนิยามปัจจุบันก่อน — ถ้ามี 'lost' แล้วแปลว่าเคยรันไปแล้ว
    $col = $pdo->query("SHOW COLUMNS FROM `borrows` LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($col && str_contains((string) $col['Type'], "'lost'")) {
        $done[] = 'ENUM ของ borrows.status มี lost/damaged อยู่แล้ว — ข้าม';
    } else {
        $pdo->exec("
            ALTER TABLE `borrows`
            MODIFY COLUMN `status` ENUM('borrowing','returned','lost','damaged')
                NOT NULL DEFAULT 'borrowing'
                COMMENT 'borrowing=ยังไม่คืน / returned=คืนแล้ว / lost=หาย / damaged=ชำรุดจนใช้ไม่ได้'
        ");
        $done[] = "เพิ่มค่า 'lost' และ 'damaged' ใน ENUM ของ borrows.status แล้ว";
    }

    // ── borrows: 3 คอลัมน์บันทึกร่องรอย ────────────────────────────────
    $cols = [
        'lost_reported_at' => "ADD COLUMN `lost_reported_at` DATETIME NULL DEFAULT NULL COMMENT 'เวลาที่แจ้งหาย/ชำรุด' AFTER `return_date`",
        'lost_reported_by' => "ADD COLUMN `lost_reported_by` INT NULL DEFAULT NULL COMMENT 'ผู้แจ้ง' AFTER `lost_reported_at`",
        'lost_note'        => "ADD COLUMN `lost_note` VARCHAR(255) NULL DEFAULT NULL COMMENT 'รายละเอียด/เหตุผล (บังคับกรอกที่ชั้น Service)' AFTER `lost_reported_by`",
    ];
    $toAdd = [];
    foreach ($cols as $name => $sql) {
        if (!$pdo->query("SHOW COLUMNS FROM `borrows` LIKE '{$name}'")->fetch()) {
            $toAdd[] = $sql;
        }
    }
    if ($toAdd) {
        $pdo->exec("ALTER TABLE `borrows` " . implode(', ', $toAdd));
        $done[] = 'เพิ่มคอลัมน์ lost_reported_at / lost_reported_by / lost_note ให้ borrows แล้ว';
    } else {
        $done[] = 'มีคอลัมน์ lost_* ครบแล้ว — ข้าม';
    }

    // ── FK ของ lost_reported_by ────────────────────────────────────────
    $fk = $pdo->query("
        SELECT COUNT(*) FROM information_schema.table_constraints
        WHERE table_schema = DATABASE()
          AND table_name = 'borrows'
          AND constraint_name = 'fk_borrows_lost_reported_by'
    ")->fetchColumn();
    if ((int) $fk === 0) {
        $pdo->exec("
            ALTER TABLE `borrows`
            ADD CONSTRAINT `fk_borrows_lost_reported_by`
                FOREIGN KEY (`lost_reported_by`) REFERENCES `users`(`id`)
                ON DELETE SET NULL ON UPDATE CASCADE
        ");
        $done[] = 'เพิ่ม FK fk_borrows_lost_reported_by แล้ว';
    }

    return implode(' · ', $done);
};
