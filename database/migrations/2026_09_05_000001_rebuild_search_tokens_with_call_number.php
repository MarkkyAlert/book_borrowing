<?php

/**
 * สร้าง search_tokens ใหม่ให้รวมเลขเรียกหนังสือ
 *
 * 🎯 ปัญหา: ค้นด้วยเลขเรียกแบบ LC (`PZ7.R79`) หรือดิวอี้ผสมอักษรผู้แต่ง
 *    (`823.914 R79`) **หาไม่เจอ** ทั้งที่หนังสืออยู่ในระบบ
 *
 * 🔴 สาเหตุ — สามอย่างมาบรรจบกันใน BookRepository::buildListQuery():
 *    1. `makeSearchTokens()` เดิมสร้าง tokens จาก title + author + isbn เท่านั้น
 *       ไม่มี call_number
 *    2. buildListQuery() ใส่ทั้ง `MATCH(search_tokens)` และ `call_number LIKE`
 *       ลง $where แล้วรวมด้วย **AND**
 *    3. `buildSearchBooleanQuery()` ข้าม FULLTEXT (คืน null) เฉพาะเมื่อสัดส่วน
 *       ตัวเลข ≥ 70% เท่านั้น
 *    → "PZ7.R79" มีตัวเลข ~43% จึงถูกบังคับให้ MATCH กับ tokens ที่ไม่มีเลขเรียก
 *      ได้ 0 แถว แล้ว AND ตัดทุกอย่างทิ้ง
 *    → "823.914" (ตัวเลขล้วน) รอดมาได้เพราะคืน null → ใช้ LIKE ล้วน
 *
 * 🧠 ทำไมต้องมี migration ไม่ใช่แค่แก้โค้ด:
 *    `search_tokens` เป็นค่าที่ **คำนวณไว้ตอนบันทึก** ไม่ได้คำนวณใหม่ตอนค้นหา
 *    ลูกค้าที่อัปเกรดแล้วไม่ได้กดแก้ไขหนังสือ จะยังมี tokens แบบเก่าและค้นไม่เจอเหมือนเดิม
 *
 * 🧠 ทำไมไม่แก้เป็น `OR` ซึ่งง่ายกว่าและไม่ต้อง rebuild:
 *    trigram ถูกใส่มาเพื่อให้ค้นภาษาไทยแม่นขึ้น ถ้า OR กับ LIKE ผลการค้นหา
 *    **ทั้งระบบ** จะกว้างขึ้นกลับไปเหมือนก่อนมี FULLTEXT — แก้บั๊กเล็กแล้วทำของใหญ่พัง
 *
 * 🛡️ ปลอดภัยกับข้อมูล: search_tokens เป็นคอลัมน์เงาสำหรับค้นหาอย่างเดียว
 *    เขียนทับได้ ไม่กระทบ title/author/isbn/สต็อก/การยืม
 *    ทำทีละ 500 แถวในทรานแซกชันของตัวเอง ไม่ล็อกตารางยาว และรันซ้ำได้
 *
 * 📥 $pdo ถูกส่งเข้ามาโดย database/migrate.php
 */

return function (PDO $pdo): string {
    // 🧠 ถ้ายังไม่มีคอลัมน์ (ติดตั้งเก่ามาก) ให้ข้าม — migration ก่อนหน้าจะสร้างให้เอง
    foreach (['search_tokens', 'call_number'] as $col) {
        if ($pdo->query("SHOW COLUMNS FROM `books` LIKE '{$col}'")->rowCount() === 0) {
            return "ยังไม่มีคอลัมน์ {$col} — ข้าม (migration ก่อนหน้าจะสร้างให้)";
        }
    }

    require_once __DIR__ . '/../../includes/functions.php';

    // 📝 อัปเดต COMMENT ให้ตรงความจริงด้วย — ตอนนี้ tokens รวมเลขเรียกแล้ว
    //    ต้องตรงกับ database/schema.sql และ install.php (กฎ "3 ที่ต้องตรงกัน")
    $pdo->exec("ALTER TABLE `books`
                MODIFY COLUMN `search_tokens` TEXT DEFAULT NULL
                COMMENT 'trigram ของ title+author+isbn+call_number สำหรับ FULLTEXT (สร้างโดย buildSearchTokens())'");

    // 🔁 ไล่ตาม id เพิ่มขึ้นเรื่อย ๆ แทน OFFSET
    //    เพราะ UPDATE ทำให้ลำดับ/เงื่อนไขเปลี่ยน → OFFSET จะข้ามแถวไป
    $select = $pdo->prepare("
        SELECT id, title, author, isbn, call_number
        FROM books
        WHERE id > ?
        ORDER BY id
        LIMIT 500
    ");
    $update = $pdo->prepare("UPDATE books SET search_tokens = ? WHERE id = ?");

    $lastId = 0;
    $done   = 0;

    while (true) {
        $select->execute([$lastId]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            break;
        }

        $pdo->beginTransaction();
        foreach ($rows as $row) {
            // 🔴 ต้องตรงกับ BookRepository::makeSearchTokens() เป๊ะ
            //    ถ้าสองที่นี้ไม่ตรงกัน หนังสือที่แก้ไขทีหลังจะมี tokens คนละสูตร
            $source = trim($row['title'] . ' ' . $row['author'] . ' '
                . ($row['isbn'] ?? '') . ' ' . ($row['call_number'] ?? ''));
            $update->execute([buildSearchTokens($source), $row['id']]);
            $lastId = (int) $row['id'];
            $done++;
        }
        $pdo->commit();
    }

    return $done > 0
        ? "สร้าง search_tokens ใหม่ให้รวมเลขเรียกแล้ว {$done} เล่ม"
        : 'ไม่มีหนังสือให้สร้าง index';
};
