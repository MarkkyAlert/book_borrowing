<?php

/**
 * ทดสอบไฟล์ CSV ที่ส่งออกจากรายงาน — F-44
 *
 * ==========================================================================
 * 🔴 ปัญหาเดิม
 * ==========================================================================
 * CSV เขียนเบอร์โทรเป็นตัวเลขเปล่า `0891809067`
 * Excel ตีความเป็นตัวเลขแล้วตัด 0 นำหน้าทิ้ง → `891809067`
 * ตรวจแล้ว 217/217 แถวเป็นแบบนี้ทั้งหมด — เจ้าหน้าที่เอาไปโทรตามคนไม่ได้
 *
 * 🔴 และเจอของที่ร้ายแรงกว่าระหว่างแก้ (เกิดจาก ROADMAP ข้อ 4)
 *    รายงาน "สมาชิกค้างชำระ" มีหัวตาราง 5 คอลัมน์ แต่ข้อมูล 6 คอลัมน์
 *    เพราะเติม b.status เข้า query แต่ลืมเติมหัวตาราง
 *    → ทุกคอลัมน์ตั้งแต่ "ค่าปรับ" เลื่อนผิดตำแหน่ง
 *      และค่า enum ภาษาอังกฤษ (returned/lost/damaged) โผล่ในไฟล์ที่ลูกค้าเอาไปใช้
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. โครงสร้างไฟล์ — BOM · จำนวนคอลัมน์หัวต้องเท่ากับทุกแถว (ครบ 5 รายงาน)
 * B. เบอร์โทร — มี ' นำหน้า · 0 นำหน้าครบ
 * C. 🔴 เงินและจำนวนนับ — ต้องเป็นตัวเลขล้วน **ไม่มีคอมมา** ไม่งั้น Excel SUM ไม่ได้
 * D. ค่า enum ต้องถูกแปลเป็นภาษาไทย ไม่ปล่อยดิบ
 * E. 🛡️ กัน CSV Formula Injection ยังทำงาน และไม่ไปทำลายคอลัมน์ตัวเลข
 *
 * 🧹 ไม่สร้างข้อมูลใหม่ — อ่านจากของที่มีอยู่ล้วน ๆ
 *
 * 📌 การใช้งาน: php tests/test_csv_export.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/report_helper.php';

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

$COOKIE = tempnam(sys_get_temp_dir(), 'bbcsv');
$TMPDIR = sys_get_temp_dir() . '/bbcsv_' . getmypid();
@mkdir($TMPDIR);

register_shutdown_function(function () use ($COOKIE, $TMPDIR) {
    @unlink($COOKIE);
    foreach (glob($TMPDIR . '/*') ?: [] as $f) @unlink($f);
    @rmdir($TMPDIR);
    echo "\n── CLEANUP ──\n  ลบไฟล์ CSV ชั่วคราวแล้ว\n";
});

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  ไฟล์ CSV ที่ส่งออกจากรายงาน (F-44)                       ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

// ============================================================
// E. ตัวจัดรูปแบบ (ทดสอบตรง ๆ ก่อน — ไม่ต้องพึ่ง HTTP)
// ============================================================
echo "── E. ตัวจัดรูปแบบและตัวกัน formula injection ──\n";

// E1 — เบอร์โทรต้องได้ ' นำหน้า
$phones = ['0891809067', '0812345678', '021234567'];
$bad = [];
foreach ($phones as $p) {
    $out = csvReportValue('user_phone', $p);
    if ($out !== "'" . $p) $bad[] = "{$p} → {$out}";
}
check('CSV-E1', $bad === [],
    "เบอร์โทรได้ ' นำหน้าครบ — Excel จะมองเป็นข้อความ ไม่ตัด 0 ทิ้ง",
    '🔴 เบอร์โทรไม่ถูกป้องกัน: ' . implode(' · ', $bad));

check('CSV-E2',
    csvReportValue('user_phone', '') === '',
    'เบอร์โทรที่ว่างยังเป็นช่องว่าง ไม่กลายเป็นเครื่องหมาย \' เดี่ยว ๆ',
    'ค่าว่างถูกเติม \' โดยไม่จำเป็น');

// E3 — 🔴 เงินต้องไม่มีคอมมา (Excel SUM ได้)
$money = [];
foreach ([['fine_amount', 1250.5, '1250.50'], ['total_amount', 300, '300.00']] as [$k, $v, $want]) {
    $got = csvReportValue($k, $v);
    if ($got !== $want) $money[] = "{$k}={$v} → {$got} (ควรเป็น {$want})";
}
check('CSV-E3', $money === [],
    'คอลัมน์เงินเป็นตัวเลขล้วนไม่มีคอมมา — Excel SUM ได้',
    '🔴 ' . implode(' · ', $money) . ' — ลูกค้าเอาไปรวมยอดต่อไม่ได้');

// E4 — enum ต้องถูกแปล
check('CSV-E4',
    csvReportValue('status', 'returned') === 'ค่าปรับคืนช้า'
        && csvReportValue('status', 'lost') === 'ค่าชดใช้ (หาย)'
        && csvReportValue('status', 'damaged') === 'ค่าชดใช้ (ชำรุด)',
    'ค่า status ถูกแปลเป็นภาษาไทยทั้ง 3 แบบ',
    '🔴 ค่า enum ภาษาอังกฤษหลุดลงไฟล์: ' . csvReportValue('status', 'returned'));

// E5 — 🛡️ สูตรอันตรายต้องยังถูกกัน
$attacks = ["=cmd|' /C calc'!A0", '-1+1', '+1+1', '@SUM(A1)', "\t=x", "\r=y"];
$escaped = [];
foreach ($attacks as $a) {
    if (!str_starts_with(csvSafeValue($a), "'")) $escaped[] = $a;
}
check('CSV-E5', $escaped === [],
    'สูตรอันตรายทั้ง ' . count($attacks) . ' แบบยังถูกเติม \' นำหน้า',
    '🔴 หลุด: ' . implode(' · ', $escaped));

// E6 — 🔴 แต่ต้องไม่ไปทำลายตัวเลข (รวมค่าติดลบ)
$numbers = ['-50.00', '300.00', '0', '-1250.50', '1250'];
$broken = [];
foreach ($numbers as $n) {
    if (csvSafeValue($n) !== $n) $broken[] = "{$n} → " . csvSafeValue($n);
}
check('CSV-E6', $broken === [],
    'ตัวเลขล้วน (รวมค่าติดลบ) ผ่านโดยไม่ถูกเติม \' — SUM ได้',
    '🔴 ตัวกัน injection ทำลายคอลัมน์ตัวเลข: ' . implode(' · ', $broken)
        . ' — วันที่มีระบบคืนเงิน/ปรับยอด ลูกค้าจะ SUM ไม่ได้');

// ============================================================
// A–D. ไฟล์จริงผ่าน HTTP
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
        CURLOPT_TIMEOUT        => 40,
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
    fail('CSV-A1', 'ล็อกอินไม่สำเร็จ — ข้ามการทดสอบไฟล์จริง (ส่งรหัสผ่าน admin เป็น argument)');
} else {
    $reports = ['unpaid', 'overdue', 'books', 'members', 'revenue'];
    $files   = [];

    foreach ($reports as $rep) {
        $raw = http('GET', "$BASE_URL/admin/reports.php?report={$rep}&export=csv")['body'];
        $path = $TMPDIR . "/{$rep}.csv";
        file_put_contents($path, $raw);
        $files[$rep] = $path;
    }

    echo "\n── A. โครงสร้างไฟล์ ──\n";

    // A1 — BOM ต้องยังอยู่ทุกไฟล์ (ไม่งั้น Excel อ่านภาษาไทยเพี้ยน)
    $noBom = [];
    foreach ($files as $rep => $path) {
        if (substr((string) file_get_contents($path), 0, 3) !== "\xEF\xBB\xBF") $noBom[] = $rep;
    }
    check('CSV-A1', $noBom === [],
        'ทุกไฟล์มี BOM — Excel อ่านภาษาไทยไม่เพี้ยน',
        '🔴 ไม่มี BOM: ' . implode(', ', $noBom));

    // A2 — 🔴 จำนวนคอลัมน์หัวตารางต้องเท่ากับทุกแถว
    $mismatch = [];
    $rowCounts = [];
    foreach ($files as $rep => $path) {
        $fh = fopen($path, 'r');
        $head = fgetcsv($fh);
        $headCount = is_array($head) ? count($head) : 0;
        $line = 1; $bad = 0;
        while (($row = fgetcsv($fh)) !== false) {
            $line++;
            if ($row === [null]) continue;   // บรรทัดว่างท้ายไฟล์
            if (count($row) !== $headCount) $bad++;
        }
        fclose($fh);
        $rowCounts[$rep] = $line - 1;
        if ($bad > 0) $mismatch[] = "{$rep}: หัว {$headCount} คอลัมน์ แต่มี {$bad} แถวไม่ตรง";
    }
    check('CSV-A2', $mismatch === [],
        'ทุกรายงานมีจำนวนคอลัมน์ตรงกันทั้งไฟล์ (' . implode(' · ', array_map(
            fn($k, $v) => "{$k} {$v} แถว", array_keys($rowCounts), $rowCounts)) . ')',
        "🔴 คอลัมน์ไม่ตรง:\n       " . implode("\n       ", $mismatch));

    echo "\n── B. เบอร์โทร ──\n";

    // B1 — ทุกเบอร์ต้องมี ' นำหน้า
    $phoneReports = ['unpaid' => 1, 'overdue' => 1];   // ดัชนีคอลัมน์เบอร์โทร
    $unprotected = [];
    $zeroKept = 0; $phoneTotal = 0;
    foreach ($phoneReports as $rep => $col) {
        $rows = array_map('str_getcsv', file($files[$rep]));
        array_shift($rows);
        foreach ($rows as $row) {
            if (!isset($row[$col]) || $row[$col] === '') continue;
            $phoneTotal++;
            if (!str_starts_with($row[$col], "'")) $unprotected[] = "{$rep}: {$row[$col]}";
            if (str_starts_with($row[$col], "'0")) $zeroKept++;
        }
    }
    check('CSV-B1', $unprotected === [] && $phoneTotal > 0,
        "เบอร์โทรทั้ง {$phoneTotal} แถวมี ' นำหน้าครบ (0 นำหน้าคงอยู่ {$zeroKept} แถว)",
        '🔴 ' . count($unprotected) . ' แถวไม่ถูกป้องกัน เช่น ' . implode(' · ', array_slice($unprotected, 0, 3)));

    echo "\n── C. เงินและจำนวนนับ ──\n";

    // C1 — 🔴 คอลัมน์เงินต้องเป็นตัวเลขล้วน
    $moneyCols = ['unpaid' => 5, 'revenue' => 2];
    $notSummable = [];
    foreach ($moneyCols as $rep => $col) {
        $rows = array_map('str_getcsv', file($files[$rep]));
        array_shift($rows);
        foreach ($rows as $row) {
            if (!isset($row[$col]) || $row[$col] === '') continue;
            if (!preg_match('/^-?\d+(\.\d+)?$/', $row[$col])) $notSummable[] = "{$rep}: {$row[$col]}";
        }
    }
    // 🧠 เคสนี้พึ่งข้อมูลจริง — ถ้าไม่มีค่าเงินเกินหลักพัน คอมมาจะไม่โผล่ให้จับได้
    //    ตัวที่มีฟันแน่นอนคือ CSV-E3 ซึ่งทดสอบ 1250.5 ตรง ๆ ที่ชั้นฟังก์ชัน
    //    บอกไว้ให้ชัดว่าเคสนี้ครอบคลุมแค่ไหน จะได้ไม่เข้าใจผิดว่ามันคุมครบ
    $maxMoney = 0.0;
    foreach ($moneyCols as $rep => $col) {
        foreach (array_slice(array_map('str_getcsv', file($files[$rep])), 1) as $row) {
            if (isset($row[$col]) && is_numeric($row[$col])) $maxMoney = max($maxMoney, (float) $row[$col]);
        }
    }
    $coverageNote = $maxMoney >= 1000
        ? 'ข้อมูลมีค่าเกินหลักพัน (' . number_format($maxMoney, 2) . ') คอมมาจะโผล่ถ้าพลาด'
        : 'ข้อมูลสูงสุดแค่ ' . number_format($maxMoney, 2) . ' — เคสนี้จับคอมมาไม่ได้ ตัวที่คุมคือ CSV-E3';

    check('CSV-C1', $notSummable === [],
        "คอลัมน์เงินเป็นตัวเลขล้วนทุกแถว — Excel SUM ได้ ({$coverageNote})",
        '🔴 SUM ไม่ได้ ' . count($notSummable) . ' แถว เช่น ' . implode(' · ', array_slice($notSummable, 0, 3)));

    echo "\n── D. ค่า enum ──\n";

    // D1 — ไม่มีคำภาษาอังกฤษดิบหลุดลงไฟล์
    $rawEnum = [];
    foreach ($files as $rep => $path) {
        $content = (string) file_get_contents($path);
        foreach (['returned', 'borrowing', 'damaged', 'pending', 'waiting', 'fulfilled'] as $enum) {
            if (preg_match('/(^|,|")' . $enum . '(,|"|$)/m', $content)) $rawEnum[] = "{$rep}: {$enum}";
        }
    }
    check('CSV-D1', $rawEnum === [],
        'ไม่มีค่า enum ภาษาอังกฤษดิบในไฟล์ที่ลูกค้าเอาไปใช้',
        '🔴 พบค่าดิบ: ' . implode(' · ', $rawEnum));

    // D2 — รายงานค้างชำระต้องบอกได้ว่าเงินก้อนนี้มาจากอะไร
    $unpaidRows = array_map('str_getcsv', file($files['unpaid']));
    $unpaidHead = array_shift($unpaidRows);
    check('CSV-D2',
        in_array('ประเภท', $unpaidHead, true),
        'รายงานค้างชำระมีคอลัมน์ "ประเภท" บอกว่าเป็นค่าปรับหรือค่าชดใช้',
        '🔴 ไม่มีหัวคอลัมน์สำหรับ status ที่ query คืนมา — คอลัมน์จะเลื่อนผิดตำแหน่ง');
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
