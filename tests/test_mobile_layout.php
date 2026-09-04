<?php

/**
 * มือถือ: ปุ่มลงมือทำต้องอยู่ในจอ — F-49
 *
 * ==========================================================================
 * 🔴 ปัญหาเดิม (วัดจริงที่ 375px ด้วยเบราว์เซอร์)
 * ==========================================================================
 * | หน้า            | ตารางกว้าง | กล่อง | ปุ่มอยู่ที่ | นอกจอ |
 * |-----------------|-----------|-------|------------|-------|
 * | borrows.php     | 841       | 317   | 842        | 467px |
 * | members.php     | 1031      | 317   | 942        | 567px |
 * | reservations.php| 1096      | 317   | 1015       | 640px |
 * | books.php       | 730       | 317   | 731        | 356px |
 * | payments.php    | 361       | 269   | 394        | 19px  |
 *
 * เอกสารระบุแค่ 2 หน้า — ของจริงเป็นทุกตารางฝั่งเจ้าหน้าที่ เพราะใช้โครงเดียวกัน
 * บรรณารักษ์ต้องลากตารางไปขวาสุดทุกครั้งก่อนกดอะไรได้สักอย่าง
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร — และทดสอบอะไรไม่ได้
 * ==========================================================================
 * ⚠️ **PHP ทดสอบการจัดวางจริงไม่ได้** ต้องมีเบราว์เซอร์คำนวณ layout
 *    ชุดนี้จึงคุม "เงื่อนไขที่ทำให้การจัดวางถูก" แทน:
 *
 * A. ทุกตารางที่มีคอลัมน์ปุ่ม ต้องติดคลาส `sticky-action`
 *    🔴 และคอลัมน์ปุ่มต้องอยู่ **ท้ายสุด** จริง ๆ เพราะ CSS ใช้ :last-child
 *       ถ้ามีคนเพิ่มคอลัมน์ต่อท้ายทีหลัง การตรึงจะไปติดคอลัมน์ผิดโดยไม่มีใครรู้
 * B. ตารางอ่านอย่างเดียว (ไม่มีปุ่ม) ต้องไม่ติดคลาส — ตรึงไปก็ไม่มีประโยชน์
 * C. CSS ที่จำเป็นมีครบ และอยู่ใน media query ของจอแคบเท่านั้น
 * D. body ต้องไม่เลื่อนซ้ายขวา (ของเดิมทำถูกอยู่แล้ว — กันไม่ให้พังทีหลัง)
 *
 * 📌 ผลวัดจริงในเบราว์เซอร์หลังแก้ (375px): ปุ่มอยู่ในจอครบทั้ง 6 ตาราง
 *    borrows 318 · members 228 · reservations 236 · books 318 · payments 302 · categories 318
 *    และที่ 1280px คอลัมน์กลับเป็น static ไม่มีเงา — ไม่แตะจอกว้าง
 *
 * 📌 การใช้งาน: php tests/test_mobile_layout.php [รหัสผ่าน admin]
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

$COOKIE = tempnam(sys_get_temp_dir(), 'bbmob');
register_shutdown_function(function () use ($COOKIE) { @unlink($COOKIE); });

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  มือถือ: ปุ่มลงมือทำต้องอยู่ในจอ (F-49)                   ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

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
 * แยก <table> ทุกตัวใน HTML ออกมาพร้อมบอกว่าติดคลาส sticky-action ไหม
 * และคอลัมน์สุดท้ายชื่ออะไร / มีปุ่มในเซลล์สุดท้ายไหม
 *
 * @return array<int, array{sticky:bool, lastHeader:string, lastCellHasAction:bool}>
 */
