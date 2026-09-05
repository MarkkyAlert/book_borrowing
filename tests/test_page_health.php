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

function http(string $method, string $url, array $fields = [], ?string $jar = null): string
{
    global $COOKIE;
    // 🧠 $jar = ยิงด้วย session อื่น (เช่น ทดสอบว่าเจ้าหน้าที่ธรรมดาโหลดไฟล์สำรองได้ไหม)
    //    ไม่ส่งมา = ใช้ session ของแอดมินที่ล็อกอินไว้ตั้งแต่ต้นไฟล์
    $jar = $jar ?? $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
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
echo "\n── E. ตัวเลขและลิงก์บนหน้าจอต้องพูดตรงกับข้อมูลจริง (จาก UAT รอบ 2) ──\n";
// ============================================================
// 🧠 ที่มา: เดิน UAT ด้วยเบราว์เซอร์แล้วเจอ 3 อย่างที่ชุดทดสอบ 1,025 เคสมองไม่เห็น
//    เพราะทุกเคสยิง Service/Repository ตรง ไม่มีใครอ่าน "สิ่งที่โผล่บนจอ"
//    ทั้งสามข้อคือ "ข้อมูลถูก แต่หน้าจอบอกผิด" ซึ่งเทสต์ระดับ Service จับไม่ได้เลย

// ── E1: ช่องวันที่ในหน้ารายงาน ต้องโชว์ช่วงเดียวกับที่กรองจริง ──
//    บั๊กเดิม: flatpickr ตั้ง dateFormat='d/m/Y' แต่รับ defaultDate เป็น ISO
//    → อ่านเพี้ยนเป็น '20/06/2026' มาเขียนทับค่าที่เซิร์ฟเวอร์ใส่มาถูกแล้ว
//    ⚠️ เทสต์นี้รัน JS ไม่ได้ จึงตรวจที่ "ค่าที่ส่งให้ปฏิทิน" แทน — ถ้ารูปแบบตรงกับ
//       dateFormat ปฏิทินจะอ่านถูก (แบบเดียวกับ CN-C3 ที่ตรวจระดับซอร์ส)
$reportTabs = ['unpaid', 'revenue', 'books', 'overdue', 'due_soon', 'members', 'dormant', 'borrows'];
$badFormat = [];
$mismatch  = [];
foreach ($reportTabs as $tab) {
    $html = http('GET', "$BASE_URL/admin/reports.php?report={$tab}");
    preg_match_all("/defaultDate: '([^']*)'/", $html, $dd);
    preg_match('/name="start_date"[^>]*value="([^"]*)"/', $html, $hs);
    preg_match('/name="end_date"[^>]*value="([^"]*)"/', $html, $he);
    $hidden = [$hs[1] ?? '', $he[1] ?? ''];
    foreach (($dd[1] ?? []) as $i => $v) {
        // ต้องเป็น d/m/Y ให้ตรงกับ dateFormat ที่ตั้งไว้
        if (!preg_match('#^\d{2}/\d{2}/\d{4}$#', $v)) {
            $badFormat[] = "{$tab}: defaultDate='{$v}' (ต้องเป็น dd/mm/yyyy)";
            continue;
        }
        // แปลงกลับเป็น ISO แล้วต้องตรงกับ hidden ที่ส่งไปกรองจริง
        [$d, $m, $y] = explode('/', $v);
        if (($hidden[$i] ?? '') !== "$y-$m-$d") {
            $mismatch[] = "{$tab}: ช่องโชว์ {$v} แต่กรองจริง " . ($hidden[$i] ?? '?');
        }
    }
}
check('PAGE-E1', $badFormat === [],
    'ค่าที่ส่งให้ปฏิทินเป็นรูปแบบ dd/mm/yyyy ตรงกับ dateFormat ทุกแท็บ (' . count($reportTabs) . ' แท็บ)',
    '🔴 รูปแบบไม่ตรงกับ dateFormat จะอ่านเพี้ยน: ' . implode(' · ', $badFormat));

check('PAGE-E2', $mismatch === [],
    'ช่วงวันที่ที่คนเห็น = ช่วงที่ส่งไปกรองจริง ทุกแท็บ',
    '🔴 ช่องวันที่โกหก: ' . implode(' · ', $mismatch));

// ── E3: ป้ายตัวเลขบนการ์ด ต้องเป็นจำนวนจริง ไม่ใช่จำนวนที่โชว์ ──
//    บั๊กเดิม: หน้าภาพรวมแปะ count() ของลิสต์ที่ถูก LIMIT 5 → ป้ายเกิน 5 ไม่ได้เลย
$dash = http('GET', "$BASE_URL/admin/");
$realOutOfStock = (int) $pdo->query(
    "SELECT COUNT(*) FROM books WHERE available = 0 AND quantity > 0"
)->fetchColumn();
$badgeOk = false;
if (preg_match('/หนังสือที่ถูกยืมหมด.*?>(\d+)</su', $dash, $bm)) {
    $badgeOk = ((int) $bm[1] === $realOutOfStock);
    $badgeVal = $bm[1];
} else {
    $badgeVal = '(ไม่พบการ์ด)';
    // ไม่มีเล่มไหนถูกยืมหมดเลย → การ์ดไม่ขึ้น ถือว่าถูกต้อง
    $badgeOk = ($realOutOfStock === 0);
}
check('PAGE-E3', $badgeOk,
    "ป้ายบนการ์ด \"หนังสือที่ถูกยืมหมด\" = จำนวนจริง ({$realOutOfStock} เล่ม)",
    "🔴 ป้ายบอก {$badgeVal} แต่ของจริง {$realOutOfStock} เล่ม — น่าจะเอา count() ของลิสต์ที่ถูก LIMIT มาแปะ");

// ── E4: ลิงก์ทุกอันบนหน้าภาพรวมที่มีพารามิเตอร์ ต้องกรองได้จริง ──
//    🛡️ ยามกันซ้ำรอย — บั๊กเดิมคือ Dashboard ส่ง ?filter=low_stock แต่ books.php อ่าน $status
//       ค่าตกพื้น กด "ดูทั้งหมด" แล้วได้หนังสือครบทั้งกอง (borrows.php ใช้ filter= จริง
//       ทั้งสองหน้าใช้ชื่อพารามิเตอร์ต่างกัน แล้วไม่มีใครเช็ค)
//       เคสนี้จับ "ประเภท" ของบั๊ก ไม่ใช่จับแค่ลิงก์เดียว
// 🧠 วัดด้วย "ขนาดของผลลัพธ์ทั้งชุด" ไม่ใช่จำนวนแถวในหน้าแรก
//    เพราะทุกหน้าแบ่งหน้าละ 20 แถวเท่ากันหมด กรองหรือไม่กรองก็ได้ 20 แถวเท่ากัน
//    (ตัวตรวจรุ่นแรกของเคสนี้นับ <tr> แล้วฟ้อง borrows.php?filter=overdue ผิด ๆ)
//    ใช้เลขหน้าสุดท้ายจากแถบแบ่งหน้า ถ้าไม่มีแถบก็ถอยไปนับแถวแทน
//    ⚠️ href ในหน้าถูก escape เป็น &amp;page=2 — regex ที่บังคับว่าต้องมี ? หรือ &
//       นำหน้าจะไม่แมตช์ แล้วตกไปนับแถวเงียบ ๆ จนยามนี้กลายเป็นยามหลับ (เจอตอนทดสอบว่ามันแดงได้ไหม)
$sizeOf = function (string $url) {
    $html  = http('GET', $url);
    $pages = preg_match_all('/page=(\d+)/', $html, $pm) ? (int) max($pm[1]) : 1;
    $rows  = preg_match_all('/<tr[\s>]/', $html);
    return ['pages' => $pages, 'rows' => $rows];
};
preg_match_all('#href="((?:books|borrows|members|payments|reservations)\.php\?[^"]+)"#', $dash, $lm);
$deadLinks    = [];
$undecidable  = [];
$checked      = 0;
foreach (array_unique($lm[1] ?? []) as $rel) {
    $page = strtok($rel, '?');
    $filtered   = $sizeOf("$BASE_URL/admin/" . html_entity_decode($rel));
    $unfiltered = $sizeOf("$BASE_URL/admin/{$page}");
    $checked++;
    // กรองแล้วต้องได้ผลลัพธ์ "เล็กลง" — เท่าเดิมทุกมิติ = พารามิเตอร์ไม่มีผล
    //
    // ⚠️ ตัดสินได้เฉพาะตอนที่ "ไม่กรองแล้วยาวเกิน 1 หน้า" เท่านั้น
    //    ถ้าข้อมูลทั้งหมดมีหน้าเดียว ตัวกรองที่ทำงานถูกก็อาจคืนครบทุกแถวได้
    //    (เจอจริง: reservations.php?expiring=1 — การจองค้างทั้ง 7 รายการหมดอายุ
    //     ภายใน 24 ชม. พอดี ตัวกรองจึงคืนทั้ง 7 อย่างถูกต้อง แต่ยามฟ้องว่า "ไม่กรอง")
    //    กรณีนั้นข้ามพร้อมพิมพ์บอก ไม่เดาแทนผู้อ่าน
    if ($unfiltered['pages'] <= 1) {
        $undecidable[] = $rel . " ({$unfiltered['rows']} แถว หน้าเดียว)";
    } elseif ($filtered['pages'] === $unfiltered['pages'] && $filtered['rows'] === $unfiltered['rows']) {
        $deadLinks[] = $rel . " (ไม่กรอง {$unfiltered['pages']} หน้า/{$unfiltered['rows']} แถว → กรองแล้วได้เท่าเดิม)";
    }
}
if ($undecidable) {
    echo "  ℹ️  ตัดสินไม่ได้ " . count($undecidable) . " ลิงก์ (ข้อมูลไม่ถึง 2 หน้า จะกรองหรือไม่กรองก็ได้ผลเท่ากัน): "
        . implode(' · ', $undecidable) . "\n";
}
check('PAGE-E4', $deadLinks === [],
    "ลิงก์บนหน้าภาพรวมกรองได้จริงทุกอัน (ตัดสินได้ " . ($checked - count($undecidable)) . " จาก {$checked} ลิงก์)",
    '🔴 ลิงก์ที่กดแล้วไม่กรองอะไรเลย (พารามิเตอร์ผิดชื่อ?): ' . implode(' · ', $deadLinks));

// ── E5: การ์ดกับหน้าที่ "ดูทั้งหมด" พาไป ต้องบอกจำนวนเท่ากัน ──
//    เจอตอนแก้ E3/E4: ป้ายบอก 7 แต่หน้าปลายทางได้ 8 เพราะนิยาม "หมด" ไม่ตรงกัน
//    (การ์ดตัดเล่มที่ quantity=0 ออก แต่ตัวกรองในหน้าหนังสือไม่ตัด)
$outPage = http('GET', "$BASE_URL/admin/books.php?status=out_of_stock");
$outRows = preg_match_all('/book_form\.php\?id=/', $outPage);
check('PAGE-E5', $outRows === $realOutOfStock,
    "หน้า \"หนังสือหมด\" แสดง {$outRows} เล่ม เท่ากับป้ายบนการ์ด",
    "🔴 การ์ดบอก {$realOutOfStock} แต่หน้าปลายทางแสดง {$outRows} — นิยาม \"หมด\" ของสองที่ไม่ตรงกัน");


// ============================================================
echo "\n── F. ของที่เพิ่มให้ตามผล UAT รอบ 2 ──\n";
// ============================================================

// ── F1: ใบรายชื่อโทรตาม ต้องกดเบอร์แล้วโทรออกได้ ──
//    ฎ.4: ใบนี้มีไว้เพื่อโทร แต่เบอร์เป็นข้อความเฉย ๆ บนมือถือต้องพิมพ์เองทีละคน
$callSheet = http('GET', "$BASE_URL/admin/reports.php?report=due_soon");
preg_match_all('/<tbody.*?<\/tbody>/s', $callSheet, $tb);
$body     = $tb[0][0] ?? '';
$dataRows = preg_match_all('/<tr[\s>]/', $body);
preg_match_all('#href="tel:([0-9+]+)"[^>]*>.*?([0-9]{6,})\s*</a>#s', $body, $tm, PREG_SET_ORDER);
$telCount = count($tm);
// เบอร์ใน href ต้องตรงกับเบอร์ที่แสดง ไม่ใช่คนละเลข
$telMismatch = [];
foreach ($tm as $m) {
    if ($m[1] !== $m[2]) $telMismatch[] = "href={$m[1]} แต่แสดง {$m[2]}";
}
// 🧠 ติดตั้งสดยังไม่มีการยืมเลย ใบโทรตามจึงว่าง — ตัดสินไม่ได้ ต้องข้าม
//    แต่ข้ามแบบพิมพ์บอก ไม่ใช่ข้ามเงียบ ๆ และไม่ใช่ปล่อยให้แดงด้วยเหตุผลผิด
if ($dataRows === 0) {
    echo "  ⏭  ข้าม PAGE-F1/F2 — ยังไม่มีรายการใกล้ครบกำหนดในระบบ (ติดตั้งใหม่)\n";
} else {
check('PAGE-F1', $telCount === $dataRows && $telMismatch === [],
    "ทุกแถวในใบโทรตามกดโทรออกได้ ({$telCount}/{$dataRows} แถว) และเบอร์ในลิงก์ตรงกับที่แสดง",
    $telMismatch !== []
        ? '🔴 เบอร์ในลิงก์ไม่ตรงกับที่แสดง: ' . implode(' · ', $telMismatch)
        : "🔴 มี {$dataRows} แถว แต่กดโทรได้ {$telCount} แถว");

// ── F2: ทางส่งออก CSV ต้องไม่โดนกระทบ ──
//    🛡️ การเติมลิงก์บนหน้าจอต้องไม่ทำให้ HTML หลุดลงไฟล์ และ ' นำหน้าเบอร์ต้องยังอยู่
//       (ไม่งั้น Excel จะกินเลข 0 ตัวหน้าหาย — ฎ.5 เคยผ่านมาแล้ว ห้ามทำพัง)
$csvOut = http('GET', "$BASE_URL/admin/reports.php?report=due_soon&export=csv");
$csvLines = array_values(array_filter(explode("\n", str_replace("\r", '', $csvOut))));
$csvHasHtml  = (bool) preg_match('/<a |href=|<i class/', $csvOut);
$csvKeepsZero = (bool) preg_match("/,'0\d/", $csvLines[1] ?? '');
check('PAGE-F2', !$csvHasHtml && $csvKeepsZero,
    "ไฟล์ CSV ไม่มี HTML ปน และเบอร์ยังมี ' นำหน้า (เลข 0 ไม่หายใน Excel)",
    '🔴 ' . ($csvHasHtml ? 'มี HTML หลุดลงไฟล์ CSV ' : '') . (!$csvKeepsZero ? "เบอร์ไม่มี ' นำหน้าแล้ว" : ''));
}

// ── F3: ต้องทำ tel: เฉพาะคอลัมน์ที่เป็นเบอร์โทรจริง ──
//    REPORT_TEXT_CODE_COLUMNS รวม isbn/barcode/member_code ด้วย ถ้าเผลอเอาลิสต์นั้น
//    มาทำ tel: ผู้ใช้จะกด ISBN แล้วเครื่องโทรออกเป็นเลข 13 หลัก
//
//    ⚠️ ตรวจที่ซอร์ส ไม่ใช่ที่หน้าจอ เพราะตอนนี้ยังไม่มีรายงานไหนคืนคอลัมน์ isbn เลย
//       (มีแค่ในนิยามค่าคงที่เผื่ออนาคต) ถ้าตรวจจากหน้าจอ เคสนี้จะเป็นยามหลับที่
//       ล้มไม่ได้เลย — ลองทำลายโค้ดดูแล้วมันยังเขียว จึงเปลี่ยนมาตรวจแบบนี้
//       (วิธีเดียวกับ CN-C3 ในเทสต์เลขเรียกหนังสือ)
$reportsSrc = file_get_contents(__DIR__ . '/../admin/reports.php');
$usesPhoneList = (bool) preg_match('/href="tel:/', $reportsSrc)
    && (bool) preg_match('/in_array\(\$key,\s*REPORT_PHONE_COLUMNS\s*,\s*true\).*?href="tel:/s', $reportsSrc);
$leaksCodeList = (bool) preg_match('/in_array\(\$key,\s*REPORT_TEXT_CODE_COLUMNS\s*,\s*true\).*?href="tel:/s', $reportsSrc);
// อ่านค่าคงที่จากซอร์สโดยตรง — ไม่ require เพราะไฟล์นั้นต้องการ dependency อื่น
$helperSrc = file_get_contents(__DIR__ . '/../includes/report_helper.php');
preg_match("/const REPORT_PHONE_COLUMNS = \[(.*?)\];/s", $helperSrc, $pc);
preg_match_all("/'([a-z_]+)'/", $pc[1] ?? '', $pcCols);
$phoneCols = $pcCols[1] ?? [];
$phoneListClean = $phoneCols !== [] && !array_intersect($phoneCols, ['isbn', 'barcode', 'member_code']);

check('PAGE-F3', $usesPhoneList && !$leaksCodeList && $phoneListClean,
    'ลิงก์ tel: ผูกกับ REPORT_PHONE_COLUMNS (' . implode(', ', $phoneCols) . ') เท่านั้น',
    '🔴 ' . ($leaksCodeList ? 'ใช้ REPORT_TEXT_CODE_COLUMNS ทำ tel: → ISBN/barcode จะกลายเป็นลิงก์โทร' : '')
        . (!$phoneListClean ? ' REPORT_PHONE_COLUMNS มีคอลัมน์ที่ไม่ใช่เบอร์โทรปนอยู่' : '')
        . (!$usesPhoneList ? ' หาเงื่อนไข tel: ที่ผูกกับ REPORT_PHONE_COLUMNS ไม่เจอ' : ''));

// ── F4: การ์ด "วันนี้" ต้องเป็นยอดของวันนี้จริง ไม่ใช่ยอดเดือน ──
//    ญ.10: สิ้นวันต้องรู้ว่าเก็บเงินได้กี่บาท
//    🧠 ปกติสองค่านี้มักเท่ากัน (เดือนที่เพิ่งเริ่ม) เทียบเฉย ๆ จะเป็นยามหลับ
//       จึงแอบใส่รายการย้อนหลังในเดือนเดียวกันก่อน เพื่อบังคับให้สองค่าต่างกันจริง
$todayNum  = (int) date('j');
$anyBorrowId = (int) $pdo->query("SELECT COALESCE(MIN(id), 0) FROM borrows")->fetchColumn();
if ($anyBorrowId === 0) {
    // payments.borrow_id เป็น FK — ไม่มีรายการยืมเลยก็ใส่รายการทดสอบไม่ได้
    echo "  ⏭  ข้าม PAGE-F4 — ยังไม่มีรายการยืมในระบบ ใส่รายการชำระทดสอบไม่ได้ (ติดตั้งใหม่)\n";
} elseif ($todayNum < 2) {
    echo "  ⏭  ข้าม PAGE-F4 — วันนี้เป็นวันที่ 1 ของเดือน ใส่รายการย้อนหลังในเดือนเดียวกันไม่ได้\n";
} else {
    $anyBorrow = $anyBorrowId;
    $probeAmt  = 7777;
    $backdate  = date('Y-m-01 09:00:00');
    $pdo->prepare("INSERT INTO payments (borrow_id, amount, recorded_by, created_at) VALUES (?, ?, NULL, ?)")
        ->execute([$anyBorrow, $probeAmt, $backdate]);
    $probeId = (int) $pdo->lastInsertId();

    $html   = http('GET', "$BASE_URL/admin/payments.php");
    $today  = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(created_at)=CURDATE()")->fetchColumn();
    $month  = (float) $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();

    $pdo->exec("DELETE FROM payments WHERE id = {$probeId}");   // เก็บกวาดทันที

    // ตอนนี้ today กับ month ต่างกันแน่นอน (ต่างกัน 7,777) → การ์ดต้องโชว์ today
    $ok = false;
    // ⚠️ ต้องจับ "การ์ด" ให้ตรง ไม่ใช่คำว่า "วันนี้" คำแรกในหน้า
    //    (กระดิ่งมี "ครบกำหนดคืนวันนี้" อยู่ก่อน — ตัวอ่านรุ่นแรกไปเจออันนั้นแล้วได้เลขมั่ว)
    if (preg_match('/>\s*วันนี้\s*<\/p>\s*<h3[^>]*>\s*([\d,]+)\s*฿/su', $html, $cm)) {
        $shown = (float) str_replace(',', '', $cm[1]);
        $ok = (abs($shown - $today) < 1);
        $shownTxt = number_format($shown);
    } else {
        $shownTxt = '(ไม่พบการ์ด)';
    }
    check('PAGE-F4', $ok && abs($today - $month) > 1,
        'การ์ด "วันนี้" โชว์ยอดของวันนี้ (' . number_format($today) . ' ฿) ไม่ใช่ยอดเดือน (' . number_format($month) . ' ฿)',
        "🔴 การ์ดโชว์ {$shownTxt} ฿ · วันนี้จริง " . number_format($today) . " ฿ · เดือนนี้ " . number_format($month) . ' ฿');
}


// ============================================================
echo "\n── G. สำรองข้อมูล (UAT รอบ 3 ข้อ ต.3) ──\n";
// ============================================================
// 🧠 ไฟล์สำรองมีข้อมูลอ่อนไหวทั้งระบบ (hash รหัสผ่านทุกคน · อีเมล · เบอร์โทร ·
//    รหัสผ่าน SMTP) หมวดนี้จึงเฝ้า 2 เรื่องคู่กัน: ใครโหลดได้ และไฟล์ใช้กู้คืนได้จริงไหม

$backupUrl = "$BASE_URL/admin/backup.php";
$adminTok  = csrfFrom(http('GET', "$BASE_URL/admin/settings.php"));

// ── G1: ต้องเป็นผู้ดูแลระบบ + ต้องมาจากปุ่มจริงเท่านั้น ──
$looksLikeDump = fn(string $body): bool => str_contains($body, 'ไฟล์สำรองข้อมูล')
    && str_contains($body, 'CREATE TABLE');

$leaks = [];
// ไม่ล็อกอิน (cookie jar ใหม่)
$anonJar = tempnam(sys_get_temp_dir(), 'bkanon');
if ($looksLikeDump(http('POST', $backupUrl, ['csrf_token' => $adminTok], $anonJar))) {
    $leaks[] = 'คนที่ไม่ได้ล็อกอินโหลดได้';
}
@unlink($anonJar);
// เจ้าหน้าที่ (staff) — ไม่ใช่ผู้ดูแล
$staffJar = tempnam(sys_get_temp_dir(), 'bkstaff');
$sLogin = http('GET', "$BASE_URL/login.php", [], $staffJar);
http('POST', "$BASE_URL/login.php", [
    'email' => 'staff@library.com', 'password' => '123456', 'csrf_token' => csrfFrom($sLogin),
], $staffJar);
$staffTok = csrfFrom(http('GET', "$BASE_URL/admin/", [], $staffJar));
if ($looksLikeDump(http('POST', $backupUrl, ['csrf_token' => $staffTok], $staffJar))) {
    $leaks[] = 'เจ้าหน้าที่ (staff) โหลดได้';
}
@unlink($staffJar);
// ผู้ดูแลแต่ไม่มี token (จำลองการถูกหลอกให้กดลิงก์จากเว็บอื่น)
if ($looksLikeDump(http('POST', $backupUrl, []))) {
    $leaks[] = 'ยิงมาโดยไม่มี CSRF token ก็ได้ไฟล์';
}
// ผู้ดูแล + GET ธรรมดา
if ($looksLikeDump(http('GET', $backupUrl))) {
    $leaks[] = 'เปิดด้วย URL เฉย ๆ (GET) ก็ได้ไฟล์';
}
check('PAGE-G1', $leaks === [],
    'ไฟล์สำรองโหลดได้เฉพาะผู้ดูแลระบบที่กดปุ่มจากหน้าตั้งค่า (กัน 4 ทาง)',
    '🔴 ไฟล์สำรองหลุด: ' . implode(' · ', $leaks));

// ── G2: ผู้ดูแลกดปุ่มจริงต้องได้ไฟล์ และต้องครบทุกตาราง ──
$dump = http('POST', $backupUrl, ['csrf_token' => $adminTok]);
$dbTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$missingTables = [];
foreach ($dbTables as $t) {
    if (!str_contains($dump, "CREATE TABLE `{$t}`")) $missingTables[] = $t;
}
check('PAGE-G2', $looksLikeDump($dump) && $missingTables === [],
    'ผู้ดูแลได้ไฟล์สำรองที่มีโครงสร้างครบ ' . count($dbTables) . ' ตาราง (' . number_format(strlen($dump) / 1024) . ' KB)',
    '🔴 ' . (!$looksLikeDump($dump) ? 'ผู้ดูแลกดแล้วไม่ได้ไฟล์ ' : '') . ($missingTables ? 'ขาดตาราง: ' . implode(', ', $missingTables) : ''));

// ── G3: 🛡️ ไฟล์ต้องกู้คืนได้จริง — backup ที่กู้ไม่ได้ไม่ใช่ backup ──
//    เทสต์นี้ตรวจ "ความครบถ้วนของเนื้อไฟล์": จำนวน INSERT ต้องเท่าจำนวนแถวจริง
//    และข้อความไทยที่มีอักขระพิเศษต้องอยู่ในไฟล์แบบอ่านออก ไม่ใช่ escape เพี้ยน
//    (การกู้คืนลงฐานข้อมูลจริงทดสอบด้วยมือแล้ว — เทสต์นี้เฝ้าไม่ให้เนื้อไฟล์เพี้ยนภายหลัง)
$rowMismatch = [];
foreach ($dbTables as $t) {
    $real   = (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
    $inFile = substr_count($dump, "INSERT INTO `{$t}` (");
    if ($real !== $inFile) $rowMismatch[] = "{$t}: จริง {$real} แต่ในไฟล์ {$inFile}";
}
// ข้อความไทยที่มีอัญประกาศ/ไม้ยมก ต้องรอดออกมาอ่านได้
$thaiSample = (string) $pdo->query("SELECT title FROM books WHERE title LIKE '%ๆ%' LIMIT 1")->fetchColumn();
$thaiOk = $thaiSample === '' || str_contains($dump, $thaiSample);
check('PAGE-G3', $rowMismatch === [] && $thaiOk,
    'จำนวน INSERT ตรงกับแถวจริงทุกตาราง และภาษาไทยในไฟล์อ่านออก',
    '🔴 ' . ($rowMismatch ? implode(' · ', array_slice($rowMismatch, 0, 3)) : '')
        . (!$thaiOk ? " ข้อความไทยเพี้ยนในไฟล์ (หาไม่เจอ: {$thaiSample})" : ''));

// ── G4: ห้ามมีไฟล์สำรองตกค้างในโฟลเดอร์เว็บ ──
//    🔴 กับดักคลาสสิก: เขียน dump ลงดิสก์ก่อนแล้วค่อยให้โหลด → ใครเดา URL ถูกก็ได้ไป
$webRoot = realpath(__DIR__ . '/..');
$strayDumps = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($webRoot, FilesystemIterator::SKIP_DOTS)) as $f) {
    $path = $f->getPathname();
    if (str_contains($path, '/.git/') || str_contains($path, '/database/')) continue;
    if (preg_match('/backup.*\.sql$/i', $f->getFilename())) $strayDumps[] = str_replace($webRoot, '', $path);
}
check('PAGE-G4', $strayDumps === [],
    'ไม่มีไฟล์สำรองตกค้างในโฟลเดอร์เว็บ (สตรีมออกอย่างเดียว ไม่เขียนลงดิสก์)',
    '🔴 พบไฟล์สำรองค้างอยู่ให้ใครก็โหลดได้: ' . implode(' · ', $strayDumps));


