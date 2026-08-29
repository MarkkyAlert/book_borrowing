<?php

/**
 * กล่องยืนยันต้องบอกว่า "ทำอะไร กับใคร" — F-47
 *
 * ==========================================================================
 * 🔴 ปัญหาเดิม
 * ==========================================================================
 * หน้าฝั่งเจ้าหน้าที่เป็นตารางที่ทุกแถวหน้าตาเหมือนกัน กดผิดแถวแล้วไม่มีทางรู้ตัว
 * เพราะกล่องยืนยันเขียนแค่ "ยืนยันการลบหนังสือเล่มนี้?" — ไม่บอกว่าเล่มไหน
 *
 * | ที่ | ข้อความเดิม |
 * |---|---|
 * | อนุมัติการจอง  | ยืนยันอนุมัติการยืม?          |
 * | ยกเลิกการจอง   | ยืนยันยกเลิกการจอง?           |
 * | ลบหมวดหมู่     | ยืนยันการลบหมวดหมู่นี้?       |
 * | ลบหนังสือ      | ยืนยันการลบหนังสือเล่มนี้?    |
 * | ลบสมาชิก       | ยืนยันการลบสมาชิกคนนี้?       |
 * | เปลี่ยนสิทธิ์  | **ไม่มีกล่องยืนยันเลย**       |
 *
 * 🔀 ที่กลับหัวกลับหาง: `my_reservations.php` ฝั่ง**สมาชิก** ส่งชื่อหนังสือเข้ากล่องอยู่แล้ว
 *    ฝั่งเจ้าหน้าที่ซึ่งกดวันละหลายสิบครั้งกลับแย่กว่า
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. ทุกกล่องมีข้อมูลระบุตัวจริงจาก DB (ชื่อเล่ม/ชื่อคน/ชื่อหมวด)
 * B. 🔴 escape ปลอดภัย — ชื่อที่มี ' " \ ต้องไม่ทำให้ปุ่มพังเงียบ ๆ
 *    (นี่คือจุดที่วิธีแก้แบบง่าย ๆ จะพัง และพังแบบไม่มี error ให้เห็น)
 * C. เปลี่ยนสิทธิ์ต้องยืนยัน — และต้องยืนยัน **เฉพาะตอนเปลี่ยนจริง**
 *
 * 🧹 ลบหนังสือ/หมวดหมู่/สมาชิกที่สร้างขึ้นเองทั้งหมด
 *
 * 📌 การใช้งาน: php tests/test_confirm_dialogs.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/BookService.php';

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
$COOKIE = tempnam(sys_get_temp_dir(), 'bbcfm');

$madeBooks = $madeCats = $madeUsers = [];
$cleanupDone = false;
$cleanup = function () use (&$madeBooks, &$madeCats, &$madeUsers, &$cleanupDone, $pdo, $COOKIE) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    echo "\n── CLEANUP ──\n";
    // 🔴 rollBack ก่อนเสมอ ไม่งั้น DELETE จะถูกย้อนไปด้วยถ้ามี transaction ค้าง
    try {
        if ($pdo->inTransaction()) $pdo->rollBack();
    } catch (Throwable $e) {
        echo '  ⚠️ rollback ไม่สำเร็จ: ' . $e->getMessage() . "\n";
    }
    // 🧹 ลบทีละรายการ — ถ้าตัวใดติด FK ต้องไม่ลากตัวอื่นค้างไปด้วย (บทเรียนจาก F-52)
    $failed = [];
    foreach ([['books', $madeBooks], ['categories', $madeCats], ['users', $madeUsers]] as [$table, $ids]) {
        foreach ($ids as $id) {
            try {
                $pdo->prepare("DELETE FROM `{$table}` WHERE id = ?")->execute([(int) $id]);
            } catch (Throwable $e) {
                $failed[] = "{$table}#{$id}";
            }
        }
    }
    echo '  ลบหนังสือ ' . count($madeBooks) . ' · หมวดหมู่ ' . count($madeCats)
        . ' · สมาชิก ' . count($madeUsers) . " รายการ\n";
    if ($failed) echo '  🔴 ลบไม่สำเร็จ ต้องลบมือ: ' . implode(' · ', $failed) . "\n";
    @unlink($COOKIE);
};
register_shutdown_function($cleanup);

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  กล่องยืนยันต้องบอกว่าทำอะไรกับใคร (F-47)                ║\n";
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
 * ถอด JS string literal ให้ได้ข้อความจริงแบบที่เบราว์เซอร์เห็น
 *
 * 🔴 [บทเรียน] ฉบับแรกใช้ json_decode ตรง ๆ ซึ่งอ่านได้แค่ literal ที่ครอบด้วย "
 *    ทำให้เทสต์ **แดงกับวิธีเขียนที่ทำงานได้จริง** (เช่น addslashes + ครอบด้วย ')
 *    = ผูกกับ "วิธีที่เราเลือกเขียน" ไม่ใช่ "พฤติกรรมที่ต้องเป็น"
 *    ตัวนี้รับทั้งสองแบบ เทสต์จึงวัดสิ่งเดียวที่สำคัญ:
 *    **สตริงที่ JS ได้รับ ต้องเท่ากับข้อความต้นฉบับ**
 *
 * @return string|null null = ไม่ใช่ string literal ที่ถูกต้อง (= ปุ่มพังในเบราว์เซอร์จริง)
 */
