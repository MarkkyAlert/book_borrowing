<?php

/**
 * ทดสอบคำศัพท์บนหน้าจอ + การระบุตัวสมาชิก — F-46 + F-51
 *
 * ==========================================================================
 * 🔴 ปัญหาเดิม
 * ==========================================================================
 * F-46 — สถานะ "จองแล้วรอมารับ" เรียกต่างกัน 3 ที่
 *          แท็บกรอง admin/reservations.php → "รออนุมัติ"
 *          ป้ายในตาราง + การ์ด Dashboard   → "รอรับของ"
 *          my_reservations.php ฝั่งสมาชิก  → "รอดำเนินการ"
 *        + คำที่บรรณารักษ์ไทยไม่ใช้: "รอรับของ" (ห้องสมุดไม่มี "ของ") ·
 *          "Quick Scan Enabled" · เมนู "ประวัติการเงิน" (เนื้อในคือค่าปรับ) ·
 *          เมนู "ผู้ใช้" (ห้องสมุดเรียก "สมาชิก") · "หนังสือใกล้หมด Stock" ·
 *          "Dashboard" · ปุ่ม "CSV" (ไม่บอกว่าได้อะไร)
 *
 * F-51 — ดรอปดาวน์เลือกผู้ยืมแสดง `ชื่อ (อีเมล)` เท่านั้น
 *        ชื่อซ้ำกันเป๊ะมี 2 คน เจ้าหน้าที่ต้องถามอีเมลซึ่งสมาชิกจำไม่ได้
 *        ทั้งที่ระบบมีรหัสสมาชิกและพิมพ์ลงบัตรพร้อมบาร์โค้ดอยู่แล้ว
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. สถานะเดียวกันต้องใช้คำเดียวกันทุกหน้า
 * B. 🔴 waiting กับ pending ต้องยังแยกจากกัน — การรวมคำต้องไม่ทำให้กลืนกัน
 * C. ไม่มีคำอังกฤษ/คำร้านค้าที่ผู้ใช้เห็น
 * D. ดรอปดาวน์ผู้ยืมแสดงรหัสสมาชิก และชื่อซ้ำแยกออกจากกันได้
 *
 * ⚠️ สิ่งที่เทสต์นี้ตรวจไม่ได้: การพิมพ์รหัสแล้วค้นเจอจริงใน select2
 *    (ต้องรัน JS) — ตรวจด้วยเบราว์เซอร์แยกตอนพัฒนา บันทึกผลใน FINDINGS
 *    เทสต์นี้คุมได้ว่า "รหัสอยู่ในข้อความของ option" ซึ่งเป็นเงื่อนไขที่ทำให้ค้นเจอ
 *
 * 🧹 ลบสมาชิกทดสอบที่สร้างขึ้น
 *
 * 📌 การใช้งาน: php tests/test_wording.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$BASE_URL       = rtrim(APP_URL, '/');
$ADMIN_EMAIL    = 'admin@library.com';
$ADMIN_PASSWORD = $argv[1] ?? '123456';

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

$pdo    = getDB();
$COOKIE = tempnam(sys_get_temp_dir(), 'bbword');

$twins = [];
$cleanupDone = false;
$cleanup = function () use (&$twins, &$cleanupDone, $pdo, $COOKIE) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    echo "\n── CLEANUP ──\n";
    try {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($twins) {
            $pdo->exec("DELETE FROM users WHERE id IN (" . implode(',', array_map('intval', $twins)) . ")");
        }
        echo '  ลบสมาชิกทดสอบ ' . count($twins) . " คน\n";
    } catch (Throwable $e) {
        echo '  ⚠️ ล้างข้อมูลไม่ครบ: ' . $e->getMessage() . "\n";
    }
    @unlink($COOKIE);
};
register_shutdown_function($cleanup);

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  คำศัพท์บนหน้าจอ + การระบุตัวสมาชิก (F-46 + F-51)         ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

/** ตัดคอมเมนต์ PHP/HTML และ script ออก — เหลือแต่สิ่งที่ผู้ใช้เห็นจริง */
function visibleOnly(string $html): string
{
    $html = preg_replace('/<!--.*?-->/s', '', $html);
    $html = preg_replace('/<script.*?<\/script>/s', '', $html);
    return $html;
}

/** อ่านไฟล์ source แล้วตัดคอมเมนต์ออก — ใช้ตรวจว่าคำเก่าไม่เหลือในโค้ดที่ทำงาน */
function sourceWithoutComments(string $path): string
{
    $src = (string) file_get_contents($path);
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $out .= is_array($tok) ? $tok[1] : $tok;
    }
    // ตัดคอมเมนต์ HTML ที่อยู่ใน inline HTML ด้วย
    return preg_replace('/<!--.*?-->/s', '', $out);
}