// ============================================================
echo "\n── H. หน้าตอนฐานข้อมูลล่ม (UAT รอบ 3 ข้อ ต.1) ──\n";
// ============================================================
// 🧠 ทำไมเรียกฟังก์ชันตรง ไม่หยุด MySQL จริง:
//    การหยุดบริการฐานข้อมูลกลางชุดทดสอบจะทำให้เคสอื่นล้มหมด และเป็นสิ่งที่
//    เครื่องของลูกค้าอาจไม่อนุญาต — renderDatabaseDownPage() เป็นฟังก์ชันบริสุทธิ์
//    ที่ไม่แตะฐานข้อมูลอยู่แล้ว จึงยิงตรงได้ผลเหมือนกัน
//
// 🔴 หน้านี้คือหน้าเดียวที่คนนอกเห็นตอนระบบล่ม — ต้องช่วยเจ้าหน้าที่ให้แก้เองได้
//    แต่ต้องไม่บอกสแตกเบื้องหลังให้คนทั่วไปรู้
$renderDown = function (string $scriptName): string {
    $cmd = 'SCRIPT_NAME_TEST=1 ' . escapeshellarg(PHP_BINARY) . ' -r '
        . escapeshellarg(
            '$_SERVER["SCRIPT_NAME"] = ' . var_export($scriptName, true) . ';'
            . 'require ' . var_export(__DIR__ . '/../includes/config.php', true) . ';'
            . 'require ' . var_export(__DIR__ . '/../includes/db.php', true) . ';'
            . 'ob_start(); renderDatabaseDownPage(null); echo ob_get_clean();'
        ) . ' 2>/dev/null';
    return (string) shell_exec($cmd);
};
$downStaff  = $renderDown('/admin/index.php');
$downPublic = $renderDown('/index.php');