function analyseTables(string $html): array
{
    // 🔴 [บทเรียน] ต้องตัด <style>/<script>/คอมเมนต์ทิ้งก่อน
    //    เคยเจอ: คำว่า "<table>" ในคอมเมนต์ CSS ทำให้ regex จับ tag ปลอม
    //    แล้วตัวจับแบบ non-greedy กลืนตารางจริงทั้งก้อนเข้าไปเป็น "เนื้อใน"
    //    ผลคือมองไม่เห็นตารางจริงเลย → เทสต์แดงทั้งที่โค้ดถูก
    $html = preg_replace('/<style\b.*?<\/style>/s', '', $html);
    $html = preg_replace('/<script\b.*?<\/script>/s', '', $html);
    $html = preg_replace('/<!--.*?-->/s', '', $html);

    if (!preg_match_all('/<table\b([^>]*)>(.*?)<\/table>/s', $html, $m, PREG_SET_ORDER)) {
        return [];
    }
    $out = [];
    foreach ($m as $t) {
        $attrs = $t[1];
        $body  = $t[2];

        // ชื่อคอลัมน์สุดท้ายของหัวตาราง
        $lastHeader = '';
        if (preg_match('/<thead\b.*?<\/thead>/s', $body, $th)) {
            preg_match_all('/<th\b[^>]*>(.*?)<\/th>/s', $th[0], $cols);
            $names = array_values(array_filter(array_map(
                fn($c) => trim(preg_replace('/\s+/', ' ', strip_tags($c))),
                $cols[1] ?? []
            ), fn($x) => $x !== ''));
            $lastHeader = $names ? (string) end($names) : '';
        }

        // เซลล์สุดท้ายมีปุ่ม/ลิงก์ที่กดแล้วเกิดอะไรขึ้นไหม
        //
        // 🔴 [บทเรียน] เดิมดู "แถวแรกแถวเดียว" แล้วตัดสินทั้งตาราง
        //    ซึ่งพังทันทีที่แถวแรกบังเอิญเป็นรายการที่ไม่มีปุ่ม เช่นหน้ายืม-คืน
        //    ที่แถวแรกเป็น "คืนแล้ว" → คอลัมน์ปุ่มว่าง → ตัดสินว่าตารางไม่มีปุ่มเลย
        //    → MOB-A2/A3 แดง ทั้งที่ CSS กับ HTML ถูกต้องทุกอย่าง
        //    (เจอจริงหลัง UAT ไปกดคืนหนังสือรายการบนสุด)
        //    ความจริงคือ "ตารางนี้มีคอลัมน์ปุ่มไหม" ไม่ใช่ "แถวแรกมีปุ่มไหม"
        //    จึงต้องไล่ทุกแถว — เจอปุ่มในแถวไหนก็ถือว่ามีคอลัมน์ปุ่ม
        $lastCellHasAction = false;
        $rowCount = 0;
        if (preg_match('/<tbody\b.*?<\/tbody>/s', $body, $tb)
            && preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/s', $tb[0], $trs, PREG_SET_ORDER)) {
            foreach ($trs as $tr) {
                preg_match_all('/<td\b[^>]*>(.*?)<\/td>/s', $tr[1], $tds);
                $cells = $tds[1] ?? [];
                if (!$cells) continue;   // แถวว่าง/แถวข้อความ "ไม่พบข้อมูล" ไม่นับเป็นแถวข้อมูล
                $rowCount++;
                if (preg_match('/<(button|a)\b/i', (string) end($cells))) {
                    $lastCellHasAction = true;
                }
            }
        }

        $out[] = [
            'sticky'            => str_contains($attrs, 'sticky-action'),
            'lastHeader'        => $lastHeader,
            'lastCellHasAction' => $lastCellHasAction,
            // 🧠 ตารางที่ไม่มีแถวข้อมูลเลย ตัดสินจากเนื้อในไม่ได้ (ติดตั้งสดจะเจอบ่อย)
            //    ต้องข้ามไป ไม่ใช่ตัดสินว่า "ไม่มีปุ่ม" แล้วฟ้องผิด ๆ
            'rowCount'          => $rowCount,
        ];
    }
    return $out;
}

$login = http('GET', "$BASE_URL/login.php");
$r = http('POST', "$BASE_URL/login.php", [
    'csrf_token' => csrfFrom($login), 'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD,
]);
if (!str_contains($r, 'ออกจากระบบ') && !str_contains($r, 'logout')) {
    fail('MOB-00', 'ล็อกอินไม่สำเร็จ — ส่งรหัสผ่าน admin เป็น argument');
    exit(1);
}

// ============================================================
// A. ทุกตารางที่มีปุ่ม ต้องตรึงคอลัมน์ปุ่ม
// ============================================================
echo "── A. ตารางที่มีปุ่มต้องตรึงคอลัมน์ปุ่ม ──\n";

$pages = [
    'borrows.php'      => 'ยืม-คืน',
    'members.php'      => 'สมาชิก',
    'books.php'        => 'หนังสือ',
    'reservations.php' => 'รายการจอง',
    'payments.php'     => 'ค่าปรับ',
    'categories.php'   => 'หมวดหมู่',
];

$missing = [];
$covered = [];
$stickyWithoutAction = [];

$emptyTables = [];
foreach ($pages as $file => $label) {
    $html = http('GET', "$BASE_URL/admin/{$file}");
    foreach (analyseTables($html) as $i => $t) {
        // ตารางที่ยังไม่มีข้อมูลเลย ตัดสินไม่ได้ — จดไว้ให้เห็น ไม่ข้ามเงียบ ๆ
        if ($t['rowCount'] === 0) {
            $emptyTables[] = "{$label} (ตารางที่ " . ($i + 1) . ")";
            continue;
        }
        if ($t['lastCellHasAction'] && !$t['sticky']) {
            $missing[] = "{$label} (ตารางที่ " . ($i + 1) . ", คอลัมน์ท้าย \"{$t['lastHeader']}\")";
        }
        if ($t['lastCellHasAction'] && $t['sticky']) {
            $covered[] = $label;
        }
        // 🔴 ติดคลาสกับตารางที่ไม่มีปุ่ม = ตรึงคอลัมน์ข้อมูลเฉย ๆ ทำให้อ่านยากขึ้นเปล่า ๆ
        if (!$t['lastCellHasAction'] && $t['sticky']) {
            $stickyWithoutAction[] = "{$label} (ตารางที่ " . ($i + 1) . ")";
        }
    }
}

