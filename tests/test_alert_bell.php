<?php

/**
 * กระดิ่งแจ้งเตือนฝั่งเจ้าหน้าที่
 *
 * ==========================================================================
 * 🔴 ปัญหาเดิม: กระดิ่งเป็นของปลอม
 * ==========================================================================
 * `admin/header.php` มีปุ่มกระดิ่งมาตลอด แต่ **ไม่มี onclick ไม่มีลิงก์ ไม่มี JS**
 * และจุดสีแดงเป็น HTML ตายตัว — **แดงตลอดเวลาแม้ไม่มีอะไรค้าง**
 *
 * 🧠 ทำไมถึงแย่กว่าไม่มีกระดิ่ง: ผู้ดูแลเห็นจุดแดงทุกวันจนชิน
 *    วันที่มีเรื่องด่วนจริงจะไม่สังเกต = สัญญาณเตือนที่ไม่มีความหมาย
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. ตัวเลขในกระดิ่งตรงกับ query ตรง ๆ ทุกตัว
 * B. 🔴 จุดแดงขึ้น **เฉพาะตอนมีของจริง** — ไม่มีอะไรค้างต้องไม่ขึ้น
 * C. 🔴 ทุกลิงก์ต้องเปิดได้ด้วย **สิทธิ์ของคนที่เห็น** (admin เห็นคนละลิงก์กับ staff)
 * D. ไม่ทำให้หน้าแอดมินช้าลง — ไฟล์นี้ถูก include ทุกหน้า
 *
 * 🧹 ลบบัญชีเจ้าหน้าที่ที่สร้างขึ้น
 *
 * 📌 การใช้งาน: php tests/test_alert_bell.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/DashboardService.php';

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

$pdo         = getDB();
$uniq        = substr((string) getmypid(), -4) . mt_rand(100, 999);
$staffEmail  = "bellstaff{$uniq}@test.local";
$staffPass   = 'BellStaff#2026';
$madeUsers   = [];
$cleanupDone = false;

$cleanup = function () use (&$madeUsers, &$cleanupDone, $pdo) {
    if ($cleanupDone) return;
    $cleanupDone = true;
    echo "\n── CLEANUP ──\n";
    foreach ($madeUsers as $id) {
        try { $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([(int) $id]); } catch (Throwable $e) {}
    }
    try {
        $n = $pdo->exec("DELETE FROM users WHERE name LIKE '%[BELLTEST]%'");
        if ($n > 0) echo "  🧹 กวาดบัญชีที่ติดป้าย [BELLTEST] อีก {$n} คน\n";
    } catch (Throwable $e) {}
    echo '  ลบบัญชี ' . count($madeUsers) . " คน\n";
};
register_shutdown_function($cleanup);

echo "\n╔══════════════════════════════════════════════════════════╗\n";
echo "║  กระดิ่งแจ้งเตือนฝั่งเจ้าหน้าที่                            ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";

/** ยิง HTTP ด้วย cookie jar ที่ระบุ — ต้องแยกเซสชัน admin กับ staff */
function httpAs(string $jar, string $method, string $url, array $fields = []): array
{
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
    $info = curl_getinfo($ch);
    curl_close($ch);
    return [$body, $info];
}

function csrfFrom(string $html): string
{
    return preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m) ? $m[1] : '';
}

/** ดึงลิงก์ในเมนูกระดิ่งจาก HTML */
function bellLinks(string $html): array
{
    preg_match_all('/href="([^"]+)"[^>]*class="flex items-center justify-between/', $html, $m);
    return $m[1] ?? [];
}

$adminJar = tempnam(sys_get_temp_dir(), 'bella');
$staffJar = tempnam(sys_get_temp_dir(), 'bells');
register_shutdown_function(function () use ($adminJar, $staffJar) { @unlink($adminJar); @unlink($staffJar); });