// ── H1: เจ้าหน้าที่ต้องได้ขั้นตอนที่ทำเองได้ ──
//    เดิมเขียนแค่ "ติดต่อผู้ดูแลระบบ" ซึ่งเป็นทางตัน เพราะบรรณารักษ์คือผู้ดูแลระบบเอง
$staffNeeds = ['XAMPP', 'MySQL', 'Start', 'รีเฟรช'];
$staffMissing = array_values(array_filter($staffNeeds, fn($w) => !str_contains($downStaff, $w)));
check('PAGE-H1', $downStaff !== '' && $staffMissing === [],
    'หน้าล่มฝั่งเจ้าหน้าที่บอกขั้นตอนที่ทำเองได้ (เปิด XAMPP → ดู MySQL → กด Start → รีเฟรช)',
    '🔴 ' . ($downStaff === '' ? 'เรนเดอร์หน้าไม่ได้' : 'ขาดคำแนะนำ: ' . implode(', ', $staffMissing)));

// ── H2: 🛡️ ฝั่งสมาชิกต้องไม่รู้ว่าเบื้องหลังใช้อะไร ──
//    นักเรียนที่เปิดเว็บมาเจอ "เปิด XAMPP Control Panel" คือทั้งงงและ
//    เท่ากับบอกสแตกของเซิร์ฟเวอร์ให้คนนอกรู้ฟรี ๆ ตอนที่ระบบกำลังอ่อนแอที่สุด
$stackWords = ['XAMPP', 'MySQL', 'Control Panel', 'phpMyAdmin', 'Apache'];
$leakedToPublic = array_values(array_filter($stackWords, fn($w) => str_contains($downPublic, $w)));
check('PAGE-H2', $downPublic !== '' && $leakedToPublic === [],
    'หน้าล่มฝั่งสมาชิกไม่บอกสแตกเบื้องหลัง (บอกแค่ให้สอบถามที่เคาน์เตอร์)',
    '🔴 คนนอกเห็นสแตกของเซิร์ฟเวอร์: ' . implode(', ', $leakedToPublic));