function decodeJsStringLiteral(string $literal): ?string
{
    $literal = trim($literal);
    if (strlen($literal) < 2) return null;

    $quote = $literal[0];
    if (!in_array($quote, ['"', "'"], true) || substr($literal, -1) !== $quote) return null;

    $body = substr($literal, 1, -1);

    // 🔴 JS ห้ามมี line terminator **ดิบ ๆ** ใน string literal — เป็น SyntaxError
    //    จุดนี้สำคัญเพราะข้อความของเราเป็นหลายบรรทัด (ชื่อเล่ม / ชื่อคน คนละบรรทัด)
    //    วิธี escape ที่ไม่แปลง \n ให้เป็น \\n จะพังในเบราว์เซอร์ทั้งที่ดูเผิน ๆ เหมือนถูก
    if (preg_match('/[\r\n\x{2028}\x{2029}]/u', $body)) return null;

    $out  = '';
    for ($i = 0; $i < strlen($body); $i++) {
        $ch = $body[$i];
        if ($ch === $quote) return null;   // quote ที่ไม่ถูก escape = literal จบก่อนเวลา
        if ($ch !== '\\') { $out .= $ch; continue; }
        $next = $body[++$i] ?? '';
        $out .= match ($next) {
            'n' => "\n", 't' => "\t", 'r' => "\r",
            '\\' => '\\', '"' => '"', "'" => "'", '/' => '/',
            default => $next,
        };
    }
    return $out;
}

/**
 * ดึงข้อความในกล่องยืนยันของฟอร์มที่มี input value=$needle
 *
 * ถอด HTML entity ก่อน (เบราว์เซอร์ทำขั้นนี้ตอน parse attribute)
 * แล้วค่อยถอด JS string literal — ถ้าถอดไม่ได้ = ปุ่มกดไม่ติดของจริง
 *
 * @return array{raw:string,text:?string}|null null = หาฟอร์มไม่เจอ
 */
function confirmMessageFor(string $html, string $needle): ?array
{
    // จับทั้ง <form ... onsubmit="..."> ... value="$needle" ... </form>
    if (!preg_match_all('/<form\b[^>]*onsubmit="([^"]*)"[^>]*>(.*?)<\/form>/s', $html, $forms, PREG_SET_ORDER)) {
        return null;
    }
    foreach ($forms as $f) {
        if (!str_contains($f[2], 'value="' . $needle . '"')) continue;
        if (!preg_match('/confirmSubmit\(this,\s*(.+?),\s*\{/s', $f[1], $m)) continue;
        $raw = $m[1];
        // เบราว์เซอร์ถอด entity ตอน parse attribute ก่อนส่งให้ JS — จำลองขั้นนั้น
        $decoded = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
        return ['raw' => $raw, 'text' => decodeJsStringLiteral($decoded)];
    }
    return null;
}

