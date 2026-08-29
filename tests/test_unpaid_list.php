<?php

/**
 * ทดสอบ "หน้ารายการค้างชำระ" — F-35 (ตัวเลขซ่อนคนค้างหนี้) + F-39 (ลำดับไม่คงที่)
 *
 * ==========================================================================
 * 🔴 บั๊กเดิมที่ต้องกันไม่ให้กลับมา
 * ==========================================================================
 * F-35 — หน้าดึงมาแค่ 50 แถวตายตัว แล้วเอา count() ของ 50 แถวนั้นมาขึ้นป้ายว่ามีกี่คน
 *        ผลคือป้ายบอก "46 คน" ทั้งที่จริง 169 คน · ไม่มีแบ่งหน้า ไม่มีช่องค้นหา
 *        คนที่ค้างมากที่สุดจึงไม่โผล่ในหน้าเลย ทั้งที่เป็นหน้าเดียวที่ใช้ตามหนี้
 *        ยอดเงินถูกเพราะมาจากอีก query ที่ไม่มี LIMIT → ป้ายกับยอดเงินขัดกันเองบนจอเดียว
 *
 * F-39 — ORDER BY ไม่มีตัวตัดสินลำดับ แถวที่ค่าเท่ากันสลับที่ทุกครั้งที่โหลดหน้า
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. ตัวเลขบนป้ายต้องมาจาก query ที่ไม่มี LIMIT และตรงกับ SQL ตรง ๆ
 * B. แบ่งหน้าเป็น "คน" — หนี้ของคนเดียวกันต้องไม่ถูกหั่นข้ามหน้า
 * C. ค้นหาได้ และตัวนับต้องเปลี่ยนตามคำค้นด้วย
 * D. ลำดับคงที่ทุกครั้งที่โหลด (F-39) — ทั้งหน้านี้และ query อื่นในระบบ
 * E. หน้าเว็บจริงผ่าน HTTP — 2 ตารางแบ่งหน้าแยกกันจริง
 *
 * 🧹 ไม่สร้างข้อมูลใหม่เลย — อ่านจากของที่มีอยู่ล้วน ๆ จึงไม่มีอะไรให้ล้าง
 *
 * 📌 การใช้งาน: php tests/test_unpaid_list.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Repositories/BorrowRepository.php';

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

$pdo        = getDB();
$borrowRepo = new App\Repositories\BorrowRepository($pdo);
$COOKIE     = tempnam(sys_get_temp_dir(), 'bbunpaid');
register_shutdown_function(fn() => @unlink($COOKIE));

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  หน้ารายการค้างชำระ (F-35 + F-39)                        ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

// 📊 ความจริงจากฐานข้อมูล — นิยาม "ค้างชำระ" ชุดเดียวกับที่อีก 5 แหล่งใช้
$truth = $pdo->query("
    SELECT COUNT(*) AS rows_count,
           COUNT(DISTINCT b.user_id) AS people,
           COALESCE(SUM(b.fine_amount), 0) AS total
    FROM borrows b
    LEFT JOIN payments p ON p.borrow_id = b.id
    WHERE b.fine_amount > 0 AND p.id IS NULL AND b.fine_waived_at IS NULL
")->fetch();

echo "  📊 ความจริงใน DB: {$truth['people']} คน · {$truth['rows_count']} รายการ · "
    . number_format((float) $truth['total'], 2) . " บาท\n\n";

// ============================================================
// A. ตัวเลขบนป้าย
// ============================================================
echo "── A. ตัวเลขสรุปต้องเป็นความจริง ──\n";

$stats = $borrowRepo->countUnpaidDebtors('');

check('UNPAID-A1', $stats['people'] === (int) $truth['people'],
    "จำนวนคน = {$stats['people']} ตรงกับ SQL",
    "🔴 จำนวนคนผิด: ได้ {$stats['people']} ควรเป็น {$truth['people']} — นี่คืออาการของ F-35");

check('UNPAID-A2', $stats['rows'] === (int) $truth['rows_count'],
    "จำนวนรายการ = {$stats['rows']} ตรงกับ SQL",
    "🔴 จำนวนรายการผิด: ได้ {$stats['rows']} ควรเป็น {$truth['rows_count']}");

check('UNPAID-A3', abs($stats['total'] - (float) $truth['total']) < 0.01,
    'ยอดเงินรวม = ' . number_format($stats['total'], 2) . ' ตรงกับ SQL',
    "🔴 ยอดเงินผิด: ได้ {$stats['total']} ควรเป็น {$truth['total']}");

// A4 — 🔴 ตัวนับต้องไม่ขึ้นกับจำนวนที่ดึงมาแสดง
//     ถ้าดึงมา 5 คน ตัวนับก็ต้องยังบอกจำนวนคนทั้งหมดอยู่ดี
$fewDebtors = $borrowRepo->getUnpaidDebtors(5, 0, '');
check('UNPAID-A4',
    count($fewDebtors) <= 5 && $stats['people'] === (int) $truth['people'],
    'ดึงมาแสดงแค่ ' . count($fewDebtors) . ' คน แต่ตัวนับยังบอก ' . $stats['people'] . ' คนตามจริง',
    '🔴 ตัวนับเปลี่ยนตามจำนวนที่ดึงมา — นี่คือบั๊ก F-35 เป๊ะ ๆ');

// ============================================================
// B. แบ่งหน้าเป็น "คน"
// ============================================================
echo "\n── B. แบ่งหน้าเป็นคน ไม่ใช่รายการ ──\n";

$per   = 20;
$page1 = $borrowRepo->getUnpaidDebtors($per, 0, '');
$page2 = $borrowRepo->getUnpaidDebtors($per, $per, '');

$ids1 = array_column($page1, 'user_id');
$ids2 = array_column($page2, 'user_id');

check('UNPAID-B1', $ids1 && $ids2 && !array_intersect($ids1, $ids2),
    'หน้า 1 กับหน้า 2 ไม่มีคนซ้ำกัน (' . count($ids1) . ' / ' . count($ids2) . ' คน)',
    '🔴 มีคนโผล่ทั้ง 2 หน้า: ' . implode(',', array_intersect($ids1, $ids2)));

// B2 — 🔴 หนี้ของคนหนึ่งต้องอยู่ครบในหน้าเดียว ไม่ถูกหั่น
$items = $borrowRepo->getUnpaidItemsByUsers($ids1);
$countedPerUser = [];
foreach ($items as $it) {
    $countedPerUser[(int) $it['user_id']] = ($countedPerUser[(int) $it['user_id']] ?? 0) + 1;
}
$mismatch = [];
foreach ($page1 as $d) {
    $uid = (int) $d['user_id'];
    if (($countedPerUser[$uid] ?? 0) !== (int) $d['item_count']) {
        $mismatch[] = $d['user_name'] . ' (ควรมี ' . $d['item_count'] . ' ได้ ' . ($countedPerUser[$uid] ?? 0) . ')';
    }
}
check('UNPAID-B2', $mismatch === [],
    'ทุกคนในหน้าได้ใบค้างชำระครบตามจำนวนที่ป้ายบอก — ไม่ถูกหั่นข้ามหน้า',
    '🔴 หนี้ถูกหั่น: ' . implode(' · ', array_slice($mismatch, 0, 3)));

// B3 — ไล่ทุกหน้าแล้วต้องได้คนครบตามจำนวนจริง ไม่ขาดไม่เกิน
$allIds = [];
for ($off = 0; $off < $stats['people']; $off += $per) {
    foreach ($borrowRepo->getUnpaidDebtors($per, $off, '') as $d) {
        $allIds[] = (int) $d['user_id'];
    }
}
check('UNPAID-B3',
    count($allIds) === $stats['people'] && count(array_unique($allIds)) === $stats['people'],
    'ไล่ทุกหน้าได้ครบ ' . count($allIds) . ' คน ไม่ซ้ำไม่ขาด',
    '🔴 ไล่ทุกหน้าได้ ' . count($allIds) . ' คน (ไม่ซ้ำ ' . count(array_unique($allIds)) . ') ควรเป็น ' . $stats['people']);

// B4 — คนที่ค้างมากที่สุดต้องอยู่หน้าแรก (เดิมหายไปเลย)
$topBySql = $pdo->query("
    SELECT b.user_id, SUM(b.fine_amount) AS s
    FROM borrows b LEFT JOIN payments p ON p.borrow_id = b.id
    WHERE b.fine_amount > 0 AND p.id IS NULL AND b.fine_waived_at IS NULL
    GROUP BY b.user_id ORDER BY s DESC, b.user_id ASC LIMIT 1
")->fetch();
check('UNPAID-B4',
    $page1 && (int) $page1[0]['user_id'] === (int) $topBySql['user_id'],
    'คนที่ค้างมากที่สุด (' . ($page1[0]['user_name'] ?? '?') . ' ' . number_format((float) ($page1[0]['total_fine'] ?? 0), 2) . ' ฿) อยู่แถวแรก',
    '🔴 คนที่ค้างมากที่สุดไม่อยู่หน้าแรก — เป็นกลุ่มที่บั๊กเดิมซ่อนไว้พอดี');

// ============================================================
// C. ค้นหา
// ============================================================
echo "\n── C. ค้นหา ──\n";

$targetName = (string) ($page1[0]['user_name'] ?? '');
if ($targetName === '') {
    fail('UNPAID-C1', 'ไม่มีข้อมูลค้างชำระให้ทดสอบการค้นหา');
} else {
    $found = $borrowRepo->getUnpaidDebtors(50, 0, $targetName);
    $names = array_column($found, 'user_name');
    check('UNPAID-C1',
        in_array($targetName, $names, true),
        "ค้นชื่อ \"{$targetName}\" แล้วเจอ (" . count($found) . ' ผลลัพธ์)',
        "🔴 ค้นชื่อ \"{$targetName}\" ไม่เจอ");

    // C2 — 🔴 ตัวนับต้องเปลี่ยนตามคำค้นด้วย ไม่ใช่ค้างที่ยอดรวมทั้งระบบ
    $searchStats = $borrowRepo->countUnpaidDebtors($targetName);
    check('UNPAID-C2',
        $searchStats['people'] === count($found) && $searchStats['people'] < $stats['people'],
        "ค้นแล้วป้ายบอก {$searchStats['people']} คน ตรงกับจำนวนแถวที่แสดง (จากทั้งหมด {$stats['people']})",
        "🔴 ป้ายไม่เปลี่ยนตามคำค้น: ป้าย {$searchStats['people']} · แถว " . count($found));

    // C3 — ตัวนับกับตัวดึงต้องใช้เงื่อนไขค้นหาชุดเดียวกัน
    $allSearched = $borrowRepo->getUnpaidDebtors(1000, 0, $targetName);
    check('UNPAID-C3',
        count($allSearched) === $searchStats['people'],
        'ตัวนับกับตัวดึงใช้เงื่อนไขค้นหาชุดเดียวกัน (' . count($allSearched) . ' = ' . $searchStats['people'] . ')',
        '🔴 ตัวนับ ' . $searchStats['people'] . ' แต่ดึงได้ ' . count($allSearched) . ' — เงื่อนไขค้นหาไม่ตรงกัน');

    // C4 — ค้นด้วยคำที่ไม่มีต้องได้ 0 ไม่ใช่ทั้งหมด
    $none = $borrowRepo->countUnpaidDebtors('zzz_ไม่มีคำนี้แน่นอน_zzz');
    check('UNPAID-C4', $none['people'] === 0 && abs($none['total']) < 0.01,
        'ค้นคำที่ไม่มี → 0 คน · 0 บาท',
        '🔴 ค้นคำที่ไม่มีแล้วได้ ' . $none['people'] . ' คน — เงื่อนไขค้นหาไม่ถูกใช้');
}

// ============================================================
// D. ลำดับคงที่ (F-39)
// ============================================================
echo "\n── D. ลำดับต้องคงที่ทุกครั้ง (F-39) ──\n";

$sig = null; $stable = true;
for ($i = 0; $i < 5; $i++) {
    $cur = implode(',', array_column($borrowRepo->getUnpaidDebtors(30, 0, ''), 'user_id'));
    if ($sig === null) $sig = $cur;
    elseif ($sig !== $cur) $stable = false;
}
check('UNPAID-D1', $stable,
    'ดึงรายชื่อคนค้าง 5 ครั้งติด ได้ลำดับเดียวกันทุกครั้ง',
    '🔴 ลำดับสลับที่ระหว่างการโหลด — ORDER BY ขาดตัวตัดสินลำดับ');

// D2 — ใบค้างชำระของแต่ละคนก็ต้องเรียงคงที่
$sig2 = null; $stable2 = true;
for ($i = 0; $i < 5; $i++) {
    $cur = implode(',', array_column($borrowRepo->getUnpaidItemsByUsers(array_slice($ids1, 0, 10)), 'id'));
    if ($sig2 === null) $sig2 = $cur;
    elseif ($sig2 !== $cur) $stable2 = false;
}
check('UNPAID-D2', $stable2,
    'ใบค้างชำระในกล่องรายละเอียดเรียงคงที่ 5 ครั้งติด',
    '🔴 ใบค้างชำระสลับที่ — COALESCE(return_date, lost_reported_at) ต้องมี id ต่อท้าย');

// D3 — 🔴 ไล่ทั้งชั้น Repository ว่าไม่มี ORDER BY ที่ขาดตัวตัดสินลำดับหลงเหลือ
//      บั๊กชนิดนี้ไม่มีวันเห็นจากหน้าจอจนกว่าจะมีข้อมูลค่าซ้ำเยอะ ๆ
$offenders = [];
foreach (glob(__DIR__ . '/../app/Repositories/*.php') as $file) {
    foreach (explode("\n", (string) file_get_contents($file)) as $lineNo => $line) {
        $t = trim($line);
        if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*')) continue;
        if (!preg_match('/ORDER BY\s+(.+?)(?:"|\'|;|$)/i', $t, $m)) continue;
        $clause = trim($m[1], " \"';");
        if ($clause === '') continue;
        // ✅ ถือว่าปลอดภัยถ้ามีคอลัมน์ id อยู่ในลำดับ หรือเรียงด้วยค่าที่ unique อยู่แล้ว
        if (preg_match('/\bid\b/i', $clause)) continue;
        if (preg_match('/^(setting_key|month)\b/i', $clause)) continue;
        $offenders[] = basename($file) . ':' . ($lineNo + 1) . ' → ' . mb_substr($clause, 0, 45);
    }
}
check('UNPAID-D3', $offenders === [],
    'ทุก ORDER BY ในชั้น Repository มีตัวตัดสินลำดับครบ',
    "🔴 ยังมี ORDER BY ที่ขาดตัวตัดสินลำดับ:\n       " . implode("\n       ", array_slice($offenders, 0, 6)));

// ============================================================
// E. หน้าเว็บจริง
// ============================================================
echo "\n── E. หน้าเว็บจริง (HTTP) ──\n";

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

/** ดึงเฉพาะบล็อก "รายการค้างชำระ" ออกมา — ไม่ให้ปนกับตารางล่าง */
function unpaidBlock(string $html): string
{
    return preg_match('/รายการค้างชำระ.*?<\/table>/s', $html, $m) ? $m[0] : '';
}