if ($emptyTables) {
    echo "  ℹ️  ข้ามตารางที่ยังไม่มีข้อมูล " . count($emptyTables) . " ตาราง: "
        . implode(' · ', array_slice($emptyTables, 0, 4))
        . (count($emptyTables) > 4 ? ' …' : '') . "\n";
}

check('MOB-A1', $missing === [],
    'ตารางที่มีปุ่มติดคลาสตรึงครบ ' . count($covered) . ' ตาราง (' . implode(', ', array_unique($covered)) . ')',
    '🔴 ยังมีตารางที่ปุ่มจะอยู่นอกจอ: ' . implode(' · ', $missing));

check('MOB-A2', $stickyWithoutAction === [],
    'ตารางอ่านอย่างเดียวไม่ได้ติดคลาสตรึงโดยไม่จำเป็น',
    'ติดคลาสตรึงกับตารางที่ไม่มีปุ่ม: ' . implode(' · ', $stickyWithoutAction));

// A3 — 🔴 CSS ใช้ :last-child ฉะนั้นคอลัมน์ปุ่ม **ต้องอยู่ท้ายสุด**
//      ถ้ามีคนเพิ่มคอลัมน์ต่อท้ายทีหลัง การตรึงจะไปติดคอลัมน์ผิดแบบเงียบ ๆ
$notLast = [];
foreach ($pages as $file => $label) {
    $html = http('GET', "$BASE_URL/admin/{$file}");
    foreach (analyseTables($html) as $i => $t) {
        if (!$t['sticky']) continue;
        if ($t['rowCount'] === 0) continue;   // ไม่มีข้อมูลให้ดู ตัดสินไม่ได้ (นับไว้แล้วใน $emptyTables)
        if (!$t['lastCellHasAction']) {
            $notLast[] = "{$label}: คอลัมน์ท้ายคือ \"{$t['lastHeader']}\" ซึ่งไม่มีปุ่ม";
        }
    }
}
check('MOB-A3', $notLast === [],
    'ทุกตารางที่ตรึงไว้ คอลัมน์ท้ายสุดคือคอลัมน์ปุ่มจริง (CSS ใช้ :last-child)',
    '🔴 คอลัมน์ท้ายไม่ใช่คอลัมน์ปุ่ม → ตรึงผิดคอลัมน์: ' . implode(' · ', $notLast));

// ============================================================
// B. CSS ที่จำเป็นต้องมีครบ
// ============================================================
echo "\n── B. CSS ที่ทำให้การตรึงทำงาน ──\n";

$headerSrc = (string) file_get_contents($ROOT . '/admin/header.php');
preg_match_all('/@media\s*\(max-width:\s*\d+px\)\s*\{(.*?)\n        \}/s', $headerSrc, $mq);
$mobileCss = implode("\n", $mq[1] ?? []);

/**
 * ดึงเนื้อในของ CSS rule ที่ selector ตรงกับ $needle
 *
 * 🔴 [บทเรียน] เคยเช็คแค่ว่า "คำนี้โผล่ที่ไหนสักแห่งใน media query ไหม"
 *    พอลบ background-color ออกจากกฎของช่องข้อมูล เทสต์ยัง**เขียว**
 *    เพราะกฎของหัวตารางก็มีคำว่า background-color เหมือนกัน
 *    → ต้องดูเป็นราย rule ไม่ใช่เหมารวมทั้งก้อน
 */
function cssRuleBody(string $css, string $needle): string
{
    if (!preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $m, PREG_SET_ORDER)) return '';
    foreach ($m as $rule) {
        if (str_contains($rule[1], $needle)) return $rule[2];
    }
    return '';
}

// 🎯 ดูกฎของ "ช่องข้อมูล" โดยเฉพาะ — เป็นตัวที่ต้องทึบและต้องตรึง
$cellRule = cssRuleBody($mobileCss, '.sticky-action td:last-child');
$needed = [
    'position: sticky' => 'ตรึงคอลัมน์',
    'right: 0'         => 'ตรึงไว้ขวาสุด',
    'background-color' => 'พื้นทึบ — ไม่งั้นข้อมูลที่เลื่อนอยู่ข้างหลังทะลุมาซ้อน',
];
$missingCss = [];
foreach ($needed as $rule => $why) {
    if (!str_contains($cellRule, $rule)) $missingCss[] = "{$rule} ({$why})";
}
// z-index อยู่คนละ rule ได้ แต่ต้องมีสักที่ใน media query
if (!str_contains($mobileCss, 'z-index')) {
    $missingCss[] = 'z-index (ลำดับชั้น — ไม่งั้นข้อมูลทับปุ่ม)';
}
check('MOB-B1', $mobileCss !== '' && $missingCss === [],
    'กฎของช่องข้อมูลมีครบทุกส่วนที่จำเป็น (' . (count($needed) + 1) . ' ข้อ)',
    '🔴 ขาด: ' . ($mobileCss === '' ? 'ไม่พบ media query จอแคบเลย' : implode(' · ', $missingCss)));

