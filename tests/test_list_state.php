<?php

/**
 * ทดสอบ "บันทึกแล้วต้องกลับที่เดิม" — F-37
 *
 * ==========================================================================
 * 🔴 บั๊กเดิมที่ต้องกันไม่ให้กลับมา
 * ==========================================================================
 * ทุกการกระทำที่บันทึกข้อมูลจะ redirect กลับหน้าแรกของรายการ
 * โดยไม่พา page และตัวกรองกลับไปด้วย
 *   - กรอง filter=overdue (26 รายการ) แล้วกดคืน 1 เล่ม → ตัวกรองหาย
 *   - อยู่ members.php?page=5 กดแก้ไข แล้วบันทึก → ลงที่หน้า 1
 * เคลียร์รายการเกินกำหนด 26 รายการ = กดปุ่มกรองใหม่ 26 รอบ
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. ตัวช่วย listState() — whitelist · ชนิดค่า · ค่าว่าง · ความยาว
 * B. 🛡️ ความปลอดภัย — ยัด URL/CRLF/array เข้ามาต้องออกนอกระบบไม่ได้
 * C. ลิงก์ "แก้ไข" ในหน้ารายการต้องพาสถานะไปด้วย (ทอดที่ 1)
 * D. หน้าฟอร์มต้องพ่นกลับเป็น hidden + ปุ่มยกเลิกกลับที่เดิม (ทอดที่ 2)
 * E. บันทึกแล้ว redirect กลับพร้อมสถานะ (ทอดที่ 3) — วงจรเต็มผ่าน HTTP
 * F. ทุกหน้าที่ redirect หลังบันทึกต้องใช้ helper ไม่มีใครหลงเหลือ
 *
 * 🧹 ไม่เปลี่ยนข้อมูลจริง — เคสที่บันทึกจะส่งค่าเดิมกลับไป และไม่แตะสต็อก
 *
 * 📌 การใช้งาน: php tests/test_list_state.php [รหัสผ่าน admin]
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

$COOKIE = tempnam(sys_get_temp_dir(), 'bbstate');
register_shutdown_function(fn() => @unlink($COOKIE));

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  บันทึกแล้วต้องกลับที่เดิม (F-37)                         ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

// ============================================================
// A. ตัวช่วย listState()
// ============================================================
echo "── A. ตัวช่วยกรองสถานะ ──\n";

$allowed = ['page', 'search', 'filter'];

$r = listState($allowed, ['page' => '5', 'search' => 'ทดสอบ', 'filter' => 'overdue']);
check('STATE-A1',
    $r === ['page' => '5', 'search' => 'ทดสอบ', 'filter' => 'overdue'],
    'ค่าที่อยู่ใน whitelist ผ่านครบ',
    'ผลผิด: ' . json_encode($r, JSON_UNESCAPED_UNICODE));

$r = listState($allowed, ['page' => '5', 'evil' => 'x', 'print' => '1', 'sort' => 'a']);
check('STATE-A2',
    $r === ['page' => '5'],
    'พารามิเตอร์นอก whitelist ถูกทิ้งทั้งหมด (evil / print / sort)',
    '🔴 มีของนอก whitelist หลุดมา: ' . json_encode($r));

$r = listState($allowed, ['page' => '', 'search' => '   ', 'filter' => 'x']);
check('STATE-A3',
    $r === ['filter' => 'x'],
    'ค่าว่าง/ช่องว่างล้วน ถูกตัดทิ้ง — URL ไม่รก',
    'ผลผิด: ' . json_encode($r));

// A4 — เลขหน้าต้องเป็นตัวเลขบวกเท่านั้น
$cases = ['5' => true, '1' => true, '0' => false, '-5' => false, 'abc' => false, '//evil.com' => false, '3.5' => false];
$bad = [];
foreach ($cases as $val => $shouldPass) {
    $got = isset(listState(['page'], ['page' => (string) $val])['page']);
    if ($got !== $shouldPass) $bad[] = "{$val} (ได้ " . ($got ? 'ผ่าน' : 'ตก') . ')';
}
check('STATE-A4', $bad === [],
    'เลขหน้ารับเฉพาะจำนวนเต็มบวก — 0 / ติดลบ / ตัวอักษร / URL ถูกปฏิเสธหมด',
    '🔴 ตรวจเลขหน้าไม่ถูก: ' . implode(' · ', $bad));

// A5 — ตัดความยาว
$long = str_repeat('ก', 500);
$r = listState(['search'], ['search' => $long]);
check('STATE-A5',
    isset($r['search']) && mb_strlen($r['search']) === 200,
    'คำค้นยาวเกินถูกตัดเหลือ 200 ตัวอักษร',
    'ความยาวผิด: ' . (isset($r['search']) ? mb_strlen($r['search']) : 'ไม่มีค่า'));

// A6 — prefix ใช้ได้ (ทอดที่ส่งผ่านหน้าฟอร์ม)
$r = listState($allowed, ['ret_page' => '7', 'page' => '99'], 'ret_');
check('STATE-A6',
    $r === ['page' => '7'],
    'อ่านผ่าน prefix ret_ ได้ และไม่ปนกับ page ของหน้าฟอร์มเอง',
    'ผลผิด: ' . json_encode($r));

// A7 — ประกอบ query string
check('STATE-A7',
    listStateQuery(['page' => '5', 'filter' => 'overdue']) === '?page=5&filter=overdue'
        && listStateQuery([]) === '',
    'ประกอบ query string ถูกต้อง และคืนค่าว่างเมื่อไม่มีอะไร',
    'ประกอบ query string ผิด: ' . listStateQuery(['page' => '5', 'filter' => 'overdue']));

// ============================================================
// B. ความปลอดภัย
// ============================================================
echo "\n── B. ความปลอดภัย ──\n";

// B1 — array/object ต้องถูกทิ้ง (กันการยัด structure)
$r = listState($allowed, ['page' => ['1', '2'], 'search' => (object) ['a' => 1], 'filter' => 'ok']);
check('STATE-B1',
    $r === ['filter' => 'ok'],
    'ค่าที่ไม่ใช่ scalar (array/object) ถูกทิ้ง',
    '🔴 มี array/object หลุดผ่าน: ' . json_encode($r));

// B2 — 🔴 ต่อให้ค่าเป็น URL เต็ม ก็ต้องกลายเป็นแค่ query param บนหน้าเราเอง
$evil = listStateQuery(listState(['search'], ['search' => 'https://evil.com']));
check('STATE-B2',
    str_starts_with($evil, '?search=') && !str_contains($evil, '://'),
    'URL ที่ยัดเข้ามากลายเป็นค่าที่ถูก encode ในพารามิเตอร์ ไม่ใช่ปลายทาง',
    '🔴 URL หลุดออกมาดิบ ๆ: ' . $evil);

// B3 — helper ไม่มีทางคืน "ปลายทาง" ให้ผู้ใช้กำหนดได้เลย
//     ตรวจที่ตัวโค้ดว่า redirectToList ประกอบ path จาก argument ที่ผู้เรียกส่งมาเท่านั้น
$fnSrc = (string) file_get_contents(__DIR__ . '/../includes/functions.php');
preg_match('/function redirectToList\(.*?\n\}/s', $fnSrc, $m);
$body = $m[0] ?? '';
check('STATE-B3',
    $body !== '' && !preg_match('/\$_(GET|POST|REQUEST|SERVER)\s*\[/', str_replace('$source', '', $body)),
    'redirectToList() ไม่อ่าน superglobal เองเพื่อหาปลายทาง — path มาจาก argument เท่านั้น',
    '🔴 redirectToList() อ่าน superglobal มาประกอบปลายทาง — เสี่ยง open redirect');

// ============================================================
// C–E. ผ่านหน้าเว็บจริง
// ============================================================
function http(string $method, string $url, array $fields = [], bool $follow = true): array
{
    global $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $COOKIE,
        CURLOPT_COOKIEFILE     => $COOKIE,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HEADER         => !$follow,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc  = '';
    if (!$follow && preg_match('/^Location:\s*(.+)$/mi', $raw, $mm)) {
        $loc = trim($mm[1]);
    }
    curl_close($ch);
    return ['body' => $raw, 'code' => $code, 'location' => $loc];
}

function csrfFrom(string $html): string
{
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

function fieldFrom(string $html, string $name): string
{
    return preg_match('/name="' . preg_quote($name, '/') . '"\s+value="([^"]*)"/', $html, $m) ? $m[1] : '';
}

$login = http('GET', "$BASE_URL/login.php");
$r = http('POST', "$BASE_URL/login.php", [
    'csrf_token' => csrfFrom($login['body']),
    'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD,
]);

if (!str_contains($r['body'], 'ออกจากระบบ') && !str_contains($r['body'], 'logout')) {
    fail('STATE-C1', 'ล็อกอินไม่สำเร็จ — ข้ามการทดสอบผ่านหน้าเว็บ (ส่งรหัสผ่าน admin เป็น argument)');
} else {
    // ── C. ทอดที่ 1: ลิงก์แก้ไขพาสถานะไปด้วย ──
    echo "\n── C. ลิงก์ \"แก้ไข\" พาสถานะไปด้วย (ทอดที่ 1) ──\n";

    $mList = http('GET', "$BASE_URL/admin/members.php?page=5&sort=newest");
    preg_match('/member_form\.php\?id=\d+[^"]*/', $mList['body'], $mm);
    // 🧠 ใน HTML เครื่องหมาย & ต้องเขียนเป็น &amp; (ถูกต้องตามมาตรฐาน เบราว์เซอร์ถอดให้เอง)
    //    เทสต์ยิง URL ดิบจึงต้องถอดก่อน ไม่งั้นชื่อพารามิเตอร์จะกลายเป็น amp;ret_page
    $memberLink = html_entity_decode($mm[0] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    check('STATE-C1',
        str_contains($memberLink, 'ret_page=5') && str_contains($memberLink, 'ret_sort=newest'),
        "ลิงก์แก้ไขสมาชิกพาสถานะไปด้วย: {$memberLink}",
        '🔴 ลิงก์แก้ไขไม่พาสถานะ: ' . ($memberLink ?: 'ไม่พบลิงก์'));

    $bList = http('GET', "$BASE_URL/admin/books.php?page=3&status=available");
    preg_match('/book_form\.php\?id=\d+[^"]*/', $bList['body'], $bm);
    $bookLink = html_entity_decode($bm[0] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    check('STATE-C2',
        str_contains($bookLink, 'ret_page=3') && str_contains($bookLink, 'ret_status=available'),
        "ลิงก์แก้ไขหนังสือพาสถานะไปด้วย: {$bookLink}",
        '🔴 ลิงก์แก้ไขหนังสือไม่พาสถานะ: ' . ($bookLink ?: 'ไม่พบลิงก์'));

    // ── D. ทอดที่ 2: หน้าฟอร์มพ่นกลับ ──
    echo "\n── D. หน้าฟอร์มส่งต่อสถานะ (ทอดที่ 2) ──\n";

    $form = http('GET', "$BASE_URL/admin/{$memberLink}");
    check('STATE-D1',
        str_contains($form['body'], 'name="ret_page" value="5"')
            && str_contains($form['body'], 'name="ret_sort" value="newest"'),
        'หน้าฟอร์มพ่นสถานะกลับเป็น hidden field ครบ',
        '🔴 หน้าฟอร์มไม่ได้ส่งต่อสถานะ — ต่อให้ redirect ถูกก็กู้ไม่ได้');

    check('STATE-D2',
        preg_match('/href="members\.php\?[^"]*page=5[^"]*"/', html_entity_decode($form['body'], ENT_QUOTES | ENT_HTML5, 'UTF-8')) === 1,
        'ปุ่มยกเลิกพากลับไปหน้า 5 ด้วย',
        '🔴 ปุ่มยกเลิกกลับไปหน้าแรก ทั้งที่มาจากหน้า 5');

    // ── E. ทอดที่ 3: บันทึกแล้วกลับที่เดิม ──
    echo "\n── E. บันทึกแล้วกลับที่เดิม (ทอดที่ 3) ──\n";

    // E1 — วงจรเต็มของหน้าแก้ไขสมาชิก (ส่งค่าเดิมกลับไป ไม่เปลี่ยนข้อมูลจริง)
    $memberId = preg_match('/id=(\d+)/', $memberLink, $im) ? $im[1] : '0';
    $post = [
        'csrf_token' => csrfFrom($form['body']),
        'id'    => $memberId,
        'name'  => fieldFrom($form['body'], 'name'),
        'email' => fieldFrom($form['body'], 'email'),
        'role'  => 'member',
        'ret_page' => '5', 'ret_sort' => 'newest',
    ];
    $save = http('POST', "$BASE_URL/admin/member_form.php", $post, false);
    check('STATE-E1',
        str_contains($save['location'], 'page=5') && str_contains($save['location'], 'sort=newest'),
        "บันทึกแล้วกลับไป: {$save['location']}",
        "🔴 บันทึกแล้วเด้งไป: {$save['location']} — สถานะหาย");

    // E2 — 🔴 ตัวกรอง overdue ต้องยังอยู่หลังกดคืนหนังสือ (เคสที่ FINDINGS ยกมา)
    //      ใช้ action ที่ไม่มีอยู่จริง เพื่อดูแค่ปลายทางของ redirect โดยไม่แตะข้อมูล
    $bp = http('GET', "$BASE_URL/admin/borrows.php?filter=overdue");
    $ret = http('POST', "$BASE_URL/admin/borrows.php?filter=overdue", [
        'csrf_token' => csrfFrom($bp['body']),
        'action' => 'return', 'borrow_id' => 0,   // id 0 = ไม่มีจริง → Service ปฏิเสธ ข้อมูลไม่ถูกแตะ
    ], false);
    check('STATE-E2',
        str_contains($ret['location'], 'filter=overdue'),
        "กดคืนในหน้าที่กรอง overdue แล้วตัวกรองยังอยู่: {$ret['location']}",
        "🔴 ตัวกรองหายหลังกดคืน: {$ret['location']} — นี่คือเคสที่ F-37 ยกมาเป็นตัวอย่าง");

    // E3 — 🛡️ ยัดของอันตรายเข้ามาต้องอยู่ในระบบเสมอ
    $attacks = ['//evil.com', 'https://evil.com', "5\r\nLocation: https://evil.com", 'javascript:alert(1)'];
    $escaped = [];
    foreach ($attacks as $a) {
        $res = http('POST', "$BASE_URL/admin/member_form.php", array_merge($post, ['ret_page' => $a]), false);
        $loc = $res['location'];
        // ต้องเป็น path ในระบบเสมอ — ห้ามขึ้นต้นด้วย // หรือมี scheme
        if ($loc === '' || str_starts_with($loc, '//') || preg_match('#^[a-z]+://#i', $loc)) {
            $escaped[] = mb_substr($a, 0, 25) . ' → ' . mb_substr($loc, 0, 40);
        }
    }
    check('STATE-E3', $escaped === [],
        'ยัด URL / CRLF / javascript: เข้ามา ' . count($attacks) . ' แบบ — redirect อยู่ในระบบทุกครั้ง',
        '🔴 หลุดออกนอกระบบ: ' . implode(' · ', $escaped));

    // E4 — พารามิเตอร์นอก whitelist ต้องไม่ติดไปกับ URL
    $res = http('POST', "$BASE_URL/admin/member_form.php", array_merge($post, ['ret_print' => '1', 'ret_evil' => 'x']), false);
    check('STATE-E4',
        !str_contains($res['location'], 'print') && !str_contains($res['location'], 'evil'),
        "พารามิเตอร์นอก whitelist ไม่ติดไปกับ URL: {$res['location']}",
        "🔴 ของนอก whitelist หลุดเข้า URL: {$res['location']}");

    // E5 — ไม่ส่งสถานะมาเลย ต้องกลับหน้ารายการเปล่า ๆ ได้ปกติ (ไม่พังและไม่มี ? ห้อยท้าย)
    $res = http('POST', "$BASE_URL/admin/member_form.php", array_merge($post, ['ret_page' => '', 'ret_sort' => '']), false);
    check('STATE-E5',
        $res['location'] === 'members.php',
        'ไม่มีสถานะให้พากลับ → ลงหน้ารายการเปล่า ๆ ไม่มี ? ห้อยท้าย',
        "URL ไม่สะอาด: {$res['location']}");
}

// ============================================================
// F. ไม่มีหน้าไหนหลงเหลือ
// ============================================================
echo "\n── F. ทุกหน้าใช้ helper ครบ ──\n";

// 🧠 หน้าที่มีรายการให้กรอง/แบ่งหน้า ต้องไม่ redirect กลับแบบไม่พาสถานะ
//    (categories ไม่มีตัวกรอง จึงยกเว้น · borrow_form/settings ไม่ได้กลับไปหน้ารายการที่กรองไว้)
$listPages = ['books.php', 'members.php', 'borrows.php', 'reservations.php', 'payments.php'];
$leftovers = [];
foreach (glob(__DIR__ . '/../admin/*.php') as $file) {
    $src = (string) file_get_contents($file);
    foreach ($listPages as $target) {
        if (preg_match("/redirect\('" . preg_quote($target, '/') . "'\)/", $src)) {
            $leftovers[] = basename($file) . " → redirect('{$target}')";
        }
    }
}
check('STATE-F1', $leftovers === [],
    'ไม่มี redirect กลับหน้ารายการแบบไม่พาสถานะหลงเหลือ',
    "🔴 ยังมีที่หลงเหลือ:\n       " . implode("\n       ", $leftovers));

// F2 — 🔴 ห้ามต่อ $_SERVER['QUERY_STRING'] ดิบเข้า redirect อีก
//      วิธีนั้นปล่อยพารามิเตอร์แปลกปลอมผ่านได้ทั้งหมด ไม่มี whitelist
$rawQuery = [];
foreach (glob(__DIR__ . '/../admin/*.php') as $file) {
    $src = (string) file_get_contents($file);
    if (preg_match("/redirect\([^)]*QUERY_STRING/", $src)) {
        $rawQuery[] = basename($file);
    }
}
check('STATE-F2', $rawQuery === [],
    'ไม่มีที่ไหนต่อ $_SERVER[QUERY_STRING] ดิบเข้า redirect — ผ่าน whitelist ทุกจุด',
    '🔴 ยังต่อ query string ดิบอยู่ที่: ' . implode(', ', $rawQuery));

// ============================================================
// SUMMARY
// ============================================================
$pct = $results['total'] > 0 ? round($results['passed'] / $results['total'] * 100, 1) : 0;
echo "\n══════════════════════════════════════\n";
echo " RESULTS: {$results['passed']}/{$results['total']} passed ($pct%)";
if ($results['failed'] > 0) echo " | {$results['failed']} FAILED";
echo "\n══════════════════════════════════════\n\n";

exit($results['failed'] > 0 ? 1 : 0);
