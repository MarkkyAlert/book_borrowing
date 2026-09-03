<?php

/**
 * Rebuild Search Index — สร้าง `books.search_tokens` ใหม่ทั้งตาราง
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้มีไว้ทำไม?
 * ==========================================================================
 * การค้นหาหนังสือใช้ FULLTEXT บนคอลัมน์ `search_tokens` ซึ่ง **PHP เป็นคนสร้าง**
 * (ไม่ใช่ trigger ของฐานข้อมูล เพราะ stored function สร้างไม่ได้บนบาง server)
 *
 * ปกติ `BookRepository::create()` / `update()` จะเติมให้อัตโนมัติ
 * แต่ถ้าหนังสือถูกเพิ่มด้วยวิธีอื่น token จะว่าง → **ค้นหาไม่เจอเล่มนั้น**
 * เช่น:
 *   - import ข้อมูลด้วย SQL ตรง ๆ / restore backup จากระบบเวอร์ชันเก่า
 *   - `database/sample_data.sql`
 *   - แก้ข้อมูลผ่าน phpMyAdmin
 *   - เปลี่ยนสูตรใน `buildSearchTokens()` หรือค่า `SEARCH_TOKEN_SIZE`
 *
 * 📌 การใช้งาน (CLI เท่านั้น):
 *   php database/rebuild_search_index.php           สร้างใหม่เฉพาะเล่มที่ยังไม่มี token
 *   php database/rebuild_search_index.php --all     สร้างใหม่ทุกเล่ม (ใช้เมื่อเปลี่ยนสูตร)
 *   php database/rebuild_search_index.php --check   ตรวจอย่างเดียว ไม่แก้ (exit 1 ถ้าเจอที่ตกหล่น)
 *
 * 🧠 `--check` มีไว้ให้ชุดทดสอบเรียก — ถ้ามีเล่มตกหล่นจะได้รู้ทันที
 *    ไม่ใช่ไปเจอตอนลูกค้าบอกว่า "ค้นหาหนังสือเล่มนี้ไม่เจอ"
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied — run this from the command line');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$opts     = getopt('', ['all', 'check', 'quiet']);
$rebuildAll = isset($opts['all']);
$checkOnly  = isset($opts['check']);
$quiet      = isset($opts['quiet']);

$pdo = getDB();

/**
 * 🎯 สร้าง token ใหม่ให้หนังสือทีละชุด
 * 🧠 ทำเป็นชุดละ 500 แถว ไม่ดึงทั้งตารางมาไว้ใน memory
 *    (ห้องสมุดหลักหมื่นเล่มจะกินแรมเป็น GB ถ้าดึงทีเดียว)
 *
 * 📤 คืนจำนวนแถวที่แก้จริง
 */
function rebuildTokens(PDO $pdo, bool $all, bool $quiet): int
{
    $where  = $all ? '' : "WHERE search_tokens IS NULL OR search_tokens = ''";
    $update = $pdo->prepare("UPDATE books SET search_tokens = ? WHERE id = ?");
    $done   = 0;
    $lastId = 0;

    while (true) {
        // 📝 ไล่ตาม id เพิ่มขึ้นเรื่อย ๆ แทน OFFSET
        //    เพราะ UPDATE ทำให้เงื่อนไข WHERE เปลี่ยน → OFFSET จะข้ามแถวไป
        $glue = $where === '' ? 'WHERE' : 'AND';
        $stmt = $pdo->prepare("
            SELECT id, title, author, isbn, call_number
            FROM books
            {$where} {$glue} id > ?
            ORDER BY id
            LIMIT 500
        ");
        $stmt->execute([$lastId]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            break;
        }

        $pdo->beginTransaction();
        foreach ($rows as $row) {
            // 📝 รวม 4 คอลัมน์ที่การค้นหาครอบคลุม (ตรงกับ makeSearchTokens() ใน BookRepository)
            //    🔴 ต้องมี call_number ด้วย ไม่งั้นค้นเลขเรียกแบบ LC (PZ7.R79) ไม่เจอ
            $source = trim($row['title'] . ' ' . $row['author'] . ' '
                . ($row['isbn'] ?? '') . ' ' . ($row['call_number'] ?? ''));
            $update->execute([buildSearchTokens($source), $row['id']]);
            $lastId = (int) $row['id'];
            $done++;
        }
        $pdo->commit();

        if (!$quiet) {
            echo "\r  กำลังสร้าง index... $done เล่ม";
        }
    }

    if (!$quiet && $done > 0) {
        echo "\n";
    }
    return $done;
}

// ── ตรวจว่าคอลัมน์มีอยู่ก่อน ──
$hasColumn = $pdo->query("SHOW COLUMNS FROM `books` LIKE 'search_tokens'")->rowCount() > 0;
if (!$hasColumn) {
    echo "\n  ❌ ยังไม่มีคอลัมน์ `search_tokens` ในตาราง books\n";
    echo "     รัน `php database/migrate.php` ก่อน\n\n";
    exit(1);
}

$missing = (int) $pdo->query("SELECT COUNT(*) FROM books WHERE search_tokens IS NULL OR search_tokens = ''")->fetchColumn();
$total   = (int) $pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();

// ── โหมด --check: ตรวจอย่างเดียว ──
if ($checkOnly) {
    if ($missing === 0) {
        if (!$quiet) {
            echo "  ✅ index ค้นหาครบทุกเล่ม ($total เล่ม)\n";
        }
        exit(0);
    }
    echo "  ❌ มีหนังสือ $missing เล่มจาก $total ที่ยังไม่มี index ค้นหา — จะค้นหาไม่เจอ\n";
    echo "     แก้ด้วย: php database/rebuild_search_index.php\n";
    exit(1);
}

if (!$quiet) {
    echo "\n╔════════════════════════════════════════════════════════════╗\n";
    echo "║  Rebuild Search Index — books.search_tokens                ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n\n";
    echo "  หนังสือทั้งหมด $total เล่ม · ยังไม่มี index $missing เล่ม\n";
    echo $rebuildAll ? "  โหมด: สร้างใหม่ทุกเล่ม\n\n" : "  โหมด: เฉพาะเล่มที่ยังไม่มี\n\n";
}

$done = rebuildTokens($pdo, $rebuildAll, $quiet);

if (!$quiet) {
    echo $done > 0
        ? "\n  ✅ สร้าง index ค้นหาให้ $done เล่มแล้ว\n\n"
        : "  ✅ ไม่มีอะไรต้องทำ — index ครบอยู่แล้ว\n\n";
}
