<?php

/**
 * ทดสอบบัตรสมาชิก — F-45
 *
 * ==========================================================================
 * 🔴 ปัญหาเดิม
 * ==========================================================================
 * 1. ชื่อยาวถูกตัดจนระบุตัวไม่ได้
 *    CSS ใช้ white-space: nowrap + text-overflow: ellipsis บรรทัดเดียว
 *    ชื่อ 55 ตัวอักษรเหลือ "เด็กหญิงพิมพ์ณดาภรณ์ช…" — **ไม่มีนามสกุลเลย**
 *    บัตรที่ระบุตัวสมาชิกไม่ได้ = บัตรที่ใช้งานไม่ได้
 * 2. ป้ายบนบัตรเป็นอังกฤษล้วน: MEMBER / ID: / SCAN ME
 * 3. ค่าเริ่มต้นของชื่อหน่วยงานคือ 'LIBRARY CARD'
 *    ลูกค้าที่ติดตั้งใหม่ได้บัตรหัวภาษาอังกฤษ ทั้งที่ settings มี library_name
 *    เป็นภาษาไทยอยู่แล้ว (สองคีย์ทับซ้อนกัน)
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. ชื่อยาวต้องอยู่ครบ — ไม่ถูกตัดกลางจนหานามสกุลไม่เจอ
 * B. ขนาดฟอนต์ปรับตามความยาว และคำนวณที่ฝั่งเซิร์ฟเวอร์ (ไม่พึ่ง JS)
 * C. ป้ายเป็นภาษาไทย
 * D. ชื่อหน่วยงาน fallback 3 ชั้น: org_name → library_name → ค่าเริ่มต้นไทย
 * E. 🔴 บาร์โค้ดและ QR ต้องยังอยู่ครบ — เป็นหัวใจของบัตร ห้ามพังจากการจัดหน้า
 *
 * ⚠️ สิ่งที่เทสต์นี้ตรวจไม่ได้: ตำแหน่งจริงบนหน้าจอ (ชื่อทับบาร์โค้ดไหม)
 *    ต้องวัดด้วยเบราว์เซอร์จริง — ทำแยกตอนพัฒนา ผลบันทึกไว้ใน FINDINGS
 *    เทสต์นี้คุมได้แค่ว่า "โครงสร้างและกติกาที่ทำให้มันไม่ทับ ยังอยู่ครบ"
 *
 * 🧹 คืนค่า settings ที่แก้ระหว่างทดสอบทุกครั้ง
 *
 * 📌 การใช้งาน: php tests/test_member_card.php [รหัสผ่าน admin]
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
$COOKIE = tempnam(sys_get_temp_dir(), 'bbcard');

// 💾 จำค่า settings เดิมไว้คืนตอนจบ
$savedSettings = [];
foreach (['org_name', 'library_name'] as $k) {
    $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $st->execute([$k]);
    $savedSettings[$k] = $st->fetchColumn();   // false = ไม่มีคีย์นี้
}

$createdMember = null;
$cleanupDone = false;
$cleanup = function () use (&$savedSettings, &$createdMember, &$cleanupDone, $pdo, $COOKIE) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    echo "\n── CLEANUP ──\n";
    try {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($savedSettings as $k => $v) {
            $pdo->prepare("DELETE FROM settings WHERE setting_key = ?")->execute([$k]);
            if ($v !== false) {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
            }
        }
        if ($createdMember) {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$createdMember]);
        }
        echo "  คืนค่า settings เดิม " . count($savedSettings) . " คีย์"
            . ($createdMember ? ' · ลบสมาชิกทดสอบ' : '') . "\n";
    } catch (Throwable $e) {
        echo '  ⚠️ ล้างข้อมูลไม่ครบ: ' . $e->getMessage() . "\n";
    }
    @unlink($COOKIE);
};
register_shutdown_function($cleanup);

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  บัตรสมาชิก (F-45)                                        ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

// 👤 สมาชิกชื่อยาว — สร้างเองเพื่อไม่พึ่งว่าข้อมูลตัวอย่างมีคนชื่อยาวอยู่
const LONG_NAME = 'เด็กหญิงพิมพ์ณดาภรณ์ชนกนันท์ ศรีสมบัติวัฒนโรจน์ประเสริฐ';
$st = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'member')");
$st->execute([LONG_NAME, 'cardtest_' . time() . rand(100, 999) . '@test.com', password_hash('x', PASSWORD_DEFAULT)]);
$createdMember = (int) $pdo->lastInsertId();

echo '  👤 สมาชิกทดสอบ #' . $createdMember . ' ชื่อยาว ' . mb_strlen(LONG_NAME) . " ตัวอักษร\n\n";

// ============================================================
// HTTP
// ============================================================
function http(string $method, string $url, array $fields = []): array
{
    global $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $COOKIE,
        CURLOPT_COOKIEFILE     => $COOKIE,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 25,
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
    fail('CARD-A1', 'ล็อกอินไม่สำเร็จ — ข้ามการทดสอบ (ส่งรหัสผ่าน admin เป็น argument)');
    $cleanup();
    exit(1);
}

$card = http('GET', "$BASE_URL/admin/member_card.php?id={$createdMember}");

// ============================================================
// A. ชื่อยาวต้องอยู่ครบ
// ============================================================
echo "── A. ชื่อยาวต้องอยู่ครบ ──\n";

check('CARD-A1',
    $card['code'] === 200 && str_contains($card['body'], LONG_NAME),
    'ชื่อ ' . mb_strlen(LONG_NAME) . ' ตัวอักษรปรากฏครบทั้งชื่อและนามสกุลในหน้าบัตร',
    '🔴 ชื่อถูกตัด — ระบุตัวสมาชิกจากบัตรไม่ได้');

// A2 — 🔴 ต้องไม่มี nowrap ที่ .member-name อีก (ต้นเหตุของการตัดบรรทัดเดียว)
preg_match('/\.member-name\s*\{(.*?)\}/s', $card['body'], $cssM);
$nameCss = $cssM[1] ?? '';
check('CARD-A2',
    $nameCss !== '' && !str_contains($nameCss, 'white-space: nowrap'),
    'CSS ของชื่อไม่บังคับบรรทัดเดียวแล้ว — ขึ้นบรรทัดใหม่ได้',
    '🔴 ยังมี white-space: nowrap — ชื่อยาวจะถูกตัดกลางเหมือนเดิม');

// A3 — ต้องยอมตัดกลางคำได้ (ภาษาไทยไม่มีช่องว่างระหว่างคำ)
check('CARD-A3',
    str_contains($nameCss, 'overflow-wrap: anywhere') || str_contains($nameCss, 'word-break: break-word'),
    'ยอมให้ตัดกลางคำได้ — ชื่อไทยไม่มีช่องว่าง ถ้าไม่ยอมจะล้นกรอบแทนที่จะขึ้นบรรทัดใหม่',
    '🔴 ไม่มีกติกาตัดคำ — ชื่อไทยยาว ๆ จะดันล้นออกนอกบัตร');

// A4 — ยังต้องมีเพดานบรรทัด ไม่งั้นชื่อยาวมากจะไปทับบาร์โค้ด
check('CARD-A4',
    str_contains($nameCss, 'line-clamp'),
    'มีเพดานจำนวนบรรทัด — ชื่อยาวผิดปกติจะไม่ดันทับบาร์โค้ด',
    '🔴 ไม่มีเพดานบรรทัด — ชื่อ 120 ตัวอักษรจะดันเลย์เอาต์พัง');

// ============================================================
// B. ขนาดฟอนต์ตามความยาว
// ============================================================
echo "\n── B. ขนาดฟอนต์ปรับตามความยาวชื่อ ──\n";

// 📏 ความจุที่ **วัดจริง** จากการเรนเดอร์บัตรแล้ววัดด้วย DOM
//    กล่องชื่อกว้าง 180px × เพดาน 3 บรรทัด · ฟอนต์ Sarabun 700
//    ตัวเลข = จำนวนอักขระที่กินความกว้างมากที่สุดที่ยังอยู่ใน 3 บรรทัดได้
//    เลือกค่าที่แคบกว่าระหว่างชื่อไทยกับชื่อละติน (ละตินกว้างกว่าที่ฟอนต์ใหญ่)
//      16px: ไทย 56 / ละติน 45  →  45
//      14px: ไทย 59 / ละติน 60  →  59
//      12px: ไทย 76 / ละติน 79  →  76
//      10.5px: ไทย 82 / ละติน 93 →  82
$measuredCapacity = ['' => 45, 'len-md' => 59, 'len-lg' => 76, 'len-xl' => 82];

// B1 — ชื่อยาว 55 ตัวอักษรกว้างเท่ากับ 42 ตัว จึงต้องได้ 14px ไม่ใช่ 10.5px
//    🔴 เดิมยามข้อนี้บังคับให้ได้ len-xl (10.5px) ซึ่งเป็นการล็อก "พฤติกรรมที่ผิด" เอาไว้
//       ยามที่ยืนยันบั๊กให้อยู่ต่อ อันตรายกว่าไม่มียาม
$dispLen = displayNameLength(LONG_NAME);
preg_match('/class="member-name\s*([a-z-]*)"/', $card['body'], $m);
$gotClass = trim($m[1] ?? '(ไม่พบ)');
check('CARD-B1', $gotClass === 'len-md',
    'ชื่อ ' . mb_strlen(LONG_NAME) . ' ตัวอักษร (กว้างเท่ากับ ' . $dispLen . ' ตัว) ได้ 14px — ไม่ถูกย่อเกินจำเป็น',
    "🔴 ได้คลาส \"{$gotClass}\" (คาดว่า len-md) — ชื่อกว้างแค่ {$dispLen} ตัว งบของ 14px รับได้ {$measuredCapacity['len-md']}");

// B2 — 🔴 ต้องคำนวณที่ฝั่งเซิร์ฟเวอร์ ไม่ใช่ JS (หน้านี้ต้องพิมพ์ได้แม้ JS ไม่ทำงาน)
$src = (string) file_get_contents(__DIR__ . '/../admin/member_card.php');
check('CARD-B2',
    str_contains($src, 'displayNameLength($member[\'name\'])'),
    'เลือกขนาดฟอนต์ที่ฝั่งเซิร์ฟเวอร์ด้วย displayNameLength — ไม่พึ่ง JS และนับความกว้างชื่อไทยถูก',
    '🔴 ไม่ได้คำนวณที่เซิร์ฟเวอร์ หรือกลับไปใช้ mb_strlen (จะนับสระ/วรรณยุกต์เป็นความกว้างด้วย)');

// B3 — คลาสต้องครบทุกระดับ
$missing = [];
foreach (['len-md', 'len-lg', 'len-xl'] as $cls) {
    if (!preg_match('/\.member-name\.' . preg_quote($cls, '/') . '\s*\{/', $card['body'])) $missing[] = $cls;
}
check('CARD-B3', $missing === [],
    'มี CSS ครบทุกระดับความยาว (len-md / len-lg / len-xl)',
    '🔴 ขาด CSS: ' . implode(', ', $missing) . ' — ชื่อบางความยาวจะไม่ถูกย่อ');

// B4 — 🛡️ ยามกันคนขยับเกณฑ์เกินความจุที่วัดไว้
//    เกณฑ์ในโค้ดบอกว่า "ยาวถึงเท่านี้ยังใช้ขนาดนี้ได้" ถ้าตั้งสูงเกินความจุจริง ชื่อจะถูกตัดหาย
if (!preg_match_all("/\\\$nameLen > (\d+) => '(len-[a-z]+)'/", $src, $mm, PREG_SET_ORDER)) {
    fail('CARD-B4', '🔴 อ่านเกณฑ์ขนาดฟอนต์จาก member_card.php ไม่ออก — เปลี่ยนโครงสร้างไปแล้ว?');
} else {
    // เรียงจากมากไปน้อยตามที่เขียนใน match() → ขอบบนของชั้นที่เล็กกว่าคือเกณฑ์ของชั้นถัดขึ้นไป
    $tiers = [];                       // คลาส => ขอบบนของความยาวที่ชั้นนั้นต้องรองรับ
    $bounds = array_map(fn($x) => (int) $x[1], $mm);
    $classes = array_map(fn($x) => $x[2], $mm);
    for ($k = 0; $k < count($mm); $k++) {
        // ชั้นที่ตรงกับเงื่อนไข "> N" ตัวถัดไป จะรับความยาวได้ถึง N
        $tiers[$classes[$k]] = $k === 0 ? PHP_INT_MAX : $bounds[$k - 1];
    }
    $tiers[''] = min($bounds);         // ชั้น 16px รับได้ถึงเกณฑ์ที่ต่ำที่สุด
    $tooBold = [];
    foreach ($tiers as $cls => $upper) {
        if ($upper === PHP_INT_MAX) continue;   // ชั้นเล็กสุดไม่มีขอบบน — มีเพดาน 3 บรรทัดรับไม้สุดท้าย
        $cap = $measuredCapacity[$cls] ?? 0;
        if ($upper > $cap) $tooBold[] = ($cls ?: '16px') . ": ตั้งไว้ {$upper} แต่วัดได้ {$cap}";
    }
    check('CARD-B4', $tooBold === [],
        'เกณฑ์ทุกชั้นอยู่ในความจุที่วัดจริง — ' . implode(' · ', array_map(
            fn($c, $u) => ($c ?: '16px') . '≤' . ($u === PHP_INT_MAX ? '∞' : $u),
            array_keys($tiers), array_values($tiers))),
        '🔴 ตั้งเกณฑ์เกินความจุที่วัดไว้ ชื่อจะถูกตัดหาย: ' . implode(' · ', $tooBold));
}

// B5 — displayNameLength() ต้องนับความกว้างถูก ไม่ใช่แค่ตัดอักขระมั่ว ๆ
//    🔴 ำ (U+0E33) กินความกว้างจริง ห้ามตัดทิ้ง — เคยพลาดตอนเขียนครั้งแรก
$countCases = [
    ['สมชาย', 5, 'ไม่มีอักขระซ้อน นับเท่า mb_strlen'],
    ['เด็ก', 3, 'ไม้ไต่คู้ (็) ซ้อนอยู่ ไม่นับ'],
    ['พิมพ์', 3, 'สระอิ + ทัณฑฆาต ซ้อนอยู่ ไม่นับ'],
    ['กำ', 2, '⚠️ ำ กินความกว้างจริง ต้องนับ'],
    ['กะ', 2, 'ะ เป็นสระหลังเต็มตัว ต้องนับ'],
    ['กา', 2, 'า เป็นสระหลังเต็มตัว ต้องนับ'],
    ['Somchai', 7, 'อักษรละตินนับตามปกติ'],
];
$wrong = [];
foreach ($countCases as [$txt, $want, $why]) {
    $got = displayNameLength($txt);
    if ($got !== $want) $wrong[] = "\"{$txt}\" ได้ {$got} คาด {$want} ({$why})";
}
check('CARD-B5', $wrong === [],
    'นับความกว้างถูกทุกเคส ' . count($countCases) . ' แบบ — รวมกรณี ำ/ะ/า ที่กินความกว้างจริง',
    '🔴 นับผิด: ' . implode(' · ', $wrong));

// B6 — ชื่อไทยปกติต้องได้ 16px เต็ม ไม่ถูกย่อโดยไม่จำเป็น
//    🧠 นี่คือเคสที่กระทบคนหมู่มาก — เดิมสมาชิก 30 จาก 206 คนโดนย่อทั้งที่ไม่ต้อง
$normal = 'เด็กหญิงปาริฉัตร แก้วประเสริฐ';   // 29 ตัวอักษร กว้างเท่ากับ 23 ตัว
$st2 = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'member')");
$st2->execute([$normal, 'cardnormal_' . time() . rand(100, 999) . '@test.com', password_hash('x', PASSWORD_DEFAULT)]);
$normalId = (int) $pdo->lastInsertId();
$normalCard = http('GET', "{$BASE_URL}/admin/member_card.php?id={$normalId}");
preg_match('/class="member-name\s*([a-z-]*)"/', $normalCard['body'], $m2);
$normalClass = trim($m2[1] ?? '(ไม่พบ)');
$pdo->exec("DELETE FROM users WHERE id = {$normalId}");
check('CARD-B6', $normalClass === '',
    "ชื่อไทยความยาวปกติ ({$normal}) ได้ฟอนต์ 16px เต็ม ไม่ถูกย่อ",
    "🔴 ได้คลาส \"{$normalClass}\" — ถูกย่อทั้งที่กว้างแค่ " . displayNameLength($normal) . ' ตัว');

// ============================================================
// C. ป้ายภาษาไทย
// ============================================================
echo "\n── C. ป้ายบนบัตร ──\n";

$english = [];
foreach (['>MEMBER<' => 'MEMBER', '>SCAN ME<' => 'SCAN ME'] as $needle => $label) {
    if (str_contains($card['body'], $needle)) $english[] = $label;
}
if (preg_match('/class="member-id">\s*ID:/', $card['body'])) $english[] = 'ID:';

check('CARD-C1', $english === [],
    'ไม่มีป้ายภาษาอังกฤษหลงเหลือบนบัตร',
    '🔴 ยังเป็นอังกฤษ: ' . implode(', ', $english));

$thai = [];
foreach (['สมาชิก', 'รหัส', 'สแกน'] as $t) {
    if (!str_contains($card['body'], $t)) $thai[] = $t;
}
check('CARD-C2', $thai === [],
    'ป้ายเป็นภาษาไทยครบ (สมาชิก · รหัส · สแกนที่นี่)',
    '🔴 ขาดป้ายไทย: ' . implode(', ', $thai));

// ============================================================
// D. ชื่อหน่วยงาน fallback 3 ชั้น
// ============================================================
echo "\n── D. ชื่อหน่วยงานบนหัวบัตร ──\n";

$setSetting = function (string $key, ?string $value) use ($pdo) {
    $pdo->prepare("DELETE FROM settings WHERE setting_key = ?")->execute([$key]);
    if ($value !== null) {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $value]);
    }
};

$orgOf = function (string $html): string {
    return preg_match('/class="org-name">([^<]*)</', $html, $m) ? trim($m[1]) : '';
};

// D1 — ชั้นที่ 1: org_name ชนะเสมอ (ระบบที่ตั้งไว้แล้วต้องไม่ถูกทับ)
$setSetting('org_name', 'ห้องสมุดทดสอบ ก');
$setSetting('library_name', 'ห้องสมุดทดสอบ ข');
$got = $orgOf(http('GET', "$BASE_URL/admin/member_card.php?id={$createdMember}")['body']);
check('CARD-D1', $got === 'ห้องสมุดทดสอบ ก',
    "ตั้ง org_name ไว้ → ใช้ค่านั้น ({$got}) ระบบเดิมไม่ถูกทับ",
    "🔴 ได้ \"{$got}\" ควรเป็น \"ห้องสมุดทดสอบ ก\"");

// D2 — ชั้นที่ 2: ไม่มี org_name → ใช้ library_name
$setSetting('org_name', null);
$got = $orgOf(http('GET', "$BASE_URL/admin/member_card.php?id={$createdMember}")['body']);
check('CARD-D2', $got === 'ห้องสมุดทดสอบ ข',
    "ไม่มี org_name → ใช้ library_name ที่มีอยู่แล้ว ({$got})",
    "🔴 ได้ \"{$got}\" — ไม่ได้ใช้ library_name ที่ระบบมีอยู่");

// D3 — 🔴 ชั้นที่ 3: ไม่มีทั้งคู่ → ต้องเป็นภาษาไทย ไม่ใช่ LIBRARY CARD
$setSetting('library_name', null);
$got = $orgOf(http('GET', "$BASE_URL/admin/member_card.php?id={$createdMember}")['body']);
check('CARD-D3',
    $got !== '' && !preg_match('/^[\x20-\x7E]+$/', $got),
    "ไม่มีทั้ง 2 คีย์ → ได้ค่าเริ่มต้นภาษาไทย ({$got})",
    "🔴 ได้ \"{$got}\" — ลูกค้าที่ติดตั้งใหม่จะได้บัตรหัวภาษาอังกฤษ");

check('CARD-D4',
    !str_contains($card['body'], 'LIBRARY CARD'),
    'ไม่มีคำว่า LIBRARY CARD หลงเหลือในโค้ด',
    '🔴 ยังมี LIBRARY CARD เป็นค่าเริ่มต้น');

// 🧹 คืนค่าก่อนทดสอบส่วนถัดไป
foreach ($savedSettings as $k => $v) {
    $setSetting($k, $v === false ? null : (string) $v);
}

// ============================================================
// E. บาร์โค้ดและ QR — หัวใจของบัตร
// ============================================================
echo "\n── E. บาร์โค้ดและ QR ──\n";

$card2 = http('GET', "$BASE_URL/admin/member_card.php?id={$createdMember}");

check('CARD-E1',
    preg_match('/jsbarcode-value="' . $createdMember . '"/', $card2['body']) === 1,
    "บาร์โค้ดยังฝังรหัสสมาชิก #{$createdMember} ไว้ถูกต้อง",
    '🔴 บาร์โค้ดหายหรือค่าผิด — สแกนที่เคาน์เตอร์ไม่ได้');

check('CARD-E2',
    str_contains($card2['body'], 'id="qrcode"'),
    'ช่องวาด QR ยังอยู่',
    '🔴 ช่อง QR หายไป');

// E3 — 🔴 ต้องใช้ไลบรารีในเครื่อง ไม่ใช่ CDN (ระบบต้องใช้งานออฟไลน์ได้)
//     🧠 เทียบที่ "โฮสต์" ไม่ใช่ "เป็น URL เต็มหรือไม่" —
//        ระบบสร้างลิงก์จาก APP_URL จึงเป็น URL เต็มของตัวเองอยู่แล้ว
//        (เวอร์ชันแรกเช็คว่าขึ้นต้นด้วย // แล้วแดง ทั้งที่ไฟล์อยู่ในเครื่อง)
$ownHost = parse_url($BASE_URL, PHP_URL_HOST) ?: 'localhost';
$externalLibs = [];
$localLibs = [];
if (preg_match_all('/<script[^>]+src="([^"]+)"/', $card2['body'], $m)) {
    foreach ($m[1] as $srcUrl) {
        $host = parse_url($srcUrl, PHP_URL_HOST);
        if ($host === null || $host === $ownHost) {
            $localLibs[] = basename(parse_url($srcUrl, PHP_URL_PATH) ?? $srcUrl);
        } else {
            $externalLibs[] = $srcUrl;
        }
    }
}
check('CARD-E3', $externalLibs === [] && $localLibs !== [],
    'ไลบรารีโหลดจากในเครื่องทั้งหมด (' . implode(', ', $localLibs) . ') — พิมพ์บัตรได้แม้เน็ตล่ม',
    $externalLibs !== []
        ? '🔴 เรียกไฟล์จากภายนอก: ' . implode(', ', $externalLibs)
        : '🔴 ไม่พบไลบรารีเลย — บาร์โค้ด/QR จะไม่ถูกวาด');

// E4 — ไฟล์ไลบรารีต้องมีอยู่จริงในโปรเจกต์ ไม่ใช่แค่ลิงก์ชี้ไปเฉย ๆ
$missingFiles = [];
foreach (['assets/vendor/qrcode/qrcode.min.js', 'assets/vendor/jsbarcode/JsBarcode.all.min.js'] as $rel) {
    $abs = __DIR__ . '/../' . $rel;
    if (!is_file($abs) || filesize($abs) < 1000) $missingFiles[] = $rel;
}
check('CARD-E3b', $missingFiles === [],
    'ไฟล์ไลบรารีอยู่ในโปรเจกต์จริงและถูกส่งไปกับ git',
    '🔴 ไฟล์หายหรือขนาดผิดปกติ: ' . implode(', ', $missingFiles)
        . ' — เคยเจอ .gitignore บล็อก assets/vendor/ มาแล้ว');

// E4 — ขนาดบัตรต้องยังเป็นขนาดมาตรฐาน (ไม่ถูกขยายเพื่อให้ชื่อพอดี)
check('CARD-E4',
    preg_match('/\.card\s*\{[^}]*width:\s*85\.6mm/s', $card2['body']) === 1
        && preg_match('/\.card\s*\{[^}]*height:\s*53\.98mm/s', $card2['body']) === 1,
    'บัตรยังเป็นขนาดมาตรฐาน 85.6 × 53.98 มม. — ไม่ได้ขยายบัตรเพื่อให้ชื่อพอดี',
    '🔴 ขนาดบัตรถูกเปลี่ยน — พิมพ์แล้วไม่พอดีซองบัตร');

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
