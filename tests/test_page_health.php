<?php

/**
 * สุขภาพของทุกหน้า — กวาดทั้งระบบผ่าน HTTP จริง
 *
 * ==========================================================================
 * 🔴 ที่มา: ทดสอบด้วยเบราว์เซอร์ทีละหน้าแล้วเจอของที่ชุดทดสอบเดิมมองไม่เห็น
 * ==========================================================================
 * ชุดทดสอบเดิม 889 เคสเน้น "กฎธุรกิจถูกไหม" — ยิง Service/Repository เป็นหลัก
 * แต่ไม่มีใครดูว่า **หน้าเว็บที่ผู้ใช้เห็นจริง ๆ มีอะไรเสียไหม**
 * เปิดดูทีละหน้าแล้วเจอ 3 อย่างที่ผ่านทุกเคสมาตลอด:
 *
 * 1. `admin/books.php` อ้างตัวแปร `$categoryId` ที่ไม่มีอยู่จริง (ชื่อจริงคือ `$category`)
 *    → APP_DEBUG=true: PHP Warning 12 บรรทัดโผล่กลางดรอปดาวน์ **เปิด path เต็มของเซิร์ฟเวอร์**
 *    → APP_DEBUG=false: เงียบ แต่ **ดรอปดาวน์ไม่จำค่าที่เลือก** กรองแล้วเด้งกลับ "ทั้งหมด"
 *
 * 2. หน้าแรกโชว์ "1,187 หนังสือทั้งหมด" คู่กับ "403 เล่ม" บนจอเดียวกัน
 *    403 คือจำนวน **ชื่อเรื่อง** ไม่ใช่เล่ม (ฝั่งแอดมินแยกถูกอยู่แล้ว)
 *
 * 3. ปุ่มหน้าลืมรหัสผ่านเขียน "ส่งลิงก์รีเซ็ตรหัสผ่าน" + ไอคอนซองจดหมาย
 *    ทั้งที่ระบบ **ไม่ส่งอีเมลเลย และตั้งใจไม่ทำ** (ดู docs/LIMITATIONS.md หัวข้อ 6)
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. ทุกหน้าต้องไม่มี PHP Warning / Notice / Deprecated / Fatal โผล่บนหน้าจอ
 * B. ตัวกรองแบบดรอปดาวน์ต้อง "จำค่าที่เลือก" หลังกรอง
 * C. คำที่ใช้นับต้องตรงกับสิ่งที่นับจริง (ชื่อเรื่อง ≠ เล่ม)
 * D. ไม่มีปุ่มไหนสัญญาว่าจะส่งอีเมล
 *
 * 🔴 ต้องรันด้วย APP_DEBUG=true ถึงจะเห็น warning
 *    ถ้า APP_DEBUG=false ข้อ A จะผ่านแบบไม่ได้ตรวจอะไรเลย — เทสต์จะเตือนให้รู้
 *
 * 🧠 ชุดนี้ไม่สร้างข้อมูลใหม่ ไม่ต้องล้างอะไร — อ่านอย่างเดียว
 *
 * 📌 การใช้งาน: php tests/test_page_health.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$ROOT           = dirname(__DIR__);
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

$COOKIE = tempnam(sys_get_temp_dir(), 'bbph');
register_shutdown_function(fn() => @unlink($COOKIE));

function http(string $method, string $url, array $fields = []): string
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
    curl_close($ch);
    return $body;
}

function csrfFrom(string $html): string
{
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

/**
 * 🔴 ยิงแบบ **ไม่มี session** — จำเป็นสำหรับหน้าที่เปิดได้เฉพาะตอนยังไม่ล็อกอิน
 *    (forgot_password.php / login.php / register.php จะ redirect ทิ้งถ้าล็อกอินอยู่)
 *
 * 🧠 เคยพลาดมาแล้ว: เทสต์ล็อกอินเป็นแอดมินตั้งแต่ข้อ A แล้วข้อ D ไปดึง
 *    forgot_password.php ด้วย cookie เดิม → ได้หน้าแรกกลับมาแทน
 *    ทำให้เคสเขียวทั้งที่ปุ่มยังเขียนว่า "ส่งลิงก์..." อยู่
 */
function httpAnon(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = (string) curl_exec($ch);
    curl_close($ch);
    return $body;
}

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  สุขภาพของทุกหน้า — กวาดทั้งระบบผ่าน HTTP                  ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

