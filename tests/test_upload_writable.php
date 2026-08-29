<?php

/**
 * ตัวติดตั้งต้องบอกว่าโฟลเดอร์รูปปกเขียนไม่ได้ — F-54
 *
 * ==========================================================================
 * 🔴 ปัญหาเดิม
 * ==========================================================================
 * เจอเองตอนทดสอบ clone สด: ชุด Upload Security แดง 5 เคส เพราะ clone ใหม่
 * ไม่มีสิทธิ์ให้ web server เขียน `uploads/covers/`
 * ทำตามคำสั่งที่คู่มือเขียนไว้แล้วกลับเขียวทันที
 *
 * → **ตัวสินค้าไม่ได้พัง คู่มือก็ถูกและครบ**
 *   ช่องว่างคือ **ไม่มีอะไรบังคับให้ลูกค้าทำ และไม่มีอะไรบอกว่าลืมทำ**
 *
 * | จุด | สภาพเดิม |
 * |---|---|
 * | `install.php` | ค้น `is_writable` เจอ 0 ครั้ง — ไม่ตรวจอะไรเลย |
 * | `admin/book_form.php` | อัปโหลดล้มเหลว → แจ้งแค่ "ไม่สามารถอัปโหลดรูปภาพได้" |
 *
 * บรรณารักษ์จะเข้าใจว่า "รูปนี้มีปัญหา" แล้วลองรูปอื่นอีก 4-5 รูป
 * ทุกรูปล้มเหลวเหมือนกัน ทั้งที่ระบบรู้อยู่แล้วว่าโฟลเดอร์เขียนไม่ได้
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. ตัวตรวจสิทธิ์ทำงานถูกกับโฟลเดอร์จริงที่ถอดสิทธิ์แล้ว
 *    🔴 และต้อง **ไม่ทิ้งไฟล์ทดสอบไว้** ในโฟลเดอร์ลูกค้า
 * B. คำสั่งที่แนะนำต้องใช้ user ของ **web server** ไม่ใช่เจ้าของไฟล์
 * C. ทั้งตัวติดตั้งและหน้าเพิ่มหนังสือใช้ตัวตรวจเดียวกัน
 *    🔴 ตัวติดตั้งต้อง **เตือน ไม่ใช่ล้มเหลว**
 * D. 🔴 ต้องไม่ตรวจ `logs/` — web server ไม่ได้เขียนที่นั่น (INSTALL.md:269)
 *    ตรวจไปก็เป็นการเตือนเท็จ ทำให้ลูกค้าไปตั้งสิทธิ์ที่ไม่จำเป็น
 *
 * ⚠️ **ไม่เปิด install.php ในเบราว์เซอร์** ตามข้อห้ามของโปรเจกต์
 *    จึงทดสอบตรรกะผ่านฟังก์ชันกลางใน functions.php + อ่านซอร์สของ install.php
 *
 * 🧹 ลบโฟลเดอร์ชั่วคราวทั้งหมด
 *
 * 📌 การใช้งาน: php tests/test_upload_writable.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$ROOT = dirname(__DIR__);

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

$tmpDirs = [];
$cleanupDone = false;
$cleanup = function () use (&$tmpDirs, &$cleanupDone) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    echo "\n── CLEANUP ──\n";
    foreach ($tmpDirs as $d) {
        // คืนสิทธิ์ก่อนลบ ไม่งั้นลบไม่ออก
        @chmod($d, 0755);
        foreach (glob($d . '/*') ?: [] as $f) @unlink($f);
        foreach (glob($d . '/.*') ?: [] as $f) {
            if (!in_array(basename($f), ['.', '..'], true)) @unlink($f);
        }
        @rmdir($d);
    }
    $left = count(array_filter($tmpDirs, 'is_dir'));
    echo '  ลบโฟลเดอร์ชั่วคราว ' . (count($tmpDirs) - $left) . '/' . count($tmpDirs) . "\n";
    if ($left > 0) echo "  🔴 ลบไม่หมด ต้องลบมือ\n";
};
register_shutdown_function($cleanup);

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  ตัวติดตั้งต้องบอกว่าโฟลเดอร์รูปปกเขียนไม่ได้ (F-54)      ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

// ============================================================
// A. ตัวตรวจสิทธิ์
// ============================================================
echo "── A. ตัวตรวจสิทธิ์เขียน ──\n";

$writable = sys_get_temp_dir() . '/bb_w_' . bin2hex(random_bytes(4));
mkdir($writable, 0755, true);
$tmpDirs[] = $writable;

check('UPW-A1', isDirActuallyWritable($writable),
    'โฟลเดอร์ปกติ → บอกว่าเขียนได้',
    '🔴 โฟลเดอร์ปกติกลับบอกว่าเขียนไม่ได้ — จะเตือนเท็จใส่ลูกค้าทุกคน');

// A2 — 🔴 ถอดสิทธิ์เขียนจริง ๆ แล้วต้องจับได้
$blocked = sys_get_temp_dir() . '/bb_b_' . bin2hex(random_bytes(4));
mkdir($blocked, 0755, true);
$tmpDirs[] = $blocked;
chmod($blocked, 0555);   // อ่าน+เข้าได้ แต่เขียนไม่ได้
clearstatcache(true, $blocked);

// ⚠️ root เขียนได้ทุกที่ไม่ว่า permission จะเป็นอะไร — เคสนี้จะไม่มีความหมาย
$runningAsRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
if ($runningAsRoot) {
    echo "  ⏭️  รันเป็น root — ข้าม UPW-A2 (root เขียนได้ทุกที่ เคสนี้พิสูจน์อะไรไม่ได้)\n";
} else {
    check('UPW-A2', !isDirActuallyWritable($blocked),
        '🔴 โฟลเดอร์ที่ถอดสิทธิ์เขียนแล้ว → จับได้ว่าเขียนไม่ได้',
        '🔴 บอกว่าเขียนได้ทั้งที่ chmod 0555 — ตัวตรวจใช้ไม่ได้ '
            . 'ลูกค้าจะไม่ได้รับคำเตือนเลย');
}

check('UPW-A3', !isDirActuallyWritable($writable . '/ไม่มีจริง'),
    'โฟลเดอร์ที่ไม่มีอยู่ → บอกว่าเขียนไม่ได้ (ไม่พังด้วย error)',
    '🔴 โฟลเดอร์ที่ไม่มีอยู่กลับบอกว่าเขียนได้');

// A4 — 🔴 ห้ามทิ้งไฟล์ทดสอบไว้ในโฟลเดอร์ลูกค้า
for ($i = 0; $i < 5; $i++) {
    isDirActuallyWritable($writable);
}
$leftovers = array_merge(glob($writable . '/*') ?: [], glob($writable . '/.*probe*') ?: []);
check('UPW-A4', $leftovers === [],
    'เรียก 5 ครั้งแล้วไม่มีไฟล์ทดสอบตกค้าง',
    '🔴 ทิ้งไฟล์ไว้ ' . count($leftovers) . ' ไฟล์: ' . implode(', ', array_map('basename', $leftovers)));

// A4b — 🔴 ถ้า process ตายกลางทาง (timeout/fatal) ไฟล์จะค้าง — ครั้งถัดไปต้องเก็บกวาดให้
//       เจอจริงตอนพิสูจน์ฟันเคส A4: มีไฟล์ค้างใน uploads/covers/ ของจริง
//       ไฟล์เล็กและซ่อนอยู่ก็จริง แต่ไม่ควรทิ้งขยะไว้ในโฟลเดอร์ของลูกค้า
$stale = $writable . '/.write_probe_เศษค้างจากรอบก่อน';
file_put_contents($stale, 'x');
isDirActuallyWritable($writable);
check('UPW-A4b', !file_exists($stale),
    'ไฟล์ทดสอบที่ค้างจากรอบก่อนถูกเก็บกวาดให้อัตโนมัติ',
    '🔴 ไฟล์ค้างไม่ถูกลบ — ถ้า process ตายกลางทาง ขยะจะสะสมในโฟลเดอร์ลูกค้าไปเรื่อย ๆ');

// A5 — โฟลเดอร์รูปปกจริงของระบบต้องเขียนได้ (ถ้าไม่ได้ ระบบนี้ก็อัปโหลดไม่ได้จริง ๆ)
$realCovers = $ROOT . '/uploads/covers';
check('UPW-A5', isDirActuallyWritable($realCovers),
    'โฟลเดอร์ uploads/covers/ ของเครื่องนี้เขียนได้',
    '⚠️ เครื่องนี้เขียน uploads/covers/ ไม่ได้ — อัปโหลดรูปปกจะล้มเหลวจริง '
        . 'รันคำสั่งนี้: ' . writablePermissionHint('uploads/covers'));

// ============================================================
// B. คำสั่งที่แนะนำ
// ============================================================
echo "\n── B. คำสั่งที่แนะนำให้ลูกค้ารัน ──\n";

$hint = writablePermissionHint('uploads/covers');

check('UPW-B1', str_contains($hint, 'uploads/covers'),
    'คำสั่งระบุโฟลเดอร์ที่ต้องแก้',
    '🔴 คำสั่งไม่บอกว่าต้องแก้โฟลเดอร์ไหน: ' . $hint);

// B2 — 🔴 ต้องไม่มีตัวยึดที่ลืมแทนที่
check('UPW-B2', !str_contains($hint, '<user-ของ-web-server>'),
    'คำสั่งระบุชื่อ user ได้จริง ไม่เหลือตัวยึดให้ลูกค้าเดา',
    'หา user ของ web server ไม่ได้ในสภาพแวดล้อมนี้ → คำสั่งเหลือตัวยึด '
        . '(ยังดีกว่าใส่ชื่อผิด แต่ควรตรวจว่า posix extension มีไหม)');

// B3 — 🔴 ต้องเป็น user ที่ process รันอยู่ ไม่ใช่เจ้าของไฟล์
//      ใส่ชื่อผิด = ลูกค้าคัดคำสั่งไปรันแล้วยังเขียนไม่ได้ แต่คิดว่าทำแล้ว
if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
    $procUser = posix_getpwuid(posix_geteuid())['name'] ?? '';
    $fileOwner = get_current_user();
    check('UPW-B3', $procUser !== '' && str_contains($hint, $procUser),
        "คำสั่งใช้ user ที่ process รันอยู่ ({$procUser})",
        "🔴 คำสั่งไม่ได้ใช้ user ของ process ({$procUser}) — "
            . "ถ้าใช้เจ้าของไฟล์ ({$fileOwner}) แทน ลูกค้าจะรันแล้วยังเขียนไม่ได้: {$hint}");
} else {
    echo "  ⏭️  ไม่มี posix extension — ข้าม UPW-B3\n";
}

// B4 — คำสั่งต้องตรงกับที่คู่มือเขียน ไม่งั้นสองที่พูดคนละอย่าง
$installMd = (string) @file_get_contents($ROOT . '/docs/INSTALL.md');
$hintCore  = PHP_OS_FAMILY === 'Darwin' ? 'chmod +a' : 'chown';
check('UPW-B4', $installMd !== '' && str_contains($installMd, $hintCore),
    'คำสั่งบนหน้าจอใช้วิธีเดียวกับที่คู่มือแนะนำ',
    '🔴 หน้าจอกับคู่มือแนะนำคนละวิธี — ลูกค้าจะสับสนว่าต้องเชื่ออันไหน');

// ============================================================
// C. เอาไปใช้จริงทั้งสองที่
// ============================================================
echo "\n── C. ตัวติดตั้ง + หน้าเพิ่มหนังสือ ──\n";

$installSrc  = (string) file_get_contents($ROOT . '/install.php');
$bookFormSrc = (string) file_get_contents($ROOT . '/admin/book_form.php');

check('UPW-C1', str_contains($installSrc, 'isDirActuallyWritable'),
    'ตัวติดตั้งเรียกตัวตรวจสิทธิ์',
    '🔴 ตัวติดตั้งยังไม่ตรวจอะไรเลย — ลูกค้ารู้ตัวอีกทีตอนอัปโหลดรูปแล้วไม่ขึ้น');

check('UPW-C2', str_contains($bookFormSrc, 'isDirActuallyWritable'),
    'หน้าเพิ่มหนังสือใช้ตัวตรวจเดียวกัน — ตัดสินตรงกันเสมอ',
    '🔴 หน้าเพิ่มหนังสือยังไม่เช็ค');

// C3 — 🔴 ตัวติดตั้งต้อง **เตือน** ไม่ใช่ล้มเหลว
//      DB กับบัญชี admin สร้างเสร็จแล้ว ระบบใช้งานได้เกือบทุกอย่าง
//      ล้มการติดตั้งเพราะอัปโหลดรูปไม่ได้ = แย่กว่าปล่อยผ่านมาก
$installBlock = '';
if (preg_match('/isDirActuallyWritable\(\$coversDir\)\).*?\n\s*\}/s', $installSrc, $m)) {
    $installBlock = $m[0];
}
// แยกให้ชัดว่า "หาบล็อกไม่เจอ" กับ "บล็อกทำให้ติดตั้งล้ม" เป็นคนละปัญหา
$blockStops = $installBlock !== ''
    && preg_match('/\bthrow\b|\bexit\b|\bdie\b|\$success\s*=\s*false/', $installBlock) === 1;
check('UPW-C3', $installBlock !== '' && !$blockStops,
    'ตัวติดตั้งแค่เตือน ไม่หยุดการติดตั้ง (DB + บัญชี admin เสร็จแล้ว)',
    '🔴 ' . ($installBlock === ''
        ? 'หาบล็อกตรวจสิทธิ์ใน install.php ไม่เจอ (ดู UPW-C1)'
        : 'ตัวติดตั้งล้มเหลวเมื่อเขียนโฟลเดอร์ไม่ได้ — แย่กว่าปัญหาเดิม'));

// C4 — 🔴 หน้าเพิ่มหนังสือต้องเช็ค **ก่อน** move_uploaded_file
//      ถ้าเช็คทีหลังก็ยังบอกสาเหตุจริงไม่ได้
$posCheck = strpos($bookFormSrc, 'isDirActuallyWritable($uploadDir)');
$posMove  = strpos($bookFormSrc, 'move_uploaded_file($file');
check('UPW-C4', $posCheck !== false && $posMove !== false && $posCheck < $posMove,
    'เช็คสิทธิ์ก่อนลองย้ายไฟล์ — จึงบอกสาเหตุจริงได้',
    '🔴 เช็คหลัง move_uploaded_file → ยังบอกได้แค่ "อัปโหลดไม่สำเร็จ" เหมือนเดิม');

// C5 — ข้อความต้องบอกว่าไม่ใช่ปัญหาที่ไฟล์รูป
check('UPW-C5',
    str_contains($bookFormSrc, 'ไม่ใช่ปัญหาที่ไฟล์รูป'),
    'ข้อความบอกชัดว่าไม่ใช่ปัญหาที่รูป — บรรณารักษ์จะไม่ลองรูปอื่นซ้ำ ๆ',
    '🔴 ข้อความยังชี้ไปที่ไฟล์รูป ทำให้ลองรูปอื่นอีก 4-5 รูปโดยเปล่าประโยชน์');

// ============================================================
// D. ต้องไม่ตรวจ logs/
// ============================================================
echo "\n── D. ขอบเขตการตรวจ ──\n";

// 🔴 `logs/` ไม่ได้ถูกเขียนโดย web server — มีแต่ cron/*.php ซึ่งรันเป็น user คนละคน
//    ตรวจไปก็เป็นการเตือนเท็จ ทำให้ลูกค้าไปตั้งสิทธิ์ที่ไม่จำเป็น
$webWritesLogs = false;
foreach (glob($ROOT . '/{admin,includes,app/Services,app/Repositories}/*.php', GLOB_BRACE) as $f) {
    if (preg_match("#logs/#", (string) file_get_contents($f))) $webWritesLogs = true;
}
check('UPW-D1', !$webWritesLogs,
    'ยืนยันจากโค้ด: ไม่มีเส้นทางเว็บที่เขียน logs/ (มีแต่ cron)',
    'มีโค้ดฝั่งเว็บเขียน logs/ แล้ว → ต้องเพิ่ม logs/ เข้าไปในการตรวจของตัวติดตั้งด้วย');

check('UPW-D2', !preg_match("#isDirActuallyWritable\([^)]*logs#", $installSrc),
    'ตัวติดตั้งไม่ตรวจ logs/ — ไม่เตือนเท็จ',
    '🔴 ตรวจ logs/ ด้วย ทั้งที่ web server ไม่ได้เขียนที่นั่น (ดู INSTALL.md:269) '
        . '— ลูกค้าจะไปตั้งสิทธิ์ที่ไม่จำเป็น');

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
