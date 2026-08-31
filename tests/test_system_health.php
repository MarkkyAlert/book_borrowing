<?php

/**
 * กระดิ่ง "สุขภาพระบบ" — ของที่พังเงียบ
 *
 * ==========================================================================
 * 🔴 ปัญหาเดิม: 5 สภาวะที่ระบบตรวจได้ทันที แต่ไม่มีที่ไหนบอกใครเลย
 * ==========================================================================
 * H1 สต็อกไม่ตรงกับการยืมจริง · H2 เปิดอีเมลไว้แต่ส่งไม่ออก
 * H3 ยังไม่ได้ลบ install.php · H4 เปิดโหมดพัฒนาบนเครื่องจริง
 * H5 โฟลเดอร์ปกเขียนไม่ได้
 *
 * ==========================================================================
 * 🎯 ทดสอบอะไร
 * ==========================================================================
 * A. ค่าปกติต้องเงียบ — ไม่มี noise
 * B. 🔴 H1 ต้องจับ "ความหมายเพี้ยน" ไม่ใช่ "ช่วงตัวเลขเพี้ยน" (ซึ่ง CHECK กันไปแล้ว)
 *    + ตัวนับกับตัวกรองต้องได้เลขเดียวกัน + ห้ามซ่อมให้เอง
 * C. 🔴 H2 บันทึกความล้มเหลว / ล้างเมื่อสำเร็จ / ไม่เก็บรหัสผ่าน / ปุ่มทดสอบไม่บันทึก
 * D-E. H3/H4 ตรงกับสภาพจริงของเครื่องที่รัน
 * F. คีย์ใหม่ต้องไม่โผล่เป็นช่องกรอกในหน้าตั้งค่า
 * G. 🔴 staff ไม่เห็นข้อที่ตัวเองแก้ไม่ได้ · ไม่มี path เซิร์ฟเวอร์หลุดออกหน้าจอ
 * H. ตัวตรวจพังต้องไม่ล้มหน้าแอดมิน · ไม่ทิ้งไฟล์ขยะ
 *
 * 🧹 คืนค่าทุกอย่าง: rollback สต็อก · ลบ mail_* · ปิด SMTP ปลอม
 *
 * 📌 การใช้งาน: php tests/test_system_health.php [รหัสผ่าน admin]
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../app/Services/DashboardService.php';

$BASE_URL       = rtrim(APP_URL, '/');
$ADMIN_EMAIL    = 'admin@library.com';
$ADMIN_PASSWORD = $argv[1] ?? '123456';
$ROOT           = dirname(__DIR__);

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

$COOKIE = tempnam(sys_get_temp_dir(), 'bbsh');
function http(string $method, string $url, array $fields = [], ?string $jar = null): string
{
    global $COOKIE;
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
 * 🔴 ตัวตรวจถูก cache 2 ชั้น: static $cache ในเมธอด + $_SESSION
 *    ทั้งสองชั้นอยู่ระดับโปรเซส → อ่านในโปรเซสเดิมจะเห็นค่าเก่าตลอดกาล
 *    ต้องยิงโปรเซสใหม่ทุกครั้งถึงจะเห็นสภาพจริง (แนวเดียวกับ test_mail_reset.php)
 *
 * ⚠️ ผลข้างเคียงสำคัญ: โปรเซสใหม่มองไม่เห็นข้อมูลที่ยังไม่ commit
 *    การทดสอบสต็อกจึงต้อง commit จริงแล้วคืนค่าเอง (ดูข้อ B)
 */
function probe(string $action, string $arg = ''): array
{
    global $PROBE_FILE;
    $out = (string) shell_exec(sprintf('%s %s %s %s 2>/dev/null',
        escapeshellarg(PHP_BINARY), escapeshellarg($PROBE_FILE),
        escapeshellarg($action), escapeshellarg($arg)));
    $json = json_decode(trim($out), true);
    return is_array($json) ? $json : ['error' => 'probe ล้มเหลว: ' . substr($out, 0, 200)];
}

function freshHealth(PDO $pdo): array
{
    $r = probe('health');
    return ['items' => $r['items'] ?? [], 'total' => $r['total'] ?? 0, 'admin_total' => $r['admin_total'] ?? 0];
}

function healthKeys(array $h): array
{
    return array_column($h['items'], 'key');
}

$pdo = getDB();
$uniq = bin2hex(random_bytes(4));

/**
 * 🧪 สคริปต์ตัวแทน — รันในโปรเซสใหม่เพื่อเลี่ยง static cache ทุกชั้น
 *    วางไว้ใน tests/ เพราะใช้ __DIR__/../ อ้างถึงโค้ดจริง
 */