$login = http('GET', "$BASE_URL/login.php");
$r = http('POST', "$BASE_URL/login.php", [
    'csrf_token' => csrfFrom($login), 'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD,
]);
if (!str_contains($r, 'ออกจากระบบ') && !str_contains($r, 'logout')) {
    fail('CFM-A0', 'ล็อกอินไม่สำเร็จ — ส่งรหัสผ่าน admin เป็น argument');
    $cleanup();
    exit(1);
}

$uniq = substr((string) getmypid(), -4) . mt_rand(100, 999);

// ============================================================
// A. ทุกกล่องต้องบอกว่าทำอะไรกับใคร
// ============================================================
echo "── A. กล่องยืนยันบอกข้อมูลระบุตัว ──\n";

// 📚 หนังสือที่ลบได้ (ไม่มีประวัติการยืม)
// 🔴 [บทเรียน] ต้องสร้างผ่าน BookService ไม่ใช่ INSERT ตรง ๆ
//    เพราะ Repository เป็นตัวเติม `books.search_tokens` (trigram สำหรับค้นภาษาไทย)
//    INSERT ดิบ ๆ จะได้หนังสือที่ **ค้นหาไม่เจอ** แล้วเทสต์จะแดงโดยที่โค้ดไม่ได้ผิด
//    (seeder ก็มีคอมเมนต์เตือนเรื่องนี้ไว้แล้ว — ผมพลาดซ้ำรอยเดิม)
$catId = (int) $pdo->query("SELECT id FROM categories LIMIT 1")->fetchColumn();
$bookService = new \App\Services\BookService($pdo);

$mkBook = function (string $title, string $author) use ($bookService, $catId, &$madeBooks): int {
    $id = (int) $bookService->createBook([
        'title' => $title, 'author' => $author, 'category_id' => $catId,
        'quantity' => 1, 'isbn' => null,
    ]);
    $madeBooks[] = $id;
    return $id;
};

$bookTitle  = "[CFMTEST] คู่มือทดสอบ {$uniq}";
$bookAuthor = "ผู้แต่งทดสอบ {$uniq}";
$bookId = $mkBook($bookTitle, $bookAuthor);

$booksHtml = http('GET', "$BASE_URL/admin/books.php?search=" . urlencode("CFMTEST"));
$bookMsg = confirmMessageFor($booksHtml, (string) $bookId);

check('CFM-A1',
    $bookMsg !== null && $bookMsg['text'] !== null
        && str_contains($bookMsg['text'], $bookTitle) && str_contains($bookMsg['text'], $bookAuthor),
    'ลบหนังสือ → บอกชื่อเล่มและผู้แต่ง: "' . str_replace("\n", ' / ', $bookMsg['text'] ?? '') . '"',
    '🔴 ไม่บอกว่าเล่มไหน: ' . var_export($bookMsg['text'] ?? $bookMsg, true));

// 🏷️ หมวดหมู่ที่ยังไม่มีหนังสือ (ปุ่มลบขึ้นเฉพาะกรณีนี้)
$catName = "[CFMTEST] หมวดทดสอบ {$uniq}";
$pdo->prepare("INSERT INTO categories (name) VALUES (?)")->execute([$catName]);
$newCatId = (int) $pdo->lastInsertId();
$madeCats[] = $newCatId;

$catsHtml = http('GET', "$BASE_URL/admin/categories.php");
$catMsg = confirmMessageFor($catsHtml, (string) $newCatId);

check('CFM-A2',
    $catMsg !== null && $catMsg['text'] !== null && str_contains($catMsg['text'], $catName),
    'ลบหมวดหมู่ → บอกชื่อหมวด: "' . ($catMsg['text'] ?? '') . '"',
    '🔴 ไม่บอกว่าหมวดไหน: ' . var_export($catMsg['text'] ?? $catMsg, true));

// 👤 สมาชิกที่ลบได้ (ไม่มีประวัติยืม/จอง)
$memberName = "[CFMTEST] สมาชิกทดสอบ {$uniq}";
$st = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'member')");
$st->execute([$memberName, "cfm_{$uniq}@test.com", password_hash('x', PASSWORD_DEFAULT)]);
$memberId = (int) $pdo->lastInsertId();
$madeUsers[] = $memberId;
$memberCode = str_pad((string) $memberId, 6, '0', STR_PAD_LEFT);