/** ลายเซ็นของตารางล่าง ใช้ดูว่าขยับหรือไม่ */
function paymentsBlockSig(string $html): string
{
    return preg_match('/ประวัติการรับชำระ.*?<\/table>/s', $html, $m) ? md5($m[0]) : 'ไม่เจอ';
}

$login = http('GET', "$BASE_URL/login.php");
$r = http('POST', "$BASE_URL/login.php", [
    'csrf_token' => csrfFrom($login['body']),
    'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD,
]);

if (!str_contains($r['body'], 'ออกจากระบบ') && !str_contains($r['body'], 'logout')) {
    fail('UNPAID-E1', 'ล็อกอินไม่สำเร็จ — ข้ามการทดสอบผ่านหน้าเว็บ (ส่งรหัสผ่าน admin เป็น argument)');
} else {
    $p1 = http('GET', "$BASE_URL/admin/payments.php");

    // E1 — ป้ายบนหน้าเว็บต้องบอกความจริง
    preg_match_all('/rounded-full">\s*([^<]+?)\s*<\/span>/u', unpaidBlock($p1['body']), $badges);
    $badgeText = implode(' · ', array_map('trim', $badges[1] ?? []));
    check('UNPAID-E1',
        str_contains($badgeText, number_format((int) $truth['people']) . ' คน')
            && str_contains($badgeText, number_format((int) $truth['rows_count']) . ' รายการ'),
        "ป้ายบนหน้าเว็บ: {$badgeText}",
        "🔴 ป้ายไม่ตรงความจริง ({$truth['people']} คน / {$truth['rows_count']} รายการ) — ได้: {$badgeText}");

    // E2 — มีแถบแบ่งหน้าของส่วนค้างชำระ (?upage=)
    check('UNPAID-E2',
        str_contains($p1['body'], 'upage='),
        'ส่วนค้างชำระมีแถบแบ่งหน้าเป็นของตัวเอง (?upage=)',
        '🔴 ไม่มีแถบแบ่งหน้าในส่วนค้างชำระ');

    // E3 — 🔴 กดหน้าของตารางบน ตารางล่างต้องไม่ขยับ (และกลับกัน)
    $p2 = http('GET', "$BASE_URL/admin/payments.php?upage=2");
    $p3 = http('GET', "$BASE_URL/admin/payments.php?page=2");
    $sigBase = paymentsBlockSig($p1['body']);
    check('UNPAID-E3',
        $sigBase === paymentsBlockSig($p2['body']) && $sigBase !== paymentsBlockSig($p3['body']),
        'กด upage=2 ตารางล่างไม่ขยับ · กด page=2 ตารางล่างขยับเอง — 2 ตารางแยกกันจริง',
        '🔴 สองตารางแบ่งหน้าผูกกันอยู่ กดอันหนึ่งอีกอันเลื่อนตาม');

    check('UNPAID-E4',
        unpaidBlock($p1['body']) !== unpaidBlock($p2['body']),
        'กด upage=2 แล้วรายชื่อคนค้างเปลี่ยนจริง',
        '🔴 กดหน้า 2 แล้วได้คนกลุ่มเดิม');

    // E5 — ยอดสรุปต้องไม่เปลี่ยนตามหน้า
    preg_match_all('/rounded-full">\s*([^<]+?)\s*<\/span>/u', unpaidBlock($p2['body']), $badges2);
    check('UNPAID-E5',
        implode('·', array_map('trim', $badges2[1] ?? [])) === implode('·', array_map('trim', $badges[1] ?? [])),
        'ยอดสรุปเหมือนเดิมทุกหน้า — ไม่ได้คำนวณจากแถวที่แสดง',
        '🔴 ยอดสรุปเปลี่ยนตามหน้า — กลับไปเป็นบั๊ก F-35 อีกแล้ว');

    // E6 — ค้นหาจากหน้าเว็บได้
    if ($targetName !== '') {
        $ps = http('GET', "$BASE_URL/admin/payments.php?search=" . rawurlencode($targetName));
        check('UNPAID-E6',
            str_contains(unpaidBlock($ps['body']), $targetName),
            "ค้นหาในส่วนค้างชำระผ่านหน้าเว็บได้ — เจอ \"{$targetName}\"",
            '🔴 ค้นหาแล้วส่วนค้างชำระไม่ขยับ (ช่องค้นหาผูกกับตารางล่างอย่างเดียว)');
    }

    // E7 — ลำดับบนหน้าเว็บคงที่
    $htmlSig = null; $htmlStable = true;
    for ($i = 0; $i < 4; $i++) {
        $cur = md5(unpaidBlock(http('GET', "$BASE_URL/admin/payments.php")['body']));
        if ($htmlSig === null) $htmlSig = $cur;
        elseif ($htmlSig !== $cur) $htmlStable = false;
    }
    check('UNPAID-E7', $htmlStable,
        'เปิดหน้าเดิมซ้ำ 4 ครั้ง ตารางค้างชำระเหมือนเดิมเป๊ะ',
        '🔴 แถวสลับที่ระหว่างการโหลด (F-39)');

    // E8 — โหมดพิมพ์ต้องได้ครบทุกคน ไม่ใช่แค่หน้าเดียว
    $pp = http('GET', "$BASE_URL/admin/payments.php?print=1");
    $printRows = substr_count(unpaidBlock($pp['body']), 'openUserFinesModal');
    check('UNPAID-E8',
        $printRows >= $stats['people'],
        "โหมดพิมพ์ได้ครบ {$printRows} แถว (คนค้างทั้งหมด {$stats['people']} คน)",
        "🔴 โหมดพิมพ์ได้แค่ {$printRows} แถว จากทั้งหมด {$stats['people']} คน");
}

// ============================================================
// SUMMARY
// ============================================================
$pct = $results['total'] > 0 ? round($results['passed'] / $results['total'] * 100, 1) : 0;
echo "\n══════════════════════════════════════\n";
echo " RESULTS: {$results['passed']}/{$results['total']} passed ($pct%)";
if ($results['failed'] > 0) echo " | {$results['failed']} FAILED";
echo "\n══════════════════════════════════════\n\n";

exit($results['failed'] > 0 ? 1 : 0);
