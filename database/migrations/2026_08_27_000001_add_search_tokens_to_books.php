<?php

/**
 * เพิ่มคอลัมน์ `search_tokens` + FULLTEXT index ให้ตาราง books
 *
 * 🎯 ทำให้การค้นหาหนังสือใช้ index ได้ แทนที่จะสแกนทั้งตารางด้วย LIKE '%คำ%'
 *
 * 🧠 ทำไมไม่ FULLTEXT บน title/author ตรง ๆ:
 *    MySQL/MariaDB ตัดคำด้วยช่องว่าง ภาษาไทยไม่มีช่องว่าง → ทั้งชื่อเรื่องเป็น token เดียว
 *    ค้น "โปรแกรม" ใน "การเขียนโปรแกรม" จะ **ไม่เจอ** (ทดสอบยืนยันแล้ว)
 *    จึงเก็บคอลัมน์เงาที่ตัดเป็นชิ้นละ 3 ตัวอักษรแทน — ดู buildSearchTokens() ใน functions.php
 */

return function (PDO $pdo): string {
    $messages = [];

    // ── 1. คอลัมน์ ──
    $hasColumn = $pdo->query("SHOW COLUMNS FROM `books` LIKE 'search_tokens'")->rowCount() > 0;
    if (!$hasColumn) {
        // 📝 TEXT เพราะ trigram ยาวประมาณ 3 เท่าของข้อความต้นฉบับ
        //    NULL ได้ = "ยังไม่ได้สร้าง index" → rebuild_search_index.php ตามเก็บได้
        $pdo->exec("
            ALTER TABLE `books`
            ADD COLUMN `search_tokens` TEXT NULL
            COMMENT 'trigram ของ title+author+isbn สำหรับ FULLTEXT (สร้างโดย buildSearchTokens())'
            AFTER `isbn`
        ");
        $messages[] = 'เพิ่มคอลัมน์ search_tokens';
    } else {
        $messages[] = 'มีคอลัมน์ search_tokens อยู่แล้ว';
    }

    // ── 2. เติมข้อมูลให้แถวเดิม ──
    // 🧠 ต้องทำก่อนสร้าง index จะได้ไม่ต้อง rebuild index ซ้ำ
    //    ใช้ helper ตัวเดียวกับที่ใช้ตอนค้นหา — ถ้าคนละสูตรจะค้นไม่เจอ
    //    (migrate.php โหลด functions.php ให้แล้วตั้งแต่ต้นไฟล์)

    $update = $pdo->prepare("UPDATE books SET search_tokens = ? WHERE id = ?");
    $filled = 0;
    $lastId = 0;
    while (true) {
        // 📝 ไล่ตาม id ทีละ 500 แถว — ห้องสมุดหลักหมื่นเล่มจะได้ไม่กินแรมทีเดียวหมด
        $stmt = $pdo->prepare("
            SELECT id, title, author, isbn FROM books
            WHERE (search_tokens IS NULL OR search_tokens = '') AND id > ?
            ORDER BY id LIMIT 500
        ");
        $stmt->execute([$lastId]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            break;
        }
        foreach ($rows as $row) {
            $source = trim($row['title'] . ' ' . $row['author'] . ' ' . ($row['isbn'] ?? ''));
            $update->execute([buildSearchTokens($source), $row['id']]);
            $lastId = (int) $row['id'];
            $filled++;
        }
    }
    if ($filled > 0) {
        $messages[] = "สร้าง index ค้นหาให้หนังสือเดิม $filled เล่ม";
    }

    // ── 3. FULLTEXT index ──
    $hasIndex = $pdo->query("SHOW INDEX FROM `books` WHERE Key_name = 'ft_books_search'")->rowCount() > 0;
    if (!$hasIndex) {
        $pdo->exec("ALTER TABLE `books` ADD FULLTEXT KEY `ft_books_search` (`search_tokens`)");
        $messages[] = 'เพิ่ม FULLTEXT index (ft_books_search)';
    } else {
        $messages[] = 'มี FULLTEXT index อยู่แล้ว';
    }

    return implode(' · ', $messages);
};