// B2 — 🔴 หัวตารางต้องตรึงด้วย ไม่งั้นหัวกับข้อมูลเหลื่อมกันตอนเลื่อน
check('MOB-B2',
    (bool) preg_match('/\.sticky-action\s+th:last-child/', $mobileCss),
    'หัวตาราง (th) ถูกตรึงด้วย ไม่ใช่แค่ช่องข้อมูล',
    '🔴 ตรึงแค่ td ไม่ตรึง th → หัวตารางเลื่อนหนีข้อมูลตอนลากตาราง');

// B3 — 🔴 ต้องอยู่ใน media query ที่จำกัด **ความกว้างสูงสุด** เท่านั้น
//      เช็คแค่ "อยู่ใน media query สักอัน" ไม่พอ — @media all ก็ผ่าน
//      ทั้งที่ @media all คือใช้กับทุกจอรวมจอคอมด้วย
$mediaQueries = [];
if (preg_match_all('/@media([^{]*)\{((?:[^{}]|\{[^{}]*\})*)\}/s', $headerSrc, $mm, PREG_SET_ORDER)) {
    foreach ($mm as $q) {
        if (str_contains($q[2], '.sticky-action')) $mediaQueries[] = trim($q[1]);
    }
}
$outsideAnyMq = preg_replace('/@media[^{]*\{(?:[^{}]|\{[^{}]*\})*\}/s', '', $headerSrc);
$narrowOnly = $mediaQueries !== []
    && !str_contains($outsideAnyMq, '.sticky-action');
foreach ($mediaQueries as $q) {
    if (!preg_match('/max-width:\s*\d+px/', $q)) $narrowOnly = false;
}
check('MOB-B3', $narrowOnly,
    'กฎการตรึงอยู่ใน media query จอแคบเท่านั้น (' . implode(', ', $mediaQueries) . ') — จอกว้างไม่โดน',
    '🔴 กฎ .sticky-action ไม่ได้จำกัดเฉพาะจอแคบ — เจอใน: '
        . ($mediaQueries ? implode(' · ', $mediaQueries) : 'นอก media query ทั้งหมด'));

// ============================================================
// C. body ต้องไม่เลื่อนซ้ายขวา (ของเดิมถูกอยู่แล้ว — กันพังทีหลัง)
// ============================================================
echo "\n── C. ตารางต้องเลื่อนในกล่องตัวเอง ──\n";

$noBox = [];
foreach ($pages as $file => $label) {
    $html = http('GET', "$BASE_URL/admin/{$file}");
    // ทุกตารางที่ติดคลาสตรึง ต้องมีกล่อง overflow-x-auto ครอบอยู่
    // ไม่งั้นตารางจะดันให้ทั้งหน้าเลื่อนซ้ายขวา แล้ว sticky ก็ไม่มีความหมาย
    if (preg_match_all('/<table\b[^>]*sticky-action[^>]*>/', $html, $tm)) {
        foreach ($tm[0] as $tag) {
            $before = substr($html, 0, (int) strpos($html, $tag));
            // ดูย้อนขึ้นไป 400 ตัวอักษรว่ามี overflow-x-auto ครอบไหม
            if (!str_contains(substr($before, -400), 'overflow-x-auto')) {
                $noBox[] = $label;
            }
        }
    }
}
check('MOB-C1', $noBox === [],
    'ทุกตารางที่ตรึงไว้อยู่ในกล่อง overflow-x-auto — body ไม่เลื่อนซ้ายขวา',
    '🔴 ตารางไม่มีกล่องครอบ ทั้งหน้าจะเลื่อนซ้ายขวาแทน: ' . implode(', ', array_unique($noBox)));

// ============================================================
// SUMMARY
// ============================================================
$pct = $results['total'] > 0 ? round($results['passed'] / $results['total'] * 100, 1) : 0;
echo "\n══════════════════════════════════════\n";
echo " RESULTS: {$results['passed']}/{$results['total']} passed ($pct%)";
if ($results['failed'] > 0) echo " | {$results['failed']} FAILED";
echo "\n══════════════════════════════════════\n\n";

exit($results['failed'] > 0 ? 1 : 0);
