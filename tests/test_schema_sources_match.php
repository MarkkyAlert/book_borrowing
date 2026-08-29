<?php

/**
 * ทดสอบว่า "3 แหล่งที่นิยามโครงสร้างฐานข้อมูล" ตรงกันเสมอ
 *
 * ==========================================================================
 * 🔴 ทำไมต้องมีไฟล์นี้
 * ==========================================================================
 * ระบบนี้มีทางติดตั้ง 3 ทาง และแต่ละทางนิยามโครงสร้างตารางแยกกัน:
 *
 *   1. install.php               — ติดตั้งผ่านหน้าเว็บ (CREATE TABLE ของตัวเอง)
 *   2. database/schema.sql       — import ด้วยมือ
 *   3. database/migrations/*.php — อัปเกรดระบบที่ติดตั้งไปแล้ว
 *
 * ถ้า 3 แหล่งนี้ห่างกันเมื่อไหร่ ลูกค้าจะได้ฐานข้อมูลไม่เหมือนกันตามทางที่เลือก
 *
 * 💣 และมันพังแบบเงียบมาก เพราะตอนจบ install.php จะ **baseline** —
 *    ทำเครื่องหมายว่า migration ทุกไฟล์ "รันแล้ว" โดยไม่รันจริง
 *    ถ้า install.php สร้างตารางแบบเก่า คอลัมน์ที่ขาดจะไม่มีวันถูกเติม
 *    เพราะ migrate.php เห็นว่ารันไปหมดแล้ว → ระบบพังตอนลูกค้าใช้จริง
 *    ไม่ใช่ตอนติดตั้ง
 *
 * 📌 เคยเกิดขึ้นจริง: ROADMAP ข้อ 1–3 (is_reference, fine_waived_*, renew_count)
 *    เข้าเฉพาะ schema.sql + migration แต่ลืม install.php ทั้ง 3 รอบ
 *    ชุดทดสอบเดิม 477 เคสจับไม่ได้เลย เพราะทุกเคสรันบน DB ที่ migrate มาแล้ว
 *
 * ==========================================================================
 * 🎯 วิธีทดสอบ
 * ==========================================================================
 * สร้างฐานข้อมูลเปล่า 2 อัน แล้วสร้างตารางด้วยคนละทาง:
 *   A) รัน CREATE TABLE ที่ดึงออกมาจาก install.php
 *   B) import schema.sql (เปลี่ยนชื่อ DB ให้ตรง) แล้วรัน migration ทุกไฟล์
 * จากนั้นเทียบ "ชื่อคอลัมน์ + ชนิด + null ได้ไหม + ค่า default" ทีละตาราง
 *
 * 🧹 ลบฐานข้อมูลชั่วคราวทิ้งทุกครั้ง แม้เทสต์จะตายกลางคัน
 *
 * 📌 การใช้งาน: php tests/test_schema_sources_match.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$results = ['passed' => 0, 'failed' => 0, 'total' => 0];

function pass(string $id, string $msg = 'OK'): void
{
    global $results;
    $results['total']++; $results['passed']++;
    echo "  \033[32m✅ $id\033[0m: $msg\n";
}

function fail(string $id, string $msg): void
{
    global $results;
    $results['total']++; $results['failed']++;
    echo "  \033[31m❌ $id\033[0m: $msg\n";
}

function check(string $id, bool $ok, string $okMsg, string $failMsg): void
{
    $ok ? pass($id, $okMsg) : fail($id, $failMsg);
}

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  โครงสร้าง DB จาก 3 แหล่งต้องตรงกัน                       ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

$ROOT    = dirname(__DIR__);
$DB_A    = 'bb_schema_chk_install';
$DB_B    = 'bb_schema_chk_sql';
// ⚠️ เพิ่มตารางใหม่ → **ต้องเพิ่มชื่อในลิสต์นี้ด้วย** ไม่งั้นชุดนี้จะผ่านแบบไม่ได้ตรวจอะไรเลย
//    (เจอเองตอนเพิ่ม closed_days: เทสต์เขียวทั้งที่ยังไม่ได้เทียบตารางใหม่เลย)
$TABLES  = ['users', 'categories', 'books', 'borrows', 'reservations', 'payments', 'settings', 'closed_days', 'password_resets', 'rate_limits'];

// 🧹 ลบฐานข้อมูลชั่วคราวเสมอ แม้เจอ fatal error กลางคัน
$cleanupDone = false;
$cleanup = function () use (&$cleanupDone, $DB_A, $DB_B) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    try {
        $root = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS);
        $root->exec("DROP DATABASE IF EXISTS `{$DB_A}`");
        $root->exec("DROP DATABASE IF EXISTS `{$DB_B}`");
        echo "\n── CLEANUP ──\n  ลบฐานข้อมูลชั่วคราว {$DB_A} / {$DB_B}\n";
    } catch (Throwable $e) {
        echo "\n⚠️  ลบฐานข้อมูลชั่วคราวไม่สำเร็จ: " . $e->getMessage() . "\n";
    }
};
register_shutdown_function($cleanup);

try {
    $root = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    echo "  ❌ ต่อฐานข้อมูลไม่ได้: " . $e->getMessage() . "\n";
    exit(1);
}

// ============================================================
// A. สร้างตารางตามที่ install.php เขียนไว้
// ============================================================
echo "── A. โครงสร้างจาก install.php ──\n";

$installSrc = file_get_contents($ROOT . '/install.php');

// 📝 ดึงเฉพาะ CREATE TABLE ที่อยู่ใน $pdo->exec("...") ของ install.php
//    ไม่ require ไฟล์เข้ามา เพราะ install.php มีทั้ง session/header/ตัวล็อค .installed
preg_match_all('/CREATE TABLE IF NOT EXISTS.*?ENGINE=InnoDB[^"\']*/s', $installSrc, $m);
$installStatements = $m[0] ?? [];