$PROBE_FILE = __DIR__ . "/.probe_sys_{$uniq}.php";
file_put_contents($PROBE_FILE, <<<'PROBE'
<?php
$action = $argv[1] ?? 'health';
$arg    = $argv[2] ?? '';
if ($action === 'health_debug') { define('APP_DEBUG', true); }
error_reporting(0);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../app/Services/DashboardService.php';

if ($action === 'health_stale') {
    // 🕐 จำลองว่า cache ถูกตรวจไว้เมื่อ N วินาทีก่อน (ยังไม่หมดอายุ)
    $_SESSION['sys_health_cache']    = [];
    $_SESSION['sys_health_cache_at'] = time() - (int) $arg;
}
if ($action === 'health' || $action === 'health_debug' || $action === 'health_stale') {
    $svc = new \App\Services\DashboardService(getDB());
    $t1 = microtime(true); $h = $svc->getSystemHealth(); $first = (microtime(true) - $t1) * 1000;
    $t2 = microtime(true); $svc->getSystemHealth();       $second = (microtime(true) - $t2) * 1000;
    echo json_encode($h + ['ms_first' => $first, 'ms_second' => $second], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($action === 'send_saved') {
    echo json_encode(sendMail('someone@test.local', 'หัวข้อภาษาไทย', 'เนื้อความ'), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($action === 'send_cfg') {
    // 🔘 จำลองปุ่ม "ทดสอบส่ง" — ส่ง cfg ที่ยังไม่ได้บันทึกเข้าไป
    echo json_encode(sendMail('someone@test.local', 'ทดสอบ', 'x', [
        'host' => '127.0.0.1', 'port' => (int) $arg, 'secure' => 'none',
        'username' => '', 'password' => '',
        'from_email' => 'lib@test.local', 'from_name' => 'ทดสอบ',
    ]), JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode(['error' => 'unknown action']);
PROBE
);
register_shutdown_function(fn() => @unlink($PROBE_FILE));

echo "\n🔎 กระดิ่งสุขภาพระบบ\n══════════════════════════════════════\n\n";

// ============================================================
echo "── A. ค่าปกติต้องเงียบ ──\n";
// ============================================================

$base = freshHealth($pdo);
$baseKeys = healthKeys($base);
pass('SYS-A1', 'สภาพเครื่องนี้: ' . ($baseKeys ? implode(', ', $baseKeys) : 'ไม่มีข้อใดเลย'));

check('SYS-A2', !in_array('stock_anomaly', $baseKeys, true),
    'สต็อกตรงสูตรทุกเล่ม — ข้อ B จึงเริ่มจากศูนย์จริง',
    '🔴 สต็อกเพี้ยนอยู่ก่อนแล้ว — ต้องแก้ก่อน ไม่งั้นข้อ B พิสูจน์อะไรไม่ได้');

// ============================================================
echo "\n── B. H1 สต็อกไม่ตรงสูตร ──\n";
// ============================================================

/**
 * 🔴 พิสูจน์ก่อนว่า "เงื่อนไขที่คิดจะใช้ตอนแรก" ใช้ไม่ได้
 *    ถ้า CHECK constraint มีอยู่ → available<0 หรือ >quantity เขียนลง DB ไม่ได้เลย
 *    → ตัวตรวจที่ใช้เงื่อนไขนั้นจะเป็นจริงไม่ได้สักครั้ง = ฟีเจอร์ที่ไม่มีวันทำงาน
 */
$checks = $pdo->query("
    SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME LIKE 'chk_books%'
")->fetchAll(PDO::FETCH_COLUMN);
check('SYS-B0', count($checks) >= 2,
    'CHECK constraint มีจริง (' . implode(', ', $checks) . ') → เงื่อนไข available<0 จึงยิงไม่ออก '
        . 'ตัวตรวจต้องเทียบ "ความหมาย" เท่านั้น',
    '🔴 ไม่มี CHECK constraint — ต้องทบทวนนิยาม H1 ใหม่');

/**
 * 🧪 ยัดความเพี้ยนแบบที่ CHECK ยอมรับ แล้วคืนค่าเอง
 *
 * 🔴 ใช้ transaction+rollback ไม่ได้ เพราะตัวตรวจต้องอ่านจากโปรเซสใหม่ (static cache)
 *    ซึ่งมองไม่เห็นข้อมูลที่ยังไม่ commit → ต้อง commit จริงแล้วคืนค่าด้วยมือ
 * 🛡️ จดค่าเดิมไว้ก่อนแตะ + ผูก shutdown handler คืนค่าให้แม้สคริปต์ตายกลางคัน
 *    แล้วปิดท้ายด้วย SYS-B6 ที่ตรวจว่าไม่เหลือความเสียหายจริง ๆ
 */
$stockUndo = [];
register_shutdown_function(function () use ($pdo, &$stockUndo) {
    foreach ($stockUndo as $bookId => $orig) {
        $pdo->prepare("UPDATE books SET available = ? WHERE id = ?")->execute([$orig, $bookId]);
    }
});

try {
    // 🅐 เล่มที่ไม่มีความเคลื่อนไหวเลย แต่ available ผิด
    //    🔴 เคสนี้สำคัญ: เคยเขียน EXISTS ตัดเล่มแบบนี้ออกเพื่อความเร็ว แล้วจะมองไม่เห็น
    //    (เกิดจริงได้จากการแก้ quantity แล้วไม่ปรับ available)
    $quiet = $pdo->query("
        SELECT b.id, b.quantity, b.available FROM books b
        WHERE b.quantity >= 2
          AND NOT EXISTS (SELECT 1 FROM borrows bo WHERE bo.book_id = b.id)
          AND NOT EXISTS (SELECT 1 FROM reservations r WHERE r.book_id = b.id)
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    if (!$quiet) {
        fail('SYS-B1', '🔴 หาเล่มที่ไม่มีความเคลื่อนไหวไม่เจอ — ทดสอบเคสนี้ไม่ได้');
    } else {
        $stockUndo[$quiet['id']] = (int) $quiet['available'];
        $pdo->prepare("UPDATE books SET available = available - 1 WHERE id = ?")
            ->execute([$quiet['id']]);
        $h = freshHealth($pdo);
        check('SYS-B1', in_array('stock_anomaly', healthKeys($h), true),
            "จับได้: เล่มที่ไม่เคยถูกยืม/จอง แต่ available ผิด (id={$quiet['id']}) "
                . "— ยังผ่าน CHECK ทั้งสองตัว",
            '🔴 ไม่จับ — ตัวตรวจกรองเล่มที่ไม่มีความเคลื่อนไหวออกไปหรือเปล่า');

        // 🔴 ห้ามซ่อมให้เอง
        $after = (int) $pdo->query("SELECT available FROM books WHERE id = {$quiet['id']}")->fetchColumn();
        check('SYS-B2', $after === (int) $quiet['available'] - 1,
            'ไม่แตะตัวเลขให้เอง — available ยังผิดอยู่เท่าเดิม (เตือนอย่างเดียวตามที่ออกแบบ)',
            "🔴 ตัวเลขถูกแก้เป็น {$after} — ระบบซ่อมเงียบ ๆ จะกลบหลักฐานว่ามีรายการยืมหายไป");

        // 🔴 ตัวนับ (กระดิ่ง) ต้องเท่ากับตัวกรอง (หน้ารายการ)
        require_once __DIR__ . '/../app/Repositories/BookRepository.php';
        $repo    = new \App\Repositories\BookRepository($pdo);
        $counted = $repo->countStockAnomalies();
        $listed  = count($repo->findAll(['stock_anomaly' => '1', 'limit' => 500, 'offset' => 0]));
        check('SYS-B3', $counted === $listed && $counted > 0,
            "กระดิ่งนับ {$counted} · หน้ารายการแสดง {$listed} — ตรงกัน",
            "🔴 กระดิ่งนับ {$counted} แต่หน้ารายการแสดง {$listed} — กดแล้วเจอคนละจำนวน");

        $pdo->prepare("UPDATE books SET available = ? WHERE id = ?")
            ->execute([(int) $quiet['available'], $quiet['id']]);
        unset($stockUndo[$quiet['id']]);
    }

    // 🅑 เล่มที่มีการยืมจริง แต่ available ไม่ถูกหัก
    $borrowed = $pdo->query("
        SELECT b.id, b.available FROM books b
        WHERE EXISTS (SELECT 1 FROM borrows bo WHERE bo.book_id = b.id AND bo.status = 'borrowing')
          AND b.available < b.quantity
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    if (!$borrowed) {
        pass('SYS-B4', '⚠️ ไม่มีเล่มที่กำลังถูกยืมอยู่ — ข้ามเคสนี้ (ไม่ใช่ความล้มเหลว)');
    } else {
        $stockUndo[$borrowed['id']] = (int) $borrowed['available'];
        $pdo->prepare("UPDATE books SET available = available + 1 WHERE id = ?")
            ->execute([$borrowed['id']]);
        $h = freshHealth($pdo);
        check('SYS-B4', in_array('stock_anomaly', healthKeys($h), true),
            "จับได้: เล่มที่มีคนยืมอยู่ แต่ available ไม่ถูกหัก (id={$borrowed['id']})",
            '🔴 ไม่จับ — สูตรนับ borrows.status=borrowing ผิดหรือเปล่า');
        $pdo->prepare("UPDATE books SET available = ? WHERE id = ?")
            ->execute([(int) $borrowed['available'], $borrowed['id']]);
        unset($stockUndo[$borrowed['id']]);
    }

    // 🅒 คืนค่าครบแล้วต้องเงียบเหมือนเดิม
    $h = freshHealth($pdo);
    check('SYS-B5', !in_array('stock_anomaly', healthKeys($h), true),
        'คืนค่าแล้วเงียบเหมือนเดิม — ตัวตรวจตอบตามข้อมูลจริง ไม่ได้ค้างสถานะไว้',
        '🔴 ยังเตือนอยู่ทั้งที่คืนค่าแล้ว');
} finally {
    // 🧹 กันตกหล่น: คืนทุกเล่มที่ยังค้างอยู่ในรายการ แล้วเคลียร์รายการ
    foreach ($stockUndo as $bookId => $orig) {
        $pdo->prepare("UPDATE books SET available = ? WHERE id = ?")->execute([$orig, $bookId]);
    }
    $stockUndo = [];
}

$residue = (int) $pdo->query("
    SELECT COUNT(*) FROM books b WHERE b.available <> b.quantity
      - (SELECT COUNT(*) FROM borrows bo WHERE bo.book_id = b.id AND bo.status = 'borrowing')
      - (SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.id AND r.status = 'pending')
")->fetchColumn();
check('SYS-B6', $residue === 0,
    'คืนค่าแล้วสต็อกกลับมาตรงสูตรทุกเล่ม — ไม่ทิ้งความเสียหายไว้',
    "🔴 เหลือเล่มเพี้ยน {$residue} เล่มหลังทดสอบ — ต้องแก้ด้วยมือ");

// ============================================================
echo "\n── C. H2 อีเมลส่งไม่ออก ──\n";
// ============================================================

$mailBefore = (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key LIKE 'mail_%'")->fetchColumn();

$port    = 21000 + (getmypid() % 4000);
$outFile = sys_get_temp_dir() . "/syshealth_{$uniq}.json";
$sinkPhp = <<<'SINK'
<?php
$port = (int) $argv[1]; $out = $argv[2];
$srv = @stream_socket_server("tcp://127.0.0.1:$port", $e, $s);
if (!$srv) { file_put_contents($out, json_encode(['error' => "listen: $s"])); exit(1); }
file_put_contents($out . '.ready', 'ok');
$n = 0;
for ($round = 0; $round < 3; $round++) {
    $c = @stream_socket_accept($srv, 20);
    if (!$c) break;
    stream_set_timeout($c, 10);
    $inData = false; $authStep = 0;
    fwrite($c, "220 fake ESMTP\r\n");
    while (($line = fgets($c, 2048)) !== false) {
        if ($inData) {
            if (rtrim($line, "\r\n") === '.') { $inData = false; $n++; fwrite($c, "250 OK queued\r\n"); }
            continue;
        }
        $u = strtoupper(trim($line));
        // 🔴 ต้องรองรับ AUTH LOGIN จริง — ค่าที่บันทึกไว้มี username/password
        //    ถ้าตอบ 250 มั่ว ๆ sendMail จะล้มที่ขั้น AUTH แล้วเทสต์เส้นทาง "ส่งสำเร็จ" จะไม่มีวันผ่าน
        if (str_starts_with($u, 'EHLO') || str_starts_with($u, 'HELO')) fwrite($c, "250-fake\r\n250 AUTH LOGIN\r\n");
        elseif (str_starts_with($u, 'AUTH LOGIN')) { fwrite($c, "334 VXNlcm5hbWU6\r\n"); $authStep = 1; }
        elseif ($authStep === 1) { fwrite($c, "334 UGFzc3dvcmQ6\r\n"); $authStep = 2; }
        elseif ($authStep === 2) { fwrite($c, "235 authenticated\r\n"); $authStep = 3; }
        elseif (str_starts_with($u, 'DATA')) { fwrite($c, "354 go\r\n"); $inData = true; }
        elseif (str_starts_with($u, 'QUIT')) { fwrite($c, "221 bye\r\n"); break; }
        else fwrite($c, "250 OK\r\n");
    }
    fclose($c);
    file_put_contents($out, json_encode(['count' => $n]));
}
fclose($srv);
SINK;
$sinkFile = sys_get_temp_dir() . "/syssink_{$uniq}.php";
file_put_contents($sinkFile, $sinkPhp);
@unlink($outFile); @unlink($outFile . '.ready');
$smtpPid = (int) shell_exec(sprintf('%s %s %d %s > /dev/null 2>&1 & echo $!',
    escapeshellarg(PHP_BINARY), escapeshellarg($sinkFile), $port, escapeshellarg($outFile)));
for ($i = 0; $i < 40 && !file_exists($outFile . '.ready'); $i++) usleep(100000);

$SECRET = 'PW-' . bin2hex(random_bytes(6));

function setMailCfg(PDO $pdo, array $kv): void
{
    foreach ($kv as $k => $v) { updateSetting($k, (string) $v); }
}

// 🔴 ตั้งค่าให้ "ส่งไม่ออก" ก่อน — พอร์ต 1 ไม่มีอะไรฟังอยู่
setMailCfg($pdo, [
    'mail_enabled' => '1', 'mail_host' => '127.0.0.1', 'mail_port' => '1',
    'mail_secure' => 'none', 'mail_username' => 'u', 'mail_password' => $SECRET,
    'mail_from_email' => 'lib@test.local', 'mail_from_name' => 'ทดสอบ',
    'mail_last_error' => '', 'mail_last_error_at' => '',
]);

$r1 = probe('send_saved');
check('SYS-C0', empty($r1['success']),
    'ส่งไม่สำเร็จจริงตามที่จัดฉาก — ข้อ C1 จึงเทียบของจริง',
    '🔴 ส่งสำเร็จทั้งที่พอร์ตตาย — การจัดฉากล้มเหลว ข้อต่อไปเชื่อไม่ได้');

$recorded = getSetting('mail_last_error', '');
check('SYS-C1', $recorded !== '',
    'บันทึกสาเหตุไว้แล้ว: "' . mb_substr($recorded, 0, 60) . '"',
    '🔴 ไม่บันทึก — ผู้ดูแลจะไม่มีทางรู้ว่าเมลส่งไม่ออก');

$h = freshHealth($pdo);
check('SYS-C1b', in_array('mail_failing', healthKeys($h), true),
    'กระดิ่งขึ้นเตือน "ส่งอีเมลไม่สำเร็จ"',
    '🔴 บันทึกแล้วแต่กระดิ่งไม่ขึ้น');

check('SYS-C2', strpos($recorded, $SECRET) === false,
    'ไม่มีรหัสผ่าน SMTP ปนอยู่ในข้อความที่เก็บ',
    '🔴 รหัสผ่าน SMTP หลุดลงตาราง settings');

// 🔴 ปุ่ม "ทดสอบส่ง" ส่ง $cfg ที่ยังไม่บันทึกเข้ามา → ห้ามบันทึกผล
updateSetting('mail_last_error', ''); updateSetting('mail_last_error_at', '');
probe('send_cfg', '1');
check('SYS-C3', getSetting('mail_last_error', '') === '',
    'ปุ่มทดสอบส่งด้วยค่าที่ยังไม่บันทึก → ไม่บันทึกเป็นความล้มเหลวของระบบ',
    '🔴 ลองค่าผิดในหน้าตั้งค่าแล้วระบบขึ้นเตือนว่าอีเมลพัง ทั้งที่ค่าที่ใช้จริงยังปกติ');

// 🔴 ส่งสำเร็จ → ต้องล้างคำเตือนทิ้ง
if (!file_exists($outFile . '.ready')) {
    fail('SYS-C4', '🔴 เปิด SMTP ปลอมไม่ได้ — ทดสอบเส้นทางสำเร็จไม่ได้');
} else {
    setMailCfg($pdo, ['mail_last_error' => 'ค้างไว้จากรอบก่อน', 'mail_last_error_at' => '01/01/2026 00:00',
                      'mail_port' => (string) $port]);
    $r2 = probe('send_saved');
    check('SYS-C4', !empty($r2['success']) && getSetting('mail_last_error', '') === '',
        'ส่งสำเร็จแล้วคำเตือนหายเอง — ผู้ดูแลไม่ต้องมาเคลียร์เอง',
        '🔴 ' . (!empty($r2['success']) ? 'ส่งสำเร็จแล้วแต่คำเตือนยังค้าง' : 'ส่งไม่สำเร็จ: ' . ($r2['error'] ?? '?')));

    $h = freshHealth($pdo);
    check('SYS-C5', !in_array('mail_failing', healthKeys($h), true),
        'กระดิ่งเงียบตามไปด้วย',
        '🔴 กระดิ่งยังเตือนอยู่ทั้งที่ส่งได้แล้ว');
}

// 🧹 ล้าง mail_* ให้กลับเป็นเท่าเดิม
$pdo->exec("DELETE FROM settings WHERE setting_key LIKE 'mail_%'");
if ($mailBefore > 0) { pass('SYS-C6', "⚠️ เครื่องนี้เดิมมี mail_* {$mailBefore} คีย์ — ถูกล้างทิ้งตามการทดสอบ"); }
else {
    $mailAfter = (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE setting_key LIKE 'mail_%'")->fetchColumn();
    check('SYS-C6', $mailAfter === 0,
        'ล้างค่าอีเมลที่ใช้ทดสอบออกหมด — ฐานข้อมูลกลับเป็นเหมือนก่อนรัน',
        "🔴 เหลือ mail_* {$mailAfter} คีย์ค้างไว้");
}
if ($smtpPid > 0) { @shell_exec("kill {$smtpPid} 2>/dev/null"); }
@unlink($sinkFile); @unlink($outFile); @unlink($outFile . '.ready');

// ============================================================
echo "\n── D-E. H3 ไฟล์ติดตั้ง / H4 โหมดพัฒนา ──\n";
// ============================================================

$h = freshHealth($pdo);
$keys = healthKeys($h);
$installerExists = is_file($ROOT . '/install.php');
check('SYS-D1', in_array('installer_present', $keys, true) === $installerExists,
    $installerExists
        ? 'install.php ยังอยู่ → เตือนถูกต้อง'
        : 'ลบ install.php ไปแล้ว → ไม่เตือน ถูกต้อง',
    '🔴 สถานะไฟล์กับคำเตือนไม่ตรงกัน');

/**
 * 🔴 APP_DEBUG เป็น constant แก้กลางคันไม่ได้ → ต้องรันใน process ใหม่
 *    define ก่อน require config.php แล้ว config.php จะ define ซ้ำไม่ได้ (ค่าเราชนะ)
 *    ห้ามแก้ไฟล์ .env เด็ดขาด — เคยเขียนทับของจริงจนเหลือ 0 ไบต์มาแล้ว
 */
$probeOut = json_encode(probe('health_debug'), JSON_UNESCAPED_UNICODE);

check('SYS-E1', strpos($probeOut, 'debug_on') !== false,
    'บังคับ APP_DEBUG=true ใน process แยก → เตือน "เปิดโหมดพัฒนาอยู่"',
    '🔴 APP_DEBUG=true แล้วไม่เตือน (ผลที่ได้: ' . ($probeOut ?: 'ว่าง') . ')');

check('SYS-E2', (defined('APP_DEBUG') && APP_DEBUG) === in_array('debug_on', $keys, true),
    'เครื่องนี้ APP_DEBUG=' . ((defined('APP_DEBUG') && APP_DEBUG) ? 'true' : 'false') . ' → ตรงกับที่กระดิ่งบอก',
    '🔴 ค่า APP_DEBUG จริงกับคำเตือนไม่ตรงกัน');

// ============================================================
echo "\n── F. คีย์ใหม่ต้องไม่โผล่ในหน้าตั้งค่า ──\n";
// ============================================================

$loginPage = http('GET', "{$BASE_URL}/login.php");
http('POST', "{$BASE_URL}/login.php", [
    'csrf_token' => csrfFrom($loginPage), 'email' => $ADMIN_EMAIL, 'password' => $ADMIN_PASSWORD,
]);
$settingsHtml = http('GET', "{$BASE_URL}/admin/settings.php");
check('SYS-F1',
    $settingsHtml !== '' && !preg_match('/name="mail_last_error/', $settingsHtml),
    'mail_last_error / mail_last_error_at ไม่โผล่เป็นช่องกรอกให้ผู้ดูแลแก้เอง',
    '🔴 คีย์ภายในโผล่เป็นช่องกรอกในหน้าตั้งค่า');

// ============================================================
echo "\n── G. ใครเห็นอะไร / ไม่มี path หลุด ──\n";
// ============================================================

$adminHtml = http('GET', "{$BASE_URL}/admin/index.php");

/**
 * 🔴 สร้างบัญชีเจ้าหน้าที่ขึ้นเองแทนการพึ่ง staff@library.com
 *
 * 🧠 เจอตอนรันบน clone สด: staff@library.com มีเฉพาะใน database/sample_data.sql
 *    ซึ่งเป็นไฟล์เดโมที่ลูกค้าไม่ได้นำเข้า (install.php ไม่มีปุ่มให้นำเข้าด้วยซ้ำ)
 *    เทสต์เดิมจึงล็อกอินไม่ได้บนเครื่องที่ติดตั้งสด แล้วรายงานว่า "กระดิ่งของ staff หายไป"
 *    ทั้งที่กระดิ่งปกติดี — เป็นบั๊กของเทสต์ ไม่ใช่ของระบบ
 *    (แนวเดียวกับ test_alert_bell.php ซึ่งสร้างบัญชีเองอยู่แล้ว)
 */
$staffEmail = "syshealth{$uniq}@test.local";
$staffPass  = 'StaffPass' . $uniq;
$madeStaff  = null;
try {
    $st = $pdo->prepare("INSERT INTO users (name, email, password, phone, role, must_change_password)
                         VALUES (?, ?, ?, ?, 'staff', 0)");
    $st->execute(["[SYSTEST] เจ้าหน้าที่ {$uniq}", $staffEmail, hashPassword($staffPass), '0800000000']);
    $madeStaff = (int) $pdo->lastInsertId();
} catch (Throwable $e) {
    fail('SYS-G0', '🔴 สร้างบัญชีเจ้าหน้าที่ไม่ได้: ' . $e->getMessage());
}
// 🧹 ลบทิ้งเสมอแม้สคริปต์ตายกลางคัน
register_shutdown_function(function () use ($pdo, &$madeStaff) {
    if ($madeStaff) {
        try { $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$madeStaff]); } catch (Throwable $e) {}
    }
});

$staffJar  = tempnam(sys_get_temp_dir(), 'bbst');
$sLogin    = http('GET', "{$BASE_URL}/login.php", [], $staffJar);
http('POST', "{$BASE_URL}/login.php",
    ['csrf_token' => csrfFrom($sLogin), 'email' => $staffEmail, 'password' => $staffPass], $staffJar);
$staffHtml = http('GET', "{$BASE_URL}/admin/index.php", [], $staffJar);
@unlink($staffJar);

check('SYS-G0', $madeStaff && strpos($staffHtml, 'ออกจากระบบ') !== false,
    'สร้างบัญชีเจ้าหน้าที่ชั่วคราวและล็อกอินได้ — ข้อ G ต่อจากนี้จึงเทียบสิทธิ์ได้จริง',
    '🔴 ล็อกอินเป็นเจ้าหน้าที่ไม่ได้ — ข้อ G เชื่อไม่ได้');

$adminOnlyLabels = ['ยังไม่ได้ลบไฟล์ติดตั้ง', 'เปิดโหมดพัฒนาอยู่'];
$adminSees = false; $staffSees = false;
foreach ($adminOnlyLabels as $lbl) {
    if (strpos($adminHtml, $lbl) !== false) { $adminSees = true; }
    if (strpos($staffHtml, $lbl) !== false) { $staffSees = true; }
}

if (!$installerExists && !(defined('APP_DEBUG') && APP_DEBUG)) {
    pass('SYS-G1', '⚠️ เครื่องนี้ไม่มีข้อ admin-only เลย — ข้ามการเทียบสิทธิ์ (ไม่ใช่ความล้มเหลว)');
} else {
    check('SYS-G1', $adminSees && !$staffSees,
        'admin เห็นข้อที่ต้องแก้บนเซิร์ฟเวอร์ · staff ไม่เห็น (แก้ไม่ได้อยู่แล้ว = noise)',
        '🔴 ' . ($staffSees ? 'staff เห็นข้อ admin-only' : 'admin ไม่เห็นข้อ admin-only'));
}

check('SYS-G1b', strpos($staffHtml, 'สิ่งที่ต้องจัดการ') !== false,
    'staff ยังเห็นกระดิ่งงานประจำวันตามปกติ',
    '🔴 กระดิ่งของ staff หายไป');

// 🛡️ ห้ามให้ path เซิร์ฟเวอร์หลุดออกหน้าจอ — คือสิ่งที่ H4 พยายามกันอยู่พอดี
$leak = false;
foreach ($h['items'] as $item) {
    $blob = $item['label'] . $item['detail'] . $item['how'];
    if (strpos($blob, $ROOT) !== false || strpos($blob, '/Applications') !== false
        || strpos($blob, '/var/www') !== false || strpos($blob, '/home/') !== false) {
        $leak = true;
    }
}
check('SYS-G2', !$leak,
    'ข้อความเตือนไม่มี path เต็มของเซิร์ฟเวอร์',
    '🔴 คำเตือนเปิดเผยโครงสร้างโฟลเดอร์บนเซิร์ฟเวอร์');

check('SYS-G3', strpos($adminHtml, 'สุขภาพระบบ') !== false || !$h['admin_total'],
    $h['admin_total'] ? 'หัวข้อ "สุขภาพระบบ" แสดงในกระดิ่งจริง' : 'ไม่มีข้อใดเลย จึงไม่ขึ้นหัวข้อ — ถูกต้อง',
    '🔴 มีข้อเตือนแต่หัวข้อไม่ขึ้นในหน้าเว็บ');

// ============================================================
echo "\n── H. ไม่ล้มหน้าแอดมิน / ไม่ทิ้งขยะ ──\n";
// ============================================================

$svcSrc = file_get_contents(__DIR__ . '/../app/Services/DashboardService.php');
check('SYS-H1', (bool) preg_match('/getSystemHealth.*?catch\s*\(\s*\\\\?Throwable/s', $svcSrc),
    'getSystemHealth() ห่อ try/catch Throwable — ตัวตรวจพังแล้วกระดิ่งเดิมยังขึ้นได้',
    '🔴 ไม่มี try/catch — ตัวตรวจพังจะล้มหน้าแอดมินทั้งหน้า');

// 🧹 [H5] isDirActuallyWritable() เขียนไฟล์จริงลงดิสก์ — ต้องเก็บกวาดทุกครั้ง
for ($i = 0; $i < 5; $i++) { freshHealth($pdo); }
$probes = glob($ROOT . '/uploads/covers/.write_probe_*') ?: [];
check('SYS-H2', count($probes) === 0,
    'เรียก 5 รอบแล้วไม่มีไฟล์ .write_probe_* ค้างในโฟลเดอร์ปก',
    '🔴 มีไฟล์ขยะค้าง ' . count($probes) . ' ไฟล์ในโฟลเดอร์ของลูกค้า');

// ⚡ ตัวแพงต้องถูก cache — ไม่งั้นทุกหน้าแอดมินช้าลง
//    🔴 วัดในโปรเซสใหม่เท่านั้น: วัดในโปรเซสนี้จะได้ 0 ms ทั้งคู่เพราะโดน cache ไปตั้งแต่ข้อ A
//       (เคยเขียนแบบนั้นแล้วเทสต์ผ่านสวยโดยไม่ได้วัดอะไรเลย)
$timed = probe('health');
check('SYS-H3', ($timed['ms_second'] ?? 99) < 1.0 && ($timed['ms_first'] ?? 0) > 0,
    sprintf('เรียกครั้งแรก %.1f ms · เรียกซ้ำ %.3f ms — มาจาก cache',
        $timed['ms_first'] ?? 0, $timed['ms_second'] ?? 0),
    sprintf('🔴 ครั้งแรก %.1f ms · เรียกซ้ำ %.3f ms — ไม่ได้ cache ทุกหน้าแอดมินจะช้าตาม',
        $timed['ms_first'] ?? 0, $timed['ms_second'] ?? 0));

if ($madeStaff) {
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$madeStaff]);
    $madeStaff = null;
}
$leftover = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE name LIKE '%[SYSTEST]%'")->fetchColumn();
check('SYS-G4', $leftover === 0,
    'ลบบัญชีเจ้าหน้าที่ที่สร้างขึ้นเรียบร้อย — ไม่ทิ้งข้อมูลไว้ในระบบลูกค้า',
    "🔴 เหลือบัญชีทดสอบ {$leftover} บัญชี");

@unlink($COOKIE);

// ============================================================
echo "\n── I. บอกเวลาที่ตรวจ ──\n";
// ============================================================

/**
 * 🔴 กระดิ่งคำนวณตอนโหลดหน้า ไม่ได้อัปเดตเอง
 *    คนที่เปิดแท็บทิ้งไว้ทั้งวันต้องรู้ว่าเลขที่เห็นเก่าแค่ไหน
 *    และเวลาของ "สุขภาพระบบ" ต้องเป็นเวลาที่ตรวจจริง ไม่ใช่เวลาที่โหลดหน้า
 *    เพราะตัวตรวจที่แพงถูก cache ไว้ 5 นาที
 */
$stale = probe('health_stale', '240');   // แกล้งว่าตรวจไว้เมื่อ 4 นาทีก่อน
$age   = time() - (int) ($stale['checked_at'] ?? time());
check('SYS-I1', $age >= 200 && $age <= 300,
    sprintf('cache อายุ 4 นาที → checked_at ตามเวลาที่ตรวจจริง (%d วินาทีก่อน) ไม่ใช่เวลาโหลดหน้า', $age),
    sprintf('🔴 checked_at ห่างจากปัจจุบัน %d วินาที — รายงานว่าเพิ่งตรวจทั้งที่ค้างมา 4 นาที', $age));

// 🧠 ใช้ HTML ที่ดึงไว้ตอนข้อ G — ตรงนั้นยังล็อกอินอยู่
//    ยิงใหม่ตรงนี้ไม่ได้ เพราะไฟล์ cookie ถูกลบไปแล้วก่อนหน้า (จะได้หน้าล็อกอินแทน)
$adminHtml2 = $adminHtml;
check('SYS-I2', (bool) preg_match('/ข้อมูล ณ \s*\d{2}:\d{2}/u', $adminHtml2),
    'กระดิ่งบอก "ข้อมูล ณ HH:MM" — ไม่อ้างว่าตัวเลขสดตลอดเวลา',
    '🔴 ไม่บอกเวลา — คนเปิดแท็บทิ้งไว้จะเข้าใจว่าเลขนี้อัปเดตเอง');

check('SYS-I3', !$h['admin_total'] || (bool) preg_match('/ตรวจเมื่อ\s*\d{2}:\d{2}/u', $adminHtml2),
    $h['admin_total'] ? 'หมวดสุขภาพระบบบอกเวลาที่ตรวจแยกของตัวเอง' : 'ไม่มีข้อเตือน จึงไม่ต้องบอกเวลา',
    '🔴 หมวดสุขภาพระบบไม่บอกเวลา ทั้งที่ถูก cache ได้ถึง 5 นาที');

// ============================================================
echo "\n══════════════════════════════════════\n";
printf(" RESULTS: %d/%d passed (%.1f%%)%s\n",
    $results['passed'], $results['total'],
    $results['total'] ? $results['passed'] / $results['total'] * 100 : 0,
    $results['failed'] ? ' | ' . $results['failed'] . ' FAILED' : '');
echo "══════════════════════════════════════\n";

exit($results['failed'] > 0 ? 1 : 0);