$membersHtml = http('GET', "$BASE_URL/admin/members.php?search=" . urlencode('CFMTEST'));
$memberMsg = confirmMessageFor($membersHtml, (string) $memberId);

check('CFM-A3',
    $memberMsg !== null && $memberMsg['text'] !== null
        && str_contains($memberMsg['text'], $memberName) && str_contains($memberMsg['text'], $memberCode),
    'ลบสมาชิก → บอกชื่อและรหัสสมาชิก: "' . str_replace("\n", ' / ', $memberMsg['text'] ?? '') . '"',
    '🔴 ไม่บอกว่าคนไหน: ' . var_export($memberMsg['text'] ?? $memberMsg, true));

// 🔖 การจองที่รอมารับ — อนุมัติ + ยกเลิก
$resHtml = http('GET', "$BASE_URL/admin/reservations.php?status=pending");

// 🔴 [บทเรียน] ฉบับแรกเทียบกับ "การจองล่าสุดใน DB" ซึ่ง**ไม่ใช่แถวที่แสดงอยู่บนหน้า**
//    (หน้าเรียงคนละแบบ + มีแบ่งหน้า) เทสต์เลยแดงทั้งที่ข้อความถูกต้องทุกอย่าง
//    → ต้องอ่าน id จากฟอร์มที่ render จริง แล้วไปถาม DB ว่าแถวนั้นคือใครเล่มไหน
$byAction = ['approve' => null, 'cancel' => null];
if (preg_match_all('/<form\b[^>]*onsubmit="([^"]*)"[^>]*>(.*?)<\/form>/s', $resHtml, $forms, PREG_SET_ORDER)) {
    foreach ($forms as $f) {
        if (!preg_match('/confirmSubmit\(this,\s*(.+?),\s*\{/s', $f[1], $m)) continue;
        if (!preg_match('/name="action" value="(approve|cancel)"/', $f[2], $am)) continue;
        if ($byAction[$am[1]] !== null) continue;
        if (!preg_match('/name="id" value="(\d+)"/', $f[2], $im)) continue;
        $byAction[$am[1]] = [
            'id'   => (int) $im[1],
            'text' => decodeJsStringLiteral(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')),
        ];
    }
}

$rowOf = function (?array $hit) use ($pdo): ?array {
    if (!$hit) return null;
    $st = $pdo->prepare("
        SELECT b.title, u.name FROM reservations r
        JOIN books b ON b.id = r.book_id JOIN users u ON u.id = r.user_id
        WHERE r.id = ?
    ");
    $st->execute([$hit['id']]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
};

if (!$byAction['approve'] && !$byAction['cancel']) {
    echo "  ⏭️  ไม่มีการจองสถานะ 'รอมารับ' บนหน้า — ข้าม CFM-A4/A5\n";
} else {
    $ap = $byAction['approve'];
    $apRow = $rowOf($ap);
    check('CFM-A4',
        $apRow !== null && is_string($ap['text'])
            && str_contains($ap['text'], $apRow['title']) && str_contains($ap['text'], $apRow['name']),
        'อนุมัติการจอง → บอกชื่อเล่มและผู้จองตรงกับแถวนั้นจริง: "'
            . str_replace("\n", ' / ', (string) ($ap['text'] ?? '')) . '"',
        '🔴 ข้อความไม่ตรงกับแถวที่กด — แถว #' . ($ap['id'] ?? '?') . ' คือ "'
            . ($apRow['title'] ?? '?') . '" ของ ' . ($apRow['name'] ?? '?')
            . ' แต่กล่องเขียนว่า "' . (string) ($ap['text'] ?? '') . '"');

    $cx = $byAction['cancel'];
    $cxRow = $rowOf($cx);
    check('CFM-A5',
        $cxRow !== null && is_string($cx['text'])
            && str_contains($cx['text'], $cxRow['title']) && str_contains($cx['text'], $cxRow['name'])
            && str_contains($cx['text'], 'สต็อก'),
        'ยกเลิกการจอง → บอกเล่ม/คนตรงแถว และเตือนว่าสต็อกจะคืนกลับ',
        '🔴 ข้อความยังไม่ครบหรือไม่ตรงแถว: "' . (string) ($cx['text'] ?? '') . '"');
}

// ============================================================
// B. 🔴 escape — จุดที่วิธีแก้แบบง่าย ๆ จะพัง
// ============================================================
echo "\n── B. ชื่อที่มีอักขระพิเศษต้องไม่ทำให้ปุ่มพัง ──\n";

// 🧠 ทำไมสำคัญ: ข้อความอยู่ใน JS string ซ้อนใน HTML attribute = escape สองชั้น
//    ถ้าพลาด ปุ่มจะกดไม่ติด **โดยไม่มี error ให้เห็น** เจ้าหน้าที่จะนึกว่าปุ่มเสีย
//    ชื่อหนังสือจริงมีเครื่องหมายคำพูดได้ปกติ เช่น หนังสือที่ชื่อขึ้นต้นด้วยคำพูด
$nasty = [
    "อัญประกาศ" => '[CFMTEST] "เสียงเพรียก" จากขุนเขา ' . $uniq,
    "อะพอสทรอฟี" => "[CFMTEST] O'Brien's Guide {$uniq}",
    "แบ็กสแลช"   => '[CFMTEST] path\\to\\book ' . $uniq,
    "ผสมทุกแบบ"  => '[CFMTEST] "mix" \'all\' \\ types ' . $uniq,
];

$broken = [];
$okList = [];
foreach ($nasty as $label => $title) {
    $id = $mkBook($title, 'ผู้แต่ง');

    $html = http('GET', "$BASE_URL/admin/books.php?search=" . urlencode('CFMTEST'));
    $msg = confirmMessageFor($html, (string) $id);

    if ($msg === null) {
        $broken[] = "{$label}: หาฟอร์มไม่เจอ (attribute น่าจะถูกตัดกลางคัน)";
    } elseif ($msg['text'] === null) {
        $broken[] = "{$label}: JS string พัง → " . substr($msg['raw'], 0, 60);
    } elseif (!str_contains($msg['text'], $title)) {
        $broken[] = "{$label}: ข้อความเพี้ยน → " . $msg['text'];
    } else {
        $okList[] = $label;
    }
}

check('CFM-B1', $broken === [],
    'ชื่อที่มี " \' \\ ผ่านครบ ' . count($okList) . ' แบบ (' . implode(', ', $okList) . ')',
    '🔴 ปุ่มพังเงียบ ๆ: ' . implode(' · ', $broken));

// B2 — attribute ต้องไม่ถูกตัดกลางคัน (เครื่องหมายคำพูดต้องกลายเป็น entity)
$htmlNasty = http('GET', "$BASE_URL/admin/books.php?search=" . urlencode('CFMTEST'));
$rawAttrOk = true;
if (preg_match_all('/onsubmit="([^"]*)"/', $htmlNasty, $am)) {
    foreach ($am[1] as $attr) {
        // ถ้ามี " ดิบหลุดเข้ามาใน attribute แปลว่า attribute ถูกตัดไปแล้ว จะจับไม่ได้ตั้งแต่แรก
        // จึงตรวจอีกทาง: ทุก onsubmit ต้องจบด้วย ); ครบรูป
        if (!str_contains($attr, 'confirmSubmit(this,')) continue;
        if (!preg_match('/\}\)\s*;?\s*$/', $attr)) $rawAttrOk = false;
    }
}
check('CFM-B2', $rawAttrOk,
    'ทุก attribute onsubmit ปิดวงเล็บครบ ไม่ถูกตัดกลางคัน',
    '🔴 มี attribute ที่ถูกตัดกลางคัน — เครื่องหมายคำพูดหลุดออกมาดิบ ๆ');

// B3 — helper jsString() ต้องคืนค่าที่ decode กลับได้ตรงต้นฉบับ
$roundTripFail = [];
foreach (["ปกติ", "มี\"คำพูด\"", "มี'อะพอสทรอฟี", 'มี\\แบ็กสแลช', "มี\nขึ้นบรรทัด", "<script>alert(1)</script>"] as $t) {
    $back = decodeJsStringLiteral(html_entity_decode(jsString($t), ENT_QUOTES, 'UTF-8'));
    if ($back !== $t) $roundTripFail[] = $t;
}
check('CFM-B3', $roundTripFail === [],
    'jsString() แปลงกลับได้ตรงต้นฉบับทุกกรณี รวม <script> และขึ้นบรรทัดใหม่',
    '🔴 แปลงกลับไม่ตรง: ' . implode(' · ', $roundTripFail));

// ============================================================
// C. เปลี่ยนสิทธิ์เป็นเจ้าหน้าที่
// ============================================================
echo "\n── C. เปลี่ยนสิทธิ์เป็นเจ้าหน้าที่ ──\n";

$formHtml = http('GET', "$BASE_URL/admin/member_form.php?id={$memberId}");

// C1 — ฟอร์มต้องมีด่านยืนยัน (เดิมไม่มีเลย กดบันทึกครั้งเดียวจบ)
check('CFM-C1',
    str_contains($formHtml, 'confirmRoleChange') && preg_match('/<form[^>]*onsubmit="[^"]*confirmRoleChange/', $formHtml) === 1,
    'ฟอร์มแก้ไขสมาชิกมีด่านยืนยันการเปลี่ยนสิทธิ์',
    '🔴 ไม่มีด่านยืนยัน — ให้สิทธิ์เข้าหลังบ้านด้วยการกดบันทึกครั้งเดียว');

// C2 — 🔴 ต้องรู้ว่า role เดิมคืออะไร ไม่งั้นแยกไม่ออกว่า "เปลี่ยน" หรือ "ไม่เปลี่ยน"
check('CFM-C2',
    preg_match('/<select[^>]*id="role"[^>]*data-original="(member|staff)"/', $formHtml, $om) === 1,
    'จำ role เดิมไว้ (data-original="' . ($om[1] ?? '') . '") — ยืนยันเฉพาะตอนเปลี่ยนจริง',
    '🔴 ไม่ได้เก็บ role เดิม → ต้องเด้งกล่องทุกครั้งที่กดบันทึก '
        . 'ซึ่งจะทำให้เจ้าหน้าที่กด "ตกลง" อัตโนมัติจนกล่องไร้ความหมาย');

// C3 — ข้อความต้องบอกผลที่ตามมา ไม่ใช่แค่ "ยืนยัน?"
check('CFM-C3',
    str_contains($formHtml, 'ระบบจัดการหลังบ้าน'),
    'ข้อความบอกผลที่ตามมา (เข้าระบบจัดการหลังบ้านได้) ไม่ใช่แค่ "ยืนยัน?"',
    '🔴 ไม่ได้บอกว่าให้สิทธิ์แล้วเกิดอะไรขึ้น');

// C4 — ชื่อสมาชิกต้องอยู่ในกล่อง (ผู้ดูแลต้องรู้ว่ากำลังให้สิทธิ์ใคร)
check('CFM-C4',
    str_contains($formHtml, 'data-member-name="' . e($memberName) . '"'),
    'กล่องยืนยันรู้ชื่อสมาชิกที่กำลังเปลี่ยนสิทธิ์',
    '🔴 ไม่ได้ส่งชื่อสมาชิกเข้ากล่อง — ผู้ดูแลไม่รู้ว่ากำลังให้สิทธิ์ใคร');

// C5 — 🔴 ฟอร์ม "เพิ่มสมาชิกใหม่" ไม่มีดรอปดาวน์ role → ต้องไม่เด้งกล่อง
//      ถ้าเด้ง เจ้าหน้าที่จะเจอกล่องยืนยันสิทธิ์ตอนเพิ่มสมาชิกธรรมดา ซึ่งไม่มีเหตุผล
$newFormHtml = http('GET', "$BASE_URL/admin/member_form.php");
check('CFM-C5',
    !preg_match('/<select[^>]*id="role"/', $newFormHtml),
    'ฟอร์มเพิ่มสมาชิกใหม่ไม่มีดรอปดาวน์สิทธิ์ → ตัวยืนยันคืน true ผ่านไปเลย',
    'ฟอร์มเพิ่มสมาชิกมีดรอปดาวน์สิทธิ์ด้วย — ต้องตรวจว่าไม่เด้งกล่องโดยไม่จำเป็น');

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