// ============================================================
// A. ห้ามมี PHP error โผล่บนหน้าจอ
// ============================================================
echo "── A. ไม่มี PHP warning บนหน้าจอ ──\n";

/**
 * 🔴 ข้อ A เห็น warning ได้เฉพาะตอน APP_DEBUG=true — ตอนปิด PHP จะกลืนทั้งหมด
 *
 * 🧠 **จงใจไม่ทำให้เคสนี้แดง** เมื่อปิด debug เพราะ:
 *    - ของที่ส่งมอบต้องตั้ง APP_DEBUG=false (เอกสารสั่งไว้เอง) ถ้าแดงตรงนี้
 *      ชุดทดสอบจะแดงถาวรบนเครื่องที่ตั้งค่าถูกต้อง แล้วคนจะเลิกสนใจทั้งชุด
 *    - บั๊กตระกูลเดียวกันถูกจับซ้ำโดยข้อ B อยู่แล้ว ซึ่งทำงานไม่ว่าจะเปิด debug หรือไม่
 *      (ตัวแปรที่ไม่มีอยู่จริง → ดรอปดาวน์ไม่ติด selected)
 *    จึงรายงานให้เห็นชัดว่า "ตรวจได้แค่ไหน" แทนการแดง
 */
$debugOn = defined('APP_DEBUG') && APP_DEBUG;
pass('PAGE-A0', $debugOn
    ? 'APP_DEBUG=true — ข้อ A ตรวจได้เต็มที่ เห็น warning ที่ซ่อนอยู่'
    : '⚠️ APP_DEBUG=false — PHP กลืน warning ข้อ A1 จึงตรวจได้แค่ error ที่แสดงเสมอ'
        . " (fatal/parse)\n       ความครอบคลุมเต็มรูปแบบต้องรันบนเครื่องที่ตั้ง APP_DEBUG=true"
        . "\n       บั๊กตัวแปรผิดชื่อยังถูกจับด้วยข้อ B ซึ่งไม่ขึ้นกับ debug");

$login = http('GET', "{$BASE_URL}/login.php");
http('POST', "{$BASE_URL}/login.php", [
    'csrf_token' => csrfFrom($login),
    'email'      => $ADMIN_EMAIL,
    'password'   => $ADMIN_PASSWORD,
]);

// 📄 ทุกหน้าที่ผู้ใช้เปิดได้จริง + รูปแบบที่มีตัวกรอง (ตัวกรองคือจุดที่พลาดง่ายที่สุด)
$pages = [
    'index.php', 'index.php?category=1', 'index.php?status=available', 'index.php?search=ก',
    'book.php?id=1', 'login.php', 'register.php', 'forgot_password.php',
    'my_borrows.php', 'my_reservations.php', 'profile.php',
    'admin/', 'admin/books.php', 'admin/books.php?category=1&status=available&sort=az',
    'admin/book_form.php', 'admin/book_form.php?id=1', 'admin/book_labels.php',
    'admin/categories.php', 'admin/members.php', 'admin/members.php?role=staff',
    'admin/member_form.php', 'admin/import_books.php', 'admin/import_members.php',
    'admin/borrows.php', 'admin/borrows.php?filter=overdue', 'admin/borrows.php?filter=due_today',
    'admin/borrow_form.php', 'admin/reservations.php', 'admin/payments.php', 'admin/settings.php',
];
foreach (['books','dormant','members','revenue','overdue','due_soon','borrows','unpaid'] as $r) {
    $pages[] = "admin/reports.php?report={$r}";
}
$pages[] = 'admin/export_pdf.php?report=overdue';

$dirty = [];
foreach ($pages as $p) {
    $html = http('GET', "{$BASE_URL}/{$p}");
    // 📝 PHP ห่อ error ด้วยแท็ก HTML (<br /><b>Warning</b>: ...) ต้องถอดแท็กก่อนค้น
    //    🔴 เคยเขียน regex หาคำว่า "Warning:" ตรง ๆ แล้วหาไม่เจอทั้งที่มีอยู่ 12 จุด
    $text = preg_replace('/<[^>]+>/', ' ', $html);
    if (preg_match_all('/(Warning|Notice|Deprecated|Fatal error|Parse error)\s*:\s*[^\n]{0,70}/', $text, $m)) {
        $dirty[] = "{$p} → " . count($m[0]) . ' จุด: ' . trim(preg_replace('/\s+/', ' ', $m[0][0]));
    }
}
check('PAGE-A1', !$dirty,
    'กวาด ' . count($pages) . ' หน้า ไม่มี PHP error โผล่บนหน้าจอเลย'
        . ($debugOn ? '' : ' (ตรวจแบบจำกัดเพราะ APP_DEBUG=false — ดู PAGE-A0)'),
    '🔴 พบใน ' . count($dirty) . " หน้า:\n       " . implode("\n       ", $dirty));

