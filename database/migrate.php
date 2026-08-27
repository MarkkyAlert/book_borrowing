<?php

/**
 * Migration Runner — อัปเดตโครงสร้างฐานข้อมูลของระบบที่ติดตั้งไปแล้ว
 *
 * ==========================================================================
 * 🎯 ไฟล์นี้แก้ปัญหาอะไร?
 * ==========================================================================
 * `install.php` ใช้ได้แค่ตอนติดตั้งครั้งแรก — ระบบที่ลูกค้าใช้อยู่แล้วจะรันซ้ำไม่ได้
 * (มีไฟล์ล็อค `.installed` กันไว้ และรันซ้ำก็ไม่ปลอดภัยกับข้อมูลจริง)
 *
 * เดิมเวลาแก้ schema ต้องเขียนสคริปต์แยกแล้วบอกลูกค้าให้รันเอง โดยไม่มีอะไรจำว่า
 * ลูกค้ารายไหนรันอะไรไปแล้วบ้าง — พอมีหลายไฟล์เข้าจะเดาไม่ออกว่าใครอัปเดตถึงไหน
 *
 * ไฟล์นี้ทำให้: วางไฟล์ migration ใหม่ → ลูกค้ารันคำสั่งเดียว → ระบบรู้เองว่าเหลืออะไรต้องรัน
 *
 * 📌 การใช้งาน (CLI เท่านั้น):
 *   php database/migrate.php            รัน migration ที่ยังไม่ได้รัน
 *   php database/migrate.php --status   ดูว่ารันอะไรไปแล้ว/เหลืออะไร
 *   php database/migrate.php --baseline ทำเครื่องหมายว่ารันครบแล้วโดยไม่รันจริง
 *                                       (ใช้กับระบบที่เพิ่งติดตั้งใหม่ด้วย install.php)
 *
 * 🧠 วิธีเขียน migration ใหม่:
 *   1. สร้างไฟล์ใน database/migrations/ ตั้งชื่อ YYYY_MM_DD_NNNNNN_อธิบายสั้นๆ.php
 *      (ชื่อไฟล์เรียงตามลำดับเวลา ระบบรันไล่ตามชื่อ)
 *   2. ในไฟล์ `return function (PDO $pdo): string { ... };`
 *      คืนข้อความสรุปว่าทำอะไรไป
 *   3. **ต้องเขียนให้รันซ้ำได้** — เช็คก่อนเสมอว่าคอลัมน์/ตารางมีอยู่แล้วหรือยัง
 *   4. อัปเดต `install.php` + `database/schema.sql` ให้ตรงกันด้วย
 *      (ติดตั้งใหม่ต้องได้โครงสร้างเดียวกับที่ migrate แล้ว)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied — run migrations from the command line');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
// 🧠 โหลด helper ให้ migration ใช้ได้ (เช่น buildSearchTokens ตอน backfill)
//    ต้องโหลด **ก่อน** echo อะไรออกไป เพราะ functions.php เรียก startSession() ท้ายไฟล์
//    ถ้าโหลดทีหลังจะได้ warning "headers already sent" เต็มจอ
require_once __DIR__ . '/../includes/functions.php';

$MIGRATION_DIR = __DIR__ . '/migrations';
$opts          = getopt('', ['status', 'baseline']);
$showStatus    = isset($opts['status']);
$doBaseline    = isset($opts['baseline']);

$pdo = getDB();

// ── ตารางบันทึกว่ารัน migration ไหนไปแล้ว ──
// 🧠 สร้างเองอัตโนมัติ ไม่ต้องให้ลูกค้าทำอะไรก่อน
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `migration` VARCHAR(191) NOT NULL UNIQUE COMMENT 'ชื่อไฟล์ migration',
        `applied_at` DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

// ── รายชื่อ migration ทั้งหมด (เรียงตามชื่อไฟล์) ──
$files = glob($MIGRATION_DIR . '/*.php') ?: [];
sort($files);
$all = array_map('basename', $files);

$applied = $pdo->query("SELECT migration FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
$pending = array_values(array_diff($all, $applied));

echo "\n╔════════════════════════════════════════════════════════════╗\n";
echo "║  Database Migration — " . DB_NAME . str_repeat(' ', max(0, 36 - strlen(DB_NAME))) . "║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

// ── โหมด --status ──
if ($showStatus) {
    echo "  migration ทั้งหมด " . count($all) . " ไฟล์\n\n";
    foreach ($all as $m) {
        $isApplied = in_array($m, $applied, true);
        echo ($isApplied ? "  \033[32m✅ รันแล้ว\033[0m  " : "  \033[33m⏳ ยังไม่รัน\033[0m ") . $m . "\n";
    }
    echo "\n  สรุป: รันแล้ว " . count($applied) . " · เหลือ " . count($pending) . "\n\n";
    exit(0);
}

// ── โหมด --baseline ──
// 🧠 ใช้กับระบบที่เพิ่งติดตั้งด้วย install.php ซึ่งมีโครงสร้างล่าสุดอยู่แล้ว
//    ถ้าไม่ทำ baseline ระบบจะพยายามรัน migration เก่าซ้ำ (ซึ่งไม่พังเพราะเขียนให้รันซ้ำได้
//    แต่เสียเวลาและทำให้ log สับสน)
if ($doBaseline) {
    if (!$pending) {
        echo "  ไม่มี migration ค้าง — ไม่ต้องทำอะไร\n\n";
        exit(0);
    }
    $stmt = $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?)");
    foreach ($pending as $m) {
        $stmt->execute([$m]);
        echo "  📝 ทำเครื่องหมายว่ารันแล้ว (ไม่ได้รันจริง): $m\n";
    }
    echo "\n  ✅ baseline เสร็จ " . count($pending) . " รายการ\n\n";
    exit(0);
}

// ── รัน migration ที่ค้าง ──
if (!$pending) {
    echo "  ✅ ฐานข้อมูลเป็นเวอร์ชันล่าสุดแล้ว (รันไปแล้ว " . count($applied) . " รายการ)\n\n";
    exit(0);
}

echo "  พบ migration ที่ยังไม่ได้รัน " . count($pending) . " รายการ\n\n";

$record = $pdo->prepare("INSERT INTO schema_migrations (migration) VALUES (?)");
$ok     = 0;

foreach ($pending as $name) {
    echo "  ▶ $name\n";

    $migration = require $MIGRATION_DIR . '/' . $name;
    if (!is_callable($migration)) {
        echo "    \033[31m❌ ไฟล์นี้ไม่ได้ return closure — ข้าม\033[0m\n";
        echo "\n  หยุดการทำงาน แก้ไฟล์ให้ถูกต้องแล้วรันใหม่\n\n";
        exit(1);
    }

    try {
        // ⚠️ ไม่ครอบ transaction: MySQL ไม่รองรับ DDL (ALTER/CREATE) ใน transaction อยู่แล้ว
        //    (implicit commit) — migration แต่ละไฟล์จึงต้องรับผิดชอบความปลอดภัยของตัวเอง
        //    ด้วยการเช็คสถานะก่อนทำ และทำทีละขั้นที่ย้อนกลับได้
        $message = (string) $migration($pdo);
        $record->execute([$name]);
        $ok++;
        echo "    \033[32m✅\033[0m $message\n";
    } catch (Throwable $e) {
        echo "    \033[31m❌ ล้มเหลว: " . $e->getMessage() . "\033[0m\n";
        echo "\n  หยุดที่ไฟล์นี้ — migration ที่เหลือยังไม่ถูกรัน\n";
        echo "  แก้ปัญหาแล้วรัน `php database/migrate.php` ใหม่ ระบบจะรันต่อจากจุดที่ค้าง\n\n";
        exit(1);
    }
}

echo "\n  ✅ รันสำเร็จ $ok รายการ — ฐานข้อมูลเป็นเวอร์ชันล่าสุดแล้ว\n\n";