function http(string $method, string $url, array $fields = []): array
{
    global $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $COOKIE,
        CURLOPT_COOKIEFILE     => $COOKIE,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'code' => $code];
}

function csrfFrom(string $html): string
{
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

$login = http('GET', "$BASE_URL/login.php");
$r = http('POST', "$BASE_URL/login.php", [
    'csrf_token' => csrfFrom($login['body']),
    'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD,
]);

if (!str_contains($r['body'], 'ออกจากระบบ') && !str_contains($r['body'], 'logout')) {
    fail('WORD-A1', 'ล็อกอินไม่สำเร็จ — ข้ามการทดสอบ (ส่งรหัสผ่าน admin เป็น argument)');
    $cleanup();
    exit(1);
}

// ============================================================
// A. คำเดียวกันทุกหน้า
// ============================================================
echo "── A. สถานะเดียวกันต้องใช้คำเดียวกัน ──\n";

// A1 — 🔴 คำเก่าทั้ง 3 ต้องไม่เหลือในโค้ดที่ทำงาน (ไม่นับคอมเมนต์)
$oldTerms = ['รอรับของ', 'รออนุมัติ'];
$leftover = [];
foreach (glob(__DIR__ . '/../{admin,includes,app/Services,app/Repositories}/*.php', GLOB_BRACE) as $f) {
    $clean = sourceWithoutComments($f);
    foreach ($oldTerms as $t) {
        if (str_contains($clean, $t)) $leftover[] = basename($f) . ": {$t}";
    }
}
foreach (['my_reservations.php', 'profile.php'] as $f) {
    $clean = sourceWithoutComments(__DIR__ . '/../' . $f);
    foreach ($oldTerms as $t) {
        if (str_contains($clean, $t)) $leftover[] = "{$f}: {$t}";
    }
}
check('WORD-A1', $leftover === [],
    'ไม่มีคำเก่า (รอรับของ / รออนุมัติ) เหลือในโค้ดที่ทำงาน',
    '🔴 ยังเหลือ: ' . implode(' · ', $leftover));

// A2 — 🔴 หน้าที่แสดงสถานะเดียวกัน ต้องใช้คำเดียวกัน
$pages = [
    'ฝั่งเจ้าหน้าที่ (รายการจอง)' => "$BASE_URL/admin/reservations.php",
    'ฝั่งเจ้าหน้าที่ (ภาพรวม)'    => "$BASE_URL/admin/index.php",
];
$missingTerm = [];
foreach ($pages as $label => $url) {
    $body = visibleOnly(http('GET', $url)['body']);
    if (!str_contains($body, 'รอมารับ')) $missingTerm[] = $label;
}
check('WORD-A2', $missingTerm === [],
    'ทุกหน้าฝั่งเจ้าหน้าที่ใช้คำว่า "รอมารับ" เหมือนกัน',
    '🔴 ไม่พบคำมาตรฐานใน: ' . implode(', ', $missingTerm));

// A3 — badge กลางของระบบ
check('WORD-A3',
    str_contains(getReservationStatusLabel('pending'), 'รอมารับ'),
    'ป้ายสถานะกลางของระบบใช้คำว่า "รอมารับ"',
    '🔴 ป้ายกลางยังใช้คำเก่า: ' . strip_tags(getReservationStatusLabel('pending')));

// A4 — 🔴 ทุกตารางแปลสถานะในโค้ดต้องใช้คำเดียวกัน (กันคำแตกกลับมาอีกในอนาคต)
//      หน้าฝั่งสมาชิกแปลสถานะเอง ไม่ได้เรียก badge กลาง จึงเป็นจุดที่คำแตกได้ง่ายที่สุด
$labelMaps = [];
$scan = array_merge(
    glob(__DIR__ . '/../{admin,includes}/*.php', GLOB_BRACE),
    glob(__DIR__ . '/../*.php')
);
foreach ($scan as $f) {
    if (preg_match_all("/'pending'\\s*=>\\s*'([^']*)'/u", sourceWithoutComments($f), $mm)) {
        foreach ($mm[1] as $val) {
            // ค่าบางที่เป็น badge HTML — เทียบเฉพาะข้อความที่คนอ่านจริง
            $val = trim(strip_tags($val));
            // ข้ามค่าที่เป็นคลาส CSS / ไอคอน — สนใจเฉพาะข้อความไทย
            if (preg_match('/[ก-๙]/u', $val)) $labelMaps[$val][] = basename($f);
        }
    }
}
$variants = array_keys($labelMaps);
check('WORD-A4', count($variants) <= 1,
    'ทุกตารางแปลสถานะ pending ในโค้ดใช้คำเดียวกัน: "' . ($variants[0] ?? '—') . '" (พบ ' . count($labelMaps[$variants[0] ?? ''] ?? []) . ' แห่ง)',
    '🔴 คำแตกอีกแล้ว ' . count($variants) . ' แบบ: ' . implode(' · ', array_map(
        fn($v) => "\"{$v}\" (" . implode(', ', array_unique($labelMaps[$v])) . ')', $variants)));

// ============================================================
// B. waiting กับ pending ต้องไม่กลืนกัน
// ============================================================
echo "\n── B. waiting กับ pending ต้องยังแยกจากกัน ──\n";

$pendingLabel = strip_tags(getReservationStatusLabel('pending'));
$waitingLabel = strip_tags(getReservationStatusLabel('waiting'));

check('WORD-B1',
    $pendingLabel !== $waitingLabel && $waitingLabel !== '',
    "แยกกันชัด — pending = \"{$pendingLabel}\" · waiting = \"{$waitingLabel}\"",
    "🔴 สองสถานะใช้คำเดียวกัน (\"{$pendingLabel}\") — การรวมคำทำให้กลืนกัน "
        . 'ซึ่งสร้างความสับสนแบบใหม่แทนที่จะแก้');

// B2 — ความหมายต่างกันมาก: อันหนึ่งกินสต็อก อีกอันไม่กิน คำต้องสื่อได้
check('WORD-B2',
    str_contains($waitingLabel, 'คิว'),
    "คำของ waiting สื่อว่าเป็นการต่อคิว (\"{$waitingLabel}\") ไม่ใช่ของพร้อมแล้ว",
    "🔴 คำของ waiting ไม่สื่อ: \"{$waitingLabel}\"");

// ============================================================
// C. คำอังกฤษ / คำร้านค้า
// ============================================================
echo "\n── C. คำที่ผู้ใช้ไม่ควรเห็น ──\n";

$adminPages = [
    'index' => "$BASE_URL/admin/index.php",
    'borrow_form' => "$BASE_URL/admin/borrow_form.php",
    'reports' => "$BASE_URL/admin/reports.php",
    'members' => "$BASE_URL/admin/members.php",
];
$banned = [
    'Quick Scan'      => 'ควรเป็น "เปิดโหมดสแกน"',
    'ประวัติการเงิน'  => 'ควรเป็น "ค่าปรับ"',
    'ใกล้หมด Stock'   => 'ไทยปนอังกฤษ',
    'สินค้าหมด'       => 'คำของร้านค้า ห้องสมุดไม่มีสินค้า',
];
$found = [];
foreach ($adminPages as $name => $url) {
    $body = visibleOnly(http('GET', $url)['body']);
    foreach ($banned as $word => $why) {
        if (str_contains($body, $word)) $found[] = "{$name}: {$word} ({$why})";
    }
}
check('WORD-C1', $found === [],
    'ไม่มีคำอังกฤษ/คำร้านค้าที่ผู้ใช้เห็นบน ' . count($adminPages) . ' หน้าหลัก',
    '🔴 ยังพบ: ' . implode(' · ', $found));

// C2 — เมนูหลักต้องเป็นภาษาไทย
$menu = visibleOnly(http('GET', "$BASE_URL/admin/index.php")['body']);
$menuMissing = [];
foreach (['ภาพรวม', 'สมาชิก', 'ค่าปรับ'] as $m) {
    if (!preg_match('/<span>\s*' . preg_quote($m, '/') . '\s*<\/span>/u', $menu)) $menuMissing[] = $m;
}
check('WORD-C2', $menuMissing === [],
    'เมนูหลักเป็นภาษาไทยครบ (ภาพรวม · สมาชิก · ค่าปรับ)',
    '🔴 เมนูยังไม่เป็นไทย: ' . implode(', ', $menuMissing));

// C3 — ปุ่มส่งออกต้องบอกว่าได้อะไร
$rep = visibleOnly(http('GET', "$BASE_URL/admin/reports.php")['body']);
check('WORD-C3',
    str_contains($rep, 'บันทึกเป็นไฟล์ Excel'),
    'ปุ่มส่งออกบอกว่ากดแล้วได้อะไร ไม่ใช่แค่ "CSV"',
    '🔴 ปุ่มยังเขียนว่า CSV เฉย ๆ — ไม่บอกว่าได้ไฟล์อะไร');

// ============================================================
// D. ดรอปดาวน์ผู้ยืม (F-51)
// ============================================================
echo "\n── D. ดรอปดาวน์เลือกผู้ยืม ──\n";

// 👥 สร้างสมาชิกชื่อซ้ำกันเป๊ะ 2 คน — เคสที่ F-51 ยกมา
$twinName = '[WORDTEST] ธนกฤต ทดสอบซ้ำ';
for ($i = 1; $i <= 2; $i++) {
    $st = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'member')");
    $st->execute([$twinName, "wordtest_{$i}_" . time() . rand(100, 999) . '@test.com', password_hash('x', PASSWORD_DEFAULT)]);
    $twins[] = (int) $pdo->lastInsertId();
}
echo '  👥 สร้างสมาชิกชื่อซ้ำกันเป๊ะ 2 คน: #' . implode(' และ #', $twins) . "\n";