// ============================================================
// B. ตัวกรองต้องจำค่าที่เลือก
// ============================================================
echo "\n── B. ตัวกรองจำค่าที่เลือกได้ ──\n";

/**
 * 🧠 ทำไมสำคัญ: ผู้ใช้กรอง "นวนิยาย" แล้วดรอปดาวน์เด้งกลับเป็น "ทั้งหมด"
 *    เขาจะคิดว่าตัวกรองไม่ทำงาน แล้วกดซ้ำ หรือเปลี่ยนตัวกรองอื่นแล้วหมวดหมู่หายไปเงียบ ๆ
 *    (ปัญหาตระกูลเดียวกับ F-37 "บันทึกแล้วต้องกลับหน้า/ตัวกรองเดิม")
 */
$firstCat = (int) getDB()->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetchColumn();
$filterCases = [
    ['admin/books.php?category=' . $firstCat, 'category', 'ตัวกรองหมวดหมู่ (หน้าจัดการหนังสือ)'],
    ['admin/books.php?status=available',      'status',   'ตัวกรองสถานะ (หน้าจัดการหนังสือ)'],
    ['admin/books.php?sort=az',               'sort',     'ตัวเรียงลำดับ (หน้าจัดการหนังสือ)'],
    ['admin/members.php?role=staff',          'role',     'ตัวกรองบทบาท (หน้าสมาชิก)'],
    ['index.php?category=' . $firstCat,       'category', 'ตัวกรองหมวดหมู่ (หน้าแรก)'],
];
$forgetful = [];
foreach ($filterCases as [$path, $name, $label]) {
    $html = http('GET', "{$BASE_URL}/{$path}");
    // 📝 หา <select name="..."> แล้วดูว่ามี option ไหนติด selected ไหม
    if (!preg_match('/<select[^>]*name="' . preg_quote($name, '/') . '"[^>]*>(.*?)<\/select>/s', $html, $m)) {
        $forgetful[] = "{$label} — ไม่เจอ <select name=\"{$name}\">";
        continue;
    }
    if (!str_contains($m[1], 'selected')) {
        $forgetful[] = "{$label} — กรองแล้วดรอปดาวน์เด้งกลับเป็นค่าเริ่มต้น";
    }
}
check('PAGE-B1', !$forgetful,
    'ตัวกรองทั้ง ' . count($filterCases) . ' จุดจำค่าที่เลือกได้หลังกรอง',
    "🔴 ลืมค่าที่เลือก " . count($forgetful) . " จุด:\n       " . implode("\n       ", $forgetful));

// ============================================================
// C. คำที่ใช้นับต้องตรงกับสิ่งที่นับ
// ============================================================
echo "\n── C. ชื่อเรื่อง ≠ เล่ม ──\n";

$pdo    = getDB();
$titles = (int) $pdo->query("SELECT COUNT(*) FROM books")->fetchColumn();
$copies = (int) $pdo->query("SELECT SUM(quantity) FROM books")->fetchColumn();

check('PAGE-C1', $titles !== $copies,
    "ข้อมูลชุดนี้แยกความต่างได้ (ชื่อเรื่อง {$titles} · เล่ม {$copies})",
    "🔴 ชื่อเรื่องเท่ากับจำนวนเล่มพอดี ({$titles}) — เทสต์นี้แยกความต่างไม่ได้ ต้องมีหนังสือที่ quantity > 1");

/**
 * 🔴 จำนวนแถวคือ "ชื่อเรื่อง" ถ้าติดป้ายว่า "เล่ม" จะขัดกับการ์ดด้านบนที่นับเล่มจริง
 *
 * 🧠 **ห้ามผูกกับ COUNT(*) ของทั้งตาราง** — หน้าแรกแสดงเฉพาะเล่มที่เปิดให้เห็น
 *    (is_visible = 1) ตัวเลขจึงไม่เท่ากับ COUNT(*) และเคยทำให้เทสต์นี้
 *    มองไม่เห็นบั๊กที่ยังอยู่ตรงหน้า · ให้จับ "ตัวเลขอะไรก็ได้ + เล่ม"
 *    ในบริเวณที่แสดงยอดรวมของรายการแทน
 */