check(
    'SCHEMA-A1',
    count($installStatements) >= count($TABLES),
    'ดึง CREATE TABLE จาก install.php ได้ ' . count($installStatements) . ' คำสั่ง',
    'ดึง CREATE TABLE จาก install.php ได้แค่ ' . count($installStatements) . ' คำสั่ง (คาดว่าอย่างน้อย ' . count($TABLES) . ') — รูปแบบไฟล์เปลี่ยนไป ต้องแก้ตัวดึงในเทสต์นี้'
);

$root->exec("DROP DATABASE IF EXISTS `{$DB_A}`");
$root->exec("CREATE DATABASE `{$DB_A}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdoA = new PDO("mysql:host=" . DB_HOST . ";dbname={$DB_A};charset=utf8mb4", DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$installError = null;
try {
    $pdoA->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($installStatements as $sql) {
        $pdoA->exec($sql);
    }
    $pdoA->exec('SET FOREIGN_KEY_CHECKS = 1');
} catch (Throwable $e) {
    $installError = $e->getMessage();
}
check('SCHEMA-A2', $installError === null,
    'รัน CREATE TABLE ของ install.php ได้ครบโดยไม่มี error',
    'install.php สร้างตารางไม่ผ่าน: ' . $installError);

// ============================================================
// B. schema.sql + migration ทุกไฟล์
// ============================================================
echo "\n── B. โครงสร้างจาก schema.sql + migration ──\n";

$root->exec("DROP DATABASE IF EXISTS `{$DB_B}`");
$root->exec("CREATE DATABASE `{$DB_B}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

// 📝 schema.sql ฝัง CREATE DATABASE / USE `book_borrowing` ไว้ตายตัว
//    ต้องเปลี่ยนชื่อให้ชี้ DB ชั่วคราว ไม่งั้นคำสั่งจะวิ่งเข้าฐานข้อมูลจริง
$schemaSql = file_get_contents($ROOT . '/database/schema.sql');
$schemaSql = str_replace('`book_borrowing`', "`{$DB_B}`", $schemaSql);

$pdoB = new PDO("mysql:host=" . DB_HOST . ";dbname={$DB_B};charset=utf8mb4", DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$schemaError = null;
try {
    $pdoB->exec($schemaSql);
} catch (Throwable $e) {
    $schemaError = $e->getMessage();
}
check('SCHEMA-B1', $schemaError === null,
    'import schema.sql ได้โดยไม่มี error',
    'schema.sql พัง: ' . $schemaError);

// 📝 รัน migration ทุกไฟล์ทับลงไป — ต้องรันซ้ำได้โดยไม่พัง (idempotent)
$migrationFiles = glob($ROOT . '/database/migrations/*.php') ?: [];
sort($migrationFiles);
$migrationError = null;
foreach ($migrationFiles as $file) {
    try {
        $fn = require $file;
        if (is_callable($fn)) $fn($pdoB);
    } catch (Throwable $e) {
        $migrationError = basename($file) . ': ' . $e->getMessage();
        break;
    }
}
check('SCHEMA-B2', $migrationError === null,
    'รัน migration ทั้ง ' . count($migrationFiles) . ' ไฟล์ทับ schema.sql ได้ (พิสูจน์ว่ารันซ้ำได้)',
    'migration พังเมื่อรันทับ schema.sql: ' . $migrationError);

// ============================================================
// C. เทียบคอลัมน์ทีละตาราง
// ============================================================
echo "\n── C. เทียบคอลัมน์ทีละตาราง ──\n";

/**
 * ดึงนิยามคอลัมน์แบบเทียบกันได้ (ไม่สนใจลำดับคอลัมน์)
 *
 * 🧠 เทียบ COMMENT ด้วย ทั้งที่ COMMENT ไม่มีผลต่อการทำงาน
 *    เพราะมันคือที่เดียวที่บอกว่าคอลัมน์นั้น**มีไว้ทำอะไร** — คนที่เปิด DB ดู
 *    ต้องเห็นคำอธิบายเดียวกันไม่ว่าลูกค้าจะติดตั้งผ่านหน้าเว็บหรือ import schema.sql
 *
 * 🔴 ตอนเปิดการเทียบนี้ครั้งแรก **แดงทันที 30 คอลัมน์** — install.php เขียน COMMENT
 *    ไว้แค่ 5 จุด ส่วน schema.sql เขียนไว้เกือบทุกคอลัมน์ สะสมมานานโดยไม่มีใครเห็น
 *    เพราะเทียบเฉพาะชนิดข้อมูลจะผ่านฉลุย
 *
 * ⚠️ ตรวจแค่ "แหล่งที่ส่งมอบ" ตรงกันไหม — ไม่ได้ตรวจ DB ของลูกค้าที่ติดตั้งไปแล้ว
 *    ลูกค้าเก่ายังมี COMMENT ว่างอยู่ และ**จงใจไม่เขียน migration ไปตามแก้**
 *    เพราะ MariaDB แก้ COMMENT ต้องใช้ MODIFY COLUMN ซึ่งต้องเขียนนิยามคอลัมน์ใหม่ทั้งบรรทัด
 *    พิมพ์ผิดตัวเดียว (VARCHAR(100) → VARCHAR(10)) = ข้อมูลลูกค้าถูกตัดถาวร
 *    ไม่คุ้มกับการได้คำอธิบาย
 */
$describe = function (PDO $pdo, string $db, string $table): array {
    $stmt = $pdo->prepare("
        SELECT column_name, column_type, is_nullable, column_default, column_comment
        FROM information_schema.columns
        WHERE table_schema = ? AND table_name = ?
        ORDER BY column_name
    ");
    $stmt->execute([$db, $table]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[strtolower($r['column_name'])] = strtolower($r['column_type'])
            . '|' . $r['is_nullable']
            . '|' . ($r['column_default'] ?? 'NULL')
            . '|' . trim((string) $r['column_comment']);
    }
    return $out;
};

foreach ($TABLES as $i => $table) {
    $a = $describe($pdoA, $DB_A, $table);
    $b = $describe($pdoB, $DB_B, $table);
    $id = 'SCHEMA-C' . ($i + 1);

    if (!$a && !$b) {
        fail($id, "ตาราง {$table} ไม่มีทั้ง 2 ฝั่ง");
        continue;
    }

    $missingInInstall = array_diff_key($b, $a);   // schema.sql มี แต่ install.php ไม่มี ← อันตรายที่สุด
    $missingInSchema  = array_diff_key($a, $b);
    $different        = [];
    foreach (array_intersect_key($a, $b) as $col => $defA) {
        if ($defA !== $b[$col]) {
            $different[] = "{$col} (install.php: {$defA} / schema.sql: {$b[$col]})";
        }
    }

    if (!$missingInInstall && !$missingInSchema && !$different) {
        pass($id, "{$table} — ตรงกันทั้ง " . count($a) . " คอลัมน์");
        continue;
    }

    $msg = "{$table} ไม่ตรงกัน:";
    if ($missingInInstall) {
        $msg .= "\n       🔴 install.php ขาด: " . implode(', ', array_keys($missingInInstall))
              . "\n          → ลูกค้าที่ติดตั้งผ่านหน้าเว็บจะได้ DB ที่ขาดคอลัมน์นี้"
              . " และ migrate.php จะไม่เติมให้ เพราะ install.php baseline ไปแล้ว";
    }
    if ($missingInSchema) {
        $msg .= "\n       ⚠️  schema.sql ขาด: " . implode(', ', array_keys($missingInSchema));
    }
    if ($different) {
        $msg .= "\n       ⚠️  นิยามต่างกัน: " . implode(' · ', $different);
    }
    fail($id, $msg);
}

// ============================================================
// D. ค่าใน ENUM ต้องตรงกันด้วย (ENUM ต่างกันแต่ชื่อคอลัมน์เหมือนกัน = จับยาก)
// ============================================================
echo "\n── D. ค่าใน ENUM ──\n";

$enumCols = [['borrows', 'status'], ['reservations', 'status'], ['users', 'role']];
foreach ($enumCols as $i => [$table, $col]) {
    $get = function (PDO $pdo, string $db) use ($table, $col) {
        $stmt = $pdo->prepare("
            SELECT column_type FROM information_schema.columns
            WHERE table_schema = ? AND table_name = ? AND column_name = ?
        ");
        $stmt->execute([$db, $table, $col]);
        return strtolower((string) $stmt->fetchColumn());
    };
    $ta = $get($pdoA, $DB_A);
    $tb = $get($pdoB, $DB_B);
    check('SCHEMA-D' . ($i + 1), $ta !== '' && $ta === $tb,
        "{$table}.{$col} → {$ta}",
        "{$table}.{$col} ต่างกัน — install.php: {$ta} / schema.sql: {$tb}");
}

// ============================================================
// E2. Foreign key + index ต้องตรงกันด้วย
// ============================================================
echo "\n── E. Foreign key และ index ──\n";

// 🧠 เทียบแค่คอลัมน์ไม่พอ — เคยเจอว่า schema.sql ขาด FK ที่ migration เพิ่มให้
//    ผลคือคนที่ import schema.sql ตรง ๆ ได้ DB ที่ลบ user แล้วข้อมูลไม่ถูกล้าง/ตั้ง NULL
// 🧠 เทียบเฉพาะตารางของแอป — ไม่รวม schema_migrations ซึ่งเป็นตารางบันทึกเวอร์ชัน
//    ที่ install.php กับ migrate.php สร้างตอนรันจริง ไม่ใช่โครงสร้างข้อมูลของระบบ
$tableList = "'" . implode("','", $TABLES) . "'";

$constraintsOf = function (PDO $pdo, string $db) use ($tableList): array {
    $stmt = $pdo->prepare("
        SELECT tc.table_name, tc.constraint_name, tc.constraint_type,
               COALESCE(GROUP_CONCAT(kcu.column_name ORDER BY kcu.ordinal_position), '') AS cols
        FROM information_schema.table_constraints tc
        LEFT JOIN information_schema.key_column_usage kcu
               ON kcu.constraint_schema = tc.table_schema
              AND kcu.constraint_name   = tc.constraint_name
              AND kcu.table_name        = tc.table_name
        WHERE tc.table_schema = ? AND tc.table_name IN ({$tableList})
        GROUP BY tc.table_name, tc.constraint_name, tc.constraint_type
    ");
    $stmt->execute([$db]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        // 🧠 ข้ามชื่อที่ MySQL ตั้งให้เอง (borrows_ibfk_1 ฯลฯ) — เทียบด้วย ตาราง+ชนิด+คอลัมน์แทน
        //    ไม่งั้นลำดับการประกาศต่างกันนิดเดียวก็เลขไม่ตรงแล้ว
        $key = $r['table_name'] . '|' . $r['constraint_type'] . '|' . $r['cols'];
        $out[$key] = true;
    }
    return $out;
};

$ca = $constraintsOf($pdoA, $DB_A);
$cb = $constraintsOf($pdoB, $DB_B);
$missA = array_diff_key($cb, $ca);
$missB = array_diff_key($ca, $cb);

if (!$missA && !$missB) {
    pass('SCHEMA-E1', 'FK / UNIQUE / CHECK ตรงกันทั้ง ' . count($ca) . ' รายการ');
} else {
    $msg = 'constraint ไม่ตรงกัน:';
    if ($missA) $msg .= "\n       🔴 install.php ขาด: " . implode(', ', array_keys($missA));
    if ($missB) $msg .= "\n       🔴 schema.sql ขาด: " . implode(', ', array_keys($missB));
    fail('SCHEMA-E1', $msg);
}

$indexesOf = function (PDO $pdo, string $db) use ($tableList): array {
    $stmt = $pdo->prepare("
        SELECT table_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols, non_unique
        FROM information_schema.statistics
        WHERE table_schema = ? AND table_name IN ({$tableList})
        GROUP BY table_name, index_name, non_unique
    ");
    $stmt->execute([$db]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['table_name'] . '|' . $r['cols'] . '|' . $r['non_unique']] = true;
    }
    return $out;
};

$ia = $indexesOf($pdoA, $DB_A);
$ib = $indexesOf($pdoB, $DB_B);
$missIA = array_diff_key($ib, $ia);
$missIB = array_diff_key($ia, $ib);

if (!$missIA && !$missIB) {
    pass('SCHEMA-E2', 'index ตรงกันทั้ง ' . count($ia) . ' รายการ');
} else {
    $msg = 'index ไม่ตรงกัน:';
    if ($missIA) $msg .= "\n       ⚠️  install.php ขาด: " . implode(', ', array_keys($missIA));
    if ($missIB) $msg .= "\n       ⚠️  schema.sql ขาด: " . implode(', ', array_keys($missIB));
    fail('SCHEMA-E2', $msg);
}

// ============================================================
// F. install.php ต้อง baseline migration ครบทุกไฟล์
// ============================================================
echo "\n── F. ตัว baseline ของ install.php ──\n";

// 🧠 install.php ทำเครื่องหมายว่า migration ทุกไฟล์รันแล้วโดยไม่รันจริง
//    ซึ่งถูกต้อง **ก็ต่อเมื่อ** ตารางที่มันสร้างเป็นโครงสร้างล่าสุดจริง
//    เทสต์ C/D ด้านบนคือตัวที่ค้ำเงื่อนไขนั้นไว้
check('SCHEMA-F1',
    str_contains($installSrc, 'schema_migrations') && str_contains($installSrc, 'INSERT IGNORE INTO schema_migrations'),
    'install.php ยัง baseline migration ให้อัตโนมัติ',
    'install.php ไม่ baseline แล้ว — ระบบที่ติดตั้งใหม่จะพยายามรัน migration เก่าซ้ำ');

$cleanup();

// ============================================================
// SUMMARY
// ============================================================
$pct = $results['total'] > 0 ? round($results['passed'] / $results['total'] * 100, 1) : 0;
echo "\n══════════════════════════════════════\n";
echo " RESULTS: {$results['passed']}/{$results['total']} passed ($pct%)";
if ($results['failed'] > 0) echo " | {$results['failed']} FAILED";
echo "\n══════════════════════════════════════\n\n";

exit($results['failed'] > 0 ? 1 : 0);