$form = http('GET', "$BASE_URL/admin/borrow_form.php")['body'];
preg_match('/id="user_id".*?<\/select>/s', $form, $selM);
$selectHtml = $selM[0] ?? '';

// ดึงข้อความของ option ทั้งสองคน
$labels = [];
foreach ($twins as $id) {
    if (preg_match('/<option value="' . $id . '"[^>]*>\s*(.*?)\s*<\/option>/s', $selectHtml, $om)) {
        $labels[$id] = trim(preg_replace('/\s+/', ' ', $om[1]));
    }
}

check('WORD-D1', count($labels) === 2,
    'เจอสมาชิกทั้ง 2 คนในดรอปดาวน์',
    '🔴 หาไม่เจอครบ — เจอ ' . count($labels) . ' จาก 2');

// D2 — 🔴 ต้องมีรหัสสมาชิกในข้อความ (เงื่อนไขที่ทำให้พิมพ์รหัสค้นเจอ)
$noCode = [];
foreach ($labels as $id => $label) {
    $code = str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    if (!str_contains($label, $code)) $noCode[] = "#{$id} ({$label})";
}
check('WORD-D2', $noCode === [] && $labels !== [],
    'ทุกรายการมีรหัสสมาชิกในข้อความ — พิมพ์รหัสค้นเจอได้ (select2 ค้นจากข้อความ option)',
    '🔴 ไม่มีรหัสใน: ' . implode(' · ', $noCode));

