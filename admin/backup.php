<?php

/**
 * ==========================================================================
 * 🎯 จุดประสงค์: สำรองข้อมูลทั้งฐานข้อมูลเป็นไฟล์ .sql ให้ดาวน์โหลด
 * ==========================================================================
 *
 * 🔴 ที่มา: UAT รอบ 3 ข้อ ต.3 — ระบบไม่มีปุ่มสำรองข้อมูลเลย
 *    ลูกค้าคือบรรณารักษ์ที่ดูแลห้องสมุดคนเดียว ไม่มีฝ่าย IT
 *    เอกสารเดิมบอกให้ไปใช้ mysqldump/phpMyAdmin เอง ซึ่งคนกลุ่มนี้ทำไม่ได้
 *    ถ้าคอมพัง = ประวัติการยืมทั้งหมดหายถาวร
 *
 * 🧠 ทำไมเขียน dump ด้วย PHP ล้วน ไม่เรียก mysqldump:
 *    โฮสต์จำนวนมากปิด exec()/shell_exec() และไม่ได้มี mysqldump ทุกที่
 *    ถ้าพึ่ง shell ลูกค้าบางรายกดแล้วพังโดยเราไม่รู้ตัว
 *    ฐานข้อมูลของระบบนี้ ~1.5 MB — วนอ่านด้วย PHP ไม่หนักเลย
 *
 * 🛡️ [SECURITY] ไฟล์นี้มีข้อมูลอ่อนไหวทั้งหมดของระบบ
 *    (hash รหัสผ่านทุกคน · อีเมล · เบอร์โทร · รหัสผ่าน SMTP ในตาราง settings)
 *    จึงต้องครบ 4 ชั้น:
 *      1. requireAdmin()      — เจ้าหน้าที่ธรรมดาก็ห้ามโหลด
 *      2. POST + CSRF         — กัน GET จากลิงก์ที่คนอื่นส่งมาหลอกให้กด
 *      3. ไม่เขียนลงดิสก์เลย  — สตรีมออกตรง ๆ ไม่งั้นไฟล์จะค้างในโฟลเดอร์เว็บ
 *                               แล้วใครเดา URL ถูกก็โหลดได้
 *      4. Cache-Control: no-store — กันเบราว์เซอร์/พร็อกซีเก็บสำเนาไว้
 */

require_once __DIR__ . '/../bootstrap.php';

// 🛡️ ชั้นที่ 1 — ผู้ดูแลระบบเท่านั้น
requireAdmin();

// 🛡️ ชั้นที่ 2 — ต้องมาจากการกดปุ่มในหน้าตั้งค่าเท่านั้น
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'คำขอสำรองข้อมูลไม่ถูกต้อง — กรุณากดปุ่มจากหน้าตั้งค่าระบบ');
    redirect(APP_URL . '/admin/settings.php');
}

$pdo = getDB();
$filename = 'backup_' . DB_NAME . '_' . date('Y-m-d_His') . '.sql';

// 🛡️ ชั้นที่ 3+4 — สตรีมออกเลย ไม่แตะดิสก์ และห้ามแคช
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$out = function (string $line): void {
    echo $line . "\n";
    // 🧠 ปล่อยออกทีละส่วน ไม่กองไว้ในหน่วยความจำจนเต็มเมื่อข้อมูลโต
    if (ob_get_level() === 0) {
        flush();
    }
};

$out('-- ============================================================');
$out('-- ไฟล์สำรองข้อมูล: ระบบยืมคืนหนังสือ');
$out('-- ฐานข้อมูล : ' . DB_NAME);
$out('-- สำรองเมื่อ : ' . date('d/m/Y H:i:s') . ' น.');
$out('-- ============================================================');
$out('--');
$out('-- 🔴 คำเตือน 1 — ไฟล์นี้มีข้อมูลส่วนตัวของสมาชิกทุกคน');
$out('--    (อีเมล เบอร์โทร รหัสผ่านที่เข้ารหัสไว้ และรหัสผ่านอีเมลของห้องสมุด)');
$out('--    เก็บไว้ในที่ปลอดภัย อย่าส่งต่อทางแชทหรืออีเมลโดยไม่ใส่รหัส');
$out('--');
$out('-- 🔴 คำเตือน 2 — การนำไฟล์นี้ไปกู้คืนจะ "ลบข้อมูลปัจจุบันทิ้งทั้งหมด"');
$out('--    แล้วแทนที่ด้วยข้อมูล ณ วันที่สำรอง ตรวจให้แน่ใจก่อนว่ากู้ถูกฐานข้อมูล');
$out('--');
$out('-- 📖 วิธีกู้คืน (ผ่าน phpMyAdmin):');
$out('--    1. เลือกฐานข้อมูล ' . DB_NAME . ' ทางซ้าย');
$out('--    2. กดแท็บ "นำเข้า" (Import) แล้วเลือกไฟล์นี้');
$out('--    3. กด "ลงมือ" (Go) แล้วรอจนขึ้นว่าสำเร็จ');
$out('-- ============================================================');
$out('');
$out('SET NAMES utf8mb4;');
$out('SET FOREIGN_KEY_CHECKS = 0;');
$out('SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";');
$out('');

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    $quoted = '`' . str_replace('`', '``', $table) . '`';

    $out('-- ------------------------------------------------------------');
    $out('-- ตาราง ' . $table);
    $out('-- ------------------------------------------------------------');
    $out('DROP TABLE IF EXISTS ' . $quoted . ';');

    $create = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM);
    $out($create[1] . ';');
    $out('');

    // 🧠 อ่านทีละแถวด้วย unbuffered cursor ไม่ดึงทั้งตารางขึ้น RAM
    $rows = $pdo->query('SELECT * FROM ' . $quoted);
    $count = 0;
    foreach ($rows as $row) {
        $cols = [];
        $vals = [];
        foreach ($row as $col => $val) {
            if (is_int($col)) {
                continue;   // PDO คืนทั้ง index และชื่อคอลัมน์ — เอาเฉพาะชื่อ
            }
            $cols[] = '`' . str_replace('`', '``', $col) . '`';
            // 🛡️ ใช้ quote() ของ PDO — จัดการ NULL / อัญประกาศ / ภาษาไทย ให้ถูกต้อง
            $vals[] = $val === null ? 'NULL' : $pdo->quote((string) $val);
        }
        $out('INSERT INTO ' . $quoted . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ');');
        $count++;
    }
    $out('-- ' . $table . ': ' . $count . ' แถว');
    $out('');
}

$out('SET FOREIGN_KEY_CHECKS = 1;');
$out('-- จบไฟล์สำรองข้อมูล');
exit;