[$lp] = httpAs($adminJar, 'GET', "{$BASE_URL}/login.php");
httpAs($adminJar, 'POST', "{$BASE_URL}/login.php",
    ['csrf_token' => csrfFrom($lp), 'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD]);
[$adminHome] = httpAs($adminJar, 'GET', "{$BASE_URL}/admin/");

// ============================================================
// A. ตัวเลขต้องตรงกับของจริง
// ============================================================
echo "── A. ตัวเลขตรงกับ query ตรง ๆ ──\n";

$bell = (new \App\Services\DashboardService($pdo))->getAlertCounts();
$days = (int) DUE_SOON_DAYS;
$raw = [
    'overdue' => (int) $pdo->query("
        SELECT COUNT(*) FROM borrows WHERE status = 'borrowing' AND due_date < CURDATE()")->fetchColumn(),
    'due_soon' => (int) $pdo->query("
        SELECT COUNT(*) FROM borrows WHERE status = 'borrowing' AND due_date >= CURDATE()
          AND due_date <= DATE_ADD(CURDATE(), INTERVAL {$days} DAY)")->fetchColumn(),
    'pending_reservations' => (int) $pdo->query("
        SELECT COUNT(*) FROM reservations WHERE status = 'pending'")->fetchColumn(),
    'unpaid_people' => (int) $pdo->query("
        SELECT COUNT(DISTINCT b.user_id) FROM borrows b
          LEFT JOIN payments p ON p.borrow_id = b.id
         WHERE b.fine_amount > 0 AND p.id IS NULL AND b.fine_waived_at IS NULL")->fetchColumn(),
];
$mismatch = [];
foreach ($raw as $k => $v) {
    if (($bell[$k] ?? null) !== $v) $mismatch[] = "{$k}: กระดิ่ง " . ($bell[$k] ?? 'null') . " · query {$v}";
}
check('BELL-A1', !$mismatch,
    'ตัวเลขทั้ง 4 ตรงกับ query ตรง ๆ (' . implode(' · ', array_map(fn($k, $v) => "{$k}={$v}", array_keys($raw), $raw)) . ')',
    "🔴 ไม่ตรง:\n       " . implode("\n       ", $mismatch));

check('BELL-A2', ($bell['total'] ?? -1) === array_sum($raw),
    'ยอดรวมบนป้าย = ผลบวกของทุกรายการ (' . array_sum($raw) . ')',
    '🔴 ยอดรวม ' . ($bell['total'] ?? 'null') . ' แต่ผลบวกได้ ' . array_sum($raw));

// ============================================================
// B. จุดแดงขึ้นเฉพาะตอนมีของจริง
// ============================================================
echo "\n── B. จุดแดงต้องไม่โกหก ──\n";

/**
 * 🔴 นี่คือหัวใจของการแก้ครั้งนี้ — ของเดิมจุดแดงเป็น HTML ตายตัว แดงตลอด
 *    เคสนี้ตรวจว่าการแสดงผลผูกกับตัวเลขจริง ไม่ใช่เขียนตายตัวไว้
 */
$hasBadge = (bool) preg_match('/aria-label="การแจ้งเตือน".{0,500}?bg-red-500/s', $adminHome);
check('BELL-B1', $hasBadge === ($bell['total'] > 0),
    $bell['total'] > 0
        ? "มีของค้าง {$bell['total']} รายการ → ป้ายแดงขึ้น"
        : 'ไม่มีของค้าง → ป้ายแดงไม่ขึ้น',
    $bell['total'] > 0
        ? '🔴 มีของค้างแต่ป้ายไม่ขึ้น'
        : '🔴 ไม่มีอะไรค้างแต่ป้ายแดงยังขึ้น — จุดแดงไม่ได้ผูกกับข้อมูลจริง');

// 🧠 ตรวจว่าไม่มีจุดแดงแบบเขียนตายตัวหลงเหลือในไฟล์
$headerSrc = (string) file_get_contents(dirname(__DIR__) . '/admin/header.php');
$hardcoded = preg_match('/<span class="absolute top-0 right-0 block h-2 w-2 rounded-full ring-2 ring-white bg-red-500"><\/span>/', $headerSrc);
check('BELL-B2', !$hardcoded,
    'ไม่มีจุดแดงแบบเขียนตายตัวหลงเหลือใน header',
    '🔴 ยังมีจุดแดงตายตัวอยู่ — จะแดงตลอดไม่ว่ามีอะไรค้างหรือไม่');

check('BELL-B3', str_contains($adminHome, 'ไม่มีอะไรค้าง') || $bell['total'] > 0,
    'มีข้อความรองรับกรณีไม่มีอะไรค้าง',
    '🔴 ไม่มีข้อความสำหรับสถานะว่าง — เมนูจะโล่งโดยไม่บอกอะไร');

// ============================================================
// C. ลิงก์ต้องเปิดได้ด้วยสิทธิ์ของคนที่เห็น
// ============================================================
echo "\n── C. ลิงก์เปิดได้จริงทั้ง admin และ staff ──\n";

/**
 * 🔴 `reports.php` เป็น **admin เท่านั้น** ส่วนหน้าอื่นเป็น staff ได้
 *    กระดิ่งที่พาเจ้าหน้าที่ไปหน้าที่กดแล้วเด้งออก **แย่กว่าไม่มีกระดิ่ง**
 *    เพราะเขาจะคิดว่าระบบพัง ไม่ใช่ว่าตัวเองไม่มีสิทธิ์
 */
$stmt = $pdo->prepare("INSERT INTO users (name, email, password, phone, role, must_change_password)
                       VALUES (?, ?, ?, ?, 'staff', 0)");
$stmt->execute(["[BELLTEST] เจ้าหน้าที่ {$uniq}", $staffEmail, hashPassword($staffPass), '0800000000']);
$madeUsers[] = (int) $pdo->lastInsertId();

[$lp2] = httpAs($staffJar, 'GET', "{$BASE_URL}/login.php");
httpAs($staffJar, 'POST', "{$BASE_URL}/login.php",
    ['csrf_token' => csrfFrom($lp2), 'email' => $staffEmail, 'password' => $staffPass]);
[$staffHome] = httpAs($staffJar, 'GET', "{$BASE_URL}/admin/");

$adminLinks = bellLinks($adminHome);
$staffLinks = bellLinks($staffHome);

check('BELL-C1', $bell['total'] === 0 || ($adminLinks && $staffLinks),
    'ทั้ง admin และ staff เห็นรายการในกระดิ่ง (' . count($adminLinks) . ' / ' . count($staffLinks) . ' รายการ)',
    '🔴 ฝ่ายใดฝ่ายหนึ่งไม่เห็นรายการเลย — admin ' . count($adminLinks) . ' · staff ' . count($staffLinks));

$broken = [];
foreach ([['admin', $adminJar, $adminLinks], ['staff', $staffJar, $staffLinks]] as [$who, $jar, $links]) {
    foreach ($links as $u) {
        [$body, $info] = httpAs($jar, 'GET', "{$BASE_URL}/admin/{$u}");
        $kicked = str_contains($info['url'] ?? '', 'login')
               || str_contains($body, 'ไม่มีสิทธิ์')
               || ($info['http_code'] ?? 0) >= 400;
        if ($kicked) $broken[] = "{$who} → {$u}";
    }
}
check('BELL-C2', !$broken,
    'ลิงก์ในกระดิ่งเปิดได้ทุกอันด้วยสิทธิ์ของคนที่เห็น',
    "🔴 กดแล้วเข้าไม่ได้:\n       " . implode("\n       ", $broken));

// 🔴 เจ้าหน้าที่ต้องไม่ถูกพาไปหน้ารายงานซึ่งเป็นของ admin
check('BELL-C3', !in_array('reports.php?report=due_soon', $staffLinks, true),
    'เจ้าหน้าที่ไม่ถูกพาไปหน้ารายงาน (admin เท่านั้น) — ได้ลิงก์หน้ายืม-คืนแทน',
    '🔴 staff เห็นลิงก์ไปหน้ารายงานซึ่งกดแล้วเด้งออก');

// ============================================================
// D. ต้องไม่ทำให้ทุกหน้าแอดมินช้า
// ============================================================
echo "\n── D. ความเร็ว ──\n";

/**
 * 🔴 `admin/header.php` ถูก include ทุกหน้าแอดมิน — ของแพงที่นี่ทำให้ช้าทั้งระบบ
 *    จึงห้ามใช้ `getCardStats()` (~22 ms · ดึงของที่กระดิ่งไม่ใช้)
 *    `getAlertCounts()` รวมทุกอย่างไว้ใน query เดียว
 */
/**
 * 🔴 ต้องวัดใน **โปรเซสใหม่** — `getAlertCounts()` cache ด้วย `static` ซึ่งอยู่ระดับโปรเซส
 *    ข้อ A เรียกไปแล้ว ถ้าวัดในโปรเซสเดียวกันจะได้ 0.1 ms จาก cache
 *    = เคสเขียวแบบไม่ได้ตรวจอะไรเลย แม้ query จะช้าแค่ไหนก็ตาม
 */
$root = escapeshellarg(dirname(__DIR__));
$code = 'require ' . $root . '."/includes/config.php"; require ' . $root
      . '."/includes/db.php"; require ' . $root . '."/includes/functions.php"; require ' . $root
      . '."/app/Services/DashboardService.php";'
      . '$t=microtime(true); (new \\App\\Services\\DashboardService(getDB()))->getAlertCounts();'
      . 'echo (microtime(true)-$t)*1000;';
$ms = (float) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
check('BELL-D1', $ms > 0 && $ms < 100,
    sprintf('getAlertCounts() แบบเย็น (โปรเซสใหม่) ใช้ %.1f ms — เกณฑ์ < 100 ms', $ms),
    $ms <= 0
        ? '🔴 วัดไม่ได้ — โปรเซสลูกไม่คืนค่า ต้องแก้เทสต์นี้ ไม่ใช่แก้โค้ด'
        : sprintf('🔴 ใช้ %.1f ms — ช้าเกินไปสำหรับของที่ include ทุกหน้าแอดมิน', $ms));

// 📌 เรียกซ้ำต้องมาจาก cache ไม่ยิง query ใหม่
$svc = new \App\Services\DashboardService($pdo);
$svc->getAlertCounts();
$t2 = microtime(true);
$svc->getAlertCounts();
$ms2 = (microtime(true) - $t2) * 1000;
check('BELL-D2', $ms2 < 1.0,
    sprintf('เรียกซ้ำใช้ %.3f ms — มาจาก cache ไม่ยิง query ใหม่', $ms2),
    sprintf('🔴 เรียกซ้ำใช้ %.1f ms — ไม่ได้ cache', $ms2));

// ============================================================
echo "\n══════════════════════════════════════\n";
printf(" RESULTS: %d/%d passed (%.1f%%)%s\n",
    $results['passed'], $results['total'],
    $results['total'] ? $results['passed'] / $results['total'] * 100 : 0,
    $results['failed'] ? ' | ' . $results['failed'] . ' FAILED' : '');
echo "══════════════════════════════════════\n";

exit($results['failed'] > 0 ? 1 : 0);