// D3 — 🔴 ชื่อซ้ำกันเป๊ะต้องแยกออกจากกันได้ *ด้วยสิ่งที่พิมพ์อยู่บนบัตรสมาชิก*
//      ⚠️ ห้ามนับอีเมลว่าแยกได้ — อีเมลไม่ได้อยู่บนบัตร และคือสาเหตุของ F-51:
//         เจ้าหน้าที่เห็นอีเมลในจอ แต่สมาชิกที่ยืนอยู่ตรงหน้าจำอีเมลตัวเองไม่ได้
//         ถ้าตัดอีเมลออกแล้วสองรายการเหมือนกัน = ของจริงยังเลือกผิดคนได้อยู่
$onCard = [];
foreach ($labels as $id => $label) {
    $onCard[$id] = trim(preg_replace('/\([^)]*@[^)]*\)/', '', $label));
}
$distinct = count(array_unique(array_values($onCard)));
check('WORD-D3', $distinct === 2 && count($onCard) === 2,
    'ชื่อซ้ำกันเป๊ะแยกออกจากกันได้โดยไม่ต้องถามอีเมล: ' . implode(' | ', array_values($onCard)),
    '🔴 ตัดอีเมลออกแล้วสองคนเหมือนกันหมด ("' . reset($onCard) . '") — '
        . 'เจ้าหน้าที่ต้องถามอีเมลซึ่งสมาชิกจำไม่ได้ นี่คือ F-51 ที่ยังไม่ถูกแก้');

// D4 — ยังคงอีเมลไว้ (ไม่ตัดทิ้ง)
$hasEmail = true;
foreach ($labels as $label) {
    if (!str_contains($label, '@')) $hasEmail = false;
}
check('WORD-D4', $hasEmail,
    'ยังแสดงอีเมลไว้ด้วย — ไม่ได้ตัดข้อมูลที่เคยใช้แยกคนออกไป',
    'อีเมลถูกตัดออก — ลดข้อมูลที่เจ้าหน้าที่เคยใช้');

// D5 — ป้ายช่องบอกว่าค้นด้วยรหัสได้
check('WORD-D5',
    str_contains($form, 'รหัสสมาชิก') && preg_match('/placeholder[^>]*รหัสสมาชิก|>พิมพ์ชื่อ หรือรหัสสมาชิก/u', $form) === 1,
    'ป้ายช่องบอกว่าพิมพ์รหัสสมาชิกค้นได้',
    'ป้ายช่องไม่ได้บอกว่าค้นด้วยรหัสได้ — เจ้าหน้าที่จะไม่รู้');

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