$mislabelled = [];
$countPatterns = [
    'index.php'       => ['หน้าแรก', '/รายการหนังสือ\s+([\d,]+)\s*(เล่ม)/u'],
    'admin/books.php' => ['หน้าจัดการหนังสือ', '/ทั้งหมด\s+([\d,]+)\s*(เล่ม)/u'],
];
foreach ($countPatterns as $path => [$label, $pattern]) {
    $text = preg_replace('/\s+/', ' ', preg_replace('/<[^>]+>/', ' ', http('GET', "{$BASE_URL}/{$path}")));
    if (preg_match($pattern, $text, $m)) {
        $mislabelled[] = "{$label} — เรียกยอดรวมของรายการ ({$m[1]}) ว่า \"เล่ม\" ทั้งที่นับเป็นชื่อเรื่อง";
    }
}
// 📝 กันเทสต์ผ่านแบบว่างเปล่า: ต้องหา "ยอดรวมของรายการ" บนหน้าให้เจอจริง ๆ ก่อน
$foundCounter = false;
foreach ($countPatterns as $path => [$label, $pattern]) {
    $text = preg_replace('/\s+/', ' ', preg_replace('/<[^>]+>/', ' ', http('GET', "{$BASE_URL}/{$path}")));
    if (preg_match('/(รายการหนังสือ|ทั้งหมด)\s+[\d,]+\s*\S+/u', $text)) { $foundCounter = true; break; }
}
if (!$foundCounter) {
    $mislabelled[] = '🔴 หา "ยอดรวมของรายการ" บนหน้าไม่เจอเลย — รูปแบบหน้าเปลี่ยนไป ต้องแก้เทสต์นี้';
}
check('PAGE-C2', !$mislabelled,
    'ไม่มีหน้าไหนเรียกจำนวนชื่อเรื่องว่า "เล่ม"',
    "🔴 " . implode("\n       ", $mislabelled)
        . "\n       จอเดียวกันจะมี 2 ตัวเลขที่อ้างว่าเป็น \"หนังสือ\" โดยไม่บอกว่าต่างกันยังไง");

// ============================================================
// D. ห้ามสัญญาว่าจะส่งอีเมล
// ============================================================
echo "\n── D. ไม่มีปุ่มไหนสัญญาว่าจะส่งอีเมล ──\n";

/**
 * 🔴 ระบบไม่ส่งอีเมลเลย และตั้งใจไม่ทำ (docs/LIMITATIONS.md หัวข้อ 6)
 *    ปุ่มที่เขียนว่า "ส่งลิงก์..." = สมาชิกนั่งรอเมลที่ไม่มีวันมา
 *    แล้วหน้าผลลัพธ์ต้องมาแก้ต่างทีหลังว่าให้ไปที่เคาน์เตอร์
 */
$promises = [];
foreach (['forgot_password.php', 'login.php', 'register.php'] as $p) {
    $html = httpAnon("{$BASE_URL}/{$p}");
    // 🛡️ กันเคสผ่านเพราะโดน redirect ทิ้ง — ต้องได้หน้านั้นจริง ๆ
    if (!str_contains($html, 'csrf_token')) {
        $promises[] = "{$p} → ⚠️ ดึงหน้าไม่ได้ (อาจโดน redirect) เทสต์นี้ตรวจไม่ได้";
        continue;
    }
    foreach (['ส่งลิงก์', 'ส่งอีเมล', 'ส่งเมล', 'ตรวจสอบอีเมลของคุณ', 'เช็คอีเมล'] as $phrase) {
        if (str_contains($html, $phrase)) $promises[] = "{$p} → \"{$phrase}\"";
    }
}
check('PAGE-D1', !$promises,
    'ไม่มีหน้าไหนบอกผู้ใช้ให้รออีเมล',
    "🔴 พบคำสัญญาที่ระบบทำไม่ได้:\n       " . implode("\n       ", $promises));

// ============================================================
echo "\n══════════════════════════════════════\n";
printf(" RESULTS: %d/%d passed (%.1f%%)%s\n",
    $results['passed'], $results['total'],
    $results['total'] ? $results['passed'] / $results['total'] * 100 : 0,
    $results['failed'] ? ' | ' . $results['failed'] . ' FAILED' : '');
echo "══════════════════════════════════════\n";

exit($results['failed'] > 0 ? 1 : 0);