// ── H3: ทั้งสองฝั่งต้องไม่รั่วความลับ ──
$secrets = ['book_borrowing', '127.0.0.1', '/Applications/XAMPP', 'SQLSTATE', 'PDOException', 'Stack trace'];
$leaked = [];
foreach (['เจ้าหน้าที่' => $downStaff, 'สมาชิก' => $downPublic] as $side => $html) {
    foreach ($secrets as $w) {
        if (str_contains($html, $w)) $leaked[] = "{$side}: {$w}";
    }
}
check('PAGE-H3', $leaked === [],
    'หน้าล่มไม่รั่วชื่อฐานข้อมูล / host / path / stack trace ทั้งสองฝั่ง',
    '🔴 รั่ว: ' . implode(' · ', $leaked));

// ── H4: ต้องยืนได้ด้วยตัวเอง — ฐานข้อมูลล่มแล้วจะไปโหลดอะไรจากไหนไม่ได้ ──
//    (ข้อบังคับที่ includes/db.php เขียนไว้เอง: ห้ามเรียก DB · ห้ามโหลด CSS/JS จากที่อื่น)
$externalRefs = [];
foreach (['เจ้าหน้าที่' => $downStaff, 'สมาชิก' => $downPublic] as $side => $html) {
    if (preg_match_all('/(?:src|href)="(?!#)([^"]+)"/', $html, $m)) {
        foreach ($m[1] as $ref) $externalRefs[] = "{$side}: {$ref}";
    }
}
$has503 = str_contains(file_get_contents(__DIR__ . '/../includes/db.php'), 'http_response_code(503)')
    && str_contains(file_get_contents(__DIR__ . '/../includes/db.php'), 'Retry-After');
check('PAGE-H4', $externalRefs === [] && $has503,
    'หน้าล่มยืนได้ด้วยตัวเอง (ไม่โหลดไฟล์จากที่อื่นเลย) และยังตอบ 503 + Retry-After',
    '🔴 ' . ($externalRefs ? 'โหลดไฟล์ภายนอก: ' . implode(' · ', $externalRefs) . ' ' : '')
        . (!$has503 ? 'ไม่ได้ตอบ 503/Retry-After แล้ว' : ''));

// ============================================================
echo "\n══════════════════════════════════════\n";
printf(" RESULTS: %d/%d passed (%.1f%%)%s\n",
    $results['passed'], $results['total'],
    $results['total'] ? $results['passed'] / $results['total'] * 100 : 0,
    $results['failed'] ? ' | ' . $results['failed'] . ' FAILED' : '');
echo "══════════════════════════════════════\n";

exit($results['failed'] > 0 ? 1 : 0);
